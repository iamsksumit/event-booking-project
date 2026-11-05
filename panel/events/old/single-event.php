<?php
ob_start();  // Start output buffering
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit(); 
}

if ($_SESSION['user_type']== 0 ) {
    header("Location: /profile");
    exit(); 
}

?>

<?php
$pageTitle = "Event Details"; // Set the page title
?>

<?php include '../include/config.php'?>
<?php include '../include/header.php';?>
<?php include '../include/sidebar.php'?>
<?php 

$id=$_REQUEST['id'];

$query = "SELECT 
    events.id as eventidd,
    events.url,
    events.tittle,
    events.banner_status,
    events.sub_tittle,
    events.status,
    events.ticketing_type,
    events.views_count,
    events.description,
    events.term,
    events.video,
    events.Organised,
    events.special_detail,
    events.image,
    events.start,
    events.end,
    place.address AS placeadd,
    place.name AS pname,
    place.url AS urls,
    place.image_url AS placeimage,
    artist.name AS artistname,
    event_artist.id,
    event_artist.artist_id,
    artist.image_url AS artistimage,
    artist.url AS artisturl
FROM 
    events
LEFT JOIN 
    place ON events.sub_tittle = place.id
LEFT JOIN 
    event_artist ON event_artist.event_id = events.id
LEFT JOIN 
    artist ON event_artist.artist_id = artist.id
WHERE 
    events.url = '$id' 
LIMIT 1";



$result = mysqli_query($conn, $query) or die ( mysqli_error());
$row = mysqli_fetch_assoc($result);
   $month = $row["start"];
     $bannerstatus =  $row["banner_status"];
     
     
    $months = date_create($month);
     $monthss = date_format($months,"M");
     $date = date_format($months,"d");
          $day = date_format($months,"l");
          $time = date_format($months,"g:i a");

 $monthend = $row["end"]; 
     

           $urlsss = $row["url"];
                      $event_id = $row["eventidd"];

           $ticketing_type = $row["ticketing_type"];

    $monthsend = date_create($monthend);
     $monthssend = date_format($monthsend,"M");
     $dateend = date_format($monthsend,"d");
          $dayend = date_format($monthsend,"l");
          $timeend = date_format($monthsend,"g:i a");
                    $timesend = date_format($monthsend,"h:i:s");
     $yearend = date_format($monthsend,"Y");


  $actual_link = "https://highspinn.com/events/" ;

     
// Build the long URL dynamically
$long_url = $actual_link . $urlsss;

