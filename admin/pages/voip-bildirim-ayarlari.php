<?php
/**
 * Admin Panel - VoIP Bakiye Bildirim Ayarları
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';

requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPagefile = basename($_SERVER['PHP_SELF']);
$pageinfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'VoIP Bildirim Ayarları';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok.');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ─── AJAX ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    // Sayfa emekliye ayrıldı: yalnız okuma işlemleri açık. Yazma işlemleri
    // Hatırlatma Yönetimi'ne taşındı (CronHatirlatmaKurallari / CronHatirlatmaAsamalari).
    if (!in_array($action, ['kural_listele', 'kanal_listesi'], true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Bu sayfa salt okunur. Bildirim kuralları Hatırlatma Yönetimi sayfasından yönetilir.',
        ]);
        exit;
    }

    try {
        switch ($action) {

            case 'kural_listele':
                $liste = $db->fetchAll("
                    SELECT
                        k.VoIPBildirimKurallari_id,
                        k.VoIPBildirimKurallari_EsikTutar,
                        k.VoIPBildirimKurallari_HedefNo,
                        k.VoIPBildirimKurallari_TedarikciNo,
                        k.VoIPBildirimKurallari_Mesaj,
                        k.VoIPBildirimKurallari_TedarikciMesaj,
                        k.VoIPBildirimKurallari_TekrarSaati,
                        k.VoIPBildirimKurallari_Durum,
                        CAST(k.VoIPBildirimKurallari_OdemeTutar AS DECIMAL(12,4)) as OdemeTutar,
                        CONVERT(VARCHAR(19), k.VoIPBildirimKurallari_OdemeTarihi, 120) as OdemeTarihi,
                        CONVERT(VARCHAR(19), k.VoIPBildirimKurallari_SonBildirimTar, 120) as SonBildirimTar,
                        k.Durum as KuralAktif,
                        vk.EntegrasyonKanallari_KanalAdi as VoIPKanalAdi,
                        ve.Entegrasyonlar_Adi as VoIPOperatorAdi,
                        wk.EntegrasyonKanallari_KanalAdi as WAKanalAdi,
                        b.VoIPBakiye_Bakiye as GuncelBakiye,
                        CONVERT(VARCHAR(19), b.VoIPBakiye_Tarih, 120) as BakiyeTarihi
                    FROM VoIPBildirimKurallari k
                    INNER JOIN EntegrasyonKanallari vk ON k.VoIPBildirimKurallari_VoIPKanal_id = vk.EntegrasyonKanallari_id
                    INNER JOIN Entegrasyonlar ve ON vk.EntegrasyonKanallari_Entegrasyon_id = ve.Entegrasyonlar_id
                    INNER JOIN EntegrasyonKanallari wk ON k.VoIPBildirimKurallari_WAKanal_id = wk.EntegrasyonKanallari_id
                    OUTER APPLY (
                        SELECT TOP 1 VoIPBakiye_Bakiye, VoIPBakiye_Tarih
                        FROM VoIPBakiye
                        WHERE VoIPBakiye_Kanal_id = k.VoIPBildirimKurallari_VoIPKanal_id
                        ORDER BY VoIPBakiye_id DESC
                    ) b
                    ORDER BY k.VoIPBildirimKurallari_id
                ");
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            case 'kural_kaydet':
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']); break; }

                $id             = (int)($_POST['id'] ?? 0);
                $voipKanalId    = (int)$_POST['voip_kanal_id'];
                $esikTutar      = (float)str_replace(',', '.', $_POST['esik_tutar']);
                $waKanalId      = (int)$_POST['wa_kanal_id'];
                $hedefNo        = trim($_POST['hedef_no']);
                $tedarikciNo    = trim($_POST['tedarikci_no']);
                $mesaj          = trim($_POST['mesaj']);
                $tedarikciMesaj = trim($_POST['tedarikci_mesaj']);
                $tekrarSaati    = (int)($_POST['tekrar_saati'] ?? 24);
                $kuralAktif     = isset($_POST['aktif']) ? 1 : 0;
                $simdi          = date('Y-m-d H:i:s');

                if ($id > 0) {
                    $db->query("
                        UPDATE VoIPBildirimKurallari SET
                            VoIPBildirimKurallari_VoIPKanal_id   = ?,
                            VoIPBildirimKurallari_EsikTutar      = ?,
                            VoIPBildirimKurallari_WAKanal_id     = ?,
                            VoIPBildirimKurallari_HedefNo        = ?,
                            VoIPBildirimKurallari_TedarikciNo    = ?,
                            VoIPBildirimKurallari_Mesaj          = ?,
                            VoIPBildirimKurallari_TedarikciMesaj = ?,
                            VoIPBildirimKurallari_TekrarSaati    = ?,
                            Durum                                = ?,
                            GuncelleyenKullanici                 = ?,
                            GuncellemeTarihi                     = ?
                        WHERE VoIPBildirimKurallari_id = ?
                    ", [$voipKanalId, $esikTutar, $waKanalId, $hedefNo, $tedarikciNo,
                        $mesaj, $tedarikciMesaj, $tekrarSaati, $kuralAktif,
                        $user['kullanici_id'], $simdi, $id]);
                    echo json_encode(['success' => true, 'message' => 'Kural güncellendi.']);
                } else {
                    $db->query("
                        INSERT INTO VoIPBildirimKurallari
                            (VoIPBildirimKurallari_VoIPKanal_id, VoIPBildirimKurallari_EsikTutar,
                             VoIPBildirimKurallari_WAKanal_id, VoIPBildirimKurallari_HedefNo,
                             VoIPBildirimKurallari_TedarikciNo, VoIPBildirimKurallari_Mesaj,
                             VoIPBildirimKurallari_TedarikciMesaj, VoIPBildirimKurallari_TekrarSaati,
                             VoIPBildirimKurallari_Durum, OlusturanKullanici, OlusturmaTarihi,
                             GuncelleyenKullanici, GuncellemeTarihi, Durum)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)
                    ", [$voipKanalId, $esikTutar, $waKanalId, $hedefNo, $tedarikciNo,
                        $mesaj, $tedarikciMesaj, $tekrarSaati, 'beklemede',
                        $user['kullanici_id'], $simdi, $user['kullanici_id'], $simdi]);
                    echo json_encode(['success' => true, 'message' => 'Kural eklendi.']);
                }
                break;

            case 'odeme_yapildi':
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']); break; }

                $id     = (int)$_POST['id'];
                $tutar  = (float)str_replace(',', '.', $_POST['tutar']);
                $tarih  = trim($_POST['tarih']);
                $simdi  = date('Y-m-d H:i:s');

                $db->query("
                    UPDATE VoIPBildirimKurallari SET
                        VoIPBildirimKurallari_Durum      = 'operator_bekleniyor',
                        VoIPBildirimKurallari_OdemeTutar = ?,
                        VoIPBildirimKurallari_OdemeTarihi = ?,
                        GuncelleyenKullanici = ?,
                        GuncellemeTarihi     = ?
                    WHERE VoIPBildirimKurallari_id = ?
                ", [$tutar, $tarih, $user['kullanici_id'], $simdi, $id]);

                echo json_encode(['success' => true, 'message' => 'Ödeme kaydedildi. Cron çalışınca tedarikçiye bildirim gönderilecek.']);
                break;

            case 'kural_sifirla':
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']); break; }

                $id = (int)$_POST['id'];
                $db->query("
                    UPDATE VoIPBildirimKurallari SET
                        VoIPBildirimKurallari_Durum       = 'beklemede',
                        VoIPBildirimKurallari_OdemeTutar  = NULL,
                        VoIPBildirimKurallari_OdemeTarihi = NULL,
                        VoIPBildirimKurallari_SonBildirimTar = NULL,
                        GuncelleyenKullanici = ?,
                        GuncellemeTarihi     = ?
                    WHERE VoIPBildirimKurallari_id = ?
                ", [$user['kullanici_id'], date('Y-m-d H:i:s'), $id]);

                echo json_encode(['success' => true, 'message' => 'Kural sıfırlandı.']);
                break;

            case 'kural_sil':
                if (!$permissions['can_delete']) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']); break; }
                $id = (int)$_POST['id'];
                $db->query("UPDATE VoIPBildirimKurallari SET Durum=0, GuncelleyenKullanici=?, GuncellemeTarihi=? WHERE VoIPBildirimKurallari_id=?",
                    [$user['kullanici_id'], date('Y-m-d H:i:s'), $id]);
                echo json_encode(['success' => true, 'message' => 'Kural silindi.']);
                break;

            case 'kanal_listesi':
                $voip = $db->fetchAll("
                    SELECT k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_KanalAdi, e.Entegrasyonlar_Adi
                    FROM EntegrasyonKanallari k INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE e.Entegrasyonlar_Tip = 'voip' AND k.Durum = 1 AND e.Durum = 1 ORDER BY e.Entegrasyonlar_Adi
                ");
                $wa = $db->fetchAll("
                    SELECT k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_KanalAdi, e.Entegrasyonlar_Adi
                    FROM EntegrasyonKanallari k INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE e.Entegrasyonlar_Tip = 'whatsapp' AND k.Durum = 1 AND e.Durum = 1 ORDER BY e.Entegrasyonlar_Adi
                ");
                echo json_encode(['success' => true, 'voip' => $voip, 'wa' => $wa]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/Admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?><li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li><?php endif; ?>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <div class="alert alert-warning d-flex align-items-center justify-content-between py-2">
                    <div>
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>Bu sayfa emekliye ayrıldı — salt okunur.</strong>
                        Bildirim kuralları <strong>Hatırlatma Yönetimi</strong>'ne taşındı; gönderimler oradaki
                        kurallardan yapılıyor. Buradaki kayıtlar yalnızca geçmiş referansı olarak duruyor,
                        düzenlemenin gönderimlere etkisi <u>yoktur</u>.
                        <br>
                        <small>Ödeme yapıldığında Hatırlatma Yönetimi'nde ilgili kuralın <strong>Tamamla</strong> butonunu kullanın.</small>
                    </div>
                    <a href="/Admin/pages/hatirlatma-yonetimi.php" class="btn btn-sm btn-warning ms-3 text-nowrap">
                        <i class="bi bi-bell"></i> Hatırlatma Yönetimi
                    </a>
                </div>

                <div class="alert alert-info py-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Bakiye snapshot'ı <span class="cron-ref">VoIP Bakiye Snapshot</span> göreviyle 30 dakikada bir
                    güncellenmeye devam ediyor; aşağıdaki "Güncel Bakiye" değerleri canlıdır.
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Bildirim Kuralları</h3>
                        <div class="card-tools">
                            <span class="badge text-bg-secondary"><i class="bi bi-lock"></i> Salt okunur</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="tblKurallar" class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>VoIP Kanalı</th>
                                    <th>Eşik</th>
                                    <th>Güncel Bakiye</th>
                                    <th>WA Kanalı</th>
                                    <th>Durum</th>
                                    <th>Son Bildirim</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Kural Modal -->
<div class="modal fade" id="modalKural" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalKuralBaslik">Yeni Bildirim Kuralı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="k_id">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">VoIP Kanalı <span class="text-danger">*</span></label>
                        <select class="form-select select2-modal" id="k_voip_kanal"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Eşik Tutar (TRY) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="k_esik_tutar" placeholder="10000">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">WhatsApp Kanalı <span class="text-danger">*</span></label>
                        <select class="form-select select2-modal" id="k_wa_kanal"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tekrar Aralığı (saat)</label>
                        <input type="number" class="form-control" id="k_tekrar_saati" value="24">
                    </div>

                    <div class="col-12"><hr class="my-1"><small class="text-muted fw-bold">Şirket İç Bildirimi (Bakiye düşünce)</small></div>
                    <div class="col-md-12">
                        <label class="form-label">Hedef No / Grup <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="k_hedef_no" placeholder="120363000000000002@g.us">
                        <small class="text-muted">Grup: ...@g.us &nbsp;|&nbsp; Kişi: 905xxxxxxxxx@s.whatsapp.net</small>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Mesaj Şablonu <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="k_mesaj" rows="2" placeholder="Efe'ye bakiye gönderebilir miyiz? Bakiye {bakiye}"></textarea>
                        <small class="text-muted"><code>{bakiye}</code> → güncel bakiye tutarı</small>
                    </div>

                    <div class="col-12"><hr class="my-1"><small class="text-muted fw-bold">Tedarikçi Bildirimi (Ödeme yapıldıktan sonra)</small></div>
                    <div class="col-md-12">
                        <label class="form-label">Tedarikçi No <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="k_tedarikci_no" placeholder="905550000001@s.whatsapp.net">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Tedarikçi Mesaj Şablonu <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="k_tedarikci_mesaj" rows="2" placeholder="Merhaba, {tutar} TRY bakiye yüklemesi yapıldı ({tarih}). Bakiye ekler misiniz?"></textarea>
                        <small class="text-muted"><code>{tutar}</code> → ödeme tutarı &nbsp;|&nbsp; <code>{tarih}</code> → ödeme tarihi &nbsp;|&nbsp; <code>{bakiye}</code> → güncel bakiye</small>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="k_aktif" checked>
                            <label class="form-check-label" for="k_aktif">Kural Aktif</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" onclick="kuralKaydet()"><i class="bi bi-save"></i> Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- Ödeme Yapıldı Modal -->
<div class="modal fade" id="modalOdeme" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">Ödeme Yapıldı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="o_id">
                <div class="mb-3">
                    <label class="form-label">Ödeme Tutarı (TRY) <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="o_tutar" placeholder="30000">
                </div>
                <div class="mb-3">
                    <label class="form-label">Ödeme Tarihi <span class="text-danger">*</span></label>
                    <input type="datetime-local" class="form-control" id="o_tarih">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-warning" onclick="odemeKaydet()"><i class="bi bi-check-lg"></i> Kaydet</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script>
// Sidebar kapalı durumunu hatırla (bu sayfa custom.js yüklemiyor)
(function () {
    var KEY = 'sidebarCollapsed', BP = 992;
    function uygula() {
        if (window.innerWidth > BP && localStorage.getItem(KEY) === '1') {
            document.body.classList.add('sidebar-collapse');
            document.body.classList.remove('sidebar-open');
        }
    }
    function init() {
        try { uygula(); } catch (e) {}
        var rzt;
        window.addEventListener('resize', function () {
            clearTimeout(rzt);
            rzt = setTimeout(function () { try { uygula(); } catch (e) {} }, 60);
        });
        document.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('[data-lte-toggle="sidebar"]')) {
                try { localStorage.setItem(KEY, document.body.classList.contains('sidebar-collapse') ? '1' : '0'); } catch (er) {}
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const pageUrl = '<?= $_SERVER['PHP_SELF'] ?>';
let dtKurallar, kanallar = { voip: [], wa: [] };

$(document).ready(function () {
    kanalListesiYukle();
    kuralListele();
});

function kanalListesiYukle() {
    $.post(pageUrl, { action: 'kanal_listesi' }, function (r) {
        if (!r.success) return;
        kanallar = r;
    });
}

function kuralListele() {
    $.post(pageUrl, { action: 'kural_listele' }, function (r) {
        if (!r.success) return;
        if (dtKurallar) dtKurallar.destroy();
        const $tbody = $('#tblKurallar tbody').empty();

        r.data.forEach(k => {
            const durumBadge = durumBadgeHtml(k.VoIPBildirimKurallari_Durum);
            const bakiye     = k.GuncelBakiye !== null ? parseFloat(k.GuncelBakiye).toLocaleString('tr-TR', {minimumFractionDigits:2}) + ' ₺' : '—';
            const esikRenk   = k.GuncelBakiye !== null && parseFloat(k.GuncelBakiye) < parseFloat(k.VoIPBildirimKurallari_EsikTutar) ? 'text-danger fw-bold' : '';
            const aktifBadge = k.KuralAktif == 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>';

            // Sayfa salt okunur: gönderim artık CronHatirlatma* kurallarından yapılıyor,
            // buradaki butonlar hiçbir şeyi tetiklemeyeceği için kaldırıldı.
            const islemler = `<a href="/Admin/pages/hatirlatma-yonetimi.php" class="btn btn-xs btn-outline-warning">
                                  <i class="bi bi-box-arrow-up-right"></i> Hatırlatma Yönetimi
                              </a>`;

            $tbody.append(`<tr>
                <td><strong>${htmlEncode(k.VoIPOperatorAdi)}</strong><br><small class="text-muted">${htmlEncode(k.VoIPKanalAdi)}</small></td>
                <td><span class="text-warning fw-bold">${parseFloat(k.VoIPBildirimKurallari_EsikTutar).toLocaleString('tr-TR', {minimumFractionDigits:2})} ₺</span></td>
                <td><span class="${esikRenk}">${bakiye}</span><br><small class="text-muted">${k.BakiyeTarihi || ''}</small></td>
                <td><small>${htmlEncode(k.WAKanalAdi)}</small></td>
                <td>${durumBadge}<br>${aktifBadge}</td>
                <td><small>${k.SonBildirimTar || '—'}</small></td>
                <td>${islemler}</td>
            </tr>`);
        });

        dtKurallar = $('#tblKurallar').DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            pageLength: 25, destroy: true, order: [[0, 'asc']],
        });
    });
}

function durumBadgeHtml(durum) {
    const map = {
        'beklemede':           ['text-bg-success',   'Beklemede'],
        'odeme_bekleniyor':    ['text-bg-warning',   'Ödeme Bekleniyor'],
        'operator_bekleniyor': ['text-bg-info',      'Operatör Bekleniyor'],
    };
    const [cls, lbl] = map[durum] || ['text-bg-secondary', durum];
    return `<span class="badge ${cls}">${lbl}</span>`;
}

function kuralModalAc(veri = null) {
    $('#modalKuralBaslik').text(veri ? 'Kural Düzenle' : 'Yeni Bildirim Kuralı');
    $('#k_id').val(veri?.VoIPBildirimKurallari_id || '');

    // VoIP kanalları
    const $voip = $('#k_voip_kanal').empty();
    kanallar.voip.forEach(k => $voip.append(`<option value="${k.EntegrasyonKanallari_id}">${htmlEncode(k.Entegrasyonlar_Adi)} — ${htmlEncode(k.EntegrasyonKanallari_KanalAdi)}</option>`));

    // WA kanalları
    const $wa = $('#k_wa_kanal').empty();
    kanallar.wa.forEach(k => $wa.append(`<option value="${k.EntegrasyonKanallari_id}">${htmlEncode(k.Entegrasyonlar_Adi)} — ${htmlEncode(k.EntegrasyonKanallari_KanalAdi)}</option>`));

    if (veri) {
        $('#k_voip_kanal').val(veri.voipKanalId);
        $('#k_wa_kanal').val(veri.waKanalId);
        $('#k_esik_tutar').val(veri.esik);
        $('#k_tekrar_saati').val(veri.tekrar);
        $('#k_hedef_no').val(veri.hedefNo);
        $('#k_mesaj').val(veri.mesaj);
        $('#k_tedarikci_no').val(veri.tedarikciNo);
        $('#k_tedarikci_mesaj').val(veri.tedarikciMesaj);
        $('#k_aktif').prop('checked', veri.aktif == 1);
    } else {
        $('#k_voip_kanal, #k_wa_kanal').val('');
        $('#k_esik_tutar, #k_hedef_no, #k_mesaj, #k_tedarikci_no, #k_tedarikci_mesaj').val('');
        $('#k_tekrar_saati').val(24);
        $('#k_aktif').prop('checked', true);
    }

    $('.select2-modal').select2({ theme: 'bootstrap-5', dropdownParent: $('#modalKural'), width: '100%' });
    new bootstrap.Modal('#modalKural').show();
}

function kuralDuzenle(id) {
    $.post(pageUrl, { action: 'kural_listele' }, function (r) {
        const k = r.data.find(x => x.VoIPBildirimKurallari_id == id);
        if (!k) return;
        kuralModalAc({
            VoIPBildirimKurallari_id: k.VoIPBildirimKurallari_id,
            voipKanalId: k.VoIPBildirimKurallari_VoIPKanal_id,
            waKanalId: k.VoIPBildirimKurallari_WAKanal_id,
            esik: k.VoIPBildirimKurallari_EsikTutar,
            tekrar: k.VoIPBildirimKurallari_TekrarSaati,
            hedefNo: k.VoIPBildirimKurallari_HedefNo,
            mesaj: k.VoIPBildirimKurallari_Mesaj,
            tedarikciNo: k.VoIPBildirimKurallari_TedarikciNo,
            tedarikciMesaj: k.VoIPBildirimKurallari_TedarikciMesaj,
            aktif: k.KuralAktif,
        });
    });
}

function kuralKaydet() {
    const data = {
        action: 'kural_kaydet',
        id: $('#k_id').val(),
        voip_kanal_id: $('#k_voip_kanal').val(),
        esik_tutar: $('#k_esik_tutar').val(),
        wa_kanal_id: $('#k_wa_kanal').val(),
        tekrar_saati: $('#k_tekrar_saati').val(),
        hedef_no: $('#k_hedef_no').val(),
        mesaj: $('#k_mesaj').val(),
        tedarikci_no: $('#k_tedarikci_no').val(),
        tedarikci_mesaj: $('#k_tedarikci_mesaj').val(),
        aktif: $('#k_aktif').is(':checked') ? 1 : 0,
    };

    if (!data.voip_kanal_id || !data.esik_tutar || !data.wa_kanal_id || !data.hedef_no || !data.mesaj || !data.tedarikci_no || !data.tedarikci_mesaj) {
        Swal.fire('Uyarı', 'Tüm zorunlu alanları doldurun.', 'warning'); return;
    }

    $.post(pageUrl, data, function (r) {
        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }
        bootstrap.Modal.getInstance('#modalKural').hide();
        Swal.fire({ icon: 'success', title: r.message, timer: 1500, showConfirmButton: false });
        kuralListele();
    });
}

function odemeModalAc(id) {
    $('#o_id').val(id);
    $('#o_tutar').val('');
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    $('#o_tarih').val(now.toISOString().slice(0, 16));
    new bootstrap.Modal('#modalOdeme').show();
}

function odemeKaydet() {
    const tutar = $('#o_tutar').val();
    const tarih = $('#o_tarih').val();
    if (!tutar || !tarih) { Swal.fire('Uyarı', 'Tutar ve tarih zorunludur.', 'warning'); return; }

    $.post(pageUrl, { action: 'odeme_yapildi', id: $('#o_id').val(), tutar, tarih: tarih.replace('T', ' ') + ':00' }, function (r) {
        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }
        bootstrap.Modal.getInstance('#modalOdeme').hide();
        Swal.fire({ icon: 'success', title: r.message, timer: 2000, showConfirmButton: false });
        kuralListele();
    });
}

function kuralSifirla(id) {
    Swal.fire({ title: 'Kuralı sıfırla?', text: 'Durum "beklemede" ya döner.', icon: 'question', showCancelButton: true, confirmButtonText: 'Evet', cancelButtonText: 'İptal' })
    .then(r => { if (r.isConfirmed) $.post(pageUrl, { action: 'kural_sifirla', id }, function (r) { kuralListele(); }); });
}

function kuralSil(id) {
    Swal.fire({ title: 'Kural silinsin mi?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Evet, sil', cancelButtonText: 'İptal', confirmButtonColor: '#d33' })
    .then(r => { if (r.isConfirmed) $.post(pageUrl, { action: 'kural_sil', id }, function () { kuralListele(); }); });
}

function htmlEncode(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

</body>
</html>
