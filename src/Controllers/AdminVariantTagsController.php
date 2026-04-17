<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\VariantTagMasterRepository;

final class AdminVariantTagsController
{
    public function index(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        $id = trim((string) ($request->query('id') ?? ''));
        if ($id !== '') {
            if (!Uuid::isValid($id)) {
                Response::json(['error' => 'Invalid id', 'errors' => ['id' => 'Must be a valid UUID']], 422);
                return;
            }
            $item = VariantTagMasterRepository::findById($id);
            if ($item === null) {
                Response::json(['error' => 'Not Found'], 404);
                return;
            }
            Response::json(['tag' => $item]);
            return;
        }

        $enabled = $request->query('enabled');
        $enabledOnly = null;
        if ($enabled !== null && trim((string) $enabled) !== '') {
            $v = trim((string) $enabled);
            $enabledOnly = ($v === '1' || strcasecmp($v, 'true') === 0);
        }
        $q = trim((string) ($request->query('q') ?? ''));
        $limit = max(1, min(200, (int) ($request->query('limit') ?? 100)));

        Response::json(['tags' => VariantTagMasterRepository::findAll($enabledOnly, $q, $limit)]);
    }

    public function create(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }
        Validator::requireJsonContentType($request);
        $body = $request->json();

        $name = self::normalizeTag((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('Invalid name', ['name' => 'Required.']);
        }
        $status = array_key_exists('status', $body) ? self::parseBool($body['status'], 'status') : true;
        $sortOrder = VariantTagMasterRepository::nextSortOrder();

        $id = Uuid::v4();
        VariantTagMasterRepository::insert($id, $name, $status, $sortOrder);
        $item = VariantTagMasterRepository::findById($id);
        if ($item === null) {
            Response::json(['error' => 'Could not create tag'], 500);
            return;
        }
        Response::json(['tag' => $item], 201);
    }

    public function update(Request $request): void
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
        if (VariantTagMasterRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $name = null;
        if (array_key_exists('name', $body)) {
            $name = self::normalizeTag((string) $body['name']);
            if ($name === '') {
                throw new ValidationException('Invalid name', ['name' => 'Cannot be empty.']);
            }
        }
        $status = array_key_exists('status', $body) ? self::parseBool($body['status'], 'status') : null;
        $sortOrder = array_key_exists('sort_order', $body) ? self::parseInt($body['sort_order'], 'sort_order') : null;

        if ($name === null && $status === null && $sortOrder === null) {
            throw new ValidationException('Nothing to update', ['body' => 'Provide at least one field to update.']);
        }

        VariantTagMasterRepository::update($id, $name, $status, $sortOrder);
        $item = VariantTagMasterRepository::findById($id);
        if ($item === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        Response::json(['tag' => $item]);
    }

    public function delete(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }
        $id = trim((string) ($request->query('id') ?? ''));
        if ($id === '') {
            $body = $request->json();
            $id = trim((string) ($body['id'] ?? ''));
        }
        if ($id === '' || !Uuid::isValid($id)) {
            Response::json(['error' => 'Invalid id', 'errors' => ['id' => 'Provide a valid UUID via ?id= or JSON body.']], 422);
            return;
        }
        if (!VariantTagMasterRepository::delete($id)) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        Response::json(['ok' => true]);
    }

    private static function normalizeTag(string $raw): string
    {
        $t = trim($raw);
        while (str_starts_with($t, '#')) {
            $t = ltrim($t, '#');
        }
        $t = strtoupper(trim($t));
        $t = preg_replace('/[^A-Z0-9_-]/', '', $t) ?? '';
        return $t;
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

