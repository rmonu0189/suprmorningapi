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
use App\Repositories\CartChargeRepository;
use PDOException;

final class CartChargesController
{
    /** Admin list (same shape as GET /v1/cart/charges). */
    public function adminIndex(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        Response::json(['charges' => CartChargeRepository::findAllOrdered()]);
    }

    public function create(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException('Invalid title', ['title' => 'Required.']);
        }

        $chargeIndex = isset($body['charge_index']) ? (int) $body['charge_index'] : 0;
        if (!array_key_exists('amount', $body)) {
            throw new ValidationException('Invalid amount', ['amount' => 'Required.']);
        }
        $amount = self::parseAmount($body['amount'], 'amount');
        $minOrderValue = self::optionalAmount($body, 'min_order_value');
        $info = null;
        if (array_key_exists('info', $body)) {
            $info = self::nullableStringValue($body['info']);
        }

        $id = Uuid::v4();

        try {
            CartChargeRepository::insert($id, $chargeIndex, $title, $amount, $minOrderValue, $info);
        } catch (PDOException $e) {
            throw new HttpException('Could not create charge', 500);
        }

        $row = CartChargeRepository::findById($id);
        if ($row === null) {
            throw new HttpException('Could not create charge', 500);
        }

        Response::json(['charge' => $row], 201);
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

        if (CartChargeRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $chargeIndex = null;
        if (array_key_exists('charge_index', $body)) {
            $chargeIndex = (int) $body['charge_index'];
        }

        $title = null;
        if (array_key_exists('title', $body)) {
            $v = trim((string) $body['title']);
            if ($v === '') {
                throw new ValidationException('Invalid title', ['title' => 'Cannot be empty.']);
            }
            $title = $v;
        }

        $amount = null;
        if (array_key_exists('amount', $body)) {
            $amount = self::parseAmount($body['amount'], 'amount');
        }

        $minOrderValue = null;
        $minProvided = array_key_exists('min_order_value', $body);
        if ($minProvided) {
            $minOrderValue = self::optionalAmountValue($body['min_order_value']);
        }

        $info = null;
        $infoProvided = array_key_exists('info', $body);
        if ($infoProvided) {
            $info = self::nullableStringValue($body['info']);
        }

        if ($chargeIndex === null && $title === null && $amount === null && !$minProvided && !$infoProvided) {
            throw new ValidationException('Nothing to update', ['body' => 'Provide at least one field.']);
        }

        try {
            CartChargeRepository::update(
                $id,
                $chargeIndex,
                $title,
                $amount,
                $minOrderValue,
                $minProvided,
                $info,
                $infoProvided
            );
        } catch (PDOException $e) {
            throw new HttpException('Could not update charge', 500);
        }

        $row = CartChargeRepository::findById($id);
        if ($row === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['charge' => $row]);
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
            Response::json(['error' => 'Invalid id'], 422);
            return;
        }

        if (CartChargeRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        if (!CartChargeRepository::delete($id)) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['ok' => true]);
    }

    private static function parseAmount(mixed $v, string $key): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }

        throw new ValidationException('Invalid ' . $key, [$key => 'Must be a number.']);
    }

    /** @param array<string, mixed> $body */
    private static function optionalAmount(array $body, string $key): ?float
    {
        if (!array_key_exists($key, $body)) {
            return null;
        }

        return self::optionalAmountValue($body[$key]);
    }

    private static function optionalAmountValue(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }

        throw new ValidationException('Invalid min_order_value', ['min_order_value' => 'Must be a number or null.']);
    }

    private static function nullableStringValue(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!is_string($v)) {
            throw new ValidationException('Invalid info', ['info' => 'Must be a string or null.']);
        }
        $t = trim($v);

        return $t === '' ? null : $t;
    }
}
