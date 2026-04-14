<?php

declare(strict_types=1);

namespace App\Repositories;

final class PaymentRepository extends BaseRepository
{
    private bool $schemaChecked = false;

    /**
     * @return array<string, mixed>|null
     */
    public function findByOrderId(int $orderId): ?array
    {
        $this->ensureSchema();
        return $this->fetchOne(
            'SELECT * FROM payments WHERE order_id = :order_id ORDER BY id DESC LIMIT 1',
            ['order_id' => $orderId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByProviderReference(string $providerReference): ?array
    {
        $this->ensureSchema();
        return $this->fetchOne(
            'SELECT * FROM payments WHERE provider_reference = :provider_reference ORDER BY id DESC LIMIT 1',
            ['provider_reference' => $providerReference]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByProviderPaymentId(string $providerPaymentId): ?array
    {
        $this->ensureSchema();
        return $this->fetchOne(
            'SELECT * FROM payments WHERE provider_payment_id = :provider_payment_id ORDER BY id DESC LIMIT 1',
            ['provider_payment_id' => $providerPaymentId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPendingForPolling(int $limit = 50): array
    {
        $this->ensureSchema();
        return $this->fetchAll(
            'SELECT * FROM payments
             WHERE status IN ("initiated", "processing")
               AND next_poll_at IS NOT NULL
               AND next_poll_at <= UTC_TIMESTAMP()
             ORDER BY next_poll_at ASC
             LIMIT ' . (int) $limit
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $this->ensureSchema();
        $this->execute(
            'INSERT INTO payments (
                order_id, provider, gateway_method, provider_payment_id, provider_reference,
                amount, currency, status, idempotency_key, request_payload, response_payload,
                next_poll_at, retry_count, last_error, created_at, updated_at
             ) VALUES (
                :order_id, :provider, :gateway_method, :provider_payment_id, :provider_reference,
                :amount, :currency, :status, :idempotency_key, :request_payload, :response_payload,
                :next_poll_at, :retry_count, :last_error, UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )',
            [
                'order_id' => $data['order_id'],
                'provider' => $data['provider'] ?? 'rozvitech',
                'gateway_method' => $data['gateway_method'] ?? 'mpesa',
                'provider_payment_id' => $data['provider_payment_id'] ?? null,
                'provider_reference' => $data['provider_reference'] ?? null,
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'MZN',
                'status' => $data['status'] ?? 'initiated',
                'idempotency_key' => $data['idempotency_key'],
                'request_payload' => $data['request_payload'] ?? '{}',
                'response_payload' => $data['response_payload'] ?? '{}',
                'next_poll_at' => $data['next_poll_at'] ?? null,
                'retry_count' => $data['retry_count'] ?? 0,
                'last_error' => $data['last_error'] ?? null,
            ]
        );

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateByOrderId(int $orderId, array $data): void
    {
        $this->ensureSchema();
        $fields = [];
        $params = ['where_order_id' => $orderId];

        foreach ($data as $key => $value) {
            if ($key === 'order_id') {
                continue;
            }

            $paramName = 'set_' . $key;
            $fields[] = sprintf('%s = :%s', $key, $paramName);
            $params[$paramName] = $value;
        }

        if ($fields === []) {
            return;
        }

        $fields[] = 'updated_at = UTC_TIMESTAMP()';

        $sql = 'UPDATE payments SET ' . implode(', ', $fields) . ' WHERE order_id = :where_order_id';
        $this->execute($sql, $params);
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->ensureColumn(
            table: 'payments',
            column: 'gateway_method',
            ddl: 'ALTER TABLE payments ADD COLUMN gateway_method VARCHAR(40) NOT NULL DEFAULT "mpesa" AFTER provider'
        );

        $this->schemaChecked = true;
    }

    private function ensureColumn(string $table, string $column, string $ddl): void
    {
        $exists = $this->fetchOne(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name
             LIMIT 1',
            [
                'table_name' => $table,
                'column_name' => $column,
            ]
        );

        if ($exists !== null) {
            return;
        }

        try {
            $this->execute($ddl);
        } catch (\Throwable) {
        }
    }
}
