<?php

declare(strict_types=1);

namespace App\Controllers\Reseller;

use App\Services\PortalService;
use App\Services\Payments\GatewayCatalogService;
use App\Utils\DashboardShell;
use App\Utils\Env;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Sanitizer;
use App\Utils\SessionManager;

final class OperationsController
{
    public function __construct(
        private readonly PortalService $portal,
        private readonly GatewayCatalogService $gateways,
        private readonly DashboardShell $shell,
        private readonly SessionManager $session,
        private readonly Sanitizer $sanitizer,
    ) {
    }

    public function contacts(Request $request): Response
    {
        $resellerId = $this->userId();
        $data = $this->portal->resellerContacts($resellerId, 200);
        if ($this->wantsJson($request)) {
            return Response::json($data);
        }

        $summary = (array) ($data['summary'] ?? []);
        $items = (array) ($data['items'] ?? []);
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr>'
                . '<td>' . $this->safe((string) ($item['customer_phone'] ?? '-')) . '</td>'
                . '<td>' . $this->number((float) ($item['total_orders'] ?? 0)) . '</td>'
                . '<td>' . $this->number((float) ($item['paid_orders'] ?? 0)) . '</td>'
                . '<td>' . $this->money((float) ($item['paid_volume'] ?? 0), 'MZN') . '</td>'
                . '<td>' . $this->safe((string) ($item['last_order_at'] ?? '-')) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5"><div class="empty-state">Sem contactos/clientes.</div></td></tr>';
        }

