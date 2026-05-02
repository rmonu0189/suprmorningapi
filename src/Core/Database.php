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
        // Core taxonomy + catalog tables used by admin endpoints.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS categories (
                id TEXT PRIMARY KEY,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                name TEXT NOT NULL UNIQUE,
                slug TEXT NULL UNIQUE,
                status INTEGER NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS subcategories (
                id TEXT PRIMARY KEY,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                category_id TEXT NOT NULL,
                name TEXT NOT NULL,
                slug TEXT NULL,
                status INTEGER NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0,
                UNIQUE (category_id, name),
                UNIQUE (category_id, slug),
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS products (
                id TEXT PRIMARY KEY,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                brand_id TEXT NOT NULL,
                category_id TEXT NULL,
                subcategory_id TEXT NULL,
                name TEXT NOT NULL,
                description TEXT NULL,
                tags TEXT NULL,
                status INTEGER NOT NULL DEFAULT 1,
                metadata TEXT NULL,
                FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE RESTRICT,
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
                FOREIGN KEY (subcategory_id) REFERENCES subcategories(id) ON DELETE SET NULL
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS variants (
                id TEXT PRIMARY KEY,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                product_id TEXT NOT NULL,
                name TEXT NOT NULL,
                sku TEXT NOT NULL UNIQUE,
                price REAL NOT NULL,
                mrp REAL NOT NULL,
                images TEXT NULL,
                metadata TEXT NULL,
                status INTEGER NOT NULL DEFAULT 1,
                discount_tag TEXT NULL,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS inventory (
                id TEXT PRIMARY KEY,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                variant_id TEXT NOT NULL UNIQUE,
                quantity INTEGER NOT NULL DEFAULT 0,
                reserved_quantity INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (variant_id) REFERENCES variants(id) ON DELETE CASCADE
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS inventory_movements (
                id TEXT PRIMARY KEY,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                variant_id TEXT NOT NULL,
                delta_quantity INTEGER NOT NULL,
                note TEXT NULL,
                created_by TEXT NULL,
                FOREIGN KEY (variant_id) REFERENCES variants(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )"
        );

        // Admin-triggered subscription order generation progress.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS subscription_order_generation (
                delivery_date TEXT NOT NULL,
                user_id TEXT NOT NULL,
                run_id TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                order_id TEXT NULL,
                error TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (delivery_date, user_id)
            )"
        );
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sog_delivery_status ON subscription_order_generation(delivery_date, status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sog_run ON subscription_order_generation(run_id)");

        // If the DB existed with older minimal tables, ensure required columns exist.
        self::ensureSqliteColumn($pdo, 'users', 'country_code', 'TEXT', "'+91'");
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_users_country_phone ON users(country_code, phone)');

        self::ensureSqliteColumn($pdo, 'orders', 'stock_deducted_at', 'TEXT', 'NULL');
        self::ensureSqliteColumn($pdo, 'orders', 'order_kind', 'TEXT', "'user'");
        self::ensureSqliteColumn($pdo, 'orders', 'coupon_code', 'TEXT', 'NULL');
        self::ensureSqliteColumn($pdo, 'orders', 'coupon_discount', 'REAL', '0');

        self::ensureSqliteColumn($pdo, 'products', 'brand_id', 'TEXT', "''");
        self::ensureSqliteColumn($pdo, 'products', 'category_id', 'TEXT', 'NULL');
        self::ensureSqliteColumn($pdo, 'products', 'subcategory_id', 'TEXT', 'NULL');
        self::ensureSqliteColumn($pdo, 'products', 'name', 'TEXT', "''");
        self::ensureSqliteColumn($pdo, 'products', 'description', 'TEXT', 'NULL');
        self::ensureSqliteColumn($pdo, 'products', 'tags', 'TEXT', 'NULL');
        self::ensureSqliteColumn($pdo, 'products', 'status', 'INTEGER', '1');
        self::ensureSqliteColumn($pdo, 'products', 'metadata', 'TEXT', 'NULL');

        self::ensureSqliteColumn($pdo, 'variants', 'product_id', 'TEXT', "''");
        self::ensureSqliteColumn($pdo, 'variants', 'name', 'TEXT', "''");
        self::ensureSqliteColumn($pdo, 'variants', 'sku', 'TEXT', "''");
        self::ensureSqliteColumn($pdo, 'variants', 'price', 'REAL', '0');
        self::ensureSqliteColumn($pdo, 'variants', 'mrp', 'REAL', '0');
        self::ensureSqliteColumn($pdo, 'variants', 'images', 'TEXT', 'NULL');
        self::ensureSqliteColumn($pdo, 'variants', 'metadata', 'TEXT', 'NULL');
        self::ensureSqliteColumn($pdo, 'variants', 'status', 'INTEGER', '1');
        self::ensureSqliteColumn($pdo, 'variants', 'discount_tag', 'TEXT', 'NULL');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS orders (
                id TEXT PRIMARY KEY,
                user_id TEXT NOT NULL,
                order_status TEXT NOT NULL DEFAULT 'created',
                payment_status TEXT NOT NULL DEFAULT 'pending',
                grand_total REAL NOT NULL DEFAULT 0,
                currency TEXT NOT NULL DEFAULT 'INR',
                coupon_code TEXT NULL,
                coupon_discount REAL NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS order_support_queries (
                id TEXT PRIMARY KEY,
                order_id TEXT NOT NULL UNIQUE,
                user_id TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'open',
                subject TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_at TEXT NULL,
                resolved_by TEXT NULL,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_order_support_queries_status ON order_support_queries(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_order_support_queries_user ON order_support_queries(user_id)');
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS order_support_messages (
                id TEXT PRIMARY KEY,
                query_id TEXT NOT NULL,
                sender_user_id TEXT NOT NULL,
                sender_role TEXT NOT NULL,
                message TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (query_id) REFERENCES order_support_queries(id) ON DELETE CASCADE,
                FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_order_support_messages_query ON order_support_messages(query_id, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_order_support_messages_sender ON order_support_messages(sender_user_id)');

        // Promote the first OTP_TEST_PHONE to an admin user for local testing.
        $raw = (string) Env::get('OTP_TEST_PHONES', '');
        $first = trim(explode(',', $raw)[0] ?? '');
        $parsed = Phone::parseLocalAndCountryCode($first, \App\Repositories\UserRepository::DEFAULT_COUNTRY_CODE);
        if ($parsed === null) {
            $parsed = Phone::parseLocalAndCountryCode('919109322140', \App\Repositories\UserRepository::DEFAULT_COUNTRY_CODE);
        }
        $phone = $parsed !== null ? $parsed['phone'] : '9109322140';
        $countryCode = $parsed !== null ? $parsed['country_code'] : \App\Repositories\UserRepository::DEFAULT_COUNTRY_CODE;

        $stmt = $pdo->prepare('SELECT id FROM users WHERE phone = :p AND country_code = :cc LIMIT 1');
        $stmt->execute(['p' => $phone, 'cc' => $countryCode]);
        $id = $stmt->fetchColumn();

        if ($id !== false && is_string($id) && $id !== '') {
            $upd = $pdo->prepare("UPDATE users SET role = 'admin', is_active = 1 WHERE id = :id");
            $upd->execute(['id' => $id]);
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO users (id, phone, country_code, email, full_name, is_active, role, created_at)
                 VALUES (:id, :phone, :country_code, :email, :full_name, 1, 'admin', CURRENT_TIMESTAMP)"
            );
            $ins->execute([
                'id' => Uuid::v4(),
                'phone' => $phone,
                'country_code' => $countryCode,
                'email' => 'admin@local.test',
                'full_name' => 'Local Admin',
            ]);

            $stmt = $pdo->prepare('SELECT id FROM users WHERE phone = :p AND country_code = :cc LIMIT 1');
            $stmt->execute(['p' => $phone, 'cc' => $countryCode]);
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

    private static function ensureSqliteColumn(PDO $pdo, string $table, string $column, string $type, string $defaultSql): void
    {
        try {
            $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
            if ($stmt === false) return;
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) return;
            foreach ($rows as $row) {
                if (is_array($row) && (string) ($row['name'] ?? '') === $column) {
                    return;
                }
            }
            $pdo->exec("ALTER TABLE " . $table . " ADD COLUMN " . $column . " " . $type . " DEFAULT " . $defaultSql);
        } catch (\Throwable $e) {
            return;
        }
    }
}
