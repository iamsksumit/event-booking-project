<?php
/**
 * promotion/index.php ("Our Plans" — boost_packages)
 * Promotion package management — list, add, edit (via modal), delete.
 * Matches the shared dark dashboard theme (include/style.php) and uses
 * include/header.php + include/sidebar.php + include/footer.php like
 * every other admin/partner screen.
 *
 * Admin-only: this manages the global list of packages partners can buy
 * (see sidebar → Event Promotion), not an individual partner's purchases.
 *
 * Requires the `boost_packages` table to have a `user_id` column — see
 * migration_boost_packages_user_id.sql if you haven't run it yet.
 */
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';          // sets up $conn (mysqli)

if ($role !== 'admin') {
    header('Location: /profile');
    exit();
}

$pageTitle  = 'Our Plans';
$activePage = 'promotion';

/* ===================== helpers ===================== */
if (!function_exists('q_one')) {
    function q_one($conn, $sql) {
        $r = $conn->query($sql);
        if (!$r) return 0;
        $row = $r->fetch_assoc();
        return $row ? array_values($row)[0] : 0;
    }
}

/* ===================== handle save / update ===================== */
if (isset($_POST['save']) || isset($_POST['update'])) {
    $package_name        = trim($_POST['package_name'] ?? '');
    $package_price       = trim($_POST['package_price'] ?? '');
    $package_description = trim($_POST['package_description'] ?? '');

    if ($package_name === '') {
        header('Location: /promotion?error=' . urlencode('Package name is required.'));
        exit();
    }

    if (isset($_POST['save'])) {
        $stmt = $conn->prepare(
            "INSERT INTO boost_packages (package_name, package_description, package_price, user_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->bind_param('sssi', $package_name, $package_description, $package_price, $userId);
        $ok  = $stmt->execute();
        $msg = 'Package added successfully!';
    } else {
        $package_id = (int) ($_POST['package_id'] ?? 0);
        $stmt = $conn->prepare(
            "UPDATE boost_packages SET package_name=?, package_description=?, package_price=?, updated_at=NOW() WHERE id=?"
        );
        $stmt->bind_param('sssi', $package_name, $package_description, $package_price, $package_id);
        $ok  = $stmt->execute();
        $msg = 'Package updated successfully!';
    }

    header('Location: /promotion?' . ($ok ? 'success=' . urlencode($msg) : 'error=' . urlencode($conn->error)));
    exit();
}

/* ===================== handle delete ===================== */
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $stmt = $conn->prepare("DELETE FROM boost_packages WHERE id = ?");
    $del_id = (int) $_GET['id'];
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    header('Location: /promotion?success=' . urlencode('Package deleted successfully!'));
    exit();
}

/* ===================== fetch list (paginated) ===================== */
$perPage = 10;
$page    = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) { $page = 1; }

