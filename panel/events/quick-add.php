<?php
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'error' => 'Method not allowed - the server received a '
            . $_SERVER['REQUEST_METHOD']
            . ' request instead of POST. The browser did send POST, so something '
            . 'between it and this script is redirecting the request (a common '
            . 'cause is an http\u2192https or www/non-www rule in .htaccess: '
            . '301/302 redirects silently turn a POST into a GET). Check your '
            . '.htaccess for rules matching /events/quick-add.php.',
    ]);
    exit();
}

/**
 * Turn a string into a lowercase, URL-safe slug and make sure it doesn't
 * already exist in $table.$column. If it does, "-2", "-3", ... is appended
 * until a free one is found.
 */
function unique_slug(mysqli $conn, string $table, string $column, string $text): string
{
    $base = strtolower(trim($text));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'item';
    }

    $slug = $base;
    $i = 2;
    while (true) {
        $stmt = $conn->prepare("SELECT id FROM `$table` WHERE `$column` = ? LIMIT 1");
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        if (!$exists) {
            return $slug;
        }
        $slug = $base . '-' . $i;
        $i++;
    }
}

/**
 * Handles an optional single-file image upload the same way place/index.php
 * does: saves it under /images/<subdir>/ with a collision-proof filename and
 * returns the public https:// URL, or '' if no (valid) file was sent.
 */
function handle_quickadd_image(string $fileField, string $subdir): string
{
    if (empty($_FILES[$fileField]) || $_FILES[$fileField]['error'] !== UPLOAD_ERR_OK || $_FILES[$fileField]['name'] === '') {
        return '';
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($_FILES[$fileField]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return '';
    }

    $targetDir = __DIR__ . "/../images/$subdir/";
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
    }

    $safeName   = uniqid($subdir . '_') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES[$fileField]['name']));
    $targetFile = $targetDir . $safeName;

    if (!move_uploaded_file($_FILES[$fileField]['tmp_name'], $targetFile)) {
        return '';
    }

    $baseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    return $baseUrl . "/images/$subdir/" . $safeName;
}

$type = $_POST['type'] ?? '';
$name = isset($_POST['name']) ? trim($_POST['name']) : '';

if ($name === '') {
    echo json_encode(['error' => 'Name is required.']);
    exit();
}

try {
    if ($type === 'place') {
        $address     = isset($_POST['address']) ? trim($_POST['address']) : '';
        $city        = isset($_POST['city']) ? trim($_POST['city']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $imageUrl    = handle_quickadd_image('image', 'place');
        $url         = unique_slug($conn, 'place', 'url', $name);

        // status = 0: quick-added venues need approval before they show up
        // live, same as events do.
        $stmt = $conn->prepare("
            INSERT INTO place (user_id, name, types, status, image_url, address, city, description, url, created_at)
            VALUES (?, ?, '', 0, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param('issssss', $userId, $name, $imageUrl, $address, $city, $description, $url);
        $stmt->execute();
        echo json_encode(['id' => $stmt->insert_id, 'text' => $name]);

    } elseif ($type === 'category') {
        $imageUrl = handle_quickadd_image('image', 'category');
        $catSlug  = unique_slug($conn, 'category', 'cat_slug', $name);

        $stmt = $conn->prepare("
            INSERT INTO category (user_id, cat_name, cat_slug, image, status)
            VALUES (?, ?, ?, ?, 0)");
        $stmt->bind_param('isss', $userId, $name, $catSlug, $imageUrl);
        $stmt->execute();
        echo json_encode(['id' => $stmt->insert_id, 'text' => $name]);

    } elseif ($type === 'artist') {
        $city        = isset($_POST['city']) ? trim($_POST['city']) : '';
        $genre       = isset($_POST['genre']) ? trim($_POST['genre']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $insta       = isset($_POST['insta']) ? trim($_POST['insta']) : '';
        $fb          = isset($_POST['fb']) ? trim($_POST['fb']) : '';
        $imageUrl    = handle_quickadd_image('image', 'artist');
        $url         = unique_slug($conn, 'artist', 'url', $name);

        $stmt = $conn->prepare("
            INSERT INTO artist (user_id, name, city, image_url, genre, description, url, fb, insta, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())");
        $stmt->bind_param('issssssss', $userId, $name, $city, $imageUrl, $genre, $description, $url, $fb, $insta);
        $stmt->execute();
        echo json_encode(['id' => $stmt->insert_id, 'text' => $name]);

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown type.']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save. It may already exist.']);
}