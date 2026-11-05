<?php
/**
 * artist.php
 * Artist management — list, add, edit (via modal), delete.
 * Matches the shared dark dashboard theme (include/style.php) and uses
 * include/header.php + include/sidebar.php + include/footer.php like
 * every other admin/partner screen.
 *
 * Requires the `artist` table to have a `user_id` column — see
 * migration_artist_user_id.sql if you haven't run it yet.
 */
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';          // sets up $conn (mysqli)

$pageTitle  = 'Artists';
$activePage = 'artist';

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
    return $text !== '' ? $text : 'artist';
}

function unique_artist_slug($conn, $base, $excludeId = 0) {
    $slug = $base;
    $i = 2;
    while (true) {
        $stmt = $conn->prepare("SELECT id FROM artist WHERE url = ? AND id != ? LIMIT 1");
        $stmt->bind_param('si', $slug, $excludeId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        if (!$exists) return $slug;
        $slug = $base . '-' . $i;
        $i++;
    }
}

/* ===================== handle save / update ===================== */
if (isset($_POST['save']) || isset($_POST['update'])) {
    $artist_name = trim($_POST['artist_name'] ?? '');
    $city        = trim($_POST['city'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $genre       = trim($_POST['genre'] ?? '');
    $facebook    = trim($_POST['facebook'] ?? '');
    $instagram   = trim($_POST['instagram'] ?? '');
    $old_image   = trim($_POST['old_image_url'] ?? '');

    $base_url = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];

    // Handle image upload (optional on update — keeps old image if none supplied)
    $image_url = $old_image;
    if (isset($_FILES['image_url']) && $_FILES['image_url']['error'] === UPLOAD_ERR_OK && $_FILES['image_url']['name'] !== '') {
        $target_dir = __DIR__ . '/../images/artist/';
        if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }
        $safe_name   = uniqid('artist_') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['image_url']['name']));
        $target_file = $target_dir . $safe_name;
        if (move_uploaded_file($_FILES['image_url']['tmp_name'], $target_file)) {
            $image_url = $base_url . '/images/artist/' . $safe_name;
        }
    }

    if (isset($_POST['save'])) {
        // Auto-generate a unique slug from the artist name
        $slug = unique_artist_slug($conn, slugify($artist_name));

        $stmt = $conn->prepare(
            "INSERT INTO artist (name, city, description, genre, fb, insta, image_url, url, user_id, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())"
        );
        $stmt->bind_param('ssssssssi', $artist_name, $city, $description, $genre, $facebook, $instagram, $image_url, $slug, $userId);
        $ok  = $stmt->execute();
        $msg = 'Artist added successfully!';
    } else {
        $artist_id = (int) ($_POST['artist_id'] ?? 0);
        // Re-derive the slug from the (possibly changed) name, excluding this record from the uniqueness check
        $slug = unique_artist_slug($conn, slugify($artist_name), $artist_id);

        $stmt = $conn->prepare(
            "UPDATE artist SET name=?, city=?, description=?, genre=?, fb=?, insta=?, image_url=?, url=? WHERE id=?"
        );
        $stmt->bind_param('ssssssssi', $artist_name, $city, $description, $genre, $facebook, $instagram, $image_url, $slug, $artist_id);
        $ok  = $stmt->execute();
        $msg = 'Artist updated successfully!';
    }

    header('Location: /artist?' . ($ok ? 'success=' . urlencode($msg) : 'error=' . urlencode($conn->error)));
    exit();
}

/* ===================== handle delete ===================== */
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $stmt = $conn->prepare("DELETE FROM artist WHERE id = ?");
    $del_id = (int) $_GET['id'];
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    header('Location: /artist?success=' . urlencode('Artist deleted successfully!'));
    exit();
}

/* ===================== handle status toggle ===================== */
if (isset($_GET['action']) && $_GET['action'] === 'toggle' && isset($_GET['id'])) {
    $toggle_id = (int) $_GET['id'];
    $conn->query("UPDATE artist SET status = 1 - status WHERE id = " . $toggle_id);
    header('Location: /artist?success=' . urlencode('Status updated.'));
    exit();
}

/* ===================== autocomplete sources ===================== */
// Genre suggestions pulled from your existing event categories
$genreOptions = [];
$catRes = $conn->query("SELECT DISTINCT cat_name FROM category WHERE cat_name != '' ORDER BY cat_name");
if ($catRes) while ($r = $catRes->fetch_assoc()) { $genreOptions[] = $r['cat_name']; }

// City suggestions pulled from artists/venues/users already in the system
$cityOptions = [];
$cityRes = $conn->query("
    SELECT city FROM (
        SELECT city FROM artist WHERE city != ''
        UNION SELECT city FROM place WHERE city != ''
        UNION SELECT city FROM users WHERE city != ''
    ) c ORDER BY city
");
if ($cityRes) while ($r = $cityRes->fetch_assoc()) { $cityOptions[] = $r['city']; }

/* ===================== fetch list (paginated) ===================== */
$perPage = 10;
$page    = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) { $page = 1; }

