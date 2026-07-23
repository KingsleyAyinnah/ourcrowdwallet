<?php
/**
 * OURCR ONLINE - Maintenance Mode Page
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

http_response_code(503);
$siteName  = setting('site_name', APP_NAME);
$siteColor = setting('site_color', DEFAULT_SITE_COLOR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Under Maintenance — <?= e($siteName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --site-color: <?= e($siteColor) ?>; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DM Sans', sans-serif; background: #0f172a; color: #fff; min-height: 100vh; display: flex; align-items: center; justify-content: center; text-align: center; }
        .icon { font-size: 5rem; color: var(--site-color); margin-bottom: 1.5rem; }
        h1 { font-size: 2.5rem; font-weight: 800; margin-bottom: 1rem; }
        p { color: #94a3b8; max-width: 480px; margin: 0 auto; font-size: 1.1rem; line-height: 1.6; }
        .brand { font-weight: 800; color: var(--site-color); }
    </style>
</head>
<body>
    <div>
        <div class="icon"><i class="fas fa-tools"></i></div>
        <h1>We'll Be Back Soon</h1>
        <p>
            <span class="brand"><?= e($siteName) ?></span> is currently undergoing scheduled maintenance.
            We apologize for any inconvenience and will be back shortly.
        </p>
    </div>
</body>
</html>
