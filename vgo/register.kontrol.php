<?php
session_start();
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Collect inputs and trim
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $role_id = intval($_POST['role_id'] ?? 4);
    $created_at = date('Y-m-d H:i:s');

    // Zone IDs
    $courier_zone_id = intval($_POST['courier_zone_id'] ?? 0);
    $merchant_zone_id = intval($_POST['merchant_zone_id'] ?? 0);

    // Role-specific optional fields

    $vehicle_type = trim($_POST['vehicle_type'] ?? '');
    $vehicle_plate = trim($_POST['vehicle_plate'] ?? '');
    $license_number = trim($_POST['license_number'] ?? '');

    $store_name = trim($_POST['store_name'] ?? '');
    $tax_number = trim($_POST['tax_number'] ?? '');
    $merchant_address = trim($_POST['merchant_address'] ?? '');

    // Basic server-side validation
    if (empty($first_name) || empty($last_name)) {
        $_SESSION['errors'] = ['İsim ve soyisim zorunludur.'];
        $_SESSION['old'] = $_POST;
        header('Location: register.php');
        exit;
    }

    // Ensure email contains '@' and is valid
    if (strpos($email, '@') === false || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['errors'] = ['Geçerli bir e-posta girin ("@" içermelidir).'];
        $_SESSION['old'] = $_POST;
        header('Location: register.php');
        exit;
    }

    // Phone required and strict Turkish mobile format (e.g. 05352114778)
    if (empty($phone)) {
        $_SESSION['errors'] = ['Telefon numarası zorunludur.'];
        $_SESSION['old'] = $_POST;
        header('Location: register.php');
        exit;
    }
    // Normalize to digits only
    $phone_digits = preg_replace('/\D+/', '', $phone);
    if (!preg_match('/^05\d{9}$/', $phone_digits)) {
        $_SESSION['errors'] = ['Telefon numarası 05352114778 formatında olmalıdır (başında 0 ile ve toplam 11 hane).'];
        $_SESSION['old'] = $_POST;
        header('Location: register.php');
        exit;
    }
    // Use normalized phone
    $phone = $phone_digits;

    // Prevent duplicate phone numbers
    $check_phone = $conn->prepare("SELECT user_id FROM Users WHERE phone = ? LIMIT 1");
    $check_phone->bind_param("s", $phone);
    $check_phone->execute();
    $res_phone = $check_phone->get_result();
    if ($res_phone && $res_phone->num_rows > 0) {
        $_SESSION['errors'] = ['Bu telefon numarası ile zaten bir hesap mevcut.'];
        $_SESSION['old'] = $_POST;
        header('Location: register.php');
        exit;
    }

    // Password rules: at least 8 chars, upper, lower, digit
    if (!preg_match('/(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}/', $password)) {
        $_SESSION['errors'] = ['Parola en az 8 karakter, büyük/küçük harf ve sayı içermelidir.'];
        $_SESSION['old'] = $_POST;
        header('Location: register.php');
        exit;
    }

    // Role-specific server-side required fields
    if ($role_id === 3) { // Courier
        if (empty($vehicle_type) || empty($license_number)) {
            $_SESSION['errors'] = ['Kurye için araç tipi ve sürücü belgesi numarası zorunludur.'];
            $_SESSION['old'] = $_POST;
            header('Location: register.php');
            exit;
        }
        if ($courier_zone_id <= 0) {
            $_SESSION['errors'] = ['Kurye için çalışma bölgesi seçimi zorunludur.'];
            $_SESSION['old'] = $_POST;
            header('Location: register.php');
            exit;
        }
    } elseif ($role_id === 5) { // Merchant
        if (empty($store_name) || empty($tax_number) || empty($merchant_address)) {
            $_SESSION['errors'] = ['Restoran için mağaza adı, vergi numarası ve adres zorunludur.'];
            $_SESSION['old'] = $_POST;
            header('Location: register.php');
            exit;
        }
        if ($merchant_zone_id <= 0) {
            $_SESSION['errors'] = ['Restoran için hizmet bölgesi seçimi zorunludur.'];
            $_SESSION['old'] = $_POST;
            header('Location: register.php');
            exit;
        }
    } elseif ($role_id === 4) { // Customer
        // Adres bilgileri kayıttan sonra eklenecek
    }

    $full_name = $first_name . ' ' . $last_name;
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Check email uniqueness
    $check_email = $conn->prepare("SELECT user_id FROM Users WHERE email = ? LIMIT 1");
    $check_email->bind_param("s", $email);
    $check_email->execute();
    $res = $check_email->get_result();
    if ($res && $res->num_rows > 0) {
        $_SESSION['errors'] = ['Bu e-posta zaten kayıtlı!'];
        $_SESSION['old'] = $_POST;
        header('Location: register.php');
        exit;
    }

    // Begin transaction so either all inserts succeed or none
    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO Users (role_id, full_name, email, phone, password_hash, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isssss", $role_id, $full_name, $email, $phone, $password_hash, $created_at);
        if (!$stmt->execute()) {
            throw new Exception('Kullanıcı eklenemedi: ' . $stmt->error);
        }
        $user_id = $stmt->insert_id;
        $stmt->close();

        // Insert into role-specific table
        if ($role_id === 4) { // Customer
            $sql2 = "INSERT INTO Customers (user_id, zone_id, default_address, city, postal_code, saved_payment_method, loyalty_points, preferred_language) VALUES (?, NULL, NULL, NULL, NULL, NULL, 0, NULL)";
            $st2 = $conn->prepare($sql2);
            $st2->bind_param("i", $user_id);
            if (!$st2->execute()) {
                throw new Exception('Customer kaydı yapılamadı: ' . $st2->error);
            }
            $st2->close();
        } elseif ($role_id === 3) { // Courier
            $sql2 = "INSERT INTO Couriers (user_id, vehicle_type, vehicle_plate, license_number, current_zone_id, is_available, rating_avg, total_deliveries) VALUES (?, ?, ?, ?, ?, 1, 0.00, 0)";
            $st2 = $conn->prepare($sql2);
            $st2->bind_param("isssi", $user_id, $vehicle_type, $vehicle_plate, $license_number, $courier_zone_id);
            if (!$st2->execute()) {
                throw new Exception('Courier kaydı yapılamadı: ' . $st2->error);
            }
            $st2->close();
        } elseif ($role_id === 5) { // Merchant
            $sql2 = "INSERT INTO Merchants (user_id, store_name, tax_number, address, city, zone_id, opening_time, closing_time, avg_preparation_time, rating_avg, is_active) VALUES (?, ?, ?, ?, NULL, ?, NULL, NULL, NULL, 0.00, 1)";
            $st2 = $conn->prepare($sql2);
            $st2->bind_param("isssi", $user_id, $store_name, $tax_number, $merchant_address, $merchant_zone_id);
            if (!$st2->execute()) {
                throw new Exception('Merchant kaydı yapılamadı: ' . $st2->error);
            }
            $st2->close();
        }

        $conn->commit();
        echo "<script>alert('Kayıt başarılı! Giriş yapabilirsiniz.'); window.location='login.php';</script>";
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        error_log('Register error: ' . $e->getMessage());
        $_SESSION['errors'] = ['Kayıt sırasında bir hata oluştu. Lütfen tekrar deneyin.'];
        $_SESSION['old'] = $_POST;
        header('Location: register.php');
        exit;
    }
}
?>