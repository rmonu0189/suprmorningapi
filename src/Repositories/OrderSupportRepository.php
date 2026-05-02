<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class OrderSupportRepository
{
    public const STATUS_OPEN = 'open';
    public const STATUS_WAITING_ADMIN = 'waiting_admin';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_WAITING_CUSTOMER = 'waiting_customer';
    public const STATUS_RESOLVED = 'resolved';

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_WAITING_ADMIN,
            self::STATUS_ANSWERED,
            self::STATUS_WAITING_CUSTOMER,
            self::STATUS_RESOLVED,
        ];
    }

    public static function findOrderOwner(string $orderId): ?string
    {
        $stmt = Database::connection()->prepare('SELECT user_id FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $v = $stmt->fetchColumn();
        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function findOrderOwnerForUser(string $orderId, string $userId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM orders WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmt->execute(['id' => $orderId, 'uid' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    public static function findQueryByOrderId(string $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT q.*, o.order_code, o.grand_total, o.currency, o.created_at AS order_created_at,
                    u.phone AS customer_phone, u.email AS customer_email, u.full_name AS customer_full_name
             FROM order_support_queries q
             INNER JOIN orders o ON o.id = q.order_id
             LEFT JOIN users u ON u.id = q.user_id
             WHERE q.order_id = :oid
             LIMIT 1'
        );
        $stmt->execute(['oid' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::formatQuery($row, true) : null;
    }

    public static function findQueryByOrderIdForUser(string $orderId, string $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT q.*, o.order_code, o.grand_total, o.currency, o.created_at AS order_created_at
             FROM order_support_queries q
             INNER JOIN orders o ON o.id = q.order_id
             WHERE q.order_id = :oid AND q.user_id = :uid
             LIMIT 1'
        );
        $stmt->execute(['oid' => $orderId, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::formatQuery($row, false) : null;
    }

    /** @return list<array<string,mixed>> */
    public static function findAllForUser(string $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT q.*, o.order_code, o.grand_total, o.currency, o.created_at AS order_created_at,
                    (SELECT message FROM order_support_messages lm WHERE lm.query_id = q.id ORDER BY lm.created_at DESC, lm.id DESC LIMIT 1) AS last_message,
                    (SELECT created_at FROM order_support_messages lm WHERE lm.query_id = q.id ORDER BY lm.created_at DESC, lm.id DESC LIMIT 1) AS last_message_at
             FROM order_support_queries q
             INNER JOIN orders o ON o.id = q.order_id
             WHERE q.user_id = :uid
             ORDER BY q.updated_at DESC, q.created_at DESC"
        );
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_map(static fn (array $row): array => self::formatQuery($row, false), $rows));
    }

    public static function createQuery(string $id, string $orderId, string $userId, ?string $subject, string $status): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO order_support_queries (id, order_id, user_id, subject, status)
             VALUES (:id, :oid, :uid, :subject, :status)'
        );
        $stmt->execute([
            'id' => $id,
            'oid' => $orderId,
            'uid' => $userId,
            'subject' => $subject,
            'status' => $status,
        ]);
    }

    public static function addMessage(string $id, string $queryId, string $senderUserId, string $senderRole, string $message): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO order_support_messages (id, query_id, sender_user_id, sender_role, message)
             VALUES (:id, :qid, :sid, :role, :msg)'
        );
        $stmt->execute([
            'id' => $id,
            'qid' => $queryId,
            'sid' => $senderUserId,
            'role' => $senderRole,
            'msg' => $message,
        ]);
    }

    public static function updateStatus(string $queryId, string $status, ?string $actorUserId): void
    {
        $resolved = $status === self::STATUS_RESOLVED;
        $stmt = Database::connection()->prepare(
            'UPDATE order_support_queries
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP,
                 resolved_at = CASE WHEN :resolved_at_flag = 1 THEN CURRENT_TIMESTAMP ELSE NULL END,
                 resolved_by = CASE WHEN :resolved_by_flag = 1 THEN :actor ELSE NULL END
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'resolved_at_flag' => $resolved ? 1 : 0,
            'resolved_by_flag' => $resolved ? 1 : 0,
            'actor' => $actorUserId,
            'id' => $queryId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function findMessages(string $queryId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT m.*, u.phone, u.email, u.full_name
             FROM order_support_messages m
             LEFT JOIN users u ON u.id = m.sender_user_id
             WHERE m.query_id = :qid
             ORDER BY m.created_at ASC, m.id ASC'
        );
        $stmt->execute(['qid' => $queryId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_map(static fn (array $row): array => [
            'id' => (string) ($row['id'] ?? ''),
            'query_id' => (string) ($row['query_id'] ?? ''),
            'sender_user_id' => (string) ($row['sender_user_id'] ?? ''),
            'sender_role' => (string) ($row['sender_role'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'sender' => [
                'phone' => isset($row['phone']) && $row['phone'] !== '' ? (string) $row['phone'] : null,
                'email' => isset($row['email']) && $row['email'] !== '' ? (string) $row['email'] : null,
                'full_name' => isset($row['full_name']) && $row['full_name'] !== '' ? (string) $row['full_name'] : null,
            ],
        ], $rows));
    }

    /** @return list<array<string,mixed>> */
    public static function findAllForAdmin(int $offset, int $limit, ?string $status): array
    {
        $where = [];
        $params = [];
        if ($status !== null && $status !== '') {
            $where[] = 'q.status = :status';
            $params['status'] = $status;
        }
        $w = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $stmt = Database::connection()->prepare(
            "SELECT q.*, o.order_code, o.grand_total, o.currency, o.created_at AS order_created_at,
                    u.phone AS customer_phone, u.email AS customer_email, u.full_name AS customer_full_name,
                    (SELECT message FROM order_support_messages lm WHERE lm.query_id = q.id ORDER BY lm.created_at DESC, lm.id DESC LIMIT 1) AS last_message,
                    (SELECT created_at FROM order_support_messages lm WHERE lm.query_id = q.id ORDER BY lm.created_at DESC, lm.id DESC LIMIT 1) AS last_message_at
             FROM order_support_queries q
             INNER JOIN orders o ON o.id = q.order_id
             LEFT JOIN users u ON u.id = q.user_id
             {$w}
             ORDER BY q.updated_at DESC, q.created_at DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue('off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_map(static fn (array $row): array => self::formatQuery($row, true), $rows));
    }

    public static function countAllForAdmin(?string $status): int
    {
        $where = [];
        $params = [];
        if ($status !== null && $status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        $w = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $stmt = Database::connection()->prepare("SELECT COUNT(*) AS c FROM order_support_queries {$w}");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }

    private static function formatQuery(array $row, bool $includeCustomer): array
    {
        $out = [
            'id' => (string) ($row['id'] ?? ''),
            'order_id' => (string) ($row['order_id'] ?? ''),
            'order_code' => isset($row['order_code']) && $row['order_code'] !== '' ? (string) $row['order_code'] : null,
            'user_id' => (string) ($row['user_id'] ?? ''),
            'status' => (string) ($row['status'] ?? self::STATUS_OPEN),
            'subject' => isset($row['subject']) && $row['subject'] !== '' ? (string) $row['subject'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'resolved_at' => isset($row['resolved_at']) && $row['resolved_at'] !== '' ? (string) $row['resolved_at'] : null,
            'resolved_by' => isset($row['resolved_by']) && $row['resolved_by'] !== '' ? (string) $row['resolved_by'] : null,
            'order' => [
                'id' => (string) ($row['order_id'] ?? ''),
                'order_code' => isset($row['order_code']) && $row['order_code'] !== '' ? (string) $row['order_code'] : null,
                'grand_total' => isset($row['grand_total']) ? (float) $row['grand_total'] : 0.0,
                'currency' => isset($row['currency']) && $row['currency'] !== '' ? (string) $row['currency'] : 'INR',
                'created_at' => isset($row['order_created_at']) ? (string) $row['order_created_at'] : null,
            ],
        ];
        if (array_key_exists('last_message', $row)) {
            $out['last_message'] = isset($row['last_message']) && $row['last_message'] !== '' ? (string) $row['last_message'] : null;
            $out['last_message_at'] = isset($row['last_message_at']) && $row['last_message_at'] !== '' ? (string) $row['last_message_at'] : null;
        }
        if ($includeCustomer) {
            $out['customer'] = [
                'phone' => isset($row['customer_phone']) && $row['customer_phone'] !== '' ? (string) $row['customer_phone'] : null,
                'email' => isset($row['customer_email']) && $row['customer_email'] !== '' ? (string) $row['customer_email'] : null,
                'full_name' => isset($row['customer_full_name']) && $row['customer_full_name'] !== '' ? (string) $row['customer_full_name'] : null,
            ];
        }
        return $out;
    }
}
