<?php

declare(strict_types=1);

namespace App\Utils;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files
     * @param array<string, string> $routeParams
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $query = [],
        private array $body = [],
        private array $headers = [],
        private array $server = [],
        private array $files = [],
        private array $routeParams = [],
        private string $rawBody = '',
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $baseDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($baseDir !== '' && $baseDir !== '.' && $baseDir !== '/' && str_starts_with($path, $baseDir)) {
            $path = substr($path, strlen($baseDir)) ?: '/';
        }
        $query = $_GET ?? [];
        $body = $_POST ?? [];
        $headers = self::extractHeaders();

        $rawBody = (string) (file_get_contents('php://input') ?: '');
        $contentType = strtolower((string) ($headers['Content-Type'] ?? $headers['content-type'] ?? ''));
        if ($rawBody !== '' && str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $body = array_merge($body, $decoded);
            }
        }

        return new self(
            method: $method,
            path: rtrim($path, '/') === '' ? '/' : rtrim($path, '/'),
            query: $query,
            body: $body,
            headers: $headers,
            server: $_SERVER,
            files: $_FILES ?? [],
            rawBody: $rawBody
        );
    }

    public function withRouteParams(array $params): self
    {
        return new self(
            method: $this->method,
            path: $this->path,
            query: $this->query,
            body: $this->body,
            headers: $this->headers,
            server: $this->server,
            files: $this->files,
            routeParams: $params,
            rawBody: $this->rawBody
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body, $this->routeParams);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function route(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    public function header(string $key, mixed $default = null): mixed
    {
        $normalized = strtolower($key);
        foreach ($this->headers as $headerKey => $value) {
            if (strtolower($headerKey) === $normalized) {
                return $value;
            }
        }

        return $default;
    }

    public function ip(): string
    {
        $remoteAddress = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        $trustedProxies = $this->trustedProxies();
        if ($trustedProxies === [] || !$this->isTrustedProxy($remoteAddress, $trustedProxies)) {
            return $remoteAddress;
        }

        $forwardedFor = (string) ($this->header('X-Forwarded-For', '') ?? '');
        if ($forwardedFor === '') {
            return $remoteAddress;
        }

        $parts = array_map('trim', explode(',', $forwardedFor));
        foreach ($parts as $part) {
            if ($part === '' || !$this->isValidIp($part)) {
                continue;
            }

            return $part;
        }

        return $remoteAddress;
    }

    public function userAgent(): string
    {
        return (string) ($this->server['HTTP_USER_AGENT'] ?? 'unknown');
    }

    public function host(): string
    {
        $host = trim((string) ($this->server['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return '';
        }

        $parsedHost = parse_url('http://' . $host, PHP_URL_HOST);
        if (is_string($parsedHost) && $parsedHost !== '') {
            return strtolower($parsedHost);
        }

        return strtolower($host);
    }

    /**
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) ? $file : null;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * @return array<string, string>
     */
    private static function extractHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (!str_starts_with($name, 'HTTP_')) {
                continue;
            }

            $key = str_replace('_', '-', ucwords(strtolower(substr($name, 5)), '_'));
            $headers[$key] = (string) $value;
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    private function trustedProxies(): array
    {
        $raw = $_ENV['SECURITY_TRUSTED_PROXIES'] ?? $_SERVER['SECURITY_TRUSTED_PROXIES'] ?? getenv('SECURITY_TRUSTED_PROXIES');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $parts = array_map(
            static fn (string $item): string => trim($item),
            explode(',', $raw)
        );

        return array_values(array_filter($parts, static fn (string $item): bool => $item !== ''));
    }

    /**
     * @param list<string> $trustedProxies
     */
    private function isTrustedProxy(string $remoteAddress, array $trustedProxies): bool
    {
        if (in_array('*', $trustedProxies, true)) {
            return true;
        }

        return in_array($remoteAddress, $trustedProxies, true);
    }

    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
