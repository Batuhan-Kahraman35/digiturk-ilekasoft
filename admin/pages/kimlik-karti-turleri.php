<?php
/**
 * Admin Panel - API Kimlik Kartı Türleri
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

$pageTitle   = $pageinfo['sayfalar_sayfa_adi'] ?? 'Kimlik Kartı Türleri';
$menuAdi     = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

const API_ENDPOINT_ID = 13;

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'list':
                $search = $_POST['search'] ?? '';
                $status = $_POST['status'] ?? '';

                $where  = ["1=1"];
                $params = [];

                if ($search) {
                    $where[] = "(t.APIKimlikKartiTurleri_card_code LIKE ? OR t.APIKimlikKartiTurleri_card_name LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($status !== '') {
                    $where[] = "t.Durum = ?";
                    $params[] = $status;
                }

                $whereClause = implode(" AND ", $where);

                $list = $db->fetchAll("
                    SELECT
                        t.APIKimlikKartiTurleri_Id,
                        t.APIKimlikKartiTurleri_card_code,
                        t.APIKimlikKartiTurleri_card_name,
                        t.Durum,
                        CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) as OlusturmaTarihi,
                        CONVERT(VARCHAR(19), t.GuncellemeTarihi, 120) as GuncellemeTarihi,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as GuncelleyenAd
                    FROM APIKimlikKartiTurleri t
                    LEFT JOIN kullanicilar k ON t.GuncelleyenKullanici = k.kullanici_id
                    WHERE $whereClause
                    ORDER BY t.APIKimlikKartiTurleri_card_code
                ", $params);

                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get':
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("SELECT * FROM APIKimlikKartiTurleri WHERE APIKimlikKartiTurleri_Id = ?", [$id]);
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
                    'APIKimlikKartiTurleri_card_code' => trim($_POST['card_code'] ?? ''),
                    'APIKimlikKartiTurleri_card_name' => trim($_POST['card_name'] ?? ''),
                    'Durum'                           => isset($_POST['durum']) ? 1 : 0,
                ];

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->update('APIKimlikKartiTurleri', $data, ['APIKimlikKartiTurleri_Id' => $id]);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['OlusturanKullanici']   = $user['kullanici_id'];
                    $data['OlusturmaTarihi']      = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->insert('APIKimlikKartiTurleri', $data);
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
                $result = $db->update('APIKimlikKartiTurleri', [
                    'Durum'               => $durum,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['APIKimlikKartiTurleri_Id' => $id]);
                echo json_encode(['success' => (bool)$result]);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id     = (int)($_POST['id'] ?? 0);
                $result = $db->delete('APIKimlikKartiTurleri', ['APIKimlikKartiTurleri_Id' => $id]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt silindi' : 'Silme hatası']);
                break;

            case 'stats':
                $stats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as c FROM APIKimlikKartiTurleri")['c'] ?? 0,
                    'aktif'  => $db->fetchOne("SELECT COUNT(*) as c FROM APIKimlikKartiTurleri WHERE Durum = 1")['c'] ?? 0,
                    'pasif'  => $db->fetchOne("SELECT COUNT(*) as c FROM APIKimlikKartiTurleri WHERE Durum = 0")['c'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            case 'api_sync':
                if (!$permissions['can_edit'] && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'API senkronizasyon yetkiniz yok!']);
                    break;
                }

                // Endpoint konfigürasyonunu al
                $endpoint = $db->fetchOne("
                    SELECT
                        APIEndpointler_Endpoint,
                        APIEndpointler_HttpMetod,
                        APIEndpointler_ParametreOrnek
                    FROM APIEndpointler
                    WHERE APIEndpointler_Id = ?
                ", [API_ENDPOINT_ID]);

                if (!$endpoint) {
                    echo json_encode(['success' => false, 'message' => 'API endpoint konfigürasyonu bulunamadı (ID: ' . API_ENDPOINT_ID . ')']);
                    break;
                }

                $url    = $endpoint['APIEndpointler_Endpoint'];
                $metod  = strtoupper($endpoint['APIEndpointler_HttpMetod'] ?? 'GET');
                $params = $endpoint['APIEndpointler_ParametreOrnek'] ? json_decode($endpoint['APIEndpointler_ParametreOrnek'], true) : [];

                // cURL isteği
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                ]);

                if ($metod === 'POST') {
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
                } else {
                    $queryStr = !empty($params) ? '?' . http_build_query($params) : '';
                    curl_setopt($ch, CURLOPT_URL, $url . $queryStr);
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

                // Yanıt içinden liste bul (dizi veya nesne içindeki dizi)
                $items = [];
                if (is_array($responseData)) {
                    if (isset($responseData[0])) {
                        // Doğrudan dizi
                        $items = $responseData;
                    } else {
                        // Obje içinde dizi ara
                        foreach ($responseData as $val) {
                            if (is_array($val) && isset($val[0])) {
                                $items = $val;
                                break;
                            }
                        }
                        if (empty($items)) {
                            $items = [$responseData];
                        }
                    }
                }

                if (empty($items)) {
                    echo json_encode(['success' => false, 'message' => 'API yanıtında işlenecek veri bulunamadı']);
                    break;
                }

                $eklendi    = 0;
                $guncellendi = 0;

                foreach ($items as $item) {
                    if (!is_array($item)) continue;

                    // Alan adlarını küçük harfe normalize et
                    $normalItem = array_change_key_case($item, CASE_LOWER);

                    $cardCode = $normalItem['card_code'] ?? $normalItem['cardcode'] ?? $normalItem['code'] ?? null;
                    $cardName = $normalItem['card_name'] ?? $normalItem['cardname'] ?? $normalItem['name'] ?? null;

                    if ($cardCode === null) continue;

                    $mevcut = $db->fetchOne(
                        "SELECT APIKimlikKartiTurleri_Id FROM APIKimlikKartiTurleri WHERE APIKimlikKartiTurleri_card_code = ?",
                        [$cardCode]
                    );

                    if ($mevcut) {
                        $db->update('APIKimlikKartiTurleri', [
                            'APIKimlikKartiTurleri_card_name' => $cardName,
                            'APIKimlikKartiTurleri_raw_response' => json_encode($item, JSON_UNESCAPED_UNICODE),
                            'GuncelleyenKullanici'            => $user['kullanici_id'],
                            'GuncellemeTarihi'                => date('Y-m-d H:i:s'),
                        ], ['APIKimlikKartiTurleri_Id' => $mevcut['APIKimlikKartiTurleri_Id']]);
                        $guncellendi++;
                    } else {
                        $db->insert('APIKimlikKartiTurleri', [
                            'APIKimlikKartiTurleri_card_code'    => $cardCode,
                            'APIKimlikKartiTurleri_card_name'    => $cardName,
                            'APIKimlikKartiTurleri_raw_response' => json_encode($item, JSON_UNESCAPED_UNICODE),
                            'OlusturanKullanici'                 => $user['kullanici_id'],
                            'OlusturmaTarihi'                    => date('Y-m-d H:i:s'),
                            'GuncelleyenKullanici'               => $user['kullanici_id'],
                            'GuncellemeTarihi'                   => date('Y-m-d H:i:s'),
                            'Durum'                              => 1,
                        ]);
                        $eklendi++;
                    }
                }

                echo json_encode([
                    'success' => true,
                    'message' => "Senkronizasyon tamamlandı. Eklenen: $eklendi, Güncellenen: $guncellendi",
                    'eklendi'    => $eklendi,
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
        .status-badge { padding: .25rem .5rem; border-radius: .25rem; font-size: .875rem; }
        .status-active   { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .code-badge { font-family: monospace; font-weight: 600; font-size: .85rem; background: #e9ecef; padding: .2rem .5rem; border-radius: .3rem; }
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
                    <div class="col-md-4">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-credit-card"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Tür</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif</span>
                                <span class="info-box-number" id="stat-aktif">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Pasif</span>
                                <span class="info-box-number" id="stat-pasif">0</span>
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
                                <div class="col-md-5">
                                    <label class="form-label">Ara</label>
                                    <input type="text" class="form-control" name="search" id="filter_search" placeholder="Kod veya isim...">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Durum</label>
                                    <select class="form-select select2" name="status" id="filter_status">
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
                        <h3 class="card-title">Kimlik Kartı Türleri</h3>
                        <div class="card-tools d-flex gap-2">
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
                                    <th>Kart Kodu</th>
                                    <th>Kart Adı</th>
                                    <th>Durum</th>
                                    <th>Son Güncelleme</th>
                                    <th>Güncelleyen</th>
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

<!-- Kayıt Modal -->
<div class="modal fade" id="kayitModal" tabindex="-1" aria-labelledby="kayitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kayitModalLabel">Yeni Kayıt Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kayitForm">
                <div class="modal-body">
                    <input type="hidden" id="rec_id" name="id">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Kart Kodu <span class="text-danger">*</span></label>
                            <input type="text" class="form-control font-monospace" id="card_code" name="card_code" required placeholder="Ör: KIMLIK, PASAPORT">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Kart Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="card_name" name="card_name" required placeholder="Ör: T.C. Kimlik Kartı">
                        </div>
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="durum" name="durum" checked>
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

<!-- Raw Response Modal -->
<div class="modal fade" id="rawModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ham API Yanıtı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="rawContent" style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:.375rem;padding:.75rem;font-size:.8rem;max-height:500px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;"></pre>
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

    let kayitModal, rawModal, dataTable, currentFilters = {};

    $(document).ready(function () {
        kayitModal = new bootstrap.Modal(document.getElementById('kayitModal'));
        rawModal   = new bootstrap.Modal(document.getElementById('rawModal'));

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc']],
            columnDefs: [{ orderable: false, targets: [5] }],
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
                search: $('#filter_search').val(),
                status: $('#filter_status').val()
            };
            Object.keys(currentFilters).forEach(k => { if (!currentFilters[k]) delete currentFilters[k]; });
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
                'API\'den verileri güncellemek istiyor musunuz?',
                'Mevcut kayıtlar güncellenecek, yeni kayıtlar eklenecektir.',
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
    });

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-aktif').text(r.data.aktif);
            $('#stat-pasif').text(r.data.pasif);
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
            const code  = `<span class="code-badge">${escapeHtml(row.APIKimlikKartiTurleri_card_code || '-')}</span>`;
            const durum = row.Durum == 1
                ? `<span class="status-badge status-active" style="cursor:pointer" onclick="toggleDurum(${row.APIKimlikKartiTurleri_Id}, 0)" title="Pasife al">Aktif</span>`
                : `<span class="status-badge status-inactive" style="cursor:pointer" onclick="toggleDurum(${row.APIKimlikKartiTurleri_Id}, 1)" title="Aktife al">Pasif</span>`;

            let islemler = '';
            if (permissions.canEdit)
                islemler += `<button class="btn btn-sm btn-warning me-1" onclick="editRecord(${row.APIKimlikKartiTurleri_Id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
            if (permissions.canDelete)
                islemler += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${row.APIKimlikKartiTurleri_Id})" title="Sil"><i class="bi bi-trash"></i></button>`;
            if (!islemler) islemler = '<span class="text-muted">-</span>';

            dataTable.row.add([
                code,
                escapeHtml(row.APIKimlikKartiTurleri_card_name || '-'),
                durum,
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
        document.getElementById('kayitModalLabel').textContent = 'Yeni Kayıt Ekle';
        document.getElementById('durum').checked = true;
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
                document.getElementById('rec_id').value    = d.APIKimlikKartiTurleri_Id;
                document.getElementById('card_code').value = d.APIKimlikKartiTurleri_card_code || '';
                document.getElementById('card_name').value = d.APIKimlikKartiTurleri_card_name || '';
                document.getElementById('durum').checked   = d.Durum == 1;
                document.getElementById('kayitModalLabel').textContent = 'Kayıt Düzenle';
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
            'Bu kaydı silmek istediğinize emin misiniz?',
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
