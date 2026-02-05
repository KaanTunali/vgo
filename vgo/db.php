<?php
$servername = "localhost";
$username = "root";
$password = "mysql"; // AMPPS varsayılan şifresi genellikle 'mysql'dir.

// Tercih edilen veritabanı adı (Database.sql içinde kullanılan)
$preferredDb = "VGO";

// Bağlantı oluşturma (önce DB seçmeden bağlan, sonra uygun şemayı seç)
$conn = new mysqli($servername, $username, $password);

// Bağlantı kontrolü
if ($conn->connect_error) {
    die("Bağlantı hatası: " . $conn->connect_error);
}

// Türkçe karakter desteği
$conn->set_charset("utf8mb4");
$conn->query("SET NAMES utf8mb4");
$conn->query("SET CHARACTER SET utf8mb4");

function vgo_db_has_table(mysqli $conn, string $dbName, string $tableName): bool {
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('ss', $dbName, $tableName);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

function vgo_db_users_count(mysqli $conn, string $dbName, string $tableName): int {
    if (!$conn->select_db($dbName)) return -1;
    $q = "SELECT COUNT(*) AS c FROM `{$tableName}`";
    $r = $conn->query($q);
    if (!$r) return -1;
    $row = $r->fetch_assoc();
    return (int)($row['c'] ?? -1);
}

// 1) Öncelik: Database.sql'deki şema adı (case-insensitive)
$preferredCandidates = array_values(array_unique([$preferredDb, strtolower($preferredDb), strtoupper($preferredDb)]));

$selectedDb = null;
$bestCount = -1;

foreach ($preferredCandidates as $dbName) {
    $hasUsersLower = vgo_db_has_table($conn, $dbName, 'users');
    $hasUsersUpper = vgo_db_has_table($conn, $dbName, 'Users');
    if (!$hasUsersLower && !$hasUsersUpper) continue;
    $tableToCount = $hasUsersLower ? 'users' : 'Users';
    $cnt = vgo_db_users_count($conn, $dbName, $tableToCount);
    if ($cnt > $bestCount) {
        $bestCount = $cnt;
        $selectedDb = $dbName;
    }
}

// 2) Eğer tercih edilen DB yoksa, diğer DB'leri tara (son çare)
if ($selectedDb === null) {
    $candidateDbs = array_values(array_unique(array_merge($preferredCandidates, ['exampledb'])));

    $dbsRes = $conn->query('SHOW DATABASES');
    if ($dbsRes) {
        while ($row = $dbsRes->fetch_row()) {
            $dbName = (string)($row[0] ?? '');
            if ($dbName === '') continue;
            $lower = strtolower($dbName);
            if (in_array($lower, ['information_schema', 'mysql', 'performance_schema', 'sys', 'phpmyadmin'], true)) continue;
            $candidateDbs[] = $dbName;
        }
    }
    $candidateDbs = array_values(array_unique($candidateDbs));

    foreach ($candidateDbs as $dbName) {
        $hasUsersLower = vgo_db_has_table($conn, $dbName, 'users');
        $hasUsersUpper = vgo_db_has_table($conn, $dbName, 'Users');
        if (!$hasUsersLower && !$hasUsersUpper) continue;
        $tableToCount = $hasUsersLower ? 'users' : 'Users';
        $cnt = vgo_db_users_count($conn, $dbName, $tableToCount);
        if ($cnt > $bestCount) {
            $bestCount = $cnt;
            $selectedDb = $dbName;
        }
    }
}

if ($selectedDb === null) {
    // Son çare: tercih edilen DB'yi seçmeye çalış
    if (!$conn->select_db($preferredDb)) {
        die('Veritabanı seçilemedi: ' . $preferredDb);
    }
} else {
    $conn->select_db($selectedDb);
}
?>