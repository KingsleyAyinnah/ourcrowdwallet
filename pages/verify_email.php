<?php
/**
 * OURCR ONLINE - Email Verification Page
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

$pageTitle = 'Verify Email';
$token     = sanitize(get('token', ''));
$result    = ['success' => false, 'message' => 'No token provided.'];

if (!empty($token)) {
    $result = verifyEmail($token);
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
    <div class="auth-simple-card text-center">
        <?php if ($result['success']): ?>
            <div class="auth-simple-icon auth-icon-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <h1 class="auth-title">Email Verified!</h1>
            <p class="auth-subtitle"><?= e($result['message']) ?></p>
            <a href="<?= APP_URL ?>/login" class="btn auth-btn mt-3">
                <i class="fas fa-sign-in-alt me-2"></i>Login to Your Account
            </a>
        <?php else: ?>
            <div class="auth-simple-icon auth-icon-error">
                <i class="fas fa-times-circle"></i>
            </div>
            <h1 class="auth-title">Verification Failed</h1>
            <p class="auth-subtitle"><?= e($result['message']) ?></p>
            <a href="<?= APP_URL ?>/login" class="btn auth-btn mt-3">
                <i class="fas fa-arrow-left me-2"></i>Back to Login
            </a>
        <?php endif; ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
