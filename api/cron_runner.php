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
    $secret  = (string)($_GET['secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '');
    $isAdmin = isset($_SESSION['user']) && in_array($_SESSION['user']['role'] ?? '', ['admin', 'superadmin'], true);

    $secretValid = !empty(CRON_SECRET) && !empty($secret) && hash_equals(CRON_SECRET, $secret);

    if (!$secretValid && !$isAdmin && APP_ENV !== 'development') {
        http_response_code(403);
        die('Access Forbidden: Invalid cron secret key.');
    }
}

// Log execution starting
writeLog('cron', 'info', 'Cron runner started execution.');

$job = $_GET['job'] ?? '';
$failedJobs = [];

try {
    if ($job === 'gaps_statement_fetch') {
        if (!runGapsStatementFetch()) {
            $failedJobs[] = 'gaps_statement_fetch';
        }
    } elseif ($job === 'deposit_reconciliation') {
        if (!runDepositReconciliation()) {
            $failedJobs[] = 'deposit_reconciliation';
        }
    } elseif ($job === 'vtpass_retry') {
        if (!runVtpassRetry()) {
            $failedJobs[] = 'vtpass_retry';
        }
    } elseif ($job === 'vtpass_auto_topup') {
        if (!checkVtpassAutoTopup()) {
            $failedJobs[] = 'vtpass_auto_topup';
        }
    } elseif ($job === 'log_cleanup') {
        if (!runLogCleanup()) {
            $failedJobs[] = 'log_cleanup';
        }
    } else {
        // Run all jobs sequentially
        if (!runGapsStatementFetch()) {
            $failedJobs[] = 'gaps_statement_fetch';
        }
        if (!runDepositReconciliation()) {
            $failedJobs[] = 'deposit_reconciliation';
        }
        if (!runVtpassRetry()) {
            $failedJobs[] = 'vtpass_retry';
        }
        if (!checkVtpassAutoTopup()) {
            $failedJobs[] = 'vtpass_auto_topup';
        }
        if (!runLogCleanup()) {
            $failedJobs[] = 'log_cleanup';
        }
    }

    if (!empty($failedJobs)) {
        throw new Exception('One or more cron jobs failed: ' . implode(', ', $failedJobs));
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
function runGapsStatementFetch(): bool
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
            return true;
        } else {
            throw new Exception($result['message']);
        }
    } catch (Exception $e) {
        Database::execute("UPDATE cron_jobs SET status = 'failed', last_output = ? WHERE name = 'gaps_statement_fetch'", [$e->getMessage()]);
        writeLog('cron', 'error', "gaps_statement_fetch failed: " . $e->getMessage());
        return false;
    }
}

