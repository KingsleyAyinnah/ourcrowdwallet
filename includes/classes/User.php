<?php
namespace Ourcr;

defined('OURCR_ONLINE') or die('Direct access not permitted.');



use Database;

/**
 * User — static user-management layer for OURCR ONLINE.
 *
 * All DB access goes through the Database class (PDO-backed).
 * No constructor — purely static.
 */
final class User
{
    private function __construct() {}

    // =========================================================================
    // Lookups
    // =========================================================================

    /** Find a user record by primary key. */
    public static function findById(int $id): array|false
    {
        try {
            return Database::fetchOne(
                'SELECT * FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1',
                [$id]
            );
        } catch (\Throwable $e) {
            error_log('[User::findById] ' . $e->getMessage());
            return false;
        }
    }

    /** Find a user record by e-mail address. */
    public static function findByEmail(string $email): array|false
    {
        try {
            return Database::fetchOne(
                'SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1',
                [strtolower(trim($email))]
            );
        } catch (\Throwable $e) {
            error_log('[User::findByEmail] ' . $e->getMessage());
            return false;
        }
    }

    /** Find a user record by username. */
    public static function findByUsername(string $username): array|false
    {
        try {
            return Database::fetchOne(
                'SELECT * FROM users WHERE username = ? AND deleted_at IS NULL LIMIT 1',
                [strtolower(trim($username))]
            );
        } catch (\Throwable $e) {
            error_log('[User::findByUsername] ' . $e->getMessage());
            return false;
        }
    }

