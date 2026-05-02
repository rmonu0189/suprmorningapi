<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\OrderSupportRepository;

final class OrderSupportController
{
    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : strlen($value);
    }

    public function byOrder(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        $orderId = trim((string) ($request->query('order_id') ?? ''));
        if ($orderId === '') {
            Response::json(['queries' => OrderSupportRepository::findAllForUser($userId)]);
            return;
        }
        if ($orderId === '' || !Uuid::isValid($orderId)) {
            Response::json(['error' => 'Invalid order_id'], 422);
            return;
        }
        if (!OrderSupportRepository::findOrderOwnerForUser($orderId, $userId)) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        $query = OrderSupportRepository::findQueryByOrderIdForUser($orderId, $userId);
        if ($query === null) {
            Response::json(['query' => null, 'messages' => []]);
            return;
        }
        Response::json(['query' => $query, 'messages' => OrderSupportRepository::findMessages((string) $query['id'])]);
    }

    public function createOrMessage(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $orderId = trim((string) ($body['order_id'] ?? ''));
        $subject = trim((string) ($body['subject'] ?? ''));
        $message = trim((string) ($body['message'] ?? ''));
        if ($orderId === '' || !Uuid::isValid($orderId)) {
            throw new ValidationException('Invalid order_id', ['order_id' => 'Must be a valid UUID.']);
        }
        if ($message === '') {
            throw new ValidationException('Invalid message', ['message' => 'Message is required.']);
        }
        if ($this->textLength($message) > 5000) {
            throw new ValidationException('Invalid message', ['message' => 'Message must be 5000 characters or less.']);
        }
        if ($subject !== '' && $this->textLength($subject) > 255) {
            throw new ValidationException('Invalid subject', ['subject' => 'Subject must be 255 characters or less.']);
        }
        if (!OrderSupportRepository::findOrderOwnerForUser($orderId, $userId)) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $query = OrderSupportRepository::findQueryByOrderIdForUser($orderId, $userId);
        if ($query === null) {
            $queryId = Uuid::v4();
            OrderSupportRepository::createQuery(
                $queryId,
                $orderId,
                $userId,
                $subject === '' ? null : $subject,
                OrderSupportRepository::STATUS_OPEN
            );
        } else {
            $queryId = (string) $query['id'];
        }
        OrderSupportRepository::addMessage(Uuid::v4(), $queryId, $userId, 'customer', $message);
        OrderSupportRepository::updateStatus($queryId, OrderSupportRepository::STATUS_WAITING_ADMIN, null);

        $query = OrderSupportRepository::findQueryByOrderIdForUser($orderId, $userId);
        Response::json([
            'query' => $query,
            'messages' => $query === null ? [] : OrderSupportRepository::findMessages((string) $query['id']),
        ], 201);
    }

    public function adminIndex(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }
        $orderId = trim((string) ($request->query('order_id') ?? ''));
        if ($orderId !== '') {
            if (!Uuid::isValid($orderId)) {
                Response::json(['error' => 'Invalid order_id'], 422);
                return;
            }
            $query = OrderSupportRepository::findQueryByOrderId($orderId);
            Response::json([
                'query' => $query,
                'messages' => $query === null ? [] : OrderSupportRepository::findMessages((string) $query['id']),
            ]);
            return;
        }

        $page = max(0, (int) ($request->query('page') ?? '0'));
        $limit = min(50, max(1, (int) ($request->query('limit') ?? '20')));
        $status = trim((string) ($request->query('status') ?? ''));
        $statusFilter = $status === '' ? null : $status;
        if ($statusFilter !== null && !in_array($statusFilter, OrderSupportRepository::statuses(), true)) {
            Response::json(['error' => 'Invalid status'], 422);
            return;
        }
        Response::json([
            'queries' => OrderSupportRepository::findAllForAdmin($page * $limit, $limit, $statusFilter),
            'total' => OrderSupportRepository::countAllForAdmin($statusFilter),
            'page' => $page,
            'limit' => $limit,
            'statuses' => OrderSupportRepository::statuses(),
        ]);
    }

    public function adminReply(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $orderId = trim((string) ($body['order_id'] ?? ''));
        $message = trim((string) ($body['message'] ?? ''));
        if ($orderId === '' || !Uuid::isValid($orderId)) {
            throw new ValidationException('Invalid order_id', ['order_id' => 'Must be a valid UUID.']);
        }
        if ($message === '') {
            throw new ValidationException('Invalid message', ['message' => 'Message is required.']);
        }
        if ($this->textLength($message) > 5000) {
            throw new ValidationException('Invalid message', ['message' => 'Message must be 5000 characters or less.']);
        }
        $query = OrderSupportRepository::findQueryByOrderId($orderId);
        if ($query === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        $queryId = (string) $query['id'];
        $actor = (string) ($claims['sub'] ?? '');
        $role = (string) ($claims['role'] ?? 'admin');
        OrderSupportRepository::addMessage(Uuid::v4(), $queryId, $actor, $role === '' ? 'admin' : $role, $message);
        OrderSupportRepository::updateStatus($queryId, OrderSupportRepository::STATUS_ANSWERED, null);

        $query = OrderSupportRepository::findQueryByOrderId($orderId);
        Response::json([
            'query' => $query,
            'messages' => $query === null ? [] : OrderSupportRepository::findMessages((string) $query['id']),
        ]);
    }

    public function adminPatch(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $orderId = trim((string) ($body['order_id'] ?? ''));
        $status = trim((string) ($body['status'] ?? ''));
        if ($orderId === '' || !Uuid::isValid($orderId)) {
            throw new ValidationException('Invalid order_id', ['order_id' => 'Must be a valid UUID.']);
        }
        if (!in_array($status, OrderSupportRepository::statuses(), true)) {
            throw new ValidationException('Invalid status', ['status' => 'Unsupported support status.']);
        }
        $query = OrderSupportRepository::findQueryByOrderId($orderId);
        if ($query === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        OrderSupportRepository::updateStatus((string) $query['id'], $status, (string) ($claims['sub'] ?? ''));
        $query = OrderSupportRepository::findQueryByOrderId($orderId);
        Response::json([
            'query' => $query,
            'messages' => $query === null ? [] : OrderSupportRepository::findMessages((string) $query['id']),
        ]);
    }
}
