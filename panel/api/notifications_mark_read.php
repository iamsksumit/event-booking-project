<?php
require_once __DIR__ . '/../include/session_guard.php';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/notify.php';

header('Content-Type: application/json');
mark_all_read($conn, $userId, $role);
echo json_encode(['success' => true]);
