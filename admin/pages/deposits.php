<?php
/**
 * OURCR ONLINE - Admin Panel Deposits matching (GAPS Reconciliation)
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'Deposits Reconciliation';
$error = '';
$success = '';

// Handle POST actions: Match unmatched GAPS credit to an intent or reject intent
if (isPost()) {
    try {
        requireCsrf();
        $action = post('action');

        if ($action === 'match_deposit') {
            $gapsId   = (int)post('gaps_id');
            $intentId = (int)post('intent_id');

            if ($gapsId <= 0 || $intentId <= 0) {
                throw new Exception("Please select valid transaction mappings.");
            }

            // Fetch details
            $credit = Database::fetchOne("SELECT * FROM gaps_transactions WHERE id = ? AND matched = 0 LIMIT 1", [$gapsId]);
            $intent = Database::fetchOne("SELECT * FROM deposit_intents WHERE id = ? AND status = 'pending' LIMIT 1", [$intentId]);

            if ($credit && $intent) {
                // Perform check
                if ((float)$credit['amount'] !== (float)$intent['amount']) {
                    throw new Exception("Security Warning: Declared amount (" . formatMoney($intent['amount']) . ") does not match bank statement credit (" . formatMoney($credit['amount']) . ").");
                }

                Database::beginTransaction();
                try {
                    $userId = (int)$intent['user_id'];
                    $amount = (float)$intent['amount'];

                    // Credit wallet (applying dynamic deposit match fees)
                    $depositPercentage = (float)setting('deposit_fee_percentage', 1.5);
                    $depositFlat = (float)setting('deposit_fee_flat', 0);
                    $fee = ($amount * ($depositPercentage / 100)) + $depositFlat;
                    $creditAmount = $amount - $fee;
                    if ($creditAmount < 0) {
                        $creditAmount = 0;
                    }

                    $desc = "Bank deposit credited matching reference " . $credit['gaps_reference'] . " (Fee: " . formatMoney($fee) . ") (Manual Override)";
                    $txnId = creditWallet($userId, $creditAmount, TXN_TYPE_DEPOSIT, $desc, $credit['gaps_reference'], [], $fee);

                    // Update intent status
                    Database::execute(
                        "UPDATE deposit_intents 
                         SET status = 'matched', gaps_reference = ?, wallet_txn_id = ?, matched_at = NOW() 
                         WHERE id = ?",
                        [$credit['gaps_reference'], $txnId, $intentId]
                    );

                    // Update gaps_transactions matched
                    Database::execute(
                        "UPDATE gaps_transactions 
                         SET matched = 1, deposit_intent_id = ?, processed_at = NOW() 
                         WHERE id = ?",
                        [$intentId, $gapsId]
                    );

                    // Notify user
                    sendNotification(
                        $userId,
                        NOTIF_SUCCESS,
                        'Deposit Credited!',
                        "Your bank transfer of " . formatMoney($amount) . " has been manually verified and credited."
                    );

                    auditLog('DEPOSIT_MANUAL_MATCHED', "Manually matched deposit intent $intentId with GAPS credit $gapsId", 'deposit_intents', $intentId);
                    Database::commit();

                    $success = "Deposit intent successfully matched and credited.";
                } catch (Exception $ex) {
                    Database::rollback();
                    $error = $ex->getMessage();
                }
            } else {
                throw new Exception("Selected record details are no longer pending or valid.");
            }
        } 
        
        elseif ($action === 'direct_approve') {
            $intentId = (int)post('intent_id');
            $intent = Database::fetchOne("SELECT * FROM deposit_intents WHERE id = ? AND status = 'pending' LIMIT 1", [$intentId]);

            if ($intent) {
                Database::beginTransaction();
                try {
                    $userId = (int)$intent['user_id'];
                    $amount = (float)$intent['amount'];

                    $depositPercentage = (float)setting('deposit_fee_percentage', 1.5);
                    $depositFlat = (float)setting('deposit_fee_flat', 0);
                    $fee = ($amount * ($depositPercentage / 100)) + $depositFlat;
                    $creditAmount = max(0, $amount - $fee);

                    $ref = 'MANUAL_' . time() . '_' . rand(100, 999);
                    $desc = "Bank deposit credited for declared intent (Fee: " . formatMoney($fee) . ") (Admin Direct Approval)";
                    $txnId = creditWallet($userId, $creditAmount, TXN_TYPE_DEPOSIT, $desc, $ref, [], $fee);

                    // Update intent status
                    Database::execute(
                        "UPDATE deposit_intents 
                         SET status = 'matched', gaps_reference = ?, wallet_txn_id = ?, matched_at = NOW() 
                         WHERE id = ?",
                        [$ref, $txnId, $intentId]
                    );

                    // Also record in gaps_transactions table
                    Database::execute(
                        "INSERT INTO gaps_transactions (gaps_reference, transaction_type, amount, sender_name, narration, transaction_date, matched, deposit_intent_id, processed_at)
                         VALUES (?, 'credit', ?, ?, ?, NOW(), 1, ?, NOW())",
                        [$ref, $amount, $intent['sender_name'], "Direct approved transfer from " . $intent['sender_name'], $intentId]
                    );

                    sendNotification(
                        $userId,
                        NOTIF_SUCCESS,
                        'Deposit Credited!',
                        "Your bank transfer of " . formatMoney($amount) . " has been verified and credited to your wallet balance."
                    );

                    auditLog('DEPOSIT_DIRECT_APPROVED', "Directly approved deposit intent ID $intentId (" . formatMoney($amount) . ")", 'deposit_intents', $intentId);
                    Database::commit();

                    $success = "Deposit intent successfully approved and user wallet credited with " . formatMoney($creditAmount) . ".";
                } catch (Exception $ex) {
                    Database::rollback();
                    $error = $ex->getMessage();
                }
            } else {
                throw new Exception("Deposit intent not found or already processed.");
            }
        }

        elseif ($action === 'simulate_inflow') {
            $senderName = sanitizeString(post('sender_name'));
            $amount     = (float)post('amount');
            $ref        = 'SIM_' . time() . '_' . rand(100, 999);

            if ($amount <= 0 || empty($senderName)) {
                throw new Exception("Amount and Sender Name are required.");
            }

            Database::execute(
                "INSERT INTO gaps_transactions (gaps_reference, transaction_type, amount, sender_name, narration, transaction_date, matched)
                 VALUES (?, 'credit', ?, ?, ?, NOW(), 0)",
                [$ref, $amount, $senderName, "Simulated transfer from $senderName"]
            );

            auditLog('GAPS_INFLOW_SIMULATED', "Simulated GAPS inflow of " . formatMoney($amount) . " from $senderName", 'gaps_transactions', 0);
            $success = "Simulated GAPS credit inflow added successfully.";
        }

        elseif ($action === 'reject_intent') {
            $intentId = (int)post('intent_id');
            if ($intentId > 0) {
                Database::execute(
                    "UPDATE deposit_intents SET status = 'rejected', updated_at = NOW() WHERE id = ?",
                    [$intentId]
                );
                auditLog('DEPOSIT_INTENT_REJECTED', "Rejected deposit intent ID $intentId", 'deposit_intents', $intentId);
                $success = "Deposit intent status marked rejected.";
            }
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch pending intents
$pendingIntents = Database::fetchAll(
    "SELECT di.*, u.username 
     FROM deposit_intents di
     LEFT JOIN users u ON di.user_id = u.id
     WHERE di.status = 'pending' AND di.expires_at > NOW()
     ORDER BY di.created_at DESC"
);

// Fetch unmatched credit transactions from GAPS
$unmatchedGaps = Database::fetchAll(
    "SELECT * FROM gaps_transactions 
     WHERE transaction_type = 'credit' AND matched = 0 
     ORDER BY transaction_date DESC"
);

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

            <div class="row g-4">
                
                <!-- Unmatched GAPS statement credits -->
                <div class="col-lg-6">
                    <div class="admin-table-wrapper">
                        <div class="p-3 border-bottom bg-white">
                            <h5 class="fw-bold mb-0 text-danger"><i class="fas fa-bank me-2"></i>Unmatched GAPS Inflows</h5>
                            <span class="small text-muted">Bank statement credits fetched via API that did not match a deposit intent.</span>
                        </div>
                        <div class="table-responsive" style="max-height: 450px; overflow-y: auto;">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Ref</th>
                                        <th>Sender / Narration</th>
                                        <th>Amount</th>
                                        <th class="text-end">Manual Match</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($unmatchedGaps)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">No unmatched statement credits.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($unmatchedGaps as $g): ?>
                                            <tr>
                                                <td class="small font-monospace"><?= e($g['gaps_reference']) ?></td>
                                                <td>
                                                    <div class="small fw-bold"><?= e($g['sender_name']) ?></div>
                                                    <div class="small text-muted" style="font-size: 11px;"><?= e($g['narration']) ?></div>
                                                </td>
                                                <td class="fw-bold text-success"><?= formatMoney($g['amount']) ?></td>
                                                <td class="text-end">
                                                    <button class="btn btn-sm btn-outline-danger py-1 px-3 btn-match-trigger" 
                                                            data-gaps-id="<?= $g['id'] ?>"
                                                            data-amount="<?= $g['amount'] ?>"
                                                            data-sender="<?= e($g['sender_name']) ?>"
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#matchModal">
                                                        Link Intent
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Pending User Declarations -->
                <div class="col-lg-6">
                    <div class="admin-table-wrapper">
                        <div class="p-3 border-bottom bg-white">
                            <h5 class="fw-bold mb-0 text-warning"><i class="fas fa-clock me-2"></i>Pending User Intents</h5>
                            <span class="small text-muted">Deposit intentions declared by users that are waiting for transfers.</span>
                        </div>
                        <div class="table-responsive" style="max-height: 450px; overflow-y: auto;">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>User Details</th>
                                        <th>Declared Sender</th>
                                        <th>Amount</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($pendingIntents)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">No pending deposit intents found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($pendingIntents as $i): ?>
                                            <tr>
                                                <td>
                                                    <span class="fw-bold">@<?= e($i['username']) ?></span>
                                                    <div class="small text-muted fs-8"><?= date('d M, h:i A', strtotime($i['created_at'])) ?></div>
                                                </td>
                                                <td><span class="small"><?= e($i['sender_name']) ?></span></td>
                                                <td class="fw-bold text-dark"><?= formatMoney($i['amount']) ?></td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1">
                                                        <form method="POST" action="<?= APP_URL ?>/admin/deposits" class="d-inline">
                                                            <?= csrfField() ?>
                                                            <input type="hidden" name="action" value="direct_approve">
                                                            <input type="hidden" name="intent_id" value="<?= $i['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-success py-1 px-2 text-white" onclick="return confirm('Directly approve and credit user wallet for this deposit intent of <?= formatMoney($i['amount']) ?>?')" title="Directly Approve & Credit Wallet">
                                                                <i class="fas fa-check me-1"></i> Approve
                                                            </button>
                                                        </form>
                                                        <form method="POST" action="<?= APP_URL ?>/admin/deposits" class="d-inline">
                                                            <?= csrfField() ?>
                                                            <input type="hidden" name="action" value="reject_intent">
                                                            <input type="hidden" name="intent_id" value="<?= $i['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-light border py-1 px-2" onclick="return confirm('Reject this deposit intent declaration?')" title="Reject Intent">
                                                                <i class="fas fa-times text-danger"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </main>
</div>

<!-- Modal: Match Form -->
<div class="modal fade" id="matchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-16 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
                <h5 class="modal-title fw-bold">Manual Statement Reconciliation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= APP_URL ?>/admin/deposits">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="match_deposit">
                <input type="hidden" id="modal_gaps_id" name="gaps_id" value="">

                <div class="modal-body p-4">
                    <div class="alert alert-light border py-2 px-3 small mb-4">
                        <div class="row">
                            <div class="col-6 text-muted">Statement Sender:</div>
                            <div id="modal_gaps_sender" class="col-6 fw-bold">-</div>
                        </div>
                        <div class="row mt-1">
                            <div class="col-6 text-muted">Amount Credited:</div>
                            <div id="modal_gaps_amount" class="col-6 fw-bold text-success">-</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="intent_id" class="form-label small">Select matching User Intent</label>
                        <select class="form-select" id="intent_id" name="intent_id" required>
                            <option value="" disabled selected>Select matching declaration</option>
                            <?php foreach ($pendingIntents as $i): ?>
                                <option value="<?= $i['id'] ?>" data-amount="<?= $i['amount'] ?>">
                                    @<?= e($i['username']) ?> - Declared: <?= e($i['sender_name']) ?> (₦<?= number_format($i['amount'], 2) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="background-color: #ef4444; border: none;">Match & Credit Wallet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.btn-match-trigger').on('click', function() {
        const id = $(this).attr('data-gaps-id');
        const amount = $(this).attr('data-amount');
        const sender = $(this).attr('data-sender');

        $('#modal_gaps_id').val(id);
        $('#modal_gaps_sender').text(sender);
        $('#modal_gaps_amount').text('₦' + parseFloat(amount).toLocaleString('en-US', { minimumFractionDigits: 2 }));
        
        // Filter dropdown to match amount
        $('#intent_id option').each(function() {
            const optAmt = $(this).attr('data-amount');
            if (optAmt && parseFloat(optAmt) !== parseFloat(amount)) {
                $(this).hide();
            } else {
                $(this).show();
            }
        });
    });
});
</script>
<?php include ADMIN_PATH . '/includes/footer.php'; ?>
