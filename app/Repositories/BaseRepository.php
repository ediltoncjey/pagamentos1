<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Utils\Database;
use PDO;
use PDOStatement;

abstract class BaseRepository
{
    public function __construct(
        protected readonly Database $database,
    ) {
    }

    protected function pdo(): PDO
    {
        return $this->database->pdo();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->query($sql, $params);
        $result = $statement->fetch();
        return is_array($result) ? $result : null;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->query($sql, $params);
        $result = $statement->fetchAll();
        return is_array($result) ? $result : [];
    }

    /**
     * @param array<int|string, mixed> $params
     */
    protected function execute(string $sql, array $params = []): bool
    {
        return $this->query($sql, $params)->rowCount() >= 0;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    protected function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
}
