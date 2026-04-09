<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDOException;
use PDO;

final class OrderRepository
{
    private static function prefixFromIndex(int $idx): string
    {
        // Base-26 (A-Z) into 4 letters. 0 => AAAA, 1 => AAAB, ...
        $n = $idx;
        $letters = ['A', 'A', 'A', 'A'];
        for ($pos = 3; $pos >= 0; $pos--) {
            $letters[$pos] = chr(ord('A') + ($n % 26));
            $n = intdiv($n, 26);
        }
        return implode('', $letters);
    }

    private static function formatOrderCodeFromNumber(int $n): string
    {
        // 1 => AAAA-000001, 999999 => AAAA-999999, 1000000 => AAAB-000001, etc.
        $perPrefix = 999999;
        $group = intdiv($n - 1, $perPrefix);
        $within = (($n - 1) % $perPrefix) + 1;

        return self::prefixFromIndex($group) . '-' . str_pad((string) $within, 6, '0', STR_PAD_LEFT);
    }

    private static function nextOrderCode(PDO $pdo): string
    {
        // Atomically increments a single-row counter and returns the next code.
        // This must run inside the same transaction as order creation.
        $pdo->exec('INSERT IGNORE INTO order_code_sequence (id, last_number) VALUES (1, 0)');

        // Trick: LAST_INSERT_ID(expr) lets us read the updated value reliably on this connection.
        $stmt = $pdo->prepare('UPDATE order_code_sequence SET last_number = LAST_INSERT_ID(last_number + 1) WHERE id = 1');
        $stmt->execute();

        $n = (int) $pdo->lastInsertId();
        if ($n < 1) {
            // Extremely defensive fallback (should never happen).
            $n = 1;
        }

        return self::formatOrderCodeFromNumber($n);
    }

    public static function insertOrder(
        string $id,
        string $userId,
        ?string $cartId,
        ?string $addressId,
        ?string $addressLabel,
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

        $pdo = Database::connection();
        $orderCode = self::nextOrderCode($pdo);

        $sql = 'INSERT INTO orders (id, order_code, user_id, cart_id, address_id, address_label, order_status, payment_status, delivery_date, delivery_slot,
                delivery_type, total_mrp, tax, delivery_fee, grand_total, currency, recipient_name, recipient_phone, full_address,
                city, state, country, postal_code, total_price, total_charges, gateway_order_id, gateway_name, charges_metadata)
             VALUES (:id, :code, :uid, :cid, :aid, :al, :os, :ps, :dd, :ds, :dt, :tm, :tx, :df, :gt, :cur, :rn, :rp, :fa, :city, :st, :ctry, :pc,
                :tp, :tc, :go, :gn, :cm)';

        for ($attempt = 0; $attempt < 8; $attempt++) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'id' => $id,
                    'code' => $orderCode,
                    'uid' => $userId,
                    'cid' => $cartId,
                    'aid' => $addressId,
                    'al' => $addressLabel,
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

