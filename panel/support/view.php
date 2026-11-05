<?php
/**
 * support/view.php ("Support Ticket Thread")
 * Full conversation for one ticket: original message + attachment, every
 * reply in order, a reply box (admin + partner both use it), and an
 * admin-only status control.
 *
 * Access:
 *   - Admin can open any ticket.
 *   - Partner can only open their own ticket (?id= must belong to them).
 *
 * When admin replies, they can change the status in the same submit
 * (defaults to "pending" -- waiting on the partner -- unless they pick
 * something else). When a partner replies to a resolved/closed ticket,
 * it's automatically reopened to "open" so it doesn't get missed.
 *
 * Requires include/session_guard.php, include/config.php, and
 * support/_helpers.php (ticketStatusBadge, ticketPriorityBadge,
 * sanitizeTicketHtml, handleTicketUpload) -- pulled in below.
 */
require_once __DIR__ . '/../include/session_guard.php';
require_once __DIR__ . '/../include/config.php';
require_once __DIR__ . '/../include/notify.php'; // notify_user / notify_role
require_once __DIR__ . '/_helpers.php';

$isAdmin   = ($role === 'admin');
$isPartner = ($role === 'partner'); // ADAPT THIS if your partner role string differs

if (!$isAdmin && !$isPartner) {
    header('Location: /profile');
    exit();
}

$pageTitle  = 'Support Ticket';
$activePage = 'support';

$currentUserId = (int) $userId; // ADAPT THIS if the logged-in id lives in a different session var
$ticketId      = (int) ($_GET['id'] ?? 0);
if (!$ticketId) { header('Location: index.php'); exit(); }

