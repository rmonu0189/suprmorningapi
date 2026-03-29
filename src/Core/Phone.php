<?php

declare(strict_types=1);

namespace App\Core;

final class Phone
{
    /**
     * Normalizes to digits-only (E.164 local maximum 15 digits, minimum practical 10).
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
}
