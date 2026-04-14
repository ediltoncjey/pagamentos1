<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\CommissionRepository;
use App\Repositories\OrderRepository;
use App\Repositories\WalletRepository;
use App\Repositories\WalletTransactionRepository;
use App\Utils\Database;
use App\Utils\Logger;
use App\Utils\Money;
use RuntimeException;
use Throwable;

final class LedgerService
{
    public function __construct(
        private readonly Database $database,
        private readonly OrderRepository $orders,
        private readonly CommissionRepository $commissions,
        private readonly WalletRepository $wallets,
        private readonly WalletTransactionRepository $walletTransactions,
        private readonly AuditLogRepository $auditLogs,
        private readonly Logger $logger,
        private readonly Money $money,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function recordCommission(int $orderId): array
    {
        $order = $this->orders->findById($orderId);
        if ($order === null) {
            throw new RuntimeException('Order not found.');
        }

        if ((string) $order['status'] !== 'paid') {
            throw new RuntimeException('Commission can only be recorded for paid orders.');
        }

        $existing = $this->commissions->findByOrderId($orderId);
        if ($existing !== null) {
            return $existing;
        }

        $split = $this->money->splitCommission((float) $order['amount'], 0.10);
        $currency = (string) $order['currency'];
        $resellerId = (int) $order['reseller_id'];

        try {
            return $this->database->transaction(function () use ($orderId, $resellerId, $split, $currency): array {
                $existingInsideTx = $this->commissions->findByOrderId($orderId);
                if ($existingInsideTx !== null) {
                    return $existingInsideTx;
                }

                $commissionId = $this->commissions->create([
                    'order_id' => $orderId,
                    'reseller_id' => $resellerId,
                    'gross_amount' => $split['gross_amount'],
                    'platform_fee' => $split['platform_fee'],
                    'reseller_earning' => $split['reseller_earning'],
                    'currency' => $currency,
                    'status' => 'pending',
                    'settlement_status' => 'pending',
                ]);

                $this->creditWalletPending(
                    userId: $resellerId,
                    amount: $split['reseller_earning'],
                    reference: [
                        'reference_type' => 'commission',
                        'reference_id' => $commissionId,
                        'currency' => $currency,
                        'description' => 'Pending commission credit for order #' . $orderId,
                    ]
                );

                $commission = $this->commissions->findByOrderId($orderId);
                if ($commission === null) {
                    throw new RuntimeException('Failed to persist commission.');
                }

                return $commission;
            });
        } catch (Throwable $exception) {
            $raceSafe = $this->commissions->findByOrderId($orderId);
            if ($raceSafe !== null) {
                return $raceSafe;
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $reference
     */
    public function creditWalletPending(int $userId, float $amount, array $reference): void
    {
        $currency = (string) ($reference['currency'] ?? 'MZN');
        $referenceType = (string) ($reference['reference_type'] ?? 'manual');
        $referenceId = (int) ($reference['reference_id'] ?? 0);
        if ($referenceId <= 0) {
            throw new RuntimeException('Reference ID is required for wallet credit.');
        }

        $existingTransaction = $this->walletTransactions->findByReference(
            userId: $userId,
            referenceType: $referenceType,
            referenceId: $referenceId,
            type: 'credit'
        );
        $allowRetryFailed = (bool) ($reference['allow_retry_failed'] ?? false);
        if (
            $existingTransaction !== null
            && in_array((string) ($existingTransaction['status'] ?? ''), ['pending', 'available'], true)
        ) {
            return;
        }

        if (
            $existingTransaction !== null
            && (string) ($existingTransaction['status'] ?? '') === 'failed'
            && !$allowRetryFailed
        ) {
            throw new RuntimeException('Reference already has a failed wallet credit transaction.');
        }

        $walletId = $this->wallets->createIfMissing($userId, $currency);
        $this->wallets->creditPending($walletId, $amount);

        $this->walletTransactions->create([
            'wallet_id' => $walletId,
            'user_id' => $userId,
            'type' => 'credit',
            'source' => 'sale',
            'amount' => $amount,
            'currency' => $currency,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'status' => 'pending',
            'description' => $reference['description'] ?? 'Pending credit from sale.',
            'metadata' => json_encode($reference, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $reference
     * @return array<string, mixed>
     */
    public function settlePendingToAvailable(int $userId, array $reference): array
    {
        $currency = (string) ($reference['currency'] ?? 'MZN');
        $wallet = $this->wallets->findByUserAndCurrency($userId, $currency);
        if ($wallet === null) {
            throw new RuntimeException('Wallet not found.');
        }

        $referenceType = (string) ($reference['reference_type'] ?? 'manual');
        $referenceId = (int) ($reference['reference_id'] ?? 0);
        $transactions = $this->walletTransactions->listPendingByReference($referenceType, $referenceId);
        if ($transactions === []) {
            return [
                'settled_count' => 0,
                'settled_amount' => 0.00,
                'currency' => $currency,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ];
        }

        $settledAmount = 0.00;
        $settledCount = 0;
        $this->database->transaction(function () use ($wallet, $transactions, &$settledAmount, &$settledCount): void {
            foreach ($transactions as $transaction) {
                $amount = (float) $transaction['amount'];
                $moved = $this->wallets->settlePendingToAvailable((int) $wallet['id'], $amount);
                if (!$moved) {
                    throw new RuntimeException('Wallet pending balance is insufficient for settlement.');
                }

                $this->walletTransactions->markAvailable((int) $transaction['id']);
                $settledAmount = $this->money->add($settledAmount, $amount);
                $settledCount++;
            }
        });

        return [
            'settled_count' => $settledCount,
            'settled_amount' => $this->money->round($settledAmount),
            'currency' => $currency,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function settleCommission(int $commissionId, array $context = []): array
    {
        $commission = $this->commissions->findById($commissionId);
        if ($commission === null) {
            throw new RuntimeException('Commission not found.');
        }

        if ((string) $commission['settlement_status'] === 'settled') {
            return $commission;
        }

        $resellerId = (int) $commission['reseller_id'];
        $currency = (string) $commission['currency'];

        try {
            $settlement = $this->settlePendingToAvailable($resellerId, [
                'currency' => $currency,
                'reference_type' => 'commission',
                'reference_id' => $commissionId,
            ]);

            if ((int) $settlement['settled_count'] > 0) {
                $this->commissions->markSettled($commissionId);
            } else {
                $tx = $this->walletTransactions->findByReference($resellerId, 'commission', $commissionId, 'credit');
                if ($tx !== null && (string) ($tx['status'] ?? '') === 'available') {
                    $this->commissions->markSettled($commissionId);
                }
            }

            $updated = $this->commissions->findById($commissionId);
            if ($updated === null) {
                throw new RuntimeException('Failed to reload commission after settlement.');
            }

            $this->writeLedgerAudit(
                action: 'ledger.commission_settle',
                entityType: 'commission',
                entityId: $commissionId,
                oldValues: [
                    'status' => $commission['status'],
                    'settlement_status' => $commission['settlement_status'],
                    'settled_at' => $commission['settled_at'],
                ],
                newValues: [
                    'status' => $updated['status'],
                    'settlement_status' => $updated['settlement_status'],
                    'settled_at' => $updated['settled_at'],
                    'settled_count' => $settlement['settled_count'],
                    'settled_amount' => $settlement['settled_amount'],
                ],
                context: $context
            );

            return $updated;
        } catch (Throwable $exception) {
            $this->commissions->markSettlementFailed($commissionId);
            $this->logger->error('Commission settlement failed', [
                'commission_id' => $commissionId,
                'error' => $exception->getMessage(),
            ]);

            $this->writeLedgerAudit(
                action: 'ledger.commission_settle_failed',
                entityType: 'commission',
                entityId: $commissionId,
                oldValues: [
                    'status' => $commission['status'],
                    'settlement_status' => $commission['settlement_status'],
                ],
                newValues: [
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ],
                context: $context
            );

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function reconcilePendingCommissions(int $limit = 100, ?int $resellerId = null, array $context = []): array
    {
        $pending = $this->commissions->listPendingSettlements($limit, $resellerId);
        $settled = [];
        $failed = [];

        foreach ($pending as $commission) {
            $commissionId = (int) $commission['id'];
            try {
                $updated = $this->settleCommission($commissionId, $context);
                $settled[] = [
                    'commission_id' => $commissionId,
                    'reseller_id' => (int) $updated['reseller_id'],
                    'settlement_status' => (string) $updated['settlement_status'],
                    'status' => (string) $updated['status'],
                ];
            } catch (Throwable $exception) {
                $failed[] = [
                    'commission_id' => $commissionId,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return [
            'requested_limit' => $limit,
            'reseller_id' => $resellerId,
            'processed' => count($pending),
            'settled_count' => count($settled),
            'failed_count' => count($failed),
            'settled' => $settled,
            'failed' => $failed,
        ];
    }

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     * @param array<string, mixed> $context
     */
    private function writeLedgerAudit(
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValues,
        ?array $newValues,
        array $context
    ): void {
        try {
            $this->auditLogs->create([
                'actor_user_id' => $context['actor_user_id'] ?? null,
                'actor_role' => $context['actor_role'] ?? 'system',
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => $oldValues !== null
                    ? json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'new_values' => $newValues !== null
                    ? json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'ip_address' => $context['ip_address'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
                'request_id' => $context['request_id'] ?? null,
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('Failed to persist ledger audit log', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
