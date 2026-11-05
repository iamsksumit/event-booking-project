<?php
/**
 * auth/send_otp.php
 * POST: phone (10 digits)
 * Generates a 6-digit OTP, stores it in session (5 min expiry, 30s resend
 * cooldown) and sends it via Fast2SMS's "quick" transactional route
 * (route=q) — same route used elsewhere in the codebase for SMS.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
define('OTP_DEBUG', true);
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json');
        }
        $payload = ['success' => false, 'message' => 'Could not send OTP. Please try again.'];
        if (defined('OTP_DEBUG') && OTP_DEBUG) {
            $payload['debug'] = $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'];
        }
        echo json_encode($payload);
    }
});
require_once __DIR__ . '/../include/config.php';
header('Content-Type: application/json');
$rawPhoneInput = $_POST['phone'] ?? '';
$phone = preg_replace('/\D/', '', $rawPhoneInput);
if (!preg_match('/^\d{10}$/', $phone)) {
    $payload = ['success' => false, 'message' => 'Enter a valid 10-digit mobile number.'];
    if (defined('OTP_DEBUG') && OTP_DEBUG) {
        // TEMPORARY — remove once the mismatch is diagnosed.
        $payload['debug'] = [
            'raw_post_phone'  => $rawPhoneInput,
            'raw_length'      => strlen($rawPhoneInput),
            'normalized'      => $phone,
            'normalized_len'  => strlen($phone),
            'all_post_keys'   => array_keys($_POST),
        ];
    }
    echo json_encode($payload);
    exit;
}
// Resend cooldown - 30 seconds between OTP requests for the same session
if (!empty($_SESSION['otp_last_sent']) && (time() - $_SESSION['otp_last_sent']) < 30) {
    $wait = 30 - (time() - $_SESSION['otp_last_sent']);
    echo json_encode(['success' => false, 'message' => "Please wait {$wait}s before requesting another OTP."]);
    exit;
}
$otp = strval(random_int(100000, 999999));
$_SESSION['otp_phone']     = $phone;
$_SESSION['otp_code']      = $otp;
$_SESSION['otp_expires']   = time() + 300; // 5 minutes
$_SESSION['otp_attempts']  = 0;
$_SESSION['otp_last_sent'] = time();
unset($_SESSION['otp_verified_phone']);
if (!hs_send_otp_sms($phone, $otp)) {
    echo json_encode(['success' => false, 'message' => 'Could not send OTP right now. Please try again in a bit.']);
    exit;
}
echo json_encode(['success' => true, 'message' => 'OTP sent to +91 ' . $phone]);
/**
 * Sends the OTP through Fast2SMS's quick route (route=q) — same
 * account/API key used elsewhere in the codebase for transactional SMS.
 */
function hs_send_otp_sms($phone, $otp) {
    // Prefer API key from config.php
    $api_key = defined('FAST2SMS_API_KEY')
        ? FAST2SMS_API_KEY
        : "m52gpELkAJxVSvBGIlnaOrzuRj6FciftdDTWybM7wQPe089ZKXWfn8mJU3pawNy1qrPQoGXeL0Ddhug2";
    // DLT-approved template ID
    $message_id = "222642";
    // DLT template variables:
    // {#var#} = OTP
    // {#var#} = Validity
    $variables_values = $otp . "|5 minutes";
    $smsUrl = "https://www.fast2sms.com/dev/bulkV2?"
        . "authorization=" . urlencode($api_key)
        . "&route=dlt"
        . "&sender_id=HSPlNN"
        . "&message=" . urlencode($message_id)
        . "&variables_values=" . urlencode($variables_values)
        . "&flash=0"
        . "&numbers=" . urlencode($phone);
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $smsUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            "cache-control: no-cache"
        ],
    ]);
    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);
    if ($err) {
        error_log("OTP SMS cURL error: " . $err);
        return false;
    }
    $data = json_decode($response, true);
    if (is_array($data) && !empty($data['return'])) {
        return true;
    }
    error_log("OTP SMS send failed. Response: " . $response);
    return false;
}