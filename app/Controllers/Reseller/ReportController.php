<?php

declare(strict_types=1);

namespace App\Controllers\Reseller;

use App\Services\ReportService;
use App\Utils\Env;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\SessionManager;

final class ReportController
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly SessionManager $session,
    ) {
    }

    public function summary(Request $request): Response
    {
        $resellerId = $this->currentUserId();
        if ($resellerId <= 0) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $query = $request->query();
        $from = $this->dateOrNull($query['from'] ?? null);
        $to = $this->dateOrNull($query['to'] ?? null);
        $data = $this->reports->resellerSummary($resellerId, $from, $to);

        return Response::json([
            'report' => 'reseller_summary',
            'data' => $data,
        ]);
    }

    public function monthly(Request $request): Response
    {
        $resellerId = $this->currentUserId();
        if ($resellerId <= 0) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $query = $request->query();
        $months = $this->months($query['months'] ?? null);
        $rows = $this->reports->resellerMonthlyOverview($resellerId, $months);

        return Response::json([
            'report' => 'reseller_monthly_overview',
            'months' => $months,
            'rows' => $rows,
        ]);
    }

    public function history(Request $request): Response
    {
        $resellerId = $this->currentUserId();
        if ($resellerId <= 0) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $query = $request->query();
        $limit = $this->historyLimit($query['limit'] ?? null);
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $status = $this->status($query['status'] ?? null);

        $data = $this->reports->resellerOrderHistory($resellerId, $limit, $offset, $status);
        return Response::json([
            'report' => 'reseller_order_history',
            'status_filter' => $status ?? 'all',
            'data' => $data,
        ]);
    }

    public function export(Request $request): Response
    {
        $resellerId = $this->currentUserId();
        if ($resellerId <= 0) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $query = $request->query();
        $type = strtolower(trim((string) ($query['type'] ?? 'monthly')));

        if ($type === 'history') {
            $limit = $this->historyLimit($query['limit'] ?? Env::get('REPORT_DEFAULT_EXPORT_LIMIT', 500));
            $status = $this->status($query['status'] ?? null);
            $history = $this->reports->resellerOrderHistory($resellerId, $limit, 0, $status);
            $rows = $history['items'] ?? [];

            $csv = $this->csv([
                'order_no',
                'customer_phone',
                'amount',
                'currency',
                'order_status',
                'payment_status',
                'provider_reference',
                'platform_fee',
                'reseller_earning',
                'settlement_status',
                'created_at',
                'paid_at',
            ], array_map(static function (array $row): array {
                return [
                    $row['order_no'] ?? '',
                    $row['customer_phone'] ?? '',
                    $row['amount'] ?? 0,
                    $row['currency'] ?? '',
                    $row['status'] ?? '',
                    $row['payment_status'] ?? '',
                    $row['provider_reference'] ?? '',
                    $row['platform_fee'] ?? 0,
                    $row['reseller_earning'] ?? 0,
                    $row['settlement_status'] ?? '',
                    $row['created_at'] ?? '',
                    $row['paid_at'] ?? '',
                ];
            }, $rows));

            return $this->csvResponse($csv, 'reseller_history');
        }

        $months = $this->months($query['months'] ?? null);
        $rows = $this->reports->resellerMonthlyOverview($resellerId, $months);
        $csv = $this->csv([
            'month',
            'total_orders',
            'paid_orders',
            'conversion_rate',
            'total_gross',
            'reseller_earning',
            'pending_reseller_earning',
            'settled_reseller_earning',
        ], array_map(static function (array $row): array {
            return [
                $row['month_key'] ?? '',
                $row['total_orders'] ?? 0,
                $row['paid_orders'] ?? 0,
                $row['conversion_rate'] ?? 0,
                $row['total_gross'] ?? 0,
                $row['reseller_earning'] ?? 0,
                $row['pending_reseller_earning'] ?? 0,
                $row['settled_reseller_earning'] ?? 0,
            ];
        }, $rows));

        return $this->csvResponse($csv, 'reseller_monthly_overview');
    }

    private function currentUserId(): int
    {
        $user = $this->session->user();
        return (int) ($user['id'] ?? 0);
    }

    private function months(mixed $value): int
    {
        $default = (int) Env::get('REPORT_DEFAULT_MONTHS', 6);
        $max = (int) Env::get('REPORT_MAX_MONTHS', 24);
        $months = (int) ($value ?? $default);
        if ($months < 1) {
            $months = $default;
        }

        return min(max(1, $months), max(1, $max));
    }

    private function historyLimit(mixed $value): int
    {
        $default = (int) Env::get('REPORT_DEFAULT_HISTORY_LIMIT', 25);
        $max = (int) Env::get('REPORT_MAX_HISTORY_LIMIT', 200);
        $limit = (int) ($value ?? $default);
        if ($limit < 1) {
            $limit = $default;
        }

        return min(max(1, $limit), max(1, $max));
    }

    private function status(mixed $value): ?string
    {
        $raw = strtolower(trim((string) ($value ?? '')));
        if ($raw === '' || $raw === 'all') {
            return null;
        }

        $allowed = ['pending', 'paid', 'failed', 'cancelled', 'expired'];
        return in_array($raw, $allowed, true) ? $raw : null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : null;
    }

    /**
     * @param list<string> $headers
     * @param array<int, array<int, scalar|null>> $rows
     */
    private function csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return '';
        }

        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return "\xEF\xBB\xBF" . $csv;
    }

    private function csvResponse(string $csv, string $name): Response
    {
        $filename = $name . '_' . date('Ymd_His') . '.csv';
        return Response::text($csv, 200)->withHeaders([
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
