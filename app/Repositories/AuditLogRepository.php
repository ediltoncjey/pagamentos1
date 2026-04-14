<?php

declare(strict_types=1);

namespace App\Repositories;

final class AuditLogRepository extends BaseRepository
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $this->execute(
            'INSERT INTO audit_logs (
                actor_user_id, actor_role, action, entity_type, entity_id,
                old_values, new_values, ip_address, user_agent, request_id, created_at
             ) VALUES (
                :actor_user_id, :actor_role, :action, :entity_type, :entity_id,
                :old_values, :new_values, :ip_address, :user_agent, :request_id, UTC_TIMESTAMP()
             )',
            [
                'actor_user_id' => $data['actor_user_id'] ?? null,
                'actor_role' => $data['actor_role'] ?? null,
                'action' => $data['action'],
                'entity_type' => $data['entity_type'],
                'entity_id' => $data['entity_id'] ?? null,
                'old_values' => $data['old_values'] ?? null,
                'new_values' => $data['new_values'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
                'request_id' => $data['request_id'] ?? null,
            ]
        );

        return (int) $this->pdo()->lastInsertId();
    }
}
