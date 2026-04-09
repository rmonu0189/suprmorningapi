<?php

declare(strict_types=1);

namespace App\Core;

final class Phone
{
    /**
     * Normalizes to digits-only.
     */
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', $input);
        if (!is_string($digits) || $digits === '') {
            return null;
        }

        $len = strlen($digits);
        if ($len < 10 || $len > 15) {
            return null;
        }

        return $digits;
    }

    /**
     * Parses a "phone-like" input into a local phone number (last 10 digits) and a country code.
     *
     * Rules:
     * - If input has exactly 10 digits => local=digits, country_code=default
     * - If input has 11–15 digits => local=last10; if length==12 and starts with "91" => country_code="+91", else country_code=default
     *
     * @return array{phone: string, country_code: string}|null
     */
    public static function parseLocalAndCountryCode(string $input, string $defaultCountryCode = '+91'): ?array
    {
        $digits = self::normalize($input);
        if ($digits === null) {
            return null;
        }

        if (strlen($digits) === 10) {
            return ['phone' => $digits, 'country_code' => $defaultCountryCode];
        }

        $local = substr($digits, -10);
        if ($local === false || strlen($local) !== 10) {
            return null;
        }

        // Best-effort inference for India numbers supplied with 91 prefix.
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return ['phone' => $local, 'country_code' => '+91'];
        }

        return ['phone' => $local, 'country_code' => $defaultCountryCode];
    }
}
