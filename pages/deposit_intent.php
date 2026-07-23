<?php
/**
 * OURCR ONLINE - Deposit Intent Detail
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$intentId = get('intent_id');
if (empty($intentId)) {
    redirectTo('wallet');
}

$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Get intent by UUID
$intent = Database::fetchOne(
    "SELECT * FROM deposit_intents WHERE uuid = ? AND user_id = ? LIMIT 1",
    [$intentId, $user['id']]
);

if (!$intent) {
    setFlash('error', 'Deposit intent not found.');
    redirectTo('wallet');
}

// Get corporate bank credentials
$companyBank   = setting('company_bank_name', 'Accelerex Bank');
$companyName   = setting('company_account_name', 'OURCR ONLINE');
$companyNumber = setting('company_account_no', '0000000000');

$pageTitle = 'Deposit Declaration Details';
include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            <div class="service-page fade-in-up">
                
                <div class="mb-4">
                    <h4 class="fw-bold">Declaration Details</h4>
                    <a href="<?= APP_URL ?>/wallet" class="text-site-color text-decoration-none small"><i class="fas fa-arrow-left me-1"></i> Back to Wallet</a>
                </div>

                <!-- Intent Info Card -->
                <div class="card border-0 shadow-sm rounded-16 mb-4">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0">Status Summary</h5>
                            <span class="badge badge-<?= $intent['status'] === 'matched' ? 'success' : ($intent['status'] === 'pending' ? 'pending' : 'failed') ?> py-2 px-3 rounded-pill fs-7">
                                <?= ucfirst($intent['status']) ?>
                            </span>
                        </div>

                        <div class="bg-light p-3 rounded-12 mb-3">
                            <div class="row mb-2">
                                <div class="col-5 text-muted small">Declaration ID:</div>
                                <div class="col-7 small font-monospace"><?= e(maskString($intent['uuid'], 8, 8)) ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 text-muted small">Amount Declared:</div>
                                <div class="col-7 fw-bold"><?= formatMoney($intent['amount']) ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 text-muted small">Sender Registered:</div>
                                <div class="col-7"><?= e($intent['sender_name']) ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 text-muted small">Expected Date:</div>
                                <div class="col-7 small"><?= date('d M Y, h:i A', strtotime($intent['expected_at'])) ?></div>
                            </div>
                            <div class="row">
                                <div class="col-5 text-muted small">Expiration:</div>
                                <div class="col-7 small text-danger"><?= date('d M Y, h:i A', strtotime($intent['expires_at'])) ?></div>
                            </div>
                        </div>

                        <?php if ($intent['status'] === 'pending'): ?>
                            <div class="alert alert-warning py-3 small border-0 mb-0">
                                <h6 class="fw-bold mb-1"><i class="fas fa-spinner fa-spin me-2"></i>Waiting for Bank Statement matching...</h6>
                                <p class="mb-0">Please transfer exactly <strong><?= formatMoney($intent['amount']) ?></strong> to the account below. Our automatic matching cron checker polls the GAPS statement API every minute.</p>
                            </div>
                        <?php elseif ($intent['status'] === 'matched'): ?>
                            <div class="alert alert-success py-3 small border-0 mb-0">
                                <h6 class="fw-bold mb-1"><i class="fas fa-check-circle me-2"></i>Matched Successfully!</h6>
                                <p class="mb-0">Matched against GAPS bank transaction reference <strong><?= e($intent['gaps_reference']) ?></strong> at <?= date('d M Y, h:i A', strtotime($intent['matched_at'])) ?>. Wallet credited.</p>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-secondary py-3 small border-0 mb-0">
                                <h6 class="fw-bold mb-1"><i class="fas fa-info-circle me-2"></i>Declaration Expired</h6>
                                <p class="mb-0">This declaration has expired. If you made the transfer but weren't credited, please file a support log referencing your transfer receipt.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Show Bank details if pending -->
                <?php if ($intent['status'] === 'pending'): ?>
                    <div class="card border-0 shadow-sm rounded-16 mb-4">
                        <div class="card-body p-4">
                            <h5 class="fw-bold mb-3">Destination Account</h5>
                            <div class="border p-3 rounded-12 bg-white">
                                <div class="row mb-2">
                                    <div class="col-5 text-muted small">Bank:</div>
                                    <div class="col-7 fw-bold"><?= e($companyBank) ?></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-5 text-muted small">Account Name:</div>
                                    <div class="col-7 fw-bold"><?= e($companyName) ?></div>
                                </div>
                                <div class="row">
                                    <div class="col-5 text-muted small">Account No:</div>
                                    <div class="col-7 fw-bold d-flex align-items-center justify-content-between">
                                        <span><?= e($companyNumber) ?></span>
                                        <button class="btn btn-sm btn-outline-danger py-0 px-2 rounded" data-copy="<?= e($companyNumber) ?>">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>
