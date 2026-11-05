<?php
ob_start();
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

include '../include/config.php';

if (!isset($_REQUEST['id']) || trim($_REQUEST['id']) === '') {
    echo "<script>alert('No event ID provided.'); window.location.href='all-events.php';</script>";
    exit();
}

$id = $_REQUEST['id'];

$query = "
   SELECT
        events.*,
        events.id AS eventid
   FROM events
   WHERE events.url = ?
   LIMIT 1";

$stmt = $conn->prepare($query);
$stmt->bind_param('s', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo "<script>alert('Event not found.'); window.location.href='all-events.php';</script>";
    exit();
}

/* generate a unique slug for the copy */
function create_dup_slug($string) {
    $slug = preg_replace('/[^A-Za-z0-9-]+/', '-', trim($string));
    $slug = trim($slug, '-');
    return $slug . '-duplicate-' . uniqid();
}

$new_url        = create_dup_slug($row['url']);
$duplicateTitle = $row['tittle'] . ' duplicate';

$insert_query = "
    INSERT INTO events (
        url, tittle, banner_status, sub_tittle, status,
        description, term, video, ticketing_type, multiple_dates, multiple_date_id,
        Organised, special_detail, image, start, end, user_id
    ) VALUES (?, ?, 0, ?, 2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$insertStmt = $conn->prepare($insert_query);
$insertStmt->bind_param(
    'sssssssssssssss',
    $new_url,
    $duplicateTitle,
    $row['sub_tittle'],
    $row['description'],
    $row['term'],
    $row['video'],
    $row['ticketing_type'],
    $row['multiple_dates'],
    $row['multiple_date_id'],
    $row['Organised'],
    $row['special_detail'],
    $row['image'],
    $row['start'],
    $row['end'],
    $row['user_id']
);

if ($insertStmt->execute()) {
    $new_event_id = $insertStmt->insert_id;

    /* duplicate related artists */
    $artistStmt = $conn->prepare("SELECT artist_id FROM event_artist WHERE event_id = ?");
    $artistStmt->bind_param('i', $row['eventid']);
    $artistStmt->execute();
    $artistRows = $artistStmt->get_result();
    $insArtist = $conn->prepare("INSERT INTO event_artist (event_id, artist_id) VALUES (?, ?)");
    while ($a = $artistRows->fetch_assoc()) {
        $insArtist->bind_param('ii', $new_event_id, $a['artist_id']);
        $insArtist->execute();
    }

    /* duplicate related categories */
    $catStmt = $conn->prepare("SELECT cat_id FROM event_category WHERE event_id = ?");
    $catStmt->bind_param('i', $row['eventid']);
    $catStmt->execute();
    $catRows = $catStmt->get_result();
    $insCat = $conn->prepare("INSERT INTO event_category (event_id, cat_id) VALUES (?, ?)");
    while ($c = $catRows->fetch_assoc()) {
        $insCat->bind_param('ii', $new_event_id, $c['cat_id']);
        $insCat->execute();
    }

    /* duplicate ticket tiers */
    $ticketStmt = $conn->prepare("SELECT * FROM event_tickets WHERE event_id = ?");
    $ticketStmt->bind_param('i', $row['eventid']);
    $ticketStmt->execute();
    $ticketRows = $ticketStmt->get_result();
    $insTicket = $conn->prepare("
        INSERT INTO event_tickets (event_id, created_by, ticket_name, max_tickets, tickets_left, ticket_cost, ticket_desc)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    while ($t = $ticketRows->fetch_assoc()) {
        $insTicket->bind_param(
            'iisiiss',
            $new_event_id,
            $t['created_by'],
            $t['ticket_name'],
            $t['max_tickets'],
            $t['max_tickets'], // tickets_left reset to full stock on duplicate
            $t['ticket_cost'],
            $t['ticket_desc']
        );
        $insTicket->execute();
    }

    echo "<script>
        alert('Event and related data duplicated successfully!');
        window.location.href = 'single-event?id=" . addslashes($new_url) . "';
        </script>";
    exit;
} else {
    echo "<script>alert('Error duplicating the event.'); window.location.href='all-events.php';</script>";
}
