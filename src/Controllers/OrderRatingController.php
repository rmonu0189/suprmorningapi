<?php

declare(strict_types=1);

namespace App\Controllers;

require_once __DIR__ . '/../Repositories/OrderRatingRepository.php';

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\OrderRatingRepository;
use App\Repositories\OrderRepository;

final class OrderRatingController
{
    /** GET /v1/orders/ratings?order_id=... */
    public function byOrder(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) return;
        $userId = (string) ($claims['sub'] ?? '');
        if ($userId === '' || !Uuid::isValid($userId)) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $orderId = trim((string) ($request->query('order_id') ?? ''));
        if ($orderId === '' || !Uuid::isValid($orderId)) {
            throw new ValidationException('Invalid order_id', ['order_id' => 'A valid UUID is required.']);
        }

        $order = OrderRepository::findByIdForUser($orderId, $userId);
        if ($order === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        if (!$this->isDelivered((string) ($order['order_status'] ?? ''))) {
            throw new ValidationException('Order not delivered', ['order_id' => 'Ratings are allowed only for delivered orders.']);
        }

        $itemRatings = OrderRatingRepository::findItemRatingsByUserAndOrder($userId, $orderId);
        $deliveryRating = OrderRatingRepository::findDeliveryRatingByUserAndOrder($userId, $orderId);
        Response::json([
            'order' => $order,
            'item_ratings' => $itemRatings,
            'delivery_rating' => $deliveryRating,
        ]);
    }

    /** POST /v1/orders/ratings */
    public function save(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) return;
        $userId = (string) ($claims['sub'] ?? '');
        if ($userId === '' || !Uuid::isValid($userId)) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();
        $orderId = trim((string) ($body['order_id'] ?? ''));
        if ($orderId === '' || !Uuid::isValid($orderId)) {
            throw new ValidationException('Invalid order_id', ['order_id' => 'A valid UUID is required.']);
        }

        $order = OrderRepository::findByIdForUser($orderId, $userId);
        if ($order === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        if (!$this->isDelivered((string) ($order['order_status'] ?? ''))) {
            throw new ValidationException('Order not delivered', ['order_id' => 'Ratings are allowed only for delivered orders.']);
        }

        $validItemIds = [];
        $orderItems = $order['order_items'] ?? [];
        if (is_array($orderItems)) {
            foreach ($orderItems as $it) {
                if (!is_array($it)) continue;
                $iid = isset($it['id']) ? trim((string) $it['id']) : '';
                if ($iid !== '') {
                    $validItemIds[$iid] = true;
                }
            }
        }

        $rawItems = $body['item_ratings'] ?? null;
        if (!is_array($rawItems) || $rawItems === []) {
            throw new ValidationException('Invalid item_ratings', ['item_ratings' => 'Provide at least one item rating.']);
        }
        foreach ($rawItems as $entry) {
            if (!is_array($entry)) continue;
            $orderItemId = trim((string) ($entry['order_item_id'] ?? ''));
            $rating = (int) ($entry['rating'] ?? 0);
            $feedback = isset($entry['feedback']) ? trim((string) $entry['feedback']) : null;
            if ($orderItemId === '' || !isset($validItemIds[$orderItemId])) {
                throw new ValidationException('Invalid order_item_id', ['item_ratings' => 'One or more item ids are invalid for this order.']);
            }
            if ($rating < 1 || $rating > 5) {
                throw new ValidationException('Invalid rating', ['item_ratings' => 'Item rating must be between 1 and 5.']);
            }
            try {
                OrderRatingRepository::upsertItemRating(
                    Uuid::v4(),
                    $userId,
                    $orderId,
                    $orderItemId,
                    $rating,
                    $feedback === '' ? null : $feedback
                );
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (is_string($msg) && $msg !== '') {
                    Response::json(['error' => 'Failed to save item ratings: ' . $msg], 500);
                    return;
                }
                Response::json(['error' => 'Failed to save item ratings'], 500);
                return;
            }
        }

        $deliveryRatingRaw = $body['delivery_rating'] ?? null;
        if (is_array($deliveryRatingRaw)) {
            $deliveryRating = (int) ($deliveryRatingRaw['rating'] ?? 0);
            $deliveryFeedback = isset($deliveryRatingRaw['feedback']) ? trim((string) $deliveryRatingRaw['feedback']) : null;
            $hasRating = $deliveryRating >= 1 && $deliveryRating <= 5;
            $hasFeedback = $deliveryFeedback !== null && $deliveryFeedback !== '';
            
            if ($hasRating || $hasFeedback) {
                $agent = $order['delivery_agent'] ?? null;
                $deliveryPartnerUserId = null;
                if (is_array($agent) && isset($agent['id']) && is_string($agent['id']) && $agent['id'] !== '') {
                    $candidate = trim($agent['id']);
                    if (Uuid::isValid($candidate)) {
                        $deliveryPartnerUserId = $candidate;
                    }
                }
                try {
                    OrderRatingRepository::upsertDeliveryRating(
                        Uuid::v4(),
                        $userId,
                        $orderId,
                        $deliveryPartnerUserId,
                        $deliveryRating,
                        $deliveryFeedback === '' ? null : $deliveryFeedback
                    );
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    if (is_string($msg) && $msg !== '') {
                        Response::json(['error' => 'Failed to save delivery rating: ' . $msg], 500);
                        return;
                    }
                    Response::json(['error' => 'Failed to save delivery rating'], 500);
                    return;
                }
            }
        }

        Response::json(['ok' => true]);
    }

    private function isDelivered(string $status): bool
    {
        $s = strtolower(trim(str_replace(['_', '-'], ' ', $status)));
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return $s === 'delivered' || $s === 'completed';
    }
}

