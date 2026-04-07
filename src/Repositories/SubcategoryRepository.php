<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class SubcategoryRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function findAll(?string $categoryId = null, ?bool $onlyEnabled = null): array
    {
        $sql = 'SELECT id, created_at, category_id, name, slug, status, sort_order FROM subcategories';
        $params = [];
        $w = [];
        if ($categoryId !== null && $categoryId !== '') {
            $w[] = 'category_id = :cid';
            $params['cid'] = $categoryId;
        }
        if ($onlyEnabled === true) {
            $w[] = 'status = 1';
        }
        if ($w !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $w);
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC, id ASC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) $out[] = self::normalizeRow($row);
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, created_at, category_id, name, slug, status, sort_order FROM subcategories WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return self::normalizeRow($row);
    }

    public static function insert(
        string $id,
        string $categoryId,
        string $name,
        ?string $slug,
        bool $status,
        int $sortOrder
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO subcategories (id, category_id, name, slug, status, sort_order)
             VALUES (:id, :cid, :name, :slug, :status, :sort)'
        );
        $stmt->execute([
            'id' => $id,
            'cid' => $categoryId,
            'name' => $name,
            'slug' => $slug,
            'status' => $status ? 1 : 0,
            'sort' => $sortOrder,
        ]);
    }

    public static function update(
        string $id,
        ?string $categoryId,
        ?string $name,
        ?string $slug,
        bool $slugProvided,
        ?bool $status,
        ?int $sortOrder
    ): void {
        $sets = [];
        $params = ['id' => $id];

        if ($categoryId !== null) {
            $sets[] = 'category_id = :cid';
            $params['cid'] = $categoryId;
        }
        if ($name !== null) {
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }
        if ($slugProvided) {
            $sets[] = 'slug = :slug';
            $params['slug'] = $slug;
        }
        if ($status !== null) {
            $sets[] = 'status = :status';
            $params['status'] = $status ? 1 : 0;
        }
        if ($sortOrder !== null) {
            $sets[] = 'sort_order = :sort';
            $params['sort'] = $sortOrder;
        }

        if ($sets === []) return;
        $sql = 'UPDATE subcategories SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }

    public static function delete(string $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM subcategories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public static function countProducts(string $subcategoryId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) AS c FROM products WHERE subcategory_id = :id');
        $stmt->execute(['id' => $subcategoryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $row */
    private static function normalizeRow(array $row): array
    {
        $slug = $row['slug'] ?? null;
        return [
            'id' => (string) $row['id'],
            'created_at' => (string) $row['created_at'],
            'category_id' => (string) $row['category_id'],
            'name' => (string) $row['name'],
            'slug' => $slug === null || $slug === '' ? null : (string) $slug,
            'status' => (bool) (int) ($row['status'] ?? 0),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }
}

