<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Repositories\CommissionRepository;
use App\Services\ReportService;
use App\Utils\DashboardShell;
use App\Utils\Env;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Sanitizer;

final class DashboardController
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly CommissionRepository $commissions,
        private readonly DashboardShell $shell,
        private readonly Sanitizer $sanitizer,
    ) {
    }

    public function index(Request $request): Response
    {
        $months = $this->dashboardMonths();
        $summary = $this->reports->adminSummary();
        $monthly = $this->reports->adminMonthlyOverview($months);
        $topResellers = $this->reports->adminTopResellers(8);
        $pending = $this->commissions->listPendingSettlements(12, null);

        $payload = [
            'dashboard' => 'admin',
            'summary' => $summary,
            'monthly' => $monthly,
            'top_resellers' => $topResellers,
            'pending_settlements_preview' => $pending,
        ];

        if ($this->wantsJson($request)) {
            return Response::json($payload);
        }

        return new Response(200, $this->renderDashboard($payload));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderDashboard(array $payload): string
    {
        $summary = $payload['summary'] ?? [];
        $totals = $summary['totals'] ?? [];
        $commissions = $summary['commissions'] ?? [];
        $topResellers = $payload['top_resellers'] ?? [];
        $pending = $payload['pending_settlements_preview'] ?? [];
        $monthly = $payload['monthly'] ?? [];

        $months = count($monthly);
        $totalOrders = (int) ($totals['total_orders'] ?? 0);
        $paidOrders = (int) ($totals['paid_orders'] ?? 0);
        $failedOrders = (int) ($totals['failed_or_cancelled_orders'] ?? 0);
        $conversionRate = (float) ($totals['conversion_rate'] ?? 0.0);
        $gross = (float) ($totals['total_gross'] ?? 0.0);
        $platformFee = (float) ($commissions['total_platform_fee'] ?? 0.0);
        $pendingEarning = (float) ($commissions['pending_reseller_earning'] ?? 0.0);
        $settledEarning = (float) ($commissions['settled_reseller_earning'] ?? 0.0);
        $settlementRatio = $settledEarning + $pendingEarning > 0
            ? ($settledEarning / ($settledEarning + $pendingEarning)) * 100
            : 0.0;

        $topRows = '';
        $topList = '';
        foreach ($topResellers as $index => $row) {
            $name = $this->safe((string) ($row['name'] ?? ''));
            $email = $this->safe((string) ($row['email'] ?? ''));
            $grossAmount = (float) ($row['gross_amount'] ?? 0);
            $earning = (float) ($row['reseller_earning'] ?? 0);
            $pendingAmount = (float) ($row['pending_earning'] ?? 0);
            $ratio = $grossAmount > 0 ? min(100.0, max(0.0, ($earning / $grossAmount) * 100)) : 0.0;

            $topRows .= '<tr>'
                . '<td>#' . ($index + 1) . '</td>'
                . '<td>' . $name . '</td>'
                . '<td>' . $email . '</td>'
                . '<td>' . $this->money($grossAmount) . '</td>'
                . '<td>' . $this->money($earning) . '</td>'
                . '<td>' . $this->money($pendingAmount) . '</td>'
                . '</tr>';

            $topList .= '<li>'
                . '<div class="list-item__main">'
                . '<p class="list-item__title">#' . ($index + 1) . ' ' . $name . '</p>'
                . '<p class="list-item__meta">' . $email . '</p>'
                . '</div>'
                . '<div style="min-width:124px; text-align:right;">'
                . '<strong>' . $this->money($earning) . '</strong>'
                . '<div class="form-hint" style="margin-top:4px;">Comissao efetiva ' . $this->percent($ratio) . '</div>'
                . '<div class="progress-track" style="margin-top:6px;"><div class="progress-fill" style="width:' . $this->percent($ratio) . ';"></div></div>'
                . '</div>'
                . '</li>';
        }

        if ($topRows === '') {
            $topRows = '<tr><td colspan="6"><div class="empty-state">Nenhum comissionista com vendas no periodo.</div></td></tr>';
            $topList = '<div class="empty-state">Nenhum ranking disponivel.</div>';
        } else {
            $topList = '<ul class="list">' . $topList . '</ul>';
        }

        $pendingRows = '';
        foreach ($pending as $row) {
            $settlementStatus = (string) ($row['settlement_status'] ?? 'pending');
            $badgeClass = $settlementStatus === 'settled' ? 'badge--success' : 'badge--warning';

            $pendingRows .= '<tr>'
                . '<td>#' . (int) ($row['id'] ?? 0) . '</td>'
                . '<td>' . $this->safe((string) ($row['reseller_name'] ?? '')) . '</td>'
                . '<td>' . $this->safe((string) ($row['order_no'] ?? '')) . '</td>'
                . '<td>' . $this->money((float) ($row['gross_amount'] ?? 0)) . '</td>'
                . '<td>' . $this->money((float) ($row['reseller_earning'] ?? 0)) . '</td>'
                . '<td><span class="badge ' . $badgeClass . '">' . $this->safe($settlementStatus) . '</span></td>'
                . '</tr>';
        }

        if ($pendingRows === '') {
            $pendingRows = '<tr><td colspan="6"><div class="empty-state">Sem pendencias de settlement.</div></td></tr>';
        }

        $content = <<<HTML
<section class="dashboard-hero">
  <div class="dashboard-hero__row">
    <div>
      <h2 class="dashboard-hero__title">Operacao financeira centralizada e pronta para reconciliacao</h2>
      <p class="dashboard-hero__subtitle">
        Este painel consolida pedidos, comissoes e estado de settlement para fechar o ciclo financeiro da plataforma.
      </p>
      <div class="hero-pills">
        <span class="pill"><i class="bi bi-calendar3"></i> Janela analitica: {$months} meses</span>
        <span class="pill"><i class="bi bi-lightning-charge"></i> Conversao global: {$this->percent($conversionRate)}</span>
        <span class="pill"><i class="bi bi-shield-check"></i> Pedidos pagos: {$paidOrders}</span>
      </div>
    </div>

    <div class="hero-insight">
      <article class="hero-insight__item">
        <div class="hero-insight__label">Volume bruto consolidado</div>
        <div class="hero-insight__value">{$this->money($gross)}</div>
        <div class="hero-insight__meta">Comissao de plataforma acumulada: {$this->money($platformFee)}</div>
      </article>
      <article class="hero-insight__item">
        <div class="hero-insight__label">Taxa de settlement</div>
        <div class="hero-insight__value">{$this->percent($settlementRatio)}</div>
        <div class="hero-insight__meta">Liquidado {$this->money($settledEarning)} de {$this->money($settledEarning + $pendingEarning)}</div>
      </article>
    </div>
  </div>
</section>

<div class="kpi-grid">
  <article class="kpi-card">
    <div class="kpi-card__head">
      <div class="kpi-card__label">Pedidos totais</div>
      <span class="kpi-card__icon"><i class="bi bi-receipt-cutoff"></i></span>
    </div>
    <div class="kpi-card__value">{$totalOrders}</div>
    <div class="kpi-card__meta">Pagos {$paidOrders} | Falhados/Cancelados {$failedOrders}</div>
  </article>

  <article class="kpi-card">
    <div class="kpi-card__head">
      <div class="kpi-card__label">Faturacao bruta</div>
      <span class="kpi-card__icon"><i class="bi bi-cash-stack"></i></span>
    </div>
    <div class="kpi-card__value">{$this->money($gross)}</div>
    <div class="kpi-card__meta">Receita da plataforma {$this->money($platformFee)}</div>
  </article>

  <article class="kpi-card">
    <div class="kpi-card__head">
      <div class="kpi-card__label">Comissoes pendentes</div>
      <span class="kpi-card__icon"><i class="bi bi-hourglass-split"></i></span>
    </div>
    <div class="kpi-card__value">{$this->money($pendingEarning)}</div>
    <div class="kpi-card__meta">Aguardam reconciliacao mensal</div>
  </article>

  <article class="kpi-card">
    <div class="kpi-card__head">
      <div class="kpi-card__label">Comissoes liquidadas</div>
      <span class="kpi-card__icon"><i class="bi bi-check2-circle"></i></span>
    </div>
    <div class="kpi-card__value">{$this->money($settledEarning)}</div>
    <div class="kpi-card__meta">Disponiveis para payout</div>
  </article>
</div>

<div class="content-grid">
  <div class="content-main">
    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Tendencia mensal de vendas e receita</h3>
          <p class="panel__subtitle">Comparativo entre faturacao bruta e comissao da plataforma.</p>
        </div>
      </div>

      <div class="legend-inline">
        <span><i style="background:#3f88ff;"></i> Faturacao bruta</span>
        <span><i style="background:#34b9a6;"></i> Receita plataforma</span>
      </div>

      <div class="chart-box"><canvas id="admin-monthly-chart" width="980" height="290"></canvas></div>
    </section>

    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Comissoes pendentes de settlement</h3>
          <p class="panel__subtitle">Itens que aguardam migracao de pending para available no ledger interno.</p>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Comissionista</th>
              <th>Pedido</th>
              <th>Bruto</th>
              <th>Ganho</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>{$pendingRows}</tbody>
        </table>
      </div>
    </section>
  </div>

  <aside class="content-side">
    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Top comissionistas</h3>
          <p class="panel__subtitle">Ranking por ganho total acumulado.</p>
        </div>
      </div>
      <div class="panel__body">{$topList}</div>
    </section>

    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Resumo operacional</h3>
          <p class="panel__subtitle">Indicadores rapidos para decisao.</p>
        </div>
      </div>

      <ul class="stats-list">
        <li>
          <span class="stats-list__label">Conversao global</span>
          <span class="stats-list__value">{$this->percent($conversionRate)}</span>
        </li>
        <li>
          <span class="stats-list__label">Pedidos sem sucesso</span>
          <span class="stats-list__value">{$failedOrders}</span>
        </li>
        <li>
          <span class="stats-list__label">Settlement concluido</span>
          <span class="stats-list__value">{$this->percent($settlementRatio)}</span>
        </li>
        <li>
          <span class="stats-list__label">Janela analisada</span>
          <span class="stats-list__value">{$months} meses</span>
        </li>
      </ul>
    </section>

    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Top em tabela</h3>
          <p class="panel__subtitle">Visao compacta para exportacao rapida.</p>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>Nome</th>
              <th>Email</th>
              <th>Bruto</th>
              <th>Ganho</th>
              <th>Pendente</th>
            </tr>
          </thead>
          <tbody>{$topRows}</tbody>
        </table>
      </div>
    </section>
  </aside>
</div>
HTML;

        $toolbar = implode('', [
            '<a class="btn btn-outline" href="/api/admin/reports/summary" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text"></i> Resumo JSON</a>',
            '<a class="btn btn-outline" href="/api/admin/reports/export?type=monthly" target="_blank" rel="noopener"><i class="bi bi-download"></i> Exportar CSV</a>',
            '<a class="btn btn-primary" href="/admin/users"><i class="bi bi-people"></i> Gestao de utilizadores</a>',
        ]);

        $scripts = $this->chartScript($monthly, 'admin-monthly-chart', 'total_gross', 'platform_fee');

        return $this->shell->render([
            'role' => 'admin',
            'active' => 'dashboard',
            'title' => 'Dashboard Administrativo',
            'subtitle' => 'Painel estrategico para vendas, comissoes e reconciliacao financeira.',
            'breadcrumbs' => [
                ['label' => 'Admin'],
                ['label' => 'Dashboard'],
            ],
            'toolbar' => $toolbar,
            'content' => $content,
            'extraScripts' => $scripts,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function chartScript(array $rows, string $canvasId, string $seriesA, string $seriesB): string
    {
        $json = $this->safeJson($rows);
        $canvasId = $this->safe($canvasId);
        $seriesA = $this->safe($seriesA);
        $seriesB = $this->safe($seriesB);

        return <<<HTML
<script>
(function () {
  const rows = {$json};
  const canvas = document.getElementById('{$canvasId}');
  if (!canvas || !Array.isArray(rows) || rows.length === 0) return;

  const ctx = canvas.getContext('2d');
  if (!ctx) return;

  const width = canvas.width;
  const height = canvas.height;
  const pad = { left: 46, right: 14, top: 16, bottom: 40 };
  const chartW = width - pad.left - pad.right;
  const chartH = height - pad.top - pad.bottom;

  const maxY = Math.max(1, ...rows.map(function (row) {
    return Math.max(Number(row['{$seriesA}'] || 0), Number(row['{$seriesB}'] || 0));
  }));

  ctx.clearRect(0, 0, width, height);
  ctx.strokeStyle = 'rgba(127,149,188,.26)';
  ctx.lineWidth = 1;
  for (let i = 0; i <= 4; i++) {
    const y = pad.top + ((chartH / 4) * i);
    ctx.beginPath();
    ctx.moveTo(pad.left, y);
    ctx.lineTo(width - pad.right, y);
    ctx.stroke();
  }

  const groupW = chartW / rows.length;
  const barW = Math.max(7, Math.min(26, groupW * 0.27));

  rows.forEach(function (row, index) {
    const x = pad.left + (index * groupW) + (groupW * 0.5);
    const valueA = Number(row['{$seriesA}'] || 0);
    const valueB = Number(row['{$seriesB}'] || 0);
    const hA = (valueA / maxY) * chartH;
    const hB = (valueB / maxY) * chartH;

    ctx.fillStyle = '#3f88ff';
    ctx.fillRect(x - barW - 2, pad.top + chartH - hA, barW, hA);

    ctx.fillStyle = '#34b9a6';
    ctx.fillRect(x + 2, pad.top + chartH - hB, barW, hB);

    ctx.fillStyle = '#889cc2';
    ctx.font = '11px Plus Jakarta Sans, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(String(row.month_label || row.month_key || ''), x, height - 12);
  });
})();
</script>
HTML;
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        return str_contains($accept, 'application/json') || str_starts_with($request->path(), '/api/');
    }

    private function dashboardMonths(): int
    {
        $default = (int) Env::get('REPORT_DEFAULT_MONTHS', 6);
        $max = (int) Env::get('REPORT_MAX_MONTHS', 24);
        $months = min(max(1, $default), max(1, $max));
        return min(12, $months);
    }

    private function money(float $amount): string
    {
        return 'MZN ' . number_format($amount, 2, '.', ',');
    }

    private function percent(float $value): string
    {
        return number_format($value, 2, '.', ',') . '%';
    }

    private function safe(string $value): string
    {
        return $this->sanitizer->html($value);
    }

    /**
     * @param mixed $value
     */
    private function safeJson(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '[]';
        }

        return $json;
    }
}
