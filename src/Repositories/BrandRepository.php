<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class BrandRepository
{
    /**
     * @return list<array{id: string, created_at: string, name: string, about: string|null, logo: string|null, status: bool}>
     */
    public static function findAll(): array
    {
        $stmt = Database::connection()->query(
            'SELECT id, created_at, name, about, logo, status FROM brands ORDER BY name ASC, id ASC'
        );
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

    /** @return array{id: string, created_at: string, name: string, about: string|null, logo: string|null, status: bool}|null */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, created_at, name, about, logo, status FROM brands WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return self::normalizeRow($row);
    }

    public static function insert(string $id, string $name, ?string $about, ?string $logo, bool $status): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO brands (id, name, about, logo, status)
             VALUES (:id, :name, :about, :logo, :status)'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'about' => $about,
            'logo' => $logo,
            'status' => $status ? 1 : 0,
        ]);
    }

    public static function update(
        string $id,
        ?string $name,
        ?string $about,
        bool $aboutProvided,
        ?string $logo,
        bool $logoProvided,
        ?bool $status
    ): void {
        $sets = [];
        $params = ['id' => $id];

        if ($name !== null) {
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }
        if ($aboutProvided) {
            $sets[] = 'about = :about';
            $params['about'] = $about;
        }
        if ($logoProvided) {
            $sets[] = 'logo = :logo';
            $params['logo'] = $logo;
        }
        if ($status !== null) {
            $sets[] = 'status = :status';
            $params['status'] = $status ? 1 : 0;
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE brands SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }

    public static function delete(string $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM brands WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public static function countProductsByBrandId(string $brandId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS c FROM products WHERE brand_id = :bid'
        );
        $stmt->execute(['bid' => $brandId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, created_at: string, name: string, about: string|null, logo: string|null, status: bool}
     */
    private static function normalizeRow(array $row): array
    {
        $about = $row['about'];
        $logo = $row['logo'];

        return [
            'id' => (string) $row['id'],
            'created_at' => (string) $row['created_at'],
            'name' => (string) $row['name'],
            'about' => $about === null || $about === '' ? null : (string) $about,
            'logo' => $logo === null || $logo === '' ? null : (string) $logo,
            'status' => (bool) (int) $row['status'],
        ];
    }
}
