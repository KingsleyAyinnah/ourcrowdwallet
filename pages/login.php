<?php
/**
 * OURCR ONLINE - Login Page
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

$pageTitle = 'Login';
$error     = '';
$success   = '';

// Fetch existing session flash messages for inline rendering (e.g. redirected from registration/logout)
$flashMessages = getFlash();
foreach ($flashMessages as $flash) {
    if ($flash['type'] === 'error') {
        $error = $flash['message'];
    } elseif ($flash['type'] === 'success') {
        $success = $flash['message'];
    }
}

// Handle POST
if (isPost()) {
    try {
        requireCsrf();

        $email      = sanitizeEmail(post('email'));
        $password   = post('password');
        $rememberMe = (bool) post('remember_me');

        if (empty($email) || empty($password)) {
            $error = 'Please enter your email and password.';
        } else {
            // Check if user exists and is admin before attempting login
            $userCheck = Database::fetchOne(
                'SELECT role FROM users WHERE (email = ? OR phone = ?) AND deleted_at IS NULL LIMIT 1',
                [$email, $email]
            );
            
            if ($userCheck && in_array($userCheck['role'], ['admin', 'superadmin'], true)) {
                $error = 'This is an admin credential. Please log in through the admin panel.';
            } else {
                $result = attemptLogin($email, $password, $rememberMe);

                if ($result['success']) {
                    $user = $result['user'];
                    $intended = getIntendedUrl(APP_URL . '/dashboard');
                    redirect($intended);
                } else {
                    $error = $result['message'];
                    // Check if redirect to OTP is needed
                    if (isset($result['redirect']) && $result['redirect'] === 'verify-otp') {
                        redirectTo('verify-otp');
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        $error = 'A system error occurred. Please try again.';
        error_log('[Login Error] ' . $e->getMessage());
    }
}

$siteName  = setting('site_name', APP_NAME);
$siteColor = setting('site_color', DEFAULT_SITE_COLOR);
$tagline   = setting('site_tagline', '');
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
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/images/favicon.png?v=<?= APP_VERSION ?>">
</head>
<body class="auth-body">

<div class="auth-container">
    <!-- Left Panel (branding) -->
    <div class="auth-left d-none d-lg-flex">
        <div class="auth-left-content">
            <div class="auth-logo mb-4">
                <a href="<?= APP_URL ?>/">
                    <img src="<?= APP_URL ?>/assets/images/logo-white.png"
                         alt="<?= e($siteName) ?>"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                    <span class="auth-brand-text" style="display:none"><?= e($siteName) ?></span>
                </a>
            </div>
            <h2 class="auth-left-title">Power Your Digital Life</h2>
            <p class="auth-left-sub"><?= e($tagline) ?></p>

            <div class="auth-features">
                <div class="auth-feature-item">
                    <i class="fas fa-bolt"></i>
                    <span>Instant Airtime & Data</span>
                </div>
                <div class="auth-feature-item">
                    <i class="fas fa-shield-alt"></i>
                    <span>Bank-grade Security</span>
                </div>
                <div class="auth-feature-item">
                    <i class="fas fa-wallet"></i>
                    <span>Smart Wallet Management</span>
                </div>
                <div class="auth-feature-item">
                    <i class="fas fa-headset"></i>
                    <span>24/7 Support</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Panel (form) -->
    <div class="auth-right">
        <div class="auth-form-wrapper">
            <!-- Mobile logo -->
            <div class="auth-mobile-logo d-lg-none text-center mb-4">
                <a href="<?= APP_URL ?>/">
                    <img src="<?= APP_URL ?>/assets/images/logo.png"
                         alt="<?= e($siteName) ?>"
                         style="height:44px;"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                    <span class="fw-bold fs-4" style="color:var(--site-color);display:none"><?= e($siteName) ?></span>
                </a>
            </div>

            <h1 class="auth-title">Welcome back</h1>
            <p class="auth-subtitle">Sign in to your account to continue</p>

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

            <form method="POST" action="<?= APP_URL ?>/login" id="loginForm" novalidate>
                <?= csrfField() ?>

                <div class="mb-3">
                    <label for="email" class="form-label">Email Address or Phone</label>
                    <div class="input-group auth-input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="text"
                               class="form-control auth-input"
                               id="email"
                               name="email"
                               value="<?= e(post('email')) ?>"
                               placeholder="you@example.com"
                               autocomplete="email"
                               required>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <label for="password" class="form-label">Password</label>
                        <a href="<?= APP_URL ?>/forgot-password" class="auth-link small">Forgot password?</a>
                    </div>
                    <div class="input-group auth-input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password"
                               class="form-control auth-input"
                               id="password"
                               name="password"
                               placeholder="Your password"
                               autocomplete="current-password"
                               required>
                        <button class="input-group-text btn-toggle-pwd" type="button" tabindex="-1"
                                data-target="password" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-4 d-flex align-items-center justify-content-between">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me" value="1">
                        <label class="form-check-label" for="remember_me">Remember me for 30 days</label>
                    </div>
                </div>

                <button type="submit" class="btn auth-btn w-100" id="loginBtn">
                    <span class="btn-text"><i class="fas fa-sign-in-alt me-2"></i>Sign In</span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2" role="status"></span>Signing in...
                    </span>
                </button>
            </form>

            <div class="auth-divider"><span>Don't have an account?</span></div>

            <a href="<?= APP_URL ?>/register" class="btn auth-btn-outline w-100">
                <i class="fas fa-user-plus me-2"></i>Create Account
            </a>

            <p class="auth-footer-text">
                By signing in, you agree to our
                <a href="<?= APP_URL ?>/terms" class="auth-link">Terms of Service</a> and
                <a href="<?= APP_URL ?>/privacy" class="auth-link">Privacy Policy</a>.
            </p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('loginForm').addEventListener('submit', function (e) {
    const emailInput = document.getElementById('email');
    const pwdInput = document.getElementById('password');
    const email = emailInput.value.trim();
    const pwd = pwdInput.value.trim();

    // Clear any client-side error blocks
    const clientErr = document.getElementById('inline-client-error');
    if (clientErr) {
        clientErr.remove();
    }

    if (email === '' || pwd === '') {
        e.preventDefault();
        showInlineError('Please enter your email and password.');
        return;
    }

    const btn  = document.getElementById('loginBtn');
    btn.querySelector('.btn-text').classList.add('d-none');
    btn.querySelector('.btn-loading').classList.remove('d-none');
    
    // Use setTimeout so disabling the button does not abort the browser's form submission
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

// Password visibility toggles
document.querySelectorAll('.btn-toggle-pwd').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const target = document.getElementById(this.dataset.target);
        const icon   = this.querySelector('i');
        if (target.type === 'password') {
            target.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            target.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });
});
</script>
</body>
</html>
