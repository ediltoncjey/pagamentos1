<?php

declare(strict_types=1);

namespace App\Repositories;

final class EmailTemplateRepository extends BaseRepository
{
    private bool $ensured = false;

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByType(?int $resellerId, string $type): ?array
    {
        $this->ensureTable();
        if ($resellerId !== null && $resellerId > 0) {
            $row = $this->fetchOne(
                'SELECT *
                 FROM email_templates
                 WHERE reseller_id = :reseller_id
                   AND template_type = :template_type
                   AND is_active = 1
                 ORDER BY id DESC
                 LIMIT 1',
                [
                    'reseller_id' => $resellerId,
                    'template_type' => $type,
                ]
            );
            if ($row !== null) {
                return $row;
            }
        }

        return $this->fetchOne(
            'SELECT *
             FROM email_templates
             WHERE reseller_id IS NULL
               AND template_type = :template_type
               AND is_active = 1
             ORDER BY id DESC
             LIMIT 1',
            ['template_type' => $type]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByReseller(int $resellerId): array
    {
        $this->ensureTable();
        return $this->fetchAll(
            'SELECT *
             FROM email_templates
             WHERE reseller_id = :reseller_id
             ORDER BY template_type ASC, id DESC',
            ['reseller_id' => $resellerId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDefaults(): array
    {
        $this->ensureTable();
        return $this->fetchAll(
            'SELECT *
             FROM email_templates
             WHERE reseller_id IS NULL
             ORDER BY template_type ASC, id DESC'
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
             FROM email_templates
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
     * @param array<string, mixed> $data
     */
    public function upsertForReseller(int $resellerId, string $type, array $data): void
    {
        $this->ensureTable();
        $existing = $this->fetchOne(
            'SELECT id
             FROM email_templates
             WHERE reseller_id = :reseller_id
               AND template_type = :template_type
             LIMIT 1',
            [
                'reseller_id' => $resellerId,
                'template_type' => $type,
            ]
        );

        if ($existing === null) {
            $this->execute(
                'INSERT INTO email_templates (
                    reseller_id, template_type, subject, body_html, is_active, created_at, updated_at
                 ) VALUES (
                    :reseller_id, :template_type, :subject, :body_html, :is_active, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                 )',
                [
                    'reseller_id' => $resellerId,
                    'template_type' => $type,
                    'subject' => $data['subject'],
                    'body_html' => $data['body_html'],
                    'is_active' => $data['is_active'] ?? 1,
                ]
            );
            return;
        }

        $this->execute(
            'UPDATE email_templates
             SET subject = :subject,
                 body_html = :body_html,
                 is_active = :is_active,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            [
                'id' => (int) $existing['id'],
                'subject' => $data['subject'],
                'body_html' => $data['body_html'],
                'is_active' => $data['is_active'] ?? 1,
            ]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createDefault(string $type, array $data): void
    {
        $this->ensureTable();
        $existing = $this->fetchOne(
            'SELECT id
             FROM email_templates
             WHERE reseller_id IS NULL
               AND template_type = :template_type
             LIMIT 1',
            ['template_type' => $type]
        );
        if ($existing !== null) {
            return;
        }

        $this->execute(
            'INSERT INTO email_templates (
                reseller_id, template_type, subject, body_html, is_active, created_at, updated_at
             ) VALUES (
                NULL, :template_type, :subject, :body_html, :is_active, UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )',
            [
                'template_type' => $type,
                'subject' => $data['subject'],
                'body_html' => $data['body_html'],
                'is_active' => $data['is_active'] ?? 1,
            ]
        );
    }

    private function ensureTable(): void
    {
        if ($this->ensured) {
            return;
        }

        $this->execute(
            'CREATE TABLE IF NOT EXISTS email_templates (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                reseller_id BIGINT UNSIGNED NULL,
                template_type ENUM("purchase_confirmation", "product_access", "upsell_offer") NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body_html LONGTEXT NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_email_templates_reseller FOREIGN KEY (reseller_id) REFERENCES users(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                INDEX idx_email_templates_scope (reseller_id, template_type, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->ensured = true;
    }
}
