<?php
/**
 * profile/index.php ("Business Profile")
 * Account-details page for both admins and partners: name, gender, city,
 * email, contact number, profile photo, and password. Shared by both roles
 * since it's the same low-sensitivity account data either way; the
 * partner-only KYC nudge card and role badge below adapt based on $role.
 *
 * NOTE: this route lives at /profile, which include/session_guard.php also
 * uses as its bounce destination for ordinary (user_type = 0) accounts on
 * every guarded page. If that fallback hasn't been changed to point
 * somewhere else, an ordinary user hitting any guarded page will loop
 * between here and session_guard.php forever. Flagging this again in code
 * in case it gets missed -- see the note at the top of my reply.
 *
 * This is deliberately separate from profile/kyc.php: everything here is
 * low-sensitivity account info either role can change freely and instantly
 * (no admin review). Business/bank/government-ID data that DOES need admin
 * review lives in profile/kyc.php against the `partner_kyc` table, and is
 * partner-only.
 *
 * Matches the shared dark dashboard theme and uses include/header.php +
 * include/footer.php like every other panel screen (see partner/index.php
 * on the admin side).
 *
 * Requires include/session_guard.php (sets $role, $userId) and
 * include/config.php (sets up $conn). Admin or partner.
 *
 * Profile photos are ordinary (non-sensitive) uploads, so — unlike KYC
 * documents — they're stored under the public /images/profile/ folder,
 * matching the existing convention already used for `users.image_url`
 * elsewhere in this codebase.
 */
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';          // sets up $conn (mysqli)

if (!in_array($role, ['admin', 'partner'], true)) {
    header('Location: /index');
    exit();
}

$pageTitle  = 'Business Profile';
$activePage = 'profile';

$errors  = [];

