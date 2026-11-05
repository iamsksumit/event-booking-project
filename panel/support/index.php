<?php
/**
 * support/index.php ("Support Tickets")
 * Admin + partner support desk, built on the existing `support_tickets` /
 * `support_ticket_replies` tables (see support_tickets_migration.sql for
 * the two small additions this page needs: an `attachment` column on each
 * table, plus a couple of indexes).
 *
 *   - PARTNER: sees only their own tickets, and gets a "New Ticket" form
 *     (subject, WYSIWYG message, optional "which of my events is this
 *     about", priority, optional attachment).
 *   - ADMIN: sees every partner's tickets, filterable by status, with the
 *     requesting partner's name shown on each row.
 *
 * Replying, changing status, and the full thread view live in view.php --
 * this page is just the create form + the list.
 *
 * Lives at support/index.php -- same one-level-deep folder pattern as
 * partner/index.php, place/index.php, analytics/index.php -- so include/
 * is reached via ../include/, and uploaded files are written to
 * ../uploads/support/ (ADAPT THIS if your app serves uploads from a
 * different folder -- see the "images/events/..." convention already used
 * elsewhere in this app for what your real path should look like).
 *
 * Requires include/session_guard.php (sets $role, $userId) and
 * include/config.php (sets up $conn).
 *
 *   >>> ASSUMPTION -- same as analytics/index.php: partner users come
 *   through with $role === 'partner' and $userId equal to their row in
 *   `users`. Update the two lines marked "ADAPT THIS" if that's wrong.
 */
require_once __DIR__ . '/../include/session_guard.php';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/notify.php'; // notify_user / notify_role
require_once __DIR__ . '/_helpers.php'; // ticketStatusBadge, ticketPriorityBadge, sanitizeTicketHtml, handleTicketUpload

$isAdmin   = ($role === 'admin');
$isPartner = ($role === 'partner'); // ADAPT THIS if your partner role string differs

if (!$isAdmin && !$isPartner) {
    header('Location: /profile');
    exit();
}

$pageTitle  = 'Support Tickets';
$activePage = 'support';

$currentUserId = (int) $userId; // ADAPT THIS if the logged-in id lives in a different session var

$formError   = null;
$formSuccess = null;

/* ===================== create ticket (partner only) ===================== */
if ($isPartner && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_ticket') {
    $subject = trim($_POST['subject'] ?? '');
    $message = sanitizeTicketHtml($_POST['message'] ?? '');
    $priority = in_array($_POST['priority'] ?? '', ['low', 'normal', 'high'], true) ? $_POST['priority'] : 'normal';

    $relatedEventId = null;
    if (($_POST['topic'] ?? '') === 'event' && !empty($_POST['related_event_id'])) {
        $candidateId = (int) $_POST['related_event_id'];
        // Confirm the event actually belongs to this partner before trusting it.
        $chk = $conn->prepare("SELECT id FROM events WHERE id = ? AND user_id = ?");
        $chk->bind_param('is', $candidateId, $currentUserId);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $relatedEventId = $candidateId;
        }
    }

    if ($subject === '' || strip_tags($message) === '') {
        $formError = 'Please fill in a subject and a description.';
    } else {
        try {
            $attachment = handleTicketUpload('attachment');
            $stmt = $conn->prepare("
                INSERT INTO support_tickets (user_id, subject, message, attachment, related_event_id, priority, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'open', NOW(), NOW())
            ");
            $stmt->bind_param('isssis', $currentUserId, $subject, $message, $attachment, $relatedEventId, $priority);
            $stmt->execute();
            $newTicketId = $conn->insert_id;

            $nameStmt = $conn->prepare("
                SELECT u.fname, u.lname, k.business_name
                FROM users u LEFT JOIN partner_kyc k ON k.user_id = u.id
                WHERE u.id = ?
            ");
            $nameStmt->bind_param('i', $currentUserId);
            $nameStmt->execute();
            $requester = $nameStmt->get_result()->fetch_assoc() ?: [];
            $requesterLabel = !empty($requester['business_name'])
                ? $requester['business_name']
                : (trim(($requester['fname'] ?? '') . ' ' . ($requester['lname'] ?? '')) ?: 'A partner');

            notify_role(
                $conn,
                'admin',
                'support_ticket',
                'New support ticket',
                $requesterLabel . ' opened a ' . $priority . '-priority ticket: "' . $subject . '"',
                '/support/view.php?id=' . $newTicketId
            );

            header('Location: view.php?id=' . $newTicketId);
            exit();
        } catch (RuntimeException $e) {
            $formError = $e->getMessage();
        }
    }
}

/* Partner's own events, for the "is this about a specific event" dropdown. */
$myEvents = [];
if ($isPartner) {
    $stmt = $conn->prepare("SELECT id, tittle FROM events WHERE user_id = ? ORDER BY start DESC LIMIT 200");
    $stmt->bind_param('s', $currentUserId);
    $stmt->execute();
    $myEvents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/* ===================== ticket list ===================== */
$allowedStatuses = ['all', 'open', 'pending', 'resolved', 'closed'];
$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, $allowedStatuses, true)) { $statusFilter = 'all'; }

