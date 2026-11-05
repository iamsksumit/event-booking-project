<?php
include '../include/config.php';
header("Content-Type: application/xml; charset=utf-8");

$baseUrl = "https://highspinn.com/";

// Fetch all places, even if they have no events
$sql = "SELECT p.name, p.url, 
        COALESCE(MAX(e.created_at), '2025-01-01') AS last_event_created
        FROM place p
        LEFT JOIN events e ON e.sub_tittle = p.id
        GROUP BY p.id
        ORDER BY last_event_created DESC";

$result = $conn->query($sql);

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

while ($row = $result->fetch_assoc()) {
    $loc = $baseUrl . 'places/' . $row['url'];
    $lastmod = date("Y-m-d", strtotime($row['last_event_created']));
    echo "<url>
    <loc>{$loc}</loc>
    <lastmod>{$lastmod}</lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
</url>\n";
}

echo "</urlset>";
exit;
