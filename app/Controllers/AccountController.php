<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AccountService;
use App\Utils\Csrf;
use App\Utils\DashboardShell;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Sanitizer;
use App\Utils\SessionManager;
use RuntimeException;
use Throwable;

final class AccountController
{
    public function __construct(
        private readonly AccountService $account,
        private readonly DashboardShell $shell,
        private readonly SessionManager $session,
        private readonly Csrf $csrf,
        private readonly Sanitizer $sanitizer,
    ) {
    }

    public function profile(Request $request): Response
    {
        $user = $this->requireUser();

        try {
            $data = $this->account->getProfile((int) $user['id']);
        } catch (Throwable $exception) {
            return $this->errorPage((string) $user['role'], 'profile', 'Erro ao carregar perfil', $exception->getMessage());
        }

        if ($this->wantsJson($request)) {
            return Response::json($data);
        }

        return new Response(200, $this->renderProfilePage($user, $data, $request->query()));
    }

    public function updateProfile(Request $request): Response
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];

        try {
            $result = $this->account->updateProfile(
                userId: $userId,
                input: $request->body(),
                avatarFile: $request->file('avatar'),
                context: $this->context($request)
            );

            $this->refreshSession($result['user'] ?? $user);

            if ($this->wantsJson($request)) {
                return Response::json([
                    'message' => 'Perfil atualizado com sucesso.',
                    'data' => $result,
                ]);
            }

            return Response::redirect('/account/profile?updated=1');
        } catch (Throwable $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['error' => $exception->getMessage()], 422);
            }

            return Response::redirect('/account/profile?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function security(Request $request): Response
    {
        $user = $this->requireUser();
        if ($this->wantsJson($request)) {
            return Response::json([
                'role' => $user['role'],
                'two_factor_ready' => true,
                'password_policy' => [
                    'min_length' => 8,
                    'mixed_case' => true,
                    'number' => true,
                    'symbol' => true,
                ],
            ]);
        }

        return new Response(200, $this->renderSecurityPage($user, $request->query()));
    }

    public function updateSecurity(Request $request): Response
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];

        try {
            $this->account->changePassword(
                userId: $userId,
                input: $request->body(),
                context: $this->context($request)
            );

            if ($this->wantsJson($request)) {
                return Response::json(['message' => 'Password atualizada com sucesso.']);
            }

            return Response::redirect('/account/security?updated=1');
        } catch (Throwable $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['error' => $exception->getMessage()], 422);
            }

            return Response::redirect('/account/security?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function preferences(Request $request): Response
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];

        try {
            $preferences = $this->account->getPreferences($userId);
        } catch (Throwable $exception) {
            return $this->errorPage((string) $user['role'], 'preferences', 'Erro ao carregar preferencias', $exception->getMessage());
        }

        if ($this->wantsJson($request)) {
            return Response::json([
                'preferences' => $preferences,
            ]);
        }

        return new Response(200, $this->renderPreferencesPage($user, $preferences, $request->query()));
    }

    public function updatePreferences(Request $request): Response
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];

        try {
            $preferences = $this->account->updatePreferences(
                userId: $userId,
                input: $request->body(),
                context: $this->context($request)
            );

            if ($this->wantsJson($request)) {
                return Response::json([
                    'message' => 'Preferencias atualizadas com sucesso.',
                    'preferences' => $preferences,
                ]);
            }

            return Response::redirect('/account/preferences?updated=1');
        } catch (Throwable $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['error' => $exception->getMessage()], 422);
            }

            return Response::redirect('/account/preferences?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function avatar(Request $request): Response
    {
        $user = $this->requireUser();
        $profile = $this->account->getProfile((int) $user['id']);
        $settings = (array) ($profile['settings'] ?? []);
        $relative = trim((string) ($settings['avatar_path'] ?? ''));
        if ($relative === '') {
            return Response::text('Avatar not configured.', 404);
        }

        $relative = str_replace('\\', '/', $relative);
        $relative = str_replace('..', '', $relative);
        $absolute = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'private'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (!is_file($absolute)) {
            return Response::text('Avatar not found.', 404);
        }

        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $absolute);
                if (is_string($detected) && trim($detected) !== '') {
                    $mime = $detected;
                }
                finfo_close($finfo);
            }
        }

        return Response::file($absolute, basename($absolute), $mime, false);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $data
     * @param array<string, mixed> $query
     */
    private function renderProfilePage(array $user, array $data, array $query): string
    {
        $userData = (array) ($data['user'] ?? []);
        $settings = (array) ($data['settings'] ?? []);
        $role = (string) ($user['role'] ?? 'reseller');
        $csrfToken = $this->safe($this->csrf->token());

        $name = $this->safe((string) ($userData['name'] ?? ''));
        $email = $this->safe((string) ($userData['email'] ?? ''));
        $phone = $this->safe((string) ($userData['phone'] ?? ''));
        $status = $this->safe((string) ($userData['status'] ?? 'active'));
        $lastLogin = $this->safe((string) ($userData['last_login_at'] ?? '-'));
        $createdAt = $this->safe((string) ($userData['created_at'] ?? '-'));
        $hasAvatar = trim((string) ($settings['avatar_path'] ?? '')) !== '';
        $avatarState = $hasAvatar ? 'Configurado' : 'Nao configurado';
        $avatarPreview = $hasAvatar
            ? '<img src="/account/avatar?t=' . rawurlencode((string) time()) . '" alt="Avatar" class="profile-avatar-preview">'
            : '<div class="profile-avatar-placeholder"><i class="bi bi-person-circle"></i></div>';

        $alert = $this->alertFromQuery($query, 'Perfil atualizado com sucesso.');

        $content = <<<HTML
{$alert}

<section class="dashboard-hero">
  <div class="dashboard-hero__row">
    <div>
      <h2 class="dashboard-hero__title">Perfil da conta</h2>
      <p class="dashboard-hero__subtitle">Atualize dados pessoais e avatar sem sair do ambiente seguro da plataforma.</p>
      <div class="hero-pills">
        <span class="pill"><i class="bi bi-person-vcard"></i> Role: {$this->safe(strtoupper($role))}</span>
        <span class="pill"><i class="bi bi-shield-check"></i> Estado: {$status}</span>
        <span class="pill"><i class="bi bi-image"></i> Avatar: {$avatarState}</span>
      </div>
    </div>
    <div class="hero-insight">
      <article class="hero-insight__item">
        <div class="hero-insight__label">Ultimo login</div>
        <div class="hero-insight__value">{$lastLogin}</div>
        <div class="hero-insight__meta">Criada em {$createdAt}</div>
      </article>
    </div>
  </div>
</section>

<div class="content-grid content-grid--account">
  <div class="content-main">
    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Dados pessoais</h3>
          <p class="panel__subtitle">Nome, email, telefone e avatar utilizados na plataforma.</p>
        </div>
      </div>

      <form method="post" action="/account/profile" enctype="multipart/form-data" class="panel__body">
        <input type="hidden" name="{$this->safe($this->csrf->tokenName())}" value="{$csrfToken}">

        <div class="form-grid">
          <div class="form-group form-group--full">
            <label class="label">Nome completo</label>
            <input class="input" type="text" name="name" maxlength="120" value="{$name}" required>
          </div>

          <div class="form-group">
            <label class="label">Email</label>
            <input class="input" type="email" name="email" maxlength="160" value="{$email}" required>
          </div>

          <div class="form-group">
            <label class="label">Telefone</label>
            <input class="input" type="text" name="phone" maxlength="20" value="{$phone}" placeholder="25884XXXXXXX">
          </div>

          <div class="form-group form-group--full">
            <label class="label">Foto de perfil</label>
            <input class="input" type="file" name="avatar" accept=".jpg,.jpeg,.png,.webp">
            <div class="form-hint">Extensoes permitidas: jpg, jpeg, png, webp.</div>
          </div>
        </div>

        <div class="inline-actions" style="margin-top:12px;">
          <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Guardar perfil</button>
          <a class="btn btn-outline" href="{$this->safe($this->homeByRole($role))}"><i class="bi bi-house"></i> Dashboard</a>
        </div>
      </form>
    </section>
  </div>

  <aside class="content-side">
    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Avatar atual</h3>
          <p class="panel__subtitle">A imagem e armazenada em diretorio privado.</p>
        </div>
      </div>
      <div class="profile-avatar-wrap">{$avatarPreview}</div>
    </section>

    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Boas praticas</h3>
          <p class="panel__subtitle">Recomendacoes para manter a conta segura e atualizada.</p>
        </div>
      </div>
      <ul class="stats-list">
        <li><span class="stats-list__label">Email corporativo</span><span class="stats-list__value">Recomendado</span></li>
        <li><span class="stats-list__label">Telefone com DDI</span><span class="stats-list__value">258...</span></li>
        <li><span class="stats-list__label">Avatar otimizado</span><span class="stats-list__value">.jpg/.png</span></li>
        <li><span class="stats-list__label">Revisao trimestral</span><span class="stats-list__value">Ativo</span></li>
      </ul>
    </section>
  </aside>
</div>
HTML;

        return $this->shell->render([
            'role' => $role,
            'active' => 'profile',
            'title' => 'Perfil',
            'subtitle' => 'Gerencie dados pessoais e identidade da conta.',
            'breadcrumbs' => [
                ['label' => strtoupper($role)],
                ['label' => 'Settings'],
                ['label' => 'Profile'],
            ],
            'toolbar' => '<a class="btn btn-outline" href="/account/security"><i class="bi bi-shield-lock"></i> Seguranca</a><a class="btn btn-outline" href="/account/preferences"><i class="bi bi-sliders"></i> Preferencias</a>',
            'content' => $content,
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     */
    private function renderSecurityPage(array $user, array $query): string
    {
        $role = (string) ($user['role'] ?? 'reseller');
        $alert = $this->alertFromQuery($query, 'Password atualizada com sucesso.');
        $csrfToken = $this->safe($this->csrf->token());

        $content = <<<HTML
{$alert}

<section class="dashboard-hero">
  <div class="dashboard-hero__row">
    <div>
      <h2 class="dashboard-hero__title">Seguranca da conta</h2>
      <p class="dashboard-hero__subtitle">Altere a password com validacao da senha atual e requisitos de complexidade.</p>
      <div class="hero-pills">
        <span class="pill"><i class="bi bi-shield-lock"></i> Password policy ativa</span>
        <span class="pill"><i class="bi bi-lock"></i> Validacao da password atual</span>
        <span class="pill"><i class="bi bi-phone"></i> Preparado para 2FA</span>
      </div>
    </div>
    <div class="hero-insight">
      <article class="hero-insight__item">
        <div class="hero-insight__label">Estado 2FA</div>
        <div class="hero-insight__value">Roadmap</div>
        <div class="hero-insight__meta">Estrutura pronta para futura ativacao</div>
      </article>
    </div>
  </div>
</section>

<div class="content-grid content-grid--account">
  <div class="content-main">
    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Alterar password</h3>
          <p class="panel__subtitle">Use uma password forte e exclusiva para proteger o acesso.</p>
        </div>
      </div>

      <form method="post" action="/account/security" class="panel__body">
        <input type="hidden" name="{$this->safe($this->csrf->tokenName())}" value="{$csrfToken}">

        <div class="form-grid">
          <div class="form-group form-group--full">
            <label class="label">Password atual</label>
            <input class="input" type="password" name="current_password" minlength="8" required>
          </div>

          <div class="form-group">
            <label class="label">Nova password</label>
            <input class="input" type="password" name="new_password" minlength="8" required>
          </div>

          <div class="form-group">
            <label class="label">Confirmar nova password</label>
            <input class="input" type="password" name="confirm_password" minlength="8" required>
          </div>
        </div>

        <div class="inline-actions" style="margin-top:12px;">
          <button class="btn btn-primary" type="submit"><i class="bi bi-shield-check"></i> Atualizar password</button>
        </div>
      </form>
    </section>
  </div>

  <aside class="content-side">
    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Requisitos</h3>
          <p class="panel__subtitle">Padrao de complexidade aplicado no backend.</p>
        </div>
      </div>
      <ul class="stats-list">
        <li><span class="stats-list__label">Minimo de caracteres</span><span class="stats-list__value">8</span></li>
        <li><span class="stats-list__label">Maiusculas e minusculas</span><span class="stats-list__value">Obrigatorio</span></li>
        <li><span class="stats-list__label">Numero</span><span class="stats-list__value">Obrigatorio</span></li>
        <li><span class="stats-list__label">Simbolo</span><span class="stats-list__value">Obrigatorio</span></li>
      </ul>
    </section>
  </aside>
</div>
HTML;

        return $this->shell->render([
            'role' => $role,
            'active' => 'security',
            'title' => 'Seguranca',
            'subtitle' => 'Controle de password e protecoes de autenticacao.',
            'breadcrumbs' => [
                ['label' => strtoupper($role)],
                ['label' => 'Settings'],
                ['label' => 'Security'],
            ],
            'toolbar' => '<a class="btn btn-outline" href="/account/profile"><i class="bi bi-person-circle"></i> Perfil</a><a class="btn btn-outline" href="/account/preferences"><i class="bi bi-sliders"></i> Preferencias</a>',
            'content' => $content,
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $preferences
     * @param array<string, mixed> $query
     */
    private function renderPreferencesPage(array $user, array $preferences, array $query): string
    {
        $role = (string) ($user['role'] ?? 'reseller');
        $alert = $this->alertFromQuery($query, 'Preferencias atualizadas com sucesso.');
        $csrfToken = $this->safe($this->csrf->token());

        $theme = (string) ($preferences['theme_preference'] ?? 'system');
        $language = $this->safe((string) ($preferences['language'] ?? 'pt-MZ'));
        $timezone = $this->safe((string) ($preferences['timezone'] ?? 'Africa/Maputo'));

        $themeSystem = $theme === 'system' ? 'selected' : '';
        $themeLight = $theme === 'light' ? 'selected' : '';
        $themeDark = $theme === 'dark' ? 'selected' : '';

        $notifySales = (int) ($preferences['notify_sales'] ?? 1) === 1 ? 'checked' : '';
        $notifyPaymentErrors = (int) ($preferences['notify_payment_errors'] ?? 1) === 1 ? 'checked' : '';
        $notifySecurity = (int) ($preferences['notify_security'] ?? 1) === 1 ? 'checked' : '';
        $notifySystem = (int) ($preferences['notify_system'] ?? 1) === 1 ? 'checked' : '';
        $emailReports = (int) ($preferences['email_reports'] ?? 1) === 1 ? 'checked' : '';
        $emailMarketing = (int) ($preferences['email_marketing'] ?? 0) === 1 ? 'checked' : '';
        $showCharts = (int) ($preferences['dashboard_show_charts'] ?? 1) === 1 ? 'checked' : '';
        $showKpis = (int) ($preferences['dashboard_show_kpis'] ?? 1) === 1 ? 'checked' : '';
        $resellerActions = '';
        if ($role === 'reseller') {
            $resellerActions = <<<HTML
<section class="panel">
  <div class="panel__header">
    <div>
      <h3 class="panel__title">Automacao de vendas</h3>
      <p class="panel__subtitle">Gestao de funis e templates de email.</p>
    </div>
  </div>
  <div class="inline-actions">
    <a class="btn btn-outline" href="/reseller/funnels"><i class="bi bi-diagram-3"></i> Funis</a>
    <a class="btn btn-outline" href="/reseller/email-templates"><i class="bi bi-envelope"></i> Emails</a>
  </div>
</section>
HTML;
        }

        $content = <<<HTML
{$alert}

<section class="dashboard-hero">
  <div class="dashboard-hero__row">
    <div>
      <h2 class="dashboard-hero__title">Preferencias da experiencia</h2>
      <p class="dashboard-hero__subtitle">Personalize tema, idioma, notificacoes, timezone e comportamento da dashboard.</p>
      <div class="hero-pills">
        <span class="pill"><i class="bi bi-moon-stars"></i> Tema: {$this->safe(strtoupper($theme))}</span>
        <span class="pill"><i class="bi bi-translate"></i> Idioma: {$language}</span>
        <span class="pill"><i class="bi bi-clock-history"></i> Timezone: {$timezone}</span>
      </div>
    </div>
    <div class="hero-insight">
      <article class="hero-insight__item">
        <div class="hero-insight__label">Estado geral</div>
        <div class="hero-insight__value">Configuravel</div>
        <div class="hero-insight__meta">Preferencias persistidas por utilizador</div>
      </article>
    </div>
  </div>
</section>

<section class="panel">
  <div class="panel__header">
    <div>
      <h3 class="panel__title">Configuracoes pessoais</h3>
      <p class="panel__subtitle">Ajustes de visual, comunicacao e layout do cockpit.</p>
    </div>
  </div>

  <form method="post" action="/account/preferences" class="panel__body">
    <input type="hidden" name="{$this->safe($this->csrf->tokenName())}" value="{$csrfToken}">

    <div class="form-grid">
      <div class="form-group">
        <label class="label">Tema preferido</label>
        <select class="select" name="theme_preference">
          <option value="system" {$themeSystem}>Sistema</option>
          <option value="light" {$themeLight}>Light</option>
          <option value="dark" {$themeDark}>Dark</option>
        </select>
      </div>

      <div class="form-group">
        <label class="label">Idioma</label>
        <input class="input" type="text" name="language" maxlength="12" value="{$language}" placeholder="pt-MZ">
      </div>

      <div class="form-group form-group--full">
        <label class="label">Timezone</label>
        <input class="input" type="text" name="timezone" maxlength="64" value="{$timezone}" placeholder="Africa/Maputo">
      </div>
    </div>

    <div class="grid-two" style="margin-top:12px;">
      <section class="panel panel--nested">
        <div class="panel__header">
          <div>
            <h4 class="panel__title">Notificacoes no sistema</h4>
            <p class="panel__subtitle">Controle o que aparece no sino.</p>
          </div>
        </div>
        <div class="panel__body">
          <label class="switch"><input type="hidden" name="notify_sales" value="0"><input type="checkbox" name="notify_sales" value="1" {$notifySales}> Nova venda</label>
          <label class="switch"><input type="hidden" name="notify_payment_errors" value="0"><input type="checkbox" name="notify_payment_errors" value="1" {$notifyPaymentErrors}> Erros de pagamento</label>
          <label class="switch"><input type="hidden" name="notify_security" value="0"><input type="checkbox" name="notify_security" value="1" {$notifySecurity}> Alertas de seguranca</label>
          <label class="switch"><input type="hidden" name="notify_system" value="0"><input type="checkbox" name="notify_system" value="1" {$notifySystem}> Alertas do sistema</label>
        </div>
      </section>

      <section class="panel panel--nested">
        <div class="panel__header">
          <div>
            <h4 class="panel__title">Email e dashboard</h4>
            <p class="panel__subtitle">Defina relatrios e visibilidade dos blocos.</p>
          </div>
        </div>
        <div class="panel__body">
          <label class="switch"><input type="hidden" name="email_reports" value="0"><input type="checkbox" name="email_reports" value="1" {$emailReports}> Receber relatorios por email</label>
          <label class="switch"><input type="hidden" name="email_marketing" value="0"><input type="checkbox" name="email_marketing" value="1" {$emailMarketing}> Receber comunicacoes comerciais</label>
          <label class="switch"><input type="hidden" name="dashboard_show_charts" value="0"><input type="checkbox" name="dashboard_show_charts" value="1" {$showCharts}> Exibir graficos no dashboard</label>
          <label class="switch"><input type="hidden" name="dashboard_show_kpis" value="0"><input type="checkbox" name="dashboard_show_kpis" value="1" {$showKpis}> Exibir KPIs no dashboard</label>
        </div>
      </section>
    </div>

    <div class="inline-actions" style="margin-top:12px;">
      <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Guardar preferencias</button>
      <a class="btn btn-outline" href="/account/profile"><i class="bi bi-person-circle"></i> Perfil</a>
    </div>
  </form>
</section>
{$resellerActions}
HTML;

        return $this->shell->render([
            'role' => $role,
            'active' => 'preferences',
            'title' => 'Preferencias',
            'subtitle' => 'Personalize tema, idioma e notificacoes.',
            'breadcrumbs' => [
                ['label' => strtoupper($role)],
                ['label' => 'Settings'],
                ['label' => 'Preferences'],
            ],
            'toolbar' => '<a class="btn btn-outline" href="/account/profile"><i class="bi bi-person-circle"></i> Perfil</a><a class="btn btn-outline" href="/account/security"><i class="bi bi-shield-lock"></i> Seguranca</a>',
            'content' => $content,
        ]);
    }

    private function requireUser(): array
    {
        $user = $this->session->user();
        if (!is_array($user) || (int) ($user['id'] ?? 0) <= 0) {
            throw new RuntimeException('Sessao invalida.');
        }

        return $user;
    }

    private function refreshSession(array $user): void
    {
        $this->session->login([
            'id' => (int) ($user['id'] ?? 0),
            'uuid' => (string) ($user['uuid'] ?? ''),
            'role' => (string) ($user['role'] ?? 'reseller'),
            'email' => (string) ($user['email'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'status' => (string) ($user['status'] ?? 'active'),
            'last_login_at' => $user['last_login_at'] ?? null,
        ]);
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        return str_contains($accept, 'application/json') || str_starts_with($request->path(), '/api/');
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Request $request): array
    {
        $user = $this->session->user();
        return [
            'actor_user_id' => $user['id'] ?? null,
            'actor_role' => $user['role'] ?? 'reseller',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => (string) $request->header('X-Request-Id', ''),
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function alertFromQuery(array $query, string $successMessage): string
    {
        if ((string) ($query['updated'] ?? '') === '1') {
            return '<div class="alert alert--success">' . $this->safe($successMessage) . '</div>';
        }

        $error = trim((string) ($query['error'] ?? ''));
        if ($error !== '') {
            return '<div class="alert alert--error">' . $this->safe($error) . '</div>';
        }

        return '';
    }

    private function homeByRole(string $role): string
    {
        return $role === 'admin' ? '/admin/dashboard' : '/reseller/dashboard';
    }

    private function errorPage(string $role, string $active, string $title, string $message): Response
    {
        $content = '<section class="panel"><div class="alert alert--error">' . $this->safe($message) . '</div></section>';
        return new Response(500, $this->shell->render([
            'role' => $role,
            'active' => $active,
            'title' => $title,
            'subtitle' => 'Nao foi possivel concluir a operacao.',
            'breadcrumbs' => [
                ['label' => strtoupper($role)],
                ['label' => 'Settings'],
            ],
            'toolbar' => '<a class="btn btn-outline" href="' . $this->safe($this->homeByRole($role)) . '"><i class="bi bi-house"></i> Dashboard</a>',
            'content' => $content,
        ]));
    }

    private function safe(string $value): string
    {
        return $this->sanitizer->html($value);
    }
}
