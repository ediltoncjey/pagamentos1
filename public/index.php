<?php

declare(strict_types=1);

use App\Middlewares\CsrfMiddleware;
use App\Middlewares\IdempotencyMiddleware;
use App\Middlewares\RateLimitMiddleware;
use App\Middlewares\SecurityHeadersMiddleware;
use App\Middlewares\AuditTrailMiddleware;
use App\Services\AuthService;
use App\Utils\Container;
use App\Utils\Csrf;
use App\Utils\Database;
use App\Utils\Env;
use App\Utils\LoginThrottle;
use App\Utils\Logger;
use App\Utils\Password;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Router;
use App\Utils\SessionManager;

define('BASE_PATH', dirname(__DIR__));

$vendorAutoload = BASE_PATH . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
        $fullPath = BASE_PATH . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $relativePath;
        if (is_file($fullPath)) {
            require_once $fullPath;
        }
    });
}

Env::load(BASE_PATH);

$config = [
    'app' => require BASE_PATH . '/config/app.php',
    'database' => require BASE_PATH . '/config/database.php',
    'services' => require BASE_PATH . '/config/services.php',
    'security' => require BASE_PATH . '/config/security.php',
];

date_default_timezone_set((string) $config['app']['timezone']);

if (
    isset($config['security']['trusted_proxies'])
    && is_array($config['security']['trusted_proxies'])
) {
    $trustedProxyList = array_values(array_filter(array_map(
        static fn (mixed $item): string => trim((string) $item),
        $config['security']['trusted_proxies']
    ), static fn (string $item): bool => $item !== ''));

    $_ENV['SECURITY_TRUSTED_PROXIES'] = implode(',', $trustedProxyList);
    $_SERVER['SECURITY_TRUSTED_PROXIES'] = implode(',', $trustedProxyList);
}

$container = new Container();
$container->instance('config', (object) $config);

$container->singleton(Database::class, static fn (): Database => new Database($config['database']));
$container->singleton(Logger::class, static fn (): Logger => new Logger('app', (string) $config['app']['log_path']));
$container->singleton(SessionManager::class, static function () use ($config): SessionManager {
    $sessionDir = BASE_PATH . '/storage/tmp/sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0775, true);
    }

    return new SessionManager(
        cookieName: (string) $config['security']['session']['cookie_name'],
        lifetimeSeconds: (int) $config['security']['session']['lifetime'],
        secure: (bool) $config['security']['session']['secure'],
        httpOnly: (bool) $config['security']['session']['http_only'],
        sameSite: (string) $config['security']['session']['same_site'],
        savePath: $sessionDir,
        enforceFingerprint: (bool) $config['security']['session']['enforce_fingerprint'],
        rotateIntervalSeconds: (int) $config['security']['session']['rotate_interval_seconds'],
    );
});
$container->singleton(Csrf::class, static fn (): Csrf => new Csrf((string) $config['security']['csrf_token_name']));
$container->singleton(Password::class, static fn (): Password => new Password((int) $config['security']['bcrypt_cost']));
$container->singleton(
    LoginThrottle::class,
    static fn (): LoginThrottle => new LoginThrottle(
        storagePath: BASE_PATH . '/storage/tmp',
        maxAttempts: (int) $config['security']['login_throttle']['max_attempts'],
        windowSeconds: (int) $config['security']['login_throttle']['window_seconds'],
        lockoutSeconds: (int) $config['security']['login_throttle']['lockout_seconds'],
    )
);
$container->singleton(
    SecurityHeadersMiddleware::class,
    static fn (): SecurityHeadersMiddleware => new SecurityHeadersMiddleware(
        hstsEnabled: (bool) $config['security']['headers']['hsts_enabled'],
        hstsMaxAge: (int) $config['security']['headers']['hsts_max_age'],
    )
);
$container->singleton(
    AuditTrailMiddleware::class,
    static fn (Container $c): AuditTrailMiddleware => new AuditTrailMiddleware(
        auditLogs: $c->make(\App\Repositories\AuditLogRepository::class),
        session: $c->make(SessionManager::class),
        logger: $c->make(Logger::class),
        excludePrefixes: (array) $config['security']['audit_trail']['exclude_prefixes'],
    )
);
$container->singleton(
    RateLimitMiddleware::class,
    static fn (Container $c): RateLimitMiddleware => new RateLimitMiddleware(
        maxRequests: (int) $config['security']['rate_limit']['max'],
        windowSeconds: (int) $config['security']['rate_limit']['window_seconds'],
        storagePath: BASE_PATH . '/storage/tmp',
        rules: (array) $config['security']['rate_limit']['rules'],
        session: $c->make(SessionManager::class),
    )
);
$container->singleton(
    IdempotencyMiddleware::class,
    static fn (): IdempotencyMiddleware => new IdempotencyMiddleware(
        storagePath: BASE_PATH . '/storage/tmp',
        ttlSeconds: (int) $config['security']['idempotency_ttl'],
        protectedPrefixes: ['/api/payments']
    )
);
$container->singleton(
    CsrfMiddleware::class,
    static fn (Container $c): CsrfMiddleware => new CsrfMiddleware(
        csrf: $c->make(Csrf::class),
        exceptPrefixes: ['/api', '/health'],
        enforceOriginCheck: (bool) $config['security']['csrf']['enforce_origin'],
        trustedOrigins: (array) $config['security']['csrf']['trusted_origins'],
    )
);

/** @var SessionManager $session */
$session = $container->make(SessionManager::class);
$session->start();

/** @var Logger $logger */
$logger = $container->make(Logger::class);

/** @var AuthService $authService */
$authService = $container->make(AuthService::class);
try {
    $authService->bootstrapDefaultAdmin($config['security']['auth']);
} catch (Throwable $exception) {
    $logger->warning('Default admin bootstrap skipped', ['error' => $exception->getMessage()]);
}

$router = new Router($container, $logger);
$router->addGlobalMiddleware(SecurityHeadersMiddleware::class);
$router->addGlobalMiddleware(AuditTrailMiddleware::class);
$router->addGlobalMiddleware(RateLimitMiddleware::class);
$router->addGlobalMiddleware(IdempotencyMiddleware::class);
$router->addGlobalMiddleware(CsrfMiddleware::class);

$webRoutes = require BASE_PATH . '/routes/web.php';
$apiRoutes = require BASE_PATH . '/routes/api.php';
$webRoutes($router);
$apiRoutes($router);

$requestId = bin2hex(random_bytes(8));
$_SERVER['HTTP_X_REQUEST_ID'] = $requestId;
header('X-Request-Id: ' . $requestId);

try {
    $request = Request::capture();
    $response = $router->dispatch($request);
} catch (Throwable $exception) {
    $logger->error('Bootstrap failure', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
        'request_id' => $requestId,
    ]);

    $response = Response::json([
        'error' => 'Internal server error',
        'request_id' => $requestId,
    ], 500);
}

$response->send();
