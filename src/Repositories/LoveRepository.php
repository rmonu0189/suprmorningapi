<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class LoveRepository
{
    public static function exists(string $userId, string $variantId): bool
    {
        return self::findId($userId, $variantId) !== null;
    }

    public static function findId(string $userId, string $variantId): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM loves WHERE user_id = :uid AND variant_id = :vid LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'vid' => $variantId]);
        $v = $stmt->fetchColumn();
        if (!is_string($v) || $v === '') {
            return null;
        }

        return $v;
    }

    public static function insert(string $id, string $userId, string $variantId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO loves (id, user_id, variant_id) VALUES (:id, :uid, :vid)'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId, 'vid' => $variantId]);
    }

    public static function delete(string $userId, string $variantId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM loves WHERE user_id = :uid AND variant_id = :vid'
        );
        $stmt->execute(['uid' => $userId, 'vid' => $variantId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<string>
     */
    public static function variantIdsForUser(string $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT variant_id FROM loves WHERE user_id = :uid ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['uid' => $userId]);
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($cols)) {
            return [];
        }

        $out = [];
        foreach ($cols as $c) {
            if (is_string($c)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>> loves rows with nested variants like mobile
     */
    public static function findAllWithVariantsForUser(string $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT l.id AS love_id, l.created_at AS love_created, l.variant_id,
                    v.id AS v_id, v.created_at AS v_created, v.product_id, v.name AS v_name, v.sku, v.price, v.mrp,
                    v.images, v.metadata, v.status AS v_status, v.discount_tag,
                    p.name AS p_name, b.name AS b_name
             FROM loves l
             INNER JOIN variants v ON v.id = l.variant_id
             INNER JOIN products p ON p.id = v.product_id
             INNER JOIN brands b ON b.id = p.brand_id
             WHERE l.user_id = :uid AND v.status = 1 AND p.status = 1 AND b.status = 1
             ORDER BY l.created_at DESC, l.id DESC'
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
            $images = [];
            if (isset($row['images']) && is_string($row['images']) && $row['images'] !== '') {
                $d = json_decode($row['images'], true);
                if (is_array($d)) {
                    foreach ($d as $u) {
                        if (is_string($u)) {
                            $images[] = $u;
                        }
                    }
                }
            }
            $meta = null;
            if (isset($row['metadata']) && is_string($row['metadata']) && $row['metadata'] !== '') {
                $m = json_decode($row['metadata'], true);
                $meta = is_array($m) ? $m : null;
            }

            $out[] = [
                'id' => (string) $row['love_id'],
                'user_id' => $userId,
                'variant_id' => (string) $row['variant_id'],
                'created_at' => (string) $row['love_created'],
                'variants' => [
                    'id' => (string) $row['v_id'],
                    'created_at' => (string) $row['v_created'],
                    'product_id' => (string) $row['product_id'],
                    'name' => (string) $row['v_name'],
                    'sku' => (string) $row['sku'],
                    'price' => (float) $row['price'],
                    'mrp' => (float) $row['mrp'],
                    'images' => $images,
                    'metadata' => $meta,
                    'status' => (bool) (int) $row['v_status'],
                    'discount_tag' => $row['discount_tag'] !== null && $row['discount_tag'] !== '' ? (string) $row['discount_tag'] : null,
                    'products' => [
                        'name' => (string) $row['p_name'],
                        'brands' => [
                            'name' => (string) $row['b_name'],
                        ],
                    ],
                ],
            ];
        }

        return $out;
    }
}
