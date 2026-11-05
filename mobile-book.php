<?php
/**
 * booking-mobile.php — app-style checkout.
 * Same data + JS as desktop (booking-data / booking-script); only layout differs.
 */
// top of file
include __DIR__ . '/include/booking-data.php';
include 'include/header.php';
include __DIR__ . '/include/booking-guest.php';
$eventUrl = hs_city_url('events/' . $row['url']);
?>

<style>
body.hs-app-view .navbar-hs,
body.hs-app-view footer { display:none !important; }
body.hs-app-view { padding-bottom:96px; }

.hs-app{ max-width:480px; margin:0 auto; }
.hidden{ display:none !important; }

/* ---- sticky app header: back + title + timer, stepper below ---- */
.bk-header{
  position:sticky; top:0; z-index:40; padding:12px 16px 10px;
  background:rgba(10,10,13,.95); backdrop-filter:blur(14px); border-bottom:1px solid var(--line);
}
.bk-top{ display:flex; align-items:center; gap:12px; }
.bk-back{
  width:36px; height:36px; border-radius:50%; flex:none; display:flex; align-items:center; justify-content:center;
  background:var(--panel-2); border:1px solid var(--line); color:var(--text);
}
.bk-title{ font-size:16px; font-weight:800; flex:1; margin:0; }
.session-note{ font-size:11px; color:var(--muted-2); margin:0; }
.session-note b{ color:var(--pink); }

