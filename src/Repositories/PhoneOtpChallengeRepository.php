<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use DateTimeInterface;
use PDO;

final class PhoneOtpChallengeRepository
{
    public static function deleteForPhone(string $phone): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM phone_otp_challenges WHERE phone = :phone');
        $stmt->execute(['phone' => $phone]);
    }

    public static function deleteById(string $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM phone_otp_challenges WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @return array{id: string, salt_hex: string, code_hash: string, attempts: int}|null
     */
    public static function findValid(string $phone): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, salt_hex, code_hash, attempts FROM phone_otp_challenges
             WHERE phone = :phone AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(['phone' => $phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'salt_hex' => (string) $row['salt_hex'],
            'code_hash' => (string) $row['code_hash'],
            'attempts' => (int) $row['attempts'],
        ];
    }

    public static function incrementAttempts(string $id): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE phone_otp_challenges SET attempts = attempts + 1 WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public static function insert(
        string $id,
        string $phone,
        string $saltHex,
        string $codeHash,
        DateTimeInterface $expiresAt
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO phone_otp_challenges (id, phone, salt_hex, code_hash, expires_at, attempts)
             VALUES (:id, :phone, :salt_hex, :code_hash, :expires_at, 0)'
        );
        $stmt->execute([
            'id' => $id,
            'phone' => $phone,
            'salt_hex' => $saltHex,
            'code_hash' => $codeHash,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
    }
}
