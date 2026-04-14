<?php

declare(strict_types=1);

namespace App\Utils;

use App\Repositories\UserSettingsRepository;

final class DashboardShell
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly Csrf $csrf,
        private readonly Sanitizer $sanitizer,
        private readonly UserSettingsRepository $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @param list<array<string, mixed>> $breadcrumbs
     */
    public function render(array $options): string
    {
        $role = (string) ($options['role'] ?? 'reseller');
        $active = (string) ($options['active'] ?? 'dashboard');
        $title = $this->safe((string) ($options['title'] ?? 'Dashboard'));
        $subtitle = $this->safe((string) ($options['subtitle'] ?? ''));
        $searchPlaceholder = $this->safe((string) ($options['searchPlaceholder'] ?? 'Pesquisar modulos, clientes ou transacoes'));
        $content = (string) ($options['content'] ?? '');
        $toolbar = (string) ($options['toolbar'] ?? '');
        $extraStyles = (string) ($options['extraStyles'] ?? '');
        $extraScripts = (string) ($options['extraScripts'] ?? '');
        $breadcrumbs = is_array($options['breadcrumbs'] ?? null)
            ? $options['breadcrumbs']
            : [];

        $user = $this->session->user() ?? [];
        $displayName = $this->safe((string) ($user['name'] ?? 'Utilizador'));
        $displayEmail = $this->safe((string) ($user['email'] ?? ''));
        $displayRole = strtoupper($this->safe((string) ($user['role'] ?? $role)));
        $avatar = $this->initials((string) ($user['name'] ?? 'SP'));
        $csrfToken = $this->safe($this->csrf->token());
        $appBasePath = $this->appBasePath();
        $appBasePathSafe = $this->safe($appBasePath);
        $preferredTheme = 'system';
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0) {
            try {
                $settings = $this->settings->getByUserId($userId);
                $candidate = strtolower(trim((string) ($settings['theme_preference'] ?? 'system')));
                if (in_array($candidate, ['system', 'light', 'dark'], true)) {
                    $preferredTheme = $candidate;
                }
            } catch (\Throwable) {
            }
        }

        $breadcrumbHtml = $this->renderBreadcrumbs($breadcrumbs);
        $menuHtml = $this->renderMenu($role, $active);

        return <<<HTML
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title} | SISTEM_PAY</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Sora:wght@500;600;700&display=swap">
  <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/assets/dashboard-shell.css">
  {$extraStyles}
