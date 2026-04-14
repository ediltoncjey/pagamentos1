<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\NotificationReadRepository;
use App\Repositories\UserSettingsRepository;
use App\Utils\Database;

final class NotificationService
{
    public function __construct(
        private readonly Database $database,
        private readonly NotificationReadRepository $notificationReads,
        private readonly UserSettingsRepository $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listForUser(int $userId, string $role, int $limit = 12): array
    {
        $limit = max(1, min(60, $limit));
        $role = strtolower(trim($role));
        if (!in_array($role, ['admin', 'reseller'], true)) {
            $role = 'reseller';
        }

        $preferences = [
            'notify_sales' => 1,
            'notify_payment_errors' => 1,
            'notify_security' => 1,
            'notify_system' => 1,
        ];
        try {
            $stored = $this->settings->getByUserId($userId);
            $preferences['notify_sales'] = (int) ($stored['notify_sales'] ?? 1);
            $preferences['notify_payment_errors'] = (int) ($stored['notify_payment_errors'] ?? 1);
            $preferences['notify_security'] = (int) ($stored['notify_security'] ?? 1);
            $preferences['notify_system'] = (int) ($stored['notify_system'] ?? 1);
        } catch (\Throwable) {
        }

        $poolLimit = max(20, $limit * 3);
        $items = [];
        if ($preferences['notify_sales'] === 1) {
            $items = array_merge($items, $this->saleNotifications($userId, $role, $poolLimit));
        }
        if ($preferences['notify_payment_errors'] === 1) {
            $items = array_merge($items, $this->paymentErrorNotifications($userId, $role, $poolLimit));
        }
        if ($preferences['notify_security'] === 1) {
            $items = array_merge($items, $this->securityNotifications($userId, $role, $poolLimit));
        }
        if ($preferences['notify_system'] === 1) {
            $items = array_merge($items, $this->systemNotifications($userId, $role, $poolLimit));
        }

        $unique = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            if ($key === '' || isset($unique[$key])) {
                continue;
            }

            $unique[$key] = $item;
        }

        $items = array_values($unique);
        usort($items, static function (array $a, array $b): int {
            $aTs = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $bTs = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
            return $bTs <=> $aTs;
        });

        $items = array_slice($items, 0, $limit);
        $keys = array_values(array_map(
            static fn (array $item): string => (string) ($item['key'] ?? ''),
            $items
        ));
        $keys = array_values(array_filter($keys, static fn (string $key): bool => $key !== ''));
        $readKeys = $this->notificationReads->listReadKeys($userId, $keys);
        $readMap = array_flip($readKeys);

        $unreadCount = 0;
        foreach ($items as &$item) {
            $key = (string) ($item['key'] ?? '');
            $isRead = isset($readMap[$key]);
            $item['is_read'] = $isRead;
            if (!$isRead) {
                $unreadCount++;
            }
        }
        unset($item);

        return [
            'items' => $items,
            'unread_count' => $unreadCount,
            'total_returned' => count($items),
        ];
    }

    public function markRead(int $userId, string $key): void
    {
        $key = trim($key);
        if ($key === '') {
            return;
        }

        $this->notificationReads->markRead($userId, $key);
    }

