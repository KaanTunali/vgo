<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>VGO - Sistem Kontrolü</title>
<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
.container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
.success { color: #28a745; font-weight: bold; }
.error { color: #dc3545; font-weight: bold; }
.warning { color: #ffc107; font-weight: bold; }
table { width: 100%; border-collapse: collapse; margin: 20px 0; }
th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
th { background: #007bff; color: white; }
.section { margin: 30px 0; padding: 15px; border-left: 4px solid #007bff; background: #f8f9fa; }
</style>
</head>
<body>
<div class="container">
<h1>🔍 VGO Sistem Kontrolü</h1>
<hr>

<?php
include 'db.php';

// Current database name (avoid hard-coded schema name)
$dbName = '';
try {
    $resDb = $conn->query("SELECT DATABASE() AS db");
    $rowDb = $resDb ? $resDb->fetch_assoc() : null;
    $dbName = (string)($rowDb['db'] ?? '');
} catch (Throwable $e) {
    $dbName = '';
}

// 1. YASAK ÖZELLİK KONTROLÜ
echo "<div class='section'>";
echo "<h2>1️⃣ YASAK ÖZELLİK KONTROLÜ</h2>";

$forbidden_patterns = [
    // NOTE: These patterns are meant to catch risky SQL features. They are scanned as standalone SQL keywords.
    '/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i' => 'ON DUPLICATE KEY UPDATE',
    '/\bREPLACE\s+INTO\b/i' => 'REPLACE INTO',
    '/\bTRUNCATE\s+TABLE\b/i' => 'TRUNCATE TABLE',
    '/\bLOAD\s+DATA\b/i' => 'LOAD DATA',
    '/\bINTO\s+OUTFILE\b/i' => 'INTO OUTFILE',
    '/\bINTO\s+DUMPFILE\b/i' => 'INTO DUMPFILE',
    // HANDLER keyword is too noisy in PHP code/comments; skip to avoid false positives.
];

$php_files = array_merge(glob("*.php") ?: [], glob("classes/*.php") ?: [], glob("api/*.php") ?: []);
$sql_files = glob("*.sql") ?: [];
$selfFile = realpath(__FILE__);

$found_issues = [];

foreach ($php_files as $file) {
    if ($selfFile && realpath($file) === $selfFile) continue; // don't self-report
    $content = file_get_contents($file);
    foreach ($forbidden_patterns as $pattern => $label) {
        if (preg_match($pattern, $content)) {
            $found_issues[] = "$file içinde: $label";
        }
    }
}

foreach ($sql_files as $file) {
    $content = file_get_contents($file);
    foreach ($forbidden_patterns as $pattern => $label) {
        if (preg_match($pattern, $content)) {
            $found_issues[] = "$file içinde: $label";
        }
    }
}

if (empty($found_issues)) {
    echo "<p class='success'>✅ Yasak özellik bulunmadı!</p>";
} else {
    echo "<p class='error'>❌ Yasak özellikler bulundu:</p><ul>";
    foreach ($found_issues as $issue) {
        echo "<li class='error'>$issue</li>";
    }
    echo "</ul>";
}
echo "</div>";

// 2. VERİTABANI STANDARTLARI
echo "<div class='section'>";
echo "<h2>2️⃣ VERİTABANI STANDARTLARI</h2>";

$safeDbName = $dbName !== '' ? $conn->real_escape_string($dbName) : '';

$standards = [
    'Stored Procedures' => ['min' => 7, 'query' => ($safeDbName !== '' ? "SELECT COUNT(*) as cnt FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='$safeDbName' AND ROUTINE_TYPE='PROCEDURE'" : "SELECT 0 as cnt")],
    'Stored Functions' => ['min' => 3, 'query' => ($safeDbName !== '' ? "SELECT COUNT(*) as cnt FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='$safeDbName' AND ROUTINE_TYPE='FUNCTION'" : "SELECT 0 as cnt")],
    'Triggers' => ['min' => 3, 'query' => ($safeDbName !== '' ? "SELECT COUNT(*) as cnt FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$safeDbName'" : "SELECT 0 as cnt")],
    'Views' => ['min' => 5, 'query' => ($safeDbName !== '' ? "SELECT COUNT(*) as cnt FROM information_schema.VIEWS WHERE TABLE_SCHEMA='$safeDbName'" : "SELECT 0 as cnt")]
];

echo "<table>";
echo "<tr><th>Özellik</th><th>Minimum</th><th>Mevcut</th><th>Durum</th></tr>";

foreach ($standards as $name => $data) {
    $result = $conn->query($data['query']);
    $row = $result->fetch_assoc();
    $count = $row['cnt'];
    $status = $count >= $data['min'] ? "<span class='success'>✅ UYGUN</span>" : "<span class='error'>❌ EKSİK</span>";
    
    echo "<tr>";
    echo "<td><strong>$name</strong></td>";
    echo "<td>{$data['min']}</td>";
    echo "<td>$count</td>";
    echo "<td>$status</td>";
    echo "</tr>";
}

echo "</table>";
echo "</div>";

// 3. TABLO YAPILARINI KONTROL
echo "<div class='section'>";
echo "<h2>3️⃣ TABLO YAPILARI</h2>";

$required_tables = [
    'Users', 'Customers', 'Merchants', 'Couriers', 'Zones', 'Orders', 
    'Order_Items', 'Deliveries', 'Products', 'Carts', 'Cart_Items',
    'Customer_Addresses', 'SupportTickets', 'SupportMessages', 
    'SupportCategories', 'Coupons', 'Favorites'
];

$required_tables_lc = array_map('strtolower', $required_tables);

$existing_tables = [];
$result = $conn->query("SHOW TABLES");
while ($row = $result->fetch_array()) {
    $existing_tables[] = $row[0];
}

$existing_tables_lc = array_map('strtolower', $existing_tables);

echo "<p><strong>Gerekli Tablolar:</strong> " . count($required_tables) . "</p>";
echo "<p><strong>Mevcut Tablolar:</strong> " . count($existing_tables) . "</p>";

$missing_lc = array_diff($required_tables_lc, $existing_tables_lc);
$missing = [];
if (!empty($missing_lc)) {
    $map = [];
    foreach ($required_tables as $t) {
        $map[strtolower($t)] = $t;
    }
    foreach ($missing_lc as $tLc) {
        $missing[] = $map[$tLc] ?? $tLc;
    }
}
if (empty($missing)) {
    echo "<p class='success'>✅ Tüm gerekli tablolar mevcut!</p>";
} else {
    echo "<p class='error'>❌ Eksik Tablolar:</p><ul>";
    foreach ($missing as $table) {
        echo "<li>$table</li>";
    }
    echo "</ul>";
}
echo "</div>";

// 4. DOSYA YAPISI KONTROLÜ
echo "<div class='section'>";
echo "<h2>4️⃣ DOSYA YAPISI</h2>";

$required_files = [
    'db.php' => 'Database connection',
    'login.php' => 'Login page',
    'register.php' => 'Register page',
    'dashboard.php' => 'Customer dashboard',
    'merchant_dashboard.php' => 'Merchant dashboard',
    'courier_dashboard.php' => 'Courier dashboard',
    'checkout.php' => 'Checkout page',
    'create_order.php' => 'Order creation',
    'addresses.php' => 'Address management',
    'support.php' => 'Support system',
    'support_widget.php' => 'Support widget'
];

echo "<table>";
echo "<tr><th>Dosya</th><th>Açıklama</th><th>Durum</th></tr>";

foreach ($required_files as $file => $desc) {
    $exists = file_exists($file);
    $status = $exists ? "<span class='success'>✅ Var</span>" : "<span class='error'>❌ Yok</span>";
    echo "<tr><td><code>$file</code></td><td>$desc</td><td>$status</td></tr>";
}

echo "</table>";
echo "</div>";

// 5. API ENDPOINT KONTROLÜ
echo "<div class='section'>";
echo "<h2>5️⃣ API ENDPOINTS</h2>";

$api_files = glob("api/*.php");
echo "<p><strong>Toplam API Endpoint:</strong> " . count($api_files) . "</p>";

if (count($api_files) > 0) {
    echo "<ul>";
    foreach ($api_files as $file) {
        echo "<li>" . basename($file) . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p class='warning'>⚠️ API klasörü boş!</p>";
}
echo "</div>";

// 6. SQL İNJECTION GÜVENLİĞİ KONTROLÜ
echo "<div class='section'>";
echo "<h2>6️⃣ SQL İNJECTION GÜVENLİĞİ</h2>";

$unsafe_patterns = [
    'mysqli_query.*\$_POST' => 'POST verisi direkt query\'de',
    'mysqli_query.*\$_GET' => 'GET verisi direkt query\'de',
    '\$conn->query.*\$_' => 'Prepared statement kullanılmamış'
];

$unsafe_files = [];

foreach ($php_files as $file) {
    if ($selfFile && realpath($file) === $selfFile) continue; // don't self-report
    $content = file_get_contents($file);
    foreach ($unsafe_patterns as $pattern => $desc) {
        if (preg_match("/$pattern/", $content)) {
            $unsafe_files[] = "$file - $desc";
        }
    }
}

if (empty($unsafe_files)) {
    echo "<p class='success'>✅ SQL Injection riski düşük!</p>";
} else {
    echo "<p class='warning'>⚠️ Dikkat edilmesi gereken dosyalar:</p><ul>";
    foreach ($unsafe_files as $file) {
        echo "<li class='warning'>$file</li>";
    }
    echo "</ul>";
}
echo "</div>";

?>

<hr>
<p style="text-align: center; margin-top: 30px;">
    <strong>Kontrol Tamamlandı!</strong><br>
    <a href="dashboard.php">Dashboard'a Dön</a>
</p>

</div>
</body>
</html>
