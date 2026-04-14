<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Utils\Csrf;
use App\Utils\Request;
use App\Utils\Response;

final class CsrfMiddleware
{
    /**
     * @param list<string> $exceptPrefixes
     * @param list<string> $trustedOrigins
     */
    public function __construct(
        private readonly Csrf $csrf,
        private readonly array $exceptPrefixes = ['/api', '/health'],
        private readonly bool $enforceOriginCheck = true,
        private readonly array $trustedOrigins = [],
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        foreach ($this->exceptPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($request->path(), $prefix)) {
                return $next($request);
            }
        }

        if ($this->enforceOriginCheck && !$this->hasTrustedOrigin($request)) {
            return Response::json(['error' => 'Invalid request origin'], 403);
        }

        $token = (string) ($request->input($this->csrf->tokenName()) ?? $request->header('X-CSRF-TOKEN', ''));
        if (!$this->csrf->validate($token)) {
            return Response::json(['error' => 'Invalid CSRF token'], 419);
        }

        return $next($request);
    }

    private function hasTrustedOrigin(Request $request): bool
    {
        $origin = trim((string) $request->header('Origin', ''));
        $referer = trim((string) $request->header('Referer', ''));

        if ($origin === '' && $referer === '') {
            return true;
        }

        $originHost = $this->hostFromUrl($origin);
        $refererHost = $this->hostFromUrl($referer);
        $requestHost = strtolower($request->host());

        $allowedHosts = array_map('strtolower', $this->trustedOrigins);
        if ($requestHost !== '') {
            $allowedHosts[] = strtolower($requestHost);
        }

        if ($originHost !== '' && in_array($originHost, $allowedHosts, true)) {
            return true;
        }

        if ($refererHost !== '' && in_array($refererHost, $allowedHosts, true)) {
            return true;
        }

        return false;
    }

    private function hostFromUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return '';
        }

        return strtolower($host);
    }
}
