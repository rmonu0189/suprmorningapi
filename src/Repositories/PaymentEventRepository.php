<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class PaymentEventRepository
{
    public static function insert(
        string $id,
        string $gateway,
        string $event,
        ?string $gatewayOrderId,
        string $payload
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO payment_events (id, gateway, event, gateway_order_id, payload)
             VALUES (:id, :gw, :ev, :go, :pl)'
        );
        $stmt->execute([
            'id' => $id,
            'gw' => $gateway,
            'ev' => $event,
            'go' => $gatewayOrderId,
            'pl' => $payload,
        ]);
    }
}

