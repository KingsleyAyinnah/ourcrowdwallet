<?php
namespace Ourcr;

defined('OURCR_ONLINE') or die('Direct access not permitted.');



use Database;

/**
 * Notification — in-app & email notification layer for OURCR ONLINE.
 *
 * All methods are static and delegate to global helpers
 * (sendNotification, sendMail) and the Database class.
 */
final class Notification
{
    private function __construct() {}

    // =========================================================================
    // In-app typed senders
    // =========================================================================

    /**
     * Send a generic in-app notification.
     *
     * @param int         $userId     Target user ID
     * @param string      $type       Notification type: 'success'|'error'|'warning'|'info'
     * @param string      $title      Short title displayed in the bell drop-down
     * @param string      $message    Full notification body
     * @param string|null $actionUrl  Optional CTA URL (relative or absolute)
     */
    public static function send(
        int     $userId,
        string  $type,
        string  $title,
        string  $message,
        ?string $actionUrl = null
    ): void {
        sendNotification($userId, $type, $title, $message, $actionUrl);
    }

    /** Send a SUCCESS notification. */
    public static function success(
        int     $userId,
        string  $title,
        string  $message,
        ?string $url = null
    ): void {
        self::send($userId, 'success', $title, $message, $url);
    }

    /** Send an ERROR notification. */
    public static function error(
        int     $userId,
        string  $title,
        string  $message,
        ?string $url = null
    ): void {
        self::send($userId, 'error', $title, $message, $url);
    }

    /** Send a WARNING notification. */
    public static function warning(
        int     $userId,
        string  $title,
        string  $message,
        ?string $url = null
    ): void {
        self::send($userId, 'warning', $title, $message, $url);
    }

    /** Send an INFO notification. */
    public static function info(
        int     $userId,
        string  $title,
        string  $message,
        ?string $url = null
    ): void {
        self::send($userId, 'info', $title, $message, $url);
    }

    // =========================================================================
    // Retrieval
    // =========================================================================

    /**
     * Fetch notifications for a user.
     *
     * @param  bool $unreadOnly  When true, only unread notifications are returned
     * @param  int  $limit       Max rows to return
     * @return array<int, array>
     */
    public static function getForUser(int $userId, bool $unreadOnly = false, int $limit = 20): array
    {
        try {
            $limit = max(1, min($limit, 100));

            $sql    = 'SELECT * FROM notifications WHERE user_id = ?';
            $params = [$userId];

            if ($unreadOnly) {
                $sql    .= ' AND is_read = 0';
            }

            $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;

            return Database::fetchAll($sql, $params) ?: [];
        } catch (\Throwable $e) {
            error_log('[Notification::getForUser] ' . $e->getMessage());
            return [];
        }
    }

    // =========================================================================
    // State mutation
    // =========================================================================

    /**
     * Mark one notification (by ID) or all notifications for a user as read.
     *
     * @param int      $userId   The user who owns the notification(s)
     * @param int|null $notifId  Specific notification ID, or null to mark all
     */
    public static function markRead(int $userId, ?int $notifId = null): void
    {
        try {
            if ($notifId !== null) {
                Database::execute(
                    'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?',
                    [$notifId, $userId]
                );
            } else {
                Database::execute(
                    'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0',
                    [$userId]
                );
            }
        } catch (\Throwable $e) {
            error_log('[Notification::markRead] ' . $e->getMessage());
        }
    }

    /**
     * Return the count of unread notifications for a user.
     */
    public static function getUnreadCount(int $userId): int
    {
        try {
            return getUnreadNotificationCount($userId);
        } catch (\Throwable $e) {
            error_log('[Notification::getUnreadCount] ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Hard-delete a single notification (user must own it).
     *
     * @return bool  True on success
     */
    public static function delete(int $id, int $userId): bool
    {
        try {
            return Database::execute(
                'DELETE FROM notifications WHERE id = ? AND user_id = ?',
                [$id, $userId]
            );
        } catch (\Throwable $e) {
            error_log('[Notification::delete] ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // Email
    // =========================================================================

    /**
     * Send a transactional email via the global sendMail() helper.
     *
     * @param  string $to      Recipient email address
     * @param  string $name    Recipient display name
     * @param  string $subject Email subject line
     * @param  string $body    Full HTML body (or plain text)
     * @return bool            True if the mailer accepted the message
     */
    public static function sendEmail(
        string $to,
        string $name,
        string $subject,
        string $body
    ): bool {
        try {
            return sendMail($to, $name, $subject, $body);
        } catch (\Throwable $e) {
            error_log('[Notification::sendEmail] ' . $e->getMessage());
            return false;
        }
    }
}
