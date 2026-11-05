<?php
/**
 * Analytics.php ("Analytics")
 * Admin + partner analytics dashboard. Two selector modes:
 *   - ADMIN, "All Partners" (default): platform-wide totals + top events /
 *     top partners leaderboards + a views trend across every event.
 *   - ADMIN, a specific partner selected -- OR a partner viewing their own
 *     data: that partner's own totals, their event list with per-event
 *     views/bookings/revenue/ticketing type, and their own views trend.
 *
 * A partner-role user is always pinned to their own data: the partner
 * selector and the two platform-wide leaderboards are hidden, and any
 * ?partner_id= override in the URL is ignored for that role.
 *
 * A date-range filter (Today / 7 Days / This Month / 90 Days / This Year /
 * All Time) scopes bookings, revenue, event counts and the views trend.
 * The trend chart buckets differently depending on range, so it never
 * tries to draw an unreadable number of bars:
 *   - Today / 7 Days / This Month  -> daily bars
 *   - 90 Days                      -> weekly bars (13 weeks)
 *   - This Year / All Time         -> monthly bars (last 12 months)
 * "All Time" KPIs otherwise keep the original all-time behaviour
 * (views_count column, unfiltered counts); other ranges re-derive views
 * from the `views` log and bookings/revenue from payments.created_at.
 *
 * Matches the shared dark dashboard theme and uses include/header.php +
 * include/footer.php like every other admin screen (see partner/index.php,
 * place/index.php). Lives at analytics/index.php -- same one-level-deep
 * folder pattern as partner/index.php and place/index.php -- so include/
 * is reached via ../include/, not ./include/.
 *
 * Requires include/session_guard.php (sets $role, $userId) and
 * include/config.php (sets up $conn).
 *
 *   >>> ASSUMPTION -- please double check against your session_guard.php:
 *   Partner users are expected to come through with $role === 'partner'
 *   and $userId equal to their row in `users`. If your app uses a
 *   different role string (or a different session variable for the
 *   partner's own id), update the two lines marked "ADAPT THIS" below.
 *
 * Schema notes (see u197683135_host dump):
 *   - events.user_id (varchar) is the owning partner's users.id, as a string.
 *   - events.ticketing_type is free text (e.g. "Free"/"Paid"/"RSVP"), not
 *     numeric -- it must NOT be run through number_format().
 *   - events.status is the publish/lifecycle flag (matches the badge logic
 *     used elsewhere in the app):
 *       1 = Published, 2 = Draft, 3 = Unapproved, 4 = Submit for Approval,
 *       0 (or anything else) = Expired.
 *     "Live" for the Top Events leaderboard, and the "published" KPI, both
 *     read status = 1. See eventStatusBadge() / LIVE_EVENT_SQL below.
 *   - payments.event_id is NOT events.id -- it's events.url (the slug).
 *     Every booking/attendee is one row in `payments`; price is stored as
 *     a varchar, so we only sum rows that are actually numeric.
 *   - views(event_id, views, date) is a daily per-event views log;
 *     events.views_count is the running total, used for the "all-time"
 *     numbers, while `views` is used for the trend chart and for any
 *     non-"all time" range.
 *   - partner_kyc.kyc_status is the KYC approval flag from partner/index.php.
 */
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';          // sets up $conn (mysqli)

$isAdmin   = ($role === 'admin');
$isPartner = ($role === 'partner'); // ADAPT THIS if your partner role string differs

if (!$isAdmin && !$isPartner) {
    header('Location: /profile');
    exit();
}

$pageTitle  = 'Analytics';
$activePage = 'analytics';

/* Only ever sum payments.price when it's actually a plain number --
   the column is a free-text varchar in this schema ('FREE', '', '0', '250', ...). */
const NUMERIC_PRICE_SQL = "CASE WHEN p.price REGEXP '^[0-9]+(\\\\.[0-9]+)?$' THEN CAST(p.price AS DECIMAL(12,2)) ELSE 0 END";

/* "Live" filter for leaderboards -- status = 1 is "Published", see schema note above. */
const LIVE_EVENT_SQL = "e.status = 1";

/* events.status -> [label, badge class], matching the badge pattern already
   used elsewhere in the app. Anything not explicitly 1-4 (including 0) is
   treated as Expired. */
function eventStatusBadge($status) {
    switch ((int) $status) {
        case 1:  return ['Published', 'badge-success'];
        case 2:  return ['Draft', 'badge-warning'];
        case 3:  return ['Unapproved', 'badge-warning'];
        case 4:  return ['Submit for Approval', 'badge-warning'];
        default: return ['Expired', 'badge-danger'];
    }
}

