<?php

declare(strict_types=1);

namespace App\Controllers\Reseller;

use App\Services\PaymentPageService;
use App\Services\ProductService;
use App\Services\Payments\GatewayCatalogService;
use App\Utils\Csrf;
use App\Utils\DashboardShell;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Sanitizer;
use App\Utils\SessionManager;
use Throwable;

final class PaymentPageController
{
    public function __construct(
        private readonly PaymentPageService $pages,
        private readonly ProductService $products,
        private readonly GatewayCatalogService $gateways,
        private readonly DashboardShell $shell,
        private readonly SessionManager $session,
        private readonly Csrf $csrf,
        private readonly Sanitizer $sanitizer,
    ) {
    }

    public function index(Request $request): Response
    {
        $resellerId = $this->currentUserId();
        $items = $this->pages->listByReseller($resellerId);

        if ($this->wantsJson($request)) {
            return Response::json([
                'payment_pages' => $items,
                'total' => count($items),
            ]);
        }

        $csrfToken = $this->csrf->token();
        $rows = '';
        $total = count($items);
        $active = 0;
        $views = 0;

        foreach ($items as $item) {
            $id = (int) $item['id'];
            $status = (string) ($item['status'] ?? 'inactive');
            $active += $status === 'active' ? 1 : 0;
            $views += (int) ($item['view_count'] ?? 0);

            $rows .= '<tr>'
                . '<td>#' . $id . '</td>'
                . '<td><strong>' . $this->safe((string) ($item['title'] ?? '')) . '</strong><div class="form-hint">/p/' . $this->safe((string) ($item['slug'] ?? '')) . '</div></td>'
                . '<td>' . $this->safe((string) ($item['product_name'] ?? '-')) . '</td>'
                . '<td>' . $this->safe((string) ($item['currency'] ?? 'MZN')) . ' ' . number_format((float) ($item['product_price'] ?? 0), 2, '.', ',') . '</td>'
                . '<td><span class="badge ' . ($status === 'active' ? 'badge--success' : 'badge--danger') . '">' . $this->safe($status) . '</span></td>'
                . '<td><div class="inline-actions">'
                . '<a class="btn btn-outline" href="/reseller/payment-pages/' . $id . '/edit"><i class="bi bi-pencil-square"></i> Editar</a>'
                . '<a class="btn btn-outline" href="/p/' . $this->safe((string) ($item['slug'] ?? '')) . '" target="_blank"><i class="bi bi-box-arrow-up-right"></i> Abrir</a>'
                . '<form method="post" action="/reseller/payment-pages/' . $id . '/toggle"><input type="hidden" name="_csrf" value="' . $this->safe($csrfToken) . '"><button class="btn btn-outline" type="submit"><i class="bi bi-arrow-repeat"></i> Alternar</button></form>'
                . '</div></td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6"><div class="empty-state">Nenhuma pagina de pagamento criada.</div></td></tr>';
        }

        $content = '
<div class="kpi-grid">
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Paginas</div><span class="kpi-card__icon"><i class="bi bi-files"></i></span></div><div class="kpi-card__value">' . $total . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Ativas</div><span class="kpi-card__icon"><i class="bi bi-check2-circle"></i></span></div><div class="kpi-card__value">' . $active . '</div></article>
  <article class="kpi-card"><div class="kpi-card__head"><div class="kpi-card__label">Views</div><span class="kpi-card__icon"><i class="bi bi-eye"></i></span></div><div class="kpi-card__value">' . $views . '</div></article>
</div>
<section class="panel">
  <div class="panel__header"><div><h3 class="panel__title">Paginas de pagamento</h3><p class="panel__subtitle">Checkout premium com metodos e campos padrao.</p></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Pagina</th><th>Produto</th><th>Preco</th><th>Status</th><th>Acoes</th></tr></thead><tbody>' . $rows . '</tbody></table></div>
</section>';

        return new Response(200, $this->shell->render([
            'role' => 'reseller',
            'active' => 'sales-pages',
            'title' => 'Paginas de Pagamento',
            'subtitle' => 'Crie checkouts profissionais com multiplos gateways.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Pagamentos'],
            ],
            'toolbar' => '<a class="btn btn-primary" href="/reseller/payment-pages/create"><i class="bi bi-plus-circle"></i> Nova pagina</a><a class="btn btn-outline" href="/reseller/products"><i class="bi bi-box-seam"></i> Produtos</a>',
            'content' => $content,
        ]));
    }

    public function show(Request $request): Response
    {
        $resellerId = $this->currentUserId();
        $id = (int) $request->route('id', 0);
        $page = $this->pages->findByIdForReseller($id, $resellerId);
        if ($page === null) {
            return Response::json(['error' => 'Pagina nao encontrada.'], 404);
        }

        return Response::json(['payment_page' => $page]);
    }

    public function createForm(Request $request): Response
    {
        return new Response(200, $this->renderForm(
            title: 'Criar Pagina de Pagamento',
            action: '/reseller/payment-pages',
            csrfToken: $this->csrf->token(),
            page: null
        ));
    }

    public function editForm(Request $request): Response
    {
        $resellerId = $this->currentUserId();
        $id = (int) $request->route('id', 0);
        $page = $this->pages->findByIdForReseller($id, $resellerId);
        if ($page === null) {
            return new Response(404, $this->renderErrorPage('Pagina nao encontrada.', '/reseller/payment-pages'));
        }

        return new Response(200, $this->renderForm(
            title: 'Editar Pagina de Pagamento',
            action: '/reseller/payment-pages/' . $id . '/update',
            csrfToken: $this->csrf->token(),
            page: $page
        ));
    }

    public function store(Request $request): Response
    {
        return $this->persist($request, null);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->route('id', 0);
        if ($id <= 0) {
            return Response::json(['error' => 'ID invalido.'], 422);
        }

        return $this->persist($request, $id);
    }

    public function toggleStatus(Request $request): Response
    {
        $resellerId = $this->currentUserId();
        $id = (int) $request->route('id', 0);
        if ($id <= 0) {
            return Response::json(['error' => 'ID invalido.'], 422);
        }

        try {
            $updated = $this->pages->toggleStatus($id, $resellerId, $this->context($request));
            if ($this->wantsJson($request)) {
                return Response::json(['message' => 'Estado da pagina atualizado.', 'payment_page' => $updated]);
            }

            return Response::redirect('/reseller/payment-pages');
        } catch (Throwable $exception) {
            return $this->errorResponse($request, $exception->getMessage(), 422);
        }
    }

    private function persist(Request $request, ?int $pageId): Response
    {
        $resellerId = $this->currentUserId();
        $input = $request->body();

        try {
            if ($pageId === null && strtolower(trim((string) ($input['product_mode'] ?? 'existing'))) === 'new') {
                $product = $this->createInlineProduct($resellerId, $input, $request->files(), $this->context($request));
                $input['product_id'] = (int) ($product['id'] ?? 0);
            }

            $page = $pageId === null
                ? $this->pages->createPage($resellerId, $input, $this->context($request))
                : $this->pages->updatePage($pageId, $resellerId, $input, $this->context($request));

            if ($this->wantsJson($request)) {
                return Response::json([
                    'message' => $pageId === null ? 'Pagina de pagamento criada com sucesso.' : 'Pagina de pagamento atualizada com sucesso.',
                    'payment_page' => $page,
                ], $pageId === null ? 201 : 200);
            }

            return Response::redirect('/reseller/payment-pages');
        } catch (Throwable $exception) {
            return $this->errorResponse($request, $exception->getMessage(), 422);
        }
    }

    /**
     * @param array<string, mixed>|null $page
     */
    private function renderForm(string $title, string $action, string $csrfToken, ?array $page): string
    {
        $resellerId = $this->currentUserId();
        $products = $this->products->listByReseller($resellerId);
        $selectedProductId = (int) ($page['product_id'] ?? 0);
        $options = '';
        foreach ($products as $product) {
            $productId = (int) $product['id'];
            $selected = $productId === $selectedProductId ? 'selected' : '';
            $name = $this->safe((string) $product['name']);
            $price = number_format((float) $product['price'], 2, '.', ',');
            $currency = $this->safe((string) $product['currency']);
            $options .= "<option value=\"{$productId}\" {$selected}>{$name} ({$currency} {$price})</option>";
        }
        if ($options === '') {
            $options = '<option value="">Sem produtos ativos</option>';
        }

        $methodData = $this->gateways->listCheckoutMethods($page ?? [
            'allow_mpesa' => 1,
            'allow_emola' => 0,
            'allow_visa' => 0,
            'allow_paypal' => 0,
        ]);
        $newProductDisabled = $page !== null ? 'disabled' : '';
        $newProductHint = $page !== null
            ? '<div class="form-hint">Para trocar produto, selecione um existente na lista.</div>'
            : '';
        $methodHtml = '';
        foreach ($methodData as $method) {
            $code = $this->safe((string) $method['code']);
            $titleLabel = $this->safe((string) $method['display_name']);
            $icon = $this->safe((string) $method['icon_class']);
            $checked = ($method['page_allowed'] ?? false) ? 'checked' : '';
            $disabled = (($method['is_enabled'] ?? false) && ($method['is_configured'] ?? false)) ? '' : 'disabled';
            $hint = $this->safe((string) (($method['unavailable_reason'] ?? '') !== '' ? $method['unavailable_reason'] : 'Disponivel'));
            $methodHtml .= '<label class="gateway-method"><input type="hidden" name="allow_' . $code . '" value="0"><input type="checkbox" name="allow_' . $code . '" value="1" ' . $checked . ' ' . $disabled . '><span><i class="bi ' . $icon . '"></i> ' . $titleLabel . '</span><small>' . $hint . '</small></label>';
        }

        $form = '
<section class="panel">
  <div class="panel__header"><div><h3 class="panel__title">' . $this->safe($title) . '</h3><p class="panel__subtitle">Checkout sem JSON manual: campos padrao e gateways visuais.</p></div></div>
  <form method="post" action="' . $this->safe($action) . '" enctype="multipart/form-data" class="panel__body">
    <input type="hidden" name="_csrf" value="' . $this->safe($csrfToken) . '">
    <div class="form-grid">
      <div class="form-group form-group--full"><label class="label">Produto</label><div class="inline-actions"><label class="switch"><input type="radio" name="product_mode" value="existing" checked> Usar existente</label><label class="switch"><input type="radio" name="product_mode" value="new" ' . $newProductDisabled . '> Criar novo</label></div></div>
      ' . $newProductHint . '
      <div class="form-group form-group--full" data-existing><label class="label">Produto associado</label><select class="select" name="product_id">' . $options . '</select></div>
      <div class="form-group form-group--full" data-new style="display:none;"><label class="label">Nome do novo produto</label><input class="input" type="text" name="new_product_name" maxlength="180"></div>
      <div class="form-group" data-new style="display:none;"><label class="label">Preco</label><input class="input" type="number" name="new_product_price" min="0.01" step="0.01"></div>
      <div class="form-group" data-new style="display:none;"><label class="label">Moeda</label><input class="input" type="text" name="new_product_currency" value="MZN"></div>
      <div class="form-group" data-new style="display:none;"><label class="label">Tipo</label><select class="select" name="new_product_type" id="new_product_type"><option value="digital">Digital</option><option value="physical">Fisico</option></select></div>
      <div class="form-group" data-new style="display:none;"><label class="label">Entrega</label><select class="select" name="new_product_delivery_type" id="new_product_delivery_type"><option value="external_link">Link externo</option><option value="file_upload">Ficheiro interno</option><option value="none">Sem entrega digital</option></select></div>
      <div class="form-group form-group--full" data-new data-external style="display:none;"><label class="label">Link do produto</label><input class="input" type="text" name="new_product_external_url"></div>
      <div class="form-group form-group--full" data-new data-file style="display:none;"><label class="label">Ficheiro do produto</label><input class="input" type="file" name="new_product_digital_file"></div>
      <div class="form-group form-group--full" data-new style="display:none;"><label class="label">Imagem do produto</label><input class="input" type="file" name="new_product_image" accept=".jpg,.jpeg,.png,.webp"></div>
      <div class="form-group form-group--full"><label class="label">Titulo da pagina</label><input class="input" type="text" name="title" value="' . $this->safe((string) ($page['title'] ?? '')) . '" required></div>
      <div class="form-group"><label class="label">Slug</label><input class="input" type="text" name="slug" value="' . $this->safe((string) ($page['slug'] ?? '')) . '"></div>
      <div class="form-group"><label class="label">Estado</label><select class="select" name="status"><option value="active" ' . ((string) ($page['status'] ?? 'active') === 'active' ? 'selected' : '') . '>Ativo</option><option value="inactive" ' . ((string) ($page['status'] ?? 'active') === 'inactive' ? 'selected' : '') . '>Inativo</option></select></div>
      <div class="form-group form-group--full"><label class="label">Descricao</label><textarea class="textarea" name="description">' . $this->safe((string) ($page['description'] ?? '')) . '</textarea></div>
    </div>
    <section class="panel panel--nested" style="margin-top:14px;"><div class="panel__header"><div><h4 class="panel__title">Campos do cliente</h4></div></div><div class="panel__body"><div class="grid-two">
      <label class="switch"><input type="hidden" name="require_customer_name" value="0"><input type="checkbox" name="require_customer_name" value="1" ' . ((int) ($page['require_customer_name'] ?? 1) === 1 ? 'checked' : '') . '> Nome completo</label>
      <label class="switch"><input type="hidden" name="require_customer_email" value="0"><input type="checkbox" name="require_customer_email" value="1" ' . ((int) ($page['require_customer_email'] ?? 1) === 1 ? 'checked' : '') . '> Email</label>
      <label class="switch"><input type="hidden" name="collect_country" value="0"><input type="checkbox" name="collect_country" value="1" ' . ((int) ($page['collect_country'] ?? 1) === 1 ? 'checked' : '') . '> Pais</label>
      <label class="switch"><input type="hidden" name="collect_city" value="0"><input type="checkbox" name="collect_city" value="1" ' . ((int) ($page['collect_city'] ?? 1) === 1 ? 'checked' : '') . '> Cidade</label>
      <label class="switch"><input type="hidden" name="collect_address" value="0"><input type="checkbox" name="collect_address" value="1" ' . ((int) ($page['collect_address'] ?? 1) === 1 ? 'checked' : '') . '> Endereco</label>
      <label class="switch"><input type="hidden" name="collect_notes" value="0"><input type="checkbox" name="collect_notes" value="1" ' . ((int) ($page['collect_notes'] ?? 1) === 1 ? 'checked' : '') . '> Observacoes</label>
    </div></div></section>
    <section class="panel panel--nested" style="margin-top:14px;"><div class="panel__header"><div><h4 class="panel__title">Metodos de pagamento</h4></div></div><div class="panel__body"><div class="gateway-methods">' . $methodHtml . '</div></div></section>
    <div class="inline-actions" style="margin-top:14px;"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Guardar pagina</button><a class="btn btn-outline" href="/reseller/payment-pages"><i class="bi bi-arrow-left"></i> Voltar</a></div>
  </form>
</section>';

        return $this->shell->render([
            'role' => 'reseller',
            'active' => 'sales-pages',
            'title' => $title,
            'subtitle' => 'Checkout moderno com campos padrao e gateways.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Pagamentos', 'url' => '/reseller/payment-pages'],
                ['label' => $title],
            ],
            'content' => $form,
            'extraStyles' => '<style>.gateway-methods{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}.gateway-method{display:grid;gap:4px;padding:10px;border:1px solid var(--border-color);border-radius:10px;background:var(--card-bg)}.gateway-method span{font-weight:600;display:flex;gap:8px;align-items:center}.gateway-method small{color:var(--text-secondary)}.gateway-method input[type="checkbox"]{margin-right:6px}</style>',
            'extraScripts' => '<script>(function(){const radios=document.querySelectorAll(\'input[name="product_mode"]\');const existing=document.querySelector(\'[data-existing]\');const blocks=document.querySelectorAll(\'[data-new]\');const pt=document.getElementById(\'new_product_type\');const dt=document.getElementById(\'new_product_delivery_type\');const ex=document.querySelector(\'[data-external]\');const fi=document.querySelector(\'[data-file]\');function mode(){let m=\'existing\';radios.forEach(function(r){if(r.checked){m=r.value;}});if(existing){existing.style.display=m===\'existing\'?\'grid\':\'none\';}blocks.forEach(function(b){b.style.display=m===\'new\'?\'grid\':\'none\';});delivery();}function delivery(){if(!pt||!dt){return;}const m=Array.from(radios).some(function(r){return r.checked&&r.value===\'new\';});if(!m){if(ex){ex.style.display=\'none\';}if(fi){fi.style.display=\'none\';}return;}if(pt.value===\'physical\'){dt.value=\'none\';dt.disabled=true;if(ex){ex.style.display=\'none\';}if(fi){fi.style.display=\'none\';}return;}dt.disabled=false;if(ex){ex.style.display=dt.value===\'external_link\'?\'grid\':\'none\';}if(fi){fi.style.display=dt.value===\'file_upload\'?\'grid\':\'none\';}}radios.forEach(function(r){r.addEventListener(\'change\',mode);});if(pt){pt.addEventListener(\'change\',delivery);}if(dt){dt.addEventListener(\'change\',delivery);}mode();})();</script>',
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function createInlineProduct(int $resellerId, array $input, array $files, array $context): array
    {
        $name = $this->sanitizer->string((string) ($input['new_product_name'] ?? ''), 180);
        $price = (float) ($input['new_product_price'] ?? 0);
        if ($name === '' || $price <= 0) {
            throw new \RuntimeException('Para criar produto rapido informe nome e preco validos.');
        }

        $productType = strtolower($this->sanitizer->string((string) ($input['new_product_type'] ?? 'digital'), 20));
        $deliveryType = $this->sanitizer->string((string) ($input['new_product_delivery_type'] ?? 'external_link'), 20);
        if ($productType === 'physical') {
            $deliveryType = 'none';
        }

        return $this->products->createProduct(
            resellerId: $resellerId,
            input: [
                'name' => $name,
                'description' => $input['new_product_description'] ?? '',
                'price' => $price,
                'currency' => $input['new_product_currency'] ?? 'MZN',
                'product_type' => $productType,
                'delivery_type' => $deliveryType,
                'external_url' => $input['new_product_external_url'] ?? '',
                'is_active' => 1,
            ],
            files: [
                'image' => $files['new_product_image'] ?? null,
                'digital_file' => $files['new_product_digital_file'] ?? null,
            ],
            context: $context
        );
    }

    private function currentUserId(): int
    {
        $user = $this->session->user();
        $id = (int) ($user['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('Sessao invalida.');
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Request $request): array
    {
        $user = $this->session->user();
        return [
            'actor_user_id' => $user['id'] ?? null,
            'actor_role' => $user['role'] ?? 'reseller',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => (string) $request->header('X-Request-Id', ''),
        ];
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        return str_contains($accept, 'application/json') || str_starts_with($request->path(), '/api/');
    }

    private function errorResponse(Request $request, string $message, int $status): Response
    {
        if ($this->wantsJson($request)) {
            return Response::json(['error' => $message], $status);
        }

        return new Response($status, $this->renderErrorPage($message, '/reseller/payment-pages'));
    }

    private function renderErrorPage(string $message, string $backUrl): string
    {
        $content = '<section class="panel"><div class="alert alert--error">' . $this->safe($message) . '</div></section>';

        return $this->shell->render([
            'role' => 'reseller',
            'active' => 'sales-pages',
            'title' => 'Erro em paginas de pagamento',
            'subtitle' => 'Corrija os dados informados e tente novamente.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Pagamentos', 'url' => '/reseller/payment-pages'],
                ['label' => 'Erro'],
            ],
            'toolbar' => '<a class="btn btn-outline" href="' . $this->safe($backUrl) . '"><i class="bi bi-arrow-left"></i> Voltar</a>',
            'content' => $content,
        ]);
    }

    private function safe(string $value): string
    {
        return $this->sanitizer->html($value);
    }
}
