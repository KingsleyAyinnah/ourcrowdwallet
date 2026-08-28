<?php
/**
 * OURCR ONLINE - Admin Panel API Credentials Config
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'API Gateway Configs';
$error = '';
$success = '';

// Handle POST: Update credentials
if (isPost()) {
    try {
        requireCsrf();

        $credentials = [
            'vtpass_env', 
            'vtpass_sandbox_api_key', 'vtpass_sandbox_public_key', 'vtpass_sandbox_secret_key', 
            'vtpass_live_api_key', 'vtpass_live_public_key', 'vtpass_live_secret_key',
            'paystack_secret_key', 'paystack_public_key',
            'gaps_env', 'gaps_base_url', 'gaps_access_code', 'gaps_username', 'gaps_password', 'gaps_channel',
            'gaps_merchant_id', 'gaps_terminal_id', 'gaps_account_number',
            'gaps_public_key', 'gaps_private_key', 'gaps_server_public_key'
        ];

        foreach ($credentials as $credName) {
            if (isset($_POST[$credName])) {
                $val = post($credName); // do not sanitize keys as they contain raw symbols, spaces or PEM formats
                $exists = Database::fetchOne("SELECT id FROM site_settings WHERE setting_key = ? LIMIT 1", [$credName]);
                if ($exists) {
                    Database::execute(
                        "UPDATE site_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?",
                        [$val, $credName]
                    );
                } else {
                    Database::execute(
                        "INSERT INTO site_settings (setting_key, setting_value, setting_group, label, updated_at) VALUES (?, ?, 'api', ?, NOW())",
                        [$credName, $val, ucwords(str_replace('_', ' ', $credName))]
                    );
                }
            }
        }

        auditLog('ADMIN_API_CONFIG_UPDATED', "Updated payment gateway API credentials keys", 'settings', 0);
        $success = "Gateway credentials updated successfully.";

    } catch (Exception $e) {
        $error = "Failed to update configurations: " . $e->getMessage();
    }
}

// Fetch settings
$settingsList = Database::fetchAll("SELECT * FROM site_settings");
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
                <div class="alert alert-danger rounded-12" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success rounded-12" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?= e($success) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?= APP_URL ?>/admin/api-config">
                <?= csrfField() ?>

                <div class="row g-4">

                    <!-- 1. Account Name Resolver (Paystack) -->
                    <div class="col-12">
                        <div class="card border-0 shadow-sm rounded-16 p-4">
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <div class="bg-info bg-opacity-10 text-info rounded-circle d-flex align-items-center justify-content-center" style="width:44px; height:44px; font-size:18px;">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold mb-0">Account Name Resolver Credentials</h5>
                                    <p class="small text-muted mb-0">Used for guaranteed instant account holder name resolution across all 100+ Nigerian commercial, digital, & microfinance banks.</p>
                                </div>
                            </div>

                            <div class="row g-3 mt-2">
                                <div class="col-md-6">
                                    <label for="paystack_secret_key" class="form-label small fw-bold">Paystack Secret Key</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="paystack_secret_key" name="paystack_secret_key" value="<?= e($settings['paystack_secret_key'] ?? '') ?>" placeholder="sk_live_... or sk_test_...">
                                        <button class="btn btn-outline-secondary toggle-pass" type="button" data-target="paystack_secret_key">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-text small">Enter your Paystack Secret Key for 100% automated name lookups.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="paystack_public_key" class="form-label small fw-bold">Paystack Public Key (Optional)</label>
                                    <input type="text" class="form-control" id="paystack_public_key" name="paystack_public_key" value="<?= e($settings['paystack_public_key'] ?? '') ?>" placeholder="pk_live_... or pk_test_...">
                                    <div class="form-text small">Optional public key.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. VTpass configurations -->
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm rounded-16 p-4 h-100">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center" style="width:40px; height:40px; font-size:16px;">
                                    <i class="fas fa-wifi"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold mb-0 text-primary">VTpass Credentials</h5>
                                    <p class="small text-muted mb-0">VAS provider for Airtime, Data, Cable TV, Electricity, & Exams.</p>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="vtpass_env" class="form-label small fw-bold">Environment Mode</label>
                                <select class="form-select" id="vtpass_env" name="vtpass_env">
                                    <option value="sandbox" <?= ($settings['vtpass_env'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>Sandbox / Test Mode</option>
                                    <option value="live" <?= ($settings['vtpass_env'] ?? 'sandbox') === 'live' ? 'selected' : '' ?>>Live / Production Mode</option>
                                </select>
                            </div>

                            <div class="border-top pt-3 mb-4">
                                <h6 class="fw-bold text-primary mb-3"><i class="fas fa-flask me-2"></i>Sandbox Keys</h6>
                                <div class="mb-3">
                                    <label for="vtpass_sandbox_api_key" class="form-label small fw-bold">API Key</label>
                                    <input type="text" class="form-control" id="vtpass_sandbox_api_key" name="vtpass_sandbox_api_key" value="<?= e($settings['vtpass_sandbox_api_key'] ?? '') ?>" placeholder="Sandbox API Key">
                                </div>
                                <div class="mb-3">
                                    <label for="vtpass_sandbox_public_key" class="form-label small fw-bold">Public Key</label>
                                    <input type="text" class="form-control" id="vtpass_sandbox_public_key" name="vtpass_sandbox_public_key" value="<?= e($settings['vtpass_sandbox_public_key'] ?? '') ?>" placeholder="PK_...">
                                </div>
                                <div class="mb-3">
                                    <label for="vtpass_sandbox_secret_key" class="form-label small fw-bold">Secret Key</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="vtpass_sandbox_secret_key" name="vtpass_sandbox_secret_key" value="<?= e($settings['vtpass_sandbox_secret_key'] ?? '') ?>" placeholder="SK_...">
                                        <button class="btn btn-outline-secondary toggle-pass" type="button" data-target="vtpass_sandbox_secret_key">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="border-top pt-3">
                                <h6 class="fw-bold text-success mb-3"><i class="fas fa-check-circle me-2"></i>Live Keys</h6>
                                <div class="mb-3">
                                    <label for="vtpass_live_api_key" class="form-label small fw-bold">API Key</label>
                                    <input type="text" class="form-control" id="vtpass_live_api_key" name="vtpass_live_api_key" value="<?= e($settings['vtpass_live_api_key'] ?? '') ?>" placeholder="Live API Key">
                                </div>
                                <div class="mb-3">
                                    <label for="vtpass_live_public_key" class="form-label small fw-bold">Public Key</label>
                                    <input type="text" class="form-control" id="vtpass_live_public_key" name="vtpass_live_public_key" value="<?= e($settings['vtpass_live_public_key'] ?? '') ?>" placeholder="PK_...">
                                </div>
                                <div class="mb-3">
                                    <label for="vtpass_live_secret_key" class="form-label small fw-bold">Secret Key</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="vtpass_live_secret_key" name="vtpass_live_secret_key" value="<?= e($settings['vtpass_live_secret_key'] ?? '') ?>" placeholder="SK_...">
                                        <button class="btn btn-outline-secondary toggle-pass" type="button" data-target="vtpass_live_secret_key">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 3. GTBank GAPS configurations -->
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm rounded-16 p-4 h-100">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center" style="width:40px; height:40px; font-size:16px;">
                                    <i class="fas fa-university"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold mb-0 text-success">GTBank GAPS Credentials</h5>
                                    <p class="small text-muted mb-0">Bank transfer reconciliation & automated withdrawal payout engine.</p>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="gaps_env" class="form-label small fw-bold">Environment Mode</label>
                                    <select class="form-select" id="gaps_env" name="gaps_env">
                                        <option value="sandbox" <?= ($settings['gaps_env'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>Sandbox / Test Mode</option>
                                        <option value="live" <?= ($settings['gaps_env'] ?? 'sandbox') === 'live' ? 'selected' : '' ?>>Live / Production Mode</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="gaps_channel" class="form-label small fw-bold">Channel</label>
                                    <input type="text" class="form-control" id="gaps_channel" name="gaps_channel" value="<?= e($settings['gaps_channel'] ?? 'CROWDAPP') ?>" placeholder="e.g. CROWDAPP">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="gaps_access_code" class="form-label small fw-bold">Access Code (Customer ID)</label>
                                    <input type="text" class="form-control" id="gaps_access_code" name="gaps_access_code" value="<?= e($settings['gaps_access_code'] ?? ($settings['gaps_merchant_id'] ?? '')) ?>" placeholder="e.g. 3002583596">
                                </div>
                                <div class="col-md-6">
                                    <label for="gaps_username" class="form-label small fw-bold">Username</label>
                                    <input type="text" class="form-control" id="gaps_username" name="gaps_username" value="<?= e($settings['gaps_username'] ?? '') ?>" placeholder="e.g. collnsuh">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="gaps_password" class="form-label small fw-bold">Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="gaps_password" name="gaps_password" value="<?= e($settings['gaps_password'] ?? '') ?>" placeholder="Password">
                                        <button class="btn btn-outline-secondary toggle-pass" type="button" data-target="gaps_password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="gaps_account_number" class="form-label small fw-bold">Settlement Account No</label>
                                    <input type="text" class="form-control" id="gaps_account_number" name="gaps_account_number" value="<?= e($settings['gaps_account_number'] ?? '') ?>" placeholder="e.g. 3002583596">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="gaps_base_url" class="form-label small fw-bold">WebService URL Endpoint</label>
                                <input type="text" class="form-control" id="gaps_base_url" name="gaps_base_url" value="<?= e($settings['gaps_base_url'] ?? '') ?>" placeholder="https://gtweb.gtbank.com/GSTPS/GAPS_FileUploader/FileUploader.asmx">
                                <div class="form-text small text-muted">Leave blank to auto-use GTBank Live (https://gtweb.gtbank.com/...) or Sandbox (https://gtweb6.gtbank.com/...) based on Environment Mode.</div>
                            </div>

                            <div class="mb-3">
                                <label for="gaps_public_key" class="form-label small fw-bold">GTBank RSA Public Key (Base64 PEM)</label>
                                <textarea class="form-control font-monospace small" id="gaps_public_key" name="gaps_public_key" rows="4" placeholder="MIGfMA0GCSqGSIb3DQEBAQUAA4GN..."><?= e($settings['gaps_public_key'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Save Button Sticky Bar -->
                <div class="mt-4 text-end">
                    <button type="submit" class="btn btn-primary py-3 px-5 rounded-12 fw-bold shadow-sm" style="background-color: #4f46e5; border: none; font-size: 15px;">
                        <i class="fas fa-save me-2"></i> Save Gateway Credentials
                    </button>
                </div>
            </form>

        </div>
    </main>
</div>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>

<script>
$(function(){
    // Password visibility toggle
    $('.toggle-pass').on('click', function(){
        var targetId = $(this).data('target');
        var $input = $('#' + targetId);
        var $icon = $(this).find('i');

        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $icon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            $input.attr('type', 'password');
            $icon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });
});
</script>
