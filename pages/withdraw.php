<?php
/**
 * OURCR ONLINE - Withdrawal Request Page
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Withdraw Funds';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

$balance = getWalletBalance($user['id']);
$minWithdrawal = (float)setting('min_withdrawal', 500);
$maxWithdrawal = (float)setting('max_withdrawal', 500000);
$withdrawalFeePercent = (float)setting('withdrawal_fee', 1.5);

$error   = '';
$success = '';

// Fetch last 10 distinct beneficiaries for this user (successful, processing, pending, or failed)
$beneficiaries = Database::fetchAll(
    "SELECT account_number, account_name, bank_name, MAX(created_at) as last_used
     FROM withdrawals
     WHERE user_id = ? AND status != 'reversed'
     GROUP BY account_number, account_name, bank_name
     ORDER BY last_used DESC
     LIMIT 10",
    [$user['id']]
);

// Retrieve and clear withdrawal result modal data
$withdrawalResult = $_SESSION['withdrawal_result'] ?? null;
if ($withdrawalResult) {
    unset($_SESSION['withdrawal_result']);
    // Refresh balance after successful withdrawal
    $balance = getWalletBalance($user['id']);
}

$oldAmount      = getOldInput('amount');
$oldBank        = getOldInput('bank_name');
$oldAccountNo   = getOldInput('account_number');
$oldAccountName = getOldInput('account_name');
$oldRemark      = getOldInput('remark');
clearOldInputs();

// Handle POST: Process Withdrawal
if (isPost()) {
    try {
        requireCsrf();

        $amount      = (float)post('amount');
        $bankName    = sanitizeString(post('bank_name'));
        $accountNo   = sanitizeString(post('account_number'));
        $accountName = sanitizeString(post('account_name'));
        $txnPin      = post('transaction_pin');
        $remark      = sanitizeString(post('remark'));

        $withdrawalFee = round(($amount * $withdrawalFeePercent) / 100, 2);

        if ($amount < $minWithdrawal || $amount > $maxWithdrawal) {
            setOldInputs(['amount' => post('amount'), 'bank_name' => post('bank_name'), 'account_number' => post('account_number'), 'account_name' => post('account_name'), 'remark' => post('remark')]);
            setFlash('error', 'Withdrawal amount must be between ' . formatMoney($minWithdrawal) . ' and ' . formatMoney($maxWithdrawal) . '.');
            redirectTo('withdraw');
        } elseif (empty($bankName) || empty($accountNo)) {
            setOldInputs(['amount' => post('amount'), 'bank_name' => post('bank_name'), 'account_number' => post('account_number'), 'remark' => post('remark')]);
            setFlash('error', 'All bank details are required.');
            redirectTo('withdraw');
        } elseif (empty($txnPin)) {
            setOldInputs(['amount' => post('amount'), 'bank_name' => post('bank_name'), 'account_number' => post('account_number'), 'remark' => post('remark')]);
            setFlash('error', 'Transaction PIN is required.');
            redirectTo('withdraw');
        } elseif (!Ourcr\Wallet::verifyPin($user['id'], $txnPin)) {
            setOldInputs(['amount' => post('amount'), 'bank_name' => post('bank_name'), 'account_number' => post('account_number'), 'remark' => post('remark')]);
            setFlash('error', 'Invalid transaction security PIN. Please try again.');
            redirectTo('withdraw');
        } elseif ($balance < ($amount + $withdrawalFee)) {
            setOldInputs(['amount' => post('amount'), 'bank_name' => post('bank_name'), 'account_number' => post('account_number'), 'remark' => post('remark')]);
            setFlash('error', 'Insufficient balance. You need ' . formatMoney($amount + $withdrawalFee) . ' (including ' . formatMoney($withdrawalFee) . ' withdrawal fee).');
            redirectTo('withdraw');
        } else {
            // Strictly verify recipient account with GTBank GAPS server-side
            $resolveCheck = \Ourcr\AccountResolver::resolveAccountName($accountNo, $bankName);
            if (empty($resolveCheck['success']) || empty($resolveCheck['account_name'])) {
                setOldInputs(['amount' => post('amount'), 'bank_name' => post('bank_name'), 'account_number' => post('account_number'), 'remark' => post('remark')]);
                $errMsg = $resolveCheck['message'] ?? 'Account name could not be resolved. Please verify the account number and bank, and try again.';
                setFlash('error', $errMsg);
                redirectTo('withdraw');
            }
            $accountName = $resolveCheck['account_name'];

            try {
                $result = Ourcr\Wallet::requestWithdrawal(
                    $user['id'],
                    $amount,
                    $bankName,
                    $accountNo,
                    $accountName,
                    $txnPin,
                    $remark
                );

                if ($result['success']) {
                    // Store result for modal display — stay on page
                    $_SESSION['withdrawal_result'] = [
                        'status'       => 'success',
                        'amount'       => formatMoney($amount),
                        'fee'          => formatMoney($withdrawalFee),
                        'bank'         => $bankName,
                        'account_no'   => $accountNo,
                        'account_name' => $accountName,
                        'reference'    => $result['reference'] ?? 'N/A',
                        'message'      => $result['message'] ?? 'Withdrawal request submitted successfully.',
                        'auto_paid'    => !empty($result['auto_paid']),
                    ];
                    redirectTo('withdraw');
                } else {
                    setOldInputs(['amount' => post('amount'), 'bank_name' => post('bank_name'), 'account_number' => post('account_number'), 'account_name' => post('account_name'), 'remark' => post('remark')]);
                    setFlash('error', $result['message']);
                    redirectTo('withdraw');
                }
            } catch (Throwable $ex) {
                writeLog(LOG_CHAN_ERROR, 'error', 'Withdrawal exception: ' . $ex->getMessage(), [
                    'user_id' => $user['id'], 'file' => $ex->getFile(), 'line' => $ex->getLine(),
                ]);
                setFlash('error', 'Withdrawal request could not be submitted. Please try again or contact support.');
                redirectTo('withdraw');
            }
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Withdraw page exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine(),
        ]);
        setFlash('error', 'An unexpected error occurred. Please try again.');
        redirectTo('withdraw');
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
                    <h4 class="fw-bold">Withdraw Funds</h4>
                    <p class="text-muted small">Transfer money from your wallet to any Nigerian bank account balance.</p>
                </div>

                <div class="form-card">
                    <div class="d-flex justify-content-between mb-4 border-bottom pb-3">
                        <span>Wallet Balance:</span>
                        <strong class="text-site-color h5 fw-bold"><?= formatMoney($balance) ?></strong>
                    </div>

                    <div id="inline-alert-container">
                        <?php renderAlerts(); ?>
                    </div>

                    <form method="POST" action="<?= APP_URL ?>/withdraw" data-has-pin="true" id="withdrawForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="transaction_pin" value="">

                        <!-- Amount -->
                        <div class="mb-3">
                            <label for="amount" class="form-label">Amount (₦)</label>
                            <input type="number" 
                                   class="form-control form-control-lg" 
                                   id="amount" 
                                   name="amount" 
                                   min="<?= $minWithdrawal ?>" 
                                   max="<?= $maxWithdrawal ?>" 
                                   value="<?= e($oldAmount) ?>"
                                   placeholder="Min: <?= $minWithdrawal ?>" 
                                   required>
                            <div class="form-text small">Standard transfer fee: <strong><?= e($withdrawalFeePercent) ?>%</strong> will be deducted.</div>
                        </div>

                        <?php if (!empty($beneficiaries)): ?>
                        <!-- Saved Beneficiaries -->
                        <div class="mb-3">
                            <label for="beneficiary_select" class="form-label">Saved Beneficiaries</label>
                            <select class="form-select" id="beneficiary_select">
                                <option value="">-- Select a saved beneficiary --</option>
                                <?php foreach ($beneficiaries as $b): ?>
                                <option
                                    value="<?= e($b['account_number']) ?>"
                                    data-bank="<?= e($b['bank_name']) ?>"
                                    data-name="<?= e($b['account_name']) ?>">
                                    <?= e($b['account_name']) ?> &mdash; <?= e($b['account_number']) ?> (<?= e($b['bank_name']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <!-- Account Number (Placed Above Bank Name) -->
                        <div class="mb-3">
                            <label for="account_number" class="form-label">Account Number</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="account_number" 
                                   name="account_number" 
                                   maxlength="10" 
                                   value="<?= e($oldAccountNo) ?>"
                                   placeholder="10-digit number" 
                                   required>
                        </div>

                        <!-- Bank Name Searchable Dropdown (custom, no Bootstrap toggle) -->
                        <div class="mb-3">
                            <label class="form-label">Recipient Bank Name</label>
                            <div class="custom-bank-dropdown" id="bankDropdownWrapper" style="position:relative;">
                                <button type="button" id="bankDropdownBtn" class="custom-bank-toggle"
                                        style="width:100%; background:#fff; border:1px solid #ced4da; color:#4b5563; padding:0.5rem 0.75rem; border-radius:8px; text-align:left; display:flex; justify-content:space-between; align-items:center; cursor:pointer;">
                                    <span id="bankDropdownLabel"><?= e(!empty($oldBank) ? $oldBank : 'Select Bank') ?></span>
                                    <i class="fas fa-chevron-down" style="font-size:12px;"></i>
                                </button>
                                <div id="bankDropdownPanel" style="display:none; position:absolute; z-index:9999; background:#fff; border:1px solid #ced4da; border-radius:12px; box-shadow:0 8px 24px rgba(0,0,0,0.12); padding:12px; width:100%; max-height:280px; overflow:hidden; margin-top:2px;">
                                    <input type="text" id="bankSearchInput" class="form-control mb-2" placeholder="Type to search..." autocomplete="off" style="border-radius:8px;">
                                    <div id="bankListQueue" style="max-height:200px; overflow-y:auto;">
                                        <div class="bank-option" data-value="Access Bank">Access Bank</div>
                                        <div class="bank-option" data-value="Carbon">Carbon</div>
                                        <div class="bank-option" data-value="Citibank">Citibank</div>
                                        <div class="bank-option" data-value="Ecobank">Ecobank</div>
                                        <div class="bank-option" data-value="Fairmoney MFB">Fairmoney MFB</div>
                                        <div class="bank-option" data-value="Fidelity Bank">Fidelity Bank</div>
                                        <div class="bank-option" data-value="First Bank of Nigeria">First Bank of Nigeria</div>
                                        <div class="bank-option" data-value="First City Monument Bank (FCMB)">First City Monument Bank (FCMB)</div>
                                        <div class="bank-option" data-value="Globus Bank">Globus Bank</div>
                                        <div class="bank-option" data-value="Guaranty Trust Bank (GTBank)">Guaranty Trust Bank (GTBank)</div>
                                        <div class="bank-option" data-value="Heritage Bank">Heritage Bank</div>
                                        <div class="bank-option" data-value="Keystone Bank">Keystone Bank</div>
                                        <div class="bank-option" data-value="Kuda Microfinance Bank">Kuda Microfinance Bank</div>
                                        <div class="bank-option" data-value="Lotus Bank">Lotus Bank</div>
                                        <div class="bank-option" data-value="Moniepoint MFB">Moniepoint MFB</div>
                                        <div class="bank-option" data-value="OPay (Digital Wallet)">OPay (Digital Wallet)</div>
                                        <div class="bank-option" data-value="OPTIMUS Bank">OPTIMUS Bank</div>
                                        <div class="bank-option" data-value="PalmPay">PalmPay</div>
                                        <div class="bank-option" data-value="Paragon MFB">Paragon MFB</div>
                                        <div class="bank-option" data-value="PremiumTrust Bank">PremiumTrust Bank</div>
                                        <div class="bank-option" data-value="Providus Bank">Providus Bank</div>
                                        <div class="bank-option" data-value="Rubies MFB">Rubies MFB</div>
                                        <div class="bank-option" data-value="Signature Bank">Signature Bank</div>
                                        <div class="bank-option" data-value="Stanbic IBTC Bank">Stanbic IBTC Bank</div>
                                        <div class="bank-option" data-value="Standard Chartered Bank">Standard Chartered Bank</div>
                                        <div class="bank-option" data-value="Sterling Bank">Sterling Bank</div>
                                        <div class="bank-option" data-value="SunTrust Bank">SunTrust Bank</div>
                                        <div class="bank-option" data-value="Taj Bank">Taj Bank</div>
                                        <div class="bank-option" data-value="Titan Trust Bank">Titan Trust Bank</div>
                                        <div class="bank-option" data-value="Union Bank of Nigeria">Union Bank of Nigeria</div>
                                        <div class="bank-option" data-value="United Bank for Africa (UBA)">United Bank for Africa (UBA)</div>
                                        <div class="bank-option" data-value="Unity Bank">Unity Bank</div>
                                        <div class="bank-option" data-value="VFD Microfinance Bank">VFD Microfinance Bank</div>
                                        <div class="bank-option" data-value="Wema Bank">Wema Bank</div>
                                        <div class="bank-option" data-value="Zenith Bank">Zenith Bank</div>
                                    </div>
                                </div>
                                <input type="hidden" name="bank_name" id="selected_bank_name" value="<?= e($oldBank) ?>" required>
                            </div>
                        </div>

                        <!-- Account Name -->
                        <div class="mb-3">
                            <label for="account_name" class="form-label">Account Name</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="account_name" 
                                   name="account_name" 
                                   value="<?= e($oldAccountName) ?>"
                                   placeholder="Will be resolved automatically from bank" 
                                   readonly 
                                   tabindex="-1"
                                   style="background-color: #f8fafc; cursor: not-allowed; font-weight: 600;"
                                   required>
                            <div id="account-name-alert" style="display:none;" class="mt-2"></div>
                        </div>

                        <!-- Remark / Purpose -->
                        <div class="mb-4">
                            <label for="remark" class="form-label">Remark / Purpose (Optional)</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="remark" 
                                   name="remark" 
                                   value="<?= e($oldRemark) ?>"
                                   placeholder="e.g. Payment for services / Rent">
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

                        <button type="submit" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0" id="btnSubmitWithdrawal">
                            Withdraw Funds <i class="fas fa-check-circle ms-2"></i>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </main>
</div>

<?php include INCLUDES_PATH . '/footer.php'; ?>

<script>
$(document).ready(function() {
    var feePct = <?= (float)$withdrawalFeePercent ?>;

    // ── Inline alert helper ───────────────────────────────────────────────────
    function showAccountAlert(type, message) {
        var cls = type === 'error' ? 'alert-danger' : 'alert-success';
        var icon = type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle';
        $('#account-name-alert')
            .removeClass('alert-danger alert-success')
            .addClass('alert ' + cls + ' d-flex align-items-center gap-2 py-2 px-3 small rounded-3')
            .html('<i class="fas ' + icon + '"></i><span>' + message + '</span>')
            .show();
    }

    function clearAccountAlert() {
        $('#account-name-alert').hide().html('');
    }

    // ── Saved Beneficiaries prefill ───────────────────────────────────────────
    $('#beneficiary_select').on('change', function() {
        var $opt = $(this).find('option:selected');
        var accNo   = $opt.val();
        var bankName = $opt.data('bank');
        var accName  = $opt.data('name');

        if (!accNo) return; // "-- Select --" chosen, do nothing

        // Fill account number
        $('#account_number').val(accNo);

        // Fill bank dropdown
        $('#selected_bank_name').val(bankName);
        $('#bankDropdownLabel').text(bankName);
        $btn.css({'border-color': '#6d28d9', 'color': '#111'});

        // Clear account name and re-verify live with GAPS
        $('#account_name').val('');
        clearAccountAlert();
        $('#btnSubmitWithdrawal').prop('disabled', true);
        fetchAccountName();
    });

    // ── Amount fee calculator ─────────────────────────────────────────────────
    $('#amount').on('input change', function() {
        var amt = parseFloat($(this).val()) || 0;
        var fee = amt * (feePct / 100);
        var total = amt + fee;
        $('#displayTotalPayable').text('₦' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    });

    // ── Custom bank dropdown ──────────────────────────────────────────────────
    var $wrapper = $('#bankDropdownWrapper');
    var $panel   = $('#bankDropdownPanel');
    var $btn     = $('#bankDropdownBtn');

    // Style bank option items
    $('.bank-option').css({
        'padding': '8px 12px',
        'cursor': 'pointer',
        'border-radius': '6px',
        'font-size': '14px',
        'display': 'block'
    }).on('mouseenter', function() {
        $(this).css('background', '#f3f4f6');
    }).on('mouseleave', function() {
        $(this).css('background', '');
    });

    // Toggle open/close panel on button click
    $btn.on('click', function(e) {
        e.stopPropagation();
        var isOpen = $panel.is(':visible');
        if (isOpen) {
            $panel.hide();
            $btn.find('i').removeClass('fa-chevron-up').addClass('fa-chevron-down');
        } else {
            $panel.show();
            $btn.find('i').removeClass('fa-chevron-down').addClass('fa-chevron-up');
            $('#bankSearchInput').val('').trigger('input').focus();
        }
    });

    // Close panel when clicking outside
    $(document).on('click', function(e) {
        if ($wrapper.length && !$wrapper[0].contains(e.target)) {
            $panel.hide();
            $btn.find('i').removeClass('fa-chevron-up').addClass('fa-chevron-down');
        }
    });

    // Search filter
    $('#bankSearchInput').on('input', function(e) {
        e.stopPropagation();
        var val = $(this).val().toLowerCase();
        $('.bank-option').each(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(val) > -1);
        });
    });

    // Select a bank item
    $('#bankListQueue').on('click', '.bank-option', function(e) {
        e.stopPropagation();
        var selectedVal = $(this).data('value');
        $('#selected_bank_name').val(selectedVal);
        $('#bankDropdownLabel').text(selectedVal);
        $btn.css({'border-color': '#6d28d9', 'color': '#111'});
        $panel.hide();
        $btn.find('i').removeClass('fa-chevron-up').addClass('fa-chevron-down');
        $('#account_name').val('');
        clearAccountAlert();
        $('#btnSubmitWithdrawal').prop('disabled', true);
        fetchAccountName();
    });

    // ── Account name auto-fetch ───────────────────────────────────────────────
    function fetchAccountName() {
        var accountNo = $('#account_number').val().replace(/\D/g, '');
        var bankName  = $('#selected_bank_name').val();

        if (accountNo.length !== 10 || !bankName) {
            $('#account_name').val('');
            clearAccountAlert();
            $('#btnSubmitWithdrawal').prop('disabled', true);
            return;
        }

        clearAccountAlert();
        $('#account_name').val('Resolving account name...');
        $('#btnSubmitWithdrawal').prop('disabled', true);

        $.ajax({
            url: 'api/wallet.php?action=resolve_account',
            type: 'POST',
            data: {
                account_number: accountNo,
                bank_name: bankName,
                csrf_token: $('input[name="csrf_token"]').val()
            },
            dataType: 'json',
            success: function(res) {
                if (res.success && res.account_name) {
                    $('#account_name').val(res.account_name);
                    showAccountAlert('success', 'Account verified: ' + res.account_name);
                    $('#btnSubmitWithdrawal').prop('disabled', false);
                } else {
                    $('#account_name').val('');
                    $('#btnSubmitWithdrawal').prop('disabled', true);
                    var msg = res.message || 'Account name could not be resolved. Please verify the account number and bank, and try again.';
                    showAccountAlert('error', msg);
                }
            },
            error: function(xhr) {
                $('#account_name').val('');
                $('#btnSubmitWithdrawal').prop('disabled', true);
                var err = (xhr.responseJSON && xhr.responseJSON.message)
                    ? xhr.responseJSON.message
                    : 'Account name could not be resolved. Please verify the account number and bank, and try again.';
                showAccountAlert('error', err);
            },
            complete: function() {
                var currentVal = $('#account_name').val().trim();
                if (currentVal && currentVal !== 'Resolving account name...') {
                    $('#btnSubmitWithdrawal').prop('disabled', false);
                } else {
                    $('#btnSubmitWithdrawal').prop('disabled', true);
                }
            }
        });
    }

    // Trigger name fetch when account number hits 10 digits
    $('#account_number').on('input', function() {
        var val = $(this).val().replace(/\D/g, '');
        $(this).val(val);
        $('#account_name').val('');
        clearAccountAlert();
        $('#btnSubmitWithdrawal').prop('disabled', true);
        if (val.length === 10) {
            fetchAccountName();
        }
    });

    // ── Form submit validation & double-click protection ──────────────────────
    var isSubmitting = false;
    $('#withdrawForm').on('submit', function(e) {
        if (isSubmitting) {
            e.preventDefault();
            return false;
        }

        if (!$('#selected_bank_name').val()) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Required Field',
                text: 'Please select a recipient bank.'
            });
            return false;
        }

        var accName = $('#account_name').val().trim();
        if (!accName || accName === 'Resolving account name...') {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Account Verification Required',
                text: 'Account name could not be resolved. Please verify the account number and bank, and try again.'
            });
            return false;
        }

        isSubmitting = true;
        var $submitBtn = $('#btnSubmitWithdrawal');
        $submitBtn.prop('disabled', true).addClass('disabled opacity-50')
            .html('<i class="fas fa-spinner fa-spin me-2"></i> Processing Withdrawal...');
    });

    // Initial check: if account name is empty, disable submit
    if (!$('#account_name').val().trim()) {
        $('#btnSubmitWithdrawal').prop('disabled', true);
    }
});
</script>

<?php if ($withdrawalResult): ?>
<?php
    $wStatus  = $withdrawalResult['auto_paid'] ? 'success' : 'pending';
    $iconCls  = $wStatus === 'success' ? 'fa-check-circle text-success' : 'fa-clock text-warning';
    $badgeCls = $wStatus === 'success' ? 'bg-success' : 'bg-warning text-dark';
    $headline = $wStatus === 'success' ? 'Transfer Sent!' : 'Withdrawal Submitted!';
    $subtext  = $wStatus === 'success'
        ? 'Your funds have been automatically sent to the recipient.'
        : 'Your withdrawal request is being processed. Funds will be sent within 24 hours.';
?>
<div class="modal fade" id="withdrawalResultModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-16 border-0 shadow-lg position-relative" style="overflow:hidden;">
            <div class="modal-body p-4 text-center">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close"></button>

                <div class="mb-3 mt-2">
                    <i class="fas <?= $iconCls ?> fa-4x animate__animated animate__bounceIn"></i>
                </div>
                <h4 class="fw-bold mb-1"><?= e($headline) ?></h4>
                <p class="text-muted small mb-4"><?= e($subtext) ?></p>

                <div class="bg-light p-3 rounded-12 text-start mb-4">
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2 border-light">
                        <span class="text-muted small">Amount:</span>
                        <span class="fw-bold text-dark small"><?= e($withdrawalResult['amount']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2 border-light">
                        <span class="text-muted small">Fee:</span>
                        <span class="fw-bold text-dark small"><?= e($withdrawalResult['fee']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2 border-light">
                        <span class="text-muted small">Recipient:</span>
                        <span class="fw-bold text-dark small"><?= e($withdrawalResult['account_name']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2 border-light">
                        <span class="text-muted small">Bank:</span>
                        <span class="fw-bold text-dark small"><?= e($withdrawalResult['bank']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2 border-light">
                        <span class="text-muted small">Account No:</span>
                        <span class="fw-bold text-dark small"><?= e($withdrawalResult['account_no']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2 border-light">
                        <span class="text-muted small">Reference:</span>
                        <span class="fw-bold text-dark small"><?= e($withdrawalResult['reference']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted small">Status:</span>
                        <span class="badge <?= $badgeCls ?> small"><?= ucfirst($wStatus) ?></span>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary w-50 py-2 rounded-12 fw-bold" data-bs-dismiss="modal">
                        Make Another
                    </button>
                    <a href="<?= APP_URL ?>/transactions" class="btn btn-danger w-50 py-2 rounded-12 fw-bold bg-site-color border-0">
                        View History
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
$(document).ready(function() {
    var modal = new bootstrap.Modal(document.getElementById('withdrawalResultModal'));
    modal.show();
});
</script>
<?php endif; ?>
