<?php
/**
 * OURCR ONLINE - Buy Data Bundle (VTpass Integration)
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Buy Data';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Verify service is enabled
if (!serviceEnabled('data')) {
    setFlash('error', 'Data service is temporarily offline.');
    redirectTo('dashboard');
}

$balance = getWalletBalance($user['id']);
$error = '';
$success = '';

// Retrieve last 10 successful recipients
$recipients = Database::fetchAll(
    "SELECT phone, MAX(created_at) as last_used 
     FROM vtpass_transactions 
     WHERE user_id = ? AND status = 'success' AND service_type = 'data' AND phone != '' 
     GROUP BY phone 
     ORDER BY last_used DESC LIMIT 10",
    [$user['id']]
);

// Check for session success modal
$successDetails = $_SESSION['last_txn_success'] ?? null;
if ($successDetails) {
    unset($_SESSION['last_txn_success']);
}

// Handle POST: Process Data Purchase
if (isPost()) {
    try {
        requireCsrf();

        $serviceId     = sanitizeString(post('service_id'));
        $variationCode = sanitizeString(post('variation_code'));
        $phone         = sanitizeString(post('phone'));
        $amount        = (float)post('amount');
        $txnPin        = post('transaction_pin');

        $normalizedPhone = Ourcr\Validation::sanitizeNigerianPhone($phone);

        $calc = calculateUserVtuDiscount($serviceId, $amount, 1, $variationCode);
        $amountToPay = $calc['amount_to_pay'];

        if (empty($serviceId) || empty($variationCode) || empty($phone) || $amount <= 0) {
            setFlash('error', 'All fields are required. Please select a network, plan, and enter a phone number.');
            redirectTo('buy-data');
        } elseif (empty($normalizedPhone)) {
            setFlash('error', 'Invalid Nigerian phone number. Please check and try again.');
            redirectTo('buy-data');
        } elseif (empty($txnPin)) {
            setFlash('error', 'Transaction PIN is required.');
            redirectTo('buy-data');
        } elseif (!Ourcr\Wallet::verifyPin($user['id'], $txnPin)) {
            setFlash('error', 'Invalid transaction security PIN. Please try again.');
            redirectTo('buy-data');
        } elseif ($balance < $amountToPay) {
            setFlash('error', 'Insufficient wallet balance. (Required: ' . formatMoney($amountToPay) . ')');
            redirectTo('buy-data');
        } else {
            $requestId = generateVtpassRequestId();
            Ourcr\VTpass::saveTransaction($user['id'], $serviceId, TXN_TYPE_DATA, $requestId, $amount, $normalizedPhone, [
                'variation_code' => $variationCode
            ]);

            Database::beginTransaction();
            try {
                $desc  = "Data purchase ($variationCode) for $normalizedPhone (Upfront discount: " . formatMoney($calc['user_commission']) . ")";
                $txnId = debitWallet($user['id'], $amountToPay, 0, TXN_TYPE_DATA, $desc, $requestId);

                $result = Ourcr\VTpass::buyData($serviceId, $variationCode, $normalizedPhone, $amount, $requestId);

                if ($result['success'] && $result['code'] === '000') {
                    Ourcr\VTpass::updateTransactionStatus($requestId, TXN_STATUS_SUCCESS, $result['reference']);
                    Database::commit();

                    $discount = (float)($result['data']['discount'] ?? 0);
                    payOngoingReferralBonus($user['id'], $discount);

                    sendNotification(
                        $user['id'],
                        NOTIF_SUCCESS,
                        'Data Dispatched!',
                        "Your data bundle purchase of " . formatMoney($amount) . " to $normalizedPhone was successful."
                    );

                    $_SESSION['last_txn_success'] = [
                        'title' => 'Data Bundle Purchased Successfully!',
                        'fields' => [
                            'Network' => strtoupper(str_replace('-data', '', $serviceId)),
                            'Plan Code' => $variationCode,
                            'Phone Number' => $normalizedPhone,
                            'Amount' => formatMoney($amount),
                            'Reference' => $result['reference'] ?? 'N/A'
                        ]
                    ];

                    setFlash('success', 'Data bundle purchased successfully!');
                    redirectTo('buy-data');
                } else {
                    Database::rollback();
                    Ourcr\VTpass::updateTransactionStatus($requestId, TXN_STATUS_FAILED, null, $result['code'] ?? 'ERR');
                    writeLog(LOG_CHAN_VTPASS, 'warning', 'Data purchase rejected', [
                        'user_id' => $user['id'], 'service_id' => $serviceId,
                        'code' => $result['code'] ?? 'N/A', 'message' => $result['message'] ?? 'N/A',
                    ]);
                    setFlash('error', 'Data purchase failed. Your wallet was not debited. Please try again or contact support.');
                    redirectTo('buy-data');
                }

            } catch (Throwable $ex) {
                Database::rollback();
                writeLog(LOG_CHAN_ERROR, 'error', 'Data purchase exception: ' . $ex->getMessage(), [
                    'user_id' => $user['id'], 'file' => $ex->getFile(), 'line' => $ex->getLine(),
                ]);
                setFlash('error', 'A technical error occurred. Your wallet was not debited. Please try again.');
                redirectTo('buy-data');
            }
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Data page exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine(),
        ]);
        setFlash('error', 'An unexpected error occurred. Please try again.');
        redirectTo('buy-data');
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
                    <h4 class="fw-bold">Buy Data Bundle</h4>
                    <p class="text-muted small">Purchase mobile high-speed internet data bundles across all networks.</p>
                </div>

                <div class="form-card">
                    <div class="d-flex justify-content-between mb-4 border-bottom pb-3">
                        <span>Wallet Balance:</span>
                        <strong class="text-site-color h5 fw-bold"><?= formatMoney($balance) ?></strong>
                    </div>

                    <div id="inline-alert-container">
                        <?php renderAlerts(); ?>
                    </div>

                    <form method="POST" action="<?= APP_URL ?>/buy-data" data-has-pin="true" id="dataForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="transaction_pin" value="">
                        <input type="hidden" id="service_id" name="service_id" value="">
                        <input type="hidden" id="amount" name="amount" value="0">

                        <!-- Network Selector -->
                        <div class="mb-3">
                            <label class="form-label small">Select Network Provider</label>
                            <div class="network-selector">
                                <div class="network-btn" data-network="mtn-data">
                                    <i class="fas fa-wifi text-warning"></i>
                                    <span>MTN</span>
                                </div>
                                <div class="network-btn" data-network="airtel-data">
                                    <i class="fas fa-wifi text-danger"></i>
                                    <span>Airtel</span>
                                </div>
                                <div class="network-btn" data-network="glo-data">
                                    <i class="fas fa-wifi text-success"></i>
                                    <span>GLO</span>
                                </div>
                                <div class="network-btn" data-network="etisalat-data">
                                    <i class="fas fa-wifi text-info"></i>
                                    <span>9Mobile</span>
                                </div>
                            </div>
                        </div>

                        <!-- Data Bundle Options dropdown -->
                        <div class="mb-3" id="bundle-container" style="display: none;">
                            <label for="variation_code" class="form-label small">Select Data Plan</label>
                            <select class="form-select" id="variation_code" name="variation_code" required>
                                <option value="" disabled selected>Loading plans...</option>
                            </select>
                        </div>

                        <!-- Phone -->
                        <div class="mb-3">
                            <label for="phone" class="form-label small">Recipient Phone Number</label>
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

                        <!-- Live Discount Summary Box -->
                        <div class="card bg-light border-0 p-3 rounded-12 mb-4" id="vtuDiscountSummary" style="display: none;">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Data Bundle Amount:</span>
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

                        <button type="submit" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0" id="btnSubmitData">
                            Purchase Plan <i class="fas fa-bolt ms-2"></i>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>

<!-- Data Page scripts -->
<script>
$(document).ready(function() {
    let variationsList = [];

    // Network select
    $('.network-btn').on('click', function() {
        const networkId = $(this).attr('data-network');
        $('.network-btn').removeClass('selected');
        $(this).addClass('selected');
        $('#service_id').val(networkId);
        
        // Fetch plans dynamically
        const $select = $('#variation_code');
        const $container = $('#bundle-container');
        
        $container.show();
        $select.html('<option value="" disabled selected>Loading bundles...</option>');
        
        $.ajax({
            url: '<?= APP_URL ?>/api/vtpass.php?action=variations&serviceId=' + networkId,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.variations.length > 0) {
                    variationsList = response.variations;
                    let html = '<option value="" disabled selected>Choose bundle plan</option>';
                    response.variations.forEach(function(item) {
                        html += '<option value="' + item.variation_code + '" data-price="' + item.variation_amount + '">' + item.name + ' - ₦' + item.variation_amount + '</option>';
                    });
                    $select.html(html);
                } else {
                    $select.html('<option value="" disabled selected>No plans available</option>');
                }
            },
            error: function() {
                $select.html('<option value="" disabled selected>Failed to fetch plans</option>');
            }
        });
    });

    const userSharePct = <?= (float)setting('vtu_commission_user_share', 66) ?>;

    function getDataRate(net) {
        switch((net || '').toLowerCase()) {
            case 'airtel-data': return 0.034;
            case 'glo-data': return 0.04;
            case 'etisalat-data': return 0.04;
            case 'mtn-data': default: return 0.03;
        }
    }

    function updateDataSummary() {
        const serviceId = $('#service_id').val() || 'mtn-data';
        const variation = $('#variation_code').val();
        const amount = parseFloat($('#amount').val()) || 0;
        const box = $('#vtuDiscountSummary');
        
        if (amount > 0) {
            const rate = getDataRate(serviceId);
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
            fetch('<?= APP_URL ?>/api/vtpass?action=calculate_discount&serviceType=data&serviceId=' + encodeURIComponent(serviceId) + '&amount=' + amount + '&variation=' + encodeURIComponent(variation || ''))
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

    // Default select MTN Data on page load if no network selected
    if (!$('#service_id').val()) {
        $('.network-btn[data-network="mtn-data"]').click();
    }

    // Form submit loading indicator & button disabling
    $('#dataForm').on('submit', function() {
        const $btn = $('#btnSubmitData');
        $btn.prop('disabled', true).addClass('disabled opacity-50')
            .html('<i class="fas fa-spinner fa-spin me-2"></i> Processing Transaction...');
    });

    // Plan select update hidden amount
    $('#variation_code').on('change', function() {
        const price = $(this).find(':selected').attr('data-price');
        $('#amount').val(price);
        updateDataSummary();
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

            if (mtn.includes(prefix) && !$('.network-btn[data-network="mtn-data"]').hasClass('selected')) {
                $('.network-btn[data-network="mtn-data"]').click();
            } else if (airtel.includes(prefix) && !$('.network-btn[data-network="airtel-data"]').hasClass('selected')) {
                $('.network-btn[data-network="airtel-data"]').click();
            } else if (glo.includes(prefix) && !$('.network-btn[data-network="glo-data"]').hasClass('selected')) {
                $('.network-btn[data-network="glo-data"]').click();
            } else if (etisalat.includes(prefix) && !$('.network-btn[data-network="etisalat-data"]').hasClass('selected')) {
                $('.network-btn[data-network="etisalat-data"]').click();
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
