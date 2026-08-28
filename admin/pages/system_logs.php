<?php
/**
 * OURCR ONLINE - Admin Panel System Log Viewer
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'System Logs Viewer';

$logDir = BASE_PATH . '/logs';
$rootLogs = glob("$logDir/*.log") ?: [];
$subLogs  = glob("$logDir/*/*.log") ?: [];
$logFiles = array_merge($rootLogs, $subLogs);

$selectedFile = get('file') ?: '';
$logContent = '';

if ($selectedFile) {
    foreach ($logFiles as $lf) {
        $relName = str_replace(BASE_PATH . '/logs/', '', str_replace('\\', '/', $lf));
        if ($selectedFile === $relName || $selectedFile === basename($lf)) {
            if (file_exists($lf)) {
                $lines = file($lf);
                $lastLines = array_slice($lines, -300);
                $logContent = implode('', $lastLines);
                $selectedFile = $relName;
                break;
            }
        }
    }
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
            
            <div class="row g-4">
                <!-- Log files list -->
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm rounded-12 p-3">
                        <h6 class="fw-bold mb-3">Log Channels</h6>
                        <div class="list-group list-group-flush">
                            <?php if (empty($logFiles)): ?>
                                <p class="text-muted small">No log files found in directory.</p>
                            <?php else: ?>
                                <?php foreach ($logFiles as $lf): ?>
                                    <?php $rel = str_replace(BASE_PATH . '/logs/', '', str_replace('\\', '/', $lf)); ?>
                                    <a href="<?= APP_URL ?>/admin/system-logs?file=<?= e($rel) ?>" 
                                       class="list-group-item list-group-item-action border-0 py-2 rounded-6 mb-1 small d-flex align-items-center justify-content-between <?= ($selectedFile === $rel || $selectedFile === basename($lf)) ? 'active bg-primary bg-opacity-10 text-primary fw-bold' : '' ?>">
                                        <span><i class="fas fa-file-text me-2"></i><?= e($rel) ?></span>
                                        <span class="text-muted fs-8"><?= formatBytes(filesize($lf)) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Log Content pre-box -->
                <div class="col-md-9">
                    <div class="card border-0 shadow-sm rounded-12 p-4 h-100">
                        <?php if ($selectedFile): ?>
                            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                                <h6 class="fw-bold mb-0 text-primary"><?= e($selectedFile) ?> (Last 200 lines)</h6>
                                <a href="<?= APP_URL ?>/admin/system-logs?file=<?= e($selectedFile) ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-sync"></i> Refresh</a>
                            </div>
                            
                            <div class="bg-dark p-3 rounded-12 overflow-auto" style="max-height: 500px; font-family: monospace; font-size: 12px; color: #a3e635; min-height: 350px;">
                                <?php if (empty($logContent)): ?>
                                    <span class="text-muted">Log file is currently empty.</span>
                                <?php else: ?>
                                    <?php
                                    $lines = explode("\n", $logContent);
                                    foreach ($lines as $line) {
                                        if (empty(trim($line))) continue;
                                        
                                        $class = 'log-entry-info';
                                        if (stripos($line, 'error') !== false || stripos($line, 'critical') !== false) {
                                            $class = 'text-danger fw-bold';
                                        } elseif (stripos($line, 'warning') !== false) {
                                            $class = 'text-warning';
                                        }
                                        
                                        echo '<div class="' . $class . '">' . htmlspecialchars($line) . '</div>';
                                    }
                                    ?>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-file-code fa-4x mb-3" style="opacity: 0.3;"></i>
                                <h5>Select a Log Channel</h5>
                                <p class="small mb-0">Select a log file from the left sidebar to audit recent runtime logs.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>
