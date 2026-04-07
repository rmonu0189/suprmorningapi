<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class CartRepository
{
    /** @return array{id: string, user_id: string, status: string, coupon_code: ?string, discount_type: ?string, discount_value: ?string}|null */
    public static function findActiveCart(string $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, status, coupon_code, discount_type, discount_value
             FROM carts WHERE user_id = :uid AND status = :st LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'st' => 'active']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::normalizeCart($row);
    }

    public static function insertCart(string $id, string $userId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO carts (id, user_id, status) VALUES (:id, :uid, :st)'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId, 'st' => 'active']);
    }

    public static function lockActiveCart(string $userId): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE carts SET status = 'locked' WHERE user_id = :uid AND status = 'active'"
        );
        $stmt->execute(['uid' => $userId]);
    }

    /** @return array<string, mixed> cart + cart_items with nested variants */
    public static function getActiveCartWithItems(string $userId): ?array
    {
        $cart = self::findActiveCart($userId);
        if ($cart === null) {
            return null;
        }

        $items = self::fetchCartItemsNested($cart['id']);

        return array_merge($cart, ['cart_items' => $items]);
    }

    /**
     * Ensures an active cart exists and returns full structure (may have empty cart_items).
     *
     * @return array<string, mixed>
     */
    public static function getOrCreateActiveCartWithItems(string $userId, string $newCartId): array
    {
        $existing = self::findActiveCart($userId);
        if ($existing !== null) {
            $items = self::fetchCartItemsNested($existing['id']);

            return array_merge($existing, ['cart_items' => $items]);
        }

        self::insertCart($newCartId, $userId);
        $cart = self::findActiveCart($userId);
        if ($cart === null) {
            throw new \RuntimeException('Could not create cart');
        }

        return array_merge($cart, ['cart_items' => []]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchCartItemsNested(string $cartId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ci.id, ci.created_at, ci.cart_id, ci.user_id, ci.variant_id, ci.quantity, ci.unit_price, ci.unit_mrp,
                    v.name AS v_name, v.sku, v.images, p.name AS p_name, b.name AS b_name
             FROM cart_items ci
             INNER JOIN variants v ON v.id = ci.variant_id
             INNER JOIN products p ON p.id = v.product_id
             INNER JOIN brands b ON b.id = p.brand_id
             WHERE ci.cart_id = :cid
             ORDER BY ci.created_at ASC, ci.id ASC'
        );
        $stmt->execute(['cid' => $cartId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $imgList = [];
            if (isset($row['images']) && is_string($row['images']) && $row['images'] !== '') {
                $d = json_decode($row['images'], true);
                if (is_array($d)) {
                    foreach ($d as $u) {
                        if (is_string($u)) {
                            $imgList[] = $u;
                        }
                    }
                }
            }
            if ($imgList === [] && $images !== '') {
                $imgList = [$images];
            }

            $out[] = [
                'id' => (string) $row['id'],
                'created_at' => (string) $row['created_at'],
                'cart_id' => (string) $row['cart_id'],
                'user_id' => (string) $row['user_id'],
                'variant_id' => (string) $row['variant_id'],
                'quantity' => (int) $row['quantity'],
                'unit_price' => (float) $row['unit_price'],
                'unit_mrp' => (float) $row['unit_mrp'],
                'variants' => [
                    'id' => (string) $row['variant_id'],
                    'name' => (string) $row['v_name'],
                    'sku' => (string) $row['sku'],
                    'images' => $imgList,
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

    /** @return array<string, mixed>|null cart item row */
    public static function findCartItemByCartAndVariant(string $cartId, string $variantId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, cart_id, user_id, variant_id, quantity, unit_price, unit_mrp FROM cart_items
             WHERE cart_id = :cid AND variant_id = :vid LIMIT 1'
        );
        $stmt->execute(['cid' => $cartId, 'vid' => $variantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'cart_id' => (string) $row['cart_id'],
            'user_id' => (string) $row['user_id'],
            'variant_id' => (string) $row['variant_id'],
            'quantity' => (int) $row['quantity'],
            'unit_price' => (float) $row['unit_price'],
            'unit_mrp' => (float) $row['unit_mrp'],
        ];
    }

    public static function insertCartItem(
        string $id,
        string $cartId,
        string $userId,
        string $variantId,
        int $quantity,
        float $unitPrice,
        float $unitMrp
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO cart_items (id, cart_id, user_id, variant_id, quantity, unit_price, unit_mrp)
             VALUES (:id, :cid, :uid, :vid, :qty, :up, :um)'
        );
        $stmt->execute([
            'id' => $id,
            'cid' => $cartId,
            'uid' => $userId,
            'vid' => $variantId,
            'qty' => $quantity,
            'up' => $unitPrice,
            'um' => $unitMrp,
        ]);
    }

    public static function updateCartItemQty(string $itemId, string $userId, int $quantity, float $unitPrice, float $unitMrp): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE cart_items SET quantity = :q, unit_price = :up, unit_mrp = :um
             WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([
            'id' => $itemId,
            'uid' => $userId,
            'q' => $quantity,
            'up' => $unitPrice,
            'um' => $unitMrp,
        ]);
    }

    public static function deleteCartItem(string $itemId, string $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM cart_items WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute(['id' => $itemId, 'uid' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** @return array{id: string, user_id: string, status: string, coupon_code: ?string, discount_type: ?string, discount_value: ?string}|null */
    public static function findCartByIdForUser(string $cartId, string $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, status, coupon_code, discount_type, discount_value FROM carts
             WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $cartId, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::normalizeCart($row);
    }

    /**
     * @return list<array<string, mixed>> line rows for totals (price, mrp, qty, variant_id)
     */
    public static function getLineItemsForCart(string $cartId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT variant_id, quantity, unit_price, unit_mrp FROM cart_items WHERE cart_id = :cid'
        );
        $stmt->execute(['cid' => $cartId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = [
                    'variant_id' => (string) $row['variant_id'],
                    'quantity' => (int) $row['quantity'],
                    'unit_price' => (float) $row['unit_price'],
                    'unit_mrp' => (float) $row['unit_mrp'],
                ];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function normalizeCart(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'user_id' => (string) $row['user_id'],
            'status' => (string) $row['status'],
            'coupon_code' => $row['coupon_code'] !== null && $row['coupon_code'] !== '' ? (string) $row['coupon_code'] : null,
            'discount_type' => $row['discount_type'] !== null && $row['discount_type'] !== '' ? (string) $row['discount_type'] : null,
            'discount_value' => $row['discount_value'] !== null && $row['discount_value'] !== '' ? (string) $row['discount_value'] : null,
        ];
    }
}
