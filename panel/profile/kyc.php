<?php
/**
 * profile/kyc.php ("KYC & Verification")
 * Partner-facing counterpart to admin's partner/index.php review screen.
 * Lets the logged-in partner submit / update their own KYC record: business
 * details, bank details, government ID, and document uploads — then wait
 * for an admin to Approve it (see partner/index.php on the admin side).
 *
 * LOCKING BEHAVIOUR: once kyc_status = 1 (Approved), every field on this
 * page renders disabled and the save button is hidden -- the partner can't
 * change approved details. The form only becomes editable again once an
 * admin flips kyc_status back to 0 via the Unapprove action on
 * partner/index.php (an admin note alone, without unapproving, does NOT
 * re-open the form -- see the callout in the UI when a note exists on an
 * approved record). The disabling is enforced server-side too, not just
 * with the `disabled` attribute: a raw POST to save_kyc while kyc_status=1
 * is rejected outright, so this can't be bypassed by re-enabling inputs in
 * devtools.
 *
 * Same `partner_kyc` table as the admin screen (one row per partner, keyed
 * by user_id), same field set, same govt ID / business type option lists,
 * so a submission here shows up in the admin review modal unchanged.
 *
 * `kyc_status` (0 = Pending, 1 = Approved) is admin-controlled only — this
 * page never writes 1. Any edit the partner makes (while unlocked) resets
 * an already-approved record back to Pending -- though in practice that
 * path is now unreachable, since the form is locked while status=1.
 * `admin_notes` is admin-controlled too: this page displays it (e.g. a
 * rejection or clarification reason) but never lets the partner submit it.
 *
 * Uploaded documents are sensitive, so — same as the admin side — they're
 * stored under /storage/kyc/{user_id}/ (outside the public webroot logic,
 * behind storage/kyc/.htaccess) and only ever served through
 * profile/kyc-document.php, which re-checks that the requester owns the
 * record on every request.
 *
 * Requires include/session_guard.php (sets $role, $userId) and
 * include/config.php (sets up $conn). Partner-only.
 */
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';          // sets up $conn (mysqli)
require_once __DIR__ . '/../include/notify.php';          // notify_role()

if ($role !== 'partner') {
    header('Location: /index');
    exit();
}

$pageTitle  = 'KYC & Verification';
$activePage = 'kyc';

$govtIdTypes = [
    'aadhaar'         => 'Aadhaar Card',
    'pan'             => 'PAN Card',
    'passport'        => 'Passport',
    'voter_id'        => 'Voter ID',
    'driving_license' => 'Driving Licence',
];
$businessTypes = ['Sole Proprietorship', 'Partnership', 'LLP', 'Private Limited', 'Other'];
$docFields     = ['govt_id_document', 'pan_document', 'business_proof_document'];
$docLabels     = [
    'govt_id_document'        => 'Government ID Document',
    'pan_document'             => 'PAN Card Document',
    'business_proof_document'  => 'Business Proof Document',
];

$errors = [];

