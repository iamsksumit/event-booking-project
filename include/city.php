<?php
/**
 * include/city.php
 * ---------------------------------------------------------------------
 * Resolves the "current city" for the request and exposes it as:
 *   $currentCityId, $currentCityName, $currentCitySlug, $allCities
 * plus a helper hs_city_url($path) for building /{city-slug}/... links.
 *
 * Include this once, early, on every page that needs city context
 * (it is already pulled in automatically by include/header.php).
 *
 * City slugs are derived from tbl_cities.name (no separate slug column
 * exists yet), e.g. "Bangalore" -> "bangalore", "New Delhi" -> "new-delhi".
 * .htaccess passes the requested slug in as $_GET['city'].
 * ---------------------------------------------------------------------
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('hs_slugify')) {
    function hs_slugify($str) {
        $str = strtolower(trim((string) $str));
        $str = preg_replace('/[^a-z0-9]+/', '-', $str);
        return trim($str, '-');
    }
}

$currentCityId   = null;
$currentCityName = null;
$currentCitySlug = null;
$hs_city_not_found = false;

// ---- Load all cities once (used for the picker modal + slug matching) ----
$allCities = [];
$citiesRes = $conn->query("SELECT id, name, state_id FROM tbl_cities ORDER BY name ASC");
if ($citiesRes) {
    while ($c = $citiesRes->fetch_assoc()) {
        $c['slug'] = hs_slugify($c['name']);
        $allCities[] = $c;
    }
}

// ---- 1. City requested via URL (?city=slug, set by .htaccess rewrite) ----
$requestedSlug = isset($_GET['city']) ? hs_slugify($_GET['city']) : null;

if ($requestedSlug !== null && $requestedSlug !== '') {
    $match = null;
    foreach ($allCities as $c) {
        if ($c['slug'] === $requestedSlug) { $match = $c; break; }
    }

    if ($match) {
        $currentCityId   = $match['id'];
        $currentCityName = $match['name'];
        $currentCitySlug = $match['slug'];

        $_SESSION['hs_city_id']   = $currentCityId;
        $_SESSION['hs_city_name'] = $currentCityName;
        $_SESSION['hs_city_slug'] = $currentCitySlug;
    } else {
        // First segment looked like a city but isn't one we know -> 404
        $hs_city_not_found = true;
    }
}

// ---- 2. Fall back to session (pages hit without a city prefix) ----
if (!$currentCityId && !$hs_city_not_found && !empty($_SESSION['hs_city_id'])) {
    $currentCityId   = $_SESSION['hs_city_id'];
    $currentCityName = $_SESSION['hs_city_name'];
    $currentCitySlug = $_SESSION['hs_city_slug'];
}

// ---- 3. Default fallback (first-ever visit, no session, no URL city) ----
if (!$currentCityId && !$hs_city_not_found && !empty($allCities)) {
    $default = null;
    foreach ($allCities as $c) {
        if (strcasecmp($c['name'], 'Bangalore') === 0) { $default = $c; break; }
    }
    if (!$default) $default = $allCities[0];

    $currentCityId   = $default['id'];
    $currentCityName = $default['name'];
    $currentCitySlug = $default['slug'];
}

// If the URL clearly named an unknown city, show a real 404 instead of
// silently rendering the default city's homepage.
if ($hs_city_not_found) {
    header("HTTP/1.0 404 Not Found");
    if (file_exists(__DIR__ . '/../404.php')) {
        include __DIR__ . '/../404.php';
    } else {
        echo "404 - City not found";
    }
    exit;
}

// Escaped version, safe to drop into hand-built SQL strings.
$currentCityEsc = $conn->real_escape_string((string) $currentCityName);

/**
 * Build a URL scoped to the current (or given) city.
 * hs_city_url()                => /bangalore/
 * hs_city_url('events')        => /bangalore/events/
 * hs_city_url('events/foo')    => /bangalore/events/foo
 */
if (!function_exists('hs_city_url')) {
    function hs_city_url($path = '', $citySlug = null) {
        global $currentCitySlug;
        $slug = $citySlug ?: $currentCitySlug;
        $path = ltrim((string) $path, '/');
        return '/' . $slug . ($path !== '' ? '/' . $path . '/' : '/');
    }
}