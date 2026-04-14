<?php

declare(strict_types=1);

namespace App\Services\Payments;

abstract class UnavailableGateway implements PaymentGatewayInterface
{
    public function supportsPolling(): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function initiate(array $order, string $idempotencyKey): array
    {
        return [
            'provider_status' => 'failed',
            'provider_payment_id' => null,
            'provider_reference' => (string) ($order['order_no'] ?? ''),
            'http_code' => 503,
            'endpoint' => null,
            'request_headers' => [],
            'request_body' => [
                'gateway' => $this->code(),
                'order_no' => (string) ($order['order_no'] ?? ''),
                'amount' => (float) ($order['amount'] ?? 0),
            ],
            'response_body' => [
                'status' => 'failed',
                'message' => $this->displayName() . ' indisponivel temporariamente.',
                'gateway' => $this->code(),
            ],
            'error' => $this->displayName() . ' indisponivel temporariamente.',
        ];
    }

    /**
     * @param array<string, mixed> $payment
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function poll(array $payment, array $order): array
    {
        return [
            'provider_status' => 'failed',
            'provider_reference' => (string) ($payment['provider_reference'] ?? ($order['order_no'] ?? '')),
            'provider_payment_id' => $payment['provider_payment_id'] ?? null,
            'http_code' => 503,
            'endpoint' => null,
            'request_headers' => [],
            'request_body' => [],
            'response_body' => [
                'status' => 'failed',
                'message' => $this->displayName() . ' indisponivel temporariamente.',
                'gateway' => $this->code(),
            ],
            'error' => $this->displayName() . ' indisponivel temporariamente.',
        ];
    }
}

