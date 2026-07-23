<?php
/**
 * OURCR ONLINE - Admin Panel HTML Head
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

$siteName  = setting('site_name', APP_NAME);
$pageTitle = $adminPageTitle ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <?= csrfMeta() ?>
    <title><?= e($pageTitle) ?> — <?= e($siteName) ?> Admin</title>

    <!-- Google Fonts: Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Admin CSS -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/master_style.css?v=<?= APP_VERSION ?>">

    <script>
        var APP_URL = "<?= APP_URL ?>";
    </script>

    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/images/favicon.png?v=<?= APP_VERSION ?>">
</head>
<body class="admin-body">
