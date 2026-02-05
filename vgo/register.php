<!DOCTYPE html>
<html>
<head>
    <title>VGO - Kayıt Ol</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<?php 
session_start();
require_once 'db.php';
$errors = $_SESSION['errors'] ?? [];
$old = $_SESSION['old'] ?? [];
unset($_SESSION['errors'], $_SESSION['old']);
?>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card shadow">
                    <div class="card-body">
                        <h3 class="text-center">VGO'ya Kayıt Ol</h3>
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    <?php foreach ($errors as $err): ?>
                                        <li><?php echo htmlspecialchars($err); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form action="register.kontrol.php" method="POST" id="registerForm">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Ad</label>
                                    <input type="text" name="first_name" class="form-control" required value="<?php echo htmlspecialchars($old['first_name'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Soyad</label>
                                    <input type="text" name="last_name" class="form-control" required value="<?php echo htmlspecialchars($old['last_name'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label>E-posta</label>
                                <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($old['email'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label>Telefon</label>
                                <input type="tel" name="phone" class="form-control" required pattern="^05\d{9}$" placeholder="Örn: 05352114778" value="<?php echo htmlspecialchars($old['phone'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label>Şifre</label>
                                <input type="password" name="password" class="form-control" required minlength="8" placeholder="En az 8 karakter, büyük/küçük harf ve sayı içermeli">
                                <small class="text-muted">Parolanız en az 8 karakter olmalı ve büyük/küçük harf ile rakam içermelidir.</small>
                            </div>

                            <div class="mb-3">
                                <label style="font-size:1.1rem;font-weight:bold;">🎯 Üyelik Tipi Seçin</label>
                                <select name="role_id" id="roleSelect" class="form-select" required style="font-weight:bold;font-size:1.1rem;border:3px solid #0d6efd;padding:12px;" onchange="updateRoleFields()">
                                    <option value="">-- Lütfen Bir Rol Seçin --</option>
                                    <option value="4" <?php echo (isset($old['role_id']) && $old['role_id'] == '4') ? 'selected' : ''; ?>>👤 Müşteri (Customer)</option>
                                    <option value="3" <?php echo (isset($old['role_id']) && $old['role_id'] == '3') ? 'selected' : ''; ?>>🚴 Kurye (Courier)</option>
                                    <option value="5" <?php echo (isset($old['role_id']) && $old['role_id'] == '5') ? 'selected' : ''; ?>>🏪 Restoran (Merchant)</option>
                                </select>
                            </div>

                            <!-- Role-specific fields -->
                            <div id="customerFields" class="role-fields" style="display:none;padding:20px;border:3px solid #0d6efd;border-radius:10px;background:#e7f3ff;margin-top:20px;">
                                <h5 class="mb-3" style="color:#084298;">👤 MÜŞTERİ BİLGİLERİ</h5>
                                <p class="alert alert-info mb-0"><i class="bi bi-info-circle"></i> Adres bilgilerinizi kayıttan sonra profilinizden ekleyebilirsiniz.</p>
                            </div>
                            
                            <div id="courierFields" class="role-fields" style="display:none;padding:20px;border:3px solid #ffc107;border-radius:10px;background:#fff8e6;margin-top:20px;">
                                <h5 class="mb-3" style="color:#856404;font-size:1.3rem;">🚴 KURYE BİLGİLERİ</h5>
                                <div class="mb-3">
                                    <label style="font-weight:bold;"><i class="bi bi-geo-alt-fill"></i> Çalışma Bölgesi <span class="text-danger" style="font-size:1.2rem;">*</span></label>
                                    <select name="courier_zone_id" class="form-select" required style="border:2px solid #ffc107;">
                                        <option value="">-- Bölgenizi seçin --</option>
                                        <?php
                                        $zones2 = $conn->query("SELECT MIN(zone_id) as zone_id, zone_name, city FROM Zones WHERE is_active = 1 GROUP BY city, zone_name ORDER BY city, zone_name");
                                        while ($z = $zones2->fetch_assoc()) {
                                            $selected = (isset($old['courier_zone_id']) && $old['courier_zone_id'] == $z['zone_id']) ? 'selected' : '';
                                            echo '<option value="' . $z['zone_id'] . '" ' . $selected . '>' . htmlspecialchars($z['city']) . ' - ' . htmlspecialchars($z['zone_name']) . '</option>';
                                        }
                                        ?>
                                    </select>
                                    <small class="text-muted">Sadece seçtiğiniz bölgedeki teslimatları alabilirsiniz.</small>
                                </div>
                                <div class="mb-3">
                                    <label style="font-weight:bold;"><i class="bi bi-bicycle"></i> Araç Tipi <span class="text-danger" style="font-size:1.2rem;">*</span></label>
                                    <select name="vehicle_type" class="form-select" required style="border:2px solid #ffc107;">
                                        <option value="">-- Araç Seçin --</option>
                                        <option value="bike" <?php echo (isset($old['vehicle_type']) && $old['vehicle_type']=='bike') ? 'selected' : ''; ?>>🚲 Bisiklet</option>
                                        <option value="motorcycle" <?php echo (isset($old['vehicle_type']) && $old['vehicle_type']=='motorcycle') ? 'selected' : ''; ?>>🏍️ Motor</option>
                                        <option value="car" <?php echo (isset($old['vehicle_type']) && $old['vehicle_type']=='car') ? 'selected' : ''; ?>>🚗 Araba</option>
                                        <option value="on_foot" <?php echo (isset($old['vehicle_type']) && $old['vehicle_type']=='on_foot') ? 'selected' : ''; ?>>🚶 Yaya</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label style="font-weight:bold;"><i class="bi bi-card-text"></i> Plaka</label>
                                    <input type="text" name="vehicle_plate" class="form-control" placeholder="Örn: 34 ABC 123" value="<?php echo htmlspecialchars($old['vehicle_plate'] ?? ''); ?>" style="border:2px solid #ffc107;">
                                    <small class="text-muted">Opsiyonel - Eğer aracınız varsa</small>
                                </div>
                                <div class="mb-3">
                                    <label style="font-weight:bold;"><i class="bi bi-person-badge"></i> Sürücü Belgesi No <span class="text-danger" style="font-size:1.2rem;">*</span></label>
                                    <input type="text" name="license_number" class="form-control" placeholder="Örn: A12345678" required value="<?php echo htmlspecialchars($old['license_number'] ?? ''); ?>" style="border:2px solid #ffc107;">
                                </div>
                            </div>

                            <div id="merchantFields" class="role-fields" style="display:none;padding:20px;border:3px solid #198754;border-radius:10px;background:#e6f7ed;margin-top:20px;">
                                <h5 class="mb-3" style="color:#0f5132;font-size:1.3rem;">🏪 RESTORAN BİLGİLERİ</h5>
                                <div class="mb-3">
                                    <label style="font-weight:bold;"><i class="bi bi-geo-fill"></i> Hizmet Bölgesi <span class="text-danger" style="font-size:1.2rem;">*</span></label>
                                    <select name="merchant_zone_id" class="form-select" required style="border:2px solid #198754;">
                                        <option value="">-- Bölgenizi seçin --</option>
                                        <?php
                                        $zones3 = $conn->query("SELECT MIN(zone_id) as zone_id, zone_name, city FROM Zones WHERE is_active = 1 GROUP BY city, zone_name ORDER BY city, zone_name");
                                        while ($z = $zones3->fetch_assoc()) {
                                            $selected = (isset($old['merchant_zone_id']) && $old['merchant_zone_id'] == $z['zone_id']) ? 'selected' : '';
                                            echo '<option value="' . $z['zone_id'] . '" ' . $selected . '>' . htmlspecialchars($z['city']) . ' - ' . htmlspecialchars($z['zone_name']) . '</option>';
                                        }
                                        ?>
                                    </select>
                                    <small class="text-muted">Sadece bu bölgedeki müşterilere hizmet verebilirsiniz.</small>
                                </div>
                                <div class="mb-3">
                                    <label style="font-weight:bold;"><i class="bi bi-shop"></i> Mağaza Adı <span class="text-danger" style="font-size:1.2rem;">*</span></label>
                                    <input type="text" name="store_name" class="form-control" placeholder="Örn: Lezzetli Burger House" required value="<?php echo htmlspecialchars($old['store_name'] ?? ''); ?>" style="border:2px solid #198754;">
                                </div>
                                <div class="mb-3">
                                    <label style="font-weight:bold;"><i class="bi bi-receipt"></i> Vergi Numarası <span class="text-danger" style="font-size:1.2rem;">*</span></label>
                                    <input type="text" name="tax_number" class="form-control" placeholder="Örn: 1234567890" required value="<?php echo htmlspecialchars($old['tax_number'] ?? ''); ?>" style="border:2px solid #198754;">
                                </div>
                                <div class="mb-3">
                                    <label style="font-weight:bold;"><i class="bi bi-building"></i> Restoran Adresi <span class="text-danger" style="font-size:1.2rem;">*</span></label>
                                    <input type="text" name="merchant_address" class="form-control" placeholder="Örn: Bağdat Cad. No:123 Kadıköy" required value="<?php echo htmlspecialchars($old['merchant_address'] ?? ''); ?>" style="border:2px solid #198754;">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-success w-100" style="font-size:2rem;padding:20px;font-weight:bold;margin-top:30px;box-shadow: 0 4px 8px rgba(0,0,0,0.3);">
                                <i class="bi bi-check-circle-fill"></i> KAYIT OL
                            </button>
                        </form>

                        <script>
                            var roleSelect = document.getElementById('roleSelect');
                            function updateRoleFields(){
                                var role = roleSelect.value;
                                
                                var customerDiv = document.getElementById('customerFields');
                                var courierDiv = document.getElementById('courierFields');
                                var merchantDiv = document.getElementById('merchantFields');
                                
                                // Hide all first
                                customerDiv.style.display = 'none';
                                courierDiv.style.display = 'none';
                                merchantDiv.style.display = 'none';
                                
                                // Show selected role fields
                                if(role == '4') {
                                    customerDiv.style.display = 'block';
                                } else if(role == '3') {
                                    courierDiv.style.display = 'block';
                                } else if(role == '5') {
                                    merchantDiv.style.display = 'block';
                                }

                                // set/clear required attributes based on role
                                // Courier required: vehicle_type, license_number
                                var vehicleType = document.getElementsByName('vehicle_type')[0];
                                var licenseNumber = document.getElementsByName('license_number')[0];
                                var vehiclePlate = document.getElementsByName('vehicle_plate')[0];
                                var courierZone = document.getElementsByName('courier_zone_id')[0];
                                if(role == '3'){
                                    vehicleType.required = true;
                                    licenseNumber.required = true;
                                    vehiclePlate.required = false;
                                    courierZone.required = true;
                                } else {
                                    if(vehicleType) vehicleType.required = false;
                                    if(licenseNumber) licenseNumber.required = false;
                                    if(vehiclePlate) vehiclePlate.required = false;
                                    if(courierZone) courierZone.required = false;
                                }

                                // Merchant required: store_name, tax_number, merchant_address
                                var storeName = document.getElementsByName('store_name')[0];
                                var taxNumber = document.getElementsByName('tax_number')[0];
                                var merchantAddress = document.getElementsByName('merchant_address')[0];
                                var merchantZone = document.getElementsByName('merchant_zone_id')[0];
                                if(role == '5'){
                                    storeName.required = true;
                                    taxNumber.required = true;
                                    merchantAddress.required = true;
                                    merchantZone.required = true;
                                } else {
                                    if(storeName) storeName.required = false;
                                    if(taxNumber) taxNumber.required = false;
                                    if(merchantAddress) merchantAddress.required = false;
                                    if(merchantZone) merchantZone.required = false;
                                }

                                // Customer optional fields (no required changes)
                            }

                            roleSelect.addEventListener('change', updateRoleFields);
                            // Trigger immediately on page load
                            window.addEventListener('DOMContentLoaded', function() {
                                updateRoleFields();
                            });
                            // Also trigger right now
                            updateRoleFields();

                            // Client-side basic password and phone rule enforcement
                            document.getElementById('registerForm').addEventListener('submit', function(e) {
                                var pwd = this.password.value;
                                var phone = this.phone.value;
                                var pwdRe = /(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}/;
                                var phoneRe = /^05\d{9}$/;
                                if(!phoneRe.test(phone)){
                                    e.preventDefault();
                                    alert('Telefon numarası 05352114778 formatında olmalıdır.');
                                    return;
                                }
                                if(!pwdRe.test(pwd)){
                                    e.preventDefault();
                                    alert('Parola en az 8 karakter, büyük/küçük harf ve sayı içermelidir.');
                                }
                            });
                        </script>
                        <p class="mt-3 text-center">Zaten hesabın var mı? <a href="login.php">Giriş Yap</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>