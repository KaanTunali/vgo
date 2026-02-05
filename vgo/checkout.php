<?php
session_start();
include 'db.php';
@include_once __DIR__ . '/address_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 4) {
    header('Location: login.php');
    exit;
}
$user_id = $_SESSION['user_id'];

// Adresleri hazırla
ensure_default_address($conn, $user_id);
$addresses = get_user_addresses($conn, $user_id);
$activeAddress = resolve_active_address($conn, $user_id);
if ($activeAddress) { set_active_address($activeAddress); }

// Cart is persisted in DB; checkout shows summary and minimal inputs
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Checkout - VGO</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include 'header.php'; ?>
<div class="container mt-4">
    <a href="dashboard.php">&larr; Geri</a>
    <div class="row mt-3 g-3">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0">Sepet Özeti</h4>
                        <div class="d-flex gap-2">
                            <button id="clearCart" type="button" class="btn btn-sm btn-outline-danger">Sepeti Temizle</button>
                        </div>
                    </div>
                    <ul id="cartList" class="list-group mb-3"></ul>
                    <div class="d-flex justify-content-between">
                        <span class="fw-semibold">Toplam</span>
                        <span class="fw-bold" id="cartTotal">0.00 ₺</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="mb-3">Ödeme</h5>
                    <form id="checkoutForm" method="POST" action="create_order.php">
                        <div class="mb-3">
                            <label class="form-label">Teslimat Adresi</label>
                            <select name="address_id" id="addressSelect" class="form-select" required>
                                <?php foreach ($addresses as $addr): ?>
                                    <option value="<?php echo $addr['address_id']; ?>" <?php echo ($activeAddress && $activeAddress['address_id'] == $addr['address_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($addr['title']); ?> — <?php echo htmlspecialchars($addr['address_line']); ?>
                                        <?php echo $addr['zone_name'] ? ' (' . htmlspecialchars($addr['zone_name']) . ')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Farklı bir konum için lütfen adres değiştirin; mağaza listesi ve siparişler bu bölgeye göre filtrelenir.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kupon</label>
                            <input type="text" name="coupon_code" class="form-control" placeholder="Kupon kodu (isteğe bağlı)">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Ödeme Yöntemi</label>
                            <select name="payment_method" id="paymentMethod" class="form-select">
                                <option value="card">Kart</option>
                                <option value="cash">Nakit</option>
                            </select>
                        </div>

                        <div id="cardFields" class="mb-3">
                            <label class="form-label">Kart Bilgileri</label>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <input type="text" name="card_holder_name" id="card_holder_name" class="form-control" placeholder="Ad">
                                </div>
                                <div class="col-6">
                                    <input type="text" name="card_holder_surname" id="card_holder_surname" class="form-control" placeholder="Soyad">
                                </div>
                            </div>
                            <div class="mb-2">
                                <input type="text" name="card_number" id="card_number" class="form-control" inputmode="numeric" maxlength="19" placeholder="1234 5678 1234 5678">
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="text" name="card_expiry" id="card_expiry" class="form-control" placeholder="AA/YY" maxlength="5">
                                </div>
                                <div class="col-6">
                                    <input type="text" name="card_cvc" id="card_cvc" class="form-control" inputmode="numeric" maxlength="3" minlength="3" placeholder="CVV (3 hane)">
                                </div>
                            </div>
                        </div>

                        <button class="btn btn-primary w-100">Siparişi Gönder</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Cart handlers
function loadCart(){
    fetch('cart_get.php').then(r=>r.json()).then(function(j){
        var list = document.getElementById('cartList');
        list.innerHTML='';
        var total = 0;
        if (j.items && j.items.length){
            j.items.forEach(function(it){
                var li = document.createElement('li');
                li.className = 'list-group-item d-flex justify-content-between align-items-center';
                li.innerHTML = '<div><strong>'+it.product_name+'</strong> x'+it.quantity+'</div>' +
                               '<div class="d-flex align-items-center gap-2">'+
                                   '<span>'+Number(it.total_price).toFixed(2)+' ₺</span>'+
                                   '<button class="btn btn-sm btn-outline-danger" data-item-id="'+it.item_id+'">Sil</button>'+
                               '</div>';
                list.appendChild(li);
            });
            total = j.total || 0;
        } else {
            var li = document.createElement('li');
            li.className = 'list-group-item text-muted';
            li.textContent = 'Sepet boş.';
            list.appendChild(li);
        }
        document.getElementById('cartTotal').textContent = Number(total).toFixed(2) + ' ₺';
    });
}
document.getElementById('clearCart').addEventListener('click', function(){
    fetch('cart_clear.php', {method:'POST'}).then(()=>loadCart());
});

function toggleCardFields(){
    var method = document.getElementById('paymentMethod').value;
    var cardSection = document.getElementById('cardFields');
    var show = method === 'card';
    cardSection.style.display = show ? 'block' : 'none';
    ['card_number','card_expiry','card_cvc','card_holder_name','card_holder_surname'].forEach(function(id){
        var el = document.getElementById(id);
        if (!el) return;
        el.required = show;
        if (!show) el.value = '';
    });
}
document.getElementById('paymentMethod').addEventListener('change', toggleCardFields);
toggleCardFields();
document.getElementById('card_number').addEventListener('input', function(e){
    var digits = e.target.value.replace(/\D/g, '').slice(0,16);
    var parts = digits.match(/.{1,4}/g) || [];
    e.target.value = parts.join(' ');
});
document.getElementById('card_expiry').addEventListener('input', function(e){
    var v = e.target.value.replace(/[^\d]/g, '').slice(0,4);
    if (v.length > 2) {
        v = v.slice(0,2) + '/' + v.slice(2);
    }
    e.target.value = v;
});
document.getElementById('checkoutForm').addEventListener('submit', function(e){
    if (document.getElementById('paymentMethod').value !== 'card') return;
    var name = document.getElementById('card_holder_name').value.trim();
    var surname = document.getElementById('card_holder_surname').value.trim();
    var num = document.getElementById('card_number').value.replace(/\s+/g,'');
    var exp = document.getElementById('card_expiry').value.trim();
    var cvc = document.getElementById('card_cvc').value.trim();

    if (!name || !surname) {
        e.preventDefault();
        alert('Kart üzerindeki ad ve soyad gerekli.');
        return;
    }

    // Card number: exactly 16 digits
    if (!/^\d{16}$/.test(num)) {
        e.preventDefault();
        alert('Kart numarası 16 haneli olmalı.');
        return;
    }

    // Expiry: MM/YY and not in the past (year >= current)
    var expMatch = exp.match(/^(0[1-9]|1[0-2])\/(\d{2})$/);
    if (!expMatch) {
        e.preventDefault();
        alert('Son kullanma tarihi AA/YY formatında olmalı.');
        return;
    }
    var month = parseInt(expMatch[1], 10);
    var year = parseInt('20' + expMatch[2], 10);
    var now = new Date();
    var thisMonth = now.getMonth() + 1;
    var thisYear = now.getFullYear();
    if (year < thisYear || (year === thisYear && month < thisMonth)) {
        e.preventDefault();
        alert('Son kullanma tarihi geçmiş olamaz.');
        return;
    }

    // CVC: exactly 3 digits
    if (!/^\d{3}$/.test(cvc)) {
        e.preventDefault();
        alert('CVV 3 haneli olmalı.');
        return;
    }
});
// initial load
loadCart();
</script>
</body>
</html>