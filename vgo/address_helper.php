<?php
// Address helper utilities
// Provides: ensure_default_address, get_user_addresses, get_active_address, set_active_address, add_address

// local table existence checker (lightweight, avoids fatal on missing table)
function _addr_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $cnt = ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
    $stmt->close();
    return $cnt;
}

function _ensure_address_table(mysqli $conn): void {
    if (_addr_table_exists($conn, 'Customer_Addresses')) return;
    // Minimal DDL to avoid fatal if migrations not run
    $conn->query("CREATE TABLE IF NOT EXISTS Customer_Addresses (
        address_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(50) NOT NULL DEFAULT 'Ev',
        address_line VARCHAR(255) NOT NULL,
        city VARCHAR(100) NULL,
        postal_code VARCHAR(20) NULL,
        zone_id INT NULL,
        is_default BOOLEAN NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX IDX_CA_USER (user_id),
        INDEX IDX_CA_ZONE (zone_id),
        CONSTRAINT FK_CA_USER FOREIGN KEY (user_id) REFERENCES Users(user_id),
        CONSTRAINT FK_CA_ZONE FOREIGN KEY (zone_id) REFERENCES Zones(zone_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function _addr_clear_call_results(mysqli $conn): void {
    while ($conn->more_results() && $conn->next_result()) {
        $r = $conn->store_result();
        if ($r) $r->free();
    }
}

function ensure_default_address(mysqli $conn, int $user_id): ?array {
    _ensure_address_table($conn);
    // If user has no address rows, create one from Customers table fields
    $row = null;
    $stmt = $conn->prepare('CALL GetUserDefaultAddress(?)');
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        _addr_clear_call_results($conn);
    }

    if (!$row) {
        $stmt = $conn->prepare("SELECT address_id, title, address_line, city, postal_code, zone_id FROM Customer_Addresses WHERE user_id = ? LIMIT 1");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if ($row) {
        return $row;
    }

    // Pull data from Customers
    $c = null;
    $stmt = $conn->prepare('CALL GetCustomerAddressFallback(?)');
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $c = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        _addr_clear_call_results($conn);
    }
    if (!$c) {
        $stmt = $conn->prepare("SELECT default_address, city, postal_code, zone_id FROM Customers WHERE user_id = ? LIMIT 1");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $c = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$c) return null;

    $addrLine = $c['default_address'] ?: 'Adres bilinmiyor';
    $city = $c['city'] ?: 'Istanbul';
    $postal = $c['postal_code'] ?: '';
    $zoneId = $c['zone_id'] ?: null;

    $newId = 0;
    $stmt = $conn->prepare('CALL InsertUserAddress(?, ?, ?, ?, ?, ?, ?)');
    if ($stmt) {
        $title = 'Ev';
        $isDefault = 1;
        $stmt->bind_param('issssii', $user_id, $title, $addrLine, $city, $postal, $zoneId, $isDefault);
        $stmt->execute();
        $res = $stmt->get_result();
        $rowId = $res ? $res->fetch_assoc() : null;
        $newId = (int)($rowId['address_id'] ?? 0);
        $stmt->close();
        _addr_clear_call_results($conn);
    }

    if ($newId <= 0) {
        $stmt = $conn->prepare("INSERT INTO Customer_Addresses (user_id, title, address_line, city, postal_code, zone_id, is_default) VALUES (?, 'Ev', ?, ?, ?, ?, 1)");
        $stmt->bind_param('isssi', $user_id, $addrLine, $city, $postal, $zoneId);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();
    }

    return [
        'address_id' => $newId,
        'title' => 'Ev',
        'address_line' => $addrLine,
        'city' => $city,
        'postal_code' => $postal,
        'zone_id' => $zoneId
    ];
}

function get_user_addresses(mysqli $conn, int $user_id): array {
    _ensure_address_table($conn);
    $rows = [];
    $stmt = $conn->prepare('CALL GetUserAddresses(?)');
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        $stmt->close();
        _addr_clear_call_results($conn);
        return $rows;
    }

    $stmt = $conn->prepare("SELECT ca.address_id, ca.title, ca.address_line, ca.city, ca.postal_code, ca.zone_id, z.zone_name, ca.is_default FROM Customer_Addresses ca LEFT JOIN Zones z ON ca.zone_id = z.zone_id WHERE ca.user_id = ? ORDER BY ca.is_default DESC, ca.created_at DESC");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function resolve_active_address(mysqli $conn, int $user_id): ?array {
    _ensure_address_table($conn);
    // Try session selection first
    if (!empty($_SESSION['selected_address_id'])) {
        $aid = (int)$_SESSION['selected_address_id'];
        $a = null;
        $stmt = $conn->prepare('CALL GetUserAddressById(?, ?)');
        if ($stmt) {
            $stmt->bind_param('ii', $aid, $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $a = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            _addr_clear_call_results($conn);
        }

        if (!$a) {
            $stmt = $conn->prepare("SELECT ca.address_id, ca.title, ca.address_line, ca.city, ca.postal_code, ca.zone_id, z.zone_name FROM Customer_Addresses ca LEFT JOIN Zones z ON ca.zone_id = z.zone_id WHERE ca.address_id = ? AND ca.user_id = ? LIMIT 1");
            $stmt->bind_param('ii', $aid, $user_id);
            $stmt->execute();
            $a = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if ($a) return $a;
    }
    // Fallback: default or first address
    $a = null;
    $stmt = $conn->prepare('CALL GetUserDefaultAddress(?)');
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $a = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        _addr_clear_call_results($conn);
    }

    if (!$a) {
        $stmt = $conn->prepare("SELECT ca.address_id, ca.title, ca.address_line, ca.city, ca.postal_code, ca.zone_id, z.zone_name FROM Customer_Addresses ca LEFT JOIN Zones z ON ca.zone_id = z.zone_id WHERE ca.user_id = ? ORDER BY ca.is_default DESC, ca.created_at DESC LIMIT 1");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $a = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    return $a ?: null;
}

function set_active_address(array $address): void {
    $_SESSION['selected_address_id'] = $address['address_id'] ?? null;
    $_SESSION['selected_zone_id'] = $address['zone_id'] ?? null;
    $_SESSION['selected_address_title'] = $address['title'] ?? '';
}

function add_address(mysqli $conn, int $user_id, string $title, string $address_line, ?int $zone_id, ?string $city, ?string $postal): int {
    _ensure_address_table($conn);

    // Normalize zone_id to avoid FK violations (zone_id=0 or non-existing zone).
    if ($zone_id !== null && $zone_id <= 0) {
        $zone_id = null;
    }
    if ($zone_id !== null) {
        $chk = $conn->prepare('SELECT 1 FROM Zones WHERE zone_id = ? LIMIT 1');
        if ($chk) {
            $chk->bind_param('i', $zone_id);
            $chk->execute();
            $ok = $chk->get_result();
            $exists = ($ok && $ok->num_rows > 0);
            $chk->close();
            if (!$exists) {
                $zone_id = null;
            }
        }
    }

    $stmt = $conn->prepare('CALL InsertUserAddress(?, ?, ?, ?, ?, ?, ?)');
    if ($stmt) {
        $isDefault = 0;
        $stmt->bind_param('issssii', $user_id, $title, $address_line, $city, $postal, $zone_id, $isDefault);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $id = (int)($row['address_id'] ?? 0);
        $stmt->close();
        _addr_clear_call_results($conn);
        if ($id > 0) return $id;
    }

    $stmt = $conn->prepare("INSERT INTO Customer_Addresses (user_id, title, address_line, city, postal_code, zone_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('issssi', $user_id, $title, $address_line, $city, $postal, $zone_id);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}
