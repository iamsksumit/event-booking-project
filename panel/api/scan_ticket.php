<?php
/**
 * api/scan_ticket.php
 * POST { ticket_code, event_id }
 * Looks up a ticket and returns its details. Does NOT check anyone in.
 * status: pending | already | wrong_event | denied | invalid
 * Approve happens in approve_ticket.php, Deny in deny_ticket.php.
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
$code    = trim((string) ($input['ticket_code'] ?? ''));
$eventId = (int) ($input['event_id'] ?? 0);

if ($code === '') { echo json_encode(['success' => false, 'message' => 'Enter or scan a ticket code.']); exit; }
if (!$eventId)     { echo json_encode(['success' => false, 'message' => 'Select an event first.']); exit; }

// Selected event + ownership check
$stmt = $conn->prepare("SELECT id, tittle, url, user_id, start FROM events WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) { echo json_encode(['success' => false, 'message' => 'Event not found.']); exit; }
if ($role !== 'admin' && (string) $event['user_id'] !== (string) $userId) {
    echo json_encode(['success' => false, 'message' => 'That event doesn\'t belong to you.']);
    exit;
}

// Door stats for this event
function scan_event_stats(mysqli $conn, string $eventUrl): array {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM booking_guests bg INNER JOIN payments p ON p.pay_ref = bg.pay_ref WHERE p.event_id = ?");
    $stmt->bind_param('s', $eventUrl); $stmt->execute();
    $guestTotal = (int) $stmt->get_result()->fetch_assoc()['c']; $stmt->close();

    if ($guestTotal > 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM booking_guests bg INNER JOIN payments p ON p.pay_ref = bg.pay_ref WHERE p.event_id = ? AND bg.checked_in = 1");
        $stmt->bind_param('s', $eventUrl); $stmt->execute();
        $checkedIn = (int) $stmt->get_result()->fetch_assoc()['c']; $stmt->close();
        $total = $guestTotal;
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) c FROM payments WHERE event_id = ?");
        $stmt->bind_param('s', $eventUrl); $stmt->execute();
        $total = (int) $stmt->get_result()->fetch_assoc()['c']; $stmt->close();

        $stmt = $conn->prepare("SELECT COUNT(*) c FROM payments WHERE event_id = ? AND checked_in = 1");
        $stmt->bind_param('s', $eventUrl); $stmt->execute();
        $checkedIn = (int) $stmt->get_result()->fetch_assoc()['c']; $stmt->close();
    }
    return ['checked_in' => $checkedIn, 'remaining' => max(0, $total - $checkedIn), 'total' => $total];
}

// 1) Normal flow: booking_guests.ticket_code
$stmt = $conn->prepare(
    "SELECT bg.id, bg.guest_name, bg.checked_in, bg.denied, bg.denied_reason, bg.pay_ref, p.event_id, p.price
     FROM booking_guests bg INNER JOIN payments p ON p.pay_ref = bg.pay_ref
     WHERE bg.ticket_code = ? LIMIT 1"
);
$stmt->bind_param('s', $code);
$stmt->execute();
$guest = $stmt->get_result()->fetch_assoc();
$stmt->close();

$record = null; $foundEventUrl = null; $attendeeName = null;
$alreadyIn = false; $deniedRow = false; $deniedReason = null;
$price = 0.0; $ticketCount = 1;

if ($guest) {
    $record        = ['type' => 'guest', 'id' => (int) $guest['id']];
    $foundEventUrl = $guest['event_id'];
    $attendeeName  = $guest['guest_name'];
    $alreadyIn     = (bool) $guest['checked_in'];
    $deniedRow     = (bool) $guest['denied'];
    $deniedReason  = $guest['denied_reason'];
    $price         = (float) $guest['price'];

    // ticket count = other guests on the same booking
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM booking_guests WHERE pay_ref = ?");
    $stmt->bind_param('s', $guest['pay_ref']);
    $stmt->execute();
    $ticketCount = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    if ($ticketCount < 1) $ticketCount = 1;
} else {
    // 2) Legacy fallback: code is pay_ref/payment_id itself
    $stmt = $conn->prepare("SELECT id, name, event_id, checked_in, denied, denied_reason, price FROM payments WHERE pay_ref = ? OR payment_id = ? LIMIT 1");
    $stmt->bind_param('ss', $code, $code);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($payment) {
        $record        = ['type' => 'payment', 'id' => (int) $payment['id']];
        $foundEventUrl = $payment['event_id'];
        $attendeeName  = $payment['name'] ?: 'Guest';
        $alreadyIn     = (bool) $payment['checked_in'];
        $deniedRow     = (bool) $payment['denied'];
        $deniedReason  = $payment['denied_reason'];
        $price         = (float) $payment['price'];
        $ticketCount   = 1;
    }
}

// Not found at all
if (!$record) {
    echo json_encode([
        'success' => true, 'status' => 'invalid',
        'message' => 'This code is not a valid ticket. Please check and try again.',
        'event'   => ['id' => (int) $event['id'], 'title' => $event['tittle']],
        'stats'   => scan_event_stats($conn, $event['url']),
    ]);
    exit;
}

$details = [
    'name'         => $attendeeName ?: 'Guest',
    'event_title'  => $event['tittle'],
    'event_start'  => date('D, d M Y — g:i A', strtotime($event['start'])),
    'price'        => $price,
    'ticket_count' => $ticketCount,
];

// Real ticket, wrong event
// Real ticket, wrong event -- show which event it's actually for, nothing else
if ($foundEventUrl !== $event['url']) {
    $stmt = $conn->prepare("SELECT tittle, start FROM events WHERE url = ? LIMIT 1");
    $stmt->bind_param('s', $foundEventUrl);
    $stmt->execute();
    $realEvent = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode([
        'success' => true, 'status' => 'wrong_event',
        'message' => 'Wrong Ticket — this code is not valid for tonight\'s event.',
        'record'  => $record,
        'event'   => ['id' => (int) $event['id'], 'title' => $event['tittle']],
        'details' => [
            'event_title' => $realEvent['tittle'] ?? 'Unknown event',
            'event_start' => $realEvent ? date('D, d M Y — g:i A', strtotime($realEvent['start'])) : '—',
        ],
        'stats'   => scan_event_stats($conn, $event['url']),
    ]);
    exit;
}
// Previously denied
if ($deniedRow) {
    echo json_encode([
        'success' => true, 'status' => 'denied',
        'message' => ($attendeeName ?: 'Guest') . ' was previously denied entry' . ($deniedReason ? ': ' . $deniedReason : '.'),
        'record'  => $record,
        'event'   => ['id' => (int) $event['id'], 'title' => $event['tittle']],
        'details' => $details,
        'stats'   => scan_event_stats($conn, $event['url']),
    ]);
    exit;
}

// Already checked in
if ($alreadyIn) {
    echo json_encode([
        'success' => true, 'status' => 'already',
        'message' => 'Already checked in: ' . ($attendeeName ?: 'Guest') . ' — ' . $event['tittle'],
        'record'  => $record,
        'event'   => ['id' => (int) $event['id'], 'title' => $event['tittle']],
        'details' => $details,
        'stats'   => scan_event_stats($conn, $event['url']),
    ]);
    exit;
}

// Valid, right event, not yet decided -> hand to staff
echo json_encode([
    'success' => true, 'status' => 'pending',
    'message' => ($attendeeName ?: 'Guest') . ' — review details and approve or deny entry.',
    'record'  => $record,
    'event'   => ['id' => (int) $event['id'], 'title' => $event['tittle']],
    'details' => $details,
    'stats'   => scan_event_stats($conn, $event['url']),
]);