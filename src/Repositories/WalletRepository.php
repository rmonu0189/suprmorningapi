<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class WalletRepository
{
    public static function ensureWallet(string $userId): void
    {
        $driver = Database::connection()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = 'INSERT OR IGNORE INTO wallets (user_id, balance, currency) VALUES (:uid, 0.00, :cur)';
        } else {
            $sql = 'INSERT IGNORE INTO wallets (user_id, balance, currency) VALUES (:uid, 0.00, :cur)';
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['uid' => $userId, 'cur' => 'INR']);
    }

    public static function findByUserId(string $userId): array
    {
        self::ensureWallet($userId);
        $stmt = Database::connection()->prepare(
            'SELECT user_id, balance, currency, created_at, updated_at
             FROM wallets
             WHERE user_id = :uid
             LIMIT 1'
        );
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                'user_id' => $userId,
                'balance' => 0.0,
                'currency' => 'INR',
                'created_at' => null,
                'updated_at' => null,
            ];
        }
        return [
            'user_id' => (string) $row['user_id'],
            'balance' => (float) $row['balance'],
            'currency' => (string) ($row['currency'] ?? 'INR'),
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }

    public static function credit(
        string $transactionId,
        string $userId,
        float $amount,
        string $source,
        ?string $orderId = null,
        ?string $referenceId = null,
        ?string $note = null
    ): void {
        self::ensureWallet($userId);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE wallets SET balance = balance + :amt WHERE user_id = :uid');
        $stmt->execute(['amt' => $amount, 'uid' => $userId]);

        $tx = $pdo->prepare(
            'INSERT INTO wallet_transactions (id, user_id, order_id, type, source, amount, status, reference_id, note)
             VALUES (:id, :uid, :oid, :type, :source, :amt, :status, :ref, :note)'
        );
        $tx->execute([
            'id' => $transactionId,
            'uid' => $userId,
            'oid' => $orderId,
            'type' => 'credit',
            'source' => $source,
            'amt' => $amount,
            'status' => 'success',
            'ref' => $referenceId,
            'note' => $note,
        ]);
    }

    public static function debit(
        string $transactionId,
        string $userId,
        float $amount,
        string $source,
        ?string $orderId = null,
        ?string $referenceId = null,
        ?string $note = null
    ): bool {
        self::ensureWallet($userId);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE wallets SET balance = balance - :amt WHERE user_id = :uid AND balance >= :amt');
        $stmt->execute(['amt' => $amount, 'uid' => $userId]);
        if ($stmt->rowCount() < 1) {
            return false;
        }

        $tx = $pdo->prepare(
            'INSERT INTO wallet_transactions (id, user_id, order_id, type, source, amount, status, reference_id, note)
             VALUES (:id, :uid, :oid, :type, :source, :amt, :status, :ref, :note)'
        );
        $tx->execute([
            'id' => $transactionId,
            'uid' => $userId,
            'oid' => $orderId,
            'type' => 'debit',
            'source' => $source,
            'amt' => $amount,
            'status' => 'success',
            'ref' => $referenceId,
            'note' => $note,
        ]);
        return true;
    }

    public static function countTransactionsByUserId(string $userId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS c FROM wallet_transactions WHERE user_id = :uid'
        );
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 0;
        }

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function findTransactionsByUserId(string $userId, int $limit, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, order_id, type, source, amount, status, reference_id, note, created_at
             FROM wallet_transactions
             WHERE user_id = :uid
             ORDER BY created_at DESC, id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue('uid', $userId);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue('off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'id' => (string) $row['id'],
                'user_id' => (string) $row['user_id'],
                'order_id' => $row['order_id'] !== null && $row['order_id'] !== '' ? (string) $row['order_id'] : null,
                'type' => (string) $row['type'],
                'source' => (string) $row['source'],
                'amount' => (float) $row['amount'],
                'status' => (string) $row['status'],
                'reference_id' => $row['reference_id'] !== null && $row['reference_id'] !== '' ? (string) $row['reference_id'] : null,
                'note' => $row['note'] !== null && $row['note'] !== '' ? (string) $row['note'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function findRecentTransactionsByUserId(string $userId, int $limit = 20): array
    {
        return self::findTransactionsByUserId($userId, $limit, 0);
    }
}
