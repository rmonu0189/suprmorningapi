<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class VariantRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function findAll(?string $productId = null): array
    {
        $sql = 'SELECT id, created_at, product_id, name, sku, price, mrp, images, metadata, status, discount_tag
                FROM variants';
        $params = [];
        if ($productId !== null && $productId !== '') {
            $sql .= ' WHERE product_id = :pid';
            $params['pid'] = $productId;
        }
        $sql .= ' ORDER BY name ASC, sku ASC, id ASC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::normalizeRow($row);
            }
        }

        return $out;
    }

    /**
     * Lightweight search for admin UIs (inventory, pickers).
     *
     * @return list<array<string, mixed>>
     */
    public static function searchWithContext(string $query, int $limit = 50): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }

        $limit = max(1, min(200, $limit));

        // Escape LIKE wildcards. MySQL/MariaDB treat backslash as the default LIKE escape.
        // (Avoid `... ESCAPE '\'` because it breaks on some MariaDB configurations.)
        $needle = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $like = '%' . $needle . '%';

        // NOTE: some PDO drivers don't allow binding LIMIT reliably; embed the sanitized int.
        $sql = 'SELECT v.id, v.created_at, v.product_id, v.name, v.sku, v.price, v.mrp, v.images, v.metadata, v.status, v.discount_tag,
                       p.name AS product_name,
                       b.name AS brand_name
                FROM variants v
                LEFT JOIN products p ON p.id = v.product_id
                LEFT JOIN brands b ON b.id = p.brand_id
                WHERE (
                       v.id = ?
                       OR v.sku = ?
                       OR v.sku LIKE ?
                       OR v.name LIKE ?
                       OR p.name LIKE ?
                       OR b.name LIKE ?
                )
                ORDER BY
                    (CASE WHEN v.sku = ? THEN 0 WHEN v.id = ? THEN 0 ELSE 1 END),
                    v.name ASC, v.sku ASC, v.id ASC
                LIMIT ' . $limit;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            $q,    // v.id = ?
            $q,    // v.sku = ?
            $like, // v.sku LIKE ?
            $like, // v.name LIKE ?
            $like, // p.name LIKE ?
            $like, // b.name LIKE ?
            $q,    // ORDER BY CASE WHEN v.sku = ?
            $q,    // ORDER BY CASE WHEN v.id = ?
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $norm = self::normalizeRow($row);
            $norm['product_name'] = isset($row['product_name']) ? (string) $row['product_name'] : null;
            $norm['brand_name'] = isset($row['brand_name']) ? (string) $row['brand_name'] : null;
            $out[] = $norm;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, created_at, product_id, name, sku, price, mrp, images, metadata, status, discount_tag
             FROM variants WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::normalizeRow($row);
    }

    public static function skuExists(string $sku, ?string $exceptVariantId = null): bool
    {
        if ($exceptVariantId !== null) {
            $stmt = Database::connection()->prepare(
                'SELECT id FROM variants WHERE sku = :sku AND id != :eid LIMIT 1'
            );
            $stmt->execute(['sku' => $sku, 'eid' => $exceptVariantId]);
        } else {
            $stmt = Database::connection()->prepare(
                'SELECT id FROM variants WHERE sku = :sku LIMIT 1'
            );
            $stmt->execute(['sku' => $sku]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row);
    }

    /**
     * @param list<string> $images
     * @param array<string, mixed>|null $metadata
     */
    public static function insert(
        string $id,
        string $productId,
        string $name,
        string $sku,
        float $price,
        float $mrp,
        array $images,
        ?array $metadata,
        bool $status,
        ?string $discountTag
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO variants (id, product_id, name, sku, price, mrp, images, metadata, status, discount_tag)
             VALUES (:id, :pid, :name, :sku, :price, :mrp, :images, :meta, :status, :dtag)'
        );
        $stmt->execute([
            'id' => $id,
            'pid' => $productId,
            'name' => $name,
            'sku' => $sku,
            'price' => $price,
            'mrp' => $mrp,
            'images' => self::encodeImages($images),
            'meta' => self::encodeMetadata($metadata),
            'status' => $status ? 1 : 0,
            'dtag' => $discountTag,
        ]);
    }

    /**
     * @param list<string>|null $images
     * @param array<string, mixed>|null $metadata
     */
    public static function update(
        string $id,
        ?string $productId,
        ?string $name,
        ?string $sku,
        ?float $price,
        ?float $mrp,
        ?array $images,
        bool $imagesProvided,
        ?array $metadata,
        bool $metadataProvided,
        ?bool $status,
        ?string $discountTag,
        bool $discountTagProvided
    ): void {
        $sets = [];
        $params = ['id' => $id];

        if ($productId !== null) {
            $sets[] = 'product_id = :pid';
            $params['pid'] = $productId;
        }
        if ($name !== null) {
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }
        if ($sku !== null) {
            $sets[] = 'sku = :sku';
            $params['sku'] = $sku;
        }
        if ($price !== null) {
            $sets[] = 'price = :price';
            $params['price'] = $price;
        }
        if ($mrp !== null) {
            $sets[] = 'mrp = :mrp';
            $params['mrp'] = $mrp;
        }
        if ($imagesProvided) {
            $sets[] = 'images = :images';
            $params['images'] = self::encodeImages($images ?? []);
        }
        if ($metadataProvided) {
            $sets[] = 'metadata = :meta';
            $params['meta'] = self::encodeMetadata($metadata);
        }
        if ($status !== null) {
            $sets[] = 'status = :status';
            $params['status'] = $status ? 1 : 0;
        }
        if ($discountTagProvided) {
            $sets[] = 'discount_tag = :dtag';
            $params['dtag'] = $discountTag;
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE variants SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }

    public static function delete(string $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM variants WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** @param array<string, mixed> $row */
    private static function normalizeRow(array $row): array
    {
        $dt = $row['discount_tag'];

        return [
            'id' => (string) $row['id'],
            'created_at' => (string) $row['created_at'],
            'product_id' => (string) $row['product_id'],
            'name' => (string) $row['name'],
            'sku' => (string) $row['sku'],
            'price' => (float) $row['price'],
            'mrp' => (float) $row['mrp'],
            'images' => self::decodeImages($row['images'] ?? null),
            'metadata' => self::decodeMetadata($row['metadata'] ?? null),
            'status' => (bool) (int) $row['status'],
            'discount_tag' => $dt === null || $dt === '' ? null : (string) $dt,
        ];
    }

    /** @return list<string> */
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

    private static function decodeMetadata(mixed $json): ?array
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

    /** @param list<string> $images */
    private static function encodeImages(array $images): ?string
    {
        if ($images === []) {
            return null;
        }

        $j = json_encode(array_values($images), JSON_UNESCAPED_UNICODE);

        return $j === false ? null : $j;
    }

    private static function encodeMetadata(?array $metadata): ?string
    {
        if ($metadata === null) {
            return null;
        }

        $j = json_encode($metadata, JSON_UNESCAPED_UNICODE);

        return $j === false ? null : $j;
    }
}
