<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

/**
 * Delivers OTP codes. Wire an SMS provider in production; file logging is the default for development.
 */
final class OtpNotifier
{
    public static function dispatch(string $phone, string $code): void
    {
        $line = sprintf("[%s] phone=%s code=%s\n", gmdate('c'), $phone, $code);

        if (self::logEnabled()) {
            $dir = __DIR__ . '/../../storage/logs';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            error_log($line, 3, $dir . '/otp.log');
        }

        // SMS / email integration: read Env::get('SMS_PROVIDER') etc. and call your gateway here.
    }

    public static function exposeCodeInApiResponse(): bool
    {
        return strtolower(trim(Env::get('OTP_RETURN_CODE_IN_RESPONSE', 'false'))) === 'true';
    }

    private static function logEnabled(): bool
    {
        return strtolower(trim(Env::get('OTP_LOG_TO_FILE', 'true'))) !== 'false';
    }
}