$totalPackages = (int) q_one($conn, "SELECT COUNT(*) FROM boost_packages");
$totalPages    = max(1, (int) ceil($totalPackages / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

$stmt = $conn->prepare("
    SELECT bp.*, u.fname AS added_fname, u.lname AS added_lname
    FROM boost_packages bp
    LEFT JOIN users u ON bp.user_id = u.id
    ORDER BY bp.id DESC LIMIT ? OFFSET ?
");
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$packages = $stmt->get_result();

include __DIR__ . '/../include/header.php';
/* header.php has already opened <div class="content"> */
?>

<div class="page-head">
  <div>
    <h2>Our Plans</h2>
    <p><?php echo number_format($totalPackages); ?> promotion package<?php echo $totalPackages === 1 ? '' : 's'; ?> available to partners</p>
  </div>
  <button type="button" class="btn-pink-sm" id="openAddPkgBtn"><i class="fa-solid fa-plus"></i> Add Package</button>
</div>

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
          <th>Package</th>
          <th>Price</th>
          <th>Added By</th>
          <th>Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($packages && $packages->num_rows): while ($package = $packages->fetch_assoc()):
          $addedBy = trim(($package['added_fname'] ?? '') . ' ' . ($package['added_lname'] ?? ''));
        ?>
          <tr>
            <td class="cell-title"><?php echo htmlspecialchars($package['package_name']); ?></td>
            <td><?php echo $package['package_price'] !== null && $package['package_price'] !== '' ? '₹' . htmlspecialchars($package['package_price']) : '—'; ?></td>
            <td class="cell-sub"><?php echo $addedBy !== '' ? htmlspecialchars($addedBy) : '—'; ?></td>
            <td><span class="badge-pill badge-live">Active</span></td>
            <td style="text-align:right;">
              <div class="d-flex justify-content-end gap-1">
                <button type="button"
                        class="btn-mini js-edit-pkg"
                        data-id="<?php echo (int) $package['id']; ?>"
                        data-name="<?php echo htmlspecialchars($package['package_name'], ENT_QUOTES); ?>"
                        data-price="<?php echo htmlspecialchars($package['package_price'], ENT_QUOTES); ?>"
                        data-description="<?php echo htmlspecialchars($package['package_description'], ENT_QUOTES); ?>">
                  <i class="fa-solid fa-pencil"></i>
                </button>
                <a href="?action=delete&id=<?php echo (int) $package['id']; ?>"
                   class="btn-mini js-delete-pkg"
                   style="color:var(--pink);border-color:var(--pink);">
                  <i class="fa-solid fa-trash"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="5" class="cell-sub text-center">No packages yet — add your first one.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pager-c">
    <div class="pager-info">
      Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $totalPackages); ?> of <?php echo $totalPackages; ?>
    </div>
    <div class="pager-links">
      <a class="pager-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo max(1, $page - 1); ?>"><i class="fa-solid fa-chevron-left"></i></a>
      <?php
        $start = max(1, $page - 2);
        $end   = min($totalPages, $start + 4);
        $start = max(1, $end - 4);
        for ($p = $start; $p <= $end; $p++):
      ?>
        <a class="pager-link <?php echo $p === $page ? 'active' : ''; ?>" href="?page=<?php echo $p; ?>"><?php echo $p; ?></a>
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

  /* Dark-theme skin for the CKEditor WYSIWYG box so it matches the rest of the form */
  .ck.ck-editor{ --ck-color-base-background: var(--panel-2); --ck-color-base-border: var(--line); }
  .ck.ck-toolbar{ background:var(--panel-2) !important; border-color:var(--line) !important; }
  .ck.ck-editor__main > .ck-editor__editable{ background:var(--panel-2) !important; color:var(--text) !important; border-color:var(--line) !important; min-height:140px; }
  .ck.ck-button, .ck .ck-button{ color:var(--muted) !important; }
  .ck.ck-button:hover, .ck .ck-button:hover{ background:var(--panel-3) !important; color:var(--text) !important; }
  .ck.ck-button.ck-on{ background:rgba(255,61,104,.16) !important; color:var(--pink) !important; }
  .ck.ck-toolbar__separator{ background:var(--line) !important; }
  .ck.ck-dropdown__panel, .ck.ck-list{ background:var(--panel-2) !important; border-color:var(--line) !important; }
  .ck.ck-list__item .ck-button{ color:var(--text) !important; }
</style>

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
              <textarea class="form-control-c" name="package_description" id="f_package_description" rows="6" placeholder="What partners get with this package"></textarea>
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

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.0/classic/ckeditor.js"></script>
<script>
  // Wrapped in DOMContentLoaded: bootstrap.bundle.min.js is loaded further down
  // in include/footer.php, so `bootstrap` isn't defined yet at the point this
  // <script> tag appears in the page. Waiting for DOMContentLoaded guarantees
  // every earlier <script src> (including Bootstrap's and CKEditor's) has run.
  document.addEventListener('DOMContentLoaded', function () {
    var pkgModalEl = document.getElementById('pkgModal');
    var pkgModal = new bootstrap.Modal(pkgModalEl);
    var pkgForm = document.getElementById('pkgForm');
    var submitBtn = document.getElementById('pkgSubmitBtn');
    var descField = document.getElementById('f_package_description');
    var descEditor = null;

    function initDescEditor() {
      if (descEditor || typeof ClassicEditor === 'undefined') return;
      ClassicEditor.create(descField).then(function (editor) {
        descEditor = editor;
      }).catch(function (err) {
        console.error('CKEditor failed to load, falling back to plain textarea.', err);
      });
    }

    function destroyDescEditor() {
      if (!descEditor) return;
      var editor = descEditor;
      descEditor = null;
      editor.destroy().catch(function () {});
    }

    function resetPkgForm() {
      pkgForm.reset();
      document.getElementById('f_package_id').value = '';
      document.getElementById('pkgModalTitle').textContent = 'Add New Package';
      submitBtn.name = 'save';
      submitBtn.textContent = 'Add New Package';
      if (descEditor) { descEditor.setData(''); }
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

        pkgModal.show();
      });
    });

    document.querySelectorAll('.js-delete-pkg').forEach(function (link) {
      link.addEventListener('click', function (e) {
        if (!confirm('Delete this package? This cannot be undone.')) e.preventDefault();
      });
    });

    // Init/teardown the WYSIWYG editor alongside the modal so it never double-inits
    pkgModalEl.addEventListener('shown.bs.modal', initDescEditor);
    pkgModalEl.addEventListener('hidden.bs.modal', destroyDescEditor);

    // Make sure the rich-text content is synced back into the textarea before POST
    pkgForm.addEventListener('submit', function () {
      if (descEditor) { descField.value = descEditor.getData(); }
    });

    <?php if (isset($_GET['success'])): ?>
    showToast('Success', <?php echo json_encode($_GET['success']); ?>);
    <?php elseif (isset($_GET['error'])): ?>
    showToast('Something went wrong', <?php echo json_encode($_GET['error']); ?>);
    <?php endif; ?>
  });
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>