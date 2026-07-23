<?php
/**
 * OURCR ONLINE - User In-App Notifications List
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Notifications';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Mark all as read when user visits this page
markNotificationsRead($user['id']);

// Get notifications
$notifications = Ourcr\Notification::getForUser($user['id'], false, 30);

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            <div class="service-page fade-in-up">
                
                <div class="mb-4">
                    <h4 class="fw-bold">My Notifications</h4>
                    <p class="text-muted small">Stay updated on account matches, payouts, security events, and bonuses.</p>
                </div>

                <div class="card border-0 shadow-sm rounded-16">
                    <div class="card-body p-4">
                        <?php if (empty($notifications)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-bell-slash fa-4x text-muted mb-3" style="opacity: 0.3;"></i>
                                <h6 class="fw-bold text-muted">No notifications found.</h6>
                                <p class="small text-muted mb-0">We will notify you here when transactions take place.</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($notifications as $n): ?>
                                    <?php
                                    $icon = match($n['type']) {
                                        'success' => 'fa-check-circle text-success',
                                        'warning' => 'fa-exclamation-triangle text-warning',
                                        'error'   => 'fa-times-circle text-danger',
                                        default   => 'fa-info-circle text-primary'
                                    };
                                    ?>
                                    <div class="list-group-item py-3 px-0 border-bottom">
                                        <div class="d-flex align-items-start gap-3">
                                            <div class="fs-4 mt-1"><i class="fas <?= $icon ?>"></i></div>
                                            <div class="flex-fill">
                                                <h6 class="fw-bold mb-1"><?= e($n['title']) ?></h6>
                                                <p class="text-muted small mb-1"><?= e($n['message']) ?></p>
                                                <span class="text-muted fs-8"><?= formatDate($n['created_at']) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>
