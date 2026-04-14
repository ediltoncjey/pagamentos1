<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\DownloadService;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\Sanitizer;

final class DeliveryController
{
    public function __construct(
        private readonly DownloadService $downloads,
        private readonly Sanitizer $sanitizer,
    ) {
    }

    public function access(Request $request): Response
    {
        $token = $this->sanitizer->string((string) $request->route('token', ''), 120);
        $delivery = $this->downloads->consumeToken($token);
        if ($delivery === null) {
            return $this->errorResponse($request, 'Token invalido, expirado ou sem downloads disponiveis.', 404);
        }

        $mode = (string) ($delivery['delivery_mode'] ?? '');
        if ($mode === 'redirect') {
            $targetUrl = (string) ($delivery['target_url'] ?? '');
            if ($targetUrl === '') {
                return $this->errorResponse($request, 'Destino de entrega indisponivel.', 410);
            }

            return Response::redirect($targetUrl, 302)->withHeaders([
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'Referrer-Policy' => 'no-referrer',
            ]);
        }

        if ($mode === 'file') {
            $path = (string) ($delivery['absolute_path'] ?? '');
            if ($path === '' || !is_file($path)) {
                return $this->errorResponse($request, 'Ficheiro nao encontrado para este token.', 410);
            }

            $downloadName = (string) ($delivery['download_name'] ?? basename($path));
            $mime = (string) ($delivery['mime_type'] ?? 'application/octet-stream');
            return Response::file($path, $downloadName, $mime, true);
        }

        return $this->errorResponse($request, 'Modo de entrega desconhecido.', 500);
    }

    public function status(Request $request): Response
    {
        $token = $this->sanitizer->string((string) $request->route('token', ''), 120);
        $snapshot = $this->downloads->validateToken($token);
        if ($snapshot === null) {
            return Response::json([
                'valid' => false,
                'message' => 'Token invalido ou expirado.',
            ], 404);
        }

        return Response::json([
            'valid' => true,
            'delivery' => [
                'mode' => $snapshot['delivery_mode'],
                'access_url' => $snapshot['access_url'],
                'expires_at' => $snapshot['expires_at'],
                'download_count' => $snapshot['download_count'],
                'max_downloads' => $snapshot['max_downloads'],
                'remaining_downloads' => $snapshot['remaining_downloads'],
                'status' => $snapshot['status'],
            ],
        ]);
    }

    private function wantsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept', ''));
        return str_contains($accept, 'application/json') || str_starts_with($request->path(), '/api/');
    }

    private function errorResponse(Request $request, string $message, int $statusCode): Response
    {
        if ($this->wantsJson($request)) {
            return Response::json(['error' => $message], $statusCode);
        }

        $safeMessage = $this->sanitizer->html($message);
        return new Response($statusCode, <<<HTML
<!doctype html>
<html lang="pt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Entrega indisponivel</title>
  <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body { margin:0; min-height:100vh; display:grid; place-items:center; font-family:"Segoe UI",Tahoma,sans-serif; background:linear-gradient(160deg,#f4f9ff 0%,#edf5ff 45%,#fbfdff 100%); color:#1f2d45; padding:20px; }
    .card { width:100%; max-width:640px; background:#fff; border:1px solid #d7e3f6; border-radius:16px; padding:24px; box-shadow:0 20px 44px rgba(10,34,74,.08); }
    h1 { margin:0 0 10px; }
    p { margin:0 0 10px; color:#5f708b; }
    a { color:#0d63bf; text-decoration:none; font-weight:700; }
  </style>
</head>
<body>
  <main class="card">
    <h1>Entrega indisponivel</h1>
    <p>{$safeMessage}</p>
    <p><a href="/">Voltar</a></p>
  </main>
</body>
</html>
HTML);
    }
}
