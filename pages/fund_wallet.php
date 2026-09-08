<?php
/**
 * OURCR ONLINE - Fund Wallet (Deposit Intent Stepper)
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Fund Wallet';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Get corporate bank credentials from Admin Manual Bank Transfer settings
$companyBank   = setting('company_bank_name', defined('COMPANY_BANK_NAME') ? COMPANY_BANK_NAME : 'Guaranty Trust Bank (GTBank)');
$companyName   = setting('company_account_name', defined('COMPANY_ACCOUNT_NAME') ? COMPANY_ACCOUNT_NAME : 'OURCR ONLINE');
$companyNumber = setting('company_account_no', setting('gaps_account_number', defined('COMPANY_ACCOUNT_NUMBER') ? COMPANY_ACCOUNT_NUMBER : '0004527849'));

$minDeposit = (float)setting('min_deposit', 100);
$maxDeposit = (float)setting('max_deposit', 5000000);

// Handle POST: Create Deposit Intent
if (isPost()) {
    try {
        requireCsrf();

        $isAjax     = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $amount     = (float) post('amount');
        $senderName = sanitizeString(post('sender_name'));
        // expected_at: 1 hour from now (user is about to transfer)
        $expectedAt = date('Y-m-d H:i:s', time() + 3600);

        $result = \Ourcr\Wallet::createDepositIntent(
            userId:     $user['id'],
            amount:     $amount,
            senderName: $senderName,
            expectedAt: $expectedAt
        );

        if ($result['success']) {
            auditLog('DEPOSIT_INTENT_CREATED', "Declared deposit of " . formatMoney($amount) . " by $senderName", 'deposit_intents', $result['intent_id']);

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'intent_id' => $result['intent_id'] ?? null,
                    'amount' => $amount,
                    'formatted_amount' => formatMoney($amount),
                    'sender_name' => $senderName,
                    'message' => 'Deposit intent registered. Please proceed with the bank transfer.'
                ]);
                exit;
            }

            setFlash('success', 'Deposit intent registered. Please proceed with the bank transfer.');
            redirectTo('fund-wallet');
        } else {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Could not register intent']);
                exit;
            }
            setFlash('error', $result['error']);
            redirectTo('fund-wallet');
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Fund wallet exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine()
        ]);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json', true, 500);
            echo json_encode(['success' => false, 'error' => 'A technical error occurred. Please try again.']);
            exit;
        }
        setFlash('error', 'A technical error occurred. Please try again.');
        redirectTo('fund-wallet');
    }
}

// Get user's active declarations
$pendingIntents = \Ourcr\Wallet::getPendingIntents($user['id']);
$showBank = !empty($pendingIntents);
$activeIntent = $showBank ? $pendingIntents[0] : null;

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            <div class="service-page fade-in-up">
                
                <!-- Title -->
                <div class="mb-4 text-center">
                    <h4 class="fw-bold">Fund Wallet</h4>
                    <p class="text-muted small">Follow the 2-step process to fund your wallet with instant automated reconciliation.</p>
                </div>

                <!-- Stepper Progress Header -->
                <div class="card border-0 shadow-sm rounded-16 mb-4">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center justify-content-between px-md-3">
                            <div id="step1Indicator" class="d-flex align-items-center gap-2 <?= $showBank ? 'text-success' : 'text-danger' ?> fw-bold">
                                <span class="badge rounded-circle <?= $showBank ? 'bg-success' : 'bg-danger' ?> text-white d-inline-flex align-items-center justify-content-center" id="step1Badge" style="width:30px; height:30px;">
                                    <?= $showBank ? '<i class="fas fa-check"></i>' : '1' ?>
                                </span>
                                <span class="small">Step 1: Declare Intent</span>
                            </div>
                            <div class="text-muted opacity-50 px-1"><i class="fas fa-chevron-right"></i></div>
                            <div id="step2Indicator" class="d-flex align-items-center gap-2 <?= $showBank ? 'text-danger fw-bold' : 'text-muted' ?>">
                                <span class="badge rounded-circle <?= $showBank ? 'bg-danger' : 'bg-secondary' ?> text-white d-inline-flex align-items-center justify-content-center" id="step2Badge" style="width:30px; height:30px;">2</span>
                                <span class="small">Step 2: Bank Details</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 1: Intent Form Card -->
                <div class="form-card" id="intentFormCard" style="display: <?= $showBank ? 'none' : 'block' ?>;">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="badge bg-danger rounded-pill">Step 1 of 2</span>
                        <h5 class="fw-bold mb-0">Declare Transfer Intent</h5>
                    </div>
                    <p class="text-muted small mb-4">Pre-declare your transfer details first so our automated system can match your payment immediately upon receipt.</p>
                    
                    <div id="step1Alert"></div>

                    <form method="POST" action="<?= APP_URL ?>/fund-wallet" id="intentForm">
                        <?= csrfField() ?>

                        <div class="mb-3">
                            <label for="amount" class="form-label fw-bold small">Transfer Amount (₦)</label>
                            <input type="number" 
                                   class="form-control form-control-lg" 
                                   id="amount" 
                                   name="amount" 
                                   min="<?= $minDeposit ?>" 
                                   max="<?= $maxDeposit ?>" 
                                   placeholder="e.g. 5000" 
                                   required>
                            <div class="form-text small mb-2">Min: <?= formatMoney($minDeposit) ?> | Max: <?= formatMoney($maxDeposit) ?></div>
                            
                            <!-- Quick Presets -->
                            <div class="d-flex gap-2 flex-wrap mt-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary preset-amt" data-val="1000">₦1,000</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary preset-amt" data-val="5000">₦5,000</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary preset-amt" data-val="10000">₦10,000</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary preset-amt" data-val="20000">₦20,000</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary preset-amt" data-val="50000">₦50,000</button>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="sender_name" class="form-label fw-bold small">Sender Full Account Name</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="sender_name" 
                                   name="sender_name" 
                                   value="<?= e($user['first_name'] . ' ' . $user['last_name']) ?>" 
                                   placeholder="Enter the sender's full bank account name" 
                                   required>
                            <div class="form-text small">Must match your full bank account name for fast automated verification.</div>
                        </div>

                        <button type="submit" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0" id="btnSubmitIntent">
                            Proceed to Bank Details <i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </form>
                </div>

                <!-- STEP 2: Bank Info Card -->
                <div id="bankInfoCard" class="form-card" style="display: <?= $showBank ? 'block' : 'none' ?>;">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-success rounded-pill">Step 2 of 2</span>
                            <h5 class="fw-bold mb-0">Our Bank Details</h5>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" id="btnNewDeclaration">
                            <i class="fas fa-plus me-1"></i> New Intent
                        </button>
                    </div>

                    <div id="step2Alert"></div>

                    <!-- Declared Amount Summary Box -->
                    <div class="card border-0 bg-light rounded-12 p-3 mb-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="small text-muted d-block">Declared Amount to Transfer:</span>
                                <strong class="h4 fw-bold text-site-color mb-0" id="displayDeclaredAmount">
                                    <?= $activeIntent ? formatMoney($activeIntent['amount']) : '₦0.00' ?>
                                </strong>
                            </div>
                            <span class="badge bg-warning text-dark px-3 py-2 rounded-pill small"><i class="fas fa-clock me-1"></i> Pending Transfer</span>
                        </div>
                        <div class="small text-muted mt-2 pt-2 border-top">
                            Sender: <strong id="displayDeclaredSender"><?= e($activeIntent['sender_name'] ?? ($user['first_name'] . ' ' . $user['last_name'])) ?></strong>
                        </div>
                    </div>

                    <p class="small text-muted mb-3">Transfer the declared amount into the bank account below using your bank app or USSD:</p>
                    
                    <div class="bg-light p-3 rounded-12 border mb-4">
                        <div class="row mb-2 pb-2 border-bottom">
                            <div class="col-5 text-muted small">Bank Name:</div>
                            <div class="col-7 fw-bold"><?= e($companyBank) ?></div>
                        </div>
                        <div class="row mb-2 pb-2 border-bottom">
                            <div class="col-5 text-muted small">Account Name:</div>
                            <div class="col-7 fw-bold"><?= e($companyName) ?></div>
                        </div>
                        <div class="row align-items-center">
                            <div class="col-5 text-muted small">Account Number:</div>
                            <div class="col-7 fw-bold d-flex align-items-center justify-content-between">
                                <span class="fs-5 text-dark font-monospace" id="accountNoText"><?= e($companyNumber) ?></span>
                                <button class="btn btn-sm btn-outline-danger py-1 px-3 rounded-10 fw-bold" data-copy="<?= e($companyNumber) ?>">
                                    <i class="fas fa-copy me-1"></i> Copy
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info py-2 px-3 small border-0 mb-4 rounded-12">
                        <i class="fas fa-info-circle me-2"></i><strong>Note:</strong> This transfer declaration is valid for <strong>1 hour</strong>. Once you complete the transfer in your bank app, click <strong>I Have Sent This Payment</strong> below. If unpaid after 1 hour, it will automatically cancel.
                    </div>

                    <div id="paymentSentSection">
                        <button type="button" id="btnPaymentSent" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0">
                            I Have Sent This Payment <i class="fas fa-check-circle ms-2"></i>
                        </button>
                        <div id="paymentSentFeedback" class="mt-3"></div>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>

<script>
$(function(){
    // Quick preset amount buttons
    $('.preset-amt').on('click', function(){
        var val = $(this).data('val');
        $('#amount').val(val);
    });

    // Step 1 Form Submission via AJAX
    $('#intentForm').on('submit', function(e){
        e.preventDefault();
        var $btn = $('#btnSubmitIntent');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i> Registering Intent...');
        $('#step1Alert').html('');

        $.ajax({
            url: $(this).attr('action'),
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            success: function(res){
                if (res.success) {
                    // Update Stepper to Step 2 Active
                    $('#step1Badge').html('<i class="fas fa-check"></i>').removeClass('bg-danger').addClass('bg-success');
                    $('#step1Indicator').removeClass('text-danger').addClass('text-success');
                    $('#step2Badge').removeClass('bg-secondary').addClass('bg-danger');
                    $('#step2Indicator').removeClass('text-muted').addClass('text-danger fw-bold');

                    // Update summary display in Step 2
                    $('#displayDeclaredAmount').text(res.formatted_amount || ('₦' + parseFloat(res.amount).toLocaleString()));
                    $('#displayDeclaredSender').text(res.sender_name);

                    // Smooth transition from Step 1 to Step 2
                    $('#intentFormCard').slideUp(300, function(){
                        $('#bankInfoCard').slideDown(300);
                        $('html, body').animate({ scrollTop: $('#bankInfoCard').offset().top - 80 }, 300);
                    });
                } else {
                    var msg = res.error || 'Could not register deposit intent.';
                    $('#step1Alert').html('<div class="alert alert-danger small rounded-12">' + msg + '</div>');
                }
            },
            error: function(xhr){
                var msg = 'A technical error occurred. Please try again.';
                if (xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
                $('#step1Alert').html('<div class="alert alert-danger small rounded-12">' + msg + '</div>');
            },
            complete: function(){
                $btn.prop('disabled', false).html('Proceed to Bank Details <i class="fas fa-arrow-right ms-2"></i>');
            }
        });
    });

    // Handle "I Have Sent This Payment" click
    $('#btnPaymentSent').on('click', function(){
        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i> Confirming...');
        
        setTimeout(function(){
            $btn.removeClass('btn-danger bg-site-color').addClass('btn-success').html('<i class="fas fa-check-circle me-2"></i> Payment Confirmed!');
            $('#paymentSentFeedback').html(
                '<div class="alert alert-success rounded-12 border-0 small">' +
                '<i class="fas fa-check-circle me-2"></i><strong>Thank you!</strong> Our automated system is verifying bank records. Your wallet will be credited automatically once confirmed.' +
                '<div class="mt-2"><a href="<?= APP_URL ?>/transactions" class="btn btn-sm btn-success rounded-pill">View Wallet History</a></div>' +
                '</div>'
            );
        }, 800);
    });

    // Start New Declaration button
    $('#btnNewDeclaration').on('click', function(){
        // Reset Stepper to Step 1 Active
        $('#step1Badge').html('1').removeClass('bg-success').addClass('bg-danger');
        $('#step1Indicator').removeClass('text-success').addClass('text-danger fw-bold');
        $('#step2Badge').removeClass('bg-danger').addClass('bg-secondary');
        $('#step2Indicator').removeClass('text-danger fw-bold').addClass('text-muted');

        $('#bankInfoCard').slideUp(300, function(){
            $('#intentFormCard').slideDown(300);
            $('#step1Alert').html('');
        });
    });

    // Copy account number
    $(document).on('click', '[data-copy]', function(){
        var txt = $(this).attr('data-copy');
        navigator.clipboard?.writeText(txt).then(function(){
            Swal.fire({
                icon: 'success',
                title: 'Copied!',
                text: 'Account number copied to clipboard',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2000
            });
        }, function(){
            alert('Account number: ' + txt);
        });
    });
});
</script>
