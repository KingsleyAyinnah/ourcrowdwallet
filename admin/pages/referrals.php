<?php
/**
 * OURCR ONLINE - Admin Panel Referrals tracking
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAdmin();

$adminPageTitle = 'Referral Commission Logs';

$page   = get('page') ? max(1, (int)get('page')) : 1;
$offset = ($page - 1) * 25;

// ── Metrics ──────────────────────────────────────────────────────────────────
$refBonusCount   = (int)(Database::fetchOne(
    "SELECT COUNT(id) as c FROM referrals WHERE status = 'paid'"
)['c'] ?? 0);

$totalRefPayout  = (float)(Database::fetchOne(
    "SELECT COALESCE(SUM(bonus_amount), 0) as t FROM referrals WHERE status = 'paid'"
)['t'] ?? 0);

$pendingCount    = (int)(Database::fetchOne(
    "SELECT COUNT(id) as c FROM referrals WHERE status = 'pending'"
)['c'] ?? 0);

// ── Fetch referrals with per-referred-user total bonus earned ─────────────────
$referrals = Database::fetchAll(
    "SELECT
        r.id,
        r.referrer_id,
        r.referred_id,
        r.bonus_amount,
        r.status,
        r.created_at,
        r.paid_at,
        u1.username  AS referrer_username,
        u1.email     AS referrer_email,
        u2.username  AS referred_username,
        u2.email     AS referred_email,
        u2.email_verified_at,
        u2.status    AS referred_user_status,
        IF(r.status = 'paid', r.bonus_amount, 0) AS signup_bonus_paid,
        COALESCE((
            SELECT SUM(wt.amount)
            FROM wallet_transactions wt
            WHERE wt.user_id   = r.referrer_id
              AND wt.category  = 'referral_bonus'
              AND wt.status    = 'success'
              AND wt.reference LIKE 'RDC%'
              AND (
                  JSON_EXTRACT(wt.meta, '$.referred_id') = r.referred_id
                  OR JSON_UNQUOTE(JSON_EXTRACT(wt.meta, '$.referred_id')) = r.referred_id
              )
        ), 0) AS ongoing_bonus_total
     FROM referrals r
     LEFT JOIN users u1 ON r.referrer_id = u1.id
     LEFT JOIN users u2 ON r.referred_id = u2.id
     ORDER BY r.created_at DESC
     LIMIT 25 OFFSET $offset"
);

$total    = (int)(Database::fetchOne("SELECT COUNT(id) as c FROM referrals")['c'] ?? 0);
$lastPage = max(1, (int)ceil($total / 25));

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
            
            <!-- Metrics Row -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-12 p-3 text-center border-start border-4 border-success">
                        <span class="small text-muted mb-1 d-block">Paid Commissions</span>
                        <span class="h3 fw-bold text-success mb-0"><?= $refBonusCount ?></span>
                        <span class="small text-muted">referrers rewarded</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-12 p-3 text-center border-start border-4 border-primary">
                        <span class="small text-muted mb-1 d-block">Total Commissions Paid</span>
                        <span class="h3 fw-bold text-primary mb-0"><?= formatMoney($totalRefPayout) ?></span>
                        <span class="small text-muted">across all referrals</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-12 p-3 text-center border-start border-4 border-warning">
                        <span class="small text-muted mb-1 d-block">Pending Verifications</span>
                        <span class="h3 fw-bold text-warning mb-0"><?= $pendingCount ?></span>
                        <span class="small text-muted">awaiting email verify</span>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Referrer</th>
                            <th>Referred User</th>
                            <th>Referred Status</th>
                            <th>Signup Bonus</th>
                            <th>Ongoing Bonus Earned</th>
                            <th>Total Earned</th>
                            <th>Referral Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($referrals)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">No referral logs found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($referrals as $ref): ?>
                                <?php
                                    // Referred user is Active if email has been verified
                                    $isActive      = !empty($ref['email_verified_at']);
                                    $signupBonus   = (float)($ref['signup_bonus_paid'] ?? $ref['bonus_amount'] ?? 0);
                                    $ongoingBonus  = (float)($ref['ongoing_bonus_total'] ?? 0);
                                    $totalEarned   = $signupBonus + $ongoingBonus;
                                ?>
                                <tr>
                                    <td class="small text-muted font-monospace"><?= $ref['id'] ?></td>
                                    <td>
                                        <span class="fw-bold">@<?= e($ref['referrer_username']) ?></span>
                                        <div class="small text-muted"><?= e($ref['referrer_email']) ?></div>
                                    </td>
                                    <td>
                                        <span class="text-dark">@<?= e($ref['referred_username']) ?></span>
                                        <div class="small text-muted"><?= e($ref['referred_email']) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($isActive): ?>
                                            <span class="admin-badge admin-badge-success">Active</span>
                                        <?php else: ?>
                                            <span class="admin-badge admin-badge-pending">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold text-success">
                                        <?= $ref['status'] === 'paid' ? formatMoney($signupBonus) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                    <td class="fw-bold <?= $ongoingBonus > 0 ? 'text-primary' : 'text-muted' ?>">
                                        <?= $ongoingBonus > 0 ? formatMoney($ongoingBonus) : '—' ?>
                                    </td>
                                    <td class="fw-bold">
                                        <?= $totalEarned > 0 ? formatMoney($totalEarned) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                    <td>
                                        <span class="admin-badge admin-badge-<?= $ref['status'] === 'paid' ? 'success' : 'pending' ?>">
                                            <?= ucfirst($ref['status']) ?>
                                        </span>
                                    </td>
                                    <td class="small">
                                        <?= date('d M Y', strtotime($ref['created_at'])) ?>
                                        <?php if ($ref['paid_at']): ?>
                                            <div class="text-muted" style="font-size:11px;">Paid: <?= date('d M Y', strtotime($ref['paid_at'])) ?></div>
                                        <?php endif; ?>
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
                                <a class="page-link" href="<?= APP_URL ?>/admin/referrals?page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>

        </div>
    </main>
</div>

<?php include ADMIN_PATH . '/includes/footer.php'; ?>
