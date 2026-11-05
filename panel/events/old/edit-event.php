<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit(); 
}

if ($_SESSION['user_type']== 0  ) {
    header("Location: /profile");
    exit(); 
}

?>

<?php
$pageTitle = "Event Creation"; // Set the page title
?>

<?php include '../include/config.php'?>
<?php include '../include/header.php';?>

<?php 

if (isset($_GET['type'])) {
    $event_type = $_GET['type'];  // Get the type parameter
} else {
    $event_type = 'rsvp';  // Default to 'rsvp' if no parameter is set
}
        if (isset($_GET['title'])) {
    $event_title = $_GET['title'];  // Get the type parameter
} else {
}

?>
  <link href="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/vendor/jquery-smartwizard/dist/css/smart_wizard.min.css" rel="stylesheet">
	<link href="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/vendor/bootstrap-select/dist/css/bootstrap-select.min.css" rel="stylesheet">
	
	 <link href="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/vendor/dropzone/dist/dropzone.css" rel="stylesheet">
    <!-- Custom Stylesheet -->
	<link href="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/vendor/jquery-nice-select/css/nice-select.css" rel="stylesheet">
    <link href="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/css/style.css" rel="stylesheet">
 

    		<style>.tab-content {
    height: auto !important;
    overflow: visible;
}

.plus-add-tickets {
    background-color: #e6e6e6;
    margin-top: 40px;
    height: 125px;
    cursor: pointer;
}
.add_ticket_symbol {
    color: #fff;
    font-size: 72px;
    text-align: center;
    display: block;
    line-height: 72px;
    margin-top: 15px;
}
.add_ticket_text {
    color: #000;
    font-size: 12px;
    text-align: center;
    display: block;
}</style>
  
				<script>
//   document.addEventListener('DOMContentLoaded', function() {
//     const startInput = document.getElementById('start');
//     const endInput = document.getElementById('end');

//     const now = new Date().toISOString().slice(0, 16);
//     startInput.min = now;
//     endInput.min = now;
//   });
</script>
<?php include '../include/sidebar.php'?>

        <!--**********************************
            Content body start
        ***********************************-->
      <div class="content-body">
    <div class="container-fluid">

<?php
function create_slug($string) {
    $slug = preg_replace('/[^A-Za-z0-9-]+/', '-', $string);
    return $slug . time();
}


$actual_link = 'https://' . $_SERVER['HTTP_HOST'];

