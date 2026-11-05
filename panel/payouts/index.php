<?php
/**
 * payouts/index.php ("Payout History")
 * Single file, branched on $role, matching the pattern already used for
 * the sidebar's /payouts entry (admin sees every partner's payouts,
 * partner sees only their own -- same convention as partner/index.php
 * being admin-only vs. profile pages branching on $role).
 *
 * Admin view: paginated table of ALL payout requests, filters, summary
 * stat cards, and row actions to move a payout through
 * pending -> processing -> paid/failed.
 *
 * Partner view: available balance card + "Request Payout" flow, and a
 * table of just their own payout history.
 *
 * Requires include/session_guard.php (sets $role, $userId) and
 * include/config.php (sets up $conn).
 */
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';          // sets up $conn (mysqli)
require_once __DIR__ . '/../include/notify.php';          // notify_user() / notify_role()
require_once __DIR__ . '/../include/payouts_helpers.php'; // shared payout logic

$pageTitle  = 'Payout History';
$activePage = 'payouts';

$settings = get_payout_settings($conn);

/* ===================================================================
   ADMIN ACTIONS: mark processing / paid / failed
   =================================================================== */
if ($role === 'admin' && isset($_POST['update_status'])) {
    $payoutId = (int) ($_POST['payout_id'] ?? 0);
    $newStatus = (string) ($_POST['status'] ?? '');
    $referenceId = trim($_POST['reference_id'] ?? '');
    $adminNotes  = trim($_POST['admin_notes'] ?? '');

    $allowed = ['processing', 'paid', 'failed'];
    if (!in_array($newStatus, $allowed, true) || $payoutId <= 0) {
        header('Location: /payouts?error=' . urlencode('Invalid status update.'));
        exit();
    }

    // Confirm the row is real before touching it.
    $stmt = $conn->prepare("SELECT * FROM payouts WHERE id = ?");
    $stmt->bind_param('i', $payoutId);
    $stmt->execute();
    $payout = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payout) {
        header('Location: /payouts?error=' . urlencode('Payout not found.'));
        exit();
    }

    if ($newStatus === 'paid' && $referenceId === '') {
        header('Location: /payouts?error=' . urlencode('A reference ID is required to mark a payout as paid.'));
        exit();
    }
    if ($newStatus === 'failed' && $adminNotes === '') {
        header('Location: /payouts?error=' . urlencode('Please add a note explaining why the payout failed.'));
        exit();
    }

    $stmt = $conn->prepare("
        UPDATE payouts
        SET status = ?, reference_id = ?, admin_notes = ?, processed_at = NOW(), processed_by = ?
        WHERE id = ?
    ");
    $refParam   = $referenceId !== '' ? $referenceId : $payout['reference_id'];
    $notesParam = $adminNotes !== '' ? $adminNotes : $payout['admin_notes'];
    $stmt->bind_param('sssii', $newStatus, $refParam, $notesParam, $userId, $payoutId);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        $statusLabel = ['processing' => 'Processing', 'paid' => 'Paid', 'failed' => 'Failed'][$newStatus];
        $msg = 'Your payout of ' . format_inr((float) $payout['amount']) . ' is now marked ' . strtolower($statusLabel) . '.';
        if ($newStatus === 'paid' && $referenceId !== '') {
            $msg .= ' Reference: ' . $referenceId . '.';
        }
        if ($newStatus === 'failed' && $adminNotes !== '') {
            $msg .= ' Reason: ' . $adminNotes;
        }
        notify_user($conn, (int) $payout['user_id'], 'payout', 'Payout ' . $statusLabel, $msg, '/payouts');
    }

    header('Location: /payouts?' . ($ok ? 'success=' . urlencode('Payout updated.') : 'error=' . urlencode($conn->error)));
    exit();
}

/* ===================================================================
   PARTNER ACTION: request a payout for a specific event
   =================================================================== */
