<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class WalletTopupRepository
{
    public static function insert(
        string $id,
        string $userId,
        float $amount,
        string $currency,
        string $gatewayName,
        string $gatewayOrderId,
        string $status = 'created'
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO wallet_topups (id, user_id, amount, currency, gateway_name, gateway_order_id, status)
             VALUES (:id, :uid, :amt, :cur, :gw, :go, :st)'
        );
        $stmt->execute([
            'id' => $id,
            'uid' => $userId,
            'amt' => $amount,
            'cur' => $currency,
            'gw' => $gatewayName,
            'go' => $gatewayOrderId,
            'st' => $status,
        ]);
    }

    /** @return array<string, mixed>|null */
    public static function findByIdForUser(string $id, string $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, amount, currency, gateway_name, gateway_order_id, gateway_payment_id, status, created_at, updated_at, credited_at
             FROM wallet_topups
             WHERE id = :id AND user_id = :uid
             LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return self::normalizeRow($row);
    }

    /** @return array<string, mixed>|null */
    public static function findByGatewayOrderId(string $gatewayOrderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, amount, currency, gateway_name, gateway_order_id, gateway_payment_id, status, created_at, updated_at, credited_at
             FROM wallet_topups
             WHERE gateway_order_id = :go
             LIMIT 1'
        );
        $stmt->execute(['go' => $gatewayOrderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return self::normalizeRow($row);
    }

    public static function markFailedByGatewayOrderId(string $gatewayOrderId, ?string $gatewayPaymentId = null): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE wallet_topups
             SET status = :st, gateway_payment_id = COALESCE(:gpid, gateway_payment_id)
             WHERE gateway_order_id = :go AND status != :success'
        );
        $stmt->execute([
            'st' => 'failed',
            'gpid' => $gatewayPaymentId,
            'go' => $gatewayOrderId,
            'success' => 'success',
        ]);
        return $stmt->rowCount() > 0;
    }

    public static function markSuccessById(string $id, ?string $gatewayPaymentId): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE wallet_topups
             SET status = :st_set, gateway_payment_id = COALESCE(:gpid, gateway_payment_id), credited_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status != :st_current'
        );
        $stmt->execute([
            'st_set' => 'success',
            'st_current' => 'success',
            'gpid' => $gatewayPaymentId,
            'id' => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<string, mixed> */
    private static function normalizeRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'user_id' => (string) $row['user_id'],
            'amount' => (float) $row['amount'],
            'currency' => (string) ($row['currency'] ?? 'INR'),
            'gateway_name' => (string) ($row['gateway_name'] ?? 'razorpay'),
            'gateway_order_id' => (string) ($row['gateway_order_id'] ?? ''),
            'gateway_payment_id' => isset($row['gateway_payment_id']) && $row['gateway_payment_id'] !== '' ? (string) $row['gateway_payment_id'] : null,
            'status' => (string) ($row['status'] ?? 'created'),
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            'credited_at' => isset($row['credited_at']) && $row['credited_at'] !== '' ? (string) $row['credited_at'] : null,
        ];
    }
}
