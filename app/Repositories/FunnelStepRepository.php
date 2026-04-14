<?php

declare(strict_types=1);

namespace App\Repositories;

final class FunnelStepRepository extends BaseRepository
{
    private bool $ensured = false;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByFunnel(int $funnelId, bool $onlyActive = false): array
    {
        $this->ensureTable();
        $sql = 'SELECT fs.*, pp.slug AS payment_page_slug, pp.title AS payment_page_title, p.name AS product_name
                FROM funnel_steps fs
                LEFT JOIN payment_pages pp ON pp.id = fs.payment_page_id
                LEFT JOIN products p ON p.id = fs.product_id
                WHERE fs.funnel_id = :funnel_id';
        if ($onlyActive) {
            $sql .= ' AND fs.is_active = 1';
        }

        $sql .= ' ORDER BY fs.sequence_no ASC, fs.id ASC';
        return $this->fetchAll($sql, ['funnel_id' => $funnelId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $stepId): ?array
    {
        $this->ensureTable();
        return $this->fetchOne(
            'SELECT *
             FROM funnel_steps
             WHERE id = :id
             LIMIT 1',
            ['id' => $stepId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdInFunnel(int $stepId, int $funnelId): ?array
    {
        $this->ensureTable();
        return $this->fetchOne(
            'SELECT *
             FROM funnel_steps
             WHERE id = :id
               AND funnel_id = :funnel_id
             LIMIT 1',
            [
                'id' => $stepId,
                'funnel_id' => $funnelId,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFirstActiveByType(int $funnelId, string $type): ?array
    {
        $this->ensureTable();
        return $this->fetchOne(
            'SELECT *
             FROM funnel_steps
             WHERE funnel_id = :funnel_id
               AND step_type = :step_type
               AND is_active = 1
             ORDER BY sequence_no ASC, id ASC
             LIMIT 1',
            [
                'funnel_id' => $funnelId,
                'step_type' => $type,
            ]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $this->ensureTable();
        $this->execute(
            'INSERT INTO funnel_steps (
                funnel_id, step_type, title, description, payment_page_id, product_id,
                sequence_no, is_active, accept_label, reject_label, created_at, updated_at
             ) VALUES (
                :funnel_id, :step_type, :title, :description, :payment_page_id, :product_id,
                :sequence_no, :is_active, :accept_label, :reject_label, UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )',
            [
                'funnel_id' => $data['funnel_id'],
                'step_type' => $data['step_type'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'payment_page_id' => $data['payment_page_id'] ?? null,
                'product_id' => $data['product_id'] ?? null,
                'sequence_no' => $data['sequence_no'] ?? 1,
                'is_active' => $data['is_active'] ?? 1,
                'accept_label' => $data['accept_label'] ?? null,
                'reject_label' => $data['reject_label'] ?? null,
            ]
        );

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateById(int $stepId, array $data): void
    {
        $this->ensureTable();
        if ($data === []) {
            return;
        }

        $fields = [];
        $params = ['id' => $stepId];
        foreach ($data as $key => $value) {
            $fields[] = $key . ' = :' . $key;
            $params[$key] = $value;
        }

        $fields[] = 'updated_at = UTC_TIMESTAMP()';

        $this->execute(
            'UPDATE funnel_steps
             SET ' . implode(', ', $fields) . '
             WHERE id = :id',
            $params
        );
    }

    public function deleteById(int $stepId): void
    {
        $this->ensureTable();
        $this->execute(
            'DELETE FROM funnel_steps WHERE id = :id',
            ['id' => $stepId]
        );
    }

    private function ensureTable(): void
    {
        if ($this->ensured) {
            return;
        }

        $this->execute(
            'CREATE TABLE IF NOT EXISTS funnel_steps (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                funnel_id BIGINT UNSIGNED NOT NULL,
                step_type ENUM("landing", "checkout", "confirmation", "upsell", "downsell", "thank_you") NOT NULL,
                title VARCHAR(190) NOT NULL,
                description TEXT NULL,
                payment_page_id BIGINT UNSIGNED NULL,
                product_id BIGINT UNSIGNED NULL,
                sequence_no INT UNSIGNED NOT NULL DEFAULT 1,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                accept_label VARCHAR(90) NULL,
                reject_label VARCHAR(90) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_funnel_steps_funnel FOREIGN KEY (funnel_id) REFERENCES funnels(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT fk_funnel_steps_payment_page FOREIGN KEY (payment_page_id) REFERENCES payment_pages(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_funnel_steps_product FOREIGN KEY (product_id) REFERENCES products(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                INDEX idx_funnel_steps_order (funnel_id, sequence_no, is_active),
                INDEX idx_funnel_steps_type (funnel_id, step_type, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->ensured = true;
    }
}

