<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\Exceptions\HttpException;
use App\Repositories\ReferralRepository;

final class ReferralService
{
    /** @return array<string, mixed> */
    public function summaryForUser(string $userId): array
    {
        $code = ReferralRepository::ensureReferralCode($userId);
        $items = ReferralRepository::findByReferrer($userId);

        return [
            'referral_code' => $code,
            'referral_link' => $this->buildReferralLink($code),
            'reward_amount' => ReferralRepository::REWARD_AMOUNT,
            'stats' => [
                'total' => ReferralRepository::countByReferrerAndStatus($userId),
                'pending' => ReferralRepository::countByReferrerAndStatus($userId, ReferralRepository::STATUS_PENDING),
                'completed' => ReferralRepository::countByReferrerAndStatus($userId, ReferralRepository::STATUS_COMPLETED),
            ],
            'referrals' => $items,
        ];
    }

    public static function completeForSuccessfulOrder(string $userId, string $orderId): void
    {
        ReferralRepository::completePendingForFirstPaidOrder($userId, $orderId);
    }

    public static function validateReferralCode(?string $code): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }

        $normalized = ReferralRepository::normalizeCode($code);
        if ($normalized === null) {
            throw new HttpException('Invalid referral code', 422);
        }

        $referrer = ReferralRepository::findReferrerByCode($normalized);
        if ($referrer === null) {
            throw new HttpException('Referral code not found', 404);
        }

        return $referrer;
    }

    private function buildReferralLink(string $code): string
    {
        $base = trim((string) Env::get('REFERRAL_LINK_BASE', ''));
        if ($base === '') {
            return 'suprmorning://login?referral_code=' . rawurlencode($code);
        }

        $base = rtrim($base, '/');
        $sep = str_contains($base, '?') ? '&' : '?';
        if (str_contains($base, '{code}')) {
            return str_replace('{code}', rawurlencode($code), $base);
        }

        return $base . $sep . 'referral_code=' . rawurlencode($code);
    }
}
