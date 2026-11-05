<?php
if (session_status() === PHP_SESSION_NONE) session_start();

include '../include/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Booking now requires a signed-in account. We trust the session for
// who the account holder is -- never the client-posted value -- and the
// booking is separately flagged as being for that account holder
// ("myself") or a different named guest ("someone else").
if (empty($_SESSION['hs_user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please sign in to continue.']);
    exit;
}
$user_id = (int) $_SESSION['hs_user_id'];

$name           = trim($_POST['name'] ?? '');
$email          = trim($_POST['email'] ?? '');
$phone          = trim($_POST['phone'] ?? '');
$gender         = trim($_POST['gender'] ?? '');
$is_self        = isset($_POST['is_self']) && (int) $_POST['is_self'] === 0 ? 0 : 1;
$event_id       = trim($_POST['event_id'] ?? '');
$payment_id     = trim($_POST['payment_id'] ?? '');
$selected_dates = trim($_POST['selected_dates'] ?? '');
$price          = floatval($_POST['price'] ?? 0);
$tickets        = isset($_POST['tickets']) ? json_decode($_POST['tickets'], true) : [];

if ($name === '' || $email === '' || $phone === '' || $event_id === '' || empty($tickets)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

$paymentRef = "HS" . mt_rand(100000, 999999) . time();

mysqli_begin_transaction($conn);

try {
    $stmt = $conn->prepare(
        "INSERT INTO payments (user_id, name, email, phone, gender, is_self, event_id, selected_dates, price, payment_id, pay_ref)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) throw new Exception("Prepare failed (payments): " . $conn->error);
    // Param order:  user_id  name  email  phone  gender  is_self  event_id  selected_dates  price  payment_id  pay_ref
    // Type string:  i        s     s      s      s       i        s         s               d      s           s
    $stmt->bind_param(
        'issssissdss',
        $user_id, $name, $email, $phone, $gender, $is_self, $event_id, $selected_dates, $price, $payment_id, $paymentRef
    );
    if (!$stmt->execute()) throw new Exception("Insert failed (payments): " . $stmt->error);
    $stmt->close();

    foreach ($tickets as $ticketId => $ticket) {
        $ticketId   = (int)$ticketId;
        $ticketName = $ticket['name'] ?? '';
        $qty        = (int)($ticket['qty'] ?? 0);
        $cost       = (float)($ticket['cost'] ?? 0);

        if ($qty <= 0) continue;

        $orderStmt = $conn->prepare(
            "INSERT INTO ticket_orders (payment_ref, ticket_name, quantity, price) VALUES (?, ?, ?, ?)"
        );
        if (!$orderStmt) throw new Exception("Prepare failed (ticket_orders): " . $conn->error);
        $orderStmt->bind_param('ssid', $paymentRef, $ticketName, $qty, $cost);
        if (!$orderStmt->execute()) throw new Exception("Insert failed (ticket_orders) for $ticketName: " . $orderStmt->error);
        $orderStmt->close();

        // Lock the row so concurrent bookings can't oversell the same ticket type
        $checkStmt = $conn->prepare("SELECT tickets_left FROM event_tickets WHERE id = ? FOR UPDATE");
        $checkStmt->bind_param('i', $ticketId);
        $checkStmt->execute();
        $row = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if (!$row) throw new Exception("Ticket ID $ticketId not found.");

        $newLeft = $row['tickets_left'] - $qty;
        if ($newLeft < 0) throw new Exception("Not enough tickets available for $ticketName.");

        $updateStmt = $conn->prepare("UPDATE event_tickets SET tickets_left = ? WHERE id = ?");
        $updateStmt->bind_param('ii', $newLeft, $ticketId);
        if (!$updateStmt->execute()) throw new Exception("Stock update failed for $ticketName: " . $updateStmt->error);
        $updateStmt->close();
    }

    mysqli_commit($conn);

} catch (Exception $e) {
    mysqli_rollback($conn);
    error_log("Booking save failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

// ---- Everything below is "nice to have" — never let it break the booking response ----

$host = preg_replace('/^www\./', '', preg_replace('/^https?:\/\//', '', $_SERVER['HTTP_HOST']));
$download_link = "http://$host/ticket/$paymentRef";
$shortenedUrl = $download_link;

try {
    $ch = curl_init("https://highspinn.com/hsplnn/shorten_api.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["long_url" => $download_link]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!empty($data['short_url'])) $shortenedUrl = $data['short_url'];
} catch (\Throwable $e) {
    error_log("Shortener failed: " . $e->getMessage());
}

// ---------------------------------------------------------------
// Pull event / venue details so the email can show the same
// header info (title, date, time, venue) as the ticket page.
// Non-fatal: if this lookup fails we just fall back to blank
// fields rather than breaking the booking response.
// ---------------------------------------------------------------
$eventTitle = '';
$eventImage = '';
$placeName  = '';
$dateTimeLabel = '';
$timeend = '';

try {
    $evStmt = $conn->prepare(
        "SELECT events.tittle as tittle, events.image as image, events.start as start, events.end as end, place.name as placename
         FROM events
         INNER JOIN place ON events.sub_tittle = place.id
         WHERE events.url = ?
         LIMIT 1"
    );
    if ($evStmt) {
        $evStmt->bind_param('s', $event_id);
        $evStmt->execute();
        $evRow = $evStmt->get_result()->fetch_assoc();
        $evStmt->close();

        if ($evRow) {
            $eventTitle = $evRow['tittle'] ?? '';
            $eventImage = $evRow['image'] ?? '';
            $placeName  = $evRow['placename'] ?? '';

            $startObj = !empty($evRow['start']) ? date_create($evRow['start']) : false;
            $endObj   = !empty($evRow['end'])   ? date_create($evRow['end'])   : false;

            if ($startObj) {
                $dateTimeLabel = date_format($startObj, 'D, d M Y') . " \xC2\xB7 " . date_format($startObj, 'g:i a');
            }
            if ($endObj) {
                $timeend = date_format($endObj, 'g:i a');
            }
        }
    }
} catch (\Throwable $e) {
    error_log("Email event lookup failed: " . $e->getMessage());
}

$selectedDatesLabel = '';
$decodedDates = json_decode($selected_dates, true);
if (is_array($decodedDates) && !empty($decodedDates)) {
    $formattedDates = array_map(function ($d) {
        try {
            return (new DateTime($d))->format('d M Y');
        } catch (\Throwable $e) {
            return $d;
        }
    }, $decodedDates);
    $selectedDatesLabel = implode(', ', $formattedDates);
}

$isFreeBooking = ((float)$price == 0);
$qrCodeUrl = 'https://quickchart.io/qr?text=' . urlencode($paymentRef) . '&size=400';

try {
    $support_email = '';
    $no_reply_email = '';
    $emailStmt = $conn->prepare("SELECT name, value FROM settings WHERE name IN ('support_email','no_reply_email')");
    $emailStmt->execute();
    $emailResult = $emailStmt->get_result();
    while ($r = $emailResult->fetch_assoc()) {
        if ($r['name'] === 'support_email')  $support_email  = $r['value'];
        if ($r['name'] === 'no_reply_email') $no_reply_email = $r['value'];
    }
    $emailStmt->close();

    // Ticket line items, styled like the pass-list rows on the ticket page
    $rowsHtml = '';
    foreach ($tickets as $t) {
        $qty  = (int)($t['qty'] ?? 0);
        $cost = (float)($t['cost'] ?? 0);
        if ($qty <= 0) continue;
        $lineTotal = $cost * $qty;
        $amtLabel = $lineTotal == 0 ? 'Free' : ('&#8377;' . number_format($lineTotal, 2));
        $rowsHtml .= '
            <tr>
                <td style="padding:9px 0;border-bottom:1px solid #26262c;font-size:13px;color:#f5f5f7;">' . htmlspecialchars($t['name']) . ' &times; ' . $qty . '</td>
                <td style="padding:9px 0;border-bottom:1px solid #26262c;font-size:13px;color:#96969f;text-align:right;">' . $amtLabel . '</td>
            </tr>';
    }

    // Guest info strip, matching the .guest-strip block on the ticket page
    $guestCellsHtml = '
        <td style="padding:14px 20px;font-size:11px;color:#96969f;vertical-align:top;">
            <b style="display:block;color:#f5f5f7;font-size:13px;margin-bottom:2px;">' . htmlspecialchars($name) . '</b>Guest name
        </td>';
    if ($phone) {
        $guestCellsHtml .= '
        <td style="padding:14px 20px;font-size:11px;color:#96969f;vertical-align:top;">
            <b style="display:block;color:#f5f5f7;font-size:13px;margin-bottom:2px;">' . htmlspecialchars($phone) . '</b>Phone
        </td>';
    }
    $guestCellsHtml .= '
        <td style="padding:14px 20px;font-size:11px;color:#96969f;vertical-align:top;">
            <b style="display:block;color:#f5f5f7;font-size:13px;margin-bottom:2px;">Online payment</b>Paid via
        </td>';

    $eventThumbHtml = '';
    if ($eventImage) {
        $imgSrc = (strpos($eventImage, 'http') === 0) ? $eventImage : ('https://' . $host . '/' . ltrim($eventImage, '/'));
        $eventThumbHtml = '<td style="width:64px;padding-right:16px;vertical-align:middle;"><img src="' . htmlspecialchars($imgSrc) . '" width="56" height="56" style="border-radius:12px;display:block;object-fit:cover;"></td>';
    }

    $message = '
    <html>
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
    </head>
    <body style="margin:0;padding:0;background:#0a0a0d;font-family:Helvetica,Arial,sans-serif;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0d;padding:32px 12px;">
        <tr>
          <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#131317;border:1px solid #26262c;border-radius:16px;overflow:hidden;">

              <!-- Header / event info -->
              <tr>
                <td style="padding:20px 22px;background:linear-gradient(135deg, rgba(255,61,104,.12), rgba(255,122,69,.06));border-bottom:1px dashed #26262c;">
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                      ' . $eventThumbHtml . '
                      <td style="vertical-align:middle;">
                        <span style="display:inline-block;background:#ff3d68;color:#ffffff;padding:4px 12px;border-radius:20px;font-size:10px;font-weight:bold;letter-spacing:.4px;">Booking ID: ' . htmlspecialchars($paymentRef) . '</span>
                        <h1 style="font-size:19px;margin:8px 0 4px;color:#f5f5f7;font-weight:bold;">' . htmlspecialchars($eventTitle ?: 'Your booking') . '</h1>
                        ' . ($dateTimeLabel ? '<div style="font-size:11px;color:#96969f;margin:2px 0;">' . htmlspecialchars($dateTimeLabel) . ($timeend ? ' &ndash; ' . htmlspecialchars($timeend) : '') . '</div>' : '') . '
                        ' . ($placeName ? '<div style="font-size:11px;color:#96969f;margin:2px 0;">' . htmlspecialchars($placeName) . '</div>' : '') . '
                        ' . ($selectedDatesLabel ? '<div style="font-size:11px;color:#96969f;margin:2px 0;">Valid for: ' . htmlspecialchars($selectedDatesLabel) . '</div>' : '') . '
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>

              <!-- QR + tickets -->
              <tr>
                <td style="padding:20px 22px;">
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="width:150px;text-align:center;vertical-align:top;padding-right:18px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;margin:0 auto;">
                          <tr><td style="padding:10px;"><img src="' . htmlspecialchars($qrCodeUrl) . '" width="130" height="130" alt="Entry QR code" style="display:block;"></td></tr>
                        </table>
                        <div style="font-size:10px;color:#6f6f78;margin-top:6px;font-weight:bold;letter-spacing:.3px;">SCAN AT ENTRY</div>
                      </td>
                      <td style="vertical-align:top;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                          ' . $rowsHtml . '
                        </table>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;border-top:1px solid #26262c;">
                          <tr>
                            <td style="padding-top:10px;font-size:12px;font-weight:bold;color:#f5f5f7;">Total paid</td>
                            <td style="padding-top:10px;text-align:right;font-size:18px;font-weight:bold;color:#ffc94d;">' . ($isFreeBooking ? 'Free' : ('&#8377;' . number_format((float)$price, 2))) . '</td>
                          </tr>
                        </table>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>

              <!-- Guest strip -->
              <tr>
                <td style="background:#1a1a1f;border-top:1px dashed #26262c;">
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>' . $guestCellsHtml . '</tr>
                  </table>
                </td>
              </tr>

              <!-- CTA -->
              <tr>
                <td style="padding:22px;text-align:center;">
                  <a href="' . htmlspecialchars($shortenedUrl) . '" target="_blank" style="display:inline-block;background:#ff3d68;color:#ffffff;text-decoration:none;font-weight:bold;font-size:14px;padding:12px 28px;border-radius:8px;">View your e-pass</a>
                  <p style="font-size:11px;color:#6f6f78;margin:14px 0 0;">Screenshot this email or the ticket page &mdash; no printout needed.</p>
                </td>
              </tr>

              <!-- Footer -->
              <tr>
                <td style="padding:16px 22px;text-align:center;border-top:1px solid #26262c;">
                  <p style="font-size:10px;color:#6f6f78;margin:0;">HighSpinn &middot; Present this e-pass at the venue for entry</p>
                </td>
              </tr>

            </table>
          </td>
        </tr>
      </table>
    </body>
    </html>';

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $no_reply_email\r\n";
    $headers .= "Reply-To: $support_email\r\n";

    @mail($email, "Highspinn Ticket - Payment Ref: $paymentRef", $message, $headers);
} catch (\Throwable $e) {
    error_log("Email send failed: " . $e->getMessage());
}

try {
    $api_key = FAST2SMS_API_KEY;
    $short_url_no_protocol = preg_replace('#^https?://#', '', $shortenedUrl);
    $smsUrl = "https://www.fast2sms.com/dev/bulkV2?"
        . "authorization=$api_key&route=dlt&sender_id=HSPlNN&message=176019"
        . "&variables_values=" . urlencode($name) . "%7C" . urlencode($short_url_no_protocol) . "%7C"
        . "&flash=0&numbers=" . urlencode($phone);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $smsUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ["cache-control: no-cache"],
    ]);
    curl_exec($curl);
    if ($err = curl_error($curl)) error_log("SMS cURL error: $err");
    curl_close($curl);
} catch (\Throwable $e) {
    error_log("SMS send failed: " . $e->getMessage());
}

// ---------------------------------------------------------------
// WhatsApp (Fast2SMS WABA) e-pass confirmation
//
// Uses the approved "highspinn_event_booking_confirmation1" template
// (Fast2SMS message_id 28648 — see WhatsApp Manager > Templates in
// the Fast2SMS panel, or the "Get WABA & Template Details" API).
// Body variables, in order, per the approved template:
//   {{1}} Guest name  {{2}} Booking ref  {{3}} Event   {{4}} Date
//   {{5}} Time        {{6}} Venue        {{7}} Tickets {{8}} Paid
// Header is an Image (QR code) — sent via media_url below.
// Non-fatal: WhatsApp delivery issues never break the booking response.
// ---------------------------------------------------------------

/**
 * Resolve the WABA phone_number_id tied to this Fast2SMS account.
 * Cached to a temp file for 24h so we don't hit the WABA details
 * API on every single booking.
 */
function hs_get_waba_phone_number_id(string $api_key): ?string
{
    $cacheFile = sys_get_temp_dir() . '/hs_f2s_waba_phone_number_id.json';

    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (!empty($cached['phone_number_id'])) {
            return (string) $cached['phone_number_id'];
        }
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://www.fast2sms.com/dev/dlt_manager/whatsapp?type=number",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ["Authorization: $api_key"],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log("WABA phone_number_id lookup cURL error: $err");
        return null;
    }

    $data = json_decode((string) $resp, true);
    $phoneNumberId = $data['data'][0]['phone_number_id'] ?? null;

    if ($phoneNumberId) {
        @file_put_contents($cacheFile, json_encode(['phone_number_id' => $phoneNumberId]));
        return (string) $phoneNumberId;
    }

    error_log("WABA phone_number_id lookup failed, response: " . $resp);
    return null;
}

try {
    // Reuses the same Fast2SMS account/key as the SMS block above.
    $waba_api_key    = FAST2SMS_API_KEY;
    $waba_message_id = FAST2SMS_WHATSAPP_MESSAGE_ID;

    $waba_phone_number_id = hs_get_waba_phone_number_id($waba_api_key);

    if (!$waba_phone_number_id) {
        throw new Exception("Could not resolve WABA phone_number_id — skipping WhatsApp send.");
    }

    // Build the "Tickets" summary, e.g. "General x2, VIP x1"
    $ticketSummaryParts = [];
    foreach ($tickets as $t) {
        $qty = (int)($t['qty'] ?? 0);
        if ($qty <= 0) continue;
        $ticketSummaryParts[] = trim(($t['name'] ?? 'Ticket') . ' x' . $qty);
    }
    $ticketsLabel = $ticketSummaryParts ? implode(', ', $ticketSummaryParts) : '1 pass';

    $paidLabel = $isFreeBooking ? 'Free' : ('Rs. ' . number_format((float)$price, 2));

    $waDateLabel = isset($startObj) && $startObj ? date_format($startObj, 'd M Y') : '';
    $waTimeLabel = isset($startObj) && $startObj ? date_format($startObj, 'g:i a') : '';

    // WhatsApp renders *text* as bold — wrap every dynamic value so the
    // whole substituted message shows up bold, per the template design.
    $wb = fn($v) => $v !== '' ? '*' . $v . '*' : '';

    // highspinn_event_booking_confirmation1 is a straight 8-variable body
    // (no separate {#var#} header slot): {{1}} name, {{2}}..{{8}} the rest.
    $waVariables = [
        $wb($name),                       // {{1}} Guest name
        $wb($paymentRef),                 // {{2}} Booking
        $eventTitle ?: 'Your event', // {{3}} Event
        $wb($waDateLabel),                // {{4}} Date
        $wb($waTimeLabel),                // {{5}} Time
        $placeName,                  // {{6}} Venue
        $wb($ticketsLabel),               // {{7}} Tickets
        $wb($paidLabel),                  // {{8}} Paid
    ];

    // Template has an Image header ("attached QR code") — must pass
    // media_url so Meta actually attaches the QR to the message.
    // quickchart.io's QR endpoint only serves PNG/SVG (no native JPEG
    // option), so this uses PNG — visually identical to a JPG for a
    // scannable QR code and accepted by WhatsApp as image media.
    $waQrMediaUrl = 'https://quickchart.io/qr?text=' . urlencode($paymentRef) . '&format=png&size=400';

    $waUrl = "https://www.fast2sms.com/dev/whatsapp?"
        . "message_id=" . urlencode((string) $waba_message_id)
        . "&phone_number_id=" . urlencode($waba_phone_number_id)
        . "&numbers=" . urlencode($phone)
        . "&variables_values=" . urlencode(implode('|', $waVariables))
        . "&media_url=" . urlencode($waQrMediaUrl);

    $waCurl = curl_init();
    curl_setopt_array($waCurl, [
        CURLOPT_URL => $waUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ["Authorization: $waba_api_key"],
    ]);
    $waResp = curl_exec($waCurl);
    if ($waErr = curl_error($waCurl)) error_log("WhatsApp cURL error: $waErr");
    curl_close($waCurl);

    error_log("WhatsApp send response for $paymentRef: " . $waResp);
} catch (\Throwable $e) {
    error_log("WhatsApp send failed: " . $e->getMessage());
}

echo json_encode(['success' => true, 'pay_ref' => $paymentRef]);