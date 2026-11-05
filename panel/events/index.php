<?php

require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';



$pageTitle = "Event List";
$activePage = 'events';

include __DIR__ . '/../include/header.php';
include __DIR__ . '/../include/events-ui.php';

// include '../include/config.php';
// include '../include/header.php';
// include '../include/sidebar.php';
// include '../include/events-ui.php';



/* ===================== inputs ===================== */
$itemsPerPage = 6;
$searchTerm   = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortOrder    = (isset($_GET['sort']) && $_GET['sort'] === 'oldest') ? 'oldest' : 'newest';
$orderSQL     = $sortOrder === 'oldest' ? 'ASC' : 'DESC';
$currentPage  = (isset($_GET['page']) && is_numeric($_GET['page'])) ? max(1, (int) $_GET['page']) : 1;

$actual_link = "https://highspinn.com/events/";

/* ===================== build queries (prepared) ===================== */
$total = 0; $totalPages = 1; $eventsResult = null;
$likeTerm = '%' . $searchTerm . '%';

try {
    if ($role === 'partner') {
        $countStmt = $conn->prepare("
            SELECT COUNT(*) AS total FROM events
            WHERE user_id = ? AND status != 0 AND tittle LIKE ?");
        $countStmt->bind_param('is', $userId, $likeTerm);
        $countStmt->execute();
        $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        $totalPages  = max(1, (int) ceil($total / $itemsPerPage));
        $currentPage = min($currentPage, $totalPages);
        $offset      = ($currentPage - 1) * $itemsPerPage;

        $sql = "SELECT events.*, events.id AS event_id, place.name AS pname
                FROM events
                LEFT JOIN place ON events.sub_tittle = place.id
                WHERE events.user_id = ? AND events.status != 0 AND events.tittle LIKE ?
                ORDER BY events.id $orderSQL
                LIMIT ?, ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('isii', $userId, $likeTerm, $offset, $itemsPerPage);
        $stmt->execute();
        $eventsResult = $stmt->get_result();

    } else { // admin / staff (user_type 2)
        $countStmt = $conn->prepare("
            SELECT COUNT(*) AS total FROM events
            WHERE status != 0 AND tittle LIKE ?");
        $countStmt->bind_param('s', $likeTerm);
        $countStmt->execute();
        $total = (int) $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        $totalPages  = max(1, (int) ceil($total / $itemsPerPage));
        $currentPage = min($currentPage, $totalPages);
        $offset      = ($currentPage - 1) * $itemsPerPage;

        $sql = "SELECT events.*, events.id AS event_id, place.name AS pname
                FROM events
                LEFT JOIN place ON events.sub_tittle = place.id
                WHERE events.status != 0 AND events.tittle LIKE ?
                ORDER BY events.id $orderSQL
                LIMIT ?, ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $likeTerm, $offset, $itemsPerPage);
        $stmt->execute();
        $eventsResult = $stmt->get_result();
    }
} catch (Throwable $e) {
    $eventsResult = null;
}

/* Buffer rows + batch-fetch view counts in a single query (avoids N+1) */
$rows = $eventsResult ? $eventsResult->fetch_all(MYSQLI_ASSOC) : [];
$viewsMap = [];
if ($rows) {
    $ids = array_column($rows, 'event_id');
    $ids = array_map('intval', $ids);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $vStmt = $conn->prepare("SELECT event_id, SUM(views) AS v FROM views WHERE event_id IN ($in) GROUP BY event_id");
    $vStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $vStmt->execute();
    $vRes = $vStmt->get_result();
    while ($vr = $vRes->fetch_assoc()) { $viewsMap[$vr['event_id']] = (int) $vr['v']; }
}
?>

<div class="page-head">
  <div><h2>Event List</h2><p>Manage <?php echo $userType === 1 ? 'your' : 'all partner'; ?> events, active and upcoming.</p></div>
</div>

<div class="events-toolbar">
    <form method="GET" class="search-pill">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="search" placeholder="Search events..." value="<?php echo h($searchTerm); ?>">
        <input type="hidden" name="sort" value="<?php echo h($sortOrder); ?>">
        <button type="submit"><i class="fa-solid fa-arrow-right"></i></button>
    </form>
    <div class="d-flex align-items-center gap-2">
        <form method="GET">
            <input type="hidden" name="search" value="<?php echo h($searchTerm); ?>">
            <select name="sort" class="events-sort-select" onchange="this.form.submit()">
                <option value="newest" <?php if ($sortOrder === 'newest') echo 'selected'; ?>>Newest first</option>
                <option value="oldest" <?php if ($sortOrder === 'oldest') echo 'selected'; ?>>Oldest first</option>
            </select>
        </form>
        <a  href="#addEventModal" class="btn-pink-sm js-add-event"><i class="fa-solid fa-plus"></i> Create Event</a>
    </div>
</div>

<?php if ($rows): ?>
<div class="event-grid">
    <?php foreach ($rows as $row):
        $urlSlug   = $row['url'] ?? '';
        $status    = (int) ($row['status'] ?? 0);
        $totalViews = $viewsMap[$row['event_id']] ?? 0;
    ?>
    <div class="event-card">
        <div class="thumb-wrap">
            <?php echo event_status_badge($status); ?>
            <a href="single-event?id=<?php echo urlencode($urlSlug); ?>">
                <img src="<?php echo h($row['image'] ?? ''); ?>" alt="<?php echo h($row['tittle'] ?? ''); ?>" loading="lazy">
            </a>
        </div>
        <div class="event-card-body">
            <h4><a href="single-event?id=<?php echo urlencode($urlSlug); ?>"><?php echo h($row['tittle'] ?? ''); ?></a></h4>
            <div class="event-meta-row"><i class="fa-solid fa-location-dot"></i><?php echo h($row['pname'] ?? '—'); ?></div>
            <div class="event-meta-row"><i class="fa-solid fa-calendar"></i><?php echo h(fmt_event_date($row['start'] ?? '')); ?></div>
            <div class="event-meta-row"><i class="fa-solid fa-eye"></i><?php echo number_format($totalViews); ?> views</div>
            <div class="event-card-actions">
                <a href="duplicate-event?id=<?php echo urlencode($urlSlug); ?>" class="btn-mini" onclick="return confirm('Duplicate this event?');"><i class="fa-solid fa-copy"></i> Duplicate</a>
                <a href="single-event?id=<?php echo urlencode($urlSlug); ?>" class="btn-mini"><i class="fa-solid fa-circle-info"></i> Details</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="panel-card empty-state">
    <i class="fa-solid fa-calendar-xmark"></i>
    No events found<?php echo $searchTerm !== '' ? ' for "' . h($searchTerm) . '"' : ''; ?>.
</div>
<?php endif; ?>

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

<?php include '../include/footer.php'; ?>
