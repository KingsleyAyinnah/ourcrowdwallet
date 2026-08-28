<?php
/**
 * OURCR ONLINE - Admin Panel: Manage Administrators
 * Superadmin-only. Lists all admin and superadmin accounts.
 * Supports: create, suspend/activate, reset password, hard-delete.
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireSuperAdmin();

$adminPageTitle = 'Manage Admins';

$error   = '';
$success = '';

// ── POST handler ─────────────────────────────────────────────────────────────
if (isPost()) {
    try {
        requireCsrf();
        $action    = post('action');
        $targetId  = (int)post('admin_id');

        // ── Create new admin ────────────────────────────────────────────────
        if ($action === 'create') {
            $firstName = sanitizeString(post('first_name'));
            $lastName  = sanitizeString(post('last_name'));
            $email     = sanitizeEmail(post('email'));
            $username  = sanitizeString(post('username'));
            $phone     = sanitizeString(post('phone'));
            $password  = post('password');
            $role      = post('role');
            if (!in_array($role, ['superadmin', 'admin', 'support'], true)) {
                $role = 'admin';
            }

            // Basic validation
            if (empty($firstName) || empty($lastName) || empty($email) || empty($username) || empty($phone) || empty($password)) {
                $error = 'All fields are required.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif (!isValidEmail($email)) {
                $error = 'Please enter a valid email address.';
            } else {
                // Check duplicates
                $dup = Database::fetchOne(
                    'SELECT id FROM users WHERE (email = ? OR username = ?) AND deleted_at IS NULL LIMIT 1',
                    [$email, $username]
                );
                if ($dup) {
                    $error = 'An account with that email or username already exists.';
                } else {
                    $uuid    = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                    );
                    $refCode = Ourcr\User::generateUniqueReferralCode();
                    $hash    = hashPassword($password);

                    Database::execute(
                        'INSERT INTO users (uuid, username, email, phone, password_hash, first_name, last_name,
                                            role, status, email_verified_at, referral_code, wallet_balance, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'active\', NOW(), ?, 0.00, NOW(), NOW())',
                        [$uuid, strtolower($username), strtolower($email), $phone, $hash, $firstName, $lastName, $role, $refCode]
                    );

                    $newId = (int)Database::lastInsertId();
                    auditLog('ADMIN_CREATED', "Created $role account for $email", 'users', $newId);
                    $success = ucfirst($role) . " account for {$firstName} {$lastName} created successfully.";
                }
            }
        }

        // ── Actions on existing admin ────────────────────────────────────────
        elseif ($targetId > 0) {
            $target = Ourcr\User::findById($targetId);

            if (!$target || !in_array($target['role'], ['admin', 'superadmin'], true)) {
                $error = 'Admin account not found.';
            }
            // Developer Account Security Safeguard
            elseif (in_array($action, ['delete', 'suspend', 'ban'], true) && $target && $target['email'] === 'kingsleyayinnah@gmail.com') {
                $error = 'This admin account is protected and cannot be suspended, banned, or deleted.';
            }
            // Protect the currently logged-in superadmin from self-harm
            elseif (in_array($action, ['delete', 'suspend', 'ban'], true) && $targetId === currentUserId()) {
                $error = 'You cannot perform this action on your own account.';
            } else {
                switch ($action) {
                    case 'activate':
                        Ourcr\User::activate($targetId);
                        auditLog('ADMIN_ACTIVATED', "Activated admin ID $targetId", 'users', $targetId);
                        $success = 'Admin account activated.';
                        break;

                    case 'suspend':
                        Ourcr\User::suspend($targetId, 'Suspended by superadmin');
                        auditLog('ADMIN_SUSPENDED', "Suspended admin ID $targetId", 'users', $targetId);
                        $success = 'Admin account suspended.';
                        break;

                    case 'reset_password':
                        $newPass = post('new_password');
                        if (strlen($newPass) < 8) {
                            $error = 'New password must be at least 8 characters.';
                        } else {
                            Ourcr\User::updatePassword($targetId, $newPass);
                            auditLog('ADMIN_PASSWORD_RESET', "Reset password for admin ID $targetId", 'users', $targetId);
                            $success = 'Admin password updated successfully.';
                        }
                        break;

                    case 'delete':
                        $name = $target['first_name'] . ' ' . $target['last_name'];
                        Ourcr\User::hardDelete($targetId);
                        auditLog('ADMIN_DELETED', "Permanently deleted admin ID $targetId ($name)", 'users', $targetId);
                        $success = "Admin account \"{$name}\" has been permanently deleted.";
                        break;
                }
            }
        }
    } catch (Exception $e) {
        $error = 'Action failed: ' . $e->getMessage();
    }
}

// ── Fetch all admin accounts ─────────────────────────────────────────────────
$adminsList = Database::fetchAll(
    "SELECT id, uuid, first_name, last_name, username, email, phone,
            role, status, last_login_at, created_at
     FROM users
     WHERE role IN ('admin', 'superadmin', 'support') AND deleted_at IS NULL
     ORDER BY role DESC, created_at ASC"
);

$currentAdminId = currentUserId();

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
            <div class="ms-auto">
                <button class="btn btn-sm btn-primary" style="background:#4f46e5;border:none;"
                        data-bs-toggle="modal" data-bs-target="#createAdminModal">
                    <i class="fas fa-plus me-1"></i> Add Admin
                </button>
            </div>
        </div>

        <div class="admin-content fade-in-up">

            <?php if ($error): ?>
                <div class="alert alert-danger py-2 px-3 small">
                    <i class="fas fa-exclamation-circle me-1"></i><?= e($error) ?>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success py-2 px-3 small">
                    <i class="fas fa-check-circle me-1"></i><?= e($success) ?>
                </div>
            <?php endif; ?>

            <!-- Admins Table -->
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email / Username</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Joined</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($adminsList)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">No admin accounts found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($adminsList as $adm): ?>
                                <?php $isSelf = ((int)$adm['id'] === $currentAdminId); ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark">
                                            <?= e($adm['first_name'] . ' ' . $adm['last_name']) ?>
                                            <?php if ($isSelf): ?>
                                                <span class="badge bg-info-subtle text-info ms-1" style="font-size:10px;">You</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small"><?= e($adm['email']) ?></div>
                                        <div class="small text-muted">@<?= e($adm['username']) ?></div>
                                    </td>
                                    <td class="small"><?= e($adm['phone'] ?? '—') ?></td>
                                    <td>
                                        <?php if ($adm['role'] === 'superadmin'): ?>
                                            <span class="badge" style="background:#4f46e5;color:#fff;">Superadmin</span>
                                        <?php elseif ($adm['role'] === 'support'): ?>
                                            <span class="badge bg-info text-white">Support</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Admin</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="admin-badge admin-badge-<?= $adm['status'] === 'active' ? 'success' : 'warning' ?>">
                                            <?= ucfirst($adm['status']) ?>
                                        </span>
                                    </td>
                                    <td class="small text-muted">
                                        <?= $adm['last_login_at'] ? date('d M Y H:i', strtotime($adm['last_login_at'])) : 'Never' ?>
                                    </td>
                                    <td class="small"><?= date('d M Y', strtotime($adm['created_at'])) ?></td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-1">

                                            <!-- Suspend / Activate -->
                                            <?php if (!$isSelf): ?>
                                                <form method="POST" action="<?= APP_URL ?>/admin/admins" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="admin_id" value="<?= $adm['id'] ?>">
                                                    <?php if ($adm['status'] === 'active'): ?>
                                                        <input type="hidden" name="action" value="suspend">
                                                        <button type="submit"
                                                                class="action-btn action-btn-edit"
                                                                title="Suspend account"
                                                                onclick="return confirm('Suspend admin account of <?= e(addslashes($adm['first_name'])) ?>?')"
                                                                <?= $adm['email'] === 'kingsleyayinnah@gmail.com' ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : '' ?>>
                                                            <i class="fas fa-ban"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <input type="hidden" name="action" value="activate">
                                                        <button type="submit"
                                                                class="action-btn action-btn-view"
                                                                style="background:rgba(16,185,129,.1);color:#10b981;"
                                                                title="Activate account"
                                                                onclick="return confirm('Activate admin account of <?= e(addslashes($adm['first_name'])) ?>?')"
                                                                <?= $adm['email'] === 'kingsleyayinnah@gmail.com' ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : '' ?>>
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Reset Password -->
                                            <button type="button"
                                                    class="action-btn action-btn-view"
                                                    style="background:rgba(79,70,229,.12);color:#4f46e5;"
                                                    title="Reset password"
                                                    onclick="openResetModal(<?= $adm['id'] ?>, '<?= e(addslashes($adm['first_name'] . ' ' . $adm['last_name'])) ?>')">
                                                <i class="fas fa-key"></i>
                                            </button>

                                            <!-- Delete (permanent, not self) -->
                                            <?php if (!$isSelf): ?>
                                                <form method="POST" action="<?= APP_URL ?>/admin/admins" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="admin_id" value="<?= $adm['id'] ?>">
                                                    <input type="hidden" name="action"   value="delete">
                                                    <button type="submit"
                                                            class="action-btn"
                                                            style="background:rgba(220,38,38,.12);color:#dc2626;"
                                                            title="Permanently delete admin"
                                                            onclick="return confirm('⚠️ Permanently delete admin account of <?= e(addslashes($adm['first_name'] . ' ' . $adm['last_name'])) ?>?\n\nThis CANNOT be undone.')"
                                                            <?= $adm['email'] === 'kingsleyayinnah@gmail.com' ? 'disabled style="opacity:0.4; cursor:not-allowed; background:rgba(108,117,125,.1); color:#6c757d;"' : '' ?>>
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </main>
</div>

<!-- ── Create Admin Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="createAdminModal" tabindex="-1" aria-labelledby="createAdminModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-16">
            <form method="POST" action="<?= APP_URL ?>/admin/admins">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">

                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="createAdminModalLabel">
                        <i class="fas fa-user-shield me-2 text-primary"></i>Create Admin Account
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body pt-3">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small">First Name</label>
                            <input type="text" class="form-control" name="first_name" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Last Name</label>
                            <input type="text" class="form-control" name="last_name" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Email Address</label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Username</label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Phone</label>
                            <input type="text" class="form-control" name="phone" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Password <span class="text-muted">(min 8 chars)</span></label>
                            <input type="password" class="form-control" name="password" minlength="8" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Role</label>
                            <select class="form-select" name="role">
                                <option value="admin" selected>Admin — full access except managing admins</option>
                                <option value="superadmin">Superadmin — unrestricted access</option>
                                <option value="support">Support — access to support portal only</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" style="background:#4f46e5;border:none;">
                        <i class="fas fa-plus me-1"></i> Create Admin
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Reset Password Modal ───────────────────────────────────────────────── -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-16">
            <form method="POST" action="<?= APP_URL ?>/admin/admins">
                <?= csrfField() ?>
                <input type="hidden" name="action"   value="reset_password">
                <input type="hidden" name="admin_id" id="resetAdminId">

                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="resetPasswordModalLabel">
                        <i class="fas fa-key me-2" style="color:#4f46e5;"></i>Reset Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body pt-2">
                    <p class="small text-muted mb-3" id="resetAdminName"></p>
                    <label class="form-label small">New Password <span class="text-muted">(min 8 chars)</span></label>
                    <input type="password" class="form-control" name="new_password" minlength="8" required>
                </div>

                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" style="background:#4f46e5;border:none;">
                        Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openResetModal(id, name) {
    document.getElementById('resetAdminId').value = id;
    document.getElementById('resetAdminName').textContent = 'Updating password for: ' + name;
    var modal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
    modal.show();
}
</script>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>
