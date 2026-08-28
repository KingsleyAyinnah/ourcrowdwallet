<?php
/**
 * OURCR ONLINE - Registration Page
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

if (setting('allow_registration', '1') !== '1') {
    setFlash('error', 'New registrations are currently disabled.');
    redirectTo('login');
}

$pageTitle = 'Create Account';
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

        $result = registerUser($_POST);
        if ($result['success']) {
            setFlash('success', 'Account registered. Please enter the OTP sent to verify your phone/email.');
            // OTP has been sent — redirect to verification page
            redirectTo('verify-otp');
        } else {
            $error = implode(" | ", $result['errors']);
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Registration exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine()
        ]);
        $error = 'A technical error occurred during registration. Please try again.';
    }
}

$siteName  = setting('site_name', APP_NAME);
$siteColor = setting('site_color', DEFAULT_SITE_COLOR);
$referralCode = sanitize(get('ref', ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="index, follow">

    <!-- Social Open Graph / WhatsApp / Facebook / Telegram Link Preview -->
    <?php
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocal = empty($host) || str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');
    $publicDomain = $isLocal ? 'https://ourcrowdwallet.com' : (isHttps() ? 'https://' : 'http://') . $host;
    $subDir = (defined('APP_URL') && str_contains(APP_URL, '/ourcr')) ? '/ourcr' : '';

    $ogUrl         = $publicDomain . ($subDir ? $subDir : '') . ($_SERVER['REQUEST_URI'] ?? '');
    $ogTagline     = setting('site_tagline', 'Instant Data, Airtime & Utility Bills Payment Platform');
    $ogTitle       = (!empty($pageTitle) && $pageTitle !== $siteName) ? ($pageTitle . ' — ' . $siteName) : ($siteName . ' — ' . $ogTagline);
    $ogDescription = 'Join ' . $siteName . ' today! Buy cheap data, airtime, pay utility bills instantly and earn referral bonuses on every transaction.';
    $ogImage       = $publicDomain . ($subDir ? $subDir : '') . '/assets/images/digital-flyer.jpeg';
    ?>
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= e($ogUrl) ?>">
    <meta property="og:site_name" content="<?= e($siteName) ?>">
    <meta property="og:title" content="<?= e($ogTitle) ?>">
    <meta property="og:description" content="<?= e($ogDescription) ?>">
    <meta property="og:image" content="<?= e($ogImage) ?>">
    <meta property="og:image:url" content="<?= e($ogImage) ?>">
    <meta property="og:image:secure_url" content="<?= e($ogImage) ?>">
    <meta property="og:image:type" content="image/jpeg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?= e($siteName) ?> Digital Flyer">
    <link rel="image_src" href="<?= e($ogImage) ?>">
    <link rel="apple-touch-icon" href="<?= e($ogImage) ?>">

    <!-- Twitter Card Meta Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="<?= e($ogUrl) ?>">
    <meta name="twitter:title" content="<?= e($ogTitle) ?>">
    <meta name="twitter:description" content="<?= e($ogDescription) ?>">
    <meta name="twitter:image" content="<?= e($ogImage) ?>">

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
    <!-- Left Panel -->
    <div class="auth-left d-none d-lg-flex">
        <div class="auth-left-content">
            <div class="auth-logo mb-4">
                <a href="<?= APP_URL ?>/">
                    <img src="<?= APP_URL ?>/assets/images/logo-white.png" alt="<?= e($siteName) ?>"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                    <span class="auth-brand-text" style="display:none"><?= e($siteName) ?></span>
                </a>
            </div>
            <h2 class="auth-left-title">Join Thousands of Nigerians</h2>
            <p class="auth-left-sub">Buy airtime, data, pay bills and earn referral bonuses — all in one place.</p>
            <div class="auth-features">
                <div class="auth-feature-item"><i class="fas fa-gift"></i><span>Earn ₦<?= REFERRAL_BONUS ?> per referral</span></div>
                <div class="auth-feature-item"><i class="fas fa-zap"></i><span>Instant transactions</span></div>
                <div class="auth-feature-item"><i class="fas fa-lock"></i><span>Secure & encrypted</span></div>
                <div class="auth-feature-item"><i class="fas fa-percent"></i><span>Best market rates</span></div>
            </div>
        </div>
    </div>

    <!-- Right Panel -->
    <div class="auth-right auth-right-register">
        <div class="auth-form-wrapper">
            <div class="auth-mobile-logo d-lg-none text-center mb-4">
                <a href="<?= APP_URL ?>/">
                    <img src="<?= APP_URL ?>/assets/images/logo.png" alt="<?= e($siteName) ?>" style="height:44px;"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                    <span class="fw-bold fs-4" style="color:var(--site-color);display:none"><?= e($siteName) ?></span>
                </a>
            </div>

            <h1 class="auth-title">Create Account</h1>
            <p class="auth-subtitle">Fill in your details to get started</p>

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
                        <div class="fw-medium">
                            <?= e($success) ?>
                            <div class="mt-2">
                                <a href="<?= APP_URL ?>/login" class="btn btn-sm btn-success text-white">
                                    <i class="fas fa-sign-in-alt me-1"></i>Login Now
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$success): ?>
                <form method="POST" action="<?= APP_URL ?>/register" id="registerForm" novalidate>
                    <?= csrfField() ?>

                    <div class="row g-3">
                        <div class="col-6">
                            <label for="first_name" class="form-label">First Name</label>
                            <div class="input-group auth-input-group">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                <input type="text" class="form-control auth-input" id="first_name" name="first_name"
                                       value="<?= e(post('first_name')) ?>" placeholder="John" required>
                            </div>
                        </div>
                        <div class="col-6">
                            <label for="last_name" class="form-label">Last Name</label>
                            <div class="input-group auth-input-group">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                <input type="text" class="form-control auth-input" id="last_name" name="last_name"
                                       value="<?= e(post('last_name')) ?>" placeholder="Doe" required>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label for="username" class="form-label">Username</label>
                        <div class="input-group auth-input-group">
                            <span class="input-group-text"><i class="fas fa-at"></i></span>
                            <input type="text" class="form-control auth-input" id="username" name="username"
                                   value="<?= e(post('username')) ?>" placeholder="johndoe" autocomplete="username" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <div class="input-group auth-input-group">
                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                            <input type="email" class="form-control auth-input" id="email" name="email"
                                   value="<?= e(post('email')) ?>" placeholder="john@example.com" autocomplete="email" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone Number</label>
                        <div class="input-group auth-input-group">
                            <span class="input-group-text"><i class="fas fa-phone"></i></span>
                            <input type="tel" class="form-control auth-input" id="phone" name="phone"
                                   value="<?= e(post('phone')) ?>" placeholder="08012345678" autocomplete="tel" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group auth-input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" class="form-control auth-input" id="password" name="password"
                                   placeholder="Min. 8 chars, uppercase, number" autocomplete="new-password" required>
                            <button class="input-group-text btn-toggle-pwd" type="button" data-target="password" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="auth-password-strength mt-2" id="pwStrength"></div>
                    </div>

                    <div class="mb-3">
                        <label for="password_confirm" class="form-label">Confirm Password</label>
                        <div class="input-group auth-input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" class="form-control auth-input" id="password_confirm"
                                   name="password_confirm" placeholder="Repeat password" required>
                            <button class="input-group-text btn-toggle-pwd" type="button" data-target="password_confirm" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="state_of_residence" class="form-label">State of Residence</label>
                        <div class="input-group auth-input-group">
                            <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                            <select class="form-select auth-input" id="state_of_residence" name="state_of_residence" required>
                                <option value="" disabled selected>Select your state</option>
                                <?php
                                $states = [
                                    "Abia", "Adamawa", "Akwa Ibom", "Anambra", "Bauchi", "Bayelsa", "Benue", "Borno", 
                                    "Cross River", "Delta", "Ebonyi", "Edo", "Ekiti", "Enugu", "FCT (Abuja)", "Gombe", 
                                    "Imo", "Jigawa", "Kaduna", "Kano", "Katsina", "Kebbi", "Kogi", "Kwara", "Lagos", 
                                    "Nasarawa", "Niger", "Ogun", "Ondo", "Osun", "Oyo", "Plateau", "Rivers", "Sokoto", 
                                    "Taraba", "Yobe", "Zamfara"
                                ];
                                foreach ($states as $state):
                                    $selected = (post('state_of_residence') === $state) ? 'selected' : '';
                                ?>
                                    <option value="<?= e($state) ?>" <?= $selected ?>><?= e($state) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label for="transaction_pin" class="form-label">Transaction PIN</label>
                            <div class="input-group auth-input-group">
                                <span class="input-group-text"><i class="fas fa-key"></i></span>
                                <input type="password" class="form-control auth-input text-center" id="transaction_pin" name="transaction_pin"
                                       maxlength="4" pattern="\d{4}" placeholder="PIN" required>
                            </div>
                        </div>
                        <div class="col-6">
                            <label for="transaction_pin_confirm" class="form-label">Confirm PIN</label>
                            <div class="input-group auth-input-group">
                                <span class="input-group-text"><i class="fas fa-key"></i></span>
                                <input type="password" class="form-control auth-input text-center" id="transaction_pin_confirm" name="transaction_pin_confirm"
                                       maxlength="4" pattern="\d{4}" placeholder="PIN" required>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="referral_code" class="form-label">Referral Code <span class="text-muted">(optional)</span></label>
                        <div class="input-group auth-input-group">
                            <span class="input-group-text"><i class="fas fa-user-plus"></i></span>
                            <input type="text" class="form-control auth-input" id="referral_code" name="referral_code"
                                   value="<?= e($referralCode ?: post('referral_code')) ?>" placeholder="Enter referral code">
                        </div>
                    </div>

                    <div class="mb-4 form-check">
                        <input type="checkbox" class="form-check-input" id="agree" name="agree" required>
                        <label class="form-check-label" for="agree">
                            I agree to the <a href="<?= APP_URL ?>/terms" class="auth-link" target="_blank">Terms of Service</a>
                            and <a href="<?= APP_URL ?>/privacy" class="auth-link" target="_blank">Privacy Policy</a>
                        </label>
                    </div>

                    <button type="submit" class="btn auth-btn w-100" id="registerBtn">
                        <span class="btn-text"><i class="fas fa-user-plus me-2"></i>Create My Account</span>
                        <span class="btn-loading d-none">
                            <span class="spinner-border spinner-border-sm me-2" role="status"></span>Creating account...
                        </span>
                    </button>
                </form>
            <?php endif; ?>

            <div class="auth-divider"><span>Already have an account?</span></div>
            <a href="<?= APP_URL ?>/login" class="btn auth-btn-outline w-100">
                <i class="fas fa-sign-in-alt me-2"></i>Sign In
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<?php renderSweetAlerts(); ?>
<script>
// Password strength meter
document.getElementById('password').addEventListener('input', function () {
    const val = this.value;
    const bar = document.getElementById('pwStrength');
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[a-z]/.test(val)) score++;
    if (/\d/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const levels = ['', 'Very Weak', 'Weak', 'Fair', 'Strong', 'Very Strong'];
    const colors = ['', '#ef4444', '#f97316', '#eab308', '#22c55e', '#16a34a'];
    const pct    = (score / 5) * 100;
    bar.innerHTML = score
        ? `<div style="height:4px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
             <div style="height:100%;width:${pct}%;background:${colors[score]};transition:width .3s;"></div>
           </div>
           <small style="color:${colors[score]}">${levels[score]}</small>`
        : '';
});

// Confirm password match and client-side validation
document.getElementById('registerForm').addEventListener('submit', function (e) {
    const pw  = document.getElementById('password').value;
    const cpw = document.getElementById('password_confirm').value;
    
    // Clear any previous client-side errors
    const clientErr = document.getElementById('inline-client-error');
    if (clientErr) {
        clientErr.remove();
    }

    if (pw !== cpw) {
        e.preventDefault();
        showInlineError('Passwords do not match.');
        return;
    }
    const pin = document.getElementById('transaction_pin').value;
    const cpin = document.getElementById('transaction_pin_confirm').value;
    if (!/^\d{4}$/.test(pin)) {
        e.preventDefault();
        showInlineError('Transaction PIN must be exactly 4 digits.');
        return;
    }
    if (pin !== cpin) {
        e.preventDefault();
        showInlineError('Transaction PINs do not match.');
        return;
    }
    if (!document.getElementById('agree').checked) {
        e.preventDefault();
        showInlineError('Please agree to the Terms of Service.');
        return;
    }
    
    const btn = document.getElementById('registerBtn');
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

// Password toggles
document.querySelectorAll('.btn-toggle-pwd').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const target = document.getElementById(this.dataset.target);
        const icon   = this.querySelector('i');
        target.type  = target.type === 'password' ? 'text' : 'password';
        icon.classList.toggle('fa-eye');
        icon.classList.toggle('fa-eye-slash');
    });
});
</script>
</body>
</html>
