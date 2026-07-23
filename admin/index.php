<?php
/**
 * OURCR ONLINE - Admin Front Controller
 * All /admin/* requests are routed here via .htaccess
 */

// Start output buffering immediately to prevent header pollution
ob_start();


require_once dirname(__DIR__) . '/config/config.php';
require_once INCLUDES_PATH . '/pagination.php';
require_once INCLUDES_PATH . '/alerts.php';

// Admin route map
$adminRoutes = [
    ''              => 'dashboard',
    'dashboard'     => 'dashboard',
    'income-wallet' => 'income_wallet',
    'users'         => 'users',
    'admins'        => 'admins',
    'user-view'     => 'user_view',
    'wallets'       => 'wallets',
    'deposits'      => 'deposits',
    'withdrawals'   => 'withdrawals',
    'transactions'  => 'transactions',
    'vtpass-logs'   => 'vtpass_logs',
    'gaps-logs'     => 'gaps_logs',
    'revenue'       => 'revenue',
    'reports'       => 'reports',
    'settings'      => 'settings',
    'api-config'    => 'api_config',
    'audit-logs'    => 'audit_logs',
    'system-logs'   => 'system_logs',
    'announcements' => 'announcements',
    'coupons'       => 'coupons',
    'referrals'     => 'referrals',
    'cron-status'   => 'cron_status',
    'backups'       => 'backups',
    'support'       => 'support',
    'login'         => 'login',
    'logout'        => 'logout',
];

$page   = isset($_GET['page']) ? strtolower(preg_replace('/[^a-z0-9_-]/', '', $_GET['page'])) : '';
$action = isset($_GET['action']) ? strtolower(preg_replace('/[^a-z0-9_-]/', '', $_GET['action'])) : '';

$slug     = $adminRoutes[$page] ?? '404';
$pageFile = ADMIN_PATH . '/pages/' . $slug . '.php';

// Public admin pages (no auth required)
$publicAdminPages = ['login'];

if (!in_array($slug, $publicAdminPages, true)) {
    requireAdmin();
}

// Redirect logged-in admins away from admin login
if ($slug === 'login' && isLoggedIn() && hasRole('admin', 'superadmin')) {
    redirectTo('admin/dashboard');
}

if (!file_exists($pageFile)) {
    $pageFile = ADMIN_PATH . '/pages/404.php';
    http_response_code(404);
}

$currentAdminPage = $slug;
include $pageFile;
