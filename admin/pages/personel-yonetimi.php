<?php
/**
 * Admin Panel - Kullanıcı Yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/GorunurlukYetki.php';

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
    // AJAX isteği ise JSON döndür
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $pagePermissions['error'] ?? 'Bu sayfaya erişim Yetkiniz bulunmamaktadır.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim Yetkiniz bulunmamaktadır.');
}

// =====================================================================
// GÖRÜNÜRLÜK YETKİ KISITLARI (ortak helper: includes/GorunurlukYetki.php)
// birim_gor → yalnız kendi birimi + alt birimleri | kendi_kullanicini_gor → yalnız kendi eklediği
// =====================================================================
$gk = gorunurlukKisitiHesapla($db, $pagePermissions, (int)$user['kullanici_id']);
$kullaniciId    = $gk['kullaniciId'];
$birimKisitli   = $gk['birimKisitli'];
$kendiKisitli   = $gk['kendiKisitli'];
$izinliBirimler = $gk['izinliBirimler'];

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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Kullanıcı Yönetimi';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

// site title'i çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // BOM ve önceki çıktıları temizle
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $action = $_POST['action'] ?? '';
        
        // Kullanıcı İŞLEMLERİ
        if ($action === 'kullanici_listele') {
            // Filtreleri al
            $departmanid = $_POST['departman_id'] ?? '';
            $durum = $_POST['durum'] ?? '';
            $search = $_POST['search'] ?? '';
            
            // WHERE koşulları
            $whereConditions = ["1=1"];
            $params = [];
            
            if ($departmanid) {
                $whereConditions[] = "k.kullanici_departman_id = ?";
                $params[] = $departmanid;
            }
            
            if ($durum !== '') {
                if ($durum === 'NULL') {
                    $whereConditions[] = "k.kullanici_durum IS NULL";
                } else {
                    $whereConditions[] = "k.kullanici_durum = ?";
                    $params[] = $durum;
                }
            }
            
            if ($search) {
                $whereConditions[] = "(k.kullanici_ad LIKE ? OR k.kullanici_soyad LIKE ? OR k.kullanici_email LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }

            // T.C. Kimlik No filtresi
            $tcKimlikNo = $_POST['tc_kimlik_no'] ?? '';
            if ($tcKimlikNo) {
                $whereConditions[] = "k.kullanici_tc_kimlik_no LIKE ?";
                $params[] = "%$tcKimlikNo%";
            }

            // Birim filtresi
            $birimId = $_POST['birim_id'] ?? '';
            if ($birimId !== '') {
                $whereConditions[] = "k.kullanici_birim_id = ?";
                $params[] = (int)$birimId;
            }

            // Görünürlük yetki kısıtı (birim / kendi kaydı)
            uygulaGorunurlukKisiti($whereConditions, $params, $birimKisitli, $izinliBirimler, $kendiKisitli, $kullaniciId);

            $whereClause = implode(" AND ", $whereConditions);

            $kullanicilar = $db->fetchAll("
                SELECT
                    k.kullanici_id,
                    k.kullanici_email,
                    k.kullanici_ad,
                    k.kullanici_soyad,
                    k.kullanici_telefon,
                    k.kullanici_durum,
                    k.kullanici_departman_id,
                    k.kullanici_birim_id,
                    k.kullanici_tc_kimlik_no,
                    CONVERT(VARCHAR(10), k.kullanici_dogum_tarihi, 120) as kullanici_dogum_tarihi,
                    d.departman_adi,
                    b.KullaniciBirim_Adi as birim_adi,
                    CONVERT(VARCHAR(19), k.kullanici_olusturma_tarihi, 120) as kullanici_olusturma_tarihi,
                    CONVERT(VARCHAR(19), k.kullanici_son_giris_tarihi, 120) as kullanici_son_giris_tarihi
                FROM kullanicilar k
                LEFT JOIN kullanici_Departmanlar d ON k.kullanici_departman_id = d.departman_id
                LEFT JOIN KullaniciBirim b ON k.kullanici_birim_id = b.KullaniciBirim_id
                WHERE $whereClause
                ORDER BY k.kullanici_ad, k.kullanici_soyad
            ", $params);
            
            echo json_encode(['success' => true, 'data' => $kullanicilar]);
            exit;
        }
        
        if ($action === 'stats') {
            // Görünürlük yetki kısıtını istatistiklere de uygula
            $statWhere = ["1=1"];
            $statParams = [];
            uygulaGorunurlukKisiti($statWhere, $statParams, $birimKisitli, $izinliBirimler, $kendiKisitli, $kullaniciId);
            $statWhereClause = implode(" AND ", $statWhere);

            // istatistikler
            $stats = [
                'toplam' => $db->fetchOne("SELECT count(*) as sayi FROM kullanicilar k WHERE $statWhereClause", $statParams)['sayi'] ?? 0,
                'aktif'  => $db->fetchOne("SELECT count(*) as sayi FROM kullanicilar k WHERE $statWhereClause AND k.kullanici_durum = 1", $statParams)['sayi'] ?? 0,
                'pasif'  => $db->fetchOne("SELECT count(*) as sayi FROM kullanicilar k WHERE $statWhereClause AND k.kullanici_durum = 0", $statParams)['sayi'] ?? 0
            ];

            // Son kayit
            $sonKayit = $db->fetchOne("
                SELECT TOP 1
                    k.kullanici_ad,
                    k.kullanici_soyad,
                    CONVERT(VARCHAR(10), k.kullanici_olusturma_tarihi, 104) as tarih
                FROM kullanicilar k
                WHERE $statWhereClause
                ORDER BY k.kullanici_olusturma_tarihi DESC
            ", $statParams);
            
            if ($sonKayit) {
                $adSoyad = trim(($sonKayit['kullanici_ad'] ?? '') . ' ' . ($sonKayit['kullanici_soyad'] ?? ''));
                $stats['son_kayit'] = ($adSoyad ?: 'Anonim') . ' (' . $sonKayit['tarih'] . ')';
            } else {
                $stats['son_kayit'] = '-';
            }
            
            echo json_encode(['success' => true, 'data' => $stats]);
            exit;
        }
        
        if ($action === 'get_departmanlar') {
            $departmanlar = $db->fetchAll("SELECT departman_id, departman_adi FROM kullanici_Departmanlar WHERE departman_durum = 1 ORDER BY departman_adi");
            echo json_encode(['success' => true, 'data' => $departmanlar]);
            exit;
        }

        if ($action === 'get_birimler') {
            $birimler = $db->fetchAll("SELECT KullaniciBirim_id, KullaniciBirim_Adi FROM KullaniciBirim WHERE Durum = 1 ORDER BY KullaniciBirim_Adi");
            echo json_encode(['success' => true, 'data' => $birimler]);
            exit;
        }
        
        if ($action === 'kullanici_ekle') {
            // Yetki kontrolü
            if (!$pagePermissions['can_add']) {
                throw new Exception('Ekleme Yetkiniz bulunmamaktadır.');
            }
            
            $email = trim($_POST['email'] ?? '');
            $sifre = trim($_POST['sifre'] ?? '');
            $ad = trim($_POST['ad'] ?? '');
            $soyad = trim($_POST['soyad'] ?? '');
            $telefon = trim($_POST['telefon'] ?? '');
            $departman_id = !empty($_POST['departman_id']) ? intval($_POST['departman_id']) : null;
            $birim_id = !empty($_POST['birim_id']) ? intval($_POST['birim_id']) : null;
            $tc_kimlik = trim($_POST['tc_kimlik_no'] ?? '');
            $dogum_tarihi = !empty($_POST['dogum_tarihi']) ? $_POST['dogum_tarihi'] : null;

            if (empty($email) || empty($sifre)) {
                throw new Exception('E-posta ve şifre boş olamaz');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Geçersiz email adresi');
            }

            // email kontrolü
            $emailKontrol = $db->fetchOne("SELECT kullanici_id FROM kullanicilar WHERE kullanici_email = ?", [$email]);
            if ($emailKontrol) {
                throw new Exception('Bu email adresi zaten kayıtlı');
            }

            $id = $db->insert('kullanicilar', [
                'kullanici_email' => $email,
                'kullanici_sifre_hash' => password_hash($sifre, PASSWORD_DEFAULT),
                'kullanici_ad' => $ad,
                'kullanici_soyad' => $soyad,
                'kullanici_telefon' => $telefon,
                'kullanici_departman_id' => $departman_id,
                'kullanici_birim_id' => $birim_id,
                'kullanici_tc_kimlik_no' => $tc_kimlik ?: null,
                'kullanici_dogum_tarihi' => $dogum_tarihi,
                'kullanici_olusturan_id' => $_SESSION['user_id'] ?? null
            ]);
            
            echo json_encode(['success' => true, 'message' => 'kullanici eklendi', 'id' => $id]);
            exit;
        }
        
        if ($action === 'kullanici_guncelle') {
            // Yetki kontrolü
            if (!$pagePermissions['can_edit']) {
                throw new Exception('Düzenleme Yetkiniz bulunmamaktadır.');
            }
            
            $id = intval($_POST['id'] ?? 0);
            $email = trim($_POST['email'] ?? '');
            $ad = trim($_POST['ad'] ?? '');
            $soyad = trim($_POST['soyad'] ?? '');
            $telefon = trim($_POST['telefon'] ?? '');
            $durum = intval($_POST['durum'] ?? 1);
            $sifre = trim($_POST['sifre'] ?? '');
            $departman_id = !empty($_POST['departman_id']) ? intval($_POST['departman_id']) : null;
            $birim_id = !empty($_POST['birim_id']) ? intval($_POST['birim_id']) : null;
            $tc_kimlik = trim($_POST['tc_kimlik_no'] ?? '');
            $dogum_tarihi = !empty($_POST['dogum_tarihi']) ? $_POST['dogum_tarihi'] : null;

            if (empty($email) || $id <= 0) {
                throw new Exception('Geçersiz veri');
            }

            // Görünürlük kapsamı dışındaki kaydı düzenlemeyi engelle
            if (($birimKisitli || $kendiKisitli) && !kayitKapsamdaMi($db, $id, $birimKisitli, $izinliBirimler, $kendiKisitli, $kullaniciId)) {
                throw new Exception('Bu kaydı düzenleme yetkiniz yok.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Geçersiz email adresi');
            }

            // email kontrolü (kendisi hariç)
            $emailKontrol = $db->fetchOne("SELECT kullanici_id FROM kullanicilar WHERE kullanici_email = ? AND kullanici_id != ?", [$email, $id]);
            if ($emailKontrol) {
                throw new Exception('Bu email adresi başka bir kullanıcıda kayıtlı');
            }

            $updateData = [
                'kullanici_email' => $email,
                'kullanici_ad' => $ad,
                'kullanici_soyad' => $soyad,
                'kullanici_telefon' => $telefon,
                'kullanici_durum' => $durum,
                'kullanici_departman_id' => $departman_id,
                'kullanici_birim_id' => $birim_id,
                'kullanici_tc_kimlik_no' => $tc_kimlik ?: null,
                'kullanici_dogum_tarihi' => $dogum_tarihi,
                'kullanici_guncelleme_tarihi' => date('Y-m-d H:i:s'),
                'kullanici_guncelleyen_id' => $_SESSION['user_id'] ?? null
            ];
            
            // Şifre değiştirilecekse
            if (!empty($sifre)) {
                $updateData['kullanici_sifre_hash'] = password_hash($sifre, PASSWORD_DEFAULT);
            }
            
            $db->update('kullanicilar', $updateData, ['kullanici_id' => $id]);
            
            echo json_encode(['success' => true, 'message' => 'Kullanıcı güncellendi']);
            exit;
        }
        
        if ($action === 'durum_degistir') {
            // Yetki kontrolu
            if (!$pagePermissions['can_edit']) {
                throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');
            }

            $id = intval($_POST['id'] ?? 0);
            $durum = intval($_POST['durum'] ?? 0) === 1 ? 1 : 0;

            if ($id <= 0) {
                throw new Exception('Geçersiz kullanıcı');
            }

            $db->update('kullanicilar', [
                'kullanici_durum' => $durum,
                'kullanici_guncelleme_tarihi' => date('Y-m-d H:i:s'),
                'kullanici_guncelleyen_id' => $_SESSION['user_id'] ?? null,
            ], ['kullanici_id' => $id]);

            echo json_encode([
                'success' => true,
                'durum' => $durum,
                'message' => $durum === 1 ? 'Personel aktif edildi' : 'Personel pasife alındı'
            ]);
            exit;
        }

        if ($action === 'kullanici_sil') {
            // Yetki kontrolü
            if (!$pagePermissions['can_delete']) {
                throw new Exception('Silme Yetkiniz bulunmamaktadır.');
            }
            
            $id = intval($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception('Geçersiz id');
            }
            
            // Kendi hesabini silmeyi engelle
            if ($id == $user['kullanici_id']) {
                throw new Exception('Kendi hesabinizi silemezsiniz');
            }

            // Görünürlük kapsamı dışındaki kaydı silmeyi engelle
            if (($birimKisitli || $kendiKisitli) && !kayitKapsamdaMi($db, $id, $birimKisitli, $izinliBirimler, $kendiKisitli, $kullaniciId)) {
                throw new Exception('Bu kaydı silme yetkiniz yok.');
            }

            // kullaniciyi sil
            $db->delete('kullanicilar', ['kullanici_id' => $id]);
            echo json_encode(['success' => true, 'message' => 'kullanici kalici olarak silindi']);
            exit;
        }
        
        if ($action === 'excel_indir') {
            // Yetki kontrolü
            if (!$pagePermissions['can_view']) {
                throw new Exception('Görüntüleme Yetkiniz bulunmamaktadır.');
            }
            
            // Filtreleri al
            $departmanid = $_POST['departman_id'] ?? '';
            $durum = $_POST['durum'] ?? '';
            $search = $_POST['search'] ?? '';
            $tcKimlikNo = $_POST['tc_kimlik_no'] ?? '';
            
            // WHERE koşulları
            $whereConditions = ["1=1"];
            $params = [];
            
            if ($departmanid) {
                $whereConditions[] = "k.kullanici_departman_id = ?";
                $params[] = $departmanid;
            }
            
            if ($durum !== '') {
                if ($durum === 'NULL') {
                    $whereConditions[] = "k.kullanici_durum IS NULL";
                } else {
                    $whereConditions[] = "k.kullanici_durum = ?";
                    $params[] = $durum;
                }
            }
            
            if ($search) {
                $whereConditions[] = "(k.kullanici_ad LIKE ? OR k.kullanici_soyad LIKE ? OR k.kullanici_email LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            // T.C. Kimlik No filtresi
            if ($tcKimlikNo) {
                $whereConditions[] = "k.kullanici_tc_kimlik_no LIKE ?";
                $params[] = "%$tcKimlikNo%";
            }

            // Birim filtresi
            $birimIdExcel = $_POST['birim_id'] ?? '';
            if ($birimIdExcel !== '') {
                $whereConditions[] = "k.kullanici_birim_id = ?";
                $params[] = (int)$birimIdExcel;
            }

            // Görünürlük yetki kısıtı (birim / kendi kaydı)
            uygulaGorunurlukKisiti($whereConditions, $params, $birimKisitli, $izinliBirimler, $kendiKisitli, $kullaniciId);

            $whereClause = implode(" AND ", $whereConditions);

            $kullanicilar = $db->fetchAll("
                SELECT
                    k.kullanici_id,
                    k.kullanici_email,
                    k.kullanici_ad,
                    k.kullanici_soyad,
                    k.kullanici_telefon,
                    k.kullanici_tc_kimlik_no,
                    CONVERT(VARCHAR(10), k.kullanici_dogum_tarihi, 104) as kullanici_dogum_tarihi,
                    CASE WHEN k.kullanici_durum = 1 THEN 'Aktif' WHEN k.kullanici_durum = 0 THEN 'Pasif' ELSE 'Belirsiz' END as durum_text,
                    d.departman_adi,
                    b.KullaniciBirim_Adi as birim_adi,
                    CONVERT(VARCHAR(19), k.kullanici_olusturma_tarihi, 120) as kullanici_olusturma_tarihi
                FROM kullanicilar k
                LEFT JOIN kullanici_Departmanlar d ON k.kullanici_departman_id = d.departman_id
                LEFT JOIN KullaniciBirim b ON k.kullanici_birim_id = b.KullaniciBirim_id
                WHERE $whereClause
                ORDER BY k.kullanici_ad, k.kullanici_soyad
            ", $params);
            
            // Excel dosyası oluştur
            $filename = 'personel_listesi_' . date('Y-m-d_H-i-s') . '.xls';
            
            header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            // UTF-8 BOM ekle (Turkçe karakterler için)
            echo "\xEF\xBB\xBF";
            
            // Excel tablosu
            echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
            echo '<head>';
            echo '<meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">';
            echo '<xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
            echo '<x:Name>Personel Listesi</x:Name>';
            echo '<x:WorksheetOptions><x:Print><x:ValidPrinterinfo/></x:Print></x:WorksheetOptions>';
            echo '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml>';
            echo '</head>';
            echo '<body>';
            echo '<table border="1">';
            
            // Başliklar
            echo '<thead>';
            echo '<tr style="background-color: #0d6efd; color: white; font-weight: bold;">';
            echo '<th>id</th>';
            echo '<th>Ad</th>';
            echo '<th>Soyad</th>';
            echo '<th>E-posta</th>';
            echo '<th>Telefon</th>';
            echo '<th>TC Kimlik No</th>';
            echo '<th>Departman</th>';
            echo '<th>Birim</th>';
            echo '<th>Doğum tarihi</th>';
            echo '<th>Kayıt tarihi</th>';
            echo '<th>Durum</th>';
            echo '</tr>';
            echo '</thead>';
            
            // Veriler
            echo '<tbody>';
            foreach ($kullanicilar as $k) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($k['kullanici_id'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_ad'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_soyad'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_email'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_telefon'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_tc_kimlik_no'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['departman_adi'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['birim_adi'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_dogum_tarihi'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['kullanici_olusturma_tarihi'] ?? '') . '</td>';
                echo '<td>' . htmlspecialchars($k['durum_text'] ?? '') . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</body></html>';
            exit;
        }
        

        if ($action === 'kullanici_olarak_giris') {
            if (!$pagePermissions['is_admin']) {
                throw new Exception('Yalnızca yöneticiler bu işlemi yapabilir.');
            }

            $hedefId = intval($_POST['kullanici_id'] ?? 0);

            if ($hedefId <= 0) {
                throw new Exception('Geçersiz kullanıcı.');
            }
            if ($hedefId == $user['kullanici_id']) {
                throw new Exception('Kendi hesabınıza zaten giriş yaptınız.');
            }
            if (Auth::isImpersonating()) {
                throw new Exception('Zaten başka bir kullanıcı olarak giriş yapılmış durumdasınız.');
            }

            $auth = new Auth();
            if ($auth->impersonate($hedefId)) {
                echo json_encode(['success' => true, 'redirect' => '/admin/anasayfa']);
            } else {
                throw new Exception('Kullanıcı bulunamadı veya pasif durumda.');
            }
            exit;
        }

        throw new Exception('Geçersiz işlem');
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    
    <style>
        .badge-status {
            font-size: 0.85rem;
            padding: 0.35em 0.65em;
        }
        .table-actions {
            white-space: nowrap;
        }
        .btn-group-xs > .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        
        /* Sidebar geçiş animasyonu */
        .app-main {
            transition: margin-left 0.3s ease-in-out;
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
                    
                    <!-- info Boxes -->
                    <div class="row mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-primary shadow-sm">
                                    <i class="bi bi-people"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Toplam kullanici</span>
                                    <span class="info-box-number" id="stat-toplam">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-success shadow-sm">
                                    <i class="bi bi-check-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Aktif</span>
                                    <span class="info-box-number" id="stat-aktif">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-danger shadow-sm">
                                    <i class="bi bi-x-circle"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Pasif</span>
                                    <span class="info-box-number" id="stat-pasif">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12 col-sm-6 col-md-3">
                            <div class="info-box">
                                <span class="info-box-icon text-bg-info shadow-sm">
                                    <i class="bi bi-clock-history"></i>
                                </span>
                                <div class="info-box-content">
                                    <span class="info-box-text">Son Kayit</span>
                                    <span class="info-box-number" id="stat-son" style="font-size: 0.9rem;">-</span>
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
                                    <!-- Departman -->
                                    <div class="col-md-3">
                                        <label class="form-label">Departman</label>
                                        <select class="form-select" name="departman_id" id="filter_departman_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>

                                    <!-- Birim -->
                                    <div class="col-md-3">
                                        <label class="form-label">Birim</label>
                                        <select class="form-select" name="birim_id" id="filter_birim_id">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>

                                    <!-- Durum -->
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select" name="durum" id="filter_durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                            <option value="NULL">Başvuru Bekliyor</option>
                                        </select>
                                    </div>

                                    <!-- Arama -->
                                    <div class="col-md-2">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" name="search" id="filter_search" placeholder="Ad, soyad, email...">
                                    </div>

                                    <!-- T.C. Kimlik No -->
                                    <div class="col-md-2">
                                        <label class="form-label">T.C. Kimlik No</label>
                                        <input type="text" class="form-control" name="tc_kimlik_no" id="filter_tc_kimlik_no" placeholder="TC No..." maxlength="11">
                                    </div>
                                    
                                    <!-- Butonlar -->
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
                    
                    <!-- kullanicilar -->
                    <div class="card card-primary card-outline">
                        <div class="card-header">
                            <h3 class="card-title">Kullanıcılar</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-success btn-sm me-1" id="excelindir">
                                    <i class="bi bi-file-earmark-excel"></i> Excel indir
                                </button>

                                <?php if ($pagePermissions['can_add']): ?>
                                <a href="/Admin/personel-form" class="btn btn-primary btn-sm">
                                    <i class="bi bi-plus-circle"></i> Yeni Kullanıcı
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <table id="kullaniciTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>id</th>
                                        <th>Ad Soyad</th>
                                        <th>E-posta</th>
                                        <th>Telefon</th>
                                        <th>T.C. Kimlik No</th>
                                        <th>Departman</th>
                                        <th>Birim</th>
                                        <th>Doğum tarihi</th>
                                        <th>Durum</th>
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
    


    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/Admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="/Admin/assets/js/custom.js"></script>
    
    <script>
        let table;
        let currentfilters = {};
        const filter_STORAGE_KEY = 'personel_yonetimi_filters';
        
        // Sayfa yetkileri
        const permissions = <?= json_encode($pagePermissions) ?>;
        const CURRENT_USER_ID = <?= (int)($user['kullanici_id'] ?? 0) ?>;
        console.log('Sayfa Yetkileri:', permissions);
        
        // Turkçe karakter normalize Fonksiyonu
        function turkishToLower(str) {
            if (!str) return '';
            return str.tostring()
                .replace(/i/g, 'i')
                .replace(/i/g, 'i')
                .replace(/Ş/g, 'ş')
                .replace(/Ğ/g, 'ğ')
                .replace(/ç/g, 'ç')
                .replace(/ç/g, 'ç')
                .replace(/ç/g, 'ç')
                .toLowerCase();
        }
        
        // DataTables için Turkçe karakter destekli arama
        $.fn.dataTable.ext.search.push(function(settings, data, dataindex) {
            // Sadece bu tablo için çaliş
            if (settings.nTable.id !== 'kullaniciTable') return true;
            
            const searchTerm = turkishToLower($('#kullaniciTable_filter input').val());
            if (!searchTerm) return true;
            
            // Tüm kolonlarda ara
            for (let i = 0; i < data.length; i++) {
                if (turkishToLower(data[i]).includes(searchTerm)) {
                    return true;
                }
            }
            return false;
        });
        
        // Filtreleri localStorage'a kaydet
        function savefiltersToStorage() {
            localStorage.setItem(filter_STORAGE_KEY, JSON.stringify(currentfilters));
        }
        
        // Filtreleri localStorage'dan yükle
        function loadfiltersFromStorage() {
            const saved = localStorage.getItem(filter_STORAGE_KEY);
            if (saved) {
                try {
                    return JSON.parse(saved);
                } catch (e) {
                    return {};
                }
            }
            return {};
        }
        
        // Filtreleri form alanlarına uygula
        function applyfiltersToForm(filters) {
            if (filters.departman_id) {
                $('#filter_departman_id').val(filters.departman_id);
            }
            if (filters.birim_id) {
                $('#filter_birim_id').val(filters.birim_id);
            }
            if (filters.durum) {
                $('#filter_durum').val(filters.durum);
            }
            if (filters.search) {
                $('#filter_search').val(filters.search);
            }
            if (filters.tc_kimlik_no) {
                $('#filter_tc_kimlik_no').val(filters.tc_kimlik_no);
            }

            // Select2 güncelle
            setTimeout(() => {
                $('#filter_departman_id').trigger('change.select2');
                $('#filter_birim_id').trigger('change.select2');
                $('#filter_durum').trigger('change.select2');
            }, 100);
        }

        function loadStats() {
            $.post('/Admin/pages/personel-yonetimi.php', { action: 'stats' }, response => {
                if (response.success) {
                    $('#stat-toplam').text(response.data.toplam);
                    $('#stat-aktif').text(response.data.aktif);
                    $('#stat-pasif').text(response.data.pasif);
                    $('#stat-son').text(response.data.son_kayit);
                }
            });
        }
        
        function initDataTable() {
            table = $('#kullaniciTable').DataTable({
                processing: true,
                scrollX: true,
                autoWidth: false,
                language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
                ajax: {
                    url: '/Admin/pages/personel-yonetimi.php',
                    type: 'POST',
                    data: function(d) {
                        return { action: 'kullanici_listele', ...currentfilters };
                    },
                    dataSrc: function(json) {
                        if (json.success) {
                            return json.data;
                        } else {
                            console.error('AJAX Hata:', json.message);
                            return [];
                        }
                    },
                    error: function(xhr, error, thrown) {
                        console.error('AJAX İstek Hatası:', xhr.responseText);
                    }
                },
                columns: [
                    { data: 'kullanici_id' },
                    { 
                        data: null,
                        render: data => {
                            const adSoyad = [data.kullanici_ad, data.kullanici_soyad].filter(x => x).join(' ') || '-';
                            return `<strong>${adSoyad}</strong>`;
                        }
                    },
                    { data: 'kullanici_email', defaultContent: '-' },
                    { data: 'kullanici_telefon', defaultContent: '-' },
                    { 
                        data: 'kullanici_tc_kimlik_no', 
                        defaultContent: '<span class="text-muted">-</span>',
                        render: data => data ? data : '<span class="text-muted">-</span>'
                    },
                    { data: 'departman_adi', defaultContent: '<span class="text-muted">-</span>' },
                    { data: 'birim_adi', defaultContent: '<span class="text-muted">-</span>' },
                    {
                        data: 'kullanici_dogum_tarihi', 
                        defaultContent: '<span class="text-muted">-</span>',
                        render: data => {
                            if (!data) return '<span class="text-muted">-</span>';
                            try {
                                const tarih = new Date(data);
                                return tarih.toLocaleDateString('tr-TR');
                            } catch(e) {
                                return '<span class="text-muted">-</span>';
                            }
                        }
                    },
                    { 
                        data: 'kullanici_durum',
                        className: 'text-center',
                        render: (data, type, row) => {
                            if (type !== 'display') return data;

                            // Duzenleme yetkisi yoksa salt okunur rozet
                            if (!permissions.can_edit) {
                                if (data == 1) return '<span class="badge bg-success">Aktif</span>';
                                if (data == 0) return '<span class="badge bg-danger">Pasif</span>';
                                return '<span class="badge bg-warning">Başvuru Bekliyor</span>';
                            }

                            const baslik = data == 1 ? 'Aktif' : (data == 0 ? 'Pasif' : 'Başvuru Bekliyor');
                            return `<div class="form-check form-switch d-flex justify-content-center">
                                <input class="form-check-input durum-switch" type="checkbox" role="switch"
                                       id="durum_${row.kullanici_id}" value="1"
                                       data-id="${row.kullanici_id}" title="${baslik}"
                                       ${data == 1 ? 'checked' : ''}>
                                <label class="form-check-label" for="durum_${row.kullanici_id}"></label>
                            </div>`;
                        }
                    },
                    { 
                        data: null,
                        orderable: false,
                        render: data => {
                            const adSoyad = [data.kullanici_ad, data.kullanici_soyad].filter(x => x).join(' ') || 'Kullanıcı';
                            let buttons = '';
                            
                            if (permissions.is_admin && data.kullanici_id != CURRENT_USER_ID && data.kullanici_durum == 1) {
                                buttons += `<button class="btn btn-sm btn-info text-white me-1" onclick="kullaniciOlarakGirisYap(${data.kullanici_id}, '${adSoyad}')" title="Bu kullanıcı olarak giriş yap">
                                    <i class="bi bi-person-fill-check"></i>
                                </button>`;
                            }

                            if (permissions.can_edit) {
                                buttons += `<a href="/Admin/personel-form?id=${data.kullanici_id}" class="btn btn-sm btn-warning me-1" title="Düzenle">
                                    <i class="bi bi-pencil"></i>
                                </a>`;
                            }

                            if (permissions.can_delete) {
                                buttons += `<button class="btn btn-sm btn-danger" onclick="kullaniciSil(${data.kullanici_id}, '${adSoyad}')" title="Sil">
                                    <i class="bi bi-trash"></i>
                                </button>`;
                            }

                            return buttons || '<span class="text-muted">-</span>';
                        }
                    }
                ],
                order: [[1, 'asc']]
            });
        }
        
        function loadDepartmanlar(targetSelect = '#filter_departman_id', callback = null) {
            $.post('/Admin/pages/personel-yonetimi.php', { action: 'get_departmanlar' }, response => {
                if (response.success) {
                    const select = $(targetSelect);
                    select.find('option:not(:first)').remove();
                    response.data.forEach(d => select.append(`<option value="${d.departman_id}">${d.departman_adi}</option>`));
                }
                if (callback) callback();
            });
        }

        function loadBirimler(targetSelect = '#filter_birim_id', callback = null) {
            $.post('/Admin/pages/personel-yonetimi.php', { action: 'get_birimler' }, response => {
                if (response.success) {
                    const select = $(targetSelect);
                    select.find('option:not(:first)').remove();
                    response.data.forEach(b => select.append(`<option value="${b.KullaniciBirim_id}">${b.KullaniciBirim_Adi}</option>`));
                }
                if (callback) callback();
            });
        }
        
        $(document).ready(() => {
            loadStats();
            
            // Kaydedilmiş filtreleri yükle
            currentfilters = loadfiltersFromStorage();
            
            // DataTable başlat
            initDataTable();
            
            // Filtre dropdown'larını doldur ve sonra filtreleri uygula
            Promise.all([
                new Promise(resolve => loadDepartmanlar('#filter_departman_id', resolve)),
                new Promise(resolve => loadBirimler('#filter_birim_id', resolve))
            ]).then(() => {
                applyfiltersToForm(currentfilters);
                if (Object.keys(currentfilters).length > 0) {
                    $('#filterCard').addClass('show');
                }
            });
            
            // Sidebar toggle - DataTable genişliğini yeniden hesapla
            // Sidebar butonuna click event ekle
            $('[data-lte-toggle="sidebar"]').on('click', function() {
                setTimeout(function() {
                    if (table) {
                        $(window).trigger('resize');
                        table.columns.adjust().draw();
                    }
                }, 350);
            });
            
            // Body class değişimini izle (sidebar-collapse eklendiğinde/kaldırıldığında)
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'class') {
                        setTimeout(function() {
                            if (table) {
                                $(window).trigger('resize');
                                table.columns.adjust().draw();
                            }
                        }, 350);
                    }
                });
            });
            
            const bodyElement = document.querySelector('body');
            if (bodyElement) {
                observer.observe(bodyElement, { attributes: true });
            }
            
            // Filtre dropdown'larını doldur (Promise destekli)
            // Not: Artık document.ready içinde Promise.all ile yönetiliyor
            
            // Filtre dropdown'lari için Select2 başlat
            setTimeout(() => {
                $('.form-select').select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    placeholder: 'Seçiniz...',
                    allowClear: true,
                    language: {
                        noResults: function() { return "Sonuç bulunamadı"; },
                        searching: function() { return "Aranıyor..."; }
                    }
                });
            }, 300);
            
            // Filtre form submit
            $('#filterForm').on('submit', function(e) {
                e.preventDefault();
                
                currentfilters = {
                    departman_id: $('#filter_departman_id').val(),
                    birim_id: $('#filter_birim_id').val(),
                    tc_kimlik_no: $('#filter_tc_kimlik_no').val(),
                    durum: $('#filter_durum').val(),
                    search: $('#filter_search').val()
                };
                
                Object.keys(currentfilters).forEach(key => {
                    if (!currentfilters[key]) delete currentfilters[key];
                });
                
                // Filtreleri localStorage'a kaydet
                savefiltersToStorage();
                
                table.ajax.reload();
                showToast('Filtre uygulandı', 'info');
            });
            
            // Filtreleri temizle
            $('#clearfilters').on('click', function() {
                $('#filterForm')[0].reset();
                $('#filter_departman_id').val('').trigger('change.select2');
                $('#filter_birim_id').val('').trigger('change.select2');
                $('#filter_durum').val('').trigger('change.select2');
                $('#filter_tc_kimlik_no').val('');
                $('#filter_search').val('');
                currentfilters = {};
                
                // localStorage'dan sil
                localStorage.removeItem(filter_STORAGE_KEY);
                
                table.ajax.reload();
                showToast('Filtreler temizlendi', 'info');
            });
            
            // Excel indir butonu
            $('#excelindir').on('click', function() {
                const form = $('<form>', {
                    method: 'POST',
                    action: '/Admin/pages/personel-yonetimi.php'
                });
                
                // Action ekle
                form.append($('<input>', { type: 'hidden', name: 'action', value: 'excel_indir' }));
                
                // Mevcut filtreleri ekle
                Object.keys(currentfilters).forEach(key => {
                    if (currentfilters[key]) {
                        form.append($('<input>', { type: 'hidden', name: key, value: currentfilters[key] }));
                    }
                });
                
                // Form günder ve kaldır
                $('body').append(form);
                form.submit();
                form.remove();
                
                showToast('Excel dosyası ındiriliyor...', 'info');
            });
        });
        

        function kullaniciOlarakGirisYap(id, adSoyad) {
            Swal.fire({
                title: 'Kullanıcı Olarak Giriş',
                html: `<strong>${adSoyad}</strong> adlı kullanıcı olarak giriş yapılacak.<br>
                       <small class="text-muted">Orijinal hesabınıza geri dönebilirsiniz.</small>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0dcaf0',
                cancelButtonText: 'İptal',
                confirmButtonText: '<i class="bi bi-person-fill-check"></i> Giriş Yap'
            }).then(result => {
                if (!result.isConfirmed) return;

                $.post('/Admin/pages/personel-yonetimi.php', {
                    action: 'kullanici_olarak_giris',
                    kullanici_id: id
                }, response => {
                    if (response.success) {
                        showToast(`${adSoyad} olarak giriş yapıldı. Yönlendiriliyorsunuz...`, 'success');
                        setTimeout(() => { window.location.href = response.redirect; }, 1200);
                    } else {
                        Swal.fire('Hata!', response.message, 'error');
                    }
                }).fail(() => {
                    Swal.fire('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.', 'error');
                });
            });
        }

        function kullaniciSil(id, adSoyad) {
            confirmAction(
                `"${adSoyad}" kullanıcısını silmek istediğinize emin misiniz?`,
                'Bu işlem geri alınamaz!',
                function() {
                    fetch('/Admin/pages/personel-yonetimi.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `action=kullanici_sil&id=${id}`
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess('Silindi!', data.message);
                            table.ajax.reload();
                            loadStats();
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
    </script>
<!-- Personel listesi: tablo ici durum switch'i -->
<script>
(function ($) {
    'use strict';

    $(function () {
        $('#kullaniciTable').on('change', '.durum-switch', function () {
            var $sw = $(this);
            var id = $sw.data('id');
            var durum = $sw.is(':checked') ? 1 : 0;

            $sw.prop('disabled', true);

            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=durum_degistir&id=' + id + '&durum=' + durum
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    $sw.attr('title', data.durum == 1 ? 'Aktif' : 'Pasif');
                    if (typeof showToast === 'function') showToast(data.message, 'success');
                    if (typeof loadStats === 'function') loadStats();
                } else {
                    $sw.prop('checked', durum !== 1);
                    if (typeof showError === 'function') showError('Hata!', data.message);
                    else alert(data.message);
                }
            })
            .catch(function () {
                $sw.prop('checked', durum !== 1);
                if (typeof showError === 'function') showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.');
            })
            .finally(function () { $sw.prop('disabled', false); });
        });
    });
})(jQuery);
</script>
</body>
</html>