// ─── Automatic Deposit Intent Reconciliation Job ─────────────────────────────
function runDepositReconciliation(): bool
{
    writeLog('cron', 'info', 'Running Job: deposit_reconciliation');
    Database::execute("UPDATE cron_jobs SET last_run_at = NOW(), status = 'running' WHERE name = 'deposit_reconciliation'");

    try {
        // 1. Automatically cancel pending deposit intents older than 1 hour (or past expires_at)
        Database::execute(
            "UPDATE deposit_intents 
             SET status = 'cancelled', updated_at = NOW() 
             WHERE status = 'pending' 
               AND (expires_at <= NOW() OR created_at < NOW() - INTERVAL 1 HOUR)"
        );

        // Fetch all unmatched credit transactions
        $unmatched = Database::fetchAll("SELECT * FROM gaps_transactions WHERE transaction_type = 'credit' AND matched = 0");
        $matches = 0;

        foreach ($unmatched as $credit) {
            $amount    = (float)$credit['amount'];
            $sender    = trim(strtolower($credit['sender_name']));
            $narration = trim($credit['narration']);
            
            // Search for pending intents matching the exact amount:
            // Bank transaction MUST have occurred between (created_at - 15 minutes) and (created_at + 1 hour).
            // Old historical bank credits or transfers arriving after 1 hour are strictly blocked.
            $pendingIntents = Database::fetchAll(
                "SELECT * FROM deposit_intents 
                 WHERE status = 'pending' 
                   AND amount = ? 
                   AND expires_at > NOW() 
                   AND ? BETWEEN DATE_SUB(created_at, INTERVAL 15 MINUTE) AND DATE_ADD(created_at, INTERVAL 1 HOUR)
                 ORDER BY created_at ASC",
                [$amount, $credit['transaction_date']]
            );

            $matchedIntent = null;
            if (!empty($pendingIntents)) {
                // Enforce strict smart name matching (word overlap / flipped name order)
                foreach ($pendingIntents as $pIntent) {
                    if (isSenderNameMatched($pIntent['sender_name'], $narration, $sender)) {
                        $matchedIntent = $pIntent;
                        break;
                    }
                }
            }

            $intent = $matchedIntent;

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
        return true;
    } catch (Exception $e) {
        Database::execute("UPDATE cron_jobs SET status = 'failed', last_output = ? WHERE name = 'deposit_reconciliation'", [$e->getMessage()]);
        writeLog('cron', 'error', "deposit_reconciliation failed: " . $e->getMessage());
        return false;
    }
}

// ─── VTpass Retry Job ────────────────────────────────────────────────────────
function runVtpassRetry(): bool
{
    writeLog('cron', 'info', 'Running Job: vtpass_retry');
    Database::execute("UPDATE cron_jobs SET last_run_at = NOW(), status = 'running' WHERE name = 'vtpass_retry'");

    try {
        $count = Ourcr\VTpass::retryFailed();
        Database::execute("UPDATE cron_jobs SET status = 'success', run_count = run_count + 1, last_output = ? WHERE name = 'vtpass_retry'", ["VTpass retries completed. Checked count: $count"]);
        return true;
    } catch (Exception $e) {
        Database::execute("UPDATE cron_jobs SET status = 'failed', last_output = ? WHERE name = 'vtpass_retry'", [$e->getMessage()]);
        writeLog('cron', 'error', "vtpass_retry failed: " . $e->getMessage());
        return false;
    }
}

// ─── Log & Rate Limits Cleanup Job ──────────────────────────────────────────
function runLogCleanup(): bool
{
    writeLog('cron', 'info', 'Running Job: log_cleanup');
    Database::execute("UPDATE cron_jobs SET last_run_at = NOW(), status = 'running' WHERE name = 'log_cleanup'");

    try {
        // Clear rate limits entries older than 3 days
        $clearedLimits = Database::execute("DELETE FROM rate_limits WHERE last_attempt < NOW() - INTERVAL 3 DAY");
        
        // Remove read notifications older than 90 days
        $clearedNotifications = Database::execute("DELETE FROM notifications WHERE is_read = 1 AND read_at < NOW() - INTERVAL 90 DAY");

        Database::execute("UPDATE cron_jobs SET status = 'success', run_count = run_count + 1, last_output = ? WHERE name = 'log_cleanup'", ["Rate limits cleared: $clearedLimits, Notifications cleared: $clearedNotifications"]);
        return true;
    } catch (Exception $e) {
        Database::execute("UPDATE cron_jobs SET status = 'failed', last_output = ? WHERE name = 'log_cleanup'", [$e->getMessage()]);
        writeLog('cron', 'error', "log_cleanup failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Smart sender name matching helper (supports flipped name orders like "AYINNAH KINGSLEY" vs "Kingsley Ayinnah")
 */
function isSenderNameMatched(string $declaredName, string $narration, string $extractedSender = ''): bool
{
    $declaredLower  = strtolower(trim($declaredName));
    $narrationLower = strtolower($narration . ' ' . $extractedSender);

    if ($declaredLower === '' || $narrationLower === '') {
        return false;
    }

    // Direct substring check
    if (str_contains($narrationLower, $declaredLower) || (!empty($extractedSender) && str_contains($declaredLower, strtolower(trim($extractedSender))))) {
        return true;
    }

    // Tokenized word overlap check (ignoring generic banking stop words)
    $stopWords = ['bank', 'transfer', 'trf', 'nip', 'gtb', 'gtbank', 'from', 'to', 'credit', 'cr', 'dr', 'pos', 'ussd', 'fbn', 'uba', 'zenith', 'access', 'account', 'ourcr', 'online', 'wallet', 'funds', 'payment', 'pay'];
    $rawWords = preg_split('/[^a-z0-9]+/i', $declaredLower);
    $declaredWords = array_values(array_filter($rawWords, function($w) use ($stopWords) { 
        return strlen($w) >= 3 && !in_array($w, $stopWords, true); 
    }));

    if (empty($declaredWords)) {
        return false;
    }

    $matchedWords = 0;
    foreach ($declaredWords as $w) {
        if (str_contains($narrationLower, $w)) {
            $matchedWords++;
        }
    }

    $wordCount = count($declaredWords);
    if ($wordCount === 1) {
        return $matchedWords >= 1;
    }

    return ($matchedWords >= 2) || (($matchedWords / $wordCount) >= 0.5);
}

/**
 * Automated VTpass Float Auto-Topup Checker
 * Triggers GTBank GAPS payout to VTpass Moniepoint Account when balance drops below min threshold
 */
function checkVtpassAutoTopup(): bool
{
    // Ensure cron_jobs table has record for vtpass_auto_topup
    try {
        Database::execute("INSERT IGNORE INTO cron_jobs (name, status, run_count, created_at) VALUES ('vtpass_auto_topup', 'idle', 0, NOW())");
    } catch (\Throwable $e) {}

    $autoEnabled = (int)setting('vtpass_auto_topup_enabled', 1) === 1;
    if (!$autoEnabled) {
        Database::execute("UPDATE cron_jobs SET status = 'idle', last_output = 'Auto-topup disabled in settings.' WHERE name = 'vtpass_auto_topup'");
        return true;
    }

    Database::execute("UPDATE cron_jobs SET last_run_at = NOW(), status = 'running' WHERE name = 'vtpass_auto_topup'");

    try {
        $minThreshold = (float)setting('vtpass_min_threshold', 10000);
        $topupAmount  = (float)setting('vtpass_topup_amount', 40000);

        // Fetch live VTpass balance
        $balRes = Ourcr\VTpass::getLiveBalance();
        if (!$balRes['success']) {
            $msg = 'Could not fetch VTpass live balance: ' . ($balRes['message'] ?? 'API error');
            Database::execute("UPDATE cron_jobs SET status = 'failed', last_output = ? WHERE name = 'vtpass_auto_topup'", [$msg]);
            writeLog('cron', 'warning', "VTpass auto-topup check failed: {$msg}");
            return false;
        }

        $liveBalance = (float)($balRes['data']['balance'] ?? 0);

        if ($liveBalance < $minThreshold) {
            // Check if a topup was executed in the last 5 minutes to prevent duplicate payouts while funds reflect
            $recent = Database::fetchOne(
                "SELECT id FROM wallet_transactions 
                 WHERE category IN ('vtpass_topup', 'vtpass_topup_auto') 
                   AND status IN ('success', 'processing', 'pending')
                   AND created_at > NOW() - INTERVAL 5 MINUTE 
                 LIMIT 1"
            );

            if ($recent) {
                $msg = "Balance ₦" . number_format($liveBalance, 2) . " < ₦" . number_format($minThreshold, 2) . " threshold. Top-up executed in last 5m. Waiting to reflect.";
                Database::execute("UPDATE cron_jobs SET status = 'success', run_count = run_count + 1, last_output = ? WHERE name = 'vtpass_auto_topup'", [$msg]);
                writeLog('cron', 'info', $msg);
                return true;
            }

            $vtpassAccountNo   = setting('vtpass_account_number', '6920229746');
            $vtpassAccountName = setting('vtpass_account_name', 'Nsuhoreidem Nsuhoreidem');
            $vtpassBankName    = setting('vtpass_bank_name', 'Moniepoint Microfinance Bank');
            $cbnBankCode       = \Ourcr\GAPS\GAPS::getBankCodeByName($vtpassBankName) ?: '090405';

            $reference  = 'VT_AUTO_' . date('ymdHis') . '_' . rand(1000, 9999);
            $narration  = "AUTOMATED VTPASS FLOAT TOPUP TO " . strtoupper($vtpassAccountName);

            $depositFee     = 100.00; // Extra ₦100 to cover VTpass deposit charge
            $transferAmount = $topupAmount + $depositFee;

            writeLog('cron', 'info', "VTpass balance (₦{$liveBalance}) dropped below threshold (₦{$minThreshold}). Initiating GAPS auto-topup transfer of ₦{$transferAmount} (₦{$topupAmount} topup + ₦{$depositFee} VTpass deposit fee) to {$vtpassBankName} ({$vtpassAccountNo}). Ref: {$reference}...");
            writeLog('wallet', 'info', "VTpass auto-topup initiated: ₦{$transferAmount} to {$vtpassBankName} ({$vtpassAccountNo}) - Ref: {$reference}");

            $gapsRes = \Ourcr\GAPS\GAPS::processWithdrawal(
                accountNumber: $vtpassAccountNo,
                accountName:   $vtpassAccountName,
                bankCode:      $cbnBankCode,
                amount:        $transferAmount,
                reference:     $reference,
                narration:     $narration,
                bankName:      $vtpassBankName
            );

            $requeryRes = null;
            $isSuccess  = false;
            $finalMessage = $gapsRes['message'] ?? 'VTpass Auto-Topup Failed';
            $finalCode    = (string)($gapsRes['code'] ?? '');

            $isNetworkTimeout = in_array($finalCode, ['NETWORK_ERROR', 'TIMEOUT', 'CURL_ERROR', '0', '504', '502'], true) 
                || str_contains(strtolower($finalMessage), 'timed out') 
                || str_contains(strtolower($finalMessage), 'curl error');

            if (!empty($gapsRes['success']) || $isNetworkTimeout || $finalCode === '1010') {
                if ($isNetworkTimeout || $finalCode === '1010') {
                    sleep(3);
                }
                // Requery transaction status immediately to verify actual payout
                $requeryRes = \Ourcr\GAPS\GAPS::requeryTransaction($reference);
                $isSuccess    = !empty($requeryRes['success']);
                $finalMessage = $requeryRes['message'] ?? $finalMessage;
                $finalCode    = (string)($requeryRes['code'] ?? $finalCode);

                // If first requery returned 1010 (in-flight), wait 3s and try a 2nd confirmation
                if (!$isSuccess && $finalCode === '1010') {
                    sleep(3);
                    $requeryRes2 = \Ourcr\GAPS\GAPS::requeryTransaction($reference);
                    if (!empty($requeryRes2['success'])) {
                        $requeryRes   = $requeryRes2;
                        $isSuccess    = true;
                        $finalMessage = $requeryRes2['message'] ?? $finalMessage;
                        $finalCode    = (string)($requeryRes2['code'] ?? '1000');
                    }
                }
            } else {
                $isSuccess = false;
            }

            $metaData     = [
                'single_transfer' => $gapsRes['data'] ?? [],
                'requery'         => $requeryRes['data'] ?? [],
                'code'            => $finalCode,
                'message'         => $finalMessage,
                'base_topup'      => $topupAmount,
                'vtpass_fee'      => $depositFee,
            ];

            if ($isSuccess) {
                Database::insert(
                    "INSERT INTO wallet_transactions (uuid, user_id, amount, fee, balance_before, balance_after, type, category, status, reference, description, meta, created_at)
                     VALUES (?, 1, ?, ?, 0, 0, 'debit', 'vtpass_topup_auto', 'success', ?, ?, ?, NOW())",
                    [
                        generateUUID(),
                        $transferAmount,
                        $depositFee,
                        $reference,
                        "Automated VTpass float top-up via GTBank GAPS to {$vtpassBankName} ({$vtpassAccountNo}) - ₦" . number_format($topupAmount, 2) . " (+ ₦" . number_format($depositFee, 2) . " deposit fee) - Verified via Requery",
                        json_encode($metaData)
                    ]
                );

                auditLog('VTPASS_AUTO_TOPUP', "Automated VTpass float top-up of ₦{$transferAmount} (₦{$topupAmount} + ₦{$depositFee} fee) executed via GAPS. Verified Ref: {$reference}", 'wallet_transactions', 0);
                writeLog('cron', 'info', "VTpass auto-topup of ₦{$transferAmount} (incl. ₦{$depositFee} fee) successfully verified and executed! Ref: {$reference}");
                Database::execute("UPDATE cron_jobs SET status = 'success', run_count = run_count + 1, last_output = ? WHERE name = 'vtpass_auto_topup'", ["Topup of ₦" . number_format($transferAmount, 2) . " executed via GAPS. Ref: {$reference}"]);
            } else {
                Database::insert(
                    "INSERT INTO wallet_transactions (uuid, user_id, amount, fee, balance_before, balance_after, type, category, status, reference, description, meta, created_at)
                     VALUES (?, 1, ?, ?, 0, 0, 'debit', 'vtpass_topup_auto', 'failed', ?, ?, ?, NOW())",
                    [
                        generateUUID(),
                        $transferAmount,
                        $depositFee,
                        $reference,
                        "Failed automated VTpass float top-up: {$finalMessage}" . ($finalCode ? " [Code: {$finalCode}]" : ""),
                        json_encode($metaData)
                    ]
                );
                writeLog('cron', 'error', "VTpass auto-topup GAPS transfer failed via Requery: {$finalMessage} (Code: {$finalCode})");
                Database::execute("UPDATE cron_jobs SET status = 'failed', last_output = ? WHERE name = 'vtpass_auto_topup'", ["GAPS Top-up failed: {$finalMessage} (Code: {$finalCode})"]);
            }
        } else {
            $healthyMsg = "VTpass balance (₦" . number_format($liveBalance, 2) . ") is healthy (>= ₦" . number_format($minThreshold, 2) . " threshold). No top-up needed.";
            Database::execute("UPDATE cron_jobs SET status = 'success', run_count = run_count + 1, last_output = ? WHERE name = 'vtpass_auto_topup'", [$healthyMsg]);
        }
        return true;
    } catch (\Throwable $e) {
        Database::execute("UPDATE cron_jobs SET status = 'failed', last_output = ? WHERE name = 'vtpass_auto_topup'", [$e->getMessage()]);
        writeLog('cron', 'error', "VTpass auto-topup exception: " . $e->getMessage());
        return false;
    }
}
