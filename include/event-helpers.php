<?php
/**
 * event-helpers.php
 * -----------------------------------------------------------------------
 * Shared rendering + query helpers for the HighSpinn mobile app view.
 * Included by both mobile-app.php (first paint) and the ajax/ endpoints
 * (infinite scroll, category reorder, search) so they never drift apart.
 *
 * CATEGORY BEHAVIOUR: a selected category no longer hides non-matching
 * events. Every "upcoming events" style query computes an is_priority
 * flag and sorts priority-first, so matching events float to the top of
 * each section while the rest stay visible underneath.
 *
 * ASSUMPTIONS (adjust if your schema differs — these are taken directly
 * from the queries already used in your existing homepage code):
 *   events(id, tittle, sub_tittle[=place.id], start, end, multiple_dates,
 *          image, url, status, banner_status)
 *   place(id, name, city, image_url, rating)
 *   category(id, cat_name, cat_slug, image)
 *   event_category(event_id, cat_id)
 *   event_tickets(event_id, ticket_cost)
 *   artist(id, name, url, image_url, genre[, city])
 *   event_artist(event_id, artist_id)
 * -----------------------------------------------------------------------
 */

if (!function_exists('hs_e')) {
    function hs_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Given an events row (with multiple_dates already selected), work out
 * the next display date >= now. Returns null if the event has fully
 * ended and has no future occurrence — callers should skip the row.
 * Mirrors the logic already used on the desktop homepage.
 */
function hs_next_display_date(array $event, DateTime $now): ?DateTime {
    $originalStart = new DateTime($event['start']);
    $endDate       = new DateTime($event['end']);
    $displayStart  = $originalStart;

    $multipleDates = [];
    if (!empty($event['multiple_dates'])) {
        $dates = array_map('trim', explode(',', $event['multiple_dates']));
        foreach ($dates as $d) {
            $ts = strtotime($d . ' ' . $originalStart->format('H:i:s'));
            if ($ts !== false && $ts >= $now->getTimestamp() && $ts <= $endDate->getTimestamp()) {
                $multipleDates[] = $ts;
            }
        }
    }

    if (!empty($multipleDates)) {
        sort($multipleDates);
        return (new DateTime())->setTimestamp($multipleDates[0]);
    }

    if ($now > $endDate) {
        return null; // fully ended, no future occurrence
    }

    return $displayStart;
}

function hs_format_price($minCost, $maxCost): array {
    // returns [displayLabel, isFree]
    if ((float)$minCost === 0.0 && (float)$maxCost === 0.0) {
        return ['Free', true];
    }
    $label = '₹' . $minCost;
    if ($minCost != $maxCost) $label .= ' - ₹' . $maxCost;
    return [$label, false];
}

/**
 * Pulls a page of upcoming events for a city. Never excludes events —
 * if $priorityCatSlugEsc is given, matching events are sorted first
 * (via an is_priority flag) but every other upcoming event is still
 * included further down the same page/pagination.
 */
function hs_fetch_upcoming_events(mysqli $conn, string $cityEsc, ?string $priorityCatSlugEsc, int $offset, int $limit): array {
    $extraSelect = '';
    $orderBy     = 'events.start ASC';
    if ($priorityCatSlugEsc) {
        $extraSelect = ", (SELECT COUNT(*) FROM event_category ecp
                            INNER JOIN category catp ON catp.id = ecp.cat_id
                            WHERE ecp.event_id = events.id AND catp.cat_slug = '{$priorityCatSlugEsc}') AS is_priority";
        $orderBy = 'is_priority DESC, events.start ASC';
    }

    $sql = "
        SELECT
            events.*,
            place.name AS placename,
            place.image_url AS placeimage,
            MIN(event_tickets.ticket_cost) AS min_cost,
            MAX(event_tickets.ticket_cost) AS max_cost,
            GROUP_CONCAT(DISTINCT category.cat_name SEPARATOR ', ') AS category_name
            {$extraSelect}
        FROM events
        INNER JOIN place ON events.sub_tittle = place.id
        LEFT JOIN event_tickets ON event_tickets.event_id = events.id
        LEFT JOIN event_category ON event_category.event_id = events.id
        LEFT JOIN category ON category.id = event_category.cat_id
        WHERE events.status = 1
        AND LOWER(place.city) = LOWER('{$cityEsc}')
        GROUP BY events.id
        ORDER BY {$orderBy}
        LIMIT " . (int)$offset . ", " . (int)$limit;

    $res = $conn->query($sql);
    $now = new DateTime();
    $out = [];

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $displayDate = hs_next_display_date($row, $now);
            if ($displayDate === null) continue; // event has fully ended, skip
            $row['_display_date'] = $displayDate;
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Headliners (banner_status = 1). Same priority-first reordering as
 * hs_fetch_upcoming_events — nothing is excluded by category.
 */
function hs_fetch_headliners(mysqli $conn, string $cityEsc, ?string $priorityCatSlugEsc, int $limit): array {
    $extraSelect = '';
    $orderBy     = 'events.start ASC';
    if ($priorityCatSlugEsc) {
        $extraSelect = ", (SELECT COUNT(*) FROM event_category ecp
                            INNER JOIN category catp ON catp.id = ecp.cat_id
                            WHERE ecp.event_id = events.id AND catp.cat_slug = '{$priorityCatSlugEsc}') AS is_priority";
        $orderBy = 'is_priority DESC, events.start ASC';
    }

    $sql = "
        SELECT events.*, place.name AS placename,
               GROUP_CONCAT(DISTINCT category.cat_name SEPARATOR ', ') AS category_name
               {$extraSelect}
        FROM events
        LEFT JOIN place ON events.sub_tittle = place.id
        LEFT JOIN event_category ON event_category.event_id = events.id
        LEFT JOIN category ON category.id = event_category.cat_id
        WHERE events.banner_status = 1
        AND events.status = 1
        AND LOWER(place.city) = LOWER('{$cityEsc}')
        GROUP BY events.id
        ORDER BY {$orderBy}
        LIMIT " . (int)$limit;

    $res = $conn->query($sql);
    $now = new DateTime();
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $d = hs_next_display_date($row, $now);
            if ($d === null) continue;
            $row['_display_date'] = $d;
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Trending venues. Never excludes a venue — pulls a slightly wider pool
 * ranked by total upcoming events, then re-sorts so venues with an
 * upcoming event in the selected category come first (ties broken by
 * total upcoming events, same as the unfiltered ranking).
 *
 * Returns [venues[], venuePriorityCountsByVenueId].
 */
function hs_fetch_trending_venues(mysqli $conn, string $cityEsc, ?string $priorityCatSlugEsc, int $limit): array {
    $venueIds = [];
    $vIdRes = $conn->query("SELECT id FROM place WHERE LOWER(city) = LOWER('{$cityEsc}')");
    if ($vIdRes) { while ($r = $vIdRes->fetch_assoc()) { $venueIds[] = (int)$r['id']; } }
    if (empty($venueIds)) return [[], []];
    $idList = implode(',', $venueIds);

    $catJoin        = '';
    $prioritySelect = '';
    if ($priorityCatSlugEsc) {
        $catJoin        = "LEFT JOIN event_category ecp ON ecp.event_id = events.id
                           LEFT JOIN category catp ON catp.id = ecp.cat_id AND catp.cat_slug = '{$priorityCatSlugEsc}'";
        $prioritySelect = ", MAX(CASE WHEN catp.id IS NOT NULL THEN 1 ELSE 0 END) AS matches_priority";
    }

    $sql = "
        SELECT events.id, events.sub_tittle AS venue_id, events.start, events.multiple_dates, events.end
               {$prioritySelect}
        FROM events
        {$catJoin}
        WHERE events.status = 1 AND events.sub_tittle IN ($idList)
        GROUP BY events.id
    ";
    $veRes = $conn->query($sql);
    $now = new DateTime();
    $venueUpcoming = [];
    $venuePriority = [];
    if ($veRes) {
        while ($e = $veRes->fetch_assoc()) {
            $d = hs_next_display_date($e, $now);
            if ($d === null) continue;
            $venueUpcoming[$e['venue_id']] = ($venueUpcoming[$e['venue_id']] ?? 0) + 1;
            if ($priorityCatSlugEsc && !empty($e['matches_priority'])) {
                $venuePriority[$e['venue_id']] = ($venuePriority[$e['venue_id']] ?? 0) + 1;
            }
        }
    }

    arsort($venueUpcoming);
    // Pool a bit wider than $limit so re-sorting has room to surface
    // priority venues that weren't already in the top slots.
    $poolSize = max($limit, 12);
    $topVenueIds = array_slice(array_keys($venueUpcoming), 0, $poolSize);
    if (empty($topVenueIds)) return [[], []];

    $topIdList = implode(',', array_map('intval', $topVenueIds));
    $venues = [];
    $vRes = $conn->query("SELECT * FROM place WHERE id IN ($topIdList)");
    if ($vRes) { while ($r = $vRes->fetch_assoc()) { $venues[] = $r; } }

    usort($venues, function ($a, $b) use ($venueUpcoming, $venuePriority) {
        $aP = $venuePriority[$a['id']] ?? 0;
        $bP = $venuePriority[$b['id']] ?? 0;
        if ($aP !== $bP) return $bP <=> $aP;
        return ($venueUpcoming[$b['id']] ?? 0) <=> ($venueUpcoming[$a['id']] ?? 0);
    });

    return [array_slice($venues, 0, $limit), $venuePriority];
}

/**
 * Featured DJs. Same "reorder, never exclude" behaviour as venues.
 * Returns [artists[], totalUpcomingCountsByArtistId, priorityCountsByArtistId].
 */
function hs_fetch_featured_djs(mysqli $conn, string $cityEsc, ?string $priorityCatSlugEsc, int $limit): array {
    $catJoin        = '';
    $prioritySelect = '';
    if ($priorityCatSlugEsc) {
        $catJoin        = "LEFT JOIN event_category ecp ON ecp.event_id = events.id
                           LEFT JOIN category catp ON catp.id = ecp.cat_id AND catp.cat_slug = '{$priorityCatSlugEsc}'";
        $prioritySelect = ", MAX(CASE WHEN catp.id IS NOT NULL THEN 1 ELSE 0 END) AS matches_priority";
    }

    $sql = "
        SELECT events.id, event_artist.artist_id, events.start, events.multiple_dates, events.end
               {$prioritySelect}
        FROM events
        INNER JOIN event_artist ON events.id = event_artist.event_id
        INNER JOIN place ON events.sub_tittle = place.id
        {$catJoin}
        WHERE events.status = 1
        AND LOWER(place.city) = LOWER('{$cityEsc}')
        GROUP BY events.id, event_artist.artist_id
    ";
    $aeRes = $conn->query($sql);
    $now = new DateTime();
    $artistUpcoming = [];
    $artistPriority = [];
    if ($aeRes) {
        while ($e = $aeRes->fetch_assoc()) {
            $d = hs_next_display_date($e, $now);
            if ($d === null) continue;
            $artistUpcoming[$e['artist_id']] = ($artistUpcoming[$e['artist_id']] ?? 0) + 1;
            if ($priorityCatSlugEsc && !empty($e['matches_priority'])) {
                $artistPriority[$e['artist_id']] = ($artistPriority[$e['artist_id']] ?? 0) + 1;
            }
        }
    }

    arsort($artistUpcoming);
    $poolSize = max($limit, 14);
    $topArtistIds = array_slice(array_keys($artistUpcoming), 0, $poolSize);

    $djs = [];
    if (!empty($topArtistIds)) {
        $idList = implode(',', array_map('intval', $topArtistIds));
        $arRes = $conn->query("SELECT * FROM artist WHERE id IN ($idList)");
        if ($arRes) { while ($r = $arRes->fetch_assoc()) { $djs[] = $r; } }
        usort($djs, function ($a, $b) use ($artistUpcoming, $artistPriority) {
            $aP = $artistPriority[$a['id']] ?? 0;
            $bP = $artistPriority[$b['id']] ?? 0;
            if ($aP !== $bP) return $bP <=> $aP;
            return ($artistUpcoming[$b['id']] ?? 0) <=> ($artistUpcoming[$a['id']] ?? 0);
        });
    }

    return [array_slice($djs, 0, $limit), $artistUpcoming, $artistPriority];
}

/** Renders one .list-card (used in "Upcoming This Week" + infinite scroll) */
function hs_render_list_card(array $event, string $cityUrlBase, bool $isPriority = false): string {
    [$priceLabel, $isFree] = hs_format_price($event['min_cost'] ?? 0, $event['max_cost'] ?? 0);
    $badge   = trim(explode(',', $event['category_name'] ?? '')[0] ?? 'Event');
    $dateStr = $event['_display_date']->format('D, d M · g:i A');
    $img     = hs_e($event['image']);
    $url     = rtrim($cityUrlBase, '/') . '/events1/' . hs_e($event['url']);
    $title   = hs_e($event['tittle']);
    $venue   = hs_e($event['placename']);

    $priceClass = $isFree ? 'price-tag price-free' : 'price-tag';
    $cardClass  = 'list-card' . ($isPriority ? ' card-priority' : '');

    return <<<HTML
    <a class="{$cardClass}" href="{$url}">
      <div class="list-thumb" style="background-image:url('{$img}')"></div>
      <div class="list-body">
        <div class="list-top">
          <span class="badge-sm">{$badge}</span>
          <span class="{$priceClass}">{$priceLabel}</span>
        </div>
        <div class="list-title">{$title}</div>
        <div class="list-meta">{$dateStr} · {$venue}</div>
      </div>
    </a>
HTML;
}

/** Renders one large headliner card (horizontal-scroll "Tonight's Headliners") */
function hs_render_headliner_card(array $event, string $cityUrlBase, bool $isPriority = false): string {
    $badge   = trim(explode(',', $event['category_name'] ?? '')[0] ?? 'Event');
    $dateStr = $event['_display_date']->format('D, d M · g:i A');
    $img     = hs_e($event['image']);
    $url     = rtrim($cityUrlBase, '/') . '/events1/' . hs_e($event['url']);
    $title   = hs_e($event['tittle']);
    $venue   = hs_e($event['placename']);
    $cardClass = 'headliner-card' . ($isPriority ? ' card-priority' : '');

    return <<<HTML
    <a class="{$cardClass}" style="background-image:url('{$img}')" href="{$url}">
      <span class="badge">{$badge}</span>
      <div>
        <div class="h-date">{$dateStr}</div>
        <div class="h-title">{$title}</div>
        <div class="h-venue">{$venue}</div>
      </div>
    </a>
HTML;
}

/** Renders one grid card (2-col "Weekend Top Picks") */
function hs_render_grid_card(array $event, string $cityUrlBase, bool $isPriority = false): string {
    $badge = trim(explode(',', $event['category_name'] ?? '')[0] ?? 'Event');
    $img   = hs_e($event['image']);
    $url   = rtrim($cityUrlBase, '/') . '/events1/' . hs_e($event['url']);
    $title = hs_e($event['tittle']);
    $day   = $event['_display_date']->format('D, d M');
    $cardClass = 'grid-card' . ($isPriority ? ' card-priority' : '');

    return <<<HTML
    <a class="{$cardClass}" href="{$url}">
      <div class="grid-media" style="background-image:url('{$img}')"><span>{$badge}</span></div>
      <div class="grid-body">
        <div class="grid-title">{$title}</div>
        <div class="grid-meta">{$day}</div>
      </div>
    </a>
HTML;
}

/** Renders one venue card (horizontal-scroll "Trending Venues") */
function hs_render_venue_card(array $venue, string $cityUrlBase, bool $isPriority = false): string {
    $img    = hs_e($venue['image_url']);
    $name   = hs_e($venue['name']);
    $rating = hs_e($venue['rating'] ?: '4.8');
    $url    = rtrim($cityUrlBase, '/') . '/venues1/' . hs_e($venue['url']);
    $cardClass = 'venue-card' . ($isPriority ? ' card-priority' : '');

    return <<<HTML
    <a class="{$cardClass}" href="{$url}">
      <div class="venue-media" style="background-image:url('{$img}')"></div>
      <div class="venue-body">
        <div class="venue-name">{$name}</div>
        <div class="venue-rating">★ {$rating}</div>
      </div>
    </a>
HTML;
}

/** Renders one DJ card (horizontal-scroll "Featured DJs") */
function hs_render_dj_card(array $artist, string $cityUrlBase, string $badge, bool $isPriority = false): string {
    $img  = hs_e($artist['image_url']);
    $name = hs_e($artist['name']);
    $url  = rtrim($cityUrlBase, '/') . '/artists1/' . hs_e($artist['url']);
    $cardClass = 'dj-card' . ($isPriority ? ' card-priority' : '');

    return <<<HTML
    <a class="{$cardClass}" href="{$url}">
      <div class="dj-photo" style="background-image:url('{$img}')"></div>
      <div class="dj-name">{$name}</div>
      <div class="dj-venue">{$badge}</div>
    </a>
HTML;
}

/**
 * Maps a category name to a badge background (CSS value), so "Techno
 * Night" / "Ladies Night" / "Bolly Tech Night" etc. each get a distinct,
 * consistent pill color across every page that shows event cards.
 */
function hs_category_badge_bg(string $catName): string {
    $c = strtolower($catName);
    if (strpos($c, 'techno') !== false)      return '#17c3d9';
    if (strpos($c, 'ladies') !== false)      return 'linear-gradient(100deg,#ff2f87,#a83ffb)';
    if (strpos($c, 'bolly') !== false)       return 'linear-gradient(100deg,#7b3ff2,#3ddbe0)';
    if (strpos($c, 'commercial') !== false)  return 'linear-gradient(100deg,#ff2f87,#ff7a45)';
    return 'var(--pink)';
}

/**
 * Resolves a city slug (e.g. "bangalore") to its display name, the same
 * table city.php already reads on every page load. Shared by every AJAX
 * endpoint that receives a city slug from the client instead of having
 * $currentCityEsc already in scope. Adjust the table/column names here
 * if your city.php uses different ones.
 */
function hs_resolve_city_by_slug(mysqli $conn, string $slug): ?string {
    $slugEsc = $conn->real_escape_string($slug);
    $res = $conn->query("SELECT name FROM tbl_cities WHERE slug = '{$slugEsc}' LIMIT 1");
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc()['name'];
    }
    return null;
}

