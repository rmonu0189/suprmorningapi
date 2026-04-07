<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class AdminDashboardRepository
{
    public static function totalUsers(): int
    {
        $stmt = Database::connection()->query('SELECT COUNT(*) AS c FROM users');
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }

    public static function totalRevenueSuccess(): float
    {
        $stmt = Database::connection()->prepare(
            "SELECT COALESCE(SUM(grand_total), 0) AS s FROM orders WHERE payment_status = 'success'"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (float) ($row['s'] ?? 0) : 0.0;
    }

    public static function totalOrders(): int
    {
        $stmt = Database::connection()->query('SELECT COUNT(*) AS c FROM orders');
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recentSuccessOrders(int $limit = 5): array
    {
        $limit = max(1, min(25, $limit));
        $sql = "SELECT
                  o.id,
                  o.order_status,
                  o.payment_status,
                  o.grand_total,
                  o.currency,
                  o.created_at,
                  o.user_id,
                  u.phone AS customer_phone,
                  u.email AS customer_email,
                  u.full_name AS customer_full_name
                FROM orders o
                LEFT JOIN users u ON u.id = o.user_id
                WHERE o.payment_status = 'success'
                ORDER BY o.created_at DESC, o.id DESC
                LIMIT :lim";
        $stmt = Database::connection()->prepare($sql);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
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
                'id' => (string) ($row['id'] ?? ''),
                'order_status' => (string) ($row['order_status'] ?? ''),
                'payment_status' => (string) ($row['payment_status'] ?? ''),
                'grand_total' => (float) ($row['grand_total'] ?? 0),
                'currency' => (string) ($row['currency'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'user_id' => (string) ($row['user_id'] ?? ''),
                'customer' => [
                    'phone' => isset($row['customer_phone']) && $row['customer_phone'] !== ''
                        ? (string) $row['customer_phone'] : null,
                    'email' => isset($row['customer_email']) && $row['customer_email'] !== ''
                        ? (string) $row['customer_email'] : null,
                    'full_name' => isset($row['customer_full_name']) && $row['customer_full_name'] !== ''
                        ? (string) $row['customer_full_name'] : null,
                ],
            ];
        }

        return $out;
    }
}