/* ===================== fetch current user ===================== */
function fetch_user($conn, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM partner WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

$user = fetch_user($conn, $userId);
if (!$user) {
    header('Location: /logout.php');
    exit();
}

/* ===================== handle: update profile ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $fname     = trim($_POST['fname'] ?? '');
    $lname     = trim($_POST['lname'] ?? '');
    $gender    = trim($_POST['gender'] ?? '');
    $city      = trim($_POST['city'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $contactno = trim($_POST['contactno'] ?? '');

    if ($fname === '') {
        $errors[] = 'First name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($contactno !== '' && !preg_match('/^[0-9+\-\s]{6,15}$/', $contactno)) {
        $errors[] = 'Please enter a valid contact number.';
    }

    // Email must stay unique across users (excluding this account).
    if (!$errors) {
        $stmt = $conn->prepare("SELECT id FROM partner WHERE email = ? AND id != ?");
        $stmt->bind_param('si', $email, $userId);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $errors[] = 'That email address is already in use by another account.';
        }
    }

    // Optional profile photo upload.
    $image_url = $user['image_url'];
    if (!$errors && isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK && $_FILES['profile_photo']['name'] !== '') {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $errors[] = 'Profile photo must be a JPG, PNG, or WEBP image.';
        } elseif ($_FILES['profile_photo']['size'] > 4 * 1024 * 1024) {
            $errors[] = 'Profile photo must be under 4MB.';
        } else {
            $target_dir = __DIR__ . '/../images/profile/';
            if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }
            $safe_name = $userId . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $target_dir . $safe_name)) {
                $image_url = '/images/profile/' . $safe_name;
            } else {
                $errors[] = 'Could not save the uploaded photo. Please try again.';
            }
        }
    }

    // Optional password change.
    $newPasswordHash = null;
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    if (!$errors && ($currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '')) {
        $storedHash = $user['password'] ?? '';
        $currentOk  = password_verify($currentPassword, $storedHash) || hash_equals((string) $storedHash, $currentPassword);
        if (!$currentOk) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        } else {
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        }
    }

    if (!$errors) {
        if ($newPasswordHash !== null) {
            $stmt = $conn->prepare(
                "UPDATE partner SET fname=?, lname=?, gender=?, city=?, email=?, contactno=?, image_url=?, password=? WHERE id=?"
            );
            $stmt->bind_param('ssssssssi', $fname, $lname, $gender, $city, $email, $contactno, $image_url, $newPasswordHash, $userId);
        } else {
            $stmt = $conn->prepare(
                "UPDATE partner SET fname=?, lname=?, gender=?, city=?, email=?, contactno=?, image_url=? WHERE id=?"
            );
            $stmt->bind_param('sssssssi', $fname, $lname, $gender, $city, $email, $contactno, $image_url, $userId);
        }
        if ($stmt->execute()) {
            header('Location: /profile?success=' . urlencode('Profile updated successfully.'));
            exit();
        }
        $errors[] = 'Something went wrong while saving: ' . $conn->error;
    }

    // Re-render with submitted values on error, without losing what they typed.
    $user = array_merge($user, [
        'fname' => $fname, 'lname' => $lname, 'gender' => $gender, 'city' => $city,
        'email' => $email, 'contactno' => $contactno, 'image_url' => $image_url,
    ]);
}

include __DIR__ . '/../include/header.php';
/* header.php has already opened <div class="content"> */
?>

<div class="page-head">
  <div>
    <h2>Business Profile</h2>
    <p>Your account details — visible on receipts, notifications, and support tickets</p>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert-box alert-err">
    <ul>
      <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="panel-card text-center">
      <div class="profile-photo-wrap">
        <?php if (!empty($user['image_url'])): ?>
          <img src="<?php echo htmlspecialchars($user['image_url']); ?>" alt="Profile photo" class="profile-photo">
        <?php else: ?>
          <div class="profile-photo profile-photo-fallback">
            <?php echo htmlspecialchars(strtoupper(substr($user['fname'] ?? 'P', 0, 1) . substr($user['lname'] ?? 'T', 0, 1))); ?>
          </div>
        <?php endif; ?>
      </div>
      <h5 style="margin-top:1rem;"><?php echo htmlspecialchars(trim(($user['fname'] ?? '') . ' ' . ($user['lname'] ?? '')) ?: ucfirst($role)); ?></h5>
      <div class="cell-sub"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
      <div style="margin-top:.9rem;">
        <span class="badge-pill badge-live"><?php echo $role === 'admin' ? 'Admin' : 'Partner'; ?></span>
      </div>
      <?php if ($role === 'partner'): ?>
      <div class="kyc-nudge">
        <i class="fa-solid fa-id-card"></i>
        <div>
          <div class="cell-title" style="font-size:.82rem;">Business &amp; KYC details</div>
          <div class="cell-sub">GSTIN, PAN, bank &amp; government ID live on a separate, admin-reviewed page.</div>
        </div>
      </div>
      <a href="/profile/kyc" class="btn-mini" style="width:100%;justify-content:center;margin-top:.9rem;display:inline-flex;">
        <i class="fa-solid fa-arrow-right"></i> Go to KYC &amp; Verification
      </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="panel-card">
      <div class="head">
        <div>
          <h5>Account Details</h5>
          <div class="sub">Basic info used across the platform</div>
        </div>
      </div>

      <form method="POST" action="" enctype="multipart/form-data">
        <div class="kyc-section-label">Personal Details</div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label-c">First Name</label>
            <input type="text" class="form-control-c" name="fname" required value="<?php echo htmlspecialchars($user['fname'] ?? ''); ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label-c">Last Name</label>
            <input type="text" class="form-control-c" name="lname" value="<?php echo htmlspecialchars($user['lname'] ?? ''); ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label-c">Gender</label>
            <select class="form-control-c" name="gender">
              <option value="">—</option>
              <?php foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $val => $lbl): ?>
                <option value="<?php echo $val; ?>" <?php echo strtolower($user['gender'] ?? '') === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label-c">City</label>
            <input type="text" class="form-control-c" name="city" value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>">
          </div>
          <div class="col-12">
            <label class="form-label-c">Profile Photo</label>
            <input type="file" class="form-control-c" name="profile_photo" accept="image/png,image/jpeg,image/webp">
          </div>
        </div>

        <div class="kyc-section-label">Contact Details</div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label-c">Email Address</label>
            <input type="email" class="form-control-c" name="email" required value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label-c">Contact Number</label>
            <input type="text" class="form-control-c" name="contactno" value="<?php echo htmlspecialchars($user['contactno'] ?? ''); ?>">
          </div>
        </div>

        <div class="kyc-section-label">Change Password <span class="cell-sub" style="text-transform:none;font-weight:600;letter-spacing:normal;">(optional)</span></div>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label-c">Current Password</label>
            <input type="password" class="form-control-c" name="current_password" autocomplete="current-password">
          </div>
          <div class="col-md-4">
            <label class="form-label-c">New Password</label>
            <input type="password" class="form-control-c" name="new_password" autocomplete="new-password">
          </div>
          <div class="col-md-4">
            <label class="form-label-c">Confirm New Password</label>
            <input type="password" class="form-control-c" name="confirm_password" autocomplete="new-password">
          </div>
        </div>

        <div style="margin-top:1.5rem;display:flex;justify-content:flex-end;">
          <button type="submit" class="btn-pink-sm" name="update_profile"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
  .alert-box{ border-radius:12px; padding:.9rem 1.2rem; margin-bottom:1.4rem; font-size:.84rem; border:1px solid; }
  .alert-box ul{ margin:0; padding-left:1.1rem; }
  .alert-err{ background:rgba(255,61,104,.08); border-color:rgba(255,61,104,.35); color:#ffb3c2; }

  .profile-photo-wrap{ display:flex; justify-content:center; }
  .profile-photo{ width:92px; height:92px; border-radius:20px; object-fit:cover; border:1px solid var(--line); }
  .profile-photo-fallback{
    display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1.6rem; color:#fff;
    background:linear-gradient(135deg,var(--pink),var(--orange));
  }
  .kyc-nudge{ display:flex; align-items:flex-start; gap:.7rem; text-align:left; background:var(--panel-2); border:1px solid var(--line); border-radius:12px; padding:.85rem .9rem; margin-top:1.2rem; }
  .kyc-nudge i{ color:var(--pink); margin-top:.15rem; }

  .kyc-section-label{ font-size:.72rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--muted-2); margin:1.3rem 0 .7rem; }
  .kyc-section-label:first-child{ margin-top:0; }
</style>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    <?php if (isset($_GET['success'])): ?>
    showToast('Success', <?php echo json_encode($_GET['success']); ?>);
    <?php endif; ?>
  });
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>