<?php
/**
 * OURCR ONLINE - Admin Panel Settings Management
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminUser = currentUser();
$adminPageTitle = 'Platform Settings';
$error = '';
$success = '';

// Auto-run DB seeds on Settings page load to ensure new parameters/defaults exist
try {
    $seedFile = DATABASE_PATH . '/002_seed_defaults.sql';
    if (file_exists($seedFile)) {
        $sql = file_get_contents($seedFile);
        // Remove multi-line comments
        $sql = preg_replace('!/\*.*?\*/!s', '', $sql);
        // Remove single-line comments starting with -- or #
        $lines = explode("\n", $sql);
        $cleanSql = '';
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && strpos($trimmed, '--') !== 0 && strpos($trimmed, '#') !== 0) {
                $cleanSql .= $line . "\n";
            }
        }
        
        // Split by semicolon followed by newline/whitespace
        $queries = preg_split("/;[ \t]*\r?\n/", $cleanSql);
        
        Database::beginTransaction();
        foreach ($queries as $q) {
            $q = trim($q);
            if ($q !== '' && strncasecmp($q, 'USE ', 4) !== 0) {
                Database::execute($q);
            }
        }
        Database::commit();
    }
} catch (\Throwable $e) {
    if (Database::inTransaction()) {
        Database::rollback();
    }
    error_log("Settings auto-seed warning: " . $e->getMessage());
}

// Fetch all settings grouped from site_settings table
$settingsList = Database::fetchAll("SELECT * FROM site_settings ORDER BY setting_key ASC");
$settings = [];
foreach ($settingsList as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}

