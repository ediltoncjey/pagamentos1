<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Repositories\CommissionRepository;
use App\Services\LedgerService;
use App\Utils\Env;
use App\Utils\Money;
use App\Utils\Request;
use App\Utils\Response;
use App\Utils\SessionManager;
use Throwable;

final class LedgerController
{
    public function __construct(
        private readonly CommissionRepository $commissions,
        private readonly LedgerService $ledger,
        private readonly SessionManager $session,
        private readonly Money $money,
    ) {
    }

    public function pendingCommissions(Request $request): Response
    {
        $query = $request->query();
        $defaultLimit = (int) Env::get('LEDGER_RECONCILE_DEFAULT_LIMIT', 100);
        $maxLimit = (int) Env::get('LEDGER_RECONCILE_MAX_LIMIT', 500);
        $limit = min(max(1, $maxLimit), max(1, (int) ($query['limit'] ?? $defaultLimit)));
        $resellerIdRaw = $query['reseller_id'] ?? null;
        $resellerId = $resellerIdRaw !== null ? (int) $resellerIdRaw : null;

        $items = $this->commissions->listPendingSettlements($limit, $resellerId);
        $totalGross = 0.00;
        $totalPlatform = 0.00;
        $totalReseller = 0.00;

        foreach ($items as $item) {
            $totalGross = $this->money->add($totalGross, (float) ($item['gross_amount'] ?? 0));
            $totalPlatform = $this->money->add($totalPlatform, (float) ($item['platform_fee'] ?? 0));
            $totalReseller = $this->money->add($totalReseller, (float) ($item['reseller_earning'] ?? 0));
        }

        return Response::json([
            'pending_commissions' => $items,
            'summary' => [
                'count' => count($items),
                'total_gross' => $this->money->round($totalGross),
                'total_platform_fee' => $this->money->round($totalPlatform),
                'total_reseller_earning' => $this->money->round($totalReseller),
            ],
            'filters' => [
                'limit' => $limit,
                'reseller_id' => $resellerId,
            ],
        ]);
    }

    public function settleCommission(Request $request): Response
    {
        $commissionId = (int) $request->route('id', 0);
        if ($commissionId <= 0) {
            return Response::json(['error' => 'ID de comissao invalido.'], 422);
        }

        try {
            $updated = $this->ledger->settleCommission($commissionId, $this->actorContext($request));
            return Response::json([
                'message' => 'Comissao reconciliada com sucesso.',
                'commission' => $updated,
            ]);
        } catch (Throwable $exception) {
            $status = str_contains(strtolower($exception->getMessage()), 'not found') ? 404 : 422;
            return Response::json([
                'error' => $exception->getMessage(),
            ], $status);
        }
    }

    public function reconcilePending(Request $request): Response
    {
        $payload = array_merge($request->query(), $request->body());
        $defaultLimit = (int) Env::get('LEDGER_RECONCILE_DEFAULT_LIMIT', 100);
        $maxLimit = (int) Env::get('LEDGER_RECONCILE_MAX_LIMIT', 1000);
        $limit = min(max(1, $maxLimit), max(1, (int) ($payload['limit'] ?? $defaultLimit)));
        $resellerIdRaw = $payload['reseller_id'] ?? null;
        $resellerId = $resellerIdRaw !== null ? (int) $resellerIdRaw : null;

        try {
            $result = $this->ledger->reconcilePendingCommissions(
                limit: $limit,
                resellerId: $resellerId,
                context: $this->actorContext($request)
            );

            return Response::json([
                'message' => 'Reconciliação executada.',
                'result' => $result,
            ]);
        } catch (Throwable $exception) {
            return Response::json([
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function actorContext(Request $request): array
    {
        $user = $this->session->user();
        return [
            'actor_user_id' => $user['id'] ?? null,
            'actor_role' => $user['role'] ?? 'admin',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => (string) $request->header('X-Request-Id', ''),
        ];
    }
}
