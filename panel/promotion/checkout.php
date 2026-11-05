<?php
/**
 * promotion/checkout.php
 * Partner promotion checkout: pick event + date, review, pay via Razorpay.
 */
require_once __DIR__ . '/../include/session_guard.php';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/promo_helpers.php';

date_default_timezone_set('Asia/Kolkata');

if ($role !== 'partner') {
    header('Location: /promotion');
    exit();
}

$packageId = (int) ($_GET['package_id'] ?? 0);
if ($packageId <= 0) {
    header('Location: /promotion?error=' . urlencode('Invalid promotion package.'));
    exit();
}

$package = promo_validate_package_for_partner($conn, $packageId, $userId);
if (!$package) {
    header('Location: /promotion?error=' . urlencode('That promotion package is not available for your account.'));
    exit();
}

$durationLabel = $package['duration_type'] === 'until_event_end'
    ? 'Until Event Ends'
    : ((int) $package['duration_days'] . ' Day' . ((int) $package['duration_days'] === 1 ? '' : 's'));

$myEvents = promo_get_partner_events($conn, $userId);

$pageTitle  = 'Checkout';
$activePage = 'promotion';
include __DIR__ . '/../include/header.php';
?>

<div class="page-head">
  <div>
    <h2>Checkout</h2>
    <p><?php echo htmlspecialchars($package['package_name']); ?></p>
  </div>
  <a href="/promotion" class="btn-mini"><i class="fa-solid fa-arrow-left"></i> Back to Plans</a>
</div>

<?php if (empty($myEvents)): ?>
  <div class="panel-card">
    <div class="cell-sub text-center" style="padding:2rem 0;">
      You don't have any live events yet. <a href="/events/">Create an event</a> first, then come back to buy this promotion.
    </div>
  </div>
<?php else: ?>

<div class="checkout-grid">

  <div class="checkout-main">

    <div class="panel-card" id="orderFormCard">
      <div class="step-head">
        <span class="step-num">1</span>
        <h5>Choose Event &amp; Date</h5>
      </div>

      <label class="form-label-c">Event</label>
      <select class="form-control-c" id="checkoutEventSelect" style="width:100%;">
        <option value="">Select an event…</option>
        <?php foreach ($myEvents as $ev): ?>
          <option value="<?php echo (int) $ev['id']; ?>"><?php echo htmlspecialchars($ev['tittle']); ?></option>
        <?php endforeach; ?>
      </select>

      <div id="checkoutDateSection" class="mt-3" style="display:none;">
        <label class="form-label-c">Event Date </label>

        <div id="checkoutSingleDateReadonly" class="doc-row" style="display:none;"></div>

        <div id="checkoutAllDatesInfo" style="display:none;">
          <div class="cell-sub" style="margin-bottom:.5rem;">This package promotes every date of the event automatically.</div>
          <div class="d-flex flex-wrap gap-1" id="checkoutAllDatesBadges"></div>
        </div>

        <div id="checkoutDateChoiceList" style="display:none;"></div>
      </div>

      <button type="button" class="btn-pink-sm mt-3" id="reviewOrderBtn" disabled>Review Order</button>
    </div>

    <div class="panel-card" id="previewCard" style="display:none;">
      <div class="step-head" style="justify-content:space-between;">
        <div class="d-flex align-items-center gap-2">
          <span class="step-num">2</span>
          <h5 style="margin:0;">Review &amp; Pay</h5>
        </div>
        <button type="button" class="btn-mini" id="editOrderBtn"><i class="fa-solid fa-pencil"></i> Edit</button>
      </div>

      <div class="doc-row"><span>Package</span><b id="previewPackage">—</b></div>
      <div class="doc-row"><span>Price</span><b id="previewPrice">—</b></div>
      <div class="doc-row"><span>Duration</span><b id="previewDuration">—</b></div>
      <div class="doc-row"><span>Event</span><b id="previewEvent">—</b></div>
      <div class="doc-row"><span>Promotion Date(s)</span><b id="previewDates">—</b></div>

      <div class="cell-sub text-center" style="margin-top:.9rem;"><i class="fa-solid fa-shield-halved"></i> Payments are processed securely via Razorpay.</div>

      <button type="button" class="btn-pink-sm mt-3 pay-btn" id="checkoutPayBtn">
        <i class="fa-solid fa-lock"></i> Pay ₹<?php echo htmlspecialchars($package['package_price']); ?> Securely
      </button>
    </div>

  </div>

  <div class="checkout-side">
    <div class="panel-card summary-card">
      <div class="head"><h5>Order Summary</h5></div>
      <h6 style="margin-bottom:.4rem;"><?php echo htmlspecialchars($package['package_name']); ?></h6>
      <div class="cell-sub" style="margin-bottom:.8rem;"><?php echo strip_tags($package['package_description'] ?? ''); ?></div>
      <div class="summary-price">₹<?php echo htmlspecialchars($package['package_price']); ?></div>
      <div class="d-flex flex-wrap gap-1">
        <span class="badge-pill"><i class="fa-regular fa-clock"></i> <?php echo htmlspecialchars($durationLabel); ?></span>
        <span class="badge-pill"><i class="fa-regular fa-calendar"></i> <?php echo ((int) $package['allow_multiple_dates'] === 1) ? 'All Event Dates ' : 'Single Event Date '; ?></span>
      </div>
    </div>
  </div>

