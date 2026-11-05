<?php
/**
 * ajax/filter-sections.php
 * -----------------------------------------------------------------------
 * Called when the user taps a category chip on mobile-app.php. Rebuilds
 * every section (Headliners, Weekend Picks, Trending Venues, Upcoming
 * This Week, Featured DJs) for the given category in one round trip.
 *
 * IMPORTANT: this does NOT filter events out. It reorders each section
 * so events/venues/DJs matching the selected category appear first,
 * with everything else still visible right after them.
 *
 * GET/POST params:
 *   city     (required)  city slug, e.g. "bangalore"
 *   category (optional)  category slug, "" or omitted = no reordering
 *
 * Response: JSON {
 *   category,
 *   headliners: { html, count },
 *   weekend:    { html, count },
 *   venues:     { html, count },
 *   upcoming:   { html, count, hasMore },
 *   djs:        { html, count }
 * }
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../include/ajax-bootstrap.php';
try {
    require_once __DIR__ . '/../include/config.php';
    require_once __DIR__ . '/../include/event-helpers.php';

    if (!isset($conn) || !($conn instanceof mysqli)) {
        hs_ajax_error("config.php did not provide a mysqli \$conn — check its variable name.");
    }

    $citySlug = isset($_REQUEST['city']) ? trim($_REQUEST['city']) : '';
    $category = isset($_REQUEST['category']) ? trim($_REQUEST['category']) : '';
    $upcomingLimit = 6;

    if ($citySlug === '') {
        hs_ajax_error('Missing city', 400);
    }

    $cityName = hs_resolve_city_by_slug($conn, $citySlug);
    if ($cityName === null) {
        hs_ajax_error("Unknown city slug '{$citySlug}' — check the tbl_cities table/slug column names in hs_resolve_city_by_slug().", 404);
    }

    $cityEsc     = $conn->real_escape_string($cityName);
    $citySlugEsc = $conn->real_escape_string($citySlug);
    $categoryEsc = $category !== '' ? $conn->real_escape_string($category) : null;
    $cityUrlBase = '/' . $citySlugEsc . '/';

    // ---- Headliners ----
    $headliners = hs_fetch_headliners($conn, $cityEsc, $categoryEsc, 6);
    $headlinersHtml = '';
    foreach ($headliners as $ev) {
        $isPriority = $categoryEsc && !empty($ev['is_priority']);
        $headlinersHtml .= hs_render_headliner_card($ev, $cityUrlBase, $isPriority);
    }

    // ---- Weekend Top Picks (day-filtered from a wider pool; order is preserved) ----
    $weekendPool = hs_fetch_upcoming_events($conn, $cityEsc, $categoryEsc, 0, 24);
    $weekendPicks = array_values(array_filter($weekendPool, function ($e) {
        $dow = (int)$e['_display_date']->format('w'); // 0=Sun ... 6=Sat
        return in_array($dow, [5, 6, 0], true);
    }));
    $weekendPicks = array_slice($weekendPicks, 0, 4);
    $weekendHtml = '';
    foreach ($weekendPicks as $ev) {
        $isPriority = $categoryEsc && !empty($ev['is_priority']);
        $weekendHtml .= hs_render_grid_card($ev, $cityUrlBase, $isPriority);
    }

    // ---- Trending Venues ----
    [$venues, $venuePriority] = hs_fetch_trending_venues($conn, $cityEsc, $categoryEsc, 5);
    $venuesHtml = '';
    foreach ($venues as $v) {
        $isPriority = $categoryEsc && !empty($venuePriority[$v['id']]);
        $venuesHtml .= hs_render_venue_card($v, $cityUrlBase, $isPriority);
    }

    // ---- Upcoming This Week (first page — infinite scroll continues via load-more-events.php) ----
    $upcomingEvents = hs_fetch_upcoming_events($conn, $cityEsc, $categoryEsc, 0, $upcomingLimit + 1);
    $upcomingHasMore = count($upcomingEvents) > $upcomingLimit;
    $upcomingEvents = array_slice($upcomingEvents, 0, $upcomingLimit);
    $upcomingHtml = '';
    foreach ($upcomingEvents as $ev) {
        $isPriority = $categoryEsc && !empty($ev['is_priority']);
        $upcomingHtml .= hs_render_list_card($ev, $cityUrlBase, $isPriority);
    }

    // ---- Featured DJs ----
    [$djs, $artistUpcoming, $artistPriority] = hs_fetch_featured_djs($conn, $cityEsc, $categoryEsc, 6);
    $djsHtml = '';
    foreach ($djs as $d) {
        $badge = ($artistUpcoming[$d['id']] ?? 0) > 0
            ? $artistUpcoming[$d['id']] . ' show' . ($artistUpcoming[$d['id']] > 1 ? 's' : '') . ' this week'
            : (!empty($d['genre']) ? $d['genre'] : 'Featured');
        $isPriority = $categoryEsc && !empty($artistPriority[$d['id']]);
        $djsHtml .= hs_render_dj_card($d, $cityUrlBase, $badge, $isPriority);
    }

    hs_ajax_success([
        'category'   => $category,
        'headliners' => ['html' => $headlinersHtml, 'count' => count($headliners)],
        'weekend'    => ['html' => $weekendHtml, 'count' => count($weekendPicks)],
        'venues'     => ['html' => $venuesHtml, 'count' => count($venues)],
        'upcoming'   => ['html' => $upcomingHtml, 'count' => count($upcomingEvents), 'hasMore' => $upcomingHasMore],
        'djs'        => ['html' => $djsHtml, 'count' => count($djs)],
    ]);
} catch (Throwable $e) {
    hs_ajax_error($e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}