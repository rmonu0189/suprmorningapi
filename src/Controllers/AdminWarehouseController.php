<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\WarehouseRepository;
use PDOException;

final class AdminWarehouseController
{
    public function index(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        $id = $request->query('id');
        if ($id !== null && trim($id) !== '') {
            $id = trim($id);
            if (!preg_match('/^\d+$/', $id)) {
                Response::json(['error' => 'Invalid id', 'errors' => ['id' => 'Must be a valid integer id.']], 422);
                return;
            }
            $row = WarehouseRepository::findById((int) $id);
            if ($row === null) {
                Response::json(['error' => 'Not Found'], 404);
                return;
            }
            Response::json(['warehouse' => $row]);
            return;
        }

        Response::json(['warehouses' => WarehouseRepository::findAll()]);
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

        $a1 = trim((string) ($body['address_line_1'] ?? ''));
        if ($a1 === '') {
            throw new ValidationException('Invalid address_line_1', ['address_line_1' => 'Required.']);
        }

        $city = trim((string) ($body['city'] ?? ''));
        if ($city === '') {
            throw new ValidationException('Invalid city', ['city' => 'Required.']);
        }
        $state = trim((string) ($body['state'] ?? ''));
        if ($state === '') {
            throw new ValidationException('Invalid state', ['state' => 'Required.']);
        }
        $country = trim((string) ($body['country'] ?? ''));
        if ($country === '') {
            throw new ValidationException('Invalid country', ['country' => 'Required.']);
        }
        $postalCode = trim((string) ($body['postal_code'] ?? ''));
        if ($postalCode === '') {
            throw new ValidationException('Invalid postal_code', ['postal_code' => 'Required.']);
        }

        $a2 = self::optionalNullableString($body, 'address_line_2');
        $area = self::optionalNullableString($body, 'area');
        $latitude = self::optionalFloat($body, 'latitude', 0.0);
        $longitude = self::optionalFloat($body, 'longitude', 0.0);

        $status = true;
        if (array_key_exists('status', $body)) {
            $status = self::parseStatus($body['status']);
        }

        // Friendly conflict on duplicate names (unique index still protects).
        if (WarehouseRepository::findByName($name) !== null) {
            Response::json(['error' => 'Conflict', 'errors' => ['name' => 'Warehouse name already exists.']], 409);
            return;
        }

        try {
            $id = WarehouseRepository::insert($name, $a1, $a2, $area, $city, $state, $country, $postalCode, $latitude, $longitude, $status);
        } catch (PDOException $e) {
            throw new HttpException('Could not create warehouse', 500);
        }

        $row = WarehouseRepository::findById((int) $id);
        if ($row === null) {
            throw new HttpException('Could not create warehouse', 500);
        }

        Response::json(['warehouse' => $row], 201);
    }

    public function update(Request $request): void
    {
        if (AuthMiddleware::requireAdmin($request) === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();

        $idRaw = trim((string) ($body['id'] ?? ''));
        if ($idRaw === '' || !preg_match('/^\d+$/', $idRaw)) {
            throw new ValidationException('Invalid id', ['id' => 'A valid integer id is required.']);
        }
        $id = (int) $idRaw;

        if (WarehouseRepository::findById($id) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $name = null;
        if (array_key_exists('name', $body)) {
            $v = trim((string) $body['name']);
            if ($v === '') throw new ValidationException('Invalid name', ['name' => 'Cannot be empty.']);
            $name = $v;
        }

        $a1 = null;
        if (array_key_exists('address_line_1', $body)) {
            $v = trim((string) $body['address_line_1']);
            if ($v === '') throw new ValidationException('Invalid address_line_1', ['address_line_1' => 'Cannot be empty.']);
            $a1 = $v;
        }

        $a2Provided = array_key_exists('address_line_2', $body);
        $a2 = null;
        if ($a2Provided) {
            $a2 = self::nullableTextValue($body['address_line_2'], 'address_line_2');
        }

        $areaProvided = array_key_exists('area', $body);
        $area = null;
        if ($areaProvided) {
            $area = self::nullableTextValue($body['area'], 'area');
        }

        $city = null;
        if (array_key_exists('city', $body)) {
            $v = trim((string) $body['city']);
            if ($v === '') throw new ValidationException('Invalid city', ['city' => 'Cannot be empty.']);
            $city = $v;
        }
        $state = null;
        if (array_key_exists('state', $body)) {
            $v = trim((string) $body['state']);
            if ($v === '') throw new ValidationException('Invalid state', ['state' => 'Cannot be empty.']);
            $state = $v;
        }
        $country = null;
        if (array_key_exists('country', $body)) {
            $v = trim((string) $body['country']);
            if ($v === '') throw new ValidationException('Invalid country', ['country' => 'Cannot be empty.']);
            $country = $v;
        }
        $postalCode = null;
        if (array_key_exists('postal_code', $body)) {
            $v = trim((string) $body['postal_code']);
            if ($v === '') throw new ValidationException('Invalid postal_code', ['postal_code' => 'Cannot be empty.']);
            $postalCode = $v;
        }

        $latitude = null;
        if (array_key_exists('latitude', $body)) {
            $latitude = self::parseFloatValue($body['latitude'], 'latitude');
        }
        $longitude = null;
        if (array_key_exists('longitude', $body)) {
            $longitude = self::parseFloatValue($body['longitude'], 'longitude');
        }

        $status = null;
        if (array_key_exists('status', $body)) {
            $status = self::parseStatus($body['status']);
        }

        if (
            $name === null && $a1 === null && !$a2Provided && !$areaProvided &&
            $city === null && $state === null && $country === null && $postalCode === null &&
            $latitude === null && $longitude === null && $status === null
        ) {
            throw new ValidationException('Nothing to update', [
                'body' => 'Provide at least one field to update.',
            ]);
        }

        try {
            WarehouseRepository::update(
                $id,
                $name,
                $a1,
                $a2,
                $a2Provided,
                $area,
                $areaProvided,
                $city,
                $state,
                $country,
                $postalCode,
                $latitude,
                $longitude,
                $status
            );
        } catch (PDOException $e) {
            throw new HttpException('Could not update warehouse', 500);
        }

        $row = WarehouseRepository::findById((int) $id);
        if ($row === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        Response::json(['warehouse' => $row]);
    }

    private static function optionalNullableString(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body)) return null;
        return self::nullableTextValue($body[$key], $key);
    }

    private static function nullableTextValue(mixed $v, string $key): ?string
    {
        if ($v === null) return null;
        if (!is_string($v)) {
            throw new ValidationException('Invalid ' . $key, [$key => 'Must be a string or null.']);
        }
        $t = trim($v);
        return $t === '' ? null : $t;
    }

    private static function parseStatus(mixed $v): bool
    {
        if (is_bool($v)) return $v;
        if ($v === 1 || $v === 0) return $v === 1;
        throw new ValidationException('Invalid status', ['status' => 'Must be a boolean.']);
    }

    private static function optionalFloat(array $body, string $key, float $default): float
    {
        if (!array_key_exists($key, $body)) return $default;
        return self::parseFloatValue($body[$key], $key);
    }

    private static function parseFloatValue(mixed $v, string $key): float
    {
        if (is_int($v) || is_float($v)) return (float) $v;
        if (is_string($v) && is_numeric($v)) return (float) $v;
        throw new ValidationException('Invalid ' . $key, [$key => 'Must be a number.']);
    }
}

