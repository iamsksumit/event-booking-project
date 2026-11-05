<?php
include_once('../include/config.php');

$term = $_GET['term'];  // The search term
$type = $_GET['type'];  // The type (place, category, artist)

$column = '';
$table = '';

switch ($type) {
    case 'place':
        $column = 'name';
        $table = 'place';
        break;
    case 'category':
        $column = 'cat_name';
        $table = 'category';
        break;
    case 'artist':
        $column = 'name';
        $table = 'artist';
        break;
}

$sql = "SELECT id, $column FROM $table WHERE $column LIKE '%$term%'";
$result = mysqli_query($conn, $sql);

$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $data[] = [
        'id' => $row['id'],
        'text' => $row[$column],
    ];
}

// Return the data in the correct format for Select2
echo json_encode(['results' => $data]);
?>
