<?php
/**
 * OURCR ONLINE - Background Cron Dispatcher
 * Triggered every minute via server task scheduler
 */

$isCli = php_sapi_name() === 'cli';
define('OURCR_ONLINE', true);
require_once dirname(__DIR__) . '/config/config.php';

// If not run via CLI, verify the cron secret key or admin session or dev mode
if (!$isCli) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $secret  = $_GET['secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
    $isAdmin = isset($_SESSION['user']) && in_array($_SESSION['user']['role'] ?? '', ['admin', 'superadmin'], true);

    if ($secret !== CRON_SECRET && !$isAdmin && APP_ENV !== 'development') {
        http_response_code(403);
        die('Access Forbidden: Invalid cron secret key.');
    }
}

// Log execution starting
writeLog('cron', 'info', 'Cron runner started execution.');

$job = $_GET['job'] ?? '';

try {
    if ($job === 'gaps_statement_fetch') {
        runGapsStatementFetch();
    } elseif ($job === 'deposit_reconciliation') {
        runDepositReconciliation();
    } elseif ($job === 'vtpass_retry') {
        runVtpassRetry();
    } elseif ($job === 'log_cleanup') {
        runLogCleanup();
    } else {
        // Run all jobs sequentially
        runGapsStatementFetch();
        runDepositReconciliation();
        runVtpassRetry();
        runLogCleanup();
    }

    writeLog('cron', 'info', 'Cron runner finished successfully.');
    if (!$isCli) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Cron tasks completed successfully.']);
    }

} catch (Throwable $e) {
    writeLog('cron', 'error', 'Cron failure exception: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
    if (!$isCli) {
        http_response_code(500);
        die('Cron execution failed.');
    }
}

// ─── GAPS Statement Fetch Job ────────────────────────────────────────────────
function runGapsStatementFetch(): void
{
    writeLog('cron', 'info', 'Running Job: gaps_statement_fetch');
    
    // Update Cron status in database
    Database::execute("UPDATE cron_jobs SET last_run_at = NOW(), status = 'running' WHERE name = 'gaps_statement_fetch'");

    try {
        $from = date('Y-m-d', strtotime('-1 day'));
        $to = date('Y-m-d');
        
        $result = \Ourcr\GAPS\GAPS::getStatement($from, $to);
        
        if ($result['success']) {
            $inserted = 0;
            foreach ($result['transactions'] ?? [] as $tx) {
                // Check if gaps transaction already stored
                $exists = Database::fetchOne(
                    "SELECT id FROM gaps_transactions WHERE gaps_reference = ? LIMIT 1",
                    [$tx['reference']]
                );
                
                if (!$exists) {
                    Database::insert(
                        "INSERT INTO gaps_transactions (gaps_reference, transaction_type, amount, sender_name, narration, transaction_date, balance_after, raw_data)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $tx['reference'],
                            $tx['type'],
                            $tx['amount'],
                            $tx['sender_name'],
                            $tx['narration'],
                            $tx['transaction_date'],
                            $tx['balance_after'],
                            json_encode($tx['raw'])
                        ]
                    );
                    $inserted++;
                }
            }
            Database::execute("UPDATE cron_jobs SET status = 'success', run_count = run_count + 1, last_output = ? WHERE name = 'gaps_statement_fetch'", ["Fetched statement. New transactions added: $inserted"]);
            writeLog('cron', 'info', "gaps_statement_fetch success: Fetched statement. New transactions added: $inserted");
        } else {
            throw new Exception($result['message']);
        }
    } catch (Exception $e) {
        Database::execute("UPDATE cron_jobs SET status = 'failed', last_output = ? WHERE name = 'gaps_statement_fetch'", [$e->getMessage()]);
        writeLog('cron', 'error', "gaps_statement_fetch failed: " . $e->getMessage());
    }
}

