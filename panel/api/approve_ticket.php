<?php
/**
 * api/approve_ticket.php
 * POST { type: guest|payment, id, event_id }
 * Checks the attendee in after staff approves in the scanner UI.
 */
require_once __DIR__ . '/../include/session_guard.php';
require_once __DIR__ . '/../include/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true);
$type    = $input['type'] ?? '';
$id      = (int) ($input['id'] ?? 0);
$eventId = (int) ($input['event_id'] ?? 0);

if (!in_array($type, ['guest', 'payment'], true) || !$id || !$eventId) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid request.']);
    exit;
}

$stmt = $conn->prepare("SELECT id, tittle, url, user_id FROM events WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) { echo json_encode(['success' => false, 'message' => 'Event not found.']); exit; }
if ($role !== 'admin' && (string) $event['user_id'] !== (string) $userId) {
    echo json_encode(['success' => false, 'message' => 'That event doesn\'t belong to you.']);
    exit;
}

if ($type === 'guest') {
    // Confirm row belongs to this event, isn't denied/already in
    $stmt = $conn->prepare("
        SELECT bg.id, bg.guest_name, bg.checked_in, bg.denied
        FROM booking_guests bg INNER JOIN payments p ON p.pay_ref = bg.pay_ref
        WHERE bg.id = ? AND p.event_id = ? LIMIT 1
    ");
    $stmt->bind_param('is', $id, $event['url']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) { echo json_encode(['success' => false, 'message' => 'Attendee not found for this event.']); exit; }
    if ((bool) $row['denied']) { echo json_encode(['success' => false, 'message' => 'This ticket was already denied and cannot be approved.']); exit; }
    if ((bool) $row['checked_in']) { echo json_encode(['success' => false, 'message' => 'Already checked in.']); exit; }

    $stmt = $conn->prepare("UPDATE booking_guests SET checked_in = 1, checked_in_at = NOW(), checked_in_by = ? WHERE id = ?");
    $stmt->bind_param('ii', $userId, $id);
    $stmt->execute();
    $stmt->close();
    $attendeeName = $row['guest_name'];
} else {
    $stmt = $conn->prepare("SELECT id, name, checked_in, denied FROM payments WHERE id = ? AND event_id = ? LIMIT 1");
    $stmt->bind_param('is', $id, $event['url']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) { echo json_encode(['success' => false, 'message' => 'Attendee not found for this event.']); exit; }
    if ((bool) $row['denied']) { echo json_encode(['success' => false, 'message' => 'This ticket was already denied and cannot be approved.']); exit; }
    if ((bool) $row['checked_in']) { echo json_encode(['success' => false, 'message' => 'Already checked in.']); exit; }

    $stmt = $conn->prepare("UPDATE payments SET checked_in = 1, checked_in_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    $attendeeName = $row['name'] ?: 'Guest';
}

// Refresh stats for this event
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
    'message' => 'Checked in: ' . $attendeeName . ' — ' . $event['tittle'],
    'stats'   => ['checked_in' => $checkedIn, 'remaining' => max(0, $total - $checkedIn), 'total' => $total],
]);