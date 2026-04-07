<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class AddressRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function findByUserId(string $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, label, recipient_name, phone, address_line_1, address_line_2, area,
                    city, state, country, postal_code, latitude, longitude, is_default, created_at
             FROM addresses WHERE user_id = :uid ORDER BY is_default DESC, created_at DESC, id DESC'
        );
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::normalize($row);
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function findByIdForUser(string $id, string $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, user_id, label, recipient_name, phone, address_line_1, address_line_2, area,
                    city, state, country, postal_code, latitude, longitude, is_default, created_at
             FROM addresses WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::normalize($row);
    }

    public static function insert(
        string $id,
        string $userId,
        string $label,
        string $recipientName,
        string $phone,
        string $addressLine1,
        ?string $addressLine2,
        ?string $area,
        string $city,
        string $state,
        string $country,
        string $postalCode,
        float $latitude,
        float $longitude,
        bool $isDefault
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO addresses (id, user_id, label, recipient_name, phone, address_line_1, address_line_2, area,
                city, state, country, postal_code, latitude, longitude, is_default)
             VALUES (:id, :uid, :label, :rn, :phone, :a1, :a2, :area, :city, :state, :country, :pc, :lat, :lng, :def)'
        );
        $stmt->execute([
            'id' => $id,
            'uid' => $userId,
            'label' => $label,
            'rn' => $recipientName,
            'phone' => $phone,
            'a1' => $addressLine1,
            'a2' => $addressLine2,
            'area' => $area,
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'pc' => $postalCode,
            'lat' => $latitude,
            'lng' => $longitude,
            'def' => $isDefault ? 1 : 0,
        ]);
    }

    public static function updateForUser(
        string $id,
        string $userId,
        string $label,
        string $recipientName,
        string $phone,
        string $addressLine1,
        ?string $addressLine2,
        ?string $area,
        string $city,
        string $state,
        string $country,
        string $postalCode,
        float $latitude,
        float $longitude,
        bool $isDefault
    ): void {
        $stmt = Database::connection()->prepare(
            'UPDATE addresses SET label = :label, recipient_name = :rn, phone = :phone,
                address_line_1 = :a1, address_line_2 = :a2, area = :area, city = :city, state = :state,
                country = :country, postal_code = :pc, latitude = :lat, longitude = :lng, is_default = :def
             WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([
            'id' => $id,
            'uid' => $userId,
            'label' => $label,
            'rn' => $recipientName,
            'phone' => $phone,
            'a1' => $addressLine1,
            'a2' => $addressLine2,
            'area' => $area,
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'pc' => $postalCode,
            'lat' => $latitude,
            'lng' => $longitude,
            'def' => $isDefault ? 1 : 0,
        ]);
    }

    public static function clearDefaultForUserExcept(string $userId, ?string $exceptId): void
    {
        if ($exceptId !== null) {
            $stmt = Database::connection()->prepare(
                'UPDATE addresses SET is_default = 0 WHERE user_id = :uid AND id != :eid'
            );
            $stmt->execute(['uid' => $userId, 'eid' => $exceptId]);
        } else {
            $stmt = Database::connection()->prepare(
                'UPDATE addresses SET is_default = 0 WHERE user_id = :uid'
            );
            $stmt->execute(['uid' => $userId]);
        }
    }

    public static function setDefault(string $id, string $userId): bool
    {
        self::clearDefaultForUserExcept($userId, null);
        $stmt = Database::connection()->prepare(
            'UPDATE addresses SET is_default = 1 WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public static function setDefaultLatest(string $userId): bool
    {
        self::clearDefaultForUserExcept($userId, null);
        $stmt = Database::connection()->prepare(
            'SELECT id FROM addresses WHERE user_id = :uid ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['uid' => $userId]);
        $id = $stmt->fetchColumn();
        if ($id === false || !is_string($id)) {
            return false;
        }

        $u = Database::connection()->prepare(
            'UPDATE addresses SET is_default = 1 WHERE id = :id AND user_id = :uid'
        );
        $u->execute(['id' => $id, 'uid' => $userId]);

        return true;
    }

    public static function delete(string $id, string $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM addresses WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** @param array<string, mixed> $row */
    private static function normalize(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'user_id' => (string) $row['user_id'],
            'label' => (string) $row['label'],
            'recipient_name' => (string) $row['recipient_name'],
            'phone' => (string) $row['phone'],
            'address_line_1' => (string) $row['address_line_1'],
            'address_line_2' => $row['address_line_2'] !== null && $row['address_line_2'] !== '' ? (string) $row['address_line_2'] : null,
            'area' => $row['area'] !== null && $row['area'] !== '' ? (string) $row['area'] : null,
            'city' => (string) $row['city'],
            'state' => (string) $row['state'],
            'country' => (string) $row['country'],
            'postal_code' => (string) $row['postal_code'],
            'latitude' => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
            'is_default' => (bool) (int) $row['is_default'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
