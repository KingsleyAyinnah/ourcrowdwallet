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
require_once INCLUDES_PATH . '/banks.php';

// Admin route map
$adminRoutes = [
    ''              => 'dashboard',
    'dashboard'     => 'dashboard',
    'income-wallet' => 'income_wallet',
    'admin-payouts' => 'admin_payouts',
    'users'         => 'users',
    'admins'        => 'admins',
    'user-view'     => 'user_view',
    'wallets'       => 'wallets',
    'deposits'      => 'deposits',
    'withdrawals'   => 'withdrawals',
    'transactions'  => 'transactions',
    'vtpass-logs'   => 'vtpass_logs',
    'vtpass-balance'=> 'vtpass_balance',
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

// Resolve admin route safely (prevent pagination ?page=2 from overwriting the route)
$routeKey = '';
$action   = isset($_GET['action']) ? strtolower(preg_replace('/[^a-z0-9_-]/', '', (string)$_GET['action'])) : '';

if (!empty($_GET['admin_route'])) {
    $routeKey = strtolower(preg_replace('/[^a-z0-9_-]/', '', (string)$_GET['admin_route']));
}

if ($routeKey === '' && !empty($_SERVER['REQUEST_URI'])) {
    $reqPath  = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $segments = explode('/', $reqPath);
    $adminPos = array_search('admin', $segments, true);
    if ($adminPos !== false && isset($segments[$adminPos + 1]) && $segments[$adminPos + 1] !== '') {
        $routeKey = strtolower(preg_replace('/[^a-z0-9_-]/', '', $segments[$adminPos + 1]));
        if (empty($action) && isset($segments[$adminPos + 2]) && $segments[$adminPos + 2] !== '') {
            $action = strtolower(preg_replace('/[^a-z0-9_-]/', '', $segments[$adminPos + 2]));
        }
    }
}

// Fallback to $_GET['page'] ONLY if it is a non-numeric route string (e.g. ?page=users)
if ($routeKey === '' && isset($_GET['page']) && !is_numeric($_GET['page'])) {
    $routeKey = strtolower(preg_replace('/[^a-z0-9_-]/', '', (string)$_GET['page']));
}

$page     = $routeKey;
$slug     = $adminRoutes[$page] ?? '404';
$pageFile = ADMIN_PATH . '/pages/' . $slug . '.php';

// Public admin pages (no auth required)
$publicAdminPages = ['login'];

if (!in_array($slug, $publicAdminPages, true)) {
    requireAdmin();

    // Support role routing restrictions
    if (hasRole('support') && !in_array($slug, ['support', 'logout'], true)) {
        setFlash('error', 'Access Restricted: Support role can only access the Support portal.');
        redirectTo('admin/support');
    }
}

// Redirect logged-in admins away from admin login page
if ($slug === 'login' && isLoggedIn()) {
    if (hasRole('support')) {
        redirectTo('admin/support');
    } elseif (hasRole('admin', 'superadmin')) {
        redirectTo('admin/dashboard');
    }
}

if (!file_exists($pageFile)) {
    $pageFile = ADMIN_PATH . '/pages/404.php';
    http_response_code(404);
}

$currentAdminPage = $slug;
include $pageFile;
