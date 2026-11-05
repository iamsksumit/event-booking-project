<div class="col-xl-4">
						<div class="row">

							<div class="col-xl-12">
								<div class="card">
									<div class="card-body">
										<div class="profile-news">
											<h5 class="text-primary d-inline">Upcoming Booked Event</h5>


<?php  
$upcoming_event = "SELECT 
    events.tittle, 
    events.sub_tittle,
    events.start, 
    events.id,
    events.image, 
    payments.event_id, 
    payments.name, 
    payments.email, 
    payments.phone, 
    users.email AS user_email, 
    users.contactno AS user_contactno, 
    users.user_type
FROM events
INNER JOIN payments ON payments.event_id = events.url 
INNER JOIN users ON payments.email = users.email OR payments.phone = users.contactno
WHERE users.id = $user_id 
AND events.start >= CURDATE()
ORDER BY events.start ASC
LIMIT 3";  // Limit to 3 events

// Execute the query
$result = mysqli_query($conn, $upcoming_event);

// Check if the query was successful
if ($result) {
    // Check if the result contains any rows
    if (mysqli_num_rows($result) > 0) {
        // Loop through all the rows and display them
        while ($row = mysqli_fetch_assoc($result)) {
            ?>
            		<div class="media pt-3 pb-3">

            <div class="event-item">
                <img src="<?php echo $row['image']; ?>" alt="event image" class="me-3 rounded" width="75">
                <div class="media-body">
                    <h5 class="m-b-5"><a href="post-details.html" class="text-black"><?php echo $row['tittle']; ?></a></h5>
                    <p><?php echo $row['start']; ?></p>
                </div>
            </div>
                        </div>
            
            <?php
        }
    } else {
        echo "No events found for the given user ID.";
    }
} else {
    echo "Error executing the query: " . mysqli_error($conn);
}
?>

            									
           


							

									
											
										</div>
									</div>
								</div>
							</div>
						</div>
                    </div>