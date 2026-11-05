<?php
/**
 * account.php — ONE FILE for the My Account page.
 * Contains: device detection, profile + bookings data, the mobile layout,
 * the desktop layout, and the shared Edit Profile modal + scripts.
 *
 * Device priority:
 *   1. ?view=mobile | ?view=desktop   (manual override, remembered in a cookie)
 *   2. Cookie from a previous override
 *   3. X-HighSpinn-App header          (native app webview => mobile)
 *   4. User-Agent sniffing             (phones => mobile, everything else => desktop)
 */

if (!function_exists('hs_is_mobile_request')) {
    function hs_is_mobile_request(): bool {
        if (isset($_GET['view']) && in_array($_GET['view'], ['mobile', 'desktop'], true)) {
            setcookie('hs_view', $_GET['view'], time() + 30 * 86400, '/');
            return $_GET['view'] === 'mobile';
        }
        if (isset($_COOKIE['hs_view']) && in_array($_COOKIE['hs_view'], ['mobile', 'desktop'], true)) {
            return $_COOKIE['hs_view'] === 'mobile';
        }
        if (($_SERVER['HTTP_X_HIGHSPINN_APP'] ?? '') === 'true') {
            return true;
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua)) return false;
        if (preg_match('/Android.*Mobile|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini|IEMobile|Mobi/i', $ua)) return true;
        return false;
    }
}

// Same URL returns different HTML, so tell caches/CDNs
header('Vary: User-Agent, Cookie, X-HighSpinn-App');
$isMobile = hs_is_mobile_request();

include('include/config.php');

$title = 'My Account - HIGHSPINN';
$description = 'Manage your HighSpinn account and view your event bookings.';
$image = 'https://www.highspinn.com/img/logo.png';
$url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

include 'include/header.php';

// header.php has already started the session and set $isLoggedIn
// (and $allCities, used by the Edit Profile modal).

if (!function_exists('columnExists')) {
    function columnExists($conn, $table, $column) {
        $table = mysqli_real_escape_string($conn, $table);
        $column = mysqli_real_escape_string($conn, $column);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $res && mysqli_num_rows($res) > 0;
    }
}

// Defaults used when nobody's signed in
$userName = $userEmail = $userPhone = $userImage = $userGender = $userCity = '';
$initials = 'U';
$bookings = [];
$totalBookings = 0;
$totalPages = 1;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

