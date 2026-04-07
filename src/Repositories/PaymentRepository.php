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
}
