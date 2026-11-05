<?php
/**
 * partner/index.php ("Event Partners")
 * Lists partner accounts (user_type = 2, per include/session_guard.php's
 * mapping) with a KYC review workflow: business details, bank details,
 * government ID, document uploads, and an Approve / Unapprove action.
 *
 * Matches the shared dark dashboard theme and uses include/header.php +
 * include/footer.php like every other admin screen (see place/index.php).
 *
 * Requires include/session_guard.php (sets $role, $userId) and
 * include/config.php (sets up $conn). Admin-only.
 *
 * KYC data lives in a separate `partner_kyc` table (one row per partner),
 * NOT on `users` — see migration_partner_kyc.sql, which must be run first.
 * Approval is its own `kyc_status` column on that table (per your choice
 * not to reuse `users.status`, which sidebar.php already uses for a
 * different, older "Verified" badge — see the note at the bottom of my
 * reply for why those two can now drift out of sync).
 *
 * Uploaded documents (government ID, PAN, business proof) are NOT stored
 * under the public /images/ folder like other uploads in this codebase --
 * these are sensitive personal documents. They're stored under
 * /storage/kyc/ (with a .htaccess denying direct access, see
 * storage/kyc/.htaccess) and only ever served through kyc-document.php,
 * which re-checks admin auth on every request.
 *
 * Toggling KYC status here fires a notification to the partner (see
 * include/notify.php) so they see "Approved" / "needs another look" show
 * up in their own bell dropdown without needing to be told separately.
 */
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';          // sets up $conn (mysqli)
require_once __DIR__ . '/../include/notify.php';          // notify_user()

if ($role !== 'admin') {
    header('Location: /profile');
    exit();
}

$pageTitle  = 'Event Partners';
$activePage = 'partners';

$govtIdTypes = [
    'aadhaar'         => 'Aadhaar Card',
    'pan'             => 'PAN Card',
    'passport'        => 'Passport',
    'voter_id'        => 'Voter ID',
    'driving_license' => 'Driving Licence',
];
$businessTypes = ['Sole Proprietorship', 'Partnership', 'LLP', 'Private Limited', 'Other'];
$docFields     = ['govt_id_document', 'pan_document', 'business_proof_document'];

