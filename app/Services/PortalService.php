<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\Database;
use DateTimeImmutable;
use DateTimeZone;

final class PortalService
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function adminPaymentsOverview(int $months = 6): array
    {
        $pdo = $this->database->pdo();
        $months = max(1, min(24, $months));
        $series = $this->monthSeries($months);
        $startAt = $series[0]['month_start_utc'];

        $summaryStmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS total_payments,
                COALESCE(SUM(CASE WHEN status = "confirmed" THEN 1 ELSE 0 END), 0) AS confirmed_count,
                COALESCE(SUM(CASE WHEN status IN ("failed", "timeout") THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(CASE WHEN status = "processing" THEN 1 ELSE 0 END), 0) AS processing_count,
                COALESCE(SUM(CASE WHEN status = "initiated" THEN 1 ELSE 0 END), 0) AS initiated_count,
                COALESCE(SUM(CASE WHEN status = "confirmed" THEN amount ELSE 0 END), 0) AS confirmed_amount
             FROM payments'
        );
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch() ?: [];

        $monthlyStmt = $pdo->prepare(
            'SELECT
                DATE_FORMAT(created_at, "%Y-%m") AS month_key,
                COUNT(*) AS total_payments,
                COALESCE(SUM(CASE WHEN status = "confirmed" THEN 1 ELSE 0 END), 0) AS confirmed_count,
                COALESCE(SUM(CASE WHEN status IN ("failed", "timeout") THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(CASE WHEN status = "confirmed" THEN amount ELSE 0 END), 0) AS confirmed_amount
             FROM payments
             WHERE created_at >= :start_at
             GROUP BY DATE_FORMAT(created_at, "%Y-%m")
             ORDER BY month_key ASC'
        );
        $monthlyStmt->execute(['start_at' => $startAt]);
        $monthlyRows = $monthlyStmt->fetchAll() ?: [];

        $monthlyIndexed = [];
        foreach ($monthlyRows as $row) {
            $monthlyIndexed[(string) $row['month_key']] = $row;
        }

        $monthly = [];
        foreach ($series as $month) {
            $key = $month['key'];
            $row = $monthlyIndexed[$key] ?? [];
            $monthly[] = [
                'month_key' => $key,
                'month_label' => $month['label'],
                'total_payments' => (int) ($row['total_payments'] ?? 0),
                'confirmed_count' => (int) ($row['confirmed_count'] ?? 0),
                'failed_count' => (int) ($row['failed_count'] ?? 0),
                'confirmed_amount' => round((float) ($row['confirmed_amount'] ?? 0), 2),
            ];
        }

        $recentStmt = $pdo->prepare(
            'SELECT
                p.id,
                p.order_id,
                p.provider,
                p.provider_payment_id,
                p.provider_reference,
                p.amount,
                p.currency,
                p.status,
                p.retry_count,
                p.last_error,
                p.created_at,
                p.updated_at,
                o.order_no,
                o.customer_phone
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             ORDER BY p.created_at DESC
             LIMIT 120'
        );
        $recentStmt->execute();
        $recent = $recentStmt->fetchAll() ?: [];

        return [
            'summary' => $summary,
            'monthly' => $monthly,
            'recent' => $recent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminTransactions(int $page = 1, int $perPage = 25, ?string $status = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;
        $status = $this->normalizeOrderStatus($status);

        $params = [];
        $statusSql = '';
        if ($status !== null) {
            $statusSql = ' WHERE o.status = :status';
            $params['status'] = $status;
        }

        $countStmt = $this->database->pdo()->prepare(
            'SELECT COUNT(*) AS total
             FROM orders o' . $statusSql
        );
        $countStmt->execute($params);
        $total = (int) (($countStmt->fetch()['total'] ?? 0));

        $listStmt = $this->database->pdo()->prepare(
            'SELECT
                o.id,
                o.order_no,
                o.customer_phone,
                o.amount,
                o.currency,
                o.status AS order_status,
                o.created_at,
                o.paid_at,
                py.status AS payment_status,
                py.provider_reference,
                py.retry_count,
                py.last_error,
                c.platform_fee,
                c.reseller_earning,
                c.settlement_status,
                u.name AS reseller_name,
                u.email AS reseller_email
             FROM orders o
             LEFT JOIN payments py ON py.order_id = o.id
             LEFT JOIN commissions c ON c.order_id = o.id
             LEFT JOIN users u ON u.id = o.reseller_id'
             . $statusSql .
             ' ORDER BY o.created_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $listStmt->execute($params);
        $items = $listStmt->fetchAll() ?: [];

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) max(1, ceil($total / $perPage)),
            ],
            'status_filter' => $status ?? 'all',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminDisputes(int $limit = 120): array
    {
        $limit = max(1, min(300, $limit));

        $summaryStmt = $this->database->pdo()->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN o.status IN ("failed", "cancelled", "expired") THEN 1 ELSE 0 END), 0) AS failed_orders,
                COALESCE(SUM(CASE WHEN py.status IN ("failed", "timeout") THEN 1 ELSE 0 END), 0) AS failed_payments
             FROM orders o
             LEFT JOIN payments py ON py.order_id = o.id'
        );
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch() ?: [];

        $listStmt = $this->database->pdo()->prepare(
            'SELECT
                o.id,
                o.order_no,
                o.customer_phone,
                o.amount,
                o.currency,
                o.status AS order_status,
                o.created_at,
                py.status AS payment_status,
                py.last_error,
                py.updated_at AS payment_updated_at,
                u.name AS reseller_name
             FROM orders o
             LEFT JOIN payments py ON py.order_id = o.id
             LEFT JOIN users u ON u.id = o.reseller_id
             WHERE o.status IN ("failed", "cancelled", "expired")
                OR py.status IN ("failed", "timeout")
             ORDER BY COALESCE(py.updated_at, o.updated_at, o.created_at) DESC
             LIMIT ' . $limit
        );
        $listStmt->execute();
        $items = $listStmt->fetchAll() ?: [];

        return [
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminWallets(int $txLimit = 80): array
    {
        $txLimit = max(1, min(300, $txLimit));

        $walletStmt = $this->database->pdo()->prepare(
            'SELECT
                w.id,
                w.user_id,
                w.currency,
                w.balance_available,
                w.balance_pending,
                w.balance_total,
                w.updated_at,
                u.name,
                u.email
             FROM wallets w
             INNER JOIN users u ON u.id = w.user_id
             ORDER BY w.balance_total DESC, w.updated_at DESC'
        );
        $walletStmt->execute();
        $wallets = $walletStmt->fetchAll() ?: [];

        $summaryStmt = $this->database->pdo()->prepare(
            'SELECT
                COUNT(*) AS wallets_count,
                COALESCE(SUM(balance_available), 0) AS total_available,
                COALESCE(SUM(balance_pending), 0) AS total_pending,
                COALESCE(SUM(balance_total), 0) AS total_balance
             FROM wallets'
        );
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch() ?: [];

        $txStmt = $this->database->pdo()->prepare(
            'SELECT
                wt.id,
                wt.user_id,
                wt.type,
                wt.source,
                wt.amount,
                wt.currency,
                wt.reference_type,
                wt.reference_id,
                wt.status,
                wt.description,
                wt.occurred_at,
                u.name
             FROM wallet_transactions wt
             INNER JOIN users u ON u.id = wt.user_id
             ORDER BY wt.occurred_at DESC, wt.id DESC
             LIMIT ' . $txLimit
        );
        $txStmt->execute();
        $transactions = $txStmt->fetchAll() ?: [];

        return [
            'summary' => $summary,
            'wallets' => $wallets,
            'transactions' => $transactions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminPayouts(int $limit = 120): array
    {
        $limit = max(1, min(400, $limit));

        $summaryStmt = $this->database->pdo()->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN settlement_status = "pending" THEN reseller_earning ELSE 0 END), 0) AS pending_amount,
                COALESCE(SUM(CASE WHEN settlement_status = "settled" THEN reseller_earning ELSE 0 END), 0) AS settled_amount,
                COALESCE(SUM(reseller_earning), 0) AS total_amount,
                COALESCE(SUM(CASE WHEN settlement_status = "pending" THEN 1 ELSE 0 END), 0) AS pending_count,
                COALESCE(SUM(CASE WHEN settlement_status = "settled" THEN 1 ELSE 0 END), 0) AS settled_count
             FROM commissions'
        );
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch() ?: [];

        $listStmt = $this->database->pdo()->prepare(
            'SELECT
                c.id,
                c.order_id,
                c.gross_amount,
                c.platform_fee,
                c.reseller_earning,
                c.currency,
                c.status,
                c.settlement_status,
                c.created_at,
                c.settled_at,
                o.order_no,
                u.name AS reseller_name,
                u.email AS reseller_email
             FROM commissions c
             INNER JOIN orders o ON o.id = c.order_id
             INNER JOIN users u ON u.id = c.reseller_id
             ORDER BY c.created_at DESC
             LIMIT ' . $limit
        );
        $listStmt->execute();
        $items = $listStmt->fetchAll() ?: [];

        return [
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resellerPaymentsOverview(int $resellerId, int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $series = $this->monthSeries($months);
        $startAt = $series[0]['month_start_utc'];

        $summaryStmt = $this->database->pdo()->prepare(
            'SELECT
                COUNT(*) AS total_payments,
                COALESCE(SUM(CASE WHEN py.status = "confirmed" THEN 1 ELSE 0 END), 0) AS confirmed_count,
                COALESCE(SUM(CASE WHEN py.status IN ("failed", "timeout") THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(CASE WHEN py.status = "processing" THEN 1 ELSE 0 END), 0) AS processing_count,
                COALESCE(SUM(CASE WHEN py.status = "confirmed" THEN py.amount ELSE 0 END), 0) AS confirmed_amount
             FROM payments py
             INNER JOIN orders o ON o.id = py.order_id
             WHERE o.reseller_id = :reseller_id'
        );
        $summaryStmt->execute(['reseller_id' => $resellerId]);
        $summary = $summaryStmt->fetch() ?: [];

        $monthlyStmt = $this->database->pdo()->prepare(
            'SELECT
                DATE_FORMAT(py.created_at, "%Y-%m") AS month_key,
                COUNT(*) AS total_payments,
                COALESCE(SUM(CASE WHEN py.status = "confirmed" THEN 1 ELSE 0 END), 0) AS confirmed_count,
                COALESCE(SUM(CASE WHEN py.status IN ("failed", "timeout") THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(CASE WHEN py.status = "confirmed" THEN py.amount ELSE 0 END), 0) AS confirmed_amount
             FROM payments py
             INNER JOIN orders o ON o.id = py.order_id
             WHERE o.reseller_id = :reseller_id
               AND py.created_at >= :start_at
             GROUP BY DATE_FORMAT(py.created_at, "%Y-%m")
             ORDER BY month_key ASC'
        );
        $monthlyStmt->execute([
            'reseller_id' => $resellerId,
            'start_at' => $startAt,
        ]);
        $rows = $monthlyStmt->fetchAll() ?: [];

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['month_key']] = $row;
        }

        $monthly = [];
        foreach ($series as $month) {
            $key = $month['key'];
            $row = $indexed[$key] ?? [];
            $monthly[] = [
                'month_key' => $key,
                'month_label' => $month['label'],
                'total_payments' => (int) ($row['total_payments'] ?? 0),
                'confirmed_count' => (int) ($row['confirmed_count'] ?? 0),
                'failed_count' => (int) ($row['failed_count'] ?? 0),
                'confirmed_amount' => round((float) ($row['confirmed_amount'] ?? 0), 2),
            ];
        }

        $recentStmt = $this->database->pdo()->prepare(
            'SELECT
                py.id,
                py.order_id,
                py.provider,
                py.provider_reference,
                py.provider_payment_id,
                py.amount,
                py.currency,
                py.status,
                py.retry_count,
                py.last_error,
                py.created_at,
                py.updated_at,
                o.order_no,
                o.customer_phone
             FROM payments py
             INNER JOIN orders o ON o.id = py.order_id
             WHERE o.reseller_id = :reseller_id
             ORDER BY py.created_at DESC
             LIMIT 120'
        );
        $recentStmt->execute(['reseller_id' => $resellerId]);
        $recent = $recentStmt->fetchAll() ?: [];

        return [
            'summary' => $summary,
            'monthly' => $monthly,
            'recent' => $recent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resellerTransactions(int $resellerId, int $page = 1, int $perPage = 25, ?string $status = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;
        $status = $this->normalizeOrderStatus($status);

        $params = ['reseller_id' => $resellerId];
        $statusSql = '';
        if ($status !== null) {
            $statusSql = ' AND o.status = :status';
            $params['status'] = $status;
        }

        $countStmt = $this->database->pdo()->prepare(
            'SELECT COUNT(*) AS total
             FROM orders o
             WHERE o.reseller_id = :reseller_id' . $statusSql
        );
        $countStmt->execute($params);
        $total = (int) (($countStmt->fetch()['total'] ?? 0));

        $listStmt = $this->database->pdo()->prepare(
            'SELECT
                o.id,
                o.order_no,
                o.customer_phone,
                o.amount,
                o.currency,
                o.status AS order_status,
                o.created_at,
                o.paid_at,
                py.status AS payment_status,
                py.provider_reference,
                py.retry_count,
                py.last_error,
                c.platform_fee,
                c.reseller_earning,
                c.settlement_status
             FROM orders o
             LEFT JOIN payments py ON py.order_id = o.id
             LEFT JOIN commissions c ON c.order_id = o.id
             WHERE o.reseller_id = :reseller_id'
             . $statusSql .
             ' ORDER BY o.created_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $listStmt->execute($params);
        $items = $listStmt->fetchAll() ?: [];

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) max(1, ceil($total / $perPage)),
            ],
            'status_filter' => $status ?? 'all',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resellerDisputes(int $resellerId, int $limit = 120): array
    {
        $limit = max(1, min(300, $limit));

        $summaryStmt = $this->database->pdo()->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN o.status IN ("failed", "cancelled", "expired") THEN 1 ELSE 0 END), 0) AS failed_orders,
                COALESCE(SUM(CASE WHEN py.status IN ("failed", "timeout") THEN 1 ELSE 0 END), 0) AS failed_payments
             FROM orders o
             LEFT JOIN payments py ON py.order_id = o.id
             WHERE o.reseller_id = :reseller_id'
        );
        $summaryStmt->execute(['reseller_id' => $resellerId]);
        $summary = $summaryStmt->fetch() ?: [];

        $listStmt = $this->database->pdo()->prepare(
            'SELECT
                o.id,
                o.order_no,
                o.customer_phone,
                o.amount,
                o.currency,
                o.status AS order_status,
                o.created_at,
                py.status AS payment_status,
                py.last_error,
                py.updated_at AS payment_updated_at
             FROM orders o
             LEFT JOIN payments py ON py.order_id = o.id
             WHERE o.reseller_id = :reseller_id
               AND (
                    o.status IN ("failed", "cancelled", "expired")
                    OR py.status IN ("failed", "timeout")
               )
             ORDER BY COALESCE(py.updated_at, o.updated_at, o.created_at) DESC
             LIMIT ' . $limit
        );
        $listStmt->execute(['reseller_id' => $resellerId]);
        $items = $listStmt->fetchAll() ?: [];

        return [
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resellerContacts(int $resellerId, int $limit = 120): array
    {
        $limit = max(1, min(500, $limit));

        $listStmt = $this->database->pdo()->prepare(
            'SELECT
                o.customer_phone,
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN o.status = "paid" THEN 1 ELSE 0 END), 0) AS paid_orders,
                COALESCE(SUM(CASE WHEN o.status = "paid" THEN o.amount ELSE 0 END), 0) AS paid_volume,
                MAX(o.created_at) AS last_order_at
             FROM orders o
             WHERE o.reseller_id = :reseller_id
             GROUP BY o.customer_phone
             ORDER BY last_order_at DESC
             LIMIT ' . $limit
        );
        $listStmt->execute(['reseller_id' => $resellerId]);
        $items = $listStmt->fetchAll() ?: [];

        $summaryStmt = $this->database->pdo()->prepare(
            'SELECT
                COUNT(DISTINCT customer_phone) AS unique_customers,
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END), 0) AS paid_amount
             FROM orders
             WHERE reseller_id = :reseller_id'
        );
        $summaryStmt->execute(['reseller_id' => $resellerId]);
        $summary = $summaryStmt->fetch() ?: [];

        return [
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resellerWallet(int $resellerId, int $transactionLimit = 120): array
    {
        $transactionLimit = max(1, min(300, $transactionLimit));
        $walletStmt = $this->database->pdo()->prepare(
            'SELECT
                id,
                user_id,
                currency,
                balance_available,
                balance_pending,
                balance_total,
                updated_at
             FROM wallets
             WHERE user_id = :reseller_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $walletStmt->execute(['reseller_id' => $resellerId]);
        $wallet = $walletStmt->fetch();
        if (!is_array($wallet)) {
            $wallet = [
                'id' => 0,
                'user_id' => $resellerId,
                'currency' => 'MZN',
                'balance_available' => 0.00,
                'balance_pending' => 0.00,
                'balance_total' => 0.00,
                'updated_at' => null,
            ];
        }

        $txStmt = $this->database->pdo()->prepare(
            'SELECT
                id,
                type,
                source,
                amount,
                currency,
                reference_type,
                reference_id,
                status,
                description,
                occurred_at
             FROM wallet_transactions
             WHERE user_id = :reseller_id
             ORDER BY occurred_at DESC, id DESC
             LIMIT ' . $transactionLimit
        );
        $txStmt->execute(['reseller_id' => $resellerId]);
        $transactions = $txStmt->fetchAll() ?: [];

        return [
            'wallet' => $wallet,
            'transactions' => $transactions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resellerPayouts(int $resellerId, int $limit = 120): array
    {
        $limit = max(1, min(400, $limit));

        $summaryStmt = $this->database->pdo()->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN settlement_status = "pending" THEN reseller_earning ELSE 0 END), 0) AS pending_amount,
                COALESCE(SUM(CASE WHEN settlement_status = "settled" THEN reseller_earning ELSE 0 END), 0) AS settled_amount,
                COALESCE(SUM(reseller_earning), 0) AS total_amount,
                COALESCE(SUM(CASE WHEN settlement_status = "pending" THEN 1 ELSE 0 END), 0) AS pending_count,
                COALESCE(SUM(CASE WHEN settlement_status = "settled" THEN 1 ELSE 0 END), 0) AS settled_count
             FROM commissions
             WHERE reseller_id = :reseller_id'
        );
        $summaryStmt->execute(['reseller_id' => $resellerId]);
        $summary = $summaryStmt->fetch() ?: [];

        $listStmt = $this->database->pdo()->prepare(
            'SELECT
                c.id,
                c.order_id,
                c.gross_amount,
                c.platform_fee,
                c.reseller_earning,
                c.currency,
                c.status,
                c.settlement_status,
                c.created_at,
                c.settled_at,
                o.order_no,
                o.customer_phone
             FROM commissions c
             INNER JOIN orders o ON o.id = c.order_id
             WHERE c.reseller_id = :reseller_id
             ORDER BY c.created_at DESC
             LIMIT ' . $limit
        );
        $listStmt->execute(['reseller_id' => $resellerId]);
        $items = $listStmt->fetchAll() ?: [];

        return [
            'summary' => $summary,
            'items' => $items,
        ];
    }

    private function normalizeOrderStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $normalized = strtolower(trim($status));
        if ($normalized === '' || $normalized === 'all') {
            return null;
        }

        $allowed = ['pending', 'paid', 'failed', 'cancelled', 'expired'];
        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    /**
     * @return list<array{key:string,label:string,month_start_utc:string}>
     */
    private function monthSeries(int $months): array
    {
        $months = max(1, min(24, $months));
        $anchor = new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('UTC'));
        $appTz = new DateTimeZone(date_default_timezone_get());

        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = $anchor->modify('-' . $i . ' month');
            $series[] = [
                'key' => $month->format('Y-m'),
                'label' => $month->setTimezone($appTz)->format('M/Y'),
                'month_start_utc' => $month->format('Y-m-d H:i:s'),
            ];
        }

        return $series;
    }
}

