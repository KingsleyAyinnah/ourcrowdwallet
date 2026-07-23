<?php
/**
 * OURCR ONLINE - Admin Panel Custom Reports Dispatcher
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'Custom Reports';
$error = '';

$dateFrom = get('date_from') ?: date('Y-m-01'); // start of month
$dateTo   = get('date_to') ?: date('Y-m-d');
$category = get('category') ?: '';
$status   = get('status') ?: '';

// Query results if requested
$reportData = [];
if (get('action') === 'generate') {
    $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
    $where = ["wt.created_at BETWEEN ? AND ?"];

    if ($category) {
        $where[] = "wt.category = ?";
        $params[] = $category;
    }
    if ($status) {
        $where[] = "wt.status = ?";
        $params[] = $status;
    }

    $whereClause = implode(" AND ", $where);
    $query = "SELECT wt.*, u.username 
              FROM wallet_transactions wt 
              LEFT JOIN users u ON wt.user_id = u.id 
              WHERE $whereClause 
              ORDER BY wt.created_at ASC";
    $reportData = Database::fetchAll($query, $params);
}

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
        </div>

        <div class="admin-content fade-in-up">
            
            <!-- Filters card -->
            <div class="card border-0 shadow-sm rounded-12 p-4 mb-4">
                <h5 class="fw-bold mb-4">Configure Custom Report Parameter Options</h5>
                
                <form method="GET" action="<?= APP_URL ?>/admin/reports" class="row g-3 align-items-end">
                    <input type="hidden" name="action" value="generate">

                    <div class="col-md-3 col-sm-6">
                        <label for="date_from" class="form-label small text-muted">From Date</label>
                        <input type="date" class="form-control" id="date_from" name="date_from" value="<?= e($dateFrom) ?>">
                    </div>

                    <div class="col-md-3 col-sm-6">
                        <label for="date_to" class="form-label small text-muted">To Date</label>
                        <input type="date" class="form-control" id="date_to" name="date_to" value="<?= e($dateTo) ?>">
                    </div>

                    <div class="col-md-2 col-sm-4">
                        <label for="category" class="form-label small text-muted">Category</label>
                        <select class="form-select" id="category" name="category">
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

                    <div class="col-md-2 col-sm-4">
                        <label for="status" class="form-label small text-muted">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="success" <?= $status === 'success' ? 'selected' : '' ?>>Success</option>
                            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option>
                        </select>
                    </div>

                    <div class="col-md-2 col-sm-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100" style="background-color: #4f46e5; border: none; height: 38px;">
                            Compile Report
                        </button>
                    </div>
                </form>
            </div>

            <!-- Report Output Table -->
            <?php if (get('action') === 'generate'): ?>
                <div class="admin-table-wrapper">
                    <div class="p-3 border-bottom bg-white d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0">Compiled Report Results</h5>
                        <!-- Direct link to CSV export matching these exact filters -->
                        <a href="<?= APP_URL ?>/admin/transactions?action=export&category=<?= e($category) ?>&status=<?= e($status) ?>&date_from=<?= e($dateFrom) ?>&date_to=<?= e($dateTo) ?>" class="btn btn-sm btn-success">Export as CSV</a>
                    </div>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Ref</th>
                                <th>Username</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <th>Fee</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">No records match the selected date filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reportData as $row): ?>
                                    <tr>
                                        <td class="small font-monospace"><?= e($row['reference']) ?></td>
                                        <td><span class="fw-bold">@<?= e($row['username']) ?></span></td>
                                        <td><span class="badge bg-light text-dark border"><?= e($row['category']) ?></span></td>
                                        <td class="fw-bold text-<?= $row['type'] === 'credit' ? 'success' : 'danger' ?>"><?= formatMoney($row['amount']) ?></td>
                                        <td class="small text-muted"><?= formatMoney($row['fee']) ?></td>
                                        <td class="small"><?= date('d M Y, H:i', strtotime($row['created_at'])) ?></td>
                                        <td><span class="admin-badge admin-badge-<?= $row['status'] ?>"><?= ucfirst($row['status']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        </div>
    </main>
</div>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>
