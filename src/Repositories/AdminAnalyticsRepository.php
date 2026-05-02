<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class AdminAnalyticsRepository
{
    /**
     * @return array{
     *   products_created: int,
     *   variants_created: int,
     *   orders_created: int,
     *   orders_success: int,
     *   revenue_success: float,
     *   coupon_discount_success: float,
     *   referral_credits: float,
     *   users_created: int
     * }
     */
    public static function overview(string $fromInclusive, string $toExclusive, ?int $warehouseId = null): array
    {
        $pdo = Database::connection();

        $countInRange = static function (string $table, ?string $extraWhere = null) use ($pdo, $fromInclusive, $toExclusive, $warehouseId): int {
            $where = "created_at >= :from AND created_at < :to";
            if ($extraWhere !== null && trim($extraWhere) !== '') {
                $where .= " AND ($extraWhere)";
            }
            if ($warehouseId !== null && $table === 'orders') {
                $where .= " AND warehouse_id = :wid";
            }
            if ($warehouseId !== null && $table === 'users') {
                $where .= " AND warehouse_id = :wid";
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE {$where}");
            $params = ['from' => $fromInclusive, 'to' => $toExclusive];
            if ($warehouseId !== null && ($table === 'orders' || $table === 'users')) {
                $params['wid'] = $warehouseId;
            }
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
        };

        $sumOrders = static function (string $whereExtra) use ($pdo, $fromInclusive, $toExclusive, $warehouseId): float {
            $where = "created_at >= :from AND created_at < :to AND ($whereExtra)";
            $params = ['from' => $fromInclusive, 'to' => $toExclusive];
            if ($warehouseId !== null) {
                $where .= " AND warehouse_id = :wid";
                $params['wid'] = $warehouseId;
            }
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) AS s FROM orders WHERE {$where}");
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? (float) ($row['s'] ?? 0) : 0.0;
        };

        $sumCouponDiscount = static function () use ($pdo, $fromInclusive, $toExclusive, $warehouseId): float {
            $where = "created_at >= :from AND created_at < :to AND coupon_code IS NOT NULL AND coupon_code <> ''";
            $params = ['from' => $fromInclusive, 'to' => $toExclusive];
            if ($warehouseId !== null) {
                $where .= " AND warehouse_id = :wid";
                $params['wid'] = $warehouseId;
            }
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(
                    CASE
                      WHEN coupon_discount > 0 THEN coupon_discount
                      WHEN (total_price + total_charges - grand_total) > 0 THEN (total_price + total_charges - grand_total)
                      ELSE 0
                    END
                ), 0) AS s FROM orders WHERE {$where}");
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? (float) ($row['s'] ?? 0) : 0.0;
        };

        $sumReferralCredits = static function () use ($pdo, $fromInclusive, $toExclusive, $warehouseId): float {
            $sql = "SELECT COALESCE(SUM(wt.amount), 0) AS s
                    FROM wallet_transactions wt";
            $params = ['from' => $fromInclusive, 'to' => $toExclusive];
            if ($warehouseId !== null) {
                $sql .= ' INNER JOIN users u ON u.id = wt.user_id';
            }
            $sql .= " WHERE wt.created_at >= :from
                        AND wt.created_at < :to
                        AND wt.type = 'credit'
                        AND wt.source = 'referral'
                        AND wt.status = 'success'";
            if ($warehouseId !== null) {
                $sql .= ' AND u.warehouse_id = :wid';
                $params['wid'] = $warehouseId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? (float) ($row['s'] ?? 0) : 0.0;
        };

        return [
            'products_created' => $countInRange('products'),
            'variants_created' => $countInRange('variants'),
            'orders_created' => $countInRange('orders'),
            'orders_success' => $countInRange('orders', 'payment_status = \'success\''),
            'revenue_success' => $sumOrders("payment_status = 'success'"),
            'coupon_discount_success' => $sumCouponDiscount(),
            'referral_credits' => $sumReferralCredits(),
            'users_created' => $countInRange('users'),
        ];
    }

    /**
     * @return array{
     *   summary: list<array{coupon_code:string, order_count:int, total_discount:float, total_revenue:float}>,
     *   orders: list<array{id:string, order_code:?string, coupon_code:string, coupon_discount:float, grand_total:float, payment_status:string, order_status:string, created_at:string, recipient_name:string, recipient_phone:string}>
     * }
     */
    public static function couponUsage(string $fromInclusive, string $toExclusive, ?int $warehouseId = null): array
    {
        $pdo = Database::connection();
        $where = "created_at >= :from AND created_at < :to AND coupon_code IS NOT NULL AND coupon_code <> '' AND coupon_discount > 0";
        $params = ['from' => $fromInclusive, 'to' => $toExclusive];
        if ($warehouseId !== null) {
            $where .= " AND warehouse_id = :wid";
            $params['wid'] = $warehouseId;
        }

        $summarySql = "SELECT coupon_code,
                              COUNT(*) AS order_count,
                              COALESCE(SUM(coupon_discount), 0) AS total_discount,
                              COALESCE(SUM(grand_total), 0) AS total_revenue
                       FROM orders
                       WHERE {$where}
                       GROUP BY coupon_code
                       ORDER BY total_discount DESC, order_count DESC, coupon_code ASC";
        $summaryStmt = $pdo->prepare($summarySql);
        $summaryStmt->execute($params);
        $summaryRows = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);
        $summary = [];
        if (is_array($summaryRows)) {
            foreach ($summaryRows as $row) {
                if (!is_array($row)) continue;
                $summary[] = [
                    'coupon_code' => (string) ($row['coupon_code'] ?? ''),
                    'order_count' => (int) ($row['order_count'] ?? 0),
                    'total_discount' => (float) ($row['total_discount'] ?? 0),
                    'total_revenue' => (float) ($row['total_revenue'] ?? 0),
                ];
            }
        }

        $ordersSql = "SELECT id, order_code, coupon_code, coupon_discount, grand_total, payment_status, order_status, created_at, recipient_name, recipient_phone
                      FROM orders
                      WHERE {$where}
                      ORDER BY created_at DESC, id DESC";
        $ordersStmt = $pdo->prepare($ordersSql);
        $ordersStmt->execute($params);
        $orderRows = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
        $orders = [];
        if (is_array($orderRows)) {
            foreach ($orderRows as $row) {
                if (!is_array($row)) continue;
                $orders[] = [
                    'id' => (string) ($row['id'] ?? ''),
                    'order_code' => isset($row['order_code']) && $row['order_code'] !== '' ? (string) $row['order_code'] : null,
                    'coupon_code' => (string) ($row['coupon_code'] ?? ''),
                    'coupon_discount' => (float) ($row['coupon_discount'] ?? 0),
                    'grand_total' => (float) ($row['grand_total'] ?? 0),
                    'payment_status' => (string) ($row['payment_status'] ?? ''),
                    'order_status' => (string) ($row['order_status'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'recipient_name' => (string) ($row['recipient_name'] ?? ''),
                    'recipient_phone' => (string) ($row['recipient_phone'] ?? ''),
                ];
            }
        }

        return ['summary' => $summary, 'orders' => $orders];
    }
}
