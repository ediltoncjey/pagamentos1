<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Utils\Request;
use App\Utils\Response;
use App\Utils\SessionManager;

final class AuthMiddleware
{
    public function __construct(
        private readonly SessionManager $session,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $this->session->start();

        if (!$this->session->isAuthenticated()) {
            if (str_starts_with($request->path(), '/api')) {
                return Response::json(['error' => 'Unauthorized'], 401);
            }

            return Response::redirect('/login');
        }

        return $next($request);
    }
}