/* ===================== helpers ===================== */
function fetch_kyc($conn, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM partner_kyc WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

$kyc = fetch_kyc($conn, $userId);
$isLocked = ((int) ($kyc['kyc_status'] ?? 0)) === 1;

/* ===================== handle: save KYC ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_kyc'])) {
    // Server-side enforcement of the lock -- never trust the client to have
    // kept the fields disabled. Re-fetch fresh in case it changed mid-request.
    $freshKyc = fetch_kyc($conn, $userId);
    if ($freshKyc && (int) $freshKyc['kyc_status'] === 1) {
        header('Location: /profile/kyc?error=' . urlencode('Your KYC is approved and locked. Contact support if details need to change.'));
        exit();
    }

    $fields = [
        'business_name'       => trim($_POST['business_name'] ?? ''),
        'business_type'       => trim($_POST['business_type'] ?? ''),
        'gstin'                => strtoupper(trim($_POST['gstin'] ?? '')),
        'pan_number'           => strtoupper(trim($_POST['pan_number'] ?? '')),
        'bank_account_name'    => trim($_POST['bank_account_name'] ?? ''),
        'bank_account_number'  => trim($_POST['bank_account_number'] ?? ''),
        'bank_ifsc'            => strtoupper(trim($_POST['bank_ifsc'] ?? '')),
        'bank_name'            => trim($_POST['bank_name'] ?? ''),
        'govt_id_type'         => trim($_POST['govt_id_type'] ?? ''),
        'govt_id_number'       => trim($_POST['govt_id_number'] ?? ''),
    ];

    if ($fields['business_name'] === '') {
        $errors[] = 'Business name is required.';
    }
    if ($fields['business_type'] !== '' && !in_array($fields['business_type'], $businessTypes, true)) {
        $fields['business_type'] = '';
    }
    if ($fields['govt_id_type'] !== '' && !isset($govtIdTypes[$fields['govt_id_type']])) {
        $fields['govt_id_type'] = '';
    }
    if ($fields['gstin'] !== '' && !preg_match('/^[0-9A-Z]{15}$/', $fields['gstin'])) {
        $errors[] = 'GSTIN must be exactly 15 characters (letters and numbers only).';
    }
    if ($fields['pan_number'] !== '' && !preg_match('/^[A-Z0-9]{10}$/', $fields['pan_number'])) {
        $errors[] = 'PAN number must be exactly 10 characters.';
    }
    if ($fields['bank_ifsc'] !== '' && !preg_match('/^[A-Z0-9]{4,11}$/', $fields['bank_ifsc'])) {
        $errors[] = 'Please enter a valid IFSC code.';
    }

    // Handle each document upload; keep the existing file if none was supplied.
    $docPaths = [];
    if (!$errors) {
        foreach ($docFields as $docField) {
            $docPaths[$docField] = $kyc[$docField] ?? null;
            if (isset($_FILES[$docField]) && $_FILES[$docField]['error'] === UPLOAD_ERR_OK && $_FILES[$docField]['name'] !== '') {
                $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
                $ext = strtolower(pathinfo($_FILES[$docField]['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) {
                    $errors[] = $docLabels[$docField] . ' must be a JPG, PNG, WEBP, or PDF file.';
                    continue;
                }
                if ($_FILES[$docField]['size'] > 8 * 1024 * 1024) {
                    $errors[] = $docLabels[$docField] . ' must be under 8MB.';
                    continue;
                }
                $target_dir = __DIR__ . '/../storage/kyc/' . $userId . '/';
                if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }
                $safe_name = $docField . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES[$docField]['tmp_name'], $target_dir . $safe_name)) {
                    $docPaths[$docField] = $userId . '/' . $safe_name;
                } else {
                    $errors[] = 'Could not save the uploaded ' . $docLabels[$docField] . '. Please try again.';
                }
            }
        }
    }

    if (!$errors) {
        // Any partner-initiated save goes back to Pending -- an admin needs
        // to re-review. admin_notes is intentionally left alone.
        if ($kyc) {
            $stmt = $conn->prepare(
                "UPDATE partner_kyc SET
                    business_name=?, business_type=?, gstin=?, pan_number=?,
                    bank_account_name=?, bank_account_number=?, bank_ifsc=?, bank_name=?,
                    govt_id_type=?, govt_id_number=?,
                    govt_id_document=?, pan_document=?, business_proof_document=?,
                    kyc_status=0
                 WHERE user_id=?"
            );
            $stmt->bind_param(
                'sssssssssssssi',
                $fields['business_name'], $fields['business_type'], $fields['gstin'], $fields['pan_number'],
                $fields['bank_account_name'], $fields['bank_account_number'], $fields['bank_ifsc'], $fields['bank_name'],
                $fields['govt_id_type'], $fields['govt_id_number'],
                $docPaths['govt_id_document'], $docPaths['pan_document'], $docPaths['business_proof_document'],
                $userId
            );
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO partner_kyc
                    (user_id, business_name, business_type, gstin, pan_number,
                     bank_account_name, bank_account_number, bank_ifsc, bank_name,
                     govt_id_type, govt_id_number,
                     govt_id_document, pan_document, business_proof_document, kyc_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)"
            );
            $stmt->bind_param(
                'issssssssssss',
                $userId,
                $fields['business_name'], $fields['business_type'], $fields['gstin'], $fields['pan_number'],
                $fields['bank_account_name'], $fields['bank_account_number'], $fields['bank_ifsc'], $fields['bank_name'],
                $fields['govt_id_type'], $fields['govt_id_number'],
                $docPaths['govt_id_document'], $docPaths['pan_document'], $docPaths['business_proof_document']
            );
        }
        if ($stmt->execute()) {
            notify_role(
                $conn,
                'admin',
                'kyc',
                'New KYC submission',
                ($fields['business_name'] ?: 'A partner') . ' ' . ($kyc ? 're-submitted' : 'submitted') . ' KYC details for review.',
                '/partner'
            );
            header('Location: /profile/kyc?success=' . urlencode('KYC details submitted for review.'));
            exit();
        }
        $errors[] = 'Something went wrong while saving: ' . $conn->error;
    }

    // Re-render with what they just typed on error, without losing it.
    $kyc = array_merge($kyc ?? ['kyc_status' => 0], $fields, $docPaths ?? []);
    $isLocked = false; // they just tried to save, so the form was (correctly) unlocked
}

$status = (int) ($kyc['kyc_status'] ?? -1);
// -1 = never submitted, 0 = pending review, 1 = approved
$statusLabel = $status === 1 ? 'Approved' : ($status === 0 ? 'Pending Review' : 'Not Submitted');
$statusClass = $status === 1 ? 'badge-live' : ($status === 0 ? 'badge-pending' : 'badge-draft');
$disabledAttr = $isLocked ? 'disabled' : '';

include __DIR__ . '/../include/header.php';
/* header.php has already opened <div class="content"> */
?>

<div class="page-head">
  <div>
    <h2>KYC &amp; Verification</h2>
    <p>Business, bank &amp; government ID details reviewed by our team before payouts are enabled</p>
  </div>
  <span class="badge-pill <?php echo $statusClass; ?>" style="font-size:.78rem;padding:.45rem .9rem;">
    <?php echo $statusLabel; ?>
  </span>
</div>

<?php if ($errors): ?>
  <div class="alert-box alert-err">
    <ul>
      <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php elseif (isset($_GET['error'])): ?>
  <div class="alert-box alert-err"><?php echo htmlspecialchars($_GET['error']); ?></div>
<?php endif; ?>

<?php if ($isLocked): ?>
  <div class="alert-box alert-locked">
    <i class="fa-solid fa-lock"></i>
    <div>
      Your KYC is <strong>verified and locked</strong>. These details can't be edited while approved.
      If something needs to change, contact support — an admin will need to unapprove your account first.
    </div>
  </div>
<?php elseif ($status === 0): ?>
  <div class="alert-box alert-pending">
    <i class="fa-solid fa-hourglass-half"></i>
    Your KYC is under review. We'll notify you once it's approved.
  </div>
<?php endif; ?>

<?php if (!empty($kyc['admin_notes'])): ?>
  <div class="alert-box alert-note">
    <i class="fa-solid fa-comment-dots"></i>
    <div>
      <div class="cell-title" style="font-size:.82rem;">Note from our team</div>
      <div><?php echo nl2br(htmlspecialchars($kyc['admin_notes'])); ?></div>
    </div>
  </div>
<?php endif; ?>

<div class="panel-card">
  <div class="head">
    <div>
      <h5>KYC Details</h5>
      <div class="sub"><?php echo $isLocked ? 'Locked while approved — read only' : 'All fields are required for payouts to be enabled on your account'; ?></div>
    </div>
  </div>

  <form method="POST" action="" enctype="multipart/form-data">
    <div class="kyc-section-label">Business Details</div>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-c">Business Name</label>
        <input type="text" class="form-control-c" name="business_name" required <?php echo $disabledAttr; ?> value="<?php echo htmlspecialchars($kyc['business_name'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-c">Business Type</label>
        <select class="form-control-c" name="business_type" <?php echo $disabledAttr; ?>>
          <option value="">—</option>
          <?php foreach ($businessTypes as $bt): ?>
            <option value="<?php echo htmlspecialchars($bt); ?>" <?php echo ($kyc['business_type'] ?? '') === $bt ? 'selected' : ''; ?>><?php echo htmlspecialchars($bt); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label-c">GSTIN</label>
        <input type="text" class="form-control-c" name="gstin" id="k_gstin" maxlength="15" placeholder="15-character GSTIN" <?php echo $disabledAttr; ?> value="<?php echo htmlspecialchars($kyc['gstin'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-c">PAN Number</label>
        <input type="text" class="form-control-c" name="pan_number" id="k_pan_number" maxlength="10" placeholder="10-character PAN" <?php echo $disabledAttr; ?> value="<?php echo htmlspecialchars($kyc['pan_number'] ?? ''); ?>">
      </div>
      <div class="col-12">
        <label class="form-label-c">Business Proof Document</label>
        <?php if (!$isLocked): ?>
          <input type="file" class="form-control-c" name="business_proof_document" accept="image/*,.pdf">
        <?php endif; ?>
        <div class="doc-row">
          <span class="doc-status"><?php echo !empty($kyc['business_proof_document']) ? 'Document on file' : 'No document uploaded yet'; ?></span>
          <?php if (!empty($kyc['business_proof_document'])): ?>
            <a href="/profile/kyc-document.php?field=business_proof_document" target="_blank" rel="noopener">View current</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="kyc-section-label">Bank Details</div>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-c">Account Holder Name</label>
        <input type="text" class="form-control-c" name="bank_account_name" <?php echo $disabledAttr; ?> value="<?php echo htmlspecialchars($kyc['bank_account_name'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-c">Bank Name</label>
        <input type="text" class="form-control-c" name="bank_name" <?php echo $disabledAttr; ?> value="<?php echo htmlspecialchars($kyc['bank_name'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-c">Account Number</label>
        <input type="text" class="form-control-c" name="bank_account_number" <?php echo $disabledAttr; ?> value="<?php echo htmlspecialchars($kyc['bank_account_number'] ?? ''); ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-c">IFSC Code</label>
        <input type="text" class="form-control-c" name="bank_ifsc" maxlength="11" <?php echo $disabledAttr; ?> value="<?php echo htmlspecialchars($kyc['bank_ifsc'] ?? ''); ?>">
      </div>
    </div>

    <div class="kyc-section-label">Government ID</div>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-c">ID Type</label>
        <select class="form-control-c" name="govt_id_type" <?php echo $disabledAttr; ?>>
          <option value="">—</option>
          <?php foreach ($govtIdTypes as $val => $label): ?>
            <option value="<?php echo htmlspecialchars($val); ?>" <?php echo ($kyc['govt_id_type'] ?? '') === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label-c">ID Number</label>
        <input type="text" class="form-control-c" name="govt_id_number" <?php echo $disabledAttr; ?> value="<?php echo htmlspecialchars($kyc['govt_id_number'] ?? ''); ?>">
      </div>
      <div class="col-12">
        <label class="form-label-c">ID Document (front)</label>
        <?php if (!$isLocked): ?>
          <input type="file" class="form-control-c" name="govt_id_document" accept="image/*,.pdf">
        <?php endif; ?>
        <div class="doc-row">
          <span class="doc-status"><?php echo !empty($kyc['govt_id_document']) ? 'Document on file' : 'No document uploaded yet'; ?></span>
          <?php if (!empty($kyc['govt_id_document'])): ?>
            <a href="/profile/kyc-document.php?field=govt_id_document" target="_blank" rel="noopener">View current</a>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-12">
        <label class="form-label-c">PAN Card Document</label>
        <?php if (!$isLocked): ?>
          <input type="file" class="form-control-c" name="pan_document" accept="image/*,.pdf">
        <?php endif; ?>
        <div class="doc-row">
          <span class="doc-status"><?php echo !empty($kyc['pan_document']) ? 'Document on file' : 'No document uploaded yet'; ?></span>
          <?php if (!empty($kyc['pan_document'])): ?>
            <a href="/profile/kyc-document.php?field=pan_document" target="_blank" rel="noopener">View current</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!$isLocked): ?>
      <div style="margin-top:1.5rem;display:flex;justify-content:flex-end;">
        <button type="submit" class="btn-pink-sm" name="save_kyc">
          <i class="fa-solid fa-floppy-disk"></i> <?php echo $kyc ? 'Save & Resubmit for Review' : 'Submit for Review'; ?>
        </button>
      </div>
    <?php endif; ?>
  </form>
