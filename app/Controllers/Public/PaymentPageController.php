<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Repositories\PaymentPageRepository;
use App\Services\CheckoutService;
use App\Services\DownloadService;
use App\Services\FunnelService;
use App\Services\PaymentService;
use App\Services\Payments\GatewayCatalogService;
use App\Utils\Csrf;
use App\Utils\Env;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Sanitizer;
use Throwable;

final class PaymentPageController
{
    public function __construct(
        private readonly PaymentPageRepository $pages,
        private readonly CheckoutService $checkout,
        private readonly PaymentService $payments,
        private readonly DownloadService $downloads,
        private readonly FunnelService $funnels,
        private readonly GatewayCatalogService $gateways,
        private readonly Sanitizer $sanitizer,
        private readonly Csrf $csrf,
    ) {
    }

    public function show(Request $request): Response
    {
        $slug = $this->sanitizer->string((string) $request->route('slug', ''), 180);
        $page = $this->pages->findActiveBySlug($slug);
        if ($page === null) {
            return Response::text('Pagina nao encontrada', 404);
        }

        $this->pages->incrementViews((int) $page['id']);
        return new Response(200, $this->renderCheckoutPage($page, null, null));
    }

    public function checkout(Request $request): Response
    {
        $slug = $this->sanitizer->string((string) $request->route('slug', ''), 180);
        $page = $this->pages->findActiveBySlug($slug);
        if ($page === null) {
            return $this->errorResponse($request, 'Pagina de pagamento indisponivel.', 404);
        }

        try {
            $gatewayCode = $this->gateways->resolveGatewayForCheckout(
                page: $page,
                requestedCode: (string) $request->input('payment_method', '')
            );

            $customer = $this->extractCustomerPayload($request, $page);
            $order = $this->checkout->createPendingOrder(
                pageSlug: $slug,
                customerPhone: $customer['customer_phone'],
                customerName: $customer['customer_name'],
                customerEmail: $customer['customer_email'],
                customerProfile: [
                    'country' => $customer['customer_country'],
                    'city' => $customer['customer_city'],
                    'address' => $customer['customer_address'],
                    'notes' => $customer['customer_notes'],
                ],
                selectedGateway: $gatewayCode
            );

            $payment = $this->payments->initiatePayment((int) $order['id'], $gatewayCode);
            if (($payment['provider_status'] ?? null) === 'confirmed') {
                try {
                    $this->funnels->processConfirmedOrder((int) $order['id']);
                } catch (Throwable) {
                }
            }

            $payload = [
                'message' => 'Pedido criado com sucesso.',
                'order' => [
                    'id' => (int) $order['id'],
                    'order_no' => (string) $order['order_no'],
                    'status' => (string) $order['status'],
                    'amount' => (float) $order['amount'],
                    'currency' => (string) $order['currency'],
                ],
                'payment' => [
                    'gateway' => $gatewayCode,
                    'provider_status' => $payment['provider_status'] ?? 'processing',
                    'http_code' => $payment['http_code'] ?? null,
                    'error' => $payment['error'] ?? null,
                ],
                'status_url' => $this->withBasePath('/checkout/status/' . (string) $order['order_no']),
            ];

            if ($this->wantsJson($request)) {
                return Response::json($payload, 201);
            }

            return new Response(200, $this->renderStatusPage(
                page: $page,
                orderNo: (string) $order['order_no'],
                initialOrderStatus: (string) $order['status'],
                initialPaymentStatus: (string) ($payment['provider_status'] ?? 'processing'),
                gatewayCode: $gatewayCode
            ));
        } catch (Throwable $exception) {
            return $this->errorResponse($request, $exception->getMessage(), 422);
        }
    }

