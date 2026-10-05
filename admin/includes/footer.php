<?php
/**
 * Admin Panel - Footer Component
 * Portal Örnek Yazılım
 */

// Veritabanından site ayarlarını çek
if (!isset($db)) {
    $db = Database::getInstance();
}

$footerYazi = '';
$siteAyarlari = null;

try {
    $siteAyarlari = $db->fetchOne("
        SELECT TOP 1 site_ayarlari_footer_yazi, site_ayarlari_site_title, site_ayarlari_favicon_url
        FROM dbo.tanim_site_ayarlari
        ORDER BY site_ayarlari_id DESC
    ");
} catch (Exception $e) {
    error_log("Footer SQL hatası: " . $e->getMessage());
}

if ($siteAyarlari && !empty($siteAyarlari['site_ayarlari_footer_yazi'])) {
    $footerYazi = htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi']);
} else {
    $footerYazi = '© ' . date('Y') . ' Örnek Yazılım Portal. Tüm hakları saklıdır.';
}

// En son versiyon numarasını çek
$sonVersiyon = null;
$versiyonNo = '1.0.0';

try {
    $sonVersiyon = $db->fetchOne("
        SELECT TOP 1 surum_versiyon 
        FROM Sistem_Surum_Notlari 
        ORDER BY surum_id DESC
    ");
    if ($sonVersiyon) {
        $versiyonNo = $sonVersiyon['surum_versiyon'];
    }
} catch (Exception $e) {
    error_log("Versiyon SQL hatası: " . $e->getMessage());
}
?>
<!--begin::Footer-->
<footer class="app-footer" style="display: block; visibility: visible; height: auto;">
    <div class="float-start">
        <strong><?= $footerYazi ?? '© 2026 Örnek Yazılım Portal' ?></strong>
    </div>
    <div class="float-end">
        <a href="/admin/surum-notlari" class="text-decoration-none" title="Sürüm notlarını görüntüle">
            <i class="bi bi-info-circle me-1"></i>Versiyon <?= htmlspecialchars($versiyonNo ?? '1.0.0') ?>
        </a>
    </div>
</footer>
<!--end::Footer-->


<?php if (isset($hasNotificationAccess) && $hasNotificationAccess): ?>
<!-- Bildirim Sistemi -->
<script>
// jQuery yüklenene kadar bekle
(function checkJQuery() {
    if (typeof jQuery !== 'undefined') {
        initNotifications();
    } else {
        setTimeout(checkJQuery, 50);
    }
})();

function initNotifications() {
    // Bildirimleri yükle
    function loadNotifications() {
        $.get('/admin/api/notifications.php?action=get_notifications', function(response) {
            if (response.success) {
                const count = response.count || 0;
                const bildirimler = response.data || [];
                
                // Badge güncelle
                const badge = $('#notificationBadge');
                if (count > 0) {
                    badge.text(count > 9 ? '9+' : count).show();
                } else {
                    badge.hide();
                }
                
                // Header güncelle
                $('#notificationHeader').html(`<i class="bi bi-bell"></i> ${count} Bildirim`);
                
                // Liste güncelle
                const liste = $('#notificationList');
                liste.empty();

                if (bildirimler.length > 0) {
                    bildirimler.forEach(bildirim => {
                        // Okunmamışlar vurgulanır; okunanlar soluk gösterilir
                        const vurgu = bildirim.okundu ? '' : 'fw-semibold bg-body-secondary';
                        const item = `
                            <a href="${bildirim.link}" class="dropdown-item ${vurgu}"
                               data-bildirim-id="${bildirim.bildirim_id || ''}">
                                <i class="bi ${bildirim.icon} me-2 text-${bildirim.renk}"></i>
                                <div class="d-inline-block text-truncate" style="max-width: 250px;">
                                    <strong>${bildirim.baslik}:</strong> ${bildirim.mesaj || ''}
                                </div>
                                <span class="float-end text-muted text-sm">${bildirim.zaman}</span>
                            </a>
                            <div class="dropdown-divider"></div>
                        `;
                        liste.append(item);
                    });
                } else {
                    liste.html('<div class="dropdown-item text-center text-muted py-3">Yeni bildirim yok</div>');
                }
            }
        }).fail(function() {
            $('#notificationHeader').html('<i class="bi bi-bell"></i> Bildirimler yüklenemedi');
            $('#notificationList').html('<div class="dropdown-item text-center text-danger py-3">Hata oluştu</div>');
        });
    }
    
    // Bildirime tıklanınca okundu işaretle (sayfa değişmeden önce gitsin diye keepalive)
    function okunduIsaretle(bildirimId) {
        const govde = new FormData();
        govde.append('action', 'mark_read');
        if (bildirimId) govde.append('bildirim_id', bildirimId);
        return fetch('/admin/api/notifications.php', {
            method: 'POST',
            body: govde,
            credentials: 'same-origin',
            keepalive: true
        });
    }

    // Sayfa yüklendiğinde bildirimleri yükle
    $(document).ready(function() {
        loadNotifications();

        // Her 60 saniyede bir güncelle
        setInterval(loadNotifications, 60000);

        // Dropdown açıldığında yenile
        $('#notificationDropdown').on('click', function() {
            loadNotifications();
        });

        // Bildirime tıklama: okundu yap, sonra linke git
        $('#notificationList').on('click', 'a[data-bildirim-id]', function() {
            const id = $(this).data('bildirim-id');
            if (id) okunduIsaretle(id);   // türetilmiş başvurularda id boş -> atlanır
        });

        // Tümünü okundu işaretle
        $('#notificationMarkAll').on('click', function(e) {
            e.preventDefault();
            okunduIsaretle(null).then(loadNotifications);
        });
    });
}
</script>
<?php endif; ?>

<?php
/**
 * ─── PWA (Progressive Web App) ───
 * Manifest ve meta etiketleri <head>'e JS ile enjekte edilir; böylece her sayfanın
 * kendi <head>'ini düzenlemek gerekmez (sayfalar head'ini kendisi yazıyor).
 * Yetki koşulu YOK: PWA tüm giriş yapmış kullanıcılara açıktır.
 */
$_pwaBaslik = 'Digiturk Portal';
if ($siteAyarlari && !empty($siteAyarlari['site_ayarlari_site_title'])) {
    $_pwaBaslik = mb_substr($siteAyarlari['site_ayarlari_site_title'], 0, 30);
}
// Sekme ikonu: admin sayfalarının kendi <head>'inde favicon linki yok; tarayıcı
// varsayılan /favicon.ico'yu (kırmızı D) gösteriyordu. Site ve PWA ile ortak olması için
// site_ayarlari_favicon_url (kırmızı halka) buraya enjekte edilir.
$_faviconUrl = ($siteAyarlari['site_ayarlari_favicon_url'] ?? '') ?: '/favicon.ico';
?>
<script>
(function () {
    var h = document.head;
    var ekle = function (etiket, ozellikler) {
        var e = document.createElement(etiket);
        for (var k in ozellikler) e.setAttribute(k, ozellikler[k]);
        h.appendChild(e);
    };
    ekle('link', { rel: 'icon', href: <?= json_encode($_faviconUrl, JSON_UNESCAPED_SLASHES) ?> });
    ekle('link', { rel: 'manifest', href: '/admin/manifest.php' });
    ekle('meta', { name: 'theme-color', content: '#0d6efd' });
    ekle('meta', { name: 'mobile-web-app-capable', content: 'yes' });
    ekle('meta', { name: 'apple-mobile-web-app-capable', content: 'yes' });
    ekle('meta', { name: 'apple-mobile-web-app-status-bar-style', content: 'default' });
    ekle('meta', { name: 'apple-mobile-web-app-title', content: <?= json_encode($_pwaBaslik, JSON_UNESCAPED_UNICODE) ?> });
    ekle('link', { rel: 'apple-touch-icon', href: '/admin/icon.php?size=192' });
})();
</script>
<script src="/admin/assets/js/pwa.js" defer></script>
<?php
// --- Oturum Sure Takibi ---
// Sure bitimine 30 sn kala uyari (uzatma / cikis), ayrica oturumu bitmis
// AJAX isteklerinin (401) yakalanmasi. Script kosulsuz yuklenir: kalan sure
// sifir olsa bile 401 yakalayicisinin devrede olmasi gerekir.
if (Auth::check()):
    $_oturumKalan = !empty($_SESSION['login_time'])
        ? oturumTimeoutSaniye() - (time() - $_SESSION['login_time'])
        : 0;
?>
<script>
window.OTURUM_TAKIP = {
    kalan: <?= max(0, (int)$_oturumKalan) ?>,
    omur:  <?= (int)oturumTimeoutSaniye() ?>
};
</script>
<script src="/admin/assets/js/oturum-takip.js?v=<?= @filemtime(__DIR__ . '/../assets/js/oturum-takip.js') ?>" defer></script>
<?php endif; ?>