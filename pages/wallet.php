<?php
/**
 * OURCR ONLINE - Wallet Overview
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'My Wallet';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Get balances
$balance = getWalletBalance($user['id']);
$bonus = (float)($user['bonus_balance'] ?? 0.00);

// Get pending intents
$pendingIntents = Database::fetchAll(
    "SELECT * FROM deposit_intents WHERE user_id = ? AND status = 'pending' ORDER BY created_at DESC",
    [$user['id']]
);

// Get recent deposits & pending deposit intents
$completedDeposits = Database::fetchAll(
    "SELECT * FROM wallet_transactions WHERE user_id = ? AND category = 'deposit' ORDER BY created_at DESC LIMIT 10",
    [$user['id']]
);

$pendingDepositRows = [];
foreach ($pendingIntents as $pi) {
    $pendingDepositRows[] = [
        'reference'   => 'DEP-' . strtoupper(substr($pi['uuid'], 0, 8)),
        'amount'      => $pi['amount'],
        'description' => 'Declared transfer (Sender: ' . $pi['sender_name'] . ')',
        'created_at'  => $pi['created_at'],
        'status'      => 'pending'
    ];
}

$deposits = array_merge($pendingDepositRows, $completedDeposits);

// Get recent withdrawals
$withdrawals = Database::fetchAll(
    "SELECT * FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC LIMIT 10",
    [$user['id']]
);

// Get recent transfers (sent & received)
$transfers = Database::fetchAll(
    "SELECT t.*,
            s.first_name AS sender_first, s.last_name AS sender_last,
            r.first_name AS receiver_first, r.last_name AS receiver_last
     FROM transfers t
     JOIN users s ON s.id = t.sender_id
     JOIN users r ON r.id = t.receiver_id
     WHERE t.sender_id = ? OR t.receiver_id = ?
     ORDER BY t.created_at DESC
     LIMIT 20",
    [$user['id'], $user['id']]
);

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            <div class="row fade-in-up">
                <div class="col-lg-8">
                    <!-- Balance Summary Card -->
                    <div class="wallet-card">
                        <div class="wallet-balance-label">Main Wallet Balance</div>
                        <div class="wallet-balance-amount"><?= formatMoney($balance) ?></div>
                        <div class="mb-4">
                            <span class="small opacity-75">Bonus Earned:</span> 
                            <strong class="ms-1"><?= formatMoney($bonus) ?></strong>
                        </div>
                        <div class="wallet-actions">
                            <a href="<?= APP_URL ?>/fund-wallet" class="wallet-action-btn">
                                <i class="fas fa-plus"></i> Fund Wallet
                            </a>
                            <a href="<?= APP_URL ?>/withdraw" class="wallet-action-btn">
                                <i class="fas fa-arrow-down"></i> Withdraw
                            </a>
                            <a href="<?= APP_URL ?>/transfer" class="wallet-action-btn">
                                <i class="fas fa-paper-plane"></i> Transfer
                            </a>
                        </div>
                    </div>

                    <!-- Pending Deposit Declarations -->
                    <?php if (!empty($pendingIntents)): ?>
                        <div class="card border-0 shadow-sm rounded-16 mb-4 border-start border-4 border-warning">
                            <div class="card-body p-4">
                                <h5 class="card-title fw-bold mb-3 text-warning"><i class="fas fa-clock me-2"></i> Pending Deposit Intents</h5>
                                <p class="small text-muted">You declared bank deposits below. Our automated system verifies incoming bank transfers to credit your wallet automatically.</p>
                                <div class="table-responsive">
                                    <table class="table table-borderless align-middle mb-0">
                                        <thead>
                                            <tr class="text-muted small">
                                                <th>Amount</th>
                                                <th>Sender Declared</th>
                                                <th>Expires At</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pendingIntents as $intent): ?>
                                                <tr>
                                                    <td class="fw-bold"><?= formatMoney($intent['amount']) ?></td>
                                                    <td><?= e($intent['sender_name']) ?></td>
                                                    <td><span class="badge bg-light text-dark"><?= date('d M, h:i A', strtotime($intent['expires_at'])) ?></span></td>
                                                    <td>
                                                        <a href="<?= APP_URL ?>/deposit-intent?intent_id=<?= e($intent['uuid']) ?>" class="btn btn-sm btn-outline-warning rounded-pill">Details</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- History tabs -->
                    <div class="card border-0 shadow-sm rounded-16 mb-4">
                        <div class="card-body p-4">
                            <ul class="nav nav-tabs border-bottom-0 mb-4" id="walletTab" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active fw-bold text-dark border-0 pb-2 px-3" id="deposit-tab" data-bs-toggle="tab" data-bs-target="#deposits" type="button" role="tab">Deposit History</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link fw-bold text-dark border-0 pb-2 px-3" id="withdraw-tab" data-bs-toggle="tab" data-bs-target="#withdrawals" type="button" role="tab">Withdrawals</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link fw-bold text-dark border-0 pb-2 px-3" id="transfer-tab" data-bs-toggle="tab" data-bs-target="#transfers" type="button" role="tab">Transfers</button>
                                </li>
                            </ul>

                            <div class="tab-content" id="walletTabContent">
                                <!-- Deposit History -->
                                <div class="tab-pane fade show active" id="deposits" role="tabpanel">
                                    <?php if (empty($deposits)): ?>
                                        <div class="text-center py-4">
                                            <i class="fas fa-arrow-down fa-3x text-muted mb-2" style="opacity: 0.3;"></i>
                                            <p class="text-muted mb-0">No deposits recorded yet.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table align-middle">
                                                <thead>
                                                    <tr class="text-muted small">
                                                        <th>Ref</th>
                                                        <th>Amount</th>
                                                        <th>Details</th>
                                                        <th>Date</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($deposits as $dep): ?>
                                                        <tr>
                                                            <td class="small text-muted"><?= e($dep['reference']) ?></td>
                                                            <td class="fw-bold text-success">+ <?= formatMoney($dep['amount'], false) ?></td>
                                                            <td><span class="small"><?= e($dep['description']) ?></span></td>
                                                            <td class="small"><?= formatDate($dep['created_at']) ?></td>
                                                            <td><span class="badge badge-<?= ($dep['status'] ?? 'success') === 'pending' ? 'pending' : 'success' ?>"><?= ucfirst($dep['status'] ?? 'success') ?></span></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Withdrawal History -->
                                <div class="tab-pane fade" id="withdrawals" role="tabpanel">
                                    <?php if (empty($withdrawals)): ?>
                                        <div class="text-center py-4">
                                            <i class="fas fa-arrow-up fa-3x text-muted mb-2" style="opacity: 0.3;"></i>
                                            <p class="text-muted mb-0">No withdrawal logs found.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table align-middle">
                                                <thead>
                                                    <tr class="text-muted small">
                                                        <th>Ref</th>
                                                        <th>Amount</th>
                                                        <th>Recipient Bank</th>
                                                        <th>Date</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($withdrawals as $w): ?>
                                                        <tr>
                                                            <td class="small text-muted"><?= maskString($w['uuid'], 4, 4) ?></td>
                                                            <td class="fw-bold text-danger">- <?= formatMoney($w['amount'], false) ?></td>
                                                            <td><span class="small"><?= e($w['bank_name']) ?> (<?= e($w['account_number']) ?>)</span></td>
                                                            <td class="small"><?= formatDate($w['created_at']) ?></td>
                                                            <td><span class="badge badge-<?= $w['status'] === 'success' ? 'success' : ($w['status'] === 'pending' ? 'pending' : 'failed') ?>"><?= ucfirst($w['status']) ?></span></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Transfer History -->
                                <div class="tab-pane fade" id="transfers" role="tabpanel">
                                    <?php if (empty($transfers)): ?>
                                        <div class="text-center py-4">
                                            <i class="fas fa-paper-plane fa-3x text-muted mb-2" style="opacity: 0.3;"></i>
                                            <p class="text-muted mb-0">No transfer history found.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table align-middle">
                                                <thead>
                                                    <tr class="text-muted small">
                                                        <th>Type</th>
                                                        <th>Amount</th>
                                                        <th>Counterparty</th>
                                                        <th>Date</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($transfers as $txf): ?>
                                                        <?php
                                                            $isSender = ((int)$txf['sender_id'] === (int)$user['id']);
                                                            $counterparty = $isSender
                                                                ? e($txf['receiver_first'] . ' ' . $txf['receiver_last'])
                                                                : e($txf['sender_first']  . ' ' . $txf['sender_last']);
                                                            $typeLabel = $isSender ? 'Sent' : 'Received';
                                                            $amtClass  = $isSender ? 'text-danger' : 'text-success';
                                                            $amtSign   = $isSender ? '- ' : '+ ';
                                                            $stBadge   = $txf['status'] === 'success' ? 'success'
                                                                       : ($txf['status'] === 'pending' ? 'pending' : 'failed');
                                                        ?>
                                                        <tr>
                                                            <td><span class="badge bg-light text-dark small"><?= $typeLabel ?></span></td>
                                                            <td class="fw-bold <?= $amtClass ?>"><?= $amtSign . formatMoney($txf['amount'], false) ?></td>
                                                            <td><span class="small"><?= $counterparty ?></span></td>
                                                            <td class="small"><?= formatDate($txf['created_at']) ?></td>
                                                            <td><span class="badge badge-<?= $stBadge ?>"><?= ucfirst($txf['status']) ?></span></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- Deposit Instructions Card -->
                    <div class="card border-0 shadow-sm rounded-16 mb-4">
                        <div class="card-body p-4">
                            <h5 class="fw-bold mb-3">Deposit Guidelines</h5>
                            <ol class="small text-muted ps-3">
                                <li class="mb-2">Click <strong>Fund Wallet</strong> to pre-declare your transfer details (Amount and Sender Account Name).</li>
                                <li class="mb-2">Transfer the exact declared amount from a bank account matching your registered name.</li>
                                <li class="mb-2">Our automated verification system checks bank records and credits your wallet automatically within a few minutes.</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>
