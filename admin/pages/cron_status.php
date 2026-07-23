<?php
/**
 * OURCR ONLINE - Admin Panel Cron Status Control
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'Cron Background Jobs';
$error = '';
$success = '';

// Fetch all cron jobs
$jobs = Database::fetchAll("SELECT * FROM cron_jobs ORDER BY name ASC");

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
                <!-- Trigger execution of all crons via AJAX link -->
                <a href="<?= APP_URL ?>/api/cron_runner.php?secret=<?= CRON_SECRET ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-play me-1"></i> Trigger All Jobs
                </a>
            </div>
        </div>

        <div class="admin-content fade-in-up">
            
            <div class="alert alert-info py-3 small border-0 mb-4">
                <h6 class="fw-bold mb-1"><i class="fas fa-info-circle me-2"></i>Cron Background Services</h6>
                <p class="mb-0">These cron scripts automate deposit reconciliation, GAPS transaction matches, and VTpass retries. Make sure your server task scheduler runs the <code>api/cron_runner.php</code> script every minute.</p>
            </div>

            <!-- Table -->
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Job Name</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Run Count</th>
                            <th>Last Run</th>
                            <th>Recent Output</th>
                            <th class="text-end">Manual Run</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($jobs)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">No cron records found in database.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($jobs as $j): ?>
                                <tr>
                                    <td><span class="fw-bold font-monospace"><?= e($j['name']) ?></span></td>
                                    <td><span class="small text-muted"><?= e($j['description'] ?? '—') ?></span></td>
                                    <td>
                                        <span class="admin-badge admin-badge-<?= $j['status'] === 'success' ? 'success' : ($j['status'] === 'running' ? 'info' : 'failed') ?>">
                                            <?= ucfirst($j['status']) ?>
                                        </span>
                                    </td>
                                    <td class="small"><?= (int)$j['run_count'] ?> times</td>
                                    <td class="small"><?= $j['last_run_at'] ? date('d M Y, H:i', strtotime($j['last_run_at'])) : '-' ?></td>
                                    <td class="small text-muted" title="<?= e($j['last_output'] ?? '') ?>">
                                        <?= e(truncate($j['last_output'] ?? '', 30)) ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= APP_URL ?>/api/cron_runner.php?job=<?= e($j['name']) ?>&secret=<?= CRON_SECRET ?>" target="_blank" class="btn btn-sm btn-outline-danger py-0 px-2 rounded">
                                            <i class="fas fa-play"></i> Run
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
