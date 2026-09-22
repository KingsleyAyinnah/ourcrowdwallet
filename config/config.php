<?php
/**
 * OURCR ONLINE - Main Application Bootstrap
 * This file is the entry point for all configuration loading
 */

if (!defined('OURCR_ONLINE')) {
    define('OURCR_ONLINE', true);
}

// ─── Timezone ────────────────────────────────────────────────────────────────
date_default_timezone_set('Africa/Lagos');

// ─── Error Reporting ─────────────────────────────────────────────────────
// ALWAYS suppress display — errors go to log only, never to the browser
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

// ─── PHP Session Configuration ───────────────────────────────────────────────
// Must be set before any output or session_start()
ini_set('session.use_only_cookies',   '1');
ini_set('session.use_strict_mode',    '1');
ini_set('session.cookie_httponly',    '1');
ini_set('session.cookie_samesite',    'Lax');
ini_set('session.gc_maxlifetime',     '7200');
ini_set('session.use_trans_sid',      '0');
// ini_set('session.cookie_secure', '1'); // Enable in production (HTTPS)

// ─── Load Constants ───────────────────────────────────────────────────────────
require_once __DIR__ . '/constants.php';

// ─── Adjust Error Display Based on Environment ───────────────────────────
// Always suppress — even in debug mode we log, not display to browser
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// ─── Set Error Log Path ──────────────────────────────────────────────────────
ini_set('error_log', LOGS_PATH . '/php_errors.log');

// ─── Load Database ───────────────────────────────────────────────────────────
require_once __DIR__ . '/database.php';

// ─── Load Other Config Files ─────────────────────────────────────────────────
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/vtpass.php';
require_once __DIR__ . '/gaps.php';
require_once __DIR__ . '/banks.php';

// ─── Autoloader (Composer) ───────────────────────────────────────────────────
$composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
    if (class_exists('Dotenv\Dotenv')) {
        try {
            $dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
            $dotenv->safeLoad();
        } catch (\Throwable $e) {
            // Silence any Dotenv loading errors to allow fallback
        }
    }
}

// ─── Custom Class Autoloader (includes/classes/) ─────────────────────────────
spl_autoload_register(function (string $class): void {
    // Strip the Ourcr\ namespace prefix
    $prefix = 'Ourcr\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        // Also support loading bare class names from includes/classes/
        $classFile = INCLUDES_PATH . '/classes/' . $class . '.php';
        if (file_exists($classFile)) {
            require_once $classFile;
        }
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = INCLUDES_PATH . '/classes/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    } else {
        $basenameFile = INCLUDES_PATH . '/classes/' . basename(str_replace('\\', '/', $relative)) . '.php';
        if (file_exists($basenameFile)) {
            require_once $basenameFile;
        }
    }
});

// ─── Load Core Includes ──────────────────────────────────────────────────────
require_once INCLUDES_PATH . '/helpers.php';
require_once INCLUDES_PATH . '/security.php';
require_once INCLUDES_PATH . '/session.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/auth.php';

// Dispatch application security headers
sendSecurityHeaders();

// ─── Global Exception Handler ────────────────────────────────────────────────
set_exception_handler(function (Throwable $e) {
    // Log full details silently
    $logMessage = date('Y-m-d H:i:s') . ' [EXCEPTION] ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine()
        . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
    error_log($logMessage, 3, LOGS_PATH . '/error.log');

    // Never show raw errors to user — always redirect with friendly message
    if (!headers_sent()) {
        // Store flash and redirect
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_flash'][] = ['type' => 'error', 'message' => 'Something went wrong. Please try again or contact support.'];
        }
        http_response_code(500);
        $isAdminReq = (strpos($_SERVER['REQUEST_URI'] ?? '', '/admin') !== false) || (function_exists('hasRole') && hasRole('admin', 'superadmin', 'support'));
        $redirectUrl = $isAdminReq 
            ? (defined('APP_URL') ? APP_URL . '/admin/dashboard' : '/admin') 
            : (defined('APP_URL') ? APP_URL . '/dashboard' : '/');
        header('Location: ' . $redirectUrl);
    } else {
        // Headers already sent — output a minimal inline snackbar script
        echo '<script>'
            . 'if(window.Swal){Swal.fire({icon:"error",title:"Error",text:"Something went wrong. Please try again.",timer:5000,timerProgressBar:true,showConfirmButton:false});}'
            . 'else{alert("Something went wrong. Please try again.");}'
            . '</script>';
    }
    exit(1);
});

