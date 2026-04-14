<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Services\AuthService;
use App\Utils\Csrf;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Sanitizer;
use App\Utils\SessionManager;
use App\Utils\TooManyRequestsException;
use Throwable;

final class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly Csrf $csrf,
        private readonly SessionManager $session,
        private readonly Sanitizer $sanitizer,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        $this->session->start();
        if ($this->session->isAuthenticated()) {
            return Response::redirect($this->redirectPathByRole());
        }

        $token = $this->csrf->token();
        $html = <<<HTML
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Entrar | SISTEM_PAY</title>
  <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    :root {
      --bg: radial-gradient(circle at 10% 10%, #e7f1ff 0, #f4f8ff 45%, #fbfdff 100%);
      --card: #ffffff;
      --text: #1d293d;
      --muted: #5f6f86;
      --line: #d7e1f2;
      --accent: #0d5db8;
      --accent-hover: #0a4e9b;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", Tahoma, sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 16px;
    }
    .panel {
      width: 100%;
      max-width: 460px;
      border: 1px solid var(--line);
      border-radius: 18px;
      background: var(--card);
      box-shadow: 0 24px 50px rgba(12, 36, 77, .10);
      padding: 28px;
    }
    h1 { margin: 0 0 6px; font-size: 1.7rem; }
    p { margin: 0 0 22px; color: var(--muted); }
    label { display: block; margin: 12px 0 6px; font-weight: 600; font-size: .95rem; }
    input {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 11px 12px;
      font-size: 1rem;
      outline: none;
    }
    input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(13, 93, 184, .12); }
    button {
      margin-top: 16px;
      width: 100%;
      border: 0;
      border-radius: 10px;
      padding: 12px;
      font-size: 1rem;
      font-weight: 700;
      background: var(--accent);
      color: #fff;
      cursor: pointer;
    }
    button:hover { background: var(--accent-hover); }
    .actions { margin-top: 14px; text-align: center; font-size: .93rem; color: var(--muted); }
    .actions a { color: var(--accent); text-decoration: none; font-weight: 600; }
    .error {
      margin-bottom: 12px;
      background: #fff1f0;
      color: #94170f;
      border: 1px solid #f7c4c0;
      border-radius: 10px;
      padding: 10px;
      font-size: .92rem;
    }
  </style>
</head>
<body>
  <main class="panel">
    <h1>Bem-vindo</h1>
    <p>Entre na sua conta para gerir vendas e comissões.</p>
    <form method="post" action="/login">
      <input type="hidden" name="_csrf" value="{$token}">
      <label for="email">Email</label>
      <input id="email" type="email" name="email" autocomplete="email" required>
      <label for="password">Password</label>
      <input id="password" type="password" name="password" autocomplete="current-password" required>
      <button type="submit">Entrar</button>
    </form>
    <div class="actions">
      Ainda não tem conta? <a href="/register">Criar conta de comissionista</a>
    </div>
  </main>
