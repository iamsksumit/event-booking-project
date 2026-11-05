<?php
/**
 * include/payouts_helpers.php
 * Shared logic for the Payout System, used by both payouts/index.php and
 * payouts/settings.php (admin + partner views) so the balance/eligibility
 * rules live in exactly one place.
 *
 * Requires $conn (mysqli) to already be set up.
 *
 * Payouts are requested per-event, not as one lump partner balance --
 * see partner_pending_events() / partner_event_balance() below.
 */

/** Platform payout settings from the shared `settings` key/value table. */
function get_payout_settings(mysqli $conn): array
{
    $defaults = [
        'payout_minimum_amount'     => '500',
        'payout_schedule'           => 'weekly',
        'payout_commission_percent' => '10',
        'payout_methods_enabled'    => 'bank,upi',
    ];

    $res = $conn->query("
        SELECT name, value FROM settings
        WHERE name IN (
            'payout_minimum_amount', 'payout_schedule',
            'payout_commission_percent', 'payout_methods_enabled'
        )
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $defaults[$row['name']] = $row['value'];
        }
    }

    $defaults['payout_minimum_amount']     = (float) $defaults['payout_minimum_amount'];
    $defaults['payout_commission_percent'] = (float) $defaults['payout_commission_percent'];
    $defaults['methods_enabled']           = array_filter(array_map('trim', explode(',', $defaults['payout_methods_enabled'])));

    return $defaults;
}

function save_payout_settings(mysqli $conn, array $values): bool
{
    $ok = true;
    $stmt = $conn->prepare("
        INSERT INTO settings (name, value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value)
    ");
    foreach ($values as $name => $value) {
        $stmt->bind_param('ss', $name, $value);
        $ok = $stmt->execute() && $ok;
    }
    $stmt->close();
    return $ok;
}

/**
 * Gross ticket-sale revenue for a partner's events, before commission,
 * one row per event.
 *
 * Matches this schema's actual shape (not a generic amount/status pair):
 *  - payments.event_id is a TEXT slug that matches events.url (not events.id)
 *  - payments.price is a VARCHAR holding the ticket price ('0' for free RSVPs)
 *  - payments has no status column; free RSVPs already carry price '0'
 *    (payment_id = 'FREE'), so summing price directly is safe and simple
 *  - events.user_id is stored as VARCHAR, not INT, so it's bound as a string
 *
 * Returns [event_id => ['event_id' => int, 'title' => string, 'gross' => float]]
 */
function partner_event_gross_revenue(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT e.id AS event_id, e.tittle AS title,
               COALESCE(SUM(CAST(p.price AS DECIMAL(12,2))), 0) AS gross
        FROM events e
        LEFT JOIN payments p ON p.event_id = e.url
        WHERE e.user_id = ?
        GROUP BY e.id, e.tittle
    ");
    $userIdStr = (string) $userId;
    $stmt->bind_param('s', $userIdStr);
    $stmt->execute();
    $res = $stmt->get_result();

    $byEvent = [];
    while ($row = $res->fetch_assoc()) {
        $byEvent[(int) $row['event_id']] = [
            'event_id' => (int) $row['event_id'],
            'title'    => $row['title'],
            'gross'    => (float) $row['gross'],
        ];
    }
    $stmt->close();
    return $byEvent;
}

/** Sum of a partner's payouts that are already paid or in flight, grouped by event_id. */
function partner_committed_payouts_by_event(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT event_id, COALESCE(SUM(amount), 0) AS total
        FROM payouts
        WHERE user_id = ? AND status IN ('paid', 'processing')
        GROUP BY event_id
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();

    $byEvent = [];
    while ($row = $res->fetch_assoc()) {
        $byEvent[(int) $row['event_id']] = (float) $row['total'];
    }
    $stmt->close();
    return $byEvent;
}

/**
 * Every event of this partner's that still has payout money owed on it:
 * net revenue (after commission) minus whatever's already been paid out
 * or is mid-processing for that event. Always recomputed server-side.
 *
 * Returns a list of:
 *   ['event_id', 'title', 'gross', 'net', 'balance', 'meets_minimum']
 * ordered by balance descending, limited to events with balance > 0.
 */
function partner_pending_events(mysqli $conn, int $userId, array $settings): array
{
    $revenueByEvent   = partner_event_gross_revenue($conn, $userId);
    $committedByEvent = partner_committed_payouts_by_event($conn, $userId);
    $commissionRate   = $settings['payout_commission_percent'] / 100;

    $pending = [];
    foreach ($revenueByEvent as $eventId => $row) {
        $gross     = $row['gross'];
        $net       = round($gross * (1 - $commissionRate), 2);
        $committed = $committedByEvent[$eventId] ?? 0.0;
        $balance   = round($net - $committed, 2);

        if ($balance > 0.009) {
            $pending[] = [
                'event_id'      => $eventId,
                'title'         => $row['title'],
                'gross'         => $gross,
                'net'           => $net,
                'balance'       => $balance,
                'meets_minimum' => $balance >= $settings['payout_minimum_amount'],
            ];
        }
    }

    usort($pending, fn($a, $b) => $b['balance'] <=> $a['balance']);
    return $pending;
}

/**
 * Recomputes a single event's pending payout balance for a partner,
 * verifying the event actually belongs to them. Used to validate a
 * payout request server-side -- never trust a client-supplied amount
 * or event ownership.
 */
function partner_event_balance(mysqli $conn, int $userId, int $eventId, array $settings): ?float
{
    $stmt = $conn->prepare("SELECT id FROM events WHERE id = ? AND user_id = ?");
    $userIdStr = (string) $userId;
    $stmt->bind_param('is', $eventId, $userIdStr);
    $stmt->execute();
    $owns = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$owns) {
        return null; // not this partner's event
    }

    foreach (partner_pending_events($conn, $userId, $settings) as $row) {
        if ($row['event_id'] === $eventId) {
            return $row['balance'];
        }
    }
    return 0.0;
}

