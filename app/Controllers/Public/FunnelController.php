<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\FunnelService;
use App\Utils\Csrf;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Sanitizer;
use Throwable;

final class FunnelController
{
    public function __construct(
        private readonly FunnelService $funnels,
        private readonly Csrf $csrf,
        private readonly Sanitizer $sanitizer,
    ) {
    }

    public function show(Request $request): Response
    {
        $slug = $this->sanitizer->string((string) $request->route('slug', ''), 190);
        $token = $this->sanitizer->string((string) ($request->query()['token'] ?? ''), 128);
        $stepTypeOverride = $this->sanitizer->string((string) ($request->query()['step'] ?? ''), 40);

        try {
            $context = $this->funnels->getPublicContext($slug, $token !== '' ? $token : null);
            return new Response(200, $this->renderFunnelPage($context, $stepTypeOverride));
        } catch (Throwable $exception) {
            return Response::text('Funil indisponivel: ' . $exception->getMessage(), 404);
        }
    }

    public function checkout(Request $request): Response
    {
        $slug = $this->sanitizer->string((string) $request->route('slug', ''), 190);
        $token = $this->sanitizer->string((string) ($request->body()['token'] ?? ''), 128);
        if ($token === '') {
            return Response::json(['error' => 'Token do funil nao informado.'], 422);
        }

        try {
            $result = $this->funnels->runBaseCheckout($slug, $token, $request->body());
            if ($this->wantsJson($request)) {
                return Response::json($result, 201);
            }

            $order = (array) ($result['order'] ?? []);
            $payment = (array) ($result['payment'] ?? []);
            $status = (string) ($payment['provider_status'] ?? 'processing');
            if ($status === 'confirmed' && !empty($result['next_url'])) {
                return Response::redirect((string) $result['next_url']);
            }

            return new Response(200, $this->renderStatusPage(
                orderNo: (string) ($order['order_no'] ?? ''),
                status: $status,
                title: 'Pagamento do checkout em processamento'
            ));
        } catch (Throwable $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['error' => $exception->getMessage()], 422);
            }