</div>

<style>
  .alert-box{ border-radius:12px; padding:.9rem 1.2rem; margin-bottom:1.2rem; font-size:.84rem; border:1px solid; display:flex; align-items:flex-start; gap:.7rem; }
  .alert-box ul{ margin:0; padding-left:1.1rem; }
  .alert-err{ background:rgba(255,61,104,.08); border-color:rgba(255,61,104,.35); color:#ffb3c2; }
  .alert-locked{ background:rgba(61,220,132,.08); border-color:rgba(61,220,132,.3); color:var(--green); }
  .alert-pending{ background:rgba(255,201,77,.08); border-color:rgba(255,201,77,.3); color:var(--yellow); }
  .alert-note{ background:var(--panel-2); border-color:var(--line); color:var(--text); }
  .alert-note i{ color:var(--pink); margin-top:.15rem; }

  .kyc-section-label{ font-size:.72rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--muted-2); margin:1.3rem 0 .7rem; }
  .kyc-section-label:first-child{ margin-top:0; }
  .doc-row{ display:flex; align-items:center; justify-content:space-between; gap:.6rem; background:var(--panel-2); border:1px solid var(--line); border-radius:10px; padding:.6rem .8rem; margin-top:.4rem; }
  .doc-row .doc-status{ font-size:.76rem; color:var(--muted); }
  .doc-row a{ color:var(--pink); font-size:.76rem; font-weight:700; }
  .form-control-c:disabled{ opacity:.6; cursor:not-allowed; }
</style>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('k_gstin')?.addEventListener('input', function (e) { e.target.value = e.target.value.toUpperCase(); });
    document.getElementById('k_pan_number')?.addEventListener('input', function (e) { e.target.value = e.target.value.toUpperCase(); });

    <?php if (isset($_GET['success'])): ?>
    showToast('Success', <?php echo json_encode($_GET['success']); ?>);
    <?php endif; ?>
  });
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>