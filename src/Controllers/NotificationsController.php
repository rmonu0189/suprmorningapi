<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\PushNotificationDeviceRepository;

final class NotificationsController
{
    public function registerDevice(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();
        $token = $this->stringValue($body['token'] ?? null, 4096);
        if ($token === null) {
            Response::json(['error' => 'token is required', 'errors' => ['token' => 'Required.']], 422);
            return;
        }

        $provider = $this->stringValue($body['provider'] ?? null, 32) ?? 'expo';
        $platform = $this->stringValue($body['platform'] ?? null, 32);
        $deviceId = $this->stringValue($body['device_id'] ?? null, 128);
        $deviceName = $this->stringValue($body['device_name'] ?? null, 255);
        $appVersion = $this->stringValue($body['app_version'] ?? null, 64);

        PushNotificationDeviceRepository::upsert((string) $claims['sub'], [
            'provider' => $provider,
            'token' => $token,
            'platform' => $platform,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'app_version' => $appVersion,
        ]);

        Response::json(['ok' => true]);
    }

    public function unregisterDevice(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();
        $token = $this->stringValue($body['token'] ?? null, 4096);
        if ($token !== null) {
            PushNotificationDeviceRepository::deleteForUserAndToken((string) $claims['sub'], $token);
        }

        Response::json(['ok' => true]);
    }

    /** @param mixed $value */
    private function stringValue($value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $s = trim($value);
        if ($s === '') {
            return null;
        }

        return strlen($s) > $maxLength ? substr($s, 0, $maxLength) : $s;
    }
}
