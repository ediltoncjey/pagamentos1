<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class ReportService
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function adminSummary(?string $fromDate = null, ?string $toDate = null): array
    {
        $pdo = $this->database->pdo();

        $orderParams = [];
        $orderRange = $this->buildDateRangeSql('created_at', $fromDate, $toDate, $orderParams, 'orders');
        $orderTotalsStmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END), 0) AS paid_orders,
                COALESCE(SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END), 0) AS total_gross,
                COALESCE(SUM(CASE WHEN status IN ("failed","cancelled","expired") THEN 1 ELSE 0 END), 0) AS failed_or_cancelled_orders
             FROM orders
             WHERE 1 = 1' . $orderRange
        );
        $orderTotalsStmt->execute($orderParams);
        $totals = $orderTotalsStmt->fetch() ?: [];

        $commissionParams = [];
        $commissionRange = $this->buildDateRangeSql(
            'created_at',
            $fromDate,
            $toDate,
            $commissionParams,
            'commissions'
        );
        $commissionTotalsStmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS total_commissions,
                COALESCE(SUM(gross_amount), 0) AS total_commission_gross,
                COALESCE(SUM(platform_fee), 0) AS total_platform_fee,
                COALESCE(SUM(reseller_earning), 0) AS total_reseller_earning,
                COALESCE(SUM(CASE WHEN settlement_status = "pending" THEN reseller_earning ELSE 0 END), 0) AS pending_reseller_earning,
                COALESCE(SUM(CASE WHEN settlement_status = "settled" THEN reseller_earning ELSE 0 END), 0) AS settled_reseller_earning
             FROM commissions
             WHERE 1 = 1' . $commissionRange
        );
        $commissionTotalsStmt->execute($commissionParams);
        $commissionTotals = $commissionTotalsStmt->fetch() ?: [];

        $topResellers = $this->adminTopResellers(5, $fromDate, $toDate);
        $totalOrders = (float) ($totals['total_orders'] ?? 0);
        $paidOrders = (float) ($totals['paid_orders'] ?? 0);
        $conversionRate = $totalOrders > 0 ? round(($paidOrders / $totalOrders) * 100, 2) : 0.0;

        return [
            'period' => $this->resolvedRangeMeta($fromDate, $toDate),
            'totals' => array_merge($totals, ['conversion_rate' => $conversionRate]),
            'commissions' => $commissionTotals,
            'top_resellers' => $topResellers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resellerSummary(int $resellerId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $pdo = $this->database->pdo();
        $orderParams = ['reseller_id' => $resellerId];
        $orderRange = $this->buildDateRangeSql('created_at', $fromDate, $toDate, $orderParams, 'orders');

        $totalsStmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END), 0) AS total_gross,
                COALESCE(SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END), 0) AS paid_orders,
                COALESCE(SUM(CASE WHEN status IN ("failed","cancelled","expired") THEN 1 ELSE 0 END), 0) AS failed_or_cancelled_orders
             FROM orders
             WHERE reseller_id = :reseller_id' . $orderRange
        );
        $totalsStmt->execute($orderParams);
        $totals = $totalsStmt->fetch() ?: [];

        $commissionParams = ['reseller_id' => $resellerId];
        $commissionRange = $this->buildDateRangeSql(
            'created_at',
            $fromDate,
            $toDate,
            $commissionParams,
            'commissions'
        );
        $commissionStmt = $pdo->prepare(
            'SELECT
                COALESCE(SUM(gross_amount), 0) AS commission_gross,
                COALESCE(SUM(platform_fee), 0) AS platform_fee_total,
                COALESCE(SUM(reseller_earning), 0) AS reseller_earning_total,
                COALESCE(SUM(CASE WHEN settlement_status = "pending" THEN reseller_earning ELSE 0 END), 0) AS reseller_pending_total,
                COALESCE(SUM(CASE WHEN settlement_status = "settled" THEN reseller_earning ELSE 0 END), 0) AS reseller_settled_total
             FROM commissions
             WHERE reseller_id = :reseller_id' . $commissionRange
        );
        $commissionStmt->execute($commissionParams);
        $commissionTotals = $commissionStmt->fetch() ?: [];

        $walletStmt = $pdo->prepare(
            'SELECT balance_available, balance_pending, balance_total, currency
             FROM wallets
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $walletStmt->execute(['user_id' => $resellerId]);
        $walletData = $walletStmt->fetch() ?: [
            'balance_available' => 0.00,
            'balance_pending' => 0.00,
            'balance_total' => 0.00,
            'currency' => 'MZN',
        ];

        $totalOrders = (float) ($totals['total_orders'] ?? 0);
        $paidOrders = (float) ($totals['paid_orders'] ?? 0);
        $conversionRate = $totalOrders > 0 ? round(($paidOrders / $totalOrders) * 100, 2) : 0.0;

        return [
            'period' => $this->resolvedRangeMeta($fromDate, $toDate),
            'totals' => array_merge($totals, ['conversion_rate' => $conversionRate]),
            'commissions' => $commissionTotals,
            'wallet' => $walletData,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function adminMonthlyOverview(int $months = 12): array
    {
        $months = $this->sanitizeMonths($months);
        $series = $this->monthSeries($months);
        $startAt = $series[0]['month_start_utc'];

        $stmt = $this->database->pdo()->prepare(
            'SELECT
                DATE_FORMAT(o.created_at, "%Y-%m") AS month_key,
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN o.status = "paid" THEN 1 ELSE 0 END), 0) AS paid_orders,
                COALESCE(SUM(CASE WHEN o.status = "paid" THEN o.amount ELSE 0 END), 0) AS total_gross,
                COALESCE(SUM(CASE WHEN c.id IS NOT NULL THEN c.platform_fee ELSE 0 END), 0) AS platform_fee,
                COALESCE(SUM(CASE WHEN c.id IS NOT NULL THEN c.reseller_earning ELSE 0 END), 0) AS reseller_earning,
                COALESCE(SUM(CASE WHEN c.settlement_status = "pending" THEN c.reseller_earning ELSE 0 END), 0) AS pending_settlement
             FROM orders o
             LEFT JOIN commissions c ON c.order_id = o.id
             WHERE o.created_at >= :start_at
             GROUP BY DATE_FORMAT(o.created_at, "%Y-%m")
             ORDER BY month_key ASC'
        );
        $stmt->execute(['start_at' => $startAt]);
        $rows = $stmt->fetchAll() ?: [];

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['month_key']] = $row;
        }

        $result = [];
        foreach ($series as $month) {
            $key = $month['key'];
            $row = $indexed[$key] ?? [];
            $totalOrders = (float) ($row['total_orders'] ?? 0);
            $paidOrders = (float) ($row['paid_orders'] ?? 0);

            $result[] = [
                'month_key' => $key,
                'month_label' => $month['label'],
                'total_orders' => (int) $totalOrders,
                'paid_orders' => (int) $paidOrders,
                'conversion_rate' => $totalOrders > 0 ? round(($paidOrders / $totalOrders) * 100, 2) : 0.0,
                'total_gross' => round((float) ($row['total_gross'] ?? 0), 2),
                'platform_fee' => round((float) ($row['platform_fee'] ?? 0), 2),
                'reseller_earning' => round((float) ($row['reseller_earning'] ?? 0), 2),
                'pending_settlement' => round((float) ($row['pending_settlement'] ?? 0), 2),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resellerMonthlyOverview(int $resellerId, int $months = 12): array
    {
        $months = $this->sanitizeMonths($months);
        $series = $this->monthSeries($months);
        $startAt = $series[0]['month_start_utc'];

        $stmt = $this->database->pdo()->prepare(
            'SELECT
                DATE_FORMAT(o.created_at, "%Y-%m") AS month_key,
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN o.status = "paid" THEN 1 ELSE 0 END), 0) AS paid_orders,
                COALESCE(SUM(CASE WHEN o.status = "paid" THEN o.amount ELSE 0 END), 0) AS total_gross,
                COALESCE(SUM(CASE WHEN c.id IS NOT NULL THEN c.reseller_earning ELSE 0 END), 0) AS reseller_earning,
                COALESCE(SUM(CASE WHEN c.settlement_status = "pending" THEN c.reseller_earning ELSE 0 END), 0) AS pending_reseller_earning,
                COALESCE(SUM(CASE WHEN c.settlement_status = "settled" THEN c.reseller_earning ELSE 0 END), 0) AS settled_reseller_earning
             FROM orders o
             LEFT JOIN commissions c ON c.order_id = o.id
             WHERE o.reseller_id = :reseller_id
               AND o.created_at >= :start_at
             GROUP BY DATE_FORMAT(o.created_at, "%Y-%m")
             ORDER BY month_key ASC'
        );
        $stmt->execute([
            'reseller_id' => $resellerId,
            'start_at' => $startAt,
        ]);
        $rows = $stmt->fetchAll() ?: [];

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['month_key']] = $row;
        }

        $result = [];
        foreach ($series as $month) {
            $key = $month['key'];
            $row = $indexed[$key] ?? [];
            $totalOrders = (float) ($row['total_orders'] ?? 0);
            $paidOrders = (float) ($row['paid_orders'] ?? 0);

            $result[] = [
                'month_key' => $key,
                'month_label' => $month['label'],
                'total_orders' => (int) $totalOrders,
                'paid_orders' => (int) $paidOrders,
                'conversion_rate' => $totalOrders > 0 ? round(($paidOrders / $totalOrders) * 100, 2) : 0.0,
                'total_gross' => round((float) ($row['total_gross'] ?? 0), 2),
                'reseller_earning' => round((float) ($row['reseller_earning'] ?? 0), 2),
                'pending_reseller_earning' => round((float) ($row['pending_reseller_earning'] ?? 0), 2),
                'settled_reseller_earning' => round((float) ($row['settled_reseller_earning'] ?? 0), 2),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function adminTopResellers(
        int $limit = 10,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        $limit = $this->sanitizeLimit($limit, 1, 100);
        $params = [];
        $range = $this->buildDateRangeSql('c.created_at', $fromDate, $toDate, $params, 'top');

        $sql = 'SELECT
                    u.id,
                    u.name,
                    u.email,
                    COUNT(c.id) AS total_sales,
                    COALESCE(SUM(c.gross_amount), 0) AS gross_amount,
                    COALESCE(SUM(c.platform_fee), 0) AS platform_fee,
                    COALESCE(SUM(c.reseller_earning), 0) AS reseller_earning,
                    COALESCE(SUM(CASE WHEN c.settlement_status = "pending" THEN c.reseller_earning ELSE 0 END), 0) AS pending_earning,
                    COALESCE(SUM(CASE WHEN c.settlement_status = "settled" THEN c.reseller_earning ELSE 0 END), 0) AS settled_earning
                FROM commissions c
                INNER JOIN users u ON u.id = c.reseller_id
                INNER JOIN roles r ON r.id = u.role_id AND r.name = "reseller"
                WHERE 1 = 1' . $range . '
                GROUP BY u.id, u.name, u.email
                ORDER BY reseller_earning DESC
                LIMIT ' . $limit;

        $stmt = $this->database->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    public function resellerOrderHistory(
        int $resellerId,
        int $limit = 50,
        int $offset = 0,
        ?string $status = null
    ): array {
        $limit = $this->sanitizeLimit($limit, 1, 200);
        $offset = max(0, $offset);
        $status = $this->normalizeOrderStatusFilter($status);

        $params = ['reseller_id' => $resellerId];
        $statusClause = '';
        if ($status !== null) {
            $statusClause = ' AND o.status = :status';
            $params['status'] = $status;
        }

        $countStmt = $this->database->pdo()->prepare(
            'SELECT COUNT(*) AS total
             FROM orders o
             WHERE o.reseller_id = :reseller_id' . $statusClause
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
                o.status,
                o.created_at,
                o.paid_at,
                py.status AS payment_status,
                py.provider_reference,
                c.platform_fee,
                c.reseller_earning,
                c.settlement_status
             FROM orders o
             LEFT JOIN payments py ON py.order_id = o.id
             LEFT JOIN commissions c ON c.order_id = o.id
             WHERE o.reseller_id = :reseller_id' . $statusClause . '
             ORDER BY o.created_at DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $listStmt->execute($params);
        $items = $listStmt->fetchAll() ?: [];

        return [
            'items' => $items,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($items),
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminMonthlyDetailedReport(int $year, int $month): array
    {
        if ($year < 2000 || $year > 2100 || !checkdate($month, 1, $year)) {
            throw new RuntimeException('Invalid year/month for monthly report.');
        }

        $from = new DateTimeImmutable(
            sprintf('%04d-%02d-01 00:00:00', $year, $month),
            new DateTimeZone('UTC')
        );
        $to = $from->modify('+1 month');

        $pdo = $this->database->pdo();
        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        $orderSummaryStmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END), 0) AS paid_orders,
                COALESCE(SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END), 0) AS gross_amount
             FROM orders
             WHERE created_at >= :from
               AND created_at < :to'
        );
        $orderSummaryStmt->execute($params);
        $orderSummary = $orderSummaryStmt->fetch() ?: [];

        $commissionSummaryStmt = $pdo->prepare(
            'SELECT
                COALESCE(SUM(platform_fee), 0) AS platform_fee,
                COALESCE(SUM(reseller_earning), 0) AS reseller_earning,
                COALESCE(SUM(CASE WHEN settlement_status = "pending" THEN reseller_earning ELSE 0 END), 0) AS pending_reseller_earning,
                COALESCE(SUM(CASE WHEN settlement_status = "settled" THEN reseller_earning ELSE 0 END), 0) AS settled_reseller_earning
             FROM commissions
             WHERE created_at >= :from
               AND created_at < :to'
        );
        $commissionSummaryStmt->execute($params);
        $commissionSummary = $commissionSummaryStmt->fetch() ?: [];

        $dailyStmt = $pdo->prepare(
            'SELECT
                DATE(o.created_at) AS day_key,
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN o.status = "paid" THEN 1 ELSE 0 END), 0) AS paid_orders,
                COALESCE(SUM(CASE WHEN o.status = "paid" THEN o.amount ELSE 0 END), 0) AS gross_amount,
                COALESCE(SUM(c.platform_fee), 0) AS platform_fee,
                COALESCE(SUM(c.reseller_earning), 0) AS reseller_earning
             FROM orders o
             LEFT JOIN commissions c ON c.order_id = o.id
             WHERE o.created_at >= :from
               AND o.created_at < :to
             GROUP BY DATE(o.created_at)
             ORDER BY day_key ASC'
        );
        $dailyStmt->execute($params);
        $dailyRows = $dailyStmt->fetchAll() ?: [];

        $dailyMap = [];
        foreach ($dailyRows as $row) {
            $dailyMap[(string) $row['day_key']] = $row;
        }

        $daily = [];
        $cursor = $from;
        while ($cursor < $to) {
            $key = $cursor->format('Y-m-d');
            $row = $dailyMap[$key] ?? [];
            $totalOrders = (float) ($row['total_orders'] ?? 0);
            $paidOrders = (float) ($row['paid_orders'] ?? 0);
            $daily[] = [
                'day_key' => $key,
                'total_orders' => (int) $totalOrders,
                'paid_orders' => (int) $paidOrders,
                'conversion_rate' => $totalOrders > 0 ? round(($paidOrders / $totalOrders) * 100, 2) : 0.0,
                'gross_amount' => round((float) ($row['gross_amount'] ?? 0), 2),
                'platform_fee' => round((float) ($row['platform_fee'] ?? 0), 2),
                'reseller_earning' => round((float) ($row['reseller_earning'] ?? 0), 2),
            ];
            $cursor = $cursor->modify('+1 day');
        }

        $top = $this->adminTopResellers(
            limit: 10,
            fromDate: $from->format('Y-m-d'),
            toDate: $to->modify('-1 day')->format('Y-m-d')
        );

        return [
            'period' => [
                'year' => $year,
                'month' => $month,
                'from_utc' => $from->format('Y-m-d H:i:s'),
                'to_utc_exclusive' => $to->format('Y-m-d H:i:s'),
            ],
            'orders' => $orderSummary,
            'commissions' => $commissionSummary,
            'daily' => $daily,
            'top_resellers' => $top,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildDateRangeSql(
        string $column,
        ?string $fromDate,
        ?string $toDate,
        array &$params,
        string $prefix
    ): string {
        $from = $this->normalizeDate($fromDate, false);
        $to = $this->normalizeDate($toDate, true);
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $sql = '';
        if ($from !== null) {
            $param = $prefix . '_from';
            $sql .= ' AND ' . $column . ' >= :' . $param;
            $params[$param] = $from;
        }

        if ($to !== null) {
            $param = $prefix . '_to';
            $sql .= ' AND ' . $column . ' <= :' . $param;
            $params[$param] = $to;
        }

        return $sql;
    }

    private function normalizeDate(?string $date, bool $endOfDay): ?string
    {
        if ($date === null) {
            return null;
        }

        $normalized = trim($date);
        if ($normalized === '') {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $normalized, new DateTimeZone('UTC'));
        if ($dt === false) {
            return null;
        }

        if ($endOfDay) {
            $dt = $dt->setTime(23, 59, 59);
        } else {
            $dt = $dt->setTime(0, 0, 0);
        }

        return $dt->format('Y-m-d H:i:s');
    }

    private function sanitizeMonths(int $months): int
    {
        if ($months < 1) {
            return 1;
        }

        return min(36, $months);
    }

    private function sanitizeLimit(int $limit, int $min, int $max): int
    {
        if ($limit < $min) {
            return $min;
        }

        return min($max, $limit);
    }

    /**
     * @return list<array{key:string,label:string,month_start_utc:string}>
     */
    private function monthSeries(int $months): array
    {
        $months = $this->sanitizeMonths($months);
        $anchor = new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('UTC'));
        $appTz = new DateTimeZone(date_default_timezone_get());

        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = $anchor->modify('-' . $i . ' month');
            $result[] = [
                'key' => $month->format('Y-m'),
                'label' => $month->setTimezone($appTz)->format('M/Y'),
                'month_start_utc' => $month->format('Y-m-d H:i:s'),
            ];
        }

        return $result;
    }

    private function normalizeOrderStatusFilter(?string $status): ?string
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
     * @return array<string, string|null>
     */
    private function resolvedRangeMeta(?string $fromDate, ?string $toDate): array
    {
        return [
            'from' => $this->normalizeDate($fromDate, false),
            'to' => $this->normalizeDate($toDate, true),
        ];
    }
}
