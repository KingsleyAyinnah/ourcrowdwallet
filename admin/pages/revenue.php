<?php
/**
 * OURCR ONLINE - Admin Panel Revenue Analysis
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'Revenue & Volume Reports';

// Totals
$totals = Database::fetchOne(
    "SELECT SUM(amount) as volume, SUM(fee) as fees 
     FROM wallet_transactions 
     WHERE status = 'success'"
);

$totalVolume = (float)($totals['volume'] ?? 0.00);
$totalFees   = (float)($totals['fees'] ?? 0.00);

// Grouped by Category
$categoryRevenue = Database::fetchAll(
    "SELECT category, SUM(amount) as volume, SUM(fee) as fees, COUNT(id) as count 
     FROM wallet_transactions 
     WHERE status = 'success' 
     GROUP BY category 
     ORDER BY fees DESC"
);

// Chart labels & values
$chartLabels = [];
$chartRevenueData = [];
$chartVolumeData = [];

foreach ($categoryRevenue as $cr) {
    $chartLabels[] = ucfirst(str_replace('_', ' ', $cr['category']));
    $chartRevenueData[] = (float)$cr['fees'];
    $chartVolumeData[] = (float)$cr['volume'];
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
            
            <!-- Overall totals row -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-12 p-3 text-center border-start border-4 border-primary">
                        <span class="small text-muted mb-1 d-block">Lifetime Sales Volume</span>
                        <span class="h3 fw-bold text-primary mb-0"><?= formatMoney($totalVolume) ?></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-12 p-3 text-center border-start border-4 border-success">
                        <span class="small text-muted mb-1 d-block">Lifetime Net Fee Revenue</span>
                        <span class="h3 fw-bold text-success mb-0"><?= formatMoney($totalFees) ?></span>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <!-- Breakdown Table -->
                <div class="col-lg-6">
                    <div class="admin-table-wrapper">
                        <div class="p-3 border-bottom bg-white">
                            <h5 class="fw-bold mb-0">Revenues by Service category</h5>
                        </div>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Txns Count</th>
                                    <th>Sales Volume</th>
                                    <th>Fee Earnings</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($categoryRevenue)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-muted">No revenue records found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($categoryRevenue as $cr): ?>
                                        <tr>
                                            <td><span class="fw-bold"><?= ucfirst(str_replace('_', ' ', $cr['category'])) ?></span></td>
                                            <td class="small"><?= (int)$cr['count'] ?> transactions</td>
                                            <td class="small"><?= formatMoney($cr['volume']) ?></td>
                                            <td class="fw-bold text-success"><?= formatMoney($cr['fees']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Chart displays -->
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-12 p-4">
                        <h5 class="fw-bold mb-4">Revenues Distribution (₦)</h5>
                        <div style="position: relative; height: 300px;">
                            <canvas id="revenuePieChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>

<script>
$(document).ready(function() {
    const ctx = document.getElementById('revenuePieChart')?.getContext('2d');
    if (ctx) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                    data: <?= json_encode($chartRevenueData) ?>,
                    backgroundColor: [
                        '#4f46e5', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#6b7280', '#ec4899', '#8b5cf6'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'bottom' }
                }
            }
        });
    }
});
</script>
