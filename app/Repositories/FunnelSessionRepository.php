<?php

declare(strict_types=1);

namespace App\Repositories;

final class FunnelSessionRepository extends BaseRepository
{
    private bool $ensured = false;

    /**
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        $this->ensureTable();
        return $this->fetchOne(
            'SELECT *
             FROM funnel_sessions
             WHERE token = :token
             LIMIT 1',
            ['token' => $token]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $this->ensureTable();
        $this->execute(
            'INSERT INTO funnel_sessions (
                token, funnel_id, current_step_id, customer_name, customer_email, customer_phone,
                base_order_id, last_order_id, status, metadata, expires_at, created_at, updated_at
             ) VALUES (
                :token, :funnel_id, :current_step_id, :customer_name, :customer_email, :customer_phone,
                :base_order_id, :last_order_id, :status, :metadata, :expires_at, UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )',
            [
                'token' => $data['token'],
                'funnel_id' => $data['funnel_id'],
                'current_step_id' => $data['current_step_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'base_order_id' => $data['base_order_id'] ?? null,
                'last_order_id' => $data['last_order_id'] ?? null,
                'status' => $data['status'] ?? 'active',
                'metadata' => $data['metadata'] ?? '{}',
                'expires_at' => $data['expires_at'] ?? null,
            ]
        );

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateByToken(string $token, array $data): void
    {
        $this->ensureTable();
        if ($data === []) {
            return;
        }

        $fields = [];
        $params = ['token' => $token];
        foreach ($data as $key => $value) {
            $fields[] = $key . ' = :' . $key;
            $params[$key] = $value;
        }
        $fields[] = 'updated_at = UTC_TIMESTAMP()';

        $this->execute(
            'UPDATE funnel_sessions
             SET ' . implode(', ', $fields) . '
             WHERE token = :token',
            $params
        );
    }

    private function ensureTable(): void
    {
        if ($this->ensured) {
            return;
        }

        $this->execute(
            'CREATE TABLE IF NOT EXISTS funnel_sessions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                token CHAR(64) NOT NULL,
                funnel_id BIGINT UNSIGNED NOT NULL,
                current_step_id BIGINT UNSIGNED NULL,
                customer_name VARCHAR(160) NULL,
                customer_email VARCHAR(190) NULL,
                customer_phone VARCHAR(20) NULL,
                base_order_id BIGINT UNSIGNED NULL,
                last_order_id BIGINT UNSIGNED NULL,
                status ENUM("active", "completed", "expired") NOT NULL DEFAULT "active",
                metadata JSON NULL,
                expires_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT uq_funnel_sessions_token UNIQUE (token),
                CONSTRAINT fk_funnel_sessions_funnel FOREIGN KEY (funnel_id) REFERENCES funnels(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_funnel_sessions_step FOREIGN KEY (current_step_id) REFERENCES funnel_steps(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_funnel_sessions_base_order FOREIGN KEY (base_order_id) REFERENCES orders(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_funnel_sessions_last_order FOREIGN KEY (last_order_id) REFERENCES orders(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                INDEX idx_funnel_sessions_funnel_status (funnel_id, status),
                INDEX idx_funnel_sessions_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->ensured = true;
    }
}

