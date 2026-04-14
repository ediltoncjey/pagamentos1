<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Utils\Request;
use App\Utils\Response;

final class IdempotencyMiddleware
{
    /**
     * @param list<string> $protectedPrefixes
     */
    public function __construct(
        private readonly string $storagePath = '',
        private readonly int $ttlSeconds = 3600,
        private readonly array $protectedPrefixes = ['/api/payments'],
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        if (!$this->isProtectedPath($request->path())) {
            return $next($request);
        }

        $isCallback = str_ends_with($request->path(), '/callback');
        $isPollingEndpoint = str_ends_with($request->path(), '/poll');
        if ($isPollingEndpoint) {
            return $next($request);
        }

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');
        if ($idempotencyKey === '' && $isCallback) {
            $fingerprint = $request->rawBody();
            if ($fingerprint === '') {
                $fingerprint = json_encode($request->body(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            }
            $idempotencyKey = hash('sha256', $request->path() . '|' . ($fingerprint ?: '{}'));
        }

        if ($idempotencyKey === '') {
            return Response::json(['error' => 'Idempotency-Key header is required'], 422);
        }

        if (!$this->isValidIdempotencyKey($idempotencyKey)) {
            return Response::json(['error' => 'Invalid Idempotency-Key format'], 422);
        }

        $storagePath = $this->storagePath !== '' ? $this->storagePath : dirname(__DIR__, 2) . '/storage/tmp';
        if (!is_dir($storagePath)) {
            @mkdir($storagePath, 0775, true);
        }

        $keyHash = sha1($request->method() . '|' . $request->path() . '|' . $idempotencyKey);
        $file = rtrim($storagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'idem_' . $keyHash . '.json';
        $fallbackBody = json_encode($request->body(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($fallbackBody)) {
            $fallbackBody = '{}';
        }

        $requestHash = hash('sha256', $request->rawBody() !== '' ? $request->rawBody() : $fallbackBody);

        if (is_file($file)) {
            $payload = json_decode((string) file_get_contents($file), true);
            $timestamp = (int) ($payload['timestamp'] ?? 0);
            if ((time() - $timestamp) < $this->ttlSeconds) {
                $storedRequestHash = (string) ($payload['request_hash'] ?? '');
                if ($storedRequestHash !== '' && $storedRequestHash !== $requestHash) {
                    return Response::json([
                        'error' => 'Idempotency-Key reuse with different payload is not allowed',
                    ], 409);
                }

                if ($isCallback) {
                    return Response::json([
                        'processed' => true,
                        'duplicate' => true,
                        'status' => 'ignored',
                    ], 200);
                }

                return Response::json([
                    'error' => 'Duplicate request blocked by idempotency guard',
                ], 409);
            }
        }

        $response = $next($request);

        file_put_contents(
            $file,
            (string) json_encode([
                'timestamp' => time(),
                'status' => $response->statusCode(),
                'request_hash' => $requestHash,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        return $response;
    }

    private function isProtectedPath(string $path): bool
    {
        foreach ($this->protectedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isValidIdempotencyKey(string $idempotencyKey): bool
    {
        $length = mb_strlen($idempotencyKey);
        if ($length < 8 || $length > 128) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9\-\_:|\.]+$/', $idempotencyKey) === 1;
    }
}
