<?php
ob_start();  // Start output buffering
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit(); 
}
if ($_SESSION['user_type']== 0 || $_SESSION['user_type']== 1 ) {
    header("Location: /profile");
    exit(); 
}
?>

<?php
$pageTitle = "Event Approval"; // Set the page title
?>

<?php include '../include/config.php'?>
<?php include '../include/header.php';?>
<?php include '../include/sidebar.php'?>
		
	
		
		<!--**********************************
            Content body start
        ***********************************-->
         <div class="content-body">
            <div class="container-fluid mh-auto">
				
        <?php
// Database connection (assume $conn is already set up)

// Variables for pagination, search, and sorting
$itemsPerPage = 6;
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
$sortOrder = isset($_GET['sort']) ? $_GET['sort'] : 'newest'; // default to 'newest'

// Sanitize search term to avoid SQL injection
$searchTerm = $conn->real_escape_string($searchTerm);

// Query for total count with search filter
$searchSQL = $searchTerm ? "WHERE events.tittle LIKE '%$searchTerm%' AND events.status = 4" : "WHERE events.status = 4";
$query = $conn->query("SELECT COUNT(*) as total FROM events INNER JOIN place ON events.sub_tittle = place.id $searchSQL");

$row = $query->fetch_assoc();
$totalPages = ceil($row['total'] / $itemsPerPage);

// Adjust current page logic to avoid invalid page numbers
if (isset($_GET['page']) && is_numeric($_GET['page'])) {
    $currentPage = min(max(1, $_GET['page']), $totalPages);
} else {
    $currentPage = 1;
}

$offset = ($currentPage - 1) * $itemsPerPage;

// Sorting logic
$orderSQL = ($sortOrder == 'oldest') ? 'ASC' : 'DESC';

