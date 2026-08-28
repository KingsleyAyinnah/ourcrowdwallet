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

            // Rate limit account name resolution: 15 attempts per 10 minutes
            $resolveRl = rateLimit('user_' . currentUserId(), 'resolve_account', 15, 600);
            if (!$resolveRl['allowed']) {
                $wait = ceil($resolveRl['retry_after'] / 60);
                jsonResponse(['success' => false, 'message' => "Too many account lookups. Please wait {$wait} minute(s) before trying again."], 429);
            }

            $accountNo = sanitizeString(post('account_number'));
            $bankName  = sanitizeString(post('bank_name'));

            if (empty($accountNo) || strlen($accountNo) !== 10) {
                jsonResponse(['success' => false, 'message' => 'Please enter a valid 10-digit account number.'], 400);
            }
            if (empty($bankName)) {
                jsonResponse(['success' => false, 'message' => 'Please select a recipient bank.'], 400);
            }

            $res = \Ourcr\AccountResolver::resolveAccountName($accountNo, $bankName);
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

        case 'notify_deposit_sent':
            if (!isPost()) {
                jsonResponse(['success' => false, 'message' => 'Invalid method.'], 405);
            }
            requireCsrf();

            // Rate limit on-demand reconciliation notification: 3 attempts per 5 minutes
            $notifyRl = rateLimit('user_' . currentUserId(), 'notify_deposit_sent', 3, 300);
            if (!$notifyRl['allowed']) {
                $wait = ceil($notifyRl['retry_after'] / 60);
                jsonResponse(['success' => false, 'message' => "Reconciliation was recently requested. Please allow {$wait} minute(s) for settlement."], 429);
            }

            $pending = \Ourcr\Wallet::getPendingIntents(currentUserId());
            if (empty($pending)) {
                jsonResponse(['success' => false, 'message' => 'No pending deposit intent found.'], 400);
            }

            $baseUrl = rtrim(APP_URL, '/');
            $jobs = ['gaps_statement_fetch', 'deposit_reconciliation'];
            $results = [];
            $errors = [];

            foreach ($jobs as $job) {
                $url = $baseUrl . '/api/cron_runner.php?job=' . urlencode($job) . '&secret=' . urlencode(CRON_SECRET);
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
                    CURLOPT_SSL_VERIFYHOST => APP_ENV === 'production' ? 2 : 0,
                ]);
                $raw = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);

                if ($raw === false || $curlErr !== '') {
                    $errors[$job] = $curlErr ?: 'Failed to fetch cron endpoint.';
                    $results[$job] = ['success' => false, 'message' => $errors[$job]];
                    continue;
                }

                $payload = json_decode($raw, true);
                if (!is_array($payload)) {
                    $errors[$job] = 'Invalid cron response.';
                    $results[$job] = ['success' => false, 'message' => $raw];
                    continue;
                }

                $results[$job] = $payload;
                if (empty($payload['success'])) {
                    $errors[$job] = $payload['message'] ?? 'Cron job failed.';
                }
            }

            if (!empty($errors)) {
                jsonResponse([
                    'success' => false,
                    'message' => 'One or more reconciliation jobs failed. Please try again.',
                    'results' => $results
                ], 500);
            }

            jsonResponse([
                'success' => true,
                'message' => 'Notification sent. GAPS statement fetch and deposit reconciliation have been triggered.',
                'results' => $results
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