                return;
            } catch (PDOException $e) {
                // Duplicate order_code: retry with next sequence value.
                if ($e->getCode() === '23000') {
                    $orderCode = self::nextOrderCode($pdo);
                    continue;
                }
                throw $e;
            }
        }

        // Last attempt (let exception bubble if it still fails).
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'code' => self::nextOrderCode($pdo),
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
            'SELECT o.*, u.phone AS customer_phone, u.email AS customer_email, u.full_name AS customer_full_name
             FROM orders o
             LEFT JOIN users u ON u.id = o.user_id
             WHERE o.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
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

        return $base;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function findAllForAdmin(
        int $offset,
        int $limit,
        ?string $paymentStatus,
        ?string $orderStatus,
        ?string $dateYmd = null,
        bool $withItems = false
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
        if ($dateYmd !== null && $dateYmd !== '') {
            $where[] = 'o.created_at >= :dfrom AND o.created_at < :dto';
            $params['dfrom'] = $dateYmd . ' 00:00:00';
            $params['dto'] = date('Y-m-d', strtotime($dateYmd . ' +1 day')) . ' 00:00:00';
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
            $base = $withItems ? self::formatOrderWithItems($orderOnly) : self::formatOrderHeader($orderOnly, []);
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

    public static function countAllForAdmin(?string $paymentStatus, ?string $orderStatus, ?string $dateYmd = null): int
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
        if ($dateYmd !== null && $dateYmd !== '') {
            $where[] = 'created_at >= :dfrom AND created_at < :dto';
            $params['dfrom'] = $dateYmd . ' 00:00:00';
            $params['dto'] = date('Y-m-d', strtotime($dateYmd . ' +1 day')) . ' 00:00:00';
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
     * Admin: deliverable orders for a delivery service view.
     *
     * Default definition (when $orderStatuses is null): paid orders that are not yet delivered.
     *
     * @param list<string>|null $orderStatuses
     * @return list<array<string, mixed>>
     */
    public static function findDeliverableOrdersForAdmin(?string $deliveryDateYmd, ?array $orderStatuses, bool $includeDelivered): array
    {
        $where = ['1=1'];
        $params = [];

        // Paid orders only.
        $where[] = 'o.payment_status = :ps';
        $params['ps'] = 'success';

        if ($deliveryDateYmd !== null && $deliveryDateYmd !== '') {
            $where[] = 'o.delivery_date = :dd';
            $params['dd'] = $deliveryDateYmd;
        }

        if (!$includeDelivered) {
            $where[] = '(o.delivered_at IS NULL OR o.delivered_at = \'\')';
        }

        if ($orderStatuses !== null && $orderStatuses !== []) {
            $in = [];
            foreach (array_values($orderStatuses) as $i => $st) {
                $k = 'os' . $i;
                $in[] = ':' . $k;
                $params[$k] = $st;
            }
            $where[] = 'o.order_status IN (' . implode(', ', $in) . ')';
        } else {
            // Default deliverable statuses.
            // Backward compatible: older rows may still use 'picked' for the same meaning as 'packed'.
            $where[] = 'o.order_status IN (\'placed\', \'packed\', \'picked\', \'processing\', \'shipped\')';
        }

        $w = implode(' AND ', $where);
        $sql = "SELECT o.id, o.order_code, o.order_status, o.payment_status, o.delivery_date, o.delivery_slot, o.delivery_type,
                       o.grand_total, o.currency, o.recipient_name, o.recipient_phone, o.full_address,
                       o.address_label AS address_label,
                       o.city, o.state, o.country, o.postal_code, o.created_at, o.delivered_at,
                       COALESCE(oi_agg.line_count, 0) AS item_lines,
                       COALESCE(oi_agg.total_qty, 0) AS item_qty,
                       COALESCE(dic_agg.reviewed_lines, 0) AS reviewed_lines,
                       COALESCE(dic_agg.issue_lines, 0) AS issue_lines,
                       COALESCE(dic_agg.picked_lines, 0) AS picked_lines,
                       COALESCE(dic_agg.short_lines, 0) AS short_lines,
                       COALESCE(dic_agg.missing_lines, 0) AS missing_lines,
                       COALESCE(dic_agg.not_available_lines, 0) AS not_available_lines,
                       u.phone AS customer_phone, u.email AS customer_email, u.full_name AS customer_full_name
                FROM orders o
                LEFT JOIN users u ON u.id = o.user_id
                LEFT JOIN (
                    SELECT order_id, COUNT(*) AS line_count, SUM(quantity) AS total_qty
                    FROM order_items
                    GROUP BY order_id
                ) oi_agg ON oi_agg.order_id = o.id
                LEFT JOIN (
                    SELECT
                        oi.order_id AS order_id,
                        SUM(CASE WHEN dic.status IS NOT NULL AND dic.status != '' AND dic.status != 'pending' THEN 1 ELSE 0 END) AS reviewed_lines,
                        SUM(CASE WHEN dic.status IN ('short', 'missing', 'not_available') THEN 1 ELSE 0 END) AS issue_lines,
                        SUM(CASE WHEN dic.status = 'picked' THEN 1 ELSE 0 END) AS picked_lines,
                        SUM(CASE WHEN dic.status = 'short' THEN 1 ELSE 0 END) AS short_lines,
                        SUM(CASE WHEN dic.status = 'missing' THEN 1 ELSE 0 END) AS missing_lines,
                        SUM(CASE WHEN dic.status = 'not_available' THEN 1 ELSE 0 END) AS not_available_lines
                    FROM order_items oi
                    LEFT JOIN delivery_item_checks dic ON dic.order_item_id = oi.id
                    GROUP BY oi.order_id
                ) dic_agg ON dic_agg.order_id = o.id
                WHERE {$w}
                ORDER BY o.delivery_date ASC, o.created_at ASC, o.id ASC";

        $stmt = Database::connection()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $orderOnly = self::stripJoinedOrderRow($row);
            $base = self::formatOrderHeader($orderOnly, []);
            $base['address_label'] = isset($row['address_label']) && $row['address_label'] !== '' ? (string) $row['address_label'] : '';
            $base['customer'] = [
                'phone' => isset($row['customer_phone']) && $row['customer_phone'] !== '' ? (string) $row['customer_phone'] : null,
                'email' => isset($row['customer_email']) && $row['customer_email'] !== '' ? (string) $row['customer_email'] : null,
                'full_name' => isset($row['customer_full_name']) && $row['customer_full_name'] !== '' ? (string) $row['customer_full_name'] : null,
            ];
            $base['item_lines'] = (int) ($row['item_lines'] ?? 0);
            $base['item_qty'] = (int) ($row['item_qty'] ?? 0);
            $base['reviewed_lines'] = (int) ($row['reviewed_lines'] ?? 0);
            $base['issue_lines'] = (int) ($row['issue_lines'] ?? 0);
            $base['picked_lines'] = (int) ($row['picked_lines'] ?? 0);
            $base['short_lines'] = (int) ($row['short_lines'] ?? 0);
            $base['missing_lines'] = (int) ($row['missing_lines'] ?? 0);
            $base['not_available_lines'] = (int) ($row['not_available_lines'] ?? 0);
            $out[] = $base;
        }

        return $out;
    }

    /**
     * Admin: fetch order items for many orders in one query and map to variants by SKU (best-effort).
     *
     * @param list<string> $orderIds
     * @return list<array<string, mixed>>
     */
    public static function findOrderItemsForOrdersWithVariantId(array $orderIds): array
    {
        $ids = array_values(array_filter(array_map('trim', $orderIds), static fn ($x) => $x !== ''));
        if ($ids === []) return [];

        // Build placeholders.
        $ph = implode(', ', array_fill(0, count($ids), '?'));
        $sql = "SELECT oi.order_id, oi.id, oi.image, oi.brand_name, oi.product_name, oi.variant_name, oi.quantity, oi.unit_price, oi.unit_mrp, oi.sku,
                       v.id AS variant_id,
                       i.quantity AS inv_quantity,
                       i.reserved_quantity AS inv_reserved,
                       dic.status AS check_status,
                       dic.picked_quantity AS picked_quantity,
                       dic.note AS check_note,
                       dic.updated_at AS check_updated_at
                FROM order_items oi
                LEFT JOIN variants v ON v.sku = oi.sku
                LEFT JOIN inventory i ON i.variant_id = v.id
                LEFT JOIN delivery_item_checks dic ON dic.order_item_id = oi.id
                WHERE oi.order_id IN ({$ph})
                ORDER BY oi.order_id ASC, oi.created_at ASC, oi.id ASC";
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $out[] = [
                'order_id' => (string) ($row['order_id'] ?? ''),
                'id' => (string) ($row['id'] ?? ''),
                'image' => (string) ($row['image'] ?? ''),
                'brand_name' => (string) ($row['brand_name'] ?? ''),
                'product_name' => (string) ($row['product_name'] ?? ''),
                'variant_name' => (string) ($row['variant_name'] ?? ''),
                'quantity' => (int) ($row['quantity'] ?? 0),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'unit_mrp' => (float) ($row['unit_mrp'] ?? 0),
                'sku' => (string) ($row['sku'] ?? ''),
                'variant_id' => isset($row['variant_id']) && $row['variant_id'] !== '' ? (string) $row['variant_id'] : null,
                'inv_quantity' => isset($row['inv_quantity']) ? (int) $row['inv_quantity'] : null,
                'inv_reserved_quantity' => isset($row['inv_reserved']) ? (int) $row['inv_reserved'] : null,
                'check_status' => isset($row['check_status']) && $row['check_status'] !== '' ? (string) $row['check_status'] : null,
                'picked_quantity' => isset($row['picked_quantity']) ? (int) $row['picked_quantity'] : null,
                'check_note' => isset($row['check_note']) && $row['check_note'] !== '' ? (string) $row['check_note'] : null,
                'check_updated_at' => isset($row['check_updated_at']) && $row['check_updated_at'] !== ''
                    ? (string) $row['check_updated_at'] : null,
            ];
        }

        return $out;
    }

    /**
     * Admin: upsert delivery checks for order items.
     *
     * @param list<array{order_item_id: string, status: string, picked_quantity: int, note: string|null}> $checks
     */
    public static function upsertDeliveryItemChecks(string $orderId, array $checks): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO delivery_item_checks (id, order_id, order_item_id, status, picked_quantity, note)
             VALUES (:id, :oid, :oiid, :st, :pq, :note)
             ON DUPLICATE KEY UPDATE status = VALUES(status), picked_quantity = VALUES(picked_quantity), note = VALUES(note)'
        );

        foreach ($checks as $c) {
            $stmt->execute([
                'id' => \App\Core\Uuid::v4(),
                'oid' => $orderId,
                'oiid' => $c['order_item_id'],
                'st' => $c['status'],
                'pq' => (int) $c['picked_quantity'],
                'note' => $c['note'],
            ]);
        }
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
            'order_code' => isset($r['order_code']) && $r['order_code'] !== '' ? (string) $r['order_code'] : null,
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
