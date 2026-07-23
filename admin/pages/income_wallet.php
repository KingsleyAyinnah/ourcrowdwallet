<?php
/**
 * OURCR ONLINE - Admin Panel Income Wallet
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

// AJAX User Lookup for Transfer Modal
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

$adminPageTitle = 'Admin Income Wallet';

$adminPct = (float)setting('vtu_commission_admin_share', 34);
$userPct  = (float)setting('vtu_commission_user_share', 66);
$refPct   = (float)setting('referral_bonus_percentage', 10);

function getAdminShareRateStr(string $rateStr, float $adminPct): string
{
    if (strpos($rateStr, '%') !== false) {
        $val = (float)str_replace('%', '', $rateStr);
        return number_format($val * 0.95 * ($adminPct / 100), 2) . '%';
    }
    if (strpos($rateStr, '₦') !== false) {
        $clean = str_replace(['₦', '(Flat)', ' '], '', $rateStr);
        $val = (float)$clean;
        return '₦' . number_format($val * 0.95 * ($adminPct / 100), 2) . ' (Flat)';
    }
    return '0.00%';
}

function getUserShareRateStr(string $rateStr, float $userPct): string
{
    if (strpos($rateStr, '%') !== false) {
        $val = (float)str_replace('%', '', $rateStr);
        return number_format($val * 0.95 * ($userPct / 100), 2) . '%';
    }
    if (strpos($rateStr, '₦') !== false) {
        $clean = str_replace(['₦', '(Flat)', ' '], '', $rateStr);
        $val = (float)$clean;
        return '₦' . number_format($val * 0.95 * ($userPct / 100), 2) . ' (Flat)';
    }
    return '0.00%';
}

// ─── 1. Service Mapping Configuration ───
$servicesConfig = [
    '9mobile_sme_data' => [
        'name' => '9mobile SME Data',
        'rate_str' => '4.00%',
        'cap_str' => '—',
        'match' => function($txn) {
            return $txn['service_type'] === 'data' && str_contains(strtolower($txn['service_id']), 'etisalat');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.04;
        }
    ],
    'airtel_airtime' => [
        'name' => 'Airtel Airtime VTU',
        'rate_str' => '3.40%',
        'cap_str' => '—',
        'match' => function($txn) {
            return $txn['service_type'] === 'airtime' && strtolower($txn['service_id']) === 'airtel';
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.034;
        }
    ],
    'airtel_data' => [
        'name' => 'Airtel Data',
        'rate_str' => '3.40%',
        'cap_str' => '—',
        'match' => function($txn) {
            return $txn['service_type'] === 'data' && str_contains(strtolower($txn['service_id']), 'airtel');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.034;
        }
    ],
    'mtn_airtime' => [
        'name' => 'MTN Airtime VTU',
        'rate_str' => '3.00%',
        'cap_str' => '—',
        'match' => function($txn) {
            return $txn['service_type'] === 'airtime' && strtolower($txn['service_id']) === 'mtn';
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.03;
        }
    ],
    'mtn_data' => [
        'name' => 'MTN Data',
        'rate_str' => '3.00%',
        'cap_str' => '—',
        'match' => function($txn) {
            return $txn['service_type'] === 'data' && str_contains(strtolower($txn['service_id']), 'mtn');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.03;
        }
    ],
    'glo_airtime' => [
        'name' => 'GLO Airtime VTU',
        'rate_str' => '4.00%',
        'cap_str' => '—',
        'match' => function($txn) {
            return $txn['service_type'] === 'airtime' && strtolower($txn['service_id']) === 'glo';
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.04;
        }
    ],
    'glo_data' => [
        'name' => 'GLO Data',
        'rate_str' => '4.00%',
        'cap_str' => '—',
        'match' => function($txn) {
            return $txn['service_type'] === 'data' && str_contains(strtolower($txn['service_id']), 'glo');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.04;
        }
    ],
    'aba_electric' => [
        'name' => 'Aba Electric Payment - ABEDC',
        'rate_str' => '1.70%',
        'cap_str' => '—',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'aba-electric');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.017;
        }
    ],
    'abuja_electric' => [
        'name' => 'Abuja Electricity AEDC',
        'rate_str' => '1.20%',
        'cap_str' => '₦1,300.00',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'abuja-electric') || str_contains(strtolower($txn['service_id']), 'abuja-electricity');
        },
        'calculator' => function($amount, $qty) {
            return min($amount * 0.012, 1300.00);
        }
    ],
    'benin_electric' => [
        'name' => 'Benin Electricity - BEDC',
        'rate_str' => '1.50%',
        'cap_str' => '—',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'benin-electric');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.015;
        }
    ],
    'eko_electric' => [
        'name' => 'Eko Electric Payment - EKEDC',
        'rate_str' => '1.00%',
        'cap_str' => '—',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'eko-electric');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.01;
        }
    ],
    'enugu_electric' => [
        'name' => 'Enugu Electric - EEDC',
        'rate_str' => '1.40%',
        'cap_str' => '—',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'enugu-electric');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.014;
        }
    ],
    'ibadan_electric' => [
        'name' => 'IBEDC - Ibadan Electricity',
        'rate_str' => '1.10%',
        'cap_str' => '₦0.00',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'ibadan-electric');
        },
        'calculator' => function($amount, $qty) {
            return 0.00;
        }
    ],
    'ikeja_electric' => [
        'name' => 'Ikeja Electric Payment - IKEDC',
        'rate_str' => '1.00%',
        'cap_str' => '₦1,500.00',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'ikeja-electric');
        },
        'calculator' => function($amount, $qty) {
            return min($amount * 0.01, 1500.00);
        }
    ],
    'jos_electric' => [
        'name' => 'Jos Electric - JED',
        'rate_str' => '0.90%',
        'cap_str' => '—',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'jos-electric');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.009;
        }
    ],
    'kaduna_electric' => [
        'name' => 'Kaduna Electric - KAEDCO',
        'rate_str' => '1.50%',
        'cap_str' => '—',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'kaduna-electric');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.015;
        }
    ],
    'kano_electric' => [
        'name' => 'KEDCO - Kano Electric',
        'rate_str' => '1.00%',
        'cap_str' => '₦0.00',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'kano-electric');
        },
        'calculator' => function($amount, $qty) {
            return 0.00;
        }
    ],
    'phed_electric' => [
        'name' => 'PHED - Port Harcourt Electric',
        'rate_str' => '1.10%',
        'cap_str' => '—',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'phed');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.011;
        }
    ],
    'yola_electric' => [
        'name' => 'Yola Electric Disco - YEDC',
        'rate_str' => '1.20%',
        'cap_str' => '—',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'yola-electric') || str_contains(strtolower($txn['service_id']), 'yola-disco');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.012;
        }
    ],
    'dstv' => [
        'name' => 'DSTV Subscription',
        'rate_str' => '1.50%',
        'cap_str' => '—',
        'match' => function($txn) {
            return strtolower($txn['service_id']) === 'dstv';
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.015;
        }
    ],
    'gotv' => [
        'name' => 'Gotv Payment',
        'rate_str' => '1.50%',
        'cap_str' => '—',
        'match' => function($txn) {
            return strtolower($txn['service_id']) === 'gotv';
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.015;
        }
    ],
    'startimes' => [
        'name' => 'Startimes Subscription',
        'rate_str' => '2.00%',
        'cap_str' => '—',
        'match' => function($txn) {
            return strtolower($txn['service_id']) === 'startimes';
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.02;
        }
    ],
    'smile_network' => [
        'name' => 'Smile Network Payment',
        'rate_str' => '5.00%',
        'cap_str' => '—',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'smile');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.05;
        }
    ],
    'smsclone' => [
        'name' => 'SMSclone.com',
        'rate_str' => '3.00%',
        'cap_str' => '—',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'smsclone');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.03;
        }
    ],
    'waec_registration' => [
        'name' => 'WAEC Registration PIN',
        'rate_str' => '₦150.00 (Flat)',
        'cap_str' => '—',
        'match' => function($txn) {
            return strtolower($txn['service_id']) === 'waec' && (str_contains(strtolower($txn['variation_code'] ?? ''), 'registration') || str_contains(strtolower($txn['variation_code'] ?? ''), 'register'));
        },
        'calculator' => function($amount, $qty) {
            return 150.00 * max(1, $qty);
        }
    ],
    'waec_result' => [
        'name' => 'WAEC Result Checker PIN',
        'rate_str' => '₦250.00 (Flat)',
        'cap_str' => '—',
        'match' => function($txn) {
            return strtolower($txn['service_id']) === 'waec' && (!str_contains(strtolower($txn['variation_code'] ?? ''), 'registration') && !str_contains(strtolower($txn['variation_code'] ?? ''), 'register'));
        },
        'calculator' => function($amount, $qty) {
            return 250.00 * max(1, $qty);
        }
    ],
    'international_airtime' => [
        'name' => 'International Airtime',
        'rate_str' => '3.00%',
        'cap_str' => '—',
        'match' => function($txn) {
            return str_contains(strtolower($txn['service_id']), 'international') || str_contains(strtolower($txn['service_id']), 'foreign');
        },
        'calculator' => function($amount, $qty) {
            return $amount * 0.03;
        }
    ],
];

// ─── 2. Fetch VTpass Successful Transactions ───
$vtpassTransactions = Database::fetchAll(
    "SELECT vt.*, u.username, u.referred_by 
     FROM vtpass_transactions vt
     LEFT JOIN users u ON u.id = vt.user_id
     WHERE vt.status = 'success'
     ORDER BY vt.created_at DESC"
);

// Process VTpass commissions
$serviceTotals = [];
foreach ($servicesConfig as $key => $conf) {
    $serviceTotals[$key] = [
        'name'            => $conf['name'],
        'rate_str'        => $conf['rate_str'],
        'cap_str'         => $conf['cap_str'],
        'count'           => 0,
        'volume'          => 0.0,
        'commission'      => 0.0,
        'user_commission' => 0.0,
    ];
}
$serviceTotals['unmapped'] = [
    'name'            => 'Other / Unmapped Services',
    'rate_str'        => '0.00%',
    'cap_str'         => '—',
    'count'           => 0,
    'volume'          => 0.0,
    'commission'      => 0.0,
    'user_commission' => 0.0,
];

$totalVtuVolume = 0.0;
$totalVtuCommission = 0.0;
$totalVtuCount = 0;

$recentCommissions = [];

foreach ($vtpassTransactions as $txn) {
    $matchedKey = 'unmapped';
    foreach ($servicesConfig as $key => $conf) {
        if ($conf['match']($txn)) {
            $matchedKey = $key;
            break;
        }
    }

    // Compute commission & fee splits
    if ($matchedKey === 'unmapped') {
        $totalApiComm   = 0.00;
        $comm           = 0.00;
        $devIncome      = 0.00;
        $refBonus       = 0.00;
        $userShareTotal = 0.00;
    } else {
        $totalApiComm   = $servicesConfig[$matchedKey]['calculator']((float)$txn['amount'], (int)($txn['quantity'] ?? 1));
        $devIncome      = round($totalApiComm * 0.05, 2);
        $remainingPool  = $totalApiComm * 0.95;
        
        $comm           = round($remainingPool * ($adminPct / 100), 2);
        $userShareTotal = round($remainingPool * ($userPct / 100), 2);
        $refBonus       = 0.00;
        if (!empty($txn['referred_by']) && setting('referral_enabled', '1') === '1') {
            $refBonus   = round($userShareTotal * ($refPct / 100), 2);
        }
    }

    $serviceTotals[$matchedKey]['count']++;
    $serviceTotals[$matchedKey]['volume']          += (float)$txn['amount'];
    $serviceTotals[$matchedKey]['commission']      += $comm;
    $serviceTotals[$matchedKey]['user_commission'] += $userShareTotal;

    $totalVtuVolume     += (float)$txn['amount'];
    $totalVtuCommission += $comm;
    $totalVtuCount++;

    // Track detailed transaction commissions (for recent transactions)
    if (count($recentCommissions) < 15) {
        $rateStr = $servicesConfig[$matchedKey]['rate_str'] ?? '0.00%';
        $recentCommissions[] = [
            'reference'       => $txn['request_id'],
            'created_at'      => $txn['created_at'],
            'username'        => $txn['username'] ?? 'System',
            'service'         => $servicesConfig[$matchedKey]['name'] ?? 'Unmapped Service (' . $txn['service_id'] . ')',
            'amount'          => (float)$txn['amount'],
            'api_rate'        => $rateStr,
            'admin_rate'      => getAdminShareRateStr($rateStr, $adminPct),
            'user_rate'       => getUserShareRateStr($rateStr, $userPct),
            'commission'      => $comm,
            'user_commission' => $userShareTotal,
            'dev_income'      => $devIncome,
            'ref_bonus'       => $refBonus,
        ];
    }
}

// ─── 3. Fetch Standard Platform Fees ───
$platformFees = Database::fetchAll(
    "SELECT category, SUM(fee) as total_fee, COUNT(id) as count 
     FROM wallet_transactions 
     WHERE status = 'success' AND fee > 0 
     GROUP BY category 
     ORDER BY total_fee DESC"
);

$totalFees = 0.0;
$totalFeesCount = 0;
foreach ($platformFees as $pf) {
    $totalFees += (float)$pf['total_fee'];
    $totalFeesCount += (int)$pf['count'];
}

$overallIncome = $totalVtuCommission + $totalFees;

// ─── 3.1 Fetch Developer Paid and Admin Transferred Payouts ───
$totalDevPaid = (float)Database::fetchOne(
    "SELECT SUM(amount) as total FROM wallet_transactions WHERE category = 'developer_income' AND status = 'success'"
)['total'];

$totalTransferred = (float)Database::fetchOne(
    "SELECT SUM(amount) as total FROM wallet_transactions WHERE category = 'admin_payout' AND status = 'success'"
)['total'];

$availableBalance = $overallIncome - $totalDevPaid - $totalTransferred;

// ─── 3.2 POST Handler for Admin Income Transfer ───
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

            // Perform credit to recipient
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
                redirectTo('admin/income-wallet');
            } catch (Exception $ex) {
                Database::rollback();
                throw $ex;
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ─── 4. Chart Data Preparation ───
$chartLabels = [];
$chartCommissionData = [];
foreach ($serviceTotals as $key => $st) {
    if ($st['commission'] > 0 || $st['count'] > 0) {
        $chartLabels[] = $st['name'];
        $chartCommissionData[] = round($st['commission'], 2);
    }
}

include ADMIN_PATH . '/includes/header.php';
?>
<style>
.table-responsive-custom {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    margin-bottom: 0;
}
.table-responsive-custom table {
    width: 100%;
    margin-bottom: 0;
    white-space: nowrap;
}
.table-responsive-custom th,
.table-responsive-custom td {
    white-space: nowrap;
    vertical-align: middle;
}
.table-responsive-custom::-webkit-scrollbar {
    height: 6px;
}
.table-responsive-custom::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 4px;
}
.table-responsive-custom::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
.table-responsive-custom::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>
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

            <!-- Statistics Spotlight cards -->
            <div class="row g-3 mb-4">
                <div class="col-xl-4 col-md-6">
                    <div class="card border-0 shadow-sm rounded-16 p-4 position-relative overflow-hidden bg-gradient-earnings text-white" style="height: 140px;">
                        <div class="position-relative z-index-2">
                            <span class="small text-white-50 mb-1 d-block font-semibold uppercase tracking-wider">Lifetime Total Earnings</span>
                            <h2 class="fw-bold mb-0 text-white"><?= formatMoney($overallIncome) ?></h2>
                            <i class="fas fa-wallet position-absolute end-0 bottom-0 text-white-10 fs-1"></i>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-md-6">
                    <div class="card border-0 shadow-sm rounded-16 p-4 position-relative overflow-hidden border-start border-4 border-warning bg-white" style="height: 140px;">
                        <div class="position-relative z-index-2">
                            <span class="small text-muted mb-1 d-block font-semibold uppercase tracking-wider text-warning">Developer Income (5%)</span>
                            <h2 class="fw-bold mb-0 text-dark"><?= formatMoney($overallIncome * 0.05) ?></h2>
                            <p class="small text-muted mb-0 mt-2">Paid to date: <span class="fw-bold text-success"><?= formatMoney($totalDevPaid) ?></span></p>
                            <span class="small text-muted font-monospace" style="font-size: 11px;">kingsleyayinnah@gmail.com</span>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-md-6">
                    <div class="card border-0 shadow-sm rounded-16 p-4 position-relative overflow-hidden border-start border-4 border-primary bg-white" style="height: 140px;">
                        <div class="position-relative z-index-2">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <span class="small text-muted mb-1 d-block font-semibold uppercase tracking-wider text-primary">Available Admin Balance</span>
                                    <h2 class="fw-bold mb-0 text-dark"><?= formatMoney($availableBalance) ?></h2>
                                </div>
                                <button class="btn btn-sm btn-primary rounded-8 px-3 py-2 font-semibold" style="background:#4f46e5; border:none;" data-bs-toggle="modal" data-bs-target="#transferIncomeModal">
                                    <i class="fas fa-paper-plane me-1"></i> Transfer
                                </button>
                            </div>
                            <p class="small text-muted mb-0 mt-2">Transferred to date: <span class="fw-bold"><?= formatMoney($totalTransferred) ?></span></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-xl-4 col-md-6">
                    <div class="card border-0 shadow-sm rounded-16 p-4 position-relative overflow-hidden border-start border-4 border-emerald-500 bg-white" style="height: 120px;">
                        <div class="position-relative z-index-2">
                            <span class="small text-muted mb-1 d-block font-semibold uppercase tracking-wider text-emerald-600">VTU Commissions (API Only)</span>
                            <h2 class="fw-bold mb-0 text-dark"><?= formatMoney($totalVtuCommission) ?></h2>
                            <p class="small text-muted mb-0 mt-1"><span class="text-emerald-500 fw-bold"><?= $totalVtuCount ?></span> successful purchases</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-md-6">
                    <div class="card border-0 shadow-sm rounded-16 p-4 position-relative overflow-hidden border-start border-4 border-indigo-500 bg-white" style="height: 120px;">
                        <div class="position-relative z-index-2">
                            <span class="small text-muted mb-1 d-block font-semibold uppercase tracking-wider text-indigo-600">Other Platform Fees</span>
                            <h2 class="fw-bold mb-0 text-dark"><?= formatMoney($totalFees) ?></h2>
                            <p class="small text-muted mb-0 mt-1"><span class="text-indigo-500 fw-bold"><?= $totalFeesCount ?></span> fee-based txns</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 col-md-6">
                    <div class="card border-0 shadow-sm rounded-16 p-4 position-relative overflow-hidden border-start border-4 border-purple-500 bg-white" style="height: 120px;">
                        <div class="position-relative z-index-2">
                            <span class="small text-muted mb-1 d-block font-semibold uppercase tracking-wider text-purple-600">Total VTU Sales Volume</span>
                            <h2 class="fw-bold mb-0 text-dark"><?= formatMoney($totalVtuVolume) ?></h2>
                            <p class="small text-muted mb-0 mt-1">API Route activity</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs/Navigation for detailed analysis -->
            <ul class="nav nav-pills gap-2 mb-4 bg-white p-2 rounded-12 shadow-sm" id="incomeTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active rounded-8 px-4" id="vtu-rates-tab" data-bs-toggle="tab" data-bs-target="#vtu-rates" type="button" role="tab" aria-controls="vtu-rates" aria-selected="true">
                        <i class="fas fa-percent me-2"></i>VTU Service Rates & Revenue
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link rounded-8 px-4" id="other-fees-tab" data-bs-toggle="tab" data-bs-target="#other-fees" type="button" role="tab" aria-controls="other-fees" aria-selected="false">
                        <i class="fas fa-file-invoice-dollar me-2"></i>Other Platform Fees
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link rounded-8 px-4" id="recent-comm-tab" data-bs-toggle="tab" data-bs-target="#recent-comm" type="button" role="tab" aria-controls="recent-comm" aria-selected="false">
                        <i class="fas fa-list-ul me-2"></i>Recent Commission Logs
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="incomeTabsContent">
                
                <!-- Tab 1: VTU Rates & Revenue -->
                <div class="tab-pane fade show active" id="vtu-rates" role="tabpanel" aria-labelledby="vtu-rates-tab">
                    <div class="row g-4">
                        
                        <!-- Breakdown Table -->
                        <div class="col-lg-8">
                            <div class="admin-table-wrapper bg-white shadow-sm rounded-16 border-0">
                                <div class="p-4 border-bottom bg-white d-flex align-items-center justify-content-between">
                                    <div>
                                        <h5 class="fw-bold mb-0 text-dark">VTU Services Commission Breakdown</h5>
                                        <p class="small text-muted mb-0">Platform commission computed according to the API share rates.</p>
                                    </div>
                                    <span class="badge bg-light-success text-success px-3 py-2 rounded-pill font-bold">API Route ONLY</span>
                                </div>
                                <div class="table-responsive-custom">
                                    <table class="admin-table table-hover">
                                        <thead>
                                            <tr>
                                                <th>VTU Service</th>
                                                <th class="text-center">API Rate</th>
                                                <th class="text-center">Admin Share Rate (<?= (float)$adminPct ?>%)</th>
                                                <th class="text-center text-info">User rate(%) (<?= (float)$userPct ?>%)</th>
                                                <th class="text-center">Cap Limit</th>
                                                <th class="text-center">Sales Count</th>
                                                <th class="text-end">Sales Volume</th>
                                                <th class="text-end text-success">Admin Commission</th>
                                                <th class="text-end text-info">user commission</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($serviceTotals as $st): ?>
                                                <?php if ($st['name'] === 'Other / Unmapped Services' && $st['count'] === 0) continue; ?>
                                                <tr>
                                                    <td><span class="fw-bold text-dark"><?= e($st['name']) ?></span></td>
                                                    <td class="text-center font-semibold text-primary"><?= e($st['rate_str']) ?></td>
                                                    <td class="text-center font-semibold text-success"><?= e(getAdminShareRateStr($st['rate_str'], $adminPct)) ?></td>
                                                    <td class="text-center font-semibold text-info"><?= e(getUserShareRateStr($st['rate_str'], $userPct)) ?></td>
                                                    <td class="text-center text-muted small"><?= e($st['cap_str']) ?></td>
                                                    <td class="text-center small"><?= (int)$st['count'] ?></td>
                                                    <td class="text-end small"><?= formatMoney($st['volume']) ?></td>
                                                    <td class="text-end fw-bold text-success"><?= formatMoney($st['commission']) ?></td>
                                                    <td class="text-end fw-bold text-info"><?= formatMoney($st['user_commission']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Chart display -->
                        <div class="col-lg-4">
                            <div class="card border-0 shadow-sm rounded-16 p-4 bg-white h-100">
                                <h5 class="fw-bold mb-2 text-dark">Earning Shares By Service</h5>
                                <p class="small text-muted mb-4">Visual representation of income distribution from VTpass API services.</p>
                                <?php if (empty($chartLabels)): ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-chart-pie fa-3x text-muted mb-3" style="opacity: 0.3;"></i>
                                        <h6 class="fw-bold text-muted">No charts data available.</h6>
                                        <p class="small text-muted mb-0">VTU commissions will display once success transactions occur.</p>
                                    </div>
                                <?php else: ?>
                                    <div style="position: relative; height: 280px;">
                                        <canvas id="vtuIncomeChart"></canvas>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Tab 2: Other Platform Fees -->
                <div class="tab-pane fade" id="other-fees" role="tabpanel" aria-labelledby="other-fees-tab">
                    <div class="admin-table-wrapper bg-white shadow-sm rounded-16 border-0">
                        <div class="p-4 border-bottom bg-white">
                            <h5 class="fw-bold mb-0 text-dark">Non-VTU Transaction Fees Revenue</h5>
                            <p class="small text-muted mb-0">Fees collected from direct wallet activities (such as withdrawal fees, deposit processing fees, or bank transfers).</p>
                        </div>
                        <div class="table-responsive-custom">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Transaction Category</th>
                                        <th>Successful Transactions</th>
                                        <th class="text-end">Total Fees Earned</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($platformFees)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center py-5 text-muted">
                                                <i class="fas fa-info-circle fa-2x mb-2" style="opacity:0.3;"></i>
                                                <p class="mb-0">No direct wallet fees earned yet.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($platformFees as $pf): ?>
                                            <tr>
                                                <td><span class="fw-bold text-dark"><?= ucfirst(str_replace('_', ' ', $pf['category'])) ?></span></td>
                                                <td><?= (int)$pf['count'] ?> transactions</td>
                                                <td class="text-end fw-bold text-success"><?= formatMoney($pf['total_fee']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Tab 3: Recent Commission Logs -->
                <div class="tab-pane fade" id="recent-comm" role="tabpanel" aria-labelledby="recent-comm-tab">
                    <div class="admin-table-wrapper bg-white shadow-sm rounded-16 border-0">
                        <div class="p-4 border-bottom bg-white">
                            <h5 class="fw-bold mb-0 text-dark">Recent VTU Commission Logs</h5>
                            <p class="small text-muted mb-0">List of the last 15 successful VTpass purchases with calculated admin commissions.</p>
                        </div>
                        <div class="table-responsive-custom">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>Request / Ref ID</th>
                                        <th>User</th>
                                        <th>Purchased Service</th>
                                        <th class="text-end">Sales Amount</th>
                                        <th class="text-center">API Rate</th>
                                        <th class="text-center">Admin Rate (<?= (float)$adminPct ?>%)</th>
                                        <th class="text-center text-info">User rate(%) (<?= (float)$userPct ?>%)</th>
                                        <th class="text-end text-success">Admin Commission</th>
                                        <th class="text-end text-info">user commission</th>
                                        <th class="text-end text-primary">Developer Income</th>
                                        <th class="text-end text-warning">Referral Bonus</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentCommissions)): ?>
                                        <tr>
                                            <td colspan="12" class="text-center py-5 text-muted">
                                                <i class="fas fa-history fa-2x mb-2" style="opacity:0.3;"></i>
                                                <p class="mb-0">No successful VTU commissions recorded yet.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentCommissions as $rc): ?>
                                            <tr>
                                                <td class="small text-muted"><?= date('d M Y, H:i', strtotime($rc['created_at'])) ?></td>
                                                <td class="font-monospace small"><?= e($rc['reference']) ?></td>
                                                <td class="fw-bold text-dark"><?= e($rc['username']) ?></td>
                                                <td><span class="badge bg-light text-dark px-2.5 py-1.5"><?= e($rc['service']) ?></span></td>
                                                <td class="text-end"><?= formatMoney($rc['amount']) ?></td>
                                                <td class="text-center text-muted font-semibold"><?= e($rc['api_rate']) ?></td>
                                                <td class="text-center text-secondary font-semibold"><?= e($rc['admin_rate']) ?></td>
                                                <td class="text-center text-info font-semibold"><?= e($rc['user_rate']) ?></td>
                                                <td class="text-end fw-bold text-success"><?= formatMoney($rc['commission']) ?></td>
                                                <td class="text-end fw-bold text-info"><?= formatMoney($rc['user_commission']) ?></td>
                                                <td class="text-end fw-bold text-primary"><?= formatMoney($rc['dev_income']) ?></td>
                                                <td class="text-end fw-bold text-warning"><?= formatMoney($rc['ref_bonus']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </main>
</div>

<style>
/* Custom premium styles scoped to income wallet dashboard */
.rounded-16 { border-radius: 16px !important; }
.rounded-12 { border-radius: 12px !important; }
.rounded-8 { border-radius: 8px !important; }
.bg-gradient-earnings {
    background: linear-gradient(135deg, #10b981 0%, #047857 100%) !important;
}
.text-white-10 {
    color: rgba(255, 255, 255, 0.1) !important;
}
.font-semibold {
    font-weight: 600 !important;
}
.uppercase {
    text-transform: uppercase !important;
}
.tracking-wider {
    letter-spacing: 0.05em !important;
}
.bg-light-success {
    background: rgba(16, 185, 129, 0.1) !important;
}
.border-emerald-500 { border-color: #10b981 !important; }
.border-indigo-500 { border-color: #6366f1 !important; }
.border-purple-500 { border-color: #a855f7 !important; }
.text-emerald-600 { color: #059669 !important; }
.text-indigo-600 { color: #4f46e5 !important; }
.text-purple-600 { color: #9333ea !important; }
</style>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>

<!-- Chart script -->
<?php if (!empty($chartLabels)): ?>
<script>
$(document).ready(function() {
    const ctx = document.getElementById('vtuIncomeChart')?.getContext('2d');
    if (ctx) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                    data: <?= json_encode($chartCommissionData) ?>,
                    backgroundColor: [
                        '#10b981', '#6366f1', '#a855f7', '#f59e0b', '#3b82f6', '#ec4899', 
                        '#06b6d4', '#6b7280', '#14b8a6', '#ef4444', '#8b5cf6', '#eab308'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 10,
                            font: { size: 11 }
                        }
                    }
                }
            }
        });
    }
});
</script>
<?php endif; ?>

<!-- Modal: Transfer Admin Income -->
<div class="modal fade" id="transferIncomeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-16 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-paper-plane me-2 text-primary"></i>Transfer Admin Income</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= APP_URL ?>/admin/income-wallet">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="transfer_income">

                <div class="modal-body p-4">
                    <div class="alert alert-warning py-2 px-3 small rounded-8 mb-3">
                        <i class="fas fa-exclamation-triangle me-1"></i> Transfers will immediately credit the target user's wallet. This action is irreversible.
                    </div>

                    <div class="mb-3">
                        <label for="recipient" class="form-label small">Recipient Username or Email</label>
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

                    <div class="mb-3">
                        <label for="amount" class="form-label small">Amount to Transfer (₦)</label>
                        <input type="number" class="form-control" id="amount" name="amount" step="0.01" max="<?= $availableBalance ?>" placeholder="Max: <?= number_format($availableBalance, 2, '.', '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="narrative" class="form-label small">Transfer Narrative / Reason</label>
                        <input type="text" class="form-control" id="narrative" name="narrative" placeholder="e.g. Platform promotion payouts" required>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label small">Confirm Your Admin Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                </div>

                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background-color: #4f46e5; border: none;">Execute Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>

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
                fetch('<?= APP_URL ?>/admin/income-wallet?action=lookup_user&query=' + encodeURIComponent(query))
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
            }, 300); // 300ms debounce
        });
    }
});
</script>
