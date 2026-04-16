<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Repositories\AddressRepository;
use App\Repositories\WarehouseRepository;

final class ServiceabilityController
{
    public function show(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        $userId = (string) ($claims['sub'] ?? '');
        if ($userId === '') {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $address = AddressRepository::findFirstByUserId($userId);
        if ($address === null) {
            Response::json([
                'has_address' => false,
                'serviceable' => false,
                'nearest_warehouse' => null,
            ]);
            return;
        }

        $lat = isset($address['latitude']) ? (float) $address['latitude'] : 0.0;
        $lng = isset($address['longitude']) ? (float) $address['longitude'] : 0.0;

        $nearestId = null;
        if ($lat != 0.0 || $lng != 0.0) {
            $nearestId = WarehouseRepository::findNearestEnabledId($lat, $lng);
        }

        if ($nearestId === null) {
            Response::json([
                'has_address' => true,
                'serviceable' => false,
                'nearest_warehouse' => null,
            ]);
            return;
        }

        $wh = WarehouseRepository::findById($nearestId);
        if ($wh === null) {
            Response::json([
                'has_address' => true,
                'serviceable' => false,
                'nearest_warehouse' => null,
            ]);
            return;
        }

        $whLat = isset($wh['latitude']) ? (float) $wh['latitude'] : 0.0;
        $whLng = isset($wh['longitude']) ? (float) $wh['longitude'] : 0.0;
        $radiusKm = isset($wh['radius_km']) ? (float) $wh['radius_km'] : 0.0;

        $distanceKm = self::haversineKm($lat, $lng, $whLat, $whLng);
        $serviceable = $radiusKm > 0 && $distanceKm <= $radiusKm;

        Response::json([
            'has_address' => true,
            'serviceable' => $serviceable,
            'nearest_warehouse' => [
                'id' => (int) ($wh['id'] ?? 0),
                'name' => (string) ($wh['name'] ?? ''),
                'radius_km' => $radiusKm,
                'distance_km' => $distanceKm,
            ],
        ]);
    }

    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $r * $c;
    }
}

