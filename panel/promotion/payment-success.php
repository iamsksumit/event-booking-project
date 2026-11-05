<?php
/**
 * promotion/payment-success.php
 * Partner-only. Confirmation screen after a verified payment.
 */
require_once __DIR__ . '/../include/session_guard.php';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/promo_helpers.php';

if ($role !== 'partner') {
    header('Location: /profile');
    exit();
}

$orderId = (int) ($_GET['order_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM boost_package_orders WHERE id = ? AND partnerid = ? LIMIT 1");
$stmt->bind_param('ii', $orderId, $userId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    header('Location: /promotion?error=' . urlencode('Order not found.'));
    exit();
}

$promotion = $conn->query("SELECT * FROM partner_promotions WHERE order_id = " . (int) $orderId . " LIMIT 1")->fetch_assoc();

$pageTitle  = 'Promotion Purchased';
$activePage = 'promotion';
include __DIR__ . '/../include/header.php';
?>

<div class="panel-card" style="max-width:560px;margin:2rem auto;text-align:center;padding:2.5rem 1.5rem;">
  <?php if ($order['status'] === 'paid' && $promotion): ?>
    <div style="font-size:3rem;color:var(--green,#20c997);margin-bottom:1rem;"><i class="fa-solid fa-circle-check"></i></div>
    <h4>Promotion purchased!</h4>
    <p class="cell-sub">Your <b><?php echo htmlspecialchars($order['package_name']); ?></b> promotion is now active.</p>

    <div class="doc-row"><span>Promotion Date(s)</span><b><?php echo htmlspecialchars(promo_dates_display((bool) $promotion['applies_to_all_dates'], $promotion['selected_dates'])); ?></b></div>
    <div class="doc-row"><span>Started</span><b><?php echo date('d M Y', strtotime($promotion['started_at'])); ?></b></div>
    <div class="doc-row"><span>Expires</span><b><?php echo $promotion['expires_at'] ? date('d M Y', strtotime($promotion['expires_at'])) : '—'; ?></b></div>

    <a href="/promotion/mine" class="btn-pink-sm" style="margin-top:1.2rem;display:inline-block;">View My Promotions</a>
  <?php else: ?>
    <div style="font-size:3rem;color:var(--yellow,#ffc107);margin-bottom:1rem;"><i class="fa-solid fa-hourglass-half"></i></div>
    <h4>Payment is being confirmed</h4>
    <p class="cell-sub">This can take a moment. Check "My Promotions" shortly — you'll also get a notification once it's active.</p>
    <a href="/promotion/mine" class="btn-mini" style="margin-top:1rem;display:inline-block;">Go to My Promotions</a>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
