<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class PaymentRepository
{
    public static function insert(
        string $id,
        string $orderId,
        string $userId,
        string $gateway,
        string $gatewayOrderId,
        float $amount,
        string $currency,
        string $status
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO payments (id, order_id, user_id, gateway, gateway_order_id, amount, currency, status)
             VALUES (:id, :oid, :uid, :gw, :go, :amt, :cur, :st)'
        );
        $stmt->execute([
            'id' => $id,
            'oid' => $orderId,
            'uid' => $userId,
            'gw' => $gateway,
            'go' => $gatewayOrderId,
            'amt' => $amount,
            'cur' => $currency,
            'st' => $status,
        ]);
    }

    /**
     * Updates the latest payment row (by created_at) for a given gateway order id.
     */
    public static function updateStatusByGatewayOrderId(string $gatewayOrderId, string $status): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE payments SET status = :st
             WHERE gateway_order_id = :go
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['st' => $status, 'go' => $gatewayOrderId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Successful payment amounts per gateway for an order (wallet vs razorpay rows).
     *
     * @return array{wallet: float, razorpay: float}
     */
    public static function sumSuccessfulByGatewayForOrder(string $orderId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT gateway, COALESCE(SUM(amount), 0) AS s
             FROM payments
             WHERE order_id = :oid AND status = 'success'
             GROUP BY gateway"
        );
        $stmt->execute(['oid' => $orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $wallet = 0.0;
        $razorpay = 0.0;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $gw = strtolower(trim((string) ($row['gateway'] ?? '')));
                $s = (float) ($row['s'] ?? 0);
                if ($gw === 'wallet') {
                    $wallet += $s;
                } elseif ($gw === 'razorpay') {
                    $razorpay += $s;
                }
            }
        }

        return ['wallet' => $wallet, 'razorpay' => $razorpay];
    }

    /**
     * Latest successful gateway_order_id per gateway (wallet vs razorpay) for receipt / support IDs.
     *
     * @return array{wallet: ?string, razorpay: ?string}
     */
    public static function successfulGatewayRefsForOrder(string $orderId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT gateway, gateway_order_id FROM payments
             WHERE order_id = :oid AND status = 'success'
             ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute(['oid' => $orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $wallet = null;
        $razorpay = null;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $gw = strtolower(trim((string) ($row['gateway'] ?? '')));
                $go = trim((string) ($row['gateway_order_id'] ?? ''));
                if ($go === '') {
                    continue;
                }
                if ($gw === 'wallet' && $wallet === null) {
                    $wallet = $go;
                } elseif ($gw === 'razorpay' && $razorpay === null) {
                    $razorpay = $go;
                }
            }
        }

        return ['wallet' => $wallet, 'razorpay' => $razorpay];
    }

    public static function hasSuccessfulGatewayForOrder(string $orderId, string $gateway): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM payments
             WHERE order_id = :oid AND gateway = :gw AND status = 'success'
             LIMIT 1"
        );
        $stmt->execute(['oid' => $orderId, 'gw' => $gateway]);

        return (bool) $stmt->fetchColumn();
    }
}
