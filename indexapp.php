<?php
/**
 * mobile-app.php
 * -----------------------------------------------------------------------
 * Mobile "app" view of HighSpinn. Reuses the exact same infrastructure as
 * index.php (config.php, header.php -> city.php + auth/city modals,
 * footer.php) so city switching, sign-in, and the search overlay all
 * keep working out of the box — only the body layout differs.
 *
 * Tapping a category chip reorders EVERY section (Headliners, Weekend
 * Top Picks, Trending Venues, Upcoming This Week, Featured DJs) so that
 * events matching the category float to the top — nothing is hidden.
 * -----------------------------------------------------------------------
 */

include('include/config.php');
require_once __DIR__ . '/include/event-helpers.php';

$title       = 'HighSpinn — Own the Night';
$description = 'Bangalore\'s nightlife, in your pocket. Ladies nights, techno sets and DJ takeovers, updated daily.';
$image       = 'https://www.highspinn.com/img/logo.png';
$url         = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

include 'include/header.php';               // gives us $conn, $currentCityName, $currentCityEsc,
                                              // $currentCitySlug, hs_city_url(), $isLoggedIn, $firstName,
                                              // #cityModal, #authModal, mobile search overlay + JS
include 'data/update_event_status.php';

$cityUrlBase = hs_city_url();                // e.g. "/bangalore/"
$today = date('Y-m-d');
$now   = new DateTime();

/* =========================================================
   FILTER CHIPS — categories that actually have a live event
   in this city, most active first (same ordering rule used
   elsewhere on the site).
   ========================================================= */
