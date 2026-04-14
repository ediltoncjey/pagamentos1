<?php

declare(strict_types=1);

use App\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Controllers\Admin\OperationsController as AdminOperationsController;
use App\Controllers\Admin\UserManagementController;
use App\Controllers\AccountController;
use App\Controllers\Auth\AuthController;
use App\Controllers\Public\DeliveryController;
use App\Controllers\HealthController;
use App\Controllers\Public\PaymentPageController;
use App\Controllers\Public\FunnelController as PublicFunnelController;
use App\Controllers\Reseller\DashboardController as ResellerDashboardController;
use App\Controllers\Reseller\EmailTemplateController as ResellerEmailTemplateController;
use App\Controllers\Reseller\FunnelController as ResellerFunnelController;
use App\Controllers\Reseller\OperationsController as ResellerOperationsController;
use App\Controllers\Reseller\PaymentPageController as ResellerPaymentPageController;
use App\Controllers\Reseller\ProductController as ResellerProductController;
use App\Middlewares\AuthMiddleware;
use App\Middlewares\RoleMiddleware;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Router;

return static function (Router $router): void {
    $router->get('/', [AuthController::class, 'showLogin']);
    $router->get('/health', [HealthController::class, 'index']);

    $router->get('/login', [AuthController::class, 'showLogin']);
    $router->post('/login', [AuthController::class, 'login']);
    $router->get('/register', [AuthController::class, 'showRegister']);
    $router->post('/register', [AuthController::class, 'register']);
    $router->post('/logout', [AuthController::class, 'logout'], [AuthMiddleware::class]);
    $router->get('/me', [AuthController::class, 'me'], [AuthMiddleware::class]);

    $router->get('/admin/dashboard', [AdminDashboardController::class, 'index'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/admin/payments', [AdminOperationsController::class, 'payments'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/admin/transactions', [AdminOperationsController::class, 'transactions'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/admin/disputes', [AdminOperationsController::class, 'disputes'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/admin/wallets', [AdminOperationsController::class, 'wallets'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/admin/payouts', [AdminOperationsController::class, 'payouts'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->post('/admin/payouts/reconcile', [AdminOperationsController::class, 'reconcilePayouts'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->post('/admin/payouts/{id}/settle', [AdminOperationsController::class, 'settlePayout'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/admin/api-settings', [AdminOperationsController::class, 'apiSettings'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/reseller/dashboard', [ResellerDashboardController::class, 'index'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/contacts', [ResellerOperationsController::class, 'contacts'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/payments', [ResellerOperationsController::class, 'payments'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/transactions', [ResellerOperationsController::class, 'transactions'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/earnings', [ResellerOperationsController::class, 'earnings'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/disputes', [ResellerOperationsController::class, 'disputes'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/wallet', [ResellerOperationsController::class, 'wallet'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/payouts', [ResellerOperationsController::class, 'payouts'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/api-settings', [ResellerOperationsController::class, 'apiSettings'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/products', [ResellerProductController::class, 'index'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/products/create', [ResellerProductController::class, 'createForm'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->post('/reseller/products', [ResellerProductController::class, 'store'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/products/{id}/edit', [ResellerProductController::class, 'editForm'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->post('/reseller/products/{id}/update', [ResellerProductController::class, 'update'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->post('/reseller/products/{id}/toggle', [ResellerProductController::class, 'toggleStatus'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/payment-pages', [ResellerPaymentPageController::class, 'index'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/payment-pages/create', [ResellerPaymentPageController::class, 'createForm'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->post('/reseller/payment-pages', [ResellerPaymentPageController::class, 'store'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/payment-pages/{id}/edit', [ResellerPaymentPageController::class, 'editForm'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->post('/reseller/payment-pages/{id}/update', [ResellerPaymentPageController::class, 'update'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->post('/reseller/payment-pages/{id}/toggle', [ResellerPaymentPageController::class, 'toggleStatus'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/funnels', [ResellerFunnelController::class, 'index'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);
    $router->post('/reseller/funnels', [ResellerFunnelController::class, 'saveFunnel'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);
    $router->post('/reseller/funnels/{id}/steps', [ResellerFunnelController::class, 'saveStep'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);
    $router->post('/reseller/funnels/{id}/steps/{step_id}/delete', [ResellerFunnelController::class, 'deleteStep'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/reseller/email-templates', [ResellerEmailTemplateController::class, 'index'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);
    $router->post('/reseller/email-templates/{type}', [ResellerEmailTemplateController::class, 'saveTemplate'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);
    $router->post('/reseller/email-logs/{id}/resend', [ResellerEmailTemplateController::class, 'resend'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['reseller']))->handle($request, $next),
    ]);

    $router->get('/admin/users', [UserManagementController::class, 'index'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->post('/admin/users', [UserManagementController::class, 'store'], [
        AuthMiddleware::class,
        static fn (Request $request, callable $next): Response =>
            (new RoleMiddleware(['admin']))->handle($request, $next),
    ]);

    $router->get('/account/profile', [AccountController::class, 'profile'], [AuthMiddleware::class]);
    $router->post('/account/profile', [AccountController::class, 'updateProfile'], [AuthMiddleware::class]);
    $router->get('/account/security', [AccountController::class, 'security'], [AuthMiddleware::class]);
    $router->post('/account/security', [AccountController::class, 'updateSecurity'], [AuthMiddleware::class]);
    $router->get('/account/preferences', [AccountController::class, 'preferences'], [AuthMiddleware::class]);
    $router->post('/account/preferences', [AccountController::class, 'updatePreferences'], [AuthMiddleware::class]);
    $router->get('/account/avatar', [AccountController::class, 'avatar'], [AuthMiddleware::class]);

    $router->get('/p/{slug}', [PaymentPageController::class, 'show']);
    $router->post('/checkout/{slug}', [PaymentPageController::class, 'checkout']);
    $router->get('/checkout/status/{order_no}', [PaymentPageController::class, 'orderStatus']);
    $router->get('/f/{slug}', [PublicFunnelController::class, 'show']);
    $router->post('/f/{slug}/checkout', [PublicFunnelController::class, 'checkout']);
    $router->post('/f/{slug}/offer/{step_type}', [PublicFunnelController::class, 'offer']);
    $router->get('/funnel/status/{order_no}', [PublicFunnelController::class, 'status']);
    $router->get('/d/{token}', [DeliveryController::class, 'access']);
};
