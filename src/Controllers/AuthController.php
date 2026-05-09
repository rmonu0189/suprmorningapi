<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\Exceptions\HttpException;
use App\Core\Phone;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\Uuid;
use App\Middleware\AuthMiddleware;
use App\Repositories\UserRepository;
use App\Repositories\FileRepository;
use App\Security\LoginLockout;
use App\Security\RateLimiter;
use App\Services\AuthService;

final class AuthController
{
    private AuthService $auth;
    private LoginLockout $lockout;
    private RateLimiter $rateLimiter;

    public function __construct()
    {
        $this->auth = new AuthService();
        $this->lockout = new LoginLockout();
        $this->rateLimiter = new RateLimiter();
    }

    public function otpSend(Request $request): void
    {
        Validator::requireJsonContentType($request);

        $ip = $request->ip();
        $rlIp = $this->rateLimiter->consume('auth:otp:send:ip:' . $ip, 30, 3600);
        if (!$rlIp['allowed']) {
            throw new HttpException('Too many OTP requests', 429, [
                'retry_after' => $rlIp['retry_after'],
            ]);
        }

        $body = $request->json();
        $phone = (string) ($body['phone'] ?? '');
        $parsed = Phone::parseLocalAndCountryCode($phone);
        if ($parsed !== null) {
            $rlPhone = $this->rateLimiter->consume('auth:otp:send:phone:' . $parsed['country_code'] . ':' . $parsed['phone'], 5, 900);
            if (!$rlPhone['allowed']) {
                throw new HttpException('Too many OTP requests for this phone number', 429, [
                    'retry_after' => $rlPhone['retry_after'],
                ]);
            }
        }

        $ua = $this->resolveUserAgent($request, $body);
        if ($ua !== null) {
            $existing = $body['user_agent'] ?? null;
            if (!is_string($existing) || trim($existing) === '') {
                $body['user_agent'] = $ua;
            }
        }

        $payload = $this->auth->requestOtp($body);
        Response::json($payload);
    }

    public function otpVerify(Request $request): void
    {
        Validator::requireJsonContentType($request);

        $body = $request->json();
        $phone = (string) ($body['phone'] ?? '');
        $parsed = Phone::parseLocalAndCountryCode($phone);
        if ($parsed === null) {
            Response::json([
                'error' => 'Invalid phone number',
                'errors' => ['phone' => 'Provide 10 digits (without country code).'],
            ], 422);
            return;
        }

        $ip = $request->ip();
        $lockKey = $parsed['country_code'] . ':' . $parsed['phone'];
        $locked = $this->lockout->isLocked($lockKey, $ip);
        if ($locked['locked']) {
            throw new HttpException('Too many failed attempts', 429, [
                'retry_after' => $locked['retry_after'],
            ]);
        }

        $rl = $this->rateLimiter->consume('auth:otp:verify:ip:' . $ip, 80, 900);
        if (!$rl['allowed']) {
            throw new HttpException('Too many verification attempts', 429, [
                'retry_after' => $rl['retry_after'],
            ]);
        }

        $ua = $this->resolveUserAgent($request, $body);
        $device = $this->optionalDeviceLabel($body);

        try {
            $payload = $this->auth->verifyOtp($body, $ua, $device);
        } catch (HttpException $e) {
            if ($e->statusCode() === 401) {
                $this->lockout->onFailedAttempt($lockKey, $ip);
            }
            throw $e;
        }

        $this->lockout->onSuccessfulLogin($lockKey, $ip);

        Response::json($payload);
    }

    public function refresh(Request $request): void
    {
        Validator::requireJsonContentType($request);

        $body = $request->json();
        $token = (string) ($body['refresh_token'] ?? '');
        $ua = $this->resolveUserAgent($request, $body);
        $device = $this->optionalDeviceLabel($body);

        $payload = $this->auth->refresh($token, $ua, $device);
        Response::json($payload);
    }

    public function logout(Request $request): void
    {
        Validator::requireJsonContentType($request);

        $body = $request->json();
        $token = (string) ($body['refresh_token'] ?? '');
        $this->auth->logout($token);
        Response::json(['ok' => true]);
    }

    public function me(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        $sub = (string) ($claims['sub'] ?? '');
        $user = UserRepository::findById($sub);
        if ($user === null) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        Response::json(['user' => $user]);
    }

    public function patchMe(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();
        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        if (!array_key_exists('full_name', $body) && !array_key_exists('avatar_url', $body)) {
            Response::json(['error' => 'Nothing to update', 'errors' => ['fields' => 'Provide full_name or avatar_url.']], 422);
            return;
        }

        if (array_key_exists('full_name', $body)) {
            $raw = $body['full_name'];
            $fullName = null;
            if ($raw !== null && $raw !== '') {
                $s = trim((string) $raw);
                if (strlen($s) > 255) {
                    Response::json(['error' => 'Invalid full name', 'errors' => ['full_name' => 'At most 255 characters.']], 422);
                    return;
                }
                $fullName = $s === '' ? null : $s;
            }
            UserRepository::updateFullName($sub, $fullName);
        }

        if (array_key_exists('avatar_url', $body)) {
            $rawAvatar = $body['avatar_url'];
            $avatarUrl = null;
            if ($rawAvatar !== null && $rawAvatar !== '') {
                $s = trim((string) $rawAvatar);
                if (strlen($s) > 2048) {
                    Response::json(['error' => 'Invalid avatar URL', 'errors' => ['avatar_url' => 'Too long.']], 422);
                    return;
                }
                $avatarUrl = $s === '' ? null : $s;
            }
            UserRepository::updateAvatarUrl($sub, $avatarUrl);
        }

        $user = UserRepository::findById($sub);
        if ($user === null) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        Response::json(['user' => $user]);
    }

