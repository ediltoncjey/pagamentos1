<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Utils\Request;
use App\Utils\Response;
use App\Utils\SessionManager;

final class RateLimitMiddleware
{
    /**
     * @param array<int, array<string, mixed>> $rules
     */
    public function __construct(
        private readonly int $maxRequests = 120,
        private readonly int $windowSeconds = 60,
        private readonly string $storagePath = '',
        private readonly array $rules = [],
        private readonly ?SessionManager $session = null,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $rule = $this->resolveRule($request->path(), $request->method());
        $max = max(1, (int) ($rule['max'] ?? $this->maxRequests));
        $window = max(1, (int) ($rule['window_seconds'] ?? $this->windowSeconds));
        $bucketPath = (string) ($rule['prefix'] ?? $this->normalizePath($request->path()));

        $storagePath = $this->resolvedStoragePath();
        $identity = $this->resolveIdentity($request);
        $bucket = hash('sha256', $identity . '|' . $request->method() . '|' . $bucketPath);
        $file = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rate_' . $bucket . '.json';
        $now = time();

        $payload = $this->updatePayloadAtomically($file, $now, $window);
        $count = (int) ($payload['count'] ?? 0);
        $windowStart = (int) ($payload['window_start'] ?? $now);
        $windowResetTs = $windowStart + $window;
        $remaining = max(0, $max - $count);
        $retryAfter = max(0, $windowResetTs - $now);

        if ($count > $max) {
            return Response::json([
                'error' => 'Too Many Requests',
                'retry_after' => $retryAfter,
            ], 429)->withHeaders([
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $max,
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) $windowResetTs,
            ]);
        }

        $response = $next($request);
        return $response->withHeaders([
            'X-RateLimit-Limit' => (string) $max,
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset' => (string) $windowResetTs,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayloadAtomically(string $file, int $now, int $window): array
    {
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return [
                'window_start' => $now,
                'count' => 1,
            ];
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return [
                    'window_start' => $now,
                    'count' => 1,
                ];
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $payload = [
                'window_start' => $now,
                'count' => 0,
            ];

            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['window_start'], $decoded['count'])) {
                    $payload = $decoded;
                }
            }

            if (($now - (int) $payload['window_start']) > $window) {
                $payload = [
                    'window_start' => $now,
                    'count' => 0,
                ];
            }

            $payload['count'] = (int) $payload['count'] + 1;

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($handle);
            flock($handle, LOCK_UN);

            return $payload;
        } finally {
            fclose($handle);
        }
    }

    private function resolvedStoragePath(): string
    {
        $path = $this->storagePath !== '' ? $this->storagePath : dirname(__DIR__, 2) . '/storage/tmp';
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return $path;
    }

    private function resolveIdentity(Request $request): string
    {
        if ($this->session === null) {
            return 'ip:' . $request->ip();
        }

        $user = $this->session->user();
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0) {
            return 'user:' . $userId;
        }

        return 'ip:' . $request->ip();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRule(string $path, string $method): array
    {
        foreach ($this->rules as $rule) {
            $prefix = (string) ($rule['prefix'] ?? '');
            if ($prefix === '' || !str_starts_with($path, $prefix)) {
                continue;
            }

            $methods = $rule['methods'] ?? null;
            if (is_array($methods) && $methods !== []) {
                $upper = array_map(static fn (mixed $item): string => strtoupper((string) $item), $methods);
                if (!in_array(strtoupper($method), $upper, true)) {
                    continue;
                }
            }

            return $rule;
        }

        return [];
    }

    private function normalizePath(string $path): string
    {
        $parts = explode('/', trim($path, '/'));
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^[0-9]+$/', $part) === 1) {
                $normalized[] = ':num';
                continue;
            }

            if (preg_match('/^[A-Fa-f0-9]{16,}$/', $part) === 1 || mb_strlen($part) > 28) {
                $normalized[] = ':id';
                continue;
            }

            $normalized[] = strtolower($part);
        }

        return '/' . implode('/', $normalized);
    }
}
