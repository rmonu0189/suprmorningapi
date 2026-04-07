<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class OrderRepository
{
    public static function insertOrder(
        string $id,
        string $userId,
        ?string $cartId,
        ?string $addressId,
        string $orderStatus,
        string $paymentStatus,
        ?string $deliveryDate,
        ?string $deliverySlot,
        ?string $deliveryType,
        float $totalMrp,
        float $tax,
        float $deliveryFee,
        float $grandTotal,
        string $currency,
        string $recipientName,
        string $recipientPhone,
        string $fullAddress,
        string $city,
        string $state,
        string $country,
        string $postalCode,
        float $totalPrice,
        float $totalCharges,
        ?string $gatewayOrderId,
        ?string $gatewayName,
        ?array $chargesMetadata
    ): void {
        $metaJson = $chargesMetadata !== null ? json_encode($chargesMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null;

        $stmt = Database::connection()->prepare(
            'INSERT INTO orders (id, user_id, cart_id, address_id, order_status, payment_status, delivery_date, delivery_slot,
                delivery_type, total_mrp, tax, delivery_fee, grand_total, currency, recipient_name, recipient_phone, full_address,
                city, state, country, postal_code, total_price, total_charges, gateway_order_id, gateway_name, charges_metadata)
             VALUES (:id, :uid, :cid, :aid, :os, :ps, :dd, :ds, :dt, :tm, :tx, :df, :gt, :cur, :rn, :rp, :fa, :city, :st, :ctry, :pc,
                :tp, :tc, :go, :gn, :cm)'
        );
        $stmt->execute([
            'id' => $id,
            'uid' => $userId,
            'cid' => $cartId,
            'aid' => $addressId,
            'os' => $orderStatus,
            'ps' => $paymentStatus,
            'dd' => $deliveryDate,
            'ds' => $deliverySlot,
            'dt' => $deliveryType,
            'tm' => $totalMrp,
            'tx' => $tax,
            'df' => $deliveryFee,
            'gt' => $grandTotal,
            'cur' => $currency,
            'rn' => $recipientName,
            'rp' => $recipientPhone,
            'fa' => $fullAddress,
            'city' => $city,
            'st' => $state,
            'ctry' => $country,
            'pc' => $postalCode,
            'tp' => $totalPrice,
            'tc' => $totalCharges,
            'go' => $gatewayOrderId,
            'gn' => $gatewayName,
            'cm' => $metaJson,
        ]);
    }

    public static function insertOrderItem(
        string $id,
        string $orderId,
        string $image,
        string $brandName,
        string $productName,
        string $variantName,
        int $quantity,
        float $unitPrice,
        float $unitMrp,
        string $sku
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO order_items (id, order_id, image, brand_name, product_name, variant_name, quantity, unit_price, unit_mrp, sku)
             VALUES (:id, :oid, :img, :bn, :pn, :vn, :q, :up, :um, :sku)'
        );
        $stmt->execute([
            'id' => $id,
            'oid' => $orderId,
            'img' => $image,
            'bn' => $brandName,
            'pn' => $productName,
            'vn' => $variantName,
            'q' => $quantity,
            'up' => $unitPrice,
            'um' => $unitMrp,
            'sku' => $sku,
        ]);
    }

    public static function updateGatewayOrderId(string $orderId, string $gatewayOrderId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE orders SET gateway_order_id = :go WHERE id = :id'
        );
        $stmt->execute(['go' => $gatewayOrderId, 'id' => $orderId]);
    }

    public static function updatePaymentStatusByGatewayOrderId(string $gatewayOrderId, string $paymentStatus): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE orders SET payment_status = :ps WHERE gateway_order_id = :go'
        );
        $stmt->execute(['ps' => $paymentStatus, 'go' => $gatewayOrderId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function findForUserExcludingPayment(string $userId, int $offset, int $limit): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM orders WHERE user_id = :uid AND payment_status != :pend
             ORDER BY created_at DESC, id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue('uid', $userId, PDO::PARAM_STR);
        $stmt->bindValue('pend', 'pending', PDO::PARAM_STR);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue('off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::formatOrderWithItems($row);
            }
        }

        return $out;
    }

    /**
     * Admin: single order with line items (no user scope).
     *
     * @return array<string, mixed>|null
     */
    public static function findByIdForAdmin(string $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM orders WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::formatOrderWithItems($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function findAllForAdmin(
        int $offset,
        int $limit,
        ?string $paymentStatus,
        ?string $orderStatus
    ): array {
        $where = ['1=1'];
        $params = [];
        if ($paymentStatus !== null && $paymentStatus !== '') {
            $where[] = 'o.payment_status = :ps';
            $params['ps'] = $paymentStatus;
        }
        if ($orderStatus !== null && $orderStatus !== '') {
            $where[] = 'o.order_status = :os';
            $params['os'] = $orderStatus;
        }
        $w = implode(' AND ', $where);
        $sql = "SELECT o.*, u.phone AS customer_phone, u.email AS customer_email, u.full_name AS customer_full_name
                FROM orders o
                LEFT JOIN users u ON u.id = o.user_id
                WHERE {$w}
                ORDER BY o.created_at DESC, o.id DESC
                LIMIT :lim OFFSET :off";
        $stmt = Database::connection()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue('off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orderOnly = self::stripJoinedOrderRow($row);
            $base = self::formatOrderWithItems($orderOnly);
            $base['customer'] = [
                'phone' => isset($row['customer_phone']) && $row['customer_phone'] !== ''
                    ? (string) $row['customer_phone'] : null,
                'email' => isset($row['customer_email']) && $row['customer_email'] !== ''
                    ? (string) $row['customer_email'] : null,
                'full_name' => isset($row['customer_full_name']) && $row['customer_full_name'] !== ''
                    ? (string) $row['customer_full_name'] : null,
            ];
            $base['user_id'] = (string) ($row['user_id'] ?? '');
            $out[] = $base;
        }

        return $out;
    }

    public static function countAllForAdmin(?string $paymentStatus, ?string $orderStatus): int
    {
        $where = ['1=1'];
        $params = [];
        if ($paymentStatus !== null && $paymentStatus !== '') {
            $where[] = 'payment_status = :ps';
            $params['ps'] = $paymentStatus;
        }
        if ($orderStatus !== null && $orderStatus !== '') {
            $where[] = 'order_status = :os';
            $params['os'] = $orderStatus;
        }
        $w = implode(' AND ', $where);
        $stmt = Database::connection()->prepare("SELECT COUNT(*) AS c FROM orders WHERE {$w}");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }

    public static function updateAdminFulfillment(
        string $orderId,
        ?string $orderStatus,
        bool $orderStatusProvided,
        ?string $deliveredAtSql,
        bool $deliveredAtProvided
    ): void {
        $sets = [];
        $params = ['id' => $orderId];
        if ($orderStatusProvided) {
            $sets[] = 'order_status = :os';
            $params['os'] = $orderStatus ?? 'created';
        }
        if ($deliveredAtProvided) {
            $sets[] = 'delivered_at = :da';
            $params['da'] = $deliveredAtSql;
        }
        if ($sets === []) {
            return;
        }
        $sql = 'UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @param array<string, mixed> $row joined row with o.* + customer_* aliases
     * @return array<string, mixed>
     */
    private static function stripJoinedOrderRow(array $row): array
    {
        unset($row['customer_phone'], $row['customer_email'], $row['customer_full_name']);

        return $row;
    }

    /** @return array<string, mixed>|null */
    public static function findByIdForUser(string $orderId, string $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM orders WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $orderId, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::formatOrderWithItems($row);
    }

    /** @return array<string, mixed>|null */
    public static function findByGatewayForUser(string $gatewayOrderId, string $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM orders WHERE gateway_order_id = :go AND user_id = :uid
             ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['go' => $gatewayOrderId, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::formatOrderWithItems($row);
    }

    public static function findPaymentStatusByGatewayOrderId(string $gatewayOrderId): string
    {
        $stmt = Database::connection()->prepare(
            'SELECT payment_status FROM orders WHERE gateway_order_id = :go
             ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['go' => $gatewayOrderId]);
        $v = $stmt->fetchColumn();
        if (!is_string($v) || $v === '') {
            return 'pending';
        }

        return $v;
    }

    /** @param array<string, mixed> $orderRow */
    private static function formatOrderWithItems(array $orderRow): array
    {
        $id = (string) $orderRow['id'];
        $items = self::fetchOrderItems($id);

        return self::formatOrderHeader($orderRow, $items);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchOrderItems(string $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, image, brand_name, product_name, variant_name, quantity, unit_price, unit_mrp, sku
             FROM order_items WHERE order_id = :oid ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['oid' => $orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'id' => (string) $row['id'],
                'image' => (string) $row['image'],
                'brand_name' => (string) $row['brand_name'],
                'product_name' => (string) $row['product_name'],
                'variant_name' => (string) $row['variant_name'],
                'quantity' => (int) $row['quantity'],
                'unit_price' => (float) $row['unit_price'],
                'unit_mrp' => (float) $row['unit_mrp'],
                'sku' => (string) $row['sku'],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @param list<array<string, mixed>> $orderItems
     * @return array<string, mixed>
     */
    private static function formatOrderHeader(array $r, array $orderItems): array
    {
        $chargesMeta = null;
        if (isset($r['charges_metadata']) && is_string($r['charges_metadata']) && $r['charges_metadata'] !== '') {
            $d = json_decode($r['charges_metadata'], true);
            $chargesMeta = is_array($d) ? $d : null;
        }

        $deliveryDate = $r['delivery_date'] ?? null;
        $deliveryDateStr = $deliveryDate !== null && $deliveryDate !== '' ? (string) $deliveryDate : '';

        $deliveredAt = $r['delivered_at'] ?? null;

        return [
            'id' => (string) $r['id'],
            'order_status' => (string) $r['order_status'],
            'payment_status' => (string) $r['payment_status'],
            'delivery_date' => $deliveryDateStr,
            'delivery_slot' => $r['delivery_slot'] !== null && $r['delivery_slot'] !== '' ? (string) $r['delivery_slot'] : '',
            'delivery_type' => $r['delivery_type'] !== null && $r['delivery_type'] !== '' ? (string) $r['delivery_type'] : '',
            'total_mrp' => (float) $r['total_mrp'],
            'tax' => (float) $r['tax'],
            'delivery_fee' => (float) $r['delivery_fee'],
            'grand_total' => (float) $r['grand_total'],
            'currency' => (string) $r['currency'],
            'recipient_name' => (string) $r['recipient_name'],
            'recipient_phone' => (string) $r['recipient_phone'],
            'full_address' => (string) $r['full_address'],
            'city' => (string) $r['city'],
            'state' => (string) $r['state'],
            'country' => (string) $r['country'],
            'postal_code' => (string) $r['postal_code'],
            'total_price' => (float) $r['total_price'],
            'total_charges' => (float) $r['total_charges'],
            'gateway_order_id' => $r['gateway_order_id'] !== null && $r['gateway_order_id'] !== '' ? (string) $r['gateway_order_id'] : null,
            'gateway_name' => $r['gateway_name'] !== null && $r['gateway_name'] !== '' ? (string) $r['gateway_name'] : null,
            'order_items' => $orderItems,
            'created_at' => (string) $r['created_at'],
            'delivered_at' => $deliveredAt !== null && $deliveredAt !== '' ? (string) $deliveredAt : null,
            'charges_metadata' => $chargesMeta,
        ];
    }
}
