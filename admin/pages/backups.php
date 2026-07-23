<?php
/**
 * OURCR ONLINE - Admin Panel Backups
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'Database Backups';
$error = '';
$success = '';

$backupDir = BASE_PATH . '/logs/backups';
if (!file_exists($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// Handle POST: Create database backup
if (isPost()) {
    try {
        requireCsrf();
        $action = post('action');

        if ($action === 'create_backup') {
            $filename = 'ourcr_backup_' . date('Ymd_His') . '.sql';
            $filePath = $backupDir . '/' . $filename;

            // Simple pure-PHP backup generator since exec(mysqldump) might be restricted
            $tables = [];
            $result = Database::fetchAll("SHOW TABLES");
            foreach ($result as $row) {
                $tables[] = current($row);
            }

            $sqlDump = "-- OURCR ONLINE Database Backup\n";
            $sqlDump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
            $sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($tables as $table) {
                // Table schema
                $createRow = Database::fetchOne("SHOW CREATE TABLE `$table`");
                $sqlDump .= "\n\n" . $createRow['Create Table'] . ";\n\n";

                // Table data
                $rows = Database::fetchAll("SELECT * FROM `$table`");
                foreach ($rows as $row) {
                    $keys = array_keys($row);
                    $values = array_values($row);
                    
                    $escapedValues = array_map(function($val) {
                        if ($val === null) return 'NULL';
                        return Database::quote((string)$val);
                    }, $values);

                    $sqlDump .= "INSERT INTO `$table` (`" . implode("`, `", $keys) . "`) VALUES (" . implode(", ", $escapedValues) . ");\n";
                }
            }
            $sqlDump .= "\n\nSET FOREIGN_KEY_CHECKS=1;\n";

            file_put_contents($filePath, $sqlDump);
            auditLog('DATABASE_BACKUP_CREATED', "Created database backup: $filename", 'backups', 0);
            $success = "Database backup file $filename generated successfully.";
        }
        
        elseif ($action === 'delete_backup') {
            $file = post('filename');
            $filePath = $backupDir . '/' . basename($file);
            if (file_exists($filePath)) {
                unlink($filePath);
                auditLog('DATABASE_BACKUP_DELETED', "Deleted database backup file: $file", 'backups', 0);
                $success = "Backup file deleted.";
            }
        }
        
        redirectTo('admin/backups');

    } catch (Exception $e) {
        $error = "Backup failed: " . $e->getMessage();
    }
}

// Download trigger
$downloadFile = get('download');
if ($downloadFile) {
    $filePath = $backupDir . '/' . basename($downloadFile);
    if (file_exists($filePath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// List backups
$files = glob("$backupDir/*.sql");
rsort($files); // newest first

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
                <form method="POST" action="<?= APP_URL ?>/admin/backups">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_backup">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-database me-1"></i> Generate SQL Backup
                    </button>
                </form>
            </div>
        </div>

        <div class="admin-content fade-in-up">
            
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?= e($success) ?>
                </div>
            <?php endif; ?>

            <!-- Table -->
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Filename</th>
                            <th>File Size</th>
                            <th>Date Generated</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($files)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-5 text-muted">No database backups generated yet. Click the button to generate one.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($files as $f): ?>
                                <?php $base = basename($f); ?>
                                <tr>
                                    <td><span class="fw-bold font-monospace"><?= e($base) ?></span></td>
                                    <td><?= formatBytes(filesize($f)) ?></td>
                                    <td class="small"><?= date('d M Y, h:i A', filemtime($f)) ?></td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-1">
                                            <a href="<?= APP_URL ?>/admin/backups?download=<?= e($base) ?>" class="action-btn action-btn-view" title="Download backup">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            
                                            <form method="POST" action="<?= APP_URL ?>/admin/backups" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete_backup">
                                                <input type="hidden" name="filename" value="<?= e($base) ?>">
                                                <button type="submit" class="action-btn action-btn-delete" onclick="return confirm('Delete SQL backup file?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
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
