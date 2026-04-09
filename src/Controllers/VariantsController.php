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
use App\Repositories\InventoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\VariantRepository;
use PDOException;

final class VariantsController
{
    public function index(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        $q = $request->query('q');
        if ($q !== null && trim($q) !== '') {
            $q = trim($q);
            $limitRaw = $request->query('limit');
            $limit = 50;
            if ($limitRaw !== null && trim((string) $limitRaw) !== '' && preg_match('/^\d+$/', trim((string) $limitRaw))) {
                $limit = (int) trim((string) $limitRaw);
            }
            if ($limit < 1) {
                $limit = 1;
            }
            if ($limit > 200) {
                $limit = 200;
            }

            Response::json(['variants' => VariantRepository::searchWithContext($q, $limit)]);

            return;
        }

        $id = $request->query('id');
        if ($id !== null && trim($id) !== '') {
            $id = trim($id);
            if (!Uuid::isValid($id)) {
                Response::json(['error' => 'Invalid id', 'errors' => ['id' => 'Must be a valid UUID']], 422);
                return;
            }
            $variant = VariantRepository::findById($id);
            if ($variant === null) {
                Response::json(['error' => 'Not Found'], 404);
                return;
            }
            Response::json(['variant' => $variant]);
            return;
        }

        $productId = $request->query('product_id');
        $filter = null;
        if ($productId !== null && trim($productId) !== '') {
            $productId = trim($productId);
            if (!Uuid::isValid($productId)) {
                Response::json(['error' => 'Invalid product_id'], 422);
                return;
            }
            $filter = $productId;
        }

        Response::json(['variants' => VariantRepository::findAll($filter)]);
    }

    public function create(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }
        if (((string) ($claims['role'] ?? '')) === 'manager') {
            Response::json(['error' => 'Forbidden'], 403);
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $productId = trim((string) ($body['product_id'] ?? ''));
        if ($productId === '' || !Uuid::isValid($productId)) {
            throw new ValidationException('Invalid product_id', ['product_id' => 'Must be a valid UUID.']);
        }
        if (ProductRepository::findById($productId) === null) {
            Response::json(['error' => 'Not Found', 'errors' => ['product_id' => 'Product does not exist.']], 404);
            return;
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('Invalid name', ['name' => 'Required.']);
        }

        $sku = trim((string) ($body['sku'] ?? ''));
        if ($sku === '') {
            throw new ValidationException('Invalid sku', ['sku' => 'Required.']);
        }
        if (VariantRepository::skuExists($sku, null)) {
            Response::json(['error' => 'Conflict', 'errors' => ['sku' => 'SKU already exists.']], 409);
            return;
        }

        $price = self::parseDecimal($body['price'] ?? null, 'price', true);
        $mrp = self::parseDecimal($body['mrp'] ?? null, 'mrp', true);

        $images = self::parseImagesArray($body['images'] ?? []);
        $metadata = null;
        if (array_key_exists('metadata', $body)) {
            $metadata = self::parseMetadata($body['metadata']);
        }

        $status = true;
        if (array_key_exists('status', $body)) {
            $status = self::parseBool($body['status'], 'status');
        }

        $discountTag = null;
        if (array_key_exists('discount_tag', $body)) {
            $discountTag = self::nullableString($body['discount_tag'], 'discount_tag');
        }

        $id = Uuid::v4();

        try {
            VariantRepository::insert(
                $id,
                $productId,
                $name,
                $sku,
                $price,
                $mrp,
                $images,
                $metadata,
                $status,
                $discountTag
            );
            // Legacy/global bucket (admin can later adjust per-warehouse stock).
            InventoryRepository::insert(Uuid::v4(), 0, $id, 0, 0);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate') || str_contains($msg, '1062')) {
                Response::json(['error' => 'Conflict', 'errors' => ['sku' => 'SKU already exists.']], 409);
                return;
            }
            throw new HttpException('Could not create variant', 500);
        }

        $variant = VariantRepository::findById($id);
        if ($variant === null) {
            throw new HttpException('Could not create variant', 500);
        }

