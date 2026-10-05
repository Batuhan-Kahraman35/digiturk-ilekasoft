<?php
/**
 * Admin Panel - Header Component
 * Portal Örnek Yazılım
 */

// $user değişkeni ana sayfada tanımlanmalı
if (!isset($user)) {
    die('Header component requires $user variable to be set.');
}

$_isImpersonating = Auth::isImpersonating();
$_impersonator    = Auth::impersonator();

// Bildirim çanı: giriş yapmış TÜM kullanıcılara görünür.
// Bildirimler artık dbo.Bildirimler'den geliyor ve kişiye özel (kendi + genel);
// içerik zaten kullanıcıya göre filtrelendiği için sayfa yetkisine bağlamaya gerek yok.
// Bekleyen başvuru uyarıları API tarafında ayrıca yetki kontrolünden geçer.
$hasNotificationAccess = isset($user['kullanici_id']);

// Destek widget'ı: giriş yapmış TÜM kullanıcılara görünür (API aktifse)
$hasDestekAccess = false;
if (isset($user['kullanici_id'])) {
    require_once __DIR__ . '/DestekHelper.php';
    $hasDestekAccess = DestekHelper::aktifMi();
}

// Header menülerini çek (sadece departman_id=1)
$headerMenus = [];
if (($user['departman_id'] ?? null) == 1 && isset($db)) {
    $headerMenuItems = $db->fetchAll("
        SELECT menuler_id, menuler_menu_adi, menuler_ikon
        FROM Menuler
        WHERE menuler_durum = 1 AND menuler_header_goster = 1 AND menuler_parent_id IS NULL
        ORDER BY menuler_sira_no
    ");
    foreach ($headerMenuItems as $hMenu) {
        $hMenu['pages'] = $db->fetchAll("
            SELECT sayfalar_sayfa_adi, sayfalar_sayfa_url, sayfalar_ikon
            FROM Menu_Sayfalar
            WHERE sayfalar_menu_id = ? AND sayfalar_durum = 1
            ORDER BY sayfalar_sira_no
        ", [$hMenu['menuler_id']]);
        $headerMenus[] = $hMenu;
    }
}
?>
<!--begin::Header-->
<nav class="app-header navbar navbar-expand bg-body">
    <!--begin::Container-->
    <div class="container-fluid">
        <!--begin::Start Navbar Links-->
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button">
                    <i class="bi bi-list"></i>
                </a>
            </li>
            <li class="nav-item d-none d-md-block">
                <a href="/admin/anasayfa" class="nav-link">Ana Sayfa</a>
            </li>
            <?php foreach ($headerMenus as $hMenu): ?>
            <li class="nav-item dropdown d-none d-md-block">
                <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" role="button">
                    <?php if ($hMenu['menuler_ikon']): ?><i class="<?= htmlspecialchars($hMenu['menuler_ikon']) ?>"></i> <?php endif; ?>
                    <?= htmlspecialchars($hMenu['menuler_menu_adi']) ?>
                </a>
                <ul class="dropdown-menu">
                    <?php foreach ($hMenu['pages'] as $hPage): ?>
                    <?php
                        $hUrl = str_replace('.php', '', $hPage['sayfalar_sayfa_url']);
                        $hUrl = preg_replace('/^pages\//', '', $hUrl);
                    ?>
                    <li>
                        <a class="dropdown-item" href="/admin/<?= htmlspecialchars($hUrl) ?>">
                            <?php if ($hPage['sayfalar_ikon']): ?><i class="<?= htmlspecialchars($hPage['sayfalar_ikon']) ?>"></i> <?php endif; ?>
                            <?= htmlspecialchars($hPage['sayfalar_sayfa_adi']) ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </li>
            <?php endforeach; ?>
        </ul>
        <!--end::Start Navbar Links-->
        
        <!--begin::End Navbar Links-->
        <ul class="navbar-nav ms-auto">
            <?php if ($hasNotificationAccess): ?>
            <!--begin::Notifications Dropdown Menu-->
            <li class="nav-item dropdown">
                <a class="nav-link" data-bs-toggle="dropdown" href="#" id="notificationDropdown">
                    <i class="bi bi-bell"></i>
                    <span class="navbar-badge badge text-bg-warning" id="notificationBadge" style="display: none;">0</span>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end" id="notificationMenu">
                    <span class="dropdown-item dropdown-header" id="notificationHeader">
                        <i class="bi bi-bell"></i> Bildirimler yükleniyor...
                    </span>
                    <div class="dropdown-divider"></div>
                    <div id="notificationList">
                        <!-- Bildirimler buraya gelecek -->
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item dropdown-footer" id="notificationMarkAll">
                        <i class="bi bi-check2-all me-1"></i>Tümünü Okundu İşaretle
                    </a>
                </div>
            </li>
            <!--end::Notifications Dropdown Menu-->
            <?php endif; ?>
            
            <?php if ($hasDestekAccess): ?>
            <!--begin::Destek Bildirim Dropdown-->
            <style>
                .destek-header-btn {
                    position: relative; display: inline-flex; align-items: center; gap: .4rem;
                    background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: #fff !important;
                    padding: .35rem .9rem; border-radius: 2rem; font-size: .85rem; font-weight: 600;
                    text-decoration: none; transition: opacity .2s;
                }
                .destek-header-btn:hover { opacity: .9; color: #fff; }
                .destek-header-btn .destek-badge {
                    position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px;
                    padding: 0 5px; background: #dc3545; color: #fff; border: 2px solid #fff;
                    border-radius: 10px; font-size: .62rem; font-weight: 700; line-height: 14px;
                    text-align: center; display: none;
                }
                @keyframes destekShake {
                    0%,100% { transform: rotate(0); } 15% { transform: rotate(10deg); }
                    30% { transform: rotate(-8deg); } 45% { transform: rotate(6deg); }
                    60% { transform: rotate(-4deg); } 75% { transform: rotate(2deg); }
                }
                .destek-header-btn.shake { animation: destekShake .6s ease-in-out; }
                .destek-yeni-etiket {
                    background: #dc3545; color: #fff; font-size: .58rem; font-weight: 700;
                    padding: 1px 5px; border-radius: 4px; margin-right: 5px; vertical-align: middle;
                }
            </style>
            <li class="nav-item dropdown d-flex align-items-center me-2">
                <a class="destek-header-btn" data-bs-toggle="dropdown" href="#" id="destekDropdown" role="button" title="Destek Talepleri">
                    <i class="bi bi-headset"></i>
                    <span class="d-none d-md-inline">Destek</span>
                    <span class="destek-badge" id="destekBadge">0</span>
                </a>
                <div class="dropdown-menu dropdown-menu-end shadow-sm" style="width: 340px; max-height: 430px; overflow-y: auto;">
                    <span class="dropdown-item dropdown-header"><i class="bi bi-headset"></i> Destek Talepleri</span>
                    <div class="dropdown-divider"></div>
                    <div id="destekList">
                        <span class="dropdown-item text-muted small">Yükleniyor...</span>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="/admin/destek-taleplerim" class="dropdown-item dropdown-footer text-center fw-bold text-primary">Tüm Talepleri Gör</a>
                </div>
            </li>
            <!--end::Destek Bildirim Dropdown-->
            <?php endif; ?>

            <!--begin::Fullscreen Toggle-->
            <li class="nav-item">
                <a class="nav-link" href="#" data-lte-toggle="fullscreen">
                    <i data-lte-icon="maximize" class="bi bi-arrows-fullscreen"></i>
                    <i data-lte-icon="minimize" class="bi bi-fullscreen-exit" style="display: none"></i>
                </a>
            </li>
            <!--end::Fullscreen Toggle-->
            
            <!--begin::User Menu Dropdown-->
            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <img
                        src="/admin/assets/images/user-avatar.png"
                        class="user-image rounded-circle shadow"
                        alt="<?= htmlspecialchars($user['name']) ?>"
                        onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22160%22 height=%22160%22%3E%3Crect fill=%22%23667eea%22 width=%22160%22 height=%22160%22/%3E%3Ctext fill=%22%23fff%22 font-family=%22Arial%22 font-size=%2260%22 x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22%3E<?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>%3C/text%3E%3C/svg%3E'"
                    />
                    <span class="d-none d-md-inline"><?= htmlspecialchars($user['name']) ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                    <!--begin::User Image-->
                    <li class="user-header text-bg-primary">
                        <img
                            src="/admin/assets/images/user-avatar.png"
                            class="rounded-circle shadow"
                            alt="<?= htmlspecialchars($user['name']) ?>"
                            onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22160%22 height=%22160%22%3E%3Crect fill=%22%23ffffff%22 width=%22160%22 height=%22160%22/%3E%3Ctext fill=%22%23667eea%22 font-family=%22Arial%22 font-size=%2260%22 x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22%3E<?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>%3C/text%3E%3C/svg%3E'"
                        />
                        <p>
                            <?= htmlspecialchars($user['name']) ?>
                            <small><?= htmlspecialchars($user['email']) ?></small>
                        </p>
                    </li>
                    <!--end::User Image-->
                    
                    <!--begin::Menu Body-->
                    <li class="user-body">
                        <div class="row">
                            <div class="col-12 text-center">
                                <small class="text-muted">
                                    <i class="bi bi-clock"></i> Son giriş: <?= date('d.m.Y H:i:s', $_SESSION['login_time']) ?>
                                </small>
                            </div>
                        </div>
                    </li>
                    <!--end::Menu Body-->
                    
                    <!--begin::Menu Footer-->
                    <li class="user-footer">
                        <a href="/admin/profil" class="btn btn-default btn-flat">Profil</a>
                        <a href="/admin/logout.php" class="btn btn-default btn-flat float-end">Çıkış Yap</a>
                    </li>
                    <!--end::Menu Footer-->

                    <?php if ($_isImpersonating && $_impersonator): ?>
                    <!--begin::Impersonate Notice-->
                    <li style="background:#fff3e0; border-top:2px solid #ff9800; padding:10px 14px;">
                        <div style="font-size:0.78rem; color:#e65100; line-height:1.5;">
                            <i class="bi bi-person-fill-badge"></i>
                            <strong><?= htmlspecialchars($user['name']) ?></strong> olarak giriş yapılmış durumdasınız.<br>
                            <span class="text-muted">
                                Orijinal: <strong><?= htmlspecialchars($_impersonator['user_name']) ?></strong>
                            </span>
                        </div>
                        <a href="/admin/impersonate-cikis.php"
                           class="btn btn-sm btn-warning w-100 mt-2"
                           style="font-size:0.8rem;">
                            <i class="bi bi-box-arrow-left"></i> Kendi Hesabıma Dön
                        </a>
                    </li>
                    <!--end::Impersonate Notice-->
                    <?php endif; ?>
                </ul>
            </li>
            <!--end::User Menu Dropdown-->
        </ul>
        <!--end::End Navbar Links-->
    </div>
    <!--end::Container-->
</nav>
<!--end::Header-->
<?php if ($hasDestekAccess): ?>
<script>
/* Destek bildirim polling — API key proxy'de (server-side) kalır */
(function () {
    var URL = '/admin/destek-bildirim';
    var LS  = 'destek_son_kontrol';
    var oncekiYeni = 0;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
        });
    }

    function sonKontrol() {
        var v = localStorage.getItem(LS);
        if (!v) { v = new Date().toISOString(); localStorage.setItem(LS, v); }
        return v;
    }

    function kontrolEt() {
        var badge = document.getElementById('destekBadge');
        var liste = document.getElementById('destekList');
        var btn   = document.getElementById('destekDropdown');
        if (!badge || !liste || !btn) return;

        fetch(URL + '?son_kontrol=' + encodeURIComponent(sonKontrol()))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) return;

                // ─── Badge + shake ───
                if (d.yeni_sayisi > 0) {
                    badge.textContent = d.yeni_sayisi > 99 ? '99+' : d.yeni_sayisi;
                    badge.style.display = 'block';
                    if (d.yeni_sayisi > oncekiYeni) { // yeni yanıt ilk düştüğünde salla
                        btn.classList.remove('shake');
                        void btn.offsetWidth; // reflow
                        btn.classList.add('shake');
                    }
                } else {
                    badge.style.display = 'none';
                }
                oncekiYeni = d.yeni_sayisi || 0;

                // ─── Son talepler listesi (yeni olanlarda kırmızı "Yeni" etiketi) ───
                var t = d.ticketler || [];
                if (!t.length) {
                    liste.innerHTML = '<span class="dropdown-item text-muted small">Talep bulunmuyor</span>';
                    return;
                }
                var html = '';
                t.forEach(function (x) {
                    var yeniEtiket = x.yeni ? '<span class="destek-yeni-etiket">Yeni</span>' : '';
                    html += '<a class="dropdown-item py-2 text-wrap" href="/admin/destek-talep-detay?id=' + parseInt(x.id) + '">' +
                            '<div class="small">' + yeniEtiket + '<strong>#' + esc(x.no) + '</strong> ' + esc(x.konu) + '</div>' +
                            '<div class="text-muted" style="font-size:.72rem"><i class="bi bi-clock me-1"></i>' +
                            esc(x.son_yanit) + ' · ' + esc(x.durum_ad) + '</div></a>';
                });
                liste.innerHTML = html;
            })
            .catch(function () { /* sessiz geç */ });
    }

    document.addEventListener('DOMContentLoaded', function () {
        kontrolEt();
        setInterval(kontrolEt, 60000);
    });
})();
</script>
<?php endif; ?>
