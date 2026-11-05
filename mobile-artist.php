<?php
/**
 * mobile-artist.php
 * -----------------------------------------------------------------------
 * Mobile "app" artist detail page. Same data layer as artists/[url].php
 * (artist row + genre fallback-to-category logic + upcoming gigs +
 * similar artists) — only the markup is app-shaped: tall full-bleed
 * portrait hero, back button, "ARTIST" eyebrow, Bio, and stacked
 * "Upcoming shows" cards.
 * -----------------------------------------------------------------------
 */

include 'include/config.php';
require_once __DIR__ . '/include/event-helpers.php';

$urlParam = $_REQUEST['url'] ?? '';

$stmt = $conn->prepare("SELECT * FROM artist WHERE url = ? LIMIT 1");
$stmt->bind_param('s', $urlParam);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    header("HTTP/1.1 404 Not Found");
    echo '<script> window.location.replace("/404") </script>';
    exit();
}

$artistid   = (int)$row['id'];
$artistName = $row['name'];
$today      = date('Y-m-d');

/* ---------- Category lookup ---------- */
$categoryById = [];
$catRes = $conn->query("SELECT id, cat_name FROM category");
if ($catRes) { while ($c = $catRes->fetch_assoc()) { $categoryById[$c['id']] = $c['cat_name']; } }

