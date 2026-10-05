<?php
/**
 * IRIS Talep Eşleştirme Servisi
 *
 * Basvurular tablosundaki telefon ile IRIS'te talep arar, "Talep Kaydeden"i
 * DigiturkAltBayiPersonel ile eşleştirir; ccapi CheckRequisitionList ile süreç
 * durumunu günceller.
 *
 * Kullanan: admin/pages/iris-talep-eslestirme.php, cron/tasks.php
 *
 * IRIS web API (iris.digiturk.com.tr/api) — IrisWebToken + IrisVerificationToken
 *   Auth/GetToken → Auth/Login → Auth/GetToken
 *   Requisition/SearchRequisition   {Value, ParameterType}
 *   Requisition/GetRequisitionDetail {RequisitionId, RequisitionRecordType, MemoCaseType}
 * ccapi (iris-cc.digiturk.com.tr/ccapi-prod/api) — Token header, APIEndpointler tablosundan
 *   Order/CheckRequisitionList (Id=18)  [talepNo, ...]
 *
 * NOT: Order/CheckRequisition (Id=17) gövde ile çalışmıyor (hep 0 döner), toplu
 * uç kullanılır.
 */

defined('IRIS_WEB_BASE')        || define('IRIS_WEB_BASE',        'https://iris.digiturk.com.tr/api');
defined('IRIS_TALEP_PERSONEL')  || define('IRIS_TALEP_PERSONEL',  1);   // ccapi token'ı alınan personel (web oturumu DEĞİL)
defined('IRIS_ARAMA_TELEFON')   || define('IRIS_ARAMA_TELEFON',   5);   // ParameterType: telefon
defined('IRIS_CAGRI_BEKLEME')   || define('IRIS_CAGRI_BEKLEME',   250); // ms
defined('IRIS_EP_DURUM')        || define('IRIS_EP_DURUM',        17);  // Order/CheckRequisition (tekil, query string)
defined('IRIS_EP_DURUM_LISTE')  || define('IRIS_EP_DURUM_LISTE',  18);  // KALDIRILDI (08.09.2026 — canlıda 404)

if (!function_exists('irisWebPost')) {

// ── IRIS web API ─────────────────────────────────────────────────────────────
function irisWebPost($ch, string $endpoint, $body, ?string $webToken, ?string $verToken): array
{
    $headers = [
        'Content-Type: application/json', 'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'Referer: https://iris.digiturk.com.tr/', 'Origin: https://iris.digiturk.com.tr',
    ];
    if ($webToken) $headers[] = 'IrisWebToken: ' . $webToken;
    if ($verToken) $headers[] = 'IrisVerificationToken: ' . $verToken;

    curl_setopt($ch, CURLOPT_URL, IRIS_WEB_BASE . '/' . $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch))  throw new RuntimeException('cURL: ' . curl_error($ch));
    if ($code >= 400)     throw new RuntimeException("HTTP {$code} [{$endpoint}]: " . mb_substr((string)$resp, 0, 300));
    $json = json_decode((string)$resp, true);
    if ($json === null)   throw new RuntimeException("JSON parse [{$endpoint}]: " . json_last_error_msg());
    usleep(IRIS_CAGRI_BEKLEME * 1000);
    return $json;
}

/**
 * IRIS web API gövde kapsayıcısı.
 * IRIS 2026/07 itibarıyla `EntityData` → `Data`, `responseMessage` → `ResponseMessage`
 * olarak değişti; her iki şema da desteklenir.
 */
function irisVeri(array $r): array
{
    $d = $r['Data'] ?? $r['EntityData'] ?? [];
    return is_array($d) ? $d : [];
}

function irisMesaj(array $r): string
{
    return trim((string)($r['ResponseMessage'] ?? $r['responseMessage'] ?? ''));
}

