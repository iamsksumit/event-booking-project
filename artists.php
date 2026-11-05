<?php
include('include/config.php');

/* =========================================================================
   CITY RESOLUTION
   -------------------------------------------------------------------------
   Must happen before we query artists, since the whole point of this page
   is now "artists relevant to the current city". header.php does its own
   require_once for this same file later - PHP dedupes by resolved real
   path, so no double DB work.

   Exposes: $currentCityId, $currentCityName, $currentCitySlug,
            $currentCityEsc, $allCities, hs_city_url().
   ========================================================================= */
include_once('include/city.php');

/* =========================================================================
   SCHEMA ASSUMPTIONS — adjust the column/table names below if yours differ.
   Everything that touches the database lives near the top of this file, so
   you only need to edit here if a name doesn't match your real schema.

   artist table:
     id, name, url, image_url, genre (comma-separated, optional),
     status (1 = active), created_at (datetime, optional),
     city (VARCHAR, optional - artist's home base; NEW, see below)
     -> "genre" is currently empty for every artist. Until it's populated,
        each artist's tag is instead derived from the categories of the
        events they're linked to (below). The moment "genre" has a real
        value for an artist, that value takes priority and categories are
        ignored for that artist.
     -> "city" is optional. If your artist table doesn't have it yet, run:
          ALTER TABLE artist ADD COLUMN city VARCHAR(150) NULL;
        The city-scoping below degrades gracefully (home-base matching just
        won't apply) if the column is missing - artists then only show up
        via an upcoming event in the current city.

   category table:
     id, cat_name, cat_slug, image

   event_category table:
     id, event_id, cat_id

   events table:
     id, status, start (date), multiple_dates (comma-separated dates),
     sub_tittle (place id)

   place table:
     id, name, city

   event_artist table:
     event_id, artist_id
   ========================================================================= */

// Fetch data based on the page type
$title = 'Artist Profiles on Highspinn - Events, Activities & Things To Do - HIGHSPINN';
$description = 'Find out top artists and their events & activities in ' . $currentCityName . '. Follow your favourite artists on HighApe and never miss out their events.';
$image = 'https://www.highspinn.com/img/logo.png';
$url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

if (isset($pageData)) {
    $title = htmlspecialchars($pageData['title'] ?? $title);
    $description = htmlspecialchars($pageData['description'] ?? $description);
    $image = htmlspecialchars($pageData['image'] ?? $image);
    $url = htmlspecialchars($pageData['url'] ?? $url);
}

$today = date('Y-m-d');

function hs_slugify_genre($g) {
    return strtolower(trim(preg_replace('/\s+/', '-', $g)));
}

// Does the artist table have a `city` column? Checked once, used below -
// same pattern index.php already uses.
$artistHasCityCol = false;
$colCheck = $conn->query("SHOW COLUMNS FROM `artist` LIKE 'city'");
if ($colCheck && $colCheck->num_rows > 0) { $artistHasCityCol = true; }

/* ---------- 1. Fetch all active artists ---------- */
$artists = [];
$artistSelectCols = "id, name, url, image_url, genre, created_at" . ($artistHasCityCol ? ", city" : "");
$res = $conn->query("SELECT $artistSelectCols FROM artist WHERE status = 1");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $ownGenres = array_values(array_filter(array_map('trim', explode(',', $row['genre'] ?? ''))));
        $artists[$row['id']] = [
            'id'             => (int)$row['id'],
            'name'           => $row['name'],
            'url'            => $row['url'],
            'image'          => $row['image_url'],
            'home_city'      => $artistHasCityCol ? ($row['city'] ?? '') : '',
            'own_genres'     => $ownGenres, // real genre data, once the artist table has it
            'genres'         => $ownGenres, // final tags used for display/filtering — may be replaced by category fallback below
            'created_at'     => $row['created_at'] ?? null,
            'next_date'      => null,
            'next_venue'     => null,
            'upcoming_count' => 0,
            'is_local'       => false,
        ];
    }
}

/* ---------- 2. Fetch upcoming events (date + venue), SCOPED TO THIS CITY ----------
   Only events at a venue whose place.city matches the current city count
   towards "upcoming here" / next date / next venue. An artist playing in a
   different city doesn't get credited with a show here. */
