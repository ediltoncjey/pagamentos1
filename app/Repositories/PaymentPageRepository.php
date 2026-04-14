<?php

declare(strict_types=1);

namespace App\Repositories;

final class PaymentPageRepository extends BaseRepository
{
    private bool $schemaChecked = false;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $this->ensureSchema();

        return $this->fetchOne(
            'SELECT pp.*, p.name AS product_name, p.price AS product_price, p.currency, p.delivery_type,
                    p.external_url, p.file_path, p.image_path, p.product_type
             FROM payment_pages pp
             INNER JOIN products p ON p.id = pp.product_id
             WHERE pp.id = :id
             LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdAndReseller(int $id, int $resellerId): ?array
    {
        $this->ensureSchema();

        return $this->fetchOne(
            'SELECT pp.*, p.name AS product_name, p.price AS product_price, p.currency, p.delivery_type,
                    p.external_url, p.file_path, p.image_path, p.product_type
             FROM payment_pages pp
             INNER JOIN products p ON p.id = pp.product_id
             WHERE pp.id = :id
               AND pp.reseller_id = :reseller_id
             LIMIT 1',
            [
                'id' => $id,
                'reseller_id' => $resellerId,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $this->ensureSchema();

        return $this->fetchOne(
            'SELECT * FROM payment_pages WHERE slug = :slug LIMIT 1',
            ['slug' => $slug]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByReseller(int $resellerId): array
    {
        $this->ensureSchema();

        return $this->fetchAll(
            'SELECT pp.*, p.name AS product_name, p.price AS product_price, p.currency, p.product_type
             FROM payment_pages pp
             INNER JOIN products p ON p.id = pp.product_id
             WHERE pp.reseller_id = :reseller_id
             ORDER BY pp.id DESC',
            ['reseller_id' => $resellerId]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $this->ensureSchema();

        $this->execute(
            'INSERT INTO payment_pages (
                product_id, reseller_id, slug, title, description,
                require_customer_name, require_customer_email, require_customer_phone,
                collect_country, collect_city, collect_address, collect_notes,
                allow_mpesa, allow_emola, allow_visa, allow_paypal,
                status, view_count, created_at, updated_at
             ) VALUES (
                :product_id, :reseller_id, :slug, :title, :description,
                :require_customer_name, :require_customer_email, :require_customer_phone,
                :collect_country, :collect_city, :collect_address, :collect_notes,
                :allow_mpesa, :allow_emola, :allow_visa, :allow_paypal,
                :status, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )',
            [
                'product_id' => $data['product_id'],
                'reseller_id' => $data['reseller_id'],
                'slug' => $data['slug'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'require_customer_name' => (int) ($data['require_customer_name'] ?? 1) === 1 ? 1 : 0,
                'require_customer_email' => (int) ($data['require_customer_email'] ?? 1) === 1 ? 1 : 0,
                'require_customer_phone' => (int) ($data['require_customer_phone'] ?? 1) === 1 ? 1 : 0,
                'collect_country' => (int) ($data['collect_country'] ?? 1) === 1 ? 1 : 0,
                'collect_city' => (int) ($data['collect_city'] ?? 1) === 1 ? 1 : 0,
                'collect_address' => (int) ($data['collect_address'] ?? 1) === 1 ? 1 : 0,
                'collect_notes' => (int) ($data['collect_notes'] ?? 1) === 1 ? 1 : 0,
                'allow_mpesa' => (int) ($data['allow_mpesa'] ?? 1) === 1 ? 1 : 0,
                'allow_emola' => (int) ($data['allow_emola'] ?? 0) === 1 ? 1 : 0,
                'allow_visa' => (int) ($data['allow_visa'] ?? 0) === 1 ? 1 : 0,
                'allow_paypal' => (int) ($data['allow_paypal'] ?? 0) === 1 ? 1 : 0,
                'status' => $data['status'] ?? 'active',
            ]
        );

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateByIdAndReseller(int $id, int $resellerId, array $data): void
    {
        $this->ensureSchema();
        if ($data === []) {
            return;
        }

        $fields = [];
        $params = [
            'id' => $id,
            'reseller_id' => $resellerId,
        ];

        foreach ($data as $key => $value) {
            $fields[] = sprintf('%s = :%s', $key, $key);
            $params[$key] = $value;
        }

        $fields[] = 'updated_at = UTC_TIMESTAMP()';

        $sql = 'UPDATE payment_pages
                SET ' . implode(', ', $fields) . '
                WHERE id = :id
                  AND reseller_id = :reseller_id';
        $this->execute($sql, $params);
    }

    public function toggleStatus(int $id, int $resellerId): void
    {
        $this->ensureSchema();

        $this->execute(
            'UPDATE payment_pages
             SET status = CASE WHEN status = "active" THEN "inactive" ELSE "active" END,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND reseller_id = :reseller_id',
            [
                'id' => $id,
                'reseller_id' => $resellerId,
            ]
        );
    }

    public function incrementViews(int $id): void
    {
        $this->ensureSchema();

        $this->execute(
            'UPDATE payment_pages SET view_count = view_count + 1, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveBySlug(string $slug): ?array
    {
        $this->ensureSchema();

        return $this->fetchOne(
            'SELECT pp.*, p.name AS product_name, p.price AS product_price, p.currency, p.delivery_type,
                    p.external_url, p.file_path, p.image_path, p.description AS product_description, p.product_type
             FROM payment_pages pp
             INNER JOIN products p ON p.id = pp.product_id
             WHERE pp.slug = :slug
               AND pp.status = "active"
               AND p.is_active = 1
               AND p.deleted_at IS NULL
             LIMIT 1',
            ['slug' => $slug]
        );
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->ensureProductsSchema();
        $this->ensurePaymentPageColumns();
        $this->schemaChecked = true;
    }

    private function ensureProductsSchema(): void
    {
        $this->ensureColumn(
            table: 'products',
            column: 'product_type',
            ddl: 'ALTER TABLE products ADD COLUMN product_type ENUM("digital", "physical") NOT NULL DEFAULT "digital" AFTER description'
        );

        try {
            $this->execute(
                'ALTER TABLE products
                 MODIFY COLUMN delivery_type ENUM("external_link", "file_upload", "none") NOT NULL DEFAULT "external_link"'
            );
        } catch (\Throwable) {
        }
    }

    private function ensurePaymentPageColumns(): void
    {
        $this->ensureColumn(
            table: 'payment_pages',
            column: 'require_customer_name',
            ddl: 'ALTER TABLE payment_pages ADD COLUMN require_customer_name TINYINT(1) NOT NULL DEFAULT 1 AFTER description'
        );
        $this->ensureColumn(
            table: 'payment_pages',
            column: 'require_customer_email',
            ddl: 'ALTER TABLE payment_pages ADD COLUMN require_customer_email TINYINT(1) NOT NULL DEFAULT 1 AFTER require_customer_name'
        );
        $this->ensureColumn(
            table: 'payment_pages',
            column: 'require_customer_phone',
            ddl: 'ALTER TABLE payment_pages ADD COLUMN require_customer_phone TINYINT(1) NOT NULL DEFAULT 1 AFTER require_customer_email'
        );
        $this->ensureColumn(
            table: 'payment_pages',
            column: 'collect_country',
            ddl: 'ALTER TABLE payment_pages ADD COLUMN collect_country TINYINT(1) NOT NULL DEFAULT 1 AFTER require_customer_phone'
        );
        $this->ensureColumn(
            table: 'payment_pages',
            column: 'collect_city',
            ddl: 'ALTER TABLE payment_pages ADD COLUMN collect_city TINYINT(1) NOT NULL DEFAULT 1 AFTER collect_country'
        );
        $this->ensureColumn(
            table: 'payment_pages',
            column: 'collect_address',
            ddl: 'ALTER TABLE payment_pages ADD COLUMN collect_address TINYINT(1) NOT NULL DEFAULT 1 AFTER collect_city'
        );
        $this->ensureColumn(
            table: 'payment_pages',
            column: 'collect_notes',
            ddl: 'ALTER TABLE payment_pages ADD COLUMN collect_notes TINYINT(1) NOT NULL DEFAULT 1 AFTER collect_address'
        );
        $this->ensureColumn(
            table: 'payment_pages',
            column: 'allow_mpesa',
            ddl: 'ALTER TABLE payment_pages ADD COLUMN allow_mpesa TINYINT(1) NOT NULL DEFAULT 1 AFTER collect_notes'
        );
        $this->ensureColumn(
            table: 'payment_pages',
            column: 'allow_emola',
            ddl: 'ALTER TABLE payment_pages ADD COLUMN allow_emola TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_mpesa'
        );
        $this->ensureColumn(
            table: 'payment_pages',
            column: 'allow_visa',
            ddl: 'ALTER TABLE payment_pages ADD COLUMN allow_visa TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_emola'
        );
        $this->ensureColumn(
            table: 'payment_pages',
            column: 'allow_paypal',
            ddl: 'ALTER TABLE payment_pages ADD COLUMN allow_paypal TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_visa'
        );
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

