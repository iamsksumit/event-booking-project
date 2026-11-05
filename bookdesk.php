<?php
include('include/config.php');

// Get URL param safely (escaped to prevent SQL injection)
$id = mysqli_real_escape_string($conn, $_REQUEST['url'] ?? '');

$query = "SELECT 
        events.*, 
        events.multiple_dates AS multiple_dates,
        place.name AS placename, 
        events.image as image,
        place.address AS placeadd, 
                place.city AS placecity, 

        place.url AS placeurl, 
        place.image_url AS placeimage, 
        artist.name AS artistname, 
        artist.image_url AS artistimage, 
        artist.url AS artisturl,
        event_category.cat_id as cat_id,
        category.cat_name as catname
    FROM events
    LEFT JOIN place ON events.sub_tittle = place.id
    LEFT JOIN event_artist ON events.id = event_artist.event_id
    LEFT JOIN artist ON event_artist.artist_id = artist.id
    LEFT JOIN event_category ON events.id = event_category.event_id
    LEFT JOIN category ON event_category.cat_id = category.id
    WHERE events.url = '$id' 
    LIMIT 1";

$result = mysqli_query($conn, $query) or die(mysqli_error($conn));
$row = mysqli_fetch_assoc($result);

if (!$row || $row['url'] != $_GET['url']) {
    header("HTTP/1.1 404 Not Found");
    echo '<script> window.location.replace("/404") </script>';
    exit();
}

// Prepare multiple dates
$multipleDates1 = [];
$displayDateObj = null;

$originalStart = new DateTime($row['start']); // start time for reference
$now = new DateTime();

if (!empty($row['multiple_dates'])) {
    $dates = array_map('trim', explode(',', $row['multiple_dates']));

    foreach ($dates as $d) {
        $ts = strtotime($d . ' ' . $originalStart->format('H:i:s'));
        if ($ts >= $now->getTimestamp()) {
            $multipleDates1[] = date('Y-m-d H:i:s', $ts);
        }
    }

    if (!empty($multipleDates1)) {
        $displayDateObj = new DateTime($multipleDates1[0]);
    }
} elseif (!empty($row['start'])) {
    $displayDateObj = new DateTime($row['start']);
}

if ($displayDateObj) {
    $day   = $displayDateObj->format('D');
    $date  = $displayDateObj->format('d');
    $month = $displayDateObj->format('M');
    $time  = $displayDateObj->format('g:i a');
} else {
    $day = $date = $month = $time = '';
}

$monthss = $month;

// ---- Fetch real ticket types for this event ----
$ticketsSql = "SELECT event_tickets.id, event_tickets.ticket_name, event_tickets.ticket_cost,
                      event_tickets.ticket_desc, event_tickets.tickets_left
               FROM event_tickets
               INNER JOIN events ON event_tickets.event_id = events.id
               WHERE events.url = '" . mysqli_real_escape_string($conn, $id) . "'
               ORDER BY event_tickets.id ASC";
$ticketsResult = mysqli_query($conn, $ticketsSql) or die(mysqli_error($conn));
$ticketsArr = [];
while ($t = mysqli_fetch_assoc($ticketsResult)) {
    $ticketsArr[] = $t;
}

// Does every ticket on this event cost 0?
$allTicketsFree = true;
foreach ($ticketsArr as $t) {
    if ((float)$t['ticket_cost'] > 0) { $allTicketsFree = false; break; }
}

$currentDate = new DateTime();
$endDate = new DateTime($row['end']);
$eventIsOver = ($endDate <= $currentDate);

// ---- Booking is only allowed for Published/Live events ----
// events.status : 0 = Past/Archived, 1 = Published/Live, 2 = Draft,
//                 3 = Unapproved, 4 = Submitted for Approval
// Any status other than 1 is treated the same as "sold out" for the
// purposes of this page: no ticket selection, no OTP flow, no submit.
$isPublished = isset($row['status']) && (int)$row['status'] === 1;

// Single source of truth used everywhere below to decide whether the
// full booking wizard (dates -> passes -> OTP -> summary -> pay) is
// rendered and wired up with JS.
$bookingAllowed = (!$eventIsOver && $isPublished);

// Not ended, but not published either: still show the ticket list
// (name, price, description) so people can see what's coming, but
// with every interactive control (Add, qty, dates, steps 2 & 3,
// the bottom-bar CTA) removed so nothing can actually be booked.
$showTicketsPreviewOnly = (!$eventIsOver && !$isPublished);

// A single combined "event note" shown on the summary step
$eventNoteText = '';
if (!empty($row['term'])) {
    $eventNoteText .= trim(strip_tags($row['term']));
}
if (!empty($row['special_detail'])) {
    $sd = trim(strip_tags($row['special_detail']));
    if ($sd !== '') $eventNoteText .= ($eventNoteText !== '' ? ' ' : '') . $sd;
}
?>

<?php include 'include/header.php'; ?>

<?php
// ---------------------------------------------------------------------
// Guest-details prefill. header.php has already started the session and
// set $isLoggedIn. Pull the account holder's details for the "myself"
// preview. If an older session predates the email/gender/city session
// keys, backfill them from the DB once so the preview isn't blank.
// ---------------------------------------------------------------------
$sessName   = $_SESSION['hs_user_name']   ?? '';
$sessPhone  = $_SESSION['hs_user_phone']  ?? '';
$sessEmail  = $_SESSION['hs_user_email']  ?? '';
$sessGender = $_SESSION['hs_user_gender'] ?? '';

if ($isLoggedIn && ($sessEmail === '' || $sessGender === '')) {
    $uidStmt = $conn->prepare("SELECT email, gender FROM users WHERE id = ? LIMIT 1");
    if ($uidStmt) {
        $uid = (int) $_SESSION['hs_user_id'];
        $uidStmt->bind_param('i', $uid);
        $uidStmt->execute();
        $uRow = $uidStmt->get_result()->fetch_assoc();
        $uidStmt->close();
        if ($uRow) {
            $sessEmail  = $sessEmail  !== '' ? $sessEmail  : ($uRow['email']  ?? '');
            $sessGender = $sessGender !== '' ? $sessGender : ($uRow['gender'] ?? '');
            $_SESSION['hs_user_email']  = $sessEmail;
            $_SESSION['hs_user_gender'] = $sessGender;
        }
    }
}
?>

<style>
/* ================================================================
   BOOKING WIZARD — single centered column, HighApe-style layout,
   restyled with the site's own palette (pink / yellow / green / void)
   ================================================================ */
.booking-hero{ padding:7.5rem 0 8rem; }
.wizard-col{ max-width:720px; margin:0 auto; }

