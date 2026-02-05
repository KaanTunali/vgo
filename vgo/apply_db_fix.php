<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/zones_seed.php';

echo "DB zone fix starting...\n";

$stats = vgo_seed_core_zones($conn);
echo "Deactivated: " . ($stats['deactivated'] ?? 0) . "\n";
echo "Inserted: " . $stats['inserted'] . "\n\n";

echo "Current counts:\n";
$res = $conn->query("SELECT city, COUNT(*) as cnt FROM Zones WHERE city IN ('Istanbul', 'Ankara', 'İzmir') GROUP BY city");
while ($row = $res->fetch_assoc()) {
    echo $row['city'] . ': ' . $row['cnt'] . "\n";
}

echo "\nDone.\n";
?>