        $content = '
<div class="kpi-grid">
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Clientes unicos</div><span class="kpi-card__icon"><i class="bi bi-people"></i></span></div><div class="kpi-card__value">' . $this->number((float) ($summary['unique_customers'] ?? 0)) . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Pedidos</div><span class="kpi-card__icon"><i class="bi bi-receipt"></i></span></div><div class="kpi-card__value">' . $this->number((float) ($summary['total_orders'] ?? 0)) . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Volume pago</div><span class="kpi-card__icon"><i class="bi bi-cash-stack"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($summary['paid_amount'] ?? 0), 'MZN') . '</div></article>
</div>
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Contacts</h3><p class="panel__subtitle">Lista de clientes por telefone.</p></div></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Telefone</th><th>Pedidos</th><th>Pagos</th><th>Volume</th><th>Ultimo pedido</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';

        return new Response(200, $this->shell->render([
            'role' => 'reseller',
            'active' => 'contacts',
            'title' => 'Contacts',
            'subtitle' => 'Carteira de clientes e historico de compra.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Contacts'],
            ],
            'content' => $content,
        ]));
    }

    public function payments(Request $request): Response
    {
        $resellerId = $this->userId();
        $months = max(1, min(24, (int) ($request->query()['months'] ?? 6)));
        $data = $this->portal->resellerPaymentsOverview($resellerId, $months);
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
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Tendencia mensal</h3><p class="panel__subtitle">Pagamentos confirmados e falhados.</p></div></div><div class="chart-box"><canvas id="reseller-payments-chart" width="980" height="280"></canvas></div></section>
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Pagamentos recentes</h3></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Pedido</th><th>Referencia</th><th>Valor</th><th>Status</th><th>Atualizado</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';

        return new Response(200, $this->shell->render([
            'role' => 'reseller',
            'active' => 'sales',
            'title' => 'Payments',
            'subtitle' => 'Acompanhamento da cobranca dos seus pedidos.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Transactions'],
                ['label' => 'Payments'],
            ],
            'toolbar' => '<a class="btn btn-outline" href="/reseller/transactions"><i class="bi bi-receipt-cutoff"></i> Transactions</a><a class="btn btn-outline" href="/reseller/disputes"><i class="bi bi-exclamation-octagon"></i> Disputes</a>',
            'content' => $content,
            'extraScripts' => $this->chartScript($monthly, 'reseller-payments-chart'),
        ]));
    }

    public function transactions(Request $request): Response
    {
        $resellerId = $this->userId();
        $query = $request->query();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($query['per_page'] ?? 25)));
        $status = (string) ($query['status'] ?? 'all');

        $data = $this->portal->resellerTransactions($resellerId, $page, $perPage, $status);
        if ($this->wantsJson($request)) {
            return Response::json($data);
        }

        $items = (array) ($data['items'] ?? []);
        $rows = '';
        foreach ($items as $item) {
            $orderStatus = (string) ($item['order_status'] ?? 'pending');
            $paymentStatus = (string) ($item['payment_status'] ?? '-');
            $rows .= '<tr>'
                . '<td>' . $this->safe((string) ($item['order_no'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($item['customer_phone'] ?? '-')) . '</td>'
                . '<td>' . $this->money((float) ($item['amount'] ?? 0), (string) ($item['currency'] ?? 'MZN')) . '</td>'
                . '<td><span class="badge ' . $this->statusBadge($orderStatus) . '">' . $this->safe($orderStatus) . '</span></td>'
                . '<td><span class="badge ' . $this->statusBadge($paymentStatus) . '">' . $this->safe($paymentStatus) . '</span></td>'
                . '<td>' . $this->safe((string) ($item['settlement_status'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($item['created_at'] ?? '-')) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7"><div class="empty-state">Sem transacoes.</div></td></tr>';
        }

        $selected = strtolower(trim($status));
        $content = '
<section class="panel">
  <div class="panel__header"><div><h3 class="panel__title">Filtros</h3><p class="panel__subtitle">Estado dos pedidos.</p></div></div>
  <form method="get" action="/reseller/transactions" class="form-grid form-grid--triple">
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
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Transactions</h3></div></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Pedido</th><th>Cliente</th><th>Valor</th><th>Order</th><th>Payment</th><th>Settlement</th><th>Criado</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';

        return new Response(200, $this->shell->render([
            'role' => 'reseller',
            'active' => 'sales',
            'title' => 'Transactions',
            'subtitle' => 'Historico completo de pedidos e estados.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Transactions'],
                ['label' => 'Transactions'],
            ],
            'content' => $content,
        ]));
    }

    public function earnings(Request $request): Response
    {
        $resellerId = $this->userId();
        $walletData = $this->portal->resellerWallet($resellerId, 200);
        $payoutData = $this->portal->resellerPayouts($resellerId, 200);

        $payload = [
            'wallet' => $walletData['wallet'] ?? [],
            'transactions' => $walletData['transactions'] ?? [],
            'commissions' => $payoutData['items'] ?? [],
            'summary' => $payoutData['summary'] ?? [],
        ];
        if ($this->wantsJson($request)) {
            return Response::json($payload);
        }

        $wallet = (array) ($payload['wallet'] ?? []);
        $transactions = (array) ($payload['transactions'] ?? []);
        $commissions = (array) ($payload['commissions'] ?? []);
        $summary = (array) ($payload['summary'] ?? []);

        $txRows = '';
        foreach ($transactions as $item) {
            $status = (string) ($item['status'] ?? 'pending');
            $txRows .= '<tr>'
                . '<td>' . $this->safe((string) ($item['type'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($item['source'] ?? '-')) . '</td>'
                . '<td>' . $this->money((float) ($item['amount'] ?? 0), (string) ($item['currency'] ?? 'MZN')) . '</td>'
                . '<td><span class="badge ' . $this->statusBadge($status) . '">' . $this->safe($status) . '</span></td>'
                . '<td>' . $this->safe((string) ($item['occurred_at'] ?? '-')) . '</td>'
                . '</tr>';
        }
        if ($txRows === '') {
            $txRows = '<tr><td colspan="5"><div class="empty-state">Sem transacoes de ganhos.</div></td></tr>';
        }

        $commissionRows = '';
        foreach ($commissions as $item) {
            $settlement = (string) ($item['settlement_status'] ?? 'pending');
            $commissionRows .= '<tr>'
                . '<td>' . $this->safe((string) ($item['order_no'] ?? '-')) . '</td>'
                . '<td>' . $this->money((float) ($item['gross_amount'] ?? 0), (string) ($item['currency'] ?? 'MZN')) . '</td>'
                . '<td>' . $this->money((float) ($item['reseller_earning'] ?? 0), (string) ($item['currency'] ?? 'MZN')) . '</td>'
                . '<td><span class="badge ' . $this->statusBadge($settlement) . '">' . $this->safe($settlement) . '</span></td>'
                . '<td>' . $this->safe((string) ($item['created_at'] ?? '-')) . '</td>'
                . '</tr>';
        }
        if ($commissionRows === '') {
            $commissionRows = '<tr><td colspan="5"><div class="empty-state">Sem comissoes registadas ainda.</div></td></tr>';
        }

        $content = '
<div class="kpi-grid">
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Saldo disponivel</div><span class="kpi-card__icon"><i class="bi bi-cash-stack"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($wallet['balance_available'] ?? 0), (string) ($wallet['currency'] ?? 'MZN')) . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Saldo pendente</div><span class="kpi-card__icon"><i class="bi bi-hourglass-split"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($wallet['balance_pending'] ?? 0), (string) ($wallet['currency'] ?? 'MZN')) . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Total ganho</div><span class="kpi-card__icon"><i class="bi bi-graph-up-arrow"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($summary['total_amount'] ?? 0), 'MZN') . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Total liquidado</div><span class="kpi-card__icon"><i class="bi bi-check2-circle"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($summary['settled_amount'] ?? 0), 'MZN') . '</div></article>
</div>
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Historico de comissoes</h3><p class="panel__subtitle">Registo de ganhos por pedido.</p></div></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Pedido</th><th>Bruto</th><th>Ganho</th><th>Settlement</th><th>Data</th></tr></thead><tbody>' . $commissionRows . '</tbody></table></div></section>
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Transacoes da carteira</h3><p class="panel__subtitle">Movimentos pendentes e disponiveis.</p></div></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Tipo</th><th>Origem</th><th>Valor</th><th>Status</th><th>Data</th></tr></thead><tbody>' . $txRows . '</tbody></table></div></section>';

        return new Response(200, $this->shell->render([
            'role' => 'reseller',
            'active' => 'earnings',
            'title' => 'Ganhos',
            'subtitle' => 'Saldo disponivel, pendente e historico completo de comissoes.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Ganhos'],
            ],
            'content' => $content,
        ]));
    }

    public function disputes(Request $request): Response
    {
        $resellerId = $this->userId();
        $data = $this->portal->resellerDisputes($resellerId, 200);
        if ($this->wantsJson($request)) {
            return Response::json($data);
        }

        $summary = (array) ($data['summary'] ?? []);
        $items = (array) ($data['items'] ?? []);
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr>'
                . '<td>' . $this->safe((string) ($item['order_no'] ?? '-')) . '</td>'
                . '<td><span class="badge ' . $this->statusBadge((string) ($item['order_status'] ?? 'failed')) . '">' . $this->safe((string) ($item['order_status'] ?? '-')) . '</span></td>'
                . '<td><span class="badge ' . $this->statusBadge((string) ($item['payment_status'] ?? 'failed')) . '">' . $this->safe((string) ($item['payment_status'] ?? '-')) . '</span></td>'
                . '<td>' . $this->safe((string) ($item['last_error'] ?? '-')) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4"><div class="empty-state">Sem disputas.</div></td></tr>';
        }

        $content = '