    public function deleteAccount(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        Validator::requireJsonContentType($request);
        $body = $request->json();
        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $payload = $this->auth->deleteAccount($sub, $body);
        Response::json($payload);
    }

    private const AVATAR_MAX_BYTES = 5_000_000; // 5 MB
    private const ALLOWED_AVATAR_MIME = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function uploadAvatar(Request $request): void
    {
        $claims = AuthMiddleware::requireAuth($request);
        if ($claims === null) {
            return;
        }

        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        if (!isset($_FILES['file'])) {
            Response::json(['error' => 'Missing file', 'errors' => ['file' => 'Provide multipart field "file".']], 422);
            return;
        }

        $f = $_FILES['file'];
        if (!is_array($f) || !isset($f['tmp_name'], $f['size'], $f['error'])) {
            Response::json(['error' => 'Invalid upload'], 422);
            return;
        }

        if ((int) $f['error'] !== UPLOAD_ERR_OK) {
            Response::json(['error' => 'Upload failed', 'errors' => ['file' => 'Upload error code: ' . (string) $f['error']]], 422);
            return;
        }

        $size = (int) ($f['size'] ?? 0);
        if ($size <= 0 || $size > self::AVATAR_MAX_BYTES) {
            Response::json(['error' => 'Invalid file size', 'errors' => ['file' => 'Max 5MB.']], 422);
            return;
        }

        $tmp = (string) $f['tmp_name'];
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Response::json(['error' => 'Invalid file'], 422);
            return;
        }

        $mime = $this->detectMime($tmp);
        if ($mime === null || !array_key_exists($mime, self::ALLOWED_AVATAR_MIME)) {
            Response::json(['error' => 'Unsupported file type', 'errors' => ['file' => 'Only JPEG, PNG, or WEBP images are allowed.']], 422);
            return;
        }

        $ext = self::ALLOWED_AVATAR_MIME[$mime];
        $fileName = 'avatar-' . (string) time() . '-' . bin2hex(random_bytes(8)) . '.' . $ext;

        $storageRoot = __DIR__ . '/../../storage';
        $relativeDir = 'uploads/avatars';
        $destDir = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativeDir;
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                throw new HttpException('Could not create upload directory', 500);
            }
        }

        $destPath = $destDir . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($tmp, $destPath)) {
            throw new HttpException('Could not save file', 500);
        }

        @chmod($destPath, 0644);

        $id = Uuid::v4();
        $accessKey = bin2hex(random_bytes(32));
        $storagePath = $relativeDir . '/' . $fileName;
        $originalName = isset($f['name']) && is_string($f['name']) ? $f['name'] : null;

        FileRepository::insert(
            $id,
            $sub,
            'avatars',
            $storagePath,
            $originalName,
            $mime,
            $size,
            $accessKey
        );

        $appUrl = Env::get('APP_URL', '');
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $isLocalHost = is_string($host) && ($host === 'localhost' || str_starts_with($host, 'localhost:') || str_starts_with($host, '127.0.0.1'));
        $base = ($appUrl !== '' && !$isLocalHost) ? rtrim($appUrl, '/') : $this->inferBaseUrl();
        $url = $base . '/v1/files?id=' . $id . '&key=' . $accessKey;

        Response::json([
            'id' => $id,
            'url' => $url,
            'mime' => $mime,
            'size' => $size,
        ], 201);
    }

    private function detectMime(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) return null;
        $mime = finfo_file($finfo, $path);
        if (function_exists('finfo_close')) {
            @finfo_close($finfo);
        }
        return is_string($mime) ? $mime : null;
    }

    private function inferBaseUrl(): string
    {
        $proto = 'https';
        $xfp = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        if (is_string($xfp) && $xfp !== '') {
            $proto = explode(',', $xfp)[0] ?: $proto;
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $proto = 'https';
        } else {
            $proto = 'http';
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $proto . '://' . $host;
    }

    /** @param array<string, mixed> $body */
    private function resolveUserAgent(Request $request, array $body): ?string
    {
        $fromBody = $body['user_agent'] ?? null;
        if (is_string($fromBody) && trim($fromBody) !== '') {
            $s = trim($fromBody);
            return strlen($s) > 512 ? substr($s, 0, 512) : $s;
        }

        $h = $request->header('User-Agent');
        if ($h === null || trim($h) === '') {
            return null;
        }

        return strlen($h) > 512 ? substr($h, 0, 512) : $h;
    }

    /** @param array<string, mixed> $body */
    private function optionalDeviceLabel(array $body): ?string
    {
        $v = $body['device_label'] ?? null;
        if (!is_string($v)) {
            return null;
        }

        $s = trim($v);
        if ($s === '') {
            return null;
        }

        return strlen($s) > 128 ? substr($s, 0, 128) : $s;
    }
}