/**
 * Search matching upcoming events in a city by title or venue name.
 * Same shape/date-filtering as hs_fetch_upcoming_events so the results
 * can be rendered with hs_render_list_card() directly.
 */
function hs_search_events(mysqli $conn, string $cityEsc, string $qEsc, int $limit = 6): array {
    $sql = "
        SELECT
            events.*,
            place.name AS placename,
            MIN(event_tickets.ticket_cost) AS min_cost,
            MAX(event_tickets.ticket_cost) AS max_cost,
            GROUP_CONCAT(DISTINCT category.cat_name SEPARATOR ', ') AS category_name
        FROM events
        INNER JOIN place ON events.sub_tittle = place.id
        LEFT JOIN event_tickets ON event_tickets.event_id = events.id
        LEFT JOIN event_category ON event_category.event_id = events.id
        LEFT JOIN category ON category.id = event_category.cat_id
        WHERE events.status = 1
        AND LOWER(place.city) = LOWER('{$cityEsc}')
        AND (events.tittle LIKE '%{$qEsc}%' OR place.name LIKE '%{$qEsc}%')
        GROUP BY events.id
        ORDER BY events.start ASC
        LIMIT 30
    ";
    $res = $conn->query($sql);
    $now = new DateTime();
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $d = hs_next_display_date($row, $now);
            if ($d === null) continue;
            $row['_display_date'] = $d;
            $out[] = $row;
            if (count($out) >= $limit) break;
        }
    }
    return $out;
}

