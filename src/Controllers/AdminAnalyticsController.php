<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Repositories\AdminAnalyticsRepository;

final class AdminAnalyticsController
{
    public function overview(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
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

        $overview = AdminAnalyticsRepository::overview($fromSql, $toSql);

        Response::json([
            'from' => $from,
            'to' => $to,
            'overview' => $overview,
        ]);
    }
}

