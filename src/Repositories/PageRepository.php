<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class PageRepository
{
    /**
     * @return list<array{id: string, card_index: int, page_name: string, type: string, content: mixed}>
     */
    public static function findAll(?string $pageNameFilter = null): array
    {
        $pdo = Database::connection();
        if ($pageNameFilter !== null && trim($pageNameFilter) !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, card_index, page_name, type, content FROM pages
                 WHERE page_name = :pn ORDER BY card_index ASC, id ASC'
            );
            $stmt->execute(['pn' => $pageNameFilter]);
        } else {
            $stmt = $pdo->query(
                'SELECT id, card_index, page_name, type, content FROM pages
                 ORDER BY page_name ASC, card_index ASC, id ASC'
            );
        }

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

    /** @return array{id: string, card_index: int, page_name: string, type: string, content: mixed}|null */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, card_index, page_name, type, content FROM pages WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return self::normalizeRow($row);
    }

    public static function insert(string $id, string $pageName, string $type, array $content, int $cardIndex): void
    {
        $json = json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $stmt = Database::connection()->prepare(
            'INSERT INTO pages (id, card_index, page_name, type, content)
             VALUES (:id, :card_index, :page_name, :type, :content)'
        );
        $stmt->execute([
            'id' => $id,
            'card_index' => $cardIndex,
            'page_name' => $pageName,
            'type' => $type,
            'content' => $json,
        ]);
    }

    /**
     * @param array<string, mixed>|null $content
     */
    public static function update(
        string $id,
        ?string $pageName,
        ?string $type,
        ?array $content,
        ?int $cardIndex
    ): void {
        $sets = [];
        $params = ['id' => $id];

        if ($pageName !== null) {
            $sets[] = 'page_name = :page_name';
            $params['page_name'] = $pageName;
        }
        if ($type !== null) {
            $sets[] = 'type = :type';
            $params['type'] = $type;
        }
        if ($content !== null) {
            $sets[] = 'content = :content';
            $params['content'] = json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        if ($cardIndex !== null) {
            $sets[] = 'card_index = :card_index';
            $params['card_index'] = $cardIndex;
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE pages SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }

    public static function delete(string $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM pages WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, card_index: int, page_name: string, type: string, content: mixed}
     */
    private static function normalizeRow(array $row): array
    {
        $raw = $row['content'];
        $decoded = $raw;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $decoded = $raw;
            }
        }

        return [
            'id' => (string) $row['id'],
            'card_index' => (int) $row['card_index'],
            'page_name' => (string) $row['page_name'],
            'type' => (string) $row['type'],
            'content' => $decoded,
        ];
    }
}
