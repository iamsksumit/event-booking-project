<?php
if (session_status() === PHP_SESSION_NONE) session_start();

include('include/config.php');

// ---------------------------------------------------------------
// Helper: check whether a column exists on a table before we ever
// SELECT it, so this page keeps working even if your `payments` /
// `ticket_orders` tables don't have every optional column below.
// ---------------------------------------------------------------
function columnExists($conn, $table, $column) {
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && mysqli_num_rows($res) > 0;
}

$id = mysqli_real_escape_string($conn, $_REQUEST['url'] ?? '');

if ($id === '') {
    header("HTTP/1.1 404 Not Found");
    echo '<script> window.location.replace("/404") </script>';
    exit();
}

$hasEmailCol  = columnExists($conn, 'payments', 'email');
$hasPhoneCol  = columnExists($conn, 'payments', 'phone');
$hasMethodCol = columnExists($conn, 'payments', 'payment_id');
$hasUserIdCol = columnExists($conn, 'payments', 'user_id');

$extraSelect = '';
if ($hasEmailCol)  $extraSelect .= ', payments.email as email';
if ($hasPhoneCol)  $extraSelect .= ', payments.phone as phone';
if ($hasMethodCol) $extraSelect .= ', payments.payment_id as payment_id';
if ($hasUserIdCol) $extraSelect .= ', payments.user_id as user_id';

$query = "SELECT
        events.id as event_pk,
        events.url as url,
        place.name as placename,
        events.tittle as tittle,
        events.sub_tittle as sub_tittle,
        payments.price as price,
        payments.selected_dates as selected_dates,
        events.description as description,
        events.term as term,
        events.video as video,
        events.Organised as Organised,
        events.special_detail as special_detail,
        events.image as image,
        events.start as start,
        events.end as end,
        payments.name as fname,
        payments.id as id,
        payments.created_at as pcreated_at,
        payments.pay_ref as ref
        $extraSelect
    FROM payments
    INNER JOIN events ON payments.event_id = events.url
    INNER JOIN place ON events.sub_tittle = place.id
    WHERE payments.pay_ref = '$id'
    LIMIT 1";

try {
    $result = mysqli_query($conn, $query);
    if ($result === false) {
        throw new Exception(mysqli_error($conn));
    }
} catch (\Throwable $e) {
    error_log("ticket.php lookup query failed: " . $e->getMessage());
    header("HTTP/1.1 404 Not Found");
    echo '<script> window.location.replace("/404") </script>';
    exit();
}

$row = mysqli_fetch_assoc($result);

if (!$row) {
    header("HTTP/1.1 404 Not Found");
    echo '<script> window.location.replace("/404") </script>';
    exit();
}

// ---------------------------------------------------------------
// Access control: a ticket page belongs to the account that made
// the booking. There is no separate /login page — sign-in happens
// through the #authModal that lives in header.php — so instead of
// redirecting anywhere, we still render the normal page shell
// (header/footer, so the modal markup + its JS are present) and:
//   - if nobody's signed in, show a "please sign in" panel and
//     auto-open #authModal
//   - if someone IS signed in but isn't the owner, show a generic
//     "can't find that ticket" panel (never reveal it belongs to
//     someone else)
//   - only the actual owner sees the real e-pass
// ---------------------------------------------------------------
$isLoggedIn = !empty($_SESSION['hs_user_id']);

$isOwner = false;
if ($isLoggedIn && $hasUserIdCol) {
    $sessionUserId = (int) $_SESSION['hs_user_id'];
    $ticketOwnerId = isset($row['user_id']) ? (int) $row['user_id'] : null;
    $isOwner = ($ticketOwnerId !== null && $ticketOwnerId === $sessionUserId);
}
if (!$hasUserIdCol) {
    // Schema doesn't have payments.user_id at all — fail closed rather
    // than silently serving every ticket to anyone who's logged in.
    error_log("ticket.php: payments.user_id column is missing; cannot verify ticket ownership.");
}

$canViewTicket = $isLoggedIn && $isOwner;

// ---------------------------------------------------------------
// PDF download endpoint — gate this the same way. No modal to show
// on a raw file download, so just bounce back to the ticket page,
// which will show the sign-in prompt (or the real e-pass, if the
// download link was hit by the rightful owner).
// ---------------------------------------------------------------
if (isset($_GET['download']) && $_GET['download'] === 'pdf' && !$canViewTicket) {
    header("Location: /ticket/" . rawurlencode($id));
    exit();
}

