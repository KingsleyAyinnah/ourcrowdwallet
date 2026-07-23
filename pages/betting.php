<?php
/**
 * OURCR ONLINE - Fund Betting Wallet (VTpass Integration)
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Betting Wallets';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Verify service is enabled
if (!serviceEnabled('betting')) {
    setFlash('error', 'Betting topup service is temporarily offline.');
    redirectTo('dashboard');
}

$balance = getWalletBalance($user['id']);
$error = '';
$success = '';

// Handle POST: Process Betting Topup
if (isPost()) {
    try {
        requireCsrf();

        $serviceId  = sanitizeString(post('service_id'));
        $customerId = sanitizeString(post('customer_id'));
        $amount     = (float)post('amount');
        $phone      = sanitizeString(post('phone'));
        $txnPin     = post('transaction_pin');

        $calc = calculateUserVtuDiscount($serviceId, $amount);
        $amountToPay = $calc['amount_to_pay'];

        if (empty($serviceId) || empty($customerId) || empty($phone) || $amount <= 0) {
            setFlash('error', 'All fields are required. Please select a provider, customer ID, phone, and amount.');
            redirectTo('betting');
        } elseif ($amount < 100 || $amount > 50000) {
            setFlash('error', 'Betting topup must be between ₦100 and ₦50,000.');
            redirectTo('betting');
        } elseif (empty($txnPin)) {
            setFlash('error', 'Transaction PIN is required.');
            redirectTo('betting');
        } elseif (!Ourcr\Wallet::verifyPin($user['id'], $txnPin)) {
            setFlash('error', 'Invalid transaction security PIN. Please try again.');
            redirectTo('betting');
        } elseif ($balance < $amountToPay) {
            setFlash('error', 'Insufficient wallet balance. (Required: ' . formatMoney($amountToPay) . ')');
            redirectTo('betting');
        } else {
            $requestId = generateVtpassRequestId();
            Ourcr\VTpass::saveTransaction($user['id'], $serviceId, TXN_TYPE_BETTING, $requestId, $amount, $phone, [
                'customer_id' => $customerId,
            ]);

            Database::beginTransaction();
            try {
                $desc  = "Betting wallet funding ($serviceId) for ID $customerId (Upfront discount: " . formatMoney($calc['user_commission']) . ")";
                $txnId = debitWallet($user['id'], $amountToPay, 0, TXN_TYPE_BETTING, $desc, $requestId);

                $result = Ourcr\VTpass::fundBetting($serviceId, $customerId, $amount, $requestId);

                if ($result['success'] && $result['code'] === '000') {
                    Ourcr\VTpass::updateTransactionStatus($requestId, TXN_STATUS_SUCCESS, $result['reference']);
                    Database::commit();

                    $discount = (float)($result['data']['discount'] ?? 0);
                    payOngoingReferralBonus($user['id'], $discount);

                    sendNotification(
                        $user['id'],
                        NOTIF_SUCCESS,
                        'Betting Wallet Funded!',
                        "Your topup of " . formatMoney($amount) . " to betting ID $customerId was successful."
                    );

                    setFlash('success', 'Betting wallet funded successfully!');
                    redirectTo('dashboard');
                } else {
                    Database::rollback();
                    Ourcr\VTpass::updateTransactionStatus($requestId, TXN_STATUS_FAILED, null, $result['code'] ?? 'ERR');
                    writeLog(LOG_CHAN_VTPASS, 'warning', 'Betting topup rejected', [
                        'user_id' => $user['id'], 'service_id' => $serviceId,
                        'code' => $result['code'] ?? 'N/A', 'message' => $result['message'] ?? 'N/A',
                    ]);
                    setFlash('error', 'Betting wallet funding failed. Your wallet was not debited. Please try again or contact support.');
                    redirectTo('betting');
                }

            } catch (Throwable $ex) {
                Database::rollback();
                writeLog(LOG_CHAN_ERROR, 'error', 'Betting topup exception: ' . $ex->getMessage(), [
                    'user_id' => $user['id'], 'file' => $ex->getFile(), 'line' => $ex->getLine(),
                ]);
                setFlash('error', 'A technical error occurred. Your wallet was not debited. Please try again.');
                redirectTo('betting');
            }
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Betting page exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine(),
        ]);
        setFlash('error', 'An unexpected error occurred. Please try again.');
        redirectTo('betting');
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
                    <h4 class="fw-bold">Betting Topup</h4>
                    <p class="text-muted small">Fund your betting accounts instantly across popular sports gaming sites.</p>
                </div>

                <div class="form-card">
                    <div class="d-flex justify-content-between mb-4 border-bottom pb-3">
                        <span>Wallet Balance:</span>
                        <strong class="text-site-color h5 fw-bold"><?= formatMoney($balance) ?></strong>
                    </div>

                    <div id="inline-alert-container">
                        <?php renderAlerts(); ?>
                    </div>

                    <form method="POST" action="<?= APP_URL ?>/betting" data-has-pin="true" id="bettingForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="transaction_pin" value="">
                        <input type="hidden" id="service_id" name="service_id" value="">

                        <!-- Betting Operator Selector -->
                        <div class="mb-3">
                            <label class="form-label small">Select Betting Platform</label>
                            <div class="network-selector">
                                <div class="network-btn" data-platform="bet9ja">
                                    <i class="fas fa-futbol text-success"></i>
                                    <span>Bet9ja</span>
                                </div>
                                <div class="network-btn" data-platform="betking">
                                    <i class="fas fa-futbol text-primary"></i>
                                    <span>BetKing</span>
                                </div>
                                <div class="network-btn" data-platform="sportybet">
                                    <i class="fas fa-futbol text-danger"></i>
                                    <span>Sportybet</span>
                                </div>
                                <div class="network-btn" data-platform="1xbet">
                                    <i class="fas fa-futbol text-info"></i>
                                    <span>1xBet</span>
                                </div>
                            </div>
                        </div>

                        <!-- Customer Account ID -->
                        <div class="mb-3">
                            <label for="customer_id" class="form-label small">Betting Account ID</label>
                            <input type="text" class="form-control" id="customer_id" name="customer_id" placeholder="Enter your customer ID" required>
                        </div>

                        <!-- Amount -->
                        <div class="mb-3">
                            <label for="amount" class="form-label small">Topup Amount (₦)</label>
                            <input type="number" class="form-control" id="amount" name="amount" min="100" max="100000" placeholder="Min: 100" required>
                            
                            <!-- Quick amount presets -->
                            <div class="d-flex gap-2 mt-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary preset-amt" data-val="500">₦500</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary preset-amt" data-val="1000">₦1000</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary preset-amt" data-val="2000">₦2000</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary preset-amt" data-val="5000">₦5000</button>
                            </div>
                        </div>

                        <!-- Phone -->
                        <div class="mb-3">
                            <label for="phone" class="form-label small">Notification Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone" placeholder="e.g. 08031234567" required>
                        </div>

                        <!-- Live Discount Summary Box -->
                        <div class="card bg-light border-0 p-3 rounded-12 mb-4" id="vtuDiscountSummary" style="display: none;">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Topup Amount:</span>
                                <span class="fw-bold text-dark" id="summaryAmount">₦0.00</span>
                            </div>
                            <div class="d-flex justify-content-between small text-success mb-1">
                                <span>Commission Discount:</span>
                                <span class="fw-bold" id="summaryDiscount">-₦0.00</span>
                            </div>
                            <hr class="my-2 text-muted" style="opacity:0.15;">
                            <div class="d-flex justify-content-between fw-bold text-dark">
                                <span>Amount to Pay:</span>
                                <span class="text-danger fs-6" id="summaryPay">₦0.00</span>
                            </div>
                        </div>

                        <!-- PIN Input -->
                        <div class="mb-4 text-center">
                            <label class="form-label d-block text-center">Security Transaction PIN</label>
                            <div class="pin-input-group">
                                <input type="password" class="pin-digit" maxlength="1" required>
                                <input type="password" class="pin-digit" maxlength="1" required>
                                <input type="password" class="pin-digit" maxlength="1" required>
                                <input type="password" class="pin-digit" maxlength="1" required>
                            </div>
                        </div>

                        <!-- Total Amount Payable Display Above Button -->
                        <div class="card border-0 rounded-12 p-3 mb-3 text-center" style="background: rgba(220, 38, 38, 0.05); border: 1px dashed rgba(220, 38, 38, 0.3)!important;">
                            <div class="small text-muted fw-bold text-uppercase" style="letter-spacing: 0.5px;">Total Amount</div>
                            <div class="h3 fw-bold text-danger mb-0" id="displayTotalPayable">₦0.00</div>
                        </div>

                        <button type="submit" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0 disabled opacity-50" id="btnSubmitBetting" disabled>
                            Coming Soon <i class="fas fa-clock ms-2"></i>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>

<!-- Betting scripts -->
<script>
$(document).ready(function() {
    const userSharePct = <?= (float)setting('vtu_commission_user_share', 66) ?>;

    function updateBettingSummary() {
        const serviceId = $('#service_id').val() || 'bet9ja';
        const amount = parseFloat($('#amount').val()) || 0;
        const box = $('#vtuDiscountSummary');
        
        if (amount >= 100) {
            const rate = 0.015;
            const rawComm = amount * rate;
            const userComm = Math.round(rawComm * 0.95 * (userSharePct / 100) * 100) / 100;
            const payAmount = Math.round((amount - userComm) * 100) / 100;

            const fmtAmt = '₦' + amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            const fmtComm = '₦' + userComm.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            const fmtPay = '₦' + payAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

            $('#displayTotalPayable').text(fmtPay);
            if (userComm > 0) {
                $('#summaryAmount').text(fmtAmt);
                $('#summaryDiscount').text('- ' + fmtComm);
                $('#summaryPay').text(fmtPay);
                box.slideDown(200);
            } else {
                box.slideUp(150);
            }

            // Sync with backend API calculation
            fetch('<?= APP_URL ?>/api/vtpass?action=calculate_discount&serviceType=betting&serviceId=' + encodeURIComponent(serviceId) + '&amount=' + amount)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        $('#displayTotalPayable').text(data.formatted_pay);
                        if (data.user_commission > 0) {
                            $('#summaryAmount').text(fmtAmt);
                            $('#summaryDiscount').text('- ' + data.formatted_comm);
                            $('#summaryPay').text(data.formatted_pay);
                            box.slideDown(200);
                        }
                    }
                }).catch(() => {});
        } else {
            const fmtAmt = '₦' + amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            $('#displayTotalPayable').text(fmtAmt);
            box.slideUp(150);
        }
    }

    // Network / Platform Button clicks
    $('.network-btn').on('click', function() {
        $('.network-btn').removeClass('selected');
        $(this).addClass('selected');
        const p = $(this).attr('data-platform') || $(this).data('platform') || $(this).data('network');
        $('#service_id').val(p);
        updateBettingSummary();
    });

    // Preset Amount clicks
    $('.preset-amt').on('click', function() {
        const val = $(this).attr('data-val') || $(this).data('val');
        $('#amount').val(val);
        updateBettingSummary();
    });

    $('#service_id, #amount').on('change input', updateBettingSummary);

    // Default select Bet9ja on page load if no platform selected
    if (!$('#service_id').val()) {
        $('.network-btn[data-platform="bet9ja"]').click();
    }

    // Form submit loading indicator — button is disabled (Coming Soon), no-op
    $('#bettingForm').on('submit', function(e) {
        e.preventDefault();
    });
});
</script>
