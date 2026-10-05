<?php
/**
 * Admin Panel - Sürüm Notları
 */

require_once __DIR__ . '/../auth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Admin kontrolü (departman_id = 1)
$isAdmin = ($user['departman_id'] == 1);

// Mevcut sayfanın bilgilerini al
$currentPagefile = basename($_SERVER['PHP_SELF']);
$pageinfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOiN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LiKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

// Sayfa bilgileri
$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Sürüm Notları';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// site title'i çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'check_new_versions':
                // versions.sql dosyasını oku ve yeni versiyonları tespit et
                $versionsfile = __DIR__ . '/../../config/versions.sql';
                
                if (!file_exists($versionsfile)) {
                    echo json_encode(['success' => false, 'message' => 'versions.sql dosyası bulunamadı!']);
                    break;
                }
                
                $content = file_get_contents($versionsfile);
                
                // Dosyadan tüm versiyonları çıkar
                preg_match_all("/surum_versiyon = '([0-9.]+)'/", $content, $matches);
                $fileVersions = $matches[1] ?? [];
                
                // Veritabanındaki versiyonları al
                $dbVersionsResult = $db->fetchAll("SELECT surum_versiyon FROM Sistem_Surum_Notlari");
                $dbVersions = array_column($dbVersionsResult, 'surum_versiyon');
                
                // Yeni versiyonları bul
                $newVersions = array_diff($fileVersions, $dbVersions);
                
                if (empty($newVersions)) {
                    echo json_encode([
                        'success' => true, 
                        'hasNew' => false,
                        'message' => 'Tüm versiyonlar güncel!'
                    ]);
                } else {
                    echo json_encode([
                        'success' => true, 
                        'hasNew' => true,
                        'count' => count($newVersions),
                        'versions' => array_values($newVersions),
                        'message' => count($newVersions) . ' yeni versiyon tespit edildi!'
                    ]);
                }
                break;
                
            case 'import_versions':
                // versions.sql dosyasını çalıştır
                $versionsfile = __DIR__ . '/../../config/versions.sql';
                
                if (!file_exists($versionsfile)) {
                    echo json_encode(['success' => false, 'message' => 'versions.sql dosyası bulunamadı!']);
                    break;
                }
                
                $sql = file_get_contents($versionsfile);
                
                try {
                    // SQL dosyasını çalıştır
                    $db->executeRaw($sql);
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Yeni versiyonlar başarıyla eklendi!'
                    ]);
                } catch (Exception $e) {
                    echo json_encode([
                        'success' => false, 
                        'message' => 'SQL hatasi: ' . $e->getMessage()
                    ]);
                }
                break;
                
            case 'list':
                // Filtreleri al
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                $tip = $_POST['tip'] ?? '';
                $kategori = $_POST['kategori'] ?? '';
                $search = $_POST['search'] ?? '';
                
                // WHERE koşulları
                $whereConditions = ["1=1"];
                $params = [];
                
                if ($startDate) {
                    $whereConditions[] = "CONVERT(date, s.surum_tarih) >= ?";
                    $params[] = $startDate;
                }
                
                if ($endDate) {
                    $whereConditions[] = "CONVERT(date, s.surum_tarih) <= ?";
                    $params[] = $endDate;
                }
                
                if ($tip) {
                    $whereConditions[] = "s.surum_tip = ?";
                    $params[] = $tip;
                }
                
                if ($kategori) {
                    $whereConditions[] = "s.surum_kategori = ?";
                    $params[] = $kategori;
                }
                
                if ($search) {
                    $whereConditions[] = "(s.surum_modul LiKE ? OR s.surum_baslik LiKE ? OR s.surum_aciklama LiKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                $whereClause = implode(" AND ", $whereConditions);
                
                // Sürüm notlarını listele
                $sql = "SELECT 
                            s.surum_id,
                            s.surum_versiyon,
                            CONVERT(VARCHAR(10), s.surum_tarih, 120) as surum_tarih,
                            s.surum_tip,
                            s.surum_kategori,
                            s.surum_modul,
                            s.surum_baslik,
                            s.surum_aciklama,
                            s.surum_dosyalar,
                            s.surum_durum,
                            CONVERT(VARCHAR(19), s.surum_olusturma_tarihi, 120) as surum_olusturma_tarihi,
                            k.kullanici_ad + ' ' + k.kullanici_soyad as kullanici_adi
                        FROM Sistem_Surum_Notlari s
                        LEFT JOiN kullanicilar k ON k.kullanici_id = s.surum_kullanici_id
                        WHERE $whereClause
                        ORDER BY s.surum_tarih DESC, s.surum_id DESC";
                
                $surum = $db->fetchAll($sql, $params);
                echo json_encode(['success' => true, 'data' => $surum]);
                break;
                
            case 'stats':
                // istatistikleri getir
                $stats = [
                    'toplam' => $db->fetchOne("SELECT count(*) as sayi FROM Sistem_Surum_Notlari")['sayi'] ?? 0,
                    'son_versiyon' => $db->fetchOne("SELECT TOP 1 surum_versiyon FROM Sistem_Surum_Notlari ORDER BY surum_id DESC")['surum_versiyon'] ?? '1.0.0',
                    'feature' => $db->fetchOne("SELECT count(*) as sayi FROM Sistem_Surum_Notlari WHERE surum_tip = 'FEATURE'")['sayi'] ?? 0,
                    'bugfix' => $db->fetchOne("SELECT count(*) as sayi FROM Sistem_Surum_Notlari WHERE surum_tip = 'BUGFiX'")['sayi'] ?? 0
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem!']);
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
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    
    <style>
        .version-badge {
            font-size: 1rem;
            font-weight: 600;
            padding: 0.5rem 1rem;
        }
        .file-list {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .timeline-item {
            border-left: 3px solid #0d6efd;
            padding-left: 20px;
            margin-bottom: 30px;
            position: relative;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -7px;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #0d6efd;
        }
        .timeline-item.feature::before { background: #0d6efd; }
        .timeline-item.bugfix::before { background: #dc3545; }
        .timeline-item.update::before { background: #198754; }
        .new-version-alert {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
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
                    
                    <!-- info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-list-ol"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Versiyon</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-tag"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Son Versiyon</span>
                                    <span class="info-box-number" id="stat-son" style="font-size: 1.5rem;">-</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-plus-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Yeni Özellik</span>
                                    <span class="info-box-number" id="stat-feature">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-bug"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Hata Düzeltme</span>
                                    <span class="info-box-number" id="stat-bugfix">0</span>
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
                                    <div class="col-md-2">
                                        <label class="form-label">Başlangıç tarihi</label>
                                        <input type="date" class="form-control" name="start_date" id="filter_start_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Bitiş tarihi</label>
                                        <input type="date" class="form-control" name="end_date" id="filter_end_date">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Versiyon Tipi</label>
                                        <select class="form-select" name="tip" id="filter_tip">
                                            <option value="">Tümü</option>
                                            <option value="FEATURE">Yeni Özellik</option>
                                            <option value="BUGFiX">Hata Düzeltme</option>
                                            <option value="UPDATE">Güncelleme</option>
                                            <option value="DELETE">Silme</option>
                                            <option value="config">Yapılandırma</option>
                                            <option value="SECURiTY">Güvenlik</option>
                                            <option value="DATABASE">Veritabanı</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Kategori</label>
                                        <select class="form-select" name="kategori" id="filter_kategori">
                                            <option value="">Tümü</option>
                                            <option value="Ui">Kullanıcı Arayüzü</option>
                                            <option value="Backend">Backend</option>
                                            <option value="Database">Veritabanı</option>
                                            <option value="Security">Güvenlik</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Ara (Modül/Başlık)</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Anahtar kelime...">
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
                    
                    <!-- Yeni Versiyon kontrolü (Sadece Admin) -->
                    <?php if ($isAdmin): ?>
                    <div id="newVersionAlert" class="alert alert-warning d-none new-version-alert">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <strong id="newVersionMessage">Yeni versiyonlar tespit edildi!</strong>
                            </div>
                            <button class="btn btn-success btn-sm" onclick="importVersions()">
                                <i class="bi bi-download"></i> Yeni Versiyonları Ekle
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Sürüm Notları Tablosu -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Değişiklik Geçmişi</h3>
                            <?php if ($isAdmin): ?>
                            <div class="card-tools">
                                <button type="button" class="btn btn-info btn-sm" onclick="checkNewVersions()">
                                    <i class="bi bi-arrow-clockwise"></i> Yeni Versiyon Kontrol Et
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <table id="surumTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>id</th>
                                        <th>Versiyon</th>
                                        <th>tarih</th>
                                        <th>Tip</th>
                                        <th>Kategori</th>
                                        <th>Modül</th>
                                        <th>Başlık</th>
                                        <th>Açıklama</th>
                                        <th>Dosyalar</th>
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
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="/Admin/assets/js/custom.js"></script>
    
    <script>
        let table;
        let currentfilters = {};
        
        function loadStats() {
            $.post('', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-son').text(response.data.son_versiyon);
                    $('#stat-feature').text(response.data.feature);
                    $('#stat-bugfix').text(response.data.bugfix);
                }
            });
        }
        
        function checkNewVersions() {
            $.post('', { action: 'check_new_versions' }, response => {
                if (response.success) {
                    if (response.hasNew) {
                        $('#newVersionMessage').text(
                            response.count + ' yeni versiyon tespit edildi: ' + response.versions.join(', ')
                        );
                        $('#newVersionAlert').removeClass('d-none');
                        showToast(response.message, 'info');
                    } else {
                        $('#newVersionAlert').addClass('d-none');
                        showToast(response.message, 'success');
                    }
                } else {
                    showToast(response.message, 'error');
                }
            });
        }
        
        function importVersions() {
            confirmAction(
                'Yeni Versiyonları Ekle',
                'config/versions.sql dosyasındaki yeni versiyonlar veritabanına eklenecek.',
                function() {
                    $.post('', { action: 'import_versions' }, function(response) {
                        if (response.success) {
                            showSuccess('Başarılı!', response.message);
                            $('#newVersionAlert').addClass('d-none');
                            table.ajax.reload();
                            loadStats();
                        } else {
                            showError('Hata!', response.message);
                        }
                    }).fail(function() {
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }
        
        function initDataTable() {
            table = $('#surumTable').DataTable({
                processing: true,
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                ajax: {
                    url: '',
                    type: 'POST',
                    data: function(d) {
                        return { action: 'list', ...currentfilters };
                    },
                    dataSrc: json => json.success ? json.data : []
                },
                columns: [
                    { data: 'surum_id' },
                    { 
                        data: 'surum_versiyon',
                        render: data => `<span class="badge bg-primary version-badge">${data}</span>`
                    },
                    { 
                        data: 'surum_tarih',
                        render: data => {
                            const date = new Date(data);
                            return date.toLocaleDateString('tr-TR');
                        }
                    },
                    { 
                        data: 'surum_tip',
                        render: data => {
                            const types = {
                                'FEATURE': 'badge bg-primary',
                                'BUGFiX': 'badge bg-danger',
                                'UPDATE': 'badge bg-success',
                                'DELETE': 'badge bg-warning',
                                'config': 'badge bg-info',
                                'SECURiTY': 'badge bg-dark',
                                'DATABASE': 'badge bg-secondary'
                            };
                            const badgeClass = types[data] || 'badge bg-secondary';
                            return `<span class="${badgeClass}">${data}</span>`;
                        }
                    },
                    { data: 'surum_kategori', defaultContent: '-' },
                    { data: 'surum_modul', defaultContent: '-' },
                    { data: 'surum_baslik' },
                    { 
                        data: 'surum_aciklama',
                        render: (data, type, row) => {
                            if (!data || data.length < 100) return data || '-';
                            return `<span title="${data}">${data.substring(0, 100)}...</span>`;
                        }
                    },
                    { 
                        data: 'surum_dosyalar',
                        render: data => {
                            if (!data) return '-';
                            try {
                                const files = JSON.parse(data);
                                return `<span class="file-list" title="${files.join(', ')}">${files.length} dosya</span>`;
                            } catch(e) {
                                return '-';
                            }
                        }
                    }
                ],
                order: [[0, 'desc']]
            });
        }
        
        $(document).ready(() => {
            loadStats();
            initDataTable();
            
            <?php if ($isAdmin): ?>
            checkNewVersions(); // Sayfa açılır açılmaz kontrol et (Sadece Admin)
            <?php endif; ?>
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventdefault();
                
                // Filtreleri topla
                currentfilters = {
                    start_date: $('#filter_start_date').val(),
                    end_date: $('#filter_end_date').val(),
                    tip: $('#filter_tip').val(),
                    kategori: $('#filter_kategori').val(),
                    search: $('#filter_search').val()
                };
                
                // Boş değerleri kaldir
                Object.keys(currentfilters).forEach(key => {
                    if (!currentfilters[key]) delete currentfilters[key];
                });
                
                table.ajax.reload();
                showToast('Filtre uygulandı', 'info');
            });
            
            // Filtreleri temizle
            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_tip').val('').trigger('change.select2');
                $('#filter_kategori').val('').trigger('change.select2');
                currentfilters = {};
                table.ajax.reload();
                showToast('Filtreler temizlendi', 'info');
            });
        });
    </script>
</body>
</html>
