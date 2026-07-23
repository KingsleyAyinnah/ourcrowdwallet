<?php
/**
 * OURCR ONLINE - Admin Panel User Wallets
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'Wallet Balances';

$search = get('search') ?: null;

// Build query
$params = [];
$where = ["deleted_at IS NULL"];
if ($search) {
    $where[] = "(username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s);
}
$whereClause = implode(" AND ", $where);

// Fetch users sorted by balance desc
$query = "SELECT id, username, first_name, last_name, email, wallet_balance, bonus_balance 
          FROM users 
          WHERE $whereClause 
          ORDER BY wallet_balance DESC";

$wallets = Database::fetchAll($query, $params);

// Calculate totals
$totalMainBalance = (float)Database::fetchOne("SELECT SUM(wallet_balance) as total FROM users WHERE deleted_at IS NULL")['total'];
$totalBonusBalance = (float)Database::fetchOne("SELECT SUM(bonus_balance) as total FROM users WHERE deleted_at IS NULL")['total'];

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
            
            <!-- Overall Totals Grid -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-12 p-3 text-center border-start border-4 border-primary">
                        <span class="small text-muted mb-1 d-block">Total Main Wallets Held</span>
                        <span class="h3 fw-bold text-primary mb-0"><?= formatMoney($totalMainBalance) ?></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-12 p-3 text-center border-start border-4 border-success">
                        <span class="small text-muted mb-1 d-block">Total Referral Bonuses Held</span>
                        <span class="h3 fw-bold text-success mb-0"><?= formatMoney($totalBonusBalance) ?></span>
                    </div>
                </div>
            </div>

            <!-- Search -->
            <div class="filter-bar">
                <form method="GET" action="<?= APP_URL ?>/admin/wallets" class="d-flex gap-2 w-100 align-items-center">
                    <div class="flex-fill">
                        <input type="text" class="form-control" name="search" value="<?= e($search) ?>" placeholder="Search user wallets by username, email, full name...">
                    </div>
                    <button type="submit" class="btn btn-primary" style="background-color: #4f46e5; border: none; height: 38px;">
                        Search
                    </button>
                    <a href="<?= APP_URL ?>/admin/wallets" class="btn btn-outline-secondary d-flex align-items-center justify-content-center" style="height: 38px;">
                        Reset
                    </a>
                </form>
            </div>

            <!-- Wallets Table -->
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>User Profile</th>
                            <th>Email Address</th>
                            <th>Main Wallet Balance</th>
                            <th>Bonus Earned</th>
                            <th class="text-end">Wallet Control</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($wallets)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">No user wallets match search criteria.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($wallets as $w): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?= e($w['first_name'] . ' ' . $w['last_name']) ?></div>
                                        <div class="small text-muted">@<?= e($w['username']) ?></div>
                                    </td>
                                    <td><span class="small"><?= e($w['email']) ?></span></td>
                                    <td class="fw-bold text-success"><?= formatMoney($w['wallet_balance']) ?></td>
                                    <td class="fw-bold text-dark"><?= formatMoney($w['bonus_balance']) ?></td>
                                    <td class="text-end">
                                        <a href="<?= APP_URL ?>/admin/user-view?id=<?= $w['id'] ?>" class="btn btn-sm btn-outline-primary py-1 px-3 rounded">
                                            <i class="fas fa-edit me-1"></i> Adjust Balance
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </main>
</div>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>
