<?php
/**
 * Admin Panel - Giriş Sayfası
 * Portal Örnek Yazılım
 */

require_once __DIR__ . '/auth.php';

// Zaten giriş yapılmışsa ana sayfaya yönlendir
if (Auth::check()) {
    redirect('index.php');
}

// Site ayarlarını veritabanından çek
$db = Database::getInstance();
$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_footer_yazi, site_ayarlari_site_title, site_ayarlari_favicon_url,
           site_ayarlari_registration_enabled
    FROM dbo.tanim_site_ayarlari 
    ORDER BY site_ayarlari_id DESC
");

// Kayıt açık mı? (Hesap oluştur linki için)
$kayitAcik = $siteAyarlari && (int)($siteAyarlari['site_ayarlari_registration_enabled'] ?? 0) === 1;

$footerYazi = '';
$siteTitle = 'Örnek Yazılım Portal';

if ($siteAyarlari) {
    if (!empty($siteAyarlari['site_ayarlari_footer_yazi'])) {
        $footerYazi = htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi']);
    } else {
        $footerYazi = '© ' . date('Y') . ' Örnek Yazılım. Tüm hakları saklıdır.';
    }
    
    if (!empty($siteAyarlari['site_ayarlari_site_title'])) {
        $siteTitle = htmlspecialchars($siteAyarlari['site_ayarlari_site_title']);
    }
} else {
    $footerYazi = '© ' . date('Y') . ' Örnek Yazılım. Tüm hakları saklıdır.';
}

$error = '';
$success = '';

// Form gönderimi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'Lütfen tüm alanları doldurun!';
    } else {
        $auth = new Auth();
        $result = $auth->login($email, $password);
        
        if ($result['success']) {
            // İlk girişte şifre değiştirme zorunluluğu kontrolü
            if (!empty($_SESSION['sifre_degistirmeli'])) {
                redirect('/admin/pages/profil.php?sifre_degistir=1');
            }

            // Giriş öncesi girilmek istenen sayfa varsa oraya dön
            // (zaman aşımında JS adresi ?donus= ile taşır; form action="" query'yi korur)
            $hedef = $_GET['donus'] ?? ($_SESSION['giris_sonrasi_url'] ?? null);
            unset($_SESSION['giris_sonrasi_url']);

            // Açık yönlendirme koruması: yalnızca kendi /admin/ yolumuza izin ver
            if (is_string($hedef) && $hedef !== '' && preg_match('#^/admin/(?!login\.php|logout\.php)[\w\-./]*(\?[\w\-./=&%+]*)?$#', $hedef)) {
                redirect($hedef);
            }

            redirect('index.php');
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap - <?= $siteTitle ?></title>
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars(($siteAyarlari['site_ayarlari_favicon_url'] ?? '') ?: '/favicon.ico') ?>">
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" integrity="sha256-tXJfXfp6Ewt1ilPzLDtQnJV4hclT9XuaZUKyUvmyr+Q=" crossorigin="anonymous">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    
    <!-- AdminLTE -->
    <link rel="stylesheet" href="assets/css/adminlte.min.css">
</head>
<body class="login-page bg-body-secondary">
    <div class="login-box">
        <div class="login-logo">
            <a href="index.php"><b><?= $siteTitle ?></b></a>
        </div>
        
        <div class="card">
            <div class="card-body login-card-body">
                <p class="login-box-msg">Admin Paneli Girişi</p>

                <?php if (isset($_GET['zaman_asimi'])): ?>
                    <div class="alert alert-warning alert-dismissible">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <i class="bi bi-clock-history"></i> Oturum süreniz dolduğu için çıkış yapıldı. Lütfen tekrar giriş yapın.
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="input-group mb-3">
                        <input 
                            type="email" 
                            class="form-control" 
                            name="email"
                            placeholder="E-posta" 
                            required
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        >
                        <div class="input-group-text">
                            <span class="bi bi-envelope"></span>
                        </div>
                    </div>
                    
                    <div class="input-group mb-3">
                        <input 
                            type="password" 
                            class="form-control" 
                            name="password"
                            placeholder="Şifre" 
                            required
                        >
                        <div class="input-group-text">
                            <span class="bi bi-lock-fill"></span>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">Giriş Yap</button>
                            </div>
                        </div>
                    </div>
                </form>
                
                <div class="d-flex <?= $kayitAcik ? 'justify-content-between' : 'justify-content-center' ?> mt-3 mb-0">
                    <a href="sifre-sifirla.php">
                        <i class="bi bi-key"></i> Şifremi unuttum
                    </a>
                    <?php if ($kayitAcik): ?>
                    <a href="register.php">
                        <i class="bi bi-person-plus"></i> Hesap oluştur
                    </a>
                    <?php endif; ?>
                </div>
                
                <p class="mt-3 mb-1 text-center">
                    <small class="text-muted"><?= $footerYazi ?></small>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    
    <!-- AdminLTE -->
    <script src="assets/js/adminlte.min.js"></script>
</body>
</html>
