<?php
/**
 * OURCR ONLINE - Reset Password Page
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

$pageTitle = 'Reset Password';
$token     = sanitize(get('token', post('token', '')));
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
        $token    = sanitize(post('token', ''));
        $password = post('password', '');
        $confirm  = post('password_confirm', '');

        if (empty($token)) {
            $error = 'Reset token is required. Please check your email link or enter the token.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $result  = resetPassword($token, $password);
            if ($result['success']) {
                $success = $result['message'];
            } else {
                $error = $result['message'];
            }
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Reset password exception: ' . $e->getMessage(), [
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
            <i class="fas fa-lock"></i>
        </div>
        <h1 class="auth-title">Set New Password</h1>
        <p class="auth-subtitle">Choose a strong password for your account.</p>

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

        <?php if (!empty($success)): ?>
            <a href="<?= APP_URL ?>/login" class="btn auth-btn w-100 mt-3 text-white">
                <i class="fas fa-sign-in-alt me-2"></i>Login Now
            </a>
        <?php else: ?>
        <form method="POST" action="<?= APP_URL ?>/reset-password" id="resetForm">
            <?= csrfField() ?>
            <?php if (!empty($token)): ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
            <?php else: ?>
                <div class="mb-3">
                    <label for="token" class="form-label">Reset Token / Code</label>
                    <div class="input-group auth-input-group">
                        <span class="input-group-text"><i class="fas fa-key"></i></span>
                        <input type="text" class="form-control auth-input" id="token" name="token"
                               placeholder="Paste your reset token from email" required>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mb-3">
                <label for="password" class="form-label">New Password</label>
                <div class="input-group auth-input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control auth-input" id="password" name="password"
                           placeholder="Min. 8 chars, uppercase, number" required>
                    <button class="input-group-text btn-toggle-pwd" type="button" data-target="password" tabindex="-1">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="mb-4">
                <label for="password_confirm" class="form-label">Confirm New Password</label>
                <div class="input-group auth-input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control auth-input" id="password_confirm"
                           name="password_confirm" placeholder="Repeat password" required>
                    <button class="input-group-text btn-toggle-pwd" type="button" data-target="password_confirm" tabindex="-1">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn auth-btn w-100" id="submitBtn">
                <span class="btn-text"><i class="fas fa-save me-2"></i>Reset Password</span>
                <span class="btn-loading d-none">
                    <span class="spinner-border spinner-border-sm me-2"></span>Resetting...
                </span>
            </button>
        </form>
        <?php endif; ?>

        <div class="text-center mt-4">
            <a href="<?= APP_URL ?>/login" class="auth-link">
                <i class="fas fa-arrow-left me-1"></i> Back to Login
            </a>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('resetForm')?.addEventListener('submit', function (e) {
    const pw  = document.getElementById('password').value;
    const cpw = document.getElementById('password_confirm').value;

    // Clear client errors
    const clientErr = document.getElementById('inline-client-error');
    if (clientErr) {
        clientErr.remove();
    }

    if (pw !== cpw) { 
        e.preventDefault(); 
        showInlineError('Passwords do not match.'); 
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

document.querySelectorAll('.btn-toggle-pwd').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const t = document.getElementById(this.dataset.target);
        const i = this.querySelector('i');
        t.type  = t.type === 'password' ? 'text' : 'password';
        i.classList.toggle('fa-eye'); i.classList.toggle('fa-eye-slash');
    });
});
</script>
</body>
</html>