if ($role === 'partner' && isset($_POST['request_payout'])) {
    $eligibility = partner_payout_eligibility($conn, $userId, $settings);

    $eventId = (int) ($_POST['event_id'] ?? 0);
    $requestedAmount = (float) ($_POST['amount'] ?? 0);
    $method = (string) ($_POST['method'] ?? '');

    $errors = [];
    if (!$eligibility['can_request']) {
        $errors[] = 'You are not eligible for a payout right now.';
    }
    if (!in_array($method, $eligibility['available_methods'], true)) {
        $errors[] = 'Choose a payout method you have on file.';
    }

    // Never trust a client-supplied event or amount -- recompute the
    // chosen event's balance server-side and confirm it's this partner's.
    $eventBalance = $eventId > 0 ? partner_event_balance($conn, $userId, $eventId, $settings) : null;
    if ($eventBalance === null) {
        $errors[] = 'Choose a valid event.';
    } elseif ($eventBalance < $settings['payout_minimum_amount']) {
        $errors[] = 'That event hasn\'t reached the minimum payout amount yet.';
    } elseif ($requestedAmount <= 0 || $requestedAmount > $eventBalance) {
        $errors[] = 'Enter a valid amount up to that event\'s pending balance.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO payouts (user_id, event_id, amount, method, status)
            VALUES (?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param('iids', $userId, $eventId, $requestedAmount, $method);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            $eventTitle = '';
            foreach ($eligibility['pending_events'] as $e) {
                if ($e['event_id'] === $eventId) { $eventTitle = $e['title']; break; }
            }
            notify_role(
                $conn,
                'admin',
                'payout',
                'New payout request',
                format_inr($requestedAmount) . ' payout requested for "' . $eventTitle . '" via ' . strtoupper($method) . '.',
                '/payouts'
            );
        }

        header('Location: /payouts?' . ($ok ? 'success=' . urlencode('Payout requested.') : 'error=' . urlencode($conn->error)));
        exit();
    }

    header('Location: /payouts?error=' . urlencode(implode(' ', $errors)));
    exit();
}

/* ===================================================================
   ADMIN VIEW DATA
   =================================================================== */
