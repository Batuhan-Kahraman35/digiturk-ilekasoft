<?php
/**
 * Admin Panel - API Yeniden Deneme Kuralları
 *
 * api/index.php gateway'i, bir endpoint'e giden istekte kendi ürettiği alan
 * (şu an: bbkAddressCode) Digiturk tarafından reddedilirse alanı yeniden üretip
 * isteği tekrarlar. Hangi endpoint'te, hangi alan için, kaç deneme yapılacağı ve
 * hangi hata mesajlarında tekrar edileceği bu sayfadan yönetilir.
 *
 * Tablolar: APIYenidenDeneme (kural) + APIYenidenDenemeKalip (mesaj kalıpları)
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'API Yeniden Deneme Kuralları';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Gateway'in yeniden üretebildiği alanlar (api/index.php'deki destek durumu).
// Yeni alan eklendiğinde hem burada hem gateway'de karşılığı olmalıdır.
$destekliAlanlar = $db->fetchAll("
    SELECT DISTINCT APIYenidenDeneme_Alan AS alan FROM APIYenidenDeneme
");
$alanListesi = array_values(array_unique(array_merge(
    ['bbkAddressCode'],
    array_column($destekliAlanlar ?: [], 'alan')
)));

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'list':
                $search   = trim((string)($_POST['search'] ?? ''));
                $endpoint = $_POST['endpoint'] ?? '';
                $alan     = $_POST['alan'] ?? '';
                $status   = $_POST['status'] ?? '';

                $where  = ["1=1"];
                $params = [];

                if ($search !== '') {
                    $where[] = "(e.APIEndpointler_Endpoint LIKE ? OR e.APIEndpointler_Kategori LIKE ? OR t.APIYenidenDeneme_Aciklama LIKE ?)";
                    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
                }
                if ($endpoint !== '') { $where[] = "t.APIYenidenDeneme_EndpointId = ?"; $params[] = (int)$endpoint; }
                if ($alan     !== '') { $where[] = "t.APIYenidenDeneme_Alan = ?";       $params[] = $alan; }
                if ($status   !== '') { $where[] = "t.Durum = ?";                       $params[] = (int)$status; }

                $whereClause = implode(" AND ", $where);

                $list = $db->fetchAll("
                    SELECT
                        t.APIYenidenDeneme_Id,
                        t.APIYenidenDeneme_EndpointId,
                        t.APIYenidenDeneme_Alan,
                        t.APIYenidenDeneme_MaxDeneme,
                        t.APIYenidenDeneme_Aciklama,
                        t.Durum,
                        e.APIEndpointler_Kategori,
                        e.APIEndpointler_HttpMetod,
                        e.APIEndpointler_Endpoint,
                        (SELECT COUNT(*) FROM APIYenidenDenemeKalip k
                          WHERE k.APIYenidenDenemeKalip_YenidenDenemeId = t.APIYenidenDeneme_Id AND k.Durum = 1) AS AktifKalip,
                        (SELECT COUNT(*) FROM APIYenidenDenemeKalip k
                          WHERE k.APIYenidenDenemeKalip_YenidenDenemeId = t.APIYenidenDeneme_Id) AS ToplamKalip,
                        CONVERT(VARCHAR(19), t.GuncellemeTarihi, 120) AS GuncellemeTarihi,
                        k2.kullanici_ad + ' ' + k2.kullanici_soyad AS GuncelleyenAd
                    FROM APIYenidenDeneme t
                    LEFT JOIN APIEndpointler e ON t.APIYenidenDeneme_EndpointId = e.APIEndpointler_Id
                    LEFT JOIN kullanicilar k2  ON t.GuncelleyenKullanici = k2.kullanici_id
                    WHERE $whereClause
                    ORDER BY t.APIYenidenDeneme_EndpointId, t.APIYenidenDeneme_Alan
                ", $params);

                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get':
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("SELECT * FROM APIYenidenDeneme WHERE APIYenidenDeneme_Id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $row]);
                break;

            case 'save':
                $id = (int)($_POST['id'] ?? 0);

                if ($id > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']); break;
                }
                if ($id == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']); break;
                }

                $endpointId = (int)($_POST['endpoint_id'] ?? 0);
                $alan       = trim((string)($_POST['alan'] ?? ''));
                $maxDeneme  = (int)($_POST['max_deneme'] ?? 0);

                if ($endpointId <= 0) { echo json_encode(['success' => false, 'message' => 'Endpoint seçilmeli!']); break; }
                if ($alan === '')     { echo json_encode(['success' => false, 'message' => 'Alan adı zorunludur!']); break; }
                if ($maxDeneme < 1 || $maxDeneme > 10) {
                    echo json_encode(['success' => false, 'message' => 'Deneme sayısı 1 ile 10 arasında olmalı!']); break;
                }

                // Endpoint gerçekten var mı? (FK hatasını kullanıcıya ham göstermemek için)
                $ep = $db->fetchOne("SELECT APIEndpointler_Id FROM APIEndpointler WHERE APIEndpointler_Id = ?", [$endpointId]);
                if (!$ep) { echo json_encode(['success' => false, 'message' => 'Seçilen endpoint bulunamadı!']); break; }

                // Aynı endpoint + alan için ikinci kural olmamalı; gateway tek kural okur.
                $exists = $db->fetchOne("
                    SELECT APIYenidenDeneme_Id FROM APIYenidenDeneme
                    WHERE APIYenidenDeneme_EndpointId = ? AND APIYenidenDeneme_Alan = ? AND APIYenidenDeneme_Id <> ?
                ", [$endpointId, $alan, $id]);
                if ($exists) {
                    echo json_encode(['success' => false, 'message' => 'Bu endpoint ve alan için zaten bir kural var!']); break;
                }

                $data = [
                    'APIYenidenDeneme_EndpointId' => $endpointId,
                    'APIYenidenDeneme_Alan'       => $alan,
                    'APIYenidenDeneme_MaxDeneme'  => $maxDeneme,
                    'APIYenidenDeneme_Aciklama'   => trim((string)($_POST['aciklama'] ?? '')) !== ''
                                                     ? trim((string)$_POST['aciklama']) : null,
                    'Durum'                       => isset($_POST['durum']) ? 1 : 0,
                ];

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->update('APIYenidenDeneme', $data, ['APIYenidenDeneme_Id' => $id]);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kural güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['OlusturanKullanici']   = $user['kullanici_id'];
                    $data['OlusturmaTarihi']      = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->insert('APIYenidenDeneme', $data);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kural eklendi' : 'Ekleme hatası']);
                }
                break;

            case 'toggle_durum':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']); break;
                }
                $result = $db->update('APIYenidenDeneme', [
                    'Durum'                => (int)($_POST['durum'] ?? 0),
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['APIYenidenDeneme_Id' => (int)($_POST['id'] ?? 0)]);
                echo json_encode(['success' => (bool)$result]);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']); break;
                }
                $id = (int)($_POST['id'] ?? 0);
                // Kalıplar FK ile bağlı; önce onlar silinir.
                $db->query("DELETE FROM APIYenidenDenemeKalip WHERE APIYenidenDenemeKalip_YenidenDenemeId = ?", [$id]);
                $result = $db->delete('APIYenidenDeneme', ['APIYenidenDeneme_Id' => $id]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kural silindi' : 'Silme hatası']);
                break;

            // --- Kalıplar ---

            case 'kalip_list':
                $kuralId = (int)($_POST['kural_id'] ?? 0);
                $list = $db->fetchAll("
                    SELECT
                        k.APIYenidenDenemeKalip_Id,
                        k.APIYenidenDenemeKalip_Kalip,
                        k.APIYenidenDenemeKalip_Aciklama,
                        k.Durum,
                        CONVERT(VARCHAR(19), k.GuncellemeTarihi, 120) AS GuncellemeTarihi
                    FROM APIYenidenDenemeKalip k
                    WHERE k.APIYenidenDenemeKalip_YenidenDenemeId = ?
                    ORDER BY k.APIYenidenDenemeKalip_Id
                ", [$kuralId]);
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'kalip_save':
                $id      = (int)($_POST['id'] ?? 0);
                $kuralId = (int)($_POST['kural_id'] ?? 0);

                if ($id > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']); break;
                }
                if ($id == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']); break;
                }

                $kalip = trim((string)($_POST['kalip'] ?? ''));
                if ($kuralId <= 0) { echo json_encode(['success' => false, 'message' => 'Kural seçilmedi!']); break; }
                if ($kalip === '') { echo json_encode(['success' => false, 'message' => 'Kalıp metni zorunludur!']); break; }
                if (mb_strlen($kalip) < 3) {
                    echo json_encode(['success' => false, 'message' => 'Kalıp en az 3 karakter olmalı; çok kısa metin ilgisiz hataları da eşleştirir!']); break;
                }

                // Eşleşme mb_stripos ile yapılır: joker karakter (% _ *) anlamsızdır, uyar.
                if (preg_match('/[%*]/', $kalip)) {
                    echo json_encode(['success' => false, 'message' => 'Kalıp düz metin olmalı; % veya * gibi joker karakter kullanılmaz!']); break;
                }

                $data = [
                    'APIYenidenDenemeKalip_YenidenDenemeId' => $kuralId,
                    'APIYenidenDenemeKalip_Kalip'           => $kalip,
                    'APIYenidenDenemeKalip_Aciklama'        => trim((string)($_POST['kalip_aciklama'] ?? '')) !== ''
                                                               ? trim((string)$_POST['kalip_aciklama']) : null,
                    'Durum'                                 => isset($_POST['kalip_durum']) ? 1 : 0,
                ];

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->update('APIYenidenDenemeKalip', $data, ['APIYenidenDenemeKalip_Id' => $id]);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kalıp güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['OlusturanKullanici']   = $user['kullanici_id'];
                    $data['OlusturmaTarihi']      = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->insert('APIYenidenDenemeKalip', $data);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kalıp eklendi' : 'Ekleme hatası']);
                }
                break;

            case 'kalip_toggle_durum':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']); break;
                }
                $result = $db->update('APIYenidenDenemeKalip', [
                    'Durum'                => (int)($_POST['durum'] ?? 0),
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['APIYenidenDenemeKalip_Id' => (int)($_POST['id'] ?? 0)]);
                echo json_encode(['success' => (bool)$result]);
                break;

            case 'kalip_delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']); break;
                }
                $result = $db->delete('APIYenidenDenemeKalip', ['APIYenidenDenemeKalip_Id' => (int)($_POST['id'] ?? 0)]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kalıp silindi' : 'Silme hatası']);
                break;

            // Kalıp denemesi: bir hata mesajı bu kuralla tekrar tetikler miydi?
            case 'kalip_test':
                $kuralId = (int)($_POST['kural_id'] ?? 0);
                $mesaj   = trim((string)($_POST['mesaj'] ?? ''));
                if ($mesaj === '') { echo json_encode(['success' => false, 'message' => 'Test mesajı boş olamaz!']); break; }

                $kaliplar = $db->fetchAll("
                    SELECT APIYenidenDenemeKalip_Kalip FROM APIYenidenDenemeKalip
                    WHERE APIYenidenDenemeKalip_YenidenDenemeId = ? AND Durum = 1
                ", [$kuralId]);

                $eslesen = null;
                foreach (($kaliplar ?: []) as $k) {
                    $p = trim((string)$k['APIYenidenDenemeKalip_Kalip']);
                    if ($p !== '' && mb_stripos($mesaj, $p, 0, 'UTF-8') !== false) { $eslesen = $p; break; }
                }
                echo json_encode(['success' => true, 'data' => ['eslesti' => $eslesen !== null, 'kalip' => $eslesen]]);
                break;

            case 'endpoint_list':
                $rows = $db->fetchAll("
                    SELECT APIEndpointler_Id, APIEndpointler_Kategori, APIEndpointler_HttpMetod, APIEndpointler_Endpoint
                    FROM APIEndpointler
                    WHERE Durum = 1
                    ORDER BY APIEndpointler_Id
                ");
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            case 'stats':
                $stats = [
                    'toplam'      => $db->fetchOne("SELECT COUNT(*) as c FROM APIYenidenDeneme")['c'] ?? 0,
                    'aktif'       => $db->fetchOne("SELECT COUNT(*) as c FROM APIYenidenDeneme WHERE Durum = 1")['c'] ?? 0,
                    'kalip'       => $db->fetchOne("SELECT COUNT(*) as c FROM APIYenidenDenemeKalip WHERE Durum = 1")['c'] ?? 0,
                    // Son 30 günde gerçekten tekrar edilen sipariş sayısı (gateway BasvuruLog'a yazar)
                    'son30'       => $db->fetchOne("
                        SELECT COUNT(*) as c FROM BasvuruLog
                        WHERE BasvuruLog_Islem = 'API_SIPARIS'
                          AND BasvuruLog_Aciklama LIKE '%yeniden denendi%'
                          AND OlusturmaTarihi >= DATEADD(DAY, -30, GETDATE())
                    ")['c'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

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
        .status-badge    { padding: .25rem .5rem; border-radius: .25rem; font-size: .875rem; }
        .status-active   { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .alan-badge {
            display: inline-block; padding: .2rem .55rem; border-radius: .25rem;
            background: #e7f1ff; color: #0a58ca; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .8rem; font-weight: 600;
        }
        .endpoint-path {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .82rem; word-break: break-all;
        }
        .kalip-metin {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .82rem; word-break: break-word;
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

                <div class="alert alert-info d-flex align-items-start gap-2">
                    <i class="bi bi-info-circle-fill mt-1"></i>
                    <div>
                        Gateway (<code>/api/...</code>), bir istekte <strong>kendi ürettiği</strong> alan Digiturk tarafından
                        reddedilirse alanı yeniden üretip isteği tekrarlar. Tekrar yalnız yanıttaki
                        <code>responseMessage</code> aşağıdaki kalıplardan biriyle eşleştiğinde olur.
                        Çağıran taraf alanı dolu gönderdiyse değere dokunulmaz.
                    </div>
                </div>

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-arrow-repeat"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Kural</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Kural</span>
                                <span class="info-box-number" id="stat-aktif">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-braces"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Kalıp</span>
                                <span class="info-box-number" id="stat-kalip">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-secondary">
                            <span class="info-box-icon"><i class="bi bi-clock-history"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Son 30 Gün Tekrar</span>
                                <span class="info-box-number" id="stat-son30">0</span>
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
                                    <label class="form-label">Ara</label>
                                    <input type="text" class="form-control" name="search" id="filter_search" placeholder="Endpoint, kategori, açıklama...">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Endpoint</label>
                                    <select class="form-select" name="endpoint" id="filter_endpoint">
                                        <option value="">Tümü</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Alan</label>
                                    <select class="form-select" name="alan" id="filter_alan">
                                        <option value="">Tümü</option>
                                        <?php foreach ($alanListesi as $a): ?>
                                        <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Durum</label>
                                    <select class="form-select" name="status" id="filter_status">
                                        <option value="">Tümü</option>
                                        <option value="1">Aktif</option>
                                        <option value="0">Pasif</option>
                                    </select>
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
                        <h3 class="card-title">Yeniden Deneme Kuralları</h3>
                        <div class="card-tools">
                            <?php if ($permissions['can_add']): ?>
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#kayitModal" onclick="resetForm()">
                                <i class="bi bi-plus-circle"></i> Yeni Kural
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Endpoint</th>
                                    <th>Alan</th>
                                    <th style="width:110px">Max Deneme</th>
                                    <th style="width:110px">Kalıp</th>
                                    <th style="width:90px">Durum</th>
                                    <th>Son Güncelleme</th>
                                    <th style="width:140px">İşlemler</th>
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
<div class="modal fade" id="kayitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kayitModalLabel">Yeni Kural Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kayitForm">
                <div class="modal-body">
                    <input type="hidden" id="rec_id" name="id">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Endpoint <span class="text-danger">*</span></label>
                            <select class="form-select" id="endpoint_id" name="endpoint_id" required style="width:100%"></select>
                            <div class="form-text">Kural yalnız bu endpoint'e giden isteklerde çalışır.</div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Alan <span class="text-danger">*</span></label>
                            <select class="form-select" id="alan" name="alan" required style="width:100%">
                                <?php foreach ($alanListesi as $a): ?>
                                <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Gateway'in yeniden üretebildiği alan. Yeni alan için gateway tarafında da karşılığı olmalıdır.</div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Maksimum Deneme <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="max_deneme" name="max_deneme" min="1" max="10" value="3" required>
                            <div class="form-text">İlk gönderim dahil toplam deneme sayısı. Her deneme isteği uzatır; 3 önerilir.</div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" id="aciklama" name="aciklama" rows="2" placeholder="Kuralın ne işe yaradığı..."></textarea>
                        </div>
                        <div class="col-md-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="durum" name="durum" value="1" checked>
                                <label class="form-check-label" for="durum">Aktif</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Kalıplar Modal -->
<div class="modal fade" id="kalipModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-braces"></i> Hata Mesajı Kalıpları</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-muted mb-3" id="kalipKuralBilgi"></div>

                <form id="kalipForm" class="border rounded p-3 mb-3 bg-body-tertiary">
                    <input type="hidden" id="kalip_id" name="id">
                    <input type="hidden" id="kalip_kural_id" name="kural_id">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Kalıp Metni <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="kalip" name="kalip" required
                                   placeholder="Ör: aktif Uydu başvurusu">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Açıklama</label>
                            <input type="text" class="form-control" id="kalip_aciklama" name="kalip_aciklama"
                                   placeholder="Bu hata ne anlama geliyor?">
                        </div>
                        <div class="col-md-12 d-flex align-items-center justify-content-between">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="kalip_durum" name="kalip_durum" value="1" checked>
                                <label class="form-check-label" for="kalip_durum">Aktif</label>
                            </div>
                            <div>
                                <button type="button" class="btn btn-secondary btn-sm" id="kalipIptal" style="display:none">
                                    <i class="bi bi-x-circle"></i> Vazgeç
                                </button>
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-plus-circle"></i> <span id="kalipKaydetMetin">Kalıp Ekle</span>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-text">
                                Eşleşme düz metin aramasıdır (büyük/küçük harf duyarsız). Joker karakter (<code>%</code>, <code>*</code>)
                                kullanılmaz; mesajın ayırt edici bir parçasını yazmak yeterlidir.
                            </div>
                        </div>
                    </div>
                </form>

                <table class="table table-sm table-bordered table-hover align-middle mb-3">
                    <thead>
                        <tr>
                            <th>Kalıp</th>
                            <th>Açıklama</th>
                            <th style="width:80px">Durum</th>
                            <th style="width:100px">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody id="kalipTbody">
                        <tr><td colspan="4" class="text-center text-muted">Yükleniyor...</td></tr>
                    </tbody>
                </table>

                <div class="border rounded p-3">
                    <label class="form-label mb-1"><i class="bi bi-magic"></i> Kalıp Denemesi</label>
                    <div class="form-text mb-2">Digiturk'ten dönen bir hata mesajını yapıştır, bu kuralın tekrar tetikleyip tetiklemeyeceğini gösterir.</div>
                    <div class="input-group">
                        <input type="text" class="form-control" id="testMesaj" placeholder="Ör: Geçersiz GeoLocationId değeri:0">
                        <button class="btn btn-outline-primary" type="button" id="testBtn"><i class="bi bi-play"></i> Dene</button>
                    </div>
                    <div id="testSonuc" class="mt-2"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
            </div>
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
        canAdd:    <?= $permissions['can_add']    ? 'true' : 'false' ?>,
        canEdit:   <?= $permissions['can_edit']   ? 'true' : 'false' ?>,
        canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
    };

    let kayitModal, kalipModal, dataTable, currentFilters = {}, aktifKural = null;

    $(document).ready(function () {
        kayitModal = new bootstrap.Modal(document.getElementById('kayitModal'));
        kalipModal = new bootstrap.Modal(document.getElementById('kalipModal'));

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc']],
            columnDefs: [{ orderable: false, targets: [6] }],
            pageLength: 25,
            scrollX: true,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        });

        loadEndpoints();
        loadStats();
        loadList();

        // Filtre select'lerini custom.js (.form-select) otomatik Select2 yapıyor — tekrar init edilmiyor.
        // Modal içindekiler çift init'e karşı destroy edilip dropdownParent ile yeniden kuruluyor.
        ['#endpoint_id', '#alan'].forEach(function (sel) {
            if ($(sel).hasClass('select2-hidden-accessible')) $(sel).select2('destroy');
            $(sel).select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#kayitModal') });
        });

        $('#kayitForm').on('submit', function (e) { e.preventDefault(); saveRecord(); });
        $('#kalipForm').on('submit', function (e) { e.preventDefault(); saveKalip(); });
        $('#kalipIptal').on('click', resetKalipForm);
        $('#testBtn').on('click', testKalip);

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            currentFilters = {
                search:   $('#filter_search').val(),
                endpoint: $('#filter_endpoint').val(),
                alan:     $('#filter_alan').val(),
                status:   $('#filter_status').val()
            };
            Object.keys(currentFilters).forEach(k => { if (!currentFilters[k] && currentFilters[k] !== '0') delete currentFilters[k]; });
            loadList();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filterForm')[0].reset();
            $('#filter_endpoint, #filter_alan, #filter_status').val('').trigger('change');
            currentFilters = {};
            loadList();
            showToast('Filtreler temizlendi', 'info');
        });

        document.getElementById('kayitModal').addEventListener('hidden.bs.modal', resetForm);
    });

    function endpointEtiketi(row) {
        const url  = row.APIEndpointler_Endpoint || '';
        const pos  = url.indexOf('/api/');
        const path = pos !== -1 ? url.substring(pos + 1) : url;
        const kat  = row.APIEndpointler_Kategori ? row.APIEndpointler_Kategori + ' — ' : '';
        return kat + path;
    }

    function loadEndpoints() {
        $.post('', { action: 'endpoint_list' }, function (r) {
            if (!r.success) return;
            r.data.forEach(function (row) {
                const etiket = escapeHtml(endpointEtiketi(row)) + ' (#' + row.APIEndpointler_Id + ')';
                $('#filter_endpoint').append(`<option value="${row.APIEndpointler_Id}">${etiket}</option>`);
                $('#endpoint_id').append(`<option value="${row.APIEndpointler_Id}">${etiket}</option>`);
            });
            $('#filter_endpoint, #endpoint_id').trigger('change');
        }, 'json');
    }

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-aktif').text(r.data.aktif);
            $('#stat-kalip').text(r.data.kalip);
            $('#stat-son30').text(r.data.son30);
        }, 'json');
    }

    function loadList() {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'list', ...currentFilters },
            dataType: 'json',
            success: function (r) {
                if (r.success) renderTable(r.data);
                else showToast('Liste yüklenirken hata oluştu', 'error');
            },
            error: function () { showToast('Sunucu hatası oluştu', 'error'); }
        });
    }

    function renderTable(rows) {
        dataTable.clear();
        rows.forEach(row => {
            const id  = row.APIYenidenDeneme_Id;
            const url = row.APIEndpointler_Endpoint || '';
            const pos = url.indexOf('/api/');
            const yol = pos !== -1 ? url.substring(pos + 1) : url;

            const endpoint = `<div><strong>${escapeHtml(row.APIEndpointler_Kategori || '-')}</strong></div>` +
                             `<div class="endpoint-path text-muted">${escapeHtml(yol || '-')}</div>`;

            const alan = `<span class="alan-badge">${escapeHtml(row.APIYenidenDeneme_Alan || '-')}</span>`;

            const aktifKalip = parseInt(row.AktifKalip || 0, 10);
            const kalip = aktifKalip > 0
                ? `<span class="badge bg-success">${aktifKalip} aktif</span>`
                : `<span class="badge bg-danger" title="Aktif kalıp yoksa kural hiç çalışmaz">kalıp yok</span>`;

            const durum = row.Durum == 1
                ? `<span class="status-badge status-active" style="cursor:pointer" onclick="toggleDurum(${id}, 0)" title="Pasife al">Aktif</span>`
                : `<span class="status-badge status-inactive" style="cursor:pointer" onclick="toggleDurum(${id}, 1)" title="Aktife al">Pasif</span>`;

            const guncelleme = escapeHtml(row.GuncellemeTarihi || '-') +
                (row.GuncelleyenAd ? `<div class="text-muted small">${escapeHtml(row.GuncelleyenAd)}</div>` : '');

            let islemler = `<button class="btn btn-sm btn-info me-1" onclick="openKaliplar(${id})" title="Kalıplar"><i class="bi bi-braces"></i></button>`;
            if (permissions.canEdit)
                islemler += `<button class="btn btn-sm btn-warning me-1" onclick="editRecord(${id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
            if (permissions.canDelete)
                islemler += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${id})" title="Sil"><i class="bi bi-trash"></i></button>`;

            dataTable.row.add([
                endpoint,
                alan,
                `<span class="badge bg-primary">${parseInt(row.APIYenidenDeneme_MaxDeneme || 1, 10)}</span>`,
                kalip,
                durum,
                guncelleme,
                islemler
            ]);
        });
        dataTable.draw();
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function resetForm() {
        document.getElementById('kayitForm').reset();
        document.getElementById('rec_id').value = '';
        document.getElementById('kayitModalLabel').textContent = 'Yeni Kural Ekle';
        document.getElementById('durum').checked = true;
        document.getElementById('max_deneme').value = 3;
        $('#endpoint_id, #alan').val('').trigger('change');
    }

    function saveRecord() {
        const formData = new FormData(document.getElementById('kayitForm'));
        formData.append('action', 'save');
        $.ajax({
            url: '', method: 'POST', data: formData,
            processData: false, contentType: false, dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showToast(r.message, 'success');
                    kayitModal.hide();
                    loadList(); loadStats();
                } else { showToast(r.message, 'error'); }
            },
            error: function () { showToast('Kayıt sırasında hata oluştu', 'error'); }
        });
    }

    function editRecord(id) {
        $.post('', { action: 'get', id: id }, function (r) {
            if (!r.success || !r.data) { showToast('Kayıt bulunamadı', 'error'); return; }
            const d = r.data;
            document.getElementById('rec_id').value     = d.APIYenidenDeneme_Id;
            document.getElementById('max_deneme').value = d.APIYenidenDeneme_MaxDeneme;
            document.getElementById('aciklama').value   = d.APIYenidenDeneme_Aciklama || '';
            document.getElementById('durum').checked    = d.Durum == 1;
            $('#endpoint_id').val(String(d.APIYenidenDeneme_EndpointId)).trigger('change');
            $('#alan').val(d.APIYenidenDeneme_Alan).trigger('change');
            document.getElementById('kayitModalLabel').textContent = 'Kural Düzenle';
            kayitModal.show();
        }, 'json');
    }

    function toggleDurum(id, yeniDurum) {
        if (!permissions.canEdit) return;
        $.post('', { action: 'toggle_durum', id: id, durum: yeniDurum }, function (r) {
            if (r.success) {
                showToast(yeniDurum == 1 ? 'Aktif edildi' : 'Pasif edildi', 'success');
                loadList(); loadStats();
            } else { showToast('Durum değiştirilemedi', 'error'); }
        }, 'json');
    }

    function deleteRecord(id) {
        confirmAction(
            'Bu kuralı silmek istediğinize emin misiniz?',
            'Kurala bağlı kalıplar da silinir, işlem geri alınamaz!',
            function () {
                $.post('', { action: 'delete', id: id }, function (r) {
                    if (r.success) { showSuccess('Silindi!', r.message); loadList(); loadStats(); }
                    else { showError('Hata!', r.message); }
                }, 'json');
            }
        );
    }

    // --- Kalıplar ---

    function openKaliplar(kuralId) {
        aktifKural = kuralId;
        document.getElementById('kalip_kural_id').value = kuralId;
        resetKalipForm();
        $('#testMesaj').val('');
        $('#testSonuc').html('');
        $.post('', { action: 'get', id: kuralId }, function (r) {
            const d = r.data || {};
            $('#kalipKuralBilgi').html(
                'Kural: <span class="alan-badge">' + escapeHtml(d.APIYenidenDeneme_Alan || '') + '</span> · ' +
                'En fazla <strong>' + parseInt(d.APIYenidenDeneme_MaxDeneme || 1, 10) + '</strong> deneme'
            );
        }, 'json');
        loadKaliplar();
        kalipModal.show();
    }

    function loadKaliplar() {
        $.post('', { action: 'kalip_list', kural_id: aktifKural }, function (r) {
            const tbody = $('#kalipTbody');
            tbody.empty();
            if (!r.success || !r.data.length) {
                tbody.append('<tr><td colspan="4" class="text-center text-muted">Kalıp yok — kural bu haliyle hiç tekrar etmez.</td></tr>');
                return;
            }
            r.data.forEach(function (k) {
                const id = k.APIYenidenDenemeKalip_Id;
                const durum = k.Durum == 1
                    ? `<span class="status-badge status-active" style="cursor:pointer" onclick="toggleKalipDurum(${id}, 0)">Aktif</span>`
                    : `<span class="status-badge status-inactive" style="cursor:pointer" onclick="toggleKalipDurum(${id}, 1)">Pasif</span>`;
                let islem = '';
                if (permissions.canEdit)
                    islem += `<button class="btn btn-sm btn-warning me-1" onclick="editKalip(${id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
                if (permissions.canDelete)
                    islem += `<button class="btn btn-sm btn-danger" onclick="deleteKalip(${id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                if (!islem) islem = '<span class="text-muted">-</span>';

                tbody.append(
                    '<tr data-kalip="' + escapeHtml(k.APIYenidenDenemeKalip_Kalip) + '"' +
                        ' data-aciklama="' + escapeHtml(k.APIYenidenDenemeKalip_Aciklama || '') + '"' +
                        ' data-durum="' + k.Durum + '">' +
                    '<td class="kalip-metin">' + escapeHtml(k.APIYenidenDenemeKalip_Kalip) + '</td>' +
                    '<td>' + escapeHtml(k.APIYenidenDenemeKalip_Aciklama || '-') + '</td>' +
                    '<td>' + durum + '</td>' +
                    '<td>' + islem + '</td></tr>'
                );
            });
        }, 'json');
    }

    function resetKalipForm() {
        document.getElementById('kalip_id').value = '';
        document.getElementById('kalip').value = '';
        document.getElementById('kalip_aciklama').value = '';
        document.getElementById('kalip_durum').checked = true;
        document.getElementById('kalipKaydetMetin').textContent = 'Kalıp Ekle';
        $('#kalipIptal').hide();
    }

    function editKalip(id) {
        const tr = $('#kalipTbody').find('button[onclick="editKalip(' + id + ')"]').closest('tr');
        document.getElementById('kalip_id').value = id;
        document.getElementById('kalip').value = tr.data('kalip');
        document.getElementById('kalip_aciklama').value = tr.data('aciklama');
        document.getElementById('kalip_durum').checked = String(tr.data('durum')) === '1';
        document.getElementById('kalipKaydetMetin').textContent = 'Güncelle';
        $('#kalipIptal').show();
        document.getElementById('kalip').focus();
    }

    function saveKalip() {
        const formData = new FormData(document.getElementById('kalipForm'));
        formData.append('action', 'kalip_save');
        $.ajax({
            url: '', method: 'POST', data: formData,
            processData: false, contentType: false, dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showToast(r.message, 'success');
                    resetKalipForm();
                    loadKaliplar(); loadList(); loadStats();
                } else { showToast(r.message, 'error'); }
            },
            error: function () { showToast('Kayıt sırasında hata oluştu', 'error'); }
        });
    }

    function toggleKalipDurum(id, yeniDurum) {
        if (!permissions.canEdit) return;
        $.post('', { action: 'kalip_toggle_durum', id: id, durum: yeniDurum }, function (r) {
            if (r.success) { loadKaliplar(); loadList(); loadStats(); }
            else { showToast('Durum değiştirilemedi', 'error'); }
        }, 'json');
    }

    function deleteKalip(id) {
        confirmAction('Bu kalıbı silmek istediğinize emin misiniz?', 'Bu işlem geri alınamaz!', function () {
            $.post('', { action: 'kalip_delete', id: id }, function (r) {
                if (r.success) { showToast(r.message, 'success'); loadKaliplar(); loadList(); loadStats(); }
                else { showError('Hata!', r.message); }
            }, 'json');
        });
    }

    function testKalip() {
        const mesaj = $('#testMesaj').val();
        if (!mesaj.trim()) { showToast('Test mesajı yazın', 'warning'); return; }
        $.post('', { action: 'kalip_test', kural_id: aktifKural, mesaj: mesaj }, function (r) {
            if (!r.success) { showToast(r.message, 'error'); return; }
            $('#testSonuc').html(r.data.eslesti
                ? '<div class="alert alert-success mb-0 py-2"><i class="bi bi-check-circle"></i> Eşleşti — ' +
                  '"<span class="kalip-metin">' + escapeHtml(r.data.kalip) + '</span>" kalıbı yakaladı, istek tekrarlanırdı.</div>'
                : '<div class="alert alert-warning mb-0 py-2"><i class="bi bi-x-circle"></i> Eşleşme yok — bu mesajda tekrar yapılmaz.</div>');
        }, 'json');
    }
</script>
</body>
</html>
