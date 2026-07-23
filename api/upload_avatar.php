<?php
// Start output buffering immediately to capture stray warnings
ob_start();

define('OURCR_ONLINE', true);
require_once dirname(__DIR__) . '/config/config.php';

// Discard warnings
ob_clean();

header('Content-Type: application/json; charset=UTF-8');

// Must be authenticated
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized access.'], 401);
}

// Must be a POST with a file
if (!isPost()) {
    jsonResponse(['success' => false, 'message' => 'Invalid request method.'], 405);
}

try {
    requireCsrf();

    if (empty($_FILES['avatar']['name'])) {
        jsonResponse(['success' => false, 'message' => 'No file selected. Please choose an image.'], 400);
    }

    $userId = currentUserId();
    $result = Ourcr\User::uploadAvatar($userId, $_FILES['avatar']);

    if (!$result['success']) {
        jsonResponse(['success' => false, 'message' => $result['error']], 422);
    }

    $avatarUrl = AVATAR_URL . rawurlencode($result['filename']) . '?v=' . time();

    jsonResponse([
        'success'    => true,
        'avatar_url' => $avatarUrl,
        'message'    => 'Profile picture updated successfully.',
    ]);

} catch (Throwable $e) {
    writeLog(LOG_CHAN_ERROR, 'error', 'Avatar upload API error: ' . $e->getMessage(), [
        'file' => $e->getFile(), 'line' => $e->getLine()
    ]);
    ob_clean();
    jsonResponse(['success' => false, 'message' => 'An error occurred while uploading picture.'], 500);
}
