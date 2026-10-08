<?php
/**
 * tasks.php - Cron görev fonksiyonları (worker.php, runner.php ve cron-yonetimi.php tarafından paylaşılır)
 */

defined('IRIS_BASE_URL')      || define('IRIS_BASE_URL',      'https://iris.digiturk.com.tr/api');
defined('IRIS_REPORT_SPEC')   || define('IRIS_REPORT_SPEC',   10);
defined('IRIS_MAX_DAYS')      || define('IRIS_MAX_DAYS',      30);
defined('IRIS_POLL_INTERVAL') || define('IRIS_POLL_INTERVAL', 10);
defined('IRIS_POLL_TIMEOUT')  || define('IRIS_POLL_TIMEOUT',  300);
// Rapor içeriği indirme süresi. 30 günlük parça 2,5 MB'ı aştığı için 60 sn yetmiyordu.
defined('IRIS_HTTP_TIMEOUT')  || define('IRIS_HTTP_TIMEOUT',  300);
defined('IRIS_PERSONEL_ID')   || define('IRIS_PERSONEL_ID',   1);
// iris_talep_eslestir: art arda bu kadar IRIS hatasında tur kesilir
defined('IRIS_ARDISIK_HATA_LIMIT') || define('IRIS_ARDISIK_HATA_LIMIT', 5);

// VoIP tedarikçi (operator_bekleniyor) hatırlatma aralığı (saat). Muhasebe hatırlatması
// kural bazlı VoIPBildirimKurallari_TekrarSaati kullanır; tedarikçi bundan bağımsızdır.
defined('VOIP_TEDARIKCI_TEKRAR_SAATI') || define('VOIP_TEDARIKCI_TEKRAR_SAATI', 3);

// VoIP tedarikçi mesaj gönderim saat aralığı (09:00–18:59). Genel mesai (09–18) yerine
// tedarikçi bildirimleri için ayrı pencere; bu aralık dışında gönderim bekletilir.
defined('VOIP_TEDARIKCI_MESAI_BAS')    || define('VOIP_TEDARIKCI_MESAI_BAS',    9);
defined('VOIP_TEDARIKCI_MESAI_BITIS')  || define('VOIP_TEDARIKCI_MESAI_BITIS',  19);

// ─── Dinamik tarih ────────────────────────────────────────────────────────────
function dinamikTarih(string $deger): string
{
    $bugun = new DateTime();
    $map = [
        '{bugun}'         => fn() => $bugun->format('d.m.Y'),
        '{dun}'           => fn() => (clone $bugun)->modify('-1 day')->format('d.m.Y'),
        '{7gun_once}'     => fn() => (clone $bugun)->modify('-7 days')->format('d.m.Y'),
        '{30gun_once}'    => fn() => (clone $bugun)->modify('-30 days')->format('d.m.Y'),
        '{ay_basi}'       => fn() => $bugun->format('01.m.Y'),
        '{ay_sonu}'       => fn() => (new DateTime('last day of this month'))->format('d.m.Y'),
        '{gecen_ay_basi}' => fn() => (new DateTime('first day of last month'))->format('d.m.Y'),
        '{gecen_ay_sonu}' => fn() => (new DateTime('last day of last month'))->format('d.m.Y'),
        '{3ay_once}'      => fn() => (clone $bugun)->modify('-3 months')->format('d.m.Y'),
        '{6ay_once}'      => fn() => (clone $bugun)->modify('-6 months')->format('d.m.Y'),
    ];
    return isset($map[$deger]) ? ($map[$deger])() : $deger;
}

function dinamikParamCoz(array $params): array
{
    return array_map(fn($v) => is_string($v) ? dinamikTarih($v) : $v, $params);
}

// ─── Cron ifade eşleştirici ───────────────────────────────────────────────────
function cronEslesiyor(string $ifade, DateTime $dt): bool
{
    $parts = preg_split('/\s+/', trim($ifade));
    if (count($parts) !== 5) return false;
    [$dk, $sa, $gun, $ay, $hgn] = $parts;
    return cronAlanEslesiyor($dk,  (int)$dt->format('i'))
        && cronAlanEslesiyor($sa,  (int)$dt->format('G'))
        && cronAlanEslesiyor($gun, (int)$dt->format('j'))
        && cronAlanEslesiyor($ay,  (int)$dt->format('n'))
        && cronAlanEslesiyor($hgn, (int)$dt->format('w'));
}

function cronAlanEslesiyor(string $alan, int $deger): bool
{
    if ($alan === '*') return true;
    foreach (explode(',', $alan) as $parca) {
        if (str_contains($parca, '/')) {
            [$aralik, $adim] = explode('/', $parca, 2);
            [$bas, $son] = $aralik === '*' ? [0, 59] : array_map('intval', explode('-', $aralik));
            if ($deger >= $bas && $deger <= $son && ($deger - $bas) % (int)$adim === 0) return true;
        } elseif (str_contains($parca, '-')) {
            [$bas, $son] = array_map('intval', explode('-', $parca));
            if ($deger >= $bas && $deger <= $son) return true;
        } elseif ((int)$parca === $deger) {
            return true;
        }
    }
    return false;
}

// ─── Log yardımcıları ─────────────────────────────────────────────────────────
function cronLogOlustur($db, int $gorevId, ?int $zamanlamaId, array $params, int $tetikTur, ?int $kullanici = null): int
{
    $simdi = date('Y-m-d H:i:s');
    return (int)$db->insert('CronCalismaLog', [
        'CronCalismaLog_GorevId'             => $gorevId,
        'CronCalismaLog_ZamanlamaId'         => $zamanlamaId,
        'CronCalismaLog_BaslangicTarihi'     => $simdi,
        'CronCalismaLog_Parametreler'        => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
        'CronCalismaLog_CalismaDurum'        => 0,
        'CronCalismaLog_TetikleyenTur'       => $tetikTur,
        'CronCalismaLog_TetikleyenKullanici' => $kullanici,
        'OlusturanKullanici'                 => 0,
        'OlusturmaTarihi'                    => $simdi,
        'GuncelleyenKullanici'               => 0,
        'GuncellemeTarihi'                   => $simdi,
        'Durum'                              => 1,
    ]);
}

function cronLogBitir($db, int $logId, int $durum, string $sonuc, float $basZaman): void
{
    $simdi = date('Y-m-d H:i:s');
    $db->update('CronCalismaLog', [
        'CronCalismaLog_BitisTarihi'  => $simdi,
        'CronCalismaLog_SureSaniye'   => (int)(microtime(true) - $basZaman),
        'CronCalismaLog_CalismaDurum' => $durum,
        'CronCalismaLog_Sonuc'        => mb_substr($sonuc, 0, 2000),
        'GuncelleyenKullanici'        => 0,
        'GuncellemeTarihi'            => $simdi,
    ], ['CronCalismaLog_Id' => $logId]);
}

// ─── IRIS yardımcıları ────────────────────────────────────────────────────────
function irisPost($ch, string $endpoint, $body, ?string &$webToken, ?string $verToken): array
{
    $headers = [
        'Content-Type: application/json', 'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'Referer: https://iris.digiturk.com.tr/', 'Origin: https://iris.digiturk.com.tr',
    ];
    if ($webToken) $headers[] = 'IrisWebToken: ' . $webToken;
    if ($verToken) $headers[] = 'IrisVerificationToken: ' . $verToken;
    curl_setopt($ch, CURLOPT_URL, IRIS_BASE_URL . '/' . $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch))  throw new RuntimeException('cURL: ' . curl_error($ch));
    if ($httpCode >= 400) {
        // Yanıt gövdesi olmadan "HTTP 500" teşhis edilemiyor; servisin gerekçesini mesaja taşı.
        $j       = json_decode($response, true);
        $gerekce = is_array($j)
            ? ($j['ResponseMessage'] ?? $j['responseMessage'] ?? $j['Message'] ?? $j['message'] ?? json_encode($j, JSON_UNESCAPED_UNICODE))
            : trim(strip_tags((string)$response));
        $gerekce = mb_substr(preg_replace('/\s+/u', ' ', (string)$gerekce), 0, 500);
        throw new RuntimeException("HTTP {$httpCode} [{$endpoint}]" . ($gerekce !== '' ? ' — ' . $gerekce : ''));
    }
    $json = json_decode($response, true);
    if ($json === null) throw new RuntimeException("JSON parse [{$endpoint}]: " . json_last_error_msg());
    return $json;
}

function irisDateChunks(string $start, string $end, int $maxDays): array
{
    $s = DateTime::createFromFormat('d.m.Y', $start);
    $e = DateTime::createFromFormat('d.m.Y', $end);
    if (!$s || !$e) throw new RuntimeException('Geçersiz tarih formatı (dd.mm.yyyy)');
    $chunks = [];
    $cur = clone $s;
    while ($cur <= $e) {
        $ce = clone $cur;
        $ce->modify('+' . ($maxDays - 1) . ' days');
        if ($ce > $e) $ce = clone $e;
        $chunks[] = ['start' => $cur->format('d.m.Y'), 'end' => $ce->format('d.m.Y')];
        $cur = clone $ce;
        $cur->modify('+1 day');
    }
    return $chunks;
}

function irisParseDateSql(?string $v): ?string
{
    $v = trim($v ?? '');
    if ($v === '') return null;
    foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y'] as $f) {
        $dt = DateTime::createFromFormat($f, $v);
        if ($dt) return $dt->format('Y-m-d H:i:s');
    }
    return null;
}

function irisImportCsv(string $csvFile, int $personelId, string $dosyaAdi, $db): array
{
    $stats   = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
    $kolonlar = [
        0  => ['IrisRapor_TalepId',                     'bigint'],
        1  => ['IrisRapor_TalepTuru',                    'string'],
        2  => ['IrisRapor_UyduBasvuruPotansiyelNo',      'string'],
        3  => ['IrisRapor_UyduBasvuruUyeNo',             'string'],
        4  => ['IrisRapor_DtMusteriNo',                  'bigint'],
        6  => ['IrisRapor_MemoKayitTipi',                'string'],
        7  => ['IrisRapor_MemoIdTip',                    'string'],
        8  => ['IrisRapor_MemoKodu',                     'string'],
        9  => ['IrisRapor_MemoKapanisTarihi',             'datetime'],
        10 => ['IrisRapor_MemoYonlenenBayiKodu',          'string'],
        11 => ['IrisRapor_MemoYonlenenBayiAdi',           'string'],
        12 => ['IrisRapor_MemoYonlenenBayiYoneticisi',    'string'],
        13 => ['IrisRapor_MemoYonlenenBayiBolge',         'string'],
        14 => ['IrisRapor_MemoYonlenenBayiTeknikYntc',    'string'],
        15 => ['IrisRapor_TalepGirisTarihi',              'datetime'],
        16 => ['IrisRapor_TalebiGirenBayiKodu',           'string'],
        17 => ['IrisRapor_TalebiGirenBayiAdi',            'string'],
        18 => ['IrisRapor_TalebiGirenPersonel',           'string'],
        19 => ['IrisRapor_TalebiGirenPersonelNo',         'string'],
        20 => ['IrisRapor_TalebiGirenPersonelKodu',       'string'],
        21 => ['IrisRapor_TalebiGirenPersonelAltbayi',    'string'],
        22 => ['IrisRapor_TalepKaynak',                   'string'],
        23 => ['IrisRapor_SatisDurumu',                   'string'],
        24 => ['IrisRapor_BasvuruSurecDurumu',             'string'],
        25 => ['IrisRapor_AktiveEdilenUyeNo',             'string'],
        26 => ['IrisRapor_AktiveEdilenOutletNo',          'string'],
        27 => ['IrisRapor_AktiveEdilenSozlesmeNo',        'string'],
        28 => ['IrisRapor_AktiveEdilenSozlesmeKmp',       'string'],
        29 => ['IrisRapor_AktiveEdilenSozlesmeDurum',     'string'],
        30 => ['IrisRapor_TalepTakipNotu',                'string'],
        31 => ['IrisRapor_GuncelOutletDurum',             'string'],
        32 => ['IrisRapor_TeyitDurum',                    'string'],
        33 => ['IrisRapor_TeyitAramaDurum',               'string'],
        34 => ['IrisRapor_RandevuTarihi',                 'datetime'],
        35 => ['IrisRapor_MemoSonDurum',                  'string'],
        36 => ['IrisRapor_MemoSonCevap',                  'string'],
        37 => ['IrisRapor_MemoSonAciklama',               'string'],
        38 => ['IrisRapor_Paket',                         'string'],
        39 => ['IrisRapor_Kampanya',                      'string'],
    ];

    $handle = fopen($csvFile, 'r');
    if (!$handle) throw new RuntimeException("CSV açılamadı: {$csvFile}");
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);
    fgetcsv($handle, 0, ';');

    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        if (count($row) < 38) { $stats['skipped']++; continue; }
        $memoId = (int)trim($row[5] ?? '');
        if ($memoId <= 0) { $stats['skipped']++; continue; }

        $data = ['IrisRapor_MemoId' => $memoId, 'IrisRapor_DigiturkAltBayiPersonel' => $personelId, 'IrisRapor_DosyaAdi' => $dosyaAdi];
        foreach ($kolonlar as $idx => $info) {
            $val = trim($row[$idx] ?? '');
            if ($val === '')             { $data[$info[0]] = null; }
            elseif ($info[1] === 'bigint')   { $data[$info[0]] = (int)$val; }
            elseif ($info[1] === 'datetime') { $data[$info[0]] = irisParseDateSql($val); }
            else                             { $data[$info[0]] = $val; }
        }

        $exists = $db->fetchOne("SELECT IrisRapor_Id FROM DigiturkIrisRapor WHERE IrisRapor_MemoId = ?", [$memoId]);
        if ($exists) {
            $upd = $data; unset($upd['IrisRapor_MemoId']);
            $upd['GuncelleyenKullanici'] = 0;
            $upd['GuncellemeTarihi']     = date('Y-m-d H:i:s');
            $db->update('DigiturkIrisRapor', $upd, ['IrisRapor_Id' => $exists['IrisRapor_Id']]);
            $stats['updated']++;
        } else {
            $data['OlusturanKullanici']   = 0;
            $data['OlusturmaTarihi']      = date('Y-m-d H:i:s');
            $data['GuncelleyenKullanici'] = 0;
            $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
            $db->insert('DigiturkIrisRapor', $data);
            $stats['inserted']++;
        }
    }
    fclose($handle);
    return $stats;
}

// ─── VoIP yardımcıları ────────────────────────────────────────────────────────
/**
 * VoIP cookie dosyası için proje içi yazılabilir yol döndürür.
 * sys_get_temp_dir() web (IIS) ve Plesk Cron (CLI) altında farklı/yazılamaz olabildiği
 * için cookie proje kök 'storage/voip-cookies' dizinine yazılır. temp/ altında DEĞİL:
 * oradaki dosyalar elle temizlendiğinde canlı Sippy oturumu kaybolur.
 */
function voipCookieDosyasi(string $on, $id): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'voip-cookies';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir . DIRECTORY_SEPARATOR . $on . md5((string)$id) . '.txt';
}

function sippyCurl(string $url, array $post = [], string $cookieFile = '', string $referer = '', bool $follow = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    ]);
    if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw     = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $final   = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $hdrSize), $m);
    return ['body' => substr($raw, $hdrSize), 'code' => $code, 'final' => $final, 'location' => trim($m[1] ?? '')];
}

function sippySync(array $kanal, $db): array
{
    $baseUrl    = rtrim($kanal['Entegrasyonlar_BaseURL'], '/');
    $hesapYolu  = trim($kanal['EntegrasyonKanallari_Instance'], '/');
    $loginUrl   = "{$baseUrl}/{$hesapYolu}/account.php";
    $accountUrl = "{$baseUrl}/{$hesapYolu}/accounts.php";
    $postUrl    = "{$baseUrl}/main.php";
    $cookieFile = voipCookieDosyasi('sippy_', $kanal['EntegrasyonKanallari_id']);
    $kanalId    = (int)$kanal['EntegrasyonKanallari_id'];

    if (file_exists($cookieFile)) unlink($cookieFile);

    sippyCurl($loginUrl, [], $cookieFile);
    $login = sippyCurl($postUrl, [
        'acct_type'  => 'customer',
        'login_page' => '',
        'username'   => $kanal['EntegrasyonKanallari_Kullanici'],
        'password'   => $kanal['EntegrasyonKanallari_Sifre'],
        'Login'      => 'Login',
    ], $cookieFile, $loginUrl, false);

    $loc     = $login['location'];
    $loginOk = ($login['code'] >= 301 && $login['code'] <= 302
        && strpos($loc, 'index.php') === false
        && strpos($loc, 'account.php') === false
        && $loc !== '');

    if (!$loginOk) return ['success' => false, 'message' => "Login başarısız: {$kanal['EntegrasyonKanallari_KanalAdi']}"];

    $afterUrl = str_starts_with($loc, 'http') ? $loc : "{$baseUrl}/" . ltrim($loc, '/');
    sippyCurl($afterUrl, [], $cookieFile, $postUrl);

    $resp = sippyCurl($accountUrl, [], $cookieFile, $afterUrl);
    if ($resp['final'] !== $accountUrl) return ['success' => false, 'message' => "Hesaplar sayfası alınamadı: {$kanal['EntegrasyonKanallari_KanalAdi']}"];

    preg_match_all('/<table[^>]*>(.*?)<\/table>/is', $resp['body'], $tblM);
    $hesapTabloHtml = '';
    $enCok = 0;
    foreach ($tblM[1] as $tbl) {
        preg_match_all('/<tr[^>]*>/i', $tbl, $trm);
        if (count($trm[0]) > $enCok && count($trm[0]) >= 5) { $enCok = count($trm[0]); $hesapTabloHtml = $tbl; }
    }

    $hesaplar = [];
    if ($hesapTabloHtml) {
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $hesapTabloHtml, $rowM);
        foreach ($rowM[1] as $rIdx => $row) {
            if ($rIdx === 0) continue;
            preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $row, $cellM);
            $ham = $cellM[1];
            $hcr = array_map(fn($c) => trim(strip_tags(html_entity_decode($c))), $ham);
            if (empty(array_filter($hcr))) continue;

            if (count($hcr) >= 7) { $durumHam = $ham[0] ?? ''; $telefon = preg_replace('/\D/', '', $hcr[1]); $aciklama = $hcr[2]; }
            else                  { $durumHam = ''; $telefon = preg_replace('/\D/', '', $hcr[0]); $aciklama = $hcr[1] ?? ''; }

            if (strlen($telefon) < 10) continue;

            if      (stripos($durumHam, 'block') !== false || stripos($durumHam, 'blok') !== false || stripos($aciklama, 'BLOKE') !== false) $durum = 'bloke';
            elseif  (stripos($durumHam, 'disabled') !== false || stripos($durumHam, 'inactive') !== false)                                   $durum = 'pasif';
            else                                                                                                                              $durum = 'aktif';

            $hesaplar[] = ['telefon' => $telefon, 'aciklama' => $aciklama ?: null, 'durum' => $durum];
        }
    }

    $simdi = date('Y-m-d H:i:s');
    $eklenen = $guncellenen = 0;
    foreach ($hesaplar as $h) {
        $mevcut = $db->fetchOne("SELECT VoIPHesaplar_id FROM VoIPHesaplar WHERE VoIPHesaplar_Kanal_id = ? AND VoIPHesaplar_TelefonNo = ?", [$kanalId, $h['telefon']]);
        if ($mevcut) {
            $db->query("UPDATE VoIPHesaplar SET VoIPHesaplar_Aciklama=?, VoIPHesaplar_HesapDurum=?, VoIPHesaplar_SonSenkTarihi=?, GuncelleyenKullanici=?, GuncellemeTarihi=? WHERE VoIPHesaplar_id=?",
                [$h['aciklama'], $h['durum'], $simdi, $kanalId, $simdi, $mevcut['VoIPHesaplar_id']]);
            $guncellenen++;
        } else {
            $db->query("INSERT INTO VoIPHesaplar (VoIPHesaplar_Kanal_id, VoIPHesaplar_TelefonNo, VoIPHesaplar_Aciklama, VoIPHesaplar_HesapDurum, VoIPHesaplar_SonSenkTarihi, OlusturanKullanici, OlusturmaTarihi, GuncelleyenKullanici, GuncellemeTarihi, Durum) VALUES (?,?,?,?,?,1,?,1,?,1)",
                [$kanalId, $h['telefon'], $h['aciklama'], $h['durum'], $simdi, $simdi, $simdi]);
            $eklenen++;
        }
    }

    if (file_exists($cookieFile)) unlink($cookieFile);
    return ['success' => true, 'eklenen' => $eklenen, 'guncellenen' => $guncellenen, 'toplam' => count($hesaplar)];
}

// ─── VoIP Günlük Harcama (CDR) yardımcıları ───────────────────────────────────
/** "dd:ss" → saniye */
function dksnSaniye(string $dkSn): int
{
    $p = explode(':', trim($dkSn));
    return isset($p[1]) ? (int)$p[0] * 60 + (int)$p[1] : (int)$p[0] * 60;
}

/** Esnek tarih → Y-m-d (Y-m-d, d.m.Y, d-m-Y veya strtotime). */
function voipTarihNormalize(?string $v): ?string
{
    $v = trim($v ?? '');
    if ($v === '') return null;
    foreach (['Y-m-d', 'd.m.Y', 'd-m-Y'] as $f) {
        $dt = DateTime::createFromFormat($f, $v);
        if ($dt && $dt->format($f) === $v) return $dt->format('Y-m-d');
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** Tek kanalın günlük harcama (CDR) raporunu çeker ve Odemeler tablosuna (OdemeTuruId=4) yazar. */
function sippyRaporSync(array $kanal, string $tarih, $db): array
{
    $baseUrl    = rtrim($kanal['Entegrasyonlar_BaseURL'], '/');
    $hesapYolu  = trim($kanal['EntegrasyonKanallari_Instance'], '/');
    $kanalId    = (int)$kanal['EntegrasyonKanallari_id'];
    $loginUrl   = "{$baseUrl}/{$hesapYolu}/account.php";
    $postUrl    = "{$baseUrl}/main.php";
    $cookieFile = voipCookieDosyasi('sippy_rapor_', $kanalId);

    // Tarih: YYYY-MM-DD → DD-MM-YYYY ve ertesi gün
    $baslan   = new DateTime($tarih);
    $bitis    = (clone $baslan)->modify('+1 day');
    $startStr = $baslan->format('d-m-Y') . ' 00:00:00';
    $endStr   = $bitis->format('d-m-Y') . ' 00:00:00';

    $raporUrl = "{$baseUrl}/{$hesapYolu}/customer_reports.php?" . http_build_query([
        'startDate' => $startStr, 'caller' => '0_0', 'endDate' => $endStr,
        'cdr_currency' => 'TRY', 'group_by' => '5', 'calls_select' => '4',
        'from_form' => '1', 'action' => '',
    ]);

    if (file_exists($cookieFile)) unlink($cookieFile);
    sippyCurl($loginUrl, [], $cookieFile);

    $login = sippyCurl($postUrl, [
        'acct_type' => 'customer', 'login_page' => '',
        'username'  => $kanal['EntegrasyonKanallari_Kullanici'],
        'password'  => $kanal['EntegrasyonKanallari_Sifre'],
        'Login'     => 'Login',
    ], $cookieFile, $loginUrl, false);

    $loc     = $login['location'];
    $loginOk = ($login['code'] >= 301 && strpos($loc, 'index.php') === false && strpos($loc, 'account.php') === false && $loc !== '');
    if (!$loginOk) return ['success' => false, 'message' => 'Login başarısız: ' . $kanal['EntegrasyonKanallari_KanalAdi']];

    $afterUrl = str_starts_with($loc, 'http') ? $loc : "{$baseUrl}/" . ltrim($loc, '/');
    sippyCurl($afterUrl, [], $cookieFile, $postUrl);

    $resp = sippyCurl($raporUrl, [], $cookieFile, $afterUrl);
    if (strpos($resp['final'], 'customer_reports.php') === false) {
        return ['success' => false, 'message' => 'Rapor sayfası alınamadı: ' . $kanal['EntegrasyonKanallari_KanalAdi']];
    }

    // Tablo parse
    preg_match_all('/<table[^>]*>(.*?)<\/table>/is', $resp['body'], $tblM);
    $anaTablo = [];
    $enCok    = 0;
    foreach ($tblM[1] as $tblContent) {
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tblContent, $rowM);
        $satirlar = [];
        foreach ($rowM[1] as $row) {
            preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $row, $cellM);
            $hcr = array_map(fn($c) => trim(strip_tags(html_entity_decode($c))), $cellM[1]);
            if (array_filter($hcr)) $satirlar[] = $hcr;
        }
        if (count($satirlar) > $enCok && count($satirlar) >= 2) {
            $enCok    = count($satirlar);
            $anaTablo = $satirlar;
        }
    }

    $simdi   = date('Y-m-d H:i:s');
    $eklenen = $guncellenen = 0;

    foreach ($anaTablo as $rIdx => $satir) {
        if ($rIdx === 0 || count($satir) < 5) continue;
        $telefonNo = preg_replace('/\D/', '', $satir[0]);
        if (strlen($telefonNo) < 10) continue;

        $cagriSayisi  = (int)$satir[1];
        $sure         = dksnSaniye($satir[2]);
        $faturaSure   = dksnSaniye($satir[3]);
        $tutar        = (float)str_replace(',', '.', $satir[4]);
        $dakikaUcreti = $faturaSure > 0 ? round($tutar / ($faturaSure / 60), 6) : 0;

        $aciklama = sprintf(
            "Çağrı Sayısı: %d\nSüre (dk:sn): %d:%02d\nFatura Süresi: %d:%02d\nDk. Ücreti: %s",
            $cagriSayisi,
            intdiv($sure, 60), $sure % 60,
            intdiv($faturaSure, 60), $faturaSure % 60,
            number_format($dakikaUcreti, 4, '.', '')
        );

        // OdemeTuruId=4 (VoIP) + Tarih + Referans(telefon) ile tekil; tekrar çalıştırmada güncelle
        $mevcut = $db->fetchOne(
            "SELECT Odemeler_Id FROM Odemeler
             WHERE Odemeler_OdemeTuruId = 4 AND Odemeler_Tarih = ? AND Odemeler_Referans = ?",
            [$tarih, $telefonNo]
        );

        if ($mevcut) {
            $db->query(
                "UPDATE Odemeler SET
                    Odemeler_Tutar = ?, Odemeler_Aciklama = ?,
                    GuncelleyenKullanici = 1, GuncellemeTarihi = ?
                 WHERE Odemeler_Id = ?",
                [$tutar, $aciklama, $simdi, $mevcut['Odemeler_Id']]
            );
            $guncellenen++;
        } else {
            $db->query(
                "INSERT INTO Odemeler
                    (Odemeler_OdemeTuruId, Odemeler_Tutar, Odemeler_Tarih, Odemeler_Referans,
                     Odemeler_Aciklama, Odemeler_Dokuman,
                     OlusturanKullanici, OlusturmaTarihi, GuncelleyenKullanici, GuncellemeTarihi, Durum)
                 VALUES (4, ?, ?, ?, ?, NULL, 1, ?, 1, ?, 1)",
                [$tutar, $tarih, $telefonNo, $aciklama, $simdi, $simdi]
            );
            $eklenen++;
        }
    }

    if (file_exists($cookieFile)) unlink($cookieFile);
    return ['success' => true, 'eklenen' => $eklenen, 'guncellenen' => $guncellenen, 'toplam' => $eklenen + $guncellenen];
}

// ─── VoIP Güncel Bakiye yardımcıları ──────────────────────────────────────────
/** Mesaj şablonundaki {anahtar} yer tutucularını değerlerle değiştirir. */
function voipMesajIsle(string $sablon, array $degiskenler): string
{
    foreach ($degiskenler as $anahtar => $deger) {
        $sablon = str_replace('{' . $anahtar . '}', (string)$deger, $sablon);
    }
    return $sablon;
}

/**
 * Sippy Softswitch'e login olup customer_prefs.php'dan canlı bakiyeyi çeker.
 * Tüm parametreler DB'den (EntegrasyonKanallari + Entegrasyonlar) gelir.
 * Cookie proje storage/voip-cookies altına yazılır (voipCookieDosyasi — Plesk CLI uyumlu).
 */
function canliBakiyeCek(array $kanal): ?float
{
    $baseUrl    = rtrim($kanal['Entegrasyonlar_BaseURL'], '/');
    $hesapYolu  = trim($kanal['EntegrasyonKanallari_Instance'], '/');
    $loginUrl   = "{$baseUrl}/{$hesapYolu}/account.php";
    $postUrl    = "{$baseUrl}/main.php";
    $cookieFile = voipCookieDosyasi('voip_bakiye_', $kanal['EntegrasyonKanallari_id']);

    if (file_exists($cookieFile)) unlink($cookieFile);
    sippyCurl($loginUrl, [], $cookieFile);

    $login = sippyCurl($postUrl, [
        'acct_type'  => 'customer',
        'login_page' => '',
        'username'   => $kanal['EntegrasyonKanallari_Kullanici'],
        'password'   => $kanal['EntegrasyonKanallari_Sifre'],
        'Login'      => 'Login',
    ], $cookieFile, $loginUrl, false);

    $loc     = $login['location'];
    $loginOk = ($login['code'] >= 301 && strpos($loc, 'index.php') === false && strpos($loc, 'account.php') === false && $loc !== '');
    if (!$loginOk) return null;

    $afterUrl = str_starts_with($loc, 'http') ? $loc : "{$baseUrl}/" . ltrim($loc, '/');
    sippyCurl($afterUrl, [], $cookieFile, $postUrl);

    $prefsUrl  = "{$baseUrl}/{$hesapYolu}/customer_prefs.php";
    $prefsResp = sippyCurl($prefsUrl, [], $cookieFile, $afterUrl);

    if (file_exists($cookieFile)) unlink($cookieFile);

    if (strpos($prefsResp['final'], 'customer_prefs.php') === false) return null;

    preg_match('/<input[^>]+name=["\']balance["\'][^>]+value=["\']([^"\']+)["\']/i', $prefsResp['body'], $bM);
    if (!$bM) preg_match('/<input[^>]+value=["\']([^"\']+)["\'][^>]+name=["\']balance["\']/i', $prefsResp['body'], $bM);

    return isset($bM[1]) ? (float)$bM[1] : null;
}

