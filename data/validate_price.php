<?php
// Enable error reporting (for debugging)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include database connection
include '../include/config.php'; // $conn is mysqli connection

header('Content-Type: application/json');

// Retrieve POST data safely
$event_id       = mysqli_real_escape_string($conn, $_POST['event_id'] ?? '');
$client_price   = floatval($_POST['client_price'] ?? 0);
$tickets        = json_decode($_POST['tickets'] ?? '{}', true);
$selected_dates = json_decode($_POST['selected_dates'] ?? '[]', true);
if (!is_array($selected_dates)) {
    $selected_dates = [];
}
if (empty($event_id) || $client_price <= 0 || empty($tickets) || empty($selected_dates)) {
    echo json_encode(['valid' => false, 'message' => 'Invalid input data.']);
    exit;
}

try {
    $numDates = count($selected_dates);
    $ticket_ids = implode(",", array_map('intval', array_keys($tickets)));

    if (empty($ticket_ids)) {
        echo json_encode(['valid' => false, 'message' => 'No tickets selected.']);
        exit;
    }

    $query = "SELECT event_tickets.id, event_tickets.ticket_name, event_tickets.ticket_cost, event_tickets.tickets_left 
              FROM event_tickets
              INNER JOIN events ON event_tickets.event_id = events.id
              WHERE events.url = '$event_id' AND event_tickets.id IN ($ticket_ids)";

    $result = mysqli_query($conn, $query);
    if (!$result) {
        throw new Exception("Database error: " . mysqli_error($conn));
    }

    $server_price = 0;
    $unavailable_tickets = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $ticket_id   = $row['id'];
        $ticket_name = $row['ticket_name'];
        $ticket_cost = $row['ticket_cost'];
        $tickets_left= $row['tickets_left'];

        if (isset($tickets[$ticket_id])) {
            $qty = $tickets[$ticket_id]['qty'];

            if ($qty > $tickets_left) {
                $unavailable_tickets[] = $ticket_name;
            }

            // Multiply by number of selected dates
            $server_price += $qty * $numDates * $ticket_cost;
        }
    }

    mysqli_free_result($result);

    if (!empty($unavailable_tickets)) {
        echo json_encode([
            'valid' => false,
            'message' => 'Some tickets are sold out or insufficient.',
            'unavailable_tickets' => $unavailable_tickets
        ]);
        exit;
    }

    if (abs($server_price - $client_price) < 0.01) {
        echo json_encode(['valid' => true, 'message' => 'Validation successful.']);
    } else {
        echo json_encode([
            'valid' => false,
            'message' => 'Price mismatch detected.',
            'server_price' => $server_price,
            'client_price' => $client_price
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['valid' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
