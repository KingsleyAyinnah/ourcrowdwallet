<?php
/**
 * OURCR ONLINE - Contribution Funding (Coming Soon)
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Contribution Funding';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            <div class="service-page fade-in-up text-center py-5">
                
                <div class="card border-0 shadow-sm rounded-16 overflow-hidden">
                    <!-- Top accent banner -->
                    <div class="bg-site-color py-3 px-4">
                        <span class="badge bg-white text-danger fw-bold px-3 py-2 rounded-pill fs-6">
                            <i class="fas fa-clock me-1"></i> COMING SOON
                        </span>
                    </div>

                    <div class="card-body p-5">
                        <!-- Icon -->
                        <div class="mb-4">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger bg-opacity-10 p-4" style="width:100px;height:100px;">
                                <i class="fas fa-users text-danger fa-3x animated pulse infinite"></i>
                            </div>
                        </div>

                        <h2 class="fw-bold mb-2">Contribution Funding</h2>
                        <p class="text-muted mb-4 mx-auto" style="max-width:480px;">
                            Our developers are building the Contribution Funding module to support smart collaborative pooling, peer contributions, and group payouts.
                        </p>

                        <!-- Highlight card -->
                        <div class="card border-0 rounded-16 mb-5 mx-auto" style="max-width:400px; background: linear-gradient(135deg,#fff5f5,#fff);">
                            <div class="card-body py-4 px-4">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger bg-opacity-10 p-3" style="width:56px;height:56px;flex-shrink:0;">
                                        <i class="fas fa-coins text-danger fa-lg"></i>
                                    </div>
                                    <div class="text-start">
                                        <div class="text-muted small mb-1">Net Income Per Spot</div>
                                        <div class="fw-bold text-danger fs-4">₦140,000</div>
                                        <div class="text-muted small">Per Contribution Spot Purchased &amp; Activated</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <a href="<?= APP_URL ?>/dashboard" class="btn btn-danger bg-site-color border-0 px-5 py-3 rounded-pill fw-bold">
                            Return to Dashboard <i class="fas fa-arrow-right ms-2"></i>
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>
