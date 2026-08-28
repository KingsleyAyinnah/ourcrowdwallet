<?php
/**
 * OURCR ONLINE - Transactions History
 * Fully responsive, pixel-perfect UI for mobile, tablet, and desktop
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Transaction History';
$user      = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Get pagination
$page = get('p') ? max(1, (int)get('p')) : (get('page') ? max(1, (int)get('page')) : 1);

// Get filters
$category = get('category') ?: null;
$type     = get('type')     ?: null;
$status   = get('status')   ?: null;
$search   = get('q')        ?: null;

// Get transactions
$result = Ourcr\Wallet::getTransactions(
    $user['id'],
    $page,
    20,
    $category,
    $status,
    $type,
    $search
);

$transactions = $result['transactions'] ?? [];
$total        = $result['total']        ?? 0;
$pagination   = $result['pagination']   ?? [];

// User wallet balance
$walletBalance = (float)($user['wallet_balance'] ?? 0);

// Inflow / Outflow Summary Statistics for this user
$statsRow = Database::fetchOne(
    "SELECT 
        COALESCE(SUM(CASE WHEN type = 'credit' AND status = 'success' THEN amount ELSE 0 END), 0) AS total_inflow,
        COALESCE(SUM(CASE WHEN type = 'debit' AND status = 'success' THEN amount ELSE 0 END), 0) AS total_outflow,
        COUNT(CASE WHEN status = 'success' THEN 1 END) AS successful_count,
        COUNT(*) AS total_count
     FROM wallet_transactions 
     WHERE user_id = ?",
    [$user['id']]
);
$totalInflow     = (float)($statsRow['total_inflow'] ?? 0);
$totalOutflow    = (float)($statsRow['total_outflow'] ?? 0);
$successfulCount = (int)($statsRow['successful_count'] ?? 0);
$totalCount      = (int)($statsRow['total_count'] ?? 0);

// Helper for category metadata (icons, colors, badges)
if (!function_exists('getCategoryVisuals')) {
function getCategoryVisuals(string $cat): array
{
    return match (strtolower(trim($cat))) {
        'deposit' => [
            'icon'  => 'fas fa-arrow-down-left',
            'label' => 'Deposit',
            'bg'    => 'rgba(16, 185, 129, 0.12)',
            'color' => '#10b981',
        ],
        'withdrawal' => [
            'icon'  => 'fas fa-arrow-up-right',
            'label' => 'Withdrawal',
            'bg'    => 'rgba(239, 68, 68, 0.12)',
            'color' => '#ef4444',
        ],
        'transfer' => [
            'icon'  => 'fas fa-paper-plane',
            'label' => 'Transfer',
            'bg'    => 'rgba(99, 102, 241, 0.12)',
            'color' => '#6366f1',
        ],
        'airtime' => [
            'icon'  => 'fas fa-phone-alt',
            'label' => 'Airtime',
            'bg'    => 'rgba(245, 158, 11, 0.12)',
            'color' => '#f59e0b',
        ],
        'data' => [
            'icon'  => 'fas fa-wifi',
            'label' => 'Data Bundle',
            'bg'    => 'rgba(14, 165, 233, 0.12)',
            'color' => '#0ea5e9',
        ],
        'cable_tv', 'cable', 'tv' => [
            'icon'  => 'fas fa-tv',
            'label' => 'Cable TV',
            'bg'    => 'rgba(168, 85, 247, 0.12)',
            'color' => '#a855f7',
        ],
        'electricity', 'electric', 'power' => [
            'icon'  => 'fas fa-bolt',
            'label' => 'Electricity',
            'bg'    => 'rgba(234, 179, 8, 0.15)',
            'color' => '#ca8a04',
        ],
        'betting', 'bet' => [
            'icon'  => 'fas fa-futbol',
            'label' => 'Betting Funding',
            'bg'    => 'rgba(20, 184, 166, 0.12)',
            'color' => '#14b8a6',
        ],
        'exam_pin', 'exam', 'waec', 'jamb', 'neco' => [
            'icon'  => 'fas fa-graduation-cap',
            'label' => 'Exam Pin',
            'bg'    => 'rgba(236, 72, 153, 0.12)',
            'color' => '#ec4899',
        ],
        'referral_bonus', 'bonus' => [
            'icon'  => 'fas fa-gift',
            'label' => 'Referral Bonus',
            'bg'    => 'rgba(16, 185, 129, 0.12)',
            'color' => '#10b981',
        ],
        default => [
            'icon'  => 'fas fa-receipt',
            'label' => ucfirst(str_replace('_', ' ', $cat)),
            'bg'    => 'rgba(107, 114, 128, 0.12)',
            'color' => '#6b7280',
        ],
    };
}
}

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            <div class="fade-in-up">
                
                <!-- Page Header & Action Bar -->
                <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3 mb-4">
                    <div>
                        <h3 class="fw-extrabold text-dark mb-1">Transaction History</h3>
                        <p class="text-muted small mb-0">Track, filter, and download detailed receipts for all account activity.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap w-100 w-md-auto">
                        <a href="<?= APP_URL ?>/deposit" class="btn btn-danger bg-site-color border-0 px-3 rounded-pill shadow-sm d-inline-flex align-items-center gap-2">
                            <i class="fas fa-plus-circle"></i> <span>Add Funds</span>
                        </a>
                        <a href="<?= APP_URL ?>/withdraw" class="btn btn-outline-dark px-3 rounded-pill d-inline-flex align-items-center gap-2">
                            <i class="fas fa-arrow-up-right-from-square"></i> <span>Withdraw</span>
                        </a>
                        <?php if (!empty($transactions)): ?>
                            <button type="button" class="btn btn-outline-secondary px-3 rounded-pill d-inline-flex align-items-center gap-2" id="exportCsvBtn" title="Export current records to CSV">
                                <i class="fas fa-file-csv text-success"></i> <span>Export</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════════════════════════
                     SUMMARY STAT CARDS
                ══════════════════════════════════════════════════════════════════════ -->
                <div class="row g-3 mb-4">
                    <!-- Available Balance -->
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card border-0 shadow-sm rounded-20 p-3 h-100 bg-white position-relative overflow-hidden">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="text-muted small fw-semibold">Wallet Balance</span>
                                <div class="txn-stat-icon" style="background: rgba(220, 38, 38, 0.1); color: var(--site-color, #dc2626);">
                                    <i class="fas fa-wallet"></i>
                                </div>
                            </div>
                            <h4 class="fw-extrabold text-dark mb-1"><?= formatMoney($walletBalance) ?></h4>
                            <div class="d-flex align-items-center justify-content-between mt-auto">
                                <span class="badge bg-light text-dark rounded-pill px-2 py-1 small">Available Now</span>
                                <a href="<?= APP_URL ?>/deposit" class="text-decoration-none small fw-bold" style="color: var(--site-color, #dc2626);">Fund <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                        </div>
                    </div>

                    <!-- Total Inflow -->
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card border-0 shadow-sm rounded-20 p-3 h-100 bg-white position-relative overflow-hidden">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="text-muted small fw-semibold">Total Inflow (Credits)</span>
                                <div class="txn-stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                                    <i class="fas fa-arrow-down"></i>
                                </div>
                            </div>
                            <h4 class="fw-extrabold text-success mb-1"><?= formatMoney($totalInflow) ?></h4>
                            <span class="text-muted small"><i class="fas fa-check-circle text-success me-1"></i> Lifetime deposits & bonuses</span>
                        </div>
                    </div>

                    <!-- Total Outflow -->
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card border-0 shadow-sm rounded-20 p-3 h-100 bg-white position-relative overflow-hidden">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="text-muted small fw-semibold">Total Outflow (Debits)</span>
                                <div class="txn-stat-icon" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">
                                    <i class="fas fa-arrow-up"></i>
                                </div>
                            </div>
                            <h4 class="fw-extrabold text-danger mb-1"><?= formatMoney($totalOutflow) ?></h4>
                            <span class="text-muted small"><i class="fas fa-shopping-cart text-muted me-1"></i> Withdrawals & bill payments</span>
                        </div>
                    </div>

                    <!-- Total Completed -->
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card border-0 shadow-sm rounded-20 p-3 h-100 bg-white position-relative overflow-hidden">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="text-muted small fw-semibold">Total Transactions</span>
                                <div class="txn-stat-icon" style="background: rgba(99, 102, 241, 0.1); color: #6366f1;">
                                    <i class="fas fa-layer-group"></i>
                                </div>
                            </div>
                            <h4 class="fw-extrabold text-dark mb-1"><?= number_format($totalCount) ?></h4>
                            <span class="text-muted small"><span class="fw-bold text-success"><?= number_format($successfulCount) ?></span> successful operations</span>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════════════════════════
                     FILTER & SEARCH PANEL
                ══════════════════════════════════════════════════════════════════════ -->
                <div class="card border-0 shadow-sm rounded-20 mb-4 overflow-hidden">
                    <div class="card-body p-3 p-lg-4">
                        <form method="GET" action="<?= APP_URL ?>/transactions" id="txnFilterForm" class="row g-3 align-items-end">
                            
                            <!-- Search Query Input -->
                            <div class="col-12 col-md-4 col-xl-4">
                                <label for="searchInput" class="form-label small fw-bold text-muted mb-1">
                                    <i class="fas fa-search me-1"></i> Search Reference or Description
                                </label>
                                <div class="position-relative">
                                    <input type="text" class="form-control rounded-pill ps-4 pe-4" id="searchInput" name="q" value="<?= e($search ?? '') ?>" placeholder="e.g. WD260826..., Airtime, MTN...">
                                </div>
                            </div>

                            <!-- Category Select -->
                            <div class="col-12 col-sm-6 col-md-3 col-xl-2">
                                <label for="categorySelect" class="form-label small fw-bold text-muted mb-1">
                                    <i class="fas fa-layer-group me-1"></i> Category
                                </label>
                                <select class="form-select rounded-pill" id="categorySelect" name="category">
                                    <option value="">All Categories</option>
                                    <option value="deposit"        <?= $category === 'deposit'        ? 'selected' : '' ?>>Deposit</option>
                                    <option value="withdrawal"     <?= $category === 'withdrawal'     ? 'selected' : '' ?>>Withdrawal</option>
                                    <option value="transfer"       <?= $category === 'transfer'       ? 'selected' : '' ?>>Transfer</option>
                                    <option value="airtime"        <?= $category === 'airtime'        ? 'selected' : '' ?>>Airtime</option>
                                    <option value="data"           <?= $category === 'data'           ? 'selected' : '' ?>>Data Bundle</option>
                                    <option value="electricity"    <?= $category === 'electricity'    ? 'selected' : '' ?>>Electricity</option>
                                    <option value="cable_tv"       <?= $category === 'cable_tv'       ? 'selected' : '' ?>>Cable TV</option>
                                    <option value="betting"        <?= $category === 'betting'        ? 'selected' : '' ?>>Betting</option>
                                    <option value="exam_pin"       <?= $category === 'exam_pin'       ? 'selected' : '' ?>>Exam Pins</option>
                                    <option value="referral_bonus" <?= $category === 'referral_bonus' ? 'selected' : '' ?>>Referral Bonus</option>
                                </select>
                            </div>

                            <!-- Flow Type Select -->
                            <div class="col-12 col-sm-6 col-md-2 col-xl-2">
                                <label for="typeSelect" class="form-label small fw-bold text-muted mb-1">
                                    <i class="fas fa-exchange-alt me-1"></i> Flow Type
                                </label>
                                <select class="form-select rounded-pill" id="typeSelect" name="type">
                                    <option value="">All Types</option>
                                    <option value="credit" <?= $type === 'credit' ? 'selected' : '' ?>>Inflow (Credit +)</option>
                                    <option value="debit"  <?= $type === 'debit'  ? 'selected' : '' ?>>Outflow (Debit −)</option>
                                </select>
                            </div>

                            <!-- Status Select -->
                            <div class="col-12 col-sm-6 col-md-3 col-xl-2">
                                <label for="statusSelect" class="form-label small fw-bold text-muted mb-1">
                                    <i class="fas fa-shield-alt me-1"></i> Status
                                </label>
                                <select class="form-select rounded-pill" id="statusSelect" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="success"  <?= $status === 'success'  ? 'selected' : '' ?>>Success</option>
                                    <option value="pending"  <?= $status === 'pending'  ? 'selected' : '' ?>>Pending</option>
                                    <option value="failed"   <?= $status === 'failed'   ? 'selected' : '' ?>>Failed</option>
                                    <option value="reversed" <?= $status === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                                </select>
                            </div>

                            <!-- Action Buttons -->
                            <div class="col-12 col-sm-6 col-md-12 col-xl-2 d-flex gap-2">
                                <button type="submit" class="btn btn-danger bg-site-color border-0 w-100 rounded-pill py-2 fw-semibold">
                                    Filter <i class="fas fa-filter ms-1"></i>
                                </button>
                                <?php if ($category || $type || $status || $search): ?>
                                    <a href="<?= APP_URL ?>/transactions" class="btn btn-outline-secondary rounded-pill py-2 px-3" title="Clear all active filters">
                                        <i class="fas fa-rotate-left"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <!-- Quick Category Pills -->
                        <div class="d-flex align-items-center gap-2 overflow-x-auto pt-3 mt-3 border-top pb-1 txn-quick-chips">
                            <span class="small fw-bold text-muted me-1 text-nowrap"><i class="fas fa-sliders me-1"></i> Quick View:</span>
                            <a href="<?= APP_URL ?>/transactions" class="txn-chip <?= (!$category && !$type && !$status) ? 'active' : '' ?>">All</a>
                            <a href="<?= APP_URL ?>/transactions?category=deposit" class="txn-chip <?= $category === 'deposit' ? 'active' : '' ?>">Deposits</a>
                            <a href="<?= APP_URL ?>/transactions?category=airtime" class="txn-chip <?= $category === 'airtime' ? 'active' : '' ?>">Airtime</a>
                            <a href="<?= APP_URL ?>/transactions?category=data" class="txn-chip <?= $category === 'data' ? 'active' : '' ?>">Data</a>
                            <a href="<?= APP_URL ?>/transactions?category=electricity" class="txn-chip <?= $category === 'electricity' ? 'active' : '' ?>">Electricity</a>
                            <a href="<?= APP_URL ?>/transactions?category=cable_tv" class="txn-chip <?= $category === 'cable_tv' ? 'active' : '' ?>">Cable TV</a>
                            <a href="<?= APP_URL ?>/transactions?category=withdrawal" class="txn-chip <?= $category === 'withdrawal' ? 'active' : '' ?>">Withdrawals</a>
                            <a href="<?= APP_URL ?>/transactions?category=transfer" class="txn-chip <?= $category === 'transfer' ? 'active' : '' ?>">Transfers</a>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════════════════════════════════════
                     TRANSACTIONS LIST (TABLE FOR DESKTOP / CARDS FOR MOBILE)
                ══════════════════════════════════════════════════════════════════════ -->
                <div class="card border-0 shadow-sm rounded-20 mb-4 overflow-hidden">
                    <div class="card-header bg-white border-0 py-3 px-3 px-md-4 d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold text-dark fs-6">Records</span>
                            <span class="badge bg-light text-muted rounded-pill px-2 py-1 small"><?= number_format($total) ?> found</span>
                        </div>
                        <span class="small text-muted">Page <?= $pagination['current'] ?? 1 ?> of <?= max(1, $pagination['last'] ?? 1) ?></span>
                    </div>

                    <div class="card-body p-0">
                        <?php if (empty($transactions)): ?>
                            <div class="text-center py-5 px-3">
                                <div class="txn-empty-state-icon mb-3">
                                    <i class="fas fa-receipt"></i>
                                </div>
                                <h5 class="fw-bold text-dark mb-1">No transactions found</h5>
                                <p class="text-muted small mb-4">We couldn't find any transaction matching your current filters.</p>
                                <a href="<?= APP_URL ?>/transactions" class="btn btn-outline-secondary rounded-pill px-4 btn-sm">
                                    <i class="fas fa-rotate-left me-1"></i> Reset Filters
                                </a>
                            </div>
                        <?php else: ?>

                            <!-- Desktop & Tablet Table (>= 768px) -->
                            <div class="table-responsive d-none d-md-block">
                                <table class="table align-middle table-hover mb-0 txn-modern-table" id="desktopTxnTable">
                                    <thead class="table-light text-muted small text-uppercase fw-semibold">
                                        <tr>
                                            <th class="ps-4 py-3">Service & Category</th>
                                            <th class="py-3">Reference ID</th>
                                            <th class="py-3">Narration / Details</th>
                                            <th class="py-3 text-end">Amount</th>
                                            <th class="py-3 text-center">Status</th>
                                            <th class="pe-4 py-3 text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transactions as $txn): ?>
                                            <?php
                                                $isCredit = $txn['type'] === 'credit';
                                                $vis = getCategoryVisuals($txn['category'] ?? '');
                                                $meta = is_array($txn['meta']) ? $txn['meta'] : [];
                                                $tokenVal = $txn['vtpass_token'] ?? $meta['token'] ?? $meta['pin'] ?? '';
                                            ?>
                                            <tr>
                                                <!-- Service & Category -->
                                                <td class="ps-4 py-3">
                                                    <div class="d-flex align-items-center gap-3">
                                                        <div class="txn-avatar" style="background-color: <?= $vis['bg'] ?>; color: <?= $vis['color'] ?>;">
                                                            <i class="<?= $vis['icon'] ?>"></i>
                                                        </div>
                                                        <div>
                                                            <div class="fw-bold text-dark small mb-0"><?= e($vis['label']) ?></div>
                                                            <div class="text-muted" style="font-size: 11px;">
                                                                <i class="far fa-clock me-1"></i><?= date('d M Y, h:i A', strtotime($txn['created_at'])) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                <!-- Reference -->
                                                <td class="py-3">
                                                    <div class="d-flex align-items-center gap-1">
                                                        <span class="font-monospace small text-dark fw-semibold"><?= e(truncate($txn['reference'], 18)) ?></span>
                                                        <button type="button" class="btn btn-sm btn-link text-muted p-0 ms-1 copy-ref-inline" data-ref="<?= e($txn['reference']) ?>" title="Copy Reference">
                                                            <i class="far fa-copy"></i>
                                                        </button>
                                                    </div>
                                                </td>

                                                <!-- Narration / Description -->
                                                <td class="py-3" style="max-width: 260px;">
                                                    <span class="small text-secondary text-truncate d-block" title="<?= e($txn['description'] ?? '—') ?>">
                                                        <?= e($txn['description'] ?: '—') ?>
                                                    </span>
                                                    <?php if (!empty($tokenVal)): ?>
                                                        <span class="badge bg-warning bg-opacity-25 text-dark font-monospace mt-1" style="font-size: 10px;">
                                                            <i class="fas fa-key me-1"></i>PIN/Token available
                                                        </span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Amount -->
                                                <td class="py-3 text-end">
                                                    <div class="fw-bold <?= $isCredit ? 'text-success' : 'text-dark' ?>" style="font-size: 14.5px;">
                                                        <?= ($isCredit ? '+' : '−') . ' ' . formatMoney($txn['amount'], false) ?>
                                                    </div>
                                                    <?php if (!empty($txn['fee']) && (float)$txn['fee'] > 0): ?>
                                                        <div class="text-muted" style="font-size: 11px;">
                                                            Fee: <?= formatMoney($txn['fee'], false) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Status -->
                                                <td class="py-3 text-center">
                                                    <span class="txn-status-badge txn-status-<?= strtolower($txn['status']) ?>">
                                                        <span class="txn-status-dot"></span>
                                                        <?= ucfirst($txn['status']) ?>
                                                    </span>
                                                </td>

                                                <!-- Action -->
                                                <td class="pe-4 py-3 text-end">
                                                    <button type="button"
                                                            class="btn btn-sm btn-light border rounded-pill px-3 txn-view-btn text-dark fw-semibold"
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
                                                            data-token="<?= e($tokenVal) ?>"
                                                            title="View Full Receipt">
                                                        <i class="fas fa-eye me-1 text-muted"></i> View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Mobile Card List (< 768px) -->
                            <div class="d-md-none txn-mobile-cards-list">
                                <?php foreach ($transactions as $txn): ?>
                                    <?php
                                        $isCredit = $txn['type'] === 'credit';
                                        $vis = getCategoryVisuals($txn['category'] ?? '');
                                        $meta = is_array($txn['meta']) ? $txn['meta'] : [];
                                        $tokenVal = $txn['vtpass_token'] ?? $meta['token'] ?? $meta['pin'] ?? '';
                                    ?>
                                    <div class="txn-mobile-card txn-view-btn"
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
                                        
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="txn-avatar-sm" style="background-color: <?= $vis['bg'] ?>; color: <?= $vis['color'] ?>;">
                                                    <i class="<?= $vis['icon'] ?>"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark small mb-0"><?= e($vis['label']) ?></div>
                                                    <span class="text-muted" style="font-size: 11px;"><?= date('d M, h:i A', strtotime($txn['created_at'])) ?></span>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <div class="fw-bold <?= $isCredit ? 'text-success' : 'text-dark' ?>" style="font-size: 14px;">
                                                    <?= ($isCredit ? '+' : '−') . ' ' . formatMoney($txn['amount'], false) ?>
                                                </div>
                                                <span class="txn-status-badge txn-status-<?= strtolower($txn['status']) ?> py-0 px-2 mt-1" style="font-size: 10px;">
                                                    <?= ucfirst($txn['status']) ?>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="d-flex align-items-center justify-content-between pt-2 border-top border-light-subtle" style="font-size: 11px;">
                                            <span class="text-muted text-truncate me-2 font-monospace"><?= e(truncate($txn['reference'], 16)) ?></span>
                                            <span class="text-secondary d-flex align-items-center gap-1">
                                                Receipt <i class="fas fa-chevron-right" style="font-size: 9px;"></i>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Pagination Controls -->
                            <?php if (($pagination['last'] ?? 1) > 1): ?>
                                <div class="card-footer bg-white border-0 py-4">
                                    <nav aria-label="Transaction page navigation">
                                        <ul class="pagination justify-content-center flex-wrap gap-1 mb-0">
                                            
                                            <!-- Previous -->
                                            <li class="page-item <?= empty($pagination['has_prev']) ? 'disabled' : '' ?>">
                                                <a class="page-link rounded-circle d-flex align-items-center justify-content-center txn-page-link"
                                                   href="<?= APP_URL ?>/transactions?p=<?= $pagination['prev'] ?>&category=<?= e($category ?? '') ?>&type=<?= e($type ?? '') ?>&status=<?= e($status ?? '') ?>&q=<?= e($search ?? '') ?>"
                                                   aria-label="Previous">
                                                    <i class="fas fa-chevron-left" style="font-size: 11px;"></i>
                                                </a>
                                            </li>

                                            <!-- Numbered Pages -->
                                            <?php
                                                $startPage = max(1, $pagination['current'] - 2);
                                                $endPage   = min($pagination['last'], $pagination['current'] + 2);
                                            ?>
                                            <?php if ($startPage > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link rounded-circle d-flex align-items-center justify-content-center txn-page-link"
                                                       href="<?= APP_URL ?>/transactions?p=1&category=<?= e($category ?? '') ?>&type=<?= e($type ?? '') ?>&status=<?= e($status ?? '') ?>&q=<?= e($search ?? '') ?>">1</a>
                                                </li>
                                                <?php if ($startPage > 2): ?>
                                                    <li class="page-item disabled"><span class="page-link border-0">...</span></li>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                                <li class="page-item <?= $pagination['current'] === $i ? 'active' : '' ?>">
                                                    <a class="page-link rounded-circle d-flex align-items-center justify-content-center txn-page-link"
                                                       href="<?= APP_URL ?>/transactions?p=<?= $i ?>&category=<?= e($category ?? '') ?>&type=<?= e($type ?? '') ?>&status=<?= e($status ?? '') ?>&q=<?= e($search ?? '') ?>">
                                                        <?= $i ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>

                                            <?php if ($endPage < $pagination['last']): ?>
                                                <?php if ($endPage < $pagination['last'] - 1): ?>
                                                    <li class="page-item disabled"><span class="page-link border-0">...</span></li>
                                                <?php endif; ?>
                                                <li class="page-item">
                                                    <a class="page-link rounded-circle d-flex align-items-center justify-content-center txn-page-link"
                                                       href="<?= APP_URL ?>/transactions?p=<?= $pagination['last'] ?>&category=<?= e($category ?? '') ?>&type=<?= e($type ?? '') ?>&status=<?= e($status ?? '') ?>&q=<?= e($search ?? '') ?>">
                                                        <?= $pagination['last'] ?>
                                                    </a>
                                                </li>
                                            <?php endif; ?>

                                            <!-- Next -->
                                            <li class="page-item <?= empty($pagination['has_next']) ? 'disabled' : '' ?>">
                                                <a class="page-link rounded-circle d-flex align-items-center justify-content-center txn-page-link"
                                                   href="<?= APP_URL ?>/transactions?p=<?= $pagination['next'] ?>&category=<?= e($category ?? '') ?>&type=<?= e($type ?? '') ?>&status=<?= e($status ?? '') ?>&q=<?= e($search ?? '') ?>"
                                                   aria-label="Next">
                                                    <i class="fas fa-chevron-right" style="font-size: 11px;"></i>
                                                </a>
                                            </li>

                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>

                        <?php endif; ?>
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
     RESPONSIVE PIXEL-PERFECT UI STYLES
══════════════════════════════════════════════════════════════════════════════ -->
<style>
/* Stat card icon */
.txn-stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

