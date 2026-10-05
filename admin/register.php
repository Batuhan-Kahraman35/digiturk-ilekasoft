<?php
/**
 * Admin Panel - Hesap Oluşturma Talebi (Ticket)
 * Portal Örnek Yazılım
 *
 * Kullanıcı doğrudan oluşturulmaz. Form verisi Destek API'ye "create_ticket"
 * olarak iletilir; yönetici ticketı görüp personel-yonetimi üzerinden manuel oluşturur.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/DestekHelper.php';

// Zaten giriş yapılmışsa ana sayfaya yönlendir
if (Auth::check()) {
    redirect('index.php');
}

$db = Database::getInstance();
$siteAyarlari = $db->fetchOne("
    SELECT TOP 1 site_ayarlari_footer_yazi, site_ayarlari_site_title, site_ayarlari_favicon_url
    FROM dbo.tanim_site_ayarlari
    ORDER BY site_ayarlari_id DESC
");

$footerYazi = '';
$siteTitle  = 'Örnek Yazılım Portal';

if ($siteAyarlari) {
    $footerYazi = !empty($siteAyarlari['site_ayarlari_footer_yazi'])
        ? htmlspecialchars($siteAyarlari['site_ayarlari_footer_yazi'])
        : '© ' . date('Y') . ' Örnek Yazılım. Tüm hakları saklıdır.';
    if (!empty($siteAyarlari['site_ayarlari_site_title'])) {
        $siteTitle = htmlspecialchars($siteAyarlari['site_ayarlari_site_title']);
    }
} else {
    $footerYazi = '© ' . date('Y') . ' Örnek Yazılım. Tüm hakları saklıdır.';
}

// Şehirler (ticket mesajına şehir adını yazmak için)
$sehirler = $db->fetchAll("SELECT Sehirid, SehirAdi FROM Sehirler ORDER BY SehirAdi");

$error   = '';
$success = '';

/** Liste içinde arama kelimesi geçen ilk kaydın ID'sini bulur; yoksa ilk kayıt. */
function findMetaId(array $liste, string $aramaKelimesi): int
{
    foreach ($liste as $item) {
        $ad = mb_strtolower($item['ad'] ?? $item['name'] ?? '');
        if (str_contains($ad, mb_strtolower($aramaKelimesi))) {
            return (int)($item['id'] ?? 0);
        }
    }
    return (int)($liste[0]['id'] ?? 0);
}

