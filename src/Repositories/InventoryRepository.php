<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class InventoryRepository
{
    /** @return array<string, mixed>|null */
    public static function findByVariantId(string $variantId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, created_at, variant_id, quantity, reserved_quantity
             FROM inventory WHERE variant_id = :vid LIMIT 1'
        );
        $stmt->execute(['vid' => $variantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::normalizeRow($row);
    }

    /**
     * Optional filter by variant_id. Joins product name and sku for admin list.
     *
     * @return list<array<string, mixed>>
     */
    public static function findAll(?string $variantId = null): array
    {
        $sql = 'SELECT i.id, i.created_at, i.variant_id, i.quantity, i.reserved_quantity,
                       v.sku, v.name AS variant_name, v.product_id,
                       p.name AS product_name
                FROM inventory i
                INNER JOIN variants v ON v.id = i.variant_id
                INNER JOIN products p ON p.id = v.product_id';
        $params = [];
        if ($variantId !== null && $variantId !== '') {
            $sql .= ' WHERE i.variant_id = :vid';
            $params['vid'] = $variantId;
        }
        $sql .= ' ORDER BY p.name ASC, v.sku ASC, i.id ASC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = [
                    'id' => (string) $row['id'],
                    'created_at' => (string) $row['created_at'],
                    'variant_id' => (string) $row['variant_id'],
                    'quantity' => (int) $row['quantity'],
                    'reserved_quantity' => (int) $row['reserved_quantity'],
                    'sku' => (string) $row['sku'],
                    'variant_name' => (string) $row['variant_name'],
                    'product_id' => (string) $row['product_id'],
                    'product_name' => (string) $row['product_name'],
                ];
            }
        }

        return $out;
    }

    public static function insert(string $id, string $variantId, int $quantity, int $reservedQuantity): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO inventory (id, variant_id, quantity, reserved_quantity)
             VALUES (:id, :vid, :qty, :res)'
        );
        $stmt->execute([
            'id' => $id,
            'vid' => $variantId,
            'qty' => $quantity,
            'res' => $reservedQuantity,
        ]);
    }

    public static function updateQuantities(
        string $variantId,
        ?int $quantity,
        ?int $reservedQuantity
    ): void {
        $sets = [];
        $params = ['vid' => $variantId];
        if ($quantity !== null) {
            $sets[] = 'quantity = :qty';
            $params['qty'] = $quantity;
        }
        if ($reservedQuantity !== null) {
            $sets[] = 'reserved_quantity = :res';
            $params['res'] = $reservedQuantity;
        }
        if ($sets === []) {
            return;
        }
        $sql = 'UPDATE inventory SET ' . implode(', ', $sets) . ' WHERE variant_id = :vid';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }

    /** @param array<string, mixed> $row */
    private static function normalizeRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'created_at' => (string) $row['created_at'],
            'variant_id' => (string) $row['variant_id'],
            'quantity' => (int) $row['quantity'],
            'reserved_quantity' => (int) $row['reserved_quantity'],
        ];
    }

    public static function countCartItemsForVariant(string $variantId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS c FROM cart_items WHERE variant_id = :vid'
        );
        $stmt->execute(['vid' => $variantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }
}
