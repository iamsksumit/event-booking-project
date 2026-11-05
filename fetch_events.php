<?php
/**
 * fetch_events.php
 * AJAX endpoint for the events grid. Called from events.php whenever the
 * user changes a filter, the sort order, or the page.
 *
 * All actual query/render logic lives in data/events_query.php
 * (hs_query_events) so this endpoint and the server-rendered first page in
 * events.php are guaranteed to produce identical markup for identical
 * filters.
 *
 * Returns JSON: { html, total, page, totalPages, empty }
 */
include('include/config.php');

// Resolve the current city the same way header.php does (session/URL via
// city.php), so the AJAX grid always matches whatever city the page is
// currently showing — the client never needs to pass city explicitly.
// Exposes $currentCityId, $currentCityName, $currentCitySlug, $allCities.
include('include/city.php');

include('data/events_query.php');

date_default_timezone_set("Asia/Kolkata");
$today = date('Y-m-d');
$conn->query("UPDATE events SET status='0' WHERE end<'$today'");

// ---------------------------------------------------------------
// Read + normalise filters
// ---------------------------------------------------------------
$categories = !empty($_GET['categories']) ? explode(',', $_GET['categories']) : [];
$eventCat   = trim($_GET['event_category'] ?? '');
if ($eventCat !== '') $categories[] = $eventCat;

$filters = [
    'city'       => $currentCitySlug,
    'cityName'   => $currentCityName,
    'categories' => $categories,
    'venues'     => !empty($_GET['venues']) ? explode(',', $_GET['venues']) : [],
    'search'     => trim($_GET['q'] ?? ''),
    'entry'      => $_GET['entry'] ?? '',
    'maxPrice'   => isset($_GET['max_price']) ? (int)$_GET['max_price'] : 0,
    'dateBucket' => $_GET['date_bucket'] ?? 'all',
    'pickedDate' => $_GET['date'] ?? '',
    'sort'       => $_GET['sort'] ?? 'start_asc',
    'page'       => max(1, (int)($_GET['page'] ?? 1)),
    'pageSize'   => 6,
];

$data = hs_query_events($conn, $filters);
header('Content-Type: application/json');
echo json_encode($data);