<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Utils\Request;
use App\Utils\Response;

final class SecurityHeadersMiddleware
{
    public function __construct(
        private readonly bool $hstsEnabled = false,
        private readonly int $hstsMaxAge = 31536000,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "img-src 'self' data: https:",
                "font-src 'self' data:",
                "script-src 'self' 'unsafe-inline'",
                "style-src 'self' 'unsafe-inline'",
                "connect-src 'self'",
                "object-src 'none'",
            ]),
        ];

        if ($this->hstsEnabled) {
            $headers['Strict-Transport-Security'] = 'max-age=' . max(300, $this->hstsMaxAge) . '; includeSubDomains';
        }

        return $response->withHeaders($headers);
    }
}
