<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class WalletHoldRepository
{
    /** @var bool|null */
    private static ?bool $tableExists = null;

    public static function isAvailable(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }

        $pdo = Database::connection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'sqlite') {
                $r = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='wallet_holds' LIMIT 1");
                self::$tableExists = $r !== false && (bool) $r->fetchColumn();

                return self::$tableExists;
            }
            $stmt = $pdo->query("SHOW TABLES LIKE 'wallet_holds'");
            self::$tableExists = $stmt !== false && $stmt->rowCount() > 0;

            return self::$tableExists;
        } catch (\Throwable) {
            self::$tableExists = false;

            return false;
        }
    }

    public static function insertActive(string $id, string $userId, string $orderId, float $amount): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO wallet_holds (id, user_id, order_id, amount, status)
             VALUES (:id, :uid, :oid, :amt, \'active\')'
        );
        $stmt->execute([
            'id' => $id,
            'uid' => $userId,
            'oid' => $orderId,
            'amt' => round($amount, 2),
        ]);
    }

    /** @return array<string, mixed>|null */
    public static function findByOrderId(string $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, order_id, amount, status, created_at, updated_at
             FROM wallet_holds WHERE order_id = :oid LIMIT 1'
        );
        $stmt->execute(['oid' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function updateStatus(string $holdId, string $status): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE wallet_holds SET status = :st WHERE id = :id'
        );
        $stmt->execute(['st' => $status, 'id' => $holdId]);
    }
}
