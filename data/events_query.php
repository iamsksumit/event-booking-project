<?php
/**
 * data/events_query.php
 *
 * Shared query + render logic for the events grid. Used by:
 *   - fetch_events.php   (AJAX calls for filtering/paging)
 *   - events.php         (server-rendered first page, so bots/crawlers see
 *                          real event markup even without JS executing)
 *
 * Keeping this in one place means the AJAX result and the initial
 * server-rendered result can never drift apart.
 *
 * SCHEMA ASSUMPTIONS (adjust if your tables differ):
 *   events(id, tittle, start, end, image, url, sub_tittle -> place.id, status)
 *   place(id, name, city)                   -- place.city stores the city NAME
 *   category(id, cat_name, cat_slug)
 *   event_category(event_id, cat_id)        -- junction table
 *   event_tickets(event_id, ticket_cost)
 *
 * @param mysqli $conn
 * @param array  $filters {
 *   @var string city        city slug used to build each card's link, e.g.
 *                           'bangalore' -> /bangalore/events/{slug}/
 *                           (matches the city-scoped routes in .htaccess).
 *                           Falls back to 'bangalore' if omitted, so any
 *                           existing caller that doesn't pass this yet
 *                           doesn't break - it just gets Bangalore links.
 *   @var string cityName    the resolved city NAME (e.g. "Bangalore"),
 *                           used to actually filter which events are
 *                           returned, matched against place.city the same
 *                           way header.php already does for suggestCats.
 *                           Empty string = no city filtering.
 *   @var array  categories  category slugs (OR'd together)
 *   @var array  venues      venue IDs (OR'd together)
 *   @var string search
 *   @var string entry       'free' | 'paid' | ''
 *   @var int    maxPrice    0 = no cap
 *   @var string dateBucket  'all'|'today'|'tomorrow'|'week'|'weekend'|'pick'
 *   @var string pickedDate  'YYYY-MM-DD', only used when dateBucket === 'pick'
 *   @var string sort        'start_asc'|'start_desc'|'price_asc'|'price_desc'|'new'
 *   @var int    page        1-based
 *   @var int    pageSize
 * }
 * @return array { html, total, page, totalPages, empty }
 */
