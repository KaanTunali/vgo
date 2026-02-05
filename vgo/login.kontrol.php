<?php
session_start();
include 'db.php'; // Veritabanı adı 'VGO' olmalı

function vgo_find_table_name($conn, $desiredName) {
    $res = $conn->query("SHOW TABLES");
    if (!$res) return null;
    $tables = [];
    while ($row = $res->fetch_array()) {
        if (isset($row[0]) && $row[0] !== null && $row[0] !== '') {
            $tables[] = (string)$row[0];
        }
    }

    // Prefer exact match first (important if DB is case-sensitive)
    foreach ($tables as $t) {
        if ($t === $desiredName) return $t;
    }

    $desiredLower = strtolower($desiredName);
    foreach ($tables as $t) {
        if (strtolower($t) === $desiredLower) return $t;
    }

    return null;
}

function vgo_table_has_column($conn, $tableName, $columnName) {
    if (!$tableName || !$columnName) return false;
    $tableName = str_replace('`', '``', $tableName);
    $columnName = str_replace('`', '``', $columnName);
    $res = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
    return $res && $res->num_rows > 0;
}

function vgo_ensure_role_exists($conn, $rolesTable, $roleId, $roleName) {
    if (!$rolesTable) return;
    $sql = "INSERT IGNORE INTO `{$rolesTable}` (role_id, role_name) VALUES (?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return;
    $stmt->bind_param("is", $roleId, $roleName);
    $stmt->execute();
    $stmt->close();
}

function vgo_is_hash_placeholder($hash) {
    if ($hash === null) return true;
    $hash = (string)$hash;
    if ($hash === '' || strtolower($hash) === 'hash') return true;
    return false;
}

