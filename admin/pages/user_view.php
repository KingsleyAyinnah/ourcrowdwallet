<?php
/**
 * OURCR ONLINE - Admin Panel User Detail View
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$userId = get('id') ? (int)get('id') : 0;
if ($userId <= 0) {
    redirectTo('admin/users');
}

$user = Ourcr\User::findById($userId);
if (!$user) {
    setFlash('error', 'User not found.');
    redirectTo('admin/users');
}

$adminPageTitle = 'User Details — ' . $user['username'];
$error = '';
$success = '';

// Handle POST actions: Credit/Debit wallet or change status
if (isPost()) {
    try {
        requireCsrf();
        $action = post('action');

        // 1. Manual Credit/Debit
        if ($action === 'adjust_wallet') {
            $type   = post('adj_type'); // 'credit' or 'debit'
            $amount = (float)post('amount');
            $desc   = sanitizeString(post('description'));

            if ($amount <= 0) {
                $error = "Amount must be greater than zero.";
            } elseif (empty($desc)) {
                $error = "Please provide an adjustment reason/description.";
            } else {
                Database::beginTransaction();
                try {
                    if ($type === 'credit') {
                        creditWallet($userId, $amount, TXN_TYPE_DEPOSIT, $desc);
                        auditLog('ADMIN_WALLET_CREDIT', "Manually credited ₦$amount to user ID $userId. Reason: $desc", 'users', $userId);
                        $success = "Wallet credited successfully.";
                    } else {
                        // Check balance
                        $bal = getWalletBalance($userId);
                        if ($bal < $amount) {
                            throw new Exception("User has insufficient wallet balance (Current: ₦$bal).");
                        }
                        debitWallet($userId, $amount, 0, TXN_TYPE_WITHDRAWAL, $desc);
                        auditLog('ADMIN_WALLET_DEBIT', "Manually debited ₦$amount from user ID $userId. Reason: $desc", 'users', $userId);
                        $success = "Wallet debited successfully.";
                    }
                    Database::commit();
                    
                    // refresh user details
                    $user = Ourcr\User::findById($userId);
                } catch (Exception $ex) {
                    Database::rollback();
                    $error = $ex->getMessage();
                }
            }
        }

        // 2. Change Role & Status
        elseif ($action === 'update_profile') {
            $role   = sanitizeString(post('role'));
            $status = sanitizeString(post('status'));

            if ($user && $user['email'] === 'kingsleyayinnah@gmail.com') {
                $error = "This user account is protected. Role and status updates are blocked.";
            } elseif (in_array($role, ['user', 'admin', 'superadmin']) && in_array($status, ['active', 'suspended', 'banned'])) {
                Database::execute(
                    "UPDATE users SET role = ?, status = ?, updated_at = NOW() WHERE id = ?",
                    [$role, $status, $userId]
                );
                auditLog('ADMIN_USER_PROFILE_UPDATE', "Updated user ID $userId: role=$role, status=$status", 'users', $userId);
                $success = "User profiles updated.";
                $user = Ourcr\User::findById($userId); // refresh
            } else {
                $error = "Invalid role or status parameters.";
            }
        }

        // 3. Reset Password
        elseif ($action === 'reset_password') {
            $newPass = post('new_password');
            if (strlen($newPass) < 8) {
                $error = "Password must be at least 8 characters.";
            } else {
                Ourcr\User::updatePassword($userId, $newPass);
                auditLog('ADMIN_USER_PASSWORD_RESET', "Manually reset password for user ID $userId", 'users', $userId);
                $success = "User password updated successfully.";
            }
        }

    } catch (Exception $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}

// Fetch user transactions
$txns = Database::fetchAll(
    "SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20",
    [$userId]
);

// Fetch user VTpass logs
$vtpassLogs = Database::fetchAll(
    "SELECT * FROM vtpass_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20",
    [$userId]
);

// Fetch referrals
$referrals = Ourcr\Referral::getForReferrer($userId);

include ADMIN_PATH . '/includes/header.php';
?>
<div class="admin-wrapper">
    <?php include ADMIN_PATH . '/includes/sidebar.php'; ?>
    
    <main class="admin-main">
        <div class="admin-topbar">
            <div class="d-flex align-items-center gap-3">
                <a href="<?= APP_URL ?>/admin/users" class="btn btn-sm btn-outline-secondary d-lg-none"><i class="fas fa-arrow-left"></i></a>
                <h1 class="admin-topbar-title"><?= e($adminPageTitle) ?></h1>
            </div>
        </div>

        <div class="admin-content fade-in-up">
            
            <div class="mb-3">
                <a href="<?= APP_URL ?>/admin/users" class="text-primary text-decoration-none small"><i class="fas fa-arrow-left me-1"></i> Back to User Directory</a>
            </div>

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

            <div class="row g-4">
                <!-- User Meta Information Card -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm rounded-12 p-3 text-center mb-4">
                        <div class="card-body">
                            <h5 class="fw-bold mb-1"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></h5>
                            <p class="text-muted small mb-3">@<?= e($user['username']) ?></p>
                            
                            <div class="bg-light p-3 rounded-12 mb-4 text-start small">
                                <div class="mb-2"><strong>Wallet Balance:</strong><br><span class="h4 fw-bold text-success"><?= formatMoney($user['wallet_balance']) ?></span></div>
                                <div class="mb-2"><strong>Email Address:</strong><br><span class="text-dark"><?= e($user['email']) ?></span></div>
                                <div class="mb-2"><strong>Phone Number:</strong><br><span class="text-dark"><?= e($user['phone']) ?></span></div>
                                <div><strong>Registered At:</strong><br><span class="text-dark"><?= date('d M Y, h:i A', strtotime($user['created_at'])) ?></span></div>
                            </div>

                            <!-- Manual adjustment trigger -->
                            <button class="btn btn-primary w-100 py-2 rounded-8" data-bs-toggle="modal" data-bs-target="#adjustWalletModal">
                                <i class="fas fa-wallet me-2"></i> Adjust Wallet
                            </button>

                            <!-- Reset Password trigger -->
                            <button class="btn btn-outline-secondary w-100 py-2 rounded-8 mt-2" data-bs-toggle="modal" data-bs-target="#resetPasswordModal">
                                <i class="fas fa-key me-2"></i> Reset Password
                            </button>
                        </div>
                    </div>

                    <!-- Profile Management Card -->
                    <div class="card border-0 shadow-sm rounded-12 p-4 mb-4">
                        <h5 class="fw-bold mb-3">Manage Profile</h5>
                        <?php if ($user['email'] === 'kingsleyayinnah@gmail.com'): ?>
                            <div class="alert alert-info py-2 px-3 small mb-0">
                                <i class="fas fa-shield-alt me-1"></i> This account is protected. Deletion, suspension, and role changes are disabled.
                            </div>
                        <?php else: ?>
                            <form method="POST" action="<?= APP_URL ?>/admin/user-view?id=<?= $userId ?>">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update_profile">

                                <div class="mb-3">
                                    <label for="role" class="form-label small">System Role</label>
                                    <select class="form-select" id="role" name="role">
                                        <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User (Customer)</option>
                                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin (Staff)</option>
                                        <option value="superadmin" <?= $user['role'] === 'superadmin' ? 'selected' : '' ?>>Superadmin (Owner)</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="status" class="form-label small">Account Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="suspended" <?= $user['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                        <option value="banned" <?= $user['status'] === 'banned' ? 'selected' : '' ?>>Banned</option>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-outline-primary w-100 py-2 rounded-8">
                                    Save Profile parameters
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Info Tabs (Right Column) -->
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm rounded-12 p-4">
                        <ul class="nav nav-tabs mb-4" id="userViewTab" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active border-0 pb-2 fw-semibold" id="txns-tab" data-bs-toggle="tab" data-bs-target="#userTxns" type="button" role="tab">Transactions</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link border-0 pb-2 fw-semibold" id="vtpass-tab" data-bs-toggle="tab" data-bs-target="#userVtpass" type="button" role="tab">VTpass Logs</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link border-0 pb-2 fw-semibold" id="refs-tab" data-bs-toggle="tab" data-bs-target="#userRefs" type="button" role="tab">Referrals</button>
                            </li>
                        </ul>

                        <div class="tab-content" id="userViewTabContent">
                            <!-- Wallet Transactions -->
                            <div class="tab-pane fade show active" id="userTxns" role="tabpanel">
                                <?php if (empty($txns)): ?>
                                    <p class="text-muted text-center py-4">No wallet transactions found.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead>
                                                <tr class="text-muted small">
                                                    <th>Ref</th>
                                                    <th>Amount</th>
                                                    <th>Description</th>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($txns as $t): ?>
                                                    <tr>
                                                        <td class="small font-monospace"><?= e(maskString($t['reference'], 6, 6)) ?></td>
                                                        <td class="fw-bold text-<?= $t['type'] === 'credit' ? 'success' : 'danger' ?>">
                                                            <?= ($t['type'] === 'credit' ? '+' : '-') . ' ' . formatMoney($t['amount'], false) ?>
                                                        </td>
                                                        <td class="small"><?= e($t['description']) ?></td>
                                                        <td class="small"><?= date('d M, h:i A', strtotime($t['created_at'])) ?></td>
                                                        <td><span class="badge bg-<?= $t['status'] === 'success' ? 'success' : 'secondary' ?>"><?= $t['status'] ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- VTpass Logs -->
                            <div class="tab-pane fade" id="userVtpass" role="tabpanel">
                                <?php if (empty($vtpassLogs)): ?>
                                    <p class="text-muted text-center py-4">No VTpass transactions recorded.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead>
                                                <tr class="text-muted small">
                                                    <th>Request ID</th>
                                                    <th>Service</th>
                                                    <th>Recipient</th>
                                                    <th>Amount</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($vtpassLogs as $vl): ?>
                                                    <tr>
                                                        <td class="small font-monospace"><?= e($vl['request_id']) ?></td>
                                                        <td><span class="badge bg-light text-dark"><?= ucfirst($vl['service_type']) ?></span></td>
                                                        <td class="small"><?= e($vl['phone']) ?></td>
                                                        <td class="fw-bold"><?= formatMoney($vl['amount']) ?></td>
                                                        <td><span class="admin-badge admin-badge-<?= $vl['status'] ?>"><?= ucfirst($vl['status']) ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Referrals -->
                            <div class="tab-pane fade" id="userRefs" role="tabpanel">
                                <?php if (empty($referrals)): ?>
                                    <p class="text-muted text-center py-4">This user has not invited anyone.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead>
                                                <tr class="text-muted small">
                                                    <th>Username</th>
                                                    <th>Email</th>
                                                    <th>Date Joined</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($referrals as $ref): ?>
                                                    <tr>
                                                        <td class="fw-bold">@<?= e($ref['username']) ?></td>
                                                        <td class="small"><?= e($ref['email']) ?></td>
                                                        <td class="small"><?= date('d M Y', strtotime($ref['created_at'])) ?></td>
                                                        <td><span class="badge bg-<?= $ref['status'] === 'paid' ? 'success' : 'warning' ?>"><?= $ref['status'] ?></span></td>
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

<!-- Modal: Adjust Wallet (Credit/Debit) -->
<div class="modal fade" id="adjustWalletModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-16 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
                <h5 class="modal-title fw-bold">Manual Wallet Adjustment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= APP_URL ?>/admin/user-view?id=<?= $userId ?>">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="adjust_wallet">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small">Adjustment Type</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="adj_type" id="adj_credit" value="credit" checked>
                                <label class="form-check-label" for="adj_credit">Credit Wallet (+)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="adj_type" id="adj_debit" value="debit">
                                <label class="form-check-label" for="adj_debit">Debit Wallet (-)</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="amount" class="form-label small">Amount (₦)</label>
                        <input type="number" class="form-control" id="amount" name="amount" placeholder="e.g. 5000" required>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label small">Reason / Narrative</label>
                        <input type="text" class="form-control" id="description" name="description" placeholder="e.g. Bank credit offset / administrative error fix" required>
                    </div>
                </div>

                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background-color: #4f46e5; border: none;">Execute Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Reset Password -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content rounded-16 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-key me-2 text-primary"></i>Reset Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= APP_URL ?>/admin/user-view?id=<?= $userId ?>">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reset_password">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="new_password" class="form-label small">New Password (min 8 characters)</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" required>
                    </div>
                </div>

                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background-color: #4f46e5; border: none;">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>
