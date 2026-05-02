<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Jwt;
use App\Core\Phone;
use App\Core\Uuid;
use App\Repositories\OrderRepository;
use App\Repositories\PhoneOtpChallengeRepository;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\ReferralRepository;
use App\Repositories\SubscriptionOrderGenerationRepository;
use App\Repositories\SubscriptionRepository;
use App\Repositories\UserRepository;
use App\Repositories\WalletHoldRepository;
use App\Repositories\WalletRepository;
use DateTimeImmutable;
use PDOException;

final class AuthService
{
    private const MAX_FULL_NAME_LENGTH = 255;
    private const REGISTRATION_CLIENT_MOBILE = 'mobile';
    private const ADMIN_PORTAL_FORBIDDEN_MESSAGE = 'You are not authorized to access this portal';

    /** @return array{ok: true, expires_in: int, debug_otp_code?: string} */
    public function requestOtp(array $body): array
    {
        $parsed = Phone::parseLocalAndCountryCode((string) ($body['phone'] ?? ''), UserRepository::DEFAULT_COUNTRY_CODE);
        if ($parsed === null) {
            throw new ValidationException('Invalid phone number', [
                'phone' => 'Provide 10 digits (without country code).',
            ]);
        }

        $phone = $parsed['phone'];
        $countryCode = $parsed['country_code'];

        $auth = UserRepository::findAuthByPhone($phone, $countryCode);
        if ($auth !== null && !$auth['is_active']) {
            throw new HttpException('Account is disabled', 403);
        }

        // Block admin portal access for new numbers and for normal "user" accounts.
        if (!$this->isMobileRegistrationClient($body)) {
            if ($auth === null) {
                throw new HttpException(self::ADMIN_PORTAL_FORBIDDEN_MESSAGE, 403);
            }
            $role = (string) ($auth['role'] ?? UserRepository::DEFAULT_ROLE);
            if ($role === UserRepository::DEFAULT_ROLE) {
                throw new HttpException(self::ADMIN_PORTAL_FORBIDDEN_MESSAGE, 403);
            }
        }

        $ttl = $this->otpTtlSeconds();
        $length = $this->otpLength();

        return $this->createAndDispatchChallenge($phone, $ttl, $length);
    }

