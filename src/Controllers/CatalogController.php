<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Middleware\AuthMiddleware;
use App\Repositories\AddressRepository;
use App\Repositories\CartRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\LoveRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PageRepository;
use App\Repositories\WarehouseRepository;
use App\Repositories\WalletRepository;
use App\Services\CommerceGatewayPaymentService;

final class CatalogController
{
    /** Home aggregate: CMS cards + love ids + active orders + cart summary + wallet */
    public function home(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        $pageName = trim((string) ($request->query('page_name') ?? 'Home'));
        if ($pageName === '') {
            $pageName = 'Home';
        }

        $cart = CartRepository::getActiveCartWithItems($userId);
        $cartItems = is_array($cart['cart_items'] ?? null) ? $cart['cart_items'] : [];
        $cartCount = 0;
        $variantQuantities = [];
        foreach ($cartItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $quantity = (int) ($item['quantity'] ?? 0);
            $variantId = (string) ($item['variant_id'] ?? '');
            $cartCount += $quantity;
            if ($variantId !== '') {
                $variantQuantities[$variantId] = $quantity;
            }
        }

        CommerceGatewayPaymentService::expireStaleWalletHolds($userId);

        Response::json([
            'pages' => PageRepository::findAll($pageName),
            'lovedVariantIds' => LoveRepository::variantIdsForUser($userId),
            'activeOrders' => OrderRepository::findActiveHomeOrdersForUser($userId, 20),
            'cartSummary' => [
                'count' => $cartCount,
                'variantQuantities' => $variantQuantities,
            ],
            'serviceability' => self::serviceabilityForUser($userId),
            'wallet' => WalletRepository::findByUserId($userId),
        ]);
    }

