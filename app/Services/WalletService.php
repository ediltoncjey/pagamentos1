<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CommissionRepository;
use App\Repositories\WalletRepository;
use App\Repositories\WalletTransactionRepository;
use App\Utils\Money;
use RuntimeException;

final class WalletService
{
    public function __construct(
        private readonly WalletRepository $wallets,
        private readonly WalletTransactionRepository $transactions,
        private readonly CommissionRepository $commissions,
        private readonly LedgerService $ledger,
        private readonly Money $money,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getBalances(int $userId, string $currency = 'MZN'): array
    {
        $wallet = $this->wallets->findByUserAndCurrency($userId, $currency);
        if ($wallet === null) {
            $walletId = $this->wallets->createIfMissing($userId, $currency);
            $wallet = $this->wallets->findByUserAndCurrency($userId, $currency) ?? [
                'id' => $walletId,
                'balance_available' => 0.00,
                'balance_pending' => 0.00,
                'balance_total' => 0.00,
                'currency' => $currency,
            ];
        }

        return [
            'wallet_id' => (int) $wallet['id'],
            'currency' => (string) $wallet['currency'],
            'available' => (float) $wallet['balance_available'],
            'pending' => (float) $wallet['balance_pending'],
            'total' => (float) $wallet['balance_total'],
        ];
    }

    /**
     * @param array<string, mixed> $reference
     */
    public function creditPending(int $userId, float $amount, array $reference): void
    {
        $this->ledger->creditWalletPending($userId, $amount, $reference);
    }

    /**
     * @param array<string, mixed> $reference
     */
    public function settle(int $userId, array $reference): void
    {
        $this->ledger->settlePendingToAvailable($userId, $reference);
    }

    /**
     * @return array<string, mixed>
     */
    public function listTransactions(int $userId, int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $items = $this->transactions->listByUser($userId, $perPage, $offset);
        $total = $this->transactions->countByUser($userId);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resellerLedgerSnapshot(int $resellerId, int $transactionLimit = 10, int $commissionLimit = 10): array
    {
        $balances = $this->getBalances($resellerId, 'MZN');
        $recentTransactions = $this->transactions->listByUser($resellerId, max(1, $transactionLimit), 0);
        $recentCommissions = $this->commissions->listByReseller($resellerId, max(1, $commissionLimit), 0);
        $pendingTxAmount = $this->transactions->sumPendingByUser($resellerId, 'MZN');

        return [
            'balances' => $balances,
            'pending_credit_total' => $this->money->round($pendingTxAmount),
            'recent_transactions' => $recentTransactions,
            'recent_commissions' => $recentCommissions,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listResellerCommissions(int $resellerId, int $limit = 50, int $offset = 0): array
    {
        if ($limit <= 0) {
            throw new RuntimeException('Limit must be greater than zero.');
        }

        return $this->commissions->listByReseller($resellerId, $limit, $offset);
    }
}
