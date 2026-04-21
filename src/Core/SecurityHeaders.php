<?php

declare(strict_types=1);

namespace App\Core;

final class SecurityHeaders
{
    public static function apply(): void
    {
        self::applyCors();

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('Cache-Control: no-store');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    private static function applyCors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $origin = is_string($origin) ? trim($origin) : '';

        // Comma-separated list (or "*" to allow any).
        $allow = Env::get('CORS_ALLOW_ORIGINS', '*');
        $allow = trim($allow);

        if ($allow === '*') {
            // If requests include credentials, browsers require an explicit origin.
            header('Access-Control-Allow-Origin: ' . ($origin !== '' ? $origin : '*'));
        } else {
            $allowed = array_filter(array_map('trim', explode(',', $allow)));
            if ($origin !== '' && in_array($origin, $allowed, true)) {
                header('Access-Control-Allow-Origin: ' . $origin);
            }
        }

        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With');
        header('Access-Control-Max-Age: 86400');

        // Set to "true" only if you need cookies; token-based auth doesn't require it.
        $creds = strtolower(trim(Env::get('CORS_ALLOW_CREDENTIALS', 'false')));
        if ($creds === 'true') {
            header('Access-Control-Allow-Credentials: true');
        }
    }
}