</body>
</html>
HTML;

        return new Response(200, $html);
    }

    public function showRegister(Request $request): Response
    {
        $this->session->start();
        if ($this->session->isAuthenticated()) {
            return Response::redirect($this->redirectPathByRole());
        }

        $token = $this->csrf->token();
        $html = <<<HTML
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Registo | SISTEM_PAY</title>
  <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    :root {
      --bg: linear-gradient(160deg, #f4f9ff 0%, #ecf5ff 42%, #fcfeff 100%);
      --card: #ffffff;
      --line: #d8e4f3;
      --text: #1d2b42;
      --muted: #60718a;
      --accent: #1366c2;
      --accent-hover: #0e539f;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 16px;
      background: var(--bg);
      color: var(--text);
      font-family: "Segoe UI", Tahoma, sans-serif;
    }
    .panel {
      width: 100%;
      max-width: 520px;
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 18px;
      box-shadow: 0 24px 52px rgba(13, 38, 74, .08);
      padding: 28px;
    }
    h1 { margin: 0 0 6px; font-size: 1.6rem; }
    p { margin: 0 0 18px; color: var(--muted); }
    label { display: block; margin: 12px 0 6px; font-weight: 600; }
    input {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 11px 12px;
      font-size: .98rem;
      outline: none;
    }
    input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(19, 102, 194, .10); }
    button {
      margin-top: 16px;
      width: 100%;
      border: 0;
      border-radius: 10px;
      background: var(--accent);
      color: #fff;
      padding: 12px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
    }
    button:hover { background: var(--accent-hover); }
    .actions { margin-top: 14px; text-align: center; color: var(--muted); font-size: .92rem; }
    .actions a { color: var(--accent); text-decoration: none; font-weight: 600; }
  </style>
</head>
<body>
  <main class="panel">
    <h1>Criar Conta</h1>
    <p>Registe-se como comissionista para começar a vender.</p>
    <form method="post" action="/register">
      <input type="hidden" name="_csrf" value="{$token}">
      <label for="name">Nome completo</label>
      <input id="name" type="text" name="name" maxlength="120" required>
      <label for="email">Email</label>
      <input id="email" type="email" name="email" maxlength="160" required>
      <label for="phone">Telefone</label>
      <input id="phone" type="text" name="phone" maxlength="20" placeholder="25884XXXXXXX">
      <label for="password">Password</label>
      <input id="password" type="password" name="password" minlength="8" required>
      <button type="submit">Criar conta</button>
    </form>
    <div class="actions">
      Já possui conta? <a href="/login">Entrar</a>
    </div>
  </main>
</body>
</html>
HTML;

        return new Response(200, $html);
    }

    public function register(Request $request): Response
    {
        try {
            $created = $this->authService->registerReseller($request->body(), $this->requestContext($request));
            $this->session->login($this->sessionPayload($created));

            if ($this->wantsJson($request)) {
                return Response::json([
                    'message' => 'Conta criada com sucesso.',
                    'user' => $this->publicUserData($created),
                    'redirect_to' => '/reseller/dashboard',
                ], 201);
            }

            return Response::redirect('/reseller/dashboard');
        } catch (Throwable $exception) {
            return $this->errorResponse($request, $exception->getMessage(), 422);
        }
    }

    public function login(Request $request): Response
    {
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');

        try {
            $user = $this->authService->attemptLogin($email, $password, $this->requestContext($request));
            if ($user === null) {
                return $this->errorResponse($request, 'Credenciais invalidas.', 401);
            }

            $this->session->login($this->sessionPayload($user));
            $redirect = $this->redirectPathByRole((string) ($user['role'] ?? 'reseller'));

            if ($this->wantsJson($request)) {
                return Response::json([
                    'message' => 'Login efetuado com sucesso.',
                    'user' => $this->publicUserData($user),
                    'redirect_to' => $redirect,
                ]);
            }

            return Response::redirect($redirect);
        } catch (TooManyRequestsException $exception) {
            if ($this->wantsJson($request)) {
                return Response::json([
                    'error' => $exception->getMessage(),
                    'retry_after' => $exception->retryAfter(),
                ], 429);
            }

            return $this->errorResponse($request, $exception->getMessage(), 429);
        } catch (Throwable $exception) {
            return $this->errorResponse($request, $exception->getMessage(), 422);
        }
    }

    public function logout(Request $request): Response
    {
        $user = $this->session->user();
        $this->authService->registerLogout([
            'actor_user_id' => $user['id'] ?? null,
            'actor_role' => $user['role'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => (string) $request->header('X-Request-Id', ''),
        ]);
        $this->session->logout();

        if ($this->wantsJson($request)) {
            return Response::json(['message' => 'Sessao terminada com sucesso.']);
        }

        return Response::redirect('/login');
    }

    public function me(Request $request): Response
    {
        $this->session->start();
        $user = $this->session->user();
        if ($user === null) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        return Response::json([
            'user' => $this->publicUserData($user),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function sessionPayload(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'uuid' => (string) ($user['uuid'] ?? ''),
            'role' => (string) ($user['role'] ?? 'reseller'),
            'email' => (string) $user['email'],
            'name' => (string) $user['name'],
            'status' => (string) ($user['status'] ?? 'active'),
            'last_login_at' => $user['last_login_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function publicUserData(array $user): array
    {
        return [
            'id' => (int) ($user['id'] ?? 0),
            'uuid' => (string) ($user['uuid'] ?? ''),
            'name' => $this->sanitizer->string((string) ($user['name'] ?? ''), 120),
            'email' => $this->sanitizer->email((string) ($user['email'] ?? '')),
            'role' => (string) ($user['role'] ?? 'reseller'),
            'status' => (string) ($user['status'] ?? 'active'),
            'last_login_at' => $user['last_login_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestContext(Request $request): array
    {
        $user = $this->session->user();
        return [
            'actor_user_id' => $user['id'] ?? null,
            'actor_role' => $user['role'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => (string) $request->header('X-Request-Id', ''),
        ];
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        return str_contains($accept, 'application/json') || str_starts_with($request->path(), '/api/');
    }

    private function redirectPathByRole(?string $role = null): string
    {
        $currentRole = $role ?? (string) (($this->session->user()['role'] ?? 'reseller'));
        return $currentRole === 'admin' ? '/admin/dashboard' : '/reseller/dashboard';
    }

    private function errorResponse(Request $request, string $message, int $status = 422): Response
    {
        if ($this->wantsJson($request)) {
            return Response::json(['error' => $message], $status);
        }

        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return new Response($status, <<<HTML
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Erro</title>
  <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body style="font-family:Segoe UI,Tahoma,sans-serif; padding:24px;">
  <h2>Operacao nao concluida</h2>
  <p>{$safeMessage}</p>
  <p><a href="/login">Voltar para login</a></p>
</body>
</html>
HTML);
    }
}