$chipCats = [];
$chipRes = $conn->query("
    SELECT c.cat_slug, c.cat_name,
           COUNT(DISTINCT CASE WHEN e.status = 1 THEN ec.event_id END) AS live_cnt
    FROM category c
    LEFT JOIN event_category ec ON ec.cat_id = c.id
    LEFT JOIN events e ON e.id = ec.event_id
    LEFT JOIN place p ON e.sub_tittle = p.id AND LOWER(p.city) = LOWER('{$currentCityEsc}')
    GROUP BY c.id
    HAVING live_cnt > 0
    ORDER BY live_cnt DESC, c.cat_name ASC
    LIMIT 6
");
if ($chipRes) { while ($r = $chipRes->fetch_assoc()) { $chipCats[] = $r; } }

/* =========================================================
   TONIGHT'S HEADLINERS (no category selected yet — default order)
   ========================================================= */
$headliners = hs_fetch_headliners($conn, $currentCityEsc, null, 99);

/* =========================================================
   WEEKEND TOP PICKS (default order)
   ========================================================= */
$weekendPool = hs_fetch_upcoming_events($conn, $currentCityEsc, null, 0, 24);
$weekendPicks = array_values(array_filter($weekendPool, function ($e) {
    $dow = (int)$e['_display_date']->format('w'); // 0=Sun ... 6=Sat
    return in_array($dow, [5, 6, 0], true);
}));
$weekendPicks = array_slice($weekendPicks, 0, 8);

/* =========================================================
   TRENDING VENUES (default order)
   ========================================================= */
[$venues, ] = hs_fetch_trending_venues($conn, $currentCityEsc, null, 5);

/* =========================================================
   FEATURED DJS (default order)
   ========================================================= */
[$djs, $artistUpcoming, ] = hs_fetch_featured_djs($conn, $currentCityEsc, null, 6);

/* =========================================================
   UPCOMING THIS WEEK — first page server-rendered; every
   subsequent page comes from ajax/load-more-events.php using
   the exact same helper, so markup never diverges. Switching
   category re-fetches page 1 via ajax/filter-sections.php.
   ========================================================= */
$UPCOMING_PAGE_SIZE = 6;
$initialUpcoming = hs_fetch_upcoming_events($conn, $currentCityEsc, null, 0, $UPCOMING_PAGE_SIZE + 1);
$upcomingHasMore = count($initialUpcoming) > $UPCOMING_PAGE_SIZE;
$initialUpcoming = array_slice($initialUpcoming, 0, $UPCOMING_PAGE_SIZE);
?>

<style>
/* Scoped to this view — hides the desktop navbar from header.php and
   replaces it with the compact app header + bottom tab bar below.
   The city modal, auth modal and mobile search overlay from header.php
   are left in the DOM and reused as-is (see JS at the bottom). */
body.hs-app-view .navbar-hs,
body.hs-app-view footer { display:none !important; }
body.hs-app-view { padding-bottom: 84px; }

.hs-app{ max-width:480px; margin:0 auto; }
.hidden{ display:none !important; }

/* ---- sticky header (logo + city pill + tagline + search bar ONLY) ---- */
.app-header-sticky{
  position:sticky; top:0; z-index:40; padding:16px 16px 14px;
  background:rgba(10,10,13,.95); backdrop-filter:blur(14px);
  border-bottom:1px solid var(--line);
}
.app-header-top{ display:flex; align-items:center; justify-content:space-between; }
.app-logo{ font-weight:800; font-size:20px; }
.app-logo .hi{ color:var(--text); } .app-logo .spinn{ color:var(--pink); }
.app-locpill{
  display:flex; align-items:center; gap:6px; background:var(--panel-2); border:1px solid var(--line);
  padding:7px 12px; border-radius:100px; font-size:12.5px; font-weight:700; color:var(--text);
}
.app-tagline{ margin-top:4px; font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:var(--muted-2); }
.app-searchbar{
  margin-top:12px; display:flex; align-items:center; gap:9px; background:var(--panel-2);
  border:1px solid var(--line); border-radius:100px; padding:12px 15px;
}
.app-searchbar input{ background:none; border:none; outline:none; color:var(--text); font-size:13.5px; width:100%; }
.app-searchbar .clear-btn{ color:var(--muted-2); font-size:13px; cursor:pointer; padding:2px; display:none; }
.app-searchbar .clear-btn.show{ display:block; }

/* ---- chip row: scrolls away with the page, NOT part of the sticky bar ---- */
.app-chip-wrap{ padding:14px 16px 4px; }
.app-chip-row{ display:flex; gap:8px; overflow-x:auto; scrollbar-width:none; }
.app-chip-row::-webkit-scrollbar{ display:none; }
.app-filter-chip{
  flex:0 0 auto; padding:9px 15px; border-radius:100px; font-size:12.5px; font-weight:700;
  background:var(--panel-2); border:1px solid var(--line); color:var(--muted); white-space:nowrap; cursor:pointer;
}
.app-filter-chip.active{ background:var(--pink); border-color:var(--pink); color:#fff; }

/* ---- search results panel (in-page, ajax) ---- */
.search-group-title{ font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--muted-2); margin:18px 0 10px; }
.search-group-title:first-child{ margin-top:0; }

/* ---- section shell ---- */
.app-section{ padding:22px 16px 2px; }
.app-section-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
.app-section-title{ font-size:17px; font-weight:800; margin:0; }
.app-see-all{ font-size:12px; font-weight:800; color:var(--pink); }

/* ---- priority highlight (applied to cards matching the selected chip) ---- */
/*.card-priority{ box-shadow: 0 0 0 2px var(--pink); position:relative; }*/
/*.headliner-card.card-priority{ box-shadow: 0 0 0 3px var(--pink); }*/
/*.dj-card.card-priority .dj-photo{ border-color:var(--pink); border-width:3px; }*/

/* ---- headliners ---- */
.headliner-scroll{ display:flex; gap:10px; overflow-x:auto; margin:0 -16px; padding:0 16px 4px; scrollbar-width:none; }
.headliner-scroll::-webkit-scrollbar{ display:none; }
.headliner-card{
  flex:0 0 auto; width:250px; height:310px; border-radius:18px; overflow:hidden; position:relative;
  background-size:cover; background-position:center; display:flex; flex-direction:column; justify-content:space-between;
  padding:13px; border:1px solid var(--line); color:#fff;
}
.headliner-card::before{ content:''; position:absolute; inset:0; background:linear-gradient(180deg, rgba(10,10,13,.05) 35%, rgba(10,10,13,.95) 100%); }
.headliner-card > *{ position:relative; z-index:1; }
.badge{ display:inline-flex; align-self:flex-start; padding:5px 11px; border-radius:100px; font-size:10px; font-weight:800; text-transform:uppercase; background:var(--pink); color:#fff; }
.h-date{ font-size:11px; color:var(--yellow); margin-bottom:5px; }
.h-title{ font-size:19px; font-weight:800; line-height:1.1; margin-bottom:6px; }
.h-venue{ font-size:11.5px; color:rgba(255,255,255,.75); }

/* ---- weekend grid ---- */
.grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.grid-card{ border-radius:14px; overflow:hidden; border:1px solid var(--line); background:var(--panel); color:var(--text); }
.grid-media{ height:100px; background-size:cover; background-position:center; position:relative; display:flex; align-items:flex-end; padding:7px; }
.grid-media::before{ content:''; position:absolute; inset:0; background:linear-gradient(180deg, rgba(0,0,0,0) 40%, rgba(0,0,0,.85) 100%); }
.grid-media span{ position:relative; z-index:1; font-size:9px; font-weight:800; background:rgba(255,255,255,.15); padding:3px 7px; border-radius:100px; color:#fff; text-transform:uppercase; }
.grid-body{ padding:9px; }
.grid-title{ font-size:12px; font-weight:800; line-height:1.3; margin-bottom:3px; }
.grid-meta{ font-size:10px; color:var(--muted-2); }

/* ---- venues ---- */
.venue-scroll{ display:flex; gap:10px; overflow-x:auto; scrollbar-width:none; }
.venue-scroll::-webkit-scrollbar{ display:none; }
.venue-card{ flex:0 0 auto; width:140px; border-radius:14px; overflow:hidden; border:1px solid var(--line); background:var(--panel); color:var(--text); }
.venue-media{ height:82px; background-size:cover; background-position:center; }
.venue-body{ padding:9px; }
.venue-name{ font-size:12px; font-weight:800; margin-bottom:2px; }
.venue-rating{ font-size:10.5px; color:var(--yellow); }

/* ---- upcoming list ---- */
.list-card{
  display:flex; gap:11px; background:var(--panel); border:1px solid var(--line); border-radius:14px;
  padding:11px; margin-bottom:10px; color:var(--text);
}
.list-thumb{ width:70px; height:70px; border-radius:10px; flex-shrink:0; background-size:cover; background-position:center; }
.list-body{ flex:1; min-width:0; display:flex; flex-direction:column; justify-content:center; gap:5px; }
.list-top{ display:flex; align-items:center; justify-content:space-between; gap:8px; }
.badge-sm{ padding:4px 9px; border-radius:100px; font-size:9px; font-weight:800; text-transform:uppercase; background:linear-gradient(100deg,var(--pink),var(--orange)); color:#fff; white-space:nowrap; }
.price-tag{ padding:4px 9px; border-radius:100px; font-size:9.5px; font-weight:800; background:var(--panel-2); border:1px solid var(--line); color:var(--yellow); white-space:nowrap; }
.price-tag.price-free{ background:var(--yellow); color:#241a00; border-color:var(--yellow); }
.list-title{ font-size:13.5px; font-weight:800; line-height:1.25; }
.list-meta{ font-size:11px; color:var(--muted-2); }

/* ---- DJs ---- */
.dj-scroll{ display:flex; gap:13px; overflow-x:auto; scrollbar-width:none; }
.dj-scroll::-webkit-scrollbar{ display:none; }
.dj-card{ flex:0 0 auto; width:88px; text-align:center; color:var(--text); }
.dj-photo{ width:74px; height:74px; border-radius:50%; margin:0 auto 7px; background-size:cover; background-position:center; border:2px solid var(--line); background-color:var(--panel-2); }
.dj-name{ font-size:11px; font-weight:800; }
.dj-venue{ font-size:9.5px; color:var(--muted-2); margin-top:2px; }

/* ---- loader / empty ---- */
.loader-row{ display:flex; align-items:center; justify-content:center; gap:9px; padding:16px 0; font-size:11.5px; color:var(--muted-2); }
.spinner{ width:15px; height:15px; border-radius:50%; border:2px solid var(--line); border-top-color:var(--pink); animation:hsspin .7s linear infinite; }
@keyframes hsspin{ to{ transform:rotate(360deg);} }
.end-msg{ text-align:center; padding:16px 0; font-size:11.5px; color:var(--muted-2); }
.empty-msg{ text-align:center; padding:30px 16px; font-size:12.5px; color:var(--muted-2); }

/* ---- bottom tab bar ---- */
.app-tabbar{
  position:fixed; bottom:0; left:0; right:0; z-index:1050;
  max-width:480px; margin:0 auto;
  display:flex; justify-content:space-around; align-items:center;
  padding:9px 6px calc(9px + env(safe-area-inset-bottom));
  background:rgba(15,15,18,.95); backdrop-filter:blur(16px); border-top:1px solid var(--line);
}
.app-tab{ display:flex; flex-direction:column; align-items:center; gap:3px; flex:1; padding:4px 0; background:none; border:none; color:var(--muted-2); font-size:10px; font-weight:700; cursor:pointer; }
.app-tab i{ font-size:18px; }
.app-tab.active{ color:var(--pink); }
</style>

<div class="hs-app">

  <!-- STICKY: logo + city pill + tagline + search bar ONLY -->
  <div class="app-header-sticky">
    <div class="app-header-top">
      <div class="app-logo"><span class="hi">HIGH</span><span class="spinn">SPINN</span></div>
      <button type="button" class="app-locpill" data-bs-toggle="modal" data-bs-target="#cityModal">
        <i class="fa-solid fa-location-dot" style="color:var(--pink);"></i>
        <?= hs_e($currentCityName) ?>
        <i class="fa-solid fa-chevron-down small"></i>
      </button>
    </div>
    <div class="app-tagline">Own the night</div>

    <div class="app-searchbar">
      <i class="fa-solid fa-magnifying-glass" style="color:var(--muted-2);"></i>
      <input type="text" id="appSearchInput" placeholder="Search events, clubs, DJs..." autocomplete="off">
      <i class="fa-solid fa-xmark clear-btn" id="appSearchClear"></i>
    </div>
  </div>

  <!-- SCROLLS AWAY: category chips -->
  <div class="app-chip-wrap">
    <div class="app-chip-row" id="appChipRow">
      <button type="button" class="app-filter-chip active" data-cat="">All</button>
      <?php foreach ($chipCats as $c): ?>
        <button type="button" class="app-filter-chip" data-cat="<?= hs_e($c['cat_slug']) ?>"><?= hs_e($c['cat_name']) ?></button>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- SEARCH RESULTS (ajax, in-page — replaces Discover while a query is active) -->
  <div class="app-section hidden" id="searchResultsWrap">
    <div class="app-section-head"><h2 class="app-section-title" id="searchResultsTitle">Search</h2></div>
    <div id="searchResultsBody"></div>
  </div>

  <!-- DISCOVER CONTENT -->
  <div id="discoverSections">

    <!-- TONIGHT'S HEADLINERS -->
    <div class="app-section<?= empty($headliners) ? ' hidden' : '' ?>" id="headlinersSection">
      <div class="app-section-head"><h2 class="app-section-title">Tonight's Headliners</h2></div>
      <div class="headliner-scroll" id="headlinersList">
        <?php foreach ($headliners as $ev) echo hs_render_headliner_card($ev, $cityUrlBase); ?>
      </div>
    </div>

        <!-- FEATURED DJs -->
    <div class="app-section<?= empty($djs) ? ' hidden' : '' ?>" id="djsSection" style="padding-bottom:24px;">
      <div class="app-section-head">
        <h2 class="app-section-title">Featured DJs</h2>
        <a class="app-see-all" href="<?= hs_e(hs_city_url('artists')) ?>">All artists</a>
      </div>
      <div class="dj-scroll" id="djsList">
        <?php foreach ($djs as $d):
          $badge = ($artistUpcoming[$d['id']] ?? 0) > 0
            ? $artistUpcoming[$d['id']] . ' show' . ($artistUpcoming[$d['id']] > 1 ? 's' : '') . ' this week'
            : (!empty($d['genre']) ? $d['genre'] : 'Featured');
          echo hs_render_dj_card($d, $cityUrlBase, $badge);
        endforeach; ?>
      </div>
    </div>


    <!-- WEEKEND TOP PICKS -->
    <div class="app-section<?= empty($weekendPicks) ? ' hidden' : '' ?>" id="weekendSection">
      <div class="app-section-head"><h2 class="app-section-title">Weekend Top Picks</h2></div>
      <div class="grid-2" id="weekendGrid">
        <?php foreach ($weekendPicks as $ev) echo hs_render_grid_card($ev, $cityUrlBase); ?>
      </div>
    </div>

    <!-- TRENDING VENUES -->
    <div class="app-section<?= empty($venues) ? ' hidden' : '' ?>" id="venuesSection">
      <div class="app-section-head">
        <h2 class="app-section-title">Trending Venues</h2>
        <a class="app-see-all" href="<?= hs_e(hs_city_url('venues')) ?>">See all</a>
      </div>
      <div class="venue-scroll" id="venuesList">
        <?php foreach ($venues as $v) echo hs_render_venue_card($v, $cityUrlBase); ?>
      </div>
    </div>
    
    



    <!-- UPCOMING THIS WEEK (infinite scroll + category reorder) -->
    <div class="app-section" id="upcomingSection">
      <div class="app-section-head">
        <h2 class="app-section-title" id="upcomingTitle">Upcoming This Week</h2>
      </div>
      <div id="upcomingList">
        <?php if (empty($initialUpcoming)): ?>
          <div class="empty-msg">No upcoming events in <?= hs_e($currentCityName) ?> right now. Check back soon.</div>
        <?php else: ?>
          <?php foreach ($initialUpcoming as $ev) echo hs_render_list_card($ev, $cityUrlBase); ?>
        <?php endif; ?>
      </div>
      <div class="loader-row hidden" id="loaderRow"><div class="spinner"></div>Loading more nights...</div>
      <div class="end-msg hidden" id="endMsg">You're all caught up ✨</div>
    </div>


  </div>
</div>

<!-- BOTTOM TAB BAR -->
<!--<div class="app-tabbar">-->
<!--  <button type="button" class="app-tab active" id="tabDiscover"><i class="fa-solid fa-compass"></i>Discover</button>-->
<!--  <button type="button" class="app-tab" id="tabSearch"><i class="fa-solid fa-magnifying-glass"></i>Search</button>-->
<!--  <button type="button" class="app-tab" id="tabPasses"><i class="fa-solid fa-ticket"></i>My Passes</button>-->
<!--  <button type="button" class="app-tab" id="tabProfile"><i class="fa-solid fa-circle-user"></i>Profile</button>-->
<!--</div>-->

<script>
document.body.classList.add('hs-app-view');

(function(){
  var cityUrlBase   = <?= json_encode($cityUrlBase) ?>;
  var citySlug      = <?= json_encode($currentCitySlug) ?>;
  var isLoggedIn    = <?= json_encode((bool)$isLoggedIn) ?>;

  var headlinersSection = document.getElementById('headlinersSection');
  var headlinersList    = document.getElementById('headlinersList');
  var weekendSection    = document.getElementById('weekendSection');
  var weekendGrid       = document.getElementById('weekendGrid');
  var venuesSection     = document.getElementById('venuesSection');
  var venuesList        = document.getElementById('venuesList');
  var djsSection        = document.getElementById('djsSection');
  var djsList           = document.getElementById('djsList');

  var upcomingList  = document.getElementById('upcomingList');
  var upcomingTitle = document.getElementById('upcomingTitle');
  var loaderRow     = document.getElementById('loaderRow');
  var endMsg        = document.getElementById('endMsg');
  var chipRow       = document.getElementById('appChipRow');

  var offset         = <?= (int)count($initialUpcoming) ?>;
  var hasMore         = <?= $upcomingHasMore ? 'true' : 'false' ?>;
  var loading         = false;
  var activeCategory  = '';
  var PAGE_SIZE       = <?= (int)$UPCOMING_PAGE_SIZE ?>;

  function toggleSection(sectionEl, count){
    sectionEl.classList.toggle('hidden', count === 0);
  }

  /* ---------------- Chip tap: reorder EVERY section, priority-first ---------------- */
  function filterAllSections(categorySlug, categoryLabel){
    loading = true;
    loaderRow.classList.remove('hidden');
    endMsg.classList.add('hidden');

    var params = new URLSearchParams({ city: citySlug, category: categorySlug });

    fetch('/ajax/filter-sections.php?' + params.toString())
      .then(function(r){ return r.text(); })
      .then(function(text){
        var data;
        try {
          data = JSON.parse(text);
        } catch (parseErr) {
          console.error('filter-sections.php returned non-JSON:', text);
          loaderRow.classList.add('hidden');
          loading = false;
          upcomingList.innerHTML = '<div class="empty-msg" style="color:#ff9aa8;">Server did not return valid JSON — check the console for the raw response from ajax/filter-sections.php.</div>';
          return;
        }

        if (data.error) {
          console.error('filter-sections.php error:', data.debug || data.error);
          loaderRow.classList.add('hidden');
          loading = false;
          upcomingList.innerHTML = '<div class="empty-msg" style="color:#ff9aa8;">' + data.error + (data.debug ? ': ' + data.debug : '') + '</div>';
          return;
        }

        // Headliners
        headlinersList.innerHTML = data.headliners.html;
        toggleSection(headlinersSection, data.headliners.count);

        // Weekend picks
        weekendGrid.innerHTML = data.weekend.html;
        toggleSection(weekendSection, data.weekend.count);

        // Trending venues
        venuesList.innerHTML = data.venues.html;
        toggleSection(venuesSection, data.venues.count);

        // Upcoming this week
        upcomingTitle.textContent = categoryLabel || 'Upcoming This Week';
        if (data.upcoming.count === 0) {
          upcomingList.innerHTML = '<div class="empty-msg">No upcoming events right now.</div>';
        } else {
          upcomingList.innerHTML = data.upcoming.html;
        }
        offset  = data.upcoming.count;
        hasMore = data.upcoming.hasMore;
        endMsg.classList.toggle('hidden', hasMore);

        // Featured DJs
        djsList.innerHTML = data.djs.html;
        toggleSection(djsSection, data.djs.count);

        loaderRow.classList.add('hidden');
        loading = false;
      })
      .catch(function(err){
        console.error('filter-sections.php request failed:', err);
        loaderRow.classList.add('hidden');
        loading = false;
      });
  }

  /* ---------------- Infinite scroll continuation for "Upcoming This Week" ---------------- */
  function fetchMoreUpcoming(){
    if (loading || !hasMore) return;
    loading = true;
    loaderRow.classList.remove('hidden');
    endMsg.classList.add('hidden');

    var params = new URLSearchParams({
      city: citySlug,
      category: activeCategory,
      offset: offset,
      limit: PAGE_SIZE
    });

    fetch('/ajax/load-more-events.php?' + params.toString())
      .then(function(r){ return r.text(); })
      .then(function(text){
        var data;
        try {
          data = JSON.parse(text);
        } catch (parseErr) {
          console.error('load-more-events.php returned non-JSON:', text);
          loaderRow.classList.add('hidden');
          loading = false;
          return;
        }

        if (data.error) {
          console.error('load-more-events.php error:', data.debug || data.error);
          loaderRow.classList.add('hidden');
          loading = false;
          return;
        }

        upcomingList.insertAdjacentHTML('beforeend', data.html);
        offset  = offset + data.count;
        hasMore = data.hasMore;
        loaderRow.classList.add('hidden');
        endMsg.classList.toggle('hidden', hasMore);
        loading = false;
      })
      .catch(function(err){
        console.error('load-more-events.php request failed:', err);
        loaderRow.classList.add('hidden');
        loading = false;
      });
  }

  window.addEventListener('scroll', function(){
    if (!searchResultsWrap.classList.contains('hidden')) return; // no infinite scroll while searching
    var scrollBottom = window.innerHeight + window.scrollY;
    var docHeight = document.body.offsetHeight;
    if (docHeight - scrollBottom < 300) fetchMoreUpcoming();
  });

  chipRow.addEventListener('click', function(e){
    var btn = e.target.closest('.app-filter-chip');
    if (!btn) return;
    if (btn.classList.contains('active')) return; // already selected, skip refetch

    document.querySelectorAll('.app-filter-chip').forEach(function(c){ c.classList.remove('active'); });
    btn.classList.add('active');
    activeCategory = btn.dataset.cat || '';

    filterAllSections(activeCategory, activeCategory ? btn.textContent.trim() : 'Upcoming This Week');

    // Bring the discover area into view since the chips can sit mid-scroll
    document.getElementById('discoverSections').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  /* ---------------- Same-page AJAX search ---------------- */
  var searchInput       = document.getElementById('appSearchInput');
  var searchClear       = document.getElementById('appSearchClear');
  var discoverSections  = document.getElementById('discoverSections');
  var searchResultsWrap = document.getElementById('searchResultsWrap');
  var searchResultsBody = document.getElementById('searchResultsBody');
  var searchResultsTitle = document.getElementById('searchResultsTitle');
  var searchDebounce;
  var searchRequestId = 0;

  function showDiscover(){
    searchResultsWrap.classList.add('hidden');
    discoverSections.classList.remove('hidden');
    searchClear.classList.remove('show');
  }

  function showSearchLoading(){
    discoverSections.classList.add('hidden');
    searchResultsWrap.classList.remove('hidden');
    searchClear.classList.add('show');
    searchResultsTitle.textContent = 'Searching…';
    searchResultsBody.innerHTML = '<div class="loader-row"><div class="spinner"></div>Searching...</div>';
  }

  function renderSearchResults(data){
    if (data.error) {
      searchResultsTitle.textContent = 'Search error';
      searchResultsBody.innerHTML =
        '<div class="empty-msg" style="color:#ff9aa8; text-align:left;">' +
        '<b>' + data.error + '</b><br>' + (data.debug || '') +
        '</div>';
      return;
    }

    searchResultsTitle.textContent = 'Results for "' + data.query + '"';
    var total = data.eventCount + data.artistCount + data.venueCount;

    if (total === 0) {
      searchResultsBody.innerHTML = '<div class="empty-msg">No events, artists or venues found for "' + data.query + '".</div>';
      return;
    }

    var html = '';
    if (data.eventCount > 0) {
      html += '<div class="search-group-title">Events (' + data.eventCount + ')</div>' + data.eventsHtml;
    }
    if (data.artistCount > 0) {
      html += '<div class="search-group-title">Artists (' + data.artistCount + ')</div>' + data.artistsHtml;
    }
    if (data.venueCount > 0) {
      html += '<div class="search-group-title">Venues (' + data.venueCount + ')</div>' + data.venuesHtml;
    }
    searchResultsBody.innerHTML = html;
  }

  function runSearch(q){
    q = q.trim();
    if (q.length < 2) { showDiscover(); return; }

    showSearchLoading();
    var thisRequestId = ++searchRequestId;

    var params = new URLSearchParams({ city: citySlug, q: q });
    fetch('/ajax/search.php?' + params.toString())
      .then(function(r){ return r.text(); })
      .then(function(text){
        if (thisRequestId !== searchRequestId) return;
        var data;
        try {
          data = JSON.parse(text);
        } catch (parseErr) {
          console.error('Search endpoint returned non-JSON:', text);
          searchResultsTitle.textContent = 'Search error';
          searchResultsBody.innerHTML =
            '<div class="empty-msg" style="color:#ff9aa8; text-align:left;">' +
            '<b>The server did not return valid JSON.</b><br>' +
            'Open the browser console or Network tab → ajax/search.php to see the raw response below:<br>' +
            '<pre style="white-space:pre-wrap; font-size:11px; opacity:.8; margin-top:8px;">' +
            text.replace(/</g, '&lt;').slice(0, 600) +
            '</pre></div>';
          return;
        }
        renderSearchResults(data);
      })
      .catch(function(err){
        if (thisRequestId !== searchRequestId) return;
        console.error('Search request failed:', err);
        searchResultsBody.innerHTML = '<div class="empty-msg">Network error — check your connection and try again.</div>';
      });
  }

  searchInput.addEventListener('input', function(){
    clearTimeout(searchDebounce);
    var val = searchInput.value;
    searchClear.classList.toggle('show', val.length > 0);
    searchDebounce = setTimeout(function(){ runSearch(val); }, 300);
  });

  searchInput.addEventListener('keydown', function(e){
    if (e.key === 'Enter') { e.preventDefault(); clearTimeout(searchDebounce); runSearch(searchInput.value); }
  });

  searchClear.addEventListener('click', function(){
    searchInput.value = '';
    searchClear.classList.remove('show');
    showDiscover();
    searchInput.focus();
  });

  /* ---------------- Bottom tabs ---------------- */
  function setActiveTab(id){
    document.querySelectorAll('.app-tab').forEach(function(t){ t.classList.toggle('active', t.id === id); });
  }

  document.getElementById('tabDiscover').addEventListener('click', function(){
    setActiveTab('tabDiscover');
    searchInput.value = '';
    showDiscover();
    window.scrollTo({top:0, behavior:'smooth'});
  });

  document.getElementById('tabSearch').addEventListener('click', function(){
    setActiveTab('tabSearch');
    window.scrollTo({top:0, behavior:'smooth'});
    setTimeout(function(){ searchInput.focus(); }, 250); // sticky bar is already on-screen, just focus it
  });

  document.getElementById('tabPasses').addEventListener('click', function(){
    if (!isLoggedIn) {
      var authModalEl = document.getElementById('authModal');
      if (authModalEl && window.bootstrap) new bootstrap.Modal(authModalEl).show();
      return;
    }
    window.location.href = cityUrlBase.replace(/\/$/, '') + '/account/#bookings';
  });

  document.getElementById('tabProfile').addEventListener('click', function(){
    if (!isLoggedIn) {
      var authModalEl = document.getElementById('authModal');
      if (authModalEl && window.bootstrap) new bootstrap.Modal(authModalEl).show();
      return;
    }
    window.location.href = cityUrlBase.replace(/\/$/, '') + '/account/';
  });
})();

window.hsGoDiscover = function(){
  document.getElementById('tabSearch') && setActiveTab('tabDiscover');
  searchInput.value = '';
  showDiscover();
  window.scrollTo({ top:0, behavior:'smooth' });
};

if (new URLSearchParams(location.search).get('focusSearch') === '1') {
  setTimeout(function(){ document.getElementById('tabSearch').click(); }, 200);
}
</script>
<?php 
$activeTab = 'discover'; // no tab is "active" on a detail page
include 'include/app-bottombar.php';
include 'include/footerapp.php'; ?>