if ($role === 'admin') {
    $perPage = 15;
    $page = max(1, (int) ($_GET['page'] ?? 1));

    $statusFilter = $_GET['status'] ?? '';
    $searchFilter = trim($_GET['q'] ?? '');
    $fromFilter   = $_GET['from'] ?? '';
    $toFilter     = $_GET['to'] ?? '';

    $where = ['1=1'];
    $params = [];
    $types = '';

    if (in_array($statusFilter, ['pending', 'processing', 'paid', 'failed'], true)) {
        $where[] = 'py.status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }
    if ($searchFilter !== '') {
        $where[] = "(u.fname LIKE ? OR u.lname LIKE ? OR u.email LIKE ?)";
        $like = '%' . $searchFilter . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= 'sss';
    }
    if ($fromFilter !== '') {
        $where[] = 'py.requested_at >= ?';
        $params[] = $fromFilter . ' 00:00:00';
        $types .= 's';
    }
    if ($toFilter !== '') {
        $where[] = 'py.requested_at <= ?';
        $params[] = $toFilter . ' 23:59:59';
        $types .= 's';
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("
        SELECT COUNT(*) c FROM payouts py
        INNER JOIN users u ON u.id = py.user_id
        WHERE $whereSql
    ");
    if ($types !== '') { $countStmt->bind_param($types, ...$params); }
    $countStmt->execute();
    $totalRows = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $countStmt->close();

    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) { $page = $totalPages; }
    $offset = ($page - 1) * $perPage;

    $listSql = "
        SELECT py.*, u.fname, u.lname, u.email, e.tittle AS event_title
        FROM payouts py
        INNER JOIN users u ON u.id = py.user_id
        LEFT JOIN events e ON e.id = py.event_id
        WHERE $whereSql
        ORDER BY py.requested_at DESC
        LIMIT ? OFFSET ?
    ";
    $listStmt = $conn->prepare($listSql);
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    $listStmt->bind_param($listTypes, ...$listParams);
    $listStmt->execute();
    $payoutRows = $listStmt->get_result();

    // Summary stat cards.
    $statTotalPending = (float) ($conn->query(
        "SELECT COALESCE(SUM(amount),0) t FROM payouts WHERE status = 'pending'"
    )->fetch_assoc()['t'] ?? 0);

    $statPaidThisMonth = (float) ($conn->query(
        "SELECT COALESCE(SUM(amount),0) t FROM payouts
         WHERE status = 'paid' AND MONTH(processed_at) = MONTH(CURRENT_DATE())
         AND YEAR(processed_at) = YEAR(CURRENT_DATE())"
    )->fetch_assoc()['t'] ?? 0);

    $statPartnersAwaiting = (int) ($conn->query(
        "SELECT COUNT(DISTINCT user_id) c FROM payouts WHERE status IN ('pending','processing')"
    )->fetch_assoc()['c'] ?? 0);
}

/* ===================================================================
   PARTNER VIEW DATA
   =================================================================== */
if ($role === 'partner') {
    $eligibility = partner_payout_eligibility($conn, $userId, $settings);

    $perPage = 15;
    $page = max(1, (int) ($_GET['page'] ?? 1));

    $countStmt = $conn->prepare("SELECT COUNT(*) c FROM payouts WHERE user_id = ?");
    $countStmt->bind_param('i', $userId);
    $countStmt->execute();
    $totalRows = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $countStmt->close();

    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) { $page = $totalPages; }
    $offset = ($page - 1) * $perPage;

    $listStmt = $conn->prepare("
        SELECT py.*, e.tittle AS event_title
        FROM payouts py
        LEFT JOIN events e ON e.id = py.event_id
        WHERE py.user_id = ?
        ORDER BY py.requested_at DESC
        LIMIT ? OFFSET ?
    ");
    $listStmt->bind_param('iii', $userId, $perPage, $offset);
    $listStmt->execute();
    $payoutRows = $listStmt->get_result();
}

include __DIR__ . '/../include/header.php';
/* header.php has already opened <div class="content"> */
?>

<?php if ($role === 'admin'): ?>

<div class="page-head">
  <div>
    <h2>Payout History</h2>
    <p>Review and process payout requests from partners</p>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="panel-card stat-card">
      <div class="stat-label">Total Pending</div>
      <div class="stat-value"><?php echo format_inr($statTotalPending); ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="panel-card stat-card">
      <div class="stat-label">Paid This Month</div>
      <div class="stat-value"><?php echo format_inr($statPaidThisMonth); ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="panel-card stat-card">
      <div class="stat-label">Partners Awaiting Payout</div>
      <div class="stat-value"><?php echo number_format($statPartnersAwaiting); ?></div>
    </div>
  </div>
</div>

<div class="panel-card">
  <div class="head">
    <div>
      <h5>All Payout Requests</h5>
      <div class="sub">Move a request through processing to paid, or mark it failed</div>
    </div>
  </div>

  <form method="GET" action="" class="filter-bar">
    <select name="status" class="form-control-c">
      <option value="">All statuses</option>
      <?php foreach (['pending','processing','paid','failed'] as $s): ?>
        <option value="<?php echo $s; ?>" <?php echo $statusFilter === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="q" class="form-control-c" placeholder="Search partner name or email" value="<?php echo htmlspecialchars($searchFilter); ?>">
    <input type="date" name="from" class="form-control-c" value="<?php echo htmlspecialchars($fromFilter); ?>">
    <input type="date" name="to" class="form-control-c" value="<?php echo htmlspecialchars($toFilter); ?>">
    <button type="submit" class="btn-mini"><i class="fa-solid fa-filter"></i> Filter</button>
    <?php if ($statusFilter || $searchFilter || $fromFilter || $toFilter): ?>
      <a href="/payouts" class="btn-mini">Clear</a>
    <?php endif; ?>
  </form>

  <div class="table-responsive">
    <table class="table-clean">
      <thead>
        <tr>
          <th>Partner</th>
          <th>Event</th>
          <th>Amount</th>
          <th>Method</th>
          <th>Status</th>
          <th>Requested</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($payoutRows && $payoutRows->num_rows): while ($p = $payoutRows->fetch_assoc()):
          $fullName = trim(($p['fname'] ?? '') . ' ' . ($p['lname'] ?? '')) ?: '—';
          $badgeClass = [
            'pending'    => 'badge-pending',
            'processing' => 'badge-draft',
            'paid'       => 'badge-live',
            'failed'     => 'badge-past',
          ][$p['status']] ?? 'badge-pending';
        ?>
          <tr>
            <td>
              <div class="cell-title"><?php echo htmlspecialchars($fullName); ?></div>
              <div class="cell-sub"><?php echo htmlspecialchars($p['email'] ?: '—'); ?></div>
            </td>
            <td class="cell-sub"><?php echo htmlspecialchars($p['event_title'] ?: '—'); ?></td>
            <td class="cell-title"><?php echo format_inr((float) $p['amount']); ?></td>
            <td class="cell-sub"><?php echo strtoupper($p['method']); ?></td>
            <td><span class="badge-pill <?php echo $badgeClass; ?>"><?php echo ucfirst($p['status']); ?></span></td>
            <td class="cell-sub"><?php echo htmlspecialchars($p['requested_at']); ?></td>
            <td style="text-align:right;">
              <div class="d-flex justify-content-end gap-1">
                <?php if ($p['status'] === 'pending'): ?>
                  <button type="button" class="btn-mini js-payout-action"
                          data-id="<?php echo (int) $p['id']; ?>" data-status="processing" data-label="Mark Processing">
                    <i class="fa-solid fa-hourglass-half"></i> Mark Processing
                  </button>
                <?php endif; ?>
                <?php if (in_array($p['status'], ['pending', 'processing'], true)): ?>
                  <button type="button" class="btn-mini js-payout-action"
                          data-id="<?php echo (int) $p['id']; ?>" data-status="paid" data-label="Mark Paid" data-needs-ref="1">
                    <i class="fa-solid fa-check"></i> Mark Paid
                  </button>
                  <button type="button" class="btn-mini js-payout-action"
                          data-id="<?php echo (int) $p['id']; ?>" data-status="failed" data-label="Mark Failed" data-needs-notes="1"
                          style="color:var(--pink);border-color:var(--pink);">
                    <i class="fa-solid fa-xmark"></i> Mark Failed
                  </button>
                <?php endif; ?>
                <?php if (in_array($p['status'], ['paid', 'failed'], true)): ?>
                  <span class="cell-sub"><?php echo htmlspecialchars($p['reference_id'] ?: ($p['admin_notes'] ?: '—')); ?></span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="7" class="cell-sub text-center">No payout requests found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pager-c">
    <div class="pager-info">Showing page <?php echo $page; ?> of <?php echo $totalPages; ?></div>
    <div class="pager-links">
      <a class="pager-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])); ?>"><i class="fa-solid fa-chevron-left"></i></a>
      <a class="pager-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)])); ?>"><i class="fa-solid fa-chevron-right"></i></a>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ================= UPDATE STATUS MODAL ================= -->
<div class="modal fade" id="payoutActionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="">
        <div class="modal-header">
          <h5 id="payoutActionTitle">Update Payout</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="payout_id" id="pa_payout_id">
          <input type="hidden" name="status" id="pa_status">

          <div id="pa_ref_wrap" class="d-none">
            <label class="form-label-c">Reference ID</label>
            <input type="text" class="form-control-c" name="reference_id" id="pa_reference_id" placeholder="Bank/UPI transaction reference">
          </div>
          <div id="pa_notes_wrap" class="d-none">
            <label class="form-label-c">Reason</label>
            <textarea class="form-control-c" name="admin_notes" id="pa_admin_notes" rows="3" placeholder="Why did this payout fail?"></textarea>
          </div>
          <p class="cell-sub" id="pa_confirm_text"></p>
        </div>
        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn-mini" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-pink-sm" name="update_status">Confirm</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php else: /* ===================== PARTNER VIEW ===================== */ ?>

<div class="page-head">
  <div>
    <h2>Payout History</h2>
    <p>Track your payout requests and request a new payout</p>
  </div>
</div>

<div class="panel-card mb-3">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
      <div class="stat-label">Total Pending Across Events</div>
      <div class="stat-value"><?php echo format_inr($eligibility['total_pending']); ?></div>
    </div>
    <?php if ($eligibility['can_request']): ?>
      <button type="button" class="btn-pink-sm" id="requestPayoutBtn"><i class="fa-solid fa-money-bill-transfer"></i> Request Payout</button>
    <?php else: ?>
      <button type="button" class="btn-pink-sm" disabled title="<?php echo htmlspecialchars(implode(' ', $eligibility['blockers'])); ?>">
        <i class="fa-solid fa-money-bill-transfer"></i> Request Payout
      </button>
    <?php endif; ?>
  </div>

  <?php if (!$eligibility['can_request']): ?>
    <div class="payout-banner mt-3">
      <i class="fa-solid fa-circle-info"></i>
      <div>
        <?php foreach ($eligibility['blockers'] as $b): ?>
          <div><?php echo htmlspecialchars($b); ?>
            <?php if (str_contains($b, 'KYC')): ?>
              <a href="/profile/kyc">Complete KYC</a>
            <?php elseif (str_contains($b, 'bank or UPI')): ?>
              <a href="/payouts/settings">Add payout details</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($eligibility['pending_events'])): ?>
    <div class="table-responsive mt-3">
      <table class="table-clean">
        <thead>
          <tr>
            <th>Event</th>
            <th>Pending Amount</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($eligibility['pending_events'] as $e): ?>
            <tr>
              <td class="cell-title"><?php echo htmlspecialchars($e['title']); ?></td>
              <td class="cell-sub"><?php echo format_inr($e['balance']); ?></td>
              <td class="cell-sub" style="text-align:right;">
                <?php if (!$e['meets_minimum']): ?>
                  <span class="cell-sub">Below <?php echo format_inr($settings['payout_minimum_amount']); ?> minimum</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="panel-card">
  <div class="head">
    <div>
      <h5>Your Payout Requests</h5>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table-clean">
      <thead>
        <tr>
          <th>Event</th>
          <th>Amount</th>
          <th>Method</th>
          <th>Status</th>
          <th>Requested</th>
          <th>Reference / Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($payoutRows && $payoutRows->num_rows): while ($p = $payoutRows->fetch_assoc()):
          $badgeClass = [
            'pending'    => 'badge-pending',
            'processing' => 'badge-draft',
            'paid'       => 'badge-live',
            'failed'     => 'badge-past',
          ][$p['status']] ?? 'badge-pending';
        ?>
          <tr>
            <td class="cell-sub"><?php echo htmlspecialchars($p['event_title'] ?: '—'); ?></td>
            <td class="cell-title"><?php echo format_inr((float) $p['amount']); ?></td>
            <td class="cell-sub"><?php echo strtoupper($p['method']); ?></td>
            <td><span class="badge-pill <?php echo $badgeClass; ?>"><?php echo ucfirst($p['status']); ?></span></td>
            <td class="cell-sub"><?php echo htmlspecialchars($p['requested_at']); ?></td>
            <td class="cell-sub"><?php echo htmlspecialchars($p['reference_id'] ?: ($p['admin_notes'] ?: '—')); ?></td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="6" class="cell-sub text-center">No payout requests yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pager-c">
    <div class="pager-info">Showing page <?php echo $page; ?> of <?php echo $totalPages; ?></div>
    <div class="pager-links">
      <a class="pager-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo max(1, $page - 1); ?>"><i class="fa-solid fa-chevron-left"></i></a>
      <a class="pager-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="?page=<?php echo min($totalPages, $page + 1); ?>"><i class="fa-solid fa-chevron-right"></i></a>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ================= REQUEST PAYOUT MODAL ================= -->
<div class="modal fade" id="requestPayoutModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="" id="requestPayoutForm">
        <div class="modal-header">
          <div>
            <h5>Request Payout</h5>
            <div class="cell-sub">Choose which event's pending money you want paid out</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <label class="form-label-c">Event</label>
          <div class="event-pick-list">
            <?php foreach ($eligibility['requestable_events'] as $i => $e): ?>
              <label class="event-pick-row">
                <input type="radio" name="event_id" value="<?php echo (int) $e['event_id']; ?>"
                       data-balance="<?php echo htmlspecialchars((string) $e['balance']); ?>"
                       <?php echo $i === 0 ? 'checked' : ''; ?> required>
                <div>
                  <div class="cell-title"><?php echo htmlspecialchars($e['title']); ?></div>
                  <div class="cell-sub">Pending: <?php echo format_inr($e['balance']); ?></div>
                </div>
              </label>
            <?php endforeach; ?>
          </div>

          <label class="form-label-c mt-3">Amount</label>
          <input type="number" step="0.01" min="0.01" class="form-control-c" name="amount" id="rp_amount" required>
          <div class="cell-sub mt-1">Up to the pending amount for the event selected above</div>

          <label class="form-label-c mt-3">Payout Method</label>
          <select class="form-control-c" name="method" required>
            <?php foreach ($eligibility['available_methods'] as $m): ?>
              <option value="<?php echo $m; ?>"><?php echo strtoupper($m); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn-mini" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-pink-sm" name="request_payout">Submit Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

<style>
  .event-pick-list{ display:flex; flex-direction:column; gap:.5rem; max-height:220px; overflow-y:auto; }
  .event-pick-row{ display:flex; align-items:center; gap:.7rem; background:var(--panel-2); border:1px solid var(--line); border-radius:10px; padding:.6rem .8rem; cursor:pointer; }
  .event-pick-row:has(input:checked){ border-color:var(--pink); }
  .event-pick-row input{ margin:0; }
  .stat-card{ padding:1.1rem 1.3rem; }
  .stat-label{ font-size:.72rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--muted-2); margin-bottom:.35rem; }
  .stat-value{ font-size:1.5rem; font-weight:800; color:var(--text); }
  .filter-bar{ display:flex; flex-wrap:wrap; gap:.6rem; margin-bottom:1.1rem; }
  .filter-bar .form-control-c{ width:auto; min-width:160px; }
  .payout-banner{ display:flex; gap:.6rem; align-items:flex-start; background:var(--panel-2); border:1px solid var(--line); border-radius:10px; padding:.8rem 1rem; font-size:.82rem; color:var(--muted); }
  .payout-banner i{ color:var(--pink); margin-top:.15rem; }
  .payout-banner a{ color:var(--pink); font-weight:700; margin-left:.3rem; }
  .pager-c{ display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.8rem; padding-top:1.1rem; margin-top:1.1rem; border-top:1px solid var(--line); }
  .pager-info{ font-size:.78rem; color:var(--muted-2); font-weight:600; }
  .pager-links{ display:flex; align-items:center; gap:.35rem; }
  .pager-link{ min-width:34px; height:34px; padding:0 .55rem; border-radius:8px; border:1px solid var(--line); background:var(--panel-2); color:var(--muted); font-size:.8rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
  .pager-link:hover{ border-color:var(--pink); color:var(--text); }
  .pager-link.disabled{ opacity:.4; pointer-events:none; }
</style>

<?php if ($role === 'admin'): ?>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('payoutActionModal');
    var modal = new bootstrap.Modal(modalEl);

    document.querySelectorAll('.js-payout-action').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.getElementById('pa_payout_id').value = btn.dataset.id;
        document.getElementById('pa_status').value = btn.dataset.status;
        document.getElementById('payoutActionTitle').textContent = btn.dataset.label;
        document.getElementById('pa_confirm_text').textContent =
          'This will notify the partner that their payout is now "' + btn.dataset.status + '".';

        document.getElementById('pa_ref_wrap').classList.toggle('d-none', !btn.dataset.needsRef);
        document.getElementById('pa_notes_wrap').classList.toggle('d-none', !btn.dataset.needsNotes);
        document.getElementById('pa_reference_id').value = '';
        document.getElementById('pa_admin_notes').value = '';

        modal.show();
      });
    });

    <?php if (isset($_GET['success'])): ?>
    showToast('Success', <?php echo json_encode($_GET['success']); ?>);
    <?php elseif (isset($_GET['error'])): ?>
    showToast('Something went wrong', <?php echo json_encode($_GET['error']); ?>);
    <?php endif; ?>
  });
</script>
<?php else: ?>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('requestPayoutBtn');
    var amountInput = document.getElementById('rp_amount');

    function syncAmountToSelectedEvent() {
      var checked = document.querySelector('#requestPayoutForm input[name="event_id"]:checked');
      if (!checked || !amountInput) return;
      var balance = parseFloat(checked.dataset.balance || '0');
      amountInput.max = balance.toFixed(2);
      amountInput.value = balance.toFixed(2);
    }

    document.querySelectorAll('#requestPayoutForm input[name="event_id"]').forEach(function (radio) {
      radio.addEventListener('change', syncAmountToSelectedEvent);
    });

    if (btn) {
      var modal = new bootstrap.Modal(document.getElementById('requestPayoutModal'));
      btn.addEventListener('click', function () {
        syncAmountToSelectedEvent();
        modal.show();
      });
    }

    <?php if (isset($_GET['success'])): ?>
    showToast('Success', <?php echo json_encode($_GET['success']); ?>);
    <?php elseif (isset($_GET['error'])): ?>
    showToast('Something went wrong', <?php echo json_encode($_GET['error']); ?>);
    <?php endif; ?>
  });
</script>
<?php endif; ?>

<?php include __DIR__ . '/../include/footer.php'; ?>