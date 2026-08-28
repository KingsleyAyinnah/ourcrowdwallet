<?php
/**
 * OURCR ONLINE - User Dashboard Home
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Dashboard';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Get balances
$balance = getWalletBalance($user['id']);
$bonus = (float)($user['bonus_balance'] ?? 0.00);

// Get last 5 transactions
$recentTxns = Database::fetchAll(
    "SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5",
    [$user['id']]
);

// Get stats
$statsCredited = Database::fetchOne(
    "SELECT SUM(amount) as total FROM wallet_transactions WHERE user_id = ? AND type = 'credit' AND status = 'success'",
    [$user['id']]
);
$statsDebited = Database::fetchOne(
    "SELECT SUM(amount) as total FROM wallet_transactions WHERE user_id = ? AND type = 'debit' AND status = 'success'",
    [$user['id']]
);

$totalCredited = (float)($statsCredited['total'] ?? 0.00);
$totalDebited = (float)($statsDebited['total'] ?? 0.00);

// Get announcements
$announcements = getActiveAnnouncements('user');

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            
            <!-- Announcements Banner -->
            <?php if (!empty($announcements)): ?>
                <?php foreach ($announcements as $ann): ?>
                    <div class="alert alert-<?= e($ann['type']) ?> alert-dismissible fade show announcement-bar" role="alert">
                        <strong><i class="fas fa-bullhorn me-2"></i> <?= e($ann['title']) ?>:</strong> <?= e($ann['message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="row fade-in-up">
                <!-- Left column -->
                <div class="col-lg-8">
                    <!-- Wallet Card -->
                    <div class="wallet-card">
                        <div class="wallet-balance-label">Main Wallet Balance</div>
                        <div class="wallet-balance-amount"><?= formatMoney($balance) ?></div>
                        
                        <div class="mb-4 d-flex justify-content-between align-items-center">
                            <div>
                                <span class="small opacity-75">Bonus Earned:</span> 
                                <strong class="ms-1"><?= formatMoney($bonus) ?></strong>
                            </div>
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

                    <!-- Services Grid -->
                    <h5 class="mb-3 fw-bold">Services</h5>
                    <div class="services-grid">
                        <a href="<?= APP_URL ?>/buy-airtime" class="service-item">
                            <div class="service-icon text-danger" style="background-color: rgba(220, 38, 38, 0.1);">
                                <i class="fas fa-phone"></i>
                            </div>
                            <span class="service-label">Airtime</span>
                        </a>
                        <a href="<?= APP_URL ?>/buy-data" class="service-item">
                            <div class="service-icon text-primary" style="background-color: rgba(59, 130, 246, 0.1);">
                                <i class="fas fa-wifi"></i>
                            </div>
                            <span class="service-label">Buy Data</span>
                        </a>
                        <a href="<?= APP_URL ?>/cable-tv" class="service-item">
                            <div class="service-icon text-info" style="background-color: rgba(6, 182, 212, 0.1);">
                                <i class="fas fa-tv"></i>
                            </div>
                            <span class="service-label">Cable TV</span>
                        </a>
                        <a href="<?= APP_URL ?>/electricity" class="service-item">
                            <div class="service-icon text-warning" style="background-color: rgba(245, 158, 11, 0.1);">
                                <i class="fas fa-bolt"></i>
                            </div>
                            <span class="service-label">Electricity</span>
                        </a>
                        <a href="<?= APP_URL ?>/betting" class="service-item">
                            <div class="service-icon text-success" style="background-color: rgba(16, 185, 129, 0.1);">
                                <i class="fas fa-futbol"></i>
                            </div>
                            <span class="service-label">Betting</span>
                        </a>
                        <a href="<?= APP_URL ?>/exam-pins" class="service-item">
                            <div class="service-icon text-secondary" style="background-color: rgba(107, 114, 128, 0.1);">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <span class="service-label">Exam Pins</span>
                        </a>
                        <a href="<?= APP_URL ?>/referrals" class="service-item">
                            <div class="service-icon text-danger" style="background-color: rgba(239, 68, 68, 0.1);">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <span class="service-label">Referrals</span>
                        </a>
                        <a href="<?= APP_URL ?>/support" class="service-item">
                            <div class="service-icon text-info" style="background-color: rgba(3, 105, 161, 0.1);">
                                <i class="fas fa-headset"></i>
                            </div>
                            <span class="service-label">Support</span>
                        </a>
                    </div>

                    <!-- Recent Transactions -->
                    <div class="card border-0 shadow-sm rounded-20 mb-4 overflow-hidden">
                        <div class="card-header bg-white border-0 py-3 px-4 d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-extrabold text-dark fs-6">Recent Transactions</span>
                                <?php if (!empty($recentTxns)): ?>
                                    <span class="badge bg-light text-muted rounded-pill px-2 py-1 small"><?= count($recentTxns) ?> recent</span>
                                <?php endif; ?>
                            </div>
                            <a href="<?= APP_URL ?>/transactions" class="btn btn-sm btn-light border rounded-pill px-3 fw-bold text-dark d-inline-flex align-items-center gap-1">
                                <span>View All</span> <i class="fas fa-chevron-right" style="font-size: 10px;"></i>
                            </a>
                        </div>

                        <div class="card-body p-0">
                            <?php if (empty($recentTxns)): ?>
                                <div class="text-center py-5 px-3">
                                    <div class="txn-empty-icon mb-3">
                                        <i class="fas fa-receipt"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">No transactions yet</h6>
                                    <p class="text-muted small mb-3">Fund your wallet or buy airtime/data to get started.</p>
                                    <a href="<?= APP_URL ?>/fund-wallet" class="btn btn-sm btn-danger bg-site-color border-0 rounded-pill px-3">
                                        <i class="fas fa-plus-circle me-1"></i> Fund Wallet
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="dashboard-txn-list">
                                    <?php foreach ($recentTxns as $txn): ?>
                                        <?php
                                            $isCredit = $txn['type'] === 'credit';
                                            $vis = getCategoryVisuals($txn['category'] ?? '');
                                            $meta = is_array($txn['meta'] ?? null) ? $txn['meta'] : [];
                                            $tokenVal = $txn['vtpass_token'] ?? $meta['token'] ?? $meta['pin'] ?? '';
                                        ?>
                                        <div class="dashboard-txn-item txn-view-btn"
                                             data-id="<?= $txn['id'] ?>"
                                             data-uuid="<?= e($txn['uuid'] ?? '') ?>"
                                             data-reference="<?= e($txn['reference']) ?>"
                                             data-category="<?= e($vis['label']) ?>"
                                             data-rawcat="<?= e($txn['category']) ?>"
                                             data-type="<?= e($txn['type']) ?>"
                                             data-amount="<?= formatMoney($txn['amount'], false) ?>"
                                             data-fee="<?= formatMoney($txn['fee'] ?? 0, false) ?>"
                                             data-balance-before="<?= formatMoney($txn['balance_before'] ?? 0, false) ?>"
                                             data-balance-after="<?= formatMoney($txn['balance_after'] ?? 0, false) ?>"
                                             data-description="<?= e($txn['description'] ?: '—') ?>"
                                             data-status="<?= e($txn['status']) ?>"
                                             data-date="<?= e(date('d M Y, h:i A', strtotime($txn['created_at']))) ?>"
                                             data-token="<?= e($tokenVal) ?>">
                                            
                                            <div class="d-flex align-items-center justify-content-between">
                                                <div class="d-flex align-items-center gap-3" style="min-width: 0;">
                                                    <div class="dashboard-txn-avatar" style="background-color: <?= $vis['bg'] ?>; color: <?= $vis['color'] ?>;">
                                                        <i class="<?= $vis['icon'] ?>"></i>
                                                    </div>
                                                    <div style="min-width: 0;">
                                                        <div class="fw-bold text-dark small text-truncate mb-0" style="max-width: 280px;">
                                                            <?= e($txn['description'] ?: $vis['label']) ?>
                                                        </div>
                                                        <div class="d-flex align-items-center gap-2 text-muted" style="font-size: 11px;">
                                                            <span class="font-monospace"><?= e(truncate($txn['reference'], 16)) ?></span>
                                                            <span>&bull;</span>
                                                            <span><?= date('d M, h:i A', strtotime($txn['created_at'])) ?></span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="text-end flex-shrink-0 ms-2">
                                                    <div class="fw-bold <?= $isCredit ? 'text-success' : 'text-dark' ?>" style="font-size: 14.5px;">
                                                        <?= ($isCredit ? '+' : '−') . ' ' . formatMoney($txn['amount'], false) ?>
                                                    </div>
                                                    <span class="dashboard-txn-badge dashboard-status-<?= strtolower($txn['status']) ?> mt-1">
                                                        <span class="dashboard-txn-dot"></span>
                                                        <?= ucfirst($txn['status']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right column -->
                <div class="col-lg-4">
                    <!-- Quick Statistics -->
                    <div class="card border-0 shadow-sm rounded-16 mb-4">
                        <div class="card-body p-4">
                            <h5 class="fw-bold mb-4">Quick Statistics</h5>
                            
                            <div class="d-flex flex-column gap-3 mb-4">
                                <div class="stat-card-sm p-3 border rounded-12 d-flex align-items-center gap-3">
                                    <div class="bg-success bg-opacity-10 rounded-pill text-success d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px; font-size: 15px;">
                                        <i class="fas fa-arrow-down"></i>
                                    </div>
                                    <div style="min-width: 0; flex: 1;">
                                        <div class="text-muted" style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .4px;">Total Inflow</div>
                                        <div class="fw-bold text-success text-truncate" style="font-size: 16px; line-height: 1.3;"><?= formatMoney($totalCredited) ?></div>
                                    </div>
                                </div>
                                <div class="stat-card-sm p-3 border rounded-12 d-flex align-items-center gap-3">
                                    <div class="bg-danger bg-opacity-10 rounded-pill text-danger d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px; font-size: 15px;">
                                        <i class="fas fa-arrow-up"></i>
                                    </div>
                                    <div style="min-width: 0; flex: 1;">
                                        <div class="text-muted" style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .4px;">Total Outflow</div>
                                        <div class="fw-bold text-danger text-truncate" style="font-size: 16px; line-height: 1.3;"><?= formatMoney($totalDebited) ?></div>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Promo Banner / Referrals Box -->
                            <?php
                                $refFlatBonus = (float) setting('referral_bonus', 200);
                                $refPct       = (float) setting('referral_bonus_percentage', 0);
                            ?>
                            <div class="p-3 rounded-12 bg-light border text-center">
                                <i class="fas fa-gift text-danger fa-2x mb-2"></i>
                                <h6 class="fw-bold">Invite Friends &amp; Earn</h6>
                                <?php if ($refFlatBonus > 0 && $refPct > 0): ?>
                                    <p class="small text-muted mb-2">Earn <strong><?= formatMoney($refFlatBonus) ?></strong> when a friend joins <span class="text-muted">+</span> <strong><?= $refPct ?>%</strong> ongoing commission on every discount they save.</p>
                                <?php elseif ($refFlatBonus > 0): ?>
                                    <p class="small text-muted mb-2">Earn <strong><?= formatMoney($refFlatBonus) ?></strong> instantly when each friend verifies their email after joining.</p>
                                <?php elseif ($refPct > 0): ?>
                                    <p class="small text-muted mb-2">Earn <strong><?= $refPct ?>%</strong> commission on every discount your referred friends save on each purchase.</p>
                                <?php else: ?>
                                    <p class="small text-muted mb-2">Invite your friends and help them enjoy great services.</p>
                                <?php endif; ?>
                                <a href="<?= APP_URL ?>/referrals" class="btn btn-sm btn-outline-danger w-100 mt-2">Get Referral Link</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TRANSACTION DETAIL / E-RECEIPT MODAL
══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="txnDetailModal" tabindex="-1" aria-labelledby="txnDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content border-0 shadow-2xl rounded-24 overflow-hidden">

            <!-- Captured Receipt Wrapper (Target for image/pdf generation) -->
            <div id="txnReceiptCaptureArea" class="bg-white">

                <!-- Receipt Header Gradient -->
                <div class="txn-modal-header p-4" id="txnModalHeaderEl">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="txn-modal-icon-badge" id="txnModalIcon">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <div>
                                <div class="text-white opacity-75" style="font-size: 10px; text-transform: uppercase; letter-spacing: 1.2px; font-weight: 800;">
                                    <?= e(setting('site_name', APP_NAME)) ?> RECEIPT
                                </div>
                                <div class="text-white fw-extrabold fs-6" id="txnModalCategoryLabel">Transaction</div>
                                <div class="text-white opacity-75 small" id="txnModalDateLabel">—</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <img src="<?= APP_URL ?>/assets/images/logo-white.png" alt="Brand Logo" style="height: 24px; width: auto; max-width: 100px; object-fit: contain;">
                            <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal" aria-label="Close" data-html2canvas-ignore="true"></button>
                        </div>
                    </div>

                    <!-- Spotlight Amount -->
                    <div class="text-center py-2">
                        <div class="text-white opacity-75 small text-uppercase fw-semibold" style="letter-spacing: 0.5px;">Transaction Amount</div>
                        <div class="txn-modal-amount fw-black text-white" id="txnModalAmount">₦0.00</div>
                        <div class="mt-2" id="txnModalStatusBadge">
                            <span class="badge px-3 py-1 rounded-pill" id="txnModalStatusSpan" style="font-size: 12px;">—</span>
                        </div>
                    </div>
                </div>

                <!-- Receipt Body Content -->
                <div class="modal-body p-4 pt-3">
                    
                    <!-- Token / PIN Highlight Banner (When Available) -->
                    <div class="p-3 mb-3 rounded-16 border bg-amber-50" id="txnModalTokenBox" style="display: none; background: #fffbeb; border-color: #fef3c7;">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="small fw-bold text-amber-900" id="txnModalTokenTitle"><i class="fas fa-key me-1 text-warning"></i> Recharge PIN / Meter Token</span>
                            <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2 rounded-pill font-monospace" id="txnModalCopyTokenBtn" style="font-size: 11px;">
                                <i class="fas fa-copy me-1"></i> Copy
                            </button>
                        </div>
                        <div class="fw-black font-monospace text-dark fs-5 text-center py-1 tracking-wider" id="txnModalTokenVal">—</div>
                    </div>

                    <!-- Detail Grid Rows -->
                    <div class="txn-receipt-grid">
                        <div class="txn-receipt-row">
                            <span class="txn-receipt-label"><i class="fas fa-hashtag me-2 text-muted"></i>Transaction Reference</span>
                            <div class="d-flex align-items-center gap-1">
                                <span class="txn-receipt-value font-monospace small" id="txnModalRef">—</span>
                                <button type="button" class="btn btn-sm btn-link text-muted p-0" id="txnModalCopyRefInline" title="Copy Reference">
                                    <i class="far fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="txn-receipt-row">
                            <span class="txn-receipt-label"><i class="fas fa-exchange-alt me-2 text-muted"></i>Flow Type</span>
                            <span class="txn-receipt-value" id="txnModalType">—</span>
                        </div>
                        <div class="txn-receipt-row">
                            <span class="txn-receipt-label"><i class="fas fa-layer-group me-2 text-muted"></i>Category</span>
                            <span class="txn-receipt-value" id="txnModalCategory">—</span>
                        </div>
                        <div class="txn-receipt-row">
                            <span class="txn-receipt-label"><i class="fas fa-align-left me-2 text-muted"></i>Description / Recipient</span>
                            <span class="txn-receipt-value small text-end" id="txnModalDesc">—</span>
                        </div>
                        <div class="txn-receipt-row">
                            <span class="txn-receipt-label"><i class="fas fa-coins me-2 text-muted"></i>Processing Fee</span>
                            <span class="txn-receipt-value" id="txnModalFee">₦0.00</span>
                        </div>
                        <div class="txn-receipt-row">
                            <span class="txn-receipt-label"><i class="far fa-calendar-alt me-2 text-muted"></i>Date & Time</span>
                            <span class="txn-receipt-value small" id="txnModalDate">—</span>
                        </div>
                    </div>

                    <!-- Collapsible Balance Impact (For Privacy on Share) -->
                    <div class="accordion mt-3" id="txnBalanceAccordion" data-html2canvas-ignore="true">
                        <div class="accordion-item border-0 bg-light rounded-16 overflow-hidden">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed py-2 px-3 bg-light text-muted small fw-semibold shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#balanceCollapse">
                                    <i class="fas fa-shield-halved me-2"></i> View Wallet Balance Impact
                                </button>
                            </h2>
                            <div id="balanceCollapse" class="accordion-collapse collapse" data-bs-parent="#txnBalanceAccordion">
                                <div class="accordion-body p-3 pt-0 small">
                                    <div class="d-flex justify-content-between py-1 border-bottom border-light">
                                        <span class="text-muted">Balance Before:</span>
                                        <span class="fw-bold font-monospace" id="txnModalBalBefore">₦0.00</span>
                                    </div>
                                    <div class="d-flex justify-content-between py-1">
                                        <span class="text-muted">Balance After:</span>
                                        <span class="fw-bold font-monospace" id="txnModalBalAfter">₦0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Receipt Footer Brand -->
                    <div class="text-center mt-4 pt-3 border-top text-muted" style="font-size: 11px; border-top-style: dashed !important; opacity: 0.85;">
                        <i class="fas fa-shield-alt text-success me-1"></i> Verified Electronic Receipt &bull; <strong><?= e(setting('site_name', APP_NAME)) ?></strong>
                    </div>

                </div>

            </div> <!-- /txnReceiptCaptureArea -->

            <!-- Modal Footer Actions -->
            <div class="modal-footer border-0 pt-0 px-4 pb-4 d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary flex-fill rounded-pill py-2" data-bs-dismiss="modal">
                    Close
                </button>
                <div class="dropdown flex-fill">
                    <button class="btn btn-danger bg-site-color border-0 w-100 rounded-pill py-2 dropdown-toggle d-flex align-items-center justify-content-center gap-2" type="button" id="shareReceiptDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-share-nodes"></i> <span>Share Receipt</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg p-2 rounded-16" aria-labelledby="shareReceiptDropdown" style="min-width: 210px;">
                        <li>
                            <button type="button" class="dropdown-item py-2 rounded-12 d-flex align-items-center gap-2" id="txnModalShareImgBtn">
                                <i class="fas fa-image text-primary"></i> <span>Download Image (PNG)</span>
                            </button>
                        </li>
                        <li>
                            <button type="button" class="dropdown-item py-2 rounded-12 d-flex align-items-center gap-2" id="txnModalSharePdfBtn">
                                <i class="fas fa-file-pdf text-danger"></i> <span>Download PDF Receipt</span>
                            </button>
                        </li>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li>
                            <button type="button" class="dropdown-item py-2 rounded-12 d-flex align-items-center gap-2" id="txnModalCopyRefBtn">
                                <i class="far fa-copy text-muted"></i> <span>Copy Reference ID</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     DASHBOARD RECENT TRANSACTIONS STYLES
══════════════════════════════════════════════════════════════════════════════ -->
<style>
.rounded-20 { border-radius: 20px !important; }
.rounded-24 { border-radius: 24px !important; }

/* Dashboard Recent Transaction List */
.dashboard-txn-list {
    display: flex;
    flex-direction: column;
}
.dashboard-txn-item {
    padding: 14px 18px;
    border-bottom: 1px solid #f3f4f6;
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.dashboard-txn-item:last-child {
    border-bottom: none;
}
.dashboard-txn-item:hover, .dashboard-txn-item:active {
    background-color: #fafbfc;
}
.dashboard-txn-avatar {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
}
.dashboard-txn-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
}
.dashboard-txn-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
}
.dashboard-status-success {
    background: rgba(16, 185, 129, 0.12);
    color: #059669;
}
.dashboard-status-success .dashboard-txn-dot {
    background: #10b981;
}
.dashboard-status-pending {
    background: rgba(245, 158, 11, 0.12);
    color: #d97706;
}
.dashboard-status-pending .dashboard-txn-dot {
    background: #f59e0b;
}
.dashboard-status-failed {
    background: rgba(239, 68, 68, 0.12);
    color: #dc2626;
}
.dashboard-status-failed .dashboard-txn-dot {
    background: #ef4444;
}
.dashboard-status-reversed {
    background: rgba(107, 114, 128, 0.12);
    color: #6b7280;
}
.dashboard-status-reversed .dashboard-txn-dot {
    background: #6b7280;
}

