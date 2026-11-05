<?php
include '../include/config.php'; // Adjust path if needed
header("Content-Type: application/xml; charset=utf-8");

$baseUrl = "https://highspinn.com/";

// Fetch all pages
$sql = "SELECT slug, created_at FROM page ORDER BY created_at DESC";
$result = $conn->query($sql);

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

while ($row = $result->fetch_assoc()) {
    $loc = $baseUrl . $row['slug']; // page URL
    $lastmod = date("Y-m-d", strtotime($row['created_at'])); // use created_at for lastmod
    echo "<url>
    <loc>{$loc}</loc>
    <lastmod>{$lastmod}</lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.7</priority>
</url>\n";
}

echo "</urlset>";
exit;
