<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class AdminDashboardRepository
{
    public static function totalUsers(?int $warehouseId = null): int
    {
        // For warehouse-scoped dashboards (staff/manager/delivery), count staff-like users only.
        if ($warehouseId !== null) {
            $stmt = Database::connection()->prepare('SELECT COUNT(*) AS c FROM users WHERE warehouse_id = :wid');
            $stmt->execute(['wid' => $warehouseId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = Database::connection()->query('SELECT COUNT(*) AS c FROM users');
            $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        }
        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }

    public static function totalRevenueSuccess(?int $warehouseId = null): float
    {
        $sql = "SELECT COALESCE(SUM(grand_total), 0) AS s FROM orders WHERE payment_status = 'success'";
        $params = [];
        if ($warehouseId !== null) {
            $sql .= ' AND warehouse_id = :wid';
            $params['wid'] = $warehouseId;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (float) ($row['s'] ?? 0) : 0.0;
    }

    public static function totalOrders(?int $warehouseId = null): int
    {
        if ($warehouseId !== null) {
            $stmt = Database::connection()->prepare('SELECT COUNT(*) AS c FROM orders WHERE warehouse_id = :wid');
            $stmt->execute(['wid' => $warehouseId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = Database::connection()->query('SELECT COUNT(*) AS c FROM orders');
            $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        }
        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recentSuccessOrders(int $limit = 5, ?int $warehouseId = null): array
    {
        $limit = max(1, min(25, $limit));
        $sql = "SELECT
                  o.id,
                  o.order_status,
                  o.payment_status,
                  o.delivery_date,
                  o.grand_total,
                  o.currency,
                  o.created_at,
                  o.user_id,
                  o.recipient_name,
                  o.recipient_phone,
                  o.gateway_order_id,
                  u.phone AS customer_phone,
                  u.email AS customer_email,
                  u.full_name AS customer_full_name
                FROM orders o
                LEFT JOIN users u ON u.id = o.user_id
                WHERE o.payment_status = 'success'";
        if ($warehouseId !== null) {
            $sql .= " AND o.warehouse_id = :wid";
        }
        $sql .= "
                ORDER BY o.created_at DESC, o.id DESC
                LIMIT :lim";
        $stmt = Database::connection()->prepare($sql);
        if ($warehouseId !== null) {
            $stmt->bindValue('wid', $warehouseId, PDO::PARAM_INT);
        }
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
            $dd = $row['delivery_date'] ?? null;
            $deliveryDateStr = $dd !== null && $dd !== '' ? (string) $dd : '';
            $go = $row['gateway_order_id'] ?? null;

            $out[] = [
                'id' => (string) ($row['id'] ?? ''),
                'user_id' => (string) ($row['user_id'] ?? ''),
                'order_status' => (string) ($row['order_status'] ?? ''),
                'payment_status' => (string) ($row['payment_status'] ?? ''),
                'delivery_date' => $deliveryDateStr,
                'grand_total' => (float) ($row['grand_total'] ?? 0),
                'currency' => (string) ($row['currency'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'recipient_name' => (string) ($row['recipient_name'] ?? ''),
                'recipient_phone' => (string) ($row['recipient_phone'] ?? ''),
                'gateway_order_id' => $go !== null && $go !== '' ? (string) $go : null,
                'order_items' => [],
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