    /** PDP aggregate: variant + siblings + love + cart line + cart count */
    public function variantDetail(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        $id = trim((string) ($request->query('id') ?? ''));
        if ($id === '' || !Uuid::isValid($id)) {
            Response::json(['error' => 'Invalid id'], 422);
            return;
        }

        $variant = CatalogRepository::findVariantDetail($id);
        if ($variant === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $productId = (string) $variant['product_id'];
        $siblings = CatalogRepository::findVariantsByProductId($productId);

        $similarVariants = [];
        $subcategoryId = $variant['products']['subcategory_id'] ?? null;
        if (is_string($subcategoryId) && $subcategoryId !== '' && Uuid::isValid($subcategoryId)) {
            $similarVariants = CatalogRepository::findSimilarVariantsBySubcategory($subcategoryId, $id, 10);
        }

        $loveId = LoveRepository::findId($userId, $id);
        $loveWrap = $loveId !== null ? ['id' => $loveId, 'user_id' => $userId, 'variant_id' => $id] : null;
        $lovedVariantIds = LoveRepository::variantIdsForUser($userId);

        $variantOut = $variant;
        $variantOut['loves'] = $loveWrap;

        $lovesList = $loveWrap !== null ? [['id' => $loveWrap['id']]] : [];

        $cart = CartRepository::findActiveCart($userId);
        $cartItem = null;
        $totalCartCount = 0;
        if ($cart !== null) {
            $items = CartRepository::getActiveCartWithItems($userId);
            if ($items !== null && isset($items['cart_items']) && is_array($items['cart_items'])) {
                foreach ($items['cart_items'] as $ci) {
                    if (!is_array($ci)) {
                        continue;
                    }
                    $totalCartCount += (int) ($ci['quantity'] ?? 0);
                    if (($ci['variant_id'] ?? '') === $id) {
                        $cartItem = $ci;
                    }
                }
            }
        }

        Response::json([
            'variant' => $variantOut,
            'loves' => $lovesList,
            'lovedVariantIds' => $lovedVariantIds,
            'allVariants' => $siblings,
            'similarVariants' => $similarVariants,
            'cartItem' => $cartItem,
            'totalCartCount' => $totalCartCount,
        ]);
    }

    /** PLP aggregate: active variants filtered by brand/category/subcategory/product */
    public function products(Request $request): void
    {
        if (AuthMiddleware::requireAuth($request) === null) {
            return;
        }
        $filters = [
            'brand_id' => trim((string) ($request->query('brand_id') ?? $request->query('brandId') ?? '')),
            'category_id' => trim((string) ($request->query('category_id') ?? $request->query('categoryId') ?? '')),
            'subcategory_id' => trim((string) ($request->query('subcategory_id') ?? $request->query('subcategoryId') ?? $request->query('subCategoryId') ?? '')),
            'product_id' => trim((string) ($request->query('product_id') ?? $request->query('productId') ?? '')),
            'tags' => self::parseTagsFilter((string) ($request->query('tags') ?? $request->query('tag') ?? '')),
        ];

        // Support target-driven routing where query contains filter key and id carries value.
        $queryKey = trim((string) ($request->query('query') ?? ''));
        $queryValue = trim((string) ($request->query('id') ?? ''));
        if ($queryKey !== '' && $queryValue !== '') {
            if ($queryKey === 'brand_id' || $queryKey === 'brandId') {
                $filters['brand_id'] = $queryValue;
            } elseif ($queryKey === 'category_id' || $queryKey === 'categoryId') {
                $filters['category_id'] = $queryValue;
            } elseif ($queryKey === 'subcategory_id' || $queryKey === 'subcategoryId' || $queryKey === 'subCategoryId') {
                $filters['subcategory_id'] = $queryValue;
            } elseif ($queryKey === 'product_id' || $queryKey === 'productId') {
                $filters['product_id'] = $queryValue;
            } elseif ($queryKey === 'tags' || $queryKey === 'tag') {
                $filters['tags'] = self::parseTagsFilter($queryValue);
            }
        }

        $hasAtLeastOneFilter = false;
        foreach ($filters as $value) {
            if (is_array($value)) {
                if ($value !== []) {
                    $hasAtLeastOneFilter = true;
                    break;
                }
                continue;
            }
            if ($value !== '') {
                $hasAtLeastOneFilter = true;
                break;
            }
        }
        if (!$hasAtLeastOneFilter) {
            Response::json(['variants' => []]);
            return;
        }

        foreach ($filters as $value) {
            if (is_array($value)) {
                continue;
            }
            if ($value !== '' && !Uuid::isValid($value)) {
                Response::json(['variants' => []]);
                return;
            }
        }

        $page = max(1, (int) ($request->query('page') ?? 1));
        $limit = max(1, min(100, (int) ($request->query('limit') ?? 30)));

        Response::json([
            'variants' => CatalogRepository::findListingVariants($filters, $page, $limit),
        ]);
    }

    /** @return list<string> */
    private static function parseTagsFilter(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[,\s]+/', $raw) ?: [];
        $out = [];
        $seen = [];
        foreach ($parts as $part) {
            if (!is_string($part)) {
                continue;
            }
            $tag = self::normalizeTag($part);
            if ($tag === '' || isset($seen[$tag])) {
                continue;
            }
            $seen[$tag] = true;
            $out[] = $tag;
        }

        return $out;
    }

    private static function normalizeTag(string $raw): string
    {
        $t = trim($raw);
        while (str_starts_with($t, '#')) {
            $t = ltrim($t, '#');
        }
        $t = strtoupper(trim($t));
        $t = preg_replace('/[^A-Z0-9_-]/', '', $t) ?? '';

        return $t;
    }

    /** @return array{has_address: bool, serviceable: bool, nearest_warehouse: array{id:int,name:string,radius_km:float,distance_km:float}|null} */
    private static function serviceabilityForUser(string $userId): array
    {
        $address = AddressRepository::findFirstByUserId($userId);
        if ($address === null) {
            return [
                'has_address' => false,
                'serviceable' => false,
                'nearest_warehouse' => null,
            ];
        }

        $lat = isset($address['latitude']) ? (float) $address['latitude'] : 0.0;
        $lng = isset($address['longitude']) ? (float) $address['longitude'] : 0.0;

        $nearestId = null;
        if ($lat !== 0.0 || $lng !== 0.0) {
            $nearestId = WarehouseRepository::findNearestEnabledId($lat, $lng);
        }

        if ($nearestId === null) {
            return [
                'has_address' => true,
                'serviceable' => false,
                'nearest_warehouse' => null,
            ];
        }

        $wh = WarehouseRepository::findById($nearestId);
        if ($wh === null) {
            return [
                'has_address' => true,
                'serviceable' => false,
                'nearest_warehouse' => null,
            ];
        }

        $whLat = isset($wh['latitude']) ? (float) $wh['latitude'] : 0.0;
        $whLng = isset($wh['longitude']) ? (float) $wh['longitude'] : 0.0;
        $radiusKm = isset($wh['radius_km']) ? (float) $wh['radius_km'] : 0.0;
        $distanceKm = self::haversineKm($lat, $lng, $whLat, $whLng);

        return [
            'has_address' => true,
            'serviceable' => $radiusKm > 0 && $distanceKm <= $radiusKm,
            'nearest_warehouse' => [
                'id' => (int) ($wh['id'] ?? 0),
                'name' => (string) ($wh['name'] ?? ''),
                'radius_km' => $radiusKm,
                'distance_km' => $distanceKm,
            ],
        ];
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
}
