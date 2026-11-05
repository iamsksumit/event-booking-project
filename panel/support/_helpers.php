<?php
/**
 * support/_helpers.php
 * Shared between index.php and view.php. Not routable on its own --
 * include it, don't link to it.
 */

function ticketStatusBadge($status) {
    switch ($status) {
        case 'open':     return ['Open', 'badge-warning'];
        case 'pending':  return ['Pending', 'badge-info'];
        case 'resolved': return ['Resolved', 'badge-success'];
        case 'closed':   return ['Closed', 'badge-secondary'];
        default:         return [ucfirst($status), 'badge-secondary'];
    }
}

function ticketPriorityBadge($priority) {
    switch ($priority) {
        case 'high': return ['High', 'badge-danger'];
        case 'low':  return ['Low', 'badge-secondary'];
        default:     return ['Normal', 'badge-warning'];
    }
}

/* Very small allow-list sanitizer for the WYSIWYG HTML the editor submits.
   This is NOT a substitute for a real HTML sanitizer (e.g. HTML Purifier)
   in a production app -- swap this out if you have one available. It
   strips everything except a safe formatting subset and drops any
   on*=, javascript:, or style attributes. */
function sanitizeTicketHtml(string $html): string {
    $allowed = '<p><br><b><strong><i><em><u><ul><ol><li><a><blockquote><h1><h2><h3><span>';
    $html    = strip_tags($html, $allowed);
    $html    = preg_replace('/\son\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html);
    $html    = preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1="#"', $html);
    $html    = preg_replace('/\sstyle\s*=\s*(".*?"|\'.*?\')/i', '', $html);
    return $html;
}

/* Shared upload handler for both the create form and the reply form.
   Returns the stored relative/public path, or null if no file was
   submitted. Throws RuntimeException with a user-facing message on
   validation failure. */
const UPLOAD_DIR_FS      = __DIR__ . '/../uploads/support/';   // ADAPT THIS to your real uploads path
const UPLOAD_DIR_URL     = '/uploads/support/';                 // ADAPT THIS to match the FS path above
const UPLOAD_MAX_BYTES   = 8 * 1024 * 1024; // 8MB
const UPLOAD_ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip'];

function handleTicketUpload(string $fieldName): ?string {
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The attachment failed to upload. Please try again.');
    }
    if ($file['size'] > UPLOAD_MAX_BYTES) {
        throw new RuntimeException('Attachment is too large (8MB max).');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, UPLOAD_ALLOWED_EXT, true)) {
        throw new RuntimeException('That file type isn\'t allowed. Allowed: ' . implode(', ', UPLOAD_ALLOWED_EXT) . '.');
    }
    if (!is_dir(UPLOAD_DIR_FS)) {
        mkdir(UPLOAD_DIR_FS, 0755, true);
    }
    $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $filename = date('Ymd_His') . '_' . uniqid() . '_' . $safeName . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR_FS . $filename)) {
        throw new RuntimeException('Could not save the attachment. Please try again.');
    }
    return UPLOAD_DIR_URL . $filename;
}
