<?php
namespace Ourcr;

/**
 * OURCR ONLINE - Wallet Service Class
 *
 * Handles all wallet operations: balance enquiry, credit, debit,
 * deposit intents, withdrawals, wallet-to-wallet transfers, and
 * transaction history retrieval.
 *
 * ALL balance-modifying methods (credit / debit) MUST be called inside
 * a Database::beginTransaction() / Database::commit() block managed by
 * the caller. The class does NOT start its own transactions so that
 * callers can compose multiple operations atomically.
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');



use RuntimeException;
use InvalidArgumentException;

class Wallet
{
    // ─── Balance ─────────────────────────────────────────────────────────────

    /**
     * Get user wallet balance fresh from DB (no cache).
     */
    public static function getBalance(int $userId): float
    {
        $row = \Database::fetchOne(
            'SELECT wallet_balance FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$userId]
        );

        if (!$row) {
            writeLog(LOG_CHAN_WALLET, 'warning', 'getBalance: user not found', ['user_id' => $userId]);
            return 0.00;
        }

        return (float) $row['wallet_balance'];
    }

    /**
     * Check if user has sufficient balance to cover amount + fee.
     */
    public static function hasSufficientBalance(int $userId, float $amount, float $fee = 0.0): bool
    {
        $balance = self::getBalance($userId);
        $total   = $amount + $fee;
        return $balance >= $total;
    }

    // ─── Credit ──────────────────────────────────────────────────────────────

    /**
     * Credit a user's wallet.
     *
     * MUST be called inside an active Database transaction.
     * Wraps the creditWallet() helper with extra validation and audit logging.
     *
     * @return int  wallet_transactions.id
     * @throws RuntimeException if amount is invalid or user not found
     */
    public static function credit(
        int    $userId,
        float  $amount,
        string $category,
        string $description,
        string $reference = '',
        array  $meta = [],
        float  $fee = 0.0
    ): int {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Credit amount must be a positive number.');
        }

        if (!in_array($category, self::validCategories(), true)) {
            writeLog(LOG_CHAN_WALLET, 'warning', 'credit: unknown category', [
                'user_id'  => $userId,
                'category' => $category,
            ]);
        }

        $reference = $reference ?: generateTxnRef('CR');

        $txnId = creditWallet(
            userId:      $userId,
            amount:      $amount,
            category:    $category,
            description: $description,
            reference:   $reference,
            meta:        $meta,
            fee:         $fee
        );

        writeLog(LOG_CHAN_WALLET, 'info', 'Wallet credited', [
            'user_id'   => $userId,
            'amount'    => $amount,
            'category'  => $category,
            'reference' => $reference,
            'txn_id'    => $txnId,
        ]);

        return $txnId;
    }

    // ─── Debit ───────────────────────────────────────────────────────────────

    /**
     * Debit a user's wallet.
     *
     * MUST be called inside an active Database transaction.
     * Wraps the debitWallet() helper with extra validation and audit logging.
     *
     * @return int  wallet_transactions.id
     * @throws RuntimeException if amount is invalid, user not found, or insufficient balance
     */
    public static function debit(
        int    $userId,
        float  $amount,
        float  $fee = 0.0,
        string $category = '',
        string $description = '',
        string $reference = '',
        array  $meta = []
    ): int {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Debit amount must be a positive number.');
        }

        $reference = $reference ?: generateTxnRef('DR');

        $txnId = debitWallet(
            userId:      $userId,
            amount:      $amount,
            fee:         $fee,
            category:    $category,
            description: $description,
            reference:   $reference,
            meta:        $meta
        );

        writeLog(LOG_CHAN_WALLET, 'info', 'Wallet debited', [
            'user_id'   => $userId,
            'amount'    => $amount,
            'fee'       => $fee,
            'category'  => $category,
            'reference' => $reference,
            'txn_id'    => $txnId,
        ]);

        return $txnId;
    }

    // ─── Deposit Intent ───────────────────────────────────────────────────────

    /**
     * Validate a deposit intent before creating it.
     *
     * @return array ['valid' => bool, 'error' => string]
     */
    public static function validateDepositIntent(float $amount, string $senderName): array
    {
        $senderName = trim($senderName);

        if ($amount < MIN_DEPOSIT) {
            return [
                'valid' => false,
                'error' => 'Minimum deposit amount is ' . formatMoney(MIN_DEPOSIT) . '.',
            ];
        }

        if ($amount > MAX_DEPOSIT) {
            return [
                'valid' => false,
                'error' => 'Maximum deposit amount is ' . formatMoney(MAX_DEPOSIT) . '.',
            ];
        }

        if (empty($senderName) || strlen($senderName) < 3) {
            return [
                'valid' => false,
                'error' => 'Sender name must be at least 3 characters.',
            ];
        }

        if (strlen($senderName) > 191) {
            return [
                'valid' => false,
                'error' => 'Sender name is too long.',
            ];
        }

        if (!preg_match('/^[\p{L}\p{N}\s\-\.\']+$/u', $senderName)) {
            return [
                'valid' => false,
                'error' => 'Sender name contains invalid characters.',
            ];
        }

        return ['valid' => true, 'error' => ''];
    }

    /**
     * Create a pending deposit intent.
     *
     * @return array ['success' => bool, 'intent_id' => int, 'error' => string]
     */
    public static function createDepositIntent(
        int    $userId,
        float  $amount,
        string $senderName,
        string $expectedAt
    ): array {
        $validation = self::validateDepositIntent($amount, $senderName);
        if (!$validation['valid']) {
            return ['success' => false, 'intent_id' => 0, 'error' => $validation['error']];
        }

        // Validate expectedAt timestamp
        $expectedTs = strtotime($expectedAt);
        if ($expectedTs === false || $expectedTs < time()) {
            return [
                'success'   => false,
                'intent_id' => 0,
                'error'     => 'Expected payment time must be in the future.',
            ];
        }

        // Check if deposit functionality is enabled
        if (!serviceEnabled('deposit')) {
            return [
                'success'   => false,
                'intent_id' => 0,
                'error'     => 'Deposit service is currently unavailable. Please try again later.',
            ];
        }

        // Limit pending intents per user (prevent spam)
        $pendingCount = \Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM deposit_intents
             WHERE user_id = ? AND status = 'pending' AND expires_at > NOW()",
            [$userId]
        );

        $maxPending = (int) setting('max_pending_intents', 3);
        if ((int) ($pendingCount['cnt'] ?? 0) >= $maxPending) {
            return [
                'success'   => false,
                'intent_id' => 0,
                'error'     => "You already have {$maxPending} pending deposit intent(s). Please wait for them to be matched or expired before creating a new one.",
            ];
        }

        try {
            $uuid      = generateUUID();
            $expiresAt = date('Y-m-d H:i:s', time() + DEPOSIT_INTENT_EXPIRY);

            $intentId = \Database::insert(
                'INSERT INTO deposit_intents
                 (uuid, user_id, amount, sender_name, expected_at, status, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $uuid,
                    $userId,
                    $amount,
                    trim($senderName),
                    date('Y-m-d H:i:s', $expectedTs),
                    DEPOSIT_PENDING,
                    $expiresAt,
                ]
            );

            writeLog(LOG_CHAN_WALLET, 'info', 'Deposit intent created', [
                'user_id'     => $userId,
                'intent_id'   => $intentId,
                'amount'      => $amount,
                'sender_name' => $senderName,
                'expires_at'  => $expiresAt,
            ]);

            sendNotification(
                $userId,
                NOTIF_INFO,
                'Deposit Intent Created',
                'Your deposit intent of ' . formatMoney($amount) . ' from "' . $senderName . '" has been registered. We will credit your wallet once we confirm receipt.',
                APP_URL . '/wallet/deposit'
            );

            return ['success' => true, 'intent_id' => (int) $intentId, 'error' => ''];
        } catch (\Exception $e) {
            writeLog(LOG_CHAN_WALLET, 'error', 'Failed to create deposit intent', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return ['success' => false, 'intent_id' => 0, 'error' => 'Failed to create deposit intent. Please try again.'];
        }
    }

    /**
     * Get all pending deposit intents for a user (not expired).
     */
    public static function getPendingIntents(int $userId): array
    {
        return \Database::fetchAll(
            "SELECT * FROM deposit_intents
             WHERE user_id = ? AND status = 'pending' AND expires_at > NOW()
             ORDER BY created_at DESC",
            [$userId]
        );
    }

    // ─── Withdrawal ──────────────────────────────────────────────────────────

    /**
     * Process a withdrawal request: validate PIN, check balance,
     * debit wallet, and create the withdrawal record.
     *
     * @return array ['success' => bool, 'message' => string, 'reference' => string]
     */
    public static function requestWithdrawal(
        int    $userId,
        float  $amount,
        string $bankName,
        string $accountNumber,
        string $accountName,
        string $transactionPin,
        string $remark = ''
    ): array {
        // ── Service check
        if (!serviceEnabled('withdrawal')) {
            return ['success' => false, 'message' => 'Withdrawal service is temporarily unavailable.', 'reference' => ''];
        }

        // ── Rate limit (5 requests per hour per user)
        $rlKey = 'user_' . $userId;
        $limit = rateLimit($rlKey, 'withdrawal', 5, 3600);
        if (!$limit['allowed']) {
            $wait = ceil($limit['retry_after'] / 60);
            return [
                'success'   => false,
                'message'   => "Too many withdrawal attempts. Please try again in {$wait} minute(s).",
                'reference' => '',
            ];
        }

        // ── Amount validation
        if ($amount < MIN_WITHDRAWAL) {
            return ['success' => false, 'message' => 'Minimum withdrawal amount is ' . formatMoney(MIN_WITHDRAWAL) . '.', 'reference' => ''];
        }

        if ($amount > MAX_WITHDRAWAL) {
            return ['success' => false, 'message' => 'Maximum withdrawal amount is ' . formatMoney(MAX_WITHDRAWAL) . '.', 'reference' => ''];
        }

        // ── Bank detail validation
        $bankName      = trim($bankName);
        $accountName   = trim($accountName);
        $accountNumber = preg_replace('/\D/', '', trim($accountNumber));
        $remark        = trim($remark);

        if (empty($bankName)) {
            return ['success' => false, 'message' => 'Bank name is required.', 'reference' => ''];
        }

        if (!preg_match('/^\d{10}$/', $accountNumber)) {
            return ['success' => false, 'message' => 'Account number must be exactly 10 digits.', 'reference' => ''];
        }

        if (strlen($accountName) < 3) {
            return ['success' => false, 'message' => 'Account name is required.', 'reference' => ''];
        }

        // ── PIN verification
        if (!self::verifyPin($userId, $transactionPin)) {
            return ['success' => false, 'message' => 'Invalid transaction PIN. Please try again.', 'reference' => ''];
        }

        $feePercent = (float) setting('withdrawal_fee', WITHDRAWAL_FEE);
        $fee        = round(($amount * $feePercent) / 100, 2);
        $totalCost = $amount + $fee;

        // ── Balance check
        if (!self::hasSufficientBalance($userId, $amount, $fee)) {
            $balance = self::getBalance($userId);
            return [
                'success'   => false,
                'message'   => 'Insufficient balance. You need ' . formatMoney($totalCost) . ' (including ' . formatMoney($fee) . ' fee) but your balance is ' . formatMoney($balance) . '.',
                'reference' => '',
            ];
        }

        $reference = generateTxnRef('WD');

        try {
            \Database::beginTransaction();

            // Debit the wallet
            $txnId = self::debit(
                userId:      $userId,
                amount:      $amount,
                fee:         $fee,
                category:    TXN_TYPE_WITHDRAWAL,
                description: 'Withdrawal to ' . $bankName . ' (' . $accountNumber . ')' . ($remark ? ' - Remark: ' . $remark : ''),
                reference:   $reference,
                meta:        [
                    'bank_name'      => $bankName,
                    'account_number' => $accountNumber,
                    'account_name'   => $accountName,
                    'remark'         => $remark,
                ]
            );

            // Create withdrawal record
            \Database::insert(
                'INSERT INTO withdrawals
                 (uuid, user_id, wallet_txn_id, amount, fee, bank_name, account_number, account_name, remark, status, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    generateUUID(),
                    $userId,
                    $txnId,
                    $amount,
                    $fee,
                    $bankName,
                    $accountNumber,
                    $accountName,
                    $remark ?: null,
                    'processing',
                    getClientIP(),
                ]
            );

            \Database::commit();

            writeLog(LOG_CHAN_WALLET, 'info', 'Withdrawal debit committed, attempting GAPS payout', [
                'user_id'   => $userId,
                'amount'    => $amount,
                'fee'       => $fee,
                'bank'      => $bankName,
                'account'   => $accountNumber,
                'reference' => $reference,
            ]);

            // Attempt automated transfer using GAPS withdrawal API client
            $bankCode = getBankCodeByName($bankName);
            if ($bankCode === '') {
                throw new \RuntimeException('Selected bank is currently unsupported for automated GAPS payouts.');
            }
            $gapsResult = \Ourcr\GAPS\GAPS::processWithdrawal(
                accountNumber: $accountNumber,
                accountName:   $accountName,
                bankCode:      $bankCode,
                amount:        $amount,
                reference:     $reference,
                narration:     'Automated withdrawal payout'
            );

            if ($gapsResult['success']) {
                \Database::execute(
                    "UPDATE withdrawals SET status = 'success', processed_at = NOW(), gaps_reference = ?, gaps_response = ? WHERE wallet_txn_id = ?",
                    [$gapsResult['reference'] ?? $reference, json_encode($gapsResult['data']), $txnId]
                );

                writeLog(LOG_CHAN_WALLET, 'info', 'Withdrawal payout successful via GAPS', [
                    'user_id'   => $userId,
                    'reference' => $reference,
                    'gaps_ref'  => $gapsResult['reference'] ?? $reference,
                ]);

                auditLog(
                    'withdrawal_processed_gaps',
                    'Withdrawal of ' . formatMoney($amount) . " to {$bankName} ({$accountNumber}) was paid out via GAPS.",
                    'withdrawal',
                    $userId,
                    ['amount' => $amount, 'fee' => $fee, 'reference' => $reference]
                );

                sendNotification(
                    $userId,
                    NOTIF_SUCCESS,
                    'Withdrawal Successful',
                    'Your withdrawal of ' . formatMoney($amount) . ' to ' . $bankName . ' (' . $accountNumber . ') was successfully paid out.',
                    APP_URL . '/wallet/transactions'
                );

                return [
                    'success'   => true,
                    'message'   => 'Your withdrawal of ' . formatMoney($amount) . ' was processed and paid out successfully.',
                    'reference' => $reference,
                ];
            } else {
                // If GAPS fails, set withdrawals status to failed, revert wallet transactions, and refund
                \Database::beginTransaction();
                try {
                    \Database::execute(
                        "UPDATE withdrawals SET status = 'failed', failure_reason = ?, gaps_response = ? WHERE wallet_txn_id = ?",
                        ['GAPS failed: ' . $gapsResult['message'], json_encode($gapsResult), $txnId]
                    );

                    \Database::execute(
                        "UPDATE wallet_transactions SET status = 'failed' WHERE id = ?",
                        [$txnId]
                    );

                    // Refund the user's wallet
                    $refundAmt = $amount + $fee;
                    self::credit(
                        userId:      $userId,
                        amount:      $refundAmt,
                        category:    TXN_TYPE_DEPOSIT,
                        description: 'Refund for failed withdrawal ' . $reference,
                        reference:   'RF-' . $reference,
                        meta:        ['original_reference' => $reference]
                    );

                    \Database::commit();

                    writeLog(LOG_CHAN_WALLET, 'error', 'Withdrawal payout failed via GAPS, refunded', [
                        'user_id'   => $userId,
                        'reference' => $reference,
                        'error'     => $gapsResult['message'],
                    ]);

                    auditLog(
                        'withdrawal_failed_gaps',
                        'Withdrawal of ' . formatMoney($amount) . " to {$bankName} ({$accountNumber}) failed: " . $gapsResult['message'] . ". Refunded.",
                        'withdrawal',
                        $userId,
                        ['amount' => $amount, 'fee' => $fee, 'reference' => $reference]
                    );

                    sendNotification(
                        $userId,
                        NOTIF_ERROR,
                        'Withdrawal Failed',
                        'Your withdrawal of ' . formatMoney($amount) . ' failed: ' . $gapsResult['message'] . '. Funds have been refunded to your wallet.',
                        APP_URL . '/wallet/transactions'
                    );

                    return [
                        'success'   => false,
                        'message'   => 'Withdrawal payout failed: ' . $gapsResult['message'] . '. Your wallet has been refunded.',
                        'reference' => $reference,
                    ];
                } catch (\Exception $ex2) {
                    \Database::rollback();
                    throw $ex2;
                }
            }
        } catch (\Exception $e) {
            \Database::rollback();
            writeLog(LOG_CHAN_WALLET, 'error', 'Withdrawal failed', [
                'user_id'   => $userId,
                'amount'    => $amount,
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);

            return [
                'success'   => false,
                'message'   => 'Withdrawal request failed. Please try again or contact support.',
                'reference' => '',
            ];
        }
    }

    // ─── Transfer ─────────────────────────────────────────────────────────────

    /**
     * Process a wallet-to-wallet transfer between users.
     *
     * @param string $recipientIdentifier  username or email of the recipient
     * @return array ['success' => bool, 'message' => string, 'reference' => string]
     */
    public static function transfer(
        int    $senderId,
        string $recipientIdentifier,
        float  $amount,
        string $description,
        string $transactionPin
    ): array {
        // ── Service check
        if (!serviceEnabled('transfer')) {
            return ['success' => false, 'message' => 'Transfer service is temporarily unavailable.', 'reference' => ''];
        }

        // ── Rate limit (10 transfers per hour per user)
        $rlKey = 'user_' . $senderId;
        $limit = rateLimit($rlKey, 'transfer', 10, 3600);
        if (!$limit['allowed']) {
            $wait = ceil($limit['retry_after'] / 60);
            return [
                'success'   => false,
                'message'   => "Too many transfer attempts. Please try again in {$wait} minute(s).",
                'reference' => '',
            ];
        }

        // ── Amount validation
        if ($amount < MIN_TRANSFER) {
            return ['success' => false, 'message' => 'Minimum transfer amount is ' . formatMoney(MIN_TRANSFER) . '.', 'reference' => ''];
        }

        if ($amount > MAX_TRANSFER) {
            return ['success' => false, 'message' => 'Maximum transfer amount is ' . formatMoney(MAX_TRANSFER) . '.', 'reference' => ''];
        }

        // ── Resolve recipient
        $recipientIdentifier = trim($recipientIdentifier);
        $recipient = \Database::fetchOne(
            'SELECT id, username, email, first_name, last_name, status, deleted_at
             FROM users
             WHERE (username = ? OR email = ?) AND deleted_at IS NULL
             LIMIT 1',
            [$recipientIdentifier, $recipientIdentifier]
        );

        if (!$recipient) {
            return ['success' => false, 'message' => 'Recipient not found. Please check the username or email.', 'reference' => ''];
        }

        if ((int) $recipient['id'] === $senderId) {
            return ['success' => false, 'message' => 'You cannot transfer funds to yourself.', 'reference' => ''];
        }

        if ($recipient['status'] !== 'active') {
            return ['success' => false, 'message' => 'The recipient account is not active.', 'reference' => ''];
        }

        // ── PIN verification
        if (!self::verifyPin($senderId, $transactionPin)) {
            return ['success' => false, 'message' => 'Invalid transaction PIN. Please try again.', 'reference' => ''];
        }

        $feePercent  = (float) setting('transfer_fee', TRANSFER_FEE);
        $fee         = round(($amount * $feePercent) / 100, 2);
        $totalCost   = $amount + $fee;
        $recipientId = (int) $recipient['id'];

        // ── Balance check
        if (!self::hasSufficientBalance($senderId, $amount, $fee)) {
            $balance = self::getBalance($senderId);
            $feeText = $fee > 0 ? ' (including ' . formatMoney($fee) . ' fee)' : '';
            return [
                'success'   => false,
                'message'   => 'Insufficient balance. You need ' . formatMoney($totalCost) . $feeText . ' but your balance is ' . formatMoney($balance) . '.',
                'reference' => '',
            ];
        }

        $reference   = generateTxnRef('TR');
        $description = trim($description) ?: 'Wallet transfer';

        try {
            \Database::beginTransaction();

            // Debit sender
            $senderTxnId = self::debit(
                userId:      $senderId,
                amount:      $amount,
                fee:         $fee,
                category:    TXN_TYPE_TRANSFER,
                description: 'Transfer to @' . $recipient['username'] . ': ' . $description,
                reference:   $reference . '_S',
                meta:        [
                    'recipient_id'       => $recipientId,
                    'recipient_username' => $recipient['username'],
                    'transfer_ref'       => $reference,
                ]
            );

            // Credit recipient
            $receiverTxnId = self::credit(
                userId:      $recipientId,
                amount:      $amount,
                category:    TXN_TYPE_TRANSFER,
                description: 'Transfer received from @' . self::getSenderUsername($senderId) . ': ' . $description,
                reference:   $reference . '_R',
                meta:        [
                    'sender_id'    => $senderId,
                    'transfer_ref' => $reference,
                ]
            );

            // Log to transfers table
            \Database::insert(
                'INSERT INTO transfers
                 (uuid, sender_id, receiver_id, amount, fee, sender_txn_id, receiver_txn_id, description, status, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    generateUUID(),
                    $senderId,
                    $recipientId,
                    $amount,
                    $fee,
                    $senderTxnId,
                    $receiverTxnId,
                    $description,
                    TXN_STATUS_SUCCESS,
                    getClientIP(),
                ]
            );

            \Database::commit();

            writeLog(LOG_CHAN_WALLET, 'info', 'Wallet transfer completed', [
                'sender_id'   => $senderId,
                'receiver_id' => $recipientId,
                'amount'      => $amount,
                'fee'         => $fee,
                'reference'   => $reference,
            ]);

            auditLog(
                'wallet_transfer',
                'Transferred ' . formatMoney($amount) . ' to @' . $recipient['username'],
                'transfer',
                null,
                ['amount' => $amount, 'recipient' => $recipient['username']]
            );

            // Notify sender
            sendNotification(
                $senderId,
                NOTIF_SUCCESS,
                'Transfer Successful',
                formatMoney($amount) . ' sent to @' . $recipient['username'] . ' successfully. Reference: ' . $reference,
                APP_URL . '/wallet/transactions'
            );

            // Notify recipient
            sendNotification(
                $recipientId,
                NOTIF_SUCCESS,
                'Money Received',
                'You have received ' . formatMoney($amount) . ' in your wallet. Reference: ' . $reference,
                APP_URL . '/wallet/transactions'
            );

            return [
                'success'   => true,
                'message'   => formatMoney($amount) . ' successfully transferred to @' . $recipient['username'] . '.',
                'reference' => $reference,
            ];
        } catch (\Exception $e) {
            \Database::rollback();
            writeLog(LOG_CHAN_WALLET, 'error', 'Transfer failed', [
                'sender_id'   => $senderId,
                'receiver_id' => $recipientId,
                'amount'      => $amount,
                'reference'   => $reference,
                'error'       => $e->getMessage(),
            ]);

            return [
                'success'   => false,
                'message'   => 'Transfer failed. Your balance has not been deducted. Please try again.',
                'reference' => '',
            ];
        }
    }

    // ─── Transaction History ──────────────────────────────────────────────────

    /**
     * Get paginated transaction history for a user.
     *
     * @param int         $userId
     * @param int         $page
     * @param int         $perPage
     * @param string|null $category   Filter by category (e.g. 'deposit', 'airtime')
     * @param string|null $status     Filter by status ('pending', 'success', 'failed', 'reversed')
     * @param string|null $type       Filter by type ('credit', 'debit')
     * @return array ['transactions' => [], 'total' => int, 'pagination' => []]
     */
    public static function getTransactions(
        int     $userId,
        int     $page = 1,
        int     $perPage = 20,
        ?string $category = null,
        ?string $status = null,
        ?string $type = null
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $conditions = ['wt.user_id = ?'];
        $params     = [$userId];

        if ($category !== null && $category !== '') {
            $conditions[] = 'wt.category = ?';
            $params[]     = $category;
        }

        if ($status !== null && $status !== '') {
            $conditions[] = 'wt.status = ?';
            $params[]     = $status;
        }

        if ($type !== null && $type !== '') {
            $conditions[] = 'wt.type = ?';
            $params[]     = $type;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        // Total count
        $countRow = \Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM wallet_transactions wt {$where}",
            $params
        );
        $total = (int) ($countRow['cnt'] ?? 0);

        $pagination = paginate($total, $page, $perPage);

        // Fetch rows
        $rows = \Database::fetchAll(
            "SELECT wt.id, wt.uuid, wt.type, wt.category, wt.amount, wt.fee,
                    wt.balance_before, wt.balance_after, wt.reference,
                    wt.description, wt.status, wt.meta, wt.ip_address, wt.created_at,
                    vt.vtpass_token
             FROM wallet_transactions wt
             LEFT JOIN vtpass_transactions vt ON wt.reference = vt.request_id
             {$where}
             ORDER BY wt.created_at DESC
             LIMIT {$perPage} OFFSET {$pagination['offset']}",
            $params
        );

        // Decode meta JSON for each row
        foreach ($rows as &$row) {
            $row['meta'] = !empty($row['meta']) ? json_decode($row['meta'], true) : null;
        }
        unset($row);

        // Fetch pending deposit intents if status filter allows 'pending' and category filter allows 'deposit'
        $includePendingDeposits = ($status === null || $status === '' || $status === 'pending')
                               && ($category === null || $category === '' || $category === 'deposit')
                               && ($type === null || $type === '' || $type === 'credit');

        if ($includePendingDeposits && $page === 1) {
            $intents = \Database::fetchAll(
                "SELECT * FROM deposit_intents 
                 WHERE user_id = ? AND status = 'pending' AND expires_at > NOW()
                 ORDER BY created_at DESC",
                [$userId]
            );
            $pendingIntents = [];
            foreach ($intents as $di) {
                $pendingIntents[] = [
                    'id'             => 'intent_' . $di['id'],
                    'uuid'           => $di['uuid'],
                    'type'           => 'credit',
                    'category'       => 'deposit',
                    'amount'         => (float)$di['amount'],
                    'fee'            => 0.0,
                    'balance_before' => null,
                    'balance_after'  => null,
                    'reference'      => 'DEP-' . strtoupper(substr($di['uuid'], 0, 8)),
                    'description'    => 'Pending Deposit Intent (Sender: ' . $di['sender_name'] . ')',
                    'status'         => 'pending',
                    'meta'           => ['sender_name' => $di['sender_name'], 'expires_at' => $di['expires_at']],
                    'created_at'     => $di['created_at'],
                    'vtpass_token'   => null,
                ];
            }
            if (!empty($pendingIntents)) {
                $rows = array_merge($pendingIntents, $rows);
                $total += count($pendingIntents);
            }
        }

        return [
            'transactions' => $rows,
            'total'        => $total,
            'pagination'   => $pagination,
        ];
    }

    // ─── Summary ─────────────────────────────────────────────────────────────

    /**
     * Get wallet summary statistics for a user (dashboard widgets).
     *
     * @return array ['total_credited', 'total_debited', 'balance', 'transaction_count']
     */
    public static function getSummary(int $userId): array
    {
        $row = \Database::fetchOne(
            "SELECT
                SUM(CASE WHEN type = 'credit' AND status = 'success' THEN amount ELSE 0 END)             AS total_credited,
                SUM(CASE WHEN type = 'debit'  AND status IN ('success','pending') THEN (amount + fee) ELSE 0 END) AS total_debited,
                COUNT(*)                                                                                   AS transaction_count
             FROM wallet_transactions
             WHERE user_id = ?",
            [$userId]
        );

        $balance = self::getBalance($userId);

        return [
            'total_credited'    => (float) ($row['total_credited']    ?? 0),
            'total_debited'     => (float) ($row['total_debited']     ?? 0),
            'balance'           => $balance,
            'transaction_count' => (int)   ($row['transaction_count'] ?? 0),
        ];
    }

    // ─── PIN Verification ─────────────────────────────────────────────────────

    /**
     * Verify a user's transaction PIN.
     * Applies rate limiting (5 failed attempts per 15 minutes).
     *
     * @return bool  true if PIN is correct
     */
    public static function verifyPin(int $userId, string $pin): bool
    {
        // Structural validation first
        if (!validatePin($pin)) {
            return false;
        }

        // Rate limit PIN attempts
        $rlKey = 'user_' . $userId;
        $limit = rateLimit($rlKey, 'pin_verify', 5, 900);
        if (!$limit['allowed']) {
            writeLog(LOG_CHAN_WALLET, 'warning', 'PIN verification rate-limited', ['user_id' => $userId]);
            return false;
        }

        $user = \Database::fetchOne(
            'SELECT transaction_pin FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$userId]
        );

        if (!$user || empty($user['transaction_pin'])) {
            // User has no PIN set
            return false;
        }

        $verified = verifyPin($pin, $user['transaction_pin']);

        if ($verified) {
            // Clear rate limit on success
            clearRateLimit($rlKey, 'pin_verify');
        } else {
            writeLog(LOG_CHAN_WALLET, 'warning', 'Failed PIN attempt', ['user_id' => $userId]);
        }

        return $verified;
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────

    /**
     * Get the username for a given user ID (used in transfer descriptions).
     */
    private static function getSenderUsername(int $userId): string
    {
        $row = \Database::fetchOne(
            'SELECT username FROM users WHERE id = ? LIMIT 1',
            [$userId]
        );
        return $row['username'] ?? ('user_' . $userId);
    }

    /**
     * Return the list of valid transaction categories (from constants).
     */
    private static function validCategories(): array
    {
        return [
            TXN_TYPE_DEPOSIT,
            TXN_TYPE_WITHDRAWAL,
            TXN_TYPE_TRANSFER,
            TXN_TYPE_AIRTIME,
            TXN_TYPE_DATA,
            TXN_TYPE_CABLE,
            TXN_TYPE_ELECTRICITY,
            TXN_TYPE_BETTING,
            TXN_TYPE_WAEC,
            TXN_TYPE_JAMB,
            TXN_TYPE_NECO,
            TXN_TYPE_REFERRAL,
        ];
    }
}
