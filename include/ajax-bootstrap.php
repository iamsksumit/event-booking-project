<?php
/**
 * include/ajax-bootstrap.php
 * -----------------------------------------------------------------------
 * Include this FIRST, before anything else, at the top of any AJAX
 * endpoint that must always respond with JSON (search.php,
 * load-more-events.php, etc.).
 *
 * Problem it solves: if config.php (or anything it pulls in) triggers a
 * fatal error, a warning that gets echoed, or any stray output at all,
 * the browser gets back invalid JSON — fetch()'s .json() call throws,
 * and the front-end shows a generic "Something went wrong" with zero
 * information about what actually broke.
 *
 * This file:
 *   1) Sends the JSON content-type immediately.
 *   2) Buffers ALL output from this point on.
 *   3) Registers a shutdown handler that, if a fatal error killed the
 *      script, discards whatever half-finished HTML/text was buffered
 *      and replaces it with a clean JSON error object instead —
 *      including the real PHP error message, so you can see exactly
 *      what broke straight from the Network tab / console.
 *
 * Usage in an endpoint:
 *     require_once __DIR__ . '/../include/ajax-bootstrap.php';
 *     require_once __DIR__ . '/../include/config.php';
 *     ... build $payload ...
 *     hs_ajax_success($payload);   // discards buffer, echoes clean JSON, exits
 *
 * If something throws inside your own code, wrap it:
 *     try { ... } catch (Throwable $e) { hs_ajax_error($e->getMessage()); }
 * -----------------------------------------------------------------------
 */

if (!defined('HS_AJAX_BOOTSTRAPPED')) {
    define('HS_AJAX_BOOTSTRAPPED', true);

    // Don't let PHP print raw warnings/notices into the response body —
    // they'll be captured by the output buffer below and stripped anyway,
    // but this avoids them leaking into server-level output too.
    ini_set('display_errors', '0');

    header('Content-Type: application/json; charset=utf-8');
    ob_start();

    register_shutdown_function(function () {
        $err = error_get_last();
        $isFatal = $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);

        if ($isFatal) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'error' => 'Server error',
                'debug' => $err['message'] . ' in ' . basename($err['file']) . ' on line ' . $err['line'],
            ]);
        }
    });
}

/** Discards any buffered/stray output and sends clean JSON. Always exits. */
function hs_ajax_success(array $payload): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    echo json_encode($payload);
    exit;
}

/** Same, but for an error response — also sets the HTTP status. */
function hs_ajax_error(string $message, int $status = 500, array $extra = []): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($status);
    echo json_encode(array_merge(['error' => 'Request failed', 'debug' => $message], $extra));
    exit;
}
