<?php
/**
 * OURCR ONLINE - Admin Panel Withdrawals payouts
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'Withdrawals Processing';
$error = '';
$success = '';

// Handle POST actions: Approve/Reject withdrawal
if (isPost()) {
    try {
        requireCsrf();
        $action = post('action');
        $withdrawalId = (int)post('withdrawal_id');

        if ($withdrawalId > 0) {
            $w = Database::fetchOne("SELECT * FROM withdrawals WHERE id = ? LIMIT 1", [$withdrawalId]);
            if ($w) {
                if ($w['status'] !== 'pending') {
                    throw new Exception("Withdrawal is already " . $w['status'] . ".");
                }

                if ($action === 'approve') {
                    // Start payouts processing
                    Database::execute("UPDATE withdrawals SET status = 'processing', updated_at = NOW() WHERE id = ?", [$withdrawalId]);

                    // Attempt automated transfer using GAPS withdrawal API client
                    $bankCode = getBankCodeByName($w['bank_name']);
                    if ($bankCode === '') {
                        throw new Exception('Unsupported bank for automated GAPS payout: ' . $w['bank_name']);
                    }

                    $gapsResult = \Ourcr\GAPS\GAPS::processWithdrawal(
                        $w['account_number'],
                        $w['account_name'],
                        $bankCode,
                        (float)$w['amount'],
                        $w['uuid'],
                        'OURCR payout',
                        $w['bank_name']
                    );

                    if ($gapsResult['success']) {
                        // Mark successful
                        Database::execute(
                            "UPDATE withdrawals SET status = 'success', processed_at = NOW(), gaps_reference = ?, gaps_response = ? WHERE id = ?",
                            [$gapsResult['reference'] ?? $w['uuid'], json_encode($gapsResult['data']), $withdrawalId]
                        );
                        
                        sendNotification(
                            $w['user_id'],
                            NOTIF_SUCCESS,
                            'Withdrawal Successful!',
                            "Your withdrawal request of " . formatMoney($w['amount']) . " has been successfully paid out."
                        );

                        auditLog('WITHDRAWAL_APPROVED', "Approved withdrawal ID $withdrawalId. paid out via GAPS", 'withdrawals', $withdrawalId);
                        $success = "Withdrawal successfully paid out via GAPS.";
                    } else {
                        // Revert to pending or fail? We set to failed and refund user
                        Database::beginTransaction();
                        try {
                            Database::execute(
                                "UPDATE withdrawals SET status = 'failed', admin_notes = ?, gaps_response = ? WHERE id = ?",
                                ['GAPS failed: ' . $gapsResult['message'], json_encode($gapsResult), $withdrawalId]
                            );

                            if (!empty($w['wallet_txn_id'])) {
                                Database::execute("UPDATE wallet_transactions SET status = 'failed' WHERE id = ?", [$w['wallet_txn_id']]);
                            }

                            // Refund user wallet
                            $refundAmt = (float)$w['amount'] + (float)$w['fee'];
                            creditWallet($w['user_id'], $refundAmt, TXN_TYPE_DEPOSIT, "Refund for failed withdrawal " . $w['uuid'], 'RF-' . $w['uuid']);
                            
                            Database::commit();
                            
                            sendNotification(
                                $w['user_id'],
                                NOTIF_ERROR,
                                'Withdrawal Failed',
                                "Your withdrawal of " . formatMoney($w['amount']) . " failed. Funds have been refunded."
                            );

                            throw new Exception("GAPS Transfer Rejection: " . $gapsResult['message'] . ". Wallet refunded.");
                        } catch (Exception $ex2) {
                            Database::rollback();
                            throw $ex2;
                        }
                    }
                } 
                
                elseif ($action === 'reject') {
                    $reason = sanitizeString(post('reject_reason'));
                    if (empty($reason)) {
                        throw new Exception("Please specify a reason for rejection.");
                    }

                    Database::beginTransaction();
                    try {
                        Database::execute("UPDATE withdrawals SET status = 'failed', admin_notes = ? WHERE id = ?", [$reason, $withdrawalId]);

                        // Refund wallet
                        $refundAmt = (float)$w['amount'] + (float)$w['fee'];
                        creditWallet($w['user_id'], $refundAmt, TXN_TYPE_DEPOSIT, "Refund for rejected withdrawal: $reason");

                        Database::commit();

                        sendNotification(
                            $w['user_id'],
                            NOTIF_ERROR,
                            'Withdrawal Rejected',
                            "Your withdrawal of " . formatMoney($w['amount']) . " was rejected: $reason. Refunded."
                        );

                        auditLog('WITHDRAWAL_REJECTED', "Rejected withdrawal ID $withdrawalId. Reason: $reason", 'withdrawals', $withdrawalId);
                        $success = "Withdrawal rejected. Funds refunded to user.";
                    } catch (Exception $ex3) {
                        Database::rollback();
                        throw $ex3;
                    }
                }
                redirectTo('admin/withdrawals');
            } else {
                setFlash('error', 'Withdrawal request not found.');
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch withdrawals
$statusFilter = get('status') ?: 'pending';
$params = [];
$where = [];
if ($statusFilter !== 'all') {
    $where[] = "w.status = ?";
    $params[] = $statusFilter;
}
$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$query = "SELECT w.*, u.username 
          FROM withdrawals w 
          LEFT JOIN users u ON w.user_id = u.id 
          $whereClause 
          ORDER BY w.created_at DESC";

$withdrawals = Database::fetchAll($query, $params);

include ADMIN_PATH . '/includes/header.php';
?>
<div class="admin-wrapper">
    <?php include ADMIN_PATH . '/includes/sidebar.php'; ?>
    
    <main class="admin-main">
        <div class="admin-topbar">
            <div class="d-flex align-items-center gap-3">
                <button class="admin-sidebar-toggle d-lg-none" id="adminSidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="admin-topbar-title"><?= e($adminPageTitle) ?></h1>
            </div>
        </div>

        <div class="admin-content fade-in-up">
            
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?= e($success) ?>
                </div>
            <?php endif; ?>

            <!-- Filter Tabs -->
            <div class="mb-4">
                <div class="btn-group" role="group">
                    <a href="<?= APP_URL ?>/admin/withdrawals?status=pending" class="btn btn-<?= $statusFilter === 'pending' ? 'primary' : 'outline-secondary' ?>">Pending</a>
                    <a href="<?= APP_URL ?>/admin/withdrawals?status=success" class="btn btn-<?= $statusFilter === 'success' ? 'primary' : 'outline-secondary' ?>">Paid</a>
                    <a href="<?= APP_URL ?>/admin/withdrawals?status=failed" class="btn btn-<?= $statusFilter === 'failed' ? 'primary' : 'outline-secondary' ?>">Failed / Rejected</a>
                    <a href="<?= APP_URL ?>/admin/withdrawals?status=all" class="btn btn-<?= $statusFilter === 'all' ? 'primary' : 'outline-secondary' ?>">All Logs</a>
                </div>
            </div>

            <!-- Withdrawals Table -->
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>User Reference</th>
                            <th>Bank Credentials</th>
                            <th>Amount</th>
                            <th>Fee</th>
                            <th>Date Request</th>
                            <th>Status</th>
                            <?php if ($statusFilter === 'pending'): ?>
                                <th class="text-end">Operations</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($withdrawals)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">No withdrawal records found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($withdrawals as $w): ?>
                                <tr>
                                    <td>
                                        <span class="fw-bold">@<?= e($w['username']) ?></span>
                                        <div class="small font-monospace text-muted fs-8"><?= e($w['uuid']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= e($w['bank_name']) ?></div>
                                        <div class="small text-muted"><?= e($w['account_number']) ?> (<?= e($w['account_name']) ?>)</div>
                                        <?php if (!empty($w['remark'])): ?>
                                            <div class="small mt-1 text-primary"><i class="fas fa-comment-dots me-1"></i><?= e($w['remark']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold text-danger">- <?= formatMoney($w['amount'], false) ?></td>
                                    <td class="small text-muted"><?= formatMoney($w['fee']) ?></td>
                                    <td class="small"><?= date('d M Y, h:i A', strtotime($w['created_at'])) ?></td>
                                    <td>
                                        <span class="admin-badge admin-badge-<?= $w['status'] === 'success' ? 'success' : ($w['status'] === 'pending' ? 'pending' : 'failed') ?>">
                                            <?= ucfirst($w['status']) ?>
                                        </span>
                                    </td>
                                    <?php if ($statusFilter === 'pending'): ?>
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end gap-1">
                                                <!-- Approve Form -->
                                                <form method="POST" action="<?= APP_URL ?>/admin/withdrawals" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="withdrawal_id" value="<?= $w['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-success px-3" onclick="return confirm('Payout this withdrawal via GAPS?')">
                                                        Payout GAPS
                                                    </button>
                                                </form>

                                                <!-- Reject Trigger -->
                                                <button class="btn btn-sm btn-danger px-3 btn-reject-trigger" 
                                                        data-id="<?= $w['id'] ?>"
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#rejectModal">
                                                    Reject
                                                </button>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </main>
</div>

<!-- Modal: Reject Withdrawal Reason -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-16 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
                <h5 class="modal-title fw-bold">Reject Payout Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= APP_URL ?>/admin/withdrawals">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" id="modal_withdrawal_id" name="withdrawal_id" value="">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="reject_reason" class="form-label small">Rejection Reason</label>
                        <textarea class="form-control" id="reject_reason" name="reject_reason" rows="3" placeholder="Provide details. E.g. Name mismatch on recipient account" required></textarea>
                        <div class="form-text small">This message will be sent to the user and their funds refunded.</div>
                    </div>
                </div>

                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="background-color: #ef4444; border: none;">Reject & Refund</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.btn-reject-trigger').on('click', function() {
        $('#modal_withdrawal_id').val($(this).attr('data-id'));
    });
});
</script>
<?php include ADMIN_PATH . '/includes/footer.php'; ?>
