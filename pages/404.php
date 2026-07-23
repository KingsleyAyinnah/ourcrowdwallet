<?php
/**
 * OURCR ONLINE - 404 Not Found
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

http_response_code(404);
$siteName  = setting('site_name', APP_NAME);
$siteColor = setting('site_color', DEFAULT_SITE_COLOR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found — <?= e($siteName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        :root { --site-color: <?= e($siteColor) ?>; }
        body { font-family: 'DM Sans', sans-serif; background: #f9fafb; }
        .error-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; text-align: center; }
        .error-code { font-size: 8rem; font-weight: 800; color: var(--site-color); line-height: 1; }
        .error-title { font-size: 2rem; font-weight: 700; color: #111827; margin: 1rem 0 0.5rem; }
        .error-sub { color: #6b7280; max-width: 400px; margin: 0 auto; }
        .btn-home { background: var(--site-color); color: #fff; padding: 12px 28px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; margin-top: 2rem; }
        .btn-home:hover { opacity: .9; color: #fff; }
    </style>
</head>
<body>
<div class="error-page">
    <div>
        <div class="error-code">404</div>
        <h1 class="error-title">Page Not Found</h1>
        <p class="error-sub">The page you're looking for doesn't exist or has been moved.</p>
        <?php if (isLoggedIn()): ?>
            <a href="<?= APP_URL ?>/dashboard" class="btn-home">
                <i class="fas fa-home"></i> Go to Dashboard
            </a>
        <?php else: ?>
            <a href="<?= APP_URL ?>/" class="btn-home">
                <i class="fas fa-home"></i> Go Home
            </a>
        <?php endif; ?>
    </div>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</body>
</html>
