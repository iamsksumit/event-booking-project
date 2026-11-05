<?php
include('include/config.php'); ?>

<?php

/* =========================================================================
   CITY RESOLUTION
   -------------------------------------------------------------------------
   Must happen before we query venues, since the whole point of this page
   is "venues located in the current city". header.php does its own
   require_once for this same file later - PHP dedupes require_once by
   resolved real path, so no double DB work.

   Exposes: $currentCityId, $currentCityName, $currentCitySlug,
            $currentCityEsc, $allCities, hs_city_url().
   ========================================================================= */
include_once('include/city.php');

/* =========================================================================
   SCHEMA ASSUMPTIONS — adjust the column/table names below if yours differ.

   place table (venues):
     id, name, url, image_url, city, status (1 = active),
     created_at (datetime, optional)
     -> place.city holds the city NAME as text, matched case-insensitively
        against tbl_cities.name - same pattern used everywhere else on the
        site (header.php's suggestCats, index.php, events_query.php).
     -> No rating, cost-for-two, or area/locality columns are assumed to
        exist, since those weren't present anywhere in the original code.
        The Rating, Cost for two, and Area filters have been removed rather
        than guessed. If your `place` table actually has columns for these,
        tell me the column names and I'll wire the filters back in — the
        front-end (sidebar cards, sort options, JS state) is already built
        for this pattern elsewhere on the site, so it's a quick add.

   category table:
     id, cat_name, cat_slug, image

   event_category table:
     id, event_id, cat_id

   events table:
     id, status, start (date), multiple_dates (comma-separated dates),
     sub_tittle (place id)

   event_artist table (not used on this page):
     event_id, artist_id
   ========================================================================= */

// Fetch data based on the page type
$title = 'Top rated and more Venues details on Highspinn - Events, Activities & Things To Do';
$description = 'Find out famous venues and respective events & activities in ' . $currentCityName . '. Keep yourself updated with most amazing venues and events on Highspinn.';
$image = 'https://www.highspinn.com/img/logo.png';
$url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

if (isset($pageData)) {
    $title = htmlspecialchars($pageData['title'] ?? $title);
    $description = htmlspecialchars($pageData['description'] ?? $description);
    $image = htmlspecialchars($pageData['image'] ?? $image);
    $url = htmlspecialchars($pageData['url'] ?? $url);
}

$today = date('Y-m-d');

function hs_slugify_type($g) {
    return strtolower(trim(preg_replace('/\s+/', '-', $g)));
}

/* ---------- 1. Fetch all active venues IN THE CURRENT CITY ---------- */
$venues = [];
$res = $conn->query("
    SELECT id, name, url, image_url, created_at
    FROM place
    WHERE status = 1
    AND LOWER(city) = LOWER('{$currentCityEsc}')
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $venues[$row['id']] = [
            'id'             => (int)$row['id'],
            'name'           => $row['name'],
            'url'            => $row['url'],
            'image'          => $row['image_url'],
            'created_at'     => $row['created_at'] ?? null,
            'next_date'      => null,
            'upcoming_count' => 0,
        ];
    }
}

/* ---------- 2. Fetch events at each venue to find next date + upcoming count ----------
   The events table has no city column of its own - a venue's city is what
   scopes this page, so restricting $venues above (step 1) to this city is
   what makes this whole query effectively city-scoped too: any venue_id
   that isn't in $venues (because it's in another city) is skipped below. */