$totalArtists = (int) q_one($conn, "SELECT COUNT(*) FROM artist");
$totalPages   = max(1, (int) ceil($totalArtists / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

$stmt = $conn->prepare("
    SELECT a.*, u.fname AS added_fname, u.lname AS added_lname
    FROM artist a
    LEFT JOIN users u ON a.user_id = u.id
    ORDER BY a.id DESC LIMIT ? OFFSET ?
");
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$artists = $stmt->get_result();

include __DIR__ . '/../include/header.php';
/* header.php has already opened <div class="content"> */
?>

<div class="page-head">
  <div>
    <h2>Artists</h2>
    <p><?php echo number_format($totalArtists); ?> artist<?php echo $totalArtists === 1 ? '' : 's'; ?> on the platform</p>
  </div>
  <button type="button" class="btn-pink-sm" id="openAddArtistBtn"><i class="fa-solid fa-plus"></i> Add Artist</button>
</div>

<div class="panel-card">
  <div class="head">
    <div>
      <h5>All Artists</h5>
      <div class="sub">Manage artist profiles shown on event pages</div>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table-clean">
      <thead>
        <tr>
          <th>Artist</th>
          <th>City</th>
          <th>Genre</th>
          <th>Added By</th>
          <th>Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($artists && $artists->num_rows): while ($artist = $artists->fetch_assoc()):
          $addedBy = trim(($artist['added_fname'] ?? '') . ' ' . ($artist['added_lname'] ?? ''));
        ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <img class="thumb" src="<?php echo htmlspecialchars($artist['image_url'] ?: '/img/placeholder-artist.png'); ?>" alt="" onerror="this.src='/img/placeholder-artist.png'">
                <div>
                  <div class="cell-title"><?php echo htmlspecialchars($artist['name']); ?></div>
                  <div class="cell-sub">/<?php echo htmlspecialchars($artist['url'] ?: '—'); ?></div>
                </div>
              </div>
            </td>
            <td><?php echo htmlspecialchars($artist['city'] ?: '—'); ?></td>
            <td><?php echo htmlspecialchars($artist['genre'] ?: '—'); ?></td>
            <td class="cell-sub"><?php echo $addedBy !== '' ? htmlspecialchars($addedBy) : '—'; ?></td>
            <td>
              <a href="?action=toggle&id=<?php echo (int) $artist['id']; ?>">
                <span class="badge-pill <?php echo ((int) $artist['status'] === 1) ? 'badge-live' : 'badge-past'; ?>">
                  <?php echo ((int) $artist['status'] === 1) ? 'Active' : 'Inactive'; ?>
                </span>
              </a>
            </td>
            <td style="text-align:right;">
              <div class="d-flex justify-content-end gap-1">
                <button type="button"
                        class="btn-mini js-edit-artist"
                        data-id="<?php echo (int) $artist['id']; ?>"
                        data-name="<?php echo htmlspecialchars($artist['name'], ENT_QUOTES); ?>"
                        data-city="<?php echo htmlspecialchars($artist['city'], ENT_QUOTES); ?>"
                        data-genre="<?php echo htmlspecialchars($artist['genre'], ENT_QUOTES); ?>"
                        data-description="<?php echo htmlspecialchars($artist['description'], ENT_QUOTES); ?>"
                        data-facebook="<?php echo htmlspecialchars($artist['fb'], ENT_QUOTES); ?>"
                        data-instagram="<?php echo htmlspecialchars($artist['insta'], ENT_QUOTES); ?>"
                        data-image="<?php echo htmlspecialchars($artist['image_url'], ENT_QUOTES); ?>">
                  <i class="fa-solid fa-pencil"></i>
                </button>
                <a href="?action=delete&id=<?php echo (int) $artist['id']; ?>"
                   class="btn-mini js-delete-artist"
                   style="color:var(--pink);border-color:var(--pink);">
                  <i class="fa-solid fa-trash"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="6" class="cell-sub text-center">No artists yet — add your first one.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pager-c">
    <div class="pager-info">
      Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $totalArtists); ?> of <?php echo $totalArtists; ?>
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

<!-- Shared datalists for autocomplete-with-freeform-entry on Genre + City -->
<datalist id="genreList">
  <?php foreach ($genreOptions as $g): ?>
    <option value="<?php echo htmlspecialchars($g, ENT_QUOTES); ?>">
  <?php endforeach; ?>
</datalist>
<datalist id="cityList">
  <?php foreach ($cityOptions as $c): ?>
    <option value="<?php echo htmlspecialchars($c, ENT_QUOTES); ?>">
  <?php endforeach; ?>
</datalist>

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
  .ck.ck-editor__main > .ck-editor__editable{ background:var(--panel-2) !important; color:var(--text) !important; border-color:var(--line) !important; min-height:160px; }
  .ck.ck-button, .ck .ck-button{ color:var(--muted) !important; }
  .ck.ck-button:hover, .ck .ck-button:hover{ background:var(--panel-3) !important; color:var(--text) !important; }
  .ck.ck-button.ck-on{ background:rgba(255,61,104,.16) !important; color:var(--pink) !important; }
  .ck.ck-toolbar__separator{ background:var(--line) !important; }
  .ck.ck-dropdown__panel, .ck.ck-list{ background:var(--panel-2) !important; border-color:var(--line) !important; }
  .ck.ck-list__item .ck-button{ color:var(--text) !important; }
</style>

<!-- ================= ADD / EDIT ARTIST MODAL ================= -->
<div class="modal fade" id="artistModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form role="form" method="POST" action="" enctype="multipart/form-data" id="artistForm">
        <div class="modal-header">
          <div>
            <h5 id="artistModalTitle">Add New Artist</h5>
            <div class="cell-sub">Shown on the public event &amp; artist pages</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="artist_id" id="f_artist_id">
          <input type="hidden" name="old_image_url" id="f_old_image_url">

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label-c">Artist Name</label>
              <input type="text" class="form-control-c" name="artist_name" id="f_artist_name" placeholder="e.g. DJ Esha" required>
              <div class="cell-sub mt-1">URL slug is generated automatically from the name.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label-c">City</label>
              <input type="text" class="form-control-c" name="city" id="f_city" list="cityList" placeholder="Start typing a city…" autocomplete="off" required>
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Genre</label>
              <input type="text" class="form-control-c" name="genre" id="f_genre" list="genreList" placeholder="Start typing a genre…" autocomplete="off">
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Artist Image</label>
              <input type="file" class="form-control-c" name="image_url" id="f_image_url" accept="image/*">
              <div id="f_image_preview_wrap" class="mt-2 d-none">
                <img id="f_image_preview" src="" width="64" height="64" style="object-fit:cover;border-radius:10px;border:1px solid var(--line);" alt="Current image">
                <span class="cell-sub ms-2">Current image — upload a new one to replace it</span>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Facebook</label>
              <input type="text" class="form-control-c" name="facebook" id="f_facebook" placeholder="Facebook URL or handle">
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Instagram</label>
              <input type="text" class="form-control-c" name="instagram" id="f_instagram" placeholder="Instagram URL or handle">
            </div>
            <div class="col-12">
              <label class="form-label-c">Description</label>
              <textarea class="form-control-c" name="description" id="f_description" rows="6" placeholder="Short artist bio"></textarea>
            </div>
          </div>
        </div>

        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn-mini" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-pink-sm" name="save" id="artistSubmitBtn">Add New Artist</button>
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
    var artistModalEl = document.getElementById('artistModal');
    var artistModal = new bootstrap.Modal(artistModalEl);
    var artistForm = document.getElementById('artistForm');
    var submitBtn = document.getElementById('artistSubmitBtn');
    var descField = document.getElementById('f_description');
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

    function resetArtistForm() {
      artistForm.reset();
      document.getElementById('f_artist_id').value = '';
      document.getElementById('f_old_image_url').value = '';
      document.getElementById('f_image_preview_wrap').classList.add('d-none');
      document.getElementById('artistModalTitle').textContent = 'Add New Artist';
      submitBtn.name = 'save';
      submitBtn.textContent = 'Add New Artist';
      if (descEditor) { descEditor.setData(''); }
    }

    document.getElementById('openAddArtistBtn').addEventListener('click', function () {
      resetArtistForm();
      artistModal.show();
    });

    document.querySelectorAll('.js-edit-artist').forEach(function (btn) {
      btn.addEventListener('click', function () {
        resetArtistForm();
        document.getElementById('artistModalTitle').textContent = 'Edit Artist';
        submitBtn.name = 'update';
        submitBtn.textContent = 'Update Artist';

        document.getElementById('f_artist_id').value = btn.dataset.id;
        document.getElementById('f_artist_name').value = btn.dataset.name;
        document.getElementById('f_city').value = btn.dataset.city;
        document.getElementById('f_genre').value = btn.dataset.genre;
        document.getElementById('f_facebook').value = btn.dataset.facebook;
        document.getElementById('f_instagram').value = btn.dataset.instagram;
        document.getElementById('f_old_image_url').value = btn.dataset.image;
        descField.value = btn.dataset.description;
        if (descEditor) { descEditor.setData(btn.dataset.description || ''); }

        if (btn.dataset.image) {
          document.getElementById('f_image_preview').src = btn.dataset.image;
          document.getElementById('f_image_preview_wrap').classList.remove('d-none');
        }

        artistModal.show();
      });
    });

    document.querySelectorAll('.js-delete-artist').forEach(function (link) {
      link.addEventListener('click', function (e) {
        if (!confirm('Delete this artist? This cannot be undone.')) e.preventDefault();
      });
    });

    // Init/teardown the WYSIWYG editor alongside the modal so it never double-inits
    artistModalEl.addEventListener('shown.bs.modal', initDescEditor);
    artistModalEl.addEventListener('hidden.bs.modal', destroyDescEditor);

    // Make sure the rich-text content is synced back into the textarea before POST
    artistForm.addEventListener('submit', function () {
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