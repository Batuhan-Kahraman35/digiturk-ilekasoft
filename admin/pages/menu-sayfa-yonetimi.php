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

// Menu_SayfaYetkileri yetki kolonları (kolon adı => başlık); AJAX'ta whitelist olarak da kullanılır
$yetkiAlanlari = [
    'gor' => 'Gör',
    'kendi_kullanicini_gor' => 'Kendi Kayıtları',
    'birim_gor' => 'Birim Gör',
    'ekle' => 'Ekle',
    'duzenle' => 'Düzenle',
    'sil' => 'Sil'
];

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

        // MENÜ SIRALAMA (sürükle-bırak)
        if ($action === 'menu_reorder') {
            if (!$pagePermissions['can_edit']) {
                throw new Exception('Düzenleme Yetkiniz bulunmamaktadır.');
            }

            $siralar = json_decode($_POST['siralar'] ?? '[]', true);
            if (!is_array($siralar) || empty($siralar)) {
                throw new Exception('Sıralama verisi geçersiz');
            }

            foreach ($siralar as $satir) {
                $id = (int)($satir['id'] ?? 0);
                $sira = (int)($satir['sira'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $db->execute(
                    "UPDATE Menuler SET menuler_sira_no = ?, menuler_updated_at = SYSUTCDATEtime() WHERE menuler_id = ?",
                    [$sira, $id]
                );
            }

            echo json_encode(['success' => true, 'message' => 'Menü sıralaması güncellendi']);
            exit;
        }

        // SAYFA SIRALAMA (sürükle-bırak)
        if ($action === 'page_reorder') {
            if (!$pagePermissions['can_edit']) {
                throw new Exception('Düzenleme Yetkiniz bulunmamaktadır.');
            }

            $siralar = json_decode($_POST['siralar'] ?? '[]', true);
            if (!is_array($siralar) || empty($siralar)) {
                throw new Exception('Sıralama verisi geçersiz');
            }

            foreach ($siralar as $satir) {
                $id = (int)($satir['id'] ?? 0);
                $sira = (int)($satir['sira'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $db->execute(
                    "UPDATE Menu_Sayfalar SET sayfalar_sira_no = ?, sayfalar_updated_at = SYSUTCDATEtime() WHERE sayfalar_id = ?",
                    [$sira, $id]
                );
            }

            echo json_encode(['success' => true, 'message' => 'Sayfa sıralaması güncellendi']);
            exit;
        }

        // YETKİ İŞLEMLERİ (Menu_SayfaYetkileri: menü kaydı menu_id, sayfa kaydı sayfa_id ile tutulur)
        if (in_array($action, ['yetki_liste', 'yetki_ekle'], true)) {
            $tip = $_POST['tip'] ?? '';
            $kayitId = (int)($_POST['kayit_id'] ?? 0);
            if (!in_array($tip, ['menu', 'sayfa'], true) || $kayitId <= 0) {
                throw new Exception('Geçersiz menü/sayfa');
            }
            $yetkiKolon = $tip === 'menu' ? 'menu_id' : 'sayfa_id';
            $digerKolon = $tip === 'menu' ? 'sayfa_id' : 'menu_id';
        }

        if ($action === 'yetki_liste') {
            $yetkiler = $db->fetchAll("
                SELECT y.menu_sayfa_yetki_id AS yetki_id, y.departman_id, d.departman_adi,
                       y.gor, y.kendi_kullanicini_gor, y.birim_gor, y.ekle, y.duzenle, y.sil, y.durum
                FROM Menu_SayfaYetkileri y
                LEFT JOIN kullanici_Departmanlar d ON d.departman_id = y.departman_id
                WHERE y.{$yetkiKolon} = ? AND y.{$digerKolon} IS NULL
                ORDER BY d.departman_adi
            ", [$kayitId]);

            echo json_encode(['success' => true, 'data' => $yetkiler]);
            exit;
        }

        if ($action === 'yetki_ekle') {
            if (!$pagePermissions['can_add']) {
                throw new Exception('Ekleme Yetkiniz bulunmamaktadır.');
            }

            $departmanIds = isset($_POST['departman_id']) && is_array($_POST['departman_id'])
                ? array_filter(array_map('intval', $_POST['departman_id']))
                : [];
            if (empty($departmanIds)) {
                throw new Exception('En az bir departman seçmelisiniz');
            }

            $yetkiData = ['durum' => 1];
            foreach (array_keys($yetkiAlanlari) as $alan) {
                $yetkiData[$alan] = isset($_POST[$alan]) ? 1 : 0;
            }

            $eklenen = 0;
            $atlanan = 0;
            foreach ($departmanIds as $departmanId) {
                $mevcut = $db->fetchOne("
                    SELECT menu_sayfa_yetki_id FROM Menu_SayfaYetkileri
                    WHERE departman_id = ? AND {$yetkiKolon} = ? AND {$digerKolon} IS NULL
                ", [$departmanId, $kayitId]);
                if ($mevcut) {
                    $atlanan++;
                    continue;
                }

                $db->insert('Menu_SayfaYetkileri', array_merge($yetkiData, [
                    'departman_id' => $departmanId,
                    $yetkiKolon => $kayitId,
                    $digerKolon => null,
                    'created_at' => date('Y-m-d H:i:s')
                ]));
                $eklenen++;
            }

            $mesaj = "$eklenen yetki eklendi" . ($atlanan > 0 ? ", $atlanan departmanda zaten vardı (atlandı)" : '');
            echo json_encode(['success' => true, 'message' => $mesaj]);
            exit;
        }

        if ($action === 'yetki_guncelle') {
            if (!$pagePermissions['can_edit']) {
                throw new Exception('Düzenleme Yetkiniz bulunmamaktadır.');
            }

            $yetkiId = (int)($_POST['yetki_id'] ?? 0);
            $alan = $_POST['alan'] ?? '';
            // Kolon adı istemciden geldiği için whitelist ile doğrulanır
            if ($yetkiId <= 0 || ($alan !== 'durum' && !array_key_exists($alan, $yetkiAlanlari))) {
                throw new Exception('Geçersiz yetki alanı');
            }

            $db->update('Menu_SayfaYetkileri',
                [$alan => !empty($_POST['deger']) ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['menu_sayfa_yetki_id' => $yetkiId]
            );

            echo json_encode(['success' => true, 'message' => 'Yetki güncellendi']);
            exit;
        }

        if ($action === 'yetki_sil') {
            if (!$pagePermissions['can_delete']) {
                throw new Exception('Silme Yetkiniz bulunmamaktadır.');
            }

            $yetkiId = (int)($_POST['yetki_id'] ?? 0);
            if ($yetkiId <= 0) {
                throw new Exception('Geçersiz yetki id');
            }

            $db->delete('Menu_SayfaYetkileri', ['menu_sayfa_yetki_id' => $yetkiId]);
            echo json_encode(['success' => true, 'message' => 'Yetki silindi']);
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
    ORDER BY iSNULL(m.menuler_menu_adi, 'ZZZ'), s.sayfalar_menu_id, s.sayfalar_sira_no
");

// Ana menüler (parent_id = NULL)
$anaMenuler = $db->fetchAll("
    SELECT menuler_id, menuler_menu_adi 
    FROM Menuler 
    WHERE menuler_parent_id IS NULL AND menuler_durum = 1
    ORDER BY menuler_sira_no
");

// Aktif departmanlar (yetki modalı için)
$departmanlar = $db->fetchAll("
    SELECT departman_id, departman_adi
    FROM kullanici_Departmanlar
    WHERE departman_durum = 1
    ORDER BY departman_adi
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
        .drag-handle {
            cursor: grab;
            color: #adb5bd;
        }
        .drag-handle:active {
            cursor: grabbing;
        }
        .sortable-ghost {
            opacity: 0.4;
            background-color: #cfe2ff !important;
        }
        .sortable-chosen {
            background-color: #e9ecef !important;
        }
        .sortable-drag {
            opacity: 0.9;
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
                            <!-- Filtre -->
                            <div class="card card-outline card-secondary mb-3">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#menuFilterCard">
                                            <i class="bi bi-chevron-down"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body collapse" id="menuFilterCard">
                                    <form id="menuFilterForm">
                                        <div class="row g-3">
                                            <div class="col-md-5">
                                                <label class="form-label">Ara</label>
                                                <input type="text" class="form-control" id="filter_menu_ara" placeholder="Menü adı veya ikon...">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Üst Menü</label>
                                                <select class="form-select" id="filter_menu_ust">
                                                    <option value="">Tümü</option>
                                                    <option value="0">Ana Menüler</option>
                                                    <?php foreach ($anaMenuler as $am): ?>
                                                    <option value="<?= $am['menuler_id'] ?>"><?= htmlspecialchars($am['menuler_menu_adi']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Header</label>
                                                <select class="form-select" id="filter_menu_header">
                                                    <option value="">Tümü</option>
                                                    <option value="1">Evet</option>
                                                    <option value="0">Hayır</option>
                                                </select>
                                            </div>
                                            <div class="col-md-12">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-search"></i> Filtrele
                                                </button>
                                                <button type="button" class="btn btn-secondary" id="menuFilterTemizle">
                                                    <i class="bi bi-x-circle"></i> Temizle
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
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
                                                <?php if ($pagePermissions['can_edit']): ?>
                                                <th style="width: 40px"></th>
                                                <?php endif; ?>
                                                <th style="width: 50px">#</th>
                                                <th>Menü Adı</th>
                                                <th>İkon</th>
                                                <th>Üst Menü</th>
                                                <th style="width: 80px">Sıra</th>
                                                <th style="width: 90px" class="text-center">Header</th>
                                                <th style="width: 160px" class="text-center">İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody id="menuTableBody">
                                            <?php foreach ($menuler as $index => $menu): ?>
                                            <tr data-id="<?= $menu['menuler_id'] ?>" data-grup="<?= $menu['menuler_parent_id'] ?? 0 ?>"
                                                data-ara="<?= htmlspecialchars($menu['menuler_menu_adi'] . ' ' . ($menu['menuler_ikon'] ?? '')) ?>"
                                                data-header="<?= $menu['menuler_header_goster'] ? 1 : 0 ?>">
                                                <?php if ($pagePermissions['can_edit']): ?>
                                                <td class="text-center drag-handle" title="Sürükleyerek sırala"><i class="bi bi-grip-vertical"></i></td>
                                                <?php endif; ?>
                                                <td class="row-index"><?= $index + 1 ?></td>
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
                                                    <span class="badge text-bg-info sira-badge"><?= $menu['menuler_sira_no'] ?? '-' ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($menu['menuler_header_goster']): ?>
                                                        <span class="badge text-bg-primary"><i class="bi bi-layout-text-navbar"></i> Evet</span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-light text-muted">Hayır</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center table-actions">
                                                    <button class="btn btn-sm btn-info" title="Yetkiler" onclick="yetkiModalAc('menu', <?= $menu['menuler_id'] ?>, <?= htmlspecialchars(json_encode($menu['menuler_menu_adi'])) ?>)">
                                                        <i class="bi bi-shield-lock"></i>
                                                    </button>
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
                                            <tr class="filtre-bos d-none">
                                                <td colspan="<?= $pagePermissions['can_edit'] ? 8 : 7 ?>" class="text-center text-muted py-4">Filtreye uygun menü bulunamadı</td>
                                            </tr>
                                            <?php if (empty($menuler)): ?>
                                            <tr>
                                                <td colspan="<?= $pagePermissions['can_edit'] ? 8 : 7 ?>" class="text-center text-muted py-4">Henüz menü eklenmemiş</td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- SAYFALAR TAB -->
                        <div class="tab-pane fade" id="page-panel" role="tabpanel">
                            <!-- Filtre -->
                            <div class="card card-outline card-secondary mb-3">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#pageFilterCard">
                                            <i class="bi bi-chevron-down"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body collapse" id="pageFilterCard">
                                    <form id="pageFilterForm">
                                        <div class="row g-3">
                                            <div class="col-md-5">
                                                <label class="form-label">Ara</label>
                                                <input type="text" class="form-control" id="filter_sayfa_ara" placeholder="Sayfa adı veya URL...">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Menü</label>
                                                <select class="form-select" id="filter_sayfa_menu">
                                                    <option value="">Tümü</option>
                                                    <option value="0">Menü Yok</option>
                                                    <?php foreach ($menuler as $m): ?>
                                                    <option value="<?= $m['menuler_id'] ?>"><?= htmlspecialchars(($m['menuler_parent_adi'] ? $m['menuler_parent_adi'] . ' › ' : '') . $m['menuler_menu_adi']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Dashboard</label>
                                                <select class="form-select" id="filter_sayfa_dashboard">
                                                    <option value="">Tümü</option>
                                                    <option value="1">Evet</option>
                                                    <option value="0">Hayır</option>
                                                </select>
                                            </div>
                                            <div class="col-md-12">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-search"></i> Filtrele
                                                </button>
                                                <button type="button" class="btn btn-secondary" id="pageFilterTemizle">
                                                    <i class="bi bi-x-circle"></i> Temizle
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
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
                                                <?php if ($pagePermissions['can_edit']): ?>
                                                <th style="width: 40px"></th>
                                                <?php endif; ?>
                                                <th style="width: 50px">#</th>
                                                <th>Sayfa Adı</th>
                                                <th>URL</th>
                                                <th>Menü</th>
                                                <th>İkon</th>
                                                <th style="width: 80px">Sıra</th>
                                                <th style="width: 100px" class="text-center">Dashboard</th>
                                                <th style="width: 160px" class="text-center">İşlemler</th>
                                            </tr>
                                        </thead>
                                        <tbody id="pageTableBody">
                                            <?php foreach ($sayfalar as $index => $sayfa): ?>
                                            <tr data-id="<?= $sayfa['sayfalar_id'] ?>" data-grup="<?= $sayfa['sayfalar_menu_id'] ?? 0 ?>"
                                                data-ara="<?= htmlspecialchars($sayfa['sayfalar_sayfa_adi'] . ' ' . $sayfa['sayfalar_sayfa_url']) ?>"
                                                data-dashboard="<?= $sayfa['sayfalar_dashboard'] ? 1 : 0 ?>">
                                                <?php if ($pagePermissions['can_edit']): ?>
                                                <td class="text-center drag-handle" title="Sürükleyerek sırala"><i class="bi bi-grip-vertical"></i></td>
                                                <?php endif; ?>
                                                <td class="row-index"><?= $index + 1 ?></td>
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
                                                    <span class="badge text-bg-info sira-badge"><?= $sayfa['sayfalar_sira_no'] ?? '-' ?></span>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($sayfa['sayfalar_dashboard']): ?>
                                                        <span class="badge text-bg-success"><i class="bi bi-check-circle"></i> Evet</span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-secondary"><i class="bi bi-x-circle"></i> Hayır</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center table-actions">
                                                    <button class="btn btn-sm btn-info" title="Yetkiler" onclick="yetkiModalAc('sayfa', <?= $sayfa['sayfalar_id'] ?>, <?= htmlspecialchars(json_encode($sayfa['sayfalar_sayfa_adi'])) ?>)">
                                                        <i class="bi bi-shield-lock"></i>
                                                    </button>
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
                                            <tr class="filtre-bos d-none">
                                                <td colspan="<?= $pagePermissions['can_edit'] ? 9 : 8 ?>" class="text-center text-muted py-4">Filtreye uygun sayfa bulunamadı</td>
                                            </tr>
                                            <?php if (empty($sayfalar)): ?>
                                            <tr>
                                                <td colspan="<?= $pagePermissions['can_edit'] ? 9 : 8 ?>" class="text-center text-muted py-4">Henüz sayfa eklenmemiş</td>
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
                            <select class="ikon-select" name="ikon" id="add_menu_ikon"><option value=""></option></select>
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
                            <select class="ikon-select" name="ikon" id="edit_ikon"><option value=""></option></select>
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
                                <select class="ikon-select" name="ikon" id="add_sayfa_ikon"><option value=""></option></select>
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
                                <select class="ikon-select" name="ikon" id="edit_sayfa_ikon"><option value=""></option></select>
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

    <!-- YETKİ MODAL -->
    <div class="modal fade" id="yetkiModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-shield-lock"></i> Yetkiler: <span id="yetkiBaslik"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border py-2 small mb-3">
                        <i class="bi bi-info-circle"></i> Administrator departmanı tüm sayfalara yetki kaydı olmadan erişir.
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Departman</th>
                                    <?php foreach ($yetkiAlanlari as $baslik): ?>
                                    <th class="text-center"><?= htmlspecialchars($baslik) ?></th>
                                    <?php endforeach; ?>
                                    <th class="text-center">Durum</th>
                                    <?php if ($pagePermissions['can_delete']): ?>
                                    <th class="text-center" style="width: 60px">Sil</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="yetkiTabloBody"></tbody>
                        </table>
                    </div>

                    <?php if ($pagePermissions['can_add']): ?>
                    <hr>
                    <h6 class="mb-3"><i class="bi bi-plus-circle"></i> Yeni Yetki Ekle</h6>
                    <form id="yetkiEkleForm">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label">Departman <span class="text-danger">*</span></label>
                                <select class="yetki-departman-select" id="yetki_departman" name="departman_id[]" multiple></select>
                            </div>
                            <div class="col-md-7">
                                <div class="d-flex flex-wrap gap-3">
                                    <?php foreach ($yetkiAlanlari as $alan => $baslik): ?>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="yetki_yeni_<?= $alan ?>" name="<?= $alan ?>" value="1" <?= $alan === 'gor' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="yetki_yeni_<?= $alan ?>"><?= htmlspecialchars($baslik) ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 text-end">
                                <button type="submit" class="btn btn-info"><i class="bi bi-plus-circle"></i> Yetki Ekle</button>
                            </div>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>
    
    <script>
        // Sayfa yetkileri
        const permissions = <?= json_encode($pagePermissions) ?>;

        // Sistemde kullanılan ikonlar (Menuler + Menu_Sayfalar)
        const kullanilanIkonlar = <?= json_encode(array_values(array_unique(array_filter(array_merge(
            array_column($menuler, 'menuler_ikon'),
            array_column($sayfalar, 'sayfalar_ikon')
        ))))) ?>;
        
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
        
        // İKON SEÇİMİ
        // Liste, sayfanın yüklediği bootstrap-icons sürümünün kendi ikon listesinden gelir
        const IKON_JSON = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.json';

        function ikonSablon(item) {
            if (!item.id) return item.text;
            return $('<span>').append($('<i>').attr('class', item.id).addClass('me-2'), document.createTextNode(item.text));
        }

        function initIkonSelect() {
            fetch(IKON_JSON)
                .then(r => r.json())
                .then(json => Object.keys(json).map(ad => 'bi bi-' + ad))
                .catch(() => {
                    showToast('İkon listesi yüklenemedi, yalnız kullanılan ikonlar listelendi', 'warning');
                    return [];
                })
                .then(tumIkonlar => {
                    const kullanilan = kullanilanIkonlar.slice().sort();
                    const data = [];
                    if (kullanilan.length) {
                        data.push({ text: 'Sistemde Kullanılanlar', children: kullanilan.map(v => ({ id: v, text: v })) });
                    }
                    const digerleri = tumIkonlar.filter(v => !kullanilan.includes(v));
                    if (digerleri.length) {
                        data.push({ text: 'Tüm İkonlar', children: digerleri.map(v => ({ id: v, text: v })) });
                    }

                    $('.ikon-select').each(function() {
                        const $sel = $(this);
                        const mevcut = $sel.val();
                        if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
                        $sel.find('option[value!=""]').remove();
                        $sel.select2({
                            theme: 'bootstrap-5',
                            width: '100%',
                            placeholder: 'İkon seçiniz...',
                            allowClear: true,
                            dropdownParent: $sel.closest('.modal'),
                            data: data,
                            templateResult: ikonSablon,
                            templateSelection: ikonSablon,
                            language: {
                                noResults: function() { return "Sonuç bulunamadi"; },
                                searching: function() { return "Araniyor..."; }
                            }
                        });
                        if (mevcut) ikonSec($sel, mevcut);
                    });
                });
        }

        // Listede olmayan (eski) bir değer gelirse seçenek olarak eklenir, kayıt kaybolmaz
        function ikonSec(selector, deger) {
            const $sel = $(selector);
            deger = deger || '';
            if (deger && !$sel.find('option').filter((i, o) => o.value === deger).length) {
                $sel.append(new Option(deger, deger, false, false));
            }
            $sel.val(deger).trigger('change');
        }

        // YETKİ MODALI
        const yetkiAlanlari = <?= json_encode($yetkiAlanlari) ?>;
        const departmanlar = <?= json_encode($departmanlar) ?>;
        let yetkiKayit = { tip: '', id: 0 };

        function htmlKacir(str) {
            return $('<div>').text(str ?? '').html();
        }

        function yetkiModalAc(tip, id, ad) {
            yetkiKayit = { tip: tip, id: id };
            $('#yetkiBaslik').text((tip === 'menu' ? 'Menü - ' : 'Sayfa - ') + ad);
            yetkiListele();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('yetkiModal')).show();
        }

        function yetkiListele() {
            const colspan = Object.keys(yetkiAlanlari).length + 2 + (permissions.can_delete ? 1 : 0);
            $('#yetkiTabloBody').html(`<tr><td colspan="${colspan}" class="text-center text-muted py-3">Yükleniyor...</td></tr>`);

            $.post('', { ajax_action: 'yetki_liste', tip: yetkiKayit.tip, kayit_id: yetkiKayit.id }, response => {
                if (!response.success) {
                    showToast(response.message, 'error');
                    return;
                }

                const satirlar = response.data;
                if (!satirlar.length) {
                    $('#yetkiTabloBody').html(`<tr><td colspan="${colspan}" class="text-center text-muted py-3">Henüz yetki verilmemiş</td></tr>`);
                } else {
                    $('#yetkiTabloBody').html(satirlar.map(y => {
                        const anahtar = (alan) => `
                            <td>
                                <div class="form-check form-switch d-flex justify-content-center">
                                    <input class="form-check-input yetki-toggle" type="checkbox" role="switch"
                                        id="yetki_${y.yetki_id}_${alan}" data-id="${y.yetki_id}" data-alan="${alan}"
                                        ${y[alan] == 1 ? 'checked' : ''} ${permissions.can_edit ? '' : 'disabled'}>
                                    <label class="form-check-label" for="yetki_${y.yetki_id}_${alan}"></label>
                                </div>
                            </td>`;
                        return `
                            <tr class="${y.durum == 1 ? '' : 'table-secondary'}">
                                <td><strong>${htmlKacir(y.departman_adi || ('#' + y.departman_id))}</strong></td>
                                ${Object.keys(yetkiAlanlari).map(anahtar).join('')}
                                ${anahtar('durum')}
                                ${permissions.can_delete ? `
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-danger" onclick="yetkiSil(${y.yetki_id}, ${htmlKacir(JSON.stringify(y.departman_adi || ''))})">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>` : ''}
                            </tr>`;
                    }).join(''));
                }

                yetkiDepartmanSelectYenile(satirlar.map(y => String(y.departman_id)));
            }).fail(xhr => showToast(xhr.responseJSON?.message || 'Sunucuya ulaşılamıyor', 'error'));
        }

        // Zaten yetkisi olan departmanlar listeden çıkarılır (sıra: close+destroy → html → init)
        function yetkiDepartmanSelectYenile(mevcutlar) {
            const $sel = $('#yetki_departman');
            if (!$sel.length) return;

            if ($sel.hasClass('select2-hidden-accessible')) {
                $sel.select2('close');
                $sel.select2('destroy');
            }
            $sel.html(departmanlar
                .filter(d => !mevcutlar.includes(String(d.departman_id)))
                .map(d => `<option value="${d.departman_id}">${htmlKacir(d.departman_adi)}</option>`)
                .join(''));
            $sel.select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Departman seçiniz...',
                dropdownParent: $('#yetkiModal'),
                closeOnSelect: false,
                language: {
                    noResults: function() { return "Yetkisi olmayan departman kalmadı"; },
                    searching: function() { return "Araniyor..."; }
                }
            });
        }

        $(document).on('change', '.yetki-toggle', function() {
            const $chk = $(this);
            $.post('', {
                ajax_action: 'yetki_guncelle',
                yetki_id: $chk.data('id'),
                alan: $chk.data('alan'),
                deger: $chk.is(':checked') ? 1 : 0
            }, response => {
                showToast(response.message, response.success ? 'success' : 'error');
                if ($chk.data('alan') === 'durum') {
                    $chk.closest('tr').toggleClass('table-secondary', !$chk.is(':checked'));
                }
            }).fail(xhr => {
                $chk.prop('checked', !$chk.is(':checked'));
                showToast(xhr.responseJSON?.message || 'Sunucuya ulaşılamıyor', 'error');
            });
        });

        function yetkiSil(id, departman) {
            confirmAction(
                `"${departman}" departmanının yetkisini silmek istediğinize emin misiniz?`,
                'Bu departman bu sayfaya erişemeyecek.',
                function() {
                    $.post('', { ajax_action: 'yetki_sil', yetki_id: id }, response => {
                        showToast(response.message, response.success ? 'success' : 'error');
                        yetkiListele();
                    }).fail(xhr => showToast(xhr.responseJSON?.message || 'Sunucuya ulaşılamıyor', 'error'));
                }
            );
        }

        $('#yetkiEkleForm').on('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('ajax_action', 'yetki_ekle');
            formData.append('tip', yetkiKayit.tip);
            formData.append('kayit_id', yetkiKayit.id);

            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    showToast(data.message, data.success ? 'success' : 'error');
                    if (data.success) yetkiListele();
                })
                .catch(() => showToast('Sunucuya ulaşılamıyor', 'error'));
        });

        // SÜRÜKLE-BIRAK SIRALAMA
        function initSortable(tbodyId, ajaxAction) {
            const tbody = document.getElementById(tbodyId);
            if (!tbody || !permissions.can_edit) {
                return;
            }

            Sortable.create(tbody, {
                handle: '.drag-handle',
                animation: 150,
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                dragClass: 'sortable-drag',
                // Sadece ayni grup icinde tasimaya izin ver
                onMove: function(evt) {
                    return evt.dragged.dataset.grup === evt.related.dataset.grup;
                },
                onEnd: function(evt) {
                    if (evt.oldIndex === evt.newIndex) {
                        return;
                    }
                    kaydetSiralama(tbody, ajaxAction);
                }
            });
        }

        function kaydetSiralama(tbody, ajaxAction) {
            const satirlar = tbody.querySelectorAll('tr[data-id]');
            const grupSayaci = {};
            const siralar = [];

            satirlar.forEach((tr, i) => {
                const grup = tr.dataset.grup;
                grupSayaci[grup] = (grupSayaci[grup] || 0) + 1;
                const sira = grupSayaci[grup];

                siralar.push({ id: parseInt(tr.dataset.id), sira: sira });

                // Ekrandaki sira ve satir numarasini guncelle
                const badge = tr.querySelector('.sira-badge');
                if (badge) badge.textContent = sira;
                const idx = tr.querySelector('.row-index');
                if (idx) idx.textContent = i + 1;
            });

            const formData = new FormData();
            formData.append('ajax_action', ajaxAction);
            formData.append('siralar', JSON.stringify(siralar));

            fetch('', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    showToast(data.message, data.success ? 'success' : 'error');
                })
                .catch(() => showToast('Sunucuya ulaşılamıyor', 'error'));
        }

        // FiLTRE
        function filtreUygula(tbodyId, ara, kosullar) {
            const tbody = document.getElementById(tbodyId);
            const aranan = ara.trim().toLocaleLowerCase('tr-TR');
            let gorunen = 0;

            tbody.querySelectorAll('tr[data-id]').forEach(tr => {
                let uygun = !aranan || tr.dataset.ara.toLocaleLowerCase('tr-TR').includes(aranan);
                Object.keys(kosullar).forEach(alan => {
                    if (kosullar[alan] !== '' && kosullar[alan] !== null && tr.dataset[alan] !== kosullar[alan]) {
                        uygun = false;
                    }
                });
                tr.classList.toggle('d-none', !uygun);
                if (uygun) gorunen++;
            });

            const bos = tbody.querySelector('.filtre-bos');
            if (bos) bos.classList.toggle('d-none', gorunen > 0 || !tbody.querySelector('tr[data-id]'));
        }

        function menuFiltrele() {
            filtreUygula('menuTableBody', $('#filter_menu_ara').val(), {
                grup: $('#filter_menu_ust').val(),
                header: $('#filter_menu_header').val()
            });
        }

        function sayfaFiltrele() {
            filtreUygula('pageTableBody', $('#filter_sayfa_ara').val(), {
                grup: $('#filter_sayfa_menu').val(),
                dashboard: $('#filter_sayfa_dashboard').val()
            });
        }

        function initFiltreler() {
            $('#menuFilterForm').on('submit', e => { e.preventDefault(); menuFiltrele(); });
            $('#filter_menu_ara').on('input', menuFiltrele);
            $('#filter_menu_ust, #filter_menu_header').on('change', menuFiltrele);
            $('#menuFilterTemizle').on('click', () => {
                $('#filter_menu_ara').val('');
                $('#filter_menu_ust, #filter_menu_header').val('').trigger('change');
                showToast('Filtreler temizlendi', 'info');
            });

            $('#pageFilterForm').on('submit', e => { e.preventDefault(); sayfaFiltrele(); });
            $('#filter_sayfa_ara').on('input', sayfaFiltrele);
            $('#filter_sayfa_menu, #filter_sayfa_dashboard').on('change', sayfaFiltrele);
            $('#pageFilterTemizle').on('click', () => {
                $('#filter_sayfa_ara').val('');
                $('#filter_sayfa_menu, #filter_sayfa_dashboard').val('').trigger('change');
                showToast('Filtreler temizlendi', 'info');
            });
        }

        // Sayfa hazir
        $(document).ready(() => {
            loadStats();
            initSelect2();
            initSortable('menuTableBody', 'menu_reorder');
            initSortable('pageTableBody', 'page_reorder');
            initFiltreler();
            initIkonSelect();
        });
        
        // Menü Fonksiyonları
        function editMenu(menu) {
            document.getElementById('edit_menu_id').value = menu.menuler_id;
            document.getElementById('edit_menu_adi').value = menu.menuler_menu_adi;
            ikonSec('#edit_ikon', menu.menuler_ikon);
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
            ikonSec('#edit_sayfa_ikon', sayfa.sayfalar_ikon);
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
