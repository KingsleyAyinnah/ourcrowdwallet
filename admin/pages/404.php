<?php
/**
 * OURCR ONLINE - Admin Panel 404 Error page
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = '404 Page Not Found';
include ADMIN_PATH . '/includes/header.php';
?>
<div class="admin-wrapper">
    <?php include ADMIN_PATH . '/includes/sidebar.php'; ?>
    
    <main class="admin-main">
        <div class="admin-topbar">
            <h1 class="admin-topbar-title"><?= e($adminPageTitle) ?></h1>
        </div>

        <div class="admin-content fade-in-up text-center py-5">
            <div class="card border-0 shadow-sm rounded-12 py-5" style="max-width: 500px; margin: 0 auto;">
                <div class="card-body py-5 text-muted">
                    <i class="fas fa-exclamation-triangle text-danger fa-4x mb-4"></i>
                    <h2 class="fw-bold text-dark">404 ERROR</h2>
                    <h5 class="mb-3">Administrative Panel Route Not Found</h5>
                    <p class="small mb-4">The command node page you are attempting to access does not exist or has been relocated.</p>
                    
                    <a href="<?= APP_URL ?>/admin/dashboard" class="btn btn-primary px-5 py-3 rounded-pill fw-bold" style="background-color: #4f46e5; border: none;">
                        <i class="fas fa-home me-2"></i> Go to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </main>
</div>
<?php include ADMIN_PATH . '/includes/footer.php'; ?>
