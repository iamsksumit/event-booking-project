<?php
/**
 * api/deny_ticket.php
 * POST { "type": "guest"|"payment", "id": 123, "event_id": 42, "reason": "..." }
 *
 * Marks an attendee as denied entry -- used both from the scanner (right
 * after a scan) and from the attendee list on events/single-event.php.
 * A reason is required. Denying also clears any existing check-in, so the
 * door stats don't keep counting someone who was then turned away.
 *
 * Same-day rule: this only allows a denial while today is the event's
 * start date (or later, for the case where a scan/decision happens after
 * midnight for a late-running event) -- so "that day only" per the request.
 * Admins get no exception; if you want a longer grace window, adjust
 * DENIAL_GRACE_HOURS below.
 */
require_once __DIR__ . '/../include/session_guard.php';
require_once __DIR__ . '/../include/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

const DENIAL_GRACE_HOURS = 12; // allow denials up to this many hours after the event's start date rolls over

$input   = json_decode(file_get_contents('php://input'), true);
$type    = $input['type'] ?? '';
$id      = (int) ($input['id'] ?? 0);
$eventId = (int) ($input['event_id'] ?? 0);
$reason  = trim((string) ($input['reason'] ?? ''));

if (!in_array($type, ['guest', 'payment'], true) || !$id || !$eventId) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid request.']);
    exit;
}
if ($reason === '') {
    echo json_encode(['success' => false, 'message' => 'Please give a reason for denying entry.']);
    exit;
}
if (mb_strlen($reason) > 500) {
    $reason = mb_substr($reason, 0, 500);
}

$stmt = $conn->prepare("SELECT id, tittle, url, user_id, start FROM events WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    echo json_encode(['success' => false, 'message' => 'Event not found.']);
    exit;
}
if ($role !== 'admin' && (string) $event['user_id'] !== (string) $userId) {
    echo json_encode(['success' => false, 'message' => 'That event doesn\'t belong to you.']);
    exit;
}

$eventDay = date('Y-m-d', strtotime($event['start']));
$cutoff   = strtotime($eventDay . ' +1 day +' . DENIAL_GRACE_HOURS . ' hours');
if (time() < strtotime($eventDay) || time() > $cutoff) {
    echo json_encode(['success' => false, 'message' => 'Entry can only be denied on the event\'s own day.']);
    exit;
}

if ($type === 'guest') {
    // Confirm this guest row actually belongs to the selected event before touching it.
    $stmt = $conn->prepare("
        SELECT bg.id, bg.guest_name
        FROM booking_guests bg
        INNER JOIN payments p ON p.pay_ref = bg.pay_ref
        WHERE bg.id = ? AND p.event_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('is', $id, $event['url']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Attendee not found for this event.']);
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE booking_guests
        SET denied = 1, denied_reason = ?, denied_at = NOW(), denied_by = ?,
            checked_in = 0, checked_in_at = NULL
        WHERE id = ?
    ");
    $stmt->bind_param('sii', $reason, $userId, $id);
    $stmt->execute();
    $stmt->close();
    $attendeeName = $row['guest_name'];
} else {
    $stmt = $conn->prepare("SELECT id, name FROM payments WHERE id = ? AND event_id = ? LIMIT 1");
    $stmt->bind_param('is', $id, $event['url']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Attendee not found for this event.']);
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE payments
        SET denied = 1, denied_reason = ?, denied_at = NOW(), denied_by = ?,
            checked_in = 0, checked_in_at = NULL
        WHERE id = ?
    ");
    $stmt->bind_param('sii', $reason, $userId, $id);
    $stmt->execute();
    $stmt->close();
    $attendeeName = $row['name'] ?: 'Guest';
}

/* Reuse the same stats shape as scan_ticket.php so the UI can refresh either way. */
$stmt = $conn->prepare("SELECT COUNT(*) c FROM booking_guests bg INNER JOIN payments p ON p.pay_ref = bg.pay_ref WHERE p.event_id = ?");
$stmt->bind_param('s', $event['url']);
$stmt->execute();
$guestTotal = (int) $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

if ($guestTotal > 0) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM booking_guests bg INNER JOIN payments p ON p.pay_ref = bg.pay_ref WHERE p.event_id = ? AND bg.checked_in = 1");
    $stmt->bind_param('s', $event['url']);
    $stmt->execute();
    $checkedIn = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    $total = $guestTotal;
} else {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM payments WHERE event_id = ?");
    $stmt->bind_param('s', $event['url']);
    $stmt->execute();
    $total = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM payments WHERE event_id = ? AND checked_in = 1");
    $stmt->bind_param('s', $event['url']);
    $stmt->execute();
    $checkedIn = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
}

echo json_encode([
    'success' => true,
    'message' => 'Entry denied for ' . $attendeeName . '.',
    'stats'   => ['checked_in' => $checkedIn, 'remaining' => max(0, $total - $checkedIn), 'total' => $total],
]);