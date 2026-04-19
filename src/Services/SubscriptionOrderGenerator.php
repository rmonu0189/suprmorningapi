<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Uuid;
use App\Repositories\AddressRepository;
use App\Repositories\CartChargeRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\SubscriptionRepository;
use App\Repositories\WalletRepository;
use App\Repositories\WarehouseRepository;
use DateTimeImmutable;

final class SubscriptionOrderGenerator
{
    /** Default morning window until slot selection exists elsewhere. */
    private const DEFAULT_DELIVERY_SLOT = '5 to 7 AM';

    /**
     * Generate one "subscription" order per user for the given delivery date.
     *
     * Idempotent: if a subscription order already exists for the user+date, we skip.
     *
     * @return array{delivery_date: string, users_processed: int, users_created: int, orders_created: int, users_skipped_existing: int, users_skipped_no_items: int, users_skipped_no_address: int, users_skipped_insufficient_wallet: int}
     */
    public static function generateForDeliveryDate(DateTimeImmutable $deliveryDate, int $pageSize = 200): array
    {
        $pageSize = max(50, min(1000, $pageSize));
        $dateYmd = $deliveryDate->format('Y-m-d');
        $weekday = (int) $deliveryDate->format('w'); // 0=Sun..6=Sat (matches stored schedule)

        $usersProcessed = 0;
        $usersCreated = 0;
        $ordersCreated = 0;
        $usersSkippedExisting = 0;
        $usersSkippedNoItems = 0;
        $usersSkippedNoAddress = 0;
        $usersSkippedInsufficientWallet = 0;

        $offset = 0;
        while (true) {
            $userIds = SubscriptionRepository::findDistinctUserIdsPaged($offset, $pageSize);
            if ($userIds === []) {
                break;
            }
            $offset += count($userIds);

            foreach ($userIds as $userId) {
                $usersProcessed++;

                if (OrderRepository::subscriptionOrderExistsForUserOnDate($userId, $dateYmd)) {
                    $usersSkippedExisting++;
                    continue;
                }

                $subs = SubscriptionRepository::findAllByUser($userId);
                $variantQty = self::subscriptionVariantQuantitiesForDate($subs, $deliveryDate, $weekday);
                if ($variantQty === []) {
                    $usersSkippedNoItems++;
                    continue;
                }

                $address = AddressRepository::findFirstByUserId($userId);
                if ($address === null) {
                    $usersSkippedNoAddress++;
                    continue;
                }

                $created = self::createSubscriptionOrderForUser($userId, $address, $variantQty, $deliveryDate);
                if ($created['order_id'] !== null) {
                    $usersCreated++;
                    $ordersCreated++;
                    continue;
                }
                if ($created['failure'] === 'insufficient_wallet') {
                    $usersSkippedInsufficientWallet++;
                    continue;
                }
                $usersSkippedNoItems++;
            }
        }

        return [
            'delivery_date' => $dateYmd,
            'users_processed' => $usersProcessed,
            'users_created' => $usersCreated,
            'orders_created' => $ordersCreated,
            'users_skipped_existing' => $usersSkippedExisting,
            'users_skipped_no_items' => $usersSkippedNoItems,
            'users_skipped_no_address' => $usersSkippedNoAddress,
            'users_skipped_insufficient_wallet' => $usersSkippedInsufficientWallet,
        ];
    }

