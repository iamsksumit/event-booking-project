<?php 
include 'include/config.php'; 
include 'include/header.php'; 

// ================= Get place data =================
$id = $_REQUEST['url'];
$stmt = $conn->prepare("SELECT * FROM place WHERE url = ?");
$stmt->bind_param('s', $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if (!$row) {
    header("HTTP/1.1 404 Not Found");
    echo '<script> window.location.replace("/404") </script>';
    exit();
}

$placeid = $row['id'];

// Page meta
$title       = $row['name'] . ' - Highspinn';
$description = $row['name'] . ' - ' . $row['address'] . ' Now on Highspinn';
$keywords    = $row['name'];
$image       = $row['image_url'];
$url         = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// ================= Fetch events for this place =================
$eventsStmt = $conn->prepare("
    SELECT events.*, events.url as eventsurl, place.name as placename,
    (SELECT MIN(ticket_cost) FROM event_tickets WHERE event_id = events.id) as min_cost,
    (SELECT MAX(ticket_cost) FROM event_tickets WHERE event_id = events.id) as max_cost
    FROM events 
    INNER JOIN place ON events.sub_tittle = place.id
    WHERE events.status = 1 AND place.id = ?
    ORDER BY events.start ASC LIMIT 10
");
$eventsStmt->bind_param('i', $placeid);
$eventsStmt->execute();
$eventsQuery = $eventsStmt->get_result();

// ================= Fetch similar / nearby venues =================
// Same city as the venue being viewed. Venues WITH an active/upcoming event are shown first,
// but venues with no current events in that city are still included to fill out the list.
// Assumes 'place' has a 'city' column — adjust the column name below if yours differs.
$similarStmt = $conn->prepare("
    SELECT place.id, place.name, place.url, place.image_url, place.city,
           MAX(CASE WHEN events.status = 1 AND events.start >= NOW() THEN 1 ELSE 0 END) AS has_active_event
    FROM place
    LEFT JOIN events ON events.sub_tittle = place.id
    WHERE place.city = ?
      AND place.id != ?
    GROUP BY place.id, place.name, place.url, place.image_url, place.city
    ORDER BY has_active_event DESC, RAND()
    LIMIT 4
");
$similarStmt->bind_param('si', $row['city'], $placeid);
$similarStmt->execute();
$similarQuery = $similarStmt->get_result();

// Reusable helper for alternating card background classes
function gvClass($i) {
    $classes = ['gv1', 'gv2', 'gv3'];
    return $classes[$i % count($classes)];
}
function catClass($i) {
    $classes = ['cat-c1', 'cat-c6', 'cat-c8'];
    return $classes[$i % count($classes)];
}
function ecClass($i) {
    $classes = ['ec2', 'ec4'];
    return $classes[$i % count($classes)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title); ?></title>
<meta name="description" content="<?php echo htmlspecialchars($description); ?>">
<meta name="keywords" content="<?php echo htmlspecialchars($keywords); ?>">
<meta property="og:image" content="<?php echo htmlspecialchars($image); ?>">
<meta property="og:url" content="<?php echo htmlspecialchars($url); ?>">

<style>

/* ---------- venue hero gallery ---------- */
.ven-hero{ padding:7rem 0 0; }
.crumb{ font-size:.8rem; color:var(--muted-2); font-weight:600; margin-bottom:1rem; }
.crumb a{ color:var(--muted); }
.crumb a:hover{ color:var(--pink); }

.ven-gallery{ display:grid; grid-template-columns:1.6fr 1fr; grid-template-rows:1fr 1fr; gap:.6rem; height:380px; border-radius:22px; overflow:hidden; }
.ven-gallery .g-main{ grid-row:1/3; position:relative; overflow:hidden; }
.ven-gallery .g-main img{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:center; z-index:0; }
.ven-gallery .g-sub{ position:relative; overflow:hidden; }
.gv1{ background:linear-gradient(150deg,#5c1f12,#20100a); }
.gv2{ background:linear-gradient(150deg,#123a52,#0a1620); }
.gv3{ background:linear-gradient(150deg,#3a1440,#160a20); }
.view-all-photos{
  position:absolute; bottom:14px; right:14px; z-index:3;
  background:rgba(10,10,13,.7); backdrop-filter:blur(6px); border:1px solid rgba(255,255,255,.15);
  color:#fff; font-size:.78rem; font-weight:700; padding:.5rem 1rem; border-radius:10px;
}
.view-all-photos:hover{ color:#fff; background:var(--pink); border-color:var(--pink); }

/* ---------- venue header info ---------- */
.ven-info-row{ display:flex; align-items:flex-start; justify-content:space-between; gap:1.5rem; margin-top:1.6rem; flex-wrap:wrap; }
.ven-info-row h1{ font-size:clamp(1.5rem,3vw,2.1rem); margin-bottom:.4rem; }
.ven-meta-line{ font-size:.86rem; color:var(--muted); display:flex; align-items:center; gap:1.2rem; flex-wrap:wrap; }
.ven-meta-line span{ display:inline-flex; align-items:center; gap:.4rem; }
.rating-pill{ background:rgba(255,201,77,.12); border:1px solid rgba(255,201,77,.35); color:var(--yellow); font-weight:800; padding:.3rem .7rem; border-radius:999px; font-size:.85rem; }
.ven-actions{ display:flex; gap:.7rem; flex-wrap:wrap; }
.btn-icon-circle{ width:42px; height:42px; border-radius:50%; background:var(--panel-2); border:1px solid var(--line); color:var(--text); display:flex; align-items:center; justify-content:center; }
.btn-icon-circle:hover{ border-color:var(--pink); color:var(--pink); }
.btn-icon-circle.liked{ color:var(--pink); border-color:var(--pink); }

/* ---------- amenities ---------- */
section{ padding:3rem 0; }
.section-head{ display:flex; align-items:flex-end; justify-content:space-between; margin-bottom:1.6rem; gap:1rem; flex-wrap:wrap; }
.section-head h2{ font-size:clamp(1.3rem,2.2vw,1.7rem); margin:0; }
.bio-text{ color:var(--muted); max-width:760px; line-height:1.7; }
.amenity-chip{ display:inline-flex; align-items:center; gap:.5rem; background:var(--panel-2); border:1px solid var(--line); border-radius:999px; padding:.5rem 1rem; font-size:.82rem; font-weight:600; color:var(--text); }
.amenity-chip i{ color:var(--pink); }

/* ---------- info panel ---------- */
.panel-card{ background:var(--panel); border:1px solid var(--line); border-radius:18px; padding:1.5rem; }
.panel-card h6{ font-size:.72rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--muted-2); margin-bottom:1.1rem; }
.info-line{ display:flex; gap:.7rem; font-size:.85rem; color:var(--muted); padding:.6rem 0; border-bottom:1px solid var(--line); }
.info-line:last-child{ border-bottom:none; }
.info-line i{ color:var(--pink); width:18px; text-align:center; margin-top:.1rem; }
.info-line b{ color:var(--text); display:block; }
.map-box{
  height:170px; border-radius:14px; overflow:hidden; position:relative; margin-bottom:1.1rem;
}
.map-box iframe{ width:100%; height:100%; border:0; }

/* ---------- event card (shared) ---------- */
.evt-card{ background:var(--panel); border:1px solid var(--line); border-radius:18px; overflow:hidden; height:100%; transition:transform .2s ease, border-color .2s ease; }
.evt-card:hover{ transform:translateY(-4px); border-color:var(--muted-2); }
.evt-media{ position:relative; aspect-ratio:4/3.1; overflow:hidden; }
.evt-media img{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:center; z-index:0; }
.evt-media::after{ content:""; position:absolute; inset:0; z-index:1; background:linear-gradient(0deg, rgba(0,0,0,.55), transparent 45%); }
.price-tag{ position:absolute; top:10px; left:10px; z-index:2; background:rgba(10,10,13,.7); backdrop-filter:blur(6px); border:1px solid rgba(255,201,77,.4); color:var(--yellow); font-size:.7rem; font-weight:800; padding:.25rem .6rem; border-radius:999px; }
.heart-btn{ position:absolute; top:10px; right:10px; z-index:2; width:28px; height:28px; border-radius:50%; background:rgba(10,10,13,.6); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; color:#fff; font-size:.75rem; border:1px solid rgba(255,255,255,.15); }
.cat-tag{ position:absolute; bottom:10px; left:10px; z-index:2; color:#fff; font-size:.72rem; font-weight:700; background:rgba(10,10,13,.55); padding:.2rem .6rem; border-radius:6px; }
.evt-body{ padding:1rem 1.1rem 1.15rem; }
.evt-meta{ font-size:.75rem; color:var(--muted-2); font-weight:600; margin-bottom:.4rem; display:flex; align-items:center; gap:.35rem; }
.evt-body h5{ font-size:.92rem; font-weight:700; margin-bottom:.35rem; line-height:1.3; color:var(--text); }
.evt-venue{ font-size:.78rem; color:var(--muted); margin-bottom:.85rem; display:flex; align-items:center; gap:.35rem; }


/* ---------- similar venues ---------- */
.ven-card{ position:relative; border-radius:16px; overflow:hidden; aspect-ratio:1/0.95; border:1px solid var(--line); display:flex; flex-direction:column; justify-content:flex-end; padding:.9rem; }
.ven-card-img{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:center; z-index:0; }
.ven-card::before{ content:""; position:absolute; inset:0; background:linear-gradient(0deg, rgba(0,0,0,.8) 20%, transparent 60%); z-index:1; }
.rating-badge{ position:absolute; top:10px; left:10px; z-index:2; background:rgba(10,10,13,.65); backdrop-filter:blur(6px); color:var(--yellow); font-size:.72rem; font-weight:800; padding:.25rem .55rem; border-radius:999px; display:flex; align-items:center; gap:.25rem; }
.ven-card h5{ position:relative; z-index:2; font-size:.92rem; margin:0; color:#fff; }
.ven-card small{ position:relative; z-index:2; color:rgba(255,255,255,.7); font-size:.72rem; font-weight:600; }
.cat-c1{ background:linear-gradient(160deg,#3a1440,#160a20); }
.cat-c6{ background:linear-gradient(160deg,#4a3210,#1c1408); }
.cat-c8{ background:linear-gradient(160deg,#5c1030,#200a14); }

footer{ background:var(--void); border-top:1px solid var(--line); padding:2.5rem 0 1.5rem; margin-top:1rem; }
footer .foot-bottom{ font-size:.8rem; color:var(--muted-2); display:flex; justify-content:space-between; flex-wrap:wrap; gap:.5rem; }
footer .foot-bottom a{ color:var(--muted); }

@media (max-width:767.98px){
  .ven-gallery{ height:260px; grid-template-columns:1fr; grid-template-rows:1fr; }
  .ven-gallery .g-sub{ display:none; }
  .ven-gallery .g-main{ grid-row:auto; }
}
</style>
</head>
<body>

<div class="ven-hero">
  <div class="container">
    <div class="crumb">
      <a href="/index">Home</a> &rarr; <a href="/venues">Venues</a> &rarr;
      <span class="text-muted-c"><?php echo htmlspecialchars($row['name']); ?></span>
    </div>

    <div class="ven-gallery">
      <div class="g-main">
        <?php if (!empty($row['image_url'])): ?>
          <img src="<?php echo htmlspecialchars($row['image_url']); ?>" alt="<?php echo htmlspecialchars($row['name']); ?>">
        <?php endif; ?>
        <div class="streaks"></div>
        <a href="#" class="view-all-photos"><i class="fa-solid fa-images me-1"></i> View photos</a>
      </div>
      <!-- No secondary photos in the DB yet — add image_url2 / image_url3 columns to make these dynamic too -->
      <div class="g-sub gv2"><div class="streaks"></div></div>
      <div class="g-sub gv3"><div class="streaks"></div></div>
    </div>

    <div class="ven-info-row">
      <div>
        <h1><?php echo htmlspecialchars($row['name']); ?></h1>
        <div class="ven-meta-line">
          <span><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($row['address']); ?></span>
          <?php if (!empty($row['rating'])): ?>
            <span class="rating-pill"><i class="fa-solid fa-star"></i> <?php echo htmlspecialchars($row['rating']); ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="ven-actions">
        <button type="button" class="btn-icon-circle" id="likeBtn" data-place-id="<?php echo (int)$placeid; ?>">
          <i class="fa-regular fa-heart"></i>
        </button>
        <button type="button" class="btn-icon-circle" id="shareBtn">
          <i class="fa-solid fa-share-nodes"></i>
        </button>
        <a href="/events" class="btn-pink">See upcoming events</a>
      </div>
    </div>
  </div>
</div>

<!-- ================= ABOUT + INFO ================= -->
<section>
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-8">
        <span class="eyebrow">About the venue</span>
        <h2 class="mb-3"><?php echo htmlspecialchars($row['name']); ?></h2>
        <p class="bio-text">
          <?php
            // If your 'place' table has a description column, use it; otherwise fall back to a generic line.
            echo !empty($row['description'])
                ? nl2br(htmlspecialchars($row['description']))
                : htmlspecialchars($row['name'] . ' is located at ' . $row['address'] . '.');
          ?>
        </p>

        <!-- Amenities aren't in the current query — wire these to a real 'amenities' column/table -->
        <div class="d-flex flex-wrap gap-2 mt-3">
          <?php if (!empty($row['amenities'])): ?>
            <?php foreach (explode(',', $row['amenities']) as $amenity): ?>
              <span class="amenity-chip"><i class="fa-solid fa-check"></i> <?php echo htmlspecialchars(trim($amenity)); ?></span>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="panel-card">
          <h6><i class="fa-regular fa-clock me-1"></i> Location</h6>
          <div class="map-box">
            <iframe
              src="https://www.google.com/maps?q=<?php echo urlencode($row['address']); ?>&output=embed"
              loading="lazy"
              referrerpolicy="no-referrer-when-downgrade">
            </iframe>
          </div>
          <div class="info-line">
            <i class="fa-solid fa-location-dot"></i>
            <div>
              <b><?php echo htmlspecialchars($row['name']); ?></b>
              <?php echo htmlspecialchars($row['address']); ?>
            </div>
          </div>
          <a target="_blank" class="green_text" href="https://www.google.com/maps?q=<?php echo urlencode($row['address']); ?>">
            Get directions on map &gt;
          </a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================= UPCOMING EVENTS ================= -->
<section id="venueEvents">
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">This week</span>
        <h2>Upcoming events here</h2>
      </div>
      <a href="/events" class="link-arrow">All events &rarr;</a>
    </div>

    <?php if ($eventsQuery && $eventsQuery->num_rows > 0): ?>
      <div class="row g-4">
        <?php $i = 0; while ($ev = $eventsQuery->fetch_assoc()):
          $startDate  = new DateTime($ev['start']);
          $displayDate = $startDate->format('D, d M g:i A');
          $minCost = $ev['min_cost'];
          $maxCost = $ev['max_cost'];
          $priceText = ($minCost == 0 && $maxCost == 0) ? 'FREE' : '&#8377;' . $minCost . ' - &#8377;' . $maxCost;
          $category  = !empty($ev['category']) ? $ev['category'] : 'Event';
        ?>
        <div class="col-sm-6 col-lg-3">
          <div class="evt-card">
            <a href="/events/<?php echo htmlspecialchars($ev['eventsurl']); ?>" style="text-decoration:none;">
              <div class="evt-media <?php echo ecClass($i); ?>">
                <?php if (!empty($ev['image'])): ?>
                  <img src="<?php echo htmlspecialchars($ev['image']); ?>" alt="<?php echo htmlspecialchars($ev['tittle']); ?>">
                <?php endif; ?>
                <div class="streaks"></div>
                <span class="price-tag"><?php echo $priceText; ?></span>
                <span class="heart-btn" data-event-id="<?php echo (int)$ev['id']; ?>"><i class="fa-regular fa-heart"></i></span>
                <span class="cat-tag"><?php echo htmlspecialchars($category); ?></span>
              </div>
            </a>
            <div class="evt-body">
              <div class="evt-meta"><i class="fa-regular fa-clock"></i><?php echo $displayDate; ?></div>
              <h5><?php echo htmlspecialchars($ev['tittle']); ?></h5>
              <div class="evt-venue"><i class="fa-solid fa-location-dot"></i><?php echo htmlspecialchars($ev['placename']); ?></div>
              <a href="/events/<?php echo htmlspecialchars($ev['eventsurl']); ?>" class="btn-pass">
                Get pass <i class="fa-solid fa-arrow-right"></i>
              </a>
            </div>
          </div>
        </div>
        <?php $i++; endwhile; ?>
      </div>
    <?php else: ?>
      <p>No upcoming events at this venue right now.</p>
    <?php endif; ?>
  </div>
</section>

<!-- ================= SIMILAR VENUES ================= -->
<section>
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">Nearby</span>
        <h2>Similar venues</h2>
      </div>
    </div>

    <?php if ($similarQuery && $similarQuery->num_rows > 0): ?>
      <div class="row g-3">
        <?php $j = 0; while ($sv = $similarQuery->fetch_assoc()): ?>
          <div class="col-6 col-md-3">
            <a href="/venues/<?php echo htmlspecialchars($sv['url']); ?>" class="text-decoration-none">
              <div class="ven-card <?php echo catClass($j); ?>">
                <?php if (!empty($sv['image_url'])): ?>
                  <img class="ven-card-img" src="<?php echo htmlspecialchars($sv['image_url']); ?>" alt="<?php echo htmlspecialchars($sv['name']); ?>" loading="lazy">
                <?php endif; ?>
                <div class="streaks"></div>
                <?php if (!empty($sv['rating'])): ?>
                  <span class="rating-badge"><i class="fa-solid fa-star"></i> <?php echo htmlspecialchars($sv['rating']); ?></span>
                <?php endif; ?>
                <span class="heart-btn" data-place-id="<?php echo (int)$sv['id']; ?>"><i class="fa-regular fa-heart"></i></span>
                <h5><?php echo htmlspecialchars($sv['name']); ?></h5>
                <small><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($sv['city']); ?></small>
              </div>
            </a>
          </div>
        <?php $j++; endwhile; ?>
      </div>
    <?php else: ?>
      <p>No similar venues found.</p>
    <?php endif; ?>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  var venueName = <?php echo json_encode($row['name']); ?>;

  var likeBtn = document.getElementById('likeBtn');
  if (likeBtn) {
    likeBtn.addEventListener('click', function(){
      var icon = this.querySelector('i');
      icon.classList.toggle('fa-regular');
      icon.classList.toggle('fa-solid');
      this.classList.toggle('liked', icon.classList.contains('fa-solid'));
      // TODO: fire an AJAX call here (e.g. fetch('/api/like.php', {method:'POST', body: JSON.stringify({place_id: this.dataset.placeId})}))
      // to persist the like server-side.
    });
  }

  var shareBtn = document.getElementById('shareBtn');
  if (shareBtn) {
    shareBtn.addEventListener('click', function(){
      if (navigator.share){
        navigator.share({ title: venueName + ' on HighSpinn', url: window.location.href }).catch(function(){});
      } else {
        navigator.clipboard && navigator.clipboard.writeText(window.location.href);
        alert('Link copied to clipboard!');
      }
    });
  }

  document.querySelectorAll('.heart-btn').forEach(function(h){
    h.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation();
      var icon = h.querySelector('i');
      icon.classList.toggle('fa-regular');
      icon.classList.toggle('fa-solid');
      h.style.color = icon.classList.contains('fa-solid') ? '#ff3d68' : '#fff';
      // TODO: persist like state for h.dataset.eventId / h.dataset.placeId via AJAX.
    });
  });
})();
</script>

<?php include 'include/footer.php'; ?>
</body>
</html>