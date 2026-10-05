<?php
/**
 * Admin Panel - CallCenter Kampanyalar
 * Esdisis kampanyalarını (/api/campaign) çekip yönetir.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/CallCenterHelper.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Kampanyalar';
$menuAdi   = $pageinfo['menu_adi'] ?? 'CallCenter';

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
                $birim  = $_POST['birim']  ?? '';

                $where  = ["1=1"];
                $params = [];

                if ($search) {
                    $where[] = "(t.CallCenterKampanyalar_Baslik LIKE ? OR CAST(t.CallCenterKampanyalar_KaynakId AS VARCHAR(20)) LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($status !== '') {
                    $where[] = "t.Durum = ?";
                    $params[] = $status;
                }
                if ($birim !== '') {
                    $where[] = "EXISTS (
                        SELECT 1 FROM KullaniciBirimYetkileri kby
                        WHERE kby.KullaniciBirimYetkileri_CallCenterKampanya_id = t.CallCenterKampanyalar_id
                          AND kby.KullaniciBirimYetkileri_Birim_id = ?
                          AND kby.Durum = 1
                    )";
                    $params[] = $birim;
                }

                $whereClause = implode(" AND ", $where);

                $list = $db->fetchAll("
                    SELECT
                        t.CallCenterKampanyalar_id,
                        t.CallCenterKampanyalar_KaynakId,
                        t.CallCenterKampanyalar_Baslik,
                        t.CallCenterKampanyalar_ElleIskontoIzni,
                        t.CallCenterKampanyalar_UrunZorunlu,
                        t.Durum,
                        (SELECT STRING_AGG(b.KullaniciBirim_Adi, ', ')
                         FROM KullaniciBirimYetkileri kby
                         JOIN KullaniciBirim b ON kby.KullaniciBirimYetkileri_Birim_id = b.KullaniciBirim_id
                         WHERE kby.KullaniciBirimYetkileri_CallCenterKampanya_id = t.CallCenterKampanyalar_id
                           AND kby.Durum = 1) as Birimler,
                        CONVERT(VARCHAR(19), t.GuncellemeTarihi, 120) as GuncellemeTarihi
                    FROM CallCenterKampanyalar t
                    WHERE $whereClause
                    ORDER BY t.CallCenterKampanyalar_KaynakId
                ", $params);

                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get':
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("SELECT * FROM CallCenterKampanyalar WHERE CallCenterKampanyalar_id = ?", [$id]);
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
                    'CallCenterKampanyalar_KaynakId'        => (int)($_POST['kaynak_id'] ?? 0),
                    'CallCenterKampanyalar_Baslik'          => trim($_POST['baslik'] ?? ''),
                    'CallCenterKampanyalar_ElleIskontoIzni' => isset($_POST['elle_iskonto']) ? 1 : 0,
                    'CallCenterKampanyalar_UrunZorunlu'     => isset($_POST['urun_zorunlu']) ? 1 : 0,
                    'Durum'                                 => isset($_POST['durum']) ? 1 : 0,
                ];

                if ($data['CallCenterKampanyalar_KaynakId'] <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir Campaign ID giriniz!']);
                    break;
                }

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->update('CallCenterKampanyalar', $data, ['CallCenterKampanyalar_id' => $id]);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['OlusturanKullanici']   = $user['kullanici_id'];
                    $data['OlusturmaTarihi']      = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->insert('CallCenterKampanyalar', $data);
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
                $result = $db->update('CallCenterKampanyalar', [
                    'Durum'                => $durum,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['CallCenterKampanyalar_id' => $id]);
                echo json_encode(['success' => (bool)$result]);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id = (int)($_POST['id'] ?? 0);
                $result = $db->delete('CallCenterKampanyalar', ['CallCenterKampanyalar_id' => $id]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt silindi' : 'Silme hatası']);
                break;

            case 'stats':
                $stats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as c FROM CallCenterKampanyalar")['c'] ?? 0,
                    'aktif'  => $db->fetchOne("SELECT COUNT(*) as c FROM CallCenterKampanyalar WHERE Durum = 1")['c'] ?? 0,
                    'pasif'  => $db->fetchOne("SELECT COUNT(*) as c FROM CallCenterKampanyalar WHERE Durum = 0")['c'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            case 'sync':
                if (!$permissions['can_add'] && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Güncelleme yetkiniz yok!']);
                    break;
                }

                $sonuc = CallCenterHelper::kampanyalar(80);
                if (!$sonuc['success']) {
                    echo json_encode(['success' => false, 'message' => $sonuc['message']]);
                    break;
                }

                $eklenen = 0; $guncellenen = 0;

                foreach ($sonuc['records'] as $rec) {
                    $kaynakId = (int)$rec['id'];
                    $baslik   = $rec['title'] ?? '';
                    $iskonto  = (isset($rec['allow_users_manually_discounts']) && (string)$rec['allow_users_manually_discounts'] === '1') ? 1 : 0;
                    $urun     = (isset($rec['sale_product_required']) && (string)$rec['sale_product_required'] === '1') ? 1 : 0;
                    $aktif    = empty($rec['deleted_at']) ? 1 : 0;

                    $mevcut = $db->fetchOne(
                        "SELECT CallCenterKampanyalar_id FROM CallCenterKampanyalar WHERE CallCenterKampanyalar_KaynakId = ?",
                        [$kaynakId]
                    );

                    if ($mevcut) {
                        $db->update('CallCenterKampanyalar', [
                            'CallCenterKampanyalar_Baslik'          => $baslik,
                            'CallCenterKampanyalar_ElleIskontoIzni' => $iskonto,
                            'CallCenterKampanyalar_UrunZorunlu'     => $urun,
                            'Durum'                                 => $aktif,
                            'GuncelleyenKullanici'                  => $user['kullanici_id'],
                            'GuncellemeTarihi'                      => date('Y-m-d H:i:s'),
                        ], ['CallCenterKampanyalar_id' => $mevcut['CallCenterKampanyalar_id']]);
                        $guncellenen++;
                    } else {
                        $db->insert('CallCenterKampanyalar', [
                            'CallCenterKampanyalar_KaynakId'        => $kaynakId,
                            'CallCenterKampanyalar_Baslik'          => $baslik,
                            'CallCenterKampanyalar_ElleIskontoIzni' => $iskonto,
                            'CallCenterKampanyalar_UrunZorunlu'     => $urun,
                            'Durum'                                 => $aktif,
                            'OlusturanKullanici'                    => $user['kullanici_id'],
                            'OlusturmaTarihi'                       => date('Y-m-d H:i:s'),
                            'GuncelleyenKullanici'                  => $user['kullanici_id'],
                            'GuncellemeTarihi'                      => date('Y-m-d H:i:s'),
                        ]);
                        $eklenen++;
                    }
                }

                echo json_encode([
                    'success' => true,
                    'message' => "Senkron tamamlandı: {$eklenen} yeni, {$guncellenen} güncellendi.",
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

// Filtre için birim listesi (sayfa render)
$birimler = $db->fetchAll("
    SELECT KullaniciBirim_id, KullaniciBirim_Adi
    FROM KullaniciBirim
    WHERE Durum = 1
    ORDER BY KullaniciBirim_Adi
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
        .status-badge  { padding: .25rem .5rem; border-radius: .25rem; font-size: .875rem; }
        .status-active   { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; }
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
                            <span class="info-box-icon"><i class="bi bi-megaphone"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Kampanya</span>
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
                                    <input type="text" class="form-control" name="search" id="filter_search" placeholder="Kampanya adı veya ID...">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Birim</label>
                                    <select class="form-select" name="birim" id="filter_birim">
                                        <option value="">Tümü</option>
                                        <?php foreach ($birimler as $b): ?>
                                        <option value="<?= (int)$b['KullaniciBirim_id'] ?>"><?= htmlspecialchars($b['KullaniciBirim_Adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
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
                        <h3 class="card-title">Kampanyalar</h3>
                        <div class="card-tools">
                            <?php if ($permissions['can_add'] || $permissions['can_edit']): ?>
                            <button type="button" class="btn btn-success btn-sm" id="btnSync">
                                <i class="bi bi-cloud-download"></i> API'den Güncelle
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Campaign ID</th>
                                    <th>Kampanya Adı</th>
                                    <th>Birim</th>
                                    <th>Ürün Zorunlu</th>
                                    <th>Elle İskonto</th>
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
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kayitModalLabel">Kampanya Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kayitForm">
                <div class="modal-body">
                    <input type="hidden" id="rec_id" name="id">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Campaign ID <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="kaynak_id" name="kaynak_id" required min="1" placeholder="Ör: 3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kampanya Adı</label>
                            <input type="text" class="form-control" id="baslik" name="baslik" placeholder="Ör: DigiBiz TV ve Internet">
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="urun_zorunlu" name="urun_zorunlu">
                                <label class="form-check-label" for="urun_zorunlu">Ürün Zorunlu</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="elle_iskonto" name="elle_iskonto">
                                <label class="form-check-label" for="elle_iskonto">Elle İskonto İzni</label>
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

    let kayitModal, dataTable, currentFilters = {};

    $(document).ready(function () {
        kayitModal = new bootstrap.Modal(document.getElementById('kayitModal'));

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc']],
            columnDefs: [{ orderable: false, targets: [7] }],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        });

        loadStats();
        loadList();

        $('#kayitForm').on('submit', function (e) {
            e.preventDefault();
            saveRecord();
        });

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            currentFilters = {
                search: $('#filter_search').val(),
                birim:  $('#filter_birim').val(),
                status: $('#filter_status').val()
            };
            Object.keys(currentFilters).forEach(k => { if (!currentFilters[k] && currentFilters[k] !== '0') delete currentFilters[k]; });
            loadList();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filterForm')[0].reset();
            $('#filter_birim, #filter_status').val('').trigger('change');
            currentFilters = {};
            loadList();
            showToast('Filtreler temizlendi', 'info');
        });

        document.getElementById('kayitModal').addEventListener('hidden.bs.modal', resetForm);

        $('#btnSync').on('click', syncFromApi);
    });

    function syncFromApi() {
        confirmAction(
            'API\'den güncellensin mi?',
            'CallCenter kampanyaları çekilip tabloya işlenecek.',
            function () {
                const $btn = $('#btnSync');
                const eski = $btn.html();
                $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Güncelleniyor...');
                $.ajax({
                    url: '', method: 'POST',
                    data: { action: 'sync' },
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
                    error: function () { showError('Bağlantı Hatası!', 'Senkron sırasında sunucuya ulaşılamadı.'); },
                    complete: function () { $btn.prop('disabled', false).html(eski); }
                });
            }
        );
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
            const campId = escapeHtml(row.CallCenterKampanyalar_KaynakId || '-');
            const baslik = escapeHtml(row.CallCenterKampanyalar_Baslik || '-');
            const birimler = row.Birimler
                ? escapeHtml(row.Birimler)
                : '<span class="text-muted">-</span>';

            const evetHayir = (v) => v == 1
                ? '<span class="badge text-bg-success">Evet</span>'
                : '<span class="badge text-bg-secondary">Hayır</span>';

            const durum = row.Durum == 1
                ? `<span class="status-badge status-active" style="cursor:pointer" onclick="toggleDurum(${row.CallCenterKampanyalar_id}, 0)" title="Pasife al">Aktif</span>`
                : `<span class="status-badge status-inactive" style="cursor:pointer" onclick="toggleDurum(${row.CallCenterKampanyalar_id}, 1)" title="Aktife al">Pasif</span>`;

            let islemler = '';
            if (permissions.canEdit)
                islemler += `<button class="btn btn-sm btn-warning me-1" onclick="editRecord(${row.CallCenterKampanyalar_id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
            if (permissions.canDelete)
                islemler += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${row.CallCenterKampanyalar_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
            if (!islemler) islemler = '<span class="text-muted">-</span>';

            dataTable.row.add([
                `<span class="mono">${campId}</span>`,
                baslik,
                birimler,
                evetHayir(row.CallCenterKampanyalar_UrunZorunlu),
                evetHayir(row.CallCenterKampanyalar_ElleIskontoIzni),
                durum,
                escapeHtml(row.GuncellemeTarihi || '-'),
                islemler
            ]);
        });
        dataTable.draw();
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function resetForm() {
        document.getElementById('kayitForm').reset();
        document.getElementById('rec_id').value = '';
        document.getElementById('kayitModalLabel').textContent = 'Kampanya Düzenle';
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
                document.getElementById('rec_id').value      = d.CallCenterKampanyalar_id;
                document.getElementById('kaynak_id').value   = d.CallCenterKampanyalar_KaynakId || '';
                document.getElementById('baslik').value      = d.CallCenterKampanyalar_Baslik || '';
                document.getElementById('urun_zorunlu').checked = d.CallCenterKampanyalar_UrunZorunlu == 1;
                document.getElementById('elle_iskonto').checked = d.CallCenterKampanyalar_ElleIskontoIzni == 1;
                document.getElementById('durum').checked     = d.Durum == 1;
                document.getElementById('kayitModalLabel').textContent = 'Kampanya Düzenle';
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
