<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\EmailLogRepository;
use App\Repositories\EmailTemplateRepository;
use App\Repositories\OrderRepository;
use App\Utils\Env;
use App\Utils\Logger;
use App\Utils\Sanitizer;
use App\Utils\SmtpMailer;

final class EmailService
{
    private bool $defaultsEnsured = false;

    public function __construct(
        private readonly EmailTemplateRepository $templates,
        private readonly EmailLogRepository $logs,
        private readonly OrderRepository $orders,
        private readonly DownloadService $downloads,
        private readonly SmtpMailer $mailer,
        private readonly Sanitizer $sanitizer,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTemplatesForReseller(int $resellerId): array
    {
        $this->ensureDefaultTemplates();
        $defaults = $this->templates->listDefaults();
        $custom = $this->templates->listByReseller($resellerId);

        $indexedCustom = [];
        foreach ($custom as $item) {
            $type = (string) ($item['template_type'] ?? '');
            if ($type !== '') {
                $indexedCustom[$type] = $item;
            }
        }

        $merged = [];
        foreach ($defaults as $default) {
            $type = (string) ($default['template_type'] ?? '');
            if ($type === '') {
                continue;
            }

            $customTemplate = $indexedCustom[$type] ?? null;
            $merged[] = [
                'template_type' => $type,
                'subject' => (string) ($customTemplate['subject'] ?? $default['subject'] ?? ''),
                'body_html' => (string) ($customTemplate['body_html'] ?? $default['body_html'] ?? ''),
                'is_active' => (int) ($customTemplate['is_active'] ?? $default['is_active'] ?? 1),
                'source' => $customTemplate !== null ? 'custom' : 'default',
            ];
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveResellerTemplate(int $resellerId, string $type, array $input): void
    {
        $this->ensureDefaultTemplates();
        $type = $this->normalizeTemplateType($type);
        $subject = $this->sanitizer->string($input['subject'] ?? '', 255);
        $body = trim((string) ($input['body_html'] ?? ''));
        $isActive = $this->boolInput($input['is_active'] ?? 1) ? 1 : 0;

        if ($subject === '' || $body === '') {
            throw new \RuntimeException('Subject e body do template sao obrigatorios.');
        }

        $this->templates->upsertForReseller($resellerId, $type, [
            'subject' => $subject,
            'body_html' => $body,
            'is_active' => $isActive,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listLogsForReseller(int $resellerId, int $limit = 120): array
    {
        return $this->logs->listByReseller($resellerId, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function resendLog(int $resellerId, int $logId): array
    {
        $existing = $this->logs->findByIdAndReseller($logId, $resellerId);
        if ($existing === null) {
            throw new \RuntimeException('Log de email nao encontrado.');
        }

        $attempt = (int) ($existing['attempt_count'] ?? 1) + 1;
        $newLogId = $this->logs->create([
            'reseller_id' => $resellerId,
            'order_id' => $existing['order_id'] ?? null,
            'recipient_email' => (string) ($existing['recipient_email'] ?? ''),
            'template_type' => (string) ($existing['template_type'] ?? 'purchase_confirmation'),
            'subject' => (string) ($existing['subject'] ?? ''),
            'body_html' => (string) ($existing['body_html'] ?? ''),
            'status' => 'failed',
            'provider_message' => null,
            'error_message' => null,
            'attempt_count' => $attempt,
            'sent_at' => null,
        ]);

        $send = $this->mailer->send(
            $this->smtpConfig(),
            (string) ($existing['recipient_email'] ?? ''),
            (string) ($existing['subject'] ?? ''),
            (string) ($existing['body_html'] ?? '')
        );

        if (($send['success'] ?? false) === true) {
            $this->logs->updateStatus($newLogId, [
                'status' => 'sent',
                'provider_message' => $send['provider_message'] ?? null,
                'error_message' => null,
                'sent_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } else {
            $this->logs->updateStatus($newLogId, [
                'status' => 'failed',
                'provider_message' => $send['provider_message'] ?? null,
                'error_message' => $send['error'] ?? 'Falha no envio.',
            ]);
        }

        $updated = $this->logs->findByIdAndReseller($newLogId, $resellerId);
        return $updated ?? ['id' => $newLogId, 'status' => 'failed'];
    }

    /**
     * @return array<string, mixed>
     */
    public function sendPostPurchaseBundle(int $orderId, ?string $upsellUrl = null): array
    {
        $context = $this->orders->findEmailContextByOrderId($orderId);
        if ($context === null) {
            return [
                'order_id' => $orderId,
                'processed' => false,
                'reason' => 'order-not-found',
                'results' => [],
            ];
        }

        $recipient = trim((string) ($context['customer_email'] ?? ''));
        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return [
                'order_id' => $orderId,
                'processed' => false,
                'reason' => 'customer-email-not-available',
                'results' => [],
            ];
        }

        $this->ensureDefaultTemplates();
        $orderNo = (string) ($context['order_no'] ?? '');
        $customerName = trim((string) ($context['customer_name'] ?? ''));
        if ($customerName === '') {
            $customerName = 'Cliente';
        }

        $accessUrl = '';
        try {
            $download = $this->downloads->issueDownloadToken($orderId);
            $accessUrl = (string) ($download['access_url'] ?? '');
        } catch (\Throwable $exception) {
            $this->logger->warning('Email bundle could not issue download token', [
                'order_id' => $orderId,
                'error' => $exception->getMessage(),
            ]);
        }

        $variables = [
            '{{customer_name}}' => $customerName,
            '{{product_name}}' => (string) ($context['product_name'] ?? 'Produto digital'),
            '{{order_no}}' => $orderNo,
            '{{amount}}' => number_format((float) ($context['amount'] ?? 0), 2, '.', ','),
            '{{currency}}' => strtoupper((string) ($context['currency'] ?? 'MZN')),
            '{{reseller_name}}' => (string) ($context['reseller_name'] ?? 'Parceiro'),
            '{{access_url}}' => $this->absoluteUrl($accessUrl),
            '{{payment_page_url}}' => $this->absoluteUrl('/p/' . rawurlencode((string) ($context['payment_page_slug'] ?? ''))),
            '{{upsell_url}}' => $upsellUrl !== null ? $this->absoluteUrl($upsellUrl) : '',
        ];

        $results = [];
        $resellerId = (int) ($context['reseller_id'] ?? 0);
        $results[] = $this->sendTemplate($resellerId, $orderId, $recipient, 'purchase_confirmation', $variables);
        $results[] = $this->sendTemplate($resellerId, $orderId, $recipient, 'product_access', $variables);
        if ($upsellUrl !== null && trim($upsellUrl) !== '') {
            $results[] = $this->sendTemplate($resellerId, $orderId, $recipient, 'upsell_offer', $variables);
        }

        return [
            'order_id' => $orderId,
            'processed' => true,
            'results' => $results,
        ];
    }

    /**
     * @param array<string, string> $variables
     * @return array<string, mixed>
     */
    private function sendTemplate(
        int $resellerId,
        int $orderId,
        string $recipientEmail,
        string $templateType,
        array $variables
    ): array {
        $templateType = $this->normalizeTemplateType($templateType);
        $alreadySent = $this->logs->findByOrderAndTemplate($orderId, $templateType);
        if ($alreadySent !== null) {
            return [
                'template_type' => $templateType,
                'status' => 'skipped',
                'reason' => 'already-sent',
            ];
        }

        $template = $this->templates->findActiveByType($resellerId, $templateType);
        if ($template === null) {
            return [
                'template_type' => $templateType,
                'status' => 'skipped',
                'reason' => 'template-not-found',
            ];
        }

        $subject = $this->renderTemplate((string) ($template['subject'] ?? ''), $variables);
        $body = $this->renderTemplate((string) ($template['body_html'] ?? ''), $variables);
        $logId = $this->logs->create([
            'reseller_id' => $resellerId,
            'order_id' => $orderId,
            'recipient_email' => $recipientEmail,
            'template_type' => $templateType,
            'subject' => $subject,
            'body_html' => $body,
            'status' => 'failed',
            'attempt_count' => 1,
        ]);

        $send = $this->mailer->send($this->smtpConfig(), $recipientEmail, $subject, $body);
        if (($send['success'] ?? false) === true) {
            $this->logs->updateStatus($logId, [
                'status' => 'sent',
                'provider_message' => $send['provider_message'] ?? null,
                'error_message' => null,
                'sent_at' => gmdate('Y-m-d H:i:s'),
            ]);

            return [
                'template_type' => $templateType,
                'status' => 'sent',
                'log_id' => $logId,
            ];
        }

        $this->logs->updateStatus($logId, [
            'status' => 'failed',
            'provider_message' => $send['provider_message'] ?? null,
            'error_message' => $send['error'] ?? 'Falha no envio.',
        ]);

        return [
            'template_type' => $templateType,
            'status' => 'failed',
            'log_id' => $logId,
            'error' => $send['error'] ?? 'Falha no envio.',
        ];
    }

    /**
     * @param array<string, string> $variables
     */
    private function renderTemplate(string $template, array $variables): string
    {
        return strtr($template, $variables);
    }

    private function ensureDefaultTemplates(): void
    {
        if ($this->defaultsEnsured) {
            return;
        }

        $this->templates->createDefault('purchase_confirmation', [
            'subject' => 'Confirmacao da compra {{order_no}}',
            'body_html' => '<p>Ola {{customer_name}},</p><p>Recebemos o pagamento do produto <strong>{{product_name}}</strong> no valor de {{currency}} {{amount}}.</p><p>Numero do pedido: <strong>{{order_no}}</strong></p><p>Obrigado por comprar connosco.</p>',
            'is_active' => 1,
        ]);

        $this->templates->createDefault('product_access', [
            'subject' => 'Acesso ao produto {{product_name}}',
            'body_html' => '<p>Ola {{customer_name}},</p><p>O seu acesso ao produto ja esta disponivel.</p><p><a href="{{access_url}}">Clique aqui para aceder ao produto</a></p><p>Pedido: {{order_no}}</p>',
            'is_active' => 1,
        ]);

        $this->templates->createDefault('upsell_offer', [
            'subject' => 'Oferta especial apos a compra',
            'body_html' => '<p>Ola {{customer_name}},</p><p>Temos uma oferta especial complementar ao produto {{product_name}}.</p><p><a href="{{upsell_url}}">Ver oferta especial</a></p>',
            'is_active' => 1,
        ]);

        $this->defaultsEnsured = true;
    }

    private function normalizeTemplateType(string $type): string
    {
        $normalized = strtolower(trim($type));
        $allowed = ['purchase_confirmation', 'product_access', 'upsell_offer'];
        if (!in_array($normalized, $allowed, true)) {
            throw new \RuntimeException('Tipo de template invalido.');
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function smtpConfig(): array
    {
        return [
            'transport' => (string) Env::get('EMAIL_TRANSPORT', 'smtp'),
            'host' => (string) Env::get('EMAIL_HOST', ''),
            'port' => (int) Env::get('EMAIL_PORT', 587),
            'username' => (string) Env::get('EMAIL_USERNAME', ''),
            'password' => (string) Env::get('EMAIL_PASSWORD', ''),
            'encryption' => (string) Env::get('EMAIL_ENCRYPTION', 'tls'),
            'from_address' => (string) Env::get('EMAIL_FROM_ADDRESS', ''),
            'from_name' => (string) Env::get('EMAIL_FROM_NAME', 'SISTEM_PAY'),
            'timeout_seconds' => (int) Env::get('EMAIL_TIMEOUT_SECONDS', 20),
            'allow_insecure' => filter_var(Env::get('EMAIL_ALLOW_INSECURE', false), FILTER_VALIDATE_BOOL),
        ];
    }

    private function absoluteUrl(string $path): string
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/');
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if ($base === '') {
            return $path;
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $base . $path;
    }

    private function boolInput(mixed $value): bool
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