$perPage = 15;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];
$types  = '';

if (!$isAdmin) {
    $where[]  = 't.user_id = ?';
    $params[] = $currentUserId;
    $types   .= 'i';
}
if ($statusFilter !== 'all') {
    $where[]  = 't.status = ?';
    $params[] = $statusFilter;
    $types   .= 's';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $conn->prepare("SELECT COUNT(*) c FROM support_tickets t $whereSql");
if ($types) { $countStmt->bind_param($types, ...$params); }
$countStmt->execute();
$totalTickets = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
$totalPages   = max(1, (int) ceil($totalTickets / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$listSql = "
    SELECT t.id, t.subject, t.status, t.priority, t.related_event_id, t.created_at, t.updated_at,
           u.fname, u.lname, k.business_name,
           e.tittle AS event_title,
           (SELECT COUNT(*) FROM support_ticket_replies r WHERE r.ticket_id = t.id) AS reply_count
    FROM support_tickets t
    LEFT JOIN users u ON u.id = t.user_id
    LEFT JOIN partner_kyc k ON k.user_id = u.id
    LEFT JOIN events e ON e.id = t.related_event_id
    $whereSql
    ORDER BY t.updated_at DESC
    LIMIT ? OFFSET ?
";
$listStmt = $conn->prepare($listSql);
$allParams = $params;
$allTypes  = $types . 'ii';
$allParams[] = $perPage;
$allParams[] = $offset;
$listStmt->bind_param($allTypes, ...$allParams);
$listStmt->execute();
$tickets = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* Status counts for the filter tabs. */
$countsWhereSql = $isAdmin ? '' : 'WHERE user_id = ?';
$countsStmt = $conn->prepare("SELECT status, COUNT(*) c FROM support_tickets $countsWhereSql GROUP BY status");
if (!$isAdmin) { $countsStmt->bind_param('i', $currentUserId); }
$countsStmt->execute();
$statusCounts = ['open' => 0, 'pending' => 0, 'resolved' => 0, 'closed' => 0];
foreach ($countsStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    if (isset($statusCounts[$row['status']])) { $statusCounts[$row['status']] = (int) $row['c']; }
}
$totalAllStatuses = array_sum($statusCounts);

include __DIR__ . '/../include/header.php';
?>

<div class="page-head analytics-head">
  <div>
    <h2>Support Tickets</h2>
    <p><?php echo $isAdmin ? 'All partner support requests' : 'Your support requests'; ?></p>
  </div>
  <?php if ($isPartner): ?>
    <button type="button" class="btn btn-c-primary" onclick="document.getElementById('newTicketPanel').classList.toggle('open')">
      <i class="fa-solid fa-plus"></i> New Ticket
    </button>
  <?php endif; ?>
</div>

<?php if ($isPartner): ?>
<!-- ================= NEW TICKET FORM ================= -->
<div class="panel-card" id="newTicketPanel">
  <div class="head"><div><h5>New Support Ticket</h5></div></div>

  <?php if ($formError): ?>
    <div class="alert-c alert-danger"><?php echo htmlspecialchars($formError); ?></div>
  <?php endif; ?>

  <form method="POST" action="" enctype="multipart/form-data" class="ticket-form">
    <input type="hidden" name="action" value="create_ticket">

    <div class="form-row">
      <label>Subject</label>
      <input type="text" name="subject" class="form-control-c" maxlength="255" required value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>">
    </div>

    <div class="form-row">
      <label>Is this about a specific event?</label>
      <div class="topic-toggle">
        <label><input type="radio" name="topic" value="other" checked onchange="toggleEventSelect(this)"> No, something else</label>
        <label><input type="radio" name="topic" value="event" onchange="toggleEventSelect(this)"> Yes, one of my events</label>
      </div>
      <select name="related_event_id" class="form-control-c" id="relatedEventSelect" style="display:none; margin-top:.5rem;">
        <option value="">Select an event…</option>
        <?php foreach ($myEvents as $ev): ?>
          <option value="<?php echo (int) $ev['id']; ?>"><?php echo htmlspecialchars($ev['tittle']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-row">
      <label>Priority</label>
      <select name="priority" class="form-control-c" style="max-width:200px;">
        <option value="low">Low</option>
        <option value="normal" selected>Normal</option>
        <option value="high">High</option>
      </select>
    </div>

    <div class="form-row">
      <label>Description</label>
      <div id="editor-new" class="wysiwyg-editor"></div>
      <textarea name="message" id="message-new" style="display:none;"></textarea>
    </div>

    <div class="form-row">
      <label>Attachment (optional -- image, PDF, or document, up to 8MB)</label>
      <input type="file" name="attachment" class="form-control-c" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">
    </div>

    <button type="submit" class="btn btn-c-primary" onclick="return syncEditor('editor-new','message-new')">Submit Ticket</button>
  </form>
</div>
<?php endif; ?>

<!-- ================= FILTER TABS ================= -->
<div class="status-tabs">
  <a class="status-tab <?php echo $statusFilter === 'all' ? 'active' : ''; ?>" href="?status=all">All <span><?php echo $totalAllStatuses; ?></span></a>
  <?php foreach (['open', 'pending', 'resolved', 'closed'] as $sv):
    [$sl] = ticketStatusBadge($sv);
  ?>
    <a class="status-tab <?php echo $statusFilter === $sv ? 'active' : ''; ?>" href="?status=<?php echo $sv; ?>"><?php echo $sl; ?> <span><?php echo $statusCounts[$sv]; ?></span></a>
  <?php endforeach; ?>
</div>

<!-- ================= TICKET LIST ================= -->
<div class="panel-card">
  <div class="table-responsive">
    <table class="table-clean">
      <thead>
        <tr>
          <th>Subject</th>
          <?php if ($isAdmin): ?><th>Partner</th><?php endif; ?>
          <th>Related Event</th>
          <th>Priority</th>
          <th>Status</th>
          <th style="text-align:right;">Replies</th>
          <th>Updated</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($tickets): foreach ($tickets as $t):
          [$sLabel, $sClass] = ticketStatusBadge($t['status']);
          [$pLabel, $pClass] = ticketPriorityBadge($t['priority']);
          $partnerLabel = trim(($t['fname'] ?? '') . ' ' . ($t['lname'] ?? '')) ?: '—';
          if (!empty($t['business_name'])) { $partnerLabel = $t['business_name']; }
        ?>
          <tr onclick="window.location='view.php?id=<?php echo (int) $t['id']; ?>'" style="cursor:pointer;">
            <td class="cell-title">#<?php echo (int) $t['id']; ?> — <?php echo htmlspecialchars($t['subject']); ?></td>
            <?php if ($isAdmin): ?><td class="cell-sub"><?php echo htmlspecialchars($partnerLabel); ?></td><?php endif; ?>
            <td class="cell-sub"><?php echo $t['event_title'] ? htmlspecialchars($t['event_title']) : 'General'; ?></td>
            <td><span class="badge <?php echo $pClass; ?> light"><?php echo $pLabel; ?></span></td>
            <td><span class="badge <?php echo $sClass; ?> light"><?php echo $sLabel; ?></span></td>
            <td style="text-align:right;" class="cell-sub"><?php echo (int) $t['reply_count']; ?></td>
            <td class="cell-sub"><?php echo htmlspecialchars(date('d M Y, g:i A', strtotime($t['updated_at']))); ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="<?php echo $isAdmin ? 7 : 6; ?>" class="cell-sub text-center">No tickets found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pager-c">
    <div class="pager-info">Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $totalTickets); ?> of <?php echo $totalTickets; ?></div>
    <div class="pager-links">
      <a class="pager-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?status=<?php echo $statusFilter; ?>&page=<?php echo max(1, $page - 1); ?>"><i class="fa-solid fa-chevron-left"></i></a>
      <?php for ($p2 = max(1, $page - 2); $p2 <= min($totalPages, $page + 2); $p2++): ?>
        <a class="pager-link <?php echo $p2 === $page ? 'active' : ''; ?>" href="?status=<?php echo $statusFilter; ?>&page=<?php echo $p2; ?>"><?php echo $p2; ?></a>
      <?php endfor; ?>
      <a class="pager-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="?status=<?php echo $statusFilter; ?>&page=<?php echo min($totalPages, $page + 1); ?>"><i class="fa-solid fa-chevron-right"></i></a>
    </div>
  </div>
  <?php endif; ?>
</div>

<link href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.6/quill.snow.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.6/quill.min.js"></script>

<style>
  .analytics-head{ display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem; }

  #newTicketPanel{ display:none; margin-bottom:1.1rem; }
  #newTicketPanel.open{ display:block; }

  .ticket-form .form-row{ margin-bottom:1rem; }
  .ticket-form label{ display:block; font-size:.78rem; font-weight:700; color:var(--muted-2); margin-bottom:.35rem; }
  .topic-toggle{ display:flex; gap:1.2rem; flex-wrap:wrap; font-size:.85rem; color:var(--text); }
  .topic-toggle label{ display:inline-flex; align-items:center; gap:.4rem; font-weight:500; margin:0; }

  /* ---- Inputs / selects (scoped to this page's forms) ---- */
  .ticket-form input.form-control-c,
  .ticket-form select.form-control-c,
  .ticket-form textarea.form-control-c{
    width:100%; background:var(--panel-2); border:1px solid var(--line); color:var(--text);
    border-radius:8px; padding:.6rem .8rem; font-size:.85rem; transition:border-color .15s, box-shadow .15s;
  }
  .ticket-form input.form-control-c::placeholder{ color:var(--muted-2); }
  .ticket-form input.form-control-c:focus,
  .ticket-form select.form-control-c:focus{
    outline:none; border-color:var(--pink); box-shadow:0 0 0 3px rgba(255,64,129,.18);
  }
  .ticket-form select.form-control-c{
    -webkit-appearance:none; appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23888'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right .9rem center; padding-right:2.2rem;
  }
  .ticket-form input[type="file"].form-control-c{
    padding:.45rem .6rem; color:var(--muted-2); font-size:.8rem;
  }
  .ticket-form input[type="file"].form-control-c::file-selector-button{
    background:var(--pink); color:#fff; border:none; border-radius:6px;
    padding:.45rem .9rem; font-size:.78rem; font-weight:700; margin-right:.8rem; cursor:pointer;
    transition:opacity .15s;
  }
  .ticket-form input[type="file"].form-control-c::file-selector-button:hover{ opacity:.9; }

  .wysiwyg-editor{ background:#fff; border:1px solid var(--line); border-radius:8px; min-height:160px; color:#111; }
  .ql-toolbar.ql-snow{ border-radius:8px 8px 0 0; background:#f4f4f6; flex-wrap:wrap; }
  .ql-container.ql-snow{ border-radius:0 0 8px 8px; min-height:130px; }

  /* ---- Buttons ---- */
  .btn-c-primary{
    display:inline-flex; align-items:center; justify-content:center; gap:.5rem;
    background:var(--pink); color:#fff; border:none; border-radius:9px;
    padding:.65rem 1.3rem; font-size:.85rem; font-weight:700; cursor:pointer;
    transition:opacity .15s, transform .1s;
  }
  .btn-c-primary:hover{ opacity:.92; }
  .btn-c-primary:active{ transform:translateY(1px); }
  .btn-c-primary:disabled{ opacity:.5; cursor:not-allowed; }

  .status-tabs{ display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; }
  .status-tab{ padding:.45rem .9rem; border-radius:20px; border:1px solid var(--line); background:var(--panel-2); color:var(--muted); font-size:.8rem; font-weight:700; }
  .status-tab span{ opacity:.7; margin-left:.25rem; }
  .status-tab.active{ background:var(--pink); border-color:var(--pink); color:#fff; }

  .alert-c{ padding:.75rem 1rem; border-radius:8px; margin-bottom:1rem; font-size:.85rem; }
  .alert-danger{ background:rgba(220,53,69,.12); border:1px solid rgba(220,53,69,.35); color:#ff8a97; }

  .pager-c{ display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.8rem; padding-top:1.1rem; margin-top:1.1rem; border-top:1px solid var(--line); }
  .pager-info{ font-size:.78rem; color:var(--muted-2); font-weight:600; }
  .pager-links{ display:flex; align-items:center; gap:.35rem; }
  .pager-link{ min-width:34px; height:34px; padding:0 .55rem; border-radius:8px; border:1px solid var(--line); background:var(--panel-2); color:var(--muted); font-size:.8rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
  .pager-link:hover{ border-color:var(--pink); color:var(--text); }
  .pager-link.active{ background:var(--pink); border-color:var(--pink); color:#fff; }
  .pager-link.disabled{ opacity:.4; pointer-events:none; }

  @media (max-width: 640px) {
    .analytics-head{ flex-direction:column; align-items:stretch; }
    .analytics-head .btn-c-primary{ width:100%; }
    table.table-clean thead{ display:none; }
    table.table-clean, table.table-clean tbody, table.table-clean tr, table.table-clean td{ display:block; width:100%; }
    table.table-clean tr{ border-bottom:1px solid var(--line); padding:.6rem 0; }
    table.table-clean td{ padding:.15rem 0; text-align:left !important; }
    .topic-toggle{ flex-direction:column; gap:.6rem; }
    .ticket-form .btn-c-primary{ width:100%; }
    .ql-toolbar.ql-snow .ql-formats{ margin-right:6px !important; }
  }
</style>

<script>
  function toggleEventSelect(radio) {
    document.getElementById('relatedEventSelect').style.display = (radio.value === 'event') ? 'block' : 'none';
  }

  var quillNew = new Quill('#editor-new', {
    theme: 'snow',
    placeholder: 'Describe your issue in detail…'
  });

  function syncEditor(editorId, textareaId) {
    var editor = editorId === 'editor-new' ? quillNew : null;
    var el = document.getElementById(textareaId);
    if (editor && el) { el.value = editor.root.innerHTML; }
    return true;
  }
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>