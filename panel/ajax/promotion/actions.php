<?php
/**
 * ajax/promotion/actions.php
 *
 * Promotion AJAX dispatcher.
 *
 * Actual URL:
 *     /ajax/promotion/actions
 *
 * Supported actions:
 *
 * GET:
 *     ?action=search-partners
 *     ?action=get-event-dates
 *
 * POST:
 *     ?action=create-order
 *     ?action=verify-payment
 *     ?action=toggle-package
 *
 * The action can also be supplied inside JSON:
 *
 * {
 *     "action": "create-order"
 * }
 */

require_once __DIR__ . '/../../include/session_guard.php';
require_once __DIR__ . '/../../include/config.php';
require_once __DIR__ . '/../../include/promo_helpers.php';

date_default_timezone_set('Asia/Kolkata');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');


/* ================================================================
 * JSON RESPONSE HELPER
 * ================================================================ */

function hs_promotion_json(
    array $data,
    int $status = 200
) {
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* ================================================================
 * READ JSON BODY
 * ================================================================ */

$jsonInput = [];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    $rawBody =
        file_get_contents(
            'php://input'
        );


    if (
        $rawBody !== false &&
        trim($rawBody) !== ''
    ) {

        $decoded =
            json_decode(
                $rawBody,
                true
            );


        if (
            is_array($decoded)
        ) {

            $jsonInput =
                $decoded;

        }

    }

}


/* ================================================================
 * GET ACTION
 *
 * IMPORTANT:
 *
 * We check the URL first.
 *
 * Examples:
 *
 * /ajax/promotion/actions?action=create-order
 *
 * /ajax/promotion/actions?action=get-event-dates
 *
 * This avoids the "Unknown action" problem when JSON body parsing
 * is affected by server/rewrite configuration.
 * ================================================================ */

$action = '';


if (
    isset($_GET['action']) &&
    $_GET['action'] !== ''
) {

    $action =
        trim(
            (string)
            $_GET['action']
        );

}


/* ================================================================
 * POST FORM ACTION
 * ================================================================ */

if (
    $action === '' &&
    isset($_POST['action']) &&
    $_POST['action'] !== ''
) {

    $action =
        trim(
            (string)
            $_POST['action']
        );

}


/* ================================================================
 * JSON ACTION
 * ================================================================ */

if (
    $action === '' &&
    isset($jsonInput['action']) &&
    $jsonInput['action'] !== ''
) {

    $action =
        trim(
            (string)
            $jsonInput['action']
        );

}


/* ================================================================
 * NORMALIZE ACTION
 * ================================================================ */

$action =
    strtolower(
        $action
    );


/* ================================================================
 * NO ACTION
 * ================================================================ */

if (
    $action === ''
) {

    hs_promotion_json(
        [
            'error' =>
                'Unknown action.',

            'debug' => [

                'method' =>
                    $_SERVER[
                        'REQUEST_METHOD'
                    ] ?? '',

                'request_uri' =>
                    $_SERVER[
                        'REQUEST_URI'
                    ] ?? '',

                'get_action' =>
                    $_GET['action'] ?? null,

                'post_action' =>
                    $_POST['action'] ?? null,

                'json_action' =>
                    $jsonInput['action'] ?? null

            ]

        ],
        400
    );

}


/* ================================================================
 * DISPATCH
 * ================================================================ */

