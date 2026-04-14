<?php

declare(strict_types=1);

namespace App\Repositories;

final class FunnelRepository extends BaseRepository
{
    private bool $ensured = false;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByReseller(int $resellerId): array
    {
        $this->ensureTable();
        return $this->fetchAll(
            'SELECT *
             FROM funnels
             WHERE reseller_id = :reseller_id
             ORDER BY created_at DESC, id DESC',
            ['reseller_id' => $resellerId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $this->ensureTable();
        return $this->fetchOne(
            'SELECT *
             FROM funnels
             WHERE id = :id
             LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdAndReseller(int $funnelId, int $resellerId): ?array
    {
        $this->ensureTable();
        return $this->fetchOne(
            'SELECT *
             FROM funnels
             WHERE id = :id
               AND reseller_id = :reseller_id
             LIMIT 1',
            [
                'id' => $funnelId,
                'reseller_id' => $resellerId,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveBySlug(string $slug): ?array
    {
        $this->ensureTable();
        return $this->fetchOne(
            'SELECT *
             FROM funnels
             WHERE slug = :slug
               AND status = "active"
             LIMIT 1',
            ['slug' => $slug]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $this->ensureTable();
        return $this->fetchOne(
            'SELECT *
             FROM funnels
             WHERE slug = :slug
             LIMIT 1',
            ['slug' => $slug]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $this->ensureTable();
        $this->execute(
            'INSERT INTO funnels (
                reseller_id, name, slug, description, status, created_at, updated_at
             ) VALUES (
                :reseller_id, :name, :slug, :description, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )',
            [
                'reseller_id' => $data['reseller_id'],
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'active',
            ]
        );

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateByIdAndReseller(int $funnelId, int $resellerId, array $data): void
    {
        $this->ensureTable();
        if ($data === []) {
            return;
        }

        $fields = [];
        $params = [
            'id' => $funnelId,
            'reseller_id' => $resellerId,
        ];

        foreach ($data as $key => $value) {
            $fields[] = $key . ' = :' . $key;
            $params[$key] = $value;
        }

        $fields[] = 'updated_at = UTC_TIMESTAMP()';

        $this->execute(
            'UPDATE funnels
             SET ' . implode(', ', $fields) . '
             WHERE id = :id
               AND reseller_id = :reseller_id',
            $params
        );
    }

    private function ensureTable(): void
    {
        if ($this->ensured) {
            return;
        }

        $this->execute(
            'CREATE TABLE IF NOT EXISTS funnels (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                reseller_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(180) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                description TEXT NULL,
                status ENUM("active", "inactive") NOT NULL DEFAULT "active",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT uq_funnels_slug UNIQUE (slug),
                CONSTRAINT fk_funnels_reseller FOREIGN KEY (reseller_id) REFERENCES users(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                INDEX idx_funnels_reseller_status (reseller_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->ensured = true;
    }
}
