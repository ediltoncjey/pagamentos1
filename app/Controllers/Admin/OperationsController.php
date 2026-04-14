<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\LedgerService;
use App\Services\PortalService;
use App\Services\Payments\GatewayCatalogService;
use App\Utils\Csrf;
use App\Utils\DashboardShell;
use App\Utils\Env;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Sanitizer;
use App\Utils\SessionManager;
use Throwable;

final class OperationsController
{
    public function __construct(
        private readonly PortalService $portal,
        private readonly LedgerService $ledger,
        private readonly GatewayCatalogService $gateways,
        private readonly DashboardShell $shell,
        private readonly SessionManager $session,
        private readonly Csrf $csrf,
        private readonly Sanitizer $sanitizer,
    ) {
    }

    public function payments(Request $request): Response
    {
        $months = max(1, min(24, (int) ($request->query()['months'] ?? 6)));
        $data = $this->portal->adminPaymentsOverview($months);
        if ($this->wantsJson($request)) {
            return Response::json($data);
        }

        $summary = (array) ($data['summary'] ?? []);
        $monthly = (array) ($data['monthly'] ?? []);
        $recent = (array) ($data['recent'] ?? []);

        $rows = '';
        foreach ($recent as $item) {
            $status = (string) ($item['status'] ?? 'initiated');
            $rows .= '<tr>'
                . '<td>' . $this->safe((string) ($item['order_no'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($item['provider_reference'] ?? '-')) . '</td>'
                . '<td>' . $this->money((float) ($item['amount'] ?? 0), (string) ($item['currency'] ?? 'MZN')) . '</td>'
                . '<td><span class="badge ' . $this->statusBadge($status) . '">' . $this->safe($status) . '</span></td>'
                . '<td>' . $this->safe((string) ($item['updated_at'] ?? '-')) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5"><div class="empty-state">Sem pagamentos registados.</div></td></tr>';
        }

        $content = '
<div class="kpi-grid">
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Total</div><span class="kpi-card__icon"><i class="bi bi-credit-card"></i></span></div><div class="kpi-card__value">' . $this->number((float) ($summary['total_payments'] ?? 0)) . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Confirmados</div><span class="kpi-card__icon"><i class="bi bi-check2-circle"></i></span></div><div class="kpi-card__value">' . $this->number((float) ($summary['confirmed_count'] ?? 0)) . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Falhados</div><span class="kpi-card__icon"><i class="bi bi-exclamation-octagon"></i></span></div><div class="kpi-card__value">' . $this->number((float) ($summary['failed_count'] ?? 0)) . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Confirmado (MZN)</div><span class="kpi-card__icon"><i class="bi bi-cash-stack"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($summary['confirmed_amount'] ?? 0), 'MZN') . '</div></article>
</div>
<section class="panel">
  <div class="panel__header"><div><h3 class="panel__title">Tendencia mensal</h3><p class="panel__subtitle">Pagamentos confirmados vs falhados.</p></div></div>
  <div class="chart-box"><canvas id="admin-payments-chart" width="980" height="280"></canvas></div>
</section>
<section class="panel">
  <div class="panel__header"><div><h3 class="panel__title">Pagamentos recentes</h3><p class="panel__subtitle">Dados reais integrados no UI.</p></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Pedido</th><th>Referencia</th><th>Valor</th><th>Status</th><th>Atualizado</th></tr></thead><tbody>' . $rows . '</tbody></table></div>
</section>';

        return new Response(200, $this->shell->render([
            'role' => 'admin',
            'active' => 'payments',
            'title' => 'Payments',
            'subtitle' => 'Monitorizacao operacional de pagamentos.',
            'breadcrumbs' => [
                ['label' => 'Admin'],
                ['label' => 'Transactions'],
                ['label' => 'Payments'],
            ],
            'toolbar' => '<a class="btn btn-outline" href="/admin/transactions"><i class="bi bi-receipt-cutoff"></i> Transactions</a><a class="btn btn-outline" href="/admin/disputes"><i class="bi bi-exclamation-octagon"></i> Disputes</a>',
            'content' => $content,
            'extraScripts' => $this->chartScript($monthly),
        ]));
    }

    public function transactions(Request $request): Response
    {
        $query = $request->query();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($query['per_page'] ?? 25)));
        $status = (string) ($query['status'] ?? 'all');

        $data = $this->portal->adminTransactions($page, $perPage, $status);
        if ($this->wantsJson($request)) {
            return Response::json($data);
        }

        $items = (array) ($data['items'] ?? []);
        $pagination = (array) ($data['pagination'] ?? []);
        $rows = '';
        foreach ($items as $item) {
            $orderStatus = (string) ($item['order_status'] ?? 'pending');
            $paymentStatus = (string) ($item['payment_status'] ?? '-');
            $rows .= '<tr>'
                . '<td>' . $this->safe((string) ($item['order_no'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($item['reseller_name'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($item['customer_phone'] ?? '-')) . '</td>'
                . '<td>' . $this->money((float) ($item['amount'] ?? 0), (string) ($item['currency'] ?? 'MZN')) . '</td>'
                . '<td><span class="badge ' . $this->statusBadge($orderStatus) . '">' . $this->safe($orderStatus) . '</span></td>'
                . '<td><span class="badge ' . $this->statusBadge($paymentStatus) . '">' . $this->safe($paymentStatus) . '</span></td>'
                . '<td>' . $this->safe((string) ($item['created_at'] ?? '-')) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7"><div class="empty-state">Sem transacoes.</div></td></tr>';
        }

        $selected = strtolower(trim($status));
        $content = '
<section class="panel">
  <div class="panel__header"><div><h3 class="panel__title">Filtros</h3><p class="panel__subtitle">Status e pagina.</p></div></div>
  <form method="get" action="/admin/transactions" class="form-grid form-grid--triple">
    <div class="form-group"><label class="label">Status</label><select class="select" name="status">
      <option value="all" ' . ($selected === '' || $selected === 'all' ? 'selected' : '') . '>Todos</option>
      <option value="pending" ' . ($selected === 'pending' ? 'selected' : '') . '>Pending</option>
      <option value="paid" ' . ($selected === 'paid' ? 'selected' : '') . '>Paid</option>
      <option value="failed" ' . ($selected === 'failed' ? 'selected' : '') . '>Failed</option>
      <option value="cancelled" ' . ($selected === 'cancelled' ? 'selected' : '') . '>Cancelled</option>
      <option value="expired" ' . ($selected === 'expired' ? 'selected' : '') . '>Expired</option>
    </select></div>
    <div class="form-group"><label class="label">Per page</label><input class="input" type="number" min="1" max="200" name="per_page" value="' . $perPage . '"></div>
    <div class="form-group" style="align-content:end;"><button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> Aplicar</button></div>
  </form>
</section>
<section class="panel">
  <div class="panel__header"><div><h3 class="panel__title">Transactions</h3><p class="panel__subtitle">Total ' . $this->number((float) ($pagination['total'] ?? 0)) . ' registos.</p></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Pedido</th><th>Reseller</th><th>Cliente</th><th>Valor</th><th>Order</th><th>Payment</th><th>Criado</th></tr></thead><tbody>' . $rows . '</tbody></table></div>
</section>';

        return new Response(200, $this->shell->render([
            'role' => 'admin',
            'active' => 'transactions-list',
            'title' => 'Transactions',
            'subtitle' => 'Pedidos com status de pagamento e ledger.',
            'breadcrumbs' => [
                ['label' => 'Admin'],
                ['label' => 'Transactions'],
                ['label' => 'Transactions'],
            ],
            'toolbar' => '<a class="btn btn-outline" href="/admin/payments"><i class="bi bi-credit-card"></i> Payments</a>',
            'content' => $content,
        ]));
    }

    public function disputes(Request $request): Response
    {
        $data = $this->portal->adminDisputes(200);
        if ($this->wantsJson($request)) {
            return Response::json($data);
        }

        $summary = (array) ($data['summary'] ?? []);
        $items = (array) ($data['items'] ?? []);
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr>'
                . '<td>' . $this->safe((string) ($item['order_no'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($item['reseller_name'] ?? '-')) . '</td>'
                . '<td><span class="badge ' . $this->statusBadge((string) ($item['order_status'] ?? 'failed')) . '">' . $this->safe((string) ($item['order_status'] ?? '-')) . '</span></td>'
                . '<td><span class="badge ' . $this->statusBadge((string) ($item['payment_status'] ?? 'failed')) . '">' . $this->safe((string) ($item['payment_status'] ?? '-')) . '</span></td>'
                . '<td>' . $this->safe((string) ($item['last_error'] ?? '-')) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5"><div class="empty-state">Sem disputas.</div></td></tr>';
        }

        $content = '
<div class="kpi-grid">
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Pedidos falhados</div><span class="kpi-card__icon"><i class="bi bi-x-octagon"></i></span></div><div class="kpi-card__value">' . $this->number((float) ($summary['failed_orders'] ?? 0)) . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Pagamentos com erro</div><span class="kpi-card__icon"><i class="bi bi-exclamation-triangle"></i></span></div><div class="kpi-card__value">' . $this->number((float) ($summary['failed_payments'] ?? 0)) . '</div></article>
</div>
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Disputes</h3><p class="panel__subtitle">Casos com falha de cobranca.</p></div></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Pedido</th><th>Reseller</th><th>Order</th><th>Payment</th><th>Erro</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';

        return new Response(200, $this->shell->render([
            'role' => 'admin',
            'active' => 'disputes',
            'title' => 'Disputes',
            'subtitle' => 'Gestao de incidentes de pagamento.',
            'breadcrumbs' => [
                ['label' => 'Admin'],
                ['label' => 'Transactions'],
                ['label' => 'Disputes'],
            ],
            'content' => $content,
        ]));
    }

    public function wallets(Request $request): Response
    {
        $data = $this->portal->adminWallets(120);
        if ($this->wantsJson($request)) {
            return Response::json($data);
        }

        $summary = (array) ($data['summary'] ?? []);
        $wallets = (array) ($data['wallets'] ?? []);
        $tx = (array) ($data['transactions'] ?? []);

        $walletRows = '';
        foreach ($wallets as $wallet) {
            $walletRows .= '<tr>'
                . '<td>' . $this->safe((string) ($wallet['name'] ?? '-')) . '</td>'
                . '<td>' . $this->money((float) ($wallet['balance_available'] ?? 0), (string) ($wallet['currency'] ?? 'MZN')) . '</td>'
                . '<td>' . $this->money((float) ($wallet['balance_pending'] ?? 0), (string) ($wallet['currency'] ?? 'MZN')) . '</td>'
                . '<td>' . $this->money((float) ($wallet['balance_total'] ?? 0), (string) ($wallet['currency'] ?? 'MZN')) . '</td>'
                . '</tr>';
        }
        if ($walletRows === '') {
            $walletRows = '<tr><td colspan="4"><div class="empty-state">Sem carteiras.</div></td></tr>';
        }

        $txRows = '';
        foreach ($tx as $item) {
            $status = (string) ($item['status'] ?? 'pending');
            $txRows .= '<tr>'
                . '<td>' . $this->safe((string) ($item['name'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($item['type'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($item['source'] ?? '-')) . '</td>'
                . '<td>' . $this->money((float) ($item['amount'] ?? 0), (string) ($item['currency'] ?? 'MZN')) . '</td>'
                . '<td><span class="badge ' . $this->statusBadge($status) . '">' . $this->safe($status) . '</span></td>'
                . '</tr>';
        }
        if ($txRows === '') {
            $txRows = '<tr><td colspan="5"><div class="empty-state">Sem movimentos.</div></td></tr>';
        }

        $content = '
<div class="kpi-grid">
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Wallets</div><span class="kpi-card__icon"><i class="bi bi-wallet2"></i></span></div><div class="kpi-card__value">' . $this->number((float) ($summary['wallets_count'] ?? 0)) . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Disponivel</div><span class="kpi-card__icon"><i class="bi bi-cash"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($summary['total_available'] ?? 0), 'MZN') . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Pendente</div><span class="kpi-card__icon"><i class="bi bi-hourglass"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($summary['total_pending'] ?? 0), 'MZN') . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Total</div><span class="kpi-card__icon"><i class="bi bi-bank2"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($summary['total_balance'] ?? 0), 'MZN') . '</div></article>
</div>
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Wallets</h3></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Utilizador</th><th>Disponivel</th><th>Pendente</th><th>Total</th></tr></thead><tbody>' . $walletRows . '</tbody></table></div></section>
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Movimentos recentes</h3></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Utilizador</th><th>Tipo</th><th>Origem</th><th>Valor</th><th>Status</th></tr></thead><tbody>' . $txRows . '</tbody></table></div></section>';

        return new Response(200, $this->shell->render([
            'role' => 'admin',
            'active' => 'wallets',
            'title' => 'Wallets',
            'subtitle' => 'Saldos e movimentacoes do ledger.',
            'breadcrumbs' => [
                ['label' => 'Admin'],
                ['label' => 'Finance'],
                ['label' => 'Wallets'],
            ],
            'content' => $content,
        ]));
    }

    public function payouts(Request $request): Response
    {
        $data = $this->portal->adminPayouts(200);
        if ($this->wantsJson($request)) {
            return Response::json($data);
        }

        $summary = (array) ($data['summary'] ?? []);
        $items = (array) ($data['items'] ?? []);
        $flash = $this->flashFromQuery($request->query());
        $csrfToken = $this->safe($this->csrf->token());

        $rows = '';
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            $settlement = (string) ($item['settlement_status'] ?? 'pending');
            $action = '';
            if ($settlement === 'pending') {
                $action = '<form method="post" action="/admin/payouts/' . $id . '/settle"><input type="hidden" name="' . $this->safe($this->csrf->tokenName()) . '" value="' . $csrfToken . '"><button class="btn btn-outline" type="submit"><i class="bi bi-check2-circle"></i> Settlar</button></form>';
            }

            $rows .= '<tr>'
                . '<td>#' . $id . '</td>'
                . '<td>' . $this->safe((string) ($item['order_no'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($item['reseller_name'] ?? '-')) . '</td>'
                . '<td>' . $this->money((float) ($item['reseller_earning'] ?? 0), (string) ($item['currency'] ?? 'MZN')) . '</td>'
                . '<td><span class="badge ' . $this->statusBadge($settlement) . '">' . $this->safe($settlement) . '</span></td>'
                . '<td>' . $action . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6"><div class="empty-state">Sem registros para payout.</div></td></tr>';
        }

        $content = $flash . '
<div class="kpi-grid">
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Pendente</div><span class="kpi-card__icon"><i class="bi bi-hourglass-split"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($summary['pending_amount'] ?? 0), 'MZN') . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Settled</div><span class="kpi-card__icon"><i class="bi bi-check2-circle"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($summary['settled_amount'] ?? 0), 'MZN') . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Total</div><span class="kpi-card__icon"><i class="bi bi-cash-stack"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($summary['total_amount'] ?? 0), 'MZN') . '</div></article>
</div>
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Payouts</h3></div><form method="post" action="/admin/payouts/reconcile"><input type="hidden" name="' . $this->safe($this->csrf->tokenName()) . '" value="' . $csrfToken . '"><button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat"></i> Reconciliar</button></form></div>
<div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Pedido</th><th>Reseller</th><th>Valor</th><th>Settlement</th><th>Acao</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';

        return new Response(200, $this->shell->render([
            'role' => 'admin',
            'active' => 'payouts',
            'title' => 'Payouts',
            'subtitle' => 'Reconciliacao de comissoes para disponibilidade.',
            'breadcrumbs' => [
                ['label' => 'Admin'],
                ['label' => 'Finance'],
                ['label' => 'Payouts'],
            ],
            'content' => $content,
        ]));
    }

    public function settlePayout(Request $request): Response
    {
        $id = (int) $request->route('id', 0);
        if ($id <= 0) {
            return Response::redirect('/admin/payouts?error=' . rawurlencode('ID invalido.'));
        }

        try {
            $this->ledger->settleCommission($id, $this->context($request));
            return Response::redirect('/admin/payouts?success=' . rawurlencode('Comissao reconciliada.'));
        } catch (Throwable $exception) {
            return Response::redirect('/admin/payouts?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function reconcilePayouts(Request $request): Response
    {
        try {
            $result = $this->ledger->reconcilePendingCommissions(300, null, $this->context($request));
            $msg = sprintf('Settled %d, falhas %d.', (int) ($result['settled_count'] ?? 0), (int) ($result['failed_count'] ?? 0));
            return Response::redirect('/admin/payouts?success=' . rawurlencode($msg));
        } catch (Throwable $exception) {
            return Response::redirect('/admin/payouts?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function apiSettings(Request $request): Response
    {
        $gatewayCatalog = $this->gateways->listAll();
        $data = [
            'provider' => (string) Env::get('PAYMENT_PROVIDER', 'rozvitech'),
            'base_url' => (string) Env::get('PAYMENT_API_BASE_URL', ''),
            'enable_callback' => filter_var(Env::get('PAYMENT_ENABLE_CALLBACK', true), FILTER_VALIDATE_BOOL),
            'enable_polling' => filter_var(Env::get('PAYMENT_ENABLE_POLLING', true), FILTER_VALIDATE_BOOL),
            'poll_interval' => (int) Env::get('PAYMENT_POLL_INTERVAL_SECONDS', 90),
            'timeout' => (int) Env::get('PAYMENT_TIMEOUT_SECONDS', 30),
            'api_key' => $this->maskApiKey((string) Env::get('PAYMENT_API_KEY', '')),
        ];
        if ($this->wantsJson($request)) {
            return Response::json([
                'payment' => $data,
                'gateways' => $gatewayCatalog,
            ]);
        }

        $gatewayRows = '';
        foreach ($gatewayCatalog as $gateway) {
            $gatewayRows .= '<tr>'
                . '<td>' . $this->safe((string) ($gateway['display_name'] ?? $gateway['code'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($gateway['code'] ?? '-')) . '</td>'
                . '<td><span class="badge ' . ((int) ($gateway['is_enabled'] ?? 0) === 1 ? 'badge--success' : 'badge--warning') . '">' . ((int) ($gateway['is_enabled'] ?? 0) === 1 ? 'Ativo' : 'Inativo') . '</span></td>'
                . '<td><span class="badge ' . ((int) ($gateway['is_configured'] ?? 0) === 1 ? 'badge--success' : 'badge--warning') . '">' . ((int) ($gateway['is_configured'] ?? 0) === 1 ? 'Configurado' : 'Pendente') . '</span></td>'
                . '</tr>';
        }
        if ($gatewayRows === '') {
            $gatewayRows = '<tr><td colspan="4"><div class="empty-state">Sem gateways.</div></td></tr>';
        }

        $content = '
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">API Settings</h3><p class="panel__subtitle">Configuracao backend da integracao.</p></div></div>
<ul class="stats-list">
  <li><span class="stats-list__label">Provider</span><span class="stats-list__value">' . $this->safe((string) $data['provider']) . '</span></li>
  <li><span class="stats-list__label">Base URL</span><span class="stats-list__value">' . $this->safe((string) $data['base_url']) . '</span></li>
  <li><span class="stats-list__label">API key</span><span class="stats-list__value">' . $this->safe((string) $data['api_key']) . '</span></li>
  <li><span class="stats-list__label">Callback</span><span class="stats-list__value">' . ($data['enable_callback'] ? 'Ativo' : 'Inativo') . '</span></li>
  <li><span class="stats-list__label">Polling</span><span class="stats-list__value">' . ($data['enable_polling'] ? 'Ativo' : 'Inativo') . '</span></li>
  <li><span class="stats-list__label">Poll interval</span><span class="stats-list__value">' . (int) $data['poll_interval'] . 's</span></li>
  <li><span class="stats-list__label">Timeout</span><span class="stats-list__value">' . (int) $data['timeout'] . 's</span></li>
</ul></section>
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Gateways</h3><p class="panel__subtitle">Ative/desative por configuracao e credenciais.</p></div></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Gateway</th><th>Codigo</th><th>Status</th><th>Configuracao</th></tr></thead><tbody>' . $gatewayRows . '</tbody></table></div></section>';

        return new Response(200, $this->shell->render([
            'role' => 'admin',
            'active' => 'api-settings',
            'title' => 'API Settings',
            'subtitle' => 'Parametros de pagamento e resiliencia.',
            'breadcrumbs' => [
                ['label' => 'Admin'],
                ['label' => 'Settings'],
                ['label' => 'API Settings'],
            ],
            'content' => $content,
        ]));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function chartScript(array $rows): string
    {
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '[]';
        }

        return '<script>(function(){const rows=' . $json . ';const c=document.getElementById("admin-payments-chart");if(!c||!Array.isArray(rows)||rows.length===0)return;const x=c.getContext("2d");if(!x)return;const w=c.width,h=c.height,p={l:44,r:12,t:16,b:36},cw=w-p.l-p.r,ch=h-p.t-p.b;const m=Math.max(1,...rows.map(r=>Math.max(Number(r.confirmed_count||0),Number(r.failed_count||0))));x.clearRect(0,0,w,h);x.strokeStyle="rgba(127,149,188,.26)";for(let i=0;i<=4;i++){const y=p.t+(ch/4)*i;x.beginPath();x.moveTo(p.l,y);x.lineTo(w-p.r,y);x.stroke();}const gw=cw/rows.length,bw=Math.max(7,Math.min(24,gw*.27));rows.forEach((r,i)=>{const cx=p.l+i*gw+gw*.5;const ok=Number(r.confirmed_count||0),ko=Number(r.failed_count||0);const hok=ok/m*ch,hko=ko/m*ch;x.fillStyle="#40c988";x.fillRect(cx-bw-2,p.t+ch-hok,bw,hok);x.fillStyle="#ff7870";x.fillRect(cx+2,p.t+ch-hko,bw,hko);x.fillStyle="#889cc2";x.font="11px Plus Jakarta Sans,sans-serif";x.textAlign="center";x.fillText(String(r.month_label||r.month_key||""),cx,h-10);});})();</script>';
    }

    /**
     * @param array<string, mixed> $query
     */
    private function flashFromQuery(array $query): string
    {
        $success = trim((string) ($query['success'] ?? ''));
        if ($success !== '') {
            return '<div class="alert alert--success">' . $this->safe($success) . '</div>';
        }

        $error = trim((string) ($query['error'] ?? ''));
        if ($error !== '') {
            return '<div class="alert alert--error">' . $this->safe($error) . '</div>';
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Request $request): array
    {
        $user = $this->session->user();
        return [
            'actor_user_id' => $user['id'] ?? null,
            'actor_role' => $user['role'] ?? 'admin',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => (string) $request->header('X-Request-Id', ''),
        ];
    }

    private function statusBadge(string $status): string
    {
        return match (strtolower(trim($status))) {
            'paid', 'confirmed', 'available', 'settled', 'success' => 'badge--success',
            'failed', 'timeout', 'cancelled', 'expired', 'reversed' => 'badge--danger',
            default => 'badge--warning',
        };
    }

    private function money(float $amount, string $currency): string
    {
        $currency = trim($currency) !== '' ? strtoupper($currency) : 'MZN';
        return $currency . ' ' . number_format($amount, 2, '.', ',');
    }

    private function number(float $value): string
    {
        return number_format($value, 0, '.', ',');
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        return str_contains($accept, 'application/json') || str_starts_with($request->path(), '/api/');
    }

    private function maskApiKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Nao definido';
        }
        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4) . str_repeat('*', max(1, strlen($value) - 8)) . substr($value, -4);
    }

    private function safe(string $value): string
    {
        return $this->sanitizer->html($value);
    }
}
