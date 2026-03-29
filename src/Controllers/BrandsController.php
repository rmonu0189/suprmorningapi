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
use App\Repositories\BrandRepository;
use PDOException;

final class BrandsController
{
    /** List all brands, or one brand when query param id is a valid UUID. */
    public function index(Request $request): void
    {
        $id = $request->query('id');
        if ($id !== null && trim($id) !== '') {
            $id = trim($id);
            if (!Uuid::isValid($id)) {
                Response::json(['error' => 'Invalid id', 'errors' => ['id' => 'Must be a valid UUID']], 422);
                return;
            }

            $brand = BrandRepository::findById($id);
            if ($brand === null) {
                Response::json(['error' => 'Not Found'], 404);
                return;
            }

            Response::json(['brand' => $brand]);
            return;
        }

        Response::json(['brands' => BrandRepository::findAll()]);
    }

    public function create(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('Invalid name', ['name' => 'Required.']);
        }

        $about = self::optionalStringOrNull($body, 'about');
        $logo = self::optionalStringOrNull($body, 'logo');
        $status = true;
        if (array_key_exists('status', $body)) {
            $status = self::parseStatus($body['status']);
        }

        $id = Uuid::v4();

        try {
            BrandRepository::insert($id, $name, $about, $logo, $status);
        } catch (PDOException $e) {
            throw new HttpException('Could not create brand', 500);
        }

        $brand = BrandRepository::findById($id);
        if ($brand === null) {
            throw new HttpException('Could not create brand', 500);
        }

        Response::json(['brand' => $brand], 201);
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

        if (BrandRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $name = null;
        if (array_key_exists('name', $body)) {
            $v = trim((string) $body['name']);
            if ($v === '') {
                throw new ValidationException('Invalid name', ['name' => 'Cannot be empty.']);
            }
            $name = $v;
        }

        $about = null;
        $aboutProvided = array_key_exists('about', $body);
        if ($aboutProvided) {
            $about = self::nullableTextValue($body['about'], 'about');
        }

        $logo = null;
        $logoProvided = array_key_exists('logo', $body);
        if ($logoProvided) {
            $logo = self::nullableTextValue($body['logo'], 'logo');
        }

        $status = null;
        if (array_key_exists('status', $body)) {
            $status = self::parseStatus($body['status']);
        }

        if ($name === null && !$aboutProvided && !$logoProvided && $status === null) {
            throw new ValidationException('Nothing to update', [
                'body' => 'Provide at least one of: name, about, logo, status.',
            ]);
        }

        try {
            BrandRepository::update($id, $name, $about, $aboutProvided, $logo, $logoProvided, $status);
        } catch (PDOException $e) {
            throw new HttpException('Could not update brand', 500);
        }

        $brand = BrandRepository::findById($id);
        if ($brand === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['brand' => $brand]);
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
            Response::json([
                'error' => 'Invalid id',
                'errors' => ['id' => 'Provide a valid UUID via ?id= or JSON body.'],
            ], 422);
            return;
        }

        if (BrandRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        if (BrandRepository::countProductsByBrandId($id) > 0) {
            Response::json([
                'error' => 'Conflict',
                'errors' => ['brand' => 'Cannot delete a brand that still has products. Remove or reassign products first.'],
            ], 409);
            return;
        }

        if (!BrandRepository::delete($id)) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['ok' => true]);
    }

    private static function optionalStringOrNull(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body)) {
            return null;
        }

        return self::nullableTextValue($body[$key], $key);
    }

    private static function nullableTextValue(mixed $v, string $key): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!is_string($v)) {
            throw new ValidationException('Invalid ' . $key, [$key => 'Must be a string or null.']);
        }

        $t = trim($v);

        return $t === '' ? null : $t;
    }

    private static function parseStatus(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if ($v === 1 || $v === 0) {
            return $v === 1;
        }

        throw new ValidationException('Invalid status', ['status' => 'Must be a boolean.']);
    }
}
