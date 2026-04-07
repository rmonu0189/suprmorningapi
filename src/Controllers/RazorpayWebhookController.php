<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\OrderRepository;

final class RazorpayWebhookController
{
    public function handle(Request $request): void
    {
        $secret = Env::get('RAZORPAY_WEBHOOK_SECRET', '');
        if (trim($secret) === '') {
            Response::json(['error' => 'Webhook not configured'], 503);
            return;
        }

        $raw = $request->rawBody();
        $sig = $request->header('X-Razorpay-Signature');
        if ($sig === null || $sig === '') {
            Response::json(['error' => 'Missing signature'], 400);
            return;
        }

        $expected = hash_hmac('sha256', $raw, $secret);
        if (!hash_equals($expected, $sig)) {
            Response::json(['error' => 'Invalid signature'], 400);
            return;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Response::json(['error' => 'Invalid JSON'], 400);
            return;
        }

        if (!is_array($data)) {
            Response::json(['error' => 'Invalid payload'], 400);
            return;
        }

        $event = (string) ($data['event'] ?? '');
        $payload = $data['payload'] ?? null;
        if (!is_array($payload)) {
            Response::json(['ok' => true]);
            return;
        }

        $orderId = self::extractRazorpayOrderId($event, $payload);
        if ($orderId === null) {
            Response::json(['ok' => true]);
            return;
        }

        if ($event === 'payment.captured' || $event === 'order.paid') {
            OrderRepository::updatePaymentStatusByGatewayOrderId($orderId, 'success');
        } elseif ($event === 'payment.failed') {
            OrderRepository::updatePaymentStatusByGatewayOrderId($orderId, 'failed');
        }

        Response::json(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function extractRazorpayOrderId(string $event, array $payload): ?string
    {
        if (str_starts_with($event, 'payment.')) {
            $pay = $payload['payment'] ?? null;
            if (is_array($pay)) {
                $ent = $pay['entity'] ?? null;
                if (is_array($ent) && isset($ent['order_id']) && is_string($ent['order_id'])) {
                    return $ent['order_id'];
                }
            }
        }
        if ($event === 'order.paid') {
            $ord = $payload['order'] ?? null;
            if (is_array($ord)) {
                $ent = $ord['entity'] ?? null;
                if (is_array($ent) && isset($ent['id']) && is_string($ent['id'])) {
                    return $ent['id'];
                }
            }
        }

        return null;
    }
}
