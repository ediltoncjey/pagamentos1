<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FunnelOrderRepository;
use App\Repositories\FunnelRepository;
use App\Repositories\FunnelSessionRepository;
use App\Repositories\FunnelStepRepository;
use App\Repositories\OrderRepository;
use App\Utils\Logger;
use App\Utils\Sanitizer;
use App\Utils\Uuid;
use RuntimeException;

final class FunnelService
{
    public function __construct(
        private readonly FunnelRepository $funnels,
        private readonly FunnelStepRepository $steps,
        private readonly FunnelSessionRepository $sessions,
        private readonly FunnelOrderRepository $funnelOrders,
        private readonly OrderRepository $orders,
        private readonly CheckoutService $checkout,
        private readonly PaymentService $payments,
        private readonly LedgerService $ledger,
        private readonly DownloadService $downloads,
        private readonly EmailService $emails,
        private readonly Sanitizer $sanitizer,
        private readonly Uuid $uuid,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForReseller(int $resellerId): array
    {
        $items = $this->funnels->listByReseller($resellerId);
        foreach ($items as &$item) {
            $funnelId = (int) ($item['id'] ?? 0);
            $item['steps'] = $funnelId > 0 ? $this->steps->listByFunnel($funnelId, false) : [];
        }
        unset($item);

        return $items;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createFunnel(int $resellerId, array $input): array
    {
        $name = $this->sanitizer->string($input['name'] ?? '', 180);
        $description = $this->sanitizer->string($input['description'] ?? '', 3000);
        $status = strtolower($this->sanitizer->string($input['status'] ?? 'active', 10));
        if ($name === '') {
            throw new RuntimeException('Nome do funil e obrigatorio.');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'inactive';
        }

        $slugInput = $this->sanitizer->string($input['slug'] ?? $name, 190);
        $slug = $this->ensureUniqueSlug($this->slugify($slugInput));

        $id = $this->funnels->create([
            'reseller_id' => $resellerId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description !== '' ? $description : null,
            'status' => $status,
        ]);

        $created = $this->funnels->findByIdAndReseller($id, $resellerId);
        if ($created === null) {
            throw new RuntimeException('Falha ao criar funil.');
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateFunnel(int $funnelId, int $resellerId, array $input): array
    {
        $existing = $this->funnels->findByIdAndReseller($funnelId, $resellerId);
        if ($existing === null) {
            throw new RuntimeException('Funil nao encontrado.');
        }

        $name = $this->sanitizer->string($input['name'] ?? ($existing['name'] ?? ''), 180);
        $description = $this->sanitizer->string($input['description'] ?? ($existing['description'] ?? ''), 3000);
        $status = strtolower($this->sanitizer->string($input['status'] ?? ($existing['status'] ?? 'active'), 10));
        if ($name === '') {
            throw new RuntimeException('Nome do funil e obrigatorio.');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'inactive';
        }

        $slugInput = $this->sanitizer->string($input['slug'] ?? ($existing['slug'] ?? $name), 190);
        $slug = $this->ensureUniqueSlug($this->slugify($slugInput), $funnelId);

        $this->funnels->updateByIdAndReseller($funnelId, $resellerId, [
            'name' => $name,
            'slug' => $slug,
            'description' => $description !== '' ? $description : null,
            'status' => $status,
        ]);

        $updated = $this->funnels->findByIdAndReseller($funnelId, $resellerId);
        if ($updated === null) {
            throw new RuntimeException('Falha ao atualizar funil.');
        }

        return $updated;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveStep(int $funnelId, int $resellerId, ?int $stepId, array $input): array
    {
        $funnel = $this->funnels->findByIdAndReseller($funnelId, $resellerId);
        if ($funnel === null) {
            throw new RuntimeException('Funil nao encontrado.');
        }

        $stepType = strtolower($this->sanitizer->string($input['step_type'] ?? '', 30));
        $allowedTypes = ['landing', 'checkout', 'confirmation', 'upsell', 'downsell', 'thank_you'];
        if (!in_array($stepType, $allowedTypes, true)) {
            throw new RuntimeException('Tipo de etapa invalido.');
        }

        $title = $this->sanitizer->string($input['title'] ?? '', 190);
        if ($title === '') {
            throw new RuntimeException('Titulo da etapa e obrigatorio.');
        }

        $description = $this->sanitizer->string($input['description'] ?? '', 3000);
        $paymentPageId = (int) ($input['payment_page_id'] ?? 0);
        if ($paymentPageId <= 0) {
            $paymentPageId = null;
        }
        $productId = (int) ($input['product_id'] ?? 0);
        if ($productId <= 0) {
            $productId = null;
        }

        $sequenceNo = max(1, (int) ($input['sequence_no'] ?? 1));
        $isActive = $this->boolInput($input['is_active'] ?? 1) ? 1 : 0;
        $acceptLabel = $this->sanitizer->string($input['accept_label'] ?? '', 90);
        $rejectLabel = $this->sanitizer->string($input['reject_label'] ?? '', 90);

        $payload = [
            'funnel_id' => $funnelId,
            'step_type' => $stepType,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'payment_page_id' => $paymentPageId,
            'product_id' => $productId,
            'sequence_no' => $sequenceNo,
            'is_active' => $isActive,
            'accept_label' => $acceptLabel !== '' ? $acceptLabel : null,
            'reject_label' => $rejectLabel !== '' ? $rejectLabel : null,
        ];

        if ($stepId === null) {
            $stepId = $this->steps->create($payload);
        } else {
            $existing = $this->steps->findByIdInFunnel($stepId, $funnelId);
            if ($existing === null) {
                throw new RuntimeException('Etapa nao encontrada.');
            }
            $this->steps->updateById($stepId, $payload);
        }

        $saved = $this->steps->findById($stepId);
        if ($saved === null) {
            throw new RuntimeException('Falha ao guardar etapa.');
        }

        return $saved;
    }

    public function deleteStep(int $funnelId, int $resellerId, int $stepId): void
    {
        $funnel = $this->funnels->findByIdAndReseller($funnelId, $resellerId);
        if ($funnel === null) {
            throw new RuntimeException('Funil nao encontrado.');
        }

        $existing = $this->steps->findByIdInFunnel($stepId, $funnelId);
        if ($existing === null) {
            throw new RuntimeException('Etapa nao encontrada.');
        }

        $this->steps->deleteById($stepId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPublicContext(string $slug, ?string $sessionToken = null): array
    {
        $funnel = $this->funnels->findActiveBySlug($this->sanitizer->string($slug, 190));
        if ($funnel === null) {
            throw new RuntimeException('Funil nao encontrado ou inativo.');
        }

        $steps = $this->steps->listByFunnel((int) $funnel['id'], true);
        if ($steps === []) {
            throw new RuntimeException('Funil sem etapas ativas.');
        }

        $session = $this->loadOrCreateSession($funnel, $steps, $sessionToken);
        $currentStep = $this->resolveCurrentStep($steps, (int) ($session['current_step_id'] ?? 0));

        return [
            'funnel' => $funnel,
            'steps' => $steps,
            'session' => $session,
            'current_step' => $currentStep,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function runBaseCheckout(string $slug, string $sessionToken, array $input): array
    {
        $context = $this->getPublicContext($slug, $sessionToken);
        $funnel = (array) $context['funnel'];
        $session = (array) $context['session'];
        $checkoutStep = $this->steps->findFirstActiveByType((int) $funnel['id'], 'checkout');
        if ($checkoutStep === null) {
            throw new RuntimeException('Etapa de checkout nao configurada.');
        }

        $paymentPageId = (int) ($checkoutStep['payment_page_id'] ?? 0);
        if ($paymentPageId <= 0) {
            throw new RuntimeException('Etapa de checkout sem pagina de pagamento associada.');
        }

        $customerPhone = $this->sanitizer->phone($input['customer_phone'] ?? ($session['customer_phone'] ?? ''));
        if ($customerPhone === '') {
            throw new RuntimeException('Telefone do cliente e obrigatorio.');
        }

        $customerName = $this->sanitizer->string($input['customer_name'] ?? ($session['customer_name'] ?? ''), 160);
        $customerEmail = $this->sanitizer->email($input['customer_email'] ?? ($session['customer_email'] ?? ''));
        if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Email do cliente invalido.');
        }
        $metadata = $this->metadataFromSession($session);
        $customerCountry = $this->sanitizer->string($input['customer_country'] ?? ($metadata['customer_country'] ?? ''), 80);
        $customerCity = $this->sanitizer->string($input['customer_city'] ?? ($metadata['customer_city'] ?? ''), 120);
        $customerAddress = $this->sanitizer->string($input['customer_address'] ?? ($metadata['customer_address'] ?? ''), 255);
        $customerNotes = $this->sanitizer->string($input['customer_notes'] ?? ($metadata['customer_notes'] ?? ''), 500);
        $selectedGateway = strtolower($this->sanitizer->string(
            $input['payment_method'] ?? ($metadata['selected_gateway'] ?? ''),
            40
        ));
        if ($selectedGateway === '') {
            $selectedGateway = 'mpesa';
        }

        $token = (string) ($session['token'] ?? $sessionToken);
        $order = $this->checkout->createPendingOrderByPageId(
            pageId: $paymentPageId,
            customerPhone: $customerPhone,
            customerName: $customerName !== '' ? $customerName : null,
            customerEmail: $customerEmail !== '' ? $customerEmail : null,
            context: [
                'order_context' => 'funnel_base',
                'funnel_session_token' => $token,
                'parent_order_id' => null,
            ],
            customerProfile: [
                'country' => $customerCountry,
                'city' => $customerCity,
                'address' => $customerAddress,
                'notes' => $customerNotes,
            ],
            selectedGateway: $selectedGateway
        );

        $this->sessions->updateByToken($token, [
            'customer_name' => $customerName !== '' ? $customerName : null,
            'customer_email' => $customerEmail !== '' ? $customerEmail : null,
            'customer_phone' => $customerPhone,
            'last_order_id' => (int) $order['id'],
            'base_order_id' => (int) $order['id'],
            'current_step_id' => (int) $checkoutStep['id'],
            'metadata' => json_encode([
                'customer_country' => $customerCountry,
                'customer_city' => $customerCity,
                'customer_address' => $customerAddress,
                'customer_notes' => $customerNotes,
                'selected_gateway' => $selectedGateway,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $payment = $this->payments->initiatePayment((int) $order['id']);
        $nextUrl = null;
        if ((string) ($payment['provider_status'] ?? '') === 'confirmed') {
            $flow = $this->processConfirmedOrder((int) $order['id']);
            $nextUrl = $flow['next_url'] ?? null;
        }

        return [
            'session_token' => $token,
            'order' => $order,
            'payment' => $payment,
            'status_url' => '/funnel/status/' . rawurlencode((string) $order['order_no']),
            'next_url' => $nextUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function runOfferAction(string $slug, string $sessionToken, string $stepType, string $decision): array
    {
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['accept', 'reject'], true)) {
            throw new RuntimeException('Decisao invalida.');
        }

        $context = $this->getPublicContext($slug, $sessionToken);
        $funnel = (array) $context['funnel'];
        $session = (array) $context['session'];
        $token = (string) $session['token'];

        $stepType = strtolower(trim($stepType));
        if (!in_array($stepType, ['upsell', 'downsell'], true)) {
            throw new RuntimeException('Tipo de oferta invalido.');
        }

        $step = $this->steps->findFirstActiveByType((int) $funnel['id'], $stepType);
        if ($step === null) {
            throw new RuntimeException('Etapa de oferta nao encontrada.');
        }

        if ($decision === 'reject') {
            if ($stepType === 'upsell') {
                $downsell = $this->steps->findFirstActiveByType((int) $funnel['id'], 'downsell');
                if ($downsell !== null) {
                    $this->sessions->updateByToken($token, ['current_step_id' => (int) $downsell['id']]);
                    return [
                        'decision' => 'reject',
                        'redirect_url' => $this->funnelUrl((string) $funnel['slug'], $token),
                    ];
                }
            }

            $final = $this->resolveFinalStep((int) $funnel['id']);
            $this->sessions->updateByToken($token, [
                'current_step_id' => $final !== null ? (int) $final['id'] : null,
                'status' => $final === null ? 'completed' : 'active',
            ]);

            return [
                'decision' => 'reject',
                'redirect_url' => $this->funnelUrl((string) $funnel['slug'], $token),
            ];
        }

        $paymentPageId = (int) ($step['payment_page_id'] ?? 0);
        if ($paymentPageId <= 0) {
            throw new RuntimeException('Etapa sem pagina de pagamento associada.');
        }

        $customerPhone = $this->sanitizer->phone($session['customer_phone'] ?? '');
        if ($customerPhone === '') {
            throw new RuntimeException('Sessao sem telefone do cliente.');
        }
        $metadata = $this->metadataFromSession($session);
        $selectedGateway = strtolower($this->sanitizer->string((string) ($metadata['selected_gateway'] ?? 'mpesa'), 40));
        if ($selectedGateway === '') {
            $selectedGateway = 'mpesa';
        }

        $orderContext = $stepType === 'upsell' ? 'funnel_upsell' : 'funnel_downsell';
        $parentOrderId = (int) ($session['last_order_id'] ?? $session['base_order_id'] ?? 0);
        $order = $this->checkout->createPendingOrderByPageId(
            pageId: $paymentPageId,
            customerPhone: $customerPhone,
            customerName: (string) ($session['customer_name'] ?? ''),
            customerEmail: (string) ($session['customer_email'] ?? ''),
            context: [
                'order_context' => $orderContext,
                'funnel_session_token' => $token,
                'parent_order_id' => $parentOrderId > 0 ? $parentOrderId : null,
            ],
            customerProfile: [
                'country' => (string) ($metadata['customer_country'] ?? ''),
                'city' => (string) ($metadata['customer_city'] ?? ''),
                'address' => (string) ($metadata['customer_address'] ?? ''),
                'notes' => (string) ($metadata['customer_notes'] ?? ''),
            ],
            selectedGateway: $selectedGateway
        );

        $this->sessions->updateByToken($token, [
            'current_step_id' => (int) $step['id'],
            'last_order_id' => (int) $order['id'],
        ]);

        $payment = $this->payments->initiatePayment((int) $order['id']);
        $nextUrl = null;
        if ((string) ($payment['provider_status'] ?? '') === 'confirmed') {
            $flow = $this->processConfirmedOrder((int) $order['id']);
            $nextUrl = $flow['next_url'] ?? null;
        }

        return [
            'decision' => 'accept',
            'order' => $order,
            'payment' => $payment,
            'status_url' => '/funnel/status/' . rawurlencode((string) $order['order_no']),
            'next_url' => $nextUrl,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function processConfirmedOrder(int $orderId): ?array
    {
        $order = $this->orders->findById($orderId);
        if ($order === null || (string) ($order['status'] ?? '') !== 'paid') {
            return null;
        }

        try {
            $this->ledger->recordCommission($orderId);
            $this->downloads->issueDownloadToken($orderId);
        } catch (\Throwable $exception) {
            $this->logger->warning('Funnel post-processing warning', [
                'order_id' => $orderId,
                'error' => $exception->getMessage(),
            ]);
        }

        $funnelToken = trim((string) ($order['funnel_session_token'] ?? ''));
        if ($funnelToken !== '') {
            $this->processFunnelStepAfterPaid($order);
        }

        $nextUpsellUrl = null;
        if ($funnelToken !== '') {
            $session = $this->sessions->findByToken($funnelToken);
            if (is_array($session)) {
                $funnel = $this->funnels->findById((int) ($session['funnel_id'] ?? 0));
                if (is_array($funnel)) {
                    $upsell = $this->steps->findFirstActiveByType((int) $funnel['id'], 'upsell');
                    if ($upsell !== null) {
                        $nextUpsellUrl = $this->funnelUrl((string) $funnel['slug'], $funnelToken);
                    }
                }
            }
        }

        try {
            $this->emails->sendPostPurchaseBundle($orderId, $nextUpsellUrl);
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to send post-purchase emails', [
                'order_id' => $orderId,
                'error' => $exception->getMessage(),
            ]);
        }

        if ($funnelToken === '') {
            return null;
        }

        $session = $this->sessions->findByToken($funnelToken);
        if ($session === null) {
            return null;
        }
        $funnel = $this->funnels->findById((int) ($session['funnel_id'] ?? 0));
        if ($funnel === null) {
            return null;
        }

        return [
            'session_token' => $funnelToken,
            'next_url' => $this->funnelUrl((string) $funnel['slug'], $funnelToken),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function statusByOrderNo(string $orderNo): ?array
    {
        $snapshot = $this->checkout->getOrderStatus($orderNo);
        if ($snapshot === null) {
            return null;
        }

        $isPaid = (string) ($snapshot['status'] ?? '') === 'paid' || (string) ($snapshot['payment_status'] ?? '') === 'confirmed';
        $nextUrl = null;
        if ($isPaid) {
            $flow = $this->processConfirmedOrder((int) $snapshot['id']);
            $nextUrl = $flow['next_url'] ?? null;
        }

        return [
            'snapshot' => $snapshot,
            'is_paid' => $isPaid,
            'next_url' => $nextUrl,
        ];
    }

    /**
     * @param array<string, mixed> $funnel
     * @param array<int, array<string, mixed>> $steps
     * @return array<string, mixed>
     */
    private function loadOrCreateSession(array $funnel, array $steps, ?string $sessionToken): array
    {
        $token = trim((string) $sessionToken);
        if ($token !== '') {
            $existing = $this->sessions->findByToken($token);
            if (
                $existing !== null
                && (int) ($existing['funnel_id'] ?? 0) === (int) $funnel['id']
            ) {
                return $existing;
            }
        }

        $firstStep = $this->resolveCurrentStep($steps, 0);
        $token = hash('sha256', $this->uuid->v4() . '|' . (string) $funnel['slug'] . '|' . microtime(true));
        $sessionId = $this->sessions->create([
            'token' => $token,
            'funnel_id' => (int) $funnel['id'],
            'current_step_id' => $firstStep !== null ? (int) $firstStep['id'] : null,
            'status' => 'active',
            'metadata' => '{}',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);

        $created = $this->sessions->findByToken($token);
        if ($created === null) {
            throw new RuntimeException('Falha ao abrir sessao do funil.');
        }

        $created['id'] = $sessionId;
        return $created;
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     * @return array<string, mixed>|null
     */
    private function resolveCurrentStep(array $steps, int $currentStepId): ?array
    {
        if ($currentStepId > 0) {
            foreach ($steps as $step) {
                if ((int) ($step['id'] ?? 0) === $currentStepId) {
                    return $step;
                }
            }
        }

        return $steps[0] ?? null;
    }

    /**
     * @param array<string, mixed> $order
     */
    private function processFunnelStepAfterPaid(array $order): void
    {
        $token = trim((string) ($order['funnel_session_token'] ?? ''));
        if ($token === '') {
            return;
        }

        $session = $this->sessions->findByToken($token);
        if ($session === null) {
            return;
        }

        $funnelId = (int) ($session['funnel_id'] ?? 0);
        if ($funnelId <= 0) {
            return;
        }

        $existingFunnelOrder = $this->funnelOrders->findByOrderId((int) $order['id']);
        if ($existingFunnelOrder === null) {
            $this->funnelOrders->create([
                'funnel_session_id' => (int) $session['id'],
                'funnel_step_id' => $session['current_step_id'] ?? null,
                'order_id' => (int) $order['id'],
                'offer_type' => $this->offerTypeFromContext((string) ($order['order_context'] ?? '')),
            ]);
        }

        $nextStep = $this->resolveNextStepAfterPaid($funnelId, (string) ($order['order_context'] ?? ''));
        if ($nextStep !== null) {
            $this->sessions->updateByToken($token, [
                'current_step_id' => (int) $nextStep['id'],
                'last_order_id' => (int) $order['id'],
                'status' => 'active',
            ]);
            return;
        }

        $this->sessions->updateByToken($token, [
            'current_step_id' => null,
            'last_order_id' => (int) $order['id'],
            'status' => 'completed',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveNextStepAfterPaid(int $funnelId, string $orderContext): ?array
    {
        $orderContext = strtolower(trim($orderContext));
        if ($orderContext === 'funnel_base') {
            $confirmation = $this->steps->findFirstActiveByType($funnelId, 'confirmation');
            if ($confirmation !== null) {
                return $confirmation;
            }

            $upsell = $this->steps->findFirstActiveByType($funnelId, 'upsell');
            if ($upsell !== null) {
                return $upsell;
            }

            return $this->resolveFinalStep($funnelId);
        }

        if (in_array($orderContext, ['funnel_upsell', 'funnel_downsell'], true)) {
            return $this->resolveFinalStep($funnelId);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveFinalStep(int $funnelId): ?array
    {
        $thankYou = $this->steps->findFirstActiveByType($funnelId, 'thank_you');
        if ($thankYou !== null) {
            return $thankYou;
        }

        return $this->steps->findFirstActiveByType($funnelId, 'confirmation');
    }

    private function offerTypeFromContext(string $context): string
    {
        $context = strtolower(trim($context));
        return match ($context) {
            'funnel_upsell' => 'upsell',
            'funnel_downsell' => 'downsell',
            default => 'base',
        };
    }

    private function funnelUrl(string $slug, string $token): string
    {
        return '/f/' . rawurlencode($slug) . '?token=' . rawurlencode($token);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
        $value = trim($value, '-');
        if ($value === '') {
            $value = 'funnel-' . substr(bin2hex(random_bytes(6)), 0, 10);
        }

        return substr($value, 0, 190);
    }

    private function ensureUniqueSlug(string $baseSlug, ?int $currentFunnelId = null): string
    {
        $candidate = $baseSlug;
        $attempt = 1;

        while (true) {
            $found = $this->funnels->findBySlug($candidate);
            if ($found === null) {
                return $candidate;
            }

            if ($currentFunnelId !== null && (int) ($found['id'] ?? 0) === $currentFunnelId) {
                return $candidate;
            }

            $attempt++;
            $suffix = '-' . $attempt;
            $candidate = substr($baseSlug, 0, max(1, 190 - strlen($suffix))) . $suffix;
        }
    }

    private function boolInput(mixed $value): bool
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function metadataFromSession(array $session): array
    {
        $decoded = json_decode((string) ($session['metadata'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }
}
