<?php
/**
 * Admin Panel - Menü Sayfa Yetkileri Yönetimi
 * 
 * Bu sayfa menü ve sayfa bazlı kullanıcı grubu yetkilerini yönetir
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa Yetki kontrolü
$currentPagefile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPagefile
);

// Sayfa erişim kontrolü
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim Yetkiniz bulunmamaktadır.');
}

// Mevcut sayfanın bilgilerini al
$pageinfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

// Sayfa bilgileri
$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Menü Sayfa Yetkileri';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? 'menü ve sayfa bazlı kullanıcı grubu yetkilerini yönetin';
$menuAdi = $pageinfo['menu_adi'] ?? 'Sistem Ayarları';

// site title'i çek
$siteAyarları = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarları['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'list':
                // Yetki listesini getir
                $yetkiler = $db->fetchAll("
                    SELECT 
                        y.menu_sayfa_yetki_id AS menu_sayfa_Yetki_id,
                        m.menuler_menu_adi,
                        s.sayfalar_sayfa_adi,
                        d.departman_adi,
                        y.gor,
                        y.kendi_kullanicini_gor,
                        y.birim_gor,
                        y.ekle,
                        y.duzenle,
                        y.sil,
                        y.durum,
                        CONVERT(VARCHAR(19), y.created_at, 120) as created_at,
                        CONVERT(VARCHAR(19), y.updated_at, 120) as updated_at
                    FROM Menu_SayfaYetkileri y
                    LEFT JOIN Menuler m ON m.menuler_id = y.menu_id
                    LEFT JOIN Menu_Sayfalar s ON s.sayfalar_id = y.sayfa_id
                    LEFT JOIN kullanici_Departmanlar d ON d.departman_id = y.departman_id
                    ORDER BY m.menuler_menu_adi, s.sayfalar_sayfa_adi, d.departman_adi
                ");
                echo json_encode(['success' => true, 'data' => $yetkiler]);
                break;
                
            case 'get':
                $id = $_POST['id'] ?? 0;
                $Yetki = $db->fetchOne("
                    SELECT 
                        menu_sayfa_yetki_id AS menu_sayfa_Yetki_id,
                        menu_id, sayfa_id, departman_id,
                        gor, kendi_kullanicini_gor, birim_gor,
                        ekle, duzenle, sil, durum
                    FROM Menu_SayfaYetkileri WHERE menu_sayfa_yetki_id = ?
                ", [$id]);
                
                if ($Yetki) {
                    echo json_encode(['success' => true, 'data' => $Yetki]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Yetki kaydı bulunamadı']);
                }
                break;
                
            case 'save':
                // Yetki kontrolü
                $id = $_POST['menu_sayfa_Yetki_id'] ?? 0;
                if ($id > 0 && !$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme Yetkiniz yok!']);
                    break;
                }
                if ($id == 0 && !$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme Yetkiniz yok!']);
                    break;
                }
                
                // Düzenleme modu kontrolü
                if ($id > 0) {
                    // Düzenleme: Tek kayıt
                    $menuid = !empty($_POST['menu_id']) ? $_POST['menu_id'] : null;
                    $sayfaid = !empty($_POST['sayfa_id']) ? $_POST['sayfa_id'] : null;
                    $departmanid = !empty($_POST['departman_id']) ? $_POST['departman_id'] : null;
                    
                    if (!$menuid && !$sayfaid) {
                        echo json_encode(['success' => false, 'message' => 'menü veya Sayfa seçmelisiniz!']);
                        break;
                    }
                    
                    $data = [
                        'sayfa_id' => $sayfaid,
                        'menu_id' => $menuid,
                        'departman_id' => $departmanid,
                        'gor' => isset($_POST['gor']) ? 1 : 0,
                        'kendi_kullanicini_gor' => isset($_POST['kendi_kullanicini_gor']) ? 1 : 0,
                        'birim_gor' => isset($_POST['birim_gor']) ? 1 : 0,
                        'ekle' => isset($_POST['ekle']) ? 1 : 0,
                        'duzenle' => isset($_POST['duzenle']) ? 1 : 0,
                        'sil' => isset($_POST['sil']) ? 1 : 0,
                        'durum' => isset($_POST['durum']) ? 1 : 0,
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $db->update('Menu_SayfaYetkileri', $data, ['menu_sayfa_Yetki_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Yetki başarıyla güncellendi']);
                    break;
                }
                
                // Yeni ekleme: Multiselect
                $menuids = isset($_POST['menu_id']) && is_array($_POST['menu_id']) ? array_filter($_POST['menu_id']) : [];
                $sayfaids = isset($_POST['sayfa_id']) && is_array($_POST['sayfa_id']) ? array_filter($_POST['sayfa_id']) : [];
                $departmanid = !empty($_POST['departman_id']) ? $_POST['departman_id'] : null;
                
                // Validasyon: En az bir menü veya sayfa seçilmeli
                if (empty($menuids) && empty($sayfaids)) {
                    echo json_encode(['success' => false, 'message' => 'En az bir menü veya Sayfa seçmelisiniz!']);
                    break;
                }
                
                if (!$departmanid) {
                    echo json_encode(['success' => false, 'message' => 'Departman seçmelisiniz!']);
                    break;
                }
                
                // Yetki bilgileri
                $permissions = [
                    'departman_id' => $departmanid,
                    'gor' => isset($_POST['gor']) ? 1 : 0,
                    'kendi_kullanicini_gor' => isset($_POST['kendi_kullanicini_gor']) ? 1 : 0,
                    'birim_gor' => isset($_POST['birim_gor']) ? 1 : 0,
                    'ekle' => isset($_POST['ekle']) ? 1 : 0,
                    'duzenle' => isset($_POST['duzenle']) ? 1 : 0,
                    'sil' => isset($_POST['sil']) ? 1 : 0,
                    'durum' => isset($_POST['durum']) ? 1 : 0
                ];
                
                $successcount = 0;
                $skipcount = 0;
                $errorMessages = [];
                
                try {
                    // Menüler için kaydet
                    foreach ($menuids as $menuid) {
                        // Aynı kayıt var mı kontrol et
                        $existing = $db->fetchOne("
                            SELECT menu_sayfa_Yetki_id 
                            FROM Menu_SayfaYetkileri 
                            WHERE departman_id = ? AND menu_id = ? AND sayfa_id IS NULL
                        ", [$departmanid, $menuid]);
                        
                        if ($existing) {
                            $skipcount++;
                            continue;
                        }
                        
                        $data = array_merge($permissions, [
                            'menu_id' => $menuid,
                            'sayfa_id' => null,
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                        
                        $db->insert('Menu_SayfaYetkileri', $data);
                        $successcount++;
                    }
                    
                    // Sayfalar için kaydet
                    foreach ($sayfaids as $sayfaid) {
                        // Aynı kayıt var mı kontrol et
                        $existing = $db->fetchOne("
                            SELECT menu_sayfa_Yetki_id 
                            FROM Menu_SayfaYetkileri 
                            WHERE departman_id = ? AND sayfa_id = ? AND menu_id IS NULL
                        ", [$departmanid, $sayfaid]);
                        
                        if ($existing) {
                            $skipcount++;
                            continue;
                        }
                        
                        $data = array_merge($permissions, [
                            'menu_id' => null,
                            'sayfa_id' => $sayfaid,
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                        
                        $db->insert('Menu_SayfaYetkileri', $data);
                        $successcount++;
                    }
                    
                    $message = "$successcount yetki başarıyla eklendi";
                    if ($skipcount > 0) {
                        $message .= ", $skipcount yetki zaten mevcut (atlandı)";
                    }
                    
                    echo json_encode(['success' => true, 'message' => $message]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
                }
                break;
                
            case 'copy_department':
                // Departmandan departmana yetki kopyalama (üzerine yaz)
                if (!$pagePermissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme Yetkiniz yok!']);
                    break;
                }

                $kaynakId = !empty($_POST['kaynak_departman_id']) ? (int)$_POST['kaynak_departman_id'] : 0;
                $hedefIds = isset($_POST['hedef_departman_id']) && is_array($_POST['hedef_departman_id'])
                    ? array_filter(array_map('intval', $_POST['hedef_departman_id']))
                    : [];

                if (!$kaynakId) {
                    echo json_encode(['success' => false, 'message' => 'Kaynak departman seçmelisiniz!']);
                    break;
                }
                if (empty($hedefIds)) {
                    echo json_encode(['success' => false, 'message' => 'En az bir hedef departman seçmelisiniz!']);
                    break;
                }
                if (in_array($kaynakId, $hedefIds, true)) {
                    echo json_encode(['success' => false, 'message' => 'Kaynak ve hedef departman aynı olamaz!']);
                    break;
                }

                // Kaynak departmanın tüm yetkileri
                $kaynakYetkiler = $db->fetchAll("
                    SELECT menu_id, sayfa_id, gor, kendi_kullanicini_gor, birim_gor, ekle, duzenle, sil, durum
                    FROM Menu_SayfaYetkileri
                    WHERE departman_id = ?
                ", [$kaynakId]);

                if (empty($kaynakYetkiler)) {
                    echo json_encode(['success' => false, 'message' => 'Kaynak departmanda kopyalanacak yetki bulunamadı!']);
                    break;
                }

                $eklenen = 0;
                $guncellenen = 0;
                $now = date('Y-m-d H:i:s');

                foreach ($hedefIds as $hedefId) {
                    foreach ($kaynakYetkiler as $ky) {
                        $menuid = $ky['menu_id'] !== null ? (int)$ky['menu_id'] : null;
                        $sayfaid = $ky['sayfa_id'] !== null ? (int)$ky['sayfa_id'] : null;

                        // Hedefte aynı menü/sayfa kaydı var mı? (NULL güvenli karşılaştırma)
                        $existing = $db->fetchOne("
                            SELECT menu_sayfa_Yetki_id
                            FROM Menu_SayfaYetkileri
                            WHERE departman_id = ?
                              AND ((menu_id = ?) OR (menu_id IS NULL AND ? IS NULL))
                              AND ((sayfa_id = ?) OR (sayfa_id IS NULL AND ? IS NULL))
                        ", [$hedefId, $menuid, $menuid, $sayfaid, $sayfaid]);

                        $permData = [
                            'gor' => (int)$ky['gor'],
                            'kendi_kullanicini_gor' => (int)$ky['kendi_kullanicini_gor'],
                            'birim_gor' => (int)$ky['birim_gor'],
                            'ekle' => (int)$ky['ekle'],
                            'duzenle' => (int)$ky['duzenle'],
                            'sil' => (int)$ky['sil'],
                            'durum' => (int)$ky['durum'],
                        ];

                        if ($existing) {
                            // Üzerine yaz
                            $db->update('Menu_SayfaYetkileri',
                                array_merge($permData, ['updated_at' => $now]),
                                ['menu_sayfa_Yetki_id' => $existing['menu_sayfa_Yetki_id']]
                            );
                            $guncellenen++;
                        } else {
                            // Yeni ekle
                            $db->insert('Menu_SayfaYetkileri', array_merge($permData, [
                                'departman_id' => $hedefId,
                                'menu_id' => $menuid,
                                'sayfa_id' => $sayfaid,
                                'created_at' => $now,
                            ]));
                            $eklenen++;
                        }
                    }
                }

                echo json_encode([
                    'success' => true,
                    'message' => "Kopyalama tamamlandı: $eklenen yeni, $guncellenen güncellendi (" . count($hedefIds) . " departman)"
                ]);
                break;

            case 'delete':
                // Yetki kontrolü
                if (!$pagePermissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme Yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $db->delete('Menu_SayfaYetkileri', ['menu_sayfa_Yetki_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Yetki başarıyla silindi']);
                break;
                
            case 'toggle_status':
                // Yetki kontrolü
                if (!$pagePermissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme Yetkiniz yok!']);
                    break;
                }
                
                $id = $_POST['id'] ?? 0;
                $currentStatus = $db->fetchOne("SELECT durum FROM Menu_SayfaYetkileri WHERE menu_sayfa_Yetki_id = ?", [$id]);
                $newStatus = $currentStatus['durum'] == 1 ? 0 : 1;
                $db->update('Menu_SayfaYetkileri', 
                    ['durum' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')], 
                    ['menu_sayfa_Yetki_id' => $id]
                );
                echo json_encode(['success' => true, 'message' => 'Durum güncellendi']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Sayfalar listesi
$sayfalar = $db->fetchAll("SELECT sayfalar_id, sayfalar_sayfa_adi FROM Menu_Sayfalar WHERE sayfalar_durum = 1 ORDER BY sayfalar_sayfa_adi");

// menüler listesi
$menuler = $db->fetchAll("SELECT menuler_id, menuler_menu_adi FROM Menuler WHERE menuler_durum = 1 ORDER BY menuler_menu_adi");

// kullanici_Departmanlar listesi
$kullanici_Departmanlar = $db->fetchAll("SELECT departman_id, departman_adi FROM kullanici_Departmanlar WHERE departman_durum = 1 ORDER BY departman_adi");

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
    
    <style>
        .table-actions {
            white-space: nowrap;
        }
        .permission-badge {
            display: inline-block;
            margin: 2px;
            font-size: 0.75rem;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 20px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 20px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #28a745;
        }
        input:checked + .slider:before {
            transform: translateX(20px);
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <!-- Sayfa Başlığı -->
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
            
            <!-- Sayfa İçeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- İstatistik Kutuları -->
                    <div class="row mb-4">
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-info">
                                <div class="inner">
                                    <h3 id="totalYetkiler">0</h3>
                                    <p>Toplam Yetki</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-shield-lock"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-success">
                                <div class="inner">
                                    <h3 id="activeYetkiler">0</h3>
                                    <p>Aktif Yetki</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-warning">
                                <div class="inner">
                                    <h3 id="totalDepartmanlar">0</h3>
                                    <p>Departman</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-people"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box text-bg-danger">
                                <div class="inner">
                                    <h3 id="totalSayfalar">0</h3>
                                    <p>Sayfa</p>
                                </div>
                                <div class="icon">
                                    <i class="bi bi-file-earmark-text"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Filtre Karti -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-funnel"></i> Filtrele
                            </h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool" data-lte-toggle="card-collapse">
                                    <i class="bi bi-dash-lg"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="filterMenu">Menü</label>
                                        <select class="form-select" id="filterMenu">
                                            <option value="">Tümü</option>
                                            <?php foreach ($menuler as $menu): ?>
                                                <option value="<?= htmlspecialchars($menu['menuler_menu_adi']) ?>">
                                                    <?= htmlspecialchars($menu['menuler_menu_adi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="filterSayfa">Sayfa</label>
                                        <select class="form-select" id="filterSayfa">
                                            <option value="">Tümü</option>
                                            <?php foreach ($sayfalar as $sayfa): ?>
                                                <option value="<?= htmlspecialchars($sayfa['sayfalar_sayfa_adi']) ?>">
                                                    <?= htmlspecialchars($sayfa['sayfalar_sayfa_adi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="filterDepartman">Departman</label>
                                        <select class="form-select" id="filterDepartman">
                                            <option value="">Tümü</option>
                                            <?php foreach ($kullanici_Departmanlar as $departman): ?>
                                                <option value="<?= htmlspecialchars($departman['departman_adi']) ?>">
                                                    <?= htmlspecialchars($departman['departman_adi']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="filterDurum">Durum</label>
                                        <select class="form-select" id="filterDurum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <button type="button" class="btn btn-primary" onclick="applyfilters()">
                                        <i class="bi bi-search"></i> Filtrele
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="clearfilters()">
                                        <i class="bi bi-x-circle"></i> Temizle
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Yetki Tablosu -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-shield-lock"></i> Menü Sayfa Yetkileri
                            </h3>
                            <div class="card-tools">
                                <?php if ($pagePermissions['can_add']): ?>
                                <button type="button" class="btn btn-info btn-sm" onclick="openCopyModal()">
                                    <i class="bi bi-files"></i> Yetki Kopyala
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" onclick="openModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni Yetki Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped table-hover" id="YetkiTable">
                                    <thead>
                                        <tr>
                                            <th style="width: 50px">#</th>
                                            <th>Menü</th>
                                            <th>Sayfa</th>
                                            <th>Departman</th>
                                            <th style="width: 300px">Yetkiler</th>
                                            <th style="width: 80px">Durum</th>
                                            <th style="width: 120px">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td colspan="7" class="text-center">
                                                <div class="spinner-border" role="status">
                                                    <span class="visually-hidden">Yükleniyor...</span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <!-- Yetki Ekleme/Düzenleme Modal -->
    <div class="modal fade" id="YetkiModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Yeni Yetki Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="YetkiForm">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="menu_sayfa_Yetki_id" id="menu_sayfa_Yetki_id">
                    
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> <strong>Not:</strong> menü veya Sayfa'dan <strong>birini veya birden fazlasını</strong> seçebilirsiniz. Toplu Yetki ataması yapılacaktır.
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="menu_id" class="form-label">Menü (Çoklu Seçim)</label>
                                <select class="form-select" id="menu_id" name="menu_id[]" multiple size="8">
                                    <?php foreach ($menuler as $menu): ?>
                                        <option value="<?= $menu['menuler_id'] ?>">
                                            <?= htmlspecialchars($menu['menuler_menu_adi']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Ctrl+Click ile birden fazla menü seçin</small>
                            </div>
                            <div class="col-md-6">
                                <label for="sayfa_id" class="form-label">Sayfa (Çoklu Seçim)</label>
                                <select class="form-select" id="sayfa_id" name="sayfa_id[]" multiple size="8">
                                    <?php foreach ($sayfalar as $sayfa): ?>
                                        <option value="<?= $sayfa['sayfalar_id'] ?>">
                                            <?= htmlspecialchars($sayfa['sayfalar_sayfa_adi']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Ctrl+Click ile birden fazla sayfa seçin</small>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="departman_id" class="form-label">Departman</label>
                                <select class="form-select" id="departman_id" name="departman_id" required>
                                    <option value="">Departman Seçiniz</option>
                                    <?php foreach ($kullanici_Departmanlar as $departman): ?>
                                        <option value="<?= $departman['departman_id'] ?>">
                                            <?= htmlspecialchars($departman['departman_adi']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Yetkiler</label>
                                <div class="card">
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="gor" name="gor" value="1">
                                                    <label class="form-check-label" for="gor">
                                                        <i class="bi bi-eye text-info"></i> Görüntüleme
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="kendi_kullanicini_gor" name="kendi_kullanicini_gor" value="1">
                                                    <label class="form-check-label" for="kendi_kullanicini_gor">
                                                        <i class="bi bi-person text-secondary"></i> Kendi Kullanıcısını Gör
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="birim_gor" name="birim_gor" value="1">
                                                    <label class="form-check-label" for="birim_gor">
                                                        <i class="bi bi-diagram-3 text-primary"></i> Birim Gör
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="ekle" name="ekle" value="1">
                                                    <label class="form-check-label" for="ekle">
                                                        <i class="bi bi-plus-circle text-success"></i> Ekleme
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="duzenle" name="duzenle" value="1">
                                                    <label class="form-check-label" for="duzenle">
                                                        <i class="bi bi-pencil text-warning"></i> Düzenleme
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-4 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="sil" name="sil" value="1">
                                                    <label class="form-check-label" for="sil">
                                                        <i class="bi bi-trash text-danger"></i> Silme
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="durum" name="durum" value="1" checked>
                                    <label class="form-check-label" for="durum">Aktif</label>
                                </div>
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
    
    <!-- Yetki Kopyalama Modal -->
    <div class="modal fade" id="copyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-files"></i> Departman Yetkilerini Kopyala</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="copyForm">
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> Kaynak departmanın <strong>tüm menü/sayfa yetkileri</strong> seçilen hedef departman(lar)a kopyalanır. Hedefte aynı kayıt varsa <strong>üzerine yazılır</strong>.
                        </div>

                        <div class="mb-3">
                            <label for="kaynak_departman_id" class="form-label">Kaynak Departman</label>
                            <select class="form-select" id="kaynak_departman_id" name="kaynak_departman_id" required>
                                <option value="">Kaynak Departman Seçiniz</option>
                                <?php foreach ($kullanici_Departmanlar as $departman): ?>
                                    <option value="<?= $departman['departman_id'] ?>">
                                        <?= htmlspecialchars($departman['departman_adi']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="hedef_departman_id" class="form-label">Hedef Departman(lar) — Çoklu Seçim</label>
                            <select class="form-select" id="hedef_departman_id" name="hedef_departman_id[]" multiple required>
                                <?php foreach ($kullanici_Departmanlar as $departman): ?>
                                    <option value="<?= $departman['departman_id'] ?>">
                                        <?= htmlspecialchars($departman['departman_adi']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> İptal
                        </button>
                        <button type="submit" class="btn btn-info" id="copySubmitBtn">
                            <i class="bi bi-files"></i> Kopyala
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    
    <script>
        // Sayfa yetkileri
        const pagePermissions = {
            can_add: <?= $pagePermissions['can_add'] ? 'true' : 'false' ?>,
            can_edit: <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>,
            can_delete: <?= $pagePermissions['can_delete'] ? 'true' : 'false' ?>
        };
        
        let YetkiModal;
        let copyModal;
        let menuSelect;
        let sayfaSelect;
        let allYetkiler = [];
        let filteredYetkiler = [];

        document.addEventListener('DOMContentLoaded', function() {
            YetkiModal = new bootstrap.Modal(document.getElementById('YetkiModal'));
            copyModal = new bootstrap.Modal(document.getElementById('copyModal'));
            menuSelect = document.getElementById('menu_id');
            sayfaSelect = document.getElementById('sayfa_id');

            loadYetkiler();

            // Kopyalama formu submit
            document.getElementById('copyForm').addEventListener('submit', function(e) {
                e.preventDefault();
                copyDepartmanYetkileri();
            });
            
            // Form submit
            document.getElementById('YetkiForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Validasyon: En az bir menü veya Sayfa seçilmeli
                const selectedMenus = Array.from(menuSelect.selectedOptions).map(opt => opt.value);
                const selectedPages = Array.from(sayfaSelect.selectedOptions).map(opt => opt.value);
                
                if (selectedMenus.length === 0 && selectedPages.length === 0) {
                    showAlert('Lütfen en az bir menü veya Sayfa seçiniz!', 'warning');
                    return;
                }
                
                saveYetki();
            });
        });
        
        // Yetkileri yükle
        function loadYetkiler() {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=list'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    allYetkiler = data.data;
                    filteredYetkiler = data.data;
                    updateinfoBoxes();
                    renderTable(filteredYetkiler);
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Veriler yüklenirken hata oluştu', 'danger');
            });
        }
        
        // Info box'ları güncelle
        function updateinfoBoxes() {
            const activeYetkiler = allYetkiler.filter(y => y.durum == 1);
            const uniqueDepartmanlar = [...new Set(allYetkiler.map(y => y.departman_adi))];
            const uniqueSayfalar = [...new Set(allYetkiler.filter(y => y.sayfalar_sayfa_adi).map(y => y.sayfalar_sayfa_adi))];
            
            document.getElementById('totalYetkiler').textContent = allYetkiler.length;
            document.getElementById('activeYetkiler').textContent = activeYetkiler.length;
            document.getElementById('totalDepartmanlar').textContent = uniqueDepartmanlar.length;
            document.getElementById('totalSayfalar').textContent = uniqueSayfalar.length;
        }
        
        // Filtreleri uygula
        function applyfilters() {
            const filterMenu = document.getElementById('filterMenu').value.toLowerCase();
            const filterSayfa = document.getElementById('filterSayfa').value.toLowerCase();
            const filterDepartman = document.getElementById('filterDepartman').value.toLowerCase();
            const filterDurum = document.getElementById('filterDurum').value;
            
            filteredYetkiler = allYetkiler.filter(Yetki => {
                const menuMatch = !filterMenu || (Yetki.menuler_menu_adi && Yetki.menuler_menu_adi.toLowerCase().includes(filterMenu));
                const sayfaMatch = !filterSayfa || (Yetki.sayfalar_sayfa_adi && Yetki.sayfalar_sayfa_adi.toLowerCase().includes(filterSayfa));
                const departmanMatch = !filterDepartman || (Yetki.departman_adi && Yetki.departman_adi.toLowerCase().includes(filterDepartman));
                const durumMatch = filterDurum === '' || Yetki.durum == filterDurum;
                
                return menuMatch && sayfaMatch && departmanMatch && durumMatch;
            });
            
            renderTable(filteredYetkiler);
            
            showAlert(`${filteredYetkiler.length} kayıt bulundu`, 'info');
        }
        
        // Filtreleri temizle
        function clearfilters() {
            document.getElementById('filterMenu').value = '';
            document.getElementById('filterSayfa').value = '';
            document.getElementById('filterDepartman').value = '';
            document.getElementById('filterDurum').value = '';
            
            filteredYetkiler = allYetkiler;
            renderTable(filteredYetkiler);
            
            showAlert('Filtreler temizlendi', 'info');
        }
        
        // Tabloyu render et
        function renderTable(yetkiler) {
            const tbody = document.querySelector('#YetkiTable tbody');
            
            if (yetkiler.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center">Henüz yetki kaydı bulunmuyor</td></tr>';
                return;
            }
            
            let html = '';
            yetkiler.forEach((Yetki, index) => {
                const permissions = [];
                if (Yetki.gor == 1) permissions.push('<span class="badge bg-info permission-badge"><i class="bi bi-eye"></i> Gör</span>');
                if (Yetki.kendi_kullanicini_gor == 1) permissions.push('<span class="badge bg-secondary permission-badge"><i class="bi bi-person"></i> Kendi</span>');
                if (Yetki.birim_gor == 1) permissions.push('<span class="badge bg-primary permission-badge"><i class="bi bi-diagram-3"></i> Birim</span>');
                if (Yetki.ekle == 1) permissions.push('<span class="badge bg-success permission-badge"><i class="bi bi-plus"></i> Ekle</span>');
                if (Yetki.duzenle == 1) permissions.push('<span class="badge bg-warning permission-badge"><i class="bi bi-pencil"></i> Düzenle</span>');
                if (Yetki.sil == 1) permissions.push('<span class="badge bg-danger permission-badge"><i class="bi bi-trash"></i> Sil</span>');
                
                const statusChecked = Yetki.durum == 1 ? 'checked' : '';
                const statusClass = Yetki.durum == 1 ? 'bg-success' : 'bg-secondary';
                
                html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${Yetki.menuler_menu_adi || '-'}</td>
                        <td>${Yetki.sayfalar_sayfa_adi || '-'}</td>
                        <td><span class="badge ${statusClass}">${Yetki.departman_adi || '-'}</span></td>
                        <td>${permissions.join(' ')}</td>
                        <td class="text-center">
                            ${pagePermissions.can_edit ? `
                            <label class="switch">
                                <input type="checkbox" ${statusChecked} onchange="toggleStatus(${Yetki.menu_sayfa_Yetki_id})">
                                <span class="slider"></span>
                            </label>
                            ` : `<span class="badge ${statusClass}">${Yetki.durum == 1 ? 'Aktif' : 'Pasif'}</span>`}
                        </td>
                        <td class="table-actions">
                            ${pagePermissions.can_edit ? `
                            <button class="btn btn-sm btn-warning" onclick="editYetki(${Yetki.menu_sayfa_Yetki_id})" title="Düzenle">
                                <i class="bi bi-pencil"></i>
                            </button>
                            ` : ''}
                            ${pagePermissions.can_delete ? `
                            <button class="btn btn-sm btn-danger" onclick="deleteYetki(${Yetki.menu_sayfa_Yetki_id})" title="Sil">
                                <i class="bi bi-trash"></i>
                            </button>
                            ` : ''}
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
        }
        
        // Modal aç
        function openModal(id = null) {
            document.getElementById('YetkiForm').reset();
            document.getElementById('menu_sayfa_Yetki_id').value = '';
            document.getElementById('modalTitle').textContent = id ? 'Yetki Düzenle' : 'Toplu Yetki Ekle';
            document.getElementById('durum').checked = true;
            
            // Multiselect seçimlerini temizle
            menuSelect.value = '';
            sayfaSelect.value = '';
            $('#menu_id').val(null).trigger('change');
            $('#sayfa_id').val(null).trigger('change');
            
            // Multiselect'i göster/gizle
            if (id) {
                // Düzenlemede multiselect gizle, single select göster
                menuSelect.removeAttribute('multiple');
                sayfaSelect.removeAttribute('multiple');
                menuSelect.removeAttribute('size');
                sayfaSelect.removeAttribute('size');
                loadYetkidata(id);
            } else {
                // Yeni eklemede multiselect göster
                menuSelect.setAttribute('multiple', 'multiple');
                sayfaSelect.setAttribute('multiple', 'multiple');
                menuSelect.setAttribute('size', '8');
                sayfaSelect.setAttribute('size', '8');
            }
            
            YetkiModal.show();
        }
        
        // Kopyalama modalını aç
        function openCopyModal() {
            document.getElementById('copyForm').reset();
            copyModal.show();
        }

        // Departman yetkilerini kopyala
        function copyDepartmanYetkileri() {
            const kaynak = document.getElementById('kaynak_departman_id').value;
            const hedefler = Array.from(document.getElementById('hedef_departman_id').selectedOptions).map(o => o.value);

            if (!kaynak) {
                showAlert('Kaynak departman seçiniz!', 'warning');
                return;
            }
            if (hedefler.length === 0) {
                showAlert('En az bir hedef departman seçiniz!', 'warning');
                return;
            }
            if (hedefler.includes(kaynak)) {
                showAlert('Kaynak departman hedef olarak seçilemez!', 'warning');
                return;
            }

            if (!confirm(hedefler.length + ' departmana yetkiler kopyalanacak ve mevcut kayıtların üzerine yazılacak. Devam edilsin mi?')) {
                return;
            }

            const btn = document.getElementById('copySubmitBtn');
            btn.disabled = true;

            const params = new URLSearchParams();
            params.append('action', 'copy_department');
            params.append('kaynak_departman_id', kaynak);
            hedefler.forEach(h => params.append('hedef_departman_id[]', h));

            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                if (data.success) {
                    showAlert(data.message, 'success');
                    copyModal.hide();
                    loadYetkiler();
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                btn.disabled = false;
                console.error('Error:', error);
                showAlert('Kopyalama sırasında hata oluştu', 'danger');
            });
        }

        // Yetki verilerini yükle (Düzenleme için)
        function loadYetkidata(id) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=get&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const Yetki = data.data;
                    document.getElementById('menu_sayfa_Yetki_id').value = Yetki.menu_sayfa_Yetki_id;
                    menuSelect.value = Yetki.menu_id || '';
                    sayfaSelect.value = Yetki.sayfa_id || '';
                    document.getElementById('departman_id').value = Yetki.departman_id || '';
                    document.getElementById('gor').checked = Yetki.gor == 1;
                    document.getElementById('kendi_kullanicini_gor').checked = Yetki.kendi_kullanicini_gor == 1;
                    document.getElementById('birim_gor').checked = Yetki.birim_gor == 1;
                    document.getElementById('ekle').checked = Yetki.ekle == 1;
                    document.getElementById('duzenle').checked = Yetki.duzenle == 1;
                    document.getElementById('sil').checked = Yetki.sil == 1;
                    document.getElementById('durum').checked = Yetki.durum == 1;
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            });
        }
        
        // Yetki kaydet
        function saveYetki() {
            const formData = new FormData(document.getElementById('YetkiForm'));
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    YetkiModal.hide();
                    loadYetkiler();
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Kayıt sırasında hata oluştu', 'danger');
            });
        }
        
        // Düzenle
        function editYetki(id) {
            openModal(id);
        }
        
        // Yetki sil
        function deleteYetki(id) {
            if (!confirm('Bu Yetkiyi silmek istediğinizden emin misiniz?')) {
                return;
            }
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    loadYetkiler();
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            });
        }
        
        // Durum değiştir
        function toggleStatus(id) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=toggle_status&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    loadYetkiler();
                } else {
                    showAlert('Hata: ' + data.message, 'danger');
                }
            });
        }
        
        // Alert göster
        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3`;
            alertDiv.style.zIndex = '9999';
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 3000);
        }
    </script>
</body>
</html>

