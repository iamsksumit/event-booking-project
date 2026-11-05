<?php
include 'include/config.php';  // Include the database connection file

// Start session to manage user login status
if (session_status() === PHP_SESSION_NONE) session_start();

// Already signed in — skip straight to the panel.
if (!empty($_SESSION['user_id'])) {
    header('Location: /index');
    exit();
}

// Define an empty variable for error messages
$error_message = "";

// Handle password-login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['pass'])) {
    $email    = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['pass'] ?? '');

    $stmt = $conn->prepare("SELECT * FROM partner WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $valid = false;
    if ($user) {
        if (password_verify($password, $user['password'])) {
            $valid = true;
        } elseif (hash_equals((string) $user['password'], md5($password))) {
            // legacy md5 account from before the switch to password_hash —
            // it checks out, so upgrade it to a proper hash transparently
            $valid = true;
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE partner SET password = ? WHERE id = ?");
            $upd->bind_param('si', $newHash, $user['id']);
            $upd->execute();
            $upd->close();
        }
    }

    if ($valid && in_array((string) $user['user_type'], ['2', '3'], true)) {
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_fname'] = $user['fname'];
        $_SESSION['user_lname'] = $user['lname'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_type']  = $user['user_type'];
        header('Location: /index');
        exit();
    } elseif ($valid) {
        $error_message = "This account isn't set up for the partner panel.";
    } else {
        $error_message = "Invalid email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Partner Sign In - Highspinn</title>

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
  background:radial-gradient(circle, rgba(255,61,104,.20), transparent 70%); pointer-events:none; z-dashboard:0;
}
body::after{
  content:""; position:fixed; bottom:-260px; left:-180px; width:520px; height:520px; border-radius:50%;
  background:radial-gradient(circle, rgba(255,122,69,.14), transparent 70%); pointer-events:none; z-dashboard:0;
}

.grad-text{
  background:linear-gradient(90deg,var(--orange),var(--pink));
  -webkit-background-clip:text; background-clip:text; color:transparent;
}

.auth-shell{ min-height:100vh; display:flex; position:relative; z-dashboard:1; }
.auth-aside{
  flex:0 0 42%; max-width:42%; display:flex; flex-direction:column; justify-content:center;
  padding:4rem 3.5rem; background:linear-gradient(160deg, var(--panel) 0%, var(--void) 100%);
  border-right:1px solid var(--line); position:relative; overflow:hidden;
}
.auth-aside::before{
  content:""; position:absolute; top:-160px; left:-120px; width:420px; height:420px; border-radius:50%;
  background:radial-gradient(circle, rgba(255,61,104,.22), transparent 70%); pointer-events:none;
}
.auth-aside-content{ position:relative; z-dashboard:1; }
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
.auth-input-pill input{
  background:transparent; border:none; color:var(--text); font-size:.92rem; outline:none;
  width:100%; padding:.68rem 0;
}
.auth-input-pill input::placeholder{ color:var(--muted-2); }
.pw-toggle-btn{
  background:none; border:none; color:var(--muted-2); font-size:.85rem; padding:.4rem; flex:none; cursor:pointer;
}
.pw-toggle-btn:hover{ color:var(--text); }

.auth-forgot{ display:flex; justify-content:flex-end; margin:-.3rem 0 1.4rem; }
.auth-forgot a{ color:var(--muted); font-size:.8rem; font-weight:700; }
.auth-forgot a:hover{ color:var(--pink); }

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
  position:absolute; top:1.8rem; right:2rem; z-dashboard:2;
  color:var(--muted); font-size:.83rem; font-weight:700; display:inline-flex; align-items:center; gap:.5rem;
}
.back-home-link:hover{ color:var(--pink); }

.auth-tabs{ display:flex; gap:.4rem; background:var(--panel-2); border:1px solid var(--line); border-radius:10px; padding:.3rem; margin-bottom:1.6rem; }
.auth-tab-btn{ flex:1; background:transparent; border:none; color:var(--muted); font-weight:700; font-size:.82rem; padding:.55rem; border-radius:8px; cursor:pointer; }
.auth-tab-btn.active{ background:var(--pink); color:#fff; }

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
      <h1>Run your nights, <span class="grad-text">from one dashboard.</span></h1>
      <p class="lead-c">Manage events, track bookings and grow your audience across every city Highspinn is live in.</p>
      <div class="aside-stat-row">
        <div class="aside-stat"><b>50K+</b><span>Active users</span></div>
        <div class="aside-stat"><b>80+</b><span>Partner venues</span></div>
        <div class="aside-stat"><b>1.2K+</b><span>Events hosted</span></div>
      </div>
    </div>
  </div>

  <!-- ================= LOGIN FORM ================= -->
  <div class="auth-form-panel">
    <div class="auth-card">
      <div class="auth-card-icon"><i class="fa-solid fa-right-to-bracket"></i></div>
      <h3>Sign in</h3>
      <p class="sub">Enter your credentials to access the partner panel.</p>

      <?php if (!empty($error_message)): ?>
        <div class="auth-alert"><i class="fa-solid fa-circle-exclamation"></i><?php echo htmlspecialchars($error_message); ?></div>
      <?php endif; ?>

      <div class="auth-tabs">
        <button type="button" class="auth-tab-btn active" data-tab="password" id="tabPasswordBtn">Password</button>
        <button type="button" class="auth-tab-btn" data-tab="otp" id="tabOtpBtn">OTP</button>
      </div>

      <!-- ---------- PASSWORD LOGIN ---------- -->
      <div id="passwordPanel">
        <form action="" method="POST" novalidate>
          <div class="auth-field">
            <label for="loginEmail">Email address</label>
            <div class="auth-input-pill">
              <i class="fa-regular fa-envelope"></i>
              <input type="email" name="email" id="loginEmail" placeholder="you@example.com" autocomplete="email" required>
            </div>
          </div>

          <div class="auth-field">
            <label for="loginPassword">Password</label>
            <div class="auth-input-pill">
              <i class="fa-solid fa-lock"></i>
              <input type="password" name="pass" id="loginPassword" placeholder="Enter your password" autocomplete="current-password" required>
              <button type="button" class="pw-toggle-btn" id="togglePw" aria-label="Show password"><i class="fa-regular fa-eye" id="togglePwIcon"></i></button>
            </div>
          </div>

          <div class="auth-forgot">
            <a href="reset_password">Forgot password?</a>
          </div>

          <button type="submit" class="btn-pink">
            Sign In <i class="fa-solid fa-arrow-right"></i>
          </button>
        </form>
      </div>

      <!-- ---------- OTP LOGIN ---------- -->
      <div id="otpPanel" class="d-none">

        <div id="otpStepPhone">
          <div class="auth-alert d-none" id="otpPhoneAlert"></div>
          <form id="otpPhoneForm" novalidate>
            <div class="auth-field">
              <label for="otpLoginPhone">Mobile number</label>
              <div class="auth-input-pill">
                <i class="fa-solid fa-mobile-screen"></i>
                <span style="color:var(--muted);font-weight:600;">+91</span>
                <input type="tel" inputmode="numeric" maxlength="15" name="phone" id="otpLoginPhone" placeholder="98765 43210" autocomplete="tel" required>
              </div>
            </div>
            <button type="submit" class="btn-pink" id="otpSendBtn">Send OTP <i class="fa-solid fa-arrow-right"></i></button>
          </form>
        </div>

        <div id="otpStepCode" class="d-none">
          <div class="auth-alert d-none" id="otpCodeAlert"></div>
          <p class="sub" style="margin-bottom:1.2rem;">
            Enter the 6-digit code sent to <b id="otpLoginPhoneLabel" style="color:var(--text);"></b>
            &nbsp;<button type="button" id="otpChangeNumberBtn" class="link-btn">Change</button>
          </p>
          <form id="otpCodeForm" novalidate>
            <div class="auth-field">
              <label for="otpLoginCode">OTP</label>
              <div class="auth-input-pill">
                <i class="fa-solid fa-shield-halved"></i>
                <input type="text" inputmode="numeric" maxlength="6" name="otp" id="otpLoginCode" placeholder="123456" autocomplete="one-time-code" required>
              </div>
            </div>
            <button type="submit" class="btn-pink" id="otpVerifyBtn">Verify &amp; Sign In <i class="fa-solid fa-check"></i></button>
          </form>
          <div class="text-center mt-3">
            <span id="otpResendTimer" class="d-none" style="color:var(--muted-2);font-size:.8rem;font-weight:700;"></span>
            <button type="button" id="otpResendBtn" class="link-btn d-none">Resend OTP</button>
          </div>
        </div>

      </div>

      <div class="auth-divider">NEW TO HIGHSPINN</div>

      <p class="auth-footer-note">Don't have a partner account? <a href="register">Register now</a></p>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  // ---------- password show/hide ----------
  var toggleBtn = document.getElementById('togglePw');
  var pwInput   = document.getElementById('loginPassword');
  var pwIcon    = document.getElementById('togglePwIcon');
  if (toggleBtn) {
    toggleBtn.addEventListener('click', function(){
      var isPw = pwInput.type === 'password';
      pwInput.type = isPw ? 'text' : 'password';
      pwIcon.classList.toggle('fa-eye', !isPw);
      pwIcon.classList.toggle('fa-eye-slash', isPw);
    });
  }

  // ---------- tab switching ----------
  var tabPasswordBtn = document.getElementById('tabPasswordBtn');
  var tabOtpBtn       = document.getElementById('tabOtpBtn');
  var passwordPanel     = document.getElementById('passwordPanel');
  var otpPanel            = document.getElementById('otpPanel');

  tabPasswordBtn.addEventListener('click', function(){
    tabPasswordBtn.classList.add('active');
    tabOtpBtn.classList.remove('active');
    passwordPanel.classList.remove('d-none');
    otpPanel.classList.add('d-none');
  });
  tabOtpBtn.addEventListener('click', function(){
    tabOtpBtn.classList.add('active');
    tabPasswordBtn.classList.remove('active');
    otpPanel.classList.remove('d-none');
    passwordPanel.classList.add('d-none');
  });

  // ---------- OTP login flow ----------
  var state = { phone: '' };
  var stepPhoneEl = document.getElementById('otpStepPhone');
  var stepCodeEl  = document.getElementById('otpStepCode');

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

  function post(url, data){
    return fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams(data).toString()
    }).then(function(r){ return r.json(); });
  }
  function showAlert(el, msg){ el.textContent = msg; el.classList.remove('d-none'); }
  function hideAlert(el){ el.classList.add('d-none'); }

  var phoneForm  = document.getElementById('otpPhoneForm');
  var phoneInput = document.getElementById('otpLoginPhone');
  var phoneAlert = document.getElementById('otpPhoneAlert');
  var sendBtn    = document.getElementById('otpSendBtn');

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
      document.getElementById('otpLoginPhoneLabel').textContent = '+91 ' + phone;
      stepPhoneEl.classList.add('d-none');
      stepCodeEl.classList.remove('d-none');
      startResendTimer();
    }).catch(function(){
      sendBtn.disabled = false;
      sendBtn.innerHTML = 'Send OTP <i class="fa-solid fa-arrow-right"></i>';
      showAlert(phoneAlert, 'Something went wrong. Please try again.');
    });
  });

  document.getElementById('otpChangeNumberBtn').addEventListener('click', function(){
    clearInterval(resendInterval);
    stepCodeEl.classList.add('d-none');
    stepPhoneEl.classList.remove('d-none');
  });

  var resendBtn      = document.getElementById('otpResendBtn');
  var resendTimerEl  = document.getElementById('otpResendTimer');
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
  resendBtn.addEventListener('click', function(){
    post('auth/send_otp', { phone: state.phone }).then(function(data){
      var codeAlert = document.getElementById('otpCodeAlert');
      if (!data.success) { showAlert(codeAlert, data.message); return; }
      hideAlert(codeAlert);
      startResendTimer();
    });
  });

  var codeForm  = document.getElementById('otpCodeForm');
  var codeInput = document.getElementById('otpLoginCode');
  var codeAlert = document.getElementById('otpCodeAlert');
  var verifyBtn = document.getElementById('otpVerifyBtn');

  codeForm.addEventListener('submit', function(e){
    e.preventDefault();
    hideAlert(codeAlert);
    var otp = codeInput.value.trim();
    if (!/^\d{6}$/.test(otp)) {
      showAlert(codeAlert, 'Enter the 6-digit OTP.');
      return;
    }
    verifyBtn.disabled = true; verifyBtn.textContent = 'Verifying...';
    post('auth/verify_otp', { phone: state.phone, otp: otp }).then(function(data){
      if (!data.success) {
        verifyBtn.disabled = false;
        verifyBtn.innerHTML = 'Verify &amp; Sign In <i class="fa-solid fa-check"></i>';
        showAlert(codeAlert, data.message);
        return;
      }
      if (data.status === 'need_details') {
        verifyBtn.disabled = false;
        verifyBtn.innerHTML = 'Verify &amp; Sign In <i class="fa-solid fa-check"></i>';
        showAlert(codeAlert, "No partner account found for this number. Please register instead.");
        return;
      }
      // status === 'logged_in' -> bridge into the partner-panel session
      fetch('auth/otp_login_finish', { method: 'POST' })
        .then(function(r){ return r.json(); })
        .then(function(finishData){
          verifyBtn.disabled = false;
          verifyBtn.innerHTML = 'Verify &amp; Sign In <i class="fa-solid fa-check"></i>';
          if (finishData.success) {
            window.location.href = finishData.redirect || '/index';
          } else {
            showAlert(codeAlert, finishData.message || 'This number is not linked to a partner account.');
          }
        });
    }).catch(function(){
      verifyBtn.disabled = false;
      verifyBtn.innerHTML = 'Verify &amp; Sign In <i class="fa-solid fa-check"></i>';
      showAlert(codeAlert, 'Something went wrong. Please try again.');
    });
  });
})();
</script>
</body>
</html>