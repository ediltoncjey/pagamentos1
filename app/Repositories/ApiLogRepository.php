<?php

declare(strict_types=1);

namespace App\Repositories;

final class ApiLogRepository extends BaseRepository
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $this->execute(
            'INSERT INTO api_logs (
                service, endpoint, method, request_headers, request_body,
                response_status, response_body, latency_ms, correlation_id,
                idempotency_key, order_id, created_at
             ) VALUES (
                :service, :endpoint, :method, :request_headers, :request_body,
                :response_status, :response_body, :latency_ms, :correlation_id,
                :idempotency_key, :order_id, UTC_TIMESTAMP()
             )',
            [
                'service' => $data['service'],
                'endpoint' => $data['endpoint'],
                'method' => $data['method'],
                'request_headers' => $data['request_headers'] ?? '{}',
                'request_body' => $data['request_body'] ?? '{}',
                'response_status' => $data['response_status'] ?? null,
                'response_body' => $data['response_body'] ?? '{}',
                'latency_ms' => $data['latency_ms'] ?? null,
                'correlation_id' => $data['correlation_id'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'order_id' => $data['order_id'] ?? null,
            ]
        );

        return (int) $this->pdo()->lastInsertId();
    }
}
