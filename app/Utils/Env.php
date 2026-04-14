<?php

declare(strict_types=1);

namespace App\Utils;

use Dotenv\Dotenv;

final class Env
{
    private static bool $loaded = false;

    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }

        $envFile = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envFile)) {
            self::$loaded = true;
            return;
        }

        if (class_exists(Dotenv::class)) {
            Dotenv::createImmutable($basePath)->safeLoad();
            self::$loaded = true;
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");

            if ($key === '') {
                continue;
            }

            if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv(sprintf('%s=%s', $key, $value));
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        return $value;
    }
}
