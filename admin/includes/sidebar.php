<?php
/**
 * OURCR ONLINE - Admin Sidebar
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

$adminUser = currentUser();
$siteName  = setting('site_name', APP_NAME);
$currentAdminPage = $currentAdminPage ?? '';

$menuGroups = [
    'Main' => [
        ['slug' => 'dashboard',     'label' => 'Dashboard',         'icon' => 'fas fa-tachometer-alt',  'url' => 'admin/dashboard'],
        ['slug' => 'income_wallet', 'label' => 'Income Wallet',     'icon' => 'fas fa-wallet',          'url' => 'admin/income-wallet'],
    ],
    'Users' => [
        ['slug' => 'users',         'label' => 'All Users',          'icon' => 'fas fa-users',            'url' => 'admin/users'],
        ['slug' => 'admins',        'label' => 'Manage Admins',      'icon' => 'fas fa-user-shield',      'url' => 'admin/admins', 'superadmin_only' => true],
    ],
    'Finance' => [
        ['slug' => 'wallets',       'label' => 'Wallets',            'icon' => 'fas fa-wallet',           'url' => 'admin/wallets'],
        ['slug' => 'deposits',      'label' => 'Deposits',           'icon' => 'fas fa-arrow-down-to-arc','url' => 'admin/deposits'],
        ['slug' => 'withdrawals',   'label' => 'Withdrawals',        'icon' => 'fas fa-money-bill-wave',  'url' => 'admin/withdrawals'],
        ['slug' => 'transactions',  'label' => 'Transactions',       'icon' => 'fas fa-exchange-alt',     'url' => 'admin/transactions'],
    ],
    'Integrations' => [
        ['slug' => 'vtpass_logs',   'label' => 'VTpass Logs',        'icon' => 'fas fa-plug',             'url' => 'admin/vtpass-logs'],
        ['slug' => 'gaps_logs',     'label' => 'GAPS Logs',          'icon' => 'fas fa-bank',             'url' => 'admin/gaps-logs'],
    ],
    'Reports' => [
        ['slug' => 'revenue',       'label' => 'Revenue',            'icon' => 'fas fa-chart-line',       'url' => 'admin/revenue'],
        ['slug' => 'reports',       'label' => 'Reports',            'icon' => 'fas fa-file-chart-column','url' => 'admin/reports'],
    ],
    'Support' => [
        ['slug' => 'support',       'label' => 'Support Tickets',    'icon' => 'fas fa-headset',          'url' => 'admin/support'],
        ['slug' => 'announcements', 'label' => 'Announcements',      'icon' => 'fas fa-bullhorn',         'url' => 'admin/announcements'],
        ['slug' => 'coupons',       'label' => 'Coupons',            'icon' => 'fas fa-tag',              'url' => 'admin/coupons'],
        ['slug' => 'referrals',     'label' => 'Referral Settings',  'icon' => 'fas fa-user-plus',        'url' => 'admin/referrals'],
    ],
    'System' => [
        ['slug' => 'settings',      'label' => 'Site Settings',      'icon' => 'fas fa-sliders',          'url' => 'admin/settings'],
        ['slug' => 'api_config',    'label' => 'API Config',         'icon' => 'fas fa-key',              'url' => 'admin/api-config'],
        ['slug' => 'audit_logs',    'label' => 'Audit Logs',         'icon' => 'fas fa-shield-check',     'url' => 'admin/audit-logs'],
        ['slug' => 'system_logs',   'label' => 'System Logs',        'icon' => 'fas fa-terminal',         'url' => 'admin/system-logs'],
        ['slug' => 'cron_status',   'label' => 'Cron Status',        'icon' => 'fas fa-clock',            'url' => 'admin/cron-status'],
        ['slug' => 'backups',       'label' => 'Backups',            'icon' => 'fas fa-database',         'url' => 'admin/backups'],
    ],
];
?>
<!-- Admin Sidebar Overlay -->
<div class="admin-sidebar-overlay" id="adminSidebarOverlay"></div>

<!-- Admin Sidebar -->
<aside class="admin-sidebar" id="adminSidebar">
    <!-- Logo -->
    <div class="admin-sidebar-header">
        <a href="<?= APP_URL ?>/admin/dashboard" class="admin-sidebar-logo">
            <img src="<?= APP_URL ?>/assets/images/logo-white.png"
                 alt="<?= e($siteName) ?>"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
            <span style="display:none;font-weight:800;color:#fff;"><?= e($siteName) ?></span>
        </a>
        <span class="admin-badge">Admin</span>
    </div>

    <!-- Nav -->
    <nav class="admin-sidebar-nav">
        <?php foreach ($menuGroups as $groupLabel => $items): ?>
            <div class="admin-nav-group">
                <div class="admin-nav-group-label"><?= $groupLabel ?></div>
                <?php foreach ($items as $item): ?>
                    <?php
                        // Hide superadmin-only items from regular admins
                        if (!empty($item['superadmin_only']) && !hasRole('superadmin')) continue;
                        $active = ($currentAdminPage === $item['slug']) ? ' active' : '';
                    ?>
                    <a href="<?= APP_URL ?>/<?= $item['url'] ?>" class="admin-nav-link<?= $active ?>">
                        <i class="<?= $item['icon'] ?> admin-nav-icon"></i>
                        <span><?= e($item['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <div class="admin-nav-group">
            <a href="<?= APP_URL ?>/dashboard" class="admin-nav-link" target="_blank">
                <i class="fas fa-external-link-alt admin-nav-icon"></i>
                <span>View Site</span>
            </a>
        </div>
    </nav>

    <!-- Sidebar Footer -->
    <div class="admin-sidebar-footer" style="padding: 16px; border-top: 1px solid rgba(255,255,255,0.08); margin-top: auto;">
        <div class="admin-sidebar-user mb-3" style="display: flex; flex-direction: column; gap: 4px; padding: 12px; background: rgba(255,255,255,0.05); border-radius: 12px;">
            <div class="admin-sidebar-user-name" style="font-weight: 600; color: #fff; font-size: 14px;"><?= e($adminUser['first_name'] ?? '') ?> <?= e($adminUser['last_name'] ?? '') ?></div>
            <div class="admin-sidebar-user-role" style="font-size: 12px; color: rgba(255,255,255,0.6);"><?= ucfirst($adminUser['role'] ?? '') ?></div>
        </div>
        <a href="<?= APP_URL ?>/admin/logout" class="admin-nav-link admin-nav-logout"
           onclick="return confirm('Logout?')"
           style="display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 8px; color: #ffffff !important; text-decoration: none; font-size: 14px; font-weight: 500; transition: all 0.2s; background: rgba(255,255,255,0.08);">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>
