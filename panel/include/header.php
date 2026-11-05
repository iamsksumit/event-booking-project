<?php
/**
 * include/header.php
 * Requires: $conn, $role, $userId, $pageTitle set by the calling page.
 * Emits <!DOCTYPE> ... through the open <div class="content">.
 * Pair with include/footer.php to close everything out.
 */
require_once __DIR__ . '/notify.php';

$logoRow = $conn->query("SELECT name, value FROM settings WHERE name IN ('logo','preloader')");
$website_logo = '';
$preloader_logo = '';
if ($logoRow) {
    while ($r = $logoRow->fetch_assoc()) {
        if ($r['name'] === 'logo') $website_logo = $r['value'];
        if ($r['name'] === 'preloader') $preloader_logo = $r['value'];
    }
}

$notifications = get_notifications($conn, $userId, $role, 8);
$unreadCount = get_unread_count($conn, $userId, $role);

if ($role === 'admin') {
    $sessRow = $conn->query("SELECT fname, lname FROM partner WHERE id = $userId")->fetch_assoc();
} else {
    $sessRow = $conn->query("SELECT fname, lname FROM partner WHERE id = $userId")->fetch_assoc();
}
$displayName = trim(($sessRow['fname'] ?? '') . ' ' . ($sessRow['lname'] ?? '')) ?: ucfirst($role);
$initials = strtoupper(substr($sessRow['fname'] ?? 'H', 0, 1) . substr($sessRow['lname'] ?? 'S', 0, 1));
?>
<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<title><?php echo htmlspecialchars($pageTitle ?? 'Dashboard'); ?> - Highspinn</title>

<link rel="shortcut icon" href="/img/favicon.ico" type="image/x-icon">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>


<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<?php include __DIR__ . '/style.php'; ?>
</head>
<body>

<?php include __DIR__ . '/sidebar.php'; ?>

<div class="main-wrap">
  <div class="topbar">
    <button class="burger-btn" id="burgerBtn"><i class="fa-solid fa-bars"></i></button>
    <div class="search-pill">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" placeholder="<?php echo $role === 'admin' ? 'Search events, partners, users, tickets…' : 'Search your events, bookings…'; ?>">
    </div>
    <div class="topbar-actions">
      <?php if ($role === 'partner'): ?>
        <button class="icon-btn js-scan-ticket" title="Scan Tickets"><i class="fa-solid fa-qrcode"></i></button>
        <a href="#" class="btn-pink-sm d-none d-md-inline-flex js-add-event"><i class="fa-solid fa-plus"></i> Add Event</a>
      <?php endif; ?>

      <div class="dropdown-wrap">
        <button class="icon-btn" id="notifBtn"><i class="fa-regular fa-bell"></i><?php if ($unreadCount > 0): ?><span class="dot-alert"></span><?php endif; ?></button>
        <div class="dropdown-panel" id="notifPanel">
          <div class="dp-head">
            <h6>Notifications</h6>
            <a href="#" id="markAllReadBtn">Mark all read</a>
          </div>
          <?php if (empty($notifications)): ?>
            <div class="dp-empty">You're all caught up.</div>
          <?php else: ?>
            <?php foreach ($notifications as $n): ?>
              <a href="<?php echo htmlspecialchars($n['link'] ?: '#'); ?>" style="display:block;">
                <div class="notif-item <?php echo $n['is_read'] ? 'read' : ''; ?>">
                  <div class="n-dot"></div>
                  <div>
                    <div class="n-title"><?php echo htmlspecialchars($n['title']); ?></div>
                    <div class="n-msg"><?php echo htmlspecialchars($n['message']); ?></div>
                    <div class="n-time"><?php echo time_ago($n['created_at']); ?></div>
                  </div>
                </div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="dropdown-wrap">
        <div class="topbar-user" id="userMenuBtn" style="cursor:pointer;">
          <div class="avatar-sm"><?php echo htmlspecialchars($initials); ?></div>
          <div class="d-none d-sm-block">
            <div class="u-name"><?php echo htmlspecialchars($displayName); ?></div>
            <div class="u-role"><?php echo $role === 'admin' ? 'Admin' : 'Partner'; ?></div>
          </div>
          <i class="fa-solid fa-chevron-down" style="font-size:.65rem;color:var(--muted-2);"></i>
        </div>
        <div class="dropdown-panel user-menu" id="userMenuPanel">
          <a href="/profile"><i class="fa-solid fa-user"></i> Profile</a>
          <a href="/settings"><i class="fa-solid fa-gear"></i> Settings</a>
          <hr>
          <a href="/logout.php" class="danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
      </div>
    </div>
  </div>

  <div class="content">
