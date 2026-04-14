<?php

declare(strict_types=1);

namespace App\Controllers\Reseller;

use App\Services\ProductService;
use App\Utils\Csrf;
use App\Utils\DashboardShell;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Sanitizer;
use App\Utils\SessionManager;
use Throwable;

final class ProductController
{
    public function __construct(
        private readonly ProductService $products,
        private readonly DashboardShell $shell,
        private readonly SessionManager $session,
        private readonly Csrf $csrf,
        private readonly Sanitizer $sanitizer,
    ) {
    }

    public function index(Request $request): Response
    {
        $resellerId = $this->currentUserId();
        $items = $this->products->listByReseller($resellerId);

        if ($this->wantsJson($request)) {
            return Response::json([
                'products' => $items,
                'total' => count($items),
            ]);
        }

        $csrfToken = $this->csrf->token();
        $rows = '';

        $total = count($items);
        $active = 0;
        $external = 0;
        $upload = 0;

        foreach ($items as $item) {
            $id = (int) $item['id'];
            $name = $this->safe((string) $item['name']);
            $price = number_format((float) $item['price'], 2, '.', ',');
            $currency = $this->safe((string) $item['currency']);
            $delivery = (string) $item['delivery_type'];
            $productType = (string) ($item['product_type'] ?? 'digital');
            if ($productType === 'physical') {
                $deliveryLabel = 'Produto fisico';
            } else {
                $deliveryLabel = $delivery === 'file_upload' ? 'Ficheiro interno' : 'Link externo';
            }
            $status = (int) $item['is_active'] === 1 ? 'Ativo' : 'Inativo';
            $statusClass = (int) $item['is_active'] === 1 ? 'badge--success' : 'badge--danger';

            if ((int) $item['is_active'] === 1) {
                $active++;
            }

            if ($delivery === 'file_upload') {
                $upload++;
            } else {
                $external++;
            }

            $rows .= <<<HTML
<tr>
  <td>#{$id}</td>
  <td>
    <strong>{$name}</strong>
    <div class="form-hint">{$this->safe($this->truncate((string) ($item['description'] ?? ''), 110))}</div>
  </td>
  <td>{$currency} {$price}</td>
  <td><span class="badge badge--muted">{$this->safe($deliveryLabel)}</span></td>
  <td><span class="badge {$statusClass}">{$status}</span></td>
  <td>
    <div class="inline-actions">
      <a class="btn btn-outline" href="/reseller/products/{$id}/edit"><i class="bi bi-pencil-square"></i> Editar</a>
      <form method="post" action="/reseller/products/{$id}/toggle">
        <input type="hidden" name="_csrf" value="{$this->safe($csrfToken)}">
        <button class="btn btn-outline" type="submit"><i class="bi bi-arrow-repeat"></i> Alternar</button>
      </form>
    </div>
  </td>
</tr>
HTML;
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6"><div class="empty-state">Ainda nao existe nenhum produto criado.</div></td></tr>';
        }

        $inactive = max(0, $total - $active);

        $content = <<<HTML
<section class="dashboard-hero">
  <div class="dashboard-hero__row">
    <div>
      <h2 class="dashboard-hero__title">Catalogo digital com foco em conversao</h2>
      <p class="dashboard-hero__subtitle">
        Organize os seus produtos, escolha o formato de entrega e mantenha a operacao pronta para checkout.
      </p>
      <div class="hero-pills">
        <span class="pill"><i class="bi bi-box-seam"></i> Produtos totais: {$total}</span>
        <span class="pill"><i class="bi bi-check2-circle"></i> Ativos: {$active}</span>
        <span class="pill"><i class="bi bi-file-earmark-arrow-up"></i> Upload interno: {$upload}</span>
      </div>
    </div>

    <div class="hero-insight">
      <article class="hero-insight__item">
        <div class="hero-insight__label">Entrega por link</div>
        <div class="hero-insight__value">{$external}</div>
        <div class="hero-insight__meta">Produtos com redirecionamento externo</div>
      </article>
      <article class="hero-insight__item">
        <div class="hero-insight__label">Produtos inativos</div>
        <div class="hero-insight__value">{$inactive}</div>
        <div class="hero-insight__meta">Nao aparecem em paginas publicas</div>
      </article>
    </div>
  </div>
</section>

<div class="kpi-grid">
  <article class="kpi-card">
    <div class="kpi-card__head"><div class="kpi-card__label">Total de produtos</div><span class="kpi-card__icon"><i class="bi bi-grid"></i></span></div>
    <div class="kpi-card__value">{$total}</div>
    <div class="kpi-card__meta">Itens cadastrados no catalogo</div>
  </article>

  <article class="kpi-card">
    <div class="kpi-card__head"><div class="kpi-card__label">Produtos ativos</div><span class="kpi-card__icon"><i class="bi bi-toggle-on"></i></span></div>
    <div class="kpi-card__value">{$active}</div>
    <div class="kpi-card__meta">Aptos para venda imediata</div>
  </article>

  <article class="kpi-card">
    <div class="kpi-card__head"><div class="kpi-card__label">Link externo</div><span class="kpi-card__icon"><i class="bi bi-link-45deg"></i></span></div>
    <div class="kpi-card__value">{$external}</div>
    <div class="kpi-card__meta">Entrega via URL segura</div>
  </article>

  <article class="kpi-card">
    <div class="kpi-card__head"><div class="kpi-card__label">Upload interno</div><span class="kpi-card__icon"><i class="bi bi-cloud-arrow-up"></i></span></div>
    <div class="kpi-card__value">{$upload}</div>
    <div class="kpi-card__meta">Entrega por ficheiro privado</div>
  </article>
</div>

<section class="panel">
  <div class="panel__header">
    <div>
      <h3 class="panel__title">Produtos digitais</h3>
      <p class="panel__subtitle">Gestao completa do catalogo de vendas e status de publicacao.</p>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Produto</th>
          <th>Preco</th>
          <th>Entrega</th>
          <th>Status</th>
          <th>Acoes</th>
        </tr>
      </thead>
      <tbody>{$rows}</tbody>
    </table>
  </div>
</section>
HTML;

        return new Response(200, $this->shell->render([
            'role' => 'reseller',
            'active' => 'products',
            'title' => 'Produtos',
            'subtitle' => 'Estruture e publique produtos digitais com entrega segura.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Produtos'],
            ],
            'toolbar' => '<a class="btn btn-primary" href="/reseller/products/create"><i class="bi bi-plus-circle"></i> Novo produto</a><a class="btn btn-outline" href="/reseller/payment-pages"><i class="bi bi-link-45deg"></i> Paginas</a>',
            'content' => $content,
        ]));
    }

    public function show(Request $request): Response
    {
        $resellerId = $this->currentUserId();
        $id = (int) $request->route('id', 0);
        if ($id <= 0) {
            return Response::json(['error' => 'ID invalido.'], 422);
        }

        $product = $this->products->findByIdForReseller($id, $resellerId);
        if ($product === null) {
            return Response::json(['error' => 'Produto nao encontrado.'], 404);
        }

        return Response::json(['product' => $product]);
    }

    public function createForm(Request $request): Response
    {
        return new Response(200, $this->renderForm(
            action: '/reseller/products',
            title: 'Criar Produto',
            csrfToken: $this->csrf->token(),
            product: null
        ));
    }

    public function editForm(Request $request): Response
    {
        $resellerId = $this->currentUserId();
        $id = (int) $request->route('id', 0);
        $product = $this->products->findByIdForReseller($id, $resellerId);
        if ($product === null) {
            return new Response(404, $this->renderErrorPage('Produto nao encontrado.', '/reseller/products'));
        }

        return new Response(200, $this->renderForm(
            action: '/reseller/products/' . $id . '/update',
            title: 'Editar Produto',
            csrfToken: $this->csrf->token(),
            product: $product
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
            $updated = $this->products->toggleStatus($id, $resellerId, $this->context($request));
            if ($this->wantsJson($request)) {
                return Response::json([
                    'message' => 'Estado do produto atualizado.',
                    'product' => $updated,
                ]);
            }

            return Response::redirect('/reseller/products');
        } catch (Throwable $exception) {
            return $this->errorResponse($request, $exception->getMessage(), 422);
        }
    }

    private function persist(Request $request, ?int $productId): Response
    {
        $resellerId = $this->currentUserId();

        try {
            if ($productId === null) {
                $product = $this->products->createProduct(
                    resellerId: $resellerId,
                    input: $request->body(),
                    files: $request->files(),
                    context: $this->context($request)
                );
                $message = 'Produto criado com sucesso.';
            } else {
                $product = $this->products->updateProduct(
                    productId: $productId,
                    resellerId: $resellerId,
                    input: $request->body(),
                    files: $request->files(),
                    context: $this->context($request)
                );
                $message = 'Produto atualizado com sucesso.';
            }

            if ($this->wantsJson($request)) {
                return Response::json([
                    'message' => $message,
                    'product' => $product,
                ], $productId === null ? 201 : 200);
            }

            return Response::redirect('/reseller/products');
        } catch (Throwable $exception) {
            return $this->errorResponse($request, $exception->getMessage(), 422);
        }
    }

    /**
     * @param array<string, mixed>|null $product
     */
    private function renderForm(string $action, string $title, string $csrfToken, ?array $product): string
    {
        $name = $this->safe((string) ($product['name'] ?? ''));
        $description = $this->safe((string) ($product['description'] ?? ''));
        $price = $this->safe((string) ($product['price'] ?? ''));
        $currency = $this->safe((string) ($product['currency'] ?? 'MZN'));
        $deliveryType = (string) ($product['delivery_type'] ?? 'external_link');
        $productType = (string) ($product['product_type'] ?? 'digital');
        $externalUrl = $this->safe((string) ($product['external_url'] ?? ''));
        $isActive = (int) ($product['is_active'] ?? 1) === 1 ? 'checked' : '';
        $hasImage = (string) ($product['image_path'] ?? '') !== '' ? 'Sim' : 'Nao';
        $hasFile = (string) ($product['file_path'] ?? '') !== '' ? 'Sim' : 'Nao';

        $selectedExternal = $deliveryType === 'external_link' ? 'selected' : '';
        $selectedFile = $deliveryType === 'file_upload' ? 'selected' : '';
        $selectedNone = $deliveryType === 'none' ? 'selected' : '';
        $selectedDigital = $productType === 'digital' ? 'selected' : '';
        $selectedPhysical = $productType === 'physical' ? 'selected' : '';

        $content = <<<HTML
<section class="dashboard-hero">
  <div class="dashboard-hero__row">
    <div>
      <h2 class="dashboard-hero__title">{$this->safe($title)}</h2>
      <p class="dashboard-hero__subtitle">Configure o produto digital com precificacao, formato de entrega e disponibilidade comercial.</p>
      <div class="hero-pills">
        <span class="pill"><i class="bi bi-image"></i> Imagem atual: {$hasImage}</span>
        <span class="pill"><i class="bi bi-file-earmark-lock"></i> Ficheiro interno: {$hasFile}</span>
      </div>
    </div>
    <div class="hero-insight">
      <article class="hero-insight__item">
        <div class="hero-insight__label">Checklist</div>
        <div class="hero-insight__meta">1. Defina nome e preco</div>
        <div class="hero-insight__meta">2. Escolha tipo de entrega</div>
        <div class="hero-insight__meta">3. Salve e publique pagina</div>
      </article>
    </div>
  </div>
</section>

<div class="content-grid content-grid--product-form">
  <div class="content-main">
    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Dados do produto</h3>
          <p class="panel__subtitle">Campos obrigatorios para publicar no checkout.</p>
        </div>
      </div>

      <form method="post" action="{$this->safe($action)}" enctype="multipart/form-data" class="panel__body">
        <input type="hidden" name="_csrf" value="{$this->safe($csrfToken)}">

        <div class="form-grid">
          <div class="form-group form-group--full">
            <label class="label">Nome do produto</label>
            <input class="input" type="text" name="name" value="{$name}" maxlength="180" required>
          </div>

          <div class="form-group form-group--full">
            <label class="label">Descricao</label>
            <textarea class="textarea" name="description" maxlength="5000">{$description}</textarea>
          </div>

          <div class="form-group">
            <label class="label">Preco</label>
            <input class="input" type="number" name="price" value="{$price}" step="0.01" min="0.01" required>
          </div>

          <div class="form-group">
            <label class="label">Moeda</label>
            <input class="input" type="text" name="currency" value="{$currency}" maxlength="3" required>
          </div>

          <div class="form-group form-group--full">
            <label class="label">Tipo de produto</label>
            <select class="select" name="product_type" id="product_type" required>
              <option value="digital" {$selectedDigital}>Digital</option>
              <option value="physical" {$selectedPhysical}>Fisico</option>
            </select>
            <div class="form-hint">Produtos fisicos nao liberam download automatico.</div>
          </div>

          <div class="form-group form-group--full">
            <label class="label">Tipo de entrega</label>
            <select class="select" name="delivery_type" id="delivery_type" required>
              <option value="external_link" {$selectedExternal}>Link externo</option>
              <option value="file_upload" {$selectedFile}>Upload interno</option>
              <option value="none" {$selectedNone}>Sem entrega digital</option>
            </select>
          </div>

          <div class="form-group form-group--full" id="external_block">
            <label class="label">Link externo (Google Drive, etc)</label>
            <input class="input" type="text" name="external_url" value="{$externalUrl}" placeholder="https://...">
          </div>

          <div class="form-group form-group--full" id="file_block">
            <label class="label">Ficheiro interno do produto</label>
            <input class="input" type="file" name="digital_file">
          </div>

          <div class="form-group">
            <label class="label">Imagem de capa</label>
            <input class="input" type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
          </div>

          <div class="form-group" style="align-content:end;">
            <label class="switch"><input type="checkbox" name="is_active" value="1" {$isActive}> Produto ativo</label>
          </div>
        </div>

        <div class="inline-actions" style="margin-top:14px;">
          <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Guardar produto</button>
          <a class="btn btn-outline" href="/reseller/products"><i class="bi bi-arrow-left"></i> Voltar</a>
        </div>
      </form>
    </section>
  </div>

  <aside class="content-side">
    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Boas praticas</h3>
          <p class="panel__subtitle">Melhore a conversao da sua pagina de venda.</p>
        </div>
      </div>

      <ul class="stats-list">
        <li><span class="stats-list__label">Titulo claro</span><span class="stats-list__value">Obrigatorio</span></li>
        <li><span class="stats-list__label">Imagem otimizada</span><span class="stats-list__value">.jpg/.png</span></li>
        <li><span class="stats-list__label">Entrega confiavel</span><span class="stats-list__value">Link ou upload</span></li>
        <li><span class="stats-list__label">Estado de publicacao</span><span class="stats-list__value">Ativo/Inativo</span></li>
      </ul>

      <div class="alert" style="margin-top:12px;">Extensoes recomendadas para entrega: .pdf, .zip, .docx, .mp4.</div>
    </section>
  </aside>
</div>
HTML;

        $script = <<<HTML
<script>
(function () {
  const productTypeSelect = document.getElementById('product_type');
  const select = document.getElementById('delivery_type');
  const externalBlock = document.getElementById('external_block');
  const fileBlock = document.getElementById('file_block');
  if (!productTypeSelect || !select || !externalBlock || !fileBlock) return;

  function sync() {
    const isPhysical = productTypeSelect.value === 'physical';
    if (isPhysical) {
      select.value = 'none';
      select.disabled = true;
      externalBlock.style.display = 'none';
      fileBlock.style.display = 'none';
      return;
    }

    select.disabled = false;
    const external = select.value === 'external_link';
    externalBlock.style.display = external ? 'grid' : 'none';
    fileBlock.style.display = select.value === 'file_upload' ? 'grid' : 'none';
  }

  productTypeSelect.addEventListener('change', sync);
  select.addEventListener('change', sync);
  sync();
})();
</script>
HTML;

        return $this->shell->render([
            'role' => 'reseller',
            'active' => 'products',
            'title' => $title,
            'subtitle' => 'Configure produto, preco e modo de entrega digital.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Produtos', 'url' => '/reseller/products'],
                ['label' => $title],
            ],
            'toolbar' => '<a class="btn btn-outline" href="/reseller/payment-pages"><i class="bi bi-link-45deg"></i> Paginas de pagamento</a>',
            'content' => $content,
            'extraScripts' => $script,
        ]);
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

        return new Response($status, $this->renderErrorPage($message, '/reseller/products'));
    }

    private function renderErrorPage(string $message, string $backUrl): string
    {
        $content = '<section class="panel"><div class="alert alert--error">' . $this->safe($message) . '</div></section>';

        return $this->shell->render([
            'role' => 'reseller',
            'active' => 'products',
            'title' => 'Erro ao processar produto',
            'subtitle' => 'Revise os dados e tente novamente.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Produtos', 'url' => '/reseller/products'],
                ['label' => 'Erro'],
            ],
            'toolbar' => '<a class="btn btn-outline" href="' . $this->safe($backUrl) . '"><i class="bi bi-arrow-left"></i> Voltar</a>',
            'content' => $content,
        ]);
    }

    private function truncate(string $text, int $max): string
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 3) . '...';
    }

    private function safe(string $value): string
    {
        return $this->sanitizer->html($value);
    }
}
