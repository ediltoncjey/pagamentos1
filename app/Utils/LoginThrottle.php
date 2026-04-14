<?php

declare(strict_types=1);

namespace App\Utils;

final class LoginThrottle
{
    public function __construct(
        private readonly string $storagePath = '',
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 900,
        private readonly int $lockoutSeconds = 900,
    ) {
    }

    public function assertAllowed(string $email, string $ip): void
    {
        $payload = $this->readPayload($email, $ip);
        if ($payload === null) {
            return;
        }

        $now = time();
        $lockedUntil = (int) ($payload['locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            throw new TooManyRequestsException(
                message: 'Muitas tentativas de login. Tente novamente mais tarde.',
                retryAfter: $lockedUntil - $now
            );
        }
    }

    public function recordFailure(string $email, string $ip): void
    {
        $now = time();
        $payload = $this->readPayload($email, $ip) ?? [
            'attempts' => [],
            'locked_until' => 0,
            'updated_at' => $now,
        ];

        $attempts = is_array($payload['attempts'] ?? null) ? $payload['attempts'] : [];
        $filteredAttempts = [];
        foreach ($attempts as $attemptTimestamp) {
            $attemptTimestamp = (int) $attemptTimestamp;
            if (($now - $attemptTimestamp) <= $this->windowSeconds) {
                $filteredAttempts[] = $attemptTimestamp;
            }
        }

        $filteredAttempts[] = $now;
        $payload['attempts'] = $filteredAttempts;
        $payload['updated_at'] = $now;

        if (count($filteredAttempts) >= $this->maxAttempts) {
            $payload['locked_until'] = $now + $this->lockoutSeconds;
            $payload['attempts'] = [];
        }

        $this->writePayload($email, $ip, $payload);
    }

    public function clearFailures(string $email, string $ip): void
    {
        $file = $this->filePath($email, $ip);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function filePath(string $email, string $ip): string
    {
        $key = hash('sha256', strtolower(trim($email)) . '|' . trim($ip));
        return rtrim($this->resolvedStoragePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'login_throttle_' . $key . '.json';
    }

    private function resolvedStoragePath(): string
    {
        $path = $this->storagePath !== '' ? $this->storagePath : dirname(__DIR__, 2) . '/storage/tmp';
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return $path;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPayload(string $email, string $ip): ?array
    {
        $file = $this->filePath($email, $ip);
        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            return null;
        }

        $updatedAt = (int) ($decoded['updated_at'] ?? 0);
        if ($updatedAt > 0 && (time() - $updatedAt) > ($this->windowSeconds + $this->lockoutSeconds)) {
            @unlink($file);
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writePayload(string $email, string $ip, array $payload): void
    {
        file_put_contents(
            $this->filePath($email, $ip),
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
