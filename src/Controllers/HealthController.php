<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;

final class HealthController
{
    public function __invoke(): void
    {
        Response::json([
            'status' => 'ok',
            'service' => 'suprmorning-api',
            'timestamp' => gmdate('c'),
        ]);
    }
}
