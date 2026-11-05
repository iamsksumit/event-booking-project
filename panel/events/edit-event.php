<?php
/*
 * ob_start() must be the very first thing that runs - see the note in
 * create-event.php. This file has THREE separate header("Location: ...")
 * redirects (role mismatch, and after a successful save), all of which sit
 * after header.php/events-ui.php already print HTML. Buffering the output
 * means those redirects work no matter what already printed before them.
 */
ob_start();

require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';

$pageTitle  = "Edit Event";
$activePage = 'events-list';

include __DIR__ . '/../include/header.php';
include __DIR__ . '/../include/events-ui.php';

$typeParam  = $_GET['type'] ?? 'rsvp';
$event_type = $typeParam === 'paid' || $typeParam === 'ticket' ? 'ticket' : 'rsvp';
$errors     = [];

if (!isset($_GET['id']) || trim($_GET['id']) === '') {
    echo '<div class="panel-card"><p>No event specified.</p></div>';
    include __DIR__ . '/../include/footer.php';
    exit();
}
$url = $_GET['id'];

$stmt = $conn->prepare("SELECT * FROM events WHERE url = ? LIMIT 1");
$stmt->bind_param('s', $url);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();

if (!$event) {
    echo '<div class="panel-card"><p>Event not found.</p></div>';
    include __DIR__ . '/../include/footer.php';
    exit();
}

if ($role === 'partner' && (int) $event['user_id'] !== $userId) {
    header("Location: index.php");
    exit();
}

$event_id       = (int) $event['id'];
$selected_venue = (int) $event['sub_tittle'];

/* Preselected option needs both id and label so Select2 can render it
   without an extra round trip to search.php. */
$currentVenueName = '';
if ($selected_venue) {
    $vStmt = $conn->prepare("SELECT name FROM place WHERE id = ?");
    $vStmt->bind_param('i', $selected_venue);
    $vStmt->execute();
    $vRow = $vStmt->get_result()->fetch_assoc();
    $currentVenueName = $vRow['name'] ?? '';
}

