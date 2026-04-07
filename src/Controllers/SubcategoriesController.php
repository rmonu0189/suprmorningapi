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
use App\Repositories\CategoryRepository;
use App\Repositories\SubcategoryRepository;
use PDOException;

final class SubcategoriesController
{
    public function index(Request $request): void
    {
        $id = $request->query('id');
        if ($id !== null && trim($id) !== '') {
            $id = trim($id);
            if (!Uuid::isValid($id)) {
                Response::json(['error' => 'Invalid id', 'errors' => ['id' => 'Must be a valid UUID']], 422);
                return;
            }
            $sub = SubcategoryRepository::findById($id);
            if ($sub === null) {
                Response::json(['error' => 'Not Found'], 404);
                return;
            }
            Response::json(['subcategory' => $sub]);
            return;
        }

        $categoryId = $request->query('category_id');
        $filterCategory = null;
        if ($categoryId !== null && trim($categoryId) !== '') {
            $categoryId = trim($categoryId);
            if (!Uuid::isValid($categoryId)) {
                Response::json(['error' => 'Invalid category_id', 'errors' => ['category_id' => 'Must be a valid UUID']], 422);
                return;
            }
            $filterCategory = $categoryId;
        }

        $enabledOnly = false;
        $enabled = $request->query('enabled');
        if ($enabled !== null && trim($enabled) !== '') {
            $v = trim($enabled);
            $enabledOnly = $v === '1' || strcasecmp($v, 'true') === 0;
        }

        Response::json(['subcategories' => SubcategoryRepository::findAll($filterCategory, $enabledOnly ? true : null)]);
    }

    public function create(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) return;
        Validator::requireJsonContentType($request);
        $body = $request->json();

        $categoryId = trim((string) ($body['category_id'] ?? ''));
        if ($categoryId === '' || !Uuid::isValid($categoryId)) {
            throw new ValidationException('Invalid category_id', ['category_id' => 'Must be a valid UUID.']);
        }
        if (CategoryRepository::findById($categoryId) === null) {
            Response::json(['error' => 'Not Found', 'errors' => ['category_id' => 'Category does not exist.']], 404);
            return;
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') throw new ValidationException('Invalid name', ['name' => 'Required.']);

        $slug = self::optionalStringOrNull($body, 'slug');
        $status = true;
        if (array_key_exists('status', $body)) $status = self::parseBool($body['status'], 'status');
        $sort = 0;
        if (array_key_exists('sort_order', $body)) $sort = self::parseInt($body['sort_order'], 'sort_order');

        $id = Uuid::v4();
        try {
            SubcategoryRepository::insert($id, $categoryId, $name, $slug, $status, $sort);
        } catch (PDOException $e) {
            throw new HttpException('Could not create subcategory', 500);
        }

        $sub = SubcategoryRepository::findById($id);
        if ($sub === null) throw new HttpException('Could not create subcategory', 500);
        Response::json(['subcategory' => $sub], 201);
    }

    public function update(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) return;
        Validator::requireJsonContentType($request);
        $body = $request->json();

        $id = trim((string) ($body['id'] ?? ''));
        if ($id === '' || !Uuid::isValid($id)) throw new ValidationException('Invalid id', ['id' => 'A valid UUID is required.']);
        if (SubcategoryRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $categoryId = null;
        if (array_key_exists('category_id', $body)) {
            $cid = trim((string) $body['category_id']);
            if ($cid === '' || !Uuid::isValid($cid)) {
                throw new ValidationException('Invalid category_id', ['category_id' => 'Must be a valid UUID.']);
            }
            if (CategoryRepository::findById($cid) === null) {
                Response::json(['error' => 'Not Found', 'errors' => ['category_id' => 'Category does not exist.']], 404);
                return;
            }
            $categoryId = $cid;
        }

        $name = null;
        if (array_key_exists('name', $body)) {
            $v = trim((string) $body['name']);
            if ($v === '') throw new ValidationException('Invalid name', ['name' => 'Cannot be empty.']);
            $name = $v;
        }

        $slug = null;
        $slugProvided = array_key_exists('slug', $body);
        if ($slugProvided) {
            $slug = self::nullableTextValue($body['slug'], 'slug');
        }

        $status = null;
        if (array_key_exists('status', $body)) $status = self::parseBool($body['status'], 'status');

        $sort = null;
        if (array_key_exists('sort_order', $body)) $sort = self::parseInt($body['sort_order'], 'sort_order');

        if ($categoryId === null && $name === null && !$slugProvided && $status === null && $sort === null) {
            throw new ValidationException('Nothing to update', ['body' => 'Provide at least one of: category_id, name, slug, status, sort_order.']);
        }

        try {
            SubcategoryRepository::update($id, $categoryId, $name, $slug, $slugProvided, $status, $sort);
        } catch (PDOException $e) {
            throw new HttpException('Could not update subcategory', 500);
        }

        $sub = SubcategoryRepository::findById($id);
        if ($sub === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        Response::json(['subcategory' => $sub]);
    }

    public function delete(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) return;

        $id = trim((string) ($request->query('id') ?? ''));
        if ($id === '') {
            $body = $request->json();
            $id = trim((string) ($body['id'] ?? ''));
        }
        if ($id === '' || !Uuid::isValid($id)) {
            Response::json(['error' => 'Invalid id', 'errors' => ['id' => 'Provide a valid UUID via ?id= or JSON body.']], 422);
            return;
        }
        if (SubcategoryRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        if (SubcategoryRepository::countProducts($id) > 0) {
            Response::json(['error' => 'Conflict', 'errors' => ['subcategory' => 'Cannot delete: subcategory is still used by products.']], 409);
            return;
        }
        if (!SubcategoryRepository::delete($id)) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        Response::json(['ok' => true]);
    }

    private static function optionalStringOrNull(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body)) return null;
        return self::nullableTextValue($body[$key], $key);
    }

    private static function nullableTextValue(mixed $v, string $key): ?string
    {
        if ($v === null) return null;
        if (!is_string($v)) throw new ValidationException('Invalid ' . $key, [$key => 'Must be a string or null.']);
        $t = trim($v);
        return $t === '' ? null : $t;
    }

    private static function parseBool(mixed $v, string $key): bool
    {
        if (is_bool($v)) return $v;
        if ($v === 1 || $v === 0) return $v === 1;
        throw new ValidationException('Invalid ' . $key, [$key => 'Must be a boolean.']);
    }

    private static function parseInt(mixed $v, string $key): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && is_numeric($v)) return (int) $v;
        throw new ValidationException('Invalid ' . $key, [$key => 'Must be an integer.']);
    }
}