</head>
<body class="dashboard-body" data-theme-preference="{$this->safe($preferredTheme)}" data-app-base="{$appBasePathSafe}">
  <div id="sidebar-overlay" class="overlay" aria-hidden="true"></div>

  <div class="shell">
    <aside class="sidebar" aria-label="Navegacao principal">
      <div class="brand">
        <div class="brand__logo">SP</div>
        <div class="brand__text">
          <p class="brand__name">SISTEM_PAY</p>
          <p class="brand__sub">Ledger and Payment Operations</p>
        </div>
      </div>

      <div class="sidebar-scroll">
        {$menuHtml}
      </div>

      <div class="sidebar-footer">
        <button data-sidebar-collapse class="btn btn-outline desktop-only" type="button">
          <i class="bi bi-layout-sidebar-inset"></i>
          Colapsar menu
        </button>
      </div>
    </aside>

    <main class="main">
      <header class="topbar">
        <div class="topbar-row">
          <div class="topbar-left">
            <button id="sidebar-open" class="icon-btn mobile-only" type="button" aria-label="Abrir menu lateral">
              <i class="bi bi-list"></i>
            </button>

            <div>
              {$breadcrumbHtml}
              <h1 class="page-title">{$title}</h1>
              <p class="page-subtitle">{$subtitle}</p>
            </div>
          </div>

          <div class="topbar-right">
            <button data-sidebar-collapse class="icon-btn desktop-only" type="button" aria-label="Colapsar menu">
              <i class="bi bi-layout-sidebar"></i>
            </button>

            <label class="topbar-search desktop-only" aria-label="Pesquisa global">
              <i class="bi bi-search"></i>
              <input type="text" placeholder="{$searchPlaceholder}">
            </label>

            <button id="theme-toggle" class="icon-btn" type="button" aria-label="Alternar tema">
              <i data-theme-icon class="bi bi-moon-stars-fill"></i>
            </button>

            <div class="notify-menu">
              <button id="notify-toggle" class="icon-btn notify-pill" type="button" aria-label="Notificacoes" aria-expanded="false">
                <i class="bi bi-bell"></i>
                <span id="notify-count" class="notify-count" hidden>0</span>
              </button>
              <div id="notify-dropdown" class="dropdown dropdown--notifications" role="menu" aria-hidden="true">
                <div class="dropdown__header">
                  <span class="dropdown__label">Notificacoes</span>
                  <button id="notify-mark-all" class="dropdown__button dropdown__button--small" type="button">Marcar tudo como lido</button>
                </div>
                <div id="notify-list" class="notify-list">
                  <div class="empty-state">A carregar notificacoes...</div>
                </div>
              </div>
            </div>

            <div class="user-menu">
              <button id="user-menu-toggle" class="user-menu__trigger" type="button" aria-expanded="false">
                <span class="avatar">{$avatar}</span>
                <span class="user-meta">
                  <span class="user-meta__name">{$displayName}</span>
                  <span class="user-meta__role">{$displayRole}</span>
                </span>
                <i class="bi bi-chevron-down"></i>
              </button>

              <div id="user-menu-dropdown" class="dropdown" role="menu" aria-hidden="true">
                <div class="dropdown__label">Conta</div>
                <a class="dropdown__link" href="/account/profile"><i class="bi bi-person-circle"></i> Perfil</a>
                <a class="dropdown__link" href="/account/security"><i class="bi bi-shield-lock"></i> Seguranca</a>
                <a class="dropdown__link" href="/account/preferences"><i class="bi bi-sliders"></i> Preferencias</a>
                <div class="dropdown__label">Sessao</div>
                <button id="logout-trigger" class="dropdown__button" type="button"><i class="bi bi-box-arrow-right"></i> Terminar sessao</button>
                <div class="dropdown__label dropdown__label--email">{$displayEmail}</div>
              </div>
            </div>
          </div>
        </div>
      </header>

      <section class="content">
        <div class="page-actions">
          <div class="action-group">
            {$toolbar}
          </div>
        </div>

        <div class="dashboard-stack">
          {$content}
        </div>
      </section>
    </main>
  </div>

  <form id="logout-form" method="post" action="/logout" style="display:none;">
    <input type="hidden" name="{$this->safe($this->csrf->tokenName())}" value="{$csrfToken}">
  </form>

  <script>
    window.SistemPay = {
      basePath: '{$appBasePathSafe}',
      notificationsUrl: '{$this->safe($this->withBasePath('/api/notifications'))}',
      notificationsReadUrl: '{$this->safe($this->withBasePath('/api/notifications/read'))}'
    };
  </script>
  <script src="/assets/dashboard-shell.js"></script>
  {$extraScripts}
