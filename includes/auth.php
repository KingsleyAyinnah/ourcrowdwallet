<?php
/**
 * OURCR ONLINE - Authentication System
 * Login, Registration, Password, Email/Phone Verification
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

// ─── Authentication State ────────────────────────────────────────────────────

/**
 * Check if user is authenticated
 */
function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_role']);
}

/**
 * Get current authenticated user ID
 */
function currentUserId(): int|null
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/**
 * Get current authenticated user data (cached per request)
 */
function currentUser(): array|null
{
    static $user = null;
    if ($user !== null) {
        return $user;
    }
    $id = currentUserId();
    if (!$id) {
        return null;
    }
    $user = Database::fetchOne(
        'SELECT id, uuid, username, email, phone, password_hash, first_name, last_name, avatar,
                role, status, email_verified_at, phone_verified_at,
                wallet_balance, bonus_balance, referral_code, site_color,
                two_fa_enabled, last_login_at, created_at
         FROM users WHERE id = ? AND deleted_at IS NULL',
        [$id]
    );
    return $user ?: null;
}

/**
 * Get current user's role
 */
function currentUserRole(): string
{
    return $_SESSION['user_role'] ?? '';
}

/**
 * Check if current user has a specific role
 */
function hasRole(string ...$roles): bool
{
    return in_array(currentUserRole(), $roles, true);
}

/**
 * Require authentication or redirect to login
 */
function requireAuth(): void
{
    if (!isLoggedIn()) {
        setIntendedUrl(currentUrl());
        setFlash('error', 'Please login to access this page.');
        redirectTo('login');
    }

    $user = currentUser();
    if (!$user) {
        sessionDestroy();
        redirectTo('login');
    }

    if ($user['status'] === 'suspended') {
        sessionDestroy();
        setFlash('error', 'Your account has been suspended. Contact support.');
        redirectTo('login');
    }

    if ($user['status'] === 'banned') {
        sessionDestroy();
        setFlash('error', 'Your account has been banned.');
        redirectTo('login');
    }
}

/**
 * Require admin authentication — redirects to admin login on failure.
 */
function requireAdmin(): void
{
    if (!isLoggedIn()) {
        setFlash('error', 'Please login to access the admin panel.');
        redirectTo('admin/login');
    }

    $user = currentUser();
    if (!$user) {
        sessionDestroy();
        redirectTo('admin/login');
    }

    if (in_array($user['status'], ['suspended', 'banned'], true)) {
        sessionDestroy();
        setFlash('error', 'Your admin account has been ' . $user['status'] . '.');
        redirectTo('admin/login');
    }

    if (!hasRole('admin', 'superadmin')) {
        http_response_code(403);
        redirectTo('dashboard');
    }
}

/**
 * Require superadmin — redirects to admin login on failure.
 */
function requireSuperAdmin(): void
{
    if (!isLoggedIn()) {
        setFlash('error', 'Please login to access the admin panel.');
        redirectTo('admin/login');
    }

    $user = currentUser();
    if (!$user) {
        sessionDestroy();
        redirectTo('admin/login');
    }

    if (!hasRole('superadmin')) {
        http_response_code(403);
        redirectTo('admin/dashboard');
    }
}

// ─── Login ───────────────────────────────────────────────────────────────────

/**
 * Log a user into the session
 */
function loginUser(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']            = $user['id'];
    $_SESSION['user_role']          = $user['role'];
    $_SESSION['user_email']         = $user['email'];
    $_SESSION['_regenerated_at']    = time();
    $_SESSION['_last_activity']     = time();
}

/**
 * Attempt to log in a user
 * Returns array with 'success', 'message', 'user'
 */
