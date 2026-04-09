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

final class AdminDeliveryController
{
    public function index(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $deliveryDate = trim((string) ($request->query('delivery_date') ?? ''));
        $deliveryDateYmd = null;
        if ($deliveryDate !== '') {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $deliveryDate);
            if ($dt === false || $dt->format('Y-m-d') !== $deliveryDate) {
                Response::json(['error' => 'Invalid delivery_date', 'errors' => ['delivery_date' => 'Use YYYY-MM-DD.']], 422);
                return;
            }
            $deliveryDateYmd = $dt->format('Y-m-d');
        }

        $statusParam = trim((string) ($request->query('order_status') ?? ''));
        $orderStatuses = null;
        if ($statusParam !== '') {
            // comma-separated list
            $parts = array_values(array_filter(array_map('trim', explode(',', $statusParam)), static fn ($x) => $x !== ''));
            $orderStatuses = $parts === [] ? null : $parts;
        }

        $includeDelivered = trim((string) ($request->query('include_delivered') ?? '')) === '1';

        $warehouseId = null;
        $role = (string) ($claims['role'] ?? '');
        $sub = (string) ($claims['sub'] ?? '');
        if (($role === 'staff' || $role === 'manager' || $role === 'delivery') && $sub !== '') {
            $warehouseId = UserRepository::findWarehouseId($sub);
        }

        $orders = OrderRepository::findDeliverableOrdersForAdmin($deliveryDateYmd, $orderStatuses, $includeDelivered, $warehouseId);
        $items = OrderRepository::findOrderItemsForOrdersWithVariantId(array_map(static fn ($o) => (string) ($o['id'] ?? ''), $orders));

        // Aggregate items by variant (prefer variant_id, fallback to sku key).
        $by = [];
        foreach ($items as $it) {
            $variantId = isset($it['variant_id']) && is_string($it['variant_id']) && $it['variant_id'] !== '' ? $it['variant_id'] : null;
            $sku = isset($it['sku']) ? (string) $it['sku'] : '';
            $k = $variantId !== null ? ('vid:' . $variantId) : ('sku:' . $sku);
            if (!isset($by[$k])) {
                $by[$k] = [
                    'variant_id' => $variantId,
                    'sku' => $sku,
                    'brand_name' => (string) ($it['brand_name'] ?? ''),
                    'product_name' => (string) ($it['product_name'] ?? ''),
                    'variant_name' => (string) ($it['variant_name'] ?? ''),
                    'image' => (string) ($it['image'] ?? ''),
                    'quantity' => 0,
                    'order_count' => 0,
                ];
            }
            $by[$k]['quantity'] += (int) ($it['quantity'] ?? 0);
        }

        // Count distinct orders per variant key.
        $seen = [];
        foreach ($items as $it) {
            $variantId = isset($it['variant_id']) && is_string($it['variant_id']) && $it['variant_id'] !== '' ? $it['variant_id'] : null;
            $sku = isset($it['sku']) ? (string) $it['sku'] : '';
            $k = $variantId !== null ? ('vid:' . $variantId) : ('sku:' . $sku);
            $oid = (string) ($it['order_id'] ?? '');
            if ($oid === '') continue;
            if (!isset($seen[$k])) $seen[$k] = [];
            if (!isset($seen[$k][$oid])) {
                $seen[$k][$oid] = true;
                $by[$k]['order_count'] += 1;
            }
        }

        $itemsByVariant = array_values($by);
        usort($itemsByVariant, static function (array $a, array $b): int {
            $qa = (int) ($a['quantity'] ?? 0);
            $qb = (int) ($b['quantity'] ?? 0);
            if ($qa === $qb) return strcmp((string) ($a['sku'] ?? ''), (string) ($b['sku'] ?? ''));
            return $qb <=> $qa;
        });

        Response::json([
            'orders' => $orders,
            'items_by_variant' => $itemsByVariant,
            'order_items' => $items,
        ]);
    }

    public function show(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $id = trim((string) ($request->query('id') ?? ''));
        if ($id === '' || !Uuid::isValid($id)) {
            Response::json(['error' => 'Invalid id'], 422);
            return;
        }

        $order = OrderRepository::findByIdForAdmin($id);
        if ($order === null) {
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

        $items = OrderRepository::findOrderItemsForOrdersWithVariantId([$id]);

        Response::json([
            'order' => $order,
            'items' => $items,
        ]);
    }

    public function patchChecks(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $orderId = trim((string) ($body['order_id'] ?? ''));
        if ($orderId === '' || !Uuid::isValid($orderId)) {
            throw new ValidationException('Invalid order_id', ['order_id' => 'A valid UUID is required.']);
        }

        if (OrderRepository::findByIdForAdmin($orderId) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $items = $body['items'] ?? null;
        if (!is_array($items)) {
            throw new ValidationException('Invalid items', ['items' => 'Must be an array.']);
        }

        $normalized = [];
        foreach ($items as $row) {
            if (!is_array($row)) continue;
            $orderItemId = trim((string) ($row['order_item_id'] ?? ''));
            if ($orderItemId === '' || !Uuid::isValid($orderItemId)) {
                throw new ValidationException('Invalid order_item_id', ['order_item_id' => 'A valid UUID is required.']);
            }
            $status = trim((string) ($row['status'] ?? ''));
            if ($status === '') {
                throw new ValidationException('Invalid status', ['status' => 'Required.']);
            }
            $pickedQty = (int) ($row['picked_quantity'] ?? 0);
            $note = array_key_exists('note', $row) ? $row['note'] : null;
            if ($note !== null && !is_string($note)) {
                throw new ValidationException('Invalid note', ['note' => 'Must be string or null.']);
            }
            $normalized[] = [
                'order_item_id' => $orderItemId,
                'status' => $status,
                'picked_quantity' => $pickedQty,
                'note' => $note === null ? null : trim($note),
            ];
        }

        OrderRepository::upsertDeliveryItemChecks($orderId, $normalized);

        $itemsOut = OrderRepository::findOrderItemsForOrdersWithVariantId([$orderId]);
        Response::json(['ok' => true, 'items' => $itemsOut]);
    }
}

