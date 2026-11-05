<?php
/**
 * auth/complete_profile.php
 * POST: phone, name, email, gender, city
 *
 * `users` now has `status` and `city` columns. City from the form is
 * persisted. Requires the phone to have been OTP-verified in this
 * session (auth/verify_otp.php sets $_SESSION['otp_verified_phone']).
 * Inserts a new `users` row (or finishes an existing incomplete row,
 * i.e. one with blank fname/email) and logs the user in.
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
        $payload = ['success' => false, 'message' => 'Could not save your details. Please try again.'];
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

    if (empty($_SESSION['otp_verified_phone']) || $_SESSION['otp_verified_phone'] !== $phone) {
        echo json_encode(['success' => false, 'message' => 'Please verify your mobile number again.']);
        exit;
    }

    $fullName = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $gender   = trim($_POST['gender'] ?? '');
    $city     = trim($_POST['city'] ?? '');

    if ($fullName === '' || $email === '' || $gender === '' || $city === '') {
        echo json_encode(['success' => false, 'message' => 'Please fill in all the fields.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Enter a valid email address.']);
        exit;
    }
    $allowedGenders = ['Male', 'Female', 'Other'];
    if (!in_array($gender, $allowedGenders, true)) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid gender.']);
        exit;
    }

    $nameParts = preg_split('/\s+/', $fullName, 2);
    $fname = $nameParts[0];
    $lname = $nameParts[1] ?? '';

    // Does a row for this phone already exist (e.g. verified before but
    // never finished onboarding)?
    $stmt = $conn->prepare("SELECT id FROM users WHERE contactno = ? LIMIT 1");
    if ($stmt === false) { throw new Exception('DB prepare failed (lookup by phone): ' . $conn->error); }
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Email must be unique across OTHER accounts
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND contactno != ? LIMIT 1");
    if ($stmt === false) { throw new Exception('DB prepare failed (email check): ' . $conn->error); }
    $stmt->bind_param('ss', $email, $phone);
    $stmt->execute();
    $emailTaken = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($emailTaken) {
        echo json_encode(['success' => false, 'message' => 'This email is already linked to another account.']);
        exit;
    }

    if ($existing) {
        $stmt = $conn->prepare("UPDATE users SET fname = ?, lname = ?, gender = ?, email = ?, city = ?, status = 1, updated_at = NOW() WHERE id = ?");
        if ($stmt === false) { throw new Exception('DB prepare failed (update): ' . $conn->error); }
        $stmt->bind_param('sssssi', $fname, $lname, $gender, $email, $city, $existing['id']);
        $ok = $stmt->execute();
        $stmt->close();
        $userId = $existing['id'];
    } else {
        // If `password` is NOT NULL in your table with no default, this
        // insert will fail without it. A random placeholder hash is safe
        // here since this account only ever logs in via OTP.
        $placeholderPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (fname, lname, gender, email, password, contactno, city, status, user_type, posting_date, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'user', NOW(), NOW())");
        if ($stmt === false) { throw new Exception('DB prepare failed (insert): ' . $conn->error); }
        $stmt->bind_param('sssssss', $fname, $lname, $gender, $email, $placeholderPassword, $phone, $city);
        $ok = $stmt->execute();
        $userId = $stmt->insert_id;
        $stmt->close();
    }

    if (!$ok) {
        throw new Exception('Save failed: ' . $conn->error);
    }

    $_SESSION['hs_user_id']     = $userId;
    $_SESSION['hs_user_name']   = $fullName;
    $_SESSION['hs_user_phone']  = $phone;
    $_SESSION['hs_user_email']  = $email;
    $_SESSION['hs_user_gender'] = $gender;
    $_SESSION['hs_user_city']   = $city;
    unset($_SESSION['otp_verified_phone']);

    echo json_encode([
        'success' => true,
        'status'  => 'registered',
        'user'    => ['id' => $userId, 'name' => $fullName, 'email' => $email],
    ]);

} catch (Exception $e) {
    error_log('complete_profile.php error: ' . $e->getMessage());
    $payload = ['success' => false, 'message' => 'Could not save your details. Please try again.'];
    if (defined('OTP_DEBUG') && OTP_DEBUG) {
        $payload['debug'] = $e->getMessage();
    }
    echo json_encode($payload);
    exit;
}