<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\AccountController;
use App\Controllers\NotificationController;
use App\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Controllers\Admin\LedgerController as AdminLedgerController;
use App\Controllers\Admin\ReportController as AdminReportController;
use App\Controllers\Admin\UserManagementController;
use App\Controllers\Auth\AuthController;
use App\Middlewares\AuthMiddleware;
use App\Middlewares\RoleMiddleware;
use App\Controllers\Public\PaymentPageController as PublicPaymentPageController;
use App\Controllers\Public\DeliveryController as PublicDeliveryController;
use App\Controllers\Reseller\DashboardController as ResellerDashboardController;
use App\Controllers\Reseller\EmailTemplateController as ResellerEmailTemplateController;
use App\Controllers\Reseller\FunnelController as ResellerFunnelController;
use App\Controllers\Reseller\OperationsController as ResellerOperationsController;
use App\Controllers\Reseller\PaymentPageController as ResellerPaymentPageController;
use App\Controllers\Reseller\ProductController as ResellerProductController;
use App\Controllers\Reseller\ReportController as ResellerReportController;
use App\Controllers\Reseller\WalletController as ResellerWalletController;
use App\Repositories\AuditLogRepository;
use App\Services\FunnelService;
use App\Services\PaymentService;
use App\Utils\Container;
use App\Utils\Env;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Router;

