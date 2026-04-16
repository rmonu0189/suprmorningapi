<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Repositories\SubscriptionRepository;

final class AdminSubscriptionsController
{
    /** GET /v1/admin/subscriptions?page=0&limit=20 */
    public function index(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $role = trim((string) ($claims['role'] ?? ''));
        if ($role !== 'admin' && $role !== 'manager') {
            Response::json(['error' => 'Forbidden'], 403);
            return;
        }

        $page = max(0, (int) ($request->query('page') ?? '0'));
        $limit = min(100, max(1, (int) ($request->query('limit') ?? '20')));
        $offset = $page * $limit;

        $rows = SubscriptionRepository::findAllPaged($offset, $limit);
        $total = SubscriptionRepository::countAll();

        Response::json([
            'subscriptions' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }
}
