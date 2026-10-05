<?php
/**
 * Admin Panel - Şehir ve KÖİ Yönetimi
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Şehir ve KÖİ Yönetimi';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

const API_ENDPOINT_ID   = 2;
const TOKEN_PERSONEL_ID = 1;

$tokenPersonel = $db->fetchOne("
    SELECT
        p.DigiturkAltBayiPersonel_Id,
        p.DigiturkAltBayiPersonel_AdSoyad,
        p.DigiturkAltBayiPersonel_KullaniciAdi,
        p.DigiturkAltBayiPersonel_TokenSuresi,
        p.DigiturkAltBayiPersonel_TokenDurum,
        n.DigiturkAnaBayiler_BayiKodu
    FROM DigiturkAltBayiPersonel p
    LEFT JOIN DigiturkAltBayiler  a ON p.DigiturkAltBayiPersonel_AltBayiId = a.DigiturkAltBayiler_Id
    LEFT JOIN DigiturkAnaBayiler  n ON a.DigiturkAltBayiler_AnaBayiId      = n.DigiturkAnaBayiler_Id
    WHERE p.DigiturkAltBayiPersonel_Id = ?
", [TOKEN_PERSONEL_ID]);

// Token al: Digiturk Login bayi bazlı günlük 10 istekle sınırlı olduğu için
// burada login ATILMAZ. Token Güncelle görevinin kaydettiği geçerli token kullanılır.
function getApiToken($db) {
    $per = $db->fetchOne("
        SELECT DigiturkAltBayiPersonel_Token
        FROM DigiturkAltBayiPersonel
        WHERE DigiturkAltBayiPersonel_Id = ?
          AND Durum = 1
          AND DigiturkAltBayiPersonel_TokenDurum = 1
          AND DigiturkAltBayiPersonel_Token IS NOT NULL
          AND DigiturkAltBayiPersonel_TokenSuresi > GETDATE()
    ", [TOKEN_PERSONEL_ID]);

    return $per['DigiturkAltBayiPersonel_Token'] ?? null;
}

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'list':
                $search   = $_POST['search'] ?? '';
                $status   = $_POST['status'] ?? '';
                $koi      = $_POST['koi'] ?? '';
                $superKoi = $_POST['super_koi'] ?? '';

                $where  = ['1=1'];
                $params = [];

                if ($search) {
                    $where[]  = "(s.APISehirler_Ad LIKE ? OR CAST(s.APISehirler_Kod AS NVARCHAR) LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($status !== '') {
                    $where[]  = "s.Durum = ?";
                    $params[] = $status;
                }
                if ($koi !== '') {
                    $where[]  = "s.APISehirler_KOI = ?";
                    $params[] = $koi;
                }
                if ($superKoi !== '') {
                    $where[]  = "s.APISehirler_SuperKOI = ?";
                    $params[] = $superKoi;
                }

                $whereClause = implode(' AND ', $where);

                $list = $db->fetchAll("
                    SELECT
                        s.APISehirler_Id,
                        s.APISehirler_Kod,
                        s.APISehirler_Ad,
                        s.APISehirler_KOI,
                        s.APISehirler_SuperKOI,
                        s.Durum,
                        CONVERT(VARCHAR(19), s.GuncellemeTarihi, 120) as GuncellemeTarihi,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as GuncelleyenAd
                    FROM APISehirler s
                    LEFT JOIN kullanicilar k ON s.GuncelleyenKullanici = k.kullanici_id
                    WHERE $whereClause
                    ORDER BY s.APISehirler_Ad
                ", $params);

                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get':
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("SELECT * FROM APISehirler WHERE APISehirler_Id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $row]);
                break;

            case 'save':
                $id = (int)($_POST['id'] ?? 0);

                if ($id > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($id == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }

                $data = [
                    'APISehirler_Kod'      => (int)($_POST['sehir_kod'] ?? 0),
                    'APISehirler_Ad'       => strtoupper(trim($_POST['sehir_ad'] ?? '')),
                    'APISehirler_KOI'      => isset($_POST['koi'])       ? 1 : 0,
                    'APISehirler_SuperKOI' => isset($_POST['super_koi']) ? 1 : 0,
                    'Durum'                => isset($_POST['durum'])     ? 1 : 0,
                ];

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->update('APISehirler', $data, ['APISehirler_Id' => $id]);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['OlusturanKullanici']   = $user['kullanici_id'];
                    $data['OlusturmaTarihi']       = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->insert('APISehirler', $data);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt eklendi' : 'Ekleme hatası']);
                }
                break;

            case 'toggle_durum':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                $id    = (int)($_POST['id'] ?? 0);
                $durum = (int)($_POST['durum'] ?? 0);
                $result = $db->update('APISehirler', [
                    'Durum'                => $durum,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['APISehirler_Id' => $id]);
                echo json_encode(['success' => (bool)$result]);
                break;

            case 'excel_import':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }

                if (empty($_FILES['excel_file']['tmp_name'])) {
                    echo json_encode(['success' => false, 'message' => 'Dosya yüklenmedi!']);
                    break;
                }

                $tmpFile = $_FILES['excel_file']['tmp_name'];
                $ext     = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));

                if ($ext !== 'xlsx') {
                    echo json_encode(['success' => false, 'message' => 'Sadece .xlsx dosyası kabul edilir!']);
                    break;
                }

                // .xlsx'i ZipArchive ile aç
                $zip = new ZipArchive();
                if ($zip->open($tmpFile) !== true) {
                    echo json_encode(['success' => false, 'message' => 'Excel dosyası açılamadı!']);
                    break;
                }

                // Shared strings (string hücre değerleri burada)
                $sharedStrings = [];
                $ssXml = $zip->getFromName('xl/sharedStrings.xml');
                if ($ssXml) {
                    $ss = simplexml_load_string($ssXml);
                    foreach ($ss->si as $si) {
                        // t veya r/t içinden string değer al
                        if (isset($si->t)) {
                            $sharedStrings[] = (string)$si->t;
                        } else {
                            $text = '';
                            foreach ($si->r as $r) {
                                $text .= (string)$r->t;
                            }
                            $sharedStrings[] = $text;
                        }
                    }
                }

                // Sheet1 verisi
                $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
                $zip->close();

                if (!$sheetXml) {
                    echo json_encode(['success' => false, 'message' => 'Excel sheet verisi okunamadı!']);
                    break;
                }

                $sheet = simplexml_load_string($sheetXml);
                $rows  = [];

                foreach ($sheet->sheetData->row as $row) {
                    $rowData = [];
                    foreach ($row->c as $cell) {
                        // Sütun harfini al (A, B, C...)
                        preg_match('/^([A-Z]+)/', (string)$cell['r'], $m);
                        $col = $m[1] ?? '';

                        if (!in_array($col, ['A', 'B'])) continue;

                        $type = (string)$cell['t'];
                        $val  = (string)($cell->v ?? '');

                        if ($type === 's') {
                            $val = $sharedStrings[(int)$val] ?? '';
                        }

                        $rowData[$col] = trim($val);
                    }
                    if (!empty($rowData)) {
                        $rows[] = $rowData;
                    }
                }

                if (empty($rows)) {
                    echo json_encode(['success' => false, 'message' => 'Excel dosyasında veri bulunamadı!']);
                    break;
                }

                // Sıfırlama seçeneği
                $sifirla = ($_POST['sifirla'] ?? '0') === '1';
                if ($sifirla) {
                    $db->update('APISehirler',
                        ['APISehirler_KOI' => 0, 'APISehirler_SuperKOI' => 0,
                         'GuncelleyenKullanici' => $user['kullanici_id'], 'GuncellemeTarihi' => date('Y-m-d H:i:s')],
                        ['Durum' => 1]
                    );
                }

                $guncellendi = 0;
                $bulunamadi  = [];

                // Normalize helper
                $normalize = fn($s) => mb_strtoupper(trim(
                    str_replace(['İ','ı','Ğ','ğ','Ş','ş','Ü','ü','Ö','ö','Ç','ç'],
                                ['I','I','G','G','S','S','U','U','O','O','C','C'], $s)
                ), 'UTF-8');

                // Tüm şehirleri hafızaya al
                $sehirler = $db->fetchAll("SELECT APISehirler_Id, APISehirler_Ad FROM APISehirler");
                $sehirMap = [];
                foreach ($sehirler as $s) {
                    $sehirMap[$normalize($s['APISehirler_Ad'])] = $s['APISehirler_Id'];
                }

                foreach ($rows as $row) {
                    $sehirAdi = $row['A'] ?? '';
                    $tur      = strtoupper(str_replace(' ', '', $row['B'] ?? ''));

                    if (empty($sehirAdi)) continue;

                    // Başlık satırını atla
                    if (in_array($normalize($sehirAdi), ['SEHIR', 'IL', 'SEHIRADI', 'AD', 'NAME'])) continue;

                    $key = $normalize($sehirAdi);
                    $id  = $sehirMap[$key] ?? null;

                    if (!$id) {
                        $bulunamadi[] = $sehirAdi;
                        continue;
                    }

                    $updateData = [
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                    ];

                    // KOI veya SUPERKOI normalizasyonu
                    $turNorm = str_replace(['Ö','ö','İ','ı'], ['O','O','I','I'], $tur);

                    if (str_contains($turNorm, 'SUPER') || str_contains($turNorm, 'SÜPER')) {
                        $updateData['APISehirler_SuperKOI'] = 1;
                    } elseif (str_contains($turNorm, 'KOI') || str_contains($turNorm, 'KÖI')) {
                        $updateData['APISehirler_KOI'] = 1;
                    } else {
                        continue;
                    }

                    $db->update('APISehirler', $updateData, ['APISehirler_Id' => $id]);
                    $guncellendi++;
                }

                $msg = "Güncellendi: $guncellendi şehir.";
                if (!empty($bulunamadi)) {
                    $msg .= ' Bulunamadı: ' . implode(', ', array_unique($bulunamadi));
                }

                echo json_encode([
                    'success'     => true,
                    'message'     => $msg,
                    'guncellendi' => $guncellendi,
                    'bulunamadi'  => array_unique($bulunamadi),
                ]);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id     = (int)($_POST['id'] ?? 0);
                $result = $db->delete('APISehirler', ['APISehirler_Id' => $id]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt silindi' : 'Silme hatası']);
                break;

            case 'stats':
                $stats = [
                    'toplam'    => $db->fetchOne("SELECT COUNT(*) as c FROM APISehirler")['c'] ?? 0,
                    'aktif'     => $db->fetchOne("SELECT COUNT(*) as c FROM APISehirler WHERE Durum = 1")['c'] ?? 0,
                    'koi'       => $db->fetchOne("SELECT COUNT(*) as c FROM APISehirler WHERE APISehirler_KOI = 1 AND Durum = 1")['c'] ?? 0,
                    'super_koi' => $db->fetchOne("SELECT COUNT(*) as c FROM APISehirler WHERE APISehirler_SuperKOI = 1 AND Durum = 1")['c'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            case 'api_sync':
                if (!$permissions['can_edit'] && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'API senkronizasyon yetkiniz yok!']);
                    break;
                }

                $token = getApiToken($db);
                if (!$token) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli token yok (Personel ID: ' . TOKEN_PERSONEL_ID . '). Önce Token Güncelle görevini çalıştırın.']);
                    break;
                }

                $endpoint = $db->fetchOne("
                    SELECT APIEndpointler_Endpoint, APIEndpointler_HttpMetod, APIEndpointler_ParametreOrnek
                    FROM APIEndpointler WHERE APIEndpointler_Id = ?
                ", [API_ENDPOINT_ID]);

                if (!$endpoint) {
                    echo json_encode(['success' => false, 'message' => 'API endpoint bulunamadı (ID: ' . API_ENDPOINT_ID . ')']);
                    break;
                }

                $url    = $endpoint['APIEndpointler_Endpoint'];
                $metod  = strtoupper($endpoint['APIEndpointler_HttpMetod'] ?? 'POST');
                $params = $endpoint['APIEndpointler_ParametreOrnek'] ? json_decode($endpoint['APIEndpointler_ParametreOrnek'], true) : [];

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Token: ' . $token,
                    ],
                ]);

                if ($metod === 'POST') {
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
                } else {
                    curl_setopt($ch, CURLOPT_URL, $url . (!empty($params) ? '?' . http_build_query($params) : ''));
                }

                $rawResponse = curl_exec($ch);
                $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError   = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    echo json_encode(['success' => false, 'message' => 'API bağlantı hatası: ' . $curlError]);
                    break;
                }
                if ($httpCode < 200 || $httpCode >= 300) {
                    echo json_encode(['success' => false, 'message' => "API hata kodu: $httpCode"]);
                    break;
                }

                $responseData = json_decode($rawResponse, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo json_encode(['success' => false, 'message' => 'API yanıtı JSON formatında değil']);
                    break;
                }

                $items = $responseData['data'] ?? [];
                if (empty($items)) {
                    echo json_encode(['success' => false, 'message' => 'API yanıtında şehir verisi bulunamadı']);
                    break;
                }

                $eklendi     = 0;
                $guncellendi = 0;

                foreach ($items as $item) {
                    if (!is_array($item)) continue;

                    $kod = $item['code'] ?? null;
                    $ad  = $item['name'] ?? null;

                    if ($kod === null || $ad === null) continue;

                    $mevcut = $db->fetchOne(
                        "SELECT APISehirler_Id FROM APISehirler WHERE APISehirler_Kod = ?",
                        [$kod]
                    );

                    if ($mevcut) {
                        $db->update('APISehirler', [
                            'APISehirler_Ad'         => $ad,
                            'APISehirler_RawResponse' => json_encode($item, JSON_UNESCAPED_UNICODE),
                            'GuncelleyenKullanici'   => $user['kullanici_id'],
                            'GuncellemeTarihi'       => date('Y-m-d H:i:s'),
                        ], ['APISehirler_Id' => $mevcut['APISehirler_Id']]);
                        $guncellendi++;
                    } else {
                        $db->insert('APISehirler', [
                            'APISehirler_Kod'         => $kod,
                            'APISehirler_Ad'          => $ad,
                            'APISehirler_KOI'         => 0,
                            'APISehirler_SuperKOI'    => 0,
                            'APISehirler_RawResponse' => json_encode($item, JSON_UNESCAPED_UNICODE),
                            'OlusturanKullanici'      => $user['kullanici_id'],
                            'OlusturmaTarihi'         => date('Y-m-d H:i:s'),
                            'GuncelleyenKullanici'    => $user['kullanici_id'],
                            'GuncellemeTarihi'        => date('Y-m-d H:i:s'),
                            'Durum'                   => 1,
                        ]);
                        $eklendi++;
                    }
                }

                echo json_encode([
                    'success'     => true,
                    'message'     => "Senkronizasyon tamamlandı. Eklenen: $eklendi, Güncellenen: $guncellendi",
                    'eklendi'     => $eklendi,
                    'guncellendi' => $guncellendi,
                ]);
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
        .status-badge   { padding: .25rem .5rem; border-radius: .25rem; font-size: .875rem; cursor: pointer; }
        .status-active  { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .kod-badge { font-family: monospace; font-weight: 600; font-size: .85rem; background: #e9ecef; padding: .2rem .5rem; border-radius: .3rem; }
        .check-icon { font-size: 1.1rem; }
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
                            <span class="info-box-icon"><i class="bi bi-geo-alt"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Şehir</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif</span>
                                <span class="info-box-number" id="stat-aktif">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-building"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">KÖİ</span>
                                <span class="info-box-number" id="stat-koi">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-star"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Süper KÖİ</span>
                                <span class="info-box-number" id="stat-super-koi">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php include __DIR__ . '/../includes/api-personel-bilgisi.php'; ?>

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
                                    <input type="text" class="form-control" name="search" id="filter_search" placeholder="Şehir adı veya kodu...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Durum</label>
                                    <select class="form-select select2" name="status" id="filter_status">
                                        <option value="">Tümü</option>
                                        <option value="1">Aktif</option>
                                        <option value="0">Pasif</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">KÖİ</label>
                                    <select class="form-select select2" name="koi" id="filter_koi">
                                        <option value="">Tümü</option>
                                        <option value="1">KÖİ</option>
                                        <option value="0">KÖİ Değil</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Süper KÖİ</label>
                                    <select class="form-select select2" name="super_koi" id="filter_super_koi">
                                        <option value="">Tümü</option>
                                        <option value="1">Süper KÖİ</option>
                                        <option value="0">Süper KÖİ Değil</option>
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
                        <h3 class="card-title">Şehir Listesi</h3>
                        <div class="card-tools d-flex align-items-center gap-2">
                            <?php if ($permissions['can_edit']): ?>
                            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#excelModal">
                                <i class="bi bi-file-earmark-excel"></i> Excel'den Güncelle
                            </button>
                            <?php endif; ?>
                            <?php if ($permissions['can_edit'] || $permissions['can_add']): ?>
                            <button type="button" class="btn btn-info btn-sm" id="btnApiSync">
                                <i class="bi bi-arrow-repeat"></i> API'den Güncelle
                            </button>
                            <?php endif; ?>
                            <?php if ($permissions['can_add']): ?>
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#kayitModal" onclick="resetForm()">
                                <i class="bi bi-plus-circle"></i> Yeni Ekle
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <th style="width:70px">Kod</th>
                                    <th>Şehir Adı</th>
                                    <th style="width:80px" class="text-center">KÖİ</th>
                                    <th style="width:100px" class="text-center">Süper KÖİ</th>
                                    <th style="width:80px" class="text-center">Durum</th>
                                    <th style="width:140px">Son Güncelleme</th>
                                    <th style="width:130px">Güncelleyen</th>
                                    <th style="width:100px">İşlemler</th>
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

<!-- Excel Import Modal -->
<div class="modal fade" id="excelModal" tabindex="-1" aria-labelledby="excelModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="excelModalLabel"><i class="bi bi-file-earmark-excel text-success"></i> Excel'den KÖİ / Süper KÖİ Güncelle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="excelForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="alert alert-info py-2 mb-3">
                        <i class="bi bi-info-circle"></i>
                        <strong>Beklenen format (.xlsx):</strong><br>
                        <span class="font-monospace">A sütunu</span> → Şehir adı &nbsp;|&nbsp;
                        <span class="font-monospace">B sütunu</span> → <code>KOİ</code> veya <code>SUPER KOİ</code>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Excel Dosyası <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xlsx" required>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="sifirla" name="sifirla" value="1">
                        <label class="form-check-label" for="sifirla">
                            <span class="text-danger fw-bold">Önce tüm KÖİ / Süper KÖİ değerlerini sıfırla</span>
                            <small class="text-muted d-block">İşaretlenirse önce tüm şehirlerin KÖİ/Süper KÖİ değerleri 0 yapılır, sonra Excel'deki veriler işlenir.</small>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                    <button type="submit" class="btn btn-success" id="btnExcelImport"><i class="bi bi-upload"></i> Yükle ve Güncelle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Kayıt Modal -->
<div class="modal fade" id="kayitModal" tabindex="-1" aria-labelledby="kayitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kayitModalLabel">Yeni Şehir Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kayitForm">
                <div class="modal-body">
                    <input type="hidden" id="rec_id" name="id">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Şehir Kodu <span class="text-danger">*</span></label>
                            <input type="number" class="form-control font-monospace" id="sehir_kod" name="sehir_kod" required placeholder="Ör: 34">
                        </div>
                        <div class="col-md-9">
                            <label class="form-label">Şehir Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="sehir_ad" name="sehir_ad" required placeholder="Ör: İSTANBUL">
                        </div>
                        <div class="col-md-12">
                            <div class="row g-2">
                                <div class="col-auto">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="koi" name="koi">
                                        <label class="form-check-label" for="koi"><i class="bi bi-building text-warning"></i> KÖİ</label>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="super_koi" name="super_koi">
                                        <label class="form-check-label" for="super_koi"><i class="bi bi-star text-danger"></i> Süper KÖİ</label>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="durum" name="durum" checked>
                                        <label class="form-check-label" for="durum"><i class="bi bi-check-circle text-success"></i> Aktif</label>
                                    </div>
                                </div>
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

    let kayitModal, dataTable, currentFilters = {};

    $(document).ready(function () {
        kayitModal = new bootstrap.Modal(document.getElementById('kayitModal'));

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[1, 'asc']],
            columnDefs: [{ orderable: false, targets: [2, 3, 4, 7] }],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        });

        loadStats();
        loadList();

        $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

        $('#kayitForm').on('submit', function (e) {
            e.preventDefault();
            saveRecord();
        });

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            currentFilters = {
                search:    $('#filter_search').val(),
                status:    $('#filter_status').val(),
                koi:       $('#filter_koi').val(),
                super_koi: $('#filter_super_koi').val(),
            };
            Object.keys(currentFilters).forEach(k => { if (!currentFilters[k] && currentFilters[k] !== '0') delete currentFilters[k]; });
            loadList();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filterForm')[0].reset();
            $('.select2').val('').trigger('change');
            currentFilters = {};
            loadList();
            showToast('Filtreler temizlendi', 'info');
        });

        $('#btnApiSync').on('click', function () {
            confirmAction(
                'API\'den şehirleri güncellemek istiyor musunuz?',
                'Mevcut şehir adları güncellenecek, yeni şehirler eklenecektir. KÖİ/Süper KÖİ ayarları korunur.',
                function () {
                    const $btn = $('#btnApiSync');
                    $btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat spin"></i> Güncelleniyor...');

                    $.ajax({
                        url: '', method: 'POST',
                        data: { action: 'api_sync' },
                        dataType: 'json',
                        success: function (r) {
                            if (r.success) {
                                showSuccess('Tamamlandı!', r.message);
                                loadList();
                                loadStats();
                            } else {
                                showError('Hata!', r.message);
                            }
                        },
                        error: function () { showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'); },
                        complete: function () {
                            $btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat"></i> API\'den Güncelle');
                        }
                    });
                }
            );
        });

        document.getElementById('kayitModal').addEventListener('hidden.bs.modal', resetForm);

        $('#excelForm').on('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'excel_import');

            const $btn = $('#btnExcelImport');
            $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> İşleniyor...');

            $.ajax({
                url: '', method: 'POST',
                data: formData, processData: false, contentType: false, dataType: 'json',
                success: function (r) {
                    if (r.success) {
                        let html = r.message;
                        if (r.bulunamadi && r.bulunamadi.length > 0) {
                            html += '<br><br><strong>Eşleşmeyen şehirler:</strong><br>' +
                                    r.bulunamadi.map(s => `<span class="badge bg-warning text-dark me-1">${s}</span>`).join('');
                        }
                        Swal.fire({ title: 'Tamamlandı!', html: html, icon: 'success' });
                        bootstrap.Modal.getInstance(document.getElementById('excelModal')).hide();
                        document.getElementById('excelForm').reset();
                        loadList();
                        loadStats();
                    } else {
                        showError('Hata!', r.message);
                    }
                },
                error: function () { showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'); },
                complete: function () {
                    $btn.prop('disabled', false).html('<i class="bi bi-upload"></i> Yükle ve Güncelle');
                }
            });
        });
    });

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-aktif').text(r.data.aktif);
            $('#stat-koi').text(r.data.koi);
            $('#stat-super-koi').text(r.data.super_koi);
        });
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
            const kod  = `<span class="kod-badge">${escapeHtml(String(row.APISehirler_Kod))}</span>`;
            const koi  = row.APISehirler_KOI == 1
                ? '<i class="bi bi-check-circle-fill text-warning check-icon" title="KÖİ"></i>'
                : '<i class="bi bi-dash text-muted check-icon"></i>';
            const skoi = row.APISehirler_SuperKOI == 1
                ? '<i class="bi bi-star-fill text-danger check-icon" title="Süper KÖİ"></i>'
                : '<i class="bi bi-dash text-muted check-icon"></i>';
            const durum = row.Durum == 1
                ? `<span class="status-badge status-active" onclick="toggleDurum(${row.APISehirler_Id}, 0)" title="Pasife al">Aktif</span>`
                : `<span class="status-badge status-inactive" onclick="toggleDurum(${row.APISehirler_Id}, 1)" title="Aktife al">Pasif</span>`;

            let islemler = '';
            if (permissions.canEdit)
                islemler += `<button class="btn btn-sm btn-warning me-1" onclick="editRecord(${row.APISehirler_Id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
            if (permissions.canDelete)
                islemler += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${row.APISehirler_Id})" title="Sil"><i class="bi bi-trash"></i></button>`;
            if (!islemler) islemler = '<span class="text-muted">-</span>';

            dataTable.row.add([
                kod,
                escapeHtml(row.APISehirler_Ad || '-'),
                `<div class="text-center">${koi}</div>`,
                `<div class="text-center">${skoi}</div>`,
                `<div class="text-center">${durum}</div>`,
                escapeHtml(row.GuncellemeTarihi || '-'),
                escapeHtml(row.GuncelleyenAd || '-'),
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
        document.getElementById('kayitModalLabel').textContent = 'Yeni Şehir Ekle';
        document.getElementById('durum').checked    = true;
        document.getElementById('koi').checked      = false;
        document.getElementById('super_koi').checked = false;
    }

    function saveRecord() {
        const formData = new FormData(document.getElementById('kayitForm'));
        formData.append('action', 'save');

        $.ajax({
            url: '', method: 'POST',
            data: formData, processData: false, contentType: false, dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showToast(r.message, 'success');
                    kayitModal.hide();
                    loadList();
                    loadStats();
                } else {
                    showToast(r.message, 'error');
                }
            },
            error: function () { showToast('Kayıt sırasında hata oluştu', 'error'); }
        });
    }

    function editRecord(id) {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'get', id: id },
            dataType: 'json',
            success: function (r) {
                if (!r.success || !r.data) { showToast('Kayıt bulunamadı', 'error'); return; }
                const d = r.data;
                document.getElementById('rec_id').value       = d.APISehirler_Id;
                document.getElementById('sehir_kod').value    = d.APISehirler_Kod;
                document.getElementById('sehir_ad').value     = d.APISehirler_Ad || '';
                document.getElementById('koi').checked        = d.APISehirler_KOI == 1;
                document.getElementById('super_koi').checked  = d.APISehirler_SuperKOI == 1;
                document.getElementById('durum').checked      = d.Durum == 1;
                document.getElementById('kayitModalLabel').textContent = 'Şehir Düzenle';
                kayitModal.show();
            },
            error: function () { showToast('Kayıt yüklenirken hata oluştu', 'error'); }
        });
    }

    function toggleDurum(id, yeniDurum) {
        if (!permissions.canEdit) return;
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'toggle_durum', id: id, durum: yeniDurum },
            dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showToast(yeniDurum == 1 ? 'Aktif edildi' : 'Pasif edildi', 'success');
                    loadList();
                    loadStats();
                } else {
                    showToast('Durum değiştirilemedi', 'error');
                }
            }
        });
    }

    function deleteRecord(id) {
        confirmAction(
            'Bu şehri silmek istediğinize emin misiniz?',
            'Bu işlem geri alınamaz!',
            function () {
                $.ajax({
                    url: '', method: 'POST',
                    data: { action: 'delete', id: id },
                    dataType: 'json',
                    success: function (r) {
                        if (r.success) {
                            showSuccess('Silindi!', r.message);
                            loadList();
                            loadStats();
                        } else {
                            showError('Hata!', r.message);
                        }
                    },
                    error: function () { showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'); }
                });
            }
        );
    }
</script>
</body>
</html>
