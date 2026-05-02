<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

final class CouponService
{
    /**
     * @param array<string,mixed>|null $coupon
     * @return array{ok: bool, message: string|null}
     */
    public static function validate(?array $coupon, float $itemsPrice, ?DateTimeImmutable $today = null): array
    {
        if ($coupon === null) {
            return ['ok' => false, 'message' => 'Coupon code is invalid.'];
        }

        if (($coupon['is_active'] ?? false) !== true) {
            return ['ok' => false, 'message' => 'This coupon is not active.'];
        }

        $todayYmd = ($today ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $startsAt = (string) ($coupon['starts_at'] ?? '');
        $expiresAt = $coupon['expires_at'] ?? null;

        if ($startsAt !== '' && $startsAt > $todayYmd) {
            return ['ok' => false, 'message' => 'This coupon is not active yet.'];
        }
        if (is_string($expiresAt) && $expiresAt !== '' && $expiresAt < $todayYmd) {
            return ['ok' => false, 'message' => 'This coupon has expired.'];
        }

        $minCartValue = max(0.0, (float) ($coupon['min_cart_value'] ?? 0));
        if ($itemsPrice + 0.005 < $minCartValue) {
            return [
                'ok' => false,
                'message' => 'Add items worth Rs. ' . self::formatAmount($minCartValue) . ' to use this coupon.',
            ];
        }

        if (!in_array((string) ($coupon['discount_type'] ?? ''), ['fixed', 'percentage'], true)) {
            return ['ok' => false, 'message' => 'This coupon is not configured correctly.'];
        }

        if ((float) ($coupon['discount_value'] ?? 0) <= 0.0) {
            return ['ok' => false, 'message' => 'This coupon is not configured correctly.'];
        }

        return ['ok' => true, 'message' => null];
    }

    /** @param array<string,mixed>|null $coupon */
    public static function discount(?array $coupon, float $itemsPrice): float
    {
        if (self::validate($coupon, $itemsPrice)['ok'] !== true) {
            return 0.0;
        }

        $type = (string) ($coupon['discount_type'] ?? '');
        $value = max(0.0, (float) ($coupon['discount_value'] ?? 0));
        $discount = $type === 'percentage' ? ($itemsPrice * $value / 100.0) : $value;

        $maxDiscount = $coupon['max_discount'] ?? null;
        if ($maxDiscount !== null && (float) $maxDiscount > 0.0) {
            $discount = min($discount, (float) $maxDiscount);
        }

        return round(min($discount, max(0.0, $itemsPrice)), 2);
    }

    private static function formatAmount(float $amount): string
    {
        $rounded = round($amount, 2);
        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }
}
