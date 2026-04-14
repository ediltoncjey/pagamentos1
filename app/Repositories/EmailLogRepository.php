<?php

declare(strict_types=1);

namespace App\Repositories;

final class EmailLogRepository extends BaseRepository
{
    private bool $ensured = false;

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $this->ensureTable();
        $this->execute(
            'INSERT INTO email_logs (
                reseller_id, order_id, recipient_email, template_type, subject, body_html,
                status, provider_message, error_message, attempt_count, sent_at, created_at, updated_at
             ) VALUES (
                :reseller_id, :order_id, :recipient_email, :template_type, :subject, :body_html,
                :status, :provider_message, :error_message, :attempt_count, :sent_at, UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )',
            [
                'reseller_id' => $data['reseller_id'] ?? null,
                'order_id' => $data['order_id'] ?? null,
                'recipient_email' => $data['recipient_email'],
                'template_type' => $data['template_type'],
                'subject' => $data['subject'],
                'body_html' => $data['body_html'],
                'status' => $data['status'] ?? 'failed',
                'provider_message' => $data['provider_message'] ?? null,
                'error_message' => $data['error_message'] ?? null,
                'attempt_count' => $data['attempt_count'] ?? 1,
                'sent_at' => $data['sent_at'] ?? null,
            ]
        );

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByReseller(int $resellerId, int $limit = 120): array
    {
        $this->ensureTable();
        $limit = max(1, min(300, $limit));
        return $this->fetchAll(
            'SELECT *
             FROM email_logs
             WHERE reseller_id = :reseller_id
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit,
            ['reseller_id' => $resellerId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdAndReseller(int $id, int $resellerId): ?array
    {
        $this->ensureTable();
        return $this->fetchOne(
            'SELECT *
             FROM email_logs
             WHERE id = :id
               AND reseller_id = :reseller_id
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
    public function findByOrderAndTemplate(int $orderId, string $templateType): ?array
    {
        $this->ensureTable();
        return $this->fetchOne(
            'SELECT *
             FROM email_logs
             WHERE order_id = :order_id
               AND template_type = :template_type
               AND status = "sent"
             ORDER BY id DESC
             LIMIT 1',
            [
                'order_id' => $orderId,
                'template_type' => $templateType,
            ]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateStatus(int $id, array $data): void
    {
        $this->ensureTable();
        if ($data === []) {
            return;
        }

        $fields = [];
        $params = ['id' => $id];
        foreach ($data as $key => $value) {
            $fields[] = $key . ' = :' . $key;
            $params[$key] = $value;
        }
        $fields[] = 'updated_at = UTC_TIMESTAMP()';

        $this->execute(
            'UPDATE email_logs
             SET ' . implode(', ', $fields) . '
             WHERE id = :id',
            $params
        );
    }

    private function ensureTable(): void
    {
        if ($this->ensured) {
            return;
        }

        $this->execute(
            'CREATE TABLE IF NOT EXISTS email_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                reseller_id BIGINT UNSIGNED NULL,
                order_id BIGINT UNSIGNED NULL,
                recipient_email VARCHAR(190) NOT NULL,
                template_type ENUM("purchase_confirmation", "product_access", "upsell_offer") NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body_html LONGTEXT NOT NULL,
                status ENUM("sent", "failed") NOT NULL DEFAULT "failed",
                provider_message TEXT NULL,
                error_message TEXT NULL,
                attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
                sent_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_email_logs_reseller FOREIGN KEY (reseller_id) REFERENCES users(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT fk_email_logs_order FOREIGN KEY (order_id) REFERENCES orders(id)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                INDEX idx_email_logs_reseller_created (reseller_id, created_at),
                INDEX idx_email_logs_order_created (order_id, created_at),
                INDEX idx_email_logs_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->ensured = true;
    }
}
