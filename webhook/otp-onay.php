<?php
/**
 * Digiturk OTP Onay Callback Alıcısı (responseUrl)
 *
 * Gerçek dosya olduğu için web.config rewrite'ına takılmaz (api/ gateway'i bypass).
 * URL: https://proje.ornekyazilim.com/webhook/otp-onay.php?token=XXX
 *
 * Digiturk onay tamamlanınca bu adrese POST eder:
 *   {"gsm":"905XXXXXXXXX","status":"true"}
 *
 * Akış:
 *   - Token doğrulanır (DB'deki Entegrasyonlar_CallbackURL query'sindeki token ile eşleşmeli).
 *   - status=true ise, o GSM'e ait 'beklemede' + son 7 gün Başvurular 'onayli' yapılır.
 *   - Her durumda 200 döner (Digiturk gereksiz retry yapmasın); işlem logs/otp-callback.log'a yazılır.
 *
 * Config: Entegrasyonlar (Tip='OTP', Durum=1) → Entegrasyonlar_CallbackURL (token buradan okunur).
 */

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/includes/KaraListeHelper.php';

const OTP_SISTEM_KULLANICI = 1; // GuncelleyenKullanici (sistem)

function otpCbLog(string $m): void
{
    @file_put_contents(__DIR__ . '/../logs/otp-callback.log', date('Y-m-d H:i:s') . ' ' . $m . PHP_EOL, FILE_APPEND);
}

function otpCbCik(int $http, bool $ok, string $msg): void
{
    http_response_code($http);
    header('Content-Type: application/json');
    echo json_encode(['status' => $ok, 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    otpCbCik(405, false, 'method not allowed');
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw ?? '', true);
if (!is_array($body)) $body = $_POST;

$db = Database::getInstance();

// ── Token doğrulama (DB'deki CallbackURL query token'ı ile) ──────────────────
$cfg   = $db->fetchOne("SELECT Entegrasyonlar_CallbackURL FROM Entegrasyonlar WHERE Entegrasyonlar_Tip = 'OTP' AND Durum = 1");
$cbUrl = (string)($cfg['Entegrasyonlar_CallbackURL'] ?? '');
parse_str((string)parse_url($cbUrl, PHP_URL_QUERY), $q);
$beklenen = (string)($q['token'] ?? '');
$gelen    = (string)($_GET['token'] ?? '');

$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if ($beklenen === '' || !hash_equals($beklenen, $gelen)) {
    otpCbLog("RED token (ip=$ip) raw=" . $raw);
    otpCbCik(403, false, 'invalid token');
}

// ── Girdi ────────────────────────────────────────────────────────────────────
$gsm    = preg_replace('/\D/', '', (string)($body['gsm'] ?? ''));
$status = strtolower(trim((string)($body['status'] ?? '')));

if ($status !== 'true' && $status !== '1') {
    otpCbLog("ATLA status!=true (ip=$ip) raw=" . $raw);
    otpCbCik(200, true, 'ignored (status not true)');
}
if (strlen($gsm) < 10) {
    otpCbLog("HATA gsm gecersiz (ip=$ip) raw=" . $raw);
    otpCbCik(400, false, 'gsm invalid');
}

// ── Kara liste ───────────────────────────────────────────────────────────────
// SMS gönderildikten SONRA kara listeye girmiş olabilir; onayı işleme almayız.
$engel = KaraListe::kontrolVeLogla($gsm, 'webhook:otp-onay', ['status' => $status], null, OTP_SISTEM_KULLANICI);
if ($engel) {
    otpCbLog("KARA LISTE engel gsm=$gsm (ip=$ip)");
    otpCbCik(200, true, 'ignored (blacklisted)');
}

// ── Eşleşen 'beklemede' başvuruları onayla ───────────────────────────────────
$adaylar = $db->fetchAll("
    SELECT Basvurular_id, phoneCountryNumber, phoneAreaNumber, phoneNumber
    FROM Basvurular
    WHERE Basvurular_OtpDurum = 'beklemede'
      AND Basvurular_OtpGonderimTarihi >= DATEADD(day, -7, GETDATE())
");

$now  = date('Y-m-d H:i:s');
$sayi = 0;
foreach ($adaylar as $a) {
    $kayitGsm = preg_replace('/\D/', '', ($a['phoneCountryNumber'] ?? '') . ($a['phoneAreaNumber'] ?? '') . ($a['phoneNumber'] ?? ''));
    if ($kayitGsm === $gsm) {
        $db->update('Basvurular', [
            'Basvurular_OtpDurum'      => 'onayli',
            'Basvurular_OtpOnayTarihi' => $now,
            'Basvurular_OtpSonMesaj'   => 'Callback ile onaylandı (responseUrl)',
            'GuncellemeTarihi'         => $now,
            'GuncelleyenKullanici'     => OTP_SISTEM_KULLANICI,
        ], ['Basvurular_id' => $a['Basvurular_id']]);
        $sayi++;
    }
}

otpCbLog("OK gsm=$gsm guncellenen=$sayi (ip=$ip)");
otpCbCik(200, true, "updated=$sayi");
