<?php
/**
 * OURCR ONLINE - Admin Panel GAPS Statement Logs
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'GAPS Statement Audit';

$page = get('page') ? max(1, (int)get('page')) : 1;

// Get filters
$type    = get('type') ?: null;
$matched = get('matched') !== null && get('matched') !== '' ? (int)get('matched') : null;

// Build query
$params = [];
$where = ["1=1"];

if ($type) {
    $where[] = "gt.transaction_type = ?";
    $params[] = $type;
}
if ($matched !== null) {
    $where[] = "gt.matched = ?";
    $params[] = $matched;
}

$whereClause = implode(" AND ", $where);

// Count
$countQuery = "SELECT COUNT(gt.id) as count FROM gaps_transactions gt WHERE $whereClause";
$total = (int)Database::fetchOne($countQuery, $params)['count'];

// Paginate
$offset = ($page - 1) * 20;
$query = "SELECT gt.*, di.uuid as intent_uuid 
          FROM gaps_transactions gt 
          LEFT JOIN deposit_intents di ON gt.deposit_intent_id = di.id 
          WHERE $whereClause 
          ORDER BY gt.transaction_date DESC 
          LIMIT 20 OFFSET $offset";
$logs = Database::fetchAll($query, $params);

$lastPage = ceil($total / 20);

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
            
            <!-- Filters -->
            <div class="filter-bar">
                <form method="GET" action="<?= APP_URL ?>/admin/gaps-logs" class="d-flex flex-wrap gap-2 w-100 align-items-center">
                    <div style="min-width: 140px;">
                        <select class="form-select" name="type">
                            <option value="">All Types</option>
                            <option value="credit" <?= $type === 'credit' ? 'selected' : '' ?>>Credit</option>
                            <option value="debit" <?= $type === 'debit' ? 'selected' : '' ?>>Debit</option>
                        </select>
                    </div>
                    <div style="min-width: 140px;">
                        <select class="form-select" name="matched">
                            <option value="">Matching Status</option>
                            <option value="1" <?= $matched === 1 ? 'selected' : '' ?>>Matched</option>
                            <option value="0" <?= $matched === 0 ? 'selected' : '' ?>>Unmatched</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="background-color: #4f46e5; border: none; height: 38px;">Filter</button>
                    <a href="<?= APP_URL ?>/admin/gaps-logs" class="btn btn-outline-secondary d-flex align-items-center justify-content-center" style="height: 38px;">Reset</a>
                </form>
            </div>

            <!-- GAPS Table -->
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>GAPS Ref Reference</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Sender Account / Narration</th>
                            <th>Transaction Date</th>
                            <th>Matched</th>
                            <th>Linked Intent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">No GAPS transaction logs found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $l): ?>
                                <tr>
                                    <td class="small font-monospace"><?= e($l['gaps_reference']) ?></td>
                                    <td><span class="badge bg-<?= $l['transaction_type'] === 'credit' ? 'success-subtle text-success' : 'danger-subtle text-danger' ?>"><?= strtoupper($l['transaction_type']) ?></span></td>
                                    <td class="fw-bold"><?= formatMoney($l['amount']) ?></td>
                                    <td>
                                        <div class="small fw-bold"><?= e($l['sender_name']) ?></div>
                                        <div class="small text-muted fs-8"><?= e($l['narration']) ?></div>
                                    </td>
                                    <td class="small"><?= date('d M Y, H:i', strtotime($l['transaction_date'])) ?></td>
                                    <td>
                                        <span class="admin-badge admin-badge-<?= $l['matched'] ? 'success' : 'pending' ?>">
                                            <?= $l['matched'] ? 'Matched' : 'Unmatched' ?>
                                        </span>
                                    </td>
                                    <td class="small text-muted font-monospace"><?= $l['intent_uuid'] ? maskString($l['intent_uuid'], 4, 4) : '-' ?></td>
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
                                <a class="page-link" href="<?= APP_URL ?>/admin/gaps-logs?page=<?= $i ?>&type=<?= e($type) ?>&matched=<?= $matched ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>

        </div>
    </main>
</div>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>
