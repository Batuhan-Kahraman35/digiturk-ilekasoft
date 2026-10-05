<?php
/**
 * Admin Panel - Hakediş Kampanya Tanımlama
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Hakediş Kampanya Tanımlama';
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
                    $where[] = "(t.HakedisKampanyaTanimlari_KampanyaAdi LIKE ? OR t.HakedisKampanyaTanimlari_TalepTuru LIKE ? OR t.HakedisKampanyaTanimlari_MemoKodu LIKE ? OR t.HakedisKampanyaTanimlari_Kampanya LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
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
                        t.HakedisKampanyaTanimlari_id,
                        t.HakedisKampanyaTanimlari_KampanyaAdi,
                        t.HakedisKampanyaTanimlari_TalepTuru,
                        t.HakedisKampanyaTanimlari_MemoKodu,
                        t.HakedisKampanyaTanimlari_Kampanya,
                        t.Durum,
                        CONVERT(VARCHAR(19), t.GuncellemeTarihi, 120) as GuncellemeTarihi,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as GuncelleyenAd
                    FROM HakedisKampanyaTanimlari t
                    LEFT JOIN kullanicilar k ON t.GuncelleyenKullanici = k.kullanici_id
                    WHERE $whereClause
                    ORDER BY t.HakedisKampanyaTanimlari_KampanyaAdi
                ", $params);

                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get':
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("SELECT * FROM HakedisKampanyaTanimlari WHERE HakedisKampanyaTanimlari_id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $row]);
                break;

            case 'options':
                // İlk yükleme: sadece Talep Türü listesi
                $talep = $db->fetchAll("
                    SELECT DISTINCT IrisRapor_TalepTuru AS v
                    FROM dbo.DigiturkIrisRapor
                    WHERE IrisRapor_TalepTuru IS NOT NULL AND LTRIM(RTRIM(IrisRapor_TalepTuru)) <> ''
                    ORDER BY IrisRapor_TalepTuru
                ");
                echo json_encode(['success' => true, 'data' => [
                    'talep_turu' => array_column($talep, 'v'),
                ]]);
                break;

            case 'dependent_options':
                // Seçilen Talep Türü'ne bağlı Memo Kodu ve Kampanya listeleri
                $talepTuru = trim($_POST['talep_turu'] ?? '');
                if ($talepTuru === '') {
                    echo json_encode(['success' => true, 'data' => ['memo_kodu' => [], 'kampanya' => []]]);
                    break;
                }
                $memo = $db->fetchAll("
                    SELECT DISTINCT IrisRapor_MemoKodu AS v
                    FROM dbo.DigiturkIrisRapor
                    WHERE IrisRapor_TalepTuru = ?
                      AND IrisRapor_MemoKodu IS NOT NULL AND LTRIM(RTRIM(IrisRapor_MemoKodu)) <> ''
                    ORDER BY IrisRapor_MemoKodu
                ", [$talepTuru]);
                $kampanya = $db->fetchAll("
                    SELECT DISTINCT IrisRapor_Kampanya AS v
                    FROM dbo.DigiturkIrisRapor
                    WHERE IrisRapor_TalepTuru = ?
                      AND IrisRapor_Kampanya IS NOT NULL AND LTRIM(RTRIM(IrisRapor_Kampanya)) <> ''
                    ORDER BY IrisRapor_Kampanya
                ", [$talepTuru]);
                // Bu talep türü için daha önce bir kampanya tanımında kaydedilmiş memo kodları
                $kayitliMemo = $db->fetchAll("
                    SELECT DISTINCT HakedisKampanyaTanimlari_MemoKodu AS v
                    FROM dbo.HakedisKampanyaTanimlari
                    WHERE HakedisKampanyaTanimlari_TalepTuru = ?
                      AND HakedisKampanyaTanimlari_MemoKodu IS NOT NULL
                      AND Durum = 1
                ", [$talepTuru]);
                echo json_encode(['success' => true, 'data' => [
                    'memo_kodu'       => array_column($memo, 'v'),
                    'kampanya'        => array_column($kampanya, 'v'),
                    'kayitli_memolar' => array_column($kayitliMemo, 'v'),
                ]]);
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
                    'HakedisKampanyaTanimlari_KampanyaAdi' => trim($_POST['kampanya_adi'] ?? ''),
                    'HakedisKampanyaTanimlari_TalepTuru'   => trim($_POST['talep_turu'] ?? '') ?: null,
                    'HakedisKampanyaTanimlari_MemoKodu'    => trim($_POST['memo_kodu'] ?? '') ?: null,
                    'HakedisKampanyaTanimlari_Kampanya'    => trim($_POST['kampanya'] ?? '') ?: null,
                    'Durum'                                => isset($_POST['durum']) ? 1 : 0,
                ];

                if (empty($data['HakedisKampanyaTanimlari_KampanyaAdi'])) {
                    echo json_encode(['success' => false, 'message' => 'Kampanya adı zorunludur!']);
                    break;
                }
                if (empty($data['HakedisKampanyaTanimlari_TalepTuru'])) {
                    echo json_encode(['success' => false, 'message' => 'Talep türü zorunludur!']);
                    break;
                }
                if (empty($data['HakedisKampanyaTanimlari_MemoKodu'])) {
                    echo json_encode(['success' => false, 'message' => 'Memo kodu zorunludur!']);
                    break;
                }

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->update('HakedisKampanyaTanimlari', $data, ['HakedisKampanyaTanimlari_id' => $id]);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['OlusturanKullanici']   = $user['kullanici_id'];
                    $data['OlusturmaTarihi']      = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->insert('HakedisKampanyaTanimlari', $data);
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
                $result = $db->update('HakedisKampanyaTanimlari', [
                    'Durum'                => $durum,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['HakedisKampanyaTanimlari_id' => $id]);
                echo json_encode(['success' => (bool)$result]);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id     = (int)($_POST['id'] ?? 0);
                $result = $db->delete('HakedisKampanyaTanimlari', ['HakedisKampanyaTanimlari_id' => $id]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt silindi' : 'Silme hatası']);
                break;

            case 'stats':
                $stats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as c FROM HakedisKampanyaTanimlari")['c'] ?? 0,
                    'aktif'  => $db->fetchOne("SELECT COUNT(*) as c FROM HakedisKampanyaTanimlari WHERE Durum = 1")['c'] ?? 0,
                    'pasif'  => $db->fetchOne("SELECT COUNT(*) as c FROM HakedisKampanyaTanimlari WHERE Durum = 0")['c'] ?? 0,
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
        .kod-badge { font-family: monospace; font-weight: 600; font-size: .85rem; background: #e9ecef; padding: .2rem .5rem; border-radius: .3rem; }
        /* Daha önce eklenmiş memo kodları (Select2 seçenek + seçili gösterim) */
        .memo-kayitli { color: #198754; font-weight: 600; }
        .select2-results__option--highlighted .memo-kayitli { color: #eafaf0; }
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
                                    <input type="text" class="form-control" name="search" id="filter_search" placeholder="Kampanya adı, talep türü, memo kodu...">
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
                        <h3 class="card-title">Hakediş Kampanyaları</h3>
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
                                    <th>Kampanya Adı</th>
                                    <th>Talep Türü</th>
                                    <th>Memo Kodu</th>
                                    <th>Kampanya</th>
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
                <h5 class="modal-title" id="kayitModalLabel">Yeni Kayıt Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kayitForm">
                <div class="modal-body">
                    <input type="hidden" id="rec_id" name="id">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Kampanya Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="kampanya_adi" name="kampanya_adi" required placeholder="Ör: Ocak Uydu Kampanyası">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Talep Türü <span class="text-danger">*</span></label>
                            <select class="form-select" id="talep_turu" name="talep_turu" required>
                                <option value="">— Seçin —</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Memo Kodu <span class="text-danger">*</span></label>
                            <select class="form-select" id="memo_kodu" name="memo_kodu" required>
                                <option value="">— Seçin —</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kampanya</label>
                            <select class="form-select" id="kampanya" name="kampanya">
                                <option value="">— Seçin —</option>
                            </select>
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
    let optionsCache = { talep_turu: [], memo_kodu: [], kampanya: [] };
    let kayitliMemolar = []; // seçili talep türü için daha önce eklenmiş memo kodları

    $(document).ready(function () {
        kayitModal = new bootstrap.Modal(document.getElementById('kayitModal'));

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc']],
            columnDefs: [{ orderable: false, targets: [6] }],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        });

        loadStats();
        loadList();
        loadOptions();

        // Talep Türü değişince (kullanıcı seçimi) bağlı Memo/Kampanya'yı yeniden yükle
        $('#talep_turu').on('change', function () {
            const t = $(this).val();
            if (!t) {
                optionsCache.memo_kodu = [];
                optionsCache.kampanya  = [];
                fillModalSelect('memo_kodu', '');
                fillModalSelect('kampanya', '');
                return;
            }
            loadDependentOptions(t, '', '');
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
            $('#filter_status').val('').trigger('change');
            currentFilters = {};
            loadList();
            showToast('Filtreler temizlendi', 'info');
        });

        document.getElementById('kayitModal').addEventListener('hidden.bs.modal', resetForm);
    });

    function loadOptions() {
        $.post('', { action: 'options' }, function (r) {
            if (!r.success) return;
            optionsCache.talep_turu = r.data.talep_turu || [];
            // İlk kurulum: Talep Türü dolu, Memo/Kampanya bağımlı olduğu için boş
            fillModalSelect('talep_turu', '');
            fillModalSelect('memo_kodu', '');
            fillModalSelect('kampanya', '');
        }, 'json');
    }

    // Seçilen Talep Türü'ne bağlı Memo Kodu ve Kampanya listelerini yükle
    function loadDependentOptions(talepTuru, memoSelected, kampanyaSelected, cb) {
        $.post('', { action: 'dependent_options', talep_turu: talepTuru }, function (r) {
            optionsCache.memo_kodu = (r.success && r.data.memo_kodu) ? r.data.memo_kodu : [];
            optionsCache.kampanya  = (r.success && r.data.kampanya)  ? r.data.kampanya  : [];
            kayitliMemolar         = (r.success && r.data.kayitli_memolar) ? r.data.kayitli_memolar : [];
            fillModalSelect('memo_kodu', memoSelected || '');
            fillModalSelect('kampanya',  kampanyaSelected || '');
            if (cb) cb();
        }, 'json');
    }

    // Modal içindeki select'i doldurup Select2 kur.
    // Çift init'e karşı önce destroy, dropdownParent modal olacak (arama kutusu focus alsın).
    function fillModalSelect(id, selected) {
        const $el = $('#' + id);
        if ($el.hasClass('select2-hidden-accessible')) $el.select2('destroy');

        const isMemo = (id === 'memo_kodu');
        const mark = v => (isMemo && kayitliMemolar.includes(v)) ? ' data-kayitli="1"' : '';

        let html = '<option value="">— Seçin —</option>';
        (optionsCache[id] || []).forEach(v => {
            html += `<option value="${escapeHtml(v)}"${mark(v)}>${escapeHtml(v)}</option>`;
        });
        // Kayıttaki değer listede yoksa (eski/silinmiş) yine de göster
        if (selected && !(optionsCache[id] || []).includes(selected)) {
            html += `<option value="${escapeHtml(selected)}"${mark(selected)}>${escapeHtml(selected)}</option>`;
        }
        $el.html(html).val(selected || '');

        const opts = {
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: '— Seçin —',
            allowClear: true,
            dropdownParent: $('#kayitModal')
        };
        if (isMemo) {
            opts.templateResult    = formatMemoOption;
            opts.templateSelection = formatMemoOption;
        }
        $el.select2(opts);
        $el.trigger('change.select2');
    }

    // Daha önce bir kampanya tanımında kaydedilmiş memo kodlarını farklı renkte göster
    function formatMemoOption(state) {
        if (!state.id) return state.text;
        const $span = $('<span></span>').text(state.text);
        if ($(state.element).data('kayitli') == 1) {
            $span.addClass('memo-kayitli').attr('title', 'Bu memo kodu daha önce eklendi');
        }
        return $span;
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
            const kampanyaAdi = escapeHtml(row.HakedisKampanyaTanimlari_KampanyaAdi || '-');
            const talepTuru   = escapeHtml(row.HakedisKampanyaTanimlari_TalepTuru || '-');
            const kampanya    = escapeHtml(row.HakedisKampanyaTanimlari_Kampanya || '-');

            const memoKodu = row.HakedisKampanyaTanimlari_MemoKodu
                ? `<span class="kod-badge">${escapeHtml(row.HakedisKampanyaTanimlari_MemoKodu)}</span>`
                : '<span class="text-muted">-</span>';

            const durum = row.Durum == 1
                ? `<span class="status-badge status-active" style="cursor:pointer" onclick="toggleDurum(${row.HakedisKampanyaTanimlari_id}, 0)" title="Pasife al">Aktif</span>`
                : `<span class="status-badge status-inactive" style="cursor:pointer" onclick="toggleDurum(${row.HakedisKampanyaTanimlari_id}, 1)" title="Aktife al">Pasif</span>`;

            let islemler = '';
            if (permissions.canEdit)
                islemler += `<button class="btn btn-sm btn-warning me-1" onclick="editRecord(${row.HakedisKampanyaTanimlari_id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
            if (permissions.canDelete)
                islemler += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${row.HakedisKampanyaTanimlari_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
            if (!islemler) islemler = '<span class="text-muted">-</span>';

            dataTable.row.add([
                kampanyaAdi,
                talepTuru,
                memoKodu,
                kampanya,
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
        optionsCache.memo_kodu = [];
        optionsCache.kampanya  = [];
        fillModalSelect('talep_turu', '');
        fillModalSelect('memo_kodu', '');
        fillModalSelect('kampanya', '');
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
                document.getElementById('rec_id').value       = d.HakedisKampanyaTanimlari_id;
                document.getElementById('kampanya_adi').value = d.HakedisKampanyaTanimlari_KampanyaAdi || '';
                document.getElementById('durum').checked      = d.Durum == 1;
                document.getElementById('kayitModalLabel').textContent = 'Kayıt Düzenle';

                // Önce Talep Türü'nü kur, sonra ona bağlı Memo/Kampanya'yı seçili değerlerle yükle
                fillModalSelect('talep_turu', d.HakedisKampanyaTanimlari_TalepTuru || '');
                loadDependentOptions(
                    d.HakedisKampanyaTanimlari_TalepTuru || '',
                    d.HakedisKampanyaTanimlari_MemoKodu || '',
                    d.HakedisKampanyaTanimlari_Kampanya || '',
                    function () { kayitModal.show(); }
                );
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