switch ($action) {


    /* ============================================================
     * SEARCH PARTNERS
     *
     * ADMIN ONLY
     * ============================================================ */

    case 'search-partners':

        if (
            $role !== 'admin'
        ) {

            hs_promotion_json(
                [
                    'results' => []
                ],
                403
            );

        }


        $q =
            trim(
                $_GET['q'] ?? ''
            );


        $results = [];


        if (
            $q !== ''
        ) {

            $like =
                '%' .
                $q .
                '%';


            $stmt =
                $conn->prepare("
                    SELECT
                        id,
                        fname,
                        lname,
                        email
                    FROM partner
                    WHERE user_type = 2
                      AND (
                            fname LIKE ?
                            OR lname LIKE ?
                            OR email LIKE ?
                      )
                    ORDER BY fname ASC
                    LIMIT 20
                ");


            if (!$stmt) {

                hs_promotion_json(
                    [
                        'error' =>
                            'Could not search partners.'
                    ],
                    500
                );

            }


            $stmt->bind_param(
                'sss',
                $like,
                $like,
                $like
            );


            $stmt->execute();


            $result =
                $stmt->get_result();


            while (
                $row =
                    $result->fetch_assoc()
            ) {

                $name =
                    trim(
                        ($row['fname'] ?? '') .
                        ' ' .
                        ($row['lname'] ?? '')
                    );


                if (
                    $name === ''
                ) {

                    $name =
                        'Partner #' .
                        $row['id'];

                }


                $results[] = [

                    'id' =>
                        (int)
                        $row['id'],

                    'text' =>
                        $name .
                        ' (' .
                        ($row['email'] ?? '') .
                        ')'

                ];

            }

        }


        hs_promotion_json(
            [
                'results' =>
                    $results
            ]
        );

        break;


    /* ============================================================
     * GET EVENT DATES
     *
     * PARTNER ONLY
     *
     * SINGLE DATE:
     *     events.start
     *
     * MULTIPLE DATE:
     *     events.multiple_dates
     *
     * ONLY LIVE EVENTS:
     *     event_status = 1
     *     status       = 1
     * ============================================================ */

    case 'get-event-dates':

        if (
            $role !== 'partner'
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'Not allowed.'
                ],
                403
            );

        }


        $eventId =
            (int)
            (
                $_GET['event_id'] ??
                0
            );


        $packageId =
            (int)
            (
                $_GET['package_id'] ??
                0
            );


        if (
            $eventId <= 0 ||
            $packageId <= 0
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'Invalid event or package.'
                ],
                422
            );

        }


        /* ========================================================
         * PACKAGE
         * ======================================================== */

        $package =
            promo_validate_package_for_partner(
                $conn,
                $packageId,
                $userId
            );


        if (!$package) {

            hs_promotion_json(
                [
                    'error' =>
                        'This promotion package is not available for your account.'
                ],
                403
            );

        }


        /* ========================================================
         * LIVE EVENT + OWNER
         * ======================================================== */

        $eventStmt =
            $conn->prepare("
                SELECT
                    id,
                    tittle,
                    url,
                    start,
                    end,
                    multiple_dates,
                    event_status,
                    status,
                    user_id
                FROM events
                WHERE id = ?
                  AND user_id = ?
                  AND event_status = 1
                  AND status = 1
                LIMIT 1
            ");


        if (!$eventStmt) {

            hs_promotion_json(
                [
                    'error' =>
                        'Could not validate event.'
                ],
                500
            );

        }


        $eventStmt->bind_param(
            'ii',
            $eventId,
            $userId
        );


        $eventStmt->execute();


        $event =
            $eventStmt
                ->get_result()
                ->fetch_assoc();


        if (!$event) {

            hs_promotion_json(
                [
                    'error' =>
                        'Invalid or inactive event selected.'
                ],
                403
            );

        }


        /* ========================================================
         * PARSE EVENT DATES
         *
         * promo_event_dates() should return:
         *
         * [
         *     'is_multi' => true/false,
         *     'dates' => [
         *         [
         *             'raw' => '2026-08-20',
         *             'label' => '20 Aug 2026'
         *         ]
         *     ]
         * ]
         * ======================================================== */

        $parsed =
            promo_event_dates(
                $event
            );


        $eventDates =
            $parsed['dates'] ?? [];


        if (
            !is_array($eventDates) ||
            count($eventDates) === 0
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'This event does not have any valid dates.'
                ],
                422
            );

        }


        $isMulti =
            !empty(
                $parsed['is_multi']
            );


        $allowMultipleDates =
            (
                (int)
                (
                    $package[
                        'allow_multiple_dates'
                    ] ?? 0
                ) === 1
            );


        $needsSelection =
            $isMulti &&
            !$allowMultipleDates;


        $appliesToAll =
            $isMulti &&
            $allowMultipleDates;


        $dates = [];


        foreach (
            $eventDates as $date
        ) {

            if (
                empty($date['raw'])
            ) {

                continue;

            }


            $dates[] = [

                'value' =>
                    $date['raw'],

                'label' =>
                    $date['label'] ??
                    $date['raw']

            ];

        }


        if (
            count($dates) === 0
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'No valid event dates found.'
                ],
                422
            );

        }


        hs_promotion_json(
            [

                'ok' =>
                    true,

                'event_id' =>
                    $eventId,

                'package_id' =>
                    $packageId,

                'is_multi' =>
                    $isMulti,

                'needs_selection' =>
                    $needsSelection,

                'applies_to_all' =>
                    $appliesToAll,

                'dates' =>
                    $dates,

                'package_price' =>
                    $package[
                        'package_price'
                    ],

                'duration_type' =>
                    $package[
                        'duration_type'
                    ],

                'duration_days' =>
                    $package[
                        'duration_days'
                    ] ?? null

            ]
        );

        break;


    /* ============================================================
     * CREATE RAZORPAY ORDER
     *
     * PARTNER ONLY
     *
     * URL:
     *
     * POST /ajax/promotion/actions?action=create-order
     * ============================================================ */

    case 'create-order': {

    if ($role !== 'partner') {

        http_response_code(403);

        echo json_encode([
            'error' => 'Not allowed.'
        ]);

        break;
    }


    $input =
        $jsonInput ?? [];


    $packageId =
        (int) (
            $input['package_id'] ?? 0
        );


    $eventId =
        (int) (
            $input['event_id'] ?? 0
        );


    $submittedDate =
        isset($input['event_date']) &&
        $input['event_date'] !== ''
            ? (string) $input['event_date']
            : null;



    /*
     * ============================================================
     * PACKAGE
     * ============================================================
     */

    $package =
        promo_validate_package_for_partner(
            $conn,
            $packageId,
            $userId
        );


    if (!$package) {

        http_response_code(403);

        echo json_encode([
            'error' =>
                'This promotion package is not available for your account.'
        ]);

        break;
    }



    /*
     * ============================================================
     * EVENT
     * ============================================================
     */

    $event =
        promo_validate_event_ownership(
            $conn,
            $eventId,
            $userId
        );


    if (!$event) {

        http_response_code(403);

        echo json_encode([
            'error' =>
                'Invalid event selected.'
        ]);

        break;
    }



    /*
     * ============================================================
     * RESOLVE EVENT DATES
     *
     * Single date:
     *      events.start
     *
     * Multiple dates:
     *      events.multiple_dates
     *
     * Package allowing multiple dates:
     *      all dates
     *
     * Package not allowing multiple dates:
     *      selected date
     * ============================================================
     */

    $resolved =
        promo_resolve_dates(
            $event,
            $package,
            $submittedDate
        );


    if (
        !$resolved['ok']
    ) {

        http_response_code(422);

        echo json_encode([
            'error' =>
                $resolved['error']
        ]);

        break;
    }



    /*
     * ============================================================
     * PRICE
     * ============================================================
     */

    $price =
        (float)
        $package['package_price'];


    if (
        $price <= 0
    ) {

        http_response_code(422);

        echo json_encode([
            'error' =>
                'This package does not have a valid price configured.'
        ]);

        break;
    }



    /*
     * ============================================================
     * PARTNER
     * ============================================================
     */

    $uStmt =
        $conn->prepare("
            SELECT
                fname,
                lname,
                email,
                contactno
            FROM partner
            WHERE id = ?
            LIMIT 1
        ");


    if (!$uStmt) {

        http_response_code(500);

        echo json_encode([
            'error' =>
                'Could not load partner details.'
        ]);

        break;
    }


    $uStmt->bind_param(
        'i',
        $userId
    );


    $uStmt->execute();


    $partnerRow =
        $uStmt
            ->get_result()
            ->fetch_assoc()
            ?: [];


    $customerName =
        trim(
            ($partnerRow['fname'] ?? '') .
            ' ' .
            ($partnerRow['lname'] ?? '')
        );


    $customerEmail =
        (string)
        ($partnerRow['email'] ?? '');


    $customerMobile =
        (string)
        ($partnerRow['contactno'] ?? '');



    /*
     * ============================================================
     * RESOLVED DATES
     * ============================================================
     */

    $selectedDatesText =
        promo_dates_to_text(
            $resolved['selected_dates']
        );


    $appliesToAll =
        $resolved['applies_to_all_dates']
            ? 1
            : 0;


    $eventDateForDisplay =
        (string)
        $resolved['final_occurrence']['raw'];


    $durationType =
        (string)
        $package['duration_type'];


    $durationDays =
        $package['duration_type'] === 'fixed'
            ? (int) $package['duration_days']
            : null;


    $priceStr =
        (string)
        $price;


    $packageName =
        (string)
        $package['package_name'];



    /*
     * ============================================================
     * CREATE INTERNAL ORDER
     * ============================================================
     */

    $stmt =
        $conn->prepare("
            INSERT INTO boost_package_orders
            (
                order_id,
                razorpay_payment_id,
                status,
                customername,
                email,
                mobile,
                price,
                package_name,
                package_id,
                eventid,
                partnerid,
                event_date,
                applies_to_all_dates,
                selected_dates,
                duration_type,
                duration_days,
                created_at
            )
            VALUES
            (
                0,
                NULL,
                'created',
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                NOW()
            )
        ");


    if (!$stmt) {

        http_response_code(500);

        echo json_encode([
            'error' =>
                'Could not prepare promotion order: ' .
                $conn->error
        ]);

        break;
    }



    /*
     * 13 variables
     *
     * s = string
     * i = integer
     *
     * 1  s customerName
     * 2  s customerEmail
     * 3  s customerMobile
     * 4  s priceStr
     * 5  s packageName
     * 6  i packageId
     * 7  i eventId
     * 8  i userId
     * 9  s eventDateForDisplay
     * 10 i appliesToAll
     * 11 s selectedDatesText
     * 12 s durationType
     * 13 i durationDays
     */

    $stmt->bind_param(
        'sssssiiisissi',

        $customerName,
        $customerEmail,
        $customerMobile,
        $priceStr,
        $packageName,

        $packageId,
        $eventId,
        $userId,

        $eventDateForDisplay,

        $appliesToAll,

        $selectedDatesText,

        $durationType,

        $durationDays
    );


    if (!$stmt->execute()) {

        http_response_code(500);

        echo json_encode([
            'error' =>
                'Could not create promotion order: ' .
                $stmt->error
        ]);

        break;
    }


    $orderId =
        (int)
        $stmt->insert_id;


    if (
        $orderId <= 0
    ) {

        http_response_code(500);

        echo json_encode([
            'error' =>
                'Promotion order was not created.'
        ]);

        break;
    }



    /*
     * ============================================================
     * SET order_id
     * ============================================================
     */

    $selfRefStmt =
        $conn->prepare("
            UPDATE boost_package_orders
            SET order_id = ?
            WHERE id = ?
        ");


    if (!$selfRefStmt) {

        http_response_code(500);

        echo json_encode([
            'error' =>
                'Could not update promotion order reference.'
        ]);

        break;
    }


    $selfRefStmt->bind_param(
        'ii',
        $orderId,
        $orderId
    );


    $selfRefStmt->execute();



    /*
     * ============================================================
     * RAZORPAY ORDER
     * ============================================================
     */

    $receipt =
        'promo-' .
        $orderId;


    $rzpOrder =
        promo_create_razorpay_order(
            $conn,
            $price,
            $receipt
        );


    if (
        isset($rzpOrder['error'])
    ) {

        $failStmt =
            $conn->prepare("
                UPDATE boost_package_orders
                SET status = 'failed'
                WHERE id = ?
            ");


        if ($failStmt) {

            $failStmt->bind_param(
                'i',
                $orderId
            );

            $failStmt->execute();

        }


        http_response_code(502);

        echo json_encode([
            'error' =>
                $rzpOrder['error']
        ]);

        break;
    }



    /*
     * ============================================================
     * SAVE RAZORPAY ORDER ID
     * ============================================================
     */

    $rzpOrderId =
        (string)
        $rzpOrder['id'];


    $stmt2 =
        $conn->prepare("
            UPDATE boost_package_orders
            SET razorpay_order_id = ?
            WHERE id = ?
        ");


    if (!$stmt2) {

        http_response_code(500);

        echo json_encode([
            'error' =>
                'Could not save Razorpay order.'
        ]);

        break;
    }


    $stmt2->bind_param(
        'si',
        $rzpOrderId,
        $orderId
    );


    $stmt2->execute();



    /*
     * ============================================================
     * RAZORPAY KEY
     * ============================================================
     */

    $keys =
        promo_get_razorpay_keys(
            $conn
        );



    /*
     * ============================================================
     * RESPONSE
     * ============================================================
     */

    echo json_encode([

        'success' =>
            true,

        'order_id' =>
            $orderId,

        'razorpay_order_id' =>
            $rzpOrderId,

        'razorpay_key' =>
            $keys['razorpay_api_key'],

        'amount' =>
            (int)
            round(
                $price * 100
            ),

        'package_name' =>
            $package['package_name'],

        'prefill' => [

            'name' =>
                $customerName,

            'email' =>
                $customerEmail,

            'contact' =>
                $customerMobile

        ]

    ]);

    break;
}

        /* ========================================================
         * LIVE EVENT + OWNER
         * ======================================================== */

        $eventStmt =
            $conn->prepare("
                SELECT
                    id,
                    tittle,
                    url,
                    start,
                    end,
                    multiple_dates,
                    event_status,
                    status,
                    user_id
                FROM events
                WHERE id = ?
                  AND user_id = ?
                  AND event_status = 1
                  AND status = 1
                LIMIT 1
            ");


        if (!$eventStmt) {

            hs_promotion_json(
                [
                    'error' =>
                        'Could not validate event.'
                ],
                500
            );

        }


        $eventStmt->bind_param(
            'ii',
            $eventId,
            $userId
        );


        $eventStmt->execute();


        $event =
            $eventStmt
                ->get_result()
                ->fetch_assoc();


        if (!$event) {

            hs_promotion_json(
                [
                    'error' =>
                        'Invalid or inactive event selected.'
                ],
                403
            );

        }


        /* ========================================================
         * RESOLVE DATE SERVER-SIDE
         *
         * IMPORTANT:
         *
         * Do not trust date coming from JavaScript.
         * ======================================================== */

        $resolved =
            promo_resolve_dates(
                $event,
                $package,
                $submittedDate
            );


        if (
            !is_array($resolved) ||
            empty($resolved['ok'])
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        $resolved['error'] ??
                        'Invalid promotion date.'
                ],
                422
            );

        }


        $price =
            (float)
            $package[
                'package_price'
            ];


        if (
            $price <= 0
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'This package does not have a valid price configured.'
                ],
                422
            );

        }


        /* ========================================================
         * PARTNER INFORMATION
         * ======================================================== */

        $uStmt =
            $conn->prepare("
                SELECT
                    fname,
                    lname,
                    email,
                    contactno
                FROM partner
                WHERE id = ?
                LIMIT 1
            ");


        if (!$uStmt) {

            hs_promotion_json(
                [
                    'error' =>
                        'Could not load partner information.'
                ],
                500
            );

        }


        $uStmt->bind_param(
            'i',
            $userId
        );


        $uStmt->execute();


        $partnerRow =
            $uStmt
                ->get_result()
                ->fetch_assoc();


        if (!$partnerRow) {

            $partnerRow = [];

        }


        $customerName =
            trim(
                ($partnerRow['fname'] ?? '') .
                ' ' .
                ($partnerRow['lname'] ?? '')
            );


        $customerEmail =
            $partnerRow['email'] ??
            '';


        $customerMobile =
            $partnerRow['contactno'] ??
            '';


        /* ========================================================
         * PROMOTION DATE INFORMATION
         * ======================================================== */

        $selectedDates =
            $resolved[
                'selected_dates'
            ] ?? [];


        $selectedDatesText =
            promo_dates_to_text(
                $selectedDates
            );


        $appliesToAll =
            !empty(
                $resolved[
                    'applies_to_all_dates'
                ]
            )
                ? 1
                : 0;


        $finalOccurrence =
            $resolved[
                'final_occurrence'
            ] ?? null;


        if (
            !$finalOccurrence
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'Could not determine promotion date.'
                ],
                422
            );

        }


        $eventDateForDisplay =
            $finalOccurrence[
                'raw'
            ] ?? '';


        $durationType =
            $package[
                'duration_type'
            ] ?? 'fixed';


        $durationDays =
            (
                $durationType === 'fixed'
            )
                ? (int)
                    (
                        $package[
                            'duration_days'
                        ] ?? 0
                    )
                : null;


        /* ========================================================
         * CREATE INTERNAL ORDER
         * ======================================================== */
$stmt = $conn->prepare("
    INSERT INTO boost_package_orders
    (
        order_id,
        razorpay_payment_id,
        status,
        customername,
        email,
        mobile,
        price,
        package_name,
        package_id,
        eventid,
        partnerid,
        event_date,
        applies_to_all_dates,
        selected_dates,
        duration_type,
        duration_days,
        created_at
    )
    VALUES
    (
        0,
        NULL,
        'created',
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        NOW()
    )
");

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        'error' => 'Could not prepare promotion order: ' . $conn->error
    ]);

    break;
}


