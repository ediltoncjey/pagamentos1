<?php

declare(strict_types=1);

namespace App\Repositories;

final class RoleRepository extends BaseRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM roles WHERE name = :name LIMIT 1',
            ['name' => $name]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->fetchAll('SELECT * FROM roles ORDER BY id ASC');
    }

    public function idByName(string $name): int
    {
        $role = $this->findByName($name);
        return $role !== null ? (int) $role['id'] : 0;
    }
}
