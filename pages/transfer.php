<?php
/**
 * OURCR ONLINE - Wallet Transfer Page
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Transfer Funds';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

$balance = getWalletBalance($user['id']);
$minTransfer = (float)setting('min_transfer', 100);
$maxTransfer = (float)setting('max_transfer', 1000000);
$transferFeePercent = (float)setting('transfer_fee', 0);

$error = '';
$success = '';

// Handle POST: Process Transfer
if (isPost()) {
    try {
        requireCsrf();

        $recipient   = sanitizeString(post('recipient'));
        $amount      = (float)post('amount');
        $description = sanitizeString(post('description'));
        $txnPin      = post('transaction_pin');

        $transferFee = round(($amount * $transferFeePercent) / 100, 2);

        if (empty($recipient)) {
            setFlash('error', 'Recipient username or email is required.');
            redirectTo('transfer');
        } elseif ($amount < $minTransfer || $amount > $maxTransfer) {
            setFlash('error', 'Transfer amount must be between ' . formatMoney($minTransfer) . ' and ' . formatMoney($maxTransfer) . '.');
            redirectTo('transfer');
        } elseif (empty($txnPin)) {
            setFlash('error', 'Transaction PIN is required.');
            redirectTo('transfer');
        } elseif (!Ourcr\Wallet::verifyPin($user['id'], $txnPin)) {
            setFlash('error', 'Invalid transaction security PIN. Please try again.');
            redirectTo('transfer');
        } elseif ($balance < ($amount + $transferFee)) {
            setFlash('error', 'Insufficient balance. You need ' . formatMoney($amount + $transferFee) . ' (including ' . formatMoney($transferFee) . ' transfer fee).');
            redirectTo('transfer');
        } else {
            try {
                $result = Ourcr\Wallet::transfer(
                    $user['id'],
                    $recipient,
                    $amount,
                    $description,
                    $txnPin
                );

                if ($result['success']) {
                    setFlash('success', 'Transfer completed successfully.');
                    redirectTo('wallet');
                } else {
                    // Wallet::transfer returns safe user-friendly messages
                    setFlash('error', $result['message']);
                    redirectTo('transfer');
                }
            } catch (Throwable $ex) {
                writeLog(LOG_CHAN_ERROR, 'error', 'Transfer exception: ' . $ex->getMessage(), [
                    'user_id' => $user['id'], 'file' => $ex->getFile(), 'line' => $ex->getLine(),
                ]);
                setFlash('error', 'Transfer could not be completed. Please try again or contact support.');
                redirectTo('transfer');
            }
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Transfer page exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine(),
        ]);
        setFlash('error', 'An unexpected error occurred. Please try again.');
        redirectTo('transfer');
    }
}

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            <div class="service-page fade-in-up">
                
                <div class="mb-4">
                    <h4 class="fw-bold">Transfer Funds</h4>
                    <p class="text-muted small">Transfer money instantly to another user on the OURCR network.</p>
                </div>

                <div class="form-card">
                    <div class="d-flex justify-content-between mb-4 border-bottom pb-3">
                        <span>Wallet Balance:</span>
                        <strong class="text-site-color h5 fw-bold"><?= formatMoney($balance) ?></strong>
                    </div>

                    <div id="inline-alert-container">
                        <?php renderAlerts(); ?>
                    </div>

                    <form method="POST" action="<?= APP_URL ?>/transfer" data-has-pin="true" id="transferForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="transaction_pin" value="">

                        <!-- Recipient -->
                        <div class="mb-3">
                            <label for="recipient" class="form-label">Recipient Username or Email</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                <input type="text" 
                                       class="form-control" 
                                       id="recipient" 
                                       name="recipient" 
                                       placeholder="Username or email address" 
                                       required>
                            </div>
                            <div id="recipient-details" class="mt-2 small text-success fw-bold" style="display: none;"></div>
                        </div>

                        <!-- Amount -->
                        <div class="mb-3">
                            <label for="amount" class="form-label">Amount (₦)</label>
                            <input type="number" 
                                   class="form-control form-control-lg" 
                                   id="amount" 
                                   name="amount" 
                                   min="<?= $minTransfer ?>" 
                                   max="<?= $maxTransfer ?>" 
                                   placeholder="Min: <?= $minTransfer ?>" 
                                   required>
                            <div class="form-text small">Transfer transaction fee: <strong><?= e($transferFeePercent) ?>%</strong></div>
                        </div>

                        <!-- Description -->
                        <div class="mb-4">
                            <label for="description" class="form-label">Description (Optional)</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="description" 
                                   name="description" 
                                   placeholder="What's this transfer for?">
                        </div>

                        <!-- Transaction PIN Input -->
                        <div class="mb-4 text-center">
                            <label class="form-label d-block text-center">Security Transaction PIN</label>
                            <div class="pin-input-group">
                                <input type="password" class="pin-digit" maxlength="1" required>
                                <input type="password" class="pin-digit" maxlength="1" required>
                                <input type="password" class="pin-digit" maxlength="1" required>
                                <input type="password" class="pin-digit" maxlength="1" required>
                            </div>
                            <span class="small text-muted">Enter your secret 4-digit security PIN to authorize.</span>
                        </div>

                        <!-- Total Amount Payable Display Above Button -->
                        <div class="card border-0 rounded-12 p-3 mb-3 text-center" style="background: rgba(220, 38, 38, 0.05); border: 1px dashed rgba(220, 38, 38, 0.3)!important;">
                            <div class="small text-muted fw-bold text-uppercase" style="letter-spacing: 0.5px;">Total Amount</div>
                            <div class="h3 fw-bold text-danger mb-0" id="displayTotalPayable">₦0.00</div>
                        </div>

                        <button type="submit" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0" id="btnSubmitTransfer">
                            Transfer Funds <i class="fas fa-paper-plane ms-2"></i>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>

<!-- Recipient Verification AJAX Script -->
<script>
$(document).ready(function() {
    const feePct = <?= (float)$transferFeePercent ?>;
    
    $('#amount').on('input change', function() {
        const amt = parseFloat($(this).val()) || 0;
        const fee = amt * (feePct / 100);
        const total = amt + fee;
        $('#displayTotalPayable').text('₦' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    });

    $('#transferForm').on('submit', function() {
        const $btn = $('#btnSubmitTransfer');
        $btn.prop('disabled', true).addClass('disabled opacity-50')
            .html('<i class="fas fa-spinner fa-spin me-2"></i> Processing Transfer...');
    });

    let lookupTimer;
    
    $('#recipient').on('input', function() {
        clearTimeout(lookupTimer);
        const val = $(this).val().trim();
        const $details = $('#recipient-details');
        
        if (val.length < 3) {
            $details.hide().text('');
            return;
        }

        $details.show().text('Checking receiver details...');

        lookupTimer = setTimeout(function() {
            $.ajax({
                url: '<?= APP_URL ?>/api/wallet.php?action=verify_recipient',
                method: 'POST',
                data: { recipient: val },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $details.removeClass('text-danger').addClass('text-success')
                                .html('<i class="fas fa-check-circle me-1"></i> Beneficiary Found: ' + response.full_name);
                    } else {
                        $details.removeClass('text-success').addClass('text-danger')
                                .html('<i class="fas fa-times-circle me-1"></i> ' + response.message);
                    }
                },
                error: function(xhr) {
                    let msg = 'Beneficiary check failed.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    $details.removeClass('text-success').addClass('text-danger')
                            .html('<i class="fas fa-times-circle me-1"></i> ' + msg);
                }
            });
        }, 500); // debounce input
    });
});
</script>
