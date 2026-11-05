<?php
include '../include/config.php';
header("Content-Type: application/xml; charset=utf-8");

$baseUrl = "https://highspinn.com/";

$sql = "SELECT name, url, updated_at FROM artist ORDER BY updated_at DESC";
$result = $conn->query($sql);

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

while ($row = $result->fetch_assoc()) {
    $loc = $baseUrl . 'artist/' . $row['url'];
    $lastmod = date("Y-m-d", strtotime($row['updated_at']));
    echo "<url>
    <loc>{$loc}</loc>
    <lastmod>{$lastmod}</lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
</url>\n";
}

echo "</urlset>";
exit;