if ($event_type == 'rsvp') {
    if (isset($_GET['id'])) {
        $url = $_GET['id'];

        // Retrieve event details from the database
        $sql_event = "SELECT * FROM events WHERE url = '$url'";
        $result = $conn->query($sql_event);
        if ($result && $result->num_rows > 0) {
            $event = $result->fetch_assoc();
            $event__id = $event['id'];
                $selected_venue = $event['sub_tittle']; 

        } else {
            echo "Event not found.";
            exit();
        }

        // Fetch existing categories and artists for the event
        $sql_fetch_categories = "SELECT cat_id FROM event_category WHERE event_id = '$event__id'";
        $result_categories = $conn->query($sql_fetch_categories);
        $selected_categories = [];
        while ($row = $result_categories->fetch_assoc()) {
            $selected_categories[] = $row['cat_id'];
        }

        $sql_fetch_artists = "SELECT artist_id FROM event_artist WHERE event_id = '$event__id'";
        $result_artists = $conn->query($sql_fetch_artists);
        $selected_artists = [];
        while ($row = $result_artists->fetch_assoc()) {
            $selected_artists[] = $row['artist_id'];
        }




        // Fetch event tickets
        $sql_tickets = "SELECT * FROM event_tickets WHERE event_id = $event__id";
        $tickets_result = $conn->query($sql_tickets);
        $tickets = $tickets_result->fetch_all(MYSQLI_ASSOC);
    }

    if (isset($_POST['save'])) {
        // Extract form data
        $title = mysqli_real_escape_string($conn, $_POST['event_name']);
        $place = mysqli_real_escape_string($conn, $_POST['venue_location']);
        $start = $_POST['start_event_date'];
        $end = $_POST['end_event_date'];
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        $video = mysqli_real_escape_string($conn, $_POST['video_url']);
        $organiser = mysqli_real_escape_string($conn, $_POST['organizer_name']);
        $special = mysqli_real_escape_string($conn, $_POST['special_entry_details']);
        $term = mysqli_real_escape_string($conn, $_POST['terms']);
        $multiple_dates_id = $_POST['multiple_date_id'];
        $multiple_dates = $_POST['multiple_dates'] ?? 0;
        $user_id = $_SESSION['user_id'];

        // Handle image upload only if a new image is provided
        $imagePath = $event['image'];
        if ($_FILES["event_image"]["error"] == 0) {
            $filename = $_FILES["event_image"]["name"];
            $tempname = $_FILES["event_image"]["tmp_name"];
            $folder = "../images/events/" . $filename;
            $folder1 = "$actual_link/images/events/" . $filename;

            if (move_uploaded_file($tempname, $folder)) {
                $imagePath = $folder1;
            }
        }

        // Update event details in the database
        $sql_event_update = "UPDATE events SET 
                                tittle = '$title', 
                                sub_tittle = '$place', 
                                start = '$start', 
                                end = '$end', 
                                description = '$description', 
                                multiple_date_id = '$multiple_dates_id',
                                multiple_dates = '$multiple_dates',
                                video = '$video', 
                                Organised = '$organiser', 
                                special_detail = '$special', 
                                term = '$term', 
                                image = '$imagePath', 
                                status = '4', 
                                event_status = '1', 
                                user_id = $user_id 
                            WHERE id = $event__id";

        if ($conn->query($sql_event_update) === TRUE) {
            // Delete previous categories and artists associated with the event
            $conn->query("DELETE FROM event_category WHERE event_id = '$event__id'");
            $conn->query("DELETE FROM event_artist WHERE event_id = '$event__id'");

            // Insert new categories if provided
            if (!empty($_POST['categories'])) {
                foreach ($_POST['categories'] as $category_id) {
                    $category_id = mysqli_real_escape_string($conn, $category_id);
                    $conn->query("INSERT INTO event_category (event_id, cat_id) VALUES ('$event__id', '$category_id')");
                }
            }

            // Insert new artists if provided
            if (!empty($_POST['artists'])) {
                foreach ($_POST['artists'] as $artist_id) {
                    $artist_id = mysqli_real_escape_string($conn, $artist_id);
                    $conn->query("INSERT INTO event_artist (event_id, artist_id) VALUES ('$event__id', '$artist_id')");
                }
            }

            // Delete previous tickets associated with the event
            $conn->query("DELETE FROM event_tickets WHERE event_id = '$event__id'");

            // Insert new tickets if provided
            if (!empty($_POST['ticket_name']) && !empty($_POST['ticket_tickets'])) {
                foreach ($_POST['ticket_name'] as $index => $ticket_name) {
                    $ticket_name = mysqli_real_escape_string($conn, $ticket_name);
                    $ticket_tickets = $_POST['ticket_tickets'][$index];
                    $ticket_desc = mysqli_real_escape_string($conn, $_POST['ticket_desc'][$index]);
                    $conn->query("INSERT INTO event_tickets (event_id, created_by, ticket_name, max_tickets, tickets_left, ticket_cost, ticket_desc) 
                                  VALUES ('$event__id', '$user_id', '$ticket_name', '$ticket_tickets', '$ticket_tickets', 0, '$ticket_desc')");
                }
            }

            echo "<script>alert('Event updated successfully!'); window.location.href = 'single-event?id=$url';</script>";
        } else {
            echo "Error: " . $sql_event_update . "<br>" . $conn->error;
        }
    }


?>

<!-- row -->
<div class="row">
    <div class="col-xl-12">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Edit RSVP/Guestlist Event</h4>
            </div>
            <div class="card-body">
                <form action="" method="POST" class="form-horizontal" role="form" enctype="multipart/form-data">
                    <div id="smartwizard" class="form-wizard order-create">
                            <ul class="nav nav-wizard">
                                <li><a class="nav-link" href="#wizard_Service">
                                    <span></span>
                                    <p class="mt-2 mb-0">Step 1</p>
                                </a></li>
                                <li><a class="nav-link" href="#wizard_Time">
                                    <span></span>
                                    <p class="mt-2 mb-0">Step 2</p>
                                </a></li>
                                <li><a class="nav-link" href="#wizard_Details">
                                    <span></span>
                                    <p class="mt-2 mb-0">Step 3</p>
                                </a></li>
                                <li><a class="nav-link" href="#wizard_Payment">
                                    <span></span>
                                    <p class="mt-2 mb-0">Step 4</p>
                                </a></li>
                            </ul>
                        <div class="tab-content">
                            <div id="wizard_Service"  class="tab-pane" role="tabpanel">
                                <div class="row">
                                    <div class="col-lg-6 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Event Name<span class="required">*</span></label>
                                            <input type="text" name="event_name" class="form-control" value="<?php echo $event['tittle']; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-6 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Venue/Location<span class="required">*</span></label>
                                            
                                                                                          <select id="select-places" name="venue_location" class="form-control" required>
    <?php
    // Fetch all available venues from the database
    $sql_all_venues = "SELECT id, name FROM place"; // Adjust table and column names as needed
    $result_all_venues = $conn->query($sql_all_venues);

    if ($result_all_venues) {
        while ($venue = $result_all_venues->fetch_assoc()) {
            $selected = ($venue['id'] == $selected_venue) ? 'selected' : '';
            $venue_name = htmlspecialchars($venue['name']);
            echo "<option value='{$venue['id']}' $selected>{$venue_name}</option>";
        }
    } else {
        echo "<option disabled>Error loading venues</option>";
    }
    ?>
</select>


                                          
                                        </div>
                                    </div>
                               <!-- Start Date -->
                    <div class="col-lg-6 mb-2">
                        <label>Start Event Date<span class="required">*</span></label>
                        <input type="datetime-local" id="start" name="start_event_date"
                               class="form-control"
                               value="<?php echo date('Y-m-d\TH:i', strtotime($event['start'])); ?>"
                               required>
                    </div>

                    <!-- End Date -->
                    <div class="col-lg-6 mb-2">
                        <label>End Event Date<span class="required">*</span></label>
                        <input type="datetime-local" id="end" name="end_event_date"
                               class="form-control"
                               value="<?php echo date('Y-m-d\TH:i', strtotime($event['end'])); ?>"
                               required>
                    </div>
                                    <div class="col-lg-6 mb-2">
                                        <label class="text-label form-label">Select Options<span class="required">*</span></label>
 <select id="multipledates" name="multiple_date_id" class="default-select form-control wide" aria-label="Default select example">
        <?php
        // Assume $multiple_dates_id is fetched from your database and holds the selected value (0 or 1)
        $multiple_dates_id = $event['multiple_date_id']; // Replace with actual variable holding the value

        // Generate options with conditional selected attribute
        $options = [
            '0' => 'Once',
            '1' => 'Multiple'
        ];
        foreach ($options as $value => $label) {
            $selected = ($value == $multiple_dates_id) ? 'selected' : '';
            echo "<option value='$value' $selected>$label</option>";
        }
        ?>
    </select>
                                    </div>
<div class="col-lg-6 mb-2" id="date-picker-container" style="display: none;">
    <div class="mb-3">
        <label class="text-label form-label">Select Multiple Dates<span class="required">*</span></label>
<input type="text" id="multipledatepicker" type="datetime-local" name="multiple_dates" class="form-control" value="<?php echo $event['multiple_dates']; ?>" readonly>   </div>
</div>
                                </div>
                            </div>
                            <div id="wizard_Time"  class="tab-pane" role="tabpanel">
                                <div class="row">
                                    <div class="col-lg-12 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Description<span class="required">*</span></label>
                                            <textarea id="descriptions" class="form-control" name="description"><?php echo $event['description']; ?></textarea>
                                        </div>
                                    </div>
                                    <div class="col-lg-6 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Image<span class="required">*</span></label>
                                            <input class="form-control" type="file" name="event_image" id="formFile">
                                            <img src="<?php echo $event['image']; ?>" alt="Event Image" width="100" height="100">
                                        </div>
                                    </div>
                                    <div class="col-lg-6 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Video URL</label>
                                            <input type="text" name="video_url" class="form-control" value="<?php echo $event['video']; ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div id="wizard_Details" class="tab-pane">
                                <div class="row">
                                    <div class="col-lg-6 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Organizer Name<span class="required">*</span></label>
                                            <input type="text" name="organizer_name" class="form-control" value="<?php echo $event['Organised']; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-6 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Special Entry Details<span class="required">*</span></label>
                                            <input type="text" name="special_entry_details" class="form-control" value="<?php echo $event['special_detail']; ?>">
                                        </div>
                                    </div>
                                                                       <div class="col-lg-6 mb-2">
    <div class="mb-3">
        <label class="text-label form-label">Event Category</label>
        <select id="select-categories" name="categories[]" multiple="multiple" class="form-control">
            <?php
            // Fetch all available categories
            $sql_all_categories = "SELECT id, cat_name FROM category";
            if ($result_all_categories = $conn->query($sql_all_categories)) {
                while ($category = $result_all_categories->fetch_assoc()) {
                    $selected = in_array($category['id'], $selected_categories) ? 'selected' : '';
                    $category_name = htmlspecialchars($category['cat_name']);
                    echo "<option value='{$category['id']}' $selected>{$category_name}</option>";
                }
            } else {
                echo "<option disabled>Error loading categories</option>";
            }
            ?>
        </select>
    </div>
</div>

<div class="col-lg-6 mb-2">
    <div class="mb-3">
        <label class="text-label form-label">Artist/Performer</label>
        
      
<select id="select-artists" name="artists[]" multiple="multiple" class="form-control select2-hidden-accessible" data-select2-id="select-artists">
    <?php
    // Fetch all available artists from the database
    $sql_all_artists = "SELECT id, name FROM artist";
    $result_all_artists = $conn->query($sql_all_artists);

    // Ensure $selected_artists contains the preselected artist IDs from the event (fetched earlier)
    if ($result_all_artists) {
        while ($artist = $result_all_artists->fetch_assoc()) {
            $selected = in_array($artist['id'], $selected_artists) ? 'selected' : '';
            $artist_name = htmlspecialchars($artist['name']);
            echo "<option value='{$artist['id']}' $selected>{$artist_name}</option>";
        }
    } else {
        echo "<option disabled>Error loading artists</option>";
    }
    ?>
</select>
    </div>
</div>

                                    <div class="col-lg-12 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Terms & Conditions<span class="required">*</span></label>
                                            <textarea id="terms" class="form-control" name="terms"><?php echo $event['term']; ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div id="wizard_Payment"  class="tab-pane" role="tabpanel">
                                <div class="row" id="ticket_view_div" class="d-flex flex-wrap">
                                    <input type="hidden" id="ticket_count" value="<?php echo count($tickets); ?>">

                                    <?php if ($tickets): ?>
                                        <?php foreach ($tickets as $index => $ticket): ?>
                                            <div class="col-sm-3 ticket1 each_ticket_section">
                                                <input name="ticket_name[]" placeholder="Ticket Name" type="text" class="form-control input_ticket each_ticket_section_input" value="<?php echo $ticket['ticket_name']; ?>" required>
                                                <input name="ticket_tickets[]" placeholder="Number Of Tickets" type="number" min="0" class="form-control input_ticket each_ticket_section_input no_tickets" value="<?php echo $ticket['max_tickets']; ?>" required>
                                                <textarea name="ticket_desc[]" placeholder="Description" rows="4" class="form-control input_ticket ticket_note"><?php echo $ticket['ticket_desc']; ?></textarea>
                                                <input type="hidden" name="ticket_id[]" value="<?php echo $ticket['ticket_id']; ?>">
                                                <span style="float: right; display: block; color: red; font-size: 10px; margin-top: 5px; cursor: pointer;" class="del_class" data-ticket-id="<?php echo $ticket['ticket_id']; ?>">Delete</span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>No tickets available for this event.</p>
                                    <?php endif; ?>
                                </div>
<button type="submit"name="save" class="btn btn-lg btn-primary" style="
    float: right;
"><span
	class="btn-icon-start text-info"><i class="fa fa-plus color-info"></i>
</span>Publish Event </button>                                
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>


<script>
document.querySelector("form").addEventListener("submit", function(e) {
    let valid = true;
    let start = document.getElementById("start");
    let end   = document.getElementById("end");

    // Reset previous error styles
    start.classList.remove("is-invalid");
    end.classList.remove("is-invalid");

    // Validate required fields
    if (!start.value) {
        valid = false;
        start.classList.add("is-invalid");
    }
    if (!end.value) {
        valid = false;
        end.classList.add("is-invalid");
    }

    // Validate end > start
    if (start.value && end.value && new Date(end.value) <= new Date(start.value)) {
        valid = false;
        end.classList.add("is-invalid");
        alert("End Date must be after Start Date.");
    }

    if (!valid) {
        e.preventDefault(); // Stop form submission
        alert("Please fill all required fields before publishing.");
    }
});
</script>

       
<script>
// Handling dynamic ticket addition
const ticketViewDiv = document.getElementById('ticket_view_div');  // The container where tickets will be added
const addTicketButtonContainer = document.createElement('div');
addTicketButtonContainer.classList.add('col-sm-3');
addTicketButtonContainer.innerHTML = `
    <div id="plus_add_tickets" class="col-sm-10 col-sm-offset-1 plus-add-tickets">
        <span class="add_ticket_symbol">+</span>
        <span class="add_ticket_text">Add Tickets</span>
    </div>
`;

ticketViewDiv.appendChild(addTicketButtonContainer);

// Track the ticket count (initialize with the count of existing tickets if any)
let ticketCount = parseInt(document.getElementById('ticket_count').value) || 0;

// Function to create a new ticket
function createTicket() {
    ticketCount++;
    const newTicket = document.createElement('div');
    newTicket.classList.add('col-sm-3', 'ticket1', 'each_ticket_section');
    newTicket.innerHTML = `
        <input name="ticket_name[]" placeholder="Ticket Name" type="text" class="form-control input_ticket each_ticket_section_input" id="input_ticket_name${ticketCount}" required>
        <input name="ticket_tickets[]" placeholder="Number Of Tickets" type="number" min="0" class="form-control input_ticket each_ticket_section_input no_tickets" id="input_ticket_no${ticketCount}" required>
        <textarea name="ticket_desc[]" placeholder="Description" rows="4" class="form-control input_ticket ticket_note" id="input_ticket_desc${ticketCount}" required></textarea>
        <span style="float: right; display: block; color: red; font-size: 10px; margin-top: 5px; cursor: pointer;" class="del_class" data-ticket-id="${ticketCount}">Delete</span>
    `;
    ticketViewDiv.appendChild(newTicket);

    // Add delete functionality
    const deleteButton = newTicket.querySelector(`.del_class[data-ticket-id="${ticketCount}"]`);
    deleteButton.addEventListener('click', function() {
        newTicket.remove();
    });

    // Move the "Add Tickets" button to the last place
    ticketViewDiv.appendChild(addTicketButtonContainer);
}

// Event listener for adding a new ticket when "Add Tickets" is clicked
addTicketButtonContainer.querySelector('#plus_add_tickets').addEventListener('click', createTicket);

// Event delegation for the delete buttons
ticketViewDiv.addEventListener('click', function(event) {
    if (event.target && event.target.classList.contains('del_class')) {
        const ticketId = event.target.getAttribute('data-ticket-id');
        const ticketToDelete = document.querySelector(`.ticket1 .del_class[data-ticket-id="${ticketId}"]`).parentElement;
        ticketToDelete.remove();
    }
});

</script>

         <?php
    // Check if the event type is 'paid' (for paid events)
    }
elseif ($event_type == 'ticket') {
    
    

  if (isset($_GET['id'])) {
        $url = $_GET['id'];

        // Retrieve event details from the database
        $sql_event = "SELECT * FROM events WHERE url = '$url'";
        $result = $conn->query($sql_event);
        if ($result && $result->num_rows > 0) {
            $event = $result->fetch_assoc();
            $event__id = $event['id'];
                $selected_venue = $event['sub_tittle']; 

        } else {
            echo "Event not found.";
            exit();
        }

        // Fetch existing categories and artists for the event
        $sql_fetch_categories = "SELECT cat_id FROM event_category WHERE event_id = '$event__id'";
        $result_categories = $conn->query($sql_fetch_categories);
        $selected_categories = [];
        while ($row = $result_categories->fetch_assoc()) {
            $selected_categories[] = $row['cat_id'];
        }

        $sql_fetch_artists = "SELECT artist_id FROM event_artist WHERE event_id = '$event__id'";
        $result_artists = $conn->query($sql_fetch_artists);
        $selected_artists = [];
        while ($row = $result_artists->fetch_assoc()) {
            $selected_artists[] = $row['artist_id'];
        }




        // Fetch event tickets
        $sql_tickets = "SELECT * FROM event_tickets WHERE event_id = $event__id";
        $tickets_result = $conn->query($sql_tickets);
        $tickets = $tickets_result->fetch_all(MYSQLI_ASSOC);
    }

    if (isset($_POST['save'])) {
        // Extract form data
        $title = mysqli_real_escape_string($conn, $_POST['event_name']);
        $place = mysqli_real_escape_string($conn, $_POST['venue_location']);
        $start = $_POST['start_event_date'];
        $end = $_POST['end_event_date'];
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        $video = mysqli_real_escape_string($conn, $_POST['video_url']);
        $organiser = mysqli_real_escape_string($conn, $_POST['organizer_name']);
        $special = mysqli_real_escape_string($conn, $_POST['special_entry_details']);
        $term = mysqli_real_escape_string($conn, $_POST['terms']);
        $multiple_dates_id = $_POST['multiple_date_id'];
        $multiple_dates = $_POST['multiple_dates'] ?? 0;
        $user_id = $_SESSION['user_id'];

        // Handle image upload only if a new image is provided
        $imagePath = $event['image'];
        if ($_FILES["event_image"]["error"] == 0) {
            $filename = $_FILES["event_image"]["name"];
            $tempname = $_FILES["event_image"]["tmp_name"];
            $folder = "../images/events/" . $filename;
            $folder1 = "$actual_link/images/events/" . $filename;

            if (move_uploaded_file($tempname, $folder)) {
                $imagePath = $folder1;
            }
        }

        // Update event details in the database
        $sql_event_update = "UPDATE events SET 
                                tittle = '$title', 
                                sub_tittle = '$place', 
                                start = '$start', 
                                end = '$end', 
                                description = '$description', 
                                multiple_date_id = '$multiple_dates_id',
                                multiple_dates = '$multiple_dates',
                                video = '$video', 
                                Organised = '$organiser', 
                                special_detail = '$special', 
                                term = '$term', 
                                image = '$imagePath', 
                                status = '4', 
                                event_status = '1', 
                                user_id = $user_id 
                            WHERE id = $event__id";

        if ($conn->query($sql_event_update) === TRUE) {
            // Delete previous categories and artists associated with the event
            $conn->query("DELETE FROM event_category WHERE event_id = '$event__id'");
            $conn->query("DELETE FROM event_artist WHERE event_id = '$event__id'");

            // Insert new categories if provided
            if (!empty($_POST['categories'])) {
                foreach ($_POST['categories'] as $category_id) {
                    $category_id = mysqli_real_escape_string($conn, $category_id);
                    $conn->query("INSERT INTO event_category (event_id, cat_id) VALUES ('$event__id', '$category_id')");
                }
            }

            // Insert new artists if provided
            if (!empty($_POST['artists'])) {
                foreach ($_POST['artists'] as $artist_id) {
                    $artist_id = mysqli_real_escape_string($conn, $artist_id);
                    $conn->query("INSERT INTO event_artist (event_id, artist_id) VALUES ('$event__id', '$artist_id')");
                }
            }

            // Delete previous tickets associated with the event
            $conn->query("DELETE FROM event_tickets WHERE event_id = '$event__id'");

       // Insert ticket details into event_tickets table if tickets are provided
    if (!empty($_POST['ticket_name']) && !empty($_POST['ticket_tickets'])) {
        foreach ($_POST['ticket_name'] as $index => $ticket_name) {
            $ticket_name = mysqli_real_escape_string($conn, $ticket_name);
            $ticket_tickets = $_POST['ticket_tickets'][$index];
            $ticket_desc = mysqli_real_escape_string($conn, $_POST['ticket_desc'][$index]);
            $ticket_cost = mysqli_real_escape_string($conn, $_POST['ticket_cost'][$index]);

            // Insert each ticket for the event
            $sql_ticket = "INSERT INTO event_tickets (event_id, created_by, ticket_name, max_tickets,tickets_left, ticket_cost, ticket_desc)
                          VALUES ('$event__id', '$user_id', '$ticket_name', '$ticket_tickets', '$ticket_tickets', '$ticket_cost', '$ticket_desc')";

            // Output ticket SQL query for debugging
            echo "<script>alert('Ticket SQL Query: " . addslashes($sql_ticket) . "');</script>";

            if (!$conn->query($sql_ticket)) {
                echo "Error: " . $conn->error;
            }
        }
    }


            echo "<script>alert('Event updated successfully!'); window.location.href = 'single-event?id=$url';</script>";
        } else {
            echo "Error: " . $sql_event_update . "<br>" . $conn->error;
        }
    }

?>



           <!-- row -->
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Paid Event Creation</h4>
                    </div>
                    <div class="card-body">
                                                           <!-- Event Creation Form -->
    <form action="" method="POST" class="form-horizontal" role="form" enctype="multipart/form-data">
                    <div id="smartwizard" class="form-wizard order-create">
                            <ul class="nav nav-wizard">
                                <li><a class="nav-link" href="#wizard_Service">
                                    <span></span>
                                    <p class="mt-2 mb-0">Step 1</p>
                                </a></li>
                                <li><a class="nav-link" href="#wizard_Time">
                                    <span></span>
                                    <p class="mt-2 mb-0">Step 2</p>
                                </a></li>
                                <li><a class="nav-link" href="#wizard_Details">
                                    <span></span>
                                    <p class="mt-2 mb-0">Step 3</p>
                                </a></li>
                                <li><a class="nav-link" href="#wizard_Payment">
                                    <span></span>
                                    <p class="mt-2 mb-0">Step 4</p>
                                </a></li>
                            </ul>
                        <div class="tab-content">
                            <div id="wizard_Service" class="tab-pane">
                                <div class="row">
                                    <div class="col-lg-6 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Event Name<span class="required">*</span></label>
                                            <input type="text" name="event_name" class="form-control" value="<?php echo $event['tittle']; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-6 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Venue/Location<span class="required">*</span></label>
                                            
                                                                                          <select id="select-places" name="venue_location" class="form-control" required>
    <?php
    // Fetch all available venues from the database
    $sql_all_venues = "SELECT id, name FROM place"; // Adjust table and column names as needed
    $result_all_venues = $conn->query($sql_all_venues);

    if ($result_all_venues) {
        while ($venue = $result_all_venues->fetch_assoc()) {
            $selected = ($venue['id'] == $selected_venue) ? 'selected' : '';
            $venue_name = htmlspecialchars($venue['name']);
            echo "<option value='{$venue['id']}' $selected>{$venue_name}</option>";
        }
    } else {
        echo "<option disabled>Error loading venues</option>";
    }
    ?>
</select>


                                          
                                        </div>
                                    </div>
                                    <div class="col-lg-6 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Start Event Date<span class="required">*</span></label>
                                            <input type="datetime-local" name="start_event_date" class="form-control" value="<?php echo $event['start']; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-6 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">End Event Date<span class="required">*</span></label>
                                            <input type="datetime-local" name="end_event_date" class="form-control" value="<?php echo $event['end']; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-6 mb-2">
                                        <label class="text-label form-label">Select Options<span class="required">*</span></label>
 <select id="multipledates" name="multiple_date_id" class="default-select form-control wide" aria-label="Default select example">
        <?php
        // Assume $multiple_dates_id is fetched from your database and holds the selected value (0 or 1)
        $multiple_dates_id = $event['multiple_date_id']; // Replace with actual variable holding the value

        // Generate options with conditional selected attribute
        $options = [
            '0' => 'Once',
            '1' => 'Multiple'
        ];
        foreach ($options as $value => $label) {
            $selected = ($value == $multiple_dates_id) ? 'selected' : '';
            echo "<option value='$value' $selected>$label</option>";
        }
        ?>
    </select>
                                    </div>
<div class="col-lg-6 mb-2" id="date-picker-container" style="display: none;">
    <div class="mb-3">
        <label class="text-label form-label">Select Multiple Dates<span class="required">*</span></label>
<input type="text" id="multipledatepicker" type="datetime-local" name="multiple_dates" class="form-control" value="<?php echo $event['multiple_dates']; ?>" readonly>   </div>
</div>
                                </div>
                            </div>
                            <div id="wizard_Time" class="tab-pane">
                                <div class="row">
                                    <div class="col-lg-12 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Description<span class="required">*</span></label>
                                            <textarea id="descriptions" class="form-control" name="description"><?php echo $event['description']; ?></textarea>
                                        </div>
                                    </div>
                                    <div class="col-lg-6 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Image<span class="required">*</span></label>
                                            <input class="form-control" type="file" name="event_image" id="formFile">
                                            <img src="<?php echo $event['image']; ?>" alt="Event Image" width="100" height="100">
                                        </div>
                                    </div>
                                    <div class="col-lg-6 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Video URL</label>
                                            <input type="text" name="video_url" class="form-control" value="<?php echo $event['video']; ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div id="wizard_Details" class="tab-pane">
                                <div class="row">
                                    <div class="col-lg-6 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Organizer Name<span class="required">*</span></label>
                                            <input type="text" name="organizer_name" class="form-control" value="<?php echo $event['Organised']; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-lg-6 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Special Entry Details<span class="required">*</span></label>
                                            <input type="text" name="special_entry_details" class="form-control" value="<?php echo $event['special_detail']; ?>">
                                        </div>
                                    </div>
                                                                         <div class="col-lg-6 mb-2">
    <div class="mb-3">
        <label class="text-label form-label">Event Category</label>
        <select id="select-categories" name="categories[]" multiple="multiple" class="form-control">
            <?php
            // Fetch all available categories
            $sql_all_categories = "SELECT id, cat_name FROM category";
            if ($result_all_categories = $conn->query($sql_all_categories)) {
                while ($category = $result_all_categories->fetch_assoc()) {
                    $selected = in_array($category['id'], $selected_categories) ? 'selected' : '';
                    $category_name = htmlspecialchars($category['cat_name']);
                    echo "<option value='{$category['id']}' $selected>{$category_name}</option>";
                }
            } else {
                echo "<option disabled>Error loading categories</option>";
            }
            ?>
        </select>
    </div>
</div>

<div class="col-lg-6 mb-2">
    <div class="mb-3">
        <label class="text-label form-label">Artist/Performer</label>
        
      
<select id="select-artists" name="artists[]" multiple="multiple" class="form-control select2-hidden-accessible" data-select2-id="select-artists">
    <?php
    // Fetch all available artists from the database
    $sql_all_artists = "SELECT id, name FROM artist";
    $result_all_artists = $conn->query($sql_all_artists);

    // Ensure $selected_artists contains the preselected artist IDs from the event (fetched earlier)
    if ($result_all_artists) {
        while ($artist = $result_all_artists->fetch_assoc()) {
            $selected = in_array($artist['id'], $selected_artists) ? 'selected' : '';
            $artist_name = htmlspecialchars($artist['name']);
            echo "<option value='{$artist['id']}' $selected>{$artist_name}</option>";
        }
    } else {
        echo "<option disabled>Error loading artists</option>";
    }
    ?>
</select>
    </div>
</div>

                                    <div class="col-lg-12 mb-2">
                                        <div class="mb-3">
                                            <label class="text-label form-label">Terms & Conditions<span class="required">*</span></label>
                                            <textarea id="terms" class="form-control" name="terms"><?php echo $event['term']; ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
           
            
            
                                        <div id="wizard_Payment" class="tab-pane">
                                <div class="row" id="ticket_view_div" class="d-flex flex-wrap">
                                    <input type="hidden" id="ticket_count" value="<?php echo count($tickets); ?>">

                                    <?php if ($tickets): ?>
                                        <?php foreach ($tickets as $index => $ticket): ?>
                                            <div class="col-sm-3 ticket1 each_ticket_section">
                                                <input name="ticket_name[]" placeholder="Ticket Name" type="text" class="form-control input_ticket each_ticket_section_input" value="<?php echo $ticket['ticket_name']; ?>" required>
                                                <input name="ticket_tickets[]" placeholder="Number Of Tickets" type="number" min="0" class="form-control input_ticket each_ticket_section_input no_tickets" value="<?php echo $ticket['max_tickets']; ?>" required>
                                                                                                <input name="ticket_cost[]" placeholder="Ticket pricing" type="number" min="0" class="form-control input_ticket each_ticket_section_input no_tickets" id="input_ticket_cost1"  value="<?php echo $ticket['ticket_cost']; ?>" required="">

                                                <textarea name="ticket_desc[]" placeholder="Description" rows="4" class="form-control input_ticket ticket_note"><?php echo $ticket['ticket_desc']; ?></textarea>
                                                <input type="hidden" name="ticket_id[]" value="<?php echo $ticket['ticket_id']; ?>">
                                                <span style="float: right; display: block; color: red; font-size: 10px; margin-top: 5px; cursor: pointer;" class="del_class" data-ticket-id="<?php echo $ticket['ticket_id']; ?>">Delete</span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p>No tickets available for this event.</p>
                                    <?php endif; ?>
                                </div>
<button type="submit" name="save" class="btn btn-lg btn-primary" style="
    float: right;
"><span
	class="btn-icon-start text-info"><i class="fa fa-plus color-info"></i>
</span>Publish Event </button>                                
                            </div>

        </div>
    </div>
</form>

                    </div>
                </div>
            </div>
        </div>
        
        
        
 <script>
// Handling dynamic ticket addition
const ticketViewDiv = document.getElementById('ticket_view_div');
const addTicketButtonContainer = document.createElement('div');
addTicketButtonContainer.classList.add('col-sm-3');
addTicketButtonContainer.innerHTML = `
    <div id="plus_add_tickets" class="col-sm-10 col-sm-offset-1 plus-add-tickets">
        <span class="add_ticket_symbol">+</span>
        <span class="add_ticket_text">Add Tickets</span>
    </div>
`;
ticketViewDiv.appendChild(addTicketButtonContainer);

let ticketCount = parseInt(document.getElementById('ticket_count').value);

function createTicket() {
    ticketCount++;
    const newTicket = document.createElement('div');
    newTicket.classList.add('col-sm-3', 'ticket1', 'each_ticket_section');
    newTicket.innerHTML = `
        <input name="ticket_name[]" placeholder="Ticket Name" type="text" class="form-control input_ticket each_ticket_section_input" id="input_ticket_name${ticketCount}" required="">
        <input name="ticket_tickets[]" placeholder="Number Of Tickets" type="number" min="0" class="form-control input_ticket each_ticket_section_input no_tickets" id="input_ticket_no${ticketCount}" required="">
        <input name="ticket_cost[]" placeholder="Ticket Pricing" type="number" min="0" class="form-control input_ticket each_ticket_section_input no_tickets" id="input_ticket_cost${ticketCount}" required="">

        <textarea name="ticket_desc[]" placeholder="Description" rows="4" class="form-control input_ticket ticket_note" id="input_ticket_desc${ticketCount}" required=""></textarea>
        <span style="float: right; display: block; color: red; font-size: 10px; margin-top: 5px; cursor: pointer;" class="del_class" data-ticket-id="${ticketCount}">Delete</span>
    `;
    ticketViewDiv.appendChild(newTicket);

    const deleteButton = newTicket.querySelector(`.del_class[data-ticket-id="${ticketCount}"]`);
    deleteButton.addEventListener('click', function() {
        newTicket.remove();
    });

    // Move the "Add Tickets" button to the last place
    ticketViewDiv.appendChild(addTicketButtonContainer);
}

addTicketButtonContainer.querySelector('#plus_add_tickets').addEventListener('click', createTicket);

// Event delegation for the delete buttons
ticketViewDiv.addEventListener('click', function(event) {
    if (event.target && event.target.classList.contains('del_class')) {
        const ticketId = event.target.getAttribute('data-ticket-id');
        const ticketToDelete = document.querySelector(`.ticket1 .del_class[data-ticket-id="${ticketId}"]`).parentElement;
        ticketToDelete.remove();
    }
});
</script>
        
       
         <?php
    } else {
        echo "<p>Invalid event type.</p>";
    }
    ?>
        
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">


<!-- Include Flatpickr JS -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<!-- Include Select2 CSS and JS (Only if Select2 is used) -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet" />
<!--<script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>-->
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.8/js/select2.min.js" defer></script>



								
        <!--**********************************
            Content body end
        ***********************************-->
        



<script>
    // Initialize Flatpickr for multiple dates
    flatpickr("#multipledatepicker", {
        mode: "multiple",      // Allows selecting multiple dates
        dateFormat: "Y-m-d",
        monthSelectorType: "static", // Set date format to Year-Month-Day
        defaultDate: [],       // If you want to set some default selected dates, add here
        onChange: function(selectedDates, dateStr, instance) {
            // You can handle the selected dates here if needed
            console.log(selectedDates); // This will print the selected dates
        }
    });
</script>
<script>
    // Wait for the document to be ready
    $(document).ready(function() {
        // Check the initial selection to hide or show the date picker
        toggleDatePicker();

        // Detect change in dropdown selection
        $('#multipledates').on('change', function() {
            toggleDatePicker();
        });

        // Function to show/hide the multiple date picker based on selection
        function toggleDatePicker() {
            var selectedOption = $('#multipledates').val();
            if (selectedOption == '1') {  // 'Multiple' selected
                $('#date-picker-container').show();  // Show the date picker
            } else {
                $('#date-picker-container').hide();  // Hide the date picker
            }
        }
    });
</script>

<script>
$(document).ready(function() {
    function initializeSelect2(selector, placeholder, type) {
        $(selector).select2({
            placeholder: placeholder,
            minimumInputLength: 2,
            ajax: {
                url: '/events/autocomplete.php',
                dataType: 'json',
                delay: 250, // Add a slight delay for better performance
                data: function(params) {
                    return {
                        term: params.term,  // The search term
                        type: type          // The type of data being fetched
                    };
                },
                processResults: function(data) {
                    console.log('Data received from server:', data); // Log data to the console
                    return {
                        results: data.results.map(function(item) {
                            return {
                                id: item.id,     // Use the ID for the value
                                text: item.text  // Use the name for display
                            };
                        })
                    };
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Error fetching data:', textStatus, errorThrown);
                }
            }
        });
    }

    // Initialize the Select2 for each field
    initializeSelect2('#select-categories', 'Search and select categories', 'category');
    initializeSelect2('#select-artists', 'Search and select artists', 'artist');
    initializeSelect2('#select-places', 'Search and select Places', 'place');

    
});


</script>


        <!--**********************************
           Support ticket button start
        ***********************************-->

        <!--**********************************
           Support ticket button end
        ***********************************-->
		<!--**********************************
				Footer start
			***********************************-->
			<div class="footer out-footer style-2">
				<div class="copyright">
					<p>Copyright © Designed &amp; Developed by <a href="#" target="_blank">Vinit Gupta</a> 2024</p>
				</div>
			</div>
			<!--**********************************
				Footer end
			***********************************-->
        
    </div>
    <!--**********************************
        Main wrapper end
    ***********************************-->
    <script src="https://cdn.ckeditor.com/ckeditor5/39.0.0/classic/ckeditor.js"></script>

 <script>
        ClassicEditor
            .create(document.querySelector('#descriptions'))
            .catch(error => {
                console.error(error);
            });
        ClassicEditor
            .create(document.querySelector('#terms'))
            .catch(error => {
                console.error(error);
            });
    </script>
    <!--**********************************
        Scripts
    ***********************************-->
    <!-- Required vendors -->
    <script src="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/vendor/global/global.min.js"></script>
	<script src="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/vendor/bootstrap-select/dist/js/bootstrap-select.min.js"></script>	
    <script src="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/vendor/jquery-steps/build/jquery.steps.min.js"></script>
    <script src="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/vendor/jquery-validation/jquery.validate.min.js"></script>
    <!-- Form validate init -->
    <script src="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/js/plugins-init/jquery.validate-init.js"></script>


	<!-- Form Steps -->
	<script src="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/vendor/jquery-smartwizard/dist/js/jquery.smartWizard.js"></script>
	<script src="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/vendor/jquery-nice-select/js/jquery.nice-select.min.js"></script>
	
	 <script src="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/vendor/dropzone/dist/dropzone.js"></script>
	     <!-- Daterangepicker -->
   
	<script src="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/js/custom.min.js"></script>
	<script src="<?php echo 'https://' . $_SERVER['HTTP_HOST']; ?>/js/dlabnav-init.js"></script>
	
	<script>
		$(document).ready(function(){
			// SmartWizard initialize
			$('#smartwizard').smartWizard(); 
		});
	</script>
		
</body>


<!-- Mirrored from akademi.dexignlab.com/xhtml/form-wizard.html by HTTrack Website Copier/3.x [XR&CO'2014], Sun, 10 Nov 2024 07:06:15 GMT -->
</html>