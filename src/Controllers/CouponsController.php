<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\CouponRepository;
use PDOException;

final class CouponsController
{
    /** Admin-only list, or one coupon when query param id is a valid UUID. */
    public function index(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        $id = $request->query('id');
        if ($id !== null && trim($id) !== '') {
            $id = trim($id);
            if (!Uuid::isValid($id)) {
                Response::json(['error' => 'Invalid id', 'errors' => ['id' => 'Must be a valid UUID']], 422);
                return;
            }

            $coupon = CouponRepository::findById($id);
            if ($coupon === null) {
                Response::json(['error' => 'Not Found'], 404);
                return;
            }

            Response::json(['coupon' => $coupon]);
            return;
        }

        Response::json(['coupons' => CouponRepository::findAll()]);
    }

    public function create(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $code = strtoupper(trim((string) ($body['code'] ?? '')));
        if ($code === '') {
            throw new ValidationException('Invalid code', ['code' => 'Required.']);
        }
        if (strlen($code) > 128) {
            throw new ValidationException('Invalid code', ['code' => 'At most 128 characters.']);
        }
        if (CouponRepository::findByCode($code) !== null) {
            throw new ValidationException('Code already exists', ['code' => 'This code is already in use.']);
        }

        $discountType = trim((string) ($body['discount_type'] ?? ''));
        if ($discountType !== 'fixed' && $discountType !== 'percentage') {
            throw new ValidationException('Invalid discount_type', [
                'discount_type' => 'Must be "fixed" or "percentage".',
            ]);
        }

        $discountValue = self::requireDecimalString($body['discount_value'] ?? null, 'discount_value');
        $minCartValue = self::requireDecimalString($body['min_cart_value'] ?? null, 'min_cart_value');

        $startsAt = trim((string) ($body['starts_at'] ?? ''));
        if (!self::isDate($startsAt)) {
            throw new ValidationException('Invalid starts_at', ['starts_at' => 'Use YYYY-MM-DD.']);
        }

        $expiresAt = null;
        if (array_key_exists('expires_at', $body)) {
            $expiresAt = self::nullableDate($body['expires_at'], 'expires_at');
        }

        $isActive = true;
        if (array_key_exists('is_active', $body)) {
            $isActive = self::parseBoolLike($body['is_active'], 'is_active');
        }

        $maxDiscount = null;
        if (array_key_exists('max_discount', $body)) {
            $maxDiscount = self::nullableDecimalString($body['max_discount'], 'max_discount');
        }

        $usageLimit = null;
        if (array_key_exists('usage_limit', $body)) {
            $usageLimit = self::nullableInt($body['usage_limit'], 'usage_limit');
        }

        $id = Uuid::v4();
        try {
            CouponRepository::insert(
                $id,
                $code,
                $discountType,
                $discountValue,
                $minCartValue,
                $startsAt,
                $expiresAt,
                $isActive,
                $maxDiscount,
                $usageLimit
            );
        } catch (PDOException $e) {
            throw new HttpException('Could not create coupon', 500);
        }

        $coupon = CouponRepository::findById($id);
        if ($coupon === null) {
            throw new HttpException('Could not create coupon', 500);
        }
        Response::json(['coupon' => $coupon], 201);
    }

