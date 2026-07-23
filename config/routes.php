<?php
/**
 * OURCR ONLINE - Route Definitions
 * Maps URL slugs to page files.
 * All routes are handled by index.php via .htaccess rewrite.
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

/**
 * Public routes (no authentication required)
 */
define('PUBLIC_ROUTES', [
    ''              => 'home',
    'home'          => 'home',
    'login'         => 'login',
    'register'      => 'register',
    'forgot-password' => 'forgot_password',
    'reset-password'  => 'reset_password',
    'verify-email'    => 'verify_email',
    'verify-otp'      => 'verify_otp',
    'verify-phone'    => 'verify_phone',
    'about'         => 'about',
    'contact'       => 'contact',
    'privacy'       => 'privacy',
    'terms'         => 'terms',
    'faq'           => 'faq',
    'pricing'       => 'pricing',
]);

/**
 * Protected routes (authentication required)
 */
define('PROTECTED_ROUTES', [
    'dashboard'         => 'dashboard',
    'profile'           => 'profile',
    'wallet'            => 'wallet',
    'fund-wallet'       => 'fund_wallet',
    'deposit-intent'    => 'deposit_intent',
    'withdraw'          => 'withdraw',
    'transfer'          => 'transfer',
    'transactions'      => 'transactions',
    'buy-airtime'       => 'buy_airtime',
    'buy-data'          => 'buy_data',
    'cable-tv'          => 'cable_tv',
    'electricity'       => 'electricity',
    'betting'           => 'betting',
    'exam-pins'         => 'exam_pins',
    'referrals'         => 'referrals',
    'notifications'     => 'notifications',
    'settings'          => 'settings',
    'change-password'   => 'change_password',
    'change-pin'        => 'change_pin',
    'support'           => 'support',
    'contribution'      => 'contribution',
    'logout'            => 'logout',
]);

/**
 * Route resolver
 */
function resolveRoute(string $page): array
{
    $page = strtolower(trim($page, '/'));

    $publicRoutes    = PUBLIC_ROUTES;
    $protectedRoutes = PROTECTED_ROUTES;

    if (isset($publicRoutes[$page])) {
        return [
            'file'      => PAGES_PATH . '/' . $publicRoutes[$page] . '.php',
            'protected' => false,
            'slug'      => $publicRoutes[$page],
        ];
    }

    if (isset($protectedRoutes[$page])) {
        return [
            'file'      => PAGES_PATH . '/' . $protectedRoutes[$page] . '.php',
            'protected' => true,
            'slug'      => $protectedRoutes[$page],
        ];
    }

    return [
        'file'      => PAGES_PATH . '/404.php',
        'protected' => false,
        'slug'      => '404',
    ];
}