function vgo_ensure_user_exists($conn, $usersTable, $roleId, $fullName, $email, $plainPassword) {
    if (!$usersTable) return null;

    $stmt = $conn->prepare("SELECT user_id, role_id, password_hash FROM `{$usersTable}` WHERE TRIM(LOWER(email)) = TRIM(LOWER(?)) LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$user) {
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $ins = $conn->prepare("INSERT INTO `{$usersTable}` (role_id, full_name, email, password_hash, status, created_at) VALUES (?, ?, ?, ?, 'active', NOW())");
        if (!$ins) return null;
        $ins->bind_param("isss", $roleId, $fullName, $email, $hash);
        $ins->execute();
        $ins->close();

        $stmt = $conn->prepare("SELECT user_id FROM `{$usersTable}` WHERE TRIM(LOWER(email)) = TRIM(LOWER(?)) LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $user2 = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $user2 ? (int)$user2['user_id'] : null;
    }

    $userId = (int)$user['user_id'];

    // Keep role/status consistent for these default accounts.
    if ((int)$user['role_id'] !== (int)$roleId) {
        $upd = $conn->prepare("UPDATE `{$usersTable}` SET role_id = ?, status = 'active' WHERE user_id = ?");
        if ($upd) {
            $upd->bind_param("ii", $roleId, $userId);
            $upd->execute();
            $upd->close();
        }
    }

    // If password was left as placeholder, set a real hash.
    if (vgo_is_hash_placeholder($user['password_hash'])) {
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE `{$usersTable}` SET password_hash = ? WHERE user_id = ?");
        if ($upd) {
            $upd->bind_param("si", $hash, $userId);
            $upd->execute();
            $upd->close();
        }
    }

    return $userId;
}

function vgo_ensure_default_accounts($conn) {
    $usersTable = vgo_find_table_name($conn, 'Users');
    if (!$usersTable) return;

    $rolesTable = vgo_find_table_name($conn, 'roles');
    vgo_ensure_role_exists($conn, $rolesTable, 1, 'Admin');
    vgo_ensure_role_exists($conn, $rolesTable, 2, 'Operator');

    $adminUserId = vgo_ensure_user_exists($conn, $usersTable, 1, 'Admin', 'admin@vgo.com', 'admin123');
    $operatorUserId = vgo_ensure_user_exists($conn, $usersTable, 2, 'Operator', 'operator@vgo.com', 'operator123');

    $adminsTable = vgo_find_table_name($conn, 'Admins');
    if ($adminsTable && $adminUserId) {
        $stmt = $conn->prepare("INSERT IGNORE INTO `{$adminsTable}` (user_id, full_name, email, access_level) VALUES (?, 'Admin', 'admin@vgo.com', 10)");
        if ($stmt) {
            $stmt->bind_param("i", $adminUserId);
            $stmt->execute();
            $stmt->close();
        }
    }

    $operatorsTable = vgo_find_table_name($conn, 'Operators');
    if ($operatorsTable && $operatorUserId) {
        $stmt = $conn->prepare("INSERT IGNORE INTO `{$operatorsTable}` (user_id) VALUES (?)");
        if ($stmt) {
            $stmt->bind_param("i", $operatorUserId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // If DB was installed partially or seed was re-run and aborted, guarantee default accounts exist.
    vgo_ensure_default_accounts($conn);

    // Resolve actual table names (case-sensitive environments)
    $usersTable = vgo_find_table_name($conn, 'Users');
    if (!$usersTable) {
        echo "<script>alert('HATA: Users tablosu bulunamadı. Doğru veritabanına bağlandığınızdan emin olun.'); window.history.back();</script>";
        exit;
    }
    $customersTable = vgo_find_table_name($conn, 'Customers');
    $couriersTable = vgo_find_table_name($conn, 'Couriers');
    $merchantsTable = vgo_find_table_name($conn, 'Merchants');
    $operatorsTable = vgo_find_table_name($conn, 'Operators');

    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "<script>alert('Lütfen geçerli bir e-posta adresi girin.'); window.history.back();</script>";
        exit;
    }

    // 1. ADIM: E-posta kontrolü
    $stmt = $conn->prepare("SELECT * FROM `{$usersTable}` WHERE TRIM(LOWER(email)) = TRIM(LOWER(?)) LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    // E-posta veritabanında var mı?
    if ($user = $result->fetch_assoc()) {

        // If the account was created manually with a placeholder hash (e.g. 'hash' or empty),
        // treat the first login attempt as password initialization.
        if (vgo_is_hash_placeholder($user['password_hash'] ?? null)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE `{$usersTable}` SET password_hash = ? WHERE user_id = ?");
            if ($upd) {
                $upd->bind_param("si", $newHash, $user['user_id']);
                $upd->execute();
                $upd->close();
                $user['password_hash'] = $newHash;
            }
        }

        // 2. ADIM: Şifre kontrolü - use password_verify for hashed passwords
        if (password_verify($password, $user['password_hash'])) {

            // Track last login if supported by schema
            if (vgo_table_has_column($conn, $usersTable, 'last_login')) {
                $upd = $conn->prepare("UPDATE `{$usersTable}` SET last_login = NOW() WHERE user_id = ?");
                if ($upd) {
                    $upd->bind_param('i', $user['user_id']);
                    $upd->execute();
                    $upd->close();
                }
            }

            // Optional: check account status
            if (isset($user['status']) && $user['status'] !== 'active') {
                echo "<script>alert('Hesabınız aktif değil. Lütfen destek ile iletişime geçin.'); window.history.back();</script>";
                exit;
            }

            // HER ŞEY DOĞRU - Oturumu başlat
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role_id'] = $user['role_id'];

            // Role-specific IDs'leri de SESSION'a ekle
            if ($user['role_id'] == 4) {
                $stmt2 = $customersTable ? $conn->prepare("SELECT customer_id FROM `{$customersTable}` WHERE user_id = ?") : null;
                if ($stmt2) {
                    $stmt2->bind_param("i", $user['user_id']);
                    $stmt2->execute();
                    $customer = $stmt2->get_result()->fetch_assoc();
                    if ($customer) $_SESSION['customer_id'] = $customer['customer_id'];
                    $stmt2->close();
                }
            } elseif ($user['role_id'] == 3) {
                $stmt2 = $couriersTable ? $conn->prepare("SELECT courier_id FROM `{$couriersTable}` WHERE user_id = ?") : null;
                if ($stmt2) {
                    $stmt2->bind_param("i", $user['user_id']);
                    $stmt2->execute();
                    $courier = $stmt2->get_result()->fetch_assoc();
                    if ($courier) $_SESSION['courier_id'] = $courier['courier_id'];
                    $stmt2->close();
                }
            } elseif ($user['role_id'] == 5) {
                $stmt2 = $merchantsTable ? $conn->prepare("SELECT merchant_id FROM `{$merchantsTable}` WHERE user_id = ?") : null;
                if ($stmt2) {
                    $stmt2->bind_param("i", $user['user_id']);
                    $stmt2->execute();
                    $merchant = $stmt2->get_result()->fetch_assoc();
                    if ($merchant) $_SESSION['merchant_id'] = $merchant['merchant_id'];
                    $stmt2->close();
                }
            } elseif ($user['role_id'] == 2) {
                $stmt2 = $operatorsTable ? $conn->prepare("SELECT operator_id FROM `{$operatorsTable}` WHERE user_id = ?") : null;
                if ($stmt2) {
                    $stmt2->bind_param("i", $user['user_id']);
                    $stmt2->execute();
                    $operator = $stmt2->get_result()->fetch_assoc();
                    if ($operator) $_SESSION['operator_id'] = $operator['operator_id'];
                    $stmt2->close();
                }
            }

            // Role göre doğru sayfaya yönlendir
            if ($user['role_id'] == 1) {
                header("Location: admin_dashboard.php");
            } elseif ($user['role_id'] == 6) {
                header("Location: support_manager.php");
            } elseif ($user['role_id'] == 3) {
                header("Location: courier_dashboard.php");
            } elseif ($user['role_id'] == 2) {
                header("Location: operator_dashboard.php");
            } elseif ($user['role_id'] == 5) {
                header("Location: merchant_dashboard.php");
            } elseif ($user['role_id'] == 4) {
                header("Location: dashboard.php");
            } else {
                header("Location: dashboard.php");
            }
            exit;

        } else {
            // Backward-compat: if legacy DB has plain-text placeholder like 'hash11', allow a single login when password matches exactly, then re-hash in DB
            if ($password === $user['password_hash']) {
                // re-hash and update DB
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $upd = $conn->prepare("UPDATE `{$usersTable}` SET password_hash = ? WHERE user_id = ?");
                $upd->bind_param("si", $newHash, $user['user_id']);
                $upd->execute();
                $upd->close();

                // Optional: check account status
                if (isset($user['status']) && $user['status'] !== 'active') {
                    echo "<script>alert('Hesabınız aktif değil. Lütfen destek ile iletişime geçin.'); window.history.back();</script>";
                    exit;
                }

                // Start session
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role_id'] = $user['role_id'];

                // Role-specific IDs'leri de SESSION'a ekle
                if ($user['role_id'] == 4) {
                    $stmt2 = $customersTable ? $conn->prepare("SELECT customer_id FROM `{$customersTable}` WHERE user_id = ?") : null;
                    if (!$stmt2) { /* ignore */ }
                    $stmt2->bind_param("i", $user['user_id']);
                    $stmt2->execute();
                    $customer = $stmt2->get_result()->fetch_assoc();
                    if ($customer) $_SESSION['customer_id'] = $customer['customer_id'];
                    $stmt2->close();
                } elseif ($user['role_id'] == 3) {
                    $stmt2 = $couriersTable ? $conn->prepare("SELECT courier_id FROM `{$couriersTable}` WHERE user_id = ?") : null;
                    if (!$stmt2) { /* ignore */ }
                    $stmt2->bind_param("i", $user['user_id']);
                    $stmt2->execute();
                    $courier = $stmt2->get_result()->fetch_assoc();
                    if ($courier) $_SESSION['courier_id'] = $courier['courier_id'];
                    $stmt2->close();
                } elseif ($user['role_id'] == 5) {
                    $stmt2 = $merchantsTable ? $conn->prepare("SELECT merchant_id FROM `{$merchantsTable}` WHERE user_id = ?") : null;
                    if (!$stmt2) { /* ignore */ }
                    $stmt2->bind_param("i", $user['user_id']);
                    $stmt2->execute();
                    $merchant = $stmt2->get_result()->fetch_assoc();
                    if ($merchant) $_SESSION['merchant_id'] = $merchant['merchant_id'];
                    $stmt2->close();
                } elseif ($user['role_id'] == 2) {
                    $stmt2 = $operatorsTable ? $conn->prepare("SELECT operator_id FROM `{$operatorsTable}` WHERE user_id = ?") : null;
                    if (!$stmt2) { /* ignore */ }
                    $stmt2->bind_param("i", $user['user_id']);
                    $stmt2->execute();
                    $operator = $stmt2->get_result()->fetch_assoc();
                    if ($operator) $_SESSION['operator_id'] = $operator['operator_id'];
                    $stmt2->close();
                }

                if ($user['role_id'] == 1) {
                    header("Location: admin_dashboard.php");
                } elseif ($user['role_id'] == 6) {
                    header("Location: support_manager.php");
                } elseif ($user['role_id'] == 3) {
                    header("Location: courier_dashboard.php");
                } elseif ($user['role_id'] == 2) {
                    header("Location: operator_dashboard.php");
                } elseif ($user['role_id'] == 5) {
                    header("Location: merchant_dashboard.php");
                } elseif ($user['role_id'] == 4) {
                    header("Location: dashboard.php");
                } else {
                    header("Location: dashboard.php");
                }
                exit;
            }

            // ŞİFRE YANLIŞ - Sisteme girmesine izin verme!
            echo "<script>alert('HATA: Girdiğiniz şifre yanlıştır!'); window.history.back();</script>";
            exit;
        }
    } else {
        // E-POSTA YANLIŞ - Sisteme girmesine izin verme!
        echo "<script>alert('HATA: Bu e-posta adresi kayıtlı değil!'); window.history.back();</script>";
        exit;
    }
}
?>