    public function update(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $id = trim((string) ($body['id'] ?? ''));
        if ($id === '' || !Uuid::isValid($id)) {
            throw new ValidationException('Invalid id', ['id' => 'A valid UUID is required.']);
        }
        if (CouponRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $code = null;
        if (array_key_exists('code', $body)) {
            $c = strtoupper(trim((string) $body['code']));
            if ($c === '') {
                throw new ValidationException('Invalid code', ['code' => 'Cannot be empty.']);
            }
            if (strlen($c) > 128) {
                throw new ValidationException('Invalid code', ['code' => 'At most 128 characters.']);
            }
            $existing = CouponRepository::findByCode($c);
            if ($existing !== null && $existing['id'] !== $id) {
                throw new ValidationException('Code already exists', ['code' => 'This code is already in use.']);
            }
            $code = $c;
        }

        $discountType = null;
        if (array_key_exists('discount_type', $body)) {
            $dt = trim((string) $body['discount_type']);
            if ($dt !== 'fixed' && $dt !== 'percentage') {
                throw new ValidationException('Invalid discount_type', [
                    'discount_type' => 'Must be "fixed" or "percentage".',
                ]);
            }
            $discountType = $dt;
        }

        $discountValue = null;
        if (array_key_exists('discount_value', $body)) {
            $discountValue = self::requireDecimalString($body['discount_value'], 'discount_value');
        }

        $minCartValue = null;
        if (array_key_exists('min_cart_value', $body)) {
            $minCartValue = self::requireDecimalString($body['min_cart_value'], 'min_cart_value');
        }

        $startsAt = null;
        if (array_key_exists('starts_at', $body)) {
            $s = trim((string) $body['starts_at']);
            if (!self::isDate($s)) {
                throw new ValidationException('Invalid starts_at', ['starts_at' => 'Use YYYY-MM-DD.']);
            }
            $startsAt = $s;
        }

        $expiresAtProvided = array_key_exists('expires_at', $body);
        $expiresAt = null;
        if ($expiresAtProvided) {
            $expiresAt = self::nullableDate($body['expires_at'], 'expires_at');
        }

        $isActive = null;
        if (array_key_exists('is_active', $body)) {
            $isActive = self::parseBoolLike($body['is_active'], 'is_active');
        }

        $maxDiscountProvided = array_key_exists('max_discount', $body);
        $maxDiscount = null;
        if ($maxDiscountProvided) {
            $maxDiscount = self::nullableDecimalString($body['max_discount'], 'max_discount');
        }

        $usageLimitProvided = array_key_exists('usage_limit', $body);
        $usageLimit = null;
        if ($usageLimitProvided) {
            $usageLimit = self::nullableInt($body['usage_limit'], 'usage_limit');
        }

        if (
            $code === null &&
            $discountType === null &&
            $discountValue === null &&
            $minCartValue === null &&
            $startsAt === null &&
            !$expiresAtProvided &&
            $isActive === null &&
            !$maxDiscountProvided &&
            !$usageLimitProvided
        ) {
            throw new ValidationException('Nothing to update', [
                'body' => 'Provide at least one of: code, discount_type, discount_value, min_cart_value, starts_at, expires_at, is_active, max_discount, usage_limit.',
            ]);
        }

        try {
            CouponRepository::update(
                $id,
                $code,
                $discountType,
                $discountValue,
                $minCartValue,
                $startsAt,
                $expiresAtProvided,
                $expiresAt,
                $isActive,
                $maxDiscountProvided,
                $maxDiscount,
                $usageLimitProvided,
                $usageLimit
            );
        } catch (PDOException $e) {
            throw new HttpException('Could not update coupon', 500);
        }

        $coupon = CouponRepository::findById($id);
        if ($coupon === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        Response::json(['coupon' => $coupon]);
    }

    public function delete(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        $id = trim((string) ($request->query('id') ?? ''));
        if ($id === '') {
            $body = $request->json();
            $id = trim((string) ($body['id'] ?? ''));
        }
        if ($id === '' || !Uuid::isValid($id)) {
            Response::json([
                'error' => 'Invalid id',
                'errors' => ['id' => 'Provide a valid UUID via ?id= or JSON body.'],
            ], 422);
            return;
        }

        if (CouponRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        if (!CouponRepository::delete($id)) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['ok' => true]);
    }

    private static function isDate(string $s): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
    }

    private static function nullableDate(mixed $v, string $key): ?string
    {
        if ($v === null) return null;
        if (!is_string($v)) {
            throw new ValidationException('Invalid ' . $key, [$key => 'Must be a string (YYYY-MM-DD) or null.']);
        }
        $t = trim($v);
        if ($t === '') return null;
        if (!self::isDate($t)) {
            throw new ValidationException('Invalid ' . $key, [$key => 'Use YYYY-MM-DD.']);
        }
        return $t;
    }

    private static function requireDecimalString(mixed $v, string $key): string
    {
        if ($v === null || $v === '') {
            throw new ValidationException('Invalid ' . $key, [$key => 'Required.']);
        }
        $s = trim((string) $v);
        if ($s === '' || !is_numeric($s)) {
            throw new ValidationException('Invalid ' . $key, [$key => 'Must be a number.']);
        }
        return $s;
    }

    private static function nullableDecimalString(mixed $v, string $key): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        if ($s === '') return null;
        if (!is_numeric($s)) {
            throw new ValidationException('Invalid ' . $key, [$key => 'Must be a number or empty.']);
        }
        return $s;
    }

    private static function nullableInt(mixed $v, string $key): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_int($v)) return $v;
        if (!is_string($v) && !is_float($v)) {
            throw new ValidationException('Invalid ' . $key, [$key => 'Must be an integer or empty.']);
        }
        $n = (int) $v;
        return $n;
    }

    private static function parseBoolLike(mixed $v, string $key): bool
    {
        if (is_bool($v)) return $v;
        if ($v === 1 || $v === 0) return $v === 1;
        throw new ValidationException('Invalid ' . $key, [$key => 'Must be a boolean.']);
    }
}

