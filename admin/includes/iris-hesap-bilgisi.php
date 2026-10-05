<?php
/**
 * IRIS Sorgularında Kullanılan Hesap kartı
 *
 * Talep arama/detay çağrıları ANA BAYİ hesabıyla yapılır (Role=DEALER); personel
 * hesabı yalnız kendi kapsamındaki talepleri gördüğü için eşleştirme yapamıyor.
 * Kullanılacak hesap DigiturkAnaBayiler_IrisTalepVarsayilan bayrağıyla seçilir.
 *
 * Süreç durumu güncelleme (ccapi) ayrı bir kimlik kullanır; kart onu da gösterir.
 *
 * Kullanan: admin/pages/iris-talep-eslestirme.php
 * Gerekli: $db (üst sayfadan), $permissions (yetki kontrolü için)
 */

// Web oturumunu açacak ana bayi
$_irisHesap = $db->fetchOne("
    SELECT DigiturkAnaBayiler_Id           AS id,
           DigiturkAnaBayiler_Ad           AS ad,
           DigiturkAnaBayiler_BayiKodu     AS bayiKodu,
           DigiturkAnaBayiler_KullaniciAdi AS kullanici,
           DigiturkAnaBayiler_Sifre        AS sifre
    FROM DigiturkAnaBayiler
    WHERE DigiturkAnaBayiler_IrisTalepVarsayilan = 1 AND Durum = 1");

// Seçilebilecek hesaplar
$_irisHesapListe = $db->fetchAll("
    SELECT DigiturkAnaBayiler_Id           AS id,
           DigiturkAnaBayiler_Ad           AS ad,
           DigiturkAnaBayiler_BayiKodu     AS bayiKodu,
           DigiturkAnaBayiler_KullaniciAdi AS kullanici
    FROM DigiturkAnaBayiler
    WHERE Durum = 1
    ORDER BY DigiturkAnaBayiler_Ad");

// ccapi (Süreç Durumu Güncelle) kimliği — ayrı mekanizma
$_irisCcapi = $db->fetchOne("
    SELECT p.DigiturkAltBayiPersonel_AdSoyad      AS adSoyad,
           p.DigiturkAltBayiPersonel_KullaniciAdi AS kullanici,
           p.DigiturkAltBayiPersonel_TokenDurum   AS tokenDurum,
           CONVERT(VARCHAR(16), p.DigiturkAltBayiPersonel_TokenSuresi, 120) AS tokenSuresi
    FROM DigiturkAltBayiPersonel p
    WHERE p.DigiturkAltBayiPersonel_Id = ?", [IRIS_TALEP_PERSONEL]);

$_sifreVar = $_irisHesap && !empty($_irisHesap['kullanici']) && !empty($_irisHesap['sifre']);
?>
<div class="card card-outline card-info mb-3">
    <div class="card-header py-2">
        <h6 class="card-title mb-0">
            <i class="bi bi-person-badge"></i> IRIS Sorgularında Kullanılan Hesap
        </h6>
    </div>
    <div class="card-body py-2">
        <div class="row g-3 align-items-end">

            <!-- Talep arama / detay — ana bayi oturumu -->
            <div class="col-lg-5">
                <label for="irisHesapSec" class="form-label small text-muted mb-1">
                    <i class="bi bi-search"></i> Talep Arama &amp; Detay (Telefon ile Test / Toplu Eşleştirme)
                </label>
                <select class="form-select form-select-sm" id="irisHesapSec"
                        <?= !empty($permissions['can_edit']) ? '' : 'disabled' ?>>
                    <option value="">— Hesap seçilmedi —</option>
                    <?php foreach ($_irisHesapListe as $h): ?>
                        <option value="<?= (int)$h['id'] ?>"
                            <?= ($_irisHesap && (int)$h['id'] === (int)$_irisHesap['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($h['ad'] ?? '-') ?> (<?= htmlspecialchars($h['bayiKodu'] ?? '-') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-3">
                <span class="text-muted small">Kullanıcı Adı</span><br>
                <code id="irisHesapKullanici"><?= htmlspecialchars($_irisHesap['kullanici'] ?? '-') ?></code>
            </div>

            <div class="col-lg-4">
                <span class="text-muted small">Durum</span><br>
                <?php if (!$_irisHesap): ?>
                    <span class="badge bg-danger">
                        <i class="bi bi-exclamation-triangle"></i> Hesap seçilmedi — sorgular çalışmaz
                    </span>
                <?php elseif (!$_sifreVar): ?>
                    <span class="badge bg-danger">
                        <i class="bi bi-exclamation-triangle"></i> Kullanıcı adı/şifre boş
                    </span>
                <?php else: ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle"></i> Ana bayi (DEALER)</span>
                <?php endif; ?>
            </div>

            <!-- ccapi — süreç durumu -->
            <div class="col-12"><hr class="my-1"></div>
            <div class="col-lg-5">
                <span class="text-muted small">
                    <i class="bi bi-arrow-repeat"></i> Süreç Durumu Güncelle (ccapi token)
                </span><br>
                <strong><?= htmlspecialchars($_irisCcapi['adSoyad'] ?? '-') ?></strong>
            </div>
            <div class="col-lg-3">
                <span class="text-muted small">Kullanıcı Adı</span><br>
                <code><?= htmlspecialchars($_irisCcapi['kullanici'] ?? '-') ?></code>
            </div>
            <div class="col-lg-4">
                <span class="text-muted small">Token Süresi</span><br>
                <?php if (!empty($_irisCcapi['tokenDurum']) && (int)$_irisCcapi['tokenDurum'] === 1): ?>
                    <span class="badge bg-info text-dark"><?= htmlspecialchars($_irisCcapi['tokenSuresi'] ?? '-') ?></span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark">
                        <i class="bi bi-exclamation-triangle"></i> Token pasif — Token Güncelle görevini çalıştırın
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="small text-muted mt-2">
            <i class="bi bi-info-circle"></i>
            Talep arama ana bayi hesabıyla yapılır. Personel hesabı yalnız kendi kaydettiği
            talepleri gördüğü için başka bayiye yönlendirilmiş talepleri bulamaz.
        </div>
    </div>
</div>

<script>
// Hesap değişimi — custom.js .form-select'i otomatik Select2 yaptığı için elle init YOK
$(function () {
    $('#irisHesapSec').on('change', function () {
        const id    = $(this).val();
        const $sec  = $(this);
        const onceki = $sec.data('onceki');

        $sec.prop('disabled', true);
        $.post('', { action: 'hesap_kaydet', anabayi_id: id }, null, 'json')
            .done(function (r) {
                if (r.success) {
                    showToast(r.message || 'Hesap güncellendi.', 'success');
                    $('#irisHesapKullanici').text(r.kullanici || '-');
                    $sec.data('onceki', id);
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(r.message || 'Hesap güncellenemedi.', 'error');
                    if (onceki !== undefined) $sec.val(onceki).trigger('change.select2');
                }
            })
            .fail(function () { showToast('Sunucu hatası.', 'error'); })
            .always(function () { $sec.prop('disabled', false); });
    }).data('onceki', $('#irisHesapSec').val());
});
</script>
