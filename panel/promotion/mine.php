<?php
/**
 * promotion/mine.php
 * Partner-only. "My Promotions" — purchase/activation history, own data only.
 */
require_once __DIR__ . '/../include/session_guard.php';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/promo_helpers.php';

if ($role !== 'partner') {
    header('Location: /profile');
    exit();
}

$pageTitle  = 'My Promotions';
$activePage = 'promotion';

$perPage = 15;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$totalStmt = $conn->prepare("SELECT COUNT(*) c FROM partner_promotions WHERE partner_id = ?");
$totalStmt->bind_param('i', $userId);
$totalStmt->execute();
$total = (int) ($totalStmt->get_result()->fetch_assoc()['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$stmt = $conn->prepare("
    SELECT pp.*, e.tittle AS event_title, bo.status AS order_status
    FROM partner_promotions pp
    LEFT JOIN events e ON e.id = pp.event_id
    LEFT JOIN boost_package_orders bo ON bo.id = pp.order_id
    WHERE pp.partner_id = ?
    ORDER BY pp.id DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param('iii', $userId, $perPage, $offset);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../include/header.php';
?>

<div class="page-head">
  <div>
    <h2>My Promotions</h2>
    <p><?php echo number_format($total); ?> promotion<?php echo $total === 1 ? '' : 's'; ?> purchased</p>
  </div>
  <a href="/promotion" class="btn-pink-sm"><i class="fa-solid fa-plus"></i> Buy a Promotion</a>
</div>

<div class="panel-card">
  <div class="table-responsive">
    <table class="table-clean">
      <thead>
        <tr>
          <th>Package</th>
          <th>Event</th>
          <th>Promotion Date(s)</th>
          <th>Amount</th>
          <th>Status</th>
          <th>Started</th>
          <th>Expires</th>
          <th>Purchased</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="8" class="cell-sub text-center">You haven't purchased any promotions yet.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td class="cell-title"><?php echo htmlspecialchars($r['package_name']); ?></td>
            <td class="cell-sub"><?php echo htmlspecialchars($r['event_title'] ?? '—'); ?></td>
            <td class="cell-sub"><?php echo htmlspecialchars(promo_dates_display((bool) $r['applies_to_all_dates'], $r['selected_dates'])); ?></td>
            <td>₹<?php echo htmlspecialchars($r['price']); ?></td>
            <td><span class="<?php echo promo_status_badge_class($r['status']); ?>"><?php echo ucfirst($r['status']); ?></span></td>
            <td class="cell-sub"><?php echo $r['started_at'] ? date('d M Y', strtotime($r['started_at'])) : '—'; ?></td>
            <td class="cell-sub"><?php echo $r['expires_at'] ? date('d M Y', strtotime($r['expires_at'])) : '—'; ?></td>
            <td class="cell-sub"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pager-c">
    <div class="pager-info">Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $total); ?> of <?php echo $total; ?></div>
    <div class="pager-links">
      <a class="pager-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo max(1, $page - 1); ?>"><i class="fa-solid fa-chevron-left"></i></a>
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
  .pager-link.disabled{ opacity:.4; pointer-events:none; }
</style>

<?php include __DIR__ . '/../include/footer.php'; ?>