$evRes = $conn->query("
    SELECT
    event_artist.artist_id,
    events.start,
    events.multiple_dates,
    events.sub_tittle,
    place.name AS place_name
FROM events
INNER JOIN event_artist
    ON events.id = event_artist.event_id
INNER JOIN place
    ON events.sub_tittle = place.id
WHERE events.status = 1
AND LOWER(place.city) = LOWER('{$currentCityEsc}');
");
if ($evRes) {
    while ($ev = $evRes->fetch_assoc()) {
        $aid = $ev['artist_id'];
        if (!isset($artists[$aid])) continue;

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

        $artists[$aid]['upcoming_count']++;
        if ($artists[$aid]['next_date'] === null || $soonest < $artists[$aid]['next_date']) {
            $artists[$aid]['next_date']  = $soonest;
            $artists[$aid]['next_venue'] = !empty($ev['place_name']) ? $ev['place_name'] : 'TBA';
        }
    }
}

/* ---------- 2b. Mark artists whose home base is this city ---------- */
if ($artistHasCityCol) {
    foreach ($artists as $aid => &$aRef) {
        if ($aRef['home_city'] !== '' && strcasecmp($aRef['home_city'], $currentCityName) === 0) {
            $aRef['is_local'] = true;
        }
    }
    unset($aRef);
}

/* ---------- 2c. Keep only artists relevant to this city ----------
   Relevant = has an upcoming show here (upcoming_count > 0) OR is based
   here (is_local). Everyone else is filtered out of the listing entirely -
   this is what makes the page city-specific while still surfacing a
   touring artist from elsewhere who has a date in this city. */
$artists = array_filter($artists, function ($a) {
    return $a['upcoming_count'] > 0 || $a['is_local'];
});

/* ---------- 3. Fetch categories, and which categories each artist is tied to via their events ----------
   Deliberately NOT city-scoped: this only drives the genre/category tag
   shown on each card, not membership in the list - an artist already
   qualified for this city above via step 2/2b. */
$categoryById = [];
$catRes = $conn->query("SELECT id, cat_name, cat_slug, image FROM category");
if ($catRes) {
    while ($c = $catRes->fetch_assoc()) {
        $categoryById[$c['id']] = [
            'label' => $c['cat_name'],
            'slug'  => !empty($c['cat_slug']) ? $c['cat_slug'] : hs_slugify_genre($c['cat_name']),
            'image' => $c['image'],
        ];
    }
}

$artistCatIds = []; // artist_id => [cat_id => true, ...]
$acRes = $conn->query("
    SELECT event_artist.artist_id, event_category.cat_id
    FROM event_artist
    INNER JOIN events ON events.id = event_artist.event_id
    INNER JOIN event_category ON event_category.event_id = events.id
    WHERE events.status = 1
");
if ($acRes) {
    while ($row = $acRes->fetch_assoc()) {
        $aid = $row['artist_id'];
        if (!isset($artists[$aid])) continue;
        $artistCatIds[$aid][$row['cat_id']] = true;
    }
}

foreach ($artists as $aid => &$aRef) {
    if (!empty($aRef['own_genres'])) continue; // real genre data always wins once populated
    $labels = [];
    if (!empty($artistCatIds[$aid])) {
        foreach (array_keys($artistCatIds[$aid]) as $catId) {
            if (isset($categoryById[$catId])) {
                $labels[] = $categoryById[$catId]['label'];
            }
        }
    }
    $aRef['genres'] = $labels; // empty array if the artist has no events with categories yet — handled as "hidden" below
}
unset($aRef);

/* ---------- 4. Default order: performing artists (in this city) first, then by id desc ---------- */
$artistList = array_values($artists);
usort($artistList, function ($a, $b) {
    if ($a['upcoming_count'] !== $b['upcoming_count']) {
        return $b['upcoming_count'] <=> $a['upcoming_count'];
    }
    return $b['id'] <=> $a['id'];
});

/* ---------- 5. Build category facets dynamically (from the city-filtered list) ---------- */
$genreCounts      = [];
$genreLabelBySlug = [];

foreach ($artistList as $a) {
    foreach ($a['genres'] as $g) {
        $slug = hs_slugify_genre($g);
        if ($slug === '') continue;
        $genreLabelBySlug[$slug] = $g;
        $genreCounts[$slug] = ($genreCounts[$slug] ?? 0) + 1;
    }
}
arsort($genreCounts);
$topGenres = array_slice(array_keys($genreCounts), 0, 4);

$totalArtists = count($artistList);
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
.chk-row:last-child{ border-bottom:none; padding-bo<?php
/**
 * artists.php — ONE FILE for the Artists listing page.
 * Contains: device detection, city-scoped artist data, the mobile layout
 * and the desktop layout.
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

/* =========================================================================
   CITY RESOLUTION
   Exposes: $currentCityId, $currentCityName, $currentCitySlug,
            $currentCityEsc, $allCities, hs_city_url().
   ========================================================================= */
include_once('include/city.php');

/* =========================================================================
   SCHEMA ASSUMPTIONS (unchanged)
   artist: id, name, url, image_url, genre (comma-separated, optional),
           status (1 = active), created_at, city (optional)
   category: id, cat_name, cat_slug, image
   event_category: id, event_id, cat_id
   events: id, status, start, multiple_dates, sub_tittle (place id)
   place: id, name, city
   event_artist: event_id, artist_id
   If artist.city is missing, run:
     ALTER TABLE artist ADD COLUMN city VARCHAR(150) NULL;
   ========================================================================= */

$title = 'Artist Profiles on Highspinn - Events, Activities & Things To Do - HIGHSPINN';
$description = 'Find out top artists and their events & activities in ' . $currentCityName . '. Follow your favourite artists on HighApe and never miss out their events.';
$image = 'https://www.highspinn.com/img/logo.png';
$url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

if (isset($pageData)) {
    $title = htmlspecialchars($pageData['title'] ?? $title);
    $description = htmlspecialchars($pageData['description'] ?? $description);
    $image = htmlspecialchars($pageData['image'] ?? $image);
    $url = htmlspecialchars($pageData['url'] ?? $url);
}

$today = date('Y-m-d');

if (!function_exists('hs_slugify_genre')) {
    function hs_slugify_genre($g) {
        return strtolower(trim(preg_replace('/\s+/', '-', $g)));
    }
}

// Does the artist table have a `city` column?
$artistHasCityCol = false;
$colCheck = $conn->query("SHOW COLUMNS FROM `artist` LIKE 'city'");
if ($colCheck && $colCheck->num_rows > 0) { $artistHasCityCol = true; }

/* ---------- 1. Fetch all active artists ---------- */
$artists = [];
$artistSelectCols = "id, name, url, image_url, genre, created_at" . ($artistHasCityCol ? ", city" : "");
$res = $conn->query("SELECT $artistSelectCols FROM artist WHERE status = 1");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $ownGenres = array_values(array_filter(array_map('trim', explode(',', $row['genre'] ?? ''))));
        $artists[$row['id']] = [
            'id'             => (int)$row['id'],
            'name'           => $row['name'],
            'url'            => $row['url'],
            'image'          => $row['image_url'],
            'home_city'      => $artistHasCityCol ? ($row['city'] ?? '') : '',
            'own_genres'     => $ownGenres,
            'genres'         => $ownGenres,
            'created_at'     => $row['created_at'] ?? null,
            'next_date'      => null,
            'next_venue'     => null,
            'upcoming_count' => 0,
            'is_local'       => false,
        ];
    }
}

/* ---------- 2. Upcoming events (date + venue), SCOPED TO THIS CITY ---------- */
$evRes = $conn->query("
    SELECT
        event_artist.artist_id,
        events.start,
        events.multiple_dates,
        events.sub_tittle,
        place.name AS place_name
    FROM events
    INNER JOIN event_artist ON events.id = event_artist.event_id
    INNER JOIN place ON events.sub_tittle = place.id
    WHERE events.status = 1
    AND LOWER(place.city) = LOWER('{$currentCityEsc}')
");
if ($evRes) {
    while ($ev = $evRes->fetch_assoc()) {
        $aid = $ev['artist_id'];
        if (!isset($artists[$aid])) continue;

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

        $artists[$aid]['upcoming_count']++;
        if ($artists[$aid]['next_date'] === null || $soonest < $artists[$aid]['next_date']) {
            $artists[$aid]['next_date']  = $soonest;
            $artists[$aid]['next_venue'] = !empty($ev['place_name']) ? $ev['place_name'] : 'TBA';
        }
    }
}

/* ---------- 2b. Mark artists whose home base is this city ---------- */
if ($artistHasCityCol) {
    foreach ($artists as $aid => &$aRef) {
        if ($aRef['home_city'] !== '' && strcasecmp($aRef['home_city'], $currentCityName) === 0) {
            $aRef['is_local'] = true;
        }
    }
    unset($aRef);
}

/* ---------- 2c. Keep only artists relevant to this city ---------- */
$artists = array_filter($artists, function ($a) {
    return $a['upcoming_count'] > 0 || $a['is_local'];
});

/* ---------- 3. Categories + each artist's categories via their events ---------- */
$categoryById = [];
$catRes = $conn->query("SELECT id, cat_name, cat_slug, image FROM category");
if ($catRes) {
    while ($c = $catRes->fetch_assoc()) {
        $categoryById[$c['id']] = [
            'label' => $c['cat_name'],
            'slug'  => !empty($c['cat_slug']) ? $c['cat_slug'] : hs_slugify_genre($c['cat_name']),
            'image' => $c['image'],
        ];
    }
}

$artistCatIds = [];
$acRes = $conn->query("
    SELECT event_artist.artist_id, event_category.cat_id
    FROM event_artist
    INNER JOIN events ON events.id = event_artist.event_id
    INNER JOIN event_category ON event_category.event_id = events.id
    WHERE events.status = 1
");
if ($acRes) {
    while ($row = $acRes->fetch_assoc()) {
        $aid = $row['artist_id'];
        if (!isset($artists[$aid])) continue;
        $artistCatIds[$aid][$row['cat_id']] = true;
    }
}

foreach ($artists as $aid => &$aRef) {
    if (!empty($aRef['own_genres'])) continue;
    $labels = [];
    if (!empty($artistCatIds[$aid])) {
        foreach (array_keys($artistCatIds[$aid]) as $catId) {
            if (isset($categoryById[$catId])) {
                $labels[] = $categoryById[$catId]['label'];
            }
        }
    }
    $aRef['genres'] = $labels;
}
unset($aRef);

/* ---------- 4. Default order: performing artists first, then by id desc ---------- */
$artistList = array_values($artists);
usort($artistList, function ($a, $b) {
    if ($a['upcoming_count'] !== $b['upcoming_count']) {
        return $b['upcoming_count'] <=> $a['upcoming_count'];
    }
    return $b['id'] <=> $a['id'];
});

/* ---------- 5. Category facets (from the city-filtered list) ---------- */
$genreCounts      = [];
$genreLabelBySlug = [];

foreach ($artistList as $a) {
    foreach ($a['genres'] as $g) {
        $slug = hs_slugify_genre($g);
        if ($slug === '') continue;
        $genreLabelBySlug[$slug] = $g;
        $genreCounts[$slug] = ($genreCounts[$slug] ?? 0) + 1;
    }
}
arsort($genreCounts);
$topGenres = array_slice(array_keys($genreCounts), 0, 4);

$totalArtists = count($artistList);

include 'include/header.php';
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
.hidden{ display:none !important; }

/* ---- sticky header: logo + city pill + search ---- */
.ar-header{
  position:sticky; top:0; z-index:40; padding:14px 16px 12px;
  background:rgba(10,10,13,.95); backdrop-filter:blur(14px); border-bottom:1px solid var(--line);
}
.ar-top{ display:flex; align-items:center; justify-content:space-between; }
.ar-logo{ font-weight:800; font-size:20px; text-decoration:none; }
.ar-logo .hi{ color:var(--text); } .ar-logo .spinn{ color:var(--pink); }
.ar-locpill{
  display:flex; align-items:center; gap:6px; background:var(--panel-2); border:1px solid var(--line);
  padding:7px 12px; border-radius:100px; font-size:12.5px; font-weight:700; color:var(--text);
}
.ar-searchbar{
  margin-top:12px; display:flex; align-items:center; gap:9px; background:var(--panel-2);
  border:1px solid var(--line); border-radius:100px; padding:12px 15px;
}
.ar-searchbar input{ background:none; border:none; outline:none; color:var(--text); font-size:16px; width:100%; }
.ar-searchbar input::placeholder{ color:var(--muted-2); }
.ar-searchbar .clear-btn{ color:var(--muted-2); font-size:13px; cursor:pointer; padding:2px; display:none; }
.ar-searchbar .clear-btn.show{ display:block; }

/* ---- page title ---- */
.ar-title-wrap{ padding:18px 16px 4px; }
.ar-eyebrow{
  display:inline-flex; align-items:center; gap:7px; background:var(--panel-2); border:1px solid var(--line);
  border-radius:100px; padding:6px 12px; font-size:11px; font-weight:700; color:var(--muted);
}
.ar-eyebrow .dot{ width:6px; height:6px; border-radius:50%; background:var(--pink); }
.ar-title{ font-size:24px; font-weight:800; line-height:1.15; margin:12px 0 0; }

/* ---- category chips (scrolls away with page) ---- */
.ar-chip-wrap{ padding:14px 16px 4px; }
.ar-chip-row{ display:flex; gap:8px; overflow-x:auto; scrollbar-width:none; }
.ar-chip-row::-webkit-scrollbar{ display:none; }
.ar-chip{
  flex:0 0 auto; padding:9px 15px; border-radius:100px; font-size:12.5px; font-weight:700;
  background:var(--panel-2); border:1px solid var(--line); color:var(--muted); white-space:nowrap; cursor:pointer;
}
.ar-chip.active{ background:var(--pink); border-color:var(--pink); color:#fff; }

/* ---- count + sort row ---- */
.ar-bar{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:12px 16px 10px; }
.ar-count{ font-size:12px; color:var(--muted); font-weight:600; }
.ar-count b{ color:var(--text); }
.ar-sort{
  background:var(--panel-2); border:1px solid var(--line); color:var(--text);
  font-size:12.5px; font-weight:700; padding:8px 30px 8px 14px; border-radius:100px;
  outline:none; appearance:none; -webkit-appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2396969f'/%3E%3C/svg%3E");
  background-repeat:no-repeat; background-position:right 12px center;
}

/* ---- artist grid (2 columns) ---- */
.ar-grid{ display:grid; grid-template-columns:1fr 1fr; gap:10px; padding:0 16px; }
.ar-card{
  position:relative; display:flex; flex-direction:column; justify-content:flex-end;
  aspect-ratio:3/4; border-radius:16px; overflow:hidden; padding:11px;
  border:1px solid var(--line); color:#fff; text-decoration:none;
  background-size:cover; background-position:center;
}
.ar-card::before{ content:''; position:absolute; inset:0; background:linear-gradient(180deg, rgba(10,10,13,.05) 35%, rgba(10,10,13,.95) 100%); }
.ar-card > *{ position:relative; z-index:1; }
.ar-tag{
  position:absolute !important; top:10px; left:10px; max-width:calc(100% - 52px);
  font-size:9.5px; font-weight:800; text-transform:uppercase; letter-spacing:.03em;
  background:rgba(10,10,13,.6); backdrop-filter:blur(6px); border:1px solid rgba(255,255,255,.15);
  padding:4px 9px; border-radius:100px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.ar-heart{
  position:absolute !important; top:8px; right:8px; width:28px; height:28px; border-radius:50%;
  background:rgba(10,10,13,.6); backdrop-filter:blur(6px); border:1px solid rgba(255,255,255,.15);
  display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px;
}
.ar-local{
  display:inline-flex; align-self:flex-start; margin-bottom:6px; font-size:9.5px; font-weight:800;
  color:var(--green); background:rgba(61,220,132,.2); border:1px solid rgba(61,220,132,.45);
  padding:3px 8px; border-radius:100px;
}
.ar-name{ font-size:15px; font-weight:800; line-height:1.15; margin-bottom:5px; word-break:break-word; }
.ar-next{ font-size:10.5px; color:rgba(255,255,255,.75); line-height:1.35; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.ar-next i{ color:var(--yellow); margin-right:4px; }

.ec1{ background-image:linear-gradient(150deg,#7a1a2a,#2a0a10); }
.ec2{ background-image:linear-gradient(150deg,#7a1240,#280a1c); }
.ec3{ background-image:linear-gradient(150deg,#123a6e,#0a1830); }
.ec4{ background-image:linear-gradient(150deg,#5c1230,#1c0a14); }
.ec5{ background-image:linear-gradient(150deg,#0f3a3a,#0a1818); }
.ec6{ background-image:linear-gradient(150deg,#123a52,#0a1620); }
.ec7{ background-image:linear-gradient(150deg,#4a2a10,#1c1006); }
.ec8{ background-image:linear-gradient(150deg,#2a4a10,#101c06); }
.ec9{ background-image:linear-gradient(150deg,#3a1440,#160a20); }
.ec10{ background-image:linear-gradient(150deg,#5c1f12,#20100a); }
.ec11{ background-image:linear-gradient(150deg,#20205c,#0c0c22); }
.ec12{ background-image:linear-gradient(150deg,#4a3210,#1c1408); }

/* ---- empty + load more ---- */
.ar-empty{ text-align:center; padding:48px 16px; margin:0 16px; background:var(--panel); border:1px solid var(--line); border-radius:16px; display:none; }
.ar-empty i{ font-size:30px; color:var(--muted-2); margin-bottom:12px; }
.ar-empty h5{ font-size:16px; margin-bottom:6px; }
.ar-empty p{ color:var(--muted); font-size:12.5px; margin-bottom:16px; }
.ar-empty .btn-pink{ padding:11px 22px; }
.ar-more-wrap{ padding:16px; text-align:center; }
.ar-more{
  width:100%; padding:13px; border-radius:100px; background:var(--panel-2); border:1px solid var(--line);
  color:var(--text); font-size:13px; font-weight:700;
}
</style>

<div class="hs-app">

  <!-- STICKY: logo + city pill + search -->
  <div class="ar-header">
    <div class="ar-top">
      <a class="ar-logo" href="<?= hs_e(hs_city_url()) ?>"><span class="hi">HIGH</span><span class="spinn">SPINN</span></a>
      <button type="button" class="ar-locpill" data-bs-toggle="modal" data-bs-target="#cityModal">
        <i class="fa-solid fa-location-dot" style="color:var(--pink);"></i>
        <?= htmlspecialchars($currentCityName) ?>
        <i class="fa-solid fa-chevron-down small"></i>
      </button>
    </div>
    <div class="ar-searchbar">
      <i class="fa-solid fa-magnifying-glass" style="color:var(--muted-2);"></i>
      <input type="text" id="artistSearch" placeholder="Search by artist name" autocomplete="off">
      <i class="fa-solid fa-xmark clear-btn" id="artistSearchClear"></i>
    </div>
  </div>

  <div class="ar-title-wrap">
    <span class="ar-eyebrow"><span class="dot"></span> <span id="liveCount"><?= $totalArtists ?></span> artists live in <?= htmlspecialchars($currentCityName) ?></span>
    <h1 class="ar-title">The DJs setting <?= htmlspecialchars($currentCityName) ?> on fire.</h1>
  </div>

  <!-- CATEGORY CHIPS -->
  <div class="ar-chip-wrap">
    <div class="ar-chip-row" id="arChipRow">
      <button type="button" class="ar-chip active" data-cat="">All</button>
      <?php foreach ($genreCounts as $slug => $count): ?>
        <button type="button" class="ar-chip" data-cat="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($genreLabelBySlug[$slug]) ?></button>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- COUNT + SORT -->
  <div class="ar-bar">
    <span class="ar-count"><b id="resultsNum"><?= $totalArtists ?></b> artists</span>
    <select class="ar-sort" id="sortSelect" aria-label="Sort artists">
      <option value="trending">Trending</option>
      <option value="az">A &ndash; Z</option>
      <option value="new">Newly added</option>
    </select>
  </div>

  <!-- ARTIST GRID -->
  <div class="ar-grid" id="artistGrid">
    <?php
    $order = 0;
    foreach ($artistList as $a):
        $order++;
        $ecClass      = 'ec' . ((($order - 1) % 12) + 1);
        $genreLabels  = $a['genres'];
        $genreSlugs   = array_map('hs_slugify_genre', $genreLabels);
        $catTagText   = implode(' · ', array_slice($genreLabels, 0, 2));

        if ($a['next_date']) {
            $nextText = date('D, d M', strtotime($a['next_date'])) . ' · ' . $a['next_venue'];
        } elseif ($a['is_local']) {
            $nextText = 'Based in ' . $currentCityName;
        } else {
            $nextText = 'No upcoming events yet';
        }

        $addedVal = $a['created_at'] ? strtotime($a['created_at']) : $a['id'];
        $profileHref = hs_city_url('artists/' . $a['url']);
    ?>
    <a class="art-item ar-card <?= $ecClass ?>" href="<?= $profileHref ?>"
       style="<?php if (!empty($a['image'])): ?>background-image:url('<?= htmlspecialchars($a['image']) ?>');<?php endif; ?>"
       data-name="<?= htmlspecialchars($a['name']) ?>"
       data-genre="<?= htmlspecialchars(implode(',', $genreSlugs)) ?>"
       data-order="<?= $order ?>"
       data-added="<?= (int)$addedVal ?>">
      <?php if ($catTagText !== ''): ?><span class="ar-tag"><?= htmlspecialchars($catTagText) ?></span><?php endif; ?>
      <span class="ar-heart"><i class="fa-regular fa-heart"></i></span>
      <?php if ($a['upcoming_count'] === 0 && $a['is_local']): ?>
        <span class="ar-local"><i class="fa-solid fa-house-chimney me-1"></i>Local</span>
      <?php endif; ?>
      <div class="ar-name"><?= htmlspecialchars($a['name']) ?></div>
      <div class="ar-next"><i class="fa-solid fa-calendar"></i><?= htmlspecialchars($nextText) ?></div>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="ar-empty" id="emptyState" style="<?= $totalArtists === 0 ? 'display:block;' : '' ?>">
    <i class="fa-solid fa-record-vinyl"></i>
    <h5>No artists match</h5>
    <p>Try a different category or search term, or check another city.</p>
    <button type="button" class="btn-pink" id="emptyClearBtn">Clear filters</button>
  </div>

  <div class="ar-more-wrap hidden" id="moreWrap">
    <button type="button" class="ar-more" id="loadMoreBtn">Show more artists</button>
  </div>

</div>

<script>
document.body.classList.add('hs-app-view');

(function(){
  var grid = document.getElementById('artistGrid');
  var items = Array.prototype.slice.call(grid.querySelectorAll('.art-item'));
  var PAGE = 8;

  var state = { search:'', genre:'', sort:'trending', shown:PAGE };

  var searchInput = document.getElementById('artistSearch');
  var searchClear = document.getElementById('artistSearchClear');
  var moreWrap    = document.getElementById('moreWrap');
  var moreBtn     = document.getElementById('loadMoreBtn');

  function itemGenres(item){
    var g = item.dataset.genre || '';
    return g ? g.split(',').filter(Boolean) : [];
  }

  function matches(item){
    var q = state.search.trim().toLowerCase();
    if (q && item.dataset.name.toLowerCase().indexOf(q) === -1) return false;
    if (state.genre && itemGenres(item).indexOf(state.genre) === -1) return false;
    return true;
  }

  function sortItems(list){
    var arr = list.slice();
    if (state.sort === 'az') arr.sort(function(a,b){ return a.dataset.name.localeCompare(b.dataset.name); });
    else if (state.sort === 'new') arr.sort(function(a,b){ return parseInt(b.dataset.added,10) - parseInt(a.dataset.added,10); });
    else arr.sort(function(a,b){ return parseInt(a.dataset.order,10) - parseInt(b.dataset.order,10); });
    return arr;
  }

  function render(){
    var sorted = sortItems(items.filter(matches));

    document.getElementById('resultsNum').textContent = sorted.length;
    document.getElementById('liveCount').textContent  = sorted.length;

    items.forEach(function(it){ it.style.display = 'none'; });
    sorted.slice(0, state.shown).forEach(function(it){ it.style.display = ''; grid.appendChild(it); });

    document.getElementById('emptyState').style.display = sorted.length === 0 ? 'block' : 'none';
    grid.style.display = sorted.length === 0 ? 'none' : '';
    moreWrap.classList.toggle('hidden', sorted.length <= state.shown);
  }

  function resetAndRender(){ state.shown = PAGE; render(); }

  searchInput.addEventListener('input', function(){
    state.search = searchInput.value;
    searchClear.classList.toggle('show', searchInput.value.length > 0);
    resetAndRender();
  });
  searchClear.addEventListener('click', function(){
    searchInput.value = ''; state.search = '';
    searchClear.classList.remove('show');
    resetAndRender();
    searchInput.focus();
  });

  document.getElementById('sortSelect').addEventListener('change', function(e){
    state.sort = e.target.value;
    resetAndRender();
  });

  document.getElementById('arChipRow').addEventListener('click', function(e){
    var btn = e.target.closest('.ar-chip');
    if (!btn) return;
    document.querySelectorAll('.ar-chip').forEach(function(c){ c.classList.remove('active'); });
    btn.classList.add('active');
    state.genre = btn.dataset.cat || '';
    resetAndRender();
  });

  function clearAll(){
    state.search = ''; state.genre = ''; state.sort = 'trending';
    searchInput.value = ''; searchClear.classList.remove('show');
    document.getElementById('sortSelect').value = 'trending';
    document.querySelectorAll('.ar-chip').forEach(function(c){ c.classList.toggle('active', (c.dataset.cat || '') === ''); });
    resetAndRender();
  }
  document.getElementById('emptyClearBtn').addEventListener('click', clearAll);

  // Show more: tap, or auto-load as the button scrolls into view
  moreBtn.addEventListener('click', function(){ state.shown += PAGE; render(); });
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function(entries){
      if (entries[0].isIntersecting && !moreWrap.classList.contains('hidden')) {
        state.shown += PAGE; render();
      }
    }, { rootMargin:'200px' }).observe(moreBtn);
  }

  // Heart toggle (cards are links, so stop navigation)
  grid.addEventListener('click', function(e){
    var h = e.target.closest('.ar-heart');
    if (!h) return;
    e.preventDefault(); e.stopPropagation();
    var icon = h.querySelector('i');
    icon.classList.toggle('fa-regular');
    icon.classList.toggle('fa-solid');
    h.style.color = icon.classList.contains('fa-solid') ? '#ff3d68' : '#fff';
  });

  render();
})();
</script>

<?php
$activeTab = 'discover';
if (is_file(__DIR__ . '/include/app-bottombar.php')) include 'include/app-bottombar.php';
include 'include/footerapp.php';
?>

<?php else: ?>
<?php /* =====================================================================
   DESKTOP LAYOUT
   ===================================================================== */ ?>

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
.local-badge{
  position:absolute; top:10px; right:44px; z-index:2; color:#fff; font-size:.68rem; font-weight:800;
  background:rgba(61,220,132,.25); border:1px solid rgba(61,220,132,.5); color:var(--green);
  padding:.22rem .55rem; border-radius:999px; backdrop-filter:blur(6px);
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

/* ---------- mobile filter bar + bottom sheets (filter + sort) ---------- */
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
    <div class="breadcrumb-hs"><a href="<?= hs_city_url() ?>">Home</a> <i class="fa-solid fa-chevron-right mx-1" style="font-size:.6rem;"></i> Artists</div>
    <span class="hero-eyebrow-pill"><span class="dot"></span> <span id="liveCount"><?php echo $totalArtists; ?></span> artists live right now in <?= htmlspecialchars($currentCityName) ?></span>
    <h1 class="mt-3">The DJs setting <span class="grad-text"><?= htmlspecialchars($currentCityName) ?> on fire.</span></h1>
    <p class="lead-c">From commercial Bollywood remixes to deep techno &mdash; filter by category to find the artist behind your next favourite night in <?= htmlspecialchars($currentCityName) ?>.</p>

    <div class="toolbar-strip">
      <div class="search-pill">
        <i class="fa-solid fa-magnifying-glass text-muted-c2 small"></i>
        <input type="text" id="artistSearch" placeholder="Search by artist name">
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

<!-- ================= ARTISTS + FILTERS ================= -->
<section class="pt-0">
  <div class="container">
    <div class="row g-4">

      <!-- backdrop for the mobile filter sheet -->
      <div class="filter-backdrop" id="filterBackdrop"></div>

      <!-- backdrop for the mobile sort sheet -->
      <div class="sort-backdrop" id="sortBackdrop"></div>

      <!-- ---------- SIDEBAR FILTERS (becomes a bottom sheet on small screens) ---------- -->
      <aside class="col-lg-3" id="filterAside">
        <div class="filter-sheet-header">
          <h5>Filters</h5>
          <button type="button" id="filterSheetClose" aria-label="Close filters"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="filter-card">
          <h6>Category</h6>
          <div class="chk-row"><div><input class="form-check-input me-2" type="checkbox" id="fcAll" checked><label for="fcAll">All categories</label></div></div>
          <?php foreach ($genreCounts as $slug => $count): ?>
          <div class="chk-row">
            <div>
              <input class="form-check-input me-2 cat-chk" type="checkbox" value="<?php echo htmlspecialchars($slug); ?>" id="fc-<?php echo htmlspecialchars($slug); ?>">
              <label for="fc-<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($genreLabelBySlug[$slug]); ?></label>
            </div>
            <span class="cnt"><?php echo (int)$count; ?></span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($genreCounts)): ?>
          <p class="text-muted-c small mb-0">No categories tagged yet.</p>
          <?php endif; ?>
        </div>

        <button class="btn-clear-filters mt-2" id="clearFilters"><i class="fa-solid fa-rotate-left me-2"></i>Clear all filters</button>

        <div class="filter-sheet-footer">
          <button type="button" id="filterSheetApply">Show <span id="sheetResultsCount"><?php echo $totalArtists; ?></span> artists</button>
        </div>
      </aside>

      <!-- ---------- SORT SHEET ---------- -->
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

      <!-- fixed bottom bar, small screens only -->
      <div class="mobile-filter-bar">
        <button type="button" id="openSortBtn">
          <i class="fa-solid fa-arrow-up-wide-short"></i> Sort
        </button>
        <button type="button" id="openFiltersBtn">
          <i class="fa-solid fa-sliders"></i> Filters
          <span class="filter-count-badge" id="filterCountBadge" style="display:none;">0</span>
        </button>
      </div>

      <!-- ---------- ARTIST GRID ---------- -->
      <div class="col-lg-9">
        <div class="section-head" style="margin-bottom:1rem;">
          <span class="results-count"><b id="resultsNum"><?php echo $totalArtists; ?></b> artists found in <?= htmlspecialchars($currentCityName) ?></span>
          <div class="filter-pills" id="quickCatPills">
            <span class="filter-pill active" data-cat="">All</span>
            <?php foreach ($topGenres as $slug): ?>
            <span class="filter-pill" data-cat="<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($genreLabelBySlug[$slug]); ?></span>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="active-chips" id="activeChips"></div>

        <div class="row g-4" id="artistGrid">
          <?php
          $order = 0;
          foreach ($artistList as $a):
              $order++;
              $ecClass      = 'ec' . ((($order - 1) % 12) + 1);
              $genreLabels  = $a['genres'];
              $genreSlugs   = array_map('hs_slugify_genre', $genreLabels);
              $primaryGenre = $genreLabels[0] ?? '';
              $catTagText   = implode(' &middot; ', array_slice($genreLabels, 0, 2));

              if ($a['next_date']) {
                  $nextText = 'Next: ' . date('D, d M', strtotime($a['next_date'])) . ' &middot; ' . htmlspecialchars($a['next_venue']);
              } elseif ($a['is_local']) {
                  $nextText = 'Based in ' . htmlspecialchars($currentCityName) . ' &middot; no upcoming shows yet';
              } else {
                  $nextText = 'No upcoming events yet';
              }

              $addedVal = $a['created_at'] ? strtotime($a['created_at']) : $a['id'];
              $profileHref = hs_city_url('artists/' . $a['url']);
          ?>
          <div class="col-sm-6 col-xl-4 art-item"
               data-name="<?php echo htmlspecialchars($a['name']); ?>"
               data-genre="<?php echo htmlspecialchars(implode(',', $genreSlugs)); ?>"
               data-order="<?php echo $order; ?>"
               data-added="<?php echo (int)$addedVal; ?>">
            <div class="evt-card"><a href="<?php echo $profileHref; ?>">
              <div class="evt-media <?php echo $ecClass; ?>"<?php if (!empty($a['image'])): ?> style="background-image:url('<?php echo htmlspecialchars($a['image']); ?>');background-size:cover;background-position:center;"<?php endif; ?>>
                <div class="streaks"></div>
                <span class="heart-btn"><i class="fa-regular fa-heart"></i></span>
                <?php if ($a['upcoming_count'] === 0 && $a['is_local']): ?>
                <span class="local-badge"><i class="fa-solid fa-house-chimney me-1"></i>Local</span>
                <?php endif; ?>
                <?php if ($catTagText !== ''): ?>
                <span class="cat-tag"><?php echo $catTagText; ?></span>
                <?php endif; ?>
              </div></a>
              <div class="evt-body">
                <?php if ($primaryGenre !== ''): ?>
                <div class="evt-meta"><i class="fa-solid fa-music"></i><?php echo htmlspecialchars($primaryGenre); ?></div>
                <?php endif; ?>
                <h5><a href="<?php echo $profileHref; ?>"><?php echo htmlspecialchars($a['name']); ?></a></h5>
                <div class="evt-venue"><i class="fa-solid fa-calendar"></i><?php echo $nextText; ?></div>
                <a href="<?php echo $profileHref; ?>" class="btn-pass">View profile <i class="fa-solid fa-arrow-right"></i></a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="empty-state" id="emptyState" style="<?= $totalArtists === 0 ? 'display:block;' : '' ?>">
          <i class="fa-solid fa-record-vinyl"></i>
          <h5>No artists match your filters</h5>
          <p>Try widening your category filter or search term, or check another city.</p>
          <button class="btn-pink" id="emptyClearBtn">Clear all filters</button>
        </div>

        <nav class="pagination-hs" id="pagination" aria-label="Artists pagination"></nav>
      </div>
    </div>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  var grid = document.getElementById('artistGrid');
  var items = Array.prototype.slice.call(grid.querySelectorAll('.art-item'));
  var pageSize = 6;

  // Genre slug -> display label, injected from PHP so chips/labels always match the DB.
  var genreLabels = <?php echo json_encode($genreLabelBySlug, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

  var state = {
    search: '',
    genres: new Set(),
    sort: 'trending',
    page: 1
  };

  // filter sheet (small screens)
  var filterAside      = document.getElementById('filterAside');
  var filterBackdrop   = document.getElementById('filterBackdrop');
  var openFiltersBtn   = document.getElementById('openFiltersBtn');
  var filterSheetClose = document.getElementById('filterSheetClose');
  var filterSheetApply = document.getElementById('filterSheetApply');
  var filterCountBadge = document.getElementById('filterCountBadge');
  var sheetResultsCount = document.getElementById('sheetResultsCount');

  // sort sheet (small screens)
  var sortSheet      = document.getElementById('sortSheet');
  var sortBackdrop   = document.getElementById('sortBackdrop');
  var openSortBtn    = document.getElementById('openSortBtn');
  var sortSheetClose = document.getElementById('sortSheetClose');

  function itemGenres(item){
    var g = item.dataset.genre || '';
    return g ? g.split(',').filter(Boolean) : [];
  }

  function matches(item){
    var d = item.dataset;
    var q = state.search.trim().toLowerCase();
    if (q && d.name.toLowerCase().indexOf(q) === -1) return false;

    if (state.genres.size){
      var gList = itemGenres(item);
      var hit = gList.some(function(g){ return state.genres.has(g); });
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
    state.genres.forEach(function(g){
      chip(genreLabels[g] || g, function(){ state.genres.delete(g); syncGenreUI(); state.page = 1; render(); });
    });
    if (state.search) chip('"' + state.search + '"', function(){ state.search=''; document.getElementById('artistSearch').value=''; state.page = 1; render(); });
  }

  function updateFilterBadge(){
    if (!filterCountBadge) return;
    var n = state.genres.size;
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

  function syncGenreUI(){
    document.querySelectorAll('.cat-chk').forEach(function(cb){ cb.checked = state.genres.has(cb.value); });
    document.getElementById('fcAll').checked = state.genres.size === 0;
    document.querySelectorAll('#quickCatPills .filter-pill').forEach(function(p){
      var c = p.getAttribute('data-cat');
      var isActive = (c === '' && state.genres.size === 0) || (c !== '' && state.genres.size === 1 && state.genres.has(c));
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
        b.addEventListener('click', function(){ state.page = page; render(); window.scrollTo({top: document.getElementById('artistGrid').offsetTop - 120, behavior:'smooth'}); });
      }
      return b;
    }
    nav.appendChild(btn('<i class="fa-solid fa-chevron-left"></i>', state.page - 1, {disabled: state.page === 1}));
    for (var p = 1; p <= totalPages; p++){ nav.appendChild(btn(String(p), p, {active: p === state.page})); }
    nav.appendChild(btn('<i class="fa-solid fa-chevron-right"></i>', state.page + 1, {disabled: state.page === totalPages}));
  }

  document.getElementById('artistSearch').addEventListener('input', function(e){ state.search = e.target.value; state.page = 1; render(); });
  document.getElementById('sortSelect').addEventListener('change', function(e){ state.sort = e.target.value; state.page = 1; syncSortUI(); render(); });

  document.getElementById('fcAll').addEventListener('change', function(){
    state.genres.clear();
    document.querySelectorAll('.cat-chk').forEach(function(cb){ cb.checked = false; });
    state.page = 1; render();
  });
  document.querySelectorAll('.cat-chk').forEach(function(cb){
    cb.addEventListener('change', function(){
      if (cb.checked) state.genres.add(cb.value); else state.genres.delete(cb.value);
      document.getElementById('fcAll').checked = state.genres.size === 0;
      state.page = 1; render();
    });
  });
  document.querySelectorAll('#quickCatPills .filter-pill').forEach(function(p){
    p.addEventListener('click', function(){
      var c = p.getAttribute('data-cat');
      state.genres.clear();
      if (c) state.genres.add(c);
      syncGenreUI();
      state.page = 1; render();
    });
  });

  document.getElementById('clearFilters').addEventListener('click', clearAll);
  document.getElementById('emptyClearBtn').addEventListener('click', clearAll);
  function clearAll(){
    state.search = ''; document.getElementById('artistSearch').value = '';
    state.genres.clear();
    state.sort = 'trending'; document.getElementById('sortSelect').value = 'trending';
    state.page = 1;
    syncGenreUI(); syncSortUI();
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

<?php endif; // isMobile ?>ttom:0; }
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
.local-badge{
  position:absolute; top:10px; right:44px; z-index:2; color:#fff; font-size:.68rem; font-weight:800;
  background:rgba(61,220,132,.25); border:1px solid rgba(61,220,132,.5); color:var(--green);
  padding:.22rem .55rem; border-radius:999px; backdrop-filter:blur(6px);
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
    <div class="breadcrumb-hs"><a href="<?= hs_city_url() ?>">Home</a> <i class="fa-solid fa-chevron-right mx-1" style="font-size:.6rem;"></i> Artists</div>
    <span class="hero-eyebrow-pill"><span class="dot"></span> <span id="liveCount"><?php echo $totalArtists; ?></span> artists live right now in <?= htmlspecialchars($currentCityName) ?></span>
    <h1 class="mt-3">The DJs setting <span class="grad-text"><?= htmlspecialchars($currentCityName) ?> on fire.</span></h1>
    <p class="lead-c">From commercial Bollywood remixes to deep techno &mdash; filter by category to find the artist behind your next favourite night in <?= htmlspecialchars($currentCityName) ?>.</p>

    <div class="toolbar-strip">
      <div class="search-pill">
        <i class="fa-solid fa-magnifying-glass text-muted-c2 small"></i>
        <input type="text" id="artistSearch" placeholder="Search by artist name">
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

<!-- ================= ARTISTS + FILTERS ================= -->
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
          <h6>Category</h6>
          <div class="chk-row"><div><input class="form-check-input me-2" type="checkbox" id="fcAll" checked><label for="fcAll">All categories</label></div></div>
          <?php foreach ($genreCounts as $slug => $count): ?>
          <div class="chk-row">
            <div>
              <input class="form-check-input me-2 cat-chk" type="checkbox" value="<?php echo htmlspecialchars($slug); ?>" id="fc-<?php echo htmlspecialchars($slug); ?>">
              <label for="fc-<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($genreLabelBySlug[$slug]); ?></label>
            </div>
            <span class="cnt"><?php echo (int)$count; ?></span>
          </div>
          <?php endforeach; ?>
          <?php if (empty($genreCounts)): ?>
          <p class="text-muted-c small mb-0">No categories tagged yet.</p>
          <?php endif; ?>
        </div>

        <button class="btn-clear-filters mt-2" id="clearFilters"><i class="fa-solid fa-rotate-left me-2"></i>Clear all filters</button>

        <div class="filter-sheet-footer">
          <button type="button" id="filterSheetApply">Show <span id="sheetResultsCount"><?php echo $totalArtists; ?></span> artists</button>
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

      <!-- ---------- ARTIST GRID ---------- -->
      <div class="col-lg-9">
        <div class="section-head" style="margin-bottom:1rem;">
          <span class="results-count"><b id="resultsNum"><?php echo $totalArtists; ?></b> artists found in <?= htmlspecialchars($currentCityName) ?></span>
          <div class="filter-pills" id="quickCatPills">
            <span class="filter-pill active" data-cat="">All</span>
            <?php foreach ($topGenres as $slug): ?>
            <span class="filter-pill" data-cat="<?php echo htmlspecialchars($slug); ?>"><?php echo htmlspecialchars($genreLabelBySlug[$slug]); ?></span>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="active-chips" id="activeChips"></div>

        <div class="row g-4" id="artistGrid">
          <?php
          $order = 0;
          foreach ($artistList as $a):
              $order++;
              $ecClass      = 'ec' . ((($order - 1) % 12) + 1);
              $genreLabels  = $a['genres'];
              $genreSlugs   = array_map('hs_slugify_genre', $genreLabels);
              $primaryGenre = $genreLabels[0] ?? '';
              $catTagText   = implode(' &middot; ', array_slice($genreLabels, 0, 2));

              if ($a['next_date']) {
                  $nextText = 'Next: ' . date('D, d M', strtotime($a['next_date'])) . ' &middot; ' . htmlspecialchars($a['next_venue']);
              } elseif ($a['is_local']) {
                  $nextText = 'Based in ' . htmlspecialchars($currentCityName) . ' &middot; no upcoming shows yet';
              } else {
                  $nextText = 'No upcoming events yet';
              }

              $addedVal = $a['created_at'] ? strtotime($a['created_at']) : $a['id'];
              $profileHref = hs_city_url('artists/' . $a['url']);
          ?>
          <div class="col-sm-6 col-xl-4 art-item"
               data-name="<?php echo htmlspecialchars($a['name']); ?>"
               data-genre="<?php echo htmlspecialchars(implode(',', $genreSlugs)); ?>"
               data-order="<?php echo $order; ?>"
               data-added="<?php echo (int)$addedVal; ?>">
            <div class="evt-card"><a href="<?php echo $profileHref; ?>">
              <div class="evt-media <?php echo $ecClass; ?>"<?php if (!empty($a['image'])): ?> style="background-image:url('<?php echo htmlspecialchars($a['image']); ?>');background-size:cover;background-position:center;"<?php endif; ?>>
                <div class="streaks"></div>
                <span class="heart-btn"><i class="fa-regular fa-heart"></i></span>
                <?php if ($a['upcoming_count'] === 0 && $a['is_local']): ?>
                <span class="local-badge"><i class="fa-solid fa-house-chimney me-1"></i>Local</span>
                <?php endif; ?>
                <?php if ($catTagText !== ''): ?>
                <span class="cat-tag"><?php echo $catTagText; ?></span>
                <?php endif; ?>
              </div></a>
              <div class="evt-body">
                <?php if ($primaryGenre !== ''): ?>
                <div class="evt-meta"><i class="fa-solid fa-music"></i><?php echo htmlspecialchars($primaryGenre); ?></div>
                <?php endif; ?>
                <h5><a href="<?php echo $profileHref; ?>"><?php echo htmlspecialchars($a['name']); ?></a></h5>
                <div class="evt-venue"><i class="fa-solid fa-calendar"></i><?php echo $nextText; ?></div>
                <a href="<?php echo $profileHref; ?>" class="btn-pass">View profile <i class="fa-solid fa-arrow-right"></i></a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="empty-state" id="emptyState" style="<?= $totalArtists === 0 ? 'display:block;' : '' ?>">
          <i class="fa-solid fa-record-vinyl"></i>
          <h5>No artists match your filters</h5>
          <p>Try widening your category filter or search term, or check another city.</p>
          <button class="btn-pink" id="emptyClearBtn">Clear all filters</button>
        </div>

        <nav class="pagination-hs" id="pagination" aria-label="Artists pagination"></nav>
      </div>
    </div>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  var grid = document.getElementById('artistGrid');
  var items = Array.prototype.slice.call(grid.querySelectorAll('.art-item'));
  var pageSize = 6;

  // Genre slug -> display label, injected from PHP so chips/labels always match the DB.
  var genreLabels = <?php echo json_encode($genreLabelBySlug, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

  var state = {
    search: '',
    genres: new Set(),
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

  function itemGenres(item){
    var g = item.dataset.genre || '';
    return g ? g.split(',').filter(Boolean) : [];
  }

  function matches(item){
    var d = item.dataset;
    var q = state.search.trim().toLowerCase();
    if (q && d.name.toLowerCase().indexOf(q) === -1) return false;

    if (state.genres.size){
      var gList = itemGenres(item);
      var hit = gList.some(function(g){ return state.genres.has(g); });
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
    state.genres.forEach(function(g){
      chip(genreLabels[g] || g, function(){ state.genres.delete(g); syncGenreUI(); state.page = 1; render(); });
    });
    if (state.search) chip('"' + state.search + '"', function(){ state.search=''; document.getElementById('artistSearch').value=''; state.page = 1; render(); });
  }

  // Only category filters live in the sheet on this page - search stays in
  // the top toolbar, matching the events.php pattern.
  function updateFilterBadge(){
    if (!filterCountBadge) return;
    var n = state.genres.size;
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

  function syncGenreUI(){
    document.querySelectorAll('.cat-chk').forEach(function(cb){ cb.checked = state.genres.has(cb.value); });
    document.getElementById('fcAll').checked = state.genres.size === 0;
    document.querySelectorAll('#quickCatPills .filter-pill').forEach(function(p){
      var c = p.getAttribute('data-cat');
      var isActive = (c === '' && state.genres.size === 0) || (c !== '' && state.genres.size === 1 && state.genres.has(c));
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
        b.addEventListener('click', function(){ state.page = page; render(); window.scrollTo({top: document.getElementById('artistGrid').offsetTop - 120, behavior:'smooth'}); });
      }
      return b;
    }
    nav.appendChild(btn('<i class="fa-solid fa-chevron-left"></i>', state.page - 1, {disabled: state.page === 1}));
    for (var p = 1; p <= totalPages; p++){ nav.appendChild(btn(String(p), p, {active: p === state.page})); }
    nav.appendChild(btn('<i class="fa-solid fa-chevron-right"></i>', state.page + 1, {disabled: state.page === totalPages}));
  }

  document.getElementById('artistSearch').addEventListener('input', function(e){ state.search = e.target.value; state.page = 1; render(); });
  document.getElementById('sortSelect').addEventListener('change', function(e){ state.sort = e.target.value; state.page = 1; syncSortUI(); render(); });

  document.getElementById('fcAll').addEventListener('change', function(){
    state.genres.clear();
    document.querySelectorAll('.cat-chk').forEach(function(cb){ cb.checked = false; });
    state.page = 1; render();
  });
  document.querySelectorAll('.cat-chk').forEach(function(cb){
    cb.addEventListener('change', function(){
      if (cb.checked) state.genres.add(cb.value); else state.genres.delete(cb.value);
      document.getElementById('fcAll').checked = state.genres.size === 0;
      state.page = 1; render();
    });
  });
  document.querySelectorAll('#quickCatPills .filter-pill').forEach(function(p){
    p.addEventListener('click', function(){
      var c = p.getAttribute('data-cat');
      state.genres.clear();
      if (c) state.genres.add(c);
      syncGenreUI();
      state.page = 1; render();
    });
  });

  document.getElementById('clearFilters').addEventListener('click', clearAll);
  document.getElementById('emptyClearBtn').addEventListener('click', clearAll);
  function clearAll(){
    state.search = ''; document.getElementById('artistSearch').value = '';
    state.genres.clear();
    state.sort = 'trending'; document.getElementById('sortSelect').value = 'trending';
    state.page = 1;
    syncGenreUI(); syncSortUI();
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