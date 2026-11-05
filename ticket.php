<?php
/**
 * ticket.php — ONE FILE for the e-pass page.
 * Contains: device detection, data + access control, PDF download,
 * the mobile layout and the desktop layout.
 *
 * Device priority:
 *   1. ?view=mobile | ?view=desktop   (manual override, remembered in a cookie)
 *   2. Cookie from a previous override
 *   3. X-HighSpinn-App header          (native app webview => mobile)
 *   4. User-Agent sniffing             (phones => mobile, everything else => desktop)
 */
if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('hs_is_mobile_request')) {
    function hs_is_mobile_request(): bool {
        if (isset($_GET['view']) && in_array($_GET['view'], ['mobile', 'desktop'], true)) {
            setcookie('hs_view', $_GET['view'], time() + 30 * 86400, '/');
            return $_GET['view'] === 'mobile';
        }
        if (isset($_COOKIE['hs_view']) && in_array($_COOKIE['hs_view'], ['mobile', 'desktop'], true)) {
            return $_COOKIE['hs_view'] === 'mobile';
        }
        if (($_SERVER['HTTP_X_HIGHSPINN_APP'] ?? '') === 'true') {
            return true;
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua)) return false;
        if (preg_match('/Android.*Mobile|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini|IEMobile|Mobi/i', $ua)) return true;
        return false;
    }
}

// Same URL returns different HTML, so tell caches/CDNs
header('Vary: User-Agent, Cookie, X-HighSpinn-App');
$isMobile = hs_is_mobile_request();

include('include/config.php');

// ---------------------------------------------------------------
// Helper: check whether a column exists before we SELECT it
// ---------------------------------------------------------------
if (!function_exists('columnExists')) {
    function columnExists($conn, $table, $column) {
        $table = mysqli_real_escape_string($conn, $table);
        $column = mysqli_real_escape_string($conn, $column);
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $res && mysqli_num_rows($res) > 0;
    }
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
// Access control: only the account that made the booking sees it
// ---------------------------------------------------------------
$isLoggedIn = !empty($_SESSION['hs_user_id']);

$isOwner = false;
if ($isLoggedIn && $hasUserIdCol) {
    $sessionUserId = (int) $_SESSION['hs_user_id'];
    $ticketOwnerId = isset($row['user_id']) ? (int) $row['user_id'] : null;
    $isOwner = ($ticketOwnerId !== null && $ticketOwnerId === $sessionUserId);
}
if (!$hasUserIdCol) {
    error_log("ticket.php: payments.user_id column is missing; cannot verify ticket ownership.");
}

$canViewTicket = $isLoggedIn && $isOwner;

if (isset($_GET['download']) && $_GET['download'] === 'pdf' && !$canViewTicket) {
    header("Location: /ticket/" . rawurlencode($id));
    exit();
}

// ---------------------------------------------------------------
// Dates / times
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
            return $d;
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
    error_log("ticket.php ticket_orders query failed: " . $e->getMessage());
    $purchasedTickets = [];
}

// ---------------------------------------------------------------
// QR code
// ---------------------------------------------------------------
$qr_code_url = 'https://quickchart.io/qr?text=' . urlencode($row['ref']) . '&size=400';
$qr_code_image = $canViewTicket ? @file_get_contents($qr_code_url) : false;
$qr_code_base64 = $qr_code_image ? base64_encode($qr_code_image) : '';

// ---------------------------------------------------------------
// Misc display helpers
// ---------------------------------------------------------------
$isFreeBooking = ((float)$row['price'] == 0);
$paidVia = $isFreeBooking ? 'Free entry' : 'Online payment';
$guestEmail = $hasEmailCol ? ($row['email'] ?? '') : '';
$guestPhone = $hasPhoneCol ? ($row['phone'] ?? '') : '';

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
   PDF E-PASS GENERATION — only reachable once $canViewTicket is true.
   ========================================================================= */
