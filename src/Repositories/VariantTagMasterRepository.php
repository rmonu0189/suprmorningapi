<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class VariantTagMasterRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function findAll(?bool $onlyEnabled = null, ?string $q = null, int $limit = 100): array
    {
        $sql = 'SELECT id, created_at, name, status, sort_order FROM variant_tag_master';
        $where = [];
        $params = [];

        if ($onlyEnabled === true) {
            $where[] = 'status = 1';
        }
        $query = trim((string) $q);
        if ($query !== '') {
            $where[] = 'name LIKE :q';
            $params['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtoupper($query)) . '%';
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $limit = max(1, min(200, $limit));
        $sql .= ' ORDER BY sort_order ASC, name ASC, id ASC LIMIT ' . $limit;

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
            'SELECT id, created_at, name, status, sort_order FROM variant_tag_master WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::normalizeRow($row) : null;
    }

    public static function insert(string $id, string $name, bool $status, int $sortOrder): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO variant_tag_master (id, name, status, sort_order) VALUES (:id, :name, :status, :sort_order)'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'status' => $status ? 1 : 0,
            'sort_order' => $sortOrder,
        ]);
    }

    public static function nextSortOrder(): int
    {
        $stmt = Database::connection()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM variant_tag_master');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int) ($row['next_sort'] ?? 1) : 1;
    }

    public static function update(
        string $id,
        ?string $name,
        ?bool $status,
        ?int $sortOrder
    ): void {
        $sets = [];
        $params = ['id' => $id];
        if ($name !== null) {
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }
        if ($status !== null) {
            $sets[] = 'status = :status';
            $params['status'] = $status ? 1 : 0;
        }
        if ($sortOrder !== null) {
            $sets[] = 'sort_order = :sort_order';
            $params['sort_order'] = $sortOrder;
        }
        if ($sets === []) {
            return;
        }
        $stmt = Database::connection()->prepare('UPDATE variant_tag_master SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public static function delete(string $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM variant_tag_master WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /** @param list<string> $names */
    public static function countExistingEnabledByNames(array $names): int
    {
        $names = array_values(array_unique(array_filter($names, static fn ($n) => is_string($n) && trim($n) !== '')));
        if ($names === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $sql = "SELECT COUNT(*) AS c FROM variant_tag_master WHERE status = 1 AND name IN ($placeholders)";
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($names);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $row */
    private static function normalizeRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'created_at' => (string) $row['created_at'],
            'name' => (string) $row['name'],
            'status' => (bool) (int) ($row['status'] ?? 1),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }
}

