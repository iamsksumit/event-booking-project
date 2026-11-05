<?php
if (session_status() === PHP_SESSION_NONE) session_start();

unset(
    $_SESSION['hs_user_id'],
    $_SESSION['hs_user_name'],
    $_SESSION['hs_user_phone'],
    $_SESSION['otp_phone'],
    $_SESSION['otp_code'],
    $_SESSION['otp_expires'],
    $_SESSION['otp_attempts'],
    $_SESSION['otp_verified_phone'],
    $_SESSION['otp_last_sent']
);

$redirect = $_SERVER['HTTP_REFERER'] ?? '/';
header('Location: ' . $redirect);
exit;