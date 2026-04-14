<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DownloadRepository;
use App\Repositories\OrderRepository;
use App\Utils\Env;
use App\Utils\Uuid;
use RuntimeException;

final class DownloadService
{
    public function __construct(
        private readonly DownloadRepository $downloads,
        private readonly OrderRepository $orders,
        private readonly Uuid $uuid,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function issueDownloadToken(int $orderId): array
    {
        $order = $this->orders->findDeliveryContextByOrderId($orderId);
        if ($order === null || (string) ($order['status'] ?? '') !== 'paid') {
            throw new RuntimeException('Download token can only be issued for paid orders.');
        }

        $existing = $this->downloads->findLatestByOrderId($orderId);
        if ($existing !== null) {
            $resolvedExisting = $this->isTokenUsable($existing, autoExpire: true)
                ? $this->resolveDeliveryPayload($existing)
                : null;
            $latestExisting = $this->downloads->findByToken((string) $existing['token']) ?? $existing;

            if ($resolvedExisting !== null) {
                return [
                    'download_id' => (int) $latestExisting['id'],
                    'token' => (string) $latestExisting['token'],
                    'delivery_mode' => (string) $latestExisting['delivery_mode'],
                    'access_url' => $this->accessUrl((string) $latestExisting['token']),
                    'expires_at' => $latestExisting['expires_at'] ?? null,
                    'max_downloads' => (int) $latestExisting['max_downloads'],
                    'status' => (string) $latestExisting['status'],
                    'can_access' => true,
                    'reused' => true,
                ];
            }

            return [
                'download_id' => (int) $latestExisting['id'],
                'token' => (string) $latestExisting['token'],
                'delivery_mode' => (string) $latestExisting['delivery_mode'],
                'access_url' => $this->accessUrl((string) $latestExisting['token']),
                'expires_at' => $latestExisting['expires_at'] ?? null,
                'max_downloads' => (int) $latestExisting['max_downloads'],
                'status' => (string) $latestExisting['status'],
                'can_access' => false,
                'reused' => true,
            ];
        }

        [$deliveryMode, $targetPath, $targetUrl] = $this->resolveDeliveryTarget($order);
        $expiresAt = $this->buildExpiryDate();
        $maxDownloads = max(1, (int) Env::get('DOWNLOAD_MAX_DOWNLOADS', 5));

        $token = hash('sha256', $this->uuid->v4() . '|' . $order['order_no']);
        $downloadId = $this->downloads->create([
            'order_id' => $orderId,
            'product_id' => (int) $order['product_id'],
            'token' => $token,
            'delivery_mode' => $deliveryMode,
            'target_path' => $targetPath,
            'target_url' => $targetUrl,
            'expires_at' => $expiresAt,
            'max_downloads' => $maxDownloads,
            'status' => 'active',
        ]);

        return [
            'download_id' => $downloadId,
            'token' => $token,
            'delivery_mode' => $deliveryMode,
            'access_url' => $this->accessUrl($token),
            'expires_at' => $expiresAt,
            'max_downloads' => $maxDownloads,
            'status' => 'active',
            'can_access' => true,
            'reused' => false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validateToken(string $token): ?array
    {
        $download = $this->findValidatedDownload($token);
        if ($download === null) {
            return null;
        }

        $resolved = $this->resolveDeliveryPayload($download);
        if ($resolved === null) {
            return null;
        }

        return [
            'download_id' => (int) $download['id'],
            'order_id' => (int) $download['order_id'],
            'product_id' => (int) $download['product_id'],
            'token' => (string) $download['token'],
            'delivery_mode' => (string) $download['delivery_mode'],
            'target_url' => $resolved['target_url'],
            'expires_at' => $download['expires_at'] ?? null,
            'download_count' => (int) $download['download_count'],
            'max_downloads' => (int) $download['max_downloads'],
            'remaining_downloads' => max(0, (int) $download['max_downloads'] - (int) $download['download_count']),
            'status' => (string) $download['status'],
            'access_url' => $this->accessUrl((string) $download['token']),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function consumeToken(string $token): ?array
    {
        $download = $this->findValidatedDownload($token);
        if ($download === null) {
            return null;
        }

        $resolved = $this->resolveDeliveryPayload($download);
        if ($resolved === null) {
            return null;
        }

        $consumed = $this->downloads->incrementCounter((int) $download['id']);
        if (!$consumed) {
            $latest = $this->downloads->findByToken((string) $download['token']);
            if ($latest !== null) {
                $this->isTokenUsable($latest, autoExpire: true);
            }

            return null;
        }

        $latest = $this->downloads->findByToken((string) $download['token']) ?? $download;
        if ((int) $latest['download_count'] >= (int) $latest['max_downloads']) {
            $this->downloads->markStatus((int) $latest['id'], 'expired');
            $latest['status'] = 'expired';
        }

        return [
            'download_id' => (int) $latest['id'],
            'order_id' => (int) $latest['order_id'],
            'product_id' => (int) $latest['product_id'],
            'token' => (string) $latest['token'],
            'delivery_mode' => (string) $latest['delivery_mode'],
            'target_url' => $resolved['target_url'],
            'absolute_path' => $resolved['absolute_path'],
            'download_name' => $resolved['download_name'],
            'mime_type' => $resolved['mime_type'],
            'expires_at' => $latest['expires_at'] ?? null,
            'download_count' => (int) $latest['download_count'],
            'max_downloads' => (int) $latest['max_downloads'],
            'remaining_downloads' => max(0, (int) $latest['max_downloads'] - (int) $latest['download_count']),
            'status' => (string) $latest['status'],
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array{0:string,1:?string,2:?string}
     */
    private function resolveDeliveryTarget(array $order): array
    {
        $deliveryType = (string) ($order['product_delivery_type'] ?? '');
        if ($deliveryType === 'external_link') {
            $url = trim((string) ($order['product_external_url'] ?? ''));
            if (!$this->isValidHttpUrl($url)) {
                throw new RuntimeException('External delivery URL is missing or invalid.');
            }

            return ['redirect', null, $url];
        }

        if ($deliveryType === 'file_upload') {
            $path = trim((string) ($order['product_file_path'] ?? ''));
            if ($path === '') {
                throw new RuntimeException('Digital file path is missing for file delivery.');
            }

            return ['file', $path, null];
        }

        throw new RuntimeException('Unsupported product delivery type.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findValidatedDownload(string $token): ?array
    {
        $token = $this->sanitizeToken($token);
        if ($token === '') {
            return null;
        }

        $download = $this->downloads->findByToken($token);
        if ($download === null) {
            return null;
        }

        if (!$this->isTokenUsable($download, autoExpire: true)) {
            return null;
        }

        return $download;
    }

    /**
     * @param array<string, mixed> $download
     */
    private function isTokenUsable(array $download, bool $autoExpire = false): bool
    {
        if ((string) ($download['status'] ?? '') !== 'active') {
            return false;
        }

        $expiresAt = $download['expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            $expiryTimestamp = strtotime($expiresAt);
            if ($expiryTimestamp !== false && $expiryTimestamp <= time()) {
                if ($autoExpire) {
                    $this->downloads->markStatus((int) $download['id'], 'expired');
                }

                return false;
            }
        }

        if ((int) $download['download_count'] >= (int) $download['max_downloads']) {
            if ($autoExpire) {
                $this->downloads->markStatus((int) $download['id'], 'expired');
            }

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $download
     * @return array{
     *   target_url:?string,
     *   absolute_path:?string,
     *   download_name:?string,
     *   mime_type:?string
     * }|null
     */
    private function resolveDeliveryPayload(array $download): ?array
    {
        $mode = (string) ($download['delivery_mode'] ?? '');

        if ($mode === 'redirect') {
            $url = trim((string) ($download['target_url'] ?? ''));
            if (!$this->isValidHttpUrl($url)) {
                $this->downloads->markStatus((int) $download['id'], 'revoked');
                return null;
            }

            return [
                'target_url' => $url,
                'absolute_path' => null,
                'download_name' => null,
                'mime_type' => null,
            ];
        }

        if ($mode === 'file') {
            $relativePath = (string) ($download['target_path'] ?? '');
            $absolutePath = $this->resolvePrivateAbsolutePath($relativePath);
            if ($absolutePath === null || !is_file($absolutePath)) {
                $this->downloads->markStatus((int) $download['id'], 'revoked');
                return null;
            }

            return [
                'target_url' => null,
                'absolute_path' => $absolutePath,
                'download_name' => basename($absolutePath),
                'mime_type' => $this->detectMimeType($absolutePath),
            ];
        }

        $this->downloads->markStatus((int) $download['id'], 'revoked');
        return null;
    }

    private function buildExpiryDate(): ?string
    {
        $ttlSeconds = (int) Env::get('DOWNLOAD_TOKEN_TTL_SECONDS', 86400);
        if ($ttlSeconds <= 0) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
    }

    private function accessUrl(string $token): string
    {
        return '/d/' . rawurlencode($token);
    }

    private function sanitizeToken(string $token): string
    {
        $normalized = strtolower(trim($token));
        return preg_match('/^[a-f0-9]{64}$/', $normalized) === 1 ? $normalized : '';
    }

    private function isValidHttpUrl(string $url): bool
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    private function resolvePrivateAbsolutePath(string $relativePath): ?string
    {
        $normalized = trim(str_replace(['\\', '..'], ['/', ''], $relativePath), '/');
        if ($normalized === '') {
            return null;
        }

        $storageRoot = realpath(
            dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'private'
        );
        if ($storageRoot === false) {
            return null;
        }

        $candidate = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $realPath = realpath($candidate);
        if ($realPath === false) {
            return null;
        }

        $prefix = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realPath, $prefix)) {
            return null;
        }

        return $realPath;
    }

    private function detectMimeType(string $absolutePath): string
    {
        if (function_exists('finfo_open') && function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $absolutePath);
                finfo_close($finfo);

                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return 'application/octet-stream';
    }
}