</div>

<?php endif; ?>

<style>
  .checkout-grid{ display:grid; grid-template-columns:1.6fr 1fr; gap:1.2rem; align-items:start; }
  @media (max-width:960px){ .checkout-grid{ grid-template-columns:1fr; } }
  .checkout-side{ position:sticky; top:1rem; }
  .step-head{ display:flex; align-items:center; gap:.6rem; margin-bottom:1.1rem; }
  .step-num{ width:26px; height:26px; border-radius:50%; background:var(--pink); color:#fff; font-size:.8rem; font-weight:800; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
  .step-head h5{ margin:0; }
  .summary-price{ font-size:1.6rem; font-weight:800; color:var(--text); margin-bottom:.5rem; }
  .pay-btn{ width:100%; text-align:center; }
  .pay-btn:disabled{ opacity:.6; cursor:not-allowed; }

  /* Dark-theme skin for select2 so it matches form-control-c instead of the browser default */
  .select2-container{ width:100% !important; }
  .select2-container--default .select2-selection--single{
    background-color:var(--panel-2); border:1px solid var(--line); border-radius:10px; height:46px;
  }
  .select2-container--default .select2-selection--single .select2-selection__rendered{
    color:var(--text); line-height:44px; padding-left:.9rem; padding-right:2rem;
  }
  .select2-container--default .select2-selection--single .select2-selection__placeholder{ color:var(--muted); }
  .select2-container--default .select2-selection--single .select2-selection__arrow{ height:44px; right:.6rem; }
  .select2-container--default .select2-selection--single .select2-selection__arrow b{ border-color:var(--muted) transparent transparent transparent; }
  .select2-container--default.select2-container--open .select2-selection--single{ border-color:var(--pink); }
  .select2-container--default.select2-container--open .select2-selection__arrow b{ border-color:transparent transparent var(--pink) transparent !important; }
  .select2-dropdown{ background:var(--panel-2); border:1px solid var(--line); border-radius:10px; overflow:hidden; }
  .select2-search--dropdown{ padding:.6rem; background:var(--panel-2); }
  .select2-search--dropdown .select2-search__field{
    background:var(--panel); border:1px solid var(--line) !important; border-radius:8px; color:var(--text); padding:.5rem .7rem; outline:none;
  }
  .select2-results__options{ max-height:220px; }
  .select2-results__option{ color:var(--text); padding:.6rem .9rem; }
  .select2-container--default .select2-results__option--highlighted[aria-selected]{ background:var(--pink) !important; color:#fff; }
  .select2-container--default .select2-results__option[aria-selected=true]{ background:var(--panel-3); color:var(--text); }
  .select2-results__message{ color:var(--muted); }
</style>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var eventSelectEl = document.getElementById('checkoutEventSelect');
  if (!eventSelectEl) return;

  var packageId = <?php echo (int) $package['id']; ?>;
  var packageName = <?php echo json_encode($package['package_name']); ?>;
  var packagePrice = <?php echo json_encode($package['package_price']); ?>;
  var durationLabel = <?php echo json_encode($durationLabel); ?>;

  $('#checkoutEventSelect').select2({ placeholder: 'Select an event…', width: '100%' });

  var state = { eventId: null, needsSelection: false, appliesToAll: false, selectedDate: null };

  var dateSection = document.getElementById('checkoutDateSection');
  var singleReadonly = document.getElementById('checkoutSingleDateReadonly');
  var allDatesInfo = document.getElementById('checkoutAllDatesInfo');
  var allDatesBadges = document.getElementById('checkoutAllDatesBadges');
  var dateChoiceList = document.getElementById('checkoutDateChoiceList');
  var reviewBtn = document.getElementById('reviewOrderBtn');
  var orderFormCard = document.getElementById('orderFormCard');
  var previewCard = document.getElementById('previewCard');
  var editOrderBtn = document.getElementById('editOrderBtn');
  var payBtn = document.getElementById('checkoutPayBtn');
  var previewEventEl = document.getElementById('previewEvent');
  var previewDatesEl = document.getElementById('previewDates');

  function escapeHtml(v) {
    var d = document.createElement('div');
    d.textContent = v == null ? '' : String(v);
    return d.innerHTML;
  }

  function resetDateUI() {
    dateSection.style.display = 'none';
    singleReadonly.style.display = 'none';
    allDatesInfo.style.display = 'none';
    dateChoiceList.style.display = 'none';
    dateChoiceList.innerHTML = '';
    allDatesBadges.innerHTML = '';
    reviewBtn.disabled = true;
  }

  function updateReviewBtnState() {
    reviewBtn.disabled = !(state.eventId && (!state.needsSelection || state.selectedDate));
  }

  $('#checkoutEventSelect').on('change', function () {
    var eventId = this.value;
    resetDateUI();
    state = { eventId: eventId || null, needsSelection: false, appliesToAll: false, selectedDate: null };
    if (!eventId) { updateReviewBtnState(); return; }

    previewEventEl.textContent = $(this).find('option:selected').text();

    var datesUrl = '/ajax/promotion/actions?action=get-event-dates&event_id=' + encodeURIComponent(eventId) + '&package_id=' + encodeURIComponent(packageId);

    fetch(datesUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || 'Could not load event dates.'); return d; }); })
      .then(function (data) {
        if (data.error) throw new Error(data.error);
        if (!Array.isArray(data.dates) || data.dates.length === 0) throw new Error('No valid event date found.');

        dateSection.style.display = 'block';
        state.needsSelection = !!data.needs_selection;
        state.appliesToAll = !!data.applies_to_all;

        if (!data.is_multi) {
          var single = data.dates[0];
          singleReadonly.style.display = 'flex';
          singleReadonly.innerHTML = '<span>Event Date </span><b> ' + escapeHtml(single.label) + '</b>';
          state.selectedDate = single.value;
          previewDatesEl.textContent = single.label;
        } else if (data.applies_to_all) {
          allDatesInfo.style.display = 'block';
          data.dates.forEach(function (d) {
            var badge = document.createElement('span');
            badge.className = 'badge-pill';
            badge.textContent = d.label;
            allDatesBadges.appendChild(badge);
          });
          state.selectedDate = null;
          previewDatesEl.textContent = 'All Event Dates';
        } else {
          dateChoiceList.style.display = 'block';
          data.dates.forEach(function (d) {
            var label = document.createElement('label');
            label.className = 'radio-pill';
            label.style.cssText = 'display:flex;width:100%;margin-bottom:.5rem;';
            var radio = document.createElement('input');
            radio.type = 'radio'; radio.name = 'checkoutPromoDate'; radio.value = d.value; radio.style.marginRight = '.5rem';
            label.appendChild(radio);
            label.appendChild(document.createTextNode(d.label));
            dateChoiceList.appendChild(label);
            radio.addEventListener('change', function () {
              state.selectedDate = this.value;
              previewDatesEl.textContent = d.label;
              updateReviewBtnState();
            });
          });
        }
        updateReviewBtnState();
      })
      .catch(function (error) {
        showToast('Cannot use this event', error.message);
        state.selectedDate = null;
        updateReviewBtnState();
      });
  });

  reviewBtn.addEventListener('click', function () {
    if (reviewBtn.disabled) return;
    document.getElementById('previewPackage').textContent = packageName;
    document.getElementById('previewPrice').textContent = '₹' + packagePrice;
    document.getElementById('previewDuration').textContent = durationLabel;
    orderFormCard.style.display = 'none';
    previewCard.style.display = 'block';
    previewCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  editOrderBtn.addEventListener('click', function () {
    previewCard.style.display = 'none';
    orderFormCard.style.display = 'block';
  });

  // Tracks whether the Razorpay handler already took over navigation, so
  // modal.ondismiss (which fires on close regardless of outcome, including
  // right after a successful payment) doesn't race it to the failed page.
  var paymentHandled = false;

  payBtn.addEventListener('click', function () {
    if (payBtn.disabled) return;
    if (!state.eventId) { showToast('Select event', 'Please select an event first.'); return; }
    if (state.needsSelection && !state.selectedDate) { showToast('Select event date', 'Please select the promotion date.'); return; }

    paymentHandled = false;
    payBtn.disabled = true;
    payBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Preparing payment…';

    fetch('/ajax/promotion/actions?action=create-order', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ package_id: Number(packageId), event_id: Number(state.eventId), event_date: state.selectedDate || null })
    })
      .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || 'Could not create order.'); return d; }); })
      .then(function (data) {
        if (data.error) throw new Error(data.error);
        if (!data.razorpay_order_id || !data.razorpay_key) throw new Error('Razorpay order could not be created.');

        var rzp = new Razorpay({
          key: data.razorpay_key,
          amount: data.amount,
          currency: 'INR',
          name: 'HighSpinn',
          description: data.package_name,
          order_id: data.razorpay_order_id,
          prefill: data.prefill,
          theme: { color: '#ff3d68' },
          handler: function (response) {
            paymentHandled = true;
            fetch('/ajax/promotion/actions?action=verify-payment', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
              body: JSON.stringify({
                order_id: data.order_id,
                razorpay_order_id: response.razorpay_order_id,
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_signature: response.razorpay_signature
              })
            })
              .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || 'Payment verification failed.'); return d; }); })
              .then(function (result) {
                window.location.href = result.redirect || ('/promotion/payment-success.php?order_id=' + encodeURIComponent(data.order_id));
              })
              .catch(function () {
                window.location.href = '/promotion/payment-failed.php?order_id=' + encodeURIComponent(data.order_id);
              });
          },
          modal: {
            ondismiss: function () {
              // Only a real user-cancelled close, not a race with a completed payment.
              if (!paymentHandled) {
                window.location.href = '/promotion/payment-failed.php?order_id=' + encodeURIComponent(data.order_id);
              }
            }
          }
        });

        rzp.on('payment.failed', function () {
          paymentHandled = true;
          window.location.href = '/promotion/payment-failed.php?order_id=' + encodeURIComponent(data.order_id);
        });

        rzp.open();
        payBtn.innerHTML = '<i class="fa-solid fa-lock"></i> Pay ₹' + packagePrice + ' Securely';
        payBtn.disabled = false;
      })
      .catch(function (error) {
        showToast('Could not start payment', error.message);
        payBtn.disabled = false;
        payBtn.innerHTML = '<i class="fa-solid fa-lock"></i> Pay ₹' + packagePrice + ' Securely';
      });
  });
});
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>