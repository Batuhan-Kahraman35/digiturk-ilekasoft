<?php
/**
 * Admin Panel - Hakediş Hesaplama
 *
 * Alt bayi + dönem (ayın 4'ü - sonraki ayın 3'ü) için DigiturkIrisRapor satışlarından
 * canlı hakediş hesabı. Yeni tablo tutulmaz, anlık hesaplanır.
 *
 * İş kuralları:
 *  - Uygun kayıt: IrisRapor_GuncelOutletDurum = 'AKTIF' AND IrisRapor_SatisDurumu = 'Tamamlandı' (filtreden değişebilir)
 *  - Dönem tarihi: IrisRapor_MemoKapanisTarihi
 *  - Kampanyalar HakedisKampanyaTanimlari tablosundan gelir (dinamik).
 *  - Kampanya eşleşmesi: IrisRapor_TalepTuru = _TalepTuru AND IrisRapor_MemoKodu = _MemoKodu
 *      AND (_Kampanya IS NULL OR IrisRapor_Kampanya = _Kampanya). Aynı ada sahip kayıtlar ada göre gruplanır.
 *  - Kampanya adedi: eşleşen uygun kayıt sayısı (COUNT).
 *  - Skala kademesi: alt bayinin dönemdeki TÜM uygun kayıt sayısına göre (2000+/1000-2000/500-1000/0-500).
 *    Alt bayi bir skala grubuna üyeyse (hakedis-tanimlama > Alt Bayi Eşleştirme) grup üyelerinin
 *    adetleri toplanır ve kademe bu toplamdan bulunur; fiyat yine bayinin kendi tanımından okunur.
 *  - Hakediş: kampanya_adedi × (alt bayi + dönem + kampanya + kademe) tutarı (DigiturkHakedisTanimlari).
 *  - Alt bayi eşleşmesi: IrisRapor_TalebiGirenPersonelAltbayi = DigiturkAltBayiler_Ad;
 *    aynı ada sahip birden çok bayi varsa IrisRapor_TalebiGirenBayiAdi = DigiturkAnaBayiler_Ad ile ayrılır.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/HakedisHesap.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPagefile = basename($_SERVER['PHP_SELF']);
$pageinfo = $db->fetchOne("
    SELECT
        s.sayfalar_sayfa_adi,
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Hakediş Hesaplama';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Ödemeye aktarım yetkisi — YALNIZCA admin. Ayrıca kayıt odemeler.php'ye yazıldığı için o sayfanın izni de aranır.
$odemeYetki = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], 'odemeler.php');
$odemeAktarilabilir = !empty($permissions['is_admin'])
                   && !empty($odemeYetki['has_access'])
                   && !empty($odemeYetki['can_add']);

// Ödeme türleri (aktarım modalı) — varsayılan "Hakediş Ödemesi"
$ODEME_TURLERI = $db->fetchAll("
    SELECT OdemeTurleri_Id, OdemeTurleri_Ad
    FROM dbo.OdemeTurleri WHERE Durum = 1 ORDER BY OdemeTurleri_Ad
");

// ── Sabit listeler ──────────────────────────────────────────────────
$SKALALAR = [1 => '2000 Adet ve Üzeri', 2 => '1000-2000 Arası', 3 => '500-1000 Arası', 4 => '0-500 Arası'];
$AYLAR    = [1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran',
             7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'];

/**
 * Dönem tarih aralığı: başlangıç = ayın 4'ü, bitiş = sonraki ayın 3'ü
 */
function donemTarihleri(int $yil, int $ay): array {
    $baslangic   = sprintf('%04d-%02d-04', $yil, $ay);
    $sonrakiAy   = $ay + 1;
    $sonrakiYil  = $yil;
    if ($sonrakiAy > 12) { $sonrakiAy = 1; $sonrakiYil++; }
    $bitis = sprintf('%04d-%02d-03', $sonrakiYil, $sonrakiAy);
    return [$baslangic, $bitis];
}