/**
 * IRIS talep sorgularında kullanılacak ana bayi hesabını verir.
 *
 * Hesap DigiturkAnaBayiler_IrisTalepVarsayilan bayrağı ile seçilir; sayfadaki
 * "IRIS Sorgularında Kullanılan Hesap" kartından değiştirilebilir.
 */
function irisTalepHesabi($db): array
{
    $hesap = $db->fetchOne("
        SELECT DigiturkAnaBayiler_Id           AS id,
               DigiturkAnaBayiler_Ad           AS ad,
               DigiturkAnaBayiler_BayiKodu     AS bayiKodu,
               DigiturkAnaBayiler_KullaniciAdi AS kullanici,
               DigiturkAnaBayiler_Sifre        AS sifre
        FROM DigiturkAnaBayiler
        WHERE DigiturkAnaBayiler_IrisTalepVarsayilan = 1 AND Durum = 1");

    if (!$hesap) {
        throw new RuntimeException('IRIS sorgu hesabı seçilmemiş — sayfadaki '
            . '"IRIS Sorgularında Kullanılan Hesap" kartından bir ana bayi seçin.');
    }
    if (empty($hesap['kullanici']) || empty($hesap['sifre'])) {
        throw new RuntimeException("IRIS sorgu hesabının kullanıcı adı/şifresi boş ({$hesap['ad']}).");
    }
    return $hesap;
}

/**
 * IRIS web oturumu açar → ['ch'=>, 'webToken'=>, 'verToken'=>, 'hesap'=>]
 *
 * Oturum ANA BAYİ hesabıyla açılır (Role=DEALER). Personel hesabı (Role=PERSONNEL)
 * yalnız kendi kapsamındaki talepleri görüyor; başka bayiye yönlendirilmiş talepler
 * için SearchRequisition "Kayıt Bulunamadı.." döndüğünden hiçbir eşleştirme
 * yapılamıyordu (tespit: 16.09.2026).
 */
function irisTalepOturum($db): array
{
    $hesap = irisTalepHesabi($db);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 60, CURLOPT_ENCODING => '',
    ]);

    $r = irisWebPost($ch, 'Auth/GetToken', new stdClass(), null, null);
    $webToken = $r['token'] ?? null;
    if (!$webToken) throw new RuntimeException('Auth/GetToken token vermedi.');

    $r = irisWebPost($ch, 'Auth/Login', [
        'DealerCode' => $hesap['bayiKodu'], 'UserCode' => $hesap['kullanici'],
        'Password'   => $hesap['sifre'],    'Language' => 'tr',
    ], $webToken, null);
    $veri     = irisVeri($r);
    $verToken = $veri['Token'] ?? null;
    if (!$verToken) {
        throw new RuntimeException("IRIS login başarısız ({$hesap['ad']}): " . (irisMesaj($r) ?: 'bilinmeyen hata'));
    }

    $r = irisWebPost($ch, 'Auth/GetToken', new stdClass(), $webToken, $verToken);
    $webToken = $r['token'] ?? $webToken;

    // Token DB'ye YAZILMAZ: _WebToken kolonlarını IRIS rapor cron'u kullanıyor,
    // buradan yazmak onun token'ını eziyordu. Oturum yalnız istek boyunca yaşar.
    $hesap['rol'] = $veri['Role'] ?? null;

    return ['ch' => $ch, 'webToken' => $webToken, 'verToken' => $verToken, 'hesap' => $hesap];
}

function irisTalepOturumKapat(array $o): void
{
    if (isset($o['ch']) && $o['ch']) @curl_close($o['ch']);
}

