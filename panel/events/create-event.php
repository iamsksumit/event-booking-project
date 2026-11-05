<?php
/*
 * ob_start() must be the very first thing that runs. header.php / events-ui.php
 * (included below) print HTML and a <style> block immediately, which is what
 * was causing "Cannot modify header information - headers already sent" the
 * moment this script tried to header("Location: ...") after a successful
 * save. Output buffering queues all of that printed output instead of
 * flushing it straight to the browser, so header() still works no matter
 * where in the file it's called from. PHP sends the buffered HTML once the
 * script ends - real HTTP headers always go out first now.
 */
ob_start();

require_once __DIR__ . '/../include/session_guard.php'; // sets $role, $userId
require_once __DIR__ . '/../include/config.php';

$pageTitle  = "Create Event";
$activePage = 'events-list';

include __DIR__ . '/../include/header.php';
include __DIR__ . '/../include/events-ui.php';

/* the header's "Add Event" modal hands off with type=rsvp|paid - normalise to rsvp|ticket */
$typeParam   = $_GET['type'] ?? 'rsvp';
$event_type  = $typeParam === 'paid' || $typeParam === 'ticket' ? 'ticket' : 'rsvp';
$event_title = isset($_GET['title']) ? trim($_GET['title']) : '';
$errors      = [];

/* ===================== organiser name default =====================
   Prefill from partner_kyc.business_name when set, otherwise fall back
   to the partner's first + last name (users.fname / users.lname - the
   users table doesn't have first_name/last_name, which is why this was
   silently coming back empty before). The field is still a normal
   editable text input, so the partner can change it before saving. */
$organiserDefault = '';
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
            if (!empty($kycRow['business_name'])) {
                $organiserDefault = $kycRow['business_name'];
            } else {
                $organiserDefault = trim(($kycRow['fname'] ?? '') . ' ' . ($kycRow['lname'] ?? ''));
            }
        }
    }
} catch (Throwable $e) {
    $organiserDefault = '';
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

    // Event image is optional now - if none is uploaded we just save an
    // empty path. The `image` column is NOT NULL but has no length/format
    // constraint on the value, so an empty string is fine.
    $imagePath = "";
    if (!empty($_FILES['event_image']['name']) && $_FILES['event_image']['error'] === 0) {
        $filename  = basename($_FILES['event_image']['name']);
        $tempname  = $_FILES['event_image']['tmp_name'];
        $ext       = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed   = ['jpg', 'jpeg', 'png'];

        if (!in_array($ext, $allowed, true)) {
            $errors[] = 'Event image must be a JPG or PNG file.';
        } elseif ($_FILES['event_image']['size'] > 8 * 1024 * 1024) {
            $errors[] = 'Event image must be under 8MB.';
        } else {
            $safeName = uniqid() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
            $folder   = __DIR__ . "/../images/events/" . $safeName;
            if (move_uploaded_file($tempname, $folder)) {
                $imagePath = 'https://' . $_SERVER['HTTP_HOST'] . "/images/events/" . $safeName;
            } else {
                $errors[] = 'Error uploading image.';
            }
        }
    }

    if (empty($errors)) {
        $url = event_slug($title);

        $sql = "INSERT INTO events
                (tittle, sub_tittle, start, end, multiple_date_id, multiple_dates, description, video,
                 Organised, special_detail, term, image, status, url, event_status, ticketing_type, user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 4, ?, 1, ?, ?)";
        $stmt = $conn->prepare($sql);

        // 15 placeholders (status=4 and event_status=1 are literals, not placeholders).
        $stmt->bind_param(
            'ssssisssssssssi',
            $title, $place, $start, $end, $multiple_dates_id, $multiple_dates, $description, $video,
            $organiser, $special, $term, $imagePath, $url, $event_type, $userId
        );

        if ($stmt->execute()) {
            $event_id = $stmt->insert_id;

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
                    $ticket_name    = trim($ticket_name);
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
            $errors[] = 'Error saving event: ' . h($conn->error);
        }
    }
}

