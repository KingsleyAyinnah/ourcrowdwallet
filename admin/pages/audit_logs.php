<?php
/**
 * OURCR ONLINE - Admin Panel Audit Logs Directory
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'Security Audit Logs';

$page = get('page') ? max(1, (int)get('page')) : 1;

// Get filters
$actionFilter = get('action_filter') ?: null;
$search       = get('search') ?: null;

// Build query
$params = [];
$where = ["1=1"];

if ($actionFilter) {
    $where[] = "al.action = ?";
    $params[] = $actionFilter;
}
if ($search) {
    $where[] = "(u.username LIKE ? OR al.description LIKE ? OR al.ip_address LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s);
}

$whereClause = implode(" AND ", $where);

// Count
$countQuery = "SELECT COUNT(al.id) as count FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id WHERE $whereClause";
$total = (int)Database::fetchOne($countQuery, $params)['count'];

// Paginate
$offset = ($page - 1) * 25;
$query = "SELECT al.*, u.username 
          FROM audit_logs al 
          LEFT JOIN users u ON al.user_id = u.id 
          WHERE $whereClause 
          ORDER BY al.created_at DESC 
          LIMIT 25 OFFSET $offset";
$logs = Database::fetchAll($query, $params);

$lastPage = ceil($total / 25);

// Fetch unique actions for filter dropdown
$actionsList = Database::fetchAll("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");

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
                <form method="GET" action="<?= APP_URL ?>/admin/audit-logs" class="d-flex flex-wrap gap-2 w-100 align-items-center">
                    <div class="flex-fill" style="min-width: 200px;">
                        <input type="text" class="form-control" name="search" value="<?= e($search) ?>" placeholder="Search username, description, IP...">
                    </div>
                    <div style="min-width: 180px;">
                        <select class="form-select" name="action_filter">
                            <option value="">All Actions</option>
                            <?php foreach ($actionsList as $act): ?>
                                <option value="<?= e($act['action']) ?>" <?= $actionFilter === $act['action'] ? 'selected' : '' ?>><?= e($act['action']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="background-color: #4f46e5; border: none; height: 38px;">Filter</button>
                    <a href="<?= APP_URL ?>/admin/audit-logs" class="btn btn-outline-secondary d-flex align-items-center justify-content-center" style="height: 38px;">Reset</a>
                </form>
            </div>

            <!-- Table -->
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Action Type</th>
                            <th>Description</th>
                            <th>IP Address</th>
                            <th>Browser Agent</th>
                            <th>Date / Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">No audit logs found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $l): ?>
                                <tr>
                                    <td class="small text-muted font-monospace"><?= $l['id'] ?></td>
                                    <td><span class="fw-bold">@<?= e($l['username'] ?: 'Guest') ?></span></td>
                                    <td><span class="badge bg-light text-dark border font-monospace"><?= e($l['action']) ?></span></td>
                                    <td><span class="small"><?= e($l['description']) ?></span></td>
                                    <td class="small font-monospace"><?= e($l['ip_address']) ?></td>
                                    <td class="small text-muted" style="font-size: 11px;" title="<?= e($l['user_agent']) ?>">
                                        <?= e(truncate($l['user_agent'], 30)) ?>
                                    </td>
                                    <td class="small"><?= date('d M Y, h:i A', strtotime($l['created_at'])) ?></td>
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
                                <a class="page-link" href="<?= APP_URL ?>/admin/audit-logs?page=<?= $i ?>&search=<?= e($search) ?>&action_filter=<?= e($actionFilter) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>

        </div>
    </main>
</div>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>
