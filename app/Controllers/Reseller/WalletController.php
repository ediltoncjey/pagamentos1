<?php

declare(strict_types=1);

namespace App\Controllers\Reseller;

use App\Services\WalletService;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\SessionManager;

final class WalletController
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly SessionManager $session,
    ) {
    }

    public function overview(Request $request): Response
    {
        try {
            $userId = $this->currentUserId();
        } catch (\RuntimeException) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $query = $request->query();
        $txLimit = min(50, max(1, (int) ($query['tx_limit'] ?? 10)));
        $commissionLimit = min(50, max(1, (int) ($query['commission_limit'] ?? 10)));

        $snapshot = $this->wallets->resellerLedgerSnapshot($userId, $txLimit, $commissionLimit);
        return Response::json([
            'wallet' => $snapshot['balances'],
            'pending_credit_total' => $snapshot['pending_credit_total'],
            'recent_transactions' => $snapshot['recent_transactions'],
            'recent_commissions' => $snapshot['recent_commissions'],
        ]);
    }

    public function transactions(Request $request): Response
    {
        try {
            $userId = $this->currentUserId();
        } catch (\RuntimeException) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $query = $request->query();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($query['per_page'] ?? 25)));

        $result = $this->wallets->listTransactions($userId, $page, $perPage);
        return Response::json([
            'transactions' => $result['items'],
            'pagination' => $result['pagination'],
        ]);
    }

    public function commissions(Request $request): Response
    {
        try {
            $userId = $this->currentUserId();
        } catch (\RuntimeException) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $query = $request->query();
        $limit = min(200, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $items = $this->wallets->listResellerCommissions($userId, $limit, $offset);
        return Response::json([
            'commissions' => $items,
            'meta' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($items),
            ],
        ]);
    }

    private function currentUserId(): int
    {
        $user = $this->session->user();
        $id = (int) ($user['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('Sessao invalida.');
        }

        return $id;
    }
}
