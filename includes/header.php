<?php
/**
 * OURCR ONLINE - User Dashboard HTML Head
 * Included at the top of every user-facing page
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

// Determine site color (user override or default)
$user      = isLoggedIn() ? currentUser() : null;
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);
$siteName  = setting('site_name', APP_NAME);
$pageTitle = $pageTitle ?? $siteName;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="<?= e($siteColor) ?>">
    <meta name="description" content="<?= e(setting('site_tagline', '')) ?>">
    <meta name="robots" content="index, follow">
    <?= csrfMeta() ?>

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
    <!-- Social Open Graph / WhatsApp / Facebook / Telegram Link Preview -->
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

    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Google Fonts: DM Sans -->
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= APP_VERSION ?>">

    <!-- Dynamic site color injection -->
    <style>
        :root {
            --site-color: <?= e($siteColor) ?>;
            --site-color-rgb: <?= hexToRgb($siteColor) ?>;
        }
    </style>

    <script>
        var APP_URL = "<?= APP_URL ?>";
    </script>

    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/images/favicon.png?v=<?= APP_VERSION ?>">
</head>
<body class="app-body" data-site-color="<?= e($siteColor) ?>">
