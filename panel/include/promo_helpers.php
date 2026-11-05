<?php
/**
 * include/promo_helpers.php
 * Shared logic for the Promotion / Boost Package system.
 * Requires $conn (mysqli) — include AFTER include/config.php.
 *
 * Event dates live as delimited text on events.multiple_dates (no
 * normalised dates table in this schema) — promo_event_dates() parses
 * that into a clean array; everything else builds on top of it.
 */

if (!function_exists('promo_event_dates')) {
    /** Normalises an event's date(s) to ['is_multi'=>bool,'dates'=>[['raw','label','start_ts','end_ts']]]. */
    function promo_event_dates(array $event): array
    {
        $raw = trim((string) ($event['multiple_dates'] ?? ''));

        if ($raw === '') {
            $startTs = strtotime($event['start']);
            $endTs   = strtotime($event['end']) ?: $startTs;
            return ['is_multi' => false, 'dates' => [[
                'raw' => date('Y-m-d', $startTs), 'label' => date('d M Y', $startTs),
                'start_ts' => $startTs, 'end_ts' => $endTs,
            ]]];
        }

        $entries = [];
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            foreach ($decoded as $item) {
                if (is_array($item)) {
                    $d = $item['date'] ?? $item['start'] ?? reset($item);
                    $e = $item['end'] ?? $item['end_date'] ?? null;
                } else {
                    $d = $item; $e = null;
                }
                if (!$d) continue;
                $entries[] = ['date' => trim((string) $d), 'end' => $e ? trim((string) $e) : null];
            }
        } else {
            foreach (preg_split('/[,\n\r]+/', $raw) as $p) {
                $p = trim($p);
                if ($p === '') continue;
                $entries[] = ['date' => $p, 'end' => null];
            }
        }

        $timeOfDayStart = date('H:i:s', strtotime($event['start']));
        $timeOfDayEnd   = date('H:i:s', strtotime($event['end']));

        $dates = [];
        foreach ($entries as $entry) {
            $ts = strtotime($entry['date']);
            if ($ts === false) continue;
            $dateOnly = date('Y-m-d', $ts);
            $startTs  = strtotime($dateOnly . ' ' . $timeOfDayStart);
            $endTs    = $entry['end'] ? strtotime($entry['end']) : strtotime($dateOnly . ' ' . $timeOfDayEnd);
            if ($endTs < $startTs) $endTs = strtotime('+1 day', $endTs);
            $dates[] = ['raw' => $entry['date'], 'label' => date('d M Y', $startTs), 'start_ts' => $startTs, 'end_ts' => $endTs];
        }
        usort($dates, fn($a, $b) => $a['start_ts'] <=> $b['start_ts']);

        if (empty($dates)) {
            $startTs = strtotime($event['start']);
            $endTs   = strtotime($event['end']) ?: $startTs;
            return ['is_multi' => false, 'dates' => [[
                'raw' => date('Y-m-d', $startTs), 'label' => date('d M Y', $startTs),
                'start_ts' => $startTs, 'end_ts' => $endTs,
            ]]];
        }

        return ['is_multi' => count($dates) > 1, 'dates' => $dates];
    }
}

if (!function_exists('promo_find_date')) {
    function promo_find_date(array $parsedDates, string $rawValue): ?array
    {
        foreach ($parsedDates['dates'] as $d) {
            if ($d['raw'] === $rawValue) return $d;
        }
        return null;
    }
}

