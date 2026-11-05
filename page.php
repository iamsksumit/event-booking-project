<?php 
include 'include/config.php'; ?>

<?php



$id=$_REQUEST['url'];
$query = "SELECT * from page where slug='$id'"; 
$result = mysqli_query($conn, $query) or die ( mysqli_error());
$row = mysqli_fetch_assoc($result);
  
 
     
//     if ($row['url'] != $_GET['url']) {
//     header("HTTP/1.1 404 Not Found");
//     echo '<script> window.location.replace("/404") </script>';
//     exit();
// }


function trimWords($text, $wordLimit = 30) {
    // Strip HTML tags if content has any
    $text = strip_tags($text);

    // Split the text into an array of words
    $words = explode(' ', $text);

    // Keep only the first $wordLimit words
    $trimmedText = implode(' ', array_slice($words, 0, $wordLimit));

    // Add ellipsis if the content is longer than $wordLimit
    if (count($words) > $wordLimit) {
        $trimmedText .= '...';
    }

    return $trimmedText;
}

// Example usage
$content = $row['content']; // Assuming $row['content'] holds your content
$shortContent = trimWords($content, 30);





// Fetch data based on the page type
$title = $row['name']. '- Highspinn' ;
$description = $row['name'].'- '.$shortContent.' Now on Highspinn';
$keywords = $row['name'];
$url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

if (isset($pageData)) {
    $title = htmlspecialchars($pageData['title'] ?? $title);
    $description = htmlspecialchars($pageData['description'] ?? $description);
    $keywords = htmlspecialchars($pageData['keywords'] ?? $keywords);
    $url = htmlspecialchars($pageData['url'] ?? $url);
}

include 'include/header.php'; 



?>

	<!-- SPECIFIC CSS -->
	<link href="css/blog.css" rel="stylesheet">

	<div class="sub_header_in sticky_header">
		<div class="container">
			<h1><?php echo $row['name'];?></h1>
		</div>
		<!-- /container -->
	</div>

		
	<main>
		<div class="container margin_60_35">

			<div class="row">
				<div class="col-lg-12">

						<!-- /post meta -->
						<div class="post-content">
								<?php echo $row['content'];?>
						<!-- /post -->
					</div>
					<!-- /single-post -->

		<!-- /container -->
				</div>
				<!-- /col -->

			
				<!-- /aside -->
			</div>
			<!-- /row -->
		</div>
		<!-- /container -->
<?php include 'include/footer.php'; ?>
