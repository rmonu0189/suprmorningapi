<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Repositories\AdminAnalyticsRepository;
use App\Repositories\UserRepository;

final class AdminAnalyticsController
{
    public function overview(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $from = trim((string) ($request->query('from') ?? ''));
        $to = trim((string) ($request->query('to') ?? ''));

        // Default: today (server time) [from 00:00, to next day 00:00)
        if ($from === '') {
            $from = date('Y-m-d');
        }
        if ($to === '') {
            $to = date('Y-m-d', strtotime($from . ' +1 day'));
        }

        // Basic validation (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            Response::json(['error' => 'Invalid date range'], 422);
            return;
        }

        $fromSql = $from . ' 00:00:00';
        $toSql = $to . ' 00:00:00';

        $warehouseId = $this->warehouseScopeForClaims($claims);
        if ($warehouseId === false) {
            Response::json(['error' => 'Forbidden'], 403);
            return;
        }

        $overview = AdminAnalyticsRepository::overview($fromSql, $toSql, $warehouseId);

        Response::json([
            'from' => $from,
            'to' => $to,
            'overview' => $overview,
        ]);
    }

    public function coupons(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $from = trim((string) ($request->query('from') ?? ''));
        $to = trim((string) ($request->query('to') ?? ''));
        if ($from === '') {
            $from = date('Y-m-01');
        }
        if ($to === '') {
            $to = date('Y-m-d', strtotime('tomorrow'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            Response::json(['error' => 'Invalid date range'], 422);
            return;
        }

        $warehouseId = $this->warehouseScopeForClaims($claims);
        if ($warehouseId === false) {
            Response::json(['error' => 'Forbidden'], 403);
            return;
        }

        $usage = AdminAnalyticsRepository::couponUsage($from . ' 00:00:00', $to . ' 00:00:00', $warehouseId);
        Response::json([
            'from' => $from,
            'to' => $to,
            'summary' => $usage['summary'],
            'orders' => $usage['orders'],
        ]);
    }

    /** @return int|null|false null means unrestricted admin scope; false means forbidden. */
    private function warehouseScopeForClaims(array $claims): int|null|false
    {
        $role = (string) ($claims['role'] ?? '');
        if ($role !== 'staff' && $role !== 'manager' && $role !== 'delivery') {
            return null;
        }

        $sub = (string) ($claims['sub'] ?? '');
        $warehouseId = $sub !== '' ? UserRepository::findWarehouseId($sub) : null;

        return $warehouseId ?? false;
    }
}
