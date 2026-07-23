<?php
/**
 * OURCR ONLINE - Referral Statistics & Management
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Referral Program';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Get referral link
$refLink = APP_URL . '/register?ref=' . e($user['referral_code']);

// Get referral metrics
$stats = Ourcr\Referral::getStats($user['id']);

// Get referrers lists
$referrals = Ourcr\Referral::getForReferrer($user['id']);

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            <div class="fade-in-up">
                
                <div class="mb-4">
                    <h4 class="fw-bold">Referral Program</h4>
                    <p class="text-muted small">Invite friends, earn a signup bonus when they verify their email, and keep earning a commission on every discount they save on service purchases.</p>
                </div>

                <!-- Metrics Grid -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm rounded-16 p-3 text-center">
                            <div class="text-muted small mb-1">Total Referrals</div>
                            <div class="h3 fw-bold text-dark mb-0"><?= (int)($stats['total'] ?? 0) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm rounded-16 p-3 text-center">
                            <div class="text-muted small mb-1">Active (Email Verified)</div>
                            <div class="h3 fw-bold text-success mb-0"><?= (int)($stats['paid'] ?? 0) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm rounded-16 p-3 text-center">
                            <div class="text-muted small mb-1">Total Earnings</div>
                            <div class="h3 fw-bold text-site-color mb-0"><?= formatMoney((float)($stats['total_earnings'] ?? 0.00)) ?></div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Referral link box -->
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm rounded-16 mb-4">
                            <div class="card-body p-4 text-center">
                                <i class="fas fa-gift fa-3x text-danger mb-3"></i>
                                <h5 class="fw-bold">Invite Friends & Earn</h5>
                                <?php
                                    $flatBonus = (float) setting('referral_bonus', 200);
                                    $discountPct = (float) setting('referral_bonus_percentage', 0);
                                ?>
                                <div class="d-flex flex-column gap-2 mb-4 text-start">
                                    <?php if ($flatBonus > 0): ?>
                                    <div class="d-flex align-items-start gap-2 p-3 rounded-12 bg-light">
                                        <i class="fas fa-check-circle text-success mt-1"></i>
                                        <div class="small">
                                            <strong>Signup Bonus:</strong> Earn <strong><?= formatMoney($flatBonus) ?></strong> instantly added to your wallet when your referred friend verifies their email.
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($discountPct > 0): ?>
                                    <div class="d-flex align-items-start gap-2 p-3 rounded-12 bg-light">
                                        <i class="fas fa-check-circle text-success mt-1"></i>
                                        <div class="small">
                                            <strong>Ongoing Commission:</strong> Earn <strong><?= $discountPct ?>%</strong> of every discount your referred friend saves on each service purchase — credited to your wallet automatically.
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($flatBonus <= 0 && $discountPct <= 0): ?>
                                    <div class="small text-muted text-center">Refer friends and help them join our platform.</div>
                                    <?php endif; ?>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small text-muted">Your Invite Link</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control bg-light font-monospace small" value="<?= $refLink ?>" readonly>
                                        <button class="btn btn-danger bg-site-color border-0" data-copy="<?= $refLink ?>">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Downline List -->
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm rounded-16 mb-4">
                            <div class="card-body p-4">
                                <h5 class="fw-bold mb-3">Invited Members</h5>
                                
                                <?php if (empty($referrals)): ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="fas fa-user-friends fa-3x mb-2" style="opacity: 0.3;"></i>
                                        <p class="small mb-0">No one registered under your code yet.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive" style="max-height: 280px; overflow-y: auto;">
                                        <table class="table align-middle">
                                            <thead>
                                                <tr class="text-muted small">
                                                    <th>Member</th>
                                                    <th>Date Joined</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($referrals as $ref): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="small fw-bold"><?= e($ref['referred_name']) ?></div>
                                                            <div class="small text-muted"><?= e($ref['referred_email']) ?></div>
                                                        </td>
                                                        <td class="small"><?= formatDate($ref['created_at']) ?></td>
                                                        <td>
                                                            <?php if ($ref['status'] === 'paid'): ?>
                                                                <span class="badge bg-success-subtle text-success">Paid</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning-subtle text-warning">Pending</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>
