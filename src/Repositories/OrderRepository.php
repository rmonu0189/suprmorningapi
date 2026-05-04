<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Uuid;
use App\Core\Exceptions\HttpException;
use PDOException;
use PDO;

final class OrderRepository
{
    /**
     * @param list<array<string,mixed>>|null $chargesMeta
     * @return list<array{id:string,index:int,title:string,amount:float,min_order_value:?float,applied_amount:float,info:?string}>
     */
    private static function normalizeBillCharges(?array $chargesMeta, float $tax, float $deliveryFee): array
    {
        $out = [];
        if (is_array($chargesMeta)) {
            foreach ($chargesMeta as $idx => $row) {
                if (!is_array($row)) continue;
                $title = isset($row['title']) ? trim((string) $row['title']) : '';
                if ($title === '') continue;
                $value = isset($row['value']) ? (float) $row['value'] : 0.0;
                $out[] = [
                    'id' => 'order_charge_' . $idx,
                    'index' => $idx,
                    'title' => $title,
                    'amount' => $value,
                    'min_order_value' => null,
                    'applied_amount' => $value,
                    'info' => null,
                ];
            }
        }

        if ($out !== []) {
            return $out;
        }

        // Legacy fallback for old orders where charges_metadata was not stored.
        if ($deliveryFee > 0) {
            $out[] = [
                'id' => 'order_charge_delivery',
                'index' => 0,
                'title' => 'Delivery charges',
                'amount' => $deliveryFee,
                'min_order_value' => null,
                'applied_amount' => $deliveryFee,
                'info' => null,
            ];
        }
        if ($tax > 0) {
            $out[] = [
                'id' => 'order_charge_tax',
                'index' => count($out),
                'title' => 'Tax',
                'amount' => $tax,
                'min_order_value' => null,
                'applied_amount' => $tax,
                'info' => null,
            ];
        }
        return $out;
    }

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
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pdo->exec('INSERT IGNORE INTO order_code_sequence (id, last_number) VALUES (1, 0)');

        if ($driver === 'sqlite') {
            // MySQL LAST_INSERT_ID(expr) is not available; increment then read.
            $pdo->exec('UPDATE order_code_sequence SET last_number = last_number + 1 WHERE id = 1');
            $stmt = $pdo->query('SELECT last_number FROM order_code_sequence WHERE id = 1');
            $n = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
            if ($n < 1) {
                $n = 1;
            }

            return self::formatOrderCodeFromNumber($n);
        }

        // MySQL: LAST_INSERT_ID(expr) reads the updated counter on this connection.
        $stmt = $pdo->prepare('UPDATE order_code_sequence SET last_number = LAST_INSERT_ID(last_number + 1) WHERE id = 1');
        $stmt->execute();