/*
 * IMPORTANT
 *
 * Variables:
 *
 * 1  customerName       = string
 * 2  customerEmail      = string
 * 3  customerMobile     = string
 * 4  priceStr           = string
 * 5  packageName        = string
 * 6  packageId          = integer
 * 7  eventId            = integer
 * 8  userId             = integer
 * 9  eventDateForDisplay= string
 * 10 appliesToAll       = integer
 * 11 selectedDatesText  = string
 * 12 durationType       = string
 * 13 durationDays       = integer
 *
 * Therefore:
 *
 * sssssiiisis si
 *
 * EXACT TYPE STRING:
 *
 * sssssiiisissi
 */

$priceStr =
    (string) $price;

$packageName =
    (string) $package['package_name'];

$eventDateForDisplay =
    (string) $resolved['final_occurrence']['raw'];

$appliesToAll =
    $resolved['applies_to_all_dates']
        ? 1
        : 0;

$selectedDatesText =
    (string) $selectedDatesText;

$durationType =
    (string) $durationType;


/*
 * duration_days can be NULL.
 *
 * mysqli bind_param() with "i" accepts NULL,
 * so keep it as integer type.
 */

$durationDays =
    $durationDays === null
        ? null
        : (int) $durationDays;


$stmt->bind_param(
    'sssssiiisissi',

    $customerName,
    $customerEmail,
    $customerMobile,
    $priceStr,
    $packageName,

    $packageId,
    $eventId,
    $userId,

    $eventDateForDisplay,

    $appliesToAll,

    $selectedDatesText,

    $durationType,

    $durationDays
);


