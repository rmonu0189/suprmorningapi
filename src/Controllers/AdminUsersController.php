<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Core\Phone;
use App\Middleware\AuthMiddleware;
use App\Repositories\UserRepository;
use App\Repositories\WarehouseRepository;
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
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
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

        $warehouseId = null;
        $role = (string) ($claims['role'] ?? '');
        $sub = (string) ($claims['sub'] ?? '');
        if ($role === 'manager' && $sub !== '') {
            $warehouseId = UserRepository::findWarehouseId($sub);
            if ($warehouseId === null) {
                Response::json(['error' => 'Forbidden'], 403);
                return;
            }
        }

        if ($q !== '') {
            // Manager search is limited to their warehouse staff list (not global).
            if ($warehouseId !== null) {
                $total = UserRepository::countByRoleExcludingAndWarehouse($excludeRole, $warehouseId);
                $users = UserRepository::listByRoleExcludingWarehousePaged($excludeRole, $warehouseId, $offset, $limit);
            } else {
                $total = UserRepository::countSearchAll($q);
                $users = UserRepository::searchAllPaged($q, $offset, $limit);
            }
        } else {
            if ($warehouseId !== null) {
                $total = UserRepository::countByRoleExcludingAndWarehouse($excludeRole, $warehouseId);
                $users = UserRepository::listByRoleExcludingWarehousePaged($excludeRole, $warehouseId, $offset, $limit);
            } else {
                $total = UserRepository::countByRoleExcluding($excludeRole);
                $users = UserRepository::listByRoleExcludingPaged($excludeRole, $offset, $limit);
            }
        }

        Response::json([
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /** GET /v1/admin/users/by-phone?phone=+919876543210 */
    public function byPhone(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $phone = trim((string) ($request->query('phone') ?? ''));
        if ($phone === '') {
            Response::json(['error' => 'Invalid phone', 'errors' => ['phone' => 'Phone is required.']], 422);
            return;
        }

        // Basic sanity + normalization to local phone (accept +, spaces, etc).
        $parsed = Phone::parseLocalAndCountryCode($phone, UserRepository::DEFAULT_COUNTRY_CODE);
        if ($parsed === null) {
            Response::json(['error' => 'Invalid phone', 'errors' => ['phone' => 'Enter a valid phone number.']], 422);
            return;
        }

        $user = UserRepository::findByPhoneExact($parsed['phone'], $parsed['country_code']);
        if ($user === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $role = (string) ($claims['role'] ?? '');
        $sub = (string) ($claims['sub'] ?? '');
        if ($role === 'manager' && $sub !== '') {
            $wid = UserRepository::findWarehouseId($sub);
            $userWid = $user['warehouse_id'] ?? null;
            $targetRole = trim((string) ($user['role'] ?? UserRepository::DEFAULT_ROLE));
            if ($wid === null) {
                Response::json(['error' => 'Not Found'], 404);
                return;
            }

            // Allow managers to find role=user accounts (typically warehouse_id NULL) for promotion.
            if ($targetRole !== UserRepository::DEFAULT_ROLE) {
                if ($userWid === null || (int) $userWid !== (int) $wid) {
                    Response::json(['error' => 'Not Found'], 404);
                    return;
                }
            }
        }

        Response::json(['user' => $user]);
    }

    /** PATCH /v1/admin/users/role { id, role } */
    public function updateRole(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $actorRole = trim((string) ($claims['role'] ?? ''));
        if ($actorRole !== 'admin' && $actorRole !== 'manager') {
            Response::json(['error' => 'Forbidden'], 403);
            return;
        }
        $actorUserId = trim((string) ($claims['sub'] ?? ''));

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

        $target = UserRepository::findById($id);
        if ($target === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $targetRole = trim((string) ($target['role'] ?? UserRepository::DEFAULT_ROLE));

        // Manager constraints:
        // - Can only assign staff/delivery
        // - Cannot modify admin/manager users
        if ($actorRole === 'manager') {
            if ($targetRole === 'admin' || $targetRole === 'manager') {
                Response::json(['error' => 'Forbidden'], 403);
                return;
            }
            if ($role !== 'staff' && $role !== 'delivery') {
                throw new ValidationException('Invalid role', ['role' => 'Managers can assign only staff or delivery roles.']);
            }
        }

        // Warehouse rules:
        // - staff/manager/delivery must have warehouse_id (admin provides; manager auto-assigns their own)
        // - user/admin => warehouse_id must be null
        $warehouseId = null;
        if ($role === 'staff' || $role === 'manager' || $role === 'delivery') {
            if ($actorRole === 'manager') {
                $warehouseId = $actorUserId !== '' ? UserRepository::findWarehouseId($actorUserId) : null;
                if ($warehouseId === null) {
                    throw new ValidationException('Invalid warehouse_id', ['warehouse_id' => 'Manager must be assigned to a warehouse first.']);
                }
            } else {
                if (!array_key_exists('warehouse_id', $body)) {
                    throw new ValidationException('Invalid warehouse_id', ['warehouse_id' => 'Required for staff/manager.']);
                }
                $raw = $body['warehouse_id'];
                if ($raw === null || $raw === '') {
                    throw new ValidationException('Invalid warehouse_id', ['warehouse_id' => 'Required for staff/manager.']);
                }
                if (is_int($raw)) {
                    $warehouseId = $raw;
                } elseif (is_string($raw) && preg_match('/^\d+$/', $raw)) {
                    $warehouseId = (int) $raw;
                } else {
                    throw new ValidationException('Invalid warehouse_id', ['warehouse_id' => 'Must be an integer.']);
                }
            }
            $wh = WarehouseRepository::findById($warehouseId);
            if ($wh === null) {
                throw new ValidationException('Invalid warehouse_id', ['warehouse_id' => 'Warehouse not found.']);
            }
            if (!((bool) ($wh['status'] ?? false))) {
                throw new ValidationException('Invalid warehouse_id', ['warehouse_id' => 'Warehouse is disabled.']);
            }
        } else {
            $warehouseId = null;
        }

        try {
            UserRepository::updateRoleAndWarehouse($id, $role, $warehouseId);
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

