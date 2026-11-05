<?php
/**
 * settings/index.php ("Settings")
 * Combined admin screen for:
 *   - Site Settings   (key/value `settings` table, grouped in an accordion —
 *                       General / Business & Fees [incl. GST, platform fee,
 *                       commission] / Payment Gateway / Social Media)
 *   - Menu Items      (`menu` table — main menu, quick links, categories)
 *   - Header Tabs     (`tabs` table)
 *
 * Matches the shared dark dashboard theme (include/style.php) and uses
 * include/header.php + include/sidebar.php + include/footer.php like every
 * other admin screen (see place/index.php, which this page mirrors).
 *
 * NOTE: `settings` is a simple name/value table, so adding a brand new
 * setting later never needs a migration — just add one entry to the
 * $settingGroups config array below and it will render, save, and load
 * automatically.
 *
 * Requires include/session_guard.php (sets $role, $userId) and
 * include/config.php (sets up $conn) — same pattern as place/index.php.
 * Admin-only: partners don't get a "System" section in the sidebar.
 */
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';          // sets up $conn (mysqli)

// Site-wide Settings / Menu / Tabs are admin-only — include/sidebar.php only
// exposes the "System" section (Tabs, Menu, Settings) on the admin sidebar,
// not the partner one, so this page mirrors that.
if ($role !== 'admin') {
    header('Location: /profile');
    exit();
}

$pageTitle  = 'Settings';
$activePage = 'settings';

/* ===================== settings field definitions ===================== */
$settingGroups = [
    'general' => [
        'label'  => 'General',
        'icon'   => 'fa-gear',
        'sub'    => 'Branding & basic site info',
        'open'   => true,
        'fields' => [
            'website_name'   => ['label' => 'Website Name',    'type' => 'text'],
            'logo'           => ['label' => 'Logo',            'type' => 'file'],
            'preloader'      => ['label' => 'Preloader Image', 'type' => 'file'],
            'address'        => ['label' => 'Address',         'type' => 'textarea'],
            'contact_info'   => ['label' => 'Contact Info',    'type' => 'text'],
            'support_email'  => ['label' => 'Support Email',   'type' => 'email'],
            'no_reply_email' => ['label' => 'No-Reply Email',  'type' => 'email'],
            'base_url'       => ['label' => 'Base URL',        'type' => 'url'],
            'partner_view_contact_details' => [
                'label' => 'Show Attendee Contact Info to Partners',
                'type'  => 'checkbox',
                'help'  => 'Admins can always see attendee phone & email on the event details page. Turn this on to let partners see it too for their own events.',
            ],
        ],
    ],
    'fees' => [
        'label'  => 'Business & Fees',
        'icon'   => 'fa-sack-dollar',
        'sub'    => 'Booking, commission, platform fee & GST',
        'open'   => false,
        'fields' => [
            'booking_percentage'      => ['label' => 'Booking Percentage (%)',     'type' => 'number'],
            'commission_percentage'   => ['label' => 'Commission Fee (%)',         'type' => 'number'],
            'platform_fee_percentage' => ['label' => 'Platform Fee (%)',           'type' => 'number'],
            'gst_percentage'          => ['label' => 'GST (%)',                    'type' => 'number'],
            'gst_on_platform_fee'     => ['label' => 'Charge GST on platform fee', 'type' => 'checkbox'],
        ],
    ],
    'payment' => [
        'label'  => 'Payment Gateway',
        'icon'   => 'fa-credit-card',
        'sub'    => 'Razorpay credentials',
        'open'   => false,
        'fields' => [
            'razorpay_api_key'    => ['label' => 'Razorpay API Key',    'type' => 'text'],
            'razorpay_api_secret' => ['label' => 'Razorpay API Secret', 'type' => 'text'],
        ],
    ],
    'social' => [
        'label'  => 'Social Media',
        'icon'   => 'fa-share-nodes',
        'sub'    => 'Links shown in the site footer',
        'open'   => false,
        'fields' => [
            'facebook'  => ['label' => 'Facebook URL',    'type' => 'url'],
            'twitter'   => ['label' => 'Twitter / X URL', 'type' => 'url'],
            'instagram' => ['label' => 'Instagram URL',   'type' => 'url'],
            'pinterest' => ['label' => 'Pinterest URL',   'type' => 'url'],
            'youtube'   => ['label' => 'YouTube URL',     'type' => 'url'],
        ],
    ],
];

