<?php

declare(strict_types=1);

namespace App\Controllers\Reseller;

use App\Services\FunnelService;
use App\Utils\Csrf;
use App\Utils\DashboardShell;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Sanitizer;
use App\Utils\SessionManager;
use Throwable;

final class FunnelController
{
    public function __construct(
        private readonly FunnelService $funnels,
        private readonly DashboardShell $shell,
        private readonly SessionManager $session,
        private readonly Csrf $csrf,
        private readonly Sanitizer $sanitizer,
    ) {
    }

    public function index(Request $request): Response
    {
        $resellerId = $this->userId();
        $items = $this->funnels->listForReseller($resellerId);
        $selectedFunnelId = (int) ($request->query()['funnel_id'] ?? 0);
        if ($selectedFunnelId <= 0 && $items !== []) {
            $selectedFunnelId = (int) ($items[0]['id'] ?? 0);
        }

        $selected = null;
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $selectedFunnelId) {
                $selected = $item;
                break;
            }
        }

        if ($this->wantsJson($request)) {
            return Response::json([
                'funnels' => $items,
                'selected' => $selected,
            ]);
        }

        return new Response(200, $this->renderIndex($items, $selected, $request->query()));
    }

    public function saveFunnel(Request $request): Response
    {
        $resellerId = $this->userId();
        $funnelId = (int) ($request->body()['funnel_id'] ?? 0);

        try {
            if ($funnelId > 0) {
                $saved = $this->funnels->updateFunnel($funnelId, $resellerId, $request->body());
                $message = 'Funil atualizado com sucesso.';
            } else {
                $saved = $this->funnels->createFunnel($resellerId, $request->body());
                $message = 'Funil criado com sucesso.';
            }

            if ($this->wantsJson($request)) {
                return Response::json([
                    'message' => $message,
                    'funnel' => $saved,
                ]);
            }

            return Response::redirect('/reseller/funnels?funnel_id=' . (int) ($saved['id'] ?? 0) . '&success=' . rawurlencode($message));
        } catch (Throwable $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['error' => $exception->getMessage()], 422);
            }

            return Response::redirect('/reseller/funnels?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function saveStep(Request $request): Response
    {
        $resellerId = $this->userId();
        $funnelId = (int) $request->route('id', 0);
        $stepId = (int) ($request->body()['step_id'] ?? 0);

        try {
            $saved = $this->funnels->saveStep(
                funnelId: $funnelId,
                resellerId: $resellerId,
                stepId: $stepId > 0 ? $stepId : null,
                input: $request->body()
            );

            if ($this->wantsJson($request)) {
                return Response::json([
                    'message' => 'Etapa guardada com sucesso.',
                    'step' => $saved,
                ]);
            }

            return Response::redirect('/reseller/funnels?funnel_id=' . $funnelId . '&success=' . rawurlencode('Etapa guardada com sucesso.'));
        } catch (Throwable $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['error' => $exception->getMessage()], 422);
            }

            return Response::redirect('/reseller/funnels?funnel_id=' . $funnelId . '&error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function deleteStep(Request $request): Response
    {
        $resellerId = $this->userId();
        $funnelId = (int) $request->route('id', 0);
        $stepId = (int) $request->route('step_id', 0);

        try {
            $this->funnels->deleteStep($funnelId, $resellerId, $stepId);
            if ($this->wantsJson($request)) {
                return Response::json(['message' => 'Etapa removida com sucesso.']);
            }

            return Response::redirect('/reseller/funnels?funnel_id=' . $funnelId . '&success=' . rawurlencode('Etapa removida com sucesso.'));
        } catch (Throwable $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['error' => $exception->getMessage()], 422);
            }

            return Response::redirect('/reseller/funnels?funnel_id=' . $funnelId . '&error=' . rawurlencode($exception->getMessage()));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed>|null $selected
     * @param array<string, mixed> $query
     */
    private function renderIndex(array $items, ?array $selected, array $query): string
    {
        $csrfToken = $this->safe($this->csrf->token());
        $flash = $this->flashFromQuery($query);

        $funnelRows = '';
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            $name = $this->safe((string) ($item['name'] ?? ''));
            $slug = $this->safe((string) ($item['slug'] ?? ''));
            $status = (string) ($item['status'] ?? 'inactive');
            $stepsCount = is_array($item['steps'] ?? null) ? count((array) $item['steps']) : 0;
            $badge = $status === 'active' ? 'badge--success' : 'badge--warning';

            $funnelRows .= '<tr>'
                . '<td>#' . $id . '</td>'
                . '<td><strong>' . $name . '</strong><div class="form-hint">/f/' . $slug . '</div></td>'
                . '<td>' . $stepsCount . '</td>'
                . '<td><span class="badge ' . $badge . '">' . $this->safe($status) . '</span></td>'
                . '<td><a class="btn btn-outline" href="/reseller/funnels?funnel_id=' . $id . '"><i class="bi bi-pencil-square"></i> Gerir</a></td>'
                . '</tr>';
        }
        if ($funnelRows === '') {
            $funnelRows = '<tr><td colspan="5"><div class="empty-state">Nenhum funil criado ainda.</div></td></tr>';
        }

        $selectedId = (int) ($selected['id'] ?? 0);
        $selectedName = $this->safe((string) ($selected['name'] ?? ''));
        $selectedSlug = $this->safe((string) ($selected['slug'] ?? ''));
        $selectedDescription = $this->safe((string) ($selected['description'] ?? ''));
        $selectedStatus = (string) ($selected['status'] ?? 'active');
        $selectedStatusActive = $selectedStatus === 'active' ? 'selected' : '';
        $selectedStatusInactive = $selectedStatus === 'inactive' ? 'selected' : '';

        $stepsRows = '';
        if ($selected !== null) {
            $steps = (array) ($selected['steps'] ?? []);
            foreach ($steps as $step) {
                $stepId = (int) ($step['id'] ?? 0);
                $stepType = $this->safe((string) ($step['step_type'] ?? ''));
                $title = $this->safe((string) ($step['title'] ?? ''));
                $sequence = (int) ($step['sequence_no'] ?? 0);
                $isActive = (int) ($step['is_active'] ?? 0) === 1;
                $stepBadge = $isActive ? 'badge--success' : 'badge--warning';
                $pageSlug = $this->safe((string) ($step['payment_page_slug'] ?? '-'));

                $stepsRows .= '<tr>'
                    . '<td>#' . $stepId . '</td>'
                    . '<td>' . $stepType . '</td>'
                    . '<td>' . $title . '</td>'
                    . '<td>' . $sequence . '</td>'
                    . '<td>' . $pageSlug . '</td>'
                    . '<td><span class="badge ' . $stepBadge . '">' . ($isActive ? 'active' : 'inactive') . '</span></td>'
                    . '<td><form method="post" action="/reseller/funnels/' . $selectedId . '/steps/' . $stepId . '/delete">'
                    . '<input type="hidden" name="' . $this->safe($this->csrf->tokenName()) . '" value="' . $csrfToken . '">'
                    . '<button class="btn btn-danger" type="submit"><i class="bi bi-trash"></i> Remover</button></form></td>'
                    . '</tr>';
            }
            if ($stepsRows === '') {
                $stepsRows = '<tr><td colspan="7"><div class="empty-state">Sem etapas para este funil.</div></td></tr>';
            }
        }
        if ($stepsRows === '') {
            $stepsRows = '<tr><td colspan="7"><div class="empty-state">Selecione um funil para ver etapas.</div></td></tr>';
        }

        $stepForm = '';
        if ($selectedId > 0) {
            $stepForm = <<<HTML
<form method="post" action="/reseller/funnels/{$selectedId}/steps" class="panel__body">
  <input type="hidden" name="{$this->safe($this->csrf->tokenName())}" value="{$csrfToken}">
  <input type="hidden" name="step_id" value="0">
  <div class="form-group"><label class="label">Tipo</label><select class="select" name="step_type" required><option value="landing">landing</option><option value="checkout">checkout</option><option value="confirmation">confirmation</option><option value="upsell">upsell</option><option value="downsell">downsell</option><option value="thank_you">thank_you</option></select></div>
  <div class="form-group"><label class="label">Titulo</label><input class="input" type="text" name="title" maxlength="190" required></div>
  <div class="form-group"><label class="label">ID da pagina de pagamento</label><input class="input" type="number" min="0" name="payment_page_id" placeholder="Opcional"></div>
  <div class="form-group"><label class="label">Ordem</label><input class="input" type="number" min="1" name="sequence_no" value="1" required></div>
  <div class="form-group"><label class="label">Botao aceitar</label><input class="input" type="text" name="accept_label" maxlength="90" placeholder="Sim, quero esta oferta"></div>
  <div class="form-group"><label class="label">Botao recusar</label><input class="input" type="text" name="reject_label" maxlength="90" placeholder="Nao, obrigado"></div>
  <div class="form-group"><label class="switch"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> Etapa ativa</label></div>
  <button class="btn btn-primary" type="submit"><i class="bi bi-plus-circle"></i> Adicionar etapa</button>
</form>
HTML;
        } else {
            $stepForm = '<div class="empty-state">Crie um funil para adicionar etapas.</div>';
        }

        $content = <<<HTML
{$flash}

<section class="dashboard-hero">
  <div class="dashboard-hero__row">
    <div>
      <h2 class="dashboard-hero__title">Funis de venda com upsell/downsell</h2>
      <p class="dashboard-hero__subtitle">Crie jornadas completas de conversao: landing, checkout, confirmacao, ofertas e pagina final.</p>
      <div class="hero-pills">
        <span class="pill"><i class="bi bi-diagram-3"></i> Funis: {$this->safe((string) count($items))}</span>
        <span class="pill"><i class="bi bi-lightning-charge"></i> Fluxo sem recolher dados novamente</span>
      </div>
    </div>
    <div class="hero-insight">
      <article class="hero-insight__item">
        <div class="hero-insight__label">Funil selecionado</div>
        <div class="hero-insight__value">{$selectedName}</div>
        <div class="hero-insight__meta">Slug: {$selectedSlug}</div>
      </article>
    </div>
  </div>
</section>

<div class="content-grid content-grid--page-form">
  <div class="content-main">
    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Funis cadastrados</h3>
          <p class="panel__subtitle">Gestao central dos fluxos de venda.</p>
        </div>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>ID</th><th>Funil</th><th>Etapas</th><th>Status</th><th>Acao</th></tr></thead>
          <tbody>{$funnelRows}</tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Etapas do funil</h3>
          <p class="panel__subtitle">Ordem operacional das paginas do fluxo.</p>
        </div>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>ID</th><th>Tipo</th><th>Titulo</th><th>Ordem</th><th>Pagina</th><th>Status</th><th>Acao</th></tr></thead>
          <tbody>{$stepsRows}</tbody>
        </table>
      </div>
    </section>
  </div>

  <aside class="content-side">
    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Criar/Editar funil</h3>
          <p class="panel__subtitle">Defina estrutura principal do funil.</p>
        </div>
      </div>
      <form method="post" action="/reseller/funnels" class="panel__body">
        <input type="hidden" name="{$this->safe($this->csrf->tokenName())}" value="{$csrfToken}">
        <input type="hidden" name="funnel_id" value="{$selectedId}">
        <div class="form-group"><label class="label">Nome</label><input class="input" type="text" name="name" value="{$selectedName}" maxlength="180" required></div>
        <div class="form-group"><label class="label">Slug</label><input class="input" type="text" name="slug" value="{$selectedSlug}" maxlength="190" placeholder="meu-funil"></div>
        <div class="form-group"><label class="label">Status</label><select class="select" name="status"><option value="active" {$selectedStatusActive}>active</option><option value="inactive" {$selectedStatusInactive}>inactive</option></select></div>
        <div class="form-group"><label class="label">Descricao</label><textarea class="textarea" name="description">{$selectedDescription}</textarea></div>
        <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Guardar funil</button>
      </form>
    </section>

    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Adicionar etapa</h3>
          <p class="panel__subtitle">Conecte pagina de pagamento quando necessario.</p>
        </div>
      </div>
      {$stepForm}
    </section>
  </aside>
</div>
HTML;

        return $this->shell->render([
            'role' => 'reseller',
            'active' => 'funnels',
            'title' => 'Funis',
            'subtitle' => 'Gestao de funis, upsell e downsell.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Funis'],
            ],
            'content' => $content,
        ]);
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

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        return str_contains($accept, 'application/json') || str_starts_with($request->path(), '/api/');
    }

    private function userId(): int
    {
        $user = $this->session->user();
        $id = (int) ($user['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('Sessao invalida.');
        }

        return $id;
    }

    private function safe(string $value): string
    {
        return $this->sanitizer->html($value);
    }
}
