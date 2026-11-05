<?php
include '../include/config.php'; // $conn = mysqli connection
session_start();

// Get session order and data
$order = $_SESSION['cashfree_order'] ?? null;
$data  = $_SESSION['cashfree_data'] ?? null;

if (!$order || !$data) {
    header("Location: /book/unknown?status=expired");
    exit;
}

$event_id = $data['event_id'] ?? '';
$bookNowUrl = "/book/" . urlencode($event_id);

// Get order ID from redirect
$orderId = $_GET['order_id'] ?? '';
if (!$orderId || $orderId !== $order['order_id']) {
    header("Location: {$bookNowUrl}?status=invalid");
    exit;


// Verify payment
$verifyUrl = "https://api.cashfree.com/pg/orders/$orderId";
$ch = curl_init($verifyUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "x-client-id: $appId",
    "x-client-secret: $secretKey",
    "x-api-version: 2023-08-01"
]);
$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
$paymentStatus = $result['order_status'] ?? '';

if ($paymentStatus !== 'PAID') {
    header("Location: {$bookNowUrl}?status=failed");
    exit;
}

// Payment successful
$payment_id = $result['cf_payment_id'] ?? '';
$paymentRef = 'HS' . mt_rand(100000, 999999) . time();

// Sanitize customer data
$name  = mysqli_real_escape_string($conn, $data['customer']['name']);
$email = mysqli_real_escape_string($conn, $data['customer']['email']);
$phone = mysqli_real_escape_string($conn, $data['customer']['phone']);
$selected_dates = mysqli_real_escape_string($conn, $data['selected_dates']);
$price = floatval($order['amount']);
$tickets = $data['tickets'] ?? [];

// Insert into payments
$paymentSql = "INSERT INTO payments (name, email, phone, event_id, selected_dates, price, payment_id, pay_ref) 
               VALUES ('$name', '$email', '$phone', '$event_id', '$selected_dates', '$price', '$payment_id', '$paymentRef')";
if (!$conn->query($paymentSql)) {
    header("Location: {$bookNowUrl}?status=db_error");
    exit;
}

