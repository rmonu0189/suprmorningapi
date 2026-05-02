<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\ReferralService;

final class ReferralController
{
    private ReferralService $service;

    public function __construct()
    {
        $this->service = new ReferralService();
    }

    public function me(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        $userId = (string) ($claims['sub'] ?? '');
        Response::json($this->service->summaryForUser($userId));
    }
}
