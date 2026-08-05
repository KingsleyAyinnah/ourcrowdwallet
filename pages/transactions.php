<?php
/**
 * OURCR ONLINE - Transactions History
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Transactions History';
$user      = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Get current page
$page = get('p') ? max(1, (int)get('p')) : (get('page') ? max(1, (int)get('page')) : 1);

// Get filters
$category = get('category') ?: null;
$type     = get('type')     ?: null;
$status   = get('status')   ?: null;
$dateFrom = get('date_from') ?: null;
$dateTo   = get('date_to')   ?: null;

// Get transactions
$result = Ourcr\Wallet::getTransactions(
    $user['id'],
    $page,
    PER_PAGE,
    $category,
    $status,
    $type
);

$transactions = $result['transactions'] ?? [];
$total        = $result['total']        ?? 0;
$pagination   = $result['pagination']   ?? [];

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            <div class="fade-in-up">
                
                <div class="mb-4">
                    <h4 class="fw-bold">Transaction History</h4>
                    <p class="text-muted small">View all inflows and outflow payments made from your account balance.</p>
                </div>

                <!-- Filter Bar -->
                <div class="card border-0 shadow-sm rounded-16 mb-4">
                    <div class="card-body p-3 p-md-4">
                        <form method="GET" action="<?= APP_URL ?>/transactions" class="row g-3 align-items-end">
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label for="category" class="form-label small text-muted">Category</label>
                                <select class="form-select" id="category" name="category">
                                    <option value="">All Categories</option>
                                    <option value="deposit"       <?= $category === 'deposit'       ? 'selected' : '' ?>>Deposit</option>
                                    <option value="withdrawal"    <?= $category === 'withdrawal'    ? 'selected' : '' ?>>Withdrawal</option>
                                    <option value="transfer"      <?= $category === 'transfer'      ? 'selected' : '' ?>>Transfer</option>
                                    <option value="airtime"       <?= $category === 'airtime'       ? 'selected' : '' ?>>Airtime</option>
                                    <option value="data"          <?= $category === 'data'          ? 'selected' : '' ?>>Data</option>
                                    <option value="cable_tv"      <?= $category === 'cable_tv'      ? 'selected' : '' ?>>Cable TV</option>
                                    <option value="electricity"   <?= $category === 'electricity'   ? 'selected' : '' ?>>Electricity</option>
                                    <option value="betting"       <?= $category === 'betting'       ? 'selected' : '' ?>>Betting</option>
                                    <option value="exam_pin"      <?= $category === 'exam_pin'      ? 'selected' : '' ?>>Exam Pins</option>
                                    <option value="referral_bonus" <?= $category === 'referral_bonus' ? 'selected' : '' ?>>Referral Bonus</option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label for="type" class="form-label small text-muted">Type</label>
                                <select class="form-select" id="type" name="type">
                                    <option value="">All Types</option>
                                    <option value="credit" <?= $type === 'credit' ? 'selected' : '' ?>>Credit (Inflow)</option>
                                    <option value="debit"  <?= $type === 'debit'  ? 'selected' : '' ?>>Debit (Outflow)</option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label for="status" class="form-label small text-muted">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="success"  <?= $status === 'success'  ? 'selected' : '' ?>>Success</option>
                                    <option value="pending"  <?= $status === 'pending'  ? 'selected' : '' ?>>Pending</option>
                                    <option value="failed"   <?= $status === 'failed'   ? 'selected' : '' ?>>Failed</option>
                                    <option value="reversed" <?= $status === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-lg-3 d-flex gap-2">
                                <button type="submit" class="btn btn-danger w-100 bg-site-color border-0">
                                    Filter <i class="fas fa-filter ms-1"></i>
                                </button>
                                <a href="<?= APP_URL ?>/transactions" class="btn btn-outline-secondary w-100">
                                    Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Ledger Cards -->
                <div class="card border-0 shadow-sm rounded-16 mb-4">
                    <div class="card-body p-3 p-md-4">
                        <?php if (empty($transactions)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-receipt fa-4x text-muted mb-3" style="opacity: 0.3;"></i>
                                <h6 class="fw-bold text-muted">No transactions match your search.</h6>
                                <p class="small text-muted mb-0">Try clearing some filters to expand details.</p>
                            </div>
                        <?php else: ?>
                            <div class="txn-history-table-wrapper d-none d-md-block">
                                <div class="txn-history-scroll">
                                    <table class="table align-middle txn-history-table">
                                        <thead>
                                            <tr class="text-muted small">
                                                <th>Reference</th>
                                                <th>Category</th>
                                                <th>Description</th>
                                                <th>Amount</th>
                                                <th>Date</th>
                                                <th>Status</th>
                                                <th class="text-center">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($transactions as $txn): ?>
                                                <?php $isCredit = $txn['type'] === 'credit'; ?>
                                                <tr>
                                                    <td class="small text-muted font-monospace"><?= e($txn['reference']) ?></td>
                                                    <td>
                                                        <span class="badge bg-light text-dark fw-bold">
                                                            <?= ucfirst(str_replace('_', ' ', $txn['category'])) ?>
                                                        </span>
                                                    </td>
                                                    <td><span class="small"><?= e($txn['description'] ?: '—') ?></span></td>
                                                    <td class="fw-bold <?= $isCredit ? 'text-success' : 'text-danger' ?>">
                                                        <?= ($isCredit ? '+' : '−') . ' ' . formatMoney($txn['amount'], false) ?>
                                                    </td>
                                                    <td class="small"><?= formatDate($txn['created_at']) ?></td>
                                                    <td>
                                                        <span class="badge badge-<?= $txn['status'] ?>">
                                                            <?= ucfirst($txn['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <button
                                                            class="btn btn-sm btn-outline-secondary rounded-pill px-3 txn-view-btn"
                                                            data-id="<?= $txn['id'] ?>"
                                                            data-uuid="<?= e($txn['uuid'] ?? '') ?>"
                                                            data-reference="<?= e($txn['reference']) ?>"
                                                            data-category="<?= e(ucfirst(str_replace('_', ' ', $txn['category']))) ?>"
                                                            data-type="<?= e($txn['type']) ?>"
                                                            data-amount="<?= formatMoney($txn['amount'], false) ?>"
                                                            data-fee="<?= formatMoney($txn['fee'] ?? 0, false) ?>"
                                                            data-balance-before="<?= formatMoney($txn['balance_before'] ?? 0, false) ?>"
                                                            data-balance-after="<?= formatMoney($txn['balance_after'] ?? 0, false) ?>"
                                                            data-description="<?= e($txn['description'] ?: '—') ?>"
                                                            data-status="<?= e($txn['status']) ?>"
                                                            data-date="<?= e(date('d M Y, h:i A', strtotime($txn['created_at']))) ?>"
                                                            data-token="<?= e($txn['vtpass_token'] ?? '') ?>"
                                                            title="View Details">
                                                            <i class="fas fa-eye me-1"></i> View
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="txn-history-mobile-list d-md-none">
                                <?php foreach ($transactions as $txn): ?>
                                    <?php
                                        $isCredit = $txn['type'] === 'credit';
                                        $iconClass = match ($txn['category']) {
                                            'deposit' => 'fas fa-arrow-up',
                                            'withdrawal' => 'fas fa-arrow-down',
                                            'transfer' => 'fas fa-exchange-alt',
                                            'airtime' => 'fas fa-phone',
                                            'data' => 'fas fa-wifi',
                                            'cable_tv' => 'fas fa-tv',
                                            'electricity' => 'fas fa-bolt',
                                            'betting' => 'fas fa-futbol',
                                            'exam_pin' => 'fas fa-graduation-cap',
                                            default => 'fas fa-receipt',
                                        };
                                        $iconBg = $isCredit ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)';
                                        $iconColor = $isCredit ? '#10b981' : '#ef4444';
                                    ?>
                                    <div class="txn-history-mobile-item">
                                        <div class="txn-history-mobile-main">
                                            <div class="txn-history-mobile-icon" style="background-color: <?= $iconBg ?>; color: <?= $iconColor ?>;">
                                                <i class="<?= $iconClass ?>"></i>
                                            </div>
                                            <div class="txn-history-mobile-info">
                                                <div class="txn-history-mobile-title"><?= e($txn['description'] ?: ucfirst($txn['category'])) ?></div>
                                                <div class="txn-history-mobile-meta">
                                                    <span><?= e($txn['reference']) ?></span>
                                                    <span><?= formatDate($txn['created_at']) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="txn-history-mobile-side">
                                            <div class="txn-history-mobile-amount <?= $isCredit ? 'text-success' : 'text-danger' ?>">
                                                <?= ($isCredit ? '+' : '−') . ' ' . formatMoney($txn['amount'], false) ?>
                                            </div>
                                            <span class="badge badge-<?= $txn['status'] ?> mt-2"><?= ucfirst($txn['status']) ?></span>
                                            <button
                                                class="btn btn-sm btn-outline-secondary rounded-pill px-3 txn-view-btn mt-2"
                                                data-id="<?= $txn['id'] ?>"
                                                data-uuid="<?= e($txn['uuid'] ?? '') ?>"
                                                data-reference="<?= e($txn['reference']) ?>"
                                                data-category="<?= e(ucfirst(str_replace('_', ' ', $txn['category']))) ?>"
                                                data-type="<?= e($txn['type']) ?>"
                                                data-amount="<?= formatMoney($txn['amount'], false) ?>"
                                                data-fee="<?= formatMoney($txn['fee'] ?? 0, false) ?>"
                                                data-balance-before="<?= formatMoney($txn['balance_before'] ?? 0, false) ?>"
                                                data-balance-after="<?= formatMoney($txn['balance_after'] ?? 0, false) ?>"
                                                data-description="<?= e($txn['description'] ?: '—') ?>"
                                                data-status="<?= e($txn['status']) ?>"
                                                data-date="<?= e(date('d M Y, h:i A', strtotime($txn['created_at']))) ?>"
                                                data-token="<?= e($txn['vtpass_token'] ?? '') ?>"
                                                title="View Details">
                                                <i class="fas fa-eye me-1"></i> View
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Pagination -->
                            <?php if ($pagination['last'] > 1): ?>
                                <nav aria-label="Page navigation" class="mt-4">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= APP_URL ?>/transactions?p=<?= $pagination['prev'] ?>&category=<?= e($category) ?>&type=<?= e($type) ?>&status=<?= e($status) ?>" aria-label="Previous">
                                                <span aria-hidden="true">&laquo;</span>
                                            </a>
                                        </li>
                                        <?php for ($i = 1; $i <= $pagination['last']; $i++): ?>
                                            <li class="page-item <?= $pagination['current'] === $i ? 'active bg-site-color border-0' : '' ?>">
                                                <a class="page-link" href="<?= APP_URL ?>/transactions?p=<?= $i ?>&category=<?= e($category) ?>&type=<?= e($type) ?>&status=<?= e($status) ?>"><?= $i ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= APP_URL ?>/transactions?p=<?= $pagination['next'] ?>&category=<?= e($category) ?>&type=<?= e($type) ?>&status=<?= e($status) ?>" aria-label="Next">
                                                <span aria-hidden="true">&raquo;</span>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>

                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TRANSACTION DETAIL MODAL
══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="txnDetailModal" tabindex="-1" aria-labelledby="txnDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">

            <!-- Captured Receipt Wrapper -->
            <div id="txnReceiptCaptureArea" style="background: #ffffff; border-radius: 20px 20px 0 0; overflow: hidden;">

                <!-- Modal Header -->
                <div class="txn-modal-header p-4" id="txnModalHeaderEl">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-3">
                            <div class="txn-modal-icon" id="txnModalIcon">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <div>
                                <div class="text-white-50" style="font-size: 10px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;"><?= e(setting('site_name', APP_NAME)) ?></div>
                                <div class="text-white fw-bold" id="txnModalCategoryLabel">Transaction</div>
                                <div class="text-white opacity-75 small" id="txnModalDateLabel">—</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <img src="<?= APP_URL ?>/assets/images/logo-white.png" alt="<?= e(setting('site_name', APP_NAME)) ?> Logo" style="height: 25px; width: auto; max-width: 110px; object-fit: contain;">
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" data-html2canvas-ignore="true"></button>
                        </div>
                    </div>

                <!-- Amount Spotlight -->
                <div class="text-center mt-4 mb-2">
                    <div class="text-white opacity-75 small mb-1">Transaction Amount</div>
                    <div class="txn-modal-amount fw-bold" id="txnModalAmount">₦0.00</div>
                    <div class="mt-2" id="txnModalStatusBadge">
                        <span class="badge px-3 py-2 rounded-pill" id="txnModalStatusSpan">—</span>
                    </div>
                </div>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-4">

                <!-- Detail Grid -->
                <div class="txn-detail-grid">
                    <div class="txn-detail-row">
                        <span class="txn-detail-label"><i class="fas fa-hashtag me-2 text-muted"></i>Reference</span>
                        <span class="txn-detail-value font-monospace small" id="txnModalRef">—</span>
                    </div>
                    <div class="txn-detail-row">
                        <span class="txn-detail-label"><i class="fas fa-tag me-2 text-muted"></i>Type</span>
                        <span class="txn-detail-value" id="txnModalType">—</span>
                    </div>
                    <div class="txn-detail-row">
                        <span class="txn-detail-label"><i class="fas fa-layer-group me-2 text-muted"></i>Category</span>
                        <span class="txn-detail-value" id="txnModalCategory">—</span>
                    </div>
                    <div class="txn-detail-row">
                        <span class="txn-detail-label"><i class="fas fa-align-left me-2 text-muted"></i>Description</span>
                        <span class="txn-detail-value small" id="txnModalDesc">—</span>
                    </div>
                    <div class="txn-detail-row">
                        <span class="txn-detail-label"><i class="fas fa-coins me-2 text-muted"></i>Fee</span>
                        <span class="txn-detail-value" id="txnModalFee">—</span>
                    </div>
                    <div class="txn-detail-row" id="txnModalTokenRow" style="display: none;">
                        <span class="txn-detail-label" id="txnModalTokenLabel"><i class="fas fa-key me-2 text-muted"></i>Token</span>
                        <span class="txn-detail-value fw-bold font-monospace" id="txnModalToken" style="font-size: 13px; color: var(--site-color, #DC2626);">—</span>
                    </div>
                    <!-- Balance Before/After hidden from shared receipt for privacy -->
                </div>

                <!-- Info Note -->
                <div class="d-flex align-items-start gap-2 mt-4 p-3 rounded-12" style="background: #f8fafc; border: 1px solid #e5e7eb;">
                    <i class="fas fa-info-circle text-muted mt-1 small"></i>
                    <p class="small text-muted mb-0">Keep your transaction reference safe. It may be required for dispute resolution or verification.</p>
                </div>

                <!-- Receipt Footer Brand -->
                <div class="text-center mt-4 pt-3 border-top text-muted" style="font-size: 11px; border-top-style: dashed !important; opacity: 0.8;">
                    <i class="fas fa-shield-alt text-success me-1"></i> Official Transaction Receipt from <span class="fw-bold text-dark"><?= e(setting('site_name', APP_NAME)) ?></span>
                </div>

            </div>

            </div> <!-- /txnReceiptCaptureArea -->

            <!-- Modal Footer -->
            <div class="modal-footer border-0 pt-0 px-4 pb-4 d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary flex-fill rounded-pill" data-bs-dismiss="modal">
                    Close
                </button>
                <div class="dropdown flex-fill">
                    <button class="btn btn-danger bg-site-color border-0 w-100 rounded-pill dropdown-toggle" type="button" id="shareReceiptDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-share-alt me-2"></i> Share Receipt
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow" aria-labelledby="shareReceiptDropdown" style="border-radius: 12px; min-width: 200px;">
                        <li>
                            <button class="dropdown-item py-2" id="txnModalShareImgBtn">
                                <i class="fas fa-image me-2 text-primary"></i> Share as Image
                            </button>
                        </li>
                        <li>
                            <button class="dropdown-item py-2" id="txnModalSharePdfBtn">
                                <i class="fas fa-file-pdf me-2 text-danger"></i> Share as PDF
                            </button>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <button class="dropdown-item py-2" id="txnModalCopyBtn">
                                <i class="fas fa-copy me-2 text-muted"></i> Copy Reference
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL STYLES (scoped to transaction modal)
══════════════════════════════════════════════════════════════════════════════ -->
<style>
.txn-modal-header {
    background: linear-gradient(135deg, var(--site-color, #DC2626) 0%, #7f1d1d 100%);
    position: relative;
}
.txn-modal-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #fff;
    flex-shrink: 0;
}
.txn-modal-amount {
    font-size: 36px;
    color: #ffffff;
    letter-spacing: -1px;
}
.txn-detail-grid {
    display: flex;
    flex-direction: column;
    gap: 0;
}
.txn-detail-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 12px 0;
    border-bottom: 1px solid #f3f4f6;
    gap: 16px;
}
.txn-detail-row:last-child {
    border-bottom: none;
}
.txn-detail-label {
    font-size: 13px;
    color: #6b7280;
    white-space: nowrap;
    flex-shrink: 0;
}
.txn-detail-value {
    font-size: 13px;
    color: #1f2937;
    font-weight: 600;
    text-align: right;
    word-break: break-all;
}
/* Status badge colours inside modal */
.txn-status-badge-success  { background: rgba(16,185,129,0.15); color: #059669; }
.txn-status-badge-pending  { background: rgba(245,158,11,0.15);  color: #d97706; }
.txn-status-badge-failed   { background: rgba(239,68,68,0.15);   color: #dc2626; }
.txn-status-badge-reversed { background: rgba(107,114,128,0.15); color: #6b7280; }
</style>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>

<!-- html2canvas and jsPDF Libraries -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
(function () {
    // Icon map per category
    const iconMap = {
        deposit:       'fas fa-arrow-down',
        withdrawal:    'fas fa-arrow-up',
        transfer:      'fas fa-exchange-alt',
        airtime:       'fas fa-phone',
        data:          'fas fa-wifi',
        cable_tv:      'fas fa-tv',
        electricity:   'fas fa-bolt',
        betting:       'fas fa-futbol',
        exam_pin:      'fas fa-graduation-cap',
        referral_bonus:'fas fa-gift',
    };

    const statusColors = {
        success:  { bg: 'rgba(16,185,129,0.15)',  text: '#059669' },
        pending:  { bg: 'rgba(245,158,11,0.15)',  text: '#d97706' },
        failed:   { bg: 'rgba(239,68,68,0.15)',   text: '#dc2626' },
        reversed: { bg: 'rgba(107,114,128,0.15)', text: '#6b7280' },
    };

    document.querySelectorAll('.txn-view-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const d = btn.dataset;
            const isCredit = d.type === 'credit';
            const status   = d.status;

            // Header icon
            const rawCat = (btn.getAttribute('data-reference') || '').toLowerCase();
            const catKey = (function () {
                for (const key of Object.keys(iconMap)) {
                    if (d.category.toLowerCase().includes(key.replace('_',' '))) return key;
                }
                return 'deposit';
            })();
            document.getElementById('txnModalIcon').innerHTML = '<i class="' + (iconMap[catKey] || 'fas fa-receipt') + '"></i>';

            // Category & date
            document.getElementById('txnModalCategoryLabel').textContent = d.category;
            document.getElementById('txnModalDateLabel').textContent     = d.date;

            // Amount
            const sign = isCredit ? '+' : '−';
            document.getElementById('txnModalAmount').textContent = sign + ' ₦' + d.amount;

            // Status badge
            const sc = statusColors[status] || { bg: '#f3f4f6', text: '#374151' };
            const sp = document.getElementById('txnModalStatusSpan');
            sp.textContent         = status.charAt(0).toUpperCase() + status.slice(1);
            sp.style.background    = sc.bg;
            sp.style.color         = sc.text;
            sp.style.fontWeight    = '700';

            // Detail rows
            document.getElementById('txnModalRef').textContent       = d.reference;
            document.getElementById('txnModalType').textContent      = isCredit ? '⬆ Credit (Inflow)' : '⬇ Debit (Outflow)';
            document.getElementById('txnModalCategory').textContent  = d.category;
            document.getElementById('txnModalDesc').textContent      = d.description || '—';
            document.getElementById('txnModalFee').textContent       = '₦' + (d.fee || '0.00');
 
            // Token/PIN row
            const token = d.token || '';
            const tokenRow = document.getElementById('txnModalTokenRow');
            const tokenLabel = document.getElementById('txnModalTokenLabel');
            const tokenVal = document.getElementById('txnModalToken');
            
            if (token && token.trim() !== '' && token.trim() !== '—') {
                tokenRow.style.display = 'flex';
                tokenVal.textContent = token;
                
                const catLower = d.category.toLowerCase();
                if (catLower.includes('exam') || catLower.includes('pin') || catLower.includes('jamb') || catLower.includes('waec') || catLower.includes('neco')) {
                    tokenLabel.innerHTML = '<i class="fas fa-key me-2 text-muted"></i>PIN';
                } else {
                    tokenLabel.innerHTML = '<i class="fas fa-key me-2 text-muted"></i>Token';
                }
            } else {
                tokenRow.style.display = 'none';
            }

            // Copy button
            document.getElementById('txnModalCopyBtn').onclick = function () {
                navigator.clipboard.writeText(d.reference).then(function () {
                    const original = document.getElementById('txnModalCopyBtn').innerHTML;
                    document.getElementById('txnModalCopyBtn').innerHTML = '<i class="fas fa-check me-2"></i> Copied!';
                    setTimeout(function () {
                        document.getElementById('txnModalCopyBtn').innerHTML = original;
                    }, 2000);
                });
            };

            // Share as Image
            document.getElementById('txnModalShareImgBtn').onclick = function () {
                shareReceipt('image', d.reference);
            };

            // Share as PDF
            document.getElementById('txnModalSharePdfBtn').onclick = function () {
                shareReceipt('pdf', d.reference);
            };

            // Open modal
            var modal = new bootstrap.Modal(document.getElementById('txnDetailModal'));
            modal.show();
        });
    });

    function shareReceipt(format, reference) {
        const element = document.getElementById('txnReceiptCaptureArea');
        if (!element) return;

        function triggerDownload(blob, filename) {
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

        function shareGeneratedFile(blob, filename, mimeType, label) {
            const file = new File([blob], filename, { type: mimeType });
            const shareData = {
                files: [file],
                title: 'Transaction Receipt',
                text: 'Transaction Receipt (Ref: ' + reference + ')'
            };

            if (navigator.canShare && navigator.canShare({ files: [file] })) {
                navigator.share(shareData).catch(function (err) {
                    console.log('Share cancelled or failed, falling back to download:', err);
                    triggerDownload(blob, filename);
                    Swal.fire({
                        icon: 'info',
                        title: 'Save to device',
                        text: label + ' is ready to save locally.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                });
            } else {
                triggerDownload(blob, filename);
                Swal.fire({
                    icon: 'info',
                    title: 'Saved locally',
                    text: label + ' downloaded to your device.',
                    timer: 2000,
                    showConfirmButton: false
                });
            }
        }

        // Show SweetAlert2 loading indicator
        Swal.fire({
            title: 'Generating Receipt',
            text: 'Please wait while we prepare your transaction receipt...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // Use html2canvas to render the capture area
        html2canvas(element, {
            scale: 2, // scale 2 is crisp and fast on mobile
            useCORS: true,
            backgroundColor: '#ffffff',
            logging: false
        }).then(function (canvas) {
            if (format === 'image') {
                canvas.toBlob(function (blob) {
                    if (!blob) {
                        Swal.fire('Error', 'Failed to generate receipt image.', 'error');
                        return;
                    }
                    Swal.close();
                    shareGeneratedFile(blob, 'Receipt_' + reference + '.png', 'image/png', 'Receipt image');
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
                shareGeneratedFile(pdfBlob, 'Receipt_' + reference + '.pdf', 'application/pdf', 'Receipt PDF');
            }
        }).catch(function (error) {
            console.error('Error generating receipt:', error);
            Swal.fire('Error', 'An error occurred while generating the receipt.', 'error');
        });
    }
})();
</script>
