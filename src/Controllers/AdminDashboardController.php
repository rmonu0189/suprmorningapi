<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Repositories\AdminAnalyticsRepository;
use App\Repositories\AdminDashboardRepository;

final class AdminDashboardController
{
    /** GET /v1/admin/dashboard/summary */
    public function summary(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        $dateParam = $request->query('date');
        if ($dateParam !== null && trim($dateParam) !== '') {
            $trimmed = trim($dateParam);
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $trimmed);
            if ($dt === false || $dt->format('Y-m-d') !== $trimmed) {
                Response::json(['error' => 'Invalid date', 'errors' => ['date' => 'Use YYYY-MM-DD.']], 422);

                return;
            }
            $today = $dt->format('Y-m-d');
        } else {
            $today = date('Y-m-d');
        }

        $fromSql = $today . ' 00:00:00';
        $toSql = date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00';

        $todayOverview = AdminAnalyticsRepository::overview($fromSql, $toSql);

        Response::json([
            'totals' => [
                'total_users' => AdminDashboardRepository::totalUsers(),
                'total_orders' => AdminDashboardRepository::totalOrders(),
                'total_revenue_success' => AdminDashboardRepository::totalRevenueSuccess(),
            ],
            'today' => [
                'date' => $today,
                'orders_created' => (int) ($todayOverview['orders_created'] ?? 0),
                'orders_success' => (int) ($todayOverview['orders_success'] ?? 0),
                'revenue_success' => (float) ($todayOverview['revenue_success'] ?? 0),
                'users_created' => (int) ($todayOverview['users_created'] ?? 0),
            ],
            'recent_orders' => AdminDashboardRepository::recentSuccessOrders(5),
        ]);
    }
}

