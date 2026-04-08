<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\UserRepository;
use PDOException;

final class AdminUsersController
{
    /**
     * GET /v1/admin/users?page=0&limit=20&exclude_role=user
     * Search (all roles, paginated): GET /v1/admin/users?page=0&limit=20&q=...
     * Legacy: phone=… behaves like q=…
     */
    public function index(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        $page = max(0, (int) ($request->query('page') ?? '0'));
        $limit = min(100, max(1, (int) ($request->query('limit') ?? '20')));
        $offset = $page * $limit;

        $q = trim((string) ($request->query('q') ?? ''));
        $phoneLegacy = trim((string) ($request->query('phone') ?? ''));
        if ($q === '' && $phoneLegacy !== '') {
            $q = $phoneLegacy;
        }

        $excludeRaw = $request->query('exclude_role');
        $excludeRole = is_string($excludeRaw) ? trim($excludeRaw) : UserRepository::DEFAULT_ROLE;
        if ($excludeRole === '') {
            $excludeRole = UserRepository::DEFAULT_ROLE;
        }

        if ($q !== '') {
            $total = UserRepository::countSearchAll($q);
            $users = UserRepository::searchAllPaged($q, $offset, $limit);
        } else {
            $total = UserRepository::countByRoleExcluding($excludeRole);
            $users = UserRepository::listByRoleExcludingPaged($excludeRole, $offset, $limit);
        }

        Response::json([
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /** PATCH /v1/admin/users/role { id, role } */
    public function updateRole(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $id = trim((string) ($body['id'] ?? ''));
        if ($id === '' || !Uuid::isValid($id)) {
            throw new ValidationException('Invalid id', ['id' => 'A valid UUID is required.']);
        }

        $role = trim((string) ($body['role'] ?? ''));
        if ($role === '') {
            throw new ValidationException('Invalid role', ['role' => 'Role is required.']);
        }
        if (strlen($role) > 50) {
            throw new ValidationException('Invalid role', ['role' => 'At most 50 characters.']);
        }

        if (UserRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        try {
            UserRepository::updateRole($id, $role);
        } catch (PDOException $e) {
            throw new HttpException('Could not update role', 500);
        }

        $user = UserRepository::findById($id);
        if ($user === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['user' => $user]);
    }
}

