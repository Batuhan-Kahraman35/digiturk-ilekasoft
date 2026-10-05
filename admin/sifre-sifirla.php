<?php
/**
 * Admin Panel - Şifre Sıfırlama Talebi
 * Portal Örnek Yazılım
 *
 * E-posta/telefona göre kullanıcıyı bulur, token üretir, 1 saatlik link'i
 * Gmail (SMTP) ve/veya WhatsApp (Evolution) ile gönderir.
 * Güvenlik: kullanıcı bulunsun ya da bulunmasın aynı başarı mesajı gösterilir.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/EntegrasyonHelper.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kimlik = trim($_POST['kimlik'] ?? ''); // e-posta veya telefon

    if ($kimlik === '') {
        $error = 'Lütfen e-posta adresinizi veya telefon numaranızı girin.';
    } else {
        $telefonSade = preg_replace('/\D/', '', $kimlik);

        // Aktif kullanıcıyı e-posta veya telefona göre bul
        $kullanici = $db->fetchOne("
            SELECT TOP 1 kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email, kullanici_telefon
            FROM kullanicilar
            WHERE kullanici_durum = 1
              AND (kullanici_email = ? OR (LEN(?) >= 10 AND kullanici_telefon = ?))
        ", [$kimlik, $telefonSade, $telefonSade]);

        if ($kullanici) {
            $token  = bin2hex(random_bytes(32));           // 64 karakter hex
            $expire = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $db->update('kullanicilar', [
                'kullanici_sifre_sifirlama_token'  => $token,
                'kullanici_sifre_sifirlama_expire' => $expire,
            ], ['kullanici_id' => $kullanici['kullanici_id']]);

            // Sıfırlama linki (mevcut host üzerinden)
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $link   = "{$scheme}://{$host}/admin/sifre-yenile.php?token={$token}";

            $adSoyad = trim(($kullanici['kullanici_ad'] ?? '') . ' ' . ($kullanici['kullanici_soyad'] ?? ''));

            // ── Gmail (SMTP) gönderimi ──
            $emailKanallari = EntegrasyonHelper::aktifKanallar('email');
            if (!empty($emailKanallari) && !empty($kullanici['kullanici_email'])) {
                $konu = 'Şifre Sıfırlama Talebi';
                $govde = "<p>Merhaba " . htmlspecialchars($adSoyad) . ",</p>"
                       . "<p>Şifrenizi sıfırlamak için aşağıdaki bağlantıya tıklayın. Bağlantı <b>1 saat</b> geçerlidir.</p>"
                       . "<p><a href=\"{$link}\" style=\"display:inline-block;padding:10px 18px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px\">Şifremi Sıfırla</a></p>"
                       . "<p>Bağlantı çalışmazsa: <br><small>{$link}</small></p>"
                       . "<hr><small>Bu talebi siz yapmadıysanız bu e-postayı yok sayabilirsiniz.</small>";
                EntegrasyonHelper::emailGonder(
                    (int)$emailKanallari[0]['EntegrasyonKanallari_id'],
                    $kullanici['kullanici_email'],
                    $konu,
                    $govde,
                    true
                );
            }

            // ── WhatsApp (Evolution) gönderimi ──
            $waKanallari = EntegrasyonHelper::aktifKanallar('whatsapp');
            $waTelefon   = preg_replace('/\D/', '', $kullanici['kullanici_telefon'] ?? '');
            if (!empty($waKanallari) && $waTelefon !== '') {
                if (strlen($waTelefon) === 10) {
                    $waTelefon = '90' . $waTelefon; // 5XX... → 905XX...
                }
                $waMesaj = "Merhaba {$adSoyad},\n\nŞifre sıfırlama bağlantınız (1 saat geçerli):\n{$link}\n\nBu talebi siz yapmadıysanız mesajı yok sayın.";
                EntegrasyonHelper::whatsappGonder(
                    (int)$waKanallari[0]['EntegrasyonKanallari_id'],
                    $waTelefon,
                    $waMesaj
                );
            }
        }

        // Güvenlik: kullanıcı bulunsun/bulunmasın aynı mesaj
        $success = 'Eğer bilgileriniz sistemde kayıtlıysa, şifre sıfırlama bağlantısı e-posta ve/veya WhatsApp ile gönderildi. Lütfen kontrol edin.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şifremi Unuttum - <?= $siteTitle ?></title>
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars(($siteAyarlari['site_ayarlari_favicon_url'] ?? '') ?: '/favicon.ico') ?>">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/adminlte.min.css">
</head>
<body class="login-page bg-body-secondary">
    <div class="login-box">
        <div class="login-logo">
            <a href="login.php"><b><?= $siteTitle ?></b></a>
        </div>

        <div class="card">
            <div class="card-body login-card-body">
                <p class="login-box-msg">Şifremi Unuttum</p>

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
                            <i class="bi bi-box-arrow-in-right"></i> Giriş Sayfasına Dön
                        </a>
                    </div>
                <?php else: ?>

                <p class="text-muted small">
                    Hesabınıza kayıtlı e-posta adresinizi veya telefon numaranızı girin.
                    Şifre sıfırlama bağlantısını göndereceğiz.
                </p>

                <form method="POST" action="">
                    <div class="input-group mb-3">
                        <input type="text" class="form-control" name="kimlik"
                               placeholder="E-posta veya Telefon" required autofocus
                               value="<?= htmlspecialchars($_POST['kimlik'] ?? '') ?>">
                        <div class="input-group-text"><span class="bi bi-person"></span></div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-send"></i> Sıfırlama Bağlantısı Gönder
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <p class="mt-3 mb-0 text-center">
                    <a href="login.php"><i class="bi bi-arrow-left"></i> Giriş sayfasına dön</a>
                </p>

                <?php endif; ?>

                <p class="mt-3 mb-1 text-center">
                    <small class="text-muted"><?= $footerYazi ?></small>
                </p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="assets/js/adminlte.min.js"></script>
</body>
</html>
