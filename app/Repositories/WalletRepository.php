<?php

declare(strict_types=1);

namespace App\Repositories;

use RuntimeException;
use Throwable;

final class WalletRepository extends BaseRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByUserAndCurrency(int $userId, string $currency = 'MZN'): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM wallets WHERE user_id = :user_id AND currency = :currency LIMIT 1',
            ['user_id' => $userId, 'currency' => $currency]
        );
    }

    public function createIfMissing(int $userId, string $currency = 'MZN'): int
    {
        $wallet = $this->findByUserAndCurrency($userId, $currency);
        if ($wallet !== null) {
            return (int) $wallet['id'];
        }

        try {
            $this->execute(
                'INSERT INTO wallets (user_id, currency, balance_available, balance_pending, balance_total, version, created_at, updated_at)
                 VALUES (:user_id, :currency, 0.00, 0.00, 0.00, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                ['user_id' => $userId, 'currency' => $currency]
            );

            return (int) $this->pdo()->lastInsertId();
        } catch (Throwable) {
            $existing = $this->findByUserAndCurrency($userId, $currency);
            if ($existing !== null) {
                return (int) $existing['id'];
            }

            throw new RuntimeException('Unable to create wallet.');
        }
    }

    public function creditPending(int $walletId, float $amount): void
    {
        $this->execute(
            'UPDATE wallets
             SET balance_pending = balance_pending + :amount,
                 balance_total = balance_total + :amount,
                 version = version + 1,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $walletId, 'amount' => $amount]
        );
    }

    public function settlePendingToAvailable(int $walletId, float $amount): bool
    {
        $statement = $this->query(
            'UPDATE wallets
             SET balance_pending = balance_pending - :amount,
                 balance_available = balance_available + :amount,
                 version = version + 1,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND balance_pending >= :amount',
            ['id' => $walletId, 'amount' => $amount]
        );

        return $statement->rowCount() > 0;
    }

    public function debitAvailable(int $walletId, float $amount): bool
    {
        $statement = $this->query(
            'UPDATE wallets
             SET balance_available = balance_available - :amount,
                 balance_total = balance_total - :amount,
                 version = version + 1,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id
               AND balance_available >= :amount',
            ['id' => $walletId, 'amount' => $amount]
        );

        return $statement->rowCount() > 0;
    }
}
