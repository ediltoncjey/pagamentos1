<?php

declare(strict_types=1);

namespace App\Repositories;

final class NotificationReadRepository extends BaseRepository
{
    private bool $ensured = false;

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    public function listReadKeys(int $userId, array $keys): array
    {
        $this->ensureTable();
        if ($keys === []) {
            return [];
        }

        $placeholders = [];
        $params = ['user_id' => $userId];
        foreach ($keys as $index => $key) {
            $param = 'k' . $index;
            $placeholders[] = ':' . $param;
            $params[$param] = $key;
        }

        $rows = $this->fetchAll(
            'SELECT notification_key
             FROM notification_reads
             WHERE user_id = :user_id
               AND notification_key IN (' . implode(',', $placeholders) . ')',
            $params
        );

        $readKeys = [];
        foreach ($rows as $row) {
            $readKeys[] = (string) ($row['notification_key'] ?? '');
        }

        return $readKeys;
    }

    public function markRead(int $userId, string $key): void
    {
        $this->ensureTable();
        $this->execute(
            'INSERT INTO notification_reads (user_id, notification_key, read_at, created_at)
             VALUES (:user_id, :notification_key, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE read_at = UTC_TIMESTAMP()',
            [
                'user_id' => $userId,
                'notification_key' => $key,
            ]
        );
    }

    /**
     * @param list<string> $keys
     */
    public function markManyRead(int $userId, array $keys): void
    {
        foreach ($keys as $key) {
            if (trim($key) === '') {
                continue;
            }

            $this->markRead($userId, $key);
        }
    }

    private function ensureTable(): void
    {
        if ($this->ensured) {
            return;
        }

        $this->execute(
            'CREATE TABLE IF NOT EXISTS notification_reads (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                notification_key VARCHAR(120) NOT NULL,
                read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uq_notification_reads_key UNIQUE (user_id, notification_key),
                CONSTRAINT fk_notification_reads_user FOREIGN KEY (user_id) REFERENCES users(id)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                INDEX idx_notification_reads_user_read_at (user_id, read_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->ensured = true;
    }
}

