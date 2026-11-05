<?php
/**
 * include/notify.php
 * Small helper library around the `notifications` table.
 * Requires $conn (mysqli) to already be set up (include/config.php).
 *
 * Usage elsewhere in the app, e.g. when an admin approves an event:
 *   notify_user($conn, $partnerUserId, 'event_approval', 'Event approved',
 *               '"Bass Odyssey" is now live.', '/events/single-event?id=...');
 *
 * Broadcasting to every admin, e.g. when a partner submits an event:
 *   notify_role($conn, 'admin', 'event_approval', 'New event awaiting approval',
 *               'Kitty Su Nights submitted "Techno Underground".', '/events/event-approval');
 */
function notify_user(mysqli $conn, int $userId, string $type, string $title, string $message, ?string $link = null): void
{
    $stmt = $conn->prepare(
        "INSERT INTO notifications (user_id, target_role, type, title, message, link)
         VALUES (?, NULL, ?, ?, ?, ?)"
    );
    $stmt->bind_param('issss', $userId, $type, $title, $message, $link);
    $stmt->execute();
    $stmt->close();
}
function notify_role(mysqli $conn, string $role, string $type, string $title, string $message, ?string $link = null): void
{
    $stmt = $conn->prepare(
        "INSERT INTO notifications (user_id, target_role, type, title, message, link)
         VALUES (NULL, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('sssss', $role, $type, $title, $message, $link);
    $stmt->execute();
    $stmt->close();
}
/** Notifications visible to the current user: their own + any broadcast to their role. */
function get_notifications(mysqli $conn, int $userId, string $role, int $limit = 8): array
{
    $stmt = $conn->prepare(
        "SELECT id, type, title, message, link, is_read, created_at
         FROM notifications
         WHERE user_id = ? OR target_role = ?
         ORDER BY created_at DESC
         LIMIT ?"
    );
    $stmt->bind_param('isi', $userId, $role, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
function get_unread_count(mysqli $conn, int $userId, string $role): int
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM notifications
         WHERE is_read = 0 AND (user_id = ? OR target_role = ?)"
    );
    $stmt->bind_param('is', $userId, $role);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['c'] ?? 0);
}
function mark_all_read(mysqli $conn, int $userId, string $role): void
{
    $stmt = $conn->prepare(
        "UPDATE notifications SET is_read = 1
         WHERE is_read = 0 AND (user_id = ? OR target_role = ?)"
    );
    $stmt->bind_param('is', $userId, $role);
    $stmt->execute();
    $stmt->close();
}
/** Human-friendly "5m ago" / "3h ago" / "2d ago" for the dropdown. */
function time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}