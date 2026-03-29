<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Phone;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Middleware\AuthMiddleware;
use App\Repositories\UserRepository;
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
        $normalized = Phone::normalize($phone);
        if ($normalized !== null) {
            $rlPhone = $this->rateLimiter->consume('auth:otp:send:phone:' . $normalized, 5, 900);
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
        $normalized = Phone::normalize($phone);

        if ($normalized === null) {
            Response::json([
                'error' => 'Invalid phone number',
                'errors' => ['phone' => 'Provide 10–15 digits (country code included if applicable).'],
            ], 422);
            return;
        }

        $ip = $request->ip();
        $locked = $this->lockout->isLocked($normalized, $ip);
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
                $this->lockout->onFailedAttempt($normalized, $ip);
            }
            throw $e;
        }

        $this->lockout->onSuccessfulLogin($normalized, $ip);

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
