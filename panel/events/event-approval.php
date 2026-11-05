<?php
require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';

if ($role !== 'admin') {
    header("Location: /profile");
    exit();
}

$pageTitle  = "Event Approval";
$activePage = 'events-approval';

include __DIR__ . '/../include/header.php';
include __DIR__ . '/../include/events-ui.php';

/* ===================== approve / unapprove actions ===================== */
if (isset($_POST['approve_event']) || isset($_POST['unapprove_event'])) {
    $eventUrl  = $_POST['event_id'] ?? '';
    $newStatus = isset($_POST['approve_event']) ? 1 : 3; // 1 = Approved/Published, 3 = Unapproved

    $stmt = $conn->prepare("UPDATE events SET status = ? WHERE url = ?");
    $stmt->bind_param('is', $newStatus, $eventUrl);
    if ($stmt->execute()) {
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
        exit();
    } else {
        $flashError = "Error updating event status: " . h($conn->error);
    }
}

/* ===================== list (only events pending approval) ===================== */
$itemsPerPage = 6;
$searchTerm   = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortOrder    = (isset($_GET['sort']) && $_GET['sort'] === 'oldest') ? 'oldest' : 'newest';
$orderSQL     = $sortOrder === 'oldest' ? 'ASC' : 'DESC';
$currentPage  = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int) $_GET['page']) : 1;
$likeTerm     = '%' . $searchTerm . '%';

$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total FROM events
    INNER JOIN place ON events.sub_tittle = place.id
    WHERE events.status = 4 AND events.tittle LIKE ?");
$countStmt->bind_param('s', $likeTerm);
$countStmt->execute();
$total = (int) $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$totalPages  = max(1, (int) ceil($total / $itemsPerPage));
$currentPage = min($currentPage, $totalPages);
$offset      = ($currentPage - 1) * $itemsPerPage;

$stmt = $conn->prepare("
    SELECT events.*, place.name AS pname
    FROM events
    INNER JOIN place ON events.sub_tittle = place.id
    WHERE events.status = 4 AND events.tittle LIKE ?
    ORDER BY events.id $orderSQL
    LIMIT ?, ?");
$stmt->bind_param('sii', $likeTerm, $offset, $itemsPerPage);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<div class="page-head">
  <div><h2>Event Approval</h2><p>Events submitted by partners, waiting on your review.</p></div>
  <?php if ($total > 0): ?><span class="badge-count" style="font-size:.72rem;"><?php echo $total; ?> pending</span><?php endif; ?>
</div>

<?php if (!empty($flashError)): ?>
  <div class="panel-card mb-3" style="border-color:rgba(255,61,104,.4); color:var(--pink);"><?php echo $flashError; ?></div>
<?php endif; ?>

<div class="events-toolbar">
    <form method="GET" class="search-pill">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="search" placeholder="Search pending events..." value="<?php echo h($searchTerm); ?>">
        <input type="hidden" name="sort" value="<?php echo h($sortOrder); ?>">
        <button type="submit"><i class="fa-solid fa-arrow-right"></i></button>
    </form>
    <form method="GET">
        <input type="hidden" name="search" value="<?php echo h($searchTerm); ?>">
        <select name="sort" class="events-sort-select" onchange="this.form.submit()">
            <option value="newest" <?php if ($sortOrder === 'newest') echo 'selected'; ?>>Newest first</option>
            <option value="oldest" <?php if ($sortOrder === 'oldest') echo 'selected'; ?>>Oldest first</option>
        </select>
    </form>
</div>

<div class="panel-card">
<?php if ($rows): foreach ($rows as $row):
    $urlSlug = $row['url'] ?? '';
?>
    <div class="approval-row">
        <div class="approval-thumb"><img src="<?php echo h($row['image'] ?? ''); ?>" alt=""></div>
        <div class="flex-grow-1">
            <div class="cell-title"><a href="single-event?id=<?php echo urlencode($urlSlug); ?>" style="color:inherit;"><?php echo h($row['tittle'] ?? ''); ?></a></div>
            <div class="cell-sub"><?php echo h($row['pname'] ?? ''); ?> &middot; <?php echo h(fmt_event_date($row['start'] ?? '')); ?></div>
        </div>
        <?php echo event_status_badge((int)($row['status'] ?? 4)); ?>
        <form method="POST" class="d-inline">
            <input type="hidden" name="event_id" value="<?php echo h($urlSlug); ?>">
            <button type="submit" name="approve_event" class="btn-mini approve"><i class="fa-solid fa-check"></i> Approve</button>
        </form>
        <form method="POST" class="d-inline">
            <input type="hidden" name="event_id" value="<?php echo h($urlSlug); ?>">
            <button type="submit" name="unapprove_event" class="btn-mini reject"><i class="fa-solid fa-xmark"></i> Unapprove</button>
        </form>
    </div>
<?php endforeach; else: ?>
    <div class="empty-state"><i class="fa-solid fa-champagne-glasses"></i>Nothing waiting on review 🎉</div>
<?php endif; ?>
</div>

<div class="events-pagination">
    <small>Showing <?php echo $total > 0 ? (($currentPage - 1) * $itemsPerPage + 1) : 0; ?>–<?php echo min($currentPage * $itemsPerPage, $total); ?> of <?php echo $total; ?></small>
    <nav><ul class="pagination">
        <li class="page-item <?php if ($currentPage <= 1) echo 'disabled'; ?>">
            <a class="page-link" href="?page=<?php echo max(1,$currentPage-1); ?>&search=<?php echo urlencode($searchTerm); ?>&sort=<?php echo urlencode($sortOrder); ?>"><i class="fa-solid fa-chevron-left"></i></a>
        </li>
        <?php
        $startPage = max(1, $currentPage - 2);
        $endPage   = min($totalPages, $currentPage + 2);
        for ($i = $startPage; $i <= $endPage; $i++): ?>
            <li class="page-item <?php if ($currentPage == $i) echo 'active'; ?>">
                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($searchTerm); ?>&sort=<?php echo urlencode($sortOrder); ?>"><?php echo $i; ?></a>
            </li>
        <?php endfor; ?>
        <li class="page-item <?php if ($currentPage >= $totalPages) echo 'disabled'; ?>">
            <a class="page-link" href="?page=<?php echo min($totalPages,$currentPage+1); ?>&search=<?php echo urlencode($searchTerm); ?>&sort=<?php echo urlencode($sortOrder); ?>"><i class="fa-solid fa-chevron-right"></i></a>
        </li>
    </ul></nav>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>