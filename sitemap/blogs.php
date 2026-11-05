<?php
include '../include/config.php'; // Adjust path if needed
header("Content-Type: application/xml; charset=utf-8");

$baseUrl = "https://highspinn.com/";

// Fetch published blogs
$sql = "SELECT id, title, url, created_at FROM blog WHERE publish_status = 1 ORDER BY created_at DESC";
$result = $conn->query($sql);

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

while ($row = $result->fetch_assoc()) {
    // Assuming you have a blog URL structure like /blog/{id}-{slug}
    $slug = isset($row['url']) && !empty($row['url']) ? $row['url'] : "blog-" . $row['id'];
    $loc = $baseUrl . 'blog/' . $slug;
    
    // Use created_at if updated_at is empty
    $lastmod = !empty($row['updated_at']) ? $row['updated_at'] : $row['created_at'];
    $lastmod = date("Y-m-d", strtotime($lastmod));
    
    echo "<url>
    <loc>{$loc}</loc>
    <lastmod>{$lastmod}</lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.7</priority>
</url>\n";
}

echo "</urlset>";
exit;