function attemptLogin(string $email, string $password, bool $rememberMe = false): array
{
    $ip     = getClientIP();
    $action = 'login_' . md5($email);

    // Rate limit check
    $limit = rateLimit($ip, 'login', MAX_LOGIN_ATTEMPTS, LOGIN_LOCKOUT_TIME);
    if (!$limit['allowed']) {
        $minutes = ceil($limit['retry_after'] / 60);
        return [
            'success' => false,
            'message' => "Too many login attempts. Try again in {$minutes} minutes.",
        ];
    }

    // Find user
    $user = Database::fetchOne(
        'SELECT * FROM users WHERE (email = ? OR phone = ?) AND deleted_at IS NULL LIMIT 1',
        [$email, $email]
    );

    if (!$user || !verifyPassword($password, $user['password_hash'])) {
        auditLog('login_failed', 'Failed login attempt for: ' . $email, null, null, null, $ip);
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    // Check account status
    if ($user['status'] === 'inactive') {
        // Set up OTP session for verification
        $_SESSION['otp_pending_user_id']    = (int) $user['id'];
        $_SESSION['otp_pending_email']      = $user['email'];
        $_SESSION['otp_pending_first_name'] = $user['first_name'];
        
        // Get existing OTP or generate new one
        $existingOtp = Database::fetchOne(
            'SELECT * FROM email_verifications WHERE user_id = ? AND otp_type = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1',
            [(int) $user['id'], 'verification']
        );
        
        if ($existingOtp) {
            $_SESSION['otp_pending_token'] = $existingOtp['token'];
        } else {
            // Generate new OTP
            $otp   = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $token = generateToken(64);
            Database::insert(
                'INSERT INTO email_verifications (user_id, token, otp, otp_type, expires_at) VALUES (?, ?, ?, ?, ?)',
                [(int) $user['id'], $token, $otp, 'verification', date('Y-m-d H:i:s', time() + 900)]
            );
            $_SESSION['otp_pending_token'] = $token;
            
            // Send OTP email
            try {
                sendOtpEmail($user['email'], $user['first_name'], $otp);
            } catch (Throwable $e) {
                writeLog(LOG_CHAN_ERROR, 'error', 'OTP email failed: ' . $e->getMessage(), [
                    'email' => $user['email'], 'user_id' => $user['id']
                ]);
            }
        }
        
        return ['success' => false, 'message' => 'Please verify your email address to activate your account.', 'redirect' => 'verify-otp'];
    }

    if (in_array($user['status'], ['suspended', 'banned'], true)) {
        return ['success' => false, 'message' => 'Your account is ' . $user['status'] . '. Contact support.'];
    }

    // Successful login — clear rate limit, regenerate session
    clearRateLimit($ip, 'login');
    loginUser($user);

    if ($rememberMe) {
        $_SESSION['remember_me'] = true;
        $token = generateToken(64);
        Database::execute(
            'UPDATE users SET remember_token = ?, last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
            [hash('sha256', $token), $ip, $user['id']]
        );
        setcookie('remember_token', $token, time() + SESSION_REMEMBER_ME, '/', '', isHttps(), true);
    } else {
        Database::execute(
            'UPDATE users SET last_login_at = NOW(), last_login_ip = ?, login_attempts = 0 WHERE id = ?',
            [$ip, $user['id']]
        );
    }

    auditLog('login_success', 'User logged in', 'user', $user['id'], null, $ip);
    sendNotification($user['id'], NOTIF_INFO, 'Login Detected', 'New login from IP: ' . $ip);

    return ['success' => true, 'message' => 'Login successful.', 'user' => $user];
}

// ─── Registration ────────────────────────────────────────────────────────────

/**
 * Register a new user
 */
function registerUser(array $data): array
{
    $errors = [];

    // Validate
    $firstName         = sanitize($data['first_name'] ?? '');
    $lastName          = sanitize($data['last_name'] ?? '');
    $username          = strtolower(sanitize($data['username'] ?? ''));
    $email             = sanitizeEmail($data['email'] ?? '');
    $phone             = normalizePhone(sanitize($data['phone'] ?? ''));
    $password          = $data['password'] ?? '';
    $pin               = $data['transaction_pin'] ?? '';
    $pinConfirm        = $data['transaction_pin_confirm'] ?? '';
    $referralCode      = strtoupper(sanitize($data['referral_code'] ?? ''));
    $stateOfResidence  = sanitize($data['state_of_residence'] ?? '');

    if (empty($firstName)) $errors[] = 'First name is required.';
    if (empty($lastName))  $errors[] = 'Last name is required.';
    if (empty($stateOfResidence)) $errors[] = 'State of residence is required.';
    if (empty($username) || strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';
    if (!preg_match('/^[a-z0-9_]+$/', $username)) $errors[] = 'Username can only contain letters, numbers and underscores.';
    if (!isValidEmail($email)) $errors[] = 'Invalid email address.';
    if (!isValidPhone($phone)) $errors[] = 'Invalid Nigerian phone number.';
    if (empty($pin)) {
        $errors[] = 'Transaction PIN is required.';
    } elseif (!preg_match('/^\d{4}$/', $pin)) {
        $errors[] = 'Transaction PIN must be exactly 4 digits.';
    }
    if ($pin !== $pinConfirm) {
        $errors[] = 'Transaction PIN confirmation does not match.';
    }

    $pwErrors = validatePassword($password);
    if (!empty($pwErrors)) $errors = array_merge($errors, $pwErrors);

    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }

    // Check duplicates
    $existing = Database::fetchOne(
        'SELECT id, email, phone, username FROM users WHERE (email = ? OR phone = ? OR username = ?) AND deleted_at IS NULL LIMIT 1',
        [$email, $phone, $username]
    );

    if ($existing) {
        if ($existing['email'] === $email) $errors[] = 'Email address already registered.';
        elseif ($existing['phone'] === $phone) $errors[] = 'Phone number already registered.';
        elseif ($existing['username'] === $username) $errors[] = 'Username already taken.';
        return ['success' => false, 'errors' => $errors];
    }

    // Referrer
    $referredBy = null;
    if ($referralCode) {
        $referrer = Database::fetchOne(
            'SELECT id FROM users WHERE referral_code = ? AND deleted_at IS NULL LIMIT 1',
            [$referralCode]
        );
        if ($referrer) {
            $referredBy = $referrer['id'];
        }
    }

    // Generate unique referral code
    do {
        $newReferralCode = generateCode(REFERRAL_CODE_LENGTH);
        $exists = Database::fetchOne('SELECT id FROM users WHERE referral_code = ?', [$newReferralCode]);
    } while ($exists);

    $uuid         = generateUUID();
    $passwordHash = hashPassword($password);
    $pinHash      = hashPassword($pin);

    try {
        Database::beginTransaction();

        $userId = Database::insert(
            'INSERT INTO users (uuid, username, email, phone, password_hash, transaction_pin, pin_set, first_name, last_name, state_of_residence,
             role, status, referral_code, referred_by)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)',
            [
                $uuid, $username, $email, $phone, $passwordHash, $pinHash,
                $firstName, $lastName, $stateOfResidence,
                ROLE_USER,
                setting('email_verification', '1') === '1' ? USER_INACTIVE : USER_ACTIVE,
                $newReferralCode,
                $referredBy,
            ]
        );

        // Track referral — bonus_amount is intentionally 0 here;
        // the actual amount is read live from site_settings at payout time.
        if ($referredBy) {
            Database::insert(
                'INSERT INTO referrals (referrer_id, referred_id, bonus_amount, status) VALUES (?, ?, ?, ?)',
                [$referredBy, $userId, 0, 'pending']
            );
        }

        // Generate 4-digit OTP for email verification
        $otp         = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $verifyToken = generateToken(64); // also store a session-linkable token
        Database::insert(
            'INSERT INTO email_verifications (user_id, token, otp, otp_type, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $verifyToken, $otp, 'verification', date('Y-m-d H:i:s', time() + 900)] // 15 min OTP
        );

        Database::commit();

        // Store pending verification state in session
        $_SESSION['otp_pending_user_id']    = (int) $userId;
        $_SESSION['otp_pending_email']      = $email;
        $_SESSION['otp_pending_first_name'] = $firstName;
        $_SESSION['otp_pending_token']      = $verifyToken;

        // Send OTP email (non-blocking)
        try {
            sendOtpEmail($email, $firstName, $otp);
        } catch (Throwable $e) {
            writeLog(LOG_CHAN_ERROR, 'error', 'OTP email failed during registration: ' . $e->getMessage(), [
                'email' => $email, 'user_id' => $userId
            ]);
        }

        auditLog('user_registered', 'New user registered: ' . $email, 'user', (int) $userId);

        return [
            'success'  => true,
            'message'  => 'Registration successful! A 4-digit OTP has been sent to your email.',
            'user_id'  => (int) $userId,
            'otp_sent' => true,
        ];
    } catch (Exception $e) {
        Database::rollback();
        error_log('Registration error: ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Registration failed. Please try again.']];
    }
}

