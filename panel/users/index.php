<?php
/**
 * users/index.php ("Registered Users")
 * Lists general users (user_type = 0), with add-user, ban/unban and CSV export.
 * Matches the shared dark dashboard theme (include/style.php) and uses
 * include/header.php + include/footer.php like every other admin screen
 * (see place/index.php / settings/index.php, which this page mirrors).
 *
 * Requires include/session_guard.php (sets $role, $userId) and
 * include/config.php (sets up $conn) — same pattern as place/index.php.
 * Admin-only: "Registered Users" only appears on the admin sidebar.
 *
 * Ban/unban uses a new `is_banned` column on `users` — see
 * migration_users_is_banned.sql. Run that once before deploying this page.
 */
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';          // sets up $conn (mysqli)

if ($role !== 'admin') {
    header('Location: /profile');
    exit();
}

$pageTitle  = 'Registered Users';
$activePage = 'users';

/* ===================== handle: CSV export ===================== */
if (isset($_POST['export_csv'])) {
    $filename = 'users_data_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'First Name', 'Last Name', 'Gender', 'City', 'Email', 'Contact No', 'Status', 'Registered Date']);

    $res = $conn->query("SELECT * FROM users WHERE user_type = 0 ORDER BY id DESC");
    while ($u = $res->fetch_assoc()) {
        fputcsv($output, [
            $u['id'],
            $u['fname'],
            $u['lname'],
            $u['gender'],
            $u['city'],
            $u['email'],
            $u['contactno'],
            ((int) ($u['is_banned'] ?? 0) === 1) ? 'Banned' : 'Active',
            $u['posting_date'],
        ]);
    }
    fclose($output);
    exit();
}

/* ===================== handle: add user ===================== */
if (isset($_POST['add_user'])) {
    $fname     = trim($_POST['fname'] ?? '');
    $lname     = trim($_POST['lname'] ?? '');
    $gender    = trim($_POST['gender'] ?? '');
    $city      = trim($_POST['city'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $contactno = trim($_POST['contactno'] ?? '');
    $password  = (string) ($_POST['password'] ?? '');

    $errors = [];
    if ($fname === '')  { $errors[] = 'First name is required.'; }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'A valid email is required.'; }
    if (strlen($password) < 6) { $errors[] = 'Password must be at least 6 characters.'; }

    if (!$errors) {
        $dupe = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $dupe->bind_param('s', $email);
        $dupe->execute();
        if ($dupe->get_result()->num_rows > 0) {
            $errors[] = 'A user with that email already exists.';
        }
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            "INSERT INTO users (fname, lname, gender, city, status, email, password, contactno, image_url, posting_date, user_type)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, '', NOW(), 0)"
        );
        $stmt->bind_param('sssssss', $fname, $lname, $gender, $city, $email, $hash, $contactno);
        $ok = $stmt->execute();

        header('Location: /users?' . ($ok ? 'success=' . urlencode('User added successfully!') : 'error=' . urlencode($conn->error)));
        exit();
    }

    // Fall through with errors -> re-render the page below with the modal reopened
    $addUserErrors = $errors;
    $addUserOld    = $_POST;
}

/* ===================== handle: ban / unban toggle ===================== */
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'toggle-ban') {
    $uid = (int) $_GET['id'];
    $conn->query("UPDATE users SET is_banned = 1 - is_banned WHERE id = " . $uid . " AND user_type = 0");
    header('Location: /users?success=' . urlencode('User status updated.'));
    exit();
}

/* ===================== fetch list (paginated) ===================== */
$perPage = 15;
$page    = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) { $page = 1; }

