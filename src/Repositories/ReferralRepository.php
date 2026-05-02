<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Uuid;
use PDO;
use PDOException;

final class ReferralRepository
{
    public const REWARD_AMOUNT = 50.00;
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';

    private static ?bool $usersHasReferralCodeColumn = null;
    private static ?bool $referralsTableExists = null;

    public static function usersTableHasReferralCodeColumn(): bool
    {
        if (self::$usersHasReferralCodeColumn !== null) {
            return self::$usersHasReferralCodeColumn;
        }

        $pdo = Database::connection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'sqlite') {
                $r = $pdo->query('PRAGMA table_info(users)');
                if ($r === false) {
                    self::$usersHasReferralCodeColumn = false;
                    return false;
                }
                foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (is_array($row) && ($row['name'] ?? '') === 'referral_code') {
                        self::$usersHasReferralCodeColumn = true;
                        return true;
                    }
                }
                self::$usersHasReferralCodeColumn = false;
                return false;
            }

            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'referral_code'");
            self::$usersHasReferralCodeColumn = $stmt !== false && $stmt->rowCount() > 0;
            return self::$usersHasReferralCodeColumn;
        } catch (\Throwable) {
            self::$usersHasReferralCodeColumn = false;
            return false;
        }
    }

    public static function referralsTableExists(): bool
    {
        if (self::$referralsTableExists !== null) {
            return self::$referralsTableExists;
        }

        $pdo = Database::connection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'sqlite') {
                $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='referrals' LIMIT 1");
                self::$referralsTableExists = $stmt !== false && (bool) $stmt->fetchColumn();
                return self::$referralsTableExists;
            }

            $stmt = $pdo->query("SHOW TABLES LIKE 'referrals'");
            self::$referralsTableExists = $stmt !== false && $stmt->rowCount() > 0;
            return self::$referralsTableExists;
        } catch (\Throwable) {
            self::$referralsTableExists = false;
            return false;
        }
    }

    public static function normalizeCode(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $value) ?? '');
        if ($code === '' || strlen($code) < 6 || strlen($code) > 16) {
            return null;
        }

        return $code;
    }

    public static function ensureReferralCode(string $userId): string
    {
        if (!self::usersTableHasReferralCodeColumn()) {
            return 'SM' . strtoupper(substr(str_replace('-', '', $userId), 0, 10));
        }

        $existing = self::findCodeByUserId($userId);
        if ($existing !== null) {
            return $existing;
        }

        for ($i = 0; $i < 12; $i++) {
            $code = self::generateCode();
            try {
                $stmt = Database::connection()->prepare(
                    'UPDATE users
                     SET referral_code = :code
                     WHERE id = :id AND (referral_code IS NULL OR referral_code = \'\')'
                );
                $stmt->execute(['code' => $code, 'id' => $userId]);
                $stored = self::findCodeByUserId($userId);
                if ($stored !== null) {
                    return $stored;
                }
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        $fallback = 'SM' . strtoupper(substr(str_replace('-', '', $userId), 0, 10));
        $stmt = Database::connection()->prepare(
            'UPDATE users
             SET referral_code = :code
             WHERE id = :id AND (referral_code IS NULL OR referral_code = \'\')'
        );
        $stmt->execute(['code' => substr($fallback, 0, 16), 'id' => $userId]);

        return self::findCodeByUserId($userId) ?? substr($fallback, 0, 16);
    }

    public static function findCodeByUserId(string $userId): ?string
    {
        if (!self::usersTableHasReferralCodeColumn()) {
            return null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT referral_code FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $code = $stmt->fetchColumn();
        if (!is_string($code) || trim($code) === '') {
            return null;
        }

        return strtoupper(trim($code));
    }

    /** @return array{id:string,phone:string,full_name:?string,referral_code:string}|null */
    public static function findReferrerByCode(string $code): ?array
    {
        if (!self::usersTableHasReferralCodeColumn()) {
            return null;
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, phone, full_name, referral_code
             FROM users
             WHERE referral_code = :code AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'phone' => (string) $row['phone'],
            'full_name' => $row['full_name'] !== null && $row['full_name'] !== '' ? (string) $row['full_name'] : null,
            'referral_code' => (string) $row['referral_code'],
        ];
    }

    public static function createPending(string $referrerUserId, string $referredUserId, string $code): void
    {
        if (!self::referralsTableExists()) {
            return;
        }

        if ($referrerUserId === '' || $referredUserId === '' || $referrerUserId === $referredUserId) {
            return;
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO referrals (id, referrer_user_id, referred_user_id, referral_code, status, reward_amount)
             VALUES (:id, :referrer, :referred, :code, :status, :reward)'
        );
        try {
            $stmt->execute([
                'id' => Uuid::v4(),
                'referrer' => $referrerUserId,
                'referred' => $referredUserId,
                'code' => $code,
                'status' => self::STATUS_PENDING,
                'reward' => self::REWARD_AMOUNT,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    public static function completePendingForFirstPaidOrder(string $referredUserId, string $orderId): void
    {
        if (!self::referralsTableExists()) {
            return;
        }

        if ($referredUserId === '' || $orderId === '') {
            return;
        }

        $successfulOrders = self::countSuccessfulPaidOrders($referredUserId);
        if ($successfulOrders !== 1) {
            return;
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, referrer_user_id, referred_user_id, reward_amount
             FROM referrals
             WHERE referred_user_id = :uid AND status = :status
             ORDER BY created_at ASC
             LIMIT 1'
        );
        $stmt->execute(['uid' => $referredUserId, 'status' => self::STATUS_PENDING]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return;
        }

        $referralId = (string) ($row['id'] ?? '');
        $referrerId = (string) ($row['referrer_user_id'] ?? '');
        $amount = round((float) ($row['reward_amount'] ?? self::REWARD_AMOUNT), 2);
        if ($referralId === '' || $referrerId === '' || $amount <= 0.0) {
            return;
        }

        $upd = Database::connection()->prepare(
            'UPDATE referrals
             SET status = :completed, qualifying_order_id = :order_id, completed_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = :pending'
        );
        $upd->execute([
            'completed' => self::STATUS_COMPLETED,
            'order_id' => $orderId,
            'id' => $referralId,
            'pending' => self::STATUS_PENDING,
        ]);
        if ($upd->rowCount() < 1) {
            return;
        }

        WalletRepository::credit(
            Uuid::v4(),
            $referrerId,
            $amount,
            'referral',
            $orderId,
            $referralId,
            'Referral reward'
        );
        WalletRepository::credit(
            Uuid::v4(),
            $referredUserId,
            $amount,
            'referral',
            $orderId,
            $referralId,
            'Referral signup reward'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function findByReferrer(string $userId, int $limit = 100): array
    {
        if (!self::referralsTableExists()) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $stmt = Database::connection()->prepare(
            'SELECT r.id, r.referred_user_id, r.referral_code, r.status, r.reward_amount,
                    r.qualifying_order_id, r.created_at, r.completed_at,
                    u.phone AS referred_phone, u.full_name AS referred_full_name
             FROM referrals r
             INNER JOIN users u ON u.id = r.referred_user_id
             WHERE r.referrer_user_id = :uid
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute(['uid' => $userId]);
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
                'referred_user_id' => (string) $row['referred_user_id'],
                'referral_code' => (string) $row['referral_code'],
                'status' => (string) $row['status'],
                'reward_amount' => (float) $row['reward_amount'],
                'qualifying_order_id' => $row['qualifying_order_id'] !== null && $row['qualifying_order_id'] !== '' ? (string) $row['qualifying_order_id'] : null,
                'created_at' => (string) $row['created_at'],
                'completed_at' => $row['completed_at'] !== null && $row['completed_at'] !== '' ? (string) $row['completed_at'] : null,
                'referred_user' => [
                    'phone' => (string) $row['referred_phone'],
                    'full_name' => $row['referred_full_name'] !== null && $row['referred_full_name'] !== '' ? (string) $row['referred_full_name'] : null,
                ],
            ];
        }

        return $out;
    }

    public static function countByReferrerAndStatus(string $userId, ?string $status = null): int
    {
        if (!self::referralsTableExists()) {
            return 0;
        }

        if ($status === null) {
            $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM referrals WHERE referrer_user_id = :uid');
            $stmt->execute(['uid' => $userId]);
        } else {
            $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM referrals WHERE referrer_user_id = :uid AND status = :status');
            $stmt->execute(['uid' => $userId, 'status' => $status]);
        }

        return (int) $stmt->fetchColumn();
    }

    private static function countSuccessfulPaidOrders(string $userId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM orders WHERE user_id = :uid AND payment_status = 'success'"
        );
        $stmt->execute(['uid' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    private static function generateCode(): string
    {
        return 'SM' . strtoupper(bin2hex(random_bytes(4)));
    }
}