// Handle POST: Update settings
if (isPost()) {
    try {
        requireCsrf();

        // Handle clear rate limits request
        if (isset($_POST['action']) && $_POST['action'] === 'clear_rate_limits') {
            requireCsrf();
            Database::execute("DELETE FROM rate_limits");
            auditLog('ADMIN_RATE_LIMITS_CLEARED', "Cleared all platform rate limit security locks", 'settings', $adminUser['id']);
            $success = "All security rate limit locks have been cleared successfully.";
        } elseif (isset($_POST['action']) && $_POST['action'] === 'test_email') {
            requireCsrf();
            
            // Clean any output buffer to prevent warnings/notices from corrupting the JSON response
            if (ob_get_level()) {
                ob_clean();
            }
            header('Content-Type: application/json');
            
            // Use POST values if provided (so they can test before saving), fallback to DB settings
            $mailHost = !empty($_POST['mail_host']) ? sanitizeString($_POST['mail_host']) : ($settings['mail_host'] ?? '');
            $mailPort = !empty($_POST['mail_port']) ? (int)$_POST['mail_port'] : ($settings['mail_port'] ?? 587);
            $mailUsername = isset($_POST['mail_username']) ? $_POST['mail_username'] : ($settings['mail_username'] ?? '');
            $mailPassword = isset($_POST['mail_password']) ? $_POST['mail_password'] : ($settings['mail_password'] ?? '');
            $mailEncryption = !empty($_POST['mail_encryption']) ? sanitizeString($_POST['mail_encryption']) : ($settings['mail_encryption'] ?? 'tls');
            $mailFrom = !empty($_POST['mail_from_address']) ? sanitizeEmail($_POST['mail_from_address']) : ($settings['mail_from_address'] ?? $adminUser['email']);
            $mailFromName = !empty($_POST['mail_from_name']) ? sanitizeString($_POST['mail_from_name']) : ($settings['mail_from_name'] ?? APP_NAME);
            $testEmail = sanitizeEmail($_POST['test_email'] ?? $adminUser['email']);
            
            if (empty($mailHost) || empty($mailUsername) || empty($mailPassword)) {
                echo json_encode(['success' => false, 'message' => 'Please configure SMTP settings first.']);
                exit;
            }
            
            if (empty($testEmail) || !isValidEmail($testEmail)) {
                echo json_encode(['success' => false, 'message' => 'Please enter a valid test email address.']);
                exit;
            }
            
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $mailHost;
                $mail->Port = $mailPort;
                $mail->SMTPAuth = true;
                $mail->Username = $mailUsername;
                $mail->Password = $mailPassword;
                $mail->SMTPSecure = $mailEncryption === 'none' ? '' : $mailEncryption;
                // Add conservative SMTP timeouts and options to avoid long blocking
                $mail->SMTPDebug = 0;
                $mail->Timeout = 5; // 5 seconds connection timeout
                $mail->SMTPAutoTLS = ($mailEncryption === 'tls');
                $mail->SMTPKeepAlive = false;
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];
                $mail->setFrom($mailFrom, $mailFromName);
                $mail->addAddress($testEmail);
                $mail->Subject = 'SMTP Test Email - ' . APP_NAME;
                $mail->Body = "This is a test email to verify your SMTP settings are working correctly.\n\nIf you received this email, your SMTP configuration is valid.\n\nTime: " . date('Y-m-d H:i:s');
                $mail->send();
                
                echo json_encode(['success' => true, 'message' => 'Test email sent successfully to ' . $testEmail]);
                exit;
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Failed to send test email: ' . $e->getMessage()]);
                exit;
            }
        } else {
            $settingsToSave = $_POST;
            unset($settingsToSave['csrf_token']); // remove token

            // Handle Admin Account settings if provided
            if (isset($settingsToSave['admin_first_name'])) {
                $firstName = sanitizeString($settingsToSave['admin_first_name']);
                $lastName  = sanitizeString($settingsToSave['admin_last_name']);
                $email     = sanitizeString($settingsToSave['admin_email']);
                $password  = $settingsToSave['admin_password'];

                unset($settingsToSave['admin_first_name']);
                unset($settingsToSave['admin_last_name']);
                unset($settingsToSave['admin_email']);
                unset($settingsToSave['admin_password']);

                if (empty($firstName) || empty($lastName) || empty($email)) {
                    throw new Exception("All admin account fields are required.");
                }

                Database::execute(
                    "UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE id = ?",
                    [$firstName, $lastName, $email, $adminUser['id']]
                );

                if (!empty($password)) {
                    if (strlen($password) < 6) {
                        throw new Exception("Password must be at least 6 characters.");
                    }
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    Database::execute(
                        "UPDATE users SET password = ? WHERE id = ?",
                        [$hashed, $adminUser['id']]
                    );
                }
            }

            // Normalise checkbox-style settings: if missing from POST, they were unchecked → save as '0'
            $checkboxSettings = ['email_notifications_enabled'];
            foreach ($checkboxSettings as $cbKey) {
                if (!array_key_exists($cbKey, $settingsToSave)) {
                    $settingsToSave[$cbKey] = '0';
                }
            }

            // Save system configuration settings to site_settings table
            foreach ($settingsToSave as $key => $val) {
                // For PEM keys or secrets, don't strip format
                if (
                    strpos($key, 'gaps_private_key') === false && 
                    strpos($key, 'gaps_public_key') === false && 
                    strpos($key, 'gaps_server_public_key') === false
                ) {
                    $val = sanitizeString($val);
                }
                
                $exists = Database::fetchOne("SELECT id FROM site_settings WHERE setting_key = ? LIMIT 1", [$key]);
                if ($exists) {
                    Database::execute(
                        "UPDATE site_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?",
                        [$val, $key]
                    );
                } else {
                    Database::execute(
                        "INSERT INTO site_settings (setting_key, setting_value, setting_group, label, updated_at) VALUES (?, ?, 'general', ?, NOW())",
                        [$key, $val, ucwords(str_replace('_', ' ', $key))]
                    );
                }
            }

            auditLog('ADMIN_SETTINGS_UPDATED', "Updated platform system settings and credentials config values", 'settings', $adminUser['id']);
            $success = "Platform configuration saved successfully.";
        }
        $adminUser = currentUser(); // refresh details

    } catch (Exception $e) {
        $error = "Failed to save settings: " . $e->getMessage();
    }
}

