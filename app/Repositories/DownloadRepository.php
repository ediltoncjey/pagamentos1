<?php

declare(strict_types=1);

namespace App\Repositories;

final class DownloadRepository extends BaseRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM downloads WHERE token = :token LIMIT 1',
            ['token' => $token]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestByOrderId(int $orderId): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM downloads WHERE order_id = :order_id ORDER BY id DESC LIMIT 1',
            ['order_id' => $orderId]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $this->execute(
            'INSERT INTO downloads (
                order_id, product_id, token, delivery_mode, target_path, target_url,
                expires_at, max_downloads, download_count, status, created_at, updated_at
             ) VALUES (
                :order_id, :product_id, :token, :delivery_mode, :target_path, :target_url,
                :expires_at, :max_downloads, 0, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )',
            [
                'order_id' => $data['order_id'],
                'product_id' => $data['product_id'],
                'token' => $data['token'],
                'delivery_mode' => $data['delivery_mode'],
                'target_path' => $data['target_path'] ?? null,
                'target_url' => $data['target_url'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'max_downloads' => $data['max_downloads'] ?? 5,
                'status' => $data['status'] ?? 'active',
            ]
        );

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByToken(string $token): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM downloads WHERE token = :token AND status = "active" LIMIT 1',
            ['token' => $token]
        );
    }

    public function incrementCounter(int $id): bool
    {
        $statement = $this->query(
            'UPDATE downloads
             SET download_count = download_count + 1, last_download_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND status = "active"
               AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
               AND download_count < max_downloads',
            ['id' => $id]
        );

        return $statement->rowCount() > 0;
    }

    public function markStatus(int $id, string $status): void
    {
        $this->execute(
            'UPDATE downloads
             SET status = :status,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            [
                'id' => $id,
                'status' => $status,
            ]
        );
    }
}
