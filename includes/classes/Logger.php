<?php
namespace Ourcr;

defined('OURCR_ONLINE') or die('Direct access not permitted.');



/**
 * Logger — centralised logging wrapper for OURCR ONLINE.
 *
 * All methods delegate to the global writeLog() / auditLog() helpers
 * defined in includes/functions.php.  The class is purely static;
 * never instantiate it.
 */
final class Logger
{
    // -------------------------------------------------------------------------
    // Prevent instantiation
    // -------------------------------------------------------------------------
    private function __construct() {}

    // =========================================================================
    // Channel-specific helpers
    // =========================================================================

    /**
     * Log to the AUTH channel.
     *
     * @param string  $level  'debug' | 'info' | 'warning' | 'error' | 'critical'
     * @param string  $msg    Human-readable log message
     * @param array   $ctx    Optional structured context
     */
    public static function auth(string $level, string $msg, array $ctx = []): void
    {
        writeLog(LOG_CHAN_AUTH, $level, $msg, $ctx);
    }

    /**
     * Log to the WALLET channel.
     */
    public static function wallet(string $level, string $msg, array $ctx = []): void
    {
        writeLog(LOG_CHAN_WALLET, $level, $msg, $ctx);
    }

    /**
     * Log to the VTPASS channel.
     */
    public static function vtpass(string $level, string $msg, array $ctx = []): void
    {
        writeLog(LOG_CHAN_VTPASS, $level, $msg, $ctx);
    }

    /**
     * Log to the GAPS channel.
     */
    public static function gaps(string $level, string $msg, array $ctx = []): void
    {
        writeLog(LOG_CHAN_GAPS, $level, $msg, $ctx);
    }

    /**
     * Log to the CRON channel.
     */
    public static function cron(string $level, string $msg, array $ctx = []): void
    {
        writeLog(LOG_CHAN_CRON, $level, $msg, $ctx);
    }

    /**
     * Log to the ADMIN channel.
     */
    public static function admin(string $level, string $msg, array $ctx = []): void
    {
        writeLog(LOG_CHAN_ADMIN, $level, $msg, $ctx);
    }

    /**
     * Log to the ERROR channel.
     */
    public static function error(string $level, string $msg, array $ctx = []): void
    {
        writeLog(LOG_CHAN_ERROR, $level, $msg, $ctx);
    }

    /**
     * Log to the SYSTEM channel.
     */
    public static function system(string $level, string $msg, array $ctx = []): void
    {
        writeLog(LOG_CHAN_SYSTEM, $level, $msg, $ctx);
    }

    // =========================================================================
    // Generic / Audit helpers
    // =========================================================================

    /**
     * Write to any arbitrary channel by name.
     *
     * @param string $channel  The log channel constant value (e.g. LOG_CHAN_AUTH)
     */
    public static function channel(string $channel, string $level, string $msg, array $ctx = []): void
    {
        writeLog($channel, $level, $msg, $ctx);
    }

    /**
     * Create an audit-trail entry.
     *
     * Delegates directly to the global auditLog() function.
     * All variadic arguments are forwarded verbatim.
     *
     * @param string $action       Short action label, e.g. 'USER_LOGIN'
     * @param string $description  Human-readable description
     * @param mixed  ...$args      Additional arguments accepted by auditLog()
     */
    public static function audit(string $action, string $description = '', mixed ...$args): void
    {
        auditLog($action, $description, ...$args);
    }

    // =========================================================================
    // Convenience severity aliases (write to ERROR channel by default)
    // =========================================================================

    /** Shorthand: log a critical error across ERROR + SYSTEM channels. */
    public static function critical(string $msg, array $ctx = []): void
    {
        writeLog(LOG_CHAN_ERROR, 'critical', $msg, $ctx);
        writeLog(LOG_CHAN_SYSTEM, 'critical', $msg, $ctx);
    }

    /** Shorthand: log a warning to the SYSTEM channel. */
    public static function warning(string $msg, array $ctx = []): void
    {
        writeLog(LOG_CHAN_SYSTEM, 'warning', $msg, $ctx);
    }

    /** Shorthand: log an informational message to the SYSTEM channel. */
    public static function info(string $msg, array $ctx = []): void
    {
        writeLog(LOG_CHAN_SYSTEM, 'info', $msg, $ctx);
    }

    /** Shorthand: log a debug message to the SYSTEM channel. */
    public static function debug(string $msg, array $ctx = []): void
    {
        writeLog(LOG_CHAN_SYSTEM, 'debug', $msg, $ctx);
    }

    // =========================================================================
    // Exception helper
    // =========================================================================

    /**
     * Log a caught Throwable to the ERROR channel with full context.
     *
     * @param \Throwable $e        The exception/error that was caught
     * @param string     $channel  Override the channel (defaults to LOG_CHAN_ERROR)
     */
    public static function exception(\Throwable $e, string $channel = ''): void
    {
        $target = $channel !== '' ? $channel : LOG_CHAN_ERROR;

        writeLog($target, 'error', $e->getMessage(), [
            'exception' => $e::class,
            'code'      => $e->getCode(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ]);
    }
}