// ── ccapi ────────────────────────────────────────────────────────────────────
function irisCcapiPost($db, int $endpointId, $body): array
{
    $ep = $db->fetchOne("SELECT APIEndpointler_Endpoint FROM APIEndpointler WHERE APIEndpointler_Id = ? AND Durum = 1", [$endpointId]);
    if (!$ep) throw new RuntimeException("API endpoint bulunamadı (Id={$endpointId})");

    $tok = $db->fetchOne("SELECT DigiturkAltBayiPersonel_Token AS token FROM DigiturkAltBayiPersonel WHERE DigiturkAltBayiPersonel_Id = ?", [IRIS_TALEP_PERSONEL]);
    $token = $tok['token'] ?? null;
    if (!$token) throw new RuntimeException('ccapi token yok (Token Güncelle görevini çalıştırın).');

    $ch = curl_init($ep['APIEndpointler_Endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Token: ' . $token],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    @curl_close($ch);

    if ($err) throw new RuntimeException('cURL: ' . $err);
    $json = json_decode((string)$resp, true);
    if ($json === null) throw new RuntimeException("HTTP {$code} — ccapi yanıtı JSON değil.");
    return $json;
}

/**
 * Parametreleri QUERY STRING ile gönderen ccapi çağrısı (Order/CheckRequisition).
 *
 * Uç POST bekliyor ama parametreyi gövdede DEĞİL query'de okuyor. Gövde hiç
 * gönderilmezse IIS "411 Length Required" döndüğü için boş gövde şarttır
 * (Content-Length: 0) — Content-Type gönderilmez.
 *
 * @param ?int $anaBayiId Token bu ana bayinin sorgu personelinden alınır (bkz. irisPersonelCoz)
 */
function irisCcapiGet($db, int $endpointId, array $parametreler, ?int $anaBayiId = null): array
{
    $ep = $db->fetchOne("SELECT APIEndpointler_Endpoint, APIEndpointler_HttpMetod FROM APIEndpointler WHERE APIEndpointler_Id = ? AND Durum = 1", [$endpointId]);
    if (!$ep) throw new RuntimeException("API endpoint bulunamadı (Id={$endpointId})");

    $personelId = irisPersonelCoz($db, $anaBayiId);
    $tok   = $db->fetchOne("SELECT DigiturkAltBayiPersonel_Token AS token FROM DigiturkAltBayiPersonel WHERE DigiturkAltBayiPersonel_Id = ?", [$personelId]);
    $token = $tok['token'] ?? null;
    if (!$token) throw new RuntimeException("ccapi token yok (personel #{$personelId} — Token Güncelle görevini çalıştırın).");

    $url = (string)$ep['APIEndpointler_Endpoint'];
    $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($parametreler);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_CUSTOMREQUEST  => strtoupper((string)($ep['APIEndpointler_HttpMetod'] ?: 'POST')),
        CURLOPT_POSTFIELDS     => '',   // 411 Length Required'ı önler
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Token: ' . $token],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    @curl_close($ch);

    if ($err) throw new RuntimeException('cURL: ' . $err);
    if ($code === 429) throw new RuntimeException('ccapi günlük sorgu limiti (HTTP 429).');
    // 401 = token ölü. Digiturk token'ı ilan edilenden kısa ömürlü olabildiği için
    // (08.09.2026: 15 saatlik token 401 verdi) bu durum sessiz "yanıt yok" sayılmamalı;
    // aksi halde parti boyunca yüzlerce boş istek atılır ve sebep log'a düşmez.
    if ($code === 401) {
        throw new RuntimeException("ccapi token geçersiz (HTTP 401) — personel #{$personelId} token'ı yenilenmeli.");
    }
    $json = json_decode((string)$resp, true);
    if ($json === null) throw new RuntimeException("HTTP {$code} — ccapi yanıtı JSON değil.");
    return $json;
}

// ── Yardımcılar ──────────────────────────────────────────────────────────────
/** "NEO - 1032568175" / "P-1032605861" → 1032568175 */
/**
 * Bir ana bayi için ccapi çağrılarında kullanılacak personeli çözer.
 *
 * Kaynak: IRIS raporu çeken cron zamanlamasının (görev kodu iris_rapor) sabit
 * parametrelerindeki personel_id. Böylece "hangi hesapla sorgulanıyor" bilgisi
 * TEK YERDE (cron zamanlaması) tanımlı kalır; panel ve servis oraya bakar.
 * Eşleşme bulunamazsa aynı ana bayinin geçerli token'lı aktif personeline,
 * o da yoksa IRIS_TALEP_PERSONEL sabitine düşülür.
 *
 * @param ?int $anaBayiId null ise doğrudan varsayılan personel döner
 */
function irisPersonelCoz($db, ?int $anaBayiId): int
{
    static $onbellek = [];
    $anahtar = (string)($anaBayiId ?? 'vars');
    if (isset($onbellek[$anahtar])) return $onbellek[$anahtar];

    if ($anaBayiId === null) return $onbellek[$anahtar] = IRIS_TALEP_PERSONEL;

    // 1) iris_rapor zamanlamalarında bu ana bayiye ait personel tanımlı mı?
    $r = $db->fetchOne("
        SELECT TOP 1 p.DigiturkAltBayiPersonel_Id AS id
        FROM CronZamanlamalar z
        INNER JOIN CronGorevler g ON g.CronGorevler_Id = z.CronZamanlamalar_GorevId
        INNER JOIN DigiturkAltBayiPersonel p
                ON p.DigiturkAltBayiPersonel_Id =
                   TRY_CAST(JSON_VALUE(z.CronZamanlamalar_SabitParametreler, '$.personel_id') AS INT)
        INNER JOIN DigiturkAltBayiler a ON a.DigiturkAltBayiler_Id = p.DigiturkAltBayiPersonel_AltBayiId
        WHERE g.CronGorevler_GorevKodu = 'iris_rapor'
          AND z.Durum = 1 AND p.Durum = 1
          AND a.DigiturkAltBayiler_AnaBayiId = ?
        ORDER BY z.CronZamanlamalar_Id", [$anaBayiId]);

    if (!empty($r['id'])) return $onbellek[$anahtar] = (int)$r['id'];

    // 2) Aynı ana bayide geçerli token'lı aktif personel
    $r = $db->fetchOne("
        SELECT TOP 1 p.DigiturkAltBayiPersonel_Id AS id
        FROM DigiturkAltBayiPersonel p
        INNER JOIN DigiturkAltBayiler a ON a.DigiturkAltBayiler_Id = p.DigiturkAltBayiPersonel_AltBayiId
        WHERE a.DigiturkAltBayiler_AnaBayiId = ?
          AND p.Durum = 1 AND p.DigiturkAltBayiPersonel_TokenDurum = 1
          AND p.DigiturkAltBayiPersonel_Token IS NOT NULL
          AND p.DigiturkAltBayiPersonel_TokenSuresi > GETDATE()
        ORDER BY p.DigiturkAltBayiPersonel_TokenSuresi DESC", [$anaBayiId]);

    return $onbellek[$anahtar] = !empty($r['id']) ? (int)$r['id'] : IRIS_TALEP_PERSONEL;
}

/** Bir başvurunun bağlı olduğu ana bayi id'si; çözülemezse null. */
function irisBasvuruAnaBayi($db, int $basvuruId): ?int
{
    $r = $db->fetchOne("
        SELECT a.DigiturkAltBayiler_AnaBayiId AS anaBayiId
        FROM Basvurular b
        LEFT JOIN DigiturkAltBayiPersonel p ON p.DigiturkAltBayiPersonel_Id = b.AltBayiPersonel_ID
        LEFT JOIN DigiturkAltBayiler     a ON a.DigiturkAltBayiler_Id = p.DigiturkAltBayiPersonel_AltBayiId
        WHERE b.Basvurular_id = ?", [$basvuruId]);

    return !empty($r['anaBayiId']) ? (int)$r['anaBayiId'] : null;
}

function irisMusteriNoTemizle($v): ?int
{
    if (!preg_match('/(\d{5,})/', (string)$v, $m)) return null;
    return (int)$m[1];
}

/** Türkçe büyük harf + boşluk normalizasyonu (İ/I, ş/s ...) */
function irisAdNormalize(?string $s): string
{
    $s = trim((string)$s);
    $s = str_replace(['İ', 'I', 'ı', 'i', 'Ş', 'ş', 'Ğ', 'ğ', 'Ü', 'ü', 'Ö', 'ö', 'Ç', 'ç'],
                     ['I', 'I', 'I', 'I', 'S', 'S', 'G', 'G', 'U', 'U', 'O', 'O', 'C', 'C'], $s);
    $s = mb_strtoupper($s, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Türkçe büyük harf (i→İ, ı→I) */
function irisTrUpper(?string $s): string
{
    return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], (string)$s), 'UTF-8');
}

/** Türkçe küçük harf (I→ı, İ→i) */
function irisTrLower(?string $s): string
{
    return mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], (string)$s), 'UTF-8');
}

