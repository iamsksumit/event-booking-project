<?php
// sitemap_index.php
error_reporting(0);
ini_set('display_errors', 0);

include 'include/config.php'; // Your DB connection

$baseUrl = "https://highspinn.com/"; // Base URL
header("Content-Type: application/xml; charset=utf-8");

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo "\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";


// 2. Events sitemap
echo "<sitemap>
    <loc>{$baseUrl}sitemap/events</loc>
    <lastmod>" . date("Y-m-d") . "</lastmod>
</sitemap>\n";


// 3. Places sitemap
echo "<sitemap>
    <loc>{$baseUrl}sitemap/places</loc>
    <lastmod>" . date("Y-m-d") . "</lastmod>
</sitemap>\n";

// 4. Blogs sitemap (if you have blogs table)
echo "<sitemap>
    <loc>{$baseUrl}sitemap/blogs</loc>
    <lastmod>" . date("Y-m-d") . "</lastmod>
</sitemap>\n";

// 5. Artists sitemap
echo "<sitemap>
    <loc>{$baseUrl}sitemap/artists</loc>
    <lastmod>" . date("Y-m-d") . "</lastmod>
</sitemap>\n";

// 1. Pages sitemap
echo "<sitemap>
    <loc>{$baseUrl}sitemap/pages</loc>
    <lastmod>" . date("Y-m-d") . "</lastmod>
</sitemap>\n";



// 6. Organisers sitemap (if you have organisers table)
// echo "<sitemap>
//     <loc>{$baseUrl}sitemap/organisers</loc>
//     <lastmod>" . date("Y-m-d") . "</lastmod>
// </sitemap>\n";

echo "</sitemapindex>";
exit;
