<?php
include('include/config.php'); ?>

<?php
// Fetch data based on the page type
$title = 'Bangalore Nightlife | Bollywood Night in Bangalore - HIGHSPINN';
$description = 'Explore and experience events and activities in Bangalore in the most amazing way ever.Find out the best events, parties, outdoor adventure, treks, activities and venues around the city and create amazing memories with your friends and family.';
// $keywords = $row['name'];
$image = 'https://www.highspinn.com/img/logo.png';
$url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

if (isset($pageData)) {
	$title = htmlspecialchars($pageData['title'] ?? $title);
	$description = htmlspecialchars($pageData['description'] ?? $description);
	// $keywords = htmlspecialchars($pageData['keywords'] ?? $keywords);
	$image = htmlspecialchars($pageData['image'] ?? $image);
	$url = htmlspecialchars($pageData['url'] ?? $url);
}
?>

 
<?php include 'include/header.php'; ?>
<?php include 'data/update_event_status.php'; ?>

<?php
/* ---------------------------------------------------------------------
   $currentCityId / $currentCityName / $currentCitySlug / $currentCityEsc
   and hs_city_url() are all set up inside include/header.php (which
   pulls in include/city.php). Every query below is scoped to
   $currentCityEsc so the homepage only ever shows this city's data.

   NOTE: this assumes:
     - place.city            holds the city name as text (matched
                              case-insensitively against tbl_cities.name)
     - artist.city  (NEW)    optional text column so an artist's home
                              base can be shown even without an event yet
       If `artist.city` doesn't exist yet, run:
         ALTER TABLE artist ADD COLUMN city VARCHAR(150) NULL;
       The artist section below degrades gracefully (city-of-origin
       matching just won't apply) if the column is missing.
   --------------------------------------------------------------------- */

// Does the artist table have a `city` column? Checked once, used below.
$artistHasCityCol = false;
$colCheck = $conn->query("SHOW COLUMNS FROM `artist` LIKE 'city'");
if ($colCheck && $colCheck->num_rows > 0) { $artistHasCityCol = true; }
?>

<style>


/* ---------- hero ---------- */
.hero{ padding:8.5rem 0 4rem; position:relative; }
.hero-eyebrow-pill{
  display:inline-flex; align-items:center; gap:.5rem;
  background:var(--panel-2); border:1px solid var(--line); border-radius:999px;
  padding:.4rem 1rem; font-size:.75rem; font-weight:700; color:var(--muted);
}
.hero-eyebrow-pill .dot{ width:6px; height:6px; border-radius:50%; background:var(--pink); }
.hero h1{ font-size:clamp(2.4rem,5vw,3.6rem); line-height:1.05; margin:1.1rem 0 1.2rem; }
.avatar-stack{ display:flex; align-items:center; }
.avatar-stack .av{
  width:34px; height:34px; border-radius:50%; border:2px solid var(--void);
  margin-left:-10px; background:linear-gradient(135deg,var(--pink),var(--orange));
  display:flex; align-items:center; justify-content:center; font-size:.7rem; font-weight:700; color:#fff;
}
.avatar-stack .av:first-child{ margin-left:0; }

.hero-media{
  position:relative; border-radius:22px; overflow:hidden;
  aspect-ratio:4/3.2;
  box-shadow:0 30px 60px rgba(0,0,0,.5);
}

.hero-slide{
    position:absolute;
    inset:0;

    opacity:0;
    visibility:hidden;

    transition:opacity .6s ease;

    background-size:cover;
    background-position:center center;
    background-repeat:no-repeat;
}

.hero-slide.active{
    opacity:1;
    visibility:visible;
    z-index:1;
}

.hero-tags{ position:absolute; top:16px; left:16px; display:flex; gap:.5rem; z-index:3; }
.tag-pill{
  background:rgba(10,10,13,.6); backdrop-filter:blur(6px);
  border:1px solid rgba(255,255,255,.15); color:#fff;
  font-size:.72rem; font-weight:700; padding:.3rem .7rem; border-radius:999px;
}
.hero-card{
  position:absolute; left:16px; right:16px; bottom:16px; z-index:3;
  background:rgba(10,10,13,.65); backdrop-filter:blur(10px);
  border:1px solid rgba(255,255,255,.12); border-radius:16px; padding:1rem 1.15rem;
}
.hero-card .meta{ font-size:.72rem; color:var(--muted); font-weight:600; margin-bottom:.3rem; }
.hero-card h5{ font-size:1rem; font-weight:800; margin-bottom:.15rem; }
.hero-card .venue{ font-size:.78rem; color:var(--muted); margin-bottom:.7rem; }
.hero-nav-btn{
  position:absolute; top:50%; transform:translateY(-50%); z-index:4;
  width:38px; height:38px; border-radius:50%;
  background:rgba(10,10,13,.55); backdrop-filter:blur(6px);
  border:1px solid rgba(255,255,255,.15); color:#fff;
  display:flex; align-items:center; justify-content:center; font-size:.85rem;
  opacity:0; transition:opacity .2s ease, background .2s ease;
}
.hero-media:hover .hero-nav-btn{ opacity:1; }
.hero-nav-btn:hover{ background:var(--pink); border-color:var(--pink); }
.hero-nav-prev{ left:14px; }
.hero-nav-next{ right:14px; }
.hero-dots{ position:absolute; bottom:16px; right:20px; z-index:4; display:flex; gap:.35rem; }
.hero-dots button{
  width:6px; height:6px; padding:0; border-radius:50%; background:rgba(255,255,255,.3);
  border:none; cursor:pointer; transition:width .25s ease, background .25s ease;
}
.hero-dots button.active{ width:18px; border-radius:4px; background:var(--pink); }