        $n = (int) $pdo->lastInsertId();
        if ($n < 1) {
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
        ?int $warehouseId,
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
        float $latitude,
        float $longitude,
        float $totalPrice,
        float $totalCharges,
        ?string $couponCode,
        float $couponDiscount,
        ?string $gatewayOrderId,
        ?string $gatewayName,
        ?array $chargesMetadata
    ): void {
        $metaJson = $chargesMetadata !== null ? json_encode($chargesMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null;

        $pdo = Database::connection();
        $orderCode = self::nextOrderCode($pdo);

        $sql = 'INSERT INTO orders (id, order_code, user_id, cart_id, address_id, address_label, warehouse_id, order_status, payment_status, delivery_date, delivery_slot,
                delivery_type, total_mrp, tax, delivery_fee, grand_total, currency, recipient_name, recipient_phone, full_address,
                city, state, country, postal_code, latitude, longitude, total_price, total_charges, coupon_code, coupon_discount, gateway_order_id, gateway_name, charges_metadata)
             VALUES (:id, :code, :uid, :cid, :aid, :al, :wid, :os, :ps, :dd, :ds, :dt, :tm, :tx, :df, :gt, :cur, :rn, :rp, :fa, :city, :st, :ctry, :pc, :lat, :lng,
                :tp, :tc, :cc, :cd, :go, :gn, :cm)';

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
                    'wid' => $warehouseId,
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
                    'lat' => $latitude,
                    'lng' => $longitude,
                    'tp' => $totalPrice,
                    'tc' => $totalCharges,
                    'cc' => $couponCode,
                    'cd' => $couponDiscount,
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
            'al' => $addressLabel,
            'wid' => $warehouseId,
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
            'lat' => $latitude,
            'lng' => $longitude,
            'tp' => $totalPrice,
            'tc' => $totalCharges,
            'cc' => $couponCode,
            'cd' => $couponDiscount,
            'go' => $gatewayOrderId,
            'gn' => $gatewayName,
            'cm' => $metaJson,
        ]);
    }

    public static function subscriptionOrderExistsForUserOnDate(string $userId, string $deliveryDateYmd): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1
             FROM orders
             WHERE user_id = :uid
               AND delivery_date = :dd
               AND gateway_order_id LIKE :go_like
             LIMIT 1'
        );
        $stmt->execute([
            'uid' => $userId,
            'dd' => $deliveryDateYmd,
            'go_like' => 'sub_%',
        ]);
        $v = $stmt->fetchColumn();
        return $v !== false;
    }

    public static function updateWarehouseId(string $orderId, ?int $warehouseId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE orders SET warehouse_id = :wid WHERE id = :id'
        );
        $stmt->execute(['wid' => $warehouseId, 'id' => $orderId]);
    }

    public static function findWarehouseIdForOrder(string $orderId): ?int
    {
        $stmt = Database::connection()->prepare(
            'SELECT warehouse_id FROM orders WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null || $v === '') return null;
        return (int) $v;
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

    public static function updateOrderKind(string $orderId, string $orderKind): void
    {
        $kind = strtolower(trim($orderKind));
        if ($kind === '') {
            $kind = 'user';
        }
        $stmt = Database::connection()->prepare(
            'UPDATE orders SET order_kind = :k WHERE id = :id'
        );
        $stmt->execute(['k' => $kind, 'id' => $orderId]);
    }

    public static function updatePaymentStatusByGatewayOrderId(string $gatewayOrderId, string $paymentStatus): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE orders SET payment_status = :ps WHERE gateway_order_id = :go'
        );
        $stmt->execute(['ps' => $paymentStatus, 'go' => $gatewayOrderId]);

        return $stmt->rowCount() > 0;
    }

    public static function updatePaymentStatusByOrderId(string $orderId, string $paymentStatus): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE orders SET payment_status = :ps WHERE id = :id'
        );
        $stmt->execute(['ps' => $paymentStatus, 'id' => $orderId]);
    }

    /**
     * Avoids clobbering a concurrent success transition; returns whether this call performed the update.
     */
    public static function updatePaymentStatusByOrderIdIfPending(string $orderId, string $paymentStatus): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE orders SET payment_status = :ps WHERE id = :id AND payment_status = \'pending\''
        );
        $stmt->execute(['ps' => $paymentStatus, 'id' => $orderId]);

        return $stmt->rowCount() > 0;
    }

    /** @return array<string, mixed>|null */
    public static function findRawByGatewayOrderId(string $gatewayOrderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, payment_status, grand_total, gateway_order_id
             FROM orders
             WHERE gateway_order_id = :go
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['go' => $gatewayOrderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function findForUserExcludingPayment(string $userId, int $offset, int $limit): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT o.*,
                    EXISTS (
                        SELECT 1
                        FROM order_item_ratings oir
                        WHERE oir.order_id = o.id AND oir.user_id = o.user_id
                        LIMIT 1
                    ) OR EXISTS (
                        SELECT 1
                        FROM order_delivery_ratings odr
                        WHERE odr.order_id = o.id AND odr.user_id = o.user_id
                        LIMIT 1
                    ) AS rated
             FROM orders o
             WHERE o.user_id = :uid AND o.payment_status != :pend
             ORDER BY o.created_at DESC, o.id DESC LIMIT :lim OFFSET :off'
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
        bool $withItems = false,
        ?int $warehouseId = null
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
        if ($warehouseId !== null) {
            $where[] = 'o.warehouse_id = :wid';
            $params['wid'] = (string) $warehouseId;
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

    public static function countAllForAdmin(?string $paymentStatus, ?string $orderStatus, ?string $dateYmd = null, ?int $warehouseId = null): int
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
        if ($warehouseId !== null) {
            $where[] = 'warehouse_id = :wid';
            $params['wid'] = (string) $warehouseId;
        }
        $w = implode(' AND ', $where);
        $stmt = Database::connection()->prepare("SELECT COUNT(*) AS c FROM orders WHERE {$w}");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? (int) ($row['c'] ?? 0) : 0;
    }

    /**
     * Admin: timeline of order_status changes (audit trail).
     *
     * @return list<array{
     *   id: string,
     *   order_id: string,
     *   status: string,
     *   note: string|null,
     *   created_at: string,
     *   changed_by: array{id: string, phone: string|null, email: string|null, full_name: string|null}|null
     * }>
     */
    public static function findStatusEventsForAdmin(string $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT e.id, e.order_id, e.status, e.note, e.created_at,
                    u.id AS user_id, u.phone AS user_phone, u.email AS user_email, u.full_name AS user_full_name
             FROM order_status_events e
             LEFT JOIN users u ON u.id = e.changed_by
             WHERE e.order_id = :oid
             ORDER BY e.created_at DESC, e.id DESC'
        );
        $stmt->execute(['oid' => $orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $changedBy = null;
            if (isset($row['user_id']) && is_string($row['user_id']) && $row['user_id'] !== '') {
                $changedBy = [
                    'id' => (string) $row['user_id'],
                    'phone' => isset($row['user_phone']) && $row['user_phone'] !== '' ? (string) $row['user_phone'] : null,
                    'email' => isset($row['user_email']) && $row['user_email'] !== '' ? (string) $row['user_email'] : null,
                    'full_name' => isset($row['user_full_name']) && $row['user_full_name'] !== '' ? (string) $row['user_full_name'] : null,
                ];
            }

            $out[] = [
                'id' => (string) ($row['id'] ?? ''),
                'order_id' => (string) ($row['order_id'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'note' => isset($row['note']) && $row['note'] !== '' ? (string) $row['note'] : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'changed_by' => $changedBy,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function findInvoiceFileForOrder(string $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT f.id, f.created_at, f.created_by, f.kind, f.storage_path, f.original_name, f.mime, f.size_bytes, f.access_key, f.is_active,
                    o.invoice_number
             FROM orders o
             INNER JOIN files f ON f.id = o.invoice_file_id
             WHERE o.id = :id AND f.is_active = 1
             LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function attachInvoice(string $orderId, string $fileId, string $invoiceNumber): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE orders
             SET invoice_file_id = :fid,
                 invoice_number = :num,
                 invoice_generated_at = CURRENT_TIMESTAMP,
                 invoice_status = \'generated\',
                 invoice_error = NULL
             WHERE id = :id AND invoice_file_id IS NULL'
        );
        $stmt->execute([
            'fid' => $fileId,
            'num' => $invoiceNumber,
            'id' => $orderId,
        ]);
    }

    public static function markInvoicePending(string $orderId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE orders
             SET invoice_status = \'pending\', invoice_error = NULL
             WHERE id = :id AND invoice_file_id IS NULL'
        );
        $stmt->execute(['id' => $orderId]);
    }

    public static function markInvoiceFailed(string $orderId, string $error): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE orders
             SET invoice_status = \'failed\',
                 invoice_error = :err,
                 invoice_attempts = COALESCE(invoice_attempts, 0) + 1
             WHERE id = :id AND invoice_file_id IS NULL'
        );
        $stmt->execute([
            'id' => $orderId,
            'err' => substr($error, 0, 1000),
        ]);
    }

    public static function updateAdminFulfillment(
        string $orderId,
        ?string $orderStatus,
        bool $orderStatusProvided,
        ?string $deliveredAtSql,
        bool $deliveredAtProvided,
        ?string $changedByUserId = null
    ): void {
        $pdo = Database::connection();
        $notifyDelivered = null;
        $generateInvoiceOrderId = null;
        $pdo->beginTransaction();
        try {
            $prevStatus = null;
            $prevDeductedAt = null;
            $userId = null;
            $orderCode = '';
            if ($orderStatusProvided) {
                $stmtPrev = $pdo->prepare('SELECT order_status, stock_deducted_at, user_id, order_code FROM orders WHERE id = :id LIMIT 1');
                $stmtPrev->execute(['id' => $orderId]);
                $row = $stmtPrev->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $prevStatus = isset($row['order_status']) && is_string($row['order_status']) ? $row['order_status'] : null;
                    $prevDeductedAt = $row['stock_deducted_at'] ?? null;
                    $userId = isset($row['user_id']) ? (string) $row['user_id'] : null;
                    $orderCode = isset($row['order_code']) ? (string) $row['order_code'] : '';
                }
            }

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
            if ($sets !== []) {
                $sql = 'UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = :id';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }

            if ($orderStatusProvided) {
                $newStatus = $orderStatus ?? 'created';
                if ($prevStatus === null || $prevStatus !== $newStatus) {
                    $stmtEvt = $pdo->prepare(
                        'INSERT INTO order_status_events (id, order_id, status, changed_by, note)
                         VALUES (:id, :oid, :st, :cb, :note)'
                    );
                    $stmtEvt->execute([
                        'id' => Uuid::v4(),
                        'oid' => $orderId,
                        'st' => $newStatus,
                        'cb' => $changedByUserId,
                        'note' => null,
                    ]);

                    if ($newStatus === 'delivered' && $userId !== null) {
                        self::markInvoicePending($orderId);
                        $generateInvoiceOrderId = $orderId;
                        $notifyDelivered = [
                            'user_id' => $userId,
                            'order_code' => $orderCode !== '' ? $orderCode : substr($orderId, 0, 8),
                            'order_id' => $orderId,
                        ];
                    }
                }

                // When order is marked packed, deduct stock once (idempotent).
                if ($newStatus === 'packed' && ($prevDeductedAt === null || $prevDeductedAt === '')) {
                    self::deductInventoryForPackedOrder($pdo, $orderId, $changedByUserId);
                }
            }

            $pdo->commit();

            if ($generateInvoiceOrderId !== null) {
                \App\Services\InvoiceService::tryGenerateForDeliveredOrder($generateInvoiceOrderId);
            }

            if (is_array($notifyDelivered)) {
                \App\Services\PushNotificationService::notifyOrderDelivered(
                    (string) $notifyDelivered['user_id'],
                    (string) $notifyDelivered['order_code'],
                    (string) $notifyDelivered['order_id']
                );
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function deductInventoryForPackedOrder(PDO $pdo, string $orderId, ?string $changedByUserId): void
    {
        // Load warehouse_id (0 for legacy bucket).
        $stmtO = $pdo->prepare('SELECT warehouse_id, stock_deducted_at FROM orders WHERE id = :id LIMIT 1');
        $stmtO->execute(['id' => $orderId]);
        $o = $stmtO->fetch(PDO::FETCH_ASSOC);
        if (!is_array($o)) {
            throw new HttpException('Order not found', 404);
        }
        if (isset($o['stock_deducted_at']) && $o['stock_deducted_at'] !== null && $o['stock_deducted_at'] !== '') {
            return; // already deducted
        }
        $wid = isset($o['warehouse_id']) && $o['warehouse_id'] !== null && $o['warehouse_id'] !== '' ? (int) $o['warehouse_id'] : 0;

        // Aggregate quantities by variant_id via sku join (order_items doesn't store variant_id).
        $stmtItems = $pdo->prepare(
            'SELECT v.id AS variant_id, SUM(oi.quantity) AS qty
             FROM order_items oi
             INNER JOIN variants v ON v.sku = oi.sku
             WHERE oi.order_id = :oid
             GROUP BY v.id'
        );
        $stmtItems->execute(['oid' => $orderId]);
        $rows = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            return;
        }

        // Deduct each variant atomically with a non-negative guard.
        $stmtUpd = $pdo->prepare(
            'UPDATE inventory
             SET quantity = quantity - :q1
             WHERE warehouse_id = :wid AND variant_id = :vid AND quantity >= :q2'
        );
        $stmtMove = $pdo->prepare(
            'INSERT INTO inventory_movements (id, warehouse_id, variant_id, delta_quantity, note, created_by)
             VALUES (:id, :wid, :vid, :dq, :note, :cb)'
        );
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $vid = (string) ($r['variant_id'] ?? '');
            $qty = (int) ($r['qty'] ?? 0);
            if ($vid === '' || $qty <= 0) continue;

            $stmtUpd->execute(['q1' => $qty, 'q2' => $qty, 'wid' => $wid, 'vid' => $vid]);
            if ($stmtUpd->rowCount() < 1) {
                throw new HttpException('Insufficient inventory to pack this order', 409, [
                    'errors' => ['inventory' => 'Insufficient inventory to pack this order.'],
                ]);
            }
            $stmtMove->execute([
                'id' => Uuid::v4(),
                'wid' => $wid,
                'vid' => $vid,
                'dq' => -$qty,
                'note' => 'Deducted for packed order ' . $orderId,
                'cb' => $changedByUserId,
            ]);
        }

        // Mark order as deducted.
        $stmtMark = $pdo->prepare('UPDATE orders SET stock_deducted_at = CURRENT_TIMESTAMP WHERE id = :id AND stock_deducted_at IS NULL');
        $stmtMark->execute(['id' => $orderId]);
    }

    /**
     * Admin: deliverable orders for a delivery service view.
     *
     * Default definition (when $orderStatuses is null): paid orders that are not yet delivered.
     *
     * @param list<string>|null $orderStatuses
     * @return list<array<string, mixed>>
     */
    public static function findDeliverableOrdersForAdmin(
        ?string $deliveryDateYmd,
        ?array $orderStatuses,
        bool $includeDelivered,
        ?int $warehouseId = null,
        ?string $deliveryDateFromYmd = null,
        ?string $deliveryDateToYmd = null
    ): array
    {
        $where = ['1=1'];
        $params = [];

        // Paid orders only.
        $where[] = 'o.payment_status = :ps';
        $params['ps'] = 'success';

        if ($deliveryDateFromYmd !== null && $deliveryDateFromYmd !== '' && $deliveryDateToYmd !== null && $deliveryDateToYmd !== '') {
            $where[] = 'o.delivery_date >= :dd_from AND o.delivery_date <= :dd_to';
            $params['dd_from'] = $deliveryDateFromYmd;
            $params['dd_to'] = $deliveryDateToYmd;
        } elseif ($deliveryDateFromYmd !== null && $deliveryDateFromYmd !== '') {
            $where[] = 'o.delivery_date >= :dd_from';
            $params['dd_from'] = $deliveryDateFromYmd;
        } elseif ($deliveryDateToYmd !== null && $deliveryDateToYmd !== '') {
            $where[] = 'o.delivery_date <= :dd_to';
            $params['dd_to'] = $deliveryDateToYmd;
        } elseif ($deliveryDateYmd !== null && $deliveryDateYmd !== '') {
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

        if ($warehouseId !== null) {
            $where[] = 'o.warehouse_id = :wid';
            $params['wid'] = (string) $warehouseId;
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
                INNER JOIN orders o ON o.id = oi.order_id
                LEFT JOIN variants v ON v.sku = oi.sku
                LEFT JOIN inventory i ON i.variant_id = v.id AND i.warehouse_id = COALESCE(o.warehouse_id, 0)
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
            'SELECT o.*,
                    EXISTS (
                        SELECT 1
                        FROM order_item_ratings oir
                        WHERE oir.order_id = o.id AND oir.user_id = o.user_id
                        LIMIT 1
                    ) OR EXISTS (
                        SELECT 1
                        FROM order_delivery_ratings odr
                        WHERE odr.order_id = o.id AND odr.user_id = o.user_id
                        LIMIT 1
                    ) AS rated
             FROM orders o
             WHERE o.id = :id AND o.user_id = :uid
             LIMIT 1'
        );
        $stmt->execute(['id' => $orderId, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::formatOrderWithItems($row);
    }

    public static function findWarehouseIdForOrderAndUser(string $orderId, string $userId): ?int
    {
        $stmt = Database::connection()->prepare(
            'SELECT warehouse_id FROM orders WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $orderId, 'uid' => $userId]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null || $v === '') return null;
        return (int) $v;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function findReorderEvaluationRows(string $orderId, int $warehouseId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT
                oi.id AS order_item_id,
                oi.sku,
                oi.quantity AS ordered_quantity,
                v.id AS variant_id,
                v.status AS variant_status,
                v.price AS current_price,
                v.mrp AS current_mrp,
                p.status AS product_status,
                b.status AS brand_status,
                i.quantity AS inv_quantity,
                i.reserved_quantity AS inv_reserved
             FROM order_items oi
             LEFT JOIN variants v ON v.sku = oi.sku
             LEFT JOIN products p ON p.id = v.product_id
             LEFT JOIN brands b ON b.id = p.brand_id
             LEFT JOIN inventory i ON i.variant_id = v.id AND i.warehouse_id = :wid
             WHERE oi.order_id = :oid
             ORDER BY oi.created_at ASC, oi.id ASC'
        );
        $stmt->execute([
            'oid' => $orderId,
            'wid' => $warehouseId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];
        return array_values(array_filter($rows, static fn ($r) => is_array($r)));
    }

    /** @return array<string, mixed>|null */
    public static function findByGatewayForUser(string $gatewayOrderId, string $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT o.*,
                    EXISTS (
                        SELECT 1
                        FROM order_item_ratings oir
                        WHERE oir.order_id = o.id AND oir.user_id = o.user_id
                        LIMIT 1
                    ) OR EXISTS (
                        SELECT 1
                        FROM order_delivery_ratings odr
                        WHERE odr.order_id = o.id AND odr.user_id = o.user_id
                        LIMIT 1
                    ) AS rated
             FROM orders o
             WHERE o.gateway_order_id = :go AND o.user_id = :uid
             ORDER BY o.created_at DESC, o.id DESC LIMIT 1'
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

    public static function findPaymentStatusByGatewayOrderIdForUser(string $gatewayOrderId, string $userId): string
    {
        $stmt = Database::connection()->prepare(
            'SELECT payment_status FROM orders WHERE gateway_order_id = :go AND user_id = :uid
             ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['go' => $gatewayOrderId, 'uid' => $userId]);
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
        $totalMrp = (float) $r['total_mrp'];
        $totalPrice = (float) $r['total_price'];
        $totalCharges = (float) $r['total_charges'];
        $grandTotal = (float) $r['grand_total'];
        $couponDiscount = isset($r['coupon_discount'])
            ? (float) $r['coupon_discount']
            : max(0.0, round(($totalPrice + $totalCharges) - $grandTotal, 2));
        $couponCode = isset($r['coupon_code']) && $r['coupon_code'] !== '' ? (string) $r['coupon_code'] : null;
        $tax = (float) $r['tax'];
        $deliveryFee = (float) $r['delivery_fee'];
        $billCharges = self::normalizeBillCharges($chargesMeta, $tax, $deliveryFee);
        $statusChangedAt = self::findLatestStatusChangedAtForOrder(
            (string) $r['id'],
            (string) $r['order_status'],
            (string) $r['created_at']
        );
        $deliveryAgent = self::findLatestOutForDeliveryAgent((string) $r['id']);

        return [
            'id' => (string) $r['id'],
            'order_code' => isset($r['order_code']) && $r['order_code'] !== '' ? (string) $r['order_code'] : null,
            'order_status' => (string) $r['order_status'],
            'payment_status' => (string) $r['payment_status'],
            'rated' => isset($r['rated']) ? ((int) $r['rated']) === 1 : false,
            'delivery_date' => $deliveryDateStr,
            'delivery_slot' => $r['delivery_slot'] !== null && $r['delivery_slot'] !== '' ? (string) $r['delivery_slot'] : '',
            'delivery_type' => $r['delivery_type'] !== null && $r['delivery_type'] !== '' ? (string) $r['delivery_type'] : '',
            'total_mrp' => $totalMrp,
            'tax' => $tax,
            'delivery_fee' => $deliveryFee,
            'grand_total' => $grandTotal,
            'currency' => (string) $r['currency'],
            'recipient_name' => (string) $r['recipient_name'],
            'recipient_phone' => (string) $r['recipient_phone'],
            'full_address' => (string) $r['full_address'],
            'city' => (string) $r['city'],
            'state' => (string) $r['state'],
            'country' => (string) $r['country'],
            'postal_code' => (string) $r['postal_code'],
            'total_price' => $totalPrice,
            'total_charges' => $totalCharges,
            'coupon_code' => $couponCode,
            'coupon_discount' => $couponDiscount,
            'gateway_order_id' => $r['gateway_order_id'] !== null && $r['gateway_order_id'] !== '' ? (string) $r['gateway_order_id'] : null,
            'gateway_name' => $r['gateway_name'] !== null && $r['gateway_name'] !== '' ? (string) $r['gateway_name'] : null,
            'order_items' => $orderItems,
            'created_at' => (string) $r['created_at'],
            'status_changed_at' => $statusChangedAt,
            'delivered_at' => $deliveredAt !== null && $deliveredAt !== '' ? (string) $deliveredAt : null,
            'invoice_status' => isset($r['invoice_status']) && $r['invoice_status'] !== '' ? (string) $r['invoice_status'] : null,
            'invoice_number' => isset($r['invoice_number']) && $r['invoice_number'] !== '' ? (string) $r['invoice_number'] : null,
            'invoice_generated_at' => isset($r['invoice_generated_at']) && $r['invoice_generated_at'] !== '' ? (string) $r['invoice_generated_at'] : null,
            'invoice_error' => isset($r['invoice_error']) && $r['invoice_error'] !== '' ? (string) $r['invoice_error'] : null,
            'invoice_attempts' => isset($r['invoice_attempts']) ? (int) $r['invoice_attempts'] : 0,
            'delivery_agent' => $deliveryAgent,
            'charges_metadata' => $chargesMeta,
            'bill_summary' => [
                'warehouse_id' => isset($r['warehouse_id']) && $r['warehouse_id'] !== null && $r['warehouse_id'] !== ''
                    ? (int) $r['warehouse_id']
                    : 0,
                'warehouse_source' => 'order_snapshot',
                'items_mrp' => $totalMrp,
                'items_price' => $totalPrice,
                'coupon_code' => $couponCode,
                'coupon_discount' => $couponDiscount,
                'charges' => $billCharges,
                'charges_total' => $totalCharges,
                'grand_total_price' => $grandTotal,
                'grand_total_mrp' => $totalMrp + $totalCharges,
            ],
            'payment_summary' => self::buildPaymentSummaryForOrder((string) $r['id'], $r),
            'payment_refs' => self::buildPaymentRefsForOrder((string) $r['id'], $r),
        ];
    }

    /**
     * Distinct gateway refs for wallet vs online (Razorpay order id). Mixed checkout has two legs after capture.
     *
     * @param array<string, mixed> $r
     * @return array{wallet_gateway_order_id: ?string, razorpay_gateway_order_id: ?string}
     */
    private static function buildPaymentRefsForOrder(string $orderId, array $r): array
    {
        $from = PaymentRepository::successfulGatewayRefsForOrder($orderId);
        $wallet = $from['wallet'];
        $rz = $from['razorpay'];
        $go = trim((string) ($r['gateway_order_id'] ?? ''));

        if ($wallet === null || $wallet === '') {
            $wallet = WalletRepository::findOrderDebitWalletRef($orderId);
        }
        if (($wallet === null || $wallet === '') && $go !== '' && str_starts_with($go, 'wallet_')) {
            $wallet = $go;
        }

        if (($rz === null || $rz === '') && $go !== '' && !str_starts_with($go, 'wallet_')) {
            $rz = $go;
        }

        return [
            'wallet_gateway_order_id' => $wallet !== null && $wallet !== '' ? $wallet : null,
            'razorpay_gateway_order_id' => $rz !== null && $rz !== '' ? $rz : null,
        ];
    }

    /**
     * Wallet vs Razorpay amounts for receipt-style UI. Mixed checkout may only store a Razorpay row;
     * wallet portion is inferred from grand_total when gateway_name is mixed.
     *
     * @param array<string, mixed> $r
     * @return array{grand_total_inr: float, wallet_inr: float, razorpay_inr: float}
     */
    private static function buildPaymentSummaryForOrder(string $orderId, array $r): array
    {
        $grandTotal = round((float) ($r['grand_total'] ?? 0), 2);
        $gwName = strtolower(trim((string) ($r['gateway_name'] ?? '')));
        $splits = PaymentRepository::sumSuccessfulByGatewayForOrder($orderId);
        $wallet = round($splits['wallet'], 2);
        $rz = round($splits['razorpay'], 2);

        if ($wallet < 0.005 && $gwName === 'wallet') {
            $wallet = $grandTotal;
        }
        if ($wallet < 0.005 && $gwName === 'mixed' && $rz > 0.005) {
            $wallet = max(0.0, round($grandTotal - $rz, 2));
        }

        return [
            'grand_total_inr' => $grandTotal,
            'wallet_inr' => $wallet,
            'razorpay_inr' => $rz,
        ];
    }

    private static function findLatestStatusChangedAtForOrder(string $orderId, string $status, string $fallbackCreatedAt): string
    {
        $st = trim($status);
        if ($st === '') {
            return $fallbackCreatedAt;
        }
        $stmt = Database::connection()->prepare(
            'SELECT created_at
             FROM order_status_events
             WHERE order_id = :oid AND status = :st
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'oid' => $orderId,
            'st' => $st,
        ]);
        $at = $stmt->fetchColumn();
        if (!is_string($at) || trim($at) === '') {
            return $fallbackCreatedAt;
        }
        return $at;
    }

    /**
     * @return array{id:string, full_name:string|null, phone:string|null}|null
     */
    private static function findLatestOutForDeliveryAgent(string $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.full_name, u.phone
             FROM order_status_events e
             LEFT JOIN users u ON u.id = e.changed_by
             WHERE e.order_id = :oid
               AND e.status IN (\'out_for_delivery\', \'out for delivery\')
               AND e.changed_by IS NOT NULL
             ORDER BY e.created_at DESC, e.id DESC
             LIMIT 1'
        );
        $stmt->execute(['oid' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !isset($row['id']) || !is_string($row['id']) || $row['id'] === '') {
            return null;
        }
        return [
            'id' => (string) $row['id'],
            'full_name' => isset($row['full_name']) && $row['full_name'] !== '' ? (string) $row['full_name'] : null,
            'phone' => isset($row['phone']) && $row['phone'] !== '' ? (string) $row['phone'] : null,
        ];
    }

    public static function countInProgressByUserId(string $userId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*)
             FROM orders
             WHERE user_id = :uid
               AND (
                 payment_status = :payment_pending
                 OR order_status IN (:created, :placed, :processing, :packed, :picked, :shipped, :out_for_delivery)
               )'
        );
        $stmt->execute([
            'uid' => $userId,
            'payment_pending' => 'pending',
            'created' => 'created',
            'placed' => 'placed',
            'processing' => 'processing',
            'packed' => 'packed',
            'picked' => 'picked',
            'shipped' => 'shipped',
            'out_for_delivery' => 'out_for_delivery',
        ]);
        return (int) $stmt->fetchColumn();
    }
}