    /**
     * @param list<string> $keys
     */
    public function markManyRead(int $userId, array $keys): void
    {
        $this->notificationReads->markManyRead($userId, $keys);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function saleNotifications(int $userId, string $role, int $limit): array
    {
        $params = [];
        $where = 'WHERE o.status = "paid"';
        if ($role === 'reseller') {
            $where .= ' AND o.reseller_id = :reseller_id';
            $params['reseller_id'] = $userId;
        }

        $stmt = $this->database->pdo()->prepare(
            'SELECT
                o.id,
                o.order_no,
                o.amount,
                o.currency,
                o.paid_at,
                o.created_at,
                p.name AS product_name
             FROM orders o
             INNER JOIN products p ON p.id = o.product_id
             ' . $where . '
             ORDER BY COALESCE(o.paid_at, o.created_at) DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        $items = [];
        foreach ($rows as $row) {
            $orderId = (int) ($row['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $amount = $this->money((float) ($row['amount'] ?? 0), (string) ($row['currency'] ?? 'MZN'));
            $orderNo = (string) ($row['order_no'] ?? ('#' . $orderId));
            $product = trim((string) ($row['product_name'] ?? 'Produto digital'));

            $items[] = [
                'key' => 'sale:order:' . $orderId,
                'type' => 'sale',
                'severity' => 'success',
                'title' => 'Nova venda confirmada',
                'message' => sprintf('%s vendido por %s (%s).', $product, $amount, $orderNo),
                'created_at' => (string) ($row['paid_at'] ?? $row['created_at'] ?? gmdate('Y-m-d H:i:s')),
                'entity_type' => 'order',
                'entity_id' => $orderId,
                'meta' => [
                    'order_no' => $orderNo,
                    'amount' => $amount,
                ],
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function paymentErrorNotifications(int $userId, string $role, int $limit): array
    {
        $params = [];
        $where = 'WHERE py.status IN ("failed", "timeout")';
        if ($role === 'reseller') {
            $where .= ' AND o.reseller_id = :reseller_id';
            $params['reseller_id'] = $userId;
        }

        $stmt = $this->database->pdo()->prepare(
            'SELECT
                py.id,
                py.status,
                py.last_error,
                py.updated_at,
                py.amount,
                py.currency,
                o.order_no
             FROM payments py
             INNER JOIN orders o ON o.id = py.order_id
             ' . $where . '
             ORDER BY py.updated_at DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        $items = [];
        foreach ($rows as $row) {
            $paymentId = (int) ($row['id'] ?? 0);
            if ($paymentId <= 0) {
                continue;
            }

            $orderNo = (string) ($row['order_no'] ?? '-');
            $status = strtoupper((string) ($row['status'] ?? 'FAILED'));
            $error = trim((string) ($row['last_error'] ?? ''));
            $amount = $this->money((float) ($row['amount'] ?? 0), (string) ($row['currency'] ?? 'MZN'));

            $items[] = [
                'key' => 'payment_error:payment:' . $paymentId,
                'type' => 'payment_error',
                'severity' => 'danger',
                'title' => 'Erro de pagamento',
                'message' => sprintf('%s em %s (%s)%s', $status, $orderNo, $amount, $error !== '' ? ': ' . $error : '.'),
                'created_at' => (string) ($row['updated_at'] ?? gmdate('Y-m-d H:i:s')),
                'entity_type' => 'payment',
                'entity_id' => $paymentId,
                'meta' => [
                    'order_no' => $orderNo,
                    'status' => $status,
                ],
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function securityNotifications(int $userId, string $role, int $limit): array
    {
        $params = [];
        if ($role === 'admin') {
            $where = 'WHERE al.action IN ("auth.login_failed", "auth.login_throttled")';
        } else {
            $where = 'WHERE al.action IN ("auth.login_failed", "auth.login_throttled")
                AND (al.entity_id = :entity_user_id OR al.actor_user_id = :actor_user_id)';
            $params['entity_user_id'] = $userId;
            $params['actor_user_id'] = $userId;
        }

        $stmt = $this->database->pdo()->prepare(
            'SELECT
                al.id,
                al.action,
                al.ip_address,
                al.created_at
             FROM audit_logs al
             ' . $where . '
             ORDER BY al.created_at DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        $items = [];
        foreach ($rows as $row) {
            $auditId = (int) ($row['id'] ?? 0);
            if ($auditId <= 0) {
                continue;
            }

            $action = (string) ($row['action'] ?? 'auth.login_failed');
            $ip = trim((string) ($row['ip_address'] ?? 'desconhecido'));
            $isThrottle = $action === 'auth.login_throttled';

            $items[] = [
                'key' => 'security:audit:' . $auditId,
                'type' => 'security',
                'severity' => 'warning',
                'title' => $isThrottle ? 'Bloqueio por tentativas falhadas' : 'Tentativa de acesso falhada',
                'message' => $isThrottle
                    ? sprintf('Foi aplicado bloqueio temporario de login a partir de %s.', $ip)
                    : sprintf('Falha de autenticacao detectada a partir de %s.', $ip),
                'created_at' => (string) ($row['created_at'] ?? gmdate('Y-m-d H:i:s')),
                'entity_type' => 'audit',
                'entity_id' => $auditId,
                'meta' => [
                    'action' => $action,
                    'ip_address' => $ip,
                ],
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function systemNotifications(int $userId, string $role, int $limit): array
    {
        if ($role !== 'admin') {
            return [];
        }

        $actions = [
            'payment.callback_signature_invalid',
            'payment.callback_signature_missing',
            'ledger.commission_settle_failed',
        ];

        $quoted = implode(',', array_map(
            static fn (string $value): string => '"' . str_replace('"', '\"', $value) . '"',
            $actions
        ));

        $stmt = $this->database->pdo()->prepare(
            'SELECT
                id,
                action,
                created_at
             FROM audit_logs
             WHERE action IN (' . $quoted . ')
             ORDER BY created_at DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $items = [];
        foreach ($rows as $row) {
            $auditId = (int) ($row['id'] ?? 0);
            if ($auditId <= 0) {
                continue;
            }

            $action = (string) ($row['action'] ?? '');
            $message = match ($action) {
                'payment.callback_signature_invalid' => 'Callback de pagamento recebido com assinatura invalida.',
                'payment.callback_signature_missing' => 'Callback de pagamento recebido sem assinatura.',
                'ledger.commission_settle_failed' => 'Falha durante reconciliacao de comissao no ledger.',
                default => 'Alerta operacional do sistema.',
            };

            $items[] = [
                'key' => 'system:audit:' . $auditId,
                'type' => 'system',
                'severity' => 'warning',
                'title' => 'Alerta do sistema',
                'message' => $message,
                'created_at' => (string) ($row['created_at'] ?? gmdate('Y-m-d H:i:s')),
                'entity_type' => 'audit',
                'entity_id' => $auditId,
                'meta' => [
                    'action' => $action,
                ],
            ];
        }

        return $items;
    }

    private function money(float $amount, string $currency): string
    {
        $currency = trim($currency) !== '' ? strtoupper($currency) : 'MZN';
        return $currency . ' ' . number_format($amount, 2, '.', ',');
    }
}
