<?php
/**
 * Digiturk OTP Onay Yönlendirme Alıcısı (redirectUrl)
 *
 * Gerçek dosya olduğu için web.config rewrite'ına takılmaz.
 * URL: https://proje.ornekyazilim.com/webhook/otp-redirect.php?token=XXX&bid=<Basvurular_id>
 *
 * Digiturk onay tamamlanınca müşterinin TARAYICISINI bu adrese yönlendirir (GET).
 * responseUrl (sunucu POST) gelmiyor; onay yakalamanın çalışan yolu budur.
 * `bid`'i biz redirectUrl'e ekliyoruz (Digiturk gsm/status eklemiyor, query'yi korur).
 *
 * Akış: token doğrula → bid'in 'beklemede' kaydını 'onayli' yap → müşteriye onay sayfası
 *       + iframe ise parent'a postMessage (liste anlık güncellensin).
 *
 * Config: Entegrasyonlar (Tip='OTP', Durum=1) → Entegrasyonlar_RedirectURL (token buradan okunur).
 */

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/includes/KaraListeHelper.php';

const OTP_REDIRECT_SISTEM_KULLANICI = 1;

function otpRdrLog(string $m): void
{
    @file_put_contents(__DIR__ . '/../logs/otp-callback.log', date('Y-m-d H:i:s') . ' [redirect] ' . $m . PHP_EOL, FILE_APPEND);
}

function otpRdrSayfa(string $baslik, string $mesaj, bool $ok, int $bid = 0): void
{
    $renk = $ok ? '#16a34a' : '#dc2626';
    $ikon = $ok ? '✅' : '⚠️';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($baslik) . '</title>'
       . '<style>body{font-family:system-ui,Segoe UI,Arial;display:flex;min-height:100vh;margin:0;align-items:center;justify-content:center;background:#f8fafc;color:#1f2937}'
       . '.box{max-width:420px;text-align:center;background:#fff;padding:36px 28px;border-radius:14px;box-shadow:0 6px 24px rgba(0,0,0,.08)}'
       . 'h1{font-size:20px;margin:12px 0 8px;color:' . $renk . '}p{color:#475569;font-size:15px;line-height:1.5}.i{font-size:52px}</style></head>'
       . '<body><div class="box"><div class="i">' . $ikon . '</div><h1>' . htmlspecialchars($baslik) . '</h1><p>' . htmlspecialchars($mesaj) . '</p></div>'
       . '<script>try{parent.postMessage({otp:"' . ($ok ? 'onayli' : 'hata') . '",bid:' . (int)$bid . '},"*")}catch(e){}</script>'
       . '</body></html>';
    exit;
}

$db  = Database::getInstance();
$bid = (int)($_GET['bid'] ?? 0);
$ip  = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

// ── Token doğrulama (DB'deki RedirectURL query token'ı ile) ──────────────────
$cfg = $db->fetchOne("SELECT Entegrasyonlar_RedirectURL FROM Entegrasyonlar WHERE Entegrasyonlar_Tip = 'OTP' AND Durum = 1");
parse_str((string)parse_url((string)($cfg['Entegrasyonlar_RedirectURL'] ?? ''), PHP_URL_QUERY), $q);
$beklenen = (string)($q['token'] ?? '');
$gelen    = (string)($_GET['token'] ?? '');

if ($beklenen === '' || !hash_equals($beklenen, $gelen)) {
    otpRdrLog("RED token bid=$bid ip=$ip");
    http_response_code(403);
    otpRdrSayfa('Geçersiz bağlantı', 'Doğrulama bağlantısı geçersiz veya süresi dolmuş.', false, $bid);
}

if ($bid < 1) {
    otpRdrLog("bid yok ip=$ip");
    otpRdrSayfa('Eksik bilgi', 'Başvuru bilgisi bulunamadı.', false, 0);
}

// ── Kaydı onayla ─────────────────────────────────────────────────────────────
// Durum filtresi YOK: OTP onayı bid bazlı gelir, kaydın aktif/pasif olması onayı engellemez
$b = $db->fetchOne("
    SELECT Basvurular_id, Basvurular_OtpDurum, phoneCountryNumber, phoneAreaNumber, phoneNumber
    FROM Basvurular WHERE Basvurular_id = ?", [$bid]);
if (!$b) {
    otpRdrLog("bid=$bid kayit yok ip=$ip");
    otpRdrSayfa('Bulunamadı', 'Başvuru kaydı bulunamadı.', false, $bid);
}

// ── Kara liste ───────────────────────────────────────────────────────────────
// SMS gönderildikten SONRA kara listeye girmiş olabilir; onayı işleme almayız.
$engel = KaraListe::kontrolVeLogla(
    KaraListe::kayittanGsm($b), 'webhook:otp-redirect', null, $bid, OTP_REDIRECT_SISTEM_KULLANICI
);
if ($engel) {
    otpRdrLog("KARA LISTE engel bid=$bid ip=$ip");
    otpRdrSayfa('İşlem yapılamıyor', 'Bu numara ile işlem yapılamaz.', false, $bid);
}

$now = date('Y-m-d H:i:s');
if (($b['Basvurular_OtpDurum'] ?? '') !== 'onayli') {
    $db->update('Basvurular', [
        'Basvurular_OtpDurum'      => 'onayli',
        'Basvurular_OtpOnayTarihi' => $now,
        'Basvurular_OtpSonMesaj'   => 'redirectUrl ile onaylandı',
        'GuncellemeTarihi'         => $now,
        'GuncelleyenKullanici'     => OTP_REDIRECT_SISTEM_KULLANICI,
    ], ['Basvurular_id' => $bid]);
    otpRdrLog("OK bid=$bid onayli ip=$ip");
} else {
    otpRdrLog("bid=$bid zaten onayli ip=$ip");
}

otpRdrSayfa('Onayınız alındı', 'Doğrulama işleminiz başarıyla tamamlandı. Bu pencereyi kapatabilirsiniz.', true, $bid);
