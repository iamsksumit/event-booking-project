<?php
/**
 * payouts/settings.php
 * Single file, branched on $role, matching the sidebar's shared
 * /payouts/settings path for admin ("Payout Settings") vs. partner
 * ("Bank & UPI Details") -- same technique as partner/index.php's own
 * KYC review modal vs. profile/kyc.php being the partner-facing form
 * for the same underlying `partner_kyc` data.
 *
 * Admin: edit the platform-level payout settings stored in the shared
 * `settings` key/value table (same read/write pattern header.php
 * already uses for logo/preloader).
 *
 * Partner: read-only bank details pulled live from partner_kyc (never
 * editable here -- that goes through profile/kyc.php), plus an
 * editable UPI + preferred-method form backed by
 * partner_payout_details (upsert by user_id).
 *
 * Requires include/session_guard.php (sets $role, $userId) and
 * include/config.php (sets up $conn).
 */
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';          // sets up $conn (mysqli)
require_once __DIR__ . '/../include/notify.php';          // notify_user()
require_once __DIR__ . '/../include/payouts_helpers.php'; // shared payout logic

$pageTitle  = $role === 'admin' ? 'Payout Settings' : 'Bank & UPI Details';
$activePage = 'payouts-settings';

/* ===================================================================
   ADMIN: save platform payout settings
   =================================================================== */
if ($role === 'admin' && isset($_POST['save_payout_settings'])) {
    $minimum    = (float) ($_POST['payout_minimum_amount'] ?? 0);
    $schedule   = (string) ($_POST['payout_schedule'] ?? 'weekly');
    $commission = (float) ($_POST['payout_commission_percent'] ?? 0);
    $methods    = $_POST['payout_methods_enabled'] ?? [];

    $allowedSchedules = ['weekly', 'biweekly', 'monthly'];
    $allowedMethods   = ['bank', 'upi'];

    $errors = [];
    if ($minimum < 0) { $errors[] = 'Minimum payout amount must be a positive number.'; }
    if ($commission < 0 || $commission > 100) { $errors[] = 'Commission percent must be between 0 and 100.'; }
    if (!in_array($schedule, $allowedSchedules, true)) { $errors[] = 'Invalid payout schedule.'; }
    $methods = array_values(array_intersect($allowedMethods, (array) $methods));
    if (empty($methods)) { $errors[] = 'Enable at least one payout method.'; }

    if (empty($errors)) {
        $ok = save_payout_settings($conn, [
            'payout_minimum_amount'     => (string) $minimum,
            'payout_schedule'           => $schedule,
            'payout_commission_percent' => (string) $commission,
            'payout_methods_enabled'    => implode(',', $methods),
        ]);
        header('Location: /payouts/settings?' . ($ok ? 'success=' . urlencode('Payout settings saved.') : 'error=' . urlencode('Could not save settings.')));
        exit();
    }

    header('Location: /payouts/settings?error=' . urlencode(implode(' ', $errors)));
    exit();
}

/* ===================================================================
   PARTNER: save UPI + preferred method
   =================================================================== */