/* ===================== helpers ===================== */
function base_url_prefix() {
    return ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
}

function fetch_settings($conn) {
    $map = [];
    $res = $conn->query("SELECT id, name, value FROM settings");
    if ($res) { while ($row = $res->fetch_assoc()) { $map[$row['name']] = $row; } }
    return $map;
}

function upsert_setting($conn, &$existingIds, $name, $value) {
    if (isset($existingIds[$name])) {
        $stmt = $conn->prepare("UPDATE settings SET value = ? WHERE id = ?");
        $stmt->bind_param('si', $value, $existingIds[$name]);
        return $stmt->execute();
    }
    $stmt = $conn->prepare("INSERT INTO settings (name, value, created_at) VALUES (?, ?, NOW())");
    $stmt->bind_param('ss', $name, $value);
    $ok = $stmt->execute();
    if ($ok) { $existingIds[$name] = $conn->insert_id; }
    return $ok;
}

/* ===================== handle: settings save ===================== */
if (isset($_POST['save_settings'])) {
    $existingRows = fetch_settings($conn);
    $existingIds  = [];
    foreach ($existingRows as $name => $row) { $existingIds[$name] = (int) $row['id']; }

    foreach ($settingGroups as $group) {
        foreach ($group['fields'] as $key => $field) {
            if ($field['type'] === 'file') {
                if (isset($_FILES[$key]) && $_FILES[$key]['error'] === UPLOAD_ERR_OK && $_FILES[$key]['name'] !== '') {
                    $target_dir = __DIR__ . '/../images/';
                    if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }
                    $safe_name   = uniqid('setting_') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES[$key]['name']));
                    $target_file = $target_dir . $safe_name;
                    if (move_uploaded_file($_FILES[$key]['tmp_name'], $target_file)) {
                        upsert_setting($conn, $existingIds, $key, base_url_prefix() . '/images/' . $safe_name);
                    }
                }
                continue; // no new file supplied -> leave existing value untouched
            }

            $value = ($field['type'] === 'checkbox')
                ? (isset($_POST[$key]) ? '1' : '0')
                : trim($_POST[$key] ?? '');

            upsert_setting($conn, $existingIds, $key, $value);
        }
    }

    header('Location: /settings?tab=settings&success=' . urlencode('Settings updated successfully!'));
    exit();
}

