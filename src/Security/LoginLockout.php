<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

final class LoginLockout
{
    private int $maxFailures;
    private int $windowSeconds;
    private int $lockoutSeconds;
    private static ?PDO $db = null;

    public function __construct(int $maxFailures = 5, int $windowSeconds = 900, int $lockoutSeconds = 900)
    {
        $this->maxFailures = $maxFailures;
        $this->windowSeconds = $windowSeconds;
        $this->lockoutSeconds = $lockoutSeconds;
    }

    private static function getDb(): PDO
    {
        if (self::$db === null) {
            $dir = __DIR__ . '/../../storage';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $file = $dir . '/lockout.sqlite';
            $exists = is_file($file);
            self::$db = new PDO('sqlite:' . $file);
            self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$db->setAttribute(PDO::ATTR_TIMEOUT, 5);
            if (!$exists) {
                self::$db->exec('CREATE TABLE IF NOT EXISTS login_lockouts (key_hash TEXT PRIMARY KEY, first_failure_at INTEGER NOT NULL, failures INTEGER NOT NULL, locked_until INTEGER NOT NULL)');
            }
        }
        return self::$db;
    }

    public function isLocked(string $email, string $ip): array
    {
        $db = self::getDb();
        $hash = $this->keyHash($email, $ip);
        $stmt = $db->prepare('SELECT locked_until FROM login_lockouts WHERE key_hash = :h');
        $stmt->execute(['h' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $now = time();
        if ($row && ((int) $row['locked_until']) > $now) {
            return ['locked' => true, 'retry_after' => (int) $row['locked_until'] - $now];
        }

        return ['locked' => false, 'retry_after' => 0];
    }

    public function onFailedAttempt(string $email, string $ip): array
    {
        $db = self::getDb();
        $hash = $this->keyHash($email, $ip);
        $now = time();

        // Optional garbage collection
        if (random_int(1, 100) === 1) {
            $db->exec('DELETE FROM login_lockouts WHERE locked_until < strftime("%s", "now") AND (first_failure_at + 86400) < strftime("%s", "now")');
        }

        for ($i = 0; $i < 3; $i++) {
            try {
                $db->beginTransaction();
                $stmt = $db->prepare('SELECT first_failure_at, failures, locked_until FROM login_lockouts WHERE key_hash = :h');
                $stmt->execute(['h' => $hash]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $firstFailureAt = (int) $row['first_failure_at'];
                    $failures = (int) $row['failures'];
                    if (($now - $firstFailureAt) > $this->windowSeconds) {
                        $firstFailureAt = $now;
                        $failures = 0;
                    }
                } else {
                    $firstFailureAt = $now;
                    $failures = 0;
                    $stmt = $db->prepare('INSERT INTO login_lockouts (key_hash, first_failure_at, failures, locked_until) VALUES (:h, :fa, :f, :lu)');
                    $stmt->execute(['h' => $hash, 'fa' => $firstFailureAt, 'f' => 0, 'lu' => 0]);
                }

                $failures++;
                $lockedUntil = 0;
                $locked = false;
                $retryAfter = 0;

                if ($failures >= $this->maxFailures) {
                    $lockedUntil = $now + $this->lockoutSeconds;
                    $locked = true;
                    $retryAfter = $this->lockoutSeconds;
                }

                $stmt = $db->prepare('UPDATE login_lockouts SET first_failure_at = :fa, failures = :f, locked_until = :lu WHERE key_hash = :h');
                $stmt->execute(['fa' => $firstFailureAt, 'f' => $failures, 'lu' => $lockedUntil, 'h' => $hash]);

                $db->commit();
                return ['locked' => $locked, 'retry_after' => $retryAfter];

            } catch (\PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if (str_contains(strtolower($e->getMessage()), 'locked')) {
                    usleep(50000);
                    continue;
                }
                break;
            }
        }

        return ['locked' => false, 'retry_after' => 0];
    }

    public function onSuccessfulLogin(string $email, string $ip): void
    {
        try {
            $db = self::getDb();
            $hash = $this->keyHash($email, $ip);
            $stmt = $db->prepare('DELETE FROM login_lockouts WHERE key_hash = :h');
            $stmt->execute(['h' => $hash]);
        } catch (\Throwable) {
            // Ignore failure on reset
        }
    }

    private function keyHash(string $email, string $ip): string
    {
        $key = 'lockout:' . strtolower(trim($email)) . ':' . $ip;
        return hash('sha256', $key);
    }
}