/** Search artists by name (site-wide, not city-scoped — artists tour). */
function hs_search_artists(mysqli $conn, string $qEsc, int $limit = 6): array {
    $sql = "SELECT id, name, url, image_url, genre FROM artist
            WHERE name LIKE '%{$qEsc}%' LIMIT " . (int)$limit;
    $res = $conn->query($sql);
    $out = [];
    if ($res) { while ($r = $res->fetch_assoc()) { $out[] = $r; } }
    return $out;
}

/** Search venues by name within the current city. */
function hs_search_venues(mysqli $conn, string $cityEsc, string $qEsc, int $limit = 6): array {
    $sql = "SELECT * FROM place
            WHERE LOWER(city) = LOWER('{$cityEsc}') AND name LIKE '%{$qEsc}%'
            LIMIT " . (int)$limit;
    $res = $conn->query($sql);
    $out = [];
    if ($res) { while ($r = $res->fetch_assoc()) { $out[] = $r; } }
    return $out;
}

/** Renders one artist row for the in-page search results panel. */
function hs_render_artist_row(array $artist, string $cityUrlBase): string {
    $img   = hs_e($artist['image_url']);
    $name  = hs_e($artist['name']);
    $genre = hs_e($artist['genre'] ?: 'Artist');
    $url   = rtrim($cityUrlBase, '/') . '/artists1/' . hs_e($artist['url']);

    return <<<HTML
    <a class="list-card" href="{$url}">
      <div class="list-thumb" style="background-image:url('{$img}');border-radius:50%;"></div>
      <div class="list-body">
        <div class="list-title">{$name}</div>
        <div class="list-meta">{$genre}</div>
      </div>
    </a>
HTML;
}

