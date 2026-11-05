<?php
/**
 * Defensive session_start(). The real fix belongs in include/config.php
 * (add the same line as the very first thing in that file) — this is a
 * safety net in case header.php is ever reached without config.php
 * having started the session first. Must run before ANY HTML output,
 * which is why it's the first thing in this file.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?php echo $title; ?></title>
  <meta name="description" content="<?php echo $description; ?>">
  <meta name="keywords" content="<?php echo $keywords; ?>">

  <meta property="og:title" content="<?php echo $title; ?>">
  <meta property="og:description" content="<?php echo $description; ?>">
  <meta property="og:image" content="<?php echo $image; ?>">
  <meta property="og:url" content="<?php echo $url; ?>">
  <meta property="og:type" content="website">

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?php echo $title; ?>">
  <meta name="twitter:description" content="<?php echo $description; ?>">
  <meta name="twitter:image" content="<?php echo $image; ?>">

  <link rel="canonical" href="<?php echo $url; ?>">

  <link rel="shortcut icon" href="/img/favicon.ico" type="image/x-icon">
  <link rel="apple-touch-icon" sizes="57x57" href="/apple-icon-57x57.png">
  <link rel="apple-touch-icon" sizes="72x72" href="/apple-icon-72x72.png">
  <link rel="apple-touch-icon" sizes="114x114" href="/apple-icon-114x114.png">
  <link rel="apple-touch-icon" sizes="144x144" href="/apple-icon-144x144.png">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
:root{
  --void:      #0a0a0d;
  --panel:     #131317;
  --panel-2:   #1a1a1f;
  --line:      #26262c;
  --pink:      #ff3d68;
  --orange:    #ff7a45;
  --yellow:    #ffc94d;
  --green:     #3ddc84;
  --text:      #f5f5f7;
  --muted:     #96969f;
  --muted-2:   #6f6f78;
}
*{ box-sizing:border-box; }
html{ scroll-behavior:smooth; }
body{
  background:var(--void);
  color:var(--text);
  font-family:'Inter',sans-serif;
  font-size:15px;
}
a{ text-decoration:none; }
::selection{ background:var(--pink); color:#fff; }
.text-muted-c{ color:var(--muted); }
.text-muted-c2{ color:var(--muted-2); }

h1,h2,h3,h4{ font-weight:800; letter-spacing:-0.02em; color:var(--text); }


/* ---------- section shell ---------- */
section{ padding:4rem 0; }
.section-head{ display:flex; align-items:flex-end; justify-content:space-between; margin-bottom:2rem; gap:1rem; flex-wrap:wrap; }
.section-head h2{ font-size:clamp(1.5rem,2.6vw,2rem); margin:0; }
.filter-pills{ display:flex; gap:.5rem; flex-wrap:wrap; }
.filter-pill{
  background:var(--panel-2); border:1px solid var(--line); color:var(--muted);
  font-size:.8rem; font-weight:700; padding:.45rem 1rem; border-radius:999px;
  cursor:pointer; transition:background .15s ease, color .15s ease, border-color .15s ease; user-select:none;
}
.filter-pill.active{ background:var(--pink); border-color:var(--pink); color:#fff; }
.filter-pill:hover{ border-color:var(--muted-2); }
.filter-pill.active:hover{ border-color:var(--pink); }


.eyebrow{
  font-size:.72rem; font-weight:700; letter-spacing:.14em; text-transform:uppercase;
  color:var(--pink); margin-bottom:.6rem; display:block;
}
.grad-text{
  background:linear-gradient(90deg,var(--orange),var(--pink));
  -webkit-background-clip:text; background-clip:text; color:transparent;
}
.link-arrow{
  font-weight:700; font-size:.85rem; color:var(--text); white-space:nowrap;
}
.link-arrow:hover{ color:var(--pink); }

.btn-pink{
  background:var(--pink); border:none; color:#fff; font-weight:700;
  padding:.72rem 1.5rem; border-radius:10px; font-size:.9rem;
  transition:filter .2s ease, transform .2s ease;
}
.btn-pink:hover{ filter:brightness(1.1); transform:translateY(-1px); color:#fff; }
.btn-dark-outline{
  background:var(--panel-2); border:1px solid var(--line); color:var(--text);
  font-weight:700; padding:.72rem 1.5rem; border-radius:10px; font-size:.9rem;
}
.btn-dark-outline:hover{ border-color:var(--muted-2); color:var(--text); }
.btn-pass{
  background:var(--panel-2); border:1px solid var(--line); color:var(--text);
  font-weight:700; font-size:.82rem; padding:.55rem 1rem; border-radius:8px;
  width:100%; text-align:left; display:flex; align-items:center; justify-content:space-between;
}
.btn-pass:hover{ background:var(--pink); border-color:var(--pink); color:#fff; }


/* ---------- navbar ---------- */
.navbar-hs{
  background:rgba(10,10,13,.85); backdrop-filter:blur(12px);
  border-bottom:1px solid var(--line);
  z-index:1045; /* deliberately above the mobile filter/sort bottom bars (1030) */
}
.brand-mark{
  display:inline-flex; align-items:center; gap:.5rem;
  font-weight:800; font-size:1.15rem; color:var(--text) !important;
}
.brand-mark img{ display:block; }
.navbar-hs .nav-link{ color:var(--muted); font-weight:600; font-size:.88rem; margin:0 .5rem; }
.navbar-hs .nav-link:hover{ color:var(--text); }
.pill-loc{
  background:var(--panel-2); border:1px solid var(--line); border-radius:999px;
  color:var(--text); font-size:.82rem; font-weight:600; padding:.45rem 1rem;
  display:inline-flex; align-items:center; gap:.4rem; white-space:nowrap;
  cursor:pointer;
}
.pill-loc:hover{ border-color:var(--pink); color:var(--pink); }
.search-pill{
  background:var(--panel-2); border:1px solid var(--line); border-radius:999px;
  padding:.45rem .5rem .45rem 1rem; display:flex; align-items:center; gap:.5rem;
  flex:1; min-width:0;
}
.search-pill input{ background:transparent; border:none; color:var(--text); font-size:.85rem; outline:none; width:100%; }
.search-pill input::placeholder{ color:var(--muted-2); }
.btn-signin{
  background:var(--panel-2); border:1px solid var(--line); color:var(--text);
  font-weight:700; font-size:.85rem; padding:.5rem 1.3rem; border-radius:999px; white-space:nowrap;
  cursor:pointer;
}
.btn-signin:hover{ background:var(--pink); border-color:var(--pink); color:#fff; }

/* ---------- mobile top-bar icons ---------- */
.mobile-nav-icons{ display:none; }
.icon-btn{
  width:38px; height:38px; border-radius:10px; background:var(--panel-2); border:1px solid var(--line);
  color:var(--text); display:flex; align-items:center; justify-content:center; font-size:.95rem; flex:none;
  cursor:pointer;
}
.icon-btn:hover{ color:var(--text); border-color:var(--muted-2); }

@media (max-width: 991.98px){
  .navbar-hs .navbar-collapse-desktop{ display:none !important; }
  .mobile-nav-icons{ display:flex; align-items:center; gap:.5rem; }
}
@media (min-width: 992px){
  .mobile-nav-icons{ display:none !important; }
}

/* ---------- offcanvas mobile menu ---------- */
.hs-offcanvas{
  background:var(--void); color:var(--text); border-left:1px solid var(--line);
  width:min(86vw, 360px);
}
.hs-offcanvas .offcanvas-header{ border-bottom:1px solid var(--line); padding:1.2rem 1.3rem; }
.hs-offcanvas .offcanvas-body{ padding:1.3rem; }
.hs-offcanvas .btn-close{ filter:invert(1) grayscale(1) brightness(1.8); opacity:.8; }
.hs-offcanvas .nav-link{
  color:var(--text); font-weight:700; font-size:1rem; padding:.x 0;
  border-bottom:1px solid var(--line);
}
.hs-offcanvas .nav-link:hover{ color:var(--pink); }
.hs-offcanvas .pill-loc{ margin-bottom:1.2rem; display:flex; }
.hs-offcanvas .btn-signin{ display:block; text-align:center; margin-top:1.2rem; }

/* ---------- mobile search overlay ---------- */
.mobile-search-overlay{
  position:fixed; inset:0; z-index:2000;
  background:var(--void);
  display:none;
  flex-direction:column;
  padding:1rem 1.1rem;
}
.mobile-search-overlay.show{ display:flex; }
.mobile-search-overlay .search-row{ display:flex; align-items:center; gap:.6rem; margin-top:.4rem; }
.mobile-search-overlay .search-pill{ padding:.6rem .9rem; }
.mobile-search-overlay .search-pill input{ font-size:.92rem; }
.search-suggest-label{
  font-size:.72rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase;
  color:var(--muted-2); margin:1.4rem 0 .8rem;
}
.suggest-chip-list{ display:flex; flex-wrap:wrap; gap:.55rem; }
.suggest-chip{
  background:var(--panel-2); border:1px solid var(--line); color:var(--text);
  font-size:.83rem; font-weight:700; padding:.5rem 1rem; border-radius:999px;
  display:inline-flex; align-items:center; gap:.4rem;
}
.suggest-chip:hover{ border-color:var(--pink); color:var(--pink); }
.suggest-chip.is-live::before{
  content:""; width:6px; height:6px; border-radius:50%; background:var(--pink); display:inline-block;
}

/* ---------- city picker modal ---------- */
.city-modal .modal-content{
  background:var(--panel); border:1px solid var(--line); border-radius:18px; color:var(--text);
}
.city-modal .modal-header{ border-bottom:1px solid var(--line); padding:1.2rem 1.3rem; }
.city-modal .modal-body{ padding:1.3rem; max-height:70vh; overflow-y:auto; }
.city-modal .btn-close{ filter:invert(1) grayscale(1) brightness(1.8); opacity:.8; }
.city-search-pill{
  background:var(--panel-2); border:1px solid var(--line); border-radius:999px;
  padding:.6rem .5rem .6rem 1rem; display:flex; align-items:center; gap:.5rem; margin-bottom:1.1rem;
}
.city-search-pill input{ background:transparent; border:none; color:var(--text); font-size:.9rem; outline:none; width:100%; }
.city-search-pill input::placeholder{ color:var(--muted-2); }
.city-grid{ display:grid; grid-template-columns:repeat(2,1fr); gap:.6rem; }
@media (min-width:576px){ .city-grid{ grid-template-columns:repeat(3,1fr); } }
.city-option{
  background:var(--panel-2); border:1px solid var(--line); color:var(--text);
  font-weight:700; font-size:.87rem; padding:.75rem .9rem; border-radius:12px;
  display:flex; align-items:center; gap:.5rem; text-align:left; width:100%;
}
.city-option i{ color:var(--muted-2); font-size:.8rem; }
.city-option:hover{ border-color:var(--pink); color:var(--pink); }
.city-option:hover i{ color:var(--pink); }
.city-option.is-current{ border-color:var(--pink); background:rgba(255,61,104,.08); color:var(--pink); }
.city-empty-msg{ color:var(--muted-2); font-size:.85rem; text-align:center; padding:1.5rem 0; display:none; }

/* ---------- account dropdown (manually toggled — see script at bottom) ---------- */
.account-dropdown{
  background:var(--panel); border:1px solid var(--line); border-radius:12px;
  padding:.4rem; min-width:200px; margin-top:.5rem;
}
.account-dropdown .dropdown-item{ color:var(--text); font-size:.85rem; font-weight:600; border-radius:8px; padding:.55rem .8rem; }
.account-dropdown .dropdown-item:hover{ background:var(--panel-2); color:var(--pink); }
.account-dropdown .dropdown-divider{ border-color:var(--line); margin:.3rem 0; }
/* Bootstrap's CSS already makes .dropdown-menu absolutely positioned and
   hidden by default, and .show { display:block } — we just never attach
   data-bs-toggle, and flip the "show" class ourselves in JS. */

/* ---------- auth modal ---------- */
.auth-modal .modal-content{
  background:var(--panel); border:1px solid var(--line); border-radius:18px; color:var(--text);
}
.auth-modal .modal-header{ border-bottom:1px solid var(--line); padding:1.2rem 1.3rem; }
.auth-modal .modal-body{ padding:1.5rem 1.4rem; }
.auth-modal .btn-close{ filter:invert(1) grayscale(1) brightness(1.8); opacity:.8; }
.auth-step{ display:none; }
.auth-step.active{ display:block; }
.auth-lead{ color:var(--muted); font-size:.85rem; margin-bottom:1.2rem; }
.auth-field{ margin-bottom:1rem; }
.auth-field label{ font-size:.78rem; font-weight:700; color:var(--muted); margin-bottom:.4rem; display:block; }
.auth-input-pill{
  background:var(--panel-2); border:1px solid var(--line); border-radius:10px;
  padding:.3rem .5rem .3rem 1rem; display:flex; align-items:center; gap:.5rem;
}
.auth-input-pill.is-invalid{ border-color:var(--pink); }
.auth-input-pill .flag{ font-size:.85rem; color:var(--muted); font-weight:700; white-space:nowrap; }
.auth-input-pill input, .auth-input-pill select{
  background:transparent; border:none; color:var(--text); font-size:.92rem; outline:none;
  width:100%; padding:.6rem 0;
}
.auth-input-pill select option{ background:var(--panel-2); color:var(--text); }
.auth-otp-row{ display:flex; gap:.5rem; }
.auth-otp-row input{
  width:44px; height:52px; text-align:center; font-size:1.2rem; font-weight:800;
  background:var(--panel-2); border:1px solid var(--line); border-radius:10px; color:var(--text);
}
.auth-otp-row input:focus{ outline:none; border-color:var(--pink); }
.auth-error{ color:var(--pink); font-size:.8rem; font-weight:600; margin-top:.5rem; min-height:1.1em; }
.auth-hint{ color:var(--muted-2); font-size:.78rem; margin-top:.7rem; }
.auth-hint a, .auth-resend{ color:var(--text); font-weight:700; cursor:pointer; }
.auth-hint a:hover, .auth-resend:hover{ color:var(--pink); }
.auth-resend[disabled]{ color:var(--muted-2); cursor:default; pointer-events:none; }
.auth-btn-block{ width:100%; }
.auth-success-icon{
  width:56px; height:56px; border-radius:50%; background:rgba(61,220,132,.12); color:var(--green);
  display:flex; align-items:center; justify-content:center; font-size:1.4rem; margin:0 auto 1rem;
}

@media (max-width: 991.98px){ .hero{ padding-top:7.5rem; } }
</style>
</head>
<body>
<?php
$logosql = "SELECT name, value FROM `settings` WHERE name IN ('logo', 'address', 'contact_info', 'razorpay_api_key', 'razorpay_api_secret', 'support_email', 'facebook', 'twitter', 'instagram', 'youtube', 'pinterest')";
$logoresult = $conn->query($logosql);

$website_logo = ""; $address = ""; $contact_info = ""; $razorpay_api_key = "";
$razorpay_api_secret = ""; $support_email = ""; $facebook = ""; $twitter = "";
$instagram = ""; $youtube = ""; $pinterest = "";

if ($logoresult && $logoresult->num_rows > 0) {
    while ($logorow = $logoresult->fetch_assoc()) {
        switch ($logorow['name']) {
            case 'logo': $website_logo = $logorow['value']; break;
            case 'address': $address = $logorow['value']; break;
            case 'contact_info': $contact_info = $logorow['value']; break;
            case 'razorpay_api_key': $razorpay_api_key = $logorow['value']; break;
            case 'razorpay_api_secret': $razorpay_api_secret = $logorow['value']; break;
            case 'support_email': $support_email = $logorow['value']; break;
            case 'facebook': $facebook = $logorow['value']; break;
            case 'twitter': $twitter = $logorow['value']; break;
            case 'instagram': $instagram = $logorow['value']; break;
            case 'youtube': $youtube = $logorow['value']; break;
            case 'pinterest': $pinterest = $logorow['value']; break;
        }
    }
}

/* ---------------------------------------------------------------------
   Resolve the current city (URL -> session -> default) and load the
   full city list for the picker modal. Exposes:
   $currentCityId, $currentCityName, $currentCitySlug, $allCities,
   and the hs_city_url() helper.
   --------------------------------------------------------------------- */
require_once __DIR__ . '/city.php';

/* ===========================================================================
   SEARCH-SUGGESTION CATEGORIES
   ---------------------------------------------------------------------------
   Categories with at least one live (status=1) event *in the current city*
   are listed first (ordered by how many live events they have), then the
   remaining categories alphabetically after. Same ordering rule is applied
   to the "Vibe / Category" list on events.php.
   =========================================================================== */
$suggestCats = [];
$scSql = "SELECT c.cat_slug, c.cat_name,
                 COUNT(DISTINCT CASE WHEN e.status = 1 THEN ec.event_id END) AS live_cnt
          FROM category c
          LEFT JOIN event_category ec ON ec.cat_id = c.id
          LEFT JOIN events e ON e.id = ec.event_id
          LEFT JOIN place p ON e.sub_tittle = p.id AND LOWER(p.city) = LOWER('{$currentCityEsc}')
          GROUP BY c.id
          ORDER BY live_cnt DESC, c.cat_name ASC
          LIMIT 8";
$scRes = $conn->query($scSql);
if ($scRes) { while ($r = $scRes->fetch_assoc()) { $suggestCats[] = $r; } }

// Reusable across both desktop + mobile account dropdowns
$isLoggedIn  = !empty($_SESSION['hs_user_id']);
$firstName   = $isLoggedIn ? htmlspecialchars(explode(' ', $_SESSION['hs_user_name'])[0]) : '';
?>

<!-- ================= NAVBAR ================= -->
<nav class="navbar navbar-expand-lg navbar-hs fixed-top py-3">
  <div class="container d-flex align-items-center justify-content-between">

    <a class="navbar-brand brand-mark" href="<?= hs_city_url() ?>" title="Highspinn">
      <img src="<?php echo htmlspecialchars($website_logo); ?>" width="120" height="40" alt="Highspinn" class="logo_normal">
    </a>

    <!-- ---- Desktop nav (menu + location + search + sign in) ---- -->
    <div class="navbar-collapse-desktop d-none d-lg-flex align-items-center flex-grow-1 justify-content-between ms-4">
      <ul class="navbar-nav mx-auto my-0">
        <?php
          $sqls = "SELECT * FROM menu WHERE place = 'Main_menu' ORDER BY created_at ASC";
          $results = mysqli_query($conn, $sqls);
          while ($menu = mysqli_fetch_assoc($results)):
        ?>
          <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars($menu['url']) ?>"><?= htmlspecialchars($menu['name']) ?></a></li>
        <?php endwhile; ?>
      </ul>
      <div class="d-flex align-items-center gap-2 flex-wrap flex-lg-nowrap">
        <button type="button" class="pill-loc" data-bs-toggle="modal" data-bs-target="#cityModal">
          <i class="fa-solid fa-location-dot"></i>
          <span><?= htmlspecialchars($currentCityName) ?></span>
          <i class="fa-solid fa-chevron-down small ms-1"></i>
        </button>
        <div class="search-pill" style="min-width:220px;">
          <i class="fa-solid fa-magnifying-glass text-muted-c2 small"></i>
          <input type="text" id="desktopSearchInput" placeholder="Search events, DJs, venues" autocomplete="off">
        </div>

        <?php if ($isLoggedIn): ?>
          <div class="dropdown">
            <button type="button" class="btn-signin d-flex align-items-center gap-2" id="accountDropdownBtn" aria-expanded="false" aria-haspopup="true">
              <i class="fa-solid fa-circle-user"></i>
              <?= $firstName ?>
              <i class="fa-solid fa-chevron-down small"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end account-dropdown" id="accountDropdownMenu" aria-labelledby="accountDropdownBtn">
              <li><a class="dropdown-item" href="/account/"><i class="fa-solid fa-user me-2"></i>Profile</a></li>
              <li><a class="dropdown-item" href="/account/#bookings"><i class="fa-solid fa-ticket me-2"></i>Past Event Bookings</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="/logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Sign out</a></li>
            </ul>
          </div>
        <?php else: ?>
          <button type="button" class="btn-signin" data-bs-toggle="modal" data-bs-target="#authModal">Sign In</button>
        <?php endif; ?>
      </div>
    </div>

    <!-- ---- Mobile-only icon bar: search, account/sign-in, menu ---- -->
    <div class="mobile-nav-icons">
      <button type="button" class="icon-btn" id="mobileSearchBtn" aria-label="Search">
        <i class="fa-solid fa-magnifying-glass"></i>
      </button>

      <?php if ($isLoggedIn): ?>
        <div class="dropdown">
          <button type="button" class="icon-btn" id="mobileAccountDropdownBtn" aria-label="Account" aria-expanded="false" aria-haspopup="true">
            <i class="fa-solid fa-circle-user"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end account-dropdown" id="mobileAccountDropdownMenu" aria-labelledby="mobileAccountDropdownBtn">
            <li><a class="dropdown-item" href="/account/"><i class="fa-solid fa-user me-2"></i>Profile</a></li>
            <li><a class="dropdown-item" href="/account/#bookings"><i class="fa-solid fa-ticket me-2"></i>Past Event Bookings</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="/logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Sign out</a></li>
          </ul>
        </div>
      <?php else: ?>
        <button type="button" class="icon-btn" aria-label="Sign in" data-bs-toggle="modal" data-bs-target="#authModal">
          <i class="fa-solid fa-user"></i>
        </button>
      <?php endif; ?>

      <button type="button" class="icon-btn" data-bs-toggle="offcanvas" data-bs-target="#hsOffcanvas" aria-controls="hsOffcanvas" aria-label="Menu">
        <i class="fa-solid fa-bars"></i>
      </button>
    </div>

  </div>
</nav>

<!-- ================= MOBILE OFF-CANVAS MENU ================= -->
<div class="offcanvas offcanvas-end hs-offcanvas" tabindex="-1" id="hsOffcanvas" aria-labelledby="hsOffcanvasLabel">
  <div class="offcanvas-header">
    <a class="brand-mark" href="<?= hs_city_url() ?>" id="hsOffcanvasLabel">
      <img src="<?php echo htmlspecialchars($website_logo); ?>" width="110" height="36" alt="Highspinn">
    </a>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body d-flex flex-column">
    <button type="button" class="pill-loc" data-bs-toggle="modal" data-bs-target="#cityModal">
      <i class="fa-solid fa-location-dot"></i>
      <span><?= htmlspecialchars($currentCityName) ?></span>
      <i class="fa-solid fa-chevron-down small ms-1"></i>
    </button>
    <nav class="nav flex-column mt-3">
      <?php
        $results2 = mysqli_query($conn, $sqls);
        while ($menu = mysqli_fetch_assoc($results2)):
      ?>
        <a class="nav-link" href="<?= htmlspecialchars($menu['url']) ?>"><?= htmlspecialchars($menu['name']) ?></a>
      <?php endwhile; ?>
    </nav>

    <?php if ($isLoggedIn): ?>
      <div class="dropdown mt-3">
        <button type="button" class="btn-signin d-flex align-items-center justify-content-between w-100" id="offcanvasAccountDropdownBtn" aria-expanded="false" aria-haspopup="true">
          <span class="d-flex align-items-center gap-2">
            <i class="fa-solid fa-circle-user"></i>
            <?= $firstName ?>
          </span>
          <i class="fa-solid fa-chevron-down small"></i>
        </button>
        <ul class="dropdown-menu account-dropdown w-100" id="offcanvasAccountDropdownMenu" aria-labelledby="offcanvasAccountDropdownBtn">
          <li><a class="dropdown-item" href="/account/"><i class="fa-solid fa-user me-2"></i>Profile</a></li>
          <li><a class="dropdown-item" href="/account/#bookings"><i class="fa-solid fa-ticket me-2"></i>Past Event Bookings</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="/logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Sign out</a></li>
        </ul>
      </div>
    <?php else: ?>
      <button type="button" class="btn-signin" data-bs-toggle="modal" data-bs-target="#authModal" data-bs-dismiss="offcanvas">Sign In</button>
    <?php endif; ?>
  </div>
</div>

<!-- ================= MOBILE SEARCH OVERLAY ================= -->
<div class="mobile-search-overlay" id="mobileSearchOverlay">
  <div class="d-flex align-items-center justify-content-between">
    <span class="fw-bold">Search</span>
    <button type="button" class="icon-btn" id="mobileSearchClose" aria-label="Close search"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="search-row">
    <div class="search-pill flex-grow-1">
      <i class="fa-solid fa-magnifying-glass text-muted-c2 small"></i>
      <input type="text" id="mobileSearchInput" placeholder="Search events, DJs, venues" autocomplete="off">
    </div>
  </div>
  <div class="search-suggest-label">Popular categories in <?= htmlspecialchars($currentCityName) ?></div>
  <div class="suggest-chip-list">
    <?php foreach ($suggestCats as $c): ?>
      <a class="suggest-chip<?= $c['live_cnt'] > 0 ? ' is-live' : '' ?>"
         href="<?= hs_city_url('categories/' . rawurlencode($c['cat_slug'])) ?>">
        <?= htmlspecialchars($c['cat_name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- ================= CITY PICKER MODAL ================= -->
<div class="modal fade city-modal" id="cityModal" tabindex="-1" aria-labelledby="cityModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cityModalLabel">Choose your city</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="city-search-pill">
          <i class="fa-solid fa-magnifying-glass text-muted-c2 small"></i>
          <input type="text" id="citySearchInput" placeholder="Search for your city" autocomplete="off">
        </div>
        <div class="city-grid" id="cityGrid">
          <?php foreach ($allCities as $c): ?>
            <button type="button"
                    class="city-option<?= ($c['slug'] === $currentCitySlug) ? ' is-current' : '' ?>"
                    data-slug="<?= htmlspecialchars($c['slug']) ?>"
                    data-name="<?= htmlspecialchars(strtolower($c['name'])) ?>">
              <i class="fa-solid fa-location-dot"></i>
              <?= htmlspecialchars($c['name']) ?>
            </button>
          <?php endforeach; ?>
        </div>
        <p class="city-empty-msg" id="cityEmptyMsg">No cities match your search.</p>
      </div>
    </div>
  </div>
</div>

<!-- ================= AUTH MODAL (Sign In / Register) ================= -->
<div class="modal fade auth-modal" id="authModal" tabindex="-1" aria-labelledby="authModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="authModalLabel">Sign in</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">

        <!-- STEP 1: phone number -->
        <div class="auth-step active" data-step="phone">
          <p class="auth-lead">Enter your mobile number, we'll send you a one-time code to sign in.</p>
          <div class="auth-field">
            <label for="authPhoneInput">Mobile number</label>
            <div class="auth-input-pill" id="authPhonePill">
              <span class="flag">🇮🇳 +91</span>
              <input type="tel" id="authPhoneInput" placeholder="98765 43210" inputmode="numeric" maxlength="10" autocomplete="tel">
            </div>
          </div>
          <button type="button" class="btn-pink auth-btn-block" id="authSendOtpBtn">Send OTP</button>
          <p class="auth-error" id="authPhoneError"></p>
        </div>

        <!-- STEP 2: OTP -->
        <div class="auth-step" data-step="otp">
          <p class="auth-lead">
            Enter the 6-digit code sent to <strong id="authOtpPhoneLabel">+91 XXXXXXXXXX</strong>
            &middot; <a id="authChangeNumber">change</a>
          </p>
          <div class="auth-field">
            <label>One-time code</label>
            <div class="auth-otp-row" id="authOtpRow">
              <input type="text" maxlength="1" inputmode="numeric" class="otp-box">
              <input type="text" maxlength="1" inputmode="numeric" class="otp-box">
              <input type="text" maxlength="1" inputmode="numeric" class="otp-box">
              <input type="text" maxlength="1" inputmode="numeric" class="otp-box">
              <input type="text" maxlength="1" inputmode="numeric" class="otp-box">
              <input type="text" maxlength="1" inputmode="numeric" class="otp-box">
            </div>
          </div>
          <button type="button" class="btn-pink auth-btn-block" id="authVerifyOtpBtn">Verify & continue</button>
          <p class="auth-error" id="authOtpError"></p>
          <p class="auth-hint">Didn't get it? <span class="auth-resend" id="authResendBtn">Resend OTP</span></p>
        </div>

        <!-- STEP 3: complete profile (new user, or returning user with incomplete profile) -->
        <div class="auth-step" data-step="details">
          <p class="auth-lead">Just a few details to finish setting up your account.</p>
          <div class="auth-field">
            <label for="authNameInput">Full name</label>
            <div class="auth-input-pill"><input type="text" id="authNameInput" placeholder="Aditi Sharma" autocomplete="name"></div>
          </div>
          <div class="auth-field">
            <label for="authEmailInput">Email</label>
            <div class="auth-input-pill"><input type="email" id="authEmailInput" placeholder="you@example.com" autocomplete="email"></div>
          </div>
          <div class="row">
            <div class="col-6">
              <div class="auth-field">
                <label for="authGenderInput">Gender</label>
                <div class="auth-input-pill">
                  <select id="authGenderInput">
                    <option value="">Select</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                    <option value="Other">Other</option>
                  </select>
                </div>
              </div>
            </div>
            <div class="col-6">
              <div class="auth-field">
                <label for="authCityInput">City</label>
                <div class="auth-input-pill">
                  <select id="authCityInput">
                    <option value="">Select</option>
                    <?php foreach ($allCities as $c): ?>
                      <option value="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
          </div>
          <button type="button" class="btn-pink auth-btn-block" id="authSaveDetailsBtn">Finish sign up</button>
          <p class="auth-error" id="authDetailsError"></p>
        </div>

        <!-- STEP 4: success -->
        <div class="auth-step text-center" data-step="success">
          <div class="auth-success-icon"><i class="fa-solid fa-check"></i></div>
          <h5 class="mb-1">You're all set!</h5>
          <p class="auth-lead mb-0" id="authSuccessMsg">Signed in successfully.</p>
        </div>

      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
var HS_CITY_SLUG = <?= json_encode($currentCitySlug) ?>;

function hsSearchRedirect(term){
  term = (term || '').trim();
  if (!term) return;
  window.location.href = '/' + HS_CITY_SLUG + '/events/?q=' + encodeURIComponent(term);
}

(function(){
  var searchBtn   = document.getElementById('mobileSearchBtn');
  var searchClose = document.getElementById('mobileSearchClose');
  var overlay     = document.getElementById('mobileSearchOverlay');
  var mobileInput = document.getElementById('mobileSearchInput');
  var desktopInput = document.getElementById('desktopSearchInput');

  function openSearch(){
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
    setTimeout(function(){ mobileInput.focus(); }, 50);
  }
  function closeSearch(){
    overlay.classList.remove('show');
    document.body.style.overflow = '';
  }
  if (searchBtn)   searchBtn.addEventListener('click', openSearch);
  if (searchClose) searchClose.addEventListener('click', closeSearch);

  mobileInput && mobileInput.addEventListener('keydown', function(e){
    if (e.key === 'Enter') hsSearchRedirect(mobileInput.value);
  });

  desktopInput && desktopInput.addEventListener('keydown', function(e){
    if (e.key === 'Enter') hsSearchRedirect(desktopInput.value);
  });
})();
</script>

<script>
(function(){
  var citySearch  = document.getElementById('citySearchInput');
  var cityOptions = document.querySelectorAll('#cityGrid .city-option');
  var emptyMsg    = document.getElementById('cityEmptyMsg');

  // Filter the grid as the user types
  citySearch && citySearch.addEventListener('input', function(){
    var q = this.value.trim().toLowerCase();
    var visible = 0;

    cityOptions.forEach(function(btn){
      var match = !q || btn.dataset.name.indexOf(q) !== -1;
      btn.style.display = match ? 'flex' : 'none';
      if (match) visible++;
    });

    emptyMsg.style.display = visible ? 'none' : 'block';
  });

  // Selecting a city takes you to that city's homepage: /{slug}/
  // (deep links reset to the city homepage so we never land on content
  // that doesn't exist for the newly selected city)
  cityOptions.forEach(function(btn){
    btn.addEventListener('click', function(){
      var slug = this.dataset.slug;
      if (!slug) return;
      window.location.href = '/' + slug + '/';
    });
  });

  // Reset the search box each time the modal re-opens
  var cityModalEl = document.getElementById('cityModal');
  if (cityModalEl) {
    cityModalEl.addEventListener('shown.bs.modal', function(){
      if (citySearch) { citySearch.value = ''; citySearch.dispatchEvent(new Event('input')); citySearch.focus(); }
    });
  }
})();
</script>

<script>
(function(){
  var modalEl   = document.getElementById('authModal');
  if (!modalEl) return;

  var titleEl   = document.getElementById('authModalLabel');
  var steps     = modalEl.querySelectorAll('.auth-step');

  var phoneInput   = document.getElementById('authPhoneInput');
  var phonePill    = document.getElementById('authPhonePill');
  var sendOtpBtn   = document.getElementById('authSendOtpBtn');
  var phoneError   = document.getElementById('authPhoneError');

  var otpPhoneLbl  = document.getElementById('authOtpPhoneLabel');
  var otpBoxes     = Array.prototype.slice.call(document.querySelectorAll('#authOtpRow .otp-box'));
  var verifyBtn    = document.getElementById('authVerifyOtpBtn');
  var otpError     = document.getElementById('authOtpError');
  var changeNumber = document.getElementById('authChangeNumber');
  var resendBtn    = document.getElementById('authResendBtn');

  var nameInput    = document.getElementById('authNameInput');
  var emailInput   = document.getElementById('authEmailInput');
  var genderInput  = document.getElementById('authGenderInput');
  var cityInput    = document.getElementById('authCityInput');
  var saveBtn      = document.getElementById('authSaveDetailsBtn');
  var detailsError = document.getElementById('authDetailsError');

  var successMsg   = document.getElementById('authSuccessMsg');

  var currentPhone = '';
  var resendTimer  = null;

  function showStep(name, title){
    steps.forEach(function(s){ s.classList.toggle('active', s.dataset.step === name); });
    if (title) titleEl.textContent = title;
  }

  function setLoading(btn, loading, label){
    if (loading){
      btn.dataset.label = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Please wait…';
    } else {
      btn.disabled = false;
      btn.textContent = label || btn.dataset.label || btn.textContent;
    }
  }

  function postJSON(url, data){
    var body = new URLSearchParams(data);
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
      credentials: 'same-origin'
    }).then(function(r){ return r.json(); });
  }

  function startResendCooldown(seconds){
    clearInterval(resendTimer);
    var remaining = seconds;
    resendBtn.setAttribute('disabled', 'disabled');
    resendBtn.textContent = 'Resend OTP in ' + remaining + 's';
    resendTimer = setInterval(function(){
      remaining--;
      if (remaining <= 0){
        clearInterval(resendTimer);
        resendBtn.removeAttribute('disabled');
        resendBtn.textContent = 'Resend OTP';
      } else {
        resendBtn.textContent = 'Resend OTP in ' + remaining + 's';
      }
    }, 1000);
  }

  function sendOtp(){
    phoneError.textContent = '';
    var phone = phoneInput.value.replace(/\D/g, '');

    if (phone.length !== 10){
      phonePill.classList.add('is-invalid');
      phoneError.textContent = 'Enter a valid 10-digit mobile number.';
      return;
    }
    phonePill.classList.remove('is-invalid');

    setLoading(sendOtpBtn, true);

    postJSON('/auth/send_otp.php', { phone: phone }).then(function(res){
      setLoading(sendOtpBtn, false, 'Send OTP');
      if (!res.success){
        phoneError.textContent = res.message || 'Could not send OTP. Try again.';
        return;
      }
      currentPhone = phone;
      otpPhoneLbl.textContent = '+91 ' + phone;
      otpBoxes.forEach(function(b){ b.value = ''; });
      otpError.textContent = '';
      showStep('otp', 'Enter OTP');
      startResendCooldown(30);
      setTimeout(function(){ otpBoxes[0].focus(); }, 100);
    }).catch(function(){
      setLoading(sendOtpBtn, false, 'Send OTP');
      phoneError.textContent = 'Something went wrong. Please try again.';
    });
  }

  sendOtpBtn.addEventListener('click', sendOtp);
  phoneInput.addEventListener('keydown', function(e){ if (e.key === 'Enter') sendOtp(); });
  phoneInput.addEventListener('input', function(){ this.value = this.value.replace(/\D/g,'').slice(0,10); });

  // OTP box auto-advance / backspace / paste
  otpBoxes.forEach(function(box, i){
    box.addEventListener('input', function(){
      this.value = this.value.replace(/\D/g,'').slice(0,1);
      if (this.value && otpBoxes[i+1]) otpBoxes[i+1].focus();
    });
    box.addEventListener('keydown', function(e){
      if (e.key === 'Backspace' && !this.value && otpBoxes[i-1]) otpBoxes[i-1].focus();
      if (e.key === 'Enter') verifyOtp();
    });
    box.addEventListener('paste', function(e){
      var text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'');
      if (!text) return;
      e.preventDefault();
      text.split('').slice(0,6).forEach(function(ch, idx){ if (otpBoxes[idx]) otpBoxes[idx].value = ch; });
      var next = Math.min(text.length, 5);
      otpBoxes[next].focus();
    });
  });

  changeNumber.addEventListener('click', function(){
    otpError.textContent = '';
    showStep('phone', 'Sign in');
    setTimeout(function(){ phoneInput.focus(); }, 100);
  });

  resendBtn.addEventListener('click', function(){
    if (resendBtn.hasAttribute('disabled')) return;
    postJSON('/auth/send_otp.php', { phone: currentPhone }).then(function(res){
      if (res.success){ startResendCooldown(30); otpError.textContent = ''; }
      else { otpError.textContent = res.message || 'Could not resend OTP.'; }
    });
  });

  function verifyOtp(){
    otpError.textContent = '';
    var otp = otpBoxes.map(function(b){ return b.value; }).join('');

    if (otp.length !== 6){
      otpError.textContent = 'Enter the 6-digit code.';
      return;
    }

    setLoading(verifyBtn, true);

    postJSON('/auth/verify_otp.php', { phone: currentPhone, otp: otp }).then(function(res){
      setLoading(verifyBtn, false, 'Verify & continue');

      if (!res.success){
        otpError.textContent = res.message || 'Incorrect OTP.';
        return;
      }

      if (res.status === 'logged_in'){
        successMsg.textContent = 'Signed in as ' + res.user.name + '.';
        showStep('success', 'Welcome back');
        setTimeout(function(){ window.location.reload(); }, 900);
        return;
      }

      // status === 'need_details' -> pre-fill whatever we already know
      var p = res.prefill || {};
      nameInput.value  = ((p.fname || '') + ' ' + (p.lname || '')).trim();
      emailInput.value = p.email || '';
      genderInput.value = p.gender || '';
      cityInput.value   = p.city || '';
      detailsError.textContent = '';
      showStep('details', 'Finish signing up');
      setTimeout(function(){ nameInput.focus(); }, 100);
    }).catch(function(){
      setLoading(verifyBtn, false, 'Verify & continue');
      otpError.textContent = 'Something went wrong. Please try again.';
    });
  }

  verifyBtn.addEventListener('click', verifyOtp);

  function saveDetails(){
    detailsError.textContent = '';

    var name   = nameInput.value.trim();
    var email  = emailInput.value.trim();
    var gender = genderInput.value;
    var city   = cityInput.value;

    if (!name || !email || !gender || !city){
      detailsError.textContent = 'Please fill in all the fields.';
      return;
    }

    setLoading(saveBtn, true);

    postJSON('/auth/complete_profile.php', {
      phone: currentPhone, name: name, email: email, gender: gender, city: city
    }).then(function(res){
      setLoading(saveBtn, false, 'Finish sign up');

      if (!res.success){
        detailsError.textContent = res.message || 'Could not save your details.';
        return;
      }

      successMsg.textContent = 'Welcome, ' + res.user.name + '!';
      showStep('success', 'You\'re in');
      setTimeout(function(){ window.location.reload(); }, 900);
    }).catch(function(){
      setLoading(saveBtn, false, 'Finish sign up');
      detailsError.textContent = 'Something went wrong. Please try again.';
    });
  }

  saveBtn.addEventListener('click', saveDetails);

  // Reset back to step 1 whenever the modal is (re)opened fresh
  modalEl.addEventListener('show.bs.modal', function(){
    clearInterval(resendTimer);
    phoneInput.value = '';
    phoneError.textContent = '';
    phonePill.classList.remove('is-invalid');
    showStep('phone', 'Sign in');
  });
})();
</script>

<!-- ================= ACCOUNT DROPDOWNS (desktop + mobile) ================= -->
<!-- Manually toggled — NOT using data-bs-toggle="dropdown", to avoid a
     double-toggle bug where Bootstrap's own handler and a custom one
     fight each other and the menu appears to never open. The .dropdown /
     .dropdown-menu / .show classes still come from bootstrap.min.css, so
     positioning and styling behave exactly like a normal Bootstrap
     dropdown — we're just driving the "show" class ourselves. -->
<script>
(function(){
  function wireDropdown(btnId, menuId){
    var btn  = document.getElementById(btnId);
    var menu = document.getElementById(menuId);
    if (!btn || !menu) return;

    btn.addEventListener('click', function(e){
      e.stopPropagation();
      var isOpen = menu.classList.contains('show');
      document.querySelectorAll('.dropdown-menu.show').forEach(function(m){
        if (m !== menu) m.classList.remove('show');
      });
      menu.classList.toggle('show', !isOpen);
      btn.setAttribute('aria-expanded', String(!isOpen));
    });

    document.addEventListener('click', function(e){
      if (!btn.contains(e.target) && !menu.contains(e.target)) {
        menu.classList.remove('show');
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  wireDropdown('accountDropdownBtn', 'accountDropdownMenu');
  wireDropdown('mobileAccountDropdownBtn', 'mobileAccountDropdownMenu');
  wireDropdown('offcanvasAccountDropdownBtn', 'offcanvasAccountDropdownMenu');

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') {
      document.querySelectorAll('.dropdown-menu.show').forEach(function(m){ m.classList.remove('show'); });
      document.querySelectorAll('[aria-expanded="true"]').forEach(function(b){ b.setAttribute('aria-expanded', 'false'); });
    }
  });
})();
</script>
</body>
</html>