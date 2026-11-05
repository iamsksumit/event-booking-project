<?php
/**
 * profile/kyc-document.php
 * Streams back one of the current partner's own KYC documents
 * (govt_id_document / pan_document / business_proof_document).
 *
 * This is the partner-facing counterpart to admin's kyc-document.php --
 * that one re-checks admin auth on every request; this one re-checks that
 * the requester is a logged-in partner asking for THEIR OWN record on
 * every request. Neither file trusts a path passed in from the client:
 * both look the stored path up in `partner_kyc` by user_id and serve only
 * that, so there's no way to walk into another partner's folder.
 *
 * Requires include/session_guard.php (sets $role, $userId) and
 * include/config.php (sets up $conn). Partner-only.
 */
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';          // sets up $conn (mysqli)

if ($role !== 'partner') {
    http_response_code(403);
    exit('Forbidden');
}

$allowedFields = ['govt_id_document', 'pan_document', 'business_proof_document'];
$field = $_GET['field'] ?? '';
if (!in_array($field, $allowedFields, true)) {
    http_response_code(400);
    exit('Invalid document field.');
}

$stmt = $conn->prepare("SELECT $field AS doc_path FROM partner_kyc WHERE user_id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row || empty($row['doc_path'])) {
    http_response_code(404);
    exit('Document not found.');
}

// Stored as "{user_id}/{filename}" -- the leading segment must match the
// logged-in partner, which it always will since we looked it up by their
// own user_id above; this is just belt-and-braces against a stale/edited row.
$storedPath = $row['doc_path'];
if (strpos($storedPath, $userId . '/') !== 0) {
    http_response_code(403);
    exit('Forbidden');
}

$fullPath = realpath(__DIR__ . '/../storage/kyc/' . $storedPath);
$baseDir  = realpath(__DIR__ . '/../storage/kyc/');
if ($fullPath === false || $baseDir === false || strpos($fullPath, $baseDir) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    exit('Document not found.');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($fullPath);
exit();