if (!function_exists('promo_validate_package_for_partner')) {
    /** Partner self-service visibility check: general or personalised-to-them, and active. */
    function promo_validate_package_for_partner(mysqli $conn, int $packageId, int $partnerId): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM boost_packages WHERE id = ? AND status = 1 AND (user_id = 0 OR user_id = ?) LIMIT 1");
        $stmt->bind_param('ii', $packageId, $partnerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('promo_get_package')) {
    /** Plain package fetch, no visibility check — for admin flows that bypass partner restrictions. */
    function promo_get_package(mysqli $conn, int $packageId): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM boost_packages WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $packageId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('promo_validate_event_ownership')) {
    /** Live + approved event belonging to $partnerId, or null. Works for both partner self-checks and admin-on-behalf-of. */
    function promo_validate_event_ownership(mysqli $conn, int $eventId, int $partnerId): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM events WHERE id = ? AND user_id = ? AND event_status = 1 AND status = 1 LIMIT 1");
        $partnerIdStr = (string) $partnerId; // events.user_id is varchar
        $stmt->bind_param('is', $eventId, $partnerIdStr);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('promo_get_partner_events')) {
    /** A partner's live + approved events, most recent first. */
    function promo_get_partner_events(mysqli $conn, int $partnerId, int $limit = 200): array
    {
        $stmt = $conn->prepare("SELECT id, tittle, url, start, end, multiple_dates FROM events WHERE user_id = ? AND event_status = 1 AND status = 1 ORDER BY start DESC LIMIT ?");
        $partnerIdStr = (string) $partnerId;
        $stmt->bind_param('si', $partnerIdStr, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

if (!function_exists('promo_partner_contact')) {
    /** Name/email/mobile for prefill + order records. */
    function promo_partner_contact(mysqli $conn, int $partnerId): array
    {
        $stmt = $conn->prepare("SELECT fname, lname, email, contactno FROM partner WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $partnerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $name = trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''));
        return [
            'name'   => $name !== '' ? $name : ('Partner #' . $partnerId),
            'email'  => $row['email'] ?? '',
            'mobile' => $row['contactno'] ?? '',
        ];
    }
}

if (!function_exists('promo_resolve_dates')) {
    /**
     * Server-side date selection matrix:
     *  - single-date event: automatic, no client input trusted
     *  - multi-date + package allows all dates: every date, ignore client input
     *  - multi-date + single-date package: exactly one, validated against the event's real dates
     */
    function promo_resolve_dates(array $event, array $package, ?string $submittedDate): array
    {
        $parsed = promo_event_dates($event);

        if (!$parsed['is_multi']) {
            $only = $parsed['dates'][0];
            return ['ok' => true, 'applies_to_all_dates' => false, 'selected_dates' => [$only], 'final_occurrence' => $only];
        }

        if ((int) $package['allow_multiple_dates'] === 1) {
            $all = $parsed['dates'];
            return ['ok' => true, 'applies_to_all_dates' => true, 'selected_dates' => $all, 'final_occurrence' => $all[count($all) - 1]];
        }

        if ($submittedDate === null || $submittedDate === '') {
            return ['ok' => false, 'error' => 'Please select an event date to promote.'];
        }
        $match = promo_find_date($parsed, $submittedDate);
        if ($match === null) {
            return ['ok' => false, 'error' => 'The selected date does not belong to this event.'];
        }
        return ['ok' => true, 'applies_to_all_dates' => false, 'selected_dates' => [$match], 'final_occurrence' => $match];
    }
}

if (!function_exists('promo_reconstruct_dates_from_text')) {
    /** Rebuilds parsed date entries from a stored selected_dates string, matched against the event's current dates. */
    function promo_reconstruct_dates_from_text(array $event, string $storedText): array
    {
        $parsed = promo_event_dates($event);
        $timeOfDayEnd = date('H:i:s', strtotime($event['end']));

        $entries = [];
        foreach (explode(',', $storedText) as $raw) {
            $raw = trim($raw);
            if ($raw === '') continue;
            $match = promo_find_date($parsed, $raw);
            if ($match) { $entries[] = $match; continue; }
            $ts = strtotime($raw);
            if ($ts === false) continue;
            $entries[] = ['raw' => $raw, 'label' => date('d M Y', $ts), 'start_ts' => $ts, 'end_ts' => strtotime(date('Y-m-d', $ts) . ' ' . $timeOfDayEnd)];
        }
        if (empty($entries)) $entries[] = $parsed['dates'][0];
        usort($entries, fn($a, $b) => $a['start_ts'] <=> $b['start_ts']);
        return $entries;
    }
}

if (!function_exists('promo_compute_expiry')) {
    function promo_compute_expiry(array $package, array $resolved): array
    {
        $startedAt = date('Y-m-d H:i:s');
        if ($package['duration_type'] === 'until_event_end') {
            return ['started_at' => $startedAt, 'expires_at' => date('Y-m-d H:i:s', $resolved['final_occurrence']['end_ts'])];
        }
        $days = (int) ($package['duration_days'] ?? 0);
        if ($days <= 0) $days = 1;
        return ['started_at' => $startedAt, 'expires_at' => date('Y-m-d H:i:s', strtotime("+{$days} days"))];
    }
}

if (!function_exists('promo_dates_to_text')) {
    function promo_dates_to_text(array $dateEntries): string
    {
        return implode(',', array_map(fn($d) => $d['raw'], $dateEntries));
    }
}

if (!function_exists('promo_dates_display')) {
    function promo_dates_display(bool $appliesToAll, string $selectedDatesText): string
    {
        if ($appliesToAll) return 'All Event Dates';
        $parts = array_filter(array_map('trim', explode(',', $selectedDatesText)));
        if (count($parts) <= 1) {
            $only = $parts[0] ?? '';
            $ts = strtotime($only);
            return $ts ? date('d M Y', $ts) : ($only ?: '—');
        }
        return implode(', ', array_map(function ($p) { $ts = strtotime($p); return $ts ? date('d M Y', $ts) : $p; }, $parts));
    }
}

if (!function_exists('promo_status_badge_class')) {
    function promo_status_badge_class(string $status): string
    {
        return match ($status) {
            'active'    => 'badge-pill badge-live',
            'pending'   => 'badge-pill badge-pending',
            'expired', 'cancelled', 'failed' => 'badge-pill badge-past',
            default     => 'badge-pill',
        };
    }
}

if (!function_exists('promo_render_pagination')) {
    function promo_render_pagination(int $page, int $totalPages, int $offset, int $perPage, int $total, array $extraQuery = []): void
    {
        static $cssPrinted = false;
        if ($totalPages <= 1) return;

        unset($extraQuery['page']);
        $qs = http_build_query($extraQuery);
        $qs = $qs !== '' ? $qs . '&' : '';
        $prevPage = max(1, $page - 1);
        $nextPage = min($totalPages, $page + 1);
        ?>
        <div class="pager-c">
          <div class="pager-info">Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $total); ?> of <?php echo number_format($total); ?></div>
          <div class="pager-links">
            <a class="pager-link <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?<?php echo $qs; ?>page=<?php echo $prevPage; ?>"><i class="fa-solid fa-chevron-left"></i></a>
            <?php $start = max(1, $page - 2); $end = min($totalPages, $start + 4); $start = max(1, $end - 4);
              for ($p = $start; $p <= $end; $p++): ?>
              <a class="pager-link <?php echo $p === $page ? 'active' : ''; ?>" href="?<?php echo $qs; ?>page=<?php echo $p; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <a class="pager-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="?<?php echo $qs; ?>page=<?php echo $nextPage; ?>"><i class="fa-solid fa-chevron-right"></i></a>
          </div>
        </div>
        <?php
        if (!$cssPrinted) {
            $cssPrinted = true;
            ?>
            <style>
              .pager-c{ display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.8rem; padding-top:1.1rem; margin-top:1.1rem; border-top:1px solid var(--line); }
              .pager-info{ font-size:.78rem; color:var(--muted-2); font-weight:600; }
              .pager-links{ display:flex; align-items:center; gap:.35rem; }
              .pager-link{ min-width:34px; height:34px; padding:0 .55rem; border-radius:8px; border:1px solid var(--line); background:var(--panel-2); color:var(--muted); font-size:.8rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
              .pager-link:hover{ border-color:var(--pink); color:var(--text); }
              .pager-link.active{ background:var(--pink); border-color:var(--pink); color:#fff; }
              .pager-link.disabled{ opacity:.4; pointer-events:none; }
            </style>
            <?php
        }
    }
}

/* ============================== ACTIVATION (shared by payment + admin grant) ============================== */

if (!function_exists('promo_activate_promotion')) {
    /**
     * Marks an order paid (idempotent) and creates its partner_promotions row.
     * Shared by the real Razorpay flow (verify-payment) and admin comp grants,
     * so there's exactly one place this transaction can go wrong.
     * $orderRow must already have: id, package_id, eventid, partnerid, package_name,
     * price, applies_to_all_dates, selected_dates (set at INSERT time by the caller).
     */
    function promo_activate_promotion(mysqli $conn, array $orderRow, array $event, array $package, array $resolved, string $paymentRef): array
    {
        $orderId = (int) $orderRow['id'];

        // Already activated? Return the existing promotion (idempotent — handles
        // double-fires from Razorpay's handler + a retried verify call).
        $existing = $conn->prepare("SELECT id FROM partner_promotions WHERE order_id = ? LIMIT 1");
        $existing->bind_param('i', $orderId);
        $existing->execute();
        $existingRow = $existing->get_result()->fetch_assoc();
        $existing->close();
        if ($existingRow) {
            return ['ok' => true, 'promotion_id' => (int) $existingRow['id']];
        }

        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("UPDATE boost_package_orders SET status = 'paid', razorpay_payment_id = ? WHERE id = ?");
            if (!$upd) throw new Exception('Could not update order.');
            $upd->bind_param('si', $paymentRef, $orderId);
            $upd->execute();

            $expiry = promo_compute_expiry($package, $resolved);
            $eventUrl = $event['url'] ?? '';
            $applies  = (int) $orderRow['applies_to_all_dates'];

            $ins = $conn->prepare("
                INSERT INTO partner_promotions
                    (partner_id, package_id, order_id, event_id, event_url, package_name, price,
                     duration_type, applies_to_all_dates, selected_dates, status, started_at, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
            ");
            if (!$ins) throw new Exception('Could not insert promotion.');
            $ins->bind_param(
                'iiiissssisss',
                $orderRow['partnerid'], $orderRow['package_id'], $orderId, $orderRow['eventid'], $eventUrl,
                $orderRow['package_name'], $orderRow['price'], $package['duration_type'], $applies,
                $orderRow['selected_dates'], $expiry['started_at'], $expiry['expires_at']
            );
            if (!$ins->execute()) throw new Exception('Could not insert promotion.');

            $promotionId = (int) $ins->insert_id;
            $conn->commit();
            return ['ok' => true, 'promotion_id' => $promotionId];
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('promo_activate_promotion: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not finalise the promotion. Please contact support.'];
        }
    }
}

/* ============================== RAZORPAY ============================== */

if (!function_exists('promo_get_razorpay_keys')) {
    function promo_get_razorpay_keys(mysqli $conn): array
    {
        $keys = ['razorpay_api_key' => '', 'razorpay_api_secret' => ''];
        $res = $conn->query("SELECT name, value FROM settings WHERE name IN ('razorpay_api_key','razorpay_api_secret')");
        if ($res) {
            while ($row = $res->fetch_assoc()) $keys[$row['name']] = $row['value'];
        }
        return $keys;
    }
}

if (!function_exists('promo_create_razorpay_order')) {
    /** Amount in rupees (converted to paise here). Returns decoded response, or ['error'=>...]. */
    function promo_create_razorpay_order(mysqli $conn, float $amountRupees, string $receipt): array
    {
        $keys = promo_get_razorpay_keys($conn);
        if ($keys['razorpay_api_key'] === '' || $keys['razorpay_api_secret'] === '') {
            return ['error' => 'Razorpay is not configured.'];
        }

        $payload = json_encode([
            'amount' => (int) round($amountRupees * 100),
            'currency' => 'INR',
            'receipt' => $receipt,
            'payment_capture' => 1,
        ]);

        $ch = curl_init('https://api.razorpay.com/v1/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERPWD => $keys['razorpay_api_key'] . ':' . $keys['razorpay_api_secret'],
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) return ['error' => 'Could not reach Razorpay: ' . $err];
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || empty($decoded['id'])) {
            return ['error' => $decoded['error']['description'] ?? 'Razorpay order creation failed.'];
        }
        return $decoded;
    }
}

if (!function_exists('promo_verify_razorpay_signature')) {
    function promo_verify_razorpay_signature(mysqli $conn, string $razorpayOrderId, string $razorpayPaymentId, string $razorpaySignature): bool
    {
        $keys = promo_get_razorpay_keys($conn);
        if ($keys['razorpay_api_secret'] === '') return false;
        $expected = hash_hmac('sha256', $razorpayOrderId . '|' . $razorpayPaymentId, $keys['razorpay_api_secret']);
        return hash_equals($expected, $razorpaySignature);
    }
}