</body>
</html>
HTML;
    }

    /**
     * @param list<array<string, mixed>> $breadcrumbs
     */
    private function renderBreadcrumbs(array $breadcrumbs): string
    {
        if ($breadcrumbs === []) {
            return '<nav class="breadcrumbs"><span class="breadcrumbs__item">SISTEM_PAY</span></nav>';
        }

        $parts = [];
        foreach ($breadcrumbs as $index => $crumb) {
            $label = $this->safe((string) ($crumb['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $url = trim((string) ($crumb['url'] ?? ''));
            $isLast = $index === array_key_last($breadcrumbs);
            if ($url !== '' && !$isLast) {
                $parts[] = '<a class="breadcrumbs__item" href="' . $this->safe($url) . '">' . $label . '</a>';
            } else {
                $parts[] = '<span class="breadcrumbs__item">' . $label . '</span>';
            }
        }

        return '<nav class="breadcrumbs">' . implode('<span class="breadcrumbs__sep">/</span>', $parts) . '</nav>';
    }

    private function renderMenu(string $role, string $active): string
    {
        $sections = $this->menuByRole($role);
        $html = '';

        foreach ($sections as $section) {
            $title = $this->safe((string) ($section['title'] ?? ''));
            $items = is_array($section['items'] ?? null) ? $section['items'] : [];

            $html .= '<section class="nav-section">';
            if ($title !== '') {
                $html .= '<h3 class="nav-section__title">' . $title . '</h3>';
            }

            $html .= '<ul class="nav-list">';
            foreach ($items as $item) {
                $html .= $this->renderMenuItem($item, $active);
            }
            $html .= '</ul>';
            $html .= '</section>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderMenuItem(array $item, string $active): string
    {
        $children = is_array($item['children'] ?? null) ? $item['children'] : [];
        if ($children !== []) {
            $groupKey = (string) ($item['key'] ?? '');
            $groupLabel = $this->safe((string) ($item['label'] ?? 'Grupo'));
            $groupIcon = $this->safe((string) ($item['icon'] ?? 'bi-grid'));
            $hasActiveChild = $this->hasActiveChild($children, $active);
            $openClass = $hasActiveChild ? ' is-open has-active' : '';
            $expanded = $hasActiveChild ? 'true' : 'false';

            $html = '<li class="nav-item nav-group' . $openClass . '">';
            $html .= '<button type="button" class="nav-group__toggle" data-nav-group-toggle aria-expanded="' . $expanded . '">';
            $html .= '<i class="bi ' . $groupIcon . '"></i>';
            $html .= '<span class="nav-group__label">' . $groupLabel . '</span>';
            $html .= '<i class="bi bi-chevron-down nav-group__caret"></i>';
            $html .= '</button>';
            $html .= '<ul class="nav-sub-list">';
            foreach ($children as $child) {
                $html .= $this->renderSubItem($child, $active, $groupKey);
            }
            $html .= '</ul>';
            $html .= '</li>';

            return $html;
        }

        $key = (string) ($item['key'] ?? '');
        $label = $this->safe((string) ($item['label'] ?? 'Menu'));
        $icon = $this->safe((string) ($item['icon'] ?? 'bi-circle'));
        $url = (string) ($item['url'] ?? '#');
        $target = (string) ($item['target'] ?? '');
        $isActive = $active !== '' && $active === $key;
        $activeClass = $isActive ? ' is-active' : '';
        $targetAttr = $target !== '' ? ' target="' . $this->safe($target) . '" rel="noopener"' : '';

        return '<li class="nav-item"><a class="nav-link' . $activeClass . '" href="' . $this->safe($url) . '"' . $targetAttr . '>'
            . '<i class="bi ' . $icon . '"></i><span class="nav-link__text">' . $label . '</span></a></li>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderSubItem(array $item, string $active, string $groupKey): string
    {
        $key = (string) ($item['key'] ?? '');
        $label = $this->safe((string) ($item['label'] ?? 'Submenu'));
        $icon = $this->safe((string) ($item['icon'] ?? 'bi-dot'));
        $url = (string) ($item['url'] ?? '#');
        $target = (string) ($item['target'] ?? '');
        $isActive = $active === $key || $active === $groupKey;
        $activeClass = $isActive ? ' is-active' : '';
        $targetAttr = $target !== '' ? ' target="' . $this->safe($target) . '" rel="noopener"' : '';

        return '<li><a class="nav-sub-link' . $activeClass . '" href="' . $this->safe($url) . '"' . $targetAttr . '>'
            . '<i class="bi ' . $icon . '"></i><span class="nav-sub-link__text">' . $label . '</span></a></li>';
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    private function hasActiveChild(array $children, string $active): bool
    {
        foreach ($children as $child) {
            if ((string) ($child['key'] ?? '') === $active) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function menuByRole(string $role): array
    {
        if ($role === 'admin') {
            return [
                [
                    'title' => 'Main',
                    'items' => [
                        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'url' => '/admin/dashboard'],
                        ['key' => 'contacts', 'label' => 'Contacts', 'icon' => 'bi-people', 'url' => '/admin/users'],
                    ],
                ],
                [
                    'title' => 'Transactions',
                    'items' => [
                        [
                            'key' => 'transactions',
                            'label' => 'Transactions',
                            'icon' => 'bi-arrow-left-right',
                            'children' => [
                                ['key' => 'payments', 'label' => 'Payments', 'icon' => 'bi-credit-card-2-front', 'url' => '/admin/payments'],
                                ['key' => 'transactions-list', 'label' => 'Transactions', 'icon' => 'bi-receipt-cutoff', 'url' => '/admin/transactions'],
                                ['key' => 'disputes', 'label' => 'Disputes', 'icon' => 'bi-exclamation-octagon', 'url' => '/admin/disputes'],
                            ],
                        ],
                    ],
                ],
                [
                    'title' => 'Finance',
                    'items' => [
                        [
                            'key' => 'finance',
                            'label' => 'Finance',
                            'icon' => 'bi-wallet2',
                            'children' => [
                                ['key' => 'wallets', 'label' => 'Wallets', 'icon' => 'bi-wallet', 'url' => '/admin/wallets'],
                                ['key' => 'payouts', 'label' => 'Payouts', 'icon' => 'bi-cash-coin', 'url' => '/admin/payouts'],
                            ],
                        ],
                    ],
                ],
                [
                    'title' => 'Settings',
                    'items' => [
                        [
                            'key' => 'settings',
                            'label' => 'Settings',
                            'icon' => 'bi-gear',
                            'children' => [
                                ['key' => 'api-settings', 'label' => 'API Settings', 'icon' => 'bi-plug', 'url' => '/admin/api-settings'],
                                ['key' => 'profile', 'label' => 'Profile', 'icon' => 'bi-person-circle', 'url' => '/account/profile'],
                                ['key' => 'security', 'label' => 'Security', 'icon' => 'bi-shield-lock', 'url' => '/account/security'],
                                ['key' => 'preferences', 'label' => 'Preferences', 'icon' => 'bi-sliders', 'url' => '/account/preferences'],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return [
            [
                'title' => 'Principal',
                'items' => [
                    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'url' => '/reseller/dashboard'],
                    ['key' => 'sales-pages', 'label' => 'Paginas de Venda', 'icon' => 'bi-window-sidebar', 'url' => '/reseller/payment-pages'],
                    ['key' => 'products', 'label' => 'Produtos', 'icon' => 'bi-box-seam', 'url' => '/reseller/products'],
                    ['key' => 'sales', 'label' => 'Vendas', 'icon' => 'bi-receipt-cutoff', 'url' => '/reseller/transactions'],
                    ['key' => 'earnings', 'label' => 'Ganhos', 'icon' => 'bi-cash-stack', 'url' => '/reseller/earnings'],
                ],
            ],
            [
                'title' => 'Conta',
                'items' => [
                    ['key' => 'profile', 'label' => 'Perfil', 'icon' => 'bi-person-circle', 'url' => '/account/profile'],
                    ['key' => 'preferences', 'label' => 'Configuracoes', 'icon' => 'bi-sliders', 'url' => '/account/preferences'],
                ],
            ],
        ];
    }

    private function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'SP';
        }

        $parts = preg_split('/\s+/u', $name) ?: [];
        $letters = '';
        foreach ($parts as $part) {
            $char = mb_substr($part, 0, 1);
            if ($char !== '') {
                $letters .= mb_strtoupper($char);
            }

            if (mb_strlen($letters) >= 2) {
                break;
            }
        }

        return $this->safe($letters !== '' ? $letters : 'SP');
    }

    private function safe(string $value): string
    {
        return $this->sanitizer->html($value);
    }

    private function appBasePath(): string
    {
        $appUrl = (string) Env::get('APP_URL', '');
        if ($appUrl === '') {
            return '';
        }

        $path = trim((string) parse_url($appUrl, PHP_URL_PATH));
        if ($path === '' || $path === '/') {
            return '';
        }

        return '/' . trim($path, '/');
    }

    private function withBasePath(string $path): string
    {
        if ($path === '' || !str_starts_with($path, '/')) {
            return $path;
        }

        $base = $this->appBasePath();
        if ($base === '') {
            return $path;
        }

        if ($path === $base || str_starts_with($path, $base . '/')) {
            return $path;
        }

        return $base . $path;
    }
}
