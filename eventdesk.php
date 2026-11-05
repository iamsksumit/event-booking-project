<?php
include 'include/config.php';
include 'data/update_event_status.php';

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
// Categories — many-to-many, fetched separately (an event can have
// more than one, which the old single-row LIMIT 1 query would drop)
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
$catNamesStr = implode(' &middot; ', array_map(function ($c) {
    return htmlspecialchars($c['cat_name']);
}, $categories));

// ---------------------------------------------------------------
// Lineup / artists — also many-to-many
// ---------------------------------------------------------------
$artistStmt = $conn->prepare("
    SELECT artist.name, artist.image_url, artist.url
    FROM event_artist
    INNER JOIN artist ON event_artist.artist_id = artist.id
    WHERE event_artist.event_id = ?
");
$artistStmt->bind_param('i', $row['id']);
$artistStmt->execute();
$artists = $artistStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------------------------
// Ticket pricing — overall min/max, used for tags & the "Entry" chip
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
$isFree   = ($minCost == 0 && $maxCost == 0);
$priceLabel = $isFree
    ? 'Free'
    : ('₹' . number_format($minCost) . ($minCost != $maxCost ? ' - ₹' . number_format($maxCost) : ''));

// ---------------------------------------------------------------
// Ticket tiers for the "Passes & pricing" card — replaces the old
// hardcoded Ladies/Couple/Table list with the real rows from
// event_tickets. Column names assumed: ticket_name, ticket_cost.
// If your table also tracks remaining inventory, add that column
// name below (e.g. `quantity`) and it'll show a "spots left" line.
// ---------------------------------------------------------------
$ticketTiers = [];
$ticketsStmt = $conn->prepare("
    SELECT *
    FROM event_tickets
    WHERE event_id = ?
    ORDER BY ticket_cost ASC
");
$ticketsStmt->bind_param('i', $row['id']);
$ticketsStmt->execute();
$ticketsResult = $ticketsStmt->get_result();
while ($t = $ticketsResult->fetch_assoc()) {
    $tCost = (float)($t['ticket_cost'] ?? 0);
    $ticketTiers[] = [
        'name'  => $t['ticket_name'] ?? $t['name'] ?? ('Pass ' . (count($ticketTiers) + 1)),
        'cost'  => $tCost,
        'label' => ($tCost == 0) ? 'Free' : ('from ₹' . number_format($tCost)),
        'qty'   => isset($t['quantity']) ? (int)$t['quantity'] : null,
    ];
}
// Headline price = the single cheapest tier (falls back to the overall min/max above if
// event_tickets has no rows yet, e.g. TBA pricing).
$headlineTierName = !empty($ticketTiers) ? $ticketTiers[0]['name'] : null;
$headlinePrice     = !empty($ticketTiers) ? $ticketTiers[0]['cost'] : $minCost;
$headlineIsFree    = ($headlinePrice == 0);

// Total spots left across tiers, only shown if the qty column actually exists on this table.
$totalSpotsLeft = null;
if (!empty($ticketTiers) && $ticketTiers[0]['qty'] !== null) {
    $totalSpotsLeft = array_sum(array_map(function ($t) { return $t['qty'] ?? 0; }, $ticketTiers));
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
        LIMIT 4
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

$day       = $finalStart->format("D");
$date      = $finalStart->format("d");
$month     = $finalStart->format("M");
$year      = $finalStart->format("Y");
$time      = $finalStart->format("g:i a");
$fullStart = $finalStart->format("D, d M Y g:i a");
$timeend   = $endDateTime->format("g:i a");

// ---------------------------------------------------------------
// Meta data
// ---------------------------------------------------------------
function trimWords($text, $wordLimit = 30) {
    $text = strip_tags($text);
    $words = explode(' ', $text);
    $trimmedText = implode(' ', array_slice($words, 0, $wordLimit));
    if (count($words) > $wordLimit) {
        $trimmedText .= '...';
    }
    return $trimmedText;
}

$shortContent = trimWords($row['description'] ?? '', 30);
$title        = $row['tittle'] . '- Highspinn';
$description  = $row['tittle'] . '- ' . $shortContent . ' Now on Highspinn';
$keywords     = $row['tittle'] . ',' . ($row['placename'] ?? '');
$image        = $row["image"];
$url          = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

if (isset($pageData)) {
    $title = htmlspecialchars($pageData['title'] ?? $title);
    $description = htmlspecialchars($pageData['description'] ?? $description);
    $keywords = htmlspecialchars($pageData['keywords'] ?? $keywords);
    $image = htmlspecialchars($pageData['image'] ?? $image);
    $url = htmlspecialchars($pageData['url'] ?? $url);
}
?>


<?php
include 'include/header.php'; ?>

<?php
/* ---------------------------------------------------------------
   View tracking — daily log (upsert) + lifetime counter on events.
   Uses its own $todayDate variable (NOT $date) since $date is
   already in use above for the display day-of-month badge — reusing
   it here was silently overwriting that value.
   --------------------------------------------------------------- */
$event_id  = (int) $row['id'];
$todayDate = date('Y-m-d');

$viewStmt = $conn->prepare("
    INSERT INTO views (event_id, views, date)
    VALUES (?, 1, ?)
    ON DUPLICATE KEY UPDATE views = views + 1
");
$viewStmt->bind_param('is', $event_id, $todayDate);
$viewStmt->execute();
$viewStmt->close();

$totalStmt = $conn->prepare("
    UPDATE events SET views_count = views_count + 1 WHERE id = ?
");
$totalStmt->bind_param('i', $event_id);
$totalStmt->execute();
$totalStmt->close();
?>

<style>

/* ---------- section shell ---------- */
section{ padding:4rem 0; }
.section-head{ display:flex; align-items:flex-end; justify-content:space-between; margin-bottom:2rem; gap:1rem; flex-wrap:wrap; }
.section-head h2{ font-size:clamp(1.4rem,2.4vw,1.9rem); margin:0; }

/* ---------- breadcrumb strip ---------- */
.breadcrumb-strip{ padding:6.7rem 0 0; }
.breadcrumb-hs{ font-size:.8rem; color:var(--muted-2); }
.breadcrumb-hs a{ color:var(--muted-2); }
.breadcrumb-hs a:hover{ color:var(--pink); }

/* ---------- event hero ---------- */
.evt-hero{ padding:1.5rem 0 3rem; }
.evt-hero-media{
  position:relative; border-radius:22px; overflow:hidden; aspect-ratio:16/11; cursor:default;
  background-color:#1a1a20; background-size:cover; background-position:center;
  box-shadow:0 30px 60px rgba(0,0,0,.45); border:1px solid rgba(255,255,255,.06);
}
.evt-hero-media .streaks{
  position:absolute; inset:0;
  background: linear-gradient(115deg, transparent 44%, rgba(255,255,255,.12) 46%, rgba(255,255,255,.05) 49%, transparent 51%);
}
.evt-hero-media::after{ content:""; position:absolute; inset:0; background:linear-gradient(0deg, rgba(0,0,0,.45) 0%, transparent 35%); pointer-events:none; }
.evt-hero-tags{ position:absolute; top:18px; left:18px; z-index:3; display:flex; gap:.5rem; flex-wrap:wrap; max-width:80%; }
.tag-pill{
  background:rgba(10,10,13,.6); backdrop-filter:blur(6px); border:1px solid rgba(255,255,255,.15); color:#fff;
  font-size:.72rem; font-weight:700; padding:.3rem .7rem; border-radius:999px;
}
.evt-hero-heart{
  position:absolute; top:18px; right:18px; z-index:4;
  width:38px; height:38px; border-radius:50%; background:rgba(10,10,13,.55); backdrop-filter:blur(6px);
  border:1px solid rgba(255,255,255,.15); color:#fff; display:flex; align-items:center; justify-content:center;
}

/* ---------- hero caption — now sits BELOW the image, not overlaid on it ---------- */
.evt-hero-caption{ margin-top:1.2rem; }
.evt-hero-caption .eyebrow{ color:var(--yellow); margin-bottom:.5rem; display:block; }
.evt-hero-caption h1{ font-size:clamp(1.7rem,3.2vw,1.7rem); color:var(--text); margin-bottom:.6rem; line-height:1.15; }
.evt-hero-caption .sub{ color:var(--muted); font-size:.92rem; font-weight:600; display:flex; align-items:center; gap:1.1rem; flex-wrap:wrap; }
.evt-hero-caption .sub span{ display:inline-flex; align-items:center; gap:.4rem; }
.evt-hero-caption .sub i{ color:var(--pink); }

/* ---------- hero side panel (event details) ---------- */
.evt-hero-side{
  background:var(--panel); border:1px solid var(--line); border-radius:20px;
  padding:1.6rem; height:100%; display:flex; flex-direction:column;
}
.evt-hero-side .info-chip-row{ display:flex; flex-direction:column; gap:.7rem; margin-top:0; }

/* ---------- mini map, shown in the sidebar above "Event details" ---------- */
.evt-side-map{
  border-radius:14px; overflow:hidden; position:relative; border:1px solid var(--line);
  aspect-ratio:16/10; margin-bottom:1.2rem; background:#141416;
}
.evt-side-map iframe{ position:absolute; inset:0; width:100%; height:100%; border:0; filter:grayscale(.15) contrast(1.05); }
.evt-side-map .pin{
  position:absolute; top:50%; left:50%;
  width:34px; height:34px; border-radius:50% 50% 50% 0; transform-origin:bottom right;
  background:var(--pink); display:flex; align-items:center; justify-content:center; color:#fff;
  box-shadow:0 6px 16px rgba(255,61,104,.5); transform: translate(-50%,-100%) rotate(-45deg);
}
.evt-side-map .pin i{ transform:rotate(45deg); font-size:.85rem; }
.evt-side-map-label{
  position:absolute; left:10px; bottom:10px; z-index:2;
  background:rgba(10,10,13,.7); backdrop-filter:blur(6px); border:1px solid rgba(255,255,255,.15);
  color:#fff; font-size:.72rem; font-weight:700; padding:.3rem .6rem; border-radius:8px;
  display:flex; align-items:center; gap:.35rem; max-width:90%;
}
.evt-side-map-label i{ color:var(--pink); flex:none; }
.evt-side-map-label span{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

@media (max-width:991.98px){
  .evt-side-map{ aspect-ratio:16/9; }
}
@media (max-width:575.98px){
  .evt-side-map{ aspect-ratio:4/2.6; }
  .evt-side-map-label{ font-size:.68rem; }
}
.info-chip{
  background:var(--panel-2); border:1px solid var(--line); border-radius:14px;
  padding:.85rem 1.1rem; display:flex; align-items:center; gap:.7rem; width:100%;
}
.info-chip .ic-icon{
  width:38px; height:38px; border-radius:10px; background:var(--void); color:var(--pink);
  display:flex; align-items:center; justify-content:center; font-size:1rem; flex:none;
}
.info-chip .ic-label{ font-size:.7rem; color:var(--muted-2); font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
.info-chip .ic-value{ font-size:.88rem; font-weight:700; color:var(--text); }

/* ---------- content layout ---------- */
.content-card{ background:var(--panel); border:1px solid var(--line); border-radius:18px; padding:1.8rem; }
.content-card + .content-card{ margin-top:1.5rem; }
.content-card h3{ font-size:1.15rem; margin-bottom:1rem; }
.content-card p{ color:var(--muted); font-size:.9rem; line-height:1.7; }

/* ---------- description read-more ---------- */
.desc-wrap{ position:relative; }
.desc-content{ max-height:126px; overflow:hidden; position:relative; transition:max-height .25s ease; }
.desc-content.expanded{ max-height:2000px; }
.desc-wrap.collapsed .desc-content::after{
  content:""; position:absolute; left:0; right:0; bottom:0; height:50px;
  background:linear-gradient(0deg, var(--panel), transparent);
}
.desc-toggle-btn{
  background:transparent; border:none; color:var(--pink); font-weight:700; font-size:.85rem;
  padding:.6rem 0 0; display:inline-flex; align-items:center; gap:.4rem; cursor:pointer;
}
.desc-toggle-btn i{ font-size:.7rem; transition:transform .2s ease; }
.desc-toggle-btn.is-open i{ transform:rotate(180deg); }

.lineup-item{
  display:flex; align-items:center; gap:1rem; padding:.9rem 0; border-bottom:1px solid var(--line);
}
.lineup-item:last-child{ border-bottom:none; padding-bottom:0; }
.lineup-item .lu-avatar{
  width:52px; height:52px; border-radius:12px; flex:none;
  display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; font-size:.95rem;
}
.lineup-item h6{ font-size:.92rem; margin:0; font-weight:700; }

.accordion-hs .accordion-item{ background:transparent; border:none; border-bottom:1px solid var(--line); border-radius:0; }
.accordion-hs .accordion-item:last-child{ border-bottom:none; }
.accordion-hs .accordion-button{
  background:transparent; color:var(--text); font-weight:700; font-size:.9rem; padding:1rem 0; box-shadow:none;
}
.accordion-hs .accordion-button:not(.collapsed){ color:var(--pink); }
.accordion-hs .accordion-button::after{ filter:invert(1) brightness(1.6); }
.accordion-hs .accordion-body{ color:var(--muted); font-size:.85rem; line-height:1.7; padding:0 0 1.2rem; }

/* ---------- venue map (real embedded Google Map) ---------- */
.venue-map{
  border-radius:14px; overflow:hidden; aspect-ratio:16/8; position:relative;
  background:#141416;
  border:1px solid var(--line);
}
.venue-map iframe{ position:absolute; inset:0; width:100%; height:100%; border:0; filter:grayscale(.15) contrast(1.05); }
.venue-map .pin{
  position:absolute; top:50%; left:50%;
  width:38px; height:38px; border-radius:50% 50% 50% 0; transform-origin:bottom right;
  background:var(--pink); display:flex; align-items:center; justify-content:center; color:#fff;
  box-shadow:0 6px 16px rgba(255,61,104,.5); transform: translate(-50%,-100%) rotate(-45deg);
}
.venue-map .pin i{ transform:rotate(45deg); }

.organizer-row{ display:flex; align-items:center; gap:1rem; }
.organizer-row .org-avatar{
  width:52px; height:52px; border-radius:14px; background:linear-gradient(135deg,var(--pink),var(--orange));
  display:flex; align-items:center; justify-content:center; color:#fff; font-weight:800; flex:none;
}
.organizer-row h6{ font-size:.92rem; margin:0; }
.organizer-row small{ color:var(--muted-2); font-size:.78rem; }

.share-row{ display:flex; gap:.6rem; }
.share-circle{
  width:38px; height:38px; border-radius:50%; border:1px solid var(--line); background:var(--panel-2);
  display:flex; align-items:center; justify-content:center; color:var(--muted);
}
.share-circle:hover{ background:var(--pink); border-color:var(--pink); color:#fff; }

/* ---------- booking sidebar ---------- */
.booking-card{
  background:var(--panel); border:1px solid var(--line); border-radius:20px; padding:1.8rem;
  position:sticky; top:6.5rem; text-align:center;
}
.booking-card .price-line{ display:flex; align-items:baseline; justify-content:center; gap:.4rem; margin-bottom:.2rem; }
.booking-card .price-line .amt{ font-size:2.1rem; font-weight:800; }
.booking-card .bc-icon{
  width:52px; height:52px; border-radius:14px; margin:0 auto 1rem; background:linear-gradient(135deg,var(--pink),var(--orange));
  display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.2rem;
}

/* ---------- event cards (similar events) ---------- */
.evt-card{ background:var(--panel); border:1px solid var(--line); border-radius:18px; overflow:hidden; height:100%; transition:transform .2s ease, border-color .2s ease; }
.evt-card:hover{ transform:translateY(-4px); border-color:var(--muted-2); }
.evt-media{ position:relative; aspect-ratio:4/3.1; overflow:hidden; background-color:#1a1a20; }
.evt-media .streaks{
  position:absolute; inset:0;
  background: linear-gradient(115deg, transparent 44%, rgba(255,255,255,.12) 46%, rgba(255,255,255,.05) 49%, transparent 51%);
}
.evt-media::after{ content:""; position:absolute; inset:0; background:linear-gradient(0deg, rgba(0,0,0,.55), transparent 45%); }
.price-tag{
  position:absolute; top:10px; left:10px; z-index:2; background:rgba(10,10,13,.7); backdrop-filter:blur(6px);
  border:1px solid rgba(255,201,77,.4); color:var(--yellow); font-size:.7rem; font-weight:800; padding:.25rem .6rem; border-radius:999px;
}
.heart-btn{
  position:absolute; top:10px; right:10px; z-index:2; width:28px; height:28px; border-radius:50%; background:rgba(10,10,13,.6);
  backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; color:#fff; font-size:.75rem; border:1px solid rgba(255,255,255,.15);
}
.evt-body{ padding:1rem 1.1rem 1.15rem; }
.evt-meta{ font-size:.75rem; color:var(--muted-2); font-weight:600; margin-bottom:.4rem; display:flex; align-items:center; gap:.35rem; }
.evt-body h5{ font-size:.92rem; font-weight:700; margin-bottom:.35rem; line-height:1.3; color:var(--text); }
.evt-body h5 a{ color:inherit; }
.evt-body h5 a:hover{ color:var(--pink); }
.evt-venue{ font-size:.78rem; color:var(--muted); margin-bottom:.85rem; display:flex; align-items:center; gap:.35rem; }

@media (max-width:991.98px){
  .breadcrumb-strip{ padding-top:6rem; }
  .booking-card{ position:relative; top:0; margin-top:1.5rem; }
  .evt-hero-side{ margin-top:0; }
}
@media (max-width:575.98px){
  .evt-hero-media{ aspect-ratio:4/3.6; }
  .evt-hero-caption .sub span{ display:flex; }
}

/* ---------- quick-book summary card (replaces on-page ticket picker) ---------- */
.quickbook-card{
  background:var(--panel); border:1px solid var(--line); border-radius:20px; padding:1.6rem;
  position:sticky; top:6.5rem;
}
.quickbook-card .price-line{ display:flex; align-items:baseline; gap:.4rem; margin-bottom:.2rem; }
.quickbook-card .price-line .amt{ font-size:1.7rem; font-weight:800; }
.quickbook-card .price-line .per{ color:var(--muted-2); font-size:.8rem; font-weight:600; }
.quickbook-card .old-price{ text-decoration:line-through; color:var(--muted-2); font-size:.85rem; margin-right:.4rem; }
.qb-feature-list{ list-style:none; padding:0; margin:1.1rem 0 1.3rem; }
.qb-feature-list li{ display:flex; align-items:center; gap:.6rem; font-size:.84rem; color:var(--muted); padding:.4rem 0; border-bottom:1px solid var(--line); }
.qb-feature-list li:last-child{ border-bottom:none; }
.qb-feature-list li i{ color:var(--green); font-size:.8rem; }
.qb-feature-list li .from{ margin-left:auto; color:var(--yellow); font-weight:800; font-size:.82rem; white-space:nowrap; }
.selling-fast{ display:flex; align-items:center; gap:.4rem; font-size:.76rem; color:var(--orange); font-weight:700; margin-top:1rem; }
.selling-fast .dot{ width:6px; height:6px; border-radius:50%; background:var(--orange); animation:pulse 1.4s infinite; }
@keyframes pulse{ 0%,100%{opacity:1;} 50%{opacity:.3;} }

/* ---------- mobile sticky "Book now" bar ---------- */
.mobile-book-bar{ display:none; }
@media (max-width:767.98px){
  .mobile-book-bar{
    display:flex; align-items:center; justify-content:space-between; gap:.9rem;
    position:fixed; left:0; right:0; bottom:0; z-index:1050;
    background:var(--panel); border-top:1px solid var(--line);
    padding:.7rem 1rem calc(.7rem + env(safe-area-inset-bottom));
    box-shadow:0 -8px 24px rgba(0,0,0,.35);
  }
  .mobile-book-bar .mbb-price{ display:flex; flex-direction:column; line-height:1.15; }
  .mobile-book-bar .mbb-price .amt{ font-size:1.15rem; font-weight:800; color:var(--text); }
  .mobile-book-bar .mbb-price .lbl{ font-size:.68rem; color:var(--muted-2); font-weight:600; text-transform:uppercase; letter-spacing:.03em; }
  .mobile-book-bar .btn-pink{ flex:none; padding:.7rem 1.3rem; font-size:.88rem; white-space:nowrap; }
  .mobile-book-bar .mbb-sold-out{
    flex:1; text-align:center; background:#f8d7da; color:#721c24; padding:.65rem; border-radius:10px; font-weight:700; font-size:.85rem;
  }
  /* keep page content from being hidden behind the fixed bar */
  body{ padding-bottom:78px; }
}
</style>


<!-- ================= BREADCRUMB ================= -->
<div class="breadcrumb-strip" id="hideonapp">
  <div class="container">
    <div class="breadcrumb-hs">
      <a href="/">Home</a> <i class="fa-solid fa-chevron-right mx-1" style="font-size:.6rem;"></i>
      <a href="/events">Events</a>
      <?php if (!empty($categories)): ?>
        <i class="fa-solid fa-chevron-right mx-1" style="font-size:.6rem;"></i>
        <a href="/events?event_category=<?= htmlspecialchars($categories[0]['cat_slug']) ?>"><?= htmlspecialchars($categories[0]['cat_name']) ?></a>
      <?php endif; ?>
      <i class="fa-solid fa-chevron-right mx-1" style="font-size:.6rem;"></i>
      <span class="text-muted-c"><?= htmlspecialchars($row['tittle']) ?></span>
    </div>
  </div>
</div>

<!-- ================= EVENT HERO ================= -->
<div class="evt-hero">
  <div class="container">
    <div class="row g-4">

      <div class="col-lg-8">
        <!-- image only, no overlaid text -->
        <div class="evt-hero-media" id="evtHeroMedia" style="background-image:url('<?= htmlspecialchars($image) ?>');">
          <div class="streaks"></div>
          <div class="evt-hero-tags">
            <?php foreach ($categories as $c): ?>
              <span class="tag-pill"><?= htmlspecialchars($c['cat_name']) ?></span>
            <?php endforeach; ?>
            <span class="tag-pill" style="color:var(--green);border-color:rgba(61,220,132,.4);"><?= $isFree ? 'Free entry' : htmlspecialchars($priceLabel) ?></span>
          </div>
          <span class="evt-hero-heart" id="evtHeartBtn"><i class="fa-regular fa-heart"></i></span>
        </div>
      </div>

      <!-- ---------- SIDE: EVENT DETAILS ---------- -->
      <div class="col-lg-4">
        <div class="evt-hero-side">

          <div class="evt-hero-caption">
            <?php if ($catNamesStr): ?><span class="eyebrow"><?= $catNamesStr ?></span><?php endif; ?>
            <h1><?= htmlspecialchars($row['tittle']) ?></h1>
    
          </div>

          <span class="eyebrow" style="margin-bottom:1rem;">Event details</span>
          <div class="info-chip-row">
            <div class="info-chip">
              <span class="ic-icon"><i class="fa-regular fa-calendar"></i></span>
              <div><div class="ic-label">Date</div><div class="ic-value"><?= $day ?>, <?= $date ?> <?= $month ?> <?= $year ?></div></div>
            </div>
            <div class="info-chip">
              <span class="ic-icon"><i class="fa-regular fa-clock"></i></span>
              <div><div class="ic-label">Time</div><div class="ic-value"><?= $time ?> &ndash; <?= $timeend ?></div></div>
            </div>
            <?php if (!empty($row['placename'])): ?>
            <div class="info-chip">
              <span class="ic-icon"><i class="fa-solid fa-location-dot"></i></span>
              <div><div class="ic-label">Venue</div><div class="ic-value"><?= htmlspecialchars($row['placename']) ?></div></div>
            </div>
            <?php endif; ?>
            <div class="info-chip">
              <span class="ic-icon"><i class="fa-solid fa-tag"></i></span>
              <div><div class="ic-label">Entry</div><div class="ic-value"><?= htmlspecialchars($priceLabel) ?></div></div>
            </div>
          </div>
          <?php if (!$eventOver): ?>
            <a href="/book/<?= htmlspecialchars($row['url']) ?>" class="btn-pink w-100 justify-content-center d-flex align-items-center mt-3">
              Book your pass <i class="fa-solid fa-arrow-right ms-2"></i>
            </a>
          <?php else: ?>
            <div class="w-100 text-center mt-3" style="background:#f8d7da;color:#721c24;padding:.7rem;border-radius:10px;font-weight:700;">Event Over</div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ================= MAIN CONTENT ================= -->
<section class="pt-0">
  <div class="container">
    <div class="row g-4">

      <!-- ---------- LEFT: DETAILS ---------- -->
      <div class="col-lg-8">

        <?php if (!empty($row['description'])): ?>
        <div class="content-card">
          <h3>About this event</h3>
          <div class="desc-wrap" id="descWrap">
            <div class="text-muted-c desc-content" id="eventDescContent" style="font-size:.9rem;line-height:1.7;"><?= $row['description'] ?></div>
            <button type="button" class="desc-toggle-btn" id="descToggleBtn">Read more <i class="fa-solid fa-chevron-down"></i></button>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($artists)): ?>
        <div class="content-card">
          <div class="d-flex align-items-center justify-content-between mb-1">
            <h3 class="mb-0">Line-up</h3>
            <span class="text-muted-c2 small fw-semibold"><?= count($artists) ?> artist<?= count($artists) > 1 ? 's' : '' ?></span>
          </div>
          <?php foreach ($artists as $a):
              $initials = strtoupper(substr($a['name'], 0, 2));
          ?>
            <div class="lineup-item">
              <?php if (!empty($a['image_url'])): ?>
                <img class="lu-avatar" src="<?= htmlspecialchars($a['image_url']) ?>" alt="<?= htmlspecialchars($a['name']) ?>" style="object-fit:cover;">
              <?php else: ?>
                <span class="lu-avatar" style="background:linear-gradient(160deg,#3a1440,#160a20);"><?= $initials ?></span>
              <?php endif; ?>
              <div><h6><?= htmlspecialchars($a['name']) ?></h6></div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($row['placename'])): ?>
        <div class="content-card">
          <h3>Venue &amp; location</h3>
          <div class="organizer-row mb-3">
            <span class="org-avatar"><i class="fa-solid fa-champagne-glasses"></i></span>
            <div>
              <h6><?= htmlspecialchars($row['placename']) ?></h6>
              <?php if (!empty($row['placeadd'])): ?><small><?= htmlspecialchars($row['placeadd']) ?></small><?php endif; ?>
            </div>
          </div>

          <!-- real embedded Google Map (falls back to a pin placeholder if no address on file) -->
          <?php if (!empty($row['placeadd'])): ?>
            <div class="venue-map">
              <iframe
                src="https://www.google.com/maps?q=<?= urlencode($row['placeadd']) ?>&output=embed"
                allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                title="Map showing <?= htmlspecialchars($row['placename']) ?>">
              </iframe>
            </div>
          <?php else: ?>
            <div class="venue-map"><div class="pin"><i class="fa-solid fa-location-dot"></i></div></div>
          <?php endif; ?>

          <div class="d-flex gap-2 mt-3">
            <?php if (!empty($row['placeadd'])): ?>
              <a href="https://www.google.com/maps?q=<?= urlencode($row['placeadd']) ?>" target="_blank" rel="noopener" class="btn-dark-outline btn-sm py-2 px-3"><i class="fa-solid fa-diamond-turn-right me-2"></i>Get directions</a>
            <?php endif; ?>
            <?php if (!empty($row['placeurl'])): ?>
              <a href="/places/<?= htmlspecialchars($row['placeurl']) ?>" class="btn-dark-outline btn-sm py-2 px-3"><i class="fa-solid fa-arrow-up-right-from-square me-2"></i>View venue page</a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($row['special_detail']) || !empty($row['term'])): ?>
        <div class="content-card">
          <h3>Good to know</h3>
          <div class="accordion accordion-hs" id="evtAccordion">
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

        <?php if (!empty($row['Organised'])): ?>
        <div class="content-card">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="organizer-row">
              <span class="org-avatar"><i class="fa-solid fa-record-vinyl"></i></span>
              <div><h6>Hosted by <?= htmlspecialchars($row['Organised']) ?></h6></div>
            </div>
            <div class="share-row">
              <a href="https://wa.me/?text=<?= urlencode($row['tittle'] . ' - ' . $url) ?>" target="_blank" rel="noopener" class="share-circle"><i class="fa-brands fa-whatsapp"></i></a>
              <a href="#" class="share-circle" id="copyLinkBtn"><i class="fa-solid fa-link"></i></a>
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div>

      <!-- ---------- RIGHT: BOOKING CTA ---------- -->
      <div class="col-lg-4">
        <div class="quickbook-card">
          <span class="eyebrow" style="margin-bottom:.3rem;">Passes &amp; pricing</span>

          <?php if (!empty($ticketTiers)): ?>
            <div class="price-line">
              <span class="amt"><?= $headlineIsFree ? 'Free' : ('₹' . number_format($headlinePrice)) ?></span>
              <?php if ($headlineTierName): ?><span class="per"><?= htmlspecialchars($headlineTierName) ?></span><?php endif; ?>
            </div>
            <p class="text-muted-c small mb-0">Choose your pass and quantity on the next step.</p>

            <ul class="qb-feature-list">
              <?php foreach ($ticketTiers as $tier): ?>
                <li>
                  <i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($tier['name']) ?>
                  <span class="from"><?= $tier['label'] ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="price-line">
              <span class="amt"><?= $isFree ? 'Free' : htmlspecialchars($priceLabel) ?></span>
            </div>
            <p class="text-muted-c small mb-0">Pass pricing to be announced.</p>
          <?php endif; ?>

          <a href="<?= $eventOver ? '#' : ('/book/' . htmlspecialchars($row['url'])) ?>" class="btn-pink w-100 justify-content-center d-flex align-items-center<?= $eventOver ? ' disabled' : '' ?>">
            <?= $eventOver ? 'Event over' : 'Select passes' ?> <?php if (!$eventOver): ?><i class="fa-solid fa-arrow-right ms-2"></i><?php endif; ?>
          </a>

          <?php if ($totalSpotsLeft !== null && !$eventOver): ?>
            <div class="selling-fast"><span class="dot"></span>Only <?= (int)$totalSpotsLeft ?> spots left at this venue</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================= SIMILAR EVENTS ================= -->
<?php if (!empty($similarEvents)): ?>
<section class="pt-0">
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">You might also like</span>
        <h2>More nights nearby.</h2>
      </div>
      <a href="/events" class="link-arrow">See all events &rarr;</a>
    </div>
    <div class="row g-4">
      <?php foreach ($similarEvents as $ev):
          $evStart = new DateTime($ev['start']);
          $evFree  = ((float)$ev['min_cost'] === 0.0 && (float)$ev['max_cost'] === 0.0);
          $evPrice = $evFree ? 'FREE' : ('₹' . number_format($ev['min_cost']));
      ?>
      <div class="col-sm-6 col-lg-3">
        <div class="evt-card">
          <a href="/events/<?= htmlspecialchars($ev['url']) ?>">
            <div class="evt-media" style="background-image:url('<?= htmlspecialchars($ev['image']) ?>');background-size:cover;background-position:center;">
              <div class="streaks"></div>
              <span class="price-tag"><?= $evPrice ?></span>
              <span class="heart-btn"><i class="fa-regular fa-heart"></i></span>
            </div>
          </a>
          <div class="evt-body">
            <div class="evt-meta"><i class="fa-regular fa-clock"></i><?= $evStart->format('D, d M') ?> &middot; <?= $evStart->format('g:i A') ?></div>
            <h5><a href="/events/<?= htmlspecialchars($ev['url']) ?>"><?= htmlspecialchars($ev['tittle']) ?></a></h5>
            <div class="evt-venue"><i class="fa-solid fa-location-dot"></i><?= htmlspecialchars($ev['placename'] ?? '') ?></div>
            <a href="/events/<?= htmlspecialchars($ev['url']) ?>" class="btn-pass">Get pass <i class="fa-solid fa-arrow-right"></i></a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>



<!-- ================= MOBILE STICKY BOOK BAR ================= -->
<div class="mobile-book-bar">
  <?php if (!$eventOver): ?>
    <div class="mbb-price">
      <span class="lbl">From</span>
      <span class="amt"><?= $headlineIsFree ? 'Free' : ('₹' . number_format($headlinePrice)) ?></span>
    </div>
    <a href="/book/<?= htmlspecialchars($row['url']) ?>" class="btn-pink d-flex align-items-center">
      Book now <i class="fa-solid fa-arrow-right ms-2"></i>
    </a>
  <?php else: ?>
    <div class="mbb-sold-out">Event Over</div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  // favourite/heart toggle on hero + similar-event cards
  document.querySelectorAll('.evt-hero-heart, .heart-btn').forEach(function(h){
    h.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation();
      var icon = h.querySelector('i');
      icon.classList.toggle('fa-regular');
      icon.classList.toggle('fa-solid');
      h.style.color = icon.classList.contains('fa-solid') ? '#ff3d68' : '#fff';
    });
  });

  // copy-link share button
  var copyBtn = document.getElementById('copyLinkBtn');
  if (copyBtn) {
    copyBtn.addEventListener('click', function(e){
      e.preventDefault();
      navigator.clipboard.writeText(window.location.href).then(function(){
        copyBtn.innerHTML = '<i class="fa-solid fa-check"></i>';
        setTimeout(function(){ copyBtn.innerHTML = '<i class="fa-solid fa-link"></i>'; }, 1500);
      });
    });
  }

  // "Read more / Read less" toggle on the event description
  var descWrap = document.getElementById('descWrap');
  if (descWrap) {
    var descContent = document.getElementById('eventDescContent');
    var descBtn = document.getElementById('descToggleBtn');

    // Only show the toggle if the text is actually taller than the collapsed clamp
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

<?php include 'include/footer.php'; ?>