/** Renders one venue row for the in-page search results panel. */
function hs_render_venue_row(array $venue, string $cityUrlBase): string {
    $img    = hs_e($venue['image_url']);
    $name   = hs_e($venue['name']);
    $city   = hs_e($venue['city'] ?? '');
    $rating = !empty($venue['rating']) ? ('★ ' . hs_e($venue['rating']) . ' · ') : '';
    $url    = rtrim($cityUrlBase, '/') . '/venues1/' . hs_e($venue['url']);

    return <<<HTML
    <a class="list-card" href="{$url}">
      <div class="list-thumb" style="background-image:url('{$img}')"></div>
      <div class="list-body">
        <div class="list-title">{$name}</div>
        <div class="list-meta">{$rating}{$city}</div>
      </div>
    </a>
HTML;
}

/**
 * Renders one "stacked" event card: image on top with a category pill
 * (top-left) and a price/FREE pill (top-right), then date + title + venue
 * beneath the image. Used on the mobile venue and artist detail pages
 * for "Upcoming events / shows".
 */
function hs_render_stacked_event_card(array $opts): string {
    $img      = hs_e($opts['image']);
    $url      = hs_e($opts['url']);
    $title    = hs_e($opts['title']);
    $venue    = hs_e($opts['venue']);
    $dateStr  = hs_e($opts['date_label']);
    $badge    = hs_e($opts['badge']);
    $badgeBg  = $opts['badge_bg'] ?? 'var(--pink)';
    $price    = hs_e($opts['price_label']);
    $isFree   = !empty($opts['is_free']);
    $priceBg  = $isFree ? 'var(--yellow)' : 'rgba(10,10,13,.7)';
    $priceFg  = $isFree ? '#241a00' : 'var(--yellow)';

    return <<<HTML
    <a class="app-ev-card" href="{$url}">
      <div class="app-ev-media" style="background-image:url('{$img}')">
        <span class="app-ev-badge" style="background:{$badgeBg};">{$badge}</span>
        <span class="app-ev-price" style="background:{$priceBg};color:{$priceFg};">{$price}</span>
      </div>
      <div class="app-ev-body">
        <div class="app-ev-date"><i class="fa-regular fa-calendar"></i> {$dateStr}</div>
        <div class="app-ev-title">{$title}</div>
        <div class="app-ev-venue"><i class="fa-solid fa-location-dot"></i> {$venue}</div>
      </div>
    </a>
HTML;
}