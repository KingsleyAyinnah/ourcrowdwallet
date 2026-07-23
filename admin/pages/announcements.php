<?php
/**
 * OURCR ONLINE - Admin Panel Announcements Manager
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'Bulletin Announcements';
$error = '';
$success = '';

// Handle POST: Create / Update / Delete Announcement
if (isPost()) {
    try {
        requireCsrf();
        $action = post('action');

        if ($action === 'save_announcement') {
            $id       = (int)post('id');
            $title    = sanitizeString(post('title'));
            $msg      = sanitizeString(post('message'));
            $type     = sanitizeString(post('type', 'info'));
            $target   = sanitizeString(post('target', 'all'));
            $startsAt = post('starts_at') ?: date('Y-m-d H:i:s');
            $endsAt   = post('ends_at') ?: date('Y-m-d H:i:s', strtotime('+30 days'));
            $isActive = (int)post('is_active', 0);

            if (empty($title) || empty($msg)) {
                throw new Exception("Title and message body are required.");
            }

            if ($id > 0) {
                // Update
                Database::execute(
                    "UPDATE announcements 
                     SET title = ?, message = ?, type = ?, target = ?, starts_at = ?, ends_at = ?, is_active = ?, updated_at = NOW()
                     WHERE id = ?",
                    [$title, $msg, $type, $target, $startsAt, $endsAt, $isActive, $id]
                );
                auditLog('ADMIN_ANNOUNCEMENT_UPDATED', "Updated announcement: $title", 'announcements', $id);
                $success = "Announcement updated successfully.";
            } else {
                // Insert
                Database::insert(
                    "INSERT INTO announcements (title, message, type, target, starts_at, ends_at, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$title, $msg, $type, $target, $startsAt, $endsAt, $isActive]
                );
                auditLog('ADMIN_ANNOUNCEMENT_CREATED', "Created announcement: $title", 'announcements', Database::lastInsertId());
                $success = "Announcement published successfully.";
            }
        } 
        
        elseif ($action === 'delete_announcement') {
            $id = (int)post('id');
            if ($id > 0) {
                Database::execute("DELETE FROM announcements WHERE id = ?", [$id]);
                auditLog('ADMIN_ANNOUNCEMENT_DELETED', "Deleted announcement ID $id", 'announcements', $id);
                $success = "Announcement deleted successfully.";
            }
        }

        redirectTo('admin/announcements');

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch all announcements
$announcements = Database::fetchAll("SELECT * FROM announcements ORDER BY created_at DESC");

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
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#announcementModal">
                    <i class="fas fa-plus me-1"></i> Add Announcement
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
                            <th>Title</th>
                            <th>Message</th>
                            <th>Display Accent</th>
                            <th>Target Scope</th>
                            <th>Valid Dates</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($announcements)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">No announcements listed.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($announcements as $ann): ?>
                                <tr>
                                    <td><span class="fw-bold"><?= e($ann['title']) ?></span></td>
                                    <td><span class="small"><?= e($ann['message']) ?></span></td>
                                    <td><span class="badge bg-light text-<?= $ann['type'] ?> border"><?= ucfirst($ann['type']) ?></span></td>
                                    <td><span class="small"><?= ucfirst($ann['target']) ?></span></td>
                                    <td>
                                        <div class="small">Starts: <?= date('d M Y', strtotime($ann['starts_at'])) ?></div>
                                        <div class="small text-muted">Ends: <?= date('d M Y', strtotime($ann['ends_at'])) ?></div>
                                    </td>
                                    <td>
                                        <span class="admin-badge admin-badge-<?= $ann['is_active'] ? 'success' : 'pending' ?>">
                                            <?= $ann['is_active'] ? 'Active' : 'Draft' ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-1">
                                            <button class="action-btn action-btn-edit btn-edit-ann" 
                                                    data-id="<?= $ann['id'] ?>"
                                                    data-title="<?= e($ann['title']) ?>"
                                                    data-message="<?= e($ann['message']) ?>"
                                                    data-type="<?= $ann['type'] ?>"
                                                    data-target="<?= $ann['target'] ?>"
                                                    data-starts="<?= date('Y-m-d\TH:i', strtotime($ann['starts_at'])) ?>"
                                                    data-ends="<?= date('Y-m-d\TH:i', strtotime($ann['ends_at'])) ?>"
                                                    data-active="<?= $ann['is_active'] ?>"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#announcementModal">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <form method="POST" action="<?= APP_URL ?>/admin/announcements" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete_announcement">
                                                <input type="hidden" name="id" value="<?= $ann['id'] ?>">
                                                <button type="submit" class="action-btn action-btn-delete" onclick="return confirm('Delete announcement bulletin?')">
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

<!-- Modal: Save Announcement -->
<div class="modal fade" id="announcementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-16 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
                <h5 class="modal-title fw-bold" id="modalTitle">Add Announcement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= APP_URL ?>/admin/announcements">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_announcement">
                <input type="hidden" id="ann_id" name="id" value="0">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="title" class="form-label small">Title</label>
                        <input type="text" class="form-control" id="title" name="title" required>
                    </div>

                    <div class="mb-3">
                        <label for="message" class="form-label small">Message Text</label>
                        <textarea class="form-control" id="message" name="message" rows="3" required></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="type" class="form-label small">Accent Color Group</label>
                        <select class="form-select" id="type" name="type">
                            <option value="info">Info (Blue)</option>
                            <option value="success">Success (Green)</option>
                            <option value="warning">Warning (Yellow)</option>
                            <option value="danger">Danger (Red)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="target" class="form-label small">Target Audience Scope</label>
                        <select class="form-select" id="target" name="target">
                            <option value="all">All (Public + Users)</option>
                            <option value="user">Users (Logged In Customers)</option>
                            <option value="admin">Administrators (Staff Only)</option>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="starts_at" class="form-label small">Starts At</label>
                            <input type="datetime-local" class="form-control" id="starts_at" name="starts_at">
                        </div>
                        <div class="col-md-6">
                            <label for="ends_at" class="form-label small">Ends At</label>
                            <input type="datetime-local" class="form-control" id="ends_at" name="ends_at">
                        </div>
                    </div>

                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label small" for="is_active">Publish Live</label>
                    </div>
                </div>

                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background-color: #4f46e5; border: none;">Save Bulletin</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Add reset on modal open
    $('#announcementModal').on('show.bs.modal', function (event) {
        const button = $(event.relatedTarget);
        if (!button.hasClass('btn-edit-ann')) {
            $('#modalTitle').text('Add Announcement');
            $('#ann_id').val('0');
            $('#title').val('');
            $('#message').val('');
            $('#type').val('info');
            $('#target').val('all');
            $('#is_active').prop('checked', true);
        }
    });

    // Populate for edit
    $('.btn-edit-ann').on('click', function() {
        $('#modalTitle').text('Edit Announcement');
        $('#ann_id').val($(this).attr('data-id'));
        $('#title').val($(this).attr('data-title'));
        $('#message').val($(this).attr('data-message'));
        $('#type').val($(this).attr('data-type'));
        $('#target').val($(this).attr('data-target'));
        $('#starts_at').val($(this).attr('data-starts'));
        $('#ends_at').val($(this).attr('data-ends'));
        $('#is_active').prop('checked', $(this).attr('data-active') == '1');
    });
});
</script>
<?php include ADMIN_PATH . '/includes/footer.php'; ?>
