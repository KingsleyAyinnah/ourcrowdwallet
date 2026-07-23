<?php
/**
 * OURCR ONLINE - Session Management
 * Secure session handling with fingerprinting and regeneration
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

/**
 * Start a secure session
 */
function sessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => 0,                    // browser session
        'path'     => '/',
        'domain'   => '',
        'secure'   => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    // Bind session to device fingerprint to detect hijacking
    $fingerprint = deviceFingerprint();
    if (isset($_SESSION['_fingerprint'])) {
        if (!hash_equals($_SESSION['_fingerprint'], $fingerprint)) {
            // Session hijacking detected — destroy and restart
            sessionDestroy();
            session_start();
            $_SESSION['_fingerprint'] = $fingerprint;
            return;
        }
    } else {
        $_SESSION['_fingerprint'] = $fingerprint;
    }

    // Session lifetime enforcement
    if (isset($_SESSION['_last_activity'])) {
        $idle = time() - $_SESSION['_last_activity'];
        $maxIdle = isset($_SESSION['remember_me']) ? SESSION_REMEMBER_ME : SESSION_LIFETIME;
        if ($idle > $maxIdle) {
            sessionDestroy();
            session_start();
            $_SESSION['_fingerprint'] = $fingerprint;
            return;
        }
    }

    $_SESSION['_last_activity'] = time();

    // Regenerate session ID periodically (every 30 minutes) to prevent fixation
    if (!isset($_SESSION['_regenerated_at'])) {
        $_SESSION['_regenerated_at'] = time();
    } elseif ((time() - $_SESSION['_regenerated_at']) > 1800) {
        session_regenerate_id(true);
        $_SESSION['_regenerated_at'] = time();
    }
}

/**
 * Destroy the current session completely
 */
function sessionDestroy(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Set a flash message (displayed once on next page load)
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Get and clear all flash messages
 */
function getFlash(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $flashes;
}

/**
 * Check if there are flash messages
 */
function hasFlash(): bool
{
    return !empty($_SESSION['_flash']);
}

/**
 * Store data in session
 */
function sessionSet(string $key, mixed $value): void
{
    $_SESSION[$key] = $value;
}

/**
 * Retrieve data from session
 */
function sessionGet(string $key, mixed $default = null): mixed
{
    return $_SESSION[$key] ?? $default;
}

/**
 * Remove data from session
 */
function sessionForget(string $key): void
{
    unset($_SESSION[$key]);
}

/**
 * Check if a session key exists
 */
function sessionHas(string $key): bool
{
    return isset($_SESSION[$key]);
}

/**
 * Store intended URL before login redirect
 */
function setIntendedUrl(string $url): void
{
    $_SESSION['_intended_url'] = $url;
}

/**
 * Get and clear intended URL
 */
function getIntendedUrl(string $default = ''): string
{
    $url = $_SESSION['_intended_url'] ?? $default;
    unset($_SESSION['_intended_url']);
    return $url;
}

// Initialize session at load time
sessionStart();
sendSecurityHeaders();
