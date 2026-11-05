<?php
include 'include/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Already signed in to the partner panel — no need to register again.
if (!empty($_SESSION['user_id'])) {
    header('Location: /index');
    exit();
}

// Cities for the registration form's city dropdown
$cities = [];
$citiesResult = $conn->query("SELECT id, name, state_id FROM tbl_cities ORDER BY name ASC");
if ($citiesResult) {
    $cities = $citiesResult->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Partner Registration - Highspinn</title>

  <link rel="shortcut icon" href="/img/favicon.ico" type="image/x-icon">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
:root{
  --void:      #0a0a0d;
  --panel:     #131317;
  --panel-2:   #1a1a1f;
  --line:      #26262c;
  --pink:      #ff3d68;
  --orange:    #ff7a45;
  --yellow:    #ffc94d;
  --green:     #3ddc84;
  --text:      #f5f5f7;
  --muted:     #96969f;
  --muted-2:   #6f6f78;
}
*{ box-sizing:border-box; }
html, body{ height:100%; }
body{
  background:var(--void);
  color:var(--text);
  font-family:'Inter',sans-serif;
  font-size:15px;
  position:relative;
  overflow-x:hidden;
}
a{ text-decoration:none; }
::selection{ background:var(--pink); color:#fff; }
h1,h2,h3,h4{ font-weight:800; letter-spacing:-0.02em; color:var(--text); }

body::before{
  content:""; position:fixed; top:-220px; right:-160px; width:560px; height:560px; border-radius:50%;
  background:radial-gradient(circle, rgba(255,61,104,.20), transparent 70%); pointer-events:none; z-index:0;
}
body::after{
  content:""; position:fixed; bottom:-260px; left:-180px; width:520px; height:520px; border-radius:50%;
  background:radial-gradient(circle, rgba(255,122,69,.14), transparent 70%); pointer-events:none; z-index:0;
}

.grad-text{
  background:linear-gradient(90deg,var(--orange),var(--pink));
  -webkit-background-clip:text; background-clip:text; color:transparent;
}

.auth-shell{ min-height:100vh; display:flex; position:relative; z-index:1; }
.auth-aside{
  flex:0 0 42%; max-width:42%; display:flex; flex-direction:column; justify-content:center;
  padding:4rem 3.5rem; background:linear-gradient(160deg, var(--panel) 0%, var(--void) 100%);
  border-right:1px solid var(--line); position:relative; overflow:hidden;
}
.auth-aside::before{
  content:""; position:absolute; top:-160px; left:-120px; width:420px; height:420px; border-radius:50%;
  background:radial-gradient(circle, rgba(255,61,104,.22), transparent 70%); pointer-events:none;
}
.auth-aside-content{ position:relative; z-index:1; }
.brand-mark-lg{
  display:inline-flex; align-items:center; gap:.6rem;
  font-weight:800; font-size:1.4rem; color:var(--text); margin-bottom:2.5rem;
}
.brand-mark-lg img{ display:block; height:38px; width:auto; }
.hero-eyebrow-pill{
  display:inline-flex; align-items:center; gap:.5rem; background:var(--panel-2); border:1px solid var(--line);
  border-radius:999px; padding:.4rem 1rem; font-size:.75rem; font-weight:700; color:var(--muted); margin-bottom:1.2rem;
}
.hero-eyebrow-pill .dot{ width:6px; height:6px; border-radius:50%; background:var(--pink); display:inline-block; }
.auth-aside h1{ font-size:clamp(1.9rem,3vw,2.6rem); line-height:1.15; margin-bottom:1rem; }
.auth-aside p.lead-c{ color:var(--muted); font-size:.92rem; max-width:380px; margin-bottom:2.2rem; }

.aside-stat-row{ display:flex; gap:1.8rem; }
.aside-stat b{ display:block; font-size:1.3rem; font-weight:800; color:var(--text); }
.aside-stat span{ font-size:.75rem; color:var(--muted-2); font-weight:600; }

.auth-form-panel{ flex:1; display:flex; align-items:center; justify-content:center; padding:2.5rem 1.5rem; }
.auth-card{
  width:100%; max-width:420px; background:var(--panel); border:1px solid var(--line); border-radius:20px;
  padding:2.4rem 2.2rem; box-shadow:0 30px 60px rgba(0,0,0,.35);
}
.auth-card-icon{
  width:48px; height:48px; border-radius:14px; background:linear-gradient(135deg,var(--pink),var(--orange));
  display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.15rem; margin-bottom:1.2rem;
}
.auth-card h3{ font-size:1.5rem; margin-bottom:.35rem; }
.auth-card .sub{ color:var(--muted); font-size:.85rem; margin-bottom:1.8rem; }

.auth-alert{
  background:rgba(255,61,104,.1); border:1px solid rgba(255,61,104,.35); color:var(--pink);
  font-size:.83rem; font-weight:600; border-radius:10px; padding:.75rem 1rem; margin-bottom:1.4rem;
  display:flex; align-items:center; gap:.55rem;
}

.auth-field{ margin-bottom:1.1rem; }
.auth-field label{ font-size:.78rem; font-weight:700; color:var(--muted); margin-bottom:.45rem; display:block; }
.auth-input-pill{
  background:var(--panel-2); border:1px solid var(--line); border-radius:10px;
  padding:.15rem .5rem .15rem 1rem; display:flex; align-items:center; gap:.6rem;
  transition:border-color .15s ease;
}
.auth-input-pill:focus-within{ border-color:var(--pink); }
.auth-input-pill i{ color:var(--muted-2); font-size:.85rem; flex:none; }
.auth-input-pill input, .auth-input-pill select{
  background:transparent; border:none; color:var(--text); font-size:.92rem; outline:none;
  width:100%; padding:.68rem 0;
}
.auth-input-pill select option{ background:var(--panel-2); color:var(--text); }
.auth-input-pill input::placeholder{ color:var(--muted-2); }
.pw-toggle-btn{ background:none; border:none; color:var(--muted-2); font-size:.85rem; padding:.4rem; flex:none; cursor:pointer; }
.pw-toggle-btn:hover{ color:var(--text); }

.btn-pink{
  background:var(--pink); border:none; color:#fff; font-weight:700;
  padding:.85rem 1.5rem; border-radius:10px; font-size:.92rem; width:100%;
  transition:filter .2s ease, transform .2s ease;
  display:flex; align-items:center; justify-content:center; gap:.5rem;
}
.btn-pink:hover{ filter:brightness(1.1); transform:translateY(-1px); color:#fff; }
.btn-pink:disabled{ opacity:.65; transform:none; cursor:not-allowed; }

.auth-divider{
  display:flex; align-items:center; gap:.8rem; margin:1.6rem 0; color:var(--muted-2); font-size:.75rem; font-weight:700;
}
.auth-divider::before, .auth-divider::after{ content:""; flex:1; height:1px; background:var(--line); }

.auth-footer-note{ text-align:center; font-size:.85rem; color:var(--muted); margin-top:.4rem; }
.auth-footer-note a{ color:var(--text); font-weight:700; }
.auth-footer-note a:hover{ color:var(--pink); }

.back-home-link{
  position:absolute; top:1.8rem; right:2rem; z-index:2;
  color:var(--muted); font-size:.83rem; font-weight:700; display:inline-flex; align-items:center; gap:.5rem;
}
.back-home-link:hover{ color:var(--pink); }

.step-dots{ display:flex; gap:.4rem; margin-bottom:1.2rem; }
.step-dots span{ height:4px; flex:1; border-radius:4px; background:var(--line); }
.step-dots span.done, .step-dots span.active{ background:var(--pink); }

.link-btn{ background:none; border:none; color:var(--pink); font-weight:700; font-size:.8rem; padding:0; cursor:pointer; }
.link-btn:hover{ text-decoration:underline; }

.d-none{ display:none !important; }

@media (max-width: 991.98px){
  .auth-aside{ display:none; }
  .auth-form-panel{ flex:1; max-width:100%; }
}
</style>
</head>
<body>

<a href="https://highspinn.com/" class="back-home-link"><i class="fa-solid fa-arrow-left"></i> Back to Highspinn</a>

<div class="auth-shell">

  <!-- ================= BRAND ASIDE ================= -->
  <div class="auth-aside">
    <div class="auth-aside-content">
      <a href="/index" class="brand-mark-lg">
        <img src="https://panel.highspinn.com/images/logo.png" alt="Highspinn">
      </a>
      <span class="hero-eyebrow-pill"><span class="dot"></span> Partner Panel</span>
      <h1>Bring your venue, <span class="grad-text">fill the room.</span></h1>
      <p class="lead-c">List your events, manage bookings and reach thousands of people looking for their next night out.</p>
      <div class="aside-stat-row">
        <div class="aside-stat"><b>50K+</b><span>Active users</span></div>
        <div class="aside-stat"><b>80+</b><span>Partner venues</span></div>
        <div class="aside-stat"><b>1.2K+</b><span>Events hosted</span></div>
      </div>
    </div>
  </div>

  <!-- ================= REGISTER FORM ================= -->
  <div class="auth-form-panel">
    <div class="auth-card">
      <div class="auth-card-icon"><i class="fa-solid fa-user-plus"></i></div>
      <h3>Register as a Partner</h3>
      <p class="sub">Verify your mobile number to get started.</p>

      <div class="step-dots" id="stepDots">
        <span class="active" data-step="1"></span>
        <span data-step="2"></span>
        <span data-step="3"></span>
      </div>

      <!-- ---------- STEP 1: PHONE ---------- -->
      <div id="stepPhone">
        <div class="auth-alert d-none" id="phoneAlert"></div>
        <form id="phoneForm" novalidate>
          <div class="auth-field">
            <label for="regPhone">Mobile number</label>
            <div class="auth-input-pill">
              <i class="fa-solid fa-mobile-screen"></i>
              <span style="color:var(--muted);font-weight:600;">+91</span>
              <input type="tel" inputmode="numeric" maxlength="15" name="phone" id="regPhone" placeholder="98765 43210" autocomplete="tel" required>
            </div>
          </div>
          <button type="submit" class="btn-pink" id="sendOtpBtn">Send OTP <i class="fa-solid fa-arrow-right"></i></button>
        </form>
      </div>

      <!-- ---------- STEP 2: OTP ---------- -->
      <div id="stepOtp" class="d-none">
        <div class="auth-alert d-none" id="otpAlert"></div>
        <p class="sub" style="margin-bottom:1.2rem;">
          Enter the 6-digit code sent to <b id="otpPhoneLabel" style="color:var(--text);"></b>
          &nbsp;<button type="button" id="changeNumberBtn" class="link-btn">Change</button>
        </p>
        <form id="otpForm" novalidate>
          <div class="auth-field">
            <label for="regOtp">OTP</label>
            <div class="auth-input-pill">
              <i class="fa-solid fa-shield-halved"></i>
              <input type="text" inputmode="numeric" maxlength="6" name="otp" id="regOtp" placeholder="123456" autocomplete="one-time-code" required>
            </div>
          </div>
          <button type="submit" class="btn-pink" id="verifyOtpBtn">Verify OTP <i class="fa-solid fa-check"></i></button>
        </form>
        <div class="text-center mt-3">
          <span id="resendTimer" class="d-none" style="color:var(--muted-2);font-size:.8rem;font-weight:700;"></span>
          <button type="button" id="resendOtpBtn" class="link-btn d-none">Resend OTP</button>
        </div>
      </div>

      <!-- ---------- STEP 3: DETAILS + PASSWORD ---------- -->
      <div id="stepDetails" class="d-none">
        <div class="auth-alert d-none" id="detailsAlert"></div>
        <form id="detailsForm" novalidate>
          <div class="auth-field">
            <label for="regFname">Full name</label>
            <div class="auth-input-pill">
              <i class="fa-regular fa-user"></i>
              <input type="text" name="name" id="regFname" placeholder="Your full name" required>
            </div>
          </div>
          <div class="auth-field">
            <label for="regEmail">Email address</label>
            <div class="auth-input-pill">
              <i class="fa-regular fa-envelope"></i>
              <input type="email" name="email" id="regEmail" placeholder="you@example.com" required>
            </div>
          </div>
          <div class="auth-field">
            <label for="regGender">Gender</label>
            <div class="auth-input-pill">
              <i class="fa-solid fa-venus-mars"></i>
              <select name="gender" id="regGender" required>
                <option value="" disabled selected>Select gender</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
              </select>
            </div>
          </div>
          <div class="auth-field">
            <label for="regCity">City</label>
            <div class="auth-input-pill">
              <i class="fa-solid fa-city"></i>
              <select name="city" id="regCity" required>
                <option value="" disabled selected>Select your city</option>
                <?php foreach ($cities as $c): ?>
                  <option value="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="auth-field">
            <label for="regPassword">Password</label>
            <div class="auth-input-pill">
              <i class="fa-solid fa-lock"></i>
              <input type="password" name="password" id="regPassword" placeholder="Create a password" autocomplete="new-password" required>
              <button type="button" class="pw-toggle-btn" id="togglePw1" aria-label="Show password"><i class="fa-regular fa-eye" id="togglePwIcon1"></i></button>
            </div>
          </div>
          <div class="auth-field">
            <label for="regPasswordConfirm">Confirm password</label>
            <div class="auth-input-pill">
              <i class="fa-solid fa-lock"></i>
              <input type="password" name="password_confirm" id="regPasswordConfirm" placeholder="Re-enter password" autocomplete="new-password" required>
              <button type="button" class="pw-toggle-btn" id="togglePw2" aria-label="Show password"><i class="fa-regular fa-eye" id="togglePwIcon2"></i></button>
            </div>
          </div>
          <button type="submit" class="btn-pink" id="registerBtn">Create account <i class="fa-solid fa-arrow-right"></i></button>
        </form>
      </div>

      <div class="auth-divider">ALREADY A PARTNER</div>
      <p class="auth-footer-note">Have a partner account? <a href="login.php">Sign in</a></p>
    </div>
  </div>

</div>

<script>
(function(){
  var stepEls = {
    1: document.getElementById('stepPhone'),
    2: document.getElementById('stepOtp'),
    3: document.getElementById('stepDetails')
  };
  var dots = document.querySelectorAll('#stepDots span');
  var state = { phone: '' };

  // Converts non-ASCII numerals (Devanagari, Arabic-Indic, etc. — common on
  // regional-locale keyboards) to plain ASCII digits before stripping
  // non-digit characters, then keeps just the last 10 digits if longer.
  function normalizePhone(raw){
    var str = String(raw || '');
    str = str.replace(/[\u0660-\u0669\u06F0-\u06F9\u0966-\u096F]/g, function(ch){
      var code = ch.charCodeAt(0);
      if (code >= 0x0660 && code <= 0x0669) return String(code - 0x0660);
      if (code >= 0x06F0 && code <= 0x06F9) return String(code - 0x06F0);
      if (code >= 0x0966 && code <= 0x096F) return String(code - 0x0966);
      return ch;
    });
    var digits = str.replace(/\D/g, '');
    return digits.length > 10 ? digits.slice(-10) : digits;
  }

  function showStep(n){
    Object.keys(stepEls).forEach(function(k){ stepEls[k].classList.toggle('d-none', Number(k) !== n); });
    dots.forEach(function(d){
      var s = Number(d.getAttribute('data-step'));
      d.classList.toggle('active', s === n);
      d.classList.toggle('done', s < n);
    });
  }
  function showAlert(el, msg){ el.textContent = msg; el.classList.remove('d-none'); }
  function hideAlert(el){ el.classList.add('d-none'); }

  function post(url, data){
    return fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams(data).toString()
    }).then(function(r){ return r.json(); });
  }

  // ---------- Step 1: phone ----------
  var phoneForm  = document.getElementById('phoneForm');
  var phoneInput = document.getElementById('regPhone');
  var phoneAlert = document.getElementById('phoneAlert');
  var sendBtn    = document.getElementById('sendOtpBtn');

  phoneInput.addEventListener('input', function(){
    phoneInput.value = normalizePhone(phoneInput.value);
  });

  phoneForm.addEventListener('submit', function(e){
    e.preventDefault();
    hideAlert(phoneAlert);
    var phone = normalizePhone(phoneInput.value);
    if (!/^\d{10}$/.test(phone)) {
      showAlert(phoneAlert, 'Please enter a valid 10-digit mobile number.');
      return;
    }
    sendBtn.disabled = true; sendBtn.textContent = 'Sending...';
    post('auth/send_otp', { phone: phone }).then(function(data){
      sendBtn.disabled = false;
      sendBtn.innerHTML = 'Send OTP <i class="fa-solid fa-arrow-right"></i>';
      if (!data.success) { showAlert(phoneAlert, data.message); return; }
      state.phone = phone;
      document.getElementById('otpPhoneLabel').textContent = '+91 ' + phone;
      showStep(2);
      startResendTimer();
    }).catch(function(){
      sendBtn.disabled = false;
      sendBtn.innerHTML = 'Send OTP <i class="fa-solid fa-arrow-right"></i>';
      showAlert(phoneAlert, 'Something went wrong. Please try again.');
    });
  });

  // ---------- Step 2: otp ----------
  var otpForm        = document.getElementById('otpForm');
  var otpInput        = document.getElementById('regOtp');
  var otpAlert          = document.getElementById('otpAlert');
  var verifyBtn           = document.getElementById('verifyOtpBtn');
  var resendBtn             = document.getElementById('resendOtpBtn');
  var resendTimerEl           = document.getElementById('resendTimer');
  var resendInterval;

  function startResendTimer(){
    var seconds = 30;
    resendBtn.classList.add('d-none');
    resendTimerEl.classList.remove('d-none');
    resendTimerEl.textContent = 'Resend OTP in ' + seconds + 's';
    clearInterval(resendInterval);
    resendInterval = setInterval(function(){
      seconds--;
      if (seconds <= 0) {
        clearInterval(resendInterval);
        resendTimerEl.classList.add('d-none');
        resendBtn.classList.remove('d-none');
        return;
      }
      resendTimerEl.textContent = 'Resend OTP in ' + seconds + 's';
    }, 1000);
  }

  document.getElementById('changeNumberBtn').addEventListener('click', function(){
    clearInterval(resendInterval);
    showStep(1);
  });

  resendBtn.addEventListener('click', function(){
    post('auth/send_otp', { phone: state.phone }).then(function(data){
      if (!data.success) { showAlert(otpAlert, data.message); return; }
      hideAlert(otpAlert);
      startResendTimer();
    });
  });

  function finishOtpLogin(msg){
    showAlert(otpAlert, msg);
    fetch('auth/otp_login_finish', { method: 'POST' })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.success) {
          window.location.href = data.redirect || '/index';
        } else {
          showAlert(otpAlert, data.message || 'This number is not linked to a partner account.');
        }
      });
  }

  otpForm.addEventListener('submit', function(e){
    e.preventDefault();
    hideAlert(otpAlert);
    var otp = otpInput.value.trim();
    if (!/^\d{6}$/.test(otp)) {
      showAlert(otpAlert, 'Enter the 6-digit OTP.');
      return;
    }
    verifyBtn.disabled = true; verifyBtn.textContent = 'Verifying...';
    post('auth/verify_otp', { phone: state.phone, otp: otp }).then(function(data){
      verifyBtn.disabled = false;
      verifyBtn.innerHTML = 'Verify OTP <i class="fa-solid fa-check"></i>';
      if (!data.success) { showAlert(otpAlert, data.message); return; }

      if (data.status === 'need_details') {
        var p = data.prefill || {};
        var fullName = ((p.fname || '') + ' ' + (p.lname || '')).trim();
        document.getElementById('regFname').value = fullName;
        document.getElementById('regEmail').value = p.email || '';
        if (p.gender) document.getElementById('regGender').value = p.gender;
        showStep(3);
        return;
      }
      if (data.status === 'logged_in') {
        finishOtpLogin('This number already has an account. Signing you in...');
        return;
      }
    }).catch(function(){
      verifyBtn.disabled = false;
      verifyBtn.innerHTML = 'Verify OTP <i class="fa-solid fa-check"></i>';
      showAlert(otpAlert, 'Something went wrong. Please try again.');
    });
  });

  // ---------- Step 3: details ----------
  var detailsForm  = document.getElementById('detailsForm');
  var detailsAlert = document.getElementById('detailsAlert');
  var registerBtn  = document.getElementById('registerBtn');

  detailsForm.addEventListener('submit', function(e){
    e.preventDefault();
    hideAlert(detailsAlert);

    var name     = document.getElementById('regFname').value.trim();
    var email    = document.getElementById('regEmail').value.trim();
    var gender   = document.getElementById('regGender').value;
    var city     = document.getElementById('regCity').value;
    var password = document.getElementById('regPassword').value;
    var confirm  = document.getElementById('regPasswordConfirm').value;

    if (!name || !email || !gender || !city || !password) {
      showAlert(detailsAlert, 'Please fill in all the fields.');
      return;
    }
    if (password.length < 6) {
      showAlert(detailsAlert, 'Password must be at least 6 characters.');
      return;
    }
    if (password !== confirm) {
      showAlert(detailsAlert, 'Passwords do not match.');
      return;
    }

    registerBtn.disabled = true; registerBtn.textContent = 'Creating account...';
    post('auth/complete_partner_profile', {
      phone: state.phone, name: name, email: email, gender: gender, city: city, password: password
    }).then(function(data){
      registerBtn.disabled = false;
      registerBtn.innerHTML = 'Create account <i class="fa-solid fa-arrow-right"></i>';
      if (!data.success) { showAlert(detailsAlert, data.message); return; }
      window.location.href = data.redirect || '/index';
    }).catch(function(){
      registerBtn.disabled = false;
      registerBtn.innerHTML = 'Create account <i class="fa-solid fa-arrow-right"></i>';
      showAlert(detailsAlert, 'Something went wrong. Please try again.');
    });
  });

  function wireToggle(btnId, inputId, iconId){
    var btn = document.getElementById(btnId), input = document.getElementById(inputId), icon = document.getElementById(iconId);
    if (!btn) return;
    btn.addEventListener('click', function(){
      var isPw = input.type === 'password';
      input.type = isPw ? 'text' : 'password';
      icon.classList.toggle('fa-eye', !isPw);
      icon.classList.toggle('fa-eye-slash', isPw);
    });
  }
  wireToggle('togglePw1', 'regPassword', 'togglePwIcon1');
  wireToggle('togglePw2', 'regPasswordConfirm', 'togglePwIcon2');
})();
</script>
</body>
</html>