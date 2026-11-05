<?php
/**
 * promotion/payment-failed.php
 * Partner-only. Shown when Razorpay checkout is dismissed/fails, or
 * server-side verification did not pass. The order stays failed/pending
 * and the partner can retry — no promotion is ever created here.
 */
require_once __DIR__ . '/../include/session_guard.php';
require_once __DIR__ . '/../include/config.php';

if ($role !== 'partner') {
    header('Location: /profile');
    exit();
}

$orderId = (int) ($_GET['order_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM boost_package_orders WHERE id = ? AND partnerid = ? LIMIT 1");
$stmt->bind_param('ii', $orderId, $userId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

$pageTitle  = 'Payment Failed';
$activePage = 'promotion';
include __DIR__ . '/../include/header.php';
?>

<div class="panel-card" style="max-width:560px;margin:2rem auto;text-align:center;padding:2.5rem 1.5rem;">
  <div style="font-size:3rem;color:var(--pink);margin-bottom:1rem;"><i class="fa-solid fa-circle-xmark"></i></div>
  <h4>Payment wasn't completed</h4>
  <p class="cell-sub">
    <?php echo $order ? 'Your payment for <b>' . htmlspecialchars($order['package_name']) . '</b> could not be completed.' : 'This payment could not be completed.'; ?>
    No promotion has been created and you have not been charged for this attempt.
  </p>

  <div class="d-flex gap-2 justify-content-center" style="margin-top:1.2rem;">
    <a href="/promotion" class="btn-pink-sm">Try Again</a>
    <a href="/promotion/mine" class="btn-mini">My Promotions</a>
  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
