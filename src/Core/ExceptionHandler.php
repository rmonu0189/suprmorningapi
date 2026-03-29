<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use JsonException;
use Throwable;

final class ExceptionHandler
{
    public static function register(): void
    {
        set_exception_handler([self::class, 'handle']);
    }

    public static function handle(Throwable $e): void
    {
        SecurityHeaders::apply();

        if ($e instanceof JsonException) {
            Response::json(['error' => 'Invalid JSON payload'], 422);
            return;
        }

        if ($e instanceof HttpException) {
            $payload = ['error' => $e->getMessage()];
            if ($e->context() !== []) {
                $payload = array_merge($payload, $e->context());
            }
            Response::json($payload, $e->statusCode());
            return;
        }

        self::log($e);
        Response::json(['error' => 'Internal Server Error'], 500);
    }

    private static function log(Throwable $e): void
    {
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $line = sprintf(
            "[%s] %s: %s in %s:%d\n",
            gmdate('c'),
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        error_log($line, 3, $logDir . '/app.log');
    }
}
