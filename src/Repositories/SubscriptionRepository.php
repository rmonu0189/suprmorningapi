<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class SubscriptionRepository
{
    /**
     * @param list<array{day:int, quantity:int}>|null $weeklySchedule
     */
    public static function insert(
        string $id,
        string $userId,
        string $variantId,
        string $frequency,
        int $quantity,
        ?array $weeklySchedule,
        string $startDate
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO subscriptions (id, user_id, variant_id, frequency, quantity, weekly_schedule, start_date)
             VALUES (:id, :uid, :vid, :freq, :qty, :sched, :start_date)'
        );
        $stmt->execute([
            'id' => $id,
            'uid' => $userId,
            'vid' => $variantId,
            'freq' => $frequency,
            'qty' => $quantity,
            'sched' => $weeklySchedule === null ? null : json_encode($weeklySchedule, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'start_date' => $startDate,
        ]);
    }

    public static function countAll(): int
    {
        $stmt = Database::connection()->query('SELECT COUNT(*) FROM subscriptions');
        $v = $stmt->fetchColumn();
        return $v === false ? 0 : (int) $v;
    }

    /** @return array<string, mixed>|null */
    public static function findByIdForUser(string $id, string $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.id, s.user_id, s.variant_id, s.frequency, s.quantity, s.weekly_schedule, s.start_date, s.created_at,
                    u.phone AS user_phone, u.email AS user_email, u.full_name AS user_full_name,
                    p.name AS product_name, v.name AS variant_label, v.sku AS variant_sku
             FROM subscriptions s
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN variants v ON v.id = s.variant_id
             LEFT JOIN products p ON p.id = v.product_id
             WHERE s.id = :id AND s.user_id = :uid
             LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return self::normalizeRow($row);
    }

    /** @return array<string, mixed>|null */
    public static function findLatestByUserAndVariant(string $userId, string $variantId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.id, s.user_id, s.variant_id, s.frequency, s.quantity, s.weekly_schedule, s.start_date, s.created_at,
                    u.phone AS user_phone, u.email AS user_email, u.full_name AS user_full_name,
                    p.name AS product_name, v.name AS variant_label, v.sku AS variant_sku
             FROM subscriptions s
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN variants v ON v.id = s.variant_id
             LEFT JOIN products p ON p.id = v.product_id
             WHERE s.user_id = :uid AND s.variant_id = :vid
             ORDER BY s.created_at DESC, s.id DESC
             LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'vid' => $variantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return self::normalizeRow($row);
    }

    /**
     * @param list<array{day:int, quantity:int}>|null $weeklySchedule
     */
    public static function updateByIdForUser(
        string $id,
        string $userId,
        string $frequency,
        int $quantity,
        ?array $weeklySchedule,
        string $startDate
    ): bool {
        $stmt = Database::connection()->prepare(
            'UPDATE subscriptions
             SET frequency = :freq, quantity = :qty, weekly_schedule = :sched, start_date = :start_date
             WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([
            'id' => $id,
            'uid' => $userId,
            'freq' => $frequency,
            'qty' => $quantity,
            'sched' => $weeklySchedule === null ? null : json_encode($weeklySchedule, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'start_date' => $startDate,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function findAllPaged(int $offset, int $limit): array
    {
        $sql = 'SELECT s.id, s.user_id, s.variant_id, s.frequency, s.quantity, s.weekly_schedule, s.start_date, s.created_at,
                       u.phone AS user_phone, u.email AS user_email, u.full_name AS user_full_name,
                       p.name AS product_name, v.name AS variant_label, v.sku AS variant_sku
                FROM subscriptions s
                LEFT JOIN users u ON u.id = s.user_id
                LEFT JOIN variants v ON v.id = s.variant_id
                LEFT JOIN products p ON p.id = v.product_id
                ORDER BY s.created_at DESC, s.id DESC
                LIMIT :lim OFFSET :off';
        $stmt = Database::connection()->prepare($sql);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue('off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = self::normalizeRow($row);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $rawSchedule = $row['weekly_schedule'] ?? null;
        $schedule = null;
        if (is_string($rawSchedule) && $rawSchedule !== '') {
            $decoded = json_decode($rawSchedule, true);
            if (is_array($decoded)) {
                $sched = [];
                foreach ($decoded as $it) {
                    if (!is_array($it)) continue;
                    $day = isset($it['day']) ? (int) $it['day'] : -1;
                    $qty = isset($it['quantity']) ? (int) $it['quantity'] : 0;
                    if ($day < 0 || $day > 6 || $qty < 1) continue;
                    $sched[] = ['day' => $day, 'quantity' => $qty];
                }
                $schedule = $sched;
            }
        }

        return [
            'id' => (string) $row['id'],
            'user_id' => (string) $row['user_id'],
            'variant_id' => (string) $row['variant_id'],
            'frequency' => (string) $row['frequency'],
            'quantity' => (int) $row['quantity'],
            'weekly_schedule' => $schedule,
            'start_date' => (string) $row['start_date'],
            'created_at' => (string) $row['created_at'],
            'user' => [
                'phone' => isset($row['user_phone']) ? (string) $row['user_phone'] : null,
                'email' => isset($row['user_email']) ? (string) $row['user_email'] : null,
                'full_name' => isset($row['user_full_name']) ? (string) $row['user_full_name'] : null,
            ],
            'product_name' => isset($row['product_name']) ? (string) $row['product_name'] : null,
            'variant_label' => isset($row['variant_label']) ? (string) $row['variant_label'] : null,
            'variant_sku' => isset($row['variant_sku']) ? (string) $row['variant_sku'] : null,
        ];
    }
}