    /**
     * @return array{user: array<string, mixed>, access_token: string, refresh_token: string, expires_in: int}
     */
    public function verifyOtp(array $body, ?string $userAgent, ?string $deviceLabel): array
    {
        $parsed = Phone::parseLocalAndCountryCode((string) ($body['phone'] ?? ''), UserRepository::DEFAULT_COUNTRY_CODE);
        if ($parsed === null) {
            throw new ValidationException('Invalid phone number', [
                'phone' => 'Provide 10 digits (without country code).',
            ]);
        }

        $phone = $parsed['phone'];
        $countryCode = $parsed['country_code'];

        $code = $this->normalizeOtpCode((string) ($body['code'] ?? ''));
        if ($code === null) {
            throw new ValidationException('Invalid code', [
                'code' => 'Enter the ' . $this->otpLength() . '-digit code.',
            ]);
        }

        $isExisting = UserRepository::phoneTaken($phone, $countryCode);
        $registerFullName = null;
        $registerEmail = null;
        $referrer = null;

        if (!$isExisting) {
            if (!$this->isMobileRegistrationClient($body)) {
                throw new HttpException(self::ADMIN_PORTAL_FORBIDDEN_MESSAGE, 403);
            }
            $registerFullName = $this->optionalFullName($body['full_name'] ?? null);
            $registerEmail = $this->parseOptionalRegisterEmail($body['email'] ?? null);
            if ($registerEmail !== null && UserRepository::emailTaken($registerEmail)) {
                throw new HttpException('Email already in use', 409);
            }
            $rawReferralCode = $body['referral_code'] ?? null;
            if (is_string($rawReferralCode) && trim($rawReferralCode) !== '') {
                $referralCode = ReferralRepository::normalizeCode($rawReferralCode);
                if ($referralCode === null) {
                    throw new ValidationException('Invalid referral code', [
                        'referral_code' => 'Enter a valid referral code.',
                    ]);
                }
                $referrer = ReferralService::validateReferralCode($referralCode);
            }
        }

        $row = PhoneOtpChallengeRepository::findValid($phone);
        if ($row === null) {
            throw new HttpException('Invalid or expired code', 401);
        }

        $maxAttempts = $this->otpMaxAttempts();
        if ($row['attempts'] >= $maxAttempts) {
            PhoneOtpChallengeRepository::deleteById($row['id']);
            throw new HttpException('Too many failed attempts', 401);
        }

        $salt = @hex2bin($row['salt_hex']);
        if ($salt === false) {
            PhoneOtpChallengeRepository::deleteById($row['id']);
            throw new HttpException('Invalid or expired code', 401);
        }

        $expectedHash = hash('sha256', $salt . $code);
        if (!hash_equals($row['code_hash'], $expectedHash)) {
            PhoneOtpChallengeRepository::incrementAttempts($row['id']);
            $again = PhoneOtpChallengeRepository::findValid($phone);
            if ($again !== null && $again['attempts'] >= $maxAttempts) {
                PhoneOtpChallengeRepository::deleteById($again['id']);
            }
            throw new HttpException('Invalid or expired code', 401);
        }

        PhoneOtpChallengeRepository::deleteById($row['id']);

        // If this request is coming from anything other than the mobile app, only allow staff/admin roles.
        if (!$this->isMobileRegistrationClient($body)) {
            $auth = UserRepository::findAuthByPhone($phone, $countryCode);
            $role = is_array($auth) ? (string) ($auth['role'] ?? UserRepository::DEFAULT_ROLE) : UserRepository::DEFAULT_ROLE;
            if ($role === UserRepository::DEFAULT_ROLE) {
                throw new HttpException(self::ADMIN_PORTAL_FORBIDDEN_MESSAGE, 403);
            }
        }

        if ($isExisting) {
            return $this->completeLoginAfterOtp($phone, $countryCode, $userAgent, $deviceLabel);
        }

        return $this->completeRegisterAfterOtp($phone, $countryCode, $registerFullName, $registerEmail, $userAgent, $deviceLabel, $referrer);
    }

    /**
     * @return array{user: array<string, mixed>, access_token: string, refresh_token: string, expires_in: int}
     */
    public function refresh(string $rawRefreshToken, ?string $userAgent, ?string $deviceLabel): array
    {
        $rawRefreshToken = trim($rawRefreshToken);
        if ($rawRefreshToken === '') {
            throw new HttpException('Invalid refresh token', 401);
        }

        $hash = hash('sha256', $rawRefreshToken);
        $tok = RefreshTokenRepository::findValidByHash($hash);
        if ($tok === null) {
            throw new HttpException('Invalid or expired refresh token', 401);
        }

        RefreshTokenRepository::deleteById($tok['id']);

        $user = UserRepository::findById($tok['user_id']);
        if ($user === null) {
            throw new HttpException('Invalid refresh token', 401);
        }

        if (!$user['is_active']) {
            throw new HttpException('Account is disabled', 403);
        }

        return $this->issueTokensForUser($user['id'], $user, $userAgent, $deviceLabel);
    }