function hs_query_events(mysqli $conn, array $filters): array
{
    $citySlug   = trim($filters['city'] ?? '') ?: 'bangalore';
    $cityName   = trim($filters['cityName'] ?? '');
    $categories = array_filter($filters['categories'] ?? []);
    $venues     = array_filter($filters['venues'] ?? []);
    $search     = trim($filters['search'] ?? '');
    $entryType  = $filters['entry'] ?? '';
    $maxPrice   = (int)($filters['maxPrice'] ?? 0);
    $dateBucket = $filters['dateBucket'] ?? 'all';
    $pickedDate = $filters['pickedDate'] ?? '';
    $sort       = $filters['sort'] ?? 'start_asc';
    $page       = max(1, (int)($filters['page'] ?? 1));
    $pageSize   = (int)($filters['pageSize'] ?? 6);

    // Every card link below is built as /{city}/events/{event-slug}/ to
    // match the city-scoped route in .htaccess:
    //   RewriteRule ^([a-z0-9-]+)/events/([^/]+)/?$ event.php?url=$2&city=$1
    $cityUrlSegment = rawurlencode($citySlug);

    $today = date('Y-m-d');

    if ($pickedDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pickedDate)) {
        $pickedDate = '';
    }

    // ---------------------------------------------------------------
    // Build WHERE clause
    // ---------------------------------------------------------------
    $where = ["e.status = 1"];

    // Only show events whose venue is in the currently selected city.
    // Matches the same pattern header.php already uses for suggestCats:
    // LOWER(p.city) = LOWER(<resolved city name>).
    if ($cityName !== '') {
        $cityNameEsc = $conn->real_escape_string($cityName);
        $where[] = "LOWER(p.city) = LOWER('$cityNameEsc')";
    }

    if (!empty($categories)) {
        $catSlugs = array_map(function ($s) use ($conn) {
            return "'" . $conn->real_escape_string($s) . "'";
        }, $categories);
        $where[] = "e.id IN (
            SELECT ec.event_id FROM event_category ec
            INNER JOIN category c ON ec.cat_id = c.id
            WHERE c.cat_slug IN (" . implode(',', $catSlugs) . ")
        )";
    }

    if (!empty($venues)) {
        $venueIds = array_map('intval', $venues);
        $where[] = "e.sub_tittle IN (" . implode(',', $venueIds) . ")";
    }

    if ($search !== '') {
        $s = $conn->real_escape_string($search);
        $where[] = "(e.tittle LIKE '%$s%' OR p.name LIKE '%$s%')";
    }

    $todayDt = new DateTime($today);
    switch ($dateBucket) {
        case 'today':
            $where[] = "DATE(e.start) = '" . $todayDt->format('Y-m-d') . "'";
            break;
        case 'tomorrow':
            $t = (clone $todayDt)->modify('+1 day');
            $where[] = "DATE(e.start) = '" . $t->format('Y-m-d') . "'";
            break;
        case 'week':
            $t = (clone $todayDt)->modify('+7 day');
            $where[] = "DATE(e.start) BETWEEN '" . $todayDt->format('Y-m-d') . "' AND '" . $t->format('Y-m-d') . "'";
            break;
        case 'weekend':
            $dow = (int)$todayDt->format('w'); // 0 Sun .. 6 Sat
            $daysToSat = (6 - $dow + 7) % 7;
            $sat = (clone $todayDt)->modify("+{$daysToSat} day");
            $sun = (clone $sat)->modify('+1 day');
            $where[] = "DATE(e.start) IN ('" . $sat->format('Y-m-d') . "','" . $sun->format('Y-m-d') . "')";
            break;
        case 'pick':
            if ($pickedDate) {
                $where[] = "DATE(e.start) = '" . $conn->real_escape_string($pickedDate) . "'";
            }
            break;
        // 'all' -> no date restriction
    }

    $whereSql = implode(' AND ', $where);

    $having = [];
    if ($entryType === 'free') $having[] = "min_cost = 0";
    if ($entryType === 'paid') $having[] = "min_cost > 0";
    if ($maxPrice > 0)         $having[] = "min_cost <= $maxPrice";
    $havingSql = $having ? ('HAVING ' . implode(' AND ', $having)) : '';

    switch ($sort) {
        case 'start_desc': $orderSql = 'ORDER BY e.start DESC'; break;
        case 'price_asc':  $orderSql = 'ORDER BY min_cost ASC';  break;
        case 'price_desc': $orderSql = 'ORDER BY max_cost DESC'; break;
        case 'new':         $orderSql = 'ORDER BY e.id DESC';     break;
        default:             $orderSql = 'ORDER BY e.start ASC';   break; // start_asc / trending
    }

    // ---------------------------------------------------------------
    // Count total matches (for pagination)
    // ---------------------------------------------------------------
    $countSql = "SELECT COUNT(*) AS total FROM (
        SELECT e.id,
            (SELECT MIN(ticket_cost) FROM event_tickets WHERE event_id = e.id) AS min_cost,
            (SELECT MAX(ticket_cost) FROM event_tickets WHERE event_id = e.id) AS max_cost
        FROM events e
        LEFT JOIN place p ON e.sub_tittle = p.id
        WHERE $whereSql
        $havingSql
    ) t";
    $countRes = $conn->query($countSql);
    $total = $countRes ? (int)$countRes->fetch_assoc()['total'] : 0;
    $totalPages = max(1, (int)ceil($total / $pageSize));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $pageSize;

    // ---------------------------------------------------------------
    // Fetch the actual page of events
    // ---------------------------------------------------------------
    $sql = "SELECT e.*, p.name AS placename,
            (SELECT MIN(ticket_cost) FROM event_tickets WHERE event_id = e.id) AS min_cost,
            (SELECT MAX(ticket_cost) FROM event_tickets WHERE event_id = e.id) AS max_cost,
            (SELECT c.cat_name FROM event_category ec
                INNER JOIN category c ON ec.cat_id = c.id
                WHERE ec.event_id = e.id LIMIT 1) AS cat_name
            FROM events e
            LEFT JOIN place p ON e.sub_tittle = p.id
            WHERE $whereSql
            $havingSql
            $orderSql
            LIMIT $pageSize OFFSET $offset";
    $result = $conn->query($sql);

    // ---------------------------------------------------------------
    // Render the grid markup
    // ---------------------------------------------------------------
    ob_start();
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $startDate   = new DateTime($row['start']);
            $displayDate = $startDate->format('D, d M') . ' &middot; ' . $startDate->format('g:i A');
            $isFree      = ((float)$row['min_cost'] === 0.0 && (float)$row['max_cost'] === 0.0);
            $priceText   = $isFree
                ? 'FREE'
                : ('₹' . number_format($row['min_cost']) . ($row['min_cost'] != $row['max_cost'] ? ' - ₹' . number_format($row['max_cost']) : ''));
            $slug = rawurlencode($row['url']);
            // City-scoped event URL, e.g. /bangalore/events/some-event-slug/
            $eventHref = '/' . $cityUrlSegment . '/events/' . $slug . '/';
            $img  = htmlspecialchars($row['image']);
            $name = htmlspecialchars($row['tittle']);
            $venueName = htmlspecialchars($row['placename'] ?? '');
            $catName = htmlspecialchars($row['cat_name'] ?? '');
            ?>
            <div class="col-sm-6 col-xl-4 evt-item">
                <div class="evt-card">
                    <a href="<?= $eventHref ?>">
                        <div class="evt-media" style="background-image:url('<?= $img ?>');background-size:cover;background-position:center;">
                            <div class="streaks"></div>
                            <span class="price-tag"><?= $priceText ?></span>
                            <span class="heart-btn"><i class="fa-regular fa-heart"></i></span>
                            <?php if ($catName): ?><span class="cat-tag"><?= $catName ?></span><?php endif; ?>
                        </div>
                    </a>
                    <div class="evt-body">
                        <div class="evt-meta"><i class="fa-regular fa-clock"></i><?= $displayDate ?></div>
                        <h5><a href="<?= $eventHref ?>"><?= $name ?></a></h5>
                        <div class="evt-venue"><i class="fa-solid fa-location-dot"></i><?= $venueName ?></div>
                        <a href="<?= $eventHref ?>" class="btn-pass">Get pass <i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
            <?php
        }
    }
    $gridHtml = ob_get_clean();

    return [
        'html'       => $gridHtml,
        'total'      => $total,
        'page'       => $page,
        'totalPages' => $totalPages,
        'empty'      => $total === 0,
    ];
}