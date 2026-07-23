<?php
/**
 * OURCR ONLINE - Admin Panel Logout
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

if (isLoggedIn()) {
    auditLog('ADMIN_LOGOUT', "Logged out from admin panel", 'users', currentUserId());
    logoutUser();
}

setFlash('success', 'Logged out from admin panel.');
redirectTo('admin/login');
