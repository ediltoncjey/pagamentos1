<?php

declare(strict_types=1);

namespace App\Controllers\Reseller;

use App\Services\EmailService;
use App\Utils\Csrf;
use App\Utils\DashboardShell;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Sanitizer;
use App\Utils\SessionManager;
use Throwable;

final class EmailTemplateController
{
    public function __construct(
        private readonly EmailService $emails,
        private readonly DashboardShell $shell,
        private readonly SessionManager $session,
        private readonly Csrf $csrf,
        private readonly Sanitizer $sanitizer,
    ) {
    }

    public function index(Request $request): Response
    {
        $resellerId = $this->userId();
        $templates = $this->emails->listTemplatesForReseller($resellerId);
        $logs = $this->emails->listLogsForReseller($resellerId, 120);

        if ($this->wantsJson($request)) {
            return Response::json([
                'templates' => $templates,
                'logs' => $logs,
            ]);
        }

        return new Response(200, $this->renderIndex($templates, $logs, $request->query()));
    }

    public function saveTemplate(Request $request): Response
    {
        $resellerId = $this->userId();
        $type = (string) ($request->route('type', ''));

        try {
            $this->emails->saveResellerTemplate($resellerId, $type, $request->body());
            if ($this->wantsJson($request)) {
                return Response::json(['message' => 'Template atualizado com sucesso.']);
            }

            return Response::redirect('/reseller/email-templates?success=' . rawurlencode('Template atualizado com sucesso.'));
        } catch (Throwable $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['error' => $exception->getMessage()], 422);
            }

            return Response::redirect('/reseller/email-templates?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function resend(Request $request): Response
    {
        $resellerId = $this->userId();
        $logId = (int) $request->route('id', 0);

        try {
            $log = $this->emails->resendLog($resellerId, $logId);
            if ($this->wantsJson($request)) {
                return Response::json([
                    'message' => 'Reenvio processado.',
                    'log' => $log,
                ]);
            }

            return Response::redirect('/reseller/email-templates?success=' . rawurlencode('Reenvio processado.'));
        } catch (Throwable $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['error' => $exception->getMessage()], 422);
            }

            return Response::redirect('/reseller/email-templates?error=' . rawurlencode($exception->getMessage()));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $templates
     * @param array<int, array<string, mixed>> $logs
     * @param array<string, mixed> $query
     */
    private function renderIndex(array $templates, array $logs, array $query): string
    {
        $csrfToken = $this->safe($this->csrf->token());
        $flash = $this->flashFromQuery($query);

        $templateCards = '';
        foreach ($templates as $template) {
            $type = $this->safe((string) ($template['template_type'] ?? ''));
            $subject = $this->safe((string) ($template['subject'] ?? ''));
            $body = $this->safe((string) ($template['body_html'] ?? ''));
            $isActive = (int) ($template['is_active'] ?? 1) === 1 ? 'checked' : '';
            $source = $this->safe((string) ($template['source'] ?? 'default'));

            $templateCards .= <<<HTML
<section class="panel">
  <div class="panel__header">
    <div>
      <h3 class="panel__title">Template {$type}</h3>
      <p class="panel__subtitle">Fonte: {$source}. Variaveis: {{customer_name}}, {{product_name}}, {{order_no}}, {{access_url}}, {{upsell_url}}.</p>
    </div>
  </div>
  <form method="post" action="/reseller/email-templates/{$type}" class="panel__body">
    <input type="hidden" name="{$this->safe($this->csrf->tokenName())}" value="{$csrfToken}">
    <div class="form-group"><label class="label">Assunto</label><input class="input" type="text" name="subject" maxlength="255" value="{$subject}" required></div>
    <div class="form-group"><label class="label">Corpo HTML</label><textarea class="textarea" name="body_html" required>{$body}</textarea></div>
    <div class="form-group"><label class="switch"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" {$isActive}> Template ativo</label></div>
    <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Guardar template</button>
  </form>
</section>
HTML;
        }

        if ($templateCards === '') {
            $templateCards = '<div class="empty-state">Nenhum template disponivel.</div>';
        }

        $logRows = '';
        foreach ($logs as $log) {
            $id = (int) ($log['id'] ?? 0);
            $template = $this->safe((string) ($log['template_type'] ?? '-'));
            $recipient = $this->safe((string) ($log['recipient_email'] ?? '-'));
            $status = (string) ($log['status'] ?? 'failed');
            $badge = $status === 'sent' ? 'badge--success' : 'badge--danger';
            $sentAt = $this->safe((string) ($log['sent_at'] ?? $log['created_at'] ?? '-'));
            $error = $this->safe((string) ($log['error_message'] ?? ''));
            $attempt = (int) ($log['attempt_count'] ?? 1);

            $logRows .= '<tr>'
                . '<td>#' . $id . '</td>'
                . '<td>' . $template . '</td>'
                . '<td>' . $recipient . '</td>'
                . '<td><span class="badge ' . $badge . '">' . $this->safe($status) . '</span></td>'
                . '<td>' . $attempt . '</td>'
                . '<td>' . $sentAt . '</td>'
                . '<td>' . ($error !== '' ? $error : '-') . '</td>'
                . '<td><form method="post" action="/reseller/email-logs/' . $id . '/resend">'
                . '<input type="hidden" name="' . $this->safe($this->csrf->tokenName()) . '" value="' . $csrfToken . '">'
                . '<button class="btn btn-outline" type="submit"><i class="bi bi-arrow-repeat"></i> Reenviar</button></form></td>'
                . '</tr>';
        }
        if ($logRows === '') {
            $logRows = '<tr><td colspan="8"><div class="empty-state">Nenhum envio de email registado.</div></td></tr>';
        }

        $content = <<<HTML
{$flash}

<section class="dashboard-hero">
  <div class="dashboard-hero__row">
    <div>
      <h2 class="dashboard-hero__title">Emails automaticos de pos-compra</h2>
      <p class="dashboard-hero__subtitle">Personalize templates, acompanhe logs e faca reenvio sem sair do painel.</p>
      <div class="hero-pills">
        <span class="pill"><i class="bi bi-envelope-open"></i> Templates: {$this->safe((string) count($templates))}</span>
        <span class="pill"><i class="bi bi-clock-history"></i> Logs: {$this->safe((string) count($logs))}</span>
      </div>
    </div>
  </div>
</section>

{$templateCards}

<section class="panel">
  <div class="panel__header">
    <div>
      <h3 class="panel__title">Logs de envio</h3>
      <p class="panel__subtitle">Historico com status, erros e tentativas.</p>
    </div>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>ID</th><th>Template</th><th>Destino</th><th>Status</th><th>Tentativas</th><th>Data</th><th>Erro</th><th>Acao</th></tr></thead>
      <tbody>{$logRows}</tbody>
    </table>
  </div>
</section>
HTML;

        return $this->shell->render([
            'role' => 'reseller',
            'active' => 'email-templates',
            'title' => 'Emails',
            'subtitle' => 'Templates SMTP, logs e reenvio.',
            'breadcrumbs' => [
                ['label' => 'Reseller'],
                ['label' => 'Emails'],
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
