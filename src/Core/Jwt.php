<?php

declare(strict_types=1);

namespace App\Core;

final class Jwt
{
    public static function issue(array $claims, ?int $ttlSeconds = null): string
    {
        $now = time();
        if ($ttlSeconds !== null) {
            $ttl = max(60, $ttlSeconds);
        } else {
            $access = Env::get('JWT_ACCESS_TTL_SECONDS');
            if ($access !== null && $access !== '') {
                $ttl = max(60, (int) $access);
            } else {
                $ttl = max(60, (int) Env::get('JWT_TTL_SECONDS', '3600'));
            }
        }

        $payload = array_merge($claims, [
            'typ' => 'access',
            'iss' => Env::get('JWT_ISSUER', 'api'),
            'aud' => Env::get('JWT_AUDIENCE', 'clients'),
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            self::b64(json_encode($header, JSON_THROW_ON_ERROR)),
            self::b64(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), self::secret(), true);
        $segments[] = self::b64($signature);

        return implode('.', $segments);
    }

    public static function verify(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;
        $expected = self::b64(hash_hmac('sha256', $headerB64 . '.' . $payloadB64, self::secret(), true));
        if (!hash_equals($expected, $sigB64)) {
            return null;
        }

        $payload = json_decode(self::ub64($payloadB64), true);
        if (!is_array($payload)) {
            return null;
        }

        $now = time();
        if (!isset($payload['exp']) || (int) $payload['exp'] < $now) {
            return null;
        }

        if (isset($payload['typ']) && $payload['typ'] !== 'access') {
            return null;
        }

        return $payload;
    }

    private static function secret(): string
    {
        return Env::get('JWT_SECRET', 'change-me-now') ?? 'change-me-now';
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function ub64(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
