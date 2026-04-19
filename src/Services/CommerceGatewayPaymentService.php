<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Uuid;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\WalletHoldRepository;
use App\Repositories\WalletRepository;

/**
 * Finalizes or rolls back wallet holds when a Razorpay order payment succeeds or fails.
 */
final class CommerceGatewayPaymentService
{
    public static function onGatewayPaymentSuccess(string $gatewayOrderId): void
    {
        $gatewayOrderId = trim($gatewayOrderId);
        if ($gatewayOrderId === '') {
            return;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $row = OrderRepository::findRawByGatewayOrderId($gatewayOrderId);
            if ($row === null) {
                $pdo->commit();

                return;
            }

            $orderId = (string) ($row['id'] ?? '');
            $userId = (string) ($row['user_id'] ?? '');
            if ($orderId === '' || $userId === '') {
                $pdo->commit();

                return;
            }

            if ((string) ($row['payment_status'] ?? '') === 'success') {
                $pdo->commit();

                return;
            }

            $hold = WalletHoldRepository::findByOrderId($orderId);
            if ($hold !== null && (string) ($hold['status'] ?? '') === 'active') {
                $amount = (float) ($hold['amount'] ?? 0);
                $holdId = (string) ($hold['id'] ?? '');
                if ($amount > 0.0 && $holdId !== '') {
                    if (!WalletRepository::finalizeLockedAsSpent($userId, $amount)) {
                        $fresh = OrderRepository::findRawByGatewayOrderId($gatewayOrderId);
                        if ($fresh !== null && (string) ($fresh['payment_status'] ?? '') === 'success') {
                            $pdo->commit();

                            return;
                        }
                        $pdo->rollBack();
                        try {
                            self::logLine('wallet_capture_failed order=' . $orderId . ' gateway=' . $gatewayOrderId);
                        } catch (\Throwable) {
                        }

                        return;
                    }

                    WalletHoldRepository::updateStatus($holdId, 'captured');

                    $txId = Uuid::v4();
                    WalletRepository::appendLedgerEntry(
                        $txId,
                        $userId,
                        'debit',
                        'order',
                        $amount,
                        'success',
                        $orderId,
                        $gatewayOrderId,
                        'Order payment (wallet portion)'
                    );

                    if (!PaymentRepository::hasSuccessfulGatewayForOrder($orderId, 'wallet')) {
                        $walletGatewayId = 'wallet_' . $txId;
                        PaymentRepository::insert(
                            Uuid::v4(),
                            $orderId,
                            $userId,
                            'wallet',
                            $walletGatewayId,
                            $amount,
                            'INR',
                            'success'
                        );
                    }
                }
            }

            OrderRepository::updatePaymentStatusByOrderIdIfPending($orderId, 'success');
            PaymentRepository::updateStatusByGatewayOrderId($gatewayOrderId, 'success');

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            try {
                self::logLine('commerce_gateway_success_err gateway=' . $gatewayOrderId . ' ' . $e->getMessage());
            } catch (\Throwable) {
            }
        }
    }

    /**
     * User closed checkout or gave up: mark pending Razorpay order as failed and release any wallet hold.
     */
    public static function abandonCheckout(string $orderId, string $userId): void
    {
        $orderId = trim($orderId);
        $userId = trim($userId);
        if ($orderId === '' || $userId === '') {
            return;
        }

        $order = OrderRepository::findByIdForUser($orderId, $userId);
        if ($order === null) {
            throw new HttpException('Not found', 404);
        }

        if ((string) ($order['payment_status'] ?? '') !== 'pending') {
            return;
        }

        $go = $order['gateway_order_id'] ?? null;
        if ($go === null || $go === '' || !is_string($go)) {
            OrderRepository::updatePaymentStatusByOrderId($orderId, 'failed');

            return;
        }

        self::onGatewayPaymentFailed($go);
    }

    public static function onGatewayPaymentFailed(string $gatewayOrderId): void
    {
        $gatewayOrderId = trim($gatewayOrderId);
        if ($gatewayOrderId === '') {
            return;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $row = OrderRepository::findRawByGatewayOrderId($gatewayOrderId);
            if ($row === null) {
                $pdo->commit();

                return;
            }

            $orderId = (string) ($row['id'] ?? '');
            $userId = (string) ($row['user_id'] ?? '');
            if ($orderId === '' || $userId === '') {
                $pdo->commit();

                return;
            }

            if ((string) ($row['payment_status'] ?? '') === 'success') {
                $pdo->commit();

                return;
            }

            OrderRepository::updatePaymentStatusByOrderId($orderId, 'failed');
            PaymentRepository::updateStatusByGatewayOrderId($gatewayOrderId, 'failed');

            $hold = WalletHoldRepository::findByOrderId($orderId);
            if ($hold === null) {
                $pdo->commit();

                return;
            }

            $status = (string) ($hold['status'] ?? '');
            if ($status !== 'active') {
                $pdo->commit();

                return;
            }

            $amount = (float) ($hold['amount'] ?? 0);
            $holdId = (string) ($hold['id'] ?? '');
            if ($amount <= 0 || $holdId === '') {
                $pdo->commit();

                return;
            }

            if (!WalletRepository::releaseLockedToSpendable($userId, $amount)) {
                $pdo->rollBack();
                try {
                    self::logLine('wallet_release_failed order=' . $orderId . ' gateway=' . $gatewayOrderId);
                } catch (\Throwable) {
                }

                return;
            }

            WalletHoldRepository::updateStatus($holdId, 'released');

            $txId = Uuid::v4();
            WalletRepository::appendLedgerEntry(
                $txId,
                $userId,
                'credit',
                'order_hold_release',
                $amount,
                'success',
                $orderId,
                $gatewayOrderId,
                'Wallet hold released after payment failure'
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            try {
                self::logLine('commerce_gateway_fail_err gateway=' . $gatewayOrderId . ' ' . $e->getMessage());
            } catch (\Throwable) {
            }
        }
    }

    private static function logLine(string $line): void
    {
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        @error_log('[' . gmdate('c') . '] ' . $line . "\n", 3, $logDir . '/commerce_payments.log');
    }
}
