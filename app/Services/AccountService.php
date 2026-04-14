<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserSettingsRepository;
use App\Utils\Env;
use App\Utils\FileStorage;
use App\Utils\Password;
use App\Utils\Sanitizer;
use App\Utils\Validator;
use RuntimeException;

final class AccountService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserSettingsRepository $settings,
        private readonly AuditLogRepository $auditLogs,
        private readonly Password $password,
        private readonly Sanitizer $sanitizer,
        private readonly Validator $validator,
        private readonly FileStorage $storage,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getProfile(int $userId): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new RuntimeException('Utilizador nao encontrado.');
        }

        $settings = $this->settings->getByUserId($userId);

        return [
            'user' => $user,
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $avatarFile
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function updateProfile(int $userId, array $input, ?array $avatarFile, array $context = []): array
    {
        $current = $this->users->findById($userId);
        if ($current === null) {
            throw new RuntimeException('Utilizador nao encontrado.');
        }

        $currentSettings = $this->settings->getByUserId($userId);

        $payload = [
            'name' => $this->sanitizer->string($input['name'] ?? '', 120),
            'email' => $this->sanitizer->email($input['email'] ?? ''),
            'phone' => $this->sanitizer->phone($input['phone'] ?? ''),
        ];

        $validation = $this->validator->validate($payload, [
            'name' => 'required|min:3|max:120',
            'email' => 'required|email|max:160',
        ]);
        if (($validation['valid'] ?? false) !== true) {
            throw new RuntimeException('Dados de perfil invalidos.');
        }

        if ($this->users->existsEmailForOtherUser($payload['email'], $userId)) {
            throw new RuntimeException('Email ja esta associado a outro utilizador.');
        }

        if ($payload['phone'] !== '' && $this->users->existsPhoneForOtherUser($payload['phone'], $userId)) {
            throw new RuntimeException('Telefone ja esta associado a outro utilizador.');
        }

        $avatarPath = $currentSettings['avatar_path'] ?? null;
        if (is_array($avatarFile) && (int) ($avatarFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $extensions = $this->imageExtensions();
            $maxBytes = max(1024, (int) Env::get('PRODUCT_IMAGE_MAX_BYTES', 5242880));
            $newPath = $this->storage->storeUploadedFile(
                file: $avatarFile,
                subDirectory: 'avatars',
                allowedExtensions: $extensions,
                maxBytes: $maxBytes
            );

            if (is_string($avatarPath) && $avatarPath !== '' && $avatarPath !== $newPath) {
                $this->storage->delete($avatarPath);
            }

            $avatarPath = $newPath;
        }

        $this->users->updateProfile($userId, $payload);
        $this->settings->upsert($userId, [
            'avatar_path' => $avatarPath,
        ]);

        $updated = $this->users->findById($userId);
        if ($updated === null) {
            throw new RuntimeException('Falha ao atualizar perfil.');
        }

        $this->writeAudit(
            action: 'account.profile_updated',
            entityType: 'user',
            entityId: $userId,
            oldValues: [
                'name' => $current['name'] ?? null,
                'email' => $current['email'] ?? null,
                'phone' => $current['phone'] ?? null,
                'avatar_path' => $currentSettings['avatar_path'] ?? null,
            ],
            newValues: [
                'name' => $updated['name'] ?? null,
                'email' => $updated['email'] ?? null,
                'phone' => $updated['phone'] ?? null,
                'avatar_path' => $avatarPath,
            ],
            context: $context
        );

        return [
            'user' => $updated,
            'settings' => $this->settings->getByUserId($userId),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     */
    public function changePassword(int $userId, array $input, array $context = []): void
    {
        $currentPassword = (string) ($input['current_password'] ?? '');
        $newPassword = (string) ($input['new_password'] ?? '');
        $confirmPassword = (string) ($input['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            throw new RuntimeException('Todos os campos de password sao obrigatorios.');
        }

        if ($newPassword !== $confirmPassword) {
            throw new RuntimeException('A confirmacao da nova password nao coincide.');
        }

        if ($newPassword === $currentPassword) {
            throw new RuntimeException('A nova password deve ser diferente da password atual.');
        }

        $this->assertPasswordPolicy($newPassword);

        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new RuntimeException('Utilizador nao encontrado.');
        }

        if (!$this->password->verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
            throw new RuntimeException('Password atual invalida.');
        }

        $this->users->updatePasswordHash($userId, $this->password->hash($newPassword));
        $this->writeAudit(
            action: 'account.password_changed',
            entityType: 'user',
            entityId: $userId,
            oldValues: null,
            newValues: ['password_changed' => true],
            context: $context
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getPreferences(int $userId): array
    {
        return $this->settings->getByUserId($userId);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function updatePreferences(int $userId, array $input, array $context = []): array
    {
        $current = $this->settings->getByUserId($userId);

        $theme = strtolower(trim((string) ($input['theme_preference'] ?? (string) ($current['theme_preference'] ?? 'system'))));
        if (!in_array($theme, ['system', 'light', 'dark'], true)) {
            $theme = 'system';
        }

        $language = $this->sanitizer->string($input['language'] ?? ($current['language'] ?? 'pt-MZ'), 12);
        if ($language === '') {
            $language = 'pt-MZ';
        }

        $timezone = $this->sanitizer->string($input['timezone'] ?? ($current['timezone'] ?? 'Africa/Maputo'), 64);
        if ($timezone === '') {
            $timezone = 'Africa/Maputo';
        }

        $payload = [
            'theme_preference' => $theme,
            'language' => $language,
            'timezone' => $timezone,
            'notify_sales' => $this->boolFromInput($input, 'notify_sales', (int) ($current['notify_sales'] ?? 1)),
            'notify_payment_errors' => $this->boolFromInput($input, 'notify_payment_errors', (int) ($current['notify_payment_errors'] ?? 1)),
            'notify_security' => $this->boolFromInput($input, 'notify_security', (int) ($current['notify_security'] ?? 1)),
            'notify_system' => $this->boolFromInput($input, 'notify_system', (int) ($current['notify_system'] ?? 1)),
            'email_reports' => $this->boolFromInput($input, 'email_reports', (int) ($current['email_reports'] ?? 1)),
            'email_marketing' => $this->boolFromInput($input, 'email_marketing', (int) ($current['email_marketing'] ?? 0)),
            'dashboard_show_charts' => $this->boolFromInput($input, 'dashboard_show_charts', (int) ($current['dashboard_show_charts'] ?? 1)),
            'dashboard_show_kpis' => $this->boolFromInput($input, 'dashboard_show_kpis', (int) ($current['dashboard_show_kpis'] ?? 1)),
        ];

        $this->settings->upsert($userId, $payload);
        $updated = $this->settings->getByUserId($userId);

        $this->writeAudit(
            action: 'account.preferences_updated',
            entityType: 'user_settings',
            entityId: $userId,
            oldValues: [
                'theme_preference' => $current['theme_preference'] ?? null,
                'language' => $current['language'] ?? null,
                'timezone' => $current['timezone'] ?? null,
            ],
            newValues: [
                'theme_preference' => $updated['theme_preference'] ?? null,
                'language' => $updated['language'] ?? null,
                'timezone' => $updated['timezone'] ?? null,
            ],
            context: $context
        );

        return $updated;
    }

    /**
     * @return list<string>
     */
    private function imageExtensions(): array
    {
        $raw = (string) Env::get('PRODUCT_IMAGE_EXTENSIONS', 'jpg,jpeg,png,webp');
        $parts = array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', $raw)
        );

        $parts = array_values(array_filter($parts, static fn (string $value): bool => $value !== ''));
        return $parts !== [] ? $parts : ['jpg', 'jpeg', 'png', 'webp'];
    }

    private function boolFromInput(array $input, string $key, int $default = 0): int
    {
        if (!array_key_exists($key, $input)) {
            return $default === 1 ? 1 : 0;
        }

        $value = $input[$key];
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
                return 1;
            }

            return 0;
        }

        return (int) (filter_var($value, FILTER_VALIDATE_BOOL) ? 1 : 0);
    }

    private function assertPasswordPolicy(string $password): void
    {
        if (mb_strlen($password) < 8 || mb_strlen($password) > 120) {
            throw new RuntimeException('Password deve ter entre 8 e 120 caracteres.');
        }

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

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     * @param array<string, mixed> $context
     */
    private function writeAudit(
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValues,
        ?array $newValues,
        array $context
    ): void {
        $this->auditLogs->create([
            'actor_user_id' => $context['actor_user_id'] ?? null,
            'actor_role' => $context['actor_role'] ?? 'user',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues !== null
                ? json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'new_values' => $newValues !== null
                ? json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'request_id' => $context['request_id'] ?? null,
        ]);
    }
}

