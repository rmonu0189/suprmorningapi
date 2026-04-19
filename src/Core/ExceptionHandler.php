<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use JsonException;
use PDOException;
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

        self::logThrowable($e);
        Response::json(['error' => 'Internal Server Error'], 500);
    }

    /**
     * Append a full exception record to storage/logs/app.log (not PHP's default error_log destination).
     * Use this before converting an exception into HttpException so operators can diagnose 500s.
     *
     * @param non-empty-string|null $context
     */
    public static function logThrowable(Throwable $e, ?string $context = null): void
    {
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $path = $logDir . '/app.log';
        $prefix = ($context !== null && $context !== '') ? '[' . $context . '] ' : '';
        $block = sprintf(
            "[%s] %s%s: %s in %s:%d\n",
            gmdate('c'),
            $prefix,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        if ($e instanceof PDOException) {
            $info = $e->errorInfo;
            if (is_array($info)) {
                $enc = json_encode($info, JSON_UNESCAPED_UNICODE);
                $block .= '  PDO errorInfo: ' . (is_string($enc) ? $enc : '[]') . "\n";
            }
        }

        $prev = $e->getPrevious();
        if ($prev instanceof Throwable) {
            $block .= sprintf(
                "  previous: %s: %s @ %s:%d\n",
                $prev::class,
                $prev->getMessage(),
                $prev->getFile(),
                $prev->getLine()
            );
        }

        $block .= "\n";

        error_log($block, 3, $path);
    }
}
