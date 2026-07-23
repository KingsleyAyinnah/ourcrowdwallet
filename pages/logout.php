<?php
/**
 * OURCR ONLINE - Logout Handler
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

logoutUser();
setFlash('success', 'You have been logged out successfully.');
redirectTo('login');
