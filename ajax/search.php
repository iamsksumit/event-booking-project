<?php
/**
 * ajax/search.php
 * -----------------------------------------------------------------------
 * Powers the in-page (no redirect) search box on mobile-app.php. Called
 * on every debounced keystroke.
 *
 * GET/POST params:
 *   city  (required)  city slug, e.g. "bangalore"
 *   q     (required)  search text, min 2 chars
 *
 * Response: JSON {
 *   query, eventCount, artistCount, venueCount,
 *   eventsHtml, artistsHtml, venuesHtml
 * }
 * Order is deliberate: events (this city) first, then artists, then
 * venues — matches how people actually think of a HighSpinn search.
 *
 * NOTE: include/ajax-bootstrap.php goes FIRST, before config.php. It
 * guarantees this endpoint always returns valid JSON — even if config.php
 * fatals or prints something unexpected — with the real error message
 * inside a `debug` field, instead of a broken response the front-end
 * can't parse.
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
    $q        = isset($_REQUEST['q']) ? trim($_REQUEST['q']) : '';

    if ($citySlug === '' || mb_strlen($q) < 2) {
        hs_ajax_success([
            'query' => $q, 'eventCount' => 0, 'artistCount' => 0, 'venueCount' => 0,
            'eventsHtml' => '', 'artistsHtml' => '', 'venuesHtml' => '',
        ]);
    }

    $cityName = hs_resolve_city_by_slug($conn, $citySlug);
    if ($cityName === null) {
        hs_ajax_error("Unknown city slug '{$citySlug}' — check the tbl_cities table/slug column names in hs_resolve_city_by_slug().", 404);
    }

    $cityEsc     = $conn->real_escape_string($cityName);
    $qEsc        = $conn->real_escape_string($q);
    $cityUrlBase = '/' . $conn->real_escape_string($citySlug) . '/';

    $events  = hs_search_events($conn, $cityEsc, $qEsc, 6);
    $artists = hs_search_artists($conn, $qEsc, 6);
    $venues  = hs_search_venues($conn, $cityEsc, $qEsc, 6);

    $eventsHtml = '';
    foreach ($events as $e) { $eventsHtml .= hs_render_list_card($e, $cityUrlBase); }

    $artistsHtml = '';
    foreach ($artists as $a) { $artistsHtml .= hs_render_artist_row($a, $cityUrlBase); }

    $venuesHtml = '';
    foreach ($venues as $v) { $venuesHtml .= hs_render_venue_row($v, $cityUrlBase); }

    hs_ajax_success([
        'query'       => $q,
        'eventCount'  => count($events),
        'artistCount' => count($artists),
        'venueCount'  => count($venues),
        'eventsHtml'  => $eventsHtml,
        'artistsHtml' => $artistsHtml,
        'venuesHtml'  => $venuesHtml,
    ]);

} catch (Throwable $e) {
    hs_ajax_error($e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}