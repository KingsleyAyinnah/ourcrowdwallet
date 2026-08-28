<?php
/**
 * OURCR ONLINE - Buy Airtime (VTpass Integration)
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Buy Airtime';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Verify service is enabled in settings
if (!serviceEnabled('airtime')) {
    setFlash('error', 'Airtime service is temporarily offline.');
    redirectTo('dashboard');
}

$balance = getWalletBalance($user['id']);
$error = '';
$success = '';

// Retrieve last 10 successful recipients
$recipients = Database::fetchAll(
    "SELECT phone, MAX(created_at) as last_used 
     FROM vtpass_transactions 
     WHERE user_id = ? AND status = 'success' AND service_type = 'airtime' AND phone != '' 
     GROUP BY phone 
     ORDER BY last_used DESC LIMIT 10",
    [$user['id']]
);

// Check for session success modal
$successDetails = $_SESSION['last_txn_success'] ?? null;
if ($successDetails) {
    unset($_SESSION['last_txn_success']);
}

// Handle POST: Purchase Airtime
if (isPost()) {
    try {
        requireCsrf();

        $serviceId = sanitizeString(post('service_id'));
        $phone     = sanitizeString(post('phone'));
        $amount    = (float)post('amount');
        $txnPin    = post('transaction_pin');

        $normalizedPhone = Ourcr\Validation::sanitizeNigerianPhone($phone);

        $calc = calculateUserVtuDiscount($serviceId, $amount);
        $amountToPay = $calc['amount_to_pay'];

        if (empty($serviceId) || empty($phone) || $amount <= 0) {
            setFlash('error', 'All fields are required.');
            redirectTo('buy-airtime');
        } elseif ($amount < 50 || $amount > 50000) {
            setFlash('error', 'Airtime purchase must be between ₦50 and ₦50,000.');
            redirectTo('buy-airtime');
        } elseif (empty($normalizedPhone)) {
            setFlash('error', 'Invalid Nigerian phone number. Please check and try again.');
            redirectTo('buy-airtime');
        } elseif (empty($txnPin)) {
            setFlash('error', 'Transaction PIN is required.');
            redirectTo('buy-airtime');
        } elseif (!Ourcr\Wallet::verifyPin($user['id'], $txnPin)) {
            setFlash('error', 'Invalid transaction security PIN. Please try again.');
            redirectTo('buy-airtime');
        } elseif ($balance < $amountToPay) {
            setFlash('error', 'Insufficient wallet balance. (Required: ' . formatMoney($amountToPay) . ')');
            redirectTo('buy-airtime');
        } else {
            $requestId = generateVtpassRequestId();
            Ourcr\VTpass::saveTransaction($user['id'], $serviceId, TXN_TYPE_AIRTIME, $requestId, $amount, $normalizedPhone);

            Database::beginTransaction();
            try {
                $desc  = "Airtime purchase ($serviceId) for $normalizedPhone (Upfront discount: " . formatMoney($calc['user_commission']) . ")";
                $txnId = debitWallet($user['id'], $amountToPay, 0, TXN_TYPE_AIRTIME, $desc, $requestId);

                $result = Ourcr\VTpass::buyAirtime($serviceId, $normalizedPhone, $amount, $requestId);

                if ($result['success'] && $result['code'] === '000') {
                    Ourcr\VTpass::updateTransactionStatus($requestId, TXN_STATUS_SUCCESS, $result['reference']);
                    Database::commit();

                    $discount = (float)($result['data']['discount'] ?? 0);
                    payOngoingReferralBonus($user['id'], $discount);

                    sendNotification(
                        $user['id'],
                        NOTIF_SUCCESS,
                        'Airtime Dispatched!',
                        "Your airtime purchase of " . formatMoney($amount) . " to $normalizedPhone was successful."
                    );

                    $_SESSION['last_txn_success'] = [
                        'title' => 'Airtime Purchased Successfully!',
                        'fields' => [
                            'Provider' => strtoupper($serviceId),
                            'Phone Number' => $normalizedPhone,
                            'Amount' => formatMoney($amount),
                            'Reference' => $result['reference'] ?? 'N/A'
                        ]
                    ];

                    setFlash('success', 'Airtime purchased successfully!');
                    redirectTo('buy-airtime');
                } else {
                    Database::rollback();
                    Ourcr\VTpass::updateTransactionStatus($requestId, TXN_STATUS_FAILED, null, $result['code'] ?? 'ERR');
                    writeLog(LOG_CHAN_VTPASS, 'warning', 'Airtime purchase rejected', [
                        'user_id' => $user['id'], 'service_id' => $serviceId,
                        'code' => $result['code'] ?? 'N/A', 'message' => $result['message'] ?? 'N/A',
                    ]);
                    setFlash('error', getVtuUserFriendlyErrorMessage($result, 'airtime'));
                    redirectTo('buy-airtime');
                }

            } catch (Throwable $ex) {
                Database::rollback();
                writeLog(LOG_CHAN_ERROR, 'error', 'Airtime purchase exception: ' . $ex->getMessage(), [
                    'user_id' => $user['id'], 'file' => $ex->getFile(), 'line' => $ex->getLine(),
                ]);
                setFlash('error', 'A technical error occurred. Your wallet was not debited. Please try again.');
                redirectTo('buy-airtime');
            }
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Airtime page exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine(),
        ]);
        setFlash('error', 'An unexpected error occurred. Please try again.');
        redirectTo('buy-airtime');
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
                    <h4 class="fw-bold">Buy Airtime</h4>
                    <p class="text-muted small">Topup your mobile lines instantly across MTN, Airtel, GLO, and 9Mobile networks.</p>
                </div>

    

                <div class="form-card">
                    <div class="d-flex justify-content-between mb-4 border-bottom pb-3">
                        <span>Wallet Balance:</span>
                        <strong class="text-site-color h5 fw-bold"><?= formatMoney($balance) ?></strong>
                    </div>

                    <div id="inline-alert-container">
                        <?php renderAlerts(); ?>
                    </div>

                    <form method="POST" action="<?= APP_URL ?>/buy-airtime" data-has-pin="true" id="airtimeForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="transaction_pin" value="">
                        <input type="hidden" id="service_id" name="service_id" value="">

                        <!-- Network Selector -->
                        <div class="mb-3">
                            <label class="form-label small">Select Network Provider</label>
                            <div class="network-selector">
                                <div class="network-btn" data-network="mtn">
                                    <i class="fas fa-phone text-warning"></i>
                                    <span>MTN</span>
                                </div>
                                <div class="network-btn" data-network="airtel">
                                    <i class="fas fa-phone text-danger"></i>
                                    <span>Airtel</span>
                                </div>
                                <div class="network-btn" data-network="glo">
                                    <i class="fas fa-phone text-success"></i>
                                    <span>GLO</span>
                                </div>
                                <div class="network-btn" data-network="etisalat">
                                    <i class="fas fa-phone text-info"></i>
                                    <span>9Mobile</span>
                                </div>
                            </div>
                        </div>

                        <!-- Phone -->
                        <div class="mb-3">
                            <label for="phone" class="form-label small">Phone Number</label>
                            <input type="text" class="form-control" id="phone" name="phone" placeholder="e.g. 08031234567" required>
                        </div>

                        <!-- Recipients Dropdown -->
                        <?php if (!empty($recipients)): ?>
                        <div class="mb-3">
                            <label for="recipient_select" class="form-label small">Recipients</label>
                            <select class="form-select" id="recipient_select">
                                <option value="">-- Choose Recent Recipient --</option>
                                <?php foreach ($recipients as $r): ?>
                                    <option value="<?= e($r['phone']) ?>"><?= e($r['phone']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <!-- Amount -->
                        <div class="mb-4">
                            <label for="amount" class="form-label small">Recharge Amount (₦)</label>
                            <input type="number" class="form-control" id="amount" name="amount" min="50" max="50000" placeholder="e.g. 100" required>
                            
                            <!-- Quick amount presets -->
                            <div class="d-flex gap-2 mt-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary preset-amt" data-val="100">₦100</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary preset-amt" data-val="200">₦200</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary preset-amt" data-val="500">₦500</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary preset-amt" data-val="1000">₦1000</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary preset-amt" data-val="2000">₦2000</button>
                            </div>
                        </div>

                        <!-- Live Discount Summary Box -->
                        <div class="card bg-light border-0 p-3 rounded-12 mb-4" id="vtuDiscountSummary" style="display: none;">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Recharge Amount:</span>
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

                        <button type="submit" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0" id="btnSubmitAirtime">
                            Recharge Line <i class="fas fa-bolt ms-2"></i>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>

<!-- Airtime Page scripts -->
<script>
$(document).ready(function() {
    const userSharePct = <?= (float)setting('vtu_commission_user_share', 66) ?>;

    function getAirtimeRate(net) {
        switch((net || '').toLowerCase()) {
            case 'airtel': return 0.034;
            case 'glo': return 0.04;
            case 'etisalat': case '9mobile': return 0.04;
            case 'mtn': default: return 0.03;
        }
    }

    function updateAirtimeSummary() {
        const serviceId = $('#service_id').val() || 'mtn';
        const amount = parseFloat($('#amount').val()) || 0;
        const box = $('#vtuDiscountSummary');
        
        if (amount >= 50) {
            const rate = getAirtimeRate(serviceId);
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
            fetch('<?= APP_URL ?>/api/vtpass?action=calculate_discount&serviceType=airtime&serviceId=' + encodeURIComponent(serviceId) + '&amount=' + amount)
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

    // Network Button clicks
    $('.network-btn').on('click', function() {
        $('.network-btn').removeClass('selected');
        $(this).addClass('selected');
        const net = $(this).attr('data-network') || $(this).data('network');
        $('#service_id').val(net);
        updateAirtimeSummary();
    });

    // Preset Amount clicks
    $('.preset-amt').on('click', function() {
        const val = $(this).attr('data-val') || $(this).data('val');
        $('#amount').val(val);
        updateAirtimeSummary();
    });

    $('#service_id, #amount').on('change input', updateAirtimeSummary);

    // Default select MTN on page load if no network selected
    if (!$('#service_id').val()) {
        $('.network-btn[data-network="mtn"]').click();
    }

    // Form submit loading indicator & button disabling
    $('#airtimeForm').on('submit', function() {
        const $btn = $('#btnSubmitAirtime');
        $btn.prop('disabled', true).addClass('disabled opacity-50')
            .html('<i class="fas fa-spinner fa-spin me-2"></i> Processing Transaction...');
    });

    // Mobile Prefix auto-detection
    $('#phone').on('input', function() {
        const val = $(this).val().trim();
        if (val.length >= 4) {
            const prefix = val.substring(0, 4);
            const mtn = ['0803', '0806', '0703', '0706', '0813', '0816', '0810', '0814', '0903', '0906'];
            const airtel = ['0802', '0808', '0701', '0812', '0708', '0902', '0907', '0901'];
            const glo = ['0805', '0807', '0705', '0815', '0811', '0905'];
            const etisalat = ['0809', '0909', '0817', '0818', '0908'];

            if (mtn.includes(prefix)) {
                $('.network-btn[data-network="mtn"]').click();
            } else if (airtel.includes(prefix)) {
                $('.network-btn[data-network="airtel"]').click();
            } else if (glo.includes(prefix)) {
                $('.network-btn[data-network="glo"]').click();
            } else if (etisalat.includes(prefix)) {
                $('.network-btn[data-network="etisalat"]').click();
            }
        }
    });

    // Auto-fill phone and trigger prefix detection when choosing saved recipient
    $('#recipient_select').on('change', function() {
        const val = $(this).val();
        if (val) {
            $('#phone').val(val).trigger('input');
        }
    });
});
</script>

<!-- Success Details Modal -->
<?php if ($successDetails): ?>
<div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-16 border-0 shadow-lg position-relative" style="overflow: hidden;">
            <div class="modal-body p-4 text-center">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="mb-3 mt-2">
                    <i class="fas fa-check-circle text-danger fa-4x animate__animated animate__bounceIn" style="color: #dc3545;"></i>
                </div>
                <h4 class="fw-bold mb-2"><?= e($successDetails['title']) ?></h4>
                <p class="text-muted small mb-4">Your transaction was processed successfully.</p>
                
                <div class="bg-light p-3 rounded-12 text-start mb-4">
                    <?php foreach ($successDetails['fields'] as $label => $value): ?>
                        <div class="d-flex justify-content-between mb-2 border-bottom pb-2 border-light">
                            <span class="text-muted small"><?= e($label) ?>:</span>
                            <span class="fw-bold text-dark small"><?= e($value) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <button type="button" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0" data-bs-dismiss="modal">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>
<script>
$(document).ready(function() {
    var myModal = new bootstrap.Modal(document.getElementById('successModal'));
    myModal.show();
});
</script>
<?php endif; ?>
