<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use DateTimeInterface;
use PDO;

final class RefreshTokenRepository
{
    /**
     * @return array{id: string, user_id: string, expires_at: string}|null
     */
    public static function findValidByHash(string $tokenHash): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, expires_at FROM refresh_tokens
             WHERE token_hash = :h AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(['h' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'user_id' => (string) $row['user_id'],
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    public static function insert(
        string $id,
        string $userId,
        string $tokenHash,
        DateTimeInterface $expiresAt,
        ?string $userAgent,
        ?string $deviceLabel
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO refresh_tokens (id, user_id, token_hash, expires_at, user_agent, device_label)
             VALUES (:id, :user_id, :token_hash, :expires_at, :user_agent, :device_label)'
        );
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'user_agent' => $userAgent,
            'device_label' => $deviceLabel,
        ]);
    }

    public static function deleteById(string $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM refresh_tokens WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function deleteByHash(string $tokenHash): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM refresh_tokens WHERE token_hash = :h');
        $stmt->execute(['h' => $tokenHash]);
    }
}
