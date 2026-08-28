<?php
/**
 * OURCR ONLINE - Admin Available Balance & Payout Management
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

// AJAX User Lookup for Internal Transfer
if (get('action') === 'lookup_user') {
    header('Content-Type: application/json');
    $query = trim(get('query') ?? '');
    if (empty($query)) {
        echo json_encode(['success' => false, 'message' => 'Empty query']);
        exit;
    }
    
    $user = Database::fetchOne(
        "SELECT first_name, last_name, username, email FROM users WHERE (username = ? OR email = ?) AND deleted_at IS NULL LIMIT 1",
        [$query, $query]
    );
    
    if ($user) {
        echo json_encode([
            'success' => true,
            'name' => $user['first_name'] . ' ' . $user['last_name'],
            'username' => $user['username'],
            'email' => $user['email']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
    exit;
}

$adminPageTitle = 'Available Admin Balance & Payout Management';

// Compute Income & Available Balance (Exact 1:1 match with Income Wallet)
$adminPct = (float)setting('vtu_commission_admin_share', 34);

$servicesConfig = [
    '9mobile_sme_data' => [
        'match' => function($txn) { return $txn['service_type'] === 'data' && str_contains(strtolower($txn['service_id']), 'etisalat'); },
        'calculator' => function($amount, $qty) { return $amount * 0.04; }
    ],
    'airtel_airtime' => [
        'match' => function($txn) { return $txn['service_type'] === 'airtime' && strtolower($txn['service_id']) === 'airtel'; },
        'calculator' => function($amount, $qty) { return $amount * 0.034; }
    ],
    'airtel_data' => [
        'match' => function($txn) { return $txn['service_type'] === 'data' && str_contains(strtolower($txn['service_id']), 'airtel'); },
        'calculator' => function($amount, $qty) { return $amount * 0.034; }
    ],
    'mtn_airtime' => [
        'match' => function($txn) { return $txn['service_type'] === 'airtime' && strtolower($txn['service_id']) === 'mtn'; },
        'calculator' => function($amount, $qty) { return $amount * 0.03; }
    ],
    'mtn_data' => [
        'match' => function($txn) { return $txn['service_type'] === 'data' && str_contains(strtolower($txn['service_id']), 'mtn'); },
        'calculator' => function($amount, $qty) { return $amount * 0.03; }
    ],
    'glo_airtime' => [
        'match' => function($txn) { return $txn['service_type'] === 'airtime' && strtolower($txn['service_id']) === 'glo'; },
        'calculator' => function($amount, $qty) { return $amount * 0.04; }
    ],
    'glo_data' => [
        'match' => function($txn) { return $txn['service_type'] === 'data' && str_contains(strtolower($txn['service_id']), 'glo'); },
        'calculator' => function($amount, $qty) { return $amount * 0.04; }
    ],
    'aba_electric' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'aba-electric'); },
        'calculator' => function($amount, $qty) { return $amount * 0.017; }
    ],
    'abuja_electric' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'abuja-electric') || str_contains(strtolower($txn['service_id']), 'abuja-electricity'); },
        'calculator' => function($amount, $qty) { return min($amount * 0.012, 1300.00); }
    ],
    'benin_electric' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'benin-electric'); },
        'calculator' => function($amount, $qty) { return $amount * 0.015; }
    ],
    'eko_electric' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'eko-electric'); },
        'calculator' => function($amount, $qty) { return $amount * 0.01; }
    ],
    'enugu_electric' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'enugu-electric'); },
        'calculator' => function($amount, $qty) { return $amount * 0.014; }
    ],
    'ibadan_electric' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'ibadan-electric'); },
        'calculator' => function($amount, $qty) { return 0.00; }
    ],
    'ikeja_electric' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'ikeja-electric'); },
        'calculator' => function($amount, $qty) { return min($amount * 0.01, 1500.00); }
    ],
    'jos_electric' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'jos-electric'); },
        'calculator' => function($amount, $qty) { return $amount * 0.009; }
    ],
    'kaduna_electric' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'kaduna-electric'); },
        'calculator' => function($amount, $qty) { return $amount * 0.015; }
    ],
    'kano_electric' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'kano-electric'); },
        'calculator' => function($amount, $qty) { return 0.00; }
    ],
    'phed_electric' => [
        'match' => function($txn) { $sid = strtolower($txn['service_id'] ?? ''); return str_contains($sid, 'phed') || str_contains($sid, 'portharcourt'); },
        'calculator' => function($amount, $qty) { return $amount * 0.011; }
    ],
    'yola_electric' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'yola-electric') || str_contains(strtolower($txn['service_id']), 'yola-disco'); },
        'calculator' => function($amount, $qty) { return $amount * 0.012; }
    ],
    'dstv' => [
        'match' => function($txn) { return strtolower($txn['service_id']) === 'dstv'; },
        'calculator' => function($amount, $qty) { return $amount * 0.015; }
    ],
    'gotv' => [
        'match' => function($txn) { return strtolower($txn['service_id']) === 'gotv'; },
        'calculator' => function($amount, $qty) { return $amount * 0.015; }
    ],
    'startimes' => [
        'match' => function($txn) { return strtolower($txn['service_id']) === 'startimes'; },
        'calculator' => function($amount, $qty) { return $amount * 0.02; }
    ],
    'smile_network' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'smile'); },
        'calculator' => function($amount, $qty) { return $amount * 0.05; }
    ],
    'smsclone' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'smsclone'); },
        'calculator' => function($amount, $qty) { return $amount * 0.03; }
    ],
    'waec_registration' => [
        'match' => function($txn) {
            $sid = strtolower($txn['service_id'] ?? '');
            $var = strtolower($txn['variation_code'] ?? '');
            return $sid === 'waec-registration' || str_contains($sid, 'waec-reg') || ($sid === 'waec' && (str_contains($var, 'registration') || str_contains($var, 'register')));
        },
        'calculator' => function($amount, $qty) { return 150.00 * max(1, $qty); }
    ],
    'waec_result' => [
        'match' => function($txn) {
            $sid = strtolower($txn['service_id'] ?? '');
            $var = strtolower($txn['variation_code'] ?? '');
            return ($sid === 'waec' || str_contains($sid, 'waec-direct') || str_contains($sid, 'waec-result')) && !str_contains($var, 'registration') && !str_contains($var, 'register');
        },
        'calculator' => function($amount, $qty) { return 250.00 * max(1, $qty); }
    ],
    'jamb' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'jamb'); },
        'calculator' => function($amount, $qty) { return 100.00 * max(1, $qty); }
    ],
    'international_airtime' => [
        'match' => function($txn) { return str_contains(strtolower($txn['service_id']), 'international') || str_contains(strtolower($txn['service_id']), 'foreign'); },
        'calculator' => function($amount, $qty) { return $amount * 0.03; }
    ],
];

$vtpassTransactions = Database::fetchAll(
    "SELECT vt.*, u.username, u.referred_by 
     FROM vtpass_transactions vt
     LEFT JOIN users u ON u.id = vt.user_id
     WHERE vt.status = 'success'
     ORDER BY vt.created_at DESC"
);

$totalVtuCommission = 0.0;
foreach ($vtpassTransactions as $txn) {
    $matchedKey = 'unmapped';
    foreach ($servicesConfig as $key => $conf) {
        if ($conf['match']($txn)) {
            $matchedKey = $key;
            break;
        }
    }

    if ($matchedKey === 'unmapped') {
        $comm = 0.00;
    } else {
        $totalApiComm   = $servicesConfig[$matchedKey]['calculator']((float)$txn['amount'], (int)($txn['quantity'] ?? 1));
        $remainingPool  = $totalApiComm * 0.95;
        $comm           = round($remainingPool * ($adminPct / 100), 2);
    }
    $totalVtuCommission += $comm;
}

$platformFees = Database::fetchAll(
    "SELECT category, SUM(fee) as total_fee, COUNT(id) as count 
     FROM wallet_transactions 
     WHERE status = 'success' AND fee > 0 
     GROUP BY category"
);

$totalFees = 0.0;
foreach ($platformFees as $pf) {
    $totalFees += (float)$pf['total_fee'];
}

$overallIncome = $totalVtuCommission + $totalFees;

$totalDevPaid = (float)Database::fetchOne(
    "SELECT SUM(amount) as total FROM wallet_transactions WHERE category = 'developer_income' AND status = 'success'"
)['total'];

$totalTransferred = (float)Database::fetchOne(
    "SELECT SUM(amount) as total FROM wallet_transactions WHERE category = 'admin_payout' AND status = 'success'"
)['total'];

$availableBalance = $overallIncome - $totalDevPaid - $totalTransferred;

$error = '';
if (isPost()) {
    try {
        requireCsrf();
        $action = post('action');

        if ($action === 'transfer_income') {
            $recipientUsernameOrEmail = sanitizeString(post('recipient'));
            $amount = (float)post('amount');
            $narrative = sanitizeString(post('narrative'));
            $password = post('password');

            $currentAdmin = Ourcr\User::findById(currentUserId());
            if (!$currentAdmin || !verifyPassword($password, $currentAdmin['password_hash'])) {
                throw new Exception("Invalid confirmation password.");
            }

            if ($amount <= 0) {
                throw new Exception("Transfer amount must be greater than zero.");
            }

            if ($amount > $availableBalance) {
                throw new Exception("Insufficient admin income balance. (Available: ₦" . number_format($availableBalance, 2) . ")");
            }

            $recipient = Database::fetchOne(
                "SELECT * FROM users WHERE (username = ? OR email = ?) AND deleted_at IS NULL LIMIT 1",
                [$recipientUsernameOrEmail, $recipientUsernameOrEmail]
            );
            if (!$recipient) {
                throw new Exception("Recipient user account not found.");
            }

            Database::beginTransaction();
            try {
                creditWallet(
                    (int)$recipient['id'],
                    $amount,
                    'admin_payout',
                    'Transfer of admin income: ' . $narrative
                );
                auditLog('ADMIN_INCOME_TRANSFER', "Transferred ₦$amount of admin income to user ID {$recipient['id']}. Narrative: $narrative", 'users', $recipient['id']);
                Database::commit();
                setFlash('success', "Successfully transferred ₦" . number_format($amount, 2) . " to @" . $recipient['username'] . ".");
                redirectTo('admin/admin-payouts');
            } catch (Exception $ex) {
                Database::rollback();
                throw $ex;
            }

        } elseif ($action === 'transfer_income_external') {
            $bankName      = sanitizeString(post('ext_bank_name'));
            $accountNumber = sanitizeString(post('ext_account_no'));
            $accountName   = sanitizeString(post('ext_account_name'));
            $amount        = (float)post('ext_amount');
            $narrative     = sanitizeString(post('ext_narrative'));
            $password      = post('password');

            $currentAdmin = Ourcr\User::findById(currentUserId());
            if (!$currentAdmin || !verifyPassword($password, $currentAdmin['password_hash'])) {
                throw new Exception("Invalid confirmation password.");
            }

            if ($amount <= 0) {
                throw new Exception("Transfer amount must be greater than zero.");
            }

            if ($amount > $availableBalance) {
                throw new Exception("Insufficient admin income balance. (Available: ₦" . number_format($availableBalance, 2) . ")");
            }

            if (empty($bankName) || empty($accountNumber) || empty($accountName)) {
                throw new Exception("Please select a bank, enter a 10-digit account number, and provide the recipient account name.");
            }

            $cbnBankCode = \Ourcr\GAPS\GAPS::getBankCodeByName($bankName);
            if (empty($cbnBankCode)) {
                $cbnBankCode = '044';
            }

            $reference = 'ADM_PAY_' . date('ymdHis') . '_' . rand(1000, 9999);

            // Execute GTBank GAPS Outward Transfer
            $gapsRes = \Ourcr\GAPS\GAPS::processWithdrawal(
                accountNumber: $accountNumber,
                accountName:   $accountName,
                bankCode:      $cbnBankCode,
                amount:        $amount,
                reference:     $reference,
                narration:     $narrative,
                bankName:      $bankName
            );

            $requeryRes = null;
            $isSuccess  = false;
            $finalMessage = $gapsRes['message'] ?? 'Admin Payout Failed';
            $finalCode    = (string)($gapsRes['code'] ?? '');

            $isNetworkTimeout = in_array($finalCode, ['NETWORK_ERROR', 'TIMEOUT', 'CURL_ERROR', '0', '504', '502'], true) 
                || str_contains(strtolower($finalMessage), 'timed out') 
                || str_contains(strtolower($finalMessage), 'curl error');

            if (!empty($gapsRes['success']) || $isNetworkTimeout || $finalCode === '1010') {
                if ($isNetworkTimeout || $finalCode === '1010') {
                    sleep(3);
                }
                // Immediate Requery Status Verification
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
                Database::insert(
                    "INSERT INTO wallet_transactions (uuid, user_id, amount, fee, balance_before, balance_after, type, category, status, reference, description, meta, created_at)
                     VALUES (?, ?, ?, 0, 0, 0, 'debit', 'admin_payout', 'success', ?, ?, ?, NOW())",
                    [
                        generateUUID(),
                        currentUserId(),
                        $amount,
                        $reference,
                        "External Admin Income Payout via GAPS to {$bankName} - {$accountName} ({$accountNumber}) - {$narrative} - Verified via Requery",
                        json_encode($metaData)
                    ]
                );
                auditLog('ADMIN_INCOME_EXTERNAL_TRANSFER', "Transferred ₦$amount of admin income via GAPS to {$accountName} ({$accountNumber}, {$bankName}). Verified Ref: $reference", 'wallet_transactions', 0);
                setFlash('success', "GAPS Payout of ₦" . number_format($amount, 2) . " to {$accountName} ({$accountNumber}, {$bankName}) successfully verified and executed! (Ref: {$reference})");
                redirectTo('admin/admin-payouts');
            } else {
                Database::insert(
                    "INSERT INTO wallet_transactions (uuid, user_id, amount, fee, balance_before, balance_after, type, category, status, reference, description, meta, created_at)
                     VALUES (?, ?, ?, 0, 0, 0, 'debit', 'admin_payout', 'failed', ?, ?, ?, NOW())",
                    [
                        generateUUID(),
                        currentUserId(),
                        $amount,
                        $reference,
                        "Failed External Admin Income Payout: {$finalMessage}" . ($finalCode ? " [Code: {$finalCode}]" : ""),
                        json_encode($metaData)
                    ]
                );
                auditLog('ADMIN_INCOME_EXTERNAL_TRANSFER_FAILED', "External Admin Income Payout of ₦$amount to {$accountName} ({$accountNumber}, {$bankName}) failed via Requery: {$finalMessage}. Ref: $reference", 'wallet_transactions', 0);
                throw new Exception("GAPS Payout Failed (Requery Verification): {$finalMessage}" . ($finalCode ? " (Code: {$finalCode})" : ""));
            }
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if (!empty($error)) {
    $adminPageScripts = "Swal.fire({icon: 'error', title: 'GAPS Payout Error', text: " . json_encode($error) . ", confirmButtonColor: '#4f46e5'});";
}

// Fetch Admin Payout History Logs
$payoutHistory = Database::fetchAll(
    "SELECT wt.*, u.username, u.first_name, u.last_name 
     FROM wallet_transactions wt
     LEFT JOIN users u ON wt.user_id = u.id
     WHERE wt.category = 'admin_payout' 
     ORDER BY wt.id DESC LIMIT 50"
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
                <div class="col-xl-3 col-md-6">
                    <div class="card border-0 shadow-sm rounded-16 p-4 position-relative overflow-hidden border-start border-4 border-dark bg-white" style="height: 130px;">
                        <div class="position-relative z-index-2">
                            <span class="small text-muted mb-1 d-block font-semibold uppercase tracking-wider text-dark">Lifetime Total Earnings</span>
                            <h2 class="fw-bold mb-0 text-dark"><?= formatMoney($overallIncome) ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card border-0 shadow-sm rounded-16 p-4 position-relative overflow-hidden border-start border-4 border-warning bg-white" style="height: 130px;">
                        <div class="position-relative z-index-2">
                            <span class="small text-muted mb-1 d-block font-semibold uppercase tracking-wider text-warning">Developer Income (5%)</span>
                            <h2 class="fw-bold mb-0 text-dark"><?= formatMoney($overallIncome * 0.05) ?></h2>
                            <p class="small text-muted mb-0 mt-1">Paid: <span class="fw-bold text-success"><?= formatMoney($totalDevPaid) ?></span></p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card border-0 shadow-sm rounded-16 p-4 position-relative overflow-hidden border-start border-4 border-primary bg-white" style="height: 130px;">
                        <div class="position-relative z-index-2">
                            <span class="small text-muted mb-1 d-block font-semibold uppercase tracking-wider text-primary">Available Admin Balance</span>
                            <h2 class="fw-bold mb-0 text-dark"><?= formatMoney($availableBalance) ?></h2>
                            <p class="small text-muted mb-0 mt-1">Ready for transfer</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card border-0 shadow-sm rounded-16 p-4 position-relative overflow-hidden border-start border-4 border-success bg-white" style="height: 130px;">
                        <div class="position-relative z-index-2">
                            <span class="small text-muted mb-1 d-block font-semibold uppercase tracking-wider text-success">Total Transferred Payouts</span>
                            <h2 class="fw-bold mb-0 text-dark"><?= formatMoney($totalTransferred) ?></h2>
                            <p class="small text-muted mb-0 mt-1">Paid out to date</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transfer Management Section (Dual Tabs) -->
            <div class="card border-0 shadow-sm rounded-16 bg-white mb-4 overflow-hidden">
                <div class="p-4 border-bottom bg-white d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-paper-plane me-2 text-primary"></i>Transfer Admin Income Balance</h5>
                        <p class="small text-muted mb-0">Execute internal user wallet transfers or external GTBank GAPS bank payouts.</p>
                    </div>
                    <span class="badge bg-light-primary text-primary px-3 py-2 rounded-pill font-bold">Max: <?= formatMoney($availableBalance) ?></span>
                </div>

                <!-- Tab Header -->
                <div class="px-4 pt-3 bg-light border-bottom">
                    <ul class="nav nav-tabs border-0" id="payoutPageTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active fw-bold py-2.5 px-4" id="internal-payout-tab" data-bs-toggle="tab" data-bs-target="#internal-payout" type="button" role="tab" aria-controls="internal-payout" aria-selected="true">
                                <i class="fas fa-user-friends me-2"></i> Internal Transfer (User Wallet)
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link fw-bold py-2.5 px-4 text-danger" id="external-payout-tab" data-bs-toggle="tab" data-bs-target="#external-payout" type="button" role="tab" aria-controls="external-payout" aria-selected="false">
                                <i class="fas fa-university me-2"></i> External Bank Payout (GAPS API)
                            </button>
                        </li>
                    </ul>
                </div>

                <div class="tab-content p-4" id="payoutPageTabsContent">
                    
                    <!-- TAB 1: Internal Transfer -->
                    <div class="tab-pane fade show active" id="internal-payout" role="tabpanel" aria-labelledby="internal-payout-tab">
                        <form method="POST" action="<?= APP_URL ?>/admin/admin-payouts">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="transfer_income">

                            <div class="alert alert-warning py-2 px-3 small rounded-12 mb-4">
                                <i class="fas fa-exclamation-triangle me-1"></i> Immediately credits the target platform user's wallet from Available Admin Income. Action is irreversible.
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="recipient" class="form-label small font-semibold">Recipient Username or Email</label>
                                    <input type="text" class="form-control" id="recipient" name="recipient" placeholder="e.g. john_doe" autocomplete="off" required>
                                    <div id="recipient-chip-container" class="mt-2" style="display: none;">
                                        <span class="badge px-3 py-2 rounded-pill border d-inline-flex align-items-center gap-1.5" style="background: rgba(79, 70, 229, 0.08); color: #4f46e5; border-color: rgba(79, 70, 229, 0.2); font-size: 13px; font-weight: 600;">
                                            <i class="fas fa-user-check"></i>
                                            <span id="recipient-full-name"></span>
                                        </span>
                                    </div>
                                    <div id="recipient-error" class="small text-danger mt-1" style="display: none;">
                                        <i class="fas fa-times-circle me-1"></i> User not found
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label for="amount" class="form-label small font-semibold">Amount to Transfer (₦)</label>
                                    <input type="number" class="form-control" id="amount" name="amount" step="0.01" max="<?= $availableBalance ?>" placeholder="Max: <?= number_format($availableBalance, 2, '.', '') ?>" required>
                                </div>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="narrative" class="form-label small font-semibold">Transfer Narrative / Reason</label>
                                    <input type="text" class="form-control" id="narrative" name="narrative" placeholder="e.g. Platform promotion payouts" required>
                                </div>

                                <div class="col-md-6">
                                    <label for="password" class="form-label small font-semibold">Confirm Your Admin Password</label>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                </div>
                            </div>

                            <div class="text-end border-top pt-3">
                                <button type="submit" class="btn btn-primary px-4 py-2.5 rounded-10 font-bold" style="background-color: #4f46e5; border: none;">
                                    <i class="fas fa-paper-plane me-1"></i> Execute Internal Transfer
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- TAB 2: External Bank Payout (GAPS API) -->
                    <div class="tab-pane fade" id="external-payout" role="tabpanel" aria-labelledby="external-payout-tab">
                        <form method="POST" action="<?= APP_URL ?>/admin/admin-payouts">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="transfer_income_external">

                            <div class="alert alert-info py-2 px-3 small rounded-12 mb-4">
                                <i class="fas fa-paper-plane me-1"></i> Sends funds directly to an external local bank account using GTBank GAPS API payout engine.
                            </div>

                            <!-- 1. Account Number (Placed FIRST like on /withdraw) -->
                            <div class="mb-3">
                                <label for="ext_account_no" class="form-label small font-semibold">Account Number</label>
                                <input type="text" 
                                       class="form-control font-monospace" 
                                       id="ext_account_no" 
                                       name="ext_account_no" 
                                       maxlength="10" 
                                       placeholder="10-digit number" 
                                       required>
                            </div>

                            <!-- 2. Recipient Bank Name (Searchable Dropdown like on /withdraw) -->
                            <div class="mb-3">
                                <label class="form-label small font-semibold">Recipient Bank Name</label>
                                <div class="custom-bank-dropdown" id="extBankDropdownWrapper" style="position:relative;">
                                    <button type="button" id="extBankDropdownBtn" class="custom-bank-toggle"
                                            style="width:100%; background:#fff; border:1px solid #ced4da; color:#4b5563; padding:0.5rem 0.75rem; border-radius:8px; text-align:left; display:flex; justify-content:space-between; align-items:center; cursor:pointer;">
                                        <span id="extBankDropdownLabel">Select Bank</span>
                                        <i class="fas fa-chevron-down" style="font-size:12px;"></i>
                                    </button>
                                    <div id="extBankDropdownPanel" style="display:none; position:absolute; z-index:9999; background:#fff; border:1px solid #ced4da; border-radius:12px; box-shadow:0 8px 24px rgba(0,0,0,0.12); padding:12px; width:100%; max-height:280px; overflow:hidden; margin-top:2px;">
                                        <input type="text" id="extBankSearchInput" class="form-control mb-2" placeholder="Type to search..." autocomplete="off" style="border-radius:8px;">
                                        <div id="extBankListQueue" style="max-height:200px; overflow-y:auto;">
                                            <div class="bank-option ext-bank-option" data-value="Access Bank">Access Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Carbon">Carbon</div>
                                            <div class="bank-option ext-bank-option" data-value="Citibank">Citibank</div>
                                            <div class="bank-option ext-bank-option" data-value="Ecobank">Ecobank</div>
                                            <div class="bank-option ext-bank-option" data-value="Fairmoney MFB">Fairmoney MFB</div>
                                            <div class="bank-option ext-bank-option" data-value="Fidelity Bank">Fidelity Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="First Bank of Nigeria">First Bank of Nigeria</div>
                                            <div class="bank-option ext-bank-option" data-value="First City Monument Bank (FCMB)">First City Monument Bank (FCMB)</div>
                                            <div class="bank-option ext-bank-option" data-value="Globus Bank">Globus Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Guaranty Trust Bank (GTBank)">Guaranty Trust Bank (GTBank)</div>
                                            <div class="bank-option ext-bank-option" data-value="Heritage Bank">Heritage Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Keystone Bank">Keystone Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Kuda Microfinance Bank">Kuda Microfinance Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Lotus Bank">Lotus Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Moniepoint MFB">Moniepoint MFB</div>
                                            <div class="bank-option ext-bank-option" data-value="OPay (Digital Wallet)">OPay (Digital Wallet)</div>
                                            <div class="bank-option ext-bank-option" data-value="OPTIMUS Bank">OPTIMUS Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="PalmPay">PalmPay</div>
                                            <div class="bank-option ext-bank-option" data-value="Paragon MFB">Paragon MFB</div>
                                            <div class="bank-option ext-bank-option" data-value="PremiumTrust Bank">PremiumTrust Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Providus Bank">Providus Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Rubies MFB">Rubies MFB</div>
                                            <div class="bank-option ext-bank-option" data-value="Signature Bank">Signature Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Stanbic IBTC Bank">Stanbic IBTC Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Standard Chartered Bank">Standard Chartered Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Sterling Bank">Sterling Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="SunTrust Bank">SunTrust Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Taj Bank">Taj Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Titan Trust Bank">Titan Trust Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Union Bank of Nigeria">Union Bank of Nigeria</div>
                                            <div class="bank-option ext-bank-option" data-value="United Bank for Africa (UBA)">United Bank for Africa (UBA)</div>
                                            <div class="bank-option ext-bank-option" data-value="Unity Bank">Unity Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="VFD Microfinance Bank">VFD Microfinance Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Wema Bank">Wema Bank</div>
                                            <div class="bank-option ext-bank-option" data-value="Zenith Bank">Zenith Bank</div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="ext_bank_name" id="selected_ext_bank_name" value="" required>
                                </div>
                            </div>

                            <!-- 3. Account Name (Auto-fetched via Paystack/AccountResolver) -->
                            <div class="mb-3">
                                <label for="ext_account_name" class="form-label small font-semibold">Account Name</label>
                                <input type="text" 
                                       class="form-control fw-bold text-dark" 
                                       id="ext_account_name" 
                                       name="ext_account_name" 
                                       placeholder="Will be fetched automatically, or type manually" 
                                       required>
                                <div id="ext-account-name-alert" style="display:none;" class="mt-2"></div>
                            </div>

                            <!-- 4. Amount -->
                            <div class="mb-3">
                                <label for="ext_amount" class="form-label small font-semibold">Amount to Transfer (₦)</label>
                                <input type="number" class="form-control" id="ext_amount" name="ext_amount" step="0.01" max="<?= $availableBalance ?>" placeholder="Max: <?= number_format($availableBalance, 2, '.', '') ?>" required>
                            </div>

                            <!-- 5. Narrative / Purpose -->
                            <div class="mb-3">
                                <label for="ext_narrative" class="form-label small font-semibold">Transfer Narrative / Purpose</label>
                                <input type="text" class="form-control" id="ext_narrative" name="ext_narrative" placeholder="e.g. Admin Payout to Director" required>
                            </div>

                            <!-- 6. Password -->
                            <div class="mb-3">
                                <label for="ext_password" class="form-label small font-semibold">Confirm Your Admin Password</label>
                                <input type="password" class="form-control" id="ext_password" name="password" required>
                            </div>

                            <div class="text-end border-top pt-3">
                                <button type="submit" class="btn btn-danger px-4 py-2.5 rounded-10 font-bold" id="btnSubmitExtTransfer" style="background-color: #dc2626; border: none;">
                                    <i class="fas fa-university me-1"></i> Execute GAPS Payout
                                </button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>

            <!-- Payout History Table -->
            <div class="admin-table-wrapper bg-white shadow-sm rounded-16 border-0">
                <div class="p-4 border-bottom bg-white d-flex align-items-center justify-content-between">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark">Admin Payouts History</h5>
                        <p class="small text-muted mb-0">Record of all internal and external admin balance payouts.</p>
                    </div>
                    <span class="badge bg-light-primary text-primary px-3 py-2 rounded-pill font-bold">Payout Logs</span>
                </div>
                <div class="table-responsive-custom">
                    <table class="admin-table table-hover">
                        <thead>
                            <tr>
                                <th>Reference / Date</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Description / Recipient</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payoutHistory)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No admin payout history recorded yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($payoutHistory as $log): ?>
                                    <tr>
                                        <td>
                                            <span class="font-monospace fw-bold text-dark d-block"><?= e($log['reference']) ?></span>
                                            <span class="small text-muted"><?= date('d M Y, H:i', strtotime($log['created_at'])) ?></span>
                                        </td>
                                        <td>
                                            <?php if (str_contains(strtolower($log['description']), 'external')): ?>
                                                <span class="badge bg-danger-subtle text-danger px-2.5 py-1 rounded-pill"><i class="fas fa-university me-1"></i> External GAPS</span>
                                            <?php else: ?>
                                                <span class="badge bg-primary-subtle text-primary px-2.5 py-1 rounded-pill"><i class="fas fa-user-friends me-1"></i> Internal User</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-bold text-dark">
                                            <?= formatMoney($log['amount']) ?>
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

<?php include ADMIN_PATH . '/includes/footer.php'; ?>

<script>
$(document).ready(function() {
    let debounceTimer;
    const recipientInput = document.getElementById('recipient');
    const chipContainer = document.getElementById('recipient-chip-container');
    const fullNameSpan = document.getElementById('recipient-full-name');
    const errorContainer = document.getElementById('recipient-error');

    if (recipientInput) {
        recipientInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const query = recipientInput.value.trim();

            if (query.length < 2) {
                chipContainer.style.display = 'none';
                errorContainer.style.display = 'none';
                return;
            }

            debounceTimer = setTimeout(() => {
                fetch('<?= APP_URL ?>/admin/admin-payouts?action=lookup_user&query=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            fullNameSpan.textContent = data.name + ' (@' + data.username + ')';
                            chipContainer.style.display = 'inline-block';
                            errorContainer.style.display = 'none';
                        } else {
                            chipContainer.style.display = 'none';
                            errorContainer.style.display = 'block';
                        }
                    })
                    .catch(err => {
                        console.error('User lookup error:', err);
                        chipContainer.style.display = 'none';
                        errorContainer.style.display = 'none';
                    });
            }, 300);
        });
    }

    // ── External GAPS Bank Dropdown & Paystack Account Name Resolver ──────────────
    var $extWrapper = $('#extBankDropdownWrapper');
    var $extBtn     = $('#extBankDropdownBtn');
    var $extPanel   = $('#extBankDropdownPanel');

    $extBtn.on('click', function(e) {
        e.stopPropagation();
        var isOpen = $extPanel.is(':visible');
        if (isOpen) {
            $extPanel.hide();
            $extBtn.find('i').removeClass('fa-chevron-up').addClass('fa-chevron-down');
        } else {
            $extPanel.show();
            $extBtn.find('i').removeClass('fa-chevron-down').addClass('fa-chevron-up');
            $('#extBankSearchInput').val('').trigger('input').focus();
        }
    });

    $(document).on('click', function(e) {
        if ($extWrapper.length && !$extWrapper[0].contains(e.target)) {
            $extPanel.hide();
            $extBtn.find('i').removeClass('fa-chevron-up').addClass('fa-chevron-down');
        }
    });

    $('#extBankSearchInput').on('input', function(e) {
        e.stopPropagation();
        var val = $(this).val().toLowerCase();
        $('.ext-bank-option').each(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(val) > -1);
        });
    });

    $('#extBankListQueue').on('click', '.ext-bank-option', function(e) {
        e.stopPropagation();
        var selectedVal = $(this).data('value');
        $('#selected_ext_bank_name').val(selectedVal);
        $('#extBankDropdownLabel').text(selectedVal);
        $extBtn.css({'border-color': '#4f46e5', 'color': '#111'});
        $extPanel.hide();
        $extBtn.find('i').removeClass('fa-chevron-up').addClass('fa-chevron-down');
        fetchExtAccountName();
    });

    function showExtAccountAlert(type, msg) {
        var cls = type === 'success' ? 'alert-success' : 'alert-warning';
        var icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        $('#ext-account-name-alert').html('<div class="alert ' + cls + ' py-1.5 px-3 small rounded-8 mb-0"><i class="fas ' + icon + ' me-1"></i> ' + msg + '</div>').show();
    }

    function fetchExtAccountName() {
        var accountNo = $('#ext_account_no').val().replace(/\D/g, '');
        var bankName  = $('#selected_ext_bank_name').val();

        if (accountNo.length === 10 && bankName) {
            $('#ext-account-name-alert').hide().html('');
            $('#ext_account_name').prop('disabled', true).val('Fetching account name...');
            $('#btnSubmitExtTransfer').prop('disabled', true);

            $.ajax({
                url: '<?= APP_URL ?>/api/wallet.php?action=resolve_account',
                type: 'POST',
                data: {
                    account_number: accountNo,
                    bank_name: bankName,
                    _csrf_token: '<?= csrfToken() ?>',
                    csrf_token: '<?= csrfToken() ?>'
                },
                dataType: 'json',
                success: function(res) {
                    if (res.success && res.account_name) {
                        $('#ext_account_name').val(res.account_name);
                        showExtAccountAlert('success', 'Account verified: ' + res.account_name);
                    } else {
                        $('#ext_account_name').val('');
                        var msg = res.message || 'Could not verify account name.';
                        if (!msg.toLowerCase().includes('manually')) {
                            msg += ' — You can type the account name manually.';
                        }
                        showExtAccountAlert('warning', msg);
                    }
                },
                error: function(xhr) {
                    $('#ext_account_name').val('');
                    var err = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Account name verification temporarily unavailable.';
                    if (!err.toLowerCase().includes('manually')) {
                        err += ' — You can type the account name manually.';
                    }
                    showExtAccountAlert('warning', err);
                },
                complete: function() {
                    $('#ext_account_name').prop('disabled', false);
                    $('#btnSubmitExtTransfer').prop('disabled', false);
                }
            });
        }
    }

    // Trigger name fetch when account number hits 10 digits
    $('#ext_account_no').on('input', function() {
        var val = $(this).val().replace(/\D/g, '');
        $(this).val(val);
        $('#ext-account-name-alert').hide();
        if (val.length === 10) {
            fetchExtAccountName();
        }
    });
});
</script>