/** Partner's KYC row (for bank details + kyc_status), or null. */
function partner_kyc_row(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM partner_kyc WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Partner's payout details row (UPI + preferred method), or null. */
function partner_payout_details_row(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM partner_payout_details WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Bundles everything payouts/index.php and payouts/settings.php need to
 * know about a partner's eligibility to request a payout. Payouts are
 * requested per-event, so this surfaces the list of events with money
 * still owed rather than one lump balance.
 */
function partner_payout_eligibility(mysqli $conn, int $userId, array $settings): array
{
    $kyc     = partner_kyc_row($conn, $userId);
    $details = partner_payout_details_row($conn, $userId);

    $hasBank = $kyc
        && trim((string) ($kyc['bank_account_number'] ?? '')) !== ''
        && trim((string) ($kyc['bank_ifsc'] ?? '')) !== '';
    $hasUpi  = $details && trim((string) ($details['upi_id'] ?? '')) !== '';

    $kycApproved   = $kyc && (int) $kyc['kyc_status'] === 1;
    $pendingEvents = partner_pending_events($conn, $userId, $settings);
    $requestableEvents = array_values(array_filter($pendingEvents, fn($e) => $e['meets_minimum']));
    $totalPending  = round(array_sum(array_column($pendingEvents, 'balance')), 2);

    $availableMethods = [];
    if ($hasBank && in_array('bank', $settings['methods_enabled'], true)) $availableMethods[] = 'bank';
    if ($hasUpi && in_array('upi', $settings['methods_enabled'], true))   $availableMethods[] = 'upi';

    $blockers = [];
    if (!$kycApproved) {
        $blockers[] = 'Your KYC has not been approved yet.';
    }
    if (empty($availableMethods)) {
        $blockers[] = 'Add a bank or UPI payout method on file.';
    }
    if (empty($pendingEvents)) {
        $blockers[] = 'No events with pending payout money yet.';
    } elseif (empty($requestableEvents)) {
        $blockers[] = 'No single event has reached the ' . format_inr($settings['payout_minimum_amount']) . ' minimum yet.';
    }

    return [
        'kyc'                 => $kyc,
        'details'             => $details,
        'has_bank'            => $hasBank,
        'has_upi'             => $hasUpi,
        'kyc_approved'        => $kycApproved,
        'pending_events'      => $pendingEvents,
        'requestable_events'  => $requestableEvents,
        'total_pending'       => $totalPending,
        'available_methods'   => $availableMethods,
        'can_request'         => empty($blockers),
        'blockers'            => $blockers,
    ];
}

function format_inr(float $amount): string
{
    return '₹' . number_format($amount, 2);
}

/** Basic UPI ID format check, e.g. name@bank. */
function is_valid_upi_id(string $upi): bool
{
    return (bool) preg_match('/^[\w.\-]{2,256}@[a-zA-Z]{2,64}$/', $upi);
}