/* ===================== date range filter ===================== */
$allowedRanges = ['today', '7d', 'month', '90d', 'year', 'all'];
$range = $_GET['range'] ?? 'all';
if (!in_array($range, $allowedRanges, true)) { $range = 'all'; }

$rangeLabels = [
    'today' => 'Today',
    '7d'    => 'Last 7 Days',
    'month' => 'This Month',
    '90d'   => 'Last 90 Days',
    'year'  => 'This Year',
    'all'   => 'All Time',
];

/* Returns the inclusive start boundary for the range, or null for "all". */
function rangeStart(string $range): ?string {
    switch ($range) {
        case 'today': return date('Y-m-d 00:00:00');
        case '7d':    return date('Y-m-d 00:00:00', strtotime('-6 days'));
        case 'month': return date('Y-m-01 00:00:00');
        case '90d':   return date('Y-m-d 00:00:00', strtotime('-89 days'));
        case 'year':  return date('Y-01-01 00:00:00');
        default:      return null; // all time
    }
}
$rangeStart     = rangeStart($range); // 'Y-m-d H:i:s' string or null
$rangeStartDate = $rangeStart ? substr($rangeStart, 0, 10) : null; // 'Y-m-d' for the `views` table
$isAllTime      = ($range === 'all');

/* How the trend chart buckets for this range. */
$trendMode = 'daily';
if ($range === '90d') {
    $trendMode = 'weekly';
} elseif ($range === 'year' || $range === 'all') {
    $trendMode = 'monthly';
}
$trendDayCounts = ['today' => 1, '7d' => 7, 'month' => (int) date('j')];
$trendDays      = $trendDayCounts[$range] ?? 30; // only used in 'daily' mode

/* ===================== partner selector ===================== */
$partnerId = 0;
if ($isAdmin) {
    $partnerId = isset($_GET['partner_id']) ? (int) $_GET['partner_id'] : 0;
} else {
    $partnerId = (int) $userId; // ADAPT THIS if the partner's own id lives in a different session var
}