// ─── OTP Verification ────────────────────────────────────────────────────────

/**
 * Verify email with a 4-digit OTP code.
 * On success: activates the account, clears session state, logs the user in.
 */
function verifyOtp(string $otp): array
{
    $userId = $_SESSION['otp_pending_user_id'] ?? null;
    $token  = $_SESSION['otp_pending_token']  ?? null;

    if (!$userId || !$token) {
        return ['success' => false, 'message' => 'No pending verification. Please register again.'];
    }

    $record = Database::fetchOne(
        'SELECT ev.*, u.id as uid, u.email, u.first_name FROM email_verifications ev
         JOIN users u ON u.id = ev.user_id
         WHERE ev.user_id = ? AND ev.token = ? AND ev.otp = ? AND ev.otp_type = ? AND ev.used_at IS NULL AND ev.expires_at > NOW()
         LIMIT 1',
        [(int) $userId, $token, trim($otp), 'verification']
    );

    if (!$record) {
        return ['success' => false, 'message' => 'Invalid or expired OTP. Please try again or request a new code.'];
    }

    try {
        Database::beginTransaction();

        Database::execute(
            'UPDATE users SET status = ?, email_verified_at = NOW() WHERE id = ?',
            [USER_ACTIVE, $record['uid']]
        );

        Database::execute(
            'UPDATE email_verifications SET used_at = NOW() WHERE id = ?',
            [$record['id']]
        );

        // Pay referral bonus if applicable
        payReferralBonus((int) $record['uid']);

        Database::commit();

        // Fetch full user to log them in
        $user = Database::fetchOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $record['uid']]);
        if ($user) {
            loginUser($user);
            Database::execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        }

        // Clear OTP session state
        unset(
            $_SESSION['otp_pending_user_id'],
            $_SESSION['otp_pending_email'],
            $_SESSION['otp_pending_first_name'],
            $_SESSION['otp_pending_token']
        );

        auditLog('email_verified', 'Email verified via OTP', 'user', $record['uid']);
        sendNotification($record['uid'], NOTIF_SUCCESS, 'Account Activated', 'Your account has been verified and is now active. Welcome!');

        return ['success' => true, 'message' => 'Account verified! Welcome to ' . APP_NAME . '.', 'user' => $user];
    } catch (Exception $e) {
        Database::rollback();
        error_log('OTP verification error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Verification failed. Please try again.'];
    }
}

