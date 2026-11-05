<?php
// Guest-details prefill. header.php has already started the session and
// set $isLoggedIn.
$sessName   = $_SESSION['hs_user_name']   ?? '';
$sessPhone  = $_SESSION['hs_user_phone']  ?? '';
$sessEmail  = $_SESSION['hs_user_email']  ?? '';
$sessGender = $_SESSION['hs_user_gender'] ?? '';

if ($isLoggedIn && ($sessEmail === '' || $sessGender === '')) {
    $uidStmt = $conn->prepare("SELECT email, gender FROM users WHERE id = ? LIMIT 1");
    if ($uidStmt) {
        $uid = (int) $_SESSION['hs_user_id'];
        $uidStmt->bind_param('i', $uid);
        $uidStmt->execute();
        $uRow = $uidStmt->get_result()->fetch_assoc();
        $uidStmt->close();
        if ($uRow) {
            $sessEmail  = $sessEmail  !== '' ? $sessEmail  : ($uRow['email']  ?? '');
            $sessGender = $sessGender !== '' ? $sessGender : ($uRow['gender'] ?? '');
            $_SESSION['hs_user_email']  = $sessEmail;
            $_SESSION['hs_user_gender'] = $sessGender;
        }
    }
}
