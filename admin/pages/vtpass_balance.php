<?php
/**
 * OURCR ONLINE - Admin Panel VTpass API Balance Management
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'VTpass API Balance & Auto-Topup Management';
$currentAdminPage = 'vtpass_balance';

// Default Target VTpass Float Account details
$vtpassAccountNo   = setting('vtpass_account_number', '6920229746');
$vtpassAccountName = setting('vtpass_account_name', 'Nsuhoreidem Nsuhoreidem');
$vtpassBankName    = setting('vtpass_bank_name', 'Moniepoint Microfinance Bank');

// Load current configuration settings
$maxBalance     = (float)setting('vtpass_max_balance', 50000);
$minThreshold   = (float)setting('vtpass_min_threshold', 10000);
$topupAmount    = (float)setting('vtpass_topup_amount', 40000);
$autoTopupOn    = (int)setting('vtpass_auto_topup_enabled', 1) === 1;

// Fetch Live VTpass API Balance
$vtpassBalance = null;
$vtpassBalanceError = null;
try {
    $vtpassBalResult = Ourcr\VTpass::getLiveBalance();
    if ($vtpassBalResult['success']) {
        $vtpassBalance = (float)($vtpassBalResult['data']['balance'] ?? 0);
    } else {
        $vtpassBalanceError = $vtpassBalResult['message'] ?? 'Could not retrieve balance.';
    }
} catch (\Throwable $e) {
    $vtpassBalanceError = $e->getMessage();
    writeLog(LOG_CHAN_VTPASS, 'error', 'Failed to fetch VTpass live balance on vtpass_balance page: ' . $e->getMessage());
}

$error = '';
if (isPost()) {
    try {
        requireCsrf();
        $action = post('action');

        if ($action === 'save_vtpass_settings') {
            $newMaxBalance   = (float)post('max_balance');
            $newMinThreshold = (float)post('min_threshold');
            $newTopupAmount  = (float)post('topup_amount');
            $newAutoTopup    = post('auto_topup_enabled') ? 1 : 0;

            if ($newMinThreshold <= 0 || $newTopupAmount <= 0) {
                throw new Exception("Threshold and top-up amounts must be greater than zero.");
            }

            setSetting('vtpass_max_balance', $newMaxBalance);
            setSetting('vtpass_min_threshold', $newMinThreshold);
            setSetting('vtpass_topup_amount', $newTopupAmount);
            setSetting('vtpass_auto_topup_enabled', $newAutoTopup);

            auditLog('VTPASS_SETTINGS_UPDATED', "Updated VTpass balance settings: Min Threshold=₦{$newMinThreshold}, Topup Amount=₦{$newTopupAmount}, Auto-Credit=" . ($newAutoTopup ? 'ON' : 'OFF'), 'settings', 0);
            setFlash('success', 'VTpass API balance auto-topup settings updated successfully.');
            redirectTo('admin/vtpass-balance');

        } elseif ($action === 'manual_vtpass_topup') {
            $amount   = (float)post('amount');
            $password = post('password');

            $currentAdmin = Ourcr\User::findById(currentUserId());
            if (!$currentAdmin || !verifyPassword($password, $currentAdmin['password_hash'])) {
                throw new Exception("Invalid confirmation password.");
            }

            if ($amount <= 0) {
                throw new Exception("Top-up amount must be greater than zero.");
            }

            $cbnBankCode = \Ourcr\GAPS\GAPS::getBankCodeByName($vtpassBankName);
            if (empty($cbnBankCode)) {
                $cbnBankCode = '090405'; // Default Moniepoint MFB NIP code
            }

            $reference  = 'VT_TOP_' . date('ymdHis') . '_' . rand(1000, 9999);
            $narration  = "VTPASS FLOAT AUTO TOPUP TO " . strtoupper($vtpassAccountName);

            // Execute GAPS Outward Transfer
            $gapsRes = \Ourcr\GAPS\GAPS::processWithdrawal(
                accountNumber: $vtpassAccountNo,
                accountName:   $vtpassAccountName,
                bankCode:      $cbnBankCode,
                amount:        $amount,
                reference:     $reference,
                narration:     $narration,
                bankName:      $vtpassBankName
            );

            $requeryRes = null;
            $isSuccess  = false;
            $finalMessage = $gapsRes['message'] ?? 'VTpass Payout Failed';
            $finalCode    = (string)($gapsRes['code'] ?? '');

            $isNetworkTimeout = in_array($finalCode, ['NETWORK_ERROR', 'TIMEOUT', 'CURL_ERROR', '0', '504', '502'], true) 
                || str_contains(strtolower($finalMessage), 'timed out') 
                || str_contains(strtolower($finalMessage), 'curl error');

            if (!empty($gapsRes['success']) || $isNetworkTimeout || $finalCode === '1010') {
                if ($isNetworkTimeout || $finalCode === '1010') {
                    sleep(3);
                }
                // Immediately Requery transaction status to verify actual payout status
                $requeryRes = \Ourcr\GAPS\GAPS::requeryTransaction($reference);
                $isSuccess    = !empty($requeryRes['success']);
                $finalMessage = $requeryRes['message'] ?? $finalMessage;
                $finalCode    = (string)($requeryRes['code'] ?? $finalCode);

                if (!$isSuccess && $finalCode === '1010') {
                    sleep(3);
                    $requeryRes2 = \Ourcr\GAPS\GAPS::requeryTransaction($reference);
                    if (!empty($requeryRes2['success'])) {
                        $requeryRes   = $requeryRes2;
                        $isSuccess    = true;
                        $finalMessage = $requeryRes2['message'] ?? $finalMessage;
                        $finalCode    = (string)($requeryRes2['code'] ?? '1000');
                    }
                }
            } else {
                $isSuccess = false;
            }

            $metaData     = [
                'single_transfer' => $gapsRes['data'] ?? [],
                'requery'         => $requeryRes['data'] ?? [],
                'code'            => $finalCode,
                'message'         => $finalMessage
            ];

            if ($isSuccess) {
                // Record payout log
                Database::insert(
                    "INSERT INTO wallet_transactions (uuid, user_id, amount, fee, balance_before, balance_after, type, category, status, reference, description, meta, created_at)
                     VALUES (?, ?, ?, 0, 0, 0, 'debit', 'vtpass_topup', 'success', ?, ?, ?, NOW())",
                    [
                        generateUUID(),
                        currentUserId(),
                        $amount,
                        $reference,
                        "Manual VTpass float top-up via GTBank GAPS to {$vtpassBankName} ({$vtpassAccountNo}) - Verified via Requery",
                        json_encode($metaData)
                    ]
                );

                auditLog('VTPASS_MANUAL_TOPUP', "Manually triggered VTpass top-up of ₦{$amount} via GAPS to {$vtpassBankName} {$vtpassAccountNo}. Verified Ref: {$reference}", 'wallet_transactions', 0);
                setFlash('success', "GAPS payout of ₦" . number_format($amount, 2) . " to VTpass Float Account successfully verified and executed! (Ref: {$reference})");
            } else {
                Database::insert(
                    "INSERT INTO wallet_transactions (uuid, user_id, amount, fee, balance_before, balance_after, type, category, status, reference, description, meta, created_at)
                     VALUES (?, ?, ?, 0, 0, 0, 'debit', 'vtpass_topup', 'failed', ?, ?, ?, NOW())",
                    [
                        generateUUID(),
                        currentUserId(),
                        $amount,
                        $reference,
                        "Failed manual VTpass float top-up: {$finalMessage}" . ($finalCode ? " [Code: {$finalCode}]" : ""),
                        json_encode($metaData)
                    ]
                );
                auditLog('VTPASS_MANUAL_TOPUP_FAILED', "Manual VTpass top-up of ₦{$amount} failed via GAPS Requery: {$finalMessage}. Ref: {$reference}", 'wallet_transactions', 0);
                throw new Exception("GAPS Payout Failed (Requery Verification): {$finalMessage}" . ($finalCode ? " (Code: {$finalCode})" : ""));
            }

            redirectTo('admin/vtpass-balance');
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if (!empty($error)) {
    $adminPageScripts = "Swal.fire({icon: 'error', title: 'GAPS Payout Error', text: " . json_encode($error) . ", confirmButtonColor: '#4f46e5'});";
}

// Fetch Top-up Payout Log History
$topupHistory = Database::fetchAll(
    "SELECT * FROM wallet_transactions 
     WHERE category IN ('vtpass_topup', 'vtpass_topup_auto') 
     ORDER BY id DESC LIMIT 50"
);

include ADMIN_PATH . '/includes/header.php';
?>
<div class="admin-wrapper">
    <?php include ADMIN_PATH . '/includes/sidebar.php'; ?>
    
    <main class="admin-main">
        <!-- Topbar -->
        <div class="admin-topbar">
            <div class="d-flex align-items-center gap-3">
                <button class="admin-sidebar-toggle d-lg-none" id="adminSidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="admin-topbar-title"><?= e($adminPageTitle) ?></h1>
            </div>
            <div>
                <a href="<?= APP_URL ?>/admin/income-wallet" class="btn btn-sm btn-outline-secondary rounded-8 font-semibold">
                    <i class="fas fa-arrow-left me-1"></i> Back to Income Wallet
                </a>
            </div>
        </div>

        <!-- Content Area -->
        <div class="admin-content fade-in-up">
            
            <?php if ($error): ?>
                <div class="alert alert-danger py-2 px-3 small rounded-12 mb-4">
                    <i class="fas fa-exclamation-circle me-1"></i><?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php foreach (getFlash() as $flash): ?>
                <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : e($flash['type']) ?> py-2 px-3 small rounded-12 mb-4">
                    <?= e($flash['message']) ?>
                </div>
            <?php endforeach; ?>

            <!-- Spotlight Cards -->
            <div class="row g-3 mb-4">
                <!-- Live VTpass Balance Card -->
                <div class="col-xl-4 col-md-6">
                    <div class="card border-0 shadow-sm rounded-16 p-4 bg-white border-start border-4 border-info">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="small text-muted font-semibold uppercase tracking-wider text-info">Live VTpass API Balance</span>
                            <?php if ($vtpassBalance !== null): ?>
                                <span class="badge bg-success-subtle text-success px-3 py-1 rounded-pill small"><i class="fas fa-signal me-1"></i> Live Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger px-3 py-1 rounded-pill small"><i class="fas fa-times-circle me-1"></i> Offline</span>
                            <?php endif; ?>
                        </div>
                        <h2 class="fw-bold mb-1 text-dark">
                            <?= $vtpassBalance !== null ? formatMoney($vtpassBalance) : '₦0.00' ?>
                        </h2>
                        <?php if ($vtpassBalanceError): ?>
                            <div class="small text-danger mt-1" title="<?= e($vtpassBalanceError) ?>"><i class="fas fa-exclamation-triangle me-1"></i> <?= e(substr($vtpassBalanceError, 0, 45)) ?>...</div>
                        <?php else: ?>
                            <div class="small text-muted mt-1">Refreshed live from VTpass API server</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Auto-Topup Status Card -->
                <div class="col-xl-4 col-md-6">
                    <div class="card border-0 shadow-sm rounded-16 p-4 bg-white border-start border-4 <?= $autoTopupOn ? 'border-success' : 'border-secondary' ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="small text-muted font-semibold uppercase tracking-wider <?= $autoTopupOn ? 'text-success' : 'text-secondary' ?>">Auto Credit Status</span>
                            <span class="badge <?= $autoTopupOn ? 'bg-success' : 'bg-secondary' ?> text-white px-3 py-1 rounded-pill small">
                                <?= $autoTopupOn ? 'ENABLED (ON)' : 'DISABLED (OFF)' ?>
                            </span>
                        </div>
                        <h4 class="fw-bold mb-1 text-dark">Topup ₦<?= number_format($topupAmount, 2) ?> <span class="small text-muted fs-6">(+₦100 fee)</span></h4>
                        <div class="small text-muted">Transfers <strong>₦<?= number_format($topupAmount + 100, 2) ?></strong> when balance drops below <strong>₦<?= number_format($minThreshold, 2) ?></strong></div>
                    </div>
                </div>

                <!-- Quick Action Trigger Card -->
                <div class="col-xl-4 col-md-12">
                    <div class="card border-0 shadow-sm rounded-16 p-4 bg-gradient-primary text-white" style="background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small text-white-50 font-semibold uppercase tracking-wider">Manual GAPS Payout</span>
                            <i class="fas fa-bolt fs-4 text-white-50"></i>
                        </div>
                        <p class="small text-white-80 mb-3">Execute an instant GAPS transfer to VTpass Moniepoint Account now.</p>
                        <button class="btn btn-light text-indigo-700 font-bold w-100 rounded-10 py-2" data-bs-toggle="modal" data-bs-target="#manualTopupModal">
                            <i class="fas fa-paper-plane me-2"></i> Trigger Topup Now
                        </button>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <!-- VTpass Target Account Details Card -->
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-16 bg-white p-4 h-100">
                        <div class="d-flex align-items-center gap-3 mb-3 border-bottom pb-3">
                            <div class="rounded-circle bg-primary-subtle text-primary p-3 d-flex align-items-center justify-content-center" style="width:48px; height:48px;">
                                <i class="fas fa-university fs-4"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-0 text-dark">VTpass Float Account Details</h5>
                                <span class="small text-muted">Target bank account for automated & manual top-ups</span>
                            </div>
                        </div>

                        <div class="bg-light rounded-12 p-3 mb-3">
                            <div class="row mb-2 pb-2 border-bottom">
                                <div class="col-5 text-muted small font-semibold">Bank Name:</div>
                                <div class="col-7 fw-bold text-dark"><?= e($vtpassBankName) ?></div>
                            </div>
                            <div class="row mb-2 pb-2 border-bottom">
                                <div class="col-5 text-muted small font-semibold">Account Name:</div>
                                <div class="col-7 fw-bold text-dark"><?= e($vtpassAccountName) ?></div>
                            </div>
                            <div class="row align-items-center">
                                <div class="col-5 text-muted small font-semibold">Account Number:</div>
                                <div class="col-7 fw-bold fs-5 font-monospace text-primary"><?= e($vtpassAccountNo) ?></div>
                            </div>
                        </div>

                        <div class="alert alert-info py-2 px-3 small border-0 rounded-12 mb-0">
                            <i class="fas fa-info-circle me-1"></i> GAPS outward payouts automatically transfer funds to this account when top-up triggers occur.
                        </div>
                    </div>
                </div>

                <!-- Settings Form Card -->
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-16 bg-white p-4">
                        <h5 class="fw-bold mb-1 text-dark">Auto Credit & Threshold Settings</h5>
                        <p class="small text-muted mb-4">Configure threshold rules for automated GTBank GAPS payouts to VTpass.</p>

                        <form method="POST" action="<?= APP_URL ?>/admin/vtpass-balance">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="save_vtpass_settings">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="max_balance" class="form-label small font-semibold">Target / Max Float Balance (₦)</label>
                                    <input type="number" step="0.01" class="form-control" id="max_balance" name="max_balance" value="<?= e($maxBalance) ?>" required>
                                    <div class="form-text small">Default target balance (e.g. ₦50,000)</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="min_threshold" class="form-label small font-semibold">Minimum Threshold Balance (₦)</label>
                                    <input type="number" step="0.01" class="form-control" id="min_threshold" name="min_threshold" value="<?= e($minThreshold) ?>" required>
                                    <div class="form-text small">Triggers auto-topup when balance drops below this</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="topup_amount" class="form-label small font-semibold">Top-up Transfer Amount (₦)</label>
                                    <input type="number" step="0.01" class="form-control" id="topup_amount" name="topup_amount" value="<?= e($topupAmount) ?>" required>
                                    <div class="form-text small">Amount transferred via GAPS (e.g. ₦40,000). +₦100 deposit fee is automatically added.</div>
                                </div>
                                <div class="col-md-6 d-flex align-items-center">
                                    <div class="form-check form-switch mt-3">
                                        <input class="form-check-input" type="checkbox" role="switch" id="auto_topup_enabled" name="auto_topup_enabled" value="1" <?= $autoTopupOn ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold text-dark ms-2" for="auto_topup_enabled">Enable Automated GAPS Auto-Credit</label>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 pt-3 border-top text-end">
                                <button type="submit" class="btn btn-primary px-4 rounded-10 font-bold" style="background:#4f46e5; border:none;">
                                    <i class="fas fa-save me-1"></i> Save Configuration
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Topup Activity Logs Table -->
            <div class="admin-table-wrapper bg-white shadow-sm rounded-16 border-0">
                <div class="p-4 border-bottom bg-white d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark">VTpass Float Top-up History</h5>
                        <p class="small text-muted mb-0">Log of all manual & automated GAPS payouts sent to VTpass.</p>
                    </div>
                    <span class="badge bg-light-primary text-primary px-3 py-2 rounded-pill font-bold">GAPS Outward Logs</span>
                </div>
                <div class="table-responsive-custom">
                    <table class="admin-table table-hover">
                        <thead>
                            <tr>
                                <th>Ref / Date</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <th>Description / Destination</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($topupHistory)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No top-up payout logs found yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($topupHistory as $log): ?>
                                    <tr>
                                        <td>
                                            <span class="font-monospace fw-bold text-dark d-block"><?= e($log['reference']) ?></span>
                                            <span class="small text-muted"><?= date('d M Y, H:i', strtotime($log['created_at'])) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border font-semibold">
                                                <?= $log['category'] === 'vtpass_topup_auto' ? 'Automated Cron' : 'Manual Admin' ?>
                                            </span>
                                        </td>
                                        <td class="fw-bold text-danger">
                                            -<?= formatMoney($log['amount']) ?>
                                        </td>
                                        <td class="small text-muted" style="max-width:300px;">
                                            <?= e($log['description']) ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($log['status'] === 'success'): ?>
                                                <span class="badge bg-success-subtle text-success px-3 py-1 rounded-pill"><i class="fas fa-check-circle me-1"></i> Success</span>
                                            <?php elseif ($log['status'] === 'pending'): ?>
                                                <span class="badge bg-warning-subtle text-warning px-3 py-1 rounded-pill"><i class="fas fa-clock me-1"></i> Pending</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger px-3 py-1 rounded-pill"><i class="fas fa-times-circle me-1"></i> Failed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- Modal: Manual VTpass Topup Trigger -->
<div class="modal fade" id="manualTopupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-16 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-bolt me-2 text-primary"></i>Trigger Manual VTpass GAPS Payout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= APP_URL ?>/admin/vtpass-balance">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="manual_vtpass_topup">

                <div class="modal-body p-4">
                    <div class="alert alert-warning py-2 px-3 small rounded-8 mb-3">
                        <i class="fas fa-exclamation-triangle me-1"></i> This will immediately initiate a live GTBank GAPS transfer of the requested amount to <strong>Moniepoint MFB (<?= e($vtpassAccountNo) ?>)</strong>.
                    </div>

                    <div class="mb-3">
                        <label for="topup_amount_modal" class="form-label small font-semibold">Amount to Transfer (₦)</label>
                        <input type="number" step="0.01" class="form-control" id="topup_amount_modal" name="amount" value="<?= e($topupAmount) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="password_modal" class="form-label small font-semibold">Confirm Your Admin Password</label>
                        <input type="password" class="form-control" id="password_modal" name="password" required>
                    </div>
                </div>

                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-bold" style="background-color: #4f46e5; border: none;">Execute GAPS Payout</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>