/* ---------- stepper ---------- */
.book-steps{ display:flex; align-items:center; justify-content:center; gap:.6rem; margin-bottom:.6rem; }
.book-step{ display:flex; flex-direction:column; align-items:center; gap:.4rem; font-size:.78rem; font-weight:700; color:var(--muted-2); }
.book-step .num{
  width:30px; height:30px; border-radius:50%; background:var(--panel-2); border:1px solid var(--line);
  display:flex; align-items:center; justify-content:center; font-size:.82rem; color:var(--muted); transition:background .2s ease, border-color .2s ease, color .2s ease;
}
.book-step.active{ color:var(--text); }
.book-step.active .num{ background:var(--pink); border-color:var(--pink); color:#fff; }
.book-step.done .num{ background:var(--green); border-color:var(--green); color:#0a0a0d; }
.book-step.done .num::before{ content:"\f00c"; font-family:"Font Awesome 6 Free"; font-weight:900; }
.book-step.done .num span{ display:none; }
.book-step-sep{ width:36px; height:1px; background:var(--line); margin-bottom:1.3rem; }

.session-note{ text-align:center; font-size:.8rem; color:var(--muted-2); margin-bottom:1.6rem; }
.session-note b{ color:var(--pink); font-weight:700; }

/* ---------- event summary strip (shown on every step) ---------- */
.evt-strip{
  background:var(--panel); border:1px solid var(--line); border-radius:16px;
  padding:1rem 1.2rem; display:flex; align-items:center; gap:1rem; margin-bottom:1.6rem; flex-wrap:wrap;
}
.evt-strip .thumb{
  width:64px; height:64px; border-radius:12px; flex:none; position:relative; overflow:hidden;
  background:linear-gradient(150deg,#7a1240,#280a1c); background-size:cover; background-position:center;
}
.evt-strip .thumb .streaks{ position:absolute; inset:0; background:linear-gradient(120deg, transparent 42%, rgba(255,255,255,.4) 45%, transparent 48%); }
.evt-strip h5{ font-size:1rem; margin:0 0 .2rem; }
.evt-strip .meta{ font-size:.8rem; color:var(--muted); display:flex; gap:1rem; flex-wrap:wrap; }
.evt-strip .meta span{ display:inline-flex; align-items:center; gap:.35rem; }

/* ---------- panel / card ---------- */
.panel-card{ background:var(--panel); border:1px solid var(--line); border-radius:18px; padding:1.5rem; }
.panel-card + .panel-card{ margin-top:1.1rem; }
.panel-card h6{ font-size:.72rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--muted-2); margin-bottom:1.1rem; display:flex; align-items:center; justify-content:space-between; gap:.6rem; flex-wrap:wrap; }
.panel-card h6 .edit-link{ font-size:.75rem; font-weight:700; letter-spacing:0; text-transform:none; color:var(--pink); cursor:pointer; }
.panel-card h6 .edit-link:hover{ text-decoration:underline; }

/* ---------- step panels (wizard) ---------- */
.step-panel{ display:none; animation:fadeSlide .35s ease; }
.step-panel.active{ display:block; }
@keyframes fadeSlide{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:translateY(0);} }
.step-title{ font-size:1.15rem; font-weight:800; margin-bottom:.3rem; }
.step-subtitle{ font-size:.84rem; color:var(--muted); margin-bottom:1.4rem; }
.step-back{ font-size:.78rem; font-weight:700; color:var(--muted); background:none; border:none; padding:0; margin-bottom:1rem; display:inline-flex; align-items:center; gap:.4rem; }
.step-back:hover{ color:var(--pink); }

/* ---------- date option ---------- */
.date-head-row{ display:flex; align-items:center; justify-content:space-between; margin-bottom:1.1rem; }
.date-head-row h6{ margin-bottom:0; }
.select-all-link{ font-size:.76rem; font-weight:700; color:var(--pink); cursor:pointer; text-transform:none; letter-spacing:0; background:none; border:none; padding:0; }
.select-all-link:hover{ text-decoration:underline; }
.date-opt{
  border:1px solid var(--line); border-radius:10px; padding:.55rem 1rem; font-size:.82rem; font-weight:700;
  cursor:pointer; margin:0 .5rem .5rem 0; display:inline-flex; align-items:center; gap:.5rem; color:var(--muted);
  transition:border-color .2s ease, background .2s ease, color .2s ease;
}
.date-opt input{ accent-color:var(--pink); }
.date-opt.selected{ border-color:var(--pink); background:rgba(255,61,104,.06); color:var(--text); }

/* ---------- pass row (list style, not boxed cards) ---------- */
.pass-row{
  display:flex; align-items:flex-start; justify-content:space-between; gap:1rem;
  padding:1.1rem 0; border-bottom:1px solid var(--line);
}
.pass-row:last-child{ border-bottom:none; padding-bottom:.2rem; }
.pass-row:first-child{ padding-top:.2rem; }
.pass-row.sold-out{ opacity:.5; }
.pass-info h6{ font-size:.95rem; font-weight:700; color:var(--text); text-transform:none; letter-spacing:0; margin:0 0 .3rem; display:flex; align-items:center; gap:.5rem; }
.pass-info .pass-price{ font-size:.85rem; font-weight:700; color:var(--yellow); margin-bottom:.35rem; }
.pass-info p{ font-size:.8rem; color:var(--muted); margin:0; max-width:420px; }
.sold-badge{ font-size:.65rem; font-weight:800; color:var(--pink); background:rgba(255,61,104,.12); padding:.15rem .5rem; border-radius:999px; text-transform:uppercase; letter-spacing:.05em; }
.stock-note{ font-size:.72rem; font-weight:700; color:var(--orange); margin-top:.4rem; display:flex; align-items:center; gap:.35rem; }
.stock-note .dot{ width:5px; height:5px; border-radius:50%; background:var(--orange); animation:pulse 1.4s infinite; }
@keyframes pulse{ 0%,100%{opacity:1;} 50%{opacity:.3;} }

.pass-controls{ flex:none; }
.btn-add-pass{
  background:transparent; border:1.5px solid var(--green); color:var(--green); font-weight:800;
  font-size:.78rem; letter-spacing:.04em; padding:.5rem 1.3rem; border-radius:999px; text-transform:uppercase;
}
.btn-add-pass:hover{ background:var(--green); color:#0a0a0d; }
.btn-add-pass:disabled{ opacity:.4; cursor:not-allowed; background:transparent; color:var(--muted-2); border-color:var(--line); }

.qty-stepper{ display:flex; align-items:center; gap:0; border:1px solid var(--line); border-radius:999px; overflow:hidden; }
.qty-stepper button{
  width:32px; height:32px; background:var(--panel-2); border:none; color:var(--text);
  font-size:1rem; display:flex; align-items:center; justify-content:center; transition:background .15s ease;
}
.qty-stepper button:first-child{ color:var(--pink); }
.qty-stepper button:hover:not(:disabled){ background:var(--pink); color:#fff; }
.qty-stepper button:disabled{ opacity:.35; cursor:not-allowed; }
.qty-stepper .qty-val{ width:34px; text-align:center; font-weight:800; font-size:.92rem; }

/* ---------- form ---------- */
.form-label-hs{ font-size:.78rem; font-weight:700; color:var(--muted); margin-bottom:.4rem; display:block; }
.form-control-hs, .form-select-hs{
  width:100%; background:var(--void); border:1px solid var(--line); color:var(--text);
  border-radius:10px; padding:.65rem .9rem; font-size:.88rem; outline:none;
  transition:border-color .15s ease;
}
.form-control-hs:focus, .form-select-hs:focus{ border-color:var(--pink); }
.field-error{ font-size:.74rem; color:var(--pink); margin-top:.35rem; display:none; }
.field-error.show{ display:block; }
.field-row{ margin-bottom:1rem; }
.field-row:last-child{ margin-bottom:0; }

/* ---------- phone / otp step (centered card, like reference) ---------- */
.info-card-wrap{ display:flex; justify-content:center; }
.info-card{ max-width:520px; width:100%; text-align:left; }
.info-card .step-title{ font-size:1.3rem; }
.phone-input-pill{ display:flex; align-items:center; gap:.6rem; background:var(--void); border:1px solid var(--line); border-radius:10px; padding:.15rem .9rem; transition:border-color .15s ease; }
.phone-input-pill:focus-within{ border-color:var(--pink); }
.phone-input-pill .flag{ font-size:.85rem; color:var(--muted); flex:none; }
.phone-input-pill input{ border:none; background:transparent; color:var(--text); outline:none; padding:.65rem 0; font-size:1rem; width:100%; letter-spacing:.02em; }

.otp-boxes{ display:flex; gap:.6rem; margin:.3rem 0 .2rem; }
.otp-boxes input{
  width:44px; height:52px; text-align:center; font-size:1.2rem; font-weight:700;
  background:var(--void); border:1px solid var(--line); border-radius:10px; color:var(--text); outline:none;
  transition:border-color .15s ease;
}
.otp-boxes input:focus{ border-color:var(--pink); }
.otp-meta-row{ display:flex; justify-content:space-between; align-items:center; font-size:.78rem; color:var(--muted); margin-top:.6rem; flex-wrap:wrap; gap:.4rem; }
.otp-meta-row .phone-shown{ color:var(--text); font-weight:700; }
.resend-link{ background:none; border:none; padding:0; color:var(--pink); font-weight:700; font-size:.78rem; }
.resend-link:disabled{ color:var(--muted-2); cursor:not-allowed; }
.change-number-link{ background:none; border:none; padding:0; color:var(--muted); font-weight:700; font-size:.78rem; text-decoration:underline; }

/* ---------- who is this for ---------- */
.who-toggle{ display:flex; gap:.6rem; margin-bottom:1.2rem; }
.who-toggle .date-opt{ flex:1; justify-content:center; margin:0; }

/* ---------- guest summary (read-only) panel ---------- */
.guest-summary-row{ display:flex; gap:.5rem; font-size:.86rem; color:var(--muted); padding:.3rem 0; }
.guest-summary-row b{ color:var(--text); font-weight:600; min-width:52px; }

/* ---------- selected passes summary ---------- */
.sp-row{ display:flex; justify-content:space-between; font-size:.87rem; color:var(--muted); padding:.5rem 0; border-bottom:1px solid var(--line); }
.sp-row:last-of-type{ border-bottom:none; }
.sp-row span:last-child{ color:var(--text); font-weight:700; }
.sp-total{ display:flex; justify-content:space-between; font-weight:800; font-size:.9rem; color:var(--text); padding-top:.7rem; margin-top:.2rem; border-top:1px solid var(--line); }

/* ---------- pricing details ---------- */
.det-row{ display:flex; justify-content:space-between; font-size:.87rem; color:var(--muted); padding:.4rem 0; }
.det-row span:last-child{ color:var(--text); font-weight:600; }
.det-total{ display:flex; justify-content:space-between; font-weight:800; font-size:1rem; color:var(--text); padding-top:.8rem; margin-top:.3rem; border-top:1px solid var(--line); }
.det-total span:last-child{ color:var(--yellow); font-size:1.15rem; }

/* ---------- event note ---------- */
.event-note-box{
  background:rgba(255,201,77,.08); border:1px solid rgba(255,201,77,.25); border-radius:14px;
  padding:1rem 1.2rem; font-size:.82rem; color:var(--muted); display:flex; gap:.7rem; align-items:flex-start;
}
.event-note-box i{ color:var(--yellow); margin-top:.15rem; flex:none; }
.event-note-box b{ color:var(--text); font-size:.7rem; letter-spacing:.06em; text-transform:uppercase; display:block; margin-bottom:.3rem; }

/* ---------- terms checkbox ---------- */
.terms-row{ display:flex; align-items:flex-start; gap:.6rem; font-size:.85rem; color:var(--muted); margin:1.4rem 0; }
.terms-row input{ accent-color:var(--pink); width:17px; height:17px; margin-top:.15rem; flex:none; }
.terms-row a{ color:var(--text); font-weight:700; text-decoration:underline; }

.change-tickets-link{ display:block; text-align:center; font-size:.85rem; font-weight:700; color:var(--muted); margin-top:1.6rem; }
.change-tickets-link:hover{ color:var(--pink); }

/* ---------- payment / free notice ---------- */
.free-notice{ display:flex; align-items:flex-start; gap:.75rem; }
.free-notice .fn-icon{
  width:38px; height:38px; border-radius:10px; background:rgba(61,220,132,.12); color:var(--green);
  display:flex; align-items:center; justify-content:center; font-size:1.05rem; flex:none;
}
.free-notice h6{ margin:0 0 .2rem; font-size:.92rem; font-weight:700; color:var(--text); text-transform:none; letter-spacing:0; }
.free-notice p{ margin:0; font-size:.8rem; color:var(--muted); line-height:1.6; }

/* ---------- persistent bottom bar (all breakpoints) ---------- */
.wizard-bottom-bar{
  position:fixed; left:0; right:0; bottom:0; z-index:1050;
  background:var(--panel); border-top:1px solid var(--line);
  padding:.9rem 1.2rem calc(.9rem + env(safe-area-inset-bottom));
  box-shadow:0 -8px 24px rgba(0,0,0,.35);
  display:flex; align-items:center; justify-content:space-between; gap:1rem;
}
.wizard-bottom-bar .wb-info{ display:flex; flex-direction:column; line-height:1.2; }
.wizard-bottom-bar .wb-info .wb-main{ font-size:1rem; font-weight:800; color:var(--text); }
.wizard-bottom-bar .wb-info .wb-sub{ font-size:.74rem; color:var(--muted-2); font-weight:600; }
.wizard-bottom-bar .btn-pink{ flex:none; padding:.75rem 1.6rem; font-size:.9rem; white-space:nowrap; }
body{ padding-bottom:92px; }

.event-over-box{ text-align:center; padding:3rem 1rem; }
.event-over-box h3{ color:indianred; }

@media (max-width:991.98px){
  .booking-hero{ padding-top:1rem; }
  
  .hideonapp{
      padding-top:5rem
  }
}
@media (max-width:575.98px){
  .pass-row{ flex-wrap:wrap; }
  .pass-controls{ margin-left:auto; }
}
</style>
<div class="hideonapp" id="hideonapp"></div>
<div class="booking-hero">
  <div class="container">

    <?php if ($eventIsOver): ?>

      <div class="wizard-col">
        <div class="panel-card event-over-box">
          <h3>Event Over</h3>
          <p class="text-muted-c2">This event has already ended and is no longer accepting bookings.</p>
        </div>
      </div>

    <?php else: ?>

    <div class="wizard-col">

      <?php if ($bookingAllowed): ?>
      <div class="book-steps">
        <div class="book-step active" id="stepTab1" data-step="1"><span class="num"><span>1</span></span> Tickets</div>
        <div class="book-step-sep"></div>
        <div class="book-step" id="stepTab2" data-step="2"><span class="num"><span>2</span></span> Info</div>
        <div class="book-step-sep"></div>
        <div class="book-step" id="stepTab3" data-step="3"><span class="num"><span>3</span></span> Summary</div>
      </div>

      <div class="session-note">Session expires in: <b id="sessionTimer">10:00</b></div>
      <?php elseif ($showTicketsPreviewOnly): ?>
      <div class="panel-card" style="text-align:center; margin-bottom:1.6rem;">
        <strong style="color:var(--yellow);"><i class="fa-solid fa-clock-rotate-left me-2"></i>Bookings aren't open yet</strong>
        <p class="text-muted-c2 mb-0" style="font-size:.85rem; margin-top:.4rem;">Here's a preview of the passes for this event &mdash; check back soon to book.</p>
      </div>
      <?php endif; ?>

      <div class="evt-strip" id="hideonapp">
        <div class="thumb"  style="background-image:url('<?php echo htmlspecialchars($row['image']); ?>');"><div class="streaks"></div></div>
        <div class="flex-grow-1">
          <h5><?php echo htmlspecialchars($row['tittle']); ?></h5>
          <div class="meta">
            <span><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($row['placename']); ?></span>
            <span><i class="fa-regular fa-clock"></i>
              <?php echo ($day && $date && $monthss) ? htmlspecialchars("$day, $date $monthss \xC2\xB7 $time") : 'Date not available'; ?>
            </span>
          </div>
        </div>
        <a href="/events/<?php echo htmlspecialchars($row['url']); ?>" class="link-arrow">Change</a>
      </div>

      <form id="bookingForm" novalidate onsubmit="return false;">
        <input type="hidden" id="event_id" name="event_id" value="<?php echo htmlspecialchars($id); ?>">

        <!-- ============ STEP 1: TICKETS ============ -->
        <div class="step-panel active" id="stepPanel1">

          <?php if ($bookingAllowed): ?>
            <?php if (!empty($multipleDates1)): ?>
            <div class="panel-card">
              <div class="date-head-row">
                <h6 class="mb-0"><i class="fa-regular fa-calendar me-1"></i> Select date(s)</h6>
                <?php if (count($multipleDates1) > 1): ?>
                  <button type="button" class="select-all-link" id="selectAllDates">Select all</button>
                <?php endif; ?>
              </div>
              <div>
                <?php foreach ($multipleDates1 as $md):
                      $mdObj = new DateTime($md);
                      $mdLabel = $mdObj->format('D, d M · g:i a');
                ?>
                <label class="date-opt">
                  <input type="checkbox" name="selected_dates[]" value="<?php echo htmlspecialchars($md); ?>">
                  <?php echo htmlspecialchars($mdLabel); ?>
                </label>
                <?php endforeach; ?>
              </div>
              <div class="field-error" id="datesError">Please select at least one date to continue.</div>
            </div>
            <?php else: ?>
              <input type="checkbox" name="selected_dates[]" value="<?php echo htmlspecialchars($originalStart->format('Y-m-d')); ?>" checked style="display:none;">
            <?php endif; ?>
          <?php endif; ?>

          <div class="panel-card">
            <?php if (empty($ticketsArr)): ?>
              <p class="text-muted-c2 mb-0">No tickets available for this event.</p>
            <?php else: foreach ($ticketsArr as $t):
                $leftRaw = $t['tickets_left'];
                $hasLeftCount = ($leftRaw !== null && $leftRaw !== '');
                $leftVal = $hasLeftCount ? (int)$leftRaw : null;
                $soldOut = $hasLeftCount && $leftVal <= 0;
                $lowStock = $hasLeftCount && !$soldOut && $leftVal <= 10;
                $costVal = (float)$t['ticket_cost'];
            ?>
            <div class="pass-row<?php echo $soldOut ? ' sold-out' : ''; ?>" data-id="<?php echo (int)$t['id']; ?>" data-name="<?php echo htmlspecialchars($t['ticket_name']); ?>" data-price="<?php echo $costVal; ?>" data-left="<?php echo $hasLeftCount ? (int)$leftVal : ''; ?>">
              <div class="pass-info" style="
    width: 65%;
">
                <h6><?php echo htmlspecialchars($t['ticket_name']); ?> <?php if ($soldOut): ?><span class="sold-badge">Sold out</span><?php endif; ?></h6>
                <div class="pass-price"><?php echo $costVal == 0 ? 'FREE' : '&#8377;' . htmlspecialchars($t['ticket_cost']); ?></div>
                <?php if (!empty($t['ticket_desc'])): ?><p><?php echo htmlspecialchars($t['ticket_desc']); ?></p><?php endif; ?>
                <?php if ($lowStock): ?>
                  <div class="stock-note"><span class="dot"></span>Only <?php echo $leftVal; ?> left &mdash; almost gone!</div>
                <?php endif; ?>
              </div>
              <div class="pass-controls" style="
    width: 25%;
">
                <?php if ($bookingAllowed): ?>
                  <button type="button" class="btn-add-pass" <?php echo $soldOut ? 'disabled' : ''; ?>>Add</button>
                  <div class="qty-stepper" style="display:none;">
                    <button type="button" class="qty-minus">&minus;</button>
                    <span class="qty-val">0</span>
                    <button type="button" class="qty-plus">+</button>
                  </div>
                <?php else: ?>
                  <span class="sold-badge" style="background:rgba(255,201,77,.12); color:var(--yellow);">Not available</span>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; endif; ?>

            <?php if ($bookingAllowed): ?>
            <div class="field-error" id="passesError">Please select at least one pass to continue.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ============ STEP 2: INFO (verify mobile) ============ -->
        <?php if ($bookingAllowed): ?>
        <div class="step-panel" id="stepPanel2">
          <div class="info-card-wrap">
            <div class="info-card">
              <button type="button" class="step-back" data-back="1"><i class="fa-solid fa-arrow-left"></i> Back to tickets</button>

              <!-- phone entry -->
              <div id="otpPhonePane">
                <div class="step-title">Confirm your number</div>
                <div class="step-subtitle">We'll send you an OTP to verify &mdash; takes 10 secs.</div>
                <label class="form-label-hs" for="otpPhoneInput">Mobile number</label>
                <div class="phone-input-pill">
                  <span class="flag">🇮🇳 +91</span>
                  <input type="tel" id="otpPhoneInput" inputmode="numeric" maxlength="10" placeholder="Enter your phone number" autocomplete="off">
                </div>
                <div class="field-error" id="otpPhoneError"></div>
                <button type="button" class="btn-pink mt-3 w-100" id="sendOtpBtn">Send OTP</button>
              </div>

              <!-- otp entry -->
              <div id="otpCodePane" style="display:none;">
                <div class="step-title">Enter the code</div>
                <div class="step-subtitle">Sent to <span class="phone-shown" id="otpPhoneShown"></span></div>
                <label class="form-label-hs">One-time code</label>
                <div class="otp-boxes">
                  <input type="text" inputmode="numeric" maxlength="1" class="otp-digit">
                  <input type="text" inputmode="numeric" maxlength="1" class="otp-digit">
                  <input type="text" inputmode="numeric" maxlength="1" class="otp-digit">
                  <input type="text" inputmode="numeric" maxlength="1" class="otp-digit">
                  <input type="text" inputmode="numeric" maxlength="1" class="otp-digit">
                  <input type="text" inputmode="numeric" maxlength="1" class="otp-digit">
                </div>
                <div class="field-error" id="otpCodeError"></div>
                <div class="otp-meta-row">
                  <span><button type="button" class="change-number-link" id="changeNumberBtn">Change number</button></span>
                  <button type="button" class="resend-link" id="resendOtpBtn" disabled>Resend in <span id="resendTimer">30</span>s</button>
                </div>
                <button type="button" class="btn-pink mt-3 w-100" id="verifyOtpBtn">Verify &amp; continue</button>
              </div>
            </div>
          </div>
        </div>
        <?php endif; // bookingAllowed (step 2) ?>

        <!-- ============ STEP 3: SUMMARY ============ -->
        <?php if ($bookingAllowed): ?>
        <div class="step-panel" id="stepPanel3">
          <button type="button" class="step-back" data-back="2"><i class="fa-solid fa-arrow-left"></i> Back to info</button>

          <!-- 3a: NEW USER — collect details (shown until saved) -->
          <div class="panel-card" id="newUserDetailsPanel" style="display:none;">
            <h6><i class="fa-solid fa-id-card me-1"></i> Tell us about you</h6>
            <div class="field-row">
              <label class="form-label-hs" for="nuName">Full name</label>
              <input type="text" class="form-control-hs" id="nuName" placeholder="Your full name" autocomplete="off">
              <div class="field-error" id="nuNameError">Enter your full name.</div>
            </div>
            <div class="field-row">
              <label class="form-label-hs" for="nuEmail">Email</label>
              <input type="email" class="form-control-hs" id="nuEmail" placeholder="you@example.com" autocomplete="off">
              <div class="field-error" id="nuEmailError">Enter a valid email address.</div>
            </div>
            <div class="row g-3">
              <div class="col-sm-6 field-row">
                <label class="form-label-hs" for="nuGender">Gender</label>
                <select class="form-select-hs" id="nuGender">
                  <option value="">Select</option>
                  <option value="Male">Male</option>
                  <option value="Female">Female</option>
                  <option value="Other">Other</option>
                </select>
                <div class="field-error" id="nuGenderError">Select a gender.</div>
              </div>
              <div class="col-sm-6 field-row">
                <label class="form-label-hs" for="nuCity">City</label>
                <input type="text" class="form-control-hs" id="nuCity" placeholder="Your city" autocomplete="off">
                <div class="field-error" id="nuCityError">Enter your city.</div>
              </div>
            </div>
            <div class="field-error" id="nuFormError"></div>
            <button type="button" class="btn-pink mt-3" id="saveNewUserBtn">Save &amp; continue</button>
          </div>

          <!-- 3b: EDITABLE booking-for panel (logged-in users; "someone else" option) -->
          <div class="panel-card" id="loggedInPreviewPanel" style="display:none;">
            <h6><i class="fa-solid fa-user me-1"></i> Who is this booking for?</h6>
            <div class="who-toggle">
              <label class="date-opt selected" id="whoMyselfLabel">
                <input type="radio" name="bookingFor" value="self" id="whoMyself" checked style="display:none;">
                Myself
              </label>
              <label class="date-opt" id="whoOtherLabel">
                <input type="radio" name="bookingFor" value="other" id="whoOther" style="display:none;">
                Someone else
              </label>
            </div>

            <div id="otherGuestFields" style="display:none;">
              <div class="field-row">
                <label class="form-label-hs" for="ogName">Guest's full name</label>
                <input type="text" class="form-control-hs" id="ogName" placeholder="Guest's full name" autocomplete="off">
              </div>
              <div class="field-row">
                <label class="form-label-hs" for="ogPhone">Guest's mobile number</label>
                <div class="phone-input-pill">
                  <span class="flag">🇮🇳 +91</span>
                  <input type="tel" id="ogPhone" inputmode="numeric" maxlength="10" placeholder="98765 43210" autocomplete="off">
                </div>
              </div>
              <div class="field-row">
                <label class="form-label-hs" for="ogEmail">Guest's email</label>
                <input type="email" class="form-control-hs" id="ogEmail" placeholder="guest@example.com" autocomplete="off">
              </div>
              <div class="field-row">
                <label class="form-label-hs" for="ogGender">Guest's gender</label>
                <select class="form-select-hs" id="ogGender">
                  <option value="">Select</option>
                  <option value="Male">Male</option>
                  <option value="Female">Female</option>
                  <option value="Other">Other</option>
                </select>
              </div>
            </div>
            <div class="field-error" id="guestDetailsError">Please add guest details to continue.</div>

            <button type="button" class="btn-pink mt-3" id="saveGuestChoiceBtn">Save</button>
          </div>

          <!-- 3c: READ-ONLY summary of who the ticket confirmation goes to -->
          <div class="panel-card" id="guestSummaryPanel" style="display:none;">
            <h6>Send ticket confirmation to <span class="edit-link" id="guestEditLink">Edit</span></h6>
            <div class="guest-summary-row"><b>Name:</b> <span id="gsName"></span></div>
            <div class="guest-summary-row"><b>Email:</b> <span id="gsEmail"></span></div>
            <div class="guest-summary-row"><b>Phone:</b> <span id="gsPhone"></span></div>
            <div class="mt-3">
              <label class="d-flex align-items-center gap-2" style="font-size:.82rem; color:var(--muted); cursor:pointer;">
                <input type="checkbox" id="authorizeConsent" checked style="accent-color:var(--pink); width:16px; height:16px;">
                I authorize HighSpinn to send notifications via SMS / RCS / Email / WhatsApp*
              </label>
            </div>
          </div>

          <!-- SELECTED PASSES -->
          <div class="panel-card">
            <h6>Selected passes</h6>
            <div id="passesSummaryList"></div>
            <div class="sp-total"><span>Total passes</span><span id="sumTotalPasses">0</span></div>
          </div>

          <!-- PRICING DETAILS -->
          <div class="panel-card">
            <h6>Details</h6>
            <div class="det-row"><span>Subtotal</span><span id="detSubtotal">&#8377;0</span></div>
            <div class="det-row"><span>Platform convenience fee</span><span id="detFee">&#8377;0</span></div>
            <div class="det-row"><span>Taxes / GST</span><span id="detTax">&#8377;0</span></div>
            <div class="det-total"><span>To pay</span><span id="detToPay">&#8377;0</span></div>
          </div>

          <!--
            PAYMENT (hidden automatically for free bookings)
            Razorpay's own checkout modal already lets the guest choose
            their exact payment method there, so we don't ask twice here.
          -->
          <div class="panel-card" id="paymentPanel" <?php echo $allTicketsFree ? 'style="display:none;"' : ''; ?>>
            <h6><i class="fa-solid fa-credit-card me-1"></i> Payment method</h6>
            <div class="free-notice">
              <span class="fn-icon"><i class="fa-solid fa-shield-halved"></i></span>
              <div>
                <h6>Pay securely via Razorpay</h6>
                <p>You'll choose UPI (GPay / PhonePe / Paytm), card, net banking, or wallet on Razorpay's secure checkout screen after you confirm.</p>
              </div>
            </div>
          </div>

          <!-- FREE EVENT NOTICE -->
          <div class="panel-card" id="freeNotice" <?php echo $allTicketsFree ? '' : 'style="display:none;"'; ?>>
            <div class="free-notice">
              <span class="fn-icon"><i class="fa-solid fa-circle-check"></i></span>
              <div>
                <h6>This is a free booking</h6>
                <p>No payment is required. Confirm your details and your e-passes will be issued instantly.</p>
              </div>
            </div>
          </div>

          <?php if ($eventNoteText !== ''): ?>
          <div class="event-note-box">
            <i class="fa-solid fa-circle-info"></i>
            <div><b>Event note</b><?php echo htmlspecialchars($eventNoteText); ?></div>
          </div>
          <?php endif; ?>

          <div class="terms-row">
            <input type="checkbox" id="termsCheckbox" checked>
            <label for="termsCheckbox">By proceeding, you agree to the <a href="/terms" target="_blank">Terms &amp; Conditions</a></label>
          </div>

          <a href="#" class="change-tickets-link" id="changeTicketsLink"><i class="fa-solid fa-arrow-left me-1"></i> Change tickets</a>
        </div>
        <?php endif; // bookingAllowed (step 3) ?>

      </form>
    </div>

    <?php endif; // eventIsOver ?>
  </div>
</div>

<?php if ($bookingAllowed): ?>
<!-- PERSISTENT BOTTOM BAR -->
<div class="wizard-bottom-bar" id="wizardBottomBar">
  <div class="wb-info">
    <span class="wb-main" id="wbMain">0 Selected</span>
    <span class="wb-sub" id="wbSub">Total &amp; offers on next step</span>
  </div>
  <button type="button" class="btn-pink" id="ctaBtn" disabled>Continue <i class="fa-solid fa-arrow-right ms-1"></i></button>
</div>
<?php elseif ($showTicketsPreviewOnly): ?>
<!-- PERSISTENT BOTTOM BAR (disabled preview state) -->
<div class="wizard-bottom-bar">
  <div class="wb-info">
    <span class="wb-main">Bookings not open</span>
    <span class="wb-sub">Check back soon</span>
  </div>
  <button type="button" class="btn-pink" disabled style="opacity:.5; cursor:not-allowed;">Not available</button>
</div>
<?php endif; ?>

<div id="loader" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); z-index:9999;">
    <div class="spinner-border text-primary" role="status">
        <span class="sr-only">Loading...</span>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const urlParams = new URLSearchParams(window.location.search);
    const status = urlParams.get("status");

    if (status) {
        const baseUrl = window.location.href.split("?")[0];
        let alertConfig = {
            icon: "error",
            title: "Payment Failed",
            text: "Your payment could not be processed. Please try again.",
            confirmButtonText: "Try Again",
            confirmButtonColor: "#d33"
        };

        if (status === "expired") {
            alertConfig = { icon: "warning", title: "Session Expired", text: "Your booking session expired. Please start again.", confirmButtonText: "Book Again" };
        } else if (status === "invalid") {
            alertConfig = { icon: "error", title: "Invalid Request", text: "Something went wrong. Please try booking again.", confirmButtonText: "Retry" };
        } else if (status === "db_error") {
            alertConfig = { icon: "error", title: "Server Error", text: "There was a problem saving your booking. Please try again later.", confirmButtonText: "Okay" };
        }

        Swal.fire(alertConfig).then(() => { window.location.href = baseUrl; });
    }
});
</script>