return static function (Router $router): void {
    $router->get('/api/health', [HealthController::class, 'index']);
    $router->get('/api/checkout/status/{order_no}', [PublicPaymentPageController::class, 'orderStatus']);
    $router->get('/api/downloads/{token}', [PublicDeliveryController::class, 'status']);
    $router->post('/api/auth/login', [AuthController::class, 'login']);
    $router->post('/api/auth/register', [AuthController::class, 'register']);
    $router->post('/api/auth/logout', [AuthController::class, 'logout'], [AuthMiddleware::class]);
    $router->get('/api/auth/me', [AuthController::class, 'me'], [AuthMiddleware::class]);
    $router->get('/api/notifications', [NotificationController::class, 'index'], [AuthMiddleware::class]);
    $router->post('/api/notifications/read', [NotificationController::class, 'markRead'], [AuthMiddleware::class]);

    $router->get('/api/account/profile', [AccountController::class, 'profile'], [AuthMiddleware::class]);
    $router->post('/api/account/profile', [AccountController::class, 'updateProfile'], [AuthMiddleware::class]);
    $router->get('/api/account/security', [AccountController::class, 'security'], [AuthMiddleware::class]);
    $router->post('/api/account/security', [AccountController::class, 'updateSecurity'], [AuthMiddleware::class]);
    $router->get('/api/account/preferences', [AccountController::class, 'preferences'], [AuthMiddleware::class]);
    $router->post('/api/account/preferences', [AccountController::class, 'updatePreferences'], [AuthMiddleware::class]);

    $router->post('/api/payments/callback', static function (Request $request, Container $container): Response {
        $callbackEnabled = filter_var(Env::get('PAYMENT_ENABLE_CALLBACK', true), FILTER_VALIDATE_BOOL);
        if ($callbackEnabled === false) {
            return Response::json([
                'processed' => false,
                'reason' => 'callback-disabled',
            ], 503);
        }

        $config = (array) $container->make('config');
        $securityConfig = (array) ($config['security'] ?? []);
        $callbackSecurity = (array) ($securityConfig['payment_callback'] ?? []);
        $signatureHeader = trim((string) ($callbackSecurity['signature_header'] ?? 'X-Signature'));
        if ($signatureHeader === '') {
            $signatureHeader = 'X-Signature';
        }

        $callbackSecret = trim((string) ($callbackSecurity['secret'] ?? ''));
        $auditCallbackRequest = static function (string $action, array $details = []) use ($container, $request): void {
            try {
                /** @var AuditLogRepository $auditLogs */
                $auditLogs = $container->make(AuditLogRepository::class);
                $auditLogs->create([
                    'actor_user_id' => null,
                    'actor_role' => 'provider',
                    'action' => $action,
                    'entity_type' => 'payment_callback',
                    'entity_id' => null,
                    'new_values' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'request_id' => (string) $request->header('X-Request-Id', ''),
                ]);
            } catch (\Throwable) {
            }
        };

        if ($callbackSecret !== '') {
            $providedSignature = trim((string) $request->header($signatureHeader, ''));
            if ($providedSignature === '') {
                $auditCallbackRequest('payment.callback_signature_missing', [
                    'header' => $signatureHeader,
                    'path' => $request->path(),
                ]);
                return Response::json([
                    'processed' => false,
                    'reason' => 'invalid-signature',
                ], 401);
            }

            $normalizedProvided = strtolower($providedSignature);
            if (str_starts_with($normalizedProvided, 'sha256=')) {
                $normalizedProvided = substr($normalizedProvided, 7);
            }

            $expectedSignature = strtolower(hash_hmac('sha256', $request->rawBody(), $callbackSecret));
            if (!hash_equals($expectedSignature, $normalizedProvided)) {
                $auditCallbackRequest('payment.callback_signature_invalid', [
                    'header' => $signatureHeader,
                    'path' => $request->path(),
                ]);
                return Response::json([
                    'processed' => false,
                    'reason' => 'invalid-signature',
                ], 401);
            }
        }

        /** @var PaymentService $payments */
        $payments = $container->make(PaymentService::class);
        /** @var FunnelService $funnels */
        $funnels = $container->make(FunnelService::class);

        $result = $payments->handleCallback($request->body());
        $postProcessingErrors = [];

        if (($result['processed'] ?? false) === true && ($result['status'] ?? null) === 'confirmed') {
            $orderId = (int) ($result['order_id'] ?? 0);
            if ($orderId > 0) {
                try {
                    $funnels->processConfirmedOrder($orderId);
                } catch (\Throwable $exception) {
                    $postProcessingErrors[] = $exception->getMessage();
                }
            }
        }

        if ($postProcessingErrors !== []) {
            $result['post_processing_errors'] = $postProcessingErrors;
        }

        return Response::json($result, 200);
    });

    $router->post('/api/payments/poll', static function (Request $request, Container $container): Response {
        $expectedToken = trim((string) Env::get('PAYMENT_POLL_SECRET', ''));
        if ($expectedToken !== '') {
            $providedToken = trim((string) $request->header('X-Poll-Token', ''));
            if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
                return Response::json(['error' => 'Unauthorized poll request'], 401);
            }
        }

        /** @var PaymentService $payments */
        $payments = $container->make(PaymentService::class);
        /** @var FunnelService $funnels */
        $funnels = $container->make(FunnelService::class);

        $result = $payments->pollPendingPayments();
        $postProcessingErrors = [];
        $confirmed = $result['confirmed_order_ids'] ?? [];
        if (is_array($confirmed)) {
            foreach ($confirmed as $orderId) {
                $orderId = (int) $orderId;
                if ($orderId <= 0) {
                    continue;
                }

                try {
                    $funnels->processConfirmedOrder($orderId);
                } catch (\Throwable $exception) {
                    $postProcessingErrors[] = [
                        'order_id' => $orderId,
                        'error' => $exception->getMessage(),
                    ];
                }
            }
        }

        if ($postProcessingErrors !== []) {
            $result['post_processing_errors'] = $postProcessingErrors;
        }

        return Response::json($result);
    });

    $router->get('/api/admin/users', [UserManagementController::class, 'index'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->post('/api/admin/users', [UserManagementController::class, 'store'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/api/admin/dashboard', [AdminDashboardController::class, 'index'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/api/admin/reports/summary', [AdminReportController::class, 'summary'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/api/admin/reports/monthly', [AdminReportController::class, 'monthly'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/api/admin/reports/top-resellers', [AdminReportController::class, 'topResellers'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/api/admin/reports/monthly-detail', [AdminReportController::class, 'monthlyDetail'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/api/admin/reports/export', [AdminReportController::class, 'export'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/api/admin/ledger/pending-commissions', [AdminLedgerController::class, 'pendingCommissions'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->post('/api/admin/ledger/commissions/{id}/settle', [AdminLedgerController::class, 'settleCommission'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->post('/api/admin/ledger/reconcile', [AdminLedgerController::class, 'reconcilePending'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/products', [ResellerProductController::class, 'index'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/products/{id}', [ResellerProductController::class, 'show'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->post('/api/reseller/products', [ResellerProductController::class, 'store'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->post('/api/reseller/products/{id}/update', [ResellerProductController::class, 'update'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->post('/api/reseller/products/{id}/toggle', [ResellerProductController::class, 'toggleStatus'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/dashboard', [ResellerDashboardController::class, 'index'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/earnings', [ResellerOperationsController::class, 'earnings'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/wallet', [ResellerWalletController::class, 'overview'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/wallet/transactions', [ResellerWalletController::class, 'transactions'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/commissions', [ResellerWalletController::class, 'commissions'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/reports/summary', [ResellerReportController::class, 'summary'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/reports/monthly', [ResellerReportController::class, 'monthly'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/reports/history', [ResellerReportController::class, 'history'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/reports/export', [ResellerReportController::class, 'export'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/payment-pages', [ResellerPaymentPageController::class, 'index'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/payment-pages/{id}', [ResellerPaymentPageController::class, 'show'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->post('/api/reseller/payment-pages', [ResellerPaymentPageController::class, 'store'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->post('/api/reseller/payment-pages/{id}/update', [ResellerPaymentPageController::class, 'update'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->post('/api/reseller/payment-pages/{id}/toggle', [ResellerPaymentPageController::class, 'toggleStatus'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/funnels', [ResellerFunnelController::class, 'index'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);
    $router->post('/api/reseller/funnels', [ResellerFunnelController::class, 'saveFunnel'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);
    $router->post('/api/reseller/funnels/{id}/steps', [ResellerFunnelController::class, 'saveStep'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);
    $router->post('/api/reseller/funnels/{id}/steps/{step_id}/delete', [ResellerFunnelController::class, 'deleteStep'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/api/reseller/email-templates', [ResellerEmailTemplateController::class, 'index'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);
    $router->post('/api/reseller/email-templates/{type}', [ResellerEmailTemplateController::class, 'saveTemplate'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);
    $router->post('/api/reseller/email-logs/{id}/resend', [ResellerEmailTemplateController::class, 'resend'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);
};
