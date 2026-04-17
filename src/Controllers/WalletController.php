<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Core\Database;
use App\Middleware\AuthMiddleware;
use App\Repositories\WalletRepository;
use App\Repositories\WalletTopupRepository;
use App\Services\RazorpayService;

final class WalletController
{
    /** GET /v1/wallet */
    public function show(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        if ($userId === '' || !Uuid::isValid($userId)) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $wallet = WalletRepository::findByUserId($userId);
        $transactions = WalletRepository::findRecentTransactionsByUserId($userId, 20);
        Response::json([
            'wallet' => $wallet,
            'transactions' => $transactions,
        ]);
    }

    /** POST /v1/wallet/add-funds/create-order */
    public function createAddFundsOrder(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        if ($userId === '' || !Uuid::isValid($userId)) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();
        $amount = (float) ($body['amount'] ?? 0);
        if ($amount <= 0) {
            throw new ValidationException('Invalid amount', ['amount' => 'Amount must be greater than 0.']);
        }
        if ($amount > 1000000) {
            throw new ValidationException('Invalid amount', ['amount' => 'Amount exceeds maximum allowed value.']);
        }

        $amount = round($amount, 2);
        $topupId = Uuid::v4();
        // Razorpay receipt supports max 40 chars. Keep deterministic but compact.
        $receipt = 'wt_' . str_replace('-', '', substr($topupId, 0, 18));
        $razorpayOrder = RazorpayService::createOrder((int) round($amount * 100), $receipt, 'INR');
        $gatewayOrderId = (string) ($razorpayOrder['id'] ?? '');
        if ($gatewayOrderId === '') {
            Response::json(['error' => 'Could not create payment order'], 502);
            return;
        }

        WalletTopupRepository::insert(
            $topupId,
            $userId,
            $amount,
            'INR',
            'razorpay',
            $gatewayOrderId,
            'created'
        );

        $keyId = Env::get('RAZORPAY_KEY_ID', '');
        Response::json([
            'topup_id' => $topupId,
            'gateway' => 'razorpay',
            'gateway_order_id' => $gatewayOrderId,
            'amount' => $amount,
            'currency' => 'INR',
            'razorpay_key_id' => $keyId !== '' ? $keyId : null,
        ], 201);
    }

    /** GET /v1/wallet/add-funds/status?topup_id=... */
    public function addFundsStatus(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        if ($userId === '' || !Uuid::isValid($userId)) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $topupId = trim((string) ($request->query('topup_id') ?? ''));
        if ($topupId === '' || !Uuid::isValid($topupId)) {
            throw new ValidationException('Invalid topup_id', ['topup_id' => 'A valid UUID is required.']);
        }

        $topup = WalletTopupRepository::findByIdForUser($topupId, $userId);
        if ($topup === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['topup' => $topup]);
    }

    /** POST /v1/wallet/add-funds/confirm */
    public function confirmAddFunds(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        if ($userId === '' || !Uuid::isValid($userId)) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();
        $topupId = trim((string) ($body['topup_id'] ?? ''));
        $gatewayOrderId = trim((string) ($body['razorpay_order_id'] ?? ''));
        $gatewayPaymentId = trim((string) ($body['razorpay_payment_id'] ?? ''));
        $signature = trim((string) ($body['razorpay_signature'] ?? ''));

        if ($topupId === '' || !Uuid::isValid($topupId)) {
            throw new ValidationException('Invalid topup_id', ['topup_id' => 'A valid UUID is required.']);
        }
        if ($gatewayOrderId === '' || $gatewayPaymentId === '' || $signature === '') {
            throw new ValidationException('Invalid payment payload', ['payment' => 'Required Razorpay fields are missing.']);
        }

        $topup = WalletTopupRepository::findByIdForUser($topupId, $userId);
        if ($topup === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        if ((string) ($topup['gateway_order_id'] ?? '') !== $gatewayOrderId) {
            throw new ValidationException('Order mismatch', ['razorpay_order_id' => 'Order does not match topup intent.']);
        }

        $secret = Env::get('RAZORPAY_KEY_SECRET', '');
        if ($secret === '') {
            Response::json(['error' => 'Payment gateway not configured'], 503);
            return;
        }
        $payload = $gatewayOrderId . '|' . $gatewayPaymentId;
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new ValidationException('Invalid signature', ['razorpay_signature' => 'Signature verification failed.']);
        }

        if ((string) ($topup['status'] ?? '') === 'success') {
            $wallet = WalletRepository::findByUserId($userId);
            Response::json(['ok' => true, 'wallet' => $wallet, 'credited' => false]);
            return;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $fresh = WalletTopupRepository::findByIdForUser($topupId, $userId);
            if ($fresh === null) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                Response::json(['error' => 'Not Found'], 404);
                return;
            }
            if ((string) ($fresh['status'] ?? '') !== 'success') {
                $amount = (float) ($fresh['amount'] ?? 0);
                if ($amount > 0) {
                    WalletRepository::credit(
                        Uuid::v4(),
                        $userId,
                        round($amount, 2),
                        'topup',
                        null,
                        $gatewayPaymentId,
                        'Wallet top-up confirmed'
                    );
                    WalletTopupRepository::markSuccessById($topupId, $gatewayPaymentId);
                }
            }
            $wallet = WalletRepository::findByUserId($userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Response::json(['ok' => true, 'wallet' => $wallet, 'credited' => true]);
    }
}
