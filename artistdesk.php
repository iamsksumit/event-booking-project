<?php
include 'include/config.php';

/* =========================================================================
   SCHEMA ASSUMPTIONS — see artists.php / venues.php for the full list used
   site-wide. Specific to this page:

   artist table: id, name, url, image_url, genre (optional, comma-separated),
     description, insta, fb, status
   events table: id, status, start, multiple_dates, sub_tittle (place id),
     tittle (event title), image, url
   event_tickets table: event_id, ticket_cost
   place table: id, name
   category / event_category: same as artists.php / venues.php

   Notes:
   - "genre" is used if the artist row has it populated; otherwise the tag(s)
     shown are derived from the categories of events this artist performs at
     (same fallback pattern as the artists listing page).
   - Follow/Like/Share buttons are UI-only for now — there's no follow table
     in the schema I was given, so nothing is persisted yet. Tell me the
     table/columns if you have (or want) one and I'll wire it up.
   ========================================================================= */

$urlParam = $_REQUEST['url'] ?? '';

$stmt = $conn->prepare("SELECT * FROM artist WHERE url = ? LIMIT 1");
$stmt->bind_param('s', $urlParam);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row || $row['url'] != $urlParam) {
    header("HTTP/1.1 404 Not Found");
    echo '<script> window.location.replace("/404") </script>';
    exit();
}

$artistid   = (int)$row['id'];
$artistName = $row['name'];
$today      = date('Y-m-d');

if (!function_exists('hs_slugify')) {
    function hs_slugify($g) {
        return strtolower(trim(preg_replace('/\s+/', '-', $g)));
    }
}

/* ---------- Category lookup table ---------- */
$categoryById = [];
$catRes = $conn->query("SELECT id, cat_name FROM category");
if ($catRes) {
    while ($c = $catRes->fetch_assoc()) {
        $categoryById[$c['id']] = $c['cat_name'];
    }
}

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

/* ---------- This artist's tags: own genre (if populated) else category fallback ---------- */
$ownGenres    = array_values(array_filter(array_map('trim', explode(',', $row['genre'] ?? ''))));
$artistGenres = !empty($ownGenres) ? $ownGenres : hs_artist_categories($conn, $artistid, $categoryById);
$primaryGenre = $artistGenres[0] ?? '';

/* ---------- Upcoming events for this artist ---------- */
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

    if (!empty($ev['start']) && $ev['start'] >= $today) {
        $isUpcoming = true;
        $soonest = $ev['start'];
    }
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

    $min = $ev['min_cost'];
    $max = $ev['max_cost'];
    if ($min === null && $max === null) {
        $priceText = 'TBA';
    } elseif ((float)$min === 0.0 && (float)$max === 0.0) {
        $priceText = 'FREE';
    } elseif ((float)$min === (float)$max) {
        $priceText = '&#8377;' . number_format((float)$min);
    } else {
        $priceText = '&#8377;' . number_format((float)$min) . ' - &#8377;' . number_format((float)$max);
    }

    $catNames = !empty($ev['cat_names']) ? array_values(array_filter(array_map('trim', explode(',', $ev['cat_names'])))) : [];

    $upcomingEvents[] = [
        'title'      => $ev['title'],
        'image'      => $ev['image'],
        'url'        => $ev['eventsurl'],
        'place'      => $ev['placename'],
        'date'       => $soonest,
        'price'      => $priceText,
        'categories' => $catNames,
    ];
}
usort($upcomingEvents, function ($a, $b) { return strcmp($a['date'], $b['date']); });
$upcomingEvents = array_slice($upcomingEvents, 0, 8);

/* ---------- Which artists (site-wide) currently have an upcoming, live event ----------
   Pulled once as a single pass so the "similar artists" ranking below doesn't need a
   per-candidate query. Uses the exact same "is this date upcoming" logic as the
   $upcomingEvents block above (checks both `start` and `multiple_dates`), so an artist
   only counts as "has events" if they'd actually show a gig card on their own page. */
