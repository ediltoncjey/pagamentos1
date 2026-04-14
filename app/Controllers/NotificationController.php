<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\NotificationService;
use App\Utils\Logger;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\SessionManager;

final class NotificationController
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SessionManager $session,
        private readonly Logger $logger,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->session->user();
        $userId = (int) ($user['id'] ?? 0);
        $role = (string) ($user['role'] ?? 'reseller');
        if ($userId <= 0) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $limit = max(1, min(60, (int) ($request->query()['limit'] ?? 12)));
        try {
            $data = $this->notifications->listForUser($userId, $role, $limit);
        } catch (\Throwable $exception) {
            $this->logger->warning('Notification feed fallback triggered', [
                'user_id' => $userId,
                'role' => $role,
                'error' => $exception->getMessage(),
            ]);

            $data = [
                'items' => [],
                'unread_count' => 0,
                'total_returned' => 0,
            ];
        }

        return Response::json($data);
    }

    public function markRead(Request $request): Response
    {
        $user = $this->session->user();
        $userId = (int) ($user['id'] ?? 0);
        $role = (string) ($user['role'] ?? 'reseller');
        if ($userId <= 0) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $body = $request->body();
        if ($body === []) {
            $decoded = json_decode($request->rawBody(), true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
        $key = trim((string) ($body['key'] ?? ''));
        $keys = $body['keys'] ?? null;
        $markAll = filter_var($body['all'] ?? false, FILTER_VALIDATE_BOOL);

        if ($markAll === true) {
            $data = $this->notifications->listForUser($userId, $role, 60);
            $allKeys = array_values(array_map(
                static fn (array $item): string => (string) ($item['key'] ?? ''),
                (array) ($data['items'] ?? [])
            ));
            $this->notifications->markManyRead($userId, $allKeys);
        } elseif (is_array($keys)) {
            $normalized = [];
            foreach ($keys as $candidate) {
                if (!is_string($candidate)) {
                    continue;
                }

                $candidate = trim($candidate);
                if ($candidate !== '') {
                    $normalized[] = $candidate;
                }
            }

            $this->notifications->markManyRead($userId, $normalized);
        } elseif ($key !== '') {
            $this->notifications->markRead($userId, $key);
        } else {
            return Response::json(['error' => 'Nenhuma notificacao informada.'], 422);
        }

        $updated = $this->notifications->listForUser($userId, $role, 12);
        return Response::json([
            'message' => 'Notificacoes atualizadas.',
            'unread_count' => $updated['unread_count'] ?? 0,
            'items' => $updated['items'] ?? [],
        ]);
    }
}
