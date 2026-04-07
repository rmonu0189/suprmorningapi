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
        $sql = 'SELECT id, created_at, brand_id, name, description, status, metadata
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
            'SELECT id, created_at, brand_id, name, description, status, metadata
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
        string $name,
        ?string $description,
        bool $status,
        ?array $metadata
    ): void {
        $metaJson = self::encodeMetadata($metadata);
        $stmt = Database::connection()->prepare(
            'INSERT INTO products (id, brand_id, name, description, status, metadata)
             VALUES (:id, :bid, :name, :desc, :status, :meta)'
        );
        $stmt->execute([
            'id' => $id,
            'bid' => $brandId,
            'name' => $name,
            'desc' => $description,
            'status' => $status ? 1 : 0,
            'meta' => $metaJson,
        ]);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function update(
        string $id,
        ?string $brandId,
        ?string $name,
        ?string $description,
        bool $descriptionProvided,
        ?bool $status,
        ?array $metadata,
        bool $metadataProvided
    ): void {
        $sets = [];
        $params = ['id' => $id];

        if ($brandId !== null) {
            $sets[] = 'brand_id = :bid';
            $params['bid'] = $brandId;
        }
        if ($name !== null) {
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }
        if ($descriptionProvided) {
            $sets[] = 'description = :desc';
            $params['desc'] = $description;
        }
        if ($status !== null) {
            $sets[] = 'status = :status';
            $params['status'] = $status ? 1 : 0;
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

        return [
            'id' => (string) $row['id'],
            'created_at' => (string) $row['created_at'],
            'brand_id' => (string) $row['brand_id'],
            'name' => (string) $row['name'],
            'description' => $desc === null || $desc === '' ? null : (string) $desc,
            'status' => (bool) (int) $row['status'],
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
}
