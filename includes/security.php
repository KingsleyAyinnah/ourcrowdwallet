<?php
/**
 * OURCR ONLINE - Security Layer
 * CSRF, XSS, Rate Limiting, Input Sanitization
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

// ─── CSRF Protection ─────────────────────────────────────────────────────────

/**
 * Generate and store a CSRF token in the session
 */
function csrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        sessionStart();
    }

    if (empty($_SESSION[CSRF_TOKEN_NAME]) || csrfTokenExpired()) {
        $_SESSION[CSRF_TOKEN_NAME]            = bin2hex(random_bytes(32));
        $_SESSION[CSRF_TOKEN_NAME . '_time']  = time();
    }

    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Check if the current CSRF token has expired
 */
function csrfTokenExpired(): bool
{
    $tokenTime = $_SESSION[CSRF_TOKEN_NAME . '_time'] ?? 0;
    return (time() - $tokenTime) > CSRF_TOKEN_EXPIRY;
}

/**
 * Verify a submitted CSRF token
 */
function verifyCsrf(string $token): bool
{
    if (empty($token) || empty($_SESSION[CSRF_TOKEN_NAME])) {
        return false;
    }
    if (csrfTokenExpired()) {
        unset($_SESSION[CSRF_TOKEN_NAME], $_SESSION[CSRF_TOKEN_NAME . '_time']);
        return false;
    }
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/**
 * Verify CSRF or abort with 403
 */
function requireCsrf(): void
{
    $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrf($token)) {
        if (isAjax()) {
            jsonResponse(['success' => false, 'message' => 'Invalid security token. Please refresh the page.'], 403);
        }
        http_response_code(403);
        die('<h1>403 Forbidden</h1><p>Invalid security token. <a href="javascript:history.back()">Go back</a>.</p>');
    }
}

/**
 * Output a hidden CSRF input field
 */
function csrfField(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . e(csrfToken()) . '">';
}

/**
 * Output a CSRF meta tag (for AJAX)
 */
function csrfMeta(): string
{
    return '<meta name="csrf-token" content="' . e(csrfToken()) . '">';
}

// ─── Rate Limiting ───────────────────────────────────────────────────────────

/**
 * Check if an action is rate limited.
 * Uses DB-backed storage for cross-process consistency.
 *
 * @param string $identifier  IP address or user ID
 * @param string $action      Action name (e.g. 'login', 'otp_send')
 * @param int    $maxAttempts Maximum allowed attempts
 * @param int    $decaySeconds Window in seconds
 * @return array ['allowed' => bool, 'attempts' => int, 'retry_after' => int]
 */
function rateLimit(string $identifier, string $action, int $maxAttempts = 5, int $decaySeconds = 900): array
{
    try {
        $row = Database::fetchOne(
            'SELECT * FROM rate_limits WHERE identifier = ? AND action = ?',
            [$identifier, $action]
        );

        $now = date('Y-m-d H:i:s');

        // Blocked?
        if ($row && !empty($row['blocked_until']) && $row['blocked_until'] > $now) {
            $retryAfter = strtotime($row['blocked_until']) - time();
            return ['allowed' => false, 'attempts' => (int) $row['attempts'], 'retry_after' => $retryAfter];
        }

        // Window expired — reset
        if ($row && (time() - strtotime($row['last_attempt'])) > $decaySeconds) {
            Database::execute(
                'UPDATE rate_limits SET attempts = 1, last_attempt = NOW(), blocked_until = NULL WHERE identifier = ? AND action = ?',
                [$identifier, $action]
            );
            return ['allowed' => true, 'attempts' => 1, 'retry_after' => 0];
        }

        if (!$row) {
            Database::execute(
                'INSERT INTO rate_limits (identifier, action, attempts, last_attempt) VALUES (?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()',
                [$identifier, $action]
            );
            return ['allowed' => true, 'attempts' => 1, 'retry_after' => 0];
        }

        $attempts = (int) $row['attempts'] + 1;
        $blockedUntil = null;

        if ($attempts >= $maxAttempts) {
            $blockedUntil = date('Y-m-d H:i:s', time() + $decaySeconds);
        }

        Database::execute(
            'UPDATE rate_limits SET attempts = ?, last_attempt = NOW(), blocked_until = ? WHERE identifier = ? AND action = ?',
            [$attempts, $blockedUntil, $identifier, $action]
        );

        if ($attempts >= $maxAttempts) {
            return ['allowed' => false, 'attempts' => $attempts, 'retry_after' => $decaySeconds];
        }

        return ['allowed' => true, 'attempts' => $attempts, 'retry_after' => 0];
    } catch (Exception $e) {
        // If DB fails, be permissive (fail open) but log it
        error_log('Rate limit check failed: ' . $e->getMessage());
        return ['allowed' => true, 'attempts' => 0, 'retry_after' => 0];
    }
}

