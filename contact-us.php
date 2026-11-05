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
			<h1>Contact Us</h1>
		</div>
		<!-- /container -->
	</div>

		

		
	<main>
	<?php
// Handle form submission
$success = $error = "";

if (isset($_POST['send_contact'])) {

    $name    = trim($_POST['name']);
    $email   = trim($_POST['email']);
    $phone   = trim($_POST['phone']);
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);

    $to = "highspinn@gmail.com";
    $mail_subject = "HighSpinn Contact Form: " . $subject;

    $body = "
Name: $name
Email: $email
Phone: $phone

Message:
$message
";

    $headers  = "From: $email\r\n";
    $headers .= "Reply-To: $email\r\n";

    if (mail($to, $mail_subject, $body, $headers)) {
        $success = "Thank you! Your message has been sent successfully.";
    } else {
        $error = "Something went wrong. Please try again later.";
    }
}
?>



<div class="container margin_60_35" style="
    justify-items: center;
">
    <div class="row">
        <div class="col-lg-12">
            <div class="post-content">

                <div style="border: 1px solid #ddd; padding: 20px; border-radius: 8px; max-width: 700px; font-family: Arial, sans-serif; background-color: #f9f9f9; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);">

                    <p style="color: #666; font-size: 14px;">
                        We’d love to hear from you! Whether you have a question about features, pricing, or anything else, our team is ready to answer all your questions.
                    </p>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <!-- CONTACT FORM -->
                    <form method="post" action="">

                        <div class="form-group">
                            <label><strong>Name</strong></label>
                            <input type="text" name="name" class="form-control" placeholder="Your Name" required>
                        </div>

                        <div class="form-group">
                            <label><strong>Email</strong></label>
                            <input type="email" name="email" class="form-control" placeholder="Your Email" required>
                        </div>

                        <div class="form-group">
                            <label><strong>Phone</strong></label>
                            <input type="tel" name="phone" class="form-control" placeholder="Your Phone Number" required>
                        </div>

                        <div class="form-group">
                            <label><strong>Subject</strong></label>
                            <input type="text" name="subject" class="form-control" placeholder="Subject" required>
                        </div>

                        <div class="form-group">
                            <label><strong>Message</strong></label>
                            <textarea name="message" class="form-control" rows="4" placeholder="Write your message..." required></textarea>
                        </div>
                        
                        <div class="form-group" style="text-align: justify;">
    <div class="custom-control custom-checkbox">
        <input type="checkbox"
               class="custom-control-input"
               id="authorizeConsent"
               name="authorize_consent"
               checked
               required>
        <label class="custom-control-label" for="authorizeConsent">
            I authorize <strong>HighSpinn</strong> to send notifications via
            <strong>SMS / RCS / Email / WhatsApp*</strong>
        </label>
    </div>
</div>


                        <button type="submit" name="send_contact" class="btn btn-primary">
                            Send Message
                        </button>

                    </form>

                    <hr>

                    <p>
                        <strong>Address:</strong><br>
                        87, 3rd Main Rd, JP Nagar,<br>
                        4th Phase, Dollars Colony,<br>
                        Bengaluru, Karnataka 560043
                    </p>

                    <p>
                        <strong>Email:</strong><br>
                        <a href="mailto:support@highspinn.com">support@highspinn.com</a>
                    </p>

                    <p>
                        <strong>Working Hours:</strong><br>
                        Monday – Friday: 9:00 AM – 6:00 PM<br>
                        Saturday: 10:00 AM – 2:00 PM
                    </p>

                    <hr>

                    <strong>Keep in touch</strong><br>
                    <a href="https://facebook.com/highspinn" target="_blank">Facebook</a> |
                    <a href="https://twitter.com/highspinn" target="_blank">Twitter</a> |
                    <a href="https://www.linkedin.com/company/highspinn" target="_blank">LinkedIn</a>

                </div>

            </div>
        </div>
    </div>
</div>


			
		<!-- /container -->


		<!-- /container -->
<?php include 'include/footer.php'; ?>