// Form gönderimi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ad       = trim($_POST['ad'] ?? '');
    $soyad    = trim($_POST['soyad'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $telefon  = trim($_POST['telefon'] ?? '');
    $sehirId  = $_POST['sehir_id'] ?? '';
    $aciklama = trim($_POST['aciklama'] ?? '');
    $formAnahtar = (string)($_POST['form_anahtar'] ?? '');

    if (!DestekHelper::formAnahtarGecerliMi($formAnahtar)) {
        $error = 'Geçersiz form anahtarı. Sayfayı yenileyip tekrar deneyin.';
    } elseif ($oncekiSonuc = DestekHelper::formAnahtarKaydi($formAnahtar)) {
        // Aynı form ikinci kez gönderildi: yeni ticket açmadan ilk sonucu göster
        $success = $oncekiSonuc;
        $_POST = [];
    } elseif (empty($ad) || empty($soyad) || empty($email)) {
        $error = 'Lütfen Ad, Soyad ve E-posta alanlarını doldurun!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçersiz e-posta adresi!';
    } elseif (!DestekHelper::aktifMi()) {
        $error = 'Talep sistemi şu anda yapılandırılmamış. Lütfen yönetici ile iletişime geçin.';
    } else {
        // Şehir adını bul (mesaj gövdesi için)
        $sehirAdi = '';
        if ($sehirId !== '') {
            foreach ($sehirler as $s) {
                if ((string)$s['Sehirid'] === (string)$sehirId) {
                    $sehirAdi = $s['SehirAdi'];
                    break;
                }
            }
        }

        // Kategori / öncelik ID'lerini meta'dan dinamik bul
        $meta        = DestekHelper::meta();
        $kategoriler = $meta['data']['kategoriler'] ?? [];
        $oncelikler  = $meta['data']['oncelikler']  ?? [];
        $kategoriId  = findMetaId($kategoriler, 'diğer');
        $oncelikId   = findMetaId($oncelikler, 'normal');

        // Ticket mesaj gövdesi
        $mesaj  = "Ad Soyad : {$ad} {$soyad}\n";
        $mesaj .= "E-posta  : {$email}\n";
        $mesaj .= "Telefon  : " . ($telefon !== '' ? $telefon : '-') . "\n";
        $mesaj .= "Şehir    : " . ($sehirAdi !== '' ? $sehirAdi : '-') . "\n";
        if ($aciklama !== '') {
            $mesaj .= "\nAçıklama : {$aciklama}\n";
        }
        $mesaj .= "\n---\nHesap oluşturmak için: /admin/personel-yonetimi";

        // Misafir kullanıcı bilgisiyle ticket aç (Auth::user yok, elle veriliyor)
        $res = DestekHelper::apiCall('create_ticket', [
            'konu'        => 'Hesap oluşturma talebi',
            'mesaj'       => $mesaj,
            'kategori_id' => $kategoriId,
            'oncelik_id'  => $oncelikId,
            'kullanici'   => [
                'kaynak_kullanici_id' => '0',
                'ad'                  => $ad,
                'soyad'               => $soyad,
                'eposta'              => $email,
                'telefon'             => preg_replace('/\D/', '', $telefon),
            ],
        ]);

        if ($res['success'] ?? false) {
            $success = 'Başvurunuz alındı! Talebiniz yöneticiye iletildi, hesabınız oluşturulunca bilgilendirileceksiniz.';
            DestekHelper::formAnahtarKaydet($formAnahtar, $success);
            $_POST = [];
        } else {
            $error = $res['message'] ?? 'Başvuru gönderilemedi, lütfen tekrar deneyin.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hesap Oluştur - <?= $siteTitle ?></title>
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars(($siteAyarlari['site_ayarlari_favicon_url'] ?? '') ?: '/favicon.ico') ?>">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="assets/css/adminlte.min.css">

    <style>
        .register-box { width: 450px; }
        @media (max-width: 576px) { .register-box { width: 90%; } }
    </style>
</head>
<body class="register-page bg-body-secondary">
    <div class="register-box">
        <div class="register-logo">
            <a href="login.php"><b><?= $siteTitle ?></b></a>
        </div>

        <div class="card">
            <div class="card-body register-card-body">
                <p class="login-box-msg">Hesap Oluşturma Talebi</p>

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
                    <div class="text-center mt-3">
                        <a href="login.php" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> Giriş Yap
                        </a>
                    </div>
                <?php else: ?>

                <p class="text-muted small text-center mb-3">
                    Talebiniz yöneticiye iletilir. Hesabınız onaylandıktan sonra giriş yapabilirsiniz.
                </p>

                <form method="POST" action="" id="registerForm">
                    <input type="hidden" name="form_anahtar" value="<?= bin2hex(random_bytes(16)) ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="input-group mb-3">
                                <input type="text" class="form-control" name="ad" placeholder="Ad *" required
                                       value="<?= htmlspecialchars($_POST['ad'] ?? '') ?>">
                                <div class="input-group-text"><span class="bi bi-person"></span></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="input-group mb-3">
                                <input type="text" class="form-control" name="soyad" placeholder="Soyad *" required
                                       value="<?= htmlspecialchars($_POST['soyad'] ?? '') ?>">
                                <div class="input-group-text"><span class="bi bi-person-fill"></span></div>
                            </div>
                        </div>
                    </div>

                    <div class="input-group mb-3">
                        <input type="email" class="form-control" name="email" placeholder="E-posta *" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        <div class="input-group-text"><span class="bi bi-envelope"></span></div>
                    </div>

                    <div class="input-group mb-3">
                        <input type="tel" class="form-control" name="telefon" placeholder="Telefon (5XX XXX XX XX)"
                               pattern="[0-9]{10}" maxlength="10"
                               value="<?= htmlspecialchars($_POST['telefon'] ?? '') ?>">
                        <div class="input-group-text"><span class="bi bi-telephone"></span></div>
                    </div>

                    <div class="input-group mb-3">
                        <select class="form-select" name="sehir_id" id="sehir_id">
                            <option value="">Şehir Seçiniz</option>
                            <?php foreach ($sehirler as $sehir): ?>
                                <option value="<?= $sehir['Sehirid'] ?>" <?= (($_POST['sehir_id'] ?? '') == $sehir['Sehirid']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sehir['SehirAdi']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="input-group-text"><span class="bi bi-geo-alt"></span></div>
                    </div>

                    <div class="input-group mb-3">
                        <textarea class="form-control" name="aciklama" rows="2"
                                  placeholder="Açıklama (opsiyonel)"><?= htmlspecialchars($_POST['aciklama'] ?? '') ?></textarea>
                        <div class="input-group-text"><span class="bi bi-chat-left-text"></span></div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-person-plus"></i> Başvuru Yap
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <p class="mt-3 mb-0 text-center">
                    <a href="login.php"><i class="bi bi-box-arrow-in-right"></i> Zaten hesabım var</a>
                </p>

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
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="assets/js/adminlte.min.js"></script>

    <script>
        $(document).ready(function () {
            $('#sehir_id').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Şehir Seçiniz',
                allowClear: true,
                language: {
                    noResults: function () { return "Sonuç bulunamadı"; },
                    searching: function () { return "Aranıyor..."; }
                }
            });

            // Çift tıklamada formun ikinci kez gönderilmesini engelle
            $('#registerForm').on('submit', function (e) {
                var $form = $(this);
                if ($form.data('gonderiliyor')) { e.preventDefault(); return; }
                $form.data('gonderiliyor', true);
                $form.find('button[type="submit"]').prop('disabled', true)
                     .html('<i class="bi bi-hourglass-split"></i> Gönderiliyor...');
            });
        });
    </script>
</body>
</html>
