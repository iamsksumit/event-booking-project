<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit(); 
}

if ($_SESSION['user_type'] == 0) {
    header("Location: /profile");
    exit(); 
}
?>

<?php
$pageTitle = "Past Event"; // Set the page title
?>

<?php include '../include/config.php'; ?>
<?php include '../include/header.php'; ?>
<?php include '../include/sidebar.php'; ?>

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

try {
    
    // Assuming $query is already set up
    $api_token = '567ca9d270fb1f1f669d039597b1296be498f4c5';
    $actual_link = "https://highspinn.com/events/";

    if ($_SESSION['user_type'] == 1) {
        // Query for total count with search filter and user type condition
        $userId = $_SESSION['user_id'];
        $searchSQL = $searchTerm ? "AND events.tittle LIKE '%$searchTerm%'" : '';
        
        // Total count query (ensure the status condition is applied)
        $query = $conn->query("
            SELECT COUNT(*) as total 
            FROM events 
            INNER JOIN users ON events.user_id = users.id 
            WHERE events.user_id = '$userId' AND events.status = 0 AND users.user_type = 1 $searchSQL
        ");
        
        if (!$query) {
            throw new Exception("Error fetching total count: " . $conn->error);
        }
        
        $row = $query->fetch_assoc();
        $totalPages = ceil($row['total'] / $itemsPerPage);

        // Adjust current page logic to avoid invalid page numbers
        $currentPage = isset($_GET['page']) && is_numeric($_GET['page']) ? min(max(1, $_GET['page']), $totalPages) : 1;
        $offset = ($currentPage - 1) * $itemsPerPage;

        // Sorting logic  
        $orderSQL = ($sortOrder == 'oldest') ? 'ASC' : 'DESC';

        // Query with search, sorting, and pagination (ensure the status condition is applied)
        $query = $conn->query("
            SELECT events.*, events.id as id ,place.name as pname,place.id, users.fname, users.lname, users.gender, users.email, users.contactno, users.image_url 
            FROM events 
            INNER JOIN users ON events.user_id = users.id 
            INNER JOIN place ON events.sub_tittle = place.id 
            WHERE events.user_id = '$userId' AND users.user_type = 1 AND events.status = 0
            $searchSQL 
            ORDER BY events.id $orderSQL 
            LIMIT $offset, $itemsPerPage
        ");
        
        if (!$query) {
            throw new Exception("Error fetching events: " . $conn->error);
        }

    } elseif ($_SESSION['user_type'] == 2) {
        // Query for total count with search filter for user_id 2
        $searchSQL = $searchTerm ? "WHERE events.tittle LIKE '%$searchTerm%'" : '';
        
        // Total count query for user_type 2 (ensure the status condition is applied)
        $query = $conn->query("SELECT COUNT(*) as total FROM events
            INNER JOIN place ON events.sub_tittle = place.id 
            WHERE events.status = 0 $searchSQL");
        
        if (!$query) {
            throw new Exception("Error fetching total count: " . $conn->error);
        }
        
        $row = $query->fetch_assoc();
        $totalPages = ceil($row['total'] / $itemsPerPage);

        // Adjust current page logic to avoid invalid page numbers
        $currentPage = isset($_GET['page']) && is_numeric($_GET['page']) ? min(max(1, $_GET['page']), $totalPages) : 1;
        $offset = ($currentPage - 1) * $itemsPerPage;

        // Sorting logic  
        $orderSQL = ($sortOrder == 'oldest') ? 'ASC' : 'DESC';

        // Query with search, sorting, and pagination (ensure the status condition is applied)
        $query = $conn->query(" 
            SELECT events.*,events.id as id ,place.name as pname
            FROM events 
            INNER JOIN place ON events.sub_tittle = place.id 
            WHERE events.status = 0 
            $searchSQL 
            ORDER BY id $orderSQL 
            LIMIT $offset, $itemsPerPage");
        
        if (!$query) {
            throw new Exception("Error fetching events: " . $conn->error);
        }
    } else {
        throw new Exception("Invalid user type");
    }
} catch (Exception $e) {
    echo '<p class="text-danger">Error: ' . $e->getMessage() . '</p>';
}
?>

<!-- Search and Sort Form -->
<div class="page-title flex-wrap">
    <form method="GET" class="input-group search-area mb-md-0 mb-3">
        <input type="text" name="search" class="form-control" placeholder="Search here..." value="<?php echo htmlspecialchars($searchTerm); ?>">
        <button type="submit" class="input-group-text">
            <i class="fa fa-search"></i>
        </button>
    </form>
    <div style="display: flex;">
        <form method="GET">
            <select name="sort" class="default-select me-3" aria-label="Sort" onchange="this.form.submit()">
                <option value="newest" <?php if ($sortOrder == 'newest') echo 'selected'; ?>>Newest</option>
                <option value="oldest" <?php if ($sortOrder == 'oldest') echo 'selected'; ?>>Oldest</option>
            </select>
        </form>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#exampleModal">
            + Create Event
        </button>
    </div>
</div>

<!-- Display Event Cards -->
<div class="row">
    <?php
    if ($query && $query->num_rows > 0) {
        while ($row = $query->fetch_assoc()) {
            $imageURL = $row["image"];
            $tittle = $row["tittle"];
            $id = $row["url"];
                        $event_id = $row["id"];

            $month = $row["start"];
            $sub_tittle = $row["sub_tittle"];
            $count = $row["count"];
            $status = $row["status"];
            $placename = $row["pname"];

            // Format date  
            $months = date_create($month);
            $monthss = date_format($months, "M");
            $date = date_format($months, "d");

            // Construct the event URL and call the API
            $long_url = $actual_link . $id;
            $api_url = "https://www.adswonder.com/HSPlNN/api?api={$api_token}&url={$long_url}&type=0";
            $api_result = @json_decode(file_get_contents($api_url), TRUE);  // Handle API errors safely
            $shortened_url = ($api_result && $api_result["status"] !== 'error') ? $api_result["shortenedUrl"] : 'Error fetching shortlink';

  $results = $conn->query("SELECT SUM(views) AS total_views FROM views WHERE event_id = '$event_id'");
            $totalViews = 0; // Default value for totalViews
            if ($results) {
                $rows = $results->fetch_assoc();
                $totalViews = ($rows['total_views']) ? $rows['total_views'] : 0;  // Assign total views if not null or 0
            } else {
                echo "Error: " . $conn->error;
            }
            // Display event card
            ?>
                    <div class="col-lg-12 col-xl-6 col-xxl-4">
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-30">
                                    <div class="col-md-5 col-xxl-12">
                                        <div class="new-arrival-product mb-4">
                                            <div class="new-arrivals-img-contnent">
                                                <a href="single-event?id=<?php echo $id; ?>">
                                                    <img class="img-fluid" src="<?php echo $imageURL ?>" alt="">
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-7 col-xxl-12">
                                        <div class="new-arrival-content position-relative">
                                            <div class="contact-icon mb-2">
                                                <?php
                                                // Display event status
                                                switch ($status) {
                                                    case 1:
                                                        echo '<span class="badge badge-success light">Published</span>';
                                                        break;
                                                    case 2:
                                                        echo '<span class="badge badge-secondary light mx-2">Draft</span>';
                                                        break;
                                                    case 3:
                                                        echo '<span class="badge badge-secondary light mx-2">Unapproved</span>';
                                                        break;
                                                    case 4:
                                                        echo '<span class="badge badge-warning light">Submit for Approval</span>';
                                                        break;
                                                    default:
                                                        echo '<span class="badge badge-danger light">Expired</span>';
                                                        break;
                                                }
                                                ?>
                                            </div>
                                            <h4><a href="single-event?id=<?php echo $id; ?>"><?php echo $tittle; ?></a></h4>
                                            <p><i class="fa fa-location-arrow"></i> <span class="ms-2"><?php echo $placename; ?></span></p>
                                            <p><i class="fa fa-calendar"></i> <span class="ms-2"><?php echo $month; ?></span></p>
                                            <p><i class="fa fa-eye"></i> <span class="ms-2"><?php echo $totalViews; ?> Views</span></p>
                                            
                                            
                                            <!--<p><i class="fa fa-globe"></i> <span class="ms-2"><a href="<?php echo $shortened_url; ?>">Copy Shortlink</a></span></p>-->

                                            <div class="d-flex align-items-center mt-3">
                                                <a href="duplicate-event?id=<?php echo $id; ?>" class="btn btn-primary btn-sm w-50 me-2"><i class="fa fa-caret-right"></i> Duplicate</a>
                                                <a href="single-event?id=<?php echo $id; ?>" class="btn btn-light btn-sm w-50"><i class="fa fa-info-circle"></i> Details</a>
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
                echo '<p class="text-center">No events found.</p>';
            }
            ?>
        </div>

      <!-- Pagination Logic -->
<div class="table-pagination teach">
    <small>Showing <span>
        <?php echo (($currentPage - 1) * $itemsPerPage + 1); ?>-<?php echo min($currentPage * $itemsPerPage, $row['total']); ?>
    </span> from <span><?php echo $row['total']; ?></span> data</small>
    <nav>
        <ul class="pagination pagination-gutter pagination-primary no-bg">
            <!-- Previous Page Indicator -->
            <li class="page-item page-indicator <?php if ($currentPage <= 1) echo 'disabled'; ?>">
                <a class="page-link" href="<?php if ($currentPage > 1) echo '?page=' . ($currentPage - 1) . '&search=' . urlencode($searchTerm) . '&sort=' . urlencode($sortOrder); else echo 'javascript:void(0)'; ?>">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
            </li>

            <?php
            // Calculate the start and end page numbers for display
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

            <!-- Next Page Indicator -->
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