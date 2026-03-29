<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\ValidationException;

/**
 * Central place for request validation. Extend with static methods as you add endpoints.
 */
final class Validator
{
    public static function requireJsonContentType(Request $request): void
    {
        $contentType = strtolower($request->header('Content-Type') ?? '');
        if (!str_contains($contentType, 'application/json')) {
            throw new ValidationException('Unsupported Content-Type', [
                'content_type' => 'Expected application/json',
            ]);
        }
    }
}
