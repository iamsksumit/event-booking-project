<?php
ob_start();
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';

$pageTitle  = "Event Details";
$activePage = 'events-list';

include __DIR__ . '/../include/header.php';
include __DIR__ . '/../include/events-ui.php';

if (!isset($_REQUEST['id']) || trim($_REQUEST['id']) === '') {
    echo '<div class="panel-card"><p>No event specified.</p></div>';
    include __DIR__ . '/../include/footer.php';
    exit();
}
$id = $_REQUEST['id'];

$stmt = $conn->prepare("
    SELECT
        events.id AS eventidd, events.url, events.tittle, events.banner_status,
        events.sub_tittle, events.status, events.ticketing_type, events.views_count,
        events.description, events.term, events.video, events.Organised,
        events.special_detail, events.image, events.start, events.end, events.user_id,
        place.address AS placeadd, place.name AS pname
    FROM events
    LEFT JOIN place ON events.sub_tittle = place.id
    WHERE events.url = ?
    LIMIT 1");
$stmt->bind_param('s', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    echo '<div class="panel-card"><p>Event not found.</p></div>';
    include __DIR__ . '/../include/footer.php';
    exit();
}

/* Partners may only view their own events */
if ($role === 'partner' && (int) $row['user_id'] !== $userId) {
    header("Location: index.php");
    exit();
}

$event_id       = (int) $row['eventidd'];
$ticketing_type = $row['ticketing_type'];
$bannerstatus   = (int) $row['banner_status'];
$status         = (int) $row['status'];
$urlsss         = $row['url'];

/* Event status values, used throughout this page and the dashboard/approval
   queue:
     0 = Past / Archived
     1 = Published / Live
     2 = Draft
     3 = Unapproved
     4 = Submitted for Approval
*/
$isEventLive     = $status === 1;
// The Disable/Enable toggle only makes sense between Live (1) and
// Archived (0) -- Draft, Unapproved and Submitted-for-Approval are handled
// by their own flows (Edit for drafts, the approval queue for the rest),
// so the toggle button is hidden outside that pair.
$canToggleStatus = in_array($status, [0, 1], true);

/* Same-day rule for denying entry -- matches api/deny_ticket.php's grace window. */
const DENIAL_GRACE_HOURS_UI = 12;
$eventDay      = date('Y-m-d', strtotime($row['start']));
$denialWindow  = time() >= strtotime($eventDay) && time() <= strtotime($eventDay . ' +1 day +' . DENIAL_GRACE_HOURS_UI . ' hours');

/* ===================== attendee contact-info visibility =====================
   Admins can always see phone/email. Whether partners can see it too is a
   site setting (Settings -> General -> "Show Attendee Contact Info to
   Partners"), stored in the same key/value `settings` table as everything
   else on that page. Defaults to hidden for partners until an admin turns
   it on. */
$contactSettingRow   = $conn->query("SELECT value FROM settings WHERE name = 'partner_view_contact_details' LIMIT 1")->fetch_assoc();
$partnersCanSeeContact = ($contactSettingRow['value'] ?? '0') === '1';
$canViewContact = $role === 'admin' || $partnersCanSeeContact;

/* ===================== deny entry (from the attendee list below) ===================== */
$denyFormError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deny_guest'])) {
    $denyType   = ($_POST['deny_type'] ?? '') === 'payment' ? 'payment' : 'guest';
    $denyId     = (int) ($_POST['deny_id'] ?? 0);
    $denyReason = trim((string) ($_POST['deny_reason'] ?? ''));

    if (!$denialWindow) {
        $denyFormError = 'Entry can only be denied on the event\'s own day.';
    } elseif ($denyId <= 0 || $denyReason === '') {
        $denyFormError = 'Please give a reason for denying entry.';
    } else {
        if ($denyType === 'guest') {
            $chk = $conn->prepare("
                SELECT bg.id FROM booking_guests bg
                INNER JOIN payments p ON p.pay_ref = bg.pay_ref
                WHERE bg.id = ? AND p.event_id = ? LIMIT 1
            ");
            $chk->bind_param('is', $denyId, $urlsss);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) {
                $u = $conn->prepare("
                    UPDATE booking_guests
                    SET denied = 1, denied_reason = ?, denied_at = NOW(), denied_by = ?,
                        checked_in = 0, checked_in_at = NULL
                    WHERE id = ?
                ");
                $u->bind_param('sii', $denyReason, $userId, $denyId);
                $u->execute();
            } else {
                $denyFormError = 'Attendee not found for this event.';
            }
        } else {
            $chk = $conn->prepare("SELECT id FROM payments WHERE id = ? AND event_id = ? LIMIT 1");
            $chk->bind_param('is', $denyId, $urlsss);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) {
                $u = $conn->prepare("
                    UPDATE payments
                    SET denied = 1, denied_reason = ?, denied_at = NOW(), denied_by = ?,
                        checked_in = 0, checked_in_at = NULL
                    WHERE id = ?
                ");
                $u->bind_param('sii', $denyReason, $userId, $denyId);
                $u->execute();
            } else {
                $denyFormError = 'Attendee not found for this event.';
            }
        }
        if (!$denyFormError) {
            header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?id=" . urlencode($id));
            exit();
        }
    }
}

/* ===================== short link ===================== */
$actual_link = "https://highspinn.com/events/";
$long_url    = $actual_link . $urlsss;
$shortenedUrl = $long_url; // sensible fallback if the shortener API is unreachable

$ch = curl_init("https://highspinn.com/hsplnn/shorten_api.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 4);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["long_url" => $long_url]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
$response = curl_exec($ch);
curl_close($ch);
$data = json_decode((string) $response, true);
if (!empty($data['short_url'])) {
    $shortenedUrl = $data['short_url'];
}

/* ===================== views ===================== */
$vStmt = $conn->prepare("SELECT SUM(views) AS total_views FROM views WHERE event_id = ?");
$vStmt->bind_param('i', $event_id);
$vStmt->execute();
$vRow = $vStmt->get_result()->fetch_assoc();
$totalViews = (int) ($vRow['total_views'] ?? 0);

/* ===================== attendees / check-ins ===================== */
/* Per-attendee rows when booking_guests has them for this event; falls back
   to one row per payment for older bookings made before booking_guests
   existed (same fallback pattern as api/scan_ticket.php). */
$gStmt = $conn->prepare("
    SELECT bg.id, bg.guest_name, bg.guest_email, bg.guest_gender,
           bg.checked_in, bg.checked_in_at, bg.checked_in_gate,
           bg.denied, bg.denied_reason, bg.denied_at
    FROM booking_guests bg
    INNER JOIN payments p ON p.pay_ref = bg.pay_ref
    WHERE p.event_id = ?
    ORDER BY bg.checked_in DESC, bg.checked_in_at DESC, bg.guest_name ASC
");
$gStmt->bind_param('s', $urlsss);
$gStmt->execute();
$attendees = $gStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$attendeeType = 'guest';

if (!$attendees) {
    $attendeeType = 'payment';
    $pStmt = $conn->prepare("
        SELECT id, name AS guest_name, email AS guest_email, gender AS guest_gender,
               checked_in, checked_in_at, checked_in_gate,
               denied, denied_reason, denied_at
        FROM payments
        WHERE event_id = ?
        ORDER BY checked_in DESC, checked_in_at DESC, name ASC
    ");
    $pStmt->bind_param('s', $urlsss);
    $pStmt->execute();
    $attendees = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$attendeeCheckedIn = count(array_filter($attendees, fn($a) => (int) $a['checked_in'] === 1));
$attendeeDenied    = count(array_filter($attendees, fn($a) => (int) $a['denied'] === 1));

/* ===================== actions ===================== */
if (isset($_POST['toggle_banner']) && $role === 'admin') {
    $newStatus = $bannerstatus == 1 ? 0 : 1;
    $u = $conn->prepare("UPDATE events SET banner_status = ? WHERE url = ?");
    $u->bind_param('is', $newStatus, $id);
    if ($u->execute()) {
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?id=" . urlencode($id));
        exit();
    }
}

/* Enable/Disable toggle.
   - Disabling (1 -> 0) is always immediate for both roles: taking an event
     offline needs no one's approval.
   - Re-enabling is where the two roles differ:
       admin   : 0 -> 1, published immediately (admins can always publish directly).
       partner : 0 -> 4, sent to "Submitted for Approval" instead of going
                 straight back to live -- it re-enters the same approval
                 queue a brand-new event would, and only an admin's
                 approve/reject action (events/event-approval) can move it
                 to 1 from there. */
if (isset($_GET['toggle'])) {
    $toggleId = (int) $_GET['toggle'];

    if ($role === 'admin') {
        $u = $conn->prepare("UPDATE events SET status = IF(status = 1, 0, 1) WHERE id = ?");
        $u->bind_param('i', $toggleId);
    } else {
        $u = $conn->prepare("UPDATE events SET status = IF(status = 1, 0, 4) WHERE id = ? AND user_id = ?");
        $u->bind_param('ii', $toggleId, $userId);
    }
    $u->execute();
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?id=" . urlencode($id));
    exit();
}

if (isset($_GET['delete'])) {
    $delId = (int) $_GET['delete'];
    $u = $conn->prepare("DELETE FROM events WHERE id = ?" . ($role === 'partner' ? " AND user_id = ?" : ""));
    if ($role === 'partner') { $u->bind_param('ii', $delId, $userId); } else { $u->bind_param('i', $delId); }
    $u->execute();
    echo "<script>alert('Event deleted successfully'); window.location='index.php';</script>";
    exit();
}

/* ===================== CSV export ===================== */
if (isset($_POST['export_csv'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="payments_' . preg_replace('/[^A-Za-z0-9_-]/', '', $urlsss) . '.csv"');
    ob_clean();
    flush();
    $output = fopen('php://output', 'w');

    $tStmt = $conn->prepare("SELECT DISTINCT ticket_name FROM event_tickets WHERE event_id = ?");
    $tStmt->bind_param('i', $event_id);
    $tStmt->execute();
    $ticket_names = [];
    $tRes = $tStmt->get_result();
    while ($t = $tRes->fetch_assoc()) { $ticket_names[] = $t['ticket_name']; }

    $csvHeader = ['Date', 'Name', 'Price', 'Order Id'];
    if ($canViewContact) { $csvHeader[] = 'Email'; $csvHeader[] = 'Phone'; }
    $csvHeader[] = 'Payment Id';
    fputcsv($output, array_merge($csvHeader, $ticket_names));

    $pStmt = $conn->prepare("
        SELECT id, name, price, created_at, pay_ref, payment_id, email, phone
        FROM payments WHERE event_id = ? ORDER BY created_at DESC");
    $pStmt->bind_param('s', $urlsss);
    $pStmt->execute();
    $pRes = $pStmt->get_result();

    $qtyStmt = $conn->prepare("SELECT ticket_name, quantity FROM ticket_orders WHERE payment_ref = ?");

    while ($p = $pRes->fetch_assoc()) {
        $line = [
            date('d-m-Y', strtotime($p['created_at'])),
            $p['name'], $p['price'], $p['pay_ref'],
        ];
        if ($canViewContact) { $line[] = $p['email']; $line[] = $p['phone']; }
        $line[] = $p['payment_id'];

        $qty = array_fill_keys($ticket_names, 0);
        $qtyStmt->bind_param('s', $p['pay_ref']);
        $qtyStmt->execute();
        $qRes = $qtyStmt->get_result();
        while ($q = $qRes->fetch_assoc()) { $qty[$q['ticket_name']] = $q['quantity']; }
        fputcsv($output, array_merge($line, $qty));
    }
    fclose($output);
    exit();
}
?>

<div class="page-head">
  <div><h2><?php echo h($row['tittle']); ?></h2><p>Event details, short link and booking export.</p></div>
  <?php echo event_status_badge($status); ?>
</div>

<div class="panel-card mb-3">
    <div class="detail-hero">
        <div class="detail-hero-media">
            <img class="detail-image" src="<?php echo h($row['image']); ?>" alt="<?php echo h($row['tittle']); ?>">
        </div>
        <div class="detail-hero-info">
            <div class="detail-stat-row"><i class="fa-solid fa-location-dot"></i><span><?php echo h($row['pname'] ?? '—'); ?></span></div>
            <div class="detail-stat-row"><i class="fa-solid fa-calendar"></i><span><?php echo h(fmt_event_date($row['start'])); ?> &rarr; <?php echo h(fmt_event_date($row['end'])); ?></span></div>
            <div class="detail-stat-row"><i class="fa-solid fa-eye"></i><span><?php echo number_format($totalViews); ?>+ total views</span></div>
            <div class="detail-stat-row"><i class="fa-solid fa-ticket"></i><span><?php echo $ticketing_type === 'ticket' ? 'Paid ticketing' : 'RSVP / guestlist'; ?></span></div>

            <div class="detail-actions">
                <a href="duplicate-event?id=<?php echo urlencode($id); ?>" class="btn-mini"><i class="fa-solid fa-copy"></i> Duplicate</a>
                <a href="edit-event?type=<?php echo h($ticketing_type); ?>&id=<?php echo urlencode($id); ?>" class="btn-mini"><i class="fa-solid fa-pen"></i> Edit</a>

                <?php if ($canToggleStatus): ?>
                    <?php if ($isEventLive): ?>
                        <button type="button" class="btn-mini" onclick="if(confirm('Disable this event? It will stop showing as live.')) window.location='?toggle=<?php echo $event_id; ?>&id=<?php echo urlencode($id); ?>';"><i class="fa-solid fa-ban"></i> Disable</button>
                    <?php elseif ($role === 'admin'): ?>
                        <button type="button" class="btn-mini approve" onclick="if(confirm('Enable this event? It will go live again.')) window.location='?toggle=<?php echo $event_id; ?>&id=<?php echo urlencode($id); ?>';"><i class="fa-solid fa-circle-check"></i> Enable</button>
                    <?php else: ?>
                        <button type="button" class="btn-mini approve" onclick="if(confirm('Re-enabling sends this event back for admin approval before it goes live again. Continue?')) window.location='?toggle=<?php echo $event_id; ?>&id=<?php echo urlencode($id); ?>';"><i class="fa-solid fa-paper-plane"></i> Submit for Approval</button>
                    <?php endif; ?>
                <?php endif; ?>

                <button type="button" class="btn-mini reject" onclick="if(confirm('Delete this event permanently? This cannot be undone.')) window.location='?delete=<?php echo $event_id; ?>&id=<?php echo urlencode($id); ?>';"><i class="fa-solid fa-trash"></i> Delete</button>
                <?php if ($role === 'admin'): ?>
                <form method="POST" class="d-inline">
                    <?php if ($bannerstatus == 1): ?>
                        <button type="submit" name="toggle_banner" class="btn-mini reject"><i class="fa-solid fa-eye-slash"></i> Disable Banner</button>
                    <?php else: ?>
                        <button type="submit" name="toggle_banner" class="btn-mini approve"><i class="fa-solid fa-eye"></i> Enable Banner</button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>

            <?php if (!$canToggleStatus): ?>
                <div class="cell-sub mt-2">
                    <?php if ($status === 2): ?>
                        This event is still a draft — finish and submit it from Edit.
                    <?php elseif ($status === 4): ?>
                        This event is submitted and waiting on admin approval.
                    <?php elseif ($status === 3): ?>
                        This event was not approved — check with an admin or edit and resubmit.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="panel-card mb-3">
    <div class="head"><h5>Shareable Links</h5></div>
    <div class="ev-field">
        <label class="form-label-c">Long URL</label>
        <div class="copy-field"><input class="form-control-c" type="text" readonly value="<?php echo h($long_url); ?>"></div>
    </div>
    <div class="ev-field mb-0">
        <label class="form-label-c">Short URL</label>
        <div class="copy-field"><input class="form-control-c" type="text" readonly value="<?php echo h($shortenedUrl); ?>"></div>
    </div>
</div>

<!-- ================= ATTENDEES / CHECK-INS ================= -->
<div class="panel-card mb-3">
    <div class="head">
        <div>
            <h5>Attendees</h5>
            <div class="sub">
                <?php echo (int) $attendeeCheckedIn; ?> checked in of <?php echo count($attendees); ?>
                <?php if ($attendeeDenied > 0): ?> &middot; <?php echo (int) $attendeeDenied; ?> denied entry<?php endif; ?>
            </div>
        </div>
        <?php if (!$denialWindow): ?>
            <span class="cell-sub" style="text-align:right;">Denying entry is only available on the event's own day.</span>
        <?php endif; ?>
    </div>

    <?php if ($denyFormError): ?>
        <div class="alert-c alert-danger"><?php echo h($denyFormError); ?></div>
    <?php endif; ?>

    <?php if ($role === 'partner' && !$canViewContact): ?>
        <div class="cell-sub contact-hidden-note"><i class="fa-solid fa-lock"></i> Attendee contact details are hidden for partners. An admin can enable this in Settings &rarr; General.</div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table-clean">
            <thead>
                <tr>
                    <th>Guest</th><th>Contact</th><th>Status</th><th>Checked In</th><th>Gate</th><th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($attendees): foreach ($attendees as $a):
                $isDenied = (int) $a['denied'] === 1;
                $isIn     = (int) $a['checked_in'] === 1;
            ?>
                <tr>
                    <td class="cell-title"><?php echo h($a['guest_name'] ?: 'Guest'); ?></td>
                    <td class="cell-sub">
                        <?php if ($canViewContact): ?>
                            <?php echo h($a['guest_email'] ?: '—'); ?>
                        <?php else: ?>
                            <span class="contact-hidden"><i class="fa-solid fa-lock"></i> Hidden</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isDenied): ?>
                            <span class="badge-pill badge-rejected" title="<?php echo h($a['denied_reason'] ?? ''); ?>">Denied</span>
                        <?php elseif ($isIn): ?>
                            <span class="badge-pill badge-live">Checked In</span>
                        <?php else: ?>
                            <span class="badge-pill badge-pending">Not Arrived</span>
                        <?php endif; ?>
                    </td>
                    <td class="cell-sub"><?php echo $a['checked_in_at'] ? h(date('d M, g:i A', strtotime($a['checked_in_at']))) : '—'; ?></td>
                    <td class="cell-sub"><?php echo h($a['checked_in_gate'] ?: '—'); ?></td>
                    <td style="text-align:right;">
                        <?php if ($isDenied): ?>
                            <span class="cell-sub" title="<?php echo h($a['denied_reason'] ?? ''); ?>">
                                <i class="fa-solid fa-circle-info"></i> Reason on file
                            </span>
                        <?php elseif ($denialWindow): ?>
                            <button type="button" class="btn-mini reject js-deny-guest"
                                    data-deny-type="<?php echo $attendeeType; ?>"
                                    data-deny-id="<?php echo (int) $a['id']; ?>"
                                    data-deny-name="<?php echo h($a['guest_name'] ?: 'this guest'); ?>">
                                <i class="fa-solid fa-ban"></i> Deny Entry
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6" class="cell-sub text-center">No attendees yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ================= DENY ENTRY MODAL ================= -->
<div class="modal fade" id="denyGuestModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="?id=<?php echo urlencode($id); ?>">
        <input type="hidden" name="deny_guest" value="1">
        <input type="hidden" name="deny_type" id="denyTypeInput">
        <input type="hidden" name="deny_id" id="denyIdInput">
        <div class="modal-header">
          <div>
            <h5>Deny entry</h5>
            <div class="cell-sub" id="denyGuestNameLabel">this guest</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <label class="form-label-c" for="deny_reason">Reason</label>
          <textarea class="form-control-c" name="deny_reason" id="deny_reason" rows="3" required placeholder="e.g. duplicate ticket, ID mismatch, altered QR code…"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-mini" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-mini reject"><i class="fa-solid fa-ban"></i> Confirm Denial</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  var denyGuestModalEl = document.getElementById('denyGuestModal');
  var denyGuestModal = denyGuestModalEl ? new bootstrap.Modal(denyGuestModalEl) : null;
  document.querySelectorAll('.js-deny-guest').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('denyTypeInput').value = btn.dataset.denyType;
      document.getElementById('denyIdInput').value = btn.dataset.denyId;
      document.getElementById('denyGuestNameLabel').textContent = btn.dataset.denyName;
      document.getElementById('deny_reason').value = '';
      if (denyGuestModal) { denyGuestModal.show(); }
    });
  });
</script>

<style>
  /* ---------- alert ---------- */
  .alert-c{ padding:.75rem 1rem; border-radius:8px; margin-bottom:1rem; font-size:.85rem; }
  .alert-danger{ background:rgba(220,53,69,.12); border:1px solid rgba(220,53,69,.35); color:#ff8a97; }

  /* ---------- hero layout (image + info side-by-side, stacks on mobile) ---------- */
  .detail-hero{
    display:grid;
    grid-template-columns: 240px 1fr;
    gap:1.5rem;
    align-items:start;
  }
  .detail-hero-media{ width:100%; }
  .detail-image{
    width:100%;
    aspect-ratio: 4 / 5;
    object-fit:cover;
    border-radius:12px;
    border:1px solid var(--line);
    display:block;
  }
  .detail-hero-info{ min-width:0; }
  .detail-stat-row{
    display:flex;
    align-items:center;
    gap:.65rem;
    font-size:.86rem;
    color:var(--muted);
    margin-bottom:.65rem;
    line-height:1.4;
  }
  .detail-stat-row:last-of-type{ margin-bottom:0; }
  .detail-stat-row i{
    width:18px;
    flex-shrink:0;
    text-align:center;
    color:var(--pink);
  }
  .detail-actions{
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:.55rem;
    margin-top:1.1rem;
    padding-top:1.1rem;
    border-top:1px solid var(--line);
  }
  .detail-actions form{ margin:0; display:inline-flex; }
  @media (max-width: 767px){
    .detail-hero{ grid-template-columns: 1fr; }
    .detail-image{ max-width:220px; aspect-ratio:4/5; margin:0 auto; }
    .detail-hero-info{ text-align:left; }
  }

  /* ---------- shareable links field ---------- */
  .ev-field{ margin-bottom:1rem; }
  .ev-field .form-label-c{ display:block; margin-bottom:.4rem; }
  .copy-field{ display:flex; align-items:center; gap:.5rem; }
  .copy-field .form-control-c{ flex:1; min-width:0; }

  /* ---------- hidden contact-info indicator ---------- */
  .contact-hidden{ display:inline-flex; align-items:center; gap:.35rem; color:var(--muted-2); opacity:.75; font-size:.8rem; }
  .contact-hidden-note{ display:flex; align-items:center; gap:.5rem; background:var(--panel-2); border:1px solid var(--line); border-radius:8px; padding:.6rem .9rem; margin-bottom:1rem; font-size:.8rem; }
  .contact-hidden-note i{ color:var(--pink); }
</style>

<?php
$ticket_result = $conn->prepare("SELECT DISTINCT ticket_name FROM event_tickets WHERE event_id = ?");
$ticket_result->bind_param('i', $event_id);
$ticket_result->execute();
$ticket_names = [];
$tr = $ticket_result->get_result();
while ($t = $tr->fetch_assoc()) { $ticket_names[] = $t['ticket_name']; }

$sel_query = $conn->prepare("
    SELECT id, name, price, created_at, pay_ref, payment_id, email, phone
    FROM payments WHERE event_id = ? ORDER BY created_at DESC");
$sel_query->bind_param('s', $urlsss);
$sel_query->execute();
$payments = $sel_query->get_result()->fetch_all(MYSQLI_ASSOC);

$qtyStmt = $conn->prepare("SELECT ticket_name, quantity FROM ticket_orders WHERE payment_ref = ?");
?>

<div class="panel-card">
    <div class="head">
        <div><h5>Bookings</h5><div class="sub"><?php echo count($payments); ?> orders</div></div>
        <form method="POST"><button type="submit" name="export_csv" class="btn-mini"><i class="fa-solid fa-arrow-down-to-line"></i> Export CSV</button></form>
    </div>
    <?php if ($role === 'partner' && !$canViewContact): ?>
        <div class="cell-sub contact-hidden-note"><i class="fa-solid fa-lock"></i> Email and phone are hidden for partners. An admin can enable this in Settings &rarr; General.</div>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table-clean">
            <thead>
                <tr>
<th>Date</th>
<th>Name</th>
<th>Price</th>
<th>Order Id</th>
<?php echo $canViewContact ? '<th>Email</th>' : ''; ?>
<?php echo $canViewContact ? '<th>Phone</th>' : ''; ?>
<th>Payment Id</th>                    <?php foreach ($ticket_names as $tn): ?><th><?php echo h($tn); ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php if ($payments): foreach ($payments as $p):
                $qty = array_fill_keys($ticket_names, 0);
                $qtyStmt->bind_param('s', $p['pay_ref']);
                $qtyStmt->execute();
                $qRes = $qtyStmt->get_result();
                while ($q = $qRes->fetch_assoc()) { $qty[$q['ticket_name']] = $q['quantity']; }
            ?>
                <tr>
                    <td class="cell-sub"><?php echo h(date('d-m-Y', strtotime($p['created_at']))); ?></td>
                    <td class="cell-title"><?php echo h($p['name']); ?></td>
                    <td><?php echo ((float) $p['price']) == 0 ? 'FREE' : h($p['price']); ?></td>
                    <td class="cell-sub"><?php echo h($p['pay_ref']); ?></td>
                   <?php echo $canViewContact ? '<td>' . h($p['email']) . '</td>' : ''; ?>
<?php echo $canViewContact ? '<td>' . h($p['phone']) . '</td>' : ''; ?>
<td class="cell-sub"><?php echo h($p['payment_id']); ?></td>
                    <?php foreach ($ticket_names as $tn): ?><td><?php echo (int) $qty[$tn]; ?></td><?php endforeach; ?>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="<?php echo 7 + count($ticket_names); ?>" class="cell-sub text-center">No bookings yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>