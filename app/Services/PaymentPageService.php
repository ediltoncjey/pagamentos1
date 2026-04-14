<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\PaymentPageRepository;
use App\Repositories\ProductRepository;
use App\Utils\Logger;
use App\Utils\Sanitizer;
use App\Utils\Validator;
use RuntimeException;

final class PaymentPageService
{
    public function __construct(
        private readonly PaymentPageRepository $pages,
        private readonly ProductRepository $products,
        private readonly AuditLogRepository $auditLogs,
        private readonly Sanitizer $sanitizer,
        private readonly Validator $validator,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByReseller(int $resellerId): array
    {
        return $this->pages->listByReseller($resellerId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForReseller(int $pageId, int $resellerId): ?array
    {
        return $this->pages->findByIdAndReseller($pageId, $resellerId);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function createPage(int $resellerId, array $input, array $context = []): array
    {
        $payload = $this->preparePayload($input, $resellerId, null);

        $pageId = $this->pages->create([
            'product_id' => $payload['product_id'],
            'reseller_id' => $resellerId,
            'slug' => $payload['slug'],
            'title' => $payload['title'],
            'description' => $payload['description'],
            'require_customer_name' => $payload['require_customer_name'],
            'require_customer_email' => $payload['require_customer_email'],
            'require_customer_phone' => $payload['require_customer_phone'],
            'collect_country' => $payload['collect_country'],
            'collect_city' => $payload['collect_city'],
            'collect_address' => $payload['collect_address'],
            'collect_notes' => $payload['collect_notes'],
            'allow_mpesa' => $payload['allow_mpesa'],
            'allow_emola' => $payload['allow_emola'],
            'allow_visa' => $payload['allow_visa'],
            'allow_paypal' => $payload['allow_paypal'],
            'status' => $payload['status'],
        ]);

        $created = $this->pages->findByIdAndReseller($pageId, $resellerId);
        if ($created === null) {
            throw new RuntimeException('Falha ao criar pagina de pagamento.');
        }

        $this->registerAudit(
            action: 'payment_page.create',
            pageId: $pageId,
            context: $context,
            newValues: $this->auditView($created)
        );

        return $created;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function updatePage(int $pageId, int $resellerId, array $input, array $context = []): array
    {
        $existing = $this->pages->findByIdAndReseller($pageId, $resellerId);
        if ($existing === null) {
            throw new RuntimeException('Pagina de pagamento nao encontrada.');
        }

        $payload = $this->preparePayload($input, $resellerId, $existing);
        $old = $this->auditView($existing);

        $this->pages->updateByIdAndReseller($pageId, $resellerId, [
            'product_id' => $payload['product_id'],
            'slug' => $payload['slug'],
            'title' => $payload['title'],
            'description' => $payload['description'],
            'require_customer_name' => $payload['require_customer_name'],
            'require_customer_email' => $payload['require_customer_email'],
            'require_customer_phone' => $payload['require_customer_phone'],
            'collect_country' => $payload['collect_country'],
            'collect_city' => $payload['collect_city'],
            'collect_address' => $payload['collect_address'],
            'collect_notes' => $payload['collect_notes'],
            'allow_mpesa' => $payload['allow_mpesa'],
            'allow_emola' => $payload['allow_emola'],
            'allow_visa' => $payload['allow_visa'],
            'allow_paypal' => $payload['allow_paypal'],
            'status' => $payload['status'],
        ]);

        $updated = $this->pages->findByIdAndReseller($pageId, $resellerId);
        if ($updated === null) {
            throw new RuntimeException('Falha ao atualizar pagina de pagamento.');
        }

        $this->registerAudit(
            action: 'payment_page.update',
            pageId: $pageId,
            context: $context,
            oldValues: $old,
            newValues: $this->auditView($updated)
        );

        return $updated;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function toggleStatus(int $pageId, int $resellerId, array $context = []): array
    {
        $existing = $this->pages->findByIdAndReseller($pageId, $resellerId);
        if ($existing === null) {
            throw new RuntimeException('Pagina de pagamento nao encontrada.');
        }

        $this->pages->toggleStatus($pageId, $resellerId);
        $updated = $this->pages->findByIdAndReseller($pageId, $resellerId);
        if ($updated === null) {
            throw new RuntimeException('Falha ao alternar estado da pagina de pagamento.');
        }

        $this->registerAudit(
            action: 'payment_page.toggle_status',
            pageId: $pageId,
            context: $context,
            oldValues: ['status' => $existing['status']],
            newValues: ['status' => $updated['status']]
        );

        return $updated;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function preparePayload(array $input, int $resellerId, ?array $existing): array
    {
        $productId = (int) ($input['product_id'] ?? ($existing['product_id'] ?? 0));
        $product = $this->products->findByIdAndReseller($productId, $resellerId);
        if ($product === null) {
            throw new RuntimeException('Produto invalido para a pagina de pagamento.');
        }

        if ((int) ($product['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Produto precisa estar ativo para criar pagina de pagamento.');
        }

        $title = $this->sanitizer->string($input['title'] ?? ($existing['title'] ?? ''), 190);
        $description = $this->sanitizer->string($input['description'] ?? ($existing['description'] ?? ''), 5000);
        $status = strtolower($this->sanitizer->string($input['status'] ?? ($existing['status'] ?? 'active'), 10));
        $slugInput = $this->sanitizer->string($input['slug'] ?? ($existing['slug'] ?? ''), 190);

        if ($slugInput === '') {
            $slugInput = $title !== '' ? $title : (string) $product['name'];
        }

        $slug = $this->ensureUniqueSlug($this->slugify($slugInput), $existing !== null ? (int) $existing['id'] : null);
        $validation = $this->validator->validate(
            [
                'title' => $title,
                'status' => $status,
                'slug' => $slug,
            ],
            [
                'title' => 'required|min:3|max:190',
                'status' => 'required|in:active,inactive',
                'slug' => 'required|min:3|max:190',
            ]
        );
        if (!$validation['valid']) {
            throw new RuntimeException('Dados invalidos: ' . json_encode($validation['errors']));
        }

        $requireCustomerName = $this->boolInput(
            $input,
            'require_customer_name',
            (int) ($existing['require_customer_name'] ?? 1) === 1
        );
        $requireCustomerEmail = $this->boolInput(
            $input,
            'require_customer_email',
            (int) ($existing['require_customer_email'] ?? 1) === 1
        );
        $collectCountry = $this->boolInput($input, 'collect_country', (int) ($existing['collect_country'] ?? 1) === 1);
        $collectCity = $this->boolInput($input, 'collect_city', (int) ($existing['collect_city'] ?? 1) === 1);
        $collectAddress = $this->boolInput($input, 'collect_address', (int) ($existing['collect_address'] ?? 1) === 1);
        $collectNotes = $this->boolInput($input, 'collect_notes', (int) ($existing['collect_notes'] ?? 1) === 1);

        $allowMpesa = $this->boolInput($input, 'allow_mpesa', (int) ($existing['allow_mpesa'] ?? 1) === 1);
        $allowEmola = $this->boolInput($input, 'allow_emola', (int) ($existing['allow_emola'] ?? 0) === 1);
        $allowVisa = $this->boolInput($input, 'allow_visa', (int) ($existing['allow_visa'] ?? 0) === 1);
        $allowPaypal = $this->boolInput($input, 'allow_paypal', (int) ($existing['allow_paypal'] ?? 0) === 1);

        if (!$allowMpesa && !$allowEmola && !$allowVisa && !$allowPaypal) {
            throw new RuntimeException('Selecione pelo menos um metodo de pagamento para a pagina.');
        }

        return [
            'product_id' => $productId,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'status' => $status,
            'slug' => $slug,
            'require_customer_name' => $requireCustomerName ? 1 : 0,
            'require_customer_email' => $requireCustomerEmail ? 1 : 0,
            'require_customer_phone' => 1,
            'collect_country' => $collectCountry ? 1 : 0,
            'collect_city' => $collectCity ? 1 : 0,
            'collect_address' => $collectAddress ? 1 : 0,
            'collect_notes' => $collectNotes ? 1 : 0,
            'allow_mpesa' => $allowMpesa ? 1 : 0,
            'allow_emola' => $allowEmola ? 1 : 0,
            'allow_visa' => $allowVisa ? 1 : 0,
            'allow_paypal' => $allowPaypal ? 1 : 0,
        ];
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value !== '' ? substr($value, 0, 190) : 'pagina-' . substr(bin2hex(random_bytes(6)), 0, 10);
    }

    private function ensureUniqueSlug(string $baseSlug, ?int $currentPageId = null): string
    {
        $candidate = $baseSlug;
        $attempt = 1;

        while (true) {
            $found = $this->pages->findBySlug($candidate);
            if ($found === null) {
                return $candidate;
            }

            if ($currentPageId !== null && (int) $found['id'] === $currentPageId) {
                return $candidate;
            }

            $attempt++;
            $suffix = '-' . $attempt;
            $candidate = substr($baseSlug, 0, max(1, 190 - strlen($suffix))) . $suffix;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function boolInput(array $input, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }

        $value = $input[$key];
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function auditView(array $page): array
    {
        return [
            'title' => $page['title'] ?? null,
            'slug' => $page['slug'] ?? null,
            'status' => $page['status'] ?? null,
            'product_id' => $page['product_id'] ?? null,
            'require_customer_name' => $page['require_customer_name'] ?? 1,
            'require_customer_email' => $page['require_customer_email'] ?? 1,
            'collect_country' => $page['collect_country'] ?? 1,
            'collect_city' => $page['collect_city'] ?? 1,
            'collect_address' => $page['collect_address'] ?? 1,
            'collect_notes' => $page['collect_notes'] ?? 1,
            'allow_mpesa' => $page['allow_mpesa'] ?? 1,
            'allow_emola' => $page['allow_emola'] ?? 0,
            'allow_visa' => $page['allow_visa'] ?? 0,
            'allow_paypal' => $page['allow_paypal'] ?? 0,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    private function registerAudit(
        string $action,
        int $pageId,
        array $context,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        try {
            $this->auditLogs->create([
                'actor_user_id' => $context['actor_user_id'] ?? null,
                'actor_role' => $context['actor_role'] ?? null,
                'action' => $action,
                'entity_type' => 'payment_page',
                'entity_id' => $pageId,
                'old_values' => $oldValues !== null
                    ? json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'new_values' => $newValues !== null
                    ? json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'ip_address' => $context['ip_address'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
                'request_id' => $context['request_id'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to persist payment page audit log', [
                'action' => $action,
                'page_id' => $pageId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