// Fetch all settings grouped from correct site_settings table
$settingsList = Database::fetchAll("SELECT * FROM site_settings ORDER BY setting_key ASC");
$settings = [];
foreach ($settingsList as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}

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

            <!-- Settings layout tabs -->
            <form method="POST" action="<?= APP_URL ?>/admin/settings">
                <?= csrfField() ?>

                <div class="row g-4">
                    <!-- Tabs navigation -->
                    <div class="col-md-3">
                        <div class="nav flex-column nav-pills border rounded-12 p-2 bg-white" id="settingsTabs" role="tablist">
                            <button class="nav-link active text-start fw-bold py-3" id="general-tab" data-bs-toggle="pill" data-bs-target="#general" type="button" role="tab"><i class="fas fa-sliders me-2"></i> General</button>
                            <button class="nav-link text-start fw-bold py-3" id="wallet-tab" data-bs-toggle="pill" data-bs-target="#wallet" type="button" role="tab"><i class="fas fa-wallet me-2"></i> Wallet & Fees</button>
                            <button class="nav-link text-start fw-bold py-3" id="manual-tab" data-bs-toggle="pill" data-bs-target="#manual" type="button" role="tab"><i class="fas fa-bank me-2"></i> Manual Funding</button>
                            <!-- GAPS API section removed (moved to api_config.php) -->
                            <button class="nav-link text-start fw-bold py-3" id="smtp-tab" data-bs-toggle="pill" data-bs-target="#smtp" type="button" role="tab"><i class="fas fa-envelope me-2"></i> SMTP Settings</button>
                            <button class="nav-link text-start fw-bold py-3" id="admin-account-tab" data-bs-toggle="pill" data-bs-target="#admin-account" type="button" role="tab"><i class="fas fa-user-cog me-2"></i> Admin Account</button>
                        </div>
                    </div>

                    <!-- Tabs content panes -->
                    <div class="col-md-9">
                        <div class="card border-0 shadow-sm rounded-12 p-4">
                            <div class="tab-content" id="settingsTabsContent">
                                
                                <!-- General settings -->
                                <div class="tab-pane fade show active" id="general" role="tabpanel">
                                    <h5 class="fw-bold mb-4">General Platform Configurations</h5>
                                    
                                    <div class="mb-3">
                                        <label for="site_name" class="form-label small">Platform Name</label>
                                        <input type="text" class="form-control" id="site_name" name="site_name" value="<?= e($settings['site_name'] ?? APP_NAME) ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label for="site_tagline" class="form-label small">Site Tagline</label>
                                        <input type="text" class="form-control" id="site_tagline" name="site_tagline" value="<?= e($settings['site_tagline'] ?? '') ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label for="site_email" class="form-label small">Support Email</label>
                                        <input type="email" class="form-control" id="site_email" name="site_email" value="<?= e($settings['site_email'] ?? '') ?>">
                                    </div>
                                     <div class="mb-3">
                                         <label for="allow_registration" class="form-label small">Allow Customer Registrations</label>
                                         <select class="form-select" id="allow_registration" name="allow_registration">
                                             <option value="1" <?= ($settings['allow_registration'] ?? '1') === '1' ? 'selected' : '' ?>>Enabled</option>
                                             <option value="0" <?= ($settings['allow_registration'] ?? '1') === '0' ? 'selected' : '' ?>>Disabled</option>
                                         </select>
                                     </div>
                                     <div class="border-top pt-4 mt-4">
                                        <h6 class="fw-bold text-danger mb-2"><i class="fas fa-shield-alt me-2"></i>Security Rate Limit Locks</h6>
                                        <p class="small text-muted mb-3">If users or administrators receive "Too many attempts" locks on login, OTP, or withdrawals, click the button below to instantly clear all active rate limit locks.</p>
                                        <button type="submit" name="action" value="clear_rate_limits" class="btn btn-outline-danger py-2 px-4 rounded-8" onclick="return confirm('Are you sure you want to clear all security rate limit locks?');">
                                            <i class="fas fa-unlock me-2"></i>Clear All Security Rate Limits
                                        </button>
                                    </div>
                                </div>

                                <!-- Wallet Limits settings -->
                                <div class="tab-pane fade" id="wallet" role="tabpanel">
                                    <h5 class="fw-bold mb-4">Wallet & Transaction Fees</h5>
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="min_deposit" class="form-label small">Min Deposit Limit (₦)</label>
                                            <input type="number" class="form-control" id="min_deposit" name="min_deposit" value="<?= (float)($settings['min_deposit'] ?? 100) ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="max_deposit" class="form-label small">Max Deposit Limit (₦)</label>
                                            <input type="number" class="form-control" id="max_deposit" name="max_deposit" value="<?= (float)($settings['max_deposit'] ?? 5000000) ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="min_withdrawal" class="form-label small">Min Withdrawal Limit (₦)</label>
                                            <input type="number" class="form-control" id="min_withdrawal" name="min_withdrawal" value="<?= (float)($settings['min_withdrawal'] ?? 500) ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="max_withdrawal" class="form-label small">Max Withdrawal Limit (₦)</label>
                                            <input type="number" class="form-control" id="max_withdrawal" name="max_withdrawal" value="<?= (float)($settings['max_withdrawal'] ?? 500000) ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="withdrawal_fee" class="form-label small">Withdrawal Transfer Fee (%)</label>
                                            <input type="number" step="0.01" class="form-control" id="withdrawal_fee" name="withdrawal_fee" value="<?= (float)($settings['withdrawal_fee'] ?? 1.5) ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="transfer_fee" class="form-label small">W2W Inside Transfer Fee (%)</label>
                                            <input type="number" step="0.01" class="form-control" id="transfer_fee" name="transfer_fee" value="<?= (float)($settings['transfer_fee'] ?? 0) ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="deposit_fee_percentage" class="form-label small">GAPS Deposit Fee Percentage (%)</label>
                                            <input type="number" step="0.01" class="form-control" id="deposit_fee_percentage" name="deposit_fee_percentage" value="<?= (float)($settings['deposit_fee_percentage'] ?? 1.5) ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="deposit_fee_flat" class="form-label small">GAPS Deposit Flat Fee (₦)</label>
                                            <input type="number" class="form-control" id="deposit_fee_flat" name="deposit_fee_flat" value="<?= (float)($settings['deposit_fee_flat'] ?? 0) ?>">
                                        </div>
                                        <div class="col-12">
                                            <hr class="my-2">
                                            <h6 class="fw-semibold mb-1">Referral Bonus</h6>
                                            <p class="text-muted small mb-3">
                                                The referrer earns this percentage of every service discount their referred user receives.
                                                E.g. if a referred user saves ₦500 on a data purchase and this is set to 10%, the referrer earns ₦50.
                                            </p>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="referral_bonus" class="form-label small">Flat Signup Bonus (₦)</label>
                                            <input type="number" step="0.01" class="form-control" id="referral_bonus" name="referral_bonus" value="<?= (float)($settings['referral_bonus'] ?? 200) ?>">
                                            <div class="form-text">One-time bonus paid to referrer when referred user verifies their email.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="referral_bonus_percentage" class="form-label small">Ongoing Discount Bonus (%)</label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" min="0" max="100" class="form-control" id="referral_bonus_percentage" name="referral_bonus_percentage" value="<?= (float)($settings['referral_bonus_percentage'] ?? 10) ?>">
                                                <span class="input-group-text">%</span>
                                            </div>
                                            <div class="form-text">% of referred user's discount credited to referrer on each transaction.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="vtu_commission_admin_share" class="form-label small">Admin VTU Commission Share (%)</label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" min="0" max="100" class="form-control" id="vtu_commission_admin_share" name="vtu_commission_admin_share" value="<?= (float)($settings['vtu_commission_admin_share'] ?? 34) ?>">
                                                <span class="input-group-text">%</span>
                                            </div>
                                            <div class="form-text">% of API commission rate allocated to Platform Admin.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="vtu_commission_user_share" class="form-label small">User VTU Commission Share (%)</label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" min="0" max="100" class="form-control" id="vtu_commission_user_share" name="vtu_commission_user_share" value="<?= (float)($settings['vtu_commission_user_share'] ?? 66) ?>">
                                                <span class="input-group-text">%</span>
                                            </div>
                                            <div class="form-text">% of API commission rate allocated to User Discount.</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Manual Funding Details -->
                                <div class="tab-pane fade" id="manual" role="tabpanel">
                                    <h5 class="fw-bold mb-4">Manual Bank Transfer Funding Accounts</h5>
                                    <p class="small text-muted mb-4">Set the bank account information shown to users for manual wallet loading bank transfers.</p>
                                    
                                    <div class="mb-3">
                                        <label for="company_bank_name" class="form-label small">Recipient Bank Name</label>
                                        <input type="text" class="form-control" id="company_bank_name" name="company_bank_name" value="<?= e($settings['company_bank_name'] ?? '') ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label for="company_account_name" class="form-label small">Beneficiary Account Name</label>
                                        <input type="text" class="form-control" id="company_account_name" name="company_account_name" value="<?= e($settings['company_account_name'] ?? '') ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label for="company_account_no" class="form-label small">Account Number</label>
                                        <input type="text" class="form-control" id="company_account_no" name="company_account_no" value="<?= e($settings['company_account_no'] ?? '') ?>">
                                    </div>
                                </div>

                                <!-- GAPS API credentials removed from settings.php (managed in api_config.php) -->

                                <!-- Admin Account Configurations -->
                                <div class="tab-pane fade" id="admin-account" role="tabpanel">
                                    <h5 class="fw-bold mb-4">Administrator Profile Settings</h5>
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="admin_first_name" class="form-label small">First Name</label>
                                            <input type="text" class="form-control" id="admin_first_name" name="admin_first_name" value="<?= e($adminUser['first_name'] ?? '') ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="admin_last_name" class="form-label small">Last Name</label>
                                            <input type="text" class="form-control" id="admin_last_name" name="admin_last_name" value="<?= e($adminUser['last_name'] ?? '') ?>" required>
                                        </div>
                                        <div class="col-md-12">
                                            <label for="admin_email" class="form-label small">Email Address</label>
                                            <input type="email" class="form-control" id="admin_email" name="admin_email" value="<?= e($adminUser['email'] ?? '') ?>" required>
                                        </div>
                                        <div class="col-md-12">
                                            <label for="admin_password" class="form-label small">Change Password (leave blank to keep current)</label>
                                            <input type="password" class="form-control" id="admin_password" name="admin_password" placeholder="Min 6 characters">
                                        </div>
                                    </div>
                                </div>

                                <!-- SMTP Settings -->
                                <div class="tab-pane fade" id="smtp" role="tabpanel">
                                    <h5 class="fw-bold mb-4">SMTP Email & Notification settings</h5>
                                    <p class="small text-muted mb-4">Configure SMTP server details to send OTP codes and transactional emails.</p>
                                    
                                    <div class="row g-3">
                                        <div class="col-md-8">
                                            <label for="mail_host" class="form-label small">SMTP Host</label>
                                            <input type="text" class="form-control" id="mail_host" name="mail_host" value="<?= e($settings['mail_host'] ?? '') ?>" placeholder="e.g. smtp.mailgun.org">
                                        </div>
                                        <div class="col-md-4">
                                            <label for="mail_port" class="form-label small">SMTP Port</label>
                                            <input type="number" class="form-control" id="mail_port" name="mail_port" value="<?= (int)($settings['mail_port'] ?? 587) ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="mail_username" class="form-label small">SMTP Username</label>
                                            <input type="text" class="form-control" id="mail_username" name="mail_username" value="<?= e($settings['mail_username'] ?? '') ?>" placeholder="SMTP username">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="mail_password" class="form-label small">SMTP Password</label>
                                            <input type="password" class="form-control" id="mail_password" name="mail_password" value="<?= e($settings['mail_password'] ?? '') ?>" placeholder="SMTP password">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="mail_encryption" class="form-label small">SMTP Encryption</label>
                                            <select class="form-select" id="mail_encryption" name="mail_encryption">
                                                <option value="tls" <?= ($settings['mail_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (Recommended)</option>
                                                <option value="ssl" <?= ($settings['mail_encryption'] ?? 'tls') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                                <option value="none" <?= ($settings['mail_encryption'] ?? 'tls') === 'none' ? 'selected' : '' ?>>None</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="mail_from_address" class="form-label small">Sender Email Address</label>
                                            <input type="email" class="form-control" id="mail_from_address" name="mail_from_address" value="<?= e($settings['mail_from_address'] ?? '') ?>" placeholder="e.g. noreply@yourdomain.com">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="mail_from_name" class="form-label small">Sender Name</label>
                                            <input type="text" class="form-control" id="mail_from_name" name="mail_from_name" value="<?= e($settings['mail_from_name'] ?? '') ?>" placeholder="e.g. OURCR ONLINE">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="mail_reply_to" class="form-label small">Reply-to Email Address</label>
                                            <input type="email" class="form-control" id="mail_reply_to" name="mail_reply_to" value="<?= e($settings['mail_reply_to'] ?? '') ?>" placeholder="e.g. support@yourdomain.com">
                                        </div>
                                        <div class="col-12">
                                            <hr class="my-2">
                                            <h6 class="fw-semibold mb-3">Notification Preferences</h6>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="email_notifications_enabled" name="email_notifications_enabled" value="1"
                                                    <?= ($settings['email_notifications_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="email_notifications_enabled">
                                                    <strong>Mirror in-app notifications to email</strong>
                                                    <p class="text-muted small mb-0">When enabled, every in-app notification sent to a user will also be delivered to their email address via SMTP.</p>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="alert alert-info p-3 small mb-0">
                                                <i class="fas fa-info-circle me-2"></i>
                                                <strong>OTP emails are always sent</strong> when a user registers, regardless of this toggle. Ensure your SMTP credentials are correct above.
                                            </div>
                                        </div>
                                        <div class="col-12 mt-3">
                                            <div class="input-group input-group-sm">
                                                <input type="email" class="form-control" id="testEmail" placeholder="Enter test email address" value="<?= e($adminUser['email']) ?>">
                                                <button type="button" class="btn btn-outline-primary" id="testEmailBtn">
                                                    <i class="fas fa-paper-plane me-2"></i>Send Test Email
                                                </button>
                                            </div>
                                            <span id="testEmailStatus" class="small d-block mt-2"></span>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <hr class="my-4">
                            <button type="submit" class="btn btn-danger py-2 px-5 bg-site-color border-0">
                                Save Configurations
                            </button>
                        </div>
                    </div>
                </div>

            </form>

        </div>
    </main>
</div>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>

<script>
document.getElementById('testEmailBtn').addEventListener('click', function() {
    const btn = this;
    const statusEl = document.getElementById('testEmailStatus');
    const testEmailInput = document.getElementById('testEmail');

    const testEmail = testEmailInput.value.trim();
    if (!testEmail) {
        statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Please enter a test email address.</span>';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
    statusEl.textContent = '';

    const formData = new FormData();
    formData.append('test_email', testEmail);
    formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

    // Pass current form values so user can test without saving first
    formData.append('mail_host',         document.getElementById('mail_host').value.trim());
    formData.append('mail_port',         document.getElementById('mail_port').value.trim());
    formData.append('mail_username',     document.getElementById('mail_username').value.trim());
    formData.append('mail_password',     document.getElementById('mail_password').value.trim());
    formData.append('mail_encryption',   document.getElementById('mail_encryption').value);
    formData.append('mail_from_address', document.getElementById('mail_from_address').value.trim());
    formData.append('mail_from_name',    document.getElementById('mail_from_name').value.trim());

    // 30-second abort timeout
    const controller = new AbortController();
    const timeoutId  = setTimeout(() => controller.abort(), 30000);

    // Use dedicated JSON-only endpoint (avoids HTML contamination from the admin layout)
    fetch('<?= APP_URL ?>/admin/api/test_email.php', {
        method: 'POST',
        body: formData,
        signal: controller.signal,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => {
        clearTimeout(timeoutId);
        // Try to parse JSON even if HTTP status is not 200
        return response.text().then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                // Server returned non-JSON (HTML error page, etc.)
                console.error('Non-JSON response:', text.substring(0, 500));
                throw new Error('Server returned an unexpected response. Check server error logs.');
            }
        });
    })
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Send Test Email';

        if (data.success) {
            statusEl.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>' + data.message + '</span>';
        } else {
            statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>' + data.message + '</span>';
        }
    })
    .catch(error => {
        clearTimeout(timeoutId);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Send Test Email';
        const msg = error.name === 'AbortError' ? 'Request timed out (30s). Check if your SMTP host is reachable.' : error.message;
        statusEl.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>' + msg + '</span>';
    });
});
</script>

