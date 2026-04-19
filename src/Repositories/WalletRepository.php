<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use PDO;

final class WalletRepository
{
    /** @var bool|null null = not yet probed */
    private static ?bool $walletsHasLockedBalanceColumn = null;

    /**
     * True when migration 038 (locked_balance + wallet use cases that lock funds) is applied.
     */
    public static function walletsTableHasLockedBalanceColumn(): bool
    {
        if (self::$walletsHasLockedBalanceColumn !== null) {
            return self::$walletsHasLockedBalanceColumn;
        }

        $pdo = Database::connection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'sqlite') {
                $r = $pdo->query('PRAGMA table_info(wallets)');
                if ($r === false) {
                    self::$walletsHasLockedBalanceColumn = false;

                    return false;
                }
                foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (is_array($row) && ($row['name'] ?? '') === 'locked_balance') {
                        self::$walletsHasLockedBalanceColumn = true;

                        return true;
                    }
                }
                self::$walletsHasLockedBalanceColumn = false;

                return false;
            }

            $stmt = $pdo->query("SHOW COLUMNS FROM wallets LIKE 'locked_balance'");
            $has = $stmt !== false && $stmt->rowCount() > 0;
            self::$walletsHasLockedBalanceColumn = $has;

            return $has;
        } catch (\Throwable) {
            self::$walletsHasLockedBalanceColumn = false;

            return false;
        }
    }

    /**
     * Wallet + Razorpay split checkout needs locked_balance and wallet_holds table.
     */
    public static function supportsSplitCheckout(): bool
    {
        return self::walletsTableHasLockedBalanceColumn() && WalletHoldRepository::isAvailable();
    }

    public static function ensureWallet(string $userId): void
    {
        $driver = Database::connection()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (self::walletsTableHasLockedBalanceColumn()) {
            if ($driver === 'sqlite') {
                $sql = 'INSERT OR IGNORE INTO wallets (user_id, balance, locked_balance, currency) VALUES (:uid, 0.00, 0.00, :cur)';
            } else {
                $sql = 'INSERT IGNORE INTO wallets (user_id, balance, locked_balance, currency) VALUES (:uid, 0.00, 0.00, :cur)';
            }
        } else {
            if ($driver === 'sqlite') {
                $sql = 'INSERT OR IGNORE INTO wallets (user_id, balance, currency) VALUES (:uid, 0.00, :cur)';
            } else {
                $sql = 'INSERT IGNORE INTO wallets (user_id, balance, currency) VALUES (:uid, 0.00, :cur)';
            }
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['uid' => $userId, 'cur' => 'INR']);
    }

    public static function findByUserId(string $userId): array
    {
        self::ensureWallet($userId);
        if (self::walletsTableHasLockedBalanceColumn()) {
            $stmt = Database::connection()->prepare(
                'SELECT user_id, balance, locked_balance, currency, created_at, updated_at
                 FROM wallets
                 WHERE user_id = :uid
                 LIMIT 1'
            );
        } else {
            $stmt = Database::connection()->prepare(
                'SELECT user_id, balance, currency, created_at, updated_at
                 FROM wallets
                 WHERE user_id = :uid
                 LIMIT 1'
            );
        }
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                'user_id' => $userId,
                'balance' => 0.0,
                'locked_balance' => 0.0,
                'currency' => 'INR',
                'created_at' => null,
                'updated_at' => null,
            ];
        }
        return [
            'user_id' => (string) $row['user_id'],
            'balance' => (float) $row['balance'],
            'locked_balance' => isset($row['locked_balance']) ? (float) $row['locked_balance'] : 0.0,
            'currency' => (string) ($row['currency'] ?? 'INR'),
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * Inserts a wallet_transactions row. When linking to an order created in the same transaction,
     * MySQL/InnoDB may reject the FK on INSERT or UPDATE. For MySQL we briefly disable FK checks so a
     * single INSERT can set order_id; other drivers use a normal INSERT.
     */
    private static function insertWalletLedgerRow(
        string $transactionId,
        string $userId,
        ?string $orderId,
        string $type,
        string $source,
        float $amount,
        string $status,
        ?string $referenceId,
        ?string $note
    ): void {
        $pdo = Database::connection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $amt = round($amount, 2);
        $oidBind = ($orderId !== null && $orderId !== '') ? $orderId : null;

        $sql = 'INSERT INTO wallet_transactions (id, user_id, order_id, type, source, amount, status, reference_id, note)
             VALUES (:id, :uid, :oid, :type, :source, :amt, :status, :ref, :note)';
        $params = [
            'id' => $transactionId,
            'uid' => $userId,
            'oid' => $oidBind,
            'type' => $type,
            'source' => $source,
            'amt' => $amt,
            'status' => $status,
            'ref' => $referenceId,
            'note' => $note,
        ];

        if ($driver === 'mysql' && $oidBind !== null) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }

            return;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
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
        $amt = round($amount, 2);
        $stmt = $pdo->prepare('UPDATE wallets SET balance = balance + :amt WHERE user_id = :uid');
        $stmt->execute(['amt' => $amt, 'uid' => $userId]);

        self::insertWalletLedgerRow(
            $transactionId,
            $userId,
            $orderId,
            'credit',
            $source,
            $amt,
            'success',
            $referenceId,
            $note
        );
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
        $amt = round($amount, 2);
        if ($amt < 0.01) {
            return false;
        }
        // PDO (native prepares) does not allow the same named placeholder twice; use distinct names.
        $stmt = $pdo->prepare('UPDATE wallets SET balance = balance - :amt_debit WHERE user_id = :uid AND balance >= :amt_min');
        $stmt->execute(['amt_debit' => $amt, 'amt_min' => $amt, 'uid' => $userId]);
        if ($stmt->rowCount() < 1) {
            return false;
        }

        self::insertWalletLedgerRow(
            $transactionId,
            $userId,
            $orderId,
            'debit',
            $source,
            $amt,
            'success',
            $referenceId,
            $note
        );

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

    /**
     * Legacy mixed orders may lack a wallet row in `payments`; map the order debit ledger id to the same
     * `wallet_{uuid}` ref shape used elsewhere.
     */
    public static function findOrderDebitWalletRef(string $orderId): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM wallet_transactions
             WHERE order_id = :oid AND type = :t AND source = :s AND status = :st
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'oid' => $orderId,
            't' => 'debit',
            's' => 'order',
            'st' => 'success',
        ]);
        $id = $stmt->fetchColumn();
        if (!is_string($id) || trim($id) === '') {
            return null;
        }

        return 'wallet_' . $id;
    }

    /**
     * Move funds from spendable balance into locked_balance (e.g. pending gateway payment for the rest).
     */
    public static function lockSpendableToLocked(string $userId, float $amount): bool
    {
        $amount = round($amount, 2);
        if ($amount <= 0.0) {
            return true;
        }
        if (!self::walletsTableHasLockedBalanceColumn()) {
            throw new HttpException(
                'Wallet hold is not available on this server. Apply database migration 038 or pay the full amount with card only.',
                503
            );
        }
        self::ensureWallet($userId);
        $stmt = Database::connection()->prepare(
            'UPDATE wallets SET balance = balance - :a1, locked_balance = locked_balance + :a2
             WHERE user_id = :u AND balance >= :a3'
        );
        $stmt->execute(['a1' => $amount, 'a2' => $amount, 'a3' => $amount, 'u' => $userId]);

        return $stmt->rowCount() >= 1;
    }

    /**
     * Return locked funds to spendable balance (payment failed or order cancelled before capture).
     */
    public static function releaseLockedToSpendable(string $userId, float $amount): bool
    {
        $amount = round($amount, 2);
        if ($amount <= 0.0) {
            return true;
        }
        if (!self::walletsTableHasLockedBalanceColumn()) {
            return false;
        }
        self::ensureWallet($userId);
        $stmt = Database::connection()->prepare(
            'UPDATE wallets SET balance = balance + :a1, locked_balance = locked_balance - :a2
             WHERE user_id = :u AND locked_balance >= :a3'
        );
        $stmt->execute(['a1' => $amount, 'a2' => $amount, 'a3' => $amount, 'u' => $userId]);

        return $stmt->rowCount() >= 1;
    }

    /**
     * Permanently consume locked funds after a successful order (wallet portion of a paid order).
     */
    public static function finalizeLockedAsSpent(string $userId, float $amount): bool
    {
        $amount = round($amount, 2);
        if ($amount <= 0.0) {
            return true;
        }
        if (!self::walletsTableHasLockedBalanceColumn()) {
            return false;
        }
        self::ensureWallet($userId);
        $stmt = Database::connection()->prepare(
            'UPDATE wallets SET locked_balance = locked_balance - :a1
             WHERE user_id = :u AND locked_balance >= :a2'
        );
        $stmt->execute(['a1' => $amount, 'a2' => $amount, 'u' => $userId]);

        return $stmt->rowCount() >= 1;
    }

    /**
     * Ledger row only — balance must already have been updated (e.g. after lock release / capture).
     */
    public static function appendLedgerEntry(
        string $transactionId,
        string $userId,
        string $type,
        string $source,
        float $amount,
        string $status = 'success',
        ?string $orderId = null,
        ?string $referenceId = null,
        ?string $note = null
    ): void {
        self::insertWalletLedgerRow(
            $transactionId,
            $userId,
            $orderId,
            $type,
            $source,
            $amount,
            $status,
            $referenceId,
            $note
        );
    }
}
