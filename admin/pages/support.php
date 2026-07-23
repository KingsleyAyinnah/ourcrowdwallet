<?php
/**
 * OURCR ONLINE - Admin Panel Help Desk & Support Ticketing
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'Support Desk Tickets';
$error = '';
$success = '';

// Handle POST actions: Reply or Close Ticket
if (isPost()) {
    try {
        requireCsrf();
        $action = post('action');

        if ($action === 'reply_ticket') {
            $ticketId = (int)post('ticket_id');
            $message  = sanitizeString(post('message'));

            if (empty($message) || $ticketId <= 0) {
                throw new Exception("Message content is required.");
            }

            $ticket = Database::fetchOne("SELECT * FROM support_tickets WHERE id = ? LIMIT 1", [$ticketId]);
            if ($ticket) {
                // Insert message
                Database::insert(
                    "INSERT INTO support_messages (ticket_id, sender_id, message, is_admin)
                     VALUES (?, ?, ?, 1)",
                    [$ticketId, currentUserId(), $message]
                );

                // Update ticket status
                Database::execute(
                    "UPDATE support_tickets SET status = 'replied', updated_at = NOW() WHERE id = ?",
                    [$ticketId]
                );

                sendNotification(
                    $ticket['user_id'],
                    NOTIF_INFO,
                    'Support Team Replied',
                    "You received a reply on support ticket: {$ticket['ticket_number']}.",
                    APP_URL . '/support?ticket_id=' . $ticketId
                );

                auditLog('ADMIN_TICKET_REPLIED', "Replied to support ticket ID $ticketId", 'support_tickets', $ticketId);
                $success = "Reply submitted successfully.";
            } else {
                throw new Exception("Support ticket not found.");
            }
        } 
        
        elseif ($action === 'close_ticket') {
            $ticketId = (int)post('ticket_id');
            if ($ticketId > 0) {
                Database::execute(
                    "UPDATE support_tickets SET status = 'closed', updated_at = NOW() WHERE id = ?",
                    [$ticketId]
                );
                auditLog('ADMIN_TICKET_CLOSED', "Closed support ticket ID $ticketId", 'support_tickets', $ticketId);
                $success = "Ticket marked closed.";
            }
        }

        redirectTo('admin/support?ticket_id=' . $ticketId);

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch tickets
$statusFilter = get('status') ?: 'open';
$params = [];
$where = [];
if ($statusFilter !== 'all') {
    $where[] = "st.status = ?";
    $params[] = $statusFilter;
}
$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$query = "SELECT st.*, u.username 
          FROM support_tickets st 
          LEFT JOIN users u ON st.user_id = u.id 
          $whereClause 
          ORDER BY st.updated_at DESC";
$tickets = Database::fetchAll($query, $params);

// Fetch selected ticket messages
$selectedTicketId = get('ticket_id') ? (int)get('ticket_id') : 0;
$selectedTicket = null;
$messages = [];

if ($selectedTicketId > 0) {
    $selectedTicket = Database::fetchOne(
        "SELECT st.*, u.username, u.first_name, u.last_name 
         FROM support_tickets st 
         LEFT JOIN users u ON st.user_id = u.id 
         WHERE st.id = ? 
         LIMIT 1",
        [$selectedTicketId]
    );

    if ($selectedTicket) {
        $messages = Database::fetchAll(
            "SELECT sm.*, u.first_name, u.last_name, u.role
             FROM support_messages sm
             LEFT JOIN users u ON sm.sender_id = u.id
             WHERE sm.ticket_id = ?
             ORDER BY sm.created_at ASC",
            [$selectedTicketId]
        );
    }
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

            <div class="row">
                <!-- Ticket List (Left Column) -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm rounded-12 p-3 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold mb-0">Help Desk Queue</h5>
                            <select class="form-select form-select-sm w-auto" onchange="location = this.value;">
                                <option value="<?= APP_URL ?>/admin/support?status=open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Open</option>
                                <option value="<?= APP_URL ?>/admin/support?status=replied" <?= $statusFilter === 'replied' ? 'selected' : '' ?>>Replied</option>
                                <option value="`<?= APP_URL ?>/admin/support?status=closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Closed</option>
                                <option value="<?= APP_URL ?>/admin/support?status=all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Tickets</option>
                            </select>
                        </div>

                        <?php if (empty($tickets)): ?>
                            <p class="text-muted text-center py-4 small">No tickets in this queue.</p>
                        <?php else: ?>
                            <div class="list-group list-group-flush" style="max-height: 450px; overflow-y: auto;">
                                <?php foreach ($tickets as $t): ?>
                                    <a href="<?= APP_URL ?>/admin/support?ticket_id=<?= $t['id'] ?>&status=<?= $statusFilter ?>" 
                                       class="list-group-item list-group-item-action py-3 px-1 border-bottom <?= $selectedTicketId === (int)$t['id'] ? 'bg-light fw-bold text-primary' : '' ?>">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="small font-monospace"><?= e($t['ticket_number']) ?></span>
                                            <span class="badge badge-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span>
                                        </div>
                                        <div class="small text-truncate">@<?= e($t['username']) ?>: <?= e($t['subject']) ?></div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Chat Thread (Right Column) -->
                <div class="col-lg-8">
                    <?php if ($selectedTicket): ?>
                        <div class="card border-0 shadow-sm rounded-12 mb-4">
                            <div class="card-header bg-white border-0 p-4 pb-0">
                                <div class="d-flex justify-content-between align-items-center border-bottom pb-3">
                                    <div>
                                        <h5 class="fw-bold mb-1">@<?= e($selectedTicket['username']) ?> — <?= e($selectedTicket['subject']) ?></h5>
                                        <span class="small text-muted">Ticket Ref: <?= e($selectedTicket['ticket_number']) ?> | Owner: <?= e($selectedTicket['first_name'] . ' ' . $selectedTicket['last_name']) ?></span>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <span class="badge badge-<?= $selectedTicket['status'] ?> py-2 px-3 rounded-pill"><?= ucfirst($selectedTicket['status']) ?></span>
                                        <?php if ($selectedTicket['status'] !== 'closed'): ?>
                                            <form method="POST" action="<?= APP_URL ?>/admin/support">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="close_ticket">
                                                <input type="hidden" name="ticket_id" value="<?= $selectedTicket['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Mark ticket closed?')">Close</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-4" style="height: 350px; overflow-y: auto; background-color: #f8fafc;" id="adminChatThread">
                                <?php foreach ($messages as $msg): ?>
                                    <?php
                                    $isAdmin = (bool)$msg['is_admin'];
                                    $alignClass = $isAdmin ? 'justify-content-end' : 'justify-content-start';
                                    $bubbleClass = $isAdmin ? 'text-white' : 'bg-light border text-dark';
                                    $bubbleBg = $isAdmin ? 'background-color: #4f46e5;' : '';
                                    ?>
                                    <div class="d-flex <?= $alignClass ?> mb-3">
                                        <div class="p-3 rounded-16 shadow-sm max-width-500 <?= $bubbleClass ?>" style="<?= $bubbleBg ?>">
                                            <div class="fw-bold small mb-1"><?= $isAdmin ? 'Support Agent (' . e($msg['first_name']) . ')' : 'Customer' ?></div>
                                            <p class="mb-1 small"><?= e($msg['message']) ?></p>
                                            <span class="fs-8 text-muted d-block text-end"><?= date('h:i A', strtotime($msg['created_at'])) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="card-footer bg-white border-0 p-4">
                                <form method="POST" action="<?= APP_URL ?>/admin/support">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="reply_ticket">
                                    <input type="hidden" name="ticket_id" value="<?= $selectedTicket['id'] ?>">

                                    <div class="input-group">
                                        <input type="text" class="form-control" name="message" placeholder="Type administrative response here..." required>
                                        <button class="btn btn-primary px-4" type="submit" style="background-color: #4f46e5; border: none;">
                                            Send Reply <i class="fas fa-paper-plane ms-1"></i>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card border-0 shadow-sm rounded-12 mb-4 text-center py-5">
                            <div class="card-body py-5 text-muted">
                                <i class="fas fa-headset fa-4x mb-3" style="opacity: 0.3;"></i>
                                <h5>No Ticket Selected</h5>
                                <p class="small mb-0">Select an active support log card from the left-side panel to open and chat.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</div>

<script>
$(document).ready(function() {
    const chat = document.getElementById('adminChatThread');
    if (chat) {
        chat.scrollTop = chat.scrollHeight;
    }
});
</script>
<?php include ADMIN_PATH . '/includes/footer.php'; ?>