if ($isLoggedIn) {

    $userId = (int) $_SESSION['hs_user_id'];

    $userStmt = $conn->prepare("SELECT fname, lname, email, contactno, image_url, gender, city FROM users WHERE id = ? LIMIT 1");
    $userStmt->bind_param('i', $userId);
    $userStmt->execute();
    $userRow = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    if (!$userRow) {
        header('Location: ' . hs_city_url());
        exit();
    }

    $userName   = trim(($userRow['fname'] ?? '') . ' ' . ($userRow['lname'] ?? ''));
    $userEmail  = $userRow['email']     ?? '';
    $userPhone  = $userRow['contactno'] ?? '';
    $userImage  = $userRow['image_url'] ?? '';
    $userGender = $userRow['gender']    ?? '';
    $userCity   = $userRow['city']      ?? '';

    $initials = '';
    foreach (explode(' ', $userName) as $part) {
        if ($part !== '') $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) break;
    }
    if ($initials === '') $initials = 'U';

    // BOOKINGS — paginated, 5 per page
    $hasPaymentsUserIdCol = columnExists($conn, 'payments', 'user_id');

    $perPage = 5;

    if ($hasPaymentsUserIdCol) {
        $countStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM payments WHERE user_id = ?");
        $countStmt->bind_param('i', $userId);
        $countStmt->execute();
        $totalBookings = (int) ($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $countStmt->close();
    }

    $totalPages = max(1, (int) ceil($totalBookings / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    if ($hasPaymentsUserIdCol && $totalBookings > 0) {
        $bookingsStmt = $conn->prepare("
            SELECT
                payments.pay_ref    AS pay_ref,
                payments.price      AS price,
                payments.created_at AS created_at,
                events.tittle       AS event_title,
                events.image        AS event_image,
                events.url          AS event_url,
                events.start        AS event_start,
                place.name          AS placename,
                COALESCE(SUM(ticket_orders.quantity), 0) AS ticket_count
            FROM payments
            LEFT JOIN events ON payments.event_id = events.url
            LEFT JOIN place  ON events.sub_tittle = place.id
            LEFT JOIN ticket_orders ON ticket_orders.payment_ref = payments.pay_ref
            WHERE payments.user_id = ?
            GROUP BY payments.id
            ORDER BY payments.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $bookingsStmt->bind_param('iii', $userId, $perPage, $offset);
        $bookingsStmt->execute();
        $bookingsResult = $bookingsStmt->get_result();
        while ($b = $bookingsResult->fetch_assoc()) { $bookings[] = $b; }
        $bookingsStmt->close();
    } elseif (!$hasPaymentsUserIdCol) {
        error_log("account.php: payments.user_id column is missing; cannot list a user's bookings.");
    }
}

if (!function_exists('hsBuildPageUrl')) {
    function hsBuildPageUrl($p){
        $params = $_GET;
        $params['page'] = $p;
        return '?' . http_build_query($params);
    }
}

// Pre-format each booking once so both layouts can share it
foreach ($bookings as &$bk) {
    $bk['_order']  = $bk['pay_ref'] ?? '';
    $bk['_passes'] = (int) ($bk['ticket_count'] ?? 0);
    $bk['_url']    = $bk['_order'] ? ('/ticket/' . rawurlencode($bk['_order'])) : '#';
    $bk['_date']   = '';
    if (!empty($bk['event_start'])) {
        try {
            $d = new DateTime($bk['event_start']);
            $bk['_date'] = $d->format('j F Y') . ' &middot; ' . $d->format('g:i a');
        } catch (Exception $e) { $bk['_date'] = ''; }
    }
}
unset($bk);
?>

<?php if ($isMobile): ?>
<?php /* =====================================================================
   MOBILE LAYOUT
   ===================================================================== */ ?>

<style>
body.hs-app-view .navbar-hs,
body.hs-app-view footer { display:none !important; }
body.hs-app-view { padding-bottom:84px; }

.hs-app{ max-width:480px; margin:0 auto; }

/* ---- sticky header ---- */
.ac-header{
  position:sticky; top:0; z-index:40; padding:12px 16px;
  display:flex; align-items:center; gap:12px;
  background:rgba(10,10,13,.95); backdrop-filter:blur(14px); border-bottom:1px solid var(--line);
}
.ac-title{ font-size:18px; font-weight:800; flex:1; margin:0; }

.acct-menu-wrap{ position:relative; }
.acct-menu-btn{
  width:38px; height:38px; border-radius:50%; background:var(--panel-2); border:1px solid var(--line);
  color:var(--text); display:flex; align-items:center; justify-content:center; font-size:15px; cursor:pointer;
}
.acct-menu-dropdown{
  position:absolute; top:calc(100% + 8px); right:0; z-index:60;
  background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:6px;
  min-width:170px; display:none; box-shadow:0 18px 40px rgba(0,0,0,.5);
}
.acct-menu-dropdown.show{ display:block; }
.acct-menu-dropdown a{
  display:flex; align-items:center; gap:10px; color:var(--text); font-size:13.5px; font-weight:600;
  border-radius:8px; padding:11px 12px;
}
.acct-menu-dropdown a:active{ background:var(--panel-2); color:var(--pink); }

.ac-body{ padding:16px; }

/* ---- profile card ---- */
.profile-card{
  background:var(--panel); border:1px solid var(--line); border-radius:18px; padding:16px;
  display:flex; align-items:center; gap:14px; margin-bottom:16px;
}
.profile-avatar{
  width:64px; height:64px; border-radius:50%; flex:none; overflow:hidden;
  background:linear-gradient(135deg,var(--pink),var(--orange));
  display:flex; align-items:center; justify-content:center;
  font-size:22px; font-weight:800; color:#fff; border:2px solid var(--line);
}
.profile-avatar img{ width:100%; height:100%; object-fit:cover; }
.profile-info{ flex:1; min-width:0; }
.profile-info h3{ font-size:16px; font-weight:800; margin:0 0 5px; text-transform:uppercase; letter-spacing:.02em; word-break:break-word; }
.profile-info .p-line{ font-size:12px; color:var(--muted); display:flex; align-items:center; gap:7px; margin-bottom:3px; min-width:0; }
.profile-info .p-line i{ color:var(--muted-2); width:13px; text-align:center; flex:none; }
.profile-info .p-line span{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.profile-edit-btn{
  width:38px; height:38px; border-radius:50%; flex:none; display:flex; align-items:center; justify-content:center;
  background:var(--panel-2); border:1px solid var(--line); color:var(--text); font-size:13px;
}

/* ---- bookings ---- */
.sec-head{ display:flex; align-items:center; justify-content:space-between; margin:4px 0 12px; }
.sec-title{ font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--muted-2); margin:0; display:flex; align-items:center; gap:7px; }
.sec-title i{ color:var(--pink); }
.sec-count{ font-size:11px; font-weight:700; color:var(--muted-2); }

.bk-card{
  display:block; background:var(--panel); border:1px solid var(--line); border-radius:16px;
  padding:12px; margin-bottom:12px; color:var(--text); text-decoration:none;
}
.bk-card:active{ border-color:var(--pink); color:var(--text); }
.bk-main{ display:flex; gap:12px; }
.bk-thumb{
  width:76px; height:76px; border-radius:12px; flex:none; overflow:hidden;
  background:linear-gradient(150deg,#7a1240,#280a1c);
}
.bk-thumb img{ width:100%; height:100%; object-fit:cover; display:block; }
.bk-body{ flex:1; min-width:0; display:flex; flex-direction:column; justify-content:center; gap:3px; }
.bk-body h5{ font-size:14px; font-weight:800; line-height:1.25; margin:0; color:var(--text); }
.bk-order{ font-size:10.5px; font-weight:700; color:var(--muted-2); font-family:monospace; word-break:break-all; }
.bk-venue, .bk-date{ font-size:12px; color:var(--muted); }
.bk-foot{ display:flex; align-items:center; justify-content:space-between; margin-top:12px; padding-top:12px; border-top:1px dashed var(--line); }
.pass-count-chip{
  background:var(--panel-2); border:1px solid var(--line); color:var(--text);
  font-size:12px; font-weight:700; padding:6px 12px; border-radius:8px;
}
.status-chip{
  font-size:10.5px; font-weight:800; letter-spacing:.04em; text-transform:uppercase;
  padding:6px 12px; border-radius:100px; border:1px solid transparent;
  color:var(--green); background:rgba(61,220,132,.12); border-color:rgba(61,220,132,.3);
}
.view-link{ font-size:12px; font-weight:800; color:var(--pink); display:inline-flex; align-items:center; gap:5px; }

/* ---- empty / locked ---- */
.bookings-empty{ text-align:center; padding:36px 16px; background:var(--panel); border:1px solid var(--line); border-radius:16px; }
.bookings-empty i{ font-size:30px; color:var(--muted-2); margin-bottom:12px; }
.bookings-empty p{ color:var(--muted); font-size:13px; margin-bottom:16px; }
.bookings-empty .btn-pink{ padding:12px 22px; }

.locked-card{
  background:var(--panel); border:1px solid var(--line); border-radius:18px;
  padding:32px 20px; text-align:center; margin-top:24px;
}
.locked-icon{
  width:60px; height:60px; border-radius:50%; margin:0 auto 14px;
  background:rgba(255,61,104,.12); color:var(--pink);
  display:flex; align-items:center; justify-content:center; font-size:22px;
}
.locked-card h1{ font-size:18px; font-weight:800; margin-bottom:8px; }
.locked-card p{ color:var(--muted); font-size:13px; line-height:1.55; margin-bottom:20px; }
.locked-card .btn-pink{ width:100%; padding:13px; }

/* ---- pagination: compact prev / page x of y / next ---- */
.ac-pager{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:6px; }
.ac-pager a, .ac-pager span.pg-btn{
  flex:1; text-align:center; padding:11px; border-radius:12px; font-size:13px; font-weight:700;
  background:var(--panel-2); border:1px solid var(--line); color:var(--text);
}
.ac-pager span.pg-btn.disabled{ opacity:.35; }
.ac-pager .pg-info{ flex:none; font-size:12px; color:var(--muted-2); font-weight:700; }
</style>

<div class="hs-app">

  <div class="ac-header">
    <h1 class="ac-title">My Account</h1>
    <?php if ($isLoggedIn): ?>
    <div class="acct-menu-wrap">
      <button type="button" class="acct-menu-btn" id="acctMenuBtn" aria-haspopup="true" aria-expanded="false" aria-label="Account options">
        <i class="fa-solid fa-ellipsis"></i>
      </button>
      <div class="acct-menu-dropdown" id="acctMenuDropdown">
        <a href="#" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fa-solid fa-user-pen"></i> Edit profile</a>
        <a href="/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="ac-body">

  <?php if (!$isLoggedIn): ?>

    <!-- ============ NOT SIGNED IN ============ -->
    <div class="locked-card">
      <div class="locked-icon"><i class="fa-solid fa-lock"></i></div>
      <h1>Please sign in to view your account</h1>
      <p>Sign in with the mobile number you used to book with HighSpinn and your profile and bookings will show up right here.</p>
      <button type="button" class="btn-pink" data-bs-toggle="modal" data-bs-target="#authModal">Sign in</button>
    </div>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        var authModalEl = document.getElementById('authModal');
        if (authModalEl && window.bootstrap) {
          new bootstrap.Modal(authModalEl).show();
        }
      });
    </script>

  <?php else: ?>

    <div class="profile-card">
      <div class="profile-avatar">
        <?php if (!empty($userImage)): ?>
          <img src="<?= htmlspecialchars($userImage) ?>" alt="<?= htmlspecialchars($userName ?: 'Profile photo') ?>">
        <?php else: ?>
          <?= htmlspecialchars($initials) ?>
        <?php endif; ?>
      </div>
      <div class="profile-info">
        <h3><?= htmlspecialchars($userName ?: 'Member') ?></h3>
        <?php if ($userEmail): ?>
          <div class="p-line"><i class="fa-regular fa-envelope"></i><span><?= htmlspecialchars($userEmail) ?></span></div>
        <?php endif; ?>
        <?php if ($userPhone): ?>
          <div class="p-line"><i class="fa-solid fa-phone"></i><span>+91<?= htmlspecialchars($userPhone) ?></span></div>
        <?php endif; ?>
      </div>
      <a href="#" class="profile-edit-btn" data-bs-toggle="modal" data-bs-target="#editProfileModal" aria-label="Edit profile"><i class="fa-solid fa-pen"></i></a>
    </div>

    <div id="bookings">
      <div class="sec-head">
        <h6 class="sec-title"><i class="fa-solid fa-ticket"></i> My Bookings</h6>
        <?php if ($totalBookings > 0): ?><span class="sec-count"><?= (int)$totalBookings ?> total</span><?php endif; ?>
      </div>

      <?php if (empty($bookings)): ?>

        <div class="bookings-empty">
          <i class="fa-solid fa-champagne-glasses"></i>
          <p>You haven't booked any events yet.</p>
          <a href="<?= hs_city_url('events') ?>" class="btn-pink">Browse Events</a>
        </div>

      <?php else: ?>

        <?php foreach ($bookings as $bk): ?>
          <a class="bk-card" href="<?= htmlspecialchars($bk['_url']) ?>">
            <div class="bk-main">
              <div class="bk-thumb">
                <?php if (!empty($bk['event_image'])): ?>
                  <img src="<?= htmlspecialchars($bk['event_image']) ?>" alt="<?= htmlspecialchars($bk['event_title'] ?? '') ?>">
                <?php endif; ?>
              </div>
              <div class="bk-body">
                <h5><?= htmlspecialchars($bk['event_title'] ?? 'Event') ?></h5>
                <div class="bk-order">Order ID: <?= htmlspecialchars($bk['_order']) ?></div>
                <?php if (!empty($bk['placename'])): ?>
                  <div class="bk-venue"><i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars($bk['placename']) ?></div>
                <?php endif; ?>
                <?php if ($bk['_date']): ?>
                  <div class="bk-date"><i class="fa-regular fa-clock me-1"></i><?= $bk['_date'] ?></div>
                <?php endif; ?>
              </div>
            </div>
            <div class="bk-foot">
              <span class="pass-count-chip"><?= $bk['_passes'] ?> Pass<?= $bk['_passes'] === 1 ? '' : 'es' ?></span>
              <span class="status-chip">Confirmed</span>
              <span class="view-link">View <i class="fa-solid fa-arrow-right"></i></span>
            </div>
          </a>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
          <div class="ac-pager">
            <?php if ($page > 1): ?>
              <a href="<?= htmlspecialchars(hsBuildPageUrl($page - 1)) ?>"><i class="fa-solid fa-chevron-left me-1"></i> Prev</a>
            <?php else: ?>
              <span class="pg-btn disabled"><i class="fa-solid fa-chevron-left me-1"></i> Prev</span>
            <?php endif; ?>

            <span class="pg-info"><?= $page ?> / <?= $totalPages ?></span>

            <?php if ($page < $totalPages): ?>
              <a href="<?= htmlspecialchars(hsBuildPageUrl($page + 1)) ?>">Next <i class="fa-solid fa-chevron-right ms-1"></i></a>
            <?php else: ?>
              <span class="pg-btn disabled">Next <i class="fa-solid fa-chevron-right ms-1"></i></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      <?php endif; ?>
    </div>

  <?php endif; ?>

  </div>
</div>

<script>document.body.classList.add('hs-app-view');</script>

<?php else: ?>
<?php /* =====================================================================
   DESKTOP LAYOUT
   ===================================================================== */ ?>

<style>
.account-hero{ padding:7.5rem 0 5rem; }
.account-col{ max-width:900px; margin:0 auto; }

/* ---------- page head ---------- */
.acct-head{ display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:1.6rem; gap:1rem; }
.acct-head h1{ font-size:1.9rem; margin:0; }

.acct-menu-wrap{ position:relative; }
.acct-menu-btn{
  width:40px; height:40px; border-radius:50%; background:var(--panel-2); border:1px solid var(--line);
  color:var(--text); display:flex; align-items:center; justify-content:center; font-size:1rem; cursor:pointer;
}
.acct-menu-btn:hover{ border-color:var(--muted-2); }
.acct-menu-dropdown{
  position:absolute; top:calc(100% + .5rem); right:0; z-index:20;
  background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:.4rem;
  min-width:170px; display:none; box-shadow:0 18px 40px rgba(0,0,0,.4);
}
.acct-menu-dropdown.show{ display:block; }
.acct-menu-dropdown a{
  display:flex; align-items:center; gap:.6rem; color:var(--text); font-size:.85rem; font-weight:600;
  border-radius:8px; padding:.6rem .8rem;
}
.acct-menu-dropdown a:hover{ background:var(--panel-2); color:var(--pink); }
.acct-menu-dropdown a.danger:hover{ color:var(--pink); }

/* ---------- profile card ---------- */
.profile-card{
  display:flex; align-items:center; gap:1.4rem; margin-bottom:1.6rem; flex-wrap:wrap;
}
.profile-avatar{
  width:88px; height:88px; border-radius:50%; flex:none; overflow:hidden;
  background:linear-gradient(135deg,var(--pink),var(--orange));
  display:flex; align-items:center; justify-content:center;
  font-size:1.7rem; font-weight:800; color:#fff;
  border:2px solid var(--line);
}
.profile-avatar img{ width:100%; height:100%; object-fit:cover; }
.profile-info{ flex:1; min-width:200px; }
.profile-info h3{ font-size:1.35rem; font-weight:800; letter-spacing:.02em; margin:0 0 .35rem; text-transform:uppercase; }
.profile-info .p-line{ font-size:.9rem; color:var(--muted); display:flex; align-items:center; gap:.5rem; margin-bottom:.2rem; }
.profile-info .p-line i{ color:var(--muted-2); width:16px; text-align:center; }
.profile-edit-link{
  font-weight:700; font-size:.85rem; color:var(--muted); display:inline-flex; align-items:center; gap:.4rem;
  flex:none; margin-left:auto;
}
.profile-edit-link:hover{ color:var(--pink); }

/* ---------- bookings panel ---------- */
.bookings-panel{ background:var(--panel); border:1px solid var(--line); border-radius:18px; padding:1.5rem; }
.bookings-panel h6{
  font-size:.72rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase;
  color:var(--muted-2); margin-bottom:1.2rem;
}

.booking-row{
  display:block; border:1px solid var(--line); border-radius:14px; padding:1.1rem;
  margin-bottom:1rem; position:relative; color:inherit; text-decoration:none;
  transition:border-color .2s ease, transform .2s ease;
}
.booking-row:hover{ border-color:var(--muted-2); transform:translateY(-2px); color:inherit; }
.booking-row:last-child{ margin-bottom:0; }
.booking-main{ display:flex; gap:1rem; align-items:flex-start; }
.booking-thumb{
  width:96px; height:96px; border-radius:12px; flex:none; overflow:hidden;
  background:linear-gradient(150deg,#7a1240,#280a1c); background-size:cover; background-position:center;
}
.booking-thumb img{ width:100%; height:100%; object-fit:cover; display:block; }
.booking-body{ flex:1; min-width:0; padding-top:.1rem; }
.booking-body h5{ font-size:1.02rem; font-weight:800; margin:0 0 .3rem; color:var(--text); }
.b-orderid{ font-size:.72rem; font-weight:700; color:var(--muted-2); font-family:monospace; letter-spacing:.03em; margin-bottom:.4rem; }
.booking-body .b-venue{ font-size:.83rem; color:var(--muted); margin-bottom:.15rem; }
.booking-body .b-date{ font-size:.83rem; color:var(--muted); }

.booking-footer{ display:flex; align-items:center; justify-content:space-between; margin-top:.9rem; }
.pass-count-chip{
  background:var(--panel-2); border:1px solid var(--line); color:var(--text);
  font-size:.8rem; font-weight:700; padding:.4rem .9rem; border-radius:8px;
}
.status-chip{
  font-size:.72rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase;
  padding:.35rem .8rem; border-radius:999px; border:1px solid transparent;
}
.status-chip.st-confirmed{ color:var(--green); background:rgba(61,220,132,.12); border-color:rgba(61,220,132,.3); }
.status-chip.st-pending{ color:var(--yellow); background:rgba(255,201,77,.12); border-color:rgba(255,201,77,.3); }
.status-chip.st-cancelled{ color:var(--pink); background:rgba(255,61,104,.12); border-color:rgba(255,61,104,.3); }

/* ---------- empty state ---------- */
.bookings-empty{ text-align:center; padding:3rem 1rem; }
.bookings-empty i{ font-size:2.1rem; color:var(--muted-2); margin-bottom:1rem; }
.bookings-empty p{ color:var(--muted); margin-bottom:1.2rem; }

/* ---------- pagination ---------- */
.acct-pagination{ display:flex; align-items:center; justify-content:center; gap:.4rem; margin-top:1.4rem; flex-wrap:wrap; }
.acct-pagination a, .acct-pagination span{
  min-width:34px; height:34px; padding:0 .4rem; border-radius:8px; border:1px solid var(--line);
  background:var(--panel-2); color:var(--muted); font-size:.82rem; font-weight:700;
  display:flex; align-items:center; justify-content:center;
}
.acct-pagination a:hover{ border-color:var(--pink); color:var(--pink); }
.acct-pagination a.active, .acct-pagination span.active{
  background:var(--pink); border-color:var(--pink); color:#fff;
}
.acct-pagination span.disabled{ opacity:.35; pointer-events:none; }

/* ---------- sign-in-required panel ---------- */
.locked-card{
  background:var(--panel); border:1px solid var(--line); border-radius:22px;
  max-width:520px; margin:0 auto; padding:2.6rem 2rem; text-align:center;
}
.locked-icon{
  width:64px; height:64px; border-radius:50%; margin:0 auto 1.2rem;
  background:rgba(255,61,104,.12); color:var(--pink);
  display:flex; align-items:center; justify-content:center; font-size:1.6rem;
}
.locked-card h1{ font-size:1.4rem; margin-bottom:.5rem; }
.locked-card p{ color:var(--muted); font-size:.9rem; margin-bottom:1.6rem; }

@media (max-width:991.98px){
  .account-hero{ padding-top:6.5rem; }
}

@media (max-width:575.98px){
  .account-hero{ padding:6rem 0 3rem; }
  .acct-head h1{ font-size:1.5rem; }

  .profile-card{ gap:1rem; }
  .profile-avatar{ width:68px; height:68px; font-size:1.3rem; }
  .profile-info h3{ font-size:1.1rem; }
  .profile-edit-link{ margin-left:0; width:100%; justify-content:center; padding-top:.5rem; border-top:1px solid var(--line); }

  .bookings-panel{ padding:1.1rem; }
  .booking-row{ padding:.9rem; }
  .booking-main{ flex-direction:column; align-items:center; text-align:center; }
  .booking-thumb{ width:100%; height:150px; }
  .booking-body h5{ font-size:.98rem; }
  .booking-footer{ flex-wrap:wrap; gap:.6rem; justify-content:center; }

  .acct-menu-dropdown{ min-width:150px; }
}
</style>

<div class="account-hero">
  <div class="container">
    <div class="account-col">

      <?php if (!$isLoggedIn): ?>

        <!-- ============ NOT SIGNED IN ============ -->
        <div class="locked-card">
          <div class="locked-icon"><i class="fa-solid fa-lock"></i></div>
          <h1>Please sign in to view your account</h1>
          <p>Sign in with the mobile number you used to book with HighSpinn and we'll show your profile and bookings right here.</p>
          <button type="button" class="btn-pink" data-bs-toggle="modal" data-bs-target="#authModal">Sign in</button>
        </div>
        <script>
          document.addEventListener('DOMContentLoaded', function () {
            var authModalEl = document.getElementById('authModal');
            if (authModalEl && window.bootstrap) {
              new bootstrap.Modal(authModalEl).show();
            }
          });
        </script>

      <?php else: ?>

      <div class="acct-head">
        <h1>My Account</h1>
        <div class="acct-menu-wrap">
          <button type="button" class="acct-menu-btn" id="acctMenuBtn" aria-haspopup="true" aria-expanded="false" aria-label="Account options">
            <i class="fa-solid fa-ellipsis"></i>
          </button>
          <div class="acct-menu-dropdown" id="acctMenuDropdown">
            <a href="#" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fa-solid fa-user-pen"></i> Edit profile</a>
            <a href="/logout.php" class="danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
          </div>
        </div>
      </div>

      <div class="profile-card">
        <div class="profile-avatar">
          <?php if (!empty($userImage)): ?>
            <img src="<?php echo htmlspecialchars($userImage); ?>" alt="<?php echo htmlspecialchars($userName ?: 'Profile photo'); ?>">
          <?php else: ?>
            <?php echo htmlspecialchars($initials); ?>
          <?php endif; ?>
        </div>
        <div class="profile-info">
          <h3><?php echo htmlspecialchars($userName ?: 'Member'); ?></h3>
          <?php if ($userEmail): ?>
            <div class="p-line"><i class="fa-regular fa-envelope"></i><?php echo htmlspecialchars($userEmail); ?></div>
          <?php endif; ?>
          <?php if ($userPhone): ?>
            <div class="p-line"><i class="fa-solid fa-phone"></i>+91<?php echo htmlspecialchars($userPhone); ?></div>
          <?php endif; ?>
        </div>
        <a href="#" class="profile-edit-link" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fa-solid fa-pen"></i> Edit</a>
      </div>

      <div class="bookings-panel" id="bookings">
        <h6><i class="fa-solid fa-ticket me-1"></i> My Bookings</h6>

        <?php if (empty($bookings)): ?>

          <div class="bookings-empty">
            <i class="fa-solid fa-champagne-glasses"></i>
            <p>You haven't booked any events yet.</p>
            <a href="<?= hs_city_url('events') ?>" class="btn-pink">Browse Events</a>
          </div>

        <?php else: ?>

          <?php foreach ($bookings as $bk): ?>
            <a class="booking-row" href="<?php echo htmlspecialchars($bk['_url']); ?>">
              <div class="booking-main">
                <div class="booking-thumb">
                  <?php if (!empty($bk['event_image'])): ?>
                    <img src="<?php echo htmlspecialchars($bk['event_image']); ?>" alt="<?php echo htmlspecialchars($bk['event_title'] ?? ''); ?>">
                  <?php endif; ?>
                </div>
                <div class="booking-body">
                  <h5><?php echo htmlspecialchars($bk['event_title'] ?? 'Event'); ?></h5>
                  <div class="b-orderid">Order ID: <?php echo htmlspecialchars($bk['_order']); ?></div>
                  <?php if (!empty($bk['placename'])): ?>
                    <div class="b-venue"><?php echo htmlspecialchars($bk['placename']); ?></div>
                  <?php endif; ?>
                  <?php if ($bk['_date']): ?>
                    <div class="b-date"><?php echo $bk['_date']; ?></div>
                  <?php endif; ?>
                </div>
              </div>
              <div class="booking-footer">
                <span class="pass-count-chip"><?php echo $bk['_passes']; ?> Pass<?php echo $bk['_passes'] === 1 ? '' : 'es'; ?></span>
                <span class="status-chip st-confirmed">CONFIRMED</span>
              </div>
            </a>
          <?php endforeach; ?>

          <?php if ($totalPages > 1): ?>
            <div class="acct-pagination">
              <?php if ($page > 1): ?>
                <a href="<?php echo htmlspecialchars(hsBuildPageUrl($page - 1)); ?>"><i class="fa-solid fa-chevron-left"></i></a>
              <?php else: ?>
                <span class="disabled"><i class="fa-solid fa-chevron-left"></i></span>
              <?php endif; ?>

              <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php if ($p === $page): ?>
                  <span class="active"><?php echo $p; ?></span>
                <?php else: ?>
                  <a href="<?php echo htmlspecialchars(hsBuildPageUrl($p)); ?>"><?php echo $p; ?></a>
                <?php endif; ?>
              <?php endfor; ?>

              <?php if ($page < $totalPages): ?>
                <a href="<?php echo htmlspecialchars(hsBuildPageUrl($page + 1)); ?>"><i class="fa-solid fa-chevron-right"></i></a>
              <?php else: ?>
                <span class="disabled"><i class="fa-solid fa-chevron-right"></i></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>

        <?php endif; ?>
      </div>

      <?php endif; ?>

    </div>
  </div>
</div>

<?php endif; // isMobile ?>

<?php if ($isLoggedIn): ?>
<!-- ================= EDIT PROFILE MODAL (shared) ================= -->
<div class="modal fade auth-modal" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editProfileModalLabel">Edit profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="auth-lead">Your mobile number is used to sign in and can't be changed here.</p>

        <div class="auth-field">
          <label for="epPhone">Mobile number</label>
          <div class="auth-input-pill">
            <span class="flag">🇮🇳 +91</span>
            <input type="text" id="epPhone" value="<?php echo htmlspecialchars($userPhone); ?>" disabled>
          </div>
        </div>

        <div class="auth-field">
          <label for="epName">Full name</label>
          <div class="auth-input-pill"><input type="text" id="epName" placeholder="Your full name" value="<?php echo htmlspecialchars($userName); ?>" autocomplete="name"></div>
        </div>

        <div class="auth-field">
          <label for="epEmail">Email</label>
          <div class="auth-input-pill"><input type="email" id="epEmail" placeholder="you@example.com" value="<?php echo htmlspecialchars($userEmail); ?>" autocomplete="email"></div>
        </div>

        <div class="row">
          <div class="col-sm-6">
            <div class="auth-field">
              <label for="epGender">Gender</label>
              <div class="auth-input-pill">
                <select id="epGender">
                  <option value="">Select</option>
                  <option value="Male" <?php echo $userGender === 'Male' ? 'selected' : ''; ?>>Male</option>
                  <option value="Female" <?php echo $userGender === 'Female' ? 'selected' : ''; ?>>Female</option>
                  <option value="Other" <?php echo $userGender === 'Other' ? 'selected' : ''; ?>>Other</option>
                </select>
              </div>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="auth-field">
              <label for="epCity">City</label>
              <div class="auth-input-pill">
                <select id="epCity">
                  <option value="">Select</option>
                  <?php foreach ($allCities as $c): ?>
                    <option value="<?= htmlspecialchars($c['name']) ?>" <?php echo ($userCity !== '' && strcasecmp($userCity, $c['name']) === 0) ? 'selected' : ''; ?>><?= htmlspecialchars($c['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </div>

        <button type="button" class="btn-pink auth-btn-block mt-2" id="epSaveBtn">Save changes</button>
        <p class="auth-error" id="epError"></p>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  // Manual dropdown toggle (avoids double-toggle with Bootstrap's own handler)
  var btn  = document.getElementById('acctMenuBtn');
  var menu = document.getElementById('acctMenuDropdown');
  if (!btn || !menu) return;

  btn.addEventListener('click', function(e){
    e.stopPropagation();
    var isOpen = menu.classList.contains('show');
    menu.classList.toggle('show', !isOpen);
    btn.setAttribute('aria-expanded', String(!isOpen));
  });

  document.addEventListener('click', function(e){
    if (!btn.contains(e.target) && !menu.contains(e.target)) {
      menu.classList.remove('show');
      btn.setAttribute('aria-expanded', 'false');
    }
  });

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
      menu.classList.remove('show');
      btn.setAttribute('aria-expanded', 'false');
    }
  });
})();
</script>

