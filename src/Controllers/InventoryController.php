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
use App\Repositories\VariantRepository;
use PDOException;

final class InventoryController
{
    public function index(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        $variantId = $request->query('variant_id');
        $filter = null;
        if ($variantId !== null && trim($variantId) !== '') {
            $variantId = trim($variantId);
            if (!Uuid::isValid($variantId)) {
                Response::json(['error' => 'Invalid variant_id'], 422);
                return;
            }
            $filter = $variantId;
        }

        Response::json(['inventory' => InventoryRepository::findAll($filter)]);
    }

    public function update(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $variantId = trim((string) ($body['variant_id'] ?? ''));
        if ($variantId === '' || !Uuid::isValid($variantId)) {
            throw new ValidationException('Invalid variant_id', ['variant_id' => 'Must be a valid UUID.']);
        }

        if (VariantRepository::findById($variantId) === null) {
            Response::json(['error' => 'Not Found', 'errors' => ['variant_id' => 'Variant does not exist.']], 404);
            return;
        }

        $quantity = null;
        if (array_key_exists('quantity', $body)) {
            $quantity = self::parseNonNegativeInt($body['quantity'], 'quantity');
        }

        $reserved = null;
        if (array_key_exists('reserved_quantity', $body)) {
            $reserved = self::parseNonNegativeInt($body['reserved_quantity'], 'reserved_quantity');
        }

        if ($quantity === null && $reserved === null) {
            throw new ValidationException('Nothing to update', [
                'body' => 'Provide quantity and/or reserved_quantity.',
            ]);
        }

        $existing = InventoryRepository::findByVariantId($variantId);
        try {
            if ($existing === null) {
                $q = $quantity ?? 0;
                $r = $reserved ?? 0;
                InventoryRepository::insert(Uuid::v4(), $variantId, $q, $r);
            } else {
                InventoryRepository::updateQuantities(
                    $variantId,
                    $quantity,
                    $reserved
                );
            }
        } catch (PDOException $e) {
            throw new HttpException('Could not update inventory', 500);
        }

        $row = InventoryRepository::findByVariantId($variantId);
        if ($row === null) {
            throw new HttpException('Could not load inventory', 500);
        }

        Response::json(['inventory' => $row]);
    }

    private static function parseNonNegativeInt(mixed $v, string $key): int
    {
        if (is_int($v)) {
            $n = $v;
        } elseif (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            $n = (int) $v;
        } else {
            throw new ValidationException('Invalid ' . $key, [$key => 'Must be a non-negative integer.']);
        }
        if ($n < 0) {
            throw new ValidationException('Invalid ' . $key, [$key => 'Must be a non-negative integer.']);
        }

        return $n;
    }
}
