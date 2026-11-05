<?php
ob_start();  // Start output buffering
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit(); 
}
?>

<?php include '../include/config.php'; ?>

<?php 
// Check if the event ID is set
if (isset($_REQUEST['id'])) {
    $id = $_REQUEST['id'];

    // Fetch the event details based on the passed id
    $query = "
       SELECT 
            events.url,
            events.tittle,
            events.banner_status,
            events.sub_tittle,
            events.price,
            events.status,
            events.ticketing_type,
            events.count,
            events.description,
            events.multiple_dates,
            events.multiple_date_id,
            
            events.term,
                        events.id as eventid,

            events.video,
            events.artist,
            events.Organised,
            events.special_detail,
            events.image,
            events.user_id,
            events.start,
            events.end,
            place.address AS placeadd,
            place.url AS urls,
            place.image_url AS placeimage,
            artist.name AS artistname,
            event_artist.id,
            event_artist.artist_id,
            artist.image_url AS artistimage,
            artist.url AS artisturl
       FROM 
            events
       INNER JOIN 
            place ON events.sub_tittle = place.id
       INNER JOIN 
            event_artist ON event_artist.event_id = events.id
       INNER JOIN 
            artist ON event_artist.artist_id = artist.id
       WHERE 
            events.url = '$id' LIMIT 1";

    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);

        // Generate a new unique URL and title
        $new_url = $row['url'] . '-duplicate-' . uniqid();
        $duplicateTitle = mysqli_real_escape_string($conn, $row['tittle'] . ' duplicate');

        // Duplicate the main event
        $insert_query = "
            INSERT INTO events (
                url, tittle, banner_status, sub_tittle, price, status, count, 
                description, term, video, ticketing_type,multiple_dates,multiple_date_id, Organised, special_detail, image, start, end, user_id
            ) VALUES (
                '" . mysqli_real_escape_string($conn, $new_url) . "',
                '" . $duplicateTitle . "',
                '0',
                '" . mysqli_real_escape_string($conn, $row['sub_tittle']) . "',
                '" . mysqli_real_escape_string($conn, $row['price']) . "',
                '2',
                '0',
                '" . mysqli_real_escape_string($conn, $row['description']) . "',
                '" . mysqli_real_escape_string($conn, $row['term']) . "',
                '" . mysqli_real_escape_string($conn, $row['video']) . "',
                '" . mysqli_real_escape_string($conn, $row['ticketing_type']) . "',
                                '" . mysqli_real_escape_string($conn, $row['multiple_dates']) . "',
                '" . mysqli_real_escape_string($conn, $row['multiple_date_id']) . "',

                '" . mysqli_real_escape_string($conn, $row['Organised']) . "',
                '" . mysqli_real_escape_string($conn, $row['special_detail']) . "',
                '" . mysqli_real_escape_string($conn, $row['image']) . "',
                '" . mysqli_real_escape_string($conn, $row['start']) . "',
                '" . mysqli_real_escape_string($conn, $row['end']) . "',
                '" . mysqli_real_escape_string($conn, $row['user_id']) . "'
            )";

        if (mysqli_query($conn, $insert_query)) {
            // Get the new event ID
            $new_event_id = mysqli_insert_id($conn);

            // Duplicate event_artist table data
            $event_artist_query = "SELECT * FROM event_artist WHERE event_id = " . $row['eventid'];
            $event_artist_result = mysqli_query($conn, $event_artist_query);

            if ($event_artist_result && mysqli_num_rows($event_artist_result) > 0) {
                while ($artist_row = mysqli_fetch_assoc($event_artist_result)) {
                    $insert_event_artist = "
                        INSERT INTO event_artist (event_id, artist_id) 
                        VALUES ('" . $new_event_id . "', '" . $artist_row['artist_id'] . "')";
                    mysqli_query($conn, $insert_event_artist);
                }
            }

            // Duplicate event_category table data
            $event_category_query = "SELECT * FROM event_category WHERE event_id = " . $row['eventid'];
            $event_category_result = mysqli_query($conn, $event_category_query);

            if ($event_category_result && mysqli_num_rows($event_category_result) > 0) {
                while ($category_row = mysqli_fetch_assoc($event_category_result)) {
                    $insert_event_category = "
                        INSERT INTO event_category (event_id, cat_id) 
                        VALUES ('" . $new_event_id . "', '" . $category_row['cat_id'] . "')";
                    mysqli_query($conn, $insert_event_category);
                }
            }

            // Duplicate event_tickets table data
            $event_tickets_query = "SELECT * FROM event_tickets WHERE event_id = " . $row['eventid'];
            $event_tickets_result = mysqli_query($conn, $event_tickets_query);

            if ($event_tickets_result && mysqli_num_rows($event_tickets_result) > 0) {
                while ($ticket_row = mysqli_fetch_assoc($event_tickets_result)) {
                    $insert_event_ticket = "
                        INSERT INTO event_tickets (event_id, created_by, ticket_name, max_tickets, tickets_left, ticket_cost, ticket_desc, created_at)
                        VALUES (
                            '" . $new_event_id . "',
                            '" . mysqli_real_escape_string($conn, $ticket_row['created_by']) . "',
                            '" . mysqli_real_escape_string($conn, $ticket_row['ticket_name']) . "',
                            '" . mysqli_real_escape_string($conn, $ticket_row['max_tickets']) . "',
                            '" . mysqli_real_escape_string($conn, $ticket_row['tickets_left']) . "',
                            '" . mysqli_real_escape_string($conn, $ticket_row['ticket_cost']) . "',
                            '" . mysqli_real_escape_string($conn, $ticket_row['ticket_desc']) . "',
                            '" . mysqli_real_escape_string($conn, $ticket_row['created_at']) . "'
                        )";
                    mysqli_query($conn, $insert_event_ticket);
                }
            }

             echo "<script>
                alert('Event and related data duplicated successfully!');
                window.location.href = 'single-event.php?id=" . $new_url . "';
                </script>";
            exit;
        } else {
            echo "<script>alert('Error duplicating the event.');</script>";
        }
    } else {
        echo "<script>alert('Event not found.');</script>";
    }
} else {
    echo "<script>alert('No event ID provided.');</script>";
}
?>
