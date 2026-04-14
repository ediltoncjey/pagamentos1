<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Utils\Request;
use App\Utils\Response;
use App\Utils\SessionManager;

final class RoleMiddleware
{
    /**
     * @param list<string> $allowedRoles
     */
    public function __construct(
        private readonly array $allowedRoles = [],
        private readonly ?SessionManager $session = null,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $session = $this->session ?? new SessionManager();
        $session->start();

        $user = $session->user();
        $role = $user['role'] ?? null;
        if (!is_string($role) || !in_array($role, $this->allowedRoles, true)) {
            if (!str_starts_with($request->path(), '/api')) {
                return Response::redirect('/login', 302);
            }

            return Response::json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
