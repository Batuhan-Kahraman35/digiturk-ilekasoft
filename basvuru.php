<?php
/**
 * Dış Başvuru Formu — herkese açık (giriş gerektirmez).
 *
 * URL: https://proje.ornekyazilim.com/basvuru.php?p=<DigiturkAltBayiPersonel_Id>[&f=<ReklamLeadFormlari_id>]
 *   f isteğe bağlı: geçerli ve aktif lead form ise Basvurular.ReklamLeadFormlari_ID'ye yazılır, değilse NULL.
 *
 * Akış:
 *   1) Müşteri Ad / Soyad / Telefon girer → Basvurular'a yeni kayıt (AltBayiPersonel_ID = p).
 *   2) GSM daha önce onaylıysa OTP atlanır, kayıt direkt 'onayli' olur.
 *   3) Değilse Digiturk OTP (smsFormat=false) başlatılır, dönen onay ekranı modal/iframe'de açılır.
 *   4) Onay tamamlanınca Digiturk tarayıcıyı webhook/otp-redirect.php?bid=..'ye yönlendirir →
 *      kayıt 'onayli' olur, iframe parent'a postMessage atar. postMessage kaçarsa durum poll'u yakalar.
 *
 * Personel: p geçersiz / pasif ise kayıt yine alınır, AltBayiPersonel_ID NULL kalır (lead kaybolmasın).
 * Kötüye kullanım: CSRF + honeypot + oturum başına gönderim limiti + kara liste.
 */

require_once __DIR__ . '/admin/db.php';
require_once __DIR__ . '/admin/includes/BasvuruLogHelper.php';
require_once __DIR__ . '/admin/includes/EntegrasyonHelper.php';
require_once __DIR__ . '/admin/includes/KaraListeHelper.php';

const DIS_BASVURU_KULLANICI   = 0;   // OlusturanKullanici (dış kaynak — API ile aynı)
const DIS_BASVURU_LIMIT       = 5;   // oturum başına en fazla gönderim
const DIS_BASVURU_LIMIT_SURE  = 900; // limit penceresi (sn)
const DIS_BASVURU_TEKRAR_SURE = 300; // aynı GSM bu süre içinde tekrar gönderilirse yeni kayıt açılmaz (sn)

session_start();
$db = Database::getInstance();

if (empty($_SESSION['dis_basvuru_csrf'])) {
    $_SESSION['dis_basvuru_csrf'] = bin2hex(random_bytes(16));
}

/** Aktif personel ID'si; geçersizse null. */
function disBasvuruPersonel($db, $p): ?int
{
    $id = (int)$p;
    if ($id < 1) return null;
    $r = $db->fetchOne("SELECT DigiturkAltBayiPersonel_Id FROM DigiturkAltBayiPersonel WHERE DigiturkAltBayiPersonel_Id = ? AND Durum = 1", [$id]);
    return $r ? (int)$r['DigiturkAltBayiPersonel_Id'] : null;
}

/** Aktif lead form ID'si (isteğe bağlı f parametresi); geçersizse null. */
function disBasvuruLeadForm($db, $f): ?int
{
    $id = (int)$f;
    if ($id < 1) return null;
    $r = $db->fetchOne("SELECT ReklamLeadFormlari_id FROM ReklamLeadFormlari WHERE ReklamLeadFormlari_id = ? AND Durum = 1", [$id]);
    return $r ? (int)$r['ReklamLeadFormlari_id'] : null;
}

function disBasvuruJson(array $d): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Ad / soyad temizliği: fazla boşluk silinir, yalnız harf, boşluk, nokta, kesme, tire. */
function disBasvuruIsim(string $v): ?string
{
    $v = trim(preg_replace('/\s+/u', ' ', $v));
    if (mb_strlen($v) < 2 || mb_strlen($v) > 100) return null;
    return preg_match("/^[\p{L}\s.'-]+$/u", $v) ? $v : null;
}