// ── Birim görünürlük kısıtı (birim_gor): admin değil + can_view_birim → kendi birimi + alt birimleri ──
$birimKisitli      = (!$permissions['is_admin'] && !empty($permissions['can_view_birim']));
$izinliBirimIdleri = [];
if ($birimKisitli) {
    $kb = $db->fetchOne("SELECT kullanici_birim_id FROM kullanicilar WHERE kullanici_id = ?", [$user['kullanici_id']]);
    $kullaniciBirimId = $kb['kullanici_birim_id'] ?? null;
    if ($kullaniciBirimId) {
        $rows = $db->fetchAll("
            WITH BirimAgaci AS (
                SELECT KullaniciBirim_id FROM KullaniciBirim WHERE KullaniciBirim_id = ?
                UNION ALL
                SELECT b.KullaniciBirim_id FROM KullaniciBirim b
                INNER JOIN BirimAgaci a ON b.KullaniciBirim_UstBirim_id = a.KullaniciBirim_id
            )
            SELECT KullaniciBirim_id FROM BirimAgaci
        ", [$kullaniciBirimId]);
        $izinliBirimIdleri = array_map(fn($r) => (int)$r['KullaniciBirim_id'], $rows);
    }
    // Birim atanmamış kısıtlı kullanıcı → boş liste → hiçbir kayıt görünmez (güvenli varsayılan)
}

// ── AJAX işlemleri ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // Aktif alt bayiler (filtre Select2)
            case 'altbayiler':
                // Etiket "ANA BAYİ — ALT BAYİ" formatında; aynı adlı alt bayiler
                // (ör. AYER DİGİTAL) ancak ana bayisiyle ayırt edilebiliyor.
                $list = $db->fetchAll("
                    SELECT a.DigiturkAltBayiler_Id,
                           a.DigiturkAltBayiler_Ad,
                           ISNULL(an.DigiturkAnaBayiler_Ad, N'') AS AnaBayiAd,
                           CASE WHEN an.DigiturkAnaBayiler_Ad IS NULL THEN a.DigiturkAltBayiler_Ad
                                ELSE an.DigiturkAnaBayiler_Ad + N' — ' + a.DigiturkAltBayiler_Ad
                           END AS Etiket
                    FROM DigiturkAltBayiler a
                    LEFT JOIN DigiturkAnaBayiler an ON an.DigiturkAnaBayiler_Id = a.DigiturkAltBayiler_AnaBayiId
                    WHERE a.Durum = 1
                    ORDER BY AnaBayiAd, a.DigiturkAltBayiler_Ad
                ");
                echo json_encode(['success' => true, 'data' => $list], JSON_UNESCAPED_UNICODE);
                break;

            // Aktif birimler (filtre Select2) — kısıtlı kullanıcıya yalnız izinli birimler
            case 'birimler':
                if ($birimKisitli && empty($izinliBirimIdleri)) {
                    echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $bWhere = "Durum = 1";
                $bParams = [];
                if ($birimKisitli) {
                    $bWhere .= " AND KullaniciBirim_id IN (" . implode(',', array_fill(0, count($izinliBirimIdleri), '?')) . ")";
                    $bParams = $izinliBirimIdleri;
                }
                $list = $db->fetchAll("
                    SELECT KullaniciBirim_id, KullaniciBirim_Adi
                    FROM KullaniciBirim
                    WHERE $bWhere
                    ORDER BY KullaniciBirim_Adi
                ", $bParams);
                echo json_encode(['success' => true, 'data' => $list], JSON_UNESCAPED_UNICODE);
                break;

            // Outlet durumu + satış durumu distinct değerleri (filtre Select2)
            case 'durumlar':
                $outlet = $db->fetchAll("
                    SELECT DISTINCT IrisRapor_GuncelOutletDurum AS v
                    FROM dbo.DigiturkIrisRapor
                    WHERE IrisRapor_GuncelOutletDurum IS NOT NULL AND LTRIM(RTRIM(IrisRapor_GuncelOutletDurum)) <> ''
                    ORDER BY IrisRapor_GuncelOutletDurum
                ");
                $satis = $db->fetchAll("
                    SELECT DISTINCT IrisRapor_SatisDurumu AS v
                    FROM dbo.DigiturkIrisRapor
                    WHERE IrisRapor_SatisDurumu IS NOT NULL AND LTRIM(RTRIM(IrisRapor_SatisDurumu)) <> ''
                    ORDER BY IrisRapor_SatisDurumu
                ");
                echo json_encode(['success' => true, 'outlet' => $outlet, 'satis' => $satis], JSON_UNESCAPED_UNICODE);
                break;

            // Tek dönem tarih aralığı (tanım varsa tanımdan, yoksa formül) — inputları otomatik doldurmak için
            case 'donem':
                $yil = (int)($_POST['yil'] ?? 0);
                $ay  = (int)($_POST['ay'] ?? 0);
                if (!$yil || $ay < 1 || $ay > 12) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz yıl/ay']);
                    break;
                }
                $donemTanim = $db->fetchOne("
                    SELECT MIN(CAST(DigiturkHakedisTanimlari_BaslangicTarihi AS DATE)) AS bas,
                           MAX(CAST(DigiturkHakedisTanimlari_BitisTarihi     AS DATE)) AS bit
                    FROM dbo.DigiturkHakedisTanimlari
                    WHERE DigiturkHakedisTanimlari_DonemYil = ? AND DigiturkHakedisTanimlari_DonemAy = ? AND Durum = 1
                ", [$yil, $ay]);
                if ($donemTanim && !empty($donemTanim['bas']) && !empty($donemTanim['bit'])) {
                    $bas = date('Y-m-d', strtotime((string)$donemTanim['bas']));
                    $bit = date('Y-m-d', strtotime((string)$donemTanim['bit']));
                    $kaynak = 'tanim';
                } else {
                    [$bas, $bit] = donemTarihleri($yil, $ay);
                    $kaynak = 'formul';
                }
                echo json_encode(['success' => true, 'bas' => $bas, 'bit' => $bit, 'kaynak' => $kaynak]);
                break;

            // Hesaplama — Alt Bayi × Kampanya (uzun tablo)
            case 'hesapla':
                echo json_encode(
                    hakedisHesapla($_POST, $db, $birimKisitli, $izinliBirimIdleri),
                    JSON_UNESCAPED_UNICODE
                );
                break;

            // Excel indir — hesaplanan özet değil, filtreye uyan HAM DigiturkIrisRapor kayıtları
            case 'iris_kayitlar':
                echo json_encode(
                    hakedisIrisKayitlari($_POST, $db, $birimKisitli, $izinliBirimIdleri),
                    JSON_UNESCAPED_UNICODE
                );
                break;

            // Ödemeye aktar — önizleme: (dönem × hedef birim) gruplarını ve mükerrer durumunu döner
            case 'odeme_onizle':
            case 'odeme_aktar':
                // Sunucu tarafı kilit: buton gizli olsa da istek elle atılabilir
                if (!$odemeAktarilabilir) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem yalnızca yöneticiler içindir!']);
                    break;
                }

                $hesap = hakedisHesapla($_POST, $db, $birimKisitli, $izinliBirimIdleri);
                if (!$hesap['success']) {
                    echo json_encode($hesap, JSON_UNESCAPED_UNICODE);
                    break;
                }
                if (empty($hesap['data'])) {
                    echo json_encode(['success' => false, 'message' => 'Aktarılacak hakediş sonucu yok'], JSON_UNESCAPED_UNICODE);
                    break;
                }

                // Filtrede birim seçiliyse tüm alt bayiler o birime tek kayıt olarak toplanır
                $hedefBirimId = (int)($_POST['birim'] ?? 0) ?: null;
                $hedefBirimAd = null;
                if ($hedefBirimId) {
                    if ($birimKisitli && !in_array($hedefBirimId, $izinliBirimIdleri, true)) {
                        echo json_encode(['success' => false, 'message' => 'Bu birim için yetkiniz yok']);
                        break;
                    }
                    $hb = $db->fetchOne("SELECT KullaniciBirim_Adi FROM dbo.KullaniciBirim WHERE KullaniciBirim_id = ?", [$hedefBirimId]);
                    $hedefBirimAd = $hb['KullaniciBirim_Adi'] ?? '';
                }

                $gruplar = hakedisOdemeGruplari($hesap['data'], $hedefBirimId, $hedefBirimAd, $AYLAR);

                $turId = (int)($_POST['odeme_turu_id'] ?? 0);
                $tarih = trim((string)($_POST['tarih'] ?? ''));

                // Mükerrer: aynı referans + birim + tür ile kayıt var mı
                foreach ($gruplar as &$g) {
                    $mWhere  = "Odemeler_Referans = ?";
                    $mParams = [$g['referans']];
                    if ($g['birimId'] !== null) { $mWhere .= " AND Odemeler_KullaniciBirim_id = ?"; $mParams[] = $g['birimId']; }
                    else                        { $mWhere .= " AND Odemeler_KullaniciBirim_id IS NULL"; }
                    if ($turId > 0)             { $mWhere .= " AND Odemeler_OdemeTuruId = ?";      $mParams[] = $turId; }

                    $var = $db->fetchOne("SELECT TOP 1 Odemeler_Id AS id FROM dbo.Odemeler WHERE $mWhere", $mParams);
                    $g['mukerrer']   = (bool)$var;
                    $g['mukerrerId'] = $var['id'] ?? null;
                }
                unset($g);

                if ($action === 'odeme_onizle') {
                    echo json_encode([
                        'success'  => true,
                        'gruplar'  => $gruplar,
                        'donemler' => $hesap['donemler'],
                    ], JSON_UNESCAPED_UNICODE);
                    break;
                }

                // ── Kayıt ──
                if ($turId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme türü zorunludur!']);
                    break;
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir tarih seçiniz!']);
                    break;
                }

                $eklendi = 0; $atlanan = 0; $hata = 0;
                foreach ($gruplar as $g) {
                    if (!empty($g['mukerrer']))   { $atlanan++; continue; }   // mükerrer → atla
                    if ($g['tutar'] <= 0)          { $atlanan++; continue; }   // tutarsız grup → atla
                    if ($g['birimId'] === null)    { $atlanan++; continue; }   // birimi çözülemeyen grup → atla

                    $ok = $db->insert('Odemeler', [
                        'Odemeler_OdemeTuruId'       => $turId,
                        'Odemeler_Tutar'             => round((float)$g['tutar'], 2),
                        'Odemeler_Tarih'             => $tarih,
                        'Odemeler_Referans'          => $g['referans'],
                        'Odemeler_Aciklama'          => $g['aciklama'],
                        'Odemeler_KullaniciBirim_id' => $g['birimId'],
                        'Durum'                      => 1,
                        'OlusturanKullanici'         => $user['kullanici_id'],
                        'OlusturmaTarihi'            => date('Y-m-d H:i:s'),
                        'GuncelleyenKullanici'       => $user['kullanici_id'],
                        'GuncellemeTarihi'           => date('Y-m-d H:i:s'),
                    ]);
                    if ($ok) $eklendi++; else $hata++;
                }

                $msg = "$eklendi ödeme kaydı oluşturuldu";
                if ($atlanan) $msg .= ", $atlanan atlandı (mükerrer/birimsiz)";
                if ($hata)    $msg .= ", $hata hata";

                echo json_encode([
                    'success' => $eklendi > 0,
                    'message' => $msg,
                    'eklendi' => $eklendi,
                    'atlanan' => $atlanan,
                ], JSON_UNESCAPED_UNICODE);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// Yıl listesi (dinamik)
$buYil  = (int)date('Y');
$yillar = range($buYil + 1, $buYil - 3);

// Kampanya sütunları (geniş tablo) — HakedisKampanyaTanimlari, ada göre tekil
$KAMPANYALAR = $db->fetchAll("
    SELECT MIN(HakedisKampanyaTanimlari_id) AS id,
           HakedisKampanyaTanimlari_KampanyaAdi AS ad
    FROM dbo.HakedisKampanyaTanimlari
    WHERE Durum = 1
    GROUP BY HakedisKampanyaTanimlari_KampanyaAdi
    ORDER BY HakedisKampanyaTanimlari_KampanyaAdi
");
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

    <style>
        .kampanya-badge { background:#e7f1ff; color:#0d6efd; padding:.2rem .5rem; border-radius:.3rem; font-size:.8rem; font-weight:600; }
        .skala-badge { background:#f1e7ff; color:#6f42c1; padding:.2rem .5rem; border-radius:.3rem; font-size:.8rem; font-weight:600; }
        .table-responsive > #sonucTable { margin-bottom: 0 !important; }
        .grup-badge  { background:#e7f1ff; color:#0d6efd; padding:.2rem .45rem; border-radius:.3rem; font-size:.72rem; font-weight:600; white-space:nowrap; }
        .donem-badge { background:#e9ecef; color:#495057; padding:.2rem .5rem; border-radius:.3rem; font-size:.8rem; font-family:monospace; }
        .tutar-text { font-weight:600; color:#198754; }
        .adet-text { font-weight:600; }
        .eksik-badge { background:#fff3cd; color:#997404; padding:.15rem .4rem; border-radius:.3rem; font-size:.75rem; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6">
                        <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?>
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <?php endif; ?>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="info-box text-bg-secondary">
                            <span class="info-box-icon"><i class="bi bi-shop"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Hesaplanan Alt Bayi</span>
                                <span class="info-box-number" id="stat-bayi">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-card-checklist"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Kampanya Adedi</span>
                                <span class="info-box-number" id="stat-adet">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-cash-stack"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Hakediş (₺)</span>
                                <span class="info-box-number" id="stat-hakedis">0,00</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-exclamation-triangle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Eksik Tanım</span>
                                <span class="info-box-number" id="stat-eksik">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hesaplama Filtresi -->
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-calculator"></i> Hesaplama</h3>
                    </div>
                    <div class="card-body">
                        <form id="hesapForm">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label">Birim</label>
                                    <select class="form-select" name="birim" id="h_birim"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Alt Bayi</label>
                                    <select class="form-select" name="altbayi" id="h_altbayi"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Outlet Durumu</label>
                                    <select class="form-select" name="outlet" id="h_outlet"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Satış Durumu</label>
                                    <select class="form-select" name="satis" id="h_satis"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Yıl <span class="text-danger">*</span></label>
                                    <select class="form-select" name="yil" id="h_yil"></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Ay <span class="text-danger">*</span> <small class="text-muted">(çoklu)</small></label>
                                    <select class="form-select" name="ay[]" id="h_ay" multiple></select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Başlangıç</label>
                                    <input type="date" class="form-control" name="bas" id="h_bas">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Bitiş</label>
                                    <input type="date" class="form-control" name="bit" id="h_bit">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-calculator"></i> Hesapla</button>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-12">
                                    <div class="alert alert-secondary mb-0 py-2 px-3">
                                        <i class="bi bi-calendar-range"></i> Dönem: <strong id="h_donem_text">-</strong>
                                        <span class="text-muted ms-2 small">(dönem tarihi hakediş tanımından; MemoKapanışTarihi'ne göre)</span>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Sonuç Listesi -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Hakediş Sonuçları</h3>
                        <div class="card-tools">
                            <?php if ($odemeAktarilabilir): ?>
                            <button type="button" class="btn btn-primary btn-sm" id="odemeyeAktar">
                                <i class="bi bi-cash-coin"></i> Ödemeye Aktar
                            </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-success btn-sm" id="excelIndir">
                                <i class="bi bi-file-earmark-excel"></i> Excel indir
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="sonucTable" class="table table-bordered table-striped table-hover nowrap w-100">
                            <thead>
                                <tr>
                                    <th>Dönem</th>
                                    <th>Birim</th>
                                    <th>Alt Bayi</th>
                                    <th>Skala Kademesi</th>
                                    <th class="text-end">Toplam Adet</th>
                                    <?php foreach ($KAMPANYALAR as $k): ?>
                                    <th class="text-end"><?= htmlspecialchars($k['ad']) ?></th>
                                    <?php endforeach; ?>
                                    <?php foreach ($KAMPANYALAR as $k): ?>
                                    <th class="text-end"><?= htmlspecialchars($k['ad']) ?> ₺</th>
                                    <?php endforeach; ?>
                                    <th class="text-end">Hakediş (₺)</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                            <tfoot>
                                <tr class="table-secondary fw-bold">
                                    <th class="text-end" colspan="4">GENEL TOPLAM</th>
                                    <th class="text-end" id="ft-toplam">0</th>
                                    <?php foreach ($KAMPANYALAR as $i => $k): ?>
                                    <th class="text-end ft-adet" data-idx="<?= $i ?>">0</th>
                                    <?php endforeach; ?>
                                    <?php foreach ($KAMPANYALAR as $i => $k): ?>
                                    <th class="text-end ft-tutar" data-idx="<?= $i ?>">0,00</th>
                                    <?php endforeach; ?>
                                    <th class="text-end" id="ft-hakedis">0,00</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<?php if ($odemeAktarilabilir): ?>
<!-- Ödemeye Aktar Modalı -->
<div class="modal fade" id="odemeModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin"></i> Ödemeye Aktar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Ödeme Türü <span class="text-danger">*</span></label>
                        <select class="form-select" id="o_turu">
                            <?php foreach ($ODEME_TURLERI as $t): ?>
                            <option value="<?= (int)$t['OdemeTurleri_Id'] ?>" <?= $t['OdemeTurleri_Ad'] === 'Hakediş Ödemesi' ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['OdemeTurleri_Ad']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Ödeme Tarihi <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="o_tarih" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="alert alert-secondary mb-0 py-2 px-3 w-100 small">
                            Her <strong>dönem + birim</strong> için tek ödeme kaydı oluşur.
                        </div>
                    </div>
                </div>

                <div id="o_uyari"></div>

                <table class="table table-bordered table-sm align-middle" id="o_onizleme">
                    <thead class="table-light">
                        <tr>
                            <th style="width:14%">Referans</th>
                            <th style="width:18%">Birim</th>
                            <th class="text-end" style="width:8%">Alt Bayi</th>
                            <th class="text-end" style="width:12%">Tutar (₺)</th>
                            <th>Açıklama</th>
                            <th style="width:10%">Durum</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn btn-primary" id="o_kaydet" disabled>
                    <i class="bi bi-save"></i> Ödemeleri Oluştur
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/admin/assets/vendor/sheetjs/xlsx.full.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
    const SKALALAR = <?= json_encode($SKALALAR, JSON_UNESCAPED_UNICODE) ?>;
    const AYLAR    = <?= json_encode($AYLAR, JSON_UNESCAPED_UNICODE) ?>;
    // Kampanya sütunları (dinamik, HakedisKampanyaTanimlari adlarından) — sıra thead ile aynı
    const KAMPANYALAR = <?= json_encode(array_map(fn($k) => $k['ad'], $KAMPANYALAR), JSON_UNESCAPED_UNICODE) ?>;

    let dataTable, sonRows = [];
    // Tabloda görünen (GENEL TOPLAM'ı 0 olmayan) kampanya sütunları
    let aktifKampanyalar = KAMPANYALAR.slice();

    $(document).ready(function () {
        dataTable = $('#sonucTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc'], [1, 'asc'], [2, 'asc']],
            // Yatay kaydırma yalnız tabloyu sarar (tfoot aynı tabloda kalır → çubuk GENEL TOPLAM'ın altında).
            // Sarmalayıcı .row/.col içinde olduğundan negatif kenar boşluğu taşması oluşmaz.
            dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
                 "<'row dt-row'<'col-sm-12'<'table-responsive'tr>>>" +
                 "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
            columnDefs: [
                {
                    targets: 0, // Dönem: değer YYYYAA sayısı, görüntü badge
                    render: function (data, type) {
                        const yil = Math.floor(data / 100), ay = data % 100;
                        if (type === 'display') return `<span class="donem-badge">${escapeHtml(AYLAR[ay])} ${yil}</span>`;
                        if (type === 'filter')  return AYLAR[ay] + ' ' + yil;
                        return data;
                    }
                }
            ],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        });

        initDonemSelect();
        loadBirimler();
        loadDurumlar();
        loadAltBayiler();
        updateDonemText();
        loadDonemTarih();

        $('#h_yil, #h_ay').on('change', function () {
            updateDonemText();
            loadDonemTarih();
        });

        $('#h_birim').on('change', function () {
            if ($('#h_yil').val() && ($('#h_ay').val() || []).length) hesapla();
        });

        $('#hesapForm').on('submit', function (e) {
            e.preventDefault();
            hesapla();
        });

        $('#excelIndir').on('click', excelIndir);

        // Ödemeye aktar (yetki yoksa buton basılmaz)
        if ($('#odemeyeAktar').length) {
            // Modal select'i: custom.js'in otomatik init'ine karşı destroy + dropdownParent
            if ($('#o_turu').hasClass('select2-hidden-accessible')) $('#o_turu').select2('destroy');
            $('#o_turu').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#odemeModal') });

            // Not: doğrudan referans verilirse jQuery event objesi `sessiz` parametresine düşer
            $('#odemeyeAktar').on('click', function () { odemeOnizle(false); });
            $('#o_turu').on('change', function () { if ($('#odemeModal').hasClass('show')) odemeOnizle(true); });
            $('#o_kaydet').on('click', odemeKaydet);
        }
    });

    // ── Ödemeye Aktar ────────────────────────────────────────────────
    // Tutarlar sunucuda yeniden hesaplanır; tarayıcıdaki değerler yalnız gösterim içindir.
    function odemeFiltre() {
        const f = $('#hesapForm').serializeArray();
        const d = { action: 'odeme_onizle', odeme_turu_id: $('#o_turu').val(), tarih: $('#o_tarih').val() };
        f.forEach(x => {
            if (x.name === 'ay[]') { (d['ay'] = d['ay'] || []).push(x.value); }
            else { d[x.name] = x.value; }
        });
        return d;
    }

    function odemeOnizle(sessiz) {
        if (!sonRows.length) { showToast('Önce hesaplama yapın', 'warning'); return; }

        const $tb = $('#o_onizleme tbody');
        $tb.html('<tr><td colspan="6" class="text-center text-muted py-3">Hesaplanıyor...</td></tr>');
        $('#o_kaydet').prop('disabled', true);
        $('#o_uyari').html('');
        if (!sessiz) new bootstrap.Modal('#odemeModal').show();

        $.post('', odemeFiltre(), function (r) {
            if (!r.success) {
                $tb.html(`<tr><td colspan="6" class="text-center text-danger py-3">${escapeHtml(r.message || 'Hata')}</td></tr>`);
                return;
            }

            let aktarilacak = 0, toplam = 0;
            const satirlar = r.gruplar.map(g => {
                let durum, cls = '';
                if (g.mukerrer) {
                    durum = '<span class="badge text-bg-secondary">Mükerrer — atlanır</span>';
                    cls = 'table-secondary';
                } else if (g.birimId === null) {
                    durum = '<span class="badge text-bg-warning">Birim yok — atlanır</span>';
                    cls = 'table-warning';
                } else if (+g.tutar <= 0) {
                    durum = '<span class="badge text-bg-warning">Tutar 0 — atlanır</span>';
                    cls = 'table-warning';
                } else {
                    durum = '<span class="badge text-bg-success">Aktarılacak</span>';
                    aktarilacak++;
                    toplam += +g.tutar;
                }
                const not = g.eksik ? ' <span class="eksik-badge">tanımsız tutar var</span>' : '';
                return `<tr class="${cls}">
                    <td>${escapeHtml(g.referans)}</td>
                    <td>${escapeHtml(g.birimAd || '(Birim Yok)')}${not}</td>
                    <td class="text-end">${g.satirSayisi}</td>
                    <td class="text-end tutar-text">${formatTutar(g.tutar)}</td>
                    <td><pre class="mb-0 small" style="white-space:pre-wrap">${escapeHtml(g.aciklama)}</pre></td>
                    <td>${durum}</td>
                </tr>`;
            });

            $tb.html(satirlar.join('') || '<tr><td colspan="6" class="text-center text-muted py-3">Kayıt yok</td></tr>');
            $('#o_uyari').html(aktarilacak
                ? `<div class="alert alert-info py-2 px-3"><i class="bi bi-info-circle"></i> <strong>${aktarilacak}</strong> ödeme kaydı oluşturulacak · Toplam <strong>${formatTutar(toplam)} ₺</strong></div>`
                : '<div class="alert alert-warning py-2 px-3"><i class="bi bi-exclamation-triangle"></i> Aktarılacak yeni kayıt yok.</div>');
            $('#o_kaydet').prop('disabled', aktarilacak === 0);
        }, 'json');
    }

    function odemeKaydet() {
        if (!$('#o_tarih').val()) { showToast('Ödeme tarihi seçiniz', 'warning'); return; }

        Swal.fire({
            title: 'Ödemeler oluşturulsun mu?',
            text: 'Ödemeler sayfasına yeni kayıtlar eklenecek.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Evet, oluştur',
            cancelButtonText: 'Vazgeç'
        }).then(res => {
            if (!res.isConfirmed) return;

            const d = odemeFiltre();
            d.action = 'odeme_aktar';
            $('#o_kaydet').prop('disabled', true);

            $.post('', d, function (r) {
                showToast(r.message || (r.success ? 'Kaydedildi' : 'Hata'), r.success ? 'success' : 'error');
                if (r.success) bootstrap.Modal.getInstance(document.getElementById('odemeModal')).hide();
                else $('#o_kaydet').prop('disabled', false);
            }, 'json');
        });
    }

    // Excel çıktısının kolonları: [alan adı, başlık] — sunucudan gelen ham IRIS satırlarıyla eşleşir
    const IRIS_KOLONLARI = [
        ['Donem',                                  'Dönem'],
        ['Birim',                                  'Birim'],
        ['AnaBayi',                                'Ana Bayi'],
        ['IrisRapor_MemoId',                       'Memo Id'],
        ['IrisRapor_MemoIdTip',                    'Memo Id Tip'],
        ['IrisRapor_MemoKodu',                     'Memo Kodu'],
        ['IrisRapor_MemoKayitTipi',                'Memo Kayıt Tipi'],
        ['IrisRapor_MemoSonDurum',                 'Memo Son Durum'],
        ['IrisRapor_MemoSonCevap',                 'Memo Son Cevap'],
        ['IrisRapor_MemoSonAciklama',              'Memo Son Açıklama'],
        ['IrisRapor_MemoKapanisTarihi',            'Memo Kapanış Tarihi'],
        ['IrisRapor_MemoYonlenenBayiAdi',          'Memo Yönlenen Bayi Adı'],
        ['IrisRapor_MemoYonlenenBayiKodu',         'Memo Yönlenen Bayi Kodu'],
        ['IrisRapor_MemoYonlenenBayiBolge',        'Memo Yönlenen Bayi Bölge'],
        ['IrisRapor_MemoYonlenenBayiYoneticisi',   'Memo Yönlenen Bayi Yöneticisi'],
        ['IrisRapor_MemoYonlenenBayiTeknikYntc',   'Memo Yönlenen Bayi Teknik Yön.'],
        ['IrisRapor_TalepId',                      'Talep Id'],
        ['IrisRapor_TalepTuru',                    'Talep Türü'],
        ['IrisRapor_TalepKaynak',                  'Talep Kaynak'],
        ['IrisRapor_TalepGirisTarihi',             'Talep Giriş Tarihi'],
        ['IrisRapor_TalepTakipNotu',               'Talep Takip Notu'],
        ['IrisRapor_TalebiGirenBayiAdi',           'Talebi Giren Bayi Adı'],
        ['IrisRapor_TalebiGirenBayiKodu',          'Talebi Giren Bayi Kodu'],
        ['IrisRapor_TalebiGirenPersonel',          'Talebi Giren Personel'],
        ['IrisRapor_TalebiGirenPersonelKodu',      'Talebi Giren Personel Kodu'],
        ['IrisRapor_TalebiGirenPersonelNo',        'Talebi Giren Personel No'],
        ['IrisRapor_TalebiGirenPersonelAltbayi',   'Alt Bayi'],
        ['IrisRapor_Kampanya',                     'Kampanya'],
        ['IrisRapor_Paket',                        'Paket'],
        ['IrisRapor_SatisDurumu',                  'Satış Durumu'],
        ['IrisRapor_BasvuruSurecDurumu',           'Başvuru Süreç Durumu'],
        ['IrisRapor_GuncelOutletDurum',            'Güncel Outlet Durum'],
        ['IrisRapor_TeyitDurum',                   'Teyit Durum'],
        ['IrisRapor_TeyitAramaDurum',              'Teyit Arama Durum'],
        ['IrisRapor_RandevuTarihi',                'Randevu Tarihi'],
        ['IrisRapor_DtMusteriNo',                  'DT Müşteri No'],
        ['IrisRapor_AktiveEdilenUyeNo',            'Aktive Edilen Üye No'],
        ['IrisRapor_AktiveEdilenOutletNo',         'Aktive Edilen Outlet No'],
        ['IrisRapor_AktiveEdilenSozlesmeNo',       'Aktive Edilen Sözleşme No'],
        ['IrisRapor_AktiveEdilenSozlesmeKmp',      'Aktive Edilen Sözleşme Kmp'],
        ['IrisRapor_AktiveEdilenSozlesmeDurum',    'Aktive Edilen Sözleşme Durum'],
        ['IrisRapor_UyduBasvuruUyeNo',             'Uydu Başvuru Üye No'],
        ['IrisRapor_UyduBasvuruPotansiyelNo',      'Uydu Başvuru Potansiyel No']
    ];

    // Filtreye uyan HAM DigiturkIrisRapor kayıtlarını sunucudan çekip .xlsx indirir.
    // (DataTable'daki hesaplanmış özet değil, hesaba giren satırların kendisi)
    function excelIndir() {
        const yil = $('#h_yil').val(), aylar = $('#h_ay').val() || [];
        if (!yil || !aylar.length) { showToast('Yıl ve en az bir ay seçiniz', 'warning'); return; }

        const $btn = $('#excelIndir');
        const eskiHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Hazırlanıyor...');

        const d = { action: 'iris_kayitlar' };
        $('#hesapForm').serializeArray().forEach(x => {
            if (x.name === 'ay[]') { (d['ay'] = d['ay'] || []).push(x.value); }
            else { d[x.name] = x.value; }
        });

        $.post('', d, function (r) {
            $btn.prop('disabled', false).html(eskiHtml);

            if (!r.success)      { showToast(r.message || 'Hata', 'error'); return; }
            if (!r.data.length)  { showToast('Filtreye uyan IRIS kaydı bulunamadı', 'warning'); return; }

            const basliklar = IRIS_KOLONLARI.map(k => k[1]);
            const veri = r.data.map(row => IRIS_KOLONLARI.map(k => {
                const v = row[k[0]];
                return (v === null || v === undefined) ? '' : v;
            }));

            const ws = XLSX.utils.aoa_to_sheet([basliklar, ...veri]);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'IRIS Rapor');

            const aylarSirali = aylar.map(Number).sort((a, b) => a - b);
            const donem = yil + '_' + aylarSirali.map(a => String(a).padStart(2, '0')).join('-');
            XLSX.writeFile(wb, `iris_rapor_${donem}.xlsx`);

            if (r.kesildi) showToast(`Kayıt sayısı sınırı aştı; ilk ${r.limit} satır indirildi.`, 'warning');
            else           showToast(`${r.data.length} kayıt indiriliyor...`, 'info');
        }, 'json').fail(function () {
            $btn.prop('disabled', false).html(eskiHtml);
            showToast('Excel verisi alınamadı', 'error');
        });
    }

    // Aynı elemana ikinci kez select2 uygulanmasını (hayalet dropdown) engeller
    function safeSelect2($el, opts) {
        if ($el.hasClass('select2-hidden-accessible')) $el.select2('destroy');
        return $el.select2(opts);
    }

    function initDonemSelect() {
        // Varsayılan dönem = önceki ay (Ocak ise Aralık + bir önceki yıl)
        const now = new Date();
        let hedefAy  = now.getMonth();       // 0-11; önceki ayın 1-tabanlı no'su (Ocak → 0, aşağıda düzeltilir)
        let hedefYil = now.getFullYear();
        if (hedefAy === 0) { hedefAy = 12; hedefYil -= 1; }

        const buYil = now.getFullYear();
        let yilOpts = '';
        for (let y = buYil + 1; y >= buYil - 3; y--) {
            yilOpts += `<option value="${y}" ${y === hedefYil ? 'selected' : ''}>${y}</option>`;
        }
        $('#h_yil').html(yilOpts);

        let ayOpts = '';
        for (const no in AYLAR) {
            ayOpts += `<option value="${no}" ${parseInt(no) === hedefAy ? 'selected' : ''}>${AYLAR[no]}</option>`;
        }
        $('#h_ay').html(ayOpts);

        safeSelect2($('#h_yil'), { theme: 'bootstrap-5', width: '100%' });
        safeSelect2($('#h_ay'),  { theme: 'bootstrap-5', width: '100%', placeholder: 'Ay seçiniz...', closeOnSelect: false });
    }

    function loadBirimler() {
        $.post('', { action: 'birimler' }, function (r) {
            if (!r.success) return;
            const opts = ['<option value="">Tümü</option>'];
            r.data.forEach(b => opts.push(`<option value="${b.KullaniciBirim_id}">${escapeHtml(b.KullaniciBirim_Adi)}</option>`));
            safeSelect2($('#h_birim').html(opts.join('')), { theme: 'bootstrap-5', width: '100%' });
        }, 'json');
    }

    // Outlet + satış durumu dropdown'ları — sayfa açılışında AKTIF / Tamamlandı seçili gelir
    function loadDurumlar() {
        $.post('', { action: 'durumlar' }, function (r) {
            if (!r.success) return;

            const outletOpts = ['<option value="">Tümü</option>'];
            r.outlet.forEach(o => {
                const sel = (o.v === 'AKTIF') ? 'selected' : '';
                outletOpts.push(`<option value="${escapeHtml(o.v)}" ${sel}>${escapeHtml(o.v)}</option>`);
            });
            safeSelect2($('#h_outlet').html(outletOpts.join('')), { theme: 'bootstrap-5', width: '100%' });

            const satisOpts = ['<option value="">Tümü</option>'];
            r.satis.forEach(s => {
                const sel = (s.v === 'Tamamlandı') ? 'selected' : '';
                satisOpts.push(`<option value="${escapeHtml(s.v)}" ${sel}>${escapeHtml(s.v)}</option>`);
            });
            safeSelect2($('#h_satis').html(satisOpts.join('')), { theme: 'bootstrap-5', width: '100%' });
        }, 'json');
    }

    function loadAltBayiler() {
        $.post('', { action: 'altbayiler' }, function (r) {
            if (!r.success) return;
            const opts = ['<option value="">Tümü</option>'];
            r.data.forEach(b => opts.push(`<option value="${b.DigiturkAltBayiler_Id}">${escapeHtml(b.Etiket)}</option>`));
            safeSelect2($('#h_altbayi').html(opts.join('')), { theme: 'bootstrap-5', width: '100%' });
        }, 'json');
    }

    function donemAralik(yil, ay) {
        let sAy = ay + 1, sYil = yil;
        if (sAy > 12) { sAy = 1; sYil++; }
        const bas = `04.${String(ay).padStart(2,'0')}.${yil}`;
        const bit = `03.${String(sAy).padStart(2,'0')}.${sYil}`;
        return `${bas} - ${bit}`;
    }

    // Yıl + tek ay seçiliyken dönem tarihini (tanım/formül) getirip inputlara yazar.
    function loadDonemTarih() {
        const yil = $('#h_yil').val();
        const aylar = ($('#h_ay').val() || []).map(Number);

        if (!yil || aylar.length !== 1) {
            $('#h_bas, #h_bit').val('').prop('disabled', true);
            return;
        }
        $('#h_bas, #h_bit').prop('disabled', false);
        $.post('', { action: 'donem', yil: yil, ay: aylar[0] }, function (r) {
            if (r && r.success) {
                $('#h_bas').val(r.bas);
                $('#h_bit').val(r.bit);
            }
        }, 'json');
    }

    function updateDonemText() {
        const yil = parseInt($('#h_yil').val());
        const aylar = ($('#h_ay').val() || []).map(Number).sort((a, b) => a - b);
        if (!yil || aylar.length === 0) { $('#h_donem_text').text('-'); return; }
        if (aylar.length === 1) $('#h_donem_text').text(donemAralik(yil, aylar[0]));
        else $('#h_donem_text').text(`${aylar.length} dönem: ` + aylar.map(a => AYLAR[a]).join(', '));
    }

    function hesapla() {
        const yil = $('#h_yil').val(), aylar = $('#h_ay').val() || [],
              altbayi = $('#h_altbayi').val(), birim = $('#h_birim').val(),
              outlet = $('#h_outlet').val(), satis = $('#h_satis').val();
        if (!yil || aylar.length === 0) { showToast('Yıl ve en az bir ay seçiniz', 'warning'); return; }

        const bas = (aylar.length === 1 && !$('#h_bas').prop('disabled')) ? ($('#h_bas').val() || '') : '';
        const bit = (aylar.length === 1 && !$('#h_bit').prop('disabled')) ? ($('#h_bit').val() || '') : '';

        const $btn = $('#hesapForm button[type="submit"]');
        $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Hesaplanıyor...');

        $.ajax({
            url: '', method: 'POST', data: { action: 'hesapla', yil, ay: aylar, altbayi, birim, outlet, satis, bas, bit }, dataType: 'json',
            success: function (r) {
                if (r.success) {
                    sonRows = r.data;
                    renderTable(r.data);
                    renderStats(r.data);
                    if (r.donemler && r.donemler.length) {
                        if (r.donemler.length === 1) {
                            const d = r.donemler[0];
                            const kaynakEtiket = { manuel: ' (manuel tarih)', tanim: ' (hakediş tanımından)', formul: ' (formül — tanım yok)' };
                            $('#h_donem_text').text(`${d.bas} - ${d.bit}` + (kaynakEtiket[d.kaynak] || ''));
                        } else {
                            const adlar = r.donemler.map(d => AYLAR[d.ay]).join(', ');
                            $('#h_donem_text').text(`${r.donemler.length} dönem: ${adlar} ${r.donemler[0].yil}`);
                        }
                    }
                    if (r.data.length === 0) showToast('Seçili dönem(ler)e ait eşleşen kampanya bulunamadı', 'info');
                    else showToast(`${r.data.length} satır hesaplandı`, 'success');
                } else {
                    showError('Hata!', r.message);
                }
            },
            error: function () { showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'); },
            complete: function () { $btn.prop('disabled', false).html('<i class="bi bi-calculator"></i> Hesapla'); }
        });
    }

    function renderTable(rows) {
        dataTable.clear();
        rows.forEach(row => {
            // Kademe grup havuzundan geldiyse rozetin yanında grup adı + grup toplamı görünür
            const kademe = `<span class="skala-badge">${escapeHtml(SKALALAR[row.kademe] || '-')}</span>`
                + (row.grupAd
                    ? ` <span class="grup-badge" title="Skala kademesi '${escapeHtml(row.grupAd)}' grubunun toplam ${row.grupToplam} adedinden hesaplandı"><i class="bi bi-diagram-3"></i> ${escapeHtml(row.grupAd)} · ${row.grupToplam}</span>`
                    : '');
            const bayiCell = escapeHtml(row.bayiEtiket || row.bayi) +
                (!row.eslesti ? ' <span class="eksik-badge" title="DigiturkAltBayiler ile eşleşmedi"><i class="bi bi-exclamation-circle"></i> eşleşmedi</span>' : '');
            const donemSort = row.donemYil * 100 + row.donemAy;

            // Her kampanya için adet ve tutar (ara toplam) hücreleri — sütun sırası KAMPANYALAR ile aynı
            const adetHucre = KAMPANYALAR.map(ad => {
                const c = row.kampanyalar[ad];
                return `<span class="adet-text">${c ? c.adet : 0}</span>`;
            });
            const tutarHucre = KAMPANYALAR.map(ad => {
                const c = row.kampanyalar[ad];
                if (!c) return '<span class="text-muted">-</span>';
                if (c.birimTutar === null) return '<span class="eksik-badge" title="Tutar tanımsız">tanımsız</span>';
                return `<span class="tutar-text">${formatTutar(c.hakedis)}</span>`;
            });

            dataTable.row.add([
                donemSort,
                escapeHtml(row.birim),
                bayiCell,
                kademe,
                `<span class="adet-text">${row.toplam}</span>`,
                ...adetHucre,
                ...tutarHucre,
                `<span class="tutar-text">${formatTutar(row.hakedis)}</span>`
            ]);
        });
        dataTable.draw();
        renderFooter(rows);
        sutunGorunurluk(rows);
    }

    // GENEL TOPLAM'ı 0 olan kampanya sütunlarını (adet + tutar) gizler.
    // Yalnız tablo görünümünü etkiler; ödemeye aktarım ve Excel çıktısı sunucudan
    // gelen veriyi kullandığı için bu gizlemeden etkilenmez.
    function sutunGorunurluk(rows) {
        const n = KAMPANYALAR.length;
        aktifKampanyalar = [];

        KAMPANYALAR.forEach((ad, i) => {
            let adet = 0, tutar = 0;
            rows.forEach(r => {
                if (r.kampanyalar[ad]) { adet += r.kampanyalar[ad].adet; tutar += +r.kampanyalar[ad].hakedis; }
            });
            // Hiç hesaplama yapılmadıysa (rows boş) sütunlar açık kalsın
            const gorunur = rows.length === 0 || adet !== 0 || tutar !== 0;
            if (gorunur) aktifKampanyalar.push(ad);

            dataTable.column(5 + i).visible(gorunur, false);      // adet sütunu
            dataTable.column(5 + n + i).visible(gorunur, false);   // tutar sütunu
        });

        dataTable.columns.adjust();
    }

    // GENEL TOPLAM (tablo footer) — sayfalama/sıralamadan bağımsız
    function renderFooter(rows) {
        $('.ft-adet').each(function () {
            const ad = KAMPANYALAR[$(this).data('idx')];
            let s = 0;
            rows.forEach(r => { if (r.kampanyalar[ad]) s += r.kampanyalar[ad].adet; });
            $(this).text(s.toLocaleString('tr-TR'));
        });
        $('.ft-tutar').each(function () {
            const ad = KAMPANYALAR[$(this).data('idx')];
            let s = 0;
            rows.forEach(r => { if (r.kampanyalar[ad]) s += +r.kampanyalar[ad].hakedis; });
            $(this).text(formatTutar(s));
        });
        let toplam = 0, hakedis = 0;
        rows.forEach(r => { toplam += r.toplam; hakedis += +r.hakedis; });
        $('#ft-toplam').text(toplam.toLocaleString('tr-TR'));
        $('#ft-hakedis').text(formatTutar(hakedis));
    }

    function renderStats(rows) {
        let adet = 0, hakedis = 0, eksik = 0;
        const bayiSet = new Set();
        rows.forEach(r => {
            hakedis += parseFloat(r.hakedis || 0);
            if (r.eksik) eksik++;
            bayiSet.add((r.id || 0) + "|" + r.bayi);
            Object.values(r.kampanyalar).forEach(c => { adet += c.adet; });
        });
        $('#stat-bayi').text(bayiSet.size);
        $('#stat-adet').text(adet.toLocaleString('tr-TR'));
        $('#stat-hakedis').text(formatTutar(hakedis));
        $('#stat-eksik').text(eksik);
    }

    function formatTutar(v) {
        const n = parseFloat(v || 0);
        return n.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
</script>
</body>
</html>
