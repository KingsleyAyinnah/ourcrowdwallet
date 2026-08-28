<?php
/**
 * OURCR ONLINE - Admin Panel User Directory
 * Lists regular users only (excludes admin and superadmin roles).
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'User Directory';

// Get current page
$page = get('page') ? max(1, (int)get('page')) : 1;

// Get filters
$status = get('status') ?: null;
$role   = get('role') ?: null;
$search = get('search') ?: null;

// Handle POST actions: Activate / Suspend / Ban / Delete
if (isPost()) {
    try {
        requireCsrf();
        $action = post('action');
        $userId = (int)post('user_id');

        if ($userId > 0) {
            $targetUser = Ourcr\User::findById($userId);

            // Developer Account Security Safeguard
            if ($targetUser && $targetUser['email'] === 'kingsleyayinnah@gmail.com') {
                setFlash('error', 'This developer account is protected and cannot be modified or deleted.');
                redirectTo('admin/users');
            }

            // Safety: never allow actions on admin / superadmin accounts from this page
            if ($targetUser && in_array($targetUser['role'], ['admin', 'superadmin'], true)) {
                setFlash('error', 'Admin accounts cannot be managed from the User Directory. Use Manage Admins.');
                redirectTo('admin/users');
            }

            if ($targetUser) {
                switch ($action) {
                    case 'suspend':
                        Ourcr\User::suspend($userId, 'Suspended by admin');
                        auditLog('USER_SUSPENDED', "Suspended user ID $userId", 'users', $userId);
                        setFlash('success', 'User account suspended.');
                        break;

                    case 'ban':
                        Ourcr\User::ban($userId, 'Banned by admin');
                        auditLog('USER_BANNED', "Banned user ID $userId", 'users', $userId);
                        setFlash('success', 'User account banned.');
                        break;

                    case 'activate':
                        Ourcr\User::activate($userId);
                        auditLog('USER_ACTIVATED', "Activated user ID $userId", 'users', $userId);
                        setFlash('success', 'User account activated.');
                        break;

                    case 'delete':
                        // Hard-delete: permanently removes the user from the database
                        $name = e($targetUser['first_name'] . ' ' . $targetUser['last_name']);
                        if (Ourcr\User::hardDelete($userId)) {
                            auditLog('USER_DELETED', "Permanently deleted user ID $userId ($name)", 'users', $userId);
                            setFlash('success', "User \"$name\" has been permanently deleted.");
                        } else {
                            setFlash('error', "Could not delete user \"$name\".");
                        }
                        break;
                }
                redirectTo('admin/users');
            } else {
                setFlash('error', 'User not found.');
            }
        }
    } catch (Exception $e) {
        setFlash('error', 'Action failed: ' . $e->getMessage());
    }
}

// Build query — always exclude admin and superadmin roles
$params = [];
$where  = ["deleted_at IS NULL", "role NOT IN ('admin', 'superadmin')"];

if ($status) {
    $where[]  = "status = ?";
    $params[] = $status;
}
if ($role && !in_array($role, ['admin', 'superadmin'], true)) {
    $where[]  = "role = ?";
    $params[] = $role;
}
if ($search) {
    $where[] = "(first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s, $s);
}

$whereClause = implode(' AND ', $where);

// Count total
$total  = (int)Database::fetchOne("SELECT COUNT(id) AS count FROM users WHERE $whereClause", $params)['count'];

// Paginate
$offset    = ($page - 1) * PER_PAGE;
$usersList = Database::fetchAll(
    "SELECT * FROM users WHERE $whereClause ORDER BY created_at DESC LIMIT " . PER_PAGE . " OFFSET $offset",
    $params
);

$lastPage = max(1, ceil($total / PER_PAGE));

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
            <div class="d-flex align-items-center gap-2 ms-auto">
                <span class="badge bg-secondary"><?= number_format($total) ?> user<?= $total !== 1 ? 's' : '' ?></span>
            </div>
        </div>

        <div class="admin-content fade-in-up">

            <?php foreach (getFlash() as $flash): ?>
                <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : e($flash['type']) ?> py-2 px-3 small">
                    <?= e($flash['message']) ?>
                </div>
            <?php endforeach; ?>

            <!-- Filters -->
            <div class="filter-bar">
                <form method="GET" action="<?= APP_URL ?>/admin/users" class="d-flex flex-wrap gap-2 w-100 align-items-center">
                    <div class="flex-fill" style="min-width: 200px;">
                        <input type="text" class="form-control" name="search"
                               value="<?= e($search) ?>"
                               placeholder="Search name, username, email, phone…">
                    </div>
                    <div style="min-width: 140px;">
                        <select class="form-select" name="status">
                            <option value="">All Statuses</option>
                            <option value="active"    <?= $status === 'active'    ? 'selected' : '' ?>>Active</option>
                            <option value="inactive"  <?= $status === 'inactive'  ? 'selected' : '' ?>>Inactive</option>
                            <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                            <option value="banned"    <?= $status === 'banned'    ? 'selected' : '' ?>>Banned</option>
                        </select>
                    </div>
                    <div style="min-width: 140px;">
                        <select class="form-select" name="role">
                            <option value="">All Roles</option>
                            <option value="user"      <?= $role === 'user'      ? 'selected' : '' ?>>User</option>
                            <option value="agent"     <?= $role === 'agent'     ? 'selected' : '' ?>>Agent</option>
                            <option value="reseller"  <?= $role === 'reseller'  ? 'selected' : '' ?>>Reseller</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="background:#4f46e5;border:none;height:38px;">
                        Filter
                    </button>
                    <a href="<?= APP_URL ?>/admin/users"
                       class="btn btn-outline-secondary d-flex align-items-center justify-content-center"
                       style="height:38px;">
                        Reset
                    </a>
                </form>
            </div>

            <!-- Users Table -->
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>User Details</th>
                            <th>Email / Phone</th>
                            <th>Role</th>
                            <th>Wallet Balance</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($usersList)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    No users found matching search criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($usersList as $u): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?= e($u['first_name'] . ' ' . $u['last_name']) ?></div>
                                        <div class="small text-muted">@<?= e($u['username']) ?></div>
                                    </td>
                                    <td>
                                        <div class="small"><?= e($u['email']) ?></div>
                                        <div class="small text-muted"><?= e($u['phone']) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?= ucfirst($u['role']) ?></span>
                                    </td>
                                    <td class="fw-bold"><?= formatMoney($u['wallet_balance']) ?></td>
                                    <td>
                                        <span class="admin-badge admin-badge-<?= $u['status'] === 'active' ? 'success' : ($u['status'] === 'suspended' ? 'warning' : 'failed') ?>">
                                            <?= ucfirst($u['status']) ?>
                                        </span>
                                    </td>
                                    <td class="small"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-1">
                                            <?php if ($u['email'] !== 'kingsleyayinnah@gmail.com'): ?>
                                                <!-- View -->
                                                <a href="<?= APP_URL ?>/admin/user-view?id=<?= $u['id'] ?>"
                                                   class="action-btn action-btn-view" title="View details">
                                                    <i class="fas fa-eye"></i>
                                                </a>

                                                <!-- Suspend / Activate -->
                                                <form method="POST" action="<?= APP_URL ?>/admin/users" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                    <?php if ($u['status'] === 'active'): ?>
                                                        <input type="hidden" name="action" value="suspend">
                                                        <button type="submit" class="action-btn action-btn-edit"
                                                                title="Suspend account"
                                                                onclick="return confirm('Suspend this user account?')">
                                                            <i class="fas fa-ban"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <input type="hidden" name="action" value="activate">
                                                        <button type="submit" class="action-btn action-btn-view"
                                                                title="Activate account"
                                                                style="background:rgba(16,185,129,.1);color:#10b981;"
                                                                onclick="return confirm('Activate this user account?')">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </form>

                                                <!-- Delete (permanent) -->
                                                <form method="POST" action="<?= APP_URL ?>/admin/users" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="user_id"  value="<?= $u['id'] ?>">
                                                    <input type="hidden" name="action"   value="delete">
                                                    <button type="submit"
                                                            class="action-btn action-btn-delete"
                                                            title="Permanently delete user"
                                                            style="background:rgba(220,38,38,.12);color:#dc2626;"
                                                            onclick="return confirm('⚠️ Permanently delete user \"<?= e(addslashes($u['first_name'] . ' ' . $u['last_name'])) ?>\"?\n\nThis CANNOT be undone. All their data will be removed forever.')">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="small text-muted py-1 px-2 border rounded bg-light" style="font-size:11px; font-weight:600;"><i class="fas fa-shield-halved me-1 text-primary"></i>Protected</span>
                                            <?php endif; ?>
                                        </div>
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
                                <a class="page-link"
                                   href="<?= APP_URL ?>/admin/users?page=<?= $i ?>&search=<?= urlencode($search ?? '') ?>&status=<?= urlencode($status ?? '') ?>&role=<?= urlencode($role ?? '') ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>

        </div>
    </main>
</div>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>
