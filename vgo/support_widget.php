<!-- Destek Widget - Tüm sayfalara eklenebilir -->
<div class="support-widget" id="supportWidget">
    <button class="support-btn" onclick="toggleSupportWidget()" title="Canlı Destek">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
        </svg>
        <span class="support-badge" id="supportBadge" style="display:none;">0</span>
    </button>
</div>

<style>
.support-widget {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 1000;
}

.support-btn {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    transition: all 0.3s ease;
    position: relative;
}

.support-btn:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
}

.support-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #ff4444;
    color: white;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: bold;
}

.support-modal {
    position: fixed;
    bottom: 90px;
    right: 20px;
    width: 380px;
    max-height: 600px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    display: none;
    flex-direction: column;
    z-index: 999;
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.support-modal.active {
    display: flex;
}

.support-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 16px;
    border-radius: 12px 12px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.support-body {
    padding: 16px;
    flex: 1;
    overflow-y: auto;
    max-height: 400px;
}

.faq-list {
    margin-top: 10px;
}

.faq-item {
    padding: 10px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.faq-item:hover {
    background: #f5f5f5;
    border-color: #667eea;
}

.quick-action-btn {
    display: block;
    width: 100%;
    padding: 12px;
    margin-bottom: 8px;
    border: 1px solid #667eea;
    background: white;
    color: #667eea;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    text-align: left;
}

.quick-action-btn:hover {
    background: #667eea;
    color: white;
}

@media (max-width: 480px) {
    .support-modal {
        width: calc(100% - 40px);
        right: 20px;
        left: 20px;
    }
}
</style>

<div class="support-modal" id="supportModal">
    <div class="support-header">
        <div>
            <h6 class="mb-0">Canlı Destek</h6>
            <small>Size nasıl yardımcı olabiliriz?</small>
        </div>
        <button onclick="toggleSupportWidget()" class="btn-close btn-close-white"></button>
    </div>
    <div class="support-body">
        <div id="supportContent">
            <?php if (isset($_SESSION['user_id'])): ?>
            <h6>Hızlı Bildirim</h6>
            <button class="quick-action-btn" onclick="openSupportTicket('system_issue')">
                🛠️ Sistemsel Sorun Bildir
            </button>
            <hr>
            <?php endif; ?>

            <?php if (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 2): // Operatör ?>
            <h6>Operatör</h6>
            <button class="quick-action-btn" onclick="location.href='operator_dashboard.php'">
                🧑‍💻 Operatör Paneline Git
            </button>
            <button class="quick-action-btn" onclick="location.href='operator_dashboard.php'">
                📥 Ticket Kutusu (Müşteri/Kurye/Merchant)
            </button>

            <?php elseif (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 3): // Kurye ?>
            <h6>Hızlı Yardım</h6>
            <button class="quick-action-btn" onclick="openSupportTicket('delivery_problem')">
                🚫 Teslimat Problemi (Adres/Müşteri)
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('customer_unreachable')">
                ☎️ Müşteriye Ulaşılamıyor
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('pickup_issue')">
                📦 Restorandan Teslim Alma Sorunu
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('technical_issue')">
                📱 Teknik Sorun (Uygulama/GPS)
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('payment_earnings')">
                💰 Ödeme/Kazanç Sorunu
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('account_issue')">
                👤 Hesap/Belgeler/Profil Sorunu
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('accident_insurance')">
                🚨 Kaza/Acil Durum
            </button>
            <button class="quick-action-btn" onclick="location.href='support.php'">
                💬 Canlı Destek ile Konuş
            </button>
            
            <h6 class="mt-3">Sık Sorulan Sorular</h6>
            <div class="faq-list" id="faqList">
                <div class="faq-item" onclick="showFAQ('courier_earnings')">
                    <small><strong>❓ Kazancımı nasıl görürüm?</strong></small>
                </div>
                <div class="faq-item" onclick="showFAQ('courier_zone')">
                    <small><strong>❓ Bölge nasıl değiştirilir?</strong></small>
                </div>
                <div class="faq-item" onclick="showFAQ('courier_reject')">
                    <small><strong>❓ Teslimat kabul etmezsem ne olur?</strong></small>
                </div>
                <div class="faq-item" onclick="showFAQ('courier_accident')">
                    <small><strong>❓ Kaza durumunda ne yapmalıyım?</strong></small>
                </div>
            </div>
            
            <?php elseif (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 5): // Merchant ?>
            <h6>Hızlı Yardım</h6>
            <button class="quick-action-btn" onclick="openSupportTicket('order_issue')">
                📦 Sipariş Yönetimi Sorunu
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('product_issue')">
                🍔 Ürün/Menü Güncelleme
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('store_hours')">
                🕒 Çalışma Saatleri / Mağaza Durumu
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('courier_issue')">
                🛵 Kurye / Teslimat Süreci Sorunu
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('payment_settlement')">
                💳 Ödeme/Hesap Özeti
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('technical_issue')">
                📱 Teknik Sorun
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('account_issue')">
                👤 Hesap/Yetki/Profil Sorunu
            </button>
            <button class="quick-action-btn" onclick="location.href='support.php'">
                💬 Canlı Destek ile Konuş
            </button>
            
            <h6 class="mt-3">Sık Sorulan Sorular</h6>
            <div class="faq-list" id="faqList">
                <div class="faq-item" onclick="showFAQ('merchant_commission')">
                    <small><strong>❓ Komisyon oranları nedir?</strong></small>
                </div>
                <div class="faq-item" onclick="showFAQ('merchant_payment')">
                    <small><strong>❓ Ödemeler ne zaman yapılır?</strong></small>
                </div>
                <div class="faq-item" onclick="showFAQ('merchant_menu')">
                    <small><strong>❓ Menü nasıl güncellenir?</strong></small>
                </div>
            </div>
            
            <?php else: // Customer ?>
            <h6>Hızlı Yardım</h6>
            <button class="quick-action-btn" onclick="openSupportTicket('order_issue')">
                📦 Siparişimle İlgili Sorun
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('payment_issue')">
                💳 Ödeme Sorunu
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('delivery_issue')">
                🚚 Teslimat Sorunu
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('refund_issue')">
                💸 İade / Ücret İadesi
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('coupon_issue')">
                🎟️ Kupon / Kampanya Sorunu
            </button>
            <button class="quick-action-btn" onclick="openSupportTicket('account_issue')">
                👤 Hesap / Giriş Sorunu
            </button>
            <button class="quick-action-btn" onclick="location.href='support.php'">
                💬 Canlı Destek ile Konuş
            </button>
            
            <h6 class="mt-3">Sık Sorulan Sorular</h6>
            <div class="faq-list" id="faqList">
                <div class="faq-item" onclick="showFAQ('customer_delivery_time')">
                    <small><strong>❓ Siparişim ne zaman gelir?</strong></small>
                </div>
                <div class="faq-item" onclick="showFAQ('customer_cancel')">
                    <small><strong>❓ Siparişimi nasıl iptal edebilirim?</strong></small>
                </div>
                <div class="faq-item" onclick="showFAQ('customer_coupon')">
                    <small><strong>❓ Kupon kodum çalışmıyor</strong></small>
                </div>
            </div>
            <?php endif; ?>
            
            <?php
                $faqHref = 'support.php';
                if (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 2) {
                    $faqHref = 'operator_dashboard.php';
                } elseif (isset($_SESSION['role_id']) && ((int)$_SESSION['role_id'] === 1 || (int)$_SESSION['role_id'] === 6)) {
                    $faqHref = 'support_manager.php';
                }
            ?>
            <button class="btn btn-sm btn-primary w-100 mt-3" onclick="location.href='<?php echo htmlspecialchars($faqHref); ?>'">
                Tüm FAQ'leri Gör
            </button>
        </div>
    </div>
</div>

<script>
function toggleSupportWidget() {
    const modal = document.getElementById('supportModal');
    modal.classList.toggle('active');
}

function openSupportTicket(category) {
    // Kategori ile support.php'ye yönlendir (category + subject)
    // Not: support.php hem sayısal categoryId hem de metin category destekler.
    let categoryValue = 'General';
    let subject = 'Destek Talebi';

    switch (category) {
        case 'system_issue':
            categoryValue = 'Technical'; subject = 'Sistemsel Sorun'; break;
        // Customer
        case 'order_issue':
            categoryValue = 'Order'; subject = 'Sipariş Sorunu'; break;
        case 'payment_issue':
            categoryValue = 'Payment'; subject = 'Ödeme Sorunu'; break;
        case 'delivery_issue':
            categoryValue = 'Delivery'; subject = 'Teslimat Sorunu'; break;
        case 'refund_issue':
            categoryValue = 'Refund'; subject = 'İade / Ücret İadesi'; break;
        case 'coupon_issue':
            categoryValue = 'Campaign'; subject = 'Kupon / Kampanya Sorunu'; break;
        case 'account_issue':
            categoryValue = 'Account'; subject = 'Hesap / Giriş / Profil Sorunu'; break;

        // Merchant
        case 'product_issue':
            categoryValue = 'Quality'; subject = 'Ürün / Menü / Stok Sorunu'; break;
        case 'store_hours':
            categoryValue = 'General'; subject = 'Çalışma Saatleri / Mağaza Durumu'; break;
        case 'courier_issue':
            categoryValue = 'Delivery'; subject = 'Kurye / Teslimat Süreci Sorunu'; break;
        case 'payment_settlement':
            categoryValue = 'Payment'; subject = 'Ödeme / Mutabakat / Kesinti Sorunu'; break;
        case 'technical_issue':
            categoryValue = 'Technical'; subject = 'Teknik Sorun'; break;

        // Courier
        case 'delivery_problem':
            categoryValue = 'Delivery'; subject = 'Teslimat Problemi (Adres/Müşteri)'; break;
        case 'customer_unreachable':
            categoryValue = 'Delivery'; subject = 'Müşteriye Ulaşılamıyor'; break;
        case 'pickup_issue':
            categoryValue = 'Delivery'; subject = 'Restorandan Teslim Alma Sorunu'; break;
        case 'accident_insurance':
            categoryValue = 'Delivery'; subject = 'Kaza / Acil Durum Bildirimi'; break;
        case 'payment_earnings':
            categoryValue = 'Payment'; subject = 'Kurye Ödeme / Kazanç Sorunu'; break;
    }

    const url = 'support.php?new=1&category=' + encodeURIComponent(categoryValue) + '&subject=' + encodeURIComponent(subject);
    location.href = url;
}

function showFAQ(faqId) {
    location.href = 'support_faq.php?id=' + faqId;
}

// Okunmamış mesaj kontrolü (her 30 saniyede)
<?php if (isset($_SESSION['user_id'])): ?>
function checkUnreadMessages() {
    fetch('api/check_support_notifications.php')
        .then(r => r.json())
        .then(data => {
            const badge = document.getElementById('supportBadge');
            if (data.unread > 0) {
                badge.textContent = data.unread;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        })
        .catch(err => console.log('Support check error:', err));
}
checkUnreadMessages();
setInterval(checkUnreadMessages, 30000);
<?php endif; ?>
</script>
