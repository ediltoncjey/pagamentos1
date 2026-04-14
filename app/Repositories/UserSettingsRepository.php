<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserSettingsRepository extends BaseRepository
{
    private bool $ensured = false;

    /**
     * @return array<string, mixed>
     */
    public function getByUserId(int $userId): array
    {
        $this->ensureTable();
        $row = $this->fetchOne(
            'SELECT *
             FROM user_settings
             WHERE user_id = :user_id
             LIMIT 1',
            ['user_id' => $userId]
        );

        if ($row === null) {
            $this->upsert($userId, []);
            $row = $this->fetchOne(
                'SELECT *
                 FROM user_settings
                 WHERE user_id = :user_id
                 LIMIT 1',
                ['user_id' => $userId]
            );
        }

        return $row ?? $this->defaultRow($userId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsert(int $userId, array $data): void
    {
        $this->ensureTable();
        $defaults = $this->defaultRow($userId);
        $payload = array_merge($defaults, $data);

        $this->execute(
            'INSERT INTO user_settings (
                user_id, avatar_path, theme_preference, language, timezone,
                notify_sales, notify_payment_errors, notify_security, notify_system,
                email_reports, email_marketing, dashboard_show_charts, dashboard_show_kpis,
                created_at, updated_at
             ) VALUES (
                :user_id, :avatar_path, :theme_preference, :language, :timezone,
                :notify_sales, :notify_payment_errors, :notify_security, :notify_system,
                :email_reports, :email_marketing, :dashboard_show_charts, :dashboard_show_kpis,
                UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )
             ON DUPLICATE KEY UPDATE
                avatar_path = VALUES(avatar_path),
                theme_preference = VALUES(theme_preference),
                language = VALUES(language),
                timezone = VALUES(timezone),
                notify_sales = VALUES(notify_sales),
                notify_payment_errors = VALUES(notify_payment_errors),
                notify_security = VALUES(notify_security),
                notify_system = VALUES(notify_system),
                email_reports = VALUES(email_reports),
                email_marketing = VALUES(email_marketing),
                dashboard_show_charts = VALUES(dashboard_show_charts),
                dashboard_show_kpis = VALUES(dashboard_show_kpis),
                updated_at = UTC_TIMESTAMP()',
            [
                'user_id' => $userId,
                'avatar_path' => $payload['avatar_path'] ?? null,
                'theme_preference' => $payload['theme_preference'],
                'language' => $payload['language'],
                'timezone' => $payload['timezone'],
                'notify_sales' => (int) $payload['notify_sales'],
                'notify_payment_errors' => (int) $payload['notify_payment_errors'],
                'notify_security' => (int) $payload['notify_security'],
                'notify_system' => (int) $payload['notify_system'],
                'email_reports' => (int) $payload['email_reports'],
                'email_marketing' => (int) $payload['email_marketing'],
                'dashboard_show_charts' => (int) $payload['dashboard_show_charts'],
                'dashboard_show_kpis' => (int) $payload['dashboard_show_kpis'],
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultRow(int $userId): array
    {
        return [
            'user_id' => $userId,
            'avatar_path' => null,
            'theme_preference' => 'system',
            'language' => 'pt-MZ',
            'timezone' => 'Africa/Maputo',
            'notify_sales' => 1,
            'notify_payment_errors' => 1,
            'notify_security' => 1,
            'notify_system' => 1,
            'email_reports' => 1,
            'email_marketing' => 0,
            'dashboard_show_charts' => 1,
            'dashboard_show_kpis' => 1,
        ];
    }

    private function ensureTable(): void
    {
        if ($this->ensured) {
            return;
        }

        $this->execute(
            'CREATE TABLE IF NOT EXISTS user_settings (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                avatar_path VARCHAR(500) NULL,
                theme_preference ENUM("system", "light", "dark") NOT NULL DEFAULT "system",
                language VARCHAR(12) NOT NULL DEFAULT "pt-MZ",
                timezone VARCHAR(64) NOT NULL DEFAULT "Africa/Maputo",
                notify_sales TINYINT(1) NOT NULL DEFAULT 1,
                notify_payment_errors TINYINT(1) NOT NULL DEFAULT 1,
                notify_security TINYINT(1) NOT NULL DEFAULT 1,
                notify_system TINYINT(1) NOT NULL DEFAULT 1,
                email_reports TINYINT(1) NOT NULL DEFAULT 1,
                email_marketing TINYINT(1) NOT NULL DEFAULT 0,
                dashboard_show_charts TINYINT(1) NOT NULL DEFAULT 1,
                dashboard_show_kpis TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT uq_user_settings_user UNIQUE (user_id),
                CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES users(id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->ensured = true;
    }
}

