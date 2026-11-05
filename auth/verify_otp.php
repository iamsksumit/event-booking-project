<?php
/**
 * auth/verify_otp.php
 * POST: phone, otp
 *
 * NOTE: `users` table has `status` and `city` columns available now, but
 * this endpoint still only selects what it actually needs.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

// Turn this off once verification is confirmed working.
define('OTP_DEBUG', true);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json');
        }
        $payload = ['success' => false, 'message' => 'Something went wrong. Please try again.'];
        if (defined('OTP_DEBUG') && OTP_DEBUG) {
            $payload['debug'] = $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'];
        }
        echo json_encode($payload);
    }
});

require_once __DIR__ . '/../include/config.php';
header('Content-Type: application/json');

try {

    $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $otp   = preg_replace('/\D/', '', $_POST['otp'] ?? '');

    if (empty($_SESSION['otp_code']) || empty($_SESSION['otp_phone'])) {
        echo json_encode(['success' => false, 'message' => 'Please request a new OTP.']);
        exit;
    }
    if ($_SESSION['otp_phone'] !== $phone) {
        echo json_encode(['success' => false, 'message' => 'Phone number mismatch. Please request a new OTP.']);
        exit;
    }
    if (time() > (int) $_SESSION['otp_expires']) {
        unset($_SESSION['otp_code'], $_SESSION['otp_expires'], $_SESSION['otp_phone'], $_SESSION['otp_attempts']);
        echo json_encode(['success' => false, 'message' => 'OTP expired. Please request a new one.']);
        exit;
    }

    $_SESSION['otp_attempts'] = (int) ($_SESSION['otp_attempts'] ?? 0) + 1;
    if ($_SESSION['otp_attempts'] > 5) {
        echo json_encode(['success' => false, 'message' => 'Too many incorrect attempts. Please request a new OTP.']);
        exit;
    }

    if (!hash_equals((string) $_SESSION['otp_code'], $otp)) {
        echo json_encode(['success' => false, 'message' => 'Incorrect OTP. Please try again.']);
        exit;
    }

    // ---- OTP correct: mark this phone verified for this session, clear the code ----
    $_SESSION['otp_verified_phone'] = $phone;
    unset($_SESSION['otp_code'], $_SESSION['otp_expires'], $_SESSION['otp_phone'], $_SESSION['otp_attempts']);

    $stmt = $conn->prepare("SELECT id, fname, lname, gender, email, contactno, city, status FROM users WHERE contactno = ? LIMIT 1");
    if ($stmt === false) {
        throw new Exception('DB prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('s', $phone);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    // "Profile complete" = we already have a name and email on file.
    $profileComplete = $user && trim((string) $user['fname']) !== '' && trim((string) $user['email']) !== '';

    if ($profileComplete) {
        $_SESSION['hs_user_id']     = $user['id'];
        $_SESSION['hs_user_name']   = trim($user['fname'] . ' ' . $user['lname']);
        $_SESSION['hs_user_phone']  = $user['contactno'];
        $_SESSION['hs_user_email']  = $user['email'];
        $_SESSION['hs_user_gender'] = $user['gender'];
        $_SESSION['hs_user_city']   = $user['city'];
        unset($_SESSION['otp_verified_phone']);

        echo json_encode([
            'success' => true,
            'status'  => 'logged_in',
            'user'    => [
                'id'    => $user['id'],
                'name'  => trim($user['fname'] . ' ' . $user['lname']),
                'email' => $user['email'],
            ],
        ]);
        exit;
    }

    // New phone, or an existing row missing name/email -> collect details.
    echo json_encode([
        'success' => true,
        'status'  => 'need_details',
        'prefill' => [
            'fname'  => $user['fname']  ?? '',
            'lname'  => $user['lname']  ?? '',
            'email'  => $user['email']  ?? '',
            'gender' => $user['gender'] ?? '',
            'city'   => $user['city']   ?? '',
        ],
    ]);

} catch (Exception $e) {
    error_log('verify_otp.php error: ' . $e->getMessage());
    $payload = ['success' => false, 'message' => 'Something went wrong. Please try again.'];
    if (defined('OTP_DEBUG') && OTP_DEBUG) {
        $payload['debug'] = $e->getMessage();
    }
    echo json_encode($payload);
    exit;
}