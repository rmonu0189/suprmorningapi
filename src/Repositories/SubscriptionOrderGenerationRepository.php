<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class SubscriptionOrderGenerationRepository
{
    /**
     * Upsert generation rows for all users with subscriptions for a given delivery date.
     * Rows that are already success are left unchanged; others are set to pending with the new run_id.
     *
     * @return array{run_id: string, delivery_date: string, users_total: int, users_pending: int, users_already_success: int}
     */
    public static function startRun(string $deliveryDateYmd, string $runId, int $pageSize = 500): array
    {
        $pageSize = max(50, min(2000, $pageSize));
        $offset = 0;

        $usersTotal = 0;
        $usersPending = 0;
        $usersAlreadySuccess = 0;

        while (true) {
            $userIds = SubscriptionRepository::findDistinctUserIdsPaged($offset, $pageSize);
            if ($userIds === []) break;
            $offset += count($userIds);

            $usersTotal += count($userIds);

            foreach ($userIds as $uid) {
                // If already succeeded, keep as-is.
                $existing = self::findRow($deliveryDateYmd, $uid);
                if ($existing !== null && (($existing['status'] ?? '') === 'success')) {
                    $usersAlreadySuccess++;
                    continue;
                }

                $ok = self::upsertPending($deliveryDateYmd, $uid, $runId);
                if ($ok) $usersPending++;
            }
        }

        return [
            'run_id' => $runId,
            'delivery_date' => $deliveryDateYmd,
            'users_total' => $usersTotal,
            'users_pending' => $usersPending,
            'users_already_success' => $usersAlreadySuccess,
        ];
    }

    /**
     * @return array{delivery_date: string, user_id: string, run_id: string, status: string, order_id: string|null, error: string|null, updated_at: string}|null
     */
    public static function findRow(string $deliveryDateYmd, string $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT delivery_date, user_id, run_id, status, order_id, error, updated_at
             FROM subscription_order_generation
             WHERE delivery_date = :dd AND user_id = :uid
             LIMIT 1'
        );
        $stmt->execute(['dd' => $deliveryDateYmd, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return [
            'delivery_date' => (string) ($row['delivery_date'] ?? ''),
            'user_id' => (string) ($row['user_id'] ?? ''),
            'run_id' => (string) ($row['run_id'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'order_id' => isset($row['order_id']) && $row['order_id'] !== '' ? (string) $row['order_id'] : null,
            'error' => isset($row['error']) && $row['error'] !== '' ? (string) $row['error'] : null,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /** @return list<string> user ids */
    public static function findNextPendingUserIds(string $deliveryDateYmd, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = Database::connection()->prepare(
            'SELECT user_id
             FROM subscription_order_generation
             WHERE delivery_date = :dd AND status = :st
             ORDER BY updated_at ASC, user_id ASC
             LIMIT :lim'
        );
        $stmt->bindValue('dd', $deliveryDateYmd, PDO::PARAM_STR);
        $stmt->bindValue('st', 'pending', PDO::PARAM_STR);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            if (!isset($r['user_id'])) continue;
            $out[] = (string) $r['user_id'];
        }
        return $out;
    }

    public static function markSuccess(string $deliveryDateYmd, string $userId, ?string $orderId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE subscription_order_generation
             SET status = :st, order_id = :oid, error = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE delivery_date = :dd AND user_id = :uid'
        );
        $stmt->execute([
            'st' => 'success',
            'oid' => $orderId,
            'dd' => $deliveryDateYmd,
            'uid' => $userId,
        ]);
    }

    /**
     * @param string|null $errorDetail Optional JSON or text (e.g. wallet shortfall); stored when status is skipped_insufficient_wallet.
     */
    public static function markSkipped(string $deliveryDateYmd, string $userId, string $status, ?string $errorDetail = null): void
    {
        $allowed = ['skipped_no_items', 'skipped_no_address', 'skipped_insufficient_wallet'];
        if (!in_array($status, $allowed, true)) {
            $status = 'skipped_no_items';
        }

        $err = null;
        if ($status === 'skipped_insufficient_wallet' && $errorDetail !== null) {
            $msg = trim($errorDetail);
            if ($msg !== '') {
                if (strlen($msg) > 2000) {
                    $msg = substr($msg, 0, 2000);
                }
                $err = $msg;
            }
        }

        $stmt = Database::connection()->prepare(
            'UPDATE subscription_order_generation
             SET status = :st, order_id = NULL, error = :err, updated_at = CURRENT_TIMESTAMP
             WHERE delivery_date = :dd AND user_id = :uid'
        );
        $stmt->execute([
            'st' => $status,
            'err' => $err,
            'dd' => $deliveryDateYmd,
            'uid' => $userId,
        ]);
    }

    public static function markFailed(string $deliveryDateYmd, string $userId, string $error): void
    {
        $msg = trim($error);
        if ($msg === '') $msg = 'Unknown error';
        if (strlen($msg) > 2000) {
            $msg = substr($msg, 0, 2000);
        }
        $stmt = Database::connection()->prepare(
            'UPDATE subscription_order_generation
             SET status = :st, order_id = NULL, error = :err, updated_at = CURRENT_TIMESTAMP
             WHERE delivery_date = :dd AND user_id = :uid'
        );
        $stmt->execute([
            'st' => 'failed',
            'err' => $msg,
            'dd' => $deliveryDateYmd,
            'uid' => $userId,
        ]);
    }

    /**
     * @return array{delivery_date: string, total: int, pending: int, success: int, failed: int, skipped_no_items: int, skipped_no_address: int, skipped_insufficient_wallet: int}
     */
    public static function statusSummary(string $deliveryDateYmd): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT status, COUNT(*) AS c
             FROM subscription_order_generation
             WHERE delivery_date = :dd
             GROUP BY status'
        );
        $stmt->execute(['dd' => $deliveryDateYmd]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $counts = [
            'pending' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped_no_items' => 0,
            'skipped_no_address' => 0,
            'skipped_insufficient_wallet' => 0,
        ];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $st = (string) ($r['status'] ?? '');
                $c = (int) ($r['c'] ?? 0);
                if (isset($counts[$st])) {
                    $counts[$st] = $c;
                }
            }
        }
        $total = array_sum($counts);
        return [
            'delivery_date' => $deliveryDateYmd,
            'total' => $total,
            'pending' => (int) $counts['pending'],
            'success' => (int) $counts['success'],
            'failed' => (int) $counts['failed'],
            'skipped_no_items' => (int) $counts['skipped_no_items'],
            'skipped_no_address' => (int) $counts['skipped_no_address'],
            'skipped_insufficient_wallet' => (int) $counts['skipped_insufficient_wallet'],
        ];
    }

    /**
     * @return list<array{user_id: string, status: string, order_id: string|null, order_kind: string|null, error: string|null, updated_at: string}>
     */
    public static function listRecent(string $deliveryDateYmd, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $pdo = Database::connection();
        $rows = null;
        try {
            // Newer schema: orders.order_kind exists.
            $stmt = $pdo->prepare(
                'SELECT g.user_id, g.status, g.order_id, o.order_kind AS order_kind, g.error, g.updated_at
                 FROM subscription_order_generation g
                 LEFT JOIN orders o ON o.id = g.order_id
                 WHERE g.delivery_date = :dd
                 ORDER BY g.updated_at DESC, g.user_id DESC
                 LIMIT :lim'
            );
            $stmt->bindValue('dd', $deliveryDateYmd, PDO::PARAM_STR);
            $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Backward-compatible fallback: orders.order_kind not present yet.
            $stmt = $pdo->prepare(
                'SELECT g.user_id, g.status, g.order_id, NULL AS order_kind, g.error, g.updated_at
                 FROM subscription_order_generation g
                 WHERE g.delivery_date = :dd
                 ORDER BY g.updated_at DESC, g.user_id DESC
                 LIMIT :lim'
            );
            $stmt->bindValue('dd', $deliveryDateYmd, PDO::PARAM_STR);
            $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $out[] = [
                'user_id' => (string) ($r['user_id'] ?? ''),
                'status' => (string) ($r['status'] ?? ''),
                'order_id' => isset($r['order_id']) && $r['order_id'] !== '' ? (string) $r['order_id'] : null,
                'order_kind' => isset($r['order_kind']) && $r['order_kind'] !== '' ? (string) $r['order_kind'] : null,
                'error' => isset($r['error']) && $r['error'] !== '' ? (string) $r['error'] : null,
                'updated_at' => (string) ($r['updated_at'] ?? ''),
            ];
        }
        return $out;
    }

    public static function countPendingByUserId(string $userId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM subscription_order_generation WHERE user_id = :uid AND status = :st'
        );
        $stmt->execute(['uid' => $userId, 'st' => 'pending']);
        return (int) $stmt->fetchColumn();
    }

    private static function upsertPending(string $deliveryDateYmd, string $userId, string $runId): bool
    {
        $pdo = Database::connection();
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare(
                'INSERT INTO subscription_order_generation (delivery_date, user_id, run_id, status, order_id, error, created_at, updated_at)
                 VALUES (:dd, :uid, :rid, :st, NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                 ON CONFLICT(delivery_date, user_id) DO UPDATE SET
                    run_id = excluded.run_id,
                    status = CASE WHEN subscription_order_generation.status = \'success\' THEN \'success\' ELSE excluded.status END,
                    order_id = CASE WHEN subscription_order_generation.status = \'success\' THEN subscription_order_generation.order_id ELSE NULL END,
                    error = CASE WHEN subscription_order_generation.status = \'success\' THEN NULL ELSE NULL END,
                    updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute(['dd' => $deliveryDateYmd, 'uid' => $userId, 'rid' => $runId, 'st' => 'pending']);
            return true;
        }

        // MySQL/MariaDB.
        $stmt = $pdo->prepare(
            'INSERT INTO subscription_order_generation (delivery_date, user_id, run_id, status, order_id, error)
             VALUES (:dd, :uid, :rid, :st, NULL, NULL)
             ON DUPLICATE KEY UPDATE
                run_id = VALUES(run_id),
                status = IF(status = \'success\', \'success\', VALUES(status)),
                order_id = IF(status = \'success\', order_id, NULL),
                error = IF(status = \'success\', NULL, NULL)'
        );
        $stmt->execute(['dd' => $deliveryDateYmd, 'uid' => $userId, 'rid' => $runId, 'st' => 'pending']);
        return true;
    }
}