$defaultTerms = "<ul>\n<li>Only 21+ allowed. Bring your ID cards for age verification.</li>\n<li>For stags cover charges will be applicable as per venue's discretion throughout the night.</li>\n<li>The entry closes at 9:00 PM. Cover charges will be applicable post that as per venue's discretion.</li>\n<li>Men must wear closed footwear (Shoes) and full length bottoms. (Applicable for Night Clubs)</li>\n<li>Tickets once booked cannot be exchanged or refunded.</li>\n<li>Venues/Organizers are solely responsible for the service, availability and quality of the events.</li>\n<li>Highspinn does not take any responsibility for the activities going on inside or outside the event. The entire responsibility of it is of the organizer/venue.</li>\n<li>In certain circumstances, Highspinn reserves the right to cancel the tickets owing to any internal reason which requires such action. In such cases, the customer will be provided full refund for the ticket within 7-10 working days.</li>\n<li>Venue/Organisers rules apply.</li>\n</ul>";
?>

<div class="page-head">
  <div><h2>Create Event</h2><p><?php echo $event_type === 'ticket' ? 'Paid ticketing event' : 'RSVP / guestlist event'; ?></p></div>
  <div class="range-tabs">
    <a href="?type=rsvp" style="text-decoration:none;"><button class="<?php echo $event_type === 'rsvp' ? 'active' : ''; ?>" type="button">RSVP</button></a>
    <a href="?type=ticket" style="text-decoration:none;"><button class="<?php echo $event_type === 'ticket' ? 'active' : ''; ?>" type="button">Ticketed</button></a>
  </div>
</div>

<?php if ($errors): ?>
<div class="panel-card mb-3" style="border-color:rgba(255,61,104,.4);">
    <?php foreach ($errors as $err): ?><div style="color:var(--pink); font-size:.85rem; font-weight:600;"><i class="fa-solid fa-circle-exclamation"></i> <?php echo h($err); ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form action="?type=<?php echo h($event_type); ?>" method="POST" enctype="multipart/form-data" id="eventForm" novalidate>
