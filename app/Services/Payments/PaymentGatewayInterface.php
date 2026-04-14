<?php

declare(strict_types=1);

namespace App\Services\Payments;

interface PaymentGatewayInterface
{
    public function code(): string;

    public function displayName(): string;

    public function provider(): string;

    public function supportsPolling(): bool;

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function initiate(array $order, string $idempotencyKey): array;

    /**
     * @param array<string, mixed> $payment
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function poll(array $payment, array $order): array;
}

