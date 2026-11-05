<?php
include_once('../include/config.php');

$term = isset($_GET['term']) ? trim($_GET['term']) : '';
$type = isset($_GET['type']) ? $_GET['type'] : '';

/* Whitelist type -> table/column. Never build SQL from raw request input. */
$map = [
    'place'    => ['table' => 'place',    'column' => 'name',     'extra_where' => ' AND status = 1'],
    'category' => ['table' => 'category', 'column' => 'cat_name', 'extra_where' => ''],
    'artist'   => ['table' => 'artist',   'column' => 'name',     'extra_where' => ' AND status = 1'],
];

if (!isset($map[$type])) {
    echo json_encode(['results' => []]);
    exit();
}

$table  = $map[$type]['table'];
$column = $map[$type]['column'];
$extra  = $map[$type]['extra_where'];

$sql = "SELECT id, $column AS label FROM $table WHERE $column LIKE ? $extra ORDER BY $column LIMIT 20";
$stmt = $conn->prepare($sql);
$likeTerm = '%' . $term . '%';
$stmt->bind_param('s', $likeTerm);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = [
        'id'   => $row['id'],
        'text' => $row['label'],
    ];
}

header('Content-Type: application/json');
echo json_encode(['results' => $data]);
