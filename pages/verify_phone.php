<?php
/**
 * OURCR ONLINE - Mobile Phone OTP Verification
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Verify Phone Number';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

// Redirect if already verified
if (!empty($user['phone_verified_at'])) {
    setFlash('info', 'Your phone number is already verified.');
    redirectTo('dashboard');
}

$error = '';
$success = '';

// Handle POST: Verify OTP
if (isPost()) {
    try {
        requireCsrf();
        $otp = sanitizeString(post('otp'));

        if (empty($otp)) {
            setFlash('error', "Please enter the OTP sent to your phone.");
            redirectTo('verify-phone');
        } else {
            // Find active OTP in database
            $record = Database::fetchOne(
                "SELECT * FROM phone_verifications WHERE user_id = ? AND otp = ? AND expires_at > NOW() AND used_at IS NULL LIMIT 1",
                [$user['id'], $otp]
            );

            if ($record) {
                // Update verification records
                Database::execute(
                    "UPDATE phone_verifications SET used_at = NOW() WHERE id = ?",
                    [$record['id']]
                );

                Database::execute(
                    "UPDATE users SET phone_verified_at = NOW() WHERE id = ?",
                    [$user['id']]
                );

                auditLog('PHONE_VERIFIED', "User verified their mobile phone number", 'users', $user['id']);

                setFlash('success', 'Mobile phone number verified successfully.');
                redirectTo('dashboard');
            } else {
                setFlash('error', "Invalid or expired OTP code.");
                redirectTo('verify-phone');
            }
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Phone verify exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine()
        ]);
        setFlash('error', 'A technical error occurred.');
        redirectTo('verify-phone');
    }
}

// Generate & send OTP if none exists
try {
    $existing = Database::fetchOne(
        "SELECT id FROM phone_verifications WHERE user_id = ? AND expires_at > NOW() AND used_at IS NULL LIMIT 1",
        [$user['id']]
    );

    if (!$existing) {
        $otp = generateOTP(6);
        $expiry = date('Y-m-d H:i:s', time() + OTP_EXPIRY);
        
        Database::insert(
            "INSERT INTO phone_verifications (user_id, otp, expires_at) VALUES (?, ?, ?)",
            [$user['id'], $otp, $expiry]
        );

        // In production, we'd fire an SMS. Here we log the mock SMS code
        writeLog('system', 'info', "SMS OTP code generated for User ID {$user['id']}: $otp");
    }
} catch (Exception $e) {
    writeLog('error', 'error', 'Failed to generate phone OTP: ' . $e->getMessage());
}

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            <div class="service-page fade-in-up">
                
                <div class="mb-4">
                    <h4 class="fw-bold">Verify Phone Number</h4>
                    <p class="text-muted small">We sent a 6-digit OTP code to your registered mobile number: <strong><?= e(maskPhone($user['phone'])) ?></strong>.</p>
                </div>



                <div class="form-card text-center">
                    <i class="fas fa-sms text-danger fa-3x mb-3"></i>
                    <h5 class="fw-bold mb-3">Enter OTP Code</h5>

                    <form method="POST" action="<?= APP_URL ?>/verify-phone">
                        <?= csrfField() ?>

                        <div class="mb-4">
                            <input type="text" 
                                   class="form-control form-control-lg text-center fw-bold fs-3 tracking-wide" 
                                   id="otp" 
                                   name="otp" 
                                   maxlength="6" 
                                   placeholder="000000" 
                                   style="letter-spacing: 0.25em;"
                                   required>
                            <div class="form-text small mt-2">Check system logs / php logs to retrieve the OTP code.</div>
                        </div>

                        <button type="submit" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0">
                            Verify Number <i class="fas fa-check-circle ms-2"></i>
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>
