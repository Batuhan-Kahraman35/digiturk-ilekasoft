<?php
/**
 * Admin Panel - Profil Yönetimi
 */

require_once __DIR__ . '/../auth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

// Site title'ı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

$pageTitle = 'Profil';

// AJAX İşlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_profile') {
            $ad = trim($_POST['ad'] ?? '');
            $soyad = trim($_POST['soyad'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $telefon = trim($_POST['telefon'] ?? '');
            
            if (empty($ad) || empty($soyad) || empty($email)) {
                echo json_encode(['success' => false, 'message' => 'Ad, Soyad ve E-posta alanları zorunludur.']);
                exit;
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Geçerli bir e-posta adresi giriniz.']);
                exit;
            }
            
            $emailCheck = $db->fetchOne("SELECT kullanici_id FROM kullanicilar WHERE kullanici_email = ? AND kullanici_id != ?", [$email, $user['id']]);
            
            if ($emailCheck) {
                echo json_encode(['success' => false, 'message' => 'Bu e-posta adresi başka bir kullanıcı tarafından kullanılıyor.']);
                exit;
            }
            
            $updated = $db->update('kullanicilar', [
                'kullanici_ad' => $ad,
                'kullanici_soyad' => $soyad,
                'kullanici_email' => $email,
                'kullanici_telefon' => $telefon
            ], ['kullanici_id' => $user['id']]);
            
            if ($updated) {
                $_SESSION['user_name'] = $ad . ' ' . $soyad;
                $_SESSION['user_email'] = $email;
                echo json_encode(['success' => true, 'message' => 'Profil bilgileriniz başarıyla güncellendi.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Profil güncellenirken bir hata oluştu.']);
            }
            exit;
        }
        
        if ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                echo json_encode(['success' => false, 'message' => 'Tüm alanları doldurunuz.']);
                exit;
            }
            
            if ($newPassword !== $confirmPassword) {
                echo json_encode(['success' => false, 'message' => 'Yeni şifreler eşleşmiyor.']);
                exit;
            }
            
            if (strlen($newPassword) < 6) {
                echo json_encode(['success' => false, 'message' => 'Şifre en az 6 karakter olmalıdır.']);
                exit;
            }
            
            $userInfo = $db->fetchOne("SELECT kullanici_sifre_hash FROM kullanicilar WHERE kullanici_id = ?", [$user['id']]);
            
            if (!$userInfo || !password_verify($currentPassword, $userInfo['kullanici_sifre_hash'])) {
                echo json_encode(['success' => false, 'message' => 'Mevcut şifreniz hatalı.']);
                exit;
            }
            
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updated = $db->update('kullanicilar', [
                'kullanici_sifre_hash' => $hashedPassword,
                'kullanici_sifre_degistirmeli' => 0
            ], ['kullanici_id' => $user['id']]);
            
            if ($updated) {
                $_SESSION['sifre_degistirmeli'] = 0;
                echo json_encode(['success' => true, 'message' => 'Şifreniz başarıyla değiştirildi.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Şifre değiştirilirken bir hata oluştu.']);
            }
            exit;
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Bir hata oluştu: ' . $e->getMessage()]);
        exit;
    }
}

// Kullanıcı bilgilerini çek
$userInfo = $db->fetchOne("
    SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email, kullanici_telefon, kullanici_olusturma_tarihi, kullanici_son_giris_tarihi
    FROM kullanicilar WHERE kullanici_id = ?
", [$user['id']]);

if (!$userInfo) {
    die('Kullanıcı bilgileri bulunamadı.');
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
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
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
                                <li class="breadcrumb-item"><a href="anasayfa.php">Ana Sayfa</a></li>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="app-content">
                <div class="container-fluid">
                    <?php if (isset($_GET['sifre_degistir']) && $_GET['sifre_degistir'] == '1'): ?>
                    <div class="alert alert-warning alert-dismissible mb-3">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Şifre Değiştirme Zorunlu!</strong> İlk girişiniz olduğu için devam etmeden önce şifrenizi değiştirmeniz gerekmektedir.
                    </div>
                    <?php endif; ?>
                    <div class="row">
                        <!-- Profil Bilgileri -->
                        <div class="col-md-6">
                            <div class="card card-primary card-outline">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-person"></i> Profil Bilgileri</h3>
                                </div>
                                <form id="profileForm">
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label for="ad" class="form-label">Ad <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="ad" name="ad" value="<?= htmlspecialchars($userInfo['kullanici_ad'] ?? '') ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="soyad" class="form-label">Soyad <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="soyad" name="soyad" value="<?= htmlspecialchars($userInfo['kullanici_soyad'] ?? '') ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="email" class="form-label">E-posta <span class="text-danger">*</span></label>
                                            <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($userInfo['kullanici_email'] ?? '') ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="telefon" class="form-label">Telefon</label>
                                            <input type="text" class="form-control" id="telefon" name="telefon" value="<?= htmlspecialchars($userInfo['kullanici_telefon'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="card-footer">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle"></i> Bilgileri Güncelle
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Şifre Değiştir -->
                        <div class="col-md-6">
                            <div class="card card-warning card-outline">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-shield-lock"></i> Şifre Değiştir</h3>
                                </div>
                                <form id="passwordForm">
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label for="current_password" class="form-label">Mevcut Şifre <span class="text-danger">*</span></label>
                                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="new_password" class="form-label">Yeni Şifre <span class="text-danger">*</span></label>
                                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                                            <small class="form-text text-muted">En az 6 karakter olmalıdır.</small>
                                        </div>
                                        <div class="mb-3">
                                            <label for="confirm_password" class="form-label">Yeni Şifre (Tekrar) <span class="text-danger">*</span></label>
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                        </div>
                                    </div>
                                    <div class="card-footer">
                                        <button type="submit" class="btn btn-warning">
                                            <i class="bi bi-key"></i> Şifreyi Değiştir
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        
        <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    
    <script src="/admin/assets/js/custom.js"></script>
    <script>
        // Profil Güncelleme
        document.getElementById('profileForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'update_profile');
            
            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast('Bir hata oluştu: ' + error.message, 'error');
            }
        });
        
        // Şifre Değiştirme
        const sifreDegistirZorunlu = <?= (!empty($_GET['sifre_degistir']) && $_GET['sifre_degistir'] == '1') ? 'true' : 'false' ?>;
        
        document.getElementById('passwordForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'change_password');
            
            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    this.reset();
                    // Zorunlu şifre değiştirme ise ana sayfaya yönlendir
                    if (sifreDegistirZorunlu) {
                        setTimeout(() => window.location.href = 'anasayfa.php', 1500);
                    }
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast('Bir hata oluştu: ' + error.message, 'error');
            }
        });
    </script>
</body>
</html>