        Response::json(['variant' => $variant], 201);
    }

    public function update(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }
        if (((string) ($claims['role'] ?? '')) === 'manager') {
            Response::json(['error' => 'Forbidden'], 403);
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $id = trim((string) ($body['id'] ?? ''));
        if ($id === '' || !Uuid::isValid($id)) {
            throw new ValidationException('Invalid id', ['id' => 'A valid UUID is required.']);
        }

        if (VariantRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $productId = null;
        if (array_key_exists('product_id', $body)) {
            $pid = trim((string) $body['product_id']);
            if ($pid === '' || !Uuid::isValid($pid)) {
                throw new ValidationException('Invalid product_id', ['product_id' => 'Must be a valid UUID.']);
            }
            if (ProductRepository::findById($pid) === null) {
                Response::json(['error' => 'Not Found', 'errors' => ['product_id' => 'Product does not exist.']], 404);
                return;
            }
            $productId = $pid;
        }

        $name = null;
        if (array_key_exists('name', $body)) {
            $v = trim((string) $body['name']);
            if ($v === '') {
                throw new ValidationException('Invalid name', ['name' => 'Cannot be empty.']);
            }
            $name = $v;
        }

        $sku = null;
        if (array_key_exists('sku', $body)) {
            $v = trim((string) $body['sku']);
            if ($v === '') {
                throw new ValidationException('Invalid sku', ['sku' => 'Cannot be empty.']);
            }
            if (VariantRepository::skuExists($v, $id)) {
                Response::json(['error' => 'Conflict', 'errors' => ['sku' => 'SKU already exists.']], 409);
                return;
            }
            $sku = $v;
        }

        $price = null;
        if (array_key_exists('price', $body)) {
            $price = self::parseDecimal($body['price'], 'price', false);
        }

        $mrp = null;
        if (array_key_exists('mrp', $body)) {
            $mrp = self::parseDecimal($body['mrp'], 'mrp', false);
        }

        $images = null;
        $imagesProvided = array_key_exists('images', $body);
        if ($imagesProvided) {
            $images = self::parseImagesArray($body['images']);
        }

        $metadata = null;
        $metadataProvided = array_key_exists('metadata', $body);
        if ($metadataProvided) {
            $metadata = self::parseMetadata($body['metadata']);
        }

        $status = null;
        if (array_key_exists('status', $body)) {
            $status = self::parseBool($body['status'], 'status');
        }

        $discountTag = null;
        $discountTagProvided = array_key_exists('discount_tag', $body);
        if ($discountTagProvided) {
            $discountTag = self::nullableString($body['discount_tag'], 'discount_tag');
        }

        if (
            $productId === null && $name === null && $sku === null && $price === null && $mrp === null
            && !$imagesProvided && !$metadataProvided && $status === null && !$discountTagProvided
        ) {
            throw new ValidationException('Nothing to update', [
                'body' => 'Provide at least one field to update.',
            ]);
        }

        try {
            VariantRepository::update(
                $id,
                $productId,
                $name,
                $sku,
                $price,
                $mrp,
                $images,
                $imagesProvided,
                $metadata,
                $metadataProvided,
                $status,
                $discountTag,
                $discountTagProvided
            );
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate') || str_contains($msg, '1062')) {
                Response::json(['error' => 'Conflict', 'errors' => ['sku' => 'SKU already exists.']], 409);
                return;
            }
            throw new HttpException('Could not update variant', 500);
        }

        $variant = VariantRepository::findById($id);
        if ($variant === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['variant' => $variant]);
    }

    public function delete(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }
        if (((string) ($claims['role'] ?? '')) === 'manager') {
            Response::json(['error' => 'Forbidden'], 403);
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

        if (VariantRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        if (InventoryRepository::countCartItemsForVariant($id) > 0) {
            Response::json([
                'error' => 'Conflict',
                'errors' => ['variant' => 'Cannot delete: variant is still in one or more carts.'],
            ], 409);
            return;
        }

        if (!VariantRepository::delete($id)) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['ok' => true]);
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

    private static function parseDecimal(mixed $v, string $key, bool $required): float
    {
        if ($v === null && !$required) {
            throw new ValidationException('Invalid ' . $key, [$key => 'Required.']);
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }

        throw new ValidationException('Invalid ' . $key, [$key => 'Must be a number.']);
    }

    /** @return list<string> */
    private static function parseImagesArray(mixed $v): array
    {
        if (!is_array($v)) {
            throw new ValidationException('Invalid images', ['images' => 'Must be an array of strings.']);
        }
        $out = [];
        foreach ($v as $item) {
            if (!is_string($item)) {
                throw new ValidationException('Invalid images', ['images' => 'Must be an array of strings.']);
            }
            $out[] = trim($item);
        }

        return $out;
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

    private static function nullableString(mixed $v, string $key): ?string
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
}
