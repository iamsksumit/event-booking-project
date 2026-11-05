<?php
/**
 * include/session_guard.php
 * Include this FIRST, before include/config.php, on every admin/partner page.
 *
 * Role numbering (post-migration, see sql/01_migrate_user_types.sql):
 *   0 = general user  -> sent to /profile
 *   2 = partner        -> $role = 'partner'
 *   3 = admin           -> $role = 'admin'
 */
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userType = (int) $_SESSION['user_type'];

if ($userType === 0) {
    header("Location: /profile");
    exit();
}

if ($userType === 2) {
    $role = 'partner';
} elseif ($userType === 3) {
    $role = 'admin';
} else {
    // Unknown type - don't silently let them into admin/partner views
    header("Location: /profile");
    exit();
}

$userId = (int) $_SESSION['user_id'];