/**
 * Clear rate limit record for identifier+action
 */
function clearRateLimit(string $identifier, string $action): void
{
    try {
        Database::execute(
            'DELETE FROM rate_limits WHERE identifier = ? AND action = ?',
            [$identifier, $action]
        );
    } catch (Exception) {}
}

// ─── Input Sanitization ───────────────────────────────────────────────────────

/**
 * Sanitize a string for general use
 */
function sanitize(string $input): string
{
    return trim(stripslashes(htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
}

/**
 * Alias of sanitize for compatibility
 */
function sanitizeString(string $input): string
{
    return sanitize($input);
}

/**
 * Sanitize an email address
 */
function sanitizeEmail(string $email): string
{
    return filter_var(strtolower(trim($email)), FILTER_SANITIZE_EMAIL);
}

/**
 * Sanitize an integer
 */
function sanitizeInt(mixed $value, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
{
    $int = filter_var($value, FILTER_VALIDATE_INT);
    if ($int === false) {
        return $min;
    }
    return max($min, min($max, $int));
}

/**
 * Sanitize a float
 */
function sanitizeFloat(mixed $value): float
{
    return (float) filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

/**
 * Validate email format
 */
function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate password strength
 */
function validatePassword(string $password): array
{
    $errors = [];
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }
    return $errors;
}

/**
 * Validate a 4-digit transaction PIN
 */
function validatePin(string $pin): bool
{
    return preg_match('/^\d{4}$/', $pin) === 1;
}

/**
 * Hash a password using bcrypt
 */
function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
}

/**
 * Verify a password against its hash
 */
function verifyPassword(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

/**
 * Hash a transaction PIN
 */
function hashPin(string $pin): string
{
    return password_hash($pin, PASSWORD_BCRYPT, ['cost' => 10]);
}

/**
 * Verify a transaction PIN
 */
function verifyPin(string $pin, string $hash): bool
{
    return password_verify($pin, $hash);
}

// ─── Security Headers ────────────────────────────────────────────────────────

/**
 * Send security headers (complement to .htaccess headers)
 */
function sendSecurityHeaders(): void
{
    if (!headers_sent()) {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}

// ─── Data Encryption Helpers ────────────────────────────────────────────────

/**
 * Encrypt a string using AES-256-GCM
 */
function encryptSecret(string $plainText, ?string $key = null): string
{
    if ($plainText === '') {
        return '';
    }
    $encKey = hash('sha256', $key ?: (defined('APP_KEY') ? APP_KEY : 'OURCR_DEFAULT_SEC_KEY_2026'), true);
    $iv = random_bytes(12);
    $tag = '';
    $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipherText === false) {
        return $plainText;
    }
    return base64_encode($iv . $tag . $cipherText);
}

/**
 * Decrypt a string using AES-256-GCM
 */
function decryptSecret(string $cipherPayload, ?string $key = null): string
{
    if ($cipherPayload === '') {
        return '';
    }
    $data = base64_decode($cipherPayload, true);
    if ($data === false || strlen($data) < 28) {
        // Return as-is if not in encrypted format (backward compatibility)
        return $cipherPayload;
    }
    $encKey = hash('sha256', $key ?: (defined('APP_KEY') ? APP_KEY : 'OURCR_DEFAULT_SEC_KEY_2026'), true);
    $iv = substr($data, 0, 12);
    $tag = substr($data, 12, 16);
    $cipherText = substr($data, 28);
    $decrypted = openssl_decrypt($cipherText, 'aes-256-gcm', $encKey, OPENSSL_RAW_DATA, $iv, $tag);
    return ($decrypted !== false) ? $decrypted : $cipherPayload;
}

// ─── IP / Device Fingerprint ─────────────────────────────────────────────────

/**
 * Get a simple device fingerprint (for session binding)
 */
function deviceFingerprint(): string
{
    return hash('sha256', implode('|', [
        $_SERVER['HTTP_USER_AGENT']      ?? '',
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
    ]));
}


