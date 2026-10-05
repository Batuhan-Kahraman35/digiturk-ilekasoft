<?php
/**
 * Admin Panel - Digiturk Endpoint Yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Digiturk Endpoint Yönetimi';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

$httpMetodlar = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'list':
                $search    = $_POST['search'] ?? '';
                $kategori  = $_POST['kategori'] ?? '';
                $metod     = $_POST['metod'] ?? '';
                $status    = $_POST['status'] ?? '';

                $where  = ["1=1"];
                $params = [];

                if ($search) {
                    $where[] = "(e.APIEndpointler_Endpoint LIKE ? OR e.APIEndpointler_Aciklama LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($kategori) {
                    $where[] = "e.APIEndpointler_Kategori = ?";
                    $params[] = $kategori;
                }
                if ($metod) {
                    $where[] = "e.APIEndpointler_HttpMetod = ?";
                    $params[] = $metod;
                }
                if ($status !== '') {
                    $where[] = "e.Durum = ?";
                    $params[] = $status;
                }

                $whereClause = implode(" AND ", $where);

                $list = $db->fetchAll("
                    SELECT
                        e.APIEndpointler_Id,
                        e.APIEndpointler_Kategori,
                        e.APIEndpointler_Endpoint,
                        e.APIEndpointler_Aciklama,
                        e.APIEndpointler_HttpMetod,
                        e.APIEndpointler_ParametreOrnek,
                        e.APIEndpointler_YanitOrnek,
                        e.Durum,
                        CONVERT(VARCHAR(19), e.OlusturmaTarihi, 120) as OlusturmaTarihi,
                        CONVERT(VARCHAR(19), e.GuncellemeTarihi, 120) as GuncellemeTarihi,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as OlusturanAd
                    FROM APIEndpointler e
                    LEFT JOIN kullanicilar k ON e.OlusturanKullanici = k.kullanici_id
                    WHERE $whereClause
                    ORDER BY e.APIEndpointler_Kategori, e.APIEndpointler_Endpoint
                ", $params);

                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get':
                $id = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("SELECT * FROM APIEndpointler WHERE APIEndpointler_Id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $row]);
                break;

            case 'kategoriler':
                $rows = $db->fetchAll("
                    SELECT DISTINCT APIEndpointler_Kategori
                    FROM APIEndpointler
                    WHERE APIEndpointler_Kategori IS NOT NULL AND APIEndpointler_Kategori <> ''
                    ORDER BY APIEndpointler_Kategori
                ");
                $kategoriler = array_column($rows, 'APIEndpointler_Kategori');
                echo json_encode(['success' => true, 'data' => $kategoriler]);
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
                    'APIEndpointler_Kategori'       => $_POST['kategori'] ?? null,
                    'APIEndpointler_Endpoint'       => $_POST['endpoint'] ?? '',
                    'APIEndpointler_Aciklama'       => $_POST['aciklama'] ?? null,
                    'APIEndpointler_HttpMetod'      => $_POST['http_metod'] ?? 'GET',
                    'APIEndpointler_ParametreOrnek' => $_POST['parametre_ornek'] ?? null,
                    'APIEndpointler_YanitOrnek'     => $_POST['yanit_ornek'] ?? null,
                    'Durum'                           => isset($_POST['durum']) ? 1 : 0,
                ];

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->update('APIEndpointler', $data, ['APIEndpointler_Id' => $id]);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Endpoint başarıyla güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['OlusturanKullanici'] = $user['kullanici_id'];
                    $data['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']    = date('Y-m-d H:i:s');
                    $result = $db->insert('APIEndpointler', $data);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Endpoint başarıyla eklendi' : 'Ekleme hatası']);
                }
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id = (int)($_POST['id'] ?? 0);
                $result = $db->delete('APIEndpointler', ['APIEndpointler_Id' => $id]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Endpoint başarıyla silindi' : 'Silme hatası']);
                break;

            case 'stats':
                $stats = [
                    'toplam'   => $db->fetchOne("SELECT COUNT(*) as c FROM APIEndpointler")['c'] ?? 0,
                    'aktif'    => $db->fetchOne("SELECT COUNT(*) as c FROM APIEndpointler WHERE Durum = 1")['c'] ?? 0,
                    'pasif'    => $db->fetchOne("SELECT COUNT(*) as c FROM APIEndpointler WHERE Durum = 0")['c'] ?? 0,
                    'kategori' => $db->fetchOne("SELECT COUNT(DISTINCT APIEndpointler_Kategori) as c FROM APIEndpointler WHERE APIEndpointler_Kategori IS NOT NULL AND APIEndpointler_Kategori <> ''")['c'] ?? 0,
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
        .status-badge { padding: .25rem .5rem; border-radius: .25rem; font-size: .875rem; }
        .status-active   { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .badge-metod { font-size: .75rem; font-weight: 600; padding: .3rem .55rem; border-radius: .3rem; }
        .metod-GET    { background: #d1ecf1; color: #0c5460; }
        .metod-POST   { background: #d4edda; color: #155724; }
        .metod-PUT    { background: #fff3cd; color: #856404; }
        .metod-PATCH  { background: #e2d9f3; color: #432874; }
        .metod-DELETE { background: #f8d7da; color: #721c24; }
        .endpoint-text { font-family: monospace; font-size: .85rem; word-break: break-all; }
        pre.json-preview { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: .375rem; padding: .75rem; font-size: .8rem; max-height: 200px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; }
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
                            <span class="info-box-icon"><i class="bi bi-hdd-network"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Endpoint</span>
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
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Pasif</span>
                                <span class="info-box-number" id="stat-pasif">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-tags"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Kategori</span>
                                <span class="info-box-number" id="stat-kategori">0</span>
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
                                    <input type="text" class="form-control" name="search" id="filter_search" placeholder="Endpoint veya açıklama...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Kategori</label>
                                    <select class="form-select select2" name="kategori" id="filter_kategori">
                                        <option value="">Tümü</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">HTTP Metod</label>
                                    <select class="form-select select2" name="metod" id="filter_metod">
                                        <option value="">Tümü</option>
                                        <?php foreach ($httpMetodlar as $m): ?>
                                        <option value="<?= $m ?>"><?= $m ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
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
                        <h3 class="card-title">Endpoint Listesi</h3>
                        <div class="card-tools">
                            <?php if ($permissions['can_add']): ?>
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#endpointModal" onclick="resetForm()">
                                <i class="bi bi-plus-circle"></i> Yeni Endpoint Ekle
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="endpointTable" class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <th style="width:60px">#</th>
                                    <th>Kategori</th>
                                    <th>Metod</th>
                                    <th>Endpoint</th>
                                    <th>Açıklama</th>
                                    <th>Durum</th>
                                    <th>Oluşturan</th>
                                    <th style="width:90px">İşlemler</th>
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

<!-- Modal -->
<div class="modal fade" id="endpointModal" tabindex="-1" aria-labelledby="endpointModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="endpointModalLabel">Yeni Endpoint Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="endpointForm">
                <div class="modal-body">
                    <input type="hidden" id="rec_id" name="id">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Kategori <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="kategori" name="kategori" list="kategoriList" required placeholder="Ör: Abone, İçerik, Ödeme...">
                            <datalist id="kategoriList"></datalist>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">HTTP Metod <span class="text-danger">*</span></label>
                            <select class="form-select select2" id="http_metod" name="http_metod" required>
                                <?php foreach ($httpMetodlar as $m): ?>
                                <option value="<?= $m ?>"><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Endpoint <span class="text-danger">*</span></label>
                            <input type="text" class="form-control font-monospace" id="endpoint" name="endpoint" required placeholder="/api/v1/subscribers/{id}">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" id="aciklama" name="aciklama" rows="2" placeholder="Endpoint'in ne işe yaradığını kısaca açıklayın..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Parametre Örneği <small class="text-muted">(JSON)</small></label>
                            <textarea class="form-control font-monospace" id="parametre_ornek" name="parametre_ornek" rows="6" placeholder='{"key": "value"}'></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Yanıt Örneği <small class="text-muted">(JSON)</small></label>
                            <textarea class="form-control font-monospace" id="yanit_ornek" name="yanit_ornek" rows="6" placeholder='{"status": "ok", "data": {}}'></textarea>
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

    const metodRenk = { GET:'metod-GET', POST:'metod-POST', PUT:'metod-PUT', PATCH:'metod-PATCH', DELETE:'metod-DELETE' };

    let endpointModal, dataTable, currentFilters = {};

    function formatDate(v) {
        if (!v) return '-';
        try {
            if (typeof v === 'object' && v.date) v = v.date;
            const d = new Date(String(v).replace(' ', 'T'));
            if (isNaN(d)) return '-';
            return d.toLocaleDateString('tr-TR', { year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit' });
        } catch { return '-'; }
    }

    $(document).ready(function () {
        endpointModal = new bootstrap.Modal(document.getElementById('endpointModal'));

        dataTable = $('#endpointTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc']],
            columnDefs: [{ orderable: false, targets: [7] }],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        });

        loadStats();
        loadKategoriler();
        loadList();

        $('#endpointForm').on('submit', function (e) {
            e.preventDefault();
            saveRecord();
        });

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            currentFilters = {
                search:   $('#filter_search').val(),
                kategori: $('#filter_kategori').val(),
                metod:    $('#filter_metod').val(),
                status:   $('#filter_status').val()
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

        // Select2 init
        $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });
        $('#http_metod').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#endpointModal') });

        // Modal kapanınca Select2 yenile
        document.getElementById('endpointModal').addEventListener('hidden.bs.modal', function () {
            resetForm();
        });
    });

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-aktif').text(r.data.aktif);
            $('#stat-pasif').text(r.data.pasif);
            $('#stat-kategori').text(r.data.kategori);
        });
    }

    function loadKategoriler() {
        $.post('', { action: 'kategoriler' }, function (r) {
            if (!r.success) return;
            const $filterSel = $('#filter_kategori');
            const $datalist  = $('#kategoriList');
            $filterSel.find('option:not(:first)').remove();
            $datalist.empty();
            r.data.forEach(k => {
                $filterSel.append(`<option value="${k}">${k}</option>`);
                $datalist.append(`<option value="${k}">`);
            });
            $filterSel.trigger('change');
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
            const metodClass = metodRenk[row.APIEndpointler_HttpMetod] || '';
            const metod   = `<span class="badge-metod ${metodClass}">${row.APIEndpointler_HttpMetod}</span>`;
            const durum   = row.Durum == 1
                ? '<span class="status-badge status-active">Aktif</span>'
                : '<span class="status-badge status-inactive">Pasif</span>';
            const endpoint = `<span class="endpoint-text">${escapeHtml(row.APIEndpointler_Endpoint)}</span>`;
            const aciklama = row.APIEndpointler_Aciklama
                ? (row.APIEndpointler_Aciklama.length > 60
                    ? escapeHtml(row.APIEndpointler_Aciklama.substring(0, 60)) + '…'
                    : escapeHtml(row.APIEndpointler_Aciklama))
                : '-';

            let islemler = '';
            if (permissions.canEdit)
                islemler += `<button class="btn btn-sm btn-warning me-1" onclick="editRecord(${row.APIEndpointler_Id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
            if (permissions.canDelete)
                islemler += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${row.APIEndpointler_Id})" title="Sil"><i class="bi bi-trash"></i></button>`;
            if (!islemler) islemler = '<span class="text-muted">-</span>';

            dataTable.row.add([
                row.APIEndpointler_Id,
                escapeHtml(row.APIEndpointler_Kategori || '-'),
                metod,
                endpoint,
                aciklama,
                durum,
                escapeHtml(row.OlusturanAd || '-'),
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
        document.getElementById('endpointForm').reset();
        document.getElementById('rec_id').value = '';
        document.getElementById('endpointModalLabel').textContent = 'Yeni Endpoint Ekle';
        $('#http_metod').val('GET').trigger('change');
        document.getElementById('durum').checked = true;
    }

    function saveRecord() {
        const formData = new FormData(document.getElementById('endpointForm'));
        formData.append('action', 'save');

        $.ajax({
            url: '', method: 'POST',
            data: formData, processData: false, contentType: false, dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showToast(r.message, 'success');
                    endpointModal.hide();
                    loadList();
                    loadStats();
                    loadKategoriler();
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
                document.getElementById('rec_id').value          = d.APIEndpointler_Id;
                document.getElementById('kategori').value        = d.APIEndpointler_Kategori || '';
                document.getElementById('endpoint').value        = d.APIEndpointler_Endpoint || '';
                document.getElementById('aciklama').value        = d.APIEndpointler_Aciklama || '';
                document.getElementById('parametre_ornek').value = d.APIEndpointler_ParametreOrnek || '';
                document.getElementById('yanit_ornek').value     = d.APIEndpointler_YanitOrnek || '';
                document.getElementById('durum').checked         = d.Durum == 1;
                $('#http_metod').val(d.APIEndpointler_HttpMetod || 'GET').trigger('change');
                document.getElementById('endpointModalLabel').textContent = 'Endpoint Düzenle';
                endpointModal.show();
            },
            error: function () { showToast('Kayıt yüklenirken hata oluştu', 'error'); }
        });
    }

    function deleteRecord(id) {
        confirmAction(
            'Bu endpoint\'i silmek istediğinize emin misiniz?',
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
                            loadKategoriler();
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