$partnerOptions = [];
if ($isAdmin) {
    $partnersStmt = $conn->query("
        SELECT u.id, u.fname, u.lname, k.business_name
        FROM users u
        LEFT JOIN partner_kyc k ON k.user_id = u.id
        WHERE u.user_type = 2
        ORDER BY (k.business_name IS NULL OR k.business_name = ''), k.business_name, u.fname
    ");
    $partnerOptions = $partnersStmt ? $partnersStmt->fetch_all(MYSQLI_ASSOC) : [];
}

$selectedPartner = null;
if ($partnerId) {
    $stmt = $conn->prepare("
        SELECT u.*, k.business_name, k.kyc_status
        FROM users u
        LEFT JOIN partner_kyc k ON k.user_id = u.id
        WHERE u.id = ? AND u.user_type = 2
    ");
    $stmt->bind_param('i', $partnerId);
    $stmt->execute();
    $selectedPartner = $stmt->get_result()->fetch_assoc() ?: null;
    if (!$selectedPartner) {
        if ($isPartner) {
            // Partner account isn't a valid user_type=2 row -- bail out rather than
            // silently falling through to the platform-wide admin view.
            header('Location: /profile');
            exit();
        }
        $partnerId = 0;
    }
}

/* ===================== helpers ===================== */
function inr($n) {
    return '₹' . number_format((float) $n, 2);
}

/* ======================================================================
   MODE: ALL PARTNERS (platform-wide) -- admin only, partners never reach here
   ====================================================================== */
if (!$partnerId) {

    $totals = $conn->query("
        SELECT
            (SELECT COUNT(*) FROM users WHERE user_type = 2) AS total_partners,
            (SELECT COUNT(*) FROM partner_kyc WHERE kyc_status = 1) AS approved_partners,
            (SELECT COUNT(*) FROM events) AS total_events,
            (SELECT COUNT(*) FROM events WHERE status = 1) AS published_events
    ")->fetch_assoc();

    /* Events created within the selected range (only meaningful when not "all time"). */
    if (!$isAllTime) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total_events, SUM(status = 1) AS published_events
            FROM events WHERE created_at >= ?
        ");
        $stmt->bind_param('s', $rangeStart);
        $stmt->execute();
        $rangeEvents = $stmt->get_result()->fetch_assoc();
        $totals['total_events']     = (int) $rangeEvents['total_events'];
        $totals['published_events'] = (int) $rangeEvents['published_events'];
    }

    /* Total views: all-time running total, or summed from the daily log for a range. */
    if ($isAllTime) {
        $totals['total_views'] = (int) ($conn->query("SELECT COALESCE(SUM(views_count),0) AS v FROM events")->fetch_assoc()['v'] ?? 0);
    } else {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(views),0) AS v FROM views WHERE date >= ?");
        $stmt->bind_param('s', $rangeStartDate);
        $stmt->execute();
        $totals['total_views'] = (int) $stmt->get_result()->fetch_assoc()['v'];
    }

    /* Bookings + revenue, scoped to the range via payments.created_at. */
    if ($isAllTime) {
        $bookingRow = $conn->query("
            SELECT COUNT(*) AS total_bookings, COALESCE(SUM(" . NUMERIC_PRICE_SQL . "),0) AS total_revenue
            FROM payments p
        ")->fetch_assoc();
    } else {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total_bookings, COALESCE(SUM(" . NUMERIC_PRICE_SQL . "),0) AS total_revenue
            FROM payments p WHERE p.created_at >= ?
        ");
        $stmt->bind_param('s', $rangeStart);
        $stmt->execute();
        $bookingRow = $stmt->get_result()->fetch_assoc();
    }
    $totals['total_bookings'] = (int) $bookingRow['total_bookings'];
    $totalRevenue             = (float) $bookingRow['total_revenue'];

    /* Top 5 LIVE (status = 1) events platform-wide, by views (all-time
       views_count for "all time", or by views logged within the selected
       range otherwise). */
    if ($isAllTime) {
        $topEvents = $conn->query("
            SELECT e.id, e.tittle, e.views_count AS ranked_views, e.user_id,
                   u.fname, u.lname, k.business_name
            FROM events e
            LEFT JOIN users u ON u.id = e.user_id
            LEFT JOIN partner_kyc k ON k.user_id = u.id
            WHERE " . LIVE_EVENT_SQL . "
            ORDER BY e.views_count DESC
            LIMIT 5
        ")->fetch_all(MYSQLI_ASSOC);
    } else {
        $stmt = $conn->prepare("
            SELECT e.id, e.tittle, e.user_id, u.fname, u.lname, k.business_name,
                   COALESCE(SUM(v.views), 0) AS ranked_views
            FROM events e
            LEFT JOIN users u ON u.id = e.user_id
            LEFT JOIN partner_kyc k ON k.user_id = u.id
            LEFT JOIN views v ON v.event_id = e.id AND v.date >= ?
            WHERE " . LIVE_EVENT_SQL . "
            GROUP BY e.id
            ORDER BY ranked_views DESC
            LIMIT 5
        ");
        $stmt->bind_param('s', $rangeStartDate);
        $stmt->execute();
        $topEvents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /* Top 5 partners platform-wide, by bookings within the selected range. */
    if ($isAllTime) {
        $topPartners = $conn->query("
            SELECT u.id, u.fname, u.lname, k.business_name,
                   COUNT(DISTINCT e.id) AS event_count,
                   COUNT(p.id) AS bookings,
                   COALESCE(SUM(" . NUMERIC_PRICE_SQL . "),0) AS revenue
            FROM users u
            LEFT JOIN partner_kyc k ON k.user_id = u.id
            LEFT JOIN events e ON e.user_id = u.id
            LEFT JOIN payments p ON p.event_id = e.url
            WHERE u.user_type = 2
            GROUP BY u.id
            ORDER BY bookings DESC, revenue DESC
            LIMIT 5
        ")->fetch_all(MYSQLI_ASSOC);
    } else {
        $stmt = $conn->prepare("
            SELECT u.id, u.fname, u.lname, k.business_name,
                   COUNT(DISTINCT e.id) AS event_count,
                   COUNT(p.id) AS bookings,
                   COALESCE(SUM(" . NUMERIC_PRICE_SQL . "),0) AS revenue
            FROM users u
            LEFT JOIN partner_kyc k ON k.user_id = u.id
            LEFT JOIN events e ON e.user_id = u.id
            LEFT JOIN payments p ON p.event_id = e.url AND p.created_at >= ?
            WHERE u.user_type = 2
            GROUP BY u.id
            ORDER BY bookings DESC, revenue DESC
            LIMIT 5
        ");
        $stmt->bind_param('s', $rangeStart);
        $stmt->execute();
        $topPartners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /* Views trend, every event combined. */
    if ($trendMode === 'monthly') {
        $trendRaw = $conn->query("
            SELECT DATE_FORMAT(date, '%Y-%m-01') AS bucket, SUM(views) AS total
            FROM views
            WHERE date >= (CURDATE() - INTERVAL 11 MONTH)
            GROUP BY bucket
            ORDER BY bucket
        ")->fetch_all(MYSQLI_ASSOC);
    } elseif ($trendMode === 'weekly') {
        $trendRaw = $conn->query("
            SELECT DATE_SUB(date, INTERVAL WEEKDAY(date) DAY) AS bucket, SUM(views) AS total
            FROM views
            WHERE date >= (CURDATE() - INTERVAL 89 DAY)
            GROUP BY bucket
            ORDER BY bucket
        ")->fetch_all(MYSQLI_ASSOC);
    } else {
        $stmt = $conn->prepare("
            SELECT date AS bucket, SUM(views) AS total
            FROM views
            WHERE date >= (CURDATE() - INTERVAL ? DAY)
            GROUP BY date
            ORDER BY date
        ");
        $daysBack = $trendDays - 1;
        $stmt->bind_param('i', $daysBack);
        $stmt->execute();
        $trendRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

/* ======================================================================
   MODE: SINGLE PARTNER (admin viewing one partner, or a partner viewing themself)
   ====================================================================== */
} else {

    $partnerIdStr = (string) $partnerId;

    if ($isAllTime) {
        $stmt = $conn->prepare("
            SELECT
                (SELECT COUNT(*) FROM events WHERE user_id = ?) AS total_events,
                (SELECT COUNT(*) FROM events WHERE user_id = ? AND status = 1) AS published_events,
                (SELECT COALESCE(SUM(views_count),0) FROM events WHERE user_id = ?) AS total_views
        ");
        $stmt->bind_param('sss', $partnerIdStr, $partnerIdStr, $partnerIdStr);
        $stmt->execute();
        $totals = $stmt->get_result()->fetch_assoc();
    } else {
        $stmt = $conn->prepare("
            SELECT
                (SELECT COUNT(*) FROM events WHERE user_id = ? AND created_at >= ?) AS total_events,
                (SELECT COUNT(*) FROM events WHERE user_id = ? AND status = 1 AND created_at >= ?) AS published_events,
                (SELECT COALESCE(SUM(v.views),0) FROM views v JOIN events e ON e.id = v.event_id WHERE e.user_id = ? AND v.date >= ?) AS total_views
        ");
        $stmt->bind_param('ssssss', $partnerIdStr, $rangeStart, $partnerIdStr, $rangeStart, $partnerIdStr, $rangeStartDate);
        $stmt->execute();
        $totals = $stmt->get_result()->fetch_assoc();
    }

    if ($isAllTime) {
        $stmt = $conn->prepare("
            SELECT COUNT(p.id) AS total_bookings,
                   COALESCE(SUM(" . NUMERIC_PRICE_SQL . "),0) AS total_revenue
            FROM payments p
            JOIN events e ON e.url = p.event_id
            WHERE e.user_id = ?
        ");
        $stmt->bind_param('s', $partnerId);
    } else {
        $stmt = $conn->prepare("
            SELECT COUNT(p.id) AS total_bookings,
                   COALESCE(SUM(" . NUMERIC_PRICE_SQL . "),0) AS total_revenue
            FROM payments p
            JOIN events e ON e.url = p.event_id
            WHERE e.user_id = ? AND p.created_at >= ?
        ");
        $stmt->bind_param('ss', $partnerId, $rangeStart);
    }
    $stmt->execute();
    $bookingRow               = $stmt->get_result()->fetch_assoc();
    $totals['total_bookings'] = (int) $bookingRow['total_bookings'];
    $totalRevenue             = (float) $bookingRow['total_revenue'];

    /* This partner's events, paginated -- same 15-per-page pattern as
       partner/index.php's partner list -- with per-event views/bookings/
       revenue/status/ticketing type, scoped to the selected date range. */
    $eventsPerPage = 15;
    $eventsPage    = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    if ($eventsPage < 1) { $eventsPage = 1; }

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM events WHERE user_id = ?");
    $stmt->bind_param('s', $partnerId);
    $stmt->execute();
    $totalPartnerEvents = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $totalEventPages    = max(1, (int) ceil($totalPartnerEvents / $eventsPerPage));
    if ($eventsPage > $totalEventPages) { $eventsPage = $totalEventPages; }
    $eventsOffset = ($eventsPage - 1) * $eventsPerPage;

    if ($isAllTime) {
        $stmt = $conn->prepare("
            SELECT e.id, e.tittle, e.start, e.views_count, e.status, e.url, e.ticketing_type,
                   (SELECT COUNT(*) FROM payments p WHERE p.event_id = e.url) AS bookings,
                   (SELECT COALESCE(SUM(" . NUMERIC_PRICE_SQL . "),0) FROM payments p WHERE p.event_id = e.url) AS revenue
            FROM events e
            WHERE e.user_id = ?
            ORDER BY e.start DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param('sii', $partnerId, $eventsPerPage, $eventsOffset);
    } else {
        $stmt = $conn->prepare("
            SELECT e.id, e.tittle, e.start, e.status, e.url, e.ticketing_type,
                   (SELECT COALESCE(SUM(v.views),0) FROM views v WHERE v.event_id = e.id AND v.date >= ?) AS views_count,
                   (SELECT COUNT(*) FROM payments p WHERE p.event_id = e.url AND p.created_at >= ?) AS bookings,
                   (SELECT COALESCE(SUM(" . NUMERIC_PRICE_SQL . "),0) FROM payments p WHERE p.event_id = e.url AND p.created_at >= ?) AS revenue
            FROM events e
            WHERE e.user_id = ?
            ORDER BY e.start DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param('ssssii', $rangeStartDate, $rangeStart, $rangeStart, $partnerId, $eventsPerPage, $eventsOffset);
    }
    $stmt->execute();
    $partnerEvents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    /* Views trend, this partner's events only. */
    if ($trendMode === 'monthly') {
        $stmt = $conn->prepare("
            SELECT DATE_FORMAT(v.date, '%Y-%m-01') AS bucket, SUM(v.views) AS total
            FROM views v
            JOIN events e ON e.id = v.event_id
            WHERE e.user_id = ? AND v.date >= (CURDATE() - INTERVAL 11 MONTH)
            GROUP BY bucket
            ORDER BY bucket
        ");
        $stmt->bind_param('s', $partnerId);
    } elseif ($trendMode === 'weekly') {
        $stmt = $conn->prepare("
            SELECT DATE_SUB(v.date, INTERVAL WEEKDAY(v.date) DAY) AS bucket, SUM(v.views) AS total
            FROM views v
            JOIN events e ON e.id = v.event_id
            WHERE e.user_id = ? AND v.date >= (CURDATE() - INTERVAL 89 DAY)
            GROUP BY bucket
            ORDER BY bucket
        ");
        $stmt->bind_param('s', $partnerId);
    } else {
        $stmt = $conn->prepare("
            SELECT v.date AS bucket, SUM(v.views) AS total
            FROM views v
            JOIN events e ON e.id = v.event_id
            WHERE e.user_id = ? AND v.date >= (CURDATE() - INTERVAL ? DAY)
            GROUP BY v.date
            ORDER BY v.date
        ");
        $daysBack = $trendDays - 1;
        $stmt->bind_param('si', $partnerId, $daysBack);
    }
    $stmt->execute();
    $trendRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/* Normalize the trend into a fixed, evenly-spaced series (fill gaps with 0)
   so the bar chart always renders a full strip. Bucket keys line up with
   whatever grouping the query above used (daily date / week-start date /
   month-start date). */
$trend = [];
if ($trendMode === 'monthly') {
    for ($i = 11; $i >= 0; $i--) {
        $key = date('Y-m-01', strtotime("-$i month"));
        $trend[$key] = 0;
    }
} elseif ($trendMode === 'weekly') {
    $mondayThisWeek = date('Y-m-d', strtotime('monday this week'));
    for ($i = 12; $i >= 0; $i--) { // 13 weeks ~= 90 days
        $key = date('Y-m-d', strtotime("-$i week", strtotime($mondayThisWeek)));
        $trend[$key] = 0;
    }
} else {
    for ($i = $trendDays - 1; $i >= 0; $i--) {
        $key = date('Y-m-d', strtotime("-$i day"));
        $trend[$key] = 0;
    }
}
foreach ($trendRaw as $row) {
    if (isset($trend[$row['bucket']])) { $trend[$row['bucket']] = (int) $row['total']; }
}
$trendMax = max(1, max($trend));

$trendChartLabel = [
    'daily'   => htmlspecialchars($rangeLabels[$range]),
    'weekly'  => 'Last 13 Weeks',
    'monthly' => 'Last 12 Months',
][$trendMode];

include __DIR__ . '/../include/header.php';
/* header.php has already opened <div class="content"> */
?>

<div class="page-head analytics-head">
  <div>
    <h2>Analytics</h2>
    <p><?php echo $partnerId
      ? ($isPartner ? 'Your performance' : 'Performance for ' . htmlspecialchars(trim(($selectedPartner['fname'] ?? '') . ' ' . ($selectedPartner['lname'] ?? '')) ?: 'this partner'))
      : 'Platform-wide performance across all partners'; ?> · <?php echo htmlspecialchars($rangeLabels[$range]); ?></p>
  </div>
  <form method="GET" action="" class="analytics-filters">
    <?php if ($isAdmin): ?>
    <select class="form-control-c" name="partner_id" onchange="this.form.submit()">
      <option value="0" <?php echo $partnerId === 0 ? 'selected' : ''; ?>>All Partners</option>
      <?php foreach ($partnerOptions as $po):
        $label = trim(($po['fname'] ?? '') . ' ' . ($po['lname'] ?? '')) ?: 'Partner #' . $po['id'];
        if (!empty($po['business_name'])) { $label .= ' — ' . $po['business_name']; }
      ?>
        <option value="<?php echo (int) $po['id']; ?>" <?php echo $partnerId === (int) $po['id'] ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($label); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select class="form-control-c" name="range" onchange="this.form.submit()">
      <?php foreach ($rangeLabels as $rv => $rl): ?>
        <option value="<?php echo htmlspecialchars($rv); ?>" <?php echo $range === $rv ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($rl); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if ($partnerId && $selectedPartner): ?>
<div class="panel-card" style="margin-bottom:1.1rem;">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="cell-title" style="font-size:1rem;">
        <?php echo htmlspecialchars(trim(($selectedPartner['fname'] ?? '') . ' ' . ($selectedPartner['lname'] ?? '')) ?: '—'); ?>
      </div>
      <div class="cell-sub"><?php echo htmlspecialchars($selectedPartner['email'] ?: '—'); ?> · <?php echo htmlspecialchars($selectedPartner['business_name'] ?: 'No business name on file'); ?></div>
    </div>
    <span class="badge-pill <?php echo ((int) ($selectedPartner['kyc_status'] ?? 0) === 1) ? 'badge-live' : 'badge-pending'; ?>">
      <?php echo ((int) ($selectedPartner['kyc_status'] ?? 0) === 1) ? 'KYC Approved' : 'KYC Pending'; ?>
    </span>
  </div>
</div>
<?php endif; ?>

<!-- ================= KPI CARDS ================= -->
<div class="kpi-grid">
  <?php if (!$partnerId): ?>
    <div class="kpi-card">
      <div class="kpi-label">Total Partners</div>
      <div class="kpi-value"><?php echo number_format((int) $totals['total_partners']); ?></div>
      <div class="kpi-sub"><?php echo number_format((int) $totals['approved_partners']); ?> KYC approved</div>
    </div>
  <?php endif; ?>
  <div class="kpi-card">
    <div class="kpi-label"><?php echo $isAllTime ? 'Total Events' : 'New Events'; ?></div>
    <div class="kpi-value"><?php echo number_format((int) $totals['total_events']); ?></div>
    <div class="kpi-sub"><?php echo number_format((int) $totals['published_events']); ?> published</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Views</div>
    <div class="kpi-value"><?php echo number_format((int) $totals['total_views']); ?></div>
    <div class="kpi-sub"><?php echo $isAllTime ? 'All-time' : htmlspecialchars($rangeLabels[$range]); ?>, across <?php echo $partnerId ? "this partner's" : 'all'; ?> events</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Bookings</div>
    <div class="kpi-value"><?php echo number_format((int) $totals['total_bookings']); ?></div>
    <div class="kpi-sub">Attendee records, <?php echo htmlspecialchars(lcfirst($rangeLabels[$range])); ?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Revenue</div>
    <div class="kpi-value"><?php echo inr($totalRevenue); ?></div>
    <div class="kpi-sub">Sum of numeric ticket payments</div>
  </div>
</div>

<!-- ================= TREND CHART ================= -->
<div class="panel-card">
  <div class="head">
    <div>
      <h5>Views Trend</h5>
      <div class="sub"><?php echo $partnerId ? "This partner's events only" : 'All events combined'; ?> · <?php echo $trendChartLabel; ?></div>
    </div>
  </div>
  <div class="trend-chart" id="trendChart">
    <?php foreach ($trend as $d => $v):
      $h = max(4, (int) round(($v / $trendMax) * 120));
      if ($trendMode === 'monthly') {
        $dayLabel = date('F Y', strtotime($d));
        $barLabel = date('M', strtotime($d));
      } elseif ($trendMode === 'weekly') {
        $dayLabel = 'Week of ' . date('D, M j, Y', strtotime($d));
        $barLabel = date('j M', strtotime($d));
      } else {
        $dayLabel = date('D, M j, Y', strtotime($d));
        $barLabel = date('j M', strtotime($d));
      }
    ?>
      <div class="trend-bar-c" data-date="<?php echo htmlspecialchars($dayLabel); ?>" data-views="<?php echo (int) $v; ?>">
        <div class="trend-bar" style="height:<?php echo $h; ?>px;"></div>
        <div class="trend-day"><?php echo htmlspecialchars($barLabel); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="trend-tooltip" id="trendTooltip"></div>
</div>

<?php if (!$partnerId): ?>
<!-- ================= ALL-PARTNERS: LEADERBOARDS (admin only) ================= -->
<div class="row g-3" style="margin-top:0;">
  <div class="col-lg-6">
    <div class="panel-card h-100">
      <div class="head">
        <div>
          <h5>Top Events</h5>
          <div class="sub">Live (published) events only · by views, <?php echo htmlspecialchars(lcfirst($rangeLabels[$range])); ?></div>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table-clean">
          <thead><tr><th>Event</th><th>Partner</th><th style="text-align:right;">Views</th></tr></thead>
          <tbody>
            <?php if ($topEvents): foreach ($topEvents as $ev):
              $partnerLabel = trim(($ev['fname'] ?? '') . ' ' . ($ev['lname'] ?? '')) ?: '—';
              if (!empty($ev['business_name'])) { $partnerLabel = $ev['business_name']; }
            ?>
              <tr>
                <td class="cell-title"><?php echo htmlspecialchars($ev['tittle']); ?></td>
                <td class="cell-sub"><?php echo htmlspecialchars($partnerLabel); ?></td>
                <td style="text-align:right;" class="cell-sub"><?php echo number_format((int) $ev['ranked_views']); ?></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="3" class="cell-sub text-center">No live events yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel-card h-100">
      <div class="head">
        <div>
          <h5>Top Partners</h5>
          <div class="sub">By bookings, <?php echo htmlspecialchars(lcfirst($rangeLabels[$range])); ?></div>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table-clean">
          <thead><tr><th>Partner</th><th style="text-align:right;">Events</th><th style="text-align:right;">Bookings</th><th style="text-align:right;">Revenue</th></tr></thead>
          <tbody>
            <?php if ($topPartners): foreach ($topPartners as $tp):
              $label = trim(($tp['fname'] ?? '') . ' ' . ($tp['lname'] ?? '')) ?: '—';
              if (!empty($tp['business_name'])) { $label = $tp['business_name']; }
            ?>
              <tr>
                <td>
                  <div class="cell-title"><?php echo htmlspecialchars($label); ?></div>
                  <a href="?partner_id=<?php echo (int) $tp['id']; ?>&range=<?php echo htmlspecialchars($range); ?>" class="cell-sub" style="color:var(--pink);">View analytics →</a>
                </td>
                <td style="text-align:right;" class="cell-sub"><?php echo number_format((int) $tp['event_count']); ?></td>
                <td style="text-align:right;" class="cell-sub"><?php echo number_format((int) $tp['bookings']); ?></td>
                <td style="text-align:right;" class="cell-sub"><?php echo inr($tp['revenue']); ?></td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="4" class="cell-sub text-center">No partners yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ================= SINGLE PARTNER: EVENTS TABLE ================= -->
<div class="panel-card">
  <div class="head">
    <div>
      <h5>Events</h5>
      <div class="sub">Per-event views, bookings, revenue &amp; ticketing type · <?php echo htmlspecialchars($rangeLabels[$range]); ?></div>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table-clean">
      <thead>
        <tr>
          <th>Event</th>
          <th>Date</th>
          <th>Status</th>
          <th style="text-align:right;">Views</th>
          <th style="text-align:right;">Bookings</th>
          <th style="text-align:right;">Type</th>
          <th style="text-align:right;">Revenue</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($partnerEvents): foreach ($partnerEvents as $ev):
          [$statusLabel, $statusClass] = eventStatusBadge($ev['status']);
        ?>
          <tr>
            <td class="cell-title"><?php echo htmlspecialchars($ev['tittle']); ?></td>
            <td class="cell-sub"><?php echo htmlspecialchars(date('d M Y', strtotime($ev['start']))); ?></td>
            <td>
              <span class="badge <?php echo $statusClass; ?> light"><?php echo $statusLabel; ?></span>
            </td>
            <td style="text-align:right;" class="cell-sub"><?php echo number_format((int) $ev['views_count']); ?></td>
            <td style="text-align:right;" class="cell-sub"><?php echo number_format((int) $ev['bookings']); ?></td>
            <td style="text-align:right;" class="cell-sub"><?php echo htmlspecialchars($ev['ticketing_type'] !== '' ? $ev['ticketing_type'] : '—'); ?></td>
            <td style="text-align:right;" class="cell-sub"><?php echo inr($ev['revenue']); ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="7" class="cell-sub text-center">This partner has no events yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalEventPages > 1): ?>
  <div class="pager-c">
    <div class="pager-info">
      Showing <?php echo $eventsOffset + 1; ?>–<?php echo min($eventsOffset + $eventsPerPage, $totalPartnerEvents); ?> of <?php echo $totalPartnerEvents; ?>
    </div>
    <div class="pager-links">
      <a class="pager-link <?php echo $eventsPage <= 1 ? 'disabled' : ''; ?>" href="?partner_id=<?php echo $partnerId; ?>&range=<?php echo htmlspecialchars($range); ?>&page=<?php echo max(1, $eventsPage - 1); ?>"><i class="fa-solid fa-chevron-left"></i></a>
      <?php
        $pStart = max(1, $eventsPage - 2);
        $pEnd   = min($totalEventPages, $pStart + 4);
        $pStart = max(1, $pEnd - 4);
        for ($p2 = $pStart; $p2 <= $pEnd; $p2++):
      ?>
        <a class="pager-link <?php echo $p2 === $eventsPage ? 'active' : ''; ?>" href="?partner_id=<?php echo $partnerId; ?>&range=<?php echo htmlspecialchars($range); ?>&page=<?php echo $p2; ?>"><?php echo $p2; ?></a>
      <?php endfor; ?>
      <a class="pager-link <?php echo $eventsPage >= $totalEventPages ? 'disabled' : ''; ?>" href="?partner_id=<?php echo $partnerId; ?>&range=<?php echo htmlspecialchars($range); ?>&page=<?php echo min($totalEventPages, $eventsPage + 1); ?>"><i class="fa-solid fa-chevron-right"></i></a>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<style>
  .kpi-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:.9rem; margin-bottom:1.1rem; }
  .kpi-card{ background:var(--panel-2); border:1px solid var(--line); border-radius:12px; padding:1rem 1.1rem; }
  .kpi-label{ font-size:.72rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--muted-2); }
  .kpi-value{ font-size:1.55rem; font-weight:800; color:var(--text); margin-top:.25rem; }
  .kpi-sub{ font-size:.76rem; color:var(--muted); margin-top:.2rem; }

  .trend-chart{ display:flex; align-items:flex-end; gap:.5rem; padding-top:.6rem; overflow-x:auto; }
  .trend-bar-c{ display:flex; flex-direction:column; align-items:center; justify-content:flex-end; min-width:34px; height:150px; gap:.4rem; cursor:pointer; }
  .trend-bar{ width:100%; max-width:22px; background:var(--pink); border-radius:5px 5px 0 0; opacity:.85; transition:opacity .15s; }
  .trend-bar-c:hover .trend-bar{ opacity:1; }
  .trend-day{ font-size:.66rem; color:var(--muted-2); font-weight:600; white-space:nowrap; }

  .trend-tooltip{
    position:fixed; z-index:1000; pointer-events:none; display:none;
    background:var(--panel-2); border:1px solid var(--line); border-radius:8px;
    padding:.5rem .7rem; font-size:.76rem; color:var(--text); white-space:nowrap;
    box-shadow:0 6px 16px rgba(0,0,0,.35);
  }
  .trend-tooltip .tt-views{ font-weight:800; color:var(--pink); }
  .trend-tooltip .tt-date{ color:var(--muted); margin-top:.1rem; }

  .panel-card.h-100{ height:100%; }

  .pager-c{ display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.8rem; padding-top:1.1rem; margin-top:1.1rem; border-top:1px solid var(--line); }
  .pager-info{ font-size:.78rem; color:var(--muted-2); font-weight:600; }
  .pager-links{ display:flex; align-items:center; gap:.35rem; }
  .pager-link{ min-width:34px; height:34px; padding:0 .55rem; border-radius:8px; border:1px solid var(--line); background:var(--panel-2); color:var(--muted); font-size:.8rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
  .pager-link:hover{ border-color:var(--pink); color:var(--text); }
  .pager-link.active{ background:var(--pink); border-color:var(--pink); color:#fff; }
  .pager-link.disabled{ opacity:.4; pointer-events:none; }

  /* ---- Responsive partner / date selectors ---- */
  .analytics-head{ display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem; }
  .analytics-filters{ display:flex; gap:.6rem; flex-wrap:wrap; }
  .analytics-filters select{ min-width:200px; flex:1 1 200px; }
  @media (max-width: 640px) {
    .analytics-head{ flex-direction:column; align-items:stretch; }
    .analytics-filters{ flex-direction:column; width:100%; }
    .analytics-filters select{ width:100%; min-width:0; }
    .trend-bar-c{ min-width:28px; }
  }
</style>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    var tooltip = document.getElementById('trendTooltip');
    if (!tooltip) { return; }

    document.querySelectorAll('#trendChart .trend-bar-c').forEach(function (bar) {
      bar.addEventListener('mouseenter', function () {
        tooltip.innerHTML =
          '<div class="tt-views">' + bar.dataset.views + ' view' + (bar.dataset.views === '1' ? '' : 's') + '</div>' +
          '<div class="tt-date">' + bar.dataset.date + '</div>';
        tooltip.style.display = 'block';
      });
      bar.addEventListener('mousemove', function (e) {
        var tw = tooltip.offsetWidth;
        var left = e.clientX + 14;
        if (left + tw > window.innerWidth - 10) { left = e.clientX - tw - 14; }
        tooltip.style.left = left + 'px';
        tooltip.style.top  = (e.clientY - 14) + 'px';
      });
      bar.addEventListener('mouseleave', function () {
        tooltip.style.display = 'none';
      });
    });
  });
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>