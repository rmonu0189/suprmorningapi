<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\HealthController;

require_once __DIR__ . '/../Controllers/HealthController.php';
require_once __DIR__ . '/../Core/Exceptions/HttpException.php';
require_once __DIR__ . '/../Core/Exceptions/ValidationException.php';
require_once __DIR__ . '/ExceptionHandler.php';
require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/SecurityHeaders.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Jwt.php';
require_once __DIR__ . '/Uuid.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Security/RateLimiter.php';
require_once __DIR__ . '/../Security/LoginLockout.php';

final class App
{
    private const API_PREFIX = '/v1';

    public function run(): void
    {
        SecurityHeaders::apply();

        $router = new Router();
        $request = new Request();

        // CORS preflight
        if ($request->method() === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $health = new HealthController();

        $router->add('GET', self::API_PREFIX . '/health', static function () use ($health): void {
            $health();
        });

        $router->dispatch($request);
    }
}
