<?php
/**
 * OURCR ONLINE - User Dashboard Top Navbar
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

$user            = currentUser();
$unreadCount     = isLoggedIn() ? getUnreadNotificationCount((int) $user['id']) : 0;
$walletBalance   = isLoggedIn() ? formatMoney((float) $user['wallet_balance']) : '';
$siteName        = setting('site_name', APP_NAME);
$siteColor       = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);
$avatarUrl       = !empty($user['avatar'])
    ? AVATAR_URL . e($user['avatar'])
    : APP_URL . '/assets/images/avatar-default.png';
?>
<nav class="app-navbar" id="appNavbar">
    <div class="navbar-left">
        <!-- Mobile hamburger (opens sidebar) -->
        <button class="navbar-toggle d-lg-none" id="sidebarToggle" aria-label="Open menu">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Logo -->
        <a href="<?= APP_URL ?>/dashboard" class="navbar-brand-link">
            <img src="<?= APP_URL ?>/assets/images/logo.png"
                 alt="<?= e($siteName) ?>"
                 class="navbar-logo"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
            <span class="navbar-brand-text" style="display:none"><?= e($siteName) ?></span>
        </a>
    </div>

    <div class="navbar-center d-none d-lg-flex">
        <!-- Wallet balance pill -->
        <div class="wallet-balance-pill">
            <i class="fas fa-wallet me-2"></i>
            <span id="navWalletBalance"><?= e($walletBalance) ?></span>
        </div>
    </div>

    <div class="navbar-right">
        <!-- Notifications -->
        <div class="navbar-icon-btn position-relative" id="notifToggle">
            <a href="<?= APP_URL ?>/notifications" aria-label="Notifications">
                <i class="fas fa-bell"></i>
                <?php if ($unreadCount > 0): ?>
                    <span class="badge-dot"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
                <?php endif; ?>
            </a>
        </div>

        <!-- Avatar / Profile dropdown -->
        <div class="dropdown">
            <button class="navbar-avatar-btn dropdown-toggle" type="button"
                    data-bs-toggle="dropdown" aria-expanded="false">
                <img src="<?= $avatarUrl ?>"
                     alt="<?= e($user['first_name'] ?? '') ?>"
                     class="navbar-avatar"
                     onerror="this.src='<?= APP_URL ?>/assets/images/avatar-default.png'">
                <span class="d-none d-lg-inline ms-2 fw-medium">
                    <?= e($user['first_name'] ?? 'Account') ?>
                </span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end navbar-dropdown">
                <li class="dropdown-header">
                    <div class="fw-semibold"><?= e(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></div>
                    <div class="small text-muted"><?= maskEmail($user['email'] ?? '') ?></div>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item" href="<?= APP_URL ?>/profile">
                        <i class="fas fa-user me-2"></i> Profile
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="<?= APP_URL ?>/settings">
                        <i class="fas fa-cog me-2"></i> Settings
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="<?= APP_URL ?>/support">
                        <i class="fas fa-headset me-2"></i> Support
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item text-danger" href="<?= APP_URL ?>/logout"
                       onclick="return confirm('Are you sure you want to logout?')">
                        <i class="fas fa-sign-out-alt me-2"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>