/* ===================== helpers ===================== */
function fetch_kyc($conn, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM partner_kyc WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/* ===================== handle: CSV export ===================== */
/* Deliberately excludes GSTIN / PAN / bank details / government ID number --
   keeping bulk-exportable data to what was already non-sensitive before. */
if (isset($_POST['export_csv'])) {
    $filename = 'partners_data_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'First Name', 'Last Name', 'Gender', 'Email', 'Contact No', 'Business Name', 'KYC Status', 'Registered Date']);

    $res = $conn->query("
        SELECT u.*, k.business_name, k.kyc_status
        FROM partner u
        LEFT JOIN partner_kyc k ON k.user_id = u.id
        WHERE u.user_type = 2
        ORDER BY u.id DESC
    ");
    while ($u = $res->fetch_assoc()) {
        fputcsv($output, [
            $u['id'], $u['fname'], $u['lname'], $u['gender'], $u['email'], $u['contactno'],
            $u['business_name'] ?: '',
            ((int) ($u['kyc_status'] ?? 0) === 1) ? 'Approved' : 'Pending',
            $u['posting_date'],
        ]);
    }
    fclose($output);
    exit();
}

/* ===================== handle: save KYC ===================== */
if (isset($_POST['save_kyc'])) {
    $user_id = (int) ($_POST['user_id'] ?? 0);

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
        'admin_notes'          => trim($_POST['admin_notes'] ?? ''),
    ];
    if ($fields['govt_id_type'] !== '' && !isset($govtIdTypes[$fields['govt_id_type']])) {
        $fields['govt_id_type'] = '';
    }

    $existing = fetch_kyc($conn, $user_id);

    // Handle each document upload; keep the existing file if none was supplied.
    $docPaths = [];
    foreach ($docFields as $docField) {
        $docPaths[$docField] = $existing[$docField] ?? null;
        if (isset($_FILES[$docField]) && $_FILES[$docField]['error'] === UPLOAD_ERR_OK && $_FILES[$docField]['name'] !== '') {
            $target_dir = __DIR__ . '/../storage/kyc/' . $user_id . '/';
            if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }
            $ext       = strtolower(pathinfo($_FILES[$docField]['name'], PATHINFO_EXTENSION));
            $ext       = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
            $safe_name = $docField . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES[$docField]['tmp_name'], $target_dir . $safe_name)) {
                $docPaths[$docField] = $user_id . '/' . $safe_name;
            }
        }
    }

    if ($existing) {
        $stmt = $conn->prepare(
            "UPDATE partner_kyc SET
                business_name=?, business_type=?, gstin=?, pan_number=?,
                bank_account_name=?, bank_account_number=?, bank_ifsc=?, bank_name=?,
                govt_id_type=?, govt_id_number=?, admin_notes=?,
                govt_id_document=?, pan_document=?, business_proof_document=?
             WHERE user_id=?"
        );
        // 14 string fields + 1 int (user_id) = 15 bound values -> type string must be 15 chars.
        $stmt->bind_param(
            'ssssssssssssssi',
            $fields['business_name'], $fields['business_type'], $fields['gstin'], $fields['pan_number'],
            $fields['bank_account_name'], $fields['bank_account_number'], $fields['bank_ifsc'], $fields['bank_name'],
            $fields['govt_id_type'], $fields['govt_id_number'], $fields['admin_notes'],
            $docPaths['govt_id_document'], $docPaths['pan_document'], $docPaths['business_proof_document'],
            $user_id
        );
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO partner_kyc
                (user_id, business_name, business_type, gstin, pan_number,
                 bank_account_name, bank_account_number, bank_ifsc, bank_name,
                 govt_id_type, govt_id_number, admin_notes,
                 govt_id_document, pan_document, business_proof_document, kyc_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)"
        );
        // 1 int (user_id) + 14 string fields = 15 bound values -> type string must be 15 chars.
        $stmt->bind_param(
            'issssssssssssss',
            $user_id,
            $fields['business_name'], $fields['business_type'], $fields['gstin'], $fields['pan_number'],
            $fields['bank_account_name'], $fields['bank_account_number'], $fields['bank_ifsc'], $fields['bank_name'],
            $fields['govt_id_type'], $fields['govt_id_number'], $fields['admin_notes'],
            $docPaths['govt_id_document'], $docPaths['pan_document'], $docPaths['business_proof_document']
        );
    }
    $ok = $stmt->execute();

    // If the admin left a note while saving (e.g. "PAN image is blurry,
    // please re-upload"), let the partner know even though status itself
    // didn't change here -- the toggle button is a separate action below.
    if ($ok && $fields['admin_notes'] !== '' && (!$existing || $fields['admin_notes'] !== ($existing['admin_notes'] ?? ''))) {
        notify_user(
            $conn,
            $user_id,
            'kyc',
            'New note on your KYC submission',
            $fields['admin_notes'],
            '/partner/kyc'
        );
    }

    header('Location: /partner?' . ($ok ? 'success=' . urlencode('KYC details saved.') : 'error=' . urlencode($conn->error)));
    exit();
}

/* ===================== handle: approve / unapprove toggle ===================== */
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'toggle-kyc') {
    $uid      = (int) $_GET['id'];
    $existing = fetch_kyc($conn, $uid);

    if ($existing) {
        $new = 1 - (int) $existing['kyc_status'];
        $stmt = $conn->prepare("UPDATE partner_kyc SET kyc_status = ? WHERE user_id = ?");
        $stmt->bind_param('ii', $new, $uid);
        $stmt->execute();
    } else {
        $new = 1;
        $stmt = $conn->prepare("INSERT INTO partner_kyc (user_id, kyc_status) VALUES (?, ?)");
        $stmt->bind_param('ii', $uid, $new);
        $stmt->execute();
    }

    notify_user(
        $conn,
        $uid,
        'kyc',
        $new === 1 ? 'KYC approved' : 'KYC approval revoked',
        $new === 1
            ? 'Your KYC details have been approved. Payouts are now enabled on your account.'
            : 'Your KYC approval has been revoked. Please review your details and resubmit.',
        '/partner/kyc'
    );

    header('Location: /partner?success=' . urlencode('KYC status updated.'));
    exit();
}

/* ===================== fetch list (paginated) ===================== */
$perPage = 15;
$page    = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) { $page = 1; }

