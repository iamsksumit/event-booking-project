<?php
/**
 * promotion/orders.php
 * Admin-only. All promotion purchases across every partner, with filters.
 */
require_once __DIR__ . '/../include/session_guard.php';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/promo_helpers.php';
require_once __DIR__ . '/../include/notify.php';

if ($role !== 'admin') {
    header('Location: /profile');
    exit();
}

/* ---- cancel an active promotion ---- */
if (isset($_GET['action']) && $_GET['action'] === 'cancel' && isset($_GET['id'])) {
    $promoId = (int) $_GET['id'];
    $stmt = $conn->prepare("SELECT partner_id, package_name FROM partner_promotions WHERE id = ? AND status = 'active'");
    $stmt->bind_param('i', $promoId);
    $stmt->execute();
    $promo = $stmt->get_result()->fetch_assoc();

    if ($promo) {
        $cancelStmt = $conn->prepare("UPDATE partner_promotions SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND status = 'active'");
        $cancelStmt->bind_param('i', $promoId);
        $cancelStmt->execute();
        notify_user($conn, (int) $promo['partner_id'], 'promotion', 'Promotion Cancelled',
            'Your "' . $promo['package_name'] . '" promotion has been cancelled by an admin.', '/promotion/mine');
    }
    header('Location: /promotion/orders.php?success=' . urlencode('Promotion cancelled.'));
    exit();
}

/* ---- reactivate a cancelled promotion ---- */
if (isset($_GET['action']) && $_GET['action'] === 'reactivate' && isset($_GET['id'])) {
    $promoId = (int) $_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM partner_promotions WHERE id = ? AND status = 'cancelled'");
    $stmt->bind_param('i', $promoId);
    $stmt->execute();
    $promo = $stmt->get_result()->fetch_assoc();

    if (!$promo) {
        header('Location: /promotion/orders.php?error=' . urlencode('This promotion cannot be reactivated.'));
        exit();
    }
    if ($promo['expires_at'] && strtotime($promo['expires_at']) < time()) {
        header('Location: /promotion/orders.php?error=' . urlencode('This promotion already expired — grant a new one instead.'));
        exit();
    }

    $reStmt = $conn->prepare("UPDATE partner_promotions SET status = 'active', updated_at = NOW() WHERE id = ? AND status = 'cancelled'");
    $reStmt->bind_param('i', $promoId);
    $reStmt->execute();
    notify_user($conn, (int) $promo['partner_id'], 'promotion', 'Promotion Reactivated',
        'Your "' . $promo['package_name'] . '" promotion is active again.', '/promotion/mine');

    header('Location: /promotion/orders.php?success=' . urlencode('Promotion reactivated.'));
    exit();
}

$pageTitle  = 'Promotion Orders';
$activePage = 'promotion';

$fPartner = trim($_GET['partner'] ?? '');
$fPackage = trim($_GET['package'] ?? '');
$fStatus  = trim($_GET['status'] ?? '');
$fFrom    = trim($_GET['from'] ?? '');
$fTo      = trim($_GET['to'] ?? '');

$where  = ['1=1'];
$types  = '';
$params = [];

if ($fPartner !== '') { $where[] = "(u.fname LIKE ? OR u.lname LIKE ? OR u.email LIKE ?)"; $like = "%$fPartner%"; $types .= 'sss'; $params[] = $like; $params[] = $like; $params[] = $like; }
if ($fPackage !== '') { $where[] = "pp.package_name LIKE ?"; $types .= 's'; $params[] = "%$fPackage%"; }
if ($fStatus !== '')  { $where[] = "pp.status = ?"; $types .= 's'; $params[] = $fStatus; }
if ($fFrom !== '')    { $where[] = "DATE(pp.created_at) >= ?"; $types .= 's'; $params[] = $fFrom; }
if ($fTo !== '')      { $where[] = "DATE(pp.created_at) <= ?"; $types .= 's'; $params[] = $fTo; }

$whereSql = implode(' AND ', $where);

$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countStmt = $conn->prepare("SELECT COUNT(*) c FROM partner_promotions pp LEFT JOIN users u ON u.id = pp.partner_id WHERE $whereSql");
if ($types !== '') $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$sql = "
    SELECT pp.*, u.fname, u.lname, u.email, e.tittle AS event_title, bo.razorpay_payment_id
    FROM partner_promotions pp
    LEFT JOIN users u ON u.id = pp.partner_id
    LEFT JOIN events e ON e.id = pp.event_id
    LEFT JOIN boost_package_orders bo ON bo.id = pp.order_id
    WHERE $whereSql
    ORDER BY pp.id DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
