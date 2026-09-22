<?php
/**
 * OURCR ONLINE - Database Configuration & PDO Connection (Template)
 * Copy this file to config/database.php and adjust your database credentials.
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

// ─── Database Credentials & Environment Routing ──────────────────────────────
$host   = $_ENV['DB_HOST'] ?? 'localhost';
$port   = $_ENV['DB_PORT'] ?? '3306';
$user   = $_ENV['DB_USER'] ?? null;
$pass   = $_ENV['DB_PASS'] ?? null;
$dbname = $_ENV['DB_NAME'] ?? null;

// Fallback detection if environment variables are not defined
if ($user === null || $pass === null || $dbname === null) {
    $is_live = false;
    if (isset($_SERVER['HTTP_HOST'])) {
        $current_host = $_SERVER['HTTP_HOST'];
        if ($current_host !== 'localhost' && $current_host !== '127.0.0.1' && strpos($current_host, 'localhost') === false) {
            $is_live = true;
        }
    }

    if ($is_live) {
        // Live cPanel Default Fallbacks (if no .env is created)
        $dbname = $dbname ?? 'your_live_db';
        $user   = $user   ?? 'your_live_user';
        $pass   = $pass   ?? 'your_live_password';
    } else {
        // Localhost Default Fallbacks
        $dbname = $dbname ?? 'ourcr_online';
        $user   = $user   ?? 'root';
        $pass   = $pass   ?? '';
    }
}

define('DB_HOST',       $host);
define('DB_PORT',       $port);
define('DB_NAME',       $dbname);
define('DB_USER',       $user);
define('DB_PASS',       $pass);

define('DB_CHARSET',    'utf8mb4');
define('DB_COLLATION',  'utf8mb4_unicode_ci');

// ─── PDO Database Class ───────────────────────────────────────────────────────
class Database
{
    private static ?PDO $pdo = null;

    /**
     * Get or create PDO connection singleton
     */
    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    DB_HOST,
                    DB_PORT,
                    DB_NAME,
                    DB_CHARSET
                );

                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE " . DB_COLLATION,
                ];

                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

            } catch (PDOException $e) {
                if (function_exists('writeLog')) {
                    writeLog(LOG_CHAN_SYSTEM, 'critical', 'Database connection failed: ' . $e->getMessage());
                } else {
                    error_log('Database connection failed: ' . $e->getMessage());
                }

                if (defined('APP_DEBUG') && APP_DEBUG) {
                    die('Database Connection Error: ' . htmlspecialchars($e->getMessage()));
                } else {
                    die('A database error occurred. Please try again later.');
                }
            }
        }

        return self::$pdo;
    }

    /**
     * Execute a query with parameters and return the statement
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Fetch all matching rows
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Execute INSERT/UPDATE/DELETE and return rows affected
     */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /**
     * Execute INSERT and return the last inserted ID
     */
    public static function insert(string $sql, array $params = []): string
    {
        self::query($sql, $params);
        return self::getConnection()->lastInsertId();
    }

    /**
     * Begin transaction
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    /**
     * Rollback transaction
     */
    public static function rollback(): bool
    {
        return self::getConnection()->rollBack();
    }

    /**
     * Check if currently inside a transaction
     */
    public static function inTransaction(): bool
    {
        return self::getConnection()->inTransaction();
    }
}
