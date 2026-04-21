<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

final class RateLimiter
{
    private static ?PDO $db = null;

    private static function getDb(): PDO
    {
        if (self::$db === null) {
            $dir = __DIR__ . '/../../storage';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $file = $dir . '/ratelimits.sqlite';
            $exists = is_file($file);
            self::$db = new PDO('sqlite:' . $file);
            self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$db->setAttribute(PDO::ATTR_TIMEOUT, 5); // 5 sec wait for lock
            if (!$exists) {
                self::$db->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, count INTEGER NOT NULL, reset_at INTEGER NOT NULL)');
            }
        }
        return self::$db;
    }

    public function consume(string $bucket, int $maxRequests, int $windowSeconds): array
    {
        $db = self::getDb();
        $now = time();
        $hash = hash('sha256', $bucket);

        // Optional garbage collection: randomly delete old records (1% chance)
        if (random_int(1, 100) === 1) {
            $db->exec('DELETE FROM rate_limits WHERE reset_at < strftime("%s", "now")');
        }

        for ($i = 0; $i < 3; $i++) {
            try {
                $db->beginTransaction();
                $stmt = $db->prepare('SELECT count, reset_at FROM rate_limits WHERE bucket = :b');
                $stmt->execute(['b' => $hash]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $count = (int) $row['count'];
                    $resetAt = (int) $row['reset_at'];
                    if ($resetAt <= $now) {
                        $count = 0;
                        $resetAt = $now + $windowSeconds;
                    }
                } else {
                    $count = 0;
                    $resetAt = $now + $windowSeconds;
                    $stmt = $db->prepare('INSERT INTO rate_limits (bucket, count, reset_at) VALUES (:b, :c, :r)');
                    $stmt->execute(['b' => $hash, 'c' => $count, 'r' => $resetAt]);
                }

                if ($count >= $maxRequests) {
                    $db->commit();
                    return [
                        'allowed' => false,
                        'remaining' => 0,
                        'retry_after' => max(1, $resetAt - $now),
                    ];
                }

                $count++;
                $stmt = $db->prepare('UPDATE rate_limits SET count = :c, reset_at = :r WHERE bucket = :b');
                $stmt->execute(['c' => $count, 'r' => $resetAt, 'b' => $hash]);
                
                $db->commit();

                return [
                    'allowed' => true,
                    'remaining' => max(0, $maxRequests - $count),
                    'retry_after' => max(1, $resetAt - $now),
                ];
            } catch (\PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                // If it's a database lock 'database is locked' (code 5 or HY000), retry
                if (str_contains(strtolower($e->getMessage()), 'locked')) {
                    usleep(50000); // 50ms
                    continue;
                }
                break; // Stop and fail open
            }
        }

        // Fail open
        return ['allowed' => true, 'remaining' => 1, 'retry_after' => 1];
    }

    public function reset(string $bucket): void
    {
        try {
            $db = self::getDb();
            $hash = hash('sha256', $bucket);
            $stmt = $db->prepare('DELETE FROM rate_limits WHERE bucket = :b');
            $stmt->execute(['b' => $hash]);
        } catch (\Throwable) {
            // Ignore failure on reset
        }
    }
}
