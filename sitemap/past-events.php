<?php
include '../include/config.php';
header("Content-Type: application/xml; charset=utf-8");

$baseUrl = "https://highspinn.com/";

$sql = "SELECT url, updated_at FROM events WHERE status = 0 ORDER BY updated_at DESC";
$result = $conn->query($sql);

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

while ($row = $result->fetch_assoc()) {
    $loc = $baseUrl . 'event/' . $row['url'];
    $lastmod = date("Y-m-d", strtotime($row['updated_at']));
    echo "<url>
    <loc>{$loc}</loc>
    <lastmod>{$lastmod}</lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.8</priority>
</url>\n";
}

echo "</urlset>";
exit;
