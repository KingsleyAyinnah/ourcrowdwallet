<?php
/**
 * OURCR ONLINE - User Settings & Security
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Security Settings';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

$error = '';
$success = '';

// Handle POST: Update Settings
if (isPost()) {
    try {
        requireCsrf();
        $action = post('action');

        // 1. Password update
        if ($action === 'change_password') {
            $currentPass = post('current_password');
            $newPass     = post('new_password');
            $confirmPass = post('confirm_password');

            if (empty($currentPass) || empty($newPass) || empty($confirmPass)) {
                setFlash('error', "All password fields are required.");
                redirectTo('settings');
            } elseif ($newPass !== $confirmPass) {
                setFlash('error', "New passwords do not match.");
                redirectTo('settings');
            } elseif (strlen($newPass) < PASSWORD_MIN_LENGTH) {
                setFlash('error', "Password must be at least " . PASSWORD_MIN_LENGTH . " characters.");
                redirectTo('settings');
            } elseif (!verifyPassword($currentPass, $user['password_hash'])) {
                setFlash('error', "Current password is incorrect.");
                redirectTo('settings');
            } else {
                $result = Ourcr\User::updatePassword($user['id'], $newPass);
                if ($result) {
                    setFlash('success', "Password changed successfully.");
                    auditLog('PASSWORD_CHANGED', "User changed their login password", 'users', $user['id']);
                    redirectTo('settings');
                } else {
                    setFlash('error', "Failed to update password.");
                    redirectTo('settings');
                }
            }
        }

        // 2. Transaction PIN update
        elseif ($action === 'change_pin') {
            $password = post('password');
            $pin      = post('transaction_pin');

            if (empty($password) || empty($pin)) {
                setFlash('error', "Password and PIN values are required.");
                redirectTo('settings');
            } elseif (!preg_match('/^\d{4}$/', $pin)) {
                setFlash('error', "Transaction PIN must be exactly 4 digits.");
                redirectTo('settings');
            } elseif (!verifyPassword($password, $user['password_hash'])) {
                setFlash('error', "Incorrect login password verification.");
                redirectTo('settings');
            } else {
                $result = Ourcr\User::updatePin($user['id'], $pin);
                if ($result) {
                    setFlash('success', "Transaction PIN updated successfully.");
                    auditLog('PIN_CHANGED', "User updated transaction security PIN", 'users', $user['id']);
                    redirectTo('settings');
                } else {
                    setFlash('error', "Failed to update PIN.");
                    redirectTo('settings');
                }
            }
        }

    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Settings exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine()
        ]);
        setFlash('error', 'A technical error occurred. Please try again.');
        redirectTo('settings');
    }
}

$hasPin = Ourcr\User::hasSetPin($user['id']);

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            <div class="service-page fade-in-up">
                
                <div class="mb-4">
                    <h4 class="fw-bold">Security Settings</h4>
                    <p class="text-muted small">Manage account passwords, transaction PIN codes, and security options.</p>
                </div>



                <div id="inline-alert-container">
                    <?php renderAlerts(); ?>
                </div>

                <!-- Bootstrap Tabs for settings sections -->
                <ul class="nav nav-pills mb-4 d-flex gap-2" id="settingsTab" role="tablist">
                    <li class="nav-item flex-fill" role="presentation">
                        <button class="nav-link active w-100 fw-bold border" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab">
                            <i class="fas fa-lock me-2"></i>Password
                        </button>
                    </li>
                    <li class="nav-item flex-fill" role="presentation">
                        <button class="nav-link w-100 fw-bold border" id="pin-tab" data-bs-toggle="tab" data-bs-target="#pin" type="button" role="tab">
                            <i class="fas fa-key me-2"></i>Txn PIN
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="settingsTabContent">
                    <!-- Change Password Tab -->
                    <div class="tab-pane fade show active" id="password" role="tabpanel">
                        <div class="form-card">
                            <h5 class="fw-bold mb-4">Update Login Password</h5>
                            
                            <form method="POST" action="<?= APP_URL ?>/settings">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="change_password">

                                <div class="mb-3">
                                    <label for="current_password" class="form-label small">Current Password</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>
                                <div class="mb-3">
                                    <label for="new_password" class="form-label small">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                </div>
                                <div class="mb-4">
                                    <label for="confirm_password" class="form-label small">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>

                                <button type="submit" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0">
                                    Change Password
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Change PIN Tab -->
                    <div class="tab-pane fade" id="pin" role="tabpanel">
                        <div class="form-card">
                            <h5 class="fw-bold mb-3"><?= $hasPin ? 'Update Security PIN' : 'Create Security PIN' ?></h5>
                            <p class="small text-muted mb-4">A 4-digit PIN code is required to authorize all wallet transactions, withdrawals, and transfers.</p>

                            <form method="POST" action="<?= APP_URL ?>/settings" data-has-pin="true" id="pinForm">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="change_pin">
                                <input type="hidden" name="transaction_pin" value="">

                                <div class="mb-3">
                                    <label for="password" class="form-label small">Account Password</label>
                                    <input type="password" class="form-control" id="password" name="password" placeholder="Verify your account password" required>
                                </div>

                                <div class="mb-4 text-center">
                                    <label class="form-label d-block text-center">4-Digit Security PIN</label>
                                    <div class="pin-input-group">
                                        <input type="password" class="pin-digit" maxlength="1" required>
                                        <input type="password" class="pin-digit" maxlength="1" required>
                                        <input type="password" class="pin-digit" maxlength="1" required>
                                        <input type="password" class="pin-digit" maxlength="1" required>
                                    </div>
                                    <span class="small text-muted">Enter a secure 4-digit number. Avoid sequential patterns (e.g. 1234).</span>
                                </div>

                                <button type="submit" class="btn btn-danger w-100 py-3 rounded-12 fw-bold bg-site-color border-0">
                                    Save PIN Code
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>