function hs_artist_categories($conn, $artistId, $categoryById) {
    $labels = [];
    $stmt = $conn->prepare("
        SELECT DISTINCT event_category.cat_id
        FROM event_artist
        INNER JOIN events ON events.id = event_artist.event_id
        INNER JOIN event_category ON event_category.event_id = events.id
        WHERE events.status = 1 AND event_artist.artist_id = ?
    ");
    $stmt->bind_param('i', $artistId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        if (isset($categoryById[$r['cat_id']])) $labels[] = $categoryById[$r['cat_id']];
    }
    return $labels;
}

$ownGenres    = array_values(array_filter(array_map('trim', explode(',', $row['genre'] ?? ''))));
$artistGenres = !empty($ownGenres) ? $ownGenres : hs_artist_categories($conn, $artistid, $categoryById);
$genreLine    = implode(' & ', array_slice($artistGenres, 0, 2));

/* ---------- Upcoming shows ---------- */
$upcomingEvents = [];
$evStmt = $conn->prepare("
    SELECT events.id, events.tittle AS title, events.image, events.start, events.multiple_dates,
           events.url AS eventsurl, place.name AS placename,
           (SELECT MIN(ticket_cost) FROM event_tickets WHERE event_id = events.id) AS min_cost,
           (SELECT MAX(ticket_cost) FROM event_tickets WHERE event_id = events.id) AS max_cost,
           (SELECT GROUP_CONCAT(category.cat_name SEPARATOR ',')
              FROM event_category
              INNER JOIN category ON category.id = event_category.cat_id
              WHERE event_category.event_id = events.id) AS cat_names
    FROM events
    INNER JOIN event_artist ON events.id = event_artist.event_id
    INNER JOIN place ON events.sub_tittle = place.id
    WHERE events.status = 1 AND event_artist.artist_id = ?
");
$evStmt->bind_param('i', $artistid);
$evStmt->execute();
$evResult = $evStmt->get_result();

while ($ev = $evResult->fetch_assoc()) {
    $isUpcoming = false;
    $soonest = null;
    if (!empty($ev['start']) && $ev['start'] >= $today) { $isUpcoming = true; $soonest = $ev['start']; }
    if (!empty($ev['multiple_dates'])) {
        foreach (explode(',', $ev['multiple_dates']) as $d) {
            $d = trim($d);
            if ($d !== '' && $d >= $today) {
                $isUpcoming = true;
                if ($soonest === null || $d < $soonest) $soonest = $d;
            }
        }
    }
    if (!$isUpcoming) continue;

    [$priceLabel, $isFree] = hs_format_price($ev['min_cost'] ?? 0, $ev['max_cost'] ?? 0);
    $catNames = !empty($ev['cat_names']) ? array_values(array_filter(array_map('trim', explode(',', $ev['cat_names'])))) : ['Event'];

    $upcomingEvents[] = [
        'title' => $ev['title'], 'image' => $ev['image'], 'url' => $ev['eventsurl'],
        'place' => $ev['placename'], 'date' => $soonest,
        'price_label' => $isFree ? 'FREE' : $priceLabel, 'is_free' => $isFree,
        'badge' => $catNames[0],
    ];
}
usort($upcomingEvents, fn($a, $b) => strcmp($a['date'], $b['date']));
$upcomingEvents = array_slice($upcomingEvents, 0, 8);

/* ---------- Similar artists ---------- */
$upcomingArtistIds = [];
$uaRes = $conn->query("
    SELECT event_artist.artist_id, events.start, events.multiple_dates
    FROM event_artist
    INNER JOIN events ON events.id = event_artist.event_id
    WHERE events.status = 1
");
if ($uaRes) {
    while ($ua = $uaRes->fetch_assoc()) {
        $aid = (int)$ua['artist_id'];
        if (isset($upcomingArtistIds[$aid])) continue;
        $hasUpcoming = !empty($ua['start']) && $ua['start'] >= $today;
        if (!$hasUpcoming && !empty($ua['multiple_dates'])) {
            foreach (explode(',', $ua['multiple_dates']) as $d) {
                if (trim($d) !== '' && trim($d) >= $today) { $hasUpcoming = true; break; }
            }
        }
        if ($hasUpcoming) $upcomingArtistIds[$aid] = true;
    }
}

$saStmt = $conn->prepare("SELECT id, name, url, image_url, genre FROM artist WHERE status = 1 AND id != ?");
$saStmt->bind_param('i', $artistid);
$saStmt->execute();
$saResult = $saStmt->get_result();
$candidates = [];
while ($r = $saResult->fetch_assoc()) {
    $ownG = array_values(array_filter(array_map('trim', explode(',', $r['genre'] ?? ''))));
    $cats = !empty($ownG) ? $ownG : hs_artist_categories($conn, $r['id'], $categoryById);
    $candidates[] = [
        'id' => $r['id'], 'name' => $r['name'], 'url' => $r['url'], 'image' => $r['image_url'],
        'genre' => implode(' · ', array_slice($cats, 0, 2)),
        'overlap' => count(array_intersect($cats, $artistGenres)),
        'has_events' => isset($upcomingArtistIds[(int)$r['id']]),
    ];
}
usort($candidates, function ($a, $b) {
    if ($a['has_events'] !== $b['has_events']) return $b['has_events'] <=> $a['has_events'];
    if ($a['overlap'] !== $b['overlap']) return $b['overlap'] <=> $a['overlap'];
    return $b['id'] <=> $a['id'];
});
$similarArtists = array_slice($candidates, 0, 6);

$title       = $artistName . ' - Highspinn';
$description = $artistName . ($genreLine !== '' ? ' - ' . $genreLine . ' DJ on Highspinn' : ' - DJ on Highspinn');
$image       = $row['image_url'];
$url         = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
?>

<?php include 'include/header.php'; ?>
<?php include 'include/mobile-detail.css.php'; ?>

<div class="hs-detail">

  <!-- HERO -->
  <div class="dt-hero" style="height:400px;<?= !empty($image) ? "background-image:url('" . hs_e($image) . "');" : "background:linear-gradient(150deg,#3a1440,#160a20);" ?>">
    <a href="javascript:history.back()" class="dt-back-btn"><i class="fa-solid fa-chevron-left"></i></a>
    <span class="dt-action-btn share" id="shareBtn"><i class="fa-solid fa-share-nodes"></i></span>
    <span class="dt-action-btn heart" id="likeBtn"><i class="fa-regular fa-heart"></i></span>
  </div>

  <div class="dt-content">

    <div class="dt-eyebrow"><span class="dot"></span>Artist</div>
    <h1 class="dt-title"><?= hs_e($artistName) ?></h1>
    <?php if ($genreLine !== ''): ?>
      <div class="dt-subline" style="margin-bottom:2px;"><?= hs_e($genreLine) ?></div>
    <?php endif; ?>

    <div style="display:flex; gap:10px; margin-top:14px;">
      <button type="button" class="dt-social-chip" id="followBtn" style="background:var(--pink); border-color:var(--pink); color:#fff;">
        <i class="fa-solid fa-plus"></i> Follow
      </button>
      <?php if (!empty($row['insta'])): ?>
        <a class="dt-social-chip" href="<?= hs_e($row['insta']) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-instagram"></i> Instagram</a>
      <?php endif; ?>
    </div>

    <!-- BIO -->
    <div class="dt-section">
      <h3>Bio</h3>
      <p class="dt-body-text">
        <?= !empty($row['description'])
            ? nl2br(hs_e($row['description']))
            : hs_e($artistName . ($genreLine !== '' ? ' — ' . $genreLine . '.' : '.') . ' A staple of the city\'s nightlife circuit, known for reading the room and never letting the floor empty.') ?>
      </p>
    </div>

    <!-- UPCOMING SHOWS -->
    <div class="dt-section">
      <h3>Upcoming shows</h3>
      <?php if (empty($upcomingEvents)): ?>
        <div class="dt-empty">No upcoming shows for this artist yet.</div>
      <?php else: ?>
        <?php foreach ($upcomingEvents as $ev):
            $dateObj = date_create($ev['date']);
            $dateLabel = $dateObj ? $dateObj->format('D, d M · g:i A') : '';
            echo hs_render_stacked_event_card([
                'image'       => $ev['image'],
                'url'         => '/events1/' . $ev['url'],
                'title'       => $ev['title'],
                'venue'       => $ev['place'],
                'date_label'  => $dateLabel,
                'badge'       => $ev['badge'],
                'badge_bg'    => hs_category_badge_bg($ev['badge']),
                'price_label' => $ev['price_label'],
                'is_free'     => $ev['is_free'],
            ]);
        endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- SIMILAR ARTISTS -->
    <?php if (!empty($similarArtists)): ?>
    <div class="dt-section" style="margin-bottom:6px;">
      <h3>Similar artists</h3>
      <div class="dt-hscroll">
        <?php foreach ($similarArtists as $sa): ?>
          <a class="dt-mini-card" href="/artists1/<?= hs_e($sa['url']) ?>">
            <div class="dt-mini-media" style="background-image:url('<?= hs_e($sa['image']) ?>');">
              <?php if ($sa['has_events']): ?><span class="rating" style="color:#ff6b8f;">● Playing now</span><?php endif; ?>
            </div>
            <div class="dt-mini-body">
              <div class="dt-mini-name"><?= hs_e($sa['name']) ?></div>
              <?php if ($sa['genre']): ?><div class="dt-mini-meta"><?= hs_e($sa['genre']) ?></div><?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
document.body.classList.add('hs-app-view');
(function(){
  var artistName = <?= json_encode($artistName) ?>;

  document.getElementById('followBtn').addEventListener('click', function(){
    var following = this.classList.toggle('following');
    this.innerHTML = following
      ? '<i class="fa-solid fa-check"></i> Following'
      : '<i class="fa-solid fa-plus"></i> Follow';
    this.style.background = following ? 'var(--panel-2)' : 'var(--pink)';
    this.style.borderColor = following ? 'var(--line)' : 'var(--pink)';
  });

  var likeBtn = document.getElementById('likeBtn');
  likeBtn.addEventListener('click', function(){
    var icon = this.querySelector('i');
    icon.classList.toggle('fa-regular');
    icon.classList.toggle('fa-solid');
    this.style.color = icon.classList.contains('fa-solid') ? '#ff2f87' : '#fff';
  });

  document.getElementById('shareBtn').addEventListener('click', function(){
    if (navigator.share) {
      navigator.share({ title: artistName + ' on HighSpinn', url: window.location.href }).catch(function(){});
    } else {
      navigator.clipboard && navigator.clipboard.writeText(window.location.href);
      alert('Link copied to clipboard!');
    }
  });
})();
</script>

<?php
$activeTab = ''; // no tab is "active" on a detail page
include 'include/app-bottombar.php';

include 'include/footerapp.php'; ?>
