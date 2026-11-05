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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

 

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
}

</style>
  
				<script>
  document.addEventListener('DOMContentLoaded', function() {
    const startInput = document.getElementById('start');
    const endInput = document.getElementById('end');

    const now = new Date().toISOString().slice(0, 16);
    startInput.min = now;
    endInput.min = now;
  });
</script>
<?php include '../include/sidebar.php'?>

        <!--**********************************
            Content body start
        ***********************************-->
      <div class="content-body">
    <div class="container-fluid">
        
        

<?php
// Check if the event type is 'rsvp' (for free events)
function create_slug($string)
{
    $slug = preg_replace('/[^A-Za-z0-9-]+/', '-', $string);
    return $slug . time();
}

if ($event_type == 'rsvp') {
    if (isset($_POST['save'])) {
          $title = mysqli_real_escape_string($conn, $_POST['event_name']);
        $place = $_POST['venue_location'];
        $start = $_POST['start_event_date'];
        $end = $_POST['end_event_date'];
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        $video = $_POST['video_url'];
        $organiser = $_POST['organizer_name'];
        $special = $_POST['special_entry_details'];
        $term = mysqli_real_escape_string($conn, $_POST['terms']);
        $multiple_dates_id = $_POST['multiple_date_id'];
        $multiple_dates = $_POST['multiple_dates'] ?? 0;
        $url = create_slug($title);

        // Handle image upload
        $actual_link = 'https://' . $_SERVER['HTTP_HOST'];
        $imagePath = "";
        $imageError = "";

        if ($_FILES["event_image"]["error"] == 0) {
            $filename = $_FILES["event_image"]["name"];
            $tempname = $_FILES["event_image"]["tmp_name"];
            $folder = "../images/events/" . $filename;
            $folder1 = "$actual_link/images/events/" . $filename;

            $allowed_extensions = ['jpg', 'jpeg', 'png'];
            $file_extension = pathinfo($filename, PATHINFO_EXTENSION);
            if (!in_array($file_extension, $allowed_extensions)) {
                $imageError = "Invalid file type!";
            } else {
                if (move_uploaded_file($tempname, $folder)) {
                    $imagePath = $folder1;
                } else {
                    $imageError = "Error uploading file!";
                }
            }
        } else {
            $imageError = "Error uploading image.";
        }

        if ($imageError) {
            echo $imageError;
            exit();
        }

        // Get user ID from session
        $user_id = $_SESSION['user_id'];

        // Insert event into database
        $sql_event = "INSERT INTO events (tittle, sub_tittle, start, end, multiple_date_id, multiple_dates, description, video, Organised, special_detail, term, image, status, url, event_status,ticketing_type, user_id)
                      VALUES ('$title', '$place', '$start', '$end', '$multiple_dates_id', '$multiple_dates', '$description', '$video', '$organiser', '$special', '$term', '$imagePath', '4', '$url', '1','rsvp', $user_id)";

        if ($conn->query($sql_event) === TRUE) {
            $event_id = $conn->insert_id;

     // Insert selected categories into the event_category table
    if (!empty($_POST['categories'])) {
        foreach ($_POST['categories'] as $category_id) {
            // Sanitize the category ID
            $category_id = mysqli_real_escape_string($conn, $category_id);

            // Insert each category into the event_category table
            $sql_category = "INSERT INTO event_category (event_id, cat_id) VALUES ('$event_id', '$category_id')";
            if (!$conn->query($sql_category)) {
                echo "Error inserting category: " . $conn->error;
            }
        }
    }

    // Insert selected artists into the event_artist table
    if (!empty($_POST['artists'])) {
        foreach ($_POST['artists'] as $artist_id) {
            // Sanitize the artist ID
            $artist_id = mysqli_real_escape_string($conn, $artist_id);

            // Insert each artist into the event_artist table
            $sql_artist = "INSERT INTO event_artist (event_id, artist_id) VALUES ('$event_id', '$artist_id')";
            if (!$conn->query($sql_artist)) {
                echo "Error inserting artist: " . $conn->error;
            }
        }
    }

    // Insert ticket details into event_tickets table if tickets are provided
    if (!empty($_POST['ticket_name']) && !empty($_POST['ticket_tickets'])) {
        foreach ($_POST['ticket_name'] as $index => $ticket_name) {
            $ticket_name = mysqli_real_escape_string($conn, $ticket_name);
            $ticket_tickets = $_POST['ticket_tickets'][$index];
            $ticket_desc = mysqli_real_escape_string($conn, $_POST['ticket_desc'][$index]);

            // Insert each ticket for the event
            $sql_ticket = "INSERT INTO event_tickets (event_id, created_by, ticket_name, max_tickets,tickets_left, ticket_cost, ticket_desc)
                           VALUES ('$event_id', '$user_id', '$ticket_name', '$ticket_tickets', '$ticket_tickets', '0', '$ticket_desc')";

            // Output ticket SQL query for debugging
            echo "<script>alert('Ticket SQL Query: " . addslashes($sql_ticket) . "');</script>";

            if (!$conn->query($sql_ticket)) {
                echo "Error: " . $conn->error;
            }
        }
    }

    // Redirect to the event edit page after successful creation
    $extra = "single-event?id=" . $url;
    echo "<script>window.location.href='" . $extra . "'</script>";
    exit();
} else {
    echo "Error: " . $sql_event . "<br>" . $conn->error;
}
}

?>

           
        <!-- row -->
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">RSVP/Guestlist Event Creation</h4>
                    </div>
                    <div class="card-body">
                                                               <form action="" class="form-horizontal" role="form" class="form-validate form-horizontal" method="POST" enctype="multipart/form-data">

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
                                <div id="wizard_Service" class="tab-pane" role="tabpanel">
                                    <div class="row">
                                        <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Event Name<span class="required">*</span></label>
                                                <input type="text" name="event_name" value="<?php echo $event_title ?>" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Venue/Location<span class="required">*</span></label>
                                                <!--<input type="text" name="venue_location" class="form-control" required>-->
                                                <select id="select-places" name="venue_location" class="form-control" required ></select>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Start Event Date<span class="required">*</span></label>
                                                <input type="datetime-local" name="start_event_date" id="start" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">End Event Date<span class="required">*</span></label>
                                                <input type="datetime-local" name="end_event_date" id="end" class="form-control" required>
                                            </div>
                                        </div>
                                       <div class="col-lg-6 mb-2">
    <label class="text-label form-label">Select Options<span class="required">*</span></label>
    <select id="multipledates" name="multiple_date_id" class="default-select form-control wide" aria-label="Default select example">
        <option value="0">Once</option>
        <option value="1">Multiple</option>
    </select>
</div>

<div class="col-lg-6 mb-2" id="date-picker-container" style="display: none;">
    <div class="mb-3">
        <label class="text-label form-label">Select Multiple Dates<span class="required">*</span></label>
<input type="text" id="multipledatepicker" type="datetime-local" name="multiple_dates" class="form-control" readonly>   </div>
</div>




    
                
    <!--<div class="form-group">-->
    <!--    <label for="select-places">Select Multiple Places</label>-->
    <!--    <select id="select-places" name="places[]" multiple="multiple" class="form-control">-->
            <!-- Options will be loaded dynamically -->
    <!--    </select>-->
    <!--</div>-->

    
                                    </div>
                                </div>
                                <div id="wizard_Time" class="tab-pane" role="tabpanel">
                                    <div class="row">
                                        <div class="col-lg-12 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Description<span class="required">*</span></label>
                                    <textarea id="descriptions" class="form-control" name="description" ></textarea>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Image<span class="required">*</span></label>
                                                <input class="form-control" type="file" name="event_image" id="formFile">
                                            </div>
                                        </div>
                                                                                <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Video URL</label>
                                                <input type="text" name="video_url" class="form-control" >
                                            </div>
                                        </div>

                                    </div>
                                </div>
                                <div id="wizard_Details" class="tab-pane" role="tabpanel">
                                    <div class="row">
                                        <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Organizer Name<span class="required">*</span></label>
                                                <input type="text" name="organizer_name" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Special Entry Details<span class="required">*</span></label>
                                                <input type="text" name="special_entry_details" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-2">
                                            <label class="text-label form-label">Event Category<span class="required">*</span></label>
                                            
                                             <select id="select-categories" name="categories[]" multiple="multiple" class="form-control">
            <!-- Options will be loaded dynamically -->
        </select>
                                          
                                           
                                        </div>
                                        <div class="col-lg-6 mb-2">
                                            <label class="text-label form-label">Artist/Performer<span class="required">*</span></label>
                                      
<select id="select-artists" name="artists[]" multiple="multiple" class="form-control"></select>




                                        </div>
                                        <div class="col-lg-12 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Terms & Conditions<span class="required">*</span></label>
                                    <textarea id="terms" class="form-control" name="terms" >
                                        
                                        
                                         <ul>
                     <li>Only 21+ allowed. Bring your ID cards for age verification.</li>
                     <li>For stags cover charges will be applicable as per venue&rsquo;s discretion throughout the night.
                     </li>
                     <li>The entry closes at 9:00 PM. Cover charges will be applicable post that as per venue&rsquo;s
                        discretion.</li>
                     <li>Men must wear closed footwear (Shoes) and full length bottoms. (Applicable for Night Clubs)</li>
                     <li>Tickets once booked cannot be exchanged or refunded.</li>
                     <li>Venues/Organizers are solely responsible for the service; availability and quality of the events.
                     </li>
                     <li>Highspinn does not take any responsibility for the activities going on inside or outside the
                        event. The entire responsibility of it is of the organizer/venue.</li>
                     <li>In certain circumstances, Highspinn reserves the right to cancel the tickets owing to any internal
                        reason which requires such action. In such cases, the customer will be provided full refund for the
                        ticket within 7-10 working days.</li>
                     <li>Venue/Organisers rules apply.</li>
                  </ul>
                                    </textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="wizard_Payment" class="tab-pane" role="tabpanel">
                                    <div class="container">
                                        <div class="row" id="ticket_view_div" class="d-flex flex-wrap">
                                        <input type="hidden" id="ticket_count" value="3">
                                            <div class="col-sm-3 ticket1 each_ticket_section">
                                                <input name="ticket_name[]" placeholder="Ticket Name" type="text" class="form-control input_ticket each_ticket_section_input" id="input_ticket_name1" required="" value="Stags">
                                                <input name="ticket_tickets[]" placeholder="Number Of Tickets" type="number" min="0" class="form-control input_ticket each_ticket_section_input no_tickets" id="input_ticket_no1" required="" value="100">
                                                <textarea name="ticket_desc[]" placeholder="Description" rows="4" class="form-control input_ticket ticket_note" id="input_ticket_desc1" required="">Each pass permits one stag to the event. Cover charges applicable at the venue throughout the event.
</textarea>
                                                <span style="float: right; display: block; color: red; font-size: 10px; margin-top: 5px; cursor: pointer;" class="del_class" data-ticket-id="1">Delete</span>
                                            </div>
                                                                                        <div class="col-sm-3 ticket1 each_ticket_section">
                                                <input name="ticket_name[]" placeholder="Ticket Name" type="text" class="form-control input_ticket each_ticket_section_input" id="input_ticket_name2" required="" value="Ladies">
                                                <input name="ticket_tickets[]" placeholder="Number Of Tickets" type="number" min="0" class="form-control input_ticket each_ticket_section_input no_tickets" id="input_ticket_no2" required="" value="100">
                                                <textarea name="ticket_desc[]" placeholder="Description" rows="4" class="form-control input_ticket ticket_note" id="input_ticket_desc2" required="">Each pass permits one lady to the event.</textarea>
                                                <span style="float: right; display: block; color: red; font-size: 10px; margin-top: 5px; cursor: pointer;" class="del_class" data-ticket-id="2">Delete</span>
                                            </div>
                                            <div class="col-sm-3 ticket1 each_ticket_section">
                                                <input name="ticket_name[]" placeholder="Ticket Name" type="text" class="form-control input_ticket each_ticket_section_input" id="input_ticket_name3" required="" value="Couple">
                                                <input name="ticket_tickets[]" placeholder="Number Of Tickets" type="number" min="0" class="form-control input_ticket each_ticket_section_input no_tickets" id="input_ticket_no3" required="" value="100">
                                                <textarea name="ticket_desc[]" placeholder="Description" rows="4" class="form-control input_ticket ticket_note" id="input_ticket_desc3" required="">Each pass permits one couple to the event. Cover charges applicable at the venue post 9:30 PM.</textarea>
                                                <span style="float: right; display: block; color: red; font-size: 10px; margin-top: 5px; cursor: pointer;" class="del_class" data-ticket-id="3">Delete</span>
                                            </div>

                                        </div>
                                        
                                         <p>Note : Event is always accessable through the link (generated after submiting for review) & can be shared instantly after creating event, even if it's still under review.
   <br> By clicking on "Submit for Approval", you agree to our Terms & Conditions.</p>
<button type="submit"name="save" class="btn btn-lg btn-primary" style="
    float: right;
"><span
	class="btn-icon-start text-info"><i class="fa fa-plus color-info"></i>
</span>Submit for Approval</button>
                                    </div>
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
    // Check if the event type is 'paid' (for paid events)
    }