<?php if ($bookingAllowed): ?>
<script>
var HS_LOGGED_IN = <?php echo json_encode($isLoggedIn); ?>;
var HS_USER_ID    = <?php echo json_encode($isLoggedIn ? (int) $_SESSION['hs_user_id'] : null); ?>;
var HS_ACCOUNT = {
  name:   <?php echo json_encode($sessName); ?>,
  phone:  <?php echo json_encode($sessPhone); ?>,
  email:  <?php echo json_encode($sessEmail); ?>,
  gender: <?php echo json_encode($sessGender); ?>
};

// guestDetails is what actually gets submitted with the booking.
var guestDetails = HS_LOGGED_IN
  ? { is_self: true, name: HS_ACCOUNT.name, phone: HS_ACCOUNT.phone, email: HS_ACCOUNT.email, gender: HS_ACCOUNT.gender }
  : null;

(function(){
  var ticketData = {};
  var currentStep = 1;
  var resendInterval = null;

  function money(n){ return '&#8377;' + Math.round(n).toLocaleString('en-IN'); }
  function showLoader(){ document.querySelector('#loader').style.display = 'block'; }
  function hideLoader(){ document.querySelector('#loader').style.display = 'none'; }

  function selectedDates(){
    return Array.prototype.slice.call(document.querySelectorAll("input[name='selected_dates[]']:checked")).map(function(el){ return el.value; });
  }

  // ============================================================
  // COSMETIC SESSION COUNTDOWN (10:00) — purely visual, matches
  // the reference layout. Does not expire or invalidate anything
  // server-side; it just resets the display if it hits zero.
  // ============================================================
  (function sessionTimer(){
    var el = document.getElementById('sessionTimer');
    if (!el) return;
    var remaining = 10 * 60;
    setInterval(function(){
      remaining = remaining > 0 ? remaining - 1 : 10 * 60;
      var m = Math.floor(remaining / 60);
      var s = remaining % 60;
      el.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }, 1000);
  })();

  // ============================================================
  // STEP NAVIGATION
  // ============================================================
  function goToStep(n, opts){
    opts = opts || {};
    currentStep = n;
    [1,2,3].forEach(function(i){
      var panel = document.getElementById('stepPanel' + i);
      if (panel) panel.classList.toggle('active', i === n);
      var tab = document.getElementById('stepTab' + i);
      if (tab){
        tab.classList.toggle('active', i === n);
        tab.classList.toggle('done', i < n);
      }
    });
    updateBottomBar();
    if (!opts.silent) window.scrollTo({ top: 0, behavior: 'smooth' });
    if (n === 3) prepareStep3();
  }

  document.querySelectorAll('.step-back').forEach(function(btn){
    btn.addEventListener('click', function(){ goToStep(parseInt(btn.getAttribute('data-back'), 10)); });
  });

  var changeTicketsLink = document.getElementById('changeTicketsLink');
  if (changeTicketsLink) changeTicketsLink.addEventListener('click', function(e){ e.preventDefault(); goToStep(1); });

  // ============================================================
  // STEP 1: dates + passes
  // ============================================================
  document.querySelectorAll('.date-opt').forEach(function(opt){
    if (opt.closest('#stepPanel3')) return; // the who-picker reuses .date-opt styling, wired separately
    var box = opt.querySelector('input');
    if (!box) return;
    box.addEventListener('change', function(){
      opt.classList.toggle('selected', box.checked);
      document.getElementById('datesError') && document.getElementById('datesError').classList.remove('show');
      updateSummary();
    });
  });

  var selectAllBtn = document.getElementById('selectAllDates');
  if (selectAllBtn) {
    selectAllBtn.addEventListener('click', function(){
      var boxes = document.querySelectorAll("input[name='selected_dates[]']");
      var allChecked = Array.prototype.every.call(boxes, function(b){ return b.checked; });
      boxes.forEach(function(b){
        b.checked = !allChecked;
        b.dispatchEvent(new Event('change'));
      });
      selectAllBtn.textContent = allChecked ? 'Select all' : 'Clear all';
    });
  }

  document.querySelectorAll('.pass-row').forEach(function(row){
    var addBtn = row.querySelector('.btn-add-pass');
    var stepper = row.querySelector('.qty-stepper');
    if (!addBtn || !stepper) return;

    var minus = row.querySelector('.qty-minus');
    var plus = row.querySelector('.qty-plus');
    var valEl = row.querySelector('.qty-val');
    var id = row.getAttribute('data-id');
    var name = row.getAttribute('data-name');
    var price = parseFloat(row.getAttribute('data-price'));
    var leftAttr = row.getAttribute('data-left');
    var maxQty = (leftAttr !== null && leftAttr !== '') ? Math.min(10, parseInt(leftAttr, 10)) : 10;

    if (addBtn.disabled) return; // sold out

    function setQty(v){
      valEl.textContent = v;
      minus.disabled = v <= 0;
      plus.disabled = v >= maxQty;
      if (v > 0) {
        ticketData[id] = { name: name, cost: price, qty: v };
        addBtn.style.display = 'none';
        stepper.style.display = 'flex';
      } else {
        delete ticketData[id];
        stepper.style.display = 'none';
        addBtn.style.display = '';
      }
      document.getElementById('passesError').classList.remove('show');
      updateSummary();
    }

    // expose so restoreSelections() can reuse the same per-row setter
    row.__hsSetQty = setQty;

    addBtn.addEventListener('click', function(){
      if (selectedDates().length === 0 && document.querySelectorAll("input[name='selected_dates[]']").length > 1){
        Swal.fire({ icon: 'error', title: 'Select Date First', text: 'Please select at least one date before choosing passes.' });
        return;
      }
      setQty(1);
    });
    plus.addEventListener('click', function(){
      var v = parseInt(valEl.textContent, 10);
      if (v >= maxQty){
        Swal.fire({ icon: 'error', title: 'Oops...', text: 'You cannot select more than ' + maxQty + ' pass(es) of this type!' });
        return;
      }
      setQty(v + 1);
    });
    minus.addEventListener('click', function(){
      var v = parseInt(valEl.textContent, 10);
      if (v <= 0) return;
      setQty(v - 1);
    });
  });

  function validateStep1(){
    var ok = true;
    var dateInputs = document.querySelectorAll("input[name='selected_dates[]']");
    var dates = selectedDates();
    if (dateInputs.length > 1 && dates.length === 0){
      var de = document.getElementById('datesError');
      if (de) de.classList.add('show');
      ok = false;
    }
    if (Object.keys(ticketData).length === 0){
      document.getElementById('passesError').classList.add('show');
      ok = false;
    } else {
      document.getElementById('passesError').classList.remove('show');
    }
    return ok;
  }

  // ============================================================
  // STEP 2: mobile + OTP
  // ============================================================
  var otpPhonePane = document.getElementById('otpPhonePane');
  var otpCodePane  = document.getElementById('otpCodePane');
  var otpPhoneInput = document.getElementById('otpPhoneInput');
  var otpDigits = Array.prototype.slice.call(document.querySelectorAll('.otp-digit'));

  otpPhoneInput.addEventListener('input', function(){ this.value = this.value.replace(/\D/g,'').slice(0,10); });

  otpDigits.forEach(function(el, i){
    el.addEventListener('input', function(){
      el.value = el.value.replace(/\D/g,'').slice(0,1);
      if (el.value && otpDigits[i+1]) otpDigits[i+1].focus();
    });
    el.addEventListener('keydown', function(e){
      if (e.key === 'Backspace' && !el.value && otpDigits[i-1]) otpDigits[i-1].focus();
    });
    el.addEventListener('paste', function(e){
      var text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
      if (!text) return;
      e.preventDefault();
      text.split('').forEach(function(ch, idx){ if (otpDigits[idx]) otpDigits[idx].value = ch; });
      var last = Math.min(text.length, 6) - 1;
      if (otpDigits[last]) otpDigits[last].focus();
    });
  });

  function getOtpValue(){ return otpDigits.map(function(d){ return d.value; }).join(''); }
  function clearOtpDigits(){ otpDigits.forEach(function(d){ d.value = ''; }); if (otpDigits[0]) otpDigits[0].focus(); }

  function startResendTimer(){
    var btn = document.getElementById('resendOtpBtn');
    var timerEl = document.getElementById('resendTimer');
    var t = 30;
    btn.disabled = true;
    if (resendInterval) clearInterval(resendInterval);
    resendInterval = setInterval(function(){
      t--;
      timerEl.textContent = t;
      if (t <= 0){
        clearInterval(resendInterval);
        btn.disabled = false;
        btn.textContent = 'Resend OTP';
      }
    }, 1000);
  }

  document.getElementById('sendOtpBtn').addEventListener('click', function(){
    var phone = otpPhoneInput.value.trim();
    var errEl = document.getElementById('otpPhoneError');
    if (!/^[6-9]\d{9}$/.test(phone)){
      errEl.textContent = 'Enter a valid 10-digit mobile number.';
      errEl.classList.add('show');
      return;
    }
    errEl.classList.remove('show');
    showLoader();
    $.post('/auth/send_otp.php', { phone: phone }, function(resp){
      hideLoader();
      if (typeof resp === 'string'){ try { resp = JSON.parse(resp); } catch(e){ resp = { success:false, message:'Unexpected server response.' }; } }
      if (resp && resp.success){
        otpPhonePane.style.display = 'none';
        otpCodePane.style.display = '';
        document.getElementById('otpPhoneShown').textContent = '+91 ' + phone;
        clearOtpDigits();
        startResendTimer();
      } else {
        errEl.textContent = (resp && resp.message) ? resp.message : 'Could not send OTP. Please try again.';
        errEl.classList.add('show');
      }
    }, 'json').fail(function(){ hideLoader(); errEl.textContent = 'Could not send OTP. Please try again.'; errEl.classList.add('show'); });
  });

  document.getElementById('changeNumberBtn').addEventListener('click', function(){
    if (resendInterval) clearInterval(resendInterval);
    otpCodePane.style.display = 'none';
    otpPhonePane.style.display = '';
  });

  document.getElementById('resendOtpBtn').addEventListener('click', function(){
    var phone = otpPhoneInput.value.trim();
    showLoader();
    $.post('/auth/send_otp.php', { phone: phone }, function(resp){
      hideLoader();
      if (typeof resp === 'string'){ try { resp = JSON.parse(resp); } catch(e){ resp = { success:false }; } }
      if (resp && resp.success){
        clearOtpDigits();
        document.getElementById('resendOtpBtn').innerHTML = 'Resend in <span id="resendTimer">30</span>s';
        startResendTimer();
      } else {
        Swal.fire({ icon: 'error', title: 'Could not resend', text: (resp && resp.message) ? resp.message : 'Please try again.' });
      }
    }, 'json').fail(function(){ hideLoader(); });
  });

  document.getElementById('verifyOtpBtn').addEventListener('click', function(){
    var phone = otpPhoneInput.value.trim();
    var otp = getOtpValue();
    var errEl = document.getElementById('otpCodeError');
    if (otp.length !== 6){
      errEl.textContent = 'Enter the complete 6-digit code.';
      errEl.classList.add('show');
      return;
    }
    errEl.classList.remove('show');
    showLoader();
    $.post('/auth/verify_otp.php', { phone: phone, otp: otp }, function(resp){
      hideLoader();
      if (typeof resp === 'string'){ try { resp = JSON.parse(resp); } catch(e){ resp = { success:false, message:'Unexpected server response.' }; } }
      if (!resp || !resp.success){
        errEl.textContent = (resp && resp.message) ? resp.message : 'Incorrect OTP. Please try again.';
        errEl.classList.add('show');
        return;
      }
      if (resp.status === 'logged_in'){
        // Session is now authenticated server-side. Reload so PHP re-renders
        // with the logged-in session (fname/email/gender/city prefilled),
        // then resume the wizard right where the guest left off.
        // NOTE: a full reload wipes in-memory JS state (ticketData, checked
        // date boxes) — stash them in sessionStorage so they survive.
        sessionStorage.setItem('hs_resume_step', '3');
        sessionStorage.setItem('hs_selected_tickets', JSON.stringify(ticketData));
        sessionStorage.setItem('hs_selected_dates', JSON.stringify(selectedDates()));
        window.location.reload();
        return;
      }
      if (resp.status === 'need_details'){
        sessionStorage.setItem('hs_resume_step', '3');
        sessionStorage.setItem('hs_verified_phone', phone);
        sessionStorage.setItem('hs_prefill', JSON.stringify(resp.prefill || {}));
        goToStep(3);
        return;
      }
    }, 'json').fail(function(){ hideLoader(); errEl.textContent = 'Something went wrong. Please try again.'; errEl.classList.add('show'); });
  });

  // ============================================================
  // RESTORE TICKET/DATE SELECTIONS AFTER A LOGIN RELOAD
  // ============================================================
  function restoreSelections(){
    var savedTickets = sessionStorage.getItem('hs_selected_tickets');
    if (savedTickets){
      try {
        var saved = JSON.parse(savedTickets);
        for (var tid in saved){
          var row = document.querySelector('.pass-row[data-id="' + tid + '"]');
          if (row && row.__hsSetQty){
            // reuse the row's own setQty so ticketData, button/stepper
            // visibility, and error states all stay in sync
            row.__hsSetQty(saved[tid].qty);
          } else if (row){
            // fallback: row has no setQty (e.g. sold out mid-flow) — just
            // restore the raw data so pricing/summary still reflects it
            ticketData[tid] = saved[tid];
          }
        }
      } catch(e){}
      sessionStorage.removeItem('hs_selected_tickets');
    }

    var savedDates = sessionStorage.getItem('hs_selected_dates');
    if (savedDates){
      try {
        var dates = JSON.parse(savedDates);
        dates.forEach(function(d){
          var input = document.querySelector("input[name='selected_dates[]'][value='" + d + "']");
          if (input){
            input.checked = true;
            var opt = input.closest('.date-opt');
            if (opt) opt.classList.add('selected');
          }
        });
      } catch(e){}
      sessionStorage.removeItem('hs_selected_dates');
    }
  }

  // ============================================================
  // STEP 3: new-user details OR logged-in editable panel OR
  // read-only summary (once details are known/confirmed)
  // ============================================================
  var newUserPanel    = document.getElementById('newUserDetailsPanel');
  var editPanel       = document.getElementById('loggedInPreviewPanel');
  var summaryPanel     = document.getElementById('guestSummaryPanel');
  var guestEditLink    = document.getElementById('guestEditLink');
  var saveGuestChoiceBtn = document.getElementById('saveGuestChoiceBtn');

  function renderGuestSummary(){
    document.getElementById('gsName').textContent  = (guestDetails && guestDetails.name)  || '—';
    document.getElementById('gsEmail').textContent = (guestDetails && guestDetails.email) || '—';
    document.getElementById('gsPhone').textContent = (guestDetails && guestDetails.phone) || '—';
  }

  function showSummaryView(){
    newUserPanel.style.display = 'none';
    editPanel.style.display = 'none';
    summaryPanel.style.display = '';
    renderGuestSummary();
  }

  function showEditView(){
    summaryPanel.style.display = 'none';
    if (HS_LOGGED_IN){
      editPanel.style.display = '';
    } else {
      newUserPanel.style.display = '';
    }
  }

  if (guestEditLink) guestEditLink.addEventListener('click', showEditView);

  function prepareStep3(){
    if (HS_LOGGED_IN){
      if (guestDetails && guestDetails.name){
        showSummaryView();
      } else {
        showEditView();
      }
    } else {
      // brand-new guest: always start on the details form
      newUserPanel.style.display = '';
      editPanel.style.display = 'none';
      summaryPanel.style.display = 'none';
      var raw = sessionStorage.getItem('hs_prefill');
      if (raw){
        try {
          var p = JSON.parse(raw);
          document.getElementById('nuName').value   = p.fname ? (p.fname + (p.lname ? ' ' + p.lname : '')) : '';
          document.getElementById('nuEmail').value  = p.email  || '';
          document.getElementById('nuGender').value = p.gender || '';
          document.getElementById('nuCity').value   = p.city   || '';
        } catch(e){}
      }
    }
    updateSummary();
  }

  // ---- someone-else toggle (logged-in users only) ----
  var myselfLabel = document.getElementById('whoMyselfLabel');
  var otherLabel  = document.getElementById('whoOtherLabel');
  var myselfRadio = document.getElementById('whoMyself');
  var otherRadio  = document.getElementById('whoOther');
  var otherFields = document.getElementById('otherGuestFields');

  function selectWho(who){
    myselfLabel.classList.toggle('selected', who === 'self');
    otherLabel.classList.toggle('selected', who === 'other');
    myselfRadio.checked = (who === 'self');
    otherRadio.checked  = (who === 'other');
    otherFields.style.display = (who === 'other') ? '' : 'none';
    document.getElementById('guestDetailsError').classList.remove('show');
  }
  myselfLabel.addEventListener('click', function(){ selectWho('self'); });
  otherLabel.addEventListener('click', function(){ selectWho('other'); });

  var ogPhone = document.getElementById('ogPhone');
  if (ogPhone) ogPhone.addEventListener('input', function(){ this.value = this.value.replace(/\D/g,'').slice(0,10); });

  function collectOtherGuestDetails(){
    var n = document.getElementById('ogName').value.trim();
    var p = document.getElementById('ogPhone').value.trim();
    var e = document.getElementById('ogEmail').value.trim();
    var g = document.getElementById('ogGender').value;
    var errEl = document.getElementById('guestDetailsError');

    if (n.length < 2){ errEl.textContent = "Enter the guest's full name."; errEl.classList.add('show'); return null; }
    if (!/^[6-9]\d{9}$/.test(p)){ errEl.textContent = 'Enter a valid 10-digit mobile number.'; errEl.classList.add('show'); return null; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e)){ errEl.textContent = 'Enter a valid email address.'; errEl.classList.add('show'); return null; }
    if (!g){ errEl.textContent = 'Select a gender.'; errEl.classList.add('show'); return null; }

    errEl.classList.remove('show');
    return { is_self: false, name: n, phone: p, email: e, gender: g };
  }

  if (saveGuestChoiceBtn) saveGuestChoiceBtn.addEventListener('click', function(){
    if (otherRadio.checked){
      var og = collectOtherGuestDetails();
      if (!og) return;
      guestDetails = og;
    } else {
      guestDetails = { is_self: true, name: HS_ACCOUNT.name, phone: HS_ACCOUNT.phone, email: HS_ACCOUNT.email, gender: HS_ACCOUNT.gender };
    }
    showSummaryView();
    updateCtaButton();
  });

  // ---- new-user "save & continue" ----
  document.getElementById('saveNewUserBtn').addEventListener('click', function(){
    var name   = document.getElementById('nuName').value.trim();
    var email  = document.getElementById('nuEmail').value.trim();
    var gender = document.getElementById('nuGender').value;
    var city   = document.getElementById('nuCity').value.trim();
    document.getElementById('nuNameError').classList.toggle('show', name.length < 2);
    document.getElementById('nuEmailError').classList.toggle('show', !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email));
    document.getElementById('nuGenderError').classList.toggle('show', !gender);
    document.getElementById('nuCityError').classList.toggle('show', city.length < 2);
    document.getElementById('nuFormError').textContent = '';

    if (name.length < 2 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || !gender || city.length < 2) return;

    var phone = sessionStorage.getItem('hs_verified_phone') || otpPhoneInput.value.trim();

    showLoader();
    $.post('/auth/complete_profile.php', {
      phone: phone, name: name, email: email, gender: gender, city: city
    }, function(resp){
      hideLoader();
      if (typeof resp === 'string'){ try { resp = JSON.parse(resp); } catch(e){ resp = { success:false, message:'Unexpected server response.' }; } }
      if (!resp || !resp.success){
        document.getElementById('nuFormError').textContent = (resp && resp.message) ? resp.message : 'Could not save your details. Please try again.';
        document.getElementById('nuFormError').classList.add('show');
        return;
      }
      // Profile saved and session is now authenticated — reload so PHP
      // re-renders logged-in, then resume straight at the confirm step.
      // Preserve ticket/date selections across this reload too.
      sessionStorage.setItem('hs_resume_step', '3');
      sessionStorage.setItem('hs_selected_tickets', JSON.stringify(ticketData));
      sessionStorage.setItem('hs_selected_dates', JSON.stringify(selectedDates()));
      window.location.reload();
    }, 'json').fail(function(){
      hideLoader();
      document.getElementById('nuFormError').textContent = 'Could not save your details. Please try again.';
      document.getElementById('nuFormError').classList.add('show');
    });
  });

  // ============================================================
  // SUMMARY LISTS + BOTTOM BAR (drives step transitions + submit)
  // ============================================================
  var lastKnownTotal = 0;

  function updateCtaButton(){
    var cta = document.getElementById('ctaBtn');
    var hasPasses = Object.keys(ticketData).length > 0;
    if (currentStep === 1){
      cta.disabled = !hasPasses;
      cta.innerHTML = 'Continue <i class="fa-solid fa-arrow-right ms-1"></i>';
    } else if (currentStep === 2){
      cta.disabled = true; // action happens inline in the OTP form
      cta.innerHTML = 'Verify to continue';
    } else {
      var termsOk = document.getElementById('termsCheckbox').checked;
      var detailsReady = summaryPanel.style.display !== 'none';
      cta.disabled = !termsOk || !detailsReady;
      cta.innerHTML = lastKnownTotal > 0
        ? ('Confirm &amp; pay ' + money(lastKnownTotal) + ' <i class="fa-solid fa-arrow-right ms-1"></i>')
        : 'Confirm order <i class="fa-solid fa-arrow-right ms-1"></i>';
    }
  }

  function updateBottomBar(){
    var wbMain = document.getElementById('wbMain');
    var wbSub  = document.getElementById('wbSub');
    var count = 0;
    for (var tid in ticketData) count += ticketData[tid].qty;

    if (currentStep === 3){
      wbMain.textContent = count + (count === 1 ? ' Ticket' : ' Tickets');
      wbSub.innerHTML = money(lastKnownTotal);
    } else {
      wbMain.textContent = count + ' Selected';
      wbSub.textContent = 'Total & offers on next step';
    }
    updateCtaButton();
  }

  var termsCheckbox = document.getElementById('termsCheckbox');
  if (termsCheckbox) termsCheckbox.addEventListener('change', updateCtaButton);

  function updateSummary(){
    var numDates = selectedDates().length || 1;
    var total = 0;
    var passesHtml = '';
    var totalPasses = 0;

    for (var tid in ticketData){
      var t = ticketData[tid];
      if (t.qty > 0){
        var lineTotal = t.qty * t.cost * numDates;
        total += lineTotal;
        totalPasses += t.qty * numDates;
        passesHtml += '<div class="sp-row"><span>' + t.name + ' (' + (t.cost === 0 ? 'Free' : money(t.cost)) + ') &times; ' + numDates + ' day(s)</span><span>' + t.qty + '</span></div>';
      }
    }

    var passesSummaryList = document.getElementById('passesSummaryList');
    var sumTotalPasses = document.getElementById('sumTotalPasses');
    if (passesSummaryList) passesSummaryList.innerHTML = passesHtml || '<p class="text-muted-c2 small mb-0">No passes selected yet.</p>';
    if (sumTotalPasses) sumTotalPasses.textContent = totalPasses;

    var detSubtotal = document.getElementById('detSubtotal');
    var detFee = document.getElementById('detFee');
    var detTax = document.getElementById('detTax');
    var detToPay = document.getElementById('detToPay');
    if (detSubtotal) detSubtotal.innerHTML = money(total);
    if (detFee) detFee.innerHTML = money(0);
    if (detTax) detTax.innerHTML = money(0);
    if (detToPay) detToPay.innerHTML = money(total);

    var paymentPanel = document.getElementById('paymentPanel');
    var freeNotice = document.getElementById('freeNotice');

    lastKnownTotal = total;

    if (total === 0){
      if (paymentPanel) paymentPanel.style.display = 'none';
      if (freeNotice) freeNotice.style.display = '';
    } else {
      if (paymentPanel) paymentPanel.style.display = '';
      if (freeNotice) freeNotice.style.display = 'none';
    }

    updateBottomBar();
    return total;
  }

  // ---- the single bottom-bar CTA drives the whole wizard ----
  document.getElementById('ctaBtn').addEventListener('click', function(){
    if (currentStep === 1){
      if (!validateStep1()) return;
      if (HS_LOGGED_IN){
        goToStep(3);
      } else {
        goToStep(2);
      }
      return;
    }

    if (currentStep === 2){
      return; // nothing to do — verifyOtpBtn handles this step
    }

    submitBooking();
  });

  function submitBooking(){
    var ok = true;

    if (!HS_LOGGED_IN){
      Swal.fire({ icon: 'error', title: 'Please verify your mobile', text: 'Go back and verify your mobile number to continue.' });
      return;
    }

    if (!guestDetails || summaryPanel.style.display === 'none'){
      document.getElementById('guestDetailsError').classList.add('show');
      showEditView();
      return;
    }

    if (!document.getElementById('termsCheckbox').checked){
      Swal.fire({ icon: 'error', title: 'Please accept the terms', text: 'You need to agree to the Terms & Conditions to continue.' });
      return;
    }

    if (!validateStep1()) ok = false;

    if (Object.keys(ticketData).length === 0){
      document.getElementById('passesError').classList.add('show');
      ok = false;
    }

    if (!ok) return;

    var dateInputs = document.querySelectorAll("input[name='selected_dates[]']");
    var dates = selectedDates();
    var eventId = document.getElementById('event_id').value;
    var numDates = dates.length || 1;
    var totalQuantity = 0, price = 0;
    for (var tid in ticketData){
      totalQuantity += ticketData[tid].qty * numDates;
      price += ticketData[tid].qty * ticketData[tid].cost * numDates;
    }

    if (totalQuantity === 0){
      Swal.fire({ icon: 'error', title: 'No Tickets Selected', text: 'Please select at least one ticket.' });
      return;
    }

    showLoader();

    var postDates = dates.length ? dates : (dateInputs.length ? [dateInputs[0].value] : []);
    var name = guestDetails.name, phone = guestDetails.phone, email = guestDetails.email, gender = guestDetails.gender;
    var isSelf = guestDetails.is_self ? 1 : 0;

    // Free ticket flow — no payment gateway involved at all
    if (price === 0){
      $.post('/data/data.php', {
        price: price, name: name, email: email, phone: phone, gender: gender, is_self: isSelf,
        event_id: eventId,
        selected_dates: JSON.stringify(postDates),
        tickets: JSON.stringify(ticketData),
        payment_id: 'FREE'
      }, function(resp){
        hideLoader();
        if (typeof resp === 'string'){
          try { resp = JSON.parse(resp); }
          catch(e){ Swal.fire('Error', 'Unexpected server response. Please try again.', 'error'); return; }
        }
        if (resp && resp.success && resp.pay_ref){
          window.location.replace('/ticket/' + resp.pay_ref);
        } else {
          Swal.fire({ icon: 'error', title: 'Booking Failed', text: (resp && resp.message) ? resp.message : 'Please try again.' });
        }
      }, 'json').fail(function(){ hideLoader(); Swal.fire('Error', 'Unable to complete free booking. Please try again.', 'error'); });
      return;
    }

    // Paid tickets: validate price with server first, then open Razorpay checkout
    $.ajax({
      url: '/data/validate_price.php',
      type: 'POST',
      data: {
        event_id: eventId,
        selected_dates: JSON.stringify(postDates),
        tickets: JSON.stringify(ticketData),
        client_price: price
      },
      success: function(response){
        hideLoader();

        if (!response.valid){
          if (response.unavailable_tickets && response.unavailable_tickets.length > 0){
            Swal.fire({
              icon: 'error',
              title: 'Tickets Unavailable',
              html: 'Some tickets are out of stock:<br><ul>' +
                    response.unavailable_tickets.map(function(t){ return '<li>' + t + '</li>'; }).join('') +
                    '</ul>'
            });
          } else {
            Swal.fire({ icon: 'error', title: 'Validation Failed', text: response.message });
          }
          return;
        }

        var options = {
          "key": "rzp_live_kBxy0MOsE2nQSa",
          "amount": Math.round(price * 100),
          "currency": "INR",
          "name": "Highspinn",
                    "webview_intent": true,

          "description": "Highspinn Event Payment",
          "image": "https://www.highspinn.com/images/logo.png",
          "handler": function(rzpResponse){
            showLoader();
            $.post('/data/data.php', {
              price: price,
              name: name, email: email, phone: phone, gender: gender, is_self: isSelf,
              event_id: eventId,
              selected_dates: JSON.stringify(postDates),
              tickets: JSON.stringify(ticketData),
              payment_id: rzpResponse.razorpay_payment_id
            }, function(resp){
              hideLoader();
              if (typeof resp === 'string'){
                try { resp = JSON.parse(resp); }
                catch(e){ Swal.fire('Error', 'Unexpected server response. Please try again.', 'error'); return; }
              }
              if (resp && resp.success && resp.pay_ref){
                window.location.replace('/ticket/' + resp.pay_ref);
              } else {
                Swal.fire({ icon: 'error', title: 'Booking Failed', text: (resp && resp.message) ? resp.message : 'Payment was captured but we could not confirm your booking. Please contact support with payment ID: ' + rzpResponse.razorpay_payment_id });
              }
            }, 'json').fail(function(){
              hideLoader();
              Swal.fire({ icon: 'error', title: 'Booking Failed', text: 'Payment was captured but we could not confirm your booking. Please contact support with payment ID: ' + rzpResponse.razorpay_payment_id });
            });
          },
          "prefill": { "name": name, "email": email, "contact": phone },
          "theme": { "color": "#ff3d68" },
          "modal": { "ondismiss": function(){ hideLoader(); } }
        };

        var rzp1 = new Razorpay(options);
        rzp1.on('payment.failed', function(rzpResponse){
          hideLoader();
          Swal.fire('Payment Failed', rzpResponse.error && rzpResponse.error.description ? rzpResponse.error.description : 'Please try again!', 'error');
        });
        rzp1.open();
      },
      error: function(){
        hideLoader();
        Swal.fire('Error', 'Unable to validate price. Please try again.', 'error');
      }
    });
  }

  // ---- resume the wizard after a login/registration reload ----
  var resumeStep = sessionStorage.getItem('hs_resume_step');
  if (resumeStep){
    sessionStorage.removeItem('hs_resume_step');
    restoreSelections();
    goToStep(parseInt(resumeStep, 10) || 1, { silent: true });
  }

  updateSummary();
})();
</script>
<?php endif; ?>

<?php include 'include/footer.php'; ?>