<div class="panel-card">

    <div class="ev-steps">
        <button type="button" class="step-btn active" data-step="1">1. Basics</button>
        <button type="button" class="step-btn" data-step="2">2. Media</button>
        <button type="button" class="step-btn" data-step="3">3. Category &amp; Terms</button>
        <button type="button" class="step-btn" data-step="4">4. Tickets</button>
    </div>

    <!-- Step 1: Basics -->
    <div class="ev-section active" data-section="1">
        <div class="row">
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Event Name<span class="required">*</span></label>
                <input type="text" name="event_name" value="<?php echo h($event_title); ?>" class="form-control-c">
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Venue / Location<span class="required">*</span></label>
                <div class="select-with-add">
                    <select name="venue_location" id="venue_location" style="width:100%;"></select>
                    <button type="button" class="btn-mini" id="addVenueBtn" title="Add a new venue"><i class="fa-solid fa-plus"></i></button>
                </div>
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Start Date &amp; Time<span class="required">*</span></label>
                <input type="text" name="start_event_date" id="start" class="form-control-c" placeholder="Select date &amp; time" readonly autocomplete="off">
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">End Date &amp; Time<span class="required">*</span></label>
                <input type="text" name="end_event_date" id="end" class="form-control-c" placeholder="Select date &amp; time" readonly autocomplete="off">
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Recurs</label>
                <select id="multipledates" name="multiple_date_id" class="form-control-c">
                    <option value="0">Once</option>
                    <option value="1">Multiple dates</option>
                </select>
            </div>
            <div class="col-lg-6 ev-field" id="date-picker-container" style="display:none;">
                <label class="form-label-c">Additional Dates</label>
                <input type="text" id="multipledatepicker" name="multiple_dates" class="form-control-c" readonly>
            </div>
        </div>
    </div>

    <!-- Step 2: Media -->
    <div class="ev-section" data-section="2">
        <div class="row">
            <div class="col-lg-12 ev-field">
                <label class="form-label-c">Description<span class="required">*</span></label>
                <textarea class="form-control-c" name="description" id="description-editor" rows="8"></textarea>
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Event Image (JPG/PNG)</label>
                <input class="form-control-c" type="file" name="event_image" accept=".jpg,.jpeg,.png">
                <small class="cell-sub">Optional — you can add or change this later.</small>
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Video URL</label>
                <input type="text" name="video_url" class="form-control-c" placeholder="https://">
            </div>
        </div>
    </div>

    <!-- Step 3: Category & Terms -->
    <div class="ev-section" data-section="3">
        <div class="row">
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Organizer Name<span class="required">*</span></label>
                <input type="text" name="organizer_name" value="<?php echo h($organiserDefault); ?>" class="form-control-c">
                <small class="cell-sub">Prefilled from your partner profile — feel free to change it.</small>
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Special Entry Details</label>
                <input type="text" name="special_entry_details" class="form-control-c">
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Event Category</label>
                <div class="select-with-add">
                    <select name="categories[]" id="categories" multiple style="width:100%;"></select>
                    <button type="button" class="btn-mini" id="addCategoryBtn" title="Add a new category"><i class="fa-solid fa-plus"></i></button>
                </div>
            </div>
            <div class="col-lg-6 ev-field">
                <label class="form-label-c">Artist / Performer</label>
                <div class="select-with-add">
                    <select name="artists[]" id="artists" multiple style="width:100%;"></select>
                    <button type="button" class="btn-mini" id="addArtistBtn" title="Add a new artist"><i class="fa-solid fa-plus"></i></button>
                </div>
            </div>
            <div class="col-lg-12 ev-field">
                <label class="form-label-c">Terms &amp; Conditions</label>
                <textarea class="form-control-c" name="terms" id="terms-editor" rows="8"><?php echo h($defaultTerms); ?></textarea>
            </div>
        </div>
    </div>

    <!-- Step 4: Tickets -->
    <div class="ev-section" data-section="4">
        <div class="ticket-editor" id="ticketEditor">
            <?php
            $seedTickets = $event_type === 'ticket'
                ? [['Early Bird', 100, '', '0']]
                : [['Stags', 100, 'Each pass permits one stag to the event.'], ['Ladies', 100, 'Each pass permits one lady to the event.'], ['Couple', 100, 'Each pass permits one couple to the event.']];
            foreach ($seedTickets as $i => $t):
            ?>
            <div class="ticket-card">
                <span class="remove-ticket" onclick="this.closest('.ticket-card').remove()">Remove</span>
                <input type="text" name="ticket_name[]" placeholder="Ticket name" class="form-control-c" value="<?php echo h($t[0]); ?>">
                <input type="number" min="0" name="ticket_tickets[]" placeholder="Number of tickets" class="form-control-c" value="<?php echo (int) $t[1]; ?>">
                <?php if ($event_type === 'ticket'): ?>
                <input type="number" min="0" step="0.01" name="ticket_cost[]" placeholder="Price (₹)" class="form-control-c" value="<?php echo h($t[3] ?? '0'); ?>">
                <?php endif; ?>
                <textarea name="ticket_desc[]" placeholder="Description" class="form-control-c"><?php echo h($t[2] ?? ''); ?></textarea>
            </div>
            <?php endforeach; ?>
            <div class="add-ticket-card" id="addTicketBtn"><i class="fa-solid fa-plus"></i>Add ticket type</div>
        </div>
    </div>

    <div class="ev-form-nav">
        <button type="button" class="btn-ghost-c" id="prevStep" style="visibility:hidden;"><i class="fa-solid fa-arrow-left"></i> Back</button>
        <button type="button" class="btn-pink-sm" id="nextStep">Next <i class="fa-solid fa-arrow-right"></i></button>
        <button type="submit" name="save" class="btn-pink-sm" id="submitBtn" style="display:none;"><i class="fa-solid fa-check"></i> Submit for Approval</button>
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

    // multiple dates toggle
    var multiSelect = document.getElementById('multipledates');
    var multiContainer = document.getElementById('date-picker-container');
    multiSelect.addEventListener('change', function () {
        multiContainer.style.display = this.value === '1' ? 'block' : 'none';
    });

    // ticket editor add/remove
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
            // image is optional
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

// The #start/#end inputs are `readonly`, so the only way to set a value is
// through this calendar+time popup - no typing possible.
flatpickr("#start", {
    enableTime: true,
    dateFormat: "Y-m-d H:i",
    minDate: "today",
    time_24hr: true,
    onChange: function (selectedDates, dateStr) {
        endPicker.set('minDate', dateStr);
    }
});
var endPicker = flatpickr("#end", {
    enableTime: true,
    dateFormat: "Y-m-d H:i",
    minDate: "today",
    time_24hr: true
});
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>