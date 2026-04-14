<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Repositories\PaymentPageRepository;
use App\Utils\Sanitizer;
use App\Utils\Uuid;
use App\Utils\Validator;
use RuntimeException;

final class CheckoutService
{
    public function __construct(
        private readonly PaymentPageRepository $pages,
        private readonly OrderRepository $orders,
        private readonly Sanitizer $sanitizer,
        private readonly Validator $validator,
        private readonly Uuid $uuid,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function createPendingOrder(
        string $pageSlug,
        string $customerPhone,
        ?string $customerName = null,
        ?string $customerEmail = null,
        array $context = [],
        array $customerProfile = [],
        ?string $selectedGateway = null
    ): array
    {
        $page = $this->pages->findActiveBySlug($this->sanitizer->string($pageSlug, 180));
        if ($page === null) {
            throw new RuntimeException('Payment page not found or inactive.');
        }

        return $this->createPendingFromPage(
            $page,
            $customerPhone,
            $customerName,
            $customerEmail,
            $context,
            $customerProfile,
            $selectedGateway
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function createPendingOrderByPageId(
        int $pageId,
        string $customerPhone,
        ?string $customerName = null,
        ?string $customerEmail = null,
        array $context = [],
        array $customerProfile = [],
        ?string $selectedGateway = null
    ): array {
        $page = $this->pages->findById($pageId);
        if ($page === null || (string) ($page['status'] ?? '') !== 'active') {
            throw new RuntimeException('Payment page not found or inactive.');
        }

        return $this->createPendingFromPage(
            $page,
            $customerPhone,
            $customerName,
            $customerEmail,
            $context,
            $customerProfile,
            $selectedGateway
        );
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function createPendingFromPage(
        array $page,
        string $customerPhone,
        ?string $customerName,
        ?string $customerEmail,
        array $context,
        array $customerProfile = [],
        ?string $selectedGateway = null
    ): array {
        $phone = $this->sanitizer->phone($customerPhone);
        $length = strlen($phone);
        if ($length < 9 || $length > 15) {
            throw new RuntimeException('Invalid customer phone.');
        }

        $name = $customerName !== null
            ? $this->sanitizer->string($customerName, 160)
            : '';
        $email = $customerEmail !== null
            ? $this->sanitizer->email($customerEmail)
            : '';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid customer email.');
        }

        $country = $this->sanitizer->string(
            $customerProfile['country'] ?? $customerProfile['customer_country'] ?? '',
            80
        );
        $city = $this->sanitizer->string(
            $customerProfile['city'] ?? $customerProfile['customer_city'] ?? '',
            120
        );
        $address = $this->sanitizer->string(
            $customerProfile['address'] ?? $customerProfile['customer_address'] ?? '',
            255
        );
        $notes = $this->sanitizer->string(
            $customerProfile['notes'] ?? $customerProfile['customer_notes'] ?? '',
            500
        );
        $gateway = strtolower($this->sanitizer->string(
            $selectedGateway ?? (string) ($customerProfile['selected_gateway'] ?? ''),
            40
        ));
        if ($gateway === '') {
            $gateway = 'mpesa';
        }

        $orderNo = 'PEDIDO-' . gmdate('YmdHis') . '-' . strtoupper(substr($this->uuid->v4(), 0, 8));
        $orderContext = (string) ($context['order_context'] ?? 'standard');
        $parentOrderId = (int) ($context['parent_order_id'] ?? 0);
        $funnelSessionToken = trim((string) ($context['funnel_session_token'] ?? ''));
        $fingerprint = implode('|', [
            (string) ($page['id'] ?? ''),
            $phone,
            $email,
            $orderContext,
            $funnelSessionToken,
            (string) $parentOrderId,
            gmdate('YmdHi'),
        ]);
        $idempotencyKey = hash('sha256', $orderNo . '|' . $fingerprint . '|' . $this->uuid->v4());
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 1800);

        $orderId = $this->orders->createPending([
            'order_no' => $orderNo,
            'payment_page_id' => (int) $page['id'],
            'product_id' => (int) $page['product_id'],
            'reseller_id' => (int) $page['reseller_id'],
            'customer_name' => $name !== '' ? $name : null,
            'customer_email' => $email !== '' ? $email : null,
            'customer_phone' => $phone,
            'customer_country' => $country !== '' ? $country : null,
            'customer_city' => $city !== '' ? $city : null,
            'customer_address' => $address !== '' ? $address : null,
            'customer_notes' => $notes !== '' ? $notes : null,
            'selected_gateway' => $gateway,
            'parent_order_id' => $parentOrderId > 0 ? $parentOrderId : null,
            'order_context' => $orderContext,
            'funnel_session_token' => $funnelSessionToken !== '' ? $funnelSessionToken : null,
            'amount' => (float) $page['product_price'],
            'currency' => (string) $page['currency'],
            'idempotency_key' => $idempotencyKey,
            'expires_at' => $expiresAt,
        ]);

        $order = $this->orders->findById($orderId);
        if ($order === null) {
            throw new RuntimeException('Failed to create order.');
        }

        return $order;
    }

    public function markOrderPaid(int $orderId): void
    {
        $this->orders->markStatus($orderId, 'paid');
    }

    public function markOrderFailed(int $orderId): void
    {
        $this->orders->markStatus($orderId, 'failed');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOrderStatus(string $orderNo): ?array
    {
        $orderNo = $this->sanitizer->string($orderNo, 64);
        if ($orderNo === '') {
            return null;
        }

        return $this->orders->findCheckoutSnapshotByOrderNo($orderNo);
    }
}
