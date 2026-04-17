<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentEventRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\WalletRepository;
use App\Repositories\WalletTopupRepository;
use App\Core\Database;
use App\Core\Uuid;

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

        // Always log the received payment event (after signature verification).
        // Never fail the webhook response just because logging failed.
        try {
            PaymentEventRepository::insert(
                Uuid::v4(),
                'razorpay',
                $event,
                $orderId,
                $raw
            );
        } catch (\Throwable) {
            // ignore
        }

        // Log basic webhook telemetry to ease production debugging.
        try {
            $logDir = __DIR__ . '/../../storage/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $line = sprintf("[%s] event=%s order_id=%s\n", gmdate('c'), $event, $orderId ?? 'null');
            @error_log($line, 3, $logDir . '/webhooks.log');
        } catch (\Throwable) {
            // ignore
        }

        if ($orderId === null) {
            Response::json(['ok' => true]);
            return;
        }

        if ($event === 'payment.captured' || $event === 'order.paid') {
            OrderRepository::updatePaymentStatusByGatewayOrderId($orderId, 'success');
            PaymentRepository::updateStatusByGatewayOrderId($orderId, 'success');
            self::creditWalletTopupIfApplicable($orderId, self::extractRazorpayPaymentId($event, $payload));
        } elseif ($event === 'payment.failed') {
            OrderRepository::updatePaymentStatusByGatewayOrderId($orderId, 'failed');
            PaymentRepository::updateStatusByGatewayOrderId($orderId, 'failed');
            WalletTopupRepository::markFailedByGatewayOrderId($orderId, self::extractRazorpayPaymentId($event, $payload));
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

    private static function creditWalletTopupIfApplicable(string $gatewayOrderId, ?string $gatewayPaymentId): void
    {
        $topup = WalletTopupRepository::findByGatewayOrderId($gatewayOrderId);
        if ($topup === null) {
            return;
        }
        if ((string) ($topup['status'] ?? '') === 'success') {
            return;
        }
        $topupId = (string) ($topup['id'] ?? '');
        $userId = (string) ($topup['user_id'] ?? '');
        $amount = (float) ($topup['amount'] ?? 0);
        if ($topupId === '' || $userId === '' || $amount <= 0) {
            return;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $fresh = WalletTopupRepository::findByGatewayOrderId($gatewayOrderId);
            if ($fresh === null || (string) ($fresh['status'] ?? '') === 'success') {
                $pdo->commit();
                return;
            }

            WalletRepository::credit(
                Uuid::v4(),
                $userId,
                round($amount, 2),
                'topup',
                null,
                $gatewayPaymentId ?? $gatewayOrderId,
                'Wallet top-up via Razorpay'
            );
            WalletTopupRepository::markSuccessById($topupId, $gatewayPaymentId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            try {
                $logDir = __DIR__ . '/../../storage/logs';
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0755, true);
                }
                $line = sprintf("[%s] wallet_credit_failed order=%s err=%s\n", gmdate('c'), $gatewayOrderId, $e->getMessage());
                @error_log($line, 3, $logDir . '/webhooks.log');
            } catch (\Throwable) {
                // ignore
            }
            // Do not fail webhook ack to avoid repeated retries due local processing issue.
            return;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function extractRazorpayPaymentId(string $event, array $payload): ?string
    {
        if (!str_starts_with($event, 'payment.') && $event !== 'order.paid') {
            return null;
        }

        $pay = $payload['payment'] ?? null;
        if (is_array($pay)) {
            $ent = $pay['entity'] ?? null;
            if (is_array($ent) && isset($ent['id']) && is_string($ent['id'])) {
                return $ent['id'];
            }
        }
        return null;
    }
}
