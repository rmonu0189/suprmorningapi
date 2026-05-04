<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Uuid;
use PDO;

final class PushNotificationDeviceRepository
{
    /**
     * @param array{
     *   provider: string,
     *   token: string,
     *   platform: string|null,
     *   device_id: string|null,
     *   device_name: string|null,
     *   app_version: string|null
     * } $input
     */
    public static function upsert(string $userId, array $input): void
    {
        $pdo = Database::connection();
        $tokenHash = hash('sha256', $input['token']);
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare(
                'INSERT INTO push_notification_devices
                    (id, user_id, provider, token, token_hash, platform, device_id, device_name, app_version, last_seen_at, created_at, updated_at)
                 VALUES
                    (:id, :user_id, :provider, :token, :token_hash, :platform, :device_id, :device_name, :app_version, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                 ON CONFLICT(token_hash) DO UPDATE SET
                    user_id = excluded.user_id,
                    provider = excluded.provider,
                    token = excluded.token,
                    platform = excluded.platform,
                    device_id = excluded.device_id,
                    device_name = excluded.device_name,
                    app_version = excluded.app_version,
                    last_seen_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP'
            );
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO push_notification_devices
                    (id, user_id, provider, token, token_hash, platform, device_id, device_name, app_version, last_seen_at)
                 VALUES
                    (:id, :user_id, :provider, :token, :token_hash, :platform, :device_id, :device_name, :app_version, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    provider = VALUES(provider),
                    token = VALUES(token),
                    platform = VALUES(platform),
                    device_id = VALUES(device_id),
                    device_name = VALUES(device_name),
                    app_version = VALUES(app_version),
                    last_seen_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP'
            );
        }

        $stmt->execute([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'provider' => $input['provider'],
            'token' => $input['token'],
            'token_hash' => $tokenHash,
            'platform' => $input['platform'],
            'device_id' => $input['device_id'],
            'device_name' => $input['device_name'],
            'app_version' => $input['app_version'],
        ]);
    }

    public static function deleteForUserAndToken(string $userId, string $token): void
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM push_notification_devices WHERE user_id = :user_id AND token_hash = :token_hash'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
        ]);
    }
}
