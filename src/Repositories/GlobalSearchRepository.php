<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Uuid;
use App\Services\SearchScoring;
use PDO;

/**
 * Fast multi-entity catalog search: brands, categories, subcategories, variants.
 * Uses SQL prefilter (LIKE / optional SOUNDEX on MySQL) + PHP relevance scoring (typo-tolerant).
 */
final class GlobalSearchRepository
{
    /**
     * @return array{
     *   query: string,
     *   brands: list<array{id: string, name: string, score: float}>,
     *   categories: list<array{id: string, name: string, slug: string|null, score: float}>,
     *   subcategories: list<array{id: string, name: string, category_id: string, category_name: string|null, score: float}>,
     *   products: list<array{id: string, name: string, brand_name: string, category_id: string|null, category_name: string|null, subcategory_id: string|null, subcategory_name: string|null, score: float}>,
     *   variants: list<array<string, mixed>>
     * }
     */
    public static function globalSearch(string $rawQuery, int $limitPerBucket = 18): array
    {
        $q = SearchScoring::normalizeQuery($rawQuery);
        if ($q === '') {
            return [
                'query' => '',
                'brands' => [],
                'categories' => [],
                'subcategories' => [],
                'products' => [],
                'variants' => [],
            ];
        }

        $pdo = Database::connection();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isMysql = strpos($driver, 'mysql') !== false;

        $limitPerBucket = max(5, min(40, $limitPerBucket));
        $overfetch = min(300, $limitPerBucket * 8);

        [$needle, $likePct] = SearchScoring::likeWrap($q);
        $words = self::splitWords($q);

        $brands = self::fetchBrands($pdo, $isMysql, $q, $needle, $likePct, $words, $overfetch, $limitPerBucket);
        $categories = self::fetchCategories($pdo, $isMysql, $q, $needle, $likePct, $words, $overfetch, $limitPerBucket);
        $subcategories = self::fetchSubcategories($pdo, $isMysql, $q, $needle, $likePct, $words, $overfetch, $limitPerBucket);
        $variants = self::fetchVariants($pdo, $isMysql, $q, $needle, $likePct, $words, $overfetch, $limitPerBucket);
        $products = self::deriveProductsFromVariants($q, $variants, $limitPerBucket);

        return [
            'query' => $q,
            'brands' => $brands,
            'categories' => $categories,
            'subcategories' => $subcategories,
            'products' => $products,
            'variants' => $variants,
        ];
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @return list<array{id: string, name: string, brand_name: string, category_id: string|null, category_name: string|null, subcategory_id: string|null, subcategory_name: string|null, score: float}>
     */
    private static function deriveProductsFromVariants(string $q, array $variants, int $limit): array
    {
        $byProduct = [];
        foreach ($variants as $v) {
            if (!is_array($v)) {
                continue;
            }
            $pid = (string) ($v['product_id'] ?? '');
            if ($pid === '') {
                continue;
            }
            $name = (string) ($v['product_name'] ?? '');
            $brand = (string) ($v['brand_name'] ?? '');
            $catName = (string) ($v['category_name'] ?? '');
            $subName = (string) ($v['subcategory_name'] ?? '');
            $score = SearchScoring::relevance($q, trim($name . ' ' . $brand . ' ' . $catName . ' ' . $subName));

            if (!isset($byProduct[$pid]) || $score < (float) $byProduct[$pid]['score']) {
                $byProduct[$pid] = [
                    'id' => $pid,
                    'name' => $name,
                    'brand_name' => $brand,
                    'category_id' => isset($v['category_id']) ? $v['category_id'] : null,
                    'category_name' => isset($v['category_name']) ? $v['category_name'] : null,
                    'subcategory_id' => isset($v['subcategory_id']) ? $v['subcategory_id'] : null,
                    'subcategory_name' => isset($v['subcategory_name']) ? $v['subcategory_name'] : null,
                    'score' => round($score, 3),
                ];
            }
        }

        $products = array_values($byProduct);
        usort(
            $products,
            static fn (array $a, array $b): int => ((float) $a['score'] <=> (float) $b['score'])
                ?: strcmp((string) $a['id'], (string) $b['id'])
        );

        return array_slice($products, 0, max(1, $limit));
    }

    /**
     * @return list<string>
     */
    private static function splitWords(string $q): array
    {
        $parts = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? array_values(array_filter($parts, static fn (string $w): bool => $w !== '')) : [];
    }

    /**
     * @param list<string> $words
     * @return list<array{id: string, name: string, score: float}>
     */
    private static function fetchBrands(PDO $pdo, bool $isMysql, string $q, string $needle, string $likePct, array $words, int $overfetch, int $limit): array
    {
        $orWord = self::buildWordOrClauses('name', $words, $overfetch > 40 ? 6 : 4);
        $soundexSql = '';
        if ($isMysql && SearchScoring::strlen($q) >= 4) {
            $soundexSql = ' OR SOUNDEX(name) = SOUNDEX(:soundex_q)';
        }

        $sql = 'SELECT id, name FROM brands WHERE status = 1 AND ('
            . 'name LIKE :like'
            . $orWord['sql']
            . $soundexSql
            . (Uuid::isValid($q) ? ' OR id = :uuid' : '')
            . ') ORDER BY name ASC LIMIT ' . (int) $overfetch;

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':like', $likePct, PDO::PARAM_STR);
        foreach ($orWord['params'] as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        if ($isMysql && SearchScoring::strlen($q) >= 4) {
            $stmt->bindValue(':soundex_q', $q, PDO::PARAM_STR);
        }
        if (Uuid::isValid($q)) {
            $stmt->bindValue(':uuid', $q, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return self::rankRows(
            $q,
            $rows,
            static fn (array $r): string => (string) ($r['name'] ?? ''),
            static fn (array $r): array => [
                'id' => (string) $r['id'],
                'name' => (string) $r['name'],
                'score' => 0.0,
            ],
            $limit
        );
    }

    /**
     * @param list<string> $words
     * @return list<array{id: string, name: string, slug: string|null, score: float}>
     */
    private static function fetchCategories(PDO $pdo, bool $isMysql, string $q, string $needle, string $likePct, array $words, int $overfetch, int $limit): array
    {
        $orWord = self::buildWordOrClauses('name', $words, $overfetch > 40 ? 6 : 4);
        $soundexSql = '';
        if ($isMysql && SearchScoring::strlen($q) >= 4) {
            $soundexSql = ' OR SOUNDEX(name) = SOUNDEX(:soundex_q)';
        }

        $orSlug = self::buildWordOrClauses('COALESCE(slug, \'\')', $words, $overfetch > 40 ? 6 : 4, 'sw');

        $sql = 'SELECT id, name, slug FROM categories WHERE status = 1 AND ('
            . 'name LIKE :like OR COALESCE(slug, \'\') LIKE :like_slug'
            . $orWord['sql']
            . $orSlug['sql']
            . $soundexSql
            . (Uuid::isValid($q) ? ' OR id = :uuid' : '')
            . ') ORDER BY sort_order ASC, name ASC LIMIT ' . (int) $overfetch;

        $stmt = $pdo->prepare($sql);
        $likeSlug = '%' . $needle . '%';
        $stmt->bindValue(':like', $likePct, PDO::PARAM_STR);
        $stmt->bindValue(':like_slug', $likeSlug, PDO::PARAM_STR);
        foreach ($orWord['params'] as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        foreach ($orSlug['params'] as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        if ($isMysql && SearchScoring::strlen($q) >= 4) {
            $stmt->bindValue(':soundex_q', $q, PDO::PARAM_STR);
        }
        if (Uuid::isValid($q)) {
            $stmt->bindValue(':uuid', $q, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return self::rankRows(
            $q,
            $rows,
            static function (array $r): string {
                $n = (string) ($r['name'] ?? '');
                $s = isset($r['slug']) && $r['slug'] !== null && $r['slug'] !== '' ? (string) $r['slug'] : '';

                return $n . ' ' . $s;
            },
            static fn (array $r): array => [
                'id' => (string) $r['id'],
                'name' => (string) $r['name'],
                'slug' => isset($r['slug']) && $r['slug'] !== '' ? (string) $r['slug'] : null,
                'score' => 0.0,
            ],
            $limit
        );
    }

    /**
     * @param list<string> $words
     * @return list<array{id: string, name: string, category_id: string, category_name: string|null, score: float}>
     */
    private static function fetchSubcategories(PDO $pdo, bool $isMysql, string $q, string $needle, string $likePct, array $words, int $overfetch, int $limit): array
    {
        $orWord = self::buildWordOrClauses('sc.name', $words, $overfetch > 40 ? 6 : 4, 'w');
        $orSlug = self::buildWordOrClauses('COALESCE(sc.slug, \'\')', $words, $overfetch > 40 ? 6 : 4, 'ssw');
        $soundexSql = '';
        if ($isMysql && SearchScoring::strlen($q) >= 4) {
            $soundexSql = ' OR SOUNDEX(sc.name) = SOUNDEX(:soundex_q)';
        }

        $sql = 'SELECT sc.id, sc.name, sc.category_id, c.name AS category_name
                FROM subcategories sc
                INNER JOIN categories c ON c.id = sc.category_id
                WHERE sc.status = 1 AND c.status = 1 AND ('
            . 'sc.name LIKE :like OR COALESCE(sc.slug, \'\') LIKE :like_slug'
            . $orWord['sql']
            . $orSlug['sql']
            . $soundexSql
            . (Uuid::isValid($q) ? ' OR sc.id = :uuid' : '')
            . ') ORDER BY c.sort_order ASC, sc.sort_order ASC, sc.name ASC LIMIT ' . (int) $overfetch;

        $stmt = $pdo->prepare($sql);
        $likeSlug = '%' . $needle . '%';
        $stmt->bindValue(':like', $likePct, PDO::PARAM_STR);
        $stmt->bindValue(':like_slug', $likeSlug, PDO::PARAM_STR);
        foreach ($orWord['params'] as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        foreach ($orSlug['params'] as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        if ($isMysql && mb_strlen($q, 'UTF-8') >= 4) {
            $stmt->bindValue(':soundex_q', $q, PDO::PARAM_STR);
        }
        if (Uuid::isValid($q)) {
            $stmt->bindValue(':uuid', $q, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return self::rankRows(
            $q,
            $rows,
            static function (array $r): string {
                $n = (string) ($r['name'] ?? '');
                $cn = (string) ($r['category_name'] ?? '');

                return $n . ' ' . $cn;
            },
            static fn (array $r): array => [
                'id' => (string) $r['id'],
                'name' => (string) $r['name'],
                'category_id' => (string) $r['category_id'],
                'category_name' => isset($r['category_name']) ? (string) $r['category_name'] : null,
                'score' => 0.0,
            ],
            $limit
        );
    }

    private static function buildWordOrClauses(string $column, array $words, int $maxWords, string $prefix = 'w'): array
    {
        $sql = '';
        $params = [];
        $i = 0;
        foreach ($words as $word) {
            if ($i >= $maxWords || SearchScoring::strlen($word) < 2) {
                continue;
            }
            $key = ':' . $prefix . $i;
            $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word);
            $params[$key] = '%' . $esc . '%';
            $sql .= ' OR ' . $column . ' LIKE ' . $key;
            ++$i;
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @template T of array
     *
     * @param list<array<string, mixed>> $rows
     * @param callable(array<string, mixed>): string $textFromRow
     * @param callable(array<string, mixed>): array{id: string, name: string, score?: float, ...} $toPayload
     * @return list<array<string, mixed>>
     */
    private static function rankRows(
        string $q,
        array $rows,
        callable $textFromRow,
        callable $toPayload,
        int $limit
    ): array {
        $scored = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $text = $textFromRow($row);
            $score = SearchScoring::relevance($q, $text);
            if ($score > 85.0 && ! SearchScoring::isAcceptableMatch($q, $text, $score)) {
                continue;
            }
            $payload = $toPayload($row);
            $payload['score'] = round($score, 3);
            $scored[] = $payload;
        }

        usort(
            $scored,
            static fn (array $a, array $b): int => ((float) ($a['score'] ?? 0) <=> (float) ($b['score'] ?? 0)) ?: strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''))
        );

        $seen = [];
        $out = [];
        foreach ($scored as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $item;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $words
     * @return list<array<string, mixed>>
     */
    private static function fetchVariants(PDO $pdo, bool $isMysql, string $q, string $needle, string $likePct, array $words, int $overfetch, int $limit): array
    {
        $wordSql = '';
        $wordParams = [];
        $wi = 0;
        foreach ($words as $word) {
            if ($wi >= 6 || SearchScoring::strlen($word) < 2) {
                continue;
            }
            $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word);
            $likeW = '%' . $esc . '%';
            $kVName = ':vw' . $wi . '_vname';
            $kPName = ':vw' . $wi . '_pname';
            $kBName = ':vw' . $wi . '_bname';
            $kSku = ':vw' . $wi . '_sku';
            $kCat = ':cw' . $wi . '_cat';
            $kSub = ':cw' . $wi . '_sub';

            $wordSql .= ' OR v.name LIKE ' . $kVName
                . ' OR p.name LIKE ' . $kPName
                . ' OR b.name LIKE ' . $kBName
                . ' OR v.sku LIKE ' . $kSku
                . ' OR COALESCE(c.name, \'\') LIKE ' . $kCat
                . ' OR COALESCE(sc.name, \'\') LIKE ' . $kSub;

            $wordParams[$kVName] = $likeW;
            $wordParams[$kPName] = $likeW;
            $wordParams[$kBName] = $likeW;
            $wordParams[$kSku] = $likeW;
            $wordParams[$kCat] = $likeW;
            $wordParams[$kSub] = $likeW;
            ++$wi;
        }

        $soundexSql = '';
        if ($isMysql && SearchScoring::strlen($q) >= 4) {
            $soundexSql = ' OR SOUNDEX(p.name) = SOUNDEX(:soundex_q) OR SOUNDEX(b.name) = SOUNDEX(:soundex_q2)';
        }

        $sql = 'SELECT v.id, v.product_id, v.name, v.sku, v.price, v.mrp, v.images, v.discount_tag, v.status,
                       p.id AS product_table_id, p.name AS product_name, p.metadata AS product_metadata, p.status AS product_status,
                       b.id AS brand_id_val, b.name AS brand_name, b.about AS brand_about, b.logo AS brand_logo, b.status AS brand_status,
                       i.quantity AS inv_quantity, i.reserved_quantity AS inv_reserved,
                       c.id AS category_id, c.name AS category_name,
                       sc.id AS subcategory_id, sc.name AS subcategory_name
                FROM variants v
                INNER JOIN products p ON p.id = v.product_id
                INNER JOIN brands b ON b.id = p.brand_id
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN subcategories sc ON sc.id = p.subcategory_id
                LEFT JOIN inventory i ON i.variant_id = v.id
                WHERE v.status = 1 AND p.status = 1 AND b.status = 1 AND ('
            . 'v.name LIKE :like_v OR p.name LIKE :like_p OR b.name LIKE :like_b OR v.sku LIKE :like_sku'
            . ' OR COALESCE(c.name, \'\') LIKE :like_c OR COALESCE(sc.name, \'\') LIKE :like_sc'
            . $wordSql
            . $soundexSql
            . (Uuid::isValid($q) ? ' OR v.id = :uuid OR p.id = :uuid2' : '')
            . ') ORDER BY p.name ASC, v.name ASC LIMIT ' . (int) $overfetch;

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':like_v', $likePct, PDO::PARAM_STR);
        $stmt->bindValue(':like_p', $likePct, PDO::PARAM_STR);
        $stmt->bindValue(':like_b', $likePct, PDO::PARAM_STR);
        $stmt->bindValue(':like_sku', $likePct, PDO::PARAM_STR);
        $stmt->bindValue(':like_c', $likePct, PDO::PARAM_STR);
        $stmt->bindValue(':like_sc', $likePct, PDO::PARAM_STR);
        foreach ($wordParams as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        if ($isMysql && SearchScoring::strlen($q) >= 4) {
            $stmt->bindValue(':soundex_q', $q, PDO::PARAM_STR);
            $stmt->bindValue(':soundex_q2', $q, PDO::PARAM_STR);
        }
        if (Uuid::isValid($q)) {
            $stmt->bindValue(':uuid', $q, PDO::PARAM_STR);
            $stmt->bindValue(':uuid2', $q, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        /** @var list<array<string, mixed>> $mapped */
        $mapped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $haystack = implode(' ', array_filter([
                (string) ($row['name'] ?? ''),
                (string) ($row['sku'] ?? ''),
                (string) ($row['product_name'] ?? ''),
                (string) ($row['brand_name'] ?? ''),
                (string) ($row['category_name'] ?? ''),
                (string) ($row['subcategory_name'] ?? ''),
            ]));
            $score = SearchScoring::relevance($q, $haystack);
            if ($score > 85.0 && ! SearchScoring::isAcceptableMatch($q, $haystack, $score)) {
                continue;
            }
            $variant = self::mapVariantRow($row);
            $variant['score'] = round($score, 3);
            $mapped[] = $variant;
        }

        usort(
            $mapped,
            static fn (array $a, array $b): int => ((float) ($a['score'] ?? 0) <=> (float) ($b['score'] ?? 0))
                ?: strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''))
        );

        return array_slice($mapped, 0, $limit);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function mapVariantRow(array $row): array
    {
        $images = self::decodeImagesJson($row['images'] ?? null);

        return [
            'id' => (string) $row['id'],
            'product_id' => (string) $row['product_id'],
            'name' => (string) $row['name'],
            'sku' => (string) $row['sku'],
            'price' => (float) ($row['price'] ?? 0),
            'mrp' => (float) ($row['mrp'] ?? 0),
            'images' => $images,
            'discount_tag' => isset($row['discount_tag']) && $row['discount_tag'] !== null && $row['discount_tag'] !== '' ? (string) $row['discount_tag'] : null,
            'product_name' => (string) ($row['product_name'] ?? ''),
            'brand_name' => (string) ($row['brand_name'] ?? ''),
            'category_id' => isset($row['category_id']) && $row['category_id'] !== null && $row['category_id'] !== '' ? (string) $row['category_id'] : null,
            'category_name' => isset($row['category_name']) && $row['category_name'] !== null && $row['category_name'] !== '' ? (string) $row['category_name'] : null,
            'subcategory_id' => isset($row['subcategory_id']) && $row['subcategory_id'] !== null && $row['subcategory_id'] !== '' ? (string) $row['subcategory_id'] : null,
            'subcategory_name' => isset($row['subcategory_name']) && $row['subcategory_name'] !== null && $row['subcategory_name'] !== '' ? (string) $row['subcategory_name'] : null,
        ];
    }

    /** @return list<string> */
    private static function decodeImagesJson(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        if (is_string($json)) {
            $d = json_decode($json, true);
            if (is_array($d)) {
                $out = [];
                foreach ($d as $item) {
                    if (is_string($item)) {
                        $out[] = $item;
                    }
                }

                return $out;
            }
        }

        return [];
    }
}