$allTypes = $types . 'ii';
$allParams = array_merge($params, [$perPage, $offset]);
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../include/header.php';
?>

<div class="page-head">
  <div>
    <h2>Promotion Orders</h2>
    <p><?php echo number_format($total); ?> promotion purchase<?php echo $total === 1 ? '' : 's'; ?> across all partners</p>
  </div>
  <a href="/promotion" class="btn-mini"><i class="fa-solid fa-arrow-left"></i> Back to Plans</a>
</div>

<div class="panel-card" style="margin-bottom:1rem;">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label-c">Partner</label>
      <input type="text" name="partner" class="form-control-c" value="<?php echo htmlspecialchars($fPartner); ?>" placeholder="Name or email">
    </div>
    <div class="col-md-2">
      <label class="form-label-c">Package</label>
      <input type="text" name="package" class="form-control-c" value="<?php echo htmlspecialchars($fPackage); ?>" placeholder="Package name">
    </div>
    <div class="col-md-2">
      <label class="form-label-c">Status</label>
      <select name="status" class="form-control-c">
        <option value="">All</option>
        <?php foreach (['pending','active','expired','cancelled','failed'] as $s): ?>
          <option value="<?php echo $s; ?>" <?php echo $fStatus === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label-c">From</label>
      <input type="date" name="from" class="form-control-c" value="<?php echo htmlspecialchars($fFrom); ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label-c">To</label>
      <input type="date" name="to" class="form-control-c" value="<?php echo htmlspecialchars($fTo); ?>">
    </div>
    <div class="col-md-1">
      <button type="submit" class="btn-pink-sm" style="width:100%;">Filter</button>
    </div>
  </form>
</div>

<div class="panel-card">
  <div class="table-responsive">
    <table class="table-clean">
      <thead>
        <tr>
          <th>Partner</th><th>Package</th><th>Event</th><th>Promotion Date(s)</th>
          <th>Amount</th><th>Status</th><th>Razorpay Payment</th><th>Purchased</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="9" class="cell-sub text-center">No promotion orders match these filters.</td></tr>
        <?php else: foreach ($rows as $r):
          $partnerName = trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? '')) ?: ('Partner #' . $r['partner_id']);
        ?>
          <tr>
            <td class="cell-title"><?php echo htmlspecialchars($partnerName); ?><div class="cell-sub"><?php echo htmlspecialchars($r['email'] ?? ''); ?></div></td>
            <td><?php echo htmlspecialchars($r['package_name']); ?></td>
            <td class="cell-sub"><?php echo htmlspecialchars($r['event_title'] ?? '—'); ?></td>
            <td class="cell-sub"><?php echo htmlspecialchars(promo_dates_display((bool) $r['applies_to_all_dates'], $r['selected_dates'])); ?></td>
            <td>₹<?php echo htmlspecialchars($r['price']); ?></td>
            <td><span class="<?php echo promo_status_badge_class($r['status']); ?>"><?php echo ucfirst($r['status']); ?></span></td>
            <td class="cell-sub"><?php echo htmlspecialchars($r['razorpay_payment_id'] ?? '—'); ?></td>
            <td class="cell-sub"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
            <td style="text-align:right;">
              <?php if ($r['status'] === 'active'): ?>
                <a href="?action=cancel&id=<?php echo (int) $r['id']; ?>&<?php echo http_build_query($_GET); ?>"
                   class="btn-mini js-confirm"
                   data-confirm-title="Cancel this active promotion?"
                   data-confirm-body="The partner's promotion will stop running immediately."
                   style="color:var(--pink);border-color:var(--pink);">Cancel</a>
              <?php elseif ($r['status'] === 'cancelled'): ?>
                <a href="?action=reactivate&id=<?php echo (int) $r['id']; ?>&<?php echo http_build_query($_GET); ?>"
                   class="btn-mini js-confirm"
                   data-confirm-title="Reactivate this promotion?"
                   data-confirm-body="It will start showing as active again for the partner.">Reactivate</a>
              <?php else: ?>
                <span class="cell-sub">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php promo_render_pagination($page, $totalPages, $offset, $perPage, $total, $_GET); ?>
</div>

<?php include __DIR__ . '/../include/confirm-modal.php'; ?>
<?php include __DIR__ . '/../include/footer.php'; ?>