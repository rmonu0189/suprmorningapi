<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class UserRepository
{
    public const DEFAULT_ROLE = 'user';

    /**
     * @return null if no user; true/false for is_active when row exists
     */
    public static function findActiveFlag(string $id): ?bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT is_active FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $v = $stmt->fetchColumn();

        if ($v === false) {
            return null;
        }

        return (bool) (int) $v;
    }

    /** @return array{id: string, phone: string, email: ?string, full_name: ?string, is_active: bool, role: string, created_at: string}|null */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, phone, email, full_name, is_active, role, created_at FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $fn = $row['full_name'];
        $role = $row['role'] ?? self::DEFAULT_ROLE;

        return [
            'id' => (string) $row['id'],
            'phone' => (string) $row['phone'],
            'email' => $row['email'] !== null && $row['email'] !== '' ? (string) $row['email'] : null,
            'full_name' => $fn !== null && $fn !== '' ? (string) $fn : null,
            'is_active' => (bool) (int) $row['is_active'],
            'role' => $role !== null && $role !== '' ? (string) $role : self::DEFAULT_ROLE,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @return array{id: string, is_active: bool}|null */
    public static function findAuthByPhone(string $phone): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, is_active FROM users WHERE phone = :phone LIMIT 1'
        );
        $stmt->execute(['phone' => $phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'is_active' => (bool) (int) $row['is_active'],
        ];
    }

    public static function phoneTaken(string $phone): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM users WHERE phone = :phone LIMIT 1');
        $stmt->execute(['phone' => $phone]);

        return (bool) $stmt->fetchColumn();
    }

    public static function emailTaken(string $email): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        return (bool) $stmt->fetchColumn();
    }

    public static function insert(
        string $id,
        string $phone,
        ?string $email,
        ?string $fullName,
        bool $isActive,
        string $role = self::DEFAULT_ROLE
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (id, phone, email, full_name, is_active, role)
             VALUES (:id, :phone, :email, :full_name, :is_active, :role)'
        );
        $stmt->execute([
            'id' => $id,
            'phone' => $phone,
            'email' => $email,
            'full_name' => $fullName,
            'is_active' => $isActive ? 1 : 0,
            'role' => $role !== '' ? $role : self::DEFAULT_ROLE,
        ]);
    }

    public static function updateFullName(string $id, ?string $fullName): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET full_name = :full_name WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'full_name' => $fullName,
        ]);
    }

    /**
     * Admin: search users by phone (partial match) and optionally exclude a role.
     *
     * @return list<array{id: string, phone: string, email: ?string, full_name: ?string, is_active: bool, role: string, created_at: string}>
     */
    public static function searchByPhone(string $phoneLike, ?string $excludeRole = null, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $like = '%' . $phoneLike . '%';

        if ($excludeRole !== null && $excludeRole !== '') {
            $stmt = Database::connection()->prepare(
                'SELECT id, phone, email, full_name, is_active, role, created_at
                 FROM users
                 WHERE phone LIKE :like AND role <> :exclude
                 ORDER BY created_at DESC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute(['like' => $like, 'exclude' => $excludeRole]);
        } else {
            $stmt = Database::connection()->prepare(
                'SELECT id, phone, email, full_name, is_active, role, created_at
                 FROM users
                 WHERE phone LIKE :like
                 ORDER BY created_at DESC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute(['like' => $like]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = [
                    'id' => (string) $row['id'],
                    'phone' => (string) $row['phone'],
                    'email' => $row['email'] !== null && $row['email'] !== '' ? (string) $row['email'] : null,
                    'full_name' => $row['full_name'] !== null && $row['full_name'] !== '' ? (string) $row['full_name'] : null,
                    'is_active' => (bool) (int) $row['is_active'],
                    'role' => (string) ($row['role'] ?? self::DEFAULT_ROLE),
                    'created_at' => (string) $row['created_at'],
                ];
            }
        }
        return $out;
    }

    /**
     * Admin: list users excluding a role (defaults to excluding regular "user").
     *
     * @return list<array{id: string, phone: string, email: ?string, full_name: ?string, is_active: bool, role: string, created_at: string}>
     */
    public static function listByRoleExcluding(string $excludeRole = self::DEFAULT_ROLE, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = Database::connection()->prepare(
            'SELECT id, phone, email, full_name, is_active, role, created_at
             FROM users
             WHERE role <> :exclude
             ORDER BY created_at DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute(['exclude' => $excludeRole]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = [
                    'id' => (string) $row['id'],
                    'phone' => (string) $row['phone'],
                    'email' => $row['email'] !== null && $row['email'] !== '' ? (string) $row['email'] : null,
                    'full_name' => $row['full_name'] !== null && $row['full_name'] !== '' ? (string) $row['full_name'] : null,
                    'is_active' => (bool) (int) $row['is_active'],
                    'role' => (string) ($row['role'] ?? self::DEFAULT_ROLE),
                    'created_at' => (string) $row['created_at'],
                ];
            }
        }
        return $out;
    }

    public static function updateRole(string $id, string $role): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET role = :role WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'role' => $role !== '' ? $role : self::DEFAULT_ROLE]);
    }
}
