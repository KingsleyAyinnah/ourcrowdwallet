<?php
/**
 * OURCR ONLINE - Forgot Password Page
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

$pageTitle = 'Forgot Password';
$error     = '';
$success   = '';

// Fetch existing session flash messages for inline rendering
$flashMessages = getFlash();
foreach ($flashMessages as $flash) {
    if ($flash['type'] === 'error') {
        $error = $flash['message'];
    } elseif ($flash['type'] === 'success') {
        $success = $flash['message'];
    }
}

if (isPost()) {
    try {
        requireCsrf();
        $email  = sanitizeEmail(post('email'));
        $result = initiatePasswordReset($email);
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Forgot password exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine()
        ]);
        $error = 'A technical error occurred.';
    }
}

$siteName  = setting('site_name', APP_NAME);
$siteColor = setting('site_color', DEFAULT_SITE_COLOR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e($siteName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/auth.css?v=<?= APP_VERSION ?>">
    <style>:root{--site-color:<?= e($siteColor) ?>;}</style>
</head>
<body class="auth-body">
<div class="auth-simple-container">
    <div class="auth-simple-card">
        <div class="auth-simple-icon">
            <i class="fas fa-key"></i>
        </div>
        <h1 class="auth-title">Forgot Password?</h1>
        <p class="auth-subtitle">Enter your email and we'll send you a reset link.</p>

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

        <?php if (empty($success)): ?>
        <form method="POST" action="<?= APP_URL ?>/forgot-password" id="forgotForm">
            <?= csrfField() ?>
            <div class="mb-4">
                <label for="email" class="form-label">Email Address</label>
                <div class="input-group auth-input-group">
                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                    <input type="email" class="form-control auth-input" id="email" name="email"
                           value="<?= e(post('email')) ?>" placeholder="you@example.com" required autofocus>
                </div>
            </div>
            <button type="submit" class="btn auth-btn w-100" id="submitBtn">
                <span class="btn-text"><i class="fas fa-paper-plane me-2"></i>Send Reset Link</span>
                <span class="btn-loading d-none">
                    <span class="spinner-border spinner-border-sm me-2"></span>Sending...
                </span>
            </button>
        </form>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mt-4">
            <a href="<?= APP_URL ?>/login" class="auth-link">
                <i class="fas fa-arrow-left me-1"></i> Back to Login
            </a>
            <a href="<?= APP_URL ?>/register" class="auth-link text-decoration-none fw-bold" style="color: var(--site-color);">
                Register <i class="fas fa-arrow-right ms-1"></i>
            </a>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('forgotForm')?.addEventListener('submit', function (e) {
    const emailInput = document.getElementById('email');
    const email = emailInput.value.trim();

    // Clear client errors
    const clientErr = document.getElementById('inline-client-error');
    if (clientErr) {
        clientErr.remove();
    }

    if (email === '') {
        e.preventDefault();
        showInlineError('Please enter your email address.');
        return;
    }

    const btn = document.getElementById('submitBtn');
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
</script>
</body>
</html>
