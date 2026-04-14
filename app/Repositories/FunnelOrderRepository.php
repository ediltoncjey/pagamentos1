<?php

declare(strict_types=1);

namespace App\Repositories;

final class FunnelOrderRepository extends BaseRepository
{
    private bool $ensured = false;

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $this->ensureTable();
        $this->execute(
            'INSERT INTO funnel_orders (
                funnel_session_id, funnel_step_id, order_id, offer_type, created_at
             ) VALUES (
                :funnel_session_id, :funnel_step_id, :order_id, :offer_type, UTC_TIMESTAMP()
             )',
            [
                'funnel_session_id' => $data['funnel_session_id'],
                'funnel_step_id' => $data['funnel_step_id'] ?? null,
                'order_id' => $data['order_id'],
                'offer_type' => $data['offer_type'] ?? 'base',
            ]
        );

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByOrderId(int $orderId): ?array
    {
        $this->ensureTable();
        return $this->fetchOne(
            'SELECT *
             FROM funnel_orders
             WHERE order_id = :order_id
             LIMIT 1',
            ['order_id' => $orderId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBySession(int $sessionId): array
    {
        $this->ensureTable();
        return $this->fetchAll(
            'SELECT fo.*, o.order_no, o.amount, o.currency, o.status AS order_status
             FROM funnel_orders fo
             INNER JOIN orders o ON o.id = fo.order_id
             WHERE fo.funnel_session_id = :session_id
             ORDER BY fo.created_at ASC, fo.id ASC',
            ['session_id' => $sessionId]
        );
    }

    private function ensureTable(): void
    {
        if ($this->ensured) {
            return;
        }

        $this->execute(
            'CREATE TABLE IF NOT EXISTS funnel_orders (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                funnel_session_id BIGINT UNSIGNED NOT NULL,
                funnel_step_id BIGINT UNSIGNED NULL,
                order_id BIGINT UNSIGNED NOT NULL,
                offer_type ENUM("base", "upsell", "downsell") NOT NULL DEFAULT "base",
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_funnel_orders_session FOREIGN KEY (funnel_session_id) REFERENCES funnel_sessions(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_funnel_orders_step FOREIGN KEY (funnel_step_id) REFERENCES funnel_steps(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_funnel_orders_order FOREIGN KEY (order_id) REFERENCES orders(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                INDEX idx_funnel_orders_session (funnel_session_id, created_at),
                INDEX idx_funnel_orders_order (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->ensured = true;
    }
}