// ─── Global Error Handler ─────────────────────────────────────────────────────
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    $logMessage = date('Y-m-d H:i:s') . ' [PHP_ERROR ' . $errno . '] ' . $errstr
        . ' in ' . $errfile . ':' . $errline . PHP_EOL;
    error_log($logMessage, 3, LOGS_PATH . '/error.log');
    // Never propagate to browser display
    return true;
});

// ─── Fatal Error / Shutdown Handler ──────────────────────────────────────────
// Catches fatal errors that set_error_handler cannot: E_ERROR, E_PARSE,
// E_CORE_ERROR, E_COMPILE_ERROR — including "Maximum execution time exceeded"
register_shutdown_function(function () {
    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if ($error !== null && in_array($error['type'], $fatalTypes, true)) {
        // Log full details
        $logMessage = date('Y-m-d H:i:s') . ' [FATAL] ' . $error['message']
            . ' in ' . $error['file'] . ':' . $error['line'] . PHP_EOL;
        if (defined('LOGS_PATH')) {
            @error_log($logMessage, 3, LOGS_PATH . '/error.log');
        } else {
            @error_log($logMessage);
        }

        // Clean any partial output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Friendly redirect with snackbar
        if (!headers_sent()) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['_flash'][] = ['type' => 'error', 'message' => 'A server error occurred. Please try again. If the problem persists, contact support.'];
            }
            http_response_code(500);
            $isAdminReq = (strpos($_SERVER['REQUEST_URI'] ?? '', '/admin') !== false) || (function_exists('hasRole') && hasRole('admin', 'superadmin', 'support'));
            $redirectUrl = $isAdminReq 
                ? (defined('APP_URL') ? APP_URL . '/admin/dashboard' : '/admin') 
                : (defined('APP_URL') ? APP_URL . '/dashboard' : '/');
            header('Location: ' . $redirectUrl);
        } else {
            // Already outputting page content — inject snackbar script
            echo '<script>'
                . 'document.addEventListener("DOMContentLoaded",function(){'
                . 'if(window.Swal){Swal.fire({icon:"error",title:"Server Error",text:"A server error occurred. Please try again.",timer:6000,timerProgressBar:true,showConfirmButton:false});}'
                . 'else{var s=document.createElement("div");s.style="position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#dc2626;color:#fff;padding:12px 24px;border-radius:8px;z-index:99999;font-family:sans-serif;";s.textContent="A server error occurred. Please try again.";document.body.appendChild(s);setTimeout(function(){s.remove();},6000);}'
                . '});'
                . '</script>';
        }
        exit(1);
    }
});

// ─── Load Dynamic Settings from Database ──────────────────────────────────────
// Settings are cached in session or fetched once per request
function loadSiteSettings(bool $refresh = false): array
{
    static $settings = null;
    if ($settings !== null && !$refresh) {
        return $settings;
    }
    try {
        $rows = Database::fetchAll('SELECT setting_key, setting_value FROM site_settings');
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {
        $settings = [];
    }
    return $settings;
}

/**
 * Get a single site setting
 */
function setting(string $key, mixed $default = null): mixed
{
    $settings = loadSiteSettings();
    return $settings[$key] ?? $default;
}

/**
 * Update or insert a site setting into site_settings table
 */
function setSetting(string $key, mixed $value, string $group = 'general', ?string $label = null): bool
{
    try {
        $valStr = (string)$value;
        $exists = Database::fetchOne("SELECT id FROM site_settings WHERE setting_key = ? LIMIT 1", [$key]);
        if ($exists) {
            Database::execute(
                "UPDATE site_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?",
                [$valStr, $key]
            );
        } else {
            $labelStr = $label ?? ucwords(str_replace('_', ' ', $key));
            Database::execute(
                "INSERT INTO site_settings (setting_key, setting_value, setting_group, label, updated_at) VALUES (?, ?, ?, ?, NOW())",
                [$key, $valStr, $group, $labelStr]
            );
        }
        // Invalidate in-memory cache
        loadSiteSettings(true);
        return true;
    } catch (\Throwable $e) {
        error_log("setSetting failed for key '{$key}': " . $e->getMessage());
        return false;
    }
}
