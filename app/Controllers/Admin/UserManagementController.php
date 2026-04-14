<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AuthService;
use App\Utils\Csrf;
use App\Utils\DashboardShell;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Sanitizer;
use App\Utils\SessionManager;
use Throwable;

final class UserManagementController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly DashboardShell $shell,
        private readonly SessionManager $session,
        private readonly Csrf $csrf,
        private readonly Sanitizer $sanitizer,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = $request->query();
        $role = isset($query['role']) ? (string) $query['role'] : '';
        $role = $role !== '' ? $role : null;
        $limit = isset($query['limit']) ? (int) $query['limit'] : 100;
        $users = $this->authService->listUsers($role, $limit);

        if ($this->wantsJson($request)) {
            return Response::json([
                'users' => $users,
                'total' => count($users),
            ]);
        }

        return new Response(200, $this->renderUsersPage($users, $query));
    }

    public function store(Request $request): Response
    {
        try {
            $actor = $this->session->user();
            $created = $this->authService->registerByAdmin(
                input: $request->body(),
                context: [
                    'actor_user_id' => $actor['id'] ?? null,
                    'actor_role' => $actor['role'] ?? 'admin',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'request_id' => (string) $request->header('X-Request-Id', ''),
                ]
            );

            if ($this->wantsJson($request)) {
                return Response::json([
                    'message' => 'Utilizador criado com sucesso.',
                    'user' => [
                        'id' => (int) $created['id'],
                        'uuid' => (string) $created['uuid'],
                        'name' => (string) $created['name'],
                        'email' => (string) $created['email'],
                        'role' => (string) $created['role'],
                        'status' => (string) $created['status'],
                    ],
                ], 201);
            }

            return Response::redirect('/admin/users?created=1');
        } catch (Throwable $exception) {
            if ($this->wantsJson($request)) {
                return Response::json(['error' => $exception->getMessage()], 422);
            }

            return Response::redirect('/admin/users?error=' . rawurlencode($exception->getMessage()));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $users
     * @param array<string, mixed> $query
     */
    private function renderUsersPage(array $users, array $query): string
    {
        $roleFilter = (string) ($query['role'] ?? '');
        $limitFilter = max(1, (int) ($query['limit'] ?? 100));

        $totalUsers = count($users);
        $adminCount = 0;
        $resellerCount = 0;
        $activeCount = 0;
        $suspendedCount = 0;

        $rows = '';
        foreach ($users as $user) {
            $status = (string) ($user['status'] ?? 'inactive');
            $role = (string) ($user['role'] ?? 'reseller');

            if ($role === 'admin') {
                $adminCount++;
            } else {
                $resellerCount++;
            }

            if ($status === 'active') {
                $activeCount++;
            }

            if ($status === 'suspended') {
                $suspendedCount++;
            }

            $statusBadge = match ($status) {
                'active' => 'badge--success',
                'suspended' => 'badge--danger',
                default => 'badge--warning',
            };

            $roleBadge = $role === 'admin' ? 'badge--warning' : 'badge--muted';

            $rows .= '<tr>'
                . '<td>#' . (int) ($user['id'] ?? 0) . '</td>'
                . '<td><strong>' . $this->safe((string) ($user['name'] ?? '')) . '</strong></td>'
                . '<td>' . $this->safe((string) ($user['email'] ?? '')) . '</td>'
                . '<td><span class="badge ' . $roleBadge . '">' . $this->safe($role) . '</span></td>'
                . '<td><span class="badge ' . $statusBadge . '">' . $this->safe($status) . '</span></td>'
                . '<td>' . $this->safe((string) ($user['created_at'] ?? '-')) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6"><div class="empty-state">Nenhum utilizador encontrado para o filtro selecionado.</div></td></tr>';
        }

        $created = (string) ($query['created'] ?? '') === '1';
        $error = trim((string) ($query['error'] ?? ''));

        $alert = '';
        if ($created) {
            $alert = '<div class="alert alert--success">Utilizador criado com sucesso.</div>';
        } elseif ($error !== '') {
            $alert = '<div class="alert alert--error">' . $this->safe($error) . '</div>';
        }

        $roleSelectedAll = $roleFilter === '' ? 'selected' : '';
        $roleSelectedAdmin = $roleFilter === 'admin' ? 'selected' : '';
        $roleSelectedReseller = $roleFilter === 'reseller' ? 'selected' : '';

        $csrfToken = $this->safe($this->csrf->token());

        $content = <<<HTML
{$alert}

<section class="dashboard-hero">
  <div class="dashboard-hero__row">
    <div>
      <h2 class="dashboard-hero__title">Gestao central de contas e perfis de acesso</h2>
      <p class="dashboard-hero__subtitle">
        Crie novos utilizadores, valide papeis e acompanhe o estado de operacao de admins e comissionistas.
      </p>
      <div class="hero-pills">
        <span class="pill"><i class="bi bi-people"></i> Utilizadores visiveis: {$totalUsers}</span>
        <span class="pill"><i class="bi bi-shield-lock"></i> Admins: {$adminCount}</span>
        <span class="pill"><i class="bi bi-person-badge"></i> Comissionistas: {$resellerCount}</span>
      </div>
    </div>

    <div class="hero-insight">
      <article class="hero-insight__item">
        <div class="hero-insight__label">Contas ativas</div>
        <div class="hero-insight__value">{$activeCount}</div>
        <div class="hero-insight__meta">Prontas para operacao imediata</div>
      </article>
      <article class="hero-insight__item">
        <div class="hero-insight__label">Contas suspensas</div>
        <div class="hero-insight__value">{$suspendedCount}</div>
        <div class="hero-insight__meta">Requerem revisao de acesso</div>
      </article>
    </div>
  </div>
</section>

<div class="kpi-grid">
  <article class="kpi-card">
    <div class="kpi-card__head"><div class="kpi-card__label">Total de contas</div><span class="kpi-card__icon"><i class="bi bi-people-fill"></i></span></div>
    <div class="kpi-card__value">{$totalUsers}</div>
    <div class="kpi-card__meta">Registos retornados pelo filtro atual</div>
  </article>

  <article class="kpi-card">
    <div class="kpi-card__head"><div class="kpi-card__label">Administradores</div><span class="kpi-card__icon"><i class="bi bi-shield-check"></i></span></div>
    <div class="kpi-card__value">{$adminCount}</div>
    <div class="kpi-card__meta">Perfis com privilegio total</div>
  </article>

  <article class="kpi-card">
    <div class="kpi-card__head"><div class="kpi-card__label">Comissionistas</div><span class="kpi-card__icon"><i class="bi bi-person-vcard"></i></span></div>
    <div class="kpi-card__value">{$resellerCount}</div>
    <div class="kpi-card__meta">Perfis de venda digital</div>
  </article>

  <article class="kpi-card">
    <div class="kpi-card__head"><div class="kpi-card__label">Ativacao</div><span class="kpi-card__icon"><i class="bi bi-check2-circle"></i></span></div>
    <div class="kpi-card__value">{$activeCount}</div>
    <div class="kpi-card__meta">Contas ativas no ecossistema</div>
  </article>
</div>

<div class="content-grid content-grid--admin-users">
  <div class="content-main">
    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Filtros de consulta</h3>
          <p class="panel__subtitle">Refine os dados antes de exportar ou criar novos acessos.</p>
        </div>
      </div>

      <form method="get" action="/admin/users" class="form-grid form-grid--triple">
        <div class="form-group">
          <label class="label">Role</label>
          <select class="select" name="role">
            <option value="" {$roleSelectedAll}>Todos</option>
            <option value="admin" {$roleSelectedAdmin}>Admin</option>
            <option value="reseller" {$roleSelectedReseller}>Reseller</option>
          </select>
        </div>

        <div class="form-group">
          <label class="label">Limite</label>
          <input class="input" type="number" name="limit" min="1" max="500" value="{$limitFilter}">
        </div>

        <div class="form-group" style="align-content:end;">
          <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> Aplicar filtros</button>
        </div>
      </form>
    </section>

    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Utilizadores da plataforma</h3>
          <p class="panel__subtitle">Tabela consolidada de administradores e comissionistas.</p>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nome</th>
              <th>Email</th>
              <th>Role</th>
              <th>Status</th>
              <th>Criado em</th>
            </tr>
          </thead>
          <tbody>{$rows}</tbody>
        </table>
      </div>
    </section>
  </div>

  <aside class="content-side">
    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Criar utilizador</h3>
          <p class="panel__subtitle">Registo rapido de admin ou comissionista.</p>
        </div>
      </div>

      <form method="post" action="/admin/users" class="panel__body">
        <input type="hidden" name="_csrf" value="{$csrfToken}">

        <div class="form-group">
          <label class="label">Nome completo</label>
          <input class="input" type="text" name="name" maxlength="120" required>
        </div>

        <div class="form-group">
          <label class="label">Email</label>
          <input class="input" type="email" name="email" maxlength="160" required>
        </div>

        <div class="form-group">
          <label class="label">Telefone (opcional)</label>
          <input class="input" type="text" name="phone" maxlength="20" placeholder="25884XXXXXXX">
        </div>

        <div class="form-group">
          <label class="label">Role</label>
          <select class="select" name="role" required>
            <option value="reseller">reseller</option>
            <option value="admin">admin</option>
          </select>
        </div>

        <div class="form-group">
          <label class="label">Password inicial</label>
          <input class="input" type="password" name="password" minlength="8" required>
        </div>

        <button class="btn btn-primary" type="submit"><i class="bi bi-person-plus"></i> Criar utilizador</button>
      </form>
    </section>

    <section class="panel">
      <div class="panel__header">
        <div>
          <h3 class="panel__title">Politica de acesso</h3>
          <p class="panel__subtitle">Checklist rapido para operacao segura.</p>
        </div>
      </div>

      <ul class="stats-list">
        <li><span class="stats-list__label">Senha minima recomendada</span><span class="stats-list__value">8+ chars</span></li>
        <li><span class="stats-list__label">RBAC ativo</span><span class="stats-list__value">admin / reseller</span></li>
        <li><span class="stats-list__label">CSRF em formularios</span><span class="stats-list__value">Ativo</span></li>
        <li><span class="stats-list__label">Auditoria de registos</span><span class="stats-list__value">Ativo</span></li>
      </ul>
    </section>
  </aside>
</div>
HTML;

        return $this->shell->render([
            'role' => 'admin',
            'active' => 'contacts',
            'title' => 'Gestao de Utilizadores',
            'subtitle' => 'Controle de contas, perfis e papeis de acesso da plataforma.',
            'breadcrumbs' => [
                ['label' => 'Admin'],
                ['label' => 'Contacts'],
            ],
            'toolbar' => '<a class="btn btn-outline" href="/admin/dashboard"><i class="bi bi-grid-1x2"></i> Dashboard</a><a class="btn btn-outline" href="/api/admin/users" target="_blank" rel="noopener"><i class="bi bi-code-slash"></i> JSON</a>',
            'content' => $content,
        ]);
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        return str_contains($accept, 'application/json') || str_starts_with($request->path(), '/api/');
    }

    private function safe(string $value): string
    {
        return $this->sanitizer->html($value);
    }
}
