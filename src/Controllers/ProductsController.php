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
use App\Repositories\ProductRepository;
use PDOException;

final class ProductsController
{
    public function index(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        $id = $request->query('id');
        if ($id !== null && trim($id) !== '') {
            $id = trim($id);
            if (!Uuid::isValid($id)) {
                Response::json(['error' => 'Invalid id', 'errors' => ['id' => 'Must be a valid UUID']], 422);
                return;
            }
            $product = ProductRepository::findById($id);
            if ($product === null) {
                Response::json(['error' => 'Not Found'], 404);
                return;
            }
            Response::json(['product' => $product]);
            return;
        }

        $brandId = $request->query('brand_id');
        $filter = null;
        if ($brandId !== null && trim($brandId) !== '') {
            $brandId = trim($brandId);
            if (!Uuid::isValid($brandId)) {
                Response::json(['error' => 'Invalid brand_id'], 422);
                return;
            }
            $filter = $brandId;
        }

        Response::json(['products' => ProductRepository::findAll($filter)]);
    }

    public function create(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $brandId = trim((string) ($body['brand_id'] ?? ''));
        if ($brandId === '' || !Uuid::isValid($brandId)) {
            throw new ValidationException('Invalid brand_id', ['brand_id' => 'Must be a valid UUID.']);
        }
        if (BrandRepository::findById($brandId) === null) {
            Response::json(['error' => 'Not Found', 'errors' => ['brand_id' => 'Brand does not exist.']], 404);
            return;
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('Invalid name', ['name' => 'Required.']);
        }

        $description = self::optionalStringOrNull($body, 'description');
        $status = true;
        if (array_key_exists('status', $body)) {
            $status = self::parseBool($body['status'], 'status');
        }
        $metadata = null;
        if (array_key_exists('metadata', $body)) {
            $metadata = self::parseMetadata($body['metadata']);
        }

        $id = Uuid::v4();

        try {
            ProductRepository::insert($id, $brandId, $name, $description, $status, $metadata);
        } catch (PDOException $e) {
            throw new HttpException('Could not create product', 500);
        }

        $product = ProductRepository::findById($id);
        if ($product === null) {
            throw new HttpException('Could not create product', 500);
        }

        Response::json(['product' => $product], 201);
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

        if (ProductRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $brandId = null;
        if (array_key_exists('brand_id', $body)) {
            $bid = trim((string) $body['brand_id']);
            if ($bid === '' || !Uuid::isValid($bid)) {
                throw new ValidationException('Invalid brand_id', ['brand_id' => 'Must be a valid UUID.']);
            }
            if (BrandRepository::findById($bid) === null) {
                Response::json(['error' => 'Not Found', 'errors' => ['brand_id' => 'Brand does not exist.']], 404);
                return;
            }
            $brandId = $bid;
        }

        $name = null;
        if (array_key_exists('name', $body)) {
            $v = trim((string) $body['name']);
            if ($v === '') {
                throw new ValidationException('Invalid name', ['name' => 'Cannot be empty.']);
            }
            $name = $v;
        }

        $description = null;
        $descriptionProvided = array_key_exists('description', $body);
        if ($descriptionProvided) {
            $description = self::nullableTextValue($body['description'], 'description');
        }

        $status = null;
        if (array_key_exists('status', $body)) {
            $status = self::parseBool($body['status'], 'status');
        }

        $metadata = null;
        $metadataProvided = array_key_exists('metadata', $body);
        if ($metadataProvided) {
            $metadata = self::parseMetadata($body['metadata']);
        }

        if (
            $brandId === null && $name === null && !$descriptionProvided && $status === null && !$metadataProvided
        ) {
            throw new ValidationException('Nothing to update', [
                'body' => 'Provide at least one of: brand_id, name, description, status, metadata.',
            ]);
        }

        try {
            ProductRepository::update(
                $id,
                $brandId,
                $name,
                $description,
                $descriptionProvided,
                $status,
                $metadata,
                $metadataProvided
            );
        } catch (PDOException $e) {
            throw new HttpException('Could not update product', 500);
        }

        $product = ProductRepository::findById($id);
        if ($product === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['product' => $product]);
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

        if (ProductRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        if (ProductRepository::countVariantsByProductId($id) > 0) {
            Response::json([
                'error' => 'Conflict',
                'errors' => ['product' => 'Remove all variants before deleting this product.'],
            ], 409);
            return;
        }

        if (ProductRepository::countCartItemsForProductVariants($id) > 0) {
            Response::json([
                'error' => 'Conflict',
                'errors' => ['product' => 'Carts still reference variants of this product.'],
            ], 409);
            return;
        }

        if (!ProductRepository::delete($id)) {
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

    private static function parseBool(mixed $v, string $key): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if ($v === 1 || $v === 0) {
            return $v === 1;
        }

        throw new ValidationException('Invalid ' . $key, [$key => 'Must be a boolean.']);
    }

    /** @return array<string, mixed>|null */
    private static function parseMetadata(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        if (!is_array($v)) {
            throw new ValidationException('Invalid metadata', ['metadata' => 'Must be an object or null.']);
        }

        return $v;
    }
}
