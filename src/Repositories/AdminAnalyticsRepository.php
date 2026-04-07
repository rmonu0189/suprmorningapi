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
     *   users_created: int
     * }
     */
    public static function overview(string $fromInclusive, string $toExclusive): array
    {
        $pdo = Database::connection();

        $countInRange = static function (string $table, ?string $extraWhere = null) use ($pdo, $fromInclusive, $toExclusive): int {
            $where = "created_at >= :from AND created_at < :to";
            if ($extraWhere !== null && trim($extraWhere) !== '') {
                $where .= " AND ($extraWhere)";
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE {$where}");
            $stmt->execute(['from' => $fromInclusive, 'to' => $toExclusive]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
        };

        $sumOrders = static function (string $whereExtra) use ($pdo, $fromInclusive, $toExclusive): float {
            $where = "created_at >= :from AND created_at < :to AND ($whereExtra)";
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) AS s FROM orders WHERE {$where}");
            $stmt->execute(['from' => $fromInclusive, 'to' => $toExclusive]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? (float) ($row['s'] ?? 0) : 0.0;
        };

        return [
            'products_created' => $countInRange('products'),
            'variants_created' => $countInRange('variants'),
            'orders_created' => $countInRange('orders'),
            'orders_success' => $countInRange('orders', 'payment_status = \'success\''),
            'revenue_success' => $sumOrders("payment_status = 'success'"),
            'users_created' => $countInRange('users'),
        ];
    }
}

