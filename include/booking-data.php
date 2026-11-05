<?php
include __DIR__ . '/config.php';

// Get URL param safely (escaped to prevent SQL injection)
$id = mysqli_real_escape_string($conn, $_REQUEST['url'] ?? '');

$query = "SELECT 
        events.*, 
        events.multiple_dates AS multiple_dates,
        place.name AS placename, 
        events.image as image,
        place.address AS placeadd, 
        place.city AS placecity, 
        place.url AS placeurl, 
        place.image_url AS placeimage, 
        artist.name AS artistname, 
        artist.image_url AS artistimage, 
        artist.url AS artisturl,
        event_category.cat_id as cat_id,
        category.cat_name as catname
    FROM events
    LEFT JOIN place ON events.sub_tittle = place.id
    LEFT JOIN event_artist ON events.id = event_artist.event_id
    LEFT JOIN artist ON event_artist.artist_id = artist.id
    LEFT JOIN event_category ON events.id = event_category.event_id
    LEFT JOIN category ON event_category.cat_id = category.id
    WHERE events.url = '$id' 
    LIMIT 1";

$result = mysqli_query($conn, $query) or die(mysqli_error($conn));
$row = mysqli_fetch_assoc($result);

if (!$row || $row['url'] != ($_GET['url'] ?? $_REQUEST['url'] ?? '')) {
    header("HTTP/1.1 404 Not Found");
    echo '<script> window.location.replace("/404") </script>';
    exit();
}

// Prepare multiple dates
$multipleDates1 = [];
$displayDateObj = null;

$originalStart = new DateTime($row['start']);
$now = new DateTime();

if (!empty($row['multiple_dates'])) {
    $dates = array_map('trim', explode(',', $row['multiple_dates']));

    foreach ($dates as $d) {
        $ts = strtotime($d . ' ' . $originalStart->format('H:i:s'));
        if ($ts >= $now->getTimestamp()) {
            $multipleDates1[] = date('Y-m-d H:i:s', $ts);
        }
    }

    if (!empty($multipleDates1)) {
        $displayDateObj = new DateTime($multipleDates1[0]);
    }
} elseif (!empty($row['start'])) {
    $displayDateObj = new DateTime($row['start']);
}

if ($displayDateObj) {
    $day   = $displayDateObj->format('D');
    $date  = $displayDateObj->format('d');
    $month = $displayDateObj->format('M');
    $time  = $displayDateObj->format('g:i a');
} else {
    $day = $date = $month = $time = '';
}

$monthss = $month;

// ---- Fetch real ticket types for this event ----
$ticketsSql = "SELECT event_tickets.id, event_tickets.ticket_name, event_tickets.ticket_cost,
                      event_tickets.ticket_desc, event_tickets.tickets_left
               FROM event_tickets
               INNER JOIN events ON event_tickets.event_id = events.id
               WHERE events.url = '" . mysqli_real_escape_string($conn, $id) . "'
               ORDER BY event_tickets.id ASC";
$ticketsResult = mysqli_query($conn, $ticketsSql) or die(mysqli_error($conn));
$ticketsArr = [];
while ($t = mysqli_fetch_assoc($ticketsResult)) {
    $ticketsArr[] = $t;
}

// Does every ticket on this event cost 0?
$allTicketsFree = true;
foreach ($ticketsArr as $t) {
    if ((float)$t['ticket_cost'] > 0) { $allTicketsFree = false; break; }
}

$currentDate = new DateTime();
$endDate = new DateTime($row['end']);
$eventIsOver = ($endDate <= $currentDate);

// events.status : 0 = Past/Archived, 1 = Published/Live, 2 = Draft,
//                 3 = Unapproved, 4 = Submitted for Approval
$isPublished = isset($row['status']) && (int)$row['status'] === 1;

$bookingAllowed = (!$eventIsOver && $isPublished);
$showTicketsPreviewOnly = (!$eventIsOver && !$isPublished);

// A single combined "event note" shown on the summary step
$eventNoteText = '';
if (!empty($row['term'])) {
    $eventNoteText .= trim(strip_tags($row['term']));
}
if (!empty($row['special_detail'])) {
    $sd = trim(strip_tags($row['special_detail']));
    if ($sd !== '') $eventNoteText .= ($eventNoteText !== '' ? ' ' : '') . $sd;
}