    /**
     * Generate (or verify) a subscription order for a single user for the given delivery date.
     *
     * @return array{status: 'success'|'skipped_no_items'|'skipped_no_address'|'skipped_insufficient_wallet', order_id: string|null, existing: bool, skip_detail: string|null}
     */
    public static function generateForUser(string $userId, DateTimeImmutable $deliveryDate): array
    {
        $dateYmd = $deliveryDate->format('Y-m-d');
        $weekday = (int) $deliveryDate->format('w');

        if (\App\Repositories\OrderRepository::subscriptionOrderExistsForUserOnDate($userId, $dateYmd)) {
            return ['status' => 'success', 'order_id' => null, 'existing' => true, 'skip_detail' => null];
        }

        $subs = SubscriptionRepository::findAllByUser($userId);
        $variantQty = self::subscriptionVariantQuantitiesForDate($subs, $deliveryDate, $weekday);
        if ($variantQty === []) {
            return ['status' => 'skipped_no_items', 'order_id' => null, 'existing' => false, 'skip_detail' => null];
        }

        $address = AddressRepository::findFirstByUserId($userId);
        if ($address === null) {
            return ['status' => 'skipped_no_address', 'order_id' => null, 'existing' => false, 'skip_detail' => null];
        }

        $created = self::createSubscriptionOrderForUser($userId, $address, $variantQty, $deliveryDate);
        if ($created['order_id'] !== null) {
            return ['status' => 'success', 'order_id' => $created['order_id'], 'existing' => false, 'skip_detail' => null];
        }
        if ($created['failure'] === 'insufficient_wallet') {
            return [
                'status' => 'skipped_insufficient_wallet',
                'order_id' => null,
                'existing' => false,
                'skip_detail' => $created['wallet_detail'],
            ];
        }

        return ['status' => 'skipped_no_items', 'order_id' => null, 'existing' => false, 'skip_detail' => null];
    }

    /**
     * @param list<array<string, mixed>> $subscriptions
     * @return array<string, int> map variant_id => quantity
     */
    private static function subscriptionVariantQuantitiesForDate(array $subscriptions, DateTimeImmutable $deliveryDate, int $weekday): array
    {
        $out = [];
        foreach ($subscriptions as $s) {
            $variantId = (string) ($s['variant_id'] ?? '');
            if ($variantId === '') {
                continue;
            }

            $startDateStr = (string) ($s['start_date'] ?? '');
            $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDateStr);
            if ($start === false) {
                continue;
            }
            if ($start->format('Y-m-d') > $deliveryDate->format('Y-m-d')) {
                continue; // not started yet
            }

            $freq = strtolower(trim((string) ($s['frequency'] ?? '')));
            $qty = 0;
            if ($freq === 'daily') {
                $qty = (int) ($s['quantity'] ?? 1);
            } elseif ($freq === 'alternate') {
                $days = (int) $start->diff($deliveryDate)->days;
                // Start date counts as day 0 => deliver on start date, then every other day.
                $qty = ($days % 2 === 0) ? (int) ($s['quantity'] ?? 1) : 0;
            } elseif ($freq === 'weekly') {
                $weekly = $s['weekly_schedule'] ?? null;
                if (is_array($weekly)) {
                    foreach ($weekly as $it) {
                        if (!is_array($it)) continue;
                        $day = isset($it['day']) ? (int) $it['day'] : -1;
                        if ($day !== $weekday) continue;
                        $q = isset($it['quantity']) ? (int) $it['quantity'] : 0;
                        if ($q > 0) {
                            $qty = $q;
                            break;
                        }
                    }
                }
            }

            if ($qty < 1) {
                continue;
            }

            $out[$variantId] = ($out[$variantId] ?? 0) + $qty;
        }

        // Remove any non-positive quantities (defensive).
        foreach ($out as $vid => $q) {
            if ($q < 1) {
                unset($out[$vid]);
            }
        }

