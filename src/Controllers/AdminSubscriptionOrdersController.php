<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Middleware\AuthMiddleware;
use App\Repositories\SubscriptionOrderGenerationRepository;
use App\Services\SubscriptionOrderGenerator;
use DateTimeImmutable;

final class AdminSubscriptionOrdersController
{
    /**
     * POST /v1/admin/subscriptions/generate-orders/start?delivery_date=YYYY-MM-DD
     * Creates/refreshes a generation run (marks pending for users not already success).
     */
    public function start(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) return;

        $role = trim((string) ($claims['role'] ?? ''));
        if ($role !== 'admin' && $role !== 'manager') {
            Response::json(['error' => 'Forbidden'], 403);
            return;
        }

        $deliveryDateYmd = $this->resolveDeliveryDate($request);
        $runId = Uuid::v4();

        $res = SubscriptionOrderGenerationRepository::startRun($deliveryDateYmd, $runId);
        $summary = SubscriptionOrderGenerationRepository::statusSummary($deliveryDateYmd);

        Response::json([
            'run' => $res,
            'summary' => $summary,
        ]);
    }

    /**
     * POST /v1/admin/subscriptions/generate-orders/process?delivery_date=YYYY-MM-DD&limit=50
     * Processes the next batch of pending users.
     */
    public function process(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) return;

        $role = trim((string) ($claims['role'] ?? ''));
        if ($role !== 'admin' && $role !== 'manager') {
            Response::json(['error' => 'Forbidden'], 403);
            return;
        }

        $deliveryDateYmd = $this->resolveDeliveryDate($request);
        $limit = max(1, min(200, (int) ($request->query('limit') ?? '50')));

        $deliveryDate = new DateTimeImmutable($deliveryDateYmd);
        $userIds = SubscriptionOrderGenerationRepository::findNextPendingUserIds($deliveryDateYmd, $limit);

        $processed = [];
        foreach ($userIds as $uid) {
            try {
                $res = SubscriptionOrderGenerator::generateForUser($uid, $deliveryDate);
                if ($res['status'] === 'success') {
                    SubscriptionOrderGenerationRepository::markSuccess($deliveryDateYmd, $uid, $res['order_id']);
                } elseif ($res['status'] === 'skipped_no_address') {
                    SubscriptionOrderGenerationRepository::markSkipped($deliveryDateYmd, $uid, 'skipped_no_address');
                } else {
                    SubscriptionOrderGenerationRepository::markSkipped($deliveryDateYmd, $uid, 'skipped_no_items');
                }
                $processed[] = ['user_id' => $uid, 'status' => $res['status'], 'order_id' => $res['order_id'], 'existing' => (bool) $res['existing']];
            } catch (\Throwable $e) {
                SubscriptionOrderGenerationRepository::markFailed($deliveryDateYmd, $uid, $e->getMessage());
                $processed[] = ['user_id' => $uid, 'status' => 'failed', 'order_id' => null, 'existing' => false];
            }
        }

        $summary = SubscriptionOrderGenerationRepository::statusSummary($deliveryDateYmd);
        $recent = SubscriptionOrderGenerationRepository::listRecent($deliveryDateYmd, 200);

        Response::json([
            'delivery_date' => $deliveryDateYmd,
            'processed' => $processed,
            'summary' => $summary,
            'recent' => $recent,
        ]);
    }

    /**
     * GET /v1/admin/subscriptions/generate-orders/status?delivery_date=YYYY-MM-DD
     */
    public function status(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) return;

        $role = trim((string) ($claims['role'] ?? ''));
        if ($role !== 'admin' && $role !== 'manager') {
            Response::json(['error' => 'Forbidden'], 403);
            return;
        }

        $deliveryDateYmd = $this->resolveDeliveryDate($request);
        $summary = SubscriptionOrderGenerationRepository::statusSummary($deliveryDateYmd);
        $recent = SubscriptionOrderGenerationRepository::listRecent($deliveryDateYmd, 200);

        Response::json([
            'delivery_date' => $deliveryDateYmd,
            'summary' => $summary,
            'recent' => $recent,
        ]);
    }

    private function resolveDeliveryDate(Request $request): string
    {
        $raw = trim((string) ($request->query('delivery_date') ?? ''));
        if ($raw === '') {
            return (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($dt === false || $dt->format('Y-m-d') !== $raw) {
            throw new ValidationException('Invalid delivery_date', ['delivery_date' => 'Use YYYY-MM-DD.']);
        }
        return $raw;
    }
}