$upcomingArtistIds = [];
$uaStmt = $conn->prepare("
    SELECT event_artist.artist_id, events.start, events.multiple_dates
    FROM event_artist
    INNER JOIN events ON events.id = event_artist.event_id
    WHERE events.status = 1
");
$uaStmt->execute();
$uaResult = $uaStmt->get_result();
while ($ua = $uaResult->fetch_assoc()) {
    $aid = (int)$ua['artist_id'];
    if (isset($upcomingArtistIds[$aid])) continue; // already confirmed upcoming

    $hasUpcoming = false;
    if (!empty($ua['start']) && $ua['start'] >= $today) $hasUpcoming = true;
    if (!$hasUpcoming && !empty($ua['multiple_dates'])) {
        foreach (explode(',', $ua['multiple_dates']) as $d) {
            $d = trim($d);
            if ($d !== '' && $d >= $today) { $hasUpcoming = true; break; }
        }
    }
    if ($hasUpcoming) $upcomingArtistIds[$aid] = true;
}

/* ---------- Similar artists: other active artists, ranked by:
   1) has an upcoming event (shown first), 2) shared categories, 3) most recent id ---------- */
$similarArtists = [];
$saStmt = $conn->prepare("SELECT id, name, url, image_url, genre FROM artist WHERE status = 1 AND id != ?");
$saStmt->bind_param('i', $artistid);
$saStmt->execute();
$saResult = $saStmt->get_result();

$candidates = [];
while ($r = $saResult->fetch_assoc()) {
    $ownG = array_values(array_filter(array_map('trim', explode(',', $r['genre'] ?? ''))));
    $cats = !empty($ownG) ? $ownG : hs_artist_categories($conn, $r['id'], $categoryById);
    $overlap = count(array_intersect($cats, $artistGenres));
    $candidates[] = [
        'id'         => $r['id'],
        'name'       => $r['name'],
        'url'        => $r['url'],
        'image'      => $r['image_url'],
        'categories' => $cats,
        'overlap'    => $overlap,
        'has_events' => isset($upcomingArtistIds[(int)$r['id']]),
    ];
}
usort($candidates, function ($a, $b) {
    if ($a['has_events'] !== $b['has_events']) return $b['has_events'] <=> $a['has_events'];
    if ($a['overlap'] !== $b['overlap']) return $b['overlap'] <=> $a['overlap'];
    return $b['id'] <=> $a['id'];
});
$similarArtists = array_slice($candidates, 0, 4);

/* ---------- Page meta ---------- */
$title = $artistName . ' - Highspinn';
$description = $artistName . ($primaryGenre !== '' ? ' - ' . $primaryGenre . ' DJ on Highspinn' : ' - DJ on Highspinn');
$image = $row['image_url'];
$url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
?>
<?php include 'include/header.php'; ?>

<style>

/* ---------- artist hero ---------- */
.art-hero{ padding:7rem 0 0; position:relative; }
.art-banner{
  position:relative; border-radius:24px; overflow:hidden; height:280px;
  background:linear-gradient(150deg,#3a1440,#160a20);
}

.crumb{ font-size:.8rem; color:var(--muted-2); font-weight:600; margin-bottom:1rem; }
.crumb a{ color:var(--muted); }
.crumb a:hover{ color:var(--pink); }

.art-profile-row{ display:flex; align-items:flex-end; gap:1.5rem; margin-top:-58px; position:relative; z-index:3; padding:0 1.5rem; flex-wrap:wrap; }
.art-avatar{
  width:116px; height:116px; border-radius:22px; border:4px solid var(--void); flex:none;
  background:linear-gradient(150deg,#5c1230,#1c0a14); position:relative; overflow:hidden;
  display:flex; align-items:center; justify-content:center; font-size:2.4rem; font-weight:800; color:#fff;
}
.art-title-block{ flex:1; min-width:220px; padding-bottom:.4rem; }
.art-title-block h1{ font-size:clamp(1.5rem,3vw,2.1rem); margin-bottom:.25rem; }
.art-title-block .genre-tags{ display:flex; gap:.5rem; flex-wrap:wrap; }
.genre-pill{ background:var(--panel-2); border:1px solid var(--line); color:var(--muted); font-size:.76rem; font-weight:700; padding:.3rem .8rem; border-radius:999px; }
.art-actions{ display:flex; gap:.7rem; padding-bottom:.4rem; flex-wrap:wrap; }
.btn-follow{ background:var(--pink); border:none; color:#fff; font-weight:700; padding:.6rem 1.3rem; border-radius:10px; font-size:.85rem; transition:background .2s ease; }
.btn-follow.following{ background:var(--panel-2); border:1px solid var(--line); color:var(--text); }
.btn-icon-circle{ width:42px; height:42px; border-radius:50%; background:var(--panel-2); border:1px solid var(--line); color:var(--text); display:flex; align-items:center; justify-content:center; }
.btn-icon-circle:hover{ border-color:var(--pink); color:var(--pink); }
.btn-icon-circle.liked{ color:var(--pink); border-color:var(--pink); }

/* ---------- stats strip ---------- */
.art-stats{ display:flex; gap:2.2rem; margin-top:1.8rem; flex-wrap:wrap; padding:1.3rem 1.5rem; background:var(--panel); border:1px solid var(--line); border-radius:16px; }
.art-stats .stat{ text-align:left; }
.art-stats .stat .num{ font-size:1.3rem; font-weight:800; }
.art-stats .stat small{ color:var(--muted); font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }

/* ---------- bio ---------- */
section{ padding:3rem 0; }
.section-head{ display:flex; align-items:flex-end; justify-content:space-between; margin-bottom:1.6rem; gap:1rem; flex-wrap:wrap; }
.section-head h2{ font-size:clamp(1.3rem,2.2vw,1.7rem); margin:0; }
.bio-text{ color:var(--muted); max-width:760px; line-height:1.7; }

/* ---------- socials ---------- */
.social-row{ display:flex; gap:.7rem; flex-wrap:wrap; }
.social-chip{ display:inline-flex; align-items:center; gap:.5rem; background:var(--panel-2); border:1px solid var(--line); border-radius:999px; padding:.5rem 1rem; font-size:.82rem; font-weight:600; color:var(--text); }
.social-chip:hover{ border-color:var(--pink); color:var(--pink); }

/* ---------- event card (shared) ---------- */
.evt-card{ background:var(--panel); border:1px solid var(--line); border-radius:18px; overflow:hidden; height:100%; transition:transform .2s ease, border-color .2s ease; }
.evt-card:hover{ transform:translateY(-4px); border-color:var(--muted-2); }
.evt-media{ position:relative; aspect-ratio:4/3.1; overflow:hidden; }
.evt-media::after{ content:""; position:absolute; inset:0; background:linear-gradient(0deg, rgba(0,0,0,.55), transparent 45%); }
.price-tag{ position:absolute; top:10px; left:10px; z-index:2; background:rgba(10,10,13,.7); backdrop-filter:blur(6px); border:1px solid rgba(255,201,77,.4); color:var(--yellow); font-size:.7rem; font-weight:800; padding:.25rem .6rem; border-radius:999px; }
.heart-btn{ position:absolute; top:10px; right:10px; z-index:2; width:28px; height:28px; border-radius:50%; background:rgba(10,10,13,.6); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; color:#fff; font-size:.75rem; border:1px solid rgba(255,255,255,.15); }
.cat-tag{ position:absolute; bottom:10px; left:10px; z-index:2; color:#fff; font-size:.72rem; font-weight:700; background:rgba(10,10,13,.55); padding:.2rem .6rem; border-radius:6px; }
.evt-body{ padding:1rem 1.1rem 1.15rem; }
.evt-meta{ font-size:.75rem; color:var(--muted-2); font-weight:600; margin-bottom:.4rem; display:flex; align-items:center; gap:.35rem; }
.evt-body h5{ font-size:.92rem; font-weight:700; margin-bottom:.35rem; line-height:1.3; color:var(--text); }
.evt-venue{ font-size:.78rem; color:var(--muted); margin-bottom:.85rem; display:flex; align-items:center; gap:.35rem; }


/* ---------- similar artists ---------- */
.art-card{ position:relative; border-radius:16px; overflow:hidden; aspect-ratio:1/1.15; border:1px solid var(--line); display:flex; flex-direction:column; justify-content:flex-end; padding:.9rem; }
.art-card::before{ content:""; position:absolute; inset:0; background:linear-gradient(0deg, rgba(0,0,0,.8) 15%, transparent 60%); z-index:1; }
.art-card h5{ position:relative; z-index:2; font-size:.92rem; margin:0; color:#fff; }
.art-card small{ position:relative; z-index:2; color:rgba(255,255,255,.7); font-size:.72rem; font-weight:600; }
.art-card .has-events-badge{ position:relative; z-index:2; display:inline-flex; align-items:center; gap:.3rem; background:rgba(255,61,104,.18); border:1px solid rgba(255,61,104,.4); color:#ff6b8f; font-size:.68rem; font-weight:800; padding:.18rem .5rem; border-radius:999px; margin-bottom:.35rem; width:fit-content; }

.empty-note{ color:var(--muted); font-size:.9rem; }

footer{ background:var(--void); border-top:1px solid var(--line); padding:2.5rem 0 1.5rem; margin-top:1rem; }
footer .foot-bottom{ font-size:.8rem; color:var(--muted-2); display:flex; justify-content:space-between; flex-wrap:wrap; gap:.5rem; }
footer .foot-bottom a{ color:var(--muted); }

@media (max-width:767.98px){
  .art-banner{ height:190px; }
  .art-profile-row{ margin-top:-42px; }
  .art-avatar{ width:88px; height:88px; font-size:1.8rem; }
}
</style>

<div class="art-hero">
  <div class="container">
    <div class="crumb"><a href="/">Home</a> &rarr; <a href="/artists">Artists</a> &rarr; <span class="text-muted-c"><?php echo htmlspecialchars($artistName); ?></span></div>

    <div class="art-banner"<?php if (!empty($image)): ?> style="background-image:url('<?php echo htmlspecialchars($image); ?>');background-size:cover;background-position:center;"<?php endif; ?>>
      <div class="streaks"></div>
    </div>

    <div class="art-profile-row">
      <div class="art-avatar"<?php if (!empty($image)): ?> style="background-image:url('<?php echo htmlspecialchars($image); ?>');background-size:cover;background-position:center;"<?php endif; ?>>
        <div class="streaks"></div>
        <?php if (empty($image)): ?><span style="position:relative; z-index:2;"><?php echo htmlspecialchars(strtoupper(substr($artistName, 0, 1))); ?></span><?php endif; ?>
      </div>
      <div class="art-title-block">
        <h1><?php echo htmlspecialchars($artistName); ?></h1>
        <div class="genre-tags">
          <?php foreach ($artistGenres as $i => $g): ?>
          <span class="genre-pill"><?php if ($i === 0): ?><i class="fa-solid fa-music me-1"></i><?php endif; ?><?php echo htmlspecialchars($g); ?></span>
          <?php endforeach; ?>
          <span class="genre-pill"><i class="fa-solid fa-location-dot me-1"></i>Bangalore</span>
        </div>
      </div>
      <div class="art-actions">
        <button type="button" class="btn-icon-circle" id="likeBtn"><i class="fa-regular fa-heart"></i></button>
        <button type="button" class="btn-icon-circle" id="shareBtn"><i class="fa-solid fa-share-nodes"></i></button>
        <button type="button" class="btn-follow" id="followBtn">Follow</button>
      </div>
    </div>

    <div class="art-stats">
      <div class="stat"><div class="num"><?php echo count($upcomingEvents); ?></div><small>Upcoming gigs</small></div>
    </div>
  </div>
</div>

<!-- ================= ABOUT ================= -->
<section>
  <div class="container">
    <span class="eyebrow">About</span>
    <h2 class="mb-3">About <?php echo htmlspecialchars($artistName); ?></h2>
    <?php if (!empty($row['description'])): ?>
    <p class="bio-text"><?php echo nl2br(htmlspecialchars($row['description'])); ?></p>
    <?php else: ?>
    <p class="bio-text empty-note">This artist hasn't added a bio yet.</p>
    <?php endif; ?>

    <?php if (!empty($row['insta']) || !empty($row['fb'])): ?>
    <div class="social-row mt-3">
      <?php if (!empty($row['insta'])): ?><a href="<?php echo htmlspecialchars($row['insta']); ?>" target="_blank" rel="noopener" class="social-chip"><i class="fa-brands fa-instagram"></i> Instagram</a><?php endif; ?>
      <?php if (!empty($row['fb'])): ?><a href="<?php echo htmlspecialchars($row['fb']); ?>" target="_blank" rel="noopener" class="social-chip"><i class="fa-brands fa-facebook-f"></i> Facebook</a><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ================= UPCOMING EVENTS ================= -->
<section id="artistEvents">
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">On the decks</span>
        <h2>Upcoming gigs</h2>
      </div>
      <a href="/events" class="link-arrow">All events &rarr;</a>
    </div>
    <?php if (!empty($upcomingEvents)): ?>
    <div class="row g-4">
      <?php foreach ($upcomingEvents as $i => $ev):
          $ecClass = 'ec' . (($i % 12) + 1);
          $dateObj = date_create($ev['date']);
          $dateLabel = $dateObj ? date_format($dateObj, 'D, d M') : '';
          $catTag = $ev['categories'][0] ?? '';
      ?>
      <div class="col-sm-6 col-lg-3">
        <div class="evt-card">
          <a href="/events/<?php echo htmlspecialchars($ev['url']); ?>">
          <div class="evt-media <?php echo $ecClass; ?>"<?php if (!empty($ev['image'])): ?> style="background-image:url('<?php echo htmlspecialchars($ev['image']); ?>');background-size:cover;background-position:center;"<?php endif; ?>>
            <div class="streaks"></div>
            <span class="price-tag"><?php echo $ev['price']; ?></span>
            <span class="heart-btn"><i class="fa-regular fa-heart"></i></span>
            <?php if ($catTag !== ''): ?><span class="cat-tag"><?php echo htmlspecialchars($catTag); ?></span><?php endif; ?>
          </div>
          </a>
          <div class="evt-body">
            <div class="evt-meta"><i class="fa-regular fa-clock"></i><?php echo htmlspecialchars($dateLabel); ?></div>
            <h5><a href="/events/<?php echo htmlspecialchars($ev['url']); ?>" class="text-decoration-none" style="color:inherit;"><?php echo htmlspecialchars($ev['title']); ?></a></h5>
            <div class="evt-venue"><i class="fa-solid fa-location-dot"></i><?php echo htmlspecialchars($ev['place']); ?></div>
            <a href="/events/<?php echo htmlspecialchars($ev['url']); ?>" class="btn-pass">Get pass <i class="fa-solid fa-arrow-right"></i></a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="empty-note">No upcoming events found for this artist yet.</p>
    <?php endif; ?>
  </div>
</section>

<!-- ================= SIMILAR ARTISTS ================= -->
<?php if (!empty($similarArtists)): ?>
<section>
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">You might also like</span>
        <h2>Similar artists</h2>
      </div>
    </div>
    <div class="row g-3">
      <?php foreach ($similarArtists as $i => $sa):
          $ecClass = 'ec' . (($i % 12) + 1);
          $simLabel = implode(' &middot; ', array_slice($sa['categories'], 0, 2));
      ?>
      <div class="col-6 col-md-3">
        <a href="/artists/<?php echo htmlspecialchars($sa['url']); ?>" class="text-decoration-none">
        <div class="art-card <?php echo $ecClass; ?>"<?php if (!empty($sa['image'])): ?> style="background-image:url('<?php echo htmlspecialchars($sa['image']); ?>');background-size:cover;background-position:center;"<?php endif; ?>>
          <div class="streaks"></div>
          <span class="heart-btn"><i class="fa-regular fa-heart"></i></span>
          <?php if ($sa['has_events']): ?><span class="has-events-badge"><i class="fa-solid fa-calendar-check"></i> Playing now</span><?php endif; ?>
          <h5><?php echo htmlspecialchars($sa['name']); ?></h5>
          <?php if ($simLabel !== ''): ?><small><?php echo $simLabel; ?></small><?php endif; ?>
        </div></a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  document.getElementById('followBtn').addEventListener('click', function(){
    var following = this.classList.toggle('following');
    this.textContent = following ? 'Following' : 'Follow';
  });

  document.getElementById('likeBtn').addEventListener('click', function(){
    var icon = this.querySelector('i');
    icon.classList.toggle('fa-regular');
    icon.classList.toggle('fa-solid');
    this.classList.toggle('liked', icon.classList.contains('fa-solid'));
  });

  document.getElementById('shareBtn').addEventListener('click', function(){
    if (navigator.share){
      navigator.share({ title: <?php echo json_encode($artistName . ' on HighSpinn'); ?>, url: window.location.href }).catch(function(){});
    } else {
      navigator.clipboard && navigator.clipboard.writeText(window.location.href);
      alert('Link copied to clipboard!');
    }
  });

  document.querySelectorAll('.heart-btn').forEach(function(h){
    h.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation();
      var icon = h.querySelector('i');
      icon.classList.toggle('fa-regular');
      icon.classList.toggle('fa-solid');
      h.style.color = icon.classList.contains('fa-solid') ? '#ff3d68' : '#fff';
    });
  });
})();
</script>

<?php include 'include/footer.php'; ?>