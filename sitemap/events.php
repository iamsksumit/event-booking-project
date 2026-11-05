<?php
include '../include/config.php'; // DB connection
header("Content-Type: application/xml; charset=utf-8");

$baseUrl = "https://highspinn.com/";

// Fetch all events
$sql = "SELECT url, start, created_at, status FROM events ORDER BY start DESC";
$result = $conn->query($sql);

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

while ($row = $result->fetch_assoc()) {
    $loc = $baseUrl . 'events/' . $row['url'];
    
    // Use start date if available, fallback to created_at
    $lastmod = !empty($row['start']) ? date("Y-m-d", strtotime($row['start'])) : date("Y-m-d", strtotime($row['created_at']));
    
    // Changefreq based on status (1 = active/upcoming)
    $changefreq = ($row['status'] == 1) ? 'weekly' : 'monthly';
    
    // Priority: higher for upcoming events
    $priority = ($row['status'] == 1) ? '0.9' : '0.7';

    echo "<url>
    <loc>{$loc}</loc>
    <lastmod>{$lastmod}</lastmod>
    <changefreq>{$changefreq}</changefreq>
    <priority>{$priority}</priority>
</url>\n";
}

echo "</urlset>";
exit;
