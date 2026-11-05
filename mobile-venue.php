<?php
/**
 * mobile-venue.php
 * -----------------------------------------------------------------------
 * Mobile "app" venue detail page. Same data layer as venue.php (place +
 * events + similar venues) — only the markup is app-shaped: full-bleed
 * hero photo, back button, rating pill, capacity/events/rating stat row,
 * About, and stacked "Upcoming events" cards.
 *
 * NOTE on `capacity`: the `place` table in the query I was given doesn't
 * include a capacity column. If you have one, nothing else needs to
 * change — it'll just start rendering. If you don't, the stat box shows
 * "—" instead of a number rather than guessing a figure.
 * -----------------------------------------------------------------------
 */

include 'include/config.php';
require_once __DIR__ . '/include/event-helpers.php';

$id = $_REQUEST['url'] ?? '';
$stmt = $conn->prepare("SELECT * FROM place WHERE url = ?");
$stmt->bind_param('s', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    header("HTTP/1.1 404 Not Found");
    echo '<script> window.location.replace("/404") </script>';
    exit();
}

$placeid = $row['id'];

$title       = $row['name'] . ' - Highspinn';
$description = $row['name'] . ' - ' . $row['address'] . ' Now on Highspinn';
$image       = $row['image_url'];
$url         = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// ---------------------------------------------------------------
// Upcoming events at this venue
// ---------------------------------------------------------------
$eventsStmt = $conn->prepare("
    SELECT events.*, events.url AS eventsurl, place.name AS placename,
    (SELECT MIN(ticket_cost) FROM event_tickets WHERE event_id = events.id) AS min_cost,
    (SELECT MAX(ticket_cost) FROM event_tickets WHERE event_id = events.id) AS max_cost,
    (SELECT GROUP_CONCAT(category.cat_name SEPARATOR ',')
        FROM event_category
        INNER JOIN category ON category.id = event_category.cat_id
        WHERE event_category.event_id = events.id) AS cat_names
    FROM events
    INNER JOIN place ON events.sub_tittle = place.id
    WHERE events.status = 1 AND place.id = ? AND events.start >= NOW()
    ORDER BY events.start ASC LIMIT 10
");
$eventsStmt->bind_param('i', $placeid);
$eventsStmt->execute();
$events = $eventsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$eventCount = count($events);

// ---------------------------------------------------------------
// Similar / nearby venues (same city, active events ranked first)
// ---------------------------------------------------------------
$similarStmt = $conn->prepare("
    SELECT place.id, place.name, place.url, place.image_url, place.city, place.rating,
           MAX(CASE WHEN events.status = 1 AND events.start >= NOW() THEN 1 ELSE 0 END) AS has_active_event
    FROM place
    LEFT JOIN events ON events.sub_tittle = place.id
    WHERE place.city = ? AND place.id != ?
    GROUP BY place.id, place.name, place.url, place.image_url, place.city, place.rating
    ORDER BY has_active_event DESC, RAND()
    LIMIT 6
");
$similarStmt->bind_param('si', $row['city'], $placeid);
$similarStmt->execute();
$similarVenues = $similarStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<?php include 'include/header.php'; ?>
<?php include 'include/mobile-detail.css.php'; ?>

<div class="hs-detail">

  <!-- HERO -->
  <div class="dt-hero" style="height:230px;<?= !empty($image) ? "background-image:url('" . hs_e($image) . "');" : '' ?>">
    <a href="javascript:history.back()" class="dt-back-btn"><i class="fa-solid fa-chevron-left"></i></a>
    <span class="dt-action-btn share" id="shareBtn"><i class="fa-solid fa-share-nodes"></i></span>
    <span class="dt-action-btn heart" id="likeBtn"><i class="fa-regular fa-heart"></i></span>
  </div>

  <div class="dt-content">

    <?php if (!empty($row['rating'])): ?>
      <div class="dt-rating-pill"><i class="fa-solid fa-star"></i> <?= hs_e($row['rating']) ?></div>
    <?php endif; ?>

    <h1 class="dt-title"><?= hs_e($row['name']) ?></h1>
    <div class="dt-subline"><i class="fa-solid fa-location-dot"></i> <?= hs_e($row['address']) ?></div>

    <!-- STATS -->
    <div class="dt-stats">
      <div class="dt-stat">
        <div class="num"><?= isset($row['capacity']) && $row['capacity'] !== '' ? hs_e($row['capacity']) : '—' ?></div>
        <div class="lbl">Capacity</div>
      </div>
      <div class="dt-stat">
        <div class="num"><?= (int)$eventCount ?></div>
        <div class="lbl">Events</div>
      </div>
      <div class="dt-stat">
        <div class="num"><?= !empty($row['rating']) ? hs_e($row['rating']) : '—' ?></div>
        <div class="lbl">Rating</div>
      </div>
    </div>

    <!-- ABOUT -->
    <div class="dt-section">
      <h3>About</h3>
      <p class="dt-body-text">
        <?= !empty($row['description'])
            ? nl2br(hs_e($row['description']))
            : hs_e($row['name'] . ' in ' . ($row['city'] ?? '') . ' is one of the city\'s most electric nightlife destinations — premium sound, killer lighting and a packed floor every weekend.') ?>
      </p>
    </div>

    <!-- LOCATION MAP -->
    <?php if (!empty($row['address'])): ?>
    <div class="dt-section">
      <h3>Location</h3>
      <div class="dt-map">
        <iframe src="https://www.google.com/maps?q=<?= urlencode($row['address']) ?>&output=embed"
                loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                title="Map showing <?= hs_e($row['name']) ?>"></iframe>
      </div>
      <a class="dt-map-cta" href="https://www.google.com/maps?q=<?= urlencode($row['address']) ?>" target="_blank" rel="noopener">
        <i class="fa-solid fa-diamond-turn-right me-1"></i> Get directions
      </a>
    </div>
    <?php endif; ?>

    <!-- UPCOMING EVENTS -->
    <div class="dt-section">
      <h3>Upcoming events</h3>
      <?php if (empty($events)): ?>
        <div class="dt-empty">No upcoming events at this venue right now.</div>
      <?php else: ?>
        <?php foreach ($events as $ev):
            $startDate = new DateTime($ev['start']);
            $dateLabel = $startDate->format('D, d M · g:i A');
            [$priceLabel, $isFree] = hs_format_price($ev['min_cost'] ?? 0, $ev['max_cost'] ?? 0);
            $catNames = !empty($ev['cat_names']) ? explode(',', $ev['cat_names']) : ['Event'];
            $badge = trim($catNames[0]);
            echo hs_render_stacked_event_card([
                'image'       => $ev['image'],
                'url'         => '/events1/' . $ev['eventsurl'],
                'title'       => $ev['tittle'],
                'venue'       => $ev['placename'] . ' · ' . ($row['city'] ?? ''),
                'date_label'  => $dateLabel,
                'badge'       => $badge,
                'badge_bg'    => hs_category_badge_bg($badge),
                'price_label' => $isFree ? 'FREE' : $priceLabel,
                'is_free'     => $isFree,
            ]);
        endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- SIMILAR VENUES -->
    <?php if (!empty($similarVenues)): ?>
    <div class="dt-section" style="margin-bottom:6px;">
      <h3>Similar venues</h3>
      <div class="dt-hscroll">
        <?php foreach ($similarVenues as $sv): ?>
          <a class="dt-mini-card" href="/venues1/<?= hs_e($sv['url']) ?>">
            <div class="dt-mini-media" style="background-image:url('<?= hs_e($sv['image_url']) ?>');">
              <?php if (!empty($sv['rating'])): ?><span class="rating">★ <?= hs_e($sv['rating']) ?></span><?php endif; ?>
            </div>
            <div class="dt-mini-body">
              <div class="dt-mini-name"><?= hs_e($sv['name']) ?></div>
              <div class="dt-mini-meta"><?= hs_e($sv['city']) ?></div>
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
  var venueName = <?= json_encode($row['name']) ?>;

  var likeBtn = document.getElementById('likeBtn');
  likeBtn.addEventListener('click', function(){
    var icon = this.querySelector('i');
    icon.classList.toggle('fa-regular');
    icon.classList.toggle('fa-solid');
    this.style.color = icon.classList.contains('fa-solid') ? '#ff2f87' : '#fff';
  });

  document.getElementById('shareBtn').addEventListener('click', function(){
    if (navigator.share) {
      navigator.share({ title: venueName + ' on HighSpinn', url: window.location.href }).catch(function(){});
    } else {
      navigator.clipboard && navigator.clipboard.writeText(window.location.href);
      alert('Link copied to clipboard!');
    }
  });
})();
</script>

<?php include 'include/footerapp.php'; ?>
