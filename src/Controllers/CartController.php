<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\AddressRepository;
use App\Repositories\CartChargeRepository;
use App\Repositories\CartRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\CouponRepository;
use App\Repositories\WarehouseRepository;
use App\Services\CouponService;

final class CartController
{
    /**
     * @param array<string, mixed>|null $address
     * @return array{warehouse_id:int,source:string}
     */
    private static function resolveChargeWarehouse(?array $address): array
    {
        if ($address === null) {
            return ['warehouse_id' => 0, 'source' => 'default'];
        }

        $lat = isset($address['latitude']) ? (float) $address['latitude'] : 0.0;
        $lng = isset($address['longitude']) ? (float) $address['longitude'] : 0.0;
        if ($lat == 0.0 && $lng == 0.0) {
            return ['warehouse_id' => 0, 'source' => 'default'];
        }

        $warehouses = WarehouseRepository::findAll();
        $nearestInRadiusId = null;
        $nearestInRadiusDistance = INF;
        foreach ($warehouses as $wh) {
            if (!is_array($wh)) continue;
            $enabled = isset($wh['status']) ? (bool) $wh['status'] : false;
            if (!$enabled) continue;
            $whLat = isset($wh['latitude']) ? (float) $wh['latitude'] : 0.0;
            $whLng = isset($wh['longitude']) ? (float) $wh['longitude'] : 0.0;
            $whRadius = isset($wh['radius_km']) ? (float) $wh['radius_km'] : 0.0;
            if ($whRadius <= 0.0) continue;
            $distance = self::haversineKm($lat, $lng, $whLat, $whLng);
            if ($distance <= $whRadius && $distance < $nearestInRadiusDistance) {
                $nearestInRadiusDistance = $distance;
                $nearestInRadiusId = (int) ($wh['id'] ?? 0);
            }
        }

        if ($nearestInRadiusId !== null && $nearestInRadiusId > 0) {
            return ['warehouse_id' => $nearestInRadiusId, 'source' => 'in_radius'];
        }

        $nearestId = WarehouseRepository::findNearestEnabledId($lat, $lng);
        if ($nearestId !== null && $nearestId > 0) {
            return ['warehouse_id' => $nearestId, 'source' => 'nearest_fallback'];
        }

        return ['warehouse_id' => 0, 'source' => 'default'];
    }

    /**
     * @param list<array<string,mixed>> $cartItems
     * @param list<array<string,mixed>> $charges
     * @return array<string,mixed>
     */
    private static function buildBillSummary(array $cartItems, array $charges, int $warehouseId, string $source, ?array $coupon): array
    {
        $itemsMrp = 0.0;
        $itemsPrice = 0.0;
        foreach ($cartItems as $item) {
            if (!is_array($item)) continue;
            $qty = isset($item['quantity']) ? (int) $item['quantity'] : 0;
            $unitMrp = isset($item['unit_mrp']) ? (float) $item['unit_mrp'] : 0.0;
            $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0.0;
            $itemsMrp += ($unitMrp * $qty);
            $itemsPrice += ($unitPrice * $qty);
        }

        $normalizedCharges = [];
        $chargesTotal = 0.0;
        foreach ($charges as $charge) {
            if (!is_array($charge)) continue;
            $amount = isset($charge['amount']) ? (float) $charge['amount'] : 0.0;
            $minOrderValue = isset($charge['min_order_value']) ? $charge['min_order_value'] : null;
            $threshold = $minOrderValue === null ? null : (float) $minOrderValue;
            $appliedAmount = $threshold !== null && $itemsPrice >= $threshold ? 0.0 : $amount;
            $chargesTotal += $appliedAmount;
            $normalizedCharges[] = [
                'id' => (string) ($charge['id'] ?? ''),
                'index' => isset($charge['index']) ? (int) $charge['index'] : 0,
                'title' => (string) ($charge['title'] ?? ''),
                'amount' => $amount,
                'min_order_value' => $threshold,
                'applied_amount' => $appliedAmount,
                'info' => isset($charge['info']) && $charge['info'] !== '' ? (string) $charge['info'] : null,
            ];
        }

        $couponDiscount = CouponService::discount($coupon, $itemsPrice);
        $payableItemsPrice = max(0.0, $itemsPrice - $couponDiscount);

        return [
            'warehouse_id' => $warehouseId,
            'warehouse_source' => $source,
            'items_mrp' => $itemsMrp,
            'items_price' => $itemsPrice,
            'coupon_discount' => $couponDiscount,
            'charges' => $normalizedCharges,
            'charges_total' => $chargesTotal,
            'grand_total_price' => $payableItemsPrice + $chargesTotal,
            'grand_total_mrp' => $itemsMrp + $chargesTotal,
        ];
    }

