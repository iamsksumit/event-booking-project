</div><!-- /.content -->
</div><!-- /.main-wrap -->

<?php if ($role === 'partner' || 'admin'):
    /* Events happening today, for the "which event are you scanning for?" step.
       Scanning (and denying) is only meaningful on the event's own day. */
    $scanStmt = $conn->prepare("
        SELECT id, tittle, start
        FROM events
        WHERE user_id = ? AND status = 1 AND DATE(start) = CURDATE()
        ORDER BY start ASC
    ");
    $scanStmt->bind_param('i', $userId);
    $scanStmt->execute();
    $scanEvents = $scanStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>


<style>.scan-details-card{
    margin-top:.8rem; padding:.7rem .9rem; border:1px solid var(--line); border-radius:10px; background:var(--panel-2);
  }
  .scan-detail-row{ display:flex; justify-content:space-between; padding:.3rem 0; font-size:.82rem; }
  .scan-detail-row span{ color:var(--muted-2); }
  .scan-detail-row b{ color:var(--text); text-align:right; }
  .scan-detail-row + .scan-detail-row{ border-top:1px dashed var(--line); }
  .btn-mini.approve{ border-color:rgba(63,209,130,.4); color:var(--green); }
  .btn-mini.approve:hover{ background:rgba(63,209,130,.12); }</style>
<!-- ================= ADD EVENT MODAL ================= -->
<div class="modal fade" id="addEventModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5>Create a new event</h5>
          <div class="cell-sub">Step <span id="stepNum">1</span> of 2</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="step-track"><div class="seg done" id="seg1"></div><div class="seg" id="seg2"></div></div>

        <div id="addEventStep1">
          <label class="form-label-c">What kind of event is this?</label>
          <div class="row g-3">
            <div class="col-6">
              <label class="type-select-card" id="cardRsvp">
                <input type="radio" name="eventType" value="rsvp">
                <div class="ic-wrap" style="background:linear-gradient(135deg,#4d9fff,#7b6bff);"><i class="fa-solid fa-envelope-open-text"></i></div>
                <h6>RSVP / Free</h6>
                <p>Guests reserve a free spot, no payment collected at checkout.</p>
              </label>
            </div>
            <div class="col-6">
              <label class="type-select-card" id="cardPaid">
                <input type="radio" name="eventType" value="paid">
                <div class="ic-wrap" style="background:linear-gradient(135deg,var(--pink),var(--orange));"><i class="fa-solid fa-indian-rupee-sign"></i></div>
                <h6>Paid Event</h6>
                <p>Sell ticket categories and collect payments through Highspinn.</p>
              </label>
            </div>
          </div>
        </div>

        <div id="addEventStep2" class="d-none">
          <label class="form-label-c" for="newEventTitle">Event title</label>
          <input type="text" id="newEventTitle" class="form-control-c" placeholder="e.g. Neon Nights Vol. 5">
          <div class="cell-sub mt-2">You'll add venue, date, tickets and media on the next screen.</div>
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <button type="button" class="btn-mini d-none" id="backBtn">Back</button>
        <div class="ms-auto d-flex gap-2">
          <button type="button" class="btn-mini" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn-pink-sm" id="nextBtn" disabled>Continue</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ================= SCAN TICKET MODAL ================= -->
<div class="modal fade" id="scanTicketModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5><i class="fa-solid fa-qrcode me-2"></i>Scan Tickets</h5>
          <div class="cell-sub" id="scanEventLabel">Select tonight's event to begin</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">

        <!-- ===== STEP 1: which event ===== -->
        <div id="scanStepEvent">
          <?php if ($scanEvents): ?>
            <div class="scan-event-list">
              <?php foreach ($scanEvents as $se): ?>
                <button type="button" class="scan-event-item"
                        data-event-id="<?php echo (int) $se['id']; ?>"
                        data-event-title="<?php echo htmlspecialchars($se['tittle'], ENT_QUOTES); ?>">
                  <div class="scan-event-time"><?php echo htmlspecialchars(date('g:i A', strtotime($se['start']))); ?></div>
                  <div class="scan-event-title"><?php echo htmlspecialchars($se['tittle']); ?></div>
                  <i class="fa-solid fa-chevron-right"></i>
                </button>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="cell-sub text-center py-4">
              No events scheduled for today. Scanning opens on the event's own day.
            </div>
          <?php endif; ?>
        </div>

        <!-- ===== STEP 2: scanner ===== -->
        <div id="scanStepScanner" class="d-none">
          <button type="button" class="btn-mini mb-3" id="scanChangeEventBtn"><i class="fa-solid fa-arrow-left"></i> Change event</button>

          <div class="scan-frame" id="scanFrame">
            <video id="scanVideo" style="display:none;width:100%;height:100%;object-fit:cover;border-radius:20px;"></video>
            <i class="fa-solid fa-camera" id="scanFrameIcon"></i>
            <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
            <div class="beam" id="scanBeam" style="display:none;"></div>
          </div>
          <div class="cell-sub text-center mt-2">Point the camera at the attendee's ticket QR code</div>

          <div class="scan-divider">OR ENTER CODE MANUALLY</div>
          <div class="d-flex gap-2">
            <input type="text" id="manualTicketCode" class="form-control-c" placeholder="Ticket code e.g. HS-000123">
            <button type="button" class="btn-pink-sm" id="manualCheckInBtn">Check In</button>
          </div>

          <!-- result banner: pending / already / wrong_event / denied / invalid -->
          <div id="scanResultBanner" class="scan-result-banner d-none">
            <div class="scan-result-icon"><i id="scanResultIcon" class="fa-solid"></i></div>
            <div class="flex-grow-1">
              <div id="scanResultTitle" class="scan-result-title"></div>
              <div id="scanResultMsg" class="scan-result-msg"></div>
            </div>
          </div>

          <!-- attendee/ticket detail card - rows hide individually when a field wasn't sent -->
          <div id="scanDetailsCard" class="scan-details-card d-none">
            <div class="scan-detail-row" id="rowName"><span>Name</span><b id="scanDetailName">—</b></div>
            <div class="scan-detail-row" id="rowEvent"><span>Event</span><b id="scanDetailEvent">—</b></div>
            <div class="scan-detail-row" id="rowTime"><span>Date &amp; Time</span><b id="scanDetailTime">—</b></div>
            <div class="scan-detail-row" id="rowTickets"><span>Tickets</span><b id="scanDetailTickets">—</b></div>
            <div class="scan-detail-row" id="rowPrice"><span>Amount Paid</span><b id="scanDetailPrice">—</b></div>
          </div>

          <!-- pending ticket: staff must choose -->
          <div class="d-flex gap-2 mt-2" id="scanDecisionBtns">
            <button type="button" class="btn-mini approve d-none flex-grow-1" id="scanApproveBtn"><i class="fa-solid fa-check"></i> Approve Entry</button>
            <button type="button" class="btn-mini reject d-none flex-grow-1" id="scanDenyBtn"><i class="fa-solid fa-ban"></i> Deny Entry</button>
          </div>

          <!-- every other result (or after a decision is made): staff must tap OK before the next scan is accepted -->
          <button type="button" class="btn-pink-sm w-100 justify-content-center mt-2 d-none" id="scanOkBtn"><i class="fa-solid fa-check-double"></i> OK, Scan Next Ticket</button>

          <!-- deny reason panel -->
          <div id="scanDenyPanel" class="scan-deny-panel d-none">
            <label class="form-label-c">Reason for denying entry</label>
            <textarea id="scanDenyReason" class="form-control-c" rows="2" placeholder="e.g. duplicate ticket, ID mismatch, altered QR code…"></textarea>
            <div class="d-flex gap-2 mt-2">
              <button type="button" class="btn-mini" id="scanDenyCancelBtn">Cancel</button>
              <button type="button" class="btn-mini reject" id="scanDenyConfirmBtn">Confirm Denial</button>
            </div>
          </div>

          <div class="row g-2 mt-4 text-center" id="scanStats">
            <div class="col-4 scan-stat"><b id="statCheckedIn">–</b><span>Checked In</span></div>
            <div class="col-4 scan-stat"><b id="statRemaining">–</b><span>Not Arrived</span></div>
            <div class="col-4 scan-stat"><b id="statTotal">–</b><span>Total Tickets</span></div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Toast -->
<div class="toast-c" id="appToast">
  <i class="fa-solid fa-circle-check"></i>
  <div>
    <div class="t-title" id="toastTitle">Success</div>
    <div class="t-sub" id="toastSub">Done</div>
  </div>
</div>

<style>
  .scan-event-list{ display:flex; flex-direction:column; gap:.5rem; max-height:340px; overflow-y:auto; }
  .scan-event-item{
    display:flex; align-items:center; gap:.8rem; width:100%; text-align:left;
    background:var(--panel-2); border:1px solid var(--line); border-radius:10px;
    padding:.7rem .9rem; color:var(--text); cursor:pointer; transition:border-color .15s, background .15s;
  }
  .scan-event-item:hover{ border-color:var(--pink); background:rgba(255,64,129,.06); }
  .scan-event-time{ font-size:.72rem; font-weight:800; color:var(--pink); min-width:72px; }
  .scan-event-title{ flex-grow:1; font-weight:700; font-size:.85rem; }
  .scan-event-item i.fa-chevron-right{ color:var(--muted-2); font-size:.75rem; }

  .scan-result-banner{
    display:flex; align-items:flex-start; gap:.7rem; margin-top:1rem; padding:.8rem .9rem;
    border-radius:10px; border:1px solid var(--line); background:var(--panel-2);
  }
  .scan-result-icon{ font-size:1.3rem; line-height:1; margin-top:.1rem; }
  .scan-result-title{ font-weight:800; font-size:.88rem; margin-bottom:.15rem; }
  .scan-result-msg{ font-size:.8rem; color:var(--muted); }
  .scan-result-banner.ok{ border-color:rgba(63,209,130,.4); background:rgba(63,209,130,.1); }
  .scan-result-banner.ok .scan-result-icon{ color:var(--green); }
  .scan-result-banner.bad{ border-color:rgba(220,53,69,.4); background:rgba(220,53,69,.1); }
  .scan-result-banner.bad .scan-result-icon{ color:#ff4d4d; }
  .scan-result-banner.warn{ border-color:rgba(255,201,77,.45); background:rgba(255,201,77,.12); }
  .scan-result-banner.warn .scan-result-icon{ color:var(--yellow); }
  .scan-result-banner.info{ border-color:rgba(77,159,255,.4); background:rgba(77,159,255,.1); }
  .scan-result-banner.info .scan-result-icon{ color:var(--blue); }

  .scan-deny-panel{ margin-top:.8rem; padding:.8rem .9rem; border:1px solid rgba(220,53,69,.35); background:rgba(220,53,69,.06); border-radius:10px; }
  .scan-deny-panel .form-label-c{ font-size:.76rem; font-weight:700; color:var(--muted-2); margin-bottom:.35rem; display:block; }
  .scan-deny-panel textarea.form-control-c{ width:100%; resize:vertical; }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // sidebar accordion
  document.querySelectorAll('[data-toggle]').forEach(function(el){
    el.addEventListener('click', function(){
      var parent = el.closest('.nav-item');
      var wasOpen = parent.classList.contains('open');
      document.querySelectorAll('.nav-item.open').forEach(function(n){ n.classList.remove('open'); });
      if(!wasOpen) parent.classList.add('open');
    });
  });
  var burger = document.getElementById('burgerBtn');
  var sidebar = document.getElementById('sidebar');
  if (burger) { burger.addEventListener('click', function(){ sidebar.classList.toggle('show'); }); }

  function showToast(title, sub){
    var t = document.getElementById('appToast');
    document.getElementById('toastTitle').textContent = title;
    document.getElementById('toastSub').textContent = sub;
    t.classList.add('show');
    clearTimeout(window._toastTimer);
    window._toastTimer = setTimeout(function(){ t.classList.remove('show'); }, 3200);
  }

  // ---------- generic dropdown toggling (notifications + user menu) ----------
  function wireDropdown(btnId, panelId){
    var btn = document.getElementById(btnId), panel = document.getElementById(panelId);
    if (!btn || !panel) return;
    btn.addEventListener('click', function(e){
      e.stopPropagation();
      document.querySelectorAll('.dropdown-panel.show').forEach(function(p){ if(p !== panel) p.classList.remove('show'); });
      panel.classList.toggle('show');
    });
    document.addEventListener('click', function(e){
      if (!panel.contains(e.target) && e.target !== btn) panel.classList.remove('show');
    });
  }
  wireDropdown('notifBtn', 'notifPanel');
  wireDropdown('userMenuBtn', 'userMenuPanel');

  var markAllReadBtn = document.getElementById('markAllReadBtn');
  if (markAllReadBtn) {
    markAllReadBtn.addEventListener('click', function(e){
      e.preventDefault();
      fetch('/api/notifications_mark_read.php', { method: 'POST' })
        .then(function(){ document.querySelectorAll('.notif-item').forEach(function(i){ i.classList.add('read'); }); var dot=document.querySelector('#notifBtn .dot-alert'); if(dot) dot.remove(); });
    });
  }
</script>

<?php if ($role === 'partner' || 'admin' ): ?>
<script>
  // ---------- Add Event modal ----------
  var addEventModalEl = document.getElementById('addEventModal');
  var addEventModal = new bootstrap.Modal(addEventModalEl);
  var step1 = document.getElementById('addEventStep1');
  var step2 = document.getElementById('addEventStep2');
  var seg1 = document.getElementById('seg1'), seg2 = document.getElementById('seg2');
  var stepNum = document.getElementById('stepNum');
  var nextBtn = document.getElementById('nextBtn');
  var backBtn = document.getElementById('backBtn');
  var titleInput = document.getElementById('newEventTitle');
  var cardRsvp = document.getElementById('cardRsvp');
  var cardPaid = document.getElementById('cardPaid');
  var currentStep = 1, selectedType = null;

  function resetAddEventModal(){
    currentStep = 1; selectedType = null;
    step1.classList.remove('d-none'); step2.classList.add('d-none');
    seg1.classList.add('done'); seg2.classList.remove('done');
    stepNum.textContent = '1';
    nextBtn.textContent = 'Continue'; nextBtn.disabled = true;
    backBtn.classList.add('d-none');
    cardRsvp.classList.remove('selected'); cardPaid.classList.remove('selected');
    cardRsvp.querySelector('input').checked = false; cardPaid.querySelector('input').checked = false;
    titleInput.value = '';
  }
  document.querySelectorAll('.js-add-event').forEach(function(btn){
    btn.addEventListener('click', function(e){ e.preventDefault(); resetAddEventModal(); addEventModal.show(); });
  });
  [cardRsvp, cardPaid].forEach(function(card){
    card.addEventListener('click', function(){
      cardRsvp.classList.remove('selected'); cardPaid.classList.remove('selected');
      card.classList.add('selected');
      card.querySelector('input').checked = true;
      selectedType = card.querySelector('input').value;
      if (currentStep === 1) nextBtn.disabled = false;
    });
  });
  titleInput.addEventListener('input', function(){
    if (currentStep === 2) nextBtn.disabled = titleInput.value.trim().length === 0;
  });
  nextBtn.addEventListener('click', function(){
    if (currentStep === 1) {
      currentStep = 2;
      step1.classList.add('d-none'); step2.classList.remove('d-none');
      seg2.classList.add('done'); stepNum.textContent = '2';
      nextBtn.textContent = 'Create Event'; nextBtn.disabled = true;
      backBtn.classList.remove('d-none');
      setTimeout(function(){ titleInput.focus(); }, 150);
    } else {
      var title = titleInput.value.trim();
      addEventModal.hide();
      window.location.href = '/events/create-event?type=' + encodeURIComponent(selectedType) + '&title=' + encodeURIComponent(title);
    }
  });
  backBtn.addEventListener('click', function(){
    currentStep = 1;
    step2.classList.add('d-none'); step1.classList.remove('d-none');
    seg2.classList.remove('done'); stepNum.textContent = '1';
    nextBtn.textContent = 'Continue'; nextBtn.disabled = !selectedType;
    backBtn.classList.add('d-none');
  });

  // ---------- Scan Ticket modal ----------
  var scanTicketModalEl = document.getElementById('scanTicketModal');
  var scanTicketModal = new bootstrap.Modal(scanTicketModalEl);

  var scanStepEvent   = document.getElementById('scanStepEvent');
  var scanStepScanner = document.getElementById('scanStepScanner');
  var scanEventLabel  = document.getElementById('scanEventLabel');
  var scanResultBanner = document.getElementById('scanResultBanner');
  var scanResultIcon   = document.getElementById('scanResultIcon');
  var scanResultTitle  = document.getElementById('scanResultTitle');
  var scanResultMsg    = document.getElementById('scanResultMsg');
  var scanDetailsCard  = document.getElementById('scanDetailsCard');
  var scanApproveBtn   = document.getElementById('scanApproveBtn');
  var scanDenyBtn      = document.getElementById('scanDenyBtn');
  var scanDenyPanel    = document.getElementById('scanDenyPanel');
  var scanDenyReason   = document.getElementById('scanDenyReason');
  var scanOkBtn        = document.getElementById('scanOkBtn');

  var currentEventId = null, currentEventTitle = null, lastScanRecord = null;
  var cameraStream = null;
  var cameraActive = false; // true whenever the scanner step is open (camera loop is alive)
  var scanPaused   = false; // true from the moment a code is detected until staff taps OK

  function resetScanModal(){
    stopCameraScan();
    currentEventId = null; currentEventTitle = null; lastScanRecord = null;
    scanStepScanner.classList.add('d-none');
    scanStepEvent.classList.remove('d-none');
    scanEventLabel.textContent = "Select tonight's event to begin";
    hideScanResult();
    document.getElementById('manualTicketCode').value = '';
    document.getElementById('statCheckedIn').textContent = '–';
    document.getElementById('statRemaining').textContent = '–';
    document.getElementById('statTotal').textContent = '–';
  }
  document.querySelectorAll('.js-scan-ticket').forEach(function(btn){
    btn.addEventListener('click', function(e){ e.preventDefault(); resetScanModal(); scanTicketModal.show(); });
  });
  scanTicketModalEl.addEventListener('hidden.bs.modal', function(){ stopCameraScan(); });

  document.querySelectorAll('.scan-event-item').forEach(function(item){
    item.addEventListener('click', function(){
      currentEventId = item.dataset.eventId;
      currentEventTitle = item.dataset.eventTitle;
      scanEventLabel.textContent = 'Scanning for: ' + currentEventTitle;
      scanStepEvent.classList.add('d-none');
      scanStepScanner.classList.remove('d-none');
      hideScanResult();
      startCameraScan();
    });
  });

  var scanChangeEventBtn = document.getElementById('scanChangeEventBtn');
  if (scanChangeEventBtn) {
    scanChangeEventBtn.addEventListener('click', function(){
      stopCameraScan();
      currentEventId = null; currentEventTitle = null;
      scanStepScanner.classList.add('d-none');
      scanStepEvent.classList.remove('d-none');
      scanEventLabel.textContent = "Select tonight's event to begin";
      hideScanResult();
    });
  }

  // clears the banner/card/buttons - does NOT touch scanPaused,
  // callers decide separately whether scanning should resume
  function hideScanResult(){
    scanResultBanner.classList.add('d-none');
    scanResultBanner.classList.remove('ok','bad','warn','info');
    scanDetailsCard.classList.add('d-none');
    scanApproveBtn.classList.add('d-none');
    scanDenyBtn.classList.add('d-none');
    scanOkBtn.classList.add('d-none');
    scanDenyPanel.classList.add('d-none');
    scanDenyReason.value = '';
  }

  // staff tapped "OK, Scan Next Ticket" - only this resumes camera detection
  scanOkBtn.addEventListener('click', function(){
    hideScanResult();
    scanPaused = false;
  });

  var SCAN_STATUS_MAP = {
    pending:     { cls: 'info', icon: 'fa-circle-question',      title: 'Ticket Found — Review Below' },
    already:     { cls: 'info', icon: 'fa-circle-info',          title: 'Already Checked In' },
    wrong_event: { cls: 'warn', icon: 'fa-triangle-exclamation', title: 'Wrong Ticket' },
    denied:      { cls: 'bad',  icon: 'fa-ban',                  title: 'Previously Denied Entry' },
    invalid:     { cls: 'bad',  icon: 'fa-circle-xmark',         title: 'Invalid Ticket' }
  };

  function renderScanResult(cls, icon, title, msg){
    scanResultBanner.classList.remove('d-none','ok','bad','warn','info');
    scanResultBanner.classList.add(cls);
    scanResultIcon.className = 'fa-solid ' + icon;
    scanResultTitle.textContent = title;
    scanResultMsg.textContent = msg;
  }

  // only shows rows whose value was actually sent by the backend
  // (invalid/wrong_event send fewer fields on purpose - see api/scan_ticket.php)
  function renderScanDetails(details){
    if (!details) { scanDetailsCard.classList.add('d-none'); return; }

    document.getElementById('scanDetailName').textContent = details.name || '—';
    document.getElementById('scanDetailEvent').textContent = details.event_title || '—';
    document.getElementById('scanDetailTime').textContent = details.event_start || '—';
    document.getElementById('scanDetailTickets').textContent = details.ticket_count || '—';
    document.getElementById('scanDetailPrice').textContent = (details.price != null) ? ('₹' + Number(details.price).toLocaleString('en-IN')) : '—';

    document.getElementById('rowName').classList.toggle('d-none', details.name == null);
    document.getElementById('rowEvent').classList.toggle('d-none', !details.event_title);
    document.getElementById('rowTime').classList.toggle('d-none', !details.event_start);
    document.getElementById('rowTickets').classList.toggle('d-none', details.ticket_count == null);
    document.getElementById('rowPrice').classList.toggle('d-none', details.price == null);

    scanDetailsCard.classList.remove('d-none');
  }

  function submitTicketCode(code){
    if (!currentEventId) { return; }
    scanPaused = true; // block further detection until this result is dismissed
    renderScanResult('info', 'fa-spinner fa-spin', 'Checking…', '');
    scanDetailsCard.classList.add('d-none');
    scanApproveBtn.classList.add('d-none');
    scanDenyBtn.classList.add('d-none');
    scanOkBtn.classList.add('d-none');
    scanDenyPanel.classList.add('d-none');

    fetch('/api/scan_ticket.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ ticket_code: code, event_id: currentEventId })
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (!data.success) {
        renderScanResult('bad', 'fa-circle-xmark', 'Error', data.message);
        scanOkBtn.classList.remove('d-none'); // staff must tap OK before scanning again
        return;
      }
      if (data.stats) {
        document.getElementById('statCheckedIn').textContent = data.stats.checked_in;
        document.getElementById('statRemaining').textContent = data.stats.remaining;
        document.getElementById('statTotal').textContent = data.stats.total;
      }
      lastScanRecord = data.record || null;

      var s = SCAN_STATUS_MAP[data.status] || SCAN_STATUS_MAP.invalid;
      renderScanResult(s.cls, s.icon, s.title, data.message);
      renderScanDetails(data.details || null);

      if (data.status === 'pending' && lastScanRecord) {
        // staff must Approve or Deny - the OK button appears only after that decision
        scanApproveBtn.classList.remove('d-none');
        scanDenyBtn.classList.remove('d-none');
      } else {
        // invalid / wrong_event / denied / already - nothing to decide, just acknowledge
        scanOkBtn.classList.remove('d-none');
      }
    })
    .catch(function(){
      renderScanResult('bad', 'fa-circle-xmark', 'Network Error', 'Please try again.');
      scanOkBtn.classList.remove('d-none');
    });
  }

  document.getElementById('manualCheckInBtn').addEventListener('click', function(){
    var input = document.getElementById('manualTicketCode');
    var code = input.value.trim();
    if (!code) return;
    submitTicketCode(code);
    input.value = '';
  });
  document.getElementById('manualTicketCode').addEventListener('keydown', function(e){
    if (e.key === 'Enter') document.getElementById('manualCheckInBtn').click();
  });

  // ---------- Approve Entry flow ----------
  scanApproveBtn.addEventListener('click', function(){
    if (!lastScanRecord || !currentEventId) return;
    scanApproveBtn.disabled = true;

    fetch('/api/approve_ticket.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ type: lastScanRecord.type, id: lastScanRecord.id, event_id: currentEventId })
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
      scanApproveBtn.disabled = false;
      if (!data.success) {
        renderScanResult('bad', 'fa-circle-xmark', 'Could not approve entry', data.message);
        scanOkBtn.classList.remove('d-none');
        return;
      }
      scanApproveBtn.classList.add('d-none');
      scanDenyBtn.classList.add('d-none');
      renderScanResult('ok', 'fa-circle-check', 'Entry Approved', data.message);
      if (data.stats) {
        document.getElementById('statCheckedIn').textContent = data.stats.checked_in;
        document.getElementById('statRemaining').textContent = data.stats.remaining;
        document.getElementById('statTotal').textContent = data.stats.total;
      }
      showToast('Checked in', data.message);
      scanOkBtn.classList.remove('d-none'); // wait for staff to confirm before next scan
    })
    .catch(function(){
      scanApproveBtn.disabled = false;
      renderScanResult('bad', 'fa-circle-xmark', 'Network Error', 'Please try again.');
      scanOkBtn.classList.remove('d-none');
    });
  });

  // ---------- Deny Entry flow ----------
  scanDenyBtn.addEventListener('click', function(){
    scanDenyPanel.classList.remove('d-none');
    scanDenyReason.focus();
  });
  document.getElementById('scanDenyCancelBtn').addEventListener('click', function(){
    scanDenyPanel.classList.add('d-none');
  });
  document.getElementById('scanDenyConfirmBtn').addEventListener('click', function(){
    var reason = scanDenyReason.value.trim();
    if (!reason) { scanDenyReason.focus(); return; }
    if (!lastScanRecord || !currentEventId) return;

    fetch('/api/deny_ticket.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ type: lastScanRecord.type, id: lastScanRecord.id, event_id: currentEventId, reason: reason })
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (!data.success) {
        renderScanResult('bad', 'fa-circle-xmark', 'Could not deny entry', data.message);
        scanOkBtn.classList.remove('d-none');
        return;
      }
      scanDenyPanel.classList.add('d-none');
      scanDenyBtn.classList.add('d-none');
      scanApproveBtn.classList.add('d-none');
      renderScanResult('bad', 'fa-ban', 'Entry Denied', data.message);
      if (data.stats) {
        document.getElementById('statCheckedIn').textContent = data.stats.checked_in;
        document.getElementById('statRemaining').textContent = data.stats.remaining;
        document.getElementById('statTotal').textContent = data.stats.total;
      }
      showToast('Entry denied', data.message);
      scanOkBtn.classList.remove('d-none'); // wait for staff to confirm before next scan
    })
    .catch(function(){
      renderScanResult('bad', 'fa-circle-xmark', 'Network Error', 'Please try again.');
      scanOkBtn.classList.remove('d-none');
    });
  });

  // ---------- Live camera scan (BarcodeDetector API where supported) ----------
  // The detection loop keeps running the whole time the scanner step is open.
  // scanPaused blocks new detections from the moment a code is found until
  // staff taps OK - it must NOT stop requestAnimationFrame, or the loop dies for good.
  function startCameraScan(){
    if (!('BarcodeDetector' in window) || !navigator.mediaDevices) return; // fall back to manual entry
    var video = document.getElementById('scanVideo');
    var icon = document.getElementById('scanFrameIcon');
    var beam = document.getElementById('scanBeam');
    var detector = new BarcodeDetector({ formats: ['qr_code'] });
    cameraActive = true;
    scanPaused = false;

    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function(s){
      cameraStream = s;
      video.srcObject = s;
      video.style.display = 'block';
      icon.style.display = 'none';
      beam.style.display = 'block';
      video.play();

      (function tick(){
        if (!cameraActive) return; // only stops when modal closes / camera stopped
        if (!scanPaused) {
          detector.detect(video).then(function(codes){
            if (codes.length > 0 && !scanPaused) {
              submitTicketCode(codes[0].rawValue); // sets scanPaused = true immediately
            }
          }).catch(function(){});
        }
        requestAnimationFrame(tick); // keep the loop alive regardless of pause state
      })();
    }).catch(function(){ /* camera not available - manual entry still works */ });
  }

  function stopCameraScan(){
    cameraActive = false;
    scanPaused = false;
    if (cameraStream) { cameraStream.getTracks().forEach(function(t){ t.stop(); }); cameraStream = null; }
    var video = document.getElementById('scanVideo');
    var icon = document.getElementById('scanFrameIcon');
    var beam = document.getElementById('scanBeam');
    if (video) { video.style.display = 'none'; video.srcObject = null; }
    if (icon) { icon.style.display = 'block'; }
    if (beam) { beam.style.display = 'none'; }
  }
</script>
<?php endif; ?>
</body>
</html>