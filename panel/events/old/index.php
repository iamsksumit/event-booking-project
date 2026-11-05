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

$pageTitle = "Event List"; // Set the page title

include '../include/config.php';
include '../include/header.php';
include '../include/sidebar.php';
?>

<!--**********************************
    Content body start
***********************************-->
<div class="content-body">
    <div class="container-fluid mh-auto">
<?php
// ====== Configuration / Inputs ======
$itemsPerPage = 6;
$searchTermRaw = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortOrderRaw  = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

// sanitize for SQL (minimal fix approach)
$searchTerm = $conn->real_escape_string($searchTermRaw);
$sortOrder = ($sortOrderRaw === 'oldest') ? 'oldest' : 'newest';
$orderSQL  = ($sortOrder === 'oldest') ? 'ASC' : 'DESC';

// API config (kept from your code)
$api_token = '567ca9d270fb1f1f669d039597b1296be498f4c5';
$actual_link = "https://highspinn.com/events/";

// Prepare variables for pagination & total
$total = 0;
$totalPages = 1;
$currentPage = 1;
$offset = 0;

// We'll build the appropriate queries depending on user_type
try {
    if ($_SESSION['user_type'] == 1) {
        // show events only for this user
        $userId = (int)$_SESSION['user_id'];
        $searchSQL = ($searchTerm !== '') ? "AND events.tittle LIKE '%{$searchTerm}%'" : '';

        // COUNT query
        $countSql = "
            SELECT COUNT(*) AS total
            FROM events
            INNER JOIN users ON events.user_id = users.id
            WHERE events.user_id = {$userId}
              AND events.status != 0
              {$searchSQL}
        ";
        $countResult = $conn->query($countSql);
        if (!$countResult) {
            throw new Exception("Count Query Error: " . $conn->error);
        }
        $totalRow = $countResult->fetch_assoc();
        $total = (int)$totalRow['total'];

        // pagination math
        $totalPages = ($total > 0) ? ceil($total / $itemsPerPage) : 1;
        $currentPage = (isset($_GET['page']) && is_numeric($_GET['page'])) ? (int)$_GET['page'] : 1;
        $currentPage = max(1, min($currentPage, $totalPages));
        $offset = ($currentPage - 1) * $itemsPerPage;

        // Events query
        $eventsSql = "
            SELECT events.*, events.id AS event_id, place.name AS pname, place.id AS place_id,
                   users.fname, users.lname, users.gender, users.email, users.contactno, users.image_url
            FROM events
            INNER JOIN users ON events.user_id = users.id
            LEFT JOIN place ON events.sub_tittle = place.id
            WHERE events.user_id = {$userId}
              AND events.status != 0
              {$searchSQL}
            ORDER BY events.id {$orderSQL}
            LIMIT {$offset}, {$itemsPerPage}
        ";
        $eventsResult = $conn->query($eventsSql);
        if (!$eventsResult) {
            throw new Exception("Events Query Error: " . $conn->error);
        }

    } elseif ($_SESSION['user_type'] == 2) {
        // show all events (for admin/manager)
        $searchSQL = ($searchTerm !== '') ? "AND events.tittle LIKE '%{$searchTerm}%'" : '';

        // COUNT query
        $countSql = "
            SELECT COUNT(*) AS total
            FROM events
            LEFT JOIN place ON events.sub_tittle = place.id
            WHERE events.status != 0
              {$searchSQL}
        ";
        $countResult = $conn->query($countSql);
        if (!$countResult) {
            throw new Exception("Count Query Error: " . $conn->error);
        }
        $totalRow = $countResult->fetch_assoc();
        $total = (int)$totalRow['total'];

        // pagination math
        $totalPages = ($total > 0) ? ceil($total / $itemsPerPage) : 1;
        $currentPage = (isset($_GET['page']) && is_numeric($_GET['page'])) ? (int)$_GET['page'] : 1;
        $currentPage = max(1, min($currentPage, $totalPages));
        $offset = ($currentPage - 1) * $itemsPerPage;

        // Events query
        $eventsSql = "
            SELECT events.*, events.id AS event_id, place.name AS pname, place.id AS place_id
            FROM events
            LEFT JOIN place ON events.sub_tittle = place.id
            WHERE events.status != 0
              {$searchSQL}
            ORDER BY events.id {$orderSQL}
            LIMIT {$offset}, {$itemsPerPage}
        ";
        $eventsResult = $conn->query($eventsSql);
        if (!$eventsResult) {
            throw new Exception("Events Query Error: " . $conn->error);
        }

    } else {
        throw new Exception("Invalid user type");
    }
} catch (Exception $e) {
    echo '<p class="text-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    // ensure $eventsResult is defined to avoid warnings below
    $eventsResult = false;
}
?>

        <!-- Search and Sort Form -->
        <div class="page-title flex-wrap mb-3">
            <form method="GET" class="input-group search-area mb-md-0 mb-3" style="max-width:420px;">
                <input type="text" name="search" class="form-control" placeholder="Search here..." value="<?php echo htmlspecialchars($searchTermRaw); ?>">
                <button type="submit" class="input-group-text">
                    <i class="fa fa-search"></i>
                </button>
            </form>

            <div style="display: flex; gap:10px; align-items:center;">
                <!-- Sort form keeps the search term as hidden so both work together -->
                <form method="GET" class="me-2">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchTermRaw); ?>">
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
            if ($eventsResult && $eventsResult->num_rows > 0) {
                while ($row = $eventsResult->fetch_assoc()) {
                    // Use safe variable names
                    $imageURL = $row["image"] ?? '';
                    $tittle = $row["tittle"] ?? '';
                    $urlSlug = $row["url"] ?? ''; // for building event link
                    $event_id = (isset($row["event_id"])) ? (int)$row["event_id"] : 0;
                    $startDateRaw = $row["start"] ?? '';
                    $sub_tittle = $row["sub_tittle"] ?? '';
                    $count = $row["count"] ?? 0;
                    $status = isset($row["status"]) ? (int)$row["status"] : 0;
                    $placename = $row["pname"] ?? '';

                    // Format date (keep original $row['start'] display too)
                    $months = date_create($startDateRaw);
                    $monthss = $months ? date_format($months, "M") : '';
                    $date = $months ? date_format($months, "d") : '';

                    // Construct the event URL and call the API (kept as in original)
                    $long_url = $actual_link . $urlSlug;
                    $api_url = "https://www.adswonder.com/HSPlNN/api?api={$api_token}&url=" . rawurlencode($long_url) . "&type=0";
                    $api_result = @json_decode(@file_get_contents($api_url), TRUE);
                    $shortened_url = ($api_result && isset($api_result["shortenedUrl"]) && $api_result["shortenedUrl"] != '') ? $api_result["shortenedUrl"] : '';

                    // Get total views for this event (safe numeric id)
                    $totalViews = 0;
                    if ($event_id > 0) {
                        $viewsSql = "SELECT SUM(views) AS total_views FROM views WHERE event_id = '{$event_id}'";
                        $viewsRes = $conn->query($viewsSql);
                        if ($viewsRes) {
                            $viewsRow = $viewsRes->fetch_assoc();
                            $totalViews = (int)($viewsRow['total_views'] ?? 0);
                        }
                    }

                    // Display event card (escape output where used in HTML)
                    ?>
                    <div class="col-lg-12 col-xl-6 col-xxl-4">
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-30">
                                    <div class="col-md-5 col-xxl-12">
                                        <div class="new-arrival-product mb-4">
                                            <div class="new-arrivals-img-contnent">
                                                <a href="single-event?id=<?php echo htmlspecialchars($urlSlug); ?>">
                                                    <img class="img-fluid" src="<?php echo htmlspecialchars($imageURL); ?>" alt="<?php echo htmlspecialchars($tittle); ?>">
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
                                            <h4><a href="single-event?id=<?php echo htmlspecialchars($urlSlug); ?>"><?php echo htmlspecialchars($tittle); ?></a></h4>
                                            <p><i class="fa fa-location-arrow"></i> <span class="ms-2"><?php echo htmlspecialchars($placename); ?></span></p>
                                            <p><i class="fa fa-calendar"></i> <span class="ms-2"><?php echo htmlspecialchars($startDateRaw); ?></span></p>
                                            <p><i class="fa fa-eye"></i> <span class="ms-2"><?php echo $totalViews; ?> Views</span></p>

                                            <?php if ($shortened_url): ?>
                                                <p><i class="fa fa-globe"></i> <span class="ms-2"><a href="<?php echo htmlspecialchars($shortened_url); ?>" target="_blank" rel="noopener noreferrer">Shortlink</a></span></p>
                                            <?php endif; ?>

                                            <div class="d-flex align-items-center mt-3">
                                                <a href="duplicate-event?id=<?php echo htmlspecialchars($urlSlug); ?>" class="btn btn-primary btn-sm w-50 me-2"><i class="fa fa-caret-right"></i> Duplicate</a>
                                                <a href="single-event?id=<?php echo htmlspecialchars($urlSlug); ?>" class="btn btn-light btn-sm w-50"><i class="fa fa-info-circle"></i> Details</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php
                } // end while
            } else {
                echo '<p class="text-center">No events found.</p>';
            }
            ?>
        </div>

        <!-- Pagination Logic -->
        <div class="table-pagination teach mt-3">
            <?php
            // Calculate visible range
            $startItem = ($total > 0) ? (($currentPage - 1) * $itemsPerPage + 1) : 0;
            $endItem = ($total > 0) ? min($currentPage * $itemsPerPage, $total) : 0;
            ?>
            <small>Showing <span><?php echo $startItem . '-' . $endItem; ?></span> from <span><?php echo $total; ?></span> data</small>

            <nav>
                <ul class="pagination pagination-gutter pagination-primary no-bg">
                    <!-- Previous Page Indicator -->
                    <li class="page-item page-indicator <?php if ($currentPage <= 1) echo 'disabled'; ?>">
                        <a class="page-link" href="<?php
                            if ($currentPage > 1) {
                                echo '?page=' . ($currentPage - 1) . '&search=' . urlencode($searchTermRaw) . '&sort=' . urlencode($sortOrder);
                            } else {
                                echo 'javascript:void(0)';
                            }
                        ?>">
                            <i class="fa-solid fa-chevron-left"></i>
                        </a>
                    </li>

                    <?php
                    // Determine pages to show
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);

                    for ($i = $startPage; $i <= $endPage; $i++) {
                        ?>
                        <li class="page-item <?php if ($currentPage == $i) echo 'active'; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($searchTermRaw); ?>&sort=<?php echo urlencode($sortOrder); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php } ?>

                    <!-- Next Page Indicator -->
                    <li class="page-item page-indicator <?php if ($currentPage >= $totalPages) echo 'disabled'; ?>">
                        <a class="page-link" href="<?php
                            if ($currentPage < $totalPages) {
                                echo '?page=' . ($currentPage + 1) . '&search=' . urlencode($searchTermRaw) . '&sort=' . urlencode($sortOrder);
                            } else {
                                echo 'javascript:void(0)';
                            }
                        ?>">
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

<?php include '../include/footer.php'; ?>
