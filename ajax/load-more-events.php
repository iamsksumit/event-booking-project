<?php
/**
 * ajax/load-more-events.php
 * -----------------------------------------------------------------------
 * Called by mobile-app.php's infinite scroll (and by tapping a filter
 * chip, which resets offset=0 and re-queries with a category).
 *
 * GET/POST params:
 *   city     (required)  city slug, e.g. "bangalore"
 *   category (optional)  category slug to filter by, "" or omitted = All
 *   offset   (optional)  default 0
 *   limit    (optional)  default 6, capped at 20 per request
 *
 * Response: JSON { html: "<a class=list-card>...</a>...", hasMore: bool }
 *
 * NOTE: include/ajax-bootstrap.php goes FIRST — see that file's docblock
 * for why. It guarantees this always returns valid JSON, with a `debug`
 * field containing the real error message if something breaks.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/../include/ajax-bootstrap.php';

try {
    require_once __DIR__ . '/../include/config.php';   // provides $conn (mysqli)
    require_once __DIR__ . '/../include/event-helpers.php';

    if (!isset($conn) || !($conn instanceof mysqli)) {
        hs_ajax_error("config.php did not provide a mysqli \$conn — check its variable name.");
    }

    $citySlug = isset($_REQUEST['city']) ? trim($_REQUEST['city']) : '';
    $category = isset($_REQUEST['category']) ? trim($_REQUEST['category']) : '';
    $offset   = isset($_REQUEST['offset']) ? max(0, (int)$_REQUEST['offset']) : 0;
    $limit    = isset($_REQUEST['limit']) ? (int)$_REQUEST['limit'] : 6;
    $limit    = max(1, min($limit, 20));

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

    // Fetch one extra row beyond $limit purely to know whether more data exists,
    // without a second COUNT(*) query.
    $events = hs_fetch_upcoming_events($conn, $cityEsc, $categoryEsc, $offset, $limit + 1);

    $hasMore = count($events) > $limit;
    if ($hasMore) {
        $events = array_slice($events, 0, $limit);
    }

    $cityUrlBase = '/' . $citySlugEsc . '/';

    $html = '';
    foreach ($events as $event) {
        $html .= hs_render_list_card($event, $cityUrlBase);
    }

    hs_ajax_success([
        'html'    => $html,
        'hasMore' => $hasMore,
        'count'   => count($events),
    ]);

} catch (Throwable $e) {
    hs_ajax_error($e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}