<?php
include '../include/config.php';
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ---------------------------------------------------------------
// Collect + sanitize POST data.
// NOTE: booking.php's front-end validates phone with
// /^[6-9]\d{9}$/.test(v.replace(/\D/g,'')) — i.e. it strips
// spaces/dashes ONLY for the validation check, then sends the raw
// (untrimmed-of-spaces) value to this endpoint. Cashfree rejects a
// phone number containing anything but 10 digits, which silently
// killed order creation. We strip it the same way here so what we
// send upstream always matches what booking.php actually validated.
// ---------------------------------------------------------------
$amount        = floatval($_POST['amount'] ?? 0);
$name          = trim($_POST['name'] ?? '');
$email         = trim($_POST['email'] ?? '');
$phoneRaw      = trim($_POST['phone'] ?? '');
$phone         = preg_replace('/\D/', '', $phoneRaw); // digits only, matches Cashfree's expectation
$event_id      = $_POST['event_id'] ?? '';
$tickets       = json_decode($_POST['tickets'] ?? '[]', true);
$selected_dates = $_POST['selected_dates'] ?? '';

// ---------------------------------------------------------------
// Server-side validation — mirrors the checks booking.php already
// does client-side, so a tampered/short-circuited request can't
// reach Cashfree with bad data (which is what was causing the
// generic "Failed to create payment order" error).
// ---------------------------------------------------------------
$errors = [];
if ($amount <= 0)                                  $errors[] = 'Invalid amount.';
if (strlen($name) < 2)                              $errors[] = 'Please enter a valid name.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))     $errors[] = 'Please enter a valid email address.';
if (!preg_match('/^[6-9]\d{9}$/', $phone))          $errors[] = 'Please enter a valid 10-digit phone number.';
if (empty($event_id))                               $errors[] = 'Missing event.';
if (empty($tickets) || !is_array($tickets))         $errors[] = 'No tickets selected.';

if (!empty($errors)) {
    // 'message' added so the front-end can show the *actual* reason
    // instead of a generic "Failed to create payment order."
    echo json_encode([
        'error'   => 'Missing or invalid fields',
        'message' => implode(' ', $errors),
        'details' => $errors,
    ]);
    exit;
}

// ---------------------------------------------------------------
// Cashfree credentials.
//
// SECURITY: these should live in config.php / an env var, not be
// hardcoded here. Rotate the secret key below in the Cashfree
// dashboard since it has been exposed in plain text — treat it as
// compromised.

// Generate unique order ID
$orderId    = 'HS' . time() . rand(1000, 9999);
$customerId = preg_replace("/[^a-zA-Z0-9_-]/", "", $email);
if ($customerId === '') $customerId = 'guest' . time();

// Cashfree payload
$payload = [
    "order_id"       => $orderId,
    "order_amount"   => $amount,
    "order_currency" => "INR",
    "order_note"     => "Highspinn Event Payment",
    "customer_details" => [
        "customer_id"    => $customerId,
        "customer_name"  => $name,
        "customer_email" => $email,
        "customer_phone" => $phone,
    ],
    "order_meta" => [
        // Redirect after payment to response.php
        "return_url" => "https://www.highspinn.com/data/cashfree_response.php?order_id={$orderId}",
    ],
];

// ---------------------------------------------------------------
// Create order in Cashfree
// ---------------------------------------------------------------
$ch = curl_init("https://api.cashfree.com/pg/orders");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "x-client-id: $appId",
    "x-client-secret: $secretKey",
    "x-api-version: 2023-08-01",
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    error_log("Cashfree order create — curl error: $curlErr");
    echo json_encode([
        'error'   => 'Curl error: ' . $curlErr,
        'message' => 'Unable to reach the payment gateway. Please try again.',
    ]);
    exit;
}

$data = json_decode($response, true);

// Cashfree responded but not with valid JSON (e.g. an HTML error page
// from a proxy/WAF) — log the raw body so it's debuggable, but don't
// leak it to the client.
if ($data === null) {
    error_log("Cashfree order create — non-JSON response (HTTP $httpCode): $response");
    echo json_encode([
        'error'   => 'Unexpected response from payment gateway. Please try again.',
        'message' => 'Unexpected response from payment gateway. Please try again.',
    ]);
    exit;
}

if ($httpCode >= 200 && $httpCode < 300 && !empty($data['payment_session_id'])) {
    // Store minimal checkout info
    $_SESSION['cashfree_order'] = [
        'order_id'    => $orderId,
        'amount'      => $amount,
        'customer_id' => $customerId,
    ];
    // Store full booking info
    $_SESSION['cashfree_data'] = [
        'event_id'       => $event_id,
        'tickets'        => $tickets,
        'customer'       => ['name' => $name, 'email' => $email, 'phone' => $phone],
        'selected_dates' => $selected_dates,
    ];

    echo json_encode([
        'orderId'             => $data['order_id'] ?? $orderId,
        'payment_session_id'  => $data['payment_session_id'],
        'checkout_url'        => $data['payment_link'] ?? '',
    ]);
    exit;
}

// ---------------------------------------------------------------
// Cashfree rejected the order — log full details server-side and
// surface Cashfree's own message to the client instead of a blind
// generic error, so failures like "invalid customer_phone" are
// actually visible instead of always saying the same unhelpful thing.
// ---------------------------------------------------------------
error_log("Cashfree order create failed (HTTP $httpCode): " . json_encode($data));
echo json_encode([
    'error'   => 'Failed to create Cashfree order',
    'message' => $data['message'] ?? ($data['type'] ?? 'Unknown error from payment gateway'),
    'details' => $data,
]);
exit;
