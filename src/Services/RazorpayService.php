<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\Exceptions\HttpException;

final class RazorpayService
{
    private const API_BASE = 'https://api.razorpay.com/v1';

    /**
     * @return array<string, mixed> Razorpay order entity (includes id, amount, currency, …)
     */
    public static function createOrder(int $amountPaise, string $receipt, string $currency = 'INR'): array
    {
        $keyId = Env::get('RAZORPAY_KEY_ID', '');
        $secret = Env::get('RAZORPAY_KEY_SECRET', '');
        if (trim($keyId) === '' || trim($secret) === '') {
            throw new HttpException('Payment gateway not configured', 503);
        }

        $payload = json_encode([
            'amount' => $amountPaise,
            'currency' => $currency,
            'receipt' => $receipt,
            'payment_capture' => 1,
        ], JSON_THROW_ON_ERROR);

        $auth = base64_encode($keyId . ':' . $secret);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAuthorization: Basic {$auth}\r\n",
                'content' => $payload,
                'timeout' => 45,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents(self::API_BASE . '/orders', false, $ctx);
        if (!is_string($raw)) {
            throw new HttpException('Could not reach payment gateway', 502);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpException('Invalid payment gateway response', 502);
        }

        if (isset($decoded['error'])) {
            $desc = is_array($decoded['error']) ? ($decoded['error']['description'] ?? 'Payment error') : 'Payment error';
            throw new HttpException((string) $desc, 400);
        }

        if (!isset($decoded['id']) || !is_string($decoded['id'])) {
            throw new HttpException('Invalid payment gateway response', 502);
        }

        return $decoded;
    }
}