    public function orderStatus(Request $request): Response
    {
        $orderNo = $this->sanitizer->string((string) $request->route('order_no', ''), 64);
        $snapshot = $this->checkout->getOrderStatus($orderNo);
        if ($snapshot === null) {
            return Response::json(['error' => 'Pedido nao encontrado.'], 404);
        }

        $orderStatus = (string) $snapshot['status'];
        $paymentStatus = (string) ($snapshot['payment_status'] ?? 'not_initiated');
        $isPaid = $orderStatus === 'paid' || $paymentStatus === 'confirmed';
        $isPhysical = (string) ($snapshot['product_type'] ?? 'digital') === 'physical';
        $delivery = null;

        if ($isPaid && !$isPhysical) {
            try {
                $issued = $this->downloads->issueDownloadToken((int) $snapshot['id']);
                if (($issued['can_access'] ?? false) === true) {
                    $delivery = [
                        'token' => $issued['token'],
                        'url' => $issued['access_url'] ?? $this->withBasePath('/d/' . rawurlencode((string) $issued['token'])),
                        'mode' => $issued['delivery_mode'] ?? null,
                        'expires_at' => $issued['expires_at'] ?? null,
                        'max_downloads' => $issued['max_downloads'] ?? null,
                    ];
                }
            } catch (Throwable) {
                $delivery = null;
            }
        }

        return Response::json([
            'order_no' => (string) $snapshot['order_no'],
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'provider_reference' => $snapshot['provider_reference'] ?? null,
            'gateway' => $snapshot['selected_gateway'] ?? null,
            'amount' => (float) $snapshot['amount'],
            'currency' => (string) $snapshot['currency'],
            'product_name' => (string) $snapshot['product_name'],
            'is_paid' => $isPaid,
            'is_physical' => $isPhysical,
            'message' => $this->statusMessage($orderStatus, $paymentStatus, $isPhysical),
            'delivery' => $delivery,
            'delivery_available' => $delivery !== null,
        ]);
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function extractCustomerPayload(Request $request, array $page): array
    {
        $name = $this->sanitizer->string((string) $request->input('customer_name', ''), 160);
        $email = $this->sanitizer->email((string) $request->input('customer_email', ''));
        $phone = $this->sanitizer->phone((string) $request->input('customer_phone', ''));
        $country = $this->sanitizer->string((string) $request->input('customer_country', ''), 80);
        $city = $this->sanitizer->string((string) $request->input('customer_city', ''), 120);
        $address = $this->sanitizer->string((string) $request->input('customer_address', ''), 255);
        $notes = $this->sanitizer->string((string) $request->input('customer_notes', ''), 500);

        if ((int) ($page['require_customer_name'] ?? 1) === 1 && $name === '') {
            throw new \RuntimeException('Nome completo e obrigatorio.');
        }
        if ((int) ($page['require_customer_email'] ?? 1) === 1 && $email === '') {
            throw new \RuntimeException('Email e obrigatorio.');
        }
        if ($phone === '' || strlen($phone) < 9 || strlen($phone) > 15) {
            throw new \RuntimeException('Telefone invalido.');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Email invalido.');
        }

        return [
            'customer_name' => $name !== '' ? $name : null,
            'customer_email' => $email !== '' ? $email : null,
            'customer_phone' => $phone,
            'customer_country' => (int) ($page['collect_country'] ?? 1) === 1 ? ($country !== '' ? $country : null) : null,
            'customer_city' => (int) ($page['collect_city'] ?? 1) === 1 ? ($city !== '' ? $city : null) : null,
            'customer_address' => (int) ($page['collect_address'] ?? 1) === 1 ? ($address !== '' ? $address : null) : null,
            'customer_notes' => (int) ($page['collect_notes'] ?? 1) === 1 ? ($notes !== '' ? $notes : null) : null,
        ];
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed>|null $flash
     */
    private function renderCheckoutPage(array $page, ?array $flash, ?array $old): string
    {
        $csrfToken = $this->csrf->token();
        $slug = $this->safe((string) $page['slug']);
        $title = $this->safe((string) $page['title']);
        $description = $this->safe((string) ($page['description'] ?: $page['product_description'] ?? ''));
        $productName = $this->safe((string) ($page['product_name'] ?? 'Produto digital'));
        $price = number_format((float) $page['product_price'], 2, '.', ',');
        $currency = $this->safe((string) $page['currency']);
        $imagePath = trim((string) ($page['image_path'] ?? ''));
        $imageBlock = $imagePath !== ''
            ? '<div class="checkout-product__image checkout-product__image--placeholder"><i class="bi bi-image"></i><span>Imagem do produto cadastrada</span></div>'
            : '<div class="checkout-product__image checkout-product__image--placeholder"><i class="bi bi-box-seam"></i><span>Produto digital</span></div>';
        $methods = $this->gateways->listCheckoutMethods($page);
        $nameRequired = (int) ($page['require_customer_name'] ?? 1) === 1;
        $emailRequired = (int) ($page['require_customer_email'] ?? 1) === 1;
        $countryField = (int) ($page['collect_country'] ?? 1) === 1
            ? '<label><span>Pais</span><input type="text" name="customer_country" maxlength="80"></label>'
            : '';
        $cityField = (int) ($page['collect_city'] ?? 1) === 1
            ? '<label><span>Cidade</span><input type="text" name="customer_city" maxlength="120"></label>'
            : '';
        $addressField = (int) ($page['collect_address'] ?? 1) === 1
            ? '<label class="checkout-grid__full"><span>Endereco / Localizacao</span><input type="text" name="customer_address" maxlength="255"></label>'
            : '';
        $notesField = (int) ($page['collect_notes'] ?? 1) === 1
            ? '<label class="checkout-grid__full"><span>Observacoes</span><textarea name="customer_notes" maxlength="500" rows="3"></textarea></label>'
            : '';
        $paymentMethodsHtml = '';
        $selectedMethodSet = false;
        foreach ($methods as $index => $method) {
            $code = $this->safe((string) $method['code']);
            $label = $this->safe((string) $method['display_name']);
            $icon = $this->safe((string) $method['icon_class']);
            $available = (bool) ($method['is_available'] ?? false);
            $checked = '';
            if ($available && !$selectedMethodSet) {
                $checked = 'checked';
                $selectedMethodSet = true;
            }
            $disabled = $available ? '' : 'disabled';
            $hint = $available ? 'Disponivel' : $this->safe((string) ($method['unavailable_reason'] ?? 'Indisponivel'));
            $paymentMethodsHtml .= '<label class="checkout-method"><input type="radio" name="payment_method" value="' . $code . '" ' . $checked . ' ' . $disabled . '><span class="checkout-method__icon"><i class="bi ' . $icon . '"></i></span><span class="checkout-method__meta"><strong>' . $label . '</strong><small>' . $hint . '</small></span></label>';
        }
        $submitDisabled = '';
        if (trim($paymentMethodsHtml) === '') {
            $paymentMethodsHtml = '<div class="checkout-alert checkout-alert--error">Nenhum metodo de pagamento disponivel nesta pagina.</div>';
            $submitDisabled = 'disabled';
        }

        $flashHtml = '';
        if (is_array($flash)) {
            $type = $this->safe((string) ($flash['type'] ?? 'error'));
            $message = $this->safe((string) ($flash['message'] ?? ''));
            $flashHtml = '<div class="checkout-alert checkout-alert--' . $type . '">' . $message . '</div>';
        }
        $bootstrapLocal = $this->withBasePath('/assets/vendor/bootstrap-icons/bootstrap-icons.min.css');
        $checkoutCss = $this->withBasePath('/assets/checkout.css');
        $checkoutJs = $this->withBasePath('/assets/checkout.js');

        return <<<HTML
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title} | Checkout</title>
  <link rel="stylesheet" href="{$bootstrapLocal}">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="{$checkoutCss}">
</head>
<body class="checkout-body">
  <main class="checkout-shell">
    <section class="checkout-panel checkout-panel--product">
      <div class="checkout-product__head">
        <span class="checkout-pill"><i class="bi bi-shield-check"></i> Pagamento seguro</span>
        <h1>{$title}</h1>
        <p>{$description}</p>
      </div>
      {$imageBlock}
      <div class="checkout-summary">
        <div class="checkout-summary__row"><span>Produto</span><strong>{$productName}</strong></div>
        <div class="checkout-summary__row"><span>Total</span><strong>{$currency} {$price}</strong></div>
        <div class="checkout-summary__trust">
          <i class="bi bi-lock"></i> Seus dados estao protegidos
          <br>
          <i class="bi bi-box-seam"></i> Entrega automatica apos confirmacao
        </div>
      </div>
    </section>

    <section class="checkout-panel checkout-panel--form">
      {$flashHtml}
      <form id="checkout-form" method="post" action="{$this->withBasePath('/checkout/' . $slug)}">
        <input type="hidden" name="{$this->safe($this->csrf->tokenName())}" value="{$this->safe($csrfToken)}">
        <h2>Dados do cliente</h2>
        <div class="checkout-grid">
          <label><span>Nome completo</span><input type="text" name="customer_name" maxlength="160" {$this->requiredAttr($nameRequired)}></label>
          <label><span>Email</span><input type="email" name="customer_email" maxlength="190" {$this->requiredAttr($emailRequired)}></label>
          <label><span>Telefone</span><input type="text" name="customer_phone" maxlength="20" placeholder="25884XXXXXXX" required></label>
          {$countryField}
          {$cityField}
          {$addressField}
          {$notesField}
        </div>

        <h2>Metodo de pagamento</h2>
        <div class="checkout-methods">{$paymentMethodsHtml}</div>

        <button id="checkout-submit" class="checkout-submit" type="submit" {$submitDisabled}>
          <i class="bi bi-shield-lock"></i> Finalizar pagamento
        </button>
      </form>

      <div id="checkout-status" class="checkout-status checkout-status--idle">
        Preencha os dados e confirme o pagamento.
      </div>
    </section>
  </main>
  <script>
    window.SistemCheckout = {
      statusPrefix: '{$this->withBasePath('/checkout/status/')}'
    };
  </script>
  <script src="{$checkoutJs}"></script>
</body>
</html>
HTML;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function renderStatusPage(
        array $page,
        string $orderNo,
        string $initialOrderStatus,
        string $initialPaymentStatus,
        string $gatewayCode
    ): string {
        $safeOrderNo = $this->safe($orderNo);
        $safeGateway = $this->safe(strtoupper($gatewayCode));
        $safeMessage = $this->safe($this->statusMessage($initialOrderStatus, $initialPaymentStatus, false));
        $backUrl = $this->withBasePath('/p/' . rawurlencode((string) ($page['slug'] ?? '')));
        $bootstrapLocal = $this->withBasePath('/assets/vendor/bootstrap-icons/bootstrap-icons.min.css');
        $checkoutCss = $this->withBasePath('/assets/checkout.css');
        $checkoutJs = $this->withBasePath('/assets/checkout.js');

        return <<<HTML
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Checkout em processamento</title>
  <link rel="stylesheet" href="{$bootstrapLocal}">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="{$checkoutCss}">
</head>
<body class="checkout-status-body">
  <main class="checkout-status-card">
    <h1>Pedido criado com sucesso</h1>
    <p>Gateway selecionado: <strong>{$safeGateway}</strong></p>
    <p>Pedido: <strong>{$safeOrderNo}</strong></p>
    <div id="status-message" class="checkout-status checkout-status--processing">{$safeMessage}</div>
    <div id="delivery-container"></div>
    <a class="checkout-back" href="{$backUrl}">Voltar para a pagina</a>
  </main>
  <script>
    window.SistemCheckout = {
      statusPrefix: '{$this->withBasePath('/checkout/status/')}',
      orderNo: '{$safeOrderNo}'
    };
  </script>
  <script src="{$checkoutJs}"></script>
</body>
</html>
HTML;
    }

    private function statusMessage(string $orderStatus, string $paymentStatus, bool $isPhysical): string
    {
        if ($orderStatus === 'paid' || $paymentStatus === 'confirmed') {
            return $isPhysical
                ? 'Pagamento confirmado. O pedido fisico foi registado.'
                : 'Pagamento confirmado. Produto liberado para acesso.';
        }

        if ($orderStatus === 'failed' || $paymentStatus === 'failed' || $paymentStatus === 'timeout') {
            return 'Pagamento falhou ou expirou. Tente novamente.';
        }

        if ($orderStatus === 'expired') {
            return 'Este pedido expirou. Gere um novo checkout.';
        }

        return 'Pagamento em processamento. Aguarde alguns segundos.';
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        return str_contains($accept, 'application/json') || str_starts_with($request->path(), '/api/');
    }

    private function errorResponse(Request $request, string $message, int $statusCode): Response
    {
        if ($this->wantsJson($request)) {
            return Response::json(['error' => $message], $statusCode);
        }

        return new Response($statusCode, $this->renderCheckoutPage(
            page: [
                'slug' => '',
                'title' => 'Checkout indisponivel',
                'description' => '',
                'product_name' => '',
                'product_price' => 0,
                'currency' => 'MZN',
                'allow_mpesa' => 1,
                'allow_emola' => 0,
                'allow_visa' => 0,
                'allow_paypal' => 0,
                'require_customer_name' => 1,
                'require_customer_email' => 1,
                'collect_country' => 1,
                'collect_city' => 1,
                'collect_address' => 1,
                'collect_notes' => 1,
            ],
            flash: ['type' => 'error', 'message' => $message],
            old: null
        ));
    }

    private function appBasePath(): string
    {
        $appUrl = (string) Env::get('APP_URL', '');
        if ($appUrl === '') {
            return '';
        }

        $path = trim((string) parse_url($appUrl, PHP_URL_PATH));
        if ($path === '' || $path === '/') {
            return '';
        }

        return '/' . trim($path, '/');
    }

    private function withBasePath(string $path): string
    {
        if ($path === '' || !str_starts_with($path, '/')) {
            return $path;
        }

        $base = $this->appBasePath();
        if ($base === '') {
            return $path;
        }

        if ($path === $base || str_starts_with($path, $base . '/')) {
            return $path;
        }

        return $base . $path;
    }

    private function requiredAttr(bool $required): string
    {
        return $required ? 'required' : '';
    }

    private function safe(string $value): string
    {
        return $this->sanitizer->html($value);
    }
}
