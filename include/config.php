<?php
// ======================================
// Razorpay
// ======================================
$keyId = 'rzp_live_kBxy0MOsE2nQSa';
$keySecret = 'RTsBnsIGM7dRuuNVzCPp7GgA';
$displayCurrency = 'INR';
require_once __DIR__ . '/../dompdf/vendor/autoload.php';
// ======================================
// Timezone
// ======================================
date_default_timezone_set('Asia/Kolkata');
// ======================================
// MySQL Error Reporting
// ======================================
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// ======================================
// Database Configuration
// ======================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'u197683135_host');
define('DB_USER', 'u197683135_host');
define('DB_PASS', 'Vinit@2100');
// ======================================
// Fast2SMS (SMS + WhatsApp/WABA)
// ======================================
define('FAST2SMS_API_KEY', 'm52gpELkAJxVSvBGIlnaOrzuRj6FciftdDTWybM7wQPe089ZKXWfn8mJU3pawNy1qrPQoGXeL0Ddhug2');
define('FAST2SMS_WHATSAPP_MESSAGE_ID', 28648); // highspinn_event_booking_confirmation1 (8 vars, image header)
try {
    $conn = new mysqli(
        DB_HOST,
        DB_USER,
        DB_PASS,
        DB_NAME
    );
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    die("Database Connection Failed: " . $e->getMessage());
}
?>