    public function logout(string $rawRefreshToken): void
    {
        $rawRefreshToken = trim($rawRefreshToken);
        if ($rawRefreshToken === '') {
            throw new ValidationException('refresh_token is required', ['refresh_token' => 'Required.']);
        }

        $hash = hash('sha256', $rawRefreshToken);
        RefreshTokenRepository::deleteByHash($hash);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{ok: true}
     */
    public function deleteAccount(string $userId, array $body): array
    {
        $reasonCode = trim((string) ($body['reason_code'] ?? ''));
        if ($reasonCode === '' || !$this->isValidDeleteReasonCode($reasonCode)) {
            throw new ValidationException('Invalid reason_code', [
                'reason_code' => 'Provide a valid reason code.',
            ]);
        }

        $reasonText = null;
        if (array_key_exists('reason_text', $body) && $body['reason_text'] !== null) {
            $reasonText = trim((string) $body['reason_text']);
            if ($reasonText === '') {
                $reasonText = null;
            }
            if ($reasonText !== null && strlen($reasonText) > 500) {
                throw new ValidationException('Invalid reason_text', [
                    'reason_text' => 'Must be at most 500 characters.',
                ]);
            }
        }
        if ($reasonCode === 'other' && ($reasonText === null || $reasonText === '')) {
            throw new ValidationException('Invalid reason_text', [
                'reason_text' => 'Please share details for "other" reason.',
            ]);
        }

        $inProgressOrders = OrderRepository::countInProgressByUserId($userId);
        if ($inProgressOrders > 0) {
            throw new HttpException('You have an active order flow. Please complete it before deleting account.', 409, [
                'block_code' => 'ACTIVE_ORDER_FLOW',
            ]);
        }

        $wallet = WalletRepository::findByUserId($userId);
        if (((float) ($wallet['balance'] ?? 0.0)) > 0.0) {
            throw new HttpException('Wallet balance remains. Please use your wallet balance before deleting account.', 409, [
                'block_code' => 'WALLET_BALANCE_REMAINS',
            ]);
        }
        if (((float) ($wallet['locked_balance'] ?? 0.0)) > 0.0) {
            throw new HttpException('Wallet locked balance remains. Please wait for payment processing to finish.', 409, [
                'block_code' => 'WALLET_LOCKED_BALANCE_REMAINS',
            ]);
        }

        if (WalletHoldRepository::countActiveByUserId($userId) > 0) {
            throw new HttpException('Wallet hold is active for a pending order.', 409, [
                'block_code' => 'ACTIVE_WALLET_HOLD',
            ]);
        }

        if (SubscriptionRepository::countByUserId($userId) > 0) {
            throw new HttpException('You have active subscriptions. Please cancel them before deleting account.', 409, [
                'block_code' => 'ACTIVE_SUBSCRIPTION',
            ]);
        }

        if (SubscriptionOrderGenerationRepository::countPendingByUserId($userId) > 0) {
            throw new HttpException('Subscription order generation is in progress. Please try again later.', 409, [
                'block_code' => 'PENDING_SUBSCRIPTION_GENERATION',
            ]);
        }

        $contact = UserRepository::findContactById($userId);
        if ($contact === null) {
            throw new HttpException('Unauthorized', 401);
        }

        $archivedPhone = $this->buildArchivedPhone((string) ($contact['phone'] ?? ''), $userId);
        $originalPhone = (string) ($contact['phone'] ?? '');
        $originalEmail = isset($contact['email']) && is_string($contact['email']) ? $contact['email'] : null;

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            UserRepository::deactivateAndArchiveIdentity(
                $userId,
                $archivedPhone,
                $originalPhone !== '' ? $originalPhone : null,
                $originalEmail,
                $reasonCode,
                $reasonText
            );
            RefreshTokenRepository::deleteByUserId($userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['ok' => true];
    }

    private function completeRegisterAfterOtp(
        string $phone,
        string $countryCode,
        ?string $fullName,
        ?string $email,
        ?string $userAgent,
        ?string $deviceLabel,
        ?array $referrer
    ): array {
        if (UserRepository::phoneTaken($phone, $countryCode)) {
            throw new HttpException('Account already exists', 409);
        }

        $id = Uuid::v4();

        try {
            UserRepository::insert($id, $phone, $countryCode, $email, $fullName, true);
            ReferralRepository::ensureReferralCode($id);
            if ($referrer !== null) {
                ReferralRepository::createPending((string) $referrer['id'], $id, (string) $referrer['referral_code']);
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new HttpException('Account already exists', 409);
            }
            throw $e;
        }

        $user = UserRepository::findById($id);
        if ($user === null) {
            throw new HttpException('Registration failed', 500);
        }

        return $this->issueTokensForUser($user['id'], $user, $userAgent, $deviceLabel);
    }

    /**
     * @return array{user: array<string, mixed>, access_token: string, refresh_token: string, expires_in: int}
     */
    private function completeLoginAfterOtp(string $phone, string $countryCode, ?string $userAgent, ?string $deviceLabel): array
    {
        $auth = UserRepository::findAuthByPhone($phone, $countryCode);
        if ($auth === null) {
            throw new HttpException('No account for this phone number', 404);
        }

        if (!$auth['is_active']) {
            throw new HttpException('Account is disabled', 403);
        }

        $user = UserRepository::findById($auth['id']);
        if ($user === null || !$user['is_active']) {
            throw new HttpException('Account is disabled', 403);
        }

        return $this->issueTokensForUser($user['id'], $user, $userAgent, $deviceLabel);
    }

    /** @return array{ok: true, expires_in: int, debug_otp_code?: string} */
    private function createAndDispatchChallenge(string $phone, int $ttl, int $length): array
    {
        $code = $this->resolveOtpCode($phone, $length);
        $salt = random_bytes(16);
        $saltHex = bin2hex($salt);
        $codeHash = hash('sha256', $salt . $code);

        PhoneOtpChallengeRepository::deleteForPhone($phone);

        $expires = (new DateTimeImmutable('now'))->modify('+' . $ttl . ' seconds');
        PhoneOtpChallengeRepository::insert(
            Uuid::v4(),
            $phone,
            $saltHex,
            $codeHash,
            $expires
        );

        OtpNotifier::dispatch($phone, $code);

        $out = ['ok' => true, 'expires_in' => $ttl];
        if (OtpNotifier::exposeCodeInApiResponse()) {
            $out['debug_otp_code'] = $code;
        }

        return $out;
    }

    private function parseOptionalRegisterEmail(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        if (!filter_var($s, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Invalid email', ['email' => 'Must be a valid email address.']);
        }

        return strtolower($s);
    }

    private function resolveOtpCode(string $normalizedPhone, int $length): string
    {
        $length = max(4, min(8, $length));

        if ($this->fixedTestOtpEnabled() && $this->isOtpTestPhone($normalizedPhone)) {
            $digits = preg_replace('/\D+/', '', trim(Env::get('OTP_TEST_CODE', '123456')));
            if ($digits !== '' && strlen($digits) === $length && ctype_digit($digits)) {
                return $digits;
            }
        }

        return $this->generateOtpDigits($length);
    }

    private function fixedTestOtpEnabled(): bool
    {
        return strtolower(trim(Env::get('OTP_ENABLE_FIXED_TEST_OTP', 'false'))) === 'true';
    }

    private function isOtpTestPhone(string $normalizedPhone): bool
    {
        $raw = Env::get('OTP_TEST_PHONES', '');
        if ($raw === null || trim($raw) === '') {
            return false;
        }

        foreach (explode(',', $raw) as $entry) {
            $parsed = Phone::parseLocalAndCountryCode(trim($entry), UserRepository::DEFAULT_COUNTRY_CODE);
            if ($parsed !== null && $parsed['phone'] === $normalizedPhone) {
                return true;
            }
        }

        return false;
    }

    private function generateOtpDigits(int $length): string
    {
        $length = max(4, min(8, $length));
        $min = (int) 10 ** ($length - 1);
        $max = (int) 10 ** $length - 1;

        return (string) random_int($min, $max);
    }

    private function normalizeOtpCode(string $raw): ?string
    {
        $s = preg_replace('/\s+/', '', trim($raw));
        if (!is_string($s)) {
            return null;
        }

        $len = $this->otpLength();
        if (!preg_match('/^\d{' . $len . '}$/', $s)) {
            return null;
        }

        return $s;
    }

    /** @param array<string, mixed> $body */
    private function isMobileRegistrationClient(array $body): bool
    {
        $v = $body['client'] ?? null;
        if (!is_string($v)) {
            return false;
        }
        return strtolower(trim($v)) === self::REGISTRATION_CLIENT_MOBILE;
    }

    private function otpLength(): int
    {
        $n = (int) Env::get('OTP_LENGTH', '6');

        return max(4, min(8, $n));
    }

    private function otpTtlSeconds(): int
    {
        $n = (int) Env::get('OTP_TTL_SECONDS', '600');

        return max(60, min(3600, $n));
    }

    private function otpMaxAttempts(): int
    {
        $n = (int) Env::get('OTP_MAX_ATTEMPTS', '5');

        return max(3, min(20, $n));
    }

    /**
     * @param array{id: string, phone: string, email: ?string, full_name: ?string, is_active: bool, role: string, created_at: string} $user
     * @return array{user: array<string, mixed>, access_token: string, refresh_token: string, expires_in: int}
     */
    private function issueTokensForUser(
        string $userId,
        array $user,
        ?string $userAgent,
        ?string $deviceLabel
    ): array {
        if (!$user['is_active']) {
            throw new HttpException('Account is disabled', 403);
        }

        $role = (string) ($user['role'] ?? UserRepository::DEFAULT_ROLE);

        $accessTtl = $this->accessTtlSeconds();
        $access = Jwt::issue(['sub' => $userId, 'role' => $role], $accessTtl);

        $raw = bin2hex(random_bytes(32));
        $refreshHash = hash('sha256', $raw);
        $refreshTtl = $this->refreshTtlSeconds();
        $expires = (new DateTimeImmutable('now'))->modify('+' . $refreshTtl . ' seconds');

        RefreshTokenRepository::insert(
            Uuid::v4(),
            $userId,
            $refreshHash,
            $expires,
            $userAgent,
            $deviceLabel
        );

        return [
            'user' => [
                'id' => $user['id'],
                'phone' => $user['phone'],
                'country_code' => $user['country_code'] ?? UserRepository::DEFAULT_COUNTRY_CODE,
                'email' => $user['email'],
                'full_name' => $user['full_name'],
                'referral_code' => $user['referral_code'] ?? null,
                'role' => $role,
                'is_active' => $user['is_active'],
                'created_at' => $user['created_at'],
            ],
            'access_token' => $access,
            'refresh_token' => $raw,
            'expires_in' => $accessTtl,
        ];
    }

    private function accessTtlSeconds(): int
    {
        $v = Env::get('JWT_ACCESS_TTL_SECONDS');
        if ($v !== null && $v !== '') {
            return max(60, (int) $v);
        }

        $fallback = Env::get('JWT_TTL_SECONDS', '3600');

        return max(60, (int) $fallback);
    }

    private function refreshTtlSeconds(): int
    {
        $v = Env::get('JWT_REFRESH_TTL_SECONDS', '2592000');

        return max(3600, (int) $v);
    }

    private function optionalFullName(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        if (strlen($s) > self::MAX_FULL_NAME_LENGTH) {
            throw new ValidationException('Invalid full name', [
                'full_name' => 'Must be at most ' . self::MAX_FULL_NAME_LENGTH . ' characters.',
            ]);
        }

        return $s;
    }

    private function isValidDeleteReasonCode(string $reasonCode): bool
    {
        return in_array($reasonCode, [
            'privacy_concerns',
            'not_useful',
            'too_expensive',
            'switching_service',
            'technical_issues',
            'other',
        ], true);
    }

    private function buildArchivedPhone(string $phone, string $userId): string
    {
        $suffix = '#' . substr($userId, 0, 8);
        $prefixLen = max(1, 20 - strlen($suffix));
        $prefix = substr($phone, 0, $prefixLen);
        return $prefix . $suffix;
    }
}
