<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class UserRepository
{
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

    /** @return array{id: string, phone: string, email: ?string, full_name: string, is_active: bool, created_at: string}|null */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, phone, email, full_name, is_active, created_at FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'phone' => (string) $row['phone'],
            'email' => $row['email'] !== null && $row['email'] !== '' ? (string) $row['email'] : null,
            'full_name' => (string) $row['full_name'],
            'is_active' => (bool) (int) $row['is_active'],
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

    public static function insert(string $id, string $phone, ?string $email, string $fullName, bool $isActive): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (id, phone, email, full_name, is_active)
             VALUES (:id, :phone, :email, :full_name, :is_active)'
        );
        $stmt->execute([
            'id' => $id,
            'phone' => $phone,
            'email' => $email,
            'full_name' => $fullName,
            'is_active' => $isActive ? 1 : 0,
        ]);
    }
}
