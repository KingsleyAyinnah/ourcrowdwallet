<?php
/**
 * OURCR ONLINE - Fund Wallet (Deposit Intent)
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Fund Wallet';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Get corporate bank credentials from Admin Manual Bank Transfer settings
$companyBank   = setting('company_bank_name', defined('COMPANY_BANK_NAME') ? COMPANY_BANK_NAME : 'Guaranty Trust Bank (GTBank)');
$companyName   = setting('company_account_name', defined('COMPANY_ACCOUNT_NAME') ? COMPANY_ACCOUNT_NAME : 'OURCR ONLINE');
$companyNumber = setting('company_account_no', setting('gaps_account_number', defined('COMPANY_ACCOUNT_NUMBER') ? COMPANY_ACCOUNT_NUMBER : '0004527849'));

$minDeposit = (float)setting('min_deposit', 100);
$maxDeposit = (float)setting('max_deposit', 5000000);

// Handle POST: Create Deposit Intent
if (isPost()) {
    try {
        requireCsrf();

        $amount     = (float) post('amount');
        $senderName = sanitizeString(post('sender_name'));
        // expected_at: 30 minutes from now (user is about to transfer)
        $expectedAt = date('Y-m-d H:i:s', time() + 1800);

        $result = \Ourcr\Wallet::createDepositIntent(
            userId:     $user['id'],
            amount:     $amount,
            senderName: $senderName,
            expectedAt: $expectedAt
        );

        if ($result['success']) {
            auditLog('DEPOSIT_INTENT_CREATED', "Declared deposit of " . formatMoney($amount) . " by $senderName", 'deposit_intents', $result['intent_id']);
            setFlash('success', 'Deposit intent registered. Please proceed with the bank transfer.');
            redirectTo('wallet');
        } else {
            setFlash('error', $result['error']);
            redirectTo('fund-wallet');
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Fund wallet exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine()
        ]);
        setFlash('error', 'A technical error occurred. Please try again.');
        redirectTo('fund-wallet');
    }
}

// Get user's active declarations
$pendingIntents = \Ourcr\Wallet::getPendingIntents($user['id']);

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            <div class="service-page fade-in-up">
                
                <!-- Title -->
                <div class="mb-4">
                    <h4 class="fw-bold">Fund Wallet</h4>
                    <p class="text-muted small">Pre-declare your transfer details to trigger GAPS auto-reconciliation.</p>
                </div>



                <!-- Bank Info Card -->
                <div class="card border-0 shadow-sm rounded-16 mb-4">
                    <div class="card-body p-4">
                        <span class="badge bg-danger mb-3">Manual Bank Transfer</span>
                        <h5 class="fw-bold mb-3">Our Corporate Bank Account</h5>
                        
                        <div class="bg-light p-3 rounded-12 mb-3">
                            <div class="row mb-2">
                                <div class="col-5 text-muted small">Bank Name:</div>
                                <div class="col-7 fw-bold"><?= e($companyBank) ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-5 text-muted small">Account Name:</div>
                                <div class="col-7 fw-bold"><?= e($companyName) ?></div>
                            </div>
                            <div class="row">
                                <div class="col-5 text-muted small">Account Number:</div>
                                <div class="col-7 fw-bold d-flex align-items-center justify-content-between">
                                    <span><?= e($companyNumber) ?></span>
                                    <button class="btn btn-sm btn-outline-danger py-0 px-2 rounded" data-copy="<?= e($companyNumber) ?>">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-info py-2 px-3 small border-0 mb-0">
                            <i class="fas fa-info-circle me-2"></i><strong>IMPORTANT:</strong> Always declare your transfer intent <em>before</em> sending the funds so the automated GAPS service credits your wallet.
                        </div>
                    </div>
                </div>

                <!-- Intent Form -->
                <div class="form-card">
                    <h5 class="fw-bold mb-4">Declare Transfer Intent</h5>
                    
                    <form method="POST" action="<?= APP_URL ?>/fund-wallet">
                        <?= csrfField() ?>

                        <div class="mb-3">
                            <label for="amount" class="form-label">Transfer Amount (₦)</label>
                            <input type="number" 
                                   class="form-control form-control-lg" 
                                   id="amount" 
                                   name="amount" 
                                   min="<?= $minDeposit ?>" 
                                   max="<?= $maxDeposit ?>" 
                                   placeholder="e.g. 5000" 
                                   required>
                            <div class="form-text small">Min: <?= formatMoney($minDeposit) ?> | Max: <?= formatMoney($maxDeposit) ?></div>
                        </div>

                        <div class="mb-4">
                            <label for="sender_name" class="form-label">Sender Account Name</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="sender_name" 
                                   name="sender_name" 
                                   value="<?= e($user['first_name'] . ' ' . $user['last_name']) ?>" 
                                   placeholder="Enter the sender's bank account name exactly" 
                                   required>
                            <div class="form-text small">Must match the source bank account name exactly to pass GAPS checks.</div>
                        </div>

                        <button type="submit" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0">
                            Submit Intent <i class="fas fa-paper-plane ms-2"></i>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>
