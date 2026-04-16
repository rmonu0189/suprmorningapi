<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class CatalogRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function findListingVariants(?string $categoryId = null): array
    {
        $sql = 'SELECT v.id, v.created_at, v.product_id, v.name, v.sku, v.price, v.mrp, v.images,
                       v.status, v.discount_tag,
                       p.id AS product_table_id, p.name AS product_name, p.description AS product_description,
                       p.status AS product_status,
                       b.id AS brand_id_val, b.name AS brand_name, b.about AS brand_about, b.logo AS brand_logo, b.status AS brand_status,
                       i.quantity AS inv_quantity, i.reserved_quantity AS inv_reserved
                FROM variants v
                INNER JOIN products p ON p.id = v.product_id
                INNER JOIN brands b ON b.id = p.brand_id
                LEFT JOIN inventory i ON i.variant_id = v.id
                WHERE v.status = 1 AND p.status = 1 AND b.status = 1';

        $params = [];
        if ($categoryId !== null && $categoryId !== '') {
            $sql .= ' AND p.category_id = :categoryId';
            $params['categoryId'] = $categoryId;
        }

        $sql .= ' ORDER BY p.name ASC, v.name ASC, v.id ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::shapeVariantRow($row);
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null variant row + products + brands + inventory + images as array
     */
    public static function findVariantDetail(string $variantId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT v.id, v.created_at, v.product_id, v.name, v.sku, v.price, v.mrp, v.images, v.metadata,
                    v.status, v.discount_tag,
                    p.id AS product_table_id, p.name AS product_name, p.brand_id, p.description AS product_description,
                    p.metadata AS product_metadata, p.status AS product_status,
                    b.id AS brand_id_val, b.name AS brand_name, b.about AS brand_about, b.logo AS brand_logo, b.status AS brand_status,
                    i.quantity AS inv_quantity, i.reserved_quantity AS inv_reserved
             FROM variants v
             INNER JOIN products p ON p.id = v.product_id
             INNER JOIN brands b ON b.id = p.brand_id
             LEFT JOIN inventory i ON i.variant_id = v.id
             WHERE v.id = :vid AND v.status = 1 AND p.status = 1 AND b.status = 1
             LIMIT 1'
        );
        $stmt->execute(['vid' => $variantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::shapeVariantRow($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function findVariantsByProductId(string $productId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT v.id, v.created_at, v.product_id, v.name, v.sku, v.price, v.mrp, v.images,
                    v.status, v.discount_tag,
                    i.quantity AS inv_quantity, i.reserved_quantity AS inv_reserved
             FROM variants v
             LEFT JOIN inventory i ON i.variant_id = v.id
             WHERE v.product_id = :pid AND v.status = 1
             ORDER BY v.name ASC, v.id ASC'
        );
        $stmt->execute(['pid' => $productId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::shapeVariantSibling($row);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function shapeVariantRow(array $row): array
    {
        $images = self::decodeImages($row['images'] ?? null);

        return [
            'id' => (string) $row['id'],
            'created_at' => (string) $row['created_at'],
            'product_id' => (string) $row['product_id'],
            'name' => (string) $row['name'],
            'sku' => (string) $row['sku'],
            'price' => (float) $row['price'],
            'mrp' => (float) $row['mrp'],
            'images' => $images,
            'metadata' => self::decodeJsonObject($row['metadata'] ?? null),
            'status' => (bool) (int) $row['status'],
            'discount_tag' => $row['discount_tag'] !== null && $row['discount_tag'] !== '' ? (string) $row['discount_tag'] : null,
            'products' => [
                'id' => (string) $row['product_table_id'],
                'name' => (string) $row['product_name'],
                'status' => (bool) (int) ($row['product_status'] ?? 1),
                'description' => $row['product_description'] !== null && $row['product_description'] !== ''
                    ? (string) $row['product_description'] : null,
                'metadata' => self::decodeJsonObject($row['product_metadata'] ?? null),
                'brands' => [
                    'id' => (string) $row['brand_id_val'],
                    'name' => (string) $row['brand_name'],
                    'about' => $row['brand_about'] !== null && $row['brand_about'] !== '' ? (string) $row['brand_about'] : null,
                    'logo' => $row['brand_logo'] !== null && $row['brand_logo'] !== '' ? (string) $row['brand_logo'] : null,
                    'status' => (bool) (int) ($row['brand_status'] ?? 1),
                ],
            ],
            'inventory' => [
                'quantity' => isset($row['inv_quantity']) ? (int) $row['inv_quantity'] : 0,
                'reserved_quantity' => isset($row['inv_reserved']) ? (int) $row['inv_reserved'] : 0,
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    private static function shapeVariantSibling(array $row): array
    {
        $images = self::decodeImages($row['images'] ?? null);

        return [
            'id' => (string) $row['id'],
            'created_at' => (string) $row['created_at'],
            'product_id' => (string) $row['product_id'],
            'name' => (string) $row['name'],
            'sku' => (string) $row['sku'],
            'price' => (float) $row['price'],
            'mrp' => (float) $row['mrp'],
            'images' => $images,
            'status' => (bool) (int) $row['status'],
            'discount_tag' => $row['discount_tag'] !== null && $row['discount_tag'] !== '' ? (string) $row['discount_tag'] : null,
            'inventory' => [
                'quantity' => isset($row['inv_quantity']) ? (int) $row['inv_quantity'] : 0,
                'reserved_quantity' => isset($row['inv_reserved']) ? (int) $row['inv_reserved'] : 0,
            ],
        ];
    }

    private static function decodeImages(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        if (is_string($json)) {
            $d = json_decode($json, true);
            if (is_array($d)) {
                $out = [];
                foreach ($d as $item) {
                    if (is_string($item)) {
                        $out[] = $item;
                    }
                }

                return $out;
            }
        }

        return [];
    }

    private static function decodeJsonObject(mixed $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        if (is_string($json)) {
            $d = json_decode($json, true);

            return is_array($d) ? $d : null;
        }

        return null;
    }

    /**
     * Batch load variant + product + brand for order line items (single query).
     *
     * @param list<string> $variantIds
     * @return array<string, array{product_id: string, image: string, brand_name: string, product_name: string, variant_name: string, sku: string, price: float, mrp: float}>
     */
    public static function snapshotVariantsForOrder(array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_filter($variantIds)));
        if ($variantIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT v.id AS variant_id, v.product_id, v.name AS vname, v.sku, v.images, v.price, v.mrp,
                    p.name AS pname, b.name AS bname
             FROM variants v
             INNER JOIN products p ON p.id = v.product_id
             INNER JOIN brands b ON b.id = p.brand_id
             WHERE v.id IN ($placeholders)"
        );
        $stmt->execute($variantIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $vid = (string) $row['variant_id'];
            $imgs = self::decodeImages($row['images'] ?? null);
            $out[$vid] = [
                'product_id' => (string) $row['product_id'],
                'image' => $imgs[0] ?? '',
                'brand_name' => (string) $row['bname'],
                'product_name' => (string) $row['pname'],
                'variant_name' => (string) $row['vname'],
                'sku' => (string) $row['sku'],
                'price' => (float) ($row['price'] ?? 0),
                'mrp' => (float) ($row['mrp'] ?? 0),
            ];
        }

        return $out;
    }

    /** First image URL for order line snapshot */
    public static function variantFirstImage(string $variantId): string
    {
        $stmt = Database::connection()->prepare('SELECT images FROM variants WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $variantId]);
        $col = $stmt->fetchColumn();
        if (!is_string($col) || $col === '') {
            return '';
        }
        $arr = self::decodeImages($col);

        return $arr[0] ?? '';
    }

    /** @return array{product_name: string, brand_name: string, variant_name: string, sku: string}|null */
    public static function variantLabels(string $variantId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT v.name AS vname, v.sku, p.name AS pname, b.name AS bname
             FROM variants v
             INNER JOIN products p ON p.id = v.product_id
             INNER JOIN brands b ON b.id = p.brand_id
             WHERE v.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $variantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'product_name' => (string) $row['pname'],
            'brand_name' => (string) $row['bname'],
            'variant_name' => (string) $row['vname'],
            'sku' => (string) $row['sku'],
        ];
    }
}
