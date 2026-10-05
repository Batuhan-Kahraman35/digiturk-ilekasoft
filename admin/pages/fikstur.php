<?php
/**
 * Admin Panel - Fikstür
 *
 * SporFikstur tablosundaki kazınmış maç kayıtlarını listeler.
 * Veri kaynağı: FiksturHelper (haberciniz.biz lig gösterim servisi).
 * Otomatik güncelleme cron görevi: fikstur_kazi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/FiksturHelper.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Fikstür';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Sayfa açılış filtresi: Süper Lig + bugünden itibaren.
// Lig kodu sabit yazılmaz, tablodan çözülür (kod değişirse filtre bozulmasın).
$varsayilanLig = $db->fetchOne("
    SELECT TOP 1 SporFikstur_LigKodu AS kod, SporFikstur_LigAdi AS ad
    FROM SporFikstur
    WHERE Durum = 1 AND SporFikstur_LigAdi LIKE N'%Süper Lig%'
    ORDER BY SporFikstur_LigKodu
");
$varsayilanLigKod  = $varsayilanLig['kod'] ?? '';
$varsayilanLigAd   = $varsayilanLig['ad']  ?? '';
$varsayilanTarihBas = date('Y-m-d');

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'list': {
                // ── DataTables server-side parametreleri ──
                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 200) $length = 25;

                $search   = trim($_POST['search']['value'] ?? '');
                $lig      = trim($_POST['f_lig']       ?? '');
                $hafta    = trim($_POST['f_hafta']     ?? '');
                $durumKod = trim($_POST['f_durum']     ?? '');
                $tarihBas = trim($_POST['f_tarih_bas'] ?? '');
                $tarihBit = trim($_POST['f_tarih_bit'] ?? '');

                $where  = ["f.Durum = 1"];
                $params = [];

                if ($search !== '') {
                    // Resmi adın yanında panelden girilen kısa ad da aranır.
                    // Sayımlar JOIN'siz kalsın diye JOIN yerine EXISTS kullanılır.
                    $where[] = "(f.SporFikstur_EvSahibi LIKE ? OR f.SporFikstur_Deplasman LIKE ?
                                 OR EXISTS (
                                     SELECT 1 FROM SporTakimlar t
                                     WHERE t.SporTakimlar_Id IN (f.SporFikstur_EvTakimId, f.SporFikstur_DeplasmanTakimId)
                                       AND t.SporTakimlar_Ad LIKE ?
                                 ))";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($lig !== '') {
                    $where[]  = "f.SporFikstur_LigKodu = ?";
                    $params[] = $lig;
                }
                if ($hafta !== '') {
                    $where[]  = "f.SporFikstur_Hafta = ?";
                    $params[] = (int)$hafta;
                }
                if ($durumKod !== '') {
                    $where[]  = "f.SporFikstur_DurumKodu = ?";
                    $params[] = $durumKod;
                }
                if ($tarihBas !== '') {
                    $where[]  = "f.SporFikstur_MacTarihi >= ?";
                    $params[] = $tarihBas . ' 00:00:00';
                }
                if ($tarihBit !== '') {
                    $where[]  = "f.SporFikstur_MacTarihi <= ?";
                    $params[] = $tarihBit . ' 23:59:59';
                }

                $whereClause = implode(" AND ", $where);

                // Sayımlar — tek tablo, JOIN yok
                $recordsTotal    = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM SporFikstur f WHERE f.Durum = 1")['c'] ?? 0);
                $recordsFiltered = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM SporFikstur f WHERE $whereClause", $params)['c'] ?? 0);

                // ── Sıralama (kolon index → güvenli whitelist) ──
                $orderMap = [
                    0 => 'f.SporFikstur_LigAdi',
                    1 => 'f.SporFikstur_Hafta',
                    2 => 'f.SporFikstur_MacTarihi',
                    3 => 'ISNULL(ev.SporTakimlar_Ad, f.SporFikstur_EvSahibi)',
                    5 => 'ISNULL(dep.SporTakimlar_Ad, f.SporFikstur_Deplasman)',
                    6 => 'f.SporFikstur_DurumKodu',
                    7 => 'f.SporFikstur_SonKazimaTarihi',
                ];
                $orderCol = (int)($_POST['order'][0]['column'] ?? 2);
                $orderBy  = $orderMap[$orderCol] ?? 'f.SporFikstur_MacTarihi';
                $orderDir = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

                $data = [];
                if ($recordsFiltered > 0) {
                    $data = $db->fetchAll("
                        SELECT
                            f.SporFikstur_Id,
                            f.SporFikstur_LigKodu,
                            f.SporFikstur_LigAdi,
                            f.SporFikstur_Hafta,
                            f.SporFikstur_HaftaMetin,
                            CONVERT(VARCHAR(16), f.SporFikstur_MacTarihi, 120) AS MacTarihi,
                            f.SporFikstur_TarihMetin,
                            f.SporFikstur_EvSahibi,
                            f.SporFikstur_Deplasman,
                            f.SporFikstur_EvGol,
                            f.SporFikstur_DeplasmanGol,
                            f.SporFikstur_DurumKodu,
                            f.SporFikstur_DurumMetin,
                            CONVERT(VARCHAR(19), f.SporFikstur_SonKazimaTarihi, 120) AS SonKazima,
                            f.SporFikstur_EvTakimId,
                            f.SporFikstur_DeplasmanTakimId,
                            ev.SporTakimlar_Ad       AS EvKisaAd,
                            ev.SporTakimlar_LogoYolu AS EvLogo,
                            dep.SporTakimlar_Ad       AS DepKisaAd,
                            dep.SporTakimlar_LogoYolu AS DepLogo
                        FROM SporFikstur f
                        LEFT JOIN SporTakimlar ev  ON ev.SporTakimlar_Id  = f.SporFikstur_EvTakimId
                        LEFT JOIN SporTakimlar dep ON dep.SporTakimlar_Id = f.SporFikstur_DeplasmanTakimId
                        WHERE $whereClause
                        ORDER BY $orderBy $orderDir, f.SporFikstur_Id DESC
                        OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
                    ", $params);
                }

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $data,
                ]);
                break;
            }

            case 'stats': {
                $ozet = $db->fetchOne("
                    SELECT
                        COUNT(*) AS toplam,
                        COUNT(DISTINCT SporFikstur_LigKodu) AS lig,
                        SUM(CASE WHEN SporFikstur_DurumKodu = 'bitti'    THEN 1 ELSE 0 END) AS oynanan,
                        SUM(CASE WHEN SporFikstur_DurumKodu = 'bekliyor' THEN 1 ELSE 0 END) AS bekleyen,
                        SUM(CASE WHEN SporFikstur_DurumKodu = 'devam'    THEN 1 ELSE 0 END) AS devam,
                        CONVERT(VARCHAR(19), MAX(SporFikstur_SonKazimaTarihi), 120) AS son_kazima
                    FROM SporFikstur
                    WHERE Durum = 1
                ");

                echo json_encode(['success' => true, 'data' => [
                    'toplam'     => (int)($ozet['toplam']   ?? 0),
                    'lig'        => (int)($ozet['lig']      ?? 0),
                    'oynanan'    => (int)($ozet['oynanan']  ?? 0),
                    'bekleyen'   => (int)($ozet['bekleyen'] ?? 0),
                    'devam'      => (int)($ozet['devam']    ?? 0),
                    'son_kazima' => $ozet['son_kazima'] ?? null,
                ]]);
                break;
            }

            case 'filtre_secenekleri': {
                // Lig ve hafta listeleri tablodan çekilir; sabit liste tutulmaz
                $ligler = $db->fetchAll("
                    SELECT DISTINCT SporFikstur_LigKodu AS kod, SporFikstur_LigAdi AS ad
                    FROM SporFikstur
                    WHERE Durum = 1
                    ORDER BY SporFikstur_LigAdi
                ");
                $haftalar = $db->fetchAll("
                    SELECT DISTINCT SporFikstur_Hafta AS hafta
                    FROM SporFikstur
                    WHERE Durum = 1 AND SporFikstur_Hafta > 0
                    ORDER BY SporFikstur_Hafta
                ");

                echo json_encode(['success' => true, 'data' => [
                    'ligler'   => $ligler,
                    'haftalar' => array_column($haftalar, 'hafta'),
                ]]);
                break;
            }

            case 'takim_get': {
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("
                    SELECT SporTakimlar_Id, SporTakimlar_ResmiAd, SporTakimlar_Ad,
                           SporTakimlar_LogoYolu, SporTakimlar_LigKodu
                    FROM SporTakimlar
                    WHERE SporTakimlar_Id = ?
                ", [$id]);

                if (!$row) {
                    echo json_encode(['success' => false, 'message' => 'Takım bulunamadı.']);
                    break;
                }

                // Bu takımın kaç maçı var — modalda bilgi olarak gösterilir
                $macAdet = $db->fetchOne("
                    SELECT COUNT(*) AS c FROM SporFikstur
                    WHERE SporFikstur_EvTakimId = ? OR SporFikstur_DeplasmanTakimId = ?
                ", [$id, $id]);

                $row['MacAdet'] = (int)($macAdet['c'] ?? 0);
                echo json_encode(['success' => true, 'data' => $row]);
                break;
            }

            case 'takim_kaydet': {
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }

                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz takım.']);
                    break;
                }

                $mevcut = $db->fetchOne("SELECT SporTakimlar_LogoYolu FROM SporTakimlar WHERE SporTakimlar_Id = ?", [$id]);
                if (!$mevcut) {
                    echo json_encode(['success' => false, 'message' => 'Takım bulunamadı.']);
                    break;
                }

                $logoYolu = $mevcut['SporTakimlar_LogoYolu'];

                // Logo kaldırma istendi mi?
                if (!empty($_POST['logo_sil'])) {
                    $logoYolu = null;
                }

                // Yeni logo yüklendi mi?
                if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $izinli = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
                    $uzanti = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));

                    if (!in_array($uzanti, $izinli, true)) {
                        echo json_encode(['success' => false, 'message' => 'Yalnız PNG, JPG, GIF, WEBP veya SVG yüklenebilir.']);
                        break;
                    }
                    if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                        echo json_encode(['success' => false, 'message' => 'Logo 2MB\'dan küçük olmalıdır.']);
                        break;
                    }

                    $yuklemeDizini = __DIR__ . '/../assets/uploads/takim-logo/';
                    if (!is_dir($yuklemeDizini)) {
                        mkdir($yuklemeDizini, 0755, true);
                    }

                    $yeniAd = 'takim-' . $id . '-' . uniqid('', true) . '.' . $uzanti;
                    if (!move_uploaded_file($_FILES['logo']['tmp_name'], $yuklemeDizini . $yeniAd)) {
                        echo json_encode(['success' => false, 'message' => 'Logo yüklenemedi.']);
                        break;
                    }

                    // Eski logoyu diskten temizle
                    if ($logoYolu && str_starts_with($logoYolu, '/admin/assets/uploads/takim-logo/')) {
                        $eski = __DIR__ . '/../assets/uploads/takim-logo/' . basename($logoYolu);
                        if (is_file($eski)) @unlink($eski);
                    }

                    $logoYolu = '/admin/assets/uploads/takim-logo/' . $yeniAd;
                }

                $kisaAd = trim($_POST['ad'] ?? '');

                $db->update('SporTakimlar', [
                    'SporTakimlar_Ad'       => $kisaAd === '' ? null : $kisaAd,
                    'SporTakimlar_LogoYolu' => $logoYolu,
                    'GuncelleyenKullanici'  => (int)$user['kullanici_id'],
                    'GuncellemeTarihi'      => date('Y-m-d H:i:s'),
                ], ['SporTakimlar_Id' => $id]);

                echo json_encode(['success' => true, 'message' => 'Takım güncellendi.']);
                break;
            }

            case 'kazi': {
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Güncelleme yetkiniz yok!']);
                    break;
                }

                set_time_limit(300);
                $r = FiksturHelper::senkron($db, (int)$user['kullanici_id']);

                echo json_encode([
                    'success' => $r['success'],
                    'message' => $r['message'],
                    'data'    => [
                        'eklenen'     => $r['eklenen'],
                        'guncellenen' => $r['guncellenen'],
                        'toplam'      => $r['toplam'],
                        'ligler'      => $r['ligler'],
                    ],
                ]);
                break;
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
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

    <style>
        .durum-badge {
            display: inline-block;
            padding: .25rem .6rem;
            border-radius: 1rem;
            font-size: .8rem;
            font-weight: 600;
            color: #fff;
            white-space: nowrap;
        }
        .durum-bitti    { background-color: #6c757d; }
        .durum-devam    { background-color: #dc3545; }
        .durum-bekliyor { background-color: #0d6efd; }

        .skor-hucre {
            font-weight: 700;
            font-size: 1rem;
            letter-spacing: .05rem;
            white-space: nowrap;
        }
        .skor-yok { color: #adb5bd; font-weight: 400; }

        .lig-rozet {
            display: inline-block;
            padding: .2rem .55rem;
            border-radius: .35rem;
            background-color: #e9ecef;
            font-size: .8rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .takim-ev  { text-align: right; }
        .takim-dep { text-align: left; }

        .takim-hucre {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            cursor: pointer;
            border-radius: .25rem;
            padding: .1rem .3rem;
        }
        .takim-hucre:hover { background-color: #e9ecef; }
        .takim-ev .takim-hucre  { flex-direction: row-reverse; }
        .takim-logo {
            width: 24px;
            height: 24px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .takim-logo-bos {
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background-color: #dee2e6;
            color: #6c757d;
            font-size: .7rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .logo-onizleme {
            width: 96px;
            height: 96px;
            object-fit: contain;
            border: 1px solid #dee2e6;
            border-radius: .375rem;
            background-color: #fff;
            padding: .25rem;
        }
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
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-calendar3"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Maç</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-secondary">
                            <span class="info-box-icon"><i class="bi bi-trophy"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Lig</span>
                                <span class="info-box-number" id="stat-lig">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check2-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Oynanan</span>
                                <span class="info-box-number" id="stat-oynanan">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-clock-history"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bekleyen</span>
                                <span class="info-box-number" id="stat-bekleyen">0</span>
                                <span class="info-box-text" id="stat-devam-metin"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body collapse" id="filterCard">
                        <form id="filterForm">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Takım Ara</label>
                                    <input type="text" class="form-control" name="search" id="filter_search" placeholder="Ev sahibi veya deplasman...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Lig</label>
                                    <select class="form-select" name="lig" id="filter_lig">
                                        <option value="">Tümü</option>
<?php if ($varsayilanLigKod !== ''): ?>
                                        <option value="<?= htmlspecialchars($varsayilanLigKod) ?>" selected><?= htmlspecialchars($varsayilanLigAd) ?></option>
<?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Hafta</label>
                                    <select class="form-select" name="hafta" id="filter_hafta">
                                        <option value="">Tümü</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Durum</label>
                                    <select class="form-select" name="durum" id="filter_durum">
                                        <option value="">Tümü</option>
                                        <option value="bekliyor">Oynanmadı</option>
                                        <option value="devam">Devam Ediyor</option>
                                        <option value="bitti">Tamamlandı</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tarih (Başlangıç)</label>
                                    <input type="date" class="form-control" id="filter_tarih_bas" value="<?= $varsayilanTarihBas ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tarih (Bitiş)</label>
                                    <input type="date" class="form-control" id="filter_tarih_bit">
                                </div>
                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                    <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Liste -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            Maç Listesi
                            <small class="text-muted ms-2" id="son-kazima-metin"></small>
                        </h3>
                        <div class="card-tools">
                            <?php if ($permissions['can_edit']): ?>
                            <button type="button" class="btn btn-primary btn-sm" id="btnKazi">
                                <i class="bi bi-arrow-repeat"></i> Şimdi Güncelle
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover w-100">
                            <thead>
                                <tr>
                                    <th>Lig</th>
                                    <th>Hafta</th>
                                    <th>Tarih</th>
                                    <th class="text-end">Ev Sahibi</th>
                                    <th class="text-center">Skor</th>
                                    <th>Deplasman</th>
                                    <th>Durum</th>
                                    <th>Son Kazıma</th>
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

<!-- Takım Modal -->
<div class="modal fade" id="takimModal" tabindex="-1" aria-labelledby="takimModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="takimModalLabel">Takım Bilgileri</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="takimForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" id="takim_id" name="id">
                    <input type="hidden" id="takim_logo_sil" name="logo_sil" value="">

                    <div class="row g-3">
                        <div class="col-md-4 text-center">
                            <img id="takim_logo_onizleme" class="logo-onizleme" src="" alt="Logo" style="display:none">
                            <div id="takim_logo_yok" class="logo-onizleme d-inline-flex align-items-center justify-content-center text-muted">
                                <i class="bi bi-image" style="font-size:2rem"></i>
                            </div>
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-outline-danger" id="btnLogoSil" style="display:none">
                                    <i class="bi bi-trash"></i> Logoyu Kaldır
                                </button>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label">Resmi Ad</label>
                                <input type="text" class="form-control" id="takim_resmi_ad" readonly>
                                <div class="form-text">Kaynaktan gelen ad. Eşleştirme anahtarı olduğu için değiştirilemez.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Görünen Ad</label>
                                <input type="text" class="form-control" id="takim_ad" name="ad" placeholder="Ör: Başakşehir">
                                <div class="form-text">Boş bırakılırsa listede resmi ad gösterilir.</div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">Logo</label>
                                <input type="file" class="form-control" id="takim_logo" name="logo" accept=".png,.jpg,.jpeg,.gif,.webp,.svg">
                                <div class="form-text">PNG, JPG, GIF, WEBP veya SVG · en fazla 2MB</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="alert alert-light border mb-0 py-2 small">
                                <i class="bi bi-info-circle"></i>
                                <span id="takim_bilgi"></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                    <?php if ($permissions['can_edit']): ?>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
    const permissions = {
        canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?>
    };

    let dataTable, takimModal;

    $(document).ready(function () {

        takimModal = new bootstrap.Modal(document.getElementById('takimModal'));

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            processing: true,
            serverSide: true,
            autoWidth: false,
            scrollX: true,
            dom: 'lrtip', // global arama kutusu gizli — filtre panelindeki "Takım Ara" kullanılır
            order: [[2, 'asc']], // en yakın maç üstte (varsayılan filtre bugünden itibaren)
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            ajax: {
                url: '', type: 'POST',
                data: function (d) {
                    d.action       = 'list';
                    d.search.value = $('#filter_search').val() || '';
                    d.f_lig        = $('#filter_lig').val() || '';
                    d.f_hafta      = $('#filter_hafta').val() || '';
                    d.f_durum      = $('#filter_durum').val() || '';
                    d.f_tarih_bas  = $('#filter_tarih_bas').val() || '';
                    d.f_tarih_bit  = $('#filter_tarih_bit').val() || '';
                    return d;
                },
                error: function () { showToast('Liste yüklenirken hata oluştu', 'error'); }
            },
            columns: [
                { data: 'SporFikstur_LigAdi',  render: d => `<span class="lig-rozet">${escapeHtml(d || '-')}</span>` },
                { data: 'SporFikstur_Hafta',   className: 'text-center', render: d => (d > 0 ? d + '. Hafta' : '-') },
                { data: null,                  render: (d, t, r) => tarihHucre(r) },
                { data: null, className: 'takim-ev',  render: (d, t, r) => takimHucre(r.SporFikstur_EvTakimId, r.EvKisaAd, r.SporFikstur_EvSahibi, r.EvLogo) },
                { data: null, className: 'text-center', orderable: false, render: (d, t, r) => skorHucre(r) },
                { data: null, className: 'takim-dep', render: (d, t, r) => takimHucre(r.SporFikstur_DeplasmanTakimId, r.DepKisaAd, r.SporFikstur_Deplasman, r.DepLogo) },
                { data: null, className: 'text-center', render: (d, t, r) => durumHucre(r) },
                { data: 'SonKazima', render: d => escapeHtml(d || '-') }
            ]
        });

        loadStats();
        loadFiltreSecenekleri();

        // Filtre select'lerini custom.js (.form-select) otomatik Select2 yapıyor — burada tekrar init etmiyoruz.

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            dataTable.ajax.reload();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filterForm')[0].reset();
            $('#filter_lig, #filter_hafta, #filter_durum').val('').trigger('change');
            $('#filter_tarih_bas, #filter_tarih_bit').val('');
            dataTable.ajax.reload();
            showToast('Filtreler temizlendi', 'info');
        });

        $('#btnKazi').on('click', kaziBaslat);

        // ── Takım modalı ──
        $('#takimForm').on('submit', function (e) {
            e.preventDefault();
            if (!permissions.canEdit) return;
            takimKaydet();
        });

        $('#btnLogoSil').on('click', function () {
            $('#takim_logo_sil').val('1');
            $('#takim_logo').val('');
            logoOnizleme(null);
            showToast('Kaydedince logo kaldırılacak', 'info');
        });

        // Seçilen dosyayı kaydetmeden önce göster
        $('#takim_logo').on('change', function () {
            const dosya = this.files && this.files[0];
            if (!dosya) return;
            $('#takim_logo_sil').val('');
            const okuyucu = new FileReader();
            okuyucu.onload = e => logoOnizleme(e.target.result);
            okuyucu.readAsDataURL(dosya);
        });
    });

    function takimHucre(takimId, kisaAd, resmiAd, logo) {
        const ad  = kisaAd || resmiAd || '-';
        const bas = (resmiAd || ad).trim().charAt(0).toUpperCase();

        const gorsel = logo
            ? `<img src="${escapeHtml(logo)}" class="takim-logo" alt="">`
            : `<span class="takim-logo-bos">${escapeHtml(bas)}</span>`;

        if (!takimId) {
            return `<span class="takim-hucre" style="cursor:default">${gorsel}<span>${escapeHtml(ad)}</span></span>`;
        }

        const baslik = kisaAd ? `${escapeHtml(resmiAd)} — düzenlemek için tıklayın` : 'Düzenlemek için tıklayın';
        return `<span class="takim-hucre" onclick="takimAc(${takimId})" title="${baslik}">`
             + `${gorsel}<span>${escapeHtml(ad)}</span></span>`;
    }

    function takimAc(id) {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'takim_get', id: id },
            dataType: 'json',
            success: function (r) {
                if (!r.success) { showToast(r.message || 'Takım bulunamadı', 'error'); return; }
                const d = r.data;

                $('#takim_id').val(d.SporTakimlar_Id);
                $('#takim_resmi_ad').val(d.SporTakimlar_ResmiAd || '');
                $('#takim_ad').val(d.SporTakimlar_Ad || '');
                $('#takim_logo').val('');
                $('#takim_logo_sil').val('');
                $('#takimModalLabel').text(d.SporTakimlar_ResmiAd || 'Takım Bilgileri');
                $('#takim_bilgi').text(
                    (d.SporTakimlar_LigKodu ? d.SporTakimlar_LigKodu + ' · ' : '') +
                    d.MacAdet + ' maç kaydı bu takıma bağlı.'
                );

                logoOnizleme(d.SporTakimlar_LogoYolu);
                takimModal.show();
            },
            error: function () { showToast('Takım bilgisi alınamadı', 'error'); }
        });
    }

    function logoOnizleme(yol) {
        if (yol) {
            $('#takim_logo_onizleme').attr('src', yol).show();
            $('#takim_logo_yok').hide();
            $('#btnLogoSil').toggle(permissions.canEdit);
        } else {
            $('#takim_logo_onizleme').attr('src', '').hide();
            $('#takim_logo_yok').show();
            $('#btnLogoSil').hide();
        }
    }

    function takimKaydet() {
        const formData = new FormData(document.getElementById('takimForm'));
        formData.append('action', 'takim_kaydet');

        $.ajax({
            url: '', method: 'POST',
            data: formData, processData: false, contentType: false, dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showToast(r.message, 'success');
                    takimModal.hide();
                    dataTable.ajax.reload(null, false);
                } else {
                    showToast(r.message, 'error');
                }
            },
            error: function () { showToast('Kayıt sırasında hata oluştu', 'error'); }
        });
    }

    function tarihHucre(row) {
        const tarih = row.MacTarihi;
        if (!tarih) return escapeHtml(row.SporFikstur_TarihMetin || '-');
        const [g, s] = tarih.split(' ');
        const [yil, ay, gun] = g.split('-');
        const saat = (s && s !== '00:00') ? ' ' + s : '';
        return `${gun}.${ay}.${yil}<span class="text-muted">${saat}</span>`;
    }

    function skorHucre(row) {
        const ev  = row.SporFikstur_EvGol;
        const dep = row.SporFikstur_DeplasmanGol;
        if (ev === null || dep === null) {
            return '<span class="skor-hucre skor-yok">- : -</span>';
        }
        return `<span class="skor-hucre">${ev} : ${dep}</span>`;
    }

    function durumHucre(row) {
        const kod = row.SporFikstur_DurumKodu || 'bekliyor';
        const ek  = row.SporFikstur_DurumMetin || '';
        if (kod === 'devam')   return `<span class="durum-badge durum-devam">${ek ? escapeHtml(ek) + "'" : 'Devam Ediyor'}</span>`;
        if (kod === 'bitti')   return `<span class="durum-badge durum-bitti">Tamamlandı</span>`;
        return `<span class="durum-badge durum-bekliyor">${escapeHtml(ek || 'Oynanmadı')}</span>`;
    }

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-lig').text(r.data.lig);
            $('#stat-oynanan').text(r.data.oynanan);
            $('#stat-bekleyen').text(r.data.bekleyen);
            $('#stat-devam-metin').text(r.data.devam > 0 ? r.data.devam + ' maç oynanıyor' : '');
            $('#son-kazima-metin').text(r.data.son_kazima ? 'Son güncelleme: ' + r.data.son_kazima : '');
        }, 'json');
    }

    function loadFiltreSecenekleri() {
        $.post('', { action: 'filtre_secenekleri' }, function (r) {
            if (!r.success) return;

            const $lig = $('#filter_lig');
            const ligSecili = $lig.val();
            if ($lig.hasClass('select2-hidden-accessible')) $lig.select2('close');
            $lig.html('<option value="">Tümü</option>');
            r.data.ligler.forEach(l => {
                $lig.append(`<option value="${escapeHtml(l.kod)}">${escapeHtml(l.ad || l.kod)}</option>`);
            });
            $lig.val(ligSecili || '').trigger('change');

            const $hafta = $('#filter_hafta');
            const haftaSecili = $hafta.val();
            if ($hafta.hasClass('select2-hidden-accessible')) $hafta.select2('close');
            $hafta.html('<option value="">Tümü</option>');
            r.data.haftalar.forEach(h => {
                $hafta.append(`<option value="${h}">${h}. Hafta</option>`);
            });
            $hafta.val(haftaSecili || '').trigger('change');
        }, 'json');
    }

    function kaziBaslat() {
        if (!permissions.canEdit) return;

        const $btn = $('#btnKazi');
        $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Güncelleniyor...');

        $.ajax({
            url: '', method: 'POST',
            data: { action: 'kazi' },
            dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showSuccess('Güncellendi!', r.message);
                    dataTable.ajax.reload(null, false);
                    loadStats();
                    loadFiltreSecenekleri();
                } else {
                    showError('Hata!', r.message);
                }
            },
            error: function () { showError('Bağlantı Hatası!', 'Kaynak servise ulaşılamadı.'); },
            complete: function () {
                $btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat"></i> Şimdi Güncelle');
            }
        });
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
</script>
</body>
</html>