/**
 * IRIS BÜYÜK HARF adını DB formatına çevirir: "BAYRAM DİLEK" → "Bayram Dilek"
 * Tire ve kesme işaretiyle ayrılan parçalar da büyütülür (ALİ-VELİ → Ali-Veli).
 */
function irisAdDuzgun(?string $s): string
{
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    if ($s === '') return '';

    return preg_replace_callback('/[^\s\-\']+/u', function ($m) {
        $k = $m[0];
        return irisTrUpper(mb_substr($k, 0, 1, 'UTF-8')) . irisTrLower(mb_substr($k, 1, null, 'UTF-8'));
    }, $s);
}

/** normalize(AdSoyad) => ['id','adSoyad','altBayiId'] */
function irisPersonelHaritasi($db): array
{
    $map = [];
    foreach ($db->fetchAll("SELECT DigiturkAltBayiPersonel_Id AS id, DigiturkAltBayiPersonel_AdSoyad AS adSoyad,
                                   DigiturkAltBayiPersonel_AltBayiId AS altBayiId
                            FROM DigiturkAltBayiPersonel WHERE Durum = 1") as $r) {
        $map[irisAdNormalize($r['adSoyad'])] = $r;
    }
    return $map;
}

/**
 * Personel → birim haritası: personelId => ['adi','renk']
 *
 * Birim, basvuru-yonetimi.php ile aynı önceliği kullanır:
 *   1) KullaniciBirimYetkileri.Personel_id doğrudan personele bağlıysa
 *   2) yoksa personelin bağlı olduğu AltBayi_id üzerinden
 */
function irisPersonelBirimHaritasi($db): array
{
    $rows = $db->fetchAll("
        SELECT p.DigiturkAltBayiPersonel_Id AS id, birim.BirimAdi, birim.BirimRenk
        FROM DigiturkAltBayiPersonel p
        OUTER APPLY (
            SELECT TOP 1 x.Adi AS BirimAdi, x.Renk AS BirimRenk
            FROM (
                SELECT 1 AS oncelik, b.KullaniciBirim_Adi AS Adi, b.KullaniciBirim_Renk AS Renk
                  FROM KullaniciBirimYetkileri kby
                  INNER JOIN KullaniciBirim b ON b.KullaniciBirim_id = kby.KullaniciBirimYetkileri_Birim_id
                  WHERE kby.Durum = 1 AND kby.KullaniciBirimYetkileri_Personel_id = p.DigiturkAltBayiPersonel_Id
                UNION ALL
                SELECT 2, b2.KullaniciBirim_Adi, b2.KullaniciBirim_Renk
                  FROM KullaniciBirimYetkileri kby2
                  INNER JOIN KullaniciBirim b2 ON b2.KullaniciBirim_id = kby2.KullaniciBirimYetkileri_Birim_id
                  WHERE kby2.Durum = 1 AND kby2.KullaniciBirimYetkileri_AltBayi_id = p.DigiturkAltBayiPersonel_AltBayiId
            ) x
            ORDER BY x.oncelik
        ) birim
        WHERE p.Durum = 1");

    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['id']] = ['adi' => $r['BirimAdi'], 'renk' => $r['BirimRenk'] ?: '#6c757d'];
    }
    return $map;
}

