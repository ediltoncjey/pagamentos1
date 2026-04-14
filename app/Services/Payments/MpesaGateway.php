<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Utils\Env;
use App\Utils\Logger;
use App\Utils\Retry;
use RuntimeException;
use Throwable;

final class MpesaGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly Retry $retry,
        private readonly Logger $logger,
    ) {
    }

    public function code(): string
    {
        return 'mpesa';
    }

    public function displayName(): string
    {
        return 'M-Pesa';
    }

    public function provider(): string
    {
        return (string) Env::get('PAYMENT_GATEWAY_MPESA_PROVIDER', 'rozvitech');
    }

    public function supportsPolling(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function initiate(array $order, string $idempotencyKey): array
    {
        $baseUrl = rtrim((string) Env::get('PAYMENT_API_BASE_URL', ''), '/');
        $apiKey = trim((string) Env::get('PAYMENT_API_KEY', ''));
        if ($baseUrl === '' || $apiKey === '') {
            return [
                'provider_status' => 'failed',
                'provider_payment_id' => null,
                'provider_reference' => (string) ($order['order_no'] ?? ''),
                'http_code' => null,
                'endpoint' => '/payments',
                'request_headers' => [],
                'request_body' => [],
                'response_body' => ['status' => 'failed', 'message' => 'Credenciais M-Pesa nao configuradas.'],
                'error' => 'Credenciais M-Pesa nao configuradas.',
            ];
        }

        $amount = (float) number_format((float) ($order['amount'] ?? 0), 2, '.', '');
        $currency = strtoupper((string) ($order['currency'] ?? 'MZN'));
        $minAmount = (float) Env::get('PAYMENT_GATEWAY_MPESA_MIN_AMOUNT', 20);
        if ($currency === 'MZN' && $amount < $minAmount) {
            $message = sprintf('Valor minimo para M-Pesa: %.2f MZN.', $minAmount);
            return [
                'provider_status' => 'failed',
                'provider_payment_id' => null,
                'provider_reference' => (string) ($order['order_no'] ?? ''),
                'http_code' => 422,
                'endpoint' => '/payments',
                'request_headers' => [
                    'Authorization' => 'Bearer ***',
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Idempotency-Key' => $idempotencyKey,
                    'X-Correlation-Id' => (string) ($order['order_no'] ?? ''),
                ],
                'request_body' => [
                    'amount' => $amount,
                    'currency' => $currency,
                    'customer_phone' => (string) ($order['customer_phone'] ?? ''),
                    'reference' => (string) ($order['order_no'] ?? ''),
                    'description' => 'Pagamento ' . (string) ($order['order_no'] ?? ''),
                ],
                'response_body' => [
                    'status' => 422,
                    'message' => $message,
                    'error' => [
                        'code' => 'min_amount_not_met',
                        'message' => $message,
                    ],
                ],
                'error' => $message,
            ];
        }

        $endpoint = $baseUrl . '/payments';
        $requestBody = [
            'amount' => $amount,
            'currency' => $currency,
            'customer_phone' => (string) ($order['customer_phone'] ?? ''),
            'reference' => (string) ($order['order_no'] ?? ''),
            'description' => 'Pagamento ' . (string) ($order['order_no'] ?? ''),
        ];
        $requestHeaders = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'Idempotency-Key: ' . $idempotencyKey,
            'X-Correlation-Id: ' . (string) ($order['order_no'] ?? ''),
        ];

        $httpCode = null;
        $responseBody = [];
        $error = null;
        try {
            $responseBody = $this->retry->execute(
                callback: function () use ($endpoint, $requestHeaders, $requestBody, &$httpCode): array {
                    [$httpCode, $body] = $this->sendJsonRequest(
                        url: $endpoint,
                        method: 'POST',
                        headers: $requestHeaders,
                        payload: $requestBody
                    );

                    if ($httpCode >= 500) {
                        throw new RuntimeException('Erro temporario no gateway M-Pesa.');
                    }

                    return $body;
                },
                attempts: max(1, (int) Env::get('PAYMENT_RETRY_ATTEMPTS', 3)),
                baseDelayMs: max(0, (int) Env::get('PAYMENT_RETRY_BASE_DELAY_MS', 250)),
                factor: 2.0,
                onRetry: function (Throwable $exception, int $attempt): void {
                    $this->logger->warning('Retry request MpesaGateway', [
                        'attempt' => $attempt,
                        'error' => $exception->getMessage(),
                    ]);
                }
            );
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            $this->logger->warning('MpesaGateway initiate failed', [
                'error' => $error,
                'order_no' => $order['order_no'] ?? null,
            ]);
        }

        $normalized = $this->normalizeProviderPayload($responseBody);
        $resolvedError = $error ?? $this->extractErrorMessage($normalized);
        return [
            'provider_status' => $this->resolvePaymentStatus($httpCode, $normalized, $resolvedError),
            'provider_payment_id' => $this->resolveProviderPaymentId($normalized),
            'provider_reference' => $this->resolveProviderReference($normalized, (string) ($order['order_no'] ?? '')),
            'http_code' => $httpCode,
            'endpoint' => '/payments',
            'request_headers' => [
                'Authorization' => 'Bearer ***',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Idempotency-Key' => $idempotencyKey,
                'X-Correlation-Id' => (string) ($order['order_no'] ?? ''),
            ],
            'request_body' => $requestBody,
            'response_body' => $normalized,
            'error' => $resolvedError,
        ];
    }

    /**
     * @param array<string, mixed> $payment
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function poll(array $payment, array $order): array
    {
        $baseUrl = rtrim((string) Env::get('PAYMENT_API_BASE_URL', ''), '/');
        $apiKey = trim((string) Env::get('PAYMENT_API_KEY', ''));
        if ($baseUrl === '' || $apiKey === '') {
            return [
                'provider_status' => 'failed',
                'provider_payment_id' => $payment['provider_payment_id'] ?? null,
                'provider_reference' => (string) ($payment['provider_reference'] ?? ($order['order_no'] ?? '')),
                'http_code' => null,
                'endpoint' => '/payments/{reference}',
                'request_headers' => [],
                'request_body' => [],
                'response_body' => ['status' => 'failed', 'message' => 'Credenciais M-Pesa nao configuradas.'],
                'error' => 'Credenciais M-Pesa nao configuradas.',
            ];
        }

        $statusTemplate = (string) Env::get('PAYMENT_STATUS_ENDPOINT_TEMPLATE', '/payments/{reference}');
        $reference = (string) ($payment['provider_reference'] ?? $payment['provider_payment_id'] ?? $order['order_no'] ?? '');
        $endpoint = $this->buildStatusEndpoint($baseUrl, $statusTemplate, $reference);
        $requestHeaders = [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'X-Correlation-Id: ' . (string) ($order['order_no'] ?? ''),
        ];

        $httpCode = null;
        $responseBody = [];
        $error = null;
        try {
            $responseBody = $this->retry->execute(
                callback: function () use ($endpoint, $requestHeaders, &$httpCode): array {
                    [$httpCode, $body] = $this->sendJsonRequest(
                        url: $endpoint,
                        method: 'GET',
                        headers: $requestHeaders
                    );

                    if ($httpCode >= 500) {
                        throw new RuntimeException('Erro temporario no polling M-Pesa.');
                    }

                    return $body;
                },
                attempts: max(1, (int) Env::get('PAYMENT_RETRY_ATTEMPTS', 3)),
                baseDelayMs: max(0, (int) Env::get('PAYMENT_RETRY_BASE_DELAY_MS', 250)),
                factor: 2.0,
                onRetry: function (Throwable $exception, int $attempt): void {
                    $this->logger->warning('Retry polling MpesaGateway', [
                        'attempt' => $attempt,
                        'error' => $exception->getMessage(),
                    ]);
                }
            );
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        $normalized = $this->normalizeProviderPayload($responseBody);
        $resolvedError = $error ?? $this->extractErrorMessage($normalized);
        return [
            'provider_status' => $this->resolvePaymentStatus($httpCode, $normalized, $resolvedError),
            'provider_payment_id' => $this->resolveProviderPaymentId($normalized) ?? ($payment['provider_payment_id'] ?? null),
            'provider_reference' => $this->resolveProviderReference($normalized, $reference),
            'http_code' => $httpCode,
            'endpoint' => parse_url($endpoint, PHP_URL_PATH) ?: $statusTemplate,
            'request_headers' => [
                'Authorization' => 'Bearer ***',
                'Accept' => 'application/json',
                'X-Correlation-Id' => (string) ($order['order_no'] ?? ''),
            ],
            'request_body' => ['reference' => $reference],
            'response_body' => $normalized,
            'error' => $resolvedError,
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function resolvePaymentStatus(?int $httpCode, array $response, ?string $error): string
    {
        if ($error !== null) {
            if ($httpCode !== null && $httpCode >= 400 && $httpCode < 500) {
                return 'failed';
            }

            return 'processing';
        }

        $providerStatus = strtolower((string) ($response['status'] ?? 'processing'));
        if (in_array($providerStatus, ['confirmed', 'paid', 'success', 'successful'], true)) {
            return 'confirmed';
        }

        if (in_array($providerStatus, ['failed', 'error', 'cancelled'], true)) {
            return 'failed';
        }

        if ($httpCode !== null && $httpCode >= 200 && $httpCode < 300) {
            return 'processing';
        }

        if ($httpCode !== null && $httpCode >= 400 && $httpCode < 500) {
            return 'failed';
        }

        return 'processing';
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

            $reference = trim((string) $value);
            if ($reference !== '') {
                return $reference;
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

            $paymentId = trim((string) $value);
            if ($paymentId !== '') {
                return $paymentId;
            }
        }

        return null;
    }

    private function buildStatusEndpoint(string $baseUrl, string $template, string $reference): string
    {
        $template = trim($template);
        if ($template === '') {
            $template = '/payments/{reference}';
        }

        $normalizedTemplate = str_starts_with($template, 'http')
            ? $template
            : rtrim($baseUrl, '/') . '/' . ltrim($template, '/');

        if (str_contains($normalizedTemplate, '{reference}')) {
            return str_replace('{reference}', rawurlencode($reference), $normalizedTemplate);
        }

        $separator = str_contains($normalizedTemplate, '?') ? '&' : '?';
        return $normalizedTemplate . $separator . 'reference=' . rawurlencode($reference);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractErrorMessage(array $payload): ?string
    {
        foreach ([
            ['error', 'message'],
            ['message'],
            ['detail'],
            ['error_description'],
        ] as $path) {
            $value = $this->arrayGet($payload, $path);
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

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $path
     */
    private function arrayGet(array $payload, array $path): mixed
    {
        $cursor = $payload;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param array<int, string> $headers
     * @param array<string, mixed>|null $payload
     * @return array{0:int,1:array<string, mixed>}
     */
    private function sendJsonRequest(string $url, string $method, array $headers, ?array $payload = null): array
    {
        $method = strtoupper($method);
        if ($method === 'GET' && is_array($payload) && $payload !== []) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . http_build_query($payload);
            $payload = null;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Nao foi possivel inicializar cURL.');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(5, (int) Env::get('PAYMENT_TIMEOUT_SECONDS', 30)),
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);
        $error = curl_error($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($raw === false) {
            throw new RuntimeException('cURL error: ' . $error);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => (string) $raw];
        }

        return [$httpCode, $decoded];
    }
}
