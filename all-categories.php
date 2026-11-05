<?php 
include('include/config.php'); ?>

<?php

// Fetch data based on the page type
$title = 'Upcoming Events and Parties in Bangalore - Highspinn' ;
$description = 'Select from the best  events in bangalore, for a wonderful evening. Select from multiple categories like DJ nights, Concerts, Band Performance, ladies night, comedy shows and more for unlimited fun.';
// $keywords = $row['name'];
$image = 'https://www.highspinn.com/img/logo.png';
$url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

if (isset($pageData)) {
    $title = htmlspecialchars($pageData['title'] ?? $title);
    $description = htmlspecialchars($pageData['description'] ?? $description);
    // $keywords = htmlspecialchars($pageData['keywords'] ?? $keywords);
    $image = htmlspecialchars($pageData['image'] ?? $image);
    $url = htmlspecialchars($pageData['url'] ?? $url);
}
?>

<?php include 'include/header.php'; ?>
	    
	<section class="banner-margin" >
	    
	    <div class="wrapper" >
<div class="main_title_2">
						<h2>Our Categories</h2>
						<small>Visit your favourite artist profiles and book parties, events on Highspinn


</small>

					</div>
					
				
   </div>
</section>

		
		
		<div class="container margin_60_35">
		

			<div class="row">
<?php
$today = date('Y-m-d');

// Step 1: Fetch all upcoming events
$eventQuery = $conn->query("
    SELECT event_category.cat_id, events.start, events.multiple_dates
    FROM events
    INNER JOIN event_category ON events.id = event_category.event_id
    WHERE events.status = 1
");

$categoryUpcomingEvents = [];

// Step 2: Count upcoming events per category
while($event = $eventQuery->fetch_assoc()){
    $catId = $event['cat_id'];
    $isUpcoming = false;

    // Check main start date
    if($event['start'] >= $today){
        $isUpcoming = true;
    }

    // Check multiple_dates
    if(!$isUpcoming && !empty($event['multiple_dates'])){
        $dates = explode(',', $event['multiple_dates']);
        foreach($dates as $d){
            if(trim($d) >= $today){
                $isUpcoming = true;
                break;
            }
        }
    }

    if($isUpcoming){
        if(!isset($categoryUpcomingEvents[$catId])){
            $categoryUpcomingEvents[$catId] = 0;
        }
        $categoryUpcomingEvents[$catId]++;
    }
}

// Step 3: Separate performing and non-performing categories
$allCategoryIds = [];
$catQueryAll = $conn->query("SELECT id FROM category");
while($row = $catQueryAll->fetch_assoc()){
    $allCategoryIds[] = $row['id'];
}

$performingIds = array_keys($categoryUpcomingEvents);
$nonPerformingIds = array_diff($allCategoryIds, $performingIds);

// Step 4: Sort performing categories by upcoming events count
arsort($categoryUpcomingEvents);

// Step 5: Fetch and display performing categories
if(!empty($performingIds)){
    $performingIdsString = implode(',', $performingIds);
    $performingQuery = $conn->query("SELECT * FROM category WHERE id IN ($performingIdsString) ORDER BY FIELD(id, $performingIdsString)");
    while($row = $performingQuery->fetch_assoc()){
        $imageURL = $row["image"];
        $name = $row['cat_name'];
        $id = $row["cat_slug"];
        $eventCount = $categoryUpcomingEvents[$row['id']];
        ?>
        <div class="col-6">
            <a class="box_news" href="/all-events?event_category=<?php echo $id ?>" style="align-content: space-around;">
                <figure><img src="<?php echo $imageURL; ?>" alt=""></figure>
                <h4><?php echo $name; ?> (<?php echo $eventCount; ?>)</h4>
            </a>
        </div>
        <?php
    }
}

// Step 6: Fetch and display non-performing categories
if(!empty($nonPerformingIds)){
    $nonPerformingIdsString = implode(',', $nonPerformingIds);
    $nonPerformingQuery = $conn->query("SELECT * FROM category WHERE id IN ($nonPerformingIdsString) ORDER BY id DESC");
    while($row = $nonPerformingQuery->fetch_assoc()){
        $imageURL = $row["image"];
        $name = $row['cat_name'];
        $id = $row["cat_slug"];
        ?>
        <div class="col-6">
            <a class="box_news" href="/all-events?event_category=<?php echo $id ?>" style="align-content: space-around;">
                <figure><img src="<?php echo $imageURL; ?>" alt=""></figure>
                <h4><?php echo $name; ?></h4>
            </a>
        </div>
        <?php
    }
}

if(empty($performingIds) && empty($nonPerformingIds)){
    echo "<p>No categories found...</p>";
}
?>
</div>


		
		
		</div>
		<!-- /container -->
		
	
		

<?php include 'include/footer.php'; ?>
