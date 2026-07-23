<?php
/**
 * OURCR ONLINE - Admin Panel Ledger History & Reversals
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'General Ledger Logs';

$page = get('page') ? max(1, (int)get('page')) : 1;

// Get filters
$category = get('category') ?: null;
$type     = get('type') ?: null;
$status   = get('status') ?: null;
$search   = get('search') ?: null;

// Export to CSV trigger
if (get('action') === 'export') {
    $filters = [
        'category' => $category,
        'type' => $type,
        'status' => $status,
        'search' => $search
    ];
    $csv = Ourcr\Transaction::exportToCSV($filters);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="ourcr_transactions_' . date('Ymd_His') . '.csv"');
    echo $csv;
    exit;
}

// Reversal handler
if (isPost() && post('action') === 'reverse') {
    try {
        requireCsrf();
        $ref = sanitizeString(post('reference'));
        $reason = sanitizeString(post('reason'));

        if (empty($ref) || empty($reason)) {
            throw new Exception("Reference and reversal reason are required.");
        }

        $result = Ourcr\Transaction::reverse($ref, currentUserId(), $reason);
        if ($result['success']) {
            setFlash('success', 'Transaction reversed successfully.');
        } else {
            setFlash('error', $result['message']);
        }
        redirectTo('admin/transactions');
    } catch (Exception $e) {
        setFlash('error', 'Reversal failed: ' . $e->getMessage());
    }
}

// Build query
$params = [];
$where = ["1=1"];

if ($category) {
    $where[] = "wt.category = ?";
    $params[] = $category;
}
if ($type) {
    $where[] = "wt.type = ?";
    $params[] = $type;
}
if ($status) {
    $where[] = "wt.status = ?";
    $params[] = $status;
}
if ($search) {
    $where[] = "(wt.reference = ? OR u.username LIKE ? OR u.email LIKE ? OR wt.description LIKE ?)";
    $s = "%$search%";
    array_push($params, $search, $s, $s, $s);
}

$whereClause = implode(" AND ", $where);

// Count
$countQuery = "SELECT COUNT(wt.id) as count FROM wallet_transactions wt LEFT JOIN users u ON wt.user_id = u.id WHERE $whereClause";
$total = (int)Database::fetchOne($countQuery, $params)['count'];

// Paginate
$offset = ($page - 1) * 25;
$query = "SELECT wt.*, u.username 
          FROM wallet_transactions wt 
          LEFT JOIN users u ON wt.user_id = u.id 
          WHERE $whereClause 
          ORDER BY wt.created_at DESC 
          LIMIT 25 OFFSET $offset";
$transactions = Database::fetchAll($query, $params);

$lastPage = ceil($total / 25);

include ADMIN_PATH . '/includes/header.php';
?>
<div class="admin-wrapper">
    <?php include ADMIN_PATH . '/includes/sidebar.php'; ?>
    
    <main class="admin-main">
        <div class="admin-topbar">
            <div class="d-flex align-items-center gap-3">
                <button class="admin-sidebar-toggle d-lg-none" id="adminSidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="admin-topbar-title"><?= e($adminPageTitle) ?></h1>
            </div>
            <div class="admin-topbar-right">
                <a href="<?= APP_URL ?>/admin/transactions?action=export&category=<?= e($category) ?>&type=<?= e($type) ?>&status=<?= e($status) ?>&search=<?= e($search) ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-file-export me-1"></i> Export CSV
                </a>
            </div>
        </div>

        <div class="admin-content fade-in-up">
            
            <!-- Filters -->
            <div class="filter-bar">
                <form method="GET" action="<?= APP_URL ?>/admin/transactions" class="d-flex flex-wrap gap-2 w-100 align-items-center">
                    <div class="flex-fill" style="min-width: 200px;">
                        <input type="text" class="form-control" name="search" value="<?= e($search) ?>" placeholder="Search reference, username, description...">
                    </div>
                    <div style="min-width: 140px;">
                        <select class="form-select" name="category">
                            <option value="">All Categories</option>
                            <option value="deposit" <?= $category === 'deposit' ? 'selected' : '' ?>>Deposit</option>
                            <option value="withdrawal" <?= $category === 'withdrawal' ? 'selected' : '' ?>>Withdrawal</option>
                            <option value="transfer" <?= $category === 'transfer' ? 'selected' : '' ?>>Transfer</option>
                            <option value="airtime" <?= $category === 'airtime' ? 'selected' : '' ?>>Airtime</option>
                            <option value="data" <?= $category === 'data' ? 'selected' : '' ?>>Data</option>
                            <option value="cable_tv" <?= $category === 'cable_tv' ? 'selected' : '' ?>>Cable TV</option>
                            <option value="electricity" <?= $category === 'electricity' ? 'selected' : '' ?>>Electricity</option>
                            <option value="betting" <?= $category === 'betting' ? 'selected' : '' ?>>Betting</option>
                            <option value="exam_pin" <?= $category === 'exam_pin' ? 'selected' : '' ?>>Exam PINs</option>
                        </select>
                    </div>
                    <div style="min-width: 120px;">
                        <select class="form-select" name="type">
                            <option value="">All Types</option>
                            <option value="credit" <?= $type === 'credit' ? 'selected' : '' ?>>Credit</option>
                            <option value="debit" <?= $type === 'debit' ? 'selected' : '' ?>>Debit</option>
                        </select>
                    </div>
                    <div style="min-width: 120px;">
                        <select class="form-select" name="status">
                            <option value="">All Statuses</option>
                            <option value="success" <?= $status === 'success' ? 'selected' : '' ?>>Success</option>
                            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option>
                            <option value="reversed" <?= $status === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="background-color: #4f46e5; border: none; height: 38px;">Filter</button>
                    <a href="<?= APP_URL ?>/admin/transactions" class="btn btn-outline-secondary d-flex align-items-center justify-content-center" style="height: 38px;">Reset</a>
                </form>
            </div>

            <!-- Ledger Table -->
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Ref Reference</th>
                            <th>Username</th>
                            <th>Category</th>
                            <th>Amount</th>
                            <th>Fee</th>
                            <th>Status</th>
                            <th>Date / Time</th>
                            <th class="text-end">Reversals</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">No transaction logs match filters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $t): ?>
                                <?php $isCredit = $t['type'] === 'credit'; ?>
                                <tr>
                                    <td class="small font-monospace"><?= e($t['reference']) ?></td>
                                    <td><span class="fw-bold">@<?= e($t['username']) ?></span></td>
                                    <td><span class="badge bg-light text-dark border"><?= ucfirst($t['category']) ?></span></td>
                                    <td class="fw-bold text-<?= $isCredit ? 'success' : 'danger' ?>">
                                        <?= ($isCredit ? '+' : '-') . ' ' . formatMoney($t['amount'], false) ?>
                                    </td>
                                    <td class="small text-muted"><?= formatMoney($t['fee']) ?></td>
                                    <td>
                                        <span class="admin-badge admin-badge-<?= $t['status'] ?>">
                                            <?= ucfirst($t['status']) ?>
                                        </span>
                                    </td>
                                    <td class="small"><?= date('d M Y, h:i A', strtotime($t['created_at'])) ?></td>
                                    <td class="text-end">
                                        <?php if ($t['status'] === 'success'): ?>
                                            <button class="btn btn-sm btn-outline-danger py-1 px-3 btn-reverse-trigger" 
                                                    data-ref="<?= $t['reference'] ?>"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#reverseModal">
                                                Reverse
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($lastPage > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $lastPage; $i++): ?>
                            <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                                <a class="page-link" href="<?= APP_URL ?>/admin/transactions?page=<?= $i ?>&search=<?= e($search) ?>&category=<?= e($category) ?>&type=<?= e($type) ?>&status=<?= e($status) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>

        </div>
    </main>
</div>

<!-- Modal: Reverse Reason -->
<div class="modal fade" id="reverseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-16 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
                <h5 class="modal-title fw-bold">Reverse Transaction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= APP_URL ?>/admin/transactions">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reverse">
                <input type="hidden" id="modal_ref" name="reference" value="">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="reason" class="form-label small">Reversal Reason</label>
                        <textarea class="form-control" id="reason" name="reason" rows="3" placeholder="Provide details. E.g. Double charging / network error correction" required></textarea>
                    </div>
                </div>

                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="background-color: #ef4444; border: none;">Execute Reversal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.btn-reverse-trigger').on('click', function() {
        $('#modal_ref').val($(this).attr('data-ref'));
    });
});
</script>
<?php include ADMIN_PATH . '/includes/footer.php'; ?>
