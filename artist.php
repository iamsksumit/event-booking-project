<?php
/**
 * index.php — single entry point.
 * Chooses the desktop or mobile view.
 *
 * Priority:
 *   1. ?view=mobile | ?view=desktop   (manual override, remembered in a cookie)
 *   2. Cookie from a previous override
 *   3. X-HighSpinn-App header          (your native app webview => mobile)
 *   4. User-Agent sniffing             (phones => mobile, everything else => desktop)
 */

function hs_is_mobile_request(): bool {
    // 1. Manual override via query string
    if (isset($_GET['view']) && in_array($_GET['view'], ['mobile', 'desktop'], true)) {
        setcookie('hs_view', $_GET['view'], time() + 30 * 86400, '/');
        return $_GET['view'] === 'mobile';
    }

    // 2. Remembered override
    if (isset($_COOKIE['hs_view']) && in_array($_COOKIE['hs_view'], ['mobile', 'desktop'], true)) {
        return $_COOKIE['hs_view'] === 'mobile';
    }

    // 3. Your native app always gets the app layout
    if (($_SERVER['HTTP_X_HIGHSPINN_APP'] ?? '') === 'true') {
        return true;
    }

    // 4. User agent: phones only (tablets and iPads get the desktop layout)
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua)) return false;
    if (preg_match('/Android.*Mobile|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini|IEMobile|Mobi/i', $ua)) return true;

    return false;
}

// The same URL now returns different HTML, so tell caches/CDNs
header('Vary: User-Agent, Cookie, X-HighSpinn-App');

if (hs_is_mobile_request()) {
    require __DIR__ . '/mobile-artist.php';
} else {
    require __DIR__ . '/artistdesk.php';
}