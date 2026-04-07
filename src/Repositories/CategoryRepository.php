<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class CategoryRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function findAll(?bool $onlyEnabled = null): array
    {
        $sql = 'SELECT id, created_at, name, slug, status, sort_order FROM categories';
        $params = [];
        if ($onlyEnabled === true) {
            $sql .= ' WHERE status = 1';
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
            'SELECT id, created_at, name, slug, status, sort_order FROM categories WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return self::normalizeRow($row);
    }

    public static function insert(string $id, string $name, ?string $slug, bool $status, int $sortOrder): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO categories (id, name, slug, status, sort_order)
             VALUES (:id, :name, :slug, :status, :sort)'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'status' => $status ? 1 : 0,
            'sort' => $sortOrder,
        ]);
    }

    public static function update(
        string $id,
        ?string $name,
        ?string $slug,
        bool $slugProvided,
        ?bool $status,
        ?int $sortOrder
    ): void {
        $sets = [];
        $params = ['id' => $id];

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
        $sql = 'UPDATE categories SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }

    public static function delete(string $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public static function countSubcategories(string $categoryId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) AS c FROM subcategories WHERE category_id = :id');
        $stmt->execute(['id' => $categoryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }

    public static function countProducts(string $categoryId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) AS c FROM products WHERE category_id = :id');
        $stmt->execute(['id' => $categoryId]);
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
            'name' => (string) $row['name'],
            'slug' => $slug === null || $slug === '' ? null : (string) $slug,
            'status' => (bool) (int) ($row['status'] ?? 0),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }
}