elseif ($event_type == 'ticket') {
   

   if (isset($_POST['save'])) {
          $title = mysqli_real_escape_string($conn, $_POST['event_name']);
        $place = $_POST['venue_location'];
        $start = $_POST['start_event_date'];
        $end = $_POST['end_event_date'];
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        $video = $_POST['video_url'];
        $organiser = $_POST['organizer_name'];
        $special = $_POST['special_entry_details'];
        $term = mysqli_real_escape_string($conn, $_POST['terms']);
        $multiple_dates_id = $_POST['multiple_date_id'];
        $multiple_dates = $_POST['multiple_dates'] ?? 0;
        $url = create_slug($title);

        // Handle image upload
        $actual_link = 'https://' . $_SERVER['HTTP_HOST'];
        $imagePath = "";
        $imageError = "";

        if ($_FILES["event_image"]["error"] == 0) {
            $filename = $_FILES["event_image"]["name"];
            $tempname = $_FILES["event_image"]["tmp_name"];
            $folder = "../images/events/" . $filename;
            $folder1 = "$actual_link/images/events/" . $filename;

            $allowed_extensions = ['jpg', 'jpeg', 'png'];
            $file_extension = pathinfo($filename, PATHINFO_EXTENSION);
            if (!in_array($file_extension, $allowed_extensions)) {
                $imageError = "Invalid file type!";
            } else {
                if (move_uploaded_file($tempname, $folder)) {
                    $imagePath = $folder1;
                } else {
                    $imageError = "Error uploading file!";
                }
            }
        } else {
            $imageError = "Error uploading image.";
        }

        if ($imageError) {
            echo $imageError;
            exit();
        }

        // Get user ID from session
        $user_id = $_SESSION['user_id'];

        // Insert event into database
        $sql_event = "INSERT INTO events (tittle, sub_tittle, start, end, multiple_date_id, multiple_dates, description, video, Organised, special_detail, term, image, status, url, event_status,ticketing_type, user_id)
                      VALUES ('$title', '$place', '$start', '$end', '$multiple_dates_id', '$multiple_dates', '$description', '$video', '$organiser', '$special', '$term', '$imagePath', '4', '$url', '1', 'ticket', $user_id)";

        if ($conn->query($sql_event) === TRUE) {
            $event_id = $conn->insert_id;

     // Insert selected categories into the event_category table
    if (!empty($_POST['categories'])) {
        foreach ($_POST['categories'] as $category_id) {
            // Sanitize the category ID
            $category_id = mysqli_real_escape_string($conn, $category_id);

            // Insert each category into the event_category table
            $sql_category = "INSERT INTO event_category (event_id, cat_id) VALUES ('$event_id', '$category_id')";
            if (!$conn->query($sql_category)) {
                echo "Error inserting category: " . $conn->error;
            }
        }
    }

    // Insert selected artists into the event_artist table
    if (!empty($_POST['artists'])) {
        foreach ($_POST['artists'] as $artist_id) {
            // Sanitize the artist ID
            $artist_id = mysqli_real_escape_string($conn, $artist_id);

            // Insert each artist into the event_artist table
            $sql_artist = "INSERT INTO event_artist (event_id, artist_id) VALUES ('$event_id', '$artist_id')";
            if (!$conn->query($sql_artist)) {
                echo "Error inserting artist: " . $conn->error;
            }
        }
    }

   // Insert ticket details into event_tickets table if tickets are provided
    if (!empty($_POST['ticket_name']) && !empty($_POST['ticket_tickets'])) {
        foreach ($_POST['ticket_name'] as $index => $ticket_name) {
            $ticket_name = mysqli_real_escape_string($conn, $ticket_name);
            $ticket_tickets = $_POST['ticket_tickets'][$index];
            $ticket_desc = mysqli_real_escape_string($conn, $_POST['ticket_desc'][$index]);
            $ticket_cost = mysqli_real_escape_string($conn, $_POST['ticket_cost'][$index]);

            // Insert each ticket for the event
            $sql_ticket = "INSERT INTO event_tickets (event_id, created_by, ticket_name, max_tickets,tickets_left, ticket_cost, ticket_desc)
                           VALUES ('$event_id', '$user_id', '$ticket_name', '$ticket_tickets', '$ticket_tickets', '$ticket_cost', '$ticket_desc')";

            // Output ticket SQL query for debugging
            echo "<script>alert('Ticket SQL Query: " . addslashes($sql_ticket) . "');</script>";

            if (!$conn->query($sql_ticket)) {
                echo "Error: " . $conn->error;
            }
        }
    }

    // Redirect to the event edit page after successful creation
    $extra = "single-event?id=" . $url;
    echo "<script>window.location.href='" . $extra . "'</script>";
    exit();
} else {
    echo "Error: " . $sql_event . "<br>" . $conn->error;
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
  
                                                <form action="" class="form-horizontal" role="form" class="form-validate form-horizontal" method="POST" enctype="multipart/form-data">

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
                                <div id="wizard_Service" class="tab-pane" role="tabpanel">
                                    <div class="row">
                                        <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Event Name<span class="required">*</span></label>
                                                <input type="text" name="event_name" value="<?php echo $event_title ?>" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Venue/Location<span class="required">*</span></label>
                                                <!--<input type="text" name="venue_location" class="form-control" required>-->
                                                <select id="select-places" name="venue_location" class="form-control" required ></select>

                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Start Event Date<span class="required">*</span></label>
                                                <input type="datetime-local" name="start_event_date" id="start" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">End Event Date<span class="required">*</span></label>
                                                <input type="datetime-local" name="end_event_date" id="end" class="form-control" required>
                                            </div>
                                        </div>
                                       <div class="col-lg-6 mb-2">
    <label class="text-label form-label">Select Options<span class="required">*</span></label>
    <select id="multipledates" name="multiple_date_id" class="default-select form-control wide" aria-label="Default select example">
        <option value="0">Once</option>
        <option value="1">Multiple</option>
    </select>
</div>

<div class="col-lg-6 mb-2" id="date-picker-container" style="display: none;">
    <div class="mb-3">
        <label class="text-label form-label">Select Multiple Dates<span class="required">*</span></label>
<input type="text" id="multipledatepicker" type="datetime-local" name="multiple_dates" class="form-control" readonly>    </div>
</div>




    
                
    <!--<div class="form-group">-->
    <!--    <label for="select-places">Select Multiple Places</label>-->
    <!--    <select id="select-places" name="places[]" multiple="multiple" class="form-control">-->
            <!-- Options will be loaded dynamically -->
    <!--    </select>-->
    <!--</div>-->

    
                                    </div>
                                </div>
                                <div id="wizard_Time" class="tab-pane" role="tabpanel">
                                    <div class="row">
                                        <div class="col-lg-12 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Description<span class="required">*</span></label>
                                    <textarea id="descriptions" class="form-control" name="description" ></textarea>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Image<span class="required">*</span></label>
                                                <input class="form-control" type="file" name="event_image" id="formFile">
                                            </div>
                                        </div>
                                                                                <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Video URL</label>
                                                <input type="text" name="video_url" class="form-control" >
                                            </div>
                                        </div>

                                    </div>
                                </div>
                                <div id="wizard_Details" class="tab-pane" role="tabpanel">
                                    <div class="row">
                                        <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Organizer Name<span class="required">*</span></label>
                                                <input type="text" name="organizer_name" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Special Entry Details<span class="required">*</span></label>
                                                <input type="text" name="special_entry_details" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-2">
                                            <label class="text-label form-label">Event Category<span class="required">*</span></label>
                                            
                                             <select id="select-categories" name="categories[]" multiple="multiple" class="form-control">
            <!-- Options will be loaded dynamically -->
        </select>
                                          
                                           
                                        </div>
                                        <div class="col-lg-6 mb-2">
                                            <label class="text-label form-label">Artist/Performer<span class="required">*</span></label>
                                      
        </select>
<select id="select-artists" name="artists[]" multiple="multiple" class="form-control"></select>




                                        </div>
                                        <div class="col-lg-12 mb-2">
                                            <div class="mb-3">
                                                <label class="text-label form-label">Terms & Conditions<span class="required">*</span></label>
                                    <textarea id="terms" class="form-control" name="terms" >
                                        
                                        
                                         <ul>
                     <li>Only 21+ allowed. Bring your ID cards for age verification.</li>
                     <li>For stags cover charges will be applicable as per venue&rsquo;s discretion throughout the night.
                     </li>
                     <li>The entry closes at 9:00 PM. Cover charges will be applicable post that as per venue&rsquo;s
                        discretion.</li>
                     <li>Men must wear closed footwear (Shoes) and full length bottoms. (Applicable for Night Clubs)</li>
                     <li>Tickets once booked cannot be exchanged or refunded.</li>
                     <li>Venues/Organizers are solely responsible for the service; availability and quality of the events.
                     </li>
                     <li>Highspinn does not take any responsibility for the activities going on inside or outside the
                        event. The entire responsibility of it is of the organizer/venue.</li>
                     <li>In certain circumstances, Highspinn reserves the right to cancel the tickets owing to any internal
                        reason which requires such action. In such cases, the customer will be provided full refund for the
                        ticket within 7-10 working days.</li>
                     <li>Venue/Organisers rules apply.</li>
                  </ul>
                                    </textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
            <!-- Step 4: Ticket Details -->
            <div id="wizard_Payment" class="tab-pane">
                <div class="container">
                    <!-- Ticket Sections -->
                                                          <div class="row" id="ticket_view_div" class="d-flex flex-wrap">
                                        <input type="hidden" id="ticket_count" value="1">
                                            <div class="col-sm-3 ticket1 each_ticket_section">
                                                <input name="ticket_name[]" placeholder="Ticket Name" type="text" class="form-control input_ticket each_ticket_section_input" id="input_ticket_name1" required="">
                                                <input name="ticket_tickets[]" placeholder="Number Of Tickets" type="number" min="0" class="form-control input_ticket each_ticket_section_input no_tickets" id="input_ticket_no1" required="">
                                                <input name="ticket_cost[]" placeholder="Ticket pricing" type="number" min="0" class="form-control input_ticket each_ticket_section_input no_tickets" id="input_ticket_cost1" required="">
                                                <textarea name="ticket_desc[]" placeholder="Description" rows="4" class="form-control input_ticket ticket_note" id="input_ticket_desc1" required=""></textarea>
                                                <span style="float: right; display: block; color: red; font-size: 10px; margin-top: 5px; cursor: pointer;" class="del_class" data-ticket-id="1">Delete</span>
                                            </div>
                                        </div>

                    <p>Note: Event is accessible through the generated link after submission for review. You can share it instantly, even if it's still under review.</p>
                    
                    <!-- Submit Button -->
                    <button type="submit" name="save" class="btn btn-lg btn-primary" style="float: right;">
                        <span class="btn-icon-start text-info"><i class="fa fa-plus color-info"></i></span>Publish Events
                    </button>
                </div>
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