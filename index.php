<?php
/**
 * OURCR ONLINE - Front Controller
 * All user-facing requests are routed through here via .htaccess
 */

// Start output buffering immediately — captures any stray PHP output
// (warnings/notices) before headers are sent, preventing header corruption
ob_start();

// Bootstrap the application
require_once __DIR__ . '/config/config.php';

// Load routes
require_once CONFIG_PATH . '/routes.php';

// Load pagination helper
require_once INCLUDES_PATH . '/pagination.php';
require_once INCLUDES_PATH . '/alerts.php';
require_once INCLUDES_PATH . '/banks.php';

// ─── Get requested page ───────────────────────────────────────────────────────
$page = '';

if (!empty($_SERVER['REQUEST_URI'])) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $requestPath = trim($requestPath, '/');

    $basePath = trim((string) parse_url(APP_URL, PHP_URL_PATH), '/');
    if ($basePath !== '') {
        $basePrefix = $basePath . '/';
        if (str_starts_with($requestPath, $basePrefix)) {
            $requestPath = substr($requestPath, strlen($basePrefix));
        } elseif ($requestPath === $basePath) {
            $requestPath = '';
        }
    }

    $requestPath = trim($requestPath, '/');

    if ($requestPath !== '') {
        $segments = explode('/', $requestPath);
        $page = strtolower(preg_replace('/[^a-z0-9_-]/', '', $segments[0]));
    }
}

if ($page === '' && isset($_GET['page']) && $_GET['page'] !== '') {
    $page = strtolower(trim($_GET['page']));
}

// Strip non-alphanumeric/dash chars for safety
$page = preg_replace('/[^a-z0-9_-]/', '', $page);

// ─── Resolve route ───────────────────────────────────────────────────────────
$route = resolveRoute($page);

// ─── Check maintenance mode ──────────────────────────────────────────────────
if (setting('maintenance_mode', '0') === '1' && $route['slug'] !== 'login') {
    // Allow admins through
    if (!isLoggedIn() || !hasRole('admin', 'superadmin')) {
        http_response_code(503);
        include PAGES_PATH . '/maintenance.php';
        exit;
    }
}

// ─── Authentication Gate ─────────────────────────────────────────────────────
if ($route['protected']) {
    requireAuth();
}

// ─── Redirect logged-in users away from auth pages ───────────────────────────
if (in_array($route['slug'], ['login', 'register', 'forgot_password', 'reset_password', 'verify_otp'], true)) {
    if (isLoggedIn()) {
        redirectTo('dashboard');
    }
}

// ─── Load and render the page ─────────────────────────────────────────────────
$pageFile = $route['file'];

if (!file_exists($pageFile)) {
    http_response_code(404);
    $pageFile = PAGES_PATH . '/404.php';
}

// Pass the current page slug for sidebar active state
$currentPage = $route['slug'];

include $pageFile;
