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

    /** @return array<string, mixed>|null */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, charge_index, title, amount, min_order_value, info FROM cart_charges WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::normalize($row);
    }

    public static function insert(
        string $id,
        int $chargeIndex,
        string $title,
        float $amount,
        ?float $minOrderValue,
        ?string $info
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO cart_charges (id, charge_index, title, amount, min_order_value, info)
             VALUES (:id, :idx, :title, :amount, :mov, :info)'
        );
        $stmt->execute([
            'id' => $id,
            'idx' => $chargeIndex,
            'title' => $title,
            'amount' => $amount,
            'mov' => $minOrderValue,
            'info' => $info,
        ]);
    }

    public static function update(
        string $id,
        ?int $chargeIndex,
        ?string $title,
        ?float $amount,
        ?float $minOrderValue,
        bool $minOrderValueProvided,
        ?string $info,
        bool $infoProvided
    ): void {
        $sets = [];
        $params = ['id' => $id];
        if ($chargeIndex !== null) {
            $sets[] = 'charge_index = :idx';
            $params['idx'] = $chargeIndex;
        }
        if ($title !== null) {
            $sets[] = 'title = :title';
            $params['title'] = $title;
        }
        if ($amount !== null) {
            $sets[] = 'amount = :amount';
            $params['amount'] = $amount;
        }
        if ($minOrderValueProvided) {
            $sets[] = 'min_order_value = :mov';
            $params['mov'] = $minOrderValue;
        }
        if ($infoProvided) {
            $sets[] = 'info = :info';
            $params['info'] = $info;
        }
        if ($sets === []) {
            return;
        }
        $sql = 'UPDATE cart_charges SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }

    public static function delete(string $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM cart_charges WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

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