// ---------------------------------------------------------------
// Dates / times
// (guarded — a booking with a missing/blank event start or end no
// longer fatals the whole page; it just shows blank date fields)
// ---------------------------------------------------------------
$startObj = !empty($row['start']) ? date_create($row['start']) : false;
$endObj   = !empty($row['end'])   ? date_create($row['end'])   : false;

if ($startObj) {
    $monthss = date_format($startObj, 'M');
    $date    = date_format($startObj, 'd');
    $day     = date_format($startObj, 'D');
    $time    = date_format($startObj, 'g:i a');
    $year    = date_format($startObj, 'Y');
    $dateTimeLabel = "$day, $date $monthss $year \xC2\xB7 $time";
} else {
    $monthss = $date = $day = $time = $year = '';
    $dateTimeLabel = 'Date not available';
}

$timeend = $endObj ? date_format($endObj, 'g:i a') : '';

$bookedOn = !empty($row['pcreated_at']) ? date('d M Y, g:i a', strtotime($row['pcreated_at'])) : '';

$selected_dates = json_decode($row['selected_dates'], true);
if (!is_array($selected_dates)) $selected_dates = [];
$selectedDatesLabel = '';
if (!empty($selected_dates)) {
    $formattedDates = array_map(function ($d) {
        try {
            return (new DateTime($d))->format('d M Y');
        } catch (\Throwable $e) {
            return $d; // fall back to the raw string rather than crashing the page
        }
    }, $selected_dates);
    $selectedDatesLabel = implode(', ', $formattedDates);
}

// ---------------------------------------------------------------
// Tickets purchased
// ---------------------------------------------------------------
$hasOrderPriceCol = columnExists($conn, 'ticket_orders', 'price');

if ($hasOrderPriceCol) {
    $ticketsSql = "SELECT ticket_orders.ticket_name, ticket_orders.quantity, ticket_orders.price as unit_price
                   FROM ticket_orders
                   WHERE ticket_orders.payment_ref = '$id'";
} else {
    $ticketsSql = "SELECT ticket_orders.ticket_name, ticket_orders.quantity, event_tickets.ticket_cost as unit_price
                   FROM ticket_orders
                   LEFT JOIN event_tickets
                     ON event_tickets.ticket_name = ticket_orders.ticket_name
                    AND event_tickets.event_id = " . (int)$row['event_pk'] . "
                   WHERE ticket_orders.payment_ref = '$id'";
}

$purchasedTickets = [];
try {
    $ticketsResult = mysqli_query($conn, $ticketsSql);
    if ($ticketsResult === false) {
        throw new Exception(mysqli_error($conn));
    }
    while ($t = mysqli_fetch_assoc($ticketsResult)) {
        $purchasedTickets[] = $t;
    }
} catch (\Throwable $e) {
    // Non-fatal: show the pass without a line-item breakdown rather
    // than crashing the whole confirmation page.
    error_log("ticket.php ticket_orders query failed: " . $e->getMessage());
    $purchasedTickets = [];
}

// ---------------------------------------------------------------
// QR code + logo
// ---------------------------------------------------------------
$qr_code_url = 'https://quickchart.io/qr?text=' . urlencode($row['ref']) . '&size=400';
$qr_code_image = $canViewTicket ? @file_get_contents($qr_code_url) : false;
$qr_code_base64 = $qr_code_image ? base64_encode($qr_code_image) : '';

// ---------------------------------------------------------------
// Misc display helpers
// ---------------------------------------------------------------
$isFreeBooking = ((float)$row['price'] == 0);
$paidVia = $isFreeBooking ? 'Free entry' : (($hasMethodCol && !empty($row['payment_id']) && strtoupper($row['payment_id']) !== 'FREE') ? 'Online payment' : 'Online payment');
$guestEmail = $hasEmailCol ? ($row['email'] ?? '') : '';
$guestPhone = $hasPhoneCol ? ($row['phone'] ?? '') : '';

// ICS calendar payload
$icsStart = $startObj ? date('Ymd\THis\Z', strtotime($row['start'])) : '';
$icsEnd   = $endObj   ? date('Ymd\THis\Z', strtotime($row['end']))   : '';