/** BasvuruDurum_DurumKodu => BasvuruDurum_id */
function irisDurumKoduHaritasi($db): array
{
    $map = [];
    foreach ($db->fetchAll("SELECT BasvuruDurum_id AS id, BasvuruDurum_DurumKodu AS kod FROM BasvuruDurum") as $r) {
        if (!isset($map[(int)$r['kod']])) $map[(int)$r['kod']] = (int)$r['id'];
    }
    return $map;
}

/** Basvurular kaydından IRIS'e sorulacak 10 haneli numara */
function irisTelefonBirlestir(?string $alan, ?string $numara): string
{
    return preg_replace('/\D/', '', (string)$alan . (string)$numara);
}

function irisTalepDetayGetir(array $o, array $item): array
{
    $r = irisWebPost($o['ch'], 'Requisition/GetRequisitionDetail', [
        'RequisitionId'         => $item['RequisitionId'] ?? $item['Id'] ?? null,
        'RequisitionRecordType' => $item['MemoIdType']    ?? null,
        'MemoCaseType'          => $item['MemoCaseType']  ?? null,
    ], $o['webToken'], $o['verToken']);
    return irisVeri($r);
}

/**
 * Telefonla arar; "Talep Kaydeden"i personel listemizle eşleşen talebi seçer.
 *
 * Dönüş: [
 *   'sonuc'    => 'eslesti' | 'talep_yok' | 'personel_eslesmedi',
 *   'talep'    => arama listesi öğesi,
 *   'detay'    => GetRequisitionDetail EntityData,
 *   'personel' => ['id','adSoyad','altBayiId'],
 *   'kaydeden' => 'AD SOYAD',
 *   'adaylar'  => tüm adaylar (kaydeden/personel dahil)
 * ]
 */
