<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Uuid;
use PDO;

final class WarehouseRepository
{
    /**
     * @return list<array{
     *   id: int,
     *   uuid: string,
     *   created_at: string,
     *   name: string,
     *   address_line_1: string,
     *   address_line_2: string|null,
     *   area: string|null,
     *   city: string,
     *   state: string,
     *   country: string,
     *   postal_code: string,
     *   latitude: float,
     *   longitude: float,
     *   status: bool
     * }>
     */
    public static function findAll(): array
    {
        $stmt = Database::connection()->query(
            'SELECT id, uuid, created_at, name, address_line_1, address_line_2, area, city, state, country, postal_code,
                    latitude, longitude, status
             FROM warehouses
             ORDER BY status DESC, id ASC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::normalizeRow($row);
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, uuid, created_at, name, address_line_1, address_line_2, area, city, state, country, postal_code,
                    latitude, longitude, status
             FROM warehouses WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return self::normalizeRow($row);
    }

    public static function findByName(string $name): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, uuid, created_at, name, address_line_1, address_line_2, area, city, state, country, postal_code,
                    latitude, longitude, status
             FROM warehouses WHERE name = :name LIMIT 1'
        );
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return self::normalizeRow($row);
    }

    /** Find nearest enabled warehouse to a coordinate. */
    public static function findNearestEnabledId(float $lat, float $lng): ?int
    {
        // Simple squared distance in degrees is good enough for nearby selection.
        $stmt = Database::connection()->prepare(
            'SELECT id
             FROM warehouses
             WHERE status = 1
             ORDER BY ((latitude - :lat1) * (latitude - :lat2) + (longitude - :lng1) * (longitude - :lng2)) ASC, id ASC
             LIMIT 1'
        );
        $stmt->execute(['lat1' => $lat, 'lat2' => $lat, 'lng1' => $lng, 'lng2' => $lng]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null || $v === '') {
            return null;
        }
        return (int) $v;
    }

    public static function insert(
        string $name,
        string $addressLine1,
        ?string $addressLine2,
        ?string $area,
        string $city,
        string $state,
        string $country,
        string $postalCode,
        float $latitude,
        float $longitude,
        bool $status
    ): int {
        $uuid = Uuid::v4();
        $stmt = Database::connection()->prepare(
            'INSERT INTO warehouses (uuid, name, address_line_1, address_line_2, area, city, state, country, postal_code, latitude, longitude, status)
             VALUES (:uuid, :name, :a1, :a2, :area, :city, :st, :ctry, :pc, :lat, :lng, :status)'
        );
        $stmt->execute([
            'uuid' => $uuid,
            'name' => $name,
            'a1' => $addressLine1,
            'a2' => $addressLine2,
            'area' => $area,
            'city' => $city,
            'st' => $state,
            'ctry' => $country,
            'pc' => $postalCode,
            'lat' => $latitude,
            'lng' => $longitude,
            'status' => $status ? 1 : 0,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function update(
        int $id,
        ?string $name,
        ?string $addressLine1,
        ?string $addressLine2,
        bool $addressLine2Provided,
        ?string $area,
        bool $areaProvided,
        ?string $city,
        ?string $state,
        ?string $country,
        ?string $postalCode,
        ?float $latitude,
        ?float $longitude,
        ?bool $status
    ): void {
        $sets = [];
        $params = ['id' => $id];

        if ($name !== null) {
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }
        if ($addressLine1 !== null) {
            $sets[] = 'address_line_1 = :a1';
            $params['a1'] = $addressLine1;
        }
        if ($addressLine2Provided) {
            $sets[] = 'address_line_2 = :a2';
            $params['a2'] = $addressLine2;
        }
        if ($areaProvided) {
            $sets[] = 'area = :area';
            $params['area'] = $area;
        }
        if ($city !== null) {
            $sets[] = 'city = :city';
            $params['city'] = $city;
        }
        if ($state !== null) {
            $sets[] = 'state = :st';
            $params['st'] = $state;
        }
        if ($country !== null) {
            $sets[] = 'country = :ctry';
            $params['ctry'] = $country;
        }
        if ($postalCode !== null) {
            $sets[] = 'postal_code = :pc';
            $params['pc'] = $postalCode;
        }
        if ($latitude !== null) {
            $sets[] = 'latitude = :lat';
            $params['lat'] = $latitude;
        }
        if ($longitude !== null) {
            $sets[] = 'longitude = :lng';
            $params['lng'] = $longitude;
        }
        if ($status !== null) {
            $sets[] = 'status = :status';
            $params['status'] = $status ? 1 : 0;
        }

        if ($sets === []) return;

        $sql = 'UPDATE warehouses SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }

    public static function delete(int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM warehouses WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $a2 = $row['address_line_2'] ?? null;
        $area = $row['area'] ?? null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'uuid' => (string) ($row['uuid'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'name' => (string) $row['name'],
            'address_line_1' => (string) $row['address_line_1'],
            'address_line_2' => $a2 === null || $a2 === '' ? null : (string) $a2,
            'area' => $area === null || $area === '' ? null : (string) $area,
            'city' => (string) $row['city'],
            'state' => (string) $row['state'],
            'country' => (string) $row['country'],
            'postal_code' => (string) $row['postal_code'],
            'latitude' => isset($row['latitude']) ? (float) $row['latitude'] : 0.0,
            'longitude' => isset($row['longitude']) ? (float) $row['longitude'] : 0.0,
            'status' => (bool) (int) $row['status'],
        ];
    }
}