/* Quick Filter Chips */
.txn-quick-chips::-webkit-scrollbar {
    display: none;
}
.txn-quick-chips {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
.txn-chip {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12.5px;
    font-weight: 600;
    color: #4b5563;
    background: #f3f4f6;
    text-decoration: none;
    white-space: nowrap;
    transition: all 0.2s ease;
}
.txn-chip:hover {
    background: #e5e7eb;
    color: #111827;
}
.txn-chip.active {
    background: var(--site-color, #dc2626);
    color: #ffffff !important;
}

/* Avatar circular icons */
.txn-avatar {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}
.txn-avatar-sm {
    width: 36px;
    height: 36px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}

/* Status Badges */
.txn-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 700;
    white-space: nowrap;
}
.txn-status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
}
.txn-status-success {
    background: rgba(16, 185, 129, 0.12);
    color: #059669;
}
.txn-status-success .txn-status-dot {
    background: #10b981;
    box-shadow: 0 0 6px rgba(16, 185, 129, 0.6);
}
.txn-status-pending {
    background: rgba(245, 158, 11, 0.12);
    color: #d97706;
}
.txn-status-pending .txn-status-dot {
    background: #f59e0b;
}
.txn-status-failed {
    background: rgba(239, 68, 68, 0.12);
    color: #dc2626;
}
.txn-status-failed .txn-status-dot {
    background: #ef4444;
}
.txn-status-reversed {
    background: rgba(107, 114, 128, 0.12);
    color: #6b7280;
}
.txn-status-reversed .txn-status-dot {
    background: #6b7280;
}