// ─── IRIS web token yenileme (rapor token'ı — _WebToken kolonuna yazılır) ─────
/**
 * Tek personel için IRIS login yapıp verification token'ı _WebToken kolonlarına yazar (+3 saat).
 * gorevTokenGuncelle (toplu) ve gorevIrisRapor (rapor öncesi) tarafından paylaşılır.
 */
function irisWebTokenYenile($db, int $personelId): array
{
    $now = date('Y-m-d H:i:s');
    $per = $db->fetchOne("
        SELECT p.DigiturkAltBayiPersonel_KullaniciAdi, p.DigiturkAltBayiPersonel_Sifre,
               n.DigiturkAnaBayiler_BayiKodu
        FROM DigiturkAltBayiPersonel p
        LEFT JOIN DigiturkAltBayiler a ON p.DigiturkAltBayiPersonel_AltBayiId = a.DigiturkAltBayiler_Id
        LEFT JOIN DigiturkAnaBayiler n ON a.DigiturkAltBayiler_AnaBayiId      = n.DigiturkAnaBayiler_Id
        WHERE p.DigiturkAltBayiPersonel_Id = ? AND p.Durum = 1", [$personelId]);

    if (!$per || empty($per['DigiturkAnaBayiler_BayiKodu']) || empty($per['DigiturkAltBayiPersonel_KullaniciAdi']) || empty($per['DigiturkAltBayiPersonel_Sifre'])) {
        $db->update('DigiturkAltBayiPersonel', ['DigiturkAltBayiPersonel_WebTokenDurum' => 2, 'GuncelleyenKullanici' => 1, 'GuncellemeTarihi' => $now], ['DigiturkAltBayiPersonel_Id' => $personelId]);
        return ['durum' => 2, 'token' => null, 'mesaj' => "IRIS web token: Personel #{$personelId} bulunamadı/eksik bilgi."];
    }

    try {
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 60, CURLOPT_ENCODING => '']);
        $webToken = $verToken = null;
        $r = irisPost($ch, 'Auth/GetToken', new stdClass(), $webToken, $verToken); $webToken = $r['token'];
        $r = irisPost($ch, 'Auth/Login', ['DealerCode' => $per['DigiturkAnaBayiler_BayiKodu'], 'UserCode' => $per['DigiturkAltBayiPersonel_KullaniciAdi'], 'Password' => $per['DigiturkAltBayiPersonel_Sifre'], 'Language' => 'tr'], $webToken, $verToken);
        $verToken = $r['Data']['Token'] ?? $r['EntityData']['Token'] ?? null;
        if (!$verToken) throw new RuntimeException($r['ResponseMessage'] ?? $r['responseMessage'] ?? 'IRIS login başarısız');
        curl_close($ch);

        $db->update('DigiturkAltBayiPersonel', [
            'DigiturkAltBayiPersonel_WebToken'       => $verToken,
            'DigiturkAltBayiPersonel_WebTokenSuresi' => date('Y-m-d H:i:s', strtotime('+3 hours')),
            'DigiturkAltBayiPersonel_WebTokenDurum'  => 1,
            'GuncelleyenKullanici' => 1, 'GuncellemeTarihi' => $now,
        ], ['DigiturkAltBayiPersonel_Id' => $personelId]);

        return ['durum' => 1, 'token' => $verToken, 'mesaj' => "IRIS web token güncellendi (#{$personelId})."];
    } catch (Throwable $e) {
        $db->update('DigiturkAltBayiPersonel', ['DigiturkAltBayiPersonel_WebTokenDurum' => 2, 'GuncelleyenKullanici' => 1, 'GuncellemeTarihi' => $now], ['DigiturkAltBayiPersonel_Id' => $personelId]);
        return ['durum' => 2, 'token' => null, 'mesaj' => "IRIS web token hatası (#{$personelId}): " . $e->getMessage()];
    }
}

// ─── Görev fonksiyonları ─────────────────────────────────────────────────────
/**
 * Token Güncelle
 *
 * Login ortak digiturkLogin() fonksiyonu ile yapılır (14.09.2026). Karar Digiturk'ün
 * cevabına göre verilir: Digiturk "limit" derse bayi gün sonuna kadar kilitlenir ve
 * o bayinin kalan personeli Digiturk'e gitmeden atlanır; şifre hatası alan personel
 * şifresi değişene kadar, geçici hata alan kural süresi kadar beklemeye alınır.
 *
 * Sıralama: sağlam personel önce, son login'i hatalı olanlar sona. Böylece şifresi
 * dolmuş hesaplar sağlam hesapların önünde login harcayamaz.
 *
 * Parametreler:
 *   zorla : 1 → token süresine bakmadan tümünü yeniler (kilitler yine uygulanır)
 */
