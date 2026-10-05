<?php
/**
 * Admin Panel - Departman Yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Mevcut sayfanın bilgilerini al
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Departman Yönetimi';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// Sayfa yetkilerini kontrol et
$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

// Erişim Yetkisi yoksa hata sayfası göster
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim Yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'list':
                // Filtreleri al
                $search = $_POST['search'] ?? '';
                $status = $_POST['status'] ?? '';
                
                // WHERE koşulları
                $whereConditions = ["1=1"];
                $params = [];
                
                if ($search) {
                    $whereConditions[] = "(d.departman_adi LIKE ? OR d.departman_kod LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                if ($status !== '') {
                    $whereConditions[] = "d.departman_durum = ?";
                    $params[] = $status;
                }
                
                $whereClause = implode(" AND ", $whereConditions);
                
                $departmanList = $db->fetchAll("
                    SELECT 
                        d.departman_id,
                        d.departman_adi,
                        d.departman_kod,
                        d.departman_aciklama,
                        d.departman_durum,
                        d.departman_personel,
                        CONVERT(VARCHAR(19), d.departman_olusturma_tarihi, 120) as departman_olusturma_tarihi,
                        ko.kullanici_ad + ' ' + ko.kullanici_soyad as olusturan_kullanici,
                        CONVERT(VARCHAR(19), d.departman_guncelleme_tarihi, 120) as departman_guncelleme_tarihi
                    FROM kullanici_Departmanlar d
                    LEFT JOIN kullanicilar ko ON d.departman_olusturan_kullanici_id = ko.kullanici_id
                    WHERE $whereClause
                    ORDER BY d.departman_adi
                ", $params);
                echo json_encode(['success' => true, 'data' => $departmanList]);
                break;
                
            case 'get':
                $departmanid = $_POST['departman_id'] ?? 0;
                $departman = $db->fetchOne("SELECT * FROM kullanici_Departmanlar WHERE departman_id = ?", [$departmanid]);
                echo json_encode(['success' => true, 'data' => $departman]);
                break;
                
            case 'save':
                $departmanid = $_POST['departman_id'] ?? 0;
                
                // Yetki kontrolü
                if ($departmanid > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($departmanid == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme Yetkiniz yok!']);
                    break;
                }
                
                $data = [
                    'departman_adi' => $_POST['departman_adi'] ?? '',
                    'departman_kod' => $_POST['departman_kod'] ?? null,
                    'departman_aciklama' => $_POST['departman_aciklama'] ?? null,
                    'departman_durum' => isset($_POST['departman_durum']) ? 1 : 0,
                    'departman_personel' => isset($_POST['departman_personel']) ? 1 : 0,
                ];
                
                if ($departmanid > 0) {
                    $data['departman_guncelleme_tarihi'] = date('Y-m-d H:i:s');
                    $data['departman_guncelleyen_kullanici_id'] = $user['kullanici_id'];
                    
                    $result = $db->update('kullanici_Departmanlar', $data, ['departman_id' => $departmanid]);
                    echo json_encode(['success' => $result, 'message' => $result ? 'Departman başarıyla güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['departman_olusturma_tarihi'] = date('Y-m-d H:i:s');
                    $data['departman_olusturan_kullanici_id'] = $user['kullanici_id'];
                    
                    $result = $db->insert('kullanici_Departmanlar', $data);
                    echo json_encode(['success' => $result, 'message' => $result ? 'Departman başarıyla eklendi' : 'Ekleme hatası']);
                }
                break;
                
            case 'delete':
                // Yetki kontrolü
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme Yetkiniz yok!']);
                    break;
                }
                
                $departmanid = $_POST['departman_id'] ?? 0;
                
                // Kullanım kontrolleri
                $checks = [];
                
                // Kullanıcı kontrolü
                $kullanicicount = $db->fetchOne("SELECT count(*) as count FROM kullanicilar WHERE kullanici_departman_id = ?", [$departmanid])['count'] ?? 0;
                if ($kullanicicount > 0) {
                    $checks[] = "$kullanicicount kullanıcı";
                }
                
                // Menü-Sayfa Yetki kontrolü
                $Yetkicount = $db->fetchOne("SELECT count(*) as count FROM Menu_SayfaYetkileri WHERE departman_id = ?", [$departmanid])['count'] ?? 0;
                if ($Yetkicount > 0) {
                    $checks[] = "$Yetkicount menü-sayfa yetkisi";
                }
                
                // Kullaniliyorsa silinemez
                if (!empty($checks)) {
                    $message = "Bu departman silinemez! Şu kayıtlarda kullanılıyor: " . implode(', ', $checks);
                    echo json_encode(['success' => false, 'message' => $message]);
                    break;
                }
                
                $result = $db->delete('kullanici_Departmanlar', ['departman_id' => $departmanid]);
                echo json_encode(['success' => true, 'message' => $result ? 'Departman başarıyla silindi' : 'Silme hatası']);
                break;
                
            case 'stats':
                $stats = [
                    'toplam' => $db->fetchOne("SELECT count(*) as count FROM kullanici_Departmanlar")['count'] ?? 0,
                    'aktif' => $db->fetchOne("SELECT count(*) as count FROM kullanici_Departmanlar WHERE departman_durum = 1")['count'] ?? 0,
                    'pasif' => $db->fetchOne("SELECT count(*) as count FROM kullanici_Departmanlar WHERE departman_durum = 0")['count'] ?? 0,
                    'yeni' => $db->fetchOne("SELECT count(*) as count FROM kullanici_Departmanlar WHERE DATEDIFF(day, departman_olusturma_tarihi, GETDATE()) <= 30")['count'] ?? 0
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    
    <style>
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.875rem;
        }
        .status-active { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
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
                                <span class="info-box-icon"><i class="bi bi-building"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Departman</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-success">
                                <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif Departman</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-danger">
                                <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Pasif Departman</span>
                                    <span class="info-box-number" id="stat-pasif">0</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-box text-bg-info">
                                <span class="info-box-icon"><i class="bi bi-plus-circle"></i></span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Son 30 Gün</span>
                                    <span class="info-box-number" id="stat-yeni">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtre Karti -->
                    <div class="card card-primary card-outline mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
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
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Departman adı veya kodu...">
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
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Filtrele
                                        </button>
                                        <button type="button" class="btn btn-secondary" id="clearfilters">
                                            <i class="bi bi-x-circle"></i> Temizle
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Ana içerik -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Departman Listesi</h3>
                            <div class="card-tools">
                                <?php if ($permissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#departmanModal" onclick="resetForm()">
                                    <i class="bi bi-plus-circle"></i> Yeni Departman Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="departmanTable" class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Departman Adı</th>
                                        <th>Departman Kodu</th>
                                        <th>Açıklama</th>
                                        <th>Tip</th>
                                        <th>Durum</th>
                                        <th>Oluşturma</th>
                                        <th>İşlemler</th>
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
    
    <!-- Departman Modal -->
    <div class="modal fade" id="departmanModal" tabindex="-1" aria-labelledby="departmanModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="departmanModalLabel">Yeni Departman Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="departmanForm">
                    <div class="modal-body">
                        <input type="hidden" id="departman_id" name="departman_id" value="">
                        
                        <div class="mb-3">
                            <label for="departman_adi" class="form-label">Departman Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="departman_adi" name="departman_adi" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="departman_kod" class="form-label">Departman Kodu</label>
                            <input type="text" class="form-control" id="departman_kod" name="departman_kod">
                        </div>
                        
                        <div class="mb-3">
                            <label for="departman_aciklama" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="departman_aciklama" name="departman_aciklama" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="departman_durum" name="departman_durum" checked>
                                <label class="form-check-label" for="departman_durum">Aktif</label>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="departman_personel" name="departman_personel" checked>
                                <label class="form-check-label" for="departman_personel">Personel (işaretli değilse Taşeron)</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> İptal
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Kaydet
                        </button>
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
        let departmanModal;
        let dataTable;
        
        function formatDate(datestring) {
            if (!datestring) return '-';
            
            try {
                if (typeof datestring === 'object' && datestring.date) {
                    datestring = datestring.date;
                }
                
                if (typeof datestring !== 'string') {
                    datestring = String(datestring);
                }
                
                const date = new Date(datestring.replace(' ', 'T'));
                
                if (isNaN(date.getTime())) {
                    return '-';
                }
                
                return date.toLocaleDateString('tr-TR', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                console.error('Tarih formatlama hatası:', datestring, e);
                return '-';
            }
        }
        
        // showToast() artik custom.js'den geliyor
        
        // Sayfa yetkileri (PHP'den)
        const permissions = {
            canAdd: <?= $permissions['can_add'] ? 'true' : 'false' ?>,
            canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?>,
            canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
        };
        
        let currentfilters = {};
        
        $(document).ready(function() {
            departmanModal = new bootstrap.Modal(document.getElementById('departmanModal'));
            
            dataTable = $('#departmanTable').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json'
                },
                order: [[0, 'asc']],
                columnDefs: [
                    { orderable: false, targets: [5] }
                ],
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tümü"]]
            });
            
            loadDepartmanList();
            loadStats();
            
            $('#departmanForm').on('submit', function(e) {
                e.preventDefault();
                saveDepartman();
            });
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                
                currentfilters = {
                    search: $('#filter_search').val(),
                    status: $('#filter_status').val()
                };
                
                Object.keys(currentfilters).forEach(key => {
                    if (!currentfilters[key]) delete currentfilters[key];
                });
                
                loadDepartmanList();
                showToast('Filtre uygulandı', 'info');
            });
            
            // Filtreleri temizle
            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_status').val('').trigger('change.select2');
                currentfilters = {};
                loadDepartmanList();
                showToast('Filtreler temizlendi', 'info');
            });
        });
        
        function loadStats() {
            $.post('', { action: 'stats' }, function(response) {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-pasif').text(response.data.pasif);
                    $('#stat-yeni').text(response.data.yeni);
                }
            });
        }
        
        function loadDepartmanList() {
            $.ajax({
                url: '',
                method: 'POST',
                data: { 
                    action: 'list',
                    ...currentfilters
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        renderDepartmanTable(data.data);
                    } else {
                        showToast('Liste yüklenirken hata oluştu', 'error');
                    }
                },
                error: function(error) {
                    showToast('Sunucu hatası oluştu', 'error');
                }
            });
        }
        
        function renderDepartmanTable(kullanici_Departmanlar) {
            dataTable.clear();
            
            kullanici_Departmanlar.forEach(dept => {
                const tipBadge = dept.departman_personel 
                    ? '<span class="badge bg-primary">Personel</span>' 
                    : '<span class="badge bg-warning">Taşeron</span>';
                    
                const durumBadge = dept.departman_durum 
                    ? '<span class="status-badge status-active">Aktif</span>' 
                    : '<span class="status-badge status-inactive">Pasif</span>';
                
                const olusturmatarihi = formatDate(dept.departman_olusturma_tarihi);
                const aciklama = dept.departman_aciklama ? 
                    (dept.departman_aciklama.length > 50 ? dept.departman_aciklama.substring(0, 50) + '...' : dept.departman_aciklama) : 
                    '-';
                
                let islemler = '';
                if (permissions.canEdit) {
                    islemler += `
                        <button class="btn btn-sm btn-warning" onclick="editDepartman(${dept.departman_id})" title="Düzenle">
                            <i class="bi bi-pencil"></i>
                        </button>
                    `;
                }
                if (permissions.canDelete) {
                    islemler += `
                        <button class="btn btn-sm btn-danger" onclick="deleteDepartman(${dept.departman_id})" title="Sil">
                            <i class="bi bi-trash"></i>
                        </button>
                    `;
                }
                if (!islemler) {
                    islemler = '<span class="text-muted">-</span>';
                }
                
                dataTable.row.add([
                    dept.departman_adi,
                    dept.departman_kod || '-',
                    aciklama,
                    tipBadge,
                    durumBadge,
                    olusturmatarihi,
                    islemler
                ]);
            });
            
            dataTable.draw();
        }
        
        function resetForm() {
            document.getElementById('departmanForm').reset();
            document.getElementById('departman_id').value = '';
            document.getElementById('departmanModalLabel').textContent = 'Yeni Departman Ekle';
        }
        
        function saveDepartman() {
            const formData = new FormData(document.getElementById('departmanForm'));
            formData.append('action', 'save');
            
            $.ajax({
                url: '',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        showToast(data.message, 'success');
                        departmanModal.hide();
                        loadDepartmanList();
                        loadStats();
                    } else {
                        showToast(data.message, 'error');
                    }
                },
                error: function(error) {
                    showToast('Kayıt sırasında hata oluştu', 'error');
                }
            });
        }
        
        function editDepartman(departmanid) {
            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'get', departman_id: departmanid },
                dataType: 'json',
                success: function(data) {
                    if (data.success && data.data) {
                        const dept = data.data;
                        
                        document.getElementById('departman_id').value = dept.departman_id;
                        document.getElementById('departman_adi').value = dept.departman_adi || '';
                        document.getElementById('departman_kod').value = dept.departman_kod || '';
                        document.getElementById('departman_aciklama').value = dept.departman_aciklama || '';
                        document.getElementById('departman_durum').checked = dept.departman_durum == 1;
                        document.getElementById('departman_personel').checked = dept.departman_personel == 1;
                        
                        document.getElementById('departmanModalLabel').textContent = 'Departman Düzenle';
                        departmanModal.show();
                    }
                },
                error: function(error) {
                    showToast('Kayıt yüklenirken hata oluştu', 'error');
                }
            });
        }
        
        function deleteDepartman(departmanid) {
            confirmAction(
                'Bu departmanı silmek istediğinize emin misiniz?',
                'Bu işlem geri alınamaz!',
                function() {
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: { action: 'delete', departman_id: departmanid },
                        dataType: 'json',
                        success: function(data) {
                            if (data.success) {
                                showSuccess('Silindi!', data.message);
                                loadDepartmanList();
                                loadStats();
                            } else {
                                showError('Hata!', data.message);
                            }
                        },
                        error: function(error) {
                            showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                        }
                    });
                }
            );
        }
    </script>
</body>
</html>
