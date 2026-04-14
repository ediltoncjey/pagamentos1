<?php

declare(strict_types=1);

namespace App\Repositories;

final class PaymentGatewayRepository extends BaseRepository
{
    private bool $ensured = false;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $this->ensureTable();

        return $this->fetchAll(
            'SELECT *
             FROM payment_gateways
             ORDER BY sort_order ASC, id ASC'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEnabled(): array
    {
        $this->ensureTable();

        return $this->fetchAll(
            'SELECT *
             FROM payment_gateways
             WHERE is_enabled = 1
             ORDER BY sort_order ASC, id ASC'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        $this->ensureTable();

        return $this->fetchOne(
            'SELECT *
             FROM payment_gateways
             WHERE code = :code
             LIMIT 1',
            ['code' => strtolower(trim($code))]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsert(array $data): void
    {
        $this->ensureTable();

        $this->execute(
            'INSERT INTO payment_gateways (
                code, display_name, description, icon_class,
                is_enabled, is_configured, is_live, sort_order, settings_json,
                created_at, updated_at
             ) VALUES (
                :code, :display_name, :description, :icon_class,
                :is_enabled, :is_configured, :is_live, :sort_order, :settings_json,
                UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )
             ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                description = VALUES(description),
                icon_class = VALUES(icon_class),
                is_enabled = VALUES(is_enabled),
                is_configured = VALUES(is_configured),
                is_live = VALUES(is_live),
                sort_order = VALUES(sort_order),
                settings_json = VALUES(settings_json),
                updated_at = UTC_TIMESTAMP()',
            [
                'code' => strtolower(trim((string) ($data['code'] ?? ''))),
                'display_name' => (string) ($data['display_name'] ?? 'Gateway'),
                'description' => $data['description'] ?? null,
                'icon_class' => (string) ($data['icon_class'] ?? 'bi-credit-card'),
                'is_enabled' => (int) ($data['is_enabled'] ?? 0) === 1 ? 1 : 0,
                'is_configured' => (int) ($data['is_configured'] ?? 0) === 1 ? 1 : 0,
                'is_live' => (int) ($data['is_live'] ?? 0) === 1 ? 1 : 0,
                'sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
                'settings_json' => $data['settings_json'] ?? '{}',
            ]
        );
    }

    private function ensureTable(): void
    {
        if ($this->ensured) {
            return;
        }

        $this->execute(
            'CREATE TABLE IF NOT EXISTS payment_gateways (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(40) NOT NULL,
                display_name VARCHAR(80) NOT NULL,
                description VARCHAR(255) NULL,
                icon_class VARCHAR(80) NOT NULL DEFAULT "bi-credit-card",
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                is_configured TINYINT(1) NOT NULL DEFAULT 0,
                is_live TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                settings_json JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT uq_payment_gateways_code UNIQUE (code),
                INDEX idx_payment_gateways_enabled_sort (is_enabled, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->ensureColumn('display_name', 'ALTER TABLE payment_gateways ADD COLUMN display_name VARCHAR(80) NOT NULL DEFAULT "Gateway" AFTER code');
        $this->ensureColumn('description', 'ALTER TABLE payment_gateways ADD COLUMN description VARCHAR(255) NULL AFTER display_name');
        $this->ensureColumn('icon_class', 'ALTER TABLE payment_gateways ADD COLUMN icon_class VARCHAR(80) NOT NULL DEFAULT "bi-credit-card" AFTER description');
        $this->ensureColumn('is_enabled', 'ALTER TABLE payment_gateways ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER icon_class');
        $this->ensureColumn('is_configured', 'ALTER TABLE payment_gateways ADD COLUMN is_configured TINYINT(1) NOT NULL DEFAULT 0 AFTER is_enabled');
        $this->ensureColumn('is_live', 'ALTER TABLE payment_gateways ADD COLUMN is_live TINYINT(1) NOT NULL DEFAULT 0 AFTER is_configured');
        $this->ensureColumn('sort_order', 'ALTER TABLE payment_gateways ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_live');
        $this->ensureColumn('settings_json', 'ALTER TABLE payment_gateways ADD COLUMN settings_json JSON NULL AFTER sort_order');

        $this->ensured = true;
    }

    private function ensureColumn(string $column, string $ddl): void
    {
        $exists = $this->fetchOne(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = "payment_gateways"
               AND COLUMN_NAME = :column
             LIMIT 1',
            ['column' => $column]
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