function gorevTokenGuncelle(array $params, $db): array
{
    ob_start();
    set_time_limit(900);
    $basarili = $hatali = $atlanan = 0;
    $hataMesajlari = [];

    require_once dirname(__DIR__) . '/admin/includes/DigiturkKotaServisi.php';

    // Token süresi dolmamış personeli atla; params['zorla'] = true ile tümü yenilenir.
    $zorla = !empty($params['zorla']);
    // Seçim filtresiyle aynı pay: bitimine 2 saatten az kalan token yenilenir
    $payDk = 120;

    try {
        $personeller = $db->fetchAll("
            SELECT p.DigiturkAltBayiPersonel_Id, n.DigiturkAnaBayiler_BayiKodu
            FROM DigiturkAltBayiPersonel p
            LEFT JOIN DigiturkAltBayiler a ON p.DigiturkAltBayiPersonel_AltBayiId = a.DigiturkAltBayiler_Id
            LEFT JOIN DigiturkAnaBayiler n ON a.DigiturkAltBayiler_AnaBayiId      = n.DigiturkAnaBayiler_Id
            WHERE p.Durum = 1" . ($zorla ? "" : "
              AND (p.DigiturkAltBayiPersonel_Token IS NULL
                OR p.DigiturkAltBayiPersonel_TokenSuresi IS NULL
                OR p.DigiturkAltBayiPersonel_TokenSuresi < DATEADD(MINUTE, {$payDk}, GETDATE()))") . "
            ORDER BY n.DigiturkAnaBayiler_BayiKodu,
                     CASE WHEN p.DigiturkAltBayiPersonel_TokenDurum = 2 THEN 1 ELSE 0 END,
                     p.DigiturkAltBayiPersonel_TokenSuresi ASC,
                     p.DigiturkAltBayiPersonel_Id");

        if (!$personeller) {
            // Filtreli çalışmada boş sonuç normaldir: tüm tokenlar hâlâ geçerli.
            $iris = irisWebTokenYenile($db, IRIS_PERSONEL_ID);
            echo ($iris['durum'] === 1 ? '  ✓ ' : '  ✗ ') . $iris['mesaj'] . "\n";
            $sonuc = ($zorla ? 'Aktif personel bulunamadı.' : 'Yenilenmesi gereken token yok.') . ' | ' . $iris['mesaj'];
            return ['durum' => $zorla ? 2 : 1, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
        }

        $ilk = true;

        foreach ($personeller as $per) {
            $pId  = (int)$per['DigiturkAltBayiPersonel_Id'];
            $bayi = (string)($per['DigiturkAnaBayiler_BayiKodu'] ?? '');

            // İstekler arası nefes payı — yalnız Digiturk'e gerçekten gidilecekse anlamlı,
            // bu yüzden bir önceki çağrı istek gönderdiyse beklenir.
            if (!$ilk) usleep(1500000);

            $s   = digiturkLogin($db, $pId, 'cron', $zorla, $payDk);
            $ilk = !$s['istek_gitti'];

            if ($s['basarili']) {
                if ($s['onbellek']) { echo "  • Personel #{$pId}: token hâlâ geçerli, atlandı.\n"; continue; }
                $basarili++; echo "  ✓ Personel #{$pId} token güncellendi.\n";
                continue;
            }

            // Digiturk'e gitmeden dönen kilit durumları → atlanan
            if (!$s['istek_gitti'] && in_array($s['hata_turu'], ['limit', 'personel_limit', 'sifre', 'gecici'], true)) {
                $atlanan++; echo "  ⏭ Personel #{$pId} (bayi {$bayi}): {$s['mesaj']}\n";
                continue;
            }

            $hatali++; $hataMesajlari[] = "#{$pId}: {$s['mesaj']}"; echo "  ✗ Personel #{$pId}: {$s['mesaj']}\n";
            if ($s['hata_turu'] === 'limit') echo "  ⛔ Bayi {$bayi} Digiturk login limitine takıldı.\n";
        }
        $sonuc = "{$basarili} personel PROD token güncellendi, {$hatali} hata";
        $sonuc .= $atlanan > 0 ? ", {$atlanan} kilit nedeniyle atlandı." : '.';
        if ($hataMesajlari) $sonuc .= ' PROD Hatalar: ' . implode('; ', $hataMesajlari);

        // IRIS web token (rapor token'ı) — yalnız IRIS personeli için _WebToken kolonlarına yazılır
        $iris = irisWebTokenYenile($db, IRIS_PERSONEL_ID);
        echo ($iris['durum'] === 1 ? '  ✓ ' : '  ✗ ') . $iris['mesaj'] . "\n";
        $sonuc .= ' | ' . $iris['mesaj'];

        $durum = ($hatali > 0 && $basarili === 0) ? 2 : 1;
    } catch (Throwable $e) { $sonuc = get_class($e) . ': ' . $e->getMessage(); $durum = 2; }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

function gorevIrisRapor(array $params, $db): array
{
    ob_start();
    set_time_limit(600);

    try {
        $baslangicParam = trim($params['baslangic'] ?? '');
        $bitisParam     = trim($params['bitis']     ?? '');
        if (!$baslangicParam || !$bitisParam) { $cikti = ob_get_clean(); return ['durum' => 2, 'sonuc' => 'baslangic ve bitis parametreleri zorunludur (dd.mm.yyyy).', 'cikti' => $cikti]; }

        // Zamanlayıcıdan personel seçilebilir; boş bırakılırsa eski davranış (sabit IRIS personeli).
        $hedefPersonelId = (int)($params['personel_id'] ?? 0) ?: IRIS_PERSONEL_ID;

        $personel = $db->fetchOne("
            SELECT p.DigiturkAltBayiPersonel_Id, p.DigiturkAltBayiPersonel_KullaniciAdi,
                   p.DigiturkAltBayiPersonel_Sifre, p.DigiturkAltBayiPersonel_AdSoyad,
                   n.DigiturkAnaBayiler_BayiKodu
            FROM DigiturkAltBayiPersonel p
            LEFT JOIN DigiturkAltBayiler a ON p.DigiturkAltBayiPersonel_AltBayiId = a.DigiturkAltBayiler_Id
            LEFT JOIN DigiturkAnaBayiler n ON a.DigiturkAltBayiler_AnaBayiId      = n.DigiturkAnaBayiler_Id
            WHERE p.DigiturkAltBayiPersonel_Id = ? AND p.Durum = 1", [$hedefPersonelId]);
        if (!$personel) { $cikti = ob_get_clean(); return ['durum' => 2, 'sonuc' => 'Personel (Id=' . $hedefPersonelId . ') bulunamadı.', 'cikti' => $cikti]; }
        if (empty($personel['DigiturkAnaBayiler_BayiKodu'])) { $cikti = ob_get_clean(); return ['durum' => 2, 'sonuc' => 'Personelin (Id=' . $hedefPersonelId . ') bağlı olduğu ana bayi kodu bulunamadı.', 'cikti' => $cikti]; }
        echo "  Personel: #{$hedefPersonelId} " . ($personel['DigiturkAltBayiPersonel_AdSoyad'] ?? '') . " — Bayi: {$personel['DigiturkAnaBayiler_BayiKodu']}\n";

        $dealerCode = $personel['DigiturkAnaBayiler_BayiKodu'];
        $userCode   = $personel['DigiturkAltBayiPersonel_KullaniciAdi'];
        $password   = $personel['DigiturkAltBayiPersonel_Sifre'];
        $personelId = (int)$personel['DigiturkAltBayiPersonel_Id'];

        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => IRIS_HTTP_TIMEOUT, CURLOPT_ENCODING => '']);

        $webToken = $verToken = null;
        $r = irisPost($ch, 'Auth/GetToken', new stdClass(), $webToken, $verToken); $webToken = $r['token'];
        $r = irisPost($ch, 'Auth/Login', ['DealerCode' => $dealerCode, 'UserCode' => $userCode, 'Password' => $password, 'Language' => 'tr'], $webToken, $verToken);
        $verToken = $r['Data']['Token'] ?? $r['EntityData']['Token'] ?? null;
        if (!$verToken) throw new RuntimeException('IRIS login başarısız: ' . ($r['ResponseMessage'] ?? $r['responseMessage'] ?? json_encode($r)));
        $r = irisPost($ch, 'Auth/GetToken', new stdClass(), $webToken, $verToken); $webToken = $r['token'];

        $db->update('DigiturkAltBayiPersonel', ['DigiturkAltBayiPersonel_WebToken' => $verToken, 'DigiturkAltBayiPersonel_WebTokenSuresi' => date('Y-m-d H:i:s', strtotime('+3 hours')), 'DigiturkAltBayiPersonel_WebTokenDurum' => 1, 'GuncelleyenKullanici' => 1, 'GuncellemeTarihi' => date('Y-m-d H:i:s')], ['DigiturkAltBayiPersonel_Id' => $personelId]);

        // Tarihler ters girilmişse otomatik düzelt
        $sDate = DateTime::createFromFormat('d.m.Y', $baslangicParam);
        $eDate = DateTime::createFromFormat('d.m.Y', $bitisParam);
        if ($sDate && $eDate && $sDate > $eDate) {
            echo "  UYARI: Başlangıç > Bitiş, tarihler otomatik yer değiştirildi.\n";
            [$baslangicParam, $bitisParam] = [$bitisParam, $baslangicParam];
        }
        $chunks = irisDateChunks($baslangicParam, $bitisParam, IRIS_MAX_DAYS);
        $tmpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'temp';
        if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);
        $totalStats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($chunks as $chunk) {
            echo "  Parça: {$chunk['start']} — {$chunk['end']}\n";
            $r = irisPost($ch, 'Auth/GetToken', new stdClass(), $webToken, $verToken); $webToken = $r['token'];
            $r = irisPost($ch, 'Report/GetReportList', new stdClass(), $webToken, $verToken);
            // IRIS liste yanıtını 'Data' altında döndürüyor; 'DataList'/'EntityDataList'
            // eski sürümler için korunuyor.
            $oncekiIdler = array_map(fn($x) => $x['reportRequestId'] ?? $x['ReportRequestId'] ?? 0, $r['DataList'] ?? $r['EntityDataList'] ?? $r['Data'] ?? []);

            $r = irisPost($ch, 'Report/AddQueue', ['reportVersionSpecId' => IRIS_REPORT_SPEC, 'requestParams' => json_encode(['BayiKodu' => $dealerCode, 'IlkTarih' => $chunk['start'], 'SonTarih' => $chunk['end']]), 'requestType' => 0, 'requestLimit' => ['requestPeriodAsMinute' => 60, 'maxRequestCount' => 10]], $webToken, $verToken);
            if (($r['ResponseCode'] ?? -1) !== 0) { echo "  Kuyruk eklenemedi, atlanıyor.\n"; continue; }

            $elapsed = 0; $raporBilgi = null;
            while ($elapsed < IRIS_POLL_TIMEOUT) {
                sleep(IRIS_POLL_INTERVAL); $elapsed += IRIS_POLL_INTERVAL;
                $r2 = irisPost($ch, 'Auth/GetToken', new stdClass(), $webToken, $verToken); $webToken = $r2['token'];
                $r2 = irisPost($ch, 'Report/GetReportList', new stdClass(), $webToken, $verToken);
                foreach ($r2['DataList'] ?? $r2['EntityDataList'] ?? $r2['Data'] ?? [] as $rr) {
                    $rid = $rr['reportRequestId'] ?? $rr['ReportRequestId'] ?? null;
                    if (in_array($rid, $oncekiIdler)) continue;
                    if ($rr['statusCd'] === 'TAMAMLANDI') { $raporBilgi = ['id' => $rid]; break 2; }
                }
            }
            if (!$raporBilgi) { echo "  Zaman aşımı, atlanıyor.\n"; continue; }

            $r2 = irisPost($ch, 'Auth/GetToken', new stdClass(), $webToken, $verToken); $webToken = $r2['token'];
            $r2 = irisPost($ch, 'Report/GetReportContent', ['ReportRequestId' => $raporBilgi['id']], $webToken, $verToken);
            $csvData = base64_decode($r2['Data'] ?? $r2['EntityData'] ?? '');
            if (empty($csvData)) { echo "  CSV boş, atlanıyor.\n"; continue; }

            $bom = "\xEF\xBB\xBF";
            if (substr($csvData, 0, 3) !== $bom) $csvData = $bom . $csvData;
            $dosyaAdi = 'IrisRapor-' . $chunk['start'] . '-' . $chunk['end'] . '_' . date('Ymd_His') . '.csv';
            $dosyaYol = $tmpDir . '/' . $dosyaAdi;
            file_put_contents($dosyaYol, $csvData);
            $cs = irisImportCsv($dosyaYol, $personelId, $dosyaAdi, $db);
            @unlink($dosyaYol);
            $totalStats['inserted'] += $cs['inserted']; $totalStats['updated'] += $cs['updated']; $totalStats['skipped'] += $cs['skipped'];
            echo "  ✓ {$cs['inserted']} eklendi, {$cs['updated']} güncellendi.\n";
        }
        curl_close($ch);
        $sonuc = "{$totalStats['inserted']} kayıt eklendi, {$totalStats['updated']} güncellendi, {$totalStats['skipped']} atlandı.";
        $durum = 1;
    } catch (Throwable $e) { $sonuc = get_class($e) . ': ' . $e->getMessage(); $durum = 2; }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

function gorevVoipHesapGuncelle(array $params, $db): array
{
    ob_start();
    set_time_limit(120);

    try {
        $kanallar = $db->fetchAll("
            SELECT k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_KanalAdi, k.EntegrasyonKanallari_Host,
                   k.EntegrasyonKanallari_Instance, k.EntegrasyonKanallari_Kullanici, k.EntegrasyonKanallari_Sifre,
                   e.Entegrasyonlar_BaseURL
            FROM EntegrasyonKanallari k
            INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
            WHERE e.Entegrasyonlar_Tip = 'voip' AND k.Durum = 1 AND e.Durum = 1
              -- Sippy giriş bilgisi tanımlı olmayan kanallar (ör. farklı altyapı) atlanır
              AND ISNULL(k.EntegrasyonKanallari_Kullanici, '') <> '' AND ISNULL(k.EntegrasyonKanallari_Instance, '') <> ''");
        if (!$kanallar) { $cikti = ob_get_clean(); return ['durum' => 2, 'sonuc' => 'Aktif VoIP kanalı bulunamadı.', 'cikti' => $cikti]; }

        $toplamEklenen = $toplamGuncellenen = 0; $hatalar = [];
        foreach ($kanallar as $k) {
            $r = sippySync($k, $db);
            if ($r['success']) {
                $toplamEklenen += $r['eklenen']; $toplamGuncellenen += $r['guncellenen'];
                echo "  ✓ {$k['EntegrasyonKanallari_KanalAdi']}: {$r['eklenen']} eklendi, {$r['guncellenen']} güncellendi.\n";
            } else {
                $hatalar[] = "{$k['EntegrasyonKanallari_KanalAdi']}: {$r['message']}";
                echo "  ✗ {$k['EntegrasyonKanallari_KanalAdi']}: {$r['message']}\n";
            }
        }
        $sonuc = "{$toplamEklenen} hesap eklendi, {$toplamGuncellenen} güncellendi.";
        if ($hatalar) $sonuc .= ' Hatalar: ' . implode('; ', $hatalar);
        $durum = ($hatalar && !$toplamEklenen && !$toplamGuncellenen) ? 2 : 1;
    } catch (Throwable $e) { $sonuc = get_class($e) . ': ' . $e->getMessage(); $durum = 2; }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

function gorevVoipGunlukHarcama(array $params, $db): array
{
    ob_start();
    set_time_limit(300);

    try {
        $tarih = voipTarihNormalize($params['tarih'] ?? '') ?: date('Y-m-d', strtotime('-1 day'));
        echo "  Rapor tarihi: {$tarih}\n";

        $kanallar = $db->fetchAll("
            SELECT k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_KanalAdi,
                   k.EntegrasyonKanallari_Instance, k.EntegrasyonKanallari_Kullanici,
                   k.EntegrasyonKanallari_Sifre, e.Entegrasyonlar_BaseURL
            FROM EntegrasyonKanallari k
            INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
            WHERE e.Entegrasyonlar_Tip = 'voip' AND k.Durum = 1 AND e.Durum = 1
              -- Sippy giriş bilgisi tanımlı olmayan kanallar (ör. farklı altyapı) atlanır
              AND ISNULL(k.EntegrasyonKanallari_Kullanici, '') <> '' AND ISNULL(k.EntegrasyonKanallari_Instance, '') <> ''");
        if (!$kanallar) { return ['durum' => 2, 'sonuc' => 'Aktif VoIP kanalı bulunamadı.', 'cikti' => ob_get_clean()]; }

        $toplamEklenen = $toplamGuncellenen = 0; $hatalar = [];
        foreach ($kanallar as $k) {
            $r = sippyRaporSync($k, $tarih, $db);
            if ($r['success']) {
                $toplamEklenen += $r['eklenen']; $toplamGuncellenen += $r['guncellenen'];
                echo "  ✓ {$k['EntegrasyonKanallari_KanalAdi']}: {$r['eklenen']} eklendi, {$r['guncellenen']} güncellendi.\n";
            } else {
                $hatalar[] = "{$k['EntegrasyonKanallari_KanalAdi']}: {$r['message']}";
                echo "  ✗ {$k['EntegrasyonKanallari_KanalAdi']}: {$r['message']}\n";
            }
        }
        $sonuc = "{$tarih} → {$toplamEklenen} kayıt eklendi, {$toplamGuncellenen} güncellendi.";
        if ($hatalar) $sonuc .= ' Hatalar: ' . implode('; ', $hatalar);
        $durum = ($hatalar && !$toplamEklenen && !$toplamGuncellenen) ? 2 : 1;
    } catch (Throwable $e) { $sonuc = get_class($e) . ': ' . $e->getMessage(); $durum = 2; }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

function gorevVoipBakiyeKontrol(array $params, $db): array
{
    ob_start();
    set_time_limit(300);

    // Manuel çalıştırmada dry-run desteği (param: dry=1)
    $isDry = !empty($params['dry']);

    $L = function (string $msg) use ($isDry) {
        echo '[' . date('H:i:s') . '] ' . ($isDry ? '[DRY] ' : '') . $msg . PHP_EOL;
    };

    try {
        require_once __DIR__ . '/../admin/includes/EntegrasyonHelper.php';

        if ($isDry) $L('========== DRY RUN — DB ve WhatsApp işlemleri YAPILMAZ ==========');

        // Mesai saati kontrolü (09:00 - 18:00): dışında bakiye çekilir ama bildirim gönderilmez
        $saat     = (int)date('H');
        $mesaiIci = ($saat >= 9 && $saat < 18);
        if (!$mesaiIci) $L("Mesai saati dışında ({$saat}:xx). Bakiye kontrol edilir ama bildirim gönderilmez.");

        $kurallar = $db->fetchAll("
            SELECT
                k.VoIPBildirimKurallari_id,
                k.VoIPBildirimKurallari_EsikTutar,
                k.VoIPBildirimKurallari_HedefNo,
                k.VoIPBildirimKurallari_TedarikciNo,
                k.VoIPBildirimKurallari_Mesaj,
                k.VoIPBildirimKurallari_TedarikciMesaj,
                k.VoIPBildirimKurallari_TekrarSaati,
                k.VoIPBildirimKurallari_Durum,
                k.VoIPBildirimKurallari_OdemeTutar,
                CONVERT(VARCHAR(19), k.VoIPBildirimKurallari_OdemeTarihi, 120) as OdemeTarihi,
                k.VoIPBildirimKurallari_SonBildirimTar,
                k.VoIPBildirimKurallari_WAKanal_id,
                vk.EntegrasyonKanallari_id,
                vk.EntegrasyonKanallari_KanalAdi,
                vk.EntegrasyonKanallari_Instance,
                vk.EntegrasyonKanallari_Kullanici,
                vk.EntegrasyonKanallari_Sifre,
                e.Entegrasyonlar_BaseURL,
                e.Entegrasyonlar_Adi as OperatorAdi
            FROM VoIPBildirimKurallari k
            INNER JOIN EntegrasyonKanallari vk ON k.VoIPBildirimKurallari_VoIPKanal_id = vk.EntegrasyonKanallari_id
            INNER JOIN Entegrasyonlar e ON vk.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
            WHERE k.Durum = 1
            ORDER BY k.VoIPBildirimKurallari_id
        ");

        if (!$kurallar) {
            return ['durum' => 1, 'sonuc' => 'Aktif kural bulunamadı.', 'cikti' => ob_get_clean()];
        }

        $L(count($kurallar) . ' aktif kural bulundu.');
        $simdi      = date('Y-m-d H:i:s');
        $bildirim   = 0; $hata = 0;

        foreach ($kurallar as $kural) {
            $kuralId  = (int)$kural['VoIPBildirimKurallari_id'];
            $durum    = $kural['VoIPBildirimKurallari_Durum'];
            $waKanal  = (int)$kural['VoIPBildirimKurallari_WAKanal_id'];
            $esik     = (float)$kural['VoIPBildirimKurallari_EsikTutar'];
            $operator = $kural['OperatorAdi'] . ' / ' . $kural['EntegrasyonKanallari_KanalAdi'];

            $L("Kural #{$kuralId} ({$operator}) — durum: {$durum}");

            $bakiye = canliBakiyeCek($kural);
            if ($bakiye === null) {
                $L("  ✗ Bakiye çekilemedi, atlanıyor.");
                $hata++;
                continue;
            }

            $L("  Bakiye: " . number_format($bakiye, 2, ',', '.') . " TRY | Eşik: " . number_format($esik, 2, ',', '.') . " TRY");

            // Bakiye snapshot — kanal id yazılır (kural id DEĞİL; okuyucular
            // EntegrasyonKanallari_id varsayıyor: voip-bakiye.php, voip-bildirim-ayarlari.php)
            if (!$isDry) {
                $db->query(
                    "INSERT INTO VoIPBakiye (VoIPBakiye_Kanal_id, VoIPBakiye_Tarih, VoIPBakiye_Bakiye,
                     OlusturanKullanici, OlusturmaTarihi, GuncelleyenKullanici, GuncellemeTarihi)
                     VALUES (?,?,?,1,?,1,?)",
                    [(int)$kural['EntegrasyonKanallari_id'], $simdi, $bakiye, $simdi, $simdi]
                );
            } else {
                $L("  [ATLANDI] VoIPBakiye snapshot eklenmedi.");
            }

            // Hatırlatma motoruna geçişte: görev yalnız bakiye snapshot'ı yazar,
            // bildirimleri CronHatirlatma* kuralları gönderir (zamanlama parametresi: sadece_snapshot=1)
            if (!empty($params['sadece_snapshot'])) {
                $L('  ⏭ Yalnız snapshot modu — bildirim gönderimi hatırlatma motoruna bırakıldı.');
                continue;
            }

            $bakiyeFormat = number_format($bakiye, 2, ',', '.');

            // ── Durum: beklemede ──────────────────────────────────────────────
            if ($durum === 'beklemede') {
                if ($bakiye >= $esik) { $L("  ✓ Bakiye yeterli, bildirim gerekmez."); continue; }

                $tekrarSaati = (int)$kural['VoIPBildirimKurallari_TekrarSaati'];
                if ($kural['VoIPBildirimKurallari_SonBildirimTar']) {
                    $sonBildirim = new DateTime($kural['VoIPBildirimKurallari_SonBildirimTar']);
                    $fark        = (new DateTime())->diff($sonBildirim);
                    $gecenSaat   = $fark->days * 24 + $fark->h;
                    if ($gecenSaat < $tekrarSaati) {
                        $L("  ⏱ Son bildirimden {$gecenSaat} saat geçmiş, {$tekrarSaati} saat bekleniyor.");
                        continue;
                    }
                }

                $mesaj = voipMesajIsle($kural['VoIPBildirimKurallari_Mesaj'], ['bakiye' => $bakiyeFormat]);
                $L("  Mesaj: {$mesaj}");
                $L("  Hedef: {$kural['VoIPBildirimKurallari_HedefNo']}");

                if ($isDry) {
                    $L("  [ATLANDI] WhatsApp gönderimi ve durum güncellemesi yapılmadı.");
                } elseif (!$mesaiIci) {
                    $L("  ⏸ Mesai saati dışında, bildirim bekletiliyor.");
                } else {
                    $sonuc = EntegrasyonHelper::whatsappGonder($waKanal, $kural['VoIPBildirimKurallari_HedefNo'], $mesaj);
                    if ($sonuc['success']) {
                        $L("  ✓ İç bildirim gönderildi → {$kural['VoIPBildirimKurallari_HedefNo']}");
                        $bildirim++;
                        $db->query("
                            UPDATE VoIPBildirimKurallari SET
                                VoIPBildirimKurallari_Durum      = 'odeme_bekleniyor',
                                VoIPBildirimKurallari_SonBildirimTar = ?,
                                GuncelleyenKullanici = 1, GuncellemeTarihi = ?
                            WHERE VoIPBildirimKurallari_id = ?
                        ", [$simdi, $simdi, $kuralId]);
                    } else {
                        $L("  ✗ Bildirim gönderilemedi: " . $sonuc['message']);
                        $hata++;
                    }
                }
                continue;
            }

            // ── Durum: odeme_bekleniyor ───────────────────────────────────────
            if ($durum === 'odeme_bekleniyor') {
                if ($bakiye >= $esik) {
                    $L("  ✓ Bakiye eşiği geçti, kural sıfırlanıyor.");
                    if (!$isDry) {
                        $db->query("
                            UPDATE VoIPBildirimKurallari SET
                                VoIPBildirimKurallari_Durum       = 'beklemede',
                                VoIPBildirimKurallari_SonBildirimTar = NULL,
                                GuncelleyenKullanici = 1, GuncellemeTarihi = ?
                            WHERE VoIPBildirimKurallari_id = ?
                        ", [$simdi, $kuralId]);
                    } else { $L("  [ATLANDI] Durum güncellemesi yapılmadı."); }
                    continue;
                }

                $tekrarSaati = (int)$kural['VoIPBildirimKurallari_TekrarSaati'];
                if ($kural['VoIPBildirimKurallari_SonBildirimTar']) {
                    $sonBildirim = new DateTime($kural['VoIPBildirimKurallari_SonBildirimTar']);
                    $gecenSaat   = (int)((new DateTime())->diff($sonBildirim)->days * 24 + (new DateTime())->diff($sonBildirim)->h);
                    if ($gecenSaat < $tekrarSaati) {
                        $L("  ⏱ Hatırlatma için {$tekrarSaati} saat bekleniyor ({$gecenSaat} saat geçti). Admin panelinden 'Ödeme Yapıldı' seçin.");
                        continue;
                    }
                }

                $mesaj = voipMesajIsle($kural['VoIPBildirimKurallari_Mesaj'], ['bakiye' => $bakiyeFormat]);
                $L("  Hatırlatma mesajı: {$mesaj}");
                $L("  Hedef: {$kural['VoIPBildirimKurallari_HedefNo']}");

                if ($isDry) {
                    $L("  [ATLANDI] Hatırlatma WhatsApp gönderimi yapılmadı.");
                } elseif (!$mesaiIci) {
                    $L("  ⏸ Mesai saati dışında, hatırlatma bekletiliyor.");
                } else {
                    $sonuc = EntegrasyonHelper::whatsappGonder($waKanal, $kural['VoIPBildirimKurallari_HedefNo'], $mesaj);
                    if ($sonuc['success']) {
                        $L("  ✓ Hatırlatma gönderildi → {$kural['VoIPBildirimKurallari_HedefNo']}");
                        $bildirim++;
                        $db->query("
                            UPDATE VoIPBildirimKurallari SET
                                VoIPBildirimKurallari_SonBildirimTar = ?,
                                GuncelleyenKullanici = 1, GuncellemeTarihi = ?
                            WHERE VoIPBildirimKurallari_id = ?
                        ", [$simdi, $simdi, $kuralId]);
                    } else {
                        $L("  ✗ Hatırlatma gönderilemedi: " . $sonuc['message']);
                        $hata++;
                    }
                }
                continue;
            }

            // ── Durum: operator_bekleniyor ────────────────────────────────────
            if ($durum === 'operator_bekleniyor') {
                if ($bakiye >= $esik) {
                    $L("  ✓ Bakiye eşiği geçti (ödeme yansıdı), kural sıfırlanıyor.");
                    if (!$isDry) {
                        $db->query("
                            UPDATE VoIPBildirimKurallari SET
                                VoIPBildirimKurallari_Durum       = 'beklemede',
                                VoIPBildirimKurallari_OdemeTutar  = NULL,
                                VoIPBildirimKurallari_OdemeTarihi = NULL,
                                VoIPBildirimKurallari_SonBildirimTar = NULL,
                                GuncelleyenKullanici = 1, GuncellemeTarihi = ?
                            WHERE VoIPBildirimKurallari_id = ?
                        ", [$simdi, $kuralId]);
                    } else { $L("  [ATLANDI] Durum güncellemesi yapılmadı."); }
                    continue;
                }

                // Tedarikçi hatırlatması muhasebeden bağımsız sabit aralık kullanır (VOIP_TEDARIKCI_TEKRAR_SAATI)
                $tekrarSaati       = VOIP_TEDARIKCI_TEKRAR_SAATI;
                // Tedarikçiye gönderim genel mesaiden ayrı kendi penceresini kullanır (09–19)
                $tedarikciMesaiIci = ($saat >= VOIP_TEDARIKCI_MESAI_BAS && $saat < VOIP_TEDARIKCI_MESAI_BITIS);
                if ($kural['VoIPBildirimKurallari_SonBildirimTar']) {
                    $sonBildirim = new DateTime($kural['VoIPBildirimKurallari_SonBildirimTar']);
                    $gecenSaat   = (int)((new DateTime())->diff($sonBildirim)->days * 24 + (new DateTime())->diff($sonBildirim)->h);
                    if ($gecenSaat < $tekrarSaati) {
                        $L("  ⏱ Tedarikçi hatırlatması için {$tekrarSaati} saat bekleniyor ({$gecenSaat} saat geçti).");
                        continue;
                    }
                }

                $odemeTutar  = $kural['VoIPBildirimKurallari_OdemeTutar'] ?? '?';
                $odemeTarihi = $kural['OdemeTarihi'] ?? '?';
                $mesaj = voipMesajIsle($kural['VoIPBildirimKurallari_TedarikciMesaj'], [
                    'tutar'  => number_format((float)$odemeTutar, 2, ',', '.'),
                    'tarih'  => $odemeTarihi,
                    'bakiye' => $bakiyeFormat,
                ]);

                $L("  Mesaj: {$mesaj}");
                $L("  Tedarikçi: {$kural['VoIPBildirimKurallari_TedarikciNo']}");

                if ($isDry) {
                    $L("  [ATLANDI] WhatsApp gönderimi ve durum güncellemesi yapılmadı.");
                } elseif (!$tedarikciMesaiIci) {
                    $L("  ⏸ Tedarikçi gönderim saati dışında (" . VOIP_TEDARIKCI_MESAI_BAS . "-" . VOIP_TEDARIKCI_MESAI_BITIS . "), bildirim bekletiliyor.");
                } else {
                    $sonuc = EntegrasyonHelper::whatsappGonder($waKanal, $kural['VoIPBildirimKurallari_TedarikciNo'], $mesaj);
                    if ($sonuc['success']) {
                        $L("  ✓ Tedarikçi bildirimi gönderildi → {$kural['VoIPBildirimKurallari_TedarikciNo']}");
                        $bildirim++;
                        $db->query("
                            UPDATE VoIPBildirimKurallari SET
                                VoIPBildirimKurallari_SonBildirimTar = ?,
                                GuncelleyenKullanici = 1, GuncellemeTarihi = ?
                            WHERE VoIPBildirimKurallari_id = ?
                        ", [$simdi, $simdi, $kuralId]);
                    } else {
                        $L("  ✗ Tedarikçi bildirimi gönderilemedi: " . $sonuc['message']);
                        $hata++;
                    }
                }
            }
        }

        $sonuc = count($kurallar) . " kural işlendi, {$bildirim} bildirim gönderildi" . ($hata ? ", {$hata} hata." : ".");
        $durum = ($hata > 0 && $bildirim === 0) ? 2 : 1;
    } catch (Throwable $e) {
        $sonuc = get_class($e) . ': ' . $e->getMessage();
        $durum = 2;
    }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

// ─── WhatsApp Rapor Görsel ───────────────────────────────────────────────────

/**
 * TTF metin çizer; kutunun yüksekliğine göre dikey ortalama yapar.
 */
function _brpText($img, string $font, float $size, int $x, int $y, int $h, int $w, string $text, $color, string $align = 'L'): void
{
    if ($text === '') return;
    $bbox = imagettfbbox($size, 0, $font, $text);
    $th   = abs($bbox[7] - $bbox[1]);
    $tw   = abs($bbox[2] - $bbox[0]);
    $ty   = $y + (int)(($h + $th) / 2);
    $tx   = match ($align) {
        'C'     => $x + (int)(($w - $tw) / 2),
        'R'     => $x + $w - $tw - 5,
        default => $x + 6,
    };
    $tx = max($x + 2, min($tx, $x + $w - 2));
    imagettftext($img, $size, 0, $tx, $ty, $color, $font, $text);
}

/**
 * Tek tablo bölümünü çizer ve alt Y koordinatını döndürür.
 */
function _brpSection(
    $img, string $fontR, string $fontB,
    int $sx, int $sy,
    string $title,
    array $colLabels, array $colWidths, array $colAligns, array $colKeys,
    array $rows,
    int $barH, int $hdrH, int $rowH, int $totH,
    float $fzSec, float $fzHdr, float $fzData,
    $cHdrBg, $cHdrTx, $cBg, $cAltBg, $cBorder, $cText, $cDiv,
    $cSecBg = null, // Bölüm başlık barı rengi (null ise cHdrBg kullanılır)
    int $minRows = 0 // Hizalama için minimum veri satırı sayısı (eksik satırlar boş doldurulur)
): int {
    $totalW  = array_sum($colWidths);
    $cBarBg  = $cSecBg ?? $cHdrBg;

    // Başlık barı
    imagefilledrectangle($img, $sx, $sy, $sx + $totalW - 1, $sy + $barH - 1, $cBarBg);
    _brpText($img, $fontB, $fzSec, $sx, $sy, $barH, $totalW, $title, $cHdrTx, 'L');

    // Kolon başlıkları
    $cy = $sy + $barH;
    imagefilledrectangle($img, $sx, $cy, $sx + $totalW - 1, $cy + $hdrH - 1, $cHdrBg);
    $cx = $sx;
    foreach ($colLabels as $i => $lbl) {
        if ($i > 0) imageline($img, $cx, $cy, $cx, $cy + $hdrH - 1, $cDiv);
        _brpText($img, $fontB, $fzHdr, $cx, $cy, $hdrH, $colWidths[$i], $lbl, $cHdrTx, $colAligns[$i] ?? 'C');
        $cx += $colWidths[$i];
    }

    // Veri satırları
    $cy += $hdrH;
    foreach ($rows as $ri => $row) {
        $rowBg = ($ri % 2 === 1) ? $cAltBg : $cBg;
        imagefilledrectangle($img, $sx, $cy, $sx + $totalW - 1, $cy + $rowH - 1, $rowBg);
        imageline($img, $sx, $cy, $sx + $totalW - 1, $cy, $cBorder);
        $cx = $sx;
        foreach ($colKeys as $ki => $key) {
            $v    = (string)($row[$key] ?? ($ki === 0 ? '-' : '0'));
            $font = str_contains($key, 'toplam') ? $fontB : $fontR;
            if ($ki > 0) imageline($img, $cx, $cy, $cx, $cy + $rowH - 1, $cBorder);
            _brpText($img, $font, $fzData, $cx, $cy, $rowH, $colWidths[$ki], $v, $cText, $colAligns[$ki] ?? 'C');
            $cx += $colWidths[$ki];
        }
        $cy += $rowH;
    }

    // Hizalama için boş doldurma satırları (Genel Toplam'ı en alta iter)
    for ($ri = count($rows); $ri < $minRows; $ri++) {
        $rowBg = ($ri % 2 === 1) ? $cAltBg : $cBg;
        imagefilledrectangle($img, $sx, $cy, $sx + $totalW - 1, $cy + $rowH - 1, $rowBg);
        imageline($img, $sx, $cy, $sx + $totalW - 1, $cy, $cBorder);
        $cx = $sx;
        foreach ($colWidths as $ki => $w) {
            if ($ki > 0) imageline($img, $cx, $cy, $cx, $cy + $rowH - 1, $cBorder);
            $cx += $w;
        }
        $cy += $rowH;
    }

    // Genel toplam satırı
    imageline($img, $sx, $cy, $sx + $totalW - 1, $cy, $cBorder);
    imagefilledrectangle($img, $sx, $cy, $sx + $totalW - 1, $cy + $totH - 1, $cHdrBg);
    _brpText($img, $fontB, $fzData, $sx, $cy, $totH, $colWidths[0], 'Genel Toplam', $cHdrTx, 'L');
    $cx = $sx + $colWidths[0];
    foreach (array_slice($colKeys, 1) as $ti => $key) {
        $tv = array_sum(array_column($rows, $key));
        imageline($img, $cx, $cy, $cx, $cy + $totH - 1, $cDiv);
        _brpText($img, $fontB, $fzData, $cx, $cy, $totH, $colWidths[$ti + 1], (string)$tv, $cHdrTx, 'C');
        $cx += $colWidths[$ti + 1];
    }
    $cy += $totH;

    // Dış kenarlık
    imagerectangle($img, $sx, $sy, $sx + $totalW - 1, $cy - 1, $cBorder);
    return $cy;
}

/**
 * Genel 2 kolonlu tablo bölümü çizer (İSİM | DEĞER). Reklam Gideri ve Satış Başı
 * Maliyet tablolarında kullanılır. Değerler önceden formatlanmış string gelir.
 * $rows: [['ad' => string, 'deger' => string], ...]  $toplamDeger: null ise toplam satırı çizilmez.
 * Alt Y koordinatını döndürür.
 */
function _brpIkiKolon(
    $img, string $fontR, string $fontB,
    int $sx, int $sy, int $colW, int $c1W,
    string $title, string $c1Label, string $c2Label,
    array $rows, ?string $toplamDeger,
    int $barH, int $hdrH, int $rowH, int $totH,
    float $fzSec, float $fzHdr, float $fzData,
    $cHdrBg, $cHdrTx, $cBg, $cAltBg, $cBorder, $cText, $cDiv, $cSecBg,
    int $minRows, string $bosMesaj
): int {
    $c2W = $colW - $c1W;

    // Başlık barı
    imagefilledrectangle($img, $sx, $sy, $sx + $colW - 1, $sy + $barH - 1, $cSecBg);
    _brpText($img, $fontB, $fzSec, $sx, $sy, $barH, $colW, $title, $cHdrTx, 'L');
    $cy = $sy + $barH;

    // Kolon başlıkları
    imagefilledrectangle($img, $sx, $cy, $sx + $colW - 1, $cy + $hdrH - 1, $cHdrBg);
    _brpText($img, $fontB, $fzHdr, $sx, $cy, $hdrH, $c1W, $c1Label, $cHdrTx, 'L');
    imageline($img, $sx + $c1W, $cy, $sx + $c1W, $cy + $hdrH - 1, $cDiv);
    _brpText($img, $fontB, $fzHdr, $sx + $c1W, $cy, $hdrH, $c2W, $c2Label, $cHdrTx, 'C');
    $cy += $hdrH;

    if (empty($rows)) {
        imageline($img, $sx, $cy, $sx + $colW - 1, $cy, $cBorder);
        imagefilledrectangle($img, $sx, $cy, $sx + $colW - 1, $cy + $rowH - 1, $cBg);
        _brpText($img, $fontR, $fzData, $sx, $cy, $rowH, $colW, $bosMesaj, $cText, 'C');
        $cy += $rowH;
    } else {
        foreach ($rows as $ri => $row) {
            $rowBg = ($ri % 2 === 1) ? $cAltBg : $cBg;
            imagefilledrectangle($img, $sx, $cy, $sx + $colW - 1, $cy + $rowH - 1, $rowBg);
            imageline($img, $sx, $cy, $sx + $colW - 1, $cy, $cBorder);
            _brpText($img, $fontR, $fzData, $sx, $cy, $rowH, $c1W, (string)($row['ad'] ?? '-'), $cText, 'L');
            imageline($img, $sx + $c1W, $cy, $sx + $c1W, $cy + $rowH - 1, $cBorder);
            _brpText($img, $fontB, $fzData, $sx + $c1W, $cy, $rowH, $c2W, (string)($row['deger'] ?? '-'), $cText, 'C');
            $cy += $rowH;
        }
        // Hizalama için boş doldurma satırları
        for ($ri = count($rows); $ri < $minRows; $ri++) {
            $rowBg = ($ri % 2 === 1) ? $cAltBg : $cBg;
            imagefilledrectangle($img, $sx, $cy, $sx + $colW - 1, $cy + $rowH - 1, $rowBg);
            imageline($img, $sx, $cy, $sx + $colW - 1, $cy, $cBorder);
            imageline($img, $sx + $c1W, $cy, $sx + $c1W, $cy + $rowH - 1, $cBorder);
            $cy += $rowH;
        }
    }

    // Genel toplam satırı
    if ($toplamDeger !== null) {
        imageline($img, $sx, $cy, $sx + $colW - 1, $cy, $cBorder);
        imagefilledrectangle($img, $sx, $cy, $sx + $colW - 1, $cy + $totH - 1, $cHdrBg);
        _brpText($img, $fontB, $fzData, $sx, $cy, $totH, $c1W, 'Genel Toplam', $cHdrTx, 'L');
        imageline($img, $sx + $c1W, $cy, $sx + $c1W, $cy + $totH - 1, $cDiv);
        _brpText($img, $fontB, $fzData, $sx + $c1W, $cy, $totH, $c2W, $toplamDeger, $cHdrTx, 'C');
        $cy += $totH;
    }

    imagerectangle($img, $sx, $sy, $sx + $colW - 1, $cy - 1, $cBorder);
    return $cy;
}

/**
 * VoIP günlük ücret — bayi-gunluk-rapor.php'deki voipSorgu ile birebir aynı.
 * Odemeler (OdemeTuruId=4) → telefon → VoIPHesaplar → KullaniciBirimYetkileri (ödeme
 * tarihi yetki aralığında) → KullaniciBirim. Birime göre gruplar, toplam ücreti döner.
 */
function voipGunlukUcret($db, string $tarih, ?string $bitis = null): array
{
    $bitis ??= $tarih;
    return $db->fetchAll("
        SELECT
            ISNULL(b.birim, 'Bilinmeyen') AS bayi,
            SUM(CAST(o.Odemeler_Tutar AS DECIMAL(18,4))) AS ucret
        FROM Odemeler o
        OUTER APPLY (
            SELECT TOP 1 kb.KullaniciBirim_Adi AS birim
            FROM VoIPHesaplar vh
            JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_VoIPHesap_id = vh.VoIPHesaplar_id
            JOIN KullaniciBirim kb ON kby.KullaniciBirimYetkileri_Birim_id = kb.KullaniciBirim_id
            WHERE vh.VoIPHesaplar_TelefonNo = o.Odemeler_Referans
              AND vh.Durum = 1 AND kby.Durum = 1
              AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= o.Odemeler_Tarih)
              AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= o.Odemeler_Tarih)
            ORDER BY kby.KullaniciBirimYetkileri_id DESC
        ) b
        WHERE o.Odemeler_OdemeTuruId = 4
          AND CAST(o.Odemeler_Tarih AS DATE) BETWEEN ? AND ?
        GROUP BY ISNULL(b.birim, 'Bilinmeyen')
        ORDER BY ucret DESC
    ", [$tarih, $bitis]);
}

/**
 * Reklam gideri (OdemeTuruId=3) → Birim'e göre toplam tutar.
 * Odemeler_Aciklama (act_id) → ReklamHesaplari (HesapID) → KullaniciBirimYetkileri
 * (ReklamHesap hedefi, ödeme tarihi yetki aralığında) → KullaniciBirim.
 */
function reklamGiderBirim($db, string $tarih): array
{
    return $db->fetchAll("
        SELECT
            b.birim AS bayi,
            SUM(CAST(o.Odemeler_Tutar AS DECIMAL(18,4))) AS tutar
        FROM Odemeler o
        CROSS APPLY (
            SELECT TOP 1 kb.KullaniciBirim_Adi AS birim
            FROM ReklamHesaplari rh
            JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_ReklamHesap_id = rh.ReklamHesaplari_id
            JOIN KullaniciBirim kb ON kby.KullaniciBirimYetkileri_Birim_id = kb.KullaniciBirim_id
            WHERE rh.ReklamHesaplari_HesapID = o.Odemeler_Aciklama
              AND rh.Durum = 1 AND kby.Durum = 1
              AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= o.Odemeler_Tarih)
              AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= o.Odemeler_Tarih)
            ORDER BY kby.KullaniciBirimYetkileri_id DESC
        ) b
        WHERE o.Odemeler_OdemeTuruId = 3
          AND CAST(o.Odemeler_Tarih AS DATE) = ?
        GROUP BY b.birim
        ORDER BY tutar DESC
    ", [$tarih]);
}

/**
 * Birim'e göre ONAY adedi (ISP+NEO+UYDU). IrisRapor bayisi → DigiturkAltBayiler (isim)
 * → KullaniciBirimYetkileri (AltBayi hedefi) → KullaniciBirim.
 */
function onayAdetBirim($db, string $tarih): array
{
    return $db->fetchAll("
        SELECT
            kb.KullaniciBirim_Adi AS birim,
            COUNT(*) AS adet
        FROM dbo.DigiturkIrisRapor ir
        JOIN DigiturkAltBayiler a ON a.DigiturkAltBayiler_Ad = ir.IrisRapor_TalebiGirenPersonelAltbayi
        JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_AltBayi_id = a.DigiturkAltBayiler_Id AND kby.Durum = 1
        JOIN KullaniciBirim kb ON kby.KullaniciBirimYetkileri_Birim_id = kb.KullaniciBirim_id
        WHERE CAST(ir.IrisRapor_TalepGirisTarihi AS DATE) = ?
          AND ir.IrisRapor_TeyitDurum = 'ONAYLANDI'
          AND ir.IrisRapor_MemoKayitTipi IN ('ISP','NEO','UYDU')
        GROUP BY kb.KullaniciBirim_Adi
    ", [$tarih]);
}

/**
 * Satış başı maliyet = Birim reklam gideri / Birim onay adedi.
 * $reklam: reklamGiderBirim() çıktısı, $onay: onayAdetBirim() çıktısı.
 * Döner: [['bayi'=>birim, 'maliyet'=>float|null, 'adet'=>int, 'tutar'=>float], ...]
 */
function satisBasiMaliyet(array $reklam, array $onay): array
{
    $adetMap = [];
    foreach ($onay as $r) $adetMap[$r['birim']] = (int)$r['adet'];

    $sonuc = [];
    foreach ($reklam as $r) {
        $birim = $r['bayi'];
        $tutar = (float)$r['tutar'];
        $adet  = $adetMap[$birim] ?? 0;
        $sonuc[] = [
            'bayi'    => $birim,
            'maliyet' => $adet > 0 ? $tutar / $adet : null,
            'adet'    => $adet,
            'tutar'   => $tutar,
        ];
    }
    return $sonuc;
}

/**
 * Bayi günlük raporu için PNG görsel üretir; ham PNG verisi döndürür.
 */
function bayiRaporGorselCiz(array $satis, array $kurulum, array $onay, array $voip, string $tarih, ?string $raporTarihi, ?string $donem = null): string
{
    // $donem verilirse (tarih aralığı raporu) başlıklardaki "GÜNLÜK" kalkar, dönem alt bilgiye yazılır
    $bp = $donem ? 'BAYİ ' : 'BAYİ GÜNLÜK ';
    if (!function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('PHP GD kütüphanesi aktif değil.');
    }

    $fontR = __DIR__ . '/../assets/fonts/arial.ttf';
    $fontB = __DIR__ . '/../assets/fonts/arialbd.ttf';
    if (!file_exists($fontR)) throw new RuntimeException("Font bulunamadı: {$fontR}");
    if (!file_exists($fontB)) $fontB = $fontR;

    // Boyutlar
    $pad  = 20; $gap  = 20; $colW = 660;
    $W    = $pad * 2 + $colW * 2 + $gap; // 1380
    $barH = 36; $hdrH = 28; $rowH = 26; $totH = 30; $footH = 26;

    // Yazı boyutları (punto)
    $fzSec  = 11.0; $fzHdr = 9.0; $fzData = 9.0; $fzFoot = 8.5;

    // 5 kolonlu tablo tanımları (SATIŞ / KURULUM / ONAY)
    $cols5W = [300, 80, 80, 88, 112]; // toplam = $colW (660)
    $cols5L = ['BAYİ İSMİ', 'ISP', 'NEO', 'UYDU', 'TV TOPLAM'];
    $cols5A = ['L', 'C', 'C', 'C', 'C'];
    $cols5K = ['bayi', 'isp', 'neo', 'uydu', 'toplam'];

    // Bölüm yükseklikleri
    // Sıra bazlı maksimum satır sayısı (Genel Toplam'ları hizalamak için)
    $ustMax = max(count($satis), count($kurulum));
    $altMax = max(count($onay), count($voip));
    $secFix = fn(int $n) => $barH + $hdrH + max(1, $n) * $rowH + $totH;
    $topH = $secFix($ustMax);
    $botH = $secFix($altMax);
    $H    = $pad + $topH + $gap + $botH + $gap + $footH + $pad;

    $img = imagecreatetruecolor($W, $H);

    // Renkler
    $cBg     = imagecolorallocate($img, 255, 255, 255);
    $cHdrBg  = imagecolorallocate($img, 42,  100, 150);  // kolon başlığı + toplam satırı (mavi)
    $cSecBg  = imagecolorallocate($img, 31,  56,  100);  // bölüm başlık barı (lacivert)
    $cHdrTx  = imagecolorallocate($img, 255, 255, 255);
    $cBorder = imagecolorallocate($img, 206, 212, 218);
    $cText   = imagecolorallocate($img, 33,  37,  41);
    $cAltBg  = imagecolorallocate($img, 248, 249, 250);
    $cDiv    = imagecolorallocate($img, 90,  140, 175);

    imagefill($img, 0, 0, $cBg);

    $x1 = $pad;
    $x2 = $pad + $colW + $gap;
    $y1 = $pad;
    $y2 = $pad + $topH + $gap;

    $args = [$barH, $hdrH, $rowH, $totH, $fzSec, $fzHdr, $fzData, $cHdrBg, $cHdrTx, $cBg, $cAltBg, $cBorder, $cText, $cDiv, $cSecBg];

    _brpSection($img, $fontR, $fontB, $x1, $y1, $bp . 'SATIŞ ADET',   $cols5L, $cols5W, $cols5A, $cols5K, $satis,   ...$args, minRows: $ustMax);
    _brpSection($img, $fontR, $fontB, $x2, $y1, $bp . 'KURULUM ADET',  $cols5L, $cols5W, $cols5A, $cols5K, $kurulum, ...$args, minRows: $ustMax);
    _brpSection($img, $fontR, $fontB, $x1, $y2, $bp . 'ONAY ADET',     $cols5L, $cols5W, $cols5A, $cols5K, $onay,    ...$args, minRows: $altMax);

    // VoIP bölümü (2 kolonlu)
    $vx = $x2; $vy = $y2;
    $vC1 = 430; $vC2 = $colW - $vC1;
    imagefilledrectangle($img, $vx, $vy, $vx + $colW - 1, $vy + $barH - 1, $cSecBg);
    _brpText($img, $fontB, $fzSec, $vx, $vy, $barH, $colW, $bp . 'VoIP ÜCRET', $cHdrTx, 'L');
    $vy += $barH;
    imagefilledrectangle($img, $vx, $vy, $vx + $colW - 1, $vy + $hdrH - 1, $cHdrBg);
    _brpText($img, $fontB, $fzHdr, $vx, $vy, $hdrH, $vC1, 'BAYİ İSMİ', $cHdrTx, 'L');
    imageline($img, $vx + $vC1, $vy, $vx + $vC1, $vy + $hdrH - 1, $cDiv);
    _brpText($img, $fontB, $fzHdr, $vx + $vC1, $vy, $hdrH, $vC2, 'ÜCRET', $cHdrTx, 'C');
    $vy += $hdrH;

    if (empty($voip)) {
        imageline($img, $vx, $vy, $vx + $colW - 1, $vy, $cBorder);
        imagefilledrectangle($img, $vx, $vy, $vx + $colW - 1, $vy + $rowH - 1, $cBg);
        _brpText($img, $fontR, $fzData, $vx, $vy, $rowH, $colW, 'Bu tarihe ait VoIP verisi bulunamadı', $cText, 'C');
        $vy += $rowH;
    } else {
        $voipToplam = 0.0;
        foreach ($voip as $ri => $row) {
            $rowBg = ($ri % 2 === 1) ? $cAltBg : $cBg;
            imagefilledrectangle($img, $vx, $vy, $vx + $colW - 1, $vy + $rowH - 1, $rowBg);
            imageline($img, $vx, $vy, $vx + $colW - 1, $vy, $cBorder);
            $ucret = (float)($row['ucret'] ?? 0);
            $voipToplam += $ucret;
            _brpText($img, $fontR, $fzData, $vx, $vy, $rowH, $vC1, (string)($row['bayi'] ?? '-'), $cText, 'L');
            imageline($img, $vx + $vC1, $vy, $vx + $vC1, $vy + $rowH - 1, $cBorder);
            _brpText($img, $fontB, $fzData, $vx + $vC1, $vy, $rowH, $vC2, number_format($ucret, 2, ',', '.') . ' TL', $cText, 'C');
            $vy += $rowH;
        }
        // Hizalama için boş doldurma satırları (Genel Toplam'ı onay tablosuyla aynı hizaya iter)
        for ($ri = count($voip); $ri < $altMax; $ri++) {
            $rowBg = ($ri % 2 === 1) ? $cAltBg : $cBg;
            imagefilledrectangle($img, $vx, $vy, $vx + $colW - 1, $vy + $rowH - 1, $rowBg);
            imageline($img, $vx, $vy, $vx + $colW - 1, $vy, $cBorder);
            imageline($img, $vx + $vC1, $vy, $vx + $vC1, $vy + $rowH - 1, $cBorder);
            $vy += $rowH;
        }
        // Genel Toplam satırı
        imageline($img, $vx, $vy, $vx + $colW - 1, $vy, $cBorder);
        imagefilledrectangle($img, $vx, $vy, $vx + $colW - 1, $vy + $totH - 1, $cHdrBg);
        _brpText($img, $fontB, $fzData, $vx, $vy, $totH, $vC1, 'Genel Toplam', $cHdrTx, 'L');
        imageline($img, $vx + $vC1, $vy, $vx + $vC1, $vy + $totH - 1, $cDiv);
        _brpText($img, $fontB, $fzData, $vx + $vC1, $vy, $totH, $vC2, number_format($voipToplam, 2, ',', '.') . ' TL', $cHdrTx, 'C');
        $vy += $totH;
    }
    imagerectangle($img, $x2, $y2, $x2 + $colW - 1, $vy - 1, $cBorder);

    // Alt bilgi (sağa hizalı)
    $footY   = $H - $footH - $pad;
    $footTxt = ($donem ? "Dönem: {$donem}   |   " : '') . 'Rapor Tarihi: ' . ($raporTarihi ?? date('d.m.Y H:i'));
    _brpText($img, $fontR, $fzFoot, $x1, $footY, $footH, $W - $pad * 2, $footTxt, $cText, 'R');

    // PNG'ye aktar
    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    imagedestroy($img);

    return $png;
}

/**
 * WhatsApp Rapor Bildirim görevi.
 * params: tarih (d.m.Y), bitis (d.m.Y, opsiyonel — verilirse tarih..bitis aralık raporu),
 *         telefon (virgüllü liste), saat_bas (0-23), saat_bitis (0-23)
 */
function gorevWhatsappRaporBildir(array $params, $db): array
{
    ob_start();
    set_time_limit(180);

    $sonuc = ''; $durum = 1;

    try {
        require_once __DIR__ . '/../admin/includes/EntegrasyonHelper.php';

        // Saat aralığı kontrolü
        $saatBas    = isset($params['saat_bas'])    ? (int)$params['saat_bas']    : 0;
        $saatBitis  = isset($params['saat_bitis'])  ? (int)$params['saat_bitis']  : 23;
        $simdikiSaat = (int)date('G');
        if ($simdikiSaat < $saatBas || $simdikiSaat > $saatBitis) {
            echo "  Saat {$simdikiSaat}:xx → aralık dışında ({$saatBas}-{$saatBitis}), atlandı.\n";
            return ['durum' => 1, 'sonuc' => "Saat aralığı dışında ({$simdikiSaat}:xx), gönderim atlandı.", 'cikti' => ob_get_clean()];
        }

        // Tarih parametresi
        $tarihParam = trim($params['tarih'] ?? '');
        if ($tarihParam && DateTime::createFromFormat('d.m.Y', $tarihParam)) {
            $tarih = DateTime::createFromFormat('d.m.Y', $tarihParam)->format('Y-m-d');
        } else {
            $tarih = date('Y-m-d', strtotime('-1 day'));
        }
        $tarihDisplay = DateTime::createFromFormat('Y-m-d', $tarih)->format('d.m.Y');

        // Bitiş parametresi (opsiyonel): verilirse tarih..bitis aralığı raporlanır
        $bitis      = $tarih;
        $bitisParam = trim($params['bitis'] ?? '');
        if ($bitisParam && ($bitisDt = DateTime::createFromFormat('d.m.Y', $bitisParam))) {
            $bitis = max($tarih, $bitisDt->format('Y-m-d'));
        }
        $aralik = $bitis !== $tarih;
        if ($aralik) {
            $tarihDisplay .= ' - ' . DateTime::createFromFormat('Y-m-d', $bitis)->format('d.m.Y');
        }
        echo "  Rapor tarihi: {$tarihDisplay}\n";

        // Alıcılar
        $telefonListesi = array_filter(array_map('trim', explode(',', $params['telefon'] ?? '')));
        if (empty($telefonListesi)) {
            return ['durum' => 2, 'sonuc' => 'Alıcı listesi boş.', 'cikti' => ob_get_clean()];
        }

        // Pivot sorgular (bayi-gunluk-rapor.php ile birebir aynı)
        $satis = $db->fetchAll("
            SELECT
                IrisRapor_TalebiGirenPersonelAltbayi AS bayi,
                ISNULL([ISP],  0) AS isp,
                ISNULL([NEO],  0) AS neo,
                ISNULL([UYDU], 0) AS uydu,
                ISNULL([NEO],  0) + ISNULL([UYDU], 0) AS toplam
            FROM (
                SELECT IrisRapor_TalebiGirenPersonelAltbayi, IrisRapor_MemoKayitTipi
                FROM dbo.DigiturkIrisRapor
                WHERE CAST(IrisRapor_TalepGirisTarihi AS DATE) BETWEEN ? AND ?
            ) src
            PIVOT (COUNT(IrisRapor_MemoKayitTipi) FOR IrisRapor_MemoKayitTipi IN ([ISP], [NEO], [UYDU])) AS PV
            ORDER BY toplam DESC
        ", [$tarih, $bitis]);

        $kurulum = $db->fetchAll("
            SELECT
                IrisRapor_TalebiGirenPersonelAltbayi AS bayi,
                ISNULL([ISP],  0) AS isp,
                ISNULL([NEO],  0) AS neo,
                ISNULL([UYDU], 0) AS uydu,
                ISNULL([NEO],  0) + ISNULL([UYDU], 0) AS toplam
            FROM (
                SELECT IrisRapor_TalebiGirenPersonelAltbayi, IrisRapor_MemoKayitTipi
                FROM dbo.DigiturkIrisRapor
                WHERE CAST(IrisRapor_MemoKapanisTarihi AS DATE) BETWEEN ? AND ?
                  AND IrisRapor_SatisDurumu = 'Tamamlandı'
            ) src
            PIVOT (COUNT(IrisRapor_MemoKayitTipi) FOR IrisRapor_MemoKayitTipi IN ([ISP], [NEO], [UYDU])) AS PV
            ORDER BY toplam DESC
        ", [$tarih, $bitis]);

        $onay = $db->fetchAll("
            SELECT
                IrisRapor_TalebiGirenPersonelAltbayi AS bayi,
                ISNULL([ISP],  0) AS isp,
                ISNULL([NEO],  0) AS neo,
                ISNULL([UYDU], 0) AS uydu,
                ISNULL([NEO],  0) + ISNULL([UYDU], 0) AS toplam
            FROM (
                SELECT IrisRapor_TalebiGirenPersonelAltbayi, IrisRapor_MemoKayitTipi
                FROM dbo.DigiturkIrisRapor
                WHERE CAST(IrisRapor_TalepGirisTarihi AS DATE) BETWEEN ? AND ?
                  AND IrisRapor_TeyitDurum = 'ONAYLANDI'
            ) src
            PIVOT (COUNT(IrisRapor_MemoKayitTipi) FOR IrisRapor_MemoKayitTipi IN ([ISP], [NEO], [UYDU])) AS PV
            ORDER BY toplam DESC
        ", [$tarih, $bitis]);

        $voip = voipGunlukUcret($db, $tarih, $bitis);

        $sonKayit    = $db->fetchOne("SELECT MAX(GuncellemeTarihi) AS son_tarih FROM dbo.DigiturkIrisRapor WHERE CAST(IrisRapor_TalepGirisTarihi AS DATE) BETWEEN ? AND ?", [$tarih, $bitis]);
        $raporTarihi = $sonKayit['son_tarih'] ? date('d.m.Y H:i', strtotime($sonKayit['son_tarih'])) : null;

        // Görsel üret
        echo "  Görsel oluşturuluyor...\n";
        $png = bayiRaporGorselCiz($satis, $kurulum, $onay, $voip, $tarih, $raporTarihi, $aralik ? $tarihDisplay : null);
        echo "  Görsel boyutu: " . strlen($png) . " bayt\n";

        $caption  = ($aralik ? '📊 Bayi Dönem Raporu — ' : '📊 Bayi Günlük Rapor — ') . $tarihDisplay;
        if ($raporTarihi) $caption .= "\n🕐 Son güncelleme: {$raporTarihi}";

        $basarili = $hatali = 0;
        foreach ($telefonListesi as $alici) {
            $r = EntegrasyonHelper::whatsappResimGonder(1, $alici, $png, $caption);
            if ($r['success']) {
                $basarili++;
                echo "  ✓ {$alici}: gönderildi\n";
            } else {
                $hatali++;
                echo "  ✗ {$alici}: {$r['message']}\n";
            }
        }

        $sonuc = "{$tarihDisplay} raporu {$basarili} alıcıya gönderildi" . ($hatali ? ", {$hatali} hata" : '') . '.';
        $durum = ($hatali > 0 && $basarili === 0) ? 2 : 1;

    } catch (Throwable $e) {
        $sonuc = get_class($e) . ': ' . $e->getMessage();
        $durum = 2;
    }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

/**
 * Sayfası eşleşmeyen (ReklamLeadFormlari_Sayfa_id IS NULL) lead formları için
 * tek seferlik destek talebi açar.
 *
 * "Bir kerelik" güvencesi EntegrasyonLoglari üzerinden sağlanır: talep açılan her
 * form için Tip='lead_form_eslesmeyen' bir satır düşer, sonraki turlarda NOT EXISTS
 * ile elenir. Talep API'si hata verse bile satır yazılır (ticket_id=0) — aksi halde
 * 15 dakikada bir yeniden denenip talep yağmuruna dönerdi.
 *
 * @return array ['cikti' => string, 'mesaj' => string]
 */
function metaEslesmeyenFormBildir($db, array $params): array
{
    $cikti = '';
    $L = function (string $m) use (&$cikti) { $cikti .= '  ' . $m . "\n"; };

    $formlar = $db->fetchAll("
        SELECT f.ReklamLeadFormlari_id,
               f.ReklamLeadFormlari_FormID,
               f.ReklamLeadFormlari_FormAdi,
               f.ReklamLeadFormlari_FormDurumu,
               f.OlusturmaTarihi
        FROM ReklamLeadFormlari f
        WHERE f.Durum = 1
          AND f.ReklamLeadFormlari_Sayfa_id IS NULL
          AND NOT EXISTS (
                SELECT 1 FROM EntegrasyonLoglari l
                WHERE l.EntegrasyonLoglari_Tip   = 'lead_form_eslesmeyen'
                  AND l.EntegrasyonLoglari_Alici = f.ReklamLeadFormlari_FormID
          )
        ORDER BY f.ReklamLeadFormlari_id
    ");

    if (!$formlar) {
        return ['cikti' => '', 'mesaj' => ''];
    }

    $adet = count($formlar);
    $L("⚑ Sayfası eşleşmeyen {$adet} yeni lead formu bulundu.");

    require_once __DIR__ . '/../admin/includes/DestekHelper.php';

    $kullaniciId = (int)($params['kullanici_id'] ?? 0);
    $kategoriId  = (int)($params['kategori_id'] ?? 0);
    $oncelikId   = (int)($params['oncelik_id']  ?? 0);

    $destekAktif = $kullaniciId > 0
        && DestekHelper::aktifMi()
        && DestekHelper::kullaniciAta($kullaniciId);

    $ticketId = 0;
    if (!$destekAktif) {
        $L('✗ Destek API kullanılamıyor veya kullanici_id parametresi yok — talep açılamadı.');
    } else {
        $satirlar = '';
        foreach ($formlar as $f) {
            $satirlar .= '- ' . ($f['ReklamLeadFormlari_FormAdi'] ?: '(adsız)')
                       . ' | Form ID: ' . $f['ReklamLeadFormlari_FormID']
                       . ' | Durum: ' . ($f['ReklamLeadFormlari_FormDurumu'] ?: '-')
                       . "\n";
        }

        $mesajMetni = "Meta'dan yeni lead formu/formları geldi, ancak hangi Facebook sayfasına ait olduğu "
                    . "otomatik eşleştirilemedi. Bu formlardan gelen başvurular sayfa bazlı raporlarda eksik görünür.\n\n"
                    . "Eşleşmeyen formlar ({$adet} adet):\n"
                    . $satirlar . "\n"
                    . "Yapılması gereken: Reklam Yönetimi → Lead Formları sekmesinden ilgili formların "
                    . "Facebook sayfası elle seçilmelidir.\n\n"
                    . "Olası neden: Formun ait olduğu sayfa henüz ReklamFacebookSayfalari tablosunda yok "
                    . "veya sayfanın Page Token'ı tanımlı değil.\n\n"
                    . "Meta Reklam Senkronizasyonu görevi tarafından otomatik açılmıştır.";

        $konu = $adet === 1
            ? 'Lead formu sayfa eşleşmesi yapılamadı — ' . ($formlar[0]['ReklamLeadFormlari_FormAdi'] ?: $formlar[0]['ReklamLeadFormlari_FormID'])
            : "Lead formu sayfa eşleşmesi yapılamadı — {$adet} form";

        $r = DestekHelper::olustur($konu, $mesajMetni, $kategoriId, $oncelikId);
        if ($r['success'] ?? false) {
            $ticketId = (int)($r['data']['ticket_id'] ?? 0);
            $L("✓ Destek talebi açıldı: #{$ticketId}");
        } else {
            $L('✗ Destek talebi yanıtı hatalı: ' . ($r['message'] ?? 'bilinmeyen hata')
               . ' — talep açılmış olabilir, tekrar denenmeyecek.');
        }
    }

    // İşaret satırları: talep açılsın ya da açılmasın yazılır (mükerrer bildirimi önler)
    $kanal = $db->fetchOne("
        SELECT TOP 1 k.EntegrasyonKanallari_id
        FROM EntegrasyonKanallari k
        INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
        WHERE e.Entegrasyonlar_Tip = 'meta' AND k.Durum = 1 AND e.Durum = 1
        ORDER BY k.EntegrasyonKanallari_id
    ");
    $kanalId = (int)($kanal['EntegrasyonKanallari_id'] ?? 0);
    $simdi   = date('Y-m-d H:i:s');

    foreach ($formlar as $f) {
        $db->insert('EntegrasyonLoglari', [
            'EntegrasyonLoglari_Kanal_id'       => $kanalId ?: null,
            'EntegrasyonLoglari_Tip'            => 'lead_form_eslesmeyen',
            'EntegrasyonLoglari_Alici'          => $f['ReklamLeadFormlari_FormID'],
            'EntegrasyonLoglari_Konu'           => mb_substr((string)($f['ReklamLeadFormlari_FormAdi'] ?? ''), 0, 200),
            'EntegrasyonLoglari_Mesaj'          => null,
            'EntegrasyonLoglari_GonderimDurumu' => $ticketId > 0 ? 'basarili' : 'hata',
            'EntegrasyonLoglari_HataMesaj'      => $ticketId > 0 ? null : 'Destek talebi açılamadı',
            'EntegrasyonLoglari_IstekVerisi'    => json_encode([
                'ticket_id' => $ticketId,
                'form_id'   => $f['ReklamLeadFormlari_FormID'],
                'form_adi'  => $f['ReklamLeadFormlari_FormAdi'],
                'kayit_id'  => (int)$f['ReklamLeadFormlari_id'],
                'tarih'     => $simdi,
            ], JSON_UNESCAPED_UNICODE),
            'EntegrasyonLoglari_CevapVerisi'    => null,
            'EntegrasyonLoglari_GonderimTarihi' => $simdi,
            'OlusturanKullanici'                => $kullaniciId ?: 1,
            'OlusturmaTarihi'                   => $simdi,
            'Durum'                             => 1,
        ]);
    }

    $mesaj = $ticketId > 0
        ? "| Eşleşmeyen form: {$adet} adet, talep #{$ticketId} açıldı."
        : "| Eşleşmeyen form: {$adet} adet, talep AÇILAMADI.";

    return ['cikti' => $cikti, 'mesaj' => $mesaj];
}

// ─── Dispatcher ──────────────────────────────────────────────────────────────
function gorevMetaReklamSenkronize(array $params, $db): array
{
    ob_start();
    set_time_limit(600);

    try {
        require_once dirname(__DIR__) . '/admin/includes/MetaReklamSync.php';

        // 1) Meta Ads verilerini senkronize et (hesap / kampanya / sayfa / form)
        $ozet = MetaReklamSync::tumunuSenkronize(1);
        foreach (['hesap' => 'Hesap', 'kampanya' => 'Kampanya', 'sayfa' => 'Sayfa', 'form' => 'Form'] as $k => $ad) {
            $s = $ozet[$k] ?? [];
            echo sprintf("  ✓ %-9s: %d eklendi, %d güncellendi (toplam %d)\n",
                $ad, $s['eklenen'] ?? 0, $s['guncellenen'] ?? 0, $s['toplam'] ?? 0);
        }

        // 2) Self-healing: LeadgenID dolu ama ReklamLeadFormlari_ID NULL başvuruları onar
        $onar = MetaReklamSync::basvuruFormlariniOnar(1);
        echo sprintf("  ⟳ Başvuru onarım: %d/%d eşleşti, %d form yok, %d hata\n",
            $onar['onarilan'], $onar['toplam'], $onar['formYok'], $onar['hata']);

        // 3) Sayfası eşleşmeyen yeni lead formları için tek seferlik destek talebi
        $bildirim = metaEslesmeyenFormBildir($db, $params);
        echo $bildirim['cikti'];

        $sonuc = sprintf(
            "Senkron: hesap %d+%d, kampanya %d+%d, sayfa %d+%d, form %d+%d | Onarım: %d/%d eşleşti (%d form yok, %d hata).",
            $ozet['hesap']['eklenen'], $ozet['hesap']['guncellenen'],
            $ozet['kampanya']['eklenen'], $ozet['kampanya']['guncellenen'],
            $ozet['sayfa']['eklenen'], $ozet['sayfa']['guncellenen'],
            $ozet['form']['eklenen'], $ozet['form']['guncellenen'],
            $onar['onarilan'], $onar['toplam'], $onar['formYok'], $onar['hata']
        );
        if ($bildirim['mesaj'] !== '') $sonuc .= ' ' . $bildirim['mesaj'];
        $durum = 1;
    } catch (Throwable $e) {
        $sonuc = get_class($e) . ': ' . $e->getMessage();
        $durum = 2;
    }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

function gorevMetaGunlukHarcamaOdeme(array $params, $db): array
{
    ob_start();
    set_time_limit(600);

    try {
        require_once dirname(__DIR__) . '/admin/includes/MetaReklamSync.php';

        // "Reklam Gideri" ödeme türü isimle çözülür; bulunamazsa 3'e düşer.
        $tur = $db->fetchOne("SELECT TOP 1 OdemeTurleri_Id FROM OdemeTurleri WHERE OdemeTurleri_Ad = N'Reklam Gideri' AND Durum = 1");
        $odemeTuruId = (int)($tur['OdemeTurleri_Id'] ?? 3);

        $bugun      = date('Y-m-d');
        $simdi      = date('Y-m-d H:i:s');
        $harcamalar = MetaReklamSync::gunlukHarcamalar('today');

        $eklenen = 0; $guncellenen = 0; $atlanan = 0;
        foreach ($harcamalar as $h) {
            if ($h['spend'] <= 0) { $atlanan++; continue; }   // 0 harcamayı atla

            // Mükerrer kontrol: aynı gün + tür + act_id (Aciklama alanı)
            $mevcut = $db->fetchOne("
                SELECT Odemeler_Id FROM Odemeler
                WHERE Odemeler_OdemeTuruId = ?
                  AND CONVERT(date, Odemeler_Tarih) = ?
                  AND Odemeler_Aciklama = ?
            ", [$odemeTuruId, $bugun, $h['act_id']]);

            $veri = [
                'Odemeler_OdemeTuruId'       => $odemeTuruId,
                'Odemeler_Tutar'             => $h['spend'],
                'Odemeler_Tarih'             => $bugun,
                'Odemeler_Referans'          => $h['ad'],
                'Odemeler_Aciklama'          => $h['act_id'],
                'Odemeler_KullaniciBirim_id' => $h['birim_id'],
            ];

            if ($mevcut) {
                $veri['GuncelleyenKullanici'] = 1;
                $veri['GuncellemeTarihi']     = $simdi;
                $db->update('Odemeler', $veri, ['Odemeler_Id' => $mevcut['Odemeler_Id']]);
                $guncellenen++;
                echo sprintf("  ⟳ %-30s %12s %s (güncellendi)\n", $h['ad'], number_format($h['spend'], 2, ',', '.'), $h['currency']);
            } else {
                $veri['Durum']                = 1;
                $veri['OlusturanKullanici']   = 1;
                $veri['OlusturmaTarihi']      = $simdi;
                $veri['GuncelleyenKullanici'] = 1;
                $veri['GuncellemeTarihi']     = $simdi;
                $db->insert('Odemeler', $veri);
                $eklenen++;
                echo sprintf("  ✓ %-30s %12s %s (eklendi)\n", $h['ad'], number_format($h['spend'], 2, ',', '.'), $h['currency']);
            }
        }

        $sonuc = sprintf(
            "Meta günlük harcama → Odemeler: %d eklendi, %d güncellendi, %d atlandı (tarih %s, tür #%d).",
            $eklenen, $guncellenen, $atlanan, $bugun, $odemeTuruId
        );
        $durum = 1;
    } catch (Throwable $e) {
        $sonuc = get_class($e) . ': ' . $e->getMessage();
        $durum = 2;
    }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

/**
 * İşletme portföyünün ACTIVE + borçlu reklam hesaplarını Graph'tan çekip
 * ödeme kartı/kaynağı bazında toplam borç özetini döner (guncel-borc-bakiyeleri.php ile aynı mantık).
 * @return array ['kartlar'=>[kart=>['adet','borc','cur']], 'genelBorc'=>float, 'cur'=>string, 'hata'=>?string]
 */
function reklamBorcOzeti($db, string $bizId): array
{
    $kanal = $db->fetchOne("
        SELECT k.EntegrasyonKanallari_Sifre AS Token,
               e.Entegrasyonlar_BaseURL     AS BaseURL,
               e.Entegrasyonlar_ApiKey      AS AppSecret
        FROM EntegrasyonKanallari k
        INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
        WHERE e.Entegrasyonlar_Tip = 'meta' AND k.Durum = 1 AND e.Durum = 1
        ORDER BY k.EntegrasyonKanallari_id
    ");
    if (!$kanal) return ['kartlar' => [], 'genelBorc' => 0.0, 'cur' => 'TRY', 'hata' => 'Aktif Meta kanalı bulunamadı (EntegrasyonKanallari).'];

    $baseURL = rtrim($kanal['BaseURL'], '/');
    $token   = (string)$kanal['Token'];
    $proof   = hash_hmac('sha256', $token, (string)$kanal['AppSecret']);

    $cek = function (string $edge) use ($baseURL, $token, $proof): array {
        $url = $baseURL . '/' . $edge . '?' . http_build_query([
            'fields'          => 'id,name,account_status,currency,balance,funding_source_details',
            'access_token'    => $token,
            'appsecret_proof' => $proof,
            'limit'           => 200,
        ]);
        $rows = []; $guv = 0;
        while ($url && $guv < 50) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false]);
            $c   = curl_exec($ch);
            $ok  = (curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400 && !curl_error($ch));
            $j   = json_decode($c, true) ?: [];
            if (!$ok) break;
            foreach (($j['data'] ?? []) as $x) $rows[] = $x;
            $url = $j['paging']['next'] ?? null; $guv++;
        }
        return $rows;
    };

    $hesaplar = array_merge($cek($bizId . '/owned_ad_accounts'), $cek($bizId . '/client_ad_accounts'));

    $kartlar = []; $genel = 0.0; $cur = 'TRY';
    foreach ($hesaplar as $a) {
        if ((int)($a['account_status'] ?? 0) !== 1) continue;         // yalnız ACTIVE
        $borc = ((float)($a['balance'] ?? 0)) / 100;                   // kuruş → ana birim
        if ($borc <= 0) continue;                                      // borcu olmayanı atla
        $cur  = $a['currency'] ?? $cur;
        $fs   = $a['funding_source_details'] ?? null;
        $kart = $fs['display_string'] ?? ($fs['type'] ?? 'Tanımsız');
        if (preg_match('/(\d{2,})\s*$/', (string)$kart, $m)) $kart = $m[1]; // "Mastercard *1234" → "1234"
        if (!isset($kartlar[$kart])) $kartlar[$kart] = ['adet' => 0, 'borc' => 0.0, 'cur' => $a['currency'] ?? $cur];
        $kartlar[$kart]['adet']++;
        $kartlar[$kart]['borc'] += $borc;
        $genel += $borc;
    }
    uasort($kartlar, fn($x, $y) => $y['borc'] <=> $x['borc']);
    return ['kartlar' => $kartlar, 'genelBorc' => $genel, 'cur' => $cur, 'hata' => null];
}

/**
 * Reklam hesap borçlarını kart bazında toplayıp tek numaraya WhatsApp ile bildirir.
 * Gönderimden önce Meta verilerini senkronize eder. Eşik yoktur.
 * params: hedef_no (tek numara), biz (portföy id), kdv (oran %), wa_kanal (WhatsApp kanal id), dry (1=test)
 */
function gorevReklamBorcBildir(array $params, $db): array
{
    ob_start();
    set_time_limit(600);

    $isDry = !empty($params['dry']);
    $L = function (string $m) use ($isDry) {
        echo '[' . date('H:i:s') . '] ' . ($isDry ? '[DRY] ' : '') . $m . PHP_EOL;
    };

    $sonuc = ''; $durum = 1;
    try {
        require_once __DIR__ . '/../admin/includes/EntegrasyonHelper.php';

        $hedefNo  = trim((string)($params['hedef_no'] ?? ''));
        $bizId    = preg_replace('/[^0-9]/', '', (string)($params['biz'] ?? '100000000000001'));
        $kdvOrani = isset($params['kdv'])      ? (float)$params['kdv']    : 20;
        $waKanal  = isset($params['wa_kanal']) ? (int)$params['wa_kanal'] : 1;
        $ekstra   = isset($params['ekstra'])   ? (float)$params['ekstra'] : 5000; // kart başına eklenecek tutar

        if ($isDry) $L('========== DRY RUN — senkron, DB ve WhatsApp YAPILMAZ ==========');

        // 1) Gönderimden önce güncel verileri senkronize et (dry'da atlanır)
        if (!$isDry) {
            require_once dirname(__DIR__) . '/admin/includes/MetaReklamSync.php';
            $s = MetaReklamSync::tumunuSenkronize(1);
            $L(sprintf('Senkron: hesap %d+%d, kampanya %d+%d, sayfa %d+%d, form %d+%d',
                $s['hesap']['eklenen'], $s['hesap']['guncellenen'],
                $s['kampanya']['eklenen'], $s['kampanya']['guncellenen'],
                $s['sayfa']['eklenen'], $s['sayfa']['guncellenen'],
                $s['form']['eklenen'], $s['form']['guncellenen']));
        } else {
            $L('Senkron atlandı (dry).');
        }

        // 2) Kart bazında borç özeti
        $ozet = reklamBorcOzeti($db, $bizId);
        if ($ozet['hata']) throw new RuntimeException($ozet['hata']);
        $kartlar = $ozet['kartlar']; $genelBorc = $ozet['genelBorc']; $cur = $ozet['cur'];
        $genelKdvli = $genelBorc * (1 + $kdvOrani / 100);

        // 3) Mesajı kur
        $fmt = fn(float $v) => number_format($v, 2, ',', '.');
        $mesaj = "Günaydın, aşağıdaki kartlara bakiye alabilir miyim?\n\n";
        if (empty($kartlar)) {
            $mesaj .= "Borçlu aktif hesap yok.";
        } else {
            foreach ($kartlar as $kart => $o) {
                $tutar = $o['borc'] * (1 + $kdvOrani / 100) + $ekstra; // KDV dahil + kart başına ekstra
                $mesaj .= "• Kart *" . $kart . ": " . $fmt($tutar) . " " . $o['cur'] . "\n";
            }
            $mesaj = rtrim($mesaj);
        }
        $L('Hazırlanan mesaj:');
        echo $mesaj . PHP_EOL;

        // 4) Gönder
        if ($isDry) {
            $L($hedefNo !== '' ? "Gönderim atlandı (dry). Hedef: {$hedefNo}" : "Gönderim atlandı (dry). Alıcı numara girilmemiş — gerçek çalıştırmada zorunlu.");
            $sonuc = 'DRY RUN: mesaj hazırlandı, gönderilmedi (' . count($kartlar) . ' kart).';
        } elseif ($hedefNo === '') {
            $sonuc = 'Alıcı numara (hedef_no) tanımlı değil, gönderim yapılmadı.';
            $durum = 2;
        } else {
            $r = EntegrasyonHelper::whatsappGonder($waKanal, $hedefNo, $mesaj);
            if (!empty($r['success'])) {
                $L("✓ Gönderildi: {$hedefNo}");
                $sonuc = "WhatsApp gönderildi ({$hedefNo}). " . count($kartlar) . ' kart, toplam ' . $fmt($genelKdvli) . ' ' . $cur . ' (KDV dahil).';
                $durum = 1;
            } else {
                $L("✗ Hata: " . ($r['message'] ?? '?'));
                $sonuc = 'WhatsApp gönderilemedi: ' . ($r['message'] ?? 'bilinmeyen hata');
                $durum = 2;
            }
        }
    } catch (Throwable $e) {
        $sonuc = get_class($e) . ': ' . $e->getMessage();
        $durum = 2;
    }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

/**
 * Verilen tarih penceresi için bayi/kampanya bazında hakediş hesaplar.
 * (hakedis-hesaplama.php 'hesapla' mantığından uyarlanmıştır)
 *
 *  - Uygun kayıt: GuncelOutletDurum + SatisDurumu filtresi
 *  - Pencere: MemoKapanisTarihi ∈ [$bas .. $bitisHaric)  (üst sınır hariç)
 *  - Skala kademesi: bayinin BU PENCEREDEKİ toplam uygun adedine göre (2000+/1000+/500+/altı)
 *  - Tutar: DigiturkHakedisTanimlari, $ay/$yil döneminden
 *
 * $tumKampanyalar=true ise satışı olmayan kampanyalar da 0 adet ile listelenir
 * (aylık bölüm bayinin tüm tanımlı kampanya setini gösterir).
 *
 * Dönüş: ['bayiler' => [ad => [toplam, kademe, kampanyalar[], hakedis]], 'toplam' => float, 'adet' => int]
 */
function hakedisPencereHesapla(
    $db, string $bas, string $bitisHaric, array $birimAdlari,
    string $outlet, string $satis, int $ay, int $yil, bool $tumKampanyalar = false
): array {
    $qp = [$bas, $bitisHaric];
    $durumFilter = '';
    if ($outlet !== '') { $durumFilter .= " AND r.IrisRapor_GuncelOutletDurum = ?"; $qp[] = $outlet; }
    if ($satis  !== '') { $durumFilter .= " AND r.IrisRapor_SatisDurumu = ?";       $qp[] = $satis; }
    $birimPh = implode(',', array_fill(0, count($birimAdlari), '?'));
    foreach ($birimAdlari as $ba) $qp[] = $ba;

    $sql = "
        ;WITH uygun AS (
            SELECT
                r.IrisRapor_TalebiGirenPersonelAltbayi AS Altbayi,
                ISNULL(bm.birim, N'(Birim Yok)')       AS Birim,
                ab.altbayiId                            AS AltBayiId,
                r.IrisRapor_TalepTuru                   AS TalepTuru,
                r.IrisRapor_MemoKodu                    AS MemoKodu,
                r.IrisRapor_Kampanya                    AS Kampanya
            FROM dbo.DigiturkIrisRapor r
            OUTER APPLY (
                SELECT TOP 1 a.DigiturkAltBayiler_Id AS altbayiId
                FROM DigiturkAltBayiler a
                WHERE a.DigiturkAltBayiler_Ad = r.IrisRapor_TalebiGirenPersonelAltbayi AND a.Durum = 1
                ORDER BY a.DigiturkAltBayiler_Id DESC
            ) ab
            OUTER APPLY (
                SELECT TOP 1 kb.KullaniciBirim_Adi AS birim
                FROM DigiturkAltBayiler a
                JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_AltBayi_id = a.DigiturkAltBayiler_Id
                JOIN KullaniciBirim kb           ON kb.KullaniciBirim_id = kby.KullaniciBirimYetkileri_Birim_id
                WHERE a.DigiturkAltBayiler_Ad = r.IrisRapor_TalebiGirenPersonelAltbayi
                  AND a.Durum = 1 AND kby.Durum = 1
                  AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
                  AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
                ORDER BY kby.KullaniciBirimYetkileri_id DESC
            ) bm
            WHERE r.IrisRapor_MemoKapanisTarihi >= ?
              AND r.IrisRapor_MemoKapanisTarihi < ?
              {$durumFilter}
        ),
        bayiToplam AS (
            SELECT Altbayi, COUNT(*) AS Toplam,
                CASE WHEN COUNT(*) >= 2000 THEN 1
                     WHEN COUNT(*) >= 1000 THEN 2
                     WHEN COUNT(*) >= 500  THEN 3
                     ELSE 4 END AS SkalaKodu
            FROM uygun
            GROUP BY Altbayi
        ),
        kampanyaAdet AS (
            SELECT
                u.Altbayi, u.Birim,
                MAX(u.AltBayiId)                          AS AltBayiId,
                t.HakedisKampanyaTanimlari_KampanyaAdi   AS KampanyaAdi,
                COUNT(*)                                 AS Adet
            FROM uygun u
            JOIN dbo.HakedisKampanyaTanimlari t
              ON u.TalepTuru = t.HakedisKampanyaTanimlari_TalepTuru
             AND u.MemoKodu  = t.HakedisKampanyaTanimlari_MemoKodu
             AND t.Durum = 1
             AND (
                 (t.HakedisKampanyaTanimlari_Kampanya IS NOT NULL
                     AND u.Kampanya = t.HakedisKampanyaTanimlari_Kampanya)
                 OR
                 (t.HakedisKampanyaTanimlari_Kampanya IS NULL
                     AND NOT EXISTS (
                         SELECT 1 FROM dbo.HakedisKampanyaTanimlari t3
                         WHERE t3.HakedisKampanyaTanimlari_TalepTuru = t.HakedisKampanyaTanimlari_TalepTuru
                           AND t3.HakedisKampanyaTanimlari_MemoKodu  = t.HakedisKampanyaTanimlari_MemoKodu
                           AND t3.HakedisKampanyaTanimlari_Kampanya IS NOT NULL
                           AND t3.HakedisKampanyaTanimlari_Kampanya = u.Kampanya
                           AND t3.Durum = 1
                     ))
             )
            GROUP BY u.Altbayi, u.Birim, t.HakedisKampanyaTanimlari_KampanyaAdi
        )
        SELECT
            ka.Birim, ka.Altbayi, ka.AltBayiId, ka.KampanyaAdi, ka.Adet,
            bt.Toplam, bt.SkalaKodu,
            (SELECT MAX(h.DigiturkHakedisTanimlari_Tutar)
               FROM dbo.DigiturkHakedisTanimlari h
               JOIN dbo.HakedisKampanyaTanimlari kt2
                 ON kt2.HakedisKampanyaTanimlari_id = h.DigiturkHakedisTanimlari_KampanyaTanim_id
               WHERE h.DigiturkHakedisTanimlari_AltBayi_id     = ka.AltBayiId
                 AND h.DigiturkHakedisTanimlari_DonemAy         = {$ay}
                 AND h.DigiturkHakedisTanimlari_DonemYil        = {$yil}
                 AND kt2.HakedisKampanyaTanimlari_KampanyaAdi   = ka.KampanyaAdi
                 AND kt2.Durum = 1
                 AND h.DigiturkHakedisTanimlari_AdetSkalasiKodu = bt.SkalaKodu
                 AND h.Durum = 1) AS BirimTutar
        FROM kampanyaAdet ka
        JOIN bayiToplam bt ON bt.Altbayi = ka.Altbayi
        WHERE ka.Birim IN ({$birimPh})
        ORDER BY ka.Altbayi, ka.KampanyaAdi
    ";
    $rows = $db->fetchAll($sql, $qp);

    $bayiler = [];
    $genelHakedis = 0.0; $genelAdet = 0;
    foreach ($rows as $p) {
        $bAd = trim((string)$p['Altbayi']);
        if (!isset($bayiler[$bAd])) {
            $bayiler[$bAd] = [
                'toplam'      => (int)$p['Toplam'],
                'kademe'      => (int)$p['SkalaKodu'],
                'altBayiId'   => $p['AltBayiId'] !== null ? (int)$p['AltBayiId'] : null,
                'kampanyalar' => [],
                'hakedis'     => 0.0,
            ];
        }
        $adet = (int)$p['Adet'];
        $tut  = $p['BirimTutar'] !== null ? (float)$p['BirimTutar'] : null;
        $hk   = $tut !== null ? $adet * $tut : 0.0;
        $bayiler[$bAd]['kampanyalar'][(string)$p['KampanyaAdi']] = ['adet' => $adet, 'birimTutar' => $tut, 'hakedis' => $hk];
        $bayiler[$bAd]['hakedis'] += $hk;
        $genelHakedis += $hk;
        $genelAdet    += $adet;
    }

    // Satışı olmayan kampanyaları da 0 adet ile ekle (bayinin dönem/kademe tutar seti)
    if ($tumKampanyalar) {
        foreach ($bayiler as $bAd => &$b) {
            if ($b['altBayiId'] === null) continue;
            $tanimlar = $db->fetchAll("
                SELECT kt.HakedisKampanyaTanimlari_KampanyaAdi AS KampanyaAdi,
                       MAX(h.DigiturkHakedisTanimlari_Tutar)   AS Tutar
                FROM dbo.DigiturkHakedisTanimlari h
                JOIN dbo.HakedisKampanyaTanimlari kt
                  ON kt.HakedisKampanyaTanimlari_id = h.DigiturkHakedisTanimlari_KampanyaTanim_id
                WHERE h.DigiturkHakedisTanimlari_AltBayi_id     = ?
                  AND h.DigiturkHakedisTanimlari_DonemAy         = ?
                  AND h.DigiturkHakedisTanimlari_DonemYil        = ?
                  AND h.DigiturkHakedisTanimlari_AdetSkalasiKodu = ?
                  AND h.Durum = 1
                  AND kt.Durum = 1
                GROUP BY kt.HakedisKampanyaTanimlari_KampanyaAdi
            ", [$b['altBayiId'], $ay, $yil, $b['kademe']]);
            foreach ($tanimlar as $t) {
                $kAd = (string)$t['KampanyaAdi'];
                if (isset($b['kampanyalar'][$kAd])) continue;
                $b['kampanyalar'][$kAd] = [
                    'adet'       => 0,
                    'birimTutar' => $t['Tutar'] !== null ? (float)$t['Tutar'] : null,
                    'hakedis'    => 0.0,
                ];
            }
            ksort($b['kampanyalar'], SORT_NATURAL | SORT_FLAG_CASE);
        }
        unset($b);
    }

    return ['bayiler' => $bayiler, 'toplam' => $genelHakedis, 'adet' => $genelAdet];
}

/**
 * Seçilen birime ait haftalık + aylık hakediş özetini, birimin gelir/gider
 * kalemlerini ve güncel bakiyeyi WhatsApp grubuna/numarasına metin olarak gönderir.
 *
 *  - Haftalık pencere: [bugün-geri_gun .. dün]
 *  - Aylık pencere: bugünün içinde olduğu dönem — DigiturkHakedisTanimlari'ndaki
 *    BaslangicTarihi/BitisTarihi kolonlarından okunur (ayın 4'ü → sonraki ayın 3'ü)
 *  - Gelir/gider: Odemeler (tüm zamanlar), giderler eksi işaretli
 *  - Bakiye = (Gelir - Gider) + Aylık Hakediş Toplamı
 *
 * params: birim (KullaniciBirim_id), hedef (grup JID/telefon), geri_gun (vars.7),
 *         wa_kanal (vars.1), outlet (vars.AKTIF), satis (vars.Tamamlandı), dry (1=test)
 */
function gorevHakedisGrubaBildir(array $params, $db): array
{
    ob_start();
    set_time_limit(300);

    $isDry = !empty($params['dry']);
    $L = function (string $m) use ($isDry) {
        echo '[' . date('H:i:s') . '] ' . ($isDry ? '[DRY] ' : '') . $m . PHP_EOL;
    };

    $sonuc = ''; $durum = 1;
    try {
        require_once __DIR__ . '/../admin/includes/EntegrasyonHelper.php';

        if ($isDry) $L('========== DRY RUN — WhatsApp gönderimi YAPILMAZ ==========');

        $birimId = (int)($params['birim'] ?? 0);
        $hedef   = trim((string)($params['hedef'] ?? ''));
        $geriGun = isset($params['geri_gun']) ? max(1, (int)$params['geri_gun']) : 7;
        $waKanal = isset($params['wa_kanal']) ? (int)$params['wa_kanal'] : 1;
        $outlet  = trim((string)($params['outlet'] ?? 'AKTIF'));
        $satis   = trim((string)($params['satis']  ?? 'Tamamlandı'));

        if (!$birimId) { return ['durum' => 2, 'sonuc' => 'Birim (birim) parametresi zorunludur.', 'cikti' => ob_get_clean()]; }

        // Haftalık pencere: [bugün-geriGun .. dün]  (MemoKapanisTarihi >= bas AND < bugün)
        $bas    = date('Y-m-d', strtotime("-{$geriGun} days"));
        $bugunT = date('Y-m-d');
        $bitG   = date('Y-m-d', strtotime('-1 day'));

        // Aylık dönem: bugünü kapsayan dönem, DigiturkHakedisTanimlari'ndan okunur.
        // Ayın 1-4'ünde önceki dönem hâlâ açık olduğu için date('n') kullanılmaz.
        $donem = $db->fetchOne("
            SELECT TOP 1
                DigiturkHakedisTanimlari_DonemAy                              AS Ay,
                DigiturkHakedisTanimlari_DonemYil                             AS Yil,
                CONVERT(VARCHAR(10), DigiturkHakedisTanimlari_BaslangicTarihi, 23) AS Bas,
                CONVERT(VARCHAR(10), DigiturkHakedisTanimlari_BitisTarihi, 23)     AS Bit
            FROM dbo.DigiturkHakedisTanimlari
            WHERE DigiturkHakedisTanimlari_BaslangicTarihi <= ?
              AND DigiturkHakedisTanimlari_BitisTarihi     >= ?
            ORDER BY DigiturkHakedisTanimlari_BaslangicTarihi DESC
        ", [$bugunT, $bugunT]);

        if ($donem) {
            $ay = (int)$donem['Ay']; $yil = (int)$donem['Yil'];
            $ayBas = $donem['Bas'];  $ayBit = $donem['Bit'];
        } else {
            // Tanım yoksa donemTarihleri() kuralına düş: ayın 4'ü → sonraki ayın 3'ü
            $ay  = (int)date('j') < 4 ? (int)date('n', strtotime('-1 month')) : (int)date('n');
            $yil = (int)date('j') < 4 ? (int)date('Y', strtotime('-1 month')) : (int)date('Y');
            $ayBas = sprintf('%04d-%02d-04', $yil, $ay);
            $ayBit = date('Y-m-d', strtotime("{$ayBas} +1 month -1 day"));
            $L("UYARI: {$bugunT} tarihini kapsayan hakediş dönemi tanımı yok, varsayılan kurala düşüldü.");
        }
        $ayBitHaric = date('Y-m-d', strtotime("{$ayBit} +1 day"));

        $L("Birim #{$birimId} | Haftalık: " . date('d.m.Y', strtotime($bas)) . " – " . date('d.m.Y', strtotime($bitG))
            . " | Aylık dönem: " . date('d.m.Y', strtotime($ayBas)) . " – " . date('d.m.Y', strtotime($ayBit)) . " ({$ay}/{$yil})");

        // Birim adı + alt birim ağacı → birim adları (ka.Birim IN ...)
        $birim = $db->fetchOne("SELECT KullaniciBirim_Adi FROM dbo.KullaniciBirim WHERE KullaniciBirim_id = ? AND Durum = 1", [$birimId]);
        if (!$birim) { return ['durum' => 2, 'sonuc' => "Birim #{$birimId} bulunamadı.", 'cikti' => ob_get_clean()]; }
        $birimAdi = $birim['KullaniciBirim_Adi'];

        $agac = $db->fetchAll("
            ;WITH birimAgaci AS (
                SELECT KullaniciBirim_id FROM dbo.KullaniciBirim WHERE KullaniciBirim_id = ?
                UNION ALL
                SELECT k.KullaniciBirim_id FROM dbo.KullaniciBirim k
                JOIN birimAgaci b ON k.KullaniciBirim_UstBirim_id = b.KullaniciBirim_id
            )
            SELECT DISTINCT kb.KullaniciBirim_id AS id, kb.KullaniciBirim_Adi AS ad
            FROM birimAgaci a JOIN dbo.KullaniciBirim kb ON kb.KullaniciBirim_id = a.KullaniciBirim_id
        ", [$birimId]);
        $birimAdlari = array_values(array_filter(array_map(fn($r) => $r['ad'], $agac)));
        $birimIdleri = array_values(array_map(fn($r) => (int)$r['id'], $agac));
        if (empty($birimAdlari)) { return ['durum' => 2, 'sonuc' => "Birim #{$birimId} için birim adı çözülemedi.", 'cikti' => ob_get_clean()]; }

        // Haftalık + aylık hakediş (ortak motor; aylıkta tüm kampanyalar 0 adetle listelenir)
        $haftalik = hakedisPencereHesapla($db, $bas, $bugunT, $birimAdlari, $outlet, $satis, $ay, $yil, false);
        $aylik    = hakedisPencereHesapla($db, $ayBas, $ayBitHaric, $birimAdlari, $outlet, $satis, $ay, $yil, true);

        $L('Haftalık: ' . count($haftalik['bayiler']) . ' bayi, ' . $haftalik['adet'] . ' adet | '
            . 'Aylık: ' . count($aylik['bayiler']) . ' bayi, ' . $aylik['adet'] . ' adet');

        // Ödemeler (odemeler.php ile aynı mantık): birim ağacındaki tüm zamanların
        // gelir/gider kalemleri, ödeme türü bazında. Giderler eksi işaretli gösterilir.
        $odemeler = [];
        $topGelir = 0.0; $topGider = 0.0;
        if (!empty($birimIdleri)) {
            $oPh = implode(',', array_fill(0, count($birimIdleri), '?'));
            $odemeler = $db->fetchAll("
                SELECT
                    ISNULL(t.OdemeTurleri_Ad, N'(Tür Yok)') AS Tur,
                    ISNULL(t.OdemeTurleri_GelirMi, 0)       AS GelirMi,
                    SUM(o.Odemeler_Tutar)                   AS Tutar
                FROM dbo.Odemeler o
                LEFT JOIN dbo.OdemeTurleri t ON o.Odemeler_OdemeTuruId = t.OdemeTurleri_Id
                WHERE o.Odemeler_KullaniciBirim_id IN ({$oPh})
                GROUP BY t.OdemeTurleri_Ad, t.OdemeTurleri_GelirMi
                ORDER BY t.OdemeTurleri_GelirMi DESC, t.OdemeTurleri_Ad
            ", $birimIdleri);
            foreach ($odemeler as $o) {
                $tt = (float)$o['Tutar'];
                if ((int)$o['GelirMi'] === 1) $topGelir += $tt; else $topGider += $tt;
            }
        }
        $netOdeme     = $topGelir - $topGider;          // giderler eksi
        $guncelBakiye = $netOdeme + $aylik['toplam'];   // aylık hakediş bakiyeye eklenir

        $L('Ödemeler: ' . count($odemeler) . ' tür kalemi | Net: ' . number_format($netOdeme, 0, ',', '.')
            . ' ₺ | Bakiye: ' . number_format($guncelBakiye, 0, ',', '.') . ' ₺');

        // ── Mesajı kur (düz WhatsApp metni; tutarlar kuruşsuz + binlik ayraçlı) ──
        $fmt      = fn(float $v) => number_format(round($v), 0, ',', '.');
        $SKALALAR = [1 => '2000+', 2 => '1000-2000', 3 => '500-1000', 4 => '0-500'];

        // Bir hakediş bölümünü (bayi listesi + ara toplamlar + genel toplam) metne döker
        $bolum = function (array $sonuc, string $toplamEtiketi) use ($fmt, $SKALALAR): string {
            if (empty($sonuc['bayiler'])) return "\nBu dönemde eşleşen kampanya bulunamadı.\n";
            $t = '';
            foreach ($sonuc['bayiler'] as $bAd => $b) {
                $t .= "\n🏪 *{$bAd}* (Kademe: " . ($SKALALAR[$b['kademe']] ?? '-') . ")\n";
                foreach ($b['kampanyalar'] as $kAd => $c) {
                    if ($c['birimTutar'] === null) {
                        $t .= "  • {$kAd}: {$c['adet']} adet (tutar tanımsız)\n";
                    } else {
                        $t .= "  • {$kAd}: {$c['adet']}x" . $fmt($c['birimTutar']) . " ₺=" . $fmt($c['hakedis']) . " ₺\n";
                    }
                }
                $adetB = array_sum(array_column($b['kampanyalar'], 'adet'));
                $t .= "  ➜ Ara Toplam: {$adetB} adet " . $fmt($b['hakedis']) . " ₺\n";
            }
            $t .= "💰 *{$toplamEtiketi}: {$sonuc['adet']} adet " . $fmt($sonuc['toplam']) . " ₺*\n";
            return $t;
        };

        $mesaj  = "📊 *Haftalık Hakediş — {$birimAdi}*\n";
        $mesaj .= "📅 " . date('d.m.Y', strtotime($bas)) . " - " . date('d.m.Y', strtotime($bitG)) . "\n";
        $mesaj .= $bolum($haftalik, 'Haftalık Toplam');

        $mesaj .= "\n📊 *Aylık Hakediş — {$birimAdi}* (" . date('d.m.Y', strtotime($ayBas))
                . " - " . date('d.m.Y', strtotime($ayBit)) . ")\n";
        $mesaj .= $bolum($aylik, 'Aylık Toplam');

        $mesaj .= "\n📥 *Gelirler ve Giderler*\n";
        if (empty($odemeler)) {
            $mesaj .= "  Bu birim için ödeme kaydı bulunamadı.\n";
        } else {
            foreach ($odemeler as $o) {
                $tt = (float)$o['Tutar'];
                if ((int)$o['GelirMi'] !== 1) $tt = -$tt;
                $mesaj .= "  • {$o['Tur']}: " . $fmt($tt) . " ₺\n";
            }
        }
        $mesaj .= "  ➜ Toplam Gelirler ve Giderler: " . $fmt($netOdeme) . " ₺\n";

        $mesaj .= "\n🏦 *TOPLAM GÜNCEL BAKİYE: " . $fmt($guncelBakiye) . " ₺*";

        $L('Hazırlanan mesaj:');
        echo $mesaj . PHP_EOL;

        // Gönder
        if ($isDry) {
            $L($hedef !== '' ? "Gönderim atlandı (dry). Hedef: {$hedef}" : "Gönderim atlandı (dry). Hedef girilmemiş — gerçek çalıştırmada zorunlu.");
            $sonuc = 'DRY RUN: mesaj hazırlandı, gönderilmedi (' . count($haftalik['bayiler']) . ' bayi, '
                . $haftalik['adet'] . ' haftalık adet, bakiye ' . $fmt($guncelBakiye) . ' ₺).';
        } elseif ($hedef === '') {
            $sonuc = 'Hedef (hedef) tanımlı değil, gönderim yapılmadı.';
            $durum = 2;
        } else {
            $r = EntegrasyonHelper::whatsappGonder($waKanal, $hedef, $mesaj);
            if (!empty($r['success'])) {
                $L("✓ Gönderildi: {$hedef}");
                $sonuc = "WhatsApp gönderildi ({$hedef}). {$birimAdi}: " . count($haftalik['bayiler']) . ' bayi, haftalık '
                    . $haftalik['adet'] . ' adet / ' . $fmt($haftalik['toplam']) . ' ₺, aylık '
                    . $aylik['adet'] . ' adet / ' . $fmt($aylik['toplam']) . ' ₺, güncel bakiye ' . $fmt($guncelBakiye) . ' ₺.';
                $durum = 1;
            } else {
                $L("✗ Hata: " . ($r['message'] ?? '?'));
                $sonuc = 'WhatsApp gönderilemedi: ' . ($r['message'] ?? 'bilinmeyen hata');
                $durum = 2;
            }
        }
    } catch (Throwable $e) {
        $sonuc = get_class($e) . ': ' . $e->getMessage();
        $durum = 2;
    }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

/**
 * Başvuru Süreç Durum Güncelleme
 *
 * TalepKayitNo dolu başvuruların süreç durumunu ccapi Order/CheckRequisition ile
 * talep başına bir istekle sorgular (toplu uç CheckRequisitionList 08.09.2026'da
 * kaldırıldı) ve yalnızca BasvuruSurecDurum_ID alanını günceller. Sorgulanan her
 * kayda BasvuruSurecDurum_KontrolTarihi damgası atılır; böylece her çalışmada sıra
 * farklı kayıtlara ilerler.
 *
 * Parametreler:
 *   adet         : Bir çalışmada sorgulanacak kayıt (vars. 500, en fazla 2000)
 *   bekleme_gun  : Kaç gündür sorulmayanlar taransın (vars. 1, 0 = tarihe bakma)
 *   final_haric  : 1 (vars.) sonuçlanmış süreçleri atlar (BasvuruSurecDurum_Sonuclandi = 1;
 *                  Başvuru Durum Yönetimi sayfasından işaretlenir)
 *   ana_bayi_id  : Yalnız bu ana bayinin başvuruları (boş = tümü). Bayi başına
 *                  ayrı zamanlayıcı kurularak her bayinin sıklığı ayrı yönetilir.
 */
function gorevBasvuruSurecGuncelle(array $params, $db): array
{
    ob_start();
    set_time_limit(900);

    require_once dirname(__DIR__) . '/admin/includes/IrisTalepServisi.php';
    require_once dirname(__DIR__) . '/admin/includes/BasvuruLogHelper.php';

    $sonuc = ''; $durum = 1;

    try {
        $adet    = (int)($params['adet'] ?? 500);
        $adet    = max(1, min(2000, $adet ?: 500));
        $bekleme = isset($params['bekleme_gun']) && $params['bekleme_gun'] !== ''
                 ? max(0, min(365, (int)$params['bekleme_gun'])) : 1;
        $finalHaric = !isset($params['final_haric']) || $params['final_haric'] === '' || (int)$params['final_haric'] === 1;
        $anaBayiFiltre = (int)($params['ana_bayi_id'] ?? 0);

        // Kolonlar b. ile nitelenir: sorgu ana bayiyi çözmek için JOIN içeriyor
        $where = ["b.TalepKayitNo IS NOT NULL", "b.TalepKayitNo > 0"];
        if ($anaBayiFiltre > 0) {
            $where[] = "alt.DigiturkAltBayiler_AnaBayiId = {$anaBayiFiltre}";
            echo "  Ana bayi filtresi: #{$anaBayiFiltre}\n";
        }
        // Sonuçlanmış süreçler Başvuru Durum Yönetimi sayfasındaki "Sonuçlandı" bayrağından okunur
        if ($finalHaric) $where[] = "(b.BasvuruSurecDurum_ID IS NULL OR b.BasvuruSurecDurum_ID NOT IN (SELECT BasvuruSurecDurum_id FROM BasvuruSurecDurum WHERE BasvuruSurecDurum_Sonuclandi = 1))";
        if ($bekleme > 0) {
            $where[] = "(b.BasvuruSurecDurum_KontrolTarihi IS NULL
                         OR b.BasvuruSurecDurum_KontrolTarihi < DATEADD(DAY, -{$bekleme}, GETDATE()))";
        }
        $whereSql = implode(' AND ', $where);

        // Hiç sorulmamışlar önce, sonra en eski sorulanlar
        $kayitlar = $db->fetchAll("
            SELECT TOP {$adet} b.Basvurular_id, b.TalepKayitNo, b.BasvuruSurecDurum_ID, b.Basvurular_YonlendirilenBayi,
                   alt.DigiturkAltBayiler_AnaBayiId AS AnaBayiId
            FROM Basvurular b
            LEFT JOIN DigiturkAltBayiPersonel per ON per.DigiturkAltBayiPersonel_Id = b.AltBayiPersonel_ID
            LEFT JOIN DigiturkAltBayiler     alt ON alt.DigiturkAltBayiler_Id = per.DigiturkAltBayiPersonel_AltBayiId
            WHERE {$whereSql}
            ORDER BY CASE WHEN b.BasvuruSurecDurum_KontrolTarihi IS NULL THEN 0 ELSE 1 END,
                     b.BasvuruSurecDurum_KontrolTarihi ASC,
                     b.Basvurular_id ASC");

        if (!$kayitlar) {
            echo "  Sorgulanacak kayıt yok (bekleme_gun={$bekleme}).\n";
            return ['durum' => 1, 'sonuc' => 'Sorgulanacak kayıt yok.', 'cikti' => ob_get_clean()];
        }

        // Toplu uç (CheckRequisitionList) kaldırıldığı için talep başına bir istek atılır.
        // Sorgular ana bayiye göre gruplanır: her grup kendi bayisinin sorgu hesabıyla gider.
        echo "  " . count($kayitlar) . " kayıt sorgulanacak (talep başına 1 istek).\n";

        $gruplar = [];
        foreach ($kayitlar as $k) {
            $gruplar[(int)($k['AnaBayiId'] ?? 0)][] = (int)$k['TalepKayitNo'];
        }

        $apiSonuc   = [];
        $grupHatasi = [];   // sonuç metnine yazılır; aksi halde sebep yalnız ekran çıktısında kalır
        foreach ($gruplar as $anaBayiId => $nolar) {
            $hedef = $anaBayiId > 0 ? $anaBayiId : null;
            echo "  → Ana bayi " . ($hedef ?? 'varsayılan') . ": " . count($nolar) . " talep sorgulanıyor.\n";
            try {
                $apiSonuc += irisDurumSorgula($db, $nolar, $hedef);
            } catch (Throwable $ex) {
                // Limit/bağlantı hatasında bu grubu bırak, diğer bayiler devam etsin
                $grupHatasi[] = 'Ana bayi ' . ($hedef ?? 'varsayılan') . ': ' . $ex->getMessage();
                echo "  ✗ Ana bayi " . ($hedef ?? 'varsayılan') . ": " . $ex->getMessage() . "\n";
            }
        }
        echo "  API'den " . count($apiSonuc) . " talep yanıtı alındı.\n";

        $simdi = date('Y-m-d H:i:s');
        $hatali = 0;
        $sorulanlar = [];
        // Kontrol edilebilmesi için ID listeleri
        $guncellenenler = []; $degismeyenler = []; $donmeyenler = []; $hatalilar = [];

        foreach ($kayitlar as $k) {
            $id = (int)$k['Basvurular_id'];
            $no = (int)$k['TalepKayitNo'];
            $d  = $apiSonuc[$no] ?? null;
            if (!$d) { $donmeyenler[] = "#{$id}(talep {$no})"; continue; }

            $sorulanlar[] = $id;

            $yeni = (int)($d['requestStatusCode'] ?? 0);
            $eski = $k['BasvuruSurecDurum_ID'] !== null ? (int)$k['BasvuruSurecDurum_ID'] : null;
            $durumDegisti = $yeni > 0 && $yeni !== $eski;

            // Yönlendirilen bayi "kod - ad" olarak tek kolonda tutulur. Talep durumu
            // değişmeden başka bayiye yönlendirilebildiği için ayrıca karşılaştırılır;
            // ikisi de boş gelirse mevcut değere dokunulmaz.
            $bayiYeni = implode(' - ', array_filter([
                trim((string)($d['referredDealerCode'] ?? '')),
                trim((string)($d['referredDealer'] ?? '')),
            ], 'strlen'));
            $bayiYeni     = mb_substr($bayiYeni, 0, 250);
            $bayiDegisti  = $bayiYeni !== '' && $bayiYeni !== (string)($k['Basvurular_YonlendirilenBayi'] ?? '');

            if (!$durumDegisti && !$bayiDegisti) { $degismeyenler[] = $id; continue; }

            try {
                $logOncesi = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$id]);
                $guncel = [
                    'BasvuruSurecDurum_KontrolTarihi' => $simdi,
                    'GuncelleyenKullanici'            => 0,
                    'GuncellemeTarihi'                => $simdi,
                ];
                if ($durumDegisti) {
                    $guncel['BasvuruSurecDurum_ID'] = $yeni;
                    // Durum mesajı yeni süreçle uyumlu olsun (IRIS eşleştirmesi de aynı alana
                    // başlığı yazar); başlık boş gelirse eski mesaja dokunulmaz.
                    $baslik = trim((string)($d['requestStatusTitle'] ?? ''));
                    if ($baslik !== '') $guncel['BasvuruDurumMesaj'] = mb_substr($baslik, 0, 500);
                }
                if ($bayiDegisti) $guncel['Basvurular_YonlendirilenBayi'] = $bayiYeni;
                $db->update('Basvurular', $guncel, ['Basvurular_id' => $id]);
                $guncellenenler[] = $id;

                $ozet = [];
                if ($durumDegisti) $ozet[] = 'BasvuruSurecDurum_ID ' . ($eski ?? '-') . ' → ' . $yeni;
                if ($bayiDegisti)  $ozet[] = 'Yönlendirilen bayi → ' . $bayiYeni;
                $ozet = implode(', ', $ozet);

                // Başvuru geçmişinde görünsün diye formdaki "Süreç Kontrol" ile aynı iki kayıt
                // atılır (değişiklik + API yanıtı). Yalnız değişen kayıtlar loglanır;
                // değişmeyenleri de yazmak her turda ~100 gereksiz satır üretir.
                $logSonrasi = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$id]);
                basvuruLogKaydet($db, $id, 'GUNCELLE', $logOncesi, $logSonrasi, 0,
                    'Cron süreç güncelleme (CheckRequisition) — requestStatusCode=' . $yeni
                    . ' (' . ($d['requestStatusTitle'] ?? '?') . ')');
                basvuruLogApi($db, $id, 'API_SUREC', 'POST Order/CheckRequisition?RequestId=' . $no, '',
                    json_encode($d, JSON_UNESCAPED_UNICODE), 200, 0, 'Cron: ' . $ozet);
                echo "  ✓ #{$id} talep {$no}: {$ozet}\n";
            } catch (Throwable $ex) {
                $hatali++; $hatalilar[] = $id;
                echo "  ✗ #{$id} talep {$no}: " . $ex->getMessage() . "\n";
            }
        }

        $guncellenen = count($guncellenenler);
        $degismeyen  = count($degismeyenler);
        $donmeyen    = count($donmeyenler);

        // ID listeleri — çıktıdan kontrol edilebilsin
        if ($donmeyenler) {
            echo "\n  API yanıtı gelmeyen (" . count($donmeyenler) . "): " . implode(', ', $donmeyenler) . "\n";
        }
        if ($degismeyenler) {
            echo "\n  Durumu değişmeyen (" . count($degismeyenler) . "): #"
               . implode(', #', array_slice($degismeyenler, 0, 200))
               . (count($degismeyenler) > 200 ? ' … (+' . (count($degismeyenler) - 200) . ')' : '') . "\n";
        }
        if ($guncellenenler) {
            echo "\n  GÜNCELLENEN (" . count($guncellenenler) . "): #" . implode(', #', $guncellenenler) . "\n";
        }

        // Yanıt gelen tüm kayıtları damgala (durumu değişmeyenler dahil)
        if ($sorulanlar) {
            foreach (array_chunk($sorulanlar, 500) as $parca) {
                $yerTutucu = implode(',', array_fill(0, count($parca), '?'));
                $db->execute("UPDATE Basvurular SET BasvuruSurecDurum_KontrolTarihi = GETDATE()
                              WHERE Basvurular_id IN ({$yerTutucu})", $parca);
            }
        }

        $sonuc = "{$guncellenen} kayıt güncellendi, {$degismeyen} değişmedi, {$donmeyen} yanıt gelmedi"
               . ($hatali ? ", {$hatali} hata" : '') . ". (" . count($sorulanlar) . " kayıt damgalandı)";

        // API tarafındaki kesinti/limit sebebi sonuca yazılır; yoksa "yanıt gelmedi"
        // sayısı görünür ama nedeni yalnız ekran çıktısında kalır ve kaybolur.
        if ($grupHatasi) {
            $sonuc .= ' | API HATASI: ' . implode(' ; ', $grupHatasi);
        }

        // Güncellenen ID'ler sonuç metnine de yazılır (log listesinden görülebilsin)
        if ($guncellenenler) {
            $ilk = array_slice($guncellenenler, 0, 60);
            $sonuc .= ' | Güncellenen: #' . implode(', #', $ilk)
                    . (count($guncellenenler) > 60 ? ' … (+' . (count($guncellenenler) - 60) . ')' : '');
        }
        if ($hatalilar) {
            $sonuc .= ' | Hata: #' . implode(', #', array_slice($hatalilar, 0, 20));
        }
        // API grubu düştüyse tur başarısız sayılır: aksi halde "0 güncellendi,
        // 120 yanıt gelmedi" satırı panelde yeşil [OK] görünür ve kesinti gözden kaçar.
        $durum = (($hatali > 0 && $guncellenen === 0) || $grupHatasi) ? 2 : 1;

    } catch (Throwable $e) {
        $sonuc = get_class($e) . ': ' . $e->getMessage();
        $durum = 2;
    }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

// ═════════════════════════════════════════════════════════════════════════════
// Ortak Hatırlatma Motoru (CronHatirlatma*)
//
// Kural  = ne zaman / hangi koşulla çalışır, nasıl sıfırlanır
// Aşama  = kime, hangi mesaj, hangi aralıkla; nasıl bir sonrakine geçer
//
// Koda gömülü tek şey koşul kodu → fonksiyon eşlemesidir; etiketler, parametre
// formu, sıfırlama/hizalama/tetikleyici seçenekleri tanım tablolarından gelir.
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Koşul dağıtıcısı.
 * Dönüş: ['gonder'=>bool, 'tamamla'=>bool, 'degiskenler'=>array, 'not'=>string]
 *   gonder  → mesaj atılsın mı
 *   tamamla → koşul kendiliğinden sağlandı, aşama 'kosul' ile ilerletilsin
 */
function hatirlatmaKosulKontrol(string $kod, array $p, $db): array
{
    return match ($kod) {
        'sabit'       => ['gonder' => true, 'tamamla' => false, 'degiskenler' => [], 'not' => ''],
        'voip_bakiye' => hatirlatmaKosulVoipBakiye($p, $db),
        default       => throw new RuntimeException("Bilinmeyen koşul tipi: {$kod}"),
    };
}

/**
 * VoIP bakiye eşiği. Canlı login yapmaz — VoIPBakiye snapshot'ının sonuncusunu okur
 * (snapshot'ı voip_bakiye_kontrol görevi kendi zamanlamasıyla yazar).
 * params: voip_kanal_id, esik, max_yas_dakika (opsiyonel; snapshot bayatsa gönderim yapılmaz)
 */
function hatirlatmaKosulVoipBakiye(array $p, $db): array
{
    $kanalId = (int)($p['voip_kanal_id'] ?? 0);
    $esik    = (float)($p['esik'] ?? 0);
    $maxYas  = isset($p['max_yas_dakika']) && $p['max_yas_dakika'] !== '' ? (int)$p['max_yas_dakika'] : null;

    if ($kanalId <= 0) throw new RuntimeException('voip_bakiye: voip_kanal_id parametresi tanımlı değil.');

    $row = $db->fetchOne("
        SELECT TOP 1 VoIPBakiye_Bakiye,
               CONVERT(VARCHAR(19), VoIPBakiye_Tarih, 120) AS Tarih
        FROM VoIPBakiye
        WHERE VoIPBakiye_Kanal_id = ?
        ORDER BY VoIPBakiye_id DESC
    ", [$kanalId]);

    if (!$row) {
        return ['gonder' => false, 'tamamla' => false, 'degiskenler' => [],
                'not' => "Kanal #{$kanalId} için bakiye snapshot'ı yok."];
    }

    $bakiye = (float)$row['VoIPBakiye_Bakiye'];
    $fmt    = fn(float $v) => number_format($v, 2, ',', '.');
    $deg    = ['bakiye' => $fmt($bakiye), 'esik' => $fmt($esik), 'tarih' => $row['Tarih']];

    // Bayat snapshot: cron durmuşsa eski bakiyeye bakıp mesaj yağdırmayalım
    if ($maxYas !== null) {
        $yasDk = (int)floor((time() - strtotime($row['Tarih'])) / 60);
        if ($yasDk > $maxYas) {
            return ['gonder' => false, 'tamamla' => false, 'degiskenler' => $deg,
                    'not' => "Snapshot {$yasDk} dk önceki (sınır {$maxYas} dk) — gönderim bekletildi."];
        }
    }

    if ($bakiye >= $esik) {
        return ['gonder' => false, 'tamamla' => true, 'degiskenler' => $deg,
                'not' => "Bakiye eşiği geçti ({$deg['bakiye']} ≥ {$deg['esik']})."];
    }

    return ['gonder' => true, 'tamamla' => false, 'degiskenler' => $deg,
            'not' => "Bakiye eşiğin altında ({$deg['bakiye']} < {$deg['esik']})."];
}

/** Tek aşamayı tanım bilgileriyle getirir. */
function hatirlatmaAsamaGetir($db, int $asamaId): ?array
{
    return $db->fetchOne("
        SELECT a.CronHatirlatmaAsamalari_Id             AS Id,
               a.CronHatirlatmaAsamalari_KuralId        AS KuralId,
               a.CronHatirlatmaAsamalari_SiraNo         AS SiraNo,
               a.CronHatirlatmaAsamalari_Ad             AS Ad,
               a.CronHatirlatmaAsamalari_KanalId        AS KanalId,
               a.CronHatirlatmaAsamalari_HedefNo        AS HedefNo,
               a.CronHatirlatmaAsamalari_Mesaj          AS Mesaj,
               a.CronHatirlatmaAsamalari_PeriyotDakika  AS PeriyotDakika,
               CONVERT(VARCHAR(5), a.CronHatirlatmaAsamalari_BaslangicSaati, 108) AS BaslangicSaati,
               CONVERT(VARCHAR(5), a.CronHatirlatmaAsamalari_BitisSaati, 108)     AS BitisSaati,
               a.CronHatirlatmaAsamalari_MaxDeneme      AS MaxDeneme,
               a.CronHatirlatmaAsamalari_AnahtarKelime  AS AnahtarKelime,
               a.CronHatirlatmaAsamalari_SonrakiAsamaId AS SonrakiAsamaId,
               a.CronHatirlatmaAsamalari_IstenenAlanlar AS IstenenAlanlar,
               a.CronHatirlatmaAsamalari_EkGonder       AS EkGonder,
               hz.CronHatirlatmaHizalamaTurleri_Kod     AS HizalamaKod
        FROM CronHatirlatmaAsamalari a
        LEFT JOIN CronHatirlatmaHizalamaTurleri hz
               ON a.CronHatirlatmaAsamalari_HizalamaTuruId = hz.CronHatirlatmaHizalamaTurleri_Id
        WHERE a.CronHatirlatmaAsamalari_Id = ? AND a.Durum = 1
    ", [$asamaId]) ?: null;
}

/** Kuralın en küçük sıralı aktif aşaması. */
function hatirlatmaIlkAsamaGetir($db, int $kuralId): ?array
{
    $row = $db->fetchOne("
        SELECT TOP 1 CronHatirlatmaAsamalari_Id AS Id
        FROM CronHatirlatmaAsamalari
        WHERE CronHatirlatmaAsamalari_KuralId = ? AND Durum = 1
        ORDER BY CronHatirlatmaAsamalari_SiraNo, CronHatirlatmaAsamalari_Id
    ", [$kuralId]);
    return $row ? hatirlatmaAsamaGetir($db, (int)$row['Id']) : null;
}

/**
 * Sıradaki aşama: SonrakiAsamaId doluysa o (dallanma/atlama),
 * boşsa sıra numarasına göre bir sonraki aktif aşama. Yoksa null → kural tamamlanır.
 */
function hatirlatmaSonrakiAsamaGetir($db, array $asama): ?array
{
    if (!empty($asama['SonrakiAsamaId'])) {
        return hatirlatmaAsamaGetir($db, (int)$asama['SonrakiAsamaId']);
    }
    $row = $db->fetchOne("
        SELECT TOP 1 CronHatirlatmaAsamalari_Id AS Id
        FROM CronHatirlatmaAsamalari
        WHERE CronHatirlatmaAsamalari_KuralId = ? AND Durum = 1
          AND (CronHatirlatmaAsamalari_SiraNo > ?
               OR (CronHatirlatmaAsamalari_SiraNo = ? AND CronHatirlatmaAsamalari_Id > ?))
        ORDER BY CronHatirlatmaAsamalari_SiraNo, CronHatirlatmaAsamalari_Id
    ", [(int)$asama['KuralId'], (int)$asama['SiraNo'], (int)$asama['SiraNo'], (int)$asama['Id']]);
    return $row ? hatirlatmaAsamaGetir($db, (int)$row['Id']) : null;
}

/** Aşamanın izin verdiği tetikleyici kodları. */
function hatirlatmaAsamaTetikleyicileri($db, int $asamaId): array
{
    $rows = $db->fetchAll("
        SELECT t.CronHatirlatmaTetikleyiciTurleri_Kod AS Kod
        FROM CronHatirlatmaAsamaTetikleyicileri at
        INNER JOIN CronHatirlatmaTetikleyiciTurleri t
                ON at.CronHatirlatmaAsamaTetikleyicileri_TetikleyiciId = t.CronHatirlatmaTetikleyiciTurleri_Id
        WHERE at.CronHatirlatmaAsamaTetikleyicileri_AsamaId = ? AND at.Durum = 1 AND t.Durum = 1
    ", [$asamaId]);
    return array_column($rows, 'Kod');
}

/**
 * Aşamayı ilerletir; sıradaki aşama yoksa kuralı tamamlar.
 * Panel butonu, WhatsApp yanıtı, koşul sinyali ve tek seferlik aşamalar — hepsi buradan geçer.
 *
 * @param string|null $tetikleyiciKod null → otomatik (tek seferlik aşama sonrası); yetki aranmaz
 * @param array       $veri           Aşamanın IstenenAlanlar şemasına karşılık gelen değerler
 *                                    (örn. ödeme tutarı/tarihi). Sonraki aşamanın mesajında
 *                                    yer tutucu olarak kullanılır.
 * @return array ['ok'=>bool, 'mesaj'=>string, 'tamamlandi'=>bool, 'sonraki'=>?array]
 */
function hatirlatmaAsamaIlerlet($db, int $kuralId, ?string $tetikleyiciKod, ?int $kullaniciId = null, array $veri = []): array
{
    $kural = $db->fetchOne("
        SELECT CronHatirlatmaKurallari_Id AS Id,
               CronHatirlatmaKurallari_Ad AS Ad,
               CronHatirlatmaKurallari_AktifAsamaId AS AktifAsamaId,
               CronHatirlatmaKurallari_IlerletmeVerisi AS IlerletmeVerisi
        FROM CronHatirlatmaKurallari
        WHERE CronHatirlatmaKurallari_Id = ? AND Durum = 1
    ", [$kuralId]);

    if (!$kural)                     return ['ok' => false, 'mesaj' => 'Kural bulunamadı veya pasif.', 'tamamlandi' => false, 'sonraki' => null];
    if (empty($kural['AktifAsamaId'])) return ['ok' => false, 'mesaj' => 'Kuralın aktif aşaması yok (zaten tamamlanmış).', 'tamamlandi' => false, 'sonraki' => null];

    $asama = hatirlatmaAsamaGetir($db, (int)$kural['AktifAsamaId']);
    if (!$asama) return ['ok' => false, 'mesaj' => 'Aktif aşama bulunamadı veya pasif.', 'tamamlandi' => false, 'sonraki' => null];

    // Yetki: bu aşama bu tetikleyiciyle ilerletilebilir mi
    $tetikleyiciId = null;
    if ($tetikleyiciKod !== null) {
        if (!in_array($tetikleyiciKod, hatirlatmaAsamaTetikleyicileri($db, (int)$asama['Id']), true)) {
            return ['ok' => false, 'mesaj' => "Bu aşama '{$tetikleyiciKod}' tetikleyicisine kapalı.", 'tamamlandi' => false, 'sonraki' => null];
        }
        $t = $db->fetchOne("
            SELECT CronHatirlatmaTetikleyiciTurleri_Id AS Id
            FROM CronHatirlatmaTetikleyiciTurleri
            WHERE CronHatirlatmaTetikleyiciTurleri_Kod = ?
        ", [$tetikleyiciKod]);
        $tetikleyiciId = $t ? (int)$t['Id'] : null;
    }

    // Aşamanın istediği alanlar: zorunlu olanlar dolu mu, gelen veri şemada tanımlı mı
    $istenen = json_decode((string)($asama['IstenenAlanlar'] ?? ''), true) ?: [];
    $temiz   = [];
    foreach ($istenen as $alan) {
        $ad = $alan['ad'] ?? null;
        if (!$ad) continue;
        $deger = isset($veri[$ad]) ? trim((string)$veri[$ad]) : '';
        if ($deger === '' && !empty($alan['zorunlu'])) {
            return ['ok' => false, 'tamamlandi' => false, 'sonraki' => null,
                    'mesaj' => "'" . ($alan['etiket'] ?? $ad) . "' alanı zorunlu."];
        }
        if ($deger !== '') $temiz[$ad] = $deger;
    }

    // Sistem alanları şemadan bağımsız taşınır: gelen belgenin dosya bilgisi
    // (sonraki aşama "eki de ilet" seçiliyse kullanır)
    foreach (['ek_dosya', 'ek_ad', 'ek_mime'] as $sistemAlan) {
        if (!empty($veri[$sistemAlan])) $temiz[$sistemAlan] = (string)$veri[$sistemAlan];
    }

    // Önceki aşamaların verisi korunur, yenisi üzerine yazılır
    $birikmis = json_decode((string)($kural['IlerletmeVerisi'] ?? ''), true) ?: [];
    $veriJson = $temiz ? json_encode(array_merge($birikmis, $temiz), JSON_UNESCAPED_UNICODE)
                       : ($kural['IlerletmeVerisi'] ?: null);

    $simdi   = date('Y-m-d H:i:s');
    $sonraki = hatirlatmaSonrakiAsamaGetir($db, $asama);

    if ($sonraki) {
        $db->query("
            UPDATE CronHatirlatmaKurallari SET
                CronHatirlatmaKurallari_AktifAsamaId      = ?,
                CronHatirlatmaKurallari_DenemeSayisi      = 0,
                CronHatirlatmaKurallari_SonGonderimZamani = NULL,
                CronHatirlatmaKurallari_IlerletmeVerisi   = ?,
                GuncelleyenKullanici = ?, GuncellemeTarihi = ?
            WHERE CronHatirlatmaKurallari_Id = ?
        ", [(int)$sonraki['Id'], $veriJson, $kullaniciId ?? 1, $simdi, $kuralId]);

        return ['ok' => true, 'tamamlandi' => false, 'sonraki' => $sonraki,
                'mesaj' => "'{$asama['Ad']}' tamamlandı → '{$sonraki['Ad']}' aşamasına geçildi."];
    }

    $db->query("
        UPDATE CronHatirlatmaKurallari SET
            CronHatirlatmaKurallari_AktifAsamaId           = NULL,
            CronHatirlatmaKurallari_DenemeSayisi           = 0,
            CronHatirlatmaKurallari_SonGonderimZamani      = NULL,
            CronHatirlatmaKurallari_SonTamamlamaTarihi     = ?,
            CronHatirlatmaKurallari_SonTamamlamaZamani     = ?,
            CronHatirlatmaKurallari_SonTamamlayan          = ?,
            CronHatirlatmaKurallari_TamamlamaTetikleyiciId = ?,
            CronHatirlatmaKurallari_IlerletmeVerisi        = ?,
            GuncelleyenKullanici = ?, GuncellemeTarihi = ?
        WHERE CronHatirlatmaKurallari_Id = ?
    ", [date('Y-m-d'), $simdi, $kullaniciId, $tetikleyiciId, $veriJson, $kullaniciId ?? 1, $simdi, $kuralId]);

    return ['ok' => true, 'tamamlandi' => true, 'sonraki' => null,
            'mesaj' => "'{$kural['Ad']}' kuralı tamamlandı."];
}

/**
 * Kuralı doğrudan tamamlar (aşama ilerletmeden). Koşul kendiliğinden sağlandığında
 * kullanılır: "hatırlatmanın sebebi ortadan kalktı" → dönem kapanır.
 * Sıfırlama türü 'kosul' ise, koşul yeniden bozulduğunda motor kuralı ilk aşamadan başlatır.
 */
function hatirlatmaKuralTamamla($db, int $kuralId, ?string $tetikleyiciKod, ?int $kullaniciId = null): array
{
    $tetikleyiciId = null;
    if ($tetikleyiciKod !== null) {
        $t = $db->fetchOne("
            SELECT CronHatirlatmaTetikleyiciTurleri_Id AS Id
            FROM CronHatirlatmaTetikleyiciTurleri
            WHERE CronHatirlatmaTetikleyiciTurleri_Kod = ?
        ", [$tetikleyiciKod]);
        $tetikleyiciId = $t ? (int)$t['Id'] : null;
    }

    $simdi = date('Y-m-d H:i:s');
    $db->query("
        UPDATE CronHatirlatmaKurallari SET
            CronHatirlatmaKurallari_AktifAsamaId           = NULL,
            CronHatirlatmaKurallari_DenemeSayisi           = 0,
            CronHatirlatmaKurallari_SonGonderimZamani      = NULL,
            CronHatirlatmaKurallari_IlerletmeVerisi        = NULL,
            CronHatirlatmaKurallari_SonTamamlamaTarihi     = ?,
            CronHatirlatmaKurallari_SonTamamlamaZamani     = ?,
            CronHatirlatmaKurallari_SonTamamlayan          = ?,
            CronHatirlatmaKurallari_TamamlamaTetikleyiciId = ?,
            GuncelleyenKullanici = ?, GuncellemeTarihi = ?
        WHERE CronHatirlatmaKurallari_Id = ?
    ", [date('Y-m-d'), $simdi, $kullaniciId, $tetikleyiciId, $kullaniciId ?? 1, $simdi, $kuralId]);

    return ['ok' => true, 'tamamlandi' => true, 'sonraki' => null, 'mesaj' => 'Kural tamamlandı.'];
}

/** Sıfırlama zamanı geldi mi (gunluk / haftalik / aylik / sureklilik / kosul). */
function hatirlatmaSifirlamaGerekli(string $sifirlamaKod, ?string $sonSifirlama, DateTime $simdi): bool
{
    // 'kosul' takvime bakmaz; koşul yeniden bozulunca motor ayrıca sıfırlar
    if ($sifirlamaKod === 'sureklilik' || $sifirlamaKod === 'kosul') return false;
    if (!$sonSifirlama)                 return true;

    $son = substr($sonSifirlama, 0, 10);
    return match ($sifirlamaKod) {
        'gunluk'   => $son < $simdi->format('Y-m-d'),
        'haftalik' => $son < (clone $simdi)->modify('monday this week')->format('Y-m-d'),
        'aylik'    => $son < $simdi->format('Y-m-01'),
        default    => false,
    };
}

/**
 * Karşılığı olmayan yer tutucuları mesajdan temizler.
 * Örn. ödeme dekontu PDF olarak gelince tutar/tarih girilmemiş olur;
 * mesajda "{tutar}" yazısı kalmasın diye çıkarılır ve boşluklar toparlanır.
 */
function hatirlatmaMesajTemizle(string $mesaj): string
{
    $mesaj = preg_replace('/\{[A-Za-zÇĞİÖŞÜçğıöşü0-9_]+\}/u', '', $mesaj);
    $mesaj = preg_replace('/[ \t]{2,}/', ' ', $mesaj);              // çoklu boşluk
    $mesaj = preg_replace('/[ \t]*\R[ \t]*\R[ \t]*(\R)+/u', "\n\n", $mesaj); // fazla boş satır
    $satirlar = array_map('rtrim', preg_split('/\R/u', $mesaj));
    return trim(implode("\n", $satirlar));
}

/** Aşamanın gönderim penceresi içinde miyiz (saatler boşsa 7/24). */
function hatirlatmaPencereIcinde(array $asama, DateTime $simdi): bool
{
    $bas = $asama['BaslangicSaati'] ?? null;
    $bit = $asama['BitisSaati'] ?? null;
    if (!$bas && !$bit) return true;

    $ss = $simdi->format('H:i');
    if ($bas && $ss < $bas) return false;
    if ($bit && $ss > $bit) return false;
    return true;
}

/**
 * Periyot/hizalamaya göre gönderim zamanı geldi mi.
 *
 * Hizalı aşamalarda ilk gönderim de dilim başını bekler: 13:28'de kurulan
 * "tam saate hizalı" bir kural ilk mesajı 14:00'te atar, ara saatte atmaz.
 * Aşamanın başlangıç saati varsa o saat de geçerli bir dilim başı sayılır
 * (08:00 başlangıçlı kural sabah 08:00'de gönderir, 09:00'u beklemez).
 */
function hatirlatmaZamaniGeldi(array $asama, ?string $sonGonderim, DateTime $simdi): bool
{
    $periyot  = (int)$asama['PeriyotDakika'];
    $hizalama = $asama['HizalamaKod'] ?? 'baslangictan';

    // Hizalı aşamalarda geçerli dilimin başlangıcı
    $dilimBas = null;
    if ($hizalama === 'tam_saat') {
        $dilimBas = (clone $simdi)->setTime((int)$simdi->format('G'), 0, 0);
    } elseif ($hizalama === 'tam_periyot' && $periyot > 0) {
        $gecenDk  = (int)$simdi->format('G') * 60 + (int)$simdi->format('i');
        $dilimBas = (clone $simdi)->setTime(0, 0, 0)
                    ->modify('+' . (intdiv($gecenDk, $periyot) * $periyot) . ' minutes');
    }

    if (!$sonGonderim) {
        // Aşamanın ilk gönderimi
        if ($dilimBas === null) return true;                   // hizalama yok → hemen

        // Dilim başına ne kadar yakınız? Cron dakikada bir döndüğü için birkaç
        // dakikalık tolerans veriyoruz; aksi halde runner bir tik kaçırırsa
        // gönderim bir sonraki dilime sarkardı.
        $tolerans = (clone $dilimBas)->modify('+5 minutes');
        if ($simdi <= $tolerans) return true;

        // Başlangıç saati de geçerli bir dilim başıdır (08:00 kuralı 08:00'de gönderir)
        if (!empty($asama['BaslangicSaati'])) {
            [$sa, $dk] = array_map('intval', explode(':', $asama['BaslangicSaati']));
            $basAn     = (clone $simdi)->setTime($sa, $dk, 0);
            if ($simdi >= $basAn && $simdi <= (clone $basAn)->modify('+5 minutes')) return true;
        }

        return false;   // ara saatte kurulmuş → sıradaki dilim başını bekle
    }

    if ($periyot <= 0) return false;                            // tek seferlik: gönderimi yapıldı

    $son = new DateTime($sonGonderim);

    // Hizalı: dilim başı geçmiş OLMALI ve son gönderimden periyot dolmuş OLMALI
    if ($dilimBas !== null) {
        return $son < $dilimBas
            && (clone $son)->modify("+{$periyot} minutes") <= $simdi;
    }

    // Varsayılan: son gönderimin üzerine periyot eklenir
    return (clone $son)->modify("+{$periyot} minutes") <= $simdi;
}

/**
 * Hatırlatma motoru. Dakikada bir çalışır; aktif kuralları tarar, sırası gelenleri gönderir.
 * params: dry (1=test, gönderim ve DB yazımı yok), kural (tek kural id ile sınırla),
 *         zorla (1=pencere/periyot/deneme sınırını atla — panelden "Şimdi Gönder")
 */
function gorevHatirlatmaGonder(array $params, $db): array
{
    ob_start();
    set_time_limit(300);

    $isDry    = !empty($params['dry']);
    $zorla    = !empty($params['zorla']);
    $tekKural = isset($params['kural']) && $params['kural'] !== '' ? (int)$params['kural'] : null;

    $L = function (string $m) use ($isDry) {
        echo '[' . date('H:i:s') . '] ' . ($isDry ? '[DRY] ' : '') . $m . PHP_EOL;
    };

    $sonuc = ''; $durum = 1;
    try {
        require_once __DIR__ . '/../admin/includes/EntegrasyonHelper.php';

        $simdi    = new DateTime();
        $simdiStr = $simdi->format('Y-m-d H:i:s');
        $bugunGun = (int)$simdi->format('N');   // 1=Pzt .. 7=Paz

        $kurallar = $db->fetchAll("
            SELECT k.CronHatirlatmaKurallari_Id             AS Id,
                   k.CronHatirlatmaKurallari_Ad             AS Ad,
                   k.CronHatirlatmaKurallari_KosulParametre AS KosulParametre,
                   k.CronHatirlatmaKurallari_AktifAsamaId   AS AktifAsamaId,
                   k.CronHatirlatmaKurallari_DenemeSayisi   AS DenemeSayisi,
                   CONVERT(VARCHAR(19), k.CronHatirlatmaKurallari_SonGonderimZamani, 120)  AS SonGonderimZamani,
                   CONVERT(VARCHAR(10), k.CronHatirlatmaKurallari_SonSifirlamaTarihi, 120) AS SonSifirlamaTarihi,
                   k.CronHatirlatmaKurallari_IlerletmeVerisi AS IlerletmeVerisi,
                   kt.CronHatirlatmaKosulTurleri_Kod        AS KosulKod,
                   st.CronHatirlatmaSifirlamaTurleri_Kod    AS SifirlamaKod
            FROM CronHatirlatmaKurallari k
            INNER JOIN CronHatirlatmaKosulTurleri kt
                    ON k.CronHatirlatmaKurallari_KosulTuruId = kt.CronHatirlatmaKosulTurleri_Id
            INNER JOIN CronHatirlatmaSifirlamaTurleri st
                    ON k.CronHatirlatmaKurallari_SifirlamaTuruId = st.CronHatirlatmaSifirlamaTurleri_Id
            WHERE k.Durum = 1" . ($tekKural ? " AND k.CronHatirlatmaKurallari_Id = ?" : "") . "
            ORDER BY k.CronHatirlatmaKurallari_Id
        ", $tekKural ? [$tekKural] : []);

        if (!$kurallar) {
            return ['durum' => 1, 'sonuc' => 'Aktif kural yok.', 'cikti' => ob_get_clean()];
        }

        $L(count($kurallar) . ' aktif kural bulundu.');
        $gonderilen = 0; $ilerleyen = 0; $hata = 0;

        foreach ($kurallar as $kural) {
            $kuralId = (int)$kural['Id'];
            $L("Kural #{$kuralId} — {$kural['Ad']} (koşul: {$kural['KosulKod']}, sıfırlama: {$kural['SifirlamaKod']})");

            // ── 1) Dönem sıfırlaması ──────────────────────────────────────────
            if (hatirlatmaSifirlamaGerekli($kural['SifirlamaKod'], $kural['SonSifirlamaTarihi'], $simdi)) {
                $ilk = hatirlatmaIlkAsamaGetir($db, $kuralId);
                if (!$ilk) { $L('  ✗ Tanımlı aktif aşama yok, atlanıyor.'); $hata++; continue; }

                $L("  ↻ Yeni dönem — '{$ilk['Ad']}' aşamasından başlatılıyor.");
                if (!$isDry) {
                    $db->query("
                        UPDATE CronHatirlatmaKurallari SET
                            CronHatirlatmaKurallari_AktifAsamaId       = ?,
                            CronHatirlatmaKurallari_DenemeSayisi       = 0,
                            CronHatirlatmaKurallari_SonGonderimZamani  = NULL,
                            CronHatirlatmaKurallari_SonSifirlamaTarihi = ?,
                            CronHatirlatmaKurallari_IlerletmeVerisi    = NULL,
                            GuncelleyenKullanici = 1, GuncellemeTarihi = ?
                        WHERE CronHatirlatmaKurallari_Id = ?
                    ", [(int)$ilk['Id'], $simdi->format('Y-m-d'), $simdiStr, $kuralId]);
                }
                $kural['AktifAsamaId']      = (int)$ilk['Id'];
                $kural['DenemeSayisi']      = 0;
                $kural['SonGonderimZamani'] = null;
                $kural['IlerletmeVerisi']   = null;   // yeni dönem, önceki dönemin verisi taşınmaz
            }

            // ── 2) Kural bu dönem için bitti mi ───────────────────────────────
            if (empty($kural['AktifAsamaId'])) {
                // Sıfırlama 'kosul' ise takvim değil, koşulun yeniden bozulması yeni dönemi başlatır
                if ($kural['SifirlamaKod'] !== 'kosul') {
                    $L('  ✓ Tamamlanmış, sıradaki döneme kadar beklemede.');
                    continue;
                }

                $kp = json_decode((string)($kural['KosulParametre'] ?? ''), true) ?: [];
                $kk = hatirlatmaKosulKontrol($kural['KosulKod'], $kp, $db);
                if (empty($kk['gonder'])) {
                    $L('  ✓ Tamamlanmış, koşul yeniden oluşana kadar beklemede.' . ($kk['not'] !== '' ? ' ' . $kk['not'] : ''));
                    continue;
                }

                $ilk = hatirlatmaIlkAsamaGetir($db, $kuralId);
                if (!$ilk) { $L('  ✗ Tanımlı aktif aşama yok, atlanıyor.'); $hata++; continue; }

                $L("  ↻ Koşul yeniden oluştu — '{$ilk['Ad']}' aşamasından başlatılıyor.");
                if (!$isDry) {
                    $db->query("
                        UPDATE CronHatirlatmaKurallari SET
                            CronHatirlatmaKurallari_AktifAsamaId      = ?,
                            CronHatirlatmaKurallari_DenemeSayisi      = 0,
                            CronHatirlatmaKurallari_SonGonderimZamani = NULL,
                            CronHatirlatmaKurallari_IlerletmeVerisi   = NULL,
                            GuncelleyenKullanici = 1, GuncellemeTarihi = ?
                        WHERE CronHatirlatmaKurallari_Id = ?
                    ", [(int)$ilk['Id'], $simdiStr, $kuralId]);
                }
                $kural['AktifAsamaId']      = (int)$ilk['Id'];
                $kural['DenemeSayisi']      = 0;
                $kural['SonGonderimZamani'] = null;
                $kural['IlerletmeVerisi']   = null;
            }

            // ── 3) Gün filtresi ───────────────────────────────────────────────
            $gunler = array_map('intval', array_column($db->fetchAll("
                SELECT g.HaftaGunleri_GunNo AS GunNo
                FROM CronHatirlatmaKuralGunleri kg
                INNER JOIN HaftaGunleri g ON kg.CronHatirlatmaKuralGunleri_GunId = g.HaftaGunleri_Id
                WHERE kg.CronHatirlatmaKuralGunleri_KuralId = ? AND kg.Durum = 1 AND g.Durum = 1
            ", [$kuralId]), 'GunNo'));

            if ($gunler && !in_array($bugunGun, $gunler, true)) { $L('  ⏭ Bugün çalışma günü değil.'); continue; }

            $asama = hatirlatmaAsamaGetir($db, (int)$kural['AktifAsamaId']);
            if (!$asama) { $L('  ✗ Aktif aşama bulunamadı veya pasif.'); $hata++; continue; }
            $L("  Aşama #{$asama['SiraNo']} — {$asama['Ad']} → {$asama['HedefNo']}");

            // ── 4) Koşul ──────────────────────────────────────────────────────
            $kosulParam = json_decode((string)($kural['KosulParametre'] ?? ''), true) ?: [];
            $kosul      = hatirlatmaKosulKontrol($kural['KosulKod'], $kosulParam, $db);
            if ($kosul['not'] !== '') $L('  ' . $kosul['not']);

            if (!empty($kosul['tamamla'])) {
                // Hatırlatmanın sebebi ortadan kalktı → dönem kapanır (aşama ilerletilmez).
                // Aşama 'Koşul Sağlandı' tetikleyicisine kapalıysa kural olduğu yerde bekler.
                if (!in_array('kosul', hatirlatmaAsamaTetikleyicileri($db, (int)$asama['Id']), true)) {
                    $L('  ⏸ Koşul sağlandı ama bu aşama "Koşul Sağlandı" tetikleyicisine kapalı — bekliyor.');
                    continue;
                }
                if ($isDry) { $L('  [ATLANDI] Koşul sağlandı — kural tamamlanmadı.'); continue; }
                $r = hatirlatmaKuralTamamla($db, $kuralId, 'kosul', null);
                $L('  ✓ ' . $r['mesaj']);
                $ilerleyen++;
                continue;
            }
            if (empty($kosul['gonder'])) { $L('  ⏸ Koşul gönderim istemiyor.'); continue; }

            // ── 5) Pencere / deneme sınırı / periyot (zorla'da atlanır) ───────
            $deneme = (int)$kural['DenemeSayisi'];

            if ($zorla) {
                $L('  ⚡ Zorla gönderim — pencere, deneme sınırı ve periyot atlandı.');
            } else {
                if (!hatirlatmaPencereIcinde($asama, $simdi)) {
                    $L("  ⏸ Gönderim penceresi dışında ({$asama['BaslangicSaati']}–{$asama['BitisSaati']}).");
                    continue;
                }
                if (!empty($asama['MaxDeneme']) && $deneme >= (int)$asama['MaxDeneme']) {
                    $L("  ⏸ Deneme sınırına ulaşıldı ({$deneme}/{$asama['MaxDeneme']}).");
                    continue;
                }
                if (!hatirlatmaZamaniGeldi($asama, $kural['SonGonderimZamani'], $simdi)) {
                    $L('  ⏱ Periyot dolmadı (son gönderim: ' . ($kural['SonGonderimZamani'] ?: '-') . ').');
                    continue;
                }
            }

            // ── 6) Mesaj ──────────────────────────────────────────────────────
            // Önceki aşamada toplanan veriler (ödeme tutarı vb.) de yer tutucu olarak kullanılabilir
            $ilerletmeVeri = json_decode((string)($kural['IlerletmeVerisi'] ?? ''), true) ?: [];

            $mesaj = voipMesajIsle((string)$asama['Mesaj'], array_merge($ilerletmeVeri, $kosul['degiskenler'], [
                'kural'  => $kural['Ad'],
                'asama'  => $asama['Ad'],
                'hedef'  => $asama['HedefNo'],
                'sira'   => $asama['SiraNo'],
                'deneme' => $deneme + 1,
                'tarih'  => $simdi->format('d.m.Y'),
                'saat'   => $simdi->format('H:i'),
            ]));
            $mesaj = hatirlatmaMesajTemizle($mesaj);
            $L('  Mesaj: ' . str_replace("\n", ' ⏎ ', $mesaj));

            // ── 7) Gönder ─────────────────────────────────────────────────────
            if ($isDry) { $L('  [ATLANDI] WhatsApp gönderimi ve DB güncellemesi yapılmadı.'); continue; }

            // Aşama "eki de ilet" seçiliyse ve önceki aşamada belge geldiyse, mesaj
            // caption olarak belgeyle birlikte gider; dosya yoksa düz metne düşer.
            $ekYolu = null;
            if (!empty($asama['EkGonder']) && !empty($ilerletmeVeri['ek_dosya'])) {
                $aday = dirname(__DIR__) . '/storage/hatirlatma-ekleri/' . basename($ilerletmeVeri['ek_dosya']);
                if (is_file($aday)) $ekYolu = $aday;
                else $L('  ⚠ Ek dosyası bulunamadı, düz metin gönderilecek: ' . basename($aday));
            }

            if ($ekYolu !== null) {
                $L('  📎 Ek ile gönderiliyor: ' . ($ilerletmeVeri['ek_ad'] ?? basename($ekYolu)));
                $r = EntegrasyonHelper::whatsappBelgeGonder(
                    (int)$asama['KanalId'], $asama['HedefNo'],
                    base64_encode((string)file_get_contents($ekYolu)),
                    (string)($ilerletmeVeri['ek_ad']   ?? basename($ekYolu)),
                    (string)($ilerletmeVeri['ek_mime'] ?? 'application/pdf'),
                    $mesaj
                );
            } else {
                $r = EntegrasyonHelper::whatsappGonder((int)$asama['KanalId'], $asama['HedefNo'], $mesaj);
            }

            if (empty($r['success'])) {
                $L('  ✗ Gönderilemedi: ' . ($r['message'] ?? 'bilinmeyen hata'));
                $hata++;
                continue;
            }

            $L("  ✓ Gönderildi → {$asama['HedefNo']} (" . ($deneme + 1) . '. deneme)');
            $gonderilen++;
            $db->query("
                UPDATE CronHatirlatmaKurallari SET
                    CronHatirlatmaKurallari_SonGonderimZamani = ?,
                    CronHatirlatmaKurallari_DenemeSayisi      = ?,
                    GuncelleyenKullanici = 1, GuncellemeTarihi = ?
                WHERE CronHatirlatmaKurallari_Id = ?
            ", [$simdiStr, $deneme + 1, $simdiStr, $kuralId]);

            // Tek seferlik aşama (periyot 0): gönderimden sonra kendiliğinden ilerler
            if ((int)$asama['PeriyotDakika'] === 0) {
                $r2 = hatirlatmaAsamaIlerlet($db, $kuralId, null, null);
                $L(($r2['ok'] ? '  ✓ ' : '  ⏸ ') . $r2['mesaj']);
                if ($r2['ok']) $ilerleyen++;
            }
        }

        $sonuc = count($kurallar) . " kural işlendi, {$gonderilen} gönderim, {$ilerleyen} aşama ilerledi"
               . ($hata ? ", {$hata} hata." : '.');
        $durum = ($hata > 0 && $gonderilen === 0 && $ilerleyen === 0) ? 2 : 1;

    } catch (Throwable $e) {
        $sonuc = get_class($e) . ': ' . $e->getMessage();
        $durum = 2;
    }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

// ─── WhatsApp (Evolution API) bağlantı kontrolü ───────────────────────────────

// Bağlantı kopukken açılan destek talebine, arıza sürdüğü sürece kaç saatte bir
// hatırlatma yanıtı yazılacağı (zamanlama parametresi verilmezse).
defined('WA_BAGLANTI_TEKRAR_SAATI') || define('WA_BAGLANTI_TEKRAR_SAATI', 6);

// Evolution kısa süreli "connecting" durumuna girip kendini toparlayabildiği için
// arıza ilk görüldüğünde talep açılmaz; bu süre boyunca sürerse açılır (dakika).
defined('WA_BAGLANTI_TOLERANS_DK') || define('WA_BAGLANTI_TOLERANS_DK', 15);

/**
 * Evolution API instance bağlantı durumunu çeker.
 * GET {baseURL}/instance/connectionState/{instance}  (apikey header)
 *
 * @return array ['state' => string|null, 'http' => int, 'hata' => string|null, 'ham' => string]
 */
function evolutionDurumCek(string $baseURL, string $apiKey, string $instance): array
{
    $endpoint = rtrim($baseURL, '/') . '/instance/connectionState/' . rawurlencode($instance);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', "apikey: {$apiKey}"],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $cevap    = curl_exec($ch);
    $httpKod  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlHata = curl_error($ch);
    curl_close($ch);

    if ($cevap === false) {
        return ['state' => null, 'http' => 0, 'hata' => 'cURL: ' . ($curlHata ?: 'bilinmeyen hata'), 'ham' => ''];
    }

    $json = json_decode((string)$cevap, true);

    if ($httpKod < 200 || $httpKod >= 300) {
        $mesaj = is_array($json) ? ($json['response']['message'][0] ?? $json['message'] ?? '') : '';
        if (is_array($mesaj)) $mesaj = json_encode($mesaj, JSON_UNESCAPED_UNICODE);
        return [
            'state' => null,
            'http'  => $httpKod,
            'hata'  => "HTTP {$httpKod}" . ($mesaj !== '' ? " — {$mesaj}" : ''),
            'ham'   => mb_substr((string)$cevap, 0, 1000),
        ];
    }

    // Evolution v2: {"instance":{"instanceName":"x","state":"open"}} — bazı sürümler {"state":"open"}
    $state = $json['instance']['state'] ?? $json['state'] ?? null;
    if ($state === null) {
        return [
            'state' => null,
            'http'  => $httpKod,
            'hata'  => 'Beklenmeyen yanıt yapısı: ' . (is_array($json) ? implode(', ', array_keys($json)) : 'JSON değil'),
            'ham'   => mb_substr((string)$cevap, 0, 1000),
        ];
    }

    return ['state' => (string)$state, 'http' => $httpKod, 'hata' => null, 'ham' => mb_substr((string)$cevap, 0, 1000)];
}

/**
 * Kanalın son bilinen bağlantı durumunu EntegrasyonLoglari'ndan okur.
 * Satır yalnız durum değiştiğinde yazıldığı için "en son satır = güncel durum".
 *
 * @return array|null ['state','ariza_bas','ticket_id','son_bildirim','son_kontrol']
 */
function waBaglantiSonDurum($db, int $kanalId): ?array
{
    $satir = $db->fetchOne("
        SELECT TOP 1 EntegrasyonLoglari_IstekVerisi
        FROM EntegrasyonLoglari
        WHERE EntegrasyonLoglari_Kanal_id = ? AND EntegrasyonLoglari_Tip = 'whatsapp_durum'
        ORDER BY EntegrasyonLoglari_id DESC
    ", [$kanalId]);

    if (!$satir) return null;

    $veri = json_decode((string)($satir['EntegrasyonLoglari_IstekVerisi'] ?? ''), true);
    return is_array($veri) ? $veri : null;
}

/** Bağlantı durumu satırı yazar (yalnız durum değişimi / ticket işlemi anlarında). */
function waBaglantiLogYaz($db, int $kanalId, string $instance, string $konu, bool $saglikli, ?string $hata, string $ham, array $durumVeri, int $kullaniciId): void
{
    $simdi = date('Y-m-d H:i:s');
    $db->insert('EntegrasyonLoglari', [
        'EntegrasyonLoglari_Kanal_id'       => $kanalId,
        'EntegrasyonLoglari_Tip'            => 'whatsapp_durum',
        'EntegrasyonLoglari_Alici'          => $instance,
        'EntegrasyonLoglari_Konu'           => $konu,
        'EntegrasyonLoglari_Mesaj'          => null,
        'EntegrasyonLoglari_GonderimDurumu' => $saglikli ? 'basarili' : 'hata',
        'EntegrasyonLoglari_HataMesaj'      => $hata,
        'EntegrasyonLoglari_IstekVerisi'    => json_encode($durumVeri, JSON_UNESCAPED_UNICODE),
        'EntegrasyonLoglari_CevapVerisi'    => $ham,
        'EntegrasyonLoglari_GonderimTarihi' => $simdi,
        'OlusturanKullanici'                => $kullaniciId,
        'OlusturmaTarihi'                   => $simdi,
        'Durum'                             => 1,
    ]);
}

/** Evolution state kodunu okunur metne çevirir. */
function waStateMetin(?string $state): string
{
    return match ($state) {
        'open'       => 'bağlı',
        'connecting' => 'bağlanmaya çalışıyor',
        'close'      => 'bağlantı kapalı',
        null         => 'durum okunamadı',
        default      => $state,
    };
}

/** İki tarih arası süreyi "2 saat 15 dk" biçiminde döndürür. */
function waSureMetin(string $bas, string $bit): string
{
    $dk = max(0, (int)floor((strtotime($bit) - strtotime($bas)) / 60));
    if ($dk < 60) return "{$dk} dk";

    $saat  = intdiv($dk, 60);
    $kalan = $dk % 60;
    if ($saat < 24) return $kalan ? "{$saat} saat {$kalan} dk" : "{$saat} saat";

    $gun = intdiv($saat, 24);
    $ks  = $saat % 24;
    return $ks ? "{$gun} gün {$ks} saat" : "{$gun} gün";
}

/**
 * Aktif WhatsApp (Evolution) kanallarının bağlantı durumunu kontrol eder.
 * Kopukluk tolerans süresini aşarsa destek talebi açar, arıza sürerken belirli
 * aralıklarla talebe hatırlatma yanıtı yazar, bağlantı düzeldiğinde talebe not düşer.
 */
function gorevWhatsappBaglantiKontrol(array $params, $db): array
{
    ob_start();
    set_time_limit(300);

    $isDry = !empty($params['dry']);
    $L = function (string $msg) use ($isDry) {
        echo '[' . date('H:i:s') . '] ' . ($isDry ? '[DRY] ' : '') . $msg . PHP_EOL;
    };

    try {
        require_once __DIR__ . '/../admin/includes/DestekHelper.php';

        if ($isDry) $L('========== DRY RUN — destek talebi açılmaz, log yazılmaz ==========');

        $kullaniciId = (int)($params['kullanici_id'] ?? 0);
        $kategoriId  = (int)($params['kategori_id'] ?? 0);
        $oncelikId   = (int)($params['oncelik_id']  ?? 0);
        $tekrarSaat  = (int)($params['tekrar_saat'] ?? WA_BAGLANTI_TEKRAR_SAATI);
        $toleransDk  = (int)($params['tolerans_dk'] ?? WA_BAGLANTI_TOLERANS_DK);

        if ($kullaniciId <= 0) {
            throw new RuntimeException('kullanici_id parametresi zorunlu (destek talebini açacak personel).');
        }
        if ($tekrarSaat < 1) $tekrarSaat = WA_BAGLANTI_TEKRAR_SAATI;
        if ($toleransDk < 0) $toleransDk = WA_BAGLANTI_TOLERANS_DK;

        $destekAktif = DestekHelper::aktifMi() && DestekHelper::kullaniciAta($kullaniciId);
        if (!$destekAktif) {
            $L('UYARI: Destek API yapılandırılmamış veya kullanıcı bulunamadı — talep açılamayacak, yalnız durum loglanacak.');
        }

        $kanallar = $db->fetchAll("
            SELECT k.EntegrasyonKanallari_id,
                   k.EntegrasyonKanallari_KanalAdi,
                   k.EntegrasyonKanallari_Instance,
                   e.Entegrasyonlar_Adi,
                   e.Entegrasyonlar_BaseURL,
                   e.Entegrasyonlar_ApiKey
            FROM EntegrasyonKanallari k
            INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
            WHERE e.Entegrasyonlar_Tip = 'whatsapp' AND k.Durum = 1 AND e.Durum = 1
              AND ISNULL(k.EntegrasyonKanallari_Instance, '') <> ''
              AND ISNULL(e.Entegrasyonlar_BaseURL, '') <> ''
            ORDER BY k.EntegrasyonKanallari_id
        ");

        if (!$kanallar) {
            return ['durum' => 1, 'sonuc' => 'Kontrol edilecek aktif WhatsApp kanalı yok.', 'cikti' => ob_get_clean()];
        }

        $L(count($kanallar) . ' aktif WhatsApp kanalı kontrol edilecek.');

        $simdiStr   = date('Y-m-d H:i:s');
        $kopuk      = 0;
        $acilan     = 0;
        $hatirlatma = 0;
        $duzelen    = 0;

        foreach ($kanallar as $kanal) {
            $kanalId  = (int)$kanal['EntegrasyonKanallari_id'];
            $instance = (string)$kanal['EntegrasyonKanallari_Instance'];
            $kanalAdi = (string)$kanal['EntegrasyonKanallari_KanalAdi'];
            $etiket   = "{$kanalAdi} ({$instance})";

            $cevap       = evolutionDurumCek($kanal['Entegrasyonlar_BaseURL'], $kanal['Entegrasyonlar_ApiKey'], $instance);
            $state       = $cevap['state'];
            $saglikli    = ($state === 'open');
            $durumEtiket = $cevap['hata'] ?? waStateMetin($state);

            $L("Kanal #{$kanalId} {$etiket} — " . ($saglikli ? 'bağlı' : "KOPUK: {$durumEtiket}"));

            $onceki         = waBaglantiSonDurum($db, $kanalId);
            $oncekiState    = $onceki['state'] ?? null;
            $oncekiSaglikli = ($onceki === null) ? true : ($oncekiState === 'open');

            // ── Bağlantı sağlıklı ────────────────────────────────────────────
            if ($saglikli) {
                if ($oncekiSaglikli) {
                    continue;   // değişiklik yok, log yazma
                }

                $duzelen++;
                $ticketId  = (int)($onceki['ticket_id'] ?? 0);
                $arizaBas  = $onceki['ariza_bas'] ?? null;
                $sureMetin = $arizaBas ? waSureMetin($arizaBas, $simdiStr) : 'bilinmiyor';

                $L("  ✓ Bağlantı düzeldi (kesinti süresi: {$sureMetin}).");

                if ($ticketId > 0 && $destekAktif && !$isDry) {
                    $mesaj = "WhatsApp bağlantısı yeniden kuruldu.\n\n"
                           . "Kanal: {$kanalAdi}\n"
                           . "Instance: {$instance}\n"
                           . "Kesinti başlangıcı: " . ($arizaBas ?: '-') . "\n"
                           . "Normale dönüş: {$simdiStr}\n"
                           . "Toplam kesinti: {$sureMetin}\n\n"
                           . "Otomatik bağlantı kontrolü tarafından gönderilmiştir.";
                    $r = DestekHelper::yanitla($ticketId, $mesaj);
                    $L(($r['success'] ?? false)
                        ? "  ✓ Talep #{$ticketId} bilgilendirildi."
                        : "  ✗ Talep yanıtlanamadı: " . ($r['message'] ?? 'bilinmeyen hata'));
                } elseif ($ticketId > 0) {
                    $L("  [ATLANDI] Talep #{$ticketId} yanıtlanmadı.");
                }

                if (!$isDry) {
                    waBaglantiLogYaz($db, $kanalId, $instance, 'Bağlantı normale döndü', true, null, $cevap['ham'], [
                        'state'        => 'open',
                        'ariza_bas'    => null,
                        'ticket_id'    => null,
                        'son_bildirim' => null,
                        'son_kontrol'  => $simdiStr,
                    ], $kullaniciId);
                }
                continue;
            }

            // ── Bağlantı kopuk ───────────────────────────────────────────────
            $kopuk++;
            $arizaBas    = $oncekiSaglikli ? $simdiStr : ($onceki['ariza_bas'] ?? $simdiStr);
            $ticketId    = $oncekiSaglikli ? 0 : (int)($onceki['ticket_id'] ?? 0);
            $sonBildirim = $oncekiSaglikli ? null : ($onceki['son_bildirim'] ?? null);

            $gecenDk   = (int)floor((strtotime($simdiStr) - strtotime($arizaBas)) / 60);
            $sureMetin = waSureMetin($arizaBas, $simdiStr);
            $stateKod  = $state ?? 'hata';
            $yazilacak = ($oncekiState !== $stateKod);   // durum kodu değiştiyse kayıt düş
            $konu      = 'Bağlantı kopuk: ' . $durumEtiket;

            // Talep açma denemesi başarısız dönse de (destek API 500 verse de) talep karşı
            // tarafta oluşmuş olabilir. Deneme zamanı kaydedilir ve her turda değil,
            // tekrar_saat aralığıyla yeniden denenir — aksi halde arıza sürdükçe
            // 10 dakikada bir yeni talep açılırdı.
            $denemeSaat = $sonBildirim ? (strtotime($simdiStr) - strtotime($sonBildirim)) / 3600 : PHP_INT_MAX;

            if ($ticketId <= 0) {
                if ($gecenDk < $toleransDk) {
                    $L("  … Arıza {$sureMetin}'dır sürüyor, {$toleransDk} dk toleransı dolmadı — talep açılmadı.");
                } elseif ($denemeSaat < $tekrarSaat) {
                    $L('  … Önceki talep açma denemesinin sonucu belirsiz (talep açılmış olabilir). '
                       . 'Yeniden deneme ' . max(0, round($tekrarSaat - $denemeSaat, 1)) . ' saat sonra.');
                } elseif (!$destekAktif) {
                    $L('  ✗ Destek API kullanılamıyor, talep açılamadı.');
                } elseif ($isDry) {
                    $L('  [ATLANDI] Destek talebi açılacaktı.');
                } else {
                    $mesaj = "WhatsApp (Evolution API) bağlantısı koptu; mesaj gönderimi ve gelen mesaj yakalama durmuş durumda.\n\n"
                           . "Kanal: {$kanalAdi}\n"
                           . "Instance: {$instance}\n"
                           . "Sağlayıcı: " . ($kanal['Entegrasyonlar_Adi'] ?? '-') . "\n"
                           . "Durum: {$durumEtiket}" . ($state !== null ? " (state: {$state})" : '') . "\n"
                           . "İlk tespit: {$arizaBas}\n"
                           . "Kesinti süresi: {$sureMetin}\n\n"
                           . "Yapılması gereken: Evolution panelinden ilgili instance'ın QR kodu yeniden okutulmalı "
                           . "veya oturum yeniden başlatılmalıdır.\n\n"
                           . "Otomatik bağlantı kontrolü tarafından açılmıştır.";

                    $r = DestekHelper::olustur("WhatsApp bağlantısı kesildi — {$etiket}", $mesaj, $kategoriId, $oncelikId);
                    if ($r['success'] ?? false) {
                        $ticketId    = (int)($r['data']['ticket_id'] ?? 0);
                        $sonBildirim = $simdiStr;
                        $yazilacak   = true;
                        $acilan++;
                        $L("  ✓ Destek talebi açıldı: #{$ticketId}");
                    } else {
                        // Talep açılmış ama yanıt hatalı dönmüş olabilir; deneme zamanını
                        // yine de kaydet ki her turda yeni talep açılmasın.
                        $sonBildirim = $simdiStr;
                        $yazilacak   = true;
                        $L('  ✗ Destek talebi yanıtı hatalı: ' . ($r['message'] ?? 'bilinmeyen hata')
                           . " — talep açılmış olabilir, {$tekrarSaat} saat sonra tekrar denenecek.");
                    }
                }
            } else {
                $gecenSaat = $denemeSaat;

                if ($gecenSaat >= $tekrarSaat) {
                    if (!$destekAktif) {
                        $L('  ✗ Destek API kullanılamıyor, hatırlatma yazılamadı.');
                    } elseif ($isDry) {
                        $L("  [ATLANDI] Talep #{$ticketId} için hatırlatma yazılacaktı.");
                    } else {
                        $mesaj = "Bağlantı hâlâ kurulamadı.\n\n"
                               . "Kanal: {$kanalAdi}\n"
                               . "Instance: {$instance}\n"
                               . "Durum: {$durumEtiket}\n"
                               . "Kesinti süresi: {$sureMetin}\n\n"
                               . "Otomatik bağlantı kontrolü tarafından gönderilmiştir.";
                        $r = DestekHelper::yanitla($ticketId, $mesaj);
                        if ($r['success'] ?? false) {
                            $sonBildirim = $simdiStr;
                            $yazilacak   = true;
                            $hatirlatma++;
                            $L("  ✓ Talep #{$ticketId} için hatırlatma yazıldı.");
                        } else {
                            $L('  ✗ Hatırlatma yazılamadı: ' . ($r['message'] ?? 'bilinmeyen hata'));
                        }
                    }
                } else {
                    $L("  … Talep #{$ticketId} açık, arıza {$sureMetin}'dır sürüyor. Sonraki hatırlatmaya "
                       . max(0, round($tekrarSaat - $gecenSaat, 1)) . ' saat var.');
                }
            }

            if ($yazilacak && !$isDry) {
                waBaglantiLogYaz($db, $kanalId, $instance, $konu, false, $cevap['hata'], $cevap['ham'], [
                    'state'        => $stateKod,
                    'ariza_bas'    => $arizaBas,
                    'ticket_id'    => $ticketId ?: null,
                    'son_bildirim' => $sonBildirim,
                    'son_kontrol'  => $simdiStr,
                ], $kullaniciId);
            }
        }

        $sonuc = count($kanallar) . ' kanal kontrol edildi'
               . ($kopuk      ? ", {$kopuk} kopuk"                   : ', tümü bağlı')
               . ($acilan     ? ", {$acilan} destek talebi açıldı"   : '')
               . ($hatirlatma ? ", {$hatirlatma} hatırlatma yazıldı" : '')
               . ($duzelen    ? ", {$duzelen} bağlantı düzeldi"      : '')
               . '.';
        $durum = 1;

    } catch (Throwable $e) {
        $sonuc = get_class($e) . ': ' . $e->getMessage();
        $durum = 2;
    }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

/**
 * Portal Banka API'den hesap hareketlerini BankaHareketleri tablosuna çeker.
 * Tablo boşsa son ilk_gun günlük geçmiş, doluysa MAX(PortalId) sonrası artımlı çekilir.
 * Parametre: ilk_gun (varsayılan 30)
 */
function gorevBankaHareketSync(array $params, $db): array
{
    ob_start();
    set_time_limit(900);

    try {
        require_once dirname(__DIR__) . '/admin/includes/PortalBankaHelper.php';

        $ilkGun = (int)($params['ilk_gun'] ?? 30);
        if ($ilkGun < 1 || $ilkGun > 365) $ilkGun = 30;

        $r = PortalBankaHelper::hareketSenkron($db, $ilkGun);

        if (!$r['success']) {
            echo "  ✗ {$r['message']}\n";
            $sonuc = 'Banka hareket senkronu başarısız: ' . $r['message'];
            $durum = 2;
        } else {
            echo sprintf(
                "  ✓ mod=%s  eklenen=%d  güncellenen=%d  istek=%d  kalan_hak=%s\n",
                $r['mod'], $r['eklenen'], $r['guncellenen'], $r['istek'],
                $r['kalan_hak'] === null ? '?' : $r['kalan_hak']
            );
            $sonuc = 'Banka hareket senkronu → ' . $r['message']
                   . ($r['kalan_hak'] !== null ? ' Kalan API hakkı: ' . $r['kalan_hak'] . '.' : '');
            $durum = 1;
        }
    } catch (Throwable $e) {
        $sonuc = get_class($e) . ': ' . $e->getMessage();
        $durum = 2;
    }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

function gorevFiksturKazi(array $params, $db): array
{
    ob_start();
    set_time_limit(300);

    try {
        require_once dirname(__DIR__) . '/admin/includes/FiksturHelper.php';

        $r = FiksturHelper::senkron($db, null);

        if (!$r['success']) {
            echo "  ✗ {$r['message']}\n";
            $sonuc = 'Fikstür kazıma başarısız: ' . $r['message'];
            $durum = 2;
        } else {
            foreach ($r['ligler'] as $kod => $adet) {
                echo "  · {$kod}: {$adet} maç\n";
            }
            echo "  ✓ {$r['message']}\n";
            $sonuc = 'Fikstür kazıma → ' . $r['message'];
            $durum = 1;
        }
    } catch (Throwable $e) {
        $sonuc = get_class($e) . ': ' . $e->getMessage();
        $durum = 2;
    }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

// ═════════════════════════════════════════════════════════════════════════════
// IRIS Talep Eşleştirme — iris-talep-eslestirme.php Toplu Eşleştirme sekmesinin
// cron karşılığı. Telefonla IRIS'te talep arar, "Talep Kaydeden"i personelimizle
// eşleştirir ve eşleşenleri Basvurular'a yazar.
//
// Panelden farkı seçim adımının olmaması: yalnız GÜVENLİ satırlar yazılır —
// sonuç 'eslesti' olacak VE talep başka bir başvuruya bağlı olmayacak.
// 'personel_eslesmedi' / 'talep_yok' kayıtlarına dokunulmaz, yalnız kontrol
// tarihleri damgalanır (aynı kayıtlar her turda yeniden sorulmasın diye).
// ═════════════════════════════════════════════════════════════════════════════
function gorevIrisTalepEslestir(array $params, $db): array
{
    ob_start();
    set_time_limit(900);

    require_once dirname(__DIR__) . '/admin/includes/IrisTalepServisi.php';

    $sonuc = ''; $durum = 1;

    try {
        $adet    = max(1, min(500, (int)($params['adet'] ?? 200) ?: 200));
        $bekleme = isset($params['bekleme_gun']) && $params['bekleme_gun'] !== ''
                 ? max(0, min(365, (int)$params['bekleme_gun'])) : 30;
        $sadeceBos   = !empty($params['sadece_bos_personel']);
        // Dolu personeli IRIS'e göre ezmek bilinçli bir karardır; cron'da varsayılan KAPALI.
        $personeliEz = !empty($params['personeli_ez']);
        // kuru=1 → hiçbir şey yazılmaz (damgalama dahil), yalnız ne olacağı raporlanır
        $kuru        = !empty($params['kuru']);

        $ek = $sadeceBos ? " AND AltBayiPersonel_ID IS NULL" : "";
        if ($bekleme > 0) {
            $ek .= " AND (BasvuruDurum_KontrolTarihi IS NULL
                          OR BasvuruDurum_KontrolTarihi < DATEADD(DAY, -{$bekleme}, GETDATE()))";
        }

        // Hiç sorulmamışlar önce, sonra en eski sorulanlar (panelle aynı sıra)
        $kayitlar = $db->fetchAll("
            SELECT TOP {$adet} Basvurular_id, Isim, Soyisim, phoneAreaNumber, phoneNumber,
                   AltBayiPersonel_ID
            FROM Basvurular
            WHERE TalepKayitNo IS NULL AND phoneNumber IS NOT NULL AND phoneNumber <> ''{$ek}
            ORDER BY CASE WHEN BasvuruDurum_KontrolTarihi IS NULL THEN 0 ELSE 1 END,
                     BasvuruDurum_KontrolTarihi ASC,
                     Basvurular_id DESC");

        if (!$kayitlar) {
            echo "  Taranacak kayıt yok (bekleme_gun={$bekleme}).\n";
            return ['durum' => 1, 'sonuc' => 'Taranacak kayıt yok.', 'cikti' => ob_get_clean()];
        }

        echo "  " . count($kayitlar) . " kayıt taranacak"
           . ($kuru ? ' (KURU — yazma yok)' : '')
           . ($personeliEz ? ' (dolu personel EZİLECEK)' : '') . "\n\n";

        $perMap   = irisPersonelHaritasi($db);
        $durumMap = irisDurumKoduHaritasi($db);
        $o        = irisTalepOturum($db);
        echo "  IRIS oturumu: {$o['hesap']['ad']} ({$o['hesap']['bayiKodu']}) — rol {$o['hesap']['rol']}\n\n";

        $sayac = ['eslesti' => 0, 'talep_yok' => 0, 'personel_eslesmedi' => 0,
                  'talep_kullanimda' => 0, 'hata' => 0, 'yazilan' => 0];
        $sorulanlar      = [];  // IRIS'e gerçekten sorulanlar → damgalanacak
        $bulunanTalepler = [];  // bu tur içinde bir talep iki kayda birden bağlanmasın
        $yazilanlar = []; $hatalar = [];
        $ardisikHata = 0; $turKesildi = false;
        $simdi = date('Y-m-d H:i:s');

        foreach ($kayitlar as $k) {
            $id  = (int)$k['Basvurular_id'];
            $tel = irisTelefonBirlestir($k['phoneAreaNumber'], $k['phoneNumber']);
            $ad  = trim(($k['Isim'] ?? '') . ' ' . ($k['Soyisim'] ?? ''));

            if (strlen($tel) < 10) {
                $sayac['hata']++;
                $hatalar[] = "#{$id} telefon hanesi eksik";
                continue;
            }

            try {
                $e = irisTalepAra($o, $tel, $perMap);
                $sorulanlar[] = $id;
                $ardisikHata  = 0;
            } catch (Throwable $ex) {
                $sayac['hata']++;
                $hatalar[] = "#{$id}: " . $ex->getMessage();
                // IRIS düştüyse her kayıt 60 sn timeout bekler ve runner 10 dk kilitlenir;
                // art arda hatada tur kesilir, sorulamayanlar damgalanmadığı için sonraki turda denenir.
                if (++$ardisikHata >= IRIS_ARDISIK_HATA_LIMIT) {
                    $turKesildi = true;
                    echo "  TUR KESİLDİ: art arda {$ardisikHata} IRIS hatası — IRIS erişilemiyor.\n";
                    break;
                }
                continue;
            }

            if ($e['sonuc'] !== 'eslesti') {
                $sayac[$e['sonuc']]++;
                continue;
            }

            $veri    = irisYazilacakAlanlar($e, $k, $durumMap, $personeliEz);
            $talepNo = (int)$veri['TalepKayitNo'];

            // Talep başka bir başvuruya bağlıysa bağlama (aynı numarayla mükerrer başvuru olabiliyor)
            $sahip = $bulunanTalepler[$talepNo] ?? null;
            if (!$sahip) {
                $q = $db->fetchOne("SELECT TOP 1 Basvurular_id FROM Basvurular
                                    WHERE TalepKayitNo = ? AND Basvurular_id <> ?", [$talepNo, $id]);
                if ($q) $sahip = (int)$q['Basvurular_id'];
            }
            if ($sahip) {
                $sayac['talep_kullanimda']++;
                echo "  ATLANDI #{$id} {$ad} — talep {$talepNo} zaten #{$sahip} kaydında\n";
                continue;
            }
            $bulunanTalepler[$talepNo] = $id;
            $sayac['eslesti']++;

            if ($kuru) {
                echo "  [KURU] #{$id} {$ad} → talep {$talepNo}, personel {$e['personel']['adSoyad']}\n";
                continue;
            }

            $veri['GuncelleyenKullanici'] = 1;   // cron
            $veri['GuncellemeTarihi']     = $simdi;

            try {
                $db->update('Basvurular', $veri, ['Basvurular_id' => $id]);
                $sayac['yazilan']++;
                $yazilanlar[] = $id;
                echo "  YAZILDI #{$id} {$ad} → talep {$talepNo}, personel {$e['personel']['adSoyad']}"
                   . (isset($veri['BasvuruSurecDurum_ID']) ? ", süreç {$veri['BasvuruSurecDurum_ID']}" : '') . "\n";
            } catch (Throwable $ex) {
                $sayac['hata']++;
                $hatalar[] = "#{$id}: " . $ex->getMessage();
                // Talep bulundu ama yazılamadı: damgalanırsa 30 gün tekrar denenmez, sessizce kaybolur
                $sorulanlar = array_values(array_diff($sorulanlar, [$id]));
            }
        }

        irisTalepOturumKapat($o);

        // Sorulan kayıtları damgala: aynı kayıtlar her turda yeniden sorulmasın
        $damgalanan = 0;
        if ($sorulanlar && !$kuru) {
            foreach (array_chunk($sorulanlar, 500) as $parca) {
                $yerTutucu = implode(',', array_fill(0, count($parca), '?'));
                $ok = $db->execute("UPDATE Basvurular SET BasvuruDurum_KontrolTarihi = GETDATE()
                                    WHERE Basvurular_id IN ({$yerTutucu})", $parca);
                if ($ok !== false) $damgalanan += count($parca);
            }
        }

        echo "\n  Özet: {$sayac['eslesti']} eşleşti, {$sayac['yazilan']} yazıldı, "
           . "{$sayac['talep_yok']} talep yok, {$sayac['personel_eslesmedi']} personel eşleşmedi, "
           . "{$sayac['talep_kullanimda']} talep kullanımda, {$sayac['hata']} hata, {$damgalanan} damgalandı\n";

        $sonuc = "{$sayac['yazilan']} kayıt eşleştirildi"
               . " ({$sayac['talep_yok']} talep yok, {$sayac['personel_eslesmedi']} personel eşleşmedi"
               . ($sayac['talep_kullanimda'] ? ", {$sayac['talep_kullanimda']} talep kullanımda" : '')
               . ($sayac['hata'] ? ", {$sayac['hata']} hata" : '')
               . ", {$damgalanan} damgalandı)" . ($kuru ? ' [KURU]' : '');

        if ($yazilanlar) {
            $ilk = array_slice($yazilanlar, 0, 60);
            $sonuc .= ' | Yazılan: #' . implode(', #', $ilk)
                    . (count($yazilanlar) > 60 ? ' … (+' . (count($yazilanlar) - 60) . ')' : '');
        }
        if ($hatalar) {
            echo "\n  HATALAR:\n    " . implode("\n    ", array_slice($hatalar, 0, 50)) . "\n";
            $sonuc .= ' | Hata: ' . implode(' ; ', array_slice($hatalar, 0, 5));
        }

        if ($turKesildi) {
            $sonuc = 'IRIS ERİŞİLEMİYOR — art arda ' . IRIS_ARDISIK_HATA_LIMIT . ' hatada tur kesildi | ' . $sonuc;
        }

        // Tur kesildiyse veya hiç yazma olmadan yalnız hata alındıysa başarısız sayılır (kesinti gözden kaçmasın)
        $durum = ($turKesildi || ($sayac['hata'] > 0 && $sayac['yazilan'] === 0)) ? 2 : 1;

    } catch (Throwable $e) {
        $sonuc = get_class($e) . ': ' . $e->getMessage();
        $durum = 2;
    }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

/**
 * Kütüphanesiz, tek sayfalık .xlsx üretir (ZipArchive). Telefonda ve WhatsApp
 * önizlemesinde açılabilsin diye HTML tabanlı .xls yerine gerçek OOXML yazılır.
 *
 * @param array  $basliklar ['anahtar' => 'Başlık', ...] — sütun sırası da bu
 * @param array  $satirlar  Her satır anahtar => değer; tüm değerler metin olarak yazılır
 *                          (numaralarda başta sıfır / bilimsel gösterim sorunu olmasın)
 * @param array  $genislik  ['anahtar' => karakter genişliği] (opsiyonel)
 * @return string Ham .xlsx içeriği
 */
function xlsxOlustur(array $basliklar, array $satirlar, array $genislik = [], string $sayfaAdi = 'Liste'): string
{
    $kol = function (int $i): string {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    };
    $x = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $anahtarlar = array_keys($basliklar);
    $sonKol     = $kol(count($anahtarlar) - 1);
    $sonSatir   = count($satirlar) + 1;

    $hucre = fn(string $ref, $v, int $stil) =>
        "<c r=\"{$ref}\" t=\"inlineStr\" s=\"{$stil}\"><is><t xml:space=\"preserve\">{$x($v)}</t></is></c>";

    $xml = '';
    $r = '';
    foreach ($anahtarlar as $i => $k) $r .= $hucre($kol($i) . '1', $basliklar[$k], 1);
    $xml .= "<row r=\"1\">{$r}</row>";
    foreach (array_values($satirlar) as $n => $satir) {
        $no = $n + 2; $r = '';
        foreach ($anahtarlar as $i => $k) {
            $v = $satir[$k] ?? '';
            if ($v === null || $v === '') continue;
            $r .= $hucre($kol($i) . $no, $v, 0);
        }
        $xml .= "<row r=\"{$no}\">{$r}</row>";
    }

    $cols = '';
    foreach ($anahtarlar as $i => $k) {
        $w = $genislik[$k] ?? max(10, mb_strlen($basliklar[$k]) + 2);
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . "\" width=\"{$w}\" customWidth=\"1\"/>";
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . "<cols>{$cols}</cols><sheetData>{$xml}</sheetData>"
        . "<autoFilter ref=\"A1:{$sonKol}{$sonSatir}\"/>"
        . '</worksheet>';

    $sayfaAdi = $x(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '', $sayfaAdi), 0, 31) ?: 'Liste');
    $dosyalar = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<sheets><sheet name=\"{$sayfaAdi}\" sheetId=\"1\" r:id=\"rId1\"/></sheets>"
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
            . "'{$sayfaAdi}'!\$A\$1:\${$sonKol}\${$sonSatir}</definedName></definedNames>"
            . '</workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        // Stil 0: normal · Stil 1: başlık (kalın, beyaz yazı, mavi zemin — sayfadaki Excel ile aynı renk)
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0D6EFD"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="49" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/></cellXfs>'
            . '</styleSheet>',
        'xl/worksheets/sheet1.xml' => $sheet,
    ];

    // sys_get_temp_dir() Plesk Cron (CLI) altında yazılamaz olabildiği için proje storage/ kullanılır
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'xlsx-tmp';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $tmp = $dir . DIRECTORY_SEPARATOR . 'xlsx_' . bin2hex(random_bytes(8)) . '.xlsx';
    $zip = new ZipArchive();
    $sonuc = $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($sonuc !== true) throw new RuntimeException('xlsx oluşturulamadı (ZipArchive kod: ' . $sonuc . ', yol: ' . $tmp . ').');
    foreach ($dosyalar as $ad => $icerik) $zip->addFromString($ad, $icerik);
    $zip->close();
    $veri = file_get_contents($tmp);
    @unlink($tmp);
    return $veri;
}

/**
 * Kurulum bayilerine yönlendirilen işlerin randevularını WhatsApp ile bildirir.
 * Her zamanlayıcı ayrı bir bildirimdir (bayi seti + alıcılar + gün aralığı).
 * Liste .xlsx ek olarak gider; belge altı yazısında kısa özet bulunur.
 *
 * Parametreler:
 *   bayi_kodlari    : Kurulum bayi kodları, virgüllü (IrisRapor_MemoYonlenenBayiKodu);
 *                     "*" = tüm kurulum bayileri (sonradan gelenler dahil)
 *   alicilar        : Telefon (905xx) ve/veya grup JID (120363...@g.us), virgüllü
 *   randevu_gunleri : Göreli gün aralığı "bas-bit" (0 = bugün). Örn: "1-1" yarın, "0-6" 7 gün
 *   bos_gonder      : "1" ise randevu yokken de bilgi mesajı (düz metin) gider
 * Kapanmış memolar da listelenir ("Durum" sütununda Kapandı).
 */
function gorevKurulumBayiRandevuBildir(array $params, $db): array
{
    ob_start();
    $sonuc = ''; $durum = 1;

    try {
        require_once __DIR__ . '/../admin/includes/EntegrasyonHelper.php';

        $bayiKodlari = array_values(array_unique(array_filter(array_map('trim', explode(',', $params['bayi_kodlari'] ?? '')))));
        $alicilar    = array_values(array_unique(array_filter(array_map('trim', explode(',', $params['alicilar'] ?? '')))));
        if (!$bayiKodlari) return ['durum' => 2, 'sonuc' => 'Kurulum bayisi seçilmemiş.', 'cikti' => ob_get_clean()];
        if (!$alicilar)    return ['durum' => 2, 'sonuc' => 'Alıcı listesi boş.',          'cikti' => ob_get_clean()];

        // Gün aralığı: "1-1", "0-6"... Tek sayı da kabul edilir ("1" = yarın)
        $gunParam = trim((string)($params['randevu_gunleri'] ?? '1-1'));
        if (!preg_match('/^(\d{1,2})(?:-(\d{1,2}))?$/', $gunParam, $m)) {
            return ['durum' => 2, 'sonuc' => "Geçersiz randevu günü: '{$gunParam}'", 'cikti' => ob_get_clean()];
        }
        $gunBas = (int)$m[1];
        $gunBit = max($gunBas, (int)($m[2] ?? $m[1]));
        $bas    = date('Y-m-d', strtotime("+{$gunBas} day"));
        $bit    = date('Y-m-d', strtotime("+{$gunBit} day"));

        $gunAdlari = [0 => 'Bugün', 1 => 'Yarın', 2 => 'Öbür gün'];
        $basGoster = date('d.m.Y', strtotime($bas));
        $baslik    = $bas === $bit
            ? $basGoster . (isset($gunAdlari[$gunBas]) ? " ({$gunAdlari[$gunBas]})" : '')
            : $basGoster . ' - ' . date('d.m.Y', strtotime($bit));
        echo "  Randevu aralığı: {$baslik}\n";
        // "*" = tüm kurulum bayileri (çalıştığı anda rapordaki herkes; yeni gelenler dahil)
        $tumBayiler = in_array('*', $bayiKodlari, true);
        echo '  Bayiler: ' . ($tumBayiler ? 'Tümü (yeniler dahil)' : implode(', ', $bayiKodlari)) . "\n";

        if ($tumBayiler) {
            $bayiKosul  = 'r.IrisRapor_MemoYonlenenBayiKodu IS NOT NULL';
            $bayiParams = [];
        } else {
            $bayiKosul  = 'r.IrisRapor_MemoYonlenenBayiKodu IN (' . implode(',', array_fill(0, count($bayiKodlari), '?')) . ')';
            $bayiParams = $bayiKodlari;
        }
        $rows = $db->fetchAll("
            SELECT r.IrisRapor_MemoYonlenenBayiKodu         AS BayiKodu,
                   r.IrisRapor_MemoYonlenenBayiAdi          AS BayiAdi,
                   r.IrisRapor_MemoYonlenenBayiYoneticisi   AS Yonetici,
                   r.IrisRapor_MemoYonlenenBayiBolge        AS Bolge,
                   r.IrisRapor_DtMusteriNo                  AS MusteriNo,
                   r.IrisRapor_TalepId                      AS TalepId,
                   r.IrisRapor_MemoId                       AS MemoId,
                   r.IrisRapor_MemoKayitTipi                AS Tip,
                   r.IrisRapor_TalepTuru                    AS TalepTuru,
                   r.IrisRapor_SatisDurumu                  AS SatisDurumu,
                   r.IrisRapor_RandevuTarihi                AS Randevu,
                   r.IrisRapor_MemoKapanisTarihi            AS Kapanis
            FROM DigiturkIrisRapor r
            WHERE $bayiKosul
              AND r.IrisRapor_RandevuTarihi >= ?
              AND r.IrisRapor_RandevuTarihi <  DATEADD(DAY, 1, CAST(? AS DATE))
            ORDER BY r.IrisRapor_MemoYonlenenBayiAdi, r.IrisRapor_RandevuTarihi, r.IrisRapor_Id",
            array_merge($bayiParams, [$bas . ' 00:00:00', $bit]));
        echo '  Bulunan randevu: ' . count($rows) . "\n";

        if (!$rows && ($params['bos_gonder'] ?? '0') !== '1') {
            return ['durum' => 1, 'sonuc' => "{$baslik}: randevu yok, gönderim yapılmadı.", 'cikti' => ob_get_clean()];
        }

        $ust = "📅 *Kurulum Randevuları — {$baslik}*";

        $basarili = $hatali = 0;
        if (!$rows) {
            // Boş Excel göndermek yerine kısa bilgi mesajı
            $mesaj = $ust . "\n\nSeçili bayilerde bu aralıkta randevu yok.";
            foreach ($alicilar as $alici) {
                $r = EntegrasyonHelper::whatsappGonder(1, $alici, $mesaj);
                if ($r['success']) { $basarili++; echo "  ✓ {$alici}: bilgi mesajı gönderildi\n"; }
                else               { $hatali++;   echo "  ✗ {$alici}: {$r['message']}\n"; }
            }
        } else {
            $basliklar = [
                'BayiKodu' => 'Kurulum Bayi Kodu', 'BayiAdi' => 'Kurulum Bayisi', 'Yonetici' => 'Bayi Yöneticisi',
                'Bolge' => 'Bölge', 'RandevuTarih' => 'Randevu Tarihi', 'RandevuSaat' => 'Randevu Saati',
                'MusteriNo' => 'Müşteri No', 'TalepId' => 'Talep No', 'MemoId' => 'Memo No', 'Tip' => 'Tip',
                'TalepTuru' => 'Talep Türü', 'SatisDurumu' => 'Satış Durumu', 'Durum' => 'Durum',
                'Kapanis' => 'Memo Kapanış',
            ];
            $genislik = [
                'BayiKodu' => 14, 'BayiAdi' => 34, 'Yonetici' => 24, 'Bolge' => 14, 'RandevuTarih' => 13,
                'RandevuSaat' => 12, 'MusteriNo' => 13, 'TalepId' => 12, 'MemoId' => 12, 'Tip' => 8,
                'TalepTuru' => 16, 'SatisDurumu' => 16, 'Durum' => 10, 'Kapanis' => 17,
            ];

            $satirlar = [];
            $acik = 0;
            $bayiSay = [];
            foreach ($rows as $r) {
                $ts = strtotime($r['Randevu']);
                if (!$r['Kapanis']) $acik++;
                $bayiSay[$r['BayiKodu']] = true;
                $satirlar[] = array_merge($r, [
                    'RandevuTarih' => date('d.m.Y', $ts),
                    'RandevuSaat'  => date('H:i', $ts),
                    'Durum'        => $r['Kapanis'] ? 'Kapandı' : 'Açık',
                    'Kapanis'      => $r['Kapanis'] ? date('d.m.Y H:i', strtotime($r['Kapanis'])) : '',
                ]);
            }

            $xlsx     = xlsxOlustur($basliklar, $satirlar, $genislik, 'Randevular');
            $base64   = base64_encode($xlsx);
            $dosyaAdi = 'kurulum_randevulari_' . date('d.m.Y', strtotime($bas))
                      . ($bas !== $bit ? '-' . date('d.m.Y', strtotime($bit)) : '') . '.xlsx';
            $toplam   = count($rows);
            $caption  = $ust . "\n"
                      . "Toplam: *{$toplam}* randevu · " . count($bayiSay) . " bayi\n"
                      . "Açık: {$acik} · Kapandı: " . ($toplam - $acik);
            echo '  Excel: ' . $dosyaAdi . ' (' . strlen($xlsx) . " bayt)\n";

            $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            foreach ($alicilar as $alici) {
                $r = EntegrasyonHelper::whatsappBelgeGonder(1, $alici, $base64, $dosyaAdi, $mime, $caption);
                if ($r['success']) { $basarili++; echo "  ✓ {$alici}: Excel gönderildi\n"; }
                else               { $hatali++;   echo "  ✗ {$alici}: {$r['message']}\n"; }
            }
        }

        $sonuc = "{$baslik}: " . count($rows) . " randevu, {$basarili} alıcıya gönderildi" . ($hatali ? ", {$hatali} hata" : '') . '.';
        $durum = ($hatali > 0 && $basarili === 0) ? 2 : 1;

    } catch (Throwable $e) {
        $sonuc = get_class($e) . ': ' . $e->getMessage();
        $durum = 2;
    }

    return ['durum' => $durum, 'sonuc' => $sonuc, 'cikti' => ob_get_clean()];
}

/**
 * Birleşik VoIP görevi: islem parametresine göre hesap / harcama / bakiye işlemini çalıştırır.
 * Her işlem ayrı zamanlayıcıyla (SabitParametreler.islem) tetiklenir.
 */
function gorevVoipIslemler(array $params, $db): array
{
    $islem = strtolower(trim($params['islem'] ?? ''));
    return match($islem) {
        'hesap'   => gorevVoipHesapGuncelle($params, $db),
        'harcama' => gorevVoipGunlukHarcama($params, $db),
        'bakiye'  => gorevVoipBakiyeKontrol($params, $db),
        default   => ['durum' => 2, 'sonuc' => "Geçersiz VoIP işlemi: '{$islem}' (hesap | harcama | bakiye)", 'cikti' => ''],
    };
}

function gorevCalistir(string $gorevKodu, array $params, $db): array
{
    return match($gorevKodu) {
        'token_guncelle'            => gorevTokenGuncelle($params, $db),
        'iris_rapor'                => gorevIrisRapor($params, $db),
        'basvuru_surec_guncelle'    => gorevBasvuruSurecGuncelle($params, $db),
        'iris_talep_eslestir'       => gorevIrisTalepEslestir($params, $db),
        'voip_islemler'             => gorevVoipIslemler($params, $db),
        // Eski kodlar: geriye uyumluluk (voip_islemler'e taşındı)
        'voip_hesap_guncelle'       => gorevVoipHesapGuncelle($params, $db),
        'voip_gunluk_harcama'       => gorevVoipGunlukHarcama($params, $db),
        'voip_bakiye_kontrol'       => gorevVoipBakiyeKontrol($params, $db),
        'whatsapp_rapor_bildir'     => gorevWhatsappRaporBildir($params, $db),
        'meta_reklam_senkronize'    => gorevMetaReklamSenkronize($params, $db),
        'meta_gunluk_harcama_odeme' => gorevMetaGunlukHarcamaOdeme($params, $db),
        'reklam_borc_bildir'        => gorevReklamBorcBildir($params, $db),
        'hakedis_gruba_bildir'      => gorevHakedisGrubaBildir($params, $db),
        'hatirlatma_gonder'         => gorevHatirlatmaGonder($params, $db),
        'whatsapp_baglanti_kontrol' => gorevWhatsappBaglantiKontrol($params, $db),
        'banka_hareket_sync'        => gorevBankaHareketSync($params, $db),
        'fikstur_kazi'              => gorevFiksturKazi($params, $db),
        'kurulum_bayi_randevu_bildir' => gorevKurulumBayiRandevuBildir($params, $db),
        default                     => throw new RuntimeException("Bilinmeyen görev kodu: {$gorevKodu}"),
    };
}
