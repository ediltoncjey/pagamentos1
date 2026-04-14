<?php

declare(strict_types=1);

namespace App\Repositories;

final class OrderRepository extends BaseRepository
{
    private bool $schemaChecked = false;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $this->ensureSchema();
        return $this->fetchOne('SELECT * FROM orders WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByFunnelSessionToken(string $token): array
    {
        $this->ensureSchema();
        return $this->fetchAll(
            'SELECT *
             FROM orders
             WHERE funnel_session_token = :token
             ORDER BY created_at ASC, id ASC',
            ['token' => $token]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByOrderNo(string $orderNo): ?array
    {
        $this->ensureSchema();
        return $this->fetchOne('SELECT * FROM orders WHERE order_no = :order_no LIMIT 1', ['order_no' => $orderNo]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDeliveryContextByOrderId(int $orderId): ?array
    {
        $this->ensureSchema();
        return $this->fetchOne(
            'SELECT o.*, p.delivery_type AS product_delivery_type, p.external_url AS product_external_url,
                    p.file_path AS product_file_path, p.name AS product_name, p.product_type AS product_type
             FROM orders o
             INNER JOIN products p ON p.id = o.product_id
             WHERE o.id = :order_id
             LIMIT 1',
            ['order_id' => $orderId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCheckoutSnapshotByOrderNo(string $orderNo): ?array
    {
        $this->ensureSchema();
        return $this->fetchOne(
            'SELECT o.*, pp.slug, pp.title AS page_title, pp.description AS page_description,
                    p.name AS product_name, p.product_type AS product_type, p.delivery_type AS product_delivery_type,
                    p.external_url AS product_external_url, p.file_path AS product_file_path,
                    py.status AS payment_status, py.provider_reference, py.last_error, py.updated_at AS payment_updated_at
             FROM orders o
             INNER JOIN payment_pages pp ON pp.id = o.payment_page_id
             INNER JOIN products p ON p.id = o.product_id
             LEFT JOIN payments py ON py.order_id = o.id
             WHERE o.order_no = :order_no
            LIMIT 1',
            ['order_no' => $orderNo]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findEmailContextByOrderId(int $orderId): ?array
    {
        $this->ensureSchema();
        return $this->fetchOne(
            'SELECT
                o.*,
                p.name AS product_name,
                p.product_type AS product_type,
                p.delivery_type AS product_delivery_type,
                p.external_url AS product_external_url,
                p.file_path AS product_file_path,
                pp.slug AS payment_page_slug,
                u.name AS reseller_name,
                u.email AS reseller_email
             FROM orders o
             INNER JOIN products p ON p.id = o.product_id
             INNER JOIN payment_pages pp ON pp.id = o.payment_page_id
             INNER JOIN users u ON u.id = o.reseller_id
             WHERE o.id = :order_id
             LIMIT 1',
            ['order_id' => $orderId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?array
    {
        $this->ensureSchema();
        return $this->fetchOne(
            'SELECT * FROM orders WHERE idempotency_key = :idempotency_key LIMIT 1',
            ['idempotency_key' => $idempotencyKey]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createPending(array $data): int
    {
        $this->ensureSchema();
        $this->execute(
            'INSERT INTO orders (
                order_no, payment_page_id, product_id, reseller_id, customer_name, customer_email,
                customer_phone, customer_country, customer_city, customer_address, customer_notes, selected_gateway,
                parent_order_id, order_context, funnel_session_token,
                amount, currency, status, idempotency_key, expires_at, created_at, updated_at
             ) VALUES (
                :order_no, :payment_page_id, :product_id, :reseller_id, :customer_name, :customer_email,
                :customer_phone, :customer_country, :customer_city, :customer_address, :customer_notes, :selected_gateway,
                :parent_order_id, :order_context, :funnel_session_token,
                :amount, :currency, "pending", :idempotency_key, :expires_at, UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )',
            [
                'order_no' => $data['order_no'],
                'payment_page_id' => $data['payment_page_id'],
                'product_id' => $data['product_id'],
                'reseller_id' => $data['reseller_id'],
                'customer_name' => $data['customer_name'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'customer_phone' => $data['customer_phone'],
                'customer_country' => $data['customer_country'] ?? null,
                'customer_city' => $data['customer_city'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'customer_notes' => $data['customer_notes'] ?? null,
                'selected_gateway' => $data['selected_gateway'] ?? 'mpesa',
                'parent_order_id' => $data['parent_order_id'] ?? null,
                'order_context' => $data['order_context'] ?? 'standard',
                'funnel_session_token' => $data['funnel_session_token'] ?? null,
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'MZN',
                'idempotency_key' => $data['idempotency_key'],
                'expires_at' => $data['expires_at'],
            ]
        );

        return (int) $this->pdo()->lastInsertId();
    }

    public function markStatus(int $orderId, string $status): void
    {
        $this->ensureSchema();
        $this->execute(
            'UPDATE orders
             SET status = :status,
                 paid_at = CASE WHEN :status_for_paid_at = "paid" THEN UTC_TIMESTAMP() ELSE paid_at END,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            [
                'status' => $status,
                'status_for_paid_at' => $status,
                'id' => $orderId,
            ]
        );
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->ensureColumn(
            table: 'orders',
            column: 'customer_name',
            ddl: 'ALTER TABLE orders ADD COLUMN customer_name VARCHAR(160) NULL AFTER reseller_id'
        );
        $this->ensureColumn(
            table: 'orders',
            column: 'customer_email',
            ddl: 'ALTER TABLE orders ADD COLUMN customer_email VARCHAR(190) NULL AFTER customer_name'
        );
        $this->ensureColumn(
            table: 'orders',
            column: 'customer_phone',
            ddl: 'ALTER TABLE orders ADD COLUMN customer_phone VARCHAR(20) NOT NULL AFTER customer_email'
        );
        $this->ensureColumn(
            table: 'orders',
            column: 'customer_country',
            ddl: 'ALTER TABLE orders ADD COLUMN customer_country VARCHAR(80) NULL AFTER customer_phone'
        );
        $this->ensureColumn(
            table: 'orders',
            column: 'customer_city',
            ddl: 'ALTER TABLE orders ADD COLUMN customer_city VARCHAR(120) NULL AFTER customer_country'
        );
        $this->ensureColumn(
            table: 'orders',
            column: 'customer_address',
            ddl: 'ALTER TABLE orders ADD COLUMN customer_address VARCHAR(255) NULL AFTER customer_city'
        );
        $this->ensureColumn(
            table: 'orders',
            column: 'customer_notes',
            ddl: 'ALTER TABLE orders ADD COLUMN customer_notes VARCHAR(500) NULL AFTER customer_address'
        );
        $this->ensureColumn(
            table: 'orders',
            column: 'selected_gateway',
            ddl: 'ALTER TABLE orders ADD COLUMN selected_gateway VARCHAR(40) NOT NULL DEFAULT "mpesa" AFTER customer_notes'
        );
        $this->ensureColumn(
            table: 'orders',
            column: 'parent_order_id',
            ddl: 'ALTER TABLE orders ADD COLUMN parent_order_id BIGINT UNSIGNED NULL AFTER selected_gateway'
        );
        $this->ensureColumn(
            table: 'orders',
            column: 'order_context',
            ddl: 'ALTER TABLE orders ADD COLUMN order_context ENUM("standard", "funnel_base", "funnel_upsell", "funnel_downsell") NOT NULL DEFAULT "standard" AFTER parent_order_id'
        );
        $this->ensureColumn(
            table: 'orders',
            column: 'funnel_session_token',
            ddl: 'ALTER TABLE orders ADD COLUMN funnel_session_token CHAR(64) NULL AFTER order_context'
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
