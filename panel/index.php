<?php
require_once __DIR__ . '/include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/include/config.php';          // must set up $conn (mysqli)
require_once __DIR__ . '/include/payouts_helpers.php'; // get_payout_settings(), partner_payout_eligibility(), format_inr()

$pageTitle = 'Dashboard';
$activePage = 'dashboard';
$todayLabel = date('d M Y');

/* ===================== helpers ===================== */
function q_one($conn, $sql) {
    $r = $conn->query($sql);
    if (!$r) return 0;
    $row = $r->fetch_assoc();
    return $row ? array_values($row)[0] : 0;
}
function fmt_inr_lakh($amount) {
    $amount = (float) $amount;
    if ($amount >= 10000000) return '₹' . round($amount / 10000000, 2) . ' Cr';
    if ($amount >= 100000) return '₹' . round($amount / 100000, 2) . 'L';
    return '₹' . number_format($amount);
}

/* ===================== date-range filter ===================== */
/* ?range=7d | 30d | 90d | all  (defaults to 30d) */
$validRanges = ['7d', '30d', '90d', 'all'];
$range = (isset($_GET['range']) && in_array($_GET['range'], $validRanges, true)) ? $_GET['range'] : '30d';

function range_days($range) {
    switch ($range) {
        case '7d':  return 7;
        case '30d': return 30;
        case '90d': return 90;
        default:    return null; // 'all' => no lower bound
    }
}
/* Builds a safe SQL boolean fragment for a given datetime column.
   $rangeDays is always one of {7,30,90,null} here — never user-supplied text. */
function range_clause($col, $rangeDays) {
    if ($rangeDays === null) return '1=1';
    return "$col >= DATE_SUB(NOW(), INTERVAL " . (int) $rangeDays . " DAY)";
}
function range_tabs_html($range) {
    $tabs = ['7d' => '7D', '30d' => '30D', '90d' => '90D', 'all' => 'All time'];
    $html = '<div class="range-tabs">';
    foreach ($tabs as $key => $label) {
        $cls = ($range === $key) ? 'active' : '';
        $html .= '<a href="?range=' . $key . '" class="' . $cls . '">' . $label . '</a>';
    }
    $html .= '</div>';
    return $html;
}

$rangeDays = range_days($range);
$rangeLabelMap = ['7d' => 'last 7 days', '30d' => 'last 30 days', '90d' => 'last 90 days', 'all' => 'all time'];
$rangeLabelText = $rangeLabelMap[$range];