if ($role === 'partner' && isset($_POST['save_payout_details'])) {
    $upiId = trim($_POST['upi_id'] ?? '');
    $preferred = (string) ($_POST['preferred_method'] ?? 'bank');

    $errors = [];
    if ($upiId !== '' && !is_valid_upi_id($upiId)) {
        $errors[] = 'That UPI ID doesn\'t look valid (expected format: name@bank).';
    }
    if (!in_array($preferred, ['bank', 'upi'], true)) {
        $errors[] = 'Invalid preferred method.';
    }

    if (empty($errors)) {
        $existing = partner_payout_details_row($conn, $userId);
        if ($existing) {
            $stmt = $conn->prepare("
                UPDATE partner_payout_details
                SET upi_id = ?, preferred_method = ?
                WHERE user_id = ?
            ");
            $stmt->bind_param('ssi', $upiId, $preferred, $userId);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO partner_payout_details (user_id, upi_id, preferred_method)
                VALUES (?, ?, ?)
            ");
            $stmt->bind_param('iss', $userId, $upiId, $preferred);
        }
        $ok = $stmt->execute();
        $stmt->close();

        header('Location: /payouts/settings?' . ($ok ? 'success=' . urlencode('Payout details saved.') : 'error=' . urlencode($conn->error)));
        exit();
    }

    header('Location: /payouts/settings?error=' . urlencode(implode(' ', $errors)));
    exit();
}

/* ===================================================================
   VIEW DATA
   =================================================================== */
$settings = get_payout_settings($conn);

if ($role === 'partner') {
    $kyc     = partner_kyc_row($conn, $userId);
    $details = partner_payout_details_row($conn, $userId);
    $hasBankDetails = $kyc
        && trim((string) ($kyc['bank_account_number'] ?? '')) !== ''
        && trim((string) ($kyc['bank_ifsc'] ?? '')) !== '';
    $hasUpi = $details && trim((string) ($details['upi_id'] ?? '')) !== '';
}

include __DIR__ . '/../include/header.php';
/* header.php has already opened <div class="content"> */
?>

<?php if ($role === 'admin'): ?>

<div class="page-head">
  <div>
    <h2>Payout Settings</h2>
    <p>Platform-wide rules for partner payouts</p>
  </div>
</div>

<div class="panel-card" style="max-width:640px;">
  <div class="head">
    <div>
      <h5>Payout Rules</h5>
      <div class="sub">These apply to every partner on the platform</div>
    </div>
  </div>

  <form method="POST" action="">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-c">Minimum Payout Amount (₹)</label>
        <input type="number" step="0.01" min="0" class="form-control-c" name="payout_minimum_amount"
               value="<?php echo htmlspecialchars((string) $settings['payout_minimum_amount']); ?>" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-c">Payout Schedule</label>
        <select class="form-control-c" name="payout_schedule">
          <?php foreach (['weekly' => 'Weekly', 'biweekly' => 'Bi-weekly', 'monthly' => 'Monthly'] as $val => $label): ?>
            <option value="<?php echo $val; ?>" <?php echo $settings['payout_schedule'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label-c">Commission (%)</label>
        <input type="number" step="0.01" min="0" max="100" class="form-control-c" name="payout_commission_percent"
               value="<?php echo htmlspecialchars((string) $settings['payout_commission_percent']); ?>" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-c">Enabled Methods</label>
        <div class="d-flex gap-3 mt-2">
          <label class="d-flex align-items-center gap-2">
            <input type="checkbox" name="payout_methods_enabled[]" value="bank"
                   <?php echo in_array('bank', $settings['methods_enabled'], true) ? 'checked' : ''; ?>>
            Bank Transfer
          </label>
          <label class="d-flex align-items-center gap-2">
            <input type="checkbox" name="payout_methods_enabled[]" value="upi"
                   <?php echo in_array('upi', $settings['methods_enabled'], true) ? 'checked' : ''; ?>>
            UPI
          </label>
        </div>
      </div>
    </div>

    <div class="mt-4 d-flex justify-content-end">
      <button type="submit" class="btn-pink-sm" name="save_payout_settings"><i class="fa-solid fa-floppy-disk"></i> Save Settings</button>
    </div>
  </form>
</div>

<?php else: /* ===================== PARTNER VIEW ===================== */ ?>

<div class="page-head">
  <div>
    <h2>Bank &amp; UPI Details</h2>
    <p>Where your payouts get sent</p>
  </div>
</div>

<div class="panel-card mb-3">
  <div class="head">
    <div>
      <h5>Bank Details</h5>
      <div class="sub">Managed on your KYC page and reviewed by an admin</div>
    </div>
  </div>

  <?php if ($hasBankDetails): ?>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-c">Account Holder Name</label>
        <input type="text" class="form-control-c" value="<?php echo htmlspecialchars($kyc['bank_account_name'] ?? ''); ?>" disabled>
      </div>
      <div class="col-md-6">
        <label class="form-label-c">Bank Name</label>
        <input type="text" class="form-control-c" value="<?php echo htmlspecialchars($kyc['bank_name'] ?? ''); ?>" disabled>
      </div>
      <div class="col-md-6">
        <label class="form-label-c">Account Number</label>
        <input type="text" class="form-control-c" value="<?php echo htmlspecialchars($kyc['bank_account_number'] ?? ''); ?>" disabled>
      </div>
      <div class="col-md-6">
        <label class="form-label-c">IFSC Code</label>
        <input type="text" class="form-control-c" value="<?php echo htmlspecialchars($kyc['bank_ifsc'] ?? ''); ?>" disabled>
      </div>
    </div>
    <div class="cell-sub mt-3">To change these, update your <a href="/profile/kyc">KYC details</a> -- edits go through admin review.</div>
  <?php else: ?>
    <div class="payout-banner">
      <i class="fa-solid fa-circle-info"></i>
      <div>Add your bank details on the <a href="/profile/kyc">KYC page</a>.</div>
    </div>
  <?php endif; ?>
</div>

<div class="panel-card">
  <div class="head">
    <div>
      <h5>UPI Details</h5>
      <div class="sub">Optional -- add a UPI ID as an alternative payout method</div>
    </div>
  </div>

  <form method="POST" action="">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-c">UPI ID</label>
        <input type="text" class="form-control-c" name="upi_id" placeholder="yourname@bank"
               value="<?php echo htmlspecialchars($details['upi_id'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-c">Preferred Payout Method</label>
        <select class="form-control-c" name="preferred_method">
          <?php if ($hasBankDetails): ?>
            <option value="bank" <?php echo (($details['preferred_method'] ?? 'bank') === 'bank') ? 'selected' : ''; ?>>Bank Transfer</option>
          <?php endif; ?>
          <?php if ($hasUpi): ?>
            <option value="upi" <?php echo (($details['preferred_method'] ?? '') === 'upi') ? 'selected' : ''; ?>>UPI</option>
          <?php endif; ?>
          <?php if (!$hasBankDetails && !$hasUpi): ?>
            <option value="bank">Add a method below first</option>
          <?php endif; ?>
        </select>
      </div>
    </div>

    <div class="mt-4 d-flex justify-content-end">
      <button type="submit" class="btn-pink-sm" name="save_payout_details"><i class="fa-solid fa-floppy-disk"></i> Save UPI Details</button>
    </div>
  </form>
</div>

<?php endif; ?>

<style>
  .payout-banner{ display:flex; gap:.6rem; align-items:flex-start; background:var(--panel-2); border:1px solid var(--line); border-radius:10px; padding:.8rem 1rem; font-size:.82rem; color:var(--muted); }
  .payout-banner i{ color:var(--pink); margin-top:.15rem; }
  .payout-banner a{ color:var(--pink); font-weight:700; }
</style>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    <?php if (isset($_GET['success'])): ?>
    showToast('Success', <?php echo json_encode($_GET['success']); ?>);
    <?php elseif (isset($_GET['error'])): ?>
    showToast('Something went wrong', <?php echo json_encode($_GET['error']); ?>);
    <?php endif; ?>
  });
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>