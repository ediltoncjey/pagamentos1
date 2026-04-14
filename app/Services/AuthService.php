<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Repositories\WalletRepository;
use App\Utils\LoginThrottle;
use App\Utils\Env;
use App\Utils\Password;
use App\Utils\Sanitizer;
use App\Utils\TooManyRequestsException;
use App\Utils\Uuid;
use App\Utils\Validator;
use RuntimeException;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly WalletRepository $wallets,
        private readonly AuditLogRepository $auditLogs,
        private readonly LoginThrottle $loginThrottle,
        private readonly Password $password,
        private readonly Sanitizer $sanitizer,
        private readonly Validator $validator,
        private readonly Uuid $uuid,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function registerReseller(array $input, array $context = []): array
    {
        return $this->registerUser($input, 'reseller', $context);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function registerByAdmin(array $input, array $context = []): array
    {
        $role = (string) ($input['role'] ?? 'reseller');
        if (!in_array($role, ['admin', 'reseller'], true)) {
            throw new RuntimeException('Role invalida.');
        }

        return $this->registerUser($input, $role, $context);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function registerUser(array $input, string $roleName, array $context = []): array
    {
        $sanitized = [
            'name' => $this->sanitizer->string($input['name'] ?? '', 120),
            'email' => $this->sanitizer->email($input['email'] ?? ''),
            'phone' => $this->sanitizer->phone($input['phone'] ?? ''),
            'password' => (string) ($input['password'] ?? ''),
        ];

        $validation = $this->validator->validate($sanitized, [
            'name' => 'required|min:3|max:120',
            'email' => 'required|email|max:160',
            'password' => 'required|min:8|max:120',
        ]);

        if (!$validation['valid']) {
            throw new RuntimeException('Validation failed: ' . json_encode($validation['errors']));
        }

        $this->assertPasswordPolicy($sanitized['password']);

        if ($this->users->findByEmail($sanitized['email']) !== null) {
            throw new RuntimeException('Email already registered.');
        }

        if ($sanitized['phone'] !== '' && $this->users->findByPhone($sanitized['phone']) !== null) {
            throw new RuntimeException('Phone already registered.');
        }

        $roleId = $this->roles->idByName($roleName);
        if ($roleId <= 0) {
            throw new RuntimeException(sprintf('Role `%s` is not configured.', $roleName));
        }

        $userId = $this->users->create([
            'uuid' => $this->uuid->v4(),
            'role_id' => $roleId,
            'name' => $sanitized['name'],
            'email' => $sanitized['email'],
            'phone' => $sanitized['phone'] !== '' ? $sanitized['phone'] : null,
            'password_hash' => $this->password->hash($sanitized['password']),
            'status' => 'active',
        ]);

        if ($roleName === 'reseller') {
            $this->wallets->createIfMissing($userId, 'MZN');
        }

        $createdUser = $this->users->findById($userId);
        if ($createdUser === null) {
            throw new RuntimeException('Failed to load created user.');
        }

        $this->auditLogs->create([
            'actor_user_id' => $context['actor_user_id'] ?? null,
            'actor_role' => $context['actor_role'] ?? ($roleName === 'admin' ? 'system' : 'guest'),
            'action' => 'auth.register',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'new_values' => json_encode([
                'email' => $createdUser['email'],
                'role' => $createdUser['role'],
                'status' => $createdUser['status'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'request_id' => $context['request_id'] ?? null,
        ]);

        return $createdUser;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function attemptLogin(string $email, string $plainPassword, array $context = []): ?array
    {
        $sanitizedEmail = $this->sanitizer->email($email);
        $ipAddress = (string) ($context['ip_address'] ?? '0.0.0.0');

        try {
            $this->loginThrottle->assertAllowed($sanitizedEmail, $ipAddress);
        } catch (TooManyRequestsException $exception) {
            $this->auditLogs->create([
                'actor_user_id' => null,
                'actor_role' => 'guest',
                'action' => 'auth.login_throttled',
                'entity_type' => 'auth',
                'entity_id' => null,
                'new_values' => json_encode([
                    'email' => $sanitizedEmail,
                    'retry_after' => $exception->retryAfter(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_address' => $ipAddress,
                'user_agent' => $context['user_agent'] ?? null,
                'request_id' => $context['request_id'] ?? null,
            ]);
            throw $exception;
        }

        $user = $this->users->findByEmail($sanitizedEmail);
        if ($user === null) {
            $this->loginThrottle->recordFailure($sanitizedEmail, $ipAddress);
            $this->auditLogs->create([
                'actor_user_id' => null,
                'actor_role' => 'guest',
                'action' => 'auth.login_failed',
                'entity_type' => 'user',
                'entity_id' => null,
                'new_values' => json_encode(['email' => $sanitizedEmail], JSON_UNESCAPED_UNICODE),
                'ip_address' => $ipAddress,
                'user_agent' => $context['user_agent'] ?? null,
                'request_id' => $context['request_id'] ?? null,
            ]);
            return null;
        }

        if (!$this->password->verify($plainPassword, (string) $user['password_hash'])) {
            $this->loginThrottle->recordFailure($sanitizedEmail, $ipAddress);
            $this->auditLogs->create([
                'actor_user_id' => (int) $user['id'],
                'actor_role' => (string) $user['role'],
                'action' => 'auth.login_failed',
                'entity_type' => 'user',
                'entity_id' => (int) $user['id'],
                'ip_address' => $ipAddress,
                'user_agent' => $context['user_agent'] ?? null,
                'request_id' => $context['request_id'] ?? null,
            ]);
            return null;
        }

        if ((string) $user['status'] !== 'active') {
            throw new RuntimeException('Conta inativa ou suspensa.');
        }

        $this->users->updateLastLoginAt((int) $user['id']);
        $freshUser = $this->users->findById((int) $user['id']);
        if ($freshUser !== null) {
            $user = $freshUser;
        }

        $this->loginThrottle->clearFailures($sanitizedEmail, $ipAddress);

        $this->auditLogs->create([
            'actor_user_id' => (int) $user['id'],
            'actor_role' => (string) $user['role'],
            'action' => 'auth.login_success',
            'entity_type' => 'user',
            'entity_id' => (int) $user['id'],
            'ip_address' => $ipAddress,
            'user_agent' => $context['user_agent'] ?? null,
            'request_id' => $context['request_id'] ?? null,
        ]);

        return $user;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function registerLogout(array $context): void
    {
        $userId = (int) ($context['actor_user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $this->auditLogs->create([
            'actor_user_id' => $userId,
            'actor_role' => $context['actor_role'] ?? null,
            'action' => 'auth.logout',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'request_id' => $context['request_id'] ?? null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUsers(?string $role = null, int $limit = 100): array
    {
        return $this->users->listUsers($role, $limit);
    }

    /**
     * @param array<string, mixed> $authConfig
     */
    public function bootstrapDefaultAdmin(array $authConfig): void
    {
        $enabled = (bool) ($authConfig['bootstrap_default_admin'] ?? false);
        if (!$enabled) {
            return;
        }

        $adminEmail = $this->sanitizer->email((string) ($authConfig['default_admin_email'] ?? ''));
        if ($adminEmail === '') {
            return;
        }

        if ($this->users->findByEmail($adminEmail) !== null) {
            return;
        }

        $this->registerUser([
            'name' => (string) ($authConfig['default_admin_name'] ?? 'System Admin'),
            'email' => $adminEmail,
            'password' => (string) ($authConfig['default_admin_password'] ?? 'ChangeMe@123'),
            'phone' => null,
        ], 'admin', [
            'actor_role' => 'system',
        ]);
    }

    private function assertPasswordPolicy(string $password): void
    {
        $requireMixedCase = filter_var(Env::get('SECURITY_PASSWORD_REQUIRE_MIXED_CASE', true), FILTER_VALIDATE_BOOL);
        $requireNumber = filter_var(Env::get('SECURITY_PASSWORD_REQUIRE_NUMBER', true), FILTER_VALIDATE_BOOL);
        $requireSymbol = filter_var(Env::get('SECURITY_PASSWORD_REQUIRE_SYMBOL', true), FILTER_VALIDATE_BOOL);

        if ($requireMixedCase && !preg_match('/[a-z]/', $password)) {
            throw new RuntimeException('Password deve conter letras maiusculas e minusculas.');
        }

        if ($requireMixedCase && !preg_match('/[A-Z]/', $password)) {
            throw new RuntimeException('Password deve conter letras maiusculas e minusculas.');
        }

        if ($requireNumber && !preg_match('/\d/', $password)) {
            throw new RuntimeException('Password deve conter pelo menos um numero.');
        }

        if ($requireSymbol && !preg_match('/[^a-zA-Z\d]/', $password)) {
            throw new RuntimeException('Password deve conter pelo menos um simbolo.');
        }
    }
}