/**
 * Resend a new OTP to the pending user's email.
 */
function resendOtp(): array
{
    $userId    = $_SESSION['otp_pending_user_id']    ?? null;
    $email     = $_SESSION['otp_pending_email']      ?? null;
    $firstName = $_SESSION['otp_pending_first_name'] ?? 'User';

    if (!$userId || !$email) {
        return ['success' => false, 'message' => 'No pending verification found.'];
    }

    // Expire old OTPs
    Database::execute(
        'UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND otp_type = ? AND used_at IS NULL',
        [(int) $userId, 'verification']
    );

    $otp   = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    $token = generateToken(64);
    Database::insert(
        'INSERT INTO email_verifications (user_id, token, otp, otp_type, expires_at) VALUES (?, ?, ?, ?, ?)',
        [(int) $userId, $token, $otp, 'verification', date('Y-m-d H:i:s', time() + 900)]
    );

    $_SESSION['otp_pending_token'] = $token;

    try {
        sendOtpEmail($email, $firstName, $otp);
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Resend OTP email failed: ' . $e->getMessage(), [
            'email' => $email, 'user_id' => $userId
        ]);
    }

    return ['success' => true, 'message' => 'A new OTP has been sent to your email.'];
}

/**
 * Legacy token-link verification (kept for backward compatibility)
 */
function verifyEmail(string $token): array
{
    $record = Database::fetchOne(
        'SELECT ev.*, u.id as user_id, u.email FROM email_verifications ev
         JOIN users u ON u.id = ev.user_id
         WHERE ev.token = ? AND ev.used_at IS NULL AND ev.expires_at > NOW()
         LIMIT 1',
        [$token]
    );

    if (!$record) {
        return ['success' => false, 'message' => 'Invalid or expired verification link.'];
    }

    try {
        Database::beginTransaction();
        Database::execute('UPDATE users SET status = ?, email_verified_at = NOW() WHERE id = ? AND status = ?', [USER_ACTIVE, $record['user_id'], USER_INACTIVE]);
        Database::execute('UPDATE email_verifications SET used_at = NOW() WHERE id = ?', [$record['id']]);
        payReferralBonus((int) $record['user_id']);
        Database::commit();
        auditLog('email_verified', 'Email verified', 'user', $record['user_id']);
        return ['success' => true, 'message' => 'Email verified successfully! You can now login.'];
    } catch (Exception $e) {
        Database::rollback();
        error_log('Email verification error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Verification failed. Please try again.'];
    }
}

