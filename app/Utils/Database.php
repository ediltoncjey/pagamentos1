<?php

declare(strict_types=1);

namespace App\Utils;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private PDO $pdo;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );

            $this->pdo = new PDO(
                $dsn,
                (string) $config['username'],
                (string) $config['password'],
                $config['options'] ?? []
            );

            $this->pdo->exec("SET time_zone = '+00:00'");
            $this->pdo->exec(sprintf("SET NAMES '%s' COLLATE '%s'", $config['charset'], $config['collation']));
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @template T
     * @param callable(PDO):T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }
}
