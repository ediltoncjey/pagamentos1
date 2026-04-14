(function () {
  var root = document.documentElement;
  var body = document.body;

  var THEME_KEY = 'sistem_pay_theme';
  var SIDEBAR_KEY = 'sistem_pay_sidebar';

  function safeStorageGet(key) {
    try {
      return window.localStorage.getItem(key);
    } catch (error) {
      return null;
    }
  }

  function safeStorageSet(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch (error) {
      // Ignore storage errors.
    }
  }

  function systemTheme() {
    if (!window.matchMedia) {
      return 'dark';
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function getTheme() {
    var preferred = (body && body.getAttribute('data-theme-preference')) || 'system';
    if (preferred === 'dark' || preferred === 'light') {
      return preferred;
    }

    var theme = safeStorageGet(THEME_KEY);
    if (theme === 'dark' || theme === 'light') {
      return theme;
    }

    return systemTheme();
  }

  function applyTheme(theme) {
    root.setAttribute('data-theme', theme);
    safeStorageSet(THEME_KEY, theme);

    var icon = document.querySelector('[data-theme-icon]');
    if (icon) {
      icon.className = theme === 'dark' ? 'bi bi-moon-stars-fill' : 'bi bi-brightness-high-fill';
    }
  }

  function initThemeToggle() {
    applyTheme(getTheme());

    var toggle = document.getElementById('theme-toggle');
    if (!toggle) {
      return;
    }

    toggle.addEventListener('click', function () {
      var current = root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
      applyTheme(current === 'dark' ? 'light' : 'dark');
    });
  }

  function setSidebarCollapsed(collapsed) {
    if (window.innerWidth <= 980) {
      body.classList.remove('sidebar-collapsed');
      return;
    }

    body.classList.toggle('sidebar-collapsed', collapsed);
    safeStorageSet(SIDEBAR_KEY, collapsed ? 'collapsed' : 'expanded');
  }

  function closeMobileSidebar() {
    body.classList.remove('sidebar-open');
  }

  function openMobileSidebar() {
    body.classList.add('sidebar-open');
  }

  function initSidebar() {
    var initialCollapsed = safeStorageGet(SIDEBAR_KEY) === 'collapsed';
    setSidebarCollapsed(initialCollapsed);

    var collapseButtons = document.querySelectorAll('[data-sidebar-collapse]');
    collapseButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        var next = !body.classList.contains('sidebar-collapsed');
        setSidebarCollapsed(next);
      });
    });

    var openButton = document.getElementById('sidebar-open');
    if (openButton) {
      openButton.addEventListener('click', function () {
        openMobileSidebar();
      });
    }

    var overlay = document.getElementById('sidebar-overlay');
    if (overlay) {
      overlay.addEventListener('click', function () {
        closeMobileSidebar();
      });
    }

    window.addEventListener('resize', function () {
      if (window.innerWidth > 980) {
        closeMobileSidebar();
        setSidebarCollapsed(safeStorageGet(SIDEBAR_KEY) === 'collapsed');
      } else {
        body.classList.remove('sidebar-collapsed');
      }
    });
  }

  function closeSiblings(group) {
    var parent = group.parentElement;
    if (!parent) {
      return;
    }

    var siblings = parent.querySelectorAll(':scope > .nav-group');
    siblings.forEach(function (sibling) {
      if (sibling === group) {
        return;
      }

      sibling.classList.remove('is-open');
      var toggle = sibling.querySelector('[data-nav-group-toggle]');
      if (toggle) {
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  function initSubmenus() {
    var toggles = document.querySelectorAll('[data-nav-group-toggle]');
    toggles.forEach(function (toggle) {
      toggle.addEventListener('click', function () {
        var group = toggle.closest('.nav-group');
        if (!group) {
          return;
        }

        var isOpen = group.classList.contains('is-open');
        closeSiblings(group);
        group.classList.toggle('is-open', !isOpen);
        toggle.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
      });
    });
  }

  function initUserMenu() {
    var trigger = document.getElementById('user-menu-toggle');
    var dropdown = document.getElementById('user-menu-dropdown');
    if (!trigger || !dropdown) {
      return;
    }

    function close() {
      dropdown.classList.remove('is-open');
      trigger.setAttribute('aria-expanded', 'false');
    }

    trigger.addEventListener('click', function (event) {
      event.stopPropagation();
      var open = dropdown.classList.toggle('is-open');
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!dropdown.contains(target) && !trigger.contains(target)) {
        close();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        close();
        closeMobileSidebar();
      }
    });
  }

  function initLogout() {
    var trigger = document.getElementById('logout-trigger');
    var form = document.getElementById('logout-form');
    if (!trigger || !form) {
      return;
    }

    trigger.addEventListener('click', function () {
      form.submit();
    });
  }

  function initNotifications() {
    var settings = window.SistemPay || {};
    var basePath = '';
    if (typeof settings.basePath === 'string') {
      basePath = settings.basePath.trim();
    } else if (body && body.getAttribute('data-app-base')) {
      basePath = String(body.getAttribute('data-app-base') || '').trim();
    }

    function withBase(path) {
      if (typeof path !== 'string' || path === '') {
        return path;
      }

      if (path.indexOf('http://') === 0 || path.indexOf('https://') === 0 || path.indexOf('//') === 0) {
        return path;
      }

      if (basePath === '' || basePath === '/') {
        return path;
      }

      if (path.indexOf(basePath + '/') === 0 || path === basePath) {
        return path;
      }

      if (path.charAt(0) === '/') {
        return basePath + path;
      }

      return basePath + '/' + path;
    }

    var endpoint = withBase(settings.notificationsUrl || '/api/notifications');
    var readEndpoint = withBase(settings.notificationsReadUrl || '/api/notifications/read');

    var toggle = document.getElementById('notify-toggle');
    var dropdown = document.getElementById('notify-dropdown');
    var list = document.getElementById('notify-list');
    var countBadge = document.getElementById('notify-count');
    var markAll = document.getElementById('notify-mark-all');
    if (!toggle || !dropdown || !list || !countBadge) {
      return;
    }

    var cache = [];

    function iconByType(type) {
      if (type === 'sale') return 'bi bi-bag-check';
      if (type === 'payment_error') return 'bi bi-exclamation-triangle';
      if (type === 'security') return 'bi bi-shield-exclamation';
      return 'bi bi-bell';
    }

    function severityClass(severity) {
      if (severity === 'success') return 'notify-item--success';
      if (severity === 'danger') return 'notify-item--danger';
      return 'notify-item--warning';
    }

    function formatDate(value) {
      if (!value) return '';
      var date = new Date(value.replace(' ', 'T') + 'Z');
      if (Number.isNaN(date.getTime())) return value;
      return date.toLocaleString();
    }

    function close() {
      dropdown.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
    }

    function setCount(count) {
      if (!count || count < 1) {
        countBadge.hidden = true;
        countBadge.textContent = '0';
        return;
      }

      countBadge.hidden = false;
      countBadge.textContent = String(count > 99 ? '99+' : count);
    }

    function render(items, unreadCount) {
      cache = Array.isArray(items) ? items : [];
      setCount(Number(unreadCount || 0));

      if (!cache.length) {
        list.innerHTML = '<div class="empty-state">Sem notificacoes disponiveis.</div>';
        return;
      }

      list.innerHTML = cache.map(function (item) {
        var key = item.key || '';
        var type = item.type || 'system';
        var title = item.title || 'Notificacao';
        var message = item.message || '';
        var createdAt = formatDate(item.created_at || '');
        var isRead = !!item.is_read;
        var severity = severityClass(item.severity || 'warning');
        var readClass = isRead ? ' is-read' : '';
        var action = isRead ? '<span class="notify-item__read">Lida</span>' : '<button class="notify-item__action" type="button" data-notify-read="' + key + '">Marcar lida</button>';
        return '<article class="notify-item ' + severity + readClass + '">' +
          '<div class="notify-item__icon"><i class="' + iconByType(type) + '"></i></div>' +
          '<div class="notify-item__body">' +
            '<h4 class="notify-item__title">' + title + '</h4>' +
            '<p class="notify-item__message">' + message + '</p>' +
            '<div class="notify-item__meta"><span>' + createdAt + '</span>' + action + '</div>' +
          '</div>' +
        '</article>';
      }).join('');
    }

    function request(url, options) {
      return fetch(url, options || {}).then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }

        return response.json();
      });
    }

    function load() {
      list.innerHTML = '<div class="empty-state">A carregar notificacoes...</div>';
      request(endpoint).then(function (payload) {
        render(payload.items || [], payload.unread_count || 0);
      }).catch(function () {
        list.innerHTML = '<div class="empty-state">Falha ao carregar notificacoes.</div>';
      });
    }

    function markRead(key) {
      if (!key) return;
      request(readEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: key })
      }).then(function (payload) {
        render(payload.items || [], payload.unread_count || 0);
      }).catch(function () {
        load();
      });
    }

    function markAllRead() {
      request(readEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ all: true })
      }).then(function (payload) {
        render(payload.items || [], payload.unread_count || 0);
      }).catch(function () {
        load();
      });
    }

    toggle.addEventListener('click', function (event) {
      event.stopPropagation();
      var open = dropdown.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        load();
      }
    });

    list.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) return;
      var key = target.getAttribute('data-notify-read');
      if (key) {
        event.preventDefault();
        markRead(key);
      }
    });

    if (markAll) {
      markAll.addEventListener('click', function () {
        markAllRead();
      });
    }

    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!dropdown.contains(target) && !toggle.contains(target)) {
        close();
      }
    });
  }

  initThemeToggle();
  initSidebar();
  initSubmenus();
  initUserMenu();
  initNotifications();
  initLogout();
})();