    /** @param array<string,mixed> $cart */
    private static function couponForCart(array $cart): ?array
    {
        $code = isset($cart['coupon_code']) ? trim((string) $cart['coupon_code']) : '';
        return $code === '' ? null : CouponRepository::findByCode(strtoupper($code));
    }

    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $r * $c;
    }

    public function charges(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        $userId = (string) ($claims['sub'] ?? '');
        if ($userId === '') {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $address = AddressRepository::findFirstByUserId($userId);
        $resolved = self::resolveChargeWarehouse($address);
        $warehouseId = $resolved['warehouse_id'];

        Response::json(['charges' => CartChargeRepository::findAllOrdered($warehouseId)]);
    }

    public function show(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        if ($userId === '') {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $cart = CartRepository::getOrCreateActiveCartWithItems($userId, Uuid::v4());
        $address = AddressRepository::findFirstByUserId($userId);
        $resolved = self::resolveChargeWarehouse($address);
        $charges = CartChargeRepository::findAllOrdered($resolved['warehouse_id']);
        $cartItems = isset($cart['cart_items']) && is_array($cart['cart_items']) ? $cart['cart_items'] : [];
        $cart['bill_summary'] = self::buildBillSummary($cartItems, $charges, $resolved['warehouse_id'], $resolved['source'], self::couponForCart($cart));
        Response::json(['cart' => $cart]);
    }

    public function addItem(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $variantId = trim((string) ($body['variant_id'] ?? ''));
        if ($variantId === '' || !Uuid::isValid($variantId)) {
            throw new ValidationException('Invalid variant_id', ['variant_id' => 'Must be a valid UUID.']);
        }

        $variant = CatalogRepository::findVariantDetail($variantId);
        if ($variant === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $cart = CartRepository::getOrCreateActiveCartWithItems($userId, Uuid::v4());
        $cartId = $cart['id'];
        $existing = CartRepository::findCartItemByCartAndVariant($cartId, $variantId);
        $price = (float) $variant['price'];
        $mrp = (float) $variant['mrp'];

        if ($existing !== null) {
            $newQty = $existing['quantity'] + 1;
            CartRepository::updateCartItemQty($existing['id'], $userId, $newQty, $existing['unit_price'], $existing['unit_mrp']);
        } else {
            CartRepository::insertCartItem(Uuid::v4(), $cartId, $userId, $variantId, 1, $price, $mrp);
        }

        $full = CartRepository::getActiveCartWithItems($userId);
        Response::json(['cart' => $full]);
    }

    public function decreaseItem(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $variantId = trim((string) ($body['variant_id'] ?? ''));
        if ($variantId === '' || !Uuid::isValid($variantId)) {
            throw new ValidationException('Invalid variant_id', ['variant_id' => 'Must be a valid UUID.']);
        }

        $cart = CartRepository::findActiveCart($userId);
        if ($cart === null) {
            Response::json(['quantity' => 0]);
            return;
        }

        $item = CartRepository::findCartItemByCartAndVariant($cart['id'], $variantId);
        if ($item === null) {
            Response::json(['quantity' => 0]);
            return;
        }

        if ($item['quantity'] > 1) {
            CartRepository::updateCartItemQty(
                $item['id'],
                $userId,
                $item['quantity'] - 1,
                $item['unit_price'],
                $item['unit_mrp']
            );
            Response::json(['quantity' => $item['quantity'] - 1]);
            return;
        }

        CartRepository::deleteCartItem($item['id'], $userId);
        Response::json(['quantity' => 0]);
    }

    public function lock(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        CartRepository::lockActiveCart($userId);
        Response::json(['ok' => true]);
    }

    public function applyCoupon(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $code = strtoupper(trim((string) ($body['code'] ?? '')));
        if ($code === '') {
            throw new ValidationException('Coupon code is required', ['code' => 'Required.']);
        }

        $cart = CartRepository::getOrCreateActiveCartWithItems($userId, Uuid::v4());
        $cartItems = isset($cart['cart_items']) && is_array($cart['cart_items']) ? $cart['cart_items'] : [];
        $itemsPrice = 0.0;
        foreach ($cartItems as $item) {
            if (!is_array($item)) continue;
            $itemsPrice += ((float) ($item['unit_price'] ?? 0)) * ((int) ($item['quantity'] ?? 0));
        }
        if ($itemsPrice <= 0.0) {
            throw new ValidationException('Add items before applying coupon', ['cart' => 'Cart is empty.']);
        }

        $coupon = CouponRepository::findByCode($code);
        $validation = CouponService::validate($coupon, $itemsPrice);
        if ($validation['ok'] !== true) {
            throw new ValidationException($validation['message'] ?? 'Coupon cannot be applied.', ['code' => $validation['message'] ?? 'Invalid coupon.']);
        }

        CartRepository::setActiveCartCoupon(
            $userId,
            (string) $coupon['code'],
            (string) $coupon['discount_type'],
            (string) $coupon['discount_value']
        );

        Response::json(['ok' => true, 'coupon_code' => (string) $coupon['code'], 'discount' => CouponService::discount($coupon, $itemsPrice)]);
    }

    public function removeCoupon(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        CartRepository::setActiveCartCoupon($userId, null, null, null);
        Response::json(['ok' => true]);
    }
}
