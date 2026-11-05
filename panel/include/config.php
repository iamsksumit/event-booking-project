<?php
// API Keys for Razorpay (use environment variables in production)
$keyId = 'rzp_live_kBxy0MOsE2nQSa';
$keySecret = 'RTsBnsIGM7dRuuNVzCPp7GgA';
$displayCurrency = 'INR';

// Database credentials
define('DB_SERVER', 'localhost');
define('DB_USER', 'u197683135_host');
define('DB_PASS', 'Vinit@2100');
define('DB_NAME', 'u197683135_host');

// Create the connection
$conn = mysqli_connect(DB_SERVER, DB_USER, DB_PASS, DB_NAME);

// Check for connection error
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Optionally, set character set for better handling of UTF-8 characters
mysqli_set_charset($conn, "utf8");

// If you need to switch between local and production, you could use conditions like this:

// if ($_SERVER['SERVER_NAME'] == 'localhost') {
//     define('DB_SERVER', 'localhost');
//     define('DB_USER', 'root');
//     define('DB_PASS', '');
//     define('DB_NAME', 'highspinn');
//     $conn = mysqli_connect(DB_SERVER, DB_USER, DB_PASS, DB_NAME);
// } else {
//     // Production DB credentials
// }

?>
