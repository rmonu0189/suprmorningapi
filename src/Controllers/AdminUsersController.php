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
    /** GET /v1/admin/users?phone=...&exclude_role=user&limit=50 */
    public function index(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        $phone = trim((string) ($request->query('phone') ?? ''));
        $excludeRole = trim((string) ($request->query('exclude_role') ?? UserRepository::DEFAULT_ROLE));
        $limitRaw = $request->query('limit');
        $limit = is_string($limitRaw) ? (int) $limitRaw : 50;

        if ($phone !== '') {
            Response::json([
                'users' => UserRepository::searchByPhone($phone, $excludeRole !== '' ? $excludeRole : null, $limit),
            ]);
            return;
        }

        Response::json([
            'users' => UserRepository::listByRoleExcluding($excludeRole !== '' ? $excludeRole : UserRepository::DEFAULT_ROLE, $limit),
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

