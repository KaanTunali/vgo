<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function ident(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function resolveTable(mysqli $conn, string $preferredName): ?string {
    $sql = "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $preferredName);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ? (string)$row['TABLE_NAME'] : null;
}

function hasColumn(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) AND LOWER(COLUMN_NAME) = LOWER(?) LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

$zonesTable = resolveTable($conn, 'Zones');
$customersTable = resolveTable($conn, 'Customers');
$couriersTable = resolveTable($conn, 'Couriers');
$addressesTable = resolveTable($conn, 'Customer_Addresses');
$merchantsTable = resolveTable($conn, 'Merchants');
$productsTable = resolveTable($conn, 'Products');

if (!$zonesTable) {
    http_response_code(500);
    echo "Zones table not found (checked case-insensitively for 'Zones').\n";
    exit;
}

$zonesHasActive = hasColumn($conn, $zonesTable, 'is_active');
$customersHasZoneId = $customersTable ? hasColumn($conn, $customersTable, 'zone_id') : false;
$couriersHasCurrentZoneId = $couriersTable ? hasColumn($conn, $couriersTable, 'current_zone_id') : false;
$couriersHasZoneId = $couriersTable ? hasColumn($conn, $couriersTable, 'zone_id') : false;
$addressesHasZoneId = $addressesTable ? hasColumn($conn, $addressesTable, 'zone_id') : false;

$zonesSql = 'SELECT zone_id, city, zone_name FROM ' . ident($zonesTable)
    . ($zonesHasActive ? ' WHERE is_active = 1' : '')
    . ' ORDER BY city, zone_name';
$zonesRes = $conn->query($zonesSql);
$zones = [];
while ($row = $zonesRes->fetch_assoc()) {
    $zones[] = [
        'zone_id' => (int)$row['zone_id'],
        'city' => $row['city'],
        'zone_name' => $row['zone_name'],
    ];
}

$totalZones = count($zones);
$okZones = 0;
$badZones = [];

// Prepared statements for counts
$stmtMerchants = null;
if ($merchantsTable) {
    $stmtMerchants = $conn->prepare('SELECT COUNT(*) AS cnt FROM ' . ident($merchantsTable) . ' WHERE zone_id = ?');
}

$stmtProducts = null;
if ($productsTable && $merchantsTable) {
    $stmtProducts = $conn->prepare(
        'SELECT COUNT(*) AS cnt FROM ' . ident($productsTable) . ' p'
        . ' INNER JOIN ' . ident($merchantsTable) . ' m ON p.merchant_id = m.merchant_id'
        . ' WHERE m.zone_id = ?'
    );
}

$stmtCustomers = null;
if ($customersHasZoneId && $customersTable) {
    $stmtCustomers = $conn->prepare('SELECT COUNT(*) AS cnt FROM ' . ident($customersTable) . ' WHERE zone_id = ?');
} elseif ($addressesHasZoneId && $addressesTable) {
    // Fallback: count distinct users who have an address in this zone
    $stmtCustomers = $conn->prepare('SELECT COUNT(DISTINCT user_id) AS cnt FROM ' . ident($addressesTable) . ' WHERE zone_id = ?');
}

$stmtCouriers = null;
$stmtCouriersHasParam = false;
if ($couriersHasCurrentZoneId) {
    $stmtCouriers = $conn->prepare('SELECT COUNT(*) AS cnt FROM ' . ident($couriersTable) . ' WHERE current_zone_id = ?');
    $stmtCouriersHasParam = true;
} elseif ($couriersHasZoneId) {
    $stmtCouriers = $conn->prepare('SELECT COUNT(*) AS cnt FROM ' . ident($couriersTable) . ' WHERE zone_id = ?');
    $stmtCouriersHasParam = true;
} elseif ($couriersTable) {
    // last resort: can only count total couriers
    $stmtCouriers = $conn->prepare('SELECT COUNT(*) AS cnt FROM ' . ident($couriersTable));
    $stmtCouriersHasParam = false;
}

echo "Zone coverage check\n";
echo "Zones scanned: {$totalZones}\n\n";
echo "Legend: merchants/customers/couriers/products\n\n";

foreach ($zones as $z) {
    $zoneId = $z['zone_id'];

    $mCnt = 0;
    if ($stmtMerchants) {
        $stmtMerchants->bind_param('i', $zoneId);
        $stmtMerchants->execute();
        $mCnt = (int)$stmtMerchants->get_result()->fetch_assoc()['cnt'];
    }

    $cCnt = -1;
    if ($stmtCustomers) {
        $stmtCustomers->bind_param('i', $zoneId);
        $stmtCustomers->execute();
        $cCnt = (int)$stmtCustomers->get_result()->fetch_assoc()['cnt'];
    }

    $rCnt = 0;
    if ($stmtCouriers) {
        if ($stmtCouriersHasParam) {
            $stmtCouriers->bind_param('i', $zoneId);
        }
        $stmtCouriers->execute();
        $rCnt = (int)$stmtCouriers->get_result()->fetch_assoc()['cnt'];
    }

    $pCnt = 0;
    if ($stmtProducts) {
        $stmtProducts->bind_param('i', $zoneId);
        $stmtProducts->execute();
        $pCnt = (int)$stmtProducts->get_result()->fetch_assoc()['cnt'];
    }

    $hasAll = ($mCnt >= 1) && ($pCnt >= 1) && ($rCnt >= 1) && ($cCnt >= 1);

    $line = sprintf(
        '%s / %s (zone_id=%d): %d/%d/%d/%d',
        $z['city'],
        $z['zone_name'],
        $zoneId,
        $mCnt,
        $cCnt,
        $rCnt,
        $pCnt
    );

    if ($hasAll) {
        $okZones++;
        echo "OK  - {$line}\n";
    } else {
        echo "MISS- {$line}\n";
        $badZones[] = $line;
    }
}

echo "\nSummary\n";
echo "OK zones: {$okZones}/{$totalZones}\n";
echo "Missing zones: " . count($badZones) . "\n";

if (!empty($badZones)) {
    echo "\nMissing details (first 50)\n";
    $shown = 0;
    foreach ($badZones as $b) {
        echo "- {$b}\n";
        $shown++;
        if ($shown >= 50) {
            break;
        }
    }
}

if ($stmtMerchants) {
    $stmtMerchants->close();
}
if ($stmtProducts) {
    $stmtProducts->close();
}
if ($stmtCustomers) {
    $stmtCustomers->close();
}
if ($stmtCouriers) {
    $stmtCouriers->close();
}
