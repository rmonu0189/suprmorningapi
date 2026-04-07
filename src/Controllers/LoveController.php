<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\LoveRepository;

final class LoveController
{
    public function index(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        Response::json(['loves' => LoveRepository::findAllWithVariantsForUser($userId)]);
    }

    public function ids(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        Response::json(['variant_ids' => LoveRepository::variantIdsForUser($userId)]);
    }

    public function add(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $variantId = trim((string) ($body['variant_id'] ?? ''));
        if ($variantId === '' || !Uuid::isValid($variantId)) {
            throw new ValidationException('Invalid variant_id', ['variant_id' => 'Must be a valid UUID.']);
        }
        if (LoveRepository::exists($userId, $variantId)) {
            Response::json(['ok' => true]);
            return;
        }
        LoveRepository::insert(Uuid::v4(), $userId, $variantId);
        Response::json(['ok' => true], 201);
    }

    public function remove(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        $variantId = trim((string) ($request->query('variant_id') ?? ''));
        if ($variantId === '' || !Uuid::isValid($variantId)) {
            Response::json(['error' => 'Invalid variant_id'], 422);
            return;
        }
        LoveRepository::delete($userId, $variantId);
        Response::json(['ok' => true]);
    }
}