if (isset($_GET['download']) && $_GET['download'] === 'pdf' && $canViewTicket) {

    if (!class_exists('Dompdf\Dompdf')) {
        header("HTTP/1.1 500 Internal Server Error");
        die('PDF generation is unavailable: Dompdf is not installed. Run "composer require dompdf/dompdf" and ensure vendor/autoload.php is loaded.');
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

// Shared values for the page shell
$backUrl = $canViewTicket ? '/account/bookings/' : '/';

include 'include/header.php';
?>

<?php if ($isMobile): ?>
<?php /* =====================================================================
   MOBILE LAYOUT
   ===================================================================== */ ?>

<style>
body.hs-app-view .navbar-hs,
body.hs-app-view footer { display:none !important; }
body.hs-app-view { padding-bottom:<?= $canViewTicket ? '96px' : '24px' ?>; }

.hs-app{ max-width:480px; margin:0 auto; }

/* ---- sticky header ---- */
.tk-header{
  position:sticky; top:0; z-index:40; padding:12px 16px;
  display:flex; align-items:center; gap:12px;
  background:rgba(10,10,13,.95); backdrop-filter:blur(14px); border-bottom:1px solid var(--line);
}
.tk-back{
  width:36px; height:36px; border-radius:50%; flex:none; display:flex; align-items:center; justify-content:center;
  background:var(--panel-2); border:1px solid var(--line); color:var(--text);
}
.tk-title{ font-size:16px; font-weight:800; flex:1; margin:0; }
.tk-status{
  font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.04em;
  color:var(--green); background:rgba(61,220,132,.12); border:1px solid rgba(61,220,132,.35);
  padding:5px 10px; border-radius:100px; display:inline-flex; align-items:center; gap:5px;
}

.tk-body{ padding:16px; }

/* ---- locked / not found ---- */
.locked-card{
  background:var(--panel); border:1px solid var(--line); border-radius:18px;
  padding:32px 20px; text-align:center; margin-top:24px;
}
.locked-icon{
  width:60px; height:60px; border-radius:50%; margin:0 auto 14px;
  background:rgba(255,61,104,.12); color:var(--pink);
  display:flex; align-items:center; justify-content:center; font-size:22px;
}
.locked-card h1{ font-size:18px; font-weight:800; margin-bottom:8px; }
.locked-card p{ color:var(--muted); font-size:13px; line-height:1.55; margin-bottom:20px; }
.locked-card .btn-pink{ width:100%; padding:13px; }
.locked-links{ display:flex; gap:10px; margin-top:14px; }
.locked-links a{ flex:1; text-align:center; padding:12px; font-size:13px; }

/* ---- ticket card ---- */
.tk-card{ background:var(--panel); border:1px solid var(--line); border-radius:20px; overflow:hidden; position:relative; }

.tk-hero{
  position:relative; padding:18px 16px 16px; color:#fff;
  background:linear-gradient(150deg,#7a1240,#280a1c) center/cover no-repeat;
}
.tk-hero::before{ content:''; position:absolute; inset:0; background:linear-gradient(180deg, rgba(10,10,13,.35) 0%, rgba(10,10,13,.92) 100%); }
.tk-hero > *{ position:relative; z-index:1; }
.tk-success{
  display:flex; align-items:center; gap:8px; font-size:12px; font-weight:700; color:var(--green); margin-bottom:46px;
}
.tk-success i{
  width:22px; height:22px; border-radius:50%; background:rgba(61,220,132,.15); border:1px solid rgba(61,220,132,.4);
  display:flex; align-items:center; justify-content:center; font-size:10px;
}
.tk-hero h2{ font-size:20px; font-weight:800; line-height:1.2; margin:0 0 8px; }
.tk-meta{ font-size:12px; color:rgba(255,255,255,.8); display:flex; flex-direction:column; gap:4px; }
.tk-meta i{ width:14px; color:var(--yellow); }

/* tear line with notches */
.tk-tear{ position:relative; height:0; border-top:2px dashed var(--line); margin:0 14px; }
.tk-tear::before, .tk-tear::after{
  content:''; position:absolute; top:-13px; width:24px; height:24px; border-radius:50%;
  background:var(--void);
  border:1px solid var(--line);
}
.tk-tear::before{ left:-27px; }
.tk-tear::after{ right:-27px; }

.tk-qr-wrap{ padding:22px 16px 18px; text-align:center; }
.qr-box{
  width:190px; height:190px; margin:0 auto; background:#fff; border-radius:16px; padding:12px;
  display:flex; align-items:center; justify-content:center;
}
.qr-box img{ width:100%; height:100%; object-fit:contain; }
.qr-caption{ font-size:11px; color:var(--muted-2); font-weight:700; letter-spacing:.08em; text-transform:uppercase; margin-top:12px; }
.tk-ref{
  display:inline-block; margin-top:8px; background:var(--panel-2); border:1px solid var(--line);
  padding:7px 14px; border-radius:100px; font-size:12px; color:var(--muted); word-break:break-all;
}
.tk-ref b{ color:var(--text); }

/* ---- sections ---- */
.tk-section{ padding:14px 16px; border-top:1px solid var(--line); }
.tk-section h6{ font-size:11px; font-weight:800; letter-spacing:.07em; text-transform:uppercase; color:var(--muted-2); margin:0 0 10px; display:flex; align-items:center; gap:7px; }
.tk-section h6 i{ color:var(--pink); }

.pl-row{ display:flex; justify-content:space-between; gap:10px; padding:8px 0; border-bottom:1px solid var(--line); font-size:13px; }
.pl-row:last-of-type{ border-bottom:none; }
.pl-row .name{ color:var(--text); font-weight:600; }
.pl-row .amt{ color:var(--muted); white-space:nowrap; }
.total-row{ display:flex; justify-content:space-between; align-items:baseline; margin-top:8px; padding-top:12px; border-top:1px solid var(--line); }
.total-row .label{ font-weight:700; font-size:13px; }
.total-row .amt{ font-size:20px; font-weight:800; color:var(--yellow); }

.guest-grid{ display:grid; grid-template-columns:1fr 1fr; gap:14px 12px; }
.guest-grid > div{ min-width:0; font-size:11px; color:var(--muted-2); }
.guest-grid b{ display:block; font-size:13px; color:var(--text); font-weight:700; margin-bottom:2px; word-break:break-word; }
.guest-grid .full{ grid-column:1 / -1; }

/* ---- instructions / next steps ---- */
.tk-extra{ margin-top:14px; background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:14px 16px; }
.tk-extra h6{ font-size:11px; font-weight:800; letter-spacing:.07em; text-transform:uppercase; color:var(--muted-2); margin:0 0 10px; display:flex; align-items:center; gap:7px; }
.tk-extra h6 i{ color:var(--pink); }
.instructions-list{ list-style:none; margin:0; padding:0; }
.instructions-list li{ display:flex; align-items:flex-start; gap:9px; font-size:12.5px; color:var(--muted); padding:9px 0; border-bottom:1px solid var(--line); line-height:1.55; }
.instructions-list li:last-child{ border-bottom:none; padding-bottom:0; }
.instructions-list li i{ color:var(--yellow); font-size:12px; margin-top:3px; flex:none; }

.step-item{ display:flex; gap:12px; padding:10px 0; border-bottom:1px solid var(--line); }
.step-item:last-child{ border-bottom:none; padding-bottom:0; }
.step-icon{ width:36px; height:36px; border-radius:10px; background:var(--panel-2); color:var(--pink); display:flex; align-items:center; justify-content:center; flex:none; }
.step-item h6{ font-size:13px; font-weight:700; color:var(--text); text-transform:none; letter-spacing:0; margin:0 0 2px; display:block; }
.step-item p{ font-size:12px; color:var(--muted); margin:0; line-height:1.45; }

.tk-home-links{ display:flex; gap:10px; margin-top:16px; }
.tk-home-links a{ flex:1; text-align:center; padding:12px; font-size:13px; }

/* ---- bottom action bar ---- */
.tk-actions{
  position:fixed; bottom:0; left:0; right:0; z-index:1050; max-width:480px; margin:0 auto;
  display:flex; align-items:center; gap:10px;
  padding:12px 16px calc(12px + env(safe-area-inset-bottom));
  background:rgba(15,15,18,.96); backdrop-filter:blur(16px); border-top:1px solid var(--line);
}
.tk-actions .btn-pink{ flex:1; padding:13px; font-size:14px; text-align:center; white-space:nowrap; }
.tk-icon-btn{
  width:48px; height:48px; flex:none; border-radius:14px; display:flex; align-items:center; justify-content:center;
  background:var(--panel-2); border:1px solid var(--line); color:var(--text); font-size:16px;
}
</style>

<div class="hs-app">

  <div class="tk-header">
    <a class="tk-back" href="<?= htmlspecialchars($backUrl) ?>" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></a>
    <h1 class="tk-title">E-Pass</h1>
    <?php if ($canViewTicket): ?>
      <span class="tk-status"><i class="fa-solid fa-circle-check"></i> Confirmed</span>
    <?php endif; ?>
  </div>

  <div class="tk-body">

  <?php if (!$isLoggedIn): ?>

    <!-- ============ NOT SIGNED IN ============ -->
    <div class="locked-card">
      <div class="locked-icon"><i class="fa-solid fa-lock"></i></div>
      <h1>Please sign in to view this ticket</h1>
      <p>This e-pass belongs to a HighSpinn account. Sign in with the mobile number used to book it and your ticket will show up right here.</p>
      <button type="button" class="btn-pink" data-bs-toggle="modal" data-bs-target="#authModal">Sign in</button>
      <div class="locked-links"><a href="/" class="btn-dark-outline">Back to home</a></div>
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

    <!-- ============ SIGNED IN, NOT THE OWNER ============ -->
    <div class="locked-card">
      <div class="locked-icon"><i class="fa-solid fa-ticket"></i></div>
      <h1>We couldn't find that ticket</h1>
      <p>This booking isn't linked to your account. Check that you're signed in with the mobile number used for this booking.</p>
      <div class="locked-links">
        <a href="/account/bookings/" class="btn-pink">My bookings</a>
        <a href="/" class="btn-dark-outline">Home</a>
      </div>
    </div>

  <?php else: ?>

    <!-- ============ REAL E-PASS ============ -->
    <div class="tk-card">

      <div class="tk-hero" style="background-image:url('<?= htmlspecialchars($row['image']) ?>');">
        <div class="tk-success"><i class="fa-solid fa-check"></i> You're on the list! &#127881;</div>
        <h2><?= htmlspecialchars($row['tittle']) ?></h2>
        <div class="tk-meta">
          <span><i class="fa-regular fa-clock"></i> <?= htmlspecialchars($dateTimeLabel) ?><?= $timeend ? ' &ndash; ' . htmlspecialchars($timeend) : '' ?></span>
          <span><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($row['placename']) ?></span>
          <?php if ($selectedDatesLabel): ?>
            <span><i class="fa-regular fa-calendar-check"></i> Valid for: <?= htmlspecialchars($selectedDatesLabel) ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="tk-tear"></div>

      <div class="tk-qr-wrap">
        <div class="qr-box">
          <?php if ($qr_code_base64): ?>
            <img src="data:image/png;base64,<?= $qr_code_base64 ?>" alt="Entry QR code">
          <?php else: ?>
            <span style="font-size:12px; color:#666;">QR unavailable</span>
          <?php endif; ?>
        </div>
        <div class="qr-caption">Scan at entry</div>
        <div class="tk-ref">Booking ID <b><?= htmlspecialchars($row['ref']) ?></b></div>
      </div>

      <div class="tk-section">
        <h6><i class="fa-solid fa-ticket"></i> Your passes</h6>
        <?php if (empty($purchasedTickets)): ?>
          <div class="pl-row"><span class="name">1 pass</span><span class="amt"><?= $isFreeBooking ? 'Free' : ('&#8377;' . number_format((float)$row['price'], 2)) ?></span></div>
        <?php else: foreach ($purchasedTickets as $pt):
              $qty = (int)$pt['quantity'];
              $unit = isset($pt['unit_price']) ? (float)$pt['unit_price'] : null;
              $lineTotal = $unit !== null ? $unit * $qty : null;
        ?>
          <div class="pl-row">
            <span class="name"><?= htmlspecialchars($pt['ticket_name']) ?> &times; <?= $qty ?></span>
            <span class="amt"><?= $lineTotal !== null ? ($lineTotal == 0 ? 'Free' : ('&#8377;' . number_format($lineTotal, 2))) : '' ?></span>
          </div>
        <?php endforeach; endif; ?>

        <div class="total-row">
          <span class="label">Total paid</span>
          <span class="amt"><?= $isFreeBooking ? 'Free' : ('&#8377;' . number_format((float)$row['price'], 2)) ?></span>
        </div>
      </div>

      <div class="tk-section">
        <h6><i class="fa-solid fa-user"></i> Guest details</h6>
        <div class="guest-grid">
          <div><b><?= htmlspecialchars($row['fname']) ?></b>Guest name</div>
          <div><b><?= htmlspecialchars($paidVia) ?></b>Paid via</div>
          <?php if ($guestPhone): ?><div><b><?= htmlspecialchars($guestPhone) ?></b>Phone</div><?php endif; ?>
          <?php if ($bookedOn): ?><div><b><?= htmlspecialchars($bookedOn) ?></b>Booked on</div><?php endif; ?>
          <?php if ($guestEmail): ?><div class="full"><b><?= htmlspecialchars($guestEmail) ?></b>Email</div><?php endif; ?>
        </div>
      </div>

    </div>

    <div class="tk-extra">
      <h6><i class="fa-solid fa-route"></i> What's next</h6>
      <div class="step-item">
        <div class="step-icon"><i class="fa-solid fa-envelope"></i></div>
        <div><h6>Check your inbox</h6><p>A copy of your e-pass and receipt has been sent to your email.</p></div>
      </div>
      <div class="step-item">
        <div class="step-icon"><i class="fa-solid fa-qrcode"></i></div>
        <div><h6>Show your QR at entry</h6><p>Screenshot this page or keep it handy. No printout needed.</p></div>
      </div>
      <?php if ($time): ?>
      <div class="step-item">
        <div class="step-icon"><i class="fa-solid fa-champagne-glasses"></i></div>
        <div><h6>Arrive by <?= htmlspecialchars($time) ?></h6><p>Entry passes may be released after this window subject to capacity.</p></div>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($instructions)): ?>
    <div class="tk-extra">
      <h6><i class="fa-solid fa-circle-info"></i> Important instructions</h6>
      <ul class="instructions-list">
        <?php foreach ($instructions as $note): ?>
          <li><i class="fa-solid fa-circle-check"></i><span><?= htmlspecialchars($note) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="tk-home-links">
      <a href="/" class="btn-pink">Back to home</a>
      <a href="/events" class="btn-dark-outline">More events</a>
    </div>

  <?php endif; ?>

  </div>
</div>

<?php if ($canViewTicket): ?>
<div class="tk-actions">
  <a href="?url=<?= urlencode($row['ref']) ?>&download=pdf" class="btn-pink"><i class="fa-solid fa-download me-2"></i>Download e-pass</a>
  <button type="button" class="tk-icon-btn" id="shareBtn" aria-label="Share"><i class="fa-solid fa-share-nodes"></i></button>
  <a href="#" class="tk-icon-btn" id="calendarBtn" aria-label="Add to calendar"><i class="fa-regular fa-calendar-plus"></i></a>
</div>
<?php endif; ?>

<script>document.body.classList.add('hs-app-view');</script>

<?php else: ?>
<?php /* =====================================================================
   DESKTOP LAYOUT
   ===================================================================== */ ?>

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

    <?php endif; ?>

  </div>
</div>

<?php endif; // isMobile ?>

<?php if ($canViewTicket): ?>
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

<?php include $isMobile ? 'include/footerapp.php' : 'include/footer.php'; ?>