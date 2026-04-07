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
}