.txn-empty-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: #f3f4f6;
    color: #9ca3af;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
}

/* Modal Styling */
.txn-modal-header {
    background: linear-gradient(135deg, var(--site-color, #dc2626) 0%, #7f1d1d 100%);
}
.txn-modal-icon-badge {
    width: 44px;
    height: 44px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: #fff;
    flex-shrink: 0;
}
.txn-modal-amount {
    font-size: 32px;
    letter-spacing: -0.8px;
}
.txn-receipt-grid {
    display: flex;
    flex-direction: column;
    gap: 0;
}
.txn-receipt-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 10px 0;
    border-bottom: 1px solid #f3f4f6;
    gap: 12px;
}
.txn-receipt-row:last-child {
    border-bottom: none;
}
.txn-receipt-label {
    font-size: 12.5px;
    color: #6b7280;
    white-space: nowrap;
    flex-shrink: 0;
}
.txn-receipt-value {
    font-size: 12.5px;
    color: #1f2937;
    font-weight: 600;
    text-align: right;
    word-break: break-word;
}
</style>

<?php include INCLUDES_PATH . '/footer.php'; ?>

<!-- html2canvas and jsPDF Libraries -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
(function () {
    const iconMap = {
        deposit:        'fas fa-arrow-down-left',
        withdrawal:     'fas fa-arrow-up-right',
        transfer:       'fas fa-paper-plane',
        airtime:        'fas fa-phone-alt',
        data:           'fas fa-wifi',
        cable_tv:       'fas fa-tv',
        electricity:    'fas fa-bolt',
        betting:        'fas fa-futbol',
        exam_pin:       'fas fa-graduation-cap',
        referral_bonus: 'fas fa-gift'
    };

    const statusConfig = {
        success:  { bg: 'rgba(16, 185, 129, 0.15)', text: '#059669', label: 'Success' },
        pending:  { bg: 'rgba(245, 158, 11, 0.15)', text: '#d97706', label: 'Pending' },
        failed:   { bg: 'rgba(239, 68, 68, 0.15)',  text: '#dc2626', label: 'Failed' },
        reversed: { bg: 'rgba(107, 114, 128, 0.15)', text: '#6b7280', label: 'Reversed' }
    };

    // Handle View Transaction Receipt
    document.querySelectorAll('.txn-view-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const d = btn.dataset;
            const isCredit = d.type === 'credit';
            const statusKey = (d.status || '').toLowerCase();
            const rawCat = (d.rawcat || d.category || '').toLowerCase();

            // Category Icon
            let iconClass = iconMap[rawCat] || 'fas fa-receipt';
            document.getElementById('txnModalIcon').innerHTML = '<i class="' + iconClass + '"></i>';

            // Category & Date
            document.getElementById('txnModalCategoryLabel').textContent = d.category || 'Transaction';
            document.getElementById('txnModalDateLabel').textContent     = d.date || '—';
            document.getElementById('txnModalDate').textContent          = d.date || '—';

            // Amount
            const sign = isCredit ? '+' : '−';
            document.getElementById('txnModalAmount').textContent = sign + ' ₦' + d.amount;

            // Status Badge
            const st = statusConfig[statusKey] || { bg: '#f3f4f6', text: '#374151', label: d.status };
            const statusSpan = document.getElementById('txnModalStatusSpan');
            statusSpan.textContent      = st.label;
            statusSpan.style.background = st.bg;
            statusSpan.style.color      = st.text;
            statusSpan.style.fontWeight = '700';

            // Detailed Rows
            document.getElementById('txnModalRef').textContent       = d.reference || '—';
            document.getElementById('txnModalType').textContent      = isCredit ? '⬆ Inflow (Credit)' : '⬇ Outflow (Debit)';
            document.getElementById('txnModalCategory').textContent  = d.category || '—';
            document.getElementById('txnModalDesc').textContent      = d.description || '—';
            document.getElementById('txnModalFee').textContent       = '₦' + (d.fee || '0.00');

            // Balance Details
            document.getElementById('txnModalBalBefore').textContent = '₦' + (d.balanceBefore || '0.00');
            document.getElementById('txnModalBalAfter').textContent  = '₦' + (d.balanceAfter || '0.00');

            // Token / PIN Highlight
            const token = (d.token || '').trim();
            const tokenBox = document.getElementById('txnModalTokenBox');
            const tokenVal = document.getElementById('txnModalTokenVal');
            const tokenTitle = document.getElementById('txnModalTokenTitle');

            if (token && token !== '—') {
                tokenBox.style.display = 'block';
                tokenVal.textContent = token;
                if (rawCat.includes('exam') || rawCat.includes('pin') || rawCat.includes('waec') || rawCat.includes('jamb')) {
                    tokenTitle.innerHTML = '<i class="fas fa-key me-1 text-warning"></i> Examination PIN / Serial';
                } else if (rawCat.includes('electric')) {
                    tokenTitle.innerHTML = '<i class="fas fa-bolt me-1 text-warning"></i> Prepaid Meter Token';
                } else {
                    tokenTitle.innerHTML = '<i class="fas fa-key me-1 text-warning"></i> Recharge PIN / Token';
                }

                document.getElementById('txnModalCopyTokenBtn').onclick = function () {
                    navigator.clipboard.writeText(token).then(function () {
                        Swal.fire({
                            icon: 'success',
                            title: 'Token Copied',
                            text: 'Recharge token copied to clipboard!',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    });
                };
            } else {
                tokenBox.style.display = 'none';
            }

            // Copy Reference button in footer
            document.getElementById('txnModalCopyRefBtn').onclick = function () {
                navigator.clipboard.writeText(d.reference).then(function () {
                    Swal.fire({
                        icon: 'success',
                        title: 'Reference Copied',
                        text: d.reference,
                        timer: 1500,
                        showConfirmButton: false
                    });
                });
            };

            // Copy Reference button in receipt body
            document.getElementById('txnModalCopyRefInline').onclick = function () {
                navigator.clipboard.writeText(d.reference).then(function () {
                    Swal.fire({
                        icon: 'success',
                        title: 'Reference Copied',
                        text: d.reference,
                        timer: 1500,
                        showConfirmButton: false
                    });
                });
            };

            // Share Image (PNG)
            document.getElementById('txnModalShareImgBtn').onclick = function () {
                generateReceiptFile('image', d.reference);
            };

            // Share PDF
            document.getElementById('txnModalSharePdfBtn').onclick = function () {
                generateReceiptFile('pdf', d.reference);
            };

            // Show Modal
            const modalEl = document.getElementById('txnDetailModal');
            const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
            modalInstance.show();
        });
    });

    // Receipt Export Engine
    function generateReceiptFile(format, reference) {
        const element = document.getElementById('txnReceiptCaptureArea');
        if (!element) return;

        Swal.fire({
            title: 'Generating Receipt',
            text: 'Preparing official transaction receipt...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        html2canvas(element, {
            scale: 2,
            useCORS: true,
            backgroundColor: '#ffffff',
            logging: false
        }).then(function (canvas) {
            if (format === 'image') {
                canvas.toBlob(function (blob) {
                    if (!blob) {
                        Swal.fire('Error', 'Failed to render receipt image.', 'error');
                        return;
                    }
                    Swal.close();
                    triggerFileSaveOrShare(blob, 'Receipt_' + reference + '.png', 'image/png', reference);
                }, 'image/png');
            } else if (format === 'pdf') {
                const imgData = canvas.toDataURL('image/png');
                const { jsPDF } = window.jspdf;
                const widthPt = canvas.width / 2;
                const heightPt = canvas.height / 2;

                const pdf = new jsPDF({
                    orientation: widthPt > heightPt ? 'l' : 'p',
                    unit: 'pt',
                    format: [widthPt, heightPt]
                });

                pdf.addImage(imgData, 'PNG', 0, 0, widthPt, heightPt, '', 'FAST');
                const pdfBlob = pdf.output('blob');
                Swal.close();
                triggerFileSaveOrShare(pdfBlob, 'Receipt_' + reference + '.pdf', 'application/pdf', reference);
            }
        }).catch(function (error) {
            console.error('Receipt generation failed:', error);
            Swal.fire('Error', 'An error occurred while generating the receipt.', 'error');
        });
    }

    function triggerFileSaveOrShare(blob, filename, mimeType, reference) {
        const file = new File([blob], filename, { type: mimeType });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            navigator.share({
                files: [file],
                title: 'Transaction Receipt',
                text: 'Official Transaction Receipt (Ref: ' + reference + ')'
            }).catch(function () {
                downloadDirectly(blob, filename);
            });
        } else {
            downloadDirectly(blob, filename);
        }
    }

    function downloadDirectly(blob, filename) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () {
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }, 1000);
    }
})();
</script>