<script>
(function(){
  var saveBtn   = document.getElementById('epSaveBtn');
  var errorEl   = document.getElementById('epError');
  var modalEl   = document.getElementById('editProfileModal');
  if (!saveBtn) return;

  function postJSON(url, data){
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(data),
      credentials: 'same-origin'
    }).then(function(r){ return r.json(); });
  }

  saveBtn.addEventListener('click', function(){
    errorEl.textContent = '';

    var name   = document.getElementById('epName').value.trim();
    var email  = document.getElementById('epEmail').value.trim();
    var gender = document.getElementById('epGender').value;
    var city   = document.getElementById('epCity').value;

    if (name.length < 2){ errorEl.textContent = 'Enter your full name.'; return; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){ errorEl.textContent = 'Enter a valid email address.'; return; }
    if (!gender){ errorEl.textContent = 'Select a gender.'; return; }
    if (!city){ errorEl.textContent = 'Select a city.'; return; }

    saveBtn.disabled = true;
    var originalLabel = saveBtn.textContent;
    saveBtn.textContent = 'Saving…';

    postJSON('/account/update_profile.php', { name: name, email: email, gender: gender, city: city })
      .then(function(res){
        if (!res || !res.success){
          saveBtn.disabled = false;
          saveBtn.textContent = originalLabel;
          errorEl.textContent = (res && res.message) ? res.message : 'Could not save your details. Please try again.';
          return;
        }
        window.location.reload();
      })
      .catch(function(){
        saveBtn.disabled = false;
        saveBtn.textContent = originalLabel;
        errorEl.textContent = 'Something went wrong. Please try again.';
      });
  });

  if (modalEl) {
    modalEl.addEventListener('show.bs.modal', function(){
      errorEl.textContent = '';
      saveBtn.disabled = false;
      saveBtn.textContent = 'Save changes';
    });
  }
})();
</script>
<?php endif; ?>

<?php
if ($isMobile) {
    $activeTab = 'profile';
    if (is_file(__DIR__ . '/include/app-bottombar.php')) include 'include/app-bottombar.php';
    include 'include/footerapp.php';
} else {
    include 'include/footer.php';
}
?>