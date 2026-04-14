<?php

declare(strict_types=1);

namespace App\Repositories;

final class ProductRepository extends BaseRepository
{
    private bool $schemaChecked = false;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $this->ensureSchema();
        return $this->fetchOne(
            'SELECT p.*, u.name AS reseller_name, u.email AS reseller_email
             FROM products p
             INNER JOIN users u ON u.id = p.reseller_id
             WHERE p.id = :id AND p.deleted_at IS NULL
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
            'SELECT * FROM products
             WHERE id = :id
               AND reseller_id = :reseller_id
               AND deleted_at IS NULL
             LIMIT 1',
            [
                'id' => $id,
                'reseller_id' => $resellerId,
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByReseller(int $resellerId): array
    {
        $this->ensureSchema();
        return $this->fetchAll(
            'SELECT *
             FROM products
             WHERE reseller_id = :reseller_id
               AND deleted_at IS NULL
             ORDER BY id DESC',
            ['reseller_id' => $resellerId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(int $limit = 200): array
    {
        $this->ensureSchema();
        return $this->fetchAll(
            'SELECT p.*, u.name AS reseller_name, u.email AS reseller_email
             FROM products p
             INNER JOIN users u ON u.id = p.reseller_id
             WHERE p.deleted_at IS NULL
             ORDER BY p.id DESC
             LIMIT ' . max(1, (int) $limit)
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $this->ensureSchema();
        $this->execute(
            'INSERT INTO products (
                reseller_id, name, description, product_type, price, currency, image_path, delivery_type, external_url,
                file_path, is_active, created_at, updated_at
             ) VALUES (
                :reseller_id, :name, :description, :product_type, :price, :currency, :image_path, :delivery_type, :external_url,
                :file_path, :is_active, UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )',
            [
                'reseller_id' => $data['reseller_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'product_type' => $data['product_type'] ?? 'digital',
                'price' => $data['price'],
                'currency' => $data['currency'] ?? 'MZN',
                'image_path' => $data['image_path'] ?? null,
                'delivery_type' => $data['delivery_type'],
                'external_url' => $data['external_url'] ?? null,
                'file_path' => $data['file_path'] ?? null,
                'is_active' => $data['is_active'] ?? 1,
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

        $sql = 'UPDATE products SET ' . implode(', ', $fields) . '
                WHERE id = :id AND reseller_id = :reseller_id AND deleted_at IS NULL';
        $this->execute($sql, $params);
    }

    public function toggleStatus(int $id, int $resellerId): void
    {
        $this->ensureSchema();
        $this->execute(
            'UPDATE products
             SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND reseller_id = :reseller_id
               AND deleted_at IS NULL',
            [
                'id' => $id,
                'reseller_id' => $resellerId,
            ]
        );
    }

    public function softDelete(int $id, int $resellerId): void
    {
        $this->ensureSchema();
        $this->execute(
            'UPDATE products
             SET deleted_at = UTC_TIMESTAMP(),
                 is_active = 0,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND reseller_id = :reseller_id
               AND deleted_at IS NULL',
            [
                'id' => $id,
                'reseller_id' => $resellerId,
            ]
        );
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

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
