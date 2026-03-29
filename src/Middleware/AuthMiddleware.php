<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Jwt;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;

final class AuthMiddleware
{
    /**
     * Validates Bearer JWT and that the user exists and is active.
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

        $active = UserRepository::findActiveFlag($sub);
        if ($active === null) {
            Response::json(['error' => 'Unauthorized'], 401);
            return null;
        }

        if (!$active) {
            Response::json(['error' => 'Account is disabled'], 403);
            return null;
        }

        return $claims;
    }

    /**
     * Valid access JWT, active user, and role claim exactly "admin".
     * Sends 401 / 403 and returns null on failure.
     *
     * @return array<string, mixed>|null
     */
    public static function requireAdmin(Request $request): ?array
    {
        $claims = self::requireAuth($request);
        if ($claims === null) {
            return null;
        }

        return self::requireRole($claims, 'admin') ? $claims : null;
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
