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
use App\Repositories\WalletHoldRepository;
use App\Repositories\WalletTopupRepository;
use App\Services\CommerceGatewayPaymentService;
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

        CommerceGatewayPaymentService::expireStaleWalletHolds($userId);

        $wallet = WalletRepository::findByUserId($userId);
        $activeHolds = array_map(static function (array $hold): array {
            return [
                'id' => (string) ($hold['id'] ?? ''),
                'order_id' => (string) ($hold['order_id'] ?? ''),
                'amount' => (float) ($hold['amount'] ?? 0),
                'status' => (string) ($hold['status'] ?? ''),
                'created_at' => (string) ($hold['created_at'] ?? ''),
                'release_at' => CommerceGatewayPaymentService::holdExpiresAt($hold),
            ];
        }, WalletHoldRepository::findActiveByUserId($userId));
        $transactions = WalletRepository::findRecentTransactionsByUserId($userId, 5);
        $totalTransactions = WalletRepository::countTransactionsByUserId($userId);
        Response::json([
            'wallet' => array_merge($wallet, [
                'locked_holds' => $activeHolds,
                'locked_release_at' => $activeHolds[0]['release_at'] ?? null,
            ]),
            'transactions' => $transactions,
            'total_transactions' => $totalTransactions,
        ]);
    }

    /** GET /v1/wallet/transactions?limit=&offset= */
    public function transactions(Request $request): void
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

        $limitRaw = $request->query('limit');
        $offsetRaw = $request->query('offset');
        $typeRaw = strtolower(trim((string) ($request->query('type') ?? 'all')));
        $type = in_array($typeRaw, ['all', 'credit', 'debit', 'holds'], true) ? $typeRaw : 'all';
        $limit = is_string($limitRaw) ? (int) $limitRaw : 20;
        $offset = is_string($offsetRaw) ? (int) $offsetRaw : 0;
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        CommerceGatewayPaymentService::expireStaleWalletHolds($userId);

        if ($type === 'holds') {
            $total = WalletRepository::countHoldTransactionsByUserId($userId);
            $transactions = array_map([self::class, 'formatHoldTransactionActivity'], WalletRepository::findHoldTransactionsByUserId($userId, $limit, $offset));
        } elseif ($type === 'credit' || $type === 'debit') {
            $total = WalletRepository::countTransactionsByUserId($userId, $type);
            $transactions = WalletRepository::findTransactionsByUserId($userId, $limit, $offset, $type);
        } else {
            $walletTransactions = WalletRepository::findTransactionsByUserId($userId, 1000, 0);
            $holdTransactions = array_map([self::class, 'formatHoldTransactionActivity'], WalletRepository::findHoldTransactionsByUserId($userId, 1000, 0));
            $all = array_merge($walletTransactions, $holdTransactions);
            usort($all, static function (array $a, array $b): int {
                $cmp = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
            });
            $total = WalletRepository::countTransactionsByUserId($userId) + WalletRepository::countHoldTransactionsByUserId($userId);
            $transactions = array_slice($all, $offset, $limit);
        }
        Response::json([
            'transactions' => $transactions,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'type' => $type,
        ]);
    }

    /** @param array<string, mixed> $tx */
    private static function formatHoldTransactionActivity(array $tx): array
    {
        $source = strtolower((string) ($tx['source'] ?? ''));
        $note = (string) ($tx['note'] ?? '');
        $status = 'active';
        if ($source === 'order_hold_release') {
            $status = stripos($note, 'timeout') !== false || stripos($note, 'expired') !== false ? 'expired' : 'released';
        }

        return [
            'id' => (string) ($tx['id'] ?? ''),
            'user_id' => (string) ($tx['user_id'] ?? ''),
            'order_id' => $tx['order_id'] !== null && $tx['order_id'] !== '' ? (string) $tx['order_id'] : null,
            'type' => 'hold',
            'source' => $source,
            'amount' => (float) ($tx['amount'] ?? 0),
            'status' => $status,
            'reference_id' => $tx['reference_id'] !== null && $tx['reference_id'] !== '' ? (string) $tx['reference_id'] : null,
            'note' => $note !== '' ? $note : ($status === 'active' ? 'Wallet amount locked for online payment' : 'Wallet hold released'),
            'created_at' => (string) ($tx['created_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $hold */
    private static function formatHoldActivity(array $hold): array
    {
        $status = strtolower((string) ($hold['status'] ?? 'active'));
        $note = match ($status) {
            'active' => 'Wallet amount reserved for online payment',
            'captured' => 'Wallet hold captured after payment success',
            'released' => 'Wallet hold released after payment failure',
            'expired' => 'Wallet hold released after payment timeout',
            default => 'Wallet hold status updated',
        };

        return [
            'id' => (string) ($hold['id'] ?? ''),
            'user_id' => (string) ($hold['user_id'] ?? ''),
            'order_id' => (string) ($hold['order_id'] ?? ''),
            'type' => 'hold',
            'source' => 'wallet_hold',
            'amount' => (float) ($hold['amount'] ?? 0),
            'status' => $status,
            'reference_id' => (string) ($hold['order_id'] ?? ''),
            'note' => $note,
            'created_at' => (string) ($hold['created_at'] ?? ''),
        ];
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
        if ($amount > 5000) {
            throw new ValidationException('Invalid amount', ['amount' => 'Maximum ₹5,000 per wallet top-up transaction.']);
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
