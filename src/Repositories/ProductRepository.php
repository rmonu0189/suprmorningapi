<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class ProductRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function findAll(?string $brandId = null): array
    {
        $sql = 'SELECT id, created_at, brand_id, category_id, subcategory_id, name, description, tags, status, is_subscribable, metadata
                FROM products';
        $params = [];
        if ($brandId !== null && $brandId !== '') {
            $sql .= ' WHERE brand_id = :bid';
            $params['bid'] = $brandId;
        }
        $sql .= ' ORDER BY name ASC, id ASC';
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

    /** @return array<string, mixed>|null */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, created_at, brand_id, category_id, subcategory_id, name, description, tags, status, is_subscribable, metadata
             FROM products WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::normalizeRow($row);
    }

    public static function insert(
        string $id,
        string $brandId,
        ?string $categoryId,
        ?string $subcategoryId,
        string $name,
        ?string $description,
        ?array $tags,
        bool $status,
        bool $isSubscribable,
        ?array $metadata
    ): void {
        $metaJson = self::encodeMetadata($metadata);
        $tagsJson = self::encodeTags($tags);
        $stmt = Database::connection()->prepare(
            'INSERT INTO products (id, brand_id, category_id, subcategory_id, name, description, tags, status, is_subscribable, metadata)
             VALUES (:id, :bid, :catid, :subid, :name, :desc, :tags, :status, :subscribable, :meta)'
        );
        $stmt->execute([
            'id' => $id,
            'bid' => $brandId,
            'catid' => $categoryId,
            'subid' => $subcategoryId,
            'name' => $name,
            'desc' => $description,
            'tags' => $tagsJson,
            'status' => $status ? 1 : 0,
            'subscribable' => $isSubscribable ? 1 : 0,
            'meta' => $metaJson,
        ]);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function update(
        string $id,
        ?string $brandId,
        ?string $categoryId,
        bool $categoryIdProvided,
        ?string $subcategoryId,
        bool $subcategoryIdProvided,
        ?string $name,
        ?string $description,
        bool $descriptionProvided,
        ?array $tags,
        bool $tagsProvided,
        ?bool $status,
        ?bool $isSubscribable,
        ?array $metadata,
        bool $metadataProvided
    ): void {
        $sets = [];
        $params = ['id' => $id];

        if ($brandId !== null) {
            $sets[] = 'brand_id = :bid';
            $params['bid'] = $brandId;
        }
        if ($categoryIdProvided) {
            $sets[] = 'category_id = :catid';
            $params['catid'] = $categoryId;
        }
        if ($subcategoryIdProvided) {
            $sets[] = 'subcategory_id = :subid';
            $params['subid'] = $subcategoryId;
        }
        if ($name !== null) {
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }
        if ($descriptionProvided) {
            $sets[] = 'description = :desc';
            $params['desc'] = $description;
        }
        if ($tagsProvided) {
            $sets[] = 'tags = :tags';
            $params['tags'] = self::encodeTags($tags);
        }
        if ($status !== null) {
            $sets[] = 'status = :status';
            $params['status'] = $status ? 1 : 0;
        }
        if ($isSubscribable !== null) {
            $sets[] = 'is_subscribable = :subscribable';
            $params['subscribable'] = $isSubscribable ? 1 : 0;
        }
        if ($metadataProvided) {
            $sets[] = 'metadata = :meta';
            $params['meta'] = self::encodeMetadata($metadata);
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }

    public static function delete(string $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public static function countVariantsByProductId(string $productId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS c FROM variants WHERE product_id = :pid'
        );
        $stmt->execute(['pid' => $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }

    /** Cart lines referencing any variant of this product block delete (RESTRICT). */
    public static function countCartItemsForProductVariants(string $productId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS c FROM cart_items ci
             INNER JOIN variants v ON v.id = ci.variant_id
             WHERE v.product_id = :pid'
        );
        $stmt->execute(['pid' => $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $row */
    private static function normalizeRow(array $row): array
    {
        $desc = $row['description'];
        $catId = $row['category_id'] ?? null;
        $subId = $row['subcategory_id'] ?? null;

        return [
            'id' => (string) $row['id'],
            'created_at' => (string) $row['created_at'],
            'brand_id' => (string) $row['brand_id'],
            'category_id' => $catId === null || $catId === '' ? null : (string) $catId,
            'subcategory_id' => $subId === null || $subId === '' ? null : (string) $subId,
            'name' => (string) $row['name'],
            'description' => $desc === null || $desc === '' ? null : (string) $desc,
            'tags' => self::decodeTags($row['tags'] ?? null),
            'status' => (bool) (int) $row['status'],
            'is_subscribable' => (bool) (int) ($row['is_subscribable'] ?? 1),
            'metadata' => self::decodeMetadata($row['metadata'] ?? null),
        ];
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

    private static function encodeMetadata(?array $metadata): ?string
    {
        if ($metadata === null) {
            return null;
        }

        $j = json_encode($metadata, JSON_UNESCAPED_UNICODE);

        return $j === false ? '{}' : $j;
    }

    /** @return list<string> */
    private static function decodeTags(mixed $json): array
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
                        $t = trim($item);
                        if ($t !== '') $out[] = $t;
                    }
                }
                return $out;
            }
        }
        return [];
    }

    /** @param list<string>|null $tags */
    private static function encodeTags(?array $tags): ?string
    {
        if ($tags === null) {
            return null;
        }
        $clean = [];
        foreach ($tags as $t) {
            if (is_string($t)) {
                $v = trim($t);
                if ($v !== '') $clean[] = $v;
            }
        }
        if ($clean === []) {
            return null;
        }
        $j = json_encode(array_values($clean), JSON_UNESCAPED_UNICODE);
        return $j === false ? null : $j;
    }
}
