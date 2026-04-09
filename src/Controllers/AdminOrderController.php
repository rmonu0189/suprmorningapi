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
use App\Repositories\UserRepository;

final class AdminOrderController
{
    public function index(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $singleId = $request->query('id');
        if ($singleId !== null && trim($singleId) !== '') {
            $this->showById(trim($singleId));
            return;
        }

        $page = max(0, (int) ($request->query('page') ?? '0'));
        $limit = min(50, max(1, (int) ($request->query('limit') ?? '20')));
        $offset = $page * $limit;

        $paymentStatus = trim((string) ($request->query('payment_status') ?? ''));
        $orderStatus = trim((string) ($request->query('order_status') ?? ''));

        $ps = $paymentStatus === '' ? null : $paymentStatus;
        $os = $orderStatus === '' ? null : $orderStatus;

        $dateParam = $request->query('date');
        $dateYmd = null;
        if ($dateParam !== null && trim($dateParam) !== '') {
            $trimmed = trim($dateParam);
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $trimmed);
            if ($dt === false || $dt->format('Y-m-d') !== $trimmed) {
                Response::json(['error' => 'Invalid date', 'errors' => ['date' => 'Use YYYY-MM-DD.']], 422);

                return;
            }
            $dateYmd = $dt->format('Y-m-d');
        }

        $warehouseId = null;
        $role = (string) ($claims['role'] ?? '');
        $sub = (string) ($claims['sub'] ?? '');
        if (($role === 'staff' || $role === 'manager' || $role === 'delivery') && $sub !== '') {
            $warehouseId = UserRepository::findWarehouseId($sub);
        }

        $total = OrderRepository::countAllForAdmin($ps, $os, $dateYmd);
        $total = OrderRepository::countAllForAdmin($ps, $os, $dateYmd, $warehouseId);
        $orders = OrderRepository::findAllForAdmin($offset, $limit, $ps, $os, $dateYmd, false, $warehouseId);

        Response::json([
            'orders' => $orders,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    private function showById(string $id): void
    {
        if ($id === '' || !Uuid::isValid($id)) {
            Response::json(['error' => 'Invalid id'], 422);
            return;
        }

        $order = OrderRepository::findByIdForAdmin($id);
        if ($order === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['order' => $order]);
    }

    public function statusEvents(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $orderId = trim((string) ($request->query('order_id') ?? ''));
        if ($orderId === '' || !Uuid::isValid($orderId)) {
            Response::json(['error' => 'Invalid order_id'], 422);
            return;
        }

        if (OrderRepository::findByIdForAdmin($orderId) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $role = (string) ($claims['role'] ?? '');
        $sub = (string) ($claims['sub'] ?? '');
        if (($role === 'staff' || $role === 'manager' || $role === 'delivery') && $sub !== '') {
            $wid = UserRepository::findWarehouseId($sub);
            $orderWid = OrderRepository::findWarehouseIdForOrder($orderId);
            if ($wid === null || $orderWid === null || $wid !== $orderWid) {
                Response::json(['error' => 'Not Found'], 404);
                return;
            }
        }

        $events = OrderRepository::findStatusEventsForAdmin($orderId);
        Response::json(['events' => $events]);
    }

    public function patch(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $id = trim((string) ($body['id'] ?? ''));
        if ($id === '' || !Uuid::isValid($id)) {
            throw new ValidationException('Invalid id', ['id' => 'A valid UUID is required.']);
        }

        if (OrderRepository::findByIdForAdmin($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $role = (string) ($claims['role'] ?? '');
        $sub = (string) ($claims['sub'] ?? '');
        if (($role === 'staff' || $role === 'manager' || $role === 'delivery') && $sub !== '') {
            $wid = UserRepository::findWarehouseId($sub);
            $orderWid = OrderRepository::findWarehouseIdForOrder($id);
            if ($wid === null || $orderWid === null || $wid !== $orderWid) {
                Response::json(['error' => 'Not Found'], 404);
                return;
            }
        }

        $orderStatus = null;
        $orderStatusProvided = array_key_exists('order_status', $body);
        if ($orderStatusProvided) {
            $v = $body['order_status'];
            if ($v === null) {
                throw new ValidationException('Invalid order_status', ['order_status' => 'Cannot be null.']);
            }
            $orderStatus = trim((string) $v);
            if ($orderStatus === '') {
                throw new ValidationException('Invalid order_status', ['order_status' => 'Cannot be empty.']);
            }
        }

        $deliveredAtSql = null;
        $deliveredAtProvided = array_key_exists('delivered_at', $body);
        if ($deliveredAtProvided) {
            $v = $body['delivered_at'];
            if ($v === null || $v === '') {
                $deliveredAtSql = null;
            } elseif (is_string($v)) {
                $ts = strtotime($v);
                if ($ts === false) {
                    throw new ValidationException('Invalid delivered_at', ['delivered_at' => 'Invalid datetime.']);
                }
                $deliveredAtSql = date('Y-m-d H:i:s', $ts);
            } else {
                throw new ValidationException('Invalid delivered_at', ['delivered_at' => 'Must be a string or null.']);
            }
        }

        if (!$orderStatusProvided && !$deliveredAtProvided) {
            throw new ValidationException('Nothing to update', [
                'body' => 'Provide order_status and/or delivered_at.',
            ]);
        }

        $actorUserId = null;
        if (isset($claims['sub']) && is_string($claims['sub']) && trim($claims['sub']) !== '') {
            $actorUserId = trim($claims['sub']);
        }

        OrderRepository::updateAdminFulfillment(
            $id,
            $orderStatus,
            $orderStatusProvided,
            $deliveredAtSql,
            $deliveredAtProvided,
            $actorUserId
        );

        $order = OrderRepository::findByIdForAdmin($id);
        if ($order === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['order' => $order]);
    }
}
