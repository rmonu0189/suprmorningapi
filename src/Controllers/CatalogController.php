<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Middleware\AuthMiddleware;
use App\Repositories\CartRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\LoveRepository;

final class CatalogController
{
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
}
