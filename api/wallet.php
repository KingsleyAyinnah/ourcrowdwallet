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

try {
    switch ($action) {
        case 'verify_recipient':
            if (!isPost()) {
                jsonResponse(['success' => false, 'message' => 'Invalid method.'], 405);
            }
            
            requireCsrf();
            $recipient = sanitizeString(post('recipient'));

            if (empty($recipient)) {
                jsonResponse(['success' => false, 'message' => 'Recipient username or email is required.'], 400);
            }

            // Locate recipient user
            $user = Database::fetchOne(
                "SELECT id, username, first_name, last_name FROM users WHERE (username = ? OR email = ?) AND deleted_at IS NULL",
                [$recipient, $recipient]
            );

            if (!$user) {
                jsonResponse(['success' => false, 'message' => 'Recipient user not found.'], 404);
            }

            if ((int)$user['id'] === (int)currentUserId()) {
                jsonResponse(['success' => false, 'message' => 'You cannot transfer funds to yourself.'], 400);
            }

            jsonResponse([
                'success' => true,
                'username' => $user['username'],
                'full_name' => $user['first_name'] . ' ' . $user['last_name']
            ]);
            break;

        case 'resolve_account':
            if (!isPost()) {
                jsonResponse(['success' => false, 'message' => 'Invalid method.'], 405);
            }
            
            requireCsrf();
            $accountNo = sanitizeString(post('account_number'));
            $bankName  = sanitizeString(post('bank_name'));

            if (empty($accountNo) || strlen($accountNo) !== 10) {
                jsonResponse(['success' => false, 'message' => 'Please enter a valid 10-digit account number.'], 400);
            }
            if (empty($bankName)) {
                jsonResponse(['success' => false, 'message' => 'Please select a recipient bank.'], 400);
            }

            $res = \Ourcr\GAPS\GAPS::resolveAccountName($accountNo, $bankName);
            if ($res['success']) {
                jsonResponse([
                    'success' => true,
                    'account_name' => $res['account_name']
                ]);
            } else {
                jsonResponse([
                    'success' => false,
                    'message' => $res['message']
                ]);
            }
            break;

        case 'check_balance':
            $balance = getWalletBalance(currentUserId());
            jsonResponse([
                'success' => true,
                'balance' => $balance,
                'formatted' => formatMoney($balance)
            ]);
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Action not found.'], 404);
            break;
    }

} catch (Throwable $e) {
    writeLog(LOG_CHAN_ERROR, 'error', 'Wallet API error: ' . $e->getMessage(), [
        'file' => $e->getFile(), 'line' => $e->getLine()
    ]);
    ob_clean();
    jsonResponse(['success' => false, 'message' => 'An error occurred while processing.'], 500);
}
