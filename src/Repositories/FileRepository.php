<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class FileRepository
{
    /** @return array{id: string, created_at: string, created_by: string|null, kind: string, storage_path: string, original_name: string|null, mime: string, size_bytes: int, access_key: string, is_active: bool}|null */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, created_at, created_by, kind, storage_path, original_name, mime, size_bytes, access_key, is_active
             FROM files WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return self::normalizeRow($row);
    }

    /** @return array{id: string, created_at: string, created_by: string|null, kind: string, storage_path: string, original_name: string|null, mime: string, size_bytes: int, access_key: string, is_active: bool}|null */
    public static function findActiveById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, created_at, created_by, kind, storage_path, original_name, mime, size_bytes, access_key, is_active
             FROM files WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return self::normalizeRow($row);
    }

    public static function insert(
        string $id,
        ?string $createdBy,
        string $kind,
        string $storagePath,
        ?string $originalName,
        string $mime,
        int $sizeBytes,
        string $accessKey
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO files (id, created_by, kind, storage_path, original_name, mime, size_bytes, access_key, is_active)
             VALUES (:id, :created_by, :kind, :storage_path, :original_name, :mime, :size_bytes, :access_key, 1)'
        );
        $stmt->execute([
            'id' => $id,
            'created_by' => $createdBy,
            'kind' => $kind,
            'storage_path' => $storagePath,
            'original_name' => $originalName,
            'mime' => $mime,
            'size_bytes' => $sizeBytes,
            'access_key' => $accessKey,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, created_at: string, created_by: string|null, kind: string, storage_path: string, original_name: string|null, mime: string, size_bytes: int, access_key: string, is_active: bool}
     */
    private static function normalizeRow(array $row): array
    {
        $createdBy = $row['created_by'] ?? null;
        $original = $row['original_name'] ?? null;

        return [
            'id' => (string) $row['id'],
            'created_at' => (string) $row['created_at'],
            'created_by' => $createdBy === null || $createdBy === '' ? null : (string) $createdBy,
            'kind' => (string) $row['kind'],
            'storage_path' => (string) $row['storage_path'],
            'original_name' => $original === null || $original === '' ? null : (string) $original,
            'mime' => (string) $row['mime'],
            'size_bytes' => (int) $row['size_bytes'],
            'access_key' => (string) $row['access_key'],
            'is_active' => (bool) (int) ($row['is_active'] ?? 0),
        ];
    }
}

