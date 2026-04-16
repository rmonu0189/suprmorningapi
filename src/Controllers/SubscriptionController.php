<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\SubscriptionRepository;
use App\Repositories\VariantRepository;

final class SubscriptionController
{
    /** GET /v1/subscriptions */
    public function index(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        if ($userId === '' || !Uuid::isValid($userId)) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $rows = SubscriptionRepository::findAllByUser($userId);
        Response::json(['subscriptions' => $rows]);
    }

    /** GET /v1/subscriptions/by-variant?variant_id=... */
    public function byVariant(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        if ($userId === '' || !Uuid::isValid($userId)) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $variantId = trim((string) ($request->query('variant_id') ?? ''));
        if ($variantId === '' || !Uuid::isValid($variantId)) {
            throw new ValidationException('Invalid variant_id', ['variant_id' => 'A valid UUID is required.']);
        }

        $sub = SubscriptionRepository::findLatestByUserAndVariant($userId, $variantId);
        Response::json(['subscription' => $sub]);
    }

    /** POST /v1/subscriptions */
    public function create(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        if ($userId === '' || !Uuid::isValid($userId)) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $variantId = trim((string) ($body['variant_id'] ?? ''));
        if ($variantId === '' || !Uuid::isValid($variantId)) {
            throw new ValidationException('Invalid variant_id', ['variant_id' => 'A valid UUID is required.']);
        }
        if (VariantRepository::findById($variantId) === null) {
            Response::json(['error' => 'Variant not found'], 404);
            return;
        }

        $frequency = strtolower(trim((string) ($body['frequency'] ?? '')));
        if (!in_array($frequency, ['daily', 'weekly', 'alternate'], true)) {
            throw new ValidationException('Invalid frequency', ['frequency' => 'Must be daily, weekly, or alternate.']);
        }

        $qty = (int) ($body['quantity'] ?? 1);
        if ($qty < 1) {
            throw new ValidationException('Invalid quantity', ['quantity' => 'Must be at least 1.']);
        }

        $startDate = trim((string) ($body['start_date'] ?? ''));
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if ($dt === false || $dt->format('Y-m-d') !== $startDate) {
            throw new ValidationException('Invalid start_date', ['start_date' => 'Use YYYY-MM-DD.']);
        }

        $weekly = null;
        if ($frequency === 'weekly') {
            $raw = $body['weekly_schedule'] ?? null;
            if (!is_array($raw) || $raw === []) {
                throw new ValidationException('Invalid weekly_schedule', ['weekly_schedule' => 'Select at least one day.']);
            }
            $weekly = [];
            foreach ($raw as $it) {
                if (!is_array($it)) continue;
                $day = isset($it['day']) ? (int) $it['day'] : -1;
                $q = isset($it['quantity']) ? (int) $it['quantity'] : 0;
                if ($day < 0 || $day > 6) {
                    throw new ValidationException('Invalid weekly_schedule', ['weekly_schedule' => 'Day must be 0..6.']);
                }
                if ($q < 1) {
                    throw new ValidationException('Invalid weekly_schedule', ['weekly_schedule' => 'Quantity must be at least 1.']);
                }
                $weekly[] = ['day' => $day, 'quantity' => $q];
            }
            if ($weekly === []) {
                throw new ValidationException('Invalid weekly_schedule', ['weekly_schedule' => 'Select at least one day.']);
            }
            // For weekly, quantity is represented in weekly schedule.
            $qty = 1;
        }

        $id = Uuid::v4();
        SubscriptionRepository::insert($id, $userId, $variantId, $frequency, $qty, $weekly, $startDate);
        Response::json(['id' => $id], 201);
    }

    /** PUT /v1/subscriptions */
    public function update(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        if ($userId === '' || !Uuid::isValid($userId)) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $id = trim((string) ($body['id'] ?? ''));
        if ($id === '' || !Uuid::isValid($id)) {
            throw new ValidationException('Invalid id', ['id' => 'A valid UUID is required.']);
        }
        $existing = SubscriptionRepository::findByIdForUser($id, $userId);
        if ($existing === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $frequency = strtolower(trim((string) ($body['frequency'] ?? '')));
        if (!in_array($frequency, ['daily', 'weekly', 'alternate'], true)) {
            throw new ValidationException('Invalid frequency', ['frequency' => 'Must be daily, weekly, or alternate.']);
        }

        $qty = (int) ($body['quantity'] ?? 1);
        if ($qty < 1) {
            throw new ValidationException('Invalid quantity', ['quantity' => 'Must be at least 1.']);
        }

        $startDate = trim((string) ($body['start_date'] ?? ''));
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if ($dt === false || $dt->format('Y-m-d') !== $startDate) {
            throw new ValidationException('Invalid start_date', ['start_date' => 'Use YYYY-MM-DD.']);
        }

        $weekly = null;
        if ($frequency === 'weekly') {
            $raw = $body['weekly_schedule'] ?? null;
            if (!is_array($raw) || $raw === []) {
                throw new ValidationException('Invalid weekly_schedule', ['weekly_schedule' => 'Select at least one day.']);
            }
            $weekly = [];
            foreach ($raw as $it) {
                if (!is_array($it)) continue;
                $day = isset($it['day']) ? (int) $it['day'] : -1;
                $q = isset($it['quantity']) ? (int) $it['quantity'] : 0;
                if ($day < 0 || $day > 6) {
                    throw new ValidationException('Invalid weekly_schedule', ['weekly_schedule' => 'Day must be 0..6.']);
                }
                if ($q < 1) {
                    throw new ValidationException('Invalid weekly_schedule', ['weekly_schedule' => 'Quantity must be at least 1.']);
                }
                $weekly[] = ['day' => $day, 'quantity' => $q];
            }
            if ($weekly === []) {
                throw new ValidationException('Invalid weekly_schedule', ['weekly_schedule' => 'Select at least one day.']);
            }
            $qty = 1;
        }

        SubscriptionRepository::updateByIdForUser($id, $userId, $frequency, $qty, $weekly, $startDate);
        $updated = SubscriptionRepository::findByIdForUser($id, $userId);
        Response::json(['subscription' => $updated]);
    }

    /** DELETE /v1/subscriptions?id=... */
    public function cancel(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        if ($userId === '' || !Uuid::isValid($userId)) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $id = trim((string) ($request->query('id') ?? ''));
        if ($id === '' || !Uuid::isValid($id)) {
            throw new ValidationException('Invalid id', ['id' => 'A valid UUID is required.']);
        }

        $ok = SubscriptionRepository::deleteByIdForUser($id, $userId);
        if (!$ok) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['ok' => true]);
    }
}
