<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\AddressRepository;
use App\Repositories\CartChargeRepository;
use App\Repositories\CartRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use DateTimeImmutable;
use PDOException;

final class OrderPlacementService
{
    /** Default morning window until slot selection exists at checkout. */
    private const DEFAULT_DELIVERY_SLOT = '5 to 7 AM';

    /**
     * @return array{order: array{id: string}, razorpayResponse: array<string, mixed>, razonpayResponse: array<string, mixed>}
     */
    public static function place(string $userId, string $cartId, string $addressId): array
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

        $chargeRows = CartChargeRepository::findAllOrdered();
        $chargeBreakdown = self::computeChargeBreakdown($totalPrice, $chargeRows);
        $otherChargesValue = $chargeBreakdown['lines'];
        $totalCharges = $chargeBreakdown['total'];
        $deliveryFee = $chargeBreakdown['delivery_fee'];

        // Edge function sets order.tax to 0; tax-like rows still flow into total_charges / metadata.
        $tax = 0.0;

        $grandTotal = $totalPrice + $totalCharges;
        $amountPaise = (int) round($grandTotal * 100);
        if ($amountPaise < 1) {
            throw new HttpException('Order total too small for payment', 400);
        }

        $orderId = Uuid::v4();
        $deliveryDate = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $fullAddress = self::formatFullAddressLikeEdge($address);

        $variantIds = array_map(static fn (array $l): string => (string) $l['variant_id'], $lines);
        $snapshots = CatalogRepository::snapshotVariantsForOrder($variantIds);
        foreach ($variantIds as $vid) {
            if (!isset($snapshots[$vid])) {
                throw new HttpException('Variant no longer available', 400);
            }
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
                $totalPrice,
                $totalCharges,
                null,
                'razorpay',
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

            $razorpay = RazorpayService::createOrder($amountPaise, $orderId, 'INR');
            $gatewayId = (string) $razorpay['id'];
            OrderRepository::updateGatewayOrderId($orderId, $gatewayId);

            PaymentRepository::insert(
                Uuid::v4(),
                $orderId,
                $userId,
                'razorpay',
                $gatewayId,
                $grandTotal,
                'INR',
                'initiated'
            );

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new HttpException('Could not create order', 500);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof HttpException || $e instanceof ValidationException) {
                throw $e;
            }
            throw new HttpException('Could not create order', 500);
        }

        return [
            'order' => ['id' => $orderId],
            'razorpayResponse' => $razorpay,
            'razonpayResponse' => $razorpay,
        ];
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