// Query with search, sorting, and pagination
$query = $conn->query("SELECT events.*, place.name as pname, place.id
            FROM events 
            INNER JOIN place ON events.sub_tittle = place.id 
            $searchSQL ORDER BY events.id $orderSQL LIMIT $offset, $itemsPerPage");

// Handle query results (loop through results, etc.)
?>

<!-- Search and Sort Form -->

<!-- Display Event Cards -->
<div class="row">
    
      <!-- Search and Sort Form -->
<div class="page-title flex-wrap">
    <form method="GET" class="input-group search-area mb-md-0 mb-3">
        <input type="text" name="search" class="form-control" placeholder="Search here..." value="<?php echo htmlspecialchars($searchTerm); ?>">
        <button type="submit" class="input-group-text">
            <svg width="15" height="15" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
<svg width="15" height="15" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M17.5605 15.4395L13.7527 11.6317C14.5395 10.446 15 9.02625 15 7.5C15 3.3645 11.6355 0 7.5 0C3.3645 0 0 3.3645 0 7.5C0 11.6355 3.3645 15 7.5 15C9.02625 15 10.446 14.5395 11.6317 13.7527L15.4395 17.5605C16.0245 18.1462 16.9755 18.1462 17.5605 17.5605C18.1462 16.9747 18.1462 16.0252 17.5605 15.4395V15.4395ZM2.25 7.5C2.25 4.605 4.605 2.25 7.5 2.25C10.395 2.25 12.75 4.605 12.75 7.5C12.75 10.395 10.395 12.75 7.5 12.75C4.605 12.75 2.25 10.395 2.25 7.5V7.5Z" fill="#01A3FF"/>
											</svg>            </svg>
        </button>
    </form> 
    <div style="display: flex;">
        <form method="GET">
            <select name="sort" class="default-select me-3" aria-label="Sort"  onchange="this.form.submit()">
                <option value="newest" <?php if ($sortOrder == 'newest') echo 'selected'; ?>>Newest</option>
                <option value="oldest" <?php if ($sortOrder == 'oldest') echo 'selected'; ?>>Oldest</option>
            </select>
        </form>
    <!--</div>-->
    <!--<div>-->
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#exampleModal">
            + Create Event
        </button>
    </div>
</div>
<?php
// Assuming $query is already set up
$api_token = '567ca9d270fb1f1f669d039597b1296be498f4c5';
$actual_link = "https://highspinn.com/events/";

if (isset($_POST['approve_event'])) {
    $eventId = $_POST['event_id'];
    $newStatus = 1; // Status for "Approved"

    // Update the status to "Approved" in the database
    $updateQuery = "UPDATE events SET status = $newStatus WHERE url = '$eventId'";
    $updateResult = mysqli_query($conn, $updateQuery);

    if ($updateResult) {
        // Redirect to avoid resubmission on refresh
        header("Location: " . $_SERVER['PHP_SELF'] . "?id=$eventId");
        exit();
    } else {
        echo "Error updating event status: " . mysqli_error($conn);
    }
}

if (isset($_POST['unapprove_event'])) {
    $eventId = $_POST['event_id'];
    $newStatus = 3; // Status for "Unapproved" or "Draft"

    // Update the status to "Unapproved" in the database
    $updateQuery = "UPDATE events SET status = $newStatus WHERE url = '$eventId'";
    $updateResult = mysqli_query($conn, $updateQuery);

    if ($updateResult) {
        // Redirect to avoid resubmission on refresh
        header("Location: " . $_SERVER['PHP_SELF'] . "?id=$eventId");
        exit();
    } else {
        echo "Error updating event status: " . mysqli_error($conn);
    }
}





if ($query->num_rows > 0) {
    while ($row = $query->fetch_assoc()) {
        // Extract data from the current row
        $imageURL = $row["image"];
        $tittle = $row["tittle"];
        $id = $row["url"];
        $month = $row["start"];
        $sub_tittle = $row["sub_tittle"];
        $pname = $row["pname"];
        
        $count = $row["count"];
        $status = $row["status"];
        // Format date
        $months = date_create($month);
        $monthss = date_format($months, "M");
        $date = date_format($months, "d");

        
        // Construct the event URL and call the API
        $long_url = $actual_link . $id;
        
        // $api_url = "https://www.adswonder.com/api?api={$api_token}&url={$long_url}&type=0";
        // $api_result = @json_decode(file_get_contents($api_url), TRUE);  // Make sure to handle errors
        // $shortened_url = $api_result["status"] === 'error' ? $api_result["message"] : $api_result["shortenedUrl"];




        ?>
        <div class="col-lg-12 col-xl-6 col-xxl-4">
            <div class="card">
                <div class="card-body">
                    <div class="row m-b-30">
                        <div class="col-md-5 col-xxl-12">
                            <div class="new-arrival-product mb-4 mb-xxl-4 mb-md-0">
                                <div class="new-arrivals-img-contnent"><a href="single-event?id=<?php echo $id; ?>">
                                    <img class="img-fluid" src="<?php echo $imageURL ?>" alt=""></a>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-7 col-xxl-12">
                            <div class="new-arrival-content position-relative">
                                <div class="contact-icon" style="margin-bottom: 5px;">
                                    <?php
                                    // Display event status
                                    if ($status == 1) {
                                        echo '<span class="badge badge-success light">Published</span>';
                                    } elseif ($status == 2) {
                                        echo ' <span class="badge badge-secondary light mx-2">Draft</span> ';
                                    }
                                    
                                     elseif($row['status'] == 3){echo'  <span class="badge badge-warning light">Unapproved</span>'; }
  
   elseif($row['status'] == 4){echo'  <span class="badge badge-warning light">Submit for Approval</span>'; }
                                    
                                    else {
                                        echo ' <span class="badge badge-danger light">Expired</span>';
                                    }
                                    ?> 
                                </div>
                                <h4><a href="single-event?id=<?php echo $id; ?>"><?php echo $tittle; ?></a></h4>
                                <p><span> <i class="fa fa-location-arrow"></i> <span style="padding-top: 4px; margin-right: 50px;"> <?php echo $pname; ?> </span></span></p>
                                <p><span> <i class="fa fa-calendar"></i><span style="padding-top: 4px;"> <?php echo $month;  ?></span></span></p>

                                <!--<div class="d-flex align-items-center">-->
                                <!--    <a href="app-profile.html" class="btn btn-primary btn-sm w-50 me-2"><i class="fa fa-caret-right"></i> Unapprove</a>-->
                                    	<div class="d-flex align-items-center">
                                <form style="
    margin: 0px 10px;
" method="POST">
    <input type="hidden" name="event_id" value="<?php echo $id; ?>">
    
    <!-- Approve button -->
    <button type="submit" name="approve_event" class="btn btn-success btn-sm w-100">
        <i class="fa fa-check color-danger"></i> Approve
    </button>
</form>

<form style="
    margin: 0px 10px;
" method="POST">
    <input type="hidden" name="event_id" value="<?php echo $id; ?>">
    
    <!-- Unapprove button -->
    <button type="submit" name="unapprove_event" class="btn btn-danger btn-sm w-100">
        <i class="fa fa-minus color-success"></i> Unapprove
    </button>
</form>
</div>

                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php
    }
} else {
    echo '<p>No Event found...</p>';
}
?>        </div>


</div>

<!-- Pagination Logic -->
<div class="table-pagination teach">
    <small>Showing <span><?php echo (($currentPage - 1) * $itemsPerPage + 1); ?>-<?php echo min($currentPage * $itemsPerPage, $row['total']); ?></span> from <span><?php echo $row['total']; ?></span> data</small>
    <nav>
        <ul class="pagination pagination-gutter pagination-primary no-bg">
            <li class="page-item page-indicator <?php if ($currentPage <= 1) echo 'disabled'; ?>">
                <a class="page-link" href="<?php if ($currentPage > 1) echo '?page=' . ($currentPage - 1) . '&search=' . urlencode($searchTerm) . '&sort=' . urlencode($sortOrder); else echo 'javascript:void(0)'; ?>">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
            </li>
            <?php
            // Calculate the start and end page numbers to display
            $startPage = max(1, $currentPage - 2);
            $endPage = min($totalPages, $currentPage + 2);
            for ($i = $startPage; $i <= $endPage; $i++) {
            ?>
                <li class="page-item <?php if ($currentPage == $i) echo 'active'; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($searchTerm); ?>&sort=<?php echo urlencode($sortOrder); ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
            <?php } ?>
            <li class="page-item page-indicator <?php if ($currentPage >= $totalPages) echo 'disabled'; ?>">
                <a class="page-link" href="<?php if ($currentPage < $totalPages) echo '?page=' . ($currentPage + 1) . '&search=' . urlencode($searchTerm) . '&sort=' . urlencode($sortOrder); else echo 'javascript:void(0)'; ?>">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
            </li>
        </ul>
    </nav>
</div>


								
            </div>
        </div>
        <!--**********************************
            Content body end
        ***********************************-->
		
		
       

		<!--**********************************
           Support ticket button start
        ***********************************-->

        <!--**********************************
           Support ticket button endw
        ***********************************-->
		<?php include '../include/footer.php'; ?>