<?php
/**
 * OURCR ONLINE - Admin Panel VTpass API Logs
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'VTpass API Logs';

$page = get('page') ? max(1, (int)get('page')) : 1;

// Get filters
$service = get('service') ?: null;
$status  = get('status') ?: null;

// JSON details viewer handler
$logId = get('view_id') ? (int)get('view_id') : 0;
if (isAjax() && $logId > 0) {
    $log = Database::fetchOne("SELECT request_payload, response_payload FROM vtpass_transactions WHERE id = ? LIMIT 1", [$logId]);
    if ($log) {
        $req = json_decode($log['request_payload'], true) ?: $log['request_payload'];
        $res = json_decode($log['response_payload'], true) ?: $log['response_payload'];
        jsonResponse([
            'success' => true,
            'request' => $req,
            'response' => $res
        ]);
    }
    jsonResponse(['success' => false, 'message' => 'Log entry not found.'], 404);
}

// Build query
$params = [];
$where = ["1=1"];

if ($service) {
    $where[] = "vt.service_type = ?";
    $params[] = $service;
}
if ($status) {
    $where[] = "vt.status = ?";
    $params[] = $status;
}

$whereClause = implode(" AND ", $where);

// Count
$countQuery = "SELECT COUNT(vt.id) as count FROM vtpass_transactions vt WHERE $whereClause";
$total = (int)Database::fetchOne($countQuery, $params)['count'];

// Paginate
$offset = ($page - 1) * 20;
$query = "SELECT vt.*, u.username 
          FROM vtpass_transactions vt 
          LEFT JOIN users u ON vt.user_id = u.id 
          WHERE $whereClause 
          ORDER BY vt.created_at DESC 
          LIMIT 20 OFFSET $offset";
$logs = Database::fetchAll($query, $params);

$lastPage = ceil($total / 20);

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
            
            <!-- Filters -->
            <div class="filter-bar">
                <form method="GET" action="<?= APP_URL ?>/admin/vtpass-logs" class="d-flex flex-wrap gap-2 w-100 align-items-center">
                    <div style="min-width: 160px;">
                        <select class="form-select" name="service">
                            <option value="">All Services</option>
                            <option value="airtime" <?= $service === 'airtime' ? 'selected' : '' ?>>Airtime</option>
                            <option value="data" <?= $service === 'data' ? 'selected' : '' ?>>Data</option>
                            <option value="cable" <?= $service === 'cable' ? 'selected' : '' ?>>Cable TV</option>
                            <option value="electricity" <?= $service === 'electricity' ? 'selected' : '' ?>>Electricity</option>
                            <option value="betting" <?= $service === 'betting' ? 'selected' : '' ?>>Betting</option>
                            <option value="exam" <?= $service === 'exam' ? 'selected' : '' ?>>Exam PINs</option>
                        </select>
                    </div>
                    <div style="min-width: 140px;">
                        <select class="form-select" name="status">
                            <option value="">All Statuses</option>
                            <option value="success" <?= $status === 'success' ? 'selected' : '' ?>>Success</option>
                            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="background-color: #4f46e5; border: none; height: 38px;">Filter</button>
                    <a href="<?= APP_URL ?>/admin/vtpass-logs" class="btn btn-outline-secondary d-flex align-items-center justify-content-center" style="height: 38px;">Reset</a>
                </form>
            </div>

            <!-- Table -->
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>User</th>
                            <th>Service</th>
                            <th>Amount</th>
                            <th>Operator Ref</th>
                            <th>Status</th>
                            <th>Date / Time</th>
                            <th class="text-end">Payload</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">No VTpass API logs found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $l): ?>
                                <tr>
                                    <td class="small font-monospace"><?= e($l['request_id']) ?></td>
                                    <td><span class="fw-bold">@<?= e($l['username']) ?></span></td>
                                    <td><span class="badge bg-light text-dark border"><?= ucfirst($l['service_type']) ?></span></td>
                                    <td class="fw-bold"><?= formatMoney($l['amount']) ?></td>
                                    <td class="small text-muted font-monospace"><?= e($l['vtpass_reference'] ?: '-') ?></td>
                                    <td>
                                        <span class="admin-badge admin-badge-<?= $l['status'] ?>">
                                            <?= ucfirst($l['status']) ?>
                                        </span>
                                    </td>
                                    <td class="small"><?= date('d M Y, h:i A', strtotime($l['created_at'])) ?></td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-light border btn-view-payload" data-id="<?= $l['id'] ?>" data-bs-toggle="modal" data-bs-target="#payloadModal">
                                            <i class="fas fa-code"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($lastPage > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $lastPage; $i++): ?>
                            <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                                <a class="page-link" href="<?= APP_URL ?>/admin/vtpass-logs?page=<?= $i ?>&service=<?= e($service) ?>&status=<?= e($status) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>

        </div>
    </main>
</div>

<!-- Modal: JSON Payload -->
<div class="modal fade" id="payloadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-16 border-0 shadow-lg">
            <div class="modal-header border-bottom p-4">
                <h5 class="modal-title fw-bold">VTpass API Payload Metadata</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <h6 class="fw-bold text-primary mb-2">Request Body</h6>
                <pre class="bg-light p-3 rounded small" id="json_request" style="max-height: 200px; overflow-y: auto;">-</pre>

                <h6 class="fw-bold text-success mb-2 mt-4">Response Body</h6>
                <pre class="bg-light p-3 rounded small" id="json_response" style="max-height: 200px; overflow-y: auto;">-</pre>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.btn-view-payload').on('click', function(e) {
        e.preventDefault();
        const id = $(this).attr('data-id');
        
        $('#json_request').text('Loading...');
        $('#json_response').text('Loading...');
        
        $.ajax({
            url: '<?= APP_URL ?>/admin/vtpass-logs?view_id=' + id,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#json_request').text(JSON.stringify(response.request, null, 4));
                    $('#json_response').text(JSON.stringify(response.response, null, 4));
                } else {
                    $('#json_request').text('Error: ' + response.message);
                }
            },
            error: function() {
                $('#json_request').text('Failed to fetch payload logs.');
            }
        });
    });
});
</script>
<?php include ADMIN_PATH . '/includes/footer.php'; ?>