/** OTP'yi başlatır / yeniden sorgular, Basvurular'ı günceller. */
function disBasvuruOtp($db, int $basvuruId, string $gsm, bool $yeniKayit): array
{
    $now = date('Y-m-d H:i:s');

    $kanal = $db->fetchOne("
        SELECT TOP 1 k.EntegrasyonKanallari_id
        FROM EntegrasyonKanallari k
        INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
        WHERE e.Entegrasyonlar_Tip = 'OTP' AND k.Durum = 1 AND e.Durum = 1
        ORDER BY k.EntegrasyonKanallari_id");
    $kanalId = (int)($kanal['EntegrasyonKanallari_id'] ?? 0);
    if (!$kanalId) return ['ok' => false, 'mesaj' => 'Doğrulama servisi şu an kullanılamıyor.'];

    // processType: Online (3)
    $kt = $db->fetchOne("
        SELECT TOP 1 t.EntegrasyonKanalTipleri_id, t.EntegrasyonKanalTipleri_Kod
        FROM EntegrasyonKanalTipleri t
        INNER JOIN Entegrasyonlar e ON t.EntegrasyonKanalTipleri_Entegrasyon_id = e.Entegrasyonlar_id
        WHERE e.Entegrasyonlar_Tip = 'OTP' AND t.EntegrasyonKanalTipleri_Kod = '3'");
    $kanalTipId = (int)($kt['EntegrasyonKanalTipleri_id'] ?? 0);
    $kod        = (string)($kt['EntegrasyonKanalTipleri_Kod'] ?? '3');

    // Sınırsız kural: GSM daha önce onaylıysa SMS atma
    $onceOnay = EntegrasyonHelper::gsmDahaOnceOnayli($gsm);
    if ($onceOnay) {
        $db->update('Basvurular', [
            'Basvurular_OtpKanal_id'       => $kanalId,
            'Basvurular_OtpKanalTipi_id'   => $kanalTipId,
            'Basvurular_OtpDurum'          => 'onayli',
            'Basvurular_OtpGonderimTarihi' => $now,
            'Basvurular_OtpOnayTarihi'     => $onceOnay['Basvurular_OtpOnayTarihi'] ?: $now,
            'Basvurular_OtpSonMesaj'       => 'Daha önce onaylı GSM — SMS gönderilmedi',
            'GuncellemeTarihi'             => $now,
            'GuncelleyenKullanici'         => DIS_BASVURU_KULLANICI,
        ], ['Basvurular_id' => $basvuruId]);
        return ['ok' => true, 'durum' => 'onayli', 'url' => null];
    }

    // Yeni kayıtta Add (SMS gider), tekrar denemede durum sorgusu (aktif kayıtta yeni SMS gitmez)
    $res = $yeniKayit
        ? EntegrasyonHelper::digiturkBasvuruGonder($kanalId, $gsm, $kod, [], false, DIS_BASVURU_KULLANICI, $basvuruId)
        : EntegrasyonHelper::digiturkDurumSorgula($kanalId, $gsm, $kod, DIS_BASVURU_KULLANICI, $basvuruId, false);

    if ($res['durum'] === 'hata') {
        $db->update('Basvurular', [
            'Basvurular_OtpSonMesaj' => mb_substr('Dış form: ' . $res['mesaj'], 0, 200),
            'GuncellemeTarihi'       => $now,
            'GuncelleyenKullanici'   => DIS_BASVURU_KULLANICI,
        ], ['Basvurular_id' => $basvuruId]);
        return ['ok' => false, 'mesaj' => !empty($res['engelli'])
            ? 'Bu numara ile işlem yapılamıyor.'
            : 'Doğrulama başlatılamadı. Lütfen biraz sonra tekrar deneyin.'];
    }

    // İstek sürerken redirect webhook'u kaydı onaylamış olabilir → 'onayli' asla geri düşmez
    $son   = $db->fetchOne("SELECT Basvurular_OtpDurum FROM Basvurular WHERE Basvurular_id = ?", [$basvuruId]);
    $durum = ($res['durum'] === 'onayli' || ($son['Basvurular_OtpDurum'] ?? '') === 'onayli') ? 'onayli' : 'beklemede';

    $upd = [
        'Basvurular_OtpKanal_id'     => $kanalId,
        'Basvurular_OtpKanalTipi_id' => $kanalTipId,
        'Basvurular_OtpDurum'        => $durum,
        'Basvurular_OtpSonMesaj'     => mb_substr((string)$res['mesaj'], 0, 200),
        'GuncellemeTarihi'           => $now,
        'GuncelleyenKullanici'       => DIS_BASVURU_KULLANICI,
    ];
    if ($yeniKayit) $upd['Basvurular_OtpGonderimTarihi'] = $now;
    if (!empty($res['onayTarihi'])) $upd['Basvurular_OtpOnayTarihi'] = $res['onayTarihi'];
    $db->update('Basvurular', $upd, ['Basvurular_id' => $basvuruId]);

    return ['ok' => true, 'durum' => $durum, 'url' => $durum === 'onayli' ? null : ($res['url'] ?? null)];
}

// =====================================================================
// AJAX
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!hash_equals($_SESSION['dis_basvuru_csrf'], (string)($_POST['csrf'] ?? ''))) {
        disBasvuruJson(['success' => false, 'message' => 'Oturum süresi doldu. Sayfayı yenileyip tekrar deneyin.']);
    }

    // ── Onay durumu (yalnız bu oturumda açılan kayıtlar) ──
    if ($action === 'durum') {
        $bid = (int)($_POST['bid'] ?? 0);
        if (!in_array($bid, array_column($_SESSION['dis_basvuru_kayitlar'] ?? [], 'bid'), true)) {
            disBasvuruJson(['success' => false, 'message' => 'Kayıt bulunamadı.']);
        }
        $r = $db->fetchOne("SELECT Basvurular_OtpDurum FROM Basvurular WHERE Basvurular_id = ?", [$bid]);
        disBasvuruJson(['success' => true, 'durum' => (string)($r['Basvurular_OtpDurum'] ?? '')]);
    }

    if ($action !== 'kaydet') {
        disBasvuruJson(['success' => false, 'message' => 'Geçersiz işlem.']);
    }

    // Honeypot: botlar gizli alanı doldurur → başarılı gibi görünüp kayıt açılmaz
    if (trim((string)($_POST['web_sitesi'] ?? '')) !== '') {
        disBasvuruJson(['success' => true, 'durum' => 'onayli', 'bid' => 0]);
    }

    // Oturum başına gönderim limiti
    $simdi = time();
    $_SESSION['dis_basvuru_denemeler'] = array_values(array_filter(
        $_SESSION['dis_basvuru_denemeler'] ?? [],
        fn($t) => $t > $simdi - DIS_BASVURU_LIMIT_SURE
    ));
    if (count($_SESSION['dis_basvuru_denemeler']) >= DIS_BASVURU_LIMIT) {
        disBasvuruJson(['success' => false, 'message' => 'Çok fazla deneme yapıldı. Lütfen birkaç dakika sonra tekrar deneyin.']);
    }

    $ad    = disBasvuruIsim((string)($_POST['ad'] ?? ''));
    $soyad = disBasvuruIsim((string)($_POST['soyad'] ?? ''));
    $gsm   = KaraListe::normalizeGsm((string)($_POST['telefon'] ?? ''));

    if ($ad === null)    disBasvuruJson(['success' => false, 'alan' => 'ad',      'message' => 'Lütfen geçerli bir isim girin.']);
    if ($soyad === null) disBasvuruJson(['success' => false, 'alan' => 'soyad',   'message' => 'Lütfen geçerli bir soyisim girin.']);
    if ($gsm === null || $gsm[2] !== '5') {
        disBasvuruJson(['success' => false, 'alan' => 'telefon', 'message' => 'Lütfen 5XX XXX XX XX formatında cep telefonu girin.']);
    }

    $personelId = disBasvuruPersonel($db, $_POST['p'] ?? 0);
    $leadFormId = disBasvuruLeadForm($db, $_POST['f'] ?? 0);

    try {
        // Aynı oturumda kısa süre önce aynı GSM ile açılmış kayıt varsa onu kullan (çift tık / tekrar dene)
        $mevcut = null;
        foreach ($_SESSION['dis_basvuru_kayitlar'] ?? [] as $k) {
            if ($k['gsm'] === $gsm && $k['zaman'] > $simdi - DIS_BASVURU_TEKRAR_SURE) $mevcut = $k;
        }

        if ($mevcut) {
            $basvuruId = (int)$mevcut['bid'];
            $yeniKayit = false;
        } else {
            $_SESSION['dis_basvuru_denemeler'][] = $simdi;

            $engel = KaraListe::kontrolVeLogla($gsm, 'dis-basvuru-formu', ['ad' => $ad, 'soyad' => $soyad, 'p' => $personelId, 'f' => $leadFormId], null, DIS_BASVURU_KULLANICI);
            if ($engel) disBasvuruJson(['success' => false, 'message' => 'Bu numara ile işlem yapılamıyor.']);

            $now  = date('Y-m-d H:i:s');
            $data = [
                'Isim'                 => $ad,
                'Soyisim'              => $soyad,
                'phoneCountryNumber'   => substr($gsm, 0, 2),
                'phoneAreaNumber'      => substr($gsm, 2, 3),
                'phoneNumber'          => substr($gsm, 5),
                'AltBayiPersonel_ID'   => $personelId,
                'ReklamLeadFormlari_ID' => $leadFormId,
                'Basvuru_Aciklama'     => 'Dış başvuru formu',
                'OlusturanKullanici'   => DIS_BASVURU_KULLANICI,
                'OlusturmaTarihi'      => $now,
                'GuncelleyenKullanici' => DIS_BASVURU_KULLANICI,
                'GuncellemeTarihi'     => $now,
            ];
            $basvuruId = (int)$db->insert('Basvurular', $data);
            if ($basvuruId < 1) disBasvuruJson(['success' => false, 'message' => 'Başvuru kaydedilemedi. Lütfen tekrar deneyin.']);

            basvuruLogKaydet($db, $basvuruId, 'EKLE', null, $data, DIS_BASVURU_KULLANICI,
                'Dış başvuru formundan eklendi (IP: ' . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '') . ')');

            $_SESSION['dis_basvuru_kayitlar'][] = ['bid' => $basvuruId, 'gsm' => $gsm, 'zaman' => $simdi];
            $yeniKayit = true;
        }

        $otp = disBasvuruOtp($db, $basvuruId, $gsm, $yeniKayit);
        if (!$otp['ok']) disBasvuruJson(['success' => false, 'message' => $otp['mesaj']]);

        disBasvuruJson(['success' => true, 'bid' => $basvuruId, 'durum' => $otp['durum'], 'url' => $otp['url']]);
    } catch (Throwable $e) {
        error_log('Dış başvuru formu hatası: ' . $e->getMessage());
        disBasvuruJson(['success' => false, 'message' => 'Beklenmeyen bir hata oluştu. Lütfen tekrar deneyin.']);
    }
}

