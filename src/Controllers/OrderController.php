<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\CartRepository;
use App\Repositories\OrderRepository;
use App\Services\OrderPlacementService;

final class OrderController
{
    public function place(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $cartId = trim((string) ($body['cart_id'] ?? ''));
        $addressId = trim((string) ($body['address_id'] ?? ''));
        if ($cartId === '' || !Uuid::isValid($cartId)) {
            throw new ValidationException('Invalid cart_id', ['cart_id' => 'Must be a valid UUID.']);
        }
        if ($addressId === '' || !Uuid::isValid($addressId)) {
            throw new ValidationException('Invalid address_id', ['address_id' => 'Must be a valid UUID.']);
        }

        $payload = OrderPlacementService::place($userId, $cartId, $addressId);
        Response::json($payload, 201);
    }

    public function index(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        $page = max(0, (int) ($request->query('page') ?? '0'));
        $limit = min(50, max(1, (int) ($request->query('limit') ?? '10')));
        $offset = $page * $limit;
        $orders = OrderRepository::findForUserExcludingPayment($userId, $offset, $limit);
        Response::json(['orders' => $orders]);
    }

    public function byId(Request $request): void
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
        $order = OrderRepository::findByIdForUser($id, $userId);
        if ($order === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        Response::json(['order' => $order]);
    }

    public function byGateway(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        $go = trim((string) ($request->query('gateway_order_id') ?? ''));
        if ($go === '') {
            Response::json(['error' => 'gateway_order_id required'], 422);
            return;
        }
        $order = OrderRepository::findByGatewayForUser($go, $userId);
        if ($order === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        Response::json(['order' => $order]);
    }

    public function paymentStatus(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $go = trim((string) ($request->query('gateway_order_id') ?? ''));
        if ($go === '') {
            Response::json(['error' => 'gateway_order_id required'], 422);
            return;
        }
        $status = OrderRepository::findPaymentStatusByGatewayOrderId($go);
        Response::json(['payment_status' => $status]);
    }

    public function reorder(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $orderId = trim((string) ($body['order_id'] ?? ''));
        $replaceCart = false;
        if (array_key_exists('replace_cart', $body)) {
            $raw = $body['replace_cart'];
            $replaceCart = $raw === true || $raw === 1 || $raw === '1' || (is_string($raw) && strtolower($raw) === 'true');
        }
        if ($orderId === '' || !Uuid::isValid($orderId)) {
            throw new ValidationException('Invalid order_id', ['order_id' => 'Must be a valid UUID.']);
        }

        $order = OrderRepository::findByIdForUser($orderId, $userId);
        if ($order === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $warehouseId = OrderRepository::findWarehouseIdForOrderAndUser($orderId, $userId) ?? 0;
        $rows = OrderRepository::findReorderEvaluationRows($orderId, $warehouseId);
        if ($replaceCart) {
            CartRepository::lockActiveCart($userId);
        }
        $cart = CartRepository::getOrCreateActiveCartWithItems($userId, Uuid::v4());
        $cartId = (string) $cart['id'];

        $requestedItems = count($rows);
        $addedItems = 0;
        $addedQuantity = 0;
        $skipped = [];

        foreach ($rows as $row) {
            $sku = isset($row['sku']) ? (string) $row['sku'] : '';
            $requestedQty = isset($row['ordered_quantity']) ? (int) $row['ordered_quantity'] : 0;
            if ($requestedQty <= 0) {
                $skipped[] = ['sku' => $sku, 'reason' => 'invalid_quantity', 'requested_quantity' => $requestedQty, 'available_quantity' => 0];
                continue;
            }
            $variantId = isset($row['variant_id']) && $row['variant_id'] !== null && $row['variant_id'] !== '' ? (string) $row['variant_id'] : '';
            if ($variantId === '') {
                $skipped[] = ['sku' => $sku, 'reason' => 'variant_not_found', 'requested_quantity' => $requestedQty, 'available_quantity' => 0];
                continue;
            }
            $variantEnabled = isset($row['variant_status']) && (int) $row['variant_status'] === 1;
            $productEnabled = isset($row['product_status']) && (int) $row['product_status'] === 1;
            $brandEnabled = isset($row['brand_status']) && (int) $row['brand_status'] === 1;
            if (!$variantEnabled || !$productEnabled || !$brandEnabled) {
                $skipped[] = ['sku' => $sku, 'reason' => 'item_disabled', 'requested_quantity' => $requestedQty, 'available_quantity' => 0];
                continue;
            }

            $invQty = isset($row['inv_quantity']) && $row['inv_quantity'] !== null ? (int) $row['inv_quantity'] : 0;
            $invReserved = isset($row['inv_reserved']) && $row['inv_reserved'] !== null ? (int) $row['inv_reserved'] : 0;
            $availableQty = max(0, $invQty - $invReserved);
            if ($availableQty <= 0) {
                $skipped[] = ['sku' => $sku, 'reason' => 'out_of_stock', 'requested_quantity' => $requestedQty, 'available_quantity' => 0];
                continue;
            }

            $qtyToAdd = min($requestedQty, $availableQty);
            if ($qtyToAdd <= 0) {
                $skipped[] = ['sku' => $sku, 'reason' => 'insufficient_stock', 'requested_quantity' => $requestedQty, 'available_quantity' => $availableQty];
                continue;
            }

            $unitPrice = isset($row['current_price']) ? (float) $row['current_price'] : 0.0;
            $unitMrp = isset($row['current_mrp']) ? (float) $row['current_mrp'] : 0.0;
            $existing = CartRepository::findCartItemByCartAndVariant($cartId, $variantId);
            if ($existing !== null) {
                $nextQty = (int) $existing['quantity'] + $qtyToAdd;
                CartRepository::updateCartItemQty((string) $existing['id'], $userId, $nextQty, $unitPrice, $unitMrp);
            } else {
                CartRepository::insertCartItem(Uuid::v4(), $cartId, $userId, $variantId, $qtyToAdd, $unitPrice, $unitMrp);
            }
            $addedItems++;
            $addedQuantity += $qtyToAdd;
            if ($qtyToAdd < $requestedQty) {
                $skipped[] = ['sku' => $sku, 'reason' => 'partially_added', 'requested_quantity' => $requestedQty, 'available_quantity' => $availableQty];
            }
        }

        $full = CartRepository::getActiveCartWithItems($userId);
        Response::json([
            'cart' => $full,
            'summary' => [
                'requested_items' => $requestedItems,
                'added_items' => $addedItems,
                'added_quantity' => $addedQuantity,
                'skipped_items' => count($skipped),
                'skipped' => $skipped,
            ],
        ]);
    }
}
