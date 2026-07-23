<?php
/**
 * OURCR ONLINE - User Dashboard Home
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Dashboard';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Get balances
$balance = getWalletBalance($user['id']);
$bonus = (float)($user['bonus_balance'] ?? 0.00);

// Get last 5 transactions
$recentTxns = Database::fetchAll(
    "SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5",
    [$user['id']]
);

// Get stats
$statsCredited = Database::fetchOne(
    "SELECT SUM(amount) as total FROM wallet_transactions WHERE user_id = ? AND type = 'credit' AND status = 'success'",
    [$user['id']]
);
$statsDebited = Database::fetchOne(
    "SELECT SUM(amount) as total FROM wallet_transactions WHERE user_id = ? AND type = 'debit' AND status = 'success'",
    [$user['id']]
);

$totalCredited = (float)($statsCredited['total'] ?? 0.00);
$totalDebited = (float)($statsDebited['total'] ?? 0.00);

// Get announcements
$announcements = getActiveAnnouncements('user');

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            
            <!-- Announcements Banner -->
            <?php if (!empty($announcements)): ?>
                <?php foreach ($announcements as $ann): ?>
                    <div class="alert alert-<?= e($ann['type']) ?> alert-dismissible fade show announcement-bar" role="alert">
                        <strong><i class="fas fa-bullhorn me-2"></i> <?= e($ann['title']) ?>:</strong> <?= e($ann['message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="row fade-in-up">
                <!-- Left column -->
                <div class="col-lg-8">
                    <!-- Wallet Card -->
                    <div class="wallet-card">
                        <div class="wallet-balance-label">Main Wallet Balance</div>
                        <div class="wallet-balance-amount"><?= formatMoney($balance) ?></div>
                        
                        <div class="mb-4 d-flex justify-content-between align-items-center">
                            <div>
                                <span class="small opacity-75">Bonus Earned:</span> 
                                <strong class="ms-1"><?= formatMoney($bonus) ?></strong>
                            </div>
                        </div>

                        <div class="wallet-actions">
                            <a href="<?= APP_URL ?>/fund-wallet" class="wallet-action-btn">
                                <i class="fas fa-plus"></i> Fund Wallet
                            </a>
                            <a href="<?= APP_URL ?>/withdraw" class="wallet-action-btn">
                                <i class="fas fa-arrow-down"></i> Withdraw
                            </a>
                            <a href="<?= APP_URL ?>/transfer" class="wallet-action-btn">
                                <i class="fas fa-paper-plane"></i> Transfer
                            </a>
                        </div>
                    </div>

                    <!-- Services Grid -->
                    <h5 class="mb-3 fw-bold">Services</h5>
                    <div class="services-grid">
                        <a href="<?= APP_URL ?>/buy-airtime" class="service-item">
                            <div class="service-icon text-danger" style="background-color: rgba(220, 38, 38, 0.1);">
                                <i class="fas fa-phone"></i>
                            </div>
                            <span class="service-label">Airtime</span>
                        </a>
                        <a href="<?= APP_URL ?>/buy-data" class="service-item">
                            <div class="service-icon text-primary" style="background-color: rgba(59, 130, 246, 0.1);">
                                <i class="fas fa-wifi"></i>
                            </div>
                            <span class="service-label">Buy Data</span>
                        </a>
                        <a href="<?= APP_URL ?>/cable-tv" class="service-item">
                            <div class="service-icon text-info" style="background-color: rgba(6, 182, 212, 0.1);">
                                <i class="fas fa-tv"></i>
                            </div>
                            <span class="service-label">Cable TV</span>
                        </a>
                        <a href="<?= APP_URL ?>/electricity" class="service-item">
                            <div class="service-icon text-warning" style="background-color: rgba(245, 158, 11, 0.1);">
                                <i class="fas fa-bolt"></i>
                            </div>
                            <span class="service-label">Electricity</span>
                        </a>
                        <a href="<?= APP_URL ?>/betting" class="service-item">
                            <div class="service-icon text-success" style="background-color: rgba(16, 185, 129, 0.1);">
                                <i class="fas fa-futbol"></i>
                            </div>
                            <span class="service-label">Betting</span>
                        </a>
                        <a href="<?= APP_URL ?>/exam-pins" class="service-item">
                            <div class="service-icon text-secondary" style="background-color: rgba(107, 114, 128, 0.1);">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <span class="service-label">Exam Pins</span>
                        </a>
                        <a href="<?= APP_URL ?>/referrals" class="service-item">
                            <div class="service-icon text-danger" style="background-color: rgba(239, 68, 68, 0.1);">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <span class="service-label">Referrals</span>
                        </a>
                        <a href="<?= APP_URL ?>/support" class="service-item">
                            <div class="service-icon text-info" style="background-color: rgba(3, 105, 161, 0.1);">
                                <i class="fas fa-headset"></i>
                            </div>
                            <span class="service-label">Support</span>
                        </a>
                    </div>

                    <!-- Recent Transactions -->
                    <div class="card border-0 shadow-sm rounded-16 mb-4">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="card-title fw-bold mb-0">Recent Transactions</h5>
                                <a href="<?= APP_URL ?>/transactions" class="text-site-color text-decoration-none fw-bold small">View All</a>
                            </div>

                            <?php if (empty($recentTxns)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-receipt fa-3x text-muted mb-2" style="opacity: 0.3;"></i>
                                    <p class="text-muted mb-0">No transactions recorded yet.</p>
                                </div>
                            <?php else: ?>
                                <ul class="txn-list">
                                    <?php foreach ($recentTxns as $txn): ?>
                                        <?php
                                        $isCredit = $txn['type'] === 'credit';
                                        $iconClass = match ($txn['category']) {
                                            'deposit' => 'fas fa-arrow-up',
                                            'withdrawal' => 'fas fa-arrow-down',
                                            'transfer' => 'fas fa-exchange-alt',
                                            'airtime' => 'fas fa-phone',
                                            'data' => 'fas fa-wifi',
                                            'cable_tv' => 'fas fa-tv',
                                            'electricity' => 'fas fa-bolt',
                                            'betting' => 'fas fa-futbol',
                                            'exam_pin' => 'fas fa-graduation-cap',
                                            default => 'fas fa-receipt',
                                        };
                                        $iconBg = $isCredit ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)';
                                        $iconColor = $isCredit ? '#10b981' : '#ef4444';
                                        ?>
                                        <li class="txn-item">
                                            <div class="txn-left">
                                                <div class="txn-icon" style="background-color: <?= $iconBg ?>; color: <?= $iconColor ?>;">
                                                    <i class="<?= $iconClass ?>"></i>
                                                </div>
                                                <div class="txn-info">
                                                    <span class="txn-title"><?= e($txn['description'] ?: ucfirst($txn['category'])) ?></span>
                                                    <span class="txn-date"><?= formatDate($txn['created_at']) ?></span>
                                                </div>
                                            </div>
                                            <div class="txn-right">
                                                <span class="txn-amount <?= $txn['type'] ?>"><?= ($isCredit ? '+' : '-') . ' ' . formatMoney($txn['amount'], false) ?></span>
                                                <span class="txn-status <?= $txn['status'] ?>"><?= $txn['status'] ?></span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right column -->
                <div class="col-lg-4">
                    <!-- Quick Statistics -->
                    <div class="card border-0 shadow-sm rounded-16 mb-4">
                        <div class="card-body p-4">
                            <h5 class="fw-bold mb-4">Quick Statistics</h5>
                            
                            <div class="d-flex flex-column gap-3 mb-4">
                                <div class="stat-card-sm p-3 border rounded-12 d-flex align-items-center gap-3">
                                    <div class="bg-success bg-opacity-10 rounded-pill text-success d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px; font-size: 15px;">
                                        <i class="fas fa-arrow-down"></i>
                                    </div>
                                    <div style="min-width: 0; flex: 1;">
                                        <div class="text-muted" style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .4px;">Total Inflow</div>
                                        <div class="fw-bold text-success text-truncate" style="font-size: 16px; line-height: 1.3;"><?= formatMoney($totalCredited) ?></div>
                                    </div>
                                </div>
                                <div class="stat-card-sm p-3 border rounded-12 d-flex align-items-center gap-3">
                                    <div class="bg-danger bg-opacity-10 rounded-pill text-danger d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px; font-size: 15px;">
                                        <i class="fas fa-arrow-up"></i>
                                    </div>
                                    <div style="min-width: 0; flex: 1;">
                                        <div class="text-muted" style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .4px;">Total Outflow</div>
                                        <div class="fw-bold text-danger text-truncate" style="font-size: 16px; line-height: 1.3;"><?= formatMoney($totalDebited) ?></div>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Promo Banner / Referrals Box -->
                            <?php
                                $refFlatBonus = (float) setting('referral_bonus', 200);
                                $refPct       = (float) setting('referral_bonus_percentage', 0);
                            ?>
                            <div class="p-3 rounded-12 bg-light border text-center">
                                <i class="fas fa-gift text-danger fa-2x mb-2"></i>
                                <h6 class="fw-bold">Invite Friends &amp; Earn</h6>
                                <?php if ($refFlatBonus > 0 && $refPct > 0): ?>
                                    <p class="small text-muted mb-2">Earn <strong><?= formatMoney($refFlatBonus) ?></strong> when a friend joins <span class="text-muted">+</span> <strong><?= $refPct ?>%</strong> ongoing commission on every discount they save.</p>
                                <?php elseif ($refFlatBonus > 0): ?>
                                    <p class="small text-muted mb-2">Earn <strong><?= formatMoney($refFlatBonus) ?></strong> instantly when each friend verifies their email after joining.</p>
                                <?php elseif ($refPct > 0): ?>
                                    <p class="small text-muted mb-2">Earn <strong><?= $refPct ?>%</strong> commission on every discount your referred friends save on each purchase.</p>
                                <?php else: ?>
                                    <p class="small text-muted mb-2">Invite your friends and help them enjoy great services.</p>
                                <?php endif; ?>
                                <a href="<?= APP_URL ?>/referrals" class="btn btn-sm btn-outline-danger w-100 mt-2">Get Referral Link</a>
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
