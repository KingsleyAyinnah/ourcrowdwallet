<?php
/**
 * OURCR ONLINE - Application Constants
 * Define all global constants here
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

// ─── Application ────────────────────────────────────────────────────────────
define('APP_NAME',          'OURCR ONLINE');
define('APP_VERSION',       '1.0.1');
define('APP_ENV',           $_ENV['APP_ENV'] ?? 'development');   // development | production
define('APP_DEBUG',         (APP_ENV === 'development') || (($_ENV['APP_DEBUG'] ?? 'false') === 'true'));

if (php_sapi_name() === 'cli') {
    define('APP_URL', 'http://localhost/ourcr');
} else {
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    $base_dir = !empty($script_name) ? str_replace(basename($script_name), '', $script_name) : '/';
    $project_base = preg_replace('/(page|admin|api|cron)\/$/', '', $base_dir);
    
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('APP_URL', rtrim($protocol . $host . $project_base, '/'));
}
define('APP_DOMAIN',        'ourcr.online');
define('APP_TIMEZONE',      'Africa/Lagos');
define('APP_CURRENCY',      'NGN');
define('APP_CURRENCY_SYMBOL', '₦');
define('APP_LOCALE',        'en_NG');

// ─── Paths ───────────────────────────────────────────────────────────────────
define('BASE_PATH',         dirname(__DIR__));
define('CONFIG_PATH',       BASE_PATH . '/config');
define('INCLUDES_PATH',     BASE_PATH . '/includes');
define('PAGES_PATH',        BASE_PATH . '/pages');
define('ADMIN_PATH',        BASE_PATH . '/admin');
define('ASSETS_PATH',       BASE_PATH . '/assets');
define('LOGS_PATH',         BASE_PATH . '/logs');
define('DATABASE_PATH',     BASE_PATH . '/database');
define('VENDOR_PATH',       BASE_PATH . '/vendor');

// ─── Session ─────────────────────────────────────────────────────────────────
define('SESSION_NAME',          'ourcr_session');
define('SESSION_LIFETIME',      7200);          // 2 hours
define('SESSION_REMEMBER_ME',   2592000);       // 30 days
define('CSRF_TOKEN_NAME',       '_csrf_token');
define('CSRF_TOKEN_EXPIRY',     3600);          // 1 hour

// ─── Security ────────────────────────────────────────────────────────────────
define('PASSWORD_MIN_LENGTH',   8);
define('PIN_LENGTH',            4);
define('MAX_LOGIN_ATTEMPTS',    5);
define('LOGIN_LOCKOUT_TIME',    900);           // 15 minutes
define('OTP_EXPIRY',            600);           // 10 minutes
define('PASSWORD_RESET_EXPIRY', 3600);          // 1 hour
define('BCRYPT_COST',           12);

// ─── Pagination ───────────────────────────────────────────────────────────────
define('PER_PAGE',          20);
define('ADMIN_PER_PAGE',    25);

// ─── Wallet ───────────────────────────────────────────────────────────────────
define('MIN_DEPOSIT',           100);
define('MAX_DEPOSIT',           5000000);
define('MIN_WITHDRAWAL',        500);
define('MAX_WITHDRAWAL',        500000);
define('MIN_TRANSFER',          100);
define('MAX_TRANSFER',          1000000);
define('WITHDRAWAL_FEE',        1.5);
define('TRANSFER_FEE',          0);
define('DEPOSIT_INTENT_EXPIRY', 3600);          // 1 hour

// ─── Transaction Types ────────────────────────────────────────────────────────
define('TXN_CREDIT',            'credit');
define('TXN_DEBIT',             'debit');

define('TXN_TYPE_DEPOSIT',      'deposit');
define('TXN_TYPE_WITHDRAWAL',   'withdrawal');
define('TXN_TYPE_TRANSFER',     'transfer');
define('TXN_TYPE_AIRTIME',      'airtime');
define('TXN_TYPE_DATA',         'data');
define('TXN_TYPE_CABLE',        'cable_tv');
define('TXN_TYPE_ELECTRICITY',  'electricity');
define('TXN_TYPE_BETTING',      'betting');
define('TXN_TYPE_WAEC',         'waec');
define('TXN_TYPE_JAMB',         'jamb');
define('TXN_TYPE_NECO',         'neco');
define('TXN_TYPE_REFERRAL',     'referral_bonus');

// ─── Transaction Status ───────────────────────────────────────────────────────
define('TXN_STATUS_PENDING',    'pending');
define('TXN_STATUS_SUCCESS',    'success');
define('TXN_STATUS_FAILED',     'failed');
define('TXN_STATUS_REVERSED',   'reversed');

// ─── User Status ─────────────────────────────────────────────────────────────
define('USER_ACTIVE',           'active');
define('USER_INACTIVE',         'inactive');
define('USER_SUSPENDED',        'suspended');
define('USER_BANNED',           'banned');

// ─── User Roles ───────────────────────────────────────────────────────────────
define('ROLE_USER',             'user');
define('ROLE_AGENT',            'agent');
define('ROLE_RESELLER',         'reseller');
define('ROLE_ADMIN',            'admin');
define('ROLE_SUPERADMIN',       'superadmin');

// ─── Deposit Status ───────────────────────────────────────────────────────────
define('DEPOSIT_PENDING',       'pending');
define('DEPOSIT_MATCHED',       'matched');
define('DEPOSIT_UNMATCHED',     'unmatched');
define('DEPOSIT_REJECTED',      'rejected');

// ─── Referral ────────────────────────────────────────────────────────────────
define('REFERRAL_BONUS',        200);           // Naira
define('REFERRAL_CODE_LENGTH',  8);

// ─── Notification Types ───────────────────────────────────────────────────────
define('NOTIF_INFO',            'info');
define('NOTIF_SUCCESS',         'success');
define('NOTIF_WARNING',         'warning');
define('NOTIF_ERROR',           'error');

// ─── Log Channels ────────────────────────────────────────────────────────────
// Prefixed LOG_CHAN_* to avoid clashing with PHP's syslog LOG_* constants
define('LOG_CHAN_AUTH',         'auth');
define('LOG_CHAN_WALLET',       'wallet');
define('LOG_CHAN_VTPASS',       'vtpass');
define('LOG_CHAN_GAPS',         'gaps');
define('LOG_CHAN_CRON',         'cron');
define('LOG_CHAN_ADMIN',        'admin');
define('LOG_CHAN_ERROR',        'error');
define('LOG_CHAN_SYSTEM',       'system');

// ─── Default UI Color ─────────────────────────────────────────────────────────
define('DEFAULT_SITE_COLOR',    '#DC2626');

// ─── File Upload ─────────────────────────────────────────────────────────────
define('UPLOAD_MAX_SIZE',       5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES',   ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('AVATAR_PATH',           ASSETS_PATH . '/images/avatars/');
define('AVATAR_URL',            APP_URL . '/assets/images/avatars/');

// ─── Date Formats ────────────────────────────────────────────────────────────
define('DATE_FORMAT',           'd M Y');
define('DATETIME_FORMAT',       'd M Y, h:i A');
define('DB_DATE_FORMAT',        'Y-m-d H:i:s');

// ─── Cron ────────────────────────────────────────────────────────────────────
define('CRON_SECRET',           'CHANGE_THIS_CRON_SECRET_IN_PRODUCTION');
define('GAPS_POLL_INTERVAL',    60);            // seconds