$totalUsers = (int) ($conn->query("SELECT COUNT(*) c FROM users WHERE user_type = 0")->fetch_assoc()['c'] ?? 0);
$totalPages = max(1, (int) ceil($totalUsers / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

$stmt = $conn->prepare("SELECT * FROM users WHERE user_type = 0 ORDER BY id DESC LIMIT ? OFFSET ?");
$stmt->bind_param('ii', $perPage, $offset);
$stmt->execute();
$users = $stmt->get_result();

include __DIR__ . '/../include/header.php';
/* header.php has already opened <div class="content"> */
?>

<div class="page-head">
  <div>
    <h2>Registered Users</h2>
    <p><?php echo number_format($totalUsers); ?> user<?php echo $totalUsers === 1 ? '' : 's'; ?> on the platform</p>
  </div>
  <div class="d-flex gap-2">
    <form method="POST" action="" class="d-inline">
      <button type="submit" name="export_csv" class="btn-mini"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
    </form>
    <button type="button" class="btn-pink-sm" id="openAddUserBtn"><i class="fa-solid fa-user-plus"></i> Add User</button>
  </div>
</div>

<div class="panel-card">
  <div class="head">
    <div>
      <h5>All Users</h5>
      <div class="sub">General registered users (not partners or admins)</div>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table-clean">
      <thead>
        <tr>
          <th>User</th>
          <th>Gender</th>
          <th>Contact</th>
          <th>Registered</th>
          <th>Status</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($users && $users->num_rows): while ($u = $users->fetch_assoc()):
          $isBanned = (int) ($u['is_banned'] ?? 0) === 1;
          $fullName = trim(($u['fname'] ?? '') . ' ' . ($u['lname'] ?? '')) ?: '—';
        ?>
          <tr>
            <td>
              <div class="cell-title"><?php echo htmlspecialchars($fullName); ?></div>
              <div class="cell-sub"><?php echo htmlspecialchars($u['email'] ?: '—'); ?></div>
            </td>
            <td class="cell-sub"><?php echo htmlspecialchars($u['gender'] ?: '—'); ?></td>
            <td class="cell-sub"><?php echo htmlspecialchars($u['contactno'] ?: '—'); ?></td>
            <td class="cell-sub"><?php echo htmlspecialchars($u['posting_date']); ?></td>
            <td>
              <span class="badge-pill <?php echo $isBanned ? 'badge-banned' : 'badge-live'; ?>">
                <?php echo $isBanned ? 'Banned' : 'Active'; ?>
              </span>
            </td>
            <td style="text-align:right;">
              <a href="?action=toggle-ban&id=<?php echo (int) $u['id']; ?>"
                 class="btn-mini js-toggle-ban"
                 data-banned="<?php echo $isBanned ? '1' : '0'; ?>"
                 style="<?php echo $isBanned ? '' : 'color:var(--pink);border-color:var(--pink);'; ?>">
                <?php if ($isBanned): ?>
                  <i class="fa-solid fa-user-check"></i> Unban
                <?php else: ?>
                  <i class="fa-solid fa-user-slash"></i> Ban
                <?php endif; ?>
              </a>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="6" class="cell-sub text-center">No users yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pager-c">
    <div class="pager-info">
      Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $totalUsers); ?> of <?php echo $totalUsers; ?>
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

  .badge-banned{ background:rgba(255,61,104,.14); color:var(--pink); }

  .field-error{ color:var(--pink); font-size:.74rem; font-weight:600; margin-top:.35rem; }
  .alert-c{ border:1px solid var(--pink); background:rgba(255,61,104,.08); color:var(--text); border-radius:10px; padding:.85rem 1rem; font-size:.82rem; margin-bottom:1.1rem; }
  .alert-c ul{ margin:.3rem 0 0 1.1rem; padding:0; }
</style>

<!-- ================= ADD USER MODAL ================= -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form role="form" method="POST" action="" id="addUserForm">
        <div class="modal-header">
          <div>
            <h5>Add New User</h5>
            <div class="cell-sub">Creates a general (non-partner) account</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <?php if (!empty($addUserErrors)): ?>
            <div class="alert-c">
              <strong>Please fix the following:</strong>
              <ul>
                <?php foreach ($addUserErrors as $err): ?>
                  <li><?php echo htmlspecialchars($err); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label-c">First Name</label>
              <input type="text" class="form-control-c" name="fname" value="<?php echo htmlspecialchars($addUserOld['fname'] ?? ''); ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Last Name</label>
              <input type="text" class="form-control-c" name="lname" value="<?php echo htmlspecialchars($addUserOld['lname'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Gender</label>
              <select class="form-control-c" name="gender">
                <option value="">—</option>
                <option value="male"   <?php echo (($addUserOld['gender'] ?? '') === 'male') ? 'selected' : ''; ?>>Male</option>
                <option value="female" <?php echo (($addUserOld['gender'] ?? '') === 'female') ? 'selected' : ''; ?>>Female</option>
                <option value="other"  <?php echo (($addUserOld['gender'] ?? '') === 'other') ? 'selected' : ''; ?>>Other</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label-c">City</label>
              <input type="text" class="form-control-c" name="city" value="<?php echo htmlspecialchars($addUserOld['city'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Email</label>
              <input type="email" class="form-control-c" name="email" value="<?php echo htmlspecialchars($addUserOld['email'] ?? ''); ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label-c">Contact No</label>
              <input type="text" class="form-control-c" name="contactno" maxlength="11" value="<?php echo htmlspecialchars($addUserOld['contactno'] ?? ''); ?>">
            </div>
            <div class="col-12">
              <label class="form-label-c">Password</label>
              <input type="password" class="form-control-c" name="password" minlength="6" placeholder="Minimum 6 characters" required>
            </div>
          </div>
        </div>

        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn-mini" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-pink-sm" name="add_user">Add User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    var addUserModalEl = document.getElementById('addUserModal');
    var addUserModal   = new bootstrap.Modal(addUserModalEl);

    document.getElementById('openAddUserBtn').addEventListener('click', function () {
      addUserModal.show();
    });

    <?php if (!empty($addUserErrors)): ?>
    addUserModal.show(); // re-open so validation errors are visible
    <?php endif; ?>

    document.querySelectorAll('.js-toggle-ban').forEach(function (link) {
      link.addEventListener('click', function (e) {
        var goingToBan = link.dataset.banned === '0';
        var msg = goingToBan ? 'Ban this user? They will be marked inactive.' : 'Unban this user?';
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