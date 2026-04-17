<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Relevance scoring + fuzzy matching for global catalog search.
 * Lower score = better match.
 */
final class SearchScoring
{
    /** Maximum Levenshtein distance for short strings (typo tolerance). */
    private const MAX_DIST_SHORT = 3;

    /** similarity_text threshold (percent) to accept as fuzzy match when substring fails. */
    private const FUZZY_PCT_MIN = 42.0;

    public static function normalizeQuery(string $raw): string
    {
        $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $raw) ?? '');
        if (function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > 120) {
            $s = mb_substr($s, 0, 120, 'UTF-8');
        } elseif (strlen($s) > 120) {
            $s = substr($s, 0, 120);
        }

        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    /**
     * Escape LIKE wildcards for SQL literal comparison.
     *
     * @return array{0: string, 1: string} [needle, likePattern %needle%]
     */
    public static function likeWrap(string $normalizedQuery): array
    {
        $needle = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalizedQuery);

        return [$needle, '%' . $needle . '%'];
    }

    /**
     * Score for whether $text matches $query. Lower is better; 0 = best.
     */
    public static function relevance(string $query, string $text): float
    {
        if ($query === '' || $text === '') {
            return 1000.0;
        }

        $nq = self::lower($query);
        $nt = self::lower($text);
        if ($nt === $nq) {
            return 0.0;
        }
        if (self::startsWith($nt, $nq)) {
            return 1.0;
        }
        if (self::contains($nt, $nq)) {
            return 2.0 + (self::strlen($nt) - self::strlen($nq)) * 0.01;
        }

        // Word overlap (multi-word queries)
        $words = preg_split('/\s+/u', $nq, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($words)) {
            foreach ($words as $w) {
                if (self::strlen($w) < 2) {
                    continue;
                }
                if (self::contains($nt, $w)) {
                    return 8.0;
                }
            }
        }

        // Fuzzy: similar_text (UTF-8 safe) + optional Levenshtein for short ASCII
        $pct = 0.0;
        similar_text($nq, $nt, $pct);
        if ($pct >= 88.0) {
            return 12.0 + (100.0 - $pct) * 0.1;
        }
        if ($pct >= self::FUZZY_PCT_MIN) {
            return 20.0 + (100.0 - $pct);
        }

        if (self::isMostlyAscii($nq) && self::isMostlyAscii($nt)) {
            $la = strlen($nq);
            $lb = strlen($nt);
            if ($la <= 64 && $lb <= 64 && $la > 0 && $lb > 0) {
                $d = levenshtein($nq, $nt);
                $maxLen = max($la, $lb);
                $maxAllow = min(self::MAX_DIST_SHORT, (int) max(1, floor($maxLen * 0.35)));
                if ($d <= $maxAllow) {
                    return 25.0 + $d;
                }
            }
        }

        return 900.0 + (100.0 - min(100.0, $pct));
    }

    public static function isMostlyAscii(string $s): bool
    {
        return $s === '' || preg_match('/^[\x20-\x7E]*$/', $s) === 1;
    }

    /**
     * Keep rows with acceptable fuzzy relevance (post-filter).
     */
    public static function isAcceptableMatch(string $query, string $text, float $scored): bool
    {
        if ($scored < 30.0) {
            return true;
        }
        $nq = self::lower($query);
        $nt = self::lower($text);
        $pct = 0.0;
        similar_text($nq, $nt, $pct);

        return $pct >= self::FUZZY_PCT_MIN;
    }

    public static function strlen(string $s): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($s, 'UTF-8');
        }
        return strlen($s);
    }

    public static function lower(string $s): string
    {
        if (function_exists('mb_strtolower')) {
            return (string) mb_strtolower($s, 'UTF-8');
        }
        return strtolower($s);
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strpos($haystack, $needle) === 0;
    }

    private static function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strpos($haystack, $needle) !== false;
    }
}
