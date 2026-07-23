<?php
/**
 * OURCR ONLINE - Help Desk & Support Ticketing
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'Support Center';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

$error = '';
$success = '';

// Handle POST: Create ticket or reply
if (isPost()) {
    try {
        requireCsrf();
        $action = post('action');

        if ($action === 'create_ticket') {
            $subject  = sanitizeString(post('subject'));
            $priority = sanitizeString(post('priority', 'medium'));
            $message  = sanitizeString(post('message'));

            if (empty($subject) || empty($message)) {
                setFlash('error', "Subject and message are required.");
                redirectTo('support');
            } else {
                $ticketNo = generateTicketNumber();
                
                // Create support ticket
                $ticketId = Database::insert(
                    "INSERT INTO support_tickets (ticket_number, user_id, subject, priority, status)
                     VALUES (?, ?, ?, ?, 'open')",
                    [$ticketNo, $user['id'], $subject, $priority]
                );

                // Insert initial message
                Database::insert(
                    "INSERT INTO support_messages (ticket_id, sender_id, message, is_admin)
                     VALUES (?, ?, ?, 0)",
                    [$ticketId, $user['id'], $message]
                );

                auditLog('TICKET_CREATED', "Created ticket $ticketNo: $subject", 'support_tickets', $ticketId);
                
                setFlash('success', "Ticket $ticketNo has been created.");
                redirectTo('support');
            }
        } 
        
        elseif ($action === 'reply_ticket') {
            $ticketId = (int)post('ticket_id');
            $message  = sanitizeString(post('message'));

            if (empty($message) || $ticketId <= 0) {
                setFlash('error', "Message content is required.");
                redirectTo('support' . ($ticketId > 0 ? '?ticket_id=' . $ticketId : ''));
            } else {
                // Confirm ticket belongs to user
                $ticket = Database::fetchOne(
                    "SELECT id, ticket_number, status FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1",
                    [$ticketId, $user['id']]
                );

                if ($ticket) {
                    Database::insert(
                        "INSERT INTO support_messages (ticket_id, sender_id, message, is_admin)
                         VALUES (?, ?, ?, 0)",
                        [$ticketId, $user['id'], $message]
                    );

                    // Re-open ticket if closed or resolved
                    if (in_array($ticket['status'], ['resolved', 'closed'])) {
                        Database::execute(
                            "UPDATE support_tickets SET status = 'open' WHERE id = ?",
                            [$ticketId]
                        );
                    } else {
                        // Just update the updated_at timestamp
                        Database::execute(
                            "UPDATE support_tickets SET updated_at = NOW() WHERE id = ?",
                            [$ticketId]
                        );
                    }

                    setFlash('success', 'Reply submitted successfully.');
                    redirectTo('support?ticket_id=' . $ticketId);
                } else {
                    setFlash('error', "Invalid ticket selection.");
                    redirectTo('support');
                }
            }
        }

    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Support exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine()
        ]);
        setFlash('error', 'A technical error occurred. Please try again.');
        redirectTo('support');
    }
}

// Get user tickets
$tickets = Database::fetchAll(
    "SELECT * FROM support_tickets WHERE user_id = ? ORDER BY updated_at DESC",
    [$user['id']]
);

// Get selected ticket details
$selectedTicketId = get('ticket_id') ? (int)get('ticket_id') : 0;
$selectedTicket = null;
$messages = [];

if ($selectedTicketId > 0) {
    $selectedTicket = Database::fetchOne(
        "SELECT * FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1",
        [$selectedTicketId, $user['id']]
    );

    if ($selectedTicket) {
        $messages = Database::fetchAll(
            "SELECT sm.*, u.first_name, u.last_name, u.avatar 
             FROM support_messages sm
             LEFT JOIN users u ON sm.sender_id = u.id
             WHERE sm.ticket_id = ?
             ORDER BY sm.created_at ASC",
            [$selectedTicketId]
        );
    }
}

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            <div class="fade-in-up">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h4 class="fw-bold">Support Center</h4>
                        <p class="text-muted small">File help logs or check conversation logs with administrators.</p>
                    </div>
                    <button class="btn btn-danger bg-site-color border-0 py-2 px-4 rounded-pill fw-bold" data-bs-toggle="modal" data-bs-target="#newTicketModal">
                        <i class="fas fa-plus me-1"></i> New Ticket
                    </button>
                </div>



                <div class="row">
                    <!-- Ticket list (Left Column) -->
                    <div class="col-lg-4">
                        <div class="card border-0 shadow-sm rounded-16 mb-4">
                            <div class="card-body p-4">
                                <h5 class="fw-bold mb-3">Support Logs</h5>

                                <?php if (empty($tickets)): ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="fas fa-folder-open fa-2x mb-2" style="opacity: 0.3;"></i>
                                        <p class="small mb-0">No tickets generated yet.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                                        <?php foreach ($tickets as $t): ?>
                                            <a href="<?= APP_URL ?>/support?ticket_id=<?= $t['id'] ?>" 
                                               class="list-group-item list-group-item-action py-3 border-bottom <?= $selectedTicketId === (int)$t['id'] ? 'bg-light fw-bold text-site-color' : '' ?>">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <span class="small font-monospace"><?= e($t['ticket_number']) ?></span>
                                                    <span class="badge badge-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span>
                                                </div>
                                                <div class="small text-truncate"><?= e($t['subject']) ?></div>
                                                <span class="fs-8 text-muted float-end mt-1"><?= formatDate($t['updated_at']) ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Chat conversation thread (Right Column) -->
                    <div class="col-lg-8">
                        <?php if ($selectedTicket): ?>
                            <div class="card border-0 shadow-sm rounded-16 mb-4">
                                <div class="card-header bg-white border-0 p-4 pb-0">
                                    <div class="d-flex justify-content-between align-items-center border-bottom pb-3">
                                        <div>
                                            <h5 class="fw-bold mb-1"><?= e($selectedTicket['subject']) ?></h5>
                                            <span class="small text-muted">Ticket: <?= e($selectedTicket['ticket_number']) ?> | Priority: <?= ucfirst($selectedTicket['priority']) ?></span>
                                        </div>
                                        <span class="badge badge-<?= $selectedTicket['status'] ?> py-2 px-3 rounded-pill"><?= ucfirst($selectedTicket['status']) ?></span>
                                    </div>
                                </div>
                                <div class="card-body p-4" style="height: 340px; overflow-y: auto; background-color: #f8fafc;" id="chatThread">
                                    <?php foreach ($messages as $msg): ?>
                                        <?php
                                        $isAdmin = (bool)$msg['is_admin'];
                                        $alignClass = $isAdmin ? 'justify-content-start' : 'justify-content-end';
                                        $bubbleClass = $isAdmin ? 'bg-light border text-dark' : 'text-white';
                                        $bubbleBg = $isAdmin ? '' : 'background-color: var(--site-color);';
                                        ?>
                                        <div class="d-flex <?= $alignClass ?> mb-3">
                                            <div class="p-3 rounded-16 shadow-sm max-width-500 <?= $bubbleClass ?>" style="<?= $bubbleBg ?>">
                                                <div class="fw-bold small mb-1"><?= $isAdmin ? 'Support Agent' : 'You' ?></div>
                                                <p class="mb-1 small"><?= e($msg['message']) ?></p>
                                                <span class="fs-8 text-muted d-block text-end"><?= date('h:i A', strtotime($msg['created_at'])) ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="card-footer bg-white border-0 p-4">
                                    <form method="POST" action="<?= APP_URL ?>/support">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="reply_ticket">
                                        <input type="hidden" name="ticket_id" value="<?= $selectedTicket['id'] ?>">

                                        <div class="input-group">
                                            <input type="text" class="form-control" name="message" placeholder="Type your reply message..." required>
                                            <button class="btn btn-danger bg-site-color border-0 px-4" type="submit">
                                                Send <i class="fas fa-paper-plane ms-1"></i>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card border-0 shadow-sm rounded-16 mb-4 text-center py-5">
                                <div class="card-body py-5 text-muted">
                                    <i class="fas fa-headset fa-4x mb-3" style="opacity: 0.3;"></i>
                                    <h5>Select a Ticket Logs</h5>
                                    <p class="small mb-0">Select a support ticket log from the left side panel list to view messages thread.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Modal: New Ticket -->
<div class="modal fade" id="newTicketModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-16 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
                <h5 class="modal-title fw-bold">Create Support Ticket</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= APP_URL ?>/support">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create_ticket">

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="subject" class="form-label small">Ticket Subject</label>
                        <input type="text" class="form-control" id="subject" name="subject" placeholder="Summarize your issue" required>
                    </div>

                    <div class="mb-3">
                        <label for="priority" class="form-label small">Priority</label>
                        <select class="form-select" id="priority" name="priority">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="message" class="form-label small">Detailed Message</label>
                        <textarea class="form-control" id="message" name="message" rows="4" placeholder="Explain the issue in detail" required></textarea>
                    </div>
                </div>

                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger bg-site-color border-0 px-4">Submit Log</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Auto-scroll chat thread to bottom -->
<script>
$(document).ready(function() {
    const chat = document.getElementById('chatThread');
    if (chat) {
        chat.scrollTop = chat.scrollHeight;
    }
});
</script>

<?php include INCLUDES_PATH . '/footer.php'; ?>
