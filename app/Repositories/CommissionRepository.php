<?php

declare(strict_types=1);

namespace App\Repositories;

final class CommissionRepository extends BaseRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM commissions WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByOrderId(int $orderId): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM commissions WHERE order_id = :order_id LIMIT 1',
            ['order_id' => $orderId]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $this->execute(
            'INSERT INTO commissions (
                order_id, reseller_id, gross_amount, platform_fee, reseller_earning, currency,
                status, settlement_status, created_at, updated_at
             ) VALUES (
                :order_id, :reseller_id, :gross_amount, :platform_fee, :reseller_earning, :currency,
                :status, :settlement_status, UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )',
            [
                'order_id' => $data['order_id'],
                'reseller_id' => $data['reseller_id'],
                'gross_amount' => $data['gross_amount'],
                'platform_fee' => $data['platform_fee'],
                'reseller_earning' => $data['reseller_earning'],
                'currency' => $data['currency'] ?? 'MZN',
                'status' => $data['status'] ?? 'pending',
                'settlement_status' => $data['settlement_status'] ?? 'pending',
            ]
        );

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByReseller(int $resellerId, int $limit = 100, int $offset = 0): array
    {
        return $this->fetchAll(
            'SELECT c.*, o.order_no, o.customer_phone, o.paid_at
             FROM commissions c
             INNER JOIN orders o ON o.id = c.order_id
             WHERE c.reseller_id = :reseller_id
             ORDER BY c.id DESC
             LIMIT ' . max(1, (int) $limit) . ' OFFSET ' . max(0, (int) $offset),
            ['reseller_id' => $resellerId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPendingSettlements(int $limit = 100, ?int $resellerId = null): array
    {
        $sql = 'SELECT c.*, o.order_no, o.paid_at, u.name AS reseller_name, u.email AS reseller_email
                FROM commissions c
                INNER JOIN orders o ON o.id = c.order_id
                INNER JOIN users u ON u.id = c.reseller_id
                WHERE c.settlement_status = "pending"';

        $params = [];
        if ($resellerId !== null && $resellerId > 0) {
            $sql .= ' AND c.reseller_id = :reseller_id';
            $params['reseller_id'] = $resellerId;
        }

        $sql .= ' ORDER BY c.created_at ASC LIMIT ' . max(1, (int) $limit);
        return $this->fetchAll($sql, $params);
    }

    public function markSettled(int $commissionId): void
    {
        $this->execute(
            'UPDATE commissions
             SET settlement_status = "settled",
                 status = "paid",
                 settled_at = UTC_TIMESTAMP(),
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $commissionId]
        );
    }

    public function markSettlementFailed(int $commissionId): void
    {
        $this->execute(
            'UPDATE commissions
             SET status = "failed",
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $commissionId]
        );
    }
}