$totalPartners = (int) ($conn->query("SELECT COUNT(*) c FROM partner WHERE user_type = 2")->fetch_assoc()['c'] ?? 0);
$totalPages    = max(1, (int) ceil($totalPartners / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

$stmt = $conn->prepare("
    SELECT u.*, k.business_name, k.kyc_status,
           k.business_type, k.gstin, k.pan_number,
           k.bank_account_name, k.bank_account_number, k.bank_ifsc, k.bank_name,
           k.govt_id_type, k.govt_id_number, k.admin_notes,
           k.govt_id_document, k.pan_document, k.business_proof_document
    FROM partner u
    LEFT JOIN partner_kyc k ON k.user_id = u.id
    WHERE u.user_type = 2
    ORDER BY u.id DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$partners = $stmt->get_result();

include __DIR__ . '/../include/header.php';
/* header.php has already opened <div class="content"> */
?>

<div class="page-head">
  <div>
    <h2>Event Partners</h2>
    <p><?php echo number_format($totalPartners); ?> partner<?php echo $totalPartners === 1 ? '' : 's'; ?> on the platform</p>
  </div>
  <form method="POST" action="" class="d-inline">
    <button type="submit" name="export_csv" class="btn-mini"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
  </form>
</div>

<div class="panel-card">
  <div class="head">
    <div>
      <h5>All Partners</h5>
      <div class="sub">Review business, bank &amp; government ID details before approving</div>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table-clean">
      <thead>
        <tr>
          <th>Partner</th>
          <th>Business</th>
          <th>Contact</th>
          <th>Registered</th>
          <th>KYC Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($partners && $partners->num_rows): while ($p = $partners->fetch_assoc()):
          $approved = (int) ($p['kyc_status'] ?? 0) === 1;
          $fullName = trim(($p['fname'] ?? '') . ' ' . ($p['lname'] ?? '')) ?: '—';
        ?>
          <tr>
            <td>
              <div class="cell-title"><?php echo htmlspecialchars($fullName); ?></div>
              <div class="cell-sub"><?php echo htmlspecialchars($p['email'] ?: '—'); ?></div>
            </td>
            <td class="cell-sub"><?php echo htmlspecialchars($p['business_name'] ?: 'Not submitted'); ?></td>
            <td class="cell-sub"><?php echo htmlspecialchars($p['contactno'] ?: '—'); ?></td>
            <td class="cell-sub"><?php echo htmlspecialchars($p['posting_date']); ?></td>
            <td>
              <span class="badge-pill <?php echo $approved ? 'badge-live' : 'badge-pending'; ?>">
                <?php echo $approved ? 'Approved' : 'Pending'; ?>
              </span>
            </td>
            <td style="text-align:right;">
              <div class="d-flex justify-content-end gap-1">
                <button type="button" class="btn-mini js-review-kyc"
                        data-id="<?php echo (int) $p['id']; ?>"
                        data-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES); ?>"
                        data-email="<?php echo htmlspecialchars($p['email'], ENT_QUOTES); ?>"
                        data-business-name="<?php echo htmlspecialchars($p['business_name'] ?? '', ENT_QUOTES); ?>"
                        data-business-type="<?php echo htmlspecialchars($p['business_type'] ?? '', ENT_QUOTES); ?>"
                        data-gstin="<?php echo htmlspecialchars($p['gstin'] ?? '', ENT_QUOTES); ?>"
                        data-pan-number="<?php echo htmlspecialchars($p['pan_number'] ?? '', ENT_QUOTES); ?>"
                        data-bank-account-name="<?php echo htmlspecialchars($p['bank_account_name'] ?? '', ENT_QUOTES); ?>"
                        data-bank-account-number="<?php echo htmlspecialchars($p['bank_account_number'] ?? '', ENT_QUOTES); ?>"
                        data-bank-ifsc="<?php echo htmlspecialchars($p['bank_ifsc'] ?? '', ENT_QUOTES); ?>"
                        data-bank-name="<?php echo htmlspecialchars($p['bank_name'] ?? '', ENT_QUOTES); ?>"
                        data-govt-id-type="<?php echo htmlspecialchars($p['govt_id_type'] ?? '', ENT_QUOTES); ?>"
                        data-govt-id-number="<?php echo htmlspecialchars($p['govt_id_number'] ?? '', ENT_QUOTES); ?>"
                        data-admin-notes="<?php echo htmlspecialchars($p['admin_notes'] ?? '', ENT_QUOTES); ?>"
                        data-has-govt-doc="<?php echo $p['govt_id_document'] ? '1' : '0'; ?>"
                        data-has-pan-doc="<?php echo $p['pan_document'] ? '1' : '0'; ?>"
                        data-has-business-doc="<?php echo $p['business_proof_document'] ? '1' : '0'; ?>">
                  <i class="fa-solid fa-id-card"></i> Review KYC
                </button>
                <a href="?action=toggle-kyc&id=<?php echo (int) $p['id']; ?>"
                   class="btn-mini js-toggle-kyc"
                   data-approved="<?php echo $approved ? '1' : '0'; ?>"
                   style="<?php echo $approved ? 'color:var(--pink);border-color:var(--pink);' : ''; ?>">
                  <?php if ($approved): ?>
                    <i class="fa-solid fa-rotate-left"></i> Unapprove
                  <?php else: ?>
                    <i class="fa-solid fa-check"></i> Approve
                  <?php endif; ?>
                </a>
              </div>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="6" class="cell-sub text-center">No partners yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pager-c">
    <div class="pager-info">
      Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $totalPartners); ?> of <?php echo $totalPartners; ?>
    </div>
    <div class="pager-links">
      <a class="pager-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo max(1, $page - 1); ?>"><i class="fa-solid fa-chevron-left"></i></a>
      <?php
        $start = max(1, $page - 2);
        $end   = min($totalPages, $start + 4);
        $start = max(1, $end - 4);
        for ($p2 = $start; $p2 <= $end; $p2++):
      ?>
        <a class="pager-link <?php echo $p2 === $page ? 'active' : ''; ?>" href="?page=<?php echo $p2; ?>"><?php echo $p2; ?></a>
      <?php endfor; ?>
      <a class="pager-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="?page=<?php echo min($totalPages, $page + 1); ?>"><i class="fa-solid fa-chevron-right"></i></a>
    </div>
  </div>
  <?php endif; ?>
</div>

<style>
  .pager-c{ display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.8rem; padding-top:1.1rem; margin-top:1.1rem; border-top:1px solid var(--line); }
  .pager-info{ font-size:.78rem; color:var(--muted-2); font-weight:600; }
  .pager-links{ display:flex; align-items:center; gap:.35rem; }
  .pager-link{ min-width:34px; height:34px; padding:0 .55rem; border-radius:8px; border:1px solid var(--line); background:var(--panel-2); color:var(--muted); font-size:.8rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
  .pager-link:hover{ border-color:var(--pink); color:var(--text); }
  .pager-link.active{ background:var(--pink); border-color:var(--pink); color:#fff; }
  .pager-link.disabled{ opacity:.4; pointer-events:none; }

  .kyc-section-label{ font-size:.72rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--muted-2); margin:1.3rem 0 .7rem; }
  .kyc-section-label:first-child{ margin-top:0; }
  .doc-row{ display:flex; align-items:center; justify-content:space-between; gap:.6rem; background:var(--panel-2); border:1px solid var(--line); border-radius:10px; padding:.6rem .8rem; margin-top:.4rem; }
  .doc-row .doc-status{ font-size:.76rem; color:var(--muted); }
  .doc-row a{ color:var(--pink); font-size:.76rem; font-weight:700; }
</style>

<!-- ================= REVIEW KYC MODAL ================= -->
<div class="modal fade" id="kycModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form role="form" method="POST" action="" enctype="multipart/form-data" id="kycForm">
        <div class="modal-header">
          <div>
            <h5 id="kycModalTitle">Review KYC</h5>
            <div class="cell-sub" id="kycModalSub">Business, bank &amp; government ID details</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="user_id" id="k_user_id">

          <div class="kyc-section-label">Business Details</div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label-c">Business Name</label>
              <input type="text" class="form-control-c" name="business_name" id="k_business_name">
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Business Type</label>
              <select class="form-control-c" name="business_type" id="k_business_type">
                <option value="">—</option>
                <?php foreach ($businessTypes as $bt): ?>
                  <option value="<?php echo htmlspecialchars($bt); ?>"><?php echo htmlspecialchars($bt); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label-c">GSTIN</label>
              <input type="text" class="form-control-c" name="gstin" id="k_gstin" maxlength="15" placeholder="15-character GSTIN">
            </div>
            <div class="col-md-6">
              <label class="form-label-c">PAN Number</label>
              <input type="text" class="form-control-c" name="pan_number" id="k_pan_number" maxlength="10" placeholder="10-character PAN">
            </div>
            <div class="col-12">
              <label class="form-label-c">Business Proof Document</label>
              <input type="file" class="form-control-c" name="business_proof_document" accept="image/*,.pdf">
              <div class="doc-row">
                <span class="doc-status" id="k_business_doc_status">No document on file</span>
                <a href="#" id="k_business_doc_link" class="d-none" target="_blank" rel="noopener">View current</a>
              </div>
            </div>
          </div>

          <div class="kyc-section-label">Bank Details</div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label-c">Account Holder Name</label>
              <input type="text" class="form-control-c" name="bank_account_name" id="k_bank_account_name">
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Bank Name</label>
              <input type="text" class="form-control-c" name="bank_name" id="k_bank_name">
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Account Number</label>
              <input type="text" class="form-control-c" name="bank_account_number" id="k_bank_account_number">
            </div>
            <div class="col-md-6">
              <label class="form-label-c">IFSC Code</label>
              <input type="text" class="form-control-c" name="bank_ifsc" id="k_bank_ifsc" maxlength="11">
            </div>
          </div>

          <div class="kyc-section-label">Government ID</div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label-c">ID Type</label>
              <select class="form-control-c" name="govt_id_type" id="k_govt_id_type">
                <option value="">—</option>
                <?php foreach ($govtIdTypes as $val => $label): ?>
                  <option value="<?php echo htmlspecialchars($val); ?>"><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label-c">ID Number</label>
              <input type="text" class="form-control-c" name="govt_id_number" id="k_govt_id_number">
            </div>
            <div class="col-12">
              <label class="form-label-c">ID Document (front)</label>
              <input type="file" class="form-control-c" name="govt_id_document" accept="image/*,.pdf">
              <div class="doc-row">
                <span class="doc-status" id="k_govt_doc_status">No document on file</span>
                <a href="#" id="k_govt_doc_link" class="d-none" target="_blank" rel="noopener">View current</a>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label-c">PAN Card Document</label>
              <input type="file" class="form-control-c" name="pan_document" accept="image/*,.pdf">
              <div class="doc-row">
                <span class="doc-status" id="k_pan_doc_status">No document on file</span>
                <a href="#" id="k_pan_doc_link" class="d-none" target="_blank" rel="noopener">View current</a>
              </div>
            </div>
          </div>

          <div class="kyc-section-label">Admin Notes</div>
          <textarea class="form-control-c" name="admin_notes" id="k_admin_notes" rows="3" placeholder="Internal notes — e.g. reason for rejection, follow-ups needed"></textarea>
        </div>

        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn-mini" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-pink-sm" name="save_kyc"><i class="fa-solid fa-floppy-disk"></i> Save KYC Details</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    var kycModalEl = document.getElementById('kycModal');
    var kycModal   = new bootstrap.Modal(kycModalEl);

    function setDoc(fieldPrefix, userId, hasDoc, docKey) {
      var statusEl = document.getElementById(fieldPrefix + '_status');
      var linkEl   = document.getElementById(fieldPrefix + '_link');
      if (hasDoc === '1') {
        statusEl.textContent = 'Document on file';
        linkEl.href = '/partner/kyc-document.php?user_id=' + userId + '&field=' + docKey;
        linkEl.classList.remove('d-none');
      } else {
        statusEl.textContent = 'No document on file';
        linkEl.classList.add('d-none');
      }
    }

    document.querySelectorAll('.js-review-kyc').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var d = btn.dataset;
        document.getElementById('kycModalTitle').textContent = 'Review KYC — ' + d.name;
        document.getElementById('kycModalSub').textContent = d.email;

        document.getElementById('k_user_id').value = d.id;
        document.getElementById('k_business_name').value = d.businessName || '';
        document.getElementById('k_business_type').value = d.businessType || '';
        document.getElementById('k_gstin').value = d.gstin || '';
        document.getElementById('k_pan_number').value = d.panNumber || '';
        document.getElementById('k_bank_account_name').value = d.bankAccountName || '';
        document.getElementById('k_bank_name').value = d.bankName || '';
        document.getElementById('k_bank_account_number').value = d.bankAccountNumber || '';
        document.getElementById('k_bank_ifsc').value = d.bankIfsc || '';
        document.getElementById('k_govt_id_type').value = d.govtIdType || '';
        document.getElementById('k_govt_id_number').value = d.govtIdNumber || '';
        document.getElementById('k_admin_notes').value = d.adminNotes || '';

        setDoc('k_business_doc', d.id, d.hasBusinessDoc, 'business_proof_document');
        setDoc('k_govt_doc', d.id, d.hasGovtDoc, 'govt_id_document');
        setDoc('k_pan_doc', d.id, d.hasPanDoc, 'pan_document');

        kycModal.show();
      });
    });

    document.querySelectorAll('.js-toggle-kyc').forEach(function (link) {
      link.addEventListener('click', function (e) {
        var goingToApprove = link.dataset.approved === '0';
        var msg = goingToApprove ? 'Approve this partner\'s KYC?' : 'Revoke KYC approval for this partner?';
        if (!confirm(msg)) e.preventDefault();
      });
    });

    <?php if (isset($_GET['success'])): ?>
    showToast('Success', <?php echo json_encode($_GET['success']); ?>);
    <?php elseif (isset($_GET['error'])): ?>
    showToast('Something went wrong', <?php echo json_encode($_GET['error']); ?>);
    <?php endif; ?>
  });
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>