/* ===================== shared: revenue chart, bucketed to match the selected range =====================
   7D  -> 7 daily buckets
   30D -> 30 daily buckets
   90D -> 13 weekly buckets
   All -> last 12 monthly buckets
*/
function build_chart_buckets($range) {
    $buckets = [];
    $now = new DateTime();

    if ($range === '7d' || $range === '30d') {
        $days = $range === '7d' ? 6 : 29;
        for ($i = $days; $i >= 0; $i--) {
            $day = (clone $now)->modify("-$i days");
            $start = (clone $day)->setTime(0, 0, 0);
            $end   = (clone $day)->setTime(23, 59, 59);
            $label = $range === '7d' ? $day->format('D') : $day->format('d M');
            $buckets[] = [$label, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
        }
    } elseif ($range === '90d') {
        for ($i = 12; $i >= 0; $i--) {
            $end   = (clone $now)->modify('-' . ($i * 7) . ' days')->setTime(23, 59, 59);
            $start = (clone $end)->modify('-6 days')->setTime(0, 0, 0);
            $buckets[] = [$start->format('d M'), $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
        }
    } else { // all
        for ($i = 11; $i >= 0; $i--) {
            $m     = (clone $now)->modify("-$i months");
            $start = (clone $m)->modify('first day of this month')->setTime(0, 0, 0);
            $end   = (clone $m)->modify('last day of this month')->setTime(23, 59, 59);
            $buckets[] = [$m->format('M Y'), $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
        }
    }
    return $buckets;
}

$chartLabels = [];
$chartRevenue = [];
foreach (build_chart_buckets($range) as $bucket) {
    list($label, $start, $end) = $bucket;
    $chartLabels[] = $label;
    $scopeSql = $role === 'admin'
        ? "SELECT COALESCE(SUM(price),0) v FROM payments WHERE created_at BETWEEN '$start' AND '$end'"
        : "SELECT COALESCE(SUM(p.price),0) v FROM payments p INNER JOIN events e ON p.event_id = e.url
           WHERE e.user_id = '$userId' AND p.created_at BETWEEN '$start' AND '$end'";
    $chartRevenue[] = round(((float) q_one($conn, $scopeSql)) / 100000, 2); // in lakhs
}

include __DIR__ . '/include/header.php';
/* header.php has already opened <div class="content"> */
?>
<style>
  .range-tabs a{padding:.4rem .85rem;border-radius:8px;font-size:.8rem;font-weight:600;color:var(--muted,#6f6f78);text-decoration:none;transition:background .15s,color .15s;}
  .range-tabs a.active{background:var(--pink,#ff3d68);color:#fff;}
  .range-tabs a:not(.active):hover{background:var(--panel-2,#1a1a1f);color:var(--text,#fff);}

  /* ===================== table alignment & truncation ===================== */
  .table-clean{ table-layout:fixed; }
  .table-clean th, .table-clean td{ vertical-align:middle; }
  .table-clean th.col-num, .table-clean td.col-num{ text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .table-clean th.col-center, .table-clean td.col-center{ text-align:center; }
  .table-clean td.trunc-cell, .table-clean th.trunc-cell{ overflow:hidden; }

  .row-thumb{ display:flex; align-items:center; gap:.55rem; min-width:0; }
  .row-thumb .thumb{ flex:none; }

  .trunc{
    display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0;
  }

  /* Unapproved / rejected status badge — same visual language as badge-pending,
     tinted pink/red since it's the "needs attention, was turned down" state. */
  .badge-rejected{ background:rgba(255,61,104,.14); color:var(--pink); }

  /* ===================== list-style rows used across the dashboard cards =====================
     approval-row / ticket-row + their child elements weren't defined in the shared
     stylesheet, which is what was causing the ragged, unaligned look on the
     Approval Queue, KYC Requests, Reported Events, Support Tickets and Payout
     Queue/History cards below — they were falling back to plain block stacking. */
  .approval-row{ display:flex; align-items:center; gap:.75rem; padding:.75rem 0; border-bottom:1px solid var(--line); }
  .approval-row:last-child{ border-bottom:none; }
  .approval-thumb{
    width:38px; height:38px; border-radius:10px; background:var(--panel-2); border:1px solid var(--line);
    display:flex; align-items:center; justify-content:center; color:var(--muted-2); font-size:.9rem; flex:none;
  }
  .approval-row .min-w-0{ min-width:0; }
  .approval-row .btn-mini{ flex:none; width:32px; height:32px; padding:0; display:inline-flex; align-items:center; justify-content:center; }
  .approval-row .btn-mini.approve:hover{ border-color:var(--green); color:var(--green); }
  .approval-row .btn-mini.reject:hover{ border-color:var(--pink); color:var(--pink); }

  .ticket-row{ display:flex; align-items:flex-start; gap:.7rem; padding:.75rem 0; border-bottom:1px solid var(--line); }
  .ticket-row:last-child{ border-bottom:none; }
  .tkt-dot{ width:8px; height:8px; border-radius:50%; margin-top:.45rem; flex:none; }
  .ticket-row .min-w-0{ min-width:0; flex:1; }
</style>
<?php

if ($role === 'admin') {

    $totalEvents   = (int) q_one($conn, "SELECT COUNT(*) FROM events");
    $ticketsBooked = (int) q_one($conn, "SELECT COUNT(*) FROM payments WHERE " . range_clause('created_at', $rangeDays));
    $grossRevenue  = (float) q_one($conn, "SELECT COALESCE(SUM(price),0) FROM payments WHERE " . range_clause('created_at', $rangeDays));
    $pendingApprovals = (int) q_one($conn, "SELECT COUNT(*) FROM events WHERE status = 4");
    $registeredUsers  = (int) q_one($conn, "SELECT COUNT(*) FROM users WHERE user_type = 0");
    $activePartners   = (int) q_one($conn, "SELECT COUNT(*) FROM partner WHERE user_type = 2");
    $activePromotions = (int) q_one($conn, "SELECT COUNT(*) FROM events WHERE is_boosted = 1");
    $openTickets      = (int) q_one($conn, "SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','pending')");

    // Bookings by category (top 4), scoped to the selected range
    $catRows = $conn->query("
        SELECT c.cat_name, COUNT(p.id) cnt
        FROM payments p
        INNER JOIN events e ON p.event_id = e.url
        INNER JOIN category c ON FIND_IN_SET(c.id, e.cat_id)
        WHERE " . range_clause('p.created_at', $rangeDays) . "
        GROUP BY c.id ORDER BY cnt DESC LIMIT 4
    ");
    $catLabels = []; $catData = [];
    if ($catRows) while ($r = $catRows->fetch_assoc()) { $catLabels[] = $r['cat_name']; $catData[] = (int) $r['cnt']; }
    if (!$catLabels) { $catLabels = ['No data yet']; $catData = [1]; }

    // Recent events (most recent overall — not range-scoped, this is a "latest activity" list)
    $recentEvents = $conn->query("
        SELECT e.tittle, e.image, e.start, e.status, e.url, pl.name AS venue,
               (SELECT COUNT(*) FROM payments WHERE event_id = e.url) sold
        FROM events e LEFT JOIN place pl ON e.sub_tittle = pl.id
        ORDER BY e.id DESC LIMIT 5
    ");

    // Approval queue (current queue — always shown regardless of range)
    $approvalQueue = $conn->query("
        SELECT e.id, e.tittle, e.user_id, u.fname, u.lname, pl.name AS venue
        FROM events e
        LEFT JOIN users u ON e.user_id = u.id
        LEFT JOIN place pl ON e.sub_tittle = pl.id
        WHERE e.status = 4 ORDER BY e.id DESC LIMIT 4
    ");

    // Partner KYC pending (status = 0 on users, partners only)
    $kycQueue = $conn->query("SELECT id, fname, lname, city FROM partner WHERE user_type = 2 AND status = 0 ORDER BY id DESC LIMIT 4");

    // Top partners by revenue, scoped to the selected range
    $topPartners = $conn->query("
        SELECT u.id, u.fname, u.lname, u.city, COUNT(DISTINCT e.id) events_cnt, COALESCE(SUM(p.price),0) revenue
        FROM partner u
        INNER JOIN events e ON e.user_id = u.id
        LEFT JOIN payments p ON p.event_id = e.url AND " . range_clause('p.created_at', $rangeDays) . "
        WHERE u.user_type = 2
        GROUP BY u.id ORDER BY revenue DESC LIMIT 4
    ");

    // Support tickets feed (current queue — always shown regardless of range)
    $ticketFeed = $conn->query("
        SELECT st.subject, st.priority, st.created_at, u.fname, u.lname
        FROM support_tickets st LEFT JOIN users u ON st.user_id = u.id
        WHERE st.status IN ('open','pending') ORDER BY st.created_at DESC LIMIT 4
    ");

    // Reported events queue (current queue — always shown regardless of range)
    $reportedEventsCount = (int) q_one($conn, "SELECT COUNT(*) FROM event_reports WHERE status IN ('open','reviewing')");
    $reportQueue = $conn->query("
        SELECT er.id, er.reason, er.status, er.created_at, e.tittle AS event_title, e.url AS event_url,
               ru.fname AS reporter_fname, ru.lname AS reporter_lname
        FROM event_reports er
        INNER JOIN events e ON e.id = er.event_id
        LEFT JOIN users ru ON ru.id = er.reported_by
        WHERE er.status IN ('open','reviewing')
        ORDER BY er.created_at DESC LIMIT 4
    ");

    // Payout queue: requests waiting on admin action (current queue — always shown regardless of range)
    $payoutQueueCount = (int) q_one($conn, "SELECT COUNT(*) FROM payouts WHERE status IN ('pending','processing')");
    $payoutQueueTotal = (float) q_one($conn, "SELECT COALESCE(SUM(amount),0) FROM payouts WHERE status IN ('pending','processing')");
    $payoutQueue = $conn->query("
        SELECT py.id, py.amount, py.method, py.status, py.requested_at,
               u.fname, u.lname, e.tittle AS event_title
        FROM payouts py
        INNER JOIN users u ON u.id = py.user_id
        LEFT JOIN events e ON e.id = py.event_id
        WHERE py.status IN ('pending','processing')
        ORDER BY py.requested_at ASC LIMIT 4
    ");
    ?>

    <div class="page-head">
      <div>
        <h2>Welcome back, <?php echo htmlspecialchars($displayName); ?> <span style="font-size:1.2rem;">👋</span></h2>
        <p>Here's what's happening across Highspinn today, <?php echo $todayLabel; ?>.</p>
      </div>
      <div class="d-flex align-items-center gap-2">
        <?php echo range_tabs_html($range); ?>
        <!--<a class="btn-mini" style="padding:.62rem .9rem;" href="?range=<?php echo $range; ?>&export=1"><i class="fa-solid fa-arrow-down-to-line"></i> Export Report</a>-->
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-6 col-xl-3"><div class="stat-card">
        <div class="ic-wrap" style="background:linear-gradient(135deg,var(--pink),var(--orange));"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="val"><?php echo number_format($totalEvents); ?></div><div class="lbl">Total Events</div>
      </div></div>
      <div class="col-6 col-xl-3"><div class="stat-card">
        <div class="ic-wrap" style="background:linear-gradient(135deg,#4d9fff,#7b6bff);"><i class="fa-solid fa-ticket"></i></div>
        <div class="val"><?php echo number_format($ticketsBooked); ?></div><div class="lbl">Tickets Booked</div>
        <div class="delta" style="color:var(--muted);font-size:.72rem;"><?php echo ucfirst($rangeLabelText); ?></div>
      </div></div>
      <div class="col-6 col-xl-3"><div class="stat-card">
        <div class="ic-wrap" style="background:linear-gradient(135deg,var(--green),#1fb673);"><i class="fa-solid fa-indian-rupee-sign"></i></div>
        <div class="val"><?php echo fmt_inr_lakh($grossRevenue); ?></div><div class="lbl">Gross Revenue</div>
        <div class="delta" style="color:var(--muted);font-size:.72rem;"><?php echo ucfirst($rangeLabelText); ?></div>
      </div></div>
      <div class="col-6 col-xl-3"><div class="stat-card">
        <div class="ic-wrap" style="background:linear-gradient(135deg,var(--yellow),var(--orange));"><i class="fa-solid fa-hourglass-half"></i></div>
        <div class="val"><?php echo number_format($pendingApprovals); ?></div><div class="lbl">Pending Approvals</div>
      </div></div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-6 col-xl-3"><div class="stat-card">
        <div class="ic-wrap" style="background:linear-gradient(135deg,#ff7ac0,var(--pink));"><i class="fa-solid fa-users"></i></div>
        <div class="val"><?php echo number_format($registeredUsers); ?></div><div class="lbl">Registered Users</div>
      </div></div>
      <div class="col-6 col-xl-3"><div class="stat-card">
        <div class="ic-wrap" style="background:linear-gradient(135deg,#4d9fff,#3ddc84);"><i class="fa-solid fa-handshake"></i></div>
        <div class="val"><?php echo number_format($activePartners); ?></div><div class="lbl">Active Partners</div>
      </div></div>
      <div class="col-6 col-xl-3"><div class="stat-card">
        <div class="ic-wrap" style="background:linear-gradient(135deg,var(--orange),var(--yellow));"><i class="fa-solid fa-bullhorn"></i></div>
        <div class="val"><?php echo number_format($activePromotions); ?></div><div class="lbl">Active Promotions</div>
      </div></div>
      <div class="col-6 col-xl-3"><div class="stat-card">
        <div class="ic-wrap" style="background:linear-gradient(135deg,#96969f,#6f6f78);"><i class="fa-solid fa-headset"></i></div>
        <div class="val"><?php echo number_format($openTickets); ?></div><div class="lbl">Open Support Tickets</div>
      </div></div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-lg-8"><div class="panel-card h-100">
        <div class="head"><div><h5>Revenue Overview</h5><div class="sub">Gross booking revenue, <?php echo $rangeLabelText; ?></div></div>
          <a class="link-more" href="/analytics">View analytics <i class="fa-solid fa-arrow-right"></i></a></div>
        <canvas id="revenueChart" height="110"></canvas>
      </div></div>
      <div class="col-lg-4"><div class="panel-card h-100">
        <div class="head"><div><h5>Bookings by Category</h5><div class="sub"><?php echo ucfirst($rangeLabelText); ?></div></div></div>
        <canvas id="categoryChart" height="170"></canvas>
        <div class="d-flex flex-wrap gap-3 mt-3" style="font-size:.76rem;">
          <?php $colors=['var(--pink)','var(--orange)','var(--yellow)','var(--blue)']; foreach($catLabels as $i=>$cl): ?>
          <span style="color:var(--muted);"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo $colors[$i%4]; ?>;margin-right:.35rem;"></span><?php echo htmlspecialchars($cl); ?></span>
          <?php endforeach; ?>
        </div>
      </div></div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-lg-7"><div class="panel-card h-100">
        <div class="head"><div><h5>Recent Events</h5><div class="sub">Latest events created across all partners</div></div>
          <a class="link-more" href="/events/">Event list <i class="fa-solid fa-arrow-right"></i></a></div>
        <div class="table-responsive"><table class="table-clean">
          <colgroup>
            <col style="width:42%;"><col style="width:22%;"><col style="width:14%;"><col style="width:14%;"><col style="width:8%;">
          </colgroup>
          <thead><tr><th>Event</th><th>Date</th><th class="col-center">Status</th>
          
          <!--<th class="col-num">Sold</th>-->
          
          </tr></thead>
          <tbody>
          <?php if ($recentEvents && $recentEvents->num_rows): while ($ev = $recentEvents->fetch_assoc()):
              $badge = 'badge-past'; $label = 'Expired';
              if ((int)$ev['status'] === 1) { $badge='badge-live'; $label='Live'; }
              elseif ((int)$ev['status'] === 4) { $badge='badge-pending'; $label='Pending'; }
              elseif ((int)$ev['status'] === 3) { $badge='badge-rejected'; $label='Unapproved'; }
          ?>
            <tr>
              <td class="trunc-cell">
                <div class="row-thumb">
                  <img class="thumb" src="<?php echo htmlspecialchars($ev['image']); ?>" alt="">
                  <a class="cell-title trunc" href="/events/single-event?id=<?php echo urlencode($ev['url']); ?>" style="color:inherit;text-decoration:none;" title="<?php echo htmlspecialchars($ev['tittle']); ?>"><?php echo htmlspecialchars($ev['tittle']); ?></a>
                </div>
              </td>
              <!--<td class="cell-sub trunc-cell"><span class="trunc" title="<?php echo htmlspecialchars($ev['venue'] ?? ''); ?>"><?php echo htmlspecialchars($ev['venue'] ?? '—'); ?></span></td>-->
              <td class="cell-sub"><?php echo date('d M', strtotime($ev['start'])); ?></td>
              <td class="col-center"><span class="badge-pill <?php echo $badge; ?>"><?php echo $label; ?></span></td>
              <!--<td class="col-num"><?php echo (int) $ev['sold']; ?></td>-->
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="5" class="cell-sub text-center">No events yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table></div>
      </div></div>

      <div class="col-lg-5"><div class="panel-card h-100">
        <div class="head"><div><h5>Event Approval Queue</h5><div class="sub">Waiting on your review</div></div>
          <?php if ($pendingApprovals>0): ?><span class="badge-count" style="font-size:.68rem;"><?php echo $pendingApprovals; ?> new</span><?php endif; ?></div>
        <?php if ($approvalQueue && $approvalQueue->num_rows): while ($a = $approvalQueue->fetch_assoc()): ?>
          <div class="approval-row">
            <div class="approval-thumb"><i class="fa-solid fa-music"></i></div>
            <div class="flex-grow-1 min-w-0">
              <div class="cell-title trunc" title="<?php echo htmlspecialchars($a['tittle']); ?>"><?php echo htmlspecialchars($a['tittle']); ?></div>
              <div class="cell-sub trunc">by <?php echo htmlspecialchars(trim($a['fname'].' '.$a['lname']) ?: 'Partner'); ?> &middot; <?php echo htmlspecialchars($a['venue'] ?? ''); ?></div>
            </div>
            <a class="btn-mini approve" href="/events/event-approval?id=<?php echo (int)$a['id']; ?>&action=approve"><i class="fa-solid fa-check"></i></a>
            <a class="btn-mini reject" href="/events/event-approval?id=<?php echo (int)$a['id']; ?>&action=reject"><i class="fa-solid fa-xmark"></i></a>
          </div>
        <?php endwhile; else: ?>
          <div class="cell-sub text-center py-3">Nothing waiting on review 🎉</div>
        <?php endif; ?>
        <a href="/events/event-approval" class="link-more d-inline-block mt-2">Review all requests <i class="fa-solid fa-arrow-right"></i></a>
      </div></div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-lg-6"><div class="panel-card h-100">
        <div class="head"><div><h5>Partner KYC Requests</h5><div class="sub">New partner onboarding awaiting verification</div></div>
          <?php $kn = $kycQueue ? $kycQueue->num_rows : 0; if ($kn>0): ?><span class="badge-count" style="font-size:.68rem;"><?php echo $kn; ?> pending</span><?php endif; ?></div>
        <?php if ($kycQueue && $kycQueue->num_rows): while ($k = $kycQueue->fetch_assoc()): ?>
          <div class="approval-row">
            <div class="approval-thumb"><i class="fa-solid fa-building"></i></div>
            <div class="flex-grow-1 min-w-0">
              <div class="cell-title trunc" title="<?php echo htmlspecialchars(trim($k['fname'].' '.$k['lname'])); ?>"><?php echo htmlspecialchars(trim($k['fname'].' '.$k['lname'])); ?></div>
              <div class="cell-sub trunc"><?php echo htmlspecialchars($k['city'] ?: 'City not set'); ?></div>
            </div>
            <a class="btn-mini approve" href="/partner/?id=<?php echo (int)$k['id']; ?>&action=verify"><i class="fa-solid fa-check"></i></a>
            <a class="btn-mini reject" href="/partner/?id=<?php echo (int)$k['id']; ?>&action=reject"><i class="fa-solid fa-xmark"></i></a>
          </div>
        <?php endwhile; else: ?>
          <div class="cell-sub text-center py-3">No pending KYC requests.</div>
        <?php endif; ?>
        <a href="/partner/" class="link-more d-inline-block mt-2">View all partners <i class="fa-solid fa-arrow-right"></i></a>
      </div></div>

      <div class="col-lg-6"><div class="panel-card h-100">
        <div class="head"><div><h5>Reported Events</h5><div class="sub">Flagged by users, needs moderation</div></div>
          <?php if ($reportedEventsCount>0): ?><span class="badge-count" style="font-size:.68rem;"><?php echo $reportedEventsCount; ?> open</span><?php endif; ?></div>
        <?php if ($reportQueue && $reportQueue->num_rows): while ($rp = $reportQueue->fetch_assoc()): ?>
          <div class="approval-row">
            <div class="approval-thumb"><i class="fa-solid fa-flag"></i></div>
            <div class="flex-grow-1 min-w-0">
              <div class="cell-title trunc" title="<?php echo htmlspecialchars($rp['event_title']); ?>"><?php echo htmlspecialchars($rp['event_title']); ?></div>
              <div class="cell-sub trunc"><?php echo htmlspecialchars($rp['reason']); ?> &middot; reported by <?php echo htmlspecialchars(trim(($rp['reporter_fname'] ?? '').' '.($rp['reporter_lname'] ?? '')) ?: 'a user'); ?></div>
            </div>
            <a class="btn-mini approve" href="/events/event-reports?id=<?php echo (int)$rp['id']; ?>&action=resolve"><i class="fa-solid fa-check"></i></a>
            <a class="btn-mini reject" href="/events/event-reports?id=<?php echo (int)$rp['id']; ?>&action=dismiss"><i class="fa-solid fa-xmark"></i></a>
          </div>
        <?php endwhile; else: ?>
          <div class="cell-sub text-center py-3">No open reports right now.</div>
        <?php endif; ?>
        <a href="/events/event-reports" class="link-more d-inline-block mt-2">View all reports <i class="fa-solid fa-arrow-right"></i></a>
      </div></div>
    </div>

    <div class="row g-3">
      <div class="col-lg-5"><div class="panel-card h-100">
        <div class="head"><div><h5>Top Event Partners</h5><div class="sub">Ranked by revenue, <?php echo $rangeLabelText; ?></div></div>
          <a class="link-more" href="/partner/">View all</a></div>
        <div class="table-responsive"><table class="table-clean">
          <colgroup>
            <col style="width:56%;"><col style="width:20%;"><col style="width:24%;">
          </colgroup>
          <thead><tr><th>Partner</th><th class="col-num">Events</th><th class="col-num">Revenue</th></tr></thead>
          <tbody>
          <?php if ($topPartners && $topPartners->num_rows): while ($tp = $topPartners->fetch_assoc()):
              $pname = trim($tp['fname'].' '.$tp['lname']) ?: 'Partner';
              $pinit = strtoupper(substr($tp['fname'] ?: 'P',0,1).substr($tp['lname'] ?: 'T',0,1));
          ?>
            <tr>
              <td class="trunc-cell"><div class="row-thumb"><div class="avatar-sm" style="border-radius:50%;"><?php echo $pinit; ?></div><div style="min-width:0;"><div class="cell-title trunc" title="<?php echo htmlspecialchars($pname); ?>"><?php echo htmlspecialchars($pname); ?></div><div class="cell-sub trunc"><?php echo htmlspecialchars($tp['city'] ?: ''); ?></div></div></div></td>
              <td class="col-num"><?php echo (int)$tp['events_cnt']; ?></td>
              <td class="cell-title col-num"><?php echo fmt_inr_lakh($tp['revenue']); ?></td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="3" class="cell-sub text-center">No partner revenue yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table></div>
      </div></div>

      <div class="col-lg-4"><div class="panel-card h-100">
        <div class="head"><div><h5>Support Tickets</h5><div class="sub">Needs a response</div></div>
          <a class="link-more" href="/support">Inbox</a></div>
        <?php if ($ticketFeed && $ticketFeed->num_rows): while ($t = $ticketFeed->fetch_assoc()):
            $dot = $t['priority']==='high' ? 'var(--pink)' : ($t['priority']==='normal' ? 'var(--yellow)' : 'var(--blue)');
        ?>
          <div class="ticket-row">
            <div class="tkt-dot" style="background:<?php echo $dot; ?>;"></div>
            <div class="min-w-0">
              <div class="cell-title trunc" title="<?php echo htmlspecialchars($t['subject']); ?>"><?php echo htmlspecialchars($t['subject']); ?></div>
              <div class="cell-sub trunc"><?php echo htmlspecialchars(trim($t['fname'].' '.$t['lname']) ?: 'User'); ?> &middot; <?php echo time_ago($t['created_at']); ?></div>
            </div>
          </div>
        <?php endwhile; else: ?>
          <div class="cell-sub text-center py-3">Inbox is clear.</div>
        <?php endif; ?>
      </div></div>

      <div class="col-lg-3"><div class="panel-card h-100">
        <div class="head"><div><h5>Payout Queue</h5><div class="sub">Pending disbursal</div></div>
          <?php if ($payoutQueueCount>0): ?><span class="badge-count" style="font-size:.68rem;"><?php echo $payoutQueueCount; ?></span><?php endif; ?></div>
        <?php if ($payoutQueue && $payoutQueue->num_rows): while ($pq = $payoutQueue->fetch_assoc()):
            $pqBadge = ['pending'=>'badge-pending','processing'=>'badge-draft'][$pq['status']] ?? 'badge-pending';
            $pqName = trim(($pq['fname'] ?? '').' '.($pq['lname'] ?? '')) ?: 'Partner';
        ?>
          <div class="ticket-row">
            <div class="tkt-dot" style="background:var(--pink);"></div>
            <div class="min-w-0">
              <div class="cell-title"><?php echo format_inr((float)$pq['amount']); ?> <span class="badge-pill <?php echo $pqBadge; ?>" style="margin-left:.3rem;"><?php echo ucfirst($pq['status']); ?></span></div>
              <div class="cell-sub trunc" title="<?php echo htmlspecialchars($pqName . ' — ' . ($pq['event_title'] ?: '')); ?>"><?php echo htmlspecialchars($pqName); ?> &middot; <?php echo htmlspecialchars($pq['event_title'] ?: '—'); ?></div>
            </div>
          </div>
        <?php endwhile; else: ?>
          <div class="cell-sub text-center py-3">Nothing waiting on payout.</div>
        <?php endif; ?>
        <?php if ($payoutQueueTotal > 0): ?>
          <div class="cell-sub mt-2">Total waiting: <strong style="color:var(--text);"><?php echo format_inr($payoutQueueTotal); ?></strong></div>
        <?php endif; ?>
        <a href="/payouts" class="link-more d-inline-block mt-2">Process payouts <i class="fa-solid fa-arrow-right"></i></a>
      </div></div>
    </div>

    <script>
      var pink='#ff3d68', orange='#ff7a45', yellow='#ffc94d', blue='#4d9fff', muted='#6f6f78', line='#26262c';
      var ctx = document.getElementById('revenueChart').getContext('2d');
      var grad = ctx.createLinearGradient(0,0,0,220);
      grad.addColorStop(0, 'rgba(255,61,104,.35)'); grad.addColorStop(1, 'rgba(255,61,104,0)');
      new Chart(ctx, { type:'line', data:{ labels: <?php echo json_encode($chartLabels); ?>,
        datasets:[{ label:'Revenue', data: <?php echo json_encode($chartRevenue); ?>, borderColor: pink, borderWidth:3, tension:.4, fill:true, backgroundColor: grad, pointRadius:3, pointBackgroundColor: pink, pointHoverRadius:6, pointHoverBackgroundColor:pink, pointHoverBorderColor:'#fff', pointHoverBorderWidth:2 }] },
        options:{
          interaction:{ mode:'index', intersect:false },
          plugins:{
            legend:{display:false},
            tooltip:{
              backgroundColor:'#1a1a1f', borderColor:line, borderWidth:1, padding:10,
              titleFont:{family:'Inter',weight:'700'}, bodyFont:{family:'Inter'},
              callbacks:{ label:function(ctx){ return 'Revenue: ₹' + ctx.parsed.y + 'L'; } }
            }
          },
          scales:{ x:{grid:{color:'transparent'},ticks:{color:muted,font:{size:11,family:'Inter'}}}, y:{grid:{color:line},ticks:{color:muted,font:{size:11,family:'Inter'},callback:function(v){return '₹'+v+'L';}}} }
        } });
      new Chart(document.getElementById('categoryChart').getContext('2d'), { type:'doughnut',
        data:{ labels: <?php echo json_encode($catLabels); ?>, datasets:[{ data: <?php echo json_encode($catData); ?>, backgroundColor:[pink,orange,yellow,blue], borderWidth:0, hoverOffset:10, hoverBorderColor:'#fff', hoverBorderWidth:2 }] },
        options:{
          cutout:'70%',
          plugins:{
            legend:{display:false},
            tooltip:{
              backgroundColor:'#1a1a1f', borderColor:line, borderWidth:1, padding:10,
              titleFont:{family:'Inter',weight:'700'}, bodyFont:{family:'Inter'},
              callbacks:{ label:function(ctx){ var total = ctx.dataset.data.reduce(function(a,b){return a+b;},0); var pct = total ? Math.round(ctx.parsed/total*100) : 0; return ' ' + ctx.label + ': ' + ctx.parsed + ' bookings (' + pct + '%)'; } }
            }
          }
        } });
    </script>

<?php

} else {
    /* ===================== PARTNER ===================== */

    $totalEvents  = (int) q_one($conn, "SELECT COUNT(*) FROM events WHERE user_id = '$userId'");
    $liveEvents   = (int) q_one($conn, "SELECT COUNT(*) FROM events WHERE user_id = '$userId' AND status = 1");
    $ticketsSold  = (int) q_one($conn, "SELECT COUNT(*) FROM payments p INNER JOIN events e ON p.event_id=e.url WHERE e.user_id='$userId' AND " . range_clause('p.created_at', $rangeDays));
    $totalRevenue = (float) q_one($conn, "SELECT COALESCE(SUM(p.price),0) FROM payments p INNER JOIN events e ON p.event_id=e.url WHERE e.user_id='$userId' AND " . range_clause('p.created_at', $rangeDays));
    $pendingApprovals = (int) q_one($conn, "SELECT COUNT(*) FROM events WHERE user_id='$userId' AND status = 4");

    // Available balance + eligibility, shared with the /payouts page so the two numbers always agree.
    $payoutSettings    = get_payout_settings($conn);
    $payoutEligibility = partner_payout_eligibility($conn, $userId, $payoutSettings);
    $availableBalance  = (float) $payoutEligibility['total_pending'];

    // Recent settlements for the "Payout History" card (most recent 4 — full list lives on /payouts)
    $recentPayouts = $conn->query("
        SELECT py.amount, py.status, py.requested_at, e.tittle AS event_title
        FROM payouts py
        LEFT JOIN events e ON e.id = py.event_id
        WHERE py.user_id = '$userId'
        ORDER BY py.requested_at DESC LIMIT 4
    ");

    $planChip = 'Partner';
    $userRow2 = $conn->query("SELECT status FROM users WHERE id = $userId")->fetch_assoc();

    // My events table (most recent overall — not range-scoped, this is a "manage your listings" view)
    $myEvents = $conn->query("
SELECT 
    e.tittle,
    e.image,
    e.start,
    e.status,
    e.url,
    (
        SELECT COUNT(*)
        FROM payments p
        WHERE p.event_id = e.url
    ) AS sold
FROM events e
WHERE e.user_id = '$userId'
ORDER BY e.id DESC
LIMIT 5;
    ");

    // Recent bookings, scoped to the selected range
    $recentBookings = $conn->query("
        SELECT p.pay_ref, p.name, p.price, p.created_at, e.tittle
        FROM payments p INNER JOIN events e ON p.event_id = e.url
        WHERE e.user_id = '$userId' AND " . range_clause('p.created_at', $rangeDays) . "
        ORDER BY p.created_at DESC LIMIT 4
    ");

    // Live check-in for most recent live event (current status — not range-scoped)
    $liveEvent = $conn->query("SELECT id, tittle, url FROM events WHERE user_id='$userId' AND status=1 ORDER BY start ASC LIMIT 1")->fetch_assoc();
    $checkinRows = null; $checkinTotal = 0; $checkinDone = 0;
    if ($liveEvent) {
        $eurl = $conn->real_escape_string($liveEvent['url']);
        $checkinRows = $conn->query("
            SELECT bg.guest_name, bg.guest_gender, bg.checked_in, bg.checked_in_at, bg.checked_in_gate
            FROM booking_guests bg INNER JOIN payments p ON p.pay_ref = bg.pay_ref
            WHERE p.event_id = '$eurl' AND bg.checked_in = 1
            ORDER BY bg.checked_in_at DESC LIMIT 4
        ");
        $checkinTotal = (int) q_one($conn, "SELECT COUNT(*) FROM booking_guests bg INNER JOIN payments p ON p.pay_ref=bg.pay_ref WHERE p.event_id='$eurl'");
        $checkinDone  = (int) q_one($conn, "SELECT COUNT(*) FROM booking_guests bg INNER JOIN payments p ON p.pay_ref=bg.pay_ref WHERE p.event_id='$eurl' AND bg.checked_in=1");
        if ($checkinTotal === 0) { // fall back to payments-level count
            $checkinTotal = (int) q_one($conn, "SELECT COUNT(*) FROM payments WHERE event_id='$eurl'");
            $checkinDone  = (int) q_one($conn, "SELECT COUNT(*) FROM payments WHERE event_id='$eurl' AND checked_in=1");
        }
    }
    ?>

    <div class="welcome-banner">
      <div>
        <span class="plan-chip"><i class="fa-solid fa-crown"></i> <?php echo htmlspecialchars($planChip); ?></span>
        <h3>Welcome back, <?php echo htmlspecialchars($displayName); ?></h3>
        <p>You have <?php echo $liveEvents; ?> event<?php echo $liveEvents==1?'':'s'; ?> live<?php echo $pendingApprovals>0 ? ", $pendingApprovals pending approval" : ''; ?>. Let's make this week count.</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <a href="#" class="btn-pink-sm js-scan-ticket" style="background:var(--panel-2);border:1px solid var(--line);color:var(--text);"><i class="fa-solid fa-qrcode"></i> Scan Tickets</a>
        <a href="#" class="btn-pink-sm js-add-event"><i class="fa-solid fa-plus"></i> Create New Event</a>
      </div>
    </div>

    <div class="page-head">
      <div><h2>Overview</h2><p>Performance summary, <?php echo $rangeLabelText; ?></p></div>
      <?php echo range_tabs_html($range); ?>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-6 col-xl-3"><div class="stat-card">
        <div class="ic-wrap" style="background:linear-gradient(135deg,var(--pink),var(--orange));"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="val"><?php echo number_format($totalEvents); ?></div><div class="lbl">Total Events</div>
        <div class="delta up"><i class="fa-solid fa-arrow-trend-up"></i> <?php echo $liveEvents; ?> live now</div>
      </div></div>
      <div class="col-6 col-xl-3"><div class="stat-card">
        <div class="ic-wrap" style="background:linear-gradient(135deg,#4d9fff,#7b6bff);"><i class="fa-solid fa-ticket"></i></div>
        <div class="val"><?php echo number_format($ticketsSold); ?></div><div class="lbl">Tickets Sold</div>
        <div class="delta" style="color:var(--muted);font-size:.72rem;"><?php echo ucfirst($rangeLabelText); ?></div>
      </div></div>
      <div class="col-6 col-xl-3"><div class="stat-card">
        <div class="ic-wrap" style="background:linear-gradient(135deg,var(--green),#1fb673);"><i class="fa-solid fa-indian-rupee-sign"></i></div>
        <div class="val"><?php echo fmt_inr_lakh($totalRevenue); ?></div><div class="lbl">Total Revenue</div>
        <div class="delta" style="color:var(--muted);font-size:.72rem;"><?php echo ucfirst($rangeLabelText); ?></div>
      </div></div>
      <div class="col-6 col-xl-3"><div class="stat-card">
        <div class="ic-wrap" style="background:linear-gradient(135deg,var(--yellow),var(--orange));"><i class="fa-solid fa-hourglass-half"></i></div>
        <div class="val"><?php echo number_format($pendingApprovals); ?></div><div class="lbl">Pending Approval</div>
      </div></div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-lg-8"><div class="panel-card h-100">
        <div class="head"><div><h5>Revenue Trend</h5><div class="sub">Your earnings, <?php echo $rangeLabelText; ?></div></div>
          <a class="link-more" href="/analytics">Full analytics <i class="fa-solid fa-arrow-right"></i></a></div>
        <canvas id="revenueChart" height="110"></canvas>
      </div></div>
      <div class="col-lg-4"><div class="wallet-card">
        <div style="color:var(--muted);font-size:.8rem;font-weight:600;">Available Balance</div>
        <div class="amt"><?php echo format_inr($availableBalance); ?></div>
        <a href="/payouts" class="btn-pink-sm w-100 justify-content-center mb-3"><i class="fa-solid fa-wallet"></i> Withdraw Funds</a>
        <?php if (!$payoutEligibility['can_request'] && !empty($payoutEligibility['blockers'])): ?>
          <div class="cell-sub text-center"><?php echo htmlspecialchars($payoutEligibility['blockers'][0]); ?></div>
        <?php else: ?>
          <div class="cell-sub text-center">Ready to withdraw, across all your events</div>
        <?php endif; ?>
      </div></div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-lg-7"><div class="panel-card h-100">
        <div class="head"><div><h5>My Events</h5><div class="sub">Manage your upcoming and live listings</div></div>
          <a class="link-more" href="/events/">Event list <i class="fa-solid fa-arrow-right"></i></a></div>
        <div class="table-responsive"><table class="table-clean">
          <colgroup>
            <col style="width:40%;"><col style="width:16%;"><col style="width:18%;"><col style="width:10%;"><col style="width:16%;">
          </colgroup>
          <thead><tr><th>Event</th><th>Date</th><th class="col-center">Status</th><th class="col-num">Sold</th><th class="col-center"></th></tr></thead>
          <tbody>
          <?php if ($myEvents && $myEvents->num_rows): while ($ev = $myEvents->fetch_assoc()):
              $badge='badge-past'; $label='Past'; $action='Report';
              if ((int)$ev['status']===1){$badge='badge-live';$label='Live';$action='Manage';}
              elseif((int)$ev['status']===4){$badge='badge-pending';$label='Pending Approval';$action='Edit';}
              elseif((int)$ev['status']===2){$badge='badge-draft';$label='Draft';$action='Continue';}
          ?>
            <tr>
              <td class="trunc-cell">
                <div class="row-thumb">
                  <img class="thumb" src="<?php echo htmlspecialchars($ev['image']); ?>" alt="">
                  <a class="cell-title trunc" href="/events/single-event?id=<?php echo urlencode($ev['url']); ?>" style="color:inherit;text-decoration:none;" title="<?php echo htmlspecialchars($ev['tittle']); ?>"><?php echo htmlspecialchars($ev['tittle']); ?></a>
                </div>
              </td>
              <td class="cell-sub"><?php echo date('d M', strtotime($ev['start'])); ?></td>
              <td class="col-center"><span class="badge-pill <?php echo $badge; ?>"><?php echo $label; ?></span></td>
              <td class="col-num"><?php echo (int)$ev['sold']; ?></td>
              <td class="col-center"><a class="btn-mini" href="/events/single-event?id=<?php echo htmlspecialchars($ev['url']); ?>"><?php echo $action; ?></a></td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="5" class="cell-sub text-center">No events yet — create your first one.</td></tr>
          <?php endif; ?>
          </tbody>
        </table></div>
      </div></div>

      <div class="col-lg-5">
        <div class="panel-card h-100">
          <div class="head"><div><h5>Active Promotions</h5><div class="sub">Boost your event reach</div></div><a class="link-more" href="/promotion">Buy plan</a></div>
          <div class="cell-sub text-center py-3">No active promotions right now.</div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <!-- Coupons & Offers hidden for now (in progress) -->
      <div class="col-lg-12"><div class="panel-card h-100">
        <div class="head">
          <div><h5>Live Check-In Activity <?php if($liveEvent): ?><span class="badge-pill badge-live ms-1"><?php echo htmlspecialchars($liveEvent['tittle']); ?></span><?php endif; ?></h5>
          <div class="sub"><?php echo $checkinDone; ?> of <?php echo $checkinTotal; ?> ticket holders checked in</div></div>
          <a class="link-more js-scan-ticket" href="#"><i class="fa-solid fa-qrcode"></i> Open scanner</a>
        </div>
        <div class="progress-thin mb-3"><span style="width:<?php echo $checkinTotal>0 ? round($checkinDone/$checkinTotal*100) : 0; ?>%;"></span></div>
        <div class="table-responsive"><table class="table-clean">
          <colgroup>
            <col style="width:55%;"><col style="width:25%;"><col style="width:20%;">
          </colgroup>
          <thead><tr><th>Attendee</th><th class="col-center">Checked In</th><th class="col-center">Gate</th></tr></thead>
          <tbody>
          <?php if ($checkinRows && $checkinRows->num_rows): while ($c = $checkinRows->fetch_assoc()): ?>
            <tr><td class="cell-title trunc-cell"><span class="trunc" title="<?php echo htmlspecialchars($c['guest_name'] ?: 'Guest'); ?>"><?php echo htmlspecialchars($c['guest_name'] ?: 'Guest'); ?></span></td>
                <td class="cell-sub col-center"><?php echo $c['checked_in_at'] ? date('g:i A', strtotime($c['checked_in_at'])) : '—'; ?></td>
                <td class="col-center"><?php echo htmlspecialchars($c['checked_in_gate'] ?: '—'); ?></td></tr>
          <?php endwhile; else: ?>
            <tr><td colspan="3" class="cell-sub text-center">No check-ins yet. Open the scanner to start.</td></tr>
          <?php endif; ?>
          </tbody>
        </table></div>
      </div></div>
    </div>

    <div class="row g-3">
      <div class="col-lg-7"><div class="panel-card h-100">
        <div class="head"><div><h5>Recent Bookings</h5><div class="sub"><?php echo ucfirst($rangeLabelText); ?></div></div><a class="link-more" href="/bookings">All bookings</a></div>
        <div class="table-responsive"><table class="table-clean">
          <colgroup>
            <col style="width:18%;"><col style="width:28%;"><col style="width:34%;"><col style="width:20%;">
          </colgroup>
          <thead><tr><th>Booking ID</th><th>Customer</th><th>Event</th><th class="col-num">Amount</th></tr></thead>
          <tbody>
          <?php if ($recentBookings && $recentBookings->num_rows): while ($b = $recentBookings->fetch_assoc()): ?>
            <tr>
              <td class="cell-sub trunc-cell"><span class="trunc">#<?php echo htmlspecialchars($b['pay_ref'] ?: 'HS-'.rand(1000,9999)); ?></span></td>
              <td class="cell-title trunc-cell"><span class="trunc" title="<?php echo htmlspecialchars($b['name'] ?: 'Guest'); ?>"><?php echo htmlspecialchars($b['name'] ?: 'Guest'); ?></span></td>
              <td class="trunc-cell"><span class="trunc" title="<?php echo htmlspecialchars($b['tittle']); ?>"><?php echo htmlspecialchars($b['tittle']); ?></span></td>
              <td class="cell-title col-num">₹<?php echo number_format((float)$b['price']); ?></td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="4" class="cell-sub text-center">No bookings in this period.</td></tr>
          <?php endif; ?>
          </tbody>
        </table></div>
      </div></div>

      <div class="col-lg-5"><div class="panel-card h-100">
        <div class="head"><div><h5>Payout History</h5><div class="sub">Settlements</div></div><a class="link-more" href="/payouts">View all</a></div>
        <?php if ($recentPayouts && $recentPayouts->num_rows): while ($py = $recentPayouts->fetch_assoc()):
            $pyBadge = ['pending'=>'badge-pending','processing'=>'badge-draft','paid'=>'badge-live','failed'=>'badge-past'][$py['status']] ?? 'badge-pending';
        ?>
          <div class="ticket-row">
            <div class="tkt-dot" style="background:var(--pink);"></div>
            <div class="min-w-0">
              <div class="cell-title"><?php echo format_inr((float)$py['amount']); ?> <span class="badge-pill <?php echo $pyBadge; ?>" style="margin-left:.3rem;"><?php echo ucfirst($py['status']); ?></span></div>
              <div class="cell-sub trunc" title="<?php echo htmlspecialchars($py['event_title'] ?: ''); ?>"><?php echo htmlspecialchars($py['event_title'] ?: '—'); ?> &middot; <?php echo time_ago($py['requested_at']); ?></div>
            </div>
          </div>
        <?php endwhile; else: ?>
          <div class="cell-sub text-center py-3">No payout requests yet.</div>
        <?php endif; ?>
        <a href="/payouts" class="link-more d-inline-block mt-2">View full payout history <i class="fa-solid fa-arrow-right"></i></a>
      </div></div>
    </div>

    <script>
      var pink='#ff3d68', muted='#6f6f78', line='#26262c';
      var ctx = document.getElementById('revenueChart').getContext('2d');
      var grad = ctx.createLinearGradient(0,0,0,220);
      grad.addColorStop(0, 'rgba(255,61,104,.35)'); grad.addColorStop(1, 'rgba(255,61,104,0)');
      new Chart(ctx, { type:'line', data:{ labels: <?php echo json_encode($chartLabels); ?>,
        datasets:[{ label:'Revenue', data: <?php echo json_encode($chartRevenue); ?>, borderColor: pink, borderWidth:3, tension:.4, fill:true, backgroundColor: grad, pointRadius:3, pointBackgroundColor: pink, pointHoverRadius:6, pointHoverBackgroundColor:pink, pointHoverBorderColor:'#fff', pointHoverBorderWidth:2 }] },
        options:{
          interaction:{ mode:'index', intersect:false },
          plugins:{
            legend:{display:false},
            tooltip:{
              backgroundColor:'#1a1a1f', borderColor:line, borderWidth:1, padding:10,
              titleFont:{family:'Inter',weight:'700'}, bodyFont:{family:'Inter'},
              callbacks:{ label:function(ctx){ return 'Revenue: ₹' + ctx.parsed.y + 'L'; } }
            }
          },
          scales:{ x:{grid:{color:'transparent'},ticks:{color:muted,font:{size:11,family:'Inter'}}}, y:{grid:{color:line},ticks:{color:muted,font:{size:11,family:'Inter'},callback:function(v){return '₹'+v+'L';}}} }
        } });
    </script>

<?php
}

include __DIR__ . '/include/footer.php';