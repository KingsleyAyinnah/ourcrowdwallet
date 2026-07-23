<?php
/**
 * OURCR ONLINE - Buy Exam Result Checker / Registration PINs (VTpass Integration)
 *
 * Supported services (per VTpass API documentation):
 *  - waec             : WAEC Result Checker  — qty supported, no billersCode, returns cards[]
 *  - waec-registration: WAEC Registration    — qty supported, no billersCode, returns tokens[]
 *  - jamb             : JAMB UTME & DE       — requires billersCode=profileId, returns Pin string
 *  - neco             : NECO Result Checker  — qty supported, no billersCode
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Exam PINs';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Verify service is enabled
if (!serviceEnabled('exam')) {
    setFlash('error', 'Exam PIN service is temporarily offline.');
    redirectTo('dashboard');
}

$balance = getWalletBalance($user['id']);

// Check for session success modal (PRG pattern)
$successDetails = $_SESSION['last_txn_success'] ?? null;
if ($successDetails) {
    unset($_SESSION['last_txn_success']);
}

// ─── POST Handler: Purchase Exam PINs ─────────────────────────────────────────
if (isPost()) {
    try {
        requireCsrf();

        $serviceId     = sanitizeString(post('service_id'));
        $variationCode = sanitizeString(post('variation_code'));
        $profileCode   = sanitizeString(post('profile_code')); // JAMB Profile ID only
        $quantity      = max(1, min(5, (int)post('quantity', 1)));
        $phone         = sanitizeString(post('phone'));
        $amount        = (float)post('amount');
        $txnPin        = post('transaction_pin');

        // Validate service
        $validServices = ['waec', 'waec-registration', 'jamb'];
        if (empty($serviceId) || !in_array($serviceId, $validServices)) {
            setFlash('error', 'Please select a valid exam body.');
            redirectTo('exam-pins');
        }

        if (empty($variationCode) || empty($phone) || $amount <= 0) {
            setFlash('error', 'All fields are required. Please select a plan and enter your phone number.');
            redirectTo('exam-pins');
        }

        // JAMB requires the Profile ID
        if ($serviceId === 'jamb' && empty($profileCode)) {
            setFlash('error', 'JAMB Profile ID is required. Please enter and verify your Profile ID.');
            redirectTo('exam-pins');
        }

        if (empty($txnPin)) {
            setFlash('error', 'Transaction PIN is required.');
            redirectTo('exam-pins');
        }

        if (!Ourcr\Wallet::verifyPin($user['id'], $txnPin)) {
            setFlash('error', 'Invalid transaction security PIN. Please try again.');
            redirectTo('exam-pins');
        }

        $calc = calculateUserVtuDiscount($serviceId, $amount, $quantity, $variationCode);
        $amountToPay = $calc['amount_to_pay'];

        if ($balance < $amountToPay) {
            setFlash('error', 'Insufficient wallet balance. (Required: ' . formatMoney($amountToPay) . ')');
            redirectTo('exam-pins');
        }

        $txnType = match($serviceId) {
            'waec', 'waec-registration' => TXN_TYPE_WAEC,
            'jamb'                      => TXN_TYPE_JAMB,
            default                     => TXN_TYPE_WAEC,
        };

        $requestId = generateVtpassRequestId();
        Ourcr\VTpass::saveTransaction($user['id'], $serviceId, $txnType, $requestId, $amount, $phone, [
            'variation_code' => $variationCode,
            'quantity'       => $quantity,
            'profile_code'   => $profileCode,
        ]);

        Database::beginTransaction();
        try {
            $desc  = "Exam PIN purchase ($serviceId / $variationCode) x$quantity for $phone (Upfront discount: " . formatMoney($calc['user_commission']) . ")";
            $txnId = debitWallet($user['id'], $amountToPay, 0, $txnType, $desc, $requestId);

            $result = Ourcr\VTpass::buyExamPin($serviceId, $variationCode, $phone, $amount, $requestId, $quantity, $profileCode);

            if ($result['success'] && $result['code'] === '000') {

                // ── Extract PINs per service type ────────────────────────────
                $pinString  = '';
                $modalPins  = []; // unified format for the success modal

                if ($serviceId === 'jamb') {
                    // JAMB: single PIN from data['pin']
                    $pin = $result['data']['pin'] ?? null;
                    if ($pin) {
                        $pinString   = "PIN: $pin";
                        $modalPins[] = ['type' => 'jamb', 'pin' => $pin];
                    }

                } elseif ($serviceId === 'waec-registration') {
                    // WAEC Registration: tokens[] array of strings
                    $tokens = $result['data']['tokens'] ?? [];
                    foreach ($tokens as $idx => $tok) {
                        $pinString  .= "Token " . ($idx + 1) . ": $tok\n";
                        $modalPins[] = ['type' => 'token', 'pin' => $tok];
                    }
                    // Fallback: parse from purchased_code if tokens array is empty
                    // (format: "Token: 0100070365657400875")

                } else {
                    // WAEC Result Checker / NECO: cards[] with pin + serial
                    $cards = $result['data']['cards'] ?? [];
                    foreach ($cards as $c) {
                        $pin    = $c['pin']    ?? 'N/A';
                        $serial = $c['serial'] ?? 'N/A';
                        $pinString  .= "PIN: $pin | Serial: $serial\n";
                        $modalPins[] = ['type' => 'card', 'pin' => $pin, 'serial' => $serial];
                    }
                }

                Ourcr\VTpass::updateTransactionStatus($requestId, TXN_STATUS_SUCCESS, $result['reference'], null, trim($pinString));
                Database::commit();

                $discount = (float)($result['data']['discount'] ?? 0);
                payOngoingReferralBonus($user['id'], $discount);

                $serviceLabel = match($serviceId) {
                    'waec'              => 'WAEC Result Checker',
                    'waec-registration' => 'WAEC Registration',
                    'jamb'              => 'JAMB',
                    'neco'              => 'NECO',
                    default             => strtoupper($serviceId),
                };

                sendNotification(
                    $user['id'],
                    NOTIF_SUCCESS,
                    'Exam PINs Purchased!',
                    "Your purchase of $quantity $serviceLabel PINs was successful."
                );

                $_SESSION['last_txn_success'] = [
                    'title'       => "$serviceLabel PIN Purchase Successful!",
                    'service_id'  => $serviceId,
                    'pins'        => $modalPins,
                    'fields'      => [
                        'Exam Body'  => $serviceLabel,
                        'Plan'       => $variationCode,
                        'Quantity'   => ($serviceId === 'jamb') ? 1 : $quantity,
                        'Amount'     => formatMoney($amount),
                        'Reference'  => $result['reference'] ?? 'N/A',
                    ],
                ];

                setFlash('success', "$serviceLabel PINs purchased successfully!");
                redirectTo('exam-pins');

            } else {
                Database::rollback();
                Ourcr\VTpass::updateTransactionStatus($requestId, TXN_STATUS_FAILED, null, $result['code'] ?? 'ERR');
                writeLog(LOG_CHAN_VTPASS, 'warning', 'Exam PIN purchase rejected', [
                    'user_id'    => $user['id'],
                    'service_id' => $serviceId,
                    'code'       => $result['code'] ?? 'N/A',
                    'message'    => $result['message'] ?? 'N/A',
                ]);
                setFlash('error', 'Exam PIN purchase failed. Your wallet was not debited. Please try again or contact support.');
                redirectTo('exam-pins');
            }

        } catch (Throwable $ex) {
            Database::rollback();
            writeLog(LOG_CHAN_ERROR, 'error', 'Exam PIN purchase exception: ' . $ex->getMessage(), [
                'user_id' => $user['id'], 'file' => $ex->getFile(), 'line' => $ex->getLine(),
            ]);
            setFlash('error', 'A technical error occurred. Your wallet was not debited. Please try again.');
            redirectTo('exam-pins');
        }

    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Exam PINs page exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine(),
        ]);
        setFlash('error', 'An unexpected error occurred. Please try again.');
        redirectTo('exam-pins');
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
                    <h4 class="fw-bold">Buy Exam PINs</h4>
                    <p class="text-muted small">Purchase result checker and registration PINs for WAEC, WAEC Registration, and JAMB.</p>
                </div>

                <div class="form-card">
                    <div class="d-flex justify-content-between mb-4 border-bottom pb-3">
                        <span>Wallet Balance:</span>
                        <strong class="text-site-color h5 fw-bold"><?= formatMoney($balance) ?></strong>
                    </div>

                    <div id="inline-alert-container">
                        <?php renderAlerts(); ?>
                    </div>

                    <form method="POST" action="<?= APP_URL ?>/exam-pins" data-has-pin="true" id="examForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="transaction_pin" value="">
                        <input type="hidden" id="service_id" name="service_id" value="">
                        <input type="hidden" id="single_price" value="0">
                        <input type="hidden" id="amount" name="amount" value="0">

                        <!-- Exam Body Selector -->
                        <div class="mb-3">
                            <label class="form-label small">Select Exam Body</label>
                            <div class="network-selector" style="flex-wrap: wrap;">
                                <div class="network-btn" data-exam="waec" data-label="WAEC Result Checker" data-needs-verify="false" data-needs-qty="true">
                                    <i class="fas fa-graduation-cap text-danger"></i>
                                    <span>WAEC<br><small class="text-muted" style="font-size:10px;">Result</small></span>
                                </div>
                                <div class="network-btn" data-exam="waec-registration" data-label="WAEC Registration" data-needs-verify="false" data-needs-qty="true">
                                    <i class="fas fa-file-alt text-warning"></i>
                                    <span>WAEC<br><small class="text-muted" style="font-size:10px;">Registration</small></span>
                                </div>
                                <div class="network-btn" data-exam="jamb" data-label="JAMB" data-needs-verify="true" data-needs-qty="false">
                                    <i class="fas fa-graduation-cap text-primary"></i>
                                    <span>JAMB</span>
                                </div>
                            </div>
                        </div>

                        <!-- Plan / Variation Selector -->
                        <div class="mb-3" id="variation-container" style="display: none;">
                            <label for="variation_code" class="form-label small">Select Plan</label>
                            <select class="form-select" id="variation_code" name="variation_code" required>
                                <option value="" disabled selected>Loading plans...</option>
                            </select>
                        </div>

                        <!-- JAMB: Profile ID + Verify (shown only for JAMB, required before plan select) -->
                        <div class="mb-3" id="jamb-profile-container" style="display: none;">
                            <label for="profile_code" class="form-label small">JAMB Profile ID <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="profile_code" name="profile_code"
                                       placeholder="Enter your JAMB Profile ID" autocomplete="off">
                                <button class="btn btn-outline-primary" type="button" id="btnVerifyJamb">
                                    Verify
                                </button>
                            </div>
                            <div id="jamb-profile-details" class="mt-2 small fw-bold" style="display: none;"></div>
                            <div class="form-text">Your Profile ID is obtained from the JAMB official website.</div>
                        </div>

                        <!-- Quantity (hidden for JAMB — always 1) -->
                        <div class="mb-3" id="quantity-container">
                            <label for="quantity" class="form-label small">Quantity (Max: 5)</label>
                            <select class="form-select" id="quantity" name="quantity">
                                <option value="1" selected>1</option>
                                <option value="2">2</option>
                                <option value="3">3</option>
                                <option value="4">4</option>
                                <option value="5">5</option>
                            </select>
                        </div>

                        <!-- Phone Number -->
                        <div class="mb-3">
                            <label for="phone" class="form-label small">Notification Phone Number</label>
                            <input type="text" class="form-control" id="phone" name="phone"
                                   placeholder="e.g. 08031234567" required>
                        </div>

                        <!-- Live Discount Summary Box -->
                        <div class="card bg-light border-0 p-3 rounded-12 mb-4" id="vtuDiscountSummary" style="display: none;">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Exam PIN Amount:</span>
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

                        <!-- Security Transaction PIN -->
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

                        <button type="submit" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0" id="examSubmitBtn" disabled>
                            Purchase PIN <i class="fas fa-check-circle ms-2"></i>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>

<!-- Exam PINs Scripts -->
<script>
$(document).ready(function() {
    let currentServiceId   = '';
    let jambVerified       = false;
    let jambVerifiedId     = '';

    // ── Exam Body Selection ─────────────────────────────────────────────────
    $('.network-btn').on('click', function() {
        currentServiceId = $(this).attr('data-exam');
        const needsVerify = $(this).attr('data-needs-verify') === 'true';
        const needsQty    = $(this).attr('data-needs-qty')    === 'true';

        $('.network-btn').removeClass('selected');
        $(this).addClass('selected');
        $('#service_id').val(currentServiceId);

        // Reset JAMB verification state when switching services
        jambVerified    = false;
        jambVerifiedId  = '';
        $('#jamb-profile-details').hide().text('').removeClass('text-success text-danger');
        $('#profile_code').val('');

        // Show/hide JAMB Profile ID field
        if (needsVerify) {
            $('#jamb-profile-container').show();
        } else {
            $('#jamb-profile-container').hide();
        }

        // Show/hide quantity field (JAMB is always 1)
        if (needsQty) {
            $('#quantity-container').show();
        } else {
            $('#quantity-container').hide();
            $('#quantity').val(1);
        }

        // Disable submit until plan is selected (and JAMB verified if needed)
        $('#examSubmitBtn').prop('disabled', true);

        // Fetch variation plans
        const $select    = $('#variation_code');
        const $container = $('#variation-container');
        $container.show();
        $select.html('<option value="" disabled selected>Loading plans...</option>');

        $.ajax({
            url: '<?= APP_URL ?>/api/vtpass.php?action=variations&serviceId=' + currentServiceId,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.variations && response.variations.length > 0) {
                    let html = '<option value="" disabled selected>Select plan</option>';
                    response.variations.forEach(function(item) {
                        html += '<option value="' + item.variation_code + '" data-price="' + item.variation_amount + '">'
                              + item.name + ' &mdash; &#8358;' + parseFloat(item.variation_amount).toLocaleString() + '</option>';
                    });
                    $select.html(html);
                } else {
                    $select.html('<option value="" disabled selected>No plans available for this service</option>');
                }
            },
            error: function() {
                $select.html('<option value="" disabled selected>Could not load plans. Please try again.</option>');
            }
        });
    });

    // ── Plan Selection → update amount ─────────────────────────────────────
    $('#variation_code').on('change', function() {
        const price = parseFloat($(this).find(':selected').attr('data-price')) || 0;
        $('#single_price').val(price);
        calculateTotal();

        // Re-enable submit after plan selection (and after JAMB verify if needed)
        checkSubmitReady();
    });

    // ── Quantity change → recalculate total ────────────────────────────────
    $('#quantity').on('change', calculateTotal);

    const userSharePct = <?= (float)setting('vtu_commission_user_share', 66) ?>;

    function getExamComm(serviceId, qty) {
        if (serviceId === 'jamb' || serviceId === 'waec-registration') {
            return 150 * qty;
        }
        return 250 * qty;
    }

    function calculateTotal() {
        const price = parseFloat($('#single_price').val()) || 0;
        const qty   = parseInt($('#quantity').val()) || 1;
        const total = price * qty;
        const serviceId = $('#service_id').val() || 'waec';
        const variation = $('#variation_code').val();
        const box = $('#vtuDiscountSummary');

        $('#amount').val(total);

        if (total > 0) {
            const rawComm = getExamComm(serviceId, qty);
            const userComm = Math.round(rawComm * 0.95 * (userSharePct / 100) * 100) / 100;
            const payAmount = Math.round((total - userComm) * 100) / 100;

            const fmtAmt = '₦' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
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
            fetch('<?= APP_URL ?>/api/vtpass?action=calculate_discount&serviceType=exam&serviceId=' + encodeURIComponent(serviceId) + '&amount=' + total + '&quantity=' + qty + '&variation=' + encodeURIComponent(variation || ''))
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
            const fmtAmt = '₦' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            $('#displayTotalPayable').text(fmtAmt);
            box.slideUp(150);
        }
    }

    // Form submit loading indicator & button disabling
    $('#examForm').on('submit', function() {
        const $btn = $('#examSubmitBtn');
        $btn.prop('disabled', true).addClass('disabled opacity-50')
            .html('<i class="fas fa-spinner fa-spin me-2"></i> Processing Transaction...');
    });

    function checkSubmitReady() {
        const planSelected = $('#variation_code').val() !== '' && $('#variation_code').val() !== null;
        const jambOk       = (currentServiceId !== 'jamb') || jambVerified;
        $('#examSubmitBtn').prop('disabled', !(planSelected && jambOk));
    }

    // ── JAMB Profile ID Verification ───────────────────────────────────────
    $('#btnVerifyJamb').on('click', function(e) {
        e.preventDefault();
        const profileId    = $('#profile_code').val().trim();
        const variationCode = $('#variation_code').val();
        const $details     = $('#jamb-profile-details');

        if (!profileId) {
            Swal.fire({ icon: 'warning', title: 'Profile ID Required', text: 'Please enter your JAMB Profile ID.' });
            return;
        }
        if (!variationCode) {
            Swal.fire({ icon: 'warning', title: 'Select Plan First', text: 'Please select a JAMB plan (UTME/Direct Entry) before verifying.' });
            return;
        }

        $(this).prop('disabled', true).text('Verifying...');
        $details.show().removeClass('text-success text-danger').text('Verifying Profile ID...');

        $.ajax({
            url: '<?= APP_URL ?>/api/vtpass.php?action=verify_jamb&profileId=' + encodeURIComponent(profileId) + '&variationCode=' + encodeURIComponent(variationCode),
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                $('#btnVerifyJamb').prop('disabled', false).text('Verify');
                if (response.success) {
                    jambVerified   = true;
                    jambVerifiedId = profileId;
                    $details.addClass('text-success')
                            .html('<i class="fas fa-check-circle me-1"></i>Verified: ' + response.customer_name);
                    checkSubmitReady();
                } else {
                    jambVerified = false;
                    $details.addClass('text-danger')
                            .html('<i class="fas fa-times-circle me-1"></i>' + (response.message || 'Profile ID not found.'));
                    checkSubmitReady();
                }
            },
            error: function() {
                $('#btnVerifyJamb').prop('disabled', false).text('Verify');
                jambVerified = false;
                $details.addClass('text-danger')
                        .html('<i class="fas fa-times-circle me-1"></i>Verification service unavailable. Please try again.');
                checkSubmitReady();
            }
        });
    });

    // Guard: prevent form submission if JAMB profile not verified
    $('#examForm').on('submit', function(e) {
        if (currentServiceId === 'jamb' && !jambVerified) {
            e.preventDefault();
            Swal.fire({ icon: 'warning', title: 'Profile Not Verified', text: 'Please verify your JAMB Profile ID before proceeding.' });
        }
    });
});
</script>

<!-- Exam PINs Success Modal -->
<?php if ($successDetails): ?>
<div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-16 border-0 shadow-lg position-relative" style="overflow: hidden;">
            <div class="modal-body p-4">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="text-center mb-3 mt-2">
                    <i class="fas fa-graduation-cap fa-4x animate__animated animate__bounceIn" style="color: #dc3545;"></i>
                </div>
                <h4 class="fw-bold mb-1 text-center"><?= e($successDetails['title']) ?></h4>
                <p class="text-muted small mb-4 text-center">
                    <?php if (($successDetails['service_id'] ?? '') === 'jamb'): ?>
                        Keep this PIN safe — it cannot be recovered once lost.
                    <?php else: ?>
                        Keep these PINs safe. They cannot be recovered once lost.
                    <?php endif; ?>
                </p>

                <!-- PINs / Tokens Display -->
                <?php if (!empty($successDetails['pins'])): ?>
                <div class="d-flex flex-column gap-3 mb-4">
                    <?php foreach ($successDetails['pins'] as $index => $item): ?>
                        <?php if ($item['type'] === 'jamb'): ?>
                            <!-- JAMB single PIN -->
                            <div class="bg-danger bg-opacity-10 border border-danger rounded-12 p-4 text-center">
                                <p class="small text-muted mb-1">Your JAMB PIN</p>
                                <div class="h3 fw-bold font-monospace mb-2"><?= e($item['pin']) ?></div>
                                <button class="btn btn-sm btn-outline-danger" data-copy="<?= e($item['pin']) ?>">
                                    <i class="fas fa-copy me-1"></i>Copy PIN
                                </button>
                            </div>

                        <?php elseif ($item['type'] === 'token'): ?>
                            <!-- WAEC Registration Token -->
                            <div class="bg-danger bg-opacity-10 border border-danger rounded-12 p-3">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span class="fw-bold text-danger">Token #<?= $index + 1 ?></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="h5 fw-bold font-monospace mb-0"><?= e($item['pin']) ?></span>
                                    <button class="btn btn-sm btn-outline-danger" data-copy="<?= e($item['pin']) ?>">
                                        <i class="fas fa-copy me-1"></i>Copy
                                    </button>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- WAEC Result / NECO card (pin + serial) -->
                            <div class="bg-danger bg-opacity-10 border border-danger rounded-12 p-3">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span class="fw-bold text-danger">Card #<?= $index + 1 ?></span>
                                    <?php if (!empty($item['serial'])): ?>
                                        <span>Serial: <?= e($item['serial']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="h5 fw-bold font-monospace mb-0">PIN: <?= e($item['pin']) ?></span>
                                    <button class="btn btn-sm btn-outline-danger" data-copy="<?= e($item['pin']) ?>">
                                        <i class="fas fa-copy me-1"></i>Copy
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="bg-danger bg-opacity-10 border border-danger rounded-12 p-3 text-danger text-center small mb-4">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    PINs are being processed. Please check your transaction history in a moment.
                </div>
                <?php endif; ?>

                <!-- Transaction Summary -->
                <div class="bg-light p-3 rounded-12 text-start mb-4">
                    <?php foreach ($successDetails['fields'] as $label => $value): ?>
                        <div class="d-flex justify-content-between mb-2 border-bottom pb-2 border-light">
                            <span class="text-muted small"><?= e($label) ?>:</span>
                            <span class="fw-bold text-dark small"><?= e($value) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="btn btn-danger w-100 py-3 rounded-12 fw-bold border-0 bg-site-color" data-bs-dismiss="modal">
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
