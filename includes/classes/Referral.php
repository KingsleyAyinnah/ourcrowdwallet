<?php
namespace Ourcr;

defined('OURCR_ONLINE') or die('Direct access not permitted.');



use Database;

/**
 * Referral — referral-programme management layer for OURCR ONLINE.
 *
 * All methods are static and use the Database class for persistence.
 * Bonus payments delegate to the global payReferralBonus() helper.
 */
final class Referral
{
    private function __construct() {}

    // =========================================================================
    // Record creation
    // =========================================================================

    /**
     * Create a new referral relationship.
     *
     * A referral row is only created when the referred user successfully
     * completes registration and is activated.
     *
     * @param  int  $referrerId  User ID of the person who owns the referral code
     * @param  int  $referredId  User ID of the newly registered user
     * @return bool              True on success, false if already exists or DB error
     */
    public static function create(int $referrerId, int $referredId): bool
    {
        // Prevent self-referral
        if ($referrerId === $referredId) {
            return false;
        }

        try {
            // Idempotency: skip if referral already recorded
            $exists = Database::fetchOne(
                'SELECT id FROM referrals WHERE referrer_id = ? AND referred_id = ? LIMIT 1',
                [$referrerId, $referredId]
            );
            if ($exists !== false) {
                return true; // already recorded — treat as success
            }

            return Database::insert('referrals', [
                'referrer_id'  => $referrerId,
                'referred_id'  => $referredId,
                'status'       => 'pending',
                'bonus_paid'   => 0,
                'created_at'   => date('Y-m-d H:i:s'),
            ]) > 0;
        } catch (\Throwable $e) {
            error_log('[Referral::create] ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // Retrieval
    // =========================================================================

    /**
     * Fetch all referrals made by a specific user (i.e. their downline).
     *
     * Each row includes the referred user's name, email, join date,
     * referral status, and whether the bonus has been paid.
     *
     * @return array<int, array>
     */
    public static function getForReferrer(int $userId): array
    {
        try {
            return Database::fetchAll(
                'SELECT r.id,
                        r.referred_id,
                        r.status,
                        r.bonus_paid,
                        r.bonus_amount,
                        r.created_at,
                        CONCAT(u.first_name, \' \', u.last_name) AS referred_name,
                        u.email                                    AS referred_email,
                        u.status                                   AS user_status,
                        u.created_at                               AS joined_at
                 FROM referrals r
                 JOIN users u ON u.id = r.referred_id
                 WHERE r.referrer_id = ? AND u.deleted_at IS NULL
                 ORDER BY r.created_at DESC',
                [$userId]
            ) ?: [];
        } catch (\Throwable $e) {
            error_log('[Referral::getForReferrer] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Return aggregate referral statistics for a user.
     *
     * @return array{
     *   total: int,
     *   paid: int,
     *   pending: int,
     *   total_earnings: float
     * }
     */
    public static function getStats(int $userId): array
    {
        try {
            $row = Database::fetchOne(
                "SELECT
                    COUNT(r.id) AS total,
                    SUM(CASE WHEN u.email_verified_at IS NOT NULL OR r.status = 'paid' THEN 1 ELSE 0 END) AS paid,
                    SUM(CASE WHEN u.email_verified_at IS NULL AND r.status != 'paid' THEN 1 ELSE 0 END) AS pending
                 FROM referrals r
                 JOIN users u ON u.id = r.referred_id
                 WHERE r.referrer_id = ? AND u.deleted_at IS NULL",
                [$userId]
            );

            // Fetch Bonus Earned from user record
            $userRow = Database::fetchOne("SELECT bonus_balance FROM users WHERE id = ?", [$userId]);
            $bonusEarned = (float)($userRow['bonus_balance'] ?? 0.00);

            return [
                'total'          => (int)   ($row['total']          ?? 0),
                'paid'           => (int)   ($row['paid']           ?? 0),
                'pending'        => (int)   ($row['pending']        ?? 0),
                'total_earnings' => $bonusEarned,
            ];
        } catch (\Throwable $e) {
            error_log('[Referral::getStats] ' . $e->getMessage());
            return ['total' => 0, 'paid' => 0, 'pending' => 0, 'total_earnings' => 0.0];
        }
    }

    // =========================================================================
    // Bonus payment
    // =========================================================================

    /**
     * Trigger the referral bonus payment for the referrer of $referredUserId.
     *
     * Calls the global payReferralBonus() helper then writes an audit log entry.
     *
     * @param  int  $referredUserId  The user whose signup triggers the bonus
     * @return bool                  True if bonus was processed successfully
     */
    public static function payBonus(int $referredUserId): bool
    {
        try {
            $referral = Database::fetchOne(
                'SELECT * FROM referrals WHERE referred_id = ? AND bonus_paid = 0 LIMIT 1',
                [$referredUserId]
            );

            if ($referral === false) {
                return false; // no unpaid referral found
            }

            $paid = payReferralBonus($referral['referrer_id'], $referredUserId);

            if ($paid) {
                // Mark as paid
                Database::execute(
                    'UPDATE referrals SET bonus_paid = 1, status = ?, paid_at = NOW() WHERE id = ?',
                    ['paid', $referral['id']]
                );

                auditLog(
                    'REFERRAL_BONUS_PAID',
                    "Referral bonus paid to user #{$referral['referrer_id']} for referring user #{$referredUserId}",
                    $referral['referrer_id']
                );
            }

            return $paid;
        } catch (\Throwable $e) {
            error_log('[Referral::payBonus] ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // Lookup helpers
    // =========================================================================

    /**
     * Return the referrer user record for a given referral code.
     *
     * @return array|false  Full user row, or false if not found / invalid
     */
    public static function getReferrerByCode(string $code): array|false
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return false;
        }

        try {
            return Database::fetchOne(
                'SELECT * FROM users WHERE referral_code = ? AND deleted_at IS NULL AND status = ? LIMIT 1',
                [$code, 'active']
            );
        } catch (\Throwable $e) {
            error_log('[Referral::getReferrerByCode] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check whether a referral code belongs to an active, non-deleted user.
     *
     * @param  string $code  The referral code to validate
     * @return bool          True if valid and usable
     */
    public static function validateCode(string $code): bool
    {
        return self::getReferrerByCode($code) !== false;
    }
}
