<?php
/**
 * Admin Panel - site Yönetimi
 * 
 * site ayarlarinin yönetimi
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
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim Yetkiniz bulunmamaktadir.');
}

// Mevcut sayfanin bilgilerini al
$pageinfo = $db->fetchOne("
    SELECT 
        s.sayfalar_sayfa_adi, 
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'site Yönetimi';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// site title'i çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'upload_image') {
            // Yetki kontrolü
            if (!$pagePermissions['can_edit']) {
                echo json_encode(['success' => false, 'message' => 'Dosya yükleme Yetkiniz yok!']);
                exit;
            }
            
            // Dosya yükleme işlemi
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'Dosya yüklenirken bir hata oluştu.']);
                exit;
            }
            
            $file = $_FILES['image'];
            $targetField = $_POST['target_field'] ?? '';
            
            // Dosya bilgileri
            $fileName = $file['name'];
            $fileTmpName = $file['tmp_name'];
            $fileSize = $file['size'];
            $fileError = $file['error'];
            
            // Dosya uzantisini al
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            // izin verilen uzantilar
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'ico', 'webp'];
            
            if (!in_array($fileExt, $allowedExtensions)) {
                echo json_encode(['success' => false, 'message' => 'izin verilmeyen dosya formati.']);
                exit;
            }
            
            // Dosya boyutu kontrolü (5MB)
            if ($fileSize > 5 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Dosya boyutu 5MB\'dan küçük olmalidir.']);
                exit;
            }
            
            // Yeni dosya adi oluştur
            $newfileName = uniqid('', true) . '.' . $fileExt;
            
            // Upload klasörü
            $uploaddir = __DIR__ . '/../assets/uploads/';
            if (!is_dir($uploaddir)) {
                mkdir($uploaddir, 0755, true);
            }
            
            $uploadPath = $uploaddir . $newfileName;
            
            // Dosyayi taşi
            if (move_uploaded_file($fileTmpName, $uploadPath)) {
                $relativePath = '/admin/assets/uploads/' . $newfileName;
                echo json_encode([
                    'success' => true, 
                    'filePath' => $relativePath,
                    'message' => 'Dosya basariyla yüklendi.'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Dosya yüklenirken bir hata oluştu.']);
            }
            exit;
        }
        
        if ($action === 'get_settings') {
            // Mevcut ayarlari getir
            $settings = $db->fetchOne("
                SELECT TOP 1 * 
                FROM dbo.tanim_site_ayarlari 
                ORDER BY site_ayarlari_id DESC
            ");
            
            if (!$settings) {
                // ilk kayit oluşturuluyor
                echo json_encode(['success' => true, 'data' => null]);
            } else {
                echo json_encode(['success' => true, 'data' => $settings]);
            }
            exit;
        }
        
        if ($action === 'save_settings') {
            // Yetki kontrolü
            if (!$pagePermissions['can_edit']) {
                echo json_encode(['success' => false, 'message' => 'Ayarlari kaydetme Yetkiniz yok!']);
                exit;
            }
            
            // Formdan gelen verileri al
            $data = [
                'site_ayarlari_profil_adi' => $_POST['profil_adi'] ?? 'default',
                'site_ayarlari_site_url' => $_POST['site_url'] ?? '',
                'site_ayarlari_site_title' => $_POST['site_title'] ?? '',
                'site_ayarlari_Admin_email' => $_POST['Admin_email'] ?? null,
                'site_ayarlari_firma_adi' => trim($_POST['firma_adi'] ?? '') ?: null,
                'site_ayarlari_iletisim_eposta' => trim($_POST['iletisim_eposta'] ?? '') ?: null,
                'site_ayarlari_callcenter_lead_url' => trim($_POST['callcenter_lead_url'] ?? '') ?: null,
                'site_ayarlari_timezone' => $_POST['timezone'] ?? 'Europe/Istanbul',
                'site_ayarlari_language' => $_POST['language'] ?? 'tr-TR',
                'site_ayarlari_date_format' => $_POST['date_format'] ?? 'dd.MM.yyyy',
                'site_ayarlari_currency' => $_POST['currency'] ?? 'TRY',
                'site_ayarlari_logo_url' => $_POST['logo_url'] ?? null,
                'site_ayarlari_favicon_url' => $_POST['favicon_url'] ?? null,
                'site_ayarlari_default_avatar' => $_POST['default_avatar'] ?? null,
                'site_ayarlari_og_gorsel_url' => $_POST['og_gorsel_url'] ?? null,
                'site_ayarlari_footer_yazi' => $_POST['footer_yazi'] ?? null,
                'site_ayarlari_login_attempt_limit' => (int)($_POST['login_attempt_limit'] ?? 5),
                'site_ayarlari_login_block_duration_min' => (int)($_POST['login_block_duration_min'] ?? 15),
                'site_ayarlari_session_timeout_min' => (int)($_POST['session_timeout_min'] ?? 60),
                'site_ayarlari_password_min_length' => (int)($_POST['password_min_length'] ?? 8),
                'site_ayarlari_require_strong_password' => isset($_POST['require_strong_password']) ? 1 : 0,
                'site_ayarlari_two_factor_enabled' => isset($_POST['two_factor_enabled']) ? 1 : 0,
                'site_ayarlari_registration_enabled' => isset($_POST['registration_enabled']) ? 1 : 0,
                'site_ayarlari_email_verification_required' => isset($_POST['email_verification_required']) ? 1 : 0,
                'site_ayarlari_csrf_enabled' => isset($_POST['csrf_enabled']) ? 1 : 0,
                'site_ayarlari_anasayfa_yonlendirme' => isset($_POST['anasayfa_yonlendirme']) ? 1 : 0,
                'site_ayarlari_site_durum' => isset($_POST['site_durum']) ? 1 : 0,
                'site_ayarlari_maintenance_message' => $_POST['maintenance_message'] ?? null,
                'site_ayarlari_debug_mode' => isset($_POST['debug_mode']) ? 1 : 0,
                'site_ayarlari_log_level' => $_POST['log_level'] ?? 'info',
                'site_ayarlari_log_retention_days' => (int)($_POST['log_retention_days'] ?? 30),
                'site_ayarlari_destek_api_key' => $_POST['destek_api_key'] ?? null,
                'site_ayarlari_destek_api_url' => $_POST['destek_api_url'] ?? null,
                'site_ayarlari_cron_hata_kullanici_id' => (int)($_POST['cron_hata_kullanici_id'] ?? 0) ?: null,
                'site_ayarlari_cron_hata_kategori_id'  => (int)($_POST['cron_hata_kategori_id']  ?? 0) ?: null,
                'site_ayarlari_cron_hata_oncelik_id'   => (int)($_POST['cron_hata_oncelik_id']   ?? 0) ?: null,
                'site_ayarlari_api_personel_id'        => (int)($_POST['api_personel_id']        ?? 0) ?: null,
                'site_ayarlari_eposta_alan_adi'        => strtolower(ltrim(trim($_POST['eposta_alan_adi'] ?? ''), '@')) ?: null,
                'site_ayarlari_guncelleme_tarihi' => date('Y-m-d H:i:s'),
                'site_ayarlari_guncelleyen_kullanici_id' => $user['kullanicilar_id']
            ];
            
            // Mevcut kayit var mi kontrol et
            $existingSettings = $db->fetchOne("SELECT site_ayarlari_id FROM dbo.tanim_site_ayarlari");
            
            if ($existingSettings) {
                // güncelle
                unset($data['site_ayarlari_profil_adi']); // Profil adi güncellenemez
                $db->update('dbo.tanim_site_ayarlari', $data, ['site_ayarlari_id' => $existingSettings['site_ayarlari_id']]);
                echo json_encode(['success' => true, 'message' => 'Ayarlar basariyla güncellendi.']);
            } else {
                // Yeni kayit
                $db->insert('dbo.tanim_site_ayarlari', $data);
                echo json_encode(['success' => true, 'message' => 'Ayarlar basariyla kaydedildi.']);
            }
            exit;
        }
        
        echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Cron hata talebi ayarları: talebi açacak kullanıcı (e-postası olmalı) + Destek API kategori/öncelik listesi
$cronHataKullanicilar = $db->fetchAll("
    SELECT kullanici_id AS id,
           LTRIM(RTRIM(ISNULL(kullanici_ad, '') + ' ' + ISNULL(kullanici_soyad, ''))) AS ad,
           kullanici_email AS eposta
    FROM kullanicilar
    WHERE kullanici_durum = 1 AND ISNULL(kullanici_email, '') <> ''
    ORDER BY kullanici_ad, kullanici_soyad") ?: [];

// Varsayılan API personeli: IRIS rapor/token, ccapi talep sorgusu ve kampanya güncellemesi bu hesapla yapılır
$apiPersoneller = $db->fetchAll("
    SELECT p.DigiturkAltBayiPersonel_Id AS id,
           p.DigiturkAltBayiPersonel_AdSoyad AS ad,
           ISNULL(a.DigiturkAltBayiler_Ad, '-') AS bayi
    FROM DigiturkAltBayiPersonel p
    LEFT JOIN DigiturkAltBayiler a ON p.DigiturkAltBayiPersonel_AltBayiId = a.DigiturkAltBayiler_Id
    WHERE p.Durum = 1
    ORDER BY a.DigiturkAltBayiler_Ad, p.DigiturkAltBayiPersonel_AdSoyad") ?: [];

require_once __DIR__ . '/../includes/DestekHelper.php';
$destekMeta        = DestekHelper::aktifMi() ? (DestekHelper::meta()['data'] ?? []) : [];
$destekKategoriler = $destekMeta['kategoriler'] ?? [];
$destekOncelikler  = $destekMeta['oncelikler']  ?? [];
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    
    <style>
        .form-section-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-top: 1.5rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #dee2e6;
        }
        .form-section-title:First-child {
            margin-top: 0;
        }
        .nav-tabs .nav-link {
            color: #6c757d;
        }
        .nav-tabs .nav-link.active {
            font-weight: 600;
        }
        .form-text {
            font-size: 0.875rem;
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <main class="app-main">
            <!-- Sayfa Başliği -->
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
            
            <!-- Sayfa içeriği -->
            <div class="app-content">
                <div class="container-fluid">
                    
                    <!-- bilgi Mesaji -->
                    <div id="alertMessage" class="alert d-none" role="alert"></div>
                    
                    <!-- site Ayarlari Formu -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="bi bi-gear"></i> site Ayarlari
                            </h3>
                        </div>
                        <div class="card-body">
                            <form id="settingsForm">
                                <!-- Tabs -->
                                <ul class="nav nav-tabs" id="settingsTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="genel-tab" data-bs-toggle="tab" 
                                                data-bs-target="#genel" type="button" role="tab">
                                            <i class="bi bi-info-circle"></i> Genel Ayarlar
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="marka-tab" data-bs-toggle="tab" 
                                                data-bs-target="#marka" type="button" role="tab">
                                            <i class="bi bi-image"></i> Marka & Görseller
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="guvenlik-tab" data-bs-toggle="tab" 
                                                data-bs-target="#guvenlik" type="button" role="tab">
                                            <i class="bi bi-shield-lock"></i> Güvenlik
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="bakim-tab" data-bs-toggle="tab" 
                                                data-bs-target="#bakim" type="button" role="tab">
                                            <i class="bi bi-tools"></i> Bakim & Log
                                        </button>
                                    </li>
                                </ul>
                                
                                <!-- Tab içerikleri -->
                                <div class="tab-content p-3" id="settingsTabContent">
                                    
                                    <!-- Genel Ayarlar -->
                                    <div class="tab-pane fade show active" id="genel" role="tabpanel">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="profil_adi" class="form-label">Profil Adi</label>
                                                    <input type="text" class="form-control" id="profil_adi" name="profil_adi" value="default" required>
                                                    <div class="form-text">site ayarlari profil adi</div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="site_url" class="form-label">site URL <span class="text-danger">*</span></label>
                                                    <input type="url" class="form-control" id="site_url" name="site_url" 
                                                           placeholder="https://alan.adi" required>
                                                    <div class="form-text">Sitenin kök adresi (/admin olmadan). Gizlilik / veri silme sayfaları ve Meta OAuth dönüş adresi buradan üretilir.</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="site_title" class="form-label">site Başliği <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control" id="site_title" name="site_title" 
                                                           placeholder="OrnekYazilim Portal" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="Admin_email" class="form-label">Admin E-posta</label>
                                                    <input type="email" class="form-control" id="Admin_email" name="Admin_email" 
                                                           placeholder="destek@ornekyazilim.com">
                                                    <div class="form-text">Sistem bildirimleri için</div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="firma_adi" class="form-label">Firma Adı</label>
                                                    <input type="text" class="form-control" id="firma_adi" name="firma_adi" maxlength="150"
                                                           placeholder="Firma / bayi adı">
                                                    <div class="form-text">Gizlilik politikası ve veri silme sayfalarında görünür. Boşsa site başlığı kullanılır.</div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="iletisim_eposta" class="form-label">İletişim E-postası</label>
                                                    <input type="email" class="form-control" id="iletisim_eposta" name="iletisim_eposta" maxlength="150"
                                                           placeholder="Ziyaretçiye gösterilecek e-posta adresi">
                                                    <div class="form-text">Ziyaretçiye gösterilen adres ve push bildirim (VAPID) iletişimi. Boşsa admin e-postası kullanılır.</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-3">
                                                <div class="mb-3">
                                                    <label for="timezone" class="form-label">Zaman Dilimi</label>
                                                    <select class="form-select" id="timezone" name="timezone">
                                                        <option value="Europe/Istanbul" selected>Europe/Istanbul</option>
                                                        <option value="UTC">UTC</option>
                                                        <option value="Europe/London">Europe/London</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="mb-3">
                                                    <label for="language" class="form-label">Dil</label>
                                                    <select class="form-select" id="language" name="language">
                                                        <option value="tr-TR" selected>Türkçe</option>
                                                        <option value="en-US">English</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="mb-3">
                                                    <label for="date_format" class="form-label">tarih Formati</label>
                                                    <select class="form-select" id="date_format" name="date_format">
                                                        <option value="dd.MM.yyyy" selected>dd.MM.yyyy</option>
                                                        <option value="yyyy-MM-dd">yyyy-MM-dd</option>
                                                        <option value="MM/dd/yyyy">MM/dd/yyyy</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="mb-3">
                                                    <label for="currency" class="form-label">Para birimi</label>
                                                    <select class="form-select" id="currency" name="currency">
                                                        <option value="TRY" selected>TRY (₺)</option>
                                                        <option value="USD">USD ($)</option>
                                                        <option value="EUR">EUR (€)</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Marka & Görseller -->
                                    <div class="tab-pane fade" id="marka" role="tabpanel">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="logo_url" class="form-label">Logo URL</label>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control" id="logo_url" name="logo_url" 
                                                               placeholder="\Admin\assets\images\logo.png">
                                                        <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('logo_file').click()">
                                                            <i class="bi bi-upload"></i>
                                                        </button>
                                                        <input type="file" id="logo_file" class="d-none" accept="image/*" onchange="handlefileUpload(this, 'logo_url')">
                                                    </div>
                                                    <div class="form-text">site logosu yolu</div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="favicon_url" class="form-label">Favicon URL</label>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control" id="favicon_url" name="favicon_url" 
                                                               placeholder="\Admin\assets\images\favicon.png">
                                                        <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('favicon_file').click()">
                                                            <i class="bi bi-upload"></i>
                                                        </button>
                                                        <input type="file" id="favicon_file" class="d-none" accept="image/*" onchange="handlefileUpload(this, 'favicon_url')">
                                                    </div>
                                                    <div class="form-text">Tarayici ikonu</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="default_avatar" class="form-label">Varsayilan Avatar</label>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control" id="default_avatar" name="default_avatar" 
                                                               placeholder="\Admin\assets\images\default_avatar.png">
                                                        <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('avatar_file').click()">
                                                            <i class="bi bi-upload"></i>
                                                        </button>
                                                        <input type="file" id="avatar_file" class="d-none" accept="image/*" onchange="handlefileUpload(this, 'default_avatar')">
                                                    </div>
                                                    <div class="form-text">kullanici profil resmi yoksa</div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="og_gorsel_url" class="form-label">Open Graph Görsel</label>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control" id="og_gorsel_url" name="og_gorsel_url">
                                                        <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('og_file').click()">
                                                            <i class="bi bi-upload"></i>
                                                        </button>
                                                        <input type="file" id="og_file" class="d-none" accept="image/*" onchange="handlefileUpload(this, 'og_gorsel_url')">
                                                    </div>
                                                    <div class="form-text">Sosyal medya paylaşim görseli</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="footer_yazi" class="form-label">Footer Yazisi</label>
                                            <textarea class="form-control" id="footer_yazi" name="footer_yazi" rows="3" 
                                                      placeholder="© 2025 Örnek Yazılım. Tüm haklari saklidir."></textarea>
                                            <div class="form-text">Footer bölümünde görünecek metin</div>
                                        </div>
                                    </div>
                                    
                                    <!-- Güvenlik -->
                                    <div class="tab-pane fade" id="guvenlik" role="tabpanel">
                                        <div class="form-section-title">giris Güvenliği</div>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="login_attempt_limit" class="form-label">giris Deneme Limiti</label>
                                                    <input type="number" class="form-control" id="login_attempt_limit" 
                                                           name="login_attempt_limit" value="5" min="1" max="20">
                                                    <div class="form-text">Maksimum basarisiz deneme sayisi</div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="login_block_duration_min" class="form-label">Bloke Süresi (dk)</label>
                                                    <input type="number" class="form-control" id="login_block_duration_min" 
                                                           name="login_block_duration_min" value="15" min="1" max="1440">
                                                    <div class="form-text">Limit aşiminda bloke süresi</div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="session_timeout_min" class="form-label">Oturum timeout (dk)</label>
                                                    <input type="number" class="form-control" id="session_timeout_min" 
                                                           name="session_timeout_min" value="60" min="5" max="1440">
                                                    <div class="form-text">Oturum süresi</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-section-title">Parola Politikasi</div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="password_min_length" class="form-label">Minimum Parola Uzunluğu</label>
                                                    <input type="number" class="form-control" id="password_min_length" 
                                                           name="password_min_length" value="8" min="4" max="32">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <div class="form-check form-switch mt-4">
                                                        <input class="form-check-input" type="checkbox" id="require_strong_password" 
                                                               name="require_strong_password" checked>
                                                        <label class="form-check-label" for="require_strong_password">
                                                            Güçlü Parola Zorunluluğu
                                                        </label>
                                                    </div>
                                                    <div class="form-text">Büyük harf, küçük harf, rakam ve özel karakter</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-section-title">Kimlik Dogrulama & Kayit</div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-check form-switch mb-3">
                                                    <input class="form-check-input" type="checkbox" id="two_factor_enabled" name="two_factor_enabled">
                                                    <label class="form-check-label" for="two_factor_enabled">
                                                        iki Faktörlü Kimlik Dogrulama Aktif
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-check form-switch mb-3">
                                                    <input class="form-check-input" type="checkbox" id="csrf_enabled" name="csrf_enabled" checked>
                                                    <label class="form-check-label" for="csrf_enabled">
                                                        CSRF Korumasi Aktif
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-check form-switch mb-3">
                                                    <input class="form-check-input" type="checkbox" id="registration_enabled" name="registration_enabled">
                                                    <label class="form-check-label" for="registration_enabled">
                                                        kullanici kaydi Aktif
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-check form-switch mb-3">
                                                    <input class="form-check-input" type="checkbox" id="email_verification_required" 
                                                           name="email_verification_required" checked>
                                                    <label class="form-check-label" for="email_verification_required">
                                                        E-posta Dogrulama Zorunlu
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Bakim & Log -->
                                    <div class="tab-pane fade" id="bakim" role="tabpanel">
                                        <div class="form-section-title">site Durumu</div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-check form-switch mb-3">
                                                    <input class="form-check-input" type="checkbox" id="anasayfa_yonlendirme" name="anasayfa_yonlendirme">
                                                    <label class="form-check-label" for="anasayfa_yonlendirme">
                                                        <strong>Ana Sayfa Yönlendirme</strong>
                                                    </label>
                                                    <div class="form-text">Açıksa ana sayfa (index.php) otomatik olarak /admin paneline yönlendirir. Kapalıysa "Çok Yakında" sayfası gösterilir.</div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-check form-switch mb-3">
                                                    <input class="form-check-input" type="checkbox" id="site_durum" name="site_durum" checked>
                                                    <label class="form-check-label" for="site_durum">
                                                        <strong>site Aktif</strong>
                                                    </label>
                                                    <div class="form-text">Kapaliysa bakim modu etkin olur</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="maintenance_message" class="form-label">Bakim Mesaji</label>
                                            <textarea class="form-control" id="maintenance_message" name="maintenance_message" rows="2" 
                                                      placeholder="Bakim çalişmasi nedeniyle geçici olarak hizmet dışı"></textarea>
                                            <div class="form-text">Bakim modunda gösterilecek mesaj</div>
                                        </div>
                                        
                                        <div class="form-section-title">Destek API Ayarları</div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="destek_api_key" class="form-label">Destek API Key</label>
                                                    <input type="text" class="form-control" id="destek_api_key" name="destek_api_key" 
                                                           placeholder="API anahtarınızı girin">
                                                    <div class="form-text">Örnek Destek sistemi API anahtarı</div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="destek_api_url" class="form-label">Destek API URL</label>
                                                    <input type="url" class="form-control" id="destek_api_url" name="destek_api_url" 
                                                           placeholder="https://destek.ornekyazilim.com/api/tickets.php">
                                                    <div class="form-text">Örnek Destek sistemi API endpoint adresi</div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="form-section-title">Cron Hata Talebi</div>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="cron_hata_kullanici_id" class="form-label">Talebi Açan Kullanıcı</label>
                                                    <select class="form-select" id="cron_hata_kullanici_id" name="cron_hata_kullanici_id">
                                                        <option value="">— Kapalı (talep açılmaz) —</option>
                                                        <?php foreach ($cronHataKullanicilar as $k): ?>
                                                        <option value="<?= (int)$k['id'] ?>"><?= htmlspecialchars($k['ad'] . ' (' . $k['eposta'] . ')') ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div class="form-text">Hata veren cron görevleri için bu kullanıcı adına destek talebi açılır</div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="cron_hata_kategori_id" class="form-label">Kategori</label>
                                                    <select class="form-select" id="cron_hata_kategori_id" name="cron_hata_kategori_id">
                                                        <option value="">— Seçiniz —</option>
                                                        <?php foreach ($destekKategoriler as $k): ?>
                                                        <option value="<?= (int)$k['id'] ?>"><?= htmlspecialchars($k['ad']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="cron_hata_oncelik_id" class="form-label">Öncelik</label>
                                                    <select class="form-select" id="cron_hata_oncelik_id" name="cron_hata_oncelik_id">
                                                        <option value="">— Seçiniz —</option>
                                                        <?php foreach ($destekOncelikler as $o): ?>
                                                        <option value="<?= (int)$o['id'] ?>"><?= htmlspecialchars($o['ad']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <?php if (!$destekKategoriler): ?>
                                            <div class="col-12"><div class="form-text text-warning mt-0 mb-3"><i class="bi bi-exclamation-triangle"></i> Destek API'den kategori/öncelik listesi alınamadı; önce API Key ve URL'yi kaydedin.</div></div>
                                            <?php endif; ?>
                                            <div class="col-12"><div class="form-text mt-0 mb-3">Aynı görev + zamanlayıcı için 24 saatte en fazla bir talep açılır. Görev bazında kapatmak için Cron Yönetimi'ndeki "Hata Talebi" anahtarını kullanın.</div></div>
                                        </div>

                                        <div class="form-section-title">Bayi / Kurulum Ayarları</div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="api_personel_id" class="form-label">Varsayılan API Personeli</label>
                                                    <select class="form-select" id="api_personel_id" name="api_personel_id">
                                                        <option value="">— Seçiniz —</option>
                                                        <?php foreach ($apiPersoneller as $p): ?>
                                                        <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['ad'] . ' (' . $p['bayi'] . ')') ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div class="form-text">IRIS rapor/token yenileme, IRIS talep sorgusu ve API kampanya güncellemesi bu personelin token'ı ile yapılır</div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="eposta_alan_adi" class="form-label">Personel E-posta Alan Adı</label>
                                                    <input type="text" class="form-control" id="eposta_alan_adi" name="eposta_alan_adi" maxlength="100"
                                                           placeholder="ornek.com.tr">
                                                    <div class="form-text">Personel formunda ad-soyaddan otomatik e-posta üretilir (isim.soyisim@alan). Boşsa üretilmez.</div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="form-section-title">CallCenter Ayarları</div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="callcenter_lead_url" class="form-label">CallCenter Lead Adresi</label>
                                                    <input type="url" class="form-control" id="callcenter_lead_url" name="callcenter_lead_url" maxlength="300"
                                                           placeholder="https://callcenter.alan.adi/api/leadapi/addLead">
                                                    <div class="form-text">API Lead Yönetimi'nde endpoint boş bırakılan kayıtların varsayılan addLead adresi</div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="form-section-title">Log Ayarlari</div>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-check form-switch mb-3">
                                                    <input class="form-check-input" type="checkbox" id="debug_mode" name="debug_mode">
                                                    <label class="form-check-label" for="debug_mode">
                                                        Debug Modu
                                                    </label>
                                                    <div class="form-text text-danger">
                                                        <i class="bi bi-exclamation-triangle"></i> Sadece geliştirme ortaminda
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="log_level" class="form-label">Log Seviyesi</label>
                                                    <select class="form-select" id="log_level" name="log_level">
                                                        <option value="TRACE">TRACE</option>
                                                        <option value="DEBUG">DEBUG</option>
                                                        <option value="info" selected>info</option>
                                                        <option value="WARN">WARN</option>
                                                        <option value="ERROR">ERROR</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="log_retention_days" class="form-label">Log Saklama (gün)</label>
                                                    <input type="number" class="form-control" id="log_retention_days" 
                                                           name="log_retention_days" value="30" min="1" max="365">
                                                    <div class="form-text">Loglarin saklanma süresi</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                </div>
                            </form>
                        </div>
                        <div class="card-footer">
                            <?php if ($pagePermissions['can_edit']): ?>
                            <button type="button" class="btn btn-primary" id="saveSettingsBtn">
                                <i class="bi bi-save"></i> Ayarlari Kaydet
                            </button>
                            <button type="button" class="btn btn-secondary" id="resetFormBtn">
                                <i class="bi bi-arrow-clockwise"></i> Sifirla
                            </button>
                            <?php else: ?>
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle"></i> Bu sayfayi görüntüleme Yetkiniz var, ancak düzenleme Yetkiniz bulunmamaktadir.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/Admin/assets/js/Adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Formdaki tüm dropdown'lar aranabilir (custom.js bu sayfada yüklü değil, init burada)
        $(function () { $('#settingsForm .form-select').select2({ theme: 'bootstrap-5', width: '100%' }); });
    </script>

    <script>
        // Sayfa yetkileri
        const pagePermissions = {
            can_edit: <?= $pagePermissions['can_edit'] ? 'true' : 'false' ?>
        };
        
        // Dosya yükleme işlemi
        function handlefileUpload(input, targetinputid) {
            const file = input.files[0];
            if (!file) return;
            
            // Dosya boyut kontrolü (max 5MB)
            if (file.size > 5 * 1024 * 1024) {
                showAlert('danger', 'Dosya boyutu 5MB\'dan küçük olmalidir.');
                input.value = '';
                return;
            }
            
            // Dosya tipi kontrolü
            if (!file.type.startsWith('image/')) {
                showAlert('danger', 'Sadece resim dosyalari yüklenebilir.');
                input.value = '';
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'upload_image');
            formData.append('image', file);
            formData.append('target_field', targetinputid);
            
            // Upload butonunu devre dışı birak ve loading göster
            const button = input.previousElementSibling;
            const originalContent = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById(targetinputid).value = data.filePath;
                    showAlert('success', 'Dosya basariyla yüklendi.');
                } else {
                    showAlert('danger', data.message || 'Dosya yüklenirken bir hata oluştu.');
                }
            })
            .catch(error => {
                showAlert('danger', 'Dosya yüklenirken bir hata oluştu: ' + error.message);
            })
            .finally(() => {
                button.disabled = false;
                button.innerHTML = originalContent;
                input.value = ''; // input'u temizle
            });
        }
        
        // Sayfa yüklendiğinde mevcut ayarlari getir
        document.addEventListener('DOMContentLoaded', function() {
            loadSettings();
            
            // Yetki yoksa tüm input'lari readonly/disabled yap
            if (!pagePermissions.can_edit) {
                const form = document.getElementById('settingsForm');
                const inputs = form.querySelectorAll('input, select, textarea');
                inputs.forEach(input => {
                    if (input.type === 'checkbox' || input.type === 'radio') {
                        input.disabled = true;
                    } else {
                        input.readOnly = true;
                    }
                });
                
                // Dosya yükleme butonlarini gizle
                document.querySelectorAll('.btn-outline-secondary').forEach(btn => {
                    if (btn.textContent.includes('Seç')) {
                        btn.style.display = 'none';
                    }
                });
            }
        });
        
        // Ayarlari yükle
        function loadSettings() {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_settings'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    populateForm(data.data);
                }
            })
            .catch(error => {
                console.error('Ayarlar yüklenirken hata:', error);
            });
        }
        
        // Formu doldur
        function populateForm(settings) {
            // Text ve select alanlari
            const fields = [
                'profil_adi', 'site_url', 'site_title', 'Admin_email', 'timezone', 
                'language', 'date_format', 'currency', 'logo_url', 'favicon_url', 
                'default_avatar', 'og_gorsel_url', 'footer_yazi', 'login_attempt_limit', 
                'login_block_duration_min', 'session_timeout_min', 'password_min_length', 
                'maintenance_message', 'log_level', 'log_retention_days',
                'destek_api_key', 'destek_api_url',
                'firma_adi', 'iletisim_eposta', 'callcenter_lead_url',
                'cron_hata_kullanici_id', 'cron_hata_kategori_id', 'cron_hata_oncelik_id',
                'api_personel_id', 'eposta_alan_adi'
            ];
            
            fields.forEach(field => {
                const element = document.getElementById(field);
                const value = settings['site_ayarlari_' + field];
                if (element && value !== null && value !== undefined) {
                    element.value = value;
                }
            });
            
            // Checkbox alanlari
            const checkboxes = [
                'require_strong_password', 'two_factor_enabled', 'registration_enabled',
                'email_verification_required', 'csrf_enabled', 'anasayfa_yonlendirme', 'site_durum', 'debug_mode'
            ];
            
            checkboxes.forEach(field => {
                const element = document.getElementById(field);
                const value = settings['site_ayarlari_' + field];
                if (element && value !== null && value !== undefined) {
                    element.checked = value == 1;
                }
            });

            // Select2 görünümü .value ile güncellenmez
            $('#settingsForm .form-select').trigger('change.select2');
        }
        
        // Kaydet butonu
        document.getElementById('saveSettingsBtn').addEventListener('click', function() {
            const form = document.getElementById('settingsForm');
            const formData = new FormData(form);
            formData.append('action', 'save_settings');
            
            // Checkbox'lari da ekle (checked olmayanlar için)
            const checkboxes = form.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                if (!checkbox.checked) {
                    formData.delete(checkbox.name);
                }
            });
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showAlert(data.success ? 'success' : 'danger', data.message);
                if (data.success) {
                    loadSettings(); // Ayarlari yeniden yükle
                }
            })
            .catch(error => {
                showAlert('danger', 'bir hata oluştu: ' + error.message);
            });
        });
        
        // Sifirla butonu
        document.getElementById('resetFormBtn').addEventListener('click', function() {
            if (confirm('Formu sifirlamak istediğinize emin misiniz?')) {
                loadSettings();
            }
        });
        
        // Alert göster
        function showAlert(type, message) {
            const alertDiv = document.getElementById('alertMessage');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            alertDiv.classList.remove('d-none');
            
            // 5 saniye sonra otomatik kapat
            setTimeout(() => {
                alertDiv.classList.add('d-none');
            }, 5000);
            
            // Sayfayi yukari kaydir
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    </script>
</body>
</html>
