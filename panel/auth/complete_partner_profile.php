<?php
/**
 * auth/complete_partner_profile.php
 * POST: phone, name, email, gender, password
 *
 * Partner-panel equivalent of complete_profile.php — the difference is
 * this one actually collects and stores a real password (the main-site
 * flow is OTP-only and just writes a random placeholder hash).
 *
 * Requires the phone to have been OTP-verified in this session
 * (auth/verify_otp.php sets $_SESSION['otp_verified_phone']).
 *
 * ASSUMPTION: user_type = 2 marks a partner account (login.php treats
 * 1 and 2 as "goes to /index"). Adjust PARTNER_USER_TYPE below if your
 * schema uses a different value.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

define('OTP_DEBUG', true);
define('PARTNER_USER_TYPE', 2);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json');
        }
        $payload = ['success' => false, 'message' => 'Could not create your account. Please try again.'];
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
    $password = (string) ($_POST['password'] ?? '');

    if ($fullName === '' || $email === '' || $gender === '' || $city === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Please fill in all the fields.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Enter a valid email address.']);
        exit;
    }
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
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

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $partnerType  = PARTNER_USER_TYPE;

    if ($existing) {
        $stmt = $conn->prepare("UPDATE users SET fname = ?, lname = ?, gender = ?, email = ?, password = ?, city = ?, user_type = ?, status = 1, updated_at = NOW() WHERE id = ?");
        if ($stmt === false) { throw new Exception('DB prepare failed (update): ' . $conn->error); }
        $stmt->bind_param('ssssssii', $fname, $lname, $gender, $email, $passwordHash, $city, $partnerType, $existing['id']);
        $ok = $stmt->execute();
        $stmt->close();
        $userId = $existing['id'];
    } else {
        $stmt = $conn->prepare("INSERT INTO users (fname, lname, gender, email, password, contactno, city, status, user_type, posting_date, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())");
        if ($stmt === false) { throw new Exception('DB prepare failed (insert): ' . $conn->error); }
        $stmt->bind_param('sssssssi', $fname, $lname, $gender, $email, $passwordHash, $phone, $city, $partnerType);
        $ok = $stmt->execute();
        $userId = $stmt->insert_id;
        $stmt->close();
    }

    if (!$ok) {
        throw new Exception('Save failed: ' . $conn->error);
    }

    // Log the new partner into the panel — these are the session keys
    // login.php reads, distinct from the hs_user_* keys the main-site
    // OTP flow uses.
    $_SESSION['user_id']    = $userId;
    $_SESSION['user_fname'] = $fname;
    $_SESSION['user_lname'] = $lname;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_type']  = $partnerType;
    unset($_SESSION['otp_verified_phone']);

    echo json_encode([
        'success'  => true,
        'redirect' => '/index',
        'user'     => ['id' => $userId, 'name' => $fullName, 'email' => $email],
    ]);

} catch (Exception $e) {
    error_log('complete_partner_profile.php error: ' . $e->getMessage());
    $payload = ['success' => false, 'message' => 'Could not create your account. Please try again.'];
    if (defined('OTP_DEBUG') && OTP_DEBUG) {
        $payload['debug'] = $e->getMessage();
    }
    echo json_encode($payload);
    exit;
}