// ─── Automatic Deposit Intent Reconciliation Job ─────────────────────────────
function runDepositReconciliation(): void
{
    writeLog('cron', 'info', 'Running Job: deposit_reconciliation');
    Database::execute("UPDATE cron_jobs SET last_run_at = NOW(), status = 'running' WHERE name = 'deposit_reconciliation'");

    try {
        // Fetch all unmatched credit transactions
        $unmatched = Database::fetchAll("SELECT * FROM gaps_transactions WHERE transaction_type = 'credit' AND matched = 0");
        $matches = 0;

        foreach ($unmatched as $credit) {
            $amount = (float)$credit['amount'];
            $sender = trim(strtolower($credit['sender_name']));
            
            // Search for a matching pending intent (sender name, amount, within 24h expected time)
            $intent = Database::fetchOne(
                "SELECT * FROM deposit_intents 
                 WHERE status = 'pending' 
                   AND amount = ? 
                   AND expires_at > NOW() 
                   AND (LOWER(sender_name) LIKE ? OR ? LIKE CONCAT('%', LOWER(sender_name), '%'))
                 LIMIT 1",
                [$amount, "%$sender%", $sender]
            );

            if ($intent) {
                Database::beginTransaction();
                try {
                    $userId = (int)$intent['user_id'];
                    $intentId = (int)$intent['id'];

                    // Credit user's wallet (applying dynamic deposit match fees)
                    $depositPercentage = (float)setting('deposit_fee_percentage', 1.5);
                    $depositFlat = (float)setting('deposit_fee_flat', 0);
                    $fee = ($amount * ($depositPercentage / 100)) + $depositFlat;
                    $creditAmount = $amount - $fee;
                    if ($creditAmount < 0) {
                        $creditAmount = 0;
                    }

                    $desc = "Credit matching deposit reference " . $credit['gaps_reference'] . " (Fee: " . formatMoney($fee) . ")";
                    $txnId = creditWallet($userId, $creditAmount, TXN_TYPE_DEPOSIT, $desc, $credit['gaps_reference'], [], $fee);

                    // Update intent status to matched
                    Database::execute(
                        "UPDATE deposit_intents 
                         SET status = 'matched', gaps_reference = ?, wallet_txn_id = ?, matched_at = NOW() 
                         WHERE id = ?",
                        [$credit['gaps_reference'], $txnId, $intentId]
                    );

                    // Update gaps credit transaction
                    Database::execute(
                        "UPDATE gaps_transactions 
                         SET matched = 1, deposit_intent_id = ?, processed_at = NOW() 
                         WHERE id = ?",
                        [$intentId, $credit['id']]
                    );

                    // Notify user
                    sendNotification(
                        $userId,
                        NOTIF_SUCCESS,
                        'Deposit Credited!',
                        "Your bank transfer of " . formatMoney($amount) . " has been successfully matched and credited to your wallet balance.",
                        APP_URL . '/wallet'
                    );

                    Database::commit();
                    $matches++;
                    writeLog('cron', 'info', "Successfully matched intent ID $intentId with GAPS ref " . $credit['gaps_reference']);

                } catch (Exception $ex) {
                    Database::rollback();
                    writeLog('cron', 'error', "Transaction mismatch rollback for GAPS ref " . $credit['gaps_reference'] . ": " . $ex->getMessage());
                }
            }
        }

        Database::execute("UPDATE cron_jobs SET status = 'success', run_count = run_count + 1, last_output = ? WHERE name = 'deposit_reconciliation'", ["Completed matching. matched count: $matches"]);
    } catch (Exception $e) {
        Database::execute("UPDATE cron_jobs SET status = 'failed', last_output = ? WHERE name = 'deposit_reconciliation'", [$e->getMessage()]);
        writeLog('cron', 'error', "deposit_reconciliation failed: " . $e->getMessage());
    }
}

// ─── VTpass Retry Job ────────────────────────────────────────────────────────
function runVtpassRetry(): void
{
    writeLog('cron', 'info', 'Running Job: vtpass_retry');
    Database::execute("UPDATE cron_jobs SET last_run_at = NOW(), status = 'running' WHERE name = 'vtpass_retry'");

    try {
        $count = Ourcr\VTpass::retryFailed();
        Database::execute("UPDATE cron_jobs SET status = 'success', run_count = run_count + 1, last_output = ? WHERE name = 'vtpass_retry'", ["VTpass retries completed. Checked count: $count"]);
    } catch (Exception $e) {
        Database::execute("UPDATE cron_jobs SET status = 'failed', last_output = ? WHERE name = 'vtpass_retry'", [$e->getMessage()]);
        writeLog('cron', 'error', "vtpass_retry failed: " . $e->getMessage());
    }
}

// ─── Log & Rate Limits Cleanup Job ──────────────────────────────────────────
function runLogCleanup(): void
{
    writeLog('cron', 'info', 'Running Job: log_cleanup');
    Database::execute("UPDATE cron_jobs SET last_run_at = NOW(), status = 'running' WHERE name = 'log_cleanup'");

    try {
        // Clear rate limits entries older than 3 days
        $clearedLimits = Database::execute("DELETE FROM rate_limits WHERE last_attempt < NOW() - INTERVAL 3 DAY");
        
        // Remove read notifications older than 90 days
        $clearedNotifications = Database::execute("DELETE FROM notifications WHERE is_read = 1 AND read_at < NOW() - INTERVAL 90 DAY");

        Database::execute("UPDATE cron_jobs SET status = 'success', run_count = run_count + 1, last_output = ? WHERE name = 'log_cleanup'", ["Rate limits cleared: $clearedLimits, Notifications cleared: $clearedNotifications"]);
    } catch (Exception $e) {
        Database::execute("UPDATE cron_jobs SET status = 'failed', last_output = ? WHERE name = 'log_cleanup'", [$e->getMessage()]);
        writeLog('cron', 'error', "log_cleanup failed: " . $e->getMessage());
    }
}