            return Response::text('Falha no checkout do funil: ' . $exception->getMessage(), 422);
        }
    }

    public function offer(Request $request): Response
    {
        $slug = $this->sanitizer->string((string) $request->route('slug', ''), 190);
        $stepType = $this->sanitizer->string((string) $request->route('step_type', ''), 40);
        $token = $this->sanitizer->string((string) ($request->body()['token'] ?? ''), 128);
        $decision = $this->sanitizer->string((string) ($request->body()['decision'] ?? ''), 20);

        if ($token === '') {
            return Response::json(['error' => 'Token do funil nao informado.'], 422);
        }

        try {
            $result = $this->funnels->runOfferAction($slug, $token, $stepType, $decision);
            if ($this->wantsJson($request)) {
                return Response::json($result);
            }

            if (($result['decision'] ?? '') === 'reject') {
                return Response::redirect((string) ($result['redirect_url'] ?? ('/f/' . rawurlencode($slug) . '?token=' . rawurlencode($token))));
            }

            $payment = (array) ($result['payment'] ?? []);
            $status = (string) ($payment['provider_status'] ?? 'processing');
            if ($status === 'confirmed' && !empty($result['next_url'])) {
                return Response::redirect((string) $result['next_url']);
            }

            $order = (array) ($result['order'] ?? []);
            return new Response(200, $this->renderStatusPage(
                orderNo: (string) ($order['order_no'] ?? ''),
                status: $status,
                title: 'Oferta em processamento'
            ));
        } catch (Throwable $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['error' => $exception->getMessage()], 422);
            }

            return Response::text('Falha ao processar oferta: ' . $exception->getMessage(), 422);
        }
    }

    public function status(Request $request): Response
    {
        $orderNo = $this->sanitizer->string((string) $request->route('order_no', ''), 64);
        $data = $this->funnels->statusByOrderNo($orderNo);
        if ($data === null) {
            return Response::json(['error' => 'Pedido nao encontrado.'], 404);
        }

        $snapshot = (array) ($data['snapshot'] ?? []);
        return Response::json([
            'order_no' => (string) ($snapshot['order_no'] ?? ''),
            'order_status' => (string) ($snapshot['status'] ?? ''),
            'payment_status' => (string) ($snapshot['payment_status'] ?? ''),
            'is_paid' => (bool) ($data['is_paid'] ?? false),
            'next_url' => $data['next_url'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderFunnelPage(array $context, string $stepTypeOverride): string
    {
        $funnel = (array) ($context['funnel'] ?? []);
        $session = (array) ($context['session'] ?? []);
        $steps = (array) ($context['steps'] ?? []);
        $current = (array) ($context['current_step'] ?? []);

        if ($stepTypeOverride !== '') {
            foreach ($steps as $step) {
                if (!is_array($step)) {
                    continue;
                }

                if ((string) ($step['step_type'] ?? '') === $stepTypeOverride) {
                    $current = $step;
                    break;
                }
            }
        }

        $slug = $this->safe((string) ($funnel['slug'] ?? ''));
        $token = $this->safe((string) ($session['token'] ?? ''));
        $title = $this->safe((string) ($funnel['name'] ?? 'Funil de vendas'));
        $description = $this->safe((string) ($funnel['description'] ?? ''));
        $stepType = (string) ($current['step_type'] ?? 'landing');
        $stepTitle = $this->safe((string) ($current['title'] ?? 'Etapa'));
        $stepDescription = $this->safe((string) ($current['description'] ?? ''));
        $productName = $this->safe((string) ($current['product_name'] ?? 'Oferta'));

        $checkoutForm = '';
        if (in_array($stepType, ['landing', 'checkout'], true)) {
            $checkoutForm = <<<HTML
<form method="post" action="/f/{$slug}/checkout" class="form-card">
  <input type="hidden" name="{$this->safe($this->csrf->tokenName())}" value="{$this->safe($this->csrf->token())}">
  <input type="hidden" name="token" value="{$token}">
  <label>Nome</label>
  <input type="text" name="customer_name" maxlength="160" placeholder="Nome completo">
  <label>Email</label>
  <input type="email" name="customer_email" maxlength="190" placeholder="email@dominio.com">
  <label>Telefone M-Pesa</label>
  <input type="text" name="customer_phone" maxlength="20" placeholder="25884XXXXXXX" required>
  <button type="submit">Ir para pagamento</button>
</form>
HTML;
        } elseif (in_array($stepType, ['upsell', 'downsell'], true)) {
            $acceptLabel = $this->safe((string) ($current['accept_label'] ?? 'Aceitar oferta'));
            $rejectLabel = $this->safe((string) ($current['reject_label'] ?? 'Recusar'));
            $checkoutForm = <<<HTML
<div class="offer-box">
  <p>Oferta: <strong>{$productName}</strong></p>
  <form method="post" action="/f/{$slug}/offer/{$this->safe($stepType)}" class="inline-form">
    <input type="hidden" name="{$this->safe($this->csrf->tokenName())}" value="{$this->safe($this->csrf->token())}">
    <input type="hidden" name="token" value="{$token}">
    <input type="hidden" name="decision" value="accept">
    <button class="accept" type="submit">{$acceptLabel}</button>
  </form>
  <form method="post" action="/f/{$slug}/offer/{$this->safe($stepType)}" class="inline-form">
    <input type="hidden" name="{$this->safe($this->csrf->tokenName())}" value="{$this->safe($this->csrf->token())}">
    <input type="hidden" name="token" value="{$token}">
    <input type="hidden" name="decision" value="reject">
    <button class="reject" type="submit">{$rejectLabel}</button>
  </form>
</div>
HTML;
        } elseif ($stepType === 'confirmation') {
            $nextType = $this->findStepType($steps, 'upsell') !== null ? 'upsell' : 'thank_you';
            $checkoutForm = '<a class="go-next" href="/f/' . $slug . '?token=' . $token . '&step=' . $nextType . '">Continuar</a>';
        } else {
            $checkoutForm = '<p class="done">Compra finalizada. Obrigado!</p>';
        }

        return <<<HTML
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title}</title>
  <style>
    :root { --bg:#0b1324; --card:#111e36; --line:#1f3355; --txt:#e8f0ff; --muted:#9bb0ce; --acc:#3f88ff; --acc2:#2cc198; --danger:#ff6e7b; }
    *{box-sizing:border-box;} body{margin:0;font-family:Segoe UI,Tahoma,sans-serif;background:radial-gradient(circle at 12% -10%, #17325f 0%, transparent 42%), var(--bg);color:var(--txt);min-height:100vh;padding:24px;}
    .wrap{max-width:880px;margin:0 auto;display:grid;gap:16px;}
    .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px;box-shadow:0 18px 40px rgba(0,0,0,.28);}
    .eyebrow{color:var(--muted);font-size:.82rem;margin-bottom:8px;}
    h1{margin:0 0 6px;font-size:1.6rem;} p{margin:0;color:var(--muted);line-height:1.6;}
    .step{margin-top:16px;padding:12px;border-radius:12px;border:1px solid var(--line);background:#0f1a2f;}
    .form-card{display:grid;gap:8px;margin-top:16px;}
    label{font-size:.85rem;color:var(--muted);} input{width:100%;border:1px solid var(--line);background:#0d172a;color:var(--txt);border-radius:10px;padding:11px;}
    button,.go-next{display:inline-block;margin-top:8px;border:0;border-radius:10px;padding:12px 14px;font-weight:700;color:#fff;text-decoration:none;background:linear-gradient(140deg,var(--acc),#2c7bff);cursor:pointer;}
    .offer-box{margin-top:16px;padding:14px;border:1px solid var(--line);border-radius:12px;background:#0f1a2f;}
    .inline-form{display:inline-block;margin-right:8px;}
    .accept{background:linear-gradient(140deg,var(--acc2),#21a786);}
    .reject{background:linear-gradient(140deg,var(--danger),#d95f6d);}
    .done{margin-top:16px;padding:12px;border-radius:10px;background:#0f1a2f;border:1px solid var(--line);}
  </style>
</head>
<body>
  <div class="wrap">
    <section class="card">
      <div class="eyebrow">Funil de vendas</div>
      <h1>{$title}</h1>
      <p>{$description}</p>
      <div class="step">
        <strong>{$stepTitle}</strong>
        <p>{$stepDescription}</p>
      </div>
      {$checkoutForm}
    </section>
  </div>
</body>
</html>
HTML;
    }

    private function renderStatusPage(string $orderNo, string $status, string $title): string
    {
        $orderNo = $this->safe($orderNo);
        $status = $this->safe($status);
        $title = $this->safe($title);

        return <<<HTML
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title}</title>
  <style>
    body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:#0b1324;color:#eaf1ff;font-family:Segoe UI,Tahoma,sans-serif;}
    .card{max-width:640px;width:100%;background:#111e36;border:1px solid #1f3355;border-radius:16px;padding:22px;}
    .status{margin-top:12px;padding:12px;border-radius:10px;background:#0f1a2f;border:1px solid #1f3355;}
  </style>
</head>
<body>
  <main class="card">
    <h1>{$title}</h1>
    <div class="status">
      <div><strong>Pedido:</strong> {$orderNo}</div>
      <div><strong>Status atual:</strong> <span id="status">{$status}</span></div>
    </div>
  </main>
  <script>
    (function () {
      const orderNo = '{$orderNo}';
      const statusEl = document.getElementById('status');
      const timer = setInterval(function () {
        fetch('/funnel/status/' + encodeURIComponent(orderNo), { headers: { 'Accept': 'application/json' } })
          .then(function (res) { return res.json(); })
          .then(function (payload) {
            if (!payload || payload.error) return;
            statusEl.textContent = payload.order_status + ' / ' + payload.payment_status;
            if (payload.next_url) {
              window.location = payload.next_url;
              return;
            }
            if (payload.order_status === 'failed' || payload.payment_status === 'failed' || payload.payment_status === 'timeout') {
              clearInterval(timer);
            }
          })
          .catch(function () {});
      }, 4000);
    })();
  </script>
</body>
</html>
HTML;
    }

    /**
     * @param array<int, mixed> $steps
     */
    private function findStepType(array $steps, string $type): ?array
    {
        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }
            if ((string) ($step['step_type'] ?? '') === $type) {
                return $step;
            }
        }

        return null;
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        return str_contains($accept, 'application/json') || str_starts_with($request->path(), '/api/');
    }

    private function safe(string $value): string
    {
        return $this->sanitizer->html($value);
    }
}
