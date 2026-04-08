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
use App\Repositories\InventoryMovementRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\VariantRepository;
use PDOException;

final class InventoryMovementsController
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

        $limitRaw = $request->query('limit');
        $offsetRaw = $request->query('offset');
        $limit = is_string($limitRaw) && preg_match('/^\d+$/', $limitRaw) ? (int) $limitRaw : 100;
        $offset = is_string($offsetRaw) && preg_match('/^\d+$/', $offsetRaw) ? (int) $offsetRaw : 0;

        Response::json([
            'movements' => InventoryMovementRepository::findAll($filter, $limit, $offset),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function create(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
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

        $delta = $body['delta_quantity'] ?? null;
        if (!is_int($delta)) {
            if (is_string($delta) && preg_match('/^-?\d+$/', $delta)) {
                $delta = (int) $delta;
            } else {
                throw new ValidationException('Invalid delta_quantity', ['delta_quantity' => 'Must be an integer (can be negative).']);
            }
        }
        if ($delta === 0) {
            throw new ValidationException('Invalid delta_quantity', ['delta_quantity' => 'Must not be 0.']);
        }

        $note = null;
        if (array_key_exists('note', $body)) {
            $v = $body['note'];
            if ($v === null) {
                $note = null;
            } elseif (!is_string($v)) {
                throw new ValidationException('Invalid note', ['note' => 'Must be a string or null.']);
            } else {
                $t = trim($v);
                $note = $t === '' ? null : $t;
            }
        }

        $createdBy = null;
        $sub = (string) ($claims['sub'] ?? '');
        if ($sub !== '' && Uuid::isValid($sub)) {
            $createdBy = $sub;
        }

        // Apply movement: update inventory and insert movement record.
        $existing = InventoryRepository::findByVariantId($variantId);
        $currentQty = is_array($existing) ? (int) ($existing['quantity'] ?? 0) : 0;
        $newQty = $currentQty + $delta;
        if ($newQty < 0) {
            throw new ValidationException('Insufficient stock', ['delta_quantity' => 'Would make quantity negative.']);
        }

        try {
            if ($existing === null) {
                InventoryRepository::insert(Uuid::v4(), $variantId, $newQty, 0);
            } else {
                InventoryRepository::updateQuantities($variantId, $newQty, null);
            }
            InventoryMovementRepository::insert(Uuid::v4(), $variantId, $delta, $note, $createdBy);
        } catch (PDOException $e) {
            throw new HttpException('Could not record inventory movement', 500);
        }

        $row = InventoryRepository::findByVariantId($variantId);
        if ($row === null) {
            throw new HttpException('Could not load inventory', 500);
        }

        Response::json(['inventory' => $row], 201);
    }
}

