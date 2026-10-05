<?php
/**
 * Admin Panel - Yeni Şifre Belirleme
 * Portal Örnek Yazılım
 *
 * ?token= parametresini doğrular; geçerliyse yeni şifre formu gösterir.
 * Şifre güncellenince token NULL'a çekilir (tek kullanımlık).
 */

require_once __DIR__ . '/auth.php';

if (Auth::check()) {
    redirect('index.php');
}

$db = Database::getInstance();
$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_footer_yazi, site_ayarlari_site_title, site_ayarlari_favicon_url
    FROM dbo.tanim_site_ayarlari
    ORDER BY site_ayarlari_id DESC
");

$footerYazi = !empty($siteAyarlari['site_ayarlari_footer_yazi'])
    ? htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi'])
    : '© ' . date('Y') . ' Örnek Yazılım. Tüm hakları saklıdır.';
$siteTitle  = !empty($siteAyarlari['site_ayarlari_site_title'])
    ? htmlspecialchars($siteAyarlari['site_ayarlari_site_title'])
    : 'Örnek Yazılım Portal';

$error   = '';
$success = '';
$token   = trim($_GET['token'] ?? $_POST['token'] ?? '');

/** Token'a karşılık gelen geçerli (süresi dolmamış, aktif) kullanıcıyı döner. */
function tokenKullanici(Database $db, string $token): ?array
{
    if ($token === '') return null;
    return $db->fetchOne("
        SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email
        FROM kullanicilar
        WHERE kullanici_sifre_sifirlama_token = ?
          AND kullanici_sifre_sifirlama_expire > GETDATE()
          AND kullanici_durum = 1
    ", [$token]);
}

$kullanici = tokenKullanici($db, $token);

if (!$kullanici) {
    $error = 'Bağlantı geçersiz veya süresi dolmuş. Lütfen yeniden şifre sıfırlama talebi oluşturun.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sifre       = $_POST['sifre'] ?? '';
    $sifreTekrar = $_POST['sifre_tekrar'] ?? '';

    if (empty($sifre) || empty($sifreTekrar)) {
        $error = 'Lütfen tüm alanları doldurun!';
    } elseif (strlen($sifre) < 6) {
        $error = 'Şifre en az 6 karakter olmalıdır!';
    } elseif ($sifre !== $sifreTekrar) {
        $error = 'Şifreler eşleşmiyor!';
    } else {
        $db->update('kullanicilar', [
            'kullanici_sifre_hash'             => password_hash($sifre, PASSWORD_DEFAULT),
            'kullanici_sifre_sifirlama_token'  => null,
            'kullanici_sifre_sifirlama_expire' => null,
            'kullanici_sifre_degistirmeli'     => 0,
        ], ['kullanici_id' => $kullanici['kullanici_id']]);

        $success = 'Şifreniz başarıyla güncellendi. Artık yeni şifrenizle giriş yapabilirsiniz.';
        $kullanici = null; // formu gizle
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni Şifre - <?= $siteTitle ?></title>
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars(($siteAyarlari['site_ayarlari_favicon_url'] ?? '') ?: '/favicon.ico') ?>">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/adminlte.min.css">

    <style>
        .strength-bar { height: 6px; border-radius: 3px; background: #e9ecef; overflow: hidden; }
        .strength-bar > span { display: block; height: 100%; width: 0; transition: width .3s, background-color .3s; }
    </style>
</head>
<body class="login-page bg-body-secondary">
    <div class="login-box">
        <div class="login-logo">
            <a href="login.php"><b><?= $siteTitle ?></b></a>
        </div>

        <div class="card">
            <div class="card-body login-card-body">
                <p class="login-box-msg">Yeni Şifre Belirle</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible">
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
                    </div>
                    <div class="text-center mt-3">
                        <a href="login.php" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> Giriş Yap
                        </a>
                    </div>
                <?php elseif ($kullanici): ?>

                <p class="text-muted small">
                    Merhaba <?= htmlspecialchars(trim($kullanici['kullanici_ad'] . ' ' . $kullanici['kullanici_soyad'])) ?>,
                    yeni şifrenizi belirleyin.
                </p>

                <form method="POST" action="" id="yenileForm">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <div class="input-group mb-2">
                        <input type="password" class="form-control" name="sifre" id="sifre"
                               placeholder="Yeni Şifre (En az 6 karakter)" required minlength="6">
                        <div class="input-group-text"><span class="bi bi-lock-fill"></span></div>
                    </div>

                    <div class="strength-bar mb-3"><span id="strengthFill"></span></div>

                    <div class="input-group mb-3">
                        <input type="password" class="form-control" name="sifre_tekrar" id="sifre_tekrar"
                               placeholder="Yeni Şifre (Tekrar)" required minlength="6">
                        <div class="input-group-text"><span class="bi bi-lock"></span></div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check2-circle"></i> Şifreyi Güncelle
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <?php endif; ?>

                <p class="mt-3 mb-1 text-center">
                    <small class="text-muted"><?= $footerYazi ?></small>
                </p>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="assets/js/adminlte.min.js"></script>

    <script>
        $(function () {
            const renkler = ['#dc3545', '#fd7e14', '#ffc107', '#0dcaf0', '#198754'];
            $('#sifre').on('input', function () {
                const v = $(this).val();
                let puan = 0;
                if (v.length >= 6)       puan++;
                if (v.length >= 10)      puan++;
                if (/[A-ZĞÜŞİÖÇ]/.test(v)) puan++;
                if (/[0-9]/.test(v))     puan++;
                if (/[^A-Za-z0-9]/.test(v)) puan++;
                const yuzde = (puan / 5) * 100;
                $('#strengthFill').css({
                    width: yuzde + '%',
                    backgroundColor: puan > 0 ? renkler[puan - 1] : 'transparent'
                });
            });

            $('#yenileForm').on('submit', function (e) {
                if ($('#sifre').val() !== $('#sifre_tekrar').val()) {
                    e.preventDefault();
                    alert('Şifreler eşleşmiyor!');
                }
            });
        });
    </script>
</body>
</html>