// ---------------------------------------------------------------
// Important instructions
// ---------------------------------------------------------------
$instructions = [];
$instructions[] = 'Kindly show this e-pass (screenshot or QR) at the venue to gain entry.';
$instructions[] = 'This ticket is valid only for the event, date and guest named above.';
if (!$isFreeBooking) {
    $instructions[] = 'No refund will be issued once the event has concluded.';
}
if (!empty($row['Organised'])) {
    $instructions[] = 'This event is organised by ' . $row['Organised'] . '. For entry-related queries, please reach out to them directly.';
}
if (!empty($row['term'])) {
    $termText = trim(strip_tags($row['term']));
    if ($termText !== '') $instructions[] = $termText;
}
if (!empty($row['special_detail'])) {
    $specialText = trim(strip_tags($row['special_detail']));
    if ($specialText !== '') $instructions[] = $specialText;
}

/* =========================================================================
   PDF E-PASS GENERATION (self-contained — no external /pdf/ service)
   Only reachable once $canViewTicket is true (checked above).
   ========================================================================= */
if (isset($_GET['download']) && $_GET['download'] === 'pdf' && $canViewTicket) {

    if (!class_exists('Dompdf\Dompdf')) {
        header("HTTP/1.1 500 Internal Server Error");
        die('PDF generation is unavailable: Dompdf is not installed. Run "composer require dompdf/dompdf" and ensure vendor/autoload.php is loaded.');
    }

    $eventImgAbs = $row['image'];
    if ($eventImgAbs && strpos($eventImgAbs, 'http') !== 0) {
        $eventImgAbs = 'https://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($eventImgAbs, '/');
    }
    $eventThumbBase64 = '';
    $localImgPath = $row['image'] ? ltrim(preg_replace('#^https?://[^/]+/#', '', $row['image']), '/') : '';
    if ($localImgPath && is_file(__DIR__ . '/' . $localImgPath)) {
        $imgData = @file_get_contents(__DIR__ . '/' . $localImgPath);
        if ($imgData) {
            $ext = strtolower(pathinfo($localImgPath, PATHINFO_EXTENSION));
            $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : ($ext === 'webp' ? 'image/webp' : 'image/png');
            $eventThumbBase64 = 'data:' . $mime . ';base64,' . base64_encode($imgData);
        }
    }

    $pdfHtml = '<html><head><meta charset="utf-8"><style>
        @page{ margin: 24px 22px; }
        body{ font-family: DejaVu Sans, sans-serif; color:#f5f5f7; font-size:11px; background:#0a0a0d; }
        .outer{ background:#0a0a0d; padding:14px; }
        .wrap{ background:#131317; border:1px solid #26262c; border-radius:14px; overflow:hidden; }

        .top-table{ width:100%; border-collapse:collapse; background:#1c0a12; border-bottom:2px dashed #26262c; }
        .top-table td{ padding:16px 20px; vertical-align:middle; }
        .thumb{ width:56px; height:56px; border-radius:10px; background:#280a1c; }
        .badge-pink{ display:inline-block; background:#ff3d68; color:#ffffff; padding:4px 12px; border-radius:20px; font-size:9px; font-weight:bold; letter-spacing:.4px; }
        h1.evt-title{ font-size:17px; margin:8px 0 6px; color:#f5f5f7; font-weight:bold; }
        .meta-line{ font-size:10px; color:#96969f; margin:2px 0; }
        .meta-line b{ color:#f5f5f7; }

        .section{ padding:18px 20px; }
        .qr-cell{ width:150px; text-align:center; vertical-align:top; padding-right:18px; }
        .qr-box{ background:#ffffff; border-radius:12px; padding:10px; display:inline-block; }
        .qr-caption{ font-size:9px; color:#6f6f78; margin-top:6px; font-weight:bold; letter-spacing:.3px; }

        table.tickets{ width:100%; border-collapse:collapse; }
        table.tickets td{ padding:7px 0; border-bottom:1px solid #26262c; font-size:10.5px; color:#f5f5f7; }
        table.tickets td.amt{ text-align:right; color:#96969f; }

        .total-table{ width:100%; border-collapse:collapse; margin-top:10px; border-top:1px solid #26262c; }
        .total-table td{ padding-top:10px; }
        .total-label{ font-size:10.5px; font-weight:bold; color:#f5f5f7; }
        .total-amt{ text-align:right; font-size:16px; font-weight:bold; color:#ffc94d; }

        .guest-table{ width:100%; border-collapse:collapse; background:#1a1a1f; border-top:1px dashed #26262c; }
        .guest-table td{ padding:14px 20px; font-size:9.5px; color:#96969f; vertical-align:top; }
        .guest-table b{ display:block; color:#f5f5f7; font-size:11px; margin-bottom:2px; font-weight:bold; }

        .instr{ padding:16px 20px 20px; background:#131317; }
        .instr-title{ font-size:9px; letter-spacing:.5px; text-transform:uppercase; color:#6f6f78; font-weight:bold; margin-bottom:8px; }
        .instr ul{ margin:0; padding:0; list-style:none; }
        .instr li{ font-size:9.5px; color:#96969f; padding:5px 0; border-bottom:1px solid #26262c; }
        .instr li:last-child{ border-bottom:none; }
        .instr li .dot{ color:#ffc94d; margin-right:6px; }

        .foot-note{ text-align:center; font-size:8.5px; color:#6f6f78; padding:14px 0 0; }
    </style></head><body>
    <div class="outer">
    <div class="wrap">

        <table class="top-table"><tr>';
    if ($eventThumbBase64) {
        $pdfHtml .= '<td style="width:56px;"><img class="thumb" src="' . $eventThumbBase64 . '" width="56" height="56"></td>';
    }
    $pdfHtml .= '<td>
                <span class="badge-pink">Booking ID: ' . htmlspecialchars($row['ref']) . '</span>
                <h1 class="evt-title">' . htmlspecialchars($row['tittle']) . '</h1>
                <div class="meta-line">' . htmlspecialchars($dateTimeLabel) . ($timeend ? ' &ndash; ' . htmlspecialchars($timeend) : '') . '</div>
                <div class="meta-line">' . htmlspecialchars($row['placename']) . '</div>';
    if ($selectedDatesLabel) {
        $pdfHtml .= '<div class="meta-line">Valid for: ' . htmlspecialchars($selectedDatesLabel) . '</div>';
    }
    $pdfHtml .= '</td>
        </tr></table>

        <table class="section" style="width:100%; border-collapse:collapse;"><tr>
            <td class="qr-cell">';
    if ($qr_code_base64) {
        $pdfHtml .= '<div class="qr-box"><img src="data:image/png;base64,' . $qr_code_base64 . '" width="130" height="130"></div>
                <div class="qr-caption">SCAN AT ENTRY</div>';
    }
    $pdfHtml .= '</td>
            <td style="vertical-align:top;">
                <table class="tickets">';
    if (empty($purchasedTickets)) {
        $pdfHtml .= '<tr><td>1 pass</td><td class="amt">' . ($isFreeBooking ? 'Free' : ('&#8377;' . number_format((float)$row['price'], 2))) . '</td></tr>';
    } else {
        foreach ($purchasedTickets as $pt) {
            $qty = (int)$pt['quantity'];
            $unit = isset($pt['unit_price']) ? (float)$pt['unit_price'] : null;
            $lineTotal = $unit !== null ? $unit * $qty : null;
            $amtLabel = $lineTotal !== null ? ($lineTotal == 0 ? 'Free' : ('&#8377;' . number_format($lineTotal, 2))) : '';
            $pdfHtml .= '<tr><td>' . htmlspecialchars($pt['ticket_name']) . ' &times; ' . $qty . '</td><td class="amt">' . $amtLabel . '</td></tr>';
        }
    }
    $pdfHtml .= '</table>
                <table class="total-table"><tr>
                    <td class="total-label">Total paid</td>
                    <td class="total-amt" style="text-align:right;">' . ($isFreeBooking ? 'Free' : ('&#8377;' . number_format((float)$row['price'], 2))) . '</td>
                </tr></table>
            </td>
        </tr></table>

        <table class="guest-table"><tr>
            <td><b>' . htmlspecialchars($row['fname']) . '</b>Guest name</td>';
    if ($guestPhone) $pdfHtml .= '<td><b>' . htmlspecialchars($guestPhone) . '</b>Phone</td>';
    if ($guestEmail) $pdfHtml .= '<td><b>' . htmlspecialchars($guestEmail) . '</b>Email</td>';
    $pdfHtml .= '<td><b>' . htmlspecialchars($paidVia) . '</b>Paid via</td>';
    if ($bookedOn) $pdfHtml .= '<td><b>' . htmlspecialchars($bookedOn) . '</b>Booked on</td>';
    $pdfHtml .= '</tr></table>';

    if (!empty($instructions)) {
        $pdfHtml .= '<div class="instr">
            <div class="instr-title">IMPORTANT INSTRUCTIONS</div>
            <ul>';
        foreach ($instructions as $note) {
            $pdfHtml .= '<li><span class="dot">&#9679;</span>' . htmlspecialchars($note) . '</li>';
        }
        $pdfHtml .= '</ul>
        </div>';
    }

    $pdfHtml .= '</div>
        <div class="foot-note">HighSpinn &middot; Present this e-pass at the venue for entry</div>
    </div>
    </body></html>';

    $dompdf = new \Dompdf\Dompdf([
        'isRemoteEnabled' => false,
        'defaultFont'     => 'DejaVu Sans',
    ]);
    $dompdf->setOptions((new \Dompdf\Options())->set('isHtml5ParserEnabled', true));
    $dompdf->loadHtml($pdfHtml);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $downloadName = 'highspinn-epass-' . preg_replace('/[^A-Za-z0-9_-]/', '', $row['ref']) . '.pdf';
    $dompdf->stream($downloadName, ['Attachment' => true]);
    exit();
}
?>

<style>

.conf-wrap{ padding:8rem 0 4rem; }
.conf-head{ text-align:center; margin-bottom:2.2rem; }
.success-badge{
  width:74px; height:74px; border-radius:50%; margin:0 auto 1.2rem;
  background:radial-gradient(circle at 35% 30%, rgba(61,220,132,.35), transparent 60%), var(--panel);
  border:1px solid rgba(61,220,132,.4);
  display:flex; align-items:center; justify-content:center; font-size:1.9rem; color:var(--green);
  animation:pop .5s cubic-bezier(.34,1.56,.64,1);
}
@keyframes pop{ 0%{ transform:scale(.3); opacity:0; } 100%{ transform:scale(1); opacity:1; } }
.conf-head h1{ font-size:clamp(1.7rem,3.4vw,2.3rem); margin-bottom:.5rem; }
.conf-head p{ color:var(--muted); max-width:440px; margin:0 auto; }

.ticket-card{
  background:var(--panel); border:1px solid var(--line); border-radius:22px; overflow:hidden;
  max-width:760px; margin:0 auto;
}
.ticket-top{
  padding:1.6rem 1.8rem; display:flex; gap:1.4rem; align-items:center; flex-wrap:wrap;
  background:linear-gradient(135deg, rgba(255,61,104,.1), rgba(255,122,69,.05));
  border-bottom:1px dashed var(--line);
}
.ticket-thumb{
  width:74px; height:74px; border-radius:14px; flex:none; position:relative; overflow:hidden;
  background:linear-gradient(150deg,#7a1240,#280a1c); background-size:cover; background-position:center;
}
.ticket-thumb .streaks{ position:absolute; inset:0; background:linear-gradient(120deg, transparent 42%, rgba(255,255,255,.4) 45%, transparent 48%); }
.ticket-top h4{ font-size:1.15rem; margin-bottom:.3rem; }
.ticket-top .meta{ font-size:.82rem; color:var(--muted); display:flex; gap:1.1rem; flex-wrap:wrap; }
.ticket-top .meta span{ display:inline-flex; align-items:center; gap:.35rem; }
.booking-id-pill{
  margin-left:auto; background:var(--panel-2); border:1px solid var(--line); border-radius:999px;
  padding:.5rem 1rem; font-size:.78rem; font-weight:700; color:var(--muted);
}
.booking-id-pill b{ color:var(--text); }

.ticket-body{ padding:1.8rem; display:flex; gap:2rem; flex-wrap:wrap; }
.qr-box{
  width:150px; height:150px; flex:none; background:#fff; border-radius:14px; padding:10px;
  display:flex; align-items:center; justify-content:center; margin:0 auto;
}
.qr-box img{ width:100%; height:100%; object-fit:contain; }
.qr-caption{ text-align:center; font-size:.72rem; color:var(--muted-2); margin-top:.6rem; font-weight:600; }

.pass-list{ flex:1; min-width:220px; }
.pass-list .pl-row{ display:flex; justify-content:space-between; padding:.55rem 0; border-bottom:1px solid var(--line); font-size:.86rem; }
.pass-list .pl-row:last-child{ border-bottom:none; }
.pl-row .name{ color:var(--text); font-weight:600; }
.pl-row .amt{ color:var(--muted); }
.total-row{ display:flex; justify-content:space-between; align-items:baseline; margin-top:.9rem; padding-top:.9rem; border-top:1px solid var(--line); }
.total-row .label{ font-weight:700; font-size:.88rem; }
.total-row .amt{ font-size:1.3rem; font-weight:800; color:var(--yellow); }

.guest-strip{
  padding:1.2rem 1.8rem; border-top:1px dashed var(--line); display:flex; gap:2rem; flex-wrap:wrap;
  font-size:.82rem; color:var(--muted);
}
.guest-strip b{ color:var(--text); display:block; font-size:.9rem; margin-bottom:.1rem; }

.ticket-actions{ padding:1.4rem 1.8rem 1.8rem; display:flex; gap:.8rem; flex-wrap:wrap; }
.ticket-actions a, .ticket-actions button{ flex:1; min-width:160px; text-align:center; }

.next-steps{ max-width:760px; margin:2.5rem auto 0; }
.next-steps .step-item{ display:flex; gap:.9rem; padding:.9rem 0; border-bottom:1px solid var(--line); }
.next-steps .step-item:last-child{ border-bottom:none; }
.next-steps .step-icon{
  width:38px; height:38px; border-radius:10px; background:var(--panel-2); color:var(--pink);
  display:flex; align-items:center; justify-content:center; flex:none; font-size:1rem;
}
.next-steps h6{ font-size:.88rem; font-weight:700; margin-bottom:.15rem; }
.next-steps p{ font-size:.8rem; color:var(--muted); margin:0; }

.instructions-card{
  max-width:760px; margin:1.5rem auto 0; background:var(--panel); border:1px solid var(--line);
  border-radius:18px; padding:1.6rem 1.8rem;
}
.instructions-card h6{ font-size:.72rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--muted-2); margin-bottom:1rem; display:flex; align-items:center; gap:.5rem; }
.instructions-card h6 i{ color:var(--pink); }
.instructions-list{ list-style:none; margin:0; padding:0; }
.instructions-list li{
  display:flex; align-items:flex-start; gap:.65rem; font-size:.84rem; color:var(--muted);
  padding:.55rem 0; border-bottom:1px solid var(--line); line-height:1.6;
}
.instructions-list li:last-child{ border-bottom:none; padding-bottom:0; }
.instructions-list li i{ color:var(--yellow); font-size:.8rem; margin-top:.25rem; flex:none; }

.bottom-links{ text-align:center; margin-top:2.5rem; }

/* ---------- sign-in-required / not-found panel ---------- */
.locked-card{
  background:var(--panel); border:1px solid var(--line); border-radius:22px;
  max-width:520px; margin:0 auto; padding:2.6rem 2rem; text-align:center;
}
.locked-icon{
  width:64px; height:64px; border-radius:50%; margin:0 auto 1.2rem;
  background:rgba(255,61,104,.12); color:var(--pink);
  display:flex; align-items:center; justify-content:center; font-size:1.6rem;
}
.locked-card h1{ font-size:1.4rem; margin-bottom:.5rem; }
.locked-card p{ color:var(--muted); font-size:.9rem; margin-bottom:1.6rem; }

@media print{
  .navbar-hs, .ticket-actions, .next-steps, .bottom-links, footer{ display:none !important; }
  body{ background:#fff; color:#000; }
}

@media (max-width:575.98px){
  .booking-id-pill{ margin-left:0; }
  .ticket-body{ flex-direction:column; align-items:center; text-align:center; }
  .pass-list .pl-row{ text-align:left; }
}
</style>

<?php include 'include/header.php'; ?>

<div class="conf-wrap">
  <div class="container">

    <?php if (!$isLoggedIn): ?>

      <!-- ============ NOT SIGNED IN ============ -->
      <div class="locked-card">
        <div class="locked-icon"><i class="fa-solid fa-lock"></i></div>
        <h1>Please sign in to view this ticket</h1>
        <p>This e-pass belongs to a HighSpinn account. Sign in with the mobile number used to book it and we'll show your ticket right here.</p>
        <button type="button" class="btn-pink" data-bs-toggle="modal" data-bs-target="#authModal">Sign in</button>
      </div>
      <div class="bottom-links">
        <a href="/" class="btn-dark-outline">Back to home</a>
      </div>
      <script>
        document.addEventListener('DOMContentLoaded', function () {
          var authModalEl = document.getElementById('authModal');
          if (authModalEl && window.bootstrap) {
            new bootstrap.Modal(authModalEl).show();
          }
        });
      </script>

    <?php elseif (!$canViewTicket): ?>

      <!-- ============ SIGNED IN, BUT NOT THIS TICKET'S OWNER ============ -->
      <div class="locked-card">
        <div class="locked-icon"><i class="fa-solid fa-ticket"></i></div>
        <h1>We couldn't find that ticket</h1>
        <p>This booking isn't linked to your account. If you think this is a mistake, check that you're signed in with the mobile number used for this booking.</p>
      </div>
      <div class="bottom-links">
        <a href="/account/bookings/" class="btn-pink me-2">My bookings</a>
        <a href="/" class="btn-dark-outline">Back to home</a>
      </div>

    <?php else: ?>

      <!-- ============ REAL E-PASS ============ -->
      <div class="conf-head">
        <div class="success-badge"><i class="fa-solid fa-check"></i></div>
        <h1>You're on the list! &#127881;</h1>
        <p>Your booking is confirmed. We've sent your e-pass to your email &mdash; also available right here.</p>
      </div>

      <div class="ticket-card">
        <div class="ticket-top">
          <div class="ticket-thumb" style="background-image:url('<?php echo htmlspecialchars($row['image']); ?>');"><div class="streaks"></div></div>
          <div>
            <h4><?php echo htmlspecialchars($row['tittle']); ?></h4>
            <div class="meta">
              <span><i class="fa-regular fa-clock"></i> <?php echo htmlspecialchars($dateTimeLabel); ?><?php echo $timeend ? ' &ndash; ' . htmlspecialchars($timeend) : ''; ?></span>
              <span><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($row['placename']); ?></span>
            </div>
            <?php if ($selectedDatesLabel): ?>
              <div class="meta mt-1"><span><i class="fa-regular fa-calendar-check"></i> Valid for: <?php echo htmlspecialchars($selectedDatesLabel); ?></span></div>
            <?php endif; ?>
          </div>
          <div class="booking-id-pill">Booking ID <b><?php echo htmlspecialchars($row['ref']); ?></b></div>
        </div>

        <div class="ticket-body">
          <div>
            <div class="qr-box">
              <?php if ($qr_code_base64): ?>
                <img src="data:image/png;base64,<?php echo $qr_code_base64; ?>" alt="Entry QR code">
              <?php else: ?>
                <span class="text-muted-c2" style="font-size:.7rem;">QR unavailable</span>
              <?php endif; ?>
            </div>
            <div class="qr-caption">Scan at entry</div>
          </div>

          <div class="pass-list">
            <?php if (empty($purchasedTickets)): ?>
              <div class="pl-row"><span class="name">1 pass</span><span class="amt"><?php echo $isFreeBooking ? 'Free' : ('&#8377;' . number_format((float)$row['price'], 2)); ?></span></div>
            <?php else: foreach ($purchasedTickets as $pt):
                  $qty = (int)$pt['quantity'];
                  $unit = isset($pt['unit_price']) ? (float)$pt['unit_price'] : null;
                  $lineTotal = $unit !== null ? $unit * $qty : null;
            ?>
              <div class="pl-row">
                <span class="name"><?php echo htmlspecialchars($pt['ticket_name']); ?> &times; <?php echo $qty; ?></span>
                <span class="amt"><?php echo $lineTotal !== null ? ($lineTotal == 0 ? 'Free' : ('&#8377;' . number_format($lineTotal, 2))) : ''; ?></span>
              </div>
            <?php endforeach; endif; ?>

            <div class="total-row">
              <span class="label">Total paid</span>
              <span class="amt"><?php echo $isFreeBooking ? 'Free' : ('&#8377;' . number_format((float)$row['price'], 2)); ?></span>
            </div>
          </div>
        </div>

        <div class="guest-strip">
          <div><b><?php echo htmlspecialchars($row['fname']); ?></b>Guest name</div>
          <?php if ($guestPhone): ?><div><b><?php echo htmlspecialchars($guestPhone); ?></b>Phone</div><?php endif; ?>
          <?php if ($guestEmail): ?><div><b><?php echo htmlspecialchars($guestEmail); ?></b>Email</div><?php endif; ?>
          <div><b><?php echo htmlspecialchars($paidVia); ?></b>Paid via</div>
          <?php if ($bookedOn): ?><div><b><?php echo htmlspecialchars($bookedOn); ?></b>Booked on</div><?php endif; ?>
        </div>

        <div class="ticket-actions">
          <a href="?url=<?php echo urlencode($row['ref']); ?>&download=pdf" class="btn-pink"><i class="fa-solid fa-download me-2"></i>Download e-pass</a>
          <button type="button" class="btn-dark-outline" id="shareBtn"><i class="fa-solid fa-share-nodes me-2"></i>Share</button>
          <a href="#" class="btn-dark-outline" id="calendarBtn"><i class="fa-regular fa-calendar-plus me-2"></i>Add to calendar</a>
        </div>
      </div>

      <div class="next-steps">
        <div class="step-item">
          <div class="step-icon"><i class="fa-solid fa-envelope"></i></div>
          <div><h6>Check your inbox</h6><p>A copy of your e-pass and receipt has been sent to your email address.</p></div>
        </div>
        <div class="step-item">
          <div class="step-icon"><i class="fa-solid fa-qrcode"></i></div>
          <div><h6>Show your QR at entry</h6><p>Screenshot this page or keep it handy &mdash; no printout needed.</p></div>
        </div>
        <?php if ($time): ?>
        <div class="step-item">
          <div class="step-icon"><i class="fa-solid fa-champagne-glasses"></i></div>
          <div><h6>Arrive by <?php echo htmlspecialchars($time); ?></h6><p>Entry passes may be released after this window subject to capacity.</p></div>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!empty($instructions)): ?>
      <div class="instructions-card">
        <h6><i class="fa-solid fa-circle-info"></i> Important instructions</h6>
        <ul class="instructions-list">
          <?php foreach ($instructions as $note): ?>
            <li><i class="fa-solid fa-circle-check"></i><span><?php echo htmlspecialchars($note); ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <div class="bottom-links">
        <a href="/" class="btn-pink me-2">Back to home</a>
        <a href="/events" class="btn-dark-outline">View more events</a>
      </div>

      <script>
      (function(){
        var booking = {
          event: <?php echo json_encode($row['tittle']); ?>,
          venue: <?php echo json_encode($row['placename']); ?>,
          id: <?php echo json_encode($row['ref']); ?>,
          icsStart: <?php echo json_encode($icsStart); ?>,
          icsEnd: <?php echo json_encode($icsEnd); ?>
        };

        var shareBtn = document.getElementById('shareBtn');
        if (shareBtn) shareBtn.addEventListener('click', function(){
          var text = 'I just booked ' + booking.event + ' on HighSpinn!';
          if (navigator.share){
            navigator.share({ title: 'HighSpinn Booking', text: text, url: window.location.href }).catch(function(){});
          } else {
            navigator.clipboard && navigator.clipboard.writeText(text + ' ' + window.location.href);
            alert('Link copied to clipboard!');
          }
        });

        var calBtn = document.getElementById('calendarBtn');
        if (calBtn) calBtn.addEventListener('click', function(e){
          e.preventDefault();
          if (!booking.icsStart || !booking.icsEnd){
            alert('Calendar details are not available for this event.');
            return;
          }
          var ics = [
            'BEGIN:VCALENDAR','VERSION:2.0','BEGIN:VEVENT',
            'SUMMARY:' + booking.event,
            'LOCATION:' + booking.venue,
            'DTSTART:' + booking.icsStart,
            'DTEND:' + booking.icsEnd,
            'DESCRIPTION:Booking ID ' + booking.id,
            'END:VEVENT','END:VCALENDAR'
          ].join('\r\n');
          var blob = new Blob([ics], {type:'text/calendar'});
          var url = URL.createObjectURL(blob);
          var a = document.createElement('a');
          a.href = url; a.download = 'highspinn-event.ics';
          document.body.appendChild(a); a.click(); document.body.removeChild(a);
          URL.revokeObjectURL(url);
        });
      })();
      </script>

    <?php endif; ?>

  </div>
</div>

<?php include 'include/footer.php'; ?>