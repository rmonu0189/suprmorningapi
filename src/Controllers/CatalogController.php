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
            'cartItem' => $cartItem,
            'totalCartCount' => $totalCartCount,
        ]);
    }
}
