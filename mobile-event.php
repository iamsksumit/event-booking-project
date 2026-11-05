<?php
/**
 * mobile-event.php
 * -----------------------------------------------------------------------
 * Mobile "app" single-event detail page. Same data layer as the existing
 * event.php (events/place/category/artist/event_tickets queries, view
 * tracking, multiple_dates handling) — only the markup is app-shaped:
 * full-bleed hero photo, back button, pill tags, info chips, About +
 * Lineup, and a sticky bottom "Get Pass" bar.
 *
 * Route this the same way event.php is routed, e.g. swap it in behind a
 * mobile user-agent check, or expose it at /app/events/{url}.
 * -----------------------------------------------------------------------
 */

include 'include/config.php';
include 'data/update_event_status.php';
require_once __DIR__ . '/include/event-helpers.php'; // hs_e(), hs_format_price()

$id = $_REQUEST['url'] ?? '';

// ---------------------------------------------------------------
// Main event + venue (1:1, so safe to pull in a single row)
// ---------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT events.*,
        place.name AS placename,
        place.address AS placeadd,
        place.url AS placeurl,
        place.image_url AS placeimage
    FROM events
    LEFT JOIN place ON events.sub_tittle = place.id
    WHERE events.url = ?
    LIMIT 1
");
$stmt->bind_param('s', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    header("HTTP/1.1 404 Not Found");
    echo '<script> window.location.replace("/404") </script>';
    exit();
}

