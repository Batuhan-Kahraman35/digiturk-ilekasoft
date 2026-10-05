<?php
// $db değişkeni üst sayfadan gelmeli
$_apiPersonel = $db->fetchOne("
    SELECT
        p.DigiturkAltBayiPersonel_AdSoyad,
        p.DigiturkAltBayiPersonel_KullaniciAdi,
        CONVERT(VARCHAR(16), p.DigiturkAltBayiPersonel_TokenSuresi, 120) AS TokenSuresi,
        a.DigiturkAltBayiler_Ad AS AltBayiAdi
    FROM DigiturkAltBayiPersonel p
    LEFT JOIN DigiturkAltBayiler a ON p.DigiturkAltBayiPersonel_AltBayiId = a.DigiturkAltBayiler_Id
    WHERE p.DigiturkAltBayiPersonel_Id = 1
");
?>
<div class="card card-outline card-info mb-3">
    <div class="card-header py-2">
        <h6 class="card-title mb-0"><i class="bi bi-person-badge"></i> API Güncellemesinde Kullanılan Personel</h6>
    </div>
    <div class="card-body py-2">
        <?php if ($_apiPersonel): ?>
        <div class="d-flex flex-wrap gap-4 align-items-center">
            <div><span class="text-muted small">Ad Soyad</span><br><strong><?= htmlspecialchars($_apiPersonel['DigiturkAltBayiPersonel_AdSoyad'] ?? '-') ?></strong></div>
            <div><span class="text-muted small">Kullanıcı Adı</span><br><code><?= htmlspecialchars($_apiPersonel['DigiturkAltBayiPersonel_KullaniciAdi'] ?? '-') ?></code></div>
            <div><span class="text-muted small">Alt Bayi</span><br><span><?= htmlspecialchars($_apiPersonel['AltBayiAdi'] ?? '-') ?></span></div>
            <div><span class="text-muted small">Token Süresi</span><br><span class="badge bg-info text-dark"><?= htmlspecialchars($_apiPersonel['TokenSuresi'] ?? '-') ?></span></div>
        </div>
        <?php else: ?>
        <span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Aktif tokenli personel bulunamadı! API güncellemesi çalışmaz.</span>
        <?php endif; ?>
    </div>
</div>
