<?php
/**
 * Admin Panel - VoIP Günlük Harcamalar
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

$pageTitle       = $pageinfo['sayfalar_sayfa_adi'] ?? 'VoIP Günlük Harcamalar';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageinfo['menu_adi'] ?? null;

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

// ─── Birim bazlı veri kısıtı (birim_gor) — KullaniciBirimYetkileri junction üzerinden ─────
// Harcama → (Kanal + TelefonNo) → VoIPHesaplar → birim (KullaniciBirimYetkileri_VoIPHesap_id).
$birimKisitli   = (!$permissions['is_admin'] && !empty($permissions['can_view_birim']));
$izinliBirimler = [];
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
        $izinliBirimler = array_map(fn($r) => (int)$r['KullaniciBirim_id'], $rows);
    }
}

/**
 * Birim kısıt EXISTS parçası — Odemeler_Referans (telefon) → VoIPHesaplar → birim junction.
 * Odemeler tablosunda kanal/hesap tutulmadığından eşleşme telefon numarası üzerinden yapılır.
 * @return [sql, params]
 */
function birimKisitTelefonWhere(array $izinliBirimler): array {
    $ph  = implode(',', array_fill(0, count($izinliBirimler), '?'));
    $sql = "EXISTS (
        SELECT 1 FROM VoIPHesaplar vrk
        JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_VoIPHesap_id = vrk.VoIPHesaplar_id
        WHERE vrk.VoIPHesaplar_TelefonNo = o.Odemeler_Referans
          AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
          AND kby.Durum = 1
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
    )";
    return [$sql, $izinliBirimler];
}

// ─── Yardımcı ─────────────────────────────────────────────────────────────────
function sn2DkSn(int $sn): string
{
    return floor($sn / 60) . ':' . str_pad($sn % 60, 2, '0', STR_PAD_LEFT);
}

/** Odemeler_Aciklama metninden çağrı detaylarını ayrıştırır (cron yazım formatına göre). */
function voipAciklamaParse(?string $a): array
{
    $a = (string)$a;
    $cagri = $sure = $fatura = 0; $dk = 0.0;
    if (preg_match('/Çağrı Sayısı:\s*(\d+)/u', $a, $m))          $cagri  = (int)$m[1];
    if (preg_match('/Süre \(dk:sn\):\s*(\d+):(\d+)/u', $a, $m))  $sure   = (int)$m[1] * 60 + (int)$m[2];
    if (preg_match('/Fatura Süresi:\s*(\d+):(\d+)/u', $a, $m))   $fatura = (int)$m[1] * 60 + (int)$m[2];
    if (preg_match('/Dk\. Ücreti:\s*([\d.]+)/u', $a, $m))        $dk     = (float)$m[1];
    return ['cagri' => $cagri, 'sure' => $sure, 'fatura' => $fatura, 'dk' => $dk];
}

// NOT: VoIP rapor çekme (sippyRaporSync), Sippy HTTP isteği (sippyCurl) ve süre
// dönüştürücü (dksnSaniye) fonksiyonları cron/tasks.php içine taşındı.
// Manuel "Raporu Güncelle" butonu sync_rapor handler'ında tasks.php'yi require ederek
// aynı ortak fonksiyonu kullanır (cookie artık proje temp/ altına yazılır — Plesk CLI uyumlu).

