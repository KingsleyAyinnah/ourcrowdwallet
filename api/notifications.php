<?php
// Start output buffering immediately to capture stray warnings
ob_start();

define('OURCR_ONLINE', true);
require_once dirname(__DIR__) . '/config/config.php';

// Discard warnings
ob_clean();

header('Content-Type: application/json; charset=UTF-8');

// Check authentication
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized access.'], 401);
}

$action = get('action');
$userId = currentUserId();

try {
    switch ($action) {
        case 'count':
            $count = getUnreadNotificationCount($userId);
            jsonResponse(['success' => true, 'count' => $count]);
            break;

        case 'mark_read':
            if (!isPost()) {
                jsonResponse(['success' => false, 'message' => 'Invalid method.'], 405);
            }
            
            requireCsrf();
            $id = get('id') ? (int)get('id') : null;
            
            Ourcr\Notification::markRead($userId, $id);
            jsonResponse(['success' => true, 'message' => 'Notification status updated.']);
            break;

        case 'latest':
            $notifications = Ourcr\Notification::getForUser($userId, true, 5);
            jsonResponse(['success' => true, 'notifications' => $notifications]);
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Action not found.'], 404);
            break;
    }

} catch (Throwable $e) {
    writeLog(LOG_CHAN_ERROR, 'error', 'Notifications API error: ' . $e->getMessage(), [
        'file' => $e->getFile(), 'line' => $e->getLine()
    ]);
    ob_clean();
    jsonResponse(['success' => false, 'message' => 'An error occurred while processing.'], 500);
}