// ---------------------------------------------------------------
// Categories — many-to-many
// ---------------------------------------------------------------
$catStmt = $conn->prepare("
    SELECT category.cat_name, category.cat_slug
    FROM event_category
    INNER JOIN category ON event_category.cat_id = category.id
    WHERE event_category.event_id = ?
");
$catStmt->bind_param('i', $row['id']);
$catStmt->execute();
$categories = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$catNamesStr = implode(' · ', array_map(function ($c) { return hs_e($c['cat_name']); }, $categories));

// ---------------------------------------------------------------
// Lineup / artists
// ---------------------------------------------------------------
$artistStmt = $conn->prepare("
    SELECT artist.name, artist.image_url, artist.url, artist.genre
    FROM event_artist
    INNER JOIN artist ON event_artist.artist_id = artist.id
    WHERE event_artist.event_id = ?
");
$artistStmt->bind_param('i', $row['id']);
$artistStmt->execute();
$artists = $artistStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------------------------
// Ticket pricing — overall min/max
// ---------------------------------------------------------------
$priceStmt = $conn->prepare("
    SELECT MIN(ticket_cost) AS min_cost, MAX(ticket_cost) AS max_cost
    FROM event_tickets
    WHERE event_id = ?
");
$priceStmt->bind_param('i', $row['id']);
$priceStmt->execute();
$priceRow = $priceStmt->get_result()->fetch_assoc();
$minCost  = (float)($priceRow['min_cost'] ?? 0);
$maxCost  = (float)($priceRow['max_cost'] ?? 0);
[$priceLabel, $isFree] = hs_format_price($minCost, $maxCost);

// ---------------------------------------------------------------
// Ticket tiers (cheapest first — the cheapest becomes the headline price)
// ---------------------------------------------------------------
$ticketTiers = [];
$ticketsStmt = $conn->prepare("SELECT * FROM event_tickets WHERE event_id = ? ORDER BY ticket_cost ASC");
$ticketsStmt->bind_param('i', $row['id']);
$ticketsStmt->execute();
$ticketsResult = $ticketsStmt->get_result();
while ($t = $ticketsResult->fetch_assoc()) {
    $tCost = (float)($t['ticket_cost'] ?? 0);
    $ticketTiers[] = [
        'name'  => $t['ticket_name'] ?? $t['name'] ?? ('Pass ' . (count($ticketTiers) + 1)),
        'cost'  => $tCost,
        'label' => ($tCost == 0) ? 'Free' : ('₹' . number_format($tCost)),
        'qty'   => isset($t['quantity']) ? (int)$t['quantity'] : null,
    ];
}
$headlinePrice  = !empty($ticketTiers) ? $ticketTiers[0]['cost'] : $minCost;
$headlineIsFree = ($headlinePrice == 0);

$totalSpotsLeft = null;
if (!empty($ticketTiers) && $ticketTiers[0]['qty'] !== null) {
    $totalSpotsLeft = array_sum(array_map(fn($t) => $t['qty'] ?? 0, $ticketTiers));
}

// ---------------------------------------------------------------
// Similar events — other upcoming events sharing the first category
// ---------------------------------------------------------------
$similarEvents = [];
if (!empty($categories)) {
    $firstCatSlug = $categories[0]['cat_slug'];
    $simStmt = $conn->prepare("
        SELECT events.tittle, events.url, events.image, events.start,
            place.name AS placename,
            (SELECT MIN(ticket_cost) FROM event_tickets WHERE event_id = events.id) AS min_cost,
            (SELECT MAX(ticket_cost) FROM event_tickets WHERE event_id = events.id) AS max_cost
        FROM events
        LEFT JOIN place ON events.sub_tittle = place.id
        INNER JOIN event_category ON event_category.event_id = events.id
        INNER JOIN category ON category.id = event_category.cat_id
        WHERE category.cat_slug = ? AND events.id != ? AND events.status = 1
        GROUP BY events.id
        ORDER BY events.start ASC
        LIMIT 6
    ");
    $simStmt->bind_param('si', $firstCatSlug, $row['id']);
    $simStmt->execute();
    $similarEvents = $simStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ---------------------------------------------------------------
// Dates (multiple_dates handling kept from the original logic)
// ---------------------------------------------------------------
$startDateTime = new DateTime($row["start"]);
$endDateTime   = new DateTime($row["end"]);
$now           = new DateTime();

$finalStart = $startDateTime;
$multipleDates = [];
if (!empty($row['multiple_dates'])) {
    $multipleDates = array_map('trim', explode(',', $row['multiple_dates']));
}
if ($now < $endDateTime) {
    foreach ($multipleDates as $md) {
        $combined = strtotime($md . ' ' . $startDateTime->format("H:i:s"));
        if ($combined > $now->getTimestamp()) {
            $finalStart = new DateTime();
            $finalStart->setTimestamp($combined);
            break;
        }
    }
}
$eventOver = ($now > $endDateTime);

$dateLabel = $finalStart->format('D, d M') . ' · ' . $finalStart->format('g:i A');

// ---------------------------------------------------------------
// Meta
// ---------------------------------------------------------------
function trimWords($text, $wordLimit = 30) {
    $text = strip_tags($text);
    $words = explode(' ', $text);
    $trimmedText = implode(' ', array_slice($words, 0, $wordLimit));
    if (count($words) > $wordLimit) $trimmedText .= '...';
    return $trimmedText;
}
$shortContent = trimWords($row['description'] ?? '', 30);
$title        = $row['tittle'] . ' - Highspinn';
$description  = $row['tittle'] . ' - ' . $shortContent . ' Now on Highspinn';
$image        = $row["image"];
$url          = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
?>

<?php include 'include/header.php'; ?>

<?php
// View tracking — same as event.php
$event_id  = (int) $row['id'];
$todayDate = date('Y-m-d');
$viewStmt = $conn->prepare("INSERT INTO views (event_id, views, date) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE views = views + 1");
$viewStmt->bind_param('is', $event_id, $todayDate);
$viewStmt->execute();
$viewStmt->close();
$totalStmt = $conn->prepare("UPDATE events SET views_count = views_count + 1 WHERE id = ?");
$totalStmt->bind_param('i', $event_id);
$totalStmt->execute();
$totalStmt->close();
?>

<style>
body.hs-app-view .navbar-hs,
body.hs-app-view footer { display:none !important; }
body.hs-app-view { padding-bottom: 0; background:var(--void); }


/* ---------- mobile width fixes ---------- */
*, *::before, *::after{ box-sizing:border-box; }

html, body{ max-width:100%; overflow-x:hidden; }

.hs-event{ width:100%; overflow-x:hidden; }

/* contain the full-bleed similar-events scroller so its negative
   margins can't push the page wider than the viewport */
.ev-section:has(.ev-similar-scroll){ overflow-x:hidden; }

/* long titles/venue names shouldn't force width either */
.ev-title,
.ev-info-card .ic-value,
.ev-lineup-name{ overflow-wrap:break-word; word-break:break-word; }

.ev-title{ font-size:clamp(20px, 5.8vw, 25px); }

/* keep the hero and map from ever exceeding the viewport */
.ev-hero, .ev-map{ max-width:100%; }
.ev-map iframe{ max-width:100%; }


.hs-event{ max-width:480px; margin:0 auto; }

/* ---------- hero ---------- */
.ev-hero{
  position:relative; height:340px; background-size:cover; background-position:center;
  background-color:#1a1a20;
}
.ev-hero::after{
  content:''; position:absolute; inset:0;
  background:linear-gradient(180deg, rgba(10,10,13,0) 45%, var(--void) 100%);
}
.ev-back-btn{
  position:absolute; top:18px; left:16px; z-index:3;
  width:38px; height:38px; border-radius:50%; background:rgba(10,10,13,.55); backdrop-filter:blur(6px);
  border:1px solid rgba(255,255,255,.15); color:#fff; display:flex; align-items:center; justify-content:center;
  text-decoration:none; font-size:.95rem;
}
.ev-heart-btn{
  position:absolute; top:18px; right:16px; z-index:3;
  width:38px; height:38px; border-radius:50%; background:rgba(10,10,13,.55); backdrop-filter:blur(6px);
  border:1px solid rgba(255,255,255,.15); color:#fff; display:flex; align-items:center; justify-content:center;
}

/* ---------- content ---------- */
.ev-content{ padding:16px 18px 26px; margin-top:-8px; position:relative; z-index:2; }
.ev-tags{ display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
.ev-tag{
  background:var(--pink); color:#fff; font-size:11px; font-weight:800; text-transform:uppercase;
  padding:6px 13px; border-radius:100px; letter-spacing:.02em;
}
.ev-title{ font-size:25px; font-weight:800; line-height:1.15; color:var(--text); margin-bottom:20px; }

/* ---------- info chip cards ---------- */
.ev-info-card{
  display:flex; align-items:center; gap:13px; background:var(--panel); border:1px solid var(--line);
  border-radius:16px; padding:14px 16px; margin-bottom:12px; color:inherit; text-decoration:none;
}
.ev-info-card .ic-icon{
  width:38px; height:38px; border-radius:10px; background:var(--panel-2); color:var(--pink);
  display:flex; align-items:center; justify-content:center; font-size:15px; flex:none;
}
.ev-info-card .ic-body{ flex:1; min-width:0; }
.ev-info-card .ic-label{ font-size:11px; color:var(--muted-2); font-weight:700; text-transform:uppercase; letter-spacing:.03em; margin-bottom:2px; }
.ev-info-card .ic-value{ font-size:14.5px; font-weight:800; color:var(--text); }
.ev-info-card .ic-chev{ color:var(--muted-2); font-size:13px; flex:none; }

/* ---------- sections ---------- */
.ev-section{ margin-top:26px; }
.ev-section h3{ font-size:18px; font-weight:800; color:var(--text); margin-bottom:12px; }
.ev-desc{ color:var(--muted); font-size:14px; line-height:1.7; }
.ev-desc-wrap{ position:relative; }
.ev-desc-content{ max-height:110px; overflow:hidden; position:relative; transition:max-height .25s ease; }
.ev-desc-content.expanded{ max-height:2000px; }
.ev-desc-wrap.collapsed .ev-desc-content::after{
  content:""; position:absolute; left:0; right:0; bottom:0; height:44px;
  background:linear-gradient(0deg, var(--void), transparent);
}
.ev-desc-toggle{
  background:none; border:none; color:var(--pink); font-weight:800; font-size:13.5px;
  padding:8px 0 0; display:inline-flex; align-items:center; gap:5px;
}
.ev-desc-toggle i{ font-size:11px; transition:transform .2s ease; }
.ev-desc-toggle.is-open i{ transform:rotate(180deg); }

/* ---------- lineup ---------- */
.ev-lineup-scroll{ display:flex; gap:14px; overflow-x:auto; scrollbar-width:none; }
.ev-lineup-scroll::-webkit-scrollbar{ display:none; }
.ev-lineup-card{ flex:0 0 auto; width:92px; text-align:center; }
.ev-lineup-photo{
  width:80px; height:80px; border-radius:50%; margin:0 auto 8px; background-size:cover; background-position:center;
  background-color:var(--panel-2); border:2px solid var(--line);
  display:flex; align-items:center; justify-content:center; font-weight:800; color:#fff; font-size:18px;
}
.ev-lineup-name{ font-size:12px; font-weight:800; color:var(--text); }
.ev-lineup-genre{ font-size:10.5px; color:var(--pink); margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* ---------- venue map ---------- */
.ev-map{ border-radius:14px; overflow:hidden; aspect-ratio:16/9; position:relative; background:#141416; border:1px solid var(--line); margin-top:12px; }
.ev-map iframe{ position:absolute; inset:0; width:100%; height:100%; border:0; filter:grayscale(.15) contrast(1.05); }
.ev-map-actions{ display:flex; gap:8px; margin-top:10px; }
.ev-map-actions a{
  flex:1; text-align:center; background:var(--panel-2); border:1px solid var(--line); color:var(--text);
  font-size:12.5px; font-weight:700; padding:11px; border-radius:100px; text-decoration:none;
}

/* ---------- accordion ---------- */
.ev-accordion .accordion-item{ background:transparent; border:none; border-bottom:1px solid var(--line); }
.ev-accordion .accordion-item:last-child{ border-bottom:none; }
.ev-accordion .accordion-button{ background:transparent; color:var(--text); font-weight:700; font-size:14px; padding:13px 0; box-shadow:none; }
.ev-accordion .accordion-button:not(.collapsed){ color:var(--pink); }
.ev-accordion .accordion-button::after{ filter:invert(1) brightness(1.6); }
.ev-accordion .accordion-body{ color:var(--muted); font-size:13px; line-height:1.7; padding:0 0 14px; }

/* ---------- similar events ---------- */
.ev-similar-scroll{ display:flex; gap:12px; overflow-x:auto; scrollbar-width:none; margin:0 -18px; padding:0 18px; }
.ev-similar-scroll::-webkit-scrollbar{ display:none; }
.ev-sim-card{ flex:0 0 auto; width:200px; border-radius:14px; overflow:hidden; border:1px solid var(--line); background:var(--panel); text-decoration:none; color:var(--text); }
.ev-sim-media{ height:120px; background-size:cover; background-position:center; position:relative; }
.ev-sim-media .price{ position:absolute; top:8px; left:8px; background:rgba(10,10,13,.7); color:var(--yellow); font-size:10px; font-weight:800; padding:4px 8px; border-radius:100px; }
.ev-sim-body{ padding:10px; }
.ev-sim-title{ font-size:12.5px; font-weight:800; line-height:1.3; margin-bottom:4px; }
.ev-sim-meta{ font-size:10.5px; color:var(--muted-2); }

/* ---------- sticky bottom bar ---------- */
.ev-book-bar{
  position:fixed; left:0; right:0;
  bottom:64px; /* fallback until JS measures the real tab bar height, prevents a flash at bottom:0 */
  z-index:1049; /* one below the tab bar's 1050 so it visually sits under/against it */
  max-width:480px; margin:0 auto;
  display:flex; align-items:center; justify-content:space-between; gap:14px;
  background:var(--panel); border-top:1px solid var(--line);
  padding:14px 18px 14px; /* env(safe-area-inset-bottom) no longer needed here — the tab bar below already reserves that space */
  box-shadow:0 -10px 26px rgba(0,0,0,.4);
}
.ev-book-price .amt{ font-size:19px; font-weight:800; color:var(--yellow); display:block; line-height:1; }
.ev-book-price .lbl{ font-size:11px; color:var(--muted-2); font-weight:600; }
.ev-book-btn{
  display:inline-flex; align-items:center; gap:8px; background:linear-gradient(100deg,var(--pink),var(--orange));
  color:#fff; font-weight:800; font-size:14px; padding:13px 24px; border-radius:100px; text-decoration:none; flex:none;
}
.ev-sold-out{ flex:1; text-align:center; background:#3a1a1e; color:#ff9aa8; padding:13px; border-radius:100px; font-weight:700; font-size:13.5px; }

body.hs-app-view{ padding-bottom: 92px; }



</style>

<div class="hs-event">

  <!-- HERO -->
  <div class="ev-hero" style="background-image:url('<?= hs_e($image) ?>');">
    <a href="javascript:history.back()" class="ev-back-btn"><i class="fa-solid fa-chevron-left"></i></a>
    <span class="ev-heart-btn" id="evtHeartBtn"><i class="fa-regular fa-heart"></i></span>
  </div>

  <div class="ev-content">

    <!-- TAGS -->
    <?php if (!empty($categories)): ?>
    <div class="ev-tags">
      <?php foreach ($categories as $c): ?>
        <span class="ev-tag"><?= hs_e($c['cat_name']) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- TITLE -->
    <h1 class="ev-title"><?= hs_e($row['tittle']) ?></h1>

    <!-- DATE & TIME -->
    <div class="ev-info-card">
      <span class="ic-icon"><i class="fa-regular fa-calendar"></i></span>
      <div class="ic-body">
        <div class="ic-label">Date &amp; time</div>
        <div class="ic-value"><?= hs_e($dateLabel) ?></div>
      </div>
    </div>

    <!-- VENUE -->
    <?php if (!empty($row['placename'])): ?>
    <a class="ev-info-card" href="#venueSection">
      <span class="ic-icon"><i class="fa-solid fa-location-dot"></i></span>
      <div class="ic-body">
        <div class="ic-label">Venue</div>
        <div class="ic-value"><?= hs_e($row['placename']) ?><?= !empty($row['placeadd']) ? ' · ' . hs_e(explode(',', $row['placeadd'])[0]) : '' ?></div>
      </div>
      <i class="fa-solid fa-chevron-right ic-chev"></i>
    </a>
    <?php endif; ?>

    <!-- ABOUT -->
    <?php if (!empty($row['description'])): ?>
    <div class="ev-section">
      <h3>About the night</h3>
      <div class="ev-desc-wrap" id="descWrap">
        <div class="ev-desc ev-desc-content" id="eventDescContent"><?= $row['description'] ?></div>
        <button type="button" class="ev-desc-toggle" id="descToggleBtn">Read more <i class="fa-solid fa-chevron-down"></i></button>
      </div>
    </div>
    <?php endif; ?>

    <!-- LINEUP -->
    <?php if (!empty($artists)): ?>
    <div class="ev-section">
      <h3>Lineup</h3>
      <div class="ev-lineup-scroll">
        <?php foreach ($artists as $a):
            $initials = strtoupper(substr($a['name'], 0, 2));
            $genre = !empty($a['genre']) ? $a['genre'] : 'Featured artist';
        ?>
          <a class="ev-lineup-card" href="<?= !empty($a['url']) ? '/artists1/' . hs_e($a['url']) : '#' ?>">
            <?php if (!empty($a['image_url'])): ?>
              <div class="ev-lineup-photo" style="background-image:url('<?= hs_e($a['image_url']) ?>');"></div>
            <?php else: ?>
              <div class="ev-lineup-photo" style="background:linear-gradient(150deg,var(--pink),var(--orange));"><?= $initials ?></div>
            <?php endif; ?>
            <div class="ev-lineup-name"><?= hs_e($a['name']) ?></div>
            <div class="ev-lineup-genre"><?= hs_e($genre) ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- VENUE & MAP -->
    <?php if (!empty($row['placename'])): ?>
    <div class="ev-section" id="venueSection">
      <h3>Venue &amp; location</h3>
      <?php if (!empty($row['placeadd'])): ?>
        <div class="ev-map">
          <iframe src="https://www.google.com/maps?q=<?= urlencode($row['placeadd']) ?>&output=embed"
                  allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                  title="Map showing <?= hs_e($row['placename']) ?>"></iframe>
        </div>
        <div class="ev-map-actions">
          <a href="https://www.google.com/maps?q=<?= urlencode($row['placeadd']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-diamond-turn-right me-1"></i> Directions</a>
          <?php if (!empty($row['placeurl'])): ?>
            <a href="/places/<?= hs_e($row['placeurl']) ?>"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Venue page</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- GOOD TO KNOW -->
    <?php if (!empty($row['special_detail']) || !empty($row['term'])): ?>
    <div class="ev-section">
      <h3>Good to know</h3>
      <div class="accordion ev-accordion" id="evtAccordion">
        <?php if (!empty($row['special_detail'])): ?>
        <div class="accordion-item">
          <h2 class="accordion-header">
            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faqSpecial">Special details</button>
          </h2>
          <div id="faqSpecial" class="accordion-collapse collapse show" data-bs-parent="#evtAccordion">
            <div class="accordion-body"><?= $row['special_detail'] ?></div>
          </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($row['term'])): ?>
        <div class="accordion-item">
          <h2 class="accordion-header">
            <button class="accordion-button <?= empty($row['special_detail']) ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faqTerms">Terms &amp; conditions</button>
          </h2>
          <div id="faqTerms" class="accordion-collapse collapse <?= empty($row['special_detail']) ? 'show' : '' ?>" data-bs-parent="#evtAccordion">
            <div class="accordion-body"><?= $row['term'] ?></div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- SIMILAR EVENTS -->
  <?php if (!empty($similarEvents)): ?>
  <div class="ev-section" style="padding:0 0 20px;">
    <div style="padding:0 18px 12px;"><h3>You might also like</h3></div>
    <div class="ev-similar-scroll">
      <?php foreach ($similarEvents as $ev):
          $evStart = new DateTime($ev['start']);
          [$evPriceLabel, $evIsFree] = hs_format_price($ev['min_cost'] ?? 0, $ev['max_cost'] ?? 0);
      ?>
        <a class="ev-sim-card" href="/events1/<?= hs_e($ev['url']) ?>">
          <div class="ev-sim-media" style="background-image:url('<?= hs_e($ev['image']) ?>');">
            <span class="price"><?= hs_e($evPriceLabel) ?></span>
          </div>
          <div class="ev-sim-body">
            <div class="ev-sim-title"><?= hs_e($ev['tittle']) ?></div>
            <div class="ev-sim-meta"><?= $evStart->format('D, d M') ?> · <?= hs_e($ev['placename'] ?? '') ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<!-- STICKY BOTTOM BAR -->
<div class="ev-book-bar">
  <?php if (!$eventOver): ?>
    <div class="ev-book-price">
      <span class="amt"><?= $headlineIsFree ? 'FREE' : ('₹' . number_format($headlinePrice)) ?></span>
      <span class="lbl">entry pass</span>
    </div>
    <a href="/book/<?= hs_e($row['url']) ?>" class="ev-book-btn"><i class="fa-solid fa-ticket"></i> Get Pass</a>
  <?php else: ?>
    <div class="ev-sold-out">Event Over</div>
  <?php endif; ?>
</div>

<script>
document.body.classList.add('hs-app-view');

(function(){
  var heart = document.getElementById('evtHeartBtn');
  if (heart) {
    heart.addEventListener('click', function(){
      var icon = heart.querySelector('i');
      icon.classList.toggle('fa-regular');
      icon.classList.toggle('fa-solid');
      heart.style.color = icon.classList.contains('fa-solid') ? '#ff3d68' : '#fff';
    });
  }

  var descWrap = document.getElementById('descWrap');
  if (descWrap) {
    var descContent = document.getElementById('eventDescContent');
    var descBtn = document.getElementById('descToggleBtn');

    if (descContent.scrollHeight <= descContent.clientHeight + 4) {
      descBtn.style.display = 'none';
    } else {
      descWrap.classList.add('collapsed');
    }

    descBtn.addEventListener('click', function(){
      var expanded = descContent.classList.toggle('expanded');
      descWrap.classList.toggle('collapsed', !expanded);
      descBtn.classList.toggle('is-open', expanded);
      descBtn.innerHTML = expanded
        ? 'Read less <i class="fa-solid fa-chevron-up"></i>'
        : 'Read more <i class="fa-solid fa-chevron-down"></i>';
    });
  }
})();
</script>

<?php
$activeTab = ''; // no tab pre-highlighted on the detail page
include 'include/app-bottombar.php';
?>
<script>
(function(){
  function syncBookBarAboveTabbar(){
    var tabbar  = document.getElementById('hsAppTabbar');
    var bookbar = document.querySelector('.ev-book-bar');
    if (!tabbar || !bookbar) return;

    var tabbarH = tabbar.offsetHeight;
    bookbar.style.bottom = tabbarH + 'px';

    // body already gets padding-bottom:92px from app-bottombar.php's CSS;
    // this inline override wins and accounts for BOTH bars precisely.
    document.body.style.paddingBottom = (tabbarH + bookbar.offsetHeight) + 'px';
  }

  document.addEventListener('DOMContentLoaded', syncBookBarAboveTabbar);
  window.addEventListener('resize', syncBookBarAboveTabbar);
  window.addEventListener('orientationchange', syncBookBarAboveTabbar);
})();
</script>
<?php include 'include/footerapp.php'; ?>