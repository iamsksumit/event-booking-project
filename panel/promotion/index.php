<?php
/**
 * promotion/index.php
 * Admin: "Our Plans" — create/edit/delete boost packages, grant one free to a partner.
 * Partner: "Buy Promotion Plan" — browse packages, buy via promotion/checkout.php.
 */
require_once __DIR__ . '/../include/session_guard.php';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/promo_helpers.php';
require_once __DIR__ . '/../include/notify.php';

$isAdmin    = $role === 'admin';
$pageTitle  = $isAdmin ? 'Our Plans' : 'Buy Promotion Plan';
$activePage = 'promotion';

if (!function_exists('q_one')) {
    function q_one($conn, $sql) {
        $r = $conn->query($sql);
        if (!$r) return 0;
        $row = $r->fetch_assoc();
        return $row ? array_values($row)[0] : 0;
    }
}

/* ---- admin: save / update package ---- */
if ($isAdmin && (isset($_POST['save']) || isset($_POST['update']))) {
    $package_name        = trim($_POST['package_name'] ?? '');
    $package_price       = trim($_POST['package_price'] ?? '');
    $package_description = trim($_POST['package_description'] ?? '');
    $availability         = $_POST['availability'] ?? 'all';
    $specific_partner_id  = (int) ($_POST['specific_partner_id'] ?? 0);
    $duration_type        = ($_POST['duration_type'] ?? 'fixed') === 'until_event_end' ? 'until_event_end' : 'fixed';
    $duration_days_raw    = trim($_POST['duration_days'] ?? '');
    $allow_multiple_dates = isset($_POST['allow_multiple_dates']) && $_POST['allow_multiple_dates'] === '1' ? 1 : 0;
    $status               = isset($_POST['status']) && $_POST['status'] === '1' ? 1 : 0;

    $errors = [];
    if ($package_name === '') $errors[] = 'Package name is required.';
    if ($package_price === '' || !is_numeric($package_price)) $errors[] = 'A valid price is required.';
    if ($availability === 'specific' && $specific_partner_id <= 0) $errors[] = 'Please choose a partner for a personalised package.';
    if ($duration_type === 'fixed' && (!ctype_digit($duration_days_raw) || (int) $duration_days_raw <= 0)) {
        $errors[] = 'Please provide a valid number of duration days.';
    }

    if (!empty($errors)) {
        header('Location: /promotion?error=' . urlencode(implode(' ', $errors)));
        exit();
    }

    $user_id_value = $availability === 'specific' ? $specific_partner_id : 0;
    $duration_days = $duration_type === 'fixed' ? (int) $duration_days_raw : null;
    $admin_user_id = (int) $userId;

    if (isset($_POST['save'])) {
        $stmt = $conn->prepare(
            "INSERT INTO boost_packages
                (package_name, package_description, package_price, user_id, admin_user_id, duration_type, duration_days, allow_multiple_dates, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->bind_param('sssiisiii', $package_name, $package_description, $package_price, $user_id_value, $admin_user_id, $duration_type, $duration_days, $allow_multiple_dates, $status);
        $ok  = $stmt->execute();
        $msg = 'Package added successfully!';

        if ($ok && $user_id_value > 0) {
            notify_user($conn, $user_id_value, 'promotion', 'New Personalised Promotion',
                'A promotion package has been created specifically for you: "' . $package_name . '" (₹' . $package_price . ').', '/promotion');
        }
    } else {
        $package_id = (int) ($_POST['package_id'] ?? 0);
        $stmt = $conn->prepare(
            "UPDATE boost_packages
                SET package_name=?, package_description=?, package_price=?, user_id=?,
                    duration_type=?, duration_days=?, allow_multiple_dates=?, status=?, updated_at=NOW()
              WHERE id=?"
        );
        $stmt->bind_param('sssisiiii', $package_name, $package_description, $package_price, $user_id_value, $duration_type, $duration_days, $allow_multiple_dates, $status, $package_id);
        $ok  = $stmt->execute();
        $msg = 'Package updated successfully!';
    }

    header('Location: /promotion?' . ($ok ? 'success=' . urlencode($msg) : 'error=' . urlencode($conn->error)));
    exit();
}

if ($isAdmin && isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $stmt = $conn->prepare("DELETE FROM boost_packages WHERE id = ?");
    $del_id = (int) $_GET['id'];
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    header('Location: /promotion?success=' . urlencode('Package deleted successfully!'));
    exit();
}

/* ---- data fetch ---- */
if ($isAdmin) {
    $perPage = 10;
    $page    = max(1, (int) ($_GET['page'] ?? 1));

    $totalPackages = (int) q_one($conn, "SELECT COUNT(*) FROM boost_packages");
    $totalPages    = max(1, (int) ceil($totalPackages / $perPage));
    if ($page > $totalPages) { $page = $totalPages; }
    $offset = ($page - 1) * $perPage;

    $stmt = $conn->prepare("
        SELECT bp.*, u.fname AS partner_fname, u.lname AS partner_lname,
               au.fname AS admin_fname, au.lname AS admin_lname
        FROM boost_packages bp
        LEFT JOIN users u ON bp.user_id = u.id
        LEFT JOIN users au ON bp.admin_user_id = au.id
        ORDER BY bp.id DESC LIMIT ? OFFSET ?
    ");
    $stmt->bind_param('ii', $perPage, $offset);
    $stmt->execute();
    $packages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $stmt = $conn->prepare("SELECT * FROM boost_packages WHERE status = 1 AND (user_id = 0 OR user_id = ?) ORDER BY (user_id = 0) ASC, id DESC");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $packages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $myEvents = promo_get_partner_events($conn, $userId);
}

include __DIR__ . '/../include/header.php';
?>

<div class="page-head">
  <div>
    <h2><?php echo $isAdmin ? 'Our Plans' : 'Buy Promotion Plan'; ?></h2>
    <p>
      <?php if ($isAdmin): ?>
        <?php echo number_format($totalPackages); ?> promotion package<?php echo $totalPackages === 1 ? '' : 's'; ?> configured
      <?php else: ?>
        Boost your events across HighSpinn — general plans and any special campaigns created for you
      <?php endif; ?>
    </p>
  </div>
  <?php if ($isAdmin): ?>
    <button type="button" class="btn-pink-sm" id="openAddPkgBtn"><i class="fa-solid fa-plus"></i> Add Package</button>
  <?php else: ?>
    <a href="/promotion/mine" class="btn-mini"><i class="fa-solid fa-clock-rotate-left"></i> My Promotions</a>
  <?php endif; ?>
</div>

<?php if ($isAdmin): ?>

  <div class="panel-card">
    <div class="head">
      <div>
        <h5>All Packages</h5>
        <div class="sub">Shown to partners when they buy an event promotion</div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table-clean">
        <thead>
          <tr>
            <th>Package</th><th>Type</th><th>Partner</th><th>Price</th><th>Duration</th>
            <th>Multiple Dates</th><th>Status</th><th>Created By</th><th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($packages)): foreach ($packages as $package):
            $isGeneral   = (int) $package['user_id'] === 0;
            $partnerName = trim(($package['partner_fname'] ?? '') . ' ' . ($package['partner_lname'] ?? ''));
            $adminName   = trim(($package['admin_fname'] ?? '') . ' ' . ($package['admin_lname'] ?? ''));
            $durationLabel = $package['duration_type'] === 'until_event_end'
                ? 'Until Event Ends'
                : ((int) $package['duration_days'] . ' Day' . ((int) $package['duration_days'] === 1 ? '' : 's'));
          ?>
            <tr>
              <td class="cell-title"><?php echo htmlspecialchars($package['package_name']); ?></td>
              <td><span class="badge-pill <?php echo $isGeneral ? 'badge-live' : 'badge-pending'; ?>"><?php echo $isGeneral ? 'General' : 'Personalised'; ?></span></td>
              <td class="cell-sub"><?php echo $isGeneral ? 'All Partners' : htmlspecialchars($partnerName ?: ('#' . $package['user_id'])); ?></td>
              <td><?php echo $package['package_price'] !== null && $package['package_price'] !== '' ? '₹' . htmlspecialchars($package['package_price']) : '—'; ?></td>
              <td class="cell-sub"><?php echo htmlspecialchars($durationLabel); ?></td>
              <td class="cell-sub"><?php echo ((int) $package['allow_multiple_dates'] === 1) ? 'All Dates' : 'Single Date'; ?></td>
              <td><span class="badge-pill <?php echo ((int) $package['status'] === 1) ? 'badge-live' : 'badge-past'; ?>"><?php echo ((int) $package['status'] === 1) ? 'Active' : 'Inactive'; ?></span></td>
              <td class="cell-sub"><?php echo $adminName !== '' ? htmlspecialchars($adminName) : ($package['admin_user_id'] ? ('#' . (int) $package['admin_user_id']) : '—'); ?></td>
              <td style="text-align:right;">
                <div class="d-flex justify-content-end gap-1">
                  <button type="button" class="btn-mini js-grant-pkg"
                          data-id="<?php echo (int) $package['id']; ?>"
                          data-name="<?php echo htmlspecialchars($package['package_name'], ENT_QUOTES); ?>"
                          data-price="<?php echo htmlspecialchars($package['package_price'], ENT_QUOTES); ?>"
                          title="Grant to a partner for free">
                    <i class="fa-solid fa-gift"></i>
                  </button>
                  <button type="button" class="btn-mini js-edit-pkg"
                          data-id="<?php echo (int) $package['id']; ?>"
                          data-name="<?php echo htmlspecialchars($package['package_name'], ENT_QUOTES); ?>"
                          data-price="<?php echo htmlspecialchars($package['package_price'], ENT_QUOTES); ?>"
                          data-description="<?php echo htmlspecialchars($package['package_description'], ENT_QUOTES); ?>"
                          data-availability="<?php echo $isGeneral ? 'all' : 'specific'; ?>"
                          data-partner-id="<?php echo (int) $package['user_id']; ?>"
                          data-partner-label="<?php echo htmlspecialchars($partnerName ?: '', ENT_QUOTES); ?>"
                          data-duration-type="<?php echo htmlspecialchars($package['duration_type']); ?>"
                          data-duration-days="<?php echo htmlspecialchars((string) $package['duration_days']); ?>"
                          data-allow-multiple="<?php echo (int) $package['allow_multiple_dates']; ?>"
                          data-status="<?php echo (int) $package['status']; ?>">
                    <i class="fa-solid fa-pencil"></i>
                  </button>
                  <a href="?action=delete&id=<?php echo (int) $package['id']; ?>"
                     class="btn-mini js-confirm"
                     data-confirm-title="Delete this package?"
                     data-confirm-body="This cannot be undone. Existing partner promotions keep their own record and are unaffected."
                     style="color:var(--pink);border-color:var(--pink);">
                    <i class="fa-solid fa-trash"></i>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="9" class="cell-sub text-center">No packages yet — add your first one.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php promo_render_pagination($page, $totalPages, $offset, $perPage, $totalPackages); ?>

    <div class="cell-sub" style="margin-top:1rem;">
      <a href="/promotion/orders.php"><i class="fa-solid fa-receipt"></i> View promotion purchase &amp; order history</a>
    </div>
  </div>

<?php else: ?>

  <?php if (empty($packages)): ?>
    <div class="panel-card">
      <div class="cell-sub text-center" style="padding:2rem 0;">No promotion packages are available right now. Check back soon.</div>
    </div>
  <?php else: ?>
    <?php if (empty($myEvents)): ?>
      <div class="panel-card" style="margin-bottom:1rem;">
        <div class="cell-sub">You don't have any live events yet. <a href="/events/">Create an event</a> first, then come back to buy a promotion.</div>
      </div>
    <?php endif; ?>

    <div class="plans-grid">
      <?php foreach ($packages as $pkg):
        $isPersonalised = (int) $pkg['user_id'] !== 0;
        $durationLabel = $pkg['duration_type'] === 'until_event_end' ? 'Until Event Ends' : ((int) $pkg['duration_days'] . ' Day' . ((int) $pkg['duration_days'] === 1 ? '' : 's'));
      ?>
        <div class="plan-card <?php echo $isPersonalised ? 'exclusive' : ''; ?>">
          <?php if ($isPersonalised): ?>
            <div class="plan-badge"><i class="fa-solid fa-star"></i> Exclusive For You</div>
          <?php endif; ?>
          <div class="plan-body">
            <h5><?php echo htmlspecialchars($pkg['package_name']); ?></h5>
            <div class="plan-desc"><?php echo strip_tags($pkg['package_description'] ?? ''); ?></div>
            <div class="plan-price">₹<?php echo htmlspecialchars($pkg['package_price']); ?></div>
            <div class="d-flex flex-wrap gap-1 plan-tags">
              <span class="badge-pill"><i class="fa-regular fa-clock"></i> <?php echo htmlspecialchars($durationLabel); ?></span>
              <span class="badge-pill"><i class="fa-regular fa-calendar"></i> <?php echo ((int) $pkg['allow_multiple_dates'] === 1) ? 'All Event Dates' : 'Single Event Date'; ?></span>
            </div>
          </div>
          <div class="plan-footer">
            <?php if (empty($myEvents)): ?>
              <button type="button" class="btn-pink-sm plan-cta" disabled title="Create an event first">Purchase</button>
            <?php else: ?>
              <a href="/promotion/checkout.php?package_id=<?php echo (int) $pkg['id']; ?>" class="btn-pink-sm plan-cta">Purchase</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php endif; ?>

<style>
  .plans-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:1rem; }
  .plan-card{ display:flex; flex-direction:column; background:var(--panel); border:1px solid var(--line); border-radius:14px; overflow:hidden; transition:border-color .15s,transform .15s; }
  .plan-card:hover{ transform:translateY(-2px); border-color:var(--pink); }
  .plan-card.exclusive{ border-color:var(--pink); box-shadow:0 0 0 1px rgba(255,61,104,.25) inset; }
  .plan-badge{ background:var(--pink); color:#fff; font-size:.7rem; font-weight:800; letter-spacing:.03em; padding:.4rem .9rem; }
  .plan-body{ padding:1.2rem; flex:1; display:flex; flex-direction:column; }
  .plan-body h5{ margin-bottom:.5rem; }
  .plan-desc{ font-size:.82rem; color:var(--muted); flex:1; margin-bottom:1rem; min-height:2.4em; }
  .plan-price{ font-size:1.6rem; font-weight:800; color:var(--text); margin-bottom:.6rem; }
  .plan-tags{ margin-top:auto; }
  .plan-footer{ padding:0 1.2rem 1.2rem; }
  .plan-cta{ width:100%; text-align:center; display:block; }

  .radio-pill-group{ display:flex; gap:.6rem; flex-wrap:wrap; }
  .radio-pill{ border:1px solid var(--line); background:var(--panel-2); border-radius:10px; padding:.55rem .9rem; font-size:.8rem; font-weight:600; color:var(--muted); cursor:pointer; display:flex; align-items:center; gap:.4rem; }
  .radio-pill input{ accent-color:var(--pink); }
  .radio-pill.checked{ border-color:var(--pink); color:var(--text); background:rgba(255,61,104,.08); }
  .conditional-field{ display:none; }
  .conditional-field.show{ display:block; }

  .ck.ck-editor{ --ck-color-base-background:var(--panel-2); --ck-color-base-border:var(--line); }
  .ck.ck-toolbar{ background:var(--panel-2) !important; border-color:var(--line) !important; }
  .ck.ck-editor__main > .ck-editor__editable{ background:var(--panel-2) !important; color:var(--text) !important; border-color:var(--line) !important; min-height:140px; }
  .ck.ck-button, .ck .ck-button{ color:var(--muted) !important; }
  .ck.ck-button:hover, .ck .ck-button:hover{ background:var(--panel-3) !important; color:var(--text) !important; }
  .ck.ck-button.ck-on{ background:rgba(255,61,104,.16) !important; color:var(--pink) !important; }
  .ck.ck-toolbar__separator{ background:var(--line) !important; }
  .ck.ck-dropdown__panel, .ck.ck-list{ background:var(--panel-2) !important; border-color:var(--line) !important; }
  .ck.ck-list__item .ck-button{ color:var(--text) !important; }

  .duration-preset{ min-width:44px; }
  .duration-preset.active{ border-color:var(--pink); color:var(--text); background:rgba(255,61,104,.08); }

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

<?php if ($isAdmin): ?>

<!-- ================= ADD / EDIT PACKAGE MODAL ================= -->
<div class="modal fade" id="pkgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form role="form" method="POST" action="" id="pkgForm">
        <div class="modal-header">
          <div>
            <h5 id="pkgModalTitle">Add New Package</h5>
            <div class="cell-sub">Shown to partners when they buy a promotion</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="package_id" id="f_package_id">

          <div class="row g-3">
            <div class="col-md-7">
              <label class="form-label-c">Package Name</label>
              <input type="text" class="form-control-c" name="package_name" id="f_package_name" placeholder="e.g. Featured Spotlight — 7 Days" required>
            </div>
            <div class="col-md-5">
              <label class="form-label-c">Price (₹)</label>
              <input type="text" class="form-control-c" name="package_price" id="f_package_price" placeholder="e.g. 999" inputmode="decimal">
            </div>

            <div class="col-12">
              <label class="form-label-c">Package Description</label>
              <textarea class="form-control-c" name="package_description" id="f_package_description" rows="5" placeholder="What partners get with this package"></textarea>
            </div>

            <div class="col-md-6">
              <label class="form-label-c">Package Availability</label>
              <div class="radio-pill-group" id="availabilityGroup">
                <label class="radio-pill"><input type="radio" name="availability" value="all" checked> All Partners</label>
                <label class="radio-pill"><input type="radio" name="availability" value="specific"> Specific Partner</label>
              </div>
            </div>
            <div class="col-md-6 conditional-field" id="specificPartnerField">
              <label class="form-label-c">Partner</label>
              <select class="form-control-c" id="f_specific_partner_id" name="specific_partner_id" style="width:100%;"></select>
              <div class="cell-sub" style="margin-top:.3rem;">Start typing a partner's name or email to search.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label-c">Promotion Duration</label>
              <div class="radio-pill-group" id="durationGroup">
                <label class="radio-pill"><input type="radio" name="duration_type" value="fixed" checked> Fixed Duration</label>
                <label class="radio-pill"><input type="radio" name="duration_type" value="until_event_end"> Until Event Ends</label>
              </div>
            </div>
            <div class="col-md-6 conditional-field show" id="durationDaysField">
              <label class="form-label-c">Duration Days</label>
              <div class="d-flex flex-wrap gap-1" style="margin-bottom:.5rem;">
                <button type="button" class="btn-mini duration-preset" data-days="1">1</button>
                <button type="button" class="btn-mini duration-preset" data-days="3">3</button>
                <button type="button" class="btn-mini duration-preset active" data-days="7">7</button>
                <button type="button" class="btn-mini duration-preset" data-days="15">15</button>
                <button type="button" class="btn-mini duration-preset" data-days="30">30</button>
              </div>
              <input type="number" min="1" step="1" class="form-control-c" name="duration_days" id="f_duration_days" placeholder="Custom number of days" value="7">
            </div>

            <div class="col-md-6">
              <label class="form-label-c">Allow Multiple Event Dates</label>
              <div class="radio-pill-group" id="multiDatesGroup">
                <label class="radio-pill"><input type="radio" name="allow_multiple_dates" value="0" checked> No</label>
                <label class="radio-pill"><input type="radio" name="allow_multiple_dates" value="1"> Yes</label>
              </div>
              <div class="cell-sub" style="margin-top:.4rem;">When "Yes", a multi-date event is promoted on all its dates automatically.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Status</label>
              <div class="radio-pill-group" id="statusGroup">
                <label class="radio-pill"><input type="radio" name="status" value="1" checked> Active</label>
                <label class="radio-pill"><input type="radio" name="status" value="0"> Inactive</label>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn-mini" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-pink-sm" name="save" id="pkgSubmitBtn">Add New Package</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================= GRANT PACKAGE MODAL (free, admin-assigned) ================= -->
<div class="modal fade" id="grantModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5>Grant Promotion</h5>
          <div class="cell-sub" id="grantPkgLabel">—</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <label class="form-label-c">Partner</label>
        <select class="form-control-c" id="grantPartnerSelect" style="width:100%;"></select>

        <div id="grantEventWrap" class="mt-3" style="display:none;">
          <label class="form-label-c">Event</label>
          <select class="form-control-c" id="grantEventSelect" style="width:100%;"></select>
        </div>

        <div id="grantDateWrap" class="mt-3" style="display:none;">
          <label class="form-label-c">Event Date</label>
          <div id="grantSingleDate" class="doc-row" style="display:none;"></div>
          <div id="grantAllDatesInfo" class="cell-sub" style="display:none;">All event dates will be promoted automatically.</div>
          <div id="grantDateChoices" style="display:none;"></div>
        </div>

        <div class="mt-3">
          <label class="form-label-c">Price to Record (₹)</label>
          <input type="number" min="0" step="0.01" class="form-control-c" id="grantPrice">
          <div class="cell-sub" style="margin-top:.3rem;">The partner is not charged — this is only for your records. Defaults to the package price.</div>
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <button type="button" class="btn-mini" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn-pink-sm" id="grantSubmitBtn" disabled>Grant Promotion</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.0/classic/ckeditor.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var pkgModalEl = document.getElementById('pkgModal');
    var pkgModal = new bootstrap.Modal(pkgModalEl);
    var pkgForm = document.getElementById('pkgForm');
    var submitBtn = document.getElementById('pkgSubmitBtn');
    var descField = document.getElementById('f_package_description');
    var durationDaysInput = document.getElementById('f_duration_days');
    var descEditor = null;
    var partnerSelectInited = false;

    function initDescEditor() {
      if (descEditor || typeof ClassicEditor === 'undefined') return;
      ClassicEditor.create(descField).then(function (e) { descEditor = e; }).catch(function (err) { console.error(err); });
    }
    function destroyDescEditor() {
      if (!descEditor) return;
      var e = descEditor; descEditor = null;
      e.destroy().catch(function () {});
    }

    function initPartnerSelect() {
      if (partnerSelectInited) return;
      partnerSelectInited = true;
      $('#f_specific_partner_id').select2({
        dropdownParent: $('#pkgModal'),
        placeholder: 'Search partner by name or email…',
        ajax: {
          url: '/ajax/promotion/actions',
          dataType: 'json',
          delay: 250,
          data: function (params) { return { action: 'search-partners', q: params.term || '' }; },
          processResults: function (data) { return { results: data.results || [] }; }
        },
        minimumInputLength: 1
      });
    }

    function setPill(groupId, value) {
      document.querySelectorAll('#' + groupId + ' input').forEach(function (r) {
        r.checked = (r.value === String(value));
        r.closest('.radio-pill').classList.toggle('checked', r.checked);
      });
    }
    document.querySelectorAll('.radio-pill-group').forEach(function (group) {
      group.querySelectorAll('input').forEach(function (input) {
        input.addEventListener('change', function () {
          group.querySelectorAll('.radio-pill').forEach(function (p) { p.classList.remove('checked'); });
          input.closest('.radio-pill').classList.add('checked');
          syncConditionalFields();
        });
      });
    });

    document.querySelectorAll('.duration-preset').forEach(function (btn) {
      btn.addEventListener('click', function () {
        durationDaysInput.value = btn.dataset.days;
        document.querySelectorAll('.duration-preset').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
      });
    });
    durationDaysInput.addEventListener('input', function () {
      document.querySelectorAll('.duration-preset').forEach(function (b) {
        b.classList.toggle('active', b.dataset.days === durationDaysInput.value);
      });
    });

    function syncConditionalFields() {
      var availability = document.querySelector('#availabilityGroup input:checked').value;
      document.getElementById('specificPartnerField').classList.toggle('show', availability === 'specific');
      var durationType = document.querySelector('#durationGroup input:checked').value;
      document.getElementById('durationDaysField').classList.toggle('show', durationType === 'fixed');
    }

    function resetPkgForm() {
      pkgForm.reset();
      document.getElementById('f_package_id').value = '';
      document.getElementById('pkgModalTitle').textContent = 'Add New Package';
      submitBtn.name = 'save';
      submitBtn.textContent = 'Add New Package';
      if (descEditor) { descEditor.setData(''); }
      if (partnerSelectInited) { $('#f_specific_partner_id').empty().val(null).trigger('change'); }
      durationDaysInput.value = '7';
      document.querySelectorAll('.duration-preset').forEach(function (b) { b.classList.toggle('active', b.dataset.days === '7'); });
      setPill('availabilityGroup', 'all');
      setPill('durationGroup', 'fixed');
      setPill('multiDatesGroup', '0');
      setPill('statusGroup', '1');
      syncConditionalFields();
    }

    document.getElementById('openAddPkgBtn').addEventListener('click', function () {
      resetPkgForm();
      pkgModal.show();
    });

    document.querySelectorAll('.js-edit-pkg').forEach(function (btn) {
      btn.addEventListener('click', function () {
        resetPkgForm();
        document.getElementById('pkgModalTitle').textContent = 'Edit Package';
        submitBtn.name = 'update';
        submitBtn.textContent = 'Update Package';

        document.getElementById('f_package_id').value = btn.dataset.id;
        document.getElementById('f_package_name').value = btn.dataset.name;
        document.getElementById('f_package_price').value = btn.dataset.price;
        descField.value = btn.dataset.description;
        if (descEditor) { descEditor.setData(btn.dataset.description || ''); }

        setPill('availabilityGroup', btn.dataset.availability);
        setPill('durationGroup', btn.dataset.durationType);
        setPill('multiDatesGroup', btn.dataset.allowMultiple);
        setPill('statusGroup', btn.dataset.status);

        if (btn.dataset.durationType === 'fixed') {
          durationDaysInput.value = btn.dataset.durationDays;
          document.querySelectorAll('.duration-preset').forEach(function (b) { b.classList.toggle('active', b.dataset.days === btn.dataset.durationDays); });
        }

        if (btn.dataset.availability === 'specific' && btn.dataset.partnerId !== '0') {
          initPartnerSelect();
          var opt = new Option(btn.dataset.partnerLabel || ('Partner #' + btn.dataset.partnerId), btn.dataset.partnerId, true, true);
          $('#f_specific_partner_id').append(opt).trigger('change');
        }

        syncConditionalFields();
        pkgModal.show();
      });
    });

    pkgModalEl.addEventListener('shown.bs.modal', function () { initDescEditor(); initPartnerSelect(); });
    pkgModalEl.addEventListener('hidden.bs.modal', destroyDescEditor);
    pkgForm.addEventListener('submit', function () { if (descEditor) descField.value = descEditor.getData(); });

    if (window.location.search.indexOf('action=add') !== -1) { resetPkgForm(); pkgModal.show(); }

    /* ---- Grant Promotion modal ---- */
    var grantModal = new bootstrap.Modal(document.getElementById('grantModal'));
    var grantPartnerSelect = $('#grantPartnerSelect');
    var grantEventSelect = $('#grantEventSelect');
    var grantSubmitBtn = document.getElementById('grantSubmitBtn');
    var grantPartnerInited = false, grantEventInited = false;
    var grantPkg = { id: null, name: '', price: '' };
    var grantState = { partnerId: null, eventId: null, needsSelection: false, appliesToAll: false, selectedDate: null };

    function initGrantPartnerSelect() {
      if (grantPartnerInited) return;
      grantPartnerInited = true;
      grantPartnerSelect.select2({
        dropdownParent: $('#grantModal'),
        placeholder: 'Search partner by name or email…',
        ajax: {
          url: '/ajax/promotion/actions',
          dataType: 'json', delay: 250,
          data: function (params) { return { action: 'search-partners', q: params.term || '' }; },
          processResults: function (data) { return { results: data.results || [] }; }
        },
        minimumInputLength: 1
      });
    }
    function initGrantEventSelect() {
      if (grantEventInited) return;
      grantEventInited = true;
      grantEventSelect.select2({ dropdownParent: $('#grantModal'), placeholder: 'Select an event…', width: '100%' });
    }

    function resetGrantDateUI() {
      document.getElementById('grantDateWrap').style.display = 'none';
      document.getElementById('grantSingleDate').style.display = 'none';
      document.getElementById('grantAllDatesInfo').style.display = 'none';
      document.getElementById('grantDateChoices').style.display = 'none';
      document.getElementById('grantDateChoices').innerHTML = '';
      grantState.needsSelection = false; grantState.appliesToAll = false; grantState.selectedDate = null;
    }
    function updateGrantSubmitState() {
      grantSubmitBtn.disabled = !(grantState.partnerId && grantState.eventId && (!grantState.needsSelection || grantState.selectedDate));
    }

    document.querySelectorAll('.js-grant-pkg').forEach(function (btn) {
      btn.addEventListener('click', function () {
        grantPkg = { id: btn.dataset.id, name: btn.dataset.name, price: btn.dataset.price };
        document.getElementById('grantPkgLabel').textContent = grantPkg.name + ' — ₹' + grantPkg.price;
        document.getElementById('grantPrice').value = grantPkg.price;
        document.getElementById('grantEventWrap').style.display = 'none';
        resetGrantDateUI();
        grantState = { partnerId: null, eventId: null, needsSelection: false, appliesToAll: false, selectedDate: null };
        grantEventSelect.empty();
        grantPartnerSelect.val(null);
        updateGrantSubmitState();
        grantModal.show();
      });
    });

    document.getElementById('grantModal').addEventListener('shown.bs.modal', function () {
      initGrantPartnerSelect(); initGrantEventSelect();
    });

    grantPartnerSelect.on('change', function () {
      var partnerId = this.value;
      grantState.partnerId = partnerId || null;
      grantState.eventId = null;
      resetGrantDateUI();
      grantEventSelect.empty().trigger('change');
      document.getElementById('grantEventWrap').style.display = partnerId ? 'block' : 'none';
      updateGrantSubmitState();
      if (!partnerId) return;

      fetch('/ajax/promotion/actions?action=list-events&partner_id=' + encodeURIComponent(partnerId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          (data.events || []).forEach(function (ev) {
            grantEventSelect.append(new Option(ev.title, ev.id, false, false));
          });
          grantEventSelect.trigger('change');
          if (!data.events || data.events.length === 0) showToast('No events', 'This partner has no live events.');
        })
        .catch(function () { showToast('Error', 'Could not load this partner\'s events.'); });
    });

    grantEventSelect.on('change', function () {
      var eventId = this.value;
      grantState.eventId = eventId || null;
      resetGrantDateUI();
      updateGrantSubmitState();
      if (!eventId || !grantState.partnerId) return;

      var url = '/ajax/promotion/actions?action=get-event-dates&event_id=' + encodeURIComponent(eventId)
        + '&package_id=' + encodeURIComponent(grantPkg.id) + '&partner_id=' + encodeURIComponent(grantState.partnerId);

      fetch(url).then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || 'Could not load dates.'); return d; }); })
        .then(function (data) {
          document.getElementById('grantDateWrap').style.display = 'block';
          grantState.needsSelection = !!data.needs_selection;
          grantState.appliesToAll = !!data.applies_to_all;

          if (!data.is_multi) {
            var single = data.dates[0];
            document.getElementById('grantSingleDate').style.display = 'flex';
            document.getElementById('grantSingleDate').innerHTML = '<span>Event Date</span><b>' + single.label + '</b>';
            grantState.selectedDate = single.value;
          } else if (data.applies_to_all) {
            document.getElementById('grantAllDatesInfo').style.display = 'block';
            grantState.selectedDate = null;
          } else {
            var wrap = document.getElementById('grantDateChoices');
            wrap.style.display = 'block';
            data.dates.forEach(function (d) {
              var label = document.createElement('label');
              label.className = 'radio-pill'; label.style.cssText = 'display:flex;width:100%;margin-bottom:.5rem;';
              var radio = document.createElement('input');
              radio.type = 'radio'; radio.name = 'grantPromoDate'; radio.value = d.value; radio.style.marginRight = '.5rem';
              label.appendChild(radio); label.appendChild(document.createTextNode(d.label));
              wrap.appendChild(label);
              radio.addEventListener('change', function () { grantState.selectedDate = this.value; updateGrantSubmitState(); });
            });
          }
          updateGrantSubmitState();
        })
        .catch(function (error) { showToast('Cannot use this event', error.message); });
    });

    grantSubmitBtn.addEventListener('click', function () {
      if (grantSubmitBtn.disabled) return;
      grantSubmitBtn.disabled = true;
      grantSubmitBtn.textContent = 'Granting…';

      fetch('/ajax/promotion/actions?action=grant-promotion', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          partner_id: Number(grantState.partnerId),
          package_id: Number(grantPkg.id),
          event_id: Number(grantState.eventId),
          event_date: grantState.selectedDate || null,
          price: document.getElementById('grantPrice').value || null
        })
      })
        .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.error || 'Could not grant promotion.'); return d; }); })
        .then(function () {
          grantModal.hide();
          showToast('Success', 'Promotion granted to the partner.');
          setTimeout(function () { window.location.reload(); }, 900);
        })
        .catch(function (error) {
          showToast('Could not grant', error.message);
          grantSubmitBtn.disabled = false;
          grantSubmitBtn.textContent = 'Grant Promotion';
        });
    });

    <?php if (isset($_GET['success'])): ?>
    showToast('Success', <?php echo json_encode($_GET['success']); ?>);
    <?php elseif (isset($_GET['error'])): ?>
    showToast('Something went wrong', <?php echo json_encode($_GET['error']); ?>);
    <?php endif; ?>
  });
</script>

<?php endif; ?>

<?php include __DIR__ . '/../include/confirm-modal.php'; ?>
<?php include __DIR__ . '/../include/footer.php'; ?>