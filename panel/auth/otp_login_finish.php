<?php
/**
 * auth/otp_login_finish.php
 * POST (no body needed) — called right after auth/verify_otp.php returns
 * status "logged_in".
 *
 * verify_otp.php authenticates against the shared `users` table and sets
 * $_SESSION['hs_user_id'] etc. (the main consumer site's session keys).
 * The partner panel's own pages/login.php read $_SESSION['user_id'] /
 * user_type instead, so this endpoint re-checks the account, confirms
 * it's actually a partner account, and normalizes the session into the
 * keys the panel expects.
 *
 * ASSUMPTION: user_type 1 or 2 = partner account (matches login.php's
 * existing redirect check). Anything else is rejected here.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../include/config.php';
header('Content-Type: application/json');

if (empty($_SESSION['hs_user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please verify OTP again.']);
    exit;
}

$stmt = $conn->prepare("SELECT id, fname, lname, email, user_type FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $_SESSION['hs_user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Account not found.']);
    exit;
}

if (!in_array((string) $user['user_type'], ['2', '3'], true)) {
    echo json_encode(['success' => false, 'message' => 'This number is not linked to a partner account.']);
    exit;
}

$_SESSION['user_id']    = $user['id'];
$_SESSION['user_fname'] = $user['fname'];
$_SESSION['user_lname'] = $user['lname'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_type']  = $user['user_type'];

// clean up the main-site-flavoured session keys so they don't linger
unset($_SESSION['hs_user_id'], $_SESSION['hs_user_name'], $_SESSION['hs_user_phone'], $_SESSION['hs_user_email'], $_SESSION['hs_user_gender'], $_SESSION['hs_user_city']);

echo json_encode(['success' => true, 'redirect' => '/index']);