$catStmt = $conn->prepare("
    SELECT c.id, c.cat_name
    FROM event_category ec
    JOIN category c ON c.id = ec.cat_id
    WHERE ec.event_id = ?");
$catStmt->bind_param('i', $event_id);
$catStmt->execute();
$selectedCategoryRows = $catStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$artStmt = $conn->prepare("
    SELECT a.id, a.name
    FROM event_artist ea
    JOIN artist a ON a.id = ea.artist_id
    WHERE ea.event_id = ?");
$artStmt->bind_param('i', $event_id);
$artStmt->execute();
$selectedArtistRows = $artStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$tixStmt = $conn->prepare("SELECT * FROM event_tickets WHERE event_id = ?");
$tixStmt->bind_param('i', $event_id);
$tixStmt->execute();
$tickets = $tixStmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ===================== organiser name default (only used if event has none) =====================
   Falls back to partner_kyc.business_name, then users.fname/users.lname
   (the users table doesn't have first_name/last_name, which is why this
   used to come back empty). Still a normal editable text input either way. */
$organiserDefault = $event['Organised'] ?? '';
if ($organiserDefault === '') {
    try {
        $kycStmt = $conn->prepare("
            SELECT u.fname, u.lname, k.business_name
            FROM users u
            LEFT JOIN partner_kyc k ON k.user_id = u.id
            WHERE u.id = ? LIMIT 1");
        if ($kycStmt) {
            $kycStmt->bind_param('i', $userId);
            $kycStmt->execute();
            $kycRow = $kycStmt->get_result()->fetch_assoc();
            if ($kycRow) {
                $organiserDefault = !empty($kycRow['business_name'])
                    ? $kycRow['business_name']
                    : trim(($kycRow['fname'] ?? '') . ' ' . ($kycRow['lname'] ?? ''));
            }
        }
    } catch (Throwable $e) {
        $organiserDefault = '';
    }
}

/* ===================== save ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $title       = trim($_POST['event_name'] ?? '');
    $place       = $_POST['venue_location'] ?? '';
    $start       = $_POST['start_event_date'] ?? '';
    $end         = $_POST['end_event_date'] ?? '';
    $description = $_POST['description'] ?? '';
    $video       = trim($_POST['video_url'] ?? '#');
    $organiser   = trim($_POST['organizer_name'] ?? '');
    $special     = trim($_POST['special_entry_details'] ?? '');
    $term        = $_POST['terms'] ?? '';
    $multiple_dates_id = (int) ($_POST['multiple_date_id'] ?? 0);
    $multiple_dates    = $_POST['multiple_dates'] ?? '';

    if ($title === '') $errors[] = 'Event name is required.';
    if ($place === '')  $errors[] = 'Venue is required.';
    if ($start === '' || $end === '') $errors[] = 'Start and end dates are required.';
    if ($start !== '' && $end !== '' && strtotime($end) <= strtotime($start)) $errors[] = 'End date must be after start date.';
    if (trim(strip_tags($description)) === '') $errors[] = 'Description is required.';
    if ($organiser === '') $errors[] = 'Organizer name is required.';

    // Image stays optional here too - if nothing new is uploaded we just keep
    // whatever was already saved.
    $imagePath = $event['image'];
    if (!empty($_FILES['event_image']['name']) && $_FILES['event_image']['error'] === 0) {
        $filename = basename($_FILES['event_image']['name']);
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed  = ['jpg', 'jpeg', 'png'];
        if (!in_array($ext, $allowed, true)) {
            $errors[] = 'Event image must be a JPG or PNG file.';
        } else {
            $safeName = uniqid() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
            $folder   = __DIR__ . "/../images/events/" . $safeName;
            if (move_uploaded_file($_FILES['event_image']['tmp_name'], $folder)) {
                $imagePath = 'https://' . $_SERVER['HTTP_HOST'] . "/images/events/" . $safeName;
            } else {
                $errors[] = 'Error uploading image.';
            }
        }
    }

    if (empty($errors)) {
        $u = $conn->prepare("
            UPDATE events SET
                tittle = ?, sub_tittle = ?, start = ?, end = ?, description = ?,
                multiple_date_id = ?, multiple_dates = ?, video = ?, Organised = ?,
                special_detail = ?, term = ?, image = ?, status = 4, event_status = 1, user_id = ?
            WHERE id = ?");

        // 14 placeholders (status=4 and event_status=1 are literals).
        $u->bind_param(
            'sssssissssssii',
            $title, $place, $start, $end, $description, $multiple_dates_id, $multiple_dates,
            $video, $organiser, $special, $term, $imagePath, $userId, $event_id
        );

        if ($u->execute()) {
            $delCat = $conn->prepare("DELETE FROM event_category WHERE event_id = ?");
            $delCat->bind_param('i', $event_id);
            $delCat->execute();

            $delArt = $conn->prepare("DELETE FROM event_artist WHERE event_id = ?");
            $delArt->bind_param('i', $event_id);
            $delArt->execute();

            $delTix = $conn->prepare("DELETE FROM event_tickets WHERE event_id = ?");
            $delTix->bind_param('i', $event_id);
            $delTix->execute();

            if (!empty($_POST['categories'])) {
                $insCat = $conn->prepare("INSERT INTO event_category (event_id, cat_id) VALUES (?, ?)");
                foreach ($_POST['categories'] as $category_id) {
                    $category_id = (int) $category_id;
                    $insCat->bind_param('ii', $event_id, $category_id);
                    $insCat->execute();
                }
            }
            if (!empty($_POST['artists'])) {
                $insArt = $conn->prepare("INSERT INTO event_artist (event_id, artist_id) VALUES (?, ?)");
                foreach ($_POST['artists'] as $artist_id) {
                    $artist_id = (int) $artist_id;
                    $insArt->bind_param('ii', $event_id, $artist_id);
                    $insArt->execute();
                }
            }
            if (!empty($_POST['ticket_name']) && !empty($_POST['ticket_tickets'])) {
                $insTix = $conn->prepare("
                    INSERT INTO event_tickets (event_id, created_by, ticket_name, max_tickets, tickets_left, ticket_cost, ticket_desc)
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($_POST['ticket_name'] as $index => $ticket_name) {
                    $ticket_name = trim($ticket_name);
                    if ($ticket_name === '') continue;
                    $ticket_tickets = (int) ($_POST['ticket_tickets'][$index] ?? 0);
                    $ticket_desc    = trim($_POST['ticket_desc'][$index] ?? '');
                    $ticket_cost    = $event_type === 'ticket' ? (float) ($_POST['ticket_cost'][$index] ?? 0) : 0;
                    $insTix->bind_param('iisiids', $event_id, $userId, $ticket_name, $ticket_tickets, $ticket_tickets, $ticket_cost, $ticket_desc);
                    $insTix->execute();
                }
            }

            header("Location: single-event?id=" . urlencode($url));
            exit();
        } else {
            $errors[] = 'Error updating event: ' . h($conn->error);
        }
    }
}
?>

<div class="page-head">
  <div><h2>Edit Event</h2><p><?php echo h($event['tittle']); ?></p></div>
</div>

<?php if ($errors): ?>
<div class="panel-card mb-3" style="border-color:rgba(255,61,104,.4);">
    <?php foreach ($errors as $err): ?><div style="color:var(--pink); font-size:.85rem; font-weight:600;"><i class="fa-solid fa-circle-exclamation"></i> <?php echo h($err); ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form action="?type=<?php echo h($event_type); ?>&id=<?php echo urlencode($url); ?>" method="POST" enctype="multipart/form-data" id="eventForm" novalidate>
<div class="panel-card">

    <div class="ev-steps">
        <button type="button" class="step-btn active" data-step="1">1. Basics</button>
        <button type="button" class="step-btn" data-step="2">2. Media</button>
        <button type="button" class="step-btn" data-step="3">3. Category &amp; Terms</button>
        <button type="button" class="step-btn" data-step="4">4. Tickets</button>
    </div>

    <div class="ev-section active" data-section="1">
        <div class="row">
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Event Name<span class="required">*</span></label>
                <input type="text" name="event_name" value="<?php echo h($event['tittle']); ?>" class="form-control-c">
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Venue / Location<span class="required">*</span></label>
                <div class="select-with-add">
                    <select name="venue_location" id="venue_location" style="width:100%;">
                        <?php if ($currentVenueName !== ''): ?>
                            <option value="<?php echo (int) $selected_venue; ?>" selected><?php echo h($currentVenueName); ?></option>
                        <?php endif; ?>
                    </select>
                    <button type="button" class="btn-mini" id="addVenueBtn" title="Add a new venue"><i class="fa-solid fa-plus"></i></button>
                </div>
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Start Date &amp; Time<span class="required">*</span></label>
                <input type="text" name="start_event_date" id="start" class="form-control-c" value="<?php echo h(date('Y-m-d H:i', strtotime($event['start']))); ?>" readonly autocomplete="off">
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">End Date &amp; Time<span class="required">*</span></label>
                <input type="text" name="end_event_date" id="end" class="form-control-c" value="<?php echo h(date('Y-m-d H:i', strtotime($event['end']))); ?>" readonly autocomplete="off">
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Recurs</label>
                <select id="multipledates" name="multiple_date_id" class="form-control-c">
                    <option value="0" <?php echo $event['multiple_date_id'] == 0 ? 'selected' : ''; ?>>Once</option>
                    <option value="1" <?php echo $event['multiple_date_id'] == 1 ? 'selected' : ''; ?>>Multiple dates</option>
                </select>
            </div>
            <div class="col-lg-6 ev-field" id="date-picker-container" style="display:<?php echo $event['multiple_date_id'] == 1 ? 'block' : 'none'; ?>;">
                <label class="form-label-c">Additional Dates</label>
                <input type="text" id="multipledatepicker" name="multiple_dates" class="form-control-c" value="<?php echo h($event['multiple_dates']); ?>" readonly>
            </div>
        </div>
    </div>

    <div class="ev-section" data-section="2">
        <div class="row">
            <div class="col-lg-12 ev-field">
                <label class="form-label-c">Description<span class="required">*</span></label>
                <textarea class="form-control-c" name="description" id="description-editor" rows="8"><?php echo h($event['description']); ?></textarea>
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Event Image (JPG/PNG)</label>
                <input class="form-control-c" type="file" name="event_image" accept=".jpg,.jpeg,.png">
                <small class="cell-sub">Optional — leave blank to keep the current image.</small>
                <?php if (!empty($event['image'])): ?>
                <img src="<?php echo h($event['image']); ?>" alt="Current image" width="90" height="90" style="border-radius:10px; object-fit:cover; margin-top:.6rem; border:1px solid var(--line);">
                <?php endif; ?>
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Video URL</label>
                <input type="text" name="video_url" class="form-control-c" value="<?php echo h($event['video']); ?>">
            </div>
        </div>
    </div>

    <div class="ev-section" data-section="3">
        <div class="row">
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Organizer Name<span class="required">*</span></label>
                <input type="text" name="organizer_name" value="<?php echo h($organiserDefault); ?>" class="form-control-c">
                <small class="cell-sub">Feel free to change it.</small>
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Special Entry Details</label>
                <input type="text" name="special_entry_details" class="form-control-c" value="<?php echo h($event['special_detail']); ?>">
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Event Category</label>
                <div class="select-with-add">
                    <select name="categories[]" id="categories" multiple style="width:100%;">
                        <?php foreach ($selectedCategoryRows as $c): ?>
                            <option value="<?php echo (int) $c['id']; ?>" selected><?php echo h($c['cat_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn-mini" id="addCategoryBtn" title="Add a new category"><i class="fa-solid fa-plus"></i></button>
                </div>
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Artist / Performer</label>
                <div class="select-with-add">
                    <select name="artists[]" id="artists" multiple style="width:100%;">
                        <?php foreach ($selectedArtistRows as $a): ?>
                            <option value="<?php echo (int) $a['id']; ?>" selected><?php echo h($a['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn-mini" id="addArtistBtn" title="Add a new artist"><i class="fa-solid fa-plus"></i></button>
                </div>
            </div>
            <div class="col-lg-12 ev-field">
                <label class="form-label-c">Terms &amp; Conditions</label>
                <textarea class="form-control-c" name="terms" id="terms-editor" rows="8"><?php echo h($event['term']); ?></textarea>
            </div>
        </div>
    </div>

    <div class="ev-section" data-section="4">
        <div class="ticket-editor" id="ticketEditor">
            <?php if ($tickets): foreach ($tickets as $t): ?>
            <div class="ticket-card">
                <span class="remove-ticket" onclick="this.closest('.ticket-card').remove()">Remove</span>
                <input type="text" name="ticket_name[]" placeholder="Ticket name" class="form-control-c" value="<?php echo h($t['ticket_name']); ?>">
                <input type="number" min="0" name="ticket_tickets[]" placeholder="Number of tickets" class="form-control-c" value="<?php echo (int) $t['max_tickets']; ?>">
                <?php if ($event_type === 'ticket'): ?>
                <input type="number" min="0" step="0.01" name="ticket_cost[]" placeholder="Price (₹)" class="form-control-c" value="<?php echo h($t['ticket_cost']); ?>">
                <?php endif; ?>
                <textarea name="ticket_desc[]" placeholder="Description" class="form-control-c"><?php echo h($t['ticket_desc']); ?></textarea>
            </div>
            <?php endforeach; else: ?>
                <p class="cell-sub">No tickets yet — add one below.</p>
            <?php endif; ?>
            <div class="add-ticket-card" id="addTicketBtn"><i class="fa-solid fa-plus"></i>Add ticket type</div>
        </div>
    </div>

    <div class="ev-form-nav">
        <button type="button" class="btn-ghost-c" id="prevStep" style="visibility:hidden;"><i class="fa-solid fa-arrow-left"></i> Back</button>
        <button type="button" class="btn-pink-sm" id="nextStep">Next <i class="fa-solid fa-arrow-right"></i></button>
        <button type="submit" name="save" class="btn-pink-sm" id="submitBtn" style="display:none;"><i class="fa-solid fa-check"></i> Save Changes</button>
    </div>
</div>
</form>

<?php include __DIR__ . '/../include/event-form-widgets.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var steps = Array.from(document.querySelectorAll('.step-btn'));
    var sections = Array.from(document.querySelectorAll('.ev-section'));
    var current = 1;

    function show(step) {
        current = step;
        steps.forEach(function (b) {
            var s = parseInt(b.dataset.step, 10);
            b.classList.toggle('active', s === step);
            b.classList.toggle('done', s < step);
        });
        sections.forEach(function (sec) {
            sec.classList.toggle('active', parseInt(sec.dataset.section, 10) === step);
        });
        document.getElementById('prevStep').style.visibility = step === 1 ? 'hidden' : 'visible';
        document.getElementById('nextStep').style.display = step === steps.length ? 'none' : 'inline-flex';
        document.getElementById('submitBtn').style.display = step === steps.length ? 'inline-flex' : 'none';
    }

    steps.forEach(function (b) { b.addEventListener('click', function () { show(parseInt(b.dataset.step, 10)); }); });
    document.getElementById('prevStep').addEventListener('click', function () { if (current > 1) show(current - 1); });

    var multiSelect = document.getElementById('multipledates');
    var multiContainer = document.getElementById('date-picker-container');
    multiSelect.addEventListener('change', function () {
        multiContainer.style.display = this.value === '1' ? 'block' : 'none';
    });

    var ticketEditor = document.getElementById('ticketEditor');
    var addBtn = document.getElementById('addTicketBtn');
    var isTicketed = <?php echo $event_type === 'ticket' ? 'true' : 'false'; ?>;

    addBtn.addEventListener('click', function () {
        var card = document.createElement('div');
        card.className = 'ticket-card';
        card.innerHTML =
            '<span class="remove-ticket" onclick="this.closest(\'.ticket-card\').remove()">Remove</span>' +
            '<input type="text" name="ticket_name[]" placeholder="Ticket name" class="form-control-c">' +
            '<input type="number" min="0" name="ticket_tickets[]" placeholder="Number of tickets" class="form-control-c">' +
            (isTicketed ? '<input type="number" min="0" step="0.01" name="ticket_cost[]" placeholder="Price (₹)" class="form-control-c">' : '') +
            '<textarea name="ticket_desc[]" placeholder="Description" class="form-control-c"></textarea>';
        ticketEditor.insertBefore(card, addBtn);
    });

    // ===================== autocomplete + WYSIWYG init =====================
    initAutocomplete('venue_location', 'place', 'Search or select a venue…', document.getElementById('addVenueBtn'));
    initAutocomplete('categories', 'category', 'Search and add categories…', document.getElementById('addCategoryBtn'));
    initAutocomplete('artists', 'artist', 'Search and add artists…', document.getElementById('addArtistBtn'));
    window.descEditor  = null;
    window.termsEditor = null;
    initEventEditor('description-editor', 'descEditor');
    initEventEditor('terms-editor', 'termsEditor');

    // ===================== validation (replaces broken native validation) =====================
    function markField(el, bad) {
        if (!el) return;
        if (el.classList) el.classList.toggle('field-error', bad);
        var container = el.nextElementSibling;
        if (container && container.classList && container.classList.contains('select2-container')) {
            container.classList.toggle('field-error', bad);
        }
    }

    function stepMissing(step) {
        var missing = [];

        function req(name, label) {
            var el = document.getElementsByName(name)[0];
            if (!el) return;
            var val = el.value;
            var bad = !val || val.toString().trim() === '';
            markField(el, bad);
            if (bad) missing.push(label);
        }

        if (step === 1) {
            req('event_name', 'Event Name');
            req('venue_location', 'Venue / Location');
            req('start_event_date', 'Start Date & Time');
            req('end_event_date', 'End Date & Time');
        }
        if (step === 2) {
            var descText = window.descEditor
                ? window.descEditor.getData().replace(/<[^>]*>/g, '').trim()
                : document.getElementById('description-editor').value.trim();
            if (descText === '') missing.push('Description');
            // image is optional on edit - an image may already exist
        }
        if (step === 3) {
            req('organizer_name', 'Organizer Name');
        }
        if (step === 4) {
            var names = document.getElementsByName('ticket_name[]');
            var hasTicket = Array.prototype.some.call(names, function (t) { return t.value.trim() !== ''; });
            if (!hasTicket) missing.push('At least one ticket type');
        }
        return missing;
    }

    document.getElementById('nextStep').addEventListener('click', function () {
        var missing = stepMissing(current);
        if (missing.length) {
            alert('Please fill in the following before continuing:\n\n• ' + missing.join('\n• '));
            return;
        }
        if (current < steps.length) show(current + 1);
    });

    document.getElementById('eventForm').addEventListener('submit', function (e) {
        if (window.descEditor) window.descEditor.updateSourceElement();
        if (window.termsEditor) window.termsEditor.updateSourceElement();

        var allMissing = [];
        for (var s = 1; s <= steps.length; s++) { allMissing = allMissing.concat(stepMissing(s)); }
        allMissing = allMissing.filter(function (v, i) { return allMissing.indexOf(v) === i; });

        var start = document.getElementById('start');
        var end = document.getElementById('end');
        if (start.value && end.value) {
            var startDate = new Date(start.value.replace(' ', 'T'));
            var endDate = new Date(end.value.replace(' ', 'T'));
            if (endDate <= startDate) {
                allMissing.push('End date must be after start date');
            }
        }

        if (allMissing.length) {
            e.preventDefault();
            alert('Please fix the following before submitting:\n\n• ' + allMissing.join('\n• '));
            for (var s2 = 1; s2 <= steps.length; s2++) {
                if (stepMissing(s2).length) { show(s2); break; }
            }
        }
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script>
flatpickr("#multipledatepicker", { mode: "multiple", dateFormat: "Y-m-d", monthSelectorType: "static" });

// Click-to-pick only - inputs are `readonly`. No minDate restriction here
// since we're editing events that may already be in the past.
flatpickr("#start", {
    enableTime: true,
    dateFormat: "Y-m-d H:i",
    time_24hr: true,
    onChange: function (selectedDates, dateStr) {
        endPicker.set('minDate', dateStr);
    }
});
var endPicker = flatpickr("#end", {
    enableTime: true,
    dateFormat: "Y-m-d H:i",
    time_24hr: true
});
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>