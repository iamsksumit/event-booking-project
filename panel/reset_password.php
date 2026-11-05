<?php
include 'include/config.php'; // Include the database connection file
if (session_status() === PHP_SESSION_NONE) session_start();

$error_message   = "";
$success_message = "";
$show_reset_form = false; // true once we've confirmed the token in the URL is valid

// Step 1: Handle the token in the URL
if (isset($_GET['token'])) {
    $token = $_GET['token'];

    // Validate the token and check expiry - prepared statement instead of
    // splicing $_GET['token'] directly into the query string.
    $stmt = mysqli_prepare($conn, "SELECT * FROM partner WHERE reset_token = ? AND reset_expiry > NOW()");
    mysqli_stmt_bind_param($stmt, 's', $token);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) > 0) {
        // Token is valid; fetch user data
        $user  = mysqli_fetch_assoc($result);
        $email = $user['email'];
        $show_reset_form = true;

        // Step 2: Handle new password submission
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $new_password     = trim($_POST['new_password'] ?? '');
            $confirm_password = trim($_POST['confirm_password'] ?? '');

            if (empty($new_password) || empty($confirm_password)) {
                $error_message = "All fields are required.";
            } elseif (strlen($new_password) < 6) {
                $error_message = "Password must be at least 6 characters.";
            } elseif ($new_password != $confirm_password) {
                $error_message = "Passwords do not match.";
            } else {
                // Hash the new password
                $hashed_password = md5($new_password);

                // Update the password and clear the reset token
                $update = mysqli_prepare($conn, "UPDATE partner SET password = ?, reset_token = NULL, reset_expiry = NULL WHERE email = ?");
                mysqli_stmt_bind_param($update, 'ss', $hashed_password, $email);
                if (mysqli_stmt_execute($update)) {
                    $success_message = "Your password has been reset successfully! <a href='login.php'>Login here</a>.";
                    $show_reset_form = false;
                } else {
                    $error_message = "Something went wrong. Please try again.";
                }
            }
        }
    } else {
        $error_message = "Invalid or expired token. Please request a new reset link.";
    }
} else {
    // Step 3: Handle password reset link request
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $email = trim($_POST['email'] ?? '');

        if (empty($email)) {
            $error_message = "Please enter your email address.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Please enter a valid email address.";
        } else {
            // Check if the email exists - prepared statement instead of
            // splicing $_POST['email'] directly into the query string.
            $stmt = mysqli_prepare($conn, "SELECT * FROM partner WHERE email = ?");
            mysqli_stmt_bind_param($stmt, 's', $email);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if ($result && mysqli_num_rows($result) > 0) {
                // Generate reset token and link
                $token      = bin2hex(random_bytes(50));
                $reset_link = "https://panel.highspinn.com/reset_password.php?token=$token";

                // Update reset token and expiry in the database
                $update = mysqli_prepare($conn, "UPDATE partner SET reset_token = ?, reset_expiry = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email = ?");
                mysqli_stmt_bind_param($update, 'ss', $token, $email);
                mysqli_stmt_execute($update);

                // Send reset email (configure mail settings)
                $subject = "Password Reset Request";
                $message = "Hello,\n\nPlease click the link below to reset your password:\n$reset_link\n\nThis link will expire in 1 hour.";
                $headers = "From: no-reply@highspinn.com";

                if (mail($email, $subject, $message, $headers)) {
                    $success_message = "A password reset link has been sent to your email.";
                } else {
                    $error_message = "Failed to send the password reset email. Please try again later.";
                }
            } else {
                // Deliberately vague - confirming/denying an email exists on
                // a public form is an account-enumeration leak. Consider
                // returning the same "check your email" message here as the
                // success case regardless of whether the account exists.
                $error_message = "No account found with that email address.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?php echo $show_reset_form ? 'Reset Password' : 'Forgot Password'; ?> - Highspinn</title>

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
.auth-alert.success{
  background:rgba(61,220,132,.1); border-color:rgba(61,220,132,.35); color:var(--green);
}
.auth-alert a{ color:inherit; text-decoration:underline; font-weight:700; }

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
      <?php if ($show_reset_form): ?>
        <h1>Almost there — <span class="grad-text">set a new password.</span></h1>
        <p class="lead-c">Choose something secure you haven't used before. You'll be able to sign back in right after.</p>
      <?php else: ?>
        <h1>Forgot your password? <span class="grad-text">No worries.</span></h1>
        <p class="lead-c">Enter the email on your partner account and we'll send you a link to get back in.</p>
      <?php endif; ?>
      <div class="aside-stat-row">
        <div class="aside-stat"><b>50K+</b><span>Active users</span></div>
        <div class="aside-stat"><b>80+</b><span>Partner venues</span></div>
        <div class="aside-stat"><b>1.2K+</b><span>Events hosted</span></div>
      </div>
    </div>
  </div>

  <!-- ================= FORM ================= -->
  <div class="auth-form-panel">
    <div class="auth-card">
      <div class="auth-card-icon"><i class="fa-solid fa-key"></i></div>

      <?php if ($show_reset_form): ?>

        <h3>Reset Your Password</h3>
        <p class="sub">Enter a new password for your account.</p>

        <?php if (!empty($error_message)): ?>
          <div class="auth-alert"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <div class="auth-field">
            <label for="newPassword">New password</label>
            <div class="auth-input-pill">
              <i class="fa-solid fa-lock"></i>
              <input type="password" class="form-control-plain" name="new_password" id="newPassword" placeholder="Enter new password" autocomplete="new-password" required>
              <button type="button" class="pw-toggle-btn" id="togglePw1" aria-label="Show password"><i class="fa-regular fa-eye" id="togglePwIcon1"></i></button>
            </div>
          </div>
          <div class="auth-field">
            <label for="confirmPassword">Confirm password</label>
            <div class="auth-input-pill">
              <i class="fa-solid fa-lock"></i>
              <input type="password" class="form-control-plain" name="confirm_password" id="confirmPassword" placeholder="Re-enter new password" autocomplete="new-password" required>
              <button type="button" class="pw-toggle-btn" id="togglePw2" aria-label="Show password"><i class="fa-regular fa-eye" id="togglePwIcon2"></i></button>
            </div>
          </div>
          <button type="submit" class="btn-pink">Reset Password <i class="fa-solid fa-check"></i></button>
        </form>

      <?php else: ?>

        <h3>Forgot Password</h3>
        <p class="sub">Enter your email to receive a reset link.</p>

        <?php if (!empty($error_message)): ?>
          <div class="auth-alert"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_message; ?></div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
          <div class="auth-alert success"><i class="fa-solid fa-circle-check"></i> <span><?php echo $success_message; ?></span></div>
        <?php endif; ?>

        <?php if (empty($success_message)): ?>
        <form method="POST" novalidate>
          <div class="auth-field">
            <label for="email">Email address</label>
            <div class="auth-input-pill">
              <i class="fa-regular fa-envelope"></i>
              <input type="email" name="email" id="email" placeholder="you@example.com" autocomplete="email" required>
            </div>
          </div>
          <button type="submit" class="btn-pink">Send Reset Link <i class="fa-solid fa-arrow-right"></i></button>
        </form>
        <?php endif; ?>

        <div class="auth-divider">REMEMBERED YOUR PASSWORD</div>
        <p class="auth-footer-note">Have a partner account? <a href="login.php">Sign in</a></p>

      <?php endif; ?>

    </div>
  </div>

</div>

<script>
(function(){
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
  wireToggle('togglePw1', 'newPassword', 'togglePwIcon1');
  wireToggle('togglePw2', 'confirmPassword', 'togglePwIcon2');
})();
</script>
</body>
</html>