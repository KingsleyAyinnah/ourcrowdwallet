<?php
/**
 * OURCR ONLINE - Buy Electricity Token (VTpass Integration)
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Electricity Bills';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Verify service is enabled
if (!serviceEnabled('electricity')) {
    setFlash('error', 'Electricity service is temporarily offline.');
    redirectTo('dashboard');
}

$balance = getWalletBalance($user['id']);
$error = '';
$success = '';

// Retrieve last 10 successful unique meter numbers
$rawTxns = Database::fetchAll(
    "SELECT request_payload 
     FROM vtpass_transactions 
     WHERE user_id = ? AND status = 'success' AND service_type = 'electricity'
     ORDER BY created_at DESC LIMIT 50",
    [$user['id']]
);
$recipients = [];
foreach ($rawTxns as $txn) {
    $payload = json_decode($txn['request_payload'], true);
    if (!empty($payload['meter_number'])) {
        $meterNum = trim($payload['meter_number']);
        if (!in_array($meterNum, $recipients) && count($recipients) < 10) {
            $recipients[] = $meterNum;
        }
    }
}

// Check for session success modal
$successDetails = $_SESSION['last_txn_success'] ?? null;
if ($successDetails) {
    unset($_SESSION['last_txn_success']);
}

// Handle POST: Purchase Electricity Token
if (isPost()) {
    try {
        requireCsrf();

        $serviceId = sanitizeString(post('service_id'));
        $meterNo   = sanitizeString(post('meter_no'));
        $meterType = sanitizeString(post('meter_type', 'prepaid'));
        $amount    = (float)post('amount');
        $phone     = sanitizeString(post('phone'));
        $txnPin    = post('transaction_pin');

        $calc = calculateUserVtuDiscount($serviceId, $amount);
        $amountToPay = $calc['amount_to_pay'];

        if (empty($serviceId) || empty($meterNo) || empty($phone) || $amount <= 0) {
            setFlash('error', 'All fields are required.');
            redirectTo('electricity');
        } elseif ($amount < 500 || $amount > 100000) {
            setFlash('error', 'Electricity purchases must be between ₦500 and ₦100,000.');
            redirectTo('electricity');
        } elseif (empty($txnPin)) {
            setFlash('error', 'Transaction PIN is required.');
            redirectTo('electricity');
        } elseif (!Ourcr\Wallet::verifyPin($user['id'], $txnPin)) {
            setFlash('error', 'Invalid transaction security PIN. Please try again.');
            redirectTo('electricity');
        } elseif ($balance < $amountToPay) {
            setFlash('error', 'Insufficient wallet balance. (Required: ' . formatMoney($amountToPay) . ')');
            redirectTo('electricity');
        } else {
            $requestId = generateVtpassRequestId();
            Ourcr\VTpass::saveTransaction($user['id'], $serviceId, TXN_TYPE_ELECTRICITY, $requestId, $amount, $phone, [
                'meter_number' => $meterNo,
                'meter_type'   => $meterType,
            ]);

            Database::beginTransaction();
            try {
                $desc  = "Electricity token purchase ($serviceId) for meter $meterNo (Upfront discount: " . formatMoney($calc['user_commission']) . ")";
                $txnId = debitWallet($user['id'], $amountToPay, 0, TXN_TYPE_ELECTRICITY, $desc, $requestId);

                $result = Ourcr\VTpass::buyElectricity($serviceId, $meterNo, $meterType, $phone, $amount, $requestId);

                if ($result['success'] && $result['code'] === '000') {
                    $token = $result['token'] ?? null;
                    $units = $result['data']['units'] ?? null;
                    Ourcr\VTpass::updateTransactionStatus($requestId, TXN_STATUS_SUCCESS, $result['reference'], null, $token);
                    Database::commit();

                    $discount = (float)($result['data']['discount'] ?? 0);
                    payOngoingReferralBonus($user['id'], $discount);

                    sendNotification(
                        $user['id'],
                        NOTIF_SUCCESS,
                        'Electricity Token Ready!',
                        "Your purchase of " . formatMoney($amount) . " for meter $meterNo was successful."
                    );

                    // Build modal fields
                    $fields = [
                        'Provider'     => strtoupper($serviceId),
                        'Meter Number' => $meterNo,
                        'Meter Type'   => ucfirst($meterType),
                        'Amount Paid'  => formatMoney($amount),
                        'Reference'    => $result['reference'] ?? 'N/A',
                    ];
                    if (!empty($token)) {
                        $fields['Token'] = $token;
                    }
                    if (!empty($units)) {
                        $fields['Units'] = $units . ' kWh';
                    }

                    $_SESSION['last_txn_success'] = [
                        'type'  => 'electricity',
                        'title' => 'Electricity Token Purchased!',
                        'token' => $token ?? null,
                        'fields' => $fields,
                    ];

                    setFlash('success', 'Electricity token purchased successfully!');
                    redirectTo('electricity');
                } else {
                    Database::rollback();
                    Ourcr\VTpass::updateTransactionStatus($requestId, TXN_STATUS_FAILED, null, $result['code'] ?? 'ERR');
                    writeLog(LOG_CHAN_VTPASS, 'warning', 'Electricity purchase rejected', [
                        'user_id' => $user['id'], 'service_id' => $serviceId,
                        'code' => $result['code'] ?? 'N/A', 'message' => $result['message'] ?? 'N/A',
                    ]);
                    setFlash('error', getVtuUserFriendlyErrorMessage($result, 'electricity'));
                    redirectTo('electricity');
                }

            } catch (Throwable $ex) {
                Database::rollback();
                writeLog(LOG_CHAN_ERROR, 'error', 'Electricity purchase exception: ' . $ex->getMessage(), [
                    'user_id' => $user['id'], 'file' => $ex->getFile(), 'line' => $ex->getLine(),
                ]);
                setFlash('error', 'A technical error occurred. Your wallet was not debited. Please try again.');
                redirectTo('electricity');
            }
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Electricity page exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine(),
        ]);
        setFlash('error', 'An unexpected error occurred. Please try again.');
        redirectTo('electricity');
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
                    <h4 class="fw-bold">Electricity Utility Token</h4>
                    <p class="text-muted small">Purchase prepaid electricity units or settle postpaid accounts directly.</p>
                </div>



                <div class="form-card">
                    <div class="d-flex justify-content-between mb-4 border-bottom pb-3">
                        <span>Wallet Balance:</span>
                        <strong class="text-site-color h5 fw-bold"><?= formatMoney($balance) ?></strong>
                    </div>

                    <div id="inline-alert-container">
                        <?php renderAlerts(); ?>
                    </div>

                    <form method="POST" action="<?= APP_URL ?>/electricity" data-has-pin="true" id="electricityForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="transaction_pin" value="">

                        <!-- DisCo Provider Selector -->
                        <div class="mb-3">
                            <label for="service_id" class="form-label small">Select Utility Provider (DisCo)</label>
                            <select class="form-select" id="service_id" name="service_id" required>
                                <option value="" disabled selected>Select Provider</option>
                                <option value="ikeja-electric">Ikeja Electric (IKEDC)</option>
                                <option value="eko-electric">Eko Electric (EKEDC)</option>
                                <option value="abuja-electric">Abuja Electric (AEDC)</option>
                                <option value="kano-electric">Kano Electric (KEDCO)</option>
                                <option value="jos-electric">Jos Electric (JED)</option>
                                <option value="ibadan-electric">Ibadan Electric (IBEDC)</option>
                                <option value="portharcourt-electric">Port Harcourt Electric (PHED)</option>
                                <option value="enugu-electric">Enugu Electric (EEDC)</option>
                                <option value="benin-electric">Benin Electric (BEDC)</option>
                                <option value="kaduna-electric">Kaduna Electric (KAEDCO)</option>
                                <option value="yola-electric">Yola Electric (YEDC)</option>
                                <option value="aba-electric">Aba Power (APLE)</option>
                            </select>
                        </div>

                        <!-- Meter Type (Prepaid/Postpaid) -->
                        <div class="mb-3">
                            <label class="form-label small d-block">Meter Account Type</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="meter_type" id="prepaid" value="prepaid" checked>
                                <label class="btn btn-outline-danger" for="prepaid">Prepaid</label>
                                
                                <input type="radio" class="btn-check" name="meter_type" id="postpaid" value="postpaid">
                                <label class="btn btn-outline-danger" for="postpaid">Postpaid</label>
                            </div>
                        </div>

                        <!-- Meter Number -->
                        <div class="mb-3">
                            <label for="meter_no" class="form-label small">Meter Card Number</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="meter_no" name="meter_no" placeholder="Enter meter number" required>
                                <button class="btn btn-outline-danger" type="button" id="btnVerifyMeter">Verify Meter</button>
                            </div>
                            <div id="meter-details" class="mt-2 small text-success fw-bold" style="display: none;"></div>
                        </div>

                        <!-- Recipients Dropdown -->
                        <?php if (!empty($recipients)): ?>
                        <div class="mb-3">
                            <label for="recipient_select" class="form-label small">Recent Meter Numbers</label>
                            <select class="form-select" id="recipient_select">
                                <option value="">-- Choose Recent Meter --</option>
                                <?php foreach ($recipients as $r): ?>
                                    <option value="<?= e($r) ?>"><?= e($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <!-- Amount -->
                        <div class="mb-3">
                            <label for="amount" class="form-label small">Purchase Amount (₦)</label>
                            <input type="number" class="form-control" id="amount" name="amount" min="500" max="100000" placeholder="Min: 500" required>
                        </div>

                        <!-- Phone -->
                        <div class="mb-3">
                            <label for="phone" class="form-label small">Customer Notification Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone" placeholder="e.g. 08031234567" required>
                        </div>

                        <!-- Live Discount Summary Box -->
                        <div class="card bg-light border-0 p-3 rounded-12 mb-4" id="vtuDiscountSummary" style="display: none;">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Purchase Amount:</span>
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

                        <button type="submit" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0" id="btnSubmitElectricity">
                            Check Out Token <i class="fas fa-bolt ms-2"></i>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>

<!-- Electricity Success Modal -->
<?php if ($successDetails): ?>
<div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-16 border-0 shadow-lg position-relative" style="overflow: hidden;">
            <div class="modal-body p-4 text-center">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="mb-3 mt-2">
                    <i class="fas fa-bolt fa-4x animate__animated animate__bounceIn" style="color: #dc3545;"></i>
                </div>
                <h4 class="fw-bold mb-1"><?= e($successDetails['title']) ?></h4>
                <p class="text-muted small mb-4">Your electricity purchase was successful.</p>

                <?php if (!empty($successDetails['token'])): ?>
                <div class="bg-danger bg-opacity-10 border border-danger rounded-12 p-3 mb-4">
                    <p class="small text-muted mb-1">Your Electricity Token</p>
                    <div class="h4 fw-bold font-monospace mb-1"><?= e($successDetails['token']) ?></div>
                    <button class="btn btn-sm btn-outline-danger" data-copy="<?= e($successDetails['token']) ?>">
                        <i class="fas fa-copy me-1"></i> Copy Token
                    </button>
                    <p class="small text-muted mt-2 mb-0">Enter this code into your meter to credit your units.</p>
                </div>
                <?php else: ?>
                <div class="alert alert-info py-2 small mb-4">
                    <i class="fas fa-info-circle me-1"></i> Postpaid payments do not generate a token. Your bill has been settled.
                </div>
                <?php endif; ?>

                <div class="bg-light p-3 rounded-12 text-start mb-4">
                    <?php foreach ($successDetails['fields'] as $label => $value): ?>
                        <div class="d-flex justify-content-between mb-2 border-bottom pb-2 border-light">
                            <span class="text-muted small"><?= e($label) ?>:</span>
                            <span class="fw-bold text-dark small"><?= e($value) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="btn btn-danger w-100 py-3 rounded-12 fw-bold border-0 text-white bg-site-color" data-bs-dismiss="modal">
                    Done
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Electricity Page scripts -->
<script>
$(document).ready(function() {
    <?php if ($successDetails): ?>
    var myModal = new bootstrap.Modal(document.getElementById('successModal'));
    myModal.show();
    <?php endif; ?>

    const userSharePct = <?= (float)setting('vtu_commission_user_share', 66) ?>;

    function runMeterVerification() {
        const serviceId = $('#service_id').val();
        const meterNo = $('#meter_no').val().trim();
        const meterType = $('input[name="meter_type"]:checked').val();
        const $details = $('#meter-details');

        if (!serviceId) {
            Swal.fire({ icon: 'warning', title: 'Provider required', text: 'Please select an electricity DisCo first.' });
            return;
        }
        if (!meterNo) {
            Swal.fire({ icon: 'warning', title: 'Meter required', text: 'Please enter your meter card number.' });
            return;
        }

        $details.show().removeClass('text-danger').addClass('text-success').text('Verifying meter card accounts...');

        $.ajax({
            url: '<?= APP_URL ?>/api/vtpass.php?action=verify_meter&serviceId=' + encodeURIComponent(serviceId) + '&meterNo=' + encodeURIComponent(meterNo) + '&meterType=' + encodeURIComponent(meterType),
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $details.removeClass('text-danger').addClass('text-success')
                            .html('<i class="fas fa-check-circle me-1"></i> Owner: ' + response.customer_name + '<br><i class="fas fa-map-marker-alt me-1"></i> Addr: ' + response.address);
                } else {
                    $details.removeClass('text-success').addClass('text-danger')
                            .html('<i class="fas fa-times-circle me-1"></i> Verification failed: ' + response.message);
                }
            },
            error: function() {
                $details.removeClass('text-success').addClass('text-danger')
                        .html('<i class="fas fa-times-circle me-1"></i> Verification check failed.');
            }
        });
    }

    function getElecRate(disco) {
        switch((disco || '').toLowerCase()) {
            case 'aba-electric': return 0.017;
            case 'benin-electric': case 'kaduna-electric': return 0.015;
            case 'enugu-electric': return 0.014;
            case 'abuja-electric': case 'yola-electric': return 0.012;
            case 'portharcourt-electric': case 'phed': return 0.011;
            case 'ikeja-electric': case 'eko-electric': return 0.01;
            case 'jos-electric': return 0.009;
            default: return 0.01;
        }
    }

    function updateElecSummary() {
        const serviceId = $('#service_id').val() || 'ikeja-electric';
        const amount = parseFloat($('#amount').val()) || 0;
        const box = $('#vtuDiscountSummary');
        
        if (amount >= 500) {
            const rate = getElecRate(serviceId);
            let rawComm = amount * rate;
            if (serviceId.includes('ikeja') && rawComm > 1500) rawComm = 1500;
            if (serviceId.includes('abuja') && rawComm > 1300) rawComm = 1300;

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
            fetch('<?= APP_URL ?>/api/vtpass?action=calculate_discount&serviceType=electricity&serviceId=' + encodeURIComponent(serviceId) + '&amount=' + amount)
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

    $('#service_id, #amount').on('change input', updateElecSummary);

    // Form submit loading indicator & button disabling
    $('#electricityForm').on('submit', function() {
        const $btn = $('#btnSubmitElectricity');
        $btn.prop('disabled', true).addClass('disabled opacity-50')
            .html('<i class="fas fa-spinner fa-spin me-2"></i> Processing Transaction...');
    });

    // Verify meter
    $('#btnVerifyMeter').on('click', function(e) {
        e.preventDefault();
        runMeterVerification();
    });

    // Auto-fill meter and trigger verification
    $('#recipient_select').on('change', function() {
        const val = $(this).val();
        if (val) {
            $('#meter_no').val(val);
            $('#meter-details').hide().text('');
            runMeterVerification();
        }
    });
});
</script>
