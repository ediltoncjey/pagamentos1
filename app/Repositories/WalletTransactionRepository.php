<?php

declare(strict_types=1);

namespace App\Repositories;

final class WalletTransactionRepository extends BaseRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM wallet_transactions WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByReference(
        int $userId,
        string $referenceType,
        int $referenceId,
        string $type = 'credit'
    ): ?array {
        return $this->fetchOne(
            'SELECT * FROM wallet_transactions
             WHERE user_id = :user_id
               AND reference_type = :reference_type
               AND reference_id = :reference_id
               AND type = :type
             ORDER BY id DESC
             LIMIT 1',
            [
                'user_id' => $userId,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'type' => $type,
            ]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $this->execute(
            'INSERT INTO wallet_transactions (
                wallet_id, user_id, type, source, amount, currency, reference_type,
                reference_id, status, description, metadata, occurred_at, created_at, updated_at
             ) VALUES (
                :wallet_id, :user_id, :type, :source, :amount, :currency, :reference_type,
                :reference_id, :status, :description, :metadata, :occurred_at, UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )',
            [
                'wallet_id' => $data['wallet_id'],
                'user_id' => $data['user_id'],
                'type' => $data['type'],
                'source' => $data['source'],
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'MZN',
                'reference_type' => $data['reference_type'],
                'reference_id' => $data['reference_id'],
                'status' => $data['status'],
                'description' => $data['description'] ?? null,
                'metadata' => $data['metadata'] ?? '{}',
                'occurred_at' => $data['occurred_at'] ?? gmdate('Y-m-d H:i:s'),
            ]
        );

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPendingByReference(string $referenceType, int $referenceId): array
    {
        return $this->fetchAll(
            'SELECT * FROM wallet_transactions
             WHERE reference_type = :reference_type
               AND reference_id = :reference_id
               AND status = "pending"',
            ['reference_type' => $referenceType, 'reference_id' => $referenceId]
        );
    }

    public function markAvailable(int $id): void
    {
        $this->execute(
            'UPDATE wallet_transactions SET status = "available", updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $id]
        );
    }

    public function markFailed(int $id, ?string $error = null): void
    {
        $description = $error;
        if ($description === null || trim($description) === '') {
            $existing = $this->findById($id);
            $description = $existing['description'] ?? null;
        }

        $this->execute(
            'UPDATE wallet_transactions
             SET status = "failed",
                 description = :description,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            [
                'id' => $id,
                'description' => $description,
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        return $this->fetchAll(
            'SELECT *
             FROM wallet_transactions
             WHERE user_id = :user_id
             ORDER BY occurred_at DESC, id DESC
             LIMIT ' . max(1, (int) $limit) . ' OFFSET ' . max(0, (int) $offset),
            ['user_id' => $userId]
        );
    }

    public function countByUser(int $userId): int
    {
        $row = $this->fetchOne(
            'SELECT COUNT(*) AS total FROM wallet_transactions WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function sumPendingByUser(int $userId, string $currency = 'MZN'): float
    {
        $row = $this->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) AS total
             FROM wallet_transactions
             WHERE user_id = :user_id
               AND currency = :currency
               AND type = "credit"
               AND status = "pending"',
            [
                'user_id' => $userId,
                'currency' => $currency,
            ]
        );

        return (float) ($row['total'] ?? 0.00);
    }
}
