<?php
/**
 * OURCR ONLINE - Core Application Functions
 * Wallet, Audit Logs, Notifications, Referrals, Mail
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

// ─── Audit Logging ───────────────────────────────────────────────────────────

/**
 * Write an audit log entry to the database
 */
function auditLog(
    string $action,
    string $description = '',
    ?string $subjectType = null,
    ?int $subjectId = null,
    ?array $changes = null,
    ?string $ip = null
): void {
    try {
        Database::insert(
            'INSERT INTO audit_logs (user_id, action, description, subject_type, subject_id, new_values, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                currentUserId(),
                $action,
                $description,
                $subjectType,
                $subjectId,
                $changes ? json_encode($changes) : null,
                $ip ?? getClientIP(),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]
        );
    } catch (Exception $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}

// ─── File Logger ─────────────────────────────────────────────────────────────

/**
 * Write a structured log entry to a channel file
 */
function writeLog(string $channel, string $level, string $message, array $context = []): void
{
    $logDir  = LOGS_PATH . '/' . date('Y-m');
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $contextStr = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $line = sprintf(
        "[%s] [%s] [%s] %s%s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        strtoupper($channel),
        $message,
        $contextStr
    );

    file_put_contents($logDir . '/' . $channel . '.log', $line, FILE_APPEND | LOCK_EX);
}

// ─── Notifications ───────────────────────────────────────────────────────────

/**
 * Send an in-app notification to a user
 */
