<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ApiLogRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Services\Payments\PaymentGatewayResolver;
use App\Utils\Env;
use App\Utils\Logger;
use RuntimeException;
use Throwable;

final class PaymentService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly ApiLogRepository $apiLogs,
        private readonly PaymentGatewayResolver $resolver,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function initiatePayment(int $orderId, ?string $gatewayCode = null): array
    {
        $order = $this->orders->findById($orderId);
        if ($order === null) {
            throw new RuntimeException('Order not found.');
        }

        $selectedGateway = strtolower(trim((string) ($gatewayCode ?? $order['selected_gateway'] ?? '')));
        if ($selectedGateway === '') {
            $selectedGateway = $this->resolver->defaultCode();
        }

        $existing = $this->payments->findByOrderId($orderId);
        if ($this->shouldSkipProviderRequest($order, $existing, $selectedGateway)) {
            return $this->formatCachedPaymentResult($order, $existing, $selectedGateway);
        }

        $gateway = $this->resolver->resolve($selectedGateway);
        $idempotencyKey = $existing['idempotency_key'] ?? hash('sha256', 'pay|' . $order['idempotency_key'] . '|' . $selectedGateway);
        $result = $gateway->initiate($order, $idempotencyKey);
        $providerStatus = $this->normalizeStatus((string) ($result['provider_status'] ?? 'processing'));
        $gatewayError = $this->resolveGatewayError($result, $providerStatus);
        $paymentData = [
            'order_id' => $orderId,
            'provider' => $gateway->provider(),
            'gateway_method' => $selectedGateway,
            'provider_payment_id' => $result['provider_payment_id'] ?? null,
            'provider_reference' => $result['provider_reference'] ?? (string) $order['order_no'],
            'amount' => (float) $order['amount'],
            'currency' => (string) $order['currency'],
            'status' => $providerStatus,
            'idempotency_key' => $idempotencyKey,
            'request_payload' => $this->encodeJson($result['request_body'] ?? []),
            'response_payload' => $this->encodeJson($result['response_body'] ?? []),
            'next_poll_at' => $providerStatus === 'processing' && $gateway->supportsPolling()
                ? $this->nextPollAt(1)
                : null,
            'retry_count' => 1,
            'last_error' => $gatewayError,
        ];

        $this->persistPayment($orderId, $existing, $paymentData);
        $this->syncOrderStatusFromPayment($orderId, $providerStatus);
        $this->logGatewayCall(
            gatewayCode: $selectedGateway,
            endpoint: (string) ($result['endpoint'] ?? '/payments'),
            method: 'POST',
            requestHeaders: is_array($result['request_headers'] ?? null) ? $result['request_headers'] : [],
            requestBody: is_array($result['request_body'] ?? null) ? $result['request_body'] : [],
            responseStatus: isset($result['http_code']) ? (int) $result['http_code'] : null,
            responseBody: is_array($result['response_body'] ?? null) ? $result['response_body'] : [],
            correlationId: (string) $order['order_no'],
            idempotencyKey: $idempotencyKey,
            orderId: $orderId
        );

        return [
            'order_id' => $orderId,
            'gateway' => $selectedGateway,
            'provider_status' => $providerStatus,
            'http_code' => $result['http_code'] ?? null,
            'response' => $result['response_body'] ?? [],
            'error' => $gatewayError,
            'cached' => false,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handleCallback(array $payload): array
    {
        $normalizedPayload = $this->normalizeProviderPayload($payload);
        $order = $this->resolveOrderFromCallback($normalizedPayload);
        $providerReference = $this->resolveProviderReference($normalizedPayload, null);
        $providerPaymentId = $this->resolveProviderPaymentId($normalizedPayload);

        if ($order === null) {
            $this->logGatewayCall(
                gatewayCode: strtolower(trim((string) ($normalizedPayload['gateway'] ?? 'unknown'))),
                endpoint: '/payments/callback',
                method: 'POST',
                requestHeaders: [],
                requestBody: $normalizedPayload,
                responseStatus: 404,
                responseBody: ['processed' => false, 'reason' => 'order-not-found'],
                correlationId: $providerReference ?? 'unknown',
                idempotencyKey: null,
                orderId: null
            );

            return ['processed' => false, 'reason' => 'order-not-found'];
        }

        $orderId = (int) $order['id'];
        $existing = $this->payments->findByOrderId($orderId);
        $gatewayCode = strtolower(trim((string) ($existing['gateway_method'] ?? $normalizedPayload['gateway'] ?? $order['selected_gateway'] ?? $this->resolver->defaultCode())));
        $incomingStatus = $this->normalizeStatus((string) ($normalizedPayload['status'] ?? 'processing'));
        $currentStatus = (string) ($existing['status'] ?? 'initiated');
        $status = $this->transitionPaymentStatus($currentStatus, $incomingStatus);

        $paymentData = [
            'order_id' => $orderId,
            'provider' => (string) ($existing['provider'] ?? $gatewayCode),
            'gateway_method' => $gatewayCode,
            'provider_payment_id' => $providerPaymentId,
            'provider_reference' => $providerReference ?? (string) $order['order_no'],
            'amount' => (float) $order['amount'],
            'currency' => (string) $order['currency'],
            'status' => $status,
            'idempotency_key' => $existing['idempotency_key'] ?? hash('sha256', 'callback|' . $order['idempotency_key']),
            'request_payload' => $existing['request_payload'] ?? $this->encodeJson(['source' => 'provider_callback']),
            'response_payload' => $this->encodeJson($normalizedPayload),
            'callback_received_at' => gmdate('Y-m-d H:i:s'),
            'next_poll_at' => null,
            'last_error' => $status === 'failed' ? 'Provider callback marked as failed' : null,
        ];

        $this->persistPayment($orderId, $existing, $paymentData);
        $this->syncOrderStatusFromPayment($orderId, $status);

        $result = [
            'processed' => true,
            'status' => $status,
            'order_id' => $orderId,
            'duplicate' => $status === $currentStatus && $this->isFinalPaymentStatus($status),
        ];

        $this->logGatewayCall(
            gatewayCode: $gatewayCode,
            endpoint: '/payments/callback',
            method: 'POST',
            requestHeaders: [],
            requestBody: $normalizedPayload,
            responseStatus: 200,
            responseBody: $result,
            correlationId: (string) $order['order_no'],
            idempotencyKey: (string) $paymentData['idempotency_key'],
            orderId: $orderId
        );

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function pollPendingPayments(): array
    {
        $enabled = filter_var(Env::get('PAYMENT_ENABLE_POLLING', true), FILTER_VALIDATE_BOOL);
        if ($enabled === false) {
            return [
                'processed' => 0,
                'pending_total' => 0,
                'mode' => 'polling_disabled',
                'confirmed_order_ids' => [],
                'failed_order_ids' => [],
            ];
        }

        $pollLimit = max(1, (int) Env::get('PAYMENT_POLL_BATCH_SIZE', 100));
        $maxRetries = max(1, (int) Env::get('PAYMENT_POLL_MAX_RETRIES', 20));
        $pending = $this->payments->listPendingForPolling($pollLimit);

        $processed = 0;
        $confirmedOrderIds = [];
        $failedOrderIds = [];
        $timedOut = 0;
        $providerErrors = 0;

        foreach ($pending as $payment) {
            $processed++;
            $orderId = (int) $payment['order_id'];
            $order = $this->orders->findById($orderId);
            if ($order === null) {
                $providerErrors++;
                $this->payments->updateByOrderId($orderId, [
                    'status' => 'failed',
                    'next_poll_at' => null,
                    'last_error' => 'Polling skipped because order was not found.',
                ]);
                continue;
            }

            $gatewayCode = strtolower(trim((string) ($payment['gateway_method'] ?? $order['selected_gateway'] ?? $this->resolver->defaultCode())));
            try {
                $gateway = $this->resolver->resolve($gatewayCode);
            } catch (Throwable $exception) {
                $providerErrors++;
                $this->payments->updateByOrderId($orderId, [
                    'status' => 'failed',
                    'next_poll_at' => null,
                    'last_error' => $exception->getMessage(),
                ]);
                continue;
            }

            if (!$gateway->supportsPolling()) {
                $providerErrors++;
                $this->payments->updateByOrderId($orderId, [
                    'status' => 'failed',
                    'next_poll_at' => null,
                    'last_error' => 'Gateway nao suporta polling.',
                ]);
                $this->syncOrderStatusFromPayment($orderId, 'failed');
                $failedOrderIds[] = $orderId;
                continue;
            }

            $result = $gateway->poll($payment, $order);
            $incomingStatus = $this->normalizeStatus((string) ($result['provider_status'] ?? 'processing'));
            $currentStatus = (string) $payment['status'];
            $status = $this->transitionPaymentStatus($currentStatus, $incomingStatus);

            $retryCount = (int) $payment['retry_count'] + 1;
            if ($status === 'processing' && $retryCount >= $maxRetries) {
                $status = 'timeout';
                $timedOut++;
            }

            $lastError = $result['error'] ?? null;
            if ($lastError !== null) {
                $providerErrors++;
            }

            $this->payments->updateByOrderId($orderId, [
                'provider_payment_id' => $result['provider_payment_id'] ?? ($payment['provider_payment_id'] ?? null),
                'provider_reference' => $result['provider_reference'] ?? ($payment['provider_reference'] ?? null),
                'status' => $status,
                'retry_count' => $retryCount,
                'next_poll_at' => $status === 'processing' ? $this->nextPollAt($retryCount) : null,
                'response_payload' => $this->encodeJson($result['response_body'] ?? []),
                'last_error' => $status === 'processing' ? $lastError : ($status === 'failed' || $status === 'timeout' ? ($lastError ?? $payment['last_error']) : null),
            ]);

            $this->syncOrderStatusFromPayment($orderId, $status);
            if ($status === 'confirmed') {
                $confirmedOrderIds[] = $orderId;
            } elseif (in_array($status, ['failed', 'timeout'], true)) {
                $failedOrderIds[] = $orderId;
            }

            $this->logGatewayCall(
                gatewayCode: $gatewayCode,
                endpoint: (string) ($result['endpoint'] ?? '/payments/{reference}'),
                method: 'GET',
                requestHeaders: is_array($result['request_headers'] ?? null) ? $result['request_headers'] : [],
                requestBody: is_array($result['request_body'] ?? null) ? $result['request_body'] : [],
                responseStatus: isset($result['http_code']) ? (int) $result['http_code'] : null,
                responseBody: is_array($result['response_body'] ?? null) ? $result['response_body'] : [],
                correlationId: (string) ($order['order_no'] ?? ''),
                idempotencyKey: (string) ($payment['idempotency_key'] ?? ''),
                orderId: $orderId
            );
        }

        return [
            'processed' => $processed,
            'pending_total' => count($pending),
            'mode' => 'gateway_polling',
            'timed_out' => $timedOut,
            'provider_errors' => $providerErrors,
            'confirmed_order_ids' => array_values(array_unique($confirmedOrderIds)),
            'failed_order_ids' => array_values(array_unique($failedOrderIds)),
        ];
    }

    /**
     * @param array<string, mixed>|null $order
     * @param array<string, mixed>|null $existing
     */
    private function shouldSkipProviderRequest(?array $order, ?array $existing, string $gatewayCode): bool
    {
        if ($order === null || $existing === null) {
            return false;
        }

        $existingGateway = strtolower(trim((string) ($existing['gateway_method'] ?? '')));
        if ($existingGateway !== '' && $existingGateway !== $gatewayCode) {
            return false;
        }

        $status = (string) ($existing['status'] ?? '');
        if ($status === 'confirmed') {
            return true;
        }

        if (in_array($status, ['initiated', 'processing'], true)) {
            return true;
        }

        if ((string) ($order['status'] ?? '') === 'paid') {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function formatCachedPaymentResult(array $order, ?array $existing, string $gatewayCode): array
    {
        if ($existing === null) {
            return [
                'order_id' => (int) $order['id'],
                'gateway' => $gatewayCode,
                'provider_status' => 'initiated',
                'http_code' => null,
                'response' => [],
                'error' => null,
                'cached' => false,
            ];
        }

        $decoded = json_decode((string) ($existing['response_payload'] ?? '{}'), true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        return [
            'order_id' => (int) $order['id'],
            'gateway' => (string) ($existing['gateway_method'] ?? $gatewayCode),
            'provider_status' => (string) ($existing['status'] ?? 'processing'),
            'http_code' => null,
            'response' => $decoded,
            'error' => $existing['last_error'] ?? null,
            'cached' => true,
        ];
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $payload
     */
    private function persistPayment(int $orderId, ?array $existing, array $payload): void
    {
        if ($existing !== null) {
            $this->payments->updateByOrderId($orderId, $payload);
            return;
        }

        try {
            $this->payments->create($payload);
        } catch (Throwable) {
            $this->payments->updateByOrderId($orderId, $payload);
        }
    }

    private function syncOrderStatusFromPayment(int $orderId, string $paymentStatus): void
    {
        $order = $this->orders->findById($orderId);
        if ($order === null) {
            return;
        }

        $currentOrderStatus = (string) ($order['status'] ?? 'pending');
        if ($currentOrderStatus === 'paid') {
            return;
        }

        if ($paymentStatus === 'confirmed') {
            $this->orders->markStatus($orderId, 'paid');
            return;
        }

        if (in_array($paymentStatus, ['failed', 'timeout'], true)) {
            $this->orders->markStatus($orderId, 'failed');
        }
    }

    private function transitionPaymentStatus(string $current, string $incoming): string
    {
        if ($current === 'confirmed' || $incoming === 'confirmed') {
            return 'confirmed';
        }

        if ($current === 'failed' && in_array($incoming, ['initiated', 'processing', 'timeout'], true)) {
            return 'failed';
        }

        if ($current === 'timeout' && in_array($incoming, ['initiated', 'processing'], true)) {
            return 'timeout';
        }

        return $incoming;
    }

    private function isFinalPaymentStatus(string $status): bool
    {
        return in_array($status, ['confirmed', 'failed', 'timeout'], true);
    }

    private function nextPollAt(int $retryCount): string
    {
        $baseInterval = max(15, (int) Env::get('PAYMENT_POLL_INTERVAL_SECONDS', 90));
        $multiplier = min(8, max(1, $retryCount));
        return gmdate('Y-m-d H:i:s', time() + ($baseInterval * $multiplier));
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'confirmed', 'paid', 'success', 'successful' => 'confirmed',
            'failed', 'error', 'cancelled' => 'failed',
            'timeout', 'expired' => 'timeout',
            'initiated', 'processing' => $status,
            default => 'processing',
        };
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function normalizeProviderPayload(array $response): array
    {
        $data = $response['data'] ?? null;
        if (is_array($data)) {
            return array_merge($response, $data);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveProviderReference(array $payload, ?string $fallback): ?string
    {
        foreach (['reference', 'provider_reference', 'order_no', 'transaction_reference'] as $key) {
            $value = $payload[$key] ?? null;
            if (!is_scalar($value)) {
                continue;
            }

            $ref = trim((string) $value);
            if ($ref !== '') {
                return $ref;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveProviderPaymentId(array $payload): ?string
    {
        foreach (['id', 'payment_id', 'provider_payment_id', 'transaction_id'] as $key) {
            $value = $payload[$key] ?? null;
            if (!is_scalar($value)) {
                continue;
            }

            $id = trim((string) $value);
            if ($id !== '') {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function resolveOrderFromCallback(array $payload): ?array
    {
        $reference = $this->resolveProviderReference($payload, null);
        if ($reference !== null) {
            $order = $this->orders->findByOrderNo($reference);
            if ($order !== null) {
                return $order;
            }

            $paymentByReference = $this->payments->findByProviderReference($reference);
            if ($paymentByReference !== null) {
                return $this->orders->findById((int) $paymentByReference['order_id']);
            }
        }

        $providerPaymentId = $this->resolveProviderPaymentId($payload);
        if ($providerPaymentId !== null) {
            $paymentByProviderId = $this->payments->findByProviderPaymentId($providerPaymentId);
            if ($paymentByProviderId !== null) {
                return $this->orders->findById((int) $paymentByProviderId['order_id']);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $requestHeaders
     * @param array<string, mixed> $requestBody
     * @param array<string, mixed> $responseBody
     */
    private function logGatewayCall(
        string $gatewayCode,
        string $endpoint,
        string $method,
        array $requestHeaders,
        array $requestBody,
        ?int $responseStatus,
        array $responseBody,
        ?string $correlationId,
        ?string $idempotencyKey,
        ?int $orderId
    ): void {
        $gatewayCode = strtolower(trim($gatewayCode));
        if ($gatewayCode === '') {
            $gatewayCode = $this->resolver->defaultCode();
        }

        $this->apiLogs->create([
            'service' => 'payment_gateway_' . $gatewayCode,
            'endpoint' => $endpoint,
            'method' => strtoupper($method),
            'request_headers' => $this->encodeJson($requestHeaders),
            'request_body' => $this->encodeJson($requestBody),
            'response_status' => $responseStatus,
            'response_body' => $this->encodeJson($responseBody),
            'latency_ms' => null,
            'correlation_id' => $correlationId,
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
        ]);
    }

    private function encodeJson(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * @param array<string, mixed> $gatewayResult
     */
    private function resolveGatewayError(array $gatewayResult, string $providerStatus): ?string
    {
        $httpCode = isset($gatewayResult['http_code']) ? (int) $gatewayResult['http_code'] : null;
        $isErrorContext = in_array($providerStatus, ['failed', 'timeout'], true)
            || ($httpCode !== null && $httpCode >= 400);
        if (!$isErrorContext) {
            return null;
        }

        $direct = $gatewayResult['error'] ?? null;
        if (is_scalar($direct)) {
            $message = trim((string) $direct);
            if ($message !== '') {
                return $message;
            }
        }

        $response = $gatewayResult['response_body'] ?? null;
        if (!is_array($response)) {
            return null;
        }

        foreach ([
            ['error', 'message'],
            ['message'],
            ['detail'],
            ['error_description'],
        ] as $path) {
            $value = $response;
            foreach ($path as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }

            if (!is_scalar($value)) {
                continue;
            }

            $message = trim((string) $value);
            if ($message !== '') {
                return $message;
            }
        }

        return null;
    }
}
