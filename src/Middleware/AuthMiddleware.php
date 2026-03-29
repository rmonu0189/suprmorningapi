<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Jwt;
use App\Core\Request;
use App\Core\Response;

final class AuthMiddleware
{
    /**
     * Validates Bearer JWT. Add a repository check (e.g. user active in DB) when you wire auth routes.
     */
    public static function requireAuth(Request $request): ?array
    {
        $header = $request->header('Authorization');
        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            Response::json(['error' => 'Unauthorized'], 401);
            return null;
        }

        $token = trim(substr($header, 7));
        $claims = Jwt::verify($token);
        if ($claims === null) {
            Response::json(['error' => 'Unauthorized'], 401);
            return null;
        }

        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            Response::json(['error' => 'Unauthorized'], 401);
            return null;
        }

        return $claims;
    }

    public static function requireRole(array $claims, string $role): bool
    {
        if (($claims['role'] ?? null) !== $role) {
            Response::json(['error' => 'Forbidden'], 403);
            return false;
        }

        return true;
    }
}
