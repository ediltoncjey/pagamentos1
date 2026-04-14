<?php

declare(strict_types=1);

return [
    'bcrypt_cost' => (int) \App\Utils\Env::get('SECURITY_BCRYPT_COST', 12),
    'csrf_token_name' => \App\Utils\Env::get('SECURITY_CSRF_TOKEN_NAME', '_csrf'),
    'session' => [
        'cookie_name' => \App\Utils\Env::get('SECURITY_SESSION_COOKIE_NAME', 'sistem_pay_session'),
        'lifetime' => (int) \App\Utils\Env::get('SECURITY_SESSION_LIFETIME', 7200),
        'same_site' => \App\Utils\Env::get('SECURITY_SESSION_SAMESITE', 'Lax'),
        'secure' => filter_var(\App\Utils\Env::get('SECURITY_SESSION_SECURE', false), FILTER_VALIDATE_BOOL),
        'http_only' => true,
        'enforce_fingerprint' => filter_var(
            \App\Utils\Env::get('SECURITY_SESSION_ENFORCE_FINGERPRINT', true),
            FILTER_VALIDATE_BOOL
        ),
        'rotate_interval_seconds' => (int) \App\Utils\Env::get('SECURITY_SESSION_ROTATE_INTERVAL_SECONDS', 900),
    ],
    'rate_limit' => [
        'max' => (int) \App\Utils\Env::get('SECURITY_RATE_LIMIT_MAX', 120),
        'window_seconds' => (int) \App\Utils\Env::get('SECURITY_RATE_LIMIT_WINDOW_SECONDS', 60),
        'rules' => [
            [
                'prefix' => '/api/auth/login',
                'methods' => ['POST'],
                'max' => (int) \App\Utils\Env::get('SECURITY_RATE_LIMIT_LOGIN_MAX', 15),
                'window_seconds' => (int) \App\Utils\Env::get('SECURITY_RATE_LIMIT_LOGIN_WINDOW_SECONDS', 300),
            ],
            [
                'prefix' => '/login',
                'methods' => ['POST'],
                'max' => (int) \App\Utils\Env::get('SECURITY_RATE_LIMIT_LOGIN_MAX', 15),
                'window_seconds' => (int) \App\Utils\Env::get('SECURITY_RATE_LIMIT_LOGIN_WINDOW_SECONDS', 300),
            ],
            [
                'prefix' => '/api/payments/callback',
                'methods' => ['POST'],
                'max' => (int) \App\Utils\Env::get('SECURITY_RATE_LIMIT_CALLBACK_MAX', 600),
                'window_seconds' => (int) \App\Utils\Env::get('SECURITY_RATE_LIMIT_CALLBACK_WINDOW_SECONDS', 60),
            ],
            [
                'prefix' => '/checkout',
                'methods' => ['POST'],
                'max' => (int) \App\Utils\Env::get('SECURITY_RATE_LIMIT_CHECKOUT_MAX', 50),
                'window_seconds' => (int) \App\Utils\Env::get('SECURITY_RATE_LIMIT_CHECKOUT_WINDOW_SECONDS', 300),
            ],
        ],
    ],
    'idempotency_ttl' => (int) \App\Utils\Env::get('SECURITY_IDEMPOTENCY_TTL', 3600),
    'login_throttle' => [
        'max_attempts' => (int) \App\Utils\Env::get('SECURITY_LOGIN_MAX_ATTEMPTS', 5),
        'window_seconds' => (int) \App\Utils\Env::get('SECURITY_LOGIN_WINDOW_SECONDS', 900),
        'lockout_seconds' => (int) \App\Utils\Env::get('SECURITY_LOGIN_LOCKOUT_SECONDS', 900),
    ],
    'csrf' => [
        'enforce_origin' => filter_var(\App\Utils\Env::get('SECURITY_CSRF_ENFORCE_ORIGIN', true), FILTER_VALIDATE_BOOL),
        'trusted_origins' => array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', (string) \App\Utils\Env::get('SECURITY_CSRF_TRUSTED_ORIGINS', ''))
        ), static fn (string $item): bool => $item !== '')),
    ],
    'headers' => [
        'hsts_enabled' => filter_var(\App\Utils\Env::get('SECURITY_HEADERS_HSTS_ENABLED', false), FILTER_VALIDATE_BOOL),
        'hsts_max_age' => (int) \App\Utils\Env::get('SECURITY_HEADERS_HSTS_MAX_AGE', 31536000),
    ],
    'audit_trail' => [
        'enabled' => filter_var(\App\Utils\Env::get('SECURITY_AUDIT_TRAIL_ENABLED', true), FILTER_VALIDATE_BOOL),
        'exclude_prefixes' => array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', (string) \App\Utils\Env::get('SECURITY_AUDIT_TRAIL_EXCLUDE_PREFIXES', '/health,/api/health'))
        ), static fn (string $item): bool => $item !== '')),
    ],
    'trusted_proxies' => array_values(array_filter(array_map(
        static fn (string $item): string => trim($item),
        explode(',', (string) \App\Utils\Env::get('SECURITY_TRUSTED_PROXIES', ''))
    ), static fn (string $item): bool => $item !== '')),
    'payment_callback' => [
        'signature_header' => (string) \App\Utils\Env::get('PAYMENT_CALLBACK_SIGNATURE_HEADER', 'X-Signature'),
        'secret' => (string) \App\Utils\Env::get('PAYMENT_CALLBACK_SECRET', ''),
    ],
    'password_policy' => [
        'require_mixed_case' => filter_var(
            \App\Utils\Env::get('SECURITY_PASSWORD_REQUIRE_MIXED_CASE', true),
            FILTER_VALIDATE_BOOL
        ),
        'require_number' => filter_var(
            \App\Utils\Env::get('SECURITY_PASSWORD_REQUIRE_NUMBER', true),
            FILTER_VALIDATE_BOOL
        ),
        'require_symbol' => filter_var(
            \App\Utils\Env::get('SECURITY_PASSWORD_REQUIRE_SYMBOL', true),
            FILTER_VALIDATE_BOOL
        ),
    ],
    'auth' => [
        'bootstrap_default_admin' => filter_var(
            \App\Utils\Env::get('AUTH_BOOTSTRAP_DEFAULT_ADMIN', true),
            FILTER_VALIDATE_BOOL
        ),
        'default_admin_name' => \App\Utils\Env::get('AUTH_DEFAULT_ADMIN_NAME', 'System Admin'),
        'default_admin_email' => \App\Utils\Env::get('AUTH_DEFAULT_ADMIN_EMAIL', 'admin@sistempay.local'),
        'default_admin_password' => \App\Utils\Env::get('AUTH_DEFAULT_ADMIN_PASSWORD', 'ChangeMe@123'),
    ],
];
