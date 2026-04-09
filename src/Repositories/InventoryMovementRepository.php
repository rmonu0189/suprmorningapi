<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class InventoryMovementRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function findAll(int $warehouseId, ?string $variantId = null, int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT m.id, m.created_at, m.warehouse_id, m.variant_id, m.delta_quantity, m.note, m.created_by,
                       v.sku, v.name AS variant_name, v.product_id,
                       p.name AS product_name
                FROM inventory_movements m
                INNER JOIN variants v ON v.id = m.variant_id
                INNER JOIN products p ON p.id = v.product_id';

        $params = ['wid' => $warehouseId];
        $sql .= ' WHERE m.warehouse_id = :wid';
        if ($variantId !== null && $variantId !== '') {
            $sql .= ' AND m.variant_id = :vid';
            $params['vid'] = $variantId;
        }

        $sql .= ' ORDER BY m.created_at DESC, m.id DESC LIMIT :lim OFFSET :off';
        $stmt = Database::connection()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $out[] = [
                'id' => (string) $row['id'],
                'created_at' => (string) $row['created_at'],
                'warehouse_id' => isset($row['warehouse_id']) ? (int) $row['warehouse_id'] : 0,
                'variant_id' => (string) $row['variant_id'],
                'delta_quantity' => (int) $row['delta_quantity'],
                'note' => ($row['note'] === null || $row['note'] === '') ? null : (string) $row['note'],
                'created_by' => ($row['created_by'] === null || $row['created_by'] === '') ? null : (string) $row['created_by'],
                'sku' => (string) $row['sku'],
                'variant_name' => (string) $row['variant_name'],
                'product_id' => (string) $row['product_id'],
                'product_name' => (string) $row['product_name'],
            ];
        }

        return $out;
    }

    public static function insert(string $id, int $warehouseId, string $variantId, int $deltaQuantity, ?string $note, ?string $createdBy): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO inventory_movements (id, warehouse_id, variant_id, delta_quantity, note, created_by)
             VALUES (:id, :wid, :vid, :dq, :note, :cb)'
        );
        $stmt->execute([
            'id' => $id,
            'wid' => $warehouseId,
            'vid' => $variantId,
            'dq' => $deltaQuantity,
            'note' => $note,
            'cb' => $createdBy,
        ]);
    }
}

