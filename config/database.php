<?php
/**
 * OURCR ONLINE - Database Configuration & PDO Connection
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
        $dbname = $dbname ?? 'ourcoldh_ourcr';
        $user   = $user   ?? 'ourcoldh_ourcr';
        $pass   = $pass   ?? '8~*}Aw3S[QHQ[-e2';
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

/**
 * PDO Database Connection Singleton
 */
class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    /**
     * Get PDO connection instance (singleton)
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }
        return self::$instance;
    }

    /**
     * Create a new PDO connection
     */
    private static function createConnection(): PDO
    {
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
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+01:00'",
            PDO::ATTR_TIMEOUT            => 5,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            return $pdo;
        } catch (PDOException $e) {
            // Log to file and show generic error
            $logMessage = date('Y-m-d H:i:s') . ' [DATABASE] Connection failed: ' . $e->getMessage() . PHP_EOL;
            error_log($logMessage, 3, LOGS_PATH . '/error.log');

            if (APP_DEBUG) {
                die('<div style="font-family:monospace;padding:20px;background:#fee2e2;color:#dc2626;border:1px solid #dc2626;">
                    <strong>Database Connection Error:</strong><br>' . htmlspecialchars($e->getMessage()) . '
                </div>');
            } else {
                http_response_code(503);
                die('Service temporarily unavailable. Please try again later.');
            }
        }
    }

    /**
     * Execute a query and return PDOStatement
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $pdo  = self::getInstance();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row
     */
    public static function fetchOne(string $sql, array $params = []): array|false
    {
        return self::query($sql, $params)->fetch();
    }

    /**
     * Fetch all rows
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Execute an insert and return last insert ID
     */
    public static function insert(string $sql, array $params = []): string|false
    {
        self::query($sql, $params);
        return self::getInstance()->lastInsertId();
    }

    /**
     * Execute update/delete and return affected rows
     */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /**
     * Begin a transaction
     */
    public static function beginTransaction(): void
    {
        self::getInstance()->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public static function commit(): void
    {
        if (self::getInstance()->inTransaction()) {
            self::getInstance()->commit();
        }
    }

    /**
     * Rollback a transaction
     */
    public static function rollback(): void
    {
        if (self::getInstance()->inTransaction()) {
            self::getInstance()->rollBack();
        }
    }

    /**
     * Check if currently in a transaction
     */
    public static function inTransaction(): bool
    {
        return self::getInstance()->inTransaction();
    }

    /**
     * Get last insert ID
     */
    public static function lastInsertId(): string|false
    {
        return self::getInstance()->lastInsertId();
    }

    /**
     * Quote a value for safe use (prefer prepared statements always)
     */
    public static function quote(string $value): string
    {
        return self::getInstance()->quote($value);
    }
}
