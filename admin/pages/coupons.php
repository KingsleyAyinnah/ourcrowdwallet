<?php
/**
 * OURCR ONLINE - Admin Panel Coupons Management
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'Promo Coupons';
$error = '';
$success = '';

// Handle POST: Create / Update / Delete Coupon
if (isPost()) {
    try {
        requireCsrf();
        $action = post('action');

        if ($action === 'save_coupon') {
            $id         = (int)post('id');
            $code       = strtoupper(sanitizeString(post('code')));
            $type       = sanitizeString(post('type', 'fixed'));
            $value      = (float)post('value');
            $minAmount  = (float)post('min_amount', 0);
            $limit      = (int)post('usage_limit', 1);
            $validFrom  = post('valid_from') ?: date('Y-m-d H:i:s');
            $validUntil = post('valid_until') ?: date('Y-m-d H:i:s', strtotime('+30 days'));
            $isActive   = (int)post('is_active', 0);

            if (empty($code) || $value <= 0) {
                throw new Exception("Coupon code and value are required.");
            }

            if ($id > 0) {
                // Update
                Database::execute(
                    "UPDATE coupons 
                     SET code = ?, type = ?, value = ?, min_amount = ?, usage_limit = ?, valid_from = ?, valid_until = ?, is_active = ?, updated_at = NOW()
                     WHERE id = ?",
                    [$code, $type, $value, $minAmount, $limit, $validFrom, $validUntil, $isActive, $id]
                );
                auditLog('ADMIN_COUPON_UPDATED', "Updated coupon code: $code", 'coupons', $id);
                $success = "Coupon updated successfully.";
            } else {
                // Insert
                Database::insert(
                    "INSERT INTO coupons (code, type, value, min_amount, usage_limit, valid_from, valid_until, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$code, $type, $value, $minAmount, $limit, $validFrom, $validUntil, $isActive]
                );
                auditLog('ADMIN_COUPON_CREATED', "Created coupon code: $code", 'coupons', Database::lastInsertId());
                $success = "Coupon created successfully.";
            }
        } 
        
        elseif ($action === 'delete_coupon') {
            $id = (int)post('id');
            if ($id > 0) {
                Database::execute("DELETE FROM coupons WHERE id = ?", [$id]);
                auditLog('ADMIN_COUPON_DELETED', "Deleted coupon ID $id", 'coupons', $id);
                $success = "Coupon deleted successfully.";
            }
        }

        redirectTo('admin/coupons');

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch coupons
$coupons = Database::fetchAll("SELECT * FROM coupons ORDER BY created_at DESC");

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
            <div class="admin-topbar-right">
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#couponModal">
                    <i class="fas fa-plus me-1"></i> Add Coupon
                </button>
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

            <!-- Table -->
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Type</th>
                            <th>Value</th>
                            <th>Min Spend Limit</th>
                            <th>Usage Limit / Count</th>
                            <th>Valid Period</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($coupons)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">No coupon codes generated.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($coupons as $c): ?>
                                <tr>
                                    <td><span class="fw-bold font-monospace"><?= e($c['code']) ?></span></td>
                                    <td><span class="badge bg-light text-dark border"><?= ucfirst($c['type']) ?></span></td>
                                    <td class="fw-bold"><?= $c['type'] === 'fixed' ? formatMoney($c['value']) : $c['value'] . '%' ?></td>
                                    <td><?= formatMoney($c['min_amount']) ?></td>
                                    <td>
                                        <div class="small">Limit: <?= (int)$c['usage_limit'] ?></div>
                                        <div class="small text-muted">Used: <?= (int)$c['used_count'] ?></div>
                                    </td>
                                    <td>
                                        <div class="small">From: <?= date('d M Y', strtotime($c['valid_from'])) ?></div>
                                        <div class="small text-muted">Until: <?= date('d M Y', strtotime($c['valid_until'])) ?></div>
                                    </td>
                                    <td>
                                        <span class="admin-badge admin-badge-<?= $c['is_active'] ? 'success' : 'pending' ?>">
                                            <?= $c['is_active'] ? 'Active' : 'Disabled' ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-1">
                                            <button class="action-btn action-btn-edit btn-edit-coupon" 
                                                    data-id="<?= $c['id'] ?>"
                                                    data-code="<?= e($c['code']) ?>"
                                                    data-type="<?= $c['type'] ?>"
                                                    data-value="<?= $c['value'] ?>"
                                                    data-min="<?= $c['min_amount'] ?>"
                                                    data-limit="<?= $c['usage_limit'] ?>"
                                                    data-from="<?= date('Y-m-d\TH:i', strtotime($c['valid_from'])) ?>"
                                                    data-until="<?= date('Y-m-d\TH:i', strtotime($c['valid_until'])) ?>"
                                                    data-active="<?= $c['is_active'] ?>"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#couponModal">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <form method="POST" action="<?= APP_URL ?>/admin/coupons" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete_coupon">
                                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                <button type="submit" class="action-btn action-btn-delete" onclick="return confirm('Delete coupon code?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
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

<!-- Modal: Save Coupon -->
<div class="modal fade" id="couponModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-16 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
                <h5 class="modal-title fw-bold" id="modalTitle">Add Coupon</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= APP_URL ?>/admin/coupons">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_coupon">
                <input type="hidden" id="coupon_id" name="id" value="0">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="code" class="form-label small">Coupon Code</label>
                        <input type="text" class="form-control font-monospace fw-bold" id="code" name="code" placeholder="e.g. WELCOME500" required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="type" class="form-label small">Coupon Type</label>
                            <select class="form-select" id="type" name="type">
                                <option value="fixed">Fixed Cash (₦)</option>
                                <option value="percentage">Percentage (%)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="value" class="form-label small">Value</label>
                            <input type="number" step="0.01" class="form-control" id="value" name="value" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="min_amount" class="form-label small">Min Wallet Spend (₦)</label>
                            <input type="number" class="form-control" id="min_amount" name="min_amount" value="0">
                        </div>
                        <div class="col-md-6">
                            <label for="usage_limit" class="form-label small">Usage Limit Count</label>
                            <input type="number" class="form-control" id="usage_limit" name="usage_limit" value="1">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="valid_from" class="form-label small">Valid From</label>
                            <input type="datetime-local" class="form-control" id="valid_from" name="valid_from">
                        </div>
                        <div class="col-md-6">
                            <label for="valid_until" class="form-label small">Valid Until</label>
                            <input type="datetime-local" class="form-control" id="valid_until" name="valid_until">
                        </div>
                    </div>

                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label small" for="is_active">Activate Code</label>
                    </div>
                </div>

                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background-color: #4f46e5; border: none;">Save Coupon</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#couponModal').on('show.bs.modal', function (event) {
        const button = $(event.relatedTarget);
        if (!button.hasClass('btn-edit-coupon')) {
            $('#modalTitle').text('Add Coupon');
            $('#coupon_id').val('0');
            $('#code').val('');
            $('#type').val('fixed');
            $('#value').val('');
            $('#min_amount').val('0');
            $('#usage_limit').val('1');
            $('#is_active').prop('checked', true);
        }
    });

    $('.btn-edit-coupon').on('click', function() {
        $('#modalTitle').text('Edit Coupon');
        $('#coupon_id').val($(this).attr('data-id'));
        $('#code').val($(this).attr('data-code'));
        $('#type').val($(this).attr('data-type'));
        $('#value').val($(this).attr('data-value'));
        $('#min_amount').val($(this).attr('data-min'));
        $('#usage_limit').val($(this).attr('data-limit'));
        $('#valid_from').val($(this).attr('data-from'));
        $('#valid_until').val($(this).attr('data-until'));
        $('#is_active').prop('checked', $(this).attr('data-active') == '1');
    });
});
</script>
<?php include ADMIN_PATH . '/includes/footer.php'; ?>
