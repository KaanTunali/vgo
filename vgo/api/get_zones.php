<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

function vgo_has_column(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $has = ($res && $res->num_rows > 0);
    $stmt->close();
    return $has;
}

$city = $_GET['city'] ?? '';
$city = trim($city);

if ($city === '') {
    echo json_encode([]);
    exit;
}

$where = vgo_has_column($conn, 'Zones', 'is_active') ? 'WHERE is_active = 1 AND city = ?' : 'WHERE city = ?';
$stmt = $conn->prepare('SELECT zone_id, zone_name FROM Zones ' . $where . ' ORDER BY zone_name ASC');
$stmt->bind_param('s', $city);
$stmt->execute();
$result = $stmt->get_result();
$zones = [];
while ($row = $result->fetch_assoc()) {
    $zones[] = [
        'zone_id' => (int)$row['zone_id'],
        'zone_name' => $row['zone_name'],
    ];
}
$stmt->close();

// Basit cache kontrolü: sonuç yoksa yine de boş dön
if (empty($zones)) {
    echo json_encode([]);
    exit;
}

echo json_encode($zones, JSON_UNESCAPED_UNICODE);
