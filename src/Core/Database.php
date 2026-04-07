<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = strtolower(trim((string) Env::get('DB_CONNECTION', 'mysql')));
        $dsn = null;
        $user = null;
        $pass = null;

        if ($driver === 'sqlite') {
            $path = (string) Env::get('DB_SQLITE_PATH', __DIR__ . '/../../storage/dev.sqlite');
            if ($path === '') {
                Response::json(['error' => 'Database connection failed'], 500);
                exit;
            }

            // Allow relative paths (resolve from api/ root).
            if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
                $path = realpath(__DIR__ . '/../../') . '/' . $path;
            }

            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }

            $dsn = 'sqlite:' . $path;
            $user = null;
            $pass = null;
        } else {
            $host = Env::get('DB_HOST', '127.0.0.1');
            $port = Env::get('DB_PORT', '3306');
            $name = Env::get('DB_NAME', '');
            $user = Env::get('DB_USER', '');
            $pass = Env::get('DB_PASS', '');

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        }

        try {
            self::$pdo = new PDO((string) $dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            Response::json(['error' => 'Database connection failed'], 500);
            exit;
        }

        if ($driver === 'sqlite') {
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::bootstrapSqlite(self::$pdo);
        }

        return self::$pdo;
    }

    private static function bootstrapSqlite(PDO $pdo): void
    {
        try {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users' LIMIT 1");
            $hasUsers = $stmt !== false ? $stmt->fetchColumn() : false;
            if (!$hasUsers) {
                $schemaPath = __DIR__ . '/../../database/schema.sqlite.sql';
                if (is_file($schemaPath)) {
                    $sql = file_get_contents($schemaPath);
                    if ($sql !== false && trim($sql) !== '') {
                        // Strip full-line comments and execute.
                        $lines = preg_split('/\R/', $sql);
                        if (is_array($lines)) {
                            $filtered = [];
                            foreach ($lines as $line) {
                                $t = ltrim($line);
                                if ($t === '' || str_starts_with($t, '--')) {
                                    continue;
                                }
                                $filtered[] = $line;
                            }
                            $pdo->exec(implode("\n", $filtered));
                        } else {
                            $pdo->exec($sql);
                        }
                    }
                }
            }

            self::ensureDevAdminSeed($pdo);
        } catch (\Throwable $e) {
            // If bootstrap fails, keep the connection but don't crash the app.
            return;
        }
    }

    private static function ensureDevAdminSeed(PDO $pdo): void
    {
        // Ensure minimal tables exist even if the DB was created before schema.sqlite.sql was added.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS brands (
                id TEXT PRIMARY KEY,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                name TEXT NOT NULL,
                about TEXT NULL,
                logo TEXT NULL,
                status INTEGER NOT NULL DEFAULT 1
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS files (
                id TEXT PRIMARY KEY,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by TEXT NULL,
                kind TEXT NOT NULL,
                storage_path TEXT NOT NULL,
                original_name TEXT NULL,
                mime TEXT NOT NULL,
                size_bytes INTEGER NOT NULL,
                access_key TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1
            )"
        );
        $pdo->exec('CREATE TABLE IF NOT EXISTS products (id TEXT PRIMARY KEY, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS variants (id TEXT PRIMARY KEY, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS orders (
                id TEXT PRIMARY KEY,
                user_id TEXT NOT NULL,
                order_status TEXT NOT NULL DEFAULT 'created',
                payment_status TEXT NOT NULL DEFAULT 'pending',
                grand_total REAL NOT NULL DEFAULT 0,
                currency TEXT NOT NULL DEFAULT 'INR',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )"
        );

        // Promote the first OTP_TEST_PHONE to an admin user for local testing.
        $raw = (string) Env::get('OTP_TEST_PHONES', '');
        $first = trim(explode(',', $raw)[0] ?? '');
        $phone = Phone::normalize($first) ?? '919109322140';

        $stmt = $pdo->prepare('SELECT id FROM users WHERE phone = :p LIMIT 1');
        $stmt->execute(['p' => $phone]);
        $id = $stmt->fetchColumn();

        if ($id !== false && is_string($id) && $id !== '') {
            $upd = $pdo->prepare("UPDATE users SET role = 'admin', is_active = 1 WHERE id = :id");
            $upd->execute(['id' => $id]);
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO users (id, phone, email, full_name, is_active, role, created_at)
                 VALUES (:id, :phone, :email, :full_name, 1, 'admin', CURRENT_TIMESTAMP)"
            );
            $ins->execute([
                'id' => Uuid::v4(),
                'phone' => $phone,
                'email' => 'admin@local.test',
                'full_name' => 'Local Admin',
            ]);

            $stmt = $pdo->prepare('SELECT id FROM users WHERE phone = :p LIMIT 1');
            $stmt->execute(['p' => $phone]);
            $id = $stmt->fetchColumn();
        }

        // Seed a few rows so the admin dashboard has something to render.
        $cStmt = $pdo->query('SELECT COUNT(*) AS c FROM orders');
        $row = $cStmt !== false ? $cStmt->fetch(\PDO::FETCH_ASSOC) : false;
        $count = is_array($row) ? (int) ($row['c'] ?? 0) : 0;
        if ($count <= 0 && $id !== false && is_string($id) && $id !== '') {
            $insOrder = $pdo->prepare(
                "INSERT OR IGNORE INTO orders (id, user_id, order_status, payment_status, grand_total, currency, created_at)
                 VALUES (:id, :user_id, :order_status, :payment_status, :grand_total, :currency, CURRENT_TIMESTAMP)"
            );
            $insOrder->execute([
                'id' => '00000000-0000-4000-8000-000000000101',
                'user_id' => $id,
                'order_status' => 'created',
                'payment_status' => 'success',
                'grand_total' => 499.0,
                'currency' => 'INR',
            ]);
            $insOrder->execute([
                'id' => '00000000-0000-4000-8000-000000000102',
                'user_id' => $id,
                'order_status' => 'created',
                'payment_status' => 'pending',
                'grand_total' => 249.0,
                'currency' => 'INR',
            ]);
        }
    }
}
