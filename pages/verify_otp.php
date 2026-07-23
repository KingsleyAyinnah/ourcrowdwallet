<?php
/**
 * OURCR ONLINE - OTP Email Verification Page
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

// If no pending OTP session, redirect to register
if (empty($_SESSION['otp_pending_user_id'])) {
    setFlash('error', 'No pending verification. Please register first.');
    redirectTo('register');
}

$pageTitle    = 'Verify Your Email';
$siteName     = setting('site_name', APP_NAME);
$siteColor    = setting('site_color', DEFAULT_SITE_COLOR);
$pendingEmail = $_SESSION['otp_pending_email'] ?? '';
$firstName    = $_SESSION['otp_pending_first_name'] ?? 'User';

$error   = '';
$success = '';

// Fetch existing session flash messages for inline rendering
$flashMessages = getFlash();
foreach ($flashMessages as $flash) {
    if ($flash['type'] === 'error') {
        $error = $flash['message'];
    } elseif ($flash['type'] === 'success') {
        $success = $flash['message'];
    }
}

// Handle OTP resend
if (isPost() && isset($_POST['action']) && $_POST['action'] === 'resend') {
    try {
        requireCsrf();
        $r = resendOtp();
        $success = $r['message'];
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'OTP resend exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine()
        ]);
        $error = 'A technical error occurred while resending OTP.';
    }
}

// Handle OTP submit
if (isPost() && !isset($_POST['action'])) {
    try {
        requireCsrf();
        $otpInput = trim($_POST['otp'] ?? '');
        if (empty($otpInput) || !preg_match('/^\d{4}$/', $otpInput)) {
            $error = 'Please enter a valid 4-digit OTP.';
        } else {
            $result = verifyOtp($otpInput);
            if ($result['success']) {
                setFlash('success', $result['message']);
                redirectTo('dashboard');
            } else {
                $error = $result['message'];
            }
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'OTP verify exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine()
        ]);
        $error = 'A technical error occurred during verification.';
    }
}

// Mask email for display: j***@gmail.com
$maskedEmail = '';
if ($pendingEmail) {
    $parts = explode('@', $pendingEmail);
    $local = $parts[0];
    $domain = $parts[1] ?? '';
    $maskedEmail = substr($local, 0, 1) . str_repeat('*', max(strlen($local) - 2, 2)) . substr($local, -1) . '@' . $domain;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e($siteName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/auth.css?v=<?= APP_VERSION ?>">
    <style>:root { --site-color: <?= e($siteColor) ?>; --site-color-rgb: <?= hexToRgb($siteColor) ?>; }</style>
    <link class="favicon" rel="icon" type="image/png" href="<?= APP_URL ?>/assets/images/favicon.png?v=<?= APP_VERSION ?>">
</head>
<body class="auth-body">

<div class="auth-container">
    <!-- Left Panel (branding) -->
    <div class="auth-left d-none d-lg-flex">
        <div class="auth-left-content">
            <div class="auth-logo mb-4">
                <a href="<?= APP_URL ?>/">
                    <img src="<?= APP_URL ?>/assets/images/logo-white.png" alt="<?= e($siteName) ?>"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                    <span class="auth-brand-text" style="display:none"><?= e($siteName) ?></span>
                </a>
            </div>
            <h2 class="auth-left-title">Verify Your Email</h2>
            <p class="auth-left-sub">Enter the 4-digit code sent to your email address to activate your account.</p>
            <div class="auth-features">
                <div class="auth-feature-item"><i class="fas fa-shield-alt"></i><span>Secure Verification</span></div>
                <div class="auth-feature-item"><i class="fas fa-clock"></i><span>Code expires in 15 minutes</span></div>
                <div class="auth-feature-item"><i class="fas fa-redo"></i><span>Request new code anytime</span></div>
            </div>
        </div>
    </div>

    <!-- Right Panel (form) -->
    <div class="auth-right">
        <div class="auth-form-wrapper">
            <!-- Mobile logo -->
            <div class="auth-mobile-logo d-lg-none text-center mb-4">
                <a href="<?= APP_URL ?>/">
                    <img src="<?= APP_URL ?>/assets/images/logo.png" alt="<?= e($siteName) ?>" style="height:44px;"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                    <span class="fw-bold fs-4" style="color:var(--site-color);display:none"><?= e($siteName) ?></span>
                </a>
            </div>

            <h1 class="auth-title">Verify Your Email</h1>
            <p class="auth-subtitle">We sent a 4-digit code to <strong><?= e($maskedEmail) ?></strong></p>

            <!-- Inline Validation Alerts -->
            <div id="inline-alert-container">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-3 border-0 py-3 px-4 mb-4 rounded-16 shadow-sm animate-fade-in" style="background: rgba(220, 38, 38, 0.1); border-left: 5px solid #DC2626 !important; color: #DC2626; font-size: 14px;">
                        <i class="fas fa-exclamation-circle fs-5"></i>
                        <div class="fw-medium"><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success d-flex align-items-center gap-3 border-0 py-3 px-4 mb-4 rounded-16 shadow-sm animate-fade-in" style="background: rgba(22, 163, 74, 0.1); border-left: 5px solid #16a34a !important; color: #16a34a; font-size: 14px;">
                        <i class="fas fa-check-circle fs-5"></i>
                        <div class="fw-medium"><?= e($success) ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <form method="POST" action="" id="otpForm" novalidate>
                <?= csrfField() ?>

                <div class="mb-4">
                    <label for="otp" class="form-label">Enter 4-Digit Code</label>
                    <div class="input-group auth-input-group">
                        <span class="input-group-text"><i class="fas fa-key"></i></span>
                        <input type="text" class="form-control auth-input" id="otp" name="otp"
                               placeholder="1234" maxlength="4" pattern="\d{4}" required autocomplete="off">
                    </div>
                    <small class="text-muted mt-2 d-block">Code expires in 15 minutes</small>
                </div>

                <button type="submit" class="btn auth-btn w-100" id="verifyBtn">
                    <span class="btn-text"><i class="fas fa-shield-check me-2"></i>Verify & Activate Account</span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2" role="status"></span>Verifying...
                    </span>
                </button>
            </form>

            <div class="auth-divider"><span>Didn't receive the code?</span></div>

            <form method="POST" action="" id="resendForm" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="btn auth-btn-outline w-100" id="resendBtn" disabled>
                    <i class="fas fa-redo me-2"></i>Resend Code
                </button>
            </form>
            <span class="countdown text-muted small d-block text-center mt-2" id="countdown">&nbsp;in <span id="countdownTimer">60</span>s</span>

            <p class="auth-footer-text mt-4">
                Wrong email? <a href="<?= APP_URL ?>/register" class="auth-link">Go back to registration</a>
            </p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('otpForm').addEventListener('submit', function (e) {
    const otpInput = document.getElementById('otp');
    const otp = otpInput.value.trim();

    // Clear client errors
    const clientErr = document.getElementById('inline-client-error');
    if (clientErr) {
        clientErr.remove();
    }

    if (!/^\d{4}$/.test(otp)) {
        e.preventDefault();
        showInlineError('Please enter a valid 4-digit OTP.');
        return;
    }

    const btn = document.getElementById('verifyBtn');
    btn.querySelector('.btn-text').classList.add('d-none');
    btn.querySelector('.btn-loading').classList.remove('d-none');
    
    setTimeout(function () {
        btn.disabled = true;
    }, 50);
});

function showInlineError(msg) {
    const alertHtml = `
        <div id="inline-client-error" class="alert alert-danger d-flex align-items-center gap-3 border-0 py-3 px-4 mb-4 rounded-16 shadow-sm animate-fade-in" style="background: rgba(220, 38, 38, 0.1); border-left: 5px solid #DC2626 !important; color: #DC2626; font-size: 14px;">
            <i class="fas fa-exclamation-circle fs-5"></i>
            <div class="fw-medium">${msg}</div>
        </div>
    `;
    const container = document.getElementById('inline-alert-container');
    container.innerHTML = alertHtml;
}

// OTP input - allow only digits
document.getElementById('otp').addEventListener('input', function (e) {
    this.value = this.value.replace(/\D/g, '').slice(0, 4);
});

// Countdown timer for resend
let secs = 60;
const timer = setInterval(() => {
    secs--;
    const timerEl = document.getElementById('countdownTimer');
    const cdEl = document.getElementById('countdown');
    if (timerEl) timerEl.textContent = secs;
    if (secs <= 0) {
        clearInterval(timer);
        document.getElementById('resendBtn').disabled = false;
        if (cdEl) cdEl.style.display = 'none';
    }
}, 1000);
</script>
</body>
</html>