    /** Find a user record by referral code. */
    public static function findByReferralCode(string $code): array|false
    {
        try {
            return Database::fetchOne(
                'SELECT * FROM users WHERE referral_code = ? AND deleted_at IS NULL LIMIT 1',
                [strtoupper(trim($code))]
            );
        } catch (\Throwable $e) {
            error_log('[User::findByReferralCode] ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // Profile mutations
    // =========================================================================

    /**
     * Update basic profile fields.
     *
     * @param array $data  Keys: first_name, last_name, phone, username
     */
    public static function updateProfile(int $userId, array $data): bool
    {
        try {
            $allowed = ['first_name', 'last_name', 'phone', 'username'];
            $sets    = [];
            $params  = [];

            foreach ($allowed as $col) {
                if (array_key_exists($col, $data)) {
                    $sets[]   = "{$col} = ?";
                    $params[] = trim((string) $data[$col]);
                }
            }

            if (empty($sets)) {
                return false;
            }

            $params[] = $userId;
            $sql      = 'UPDATE users SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?';

            return Database::execute($sql, $params);
        } catch (\Throwable $e) {
            error_log('[User::updateProfile] ' . $e->getMessage());
            return false;
        }
    }

    /** Store a new avatar filename for a user. */
    public static function updateAvatar(int $userId, string $filename): bool
    {
        try {
            return Database::execute(
                'UPDATE users SET avatar = ?, updated_at = NOW() WHERE id = ?',
                [$filename, $userId]
            );
        } catch (\Throwable $e) {
            error_log('[User::updateAvatar] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Persist a user's chosen site colour.
     * Validates #RRGGBB format before saving.
     */
    public static function updateSiteColor(int $userId, string $color): bool
    {
        $color = strtoupper(trim($color));
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            return false;
        }

        try {
            return Database::execute(
                'UPDATE users SET site_color = ?, updated_at = NOW() WHERE id = ?',
                [$color, $userId]
            );
        } catch (\Throwable $e) {
            error_log('[User::updateSiteColor] ' . $e->getMessage());
            return false;
        }
    }

    /** Replace the stored bcrypt password hash. */
    public static function updatePassword(int $userId, string $newPassword): bool
    {
        try {
            $hash = hashPassword($newPassword);
            return Database::execute(
                'UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?',
                [$hash, $userId]
            );
        } catch (\Throwable $e) {
            error_log('[User::updatePassword] ' . $e->getMessage());
            return false;
        }
    }

    /** Hash and store a 4-digit transaction PIN. */
    public static function updatePin(int $userId, string $pin): bool
    {
        if (!preg_match('/^\d{4}$/', $pin)) {
            return false;
        }

        try {
            $hash = hashPassword($pin);
            return Database::execute(
                'UPDATE users SET transaction_pin = ?, pin_set = 1, updated_at = NOW() WHERE id = ?',
                [$hash, $userId]
            );
        } catch (\Throwable $e) {
            error_log('[User::updatePin] ' . $e->getMessage());
            return false;
        }
    }

    /** Verify a plain-text PIN against the stored hash. */
    public static function verifyPin(int $userId, string $pin): bool
    {
        try {
            $row = Database::fetchOne(
                'SELECT transaction_pin FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1',
                [$userId]
            );
            if (!$row || empty($row['transaction_pin'])) {
                return false;
            }
            return verifyPassword($pin, $row['transaction_pin']);
        } catch (\Throwable $e) {
            error_log('[User::verifyPin] ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // Status management
    // =========================================================================

    /** Mark a user's account as active. */
    public static function activate(int $userId): bool
    {
        try {
            return Database::execute(
                'UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?',
                ['active', $userId]
            );
        } catch (\Throwable $e) {
            error_log('[User::activate] ' . $e->getMessage());
            return false;
        }
    }

    /** Suspend a user account with an optional reason. */
    public static function suspend(int $userId, string $reason = ''): bool
    {
        $u = self::findById($userId);
        if ($u && $u['email'] === 'kingsleyayinnah@gmail.com') {
            return false;
        }
        try {
            return Database::execute(
                'UPDATE users SET status = ?, status_reason = ?, updated_at = NOW() WHERE id = ?',
                ['suspended', $reason, $userId]
            );
        } catch (\Throwable $e) {
            error_log('[User::suspend] ' . $e->getMessage());
            return false;
        }
    }

    /** Permanently ban a user account. */
    public static function ban(int $userId, string $reason = ''): bool
    {
        $u = self::findById($userId);
        if ($u && $u['email'] === 'kingsleyayinnah@gmail.com') {
            return false;
        }
        try {
            return Database::execute(
                'UPDATE users SET status = ?, status_reason = ?, updated_at = NOW() WHERE id = ?',
                ['banned', $reason, $userId]
            );
        } catch (\Throwable $e) {
            error_log('[User::ban] ' . $e->getMessage());
            return false;
        }
    }

    /** Soft-delete a user (sets deleted_at timestamp). */
    public static function softDelete(int $userId): bool
    {
        $u = self::findById($userId);
        if ($u && $u['email'] === 'kingsleyayinnah@gmail.com') {
            return false;
        }
        try {
            return Database::execute(
                'UPDATE users SET deleted_at = NOW(), status = ?, updated_at = NOW() WHERE id = ?',
                ['deleted', $userId]
            );
        } catch (\Throwable $e) {
            error_log('[User::softDelete] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Permanently delete a user and all their associated data from the database.
     * This is irreversible. Use only from superadmin-level operations.
     */
    public static function hardDelete(int $userId): bool
    {
        $u = Database::fetchOne("SELECT email, phone, username FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$u) {
            return false;
        }
        if ($u['email'] === 'kingsleyayinnah@gmail.com') {
            return false;
        }
        try {
            // Attempt physical hard delete
            return Database::execute(
                'DELETE FROM users WHERE id = ?',
                [$userId]
            );
        } catch (\Throwable $e) {
            // If hard delete fails due to foreign keys, soft-delete and release unique constraints
            $suffix = '_del_' . time();
            $newEmail = $u['email'] . $suffix;
            $newPhone = $u['phone'] . $suffix;
            $newUsername = $u['username'] . $suffix;
            try {
                return Database::execute(
                    'UPDATE users SET email = ?, phone = ?, username = ?, deleted_at = NOW(), status = ?, updated_at = NOW() WHERE id = ?',
                    [$newEmail, $newPhone, $newUsername, 'deleted', $userId]
                );
            } catch (\Throwable $ex) {
                error_log('[User::hardDelete fallback] ' . $ex->getMessage());
                return false;
            }
        }
    }

    // =========================================================================
    // Stats & checks
    // =========================================================================

    /**
     * Return aggregate stats for a user dashboard.
     *
     * @return array{
     *   total_transactions: int,
     *   wallet_balance: float,
     *   referral_count: int,
     *   join_date: string
     * }
     */
    public static function getStats(int $userId): array
    {
        try {
            $txnRow = Database::fetchOne(
                'SELECT COUNT(*) AS total FROM transactions WHERE user_id = ?',
                [$userId]
            );
            $refRow = Database::fetchOne(
                'SELECT COUNT(*) AS total FROM referrals WHERE referrer_id = ?',
                [$userId]
            );
            $userRow = Database::fetchOne(
                'SELECT created_at FROM users WHERE id = ? LIMIT 1',
                [$userId]
            );

            return [
                'total_transactions' => (int)   ($txnRow['total']  ?? 0),
                'wallet_balance'     => (float)  getWalletBalance($userId),
                'referral_count'     => (int)   ($refRow['total']  ?? 0),
                'join_date'          => (string) ($userRow['created_at'] ?? ''),
            ];
        } catch (\Throwable $e) {
            error_log('[User::getStats] ' . $e->getMessage());
            return [
                'total_transactions' => 0,
                'wallet_balance'     => 0.0,
                'referral_count'     => 0,
                'join_date'          => '',
            ];
        }
    }

    /** Check whether the user has already set a transaction PIN. */
    public static function hasSetPin(int $userId): bool
    {
        try {
            $row = Database::fetchOne(
                'SELECT pin_set FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1',
                [$userId]
            );
            return (bool) ($row['pin_set'] ?? false);
        } catch (\Throwable $e) {
            error_log('[User::hasSetPin] ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // Avatar upload
    // =========================================================================

    /**
     * Handle a profile avatar upload.
     *
     * Rules:
     *  - > 1 MB  → rejected outright with a clear error message.
     *  - > 100 KB and ≤ 1 MB → auto-compressed to ≤ 100 KB before saving.
     *  - ≤ 100 KB → saved as-is (no recompression needed).
     *
     * Non-GIF images are normalised to JPEG. GIFs are kept in their original
     * format (converting would destroy animation).
     *
     * @param  int   $userId  The owner user ID
     * @param  array $file    Entry from $_FILES['avatar']
     * @return array{success: bool, filename: string, error: string}
     */
    public static function uploadAvatar(int $userId, array $file): array
    {
        $result = ['success' => false, 'filename' => '', 'error' => ''];

        // Basic upload error check
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $result['error'] = 'File upload failed. Please try again.';
            return $result;
        }

        $hardLimitBytes  = 1 * 1024 * 1024;  // 1 MB  — hard reject above this
        $targetBytes     = 100 * 1024;         // 100 KB — compress down to this
        $allowed         = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

        // Hard size check — reject immediately, no processing at all
        if ($file['size'] > $hardLimitBytes) {
            $sizeMB = round($file['size'] / (1024 * 1024), 2);
            $result['error'] = "Image is too large ({$sizeMB} MB). Maximum allowed size is 1 MB. Please choose a smaller image and try again.";
            return $result;
        }

        // MIME check using finfo (not just extension)
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, $allowed, true)) {
            $result['error'] = 'Only JPEG, PNG, WebP and GIF images are accepted.';
            return $result;
        }

        $isGif    = ($mimeType === 'image/gif');
        $ext      = $isGif ? 'gif' : 'jpg';
        $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
        $destDir  = rtrim(AVATAR_PATH, '/\\') . DIRECTORY_SEPARATOR;

        if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
            $result['error'] = 'Could not create avatar directory. Please contact support.';
            return $result;
        }

        $destPath = $destDir . $filename;

        if ($isGif || $file['size'] <= $targetBytes) {
            // GIF or already small enough — move as-is, no recompression
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                $result['error'] = 'Failed to save the uploaded file.';
                return $result;
            }
        } else {
            // > 100 KB non-GIF: load into GD and compress down to ≤ 100 KB
            $src = match ($mimeType) {
                'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
                'image/png'  => @imagecreatefrompng($file['tmp_name']),
                'image/webp' => @imagecreatefromwebp($file['tmp_name']),
                default      => false,
            };

            if ($src === false) {
                $result['error'] = 'Could not process the image. Please try a different file.';
                return $result;
            }

            // Flatten PNG/WebP transparency onto a white background before JPEG conversion
            if (in_array($mimeType, ['image/png', 'image/webp'], true)) {
                $w     = imagesx($src);
                $h     = imagesy($src);
                $bg    = imagecreatetruecolor($w, $h);
                $white = imagecolorallocate($bg, 255, 255, 255);
                imagefill($bg, 0, 0, $white);
                imagecopy($bg, $src, 0, 0, 0, 0, $w, $h);
                imagedestroy($src);
                $src = $bg;
            }

            // Step quality down from 85 by 5 until the file fits within 100 KB
            $quality = 85;
            $tmpPath = $file['tmp_name'] . '_compressed.jpg';

            do {
                imagejpeg($src, $tmpPath, $quality);
                $quality -= 5;
            } while (filesize($tmpPath) > $targetBytes && $quality >= 10);

            imagedestroy($src);

            if (!rename($tmpPath, $destPath)) {
                @unlink($tmpPath);
                $result['error'] = 'Failed to save the processed image.';
                return $result;
            }
        }

        // Delete the old avatar (skip default placeholders)
        $existing = Database::fetchOne(
            'SELECT avatar FROM users WHERE id = ? LIMIT 1',
            [$userId]
        );
        if ($existing && !empty($existing['avatar']) && !str_starts_with($existing['avatar'], 'default')) {
            $oldPath = rtrim(AVATAR_PATH, '/\\') . DIRECTORY_SEPARATOR . $existing['avatar'];
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        if (!self::updateAvatar($userId, $filename)) {
            @unlink($destPath);
            $result['error'] = 'Failed to update avatar in database.';
            return $result;
        }

        $result['success']  = true;
        $result['filename'] = $filename;
        return $result;
    }

    // =========================================================================
    // Referral helpers
    // =========================================================================

    /**
     * Generate a unique uppercase referral code (6 chars).
     */
    public static function generateUniqueReferralCode(): string
    {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $exists = Database::fetchOne(
                'SELECT id FROM users WHERE referral_code = ? LIMIT 1',
                [$code]
            );
        } while ($exists !== false);

        return $code;
    }

    /**
     * Return a list of users referred by the given user, with join date and status.
     *
     * @return array<int, array{id: int, name: string, email: string, status: string, joined: string}>
     */
    public static function getReferralTree(int $userId): array
    {
        try {
            return Database::fetchAll(
                'SELECT u.id, CONCAT(u.first_name, \' \', u.last_name) AS name,
                        u.email, u.status, u.created_at AS joined
                 FROM referrals r
                 JOIN users u ON u.id = r.referred_id
                 WHERE r.referrer_id = ? AND u.deleted_at IS NULL
                 ORDER BY u.created_at DESC',
                [$userId]
            );
        } catch (\Throwable $e) {
            error_log('[User::getReferralTree] ' . $e->getMessage());
            return [];
        }
    }
}