// Insert tickets and update stock
foreach ($tickets as $ticketId => $ticket) {
    $ticketName = mysqli_real_escape_string($conn, $ticket['name']);
    $ticketQty  = intval($ticket['qty']);
    $ticketPrice = floatval($ticket['cost']);

    if ($ticketQty > 0) {
        $conn->query("INSERT INTO ticket_orders (payment_ref, ticket_name, quantity, price) 
                      VALUES ('$paymentRef', '$ticketName', '$ticketQty', '$ticketPrice')");

        // Update ticket stock
        $ticketResult = $conn->query("SELECT tickets_left FROM event_tickets WHERE id='$ticketId'");
        if ($ticketResult && $ticketResult->num_rows > 0) {
            $row = $ticketResult->fetch_assoc();
            $newTicketsLeft = max(0, $row['tickets_left'] - $ticketQty);
            $conn->query("UPDATE event_tickets SET tickets_left='$newTicketsLeft' WHERE id='$ticketId'");
        }
    }
}

// Generate ticket link
$ticket_link = "/ticket/$paymentRef";
$download_link = "https://{$_SERVER['HTTP_HOST']}$ticket_link";

// Call HSPLNN shortener API
$ch = curl_init("https://highspinn.com/hsplnn/shorten_api.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["long_url" => $download_link]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
$response = curl_exec($ch);
curl_close($ch);

$shortData = json_decode($response, true);
$shortenedUrl = $shortData['short_url'] ?? $download_link;

// Separate links for email and SMS
$emailLink = $shortenedUrl; // full URL for email
$shortenedUrlNoProtocol = str_replace(['https://', 'http://'], '', $shortenedUrl);
$shortenedUrlEnc = urlencode($shortenedUrlNoProtocol);

// Fetch emails from settings
$result = $conn->query("SELECT name, value FROM settings WHERE name IN ('support_email','no_reply_email')");
$support_email = $no_reply_email = '';
while ($row = $result->fetch_assoc()) {
    if ($row['name'] == 'support_email') $support_email = $row['value'];
    if ($row['name'] == 'no_reply_email') $no_reply_email = $row['value'];
}

// Prepare email
$subject = "Highspinn Ticket - Payment Ref: $paymentRef";
$message = '
<html>
<head>
<style>
body { font-family: Arial, sans-serif; background-color: #f5f5f5; color: #000; }
.ticket { max-width: 600px; margin: 20px auto; padding: 20px; background: #fff; border-radius: 10px; border: 2px dashed #000; }
.header { text-align: center; color: #000; font-size: 24px; font-weight: bold; margin-bottom: 20px; }
.details p { margin: 5px 0; line-height: 1.4; }
.tickets { margin: 20px 0; }
.tickets table { width: 100%; border-collapse: collapse; }
.tickets th, .tickets td { border: 1px solid #000; padding: 8px; text-align: center; }
.tickets th { background-color: #000; color: #fff; }
.cta { text-align: center; margin-top: 20px; }
.cta a { display: inline-block; padding: 10px 20px; background: #000; color: #fff; text-decoration: none; border-radius: 5px; font-weight: bold; }
.footer { margin-top: 20px; font-size: 12px; text-align: center; color: #555; }
</style>
</head>
<body>
<div class="ticket">
<div class="header">HIGHSPINN TICKET</div>
<div class="details">
<p><strong>Name:</strong> '.$name.'</p>
<p><strong>Email:</strong> '.$email.'</p>
<p><strong>Phone:</strong> '.$phone.'</p>
<p><strong>Payment Ref:</strong> '.$paymentRef.'</p>
<p><strong>Selected Dates:</strong> '.$selected_dates.'</p>
<p><strong>Total Price:</strong> ₹'.$price.'</p>
</div>
<div class="tickets">
<h4>Tickets Purchased:</h4>
<table>
<tr><th>Ticket Name</th><th>Quantity</th><th>Price</th></tr>';

foreach ($tickets as $ticket) {
    $ticketName = htmlspecialchars($ticket['name']);
    $ticketQty = intval($ticket['qty']);
    $ticketPrice = floatval($ticket['cost']);
    $message .= "<tr><td>$ticketName</td><td>$ticketQty</td><td>₹$ticketPrice</td></tr>";
}

$message .= '
</table>
</div>
<div class="cta">
<a href="'.$emailLink.'" target="_blank">View Your Ticket</a>
</div>
<div class="footer">
Please present this ticket at the event entrance.<br>
&copy; '.date("Y").' Highspinn
</div>
</div>
</body>
</html>';

// Email headers
$headers = "From: $no_reply_email\r\n";
$headers .= "Reply-To: $support_email\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";

// Send email
@mail($email, $subject, $message, $headers);

// Send SMS
$api_key = "m52gpELkAJxVSvBGIlnaOrzuRj6FciftdDTWybM7wQPe089ZKXWfn8mJU3pawNy1qrPQoGXeL0Ddhug2"; // Securely store this
$nameEnc = urlencode($name);
$shortenedUrlEnc = urlencode($shortenedUrlNoProtocol);
$phoneEnc = urlencode($phone);

$sms_url = "https://www.fast2sms.com/dev/bulkV2?"
    . "authorization=$api_key"
    . "&route=dlt"
    . "&sender_id=HSPlNN"
    . "&message=176019"
    . "&variables_values=$nameEnc%7C$shortenedUrlEnc%7C"
    . "&flash=0"
    . "&numbers=$phoneEnc";

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $sms_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => "GET",
]);
curl_exec($curl);
curl_close($curl);

// Clear sessions
unset($_SESSION['cashfree_order'], $_SESSION['cashfree_data']);

// Redirect user to ticket page
header("Location: $shortenedUrl");
exit;
?>