.book-steps{ display:flex; align-items:center; justify-content:center; gap:8px; margin-top:10px; }
.book-step{ display:flex; align-items:center; gap:6px; font-size:11.5px; font-weight:700; color:var(--muted-2); }
.book-step .num{
  width:22px; height:22px; border-radius:50%; background:var(--panel-2); border:1px solid var(--line);
  display:flex; align-items:center; justify-content:center; font-size:11px; color:var(--muted);
}
.book-step.active{ color:var(--text); }
.book-step.active .num{ background:var(--pink); border-color:var(--pink); color:#fff; }
.book-step.done .num{ background:var(--green); border-color:var(--green); color:#0a0a0d; }
.book-step.done .num::before{ content:"\f00c"; font-family:"Font Awesome 6 Free"; font-weight:900; font-size:10px; }
.book-step.done .num span{ display:none; }
.book-step-sep{ width:22px; height:1px; background:var(--line); }

.bk-body{ padding:14px 16px 8px; }

/* ---- event strip ---- */
.evt-strip{ display:flex; align-items:center; gap:12px; background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:10px; margin-bottom:14px; }
.evt-strip .thumb{ width:56px; height:56px; border-radius:10px; flex:none; background:var(--panel-2) center/cover; }
.evt-strip h5{ font-size:13.5px; font-weight:800; line-height:1.25; margin:0 0 4px; }
.evt-strip .meta{ font-size:11px; color:var(--muted-2); display:flex; flex-direction:column; gap:2px; }
.evt-strip .link-arrow{ font-size:12px; font-weight:800; color:var(--pink); flex:none; }

/* ---- cards ---- */
.panel-card{ background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:14px; }
.panel-card + .panel-card, .event-note-box{ margin-top:12px; }
.panel-card h6{ font-size:11px; font-weight:800; letter-spacing:.07em; text-transform:uppercase; color:var(--muted-2); margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; gap:8px; }
.panel-card h6 .edit-link{ font-size:12px; font-weight:700; letter-spacing:0; text-transform:none; color:var(--pink); cursor:pointer; }

.step-panel{ display:none; animation:fadeSlide .3s ease; }
.step-panel.active{ display:block; }
@keyframes fadeSlide{ from{opacity:0; transform:translateY(8px);} to{opacity:1; transform:none;} }
.step-title{ font-size:18px; font-weight:800; margin-bottom:4px; }
.step-subtitle{ font-size:12.5px; color:var(--muted); margin-bottom:16px; }
.step-back{ font-size:12px; font-weight:700; color:var(--muted); background:none; border:none; padding:0; margin-bottom:12px; display:inline-flex; align-items:center; gap:6px; }

/* ---- dates: horizontal scroll chips ---- */
.date-head-row{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.date-head-row h6{ margin:0; }
.select-all-link{ font-size:12px; font-weight:700; color:var(--pink); background:none; border:none; padding:0; }
.date-scroll{
  display:grid;
  grid-template-columns:repeat(2, 1fr);
  gap:8px;
}
.date-scroll::-webkit-scrollbar{ display:none; }
.date-opt{
  border:1px solid var(--line); border-radius:12px; padding:11px 24px; font-size:12px; font-weight:700;
  display:flex; align-items:center; gap:8px; color:var(--muted); cursor:pointer; margin:0;
  min-width:0; line-height:1.3;
}
.date-opt input{ accent-color:var(--pink); flex:none; width:16px; height:16px; }
.date-opt.selected{ border-color:var(--pink); background:rgba(255,61,104,.08); color:var(--text); }
/* ---- passes ---- */
.pass-row{ display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:14px 0; border-bottom:1px solid var(--line); }
.pass-row:first-child{ padding-top:0; }
.pass-row:last-child{ border-bottom:none; padding-bottom:0; }
.pass-row.sold-out{ opacity:.5; }
.pass-info{ flex:1; min-width:0; }
.pass-info h6{ font-size:14px; font-weight:800; color:var(--text); text-transform:none; letter-spacing:0; margin:0 0 3px; display:flex; flex-wrap:wrap; align-items:center; gap:6px; }
.pass-price{ font-size:13px; font-weight:800; color:var(--yellow); margin-bottom:4px; }
.pass-info p{ font-size:12px; color:var(--muted); margin:0; line-height:1.45; }
.sold-badge{ font-size:9.5px; font-weight:800; color:var(--pink); background:rgba(255,61,104,.12); padding:3px 8px; border-radius:100px; text-transform:uppercase; }
.stock-note{ font-size:11px; font-weight:700; color:var(--orange); margin-top:6px; display:flex; align-items:center; gap:6px; }
.stock-note .dot{ width:5px; height:5px; border-radius:50%; background:var(--orange); animation:pulse 1.4s infinite; }
@keyframes pulse{ 0%,100%{opacity:1;} 50%{opacity:.3;} }
.pass-controls{ flex:none; }
.btn-add-pass{ background:transparent; border:1.5px solid var(--green); color:var(--green); font-weight:800; font-size:12px; padding:8px 20px; border-radius:100px; text-transform:uppercase; }
.btn-add-pass:disabled{ opacity:.4; color:var(--muted-2); border-color:var(--line); }
.qty-stepper{ align-items:center; border:1px solid var(--line); border-radius:100px; overflow:hidden; }
.qty-stepper button{ width:36px; height:36px; background:var(--panel-2); border:none; color:var(--text); font-size:17px; display:flex; align-items:center; justify-content:center; }
.qty-stepper button:first-child{ color:var(--pink); }
.qty-stepper button:disabled{ opacity:.35; }
.qty-stepper .qty-val{ width:32px; text-align:center; font-weight:800; font-size:14px; }

/* ---- forms (16px font stops iOS zoom-on-focus) ---- */
.form-label-hs{ font-size:12px; font-weight:700; color:var(--muted); margin-bottom:6px; display:block; }
.form-control-hs, .form-select-hs{
  width:100%; background:var(--void); border:1px solid var(--line); color:var(--text);
  border-radius:12px; padding:12px 14px; font-size:16px; outline:none;
}
.form-control-hs:focus, .form-select-hs:focus{ border-color:var(--pink); }
.field-error{ font-size:11.5px; color:var(--pink); margin-top:6px; display:none; }
.field-error.show{ display:block; }
.field-row{ margin-bottom:12px; }
.btn-pink.w-100{ padding:14px; }

.phone-input-pill{ display:flex; align-items:center; gap:10px; background:var(--void); border:1px solid var(--line); border-radius:12px; padding:0 14px; }
.phone-input-pill:focus-within{ border-color:var(--pink); }
.phone-input-pill .flag{ font-size:13px; color:var(--muted); flex:none; }
.phone-input-pill input{ border:none; background:transparent; color:var(--text); outline:none; padding:13px 0; font-size:16px; width:100%; }

.otp-boxes{ display:flex; gap:8px; margin:4px 0; }
.otp-boxes input{ flex:1; min-width:0; height:50px; text-align:center; font-size:20px; font-weight:700; background:var(--void); border:1px solid var(--line); border-radius:12px; color:var(--text); outline:none; }
.otp-boxes input:focus{ border-color:var(--pink); }
.otp-meta-row{ display:flex; justify-content:space-between; align-items:center; font-size:12px; color:var(--muted); margin-top:10px; }
.phone-shown{ color:var(--text); font-weight:700; }
.resend-link{ background:none; border:none; padding:0; color:var(--pink); font-weight:700; font-size:12px; }
.resend-link:disabled{ color:var(--muted-2); }
.change-number-link{ background:none; border:none; padding:0; color:var(--muted); font-weight:700; font-size:12px; text-decoration:underline; }

.who-toggle{ display:flex; gap:8px; margin-bottom:14px; }
.who-toggle .date-opt{ flex:1; justify-content:center; }

.guest-summary-row{ display:flex; gap:8px; font-size:13px; color:var(--muted); padding:4px 0; }
.guest-summary-row b{ color:var(--text); min-width:52px; }

.sp-row{ display:flex; justify-content:space-between; gap:10px; font-size:13px; color:var(--muted); padding:8px 0; border-bottom:1px solid var(--line); }
.sp-row span:last-child{ color:var(--text); font-weight:700; }
.sp-total{ display:flex; justify-content:space-between; font-weight:800; font-size:13.5px; padding-top:10px; }
.det-row{ display:flex; justify-content:space-between; font-size:13px; color:var(--muted); padding:5px 0; }
.det-row span:last-child{ color:var(--text); font-weight:600; }
.det-total{ display:flex; justify-content:space-between; font-weight:800; font-size:15px; padding-top:10px; margin-top:6px; border-top:1px solid var(--line); }
.det-total span:last-child{ color:var(--yellow); }

.free-notice{ display:flex; align-items:flex-start; gap:12px; }
.free-notice .fn-icon{ width:36px; height:36px; border-radius:10px; background:rgba(61,220,132,.12); color:var(--green); display:flex; align-items:center; justify-content:center; flex:none; }
.free-notice h6{ margin:0 0 3px; font-size:13.5px; font-weight:800; color:var(--text); text-transform:none; letter-spacing:0; }
.free-notice p{ margin:0; font-size:12px; color:var(--muted); line-height:1.55; }

.event-note-box{ background:rgba(255,201,77,.08); border:1px solid rgba(255,201,77,.25); border-radius:14px; padding:12px 14px; font-size:12px; color:var(--muted); display:flex; gap:10px; }
.event-note-box i{ color:var(--yellow); margin-top:2px; }
.event-note-box b{ color:var(--text); font-size:10px; letter-spacing:.06em; text-transform:uppercase; display:block; margin-bottom:4px; }

.terms-row{ display:flex; align-items:flex-start; gap:10px; font-size:12.5px; color:var(--muted); margin:16px 0; }
.terms-row input{ accent-color:var(--pink); width:18px; height:18px; flex:none; }
.terms-row a{ color:var(--text); font-weight:700; text-decoration:underline; }
.change-tickets-link{ display:block; text-align:center; font-size:13px; font-weight:700; color:var(--muted); margin:6px 0 10px; }

.event-over-box{ text-align:center; padding:40px 16px; }
.event-over-box h3{ color:indianred; }

/* ---- bottom CTA bar ---- */
.wizard-bottom-bar{
  position:fixed; bottom:0; left:0; right:0; z-index:1050; max-width:480px; margin:0 auto;
  display:flex; align-items:center; justify-content:space-between; gap:12px;
  padding:12px 16px calc(12px + env(safe-area-inset-bottom));
  background:rgba(15,15,18,.96); backdrop-filter:blur(16px); border-top:1px solid var(--line);
}
.wb-info{ display:flex; flex-direction:column; line-height:1.2; }
.wb-main{ font-size:15px; font-weight:800; color:var(--text); }
.wb-sub{ font-size:11px; color:var(--muted-2); font-weight:600; }
.wizard-bottom-bar .btn-pink{ flex:none; padding:12px 20px; font-size:13.5px; white-space:nowrap; }
</style>

<div class="hs-app">

  <div class="bk-header">
    <div class="bk-top">
      <a class="bk-back" href="<?= htmlspecialchars($eventUrl) ?>" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></a>
      <h1 class="bk-title">Checkout</h1>
      <?php if (!$eventIsOver && $bookingAllowed): ?>
        <div class="session-note">Expires in <b id="sessionTimer">10:00</b></div>
      <?php endif; ?>
    </div>

    <?php if (!$eventIsOver && $bookingAllowed): ?>
    <div class="book-steps">
      <div class="book-step active" id="stepTab1" data-step="1"><span class="num"><span>1</span></span> Tickets</div>
      <div class="book-step-sep"></div>
      <div class="book-step" id="stepTab2" data-step="2"><span class="num"><span>2</span></span> Info</div>
      <div class="book-step-sep"></div>
      <div class="book-step" id="stepTab3" data-step="3"><span class="num"><span>3</span></span> Summary</div>
    </div>
    <?php endif; ?>
  </div>

  <div class="bk-body">

  <?php if ($eventIsOver): ?>

    <div class="panel-card event-over-box">
      <h3>Event Over</h3>
      <p class="text-muted-c2">This event has already ended and is no longer accepting bookings.</p>
    </div>

  <?php else: ?>

    <?php if ($showTicketsPreviewOnly): ?>
    <div class="panel-card" style="text-align:center; margin-bottom:14px;">
      <strong style="color:var(--yellow);"><i class="fa-solid fa-clock-rotate-left me-2"></i>Bookings aren't open yet</strong>
      <p class="text-muted-c2 mb-0" style="font-size:12.5px; margin-top:6px;">Here's a preview of the passes. Check back soon to book.</p>
    </div>
    <?php endif; ?>

    <div class="evt-strip">
      <div class="thumb" style="background-image:url('<?= htmlspecialchars($row['image']) ?>');"></div>
      <div class="flex-grow-1" style="min-width:0;">
        <h5><?= htmlspecialchars($row['tittle']) ?></h5>
        <div class="meta">
          <span><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($row['placename']) ?></span>
          <span><i class="fa-regular fa-clock"></i>
            <?= ($day && $date && $monthss) ? htmlspecialchars("$day, $date $monthss \xC2\xB7 $time") : 'Date not available' ?>
          </span>
        </div>
      </div>
      <a href="<?= htmlspecialchars($eventUrl) ?>" class="link-arrow">Change</a>
    </div>

    <form id="bookingForm" novalidate onsubmit="return false;">
      <input type="hidden" id="event_id" name="event_id" value="<?= htmlspecialchars($id) ?>">

      <!-- ============ STEP 1: TICKETS ============ -->
      <div class="step-panel active" id="stepPanel1">

        <?php if ($bookingAllowed): ?>
          <?php if (!empty($multipleDates1)): ?>
          <div class="panel-card" style="margin-bottom:12px;">
            <div class="date-head-row">
              <h6><i class="fa-regular fa-calendar me-1"></i> Select date(s)</h6>
              <?php if (count($multipleDates1) > 1): ?>
                <button type="button" class="select-all-link" id="selectAllDates">Select all</button>
              <?php endif; ?>
            </div>
            <div class="date-scroll">
              <?php foreach ($multipleDates1 as $md):
                    $mdLabel = (new DateTime($md))->format('D, d M '); 
                    
                    //· g:i a
                    
                    ?>
              <label class="date-opt">
                <input type="checkbox" name="selected_dates[]" value="<?= htmlspecialchars($md) ?>">
                <?= htmlspecialchars($mdLabel) ?>
              </label>
              <?php endforeach; ?>
            </div>
            <div class="field-error" id="datesError">Please select at least one date to continue.</div>
          </div>
          <?php else: ?>
            <input type="checkbox" name="selected_dates[]" value="<?= htmlspecialchars($originalStart->format('Y-m-d')) ?>" checked style="display:none;">
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
          <div class="pass-row<?= $soldOut ? ' sold-out' : '' ?>" data-id="<?= (int)$t['id'] ?>" data-name="<?= htmlspecialchars($t['ticket_name']) ?>" data-price="<?= $costVal ?>" data-left="<?= $hasLeftCount ? (int)$leftVal : '' ?>">
            <div class="pass-info">
              <h6><?= htmlspecialchars($t['ticket_name']) ?> <?php if ($soldOut): ?><span class="sold-badge">Sold out</span><?php endif; ?></h6>
              <div class="pass-price"><?= $costVal == 0 ? 'FREE' : '&#8377;' . htmlspecialchars($t['ticket_cost']) ?></div>
              <?php if (!empty($t['ticket_desc'])): ?><p><?= htmlspecialchars($t['ticket_desc']) ?></p><?php endif; ?>
              <?php if ($lowStock): ?>
                <div class="stock-note"><span class="dot"></span>Only <?= $leftVal ?> left</div>
              <?php endif; ?>
            </div>
            <div class="pass-controls">
              <?php if ($bookingAllowed): ?>
                <button type="button" class="btn-add-pass" <?= $soldOut ? 'disabled' : '' ?>>Add</button>
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

      <?php if ($bookingAllowed): ?>
      <!-- ============ STEP 2: INFO ============ -->
      <div class="step-panel" id="stepPanel2">
        <button type="button" class="step-back" data-back="1"><i class="fa-solid fa-arrow-left"></i> Back to tickets</button>

        <div id="otpPhonePane">
          <div class="step-title">Confirm your number</div>
          <div class="step-subtitle">We'll send an OTP to verify. Takes 10 secs.</div>
          <label class="form-label-hs" for="otpPhoneInput">Mobile number</label>
          <div class="phone-input-pill">
            <span class="flag">🇮🇳 +91</span>
            <input type="tel" id="otpPhoneInput" inputmode="numeric" maxlength="10" placeholder="Enter your phone number" autocomplete="tel-national">
          </div>
          <div class="field-error" id="otpPhoneError"></div>
          <button type="button" class="btn-pink mt-3 w-100" id="sendOtpBtn">Send OTP</button>
        </div>

        <div id="otpCodePane" style="display:none;">
          <div class="step-title">Enter the code</div>
          <div class="step-subtitle">Sent to <span class="phone-shown" id="otpPhoneShown"></span></div>
          <label class="form-label-hs">One-time code</label>
          <div class="otp-boxes">
            <input type="text" inputmode="numeric" maxlength="1" class="otp-digit" autocomplete="one-time-code">
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

      <!-- ============ STEP 3: SUMMARY ============ -->
      <div class="step-panel" id="stepPanel3">
        <button type="button" class="step-back" data-back="2"><i class="fa-solid fa-arrow-left"></i> Back to info</button>

        <div class="panel-card" id="newUserDetailsPanel" style="display:none;">
          <h6><i class="fa-solid fa-id-card me-1"></i> Tell us about you</h6>
          <div class="field-row">
            <label class="form-label-hs" for="nuName">Full name</label>
            <input type="text" class="form-control-hs" id="nuName" placeholder="Your full name" autocomplete="name">
            <div class="field-error" id="nuNameError">Enter your full name.</div>
          </div>
          <div class="field-row">
            <label class="form-label-hs" for="nuEmail">Email</label>
            <input type="email" class="form-control-hs" id="nuEmail" placeholder="you@example.com" autocomplete="email">
            <div class="field-error" id="nuEmailError">Enter a valid email address.</div>
          </div>
          <div class="field-row">
            <label class="form-label-hs" for="nuGender">Gender</label>
            <select class="form-select-hs" id="nuGender">
              <option value="">Select</option><option value="Male">Male</option>
              <option value="Female">Female</option><option value="Other">Other</option>
            </select>
            <div class="field-error" id="nuGenderError">Select a gender.</div>
          </div>
          <div class="field-row">
            <label class="form-label-hs" for="nuCity">City</label>
            <input type="text" class="form-control-hs" id="nuCity" placeholder="Your city" autocomplete="address-level2">
            <div class="field-error" id="nuCityError">Enter your city.</div>
          </div>
          <div class="field-error" id="nuFormError"></div>
          <button type="button" class="btn-pink mt-2 w-100" id="saveNewUserBtn">Save &amp; continue</button>
        </div>

        <div class="panel-card" id="loggedInPreviewPanel" style="display:none;">
          <h6><i class="fa-solid fa-user me-1"></i> Who is this booking for?</h6>
          <div class="who-toggle">
            <label class="date-opt selected" id="whoMyselfLabel">
              <input type="radio" name="bookingFor" value="self" id="whoMyself" checked style="display:none;">Myself
            </label>
            <label class="date-opt" id="whoOtherLabel">
              <input type="radio" name="bookingFor" value="other" id="whoOther" style="display:none;">Someone else
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
                <option value="">Select</option><option value="Male">Male</option>
                <option value="Female">Female</option><option value="Other">Other</option>
              </select>
            </div>
          </div>
          <div class="field-error" id="guestDetailsError">Please add guest details to continue.</div>
          <button type="button" class="btn-pink mt-2 w-100" id="saveGuestChoiceBtn">Save</button>
        </div>

        <div class="panel-card" id="guestSummaryPanel" style="display:none;">
          <h6>Send tickets to <span class="edit-link" id="guestEditLink">Edit</span></h6>
          <div class="guest-summary-row"><b>Name:</b> <span id="gsName"></span></div>
          <div class="guest-summary-row"><b>Email:</b> <span id="gsEmail"></span></div>
          <div class="guest-summary-row"><b>Phone:</b> <span id="gsPhone"></span></div>
          <label class="d-flex align-items-start gap-2 mt-3" style="font-size:12px; color:var(--muted);">
            <input type="checkbox" id="authorizeConsent" checked style="accent-color:var(--pink); width:16px; height:16px; margin-top:2px; flex:none;">
            I authorize HighSpinn to send notifications via SMS / RCS / Email / WhatsApp*
          </label>
        </div>

        <div class="panel-card">
          <h6>Selected passes</h6>
          <div id="passesSummaryList"></div>
          <div class="sp-total"><span>Total passes</span><span id="sumTotalPasses">0</span></div>
        </div>

        <div class="panel-card">
          <h6>Details</h6>
          <div class="det-row"><span>Subtotal</span><span id="detSubtotal">&#8377;0</span></div>
          <div class="det-row"><span>Platform convenience fee</span><span id="detFee">&#8377;0</span></div>
          <div class="det-row"><span>Taxes / GST</span><span id="detTax">&#8377;0</span></div>
          <div class="det-total"><span>To pay</span><span id="detToPay">&#8377;0</span></div>
        </div>

        <div class="panel-card" id="paymentPanel" <?= $allTicketsFree ? 'style="display:none;"' : '' ?>>
          <h6><i class="fa-solid fa-credit-card me-1"></i> Payment method</h6>
          <div class="free-notice">
            <span class="fn-icon"><i class="fa-solid fa-shield-halved"></i></span>
            <div>
              <h6>Pay securely via Razorpay</h6>
              <p>Choose UPI (GPay / PhonePe / Paytm), card, net banking or wallet on the next screen.</p>
            </div>
          </div>
        </div>

        <div class="panel-card" id="freeNotice" <?= $allTicketsFree ? '' : 'style="display:none;"' ?>>
          <div class="free-notice">
            <span class="fn-icon"><i class="fa-solid fa-circle-check"></i></span>
            <div>
              <h6>This is a free booking</h6>
              <p>No payment required. Confirm your details and your e-passes are issued instantly.</p>
            </div>
          </div>
        </div>

        <?php if ($eventNoteText !== ''): ?>
        <div class="event-note-box">
          <i class="fa-solid fa-circle-info"></i>
          <div><b>Event note</b><?= htmlspecialchars($eventNoteText) ?></div>
        </div>
        <?php endif; ?>

        <div class="terms-row">
          <input type="checkbox" id="termsCheckbox" checked>
          <label for="termsCheckbox">By proceeding, you agree to the <a href="/terms" target="_blank">Terms &amp; Conditions</a></label>
        </div>

        <a href="#" class="change-tickets-link" id="changeTicketsLink"><i class="fa-solid fa-arrow-left me-1"></i> Change tickets</a>
      </div>
      <?php endif; ?>

    </form>

  <?php endif; // eventIsOver ?>
  </div>
</div>

<?php if ($bookingAllowed): ?>
<div class="wizard-bottom-bar" id="wizardBottomBar">
  <div class="wb-info">
    <span class="wb-main" id="wbMain">0 Selected</span>
    <span class="wb-sub" id="wbSub">Total &amp; offers on next step</span>
  </div>
  <button type="button" class="btn-pink" id="ctaBtn" disabled>Continue <i class="fa-solid fa-arrow-right ms-1"></i></button>
</div>
<?php elseif ($showTicketsPreviewOnly): ?>
<div class="wizard-bottom-bar">
  <div class="wb-info">
    <span class="wb-main">Bookings not open</span>
    <span class="wb-sub">Check back soon</span>
  </div>
  <button type="button" class="btn-pink" disabled style="opacity:.5;">Not available</button>
</div>
<?php endif; ?>

<div id="loader" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); z-index:9999;">
  <div class="spinner-border text-primary" role="status"><span class="sr-only">Loading...</span></div>
</div>

<script>document.body.classList.add('hs-app-view');</script>

<?php
// bottom of file
include __DIR__ . '/include/booking-script.php';
include 'include/footerapp.php';   // desktop file: include 'include/footer.php';