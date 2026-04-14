<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Repositories\AuditLogRepository;
use App\Utils\Logger;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\SessionManager;
use Throwable;

final class AuditTrailMiddleware
{
    /**
     * @param list<string> $excludePrefixes
     */
    public function __construct(
        private readonly AuditLogRepository $auditLogs,
        private readonly SessionManager $session,
        private readonly Logger $logger,
        private readonly array $excludePrefixes = ['/health', '/api/health'],
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        if (!$this->shouldAudit($request)) {
            return $response;
        }

        $user = $this->session->user();
        $oldValues = [
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'status' => $response->statusCode(),
        ];
        $newValues = [
            'query' => $this->scrubData($request->query()),
            'body' => $this->scrubData($request->body()),
            'response_status' => $response->statusCode(),
        ];

        try {
            $this->auditLogs->create([
                'actor_user_id' => $user['id'] ?? null,
                'actor_role' => $user['role'] ?? null,
                'action' => 'http.' . strtolower($request->method()),
                'entity_type' => 'route',
                'entity_id' => null,
                'old_values' => json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'new_values' => json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_id' => (string) $request->header('X-Request-Id', ''),
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('Audit trail middleware failed to write log', [
                'path' => $request->path(),
                'method' => $request->method(),
                'error' => $exception->getMessage(),
            ]);
        }

        return $response;
    }

    private function shouldAudit(Request $request): bool
    {
        foreach ($this->excludePrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($request->path(), $prefix)) {
                return false;
            }
        }

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        if (str_starts_with($request->path(), '/api/admin') || str_starts_with($request->path(), '/api/reseller')) {
            return true;
        }

        if ($request->path() === '/login' || $request->path() === '/register') {
            return true;
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function scrubData(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $result[$key] = '***';
                    continue;
                }

                $result[$key] = $this->scrubData($item);
            }

            return $result;
        }

        if (is_string($value)) {
            if (mb_strlen($value) > 500) {
                return mb_substr($value, 0, 500) . '...(truncated)';
            }

            return $value;
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (['password', 'token', 'secret', 'authorization', 'api_key', 'csrf'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
