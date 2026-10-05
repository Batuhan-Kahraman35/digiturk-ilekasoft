<?php
/**
 * Admin Panel - Menü ve Sayfa Yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Sayfa Yetki kontrolç
$currentPagefile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPagefile
);

// Sayfa erişim kontrolç
if (!$pagePermissions['has_access']) {
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim Yetkiniz bulunmamaktadir.');
}

// Mevcut sayfanin bilgilerini al
$pageinfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOiN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LiKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Menü ve Sayfa Yönetimi';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// site title'i çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Modçl klasçrç oluşturma Fonksiyonu
function createModuleFolder($menuUrl) {
    if (empty($menuUrl)) {
        return false;
    }
    
    // URL'den .php uzantisini kaldir
    $folderName = str_replace('.php', '', $menuUrl);
    $folderPath = __DIR__ . '/' . $folderName;
    
    if (!is_dir($folderPath)) {
        if (!mkdir($folderPath, 0755, true)) {
            throw new Exception('Modçl klasçrç oluşturulamadi: ' . $folderName);
        }
    }
    
    return $folderName;
}

// Klasçr yeniden adlandirma Fonksiyonu
function renameModuleFolder($oldUrl, $newUrl) {
    if (empty($oldUrl) || empty($newUrl) || $oldUrl === $newUrl) {
        return false;
    }
    
    $oldFolder = str_replace('.php', '', $oldUrl);
    $newFolder = str_replace('.php', '', $newUrl);
    
    $oldPath = __DIR__ . '/' . $oldFolder;
    $newPath = __DIR__ . '/' . $newFolder;
    
    if (is_dir($oldPath) && !is_dir($newPath)) {
        rename($oldPath, $newPath);
        return true;
    }
    
    return false;
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $action = $_POST['ajax_action'];
        
        // iSTATiSTiKLER
        if ($action === 'stats') {
            $stats = [
                'toplam_menu' => $db->fetchOne("SELECT count(*) as sayi FROM Menuler WHERE menuler_durum = 1")['sayi'] ?? 0,
                'toplam_sayfa' => $db->fetchOne("SELECT count(*) as sayi FROM Menu_Sayfalar WHERE sayfalar_durum = 1")['sayi'] ?? 0,
                'aktif_menu' => $db->fetchOne("SELECT count(*) as sayi FROM Menuler WHERE menuler_durum = 1 AND menuler_parent_id IS NULL")['sayi'] ?? 0,
                'dashboard_sayfa' => $db->fetchOne("SELECT count(*) as sayi FROM Menu_Sayfalar WHERE sayfalar_durum = 1 AND sayfalar_dashboard = 1")['sayi'] ?? 0
            ];
            echo json_encode(['success' => true, 'data' => $stats]);
            exit;
        }
        
        // MENÜ İŞLEMLERİ
        if ($action === 'menu_add') {
            // Yetki kontrolü
            if (!$pagePermissions['can_add']) {
                throw new Exception('Ekleme Yetkiniz bulunmamaktadir.');
            }
            $menuAdi = trim($_POST['menu_adi'] ?? '');
            $ikon = trim($_POST['ikon'] ?? '') ?: null;
            $parentid = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
            $siraNo = isset($_POST['sira_no']) ? (int)$_POST['sira_no'] : 0;
            $headerGoster = isset($_POST['header_goster']) ? 1 : 0;

            if (empty($menuAdi)) {
                throw new Exception('Menü adı zorunludur');
            }

            $sql = "
                INSERT INTO Menuler
                (menuler_menu_adi, menuler_ikon, menuler_parent_id, menuler_sira_no, menuler_header_goster, menuler_durum, menuler_created_at)
                VALUES (?, ?, ?, ?, ?, 1, SYSUTCDATEtime())
            ";
            $db->execute($sql, [$menuAdi, $ikon, $parentid, $siraNo, $headerGoster]);

            echo json_encode(['success' => true, 'message' => 'Menü başarıyla eklendi']);
            exit;
        }
        
        if ($action === 'menu_update') {
            // Yetki kontrolü
            if (!$pagePermissions['can_edit']) {
                throw new Exception('Düzenleme Yetkiniz bulunmamaktadır.');
            }
            
            $menuid = (int)($_POST['menu_id'] ?? 0);
            $menuAdi = trim($_POST['menu_adi'] ?? '');
            $ikon = trim($_POST['ikon'] ?? '') ?: null;
            $parentid = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
            $siraNo = isset($_POST['sira_no']) ? (int)$_POST['sira_no'] : 0;
            $headerGoster = isset($_POST['header_goster']) ? 1 : 0;

            if (empty($menuAdi)) {
                throw new Exception('Menü adı zorunludur');
            }
            if ($menuid <= 0) {
                throw new Exception('Geçersiz menü ID');
            }
            if ($parentid == $menuid) {
                throw new Exception('Bir menü kendi alt menüsü olamaz');
            }

            $sql = "
                UPDATE Menuler
                SET menuler_menu_adi = ?, menuler_ikon = ?,
                    menuler_parent_id = ?, menuler_sira_no = ?, menuler_header_goster = ?, menuler_updated_at = SYSUTCDATEtime()
                WHERE menuler_id = ?
            ";
            $db->execute($sql, [$menuAdi, $ikon, $parentid, $siraNo, $headerGoster, $menuid]);

            echo json_encode(['success' => true, 'message' => 'Menü başarıyla güncellendi']);
            exit;
        }
        
        if ($action === 'menu_delete') {
            // Yetki kontrolç
            if (!$pagePermissions['can_delete']) {
                throw new Exception('Silme Yetkiniz bulunmamaktadir.');
            }
            
            $menuid = (int)($_POST['menu_id'] ?? 0);
            if ($menuid <= 0) {
                throw new Exception('Geçersiz menü ID');
            }

            $sql = "UPDATE Menuler SET menuler_durum = 0, menuler_updated_at = SYSUTCDATEtime() WHERE menuler_id = ?";
            $db->execute($sql, [$menuid]);

            echo json_encode(['success' => true, 'message' => 'Menü başarıyla silindi']);
            exit;
        }

        // SAYFA iŞLEMLERi
        if ($action === 'page_add') {
            // Yetki kontrolç
            if (!$pagePermissions['can_add']) {
                throw new Exception('Ekleme Yetkiniz bulunmamaktadir.');
            }
            
            $sayfaAdi = trim($_POST['sayfa_adi'] ?? '');
            $sayfaUrl = trim($_POST['sayfa_url'] ?? '');
            $aciklama = trim($_POST['aciklama'] ?? '') ?: null;
            $menuid = (int)($_POST['menu_id'] ?? 0);
            $ikon = trim($_POST['ikon'] ?? '') ?: null;
            $siraNo = isset($_POST['sira_no']) ? (int)$_POST['sira_no'] : 0;
            $dashboard = isset($_POST['dashboard']) ? 1 : 0;

            if (empty($sayfaAdi)) {
                throw new Exception('Sayfa adi zorunludur');
            }
            if (empty($sayfaUrl)) {
                throw new Exception('Sayfa URL zorunludur');
            }
            if ($menuid <= 0) {
                throw new Exception('Menü seçimi zorunludur');
            }

            $sql = "
                INSERT INTO Menu_Sayfalar 
                (sayfalar_menu_id, sayfalar_sayfa_adi, sayfalar_sayfa_url, sayfalar_aciklama, 
                 sayfalar_ikon, sayfalar_sira_no, sayfalar_dashboard, sayfalar_durum, sayfalar_created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, SYSUTCDATEtime())
            ";
            $db->execute($sql, [$menuid, $sayfaAdi, $sayfaUrl, $aciklama, $ikon, $siraNo, $dashboard]);

            echo json_encode(['success' => true, 'message' => 'Sayfa basariyla eklendi']);
            exit;
        }
        
        if ($action === 'page_update') {
            // Yetki kontrolü
            if (!$pagePermissions['can_edit']) {
                throw new Exception('Düzenleme Yetkiniz bulunmamaktadır.');
            }
            
            $sayfaid = (int)($_POST['sayfa_id'] ?? 0);
            $sayfaAdi = trim($_POST['sayfa_adi'] ?? '');
            $sayfaUrl = trim($_POST['sayfa_url'] ?? '');
            $oldUrl = trim($_POST['old_url'] ?? '');
            $aciklama = trim($_POST['aciklama'] ?? '') ?: null;
            $menuid = (int)($_POST['menu_id'] ?? 0);
            $ikon = trim($_POST['ikon'] ?? '') ?: null;
            $siraNo = isset($_POST['sira_no']) ? (int)$_POST['sira_no'] : 0;
            $dashboard = isset($_POST['dashboard']) ? 1 : 0;

            if (empty($sayfaAdi)) {
                throw new Exception('Sayfa adi zorunludur');
            }
            if (empty($sayfaUrl)) {
                throw new Exception('Sayfa URL zorunludur');
            }
            if ($menuid <= 0) {
                throw new Exception('Menü seçimi zorunludur');
            }
            if ($sayfaid <= 0) {
                throw new Exception('Geçersiz sayfa id');
            }

            // Eğer URL değişmişse, fiziksel dosyayi da yeniden adlandir
            if (!empty($oldUrl) && $oldUrl !== $sayfaUrl) {
                $oldfilePath = __DIR__ . '/' . $oldUrl;
                $newfilePath = __DIR__ . '/' . $sayfaUrl;
                
                // Dosya varsa yeniden adlandir
                if (file_exists($oldfilePath)) {
                    if (file_exists($newfilePath)) {
                        throw new Exception('Hedef dosya adi zaten mevcut: ' . $sayfaUrl);
                    }
                    
                    if (!rename($oldfilePath, $newfilePath)) {
                        throw new Exception('Dosya yeniden adlandirilamadi');
                    }
                }
            }

            $sql = "
                UPDATE Menu_Sayfalar 
                SET sayfalar_menu_id = ?, sayfalar_sayfa_adi = ?, sayfalar_sayfa_url = ?,
                    sayfalar_aciklama = ?, sayfalar_ikon = ?, sayfalar_sira_no = ?,
                    sayfalar_dashboard = ?, sayfalar_updated_at = SYSUTCDATEtime()
                WHERE sayfalar_id = ?
            ";
            $db->execute($sql, [$menuid, $sayfaAdi, $sayfaUrl, $aciklama, $ikon, $siraNo, $dashboard, $sayfaid]);

            echo json_encode(['success' => true, 'message' => 'Sayfa başarıyla güncellendi' . (!empty($oldUrl) && $oldUrl !== $sayfaUrl ? ' ve dosya yeniden adlandırıldı' : '')]);
            exit;
        }
        
        if ($action === 'page_delete') {
            // Yetki kontrolç
            if (!$pagePermissions['can_delete']) {
                throw new Exception('Silme Yetkiniz bulunmamaktadir.');
            }
            
            $sayfaid = (int)($_POST['sayfa_id'] ?? 0);
            if ($sayfaid <= 0) {
                throw new Exception('Geçersiz sayfa id');
            }

            $sql = "UPDATE Menu_Sayfalar SET sayfalar_durum = 0, sayfalar_updated_at = SYSUTCDATEtime() WHERE sayfalar_id = ?";
            $db->execute($sql, [$sayfaid]);

            echo json_encode(['success' => true, 'message' => 'Sayfa basariyla silindi']);
            exit;
        }

        throw new Exception('Geçersiz işlem');

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Aktif menüleri çek (tablo ve modal select için)
// NOT: vw_tanim_menuler_aktif view'i mevcut değil, doğrudan sorgu kullanıyoruz
$menuler = $db->fetchAll("
    SELECT m.menuler_id, m.menuler_menu_adi, m.menuler_parent_id, m.menuler_sira_no, m.menuler_ikon,
           m.menuler_header_goster, p.menuler_menu_adi as menuler_parent_adi
    FROM Menuler m
    LEFT JOiN Menuler p ON m.menuler_parent_id = p.menuler_id
    WHERE m.menuler_durum = 1
    ORDER BY iSNULL(m.menuler_parent_id, 0), m.menuler_sira_no, m.menuler_menu_adi
");

// Aktif sayfaları çek (LEFT JOIN ile menüsü pasif olsa bile göster)
$sayfalar = $db->fetchAll("
    SELECT 
        s.sayfalar_id,
        s.sayfalar_menu_id,
        s.sayfalar_sayfa_adi,
        s.sayfalar_sayfa_url,
        s.sayfalar_aciklama,
        s.sayfalar_ikon,
        s.sayfalar_sira_no,
        s.sayfalar_dashboard,
        s.sayfalar_durum,
        s.sayfalar_created_at,
        s.sayfalar_updated_at,
        m.menuler_id,
        m.menuler_menu_adi,
        m.menuler_durum
    FROM Menu_Sayfalar s
    LEFT JOiN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_durum = 1
    ORDER BY iSNULL(m.menuler_menu_adi, 'ZZZ'), s.sayfalar_sira_no
");

// Ana menüler (parent_id = NULL)
$anaMenuler = $db->fetchAll("
    SELECT menuler_id, menuler_menu_adi 
    FROM Menuler 
    WHERE menuler_parent_id IS NULL AND menuler_durum = 1 
    ORDER BY menuler_sira_no
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
    <link rel="stylesheet" href="/Admin/assets/css/Adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    
    <style>
        .table-actions {
            white-space: nowrap;
        }
        .badge-parent {
            font-size: 0.75rem;
        }
        .app-sidebar {
            min-height: 100vh !important;
            height: 100% !important;
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
                                    <i class="bi bi-menu-button-wide"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Menü</span>
                                    <span class="info-box-number" id="stat-toplam-menu">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-file-earmark-text"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam Sayfa</span>
                                    <span class="info-box-number" id="stat-toplam-sayfa">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-check-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Ana Menüler</span>
                                    <span class="info-box-number" id="stat-aktif-menu">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-warning shadow-sm">
                                    <i class="bi bi-speedometer2"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Dashboard Sayfalari</span>
                                    <span class="info-box-number" id="stat-dashboard-sayfa">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Nav Tabs -->
                    <ul class="nav nav-tabs mb-3" id="managementTab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="menu-tab" data-bs-toggle="tab" data-bs-target="#menu-panel" type="button" role="tab">
                                <i class="bi bi-menu-button-wide"></i> Menüler
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="page-tab" data-bs-toggle="tab" data-bs-target="#page-panel" type="button" role="tab">
                                <i class="bi bi-file-earmark-text"></i> Sayfalar
                            </button>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content" id="managementTabContent">
                        <!-- MENÜLER TAB -->
                        <div class="tab-pane fade show active" id="menu-panel" role="tabpanel">
                            <div class="card card-primary card-outline">
                                <div class="card-header">
                                    <h3 class="card-title">Menü Listesi</h3>
                                    <div class="card-tools">
                                        <?php if ($pagePermissions['can_add']): ?>
                                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#menuAddModal">
                                            <i class="bi bi-plus-circle"></i> Yeni Menü
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th style="width: 50px">#</th>
                                                <th>Menü Adı</th>
                                                <th>İkon</th>
                                                <th>Üst Menü</th>
                                                <th style="width: 80px">Sıra</th>
                                                <th style="width: 90px" class="text-center">Header</th>
                                                <th style="width: 120px" class="text-center">İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($menuler as $index => $menu): ?>
                                            <tr>
                                                <td><?= $index + 1 ?></td>
                                                <td>
                                                    <?php if ($menu['menuler_parent_id']): ?>
                                                        <i class="bi bi-arrow-return-right text-muted"></i>
                                                    <?php endif; ?>
                                                    <strong><?= htmlspecialchars($menu['menuler_menu_adi']) ?></strong>
                                                </td>
                                                <td>
                                                    <?php if ($menu['menuler_ikon']): ?>
                                                        <i class="<?= htmlspecialchars($menu['menuler_ikon']) ?>"></i>
                                                        <small class="text-muted"><?= htmlspecialchars($menu['menuler_ikon']) ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($menu['menuler_parent_adi']): ?>
                                                        <span class="badge badge-parent text-bg-secondary"><?= htmlspecialchars($menu['menuler_parent_adi']) ?></span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-primary">Ana Menü</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge text-bg-info"><?= $menu['menuler_sira_no'] ?? '-' ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($menu['menuler_header_goster']): ?>
                                                        <span class="badge text-bg-primary"><i class="bi bi-layout-text-navbar"></i> Evet</span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-light text-muted">Hayır</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center table-actions">
                                                    <?php if ($pagePermissions['can_edit']): ?>
                                                    <button class="btn btn-sm btn-warning" onclick="editMenu(<?= htmlspecialchars(json_encode($menu)) ?>)">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php if ($pagePermissions['can_delete']): ?>
                                                    <button class="btn btn-sm btn-danger" onclick="deleteMenu(<?= $menu['menuler_id'] ?>, '<?= htmlspecialchars($menu['menuler_menu_adi']) ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($menuler)): ?>
                                            <tr>
                                                <td colspan="7" class="text-center text-muted py-4">Henüz menü eklenmemiş</td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- SAYFALAR TAB -->
                        <div class="tab-pane fade" id="page-panel" role="tabpanel">
                            <div class="card card-success card-outline">
                                <div class="card-header">
                                    <h3 class="card-title">Sayfa Listesi</h3>
                                    <div class="card-tools">
                                        <?php if ($pagePermissions['can_add']): ?>
                                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#pageAddModal">
                                            <i class="bi bi-plus-circle"></i> Yeni Sayfa
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th style="width: 50px">#</th>
                                                <th>Sayfa Adı</th>
                                                <th>URL</th>
                                                <th>Menü</th>
                                                <th>İkon</th>
                                                <th style="width: 80px">Sıra</th>
                                                <th style="width: 100px" class="text-center">Dashboard</th>
                                                <th style="width: 120px" class="text-center">İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sayfalar as $index => $sayfa): ?>
                                            <tr>
                                                <td><?= $index + 1 ?></td>
                                                <td><strong><?= htmlspecialchars($sayfa['sayfalar_sayfa_adi']) ?></strong></td>
                                                <td><code class="text-sm"><?= htmlspecialchars($sayfa['sayfalar_sayfa_url']) ?></code></td>
                                                <td>
                                                    <?php if (!empty($sayfa['menuler_menu_adi'])): ?>
                                                        <span class="badge <?= ($sayfa['menuler_durum'] ?? 0) == 1 ? 'text-bg-secondary' : 'text-bg-danger' ?>">
                                                            <?= htmlspecialchars($sayfa['menuler_menu_adi']) ?>
                                                            <?php if (($sayfa['menuler_durum'] ?? 0) != 1): ?>
                                                                <i class="bi bi-exclamation-triangle" title="Menü pasif"></i>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-warning">Menü Yok</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($sayfa['sayfalar_ikon']): ?>
                                                        <i class="<?= htmlspecialchars($sayfa['sayfalar_ikon']) ?>"></i>
                                                        <small class="text-muted"><?= htmlspecialchars($sayfa['sayfalar_ikon']) ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge text-bg-info"><?= $sayfa['sayfalar_sira_no'] ?? '-' ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($sayfa['sayfalar_dashboard']): ?>
                                                        <span class="badge text-bg-success"><i class="bi bi-check-circle"></i> Evet</span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-secondary"><i class="bi bi-x-circle"></i> Hayır</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center table-actions">
                                                    <?php if ($pagePermissions['can_edit']): ?>
                                                    <button class="btn btn-sm btn-warning" onclick="editPage(<?= htmlspecialchars(json_encode($sayfa)) ?>)">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php if ($pagePermissions['can_delete']): ?>
                                                    <button class="btn btn-sm btn-danger" onclick="deletePage(<?= $sayfa['sayfalar_id'] ?>, '<?= htmlspecialchars($sayfa['sayfalar_sayfa_adi']) ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($sayfalar)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center text-muted py-4">Henüz sayfa eklenmemiş</td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>

    <!-- MENÜ EKLEME MODAL -->
    <div class="modal fade" id="menuAddModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="menuAddForm">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Yeni Menü Ekle</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Menü Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="menu_adi" required>
                            <small class="text-muted">Menüler sadece organizasyon için kullanılır, gerçek linkler sayfalara eklenir</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">İkon</label>
                            <input type="text" class="form-control" name="ikon" placeholder="bi bi-folder">
                            <small class="text-muted">Bootstrap Icons sınıfı (örn: bi bi-folder)</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Üst Menü</label>
                            <select class="form-select" name="parent_id">
                                <option value="">Ana Menü</option>
                                <?php foreach ($anaMenuler as $anaMenu): ?>
                                <option value="<?= $anaMenu['menuler_id'] ?>"><?= htmlspecialchars($anaMenu['menuler_menu_adi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sıra No</label>
                                <input type="number" class="form-control" name="sira_no" value="0" min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Header'da Göster</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="header_goster" id="add_header_goster" value="1">
                                    <label class="form-check-label" for="add_header_goster">Navbar'da görünsün</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MENÜ DÜZENLEME MODAL -->
    <div class="modal fade" id="menuEditModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="menuEditForm">
                    <input type="hidden" name="menu_id" id="edit_menu_id">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title"><i class="bi bi-pencil"></i> Menü Düzenle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Menü Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="menu_adi" id="edit_menu_adi" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">İkon</label>
                            <input type="text" class="form-control" name="ikon" id="edit_ikon" placeholder="bi bi-folder">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Üst Menü</label>
                            <select class="form-select" name="parent_id" id="edit_parent_id">
                                <option value="">Ana Menü</option>
                                <?php foreach ($anaMenuler as $anaMenu): ?>
                                <option value="<?= $anaMenu['menuler_id'] ?>"><?= htmlspecialchars($anaMenu['menuler_menu_adi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sıra No</label>
                                <input type="number" class="form-control" name="sira_no" id="edit_sira_no" min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Header'da Göster</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="header_goster" id="edit_header_goster" value="1">
                                    <label class="form-check-label" for="edit_header_goster">Navbar'da görünsün</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-warning"><i class="bi bi-save"></i> Güncelle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- SAYFA EKLEME MODAL -->
    <div class="modal fade" id="pageAddModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="pageAddForm">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Yeni Sayfa Ekle</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sayfa Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="sayfa_adi" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sayfa URL <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="sayfa_url" placeholder="ornek-sayfa.php" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" name="aciklama" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Menü <span class="text-danger">*</span></label>
                                <select class="form-select" name="menu_id" required>
                                    <option value="">Menü Seçin</option>
                                    <?php foreach ($menuler as $menu): ?>
                                    <option value="<?= $menu['menuler_id'] ?>">
                                        <?php if ($menu['menuler_parent_id']): ?>⤷ <?php endif; ?>
                                        <?= htmlspecialchars($menu['menuler_menu_adi']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">İkon</label>
                                <input type="text" class="form-control" name="ikon" placeholder="bi bi-file-text">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sıra No</label>
                                <input type="number" class="form-control" name="sira_no" value="0" min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Dashboard Göster</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="dashboard" id="dashboard_check" value="1">
                                    <label class="form-check-label" for="dashboard_check">Ana sayfada göster</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- SAYFA DÜZENLEME MODAL -->
    <div class="modal fade" id="pageEditModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="pageEditForm">
                    <input type="hidden" name="sayfa_id" id="edit_sayfa_id">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title"><i class="bi bi-pencil"></i> Sayfa Düzenle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sayfa Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="sayfa_adi" id="edit_sayfa_adi" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sayfa URL <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="sayfa_url" id="edit_sayfa_url" required>
                                <small class="text-warning">
                                    <i class="bi bi-exclamation-triangle"></i> 
                                    <strong>Uyarı:</strong> URL değiştirildiğinde fiziksel dosya adı da otomatik olarak değiştirilecektir.
                                </small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" name="aciklama" id="edit_aciklama" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Menü <span class="text-danger">*</span></label>
                                <select class="form-select" name="menu_id" id="edit_menu_id_select" required>
                                    <option value="">Menü Seçin</option>
                                    <?php foreach ($menuler as $menu): ?>
                                    <option value="<?= $menu['menuler_id'] ?>">
                                        <?php if ($menu['menuler_parent_id']): ?>⤷ <?php endif; ?>
                                        <?= htmlspecialchars($menu['menuler_menu_adi']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">İkon</label>
                                <input type="text" class="form-control" name="ikon" id="edit_sayfa_ikon">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sıra No</label>
                                <input type="number" class="form-control" name="sira_no" id="edit_sayfa_sira_no" min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Dashboard Göster</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="dashboard" id="edit_dashboard_check" value="1">
                                    <label class="form-check-label" for="edit_dashboard_check">Ana sayfada göster</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-warning"><i class="bi bi-save"></i> Güncelle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>
    
    <script>
        // Sayfa yetkileri
        const permissions = <?= json_encode($pagePermissions) ?>;
        
        // istatistikleri yçkle
        function loadStats() {
            $.post('', { ajax_action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam-menu').text(response.data.toplam_menu);
                    $('#stat-toplam-sayfa').text(response.data.toplam_sayfa);
                    $('#stat-aktif-menu').text(response.data.aktif_menu);
                    $('#stat-dashboard-sayfa').text(response.data.dashboard_sayfa);
                }
            });
        }
        
        // Select2 başlat
        function initSelect2() {
            $('.form-select').each(function() {
                const $this = $(this);
                const modalid = $this.closest('.modal').attr('id');
                
                // Eğer zaten Select2 varsa yok et
                if ($this.data('select2')) {
                    $this.select2('destroy');
                }
                
                // Yeni Select2 başlat
                const config = {
                    theme: 'bootstrap-5',
                    width: '100%',
                    placeholder: 'Seçiniz...',
                    allowClear: true,
                    language: {
                        noResults: function() { return "Sonuç bulunamadi"; },
                        searching: function() { return "Araniyor..."; }
                    }
                };
                
                // Modal içindeyse dropdownParent ekle
                if (modalid) {
                    config.dropdownParent = $('#' + modalid);
                }
                
                $this.select2(config);
            });
        }
        
        // Sayfa hazir
        $(document).ready(() => {
            loadStats();
            initSelect2();
        });
        
        // Menü Fonksiyonları
        function editMenu(menu) {
            document.getElementById('edit_menu_id').value = menu.menuler_id;
            document.getElementById('edit_menu_adi').value = menu.menuler_menu_adi;
            document.getElementById('edit_ikon').value = menu.menuler_ikon || '';
            document.getElementById('edit_parent_id').value = menu.menuler_parent_id || '';
            document.getElementById('edit_sira_no').value = menu.menuler_sira_no || 0;
            document.getElementById('edit_header_goster').checked = menu.menuler_header_goster == 1;

            new bootstrap.Modal(document.getElementById('menuEditModal')).show();
        }

        function deleteMenu(id, name) {
            confirmAction(
                `"${name}" menüsünü silmek istediğinize emin misiniz?`,
                'Bu işlem alt menüleri ve ilgili sayfaları da silecektir!',
                function() {
                    const formData = new FormData();
                    formData.append('ajax_action', 'menu_delete');
                    formData.append('menu_id', id);

                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess('Silindi!', data.message);
                            setTimeout(() => window.location.reload(), 1500);
                        } else {
                            showError('Hata!', data.message);
                        }
                    })
                    .catch(error => {
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }

        // SAYFA Fonksiyonlari
        let originalPageUrl = '';
        
        function editPage(sayfa) {
            document.getElementById('edit_sayfa_id').value = sayfa.sayfalar_id;
            document.getElementById('edit_sayfa_adi').value = sayfa.sayfalar_sayfa_adi;
            document.getElementById('edit_sayfa_url').value = sayfa.sayfalar_sayfa_url;
            document.getElementById('edit_aciklama').value = sayfa.sayfalar_aciklama || '';
            document.getElementById('edit_menu_id_select').value = sayfa.menuler_id;
            document.getElementById('edit_sayfa_ikon').value = sayfa.sayfalar_ikon || '';
            document.getElementById('edit_sayfa_sira_no').value = sayfa.sayfalar_sira_no || 0;
            document.getElementById('edit_dashboard_check').checked = sayfa.sayfalar_dashboard == 1;
            
            // Orijinal URL'yi sakla
            originalPageUrl = sayfa.sayfalar_sayfa_url;
            
            // URL input alanini resetle (border rengini normal yap)
            const urlinput = document.getElementById('edit_sayfa_url');
            urlinput.classList.remove('border-warning', 'border-danger');
            
            new bootstrap.Modal(document.getElementById('pageEditModal')).show();
        }
        
        // URL değişikliğini izle
        document.addEventListener('DOMContentLoaded', function() {
            const editUrlinput = document.getElementById('edit_sayfa_url');
            
            editUrlinput.addEventListener('input', function() {
                const currentUrl = this.value.trim();
                
                if (currentUrl !== originalPageUrl && originalPageUrl !== '') {
                    // URL değişmiş, uyari rengi ver
                    this.classList.add('border-warning');
                    this.classList.remove('border-danger');
                } else {
                    // URL ayni, normal renk
                    this.classList.remove('border-warning', 'border-danger');
                }
            });

            // .php uzantısı kontrolü
            const urlInputs = document.querySelectorAll('input[name="sayfa_url"]');
            urlInputs.forEach(input => {
                input.addEventListener('blur', function() {
                    let val = this.value.trim();
                    if (val !== '' && !val.toLowerCase().endsWith('.php')) {
                        this.value = val + '.php';
                        // Edit modundaysa input eventini tetikle (renk değişimi için)
                        this.dispatchEvent(new Event('input'));
                    }
                });
            });
        });

        function deletePage(id, name) {
            confirmAction(
                `"${name}" sayfasını silmek istediğinizden emin misiniz?`,
                'Bu işlem geri alınamaz!',
                function() {
                    const formData = new FormData();
                    formData.append('ajax_action', 'page_delete');
                    formData.append('sayfa_id', id);

                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess('Silindi!', data.message);
                            setTimeout(() => window.location.reload(), 1500);
                        } else {
                            showError('Hata!', data.message);
                        }
                    })
                    .catch(error => {
                        showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
                    });
                }
            );
        }

        // FORM SUBMIT HANDLER'LARI
        document.getElementById('menuAddForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('ajax_action', 'menu_add');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('menuAddModal')).hide();
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Sunucuya ulaşılamıyor', 'error');
            });
        });

        document.getElementById('menuEditForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('ajax_action', 'menu_update');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('menuEditModal')).hide();
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Sunucuya ulaşılamıyor', 'error');
            });
        });

        document.getElementById('pageAddForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // URL .php kontrolü
            const urlInput = this.querySelector('input[name="sayfa_url"]');
            let urlVal = urlInput.value.trim();
            if (urlVal !== '' && !urlVal.toLowerCase().endsWith('.php')) {
                urlInput.value = urlVal + '.php';
            }

            const formData = new FormData(this);
            formData.append('ajax_action', 'page_add');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('pageAddModal')).hide();
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Sunucuya ulaşılamıyor', 'error');
            });
        });

        document.getElementById('pageEditForm').addEventListener('submit', function(e) {
            e.preventDefault();

            // URL .php kontrolü
            const urlInput = document.getElementById('edit_sayfa_url');
            let urlVal = urlInput.value.trim();
            if (urlVal !== '' && !urlVal.toLowerCase().endsWith('.php')) {
                urlInput.value = urlVal + '.php';
            }

            const formData = new FormData(this);
            formData.append('ajax_action', 'page_update');
            formData.append('old_url', originalPageUrl);

            const newUrl = document.getElementById('edit_sayfa_url').value;
            
            if (originalPageUrl !== newUrl) {
                confirmAction(
                    'Sayfa URL\'si değiştirilecek',
                    `Eski: ${originalPageUrl}\nYeni: ${newUrl}\n\nFiziksel dosya adı da değiştirilecektir.`,
                    function() {
                        submitPageUpdate(formData);
                    }
                );
            } else {
                submitPageUpdate(formData);
            }
        });

        function submitPageUpdate(formData) {
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('pageEditModal')).hide();
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Sunucuya ulaşılamıyor', 'error');
            });
        }
    </script>
</body>
</html>
