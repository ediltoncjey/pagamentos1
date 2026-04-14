<?php

declare(strict_types=1);

namespace App\Utils;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private int $statusCode = 200,
        private string $body = '',
        private array $headers = ['Content-Type' => 'text/html; charset=UTF-8'],
        private ?string $filePath = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $statusCode = 200): self
    {
        return new self(
            statusCode: $statusCode,
            body: (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            headers: ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public static function text(string $text, int $statusCode = 200): self
    {
        return new self(
            statusCode: $statusCode,
            body: $text,
            headers: ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }

    public static function redirect(string $location, int $statusCode = 302): self
    {
        $location = self::withAppBasePath($location);

        return new self(
            statusCode: $statusCode,
            body: '',
            headers: ['Location' => $location]
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public static function file(
        string $absolutePath,
        string $downloadName,
        string $mimeType = 'application/octet-stream',
        bool $attachment = true,
        array $headers = [],
        int $statusCode = 200
    ): self {
        $safeName = str_replace(['"', "\r", "\n"], '', $downloadName);
        $baseHeaders = [
            'Content-Type' => $mimeType,
            'Content-Length' => is_file($absolutePath) ? (string) filesize($absolutePath) : '0',
            'Content-Disposition' => ($attachment ? 'attachment' : 'inline') . '; filename="' . $safeName . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return new self(
            statusCode: $statusCode,
            body: '',
            headers: array_merge($baseHeaders, $headers),
            filePath: $absolutePath
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self(
            statusCode: $this->statusCode,
            body: $this->body,
            headers: array_merge($this->headers, $headers),
            filePath: $this->filePath
        );
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        $headers = $this->headers;
        if (isset($headers['Location']) && is_string($headers['Location'])) {
            $headers['Location'] = self::withAppBasePath($headers['Location']);
        }

        $body = $this->body;
        $contentType = strtolower((string) ($headers['Content-Type'] ?? ''));
        $basePath = self::appBasePath();
        if ($this->filePath === null && $basePath !== '' && str_contains($contentType, 'text/html')) {
            $body = self::rewriteHtmlAbsolutePaths($body, $basePath);
        }

        foreach ($headers as $name => $value) {
            header(sprintf('%s: %s', $name, $value));
        }

        if ($this->filePath !== null) {
            $stream = @fopen($this->filePath, 'rb');
            if ($stream === false) {
                return;
            }

            while (!feof($stream)) {
                echo (string) fread($stream, 8192);
                @flush();
            }

            fclose($stream);
            return;
        }

        echo $body;
    }

    private static function appBasePath(): string
    {
        $url = (string) Env::get('APP_URL', '');
        if ($url === '') {
            return '';
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '';
        }

        return '/' . trim($path, '/');
    }

    private static function withAppBasePath(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        if (
            str_starts_with($value, 'http://')
            || str_starts_with($value, 'https://')
            || str_starts_with($value, '//')
        ) {
            return $value;
        }

        if (!str_starts_with($value, '/')) {
            return $value;
        }

        $basePath = self::appBasePath();
        if ($basePath === '') {
            return $value;
        }

        if ($value === $basePath || str_starts_with($value, $basePath . '/')) {
            return $value;
        }

        if ($value === '/') {
            return $basePath . '/';
        }

        return $basePath . $value;
    }

    private static function rewriteHtmlAbsolutePaths(string $html, string $basePath): string
    {
        $prefix = ltrim($basePath, '/');
        if ($prefix === '') {
            return $html;
        }

        $patterns = [
            '/(\b(?:href|src|action)=["\'])\/(?!\/|' . preg_quote($prefix, '/') . '\/)/i' => '$1' . $basePath . '/',
            '/fetch\(\s*\'\/(?!\/|' . preg_quote($prefix, '/') . '\/)/i' => 'fetch(\'' . $basePath . '/',
            '/fetch\(\s*"\/(?!\/|' . preg_quote($prefix, '/') . '\/)/i' => 'fetch("' . $basePath . '/',
            '/window\.location\s*=\s*\'\/(?!\/|' . preg_quote($prefix, '/') . '\/)/i' => 'window.location = \'' . $basePath . '/',
            '/window\.location\s*=\s*"\/(?!\/|' . preg_quote($prefix, '/') . '\/)/i' => 'window.location = "' . $basePath . '/',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $html = (string) preg_replace($pattern, $replacement, $html);
        }

        return $html;
    }
}
