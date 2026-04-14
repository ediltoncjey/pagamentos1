<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends BaseRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT u.*, r.name AS role
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne(
            'SELECT u.*, r.name AS role
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email
             LIMIT 1',
            ['email' => $email]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPhone(string $phone): ?array
    {
        return $this->fetchOne(
            'SELECT u.*, r.name AS role
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.phone = :phone
             LIMIT 1',
            ['phone' => $phone]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $this->execute(
            'INSERT INTO users (uuid, role_id, name, email, phone, password_hash, status, created_at, updated_at)
             VALUES (:uuid, :role_id, :name, :email, :phone, :password_hash, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'uuid' => $data['uuid'],
                'role_id' => $data['role_id'],
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password_hash' => $data['password_hash'],
                'status' => $data['status'] ?? 'active',
            ]
        );

        return (int) $this->pdo()->lastInsertId();
    }

    public function updateLastLoginAt(int $userId): void
    {
        $this->execute(
            'UPDATE users
             SET last_login_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['id' => $userId]
        );
    }

    public function existsEmailForOtherUser(string $email, int $userId): bool
    {
        $row = $this->fetchOne(
            'SELECT id
             FROM users
             WHERE email = :email
               AND id <> :user_id
             LIMIT 1',
            [
                'email' => $email,
                'user_id' => $userId,
            ]
        );

        return $row !== null;
    }

    public function existsPhoneForOtherUser(string $phone, int $userId): bool
    {
        if (trim($phone) === '') {
            return false;
        }

        $row = $this->fetchOne(
            'SELECT id
             FROM users
             WHERE phone = :phone
               AND id <> :user_id
             LIMIT 1',
            [
                'phone' => $phone,
                'user_id' => $userId,
            ]
        );

        return $row !== null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateProfile(int $userId, array $data): void
    {
        $this->execute(
            'UPDATE users
             SET name = :name,
                 email = :email,
                 phone = :phone,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            [
                'id' => $userId,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] !== '' ? $data['phone'] : null,
            ]
        );
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $this->execute(
            'UPDATE users
             SET password_hash = :password_hash,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            [
                'id' => $userId,
                'password_hash' => $passwordHash,
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUsers(?string $role = null, int $limit = 100): array
    {
        $sql = 'SELECT u.id, u.uuid, u.name, u.email, u.phone, u.status, u.last_login_at, u.created_at, r.name AS role
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id';
        $params = [];

        if ($role !== null && $role !== '') {
            $sql .= ' WHERE r.name = :role';
            $params['role'] = $role;
        }

        $sql .= ' ORDER BY u.id DESC LIMIT ' . max(1, (int) $limit);
        return $this->fetchAll($sql, $params);
    }
}