function irisTalepAra(array $o, string $tel, array $perMap): array
{
    $r = irisWebPost($o['ch'], 'Requisition/SearchRequisition',
        ['Value' => $tel, 'ParameterType' => IRIS_ARAMA_TELEFON], $o['webToken'], $o['verToken']);

    $items = irisVeri($r)['RequisitionItems'] ?? [];
    if (!$items) return ['sonuc' => 'talep_yok', 'adaylar' => []];

    $adaylar = [];
    foreach ($items as $it) {
        $det      = irisTalepDetayGetir($o, $it);
        $kaydeden = trim(($det['EntryPersonnelName'] ?? '') . ' ' . ($det['EntryPersonnelSurname'] ?? ''));
        $per      = $perMap[irisAdNormalize($kaydeden)] ?? null;
        $adaylar[] = ['talep' => $it, 'detay' => $det, 'kaydeden' => $kaydeden, 'personel' => $per];
        if ($per) {
            return ['sonuc' => 'eslesti', 'talep' => $it, 'detay' => $det,
                    'personel' => $per, 'kaydeden' => $kaydeden, 'adaylar' => $adaylar];
        }
    }
    return ['sonuc' => 'personel_eslesmedi', 'adaylar' => $adaylar];
}

/** Eşleşme sonucundan Basvurular'a yazılacak alanları üretir (kural bazlı). */
function irisYazilacakAlanlar(array $eslesme, array $mevcutKayit, array $durumMap, bool $personeliEz = false): array
{
    $it  = $eslesme['talep'];
    $det = $eslesme['detay'];

    $veri = [
        'MusteriNo'    => irisMusteriNoTemizle($det['AccountNo'] ?? ($it['AccountNo'] ?? null)),
        'TalepKayitNo' => (int)($it['RequisitionId'] ?? $det['Id'] ?? 0),
        'MemoID'       => (int)($it['MemoId'] ?? $det['MemoId'] ?? 0),
    ];

    $surec = isset($det['RequisitonStatus']) ? (int)$det['RequisitonStatus'] : 0;
    if ($surec > 0) $veri['BasvuruSurecDurum_ID'] = $surec;
    if (isset($durumMap[0])) $veri['BasvuruDurum_ID'] = $durumMap[0];

    // Durum mesajı: IRIS'in yazdığı başlık (ör. "Tamamlandı", "Teyit Bekliyor")
    $mesaj = trim((string)($det['RequisitionStatusTitle'] ?? $it['Status'] ?? ''));
    if ($mesaj !== '') $veri['BasvuruDurumMesaj'] = mb_substr($mesaj, 0, 500);

    // AltBayiPersonel_ID: boşsa yazılır; doluysa yalnızca $personeliEz ile ezilir.
    if (empty($mevcutKayit['AltBayiPersonel_ID']) || $personeliEz) {
        $veri['AltBayiPersonel_ID'] = (int)$eslesme['personel']['id'];
    }

    // Isim / Soyisim IRIS'teki üye bilgisiyle güncellenir (BÜYÜK HARF → düzgün yazım).
    $isim    = irisAdDuzgun($det['Name']    ?? null);
    $soyisim = irisAdDuzgun($det['Surname'] ?? null);
    if ($isim    !== '') $veri['Isim']    = $isim;
    if ($soyisim !== '') $veri['Soyisim'] = $soyisim;

    return $veri;
}

