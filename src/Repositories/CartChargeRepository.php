<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class CartChargeRepository
{
    /**
     * @return list<array{id: string, index: int, title: string, amount: float, min_order_value: float|null, info: string|null}>
     */
    public static function findAllOrdered(): array
    {
        $stmt = Database::connection()->query(
            'SELECT id, charge_index, title, amount, min_order_value, info
             FROM cart_charges ORDER BY charge_index ASC, id ASC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::normalize($row);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function normalize(array $row): array
    {
        $mov = $row['min_order_value'];
        $info = $row['info'];

        return [
            'id' => (string) $row['id'],
            'index' => (int) $row['charge_index'],
            'title' => (string) $row['title'],
            'amount' => (float) $row['amount'],
            'min_order_value' => $mov === null ? null : (float) $mov,
            'info' => $info === null || $info === '' ? null : (string) $info,
        ];
    }
}
