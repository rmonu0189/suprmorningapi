<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\AddressRepository;

final class AddressController
{
    public function index(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        Response::json(['addresses' => AddressRepository::findByUserId($userId)]);
    }

    public function create(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $row = self::parseAddressBody($body, false);
        $id = Uuid::v4();

        if ($row['is_default']) {
            AddressRepository::clearDefaultForUserExcept($userId, null);
        }

        AddressRepository::insert(
            $id,
            $userId,
            $row['label'],
            $row['recipient_name'],
            $row['phone'],
            $row['address_line_1'],
            $row['address_line_2'],
            $row['area'],
            $row['city'],
            $row['state'],
            $row['country'],
            $row['postal_code'],
            $row['latitude'],
            $row['longitude'],
            $row['is_default']
        );

        $created = AddressRepository::findByIdForUser($id, $userId);
        Response::json(['address' => $created], 201);
    }

    public function update(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $id = trim((string) ($body['id'] ?? ''));
        if ($id === '' || !Uuid::isValid($id)) {
            throw new ValidationException('Invalid id', ['id' => 'A valid UUID is required.']);
        }

        if (AddressRepository::findByIdForUser($id, $userId) === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $row = self::parseAddressBody($body, true);
        if ($row['is_default']) {
            AddressRepository::clearDefaultForUserExcept($userId, $id);
        }

        AddressRepository::updateForUser(
            $id,
            $userId,
            $row['label'],
            $row['recipient_name'],
            $row['phone'],
            $row['address_line_1'],
            $row['address_line_2'],
            $row['area'],
            $row['city'],
            $row['state'],
            $row['country'],
            $row['postal_code'],
            $row['latitude'],
            $row['longitude'],
            $row['is_default']
        );

        Response::json(['address' => AddressRepository::findByIdForUser($id, $userId)]);
    }

    public function delete(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        $id = trim((string) ($request->query('id') ?? ''));
        if ($id === '' || !Uuid::isValid($id)) {
            Response::json(['error' => 'Invalid id'], 422);
            return;
        }
        if (!AddressRepository::delete($id, $userId)) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        Response::json(['ok' => true]);
    }

    public function setDefault(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        Validator::requireJsonContentType($request);
        $body = $request->json();
        $id = trim((string) ($body['id'] ?? ''));
        if ($id === '' || !Uuid::isValid($id)) {
            throw new ValidationException('Invalid id', ['id' => 'A valid UUID is required.']);
        }
        if (!AddressRepository::setDefault($id, $userId)) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }
        Response::json(['ok' => true]);
    }

    public function setDefaultLatest(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }
        $userId = (string) ($claims['sub'] ?? '');
        AddressRepository::setDefaultLatest($userId);
        Response::json(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{label: string, recipient_name: string, phone: string, address_line_1: string, address_line_2: ?string, area: ?string, city: string, state: string, country: string, postal_code: string, latitude: float, longitude: float, is_default: bool}
     */
    private static function parseAddressBody(array $body, bool $forUpdate): array
    {
        $req = static function (string $key) use ($body, $forUpdate): string {
            if (!array_key_exists($key, $body) && $forUpdate) {
                throw new ValidationException('Missing field', [$key => 'Required for update.']);
            }
            $v = trim((string) ($body[$key] ?? ''));
            if ($v === '' && !$forUpdate && in_array($key, ['label', 'recipient_name', 'phone', 'address_line_1', 'city', 'state', 'country', 'postal_code'], true)) {
                throw new ValidationException('Invalid ' . $key, [$key => 'Required.']);
            }

            return $v;
        };

        return [
            'label' => $req('label'),
            'recipient_name' => $req('recipient_name'),
            'phone' => $req('phone'),
            'address_line_1' => $req('address_line_1'),
            'address_line_2' => isset($body['address_line_2']) ? (trim((string) $body['address_line_2']) ?: null) : null,
            'area' => isset($body['area']) ? (trim((string) $body['area']) ?: null) : null,
            'city' => $req('city'),
            'state' => $req('state'),
            'country' => $req('country'),
            'postal_code' => $req('postal_code'),
            'latitude' => isset($body['latitude']) ? (float) $body['latitude'] : 0.0,
            'longitude' => isset($body['longitude']) ? (float) $body['longitude'] : 0.0,
            'is_default' => !empty($body['is_default']),
        ];
    }
}
