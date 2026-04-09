<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class UserRepository
{
    public const DEFAULT_ROLE = 'user';
    public const DEFAULT_COUNTRY_CODE = '+91';

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

    /** @return int|null warehouse_id for the user (null when unset or user not found) */
    public static function findWarehouseId(string $id): ?int
    {
        $stmt = Database::connection()->prepare(
            'SELECT warehouse_id FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null || $v === '') {
            return null;
        }
        return (int) $v;
    }

    /** @return array{id: string, phone: string, country_code: string, email: ?string, full_name: ?string, is_active: bool, role: string, warehouse_id: ?int, created_at: string}|null */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, phone, country_code, email, full_name, is_active, role, warehouse_id, created_at FROM users WHERE id = :id LIMIT 1'
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
            'country_code' => isset($row['country_code']) && $row['country_code'] !== null && $row['country_code'] !== '' ? (string) $row['country_code'] : self::DEFAULT_COUNTRY_CODE,
            'email' => $row['email'] !== null && $row['email'] !== '' ? (string) $row['email'] : null,
            'full_name' => $fn !== null && $fn !== '' ? (string) $fn : null,
            'is_active' => (bool) (int) $row['is_active'],
            'role' => $role !== null && $role !== '' ? (string) $role : self::DEFAULT_ROLE,
            'warehouse_id' => isset($row['warehouse_id']) && $row['warehouse_id'] !== null ? (int) $row['warehouse_id'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @return array{id: string, is_active: bool, role: string}|null */
    public static function findAuthByPhone(string $phone, string $countryCode = self::DEFAULT_COUNTRY_CODE): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, is_active, role FROM users WHERE phone = :phone AND country_code = :country_code LIMIT 1'
        );
        $stmt->execute(['phone' => $phone, 'country_code' => $countryCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'is_active' => (bool) (int) $row['is_active'],
            'role' => isset($row['role']) && $row['role'] !== null && $row['role'] !== '' ? (string) $row['role'] : self::DEFAULT_ROLE,
        ];
    }

    /** @return array{id: string, phone: string, country_code: string, email: ?string, full_name: ?string, is_active: bool, role: string, warehouse_id: ?int, created_at: string}|null */
    public static function findByPhoneExact(string $phone, string $countryCode = self::DEFAULT_COUNTRY_CODE): ?array
    {
        $p = trim($phone);
        if ($p === '') return null;
        $stmt = Database::connection()->prepare(
            'SELECT id, phone, country_code, email, full_name, is_active, role, warehouse_id, created_at
             FROM users WHERE phone = :phone AND country_code = :country_code LIMIT 1'
        );
        $stmt->execute(['phone' => $p, 'country_code' => $countryCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return self::formatUserRow($row);
    }

    public static function phoneTaken(string $phone, string $countryCode = self::DEFAULT_COUNTRY_CODE): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM users WHERE phone = :phone AND country_code = :country_code LIMIT 1');
        $stmt->execute(['phone' => $phone, 'country_code' => $countryCode]);

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
        ?string $countryCode,
        ?string $email,
        ?string $fullName,
        bool $isActive,
        string $role = self::DEFAULT_ROLE
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (id, phone, country_code, email, full_name, is_active, role)
             VALUES (:id, :phone, :country_code, :email, :full_name, :is_active, :role)'
        );
        $stmt->execute([
            'id' => $id,
            'phone' => $phone,
            'country_code' => $countryCode !== null && trim($countryCode) !== '' ? trim($countryCode) : self::DEFAULT_COUNTRY_CODE,
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
     * Escape `%` and `_` for SQL LIKE patterns.
     */
    private static function sqlLikeContains(string $term): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return '%' . $escaped . '%';
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, phone: string, email: ?string, full_name: ?string, is_active: bool, role: string, created_at: string}
     */
    private static function formatUserRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'phone' => (string) $row['phone'],
            'country_code' => isset($row['country_code']) && $row['country_code'] !== null && $row['country_code'] !== '' ? (string) $row['country_code'] : self::DEFAULT_COUNTRY_CODE,
            'email' => $row['email'] !== null && $row['email'] !== '' ? (string) $row['email'] : null,
            'full_name' => $row['full_name'] !== null && $row['full_name'] !== '' ? (string) $row['full_name'] : null,
            'is_active' => (bool) (int) $row['is_active'],
            'role' => (string) ($row['role'] ?? self::DEFAULT_ROLE),
            'warehouse_id' => isset($row['warehouse_id']) && $row['warehouse_id'] !== null ? (int) $row['warehouse_id'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    public static function countByRoleExcluding(string $excludeRole = self::DEFAULT_ROLE): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM users WHERE role <> :exclude'
        );
        $stmt->execute(['exclude' => $excludeRole]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Admin: list users excluding a role (defaults to excluding regular "user"), paginated.
     *
     * @return list<array{id: string, phone: string, email: ?string, full_name: ?string, is_active: bool, role: string, created_at: string}>
     */
    public static function listByRoleExcludingPaged(string $excludeRole, int $offset, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $stmt = Database::connection()->prepare(
            'SELECT id, phone, email, full_name, is_active, role, warehouse_id, created_at
             FROM users
             WHERE role <> :exclude
             ORDER BY created_at DESC
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $stmt->execute(['exclude' => $excludeRole]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::formatUserRow($row);
            }
        }

        return $out;
    }

    public static function countSearchAll(string $q): int
    {
        $q = trim($q);
        if ($q === '') {
            return 0;
        }
        $like = self::sqlLikeContains($q);
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM users
             WHERE phone LIKE :like
                OR (email IS NOT NULL AND email LIKE :like2)
                OR (full_name IS NOT NULL AND full_name LIKE :like3)
                OR id LIKE :like4'
        );
        $stmt->execute([
            'like' => $like,
            'like2' => $like,
            'like3' => $like,
            'like4' => $like,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Search all users (any role) by phone, email, name, or id substring.
     *
     * @return list<array{id: string, phone: string, email: ?string, full_name: ?string, is_active: bool, role: string, created_at: string}>
     */
    public static function searchAllPaged(string $q, int $offset, int $limit): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $like = self::sqlLikeContains($q);
        $stmt = Database::connection()->prepare(
            'SELECT id, phone, email, full_name, is_active, role, warehouse_id, created_at
             FROM users
             WHERE phone LIKE :like
                OR (email IS NOT NULL AND email LIKE :like2)
                OR (full_name IS NOT NULL AND full_name LIKE :like3)
                OR id LIKE :like4
             ORDER BY created_at DESC
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $stmt->execute([
            'like' => $like,
            'like2' => $like,
            'like3' => $like,
            'like4' => $like,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::formatUserRow($row);
            }
        }

        return $out;
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
                'SELECT id, phone, email, full_name, is_active, role, warehouse_id, created_at
                 FROM users
                 WHERE phone LIKE :like AND role <> :exclude
                 ORDER BY created_at DESC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute(['like' => $like, 'exclude' => $excludeRole]);
        } else {
            $stmt = Database::connection()->prepare(
                'SELECT id, phone, email, full_name, is_active, role, warehouse_id, created_at
                 FROM users
                 WHERE phone LIKE :like
                 ORDER BY created_at DESC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute(['like' => $like]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::formatUserRow($row);
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
        return self::listByRoleExcludingPaged($excludeRole, 0, max(1, min(500, $limit)));
    }

    public static function updateRole(string $id, string $role): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET role = :role WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'role' => $role !== '' ? $role : self::DEFAULT_ROLE]);
    }

    public static function updateRoleAndWarehouse(string $id, string $role, ?int $warehouseId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET role = :role, warehouse_id = :wid WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'role' => $role !== '' ? $role : self::DEFAULT_ROLE,
            'wid' => $warehouseId,
        ]);
    }

    public static function countByRoleExcludingAndWarehouse(string $excludeRole, int $warehouseId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM users WHERE role <> :exclude AND warehouse_id = :wid'
        );
        $stmt->execute(['exclude' => $excludeRole, 'wid' => $warehouseId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array{id: string, phone: string, country_code: string, email: ?string, full_name: ?string, is_active: bool, role: string, warehouse_id: ?int, created_at: string}>
     */
    public static function listByRoleExcludingWarehousePaged(string $excludeRole, int $warehouseId, int $offset, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $stmt = Database::connection()->prepare(
            'SELECT id, phone, country_code, email, full_name, is_active, role, warehouse_id, created_at
             FROM users
             WHERE role <> :exclude AND warehouse_id = :wid
             ORDER BY created_at DESC
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $stmt->execute(['exclude' => $excludeRole, 'wid' => $warehouseId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::formatUserRow($row);
            }
        }

        return $out;
    }
}
