<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\Database;
use PDO;

/**
 * Handles sending push notifications via Expo and Firebase Cloud Messaging (FCM) HTTP v1 API.
 */
final class PushNotificationService
{
    private const EXPO_API_URL = 'https://exp.host/--/api/v2/push/send';
    
    private static ?string $cachedFcmToken = null;
    private static int $fcmTokenExpiresAt = 0;

    /**
     * Sends a notification to all devices registered for a user and logs it.
     */
    public static function sendToUser(string $userId, string $title, string $body, array $data = [], ?string $subtitle = null): void
    {
        self::sendToUserDevices($userId, $title, $body, $data, $subtitle, false);
    }

    /**
     * Sends a silent data notification to all devices registered for a user.
     */
    public static function sendSilentToUser(string $userId, array $data = []): void
    {
        self::sendToUserDevices($userId, '', '', $data, null, true);
    }

    private static function sendToUserDevices(string $userId, string $title, string $body, array $data = [], ?string $subtitle = null, bool $silent = false): void
    {
        // 1. Log to database for historical reference in Admin portal
        if (!$silent) {
            try {
                $id = \App\Core\Uuid::v4();
                $stmtLog = Database::connection()->prepare(
                    'INSERT INTO sent_notifications (id, user_id, title, subtitle, body, data, created_at) 
                     VALUES (:id, :uid, :title, :subtitle, :body, :data, CURRENT_TIMESTAMP)'
                );
                $stmtLog->execute([
                    'id' => $id,
                    'uid' => $userId,
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'body' => $body,
                    'data' => json_encode($data),
                ]);
            } catch (\Exception $e) {
                // Silently fail logging if DB error
            }
        }

        // 2. Fetch devices
        $stmt = Database::connection()->prepare(
            'SELECT provider, token FROM push_notification_devices WHERE user_id = :uid'
        );
        $stmt->execute(['uid' => $userId]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$devices) {
            return;
        }

        foreach ($devices as $device) {
            $provider = strtolower((string) ($device['provider'] ?? ''));
            $token = (string) ($device['token'] ?? '');

            if ($token === '') continue;

            if ($provider === 'expo' || str_starts_with($token, 'ExponentPushToken')) {
                self::sendToExpo($token, $title, $body, $data, $subtitle, $silent);
            } elseif ($provider === 'fcm' || $provider === 'android' || $provider === 'gcm' || $provider === 'device') {
                self::sendToFcmV1($token, $title, $body, $data, $subtitle, $silent);
            }
        }
    }

    /**
     * Specialized helper for order delivery notification.
     */
    public static function notifyOrderDelivered(string $userId, string $orderCode, string $orderId): void
    {
        $title = "Order Delivered! 🎉";
        $subtitle = "Freshness at your doorstep";
        $body = "Your order #{$orderCode} has been delivered. Enjoy your morning!";
        $url = 'suprmorning://order_details?orderId=' . rawurlencode($orderId);
        $data = [
            'event' => 'order_status_changed',
            'silent' => false,
            'url' => $url,
            'screen' => 'OrderDetails',
            'orderId' => $orderId,
            'orderStatus' => 'delivered',
            'params' => [
                'orderId' => $orderId
            ]
        ];

        self::sendToUser($userId, $title, $body, $data, $subtitle);
    }

    /**
     * Specialized helper for silent order status refresh notifications.
     */
    public static function notifyOrderStatusChanged(string $userId, string $orderId, string $orderStatus, ?string $previousStatus = null): void
    {
        self::sendSilentToUser($userId, [
            'event' => 'order_status_changed',
            'silent' => true,
            'orderId' => $orderId,
            'orderStatus' => $orderStatus,
            'previousOrderStatus' => $previousStatus,
        ]);
    }

    private static function sendToExpo(string $token, string $title, string $body, array $data, ?string $subtitle = null, bool $silent = false): void
    {
        $payload = [
            'to' => $token,
            'data' => (object)$data,
            'priority' => 'high',
        ];

        if ($silent) {
            $payload['contentAvailable'] = true;
            $payload['sound'] = null;
        } else {
            $payload['title'] = $title;
            $payload['body'] = $body;
            $payload['sound'] = 'default';
        }

        if ($subtitle !== null && $subtitle !== '') {
            $payload['subtitle'] = $subtitle;
        }

        // Expo expects an array of notification objects
        self::postJson(self::EXPO_API_URL, json_encode([$payload]));
    }

    /**
     * Sends notification via FCM HTTP v1 API (Modern).
     */
    private static function sendToFcmV1(string $token, string $title, string $body, array $data, ?string $subtitle = null, bool $silent = false): void
    {
        $saPath = Env::get('FIREBASE_SERVICE_ACCOUNT_JSON', '');
        if ($saPath === '') return;

        // Resolve relative paths from project root
        if (!str_starts_with($saPath, '/') && !preg_match('/^[A-Za-z]:\\\\/', $saPath)) {
            $saPath = realpath(__DIR__ . '/../../') . '/' . $saPath;
        }

        if ($saPath === false || !file_exists($saPath)) return;

        $sa = json_decode((string)file_get_contents($saPath), true);
        if (!is_array($sa) || !isset($sa['project_id'])) return;

        $accessToken = self::getFcmAccessToken($sa);
        if (!$accessToken) return;

        $projectId = $sa['project_id'];
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $message = [
            'token' => $token,
            'android' => [
                'priority' => 'high',
            ],
        ];

        if (!$silent) {
            $message['notification'] = [
                'title' => $title,
                'body' => $body,
            ];
            $message['android']['notification'] = [
                'channel_id' => 'default',
            ];
        } else {
            $message['apns'] = [
                'payload' => [
                    'aps' => [
                        'content-available' => 1,
                    ],
                ],
            ];
        }

        $flattened = self::flattenData($data);
        if (!empty($flattened)) {
            $message['data'] = $flattened;
        }

        $payload = json_encode(['message' => $message]);

        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer {$accessToken}"
        ];

        self::postJson($url, $payload, $headers);
    }

    private static function getFcmAccessToken(array $sa): ?string
    {
        if (self::$cachedFcmToken && time() < self::$fcmTokenExpiresAt) {
            return self::$cachedFcmToken;
        }

        $now = time();
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $claim = json_encode([
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $input = self::base64UrlEncode($header) . '.' . self::base64UrlEncode($claim);
        $signature = '';
        if (!openssl_sign($input, $signature, $sa['private_key'], 'sha256WithRSAEncryption')) {
            return null;
        }

        $jwt = $input . '.' . self::base64UrlEncode($signature);

        $postData = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $postData,
                'timeout' => 10,
            ],
        ]);

        $resp = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
        if (!$resp) return null;

        $data = json_decode($resp, true);
        if (isset($data['access_token'])) {
            self::$cachedFcmToken = $data['access_token'];
            self::$fcmTokenExpiresAt = $now + (int)($data['expires_in'] ?? 3600) - 60;
            return self::$cachedFcmToken;
        }

        return null;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function flattenData(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $out[(string)$k] = json_encode($v);
            } else {
                $out[(string)$k] = (string)$v;
            }
        }
        return $out;
    }

    private static function postJson(string $url, string $payload, array $headers = ["Content-Type: application/json"]): void
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $payload,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        @file_get_contents($url, false, $ctx);
    }
}
