<?php
/**
 * category/index.php ("Categories")
 * Event category management — list, add, edit (via modal), delete.
 * Matches the shared dark dashboard theme (include/style.php) and uses
 * include/header.php + include/sidebar.php + include/footer.php like
 * every other admin/partner screen.
 *
 * Requires the `category` table to have a `user_id` column — see
 * migration_category_user_id.sql if you haven't run it yet.
 */
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';          // sets up $conn (mysqli)

$pageTitle  = 'Categories';
$activePage = 'category';

/* ===================== helpers ===================== */
if (!function_exists('q_one')) {
    function q_one($conn, $sql) {
        $r = $conn->query($sql);
        if (!$r) return 0;
        $row = $r->fetch_assoc();
        return $row ? array_values($row)[0] : 0;
    }
}

function slugify($text) {
    $text = strtolower(trim((string) $text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    return $text !== '' ? $text : 'category';
}

function unique_category_slug($conn, $base, $excludeId = 0) {
    $slug = $base;
    $i = 2;
    while (true) {
        $stmt = $conn->prepare("SELECT id FROM category WHERE cat_slug = ? AND id != ? LIMIT 1");
        $stmt->bind_param('si', $slug, $excludeId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        if (!$exists) return $slug;
        $slug = $base . '-' . $i;
        $i++;
    }
}

function generate_unique_filename($dir, $filename) {
    $parts = pathinfo($filename);
    $basename  = $parts['filename'];
    $extension = isset($parts['extension']) ? '.' . $parts['extension'] : '';
    $candidate = $basename . $extension;
    $i = 1;
    while (file_exists($dir . $candidate)) {
        $candidate = $basename . '-' . $i . $extension;
        $i++;
    }
    return $candidate;
}

/* ===================== handle save / update ===================== */
if (isset($_POST['save']) || isset($_POST['update'])) {
    $title = trim($_POST['catname'] ?? '');
    $base_url = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];

    if ($title === '') {
        header('Location: /category?error=' . urlencode('Category name is required.'));
        exit();
    }

    $image_url = trim($_POST['old_image_url'] ?? '');

    if (isset($_FILES['image_url']) && $_FILES['image_url']['error'] === UPLOAD_ERR_OK && $_FILES['image_url']['name'] !== '') {
        $target_dir = __DIR__ . '/../images/cat/';
        if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }

        $check = getimagesize($_FILES['image_url']['tmp_name']);
        if ($check === false) {
            header('Location: /category?error=' . urlencode('File is not an image.'));
            exit();
        }
        if ($_FILES['image_url']['size'] > 5000000) {
            header('Location: /category?error=' . urlencode('Image is too large (5MB max).'));
            exit();
        }
        $ext = strtolower(pathinfo($_FILES['image_url']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            header('Location: /category?error=' . urlencode('Only JPG, JPEG, PNG & GIF files are allowed.'));
            exit();
        }

        $safe_name   = generate_unique_filename($target_dir, basename($_FILES['image_url']['name']));
        $target_file = $target_dir . $safe_name;
        if (move_uploaded_file($_FILES['image_url']['tmp_name'], $target_file)) {
            $image_url = $base_url . '/images/cat/' . $safe_name;
        }
    }

    if (isset($_POST['save'])) {
        $slug = unique_category_slug($conn, slugify($title));
        $stmt = $conn->prepare("INSERT INTO category (cat_name, cat_slug, image, user_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('sssi', $title, $slug, $image_url, $userId);
        $ok  = $stmt->execute();
        $msg = 'Category added successfully!';
    } else {
        $cat_id = (int) ($_POST['id'] ?? 0);
        $slug   = unique_category_slug($conn, slugify($title), $cat_id);
        $stmt = $conn->prepare("UPDATE category SET cat_name = ?, cat_slug = ?, image = ? WHERE id = ?");
        $stmt->bind_param('sssi', $title, $slug, $image_url, $cat_id);
        $ok  = $stmt->execute();
        $msg = 'Category updated successfully!';
    }

    header('Location: /category?' . ($ok ? 'success=' . urlencode($msg) : 'error=' . urlencode($conn->error)));
    exit();
}

/* ===================== handle delete ===================== */
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $stmt = $conn->prepare("DELETE FROM category WHERE id = ?");
    $del_id = (int) $_GET['id'];
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    header('Location: /category?success=' . urlencode('Category deleted successfully!'));
    exit();
}

/* ===================== fetch list (paginated) ===================== */
$perPage = 10;
$page    = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) { $page = 1; }

$totalCategories = (int) q_one($conn, "SELECT COUNT(*) FROM category");
$totalPages       = max(1, (int) ceil($totalCategories / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

$stmt = $conn->prepare("
    SELECT c.*, u.fname AS added_fname, u.lname AS added_lname
    FROM category c
    LEFT JOIN users u ON c.user_id = u.id
    ORDER BY c.id DESC LIMIT ? OFFSET ?
");
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$categories = $stmt->get_result();

include __DIR__ . '/../include/header.php';
/* header.php has already opened <div class="content"> */
?>

<div class="page-head">
  <div>
    <h2>Categories</h2>
    <p><?php echo number_format($totalCategories); ?> categor<?php echo $totalCategories === 1 ? 'y' : 'ies'; ?> on the platform</p>
  </div>
  <button type="button" class="btn-pink-sm" id="openAddCatBtn"><i class="fa-solid fa-plus"></i> Add Category</button>
</div>

<div class="panel-card">
  <div class="head">
    <div>
      <h5>All Categories</h5>
      <div class="sub">Used to tag and filter events on the public site</div>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table-clean">
      <thead>
        <tr>
          <th>Category</th>
          <th>Slug</th>
          <th>Added By</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($categories && $categories->num_rows): while ($cat = $categories->fetch_assoc()):
          $addedBy = trim(($cat['added_fname'] ?? '') . ' ' . ($cat['added_lname'] ?? ''));
        ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <img class="thumb" src="<?php echo htmlspecialchars($cat['image'] ?: '/img/placeholder-cat.png'); ?>" alt="" onerror="this.src='/img/placeholder-cat.png'">
                <div class="cell-title"><?php echo htmlspecialchars($cat['cat_name']); ?></div>
              </div>
            </td>
            <td class="cell-sub">/<?php echo htmlspecialchars($cat['cat_slug']); ?></td>
            <td class="cell-sub"><?php echo $addedBy !== '' ? htmlspecialchars($addedBy) : '—'; ?></td>
            <td style="text-align:right;">
              <div class="d-flex justify-content-end gap-1">
                <button type="button"
                        class="btn-mini js-edit-cat"
                        data-id="<?php echo (int) $cat['id']; ?>"
                        data-name="<?php echo htmlspecialchars($cat['cat_name'], ENT_QUOTES); ?>"
                        data-image="<?php echo htmlspecialchars($cat['image'], ENT_QUOTES); ?>">
                  <i class="fa-solid fa-pencil"></i>
                </button>
                <a href="?action=delete&id=<?php echo (int) $cat['id']; ?>"
                   class="btn-mini js-delete-cat"
                   style="color:var(--pink);border-color:var(--pink);">
                  <i class="fa-solid fa-trash"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="4" class="cell-sub text-center">No categories yet — add your first one.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pager-c">
    <div class="pager-info">
      Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $totalCategories); ?> of <?php echo $totalCategories; ?>
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
</style>

<!-- ================= ADD / EDIT CATEGORY MODAL ================= -->
<div class="modal fade" id="catModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form role="form" method="POST" action="" enctype="multipart/form-data" id="catForm">
        <div class="modal-header">
          <div>
            <h5 id="catModalTitle">Add New Category</h5>
            <div class="cell-sub">Used to tag events on the public site</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="id" id="f_cat_id">
          <input type="hidden" name="old_image_url" id="f_old_image_url">

          <div class="mb-3">
            <label class="form-label-c">Category Name</label>
            <input type="text" class="form-control-c" name="catname" id="f_catname" placeholder="e.g. Ladies Night" required>
            <div class="cell-sub mt-1">URL slug is generated automatically from the name.</div>
          </div>

          <div class="mb-1">
            <label class="form-label-c">Category Image</label>
            <input type="file" class="form-control-c" name="image_url" id="f_image_url" accept="image/*">
            <div id="f_image_preview_wrap" class="mt-2 d-none">
              <img id="f_image_preview" src="" width="64" height="64" style="object-fit:cover;border-radius:10px;border:1px solid var(--line);" alt="Current image">
              <span class="cell-sub ms-2">Current image — upload a new one to replace it</span>
            </div>
          </div>
        </div>

        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn-mini" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-pink-sm" name="save" id="catSubmitBtn">Add New Category</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  // Wrapped in DOMContentLoaded: bootstrap.bundle.min.js is loaded further down
  // in include/footer.php, so `bootstrap` isn't defined yet at the point this
  // <script> tag appears in the page. Waiting for DOMContentLoaded guarantees
  // every earlier <script src> (including Bootstrap's) has already run.
  document.addEventListener('DOMContentLoaded', function () {
    var catModalEl = document.getElementById('catModal');
    var catModal = new bootstrap.Modal(catModalEl);
    var catForm = document.getElementById('catForm');
    var submitBtn = document.getElementById('catSubmitBtn');

    function resetCatForm() {
      catForm.reset();
      document.getElementById('f_cat_id').value = '';
      document.getElementById('f_old_image_url').value = '';
      document.getElementById('f_image_preview_wrap').classList.add('d-none');
      document.getElementById('catModalTitle').textContent = 'Add New Category';
      submitBtn.name = 'save';
      submitBtn.textContent = 'Add New Category';
    }

    document.getElementById('openAddCatBtn').addEventListener('click', function () {
      resetCatForm();
      catModal.show();
    });

    document.querySelectorAll('.js-edit-cat').forEach(function (btn) {
      btn.addEventListener('click', function () {
        resetCatForm();
        document.getElementById('catModalTitle').textContent = 'Edit Category';
        submitBtn.name = 'update';
        submitBtn.textContent = 'Update Category';

        document.getElementById('f_cat_id').value = btn.dataset.id;
        document.getElementById('f_catname').value = btn.dataset.name;
        document.getElementById('f_old_image_url').value = btn.dataset.image;

        if (btn.dataset.image) {
          document.getElementById('f_image_preview').src = btn.dataset.image;
          document.getElementById('f_image_preview_wrap').classList.remove('d-none');
        }

        catModal.show();
      });
    });

    document.querySelectorAll('.js-delete-cat').forEach(function (link) {
      link.addEventListener('click', function (e) {
        if (!confirm('Delete this category? This cannot be undone.')) e.preventDefault();
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