<div class="kpi-grid">
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Pedidos falhados</div><span class="kpi-card__icon"><i class="bi bi-x-octagon"></i></span></div><div class="kpi-card__value">' . $this->number((float) ($summary['failed_orders'] ?? 0)) . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Pagamentos com erro</div><span class="kpi-card__icon"><i class="bi bi-exclamation-triangle"></i></span></div><div class="kpi-card__value">' . $this->number((float) ($summary['failed_payments'] ?? 0)) . '</div></article>
</div>
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Disputes</h3></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Pedido</th><th>Order</th><th>Payment</th><th>Erro</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';

        return new Response(200, $this->shell->render([
            'role' => 'reseller',
            'active' => 'disputes',
            'title' => 'Disputes',
            'subtitle' => 'Erros e anomalias dos seus pagamentos.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Transactions'],
                ['label' => 'Disputes'],
            ],
            'content' => $content,
        ]));
    }

    public function wallet(Request $request): Response
    {
        $resellerId = $this->userId();
        $data = $this->portal->resellerWallet($resellerId, 200);
        if ($this->wantsJson($request)) {
            return Response::json($data);
        }

        $wallet = (array) ($data['wallet'] ?? []);
        $transactions = (array) ($data['transactions'] ?? []);
        $rows = '';
        foreach ($transactions as $item) {
            $status = (string) ($item['status'] ?? 'pending');
            $rows .= '<tr>'
                . '<td>' . $this->safe((string) ($item['type'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($item['source'] ?? '-')) . '</td>'
                . '<td>' . $this->money((float) ($item['amount'] ?? 0), (string) ($item['currency'] ?? 'MZN')) . '</td>'
                . '<td><span class="badge ' . $this->statusBadge($status) . '">' . $this->safe($status) . '</span></td>'
                . '<td>' . $this->safe((string) ($item['occurred_at'] ?? '-')) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5"><div class="empty-state">Sem transacoes de carteira.</div></td></tr>';
        }

        $content = '
<div class="kpi-grid">
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Disponivel</div><span class="kpi-card__icon"><i class="bi bi-cash"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($wallet['balance_available'] ?? 0), (string) ($wallet['currency'] ?? 'MZN')) . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Pendente</div><span class="kpi-card__icon"><i class="bi bi-hourglass"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($wallet['balance_pending'] ?? 0), (string) ($wallet['currency'] ?? 'MZN')) . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Total</div><span class="kpi-card__icon"><i class="bi bi-wallet2"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($wallet['balance_total'] ?? 0), (string) ($wallet['currency'] ?? 'MZN')) . '</div></article>
</div>
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Movimentos da carteira</h3></div></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Tipo</th><th>Origem</th><th>Valor</th><th>Status</th><th>Data</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';

        return new Response(200, $this->shell->render([
            'role' => 'reseller',
            'active' => 'wallets',
            'title' => 'Wallets',
            'subtitle' => 'Saldo e historico do ledger pessoal.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Finance'],
                ['label' => 'Wallets'],
            ],
            'content' => $content,
        ]));
    }

    public function payouts(Request $request): Response
    {
        $resellerId = $this->userId();
        $data = $this->portal->resellerPayouts($resellerId, 200);
        if ($this->wantsJson($request)) {
            return Response::json($data);
        }

        $summary = (array) ($data['summary'] ?? []);
        $items = (array) ($data['items'] ?? []);
        $rows = '';
        foreach ($items as $item) {
            $settlement = (string) ($item['settlement_status'] ?? 'pending');
            $rows .= '<tr>'
                . '<td>' . $this->safe((string) ($item['order_no'] ?? '-')) . '</td>'
                . '<td>' . $this->money((float) ($item['reseller_earning'] ?? 0), (string) ($item['currency'] ?? 'MZN')) . '</td>'
                . '<td><span class="badge ' . $this->statusBadge($settlement) . '">' . $this->safe($settlement) . '</span></td>'
                . '<td>' . $this->safe((string) ($item['settled_at'] ?? '-')) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4"><div class="empty-state">Sem registos de payout.</div></td></tr>';
        }

        $content = '
<div class="kpi-grid">
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Pendente</div><span class="kpi-card__icon"><i class="bi bi-hourglass-split"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($summary['pending_amount'] ?? 0), 'MZN') . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Settled</div><span class="kpi-card__icon"><i class="bi bi-check2-circle"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($summary['settled_amount'] ?? 0), 'MZN') . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Total</div><span class="kpi-card__icon"><i class="bi bi-cash-stack"></i></span></div><div class="kpi-card__value">' . $this->money((float) ($summary['total_amount'] ?? 0), 'MZN') . '</div></article>
</div>
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Historico de payout</h3></div></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Pedido</th><th>Valor</th><th>Settlement</th><th>Settled at</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';

        return new Response(200, $this->shell->render([
            'role' => 'reseller',
            'active' => 'payouts',
            'title' => 'Payouts',
            'subtitle' => 'Estado da disponibilizacao de comissoes.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Finance'],
                ['label' => 'Payouts'],
            ],
            'content' => $content,
        ]));
    }

    public function apiSettings(Request $request): Response
    {
        $gateways = $this->gateways->listAll();
        $data = [
            'provider' => (string) Env::get('PAYMENT_PROVIDER', 'rozvitech'),
            'enable_callback' => filter_var(Env::get('PAYMENT_ENABLE_CALLBACK', true), FILTER_VALIDATE_BOOL),
            'enable_polling' => filter_var(Env::get('PAYMENT_ENABLE_POLLING', true), FILTER_VALIDATE_BOOL),
            'poll_interval' => (int) Env::get('PAYMENT_POLL_INTERVAL_SECONDS', 90),
        ];
        if ($this->wantsJson($request)) {
            return Response::json([
                'payment' => $data,
                'gateways' => $gateways,
            ]);
        }

        $gatewayRows = '';
        foreach ($gateways as $gateway) {
            $gatewayRows .= '<tr>'
                . '<td>' . $this->safe((string) ($gateway['display_name'] ?? $gateway['code'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($gateway['code'] ?? '-')) . '</td>'
                . '<td><span class="badge ' . ((int) ($gateway['is_enabled'] ?? 0) === 1 ? 'badge--success' : 'badge--warning') . '">' . ((int) ($gateway['is_enabled'] ?? 0) === 1 ? 'Ativo' : 'Inativo') . '</span></td>'
                . '</tr>';
        }
        if ($gatewayRows === '') {
            $gatewayRows = '<tr><td colspan="3"><div class="empty-state">Sem gateways.</div></td></tr>';
        }

        $content = '
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">API Settings</h3><p class="panel__subtitle">Visao de configuracao operacional.</p></div></div>
<ul class="stats-list">
  <li><span class="stats-list__label">Provider</span><span class="stats-list__value">' . $this->safe((string) $data['provider']) . '</span></li>
  <li><span class="stats-list__label">Callback</span><span class="stats-list__value">' . ($data['enable_callback'] ? 'Ativo' : 'Inativo') . '</span></li>
  <li><span class="stats-list__label">Polling</span><span class="stats-list__value">' . ($data['enable_polling'] ? 'Ativo' : 'Inativo') . '</span></li>
  <li><span class="stats-list__label">Poll interval</span><span class="stats-list__value">' . (int) $data['poll_interval'] . 's</span></li>
</ul></section>
<section class="panel"><div class="panel__header"><div><h3 class="panel__title">Gateways disponiveis</h3></div></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Gateway</th><th>Codigo</th><th>Status</th></tr></thead><tbody>' . $gatewayRows . '</tbody></table></div></section>';

        return new Response(200, $this->shell->render([
            'role' => 'reseller',
            'active' => 'api-settings',
            'title' => 'API Settings',
            'subtitle' => 'Parametros da integracao de pagamento.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Settings'],
                ['label' => 'API Settings'],
            ],
            'content' => $content,
        ]));
    }

    private function userId(): int
    {
        $user = $this->session->user();
        return (int) ($user['id'] ?? 0);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function chartScript(array $rows, string $canvasId): string
    {
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '[]';
        }

        return '<script>(function(){const rows=' . $json . ';const c=document.getElementById("' . $this->safe($canvasId) . '");if(!c||!Array.isArray(rows)||rows.length===0)return;const x=c.getContext("2d");if(!x)return;const w=c.width,h=c.height,p={l:44,r:12,t:16,b:36},cw=w-p.l-p.r,ch=h-p.t-p.b;const m=Math.max(1,...rows.map(r=>Math.max(Number(r.confirmed_count||0),Number(r.failed_count||0))));x.clearRect(0,0,w,h);x.strokeStyle="rgba(127,149,188,.26)";for(let i=0;i<=4;i++){const y=p.t+(ch/4)*i;x.beginPath();x.moveTo(p.l,y);x.lineTo(w-p.r,y);x.stroke();}const gw=cw/rows.length,bw=Math.max(7,Math.min(24,gw*.27));rows.forEach((r,i)=>{const cx=p.l+i*gw+gw*.5;const ok=Number(r.confirmed_count||0),ko=Number(r.failed_count||0);const hok=ok/m*ch,hko=ko/m*ch;x.fillStyle="#40c988";x.fillRect(cx-bw-2,p.t+ch-hok,bw,hok);x.fillStyle="#ff7870";x.fillRect(cx+2,p.t+ch-hko,bw,hko);x.fillStyle="#889cc2";x.font="11px Plus Jakarta Sans,sans-serif";x.textAlign="center";x.fillText(String(r.month_label||r.month_key||""),cx,h-10);});})();</script>';
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        return str_contains($accept, 'application/json') || str_starts_with($request->path(), '/api/');
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

    private function safe(string $value): string
    {
        return $this->sanitizer->html($value);
    }
}