$evRes = $conn->query("
    SELECT events.sub_tittle AS venue_id, events.start, events.multiple_dates
    FROM events
    WHERE events.status = 1
");
if ($evRes) {
    while ($ev = $evRes->fetch_assoc()) {
        $vid = $ev['venue_id'];
        if (!isset($venues[$vid])) continue;

        $candidateDates = [];
        if (!empty($ev['start']) && $ev['start'] >= $today) {
            $candidateDates[] = $ev['start'];
        }
        if (!empty($ev['multiple_dates'])) {
            foreach (explode(',', $ev['multiple_dates']) as $d) {
                $d = trim($d);
                if ($d !== '' && $d >= $today) $candidateDates[] = $d;
            }
        }
        if (empty($candidateDates)) continue;

        sort($candidateDates);
        $soonest = $candidateDates[0];

        $venues[$vid]['upcoming_count']++;
        if ($venues[$vid]['next_date'] === null || $soonest < $venues[$vid]['next_date']) {
            $venues[$vid]['next_date'] = $soonest;
        }
    }
}

/* ---------- 3. Fetch categories, and which categories each venue is tied to via events held there ---------- */
$categoryById = [];
$catRes = $conn->query("SELECT id, cat_name, cat_slug, image FROM category");
if ($catRes) {
    while ($c = $catRes->fetch_assoc()) {
        $categoryById[$c['id']] = [
            'label' => $c['cat_name'],
            'slug'  => !empty($c['cat_slug']) ? $c['cat_slug'] : hs_slugify_type($c['cat_name']),
            'image' => $c['image'],
        ];
    }
}

$venueCatIds = []; // venue_id => [cat_id => true, ...]
$vcRes = $conn->query("
    SELECT events.sub_tittle AS venue_id, event_category.cat_id
    FROM events
    INNER JOIN event_category ON event_category.event_id = events.id
    WHERE events.status = 1
");
if ($vcRes) {
    while ($row = $vcRes->fetch_assoc()) {
        $vid = $row['venue_id'];
        if (!isset($venues[$vid])) continue; // skips venues outside this city automatically
        $venueCatIds[$vid][$row['cat_id']] = true;
    }
}

foreach ($venues as $vid => &$vRef) {
    $labels = [];
    if (!empty($venueCatIds[$vid])) {
        foreach (array_keys($venueCatIds[$vid]) as $catId) {
            if (isset($categoryById[$catId])) {
                $labels[] = $categoryById[$catId]['label'];
            }
        }
    }
    $vRef['types'] = $labels; // empty array if venue has no categorized events yet — handled as "hidden" below
}
unset($vRef);

/* ---------- 4. Default order: venues with upcoming events first, then by id desc ---------- */
$venueList = array_values($venues);
usort($venueList, function ($a, $b) {
    if ($a['upcoming_count'] !== $b['upcoming_count']) {
        return $b['upcoming_count'] <=> $a['upcoming_count'];
    }
    return $b['id'] <=> $a['id'];
});

/* ---------- 5. Build venue-type facets dynamically (from the city-filtered list) ---------- */
$typeCounts      = [];
$typeLabelBySlug = [];

foreach ($venueList as $v) {
    foreach ($v['types'] as $t) {
        $slug = hs_slugify_type($t);
        if ($slug === '') continue;
        $typeLabelBySlug[$slug] = $t;
        $typeCounts[$slug] = ($typeCounts[$slug] ?? 0) + 1;
    }
}
arsort($typeCounts);
$topTypes = array_slice(array_keys($typeCounts), 0, 4);

$totalVenues = count($venueList);
?>
<?php include 'include/header.php'; ?>




<style>


/* ---------- page header (listing hero) ---------- */
.page-header{ padding:7.5rem 0 2.5rem; position:relative; overflow:hidden; }
.page-header::before{
  content:""; position:absolute; top:-180px; right:-120px; width:480px; height:480px; border-radius:50%;
  background:radial-gradient(circle, rgba(255,61,104,.22), transparent 70%); pointer-events:none;
}
.page-header::after{
  content:""; position:absolute; bottom:-220px; left:-140px; width:420px; height:420px; border-radius:50%;
  background:radial-gradient(circle, rgba(255,122,69,.16), transparent 70%); pointer-events:none;
}
.breadcrumb-hs{ font-size:.8rem; color:var(--muted-2); margin-bottom:1rem; }
.breadcrumb-hs a{ color:var(--muted-2); }
.breadcrumb-hs a:hover{ color:var(--pink); }
.hero-eyebrow-pill{
  display:inline-flex; align-items:center; gap:.5rem; background:var(--panel-2); border:1px solid var(--line);
  border-radius:999px; padding:.4rem 1rem; font-size:.75rem; font-weight:700; color:var(--muted);
}
.hero-eyebrow-pill .dot{ width:6px; height:6px; border-radius:50%; background:var(--pink); display:inline-block; }
.page-header h1{ font-size:clamp(2rem,4vw,2.9rem); margin-bottom:.6rem; }
.page-header p.lead-c{ color:var(--muted); max-width:560px; font-size:.95rem; }

.toolbar-strip{
  background:var(--panel); border:1px solid var(--line); border-radius:16px;
  padding:1rem 1.2rem; display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; margin-top:2rem;
}
.toolbar-strip .search-pill{ min-width:220px; }
.sort-select{
  background:var(--panel-2); border:1px solid var(--line); color:var(--text);
  font-size:.82rem; font-weight:600; padding:.55rem 1rem; border-radius:10px;
  outline:none; appearance:none; -webkit-appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2396969f'/%3E%3C/svg%3E");
  background-repeat:no-repeat; background-position:right 1rem center; padding-right:2.2rem;
}
.view-toggle{ display:flex; border:1px solid var(--line); border-radius:10px; overflow:hidden; }
.view-toggle button{
  background:var(--panel-2); border:none; color:var(--muted); width:38px; height:38px;
  display:flex; align-items:center; justify-content:center; font-size:.85rem;
}
.view-toggle button.active{ background:var(--pink); color:#fff; }

/* ---------- layout: sidebar + grid ---------- */
.filter-card{ background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:1.4rem; }
.filter-card + .filter-card{ margin-top:1.2rem; }
.filter-card h6{
  font-size:.72rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase;
  color:var(--muted-2); margin-bottom:1rem;
}
.chk-row{
  display:flex; align-items:center; justify-content:space-between; gap:.6rem;
  padding:.5rem 0; border-bottom:1px solid var(--line); font-size:.85rem; font-weight:600;
}
.chk-row:last-child{ border-bottom:none; padding-bottom:0; }
.chk-row .form-check-input{ background-color:var(--panel-2); border-color:var(--line); width:1.05rem; height:1.05rem; cursor:pointer; }
.chk-row .form-check-input:checked{ background-color:var(--pink); border-color:var(--pink); }
.chk-row label{ flex:1; cursor:pointer; }
.chk-row .cnt{ color:var(--muted-2); font-size:.76rem; font-weight:700; }
.range-price{ width:100%; accent-color:var(--pink); }
.price-vals{ display:flex; justify-content:space-between; font-size:.78rem; color:var(--muted); font-weight:700; margin-top:.4rem; }
.date-quick{ display:flex; flex-wrap:wrap; gap:.5rem; position:relative; }
.date-quick .filter-pill{ font-size:.75rem; padding:.4rem .8rem; }
#pickDateInput{
  background:var(--panel-2); border:1px solid var(--line); color:var(--text); border-radius:8px;
  font-size:.78rem; padding:.35rem .6rem; color-scheme:dark; display:none;
}
.btn-clear-filters{
  background:transparent; border:1px solid var(--line); color:var(--muted);
  font-size:.8rem; font-weight:700; padding:.6rem 1rem; border-radius:10px; width:100%;
}
.btn-clear-filters:hover{ border-color:var(--pink); color:var(--pink); }

/* ---------- event cards ---------- */
.evt-card{ background:var(--panel); border:1px solid var(--line); border-radius:18px; overflow:hidden; height:100%; transition:transform .2s ease, border-color .2s ease; }
.evt-card:hover{ transform:translateY(-4px); border-color:var(--muted-2); }
.evt-media{ position:relative; aspect-ratio:4/3.1; overflow:hidden; }
.evt-media::after{ content:""; position:absolute; inset:0; background:linear-gradient(0deg, rgba(0,0,0,.55), transparent 45%); }
.price-tag{
  position:absolute; top:10px; left:10px; z-index:2; background:rgba(10,10,13,.7); backdrop-filter:blur(6px);
  border:1px solid rgba(255,201,77,.4); color:var(--yellow); font-size:.7rem; font-weight:800; padding:.25rem .6rem; border-radius:999px;
}
.heart-btn{
  position:absolute; top:10px; right:10px; z-index:2; width:28px; height:28px; border-radius:50%; background:rgba(10,10,13,.6);
  backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; color:#fff; font-size:.75rem; border:1px solid rgba(255,255,255,.15);
}
.cat-tag{
  position:absolute; bottom:10px; left:10px; z-index:2; color:#fff; font-size:.72rem; font-weight:700;
  background:rgba(10,10,13,.55); padding:.2rem .6rem; border-radius:6px;
}
.evt-body{ padding:1rem 1.1rem 1.15rem; }
.evt-meta{ font-size:.75rem; color:var(--muted-2); font-weight:600; margin-bottom:.4rem; display:flex; align-items:center; gap:.35rem; }
.evt-body h5{ font-size:.92rem; font-weight:700; margin-bottom:.35rem; line-height:1.3; color:var(--text); }
.evt-body h5 a{ color:inherit; }
.evt-body h5 a:hover{ color:var(--pink); }
.evt-venue{ font-size:.78rem; color:var(--muted); margin-bottom:.85rem; display:flex; align-items:center; gap:.35rem; }

.ec1{ background:linear-gradient(150deg,#7a1a2a,#2a0a10); }
.ec2{ background:linear-gradient(150deg,#7a1240,#280a1c); }
.ec3{ background:linear-gradient(150deg,#123a6e,#0a1830); }
.ec4{ background:linear-gradient(150deg,#5c1230,#1c0a14); }
.ec5{ background:linear-gradient(150deg,#0f3a3a,#0a1818); }
.ec6{ background:linear-gradient(150deg,#123a52,#0a1620); }
.ec7{ background:linear-gradient(150deg,#4a2a10,#1c1006); }
.ec8{ background:linear-gradient(150deg,#2a4a10,#101c06); }
.ec9{ background:linear-gradient(150deg,#3a1440,#160a20); }
.ec10{ background:linear-gradient(150deg,#5c1f12,#20100a); }
.ec11{ background:linear-gradient(150deg,#20205c,#0c0c22); }
.ec12{ background:linear-gradient(150deg,#4a3210,#1c1408); }

.results-count{ font-size:.85rem; color:var(--muted); font-weight:600; }
.results-count b{ color:var(--text); }
.active-chips{ display:flex; flex-wrap:wrap; gap:.5rem; margin:-.6rem 0 1.4rem; }
.active-chip{
  display:inline-flex; align-items:center; gap:.4rem; background:rgba(255,61,104,.12); border:1px solid rgba(255,61,104,.35);
  color:var(--pink); font-size:.75rem; font-weight:700; padding:.3rem .5rem .3rem .75rem; border-radius:999px;
}
.active-chip button{ background:none; border:none; color:var(--pink); font-size:.8rem; display:flex; padding:0; }

.empty-state{
  display:none; text-align:center; padding:4rem 1rem; background:var(--panel); border:1px solid var(--line); border-radius:18px;
}
.empty-state i{ font-size:2.2rem; color:var(--muted-2); margin-bottom:1rem; }
.empty-state h5{ margin-bottom:.4rem; }
.empty-state p{ color:var(--muted); font-size:.85rem; margin-bottom:1.2rem; }

/* ---------- pagination ---------- */
.pagination-hs{ display:flex; align-items:center; justify-content:center; gap:.5rem; margin-top:3rem; }
.page-btn{
  width:38px; height:38px; border-radius:10px; background:var(--panel-2); border:1px solid var(--line);
  color:var(--text); display:flex; align-items:center; justify-content:center; font-size:.82rem; font-weight:700;
}
.page-btn:hover{ border-color:var(--muted-2); color:var(--text); }
.page-btn.active{ background:var(--pink); border-color:var(--pink); color:#fff; }
.page-btn[disabled], .page-btn.disabled{ opacity:.4; pointer-events:none; }

/* ---------- mobile filter bar + bottom sheets (filter + sort) ----------
   Same mechanism as events.php: the sidebar becomes a slide-up sheet, and
   sort moves into its own slide-up sheet, both triggered from a fixed
   bottom bar on mobile. */
.mobile-filter-bar{ display:none; }
.filter-backdrop, .sort-backdrop{ display:none; }
.filter-sheet-header{ display:none; }
.filter-sheet-footer{ display:none; }
.sort-sheet{ display:none; }

@media (max-width: 991.98px){
  aside.col-lg-3{
    position:fixed; left:0; right:0; bottom:0; top:auto;
    width:100%; max-width:100%;
    max-height:82vh; overflow-y:auto;
    background:var(--bg, #0f0f13);
    border:1px solid var(--line); border-bottom:none;
    border-radius:20px 20px 0 0;
    padding:0 1.2rem 1.2rem;
    z-index:1055;
    transform:translateY(110%);
    transition:transform .32s cubic-bezier(.32,.72,0,1);
    box-shadow:0 -16px 48px rgba(0,0,0,.5);
  }
  aside.col-lg-3.sheet-open{ transform:translateY(0); }
  aside.col-lg-3 .filter-card{ background:transparent; border:none; border-bottom:1px solid var(--line); border-radius:0; padding:1.2rem 0; }
  aside.col-lg-3 .filter-card:first-of-type{ border-top:none; }
  aside.col-lg-3 .btn-clear-filters{ margin:1rem 0 1.5rem; }

  .filter-sheet-header{
    display:flex; align-items:center; justify-content:space-between;
    position:sticky; top:0; background:var(--bg, #0f0f13); padding:1.1rem 0 .8rem; margin-bottom:.2rem;
    border-bottom:1px solid var(--line); z-index:2;
  }
  .filter-sheet-header h5{ margin:0; font-size:1rem; font-weight:800; }
  .filter-sheet-header button{
    width:32px; height:32px; border-radius:50%; background:var(--panel-2); border:1px solid var(--line);
    color:var(--text); display:flex; align-items:center; justify-content:center; font-size:.85rem;
  }

  .filter-sheet-footer{
    display:block; position:sticky; bottom:-1.2rem; margin:0 -1.2rem -1.2rem; padding:.9rem 1.2rem calc(.9rem + env(safe-area-inset-bottom));
    background:var(--bg, #0f0f13); border-top:1px solid var(--line);
  }
  .filter-sheet-footer button{
    width:100%; background:var(--pink); border:none; color:#fff; font-weight:700; font-size:.9rem;
    padding:.85rem 1rem; border-radius:12px;
  }

  .filter-backdrop{
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:1050;
  }
  .filter-backdrop.show{ display:block; }

  .sort-sheet{
    display:block;
    position:fixed; left:0; right:0; bottom:0;
    max-height:60vh; overflow-y:auto;
    background:var(--bg, #0f0f13);
    border:1px solid var(--line); border-bottom:none;
    border-radius:20px 20px 0 0;
    padding:0 1.2rem 1.2rem;
    z-index:1055;
    transform:translateY(110%);
    transition:transform .32s cubic-bezier(.32,.72,0,1);
    box-shadow:0 -16px 48px rgba(0,0,0,.5);
  }
  .sort-sheet.sheet-open{ transform:translateY(0); }
  .sort-backdrop{
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:1050;
  }
  .sort-backdrop.show{ display:block; }
  .sort-options .chk-row{ font-size:.9rem; }
  .sort-options .form-check-input:checked{ background-color:var(--pink); border-color:var(--pink); }

  .toolbar-strip .sort-select{ display:none; }

  .mobile-filter-bar{
    display:flex; align-items:center; justify-content:center; gap:.6rem;
    position:fixed; left:0; right:0; bottom:0; z-index:1030;
    padding:.75rem 1.2rem calc(.75rem + env(safe-area-inset-bottom));
  }
  .mobile-filter-bar button{
    display:flex; align-items:center; gap:.5rem;
    background:var(--panel-2); border:1px solid var(--line); color:var(--text);
    font-size:.85rem; font-weight:700; padding:.7rem 1.4rem; border-radius:999px;
  }
  .mobile-filter-bar button .filter-count-badge{
    background:var(--pink); color:#fff; font-size:.68rem; font-weight:800;
    min-width:18px; height:18px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; padding:0 4px;
  }

  .col-lg-9{ padding-bottom:4.5rem; }
}

@media (min-width: 992px){
  .mobile-filter-bar, .filter-backdrop, .filter-sheet-header, .filter-sheet-footer,
  .sort-sheet, .sort-backdrop{ display:none !important; }
}

/* ---------- newsletter cta ---------- */
.news-cta{ background:linear-gradient(120deg,#3a0a1e,#1c0610 60%); border:1px solid var(--line); border-radius:22px; padding:3rem; position:relative; overflow:hidden; }
.news-cta::before{ content:""; position:absolute; width:320px; height:320px; border-radius:50%; background:radial-gradient(circle, rgba(255,61,104,.35), transparent 70%); top:-140px; right:-60px; }
.news-cta h2{ font-size:clamp(1.6rem,3vw,2.2rem); }
.news-form{ display:flex; gap:.6rem; max-width:420px; }
.news-form input{ flex:1; background:var(--void); border:1px solid var(--line); color:#fff; border-radius:999px; padding:.7rem 1.2rem; font-size:.85rem; outline:none; }
.news-form button{ background:var(--pink); border:none; color:#fff; font-weight:700; padding:.7rem 1.4rem; border-radius:999px; font-size:.85rem; white-space:nowrap; }

/* ---------- app bar ---------- */
.app-bar{ background:var(--panel); border:1px solid var(--line); border-radius:18px; padding:1.6rem 2rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; }
.app-bar .icon-sq{ width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg,var(--pink),var(--orange)); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.1rem; flex:none; }
.store-btn{ background:var(--panel-2); border:1px solid var(--line); color:var(--text); font-weight:700; font-size:.82rem; padding:.55rem 1.1rem; border-radius:10px; display:inline-flex; align-items:center; gap:.5rem; }
.store-btn:hover{ border-color:var(--pink); color:var(--text); }

/* ---------- footer ---------- */
footer{ background:var(--void); border-top:1px solid var(--line); padding:3.5rem 0 1.5rem; }
footer h6{ font-size:.72rem; font-weight:800; letter-spacing:.1em; text-transform:uppercase; color:var(--muted-2); margin-bottom:1.1rem; }
footer ul{ list-style:none; padding:0; margin:0; }
footer li{ margin-bottom:.6rem; }
footer a{ color:var(--muted); font-size:.87rem; }
footer a:hover{ color:var(--pink); }
.social-circle{ width:34px; height:34px; border-radius:50%; border:1px solid var(--line); display:flex; align-items:center; justify-content:center; color:var(--muted); }
.social-circle:hover{ background:var(--pink); border-color:var(--pink); color:#fff; }
.foot-bottom{ border-top:1px solid var(--line); margin-top:2.5rem; padding-top:1.5rem; font-size:.8rem; color:var(--muted-2); }

@media (max-width:991.98px){ .page-header{ padding-top:7.5rem; } }

</style>



<!-- ================= PAGE HEADER ================= -->
<header class="page-header">
  <div class="container position-relative">
    <div class="breadcrumb-hs"><a href="<?= hs_city_url() ?>">Home</a> <i class="fa-solid fa-chevron-right mx-1" style="font-size:.6rem;"></i> Venues</div>
    <span class="hero-eyebrow-pill"><span class="dot"></span> <span id="liveCount"><?php echo $totalVenues; ?></span> venues live right now in <?= htmlspecialchars($currentCityName) ?></span>
    <h1 class="mt-3">Every floor, <span class="grad-text">every vibe.</span></h1>
    <p class="lead-c">Nightclubs, rooftop bars, lounges and banquet halls across <?= htmlspecialchars($currentCityName) ?> &mdash; filter by venue type to find your spot.</p>

    <div class="toolbar-strip">
      <div class="search-pill">
        <i class="fa-solid fa-magnifying-glass text-muted-c2 small"></i>
        <input type="text" id="venueSearch" placeholder="Search by venue name">
      </div>
      <span class="pill-loc" data-bs-toggle="modal" data-bs-target="#cityModal"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($currentCityName) ?></span>
      <select class="sort-select" id="sortSelect">
        <option value="trending">Sort: Trending</option>
        <option value="az">Sort: A &ndash; Z</option>
        <option value="new">Sort: Newly added</option>
      </select>
      <div class="ms-auto d-flex align-items-center gap-2">
        <span class="text-muted-c2 small fw-semibold d-none d-md-inline">View</span>
        <div class="view-toggle" id="viewToggle">
          <button class="active" data-view="grid" aria-label="Grid view"><i class="fa-solid fa-grip"></i></button>
          <button data-view="list" aria-label="List view"><i class="fa-solid fa-list"></i></button>
        </div>
      </div>
    </div>
  </div>
</header>

<!-- ================= VENUES + FILTERS ================= -->
<section class="pt-0">
  <div class="container">
    <div class="row g-4">

      <!-- backdrop for the mobile filter sheet -->
      <div class="filter-backdrop" id="filterBackdrop"></div>

      <!-- backdrop for the mobile sort sheet -->
      <div class="sort-backdrop" id="sortBackdrop"></div>

      <!-- ---------- SIDEBAR FILTERS (becomes a bottom sheet on mobile) ---------- -->
      <aside class="col-lg-3" id="filterAside">
        <div class="filter-sheet-header">
          <h5>Filters</h5>
          <button type="button" id="filterSheetClose" aria-label="Close filters"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="filter-card">
          <h6>Venue type</h6>
          <div class="chk-row"><div><input class="form-check-input me-2" type="checkbox" id="fcAll" checked><label for="fcAll">All types</label></div></div>
          <?php foreach ($typeCounts as $slug => $count): ?>
          <div class="chk-row">
            <div>
              <input class="form-check-input me-2 cat-chk" type="checkbox" value="<?php echo htmlspecialchars($slug); ?>" id="fc-<?php echo htmlspecialchars($slug); ?>">
              <label for="fc-<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($typeLabelBySlug[$slug]); ?></label>
            </div>
            <span class="cnt"><?php echo (int)$count; ?></span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($typeCounts)): ?>
          <p class="text-muted-c small mb-0">No venue types tagged yet.</p>
          <?php endif; ?>
        </div>

        <button class="btn-clear-filters mt-2" id="clearFilters"><i class="fa-solid fa-rotate-left me-2"></i>Clear all filters</button>

        <div class="filter-sheet-footer">
          <button type="button" id="filterSheetApply">Show <span id="sheetResultsCount"><?php echo $totalVenues; ?></span> venues</button>
        </div>
      </aside>

      <!-- ---------- SORT SHEET (mobile only) ---------- -->
      <div class="sort-sheet" id="sortSheet">
        <div class="filter-sheet-header">
          <h5>Sort by</h5>
          <button type="button" id="sortSheetClose" aria-label="Close sort"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="sort-options">
          <div class="chk-row">
            <div><input class="form-check-input me-2 sort-radio" type="radio" name="sortRadio" value="trending" id="sr1" checked><label for="sr1">Trending</label></div>
          </div>
          <div class="chk-row">
            <div><input class="form-check-input me-2 sort-radio" type="radio" name="sortRadio" value="az" id="sr2"><label for="sr2">A &ndash; Z</label></div>
          </div>
          <div class="chk-row">
            <div><input class="form-check-input me-2 sort-radio" type="radio" name="sortRadio" value="new" id="sr3"><label for="sr3">Newly added</label></div>
          </div>
        </div>
      </div>

      <!-- fixed bottom bar, mobile only -->
      <div class="mobile-filter-bar">
        <button type="button" id="openSortBtn">
          <i class="fa-solid fa-arrow-up-wide-short"></i> Sort
        </button>
        <button type="button" id="openFiltersBtn">
          <i class="fa-solid fa-sliders"></i> Filters
          <span class="filter-count-badge" id="filterCountBadge" style="display:none;">0</span>
        </button>
      </div>

      <!-- ---------- VENUE GRID ---------- -->
      <div class="col-lg-9">
        <div class="section-head" style="margin-bottom:1rem;">
          <span class="results-count"><b id="resultsNum"><?php echo $totalVenues; ?></b> venues found in <?= htmlspecialchars($currentCityName) ?></span>
          <div class="filter-pills" id="quickCatPills">
            <span class="filter-pill active" data-cat="">All</span>
            <?php foreach ($topTypes as $slug): ?>
            <span class="filter-pill" data-cat="<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($typeLabelBySlug[$slug]); ?></span>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="active-chips" id="activeChips"></div>

        <div class="row g-4" id="venueGrid">
          <?php
          $order = 0;
          foreach ($venueList as $v):
              $order++;
              $ecClass    = 'ec' . ((($order - 1) % 12) + 1);
              $typeLabels = $v['types'];
              $typeSlugs  = array_map('hs_slugify_type', $typeLabels);
              $primaryType = $typeLabels[0] ?? '';
              $catTagText  = implode(' &middot; ', array_slice($typeLabels, 0, 2));

              if ($v['next_date']) {
                  $nextText = 'Next event: ' . date('D, d M', strtotime($v['next_date']));
              } elseif ($v['upcoming_count'] > 0) {
                  $nextText = $v['upcoming_count'] . ' upcoming event' . ($v['upcoming_count'] > 1 ? 's' : '');
              } else {
                  $nextText = 'No upcoming events yet';
              }

              $addedVal = $v['created_at'] ? strtotime($v['created_at']) : $v['id'];
              $venueHref = hs_city_url('venues/' . $v['url']);
          ?>
          <div class="col-sm-6 col-xl-4 ven-item"
               data-name="<?php echo htmlspecialchars($v['name']); ?>"
               data-cat="<?php echo htmlspecialchars(implode(',', $typeSlugs)); ?>"
               data-order="<?php echo $order; ?>"
               data-added="<?php echo (int)$addedVal; ?>">
            <div class="evt-card"><a href="<?php echo $venueHref; ?>">
              <div class="evt-media <?php echo $ecClass; ?>"<?php if (!empty($v['image'])): ?> style="background-image:url('<?php echo htmlspecialchars($v['image']); ?>');background-size:cover;background-position:center;"<?php endif; ?>>
                <div class="streaks"></div>
                <span class="heart-btn"><i class="fa-regular fa-heart"></i></span>
                <?php if ($catTagText !== ''): ?>
                <span class="cat-tag"><?php echo $catTagText; ?></span>
                <?php endif; ?>
              </div></a>
              <div class="evt-body">
                <?php if ($primaryType !== ''): ?>
                <div class="evt-meta"><i class="fa-solid fa-tag"></i><?php echo htmlspecialchars($primaryType); ?></div>
                <?php endif; ?>
                <h5><a href="<?php echo $venueHref; ?>"><?php echo htmlspecialchars($v['name']); ?></a></h5>
                <div class="evt-venue"><i class="fa-solid fa-calendar"></i><?php echo $nextText; ?></div>
                <a href="<?php echo $venueHref; ?>" class="btn-pass">View venue <i class="fa-solid fa-arrow-right"></i></a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="empty-state" id="emptyState" style="<?= $totalVenues === 0 ? 'display:block;' : '' ?>">
          <i class="fa-solid fa-champagne-glasses"></i>
          <h5>No venues match your filters</h5>
          <p>Try widening your venue type filter or search term, or check another city.</p>
          <button class="btn-pink" id="emptyClearBtn">Clear all filters</button>
        </div>

        <nav class="pagination-hs" id="pagination" aria-label="Venues pagination"></nav>
      </div>
    </div>
  </div>
</section>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  var grid = document.getElementById('venueGrid');
  var items = Array.prototype.slice.call(grid.querySelectorAll('.ven-item'));
  var pageSize = 6;

  // Type slug -> display label, injected from PHP so chips/labels always match the DB.
  var catLabels = <?php echo json_encode($typeLabelBySlug, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

  var state = {
    search: '',
    cats: new Set(),
    sort: 'trending',
    page: 1
  };

  // mobile filter sheet
  var filterAside      = document.getElementById('filterAside');
  var filterBackdrop   = document.getElementById('filterBackdrop');
  var openFiltersBtn   = document.getElementById('openFiltersBtn');
  var filterSheetClose = document.getElementById('filterSheetClose');
  var filterSheetApply = document.getElementById('filterSheetApply');
  var filterCountBadge = document.getElementById('filterCountBadge');
  var sheetResultsCount = document.getElementById('sheetResultsCount');

  // mobile sort sheet
  var sortSheet      = document.getElementById('sortSheet');
  var sortBackdrop   = document.getElementById('sortBackdrop');
  var openSortBtn    = document.getElementById('openSortBtn');
  var sortSheetClose = document.getElementById('sortSheetClose');

  function itemCats(item){
    var c = item.dataset.cat || '';
    return c ? c.split(',').filter(Boolean) : [];
  }

  function matches(item){
    var d = item.dataset;
    var q = state.search.trim().toLowerCase();
    if (q && d.name.toLowerCase().indexOf(q) === -1) return false;

    if (state.cats.size){
      var cList = itemCats(item);
      var hit = cList.some(function(c){ return state.cats.has(c); });
      if (!hit) return false;
    }

    return true;
  }

  function sortItems(list){
    var arr = list.slice();
    if (state.sort === 'az') arr.sort(function(a,b){ return a.dataset.name.localeCompare(b.dataset.name); });
    else if (state.sort === 'new') arr.sort(function(a,b){ return parseInt(b.dataset.added,10) - parseInt(a.dataset.added,10); });
    else arr.sort(function(a,b){ return parseInt(a.dataset.order,10) - parseInt(b.dataset.order,10); });
    return arr;
  }

  function renderChips(){
    var wrap = document.getElementById('activeChips');
    wrap.innerHTML = '';
    function chip(label, onRemove){
      var el = document.createElement('span');
      el.className = 'active-chip';
      el.innerHTML = '<span></span><button aria-label="Remove filter"><i class="fa-solid fa-xmark"></i></button>';
      el.querySelector('span').textContent = label;
      el.querySelector('button').addEventListener('click', onRemove);
      wrap.appendChild(el);
    }
    state.cats.forEach(function(c){
      chip(catLabels[c] || c, function(){ state.cats.delete(c); syncCategoryUI(); state.page = 1; render(); });
    });
    if (state.search) chip('"' + state.search + '"', function(){ state.search=''; document.getElementById('venueSearch').value=''; state.page = 1; render(); });
  }

  // Only venue-type filters live in the sheet on this page - search stays
  // in the top toolbar, matching the events.php pattern.
  function updateFilterBadge(){
    if (!filterCountBadge) return;
    var n = state.cats.size;
    if (n > 0){
      filterCountBadge.textContent = n;
      filterCountBadge.style.display = 'inline-flex';
    } else {
      filterCountBadge.style.display = 'none';
    }
  }

  function openFilterSheet(){
    if (!filterAside) return;
    closeSortSheet();
    filterAside.classList.add('sheet-open');
    filterBackdrop.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
  function closeFilterSheet(){
    if (!filterAside) return;
    filterAside.classList.remove('sheet-open');
    filterBackdrop.classList.remove('show');
    if (!(sortSheet && sortSheet.classList.contains('sheet-open'))) {
      document.body.style.overflow = '';
    }
  }
  if (openFiltersBtn)   openFiltersBtn.addEventListener('click', openFilterSheet);
  if (filterSheetClose) filterSheetClose.addEventListener('click', closeFilterSheet);
  if (filterSheetApply) filterSheetApply.addEventListener('click', closeFilterSheet);
  if (filterBackdrop)   filterBackdrop.addEventListener('click', closeFilterSheet);

  function openSortSheet(){
    if (!sortSheet) return;
    closeFilterSheet();
    sortSheet.classList.add('sheet-open');
    sortBackdrop.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
  function closeSortSheet(){
    if (!sortSheet) return;
    sortSheet.classList.remove('sheet-open');
    sortBackdrop.classList.remove('show');
    if (!(filterAside && filterAside.classList.contains('sheet-open'))) {
      document.body.style.overflow = '';
    }
  }
  if (openSortBtn)    openSortBtn.addEventListener('click', openSortSheet);
  if (sortSheetClose) sortSheetClose.addEventListener('click', closeSortSheet);
  if (sortBackdrop)   sortBackdrop.addEventListener('click', closeSortSheet);

  document.querySelectorAll('.sort-radio').forEach(function(r){
    r.addEventListener('change', function(){
      if (!r.checked) return;
      state.sort = r.value;
      document.getElementById('sortSelect').value = r.value;
      state.page = 1;
      render();
      closeSortSheet();
    });
  });

  function syncCategoryUI(){
    document.querySelectorAll('.cat-chk').forEach(function(cb){ cb.checked = state.cats.has(cb.value); });
    document.getElementById('fcAll').checked = state.cats.size === 0;
    document.querySelectorAll('#quickCatPills .filter-pill').forEach(function(p){
      var c = p.getAttribute('data-cat');
      var isActive = (c === '' && state.cats.size === 0) || (c !== '' && state.cats.size === 1 && state.cats.has(c));
      p.classList.toggle('active', isActive);
    });
  }

  function syncSortUI(){
    document.querySelectorAll('.sort-radio').forEach(function(r){ r.checked = (r.value === state.sort); });
  }

  function render(){
    var filtered = items.filter(matches);
    var sorted = sortItems(filtered);

    document.getElementById('resultsNum').textContent = sorted.length;
    document.getElementById('liveCount').textContent = sorted.length;
    if (sheetResultsCount) sheetResultsCount.textContent = sorted.length;

    var totalPages = Math.max(1, Math.ceil(sorted.length / pageSize));
    if (state.page > totalPages) state.page = totalPages;
    var start = (state.page - 1) * pageSize;
    var pageItems = sorted.slice(start, start + pageSize);

    items.forEach(function(it){ it.style.display = 'none'; });
    pageItems.forEach(function(it){ it.style.display = ''; grid.appendChild(it); });

    document.getElementById('emptyState').style.display = sorted.length === 0 ? 'block' : 'none';
    grid.style.display = sorted.length === 0 ? 'none' : '';

    renderPagination(totalPages);
    renderChips();
    updateFilterBadge();
  }

  function renderPagination(totalPages){
    var nav = document.getElementById('pagination');
    nav.innerHTML = '';
    if (totalPages <= 1) return;
    function btn(label, page, opts){
      opts = opts || {};
      var b = document.createElement('button');
      b.className = 'page-btn' + (opts.active ? ' active' : '') + (opts.disabled ? ' disabled' : '');
      b.innerHTML = label;
      if (!opts.disabled){
        b.addEventListener('click', function(){ state.page = page; render(); window.scrollTo({top: document.getElementById('venueGrid').offsetTop - 120, behavior:'smooth'}); });
      }
      return b;
    }
    nav.appendChild(btn('<i class="fa-solid fa-chevron-left"></i>', state.page - 1, {disabled: state.page === 1}));
    for (var p = 1; p <= totalPages; p++){ nav.appendChild(btn(String(p), p, {active: p === state.page})); }
    nav.appendChild(btn('<i class="fa-solid fa-chevron-right"></i>', state.page + 1, {disabled: state.page === totalPages}));
  }

  document.getElementById('venueSearch').addEventListener('input', function(e){ state.search = e.target.value; state.page = 1; render(); });
  document.getElementById('sortSelect').addEventListener('change', function(e){ state.sort = e.target.value; state.page = 1; syncSortUI(); render(); });

  document.getElementById('fcAll').addEventListener('change', function(){
    state.cats.clear();
    document.querySelectorAll('.cat-chk').forEach(function(cb){ cb.checked = false; });
    state.page = 1; render();
  });
  document.querySelectorAll('.cat-chk').forEach(function(cb){
    cb.addEventListener('change', function(){
      if (cb.checked) state.cats.add(cb.value); else state.cats.delete(cb.value);
      document.getElementById('fcAll').checked = state.cats.size === 0;
      state.page = 1; render();
    });
  });
  document.querySelectorAll('#quickCatPills .filter-pill').forEach(function(p){
    p.addEventListener('click', function(){
      var c = p.getAttribute('data-cat');
      state.cats.clear();
      if (c) state.cats.add(c);
      syncCategoryUI();
      state.page = 1; render();
    });
  });

  document.getElementById('clearFilters').addEventListener('click', clearAll);
  document.getElementById('emptyClearBtn').addEventListener('click', clearAll);
  function clearAll(){
    state.search = ''; document.getElementById('venueSearch').value = '';
    state.cats.clear();
    state.sort = 'trending'; document.getElementById('sortSelect').value = 'trending';
    state.page = 1;
    syncCategoryUI(); syncSortUI();
    render();
  }

  document.querySelectorAll('#viewToggle button').forEach(function(b){
    b.addEventListener('click', function(){
      document.querySelectorAll('#viewToggle button').forEach(function(x){ x.classList.remove('active'); });
      b.classList.add('active');
      grid.classList.toggle('list-view', b.getAttribute('data-view') === 'list');
    });
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

  render();
})();
</script>

<?php include 'include/footer.php'; ?>