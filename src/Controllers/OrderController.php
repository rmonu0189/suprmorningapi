<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
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
}
