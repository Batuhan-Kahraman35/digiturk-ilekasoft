<?php
/**
 * Gizlilik Politikası — herkese açık (Meta App Review zorunlu sayfası).
 * App settings → Temel → "Privacy Policy URL" olarak girilecek:
 *   https://proje.ornekyazilim.com/gizlilik-politikasi.php
 *
 * Aşağıdaki sabitleri kendi firma bilgilerinle GÜNCELLE.
 */
const FIRMA_ADI       = 'ORNEK';
const ILETISIM_EPOSTA = 'info@ornekyazilim.com';   // ← kendi e-postanla değiştir
const WEB_ADRESI      = 'https://proje.ornekyazilim.com';
const YURURLUK_TARIHI = '29.06.2026';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gizlilik Politikası — <?= htmlspecialchars(FIRMA_ADI) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width: 860px;">
    <h1 class="mb-1">Gizlilik Politikası</h1>
    <p class="text-muted">Yürürlük tarihi: <?= htmlspecialchars(YURURLUK_TARIHI) ?></p>
    <hr>

    <p><strong><?= htmlspecialchars(FIRMA_ADI) ?></strong> ("biz", "şirket") olarak kişisel verilerinizin
    gizliliğine önem veriyoruz. Bu politika, Facebook ve Instagram reklam (Lead Ads) formları aracılığıyla
    topladığımız verilerin nasıl işlendiğini açıklar. Veri sorumlusu <?= htmlspecialchars(FIRMA_ADI) ?>'dır.</p>

    <h4 class="mt-4">1. Topladığımız Veriler</h4>
    <p>Facebook/Instagram lead formlarını doldurduğunuzda, yalnızca formda paylaştığınız bilgileri alırız:</p>
    <ul>
        <li>Ad ve soyad</li>
        <li>Telefon numarası</li>
        <li>E-posta adresi</li>
        <li>Formda yer alan diğer iletişim/talep bilgileri</li>
    </ul>

    <h4 class="mt-4">2. Verileri Nasıl Toplarız</h4>
    <p>Verileriniz, yalnızca siz bir Facebook/Instagram lead formunu gönüllü olarak doldurup
    gönderdiğinizde, Meta'nın <em>Lead Ads</em> altyapısı üzerinden (leads_retrieval izniyle) sistemimize
    aktarılır. Başka herhangi bir kaynaktan veri toplamayız.</p>

    <h4 class="mt-4">3. Verileri İşleme Amaçlarımız</h4>
    <ul>
        <li>Talebiniz/başvurunuz doğrultusunda sizinle iletişime geçmek</li>
        <li>Ürün ve hizmetlerimiz hakkında bilgi vermek</li>
        <li>Başvuru ve müşteri kayıtlarını yönetmek</li>
    </ul>

    <h4 class="mt-4">4. Verilerin Paylaşımı</h4>
    <p>Verilerinizi pazarlama amacıyla üçüncü taraflara satmayız. Yalnızca hizmetin sunulması için
    gerekli olduğunda (ör. çağrı merkezi/iş ortağı) ve yasal yükümlülükler kapsamında paylaşırız.</p>

    <h4 class="mt-4">5. Saklama Süresi</h4>
    <p>Verileriniz, işleme amacının gerektirdiği süre boyunca veya ilgili mevzuatın öngördüğü süreyle
    saklanır; süre sonunda silinir veya anonim hale getirilir.</p>

    <h4 class="mt-4">6. Facebook/Meta Verileri</h4>
    <p>Meta platformlarından alınan veriler, Meta Platform Şartları'na uygun şekilde işlenir. Bu verileri
    yalnızca yukarıda belirtilen amaçlarla kullanır, izinsiz üçüncü taraflarla paylaşmayız.</p>

    <h4 class="mt-4">7. Haklarınız (KVKK md. 11)</h4>
    <p>Kişisel verilerinize erişme, düzeltme, silme, işlenmesine itiraz etme ve verilerinizin
    silinmesini talep etme hakkına sahipsiniz. Talepleriniz için
    <a href="/veri-silme.php">Veri Silme</a> sayfamızı kullanabilir veya bizimle iletişime geçebilirsiniz.</p>

    <h4 class="mt-4">8. Veri Güvenliği</h4>
    <p>Verileriniz yetkisiz erişime karşı uygun teknik ve idari tedbirlerle korunur.</p>

    <h4 class="mt-4">9. Veri Silme Talebi</h4>
    <p>Verilerinizin silinmesini istiyorsanız <a href="/veri-silme.php">veri silme sayfamızdan</a> talep
    oluşturabilirsiniz.</p>

    <h4 class="mt-4">10. İletişim</h4>
    <p>Sorularınız için: <a href="mailto:<?= htmlspecialchars(ILETISIM_EPOSTA) ?>"><?= htmlspecialchars(ILETISIM_EPOSTA) ?></a><br>
    Web: <a href="<?= htmlspecialchars(WEB_ADRESI) ?>"><?= htmlspecialchars(WEB_ADRESI) ?></a></p>

    <h4 class="mt-4">11. Değişiklikler</h4>
    <p>Bu politika zaman zaman güncellenebilir. Güncel sürüm bu sayfada yayımlanır.</p>

    <hr class="mt-4">
    <p class="text-muted small">&copy; <?= date('Y') ?> <?= htmlspecialchars(FIRMA_ADI) ?>. Tüm hakları saklıdır.</p>
</div>
</body>
</html>