/* ===================== load ticket + access check ===================== */
$stmt = $conn->prepare("
    SELECT t.*, u.fname, u.lname, u.email, k.business_name, e.tittle AS event_title, e.url AS event_url
    FROM support_tickets t
    LEFT JOIN users u ON u.id = t.user_id
    LEFT JOIN partner_kyc k ON k.user_id = u.id
    LEFT JOIN events e ON e.id = t.related_event_id
    WHERE t.id = ?
");
$stmt->bind_param('i', $ticketId);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();

if (!$ticket) { header('Location: index.php'); exit(); }
if ($isPartner && (int) $ticket['user_id'] !== $currentUserId) {
    header('Location: index.php');
    exit();
}

$formError = null;

/* ===================== reply / status update ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply') {
    $message = sanitizeTicketHtml($_POST['message'] ?? '');

    $newStatus = $ticket['status'];
    if ($isAdmin) {
        $requestedStatus = $_POST['status'] ?? '';
        $newStatus = in_array($requestedStatus, ['open', 'pending', 'resolved', 'closed'], true)
            ? $requestedStatus
            : 'pending'; // admin replied -> default to "waiting on partner" unless told otherwise
    } elseif (in_array($ticket['status'], ['resolved', 'closed'], true)) {
        $newStatus = 'open'; // partner replying reopens the ticket
    }

    if (strip_tags($message) === '' && empty($_FILES['attachment']['name'])) {
        $formError = 'Please write a message or attach a file.';
    } else {
        try {
            $attachment = handleTicketUpload('attachment');
            $senderType = $isAdmin ? 'admin' : 'partner';

            $stmt = $conn->prepare("
                INSERT INTO support_ticket_replies (ticket_id, sender_type, sender_id, message, attachment, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param('isiss', $ticketId, $senderType, $currentUserId, $message, $attachment);
            $stmt->execute();

            $stmt = $conn->prepare("UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('si', $newStatus, $ticketId);
            $stmt->execute();

            $excerpt = mb_substr(trim(strip_tags($message)), 0, 140);
            if ($excerpt === '') { $excerpt = 'View the attachment on this ticket.'; }
            $ticketLink = '/support/view.php?id=' . $ticketId;

            if ($isAdmin) {
                // Admin replied -> notify the partner who owns this ticket.
                notify_user(
                    $conn,
                    (int) $ticket['user_id'],
                    'support_reply',
                    'New reply on ticket #' . $ticketId . ': ' . $ticket['subject'],
                    $excerpt,
                    $ticketLink
                );
            } else {
                // Partner replied -> notify all admins.
                $requesterLabel = trim(($ticket['fname'] ?? '') . ' ' . ($ticket['lname'] ?? '')) ?: 'A partner';
                if (!empty($ticket['business_name'])) { $requesterLabel = $ticket['business_name']; }
                notify_role(
                    $conn,
                    'admin',
                    'support_reply',
                    $requesterLabel . ' replied to ticket #' . $ticketId,
                    $excerpt,
                    $ticketLink
                );
            }

            header('Location: view.php?id=' . $ticketId);
            exit();
        } catch (RuntimeException $e) {
            $formError = $e->getMessage();
        }
    }
} elseif ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    /* Status-only change, no reply text required. */
    $requestedStatus = $_POST['status'] ?? '';
    if (in_array($requestedStatus, ['open', 'pending', 'resolved', 'closed'], true)) {
        $stmt = $conn->prepare("UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $requestedStatus, $ticketId);
        $stmt->execute();

        [$statusLabelForNotice] = ticketStatusBadge($requestedStatus);
        notify_user(
            $conn,
            (int) $ticket['user_id'],
            'support_status',
            'Ticket #' . $ticketId . ' status updated',
            'Your ticket "' . $ticket['subject'] . '" was marked as ' . $statusLabelForNotice . '.',
            '/support/view.php?id=' . $ticketId
        );

        header('Location: view.php?id=' . $ticketId);
        exit();
    }
}

/* Reload ticket in case status just changed above without a redirect (shouldn't happen, but safe). */
if (($_POST['action'] ?? '') && !$formError) {
    $stmt = $conn->prepare("
        SELECT t.*, u.fname, u.lname, u.email, k.business_name, e.tittle AS event_title, e.url AS event_url
        FROM support_tickets t
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN partner_kyc k ON k.user_id = u.id
        LEFT JOIN events e ON e.id = t.related_event_id
        WHERE t.id = ?
    ");
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
}

/* ===================== replies thread ===================== */
$stmt = $conn->prepare("
    SELECT r.*, u.fname, u.lname
    FROM support_ticket_replies r
    LEFT JOIN users u ON u.id = r.sender_id
    WHERE r.ticket_id = ?
    ORDER BY r.created_at ASC
");
$stmt->bind_param('i', $ticketId);
$stmt->execute();
$replies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

[$sLabel, $sClass] = ticketStatusBadge($ticket['status']);
[$pLabel, $pClass] = ticketPriorityBadge($ticket['priority']);
$requesterLabel = trim(($ticket['fname'] ?? '') . ' ' . ($ticket['lname'] ?? '')) ?: '—';
if (!empty($ticket['business_name'])) { $requesterLabel = $ticket['business_name']; }

include __DIR__ . '/../include/header.php';
?>

<div class="page-head analytics-head">
  <div>
    <h2><a href="index.php" style="color:var(--muted); text-decoration:none;">Support Tickets</a> / #<?php echo (int) $ticket['id']; ?></h2>
    <p><?php echo htmlspecialchars($ticket['subject']); ?></p>
  </div>
  <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
    <span class="badge <?php echo $pClass; ?> light"><?php echo $pLabel; ?> priority</span>
    <span class="badge <?php echo $sClass; ?> light"><?php echo $sLabel; ?></span>
  </div>
</div>

<div class="panel-card" style="margin-bottom:1.1rem;">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="cell-title" style="font-size:1rem;"><?php echo htmlspecialchars($requesterLabel); ?></div>
      <div class="cell-sub">
        <?php echo htmlspecialchars($ticket['email'] ?: '—'); ?> ·
        Opened <?php echo htmlspecialchars(date('d M Y, g:i A', strtotime($ticket['created_at']))); ?> ·
        <?php if ($ticket['event_title']): ?>
          Re: <strong><?php echo htmlspecialchars($ticket['event_title']); ?></strong>
        <?php else: ?>
          General inquiry
        <?php endif; ?>
      </div>
    </div>
    <?php if ($isAdmin): ?>
    <form method="POST" action="" class="ticket-form inline-status-form">
      <input type="hidden" name="action" value="update_status">
      <select name="status" class="form-control-c" onchange="this.form.submit()">
        <?php foreach (['open', 'pending', 'resolved', 'closed'] as $sv):
          [$sl] = ticketStatusBadge($sv);
        ?>
          <option value="<?php echo $sv; ?>" <?php echo $ticket['status'] === $sv ? 'selected' : ''; ?>><?php echo $sl; ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- ================= THREAD ================= -->
<div class="panel-card">
  <div class="ticket-thread">

    <div class="thread-msg">
      <div class="thread-msg-head">
        <span class="thread-author"><?php echo htmlspecialchars($requesterLabel); ?> <span class="thread-role">(requester)</span></span>
        <span class="thread-time"><?php echo htmlspecialchars(date('d M Y, g:i A', strtotime($ticket['created_at']))); ?></span>
      </div>
      <div class="thread-body"><?php echo $ticket['message']; // pre-sanitized on insert ?></div>
      <?php if (!empty($ticket['attachment'])): ?>
        <a class="thread-attachment" href="<?php echo htmlspecialchars($ticket['attachment']); ?>" target="_blank" rel="noopener">
          <i class="fa-solid fa-paperclip"></i> View attachment
        </a>
      <?php endif; ?>
    </div>

    <?php foreach ($replies as $r):
      $authorLabel = trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? '')) ?: ($r['sender_type'] === 'admin' ? 'Admin' : 'Partner');
      $roleTag     = $r['sender_type'] === 'admin' ? 'support team' : 'partner';
    ?>
      <div class="thread-msg <?php echo $r['sender_type'] === 'admin' ? 'thread-msg-admin' : ''; ?>">
        <div class="thread-msg-head">
          <span class="thread-author"><?php echo htmlspecialchars($authorLabel); ?> <span class="thread-role">(<?php echo $roleTag; ?>, user #<?php echo (int) $r['sender_id']; ?>)</span></span>
          <span class="thread-time"><?php echo htmlspecialchars(date('d M Y, g:i A', strtotime($r['created_at']))); ?></span>
        </div>
        <div class="thread-body"><?php echo $r['message']; // pre-sanitized on insert ?></div>
        <?php if (!empty($r['attachment'])): ?>
          <a class="thread-attachment" href="<?php echo htmlspecialchars($r['attachment']); ?>" target="_blank" rel="noopener">
            <i class="fa-solid fa-paperclip"></i> View attachment
          </a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

  </div>
</div>

<!-- ================= REPLY FORM ================= -->
<div class="panel-card">
  <div class="head"><div><h5>Reply</h5></div></div>

  <?php if ($formError): ?>
    <div class="alert-c alert-danger"><?php echo htmlspecialchars($formError); ?></div>
  <?php endif; ?>

  <form method="POST" action="" enctype="multipart/form-data" class="ticket-form">
    <input type="hidden" name="action" value="reply">

    <div class="form-row">
      <div id="editor-reply" class="wysiwyg-editor"></div>
      <textarea name="message" id="message-reply" style="display:none;"></textarea>
    </div>

    <div class="form-row">
      <label>Attachment (optional)</label>
      <input type="file" name="attachment" class="form-control-c" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip">
    </div>

    <?php if ($isAdmin): ?>
    <div class="form-row">
      <label>Set status on send</label>
      <select name="status" class="form-control-c" style="max-width:220px;">
        <?php foreach (['open', 'pending', 'resolved', 'closed'] as $sv):
          [$sl] = ticketStatusBadge($sv);
        ?>
          <option value="<?php echo $sv; ?>" <?php echo $sv === 'pending' ? 'selected' : ''; ?>><?php echo $sl; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <button type="submit" class="btn btn-c-primary" onclick="return syncEditor()">Send Reply</button>
  </form>
</div>

<link href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.6/quill.snow.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.6/quill.min.js"></script>

<style>
  .analytics-head{ display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem; }

  .ticket-thread{ display:flex; flex-direction:column; gap:1rem; }
  .thread-msg{ background:var(--panel-2); border:1px solid var(--line); border-radius:10px; padding:.9rem 1rem; }
  .thread-msg-admin{ border-left:3px solid var(--pink); }
  .thread-msg-head{ display:flex; justify-content:space-between; flex-wrap:wrap; gap:.4rem; margin-bottom:.5rem; }
  .thread-author{ font-weight:700; font-size:.85rem; color:var(--text); }
  .thread-role{ font-weight:500; color:var(--muted-2); font-size:.75rem; }
  .thread-time{ font-size:.75rem; color:var(--muted-2); }
  .thread-body{ font-size:.85rem; color:var(--text); line-height:1.5; }
  .thread-body p{ margin:0 0 .5rem; }
  .thread-attachment{ display:inline-flex; align-items:center; gap:.4rem; margin-top:.6rem; font-size:.78rem; color:var(--pink); text-decoration:none; }

  .ticket-form .form-row{ margin-bottom:1rem; }
  .ticket-form label{ display:block; font-size:.78rem; font-weight:700; color:var(--muted-2); margin-bottom:.35rem; }

  /* ---- Inputs / selects ---- */
  .ticket-form input.form-control-c,
  .ticket-form select.form-control-c{
    width:100%; background:var(--panel-2); border:1px solid var(--line); color:var(--text);
    border-radius:8px; padding:.6rem .8rem; font-size:.85rem; transition:border-color .15s, box-shadow .15s;
  }
  .ticket-form input.form-control-c:focus,
  .ticket-form select.form-control-c:focus{
    outline:none; border-color:var(--pink); box-shadow:0 0 0 3px rgba(255,64,129,.18);
  }
  .ticket-form select.form-control-c{
    -webkit-appearance:none; appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23888'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right .9rem center; padding-right:2.2rem;
  }
  .ticket-form input[type="file"].form-control-c{ padding:.45rem .6rem; color:var(--muted-2); font-size:.8rem; }
  .ticket-form input[type="file"].form-control-c::file-selector-button{
    background:var(--pink); color:#fff; border:none; border-radius:6px;
    padding:.45rem .9rem; font-size:.78rem; font-weight:700; margin-right:.8rem; cursor:pointer;
    transition:opacity .15s;
  }
  .ticket-form input[type="file"].form-control-c::file-selector-button:hover{ opacity:.9; }

  .inline-status-form{ display:flex; align-items:center; margin:0; }
  .inline-status-form select.form-control-c{ min-width:150px; width:auto; }

  .wysiwyg-editor{ background:#fff; border:1px solid var(--line); border-radius:8px; min-height:130px; color:#111; }
  .ql-toolbar.ql-snow{ border-radius:8px 8px 0 0; background:#f4f4f6; flex-wrap:wrap; }
  .ql-container.ql-snow{ border-radius:0 0 8px 8px; min-height:100px; }

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

  .alert-c{ padding:.75rem 1rem; border-radius:8px; margin-bottom:1rem; font-size:.85rem; }
  .alert-danger{ background:rgba(220,53,69,.12); border:1px solid rgba(220,53,69,.35); color:#ff8a97; }

  @media (max-width: 640px) {
    .analytics-head{ flex-direction:column; align-items:stretch; }
    .thread-msg-head{ flex-direction:column; }
    .inline-status-form{ width:100%; }
    .inline-status-form select.form-control-c{ width:100%; }
    .ticket-form .btn-c-primary{ width:100%; }
    .ql-toolbar.ql-snow .ql-formats{ margin-right:6px !important; }
  }
</style>

<script>
  var quillReply = new Quill('#editor-reply', {
    theme: 'snow',
    placeholder: 'Write a reply…'
  });

  function syncEditor() {
    document.getElementById('message-reply').value = quillReply.root.innerHTML;
    return true;
  }
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>