        return $out;
    }

    /**
     * JSON for `subscription_order_generation.error` when status is skipped_insufficient_wallet.
     * `reason` is `precheck` (before txn) or `debit_failed` (wallet row changed / race).
     */
    private static function walletShortfallJson(float $requiredInr, float $availableInr, string $reason): string
    {
        $required = round($requiredInr, 2);
        $available = round($availableInr, 2);
        $shortfall = round(max(0.0, $required - $available), 2);
        $payload = [
            'currency' => 'INR',
            'required_inr' => $required,
            'available_inr' => $available,
            'shortfall_inr' => $shortfall,
            'reason' => $reason,
        ];
        $enc = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($enc) || $enc === '') {
            return '{"currency":"INR","reason":"' . $reason . '"}';
        }

        return $enc;
    }

    /**
     * @param array<string, mixed> $address
     * @param array<string, int> $variantQty map variant_id => quantity
     *
     * @return array{
     *   order_id: string|null,
     *   failure: null|'insufficient_wallet'|'no_lines'|'total_too_small',
     *   wallet_detail: string|null
     * }
     */
    private static function createSubscriptionOrderForUser(
        string $userId,
        array $address,
        array $variantQty,
        DateTimeImmutable $deliveryDate
    ): array {
        $variantIds = array_keys($variantQty);
        $snapshots = CatalogRepository::snapshotVariantsForOrder($variantIds);

        // Build order line items from snapshots.
        $lineItems = [];
        $totalMrp = 0.0;
        $totalPrice = 0.0;
        foreach ($variantQty as $vid => $qty) {
            if ($qty < 1) continue;
            if (!isset($snapshots[$vid])) continue;

            $snap = $snapshots[$vid];
            $price = (float) ($snap['price'] ?? 0);
            $mrp = (float) ($snap['mrp'] ?? 0);

            $lineItems[] = [
                'variant_id' => $vid,
                'quantity' => $qty,
                'unit_price' => $price,
                'unit_mrp' => $mrp,
                'image' => (string) ($snap['image'] ?? ''),
                'brand_name' => (string) ($snap['brand_name'] ?? ''),
                'product_name' => (string) ($snap['product_name'] ?? ''),
                'variant_name' => (string) ($snap['variant_name'] ?? ''),
                'sku' => (string) ($snap['sku'] ?? ''),
            ];

            $totalMrp += $mrp * $qty;
            $totalPrice += $price * $qty;
        }
        if ($lineItems === []) {
            return ['order_id' => null, 'failure' => 'no_lines', 'wallet_detail' => null];
        }

        $lat = isset($address['latitude']) ? (float) $address['latitude'] : 0.0;
        $lng = isset($address['longitude']) ? (float) $address['longitude'] : 0.0;

        $warehouseId = null;
        if ($lat != 0.0 || $lng != 0.0) {
            $warehouseId = WarehouseRepository::findNearestEnabledId($lat, $lng);
        }
        $warehouseIdInt = $warehouseId ?? 0;

        $charges = CartChargeRepository::findAllOrdered($warehouseIdInt);
        $chargeBreakdown = self::computeChargeBreakdown($totalPrice, $charges);
        $otherChargesValue = $chargeBreakdown['lines'];
        $totalCharges = $chargeBreakdown['total'];
        $deliveryFee = $chargeBreakdown['delivery_fee'];

        // Match checkout rounding (OrderPlacementService) so wallet debit amount matches order total.
        $grandTotal = round($totalPrice + $totalCharges, 2);
        if ($grandTotal < 0.01) {
            return ['order_id' => null, 'failure' => 'total_too_small', 'wallet_detail' => null];
        }

        // Skip before opening a transaction when spendable balance clearly cannot cover the order.
        $walletRow = WalletRepository::findByUserId($userId);
        $spendable = (float) ($walletRow['balance'] ?? 0.0);
        $needPaise = max(0, (int) round($grandTotal * 100));
        $balancePaise = max(0, (int) floor(round($spendable * 100)));
        if ($balancePaise < $needPaise) {
            return [
                'order_id' => null,
                'failure' => 'insufficient_wallet',
                'wallet_detail' => self::walletShortfallJson($grandTotal, $spendable, 'precheck'),
            ];
        }

        $orderId = Uuid::v4();
        $gatewayOrderId = 'sub_' . Uuid::v4();
        $deliveryDateYmd = $deliveryDate->format('Y-m-d');
        $fullAddress = self::formatFullAddress($address);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            OrderRepository::insertOrder(
                $orderId,
                $userId,
                null,
                (string) ($address['id'] ?? ''),
                isset($address['label']) ? (string) $address['label'] : null,
                $warehouseId,
                'placed',
                'pending',
                $deliveryDateYmd,
                self::DEFAULT_DELIVERY_SLOT,
                'standard',
                $totalMrp,
                0.0,
                $deliveryFee,
                $grandTotal,
                'INR',
                (string) ($address['recipient_name'] ?? ''),
                (string) ($address['phone'] ?? ''),
                $fullAddress,
                (string) ($address['city'] ?? ''),
                (string) ($address['state'] ?? ''),
                (string) ($address['country'] ?? ''),
                (string) ($address['postal_code'] ?? ''),
                $lat,
                $lng,
                $totalPrice,
                $totalCharges,
                $gatewayOrderId,
                'wallet',
                $otherChargesValue !== [] ? $otherChargesValue : null
            );
            OrderRepository::updateOrderKind($orderId, 'subscription');

            foreach ($lineItems as $li) {
                OrderRepository::insertOrderItem(
                    Uuid::v4(),
                    $orderId,
                    (string) $li['image'],
                    (string) $li['brand_name'],
                    (string) $li['product_name'],
                    (string) $li['variant_name'],
                    (int) $li['quantity'],
                    (float) $li['unit_price'],
                    (float) $li['unit_mrp'],
                    (string) $li['sku']
                );
            }

            $walletTxId = Uuid::v4();
            $debited = WalletRepository::debit(
                $walletTxId,
                $userId,
                $grandTotal,
                'subscription_order',
                $orderId,
                null,
                'Subscription order payment via wallet'
            );
            if (!$debited) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $fresh = WalletRepository::findByUserId($userId);
                $availAfter = (float) ($fresh['balance'] ?? 0.0);

                return [
                    'order_id' => null,
                    'failure' => 'insufficient_wallet',
                    'wallet_detail' => self::walletShortfallJson($grandTotal, $availAfter, 'debit_failed'),
                ];
            }

            $gatewayOrderId = 'wallet_' . $walletTxId;
            OrderRepository::updateGatewayOrderId($orderId, $gatewayOrderId);
            OrderRepository::updatePaymentStatusByGatewayOrderId($gatewayOrderId, 'success');

            PaymentRepository::insert(
                Uuid::v4(),
                $orderId,
                $userId,
                'wallet',
                $gatewayOrderId,
                $grandTotal,
                'INR',
                'success'
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'order_id' => $orderId,
            'failure' => null,
            'wallet_detail' => null,
        ];
    }

    /**
     * Same rule as checkout:
     * value = min_order_value === null ? amount : (min_order_value > priceValue ? amount : 0).
     *
     * @param list<array<string, mixed>> $charges rows from CartChargeRepository::findAllOrdered()
     * @return array{lines: list<array{title: string, value: float}>, total: float, delivery_fee: float}
     */
    private static function computeChargeBreakdown(float $totalPrice, array $charges): array
    {
        $lines = [];
        $total = 0.0;
        foreach ($charges as $ch) {
            $amount = (float) ($ch['amount'] ?? 0);
            $mov = $ch['min_order_value'] ?? null;
            if ($mov === null) {
                $value = $amount;
            } else {
                $threshold = (float) $mov;
                $value = $threshold > $totalPrice ? $amount : 0.0;
            }
            $lines[] = [
                'title' => (string) ($ch['title'] ?? ''),
                'value' => $value,
            ];
            $total += $value;
        }

        $deliveryFee = self::deliveryFeeFromChargeLines($lines);

        return [
            'lines' => $lines,
            'total' => $total,
            'delivery_fee' => $deliveryFee,
        ];
    }

    /**
     * @param list<array{title: string, value: float}> $lines
     */
    private static function deliveryFeeFromChargeLines(array $lines): float
    {
        foreach ($lines as $row) {
            if (strcasecmp(trim($row['title']), 'Delivery charges') === 0) {
                return (float) $row['value'];
            }
        }
        foreach ($lines as $row) {
            if (str_contains(strtolower($row['title']), 'deliver')) {
                return (float) $row['value'];
            }
        }
        return 0.0;
    }

    /** @param array<string, mixed> $address */
    private static function formatFullAddress(array $address): string
    {
        $parts = [
            $address['address_line_1'] ?? null,
            $address['area'] ?? null,
            $address['address_line_2'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['country'] ?? null,
            $address['postal_code'] ?? null,
        ];
        $out = [];
        foreach ($parts as $p) {
            if ($p === null) continue;
            $s = trim((string) $p);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return implode(', ', $out);
    }
}