// Call your own HSPLNN shortener API
$ch = curl_init("https://highspinn.com/hsplnn/shorten_api.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["long_url" => $long_url]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);

$response = curl_exec($ch);
curl_close($ch);

// Decode response
$data = json_decode($response, true);

// If success, use short_url
if (isset($data['short_url'])) {
    $shortenedUrl = $data['short_url'];
} else {
    $shortenedUrl = $long_url; // fallback if API fails
}


      $results = $conn->query("SELECT SUM(views) AS total_views FROM views WHERE event_id = '$event_id'");
            $totalViews = 0; // Default value for totalViews
            if ($results) {
                $rows = $results->fetch_assoc();
                $totalViews = ($rows['total_views']) ? $rows['total_views'] : 0;  // Assign total views if not null or 0
            } else {
                echo "Error: " . $conn->error;
            } 
     
     
// Handle the banner status toggle
if (isset($_POST['toggle_banner'])) {
    // Toggle the banner status
    $newStatus = ($bannerstatus == 1) ? 0 : 1;

    // Update the banner status in the database
    $updateQuery = "UPDATE events SET banner_status = $newStatus WHERE url = '$id'";
    $result = mysqli_query($conn, $updateQuery);

    if ($result) {
        // Redirect to the same page to avoid resubmission on refresh
        header("Location: " . $_SERVER['PHP_SELF'] . "?id=$id");
        exit();
    } else {
        echo "Error updating banner status: " . mysqli_error($conn);
    }
}

if (isset($_GET['toggle'])) {
    $event_id = intval($_GET['toggle']);
    $conn->query("UPDATE events SET status = IF(status=1, 0, 1) WHERE id = $event_id");
    header("Location: ".$_SERVER['PHP_SELF']."?id=".$_GET['id']);
    exit;
}

// Handle delete with confirmation
if (isset($_GET['delete'])) {
    $event_id = intval($_GET['delete']);
    $conn->query("DELETE FROM events WHERE id = $event_id");
    echo "<script>alert('Event deleted successfully'); window.location='all-events.php';</script>";
    exit;
}
    
?>
		
		<!--**********************************
            Content body start
        ***********************************-->
            <div class="content-body">
            <div class="container-fluid mh-auto">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-xl-3 col-lg-6  col-md-6 col-xxl-5 ">
                                        <!-- Tab panes -->
                                        <div class="tab-content" id="myTabContent">
										  <div class="tab-pane fade show active" id="home-tab-pane" role="tabpanel" aria-labelledby="home-tab" tabindex="0">
												 <img class="img-fluid rounded  " src="<?php echo $row['image']; ?>" alt="">
										  </div>
										
										  
										</div>
									  
                                    </div>
                                    <!--Tab slider End-->
                                    <div class="col-xl-9 col-lg-6  col-md-6 col-xxl-7 col-sm-12">
                                        <div class="product-detail-content">
                                            <!--Product details-->
                                            <div class="new-arrival-content">
                                                <h4><?php echo $row['tittle']; ?></h4>
                                                  <p><span class="item fs-14">Status:</span>&nbsp;&nbsp;
                                                  
                                                  	<?Php 
  if($row['status'] == 1){ echo' <span class="badge badge-success light">Published</span>';} 
  elseif($row['status'] == 2){echo'  <span class="badge badge-warning light">Draft</span>'; }
  elseif($row['status'] == 3){echo'  <span class="badge badge-warning light">Unapproved</span>'; }
  
   elseif($row['status'] == 4){echo'  <span class="badge badge-warning light">Submit for Approval</span>'; }
  else{ echo'  <span class="badge badge-danger light">Expired</span>'; } ?>
                                                   
                                                    
                                                    
                                                    
                                                </p>
                                             
                                             
                                                <p><span class="item fs-14">Location:</span>	 <?php echo $row['pname']; ?></p>
                                                <p><span class="item fs-14"> Date:</span> <?php echo $row['start']; ?> - <?php echo $row['end']; ?></p>
                                                  
                                                  <p><span class="item fs-14">Total View:</span> <?php echo $totalViews; ?>+</p>
                                                                                                    <!--<p><span class="item fs-14">Event Id:</span> <?php echo $event_id; ?></p>-->
<!--<div class="d-flex flex-wrap mt-4 gap-2">-->

    <!-- Edit -->
<!--    <a href="edit-event?type=<?php echo $ticketing_type; ?>&id=<?php echo $event_id; ?>" -->
<!--       class="btn btn-info btn-sm w-25">-->
<!--        <i class="fa fa-edit"></i> Edit-->
<!--    </a>-->

    <!-- Enable/Disable -->
<!--    <?php if ($status == 1) { ?>-->
<!--        <a href="?toggle=<?php echo $event_id; ?>&id=<?php echo $id; ?>" -->
<!--           class="btn btn-warning btn-sm w-25"-->
<!--           onclick="return confirm('Are you sure you want to disable this event?');">-->
<!--            <i class="fa fa-ban"></i> Disable-->
<!--        </a>-->
<!--    <?php } else { ?>-->
<!--        <a href="?toggle=<?php echo $event_id; ?>&id=<?php echo $id; ?>" -->
<!--           class="btn btn-success btn-sm w-25"-->
<!--           onclick="return confirm('Do you want to enable this event?');">-->
<!--            <i class="fa fa-check"></i> Enable-->
<!--        </a>-->
<!--    <?php } ?>-->

    <!-- Banner On/Off -->
<!--    <?php if ($banner_status == 1) { ?>-->
<!--        <a href="?banner=<?php echo $event_id; ?>&id=<?php echo $id; ?>" -->
<!--           class="btn btn-secondary btn-sm w-25"-->
<!--           onclick="return confirm('Hide this event from banner?');">-->
<!--            <i class="fa fa-eye-slash"></i> Banner Off-->
<!--        </a>-->
<!--    <?php } else { ?>-->
<!--        <a href="?banner=<?php echo $event_id; ?>&id=<?php echo $id; ?>" -->
<!--           class="btn btn-primary btn-sm w-25"-->
<!--           onclick="return confirm('Show this event on banner?');">-->
<!--            <i class="fa fa-eye"></i> Banner On-->
<!--        </a>-->
<!--    <?php } ?>-->

    <!-- Duplicate -->
<!--    <a href="?duplicate=<?php echo $event_id; ?>&id=<?php echo $id; ?>" -->
<!--       class="btn btn-dark btn-sm w-25"-->
<!--       onclick="return confirm('Duplicate this event?');">-->
<!--        <i class="fa fa-copy"></i> Duplicate-->
<!--    </a>-->

    <!-- Delete -->
<!--    <a href="?delete=<?php echo $event_id; ?>" -->
<!--       class="btn btn-danger btn-sm w-25"-->
<!--       onclick="return confirm('⚠️ Are you sure you want to delete this event permanently?');">-->
<!--        <i class="fa fa-trash"></i> Delete-->
<!--    </a>-->
<!--</div>-->
<div class="d-flex align-items-center"> <a href="duplicate-event?id=<?php echo $id; ?>" class="btn btn-primary btn-sm w-50 me-2"><i class="fa fa-caret-right"></i> Duplicate </a> <a href="edit-event?type=<?php echo $ticketing_type; ?>&id=<?php echo $id; ?>" class="btn btn-info btn-sm w-50 me-2"><i class="fa fa-caret-right"></i> Edit </a> <button type="button" class="btn btn-warning btn-sm me-2 w-50" onclick="disableEvent(<?php echo $id; ?>)"> <i class="fa fa-ban"></i> Disable </button> <!-- Delete Button --> <button type="button" class="btn btn-danger btn-sm w-50" onclick="deleteEvent(<?php echo $id; ?>)"> <i class="fa fa-trash"></i> Delete </button> <form method="POST"> <?php if ( $_SESSION['user_type']== 2){ if ($bannerstatus == 1) { echo '<button type="submit" name="toggle_banner" class="btn btn-danger btn-sm w-100"> <i class="fa fa-minus color-danger"></i> Disable Banner </button>'; } else { echo '<button type="submit" name="toggle_banner" class="btn btn-success btn-sm w-100"> <i class="fa fa-check color-success"></i> Enable Banner </button>'; } } ?> </form> </div>
										 </div>
                                        </div>
                                    </div>
                                </div>
								
                            </div>
                        </div>
                    </div>
					<!-- review -->
				
                </div>
                
                
                   <div class="row">
                    <div class="col-lg-12">
                        <div class="card">
                            <div class="card-body">
				 <label for="cname" class="control-label col-lg-2">Long Url </label>

              <input class="form-control" type="text" disabled="" value="<?php echo $long_url ?>" id="myInput">
       <br>
                     <label for="cname" class="control-label col-lg-2">Shorten Url </label>

               
                            <input class="form-control" type="text" disabled="" value="<?php echo $shortenedUrl; ?>" id="myInput">

				
                            </div>
                        </div>
                    </div>
					<!-- review -->
				
                </div>
  <?php
// Handle CSV export
if (isset($_POST['export_csv'])) {
    // Prepare the CSV output
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="payments_data.csv"');

    // Clear any previous output buffers
    ob_clean();
    flush();

    // Open the output stream to php://output
    $output = fopen('php://output', 'w');

    // Fetch all unique ticket names to create dynamic headers
    $ticket_query = "SELECT DISTINCT ticket_name FROM ticket_orders";
    $ticket_result = mysqli_query($conn, $ticket_query);
    $ticket_names = [];
    while ($ticket = mysqli_fetch_assoc($ticket_result)) {
        $ticket_names[] = $ticket['ticket_name'];
    }

    // Prepare the CSV headers
    $headers = array_merge(
        ['Date', 'Name', 'Order Id', 'Email id', 'Phone Number', 'Payment Id'],
        $ticket_names // Append ticket names as columns
    );
    fputcsv($output, $headers);

    // Query to fetch data for CSV export
    $sel_query = "
        SELECT 
            payments.id AS id, 
            payments.name AS name, 
            payments.price AS price, 
            payments.created_at AS created_at, 
            payments.pay_ref AS pay_ref, 
            payments.payment_id AS payment_id, 
            payments.email AS email, 
            payments.phone AS phone 
        FROM 
            payments 
        WHERE 
            payments.event_id = '".$urlsss."' 
        ORDER BY 
            payments.created_at DESC";

    $result = mysqli_query($conn, $sel_query);

    // Fetch each row and map ticket quantities
    while ($row = mysqli_fetch_assoc($result)) {
        $row_data = [
            date('d-m-Y', strtotime($row['created_at'])),
            $row['name'],
            $row['price'],
            $row['pay_ref'],
            $row['email'],
            $row['phone'],
            $row['payment_id']
        ];

        // Fetch ticket quantities for this user
        $ticket_quantity_query = "
            SELECT 
                ticket_name, 
                quantity 
            FROM 
                ticket_orders 
            WHERE 
                payment_ref = '".$row['pay_ref']."'";
        $ticket_quantity_result = mysqli_query($conn, $ticket_quantity_query);
        $ticket_quantities = array_fill_keys($ticket_names, 0); // Initialize quantities with 0

        while ($ticket_row = mysqli_fetch_assoc($ticket_quantity_result)) {
            $ticket_quantities[$ticket_row['ticket_name']] = $ticket_row['quantity'];
        }

        // Merge ticket quantities with row data
        $row_data = array_merge($row_data, $ticket_quantities);
        fputcsv($output, $row_data); // Write row to CSV
    }

    fclose($output);
    exit(); // Stop further script execution
}
?>

<div class="row">
    <div class="col-xl-12 wow fadeInUp" data-wow-delay="1.5s">
        <div class="table-responsive full-data">
            <!-- Export Button -->
            <form method="POST">
                <button type="submit" name="export_csv" class="btn btn-success mb-3">Export to CSV</button>
            </form>

            <!-- Table Display -->
            <table class="table-responsive-lg table display dataTablesCard student-tab dataTable no-footer" id="example-student">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Name</th>
                        <th>Price</th>
                        <th>Order Id</th>
                        <th>Email id</th>
                        <th>Phone Number</th>
                        <th>Payment Id</th>
                        <?php
// Generate ticket headers dynamically
$ticket_result = mysqli_query($conn, "SELECT DISTINCT ticket_name FROM event_tickets WHERE event_id = '$event_id'");
$ticket_names = [];
while ($ticket = mysqli_fetch_assoc($ticket_result)) {
    $ticket_names[] = $ticket['ticket_name'];
    echo "<th>" . $ticket['ticket_name'] . "</th>";
}
?>

                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Query to fetch data for the table
                    $sel_query = "
                        SELECT 
                            payments.id AS id, 
                            payments.name AS name, 
                            payments.price AS price, 
                            payments.created_at AS created_at, 
                            payments.pay_ref AS pay_ref, 
                            payments.payment_id AS payment_id, 
                            payments.email AS email, 
                            payments.phone AS phone 
                        FROM 
                            payments 
                        WHERE 
                            payments.event_id = '".$urlsss."' 
                        ORDER BY 
                            payments.created_at DESC";

                    $result = mysqli_query($conn, $sel_query);
                    while ($row = mysqli_fetch_assoc($result)) {
                        echo "<tr>";
                        echo "<td>" . date('d-m-Y', strtotime($row['created_at'])) . "</td>";
                        echo "<td>" . $row['name'] . "</td>";
                        echo "<td>" . ($row['price'] == 0 ? 'FREE' : $row['price']) . "</td>";
                        echo "<td>" . $row['pay_ref'] . "</td>";
                        echo "<td>" . $row['email'] . "</td>";
                        echo "<td>" . $row['phone'] . "</td>";
                        echo "<td>" . $row['payment_id'] . "</td>";

                        // Fetch and display ticket quantities
                        $ticket_quantity_query = "
                            SELECT 
                                ticket_name, 
                                quantity 
                            FROM 
                                ticket_orders 
                            WHERE 
                                payment_ref = '".$row['pay_ref']."'";
                        $ticket_quantity_result = mysqli_query($conn, $ticket_quantity_query);
                        $ticket_quantities = array_fill_keys($ticket_names, 0);

                        while ($ticket_row = mysqli_fetch_assoc($ticket_quantity_result)) {
                            $ticket_quantities[$ticket_row['ticket_name']] = $ticket_row['quantity'];
                        }

                        foreach ($ticket_names as $ticket_name) {
                            echo "<td>" . $ticket_quantities[$ticket_name] . "</td>";
                        }

                        echo "</tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

            </div>
        </div>
        <!--**********************************
            Content body end
        ***********************************-->
			<!--<td>-->
												<!--	<div class="badge bg-secondary">-->
												<!--		VII A-->
												<!--	</div>-->
												<!--</td>-->
												<!--<td>-->
												<!--	<div class="dropdown custom-dropdown float-end">-->
												<!--		<div class="btn sharp tp-btn " data-bs-toggle="dropdown">-->
												<!--			<svg width="24" height="6" viewBox="0 0 24 6" fill="none" xmlns="http://www.w3.org/2000/svg">-->
												<!--				<path d="M12.0012 0.359985C11.6543 0.359985 11.3109 0.428302 10.9904 0.561035C10.67 0.693767 10.3788 0.888317 10.1335 1.13358C9.88829 1.37883 9.69374 1.67 9.56101 1.99044C9.42828 2.31089 9.35996 2.65434 9.35996 3.00119C9.35996 3.34803 9.42828 3.69148 9.56101 4.01193C9.69374 4.33237 9.88829 4.62354 10.1335 4.8688C10.3788 5.11405 10.67 5.3086 10.9904 5.44134C11.3109 5.57407 11.6543 5.64239 12.0012 5.64239C12.7017 5.64223 13.3734 5.36381 13.8686 4.86837C14.3638 4.37294 14.6419 3.70108 14.6418 3.00059C14.6416 2.3001 14.3632 1.62836 13.8677 1.13315C13.3723 0.637942 12.7004 0.359826 12 0.359985H12.0012ZM3.60116 0.359985C3.25431 0.359985 2.91086 0.428302 2.59042 0.561035C2.26997 0.693767 1.97881 0.888317 1.73355 1.13358C1.48829 1.37883 1.29374 1.67 1.16101 1.99044C1.02828 2.31089 0.959961 2.65434 0.959961 3.00119C0.959961 3.34803 1.02828 3.69148 1.16101 4.01193C1.29374 4.33237 1.48829 4.62354 1.73355 4.8688C1.97881 5.11405 2.26997 5.3086 2.59042 5.44134C2.91086 5.57407 3.25431 5.64239 3.60116 5.64239C4.30165 5.64223 4.97339 5.36381 5.4686 4.86837C5.9638 4.37294 6.24192 3.70108 6.24176 3.00059C6.2416 2.3001 5.96318 1.62836 5.46775 1.13315C4.97231 0.637942 4.30045 0.359826 3.59996 0.359985H3.60116ZM20.4012 0.359985C20.0543 0.359985 19.7109 0.428302 19.3904 0.561035C19.07 0.693767 18.7788 0.888317 18.5336 1.13358C18.2883 1.37883 18.0937 1.67 17.961 1.99044C17.8283 2.31089 17.76 2.65434 17.76 3.00119C17.76 3.34803 17.8283 3.69148 17.961 4.01193C18.0937 4.33237 18.2883 4.62354 18.5336 4.8688C18.7788 5.11405 19.07 5.3086 19.3904 5.44134C19.7109 5.57407 20.0543 5.64239 20.4012 5.64239C21.1017 5.64223 21.7734 5.36381 22.2686 4.86837C22.7638 4.37294 23.0419 3.70108 23.0418 3.00059C23.0416 2.3001 22.7632 1.62836 22.2677 1.13315C21.7723 0.637942 21.1005 0.359826 20.4 0.359985H20.4012Z" fill="#A098AE"/>-->
												<!--			</svg>-->
												<!--		</div>-->
												<!--		<div class="dropdown-menu dropdown-menu-end">-->
												<!--			<a class="dropdown-item" href="javascript:void(0);">Option 1</a>-->
												<!--			<a class="dropdown-item" href="javascript:void(0);">Option 2</a>-->
												<!--			<a class="dropdown-item" href="javascript:void(0);">Option 3</a>-->
												<!--		</div>-->
												<!--	</div>-->
												<!--</td>-->
		
       

		<!--**********************************
           Support ticket button start
        ***********************************-->

        <!--**********************************
           Support ticket button endw
        ***********************************-->
		<?php include '../include/footer.php'; ?>