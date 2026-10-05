<?php
/**
 * Admin Panel - API Kampanya Türleri
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'API Kampanya Türleri';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

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
                    $where[] = "(t.APIKampanyaTurleri_Tur_Adi LIKE ? OR t.APIKampanyaTurleri_Kod LIKE ?)";
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
                        t.APIKampanyaTurleri_Id,
                        t.APIKampanyaTurleri_Tur_Adi,
                        t.APIKampanyaTurleri_Kod,
                        t.APIKampanyaTurleri_Aciklama,
                        t.APIKampanyaTurleri_Renk,
                        t.APIKampanyaTurleri_Simge,
                        t.Durum,
                        CONVERT(VARCHAR(19), t.GuncellemeTarihi, 120) as GuncellemeTarihi,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as GuncelleyenAd
                    FROM APIKampanyaTurleri t
                    LEFT JOIN kullanicilar k ON t.GuncelleyenKullanici = k.kullanici_id
                    WHERE $whereClause
                    ORDER BY t.APIKampanyaTurleri_Tur_Adi
                ", $params);

                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get':
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("SELECT * FROM APIKampanyaTurleri WHERE APIKampanyaTurleri_Id = ?", [$id]);
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

                $renk  = trim($_POST['renk'] ?? '#007bff');
                $simge = trim($_POST['simge'] ?? 'bi-tag');

                // Renk hex formatı kontrolü
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $renk)) {
                    $renk = '#007bff';
                }

                $data = [
                    'APIKampanyaTurleri_Tur_Adi'  => trim($_POST['tur_adi'] ?? ''),
                    'APIKampanyaTurleri_Kod'       => strtoupper(trim($_POST['kod'] ?? '')) ?: null,
                    'APIKampanyaTurleri_Aciklama'  => trim($_POST['aciklama'] ?? '') ?: null,
                    'APIKampanyaTurleri_Renk'      => $renk,
                    'APIKampanyaTurleri_Simge'     => $simge,
                    'Durum'                        => isset($_POST['durum']) ? 1 : 0,
                ];

                if (empty($data['APIKampanyaTurleri_Tur_Adi'])) {
                    echo json_encode(['success' => false, 'message' => 'Tür adı zorunludur!']);
                    break;
                }

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->update('APIKampanyaTurleri', $data, ['APIKampanyaTurleri_Id' => $id]);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['OlusturanKullanici']   = $user['kullanici_id'];
                    $data['OlusturmaTarihi']      = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->insert('APIKampanyaTurleri', $data);
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
                $result = $db->update('APIKampanyaTurleri', [
                    'Durum'               => $durum,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['APIKampanyaTurleri_Id' => $id]);
                echo json_encode(['success' => (bool)$result]);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id     = (int)($_POST['id'] ?? 0);
                $result = $db->delete('APIKampanyaTurleri', ['APIKampanyaTurleri_Id' => $id]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt silindi' : 'Silme hatası']);
                break;

            case 'stats':
                $stats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as c FROM APIKampanyaTurleri")['c'] ?? 0,
                    'aktif'  => $db->fetchOne("SELECT COUNT(*) as c FROM APIKampanyaTurleri WHERE Durum = 1")['c'] ?? 0,
                    'pasif'  => $db->fetchOne("SELECT COUNT(*) as c FROM APIKampanyaTurleri WHERE Durum = 0")['c'] ?? 0,
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
        .status-badge  { padding: .25rem .5rem; border-radius: .25rem; font-size: .875rem; }
        .status-active   { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .kod-badge { font-family: monospace; font-weight: 600; font-size: .85rem; background: #e9ecef; padding: .2rem .5rem; border-radius: .3rem; }
        .tur-badge {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .3rem .65rem;
            border-radius: 1rem;
            font-weight: 600;
            font-size: .85rem;
            color: #fff;
        }
        .color-dot {
            display: inline-block;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 2px solid rgba(0,0,0,.15);
            vertical-align: middle;
        }
        .icon-preview {
            font-size: 1.4rem;
            line-height: 1;
        }
        input[type="color"] {
            height: 38px;
            padding: .25rem .5rem;
            cursor: pointer;
        }
        .icon-search-results {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: .375rem;
            display: none;
        }
        .icon-search-results .icon-item {
            display: inline-block;
            width: 48px;
            height: 48px;
            text-align: center;
            line-height: 48px;
            font-size: 1.4rem;
            cursor: pointer;
            border-radius: .375rem;
            transition: background .15s;
        }
        .icon-search-results .icon-item:hover {
            background: #e9ecef;
        }
        .icon-search-results .icon-item.selected {
            background: #cfe2ff;
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
                    <div class="col-md-4">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-tags"></i></span>
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
                                    <input type="text" class="form-control" name="search" id="filter_search" placeholder="Tür adı veya kod...">
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
                        <h3 class="card-title">Kampanya Türleri</h3>
                        <div class="card-tools">
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
                                    <th>Görünüm</th>
                                    <th>Tür Adı</th>
                                    <th>Kod</th>
                                    <th>Açıklama</th>
                                    <th>Durum</th>
                                    <th>Son Güncelleme</th>
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
                        <div class="col-md-8">
                            <label class="form-label">Tür Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="tur_adi" name="tur_adi" required placeholder="Ör: Neo (Kutusuz)">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Kod</label>
                            <input type="text" class="form-control font-monospace text-uppercase" id="kod" name="kod" placeholder="Ör: NEO" maxlength="50"
                                   oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Açıklama</label>
                            <input type="text" class="form-control" id="aciklama" name="aciklama" placeholder="Ör: BeinConnect uygulaması ile kullanılan">
                        </div>

                        <!-- Renk ve Simge -->
                        <div class="col-md-4">
                            <label class="form-label">Renk</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="form-control" id="renk" name="renk" value="#007bff">
                                <span id="renkHex" class="font-monospace text-muted small">#007bff</span>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Bootstrap Simge</label>
                            <div class="input-group">
                                <span class="input-group-text"><i id="simgeOnizleme" class="bi bi-tag icon-preview"></i></span>
                                <input type="text" class="form-control font-monospace" id="simge" name="simge" value="bi-tag" placeholder="Ör: bi-phone, bi-broadcast">
                            </div>
                            <div class="mt-1">
                                <input type="text" class="form-control form-control-sm" id="simgeAra" placeholder="Simge ara... (telefon, uydu, wifi...)">
                            </div>
                            <div id="simgeAramasonuclari" class="icon-search-results mt-1 p-2"></div>
                            <small class="text-muted">
                                <a href="https://icons.getbootstrap.com/" target="_blank">Bootstrap Icons</a> listesinden seçin.
                            </small>
                        </div>

                        <!-- Önizleme -->
                        <div class="col-md-12">
                            <label class="form-label">Önizleme</label>
                            <div>
                                <span class="tur-badge" id="onizlemeBadge" style="background-color:#007bff">
                                    <i class="bi bi-tag" id="onizlemeSimge"></i>
                                    <span id="onizlemeAd">Tür Adı</span>
                                </span>
                            </div>
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

    // Bootstrap Icons arama için temel liste
    const BI_ICONS = [
        'bi-phone','bi-phone-fill','bi-broadcast','bi-broadcast-pin','bi-wifi','bi-wifi-off',
        'bi-satellite','bi-satellite-fill','bi-tv','bi-tv-fill','bi-reception-4','bi-router',
        'bi-router-fill','bi-tag','bi-tag-fill','bi-tags','bi-tags-fill','bi-gift','bi-gift-fill',
        'bi-star','bi-star-fill','bi-award','bi-award-fill','bi-trophy','bi-trophy-fill',
        'bi-lightning','bi-lightning-fill','bi-lightning-charge','bi-lightning-charge-fill',
        'bi-check-circle','bi-check-circle-fill','bi-x-circle','bi-x-circle-fill',
        'bi-info-circle','bi-info-circle-fill','bi-exclamation-circle','bi-exclamation-circle-fill',
        'bi-shield','bi-shield-fill','bi-shield-check','bi-shield-check-fill',
        'bi-person','bi-person-fill','bi-people','bi-people-fill',
        'bi-house','bi-house-fill','bi-building','bi-building-fill',
        'bi-cart','bi-cart-fill','bi-bag','bi-bag-fill',
        'bi-credit-card','bi-credit-card-fill','bi-cash','bi-cash-stack',
        'bi-percent','bi-currency-dollar','bi-currency-euro',
        'bi-calendar','bi-calendar-fill','bi-clock','bi-clock-fill',
        'bi-play-circle','bi-play-circle-fill','bi-film','bi-camera-video','bi-camera-video-fill',
        'bi-music-note','bi-music-note-beamed','bi-headphones','bi-speaker','bi-speaker-fill',
        'bi-globe','bi-globe2','bi-arrow-up-right-square','bi-box-arrow-up-right',
        'bi-device-hdd','bi-device-ssd','bi-hdd','bi-hdd-fill',
        'bi-cpu','bi-cpu-fill','bi-memory','bi-display','bi-display-fill',
        'bi-controller','bi-joystick','bi-puzzle','bi-puzzle-fill',
        'bi-balloon','bi-balloon-fill','bi-fire','bi-snow','bi-sun','bi-moon',
        'bi-heart','bi-heart-fill','bi-hand-thumbs-up','bi-hand-thumbs-up-fill',
        'bi-emoji-smile','bi-emoji-heart-eyes','bi-emoji-laughing',
        'bi-box','bi-box-fill','bi-boxes','bi-archive','bi-archive-fill',
        'bi-collection','bi-collection-fill','bi-grid','bi-grid-fill',
        'bi-list','bi-list-ul','bi-card-list','bi-card-checklist',
        'bi-flag','bi-flag-fill','bi-pin','bi-pin-fill','bi-bookmark','bi-bookmark-fill',
        'bi-bell','bi-bell-fill','bi-megaphone','bi-megaphone-fill',
        'bi-chat','bi-chat-fill','bi-chat-dots','bi-chat-dots-fill',
        'bi-envelope','bi-envelope-fill','bi-send','bi-send-fill',
        'bi-search','bi-filter','bi-funnel','bi-funnel-fill',
        'bi-gear','bi-gear-fill','bi-sliders','bi-sliders2',
        'bi-tools','bi-wrench','bi-hammer','bi-screwdriver',
        'bi-lock','bi-lock-fill','bi-unlock','bi-unlock-fill',
        'bi-key','bi-key-fill','bi-door-open','bi-door-closed',
        'bi-file','bi-file-fill','bi-files','bi-folder','bi-folder-fill',
        'bi-cloud','bi-cloud-fill','bi-cloud-upload','bi-cloud-download',
        'bi-database','bi-database-fill','bi-server','bi-hdd-network',
        'bi-lightning-bolt','bi-plug','bi-plug-fill','bi-battery','bi-battery-full',
        'bi-arrow-clockwise','bi-arrow-repeat','bi-refresh','bi-sync',
        'bi-check-lg','bi-x-lg','bi-plus-lg','bi-dash-lg',
        'bi-chevron-up','bi-chevron-down','bi-chevron-left','bi-chevron-right',
        'bi-three-dots','bi-three-dots-vertical','bi-grid-3x3-gap','bi-grid-3x3-gap-fill'
    ];

    let kayitModal, dataTable, currentFilters = {};
    let iconSearchTimeout = null;

    $(document).ready(function () {
        kayitModal = new bootstrap.Modal(document.getElementById('kayitModal'));

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[1, 'asc']],
            columnDefs: [{ orderable: false, targets: [0, 6] }],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        });

        loadStats();
        loadList();

        $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

        // Renk değişince önizleme güncelle
        $('#renk').on('input', function () {
            const val = this.value;
            $('#renkHex').text(val);
            updateOnizleme();
        });

        // Simge adı değişince önizleme güncelle
        $('#simge').on('input', function () {
            updateOnizleme();
        });

        // Tür adı değişince önizleme güncelle
        $('#tur_adi').on('input', function () {
            updateOnizleme();
        });

        // Simge arama
        $('#simgeAra').on('input', function () {
            clearTimeout(iconSearchTimeout);
            iconSearchTimeout = setTimeout(() => searchIcons(this.value.trim()), 200);
        });

        // Dışarı tıklayınca sonuçları gizle
        $(document).on('click', function (e) {
            if (!$(e.target).closest('#simgeAra, #simgeAramasonuclari').length) {
                $('#simgeAramasonuclari').hide();
            }
        });

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

        document.getElementById('kayitModal').addEventListener('hidden.bs.modal', resetForm);
    });

    function searchIcons(query) {
        const container = $('#simgeAramasonuclari');
        if (!query) { container.hide().empty(); return; }

        const results = BI_ICONS.filter(ic => ic.replace('bi-', '').includes(query.toLowerCase()));
        if (!results.length) { container.hide().empty(); return; }

        const selected = $('#simge').val();
        container.empty();
        results.slice(0, 60).forEach(ic => {
            const cls = ic === selected ? 'selected' : '';
            container.append(
                `<span class="icon-item ${cls}" title="${ic}" onclick="selectIcon('${ic}')"><i class="bi ${ic}"></i></span>`
            );
        });
        container.show();
    }

    function selectIcon(ic) {
        $('#simge').val(ic);
        $('#simgeAramasonuclari').hide();
        updateOnizleme();
    }

    function updateOnizleme() {
        const renk  = $('#renk').val() || '#007bff';
        const simge = $('#simge').val() || 'bi-tag';
        const ad    = $('#tur_adi').val() || 'Tür Adı';

        $('#onizlemeBadge').css('background-color', renk);
        $('#onizlemeSimge').attr('class', 'bi ' + simge);
        $('#onizlemeAd').text(ad);
        $('#simgeOnizleme').attr('class', 'bi ' + simge + ' icon-preview');
    }

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
            const renk  = escapeHtml(row.APIKampanyaTurleri_Renk || '#007bff');
            const simge = escapeHtml(row.APIKampanyaTurleri_Simge || 'bi-tag');
            const ad    = escapeHtml(row.APIKampanyaTurleri_Tur_Adi || '-');

            const gorunum = `<span class="tur-badge" style="background-color:${renk}">
                <i class="bi ${simge}"></i> ${ad}
            </span>`;

            const kod = row.APIKampanyaTurleri_Kod
                ? `<span class="kod-badge">${escapeHtml(row.APIKampanyaTurleri_Kod)}</span>`
                : '<span class="text-muted">-</span>';

            const durum = row.Durum == 1
                ? `<span class="status-badge status-active" style="cursor:pointer" onclick="toggleDurum(${row.APIKampanyaTurleri_Id}, 0)" title="Pasife al">Aktif</span>`
                : `<span class="status-badge status-inactive" style="cursor:pointer" onclick="toggleDurum(${row.APIKampanyaTurleri_Id}, 1)" title="Aktife al">Pasif</span>`;

            let islemler = '';
            if (permissions.canEdit)
                islemler += `<button class="btn btn-sm btn-warning me-1" onclick="editRecord(${row.APIKampanyaTurleri_Id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
            if (permissions.canDelete)
                islemler += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${row.APIKampanyaTurleri_Id})" title="Sil"><i class="bi bi-trash"></i></button>`;
            if (!islemler) islemler = '<span class="text-muted">-</span>';

            dataTable.row.add([
                gorunum,
                ad,
                kod,
                escapeHtml(row.APIKampanyaTurleri_Aciklama || '-'),
                durum,
                escapeHtml(row.GuncellemeTarihi || '-'),
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
        document.getElementById('renk').value = '#007bff';
        document.getElementById('simge').value = 'bi-tag';
        document.getElementById('renkHex').textContent = '#007bff';
        $('#simgeAramasonuclari').hide().empty();
        updateOnizleme();
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
                document.getElementById('rec_id').value  = d.APIKampanyaTurleri_Id;
                document.getElementById('tur_adi').value = d.APIKampanyaTurleri_Tur_Adi || '';
                document.getElementById('kod').value     = d.APIKampanyaTurleri_Kod || '';
                document.getElementById('aciklama').value= d.APIKampanyaTurleri_Aciklama || '';
                document.getElementById('renk').value    = d.APIKampanyaTurleri_Renk || '#007bff';
                document.getElementById('simge').value   = d.APIKampanyaTurleri_Simge || 'bi-tag';
                document.getElementById('durum').checked = d.Durum == 1;
                document.getElementById('renkHex').textContent = d.APIKampanyaTurleri_Renk || '#007bff';
                document.getElementById('kayitModalLabel').textContent = 'Kayıt Düzenle';
                updateOnizleme();
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
