<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\ExceptionHandler;
use App\Core\Env;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\AddressRepository;
use App\Repositories\CartChargeRepository;
use App\Repositories\CartRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\WalletHoldRepository;
use App\Repositories\WalletRepository;
use App\Repositories\WarehouseRepository;
use DateTimeImmutable;
use PDOException;

final class OrderPlacementService
{
    /**
     * @param array<string, mixed>|null $address
     * @return array{warehouse_id:int,source:string}
     */
    private static function resolveChargeWarehouse(?array $address): array
    {
        if ($address === null) {
            return ['warehouse_id' => 0, 'source' => 'default'];
        }

        $lat = isset($address['latitude']) ? (float) $address['latitude'] : 0.0;
        $lng = isset($address['longitude']) ? (float) $address['longitude'] : 0.0;
        if ($lat == 0.0 && $lng == 0.0) {
            return ['warehouse_id' => 0, 'source' => 'default'];
        }

        $warehouses = WarehouseRepository::findAll();
        $nearestInRadiusId = null;
        $nearestInRadiusDistance = INF;
        foreach ($warehouses as $wh) {
            if (!is_array($wh)) continue;
            $enabled = isset($wh['status']) ? (bool) $wh['status'] : false;
            if (!$enabled) continue;
            $whLat = isset($wh['latitude']) ? (float) $wh['latitude'] : 0.0;
            $whLng = isset($wh['longitude']) ? (float) $wh['longitude'] : 0.0;
            $whRadius = isset($wh['radius_km']) ? (float) $wh['radius_km'] : 0.0;
            if ($whRadius <= 0.0) continue;
            $distance = self::haversineKm($lat, $lng, $whLat, $whLng);
            if ($distance <= $whRadius && $distance < $nearestInRadiusDistance) {
                $nearestInRadiusDistance = $distance;
                $nearestInRadiusId = (int) ($wh['id'] ?? 0);
            }
        }

        if ($nearestInRadiusId !== null && $nearestInRadiusId > 0) {
            return ['warehouse_id' => $nearestInRadiusId, 'source' => 'in_radius'];
        }

        $nearestId = WarehouseRepository::findNearestEnabledId($lat, $lng);
        if ($nearestId !== null && $nearestId > 0) {
            return ['warehouse_id' => $nearestId, 'source' => 'nearest_fallback'];
        }

        return ['warehouse_id' => 0, 'source' => 'default'];
    }

    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $r * $c;
    }

    /** Default morning window until slot selection exists at checkout. */
    private const DEFAULT_DELIVERY_SLOT = '5 to 7 AM';

    /**
     * @return array<string, mixed>
     */
    public static function place(string $userId, string $cartId, string $addressId, bool $useWallet): array
    {
        $cart = CartRepository::findCartByIdForUser($cartId, $userId);
        if ($cart === null || $cart['status'] !== 'active') {
            throw new ValidationException('Invalid cart', ['cart_id' => 'Active cart not found.']);
        }

        $lines = CartRepository::getLineItemsForCart($cartId);
        if ($lines === []) {
            throw new ValidationException('Empty cart', ['cart' => 'Add items before checkout.']);
        }

        $address = AddressRepository::findByIdForUser($addressId, $userId);
        if ($address === null) {
            throw new ValidationException('Invalid address', ['address_id' => 'Address not found.']);
        }

        $totalMrp = 0.0;
        $totalPrice = 0.0;
        foreach ($lines as $line) {
            $q = $line['quantity'];
            $totalMrp += $line['unit_mrp'] * $q;
            $totalPrice += $line['unit_price'] * $q;
        }

        $resolvedWarehouse = self::resolveChargeWarehouse($address);
        $chargeRows = CartChargeRepository::findAllOrdered($resolvedWarehouse['warehouse_id']);
        $chargeBreakdown = self::computeChargeBreakdown($totalPrice, $chargeRows);
        $otherChargesValue = $chargeBreakdown['lines'];
        $totalCharges = $chargeBreakdown['total'];
        $deliveryFee = $chargeBreakdown['delivery_fee'];

        // Edge function sets order.tax to 0; tax-like rows still flow into total_charges / metadata.
        $tax = 0.0;

        $grandTotal = round($totalPrice + $totalCharges, 2);
        if ($grandTotal < 0.01) {
            throw new HttpException('Order total too small for payment', 400);
        }

        $walletRow = WalletRepository::findByUserId($userId);
        $spendable = (float) ($walletRow['balance'] ?? 0.0);
        $totalPaise = max(0, (int) round($grandTotal * 100));
        [$walletAppliedPaise, $gatewayPaise] = self::computeWalletAndGatewayPaise($totalPaise, $spendable, $useWallet);
        $walletApplied = round($walletAppliedPaise / 100, 2);
        $gatewayAmount = round($gatewayPaise / 100, 2);

        $orderId = Uuid::v4();
        $deliveryDate = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $fullAddress = self::formatFullAddressLikeEdge($address);
        $lat = isset($address['latitude']) ? (float) $address['latitude'] : 0.0;
        $lng = isset($address['longitude']) ? (float) $address['longitude'] : 0.0;

        $variantIds = array_map(static fn (array $l): string => (string) $l['variant_id'], $lines);
        $snapshots = CatalogRepository::snapshotVariantsForOrder($variantIds);
        foreach ($variantIds as $vid) {
            if (!isset($snapshots[$vid])) {
                throw new HttpException('Variant no longer available', 400);
            }
        }

        $gatewayNameInsert = 'razorpay';
        if ($walletAppliedPaise >= $totalPaise && $gatewayPaise === 0) {
            $gatewayNameInsert = 'wallet';
        } elseif ($walletAppliedPaise > 0 && $gatewayPaise > 0) {
            $gatewayNameInsert = 'mixed';
        }

        if ($walletAppliedPaise > 0 && $gatewayPaise > 0 && !WalletRepository::supportsSplitCheckout()) {
            throw new HttpException(
                'Wallet + card checkout is not enabled on this server. Apply database migration 038, or pay the full amount with card only (turn off wallet).',
                503
            );
        }

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();

            OrderRepository::insertOrder(
                $orderId,
                $userId,
                $cartId,
                $addressId,
                isset($address['label']) ? (string) $address['label'] : null,
                $resolvedWarehouse['warehouse_id'] > 0 ? $resolvedWarehouse['warehouse_id'] : null,
                'placed',
                'pending',
                $deliveryDate,
                self::DEFAULT_DELIVERY_SLOT,
                'standard',
                $totalMrp,
                $tax,
                $deliveryFee,
                $grandTotal,
                'INR',
                (string) $address['recipient_name'],
                (string) $address['phone'],
                $fullAddress,
                (string) $address['city'],
                (string) $address['state'],
                (string) $address['country'],
                (string) $address['postal_code'],
                $lat,
                $lng,
                $totalPrice,
                $totalCharges,
                null,
                $gatewayNameInsert,
                $otherChargesValue !== [] ? $otherChargesValue : null
            );

            foreach ($lines as $line) {
                $vid = (string) $line['variant_id'];
                $snap = $snapshots[$vid];
                OrderRepository::insertOrderItem(
                    Uuid::v4(),
                    $orderId,
                    $snap['image'],
                    $snap['brand_name'],
                    $snap['product_name'],
                    $snap['variant_name'],
                    $line['quantity'],
                    $line['unit_price'],
                    $line['unit_mrp'],
                    $snap['sku']
                );
            }

            // Full wallet: debit and complete in one transaction.
            // Use paise-derived amount so float balance vs grand_total rounding cannot fail the debit.
            if ($gatewayPaise === 0 && $walletAppliedPaise > 0) {
                $debitAmount = round($walletAppliedPaise / 100, 2);
                if ($debitAmount < 0.01) {
                    throw new HttpException('Could not compute payment split', 500);
                }

                $walletTxId = Uuid::v4();
                $debited = WalletRepository::debit(
                    $walletTxId,
                    $userId,
                    $debitAmount,
                    'order',
                    $orderId,
                    null,
                    'Order payment via wallet'
                );
                if (!$debited) {
                    throw new HttpException('Insufficient wallet balance', 400);
                }

                $gatewayId = 'wallet_' . $walletTxId;
                OrderRepository::updateGatewayOrderId($orderId, $gatewayId);
                OrderRepository::updatePaymentStatusByOrderId($orderId, 'success');

                PaymentRepository::insert(
                    Uuid::v4(),
                    $orderId,
                    $userId,
                    'wallet',
                    $gatewayId,
                    $debitAmount,
                    'INR',
                    'success'
                );

                $pdo->commit();

                return [
                    'order' => ['id' => $orderId],
                    'payment' => [
                        'mode' => 'wallet',
                        'status' => 'success',
                        'gateway_order_id' => $gatewayId,
                        'wallet_applied' => $debitAmount,
                        'gateway_amount' => 0.0,
                        'amount_paise' => 0,
                        'currency' => 'INR',
                        'razorpay_key_id' => null,
                    ],
                ];
            }

            // Razorpay (pure or mixed).
            if ($gatewayPaise <= 0) {
                throw new HttpException('Could not compute payment split', 500);
            }

            $receipt = 'ord_' . str_replace('-', '', substr($orderId, 0, 18));
            try {
                $rz = RazorpayService::createOrder($gatewayPaise, $receipt, 'INR');
            } catch (HttpException $e) {
                throw $e;
            } catch (\Throwable) {
                throw new HttpException('Could not start payment', 502);
            }

            $rzOrderId = isset($rz['id']) && is_string($rz['id']) ? $rz['id'] : '';
            if ($rzOrderId === '') {
                throw new HttpException('Could not start payment', 502);
            }

            if ($walletAppliedPaise > 0) {
                $holdId = Uuid::v4();
                if (!WalletRepository::lockSpendableToLocked($userId, $walletApplied)) {
                    throw new HttpException('Insufficient wallet balance', 400);
                }
                WalletHoldRepository::insertActive($holdId, $userId, $orderId, $walletApplied);
            }

            OrderRepository::updateGatewayOrderId($orderId, $rzOrderId);

            PaymentRepository::insert(
                Uuid::v4(),
                $orderId,
                $userId,
                'razorpay',
                $rzOrderId,
                $gatewayAmount,
                'INR',
                'pending'
            );

            $pdo->commit();

            $keyId = Env::get('RAZORPAY_KEY_ID', '');

            return [
                'order' => ['id' => $orderId],
                'payment' => [
                    'mode' => $walletAppliedPaise > 0 ? 'mixed' : 'razorpay',
                    'status' => 'pending',
                    'gateway_order_id' => $rzOrderId,
                    'wallet_applied' => $walletApplied,
                    'gateway_amount' => $gatewayAmount,
                    'amount_paise' => $gatewayPaise,
                    'currency' => 'INR',
                    'razorpay_key_id' => trim($keyId) !== '' ? $keyId : null,
                ],
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            ExceptionHandler::logThrowable($e, 'OrderPlacementService::place');
            throw new HttpException('Could not create order', 500, self::orderPlaceFailureDebugContext($e));
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof HttpException || $e instanceof ValidationException) {
                throw $e;
            }
            ExceptionHandler::logThrowable($e, 'OrderPlacementService::place');
            throw new HttpException('Could not create order', 500, self::orderPlaceFailureDebugContext($e));
        }
    }

    /**
     * When APP_DEBUG is true, JSON responses include these keys so operators can see the real error
     * without reading server logs. Never enable APP_DEBUG on public production.
     *
     * @return array<string, string|int>
     */
    private static function orderPlaceFailureDebugContext(\Throwable $e): array
    {
        $raw = Env::get('APP_DEBUG', 'false');
        $debug = strtolower(trim((string) $raw)) === 'true' || (string) $raw === '1';
        if (!$debug) {
            return [];
        }

        $ctx = ['detail' => $e->getMessage()];
        if ($e instanceof PDOException) {
            $info = $e->errorInfo;
            if (is_array($info)) {
                if (isset($info[0]) && is_scalar($info[0])) {
                    $ctx['sqlstate'] = (string) $info[0];
                }
                if (isset($info[1]) && is_scalar($info[1])) {
                    $ctx['driver_code'] = (int) $info[1];
                }
            }
        }

        return $ctx;
    }

    /**
     * @return array{0: int, 1: int} [walletAppliedPaise, gatewayPaise]
     */
    private static function computeWalletAndGatewayPaise(int $totalPaise, float $spendableBalance, bool $useWallet): array
    {
        if ($totalPaise <= 0) {
            return [0, 0];
        }

        $balancePaise = max(0, (int) floor(round($spendableBalance * 100)));
        $walletPaise = 0;
        if ($useWallet && $balancePaise > 0) {
            $walletPaise = min($balancePaise, $totalPaise);
        }
        $gatewayPaise = max(0, $totalPaise - $walletPaise);

        // When the card/UPI remainder would be below ₹1 but the order total is at least ₹1,
        // shift spend from wallet so the gateway charges at least ₹1 (Razorpay/UI practical minimum).
        if ($gatewayPaise > 0 && $gatewayPaise < 100 && $totalPaise >= 100) {
            $deficit = 100 - $gatewayPaise;
            if ($walletPaise >= $deficit) {
                $walletPaise -= $deficit;
                $gatewayPaise = 100;
            } else {
                $gatewayPaise = $totalPaise;
                $walletPaise = 0;
            }
        }

        return [$walletPaise, $gatewayPaise];
    }

    /**
     * Same rule as Supabase edge:
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
            $amount = (float) $ch['amount'];
            $mov = $ch['min_order_value'];
            if ($mov === null) {
                $value = $amount;
            } else {
                $threshold = (float) $mov;
                $value = $threshold > $totalPrice ? $amount : 0.0;
            }
            $lines[] = [
                'title' => (string) $ch['title'],
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
     * Edge: otherChargesValue.find((value) => value.title == 'Delivery charges').value
     * Fallback: any title containing "deliver" (e.g. seeded title "Delivery").
     *
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

    /**
     * Edge: [recipient_name, address_line_1, area, address_line_2, city, state, country, postal_code].filter(Boolean).join(', ')
     *
     * @param array<string, mixed> $address
     */
    private static function formatFullAddressLikeEdge(array $address): string
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
            if ($p === null) {
                continue;
            }
            $s = trim((string) $p);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return implode(', ', $out);
    }
}
