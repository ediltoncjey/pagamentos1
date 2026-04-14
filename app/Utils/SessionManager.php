<?php

declare(strict_types=1);

namespace App\Utils;

final class SessionManager
{
    public function __construct(
        private readonly string $cookieName = 'sistem_pay_session',
        private readonly int $lifetimeSeconds = 7200,
        private readonly bool $secure = false,
        private readonly bool $httpOnly = true,
        private readonly string $sameSite = 'Lax',
        private readonly string $savePath = '',
        private readonly bool $enforceFingerprint = true,
        private readonly int $rotateIntervalSeconds = 900,
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->touch();
            return;
        }

        if ($this->savePath !== '' && is_dir($this->savePath) && is_writable($this->savePath)) {
            session_save_path($this->savePath);
        }

        if ($this->cookieName !== '') {
            session_name($this->cookieName);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', $this->httpOnly ? '1' : '0');
        ini_set('session.cookie_secure', $this->secure ? '1' : '0');
        ini_set('session.gc_maxlifetime', (string) $this->lifetimeSeconds);

        session_set_cookie_params([
            'lifetime' => $this->lifetimeSeconds,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => $this->httpOnly,
            'samesite' => $this->sameSite,
        ]);

        session_start();
        $this->enforceInactivityTimeout();
        $this->enforceFingerprint();
        $this->rotateIfNeeded();
        $this->touch();
    }

    /**
     * @param array<string, mixed> $authUser
     */
    public function login(array $authUser): void
    {
        $this->start();
        session_regenerate_id(true);
        $_SESSION['auth_user'] = $authUser;
        $_SESSION['_fingerprint'] = $this->currentFingerprint();
        $_SESSION['_last_regenerated'] = time();
        $this->touch();
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'] ?? '/',
                    'domain' => $params['domain'] ?? '',
                    'secure' => (bool) ($params['secure'] ?? false),
                    'httponly' => (bool) ($params['httponly'] ?? true),
                    'samesite' => (string) ($params['samesite'] ?? 'Lax'),
                ]
            );
        }

        session_destroy();
    }

    public function isAuthenticated(): bool
    {
        $this->start();
        return isset($_SESSION['auth_user']) && is_array($_SESSION['auth_user']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        $this->start();
        $user = $_SESSION['auth_user'] ?? null;
        return is_array($user) ? $user : null;
    }

    public function touch(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_last_activity'] = time();
        }
    }

    private function enforceInactivityTimeout(): void
    {
        $lastActivity = (int) ($_SESSION['_last_activity'] ?? 0);
        if ($lastActivity > 0 && (time() - $lastActivity) > $this->lifetimeSeconds) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
    }

    private function enforceFingerprint(): void
    {
        if (!$this->enforceFingerprint || !isset($_SESSION['auth_user'])) {
            return;
        }

        $fingerprint = (string) ($_SESSION['_fingerprint'] ?? '');
        $current = $this->currentFingerprint();
        if ($fingerprint === '') {
            $_SESSION['_fingerprint'] = $current;
            return;
        }

        if (!hash_equals($fingerprint, $current)) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
    }

    private function rotateIfNeeded(): void
    {
        if ($this->rotateIntervalSeconds <= 0) {
            return;
        }

        $lastRegenerated = (int) ($_SESSION['_last_regenerated'] ?? 0);
        if ($lastRegenerated <= 0) {
            $_SESSION['_last_regenerated'] = time();
            return;
        }

        if ((time() - $lastRegenerated) >= $this->rotateIntervalSeconds) {
            session_regenerate_id(true);
            $_SESSION['_last_regenerated'] = time();
        }
    }

    private function currentFingerprint(): string
    {
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
        $accept = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        return hash('sha256', $userAgent . '|' . $accept . '|' . $this->maskedIp($remote));
    }

    private function maskedIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0';
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $segments = explode(':', $ip);
            return implode(':', array_slice($segments, 0, 4)) . '::';
        }

        return $ip;
    }
}