/* ---------- section shell ---------- */
section{ padding:2rem 0; }
.section-head{
  display:flex; align-items:flex-end; justify-content:space-between; margin-bottom:2rem; gap:1rem; flex-wrap:wrap;
}
.section-head h2{ font-size:clamp(1.5rem,2.6vw,2rem); margin:0; }
.filter-pills{ display:flex; gap:.5rem; flex-wrap:wrap; }
.filter-pill{
  background:var(--panel-2); border:1px solid var(--line); color:var(--muted);
  font-size:.8rem; font-weight:700; padding:.45rem 1rem; border-radius:999px;
}
.filter-pill.active{ background:var(--pink); border-color:var(--pink); color:#fff; }

/* ---------- category cards ---------- */
.cat-card{
    position:relative;
    border-radius:18px;
    overflow:hidden;
    aspect-ratio:1/1.05;
    border:1px solid var(--line);

    display:flex;
    flex-direction:column;
    justify-content:flex-end;

    padding:1.1rem;
    color:#fff;

    background-size:cover;
    background-position:center;
    background-repeat:no-repeat;

    transition:.35s ease;
}

.cat-card:hover{
    transform:translateY(-6px);
    box-shadow:0 18px 40px rgba(0,0,0,.35);
}

.cat-card::before{
    content:"";
    position:absolute;
    inset:0;

    background:
    linear-gradient(
        180deg,
        rgba(0,0,0,.10) 0%,
        rgba(0,0,0,.20) 35%,
        rgba(0,0,0,.82) 100%
    );

    z-index:1;
}

.cat-card .icon-badge,
.cat-card .arrow-badge,
.cat-card h4,
.cat-card small{
    position:relative;
    z-index:2;
}

.cat-card .icon-badge{
    position:absolute;
    top:15px;
    left:15px;

    width:36px;
    height:36px;

    border-radius:10px;

    background:rgba(255,255,255,.15);
    backdrop-filter:blur(8px);

    display:flex;
    align-items:center;
    justify-content:center;
}

.cat-card .arrow-badge{
    position:absolute;
    top:15px;
    right:15px;

    width:30px;
    height:30px;

    border-radius:50%;

    background:rgba(255,255,255,.15);
    backdrop-filter:blur(8px);

    display:flex;
    align-items:center;
    justify-content:center;

    transition:.3s;
}

.cat-card:hover .arrow-badge{
    transform:rotate(45deg);
}

.cat-card h4{
    font-size:1.1rem;
    font-weight:700;
    margin-bottom:4px;
}

.cat-card small{
    color:rgba(255,255,255,.85);
    font-size:.8rem;
}

/* ---------- event cards ---------- */
.evt-card{
  background:var(--panel); border:1px solid var(--line); border-radius:18px; overflow:hidden; height:100%;
  transition:transform .2s ease, border-color .2s ease;
}
.evt-card:hover{ transform:translateY(-4px); border-color:var(--muted-2); }
.evt-media{ position:relative; aspect-ratio:4/3.1; overflow:hidden; }
.evt-media::after{ content:""; position:absolute; inset:0; background:linear-gradient(0deg, rgba(0,0,0,.55), transparent 45%); }
.price-tag{
  position:absolute; top:10px; left:10px; z-index:2;
  background:rgba(10,10,13,.7); backdrop-filter:blur(6px); border:1px solid rgba(255,201,77,.4);
  color:var(--yellow); font-size:.7rem; font-weight:800; padding:.25rem .6rem; border-radius:999px;
}
.heart-btn{
  position:absolute; top:10px; right:10px; z-index:2;
  width:28px; height:28px; border-radius:50%; background:rgba(10,10,13,.6);
  backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; color:#fff; font-size:.75rem;
  border:1px solid rgba(255,255,255,.15);
}
.cat-tag{
  position:absolute; bottom:10px; left:10px; z-index:2;
  color:#fff; font-size:.72rem; font-weight:700;
  background:rgba(10,10,13,.55); padding:.2rem .6rem; border-radius:6px;
}
.evt-body{ padding:1rem 1.1rem 1.15rem; }
.evt-meta{ font-size:.75rem; color:var(--muted-2); font-weight:600; margin-bottom:.4rem; display:flex; align-items:center; gap:.35rem; }
.evt-body h5{ font-size:.92rem; font-weight:700; margin-bottom:.35rem; line-height:1.3; color:var(--text); }
.evt-venue{ font-size:.78rem; color:var(--muted); margin-bottom:.85rem; display:flex; align-items:center; gap:.35rem; }

.ec1{ background:linear-gradient(150deg,#7a1a2a,#2a0a10); }
.ec2{ background:linear-gradient(150deg,#7a1240,#280a1c); }
.ec3{ background:linear-gradient(150deg,#123a6e,#0a1830); }
.ec4{ background:linear-gradient(150deg,#5c1230,#1c0a14); }
.ec5{ background:linear-gradient(150deg,#0f3a3a,#0a1818); }
.ec6{ background:linear-gradient(150deg,#123a52,#0a1620); }
.ec7{ background:linear-gradient(150deg,#4a2a10,#1c1006); }
.ec8{ background:linear-gradient(150deg,#2a4a10,#101c06); }

/* ---------- artist cards ---------- */
.art-card{
    position:relative;
    border-radius:16px;
    overflow:hidden;
    aspect-ratio:1/1.15;
    border:1px solid var(--line);
    display:flex;
    flex-direction:column;
    justify-content:flex-end;
    padding:.9rem;

    background-size:cover;
    background-position:center;
    background-repeat:no-repeat;
        transition:.35s ease;

}

.art-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(
        to top,
        rgba(0,0,0,.9) 15%,
        rgba(0,0,0,.35) 55%,
        rgba(0,0,0,.15) 100%
    );
    z-index:1;
}



.art-card h5,
.art-card small,
.art-card .heart-btn{
    position:relative;
    z-index:2;
}

.art-card h5{
    color:#fff;
    margin:0;
    font-size:.95rem;
}

.art-card small{
    color:rgba(255,255,255,.75);
    font-size:.72rem;
    font-weight:600;
}

.art-card:hover{
    transform:translateY(-6px);
    box-shadow:0 18px 40px rgba(0,0,0,.35);
}


/* ---------- venue cards ---------- */
.ven-card{
    position: relative;
    border-radius: 16px;
    overflow: hidden;
    aspect-ratio: 1/0.95;
    border: 1px solid var(--line);

    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    padding: .9rem;

    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
        transition:.35s ease;

}

.ven-card:hover{
    transform:translateY(-6px);
    box-shadow:0 18px 40px rgba(0,0,0,.35);
}

.ven-card::before{ content:""; position:absolute; inset:0; background:linear-gradient(0deg, rgba(0,0,0,.8) 20%, transparent 60%); z-index:1; }
.rating-badge{
  position:absolute; top:10px; left:10px; z-index:2;
  background:rgba(10,10,13,.65); backdrop-filter:blur(6px); color:var(--yellow);
  font-size:.72rem; font-weight:800; padding:.25rem .55rem; border-radius:999px;
  display:flex; align-items:center; gap:.25rem;
}
.ven-card h5{ position:relative; z-index:2; font-size:.92rem; margin:0; color:#fff; }
.ven-card small{ position:relative; z-index:2; color:rgba(255,255,255,.7); font-size:.72rem; font-weight:600; }




.social-circle{
  width:34px; height:34px; border-radius:50%; border:1px solid var(--line);
  display:flex; align-items:center; justify-content:center; color:var(--muted);
}
.social-circle:hover{ background:var(--pink); border-color:var(--pink); color:#fff; }
.foot-bottom{ border-top:1px solid var(--line); margin-top:2.5rem; padding-top:1.5rem; font-size:.8rem; color:var(--muted-2); }

/* ---------- horizontal 2-row carousel (categories / artists / venues) ---------- */
.carousel-wrap{ position:relative; }
.carousel-track{
  display:grid;
  grid-auto-flow:column;
  grid-template-rows:repeat(2,1fr);
  grid-auto-columns:46%;
  gap:.75rem;
  overflow-x:auto;
  overflow-y:hidden;
  scroll-snap-type:x mandatory;
  -webkit-overflow-scrolling:touch;
  scrollbar-width:none;
  padding-bottom:.25rem;
}
.carousel-track::-webkit-scrollbar{ display:none; }
.carousel-track > div{ scroll-snap-align:start; min-width:0; }
.carousel-track .empty-msg{ grid-row:1 / span 2; width:100%; }
.carousel-nav{
  position:absolute; top:50%; transform:translateY(-50%); z-index:4;
  width:38px; height:38px; border-radius:50%;
  background:rgba(10,10,13,.75); backdrop-filter:blur(6px);
  border:1px solid rgba(255,255,255,.15); color:#fff;
  display:none; align-items:center; justify-content:center; font-size:.85rem;
  transition:background .2s ease, border-color .2s ease;
}
.carousel-nav:hover{ background:var(--pink); border-color:var(--pink); }
.carousel-nav.prev{ left:-8px; }
.carousel-nav.next{ right:-8px; }

@media (min-width:576px){
  .carousel-track{ grid-auto-columns:32%; }
}

@media (min-width:768px){
  .carousel-track{ grid-auto-columns:23%; }
}

@media (min-width:992px){
  .carousel-track{ grid-template-rows:1fr; grid-auto-columns:22.5%; }
  .carousel-nav{ display:flex; }
}

@media (max-width:991.98px){
  .hero{ padding-top:1rem; }
  .hideonapp{ padding-top:6.5rem; }
}
</style>
<div class="hideonapp" id="hideonapp"></div>


<?php
if ($_SERVER['HTTP_X_HIGHSPINN_APP'] == 'true') {?>

             



<?php
}?>
<div class="hero">
  <div class="container">
    <div class="row align-items-center gy-5">
      <div class="col-lg-6" id="hideonapp">
        <span class="hero-eyebrow-pill"><span class="dot"></span> <?= htmlspecialchars($currentCityName) ?>'s #1 nightlife discovery</span>
        <h1>The night is<br><span class="grad-text">yours to spin.</span></h1>
        <p class="text-muted-c" style="max-width:440px;">
          Discover the best clubs, curated events, ladies nights and DJ takeovers
          happening across <?= htmlspecialchars($currentCityName) ?> &mdash; all in one place, updated daily.
        </p>
        <div class="d-flex flex-wrap gap-3 mt-4 mb-4">
          <a href="#events" class="btn-pink"><i class="fa-solid fa-compass me-2"></i>Explore Events</a>
          <a href="#" class="btn-dark-outline"><i class="fa-solid fa-play me-2"></i>Watch highlights</a>
        </div>
        <div class="d-flex align-items-center gap-3">
          <div class="avatar-stack">
            <div class="av">A</div><div class="av">R</div><div class="av">P</div><div class="av">+</div>
          </div>
          <span class="text-muted-c small fw-semibold">50,000+ revelers already vibing</span>
        </div>
      </div>
      <div class="col-lg-6" >

<?php
$slidesData = [];
?>

<div class="hero-media" id="heroCarousel">

<?php
// Hero banner: only events happening at a venue in the current city
$bannerQuery = $conn->query("
    SELECT
        events.*,
        place.name AS placename
    FROM events
    LEFT JOIN place ON events.sub_tittle = place.id
    WHERE events.banner_status = 1
    AND events.status = 1
    AND LOWER(place.city) = LOWER('{$currentCityEsc}')
    ORDER BY events.start ASC
    LIMIT 10
");

$now = new DateTime();
$count = 0;

if($bannerQuery && $bannerQuery->num_rows > 0){

    while($row = $bannerQuery->fetch_assoc()){

        $originalStart = new DateTime($row['start']);
        $endDate = new DateTime($row['end']);
        $displayStart = $originalStart;

        $multipleDates = [];

        if(!empty($row['multiple_dates'])){

            $dates = array_map('trim', explode(',', $row['multiple_dates']));

            foreach($dates as $d){

                $ts = strtotime($d.' '.$originalStart->format('H:i:s'));

                if($ts >= $now->getTimestamp() && $ts <= $endDate->getTimestamp()){
                    $multipleDates[] = $ts;
                }

            }

        }

        if(!empty($multipleDates)){

            sort($multipleDates);
            $displayStart = (new DateTime())->setTimestamp($multipleDates[0]);

        }else{

            if($now > $endDate) continue;

        }

        $displayDate = $displayStart->format('D, d M · g:i A');

        $ticketQ = $conn->query("
            SELECT
            MIN(ticket_cost) min_cost,
            MAX(ticket_cost) max_cost
            FROM event_tickets
            WHERE event_id='{$row['id']}'
        ");

        $ticket = $ticketQ->fetch_assoc();

        if($ticket['min_cost']==0 && $ticket['max_cost']==0){
            $priceText="Free Entry";
        }else{
            $priceText="From ₹".$ticket['min_cost'];
        }

        $slidesData[]=[
            "tags"=>[
                "Party",
                $priceText
            ],
            "meta"=>$displayDate,
            "title"=>$row['tittle'],
            "venue"=>$row['placename'],
            "url"=>hs_city_url('events/' . $row['url']),
            "image"=>$row['image']
        ];
?>

<div class="hero-slide <?php if($count==0) echo 'active'; ?>"
     style="background-image:url('<?php echo $row['image'];?>');">
</div>

<?php
$count++;
}
}
?>

<div class="hero-tags">
    <span class="tag-pill" id="heroTag1"></span>
    <span class="tag-pill" id="heroTag2"></span>
</div>

<button class="hero-nav-btn hero-nav-prev" id="heroPrev">
    <i class="fa-solid fa-chevron-left"></i>
</button>

<button class="hero-nav-btn hero-nav-next" id="heroNext">
    <i class="fa-solid fa-chevron-right"></i>
</button>

<div class="hero-card">

    <div class="meta">
        <i class="fa-regular fa-clock me-1"></i>
        <span id="heroMeta"></span>
    </div>

    <h5 id="heroTitle"></h5>

    <div class="venue" id="heroVenue"></div>

    <a href="#" id="heroLink" class="btn-pink btn-sm py-2 px-3">
        Book now
        <i class="fa-solid fa-arrow-right ms-1"></i>
    </a>

</div>

<div class="hero-dots" id="heroDots">
<?php
for($i=0;$i<$count;$i++){
?>
<button class="<?php if($i==0) echo 'active'; ?>" data-i="<?php echo $i;?>"></button>
<?php
}
?>
</div>

</div>

<?php if ($count === 0): ?>
<p class="text-muted-c small mt-3 mb-0">No featured events in <?= htmlspecialchars($currentCityName) ?> right now &mdash; check back soon.</p>
<?php endif; ?>

      </div>
    </div>
  </div>
</div>


<!-- ================= CATEGORIES ================= -->
<?php
$today = date('Y-m-d');

// Fetch all upcoming events IN THE CURRENT CITY
$eventQuery = $conn->query("
    SELECT
        event_category.cat_id,
        events.start,
        events.multiple_dates
    FROM events
    INNER JOIN event_category ON events.id = event_category.event_id
    INNER JOIN place ON events.sub_tittle = place.id
    WHERE events.status = 1
    AND LOWER(place.city) = LOWER('{$currentCityEsc}')
");

$categoryUpcomingEvents = [];

// Count upcoming events for each category
while ($event = $eventQuery->fetch_assoc()) {

    $catId = $event['cat_id'];
    $isUpcoming = false;

    // Main event date
    if (!empty($event['start']) && $event['start'] >= $today) {
        $isUpcoming = true;
    }

    // Multiple dates
    if (!$isUpcoming && !empty($event['multiple_dates'])) {
        $dates = explode(',', $event['multiple_dates']);

        foreach ($dates as $date) {
            if (trim($date) >= $today) {
                $isUpcoming = true;
                break;
            }
        }
    }

    if ($isUpcoming) {
        if (!isset($categoryUpcomingEvents[$catId])) {
            $categoryUpcomingEvents[$catId] = 0;
        }

        $categoryUpcomingEvents[$catId]++;
    }
}

// Get all categories
$allCategoryIds = [];

$catQuery = $conn->query("SELECT id FROM category");

while ($row = $catQuery->fetch_assoc()) {
    $allCategoryIds[] = $row['id'];
}

// Separate performing & non-performing categories (performing = has an
// upcoming event in THIS city)
$performingIds = array_keys($categoryUpcomingEvents);
$nonPerformingIds = array_diff($allCategoryIds, $performingIds);

// Sort by highest event count
arsort($categoryUpcomingEvents);

// Card Colors
$colors = [
    'cat-c1',
    'cat-c2',
    'cat-c3',
    'cat-c4',
    'cat-c5',
    'cat-c6',
    'cat-c7',
    'cat-c8'
];

// Icons
$icons = [
    'fa-solid fa-city',
    'fa-solid fa-music',
    'fa-solid fa-headphones',
    'fa-solid fa-guitar',
    'fa-solid fa-champagne-glasses',
    'fa-solid fa-drum',
    'fa-solid fa-star',
    'fa-solid fa-masks-theater'
];

$i = 0;
?>

<section id="categories">
    <div class="container">

        <div class="section-head">
            <div>
                <span class="eyebrow">Browse by vibe &middot; <?= htmlspecialchars($currentCityName) ?></span>
                <h2>Pick your night, pick your beat.</h2>
            </div>

            <a href="<?= hs_city_url('events') ?>" class="link-arrow">
                See all categories &rarr;
            </a>
        </div>

        <div class="carousel-wrap">

            <button class="carousel-nav prev" data-target="categoryTrack" aria-label="Previous">
                <i class="fa-solid fa-chevron-left"></i>
            </button>

            <div class="carousel-track" id="categoryTrack">

            <?php
            // Performing Categories (upcoming events in this city)
            if (!empty($performingIds)) {

                $ids = implode(',', $performingIds);

                $query = $conn->query("
                    SELECT *
                    FROM category
                    WHERE id IN ($ids)
                    ORDER BY FIELD(id,$ids)
                ");

                while ($cat = $query->fetch_assoc()) {

                    $count = $categoryUpcomingEvents[$cat['id']];
                    $color = $colors[$i % count($colors)];
                    $icon  = $icons[$i % count($icons)];
            ?>

                <div>
                    <a href="<?= hs_city_url('categories/' . urlencode($cat['cat_slug'])) ?>" class="text-decoration-none">

<div class="cat-card <?php echo $color; ?>"
     style="background-image:url('<?php echo htmlspecialchars($cat['image']); ?>');">
                            <div class="streaks"></div>

                            <span class="icon-badge">
                                <i class="<?php echo $icon; ?>"></i>
                            </span>

                            <h4><?php echo htmlspecialchars($cat['cat_name']); ?></h4>

                            <small>
                                <?php echo $count; ?>
                                upcoming event<?php echo ($count > 1) ? 's' : ''; ?>
                            </small>

                        </div>

                    </a>
                </div>

            <?php
                    $i++;
                }
            }

            // Non-performing categories in this city (0 upcoming events)
            if (!empty($nonPerformingIds)) {

                $ids = implode(',', $nonPerformingIds);

                $query = $conn->query("
                    SELECT *
                    FROM category
                    WHERE id IN ($ids)
                    LIMIT 6
                ");

                while ($cat = $query->fetch_assoc()) {

                    $color = $colors[$i % count($colors)];
                    $icon  = $icons[$i % count($icons)];
            ?>

                <div>
                    <a href="<?= hs_city_url('categories/' . urlencode($cat['cat_slug'])) ?>" class="text-decoration-none">

<div class="cat-card <?php echo $color; ?>"
     style="background-image:url('<?php echo htmlspecialchars($cat['image']); ?>');">
                            <div class="streaks"></div>

                            <span class="icon-badge">
                                <i class="<?php echo $icon; ?>"></i>
                            </span>

                            <h4><?php echo htmlspecialchars($cat['cat_name']); ?></h4>

                            <small>0 upcoming events</small>

                        </div>

                    </a>
                </div>

            <?php
                    $i++;
                }
            }

            if (empty($performingIds) && empty($nonPerformingIds)) {
                echo '<div class="empty-msg text-center"><p>No categories found.</p></div>';
            }
            ?>

            </div>

            <button class="carousel-nav next" data-target="categoryTrack" aria-label="Next">
                <i class="fa-solid fa-chevron-right"></i>
            </button>

        </div>

    </div>
</section>





<?php
$now = new DateTime();

// =============================
// Get Categories that have events IN THIS CITY
// =============================
$catQuery = $conn->query("
    SELECT DISTINCT
        c.cat_name,
        c.cat_slug
    FROM category c
    INNER JOIN event_category ec ON ec.cat_id = c.id
    INNER JOIN events e ON e.id = ec.event_id
    INNER JOIN place p ON e.sub_tittle = p.id
    WHERE e.status = 1
    AND LOWER(p.city) = LOWER('{$currentCityEsc}')
    ORDER BY c.cat_name ASC
");

// =============================
// Get Events IN THIS CITY
// =============================
$eventQuery = $conn->query("
    SELECT
        events.*,
        place.name AS placename,
        MIN(event_tickets.ticket_cost) AS min_cost,
        MAX(event_tickets.ticket_cost) AS max_cost,
        GROUP_CONCAT(DISTINCT category.cat_slug SEPARATOR ',') AS category_slug,
        GROUP_CONCAT(DISTINCT category.cat_name SEPARATOR ', ') AS category_name
    FROM events
    INNER JOIN place
        ON events.sub_tittle = place.id
    LEFT JOIN event_tickets
        ON event_tickets.event_id = events.id
    LEFT JOIN event_category
        ON event_category.event_id = events.id
    LEFT JOIN category
        ON category.id = event_category.cat_id
    WHERE events.status = 1
    AND LOWER(place.city) = LOWER('{$currentCityEsc}')
    GROUP BY events.id
    ORDER BY events.start ASC
    LIMIT 12
");
?>

<section id="events">
    <div class="container">

        <div class="section-head">
            <div>
                <span class="eyebrow">Events & Parties</span>
                <h2>Upcoming Events in <?= htmlspecialchars($currentCityName) ?></h2>
            </div>

            <div class="filter-pills" id="homeCatPills">

                <span class="filter-pill active" data-cat="">All</span>

             <?php while($cat = $catQuery->fetch_assoc()) { ?>

<span class="filter-pill"
      data-cat="<?= strtolower($cat['cat_slug']); ?>">
    <?= htmlspecialchars($cat['cat_name']); ?>
</span>

<?php } ?>

            </div>
        </div>


        <div class="row g-4" id="homeEventGrid">

            <?php

            if($eventQuery && $eventQuery->num_rows){

                while($event=$eventQuery->fetch_assoc()){

                    $originalStart = new DateTime($event['start']);
                    $endDate       = new DateTime($event['end']);
                    $displayStart  = $originalStart;

                    $multipleDates=[];

                    if(!empty($event['multiple_dates'])){

                        $dates=array_map('trim',explode(',',$event['multiple_dates']));

                        foreach($dates as $d){

                            $ts=strtotime($d.' '.$originalStart->format('H:i:s'));

                            if($ts >= $now->getTimestamp() && $ts <= $endDate->getTimestamp()){
                                $multipleDates[]=$ts;
                            }

                        }

                    }

                    if(!empty($multipleDates)){

                        sort($multipleDates);
                        $displayStart=(new DateTime())->setTimestamp($multipleDates[0]);

                    }else{

                        if($now > $endDate){
                            continue;
                        }

                    }

                    $displayDate=$displayStart->format('D, d M').' · '.$displayStart->format('g:i A');

                    if($event['min_cost']==0 && $event['max_cost']==0){
                        $price="FREE";
                    }else{
                        $price="₹".$event['min_cost'];
                        if($event['min_cost']!=$event['max_cost']){
                            $price.=" - ₹".$event['max_cost'];
                        }
                    }

                    $eventUrl=htmlspecialchars($event['url']);

                    $slug=$event['category_slug'];
                    $catName=explode(',',$event['category_name'])[0] ?? '';

            ?>

            <div class="col-sm-6 col-lg-3 evt-item"
                 data-cat="<?= strtolower($slug); ?>">

                <a href="<?= hs_city_url('events/' . $eventUrl) ?>" class="text-decoration-none">

                    <div class="evt-card">

                        <div class="evt-media">

                            <img src="<?= $event['image']; ?>"
                                 class="w-100 h-100"
                                 style="object-fit:cover;border-radius:18px;"
                                 alt="<?= htmlspecialchars($event['tittle']); ?>">

                            <span class="price-tag">
                                <?= $price; ?>
                            </span>

                            <span class="heart-btn">
                                <i class="fa-regular fa-heart"></i>
                            </span>

                          <?php
$cats = explode(',', $event['category_name']);

foreach ($cats as $cat){
?>
    <span class="cat-tag">
        <?= htmlspecialchars(trim($cat)); ?>
    </span>
<?php
}
?>

                        </div>

                        <div class="evt-body">

                            <div class="evt-meta">
                                <i class="fa-regular fa-clock"></i>
                                <?= $displayDate; ?>
                            </div>

                            <h5>
                                <?= htmlspecialchars($event['tittle']); ?>
                            </h5>

                            <div class="evt-venue">
                                <i class="fa-solid fa-location-dot"></i>
                                <?= htmlspecialchars($event['placename']); ?>
                            </div>

                            <span class="btn-pass">
                                Get Pass
                                <i class="fa-solid fa-arrow-right"></i>
                            </span>

                        </div>

                    </div>

                </a>

            </div>

            <?php
                }
            }
            ?>

        </div>


        <div class="empty-state-home text-center py-5"
             id="homeEmptyState"
             style="display:none;">

            <i class="fa-solid fa-champagne-glasses"
               style="font-size:2.2rem;color:var(--muted-2);margin-bottom:1rem;">
            </i>

            <h5 class="mb-2">
                No events found
            </h5>

            <p class="text-muted-c mb-3">
                Check back later for upcoming events in <?= htmlspecialchars($currentCityName) ?>.
            </p>

            <a href="<?= hs_city_url('events') ?>" class="btn-pink">
                Browse All Events
            </a>

        </div>

    </div>
</section>




<?php
$today = date('Y-m-d');

/* ==================================================================
   ARTISTS — shown for the current city if EITHER:
     a) they have an upcoming event at a venue located in this city, OR
     b) their own artist.city (home base) matches this city
   Artists with no city event and no matching home base are NOT shown.
   ================================================================== */

// a) Upcoming events count per artist, scoped to this city's venues
$eventQuery = $conn->query("
    SELECT event_artist.artist_id, events.start, events.multiple_dates
    FROM events
    INNER JOIN event_artist ON events.id = event_artist.event_id
    INNER JOIN place ON events.sub_tittle = place.id
    WHERE events.status = 1
    AND LOWER(place.city) = LOWER('{$currentCityEsc}')
");

$artistUpcomingEvents = [];

while ($event = $eventQuery->fetch_assoc()) {

    $artistId = $event['artist_id'];
    $isUpcoming = false;

    // Main event date
    if ($event['start'] >= $today) {
        $isUpcoming = true;
    }

    // Multiple dates
    if (!$isUpcoming && !empty($event['multiple_dates'])) {
        $dates = explode(',', $event['multiple_dates']);

        foreach ($dates as $date) {
            if (trim($date) >= $today) {
                $isUpcoming = true;
                break;
            }
        }
    }

    if ($isUpcoming) {
        if (!isset($artistUpcomingEvents[$artistId])) {
            $artistUpcomingEvents[$artistId] = 0;
        }

        $artistUpcomingEvents[$artistId]++;
    }
}

// b) Artists based in this city (shown even with 0 upcoming events here)
$localArtistIds = [];
if ($artistHasCityCol) {
    $localArtistsQuery = $conn->query("
        SELECT id FROM artist WHERE LOWER(city) = LOWER('{$currentCityEsc}')
    ");
    if ($localArtistsQuery) {
        while ($row = $localArtistsQuery->fetch_assoc()) {
            $localArtistIds[] = $row['id'];
            if (!isset($artistUpcomingEvents[$row['id']])) {
                $artistUpcomingEvents[$row['id']] = 0; // ensure they appear, badge shows "Based here"
            }
        }
    }
}

// Order: most upcoming shows in this city first, then home-base artists
arsort($artistUpcomingEvents);
$artistIds = array_slice(array_keys($artistUpcomingEvents), 0, 8);
?>

<!-- ================= ARTISTS ================= -->
<section id="artists">
    <div class="container">

        <div class="section-head">
            <div>
                <span class="eyebrow">Featured artists &middot; <?= htmlspecialchars($currentCityName) ?></span>
                <h2>The DJs setting the city on fire.</h2>
            </div>

            <a href="<?= hs_city_url('artists') ?>" class="link-arrow">
                All artists &rarr;
            </a>
        </div>

        <div class="carousel-wrap">

            <button class="carousel-nav prev" data-target="artistTrack" aria-label="Previous">
                <i class="fa-solid fa-chevron-left"></i>
            </button>

            <div class="carousel-track" id="artistTrack">

            <?php

            if (!empty($artistIds)) {

                $ids = implode(',', $artistIds);

                $artistQuery = $conn->query("
                    SELECT *
                    FROM artist
                    WHERE id IN ($ids)
                    ORDER BY FIELD(id,$ids)
                ");

                while ($artist = $artistQuery->fetch_assoc()) {

                    $upcomingHere = $artistUpcomingEvents[$artist['id']] ?? 0;
                    $isLocal = in_array($artist['id'], $localArtistIds);

                    if ($upcomingHere > 0) {
                        $badge = $upcomingHere . ' upcoming show' . ($upcomingHere > 1 ? 's' : '') . ' here';
                    } elseif ($isLocal) {
                        $badge = 'Based in ' . htmlspecialchars($currentCityName);
                    } else {
                        $badge = !empty($artist['genre']) ? htmlspecialchars($artist['genre']) : 'Featured Artist';
                    }
                    ?>

                    <div>

                        <a href="<?= hs_city_url('artists/' . $artist['url']) ?>" class="text-decoration-none">

                            <div class="art-card"
                                 style="background-image:url('<?php echo htmlspecialchars($artist['image_url']); ?>');">

                                <div class="streaks"></div>

                                <h5>
                                    <?php echo htmlspecialchars($artist['name']); ?>
                                </h5>

                                <small>
                                    <?php echo $badge; ?>
                                </small>

                            </div>

                        </a>

                    </div>

                    <?php
                }

            } else {
                ?>

                <div class="empty-msg text-center">
                    <p class="text-muted-c mb-0">No artists playing in <?= htmlspecialchars($currentCityName) ?> right now.</p>
                </div>

                <?php
            }
            ?>

            </div>

            <button class="carousel-nav next" data-target="artistTrack" aria-label="Next">
                <i class="fa-solid fa-chevron-right"></i>
            </button>

        </div>
    </div>
</section>


<section id="venues">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="eyebrow">Popular venues &middot; <?= htmlspecialchars($currentCityName) ?></span>
                <h2>Where <?= htmlspecialchars($currentCityName) ?> comes alive.</h2>
            </div>
            <a href="<?= hs_city_url('venues') ?>" class="link-arrow">See all venues &rarr;</a>
        </div>

        <div class="carousel-wrap">

            <button class="carousel-nav prev" data-target="venueTrack" aria-label="Previous">
                <i class="fa-solid fa-chevron-left"></i>
            </button>

            <div class="carousel-track" id="venueTrack">

        <?php
        $today = date('Y-m-d');

        // ==========================
        // All venues located IN THIS CITY only
        // ==========================
        $allVenueIds = [];
        $venueIdQuery = $conn->query("
            SELECT id FROM place WHERE LOWER(city) = LOWER('{$currentCityEsc}')
        ");
        if ($venueIdQuery) {
            while ($row = $venueIdQuery->fetch_assoc()) {
                $allVenueIds[] = $row['id'];
            }
        }

        // ==========================
        // Upcoming event counts for those venues
        // ==========================
        $venueUpcomingEvents = [];

        if (!empty($allVenueIds)) {

            $vIds = implode(',', $allVenueIds);

            $eventQuery = $conn->query("
                SELECT sub_tittle AS venue_id, start, multiple_dates
                FROM events
                WHERE status = 1
                AND sub_tittle IN ($vIds)
            ");

            while ($event = $eventQuery->fetch_assoc()) {

                $venueId = $event['venue_id'];
                $isUpcoming = false;

                // Main Date
                if ($event['start'] >= $today) {
                    $isUpcoming = true;
                }

                // Multiple Dates
                if (!$isUpcoming && !empty($event['multiple_dates'])) {

                    $dates = explode(',', $event['multiple_dates']);

                    foreach ($dates as $date) {
                        if (trim($date) >= $today) {
                            $isUpcoming = true;
                            break;
                        }
                    }
                }

                if ($isUpcoming) {

                    if (!isset($venueUpcomingEvents[$venueId])) {
                        $venueUpcomingEvents[$venueId] = 0;
                    }

                    $venueUpcomingEvents[$venueId]++;
                }
            }
        }

        $performingIds = array_keys($venueUpcomingEvents);
        $nonPerformingIds = array_diff($allVenueIds, $performingIds);

        // Sort by Event Count
        arsort($venueUpcomingEvents);

        $displayCount = 0;

        // ==========================
        // Performing Venues (this city, has upcoming events)
        // ==========================
        if (!empty($performingIds)) {

            $ids = implode(',', $performingIds);

            $venueQuery = $conn->query("
                SELECT *
                FROM place
                WHERE id IN ($ids)
            ");

            while ($venue = $venueQuery->fetch_assoc()) {

                if ($displayCount >= 8) break;

                $eventCount = $venueUpcomingEvents[$venue['id']];
                $rating = !empty($venue['rating']) ? $venue['rating'] : '4.8';

                $colors = ['cat-c1','cat-c2','cat-c3','cat-c4','cat-c5'];
                $colorClass = $colors[$displayCount % count($colors)];
                ?>

                <div>
                    <a href="<?= hs_city_url('venues/' . $venue['url']) ?>" class="text-decoration-none">

<div class="ven-card"
     style="background-image:url('<?php echo htmlspecialchars($venue['image_url']); ?>');">
    
                            <div class="streaks"></div>

                            <span class="rating-badge">
                                <i class="fa-solid fa-star"></i>
                                <?php echo $rating; ?>
                            </span>

                            <span class="heart-btn">
                                <i class="fa-regular fa-heart"></i>
                            </span>

                            <h5><?php echo htmlspecialchars($venue['name']); ?></h5>

                            <small>
                                <i class="fa-solid fa-location-dot"></i>
                                <?php echo htmlspecialchars($venue['city']); ?>
                            </small>

                        </div>

                    </a>
                </div>

                <?php
                $displayCount++;
            }
        }

        // ==========================
        // Non-performing venues (this city, no upcoming events yet)
        // ==========================
        if ($displayCount < 8 && !empty($nonPerformingIds)) {

            $ids = implode(',', $nonPerformingIds);

            $venueQuery = $conn->query("
                SELECT *
                FROM place
                WHERE id IN ($ids)
                LIMIT ".(8-$displayCount)."
            ");

            while ($venue = $venueQuery->fetch_assoc()) {

                $rating = !empty($venue['rating']) ? $venue['rating'] : '4.8';

                $colors = ['cat-c1','cat-c2','cat-c3','cat-c4','cat-c5'];
                $colorClass = $colors[$displayCount % count($colors)];
                ?>

                <div>
                    <a href="<?= hs_city_url('venues/' . $venue['url']) ?>" class="text-decoration-none">

<div class="ven-card"
     style="background-image:url('<?php echo htmlspecialchars($venue['image_url']); ?>');">
    
                            <div class="streaks"></div>

                            <span class="rating-badge">
                                <i class="fa-solid fa-star"></i>
                                <?php echo $rating; ?>
                            </span>

                            <span class="heart-btn">
                                <i class="fa-regular fa-heart"></i>
                            </span>

                            <h5><?php echo htmlspecialchars($venue['name']); ?></h5>

                            <small>
                                <i class="fa-solid fa-location-dot"></i>
                                <?php echo htmlspecialchars($venue['city']); ?>
                            </small>

                        </div>

                    </a>
                </div>

                <?php
                $displayCount++;
            }
        }

        if ($displayCount == 0) {
            echo '<div class="empty-msg"><p class="text-center">No venues found in ' . htmlspecialchars($currentCityName) . ' yet.</p></div>';
        }
        ?>

            </div>

            <button class="carousel-nav next" data-target="venueTrack" aria-label="Next">
                <i class="fa-solid fa-chevron-right"></i>
            </button>

        </div>
    </div>
</section>


<!-- ================= WHY HIGHSPINN ================= -->
<section id="hideonapp">
  <div class="container">
    <div class="row g-5">
      <div class="col-lg-4">
        <span class="eyebrow">Why HighSpinn</span>
        <h2 class="mb-3">Built for people who live for the weekend.</h2>
        <p class="text-muted-c mb-4">
          We hand-curate the city's wildest events so you never miss the moment.
          No spam, no fake listings &mdash; just the best of <?= htmlspecialchars($currentCityName) ?>'s nightlife.
        </p>
        <div class="row">
          <div class="col-6">
            <div class="stat-box"><div class="num">50K+</div><small>Happy users</small></div>
            <div class="stat-box"><div class="num">80+</div><small>Venues</small></div>
          </div>
          <div class="col-6">
            <div class="stat-box"><div class="num">1.2K+</div><small>Events hosted</small></div>
            <div class="stat-box"><div class="num">150+</div><small>Featured artists</small></div>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="feat-card">
              <div class="feat-icon"><i class="fa-solid fa-shield-heart"></i></div>
              <h5>Trusted by 50K+</h5>
              <p>HighSpinn is loved by revelers across the country for reliable curation.</p>
            </div>
          </div>
          <div class="col-md-6">
            <div class="feat-card">
              <div class="feat-icon"><i class="fa-solid fa-swatchbook"></i></div>
              <h5>Wide Range</h5>
              <p>From underground techno to ladies nights &mdash; all in one feed.</p>
            </div>
          </div>
          <div class="col-md-6">
            <div class="feat-card">
              <div class="feat-icon"><i class="fa-solid fa-gift"></i></div>
              <h5>Exclusive drops</h5>
              <p>Handpicked events, guest passes and deals only found on HighSpinn.</p>
            </div>
          </div>
          <div class="col-md-6">
            <div class="feat-card">
              <div class="feat-icon"><i class="fa-solid fa-bolt"></i></div>
              <h5>Instant booking</h5>
              <p>One-tap RSVPs, live capacity updates and digital passes in seconds.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================= TESTIMONIALS ================= -->
<section id="hideonapp">
  <div class="container">
    <div class="row g-4">
      <div class="col-md-4">
        <div class="testi-card">
          <p>&ldquo;HighSpinn is my go-to for every Friday plan. The curation is unreal.&rdquo;</p>
          <div class="testi-user">
            <div class="testi-avatar">AS</div>
            <div><h6>Aditi Sharma</h6><small>Party Regular</small></div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="testi-card">
          <p>&ldquo;Discovered my favourite techno nights through HighSpinn. Never missed a beat since.&rdquo;</p>
          <div class="testi-user">
            <div class="testi-avatar">RV</div>
            <div><h6>Rohan Verma</h6><small>Music Lover</small></div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="testi-card">
          <p>&ldquo;From Bollywood nights to Sunday brunches, everything I love is on this app.&rdquo;</p>
          <div class="testi-user">
            <div class="testi-avatar">PN</div>
            <div><h6>Priya Nair</h6><small>Events &amp; Brunch</small></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>





<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){

    var slidesData = <?php echo json_encode($slidesData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    var slides = document.querySelectorAll('#heroCarousel .hero-slide');
    var dots = document.querySelectorAll('#heroDots button');

    var prevBtn = document.getElementById('heroPrev');
    var nextBtn = document.getElementById('heroNext');

    var tag1 = document.getElementById('heroTag1');
    var tag2 = document.getElementById('heroTag2');

    var metaEl = document.getElementById('heroMeta');
    var titleEl = document.getElementById('heroTitle');
    var venueEl = document.getElementById('heroVenue');
    var heroLink = document.getElementById('heroLink');

    var current = 0;
    var timer = null;

    function render(i){

        if(!slidesData.length) return;

        slides.forEach(function(slide,index){
            slide.classList.toggle('active', index === i);
        });

        dots.forEach(function(dot,index){
            dot.classList.toggle('active', index === i);
        });

        var data = slidesData[i];

        tag1.textContent = data.tags[0] || '';
        tag2.textContent = data.tags[1] || '';

        metaEl.innerHTML = data.meta || '';
        titleEl.textContent = data.title || '';
        venueEl.textContent = data.venue || '';

        if(heroLink){
            heroLink.href = data.url || '#';
        }

        current = i;
    }

    function goTo(i){

        if(!slidesData.length) return;

        var total = slidesData.length;

        if(i >= total){
            i = 0;
        }

        if(i < 0){
            i = total - 1;
        }

        render(i);

    }

    function next(){
        goTo(current + 1);
    }

    function prev(){
        goTo(current - 1);
    }

    function startAuto(){

        if(slidesData.length <= 1) return;

        stopAuto();

        timer = setInterval(function(){
            next();
        },4500);

    }

    function stopAuto(){

        if(timer){
            clearInterval(timer);
            timer = null;
        }

    }

    dots.forEach(function(dot){

        dot.addEventListener('click',function(){

            goTo(parseInt(this.dataset.i));

            startAuto();

        });

    });

    if(nextBtn){
        nextBtn.addEventListener('click',function(){
            next();
            startAuto();
        });
    }

    if(prevBtn){
        prevBtn.addEventListener('click',function(){
            prev();
            startAuto();
        });
    }

    var carouselEl = document.getElementById('heroCarousel');

    if(carouselEl){

        carouselEl.addEventListener('mouseenter',stopAuto);
        carouselEl.addEventListener('mouseleave',startAuto);

        var touchStartX = 0;

        carouselEl.addEventListener('touchstart',function(e){

            touchStartX = e.changedTouches[0].clientX;

        },{passive:true});

        carouselEl.addEventListener('touchend',function(e){

            var touchEndX = e.changedTouches[0].clientX;

            var diff = touchStartX - touchEndX;

            if(Math.abs(diff) > 40){

                if(diff > 0){
                    next();
                }else{
                    prev();
                }

                startAuto();

            }

        },{passive:true});

    }

    if(slidesData.length){

        render(0);
        startAuto();

    }

})();
</script>

<script>
(function () {

    const items = document.querySelectorAll("#homeEventGrid .evt-item");
    const pills = document.querySelectorAll("#homeCatPills .filter-pill");
    const emptyState = document.getElementById("homeEmptyState");
    const grid = document.getElementById("homeEventGrid");

    function filter(cat){

        cat = (cat || "").trim().toLowerCase();

        let visible = 0;

        items.forEach(function(item){

            const cats = item.dataset.cat
                .toLowerCase()
                .split(",")
                .map(c => c.trim());

            const show = !cat || cats.includes(cat);

            item.style.removeProperty("display");

            if(show){

                item.classList.remove("d-none");
                item.style.display = "block";
                visible++;

            }else{

                item.classList.add("d-none");
                item.style.display = "none";

            }

        });

        if(visible){

            grid.classList.remove("d-none");
            emptyState.style.display="none";

        }else{

            grid.classList.add("d-none");
            emptyState.style.display="block";

        }

    }

    pills.forEach(function(btn){

        btn.onclick=function(){

            pills.forEach(function(x){
                x.classList.remove("active");
            });

            this.classList.add("active");

            filter(this.dataset.cat);

        };

    });

    filter("");

})();
</script>

<script>
(function(){

    // Generic drag-to-scroll + arrow-nav for the horizontal
    // category / artist / venue carousels.
    document.querySelectorAll('.carousel-track').forEach(function(track){

        var isDown = false;
        var startX, scrollLeft;

        track.addEventListener('mousedown', function(e){
            isDown = true;
            track.classList.add('dragging');
            startX = e.pageX - track.offsetLeft;
            scrollLeft = track.scrollLeft;
        });

        ['mouseleave','mouseup'].forEach(function(evt){
            track.addEventListener(evt, function(){
                isDown = false;
            });
        });

        track.addEventListener('mousemove', function(e){
            if(!isDown) return;
            e.preventDefault();
            var x = e.pageX - track.offsetLeft;
            var walk = (x - startX) * 1.2;
            track.scrollLeft = scrollLeft - walk;
        });

    });

    document.querySelectorAll('.carousel-nav').forEach(function(btn){

        btn.addEventListener('click', function(){

            var track = document.getElementById(this.dataset.target);
            if(!track) return;

            var firstItem = track.querySelector('div');
            var step = firstItem ? (firstItem.getBoundingClientRect().width + 12) : track.clientWidth * 0.6;

            track.scrollBy({
                left: this.classList.contains('next') ? step : -step,
                behavior: 'smooth'
            });

        });

    });

})();
</script>
<?php include 'include/footer.php'; ?>