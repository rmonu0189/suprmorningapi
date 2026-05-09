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
    public const WALLET_HOLD_TIMEOUT_SECONDS = 1800;

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
                ReferralService::completeForSuccessfulOrder($userId, $orderId);
                $pdo->commit();

                return;
            }

            $hold = WalletHoldRepository::findByOrderId($orderId);
            if ($hold !== null && (string) ($hold['status'] ?? '') === 'released' && !self::holdIsExpired($hold)) {
                $amount = (float) ($hold['amount'] ?? 0);
                $holdId = (string) ($hold['id'] ?? '');
                if ($amount > 0.0 && $holdId !== '') {
                    $txId = Uuid::v4();
                    if (!WalletRepository::debit(
                        $txId,
                        $userId,
                        $amount,
                        'order',
                        $orderId,
                        $gatewayOrderId,
                        'Order payment (wallet portion captured after checkout callback race)'
                    )) {
                        OrderRepository::updatePaymentStatusByOrderId($orderId, 'failed');
                        PaymentRepository::updateStatusByGatewayOrderId($gatewayOrderId, 'refund_required');
                        self::logLine('wallet_recapture_failed_after_release order=' . $orderId . ' gateway=' . $gatewayOrderId . ' refund_required=1');
                        $pdo->commit();

                        return;
                    }

                    WalletHoldRepository::updateStatus($holdId, 'captured');

                    if (!PaymentRepository::hasSuccessfulGatewayForOrder($orderId, 'wallet')) {
                        PaymentRepository::insert(
                            Uuid::v4(),
                            $orderId,
                            $userId,
                            'wallet',
                            'wallet_' . $txId,
                            $amount,
                            'INR',
                            'success'
                        );
                    }
                }
            } elseif ($hold !== null && in_array((string) ($hold['status'] ?? ''), ['released', 'expired'], true)) {
                OrderRepository::updatePaymentStatusByOrderId($orderId, 'failed');
                PaymentRepository::updateStatusByGatewayOrderId($gatewayOrderId, 'refund_required');
                self::logLine('late_gateway_success_after_hold_released order=' . $orderId . ' gateway=' . $gatewayOrderId . ' hold_status=' . (string) ($hold['status'] ?? '') . ' refund_required=1');
                $pdo->commit();

                return;
            }

            if ($hold !== null && (string) ($hold['status'] ?? '') === 'active' && self::holdIsExpired($hold)) {
                if (self::expireActiveHoldInsideTransaction($hold, 'refund_required')) {
                    OrderRepository::updatePaymentStatusByOrderId($orderId, 'failed');
                    PaymentRepository::updateStatusByGatewayOrderId($gatewayOrderId, 'refund_required');
                    self::logLine('late_gateway_success_after_hold_expired order=' . $orderId . ' gateway=' . $gatewayOrderId . ' refund_required=1');
                }
                $pdo->commit();

                return;
            }

            if ($hold !== null && (string) ($hold['status'] ?? '') === 'active') {
                $holdStatus = (string) ($hold['status'] ?? '');
                $amount = (float) ($hold['amount'] ?? 0);
                $holdId = (string) ($hold['id'] ?? '');
                if ($amount > 0.0 && $holdId !== '') {
                    $txId = Uuid::v4();
                    $walletCaptured = $holdStatus === 'active'
                        ? WalletRepository::finalizeLockedAsSpent($userId, $amount)
                        : WalletRepository::debit(
                            $txId,
                            $userId,
                            $amount,
                            'order',
                            $orderId,
                            $gatewayOrderId,
                            'Order payment (wallet portion)'
                        );

                    if (!$walletCaptured) {
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

                    if ($holdStatus === 'active') {
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
                    }

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

            OrderRepository::updatePaymentStatusByOrderId($orderId, 'success');
            PaymentRepository::updateStatusByGatewayOrderId($gatewayOrderId, 'success');
            ReferralService::completeForSuccessfulOrder($userId, $orderId);

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

    public static function expireStaleWalletHolds(?string $userId = null): int
    {
        if (!WalletRepository::supportsSplitCheckout()) {
            return 0;
        }

        $expired = 0;
        $cutoffUtc = gmdate('Y-m-d H:i:s', time() - self::WALLET_HOLD_TIMEOUT_SECONDS);
        $holds = WalletHoldRepository::findExpiredActive($cutoffUtc, $userId);
        foreach ($holds as $hold) {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            try {
                if (self::expireActiveHoldInsideTransaction($hold, 'timeout')) {
                    $orderId = (string) ($hold['order_id'] ?? '');
                    if ($orderId !== '') {
                        OrderRepository::updatePaymentStatusByOrderId($orderId, 'failed');
                        $order = OrderRepository::findRawByOrderId($orderId);
                        $gatewayOrderId = is_array($order) ? (string) ($order['gateway_order_id'] ?? '') : '';
                        if ($gatewayOrderId !== '') {
                            PaymentRepository::updateStatusByGatewayOrderId($gatewayOrderId, 'failed');
                        }
                    }
                    $expired++;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                try {
                    self::logLine('wallet_hold_expire_err hold=' . (string) ($hold['id'] ?? '') . ' ' . $e->getMessage());
                } catch (\Throwable) {
                }
            }
        }

        return $expired;
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

    /**
     * @param array<string, mixed> $hold
     */
    private static function expireActiveHoldInsideTransaction(array $hold, string $reason): bool
    {
        $holdId = (string) ($hold['id'] ?? '');
        $userId = (string) ($hold['user_id'] ?? '');
        $orderId = (string) ($hold['order_id'] ?? '');
        $amount = (float) ($hold['amount'] ?? 0);
        if ($holdId === '' || $userId === '' || $amount <= 0.0) {
            return false;
        }

        if (!WalletHoldRepository::updateStatusIfActive($holdId, 'expired')) {
            return false;
        }

        if (!WalletRepository::releaseLockedToSpendable($userId, $amount)) {
            throw new \RuntimeException('Could not release expired wallet hold');
        }

        $referenceId = $orderId !== '' ? $orderId : $holdId;
        WalletRepository::appendLedgerEntry(
            Uuid::v4(),
            $userId,
            'credit',
            'order_hold_release',
            $amount,
            'success',
            $orderId !== '' ? $orderId : null,
            $referenceId,
            $reason === 'refund_required'
                ? 'Wallet hold expired; online payment needs refund review'
                : 'Wallet hold released after payment timeout'
        );

        self::logLine('wallet_hold_expired user=' . $userId . ' order=' . $orderId . ' hold=' . $holdId . ' amount=' . number_format($amount, 2, '.', '') . ' reason=' . $reason);

        return true;
    }

    /**
     * @param array<string, mixed> $hold
     */
    public static function holdExpiresAt(array $hold): string
    {
        $createdAt = (string) ($hold['created_at'] ?? '');
        $ts = strtotime($createdAt . ' UTC');
        if ($ts === false) {
            $ts = time();
        }

        return gmdate('Y-m-d H:i:s', $ts + self::WALLET_HOLD_TIMEOUT_SECONDS);
    }

    /**
     * @param array<string, mixed> $hold
     */
    private static function holdIsExpired(array $hold): bool
    {
        $createdAt = (string) ($hold['created_at'] ?? '');
        $ts = strtotime($createdAt . ' UTC');
        if ($ts === false) {
            return false;
        }

        return (time() - $ts) >= self::WALLET_HOLD_TIMEOUT_SECONDS;
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
