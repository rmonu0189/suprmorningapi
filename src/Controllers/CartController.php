<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\CartChargeRepository;
use App\Repositories\CartRepository;
use App\Repositories\CatalogRepository;

final class CartController
{
    public function charges(Request $request): void
    {
        Response::json(['charges' => CartChargeRepository::findAllOrdered()]);
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
}
