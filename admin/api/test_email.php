<?php
/**
 * OURCR ONLINE - Admin API: Test SMTP Email
 * Standalone JSON endpoint — no HTML output.
 */

ob_start(); // Buffer everything so we can always output clean JSON

define('OURCR_ONLINE', true);
require_once dirname(__DIR__, 2) . '/config/config.php';

// Always respond with JSON
function jsonExit(bool $success, string $message): never
{
    ob_clean();
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

// Must be admin
if (!isLoggedIn() || !hasRole('admin', 'superadmin')) {
    jsonExit(false, 'Unauthorised. Please log in as admin.');
}

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonExit(false, 'Invalid request method.');
}

// CSRF check
$csrfToken = $_POST[CSRF_TOKEN_NAME] ?? '';
if (!verifyCsrf($csrfToken)) {
    jsonExit(false, 'Invalid security token. Please refresh the page.');
}

// Validate test recipient email
$testEmail = sanitizeEmail($_POST['test_email'] ?? '');
if (empty($testEmail) || !isValidEmail($testEmail)) {
    jsonExit(false, 'Please enter a valid recipient email address.');
}

// Use submitted form values (test before saving), fall back to saved DB settings
$mailHost       = !empty($_POST['mail_host'])         ? trim($_POST['mail_host'])         : setting('mail_host', '');
$mailPort       = !empty($_POST['mail_port'])         ? (int) $_POST['mail_port']         : (int) setting('mail_port', 587);
$mailUsername   = isset($_POST['mail_username'])      ? $_POST['mail_username']           : setting('mail_username', '');
$mailPassword   = isset($_POST['mail_password'])      ? $_POST['mail_password']           : setting('mail_password', '');
$mailEncryption = !empty($_POST['mail_encryption'])   ? trim($_POST['mail_encryption'])   : setting('mail_encryption', 'tls');
$mailFrom       = !empty($_POST['mail_from_address']) ? sanitizeEmail($_POST['mail_from_address']) : setting('mail_from_address', '');
$mailFromName   = !empty($_POST['mail_from_name'])    ? trim($_POST['mail_from_name'])    : setting('mail_from_name', APP_NAME);

if (empty($mailFromName)) {
    $mailFromName = APP_NAME;
}
if (empty($mailFrom)) {
    $mailFrom = setting('mail_from_address', 'noreply@' . APP_DOMAIN);
}

// Require at minimum host / username / password
if (empty($mailHost) || empty($mailUsername) || empty($mailPassword)) {
    jsonExit(false, 'SMTP credentials are incomplete. Please fill in Host, Username and Password before testing.');
}

// Check PHPMailer is installed
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    jsonExit(false, 'PHPMailer is not installed. Run "composer install" on the server.');
}

try {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $mailHost;
    $mail->Port       = $mailPort;
    $mail->SMTPAuth   = true;
    $mail->Username   = $mailUsername;
    $mail->Password   = $mailPassword;
    $mail->Timeout    = 10;
    $mail->SMTPDebug  = 0;
    $mail->SMTPKeepAlive = false;

    if ($mailEncryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($mailEncryption === 'none') {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    } else {
        // tls (STARTTLS)
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    $mail->CharSet = 'UTF-8';
    $mail->setFrom($mailFrom, $mailFromName);
    $mail->addAddress($testEmail);

    $mail->isHTML(true);
    $mail->Subject = '✅ SMTP Test — ' . APP_NAME;
    $mail->Body    = "
        <div style='font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:32px;background:#f9fafb;border-radius:12px;'>
            <h2 style='color:#16a34a;'>✅ SMTP Test Successful</h2>
            <p>This test email was sent from your <strong>" . htmlspecialchars(APP_NAME) . "</strong> admin panel to confirm that your SMTP configuration is working correctly.</p>
            <table style='width:100%;border-collapse:collapse;margin-top:16px;font-size:14px;'>
                <tr><td style='padding:6px;color:#6b7280;'>Host</td><td style='padding:6px;'><strong>" . htmlspecialchars($mailHost) . "</strong></td></tr>
                <tr><td style='padding:6px;color:#6b7280;'>Port</td><td style='padding:6px;'><strong>{$mailPort}</strong></td></tr>
                <tr><td style='padding:6px;color:#6b7280;'>Encryption</td><td style='padding:6px;'><strong>" . htmlspecialchars($mailEncryption) . "</strong></td></tr>
                <tr><td style='padding:6px;color:#6b7280;'>Sent at</td><td style='padding:6px;'><strong>" . date('Y-m-d H:i:s') . "</strong></td></tr>
            </table>
        </div>
    ";
    $mail->AltBody = "SMTP Test Successful. Host: {$mailHost}, Port: {$mailPort}. Sent at " . date('Y-m-d H:i:s');

    $mail->send();

    auditLog('SMTP_TEST_EMAIL_SENT', "Test email sent to {$testEmail} via {$mailHost}:{$mailPort}", 'settings', currentUserId());
    jsonExit(true, "Test email sent successfully to {$testEmail}. Check your inbox.");

} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    error_log('[SMTP Test] ' . $errorMsg);
    jsonExit(false, 'SMTP Error: ' . $errorMsg);
}
