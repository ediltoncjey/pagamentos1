<?php

declare(strict_types=1);

namespace App\Utils;

final class Csrf
{
    public function __construct(
        private readonly string $tokenName = '_csrf',
    ) {
    }

    public function tokenName(): string
    {
        return $this->tokenName;
    }

    public function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION[$this->tokenName])) {
            $_SESSION[$this->tokenName] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[$this->tokenName];
    }

    public function validate(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $sessionToken = $_SESSION[$this->tokenName] ?? null;
        if (!is_string($token) || !is_string($sessionToken)) {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }
}