if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([
        'error' =>
            'Could not create promotion order: ' .
            $stmt->error
    ]);

    break;
}


$orderId =
    (int) $stmt->insert_id;


if ($orderId <= 0) {

    http_response_code(500);

    echo json_encode([
        'error' =>
            'Promotion order was not created.'
    ]);

    break;
}
       
        /* ========================================================
         * INTERNAL ORDER ID
         * ======================================================== */

        $selfRefStmt =
            $conn->prepare("
                UPDATE boost_package_orders
                SET order_id = ?
                WHERE id = ?
            ");


        if ($selfRefStmt) {

            $selfRefStmt->bind_param(
                'ii',
                $orderId,
                $orderId
            );


            $selfRefStmt->execute();

        }


        /* ========================================================
         * RAZORPAY
         * ======================================================== */

        $receipt =
            'promo-' .
            $orderId;


        $rzpOrder =
            promo_create_razorpay_order(
                $conn,
                $price,
                $receipt
            );


        if (
            !is_array($rzpOrder) ||
            empty(
                $rzpOrder['id']
            )
        ) {

            $failStmt =
                $conn->prepare("
                    UPDATE boost_package_orders
                    SET status = 'failed'
                    WHERE id = ?
                ");


            if ($failStmt) {

                $failStmt->bind_param(
                    'i',
                    $orderId
                );


                $failStmt->execute();

            }


            hs_promotion_json(
                [
                    'error' =>
                        $rzpOrder['error'] ??
                        'Could not create Razorpay order.'
                ],
                502
            );

        }


        $razorpayOrderId =
            $rzpOrder['id'];


        /* ========================================================
         * SAVE RAZORPAY ORDER ID
         * ======================================================== */

        $stmt2 =
            $conn->prepare("
                UPDATE boost_package_orders
                SET razorpay_order_id = ?
                WHERE id = ?
            ");


        if (!$stmt2) {

            hs_promotion_json(
                [
                    'error' =>
                        'Could not save Razorpay order.'
                ],
                500
            );

        }


        $stmt2->bind_param(
            'si',
            $razorpayOrderId,
            $orderId
        );


        if (
            !$stmt2->execute()
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'Could not save Razorpay order ID.'
                ],
                500
            );

        }


        /* ========================================================
         * RAZORPAY KEY
         * ======================================================== */

        $keys =
            promo_get_razorpay_keys(
                $conn
            );


        if (
            empty(
                $keys[
                    'razorpay_api_key'
                ]
            )
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'Razorpay API key is not configured.'
                ],
                500
            );

        }


        /* ========================================================
         * RESPONSE
         * ======================================================== */

        hs_promotion_json(
            [

                'ok' =>
                    true,

                'order_id' =>
                    $orderId,

                'razorpay_order_id' =>
                    $razorpayOrderId,

                'razorpay_key' =>
                    $keys[
                        'razorpay_api_key'
                    ],

                'amount' =>
                    (int)
                    round(
                        $price * 100
                    ),

                'package_name' =>
                    $packageName,

                'prefill' => [

                    'name' =>
                        $customerName,

                    'email' =>
                        $customerEmail,

                    'contact' =>
                        $customerMobile

                ]

            ]
        );

        break;


    /* ============================================================
     * VERIFY PAYMENT
     *
     * PARTNER ONLY
     * ============================================================ */

    case 'verify-payment':

        if (
            $role !== 'partner'
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'Not allowed.'
                ],
                403
            );

        }


        $input =
            $jsonInput;


        $orderId =
            (int)
            (
                $input[
                    'order_id'
                ] ?? 0
            );


        $razorpayOrderId =
            trim(
                (string)
                (
                    $input[
                        'razorpay_order_id'
                    ] ?? ''
                )
            );


        $razorpayPaymentId =
            trim(
                (string)
                (
                    $input[
                        'razorpay_payment_id'
                    ] ?? ''
                )
            );


        $razorpaySignature =
            trim(
                (string)
                (
                    $input[
                        'razorpay_signature'
                    ] ?? ''
                )
            );


        if (
            $orderId <= 0 ||
            $razorpayOrderId === '' ||
            $razorpayPaymentId === '' ||
            $razorpaySignature === ''
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'Incomplete payment information.'
                ],
                422
            );

        }


        /* ========================================================
         * LOAD ORDER
         * ======================================================== */

        $stmt =
            $conn->prepare("
                SELECT *
                FROM boost_package_orders
                WHERE id = ?
                  AND partnerid = ?
                LIMIT 1
            ");


        if (!$stmt) {

            hs_promotion_json(
                [
                    'error' =>
                        'Could not load payment order.'
                ],
                500
            );

        }


        $stmt->bind_param(
            'ii',
            $orderId,
            $userId
        );


        $stmt->execute();


        $order =
            $stmt
                ->get_result()
                ->fetch_assoc();


        if (!$order) {

            hs_promotion_json(
                [
                    'error' =>
                        'Order not found.'
                ],
                404
            );

        }


        /* ========================================================
         * ALREADY PAID
         * ======================================================== */

        if (
            $order['status'] === 'paid'
        ) {

            hs_promotion_json(
                [
                    'redirect' =>
                        '/promotion/payment-success.php?order_id=' .
                        $orderId,

                    'already_processed' =>
                        true
                ]
            );

        }


        /* ========================================================
         * CHECK RAZORPAY ORDER
         * ======================================================== */

        if (
            (string)
            $order[
                'razorpay_order_id'
            ] !==
            $razorpayOrderId
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'Order mismatch.'
                ],
                400
            );

        }


        /* ========================================================
         * VERIFY RAZORPAY SIGNATURE
         * ======================================================== */

        $valid =
            promo_verify_razorpay_signature(
                $conn,
                $razorpayOrderId,
                $razorpayPaymentId,
                $razorpaySignature
            );


        if (!$valid) {

            $failStmt =
                $conn->prepare("
                    UPDATE boost_package_orders
                    SET
                        status = 'failed',
                        razorpay_payment_id = ?
                    WHERE id = ?
                ");


            if ($failStmt) {

                $failStmt->bind_param(
                    'si',
                    $razorpayPaymentId,
                    $orderId
                );


                $failStmt->execute();

            }


            notify_user(
                $conn,
                $userId,
                'promotion',
                'Promotion Payment Failed',
                'Your payment for ' .
                $order[
                    'package_name'
                ] .
                ' could not be completed.',
                '/promotion'
            );


            hs_promotion_json(
                [
                    'error' =>
                        'Payment verification failed.',

                    'redirect' =>
                        '/promotion/payment-failed.php?order_id=' .
                        $orderId
                ],
                400
            );

        }


        /* ========================================================
         * LOAD EVENT
         * ======================================================== */

        $eventId =
            (int)
            $order[
                'eventid'
            ];


        $eventStmt =
            $conn->prepare("
                SELECT
                    id,
                    tittle,
                    url,
                    start,
                    end,
                    multiple_dates,
                    event_status,
                    status,
                    user_id
                FROM events
                WHERE id = ?
                  AND user_id = ?
                  AND event_status = 1
                  AND status = 1
                LIMIT 1
            ");


        if (!$eventStmt) {

            hs_promotion_json(
                [
                    'error' =>
                        'Could not validate event.'
                ],
                500
            );

        }


        $eventStmt->bind_param(
            'ii',
            $eventId,
            $userId
        );


        $eventStmt->execute();


        $event =
            $eventStmt
                ->get_result()
                ->fetch_assoc();


        /* ========================================================
         * PACKAGE
         * ======================================================== */

        $pkgStmt =
            $conn->prepare("
                SELECT *
                FROM boost_packages
                WHERE id = ?
                LIMIT 1
            ");


        if (!$pkgStmt) {

            hs_promotion_json(
                [
                    'error' =>
                        'Could not validate package.'
                ],
                500
            );

        }


        $packageId =
            (int)
            $order[
                'package_id'
            ];


        $pkgStmt->bind_param(
            'i',
            $packageId
        );


        $pkgStmt->execute();


        $package =
            $pkgStmt
                ->get_result()
                ->fetch_assoc();


        if (
            !$event ||
            !$package
        ) {

            $failStmt =
                $conn->prepare("
                    UPDATE boost_package_orders
                    SET status = 'failed'
                    WHERE id = ?
                ");


            if ($failStmt) {

                $failStmt->bind_param(
                    'i',
                    $orderId
                );


                $failStmt->execute();

            }


            hs_promotion_json(
                [
                    'error' =>
                        'Event or package is no longer available.',

                    'redirect' =>
                        '/promotion/payment-failed.php?order_id=' .
                        $orderId
                ],
                422
            );

        }


        /* ========================================================
         * RECONSTRUCT PROMOTION DATES
         * ======================================================== */

        $reconstructedDates =
            promo_reconstruct_dates_from_text(
                $event,
                $order[
                    'selected_dates'
                ]
            );


        if (
            !is_array(
                $reconstructedDates
            ) ||
            count(
                $reconstructedDates
            ) === 0
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'Could not reconstruct promotion dates.',

                    'redirect' =>
                        '/promotion/payment-failed.php?order_id=' .
                        $orderId
                ],
                422
            );

        }


        $lastDate =
            end(
                $reconstructedDates
            );


        $resolved = [

            'applies_to_all_dates' =>
                (
                    (int)
                    $order[
                        'applies_to_all_dates'
                    ] === 1
                ),

            'selected_dates' =>
                $reconstructedDates,

            'final_occurrence' =>
                $lastDate

        ];


        /* ========================================================
         * CALCULATE EXPIRY
         * ======================================================== */

        $expiry =
            promo_compute_expiry(
                $package,
                $resolved
            );


        /* ========================================================
         * TRANSACTION
         * ======================================================== */

        $conn->begin_transaction();


        try {

            /* ====================================================
             * MARK ORDER PAID
             * ==================================================== */

            $updStmt =
                $conn->prepare("
                    UPDATE boost_package_orders
                    SET
                        status = 'paid',
                        razorpay_payment_id = ?
                    WHERE id = ?
                      AND status != 'paid'
                ");


            if (!$updStmt) {

                throw new Exception(
                    'Could not update payment order.'
                );

            }


            $updStmt->bind_param(
                'si',
                $razorpayPaymentId,
                $orderId
            );


            $updStmt->execute();


            if (
                $updStmt->affected_rows === 0
            ) {

                $conn->rollback();


                hs_promotion_json(
                    [
                        'redirect' =>
                            '/promotion/payment-success.php?order_id=' .
                            $orderId,

                        'already_processed' =>
                            true
                    ]
                );

            }


            /* ====================================================
             * CREATE ACTIVE PROMOTION
             * ==================================================== */

            $insStmt =
                $conn->prepare("
                    INSERT INTO partner_promotions
                    (
                        partner_id,
                        package_id,
                        order_id,
                        event_id,
                        event_url,
                        package_name,
                        price,
                        duration_type,
                        applies_to_all_dates,
                        selected_dates,
                        status,
                        started_at,
                        expires_at
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'active',
                        ?,
                        ?
                    )
                ");


            if (!$insStmt) {

                throw new Exception(
                    'Could not create promotion.'
                );

            }


            $eventUrl =
                $event[
                    'url'
                ] ?? '';


            $packageName =
                $order[
                    'package_name'
                ];


            $promotionPrice =
                $order[
                    'price'
                ];


            $durationType =
                $package[
                    'duration_type'
                ];


            $appliesToAll =
                (int)
                $order[
                    'applies_to_all_dates'
                ];


            $selectedDatesText =
                $order[
                    'selected_dates'
                ];


            $startedAt =
                $expiry[
                    'started_at'
                ];


            $expiresAt =
                $expiry[
                    'expires_at'
                ];


            $insStmt->bind_param(
                'iiiissssisss',
                $userId,
                $order['package_id'],
                $orderId,
                $order['eventid'],
                $eventUrl,
                $packageName,
                $promotionPrice,
                $durationType,
                $appliesToAll,
                $selectedDatesText,
                $startedAt,
                $expiresAt
            );


            if (
                !$insStmt->execute()
            ) {

                throw new Exception(
                    'Could not insert promotion.'
                );

            }


            $conn->commit();

        } catch (
            Throwable $e
        ) {

            $conn->rollback();


            error_log(
                'HighSpinn promotion payment error: ' .
                $e->getMessage()
            );


            hs_promotion_json(
                [
                    'error' =>
                        'Could not finalise the promotion. Please contact support.',

                    'redirect' =>
                        '/promotion/payment-failed.php?order_id=' .
                        $orderId
                ],
                500
            );

        }


        /* ========================================================
         * EVENT TITLE
         * ======================================================== */

        $eventTitle =
            $event[
                'tittle'
            ] ??
            'your event';


        /* ========================================================
         * PARTNER NOTIFICATION
         * ======================================================== */

        notify_user(
            $conn,
            $userId,
            'promotion',
            'Promotion Purchased',
            'Your ' .
            $order[
                'package_name'
            ] .
            ' promotion has been successfully purchased for ' .
            $eventTitle .
            '.',
            '/promotion/mine'
        );


        /* ========================================================
         * ADMIN NOTIFICATION
         * ======================================================== */

        $partnerNameStmt =
            $conn->prepare("
                SELECT
                    fname,
                    lname
                FROM partner
                WHERE id = ?
                LIMIT 1
            ");


        $partnerName =
            'Partner #' .
            $userId;


        if ($partnerNameStmt) {

            $partnerNameStmt->bind_param(
                'i',
                $userId
            );


            $partnerNameStmt->execute();


            $partnerNameRow =
                $partnerNameStmt
                    ->get_result()
                    ->fetch_assoc();


            if ($partnerNameRow) {

                $partnerName =
                    trim(
                        ($partnerNameRow['fname'] ?? '') .
                        ' ' .
                        ($partnerNameRow['lname'] ?? '')
                    );


                if (
                    $partnerName === ''
                ) {

                    $partnerName =
                        'Partner #' .
                        $userId;

                }

            }

        }


        notify_role(
            $conn,
            'admin',
            'promotion',
            'New Promotion Purchase',
            $partnerName .
            ' purchased ' .
            $order[
                'package_name'
            ] .
            ' for ' .
            $eventTitle .
            '. Amount: ₹' .
            $order[
                'price'
            ],
            '/promotion/orders.php'
        );


        /* ========================================================
         * SUCCESS
         * ======================================================== */

        hs_promotion_json(
            [
                'ok' =>
                    true,

                'redirect' =>
                    '/promotion/payment-success.php?order_id=' .
                    $orderId
            ]
        );

        break;


    /* ============================================================
     * TOGGLE PACKAGE
     *
     * ADMIN ONLY
     * ============================================================ */

    case 'toggle-package':

        if (
            $role !== 'admin'
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'Not allowed.'
                ],
                403
            );

        }


        $input =
            $jsonInput;


        $packageId =
            (int)
            (
                $input[
                    'package_id'
                ] ?? 0
            );


        if (
            $packageId <= 0
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'Invalid package.'
                ],
                422
            );

        }


        $stmt =
            $conn->prepare("
                UPDATE boost_packages
                SET
                    status =
                        IF(
                            status = 1,
                            0,
                            1
                        ),
                    updated_at = NOW()
                WHERE id = ?
            ");


        if (!$stmt) {

            hs_promotion_json(
                [
                    'error' =>
                        'Could not update package.'
                ],
                500
            );

        }


        $stmt->bind_param(
            'i',
            $packageId
        );


        if (
            !$stmt->execute()
        ) {

            hs_promotion_json(
                [
                    'error' =>
                        'Could not update package.'
                ],
                500
            );

        }


        $rowStmt =
            $conn->prepare("
                SELECT
                    status
                FROM boost_packages
                WHERE id = ?
                LIMIT 1
            ");


        if (!$rowStmt) {

            hs_promotion_json(
                [
                    'error' =>
                        'Could not read package status.'
                ],
                500
            );

        }


        $rowStmt->bind_param(
            'i',
            $packageId
        );


        $rowStmt->execute();


        $row =
            $rowStmt
                ->get_result()
                ->fetch_assoc();


        hs_promotion_json(
            [

                'ok' =>
                    true,

                'status' =>
                    (int)
                    (
                        $row[
                            'status'
                        ] ?? 0
                    )

            ]
        );

        break;


    /* ============================================================
     * UNKNOWN ACTION
     * ============================================================ */

    default:

        hs_promotion_json(
            [
                'error' =>
                    'Unknown action.',

                'received_action' =>
                    $action,

                'method' =>
                    $_SERVER[
                        'REQUEST_METHOD'
                    ] ?? '',

                'request_uri' =>
                    $_SERVER[
                        'REQUEST_URI'
                    ] ?? ''
            ],
            400
        );

        break;

}