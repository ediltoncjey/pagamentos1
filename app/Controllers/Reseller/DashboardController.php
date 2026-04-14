<?php

declare(strict_types=1);

namespace App\Controllers\Reseller;

use App\Services\ReportService;
use App\Services\WalletService;
use App\Utils\DashboardShell;
use App\Utils\Env;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Sanitizer;
use App\Utils\SessionManager;

final class DashboardController
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly WalletService $wallets,
        private readonly DashboardShell $shell,
        private readonly SessionManager $session,
        private readonly Sanitizer $sanitizer,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->session->user();
        $resellerId = (int) ($user['id'] ?? 0);
        if ($resellerId <= 0) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $months = $this->dashboardMonths();
        $summary = $this->reports->resellerSummary($resellerId);
        $monthly = $this->reports->resellerMonthlyOverview($resellerId, $months);
        $history = $this->reports->resellerOrderHistory($resellerId, 12, 0, null);
        $ledger = $this->wallets->resellerLedgerSnapshot($resellerId, 12, 12);

        $payload = [
            'dashboard' => 'reseller',
            'summary' => $summary,
            'monthly' => $monthly,
            'history' => $history,
            'ledger' => $ledger,
        ];

        if ($this->wantsJson($request)) {
            return Response::json($payload);
        }

        return new Response(200, $this->renderDashboard($payload, (string) ($user['name'] ?? 'Comissionista')));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderDashboard(array $payload, string $displayName): string
    {
        $summary = $payload['summary'] ?? [];
        $totals = $summary['totals'] ?? [];
        $commissions = $summary['commissions'] ?? [];
        $wallet = $summary['wallet'] ?? [];
        $monthly = $payload['monthly'] ?? [];
        $historyRows = $payload['history']['items'] ?? [];
        $ledger = $payload['ledger'] ?? [];
        $txRows = $ledger['recent_transactions'] ?? [];

        $available = (float) ($wallet['balance_available'] ?? 0.0);
        $pending = (float) ($wallet['balance_pending'] ?? 0.0);
        $total = (float) ($wallet['balance_total'] ?? 0.0);
        $currency = $this->safe((string) ($wallet['currency'] ?? 'MZN'));

        $totalOrders = (int) ($totals['total_orders'] ?? 0);
        $paidOrders = (int) ($totals['paid_orders'] ?? 0);
        $failedOrders = (int) ($totals['failed_or_cancelled_orders'] ?? 0);
        $conversionRate = (float) ($totals['conversion_rate'] ?? 0.0);
        $gross = (float) ($totals['total_gross'] ?? 0.0);

        $resellerEarning = (float) ($commissions['reseller_earning_total'] ?? 0.0);
        $resellerPending = (float) ($commissions['reseller_pending_total'] ?? 0.0);
        $resellerSettled = (float) ($commissions['reseller_settled_total'] ?? 0.0);

        $months = count($monthly);
        $availableRatio = $total > 0 ? ($available / $total) * 100 : 0.0;
        $pendingRatio = $total > 0 ? ($pending / $total) * 100 : 0.0;

        $orderRows = '';
        foreach ($historyRows as $row) {
            $status = (string) ($row['status'] ?? 'pending');
            $badge = match ($status) {
                'paid' => 'badge--success',
                'failed', 'cancelled' => 'badge--danger',
                default => 'badge--warning',
            };

            $orderRows .= '<tr>'
                . '<td>' . $this->safe((string) ($row['order_no'] ?? '')) . '</td>'
                . '<td>' . $this->money((float) ($row['amount'] ?? 0)) . '</td>'
                . '<td><span class="badge ' . $badge . '">' . $this->safe($status) . '</span></td>'
                . '<td>' . $this->safe((string) ($row['payment_status'] ?? '')) . '</td>'
                . '<td>' . $this->safe((string) ($row['settlement_status'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($row['created_at'] ?? '-')) . '</td>'
                . '</tr>';
        }
        if ($orderRows === '') {
            $orderRows = '<tr><td colspan="6"><div class="empty-state">Ainda nao existem pedidos para este comissionista.</div></td></tr>';
        }

        $txTableRows = '';
        $txListItems = '';
        foreach ($txRows as $row) {
            $txStatus = (string) ($row['status'] ?? 'pending');
            $txBadge = match ($txStatus) {
                'available' => 'badge--success',
                'failed', 'reversed' => 'badge--danger',
                default => 'badge--warning',
            };

            $type = $this->safe((string) ($row['type'] ?? ''));
            $source = $this->safe((string) ($row['source'] ?? ''));
            $amount = $this->money((float) ($row['amount'] ?? 0));
            $occurredAt = $this->safe((string) ($row['occurred_at'] ?? '-'));

            $txTableRows .= '<tr>'
                . '<td>' . $type . '</td>'
                . '<td>' . $source . '</td>'
                . '<td>' . $amount . '</td>'
                . '<td><span class="badge ' . $txBadge . '">' . $this->safe($txStatus) . '</span></td>'
                . '<td>' . $occurredAt . '</td>'
                . '</tr>';

            $txListItems .= '<li class="list-item">'
                . '<div class="list-item__main">'
                . '<p class="list-item__title">' . strtoupper($type) . ' · ' . strtoupper($source) . '</p>'
                . '<p class="list-item__meta">' . $occurredAt . '</p>'
                . '</div>'
                . '<div style="text-align:right;">'
                . '<strong>' . $amount . '</strong><br>'
                . '<span class="badge ' . $txBadge . '">' . $this->safe($txStatus) . '</span>'
                . '</div>'
                . '</li>';
        }

        if ($txTableRows === '') {
            $txTableRows = '<tr><td colspan="5"><div class="empty-state">Sem movimentos na carteira.</div></td></tr>';
            $txListItems = '<div class="empty-state">Sem transacoes recentes.</div>';
        } else {
            $txListItems = '<ul class="list">' . $txListItems . '</ul>';
        }

        $content = <<<HTML
<section class="dashboard-hero">
  <div class="dashboard-hero__row">
    <div>
      <h2 class="dashboard-hero__title">Ola, {$this->safe($displayName)}. O seu cockpit financeiro esta pronto.</h2>
      <p class="dashboard-hero__subtitle">
        Acompanhe vendas em tempo real, evolucao das comissoes e saude da sua carteira sem sair do painel.
      </p>
      <div class="hero-pills">
        <span class="pill"><i class="bi bi-wallet2"></i> Saldo disponivel {$this->money($available)}</span>
        <span class="pill"><i class="bi bi-hourglass-split"></i> Pendente {$this->money($pending)}</span>
        <span class="pill"><i class="bi bi-graph-up"></i> Conversao {$this->percent($conversionRate)}</span>
      </div>
    </div>

    <div class="hero-insight">
      <article class="hero-insight__item">
        <div class="hero-insight__label">Carteira total</div>
        <div class="hero-insight__value">{$this->money($total)}</div>
        <div class="hero-insight__meta">Moeda operacional: {$currency}</div>
      </article>

      <article class="hero-insight__item">
        <div class="hero-insight__label">Pedidos processados</div>
        <div class="hero-insight__value">{$totalOrders}</div>
        <div class="hero-insight__meta">Pagos {$paidOrders} | Sem sucesso {$failedOrders}</div>
      </article>
    </div>
  </div>
</section>

<div class="kpi-grid">
  <article class="kpi-card">
    <div class="kpi-card__head">
      <div class="kpi-card__label">Saldo disponivel</div>
      <span class="kpi-card__icon"><i class="bi bi-wallet"></i></span>
    </div>
    <div class="kpi-card__value">{$this->money($available)}</div>
    <div class="kpi-card__meta">Pronto para payout</div>
  </article>

  <article class="kpi-card">
    <div class="kpi-card__head">
      <div class="kpi-card__label">Saldo pendente</div>
      <span class="kpi-card__icon"><i class="bi bi-clock-history"></i></span>
    </div>
    <div class="kpi-card__value">{$this->money($pending)}</div>
    <div class="kpi-card__meta">Em reconciliacao</div>
  </article>

  <article class="kpi-card">
    <div class="kpi-card__head">
      <div class="kpi-card__label">Ganhos acumulados</div>
      <span class="kpi-card__icon"><i class="bi bi-currency-dollar"></i></span>
    </div>
    <div class="kpi-card__value">{$this->money($resellerEarning)}</div>
    <div class="kpi-card__meta">Comissao historica total</div>
  </article>

  <article class="kpi-card">
    <div class="kpi-card__head">
      <div class="kpi-card__label">Faturacao bruta</div>
      <span class="kpi-card__icon"><i class="bi bi-graph-up-arrow"></i></span>
    </div>
    <div class="kpi-card__value">{$this->money($gross)}</div>
    <div class="kpi-card__meta">Base das comissoes calculadas</div>
  </article>
</div>

<div class="content-grid">
  <div class="content-main">
    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Tendencia mensal de vendas</h3>
          <p class="panel__subtitle">Comparativo entre faturacao e ganho do comissionista nos ultimos {$months} meses.</p>
        </div>
      </div>

      <div class="legend-inline">
        <span><i style="background:#3f88ff;"></i> Faturacao bruta</span>
        <span><i style="background:#34b9a6;"></i> Ganho comissionista</span>
      </div>

      <div class="chart-box"><canvas id="reseller-monthly-chart" width="980" height="290"></canvas></div>
    </section>

    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Pedidos recentes</h3>
          <p class="panel__subtitle">Seguimento de pagamento e settlement de cada pedido.</p>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Pedido</th>
              <th>Valor</th>
              <th>Ordem</th>
              <th>Pagamento</th>
              <th>Settlement</th>
              <th>Criado em</th>
            </tr>
          </thead>
          <tbody>{$orderRows}</tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Movimentos detalhados da carteira</h3>
          <p class="panel__subtitle">Historico completo de creditos e debitos no ledger.</p>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Tipo</th>
              <th>Origem</th>
              <th>Valor</th>
              <th>Status</th>
              <th>Data</th>
            </tr>
          </thead>
          <tbody>{$txTableRows}</tbody>
        </table>
      </div>
    </section>
  </div>

  <aside class="content-side">
    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Saude da carteira</h3>
          <p class="panel__subtitle">Distribuicao entre saldo disponivel e pendente.</p>
        </div>
      </div>

      <ul class="stats-list">
        <li>
          <span class="stats-list__label">Disponivel</span>
          <span class="stats-list__value">{$this->money($available)}</span>
        </li>
        <li>
          <span class="stats-list__label">Pendente</span>
          <span class="stats-list__value">{$this->money($pending)}</span>
        </li>
        <li>
          <span class="stats-list__label">Comissoes pendentes</span>
          <span class="stats-list__value">{$this->money($resellerPending)}</span>
        </li>
        <li>
          <span class="stats-list__label">Comissoes liquidadas</span>
          <span class="stats-list__value">{$this->money($resellerSettled)}</span>
        </li>
      </ul>

      <div style="margin-top:12px;">
        <div class="form-hint" style="margin-bottom:6px;">Disponivel: {$this->percent($availableRatio)}</div>
        <div class="progress-track"><div class="progress-fill" style="width: {$this->percent($availableRatio)};"></div></div>
      </div>
      <div style="margin-top:10px;">
        <div class="form-hint" style="margin-bottom:6px;">Pendente: {$this->percent($pendingRatio)}</div>
        <div class="progress-track"><div class="progress-fill" style="width: {$this->percent($pendingRatio)};"></div></div>
      </div>
    </section>

    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Atividade recente</h3>
          <p class="panel__subtitle">Ultimas transacoes do seu wallet ledger.</p>
        </div>
      </div>
      <div class="panel__body">{$txListItems}</div>
    </section>
  </aside>
</div>
HTML;

        $toolbar = implode('', [
            '<a class="btn btn-primary" href="/reseller/products"><i class="bi bi-box-seam"></i> Produtos</a>',
            '<a class="btn btn-outline" href="/reseller/payment-pages"><i class="bi bi-link-45deg"></i> Paginas de pagamento</a>',
            '<a class="btn btn-outline" href="/api/reseller/reports/export?type=monthly" target="_blank" rel="noopener"><i class="bi bi-download"></i> Exportar CSV</a>',
        ]);

        $scripts = $this->chartScript($monthly, 'reseller-monthly-chart', 'total_gross', 'reseller_earning');

        return $this->shell->render([
            'role' => 'reseller',
            'active' => 'dashboard',
            'title' => 'Dashboard do Comissionista',
            'subtitle' => 'Monitorize vendas, comissoes e saldo da carteira em tempo real.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
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
