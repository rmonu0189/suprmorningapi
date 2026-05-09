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
        $nowUtc = gmdate('Y-m-d H:i:s');
        $stmt = Database::connection()->prepare(
            'INSERT INTO wallet_holds (id, user_id, order_id, amount, status, created_at, updated_at)
             VALUES (:id, :uid, :oid, :amt, \'active\', :created_at, :updated_at)'
        );
        $stmt->execute([
            'id' => $id,
            'uid' => $userId,
            'oid' => $orderId,
            'amt' => round($amount, 2),
            'created_at' => $nowUtc,
            'updated_at' => $nowUtc,
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

    /** @return list<array<string, mixed>> */
    public static function findActiveByUserId(string $userId): array
    {
        if (!self::isAvailable()) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, order_id, amount, status, created_at, updated_at
             FROM wallet_holds
             WHERE user_id = :uid AND status = :st
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['uid' => $userId, 'st' => 'active']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return list<array<string, mixed>> */
    public static function findExpiredActive(string $cutoffUtc, ?string $userId = null): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $params = ['st' => 'active', 'cutoff' => $cutoffUtc];
        $whereUser = '';
        if ($userId !== null && $userId !== '') {
            $whereUser = ' AND user_id = :uid';
            $params['uid'] = $userId;
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, order_id, amount, status, created_at, updated_at
             FROM wallet_holds
             WHERE status = :st AND created_at <= :cutoff' . $whereUser . '
             ORDER BY created_at ASC, id ASC
             LIMIT 100'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    public static function updateStatus(string $holdId, string $status): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE wallet_holds SET status = :st WHERE id = :id'
        );
        $stmt->execute(['st' => $status, 'id' => $holdId]);
    }

    public static function updateStatusIfActive(string $holdId, string $status): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE wallet_holds SET status = :st WHERE id = :id AND status = :active'
        );
        $stmt->execute(['st' => $status, 'id' => $holdId, 'active' => 'active']);

        return $stmt->rowCount() > 0;
    }

    public static function countActiveByUserId(string $userId): int
    {
        if (!self::isAvailable()) {
            return 0;
        }
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM wallet_holds WHERE user_id = :uid AND status = :st'
        );
        $stmt->execute(['uid' => $userId, 'st' => 'active']);
        return (int) $stmt->fetchColumn();
    }

    public static function countByUserId(string $userId): int
    {
        if (!self::isAvailable()) {
            return 0;
        }
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM wallet_holds WHERE user_id = :uid'
        );
        $stmt->execute(['uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public static function findByUserId(string $userId, int $limit, int $offset = 0): array
    {
        if (!self::isAvailable()) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, order_id, amount, status, created_at, updated_at
             FROM wallet_holds
             WHERE user_id = :uid
             ORDER BY created_at DESC, id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue('uid', $userId);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue('off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }
}
