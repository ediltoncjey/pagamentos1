<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\ReportService;
use App\Utils\Env;
use App\Utils\Request;
use App\Utils\Response;

final class ReportController
{
    public function __construct(
        private readonly ReportService $reports,
    ) {
    }

    public function summary(Request $request): Response
    {
        $query = $request->query();
        $from = $this->dateOrNull($query['from'] ?? null);
        $to = $this->dateOrNull($query['to'] ?? null);

        $data = $this->reports->adminSummary($from, $to);
        return Response::json([
            'report' => 'admin_summary',
            'data' => $data,
        ]);
    }

    public function monthly(Request $request): Response
    {
        $query = $request->query();
        $months = $this->months($query['months'] ?? null);
        $rows = $this->reports->adminMonthlyOverview($months);

        return Response::json([
            'report' => 'admin_monthly_overview',
            'months' => $months,
            'rows' => $rows,
        ]);
    }

    public function topResellers(Request $request): Response
    {
        $query = $request->query();
        $limit = $this->topLimit($query['limit'] ?? null);
        $from = $this->dateOrNull($query['from'] ?? null);
        $to = $this->dateOrNull($query['to'] ?? null);

        $rows = $this->reports->adminTopResellers($limit, $from, $to);
        return Response::json([
            'report' => 'admin_top_resellers',
            'limit' => $limit,
            'rows' => $rows,
        ]);
    }

    public function monthlyDetail(Request $request): Response
    {
        $query = $request->query();
        $year = $this->year($query['year'] ?? null);
        $month = $this->month($query['month'] ?? null);

        $data = $this->reports->adminMonthlyDetailedReport($year, $month);
        return Response::json([
            'report' => 'admin_monthly_detail',
            'data' => $data,
        ]);
    }

    public function export(Request $request): Response
    {
        $query = $request->query();
        $type = strtolower(trim((string) ($query['type'] ?? 'monthly')));

        if ($type === 'top_resellers') {
            $limit = $this->topLimit($query['limit'] ?? null);
            $from = $this->dateOrNull($query['from'] ?? null);
            $to = $this->dateOrNull($query['to'] ?? null);
            $rows = $this->reports->adminTopResellers($limit, $from, $to);

            $csv = $this->csv([
                'reseller_id',
                'name',
                'email',
                'total_sales',
                'gross_amount',
                'platform_fee',
                'reseller_earning',
                'pending_earning',
                'settled_earning',
            ], array_map(static function (array $row): array {
                return [
                    $row['id'] ?? '',
                    $row['name'] ?? '',
                    $row['email'] ?? '',
                    $row['total_sales'] ?? 0,
                    $row['gross_amount'] ?? 0,
                    $row['platform_fee'] ?? 0,
                    $row['reseller_earning'] ?? 0,
                    $row['pending_earning'] ?? 0,
                    $row['settled_earning'] ?? 0,
                ];
            }, $rows));

            return $this->csvResponse($csv, 'admin_top_resellers');
        }

        if ($type === 'monthly_detail') {
            $year = $this->year($query['year'] ?? null);
            $month = $this->month($query['month'] ?? null);
            $detail = $this->reports->adminMonthlyDetailedReport($year, $month);
            $rows = $detail['daily'] ?? [];
            $csv = $this->csv([
                'day',
                'total_orders',
                'paid_orders',
                'conversion_rate',
                'gross_amount',
                'platform_fee',
                'reseller_earning',
            ], array_map(static function (array $row): array {
                return [
                    $row['day_key'] ?? '',
                    $row['total_orders'] ?? 0,
                    $row['paid_orders'] ?? 0,
                    $row['conversion_rate'] ?? 0,
                    $row['gross_amount'] ?? 0,
                    $row['platform_fee'] ?? 0,
                    $row['reseller_earning'] ?? 0,
                ];
            }, $rows));

            return $this->csvResponse($csv, 'admin_monthly_detail');
        }

        $months = $this->months($query['months'] ?? null);
        $rows = $this->reports->adminMonthlyOverview($months);
        $csv = $this->csv([
            'month',
            'total_orders',
            'paid_orders',
            'conversion_rate',
            'total_gross',
            'platform_fee',
            'reseller_earning',
            'pending_settlement',
        ], array_map(static function (array $row): array {
            return [
                $row['month_key'] ?? '',
                $row['total_orders'] ?? 0,
                $row['paid_orders'] ?? 0,
                $row['conversion_rate'] ?? 0,
                $row['total_gross'] ?? 0,
                $row['platform_fee'] ?? 0,
                $row['reseller_earning'] ?? 0,
                $row['pending_settlement'] ?? 0,
            ];
        }, $rows));

        return $this->csvResponse($csv, 'admin_monthly_overview');
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

    private function topLimit(mixed $value): int
    {
        $default = (int) Env::get('REPORT_DEFAULT_TOP_RESELLERS', 10);
        $max = (int) Env::get('REPORT_MAX_TOP_RESELLERS', 50);
        $limit = (int) ($value ?? $default);
        if ($limit < 1) {
            $limit = $default;
        }

        return min(max(1, $limit), max(1, $max));
    }

    private function year(mixed $value): int
    {
        $nowYear = (int) date('Y');
        $year = (int) ($value ?? $nowYear);
        if ($year < 2000 || $year > 2100) {
            return $nowYear;
        }

        return $year;
    }

    private function month(mixed $value): int
    {
        $nowMonth = (int) date('n');
        $month = (int) ($value ?? $nowMonth);
        if ($month < 1 || $month > 12) {
            return $nowMonth;
        }

        return $month;
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
