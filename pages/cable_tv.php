<?php
/**
 * OURCR ONLINE - Buy Cable TV Subscription (VTpass Integration)
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Cable TV Subscription';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Verify service is enabled
if (!serviceEnabled('cable')) {
    setFlash('error', 'Cable TV service is temporarily offline.');
    redirectTo('dashboard');
}

$balance = getWalletBalance($user['id']);
$error = '';
$success = '';

// Retrieve last 10 successful unique smartcard numbers
$rawTxns = Database::fetchAll(
    "SELECT service_id, request_payload
     FROM vtpass_transactions
     WHERE user_id = ? AND status = 'success' AND service_type = 'cable_tv'
     ORDER BY created_at DESC LIMIT 50",
    [$user['id']]
);
$recipients = [];
foreach ($rawTxns as $txn) {
    $payload = json_decode($txn['request_payload'], true);
    $smartCard = trim((string) ($payload['smartcard_number'] ?? ''));
    if ($smartCard === '' || isset($recipients[$smartCard])) {
        continue;
    }

    if (count($recipients) >= 10) {
        break;
    }

    $recipients[$smartCard] = [
        'smartcard_number' => $smartCard,
        'service_id'       => trim((string) ($txn['service_id'] ?? '')),
    ];
}
$recipients = array_values($recipients);

// Check for session success modal
$successDetails = $_SESSION['last_txn_success'] ?? null;
if ($successDetails) {
    unset($_SESSION['last_txn_success']);
}

// Handle POST: Process Cable TV Subscription
if (isPost()) {
    try {
        requireCsrf();

        $serviceId     = sanitizeString(post('service_id'));
        $variationCode = sanitizeString(post('variation_code'));
        $smartCardNo   = sanitizeString(post('smart_card_no'));
        $phone         = sanitizeString(post('phone'));
        $amount        = (float)post('amount');
        $txnPin        = post('transaction_pin');

        $calc = calculateUserVtuDiscount($serviceId, $amount, 1, $variationCode);
        $amountToPay = $calc['amount_to_pay'];

        if (empty($serviceId) || empty($variationCode) || empty($smartCardNo) || empty($phone) || $amount <= 0) {
            setFlash('error', 'All fields are required. Please select a provider, plan, and enter your smartcard number.');
            redirectTo('cable-tv');
        } elseif (empty($txnPin)) {
            setFlash('error', 'Transaction PIN is required.');
            redirectTo('cable-tv');
        } elseif (!Ourcr\Wallet::verifyPin($user['id'], $txnPin)) {
            setFlash('error', 'Invalid transaction security PIN. Please try again.');
            redirectTo('cable-tv');
        } elseif ($balance < $amountToPay) {
            setFlash('error', 'Insufficient wallet balance. (Required: ' . formatMoney($amountToPay) . ')');
            redirectTo('cable-tv');
        } else {
            $requestId = generateVtpassRequestId();
            Ourcr\VTpass::saveTransaction($user['id'], $serviceId, TXN_TYPE_CABLE, $requestId, $amount, $phone, [
                'variation_code'   => $variationCode,
                'smartcard_number' => $smartCardNo,
            ]);

            Database::beginTransaction();
            try {
                $desc  = "Cable TV subscription ($variationCode) for card $smartCardNo (Upfront discount: " . formatMoney($calc['user_commission']) . ")";
                $txnId = debitWallet($user['id'], $amountToPay, 0, TXN_TYPE_CABLE, $desc, $requestId);

                $result = Ourcr\VTpass::subscribeCable($serviceId, $variationCode, $smartCardNo, $phone, $amount, $requestId);

                if ($result['success'] && $result['code'] === '000') {
                    Ourcr\VTpass::updateTransactionStatus($requestId, TXN_STATUS_SUCCESS, $result['reference']);
                    Database::commit();

                    $discount = (float)($result['data']['discount'] ?? 0);
                    payOngoingReferralBonus($user['id'], $discount);

                    sendNotification(
                        $user['id'],
                        NOTIF_SUCCESS,
                        'Cable Subscribed!',
                        "Your subscription to $serviceId for $smartCardNo was successful."
                    );

                    $_SESSION['last_txn_success'] = [
                        'title'  => 'Cable TV Subscription Successful!',
                        'fields' => [
                            'Provider'       => strtoupper($serviceId),
                            'Smart Card No.' => $smartCardNo,
                            'Plan'           => $variationCode,
                            'Amount Paid'    => formatMoney($amount),
                            'Reference'      => $result['reference'] ?? 'N/A',
                        ],
                    ];

                    setFlash('success', 'Cable TV subscription successful!');
                    redirectTo('cable-tv');
                } else {
                    Database::rollback();
                    Ourcr\VTpass::updateTransactionStatus($requestId, TXN_STATUS_FAILED, null, $result['code'] ?? 'ERR');
                    writeLog(LOG_CHAN_VTPASS, 'warning', 'Cable TV subscription rejected', [
                        'user_id' => $user['id'], 'service_id' => $serviceId,
                        'code' => $result['code'] ?? 'N/A', 'message' => $result['message'] ?? 'N/A',
                    ]);
                    setFlash('error', getVtuUserFriendlyErrorMessage($result, 'tv'));
                    redirectTo('cable-tv');
                }

            } catch (Throwable $ex) {
                Database::rollback();
                writeLog(LOG_CHAN_ERROR, 'error', 'Cable TV subscription exception: ' . $ex->getMessage(), [
                    'user_id' => $user['id'], 'file' => $ex->getFile(), 'line' => $ex->getLine(),
                ]);
                setFlash('error', 'A technical error occurred. Your wallet was not debited. Please try again.');
                redirectTo('cable-tv');
            }
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Cable TV page exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine(),
        ]);
        setFlash('error', 'An unexpected error occurred. Please try again.');
        redirectTo('cable-tv');
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
                    <h4 class="fw-bold">Cable TV Subscription</h4>
                    <p class="text-muted small">Renew bouquet plans across DStv, GOtv, and Startimes platforms.</p>
                </div>

                <div class="form-card">
                    <div class="d-flex justify-content-between mb-4 border-bottom pb-3">
                        <span>Wallet Balance:</span>
                        <strong class="text-site-color h5 fw-bold"><?= formatMoney($balance) ?></strong>
                    </div>

                    <div id="inline-alert-container">
                        <?php renderAlerts(); ?>
                    </div>

                    <form method="POST" action="<?= APP_URL ?>/cable-tv" data-has-pin="true" id="cableForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="transaction_pin" value="">
                        <input type="hidden" id="service_id" name="service_id" value="">
                        <input type="hidden" id="amount" name="amount" value="0">

                        <!-- Provider Selector -->
                        <div class="mb-3">
                            <label class="form-label small">Select Cable Provider</label>
                            <div class="network-selector">
                                <div class="network-btn" data-provider="dstv">
                                    <i class="fas fa-tv text-primary"></i>
                                    <span>DStv</span>
                                </div>
                                <div class="network-btn" data-provider="gotv">
                                    <i class="fas fa-tv text-danger"></i>
                                    <span>GOtv</span>
                                </div>
                                <div class="network-btn" data-provider="startimes">
                                    <i class="fas fa-tv text-success"></i>
                                    <span>Startimes</span>
                                </div>
                            </div>
                        </div>

                        <!-- Smart Card / IUC Number -->
                        <div class="mb-3">
                            <label for="smart_card_no" class="form-label small">Smartcard / IUC Number</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="smart_card_no" name="smart_card_no" placeholder="Enter smartcard number" required>
                                <button class="btn btn-outline-danger" type="button" id="btnVerifyCard">Verify Account</button>
                            </div>
                            <div id="card-details" class="mt-2 small text-success fw-bold" style="display: none;"></div>
                        </div>

                        <?php if (!empty($recipients)): ?>
                        <div class="mb-3">
                            <label for="recipient_select" class="form-label small">Recipient</label>
                            <select class="form-select" id="recipient_select">
                                <option value="">-- Choose Recent Smartcard / IUC --</option>
                                <?php foreach ($recipients as $recipient): ?>
                                    <option value="<?= e($recipient['smartcard_number']) ?>" data-provider="<?= e($recipient['service_id']) ?>">
                                        <?= e($recipient['smartcard_number']) ?><?= $recipient['service_id'] ? ' - ' . e(strtoupper($recipient['service_id'])) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <!-- Bouquet Selector -->
                        <div class="mb-3" id="bouquet-container" style="display: none;">
                            <label for="variation_code" class="form-label small">Select Bouquet Plan</label>
                            <select class="form-select" id="variation_code" name="variation_code" required>
                                <option value="" disabled selected>Select bouquet plan</option>
                            </select>
                        </div>

                        <!-- Phone -->
                        <div class="mb-3">
                            <label for="phone" class="form-label small">Recipient Phone Number</label>
                            <input type="text" class="form-control" id="phone" name="phone" placeholder="e.g. 08031234567" required>
                        </div>

                        <!-- Live Discount Summary Box -->
                        <div class="card bg-light border-0 p-3 rounded-12 mb-4" id="vtuDiscountSummary" style="display: none;">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Subscription Amount:</span>
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

                        <button type="submit" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0" id="btnSubmitCable">
                            Subscribe Bouquet <i class="fas fa-tv ms-2"></i>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>

<!-- Cable TV Success Modal -->
<?php if ($successDetails): ?>
<div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-16 border-0 shadow-lg position-relative" style="overflow: hidden;">
            <div class="modal-body p-4 text-center">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="mb-3 mt-2">
                    <i class="fas fa-tv fa-4x animate__animated animate__bounceIn" style="color: #dc2626;"></i>
                </div>
                <h4 class="fw-bold mb-1"><?= e($successDetails['title']) ?></h4>
                <p class="text-muted small mb-4">Your cable TV subscription is now active.</p>

                <div class="bg-light p-3 rounded-12 text-start mb-4">
                    <?php foreach ($successDetails['fields'] as $label => $value): ?>
                        <div class="d-flex justify-content-between mb-2 border-bottom pb-2 border-light">
                            <span class="text-muted small"><?= e($label) ?>:</span>
                            <span class="fw-bold text-dark small"><?= e($value) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0" data-bs-dismiss="modal">
                    Done
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

<!-- Cable TV scripts -->
<script>
$(document).ready(function() {
    let serviceId = '';
    let pendingRecipientVerification = false;

    function runCardVerification() {
        const smartCardNo = $('#smart_card_no').val().trim();
        const $details = $('#card-details');

        if (!serviceId) {
            Swal.fire({ icon: 'warning', title: 'Provider required', text: 'Please select a cable provider first.' });
            return;
        }
        if (!smartCardNo) {
            Swal.fire({ icon: 'warning', title: 'Card number required', text: 'Please enter smartcard number.' });
            return;
        }

        $details.show().removeClass('text-danger').addClass('text-success').text('Verifying decoder smartcard number...');

        $.ajax({
            url: '<?= APP_URL ?>/api/vtpass.php?action=verify_card&serviceId=' + encodeURIComponent(serviceId) + '&smartCardNo=' + encodeURIComponent(smartCardNo),
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $details.removeClass('text-danger').addClass('text-success')
                            .html('<i class="fas fa-check-circle me-1"></i> Beneficiary: ' + response.customer_name);
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

    function loadProviderPlans(provider, afterLoad) {
        const $select = $('#variation_code');
        const $container = $('#bouquet-container');

        $container.show();
        $select.html('<option value="" disabled selected>Loading plans...</option>');
        
        $.ajax({
            url: '<?= APP_URL ?>/api/vtpass.php?action=variations&serviceId=' + encodeURIComponent(provider),
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.variations.length > 0) {
                    let html = '<option value="" disabled selected>Select bouquet plan</option>';
                    response.variations.forEach(function(item) {
                        html += '<option value="' + item.variation_code + '" data-price="' + item.variation_amount + '">' + item.name + ' - ₦' + item.variation_amount + '</option>';
                    });
                    $select.html(html);
                } else {
                    $select.html('<option value="" disabled selected>No plans available</option>');
                }

                if (typeof afterLoad === 'function') {
                    afterLoad(response);
                }
            },
            error: function() {
                $select.html('<option value="" disabled selected>Failed to fetch plans</option>');
                if (typeof afterLoad === 'function') {
                    afterLoad(null);
                }
            }
        });
    }

    function selectProvider(provider, afterLoad) {
        if (!provider) {
            return;
        }

        serviceId = provider;
        $('.network-btn').removeClass('selected');
        $('.network-btn[data-provider="' + provider + '"]').addClass('selected');
        $('#service_id').val(provider);
        $('#card-details').hide().text('');
        $('#variation_code').val('');
        $('#amount').val(0);
        updateCableSummary();

        loadProviderPlans(provider, afterLoad);
    }

    // Select provider
    $('.network-btn').on('click', function() {
        selectProvider($(this).attr('data-provider'), function() {
            if (pendingRecipientVerification) {
                pendingRecipientVerification = false;
                runCardVerification();
            }
        });
    });

    const userSharePct = <?= (float)setting('vtu_commission_user_share', 66) ?>;

    function getTvRate(provider) {
        switch((provider || '').toLowerCase()) {
            case 'startimes': return 0.02;
            case 'gotv': case 'dstv': default: return 0.015;
        }
    }

    function updateCableSummary() {
        const serviceId = $('#service_id').val() || 'dstv';
        const variation = $('#variation_code').val();
        const amount = parseFloat($('#amount').val()) || 0;
        const box = $('#vtuDiscountSummary');
        
        if (amount > 0) {
            const rate = getTvRate(serviceId);
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
            fetch('<?= APP_URL ?>/api/vtpass?action=calculate_discount&serviceType=tv&serviceId=' + encodeURIComponent(serviceId) + '&amount=' + amount + '&variation=' + encodeURIComponent(variation || ''))
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

    // Form submit loading indicator & button disabling
    $('#cableForm').on('submit', function() {
        const $btn = $('#btnSubmitCable');
        $btn.prop('disabled', true).addClass('disabled opacity-50')
            .html('<i class="fas fa-spinner fa-spin me-2"></i> Processing Transaction...');
    });

    // Plan select update hidden amount
    $('#variation_code').on('change', function() {
        const price = $(this).find(':selected').attr('data-price');
        $('#amount').val(price);
        updateCableSummary();
    });

    // Verify Smartcard
    $('#btnVerifyCard').on('click', function(e) {
        e.preventDefault();
        runCardVerification();
    });

    $('#recipient_select').on('change', function() {
        const smartCardNo = $(this).val();
        const provider = $(this).find(':selected').attr('data-provider') || '';

        if (!smartCardNo) {
            return;
        }

        $('#smart_card_no').val(smartCardNo);
        $('#card-details').hide().text('');

        if (provider) {
            pendingRecipientVerification = true;
            selectProvider(provider, function() {
                if (pendingRecipientVerification) {
                    pendingRecipientVerification = false;
                    runCardVerification();
                }
            });
            return;
        }

        if (serviceId) {
            runCardVerification();
        }
    });
});
</script>
