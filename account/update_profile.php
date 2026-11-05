<?php
/**
 * account/update_profile.php
 * POST: name, email, gender, city
 *
 * Session-based profile edit for an already signed-in account (used by
 * the "Edit profile" modal on account.php). Unlike auth/complete_profile.php,
 * this never accepts a phone number or user id from the client — the
 * account being edited is always $_SESSION['hs_user_id'], and the phone
 * number itself is never changed here (that's a re-verification flow,
 * not a plain edit).
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {

    if (empty($_SESSION['hs_user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Please sign in to continue.']);
        exit;
    }
    $userId = (int) $_SESSION['hs_user_id'];

    $fullName = trim($_POST['name']   ?? '');
    $email    = trim($_POST['email']  ?? '');
    $gender   = trim($_POST['gender'] ?? '');
    $city     = trim($_POST['city']   ?? '');

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

    // "Full name" in the UI maps to users.fname + users.lname, same split
    // rule as auth/complete_profile.php: first word is fname, the rest
    // (if any) is lname.
    $nameParts = preg_split('/\s+/', $fullName, 2);
    $fname = $nameParts[0];
    $lname = $nameParts[1] ?? '';

    if ($fname === '') {
        echo json_encode(['success' => false, 'message' => 'Enter your full name.']);
        exit;
    }

    // Email must be unique across OTHER accounts (excluded by id here,
    // since — unlike complete_profile.php — we don't have this user's
    // phone number in the POST body to exclude by).
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
    if ($stmt === false) { throw new Exception('DB prepare failed (email check): ' . $conn->error); }
    $stmt->bind_param('si', $email, $userId);
    $stmt->execute();
    $emailTaken = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($emailTaken) {
        echo json_encode(['success' => false, 'message' => 'This email is already linked to another account.']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE users SET fname = ?, lname = ?, gender = ?, email = ?, city = ?, updated_at = NOW() WHERE id = ?");
    if ($stmt === false) { throw new Exception('DB prepare failed (update): ' . $conn->error); }
    $stmt->bind_param('sssssi', $fname, $lname, $gender, $email, $city, $userId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        throw new Exception('Save failed: ' . $conn->error);
    }

    // Keep the session in sync so header.php's navbar name, and anywhere
    // else that reads these keys (e.g. the booking wizard's "myself"
    // prefill), reflect the change immediately without a fresh DB read.
    $_SESSION['hs_user_name']   = $fullName;
    $_SESSION['hs_user_email']  = $email;
    $_SESSION['hs_user_gender'] = $gender;
    $_SESSION['hs_user_city']   = $city;

    echo json_encode([
        'success' => true,
        'user' => [
            'id'     => $userId,
            'name'   => $fullName,
            'email'  => $email,
            'gender' => $gender,
            'city'   => $city,
        ],
    ]);

} catch (Exception $e) {
    error_log('update_profile.php error: ' . $e->getMessage());
    $payload = ['success' => false, 'message' => 'Could not save your details. Please try again.'];
    if (defined('OTP_DEBUG') && OTP_DEBUG) {
        $payload['debug'] = $e->getMessage();
    }
    echo json_encode($payload);
    exit;
}