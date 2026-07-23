<?php
/**
 * OURCR ONLINE - Admin Panel Dashboard
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'Dashboard Overview';

// Get metrics
$totalUsers = (int)Database::fetchOne("SELECT COUNT(id) as count FROM users WHERE deleted_at IS NULL")['count'];
$activeUsers = (int)Database::fetchOne("SELECT COUNT(id) as count FROM users WHERE status = 'active' AND deleted_at IS NULL")['count'];
$pendingWithdrawals = (int)Database::fetchOne("SELECT COUNT(id) as count FROM withdrawals WHERE status = 'pending'")['count'];
$openTickets = (int)Database::fetchOne("SELECT COUNT(id) as count FROM support_tickets WHERE status = 'open'")['count'];

$todayVolume = (float)Database::fetchOne("SELECT SUM(amount) as sum FROM wallet_transactions WHERE DATE(created_at) = CURDATE() AND status = 'success'")['sum'];
$todayFees = (float)Database::fetchOne("SELECT SUM(fee) as sum FROM wallet_transactions WHERE DATE(created_at) = CURDATE() AND status = 'success'")['sum'];

// Get last 10 transactions
$recentTxns = Database::fetchAll(
    "SELECT wt.*, u.username 
     FROM wallet_transactions wt 
     LEFT JOIN users u ON wt.user_id = u.id 
     ORDER BY wt.created_at DESC LIMIT 10"
);

// Get last 5 registered users
$recentUsers = Database::fetchAll(
    "SELECT id, first_name, last_name, username, email, created_at 
     FROM users WHERE deleted_at IS NULL 
     ORDER BY created_at DESC LIMIT 5"
);

// Fetch weekly transaction volume for chart
$weeklyStats = Database::fetchAll(
    "SELECT DATE(created_at) as date, SUM(amount) as volume, SUM(fee) as fees 
     FROM wallet_transactions 
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status = 'success'
     GROUP BY DATE(created_at)
     ORDER BY DATE(created_at) ASC"
);

$chartLabels = [];
$chartVolume = [];
$chartFees   = [];
foreach ($weeklyStats as $ws) {
    $chartLabels[] = date('d M', strtotime($ws['date']));
    $chartVolume[] = (float)$ws['volume'];
    $chartFees[]   = (float)$ws['fees'];
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
            <div class="admin-topbar-right">
                <div class="admin-user-info">
                    <span class="small fw-semibold text-muted">Console Logged:</span>
                    <div class="admin-avatar">A</div>
                </div>
            </div>
        </div>

        <div class="admin-content fade-in-up">
            
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-left">
                        <span class="stat-label">Total Registered Users</span>
                        <span class="stat-value"><?= $totalUsers ?></span>
                    </div>
                    <div class="stat-icon text-primary bg-primary bg-opacity-10">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-left">
                        <span class="stat-label">Active Users</span>
                        <span class="stat-value text-success"><?= $activeUsers ?></span>
                    </div>
                    <div class="stat-icon text-success bg-success bg-opacity-10">
                        <i class="fas fa-user-check"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-left">
                        <span class="stat-label">Today's Txn Volume</span>
                        <span class="stat-value"><?= formatMoney($todayVolume) ?></span>
                    </div>
                    <div class="stat-icon text-warning bg-warning bg-opacity-10">
                        <i class="fas fa-chart-line"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-left">
                        <span class="stat-label">Today's Net Fee Revenue</span>
                        <span class="stat-value text-indigo" style="color: #4f46e5;"><?= formatMoney($todayFees) ?></span>
                    </div>
                    <div class="stat-icon text-indigo bg-indigo bg-opacity-10" style="color: #4f46e5; background-color: rgba(79, 70, 229, 0.1);">
                        <i class="fas fa-sack-dollar"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-left">
                        <span class="stat-label">Pending Withdrawals</span>
                        <span class="stat-value text-danger"><?= $pendingWithdrawals ?></span>
                    </div>
                    <div class="stat-icon text-danger bg-danger bg-opacity-10">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-left">
                        <span class="stat-label">Open Support Tickets</span>
                        <span class="stat-value text-info"><?= $openTickets ?></span>
                    </div>
                    <div class="stat-icon text-info bg-info bg-opacity-10">
                        <i class="fas fa-headset"></i>
                    </div>
                </div>
            </div>

            <!-- Charts & System Status -->
            <div class="row g-4 mb-4">
                <!-- Transaction chart -->
                <div class="col-lg-8">
                    <div class="chart-card">
                        <div class="chart-card-header">
                            <h5 class="chart-card-title">Weekly Sales Volume (₦)</h5>
                        </div>
                        <div style="position: relative; height: 300px;">
                            <canvas id="weeklyVolumeChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- API Connectivity Status -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm rounded-12 h-100 border-start border-4 border-primary">
                        <div class="card-body p-4">
                            <h5 class="fw-bold mb-4">System Gateways</h5>
                            
                            <div class="mb-3 d-flex justify-content-between align-items-center">
                                <span>GAPS Settlement Engine:</span>
                                <?php if (setting('gaps_enabled', '1') === '1'): ?>
                                    <span class="badge bg-success-subtle text-success py-1 px-3 rounded-pill">ONLINE</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger py-1 px-3 rounded-pill">DISABLED</span>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3 d-flex justify-content-between align-items-center">
                                <span>VTpass Operator Hub:</span>
                                <?php if (setting('vtpass_enabled', '1') === '1'): ?>
                                    <span class="badge bg-success-subtle text-success py-1 px-3 rounded-pill">ONLINE</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger py-1 px-3 rounded-pill">DISABLED</span>
                                <?php endif; ?>
                            </div>

                            <hr class="my-4">

                            <!-- Quick administration tool links -->
                            <h6 class="fw-bold mb-3">Gateway Control Logs</h6>
                            <div class="d-flex flex-column gap-2">
                                <a href="<?= APP_URL ?>/admin/gaps-logs" class="btn btn-sm btn-outline-primary text-start"><i class="fas fa-arrow-right-to-bracket me-2"></i> GAPS Deposit Logs</a>
                                <a href="<?= APP_URL ?>/admin/vtpass-logs" class="btn btn-sm btn-outline-primary text-start"><i class="fas fa-wifi me-2"></i> VTpass Operator Logs</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Tables -->
            <div class="row g-4">
                <!-- Recent Transactions -->
                <div class="col-lg-8">
                    <div class="admin-table-wrapper">
                        <div class="p-3 border-bottom bg-white d-flex justify-content-between align-items-center">
                            <h5 class="fw-bold mb-0">Recent General Ledger Activities</h5>
                            <a href="<?= APP_URL ?>/admin/transactions" class="btn btn-sm btn-outline-primary">View All</a>
                        </div>
                        <div class="table-responsive">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Ref</th>
                                        <th>User</th>
                                        <th>Category</th>
                                        <th>Amount</th>
                                        <th>Fee</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentTxns)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">No transactions logged yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentTxns as $txn): ?>
                                            <tr>
                                                <td class="small font-monospace"><?= e(maskString($txn['reference'], 6, 6)) ?></td>
                                                <td><span class="fw-bold">@<?= e($txn['username']) ?></span></td>
                                                <td><span class="badge bg-light text-dark"><?= ucfirst($txn['category']) ?></span></td>
                                                <td class="fw-bold text-<?= $txn['type'] === 'credit' ? 'success' : 'danger' ?>">
                                                    <?= ($txn['type'] === 'credit' ? '+' : '-') . ' ' . formatMoney($txn['amount'], false) ?>
                                                </td>
                                                <td class="small text-muted"><?= formatMoney($txn['fee']) ?></td>
                                                <td><span class="admin-badge admin-badge-<?= $txn['status'] ?>"><?= ucfirst($txn['status']) ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Registrations -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm rounded-12 mb-4">
                        <div class="card-body p-4">
                            <h5 class="fw-bold mb-3">Newly Joined Customers</h5>
                            
                            <?php if (empty($recentUsers)): ?>
                                <p class="text-muted text-center py-4">No users found.</p>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recentUsers as $ru): ?>
                                        <div class="list-group-item px-0 py-3 border-bottom d-flex align-items-center justify-content-between">
                                            <div>
                                                <div class="small fw-bold text-dark"><?= e($ru['first_name'] . ' ' . $ru['last_name']) ?></div>
                                                <div class="text-muted fs-8">@<?= e($ru['username']) ?></div>
                                            </div>
                                            <a href="<?= APP_URL ?>/admin/user-view?id=<?= $ru['id'] ?>" class="btn btn-sm btn-light border py-1 px-2 rounded">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>

<!-- Chart initialization scripts -->
<script>
$(document).ready(function() {
    const ctx = document.getElementById('weeklyVolumeChart')?.getContext('2d');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [
                    {
                        label: 'Sales Volume (₦)',
                        data: <?= json_encode($chartVolume) ?>,
                        borderColor: '#4f46e5',
                        backgroundColor: 'rgba(79, 70, 229, 0.05)',
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: 'Fee Revenues (₦)',
                        data: <?= json_encode($chartFees) ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.05)',
                        tension: 0.3,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    }
});
</script>