function sendNotification(int $userId, string $type, string $title, string $message, ?string $actionUrl = null): void
{
    try {
        Database::insert(
            'INSERT INTO notifications (user_id, type, title, message, action_url) VALUES (?, ?, ?, ?, ?)',
            [$userId, $type, $title, $message, $actionUrl]
        );
    } catch (Exception $e) {
        error_log('Notification failed: ' . $e->getMessage());
    }

    // Mirror to email if SMTP is configured
    try {
        if (setting('mail_host', '') !== '' && setting('email_notifications_enabled', '1') === '1') {
            // Do not mirror 'Login Detected' notifications to email
            if (strcasecmp($title, 'Login Detected') !== 0) {
                $user = Database::fetchOne('SELECT email, first_name FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$userId]);
                if ($user && !empty($user['email'])) {
                    $icon  = match ($type) { 'success' => '✅', 'error' => '❌', 'warning' => '⚠️', default => 'ℹ️' };
                    $body  = emailTemplate('notification', [
                        'name'      => $user['first_name'],
                        'title'     => $title,
                        'message'   => $message,
                        'icon'      => $icon,
                        'actionUrl' => $actionUrl,
                    ]);
                    sendMail($user['email'], $user['first_name'], $icon . ' ' . $title, $body);
                }
            }
        }
    } catch (Exception $e) {
        error_log('Notification email mirror failed: ' . $e->getMessage());
    }
}

/**
 * Get unread notification count for a user
 */
function getUnreadNotificationCount(int $userId): int
{
    $row = Database::fetchOne(
        'SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0',
        [$userId]
    );
    return (int) ($row['cnt'] ?? 0);
}

/**
 * Mark all notifications as read for a user
 */
function markNotificationsRead(int $userId): void
{
    Database::execute(
        'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0',
        [$userId]
    );
}

// ─── Wallet Operations ───────────────────────────────────────────────────────

/**
 * Credit a user's wallet inside a transaction.
 * MUST be called within an existing DB transaction.
 *
 * @return int  wallet_transactions.id
 */
function creditWallet(
    int $userId,
    float $amount,
    string $category,
    string $description,
    string $reference = '',
    array $meta = [],
    float $fee = 0.0
): int {
    if ($amount <= 0) {
        throw new RuntimeException('Credit amount must be positive.');
    }

    // Lock the row for update
    $user = Database::fetchOne(
        'SELECT id, wallet_balance FROM users WHERE id = ? FOR UPDATE',
        [$userId]
    );

    if (!$user) {
        throw new RuntimeException('User not found for wallet credit.');
    }

    $balanceBefore = (float) $user['wallet_balance'];
    $balanceAfter  = $balanceBefore + $amount;

    if ($category === TXN_TYPE_REFERRAL || $category === 'referral_bonus') {
        Database::execute(
            'UPDATE users SET wallet_balance = ?, bonus_balance = bonus_balance + ? WHERE id = ?',
            [$balanceAfter, $amount, $userId]
        );
    } else {
        Database::execute(
            'UPDATE users SET wallet_balance = ? WHERE id = ?',
            [$balanceAfter, $userId]
        );
    }

    if (empty($reference)) {
        $reference = generateTxnRef('CR');
    }

    $txnId = Database::insert(
        'INSERT INTO wallet_transactions
         (uuid, user_id, type, category, amount, fee, balance_before, balance_after, reference, description, status, meta, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            generateUUID(),
            $userId,
            TXN_CREDIT,
            $category,
            $amount,
            $fee,
            $balanceBefore,
            $balanceAfter,
            $reference,
            $description,
            TXN_STATUS_SUCCESS,
            empty($meta) ? null : json_encode($meta),
            getClientIP(),
        ]
    );

    return (int) $txnId;
}

/**
 * Debit a user's wallet inside a transaction.
 * MUST be called within an existing DB transaction.
 *
 * @return int  wallet_transactions.id
 */
function debitWallet(
    int $userId,
    float $amount,
    float $fee,
    string $category,
    string $description,
    string $reference = '',
    array $meta = []
): int {
    if ($amount <= 0) {
        throw new RuntimeException('Debit amount must be positive.');
    }

    $user = Database::fetchOne(
        'SELECT id, wallet_balance FROM users WHERE id = ? FOR UPDATE',
        [$userId]
    );

    if (!$user) {
        throw new RuntimeException('User not found for wallet debit.');
    }

    $total = $amount + $fee;
    $balanceBefore = (float) $user['wallet_balance'];

    if ($balanceBefore < $total) {
        throw new RuntimeException('Insufficient wallet balance.');
    }

    $balanceAfter = $balanceBefore - $total;

    Database::execute(
        'UPDATE users SET wallet_balance = ? WHERE id = ?',
        [$balanceAfter, $userId]
    );

    if (empty($reference)) {
        $reference = generateTxnRef('DR');
    }

    $txnId = Database::insert(
        'INSERT INTO wallet_transactions
         (uuid, user_id, type, category, amount, fee, balance_before, balance_after, reference, description, status, meta, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            generateUUID(),
            $userId,
            TXN_DEBIT,
            $category,
            $amount,
            $fee,
            $balanceBefore,
            $balanceAfter,
            $reference,
            $description,
            TXN_STATUS_SUCCESS,
            empty($meta) ? null : json_encode($meta),
            getClientIP(),
        ]
    );

    if ($fee > 0) {
        triggerDeveloperFeeShare($fee, $reference);
    }

    return (int) $txnId;
}

/**
 * Get a user's current wallet balance (fresh from DB)
 */
function getWalletBalance(int $userId): float
{
    $row = Database::fetchOne('SELECT wallet_balance FROM users WHERE id = ? LIMIT 1', [$userId]);
    return (float) ($row['wallet_balance'] ?? 0);
}

// ─── Referral Bonus ───────────────────────────────────────────────────────────

/**
 * Pay flat signup referral bonus to referrer after referred user verifies email.
 *
 * The bonus amount is read live from the `referral_bonus` site setting so that
 * any admin change takes effect immediately without re-registration.
 */
function payReferralBonus(int $referredUserId): void
{
    if (setting('referral_enabled', '1') !== '1') {
        return;
    }

    $referral = Database::fetchOne(
        'SELECT * FROM referrals WHERE referred_id = ? AND status = ? LIMIT 1',
        [$referredUserId, 'pending']
    );

    if (!$referral) {
        return;
    }

    // Use live setting value; fall back to stored bonus_amount if setting is 0 / missing
    $bonus = (float) setting('referral_bonus', REFERRAL_BONUS);
    if ($bonus <= 0) {
        // No flat bonus configured — just mark referral as paid with zero amount
        Database::execute(
            'UPDATE referrals SET status = ?, paid_at = NOW(), bonus_amount = 0 WHERE id = ?',
            ['paid', $referral['id']]
        );
        return;
    }

    $inTxn = Database::inTransaction();

    try {
        if (!$inTxn) {
            Database::beginTransaction();
        }

        $ref = generateTxnRef('REF');

        $txnId = creditWallet(
            (int) $referral['referrer_id'],
            $bonus,
            TXN_TYPE_REFERRAL,
            'Flat signup referral bonus for new user registration',
            $ref
        );

        Database::execute(
            'UPDATE referrals SET status = ?, paid_at = NOW(), bonus_amount = ?, txn_id = ? WHERE id = ?',
            ['paid', $bonus, $txnId, $referral['id']]
        );

        sendNotification(
            (int) $referral['referrer_id'],
            NOTIF_SUCCESS,
            'Referral Bonus Credited!',
            formatMoney($bonus) . ' signup referral bonus has been added to your wallet.',
            APP_URL . '/referrals'
        );

        if (!$inTxn) {
            Database::commit();
        }
    } catch (Exception $e) {
        if (!$inTxn) {
            Database::rollback();
        }
        error_log('Error paying referral bonus: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Pay ongoing discount referral bonus to a referrer after a referred user
 * completes a successful service purchase.
 *
 * The referrer earns `referral_bonus_percentage` % of the discount the referred
 * user received (i.e. the difference between the market/face price and the
 * amount actually charged from their wallet).
 *
 * @param int   $referredUserId  The user who just made a purchase
 * @param float $discount        The ₦ saving the referred user received on this transaction
 */
function payOngoingReferralBonus(int $referredUserId, float $discount): void
{
    // Deactivated in favor of the new centralized VTU Commission Distribution system
    return;
}

// ─── Pagination ───────────────────────────────────────────────────────────────

/**
 * Calculate pagination metadata
 */
function paginate(int $total, int $page, int $perPage = PER_PAGE): array
{
    $page       = max(1, $page);
    $totalPages = (int) ceil($total / $perPage);
    $offset     = ($page - 1) * $perPage;

    return [
        'total'       => $total,
        'per_page'    => $perPage,
        'current'     => $page,
        'last'        => $totalPages,
        'offset'      => $offset,
        'has_prev'    => $page > 1,
        'has_next'    => $page < $totalPages,
        'prev'        => max(1, $page - 1),
        'next'        => min($totalPages, $page + 1),
        'from'        => $offset + 1,
        'to'          => min($offset + $perPage, $total),
    ];
}

// ─── Mail Sending ─────────────────────────────────────────────────────────────

/**
 * Send an HTML email using PHPMailer
 */
function sendMail(string $to, string $toName, string $subject, string $htmlBody): bool
{
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log('PHPMailer not installed. Run composer install.');
        return false;
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = setting('mail_host', defined('MAIL_HOST') ? MAIL_HOST : 'localhost');
        $mail->SMTPAuth   = true;
        $mail->Username   = setting('mail_username', defined('MAIL_USERNAME') ? MAIL_USERNAME : '');
        $mail->Password   = setting('mail_password', defined('MAIL_PASSWORD') ? MAIL_PASSWORD : '');
        
        $encryption       = setting('mail_encryption', defined('MAIL_ENCRYPTION') ? MAIL_ENCRYPTION : 'tls');
        $mail->SMTPSecure = $encryption === 'ssl'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            
        $mail->Port       = (int)setting('mail_port', defined('MAIL_PORT') ? MAIL_PORT : 587);
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];
        $mail->CharSet    = 'UTF-8';

        $fromAddress      = setting('mail_from_address', defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@ourcr.online');
        $fromName         = setting('mail_from_name', defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : APP_NAME);
        $replyTo          = setting('mail_reply_to', defined('MAIL_REPLY_TO') ? MAIL_REPLY_TO : $fromAddress);

        $mail->setFrom($fromAddress, $fromName);
        $mail->addReplyTo($replyTo, $fromName);
        $mail->addAddress($to, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (Exception $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Mail send error: ' . $e->getMessage(), [
            'to'      => $to,
            'subject' => $subject,
            'host'    => $mail->Host ?? '?',
            'port'    => $mail->Port ?? '?',
        ]);
        return false;
    }
}

/**
 * Build and send email verification email
 */
function sendVerificationEmail(string $email, string $name, string $token): void
{
    $link    = APP_URL . '/verify-email?token=' . urlencode($token);
    $subject = 'Verify Your Email - ' . APP_NAME;
    $body    = emailTemplate('verification', [
        'name'  => $name,
        'link'  => $link,
        'token' => $token,
    ]);
    sendMail($email, $name, $subject, $body);
}

/**
 * Send a 4-digit OTP email for account verification.
 */
function sendOtpEmail(string $email, string $name, string $otp): void
{
    $subject = 'Your Verification Code - ' . APP_NAME;
    $body    = emailTemplate('otp', [
        'name' => $name,
        'otp'  => $otp,
    ]);
    sendMail($email, $name, $subject, $body);
}

/**
 * Build and send password reset email
 */
function sendPasswordResetEmail(string $email, string $name, string $token): void
{
    $link    = APP_URL . '/reset-password?token=' . urlencode($token);
    $subject = 'Reset Your Password - ' . APP_NAME;
    $body    = emailTemplate('password_reset', [
        'name'  => $name,
        'link'  => $link,
    ]);
    sendMail($email, $name, $subject, $body);
}

/**
 * Render an inline email template (no template file dependency)
 */
function emailTemplate(string $type, array $vars): string
{
    $appName  = setting('site_name', defined('APP_NAME') ? APP_NAME : 'OURCR ONLINE');
    $appUrl   = rtrim(setting('site_url', defined('APP_URL') ? APP_URL : 'http://localhost/ourcr'), '/');
    $year     = date('Y');
    $color    = setting('site_color', defined('DEFAULT_SITE_COLOR') ? DEFAULT_SITE_COLOR : '#DC2626');
    $name     = '';
    $link     = '';

    extract($vars, EXTR_SKIP);

    $defaultMsg = $vars['message'] ?? '';

    $content = match ($type) {
        'otp' => "
            <h2 style='color:{$color};margin:0 0 8px;font-size:22px;'>Your Verification Code</h2>
            <p>Hi <strong>{$name}</strong>,</p>
            <p>Welcome to <strong>{$appName}</strong>! Use the code below to verify your email and activate your account.</p>
            <div style='text-align:center;margin:32px 0;'>
                <div style='display:inline-block;background:linear-gradient(135deg,{$color},#1e293b);padding:24px 48px;border-radius:16px;box-shadow:0 4px 12px rgba(0,0,0,0.1);'>
                    <p style='color:#fff;font-size:12px;margin:0 0 8px;letter-spacing:2px;text-transform:uppercase;opacity:0.9;'>Verification Code</p>
                    <span style='color:#fff;font-size:48px;font-weight:900;letter-spacing:10px;font-family:monospace;display:block;'>{$vars['otp']}</span>
                    <p style='color:#fff;font-size:12px;margin:10px 0 0;opacity:0.8;'>Expires in 15 minutes</p>
                </div>
            </div>
            <p style='text-align:center;color:#6b7280;font-size:14px;'>Enter this code on the verification page to complete your registration.</p>
            <p style='color:#9ca3af;font-size:12px;margin-top:24px;'>If you didn't create an account, you can safely ignore this email.</p>
        ",
        'notification' => "
            <p>Hi <strong>{$name}</strong>,</p>
            <div style='border-left:4px solid {$color};padding:14px 18px;background:#f8fafc;border-radius:0 8px 8px 0;margin:20px 0;'>
                <p style='margin:0 0 6px;font-weight:700;color:#0f172a;font-size:16px;'>{$vars['title']}</p>
                <p style='margin:0;color:#334155;font-size:14px;line-height:1.5;'>{$vars['message']}</p>
            </div>
            " . (!empty($vars['actionUrl']) ? "<table border='0' cellpadding='0' cellspacing='0' style='margin:24px auto;text-align:center;'><tr><td align='center' style='border-radius:8px;background-color:{$color};'><a href='{$vars['actionUrl']}' target='_blank' style='display:inline-block;padding:12px 28px;font-family:Arial,sans-serif;font-size:14px;color:#ffffff !important;font-weight:bold;text-decoration:none;border-radius:8px;'>View Details</a></td></tr></table>" : '') . "
            <p style='color:#9ca3af;font-size:12px;margin-top:24px;'>You are receiving this notification because it is enabled on your account. <a href='{$appUrl}/settings' style='color:{$color};text-decoration:underline;'>Manage preferences</a></p>
        ",
        'verification' => "
            <h2 style='color:{$color};margin:0 0 12px;font-size:22px;'>Verify Your Email Address</h2>
            <p>Hi <strong>{$name}</strong>,</p>
            <p>Thank you for registering with <strong>{$appName}</strong>. Please click the button below to verify your email address and activate your account.</p>
            
            <table border='0' cellpadding='0' cellspacing='0' style='margin:28px auto;text-align:center;'>
                <tr>
                    <td align='center' style='border-radius:8px;background-color:{$color};'>
                        <a href='{$link}' target='_blank' style='display:inline-block;padding:14px 32px;font-family:Arial,sans-serif;font-size:15px;color:#ffffff !important;font-weight:bold;text-decoration:none;border-radius:8px;border:1px solid {$color};'>
                            Verify Email Address
                        </a>
                    </td>
                </tr>
            </table>

            <div style='background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin:24px 0;text-align:left;word-break:break-all;'>
                <p style='margin:0 0 6px 0;font-size:12px;color:#64748b;font-weight:bold;text-transform:uppercase;letter-spacing:0.5px;'>Or copy and paste this link in your browser:</p>
                <a href='{$link}' target='_blank' style='color:{$color};font-size:13px;text-decoration:underline;word-break:break-all;line-height:1.5;font-family:monospace;display:inline-block;'>{$link}</a>
            </div>

            <p style='color:#64748b;font-size:13px;'>This verification link expires in 1 hour.</p>
            <p style='color:#9ca3af;font-size:12px;margin-top:20px;'>If you didn't create an account, you can safely ignore this email.</p>
        ",
        'password_reset' => "
            <h2 style='color:{$color};margin:0 0 12px;font-size:22px;'>Reset Your Password</h2>
            <p>Hi <strong>{$name}</strong>,</p>
            <p>We received a request to reset your password for your <strong>{$appName}</strong> account. Click the button below to choose a new password.</p>
            
            <table border='0' cellpadding='0' cellspacing='0' style='margin:28px auto;text-align:center;'>
                <tr>
                    <td align='center' style='border-radius:8px;background-color:{$color};'>
                        <a href='{$link}' target='_blank' style='display:inline-block;padding:14px 36px;font-family:Arial,sans-serif;font-size:15px;color:#ffffff !important;font-weight:bold;text-decoration:none;border-radius:8px;border:1px solid {$color};'>
                            Reset Password
                        </a>
                    </td>
                </tr>
            </table>

            <div style='background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin:24px 0;text-align:left;word-break:break-all;'>
                <p style='margin:0 0 6px 0;font-size:12px;color:#64748b;font-weight:bold;text-transform:uppercase;letter-spacing:0.5px;'>Or copy and paste this link in your browser:</p>
                <a href='{$link}' target='_blank' style='color:{$color};font-size:13px;text-decoration:underline;word-break:break-all;line-height:1.5;font-family:monospace;display:inline-block;'>{$link}</a>
            </div>

            <p style='color:#64748b;font-size:13px;'>This password reset link will expire in <strong>1 hour</strong>.</p>
            <p style='color:#9ca3af;font-size:12px;margin-top:20px;'>If you did not request a password reset, please ignore this email or contact support if you suspect unauthorized access.</p>
        ",
        default => '<p>' . htmlspecialchars($defaultMsg) . '</p>',
    };

    return "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width,initial-scale=1'>
    <title>{$appName}</title>
</head>
<body style='margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;'>
<table width='100%' cellpadding='0' cellspacing='0' border='0' style='background:#f1f5f9;width:100%;min-height:100vh;'>
<tr>
    <td align='center' style='padding:36px 16px;'>
        <table width='100%' cellpadding='0' cellspacing='0' border='0' style='max-width:580px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.06);border:1px solid #e2e8f0;'>
        <tr>
            <td style='background:{$color};padding:24px 32px;text-align:center;'>
                <h1 style='color:#ffffff;margin:0;font-size:24px;font-weight:800;letter-spacing:0.5px;'>{$appName}</h1>
            </td>
        </tr>
        <tr>
            <td style='padding:32px 28px;color:#334155;font-size:15px;line-height:1.6;'>
                {$content}
            </td>
        </tr>
        <tr>
            <td style='background:#f8fafc;padding:18px 28px;text-align:center;font-size:12px;color:#94a3b8;border-top:1px solid #e2e8f0;'>
                &copy; {$year} {$appName}. All rights reserved. &bull; <a href='{$appUrl}' target='_blank' style='color:{$color};text-decoration:none;font-weight:600;'>Visit Website</a>
            </td>
        </tr>
        </table>
    </td>
</tr>
</table>
</body>
</html>";
}

// ─── Active Announcement ──────────────────────────────────────────────────────

/**
 * Get active announcements for the given target
 */
function getActiveAnnouncements(string $target = 'all'): array
{
    return Database::fetchAll(
        "SELECT * FROM announcements
         WHERE is_active = 1
           AND (target = 'all' OR target = ?)
           AND (starts_at IS NULL OR starts_at <= NOW())
           AND (ends_at IS NULL OR ends_at >= NOW())
         ORDER BY created_at DESC",
        [$target]
    );
}

// ─── Ticket Number Generator ──────────────────────────────────────────────────

/**
 * Generate a support ticket number
 */
function generateTicketNumber(): string
{
    return 'TKT-' . strtoupper(date('ymd')) . '-' . strtoupper(generateCode(4));
}

// ─── Service Status ───────────────────────────────────────────────────────────

/**
 * Check if a service is enabled in site settings
 */
function serviceEnabled(string $service): bool
{
    return setting($service . '_enabled', '1') === '1';
}

/**
 * Get or create the developer user
 */
function getOrCreateDeveloperUser(): array|false
{
    $email = 'kingsleyayinnah@gmail.com';
    try {
        $user = Database::fetchOne("SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1", [$email]);
        if ($user) {
            return $user;
        }

        // Auto-create
        $uuid = generateUUID();
        $username = 'kingsley';
        $phone = '08000000000';
        $password = 'DevPassKingsley123!';
        $hash = hashPassword($password);
        $refCode = 'DEV100';

        Database::execute(
            'INSERT INTO users (uuid, username, email, phone, password_hash, first_name, last_name,
                                role, status, email_verified_at, referral_code, wallet_balance, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'superadmin\', \'active\', NOW(), ?, 0.00, NOW(), NOW())',
            [$uuid, $username, $email, $phone, $hash, 'Kingsley', 'Ayinnah', $refCode]
        );

        return Database::fetchOne("SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1", [$email]);
    } catch (\Throwable $e) {
        error_log('[getOrCreateDeveloperUser] Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Calculate VTU commission for a transaction object
 */
function calculateVtuCommissionForTxn(array $txn): float
{
    $serviceId = strtolower($txn['service_id'] ?? '');
    $serviceType = strtolower($txn['service_type'] ?? '');
    $variation = strtolower($txn['variation_code'] ?? '');
    $amount = (float)($txn['amount'] ?? 0);
    $qty = (int)($txn['quantity'] ?? 1);

    if (empty($serviceType)) {
        if (str_contains($serviceId, '-data') || str_contains($serviceId, 'data')) {
            $serviceType = 'data';
        } elseif (in_array($serviceId, ['mtn', 'airtel', 'glo', 'etisalat', '9mobile']) || str_contains($serviceId, 'airtime')) {
            $serviceType = 'airtime';
        } elseif (str_contains($serviceId, 'electric')) {
            $serviceType = 'electricity';
        } elseif (in_array($serviceId, ['dstv', 'gotv', 'startimes', 'showmax'])) {
            $serviceType = 'tv';
        } elseif (in_array($serviceId, ['waec', 'jamb', 'neco'])) {
            $serviceType = 'exam';
        }
    }

    // 9mobile SME Data
    if ($serviceType === 'data' && str_contains($serviceId, 'etisalat')) {
        return $amount * 0.04;
    }
    // Airtel Airtime
    if ($serviceType === 'airtime' && $serviceId === 'airtel') {
        return $amount * 0.034;
    }
    // Airtel Data
    if ($serviceType === 'data' && str_contains($serviceId, 'airtel')) {
        return $amount * 0.034;
    }
    // MTN Airtime
    if ($serviceType === 'airtime' && $serviceId === 'mtn') {
        return $amount * 0.03;
    }
    // MTN Data
    if ($serviceType === 'data' && str_contains($serviceId, 'mtn')) {
        return $amount * 0.03;
    }
    // GLO Airtime
    if ($serviceType === 'airtime' && $serviceId === 'glo') {
        return $amount * 0.04;
    }
    // GLO Data
    if ($serviceType === 'data' && str_contains($serviceId, 'glo')) {
        return $amount * 0.04;
    }
    // Aba Electric
    if (str_contains($serviceId, 'aba-electric')) {
        return $amount * 0.017;
    }
    // Abuja Electric
    if (str_contains($serviceId, 'abuja-electric')) {
        return min($amount * 0.012, 1300.00);
    }
    // Benin Electric
    if (str_contains($serviceId, 'benin-electric')) {
        return $amount * 0.015;
    }
    // Eko Electric
    if (str_contains($serviceId, 'eko-electric')) {
        return $amount * 0.01;
    }
    // Enugu Electric
    if (str_contains($serviceId, 'enugu-electric')) {
        return $amount * 0.014;
    }
    // Ibadan Electric
    if (str_contains($serviceId, 'ibadan-electric')) {
        return 0.00; // Capped at ₦0.00
    }
    // Ikeja Electric
    if (str_contains($serviceId, 'ikeja-electric')) {
        return min($amount * 0.01, 1500.00);
    }
    // Jos Electric
    if (str_contains($serviceId, 'jos-electric')) {
        return $amount * 0.009;
    }
    // Kaduna Electric
    if (str_contains($serviceId, 'kaduna-electric')) {
        return $amount * 0.015;
    }
    // Kano Electric
    if (str_contains($serviceId, 'kano-electric')) {
        return 0.00; // Capped at ₦0.00
    }
    // PHED Electric
    if (str_contains($serviceId, 'phed')) {
        return $amount * 0.011;
    }
    // Yola Electric
    if (str_contains($serviceId, 'yola-electric')) {
        return $amount * 0.012;
    }
    // DSTV
    if ($serviceId === 'dstv') {
        return $amount * 0.015;
    }
    // GOTV
    if ($serviceId === 'gotv') {
        return $amount * 0.015;
    }
    // Startimes
    if ($serviceId === 'startimes') {
        return $amount * 0.02;
    }
    // Smile
    if (str_contains($serviceId, 'smile')) {
        return $amount * 0.05;
    }
    // SMSclone
    if (str_contains($serviceId, 'smsclone')) {
        return $amount * 0.03;
    }
    // WAEC
    if (str_contains($serviceId, 'waec')) {
        if (str_contains($variation, 'registration') || str_contains($variation, 'register') || str_contains($serviceId, 'registration')) {
            return 150.00 * $qty;
        } else {
            return 250.00 * $qty;
        }
    }
    // JAMB
    if (str_contains($serviceId, 'jamb')) {
        return 150.00 * $qty;
    }
    // Betting
    if (in_array($serviceId, ['bet9ja', '1xbet', 'nairabet', 'bangbet', 'betway', 'merrybet']) || str_contains($serviceId, 'bet') || $serviceType === 'betting') {
        return $amount * 0.015;
    }
    // International Airtime
    if (str_contains($serviceId, 'international') || str_contains($serviceId, 'foreign')) {
        return $amount * 0.03;
    }

    return 0.00;
}

/**
 * Calculate upfront user commission discount and amount to pay for VTU purchases.
 */
function calculateUserVtuDiscount(string $serviceId, float $amount, int $quantity = 1, string $variation = '', string $serviceType = ''): array
{
    if ($amount <= 0) {
        return [
            'api_commission'  => 0.00,
            'user_commission' => 0.00,
            'amount_to_pay'   => 0.00,
        ];
    }

    $apiComm = calculateVtuCommissionForTxn([
        'service_id'   => $serviceId,
        'service_type' => $serviceType,
        'amount'       => $amount,
        'quantity'     => $quantity,
        'variation'    => $variation,
    ]);

    if ($apiComm <= 0) {
        return [
            'api_commission'  => 0.00,
            'user_commission' => 0.00,
            'amount_to_pay'   => round($amount, 2),
        ];
    }

    $userPct = (float)setting('vtu_commission_user_share', 66);
    $remainingPool = $apiComm * 0.95;
    $userCommission = round($remainingPool * ($userPct / 100), 2);

    if ($userCommission >= $amount) {
        $userCommission = 0.00;
    }

    $amountToPay = round($amount - $userCommission, 2);

    return [
        'api_commission'  => round($apiComm, 2),
        'user_commission' => $userCommission,
        'amount_to_pay'   => $amountToPay,
    ];
}

/**
 * Centrally distribute VTU purchase commissions between referrers and admin.
 * Note: Users receive their user commission upfront during purchase debit.
 */
function triggerVtuCommissionDistribution(array $txn): void
{
    try {
        $userId = (int)$txn['user_id'];
        $requestId = $txn['request_id'];

        // Prevent duplicate processing
        $dupKey = 'REF_COMM_' . $requestId;
        $exists = Database::fetchOne(
            "SELECT id FROM wallet_transactions WHERE reference = ? LIMIT 1",
            [$dupKey]
        );
        if ($exists) {
            return;
        }

        // 1. Calculate total API commission for transaction
        $apiComm = calculateVtuCommissionForTxn($txn);
        if ($apiComm <= 0) {
            return;
        }

        // 2. Fetch user's referrer ID from users table or referrals table
        $user = Database::fetchOne("SELECT referred_by FROM users WHERE id = ?", [$userId]);
        $referrerRef = $user['referred_by'] ?? null;
        if (empty($referrerRef)) {
            $referralRow = Database::fetchOne("SELECT referrer_id FROM referrals WHERE referred_id = ? LIMIT 1", [$userId]);
            if ($referralRow) {
                $referrerRef = $referralRow['referrer_id'];
            }
        }

        if (empty($referrerRef)) {
            return;
        }

        // Find referrer user ID
        $referrer = Database::fetchOne(
            "SELECT id FROM users WHERE (id = ? OR referral_code = ?) AND deleted_at IS NULL LIMIT 1",
            [$referrerRef, $referrerRef]
        );
        if (!$referrer) {
            return;
        }
        $referrerId = (int)$referrer['id'];

        // 3. Compute Referral Share from 95% remaining pool
        $userPct = (float)setting('vtu_commission_user_share', 66);
        $refPct  = (float)setting('referral_bonus_percentage', 10);

        $remainingPool = $apiComm * 0.95;
        $userShareTotal = round($remainingPool * ($userPct / 100), 2);

        if ($userShareTotal <= 0 || setting('referral_enabled', '1') !== '1') {
            return;
        }

        $referrerShare = round($userShareTotal * ($refPct / 100), 2);
        if ($referrerShare <= 0) {
            return;
        }

        $inTxn = Database::inTransaction();
        if (!$inTxn) {
            Database::beginTransaction();
        }

        // 4. Credit referrer's wallet & bonus_balance
        creditWallet(
            $referrerId,
            $referrerShare,
            TXN_TYPE_REFERRAL,
            'Referral ongoing commission (' . $refPct . '% of referred user purchase discount)',
            $dupKey,
            ['referred_id' => $userId, 'vtu_reference' => $requestId]
        );

        sendNotification(
            $referrerId,
            NOTIF_SUCCESS,
            'Referral Commission Earned!',
            formatMoney($referrerShare) . ' referral commission from your referred user purchase has been added to your wallet.',
            APP_URL . '/referrals'
        );

        if (!$inTxn) {
            Database::commit();
        }

    } catch (\Throwable $e) {
        if (isset($inTxn) && !$inTxn && Database::inTransaction()) {
            Database::rollback();
        }
        writeLog(LOG_CHAN_ERROR, 'error', 'Error in triggerVtuCommissionDistribution: ' . $e->getMessage(), [
            'request_id' => $txn['request_id'] ?? 'N/A'
        ]);
    }
}

/**
 * Trigger Developer Share of a fee transaction
 */
function triggerDeveloperFeeShare(float $fee, string $reference): void
{
    $share = round($fee * 0.05, 2);
    if ($share <= 0) {
        return;
    }

    try {
        $dev = getOrCreateDeveloperUser();
        if ($dev) {
            // Check if we already paid this to prevent duplicate credits
            $exists = Database::fetchOne(
                "SELECT id FROM wallet_transactions WHERE category = 'developer_income' AND description LIKE ?",
                ['%fee share%' . $reference]
            );
            if (!$exists) {
                creditWallet(
                    (int)$dev['id'],
                    $share,
                    'developer_income',
                    '5% Developer fee share of transaction fee: ' . $reference
                );
            }
        }
    } catch (\Throwable $e) {
        error_log('[triggerDeveloperFeeShare] Error: ' . $e->getMessage());
    }
}

/**
 * Trigger Developer Share of a VTU commission transaction
 */
function triggerDeveloperVtuCommissionShare(array $txn): void
{
    $commission = calculateVtuCommissionForTxn($txn);
    $share = round($commission * 0.05, 2);
    if ($share <= 0) {
        return;
    }

    try {
        $dev = getOrCreateDeveloperUser();
        if ($dev) {
            // Check if we already paid this
            $exists = Database::fetchOne(
                "SELECT id FROM wallet_transactions WHERE category = 'developer_income' AND description LIKE ?",
                ['%VTU share%' . $txn['request_id']]
            );
            if (!$exists) {
                creditWallet(
                    (int)$dev['id'],
                    $share,
                    'developer_income',
                    '5% Developer VTU share of commission: ' . $txn['request_id']
                );
            }
        }
    } catch (\Throwable $e) {
        error_log('[triggerDeveloperVtuCommissionShare] Error: ' . $e->getMessage());
    }
}

/**
 * Maps VTpass API response codes or logs to clean, human-readable user messages.
 */
function getVtuUserFriendlyErrorMessage(array $result, string $serviceType): string
{
    $code = (string)($result['code'] ?? '');
    $apiMsg = trim((string)($result['message'] ?? ''));

    $map = [
        '011' => 'Invalid transaction details or parameters. Please double-check your inputs.',
        '012' => 'The selected product or billing provider is currently not supported.',
        '013' => 'The entered amount is below the minimum allowed limit for this service.',
        '014' => 'Duplicate request detected. Please wait a moment and try again.',
        '015' => 'Invalid transaction request ID.',
        '016' => 'The transaction failed. Please verify your details or try again later.',
        '017' => 'The entered amount exceeds the maximum allowed limit for this service.',
        '018' => 'System maintenance error. Please contact system support.',
        '019' => 'Duplicate transaction detected. Please wait a few minutes before resubmitting.',
        '021' => 'API authorization failure. Please contact administrator support.',
        '022' => 'Invalid customer phone number. Please check and try again.',
        '023' => 'Invalid decoder/smartcard number. Please check and try again.',
        '024' => 'Invalid transaction amount. Please check and try again.',
        '025' => 'Invalid meter number. Please check the meter number and try again.',
        '027' => 'Server IP address is not whitelisted. Please authorize it on the developer portal.',
        '028' => 'This product/service is not enabled on your VTpass account. Please contact VTpass support to activate it for your API keys.',
        '030' => 'The billing provider is currently offline or unreachable. Please try again later.',
        '087' => 'Service provider is temporarily busy. Your wallet was not debited. Please try again.',
        'NETWORK_ERROR' => 'Connection timeout. Your wallet was not debited. Please check your internet or try again.',
    ];

    if (isset($map[$code])) {
        if ($code === '027') {
            return 'Purchase failed: Server IP (' . ($_SERVER['SERVER_ADDR'] ?? 'N/A') . ') is not whitelisted on your VTpass account. Please whitelist this IP on your VTpass developer dashboard.';
        }
        return $map[$code];
    }

    if (!empty($apiMsg)) {
        if (stripos($apiMsg, 'duplicate') !== false) {
            return 'Duplicate transaction detected. Please wait a few minutes before resubmitting.';
        }
        if (stripos($apiMsg, 'product') !== false && stripos($apiMsg, 'whitelisted') !== false) {
            return 'This product/service is not enabled on your VTpass account. Please contact VTpass support to activate it for your API keys.';
        }
        if (stripos($apiMsg, 'not whitelisted') !== false) {
            return 'Purchase failed: Server IP (' . ($_SERVER['SERVER_ADDR'] ?? 'N/A') . ') is not whitelisted on your VTpass account. Please whitelist this IP on your VTpass developer dashboard.';
        }
        if (stripos($apiMsg, 'balance') !== false || stripos($apiMsg, 'funds') !== false) {
            return 'Service temporarily unavailable due to funding limits. Please try again or contact support.';
        }
        if (stripos($apiMsg, 'timeout') !== false || stripos($apiMsg, 'time out') !== false) {
            return 'The transaction request timed out. Your wallet was not debited. Please try again.';
        }
        return $apiMsg;
    }

    $serviceName = match($serviceType) {
        'airtime'     => 'Airtime purchase',
        'data'        => 'Data bundle purchase',
        'electricity' => 'Electricity utility token payment',
        'cable_tv', 'tv' => 'Cable TV subscription',
        'exam'        => 'Exam PIN purchase',
        'betting'     => 'Betting wallet funding',
        default       => 'Transaction',
    };

    return "$serviceName failed. Your wallet was not debited. Please try again or contact support.";
}
