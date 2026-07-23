<?php
/**
 * OURCR ONLINE - User Dashboard Sidebar
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

$user      = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);
$currentPage = $currentPage ?? '';
$siteName  = setting('site_name', APP_NAME);

$menuItems = [
    [
        'label' => 'Dashboard',
        'icon'  => 'fas fa-home',
        'url'   => 'dashboard',
        'slug'  => 'dashboard',
    ],
    [
        'label' => 'Wallet',
        'icon'  => 'fas fa-wallet',
        'url'   => 'wallet',
        'slug'  => 'wallet',
    ],

    [
        'label' => 'Transactions',
        'icon'  => 'fas fa-list-alt',
        'url'   => 'transactions',
        'slug'  => 'transactions',
    ],
    [
        'label'  => 'Buy Airtime',
        'icon'   => 'fas fa-phone',
        'url'    => 'buy-airtime',
        'slug'   => 'buy_airtime',
        'badge'  => serviceEnabled('airtime') ? '' : 'Off',
    ],
    [
        'label' => 'Buy Data',
        'icon'  => 'fas fa-wifi',
        'url'   => 'buy-data',
        'slug'  => 'buy_data',
        'badge' => serviceEnabled('data') ? '' : 'Off',
    ],
    [
        'label' => 'Cable TV',
        'icon'  => 'fas fa-tv',
        'url'   => 'cable-tv',
        'slug'  => 'cable_tv',
        'badge' => serviceEnabled('cable') ? '' : 'Off',
    ],
    [
        'label' => 'Electricity',
        'icon'  => 'fas fa-bolt',
        'url'   => 'electricity',
        'slug'  => 'electricity',
        'badge' => serviceEnabled('electricity') ? '' : 'Off',
    ],
    [
        'label' => 'Betting',
        'icon'  => 'fas fa-futbol',
        'url'   => 'betting',
        'slug'  => 'betting',
        'badge' => serviceEnabled('betting') ? '' : 'Off',
    ],
    [
        'label' => 'Exam Pins',
        'icon'  => 'fas fa-graduation-cap',
        'url'   => 'exam-pins',
        'slug'  => 'exam_pins',
        'badge' => serviceEnabled('exam') ? '' : 'Off',
    ],
    [
        'label' => 'Contribution',
        'icon'  => 'fas fa-users',
        'url'   => 'contribution',
        'slug'  => 'contribution',
        'badge' => 'Soon',
    ],
    [
        'label' => 'Referrals',
        'icon'  => 'fas fa-user-plus',
        'url'   => 'referrals',
        'slug'  => 'referrals',
    ],
    [
        'label' => 'Notifications',
        'icon'  => 'fas fa-bell',
        'url'   => 'notifications',
        'slug'  => 'notifications',
    ],
    [
        'label' => 'Profile',
        'icon'  => 'fas fa-user-circle',
        'url'   => 'profile',
        'slug'  => 'profile',
    ],
    [
        'label' => 'Settings',
        'icon'  => 'fas fa-cog',
        'url'   => 'settings',
        'slug'  => 'settings',
    ],
    [
        'label' => 'Support',
        'icon'  => 'fas fa-headset',
        'url'   => 'support',
        'slug'  => 'support',
    ],
];
?>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<aside class="app-sidebar" id="appSidebar">
    <!-- Logo -->
    <div class="sidebar-header">
        <a href="<?= APP_URL ?>/dashboard" class="sidebar-logo-link">
            <img src="<?= APP_URL ?>/assets/images/logo-white.png"
                 alt="<?= e($siteName) ?>"
                 class="sidebar-logo"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
            <span class="sidebar-brand-text" style="display:none"><?= e($siteName) ?></span>
        </a>
        <button class="sidebar-close d-lg-none" id="sidebarClose" aria-label="Close sidebar">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <!-- User Quick Info -->
    <div class="sidebar-user">
        <div class="sidebar-user-avatar">
            <?php
            $avatarUrl = !empty($user['avatar'])
                ? AVATAR_URL . e($user['avatar'])
                : APP_URL . '/assets/images/avatar-default.png';
            ?>
            <img src="<?= $avatarUrl ?>"
                 alt="<?= e($user['first_name'] ?? '') ?>"
                 onerror="this.src='<?= APP_URL ?>/assets/images/avatar-default.png'">
        </div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?= e($user['first_name'] ?? '') ?> <?= e($user['last_name'] ?? '') ?></div>
            <div class="sidebar-user-email"><?= maskEmail($user['email'] ?? '') ?></div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav" aria-label="Main navigation">
        <ul class="sidebar-menu">
            <?php foreach ($menuItems as $item): ?>
                <?php
                $hasChildren = !empty($item['children']);
                $isActive    = ($currentPage === $item['slug']);
                if ($hasChildren) {
                    foreach ($item['children'] as $child) {
                        $childSlug = str_replace('-', '_', $child['url']);
                        if ($currentPage === $childSlug) {
                            $isActive = true;
                            break;
                        }
                    }
                }
                $activeClass = $isActive ? ' active' : '';
                ?>
                <li class="sidebar-item<?= $activeClass ?><?= $hasChildren ? ' has-children' : '' ?>">
                    <?php if ($hasChildren): ?>
                        <button class="sidebar-link sidebar-link-toggle<?= $activeClass ?>"
                                data-bs-toggle="collapse"
                                data-bs-target="#menu-<?= $item['slug'] ?>"
                                aria-expanded="<?= $isActive ? 'true' : 'false' ?>">
                            <i class="<?= $item['icon'] ?> sidebar-icon"></i>
                            <span><?= e($item['label']) ?></span>
                            <?php if (!empty($item['badge'])): ?>
                                <span class="sidebar-badge"><?= e($item['badge']) ?></span>
                            <?php endif; ?>
                            <i class="fas fa-chevron-down sidebar-chevron ms-auto"></i>
                        </button>
                        <ul class="sidebar-submenu collapse<?= $isActive ? ' show' : '' ?>" id="menu-<?= $item['slug'] ?>">
                            <?php foreach ($item['children'] as $child): ?>
                                <?php
                                $childSlug = str_replace('-', '_', $child['url']);
                                $isChildActive = ($currentPage === $childSlug);
                                ?>
                                <li class="sidebar-subitem">
                                    <a href="<?= APP_URL ?>/<?= $child['url'] ?>" class="sidebar-sublink<?= $isChildActive ? ' active' : '' ?>">
                                        <i class="fas fa-circle sidebar-dot"></i>
                                        <?= e($child['label']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <a href="<?= APP_URL ?>/<?= $item['url'] ?>"
                           class="sidebar-link<?= $activeClass ?>">
                            <i class="<?= $item['icon'] ?> sidebar-icon"></i>
                            <span><?= e($item['label']) ?></span>
                            <?php if (!empty($item['badge'])): ?>
                                <span class="sidebar-badge sidebar-badge-<?= $item['badge'] === 'Soon' ? 'soon' : 'off' ?>">
                                    <?= e($item['badge']) ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <a href="<?= APP_URL ?>/logout"
           class="sidebar-link sidebar-logout mb-3"
           onclick="return confirm('Logout of your account?')">
            <i class="fas fa-sign-out-alt sidebar-icon"></i>
            <span>Logout</span>
        </a>
        <div class="small text-muted">&copy; <?= date('Y') ?> <?= e($siteName) ?></div>
    </div>
</aside>