/* ===================== handle: menu save / update ===================== */
if (isset($_POST['save_menu']) || isset($_POST['update_menu'])) {
    $name  = trim($_POST['name'] ?? '');
    $url   = trim($_POST['url'] ?? '');
    $place = trim($_POST['place'] ?? 'main_menu');

    if (isset($_POST['save_menu'])) {
        $stmt = $conn->prepare("INSERT INTO menu (name, url, place, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param('sss', $name, $url, $place);
        $ok  = $stmt->execute();
        $msg = 'Menu item added successfully!';
    } else {
        $menu_id = (int) ($_POST['menu_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE menu SET name=?, url=?, place=? WHERE id=?");
        $stmt->bind_param('sssi', $name, $url, $place, $menu_id);
        $ok  = $stmt->execute();
        $msg = 'Menu item updated successfully!';
    }

    header('Location: /settings?tab=menu&' . ($ok ? 'success=' . urlencode($msg) : 'error=' . urlencode($conn->error)));
    exit();
}

/* ===================== handle: menu delete ===================== */
if (isset($_GET['section'], $_GET['action'], $_GET['id']) && $_GET['section'] === 'menu' && $_GET['action'] === 'delete') {
    $stmt = $conn->prepare("DELETE FROM menu WHERE id = ?");
    $del_id = (int) $_GET['id'];
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    header('Location: /settings?tab=menu&success=' . urlencode('Menu item deleted successfully!'));
    exit();
}

/* ===================== handle: tab save / update ===================== */
if (isset($_POST['save_tab']) || isset($_POST['update_tab'])) {
    $name     = trim($_POST['name'] ?? '');
    $url      = trim($_POST['url'] ?? '');
    $old_icon = trim($_POST['old_icon'] ?? '');
    $icon     = $old_icon;

    if (isset($_FILES['icon']) && $_FILES['icon']['error'] === UPLOAD_ERR_OK && $_FILES['icon']['name'] !== '') {
        $target_dir = __DIR__ . '/../images/icons/';
        if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }
        $check = getimagesize($_FILES['icon']['tmp_name']);
        if ($check !== false) {
            $safe_name   = uniqid('icon_') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['icon']['name']));
            $target_file = $target_dir . $safe_name;
            if (move_uploaded_file($_FILES['icon']['tmp_name'], $target_file)) {
                $icon = base_url_prefix() . '/images/icons/' . $safe_name;
            }
        }
    }

    if (isset($_POST['save_tab'])) {
        $stmt = $conn->prepare("INSERT INTO tabs (name, url, icon, created) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param('sss', $name, $url, $icon);
        $ok  = $stmt->execute();
        $msg = 'Tab added successfully!';
    } else {
        $tab_id = (int) ($_POST['tab_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE tabs SET name=?, url=?, icon=? WHERE id=?");
        $stmt->bind_param('sssi', $name, $url, $icon, $tab_id);
        $ok  = $stmt->execute();
        $msg = 'Tab updated successfully!';
    }

    header('Location: /settings?tab=tabs&' . ($ok ? 'success=' . urlencode($msg) : 'error=' . urlencode($conn->error)));
    exit();
}

/* ===================== handle: tab delete ===================== */
if (isset($_GET['section'], $_GET['action'], $_GET['id']) && $_GET['section'] === 'tabs' && $_GET['action'] === 'delete') {
    $stmt = $conn->prepare("DELETE FROM tabs WHERE id = ?");
    $del_id = (int) $_GET['id'];
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    header('Location: /settings?tab=tabs&success=' . urlencode('Tab deleted successfully!'));
    exit();
}

/* ===================== fetch data for render ===================== */
$settingsMap = fetch_settings($conn); // name => ['id'=>,'name'=>,'value'=>]

$menuRes = $conn->query("SELECT * FROM menu ORDER BY FIELD(place,'main_menu','quick_link','category'), id DESC");
$tabsRes = $conn->query("SELECT * FROM tabs ORDER BY id DESC");

$placeLabels = ['main_menu' => 'Main Menu', 'quick_link' => 'Quick Link', 'category' => 'Category'];

$activeTab = $_GET['tab'] ?? 'settings';
if (!in_array($activeTab, ['settings', 'menu', 'tabs'], true)) { $activeTab = 'settings'; }

include __DIR__ . '/../include/header.php';
/* header.php has already opened <div class="content"> */
?>

<div class="page-head">
  <div>
    <h2>Settings</h2>
    <p>Site configuration, navigation menus &amp; header tabs</p>
  </div>
</div>

<ul class="nav seg-tabs" id="settingsTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link <?php echo $activeTab === 'settings' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#paneSettings" type="button" role="tab">
      <i class="fa-solid fa-sliders"></i> Site Settings
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?php echo $activeTab === 'menu' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#paneMenu" type="button" role="tab">
      <i class="fa-solid fa-bars"></i> Menu Items
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?php echo $activeTab === 'tabs' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#paneTabs" type="button" role="tab">
      <i class="fa-solid fa-table-columns"></i> Header Tabs
    </button>
  </li>
</ul>

<div class="tab-content">

  <!-- ================= SITE SETTINGS ================= -->
  <div class="tab-pane fade <?php echo $activeTab === 'settings' ? 'show active' : ''; ?>" id="paneSettings" role="tabpanel">
    <form method="POST" action="" enctype="multipart/form-data" id="settingsForm">
      <div class="panel-card">
        <div class="head">
          <div>
            <h5>Site Settings</h5>
            <div class="sub">Grouped by category — expand a section to edit</div>
          </div>
          <button type="submit" name="save_settings" class="btn-pink-sm"><i class="fa-solid fa-floppy-disk"></i> Save Settings</button>
        </div>

        <div class="accordion accordion-dark" id="settingsAccordion">
          <?php foreach ($settingGroups as $groupKey => $group): ?>
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button <?php echo $group['open'] ? '' : 'collapsed'; ?>" type="button"
                        data-bs-toggle="collapse" data-bs-target="#grp_<?php echo $groupKey; ?>">
                  <i class="fa-solid <?php echo htmlspecialchars($group['icon']); ?> me-2"></i>
                  <span><?php echo htmlspecialchars($group['label']); ?><small><?php echo htmlspecialchars($group['sub']); ?></small></span>
                </button>
              </h2>
              <div id="grp_<?php echo $groupKey; ?>" class="accordion-collapse collapse <?php echo $group['open'] ? 'show' : ''; ?>" data-bs-parent="#settingsAccordion">
                <div class="accordion-body">
                  <div class="row g-3">
                    <?php foreach ($group['fields'] as $key => $field):
                        $currentValue = $settingsMap[$key]['value'] ?? '';
                    ?>
                      <div class="col-md-6">
                        <label class="form-label-c"><?php echo htmlspecialchars($field['label']); ?></label>

                        <?php if ($field['type'] === 'textarea'): ?>
                          <textarea class="form-control-c" name="<?php echo $key; ?>" rows="3"><?php echo htmlspecialchars($currentValue); ?></textarea>

                        <?php elseif ($field['type'] === 'file'): ?>
                          <input type="file" class="form-control-c" name="<?php echo $key; ?>" accept="image/*">
                          <?php if ($currentValue): ?>
                            <div class="cell-sub mt-1 d-flex align-items-center gap-2">
                              <img src="<?php echo htmlspecialchars($currentValue); ?>" width="36" height="36" style="object-fit:cover;border-radius:8px;border:1px solid var(--line);" alt="">
                              Current — upload a new file to replace
                            </div>
                          <?php endif; ?>

                        <?php elseif ($field['type'] === 'checkbox'): ?>
                          <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="<?php echo $key; ?>" id="chk_<?php echo $key; ?>" value="1" <?php echo $currentValue === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label cell-sub" for="chk_<?php echo $key; ?>">Enabled</label>
                          </div>

                        <?php else: ?>
                          <input type="<?php echo htmlspecialchars($field['type']); ?>" class="form-control-c" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($currentValue); ?>">
                        <?php endif; ?>

                        <?php if (!empty($field['help'])): ?>
                          <div class="cell-sub field-help"><?php echo htmlspecialchars($field['help']); ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </form>
  </div>

  <!-- ================= MENU ITEMS ================= -->
  <div class="tab-pane fade <?php echo $activeTab === 'menu' ? 'show active' : ''; ?>" id="paneMenu" role="tabpanel">
    <div class="panel-card">
      <div class="head">
        <div>
          <h5>Menu Items</h5>
          <div class="sub">Main menu, quick links &amp; category shortcuts shown on the site</div>
        </div>
        <button type="button" class="btn-pink-sm" id="openAddMenuBtn"><i class="fa-solid fa-plus"></i> Add Menu Item</button>
      </div>

      <div class="table-responsive">
        <table class="table-clean">
          <thead>
            <tr>
              <th>Name</th>
              <th>URL</th>
              <th>Place</th>
              <th>Created</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($menuRes && $menuRes->num_rows): while ($item = $menuRes->fetch_assoc()): ?>
              <tr>
                <td class="cell-title"><?php echo htmlspecialchars($item['name']); ?></td>
                <td class="cell-sub" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($item['url']); ?></td>
                <td><span class="badge-pill badge-live"><?php echo htmlspecialchars($placeLabels[$item['place']] ?? $item['place']); ?></span></td>
                <td class="cell-sub"><?php echo htmlspecialchars($item['created_at']); ?></td>
                <td style="text-align:right;">
                  <div class="d-flex justify-content-end gap-1">
                    <button type="button" class="btn-mini js-edit-menu"
                            data-id="<?php echo (int) $item['id']; ?>"
                            data-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>"
                            data-url="<?php echo htmlspecialchars($item['url'], ENT_QUOTES); ?>"
                            data-place="<?php echo htmlspecialchars($item['place'], ENT_QUOTES); ?>">
                      <i class="fa-solid fa-pencil"></i>
                    </button>
                    <a href="?tab=menu&section=menu&action=delete&id=<?php echo (int) $item['id']; ?>"
                       class="btn-mini js-delete-item" style="color:var(--pink);border-color:var(--pink);">
                      <i class="fa-solid fa-trash"></i>
                    </a>
                  </div>
                </td>
              </tr>
            <?php endwhile; else: ?>
              <tr><td colspan="5" class="cell-sub text-center">No menu items yet — add your first one.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ================= HEADER TABS ================= -->
  <div class="tab-pane fade <?php echo $activeTab === 'tabs' ? 'show active' : ''; ?>" id="paneTabs" role="tabpanel">
    <div class="panel-card">
      <div class="head">
        <div>
          <h5>Header Tabs</h5>
          <div class="sub">Icon-led tabs shown in the site's main navigation</div>
        </div>
        <button type="button" class="btn-pink-sm" id="openAddTabBtn"><i class="fa-solid fa-plus"></i> Add Tab</button>
      </div>

      <div class="table-responsive">
        <table class="table-clean">
          <thead>
            <tr>
              <th>Tab</th>
              <th>URL</th>
              <th>Created</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($tabsRes && $tabsRes->num_rows): while ($tab = $tabsRes->fetch_assoc()): ?>
              <tr>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <img class="thumb" src="<?php echo htmlspecialchars($tab['icon'] ?: '/img/placeholder-venue.png'); ?>" alt="" onerror="this.src='/img/placeholder-venue.png'">
                    <div class="cell-title"><?php echo htmlspecialchars($tab['name']); ?></div>
                  </div>
                </td>
                <td class="cell-sub" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($tab['url']); ?></td>
                <td class="cell-sub"><?php echo htmlspecialchars($tab['created']); ?></td>
                <td style="text-align:right;">
                  <div class="d-flex justify-content-end gap-1">
                    <button type="button" class="btn-mini js-edit-tab"
                            data-id="<?php echo (int) $tab['id']; ?>"
                            data-name="<?php echo htmlspecialchars($tab['name'], ENT_QUOTES); ?>"
                            data-url="<?php echo htmlspecialchars($tab['url'], ENT_QUOTES); ?>"
                            data-icon="<?php echo htmlspecialchars($tab['icon'], ENT_QUOTES); ?>">
                      <i class="fa-solid fa-pencil"></i>
                    </button>
                    <a href="?tab=tabs&section=tabs&action=delete&id=<?php echo (int) $tab['id']; ?>"
                       class="btn-mini js-delete-item" style="color:var(--pink);border-color:var(--pink);">
                      <i class="fa-solid fa-trash"></i>
                    </a>
                  </div>
                </td>
              </tr>
            <?php endwhile; else: ?>
              <tr><td colspan="4" class="cell-sub text-center">No tabs yet — add your first one.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<style>
  /* Segmented tab bar (Settings / Menu Items / Header Tabs) */
  .seg-tabs{ display:flex; gap:.4rem; border:none; margin:0 0 1.1rem; flex-wrap:wrap; }
  .seg-tabs .nav-link{ border:1px solid var(--line); background:var(--panel-2); color:var(--muted); font-size:.82rem; font-weight:700; border-radius:10px; padding:.55rem 1rem; display:inline-flex; align-items:center; gap:.45rem; }
  .seg-tabs .nav-link:hover{ color:var(--text); border-color:var(--pink); }
  .seg-tabs .nav-link.active{ background:var(--pink); border-color:var(--pink); color:#fff; }

  /* Accordion skin for the dark theme */
  .accordion-dark .accordion-item{ background:transparent; border:1px solid var(--line); border-radius:10px; margin-bottom:.7rem; overflow:hidden; }
  .accordion-dark .accordion-item:last-child{ margin-bottom:0; }
  .accordion-dark .accordion-button{ background:var(--panel-2); color:var(--text); font-weight:700; font-size:.88rem; box-shadow:none; }
  .accordion-dark .accordion-button span{ display:flex; flex-direction:column; gap:.1rem; }
  .accordion-dark .accordion-button span small{ font-weight:600; font-size:.72rem; color:var(--muted-2); }
  .accordion-dark .accordion-button:not(.collapsed){ background:rgba(255,61,104,.10); color:var(--pink); }
  .accordion-dark .accordion-button::after{ filter:invert(60%) sepia(6%) saturate(300%); }
  .accordion-dark .accordion-body{ background:var(--panel-2); border-top:1px solid var(--line); padding:1.1rem 1.2rem; }
  .accordion-dark .form-check-input:checked{ background-color:var(--pink); border-color:var(--pink); }
  .field-help{ margin-top:.35rem; font-size:.74rem; line-height:1.4; }

  /* Dark-theme skin for the CKEditor-free panels reuses form-control-c already defined globally */
</style>

<!-- ================= ADD / EDIT MENU MODAL ================= -->
<div class="modal fade" id="menuModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form role="form" method="POST" action="" id="menuForm">
        <div class="modal-header">
          <div>
            <h5 id="menuModalTitle">Add Menu Item</h5>
            <div class="cell-sub">Shown in the site's main menu, quick links or category shortcuts</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="menu_id" id="m_menu_id">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label-c">Name</label>
              <input type="text" class="form-control-c" name="name" id="m_name" placeholder="e.g. Events &amp; Parties" required>
            </div>
            <div class="col-12">
              <label class="form-label-c">URL</label>
              <input type="text" class="form-control-c" name="url" id="m_url" placeholder="https://…" required>
            </div>
            <div class="col-12">
              <label class="form-label-c">Place</label>
              <select class="form-control-c" name="place" id="m_place" required>
                <option value="main_menu">Main Menu</option>
                <option value="quick_link">Quick Link</option>
                <option value="category">Category</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn-mini" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-pink-sm" name="save_menu" id="menuSubmitBtn">Add Menu Item</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================= ADD / EDIT TAB MODAL ================= -->
<div class="modal fade" id="tabModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form role="form" method="POST" action="" enctype="multipart/form-data" id="tabForm">
        <div class="modal-header">
          <div>
            <h5 id="tabModalTitle">Add Tab</h5>
            <div class="cell-sub">Icon-led tab shown in the site's main navigation</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="tab_id" id="t_tab_id">
          <input type="hidden" name="old_icon" id="t_old_icon">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label-c">Name</label>
              <input type="text" class="form-control-c" name="name" id="t_name" placeholder="e.g. Events &amp; Parties" required>
            </div>
            <div class="col-12">
              <label class="form-label-c">URL</label>
              <input type="text" class="form-control-c" name="url" id="t_url" placeholder="https://…" required>
            </div>
            <div class="col-12">
              <label class="form-label-c">Icon</label>
              <input type="file" class="form-control-c" name="icon" id="t_icon" accept="image/*">
              <div id="t_icon_preview_wrap" class="mt-2 d-none">
                <img id="t_icon_preview" src="" width="48" height="48" style="object-fit:cover;border-radius:10px;border:1px solid var(--line);" alt="Current icon">
                <span class="cell-sub ms-2">Current icon — upload a new one to replace it</span>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn-mini" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-pink-sm" name="save_tab" id="tabSubmitBtn">Add Tab</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {

    /* ---------- delete confirm (menu + tabs) ---------- */
    document.querySelectorAll('.js-delete-item').forEach(function (link) {
      link.addEventListener('click', function (e) {
        if (!confirm('Delete this item? This cannot be undone.')) e.preventDefault();
      });
    });

    /* ---------- Menu modal ---------- */
    var menuModalEl  = document.getElementById('menuModal');
    var menuModal    = new bootstrap.Modal(menuModalEl);
    var menuForm     = document.getElementById('menuForm');
    var menuSubmit   = document.getElementById('menuSubmitBtn');

    function resetMenuForm() {
      menuForm.reset();
      document.getElementById('m_menu_id').value = '';
      document.getElementById('menuModalTitle').textContent = 'Add Menu Item';
      menuSubmit.name = 'save_menu';
      menuSubmit.textContent = 'Add Menu Item';
    }

    document.getElementById('openAddMenuBtn').addEventListener('click', function () {
      resetMenuForm();
      menuModal.show();
    });

    document.querySelectorAll('.js-edit-menu').forEach(function (btn) {
      btn.addEventListener('click', function () {
        resetMenuForm();
        document.getElementById('menuModalTitle').textContent = 'Edit Menu Item';
        menuSubmit.name = 'update_menu';
        menuSubmit.textContent = 'Update Menu Item';

        document.getElementById('m_menu_id').value = btn.dataset.id;
        document.getElementById('m_name').value = btn.dataset.name;
        document.getElementById('m_url').value = btn.dataset.url;
        document.getElementById('m_place').value = btn.dataset.place;

        menuModal.show();
      });
    });

    /* ---------- Tab modal ---------- */
    var tabModalEl = document.getElementById('tabModal');
    var tabModal   = new bootstrap.Modal(tabModalEl);
    var tabForm    = document.getElementById('tabForm');
    var tabSubmit  = document.getElementById('tabSubmitBtn');

    function resetTabForm() {
      tabForm.reset();
      document.getElementById('t_tab_id').value = '';
      document.getElementById('t_old_icon').value = '';
      document.getElementById('t_icon_preview_wrap').classList.add('d-none');
      document.getElementById('tabModalTitle').textContent = 'Add Tab';
      tabSubmit.name = 'save_tab';
      tabSubmit.textContent = 'Add Tab';
    }

    document.getElementById('openAddTabBtn').addEventListener('click', function () {
      resetTabForm();
      tabModal.show();
    });

    document.querySelectorAll('.js-edit-tab').forEach(function (btn) {
      btn.addEventListener('click', function () {
        resetTabForm();
        document.getElementById('tabModalTitle').textContent = 'Edit Tab';
        tabSubmit.name = 'update_tab';
        tabSubmit.textContent = 'Update Tab';

        document.getElementById('t_tab_id').value = btn.dataset.id;
        document.getElementById('t_name').value = btn.dataset.name;
        document.getElementById('t_url').value = btn.dataset.url;
        document.getElementById('t_old_icon').value = btn.dataset.icon;

        if (btn.dataset.icon) {
          document.getElementById('t_icon_preview').src = btn.dataset.icon;
          document.getElementById('t_icon_preview_wrap').classList.remove('d-none');
        }

        tabModal.show();
      });
    });

    /* ---------- flash toast ---------- */
    <?php if (isset($_GET['success'])): ?>
    showToast('Success', <?php echo json_encode($_GET['success']); ?>);
    <?php elseif (isset($_GET['error'])): ?>
    showToast('Something went wrong', <?php echo json_encode($_GET['error']); ?>);
    <?php endif; ?>
  });
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>