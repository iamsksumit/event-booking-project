<?php
/**
 * include/sidebar.php
 * Requires: $conn (mysqli), $role ('admin'|'partner'), $userId, $activePage (string)
 * $activePage is set by the calling page, e.g. $activePage = 'events-list';
 * used only to add the "active" class - safe to leave unset (nothing highlights).
 */
$activePage = $activePage ?? '';

if ($role === 'admin') {
    // Pending event approvals across the platform
    $pendingApprovals = (int) ($conn->query("SELECT COUNT(*) c FROM events WHERE status = 4")->fetch_assoc()['c'] ?? 0);
    // Open support tickets
    $openTickets = (int) ($conn->query("SELECT COUNT(*) c FROM support_tickets WHERE status IN ('open','pending')")->fetch_assoc()['c'] ?? 0);

    $initials = strtoupper(substr($_SESSION['user_fname'] ?? 'A', 0, 1) . substr($_SESSION['user_lname'] ?? 'D', 0, 1));
    $name = trim(($_SESSION['user_fname'] ?? '') . ' ' . ($_SESSION['user_lname'] ?? '')) ?: 'Admin';
?>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<aside class="sidebar" id="sidebar">
  <button type="button" class="sidebar-close" id="sidebarClose" aria-label="Close menu">
    <i class="fa-solid fa-xmark"></i>
  </button>

  <a href="/index" class="sidebar-brand">
    <img src="<?php echo htmlspecialchars($website_logo ?? ''); ?>" alt="Highspinn" onerror="this.style.display='none'">
    <span> <span class="grad-text">Admin</span></span>
  </a>

  <div class="sidebar-scroll">
    <ul class="nav flex-column" style="list-style:none;padding:0;margin:0;">

      <li class="nav-item single <?php echo $activePage === 'dashboard' ? 'active' : ''; ?>">
        <a class="nav-link-item" href="/index"><i class="fa-solid fa-house ic"></i>Dashboard</a>
      </li>

      <div class="nav-label">Operations</div>

      <li class="nav-item<?php echo in_array($activePage, ['events-list','events-past','events-approval']) ? ' open active' : ''; ?>" data-group>
        <div class="nav-link-item" data-toggle><i class="fa-solid fa-calendar-days ic"></i>Events<i class="fa-solid fa-chevron-right chev"></i></div>
        <ul class="submenu">
          <li><a href="/events/" class="<?php echo $activePage === 'events-list' ? 'active' : ''; ?>">Event List</a></li>
          <li><a href="/events/past-event" class="<?php echo $activePage === 'events-past' ? 'active' : ''; ?>">Past Events</a></li>
          <li><a href="/events/event-approval" class="<?php echo $activePage === 'events-approval' ? 'active' : ''; ?>">Event Approval <?php if ($pendingApprovals > 0): ?><span class="badge-count"><?php echo $pendingApprovals; ?></span><?php endif; ?></a></li>
        </ul>
      </li>

      <li class="nav-item" data-group>
        <div class="nav-link-item" data-toggle><i class="fa-solid fa-tags ic"></i>Category<i class="fa-solid fa-chevron-right chev"></i></div>
        <ul class="submenu">
          <li><a href="/category">Categories</a></li>
        </ul>
      </li>

      <li class="nav-item" data-group>
        <div class="nav-link-item" data-toggle><i class="fa-solid fa-location-dot ic"></i>Event Venues<i class="fa-solid fa-chevron-right chev"></i></div>
        <ul class="submenu">
          <li><a href="/place">Venues</a></li>
        </ul>
      </li>

      <li class="nav-item" data-group>
        <div class="nav-link-item" data-toggle><i class="fa-solid fa-music ic"></i>Event Artist<i class="fa-solid fa-chevron-right chev"></i></div>
        <ul class="submenu">
          <li><a href="/artist">Artists</a></li>
        </ul>
      </li>

      <li class="nav-item" data-group>
        <div class="nav-link-item" data-toggle><i class="fa-solid fa-bullhorn ic"></i>Event Promotion<i class="fa-solid fa-chevron-right chev"></i></div>
        <ul class="submenu">
          <li><a href="/promotion?action=add">Add Promotion Plan</a></li>
          <li><a href="/promotion">Promotion Plan List</a></li>
        </ul>
      </li>

      <li class="nav-item" data-group>
        <div class="nav-link-item" data-toggle><i class="fa-solid fa-money-bill-wave ic"></i>Payouts<i class="fa-solid fa-chevron-right chev"></i></div>
        <ul class="submenu">
          <li><a href="/payouts">Payout History</a></li>
          <li><a href="/payouts/settings">Payout Settings</a></li>
        </ul>
      </li>

      <div class="nav-label">Insights &amp; People</div>

      <li class="nav-item single">
        <a class="nav-link-item" href="/analytics"><i class="fa-solid fa-chart-line ic"></i>Analytics</a>
      </li>
      <li class="nav-item single">
        <a class="nav-link-item" href="/users/"><i class="fa-solid fa-users ic"></i>Registered Users</a>
      </li>
      <li class="nav-item single">
        <a class="nav-link-item" href="/partner/"><i class="fa-solid fa-handshake ic"></i>Event Partners</a>
      </li>
      <li class="nav-item single">
        <a class="nav-link-item" href="/support"><i class="fa-solid fa-headset ic"></i>Support Tickets <?php if ($openTickets > 0): ?><span class="badge-count"><?php echo $openTickets; ?></span><?php endif; ?></a>
      </li>

      <div class="nav-label">System</div>

      <li class="nav-item single">
        <a class="nav-link-item" href="/settings"><i class="fa-solid fa-gear ic"></i>Website Settings</a>
      </li>

      <li class="nav-item single">
        <a class="nav-link-item" href="/logout.php" style="color:var(--pink);"><i class="fa-solid fa-right-from-bracket ic" style="color:var(--pink);"></i>Logout</a>
      </li>
    </ul>
  </div>

  <div class="sidebar-user">
    <div class="avatar-sm"><?php echo htmlspecialchars($initials); ?></div>
    <div>
      <div class="u-name"><?php echo htmlspecialchars($name); ?></div>
      <div class="u-role">Admin</div>
    </div>
  </div>
</aside>
<?php
} else {
    // ===================== PARTNER =====================
    $bookingsCount = (int) ($conn->query("
        SELECT COUNT(*) c FROM payments
        INNER JOIN events ON payments.event_id = events.url
        WHERE events.user_id = '$userId'
    ")->fetch_assoc()['c'] ?? 0);

    $openTickets = (int) ($conn->query("
        SELECT COUNT(*) c FROM support_tickets WHERE user_id = $userId AND status IN ('open','pending')
    ")->fetch_assoc()['c'] ?? 0);

    $userRow = $conn->query("SELECT fname, lname, status FROM partner WHERE id = $userId")->fetch_assoc();
    $name = trim(($userRow['fname'] ?? '') . ' ' . ($userRow['lname'] ?? '')) ?: 'Partner';
    $initials = strtoupper(substr($userRow['fname'] ?? 'P', 0, 1) . substr($userRow['lname'] ?? 'T', 0, 1));
    $kycVerified = (int) ($userRow['status'] ?? 0) === 1;
?>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<aside class="sidebar" id="sidebar">
  <button type="button" class="sidebar-close" id="sidebarClose" aria-label="Close menu">
    <i class="fa-solid fa-xmark"></i>
  </button>

  <a href="/index" class="sidebar-brand">
    <img src="<?php echo htmlspecialchars($website_logo ?? ''); ?>" alt="Highspinn" onerror="this.style.display='none'">
    <span> <span class="grad-text">Partner</span></span>
  </a>

  <div class="sidebar-scroll">
    <ul class="nav flex-column" style="list-style:none;padding:0;margin:0;">

      <li class="nav-item single <?php echo $activePage === 'dashboard' ? 'active' : ''; ?>">
        <a class="nav-link-item" href="/index"><i class="fa-solid fa-house ic"></i>Dashboard</a>
      </li>

      <div class="nav-label">Manage</div>

      <li class="nav-item<?php echo in_array($activePage, ['events-list','events-past']) ? ' open active' : ''; ?>" data-group>
        <div class="nav-link-item" data-toggle><i class="fa-solid fa-calendar-days ic"></i>My Events<i class="fa-solid fa-chevron-right chev"></i></div>
        <ul class="submenu">
          <li><a href="#" class="js-add-event">Add Event</a></li>
          <li><a href="/events/">Event List</a></li>
          <li><a href="/events/past-event">Past Events</a></li>
        </ul>
      </li>

      <li class="nav-item single">
        <a class="nav-link-item js-scan-ticket" href="#"><i class="fa-solid fa-qrcode ic"></i>Scan Tickets <span class="badge-count" style="background:var(--green);">Live</span></a>
      </li>

      <li class="nav-item" data-group>
        <div class="nav-link-item" data-toggle><i class="fa-solid fa-bullhorn ic"></i>Promotions<i class="fa-solid fa-chevron-right chev"></i></div>
        <ul class="submenu">
          <li><a href="/promotion">Buy Promotion Plan</a></li>
          <li><a href="/promotion/mine">My Promotions</a></li>
        </ul>
      </li>

      <div class="nav-label">Finance &amp; Insights</div>

      <li class="nav-item" data-group>
        <div class="nav-link-item" data-toggle><i class="fa-solid fa-money-bill-wave ic"></i>Payouts<i class="fa-solid fa-chevron-right chev"></i></div>
        <ul class="submenu">
          <li><a href="/payouts">Payout History</a></li>
          <li><a href="/payouts/settings">Bank &amp; UPI Details</a></li>
        </ul>
      </li>
      <li class="nav-item single">
        <a class="nav-link-item" href="/analytics"><i class="fa-solid fa-chart-line ic"></i>Analytics</a>
      </li>
      <li class="nav-item single">
        <a class="nav-link-item" href="/support"><i class="fa-solid fa-headset ic"></i>Support Tickets <?php if ($openTickets > 0): ?><span class="badge-count"><?php echo $openTickets; ?></span><?php endif; ?></a>
      </li>

      <div class="nav-label">Account</div>

      <li class="nav-item single">
        <a class="nav-link-item" href="/profile"><i class="fa-solid fa-building ic"></i>Business Profile</a>
      </li>
      <li class="nav-item single">
        <a class="nav-link-item" href="/profile/kyc"><i class="fa-solid fa-id-card ic"></i>KYC &amp; Verification
          <span class="badge-count" style="background:<?php echo $kycVerified ? 'var(--green)' : 'var(--yellow)'; ?>;<?php echo $kycVerified ? '' : 'color:#1a1a1f;'; ?>"><?php echo $kycVerified ? 'Verified' : 'Pending'; ?></span>
        </a>
      </li>

      <li class="nav-item single">
        <a class="nav-link-item" href="/logout.php" style="color:var(--pink);"><i class="fa-solid fa-right-from-bracket ic" style="color:var(--pink);"></i>Logout</a>
      </li>
    </ul>
  </div>

  <div class="sidebar-user">
    <div class="avatar-sm"><?php echo htmlspecialchars($initials); ?></div>
    <div>
      <div class="u-name"><?php echo htmlspecialchars($name); ?></div>
      <div class="u-role"><?php echo $kycVerified ? 'Verified Partner' : 'Partner'; ?></div>
    </div>
  </div>
</aside>
<?php
}
?>

<style>
.sidebar-close {
  display: none;
}

@media (max-width: 768px) {
  .sidebar {
    transition: transform 0.25s ease;
  }

  .sidebar-close {
    display: flex;
    align-items: center;
    justify-content: center;
    position: absolute;
    top: 12px;
    right: 12px;
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 50%;
    background: rgba(255,255,255,0.08);
    color: inherit;
    font-size: 16px;
    cursor: pointer;
    z-index: 10;
  }
  .sidebar-close:hover {
    background: rgba(255,255,255,0.15);
  }

  .sidebar-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1049; /* one below the sidebar's own z-index */
    opacity: 0;
    transition: opacity 0.25s ease;
  }
  .sidebar-backdrop.show {
    display: block;
    opacity: 1;
  }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const sidebar = document.getElementById('sidebar');
  const closeBtn = document.getElementById('sidebarClose');
  const backdrop = document.getElementById('sidebarBackdrop');

  if (!sidebar) return;

  // --- Adjust these two lines to match whatever class your existing
  //     hamburger/open button already toggles on #sidebar ---
  const OPEN_CLASSES = ['show', 'open'];

  function isOpen() {
    return OPEN_CLASSES.some(c => sidebar.classList.contains(c));
  }

  function closeSidebar() {
    OPEN_CLASSES.forEach(c => sidebar.classList.remove(c));
    if (backdrop) backdrop.classList.remove('show');
    sidebar.style.transform = '';
  }

  if (closeBtn) {
    closeBtn.addEventListener('click', closeSidebar);
  }
  if (backdrop) {
    backdrop.addEventListener('click', closeSidebar);
  }

  // --- Swipe to close (swipe left anywhere on the open sidebar) ---
  let touchStartX = 0;
  let touchCurrentX = 0;
  let dragging = false;

  sidebar.addEventListener('touchstart', function (e) {
    if (!isOpen()) return;
    touchStartX = e.touches[0].clientX;
    touchCurrentX = touchStartX;
    dragging = true;
    sidebar.style.transition = 'none';
  }, { passive: true });

  sidebar.addEventListener('touchmove', function (e) {
    if (!dragging) return;
    touchCurrentX = e.touches[0].clientX;
    const deltaX = Math.min(0, touchCurrentX - touchStartX); // only allow left drag
    sidebar.style.transform = `translateX(${deltaX}px)`;
  }, { passive: true });

  sidebar.addEventListener('touchend', function () {
    if (!dragging) return;
    dragging = false;
    sidebar.style.transition = '';
    const deltaX = touchCurrentX - touchStartX;

    if (deltaX < -60) {
      closeSidebar();
    } else {
      sidebar.style.transform = ''; // snap back
    }

    touchStartX = 0;
    touchCurrentX = 0;
  });
});
</script>