/* Table Enhancements */
.txn-modern-table tbody tr {
    transition: background-color 0.15s ease;
}
.txn-modern-table tbody tr:hover {
    background-color: #fafbfc;
}

/* Mobile Transaction Card */
.txn-mobile-cards-list {
    display: flex;
    flex-direction: column;
    gap: 0;
}
.txn-mobile-card {
    padding: 14px 16px;
    border-bottom: 1px solid #f3f4f6;
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.txn-mobile-card:active {
    background-color: #f9fafb;
}
.txn-mobile-card:last-child {
    border-bottom: none;
}

/* Empty State */
.txn-empty-state-icon {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: #f3f4f6;
    color: #9ca3af;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
}

/* Pagination */
.txn-page-link {
    width: 36px;
    height: 36px;
    color: #4b5563;
    border: 1px solid #e5e7eb;
    font-size: 13px;
    font-weight: 600;
}
.page-item.active .txn-page-link {
    background-color: var(--site-color, #dc2626) !important;
    border-color: var(--site-color, #dc2626) !important;
    color: #ffffff !important;
}

/* Modal Styling */
.rounded-20 { border-radius: 20px !important; }
.rounded-24 { border-radius: 24px !important; }
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

<!-- html2canvas and jsPDF Libraries for Instant Share & Export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
(function () {
    // Map category icons
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

    // Quick inline copy for reference
    document.querySelectorAll('.copy-ref-inline').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            const ref = btn.getAttribute('data-ref');
            navigator.clipboard.writeText(ref).then(function () {
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check text-success"></i>';
                setTimeout(function () {
                    btn.innerHTML = originalHtml;
                }, 1500);
            });
        });
    });

    // Handle View Transaction Receipt
    document.querySelectorAll('.txn-view-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const d = btn.dataset;
            const isCredit = d.type === 'credit';
            const statusKey = (d.status || '').toLowerCase();
            const rawCat = (d.rawcat || d.category || '').toLowerCase();

            // Set Header Category Icon
            let iconClass = iconMap[rawCat] || 'fas fa-receipt';
            document.getElementById('txnModalIcon').innerHTML = '<i class="' + iconClass + '"></i>';

            // Category & Date
            document.getElementById('txnModalCategoryLabel').textContent = d.category || 'Transaction';
            document.getElementById('txnModalDateLabel').textContent     = d.date || '—';
            document.getElementById('txnModalDate').textContent          = d.date || '—';

            // Amount Spotlight
            const sign = isCredit ? '+' : '−';
            document.getElementById('txnModalAmount').textContent = sign + ' ₦' + d.amount;

            // Status Badge
            const st = statusConfig[statusKey] || { bg: '#f3f4f6', text: '#374151', label: d.status };
            const statusSpan = document.getElementById('txnModalStatusSpan');
            statusSpan.textContent      = st.label;
            statusSpan.style.background = st.bg;
            statusSpan.style.color      = st.text;
            statusSpan.style.fontWeight = '700';

            // Detailed Key-Value Rows
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

    // Receipt Export Engine (Image & PDF)
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

    // Client-side CSV Exporter
    const exportBtn = document.getElementById('exportCsvBtn');
    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            const rows = [];
            const headers = ['Reference', 'Category', 'Type', 'Amount', 'Fee', 'Description', 'Status', 'Date'];
            rows.push(headers.join(','));

            document.querySelectorAll('#desktopTxnTable tbody tr').forEach(function (tr) {
                const btn = tr.querySelector('.txn-view-btn');
                if (btn) {
                    const d = btn.dataset;
                    const rowData = [
                        '"' + (d.reference || '') + '"',
                        '"' + (d.category || '') + '"',
                        '"' + (d.type || '') + '"',
                        '"' + (d.amount || '') + '"',
                        '"' + (d.fee || '0.00') + '"',
                        '"' + (d.description || '').replace(/"/g, '""') + '"',
                        '"' + (d.status || '') + '"',
                        '"' + (d.date || '') + '"'
                    ];
                    rows.push(rowData.join(','));
                }
            });

            const csvContent = 'data:text/csv;charset=utf-8,' + encodeURIComponent(rows.join('\n'));
            const downloadLink = document.createElement('a');
            downloadLink.setAttribute('href', csvContent);
            downloadLink.setAttribute('download', 'Transactions_' + new Date().toISOString().slice(0, 10) + '.csv');
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
        });
    }
})();
</script>
