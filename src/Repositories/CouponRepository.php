<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class CouponRepository
{
    /**
     * @return list<array{
     *   id: string,
     *   created_at: string,
     *   code: string,
     *   discount_type: string,
     *   discount_value: string,
     *   min_cart_value: string,
     *   starts_at: string,
     *   expires_at: string|null,
     *   is_active: bool,
     *   max_discount: string|null,
     *   usage_limit: int|null
     * }>
     */
    public static function findAll(): array
    {
        $stmt = Database::connection()->query(
            'SELECT id, created_at, code, discount_type, discount_value, min_cart_value, starts_at, expires_at, is_active, max_discount, usage_limit
             FROM coupons
             ORDER BY created_at DESC, id DESC'
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

    /** @return array{id: string, created_at: string, code: string, discount_type: string, discount_value: string, min_cart_value: string, starts_at: string, expires_at: string|null, is_active: bool, max_discount: string|null, usage_limit: int|null}|null */
    public static function findById(string $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, created_at, code, discount_type, discount_value, min_cart_value, starts_at, expires_at, is_active, max_discount, usage_limit
             FROM coupons WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return self::normalizeRow($row);
    }

    public static function findByCode(string $code): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, created_at, code, discount_type, discount_value, min_cart_value, starts_at, expires_at, is_active, max_discount, usage_limit
             FROM coupons WHERE code = :code LIMIT 1'
        );
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return self::normalizeRow($row);
    }

    public static function insert(
        string $id,
        string $code,
        string $discountType,
        string $discountValue,
        string $minCartValue,
        string $startsAt,
        ?string $expiresAt,
        bool $isActive,
        ?string $maxDiscount,
        ?int $usageLimit
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO coupons (id, code, discount_type, discount_value, min_cart_value, starts_at, expires_at, is_active, max_discount, usage_limit)
             VALUES (:id, :code, :discount_type, :discount_value, :min_cart_value, :starts_at, :expires_at, :is_active, :max_discount, :usage_limit)'
        );
        $stmt->execute([
            'id' => $id,
            'code' => $code,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'min_cart_value' => $minCartValue,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'is_active' => $isActive ? 1 : 0,
            'max_discount' => $maxDiscount,
            'usage_limit' => $usageLimit,
        ]);
    }

    public static function update(
        string $id,
        ?string $code,
        ?string $discountType,
        ?string $discountValue,
        ?string $minCartValue,
        ?string $startsAt,
        bool $expiresAtProvided,
        ?string $expiresAt,
        ?bool $isActive,
        bool $maxDiscountProvided,
        ?string $maxDiscount,
        bool $usageLimitProvided,
        ?int $usageLimit
    ): void {
        $sets = [];
        $params = ['id' => $id];

        if ($code !== null) {
            $sets[] = 'code = :code';
            $params['code'] = $code;
        }
        if ($discountType !== null) {
            $sets[] = 'discount_type = :discount_type';
            $params['discount_type'] = $discountType;
        }
        if ($discountValue !== null) {
            $sets[] = 'discount_value = :discount_value';
            $params['discount_value'] = $discountValue;
        }
        if ($minCartValue !== null) {
            $sets[] = 'min_cart_value = :min_cart_value';
            $params['min_cart_value'] = $minCartValue;
        }
        if ($startsAt !== null) {
            $sets[] = 'starts_at = :starts_at';
            $params['starts_at'] = $startsAt;
        }
        if ($expiresAtProvided) {
            $sets[] = 'expires_at = :expires_at';
            $params['expires_at'] = $expiresAt;
        }
        if ($isActive !== null) {
            $sets[] = 'is_active = :is_active';
            $params['is_active'] = $isActive ? 1 : 0;
        }
        if ($maxDiscountProvided) {
            $sets[] = 'max_discount = :max_discount';
            $params['max_discount'] = $maxDiscount;
        }
        if ($usageLimitProvided) {
            $sets[] = 'usage_limit = :usage_limit';
            $params['usage_limit'] = $usageLimit;
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE coupons SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }

    public static function delete(string $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM coupons WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, created_at: string, code: string, discount_type: string, discount_value: string, min_cart_value: string, starts_at: string, expires_at: string|null, is_active: bool, max_discount: string|null, usage_limit: int|null}
     */
    private static function normalizeRow(array $row): array
    {
        $expires = $row['expires_at'] ?? null;
        $maxDiscount = $row['max_discount'] ?? null;
        $usageLimit = $row['usage_limit'] ?? null;

        return [
            'id' => (string) $row['id'],
            'created_at' => (string) $row['created_at'],
            'code' => (string) $row['code'],
            'discount_type' => (string) $row['discount_type'],
            'discount_value' => (string) $row['discount_value'],
            'min_cart_value' => (string) $row['min_cart_value'],
            'starts_at' => (string) $row['starts_at'],
            'expires_at' => $expires === null || $expires === '' ? null : (string) $expires,
            'is_active' => (bool) (int) ($row['is_active'] ?? 0),
            'max_discount' => $maxDiscount === null || $maxDiscount === '' ? null : (string) $maxDiscount,
            'usage_limit' => $usageLimit === null ? null : (int) $usageLimit,
        ];
    }
}