// ─── Password Reset ───────────────────────────────────────────────────────────

/**
 * Initiate password reset
 */
function initiatePasswordReset(string $email): array
{
    $ip    = getClientIP();
    $limit = rateLimit($ip, 'password_reset', 3, 3600);
    if (!$limit['allowed']) {
        return ['success' => false, 'message' => 'Too many reset attempts. Please try again in 1 hour.'];
    }

    $user = Database::fetchOne(
        'SELECT id, email, first_name FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1',
        [sanitizeEmail($email)]
    );

    // Always return success to prevent email enumeration
    if (!$user) {
        return ['success' => true, 'message' => 'If that email is registered, you will receive a reset link shortly.'];
    }

    // Invalidate old tokens
    Database::execute(
        'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
        [$user['id']]
    );

    $token = generateToken(64);
    Database::insert(
        'INSERT INTO password_resets (user_id, token, expires_at, ip_address) VALUES (?, ?, ?, ?)',
        [$user['id'], $token, date('Y-m-d H:i:s', time() + PASSWORD_RESET_EXPIRY), $ip]
    );

    try {
        sendPasswordResetEmail($user['email'], $user['first_name'], $token);
    } catch (Exception $e) {
        error_log('Password reset email failed: ' . $e->getMessage());
    }

    auditLog('password_reset_requested', 'Password reset requested', 'user', $user['id'], null, $ip);

    return ['success' => true, 'message' => 'If that email is registered, you will receive a reset link shortly.'];
}

/**
 * Reset password with token
 */
function resetPassword(string $token, string $password): array
{
    $errors = validatePassword($password);
    if (!empty($errors)) {
        return ['success' => false, 'message' => implode(' ', $errors)];
    }

    $record = Database::fetchOne(
        'SELECT pr.*, u.email FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
         LIMIT 1',
        [$token]
    );

    if (!$record) {
        return ['success' => false, 'message' => 'Invalid or expired reset link. Please request a new one.'];
    }

    try {
        Database::beginTransaction();

        Database::execute(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [hashPassword($password), $record['user_id']]
        );

        Database::execute(
            'UPDATE password_resets SET used_at = NOW() WHERE id = ?',
            [$record['id']]
        );

        Database::commit();

        auditLog('password_reset_success', 'Password reset completed', 'user', $record['user_id']);

        return ['success' => true, 'message' => 'Password reset successfully. You can now login.'];
    } catch (Exception $e) {
        Database::rollback();
        error_log('Password reset error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Password reset failed. Please try again.'];
    }
}

// ─── Logout ──────────────────────────────────────────────────────────────────

/**
 * Log out the current user
 */
function logoutUser(): void
{
    $userId = currentUserId();
    if ($userId) {
        auditLog('logout', 'User logged out', 'user', $userId);
        Database::execute('UPDATE users SET remember_token = NULL WHERE id = ?', [$userId]);
    }

    // Clear remember me cookie
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/', '', isHttps(), true);
    }

    sessionDestroy();
}

// ─── Remember Me ─────────────────────────────────────────────────────────────

/**
 * Auto-login via remember me cookie
 */
function tryRememberMeLogin(): void
{
    if (isLoggedIn()) {
        return;
    }

    $cookie = $_COOKIE['remember_token'] ?? null;
    if (!$cookie) {
        return;
    }

    $tokenHash = hash('sha256', $cookie);
    $user = Database::fetchOne(
        'SELECT * FROM users WHERE remember_token = ? AND deleted_at IS NULL AND status = ? LIMIT 1',
        [$tokenHash, USER_ACTIVE]
    );

    if ($user) {
        session_regenerate_id(true);
        $_SESSION['user_id']            = $user['id'];
        $_SESSION['user_role']          = $user['role'];
        $_SESSION['user_email']         = $user['email'];
        $_SESSION['remember_me']        = true;
        $_SESSION['_regenerated_at']    = time();
        $_SESSION['_last_activity']     = time();

        Database::execute(
            'UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
            [getClientIP(), $user['id']]
        );
    } else {
        setcookie('remember_token', '', time() - 3600, '/', '', isHttps(), true);
    }
}

// Auto-attempt remember me on every page load
tryRememberMeLogin();