/**
 * Talep numaralarının süreç durumunu sorgular → [talepNo => data satırı]
 *
 * Digiturk 08.09.2026 sürümünde toplu uç Order/CheckRequisitionList'i KALDIRDI
 * (canlıda 404). Bu yüzden tekil uç Order/CheckRequisition kullanılır:
 *   - RequestId GÖVDEDE DEĞİL, QUERY STRING'de gider (gövdeyle gönderilirse
 *     parametre okunmaz, "0 talep numaralı kayıt" hatası döner)
 *   - Gövde hiç gönderilmezse IIS "411 Length Required" verir; boş gövde
 *     (Content-Length: 0) zorunludur
 * Talep başına bir istek atılır; günlük limit bayi + talep id bazlı 40'tır.
 *
 * @param ?int $anaBayiId Sorgunun hangi bayinin hesabıyla yapılacağı (bkz. irisPersonelCoz)
 * @param int  $bekleMs   İstekler arası bekleme (ms). API'yi yormamak için.
 */
function irisDurumSorgula($db, array $talepNolar, ?int $anaBayiId = null, int $bekleMs = IRIS_CAGRI_BEKLEME): array
{
    $sonuc = [];
    $nolar = array_values(array_unique(array_filter(array_map('intval', $talepNolar))));
    $ilk   = true;

    // Tek tük geçici hata (anlık kesinti, timeout) tüm partiyi düşürmemeli; ama
    // üst üste hata alınıyorsa servis gerçekten kapalıdır, boşuna kota harcanmaz.
    $ardisikHata = 0;
    $sonHata     = null;

    foreach ($nolar as $no) {
        if (!$ilk && $bekleMs > 0) usleep($bekleMs * 1000);
        $ilk = false;

        try {
            $r = irisCcapiGet($db, IRIS_EP_DURUM, ['RequestId' => $no], $anaBayiId);
        } catch (Throwable $e) {
            $sonHata = $e->getMessage();
            if (++$ardisikHata >= 3) {
                throw new RuntimeException("3 ardışık istek hatası, parti durduruldu: {$sonHata}");
            }
            continue;
        }
        $ardisikHata = 0;

        // responseCode 6 = "son 1 yıl içinde oluşturulmadı" gibi kayda özel durumlar;
        // tüm turu düşürmemek için bu talep atlanır.
        if (($r['responseCode'] ?? -1) !== 0) {
            continue;
        }
        $d = $r['data'] ?? null;
        if (!is_array($d)) continue;

        $donen = (int)($d['requestID'] ?? 0);
        if ($donen > 0) $sonuc[$donen] = $d;
    }
    return $sonuc;
}

} // function_exists guard
