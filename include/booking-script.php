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

  // COSMETIC SESSION COUNTDOWN (10:00)
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

  // STEP NAVIGATION
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

  // STEP 1: dates + passes
  document.querySelectorAll('.date-opt').forEach(function(opt){
    if (opt.closest('#stepPanel3')) return;
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

    if (addBtn.disabled) return;

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

  // STEP 2: mobile + OTP
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

  // RESTORE TICKET/DATE SELECTIONS AFTER A LOGIN RELOAD
  function restoreSelections(){
    var savedTickets = sessionStorage.getItem('hs_selected_tickets');
    if (savedTickets){
      try {
        var saved = JSON.parse(savedTickets);
        for (var tid in saved){
          var row = document.querySelector('.pass-row[data-id="' + tid + '"]');
          if (row && row.__hsSetQty){
            row.__hsSetQty(saved[tid].qty);
          } else if (row){
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

  // STEP 3
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

  // someone-else toggle
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

  // new-user "save & continue"
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

  // SUMMARY LISTS + BOTTOM BAR
  var lastKnownTotal = 0;

  function updateCtaButton(){
    var cta = document.getElementById('ctaBtn');
    var hasPasses = Object.keys(ticketData).length > 0;
    if (currentStep === 1){
      cta.disabled = !hasPasses;
      cta.innerHTML = 'Continue <i class="fa-solid fa-arrow-right ms-1"></i>';
    } else if (currentStep === 2){
      cta.disabled = true;
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
      return;
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

    // Free ticket flow
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

    // Paid tickets
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

  // resume the wizard after a login/registration reload
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
