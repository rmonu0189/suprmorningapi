<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class OrderRatingRepository
{
    /**
     * @return list<array{id:string, order_item_id:string, rating:int, feedback:string|null, created_at:string, updated_at:string}>
     */
    public static function findItemRatingsByUserAndOrder(string $userId, string $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, order_item_id, rating, feedback, created_at, updated_at
             FROM order_item_ratings
             WHERE user_id = :uid AND order_id = :oid
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['uid' => $userId, 'oid' => $orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $out[] = [
                'id' => (string) ($row['id'] ?? ''),
                'order_item_id' => (string) ($row['order_item_id'] ?? ''),
                'rating' => (int) ($row['rating'] ?? 0),
                'feedback' => isset($row['feedback']) && $row['feedback'] !== '' ? (string) $row['feedback'] : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return array{id:string, rating:int, feedback:string|null, delivery_partner_user_id:string|null, created_at:string, updated_at:string}|null */
    public static function findDeliveryRatingByUserAndOrder(string $userId, string $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, rating, feedback, delivery_partner_user_id, created_at, updated_at
             FROM order_delivery_ratings
             WHERE user_id = :uid AND order_id = :oid
             LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'oid' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return [
            'id' => (string) ($row['id'] ?? ''),
            'rating' => (int) ($row['rating'] ?? 0),
            'feedback' => isset($row['feedback']) && $row['feedback'] !== '' ? (string) $row['feedback'] : null,
            'delivery_partner_user_id' => isset($row['delivery_partner_user_id']) && $row['delivery_partner_user_id'] !== ''
                ? (string) $row['delivery_partner_user_id'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @return list<array{id:string}>
     */
    public static function findOrderItemsLite(string $orderId): array
    {
        $stmt = Database::connection()->prepare('SELECT id FROM order_items WHERE order_id = :oid');
        $stmt->execute(['oid' => $orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $id = isset($row['id']) ? (string) $row['id'] : '';
            if ($id === '') continue;
            $out[] = ['id' => $id];
        }
        return $out;
    }

    public static function upsertItemRating(
        string $id,
        string $userId,
        string $orderId,
        string $orderItemId,
        int $rating,
        ?string $feedback
    ): void {
        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT id FROM order_item_ratings WHERE user_id = :uid AND order_item_id = :oiid LIMIT 1');
        $check->execute(['uid' => $userId, 'oiid' => $orderItemId]);
        $existingId = $check->fetchColumn();
        if (is_string($existingId) && $existingId !== '') {
            $upd = $pdo->prepare(
                'UPDATE order_item_ratings
                 SET rating = :rating, feedback = :feedback, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $upd->execute([
                'id' => $existingId,
                'rating' => $rating,
                'feedback' => $feedback,
            ]);
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO order_item_ratings (id, user_id, order_id, order_item_id, rating, feedback)
             VALUES (:id, :uid, :oid, :oiid, :rating, :feedback)'
        );
        $ins->execute([
            'id' => $id,
            'uid' => $userId,
            'oid' => $orderId,
            'oiid' => $orderItemId,
            'rating' => $rating,
            'feedback' => $feedback,
        ]);
    }

    public static function upsertDeliveryRating(
        string $id,
        string $userId,
        string $orderId,
        ?string $deliveryPartnerUserId,
        int $rating,
        ?string $feedback
    ): void {
        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT id FROM order_delivery_ratings WHERE user_id = :uid AND order_id = :oid LIMIT 1');
        $check->execute(['uid' => $userId, 'oid' => $orderId]);
        $existingId = $check->fetchColumn();
        if (is_string($existingId) && $existingId !== '') {
            $upd = $pdo->prepare(
                'UPDATE order_delivery_ratings
                 SET delivery_partner_user_id = :dpid, rating = :rating, feedback = :feedback, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $upd->execute([
                'id' => $existingId,
                'dpid' => $deliveryPartnerUserId,
                'rating' => $rating,
                'feedback' => $feedback,
            ]);
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO order_delivery_ratings (id, user_id, order_id, delivery_partner_user_id, rating, feedback)
             VALUES (:id, :uid, :oid, :dpid, :rating, :feedback)'
        );
        $ins->execute([
            'id' => $id,
            'uid' => $userId,
            'oid' => $orderId,
            'dpid' => $deliveryPartnerUserId,
            'rating' => $rating,
            'feedback' => $feedback,
        ]);
    }
}