// ─── AJAX ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'harcama_listele':
                $tarihBas  = $_POST['tarih_bas']  ?? date('Y-m-d', strtotime('-7 days'));
                $tarihBit  = $_POST['tarih_bit']  ?? date('Y-m-d');
                $kanalId   = $_POST['kanal_id']   ?? '';
                $birimId   = $_POST['birim_id']   ?? '';
                $search    = $_POST['search']     ?? '';

                $where  = ['o.Odemeler_OdemeTuruId = 4', 'o.Odemeler_Tarih >= ?', 'o.Odemeler_Tarih <= ?'];
                $params = [$tarihBas, $tarihBit];

                if ($kanalId !== '') { $where[] = 'v.KanalId = ?'; $params[] = (int)$kanalId; }
                if ($search)         { $where[] = 'o.Odemeler_Referans LIKE ?'; $params[] = "%$search%"; }
                if ($birimId !== '') {
                    $where[]  = "EXISTS (SELECT 1 FROM VoIPHesaplar vf
                                         JOIN KullaniciBirimYetkileri kbf ON kbf.KullaniciBirimYetkileri_VoIPHesap_id = vf.VoIPHesaplar_id
                                         WHERE vf.VoIPHesaplar_TelefonNo = o.Odemeler_Referans
                                           AND kbf.KullaniciBirimYetkileri_Birim_id = ? AND kbf.Durum = 1
                                           AND (kbf.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kbf.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
                                           AND (kbf.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kbf.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE()))";
                    $params[] = (int)$birimId;
                }

                // Birim bazlı veri kısıtı (telefon → VoIPHesaplar junction)
                if ($birimKisitli) {
                    if (empty($izinliBirimler)) { echo json_encode(['success' => true, 'data' => []]); break; }
                    [$wK, $pK] = birimKisitTelefonWhere($izinliBirimler);
                    $where[] = $wK;
                    $params  = array_merge($params, $pK);
                }

                $liste = $db->fetchAll("
                    SELECT
                        o.Odemeler_Id,
                        CONVERT(VARCHAR(10), o.Odemeler_Tarih, 23) as Tarih,
                        o.Odemeler_Referans as TelefonNo,
                        CAST(o.Odemeler_Tutar AS DECIMAL(18,4)) as Tutar,
                        o.Odemeler_Aciklama as Aciklama,
                        v.KanalAdi,
                        v.OperatorAdi,
                        v.HesapAciklama,
                        v.Birimler
                    FROM Odemeler o
                    OUTER APPLY (
                        SELECT TOP 1
                            k.EntegrasyonKanallari_id       as KanalId,
                            k.EntegrasyonKanallari_KanalAdi as KanalAdi,
                            e.Entegrasyonlar_Adi            as OperatorAdi,
                            vh.VoIPHesaplar_Aciklama        as HesapAciklama,
                            (SELECT STRING_AGG(kb.KullaniciBirim_Adi, ', ')
                             FROM KullaniciBirimYetkileri kby
                             JOIN KullaniciBirim kb ON kby.KullaniciBirimYetkileri_Birim_id = kb.KullaniciBirim_id
                             WHERE kby.KullaniciBirimYetkileri_VoIPHesap_id = vh.VoIPHesaplar_id AND kby.Durum = 1
                               AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
                               AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())) AS Birimler
                        FROM VoIPHesaplar vh
                        INNER JOIN EntegrasyonKanallari k ON vh.VoIPHesaplar_Kanal_id = k.EntegrasyonKanallari_id
                        INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                        WHERE vh.VoIPHesaplar_TelefonNo = o.Odemeler_Referans AND vh.Durum = 1
                        ORDER BY vh.VoIPHesaplar_id DESC
                    ) v
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY o.Odemeler_Tarih DESC, o.Odemeler_Tutar DESC
                ", $params);

                foreach ($liste as &$r) {
                    $p = voipAciklamaParse($r['Aciklama']);
                    $r['CagriSayisi']  = $p['cagri'];
                    $r['Sure']         = $p['sure'];
                    $r['FaturaSure']   = $p['fatura'];
                    $r['DakikaUcreti'] = $p['dk'];
                    unset($r['Aciklama']);
                }
                unset($r);

                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            case 'stats':
                $tarihBas = $_POST['tarih_bas'] ?? date('Y-m-d', strtotime('-7 days'));
                $tarihBit = $_POST['tarih_bit'] ?? date('Y-m-d');

                $statWhere  = ['o.Odemeler_OdemeTuruId = 4', 'o.Odemeler_Tarih >= ?', 'o.Odemeler_Tarih <= ?'];
                $statParams = [$tarihBas, $tarihBit];

                // Birim bazlı veri kısıtı: yalnız izinli birimlerin VoIP hesaplarına ait harcamalar (telefon eşleşmesi)
                if ($birimKisitli) {
                    if (empty($izinliBirimler)) {
                        echo json_encode(['success' => true, 'data' => [
                            'kayit_sayisi'=>0,'toplam_tutar'=>0,'toplam_cagri'=>0,'toplam_fatura_sure'=>0,'ort_dk_ucreti'=>0
                        ]]);
                        break;
                    }
                    [$wK, $pK] = birimKisitTelefonWhere($izinliBirimler);
                    $statWhere[] = $wK;
                    $statParams  = array_merge($statParams, $pK);
                }

                $rows = $db->fetchAll("
                    SELECT o.Odemeler_Tutar as Tutar, o.Odemeler_Aciklama as Aciklama
                    FROM Odemeler o
                    WHERE " . implode(' AND ', $statWhere) . "
                ", $statParams);

                $kayit = 0; $toplamTutar = 0.0; $toplamCagri = 0; $toplamFatura = 0; $dkTop = 0.0; $dkAdet = 0;
                foreach ($rows as $r) {
                    $kayit++;
                    $toplamTutar += (float)$r['Tutar'];
                    $p = voipAciklamaParse($r['Aciklama']);
                    $toplamCagri  += $p['cagri'];
                    $toplamFatura += $p['fatura'];
                    if ($p['dk'] > 0) { $dkTop += $p['dk']; $dkAdet++; }
                }

                echo json_encode(['success' => true, 'data' => [
                    'kayit_sayisi'       => $kayit,
                    'toplam_tutar'       => $toplamTutar,
                    'toplam_cagri'       => $toplamCagri,
                    'toplam_fatura_sure' => $toplamFatura,
                    'ort_dk_ucreti'      => $dkAdet ? $dkTop / $dkAdet : 0,
                ]]);
                break;

            case 'birim_select':
                if ($birimKisitli) {
                    if (empty($izinliBirimler)) { echo json_encode(['success' => true, 'data' => []]); break; }
                    $ph   = implode(',', array_fill(0, count($izinliBirimler), '?'));
                    $liste = $db->fetchAll("SELECT KullaniciBirim_id, KullaniciBirim_Adi FROM KullaniciBirim WHERE Durum=1 AND KullaniciBirim_id IN ($ph) ORDER BY KullaniciBirim_Adi", $izinliBirimler);
                } else {
                    $liste = $db->fetchAll("SELECT KullaniciBirim_id, KullaniciBirim_Adi FROM KullaniciBirim WHERE Durum=1 ORDER BY KullaniciBirim_Adi");
                }
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            case 'kanal_select':
                $liste = $db->fetchAll("
                    SELECT k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_KanalAdi, e.Entegrasyonlar_Adi
                    FROM EntegrasyonKanallari k
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE e.Entegrasyonlar_Tip = 'voip' AND k.Durum = 1 AND e.Durum = 1
                    ORDER BY e.Entegrasyonlar_Adi, k.EntegrasyonKanallari_KanalAdi
                ");
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            case 'sync_rapor':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']);
                    break;
                }
                // Rapor çekme mantığı cron/tasks.php'deki ortak sippyRaporSync() ile paylaşılır
                require_once __DIR__ . '/../../cron/tasks.php';
                $tarih = $_POST['tarih'] ?? date('Y-m-d', strtotime('-1 day'));

                $kanallar = $db->fetchAll("
                    SELECT k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_KanalAdi,
                           k.EntegrasyonKanallari_Instance, k.EntegrasyonKanallari_Kullanici,
                           k.EntegrasyonKanallari_Sifre, e.Entegrasyonlar_BaseURL
                    FROM EntegrasyonKanallari k
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE e.Entegrasyonlar_Tip = 'voip' AND k.Durum = 1 AND e.Durum = 1
                ");

                if (!$kanallar) {
                    echo json_encode(['success' => false, 'message' => 'Aktif VoIP kanalı bulunamadı.']);
                    break;
                }

                $sonuclar = [];
                $toplamEklenen = $toplamGuncellenen = 0;

                foreach ($kanallar as $k) {
                    $sonuc = sippyRaporSync($k, $tarih, $db);
                    $sonuclar[] = array_merge($sonuc, ['kanal' => $k['EntegrasyonKanallari_KanalAdi']]);
                    if ($sonuc['success']) {
                        $toplamEklenen     += $sonuc['eklenen'];
                        $toplamGuncellenen += $sonuc['guncellenen'];
                    }
                }

                echo json_encode([
                    'success'     => true,
                    'eklenen'     => $toplamEklenen,
                    'guncellenen' => $toplamGuncellenen,
                    'detay'       => $sonuclar,
                ]);
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

                <div class="alert alert-info d-flex align-items-center justify-content-between py-2">
                    <div>
                        <i class="bi bi-clock-history me-1"></i>
                        Günlük harcama raporu <strong>otomatik olarak Cron Yönetimi</strong> üzerinden güncellenir
                        (<span class="cron-ref">VoIP Günlük Harcama</span> görevi). Aşağıdaki
                        <strong>"Raporu Güncelle"</strong> butonu yalnızca manuel/anlık güncelleme içindir.
                    </div>
                    <a href="/Admin/pages/cron-yonetimi.php" class="btn btn-sm btn-outline-primary ms-3 text-nowrap">
                        <i class="bi bi-gear"></i> Cron Yönetimi
                    </a>
                </div>

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-currency-dollar"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Tutar</span>
                                <span class="info-box-number" id="stat-tutar">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-telephone-inbound"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Çağrı</span>
                                <span class="info-box-number" id="stat-cagri">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-clock"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Fatura Süresi</span>
                                <span class="info-box-number" id="stat-sure">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-graph-up"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Ort. Dk Ücreti</span>
                                <span class="info-box-number" id="stat-dkucreti">—</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                        <div class="card-tools">
                            <button class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterPanel">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body collapse show" id="filterPanel">
                        <div class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">Başlangıç Tarihi</label>
                                <input type="date" class="form-control" id="f_tarih_bas">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Bitiş Tarihi</label>
                                <input type="date" class="form-control" id="f_tarih_bit">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Operatör / Kanal</label>
                                <select class="form-select select2-basic" id="f_kanal">
                                    <option value="">Tümü</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Birim</label>
                                <select class="form-select select2-basic" id="f_birim">
                                    <option value="">Tümü</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Telefon No</label>
                                <input type="text" class="form-control" id="f_search" placeholder="Telefon ara...">
                            </div>
                            <div class="col-md-12">
                                <button class="btn btn-primary" onclick="listele()"><i class="bi bi-search"></i> Filtrele</button>
                                <button class="btn btn-secondary" onclick="filtreTemizle()"><i class="bi bi-x-circle"></i> Temizle</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tablo -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Harcama Listesi</h3>
                        <div class="card-tools d-flex align-items-center gap-2">
                            <?php if ($permissions['can_edit']): ?>
                            <input type="date" class="form-control form-control-sm" id="sync_tarih" style="width:160px">
                            <button class="btn btn-success btn-sm" onclick="raporGuncelle()" id="btnGuncelle">
                                <i class="bi bi-arrow-repeat"></i> Raporu Güncelle
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="tblHarcamalar" class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>Operatör</th>
                                    <th>Telefon No</th>
                                    <th>Açıklama</th>
                                    <th>Birim</th>
                                    <th>Çağrı</th>
                                    <th>Süre</th>
                                    <th>Fatura Süresi</th>
                                    <th>Tutar (TRY)</th>
                                    <th>Dk Ücreti</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                            <tfoot>
                                <tr class="table-primary fw-bold">
                                    <td colspan="5" id="tfoot-label">—</td>
                                    <td id="tfoot-cagri">—</td>
                                    <td id="tfoot-sure">—</td>
                                    <td id="tfoot-fatura">—</td>
                                    <td id="tfoot-tutar">—</td>
                                    <td id="tfoot-dkucreti">—</td>
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
let dtHarcamalar;

$(document).ready(function () {
    // Varsayılan tarih aralığı: son 7 gün
    const bugun = new Date();
    const yediGunOnce = new Date(bugun);
    yediGunOnce.setDate(yediGunOnce.getDate() - 7);

    $('#f_tarih_bas').val(formatTarih(yediGunOnce));
    $('#f_tarih_bit').val(formatTarih(bugun));

    // Sync tarihi: dün
    const dun = new Date(bugun);
    dun.setDate(dun.getDate() - 1);
    $('#sync_tarih').val(formatTarih(dun));

    $('.select2-basic').select2({ theme: 'bootstrap-5', width: '100%' });
    kanalSelectDoldur();
    birimSelectDoldur();
    listele();
});

function formatTarih(d) {
    return d.toISOString().split('T')[0];
}

function sn2DkSn(sn) {
    const dk = Math.floor(sn / 60);
    const s  = sn % 60;
    return dk + ':' + String(s).padStart(2, '0');
}

function kanalSelectDoldur() {
    $.post(pageUrl, { action: 'kanal_select' }, function (r) {
        if (!r.success) return;
        const $sel = $('#f_kanal');
        $sel.find('option:not(:first)').remove();
        r.data.forEach(function (k) {
            $sel.append(`<option value="${k.EntegrasyonKanallari_id}">${htmlEncode(k.Entegrasyonlar_Adi)} — ${htmlEncode(k.EntegrasyonKanallari_KanalAdi)}</option>`);
        });
        $sel.trigger('change');
    });
}

function birimSelectDoldur() {
    $.post(pageUrl, { action: 'birim_select' }, function (r) {
        if (!r.success) return;
        const $sel = $('#f_birim');
        $sel.find('option:not(:first)').remove();
        r.data.forEach(function (b) {
            $sel.append(`<option value="${b.KullaniciBirim_id}">${htmlEncode(b.KullaniciBirim_Adi)}</option>`);
        });
        $sel.trigger('change');
    });
}

function listele() {
    const tarihBas = $('#f_tarih_bas').val();
    const tarihBit = $('#f_tarih_bit').val();

    const data = {
        action    : 'harcama_listele',
        tarih_bas : tarihBas,
        tarih_bit : tarihBit,
        kanal_id  : $('#f_kanal').val(),
        birim_id  : $('#f_birim').val(),
        search    : $('#f_search').val(),
    };

    // Stats güncelle
    statsYukle(tarihBas, tarihBit);

    $.post(pageUrl, data, function (r) {
        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }

        if (dtHarcamalar) dtHarcamalar.destroy();
        const $tbody = $('#tblHarcamalar tbody').empty();

        let totCagri = 0, totSure = 0, totFatura = 0, totTutar = 0;

        r.data.forEach(function (h) {
            totCagri  += parseInt(h.CagriSayisi) || 0;
            totSure   += parseInt(h.Sure) || 0;
            totFatura += parseInt(h.FaturaSure) || 0;
            totTutar  += parseFloat(h.Tutar) || 0;

            $tbody.append(`<tr>
                <td>${htmlEncode(h.Tarih)}</td>
                <td><small>${htmlEncode(h.OperatorAdi || '—')}</small></td>
                <td><strong>${htmlEncode(h.TelefonNo)}</strong></td>
                <td><small>${htmlEncode(h.HesapAciklama || '—')}</small></td>
                <td>${birimBadges(h.Birimler)}</td>
                <td>${h.CagriSayisi}</td>
                <td><small>${sn2DkSn(parseInt(h.Sure))}</small></td>
                <td><small>${sn2DkSn(parseInt(h.FaturaSure))}</small></td>
                <td><strong>${parseFloat(h.Tutar).toFixed(4)}</strong></td>
                <td><small>${parseFloat(h.DakikaUcreti).toFixed(4)}</small></td>
            </tr>`);
        });

        // Tfoot toplamları
        const kayitSayisi = r.data.length;
        $('#tfoot-label').text(kayitSayisi + ' kayıt');
        $('#tfoot-cagri').text(totCagri.toLocaleString('tr-TR'));
        $('#tfoot-sure').text(sn2DkSn(totSure));
        $('#tfoot-fatura').text(sn2DkSn(totFatura));
        $('#tfoot-tutar').text(totTutar.toFixed(4) + ' ₺');
        $('#tfoot-dkucreti').text('');

        dtHarcamalar = $('#tblHarcamalar').DataTable({
            language  : { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order     : [[0, 'desc'], [8, 'desc']],
            pageLength: 25,
            destroy   : true,
        });
    });
}

function statsYukle(tarihBas, tarihBit) {
    $.post(pageUrl, { action: 'stats', tarih_bas: tarihBas, tarih_bit: tarihBit }, function (r) {
        if (!r.success) return;
        const s = r.data;
        $('#stat-tutar').text(parseFloat(s.toplam_tutar || 0).toFixed(2) + ' ₺');
        $('#stat-cagri').text(parseInt(s.toplam_cagri || 0).toLocaleString('tr-TR'));
        $('#stat-sure').text(sn2DkSn(parseInt(s.toplam_fatura_sure || 0)));
        $('#stat-dkucreti').text(parseFloat(s.ort_dk_ucreti || 0).toFixed(4) + ' ₺/dk');
    });
}

function filtreTemizle() {
    const bugun = new Date();
    const yediGunOnce = new Date(bugun);
    yediGunOnce.setDate(yediGunOnce.getDate() - 7);
    $('#f_tarih_bas').val(formatTarih(yediGunOnce));
    $('#f_tarih_bit').val(formatTarih(bugun));
    $('#f_kanal, #f_birim, #f_search').val('');
    $('#f_kanal, #f_birim').trigger('change');
    listele();
}

function birimBadges(str) {
    if (!str) return '<span class="text-muted">—</span>';
    return String(str).split(', ').map(b => `<span class="badge text-bg-light border me-1">${htmlEncode(b)}</span>`).join('');
}

function raporGuncelle() {
    const tarih = $('#sync_tarih').val();
    if (!tarih) { Swal.fire('Uyarı', 'Lütfen bir tarih seçin.', 'warning'); return; }

    Swal.fire({
        title: 'Rapor Güncelleniyor...',
        text: tarih + ' tarihi için veriler çekiliyor.',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $('#btnGuncelle').prop('disabled', true);

    $.post(pageUrl, { action: 'sync_rapor', tarih: tarih }, function (r) {
        $('#btnGuncelle').prop('disabled', false);

        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }

        let detayHtml = '';
        (r.detay || []).forEach(function (d) {
            const ikon = d.success ? '✅' : '❌';
            const msg  = d.success
                ? `${d.toplam} kayıt — <b>${d.eklenen}</b> yeni, <b>${d.guncellenen}</b> güncellendi`
                : d.message;
            detayHtml += `<div>${ikon} <b>${htmlEncode(d.kanal)}</b>: ${msg}</div>`;
        });

        Swal.fire({
            icon : 'success',
            title: 'Rapor Güncellendi',
            html : `<b>${r.eklenen}</b> yeni eklendi &nbsp;|&nbsp; <b>${r.guncellenen}</b> güncellendi<hr>${detayHtml}`,
        });

        listele();
    }).fail(function () {
        $('#btnGuncelle').prop('disabled', false);
        Swal.fire('Hata', 'Sunucuya bağlanılamadı.', 'error');
    });
}

function htmlEncode(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

</body>
</html>
