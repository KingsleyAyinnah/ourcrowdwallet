<?php
namespace Ourcr;

/**
 * OURCR ONLINE - Transaction Service Class
 *
 * Provides admin and system-level transaction management: lookup,
 * status updates, reversals, revenue analytics, chart data, and CSV export.
 *
 * All DB operations use Database:: static methods with prepared statements.
 * Reversals are wrapped in their own transactions internally.
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');



class Transaction
{
    // ─── Lookup ──────────────────────────────────────────────────────────────

    /**
     * Find a single transaction by its unique reference string.
     *
     * @return array|false  Row data or false if not found
     */
    public static function findByReference(string $reference): array|false
    {
        if (empty($reference)) {
            return false;
        }

        $row = \Database::fetchOne(
            'SELECT wt.*, u.username, u.email, u.first_name, u.last_name
             FROM wallet_transactions wt
             JOIN users u ON u.id = wt.user_id
             WHERE wt.reference = ?
             LIMIT 1',
            [trim($reference)]
        );

        if ($row && !empty($row['meta'])) {
            $row['meta'] = json_decode($row['meta'], true);
        }

        return $row ?: false;
    }

    /**
     * Find a single transaction by its primary key ID.
     *
     * @return array|false  Row data or false if not found
     */
    public static function findById(int $id): array|false
    {
        $row = \Database::fetchOne(
            'SELECT wt.*, u.username, u.email, u.first_name, u.last_name
             FROM wallet_transactions wt
             JOIN users u ON u.id = wt.user_id
             WHERE wt.id = ?
             LIMIT 1',
            [$id]
        );

        if ($row && !empty($row['meta'])) {
            $row['meta'] = json_decode($row['meta'], true);
        }

        return $row ?: false;
    }

    // ─── Admin: List All ─────────────────────────────────────────────────────

    /**
     * Get all transactions (admin use) with optional filtering and pagination.
     *
     * @param int   $page
     * @param int   $perPage
     * @param array $filters  Supported keys: user_id, category, status, type, date_from, date_to, search
     * @return array ['transactions' => [], 'total' => int]
     */
    public static function getAll(
        int   $page = 1,
        int   $perPage = 25,
        array $filters = []
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $conditions = ['1 = 1'];
        $params     = [];

        // ── Apply filters
        if (!empty($filters['user_id'])) {
            $conditions[] = 'wt.user_id = ?';
            $params[]     = (int) $filters['user_id'];
        }

        if (!empty($filters['category'])) {
            $conditions[] = 'wt.category = ?';
            $params[]     = $filters['category'];
        }

        if (!empty($filters['status'])) {
            $conditions[] = 'wt.status = ?';
            $params[]     = $filters['status'];
        }

        if (!empty($filters['type'])) {
            $conditions[] = 'wt.type = ?';
            $params[]     = $filters['type'];
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = 'DATE(wt.created_at) >= ?';
            $params[]     = date('Y-m-d', strtotime($filters['date_from']));
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = 'DATE(wt.created_at) <= ?';
            $params[]     = date('Y-m-d', strtotime($filters['date_to']));
        }

        if (!empty($filters['search'])) {
            $search       = '%' . trim($filters['search']) . '%';
            $conditions[] = '(wt.reference LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR wt.description LIKE ?)';
            $params[]     = $search;
            $params[]     = $search;
            $params[]     = $search;
            $params[]     = $search;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        // ── Count
        $countRow = \Database::fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM wallet_transactions wt
             JOIN users u ON u.id = wt.user_id
             {$where}",
            $params
        );
        $total = (int) ($countRow['cnt'] ?? 0);

        $pagination = paginate($total, $page, $perPage);

        // ── Fetch
        $rows = \Database::fetchAll(
            "SELECT wt.id, wt.uuid, wt.user_id, wt.type, wt.category,
                    wt.amount, wt.fee, wt.balance_before, wt.balance_after,
                    wt.reference, wt.description, wt.status,
                    wt.meta, wt.ip_address, wt.created_at, wt.updated_at,
                    u.username, u.email,
                    CONCAT(u.first_name, ' ', u.last_name) AS full_name
             FROM wallet_transactions wt
             JOIN users u ON u.id = wt.user_id
             {$where}
             ORDER BY wt.created_at DESC
             LIMIT {$perPage} OFFSET {$pagination['offset']}",
            $params
        );

        foreach ($rows as &$row) {
            $row['meta'] = !empty($row['meta']) ? json_decode($row['meta'], true) : null;
        }
        unset($row);

        return [
            'transactions' => $rows,
            'total'        => $total,
            'pagination'   => $pagination,
        ];
    }

    // ─── Status Updates ───────────────────────────────────────────────────────

    /**
     * Mark a transaction as failed and optionally record a reason.
     *
     * @return bool  true on success
     */
    public static function markFailed(string $reference, string $reason = ''): bool
    {
        $txn = self::findByReference($reference);
        if (!$txn) {
            writeLog(LOG_CHAN_WALLET, 'warning', 'markFailed: transaction not found', ['reference' => $reference]);
            return false;
        }

        if ($txn['status'] === TXN_STATUS_FAILED) {
            // Already failed — idempotent
            return true;
        }

        if (in_array($txn['status'], [TXN_STATUS_REVERSED], true)) {
            writeLog(LOG_CHAN_WALLET, 'warning', 'markFailed: cannot fail a reversed transaction', ['reference' => $reference]);
            return false;
        }

        $meta = $txn['meta'] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }
        if ($reason !== '') {
            $meta['failure_reason'] = $reason;
            $meta['failed_at']      = date('Y-m-d H:i:s');
        }

        $affected = \Database::execute(
            "UPDATE wallet_transactions SET status = ?, meta = ? WHERE reference = ?",
            [TXN_STATUS_FAILED, json_encode($meta), $reference]
        );

        if ($affected > 0) {
            writeLog(LOG_CHAN_WALLET, 'info', 'Transaction marked failed', [
                'reference' => $reference,
                'reason'    => $reason,
            ]);
            return true;
        }

        return false;
    }

    // ─── Reversal ─────────────────────────────────────────────────────────────

    /**
     * Reverse a successful transaction.
     *
     * For a debit transaction: credit the user back the full amount + fee.
     * For a credit transaction: debit the user the credited amount.
     *
     * Creates a counter-entry in wallet_transactions with status 'reversed'.
     * Marks the original transaction as 'reversed'.
     *
     * @param string $reference   Original transaction reference
     * @param int    $adminId     Admin user performing the reversal
     * @param string $reason      Reason for reversal (required)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function reverse(string $reference, int $adminId, string $reason): array
    {
        $reason = trim($reason);
        if (empty($reason)) {
            return ['success' => false, 'message' => 'A reason is required to reverse a transaction.'];
        }

        $txn = self::findByReference($reference);
        if (!$txn) {
            return ['success' => false, 'message' => 'Transaction not found.'];
        }

        if ($txn['status'] !== TXN_STATUS_SUCCESS) {
            return ['success' => false, 'message' => 'Only successful transactions can be reversed. Current status: ' . $txn['status'] . '.'];
        }

        $userId  = (int) $txn['user_id'];
        $amount  = (float) $txn['amount'];
        $fee     = (float) $txn['fee'];
        $revRef  = generateTxnRef('REV');

        try {
            \Database::beginTransaction();

            if ($txn['type'] === TXN_CREDIT) {
                // Reverse a credit: debit the user for the credited amount
                // We do NOT charge the fee again on reversal — only reverse the credit amount
                debitWallet(
                    userId:      $userId,
                    amount:      $amount,
                    fee:         0.0,
                    category:    $txn['category'],
                    description: 'Reversal of credit #' . $txn['id'] . ': ' . $reason,
                    reference:   $revRef,
                    meta:        [
                        'reversal_of'  => $reference,
                        'reversed_by'  => $adminId,
                        'reason'       => $reason,
                        'reversed_at'  => date('Y-m-d H:i:s'),
                    ]
                );
            } else {
                // Reverse a debit: credit the user back the full amount + fee
                creditWallet(
                    userId:      $userId,
                    amount:      $amount + $fee,
                    category:    $txn['category'],
                    description: 'Reversal of debit #' . $txn['id'] . ': ' . $reason,
                    reference:   $revRef,
                    meta:        [
                        'reversal_of'  => $reference,
                        'reversed_by'  => $adminId,
                        'reason'       => $reason,
                        'reversed_at'  => date('Y-m-d H:i:s'),
                    ]
                );
            }

            // Mark original as reversed
            $originalMeta = (is_array($txn['meta']) ? $txn['meta'] : []);
            $originalMeta['reversed_by']  = $adminId;
            $originalMeta['reversal_ref'] = $revRef;
            $originalMeta['reversal_reason'] = $reason;
            $originalMeta['reversed_at']  = date('Y-m-d H:i:s');

            \Database::execute(
                "UPDATE wallet_transactions SET status = ?, meta = ? WHERE reference = ?",
                [TXN_STATUS_REVERSED, json_encode($originalMeta), $reference]
            );

            \Database::commit();

            writeLog(LOG_CHAN_WALLET, 'info', 'Transaction reversed', [
                'original_ref' => $reference,
                'reversal_ref' => $revRef,
                'user_id'      => $userId,
                'admin_id'     => $adminId,
                'reason'       => $reason,
            ]);

            auditLog(
                'transaction_reversed',
                "Transaction {$reference} reversed by admin #{$adminId}. Reason: {$reason}",
                'wallet_transactions',
                (int) $txn['id'],
                ['reversal_ref' => $revRef, 'reason' => $reason]
            );

            sendNotification(
                $userId,
                NOTIF_INFO,
                'Transaction Reversed',
                'Your transaction with reference ' . $reference . ' has been reversed. Please check your wallet balance.',
                APP_URL . '/wallet/transactions'
            );

            return [
                'success' => true,
                'message' => 'Transaction reversed successfully. Reversal reference: ' . $revRef,
            ];
        } catch (\Exception $e) {
            \Database::rollback();
            writeLog(LOG_CHAN_WALLET, 'error', 'Reversal failed', [
                'reference' => $reference,
                'admin_id'  => $adminId,
                'error'     => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Reversal failed: ' . $e->getMessage(),
            ];
        }
    }

    // ─── Revenue Summary ─────────────────────────────────────────────────────

    /**
     * Get revenue summary for admin dashboard.
     *
     * @param string $period  'today' | 'yesterday' | 'week' | 'month' | 'year'
     * @return array ['total_volume', 'total_fees', 'by_category']
     */
    public static function getRevenueSummary(string $period = 'today'): array
    {
        $dateCondition = match ($period) {
            'today'     => 'DATE(created_at) = CURDATE()',
            'yesterday' => 'DATE(created_at) = CURDATE() - INTERVAL 1 DAY',
            'week'      => 'created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            'month'     => 'created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            'year'      => 'YEAR(created_at) = YEAR(NOW())',
            default     => 'DATE(created_at) = CURDATE()',
        };

        // Overall totals for successful debit transactions (fee-generating)
        $totals = \Database::fetchOne(
            "SELECT
                SUM(amount)         AS total_volume,
                SUM(fee)            AS total_fees,
                COUNT(*)            AS transaction_count
             FROM wallet_transactions
             WHERE status = 'success'
               AND {$dateCondition}"
        );

        // Breakdown by category
        $byCategory = \Database::fetchAll(
            "SELECT
                category,
                COUNT(*)            AS count,
                SUM(amount)         AS volume,
                SUM(fee)            AS fees
             FROM wallet_transactions
             WHERE status = 'success'
               AND {$dateCondition}
             GROUP BY category
             ORDER BY volume DESC"
        );

        return [
            'total_volume'      => (float) ($totals['total_volume']      ?? 0),
            'total_fees'        => (float) ($totals['total_fees']         ?? 0),
            'transaction_count' => (int)   ($totals['transaction_count']  ?? 0),
            'by_category'       => $byCategory,
            'period'            => $period,
        ];
    }

    // ─── Chart Data ───────────────────────────────────────────────────────────

    /**
     * Get daily transaction totals for chart rendering (last N days).
     *
     * Returns an array of objects with:
     *   date, credit_total, debit_total, credit_count, debit_count
     *
     * @param int $days  Number of past days to retrieve (max 365)
     * @return array
     */
    public static function getChartData(int $days = 30): array
    {
        $days = max(1, min(365, $days));

        $rows = \Database::fetchAll(
            "SELECT
                DATE(created_at)                                                   AS `date`,
                SUM(CASE WHEN type = 'credit' AND status = 'success' THEN amount ELSE 0 END)  AS credit_total,
                SUM(CASE WHEN type = 'debit'  AND status = 'success' THEN amount ELSE 0 END)  AS debit_total,
                SUM(CASE WHEN type = 'debit'  AND status = 'success' THEN fee    ELSE 0 END)  AS fee_total,
                COUNT(CASE WHEN type = 'credit' AND status = 'success' THEN 1 END)            AS credit_count,
                COUNT(CASE WHEN type = 'debit'  AND status = 'success' THEN 1 END)            AS debit_count
             FROM wallet_transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY `date` ASC",
            [$days]
        );

        // Fill in missing days with zeros so charts are continuous
        $filled = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $filled[$date] = [
                'date'         => $date,
                'credit_total' => 0.0,
                'debit_total'  => 0.0,
                'fee_total'    => 0.0,
                'credit_count' => 0,
                'debit_count'  => 0,
            ];
        }

        foreach ($rows as $row) {
            $date = $row['date'];
            if (isset($filled[$date])) {
                $filled[$date] = [
                    'date'         => $date,
                    'credit_total' => (float) $row['credit_total'],
                    'debit_total'  => (float) $row['debit_total'],
                    'fee_total'    => (float) $row['fee_total'],
                    'credit_count' => (int)   $row['credit_count'],
                    'debit_count'  => (int)   $row['debit_count'],
                ];
            }
        }

        return array_values($filled);
    }

    // ─── Count by Status ─────────────────────────────────────────────────────

    /**
     * Count all transactions by status for admin overview cards.
     *
     * @return array ['pending' => int, 'success' => int, 'failed' => int, 'reversed' => int]
     */
    public static function countByStatus(): array
    {
        $rows = \Database::fetchAll(
            "SELECT status, COUNT(*) AS cnt FROM wallet_transactions GROUP BY status"
        );

        $counts = [
            'pending'  => 0,
            'success'  => 0,
            'failed'   => 0,
            'reversed' => 0,
        ];

        foreach ($rows as $row) {
            if (array_key_exists($row['status'], $counts)) {
                $counts[$row['status']] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    // ─── CSV Export ───────────────────────────────────────────────────────────

    /**
     * Export transactions to CSV format string.
     *
     * Accepts the same filter keys as getAll():
     *   user_id, category, status, type, date_from, date_to, search
     *
     * Returns the raw CSV string (caller is responsible for sending headers
     * and echoing the content).
     *
     * @param array $filters
     * @return string  CSV content
     */
    public static function exportToCSV(array $filters = []): string
    {
        $conditions = ['1 = 1'];
        $params     = [];

        if (!empty($filters['user_id'])) {
            $conditions[] = 'wt.user_id = ?';
            $params[]     = (int) $filters['user_id'];
        }

        if (!empty($filters['category'])) {
            $conditions[] = 'wt.category = ?';
            $params[]     = $filters['category'];
        }

        if (!empty($filters['status'])) {
            $conditions[] = 'wt.status = ?';
            $params[]     = $filters['status'];
        }

        if (!empty($filters['type'])) {
            $conditions[] = 'wt.type = ?';
            $params[]     = $filters['type'];
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = 'DATE(wt.created_at) >= ?';
            $params[]     = date('Y-m-d', strtotime($filters['date_from']));
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = 'DATE(wt.created_at) <= ?';
            $params[]     = date('Y-m-d', strtotime($filters['date_to']));
        }

        if (!empty($filters['search'])) {
            $search       = '%' . trim($filters['search']) . '%';
            $conditions[] = '(wt.reference LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
            $params[]     = $search;
            $params[]     = $search;
            $params[]     = $search;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $rows = \Database::fetchAll(
            "SELECT
                wt.id,
                wt.reference,
                wt.type,
                wt.category,
                wt.amount,
                wt.fee,
                wt.balance_before,
                wt.balance_after,
                wt.status,
                wt.description,
                wt.ip_address,
                wt.created_at,
                u.username,
                u.email,
                CONCAT(u.first_name, ' ', u.last_name) AS full_name
             FROM wallet_transactions wt
             JOIN users u ON u.id = wt.user_id
             {$where}
             ORDER BY wt.created_at DESC
             LIMIT 50000",
            $params
        );

        // Build CSV output
        $handle = fopen('php://temp', 'r+');

        // Header row
        fputcsv($handle, [
            'ID',
            'Reference',
            'Type',
            'Category',
            'Amount (NGN)',
            'Fee (NGN)',
            'Balance Before',
            'Balance After',
            'Status',
            'Description',
            'Username',
            'Email',
            'Full Name',
            'IP Address',
            'Date & Time',
        ]);

        // Data rows
        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['id'],
                $row['reference'],
                strtoupper($row['type']),
                $row['category'],
                number_format((float) $row['amount'], 2, '.', ''),
                number_format((float) $row['fee'],    2, '.', ''),
                number_format((float) $row['balance_before'], 2, '.', ''),
                number_format((float) $row['balance_after'],  2, '.', ''),
                strtoupper($row['status']),
                $row['description'] ?? '',
                $row['username'],
                $row['email'],
                $row['full_name'],
                $row['ip_address'] ?? '',
                $row['created_at'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        writeLog(LOG_CHAN_ADMIN, 'info', 'Transaction CSV export', [
            'filters'   => $filters,
            'row_count' => count($rows),
        ]);

        return (string) $csv;
    }
}