// =====================================================================
// SAYFA
// =====================================================================
$ayar       = $db->fetchOne("SELECT TOP 1 site_ayarlari_favicon_url FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$personelId = disBasvuruPersonel($db, $_GET['p'] ?? 0);
$leadFormId = disBasvuruLeadForm($db, $_GET['f'] ?? 0);
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Başvuru Formu</title>
    <link rel="icon" href="<?= htmlspecialchars($ayar['site_ayarlari_favicon_url'] ?? '/favicon.ico') ?>">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="/admin/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <style>
        body { background: linear-gradient(160deg, #eef2f7 0%, #dfe7f1 100%); min-height: 100vh; }
        .basvuru-kutu { max-width: 440px; margin: 0 auto; padding: 48px 16px; }
        .basvuru-kutu .card { border: 0; border-radius: 16px; box-shadow: 0 10px 30px rgba(15, 23, 42, .08); }
        .basvuru-ikon { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; font-size: 30px; }
        .form-control-lg { font-size: 1rem; }
        .web-sitesi { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
        #otpFrame { width: 100%; height: 70vh; height: 70dvh; border: 0; display: none; }
        #otpModal .modal-body { overflow: hidden; }
        .otp-yeni-sekme-ikon { display: none; }

        /* Mobil: modal tam ekran; footer kalkar, iframe kalan alanın tamamını doldurur */
        @media (max-width: 575.98px) {
            .basvuru-kutu { padding-block: 24px; }
            #otpModal .modal-header { padding: .5rem .75rem; }
            #otpModal .modal-title { font-size: 1rem; }
            #otpModal .modal-footer { display: none; }
            #otpModal .modal-body { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; }
            #otpModal .otp-alan { position: relative; flex: 1 1 auto; min-height: 0; overflow: hidden; }
            #otpModal #otpFrame { position: absolute; top: 0; left: 0; height: 100%; transform-origin: 0 0; }
            .otp-yeni-sekme-ikon { display: inline-flex; }
        }
    </style>
</head>
<body>
<div class="basvuru-kutu">
    <div class="card">
        <div class="card-body p-4">

            <!-- FORM -->
            <div id="adimForm">
                <div class="text-center mb-4">
                    <div class="basvuru-ikon bg-primary-subtle text-primary"><i class="bi bi-person-lines-fill"></i></div>
                    <h1 class="h4 mb-1">Başvuru Formu</h1>
                    <p class="text-muted mb-0">Bilgilerinizi bırakın, sizi arayalım.</p>
                </div>

                <div id="formUyari" class="alert alert-danger py-2 d-none" role="alert"></div>

                <form id="basvuruForm" novalidate autocomplete="on">
                    <input type="hidden" name="action" value="kaydet">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['dis_basvuru_csrf']) ?>">
                    <input type="hidden" name="p" value="<?= (int)$personelId ?>">
                    <input type="hidden" name="f" value="<?= (int)$leadFormId ?>">
                    <div class="web-sitesi" aria-hidden="true">
                        <label for="web_sitesi">Web sitesi</label>
                        <input type="text" id="web_sitesi" name="web_sitesi" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="mb-3">
                        <label for="ad" class="form-label">İsim</label>
                        <input type="text" class="form-control form-control-lg" id="ad" name="ad" maxlength="100" autocomplete="given-name" required>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="soyad" class="form-label">Soyisim</label>
                        <input type="text" class="form-control form-control-lg" id="soyad" name="soyad" maxlength="100" autocomplete="family-name" required>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="mb-4">
                        <label for="telefon" class="form-label">Cep Telefonu</label>
                        <div class="input-group input-group-lg has-validation">
                            <span class="input-group-text">+90</span>
                            <input type="tel" class="form-control" id="telefon" name="telefon" inputmode="numeric" placeholder="5XX XXX XX XX" maxlength="13" autocomplete="tel-national" required>
                            <div class="invalid-feedback"></div>
                        </div>
                        <div class="form-text">Numaranızı doğrulamak için SMS gönderilecektir.</div>
                    </div>

                    <button type="submit" id="btnKaydet" class="btn btn-primary btn-lg w-100">
                        <span class="btn-yazi"><i class="bi bi-send me-1"></i> Kaydet</span>
                        <span class="btn-yukleniyor d-none"><span class="spinner-border spinner-border-sm me-1"></span> Gönderiliyor...</span>
                    </button>
                </form>
            </div>

            <!-- SMS BEKLENİYOR (onay ekranı URL'i dönmezse) -->
            <div id="adimBekle" class="text-center d-none">
                <div class="basvuru-ikon bg-warning-subtle text-warning"><i class="bi bi-phone"></i></div>
                <h2 class="h5">Telefonunuzu kontrol edin</h2>
                <p class="text-muted">Size gönderilen SMS'teki bağlantıdan doğrulamayı tamamlayın. Onaylandığında bu ekran otomatik güncellenecek.</p>
                <div class="spinner-border text-warning" role="status"></div>
                <div class="mt-3"><button type="button" class="btn btn-link btn-otp-ac d-none">Doğrulama ekranını aç</button></div>
            </div>

            <!-- BAŞARILI -->
            <div id="adimTamam" class="text-center d-none">
                <div class="basvuru-ikon bg-success-subtle text-success"><i class="bi bi-check-lg"></i></div>
                <h2 class="h5 text-success">Başvurunuz alındı</h2>
                <p class="text-muted mb-0">Telefon numaranız doğrulandı. En kısa sürede sizinle iletişime geçeceğiz.</p>
            </div>

        </div>
    </div>
</div>

<!-- OTP ONAY MODALI -->
<div class="modal fade" id="otpModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="otpModalBaslik" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title me-auto" id="otpModalBaslik"><i class="bi bi-shield-check me-1"></i> Telefon Doğrulama</h5>
                <a href="#" target="_blank" rel="noopener" class="btn btn-sm btn-link text-secondary otp-yeni-sekme-ikon me-1" title="Yeni sekmede aç" aria-label="Yeni sekmede aç"><i class="bi bi-box-arrow-up-right"></i></a>
                <button type="button" class="btn-close ms-0" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body p-0">
                <div id="otpYukleniyor" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-1"></span> Doğrulama ekranı yükleniyor...</div>
                <div class="otp-alan"><iframe id="otpFrame" src="" title="Telefon doğrulama"></iframe></div>
            </div>
            <div class="modal-footer">
                <small class="text-muted me-auto">Telefonunuza gelen doğrulama kodunu bu ekrana girin.</small>
                <a id="otpYeniSekme" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i> Yeni sekmede aç</a>
            </div>
        </div>
    </div>
</div>

<script src="/admin/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const form     = document.getElementById('basvuruForm');
    const btn      = document.getElementById('btnKaydet');
    const uyari    = document.getElementById('formUyari');
    const frame    = document.getElementById('otpFrame');
    const modalEl  = document.getElementById('otpModal');
    const otpModal = new bootstrap.Modal(modalEl);
    const csrf     = form.querySelector('[name=csrf]').value;

    let aktifBid = 0, otpUrl = null, pollTimer = null;

    // Telefon: yalnız rakam, baştaki 0 / 90 atılır, 5XX XXX XX XX biçimlenir
    const tel = document.getElementById('telefon');
    tel.addEventListener('input', function () {
        let s = this.value.replace(/\D/g, '');
        if (s.startsWith('90') && s.length > 10) s = s.slice(2);
        if (s.startsWith('0')) s = s.slice(1);
        s = s.slice(0, 10);
        this.value = [s.slice(0, 3), s.slice(3, 6), s.slice(6, 8), s.slice(8, 10)].filter(Boolean).join(' ');
    });

    function adim(ad) {
        ['adimForm', 'adimBekle', 'adimTamam'].forEach(id =>
            document.getElementById(id).classList.toggle('d-none', id !== ad));
    }

    function yukleniyor(acik) {
        btn.disabled = acik;
        btn.querySelector('.btn-yazi').classList.toggle('d-none', acik);
        btn.querySelector('.btn-yukleniyor').classList.toggle('d-none', !acik);
    }

    function alanHata(alan, mesaj) {
        const input = document.getElementById(alan);
        input.classList.add('is-invalid');
        input.parentElement.querySelector('.invalid-feedback').textContent = mesaj;
        input.focus();
    }

    function temizle() {
        uyari.classList.add('d-none');
        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    }

    function istemciKontrol() {
        const harf = /^[\p{L}\s.'-]{2,100}$/u;
        if (!harf.test(form.ad.value.trim()))    { alanHata('ad', 'Lütfen geçerli bir isim girin.'); return false; }
        if (!harf.test(form.soyad.value.trim())) { alanHata('soyad', 'Lütfen geçerli bir soyisim girin.'); return false; }
        if (!/^5\d{9}$/.test(tel.value.replace(/\D/g, ''))) { alanHata('telefon', 'Lütfen 5XX XXX XX XX formatında girin.'); return false; }
        return true;
    }

    async function post(veri) {
        const r = await fetch(location.pathname, { method: 'POST', body: veri, credentials: 'same-origin' });
        return r.json();
    }

    function tamamlandi() {
        clearInterval(pollTimer);
        try { otpModal.hide(); } catch (_) {}
        adim('adimTamam');
    }

    function otpAc() {
        if (!otpUrl) return;
        frame.style.display = 'none';
        document.getElementById('otpYukleniyor').style.display = '';
        frame.src = otpUrl;
        document.getElementById('otpYeniSekme').href = otpUrl;
        document.querySelector('.otp-yeni-sekme-ikon').href = otpUrl;
        otpModal.show();
    }

    // Digiturk ekranı (logo → mor onay butonu) ~OTP_ICERIK_YUKSEKLIK px tutuyor.
    // Mobilde alan bundan kısaysa iframe'i orantılı küçültüp kaydırmadan sığdırır.
    const OTP_ICERIK_YUKSEKLIK = 640;
    const OTP_MIN_OLCEK        = 0.72;
    function otpOlcekle() {
        const alan = frame.parentElement;
        if (!window.matchMedia('(max-width: 575.98px)').matches) {
            frame.style.transform = ''; frame.style.width = ''; frame.style.height = '';
            return;
        }
        const h = alan.clientHeight, w = alan.clientWidth;
        if (!h || !w) return;
        const olcek = Math.max(OTP_MIN_OLCEK, Math.min(1, h / OTP_ICERIK_YUKSEKLIK));
        frame.style.transform = olcek < 1 ? 'scale(' + olcek + ')' : '';
        frame.style.width     = (w / olcek) + 'px';
        frame.style.height    = (h / olcek) + 'px';
    }
    modalEl.addEventListener('shown.bs.modal', otpOlcekle);
    window.addEventListener('resize', otpOlcekle);
    window.addEventListener('orientationchange', function () { setTimeout(otpOlcekle, 250); });

    // postMessage kaçarsa (yeni sekmede onay, SMS linki vb.) durumu periyodik kontrol et
    function pollBaslat() {
        clearInterval(pollTimer);
        pollTimer = setInterval(async function () {
            const fd = new FormData();
            fd.append('action', 'durum'); fd.append('csrf', csrf); fd.append('bid', aktifBid);
            try { const r = await post(fd); if (r.success && r.durum === 'onayli') tamamlandi(); } catch (_) {}
        }, 4000);
    }

    frame.addEventListener('load', function () {
        if (this.getAttribute('src')) {
            document.getElementById('otpYukleniyor').style.display = 'none';
            this.style.display = 'block';
            otpOlcekle();
        }
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        frame.style.display = 'none';
        frame.src = '';
        // Onay tamamlanmadan kapatıldıysa bekleme ekranında kal, tekrar açılabilsin
        if (document.getElementById('adimTamam').classList.contains('d-none')) {
            document.querySelector('.btn-otp-ac').classList.toggle('d-none', !otpUrl);
            adim('adimBekle');
        }
    });

    document.querySelector('.btn-otp-ac').addEventListener('click', otpAc);

    // webhook/otp-redirect.php onay sonrası parent'a mesaj atar
    window.addEventListener('message', function (e) {
        if (e.data && e.data.otp === 'onayli' && Number(e.data.bid) === aktifBid) tamamlandi();
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        temizle();
        if (!istemciKontrol()) return;

        yukleniyor(true);
        try {
            const r = await post(new FormData(form));
            if (!r.success) {
                if (r.alan) alanHata(r.alan, r.message);
                else { uyari.textContent = r.message; uyari.classList.remove('d-none'); }
                return;
            }
            aktifBid = Number(r.bid);
            if (r.durum === 'onayli') { tamamlandi(); return; }

            otpUrl = r.url || null;
            adim('adimBekle');
            pollBaslat();
            if (otpUrl) otpAc();
        } catch (_) {
            uyari.textContent = 'Sunucuya ulaşılamıyor. Lütfen tekrar deneyin.';
            uyari.classList.remove('d-none');
        } finally {
            yukleniyor(false);
        }
    });
})();
</script>
</body>
</html>
