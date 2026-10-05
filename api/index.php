<?php
/**
 * Kendi API Gateway'imiz
 * Digiturk CC API'sini sarmalar. İstek doğrudan Digiturk'e değil buraya gelir;
 * burada DigiturkAltBayiPersonel doğrulaması + token kalıcılığı yapılıp Digiturk'e iletilir.
 *
 * URL şeması (web.config rewrite): /api/{Kategori}/{Aksiyon}  ->  api/index.php?__route={R:1}
 * Örn: POST /api/Authentication/Login
 */

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/includes/BasvuruLogHelper.php';
require_once __DIR__ . '/../admin/includes/EntegrasyonHelper.php';
require_once __DIR__ . '/../admin/includes/AdresYanitHelper.php';
require_once __DIR__ . '/../admin/includes/KaraListeHelper.php';

header('Content-Type: application/json; charset=utf-8');

/** JSON yanıt verip çıkar. Çıkmadan önce isteği ApiGuvenlikLog'a yazar. */
function jsonOut($data, int $httpCode = 200): void
{
    apiGuvenlikLogYaz($httpCode);
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Gerçek istemci IP'si (Cloudflare arkasında CF-Connecting-IP). */
function apiIstemciIp(): ?string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', (string)$_SERVER[$k])[0]); // XFF ilk değer = gerçek istemci
            return mb_substr($ip, 0, 45);
        }
    }
    return null;
}

/**
 * İsteği ApiGuvenlikLog'a yazar (jsonOut ve shutdown'dan çağrılır).
 * Log hatası ana akışı bozmaz; çift yazımı guard'la önlenir.
 */
function apiGuvenlikLogYaz(int $httpCode): void
{
    if (empty($GLOBALS['_apiLog']) || !empty($GLOBALS['_apiLog']['_yazildi'])) return;
    $GLOBALS['_apiLog']['_yazildi'] = true;
    $c = $GLOBALS['_apiLog'];
    try {
        $sonuc = $c['sonuc'] ?? null;
        if ($sonuc === null) {
            $sonuc = $httpCode === 200 ? 'BASARILI'
                   : ($httpCode === 401 ? 'YETKISIZ'
                   : ($httpCode === 429 ? 'RATE_LIMIT' : 'BASARISIZ'));
        }
        $sureMs = (int)round((microtime(true) - $c['start']) * 1000);
        Database::getInstance()->insert('ApiGuvenlikLog', [
            'ApiGuvenlikLog_IP'             => $c['ip'],
            'ApiGuvenlikLog_Endpoint'       => mb_substr((string)$c['endpoint'], 0, 255),
            'ApiGuvenlikLog_Metod'          => $c['metod'],
            'ApiGuvenlikLog_Personel_id'    => $c['personel'],
            'ApiGuvenlikLog_Organisation'   => $c['org']   !== null ? mb_substr((string)$c['org'], 0, 100)   : null,
            'ApiGuvenlikLog_LoginKullanici' => $c['login'] !== null ? mb_substr((string)$c['login'], 0, 100) : null,
            'ApiGuvenlikLog_HttpKodu'       => $httpCode,
            'ApiGuvenlikLog_Sonuc'          => $sonuc,
            'ApiGuvenlikLog_SureMs'         => $sureMs,
            'ApiGuvenlikLog_UserAgent'      => ($c['ua'] ?? '') !== '' ? $c['ua'] : null,
            'ApiGuvenlikLog_Aciklama'       => $c['aciklama'] ?? null,
            'OlusturanKullanici'            => 0, // API kaynaklı = sistem; çağıran personel _Personel_id'de
            'OlusturmaTarihi'               => date('Y-m-d H:i:s'),
            'Durum'                         => 1,
        ]);
    } catch (Throwable $e) {
        error_log('ApiGuvenlikLog yazılamadı: ' . $e->getMessage());
    }
}

// Login token'ının bitimine bu kadar dakika kalmışsa önbellek kullanılmaz, yenilenir.
// NOT: Yönlendirme aşağıda başladığı için sabit BURADA tanımlanmalı; fonksiyon
// gövdesinin yanına konursa handleLogin çağrıldığında henüz tanımlı olmaz.
defined('LOGIN_ONBELLEK_PAY_DK') || define('LOGIN_ONBELLEK_PAY_DK', 60);

$route  = trim((string)($_GET['__route'] ?? ''), '/');
$method = $_SERVER['REQUEST_METHOD'];

// JSON gövdeyi oku
$rawBody = file_get_contents('php://input');
$input   = json_decode((string)$rawBody, true);
if (!is_array($input)) $input = [];

$db = Database::getInstance();

// ─── API güvenlik/erişim logu bağlamı ─────────────────────────────────────────
// Her istek jsonOut() (veya fatal olursa shutdown) üzerinden ApiGuvenlikLog'a yazılır.
$GLOBALS['_apiLog'] = [
    'start'    => microtime(true),
    'ip'       => apiIstemciIp(),
    'endpoint' => $route,
    'metod'    => $method,
    'personel' => null,   // token/başarılı login çözülünce handler doldurur
    'org'      => null,   // login denemesindeki OrganisationCd
    'login'    => null,   // login denemesindeki LoginCd (ŞİFRE loglanmaz)
    'ua'       => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
    'sonuc'    => null,   // null ise httpCode'dan türetilir
    'aciklama' => null,
];
// Handler jsonOut çağırmadan ölürse (fatal/timeout) yine de logla.
register_shutdown_function(function () {
    if (!empty($GLOBALS['_apiLog']) && empty($GLOBALS['_apiLog']['_yazildi'])) {
        apiGuvenlikLogYaz((int)(http_response_code() ?: 500));
    }
});

// ─── Yönlendirme ──────────────────────────────────────────────────────────────
// Login: özel mantık (DB doğrulama + token kalıcılaştırma).
// Diğerleri: tablo-driven generic proxy (gelen Token header'ı Digiturk'e iletilir).
switch (strtolower($route)) {

    case 'authentication/login':
        handleLogin($db, $input, $method);
        break;

    // Kampanya listeleri: Digiturk'e GİTMEZ, kendi APIKampanyalar tablomuzdan
    // Digiturk formatında üretilir (yalnız Durum=1 aktif kampanyalar).
    case 'order/getneocampaignlist':
        handleCampaignList($db, 1);
        break;

    case 'order/getsatellitecampaignlist':
        handleCampaignList($db, 2);
        break;

    // ISP kampanya listeleri: bbkId GÖNDERİLMEZSE Digiturk'e GİTMEZ, APIKampanyalar
    // tablosundaki aktif ISP kayıtlarından üretilir (adresten bağımsız genel katalog).
    // bbkId gönderilirse istek Digiturk'e devredilir; adrese özel güncel fiyat/hız gelir.
    case 'order/getispandneocampaignlist':
        handleIspCampaignList($db, 'ISP+NEO', 1, $input, $route, $method, $rawBody);
        break;

    case 'order/getispandsatellitecampaignlist':
        handleIspCampaignList($db, 'ISP+UYDU', 2, $input, $route, $method, $rawBody);
        break;

    // Şehir listesi: Digiturk'e GİTMEZ. APISehirler tablosundan Digiturk
    // formatında üretilir; her şehre koi/superKoi eklenir. Token gerekmez.
    case 'address/getallcities':
        handleGetAllCities($db);
        break;

    // CheckRequisition: RequestId'yi query'de bekler (body değil)
    case 'order/checkrequisition':
        handleProxy($db, $route, $method, $rawBody, true);
        break;

    // Sipariş oluştur: Digiturk'e gönderir + Basvurular'a kaydeder, yanıta Basvurular_id ekler
    case 'order/createneo':
        handleCreateOrder($db, 1, $rawBody);
        break;
    case 'order/createsatellite':
        handleCreateOrder($db, 2, $rawBody);
        break;
    // İnternetli paketler. Proxy dalına düşerlerse Basvurular kaydı oluşmuyordu
    // (dış siteden gelen başvurular sistemde görünmüyordu) — buradan geçmeliler.
    case 'order/createispandneo':
        handleCreateOrder($db, 3, $rawBody);
        break;
    case 'order/createispandsatellite':
        handleCreateOrder($db, 4, $rawBody);
        break;

    // Reklam Lead Formları: Digiturk'e GİTMEZ. Token sahibi personelin bağlı
    // olduğu alt bayinin yetkili olduğu birim(ler)e tanımlı reklam hedefleri
    // üzerinden ulaşılan aktif Lead Formlarını listeler.
    case 'lead/formlist':
        handleReklamLeadFormList($db);
        break;

    // Çağrı Merkezi API Lead: Token sahibi personelin alt bayisinin yetkili
    // olduğu birim(ler)e tanımlı CallCenterApiLead kayıtlarını listeler.
    case 'callcenter/apileadlist':
        handleCallCenterApiLeadList($db);
        break;

    // OTP (SMS Doğrulama): Digiturk CC'ye GİTMEZ, kendi OTP altyapımızı kullanır.
    // Gonder → başvuru bul/oluştur + digiturkBasvuruGonder (redirectUrl'li).
    // Durum  → Basvurular OTP durumunu döner.
    case 'otp/gonder':
        handleOtpGonder($db, $rawBody, $method);
        break;
    case 'otp/durum':
        handleOtpDurum($db, $method);
        break;
    case 'otp/kanaltipleri':
        handleOtpKanalTipleri($db, $method);
        break;

    default:
        handleProxy($db, $route, $method, $rawBody);
}

// ─── Handler'lar ──────────────────────────────────────────────────────────────

/**
 * POST /api/Authentication/Login
 * Body: { OrganisationCd, LoginCd, Password }
 * - Bilgiler DigiturkAltBayiPersonel'de kayıtlıysa ÖNCE kayıtlı token denenir.
 * - Token yok/süresi dolmak üzereyse Digiturk'e iletilir ve yanıt saklanır.
 * - Kayıt yoksa veya Digiturk hata dönerse hata verir.
 *
 * ÖNBELLEK NEDENİ: Digiturk Login metodunu BAYİ (OrganisationCd) bazlı GÜNLÜK 10
 * istekle sınırladı. Her istemci çağrısını yukarı geçirmek kotayı dakikalar içinde
 * bitiriyordu (ApiGuvenlikLog: 06.09.2026 → 41 istek / 24 adet 429). Token +1 gün
 * geçerli olduğundan aynı token yeniden dağıtılır; Digiturk'e yalnız süre dolunca
 * gidilir. Saklanan HAM yanıt (_TokenYanit) aynen döndürülür, sözleşme değişmez.
 *
 * 14.09.2026: Login akışı ortak digiturkLogin() fonksiyonuna taşındı. Yerel sayaç
 * artık engellemez; Digiturk "limit" derse bayi, şifre hatasında personel kilitlenir
 * (DigiturkLoginHataKurallari) ve kilit süresince istemci çağrıları Digiturk'e gitmez.
 */
// Önbellek payı sabiti (LOGIN_ONBELLEK_PAY_DK) dosyanın başında tanımlıdır.
function handleLogin($db, array $input, string $method): void
{
    if ($method !== 'POST') {
        jsonOut(['data' => null, 'responseCode' => 405, 'responseMessage' => 'Yalnızca POST desteklenir.'], 405);
    }

    $org   = trim((string)($input['OrganisationCd'] ?? ''));
    $login = trim((string)($input['LoginCd'] ?? ''));
    $pass  = (string)($input['Password'] ?? '');

    // Güvenlik logu: denemenin org + kullanıcı adı (ŞİFRE ASLA loglanmaz)
    $GLOBALS['_apiLog']['org']   = $org;
    $GLOBALS['_apiLog']['login'] = $login;

    if ($org === '' || $login === '' || $pass === '') {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'OrganisationCd, LoginCd ve Password zorunludur.'], 400);
    }

    // 1) Bizde kayıtlı mı? (OrganisationCd = AnaBayi BayiKodu)
    $per = $db->fetchOne("
        SELECT p.DigiturkAltBayiPersonel_Id,
               p.DigiturkAltBayiPersonel_Token       AS Token,
               p.DigiturkAltBayiPersonel_TokenSuresi AS TokenSuresi,
               p.DigiturkAltBayiPersonel_TokenDurum  AS TokenDurum,
               p.DigiturkAltBayiPersonel_TokenYanit  AS TokenYanit
        FROM DigiturkAltBayiPersonel p
        LEFT JOIN DigiturkAltBayiler a ON p.DigiturkAltBayiPersonel_AltBayiId = a.DigiturkAltBayiler_Id
        LEFT JOIN DigiturkAnaBayiler n ON a.DigiturkAltBayiler_AnaBayiId      = n.DigiturkAnaBayiler_Id
        WHERE n.DigiturkAnaBayiler_BayiKodu              = ?
          AND p.DigiturkAltBayiPersonel_KullaniciAdi     = ?
          AND p.DigiturkAltBayiPersonel_Sifre            = ?
          AND p.Durum = 1
    ", [$org, $login, $pass]);

    if (!$per) {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'Bu bilgilerle kayıtlı personel bulunamadı.'], 401);
    }
    $pId = (int)$per['DigiturkAltBayiPersonel_Id'];
    $GLOBALS['_apiLog']['personel'] = $pId; // deneme bizde eşleşti

    // 2) Ortak login: önbellek, bayi limiti ve şifre/bekleme kilitleri fonksiyonda uygulanır
    require_once dirname(__DIR__) . '/admin/includes/DigiturkKotaServisi.php';
    $s = digiturkLogin($db, $pId, 'api', false, LOGIN_ONBELLEK_PAY_DK);

    // 2a) Başarılı — önbellekten ya da Digiturk'ten
    if ($s['basarili']) {
        if ($s['onbellek']) {
            // Saklanan ham yanıt varsa aynen döndürülür; token güncel değerle tazelenir
            $yanit = json_decode((string)($per['TokenYanit'] ?? ''), true);
            if (!is_array($yanit) || !isset($yanit['data'])) {
                $yanit = ['data' => ['token' => $s['token']], 'responseCode' => 0, 'responseMessage' => null];
            }
            $yanit['data']['token'] = $s['token'];
            $GLOBALS['_apiLog']['aciklama'] = 'Login önbellekten (token bitiş: ' . $per['TokenSuresi'] . ')';
            jsonOut($yanit, 200);
        }
        $GLOBALS['_apiLog']['aciklama'] = 'Login Digiturk\'e gitti (token yenilendi)';
        jsonOut($s['yanit'] ?? ['data' => ['token' => $s['token']], 'responseCode' => 0, 'responseMessage' => null], 200);
    }

    // 2b) Başarısız — log sonucu HTTP koduna değil hata türüne göre yazılır
    //     (Digiturk şifre hatasını HTTP 200 ile dönüyor; eskiden BASARILI loglanıyordu)
    $GLOBALS['_apiLog']['sonuc']    = in_array($s['hata_turu'], ['limit', 'personel_limit'], true) ? 'RATE_LIMIT' : 'BASARISIZ';
    $GLOBALS['_apiLog']['aciklama'] = mb_substr(
        ($s['istek_gitti'] ? 'Login Digiturk\'e gitti, reddedildi' : 'Login Digiturk\'e gitmedi')
        . " [{$s['hata_turu']}]: {$s['mesaj']}", 0, 500);

    // Digiturk cevap verdiyse yanıtı ve HTTP kodunu aynen geçir (sözleşme değişmez)
    if ($s['istek_gitti'] && is_array($s['yanit'])) {
        jsonOut($s['yanit'], $s['digiturk_http'] ?: $s['http']);
    }

    // Digiturk'e gidilmedi (kilit / eksik bilgi) veya bağlantı hatası → Digiturk formatında cevap
    jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => $s['mesaj']], $s['http']);
}

/**
 * APIKampanyalar satırını Digiturk offer JSON'una çevirir.
 * $koiEkle=true ise (Satellite) offer'a koi/superKoi alanları eklenir.
 */
function offerYap(array $r, bool $koiEkle = false): array
{
    $fiyat = isset($r['APIKampanyalar_Fiyat']) ? (float)$r['APIKampanyalar_Fiyat'] : 0.0;
    $offer = [
        'billFrequency'       => 1,
        'billFrequencyTypeCd' => $r['APIKampanyalar_FaturaDonemi'] ?? null,
        'currencyTypeCd'      => $r['APIKampanyalar_ParaBirimi'] ?? 'TL',
        'description'         => $r['APIKampanyalar_PaketAdi'] ?? null,
        'name'                => $r['APIKampanyalar_Ad'] ?? null,
        'offerFromCode'       => $r['APIKampanyalar_OfferFromCode'] ?? null,
        'offerFromId'         => isset($r['APIKampanyalar_OfferFromId']) ? (int)$r['APIKampanyalar_OfferFromId'] : null,
        'offerToCode'         => $r['APIKampanyalar_OfferToCode'] ?? null,
        'offerToId'           => isset($r['APIKampanyalar_OfferToId']) ? (int)$r['APIKampanyalar_OfferToId'] : null,
        // Tam sayıysa int (Digiturk böyle: 5268, 499); ondalıklıysa float
        'priceAmount'         => ($fiyat == (int)$fiyat) ? (int)$fiyat : $fiyat,
    ];
    if ($koiEkle) {
        $offer['koi']      = (bool)(int)($r['APIKampanyalar_KOI'] ?? 0);
        $offer['superKoi'] = (bool)(int)($r['APIKampanyalar_SUPERKOI'] ?? 0);
    }
    return $offer;
}

/** Kategori adından Digiturk entityResult bloğunu üretir. */
function entityResultYap(string $kategoriAdi): array
{
    return [
        'entityJsonTypeList' => json_encode(
            ['GORUNEN_ISIM' => $kategoriAdi, 'ACIKLAMA' => $kategoriAdi],
            JSON_UNESCAPED_UNICODE
        ),
        'logoList'  => null,
        'sortOrder' => 9999,
    ];
}

/**
 * Kampanya listesi (Order/GetNeoCampaignList=Tur1, GetSatelliteCampaignList=Tur2).
 * Digiturk'e gitmez; APIKampanyalar tablosundan Durum=1 kayıtlarla Digiturk
 * formatında yanıt üretir. Kök kategori (FATURALI/KREDI içermeyen) üst seviyeye,
 * diğerleri subProductCatalogList'e yerleştirilir.
 */
function handleCampaignList($db, int $tur): void
{
    // Tür için tüm kategoriler (kök tespiti Durum'dan bağımsız)
    $katRows = $db->fetchAll("
        SELECT DISTINCT APIKampanyalar_KategoriAdi
        FROM APIKampanyalar
        WHERE APIKampanyalar_Tur = ? AND APIKampanyalar_KategoriAdi IS NOT NULL
        ORDER BY APIKampanyalar_KategoriAdi
    ", [$tur]);
    $kategoriler = array_column($katRows, 'APIKampanyalar_KategoriAdi');

    // Kök kategori: FATURALI ve KREDI içermeyen ilk kategori
    $rootKat = null;
    foreach ($kategoriler as $k) {
        $u = mb_strtoupper((string)$k, 'UTF-8');
        if (mb_strpos($u, 'FATURALI') === false && mb_strpos($u, 'KREDI') === false) {
            $rootKat = $k;
            break;
        }
    }

    // Aktif offerlar, kategoriye göre
    $offers = $db->fetchAll("
        SELECT APIKampanyalar_KategoriAdi, APIKampanyalar_Ad, APIKampanyalar_PaketAdi,
               APIKampanyalar_OfferFromCode, APIKampanyalar_OfferFromId,
               APIKampanyalar_OfferToCode, APIKampanyalar_OfferToId,
               APIKampanyalar_Fiyat, APIKampanyalar_ParaBirimi, APIKampanyalar_FaturaDonemi,
               APIKampanyalar_KOI, APIKampanyalar_SUPERKOI
        FROM APIKampanyalar
        WHERE APIKampanyalar_Tur = ? AND Durum = 1
        ORDER BY APIKampanyalar_KategoriAdi, APIKampanyalar_Id
    ", [$tur]);

    $koiEkle = ($tur === 2); // koi/superKoi alanları yalnız Satellite'te
    $byKat = [];
    foreach ($offers as $o) {
        $byKat[(string)$o['APIKampanyalar_KategoriAdi']][] = offerYap($o, $koiEkle);
    }

    $rootName = $rootKat ?? ($kategoriler[0] ?? '');

    $data = [
        'entityResult'          => entityResultYap((string)$rootName),
        'offerResultList'       => $byKat[$rootName] ?? [],
        'subProductCatalogList' => [],
    ];

    foreach ($kategoriler as $k) {
        if ($k === $rootName) continue;
        if (empty($byKat[$k])) continue; // aktif offer yoksa alt katalog ekleme
        $data['subProductCatalogList'][] = [
            'entityResult'          => entityResultYap((string)$k),
            'offerResultList'       => $byKat[$k],
            'subProductCatalogList' => null,
        ];
    }

    jsonOut(['data' => $data, 'responseCode' => 0, 'responseMessage' => null], 200);
}

/**
 * ISP kampanya listesi (Order/GetIspAndNeoCampaignList  = ISP+NEO,
 *                       Order/GetIspAndSatelliteCampaignList = ISP+UYDU).
 *
 * bbkId geçerliyse (>0) istek Digiturk'e devredilir: fiyat ve hız varyantları
 * adrese bağlı olduğu için canlı yanıt her zaman önceliklidir. bbkId yoksa
 * APIKampanyalar (Tur=3, Durum=1) kayıtlarından Digiturk formatında genel
 * katalog üretilir.
 *
 * NEO/UYDU'dan farkı: yanıtta entityResult ve subProductCatalogList YOKTUR,
 * düz bir offerResultList döner; fiyat alanı priceAmount değil price'tır ve
 * her offer'ın altında TV paketini taşıyan bundleOfferResultList bulunur.
 */
function handleIspCampaignList($db, string $kategoriAdi, int $bundleTur, array $input,
                               string $route, string $method, string $rawBody): void
{
    // Adres verilmişse canlıya devret (DB kataloğu adresten bağımsızdır)
    if (isset($input['bbkId']) && (int)$input['bbkId'] > 0) {
        handleProxy($db, $route, $method, $rawBody);
        return;
    }

    // Bundle (TV paketi) offerTo çifti tabloda ayrı tutulmadığı için aynı
    // PaketAdi'na sahip NEO/UYDU kaydından çözülür; kod ve id canlı yanıtla
    // birebir örtüşür (NOA_SRV/345942, NOY_SRV/345939).
    $rows = $db->fetchAll("
        SELECT
            i.APIKampanyalar_Ad,
            i.APIKampanyalar_Aciklama,
            i.APIKampanyalar_PaketAdi,
            i.APIKampanyalar_OfferFromCode,
            i.APIKampanyalar_OfferFromId,
            i.APIKampanyalar_OfferToCode,
            i.APIKampanyalar_OfferToId,
            i.APIKampanyalar_Fiyat,
            i.APIKampanyalar_ParaBirimi,
            i.APIKampanyalar_FaturaDonemi,
            b.BundleOfferToCode,
            b.BundleOfferToId
        FROM APIKampanyalar i
        OUTER APPLY (
            SELECT TOP 1
                p.APIKampanyalar_OfferToCode AS BundleOfferToCode,
                p.APIKampanyalar_OfferToId   AS BundleOfferToId
            FROM APIKampanyalar p
            WHERE p.APIKampanyalar_Tur      = ?
              AND p.APIKampanyalar_PaketAdi = i.APIKampanyalar_PaketAdi
            ORDER BY p.APIKampanyalar_Id
        ) b
        WHERE i.APIKampanyalar_Tur        = 3
          AND i.APIKampanyalar_KategoriAdi = ?
          AND i.Durum = 1
        ORDER BY i.APIKampanyalar_OfferFromCode, i.APIKampanyalar_Id
    ", [$bundleTur, $kategoriAdi]);

    $offerResultList = [];
    foreach ($rows as $r) {
        $offerResultList[] = ispOfferYap($r);
    }

    jsonOut([
        'data'            => ['offerResultList' => $offerResultList],
        'responseCode'    => 0,
        'responseMessage' => null,
    ], 200);
}

/**
 * Tek bir ISP offer'ını Digiturk formatında üretir.
 *
 * price = internet + TV toplamıdır; tabloda yalnız toplam tutulduğu için tamamı
 * ana satıra yazılır, bundle price 0 döner (toplam tutar doğru görünür).
 * equipment/additional listeleri (kiralık modem, STATIK IP) tabloda tutulmaz;
 * bunlar opsiyonel kalemlerdir ve yalnız bbkId ile yapılan canlı çağrıda gelir.
 */
function ispOfferYap(array $r): array
{
    $fiyat  = isset($r['APIKampanyalar_Fiyat']) ? (float)$r['APIKampanyalar_Fiyat'] : 0.0;
    $price  = ($fiyat == (int)$fiyat) ? (int)$fiyat : $fiyat;
    $donem  = $r['APIKampanyalar_FaturaDonemi'] ?? 'AY';
    $birim  = $r['APIKampanyalar_ParaBirimi']   ?? 'TL';

    $bundleList = [];
    if (!empty($r['BundleOfferToCode'])) {
        $bundleList[] = [
            'billFrequency'       => 1,
            'billFrequencyTypeCd' => $donem,
            'currencyTypeCd'      => $birim,
            'description'         => $r['APIKampanyalar_PaketAdi'] ?? null,
            'name'                => $r['APIKampanyalar_PaketAdi'] ?? null,
            // Bundle'ın offerFrom'u ana offer ile aynıdır (canlı yanıtta da öyle)
            'offerFromCode'       => $r['APIKampanyalar_OfferFromCode'] ?? null,
            'offerFromId'         => isset($r['APIKampanyalar_OfferFromId']) ? (int)$r['APIKampanyalar_OfferFromId'] : null,
            'offerToCode'         => $r['BundleOfferToCode'],
            'offerToId'           => (int)$r['BundleOfferToId'],
            'price'               => 0,
            'productCatalogCd'    => null,
        ];
    }

    return [
        'additionalOfferResultList' => [],
        'bundleOfferResultList'     => $bundleList,
        'equipmentOfferResultList'  => [],
        'billFrequency'             => 1,
        'billFrequencyTypeCd'       => $donem,
        'currencyTypeCd'            => null, // canlı yanıtta ana satırda null gelir
        'description'               => $r['APIKampanyalar_Aciklama'] ?? null,
        'name'                      => null, // canlı yanıtta ana satırda null gelir
        'offerFromCode'             => $r['APIKampanyalar_OfferFromCode'] ?? null,
        'offerFromId'               => isset($r['APIKampanyalar_OfferFromId']) ? (int)$r['APIKampanyalar_OfferFromId'] : null,
        'offerToCode'               => $r['APIKampanyalar_OfferToCode'] ?? null,
        'offerToId'                 => isset($r['APIKampanyalar_OfferToId']) ? (int)$r['APIKampanyalar_OfferToId'] : null,
        'price'                     => $price,
        'productCatalogCd'          => null, // tabloda tutulmuyor
    ];
}

/**
 * GET /api/Address/GetAllCities
 * Digiturk'e gitmez; APISehirler (Durum=1) tablosundan Digiturk formatında
 * şehir listesi üretir. Ham yanıt (RawResponse) varsa temel alınır (orijinal
 * yapı korunur), üstüne koi/superKoi eklenir; yoksa name/code'dan minimal obje
 * kurulur. Token gerekmez.
 */
function handleGetAllCities($db): void
{
    $rows = $db->fetchAll("
        SELECT APISehirler_Kod, APISehirler_Ad, APISehirler_KOI,
               APISehirler_SuperKOI, APISehirler_RawResponse
        FROM APISehirler
        WHERE Durum = 1
        ORDER BY APISehirler_Ad
    ");

    $data = [];
    foreach ($rows as $r) {
        $item = null;
        if (!empty($r['APISehirler_RawResponse'])) {
            $decoded = json_decode($r['APISehirler_RawResponse'], true);
            if (is_array($decoded)) $item = $decoded;
        }
        if ($item === null) {
            $item = [
                'name' => $r['APISehirler_Ad'],
                'code' => (int)$r['APISehirler_Kod'],
            ];
        }
        $item['koi']      = (bool)(int)$r['APISehirler_KOI'];
        $item['superKoi'] = (bool)(int)$r['APISehirler_SuperKOI'];
        $data[] = $item;
    }

    jsonOut(['data' => $data, 'responseCode' => 0, 'responseMessage' => null], 200);
}

/** Değer boş mu? null, '' veya 0/'0' → true. */
function apiBosMu($v): bool
{
    if ($v === null) return true;
    $s = trim((string)$v);
    return ($s === '' || $s === '0');
}

/** Ad-soyaddan rastgele gmail üretir (ör. adsoyad123@gmail.com). */
function apiEmailUret(string $ad, string $soy): string
{
    $map = ['ç'=>'c','Ç'=>'c','ğ'=>'g','Ğ'=>'g','ı'=>'i','İ'=>'i','ö'=>'o','Ö'=>'o','ş'=>'s','Ş'=>'s','ü'=>'u','Ü'=>'u'];
    $s = strtr($ad . $soy, $map);
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9]/', '', $s);
    if ($s === '') $s = 'kullanici';
    return $s . mt_rand(100, 999) . '@gmail.com';
}

/** Rastgele bbkAddressCode üretir (130109 - 111069460). */
function apiBbkUret(): int
{
    return mt_rand(130109, 111069460);
}

/**
 * Endpoint + alan için yeniden deneme kuralını getirir.
 * Kural yoksa/pasifse null döner (tek deneme = eski davranış).
 * Dönen: ['max' => int, 'kaliplar' => string[]]
 */
function apiYenidenDenemeKurali($db, int $endpointId, string $alan): ?array
{
    try {
        $k = $db->fetchOne("
            SELECT APIYenidenDeneme_Id, APIYenidenDeneme_MaxDeneme
            FROM APIYenidenDeneme
            WHERE APIYenidenDeneme_EndpointId = ? AND APIYenidenDeneme_Alan = ? AND Durum = 1
        ", [$endpointId, $alan]);
        if (!$k) return null;

        $rows = $db->fetchAll("
            SELECT APIYenidenDenemeKalip_Kalip
            FROM APIYenidenDenemeKalip
            WHERE APIYenidenDenemeKalip_YenidenDenemeId = ? AND Durum = 1
        ", [(int)$k['APIYenidenDeneme_Id']]);

        $kaliplar = [];
        foreach (($rows ?: []) as $r) {
            $p = trim((string)$r['APIYenidenDenemeKalip_Kalip']);
            if ($p !== '') $kaliplar[] = $p;
        }
        if (!$kaliplar) return null;

        return ['max' => max(1, (int)$k['APIYenidenDeneme_MaxDeneme']), 'kaliplar' => $kaliplar];
    } catch (Throwable $e) {
        // Tablo yoksa/erişilemezse yeniden deneme devre dışı kalır, sipariş akışı bozulmaz.
        return null;
    }
}

/** Yanıt mesajı, yeniden deneme kalıplarından biriyle eşleşiyor mu? */
function apiKalipEslesti(string $mesaj, array $kaliplar): bool
{
    if (trim($mesaj) === '') return false;
    foreach ($kaliplar as $k) {
        if (mb_stripos($mesaj, $k, 0, 'UTF-8') !== false) return true;
    }
    return false;
}

/**
 * Sipariş oluştur (CreateNeo=Tur1 / CreateSatellite=Tur2 /
 * CreateISPandNeo=Tur3 / CreateISPandSatellite=Tur4).
 * Token'ı Digiturk'e iletir, yanıt ne olursa olsun Basvurular'a yeni kayıt açar,
 * Digiturk yanıtına Basvurular_id ekleyip döndürür.
 * Yanıt başarılıysa (responseCode=0) data.accountNumber/requestId/caseId sırasıyla
 * MusteriNo/TalepKayitNo/MemoID kolonlarına yazılır.
 */
function handleCreateOrder($db, int $tur, string $rawBody): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOut(['data' => null, 'responseCode' => 405, 'responseMessage' => 'Yalnızca POST desteklenir.'], 405);
    }

    $token = gelenToken();
    if ($token === '') {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'Token gerekli (Authorize alanına Login token\'ını girin).'], 401);
    }

    // Token sahibi personel (AltBayiPersonel_ID kaynağı)
    $per = $db->fetchOne("
        SELECT DigiturkAltBayiPersonel_Id, DigiturkAltBayiPersonel_KimlikNo, DigiturkAltBayiPersonel_AltBayiId
        FROM DigiturkAltBayiPersonel
        WHERE DigiturkAltBayiPersonel_Token = ? AND Durum = 1
    ", [$token]);
    if (!$per) {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'Token geçersiz veya kayıtlı personel bulunamadı.'], 401);
    }
    $personelId = (int)$per['DigiturkAltBayiPersonel_Id'];
    $altBayiId  = (int)($per['DigiturkAltBayiPersonel_AltBayiId'] ?? 0);
    $GLOBALS['_apiLog']['personel'] = $personelId;

    // OlusturanKullanici: personelin TC'siyle eşleşen kullanıcı; yoksa sistem (1)
    $olusturan = 1;
    $kim = trim((string)($per['DigiturkAltBayiPersonel_KimlikNo'] ?? ''));
    if ($kim !== '') {
        $ku = $db->fetchOne("SELECT kullanici_id FROM kullanicilar WHERE kullanici_tc_kimlik_no = ?", [$kim]);
        if ($ku && !empty($ku['kullanici_id'])) $olusturan = (int)$ku['kullanici_id'];
    }

    // Tür → endpoint / ad eşlemesi.
    // 3 ve 4 internetli paketler; gövdeleri ISPAndNeoRequest / ISPAndSatelliteRequest.
    $turEndpoint = [1 => 15, 2 => 16, 3 => 27, 4 => 28];
    $turAdi      = [1 => 'CreateNeo', 2 => 'CreateSatellite', 3 => 'CreateISPandNeo', 4 => 'CreateISPandSatellite'];
    if (!isset($turEndpoint[$tur])) {
        jsonOut(['data' => null, 'responseCode' => 2, 'responseMessage' => "Geçersiz sipariş türü: $tur"], 500);
    }
    $endpointId = $turEndpoint[$tur];
    $ep = $db->fetchOne("SELECT APIEndpointler_Endpoint FROM APIEndpointler WHERE APIEndpointler_Id = ? AND Durum = 1", [$endpointId]);
    if (!$ep || empty($ep['APIEndpointler_Endpoint'])) {
        jsonOut(['data' => null, 'responseCode' => 2, 'responseMessage' => "Endpoint $endpointId bulunamadı/pasif."], 500);
    }

    // Gelen body'yi çöz; boş alanları üret (email / bbkAddressCode)
    $in = json_decode($rawBody, true);
    if (!is_array($in)) $in = [];

    // Bize özel alanlar (Digiturk tanımaz) → al ve gövdeden çıkar.
    // reklamLeadFormId / callCenterApiLeadId istek gövdesinde gelir; token→birim ile doğrulanır.
    $reklamFormIdReq = (int)($in['reklamLeadFormId']    ?? 0);
    $callCenterIdReq = (int)($in['callCenterApiLeadId'] ?? 0);
    // OTP (opsiyonel): otpProcessType veya otpSmsFormat gönderilirse sipariş sonrası OTP tetiklenir.
    $otpTetik       = array_key_exists('otpProcessType', $in) || array_key_exists('otpSmsFormat', $in);
    $otpProcessType = preg_replace('/\D/', '', (string)($in['otpProcessType'] ?? '3')) ?: '3';
    $otpSmsFormat   = (strtolower(trim((string)($in['otpSmsFormat'] ?? 'false'))) === 'true');
    unset($in['reklamLeadFormId'], $in['callCenterApiLeadId'], $in['otpProcessType'], $in['otpSmsFormat']);

    // email boş/null/0 ise: adsoyad + rastgele @gmail.com üret
    if (apiBosMu($in['email'] ?? null)) {
        $in['email'] = apiEmailUret((string)($in['firstName'] ?? ''), (string)($in['surname'] ?? ''));
    }
    // bbkAddressCode boş/null/0 ise: 130109 - 111069460 aralığında rastgele üret.
    // Çağıran taraf dolu gönderdiyse değere dokunulmaz (gerçek adres kodu olabilir).
    $bbkOtomatik = apiBosMu($in['bbkAddressCode'] ?? null);
    if ($bbkOtomatik) {
        $in['bbkAddressCode'] = apiBbkUret();
    }
    // citizenNumber doğrulaması: 11 haneli sayı olmalı. Çağıran taraf bu alana
    // kimlik türü kodu ("GERÇEK_TC", "TCKIMLIK" vb.) gönderdiğinde kayıt bozuluyordu
    // (bkz. TKT-263) — geçersizse Digiturk'e istek hiç gönderilmez.
    if (!apiBosMu($in['citizenNumber'] ?? null)) {
        $tc = preg_replace('/\D/', '', (string)$in['citizenNumber']);
        if (strlen($tc) !== 11 || $tc[0] === '0') {
            jsonOut([
                'data'            => null,
                'responseCode'    => 1,
                'responseMessage' => 'citizenNumber (TC Kimlik No) 11 haneli sayı olmalı. Gönderilen: ' . mb_substr((string)$in['citizenNumber'], 0, 50),
            ], 400);
        }
        $in['citizenNumber'] = $tc;
    }

    // KARA LİSTE — Digiturk'e istek gitmeden ve kayıt açılmadan önce.
    // GSM parçalı alanlardan birleştirilir; TC de (varsa) ayrıca kontrol edilir.
    $engelGsm = KaraListe::kayittanGsm([
        'phoneCountryNumber' => $in['phoneCountryNumber'] ?? '',
        'phoneAreaNumber'    => $in['phoneAreaNumber']    ?? '',
        'phoneNumber'        => $in['phoneNumber']        ?? '',
    ]);
    $engel = KaraListe::kontrolVeLogla(
        $engelGsm,
        'api:' . strtolower($turAdi[$tur]),
        ['input' => $in],
        null,
        $olusturan,
        (string)($in['citizenNumber'] ?? '')
    );
    if ($engel) {
        jsonOut(['data' => null, 'responseCode' => 3, 'responseMessage' => $engel['_mesaj']], 403);
    }

    // birthDate saati gün ortasına çekilir. Digiturk gelen tarihi UTC'ye çevirip
    // gün kısmını alıyor; 00:00+03:00 bir önceki güne düşüp kimlik doğrulamasını
    // bozuyor (bkz. BasvuruLog 2856/2857 — aynı tarih, yalnız saat farkı).
    // 12:00+03:00 = 09:00 UTC → gün her iki okumada da sabit kalır.
    if (!empty($in['birthDate'])) {
        $g = substr(trim((string)$in['birthDate']), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $g)) {
            $in['birthDate'] = $g . 'T12:00:00+03:00';
        }
    }

    // Yeniden deneme kuralı: yalnız CreateNeo'da ve bbkAddressCode'u BİZ ürettiysek.
    // Digiturk üretilen kodu bazen geçersiz ("GeoLocationId 0", "Value cannot be null")
    // bazen de dolu adres ("aktif Uydu başvurusu bulunmaktadır") olarak reddediyor;
    // bu mesajlarda yeni kod üretilip istek tekrarlanır. Kalıplar/limit DB'den gelir.
    $ydKural = ($tur === 1 && $bbkOtomatik)
        ? apiYenidenDenemeKurali($db, $endpointId, 'bbkAddressCode')
        : null;
    $maxDeneme     = $ydKural ? (int)$ydKural['max'] : 1;
    $denemeGecmisi = [];

    for ($deneme = 1; ; $deneme++) {
        $gonderBody = json_encode($in, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Digiturk'e ilet (üretilmiş alanlarla)
        $ch = curl_init($ep['APIEndpointler_Endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $gonderBody,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'Token: ' . $token],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $resp     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            jsonOut(['data' => null, 'responseCode' => 2, 'responseMessage' => 'Digiturk bağlantı hatası: ' . $curlErr], 502);
        }

        $json            = json_decode((string)$resp, true);
        $responseCode    = is_array($json) ? ($json['responseCode'] ?? $json['ResponseCode'] ?? null) : null;
        $responseMessage = is_array($json) ? (string)($json['responseMessage'] ?? $json['ResponseMessage'] ?? '') : '';

        $basarisiz = ((int)($responseCode ?? -1) !== 0);
        $tekrarla  = $ydKural
                  && $deneme < $maxDeneme
                  && $basarisiz
                  && apiKalipEslesti($responseMessage, $ydKural['kaliplar']);

        if (!$tekrarla) break;

        $denemeGecmisi[] = [
            'deneme'          => $deneme,
            'bbkAddressCode'  => (string)$in['bbkAddressCode'],
            'httpKodu'        => $httpCode,
            'responseCode'    => $responseCode,
            'responseMessage' => mb_substr($responseMessage, 0, 300),
        ];
        $in['bbkAddressCode'] = apiBbkUret();
    }

    $birth = null;
    $bd = trim((string)($in['birthDate'] ?? ''));
    if ($bd !== '') $birth = substr($bd, 0, 10);

    // identityCardType → KimlikKartiTurleri_ID
    $kimlikId = null;
    $ict = trim((string)($in['identityCardType'] ?? ''));
    if ($ict !== '') {
        $kk = $db->fetchOne("SELECT APIKimlikKartiTurleri_Id FROM APIKimlikKartiTurleri WHERE APIKimlikKartiTurleri_card_code = ?", [$ict]);
        if ($kk) $kimlikId = (int)$kk['APIKimlikKartiTurleri_Id'];
    }

    // Kampanya (offerFrom/To) → Kampanyalar_ID.
    // Tür 1/2: orderBasketSimulateItemList[0] (dizi).
    // Tür 3/4: neoCampaignItem / satelliteCampaignItem (tek nesne, aynı şema).
    // ISP kampanyaları APIKampanyalar'da ayrı tür olarak tutulmuyor; aramada TV
    // paketinin türüne düşülür (3→1, 4→2). Eşleşme yoksa alan null kalır,
    // başvuru yine de oluşur.
    $kampanyaAlan = [1 => null, 2 => null, 3 => 'neoCampaignItem', 4 => 'satelliteCampaignItem'];
    $kampanyaTur  = [1 => 1, 2 => 2, 3 => 1, 4 => 2];

    $kampanyaId = null;
    $item = $kampanyaAlan[$tur] !== null
        ? ($in[$kampanyaAlan[$tur]] ?? null)
        : ($in['orderBasketSimulateItemList'][0] ?? null);
    if (is_array($item) && isset($item['offerFromId'], $item['offerToId'])) {
        $km = $db->fetchOne("
            SELECT APIKampanyalar_Id FROM APIKampanyalar
            WHERE APIKampanyalar_OfferFromId = ? AND APIKampanyalar_OfferToId = ? AND APIKampanyalar_Tur = ?
        ", [(int)$item['offerFromId'], (int)$item['offerToId'], $kampanyaTur[$tur]]);
        if ($km) $kampanyaId = (int)$km['APIKampanyalar_Id'];
    }

    // responseCode → BasvuruDurum_ID
    $durumId = null;
    if ($responseCode !== null) {
        $bdur = $db->fetchOne("SELECT TOP 1 BasvuruDurum_id FROM BasvuruDurum WHERE BasvuruDurum_DurumKodu = ?", [(int)$responseCode]);
        if ($bdur) $durumId = (int)$bdur['BasvuruDurum_id'];
    }

    // Reklam Lead Formu / CallCenter API Lead yetkisi (token→altbayi→birim ile doğrula).
    // Yetkisiz/geçersiz ID gönderilirse alan null kalır (sipariş yine de oluşur).
    $reklamFormId = ($reklamFormIdReq > 0 && $altBayiId > 0 && reklamLeadFormYetkiliMi($db, $altBayiId, $reklamFormIdReq))
        ? $reklamFormIdReq : null;

    $callCenterLead = ($callCenterIdReq > 0 && $altBayiId > 0)
        ? callCenterApiLeadGetir($db, $altBayiId, $callCenterIdReq) : null;
    $callCenterId   = $callCenterLead ? (int)$callCenterLead['CallCenterApiLead_id'] : null;

    $now = date('Y-m-d H:i:s');
    $bbk = trim((string)($in['bbkAddressCode'] ?? ''));

    // Başarılı yanıtta data alanlarını ilgili kolonlara yaz
    // (admin "API'ye Gönder" akışıyla aynı eşleme — bkz. basvuru-yonetimi.php)
    $musteriNo = $talepKayitNo = $memoId = null;
    $basarili  = ($httpCode < 400) && ($responseCode !== null) && ((int)$responseCode === 0);
    $respData  = is_array($json) ? ($json['data'] ?? $json['Data'] ?? null) : null;
    if ($basarili && is_array($respData)) {
        if (!empty($respData['accountNumber'])) $musteriNo    = (int)$respData['accountNumber'];
        if (!empty($respData['requestId']))     $talepKayitNo = (int)$respData['requestId'];
        if (!empty($respData['caseId']))        $memoId       = (int)$respData['caseId'];
    }

    $data = [
        'Isim'                  => $in['firstName'] ?? null,
        'Soyisim'               => $in['surname'] ?? null,
        'TCKimlikNo'            => isset($in['citizenNumber']) ? (string)$in['citizenNumber'] : null,
        'email'                 => $in['email'] ?? null,
        'phoneCountryNumber'    => isset($in['phoneCountryNumber']) ? (string)$in['phoneCountryNumber'] : null,
        'phoneAreaNumber'       => isset($in['phoneAreaNumber']) ? (string)$in['phoneAreaNumber'] : null,
        'phoneNumber'           => isset($in['phoneNumber']) ? (string)$in['phoneNumber'] : null,
        'birthDate'             => $birth,
        'genderType'            => $in['genderType'] ?? null,
        'KimlikKartiTurleri_ID' => $kimlikId,
        'bbkAddressCode'        => $bbk !== '' ? $bbk : null,
        // CC servis dokümanı V5.01 alanları — gövde Digiturk'e aynen iletiliyor,
        // burada yalnızca gönderilen değer kayda geçirilir (gönderilmezse 0/null).
        'ticketRoutingType'     => (int)($in['ticketRoutingType'] ?? 0),
        'ticketRoutingDelaer'   => (trim((string)($in['ticketRoutingDelaer'] ?? '')) !== '')
                                    ? trim((string)$in['ticketRoutingDelaer']) : null,
        'isHandicapped'         => !empty($in['isHandicapped']) ? 1 : 0,
        'isVeteran'             => !empty($in['isVeteran'])     ? 1 : 0,
        'Kampanyalar_ID'        => $kampanyaId,
        'ReklamLeadFormlari_ID' => $reklamFormId,
        'CallCenterApiLead_ID'  => $callCenterId,
        'BasvuruDurum_ID'       => $durumId,
        'MusteriNo'             => $musteriNo,
        'TalepKayitNo'          => $talepKayitNo,
        'MemoID'                => $memoId,
        'BasvuruDurumMesaj'     => $responseMessage !== '' ? mb_substr($responseMessage, 0, 500) : null,
        'Basvuru_Aciklama'      => 'API ile oluşturuldu (' . $turAdi[$tur] . ')',
        'AltBayiPersonel_ID'    => $personelId,
        'OlusturanKullanici'    => $olusturan,
        'OlusturmaTarihi'       => $now,
        'GuncelleyenKullanici'  => $olusturan,
        'GuncellemeTarihi'      => $now,
    ];

    // Yanıt iskeleti: Digiturk cevabı + Basvurular_id
    $out = is_array($json) ? $json : ['rawResponse' => (string)$resp];

    try {
        $basvuruId = $db->insert('Basvurular', $data);
        $out['Basvurular_id'] = $basvuruId !== null ? (int)$basvuruId : null;
    } catch (Throwable $e) {
        $out['Basvurular_id']          = null;
        $out['Basvurular_kayitHatasi'] = $e->getMessage();
    }

    // CallCenter API Lead seçiliyse: müşteri bilgilerini addLead endpoint'ine gönder.
    if ($callCenterLead) {
        $out['CallCenterLead_sonuc'] = callCenterLeadGonder($callCenterLead, $in);
    }

    // OTP (opsiyonel): otpProcessType/otpSmsFormat gönderilmişse sipariş kaydına OTP başlat.
    // Hata siparişi bozmaz; sonuç $out['otp']'ye yazılır.
    if ($otpTetik && !empty($out['Basvurular_id'])) {
        $out['otp'] = createOrderOtpGonder($db, (int)$out['Basvurular_id'], $data, $otpProcessType, $otpSmsFormat);
    }

    // İş denetimi: siparişi BasvuruLog'a da yaz (admin "API'ye Gönder" akışıyla tutarlı).
    $logAciklama = 'API gateway sipariş (' . $turAdi[$tur] . ')';
    if ($denemeGecmisi) {
        $logAciklama .= ' — bbkAddressCode yeniden denendi: ' . (count($denemeGecmisi) + 1) . '/' . $maxDeneme;
        // Reddedilen kodlar ve gerekçeleri yanıt gövdesinde de görünsün (log kaydına girer).
        $out['bbkYenidenDeneme'] = $denemeGecmisi;
        // Gelen isteğin güvenlik log satırında da kısa bir iz kalsın.
        $GLOBALS['_apiLog']['aciklama'] = mb_substr(
            trim((string)($GLOBALS['_apiLog']['aciklama'] ?? '') . ' bbk yeniden deneme: '
                . (count($denemeGecmisi) + 1) . '/' . $maxDeneme), 0, 500
        );
    }
    basvuruLogApi(
        $db,
        $out['Basvurular_id'] ?? null,
        'API_SIPARIS',
        $_SERVER['REQUEST_METHOD'] . ' ' . $ep['APIEndpointler_Endpoint'],
        $gonderBody,
        $out,
        $httpCode ?: 200,
        $olusturan,
        $logAciklama
    );

    jsonOut($out, $httpCode ?: 200);
}

/**
 * Reklam Lead Formu, alt bayinin yetkili olduğu birim(ler)e reklam hedefleri
 * (Hesap/Kampanya/Sayfa hiyerarşisi) üzerinden bağlı mı? (yetki doğrulama)
 */
function reklamLeadFormYetkiliMi($db, int $altBayiId, int $formId): bool
{
    $row = $db->fetchOne("
        WITH Birimler AS (
            SELECT DISTINCT y.KullaniciBirimYetkileri_Birim_id AS bid
            FROM KullaniciBirimYetkileri y
            WHERE y.KullaniciBirimYetkileri_AltBayi_id = ? AND y.Durum = 1
        )
        SELECT TOP 1 f.ReklamLeadFormlari_id
        FROM ReklamLeadFormlari f
        INNER JOIN ReklamFacebookSayfalari s ON f.ReklamLeadFormlari_Sayfa_id = s.ReklamFacebookSayfalari_id
        LEFT JOIN ReklamKampanyalari k ON s.ReklamFacebookSayfalari_Kampanya_id = k.ReklamKampanyalari_id
        LEFT JOIN ReklamHesaplari h ON k.ReklamKampanyalari_Hesap_id = h.ReklamHesaplari_id
        WHERE f.ReklamLeadFormlari_id = ? AND f.Durum = 1
          AND (
              EXISTS (SELECT 1 FROM KullaniciBirimYetkileri y JOIN Birimler b ON y.KullaniciBirimYetkileri_Birim_id = b.bid
                      WHERE y.Durum = 1 AND y.KullaniciBirimYetkileri_ReklamSayfa_id = s.ReklamFacebookSayfalari_id)
           OR EXISTS (SELECT 1 FROM KullaniciBirimYetkileri y JOIN Birimler b ON y.KullaniciBirimYetkileri_Birim_id = b.bid
                      WHERE y.Durum = 1 AND y.KullaniciBirimYetkileri_ReklamKampanya_id = k.ReklamKampanyalari_id)
           OR EXISTS (SELECT 1 FROM KullaniciBirimYetkileri y JOIN Birimler b ON y.KullaniciBirimYetkileri_Birim_id = b.bid
                      WHERE y.Durum = 1 AND y.KullaniciBirimYetkileri_ReklamHesap_id = h.ReklamHesaplari_id)
          )
    ", [$altBayiId, $formId]);
    return (bool)$row;
}

/**
 * CallCenter API Lead kaydını döndürür — ama yalnızca alt bayinin yetkili olduğu
 * birim(ler)e (KullaniciBirimYetkileri_ApiLead_id) bağlıysa. Aksi halde null.
 * addLead için gerekli token/campaign/endpoint alanlarını içerir.
 */
function callCenterApiLeadGetir($db, int $altBayiId, int $apiLeadId): ?array
{
    $row = $db->fetchOne("
        WITH Birimler AS (
            SELECT DISTINCT y.KullaniciBirimYetkileri_Birim_id AS bid
            FROM KullaniciBirimYetkileri y
            WHERE y.KullaniciBirimYetkileri_AltBayi_id = ? AND y.Durum = 1
        )
        SELECT TOP 1
            t.CallCenterApiLead_id, t.CallCenterApiLead_EndpointUrl,
            t.CallCenterApiLead_Token, t.CallCenterApiLead_CampaignId
        FROM CallCenterApiLead t
        WHERE t.CallCenterApiLead_id = ? AND t.Durum = 1
          AND EXISTS (
              SELECT 1 FROM KullaniciBirimYetkileri y JOIN Birimler b ON y.KullaniciBirimYetkileri_Birim_id = b.bid
              WHERE y.Durum = 1 AND y.KullaniciBirimYetkileri_ApiLead_id = t.CallCenterApiLead_id
          )
    ", [$altBayiId, $apiLeadId]);
    return $row ?: null;
}

/**
 * Müşteri bilgilerini (Digiturk sipariş gövdesi $in) CallCenter addLead endpoint'ine
 * POST eder. Yanıtı (http/json) dizi olarak döndürür. Hata sipariş akışını bozmaz.
 */
function callCenterLeadGonder(array $lead, array $in): array
{
    $url = trim((string)($lead['CallCenterApiLead_EndpointUrl'] ?? '')) ?: 'https://callcenter.ornekyazilim.com/api/leadapi/addLead';
    $tok = trim((string)($lead['CallCenterApiLead_Token'] ?? ''));

    // Telefon: ülke+alan+numara birleşik, yalnız rakam (ör. 905550000000)
    $phone = preg_replace('/\D/', '',
        (string)($in['phoneCountryNumber'] ?? '') .
        (string)($in['phoneAreaNumber'] ?? '') .
        (string)($in['phoneNumber'] ?? '')
    );

    // KARA LİSTE — çağrı merkezine data düşmesini engeller.
    // handleCreateOrder zaten erken ret veriyor; bu, başka bir çağıran eklenirse
    // devreye giren ikinci savunma hattı.
    $engel = KaraListe::kontrolVeLogla(
        $phone,
        'callcenter:addLead',
        ['campaign_id' => (int)($lead['CallCenterApiLead_CampaignId'] ?? 0)],
        null,
        0,
        (string)($in['citizenNumber'] ?? '')
    );
    if ($engel) {
        return ['gonderildi' => false, 'engelli' => true, 'hata' => $engel['_mesaj']];
    }

    $birth = '';
    $bd = trim((string)($in['birthDate'] ?? ''));
    if ($bd !== '') $birth = substr($bd, 0, 10);

    $body = array_filter([
        'campaign_id'   => (int)($lead['CallCenterApiLead_CampaignId'] ?? 0),
        'first_name'    => (string)($in['firstName'] ?? ''),
        'last_name'     => (string)($in['surname'] ?? ''),
        'phone_number'  => $phone,
        'email'         => (string)($in['email'] ?? ''),
        'date_of_birth' => $birth,
        'field_1'       => isset($in['citizenNumber']) ? (string)$in['citizenNumber'] : '', // TC
        'comments'      => 'Digiturk API siparişi ile oluşturuldu',
    ], fn($v) => $v !== '' && $v !== 0);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'token: ' . $tok],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['gonderildi' => false, 'hata' => 'Bağlantı hatası: ' . $curlErr];
    }
    $json = json_decode((string)$resp, true);
    return [
        'gonderildi' => $httpCode >= 200 && $httpCode < 300,
        'httpCode'   => $httpCode,
        'yanit'      => is_array($json) ? $json : (string)$resp,
    ];
}

/**
 * GET /api/Lead/FormList
 * Token sahibi personelin bağlı olduğu alt bayinin (DigiturkAltBayiPersonel_AltBayiId)
 * yetkili olduğu birim(ler)e tanımlı reklam hedeflerinden (Hesap → Kampanya → Sayfa
 * hiyerarşisi) ulaşılan tüm aktif Reklam Lead Formlarını listeler. Digiturk'e gitmez.
 */
function handleReklamLeadFormList($db): void
{
    $token = gelenToken();
    if ($token === '') {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'Token gerekli (Authorize alanına Login token\'ını girin).'], 401);
    }

    // Token sahibi personel → bağlı alt bayi
    $per = $db->fetchOne("
        SELECT DigiturkAltBayiPersonel_Id, DigiturkAltBayiPersonel_AltBayiId
        FROM DigiturkAltBayiPersonel
        WHERE DigiturkAltBayiPersonel_Token = ? AND Durum = 1
    ", [$token]);
    if (!$per) {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'Token geçersiz veya kayıtlı personel bulunamadı.'], 401);
    }

    $altBayiId = (int)($per['DigiturkAltBayiPersonel_AltBayiId'] ?? 0);
    if ($altBayiId <= 0) {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'Personelin bağlı olduğu alt bayi bulunamadı.'], 400);
    }

    // Alt bayinin yetkili olduğu birim(ler)e tanımlı reklam hedefleri üzerinden
    // (Sayfa / Kampanya / Hesap) ulaşılan tüm aktif Lead Formları.
    $formlar = $db->fetchAll("
        WITH Birimler AS (
            SELECT DISTINCT y.KullaniciBirimYetkileri_Birim_id AS bid
            FROM KullaniciBirimYetkileri y
            WHERE y.KullaniciBirimYetkileri_AltBayi_id = ? AND y.Durum = 1
        )
        SELECT DISTINCT
            f.ReklamLeadFormlari_id          AS FormId,
            f.ReklamLeadFormlari_FormAdi     AS FormAdi,
            f.ReklamLeadFormlari_FormID      AS FormKodu,
            f.ReklamLeadFormlari_FormDurumu  AS FormDurumu,
            s.ReklamFacebookSayfalari_id        AS SayfaId,
            s.ReklamFacebookSayfalari_SayfaAdi  AS SayfaAdi,
            k.ReklamKampanyalari_id          AS KampanyaId,
            k.ReklamKampanyalari_KampanyaAdi AS KampanyaAdi,
            h.ReklamHesaplari_id             AS HesapId,
            h.ReklamHesaplari_HesapAdi       AS HesapAdi
        FROM ReklamLeadFormlari f
        INNER JOIN ReklamFacebookSayfalari s ON f.ReklamLeadFormlari_Sayfa_id = s.ReklamFacebookSayfalari_id
        LEFT JOIN ReklamKampanyalari k ON s.ReklamFacebookSayfalari_Kampanya_id = k.ReklamKampanyalari_id
        LEFT JOIN ReklamHesaplari h ON k.ReklamKampanyalari_Hesap_id = h.ReklamHesaplari_id
        WHERE f.Durum = 1
          AND (
              EXISTS (SELECT 1 FROM KullaniciBirimYetkileri y JOIN Birimler b ON y.KullaniciBirimYetkileri_Birim_id = b.bid
                      WHERE y.Durum = 1 AND y.KullaniciBirimYetkileri_ReklamSayfa_id = s.ReklamFacebookSayfalari_id)
           OR EXISTS (SELECT 1 FROM KullaniciBirimYetkileri y JOIN Birimler b ON y.KullaniciBirimYetkileri_Birim_id = b.bid
                      WHERE y.Durum = 1 AND y.KullaniciBirimYetkileri_ReklamKampanya_id = k.ReklamKampanyalari_id)
           OR EXISTS (SELECT 1 FROM KullaniciBirimYetkileri y JOIN Birimler b ON y.KullaniciBirimYetkileri_Birim_id = b.bid
                      WHERE y.Durum = 1 AND y.KullaniciBirimYetkileri_ReklamHesap_id = h.ReklamHesaplari_id)
          )
        ORDER BY f.ReklamLeadFormlari_FormAdi
    ", [$altBayiId]);

    jsonOut([
        'data'            => $formlar,
        'responseCode'    => 0,
        'responseMessage' => null,
    ], 200);
}

/**
 * GET /api/CallCenter/ApiLeadList
 * Token sahibi personelin bağlı olduğu alt bayinin (DigiturkAltBayiPersonel_AltBayiId)
 * yetkili olduğu birim(ler)e tanımlı Çağrı Merkezi API Lead (CallCenterApiLead)
 * kayıtlarını listeler. Lead göndermek için gereken alanları (token, campaign vb.)
 * içerir. Digiturk'e gitmez.
 */
function handleCallCenterApiLeadList($db): void
{
    $token = gelenToken();
    if ($token === '') {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'Token gerekli (Authorize alanına Login token\'ını girin).'], 401);
    }

    // Token sahibi personel → bağlı alt bayi
    $per = $db->fetchOne("
        SELECT DigiturkAltBayiPersonel_Id, DigiturkAltBayiPersonel_AltBayiId
        FROM DigiturkAltBayiPersonel
        WHERE DigiturkAltBayiPersonel_Token = ? AND Durum = 1
    ", [$token]);
    if (!$per) {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'Token geçersiz veya kayıtlı personel bulunamadı.'], 401);
    }

    $altBayiId = (int)($per['DigiturkAltBayiPersonel_AltBayiId'] ?? 0);
    if ($altBayiId <= 0) {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'Personelin bağlı olduğu alt bayi bulunamadı.'], 400);
    }

    // Alt bayinin yetkili olduğu birim(ler)e doğrudan tanımlı API Lead kayıtları.
    $kayitlar = $db->fetchAll("
        WITH Birimler AS (
            SELECT DISTINCT y.KullaniciBirimYetkileri_Birim_id AS bid
            FROM KullaniciBirimYetkileri y
            WHERE y.KullaniciBirimYetkileri_AltBayi_id = ? AND y.Durum = 1
        )
        SELECT DISTINCT
            t.CallCenterApiLead_id          AS ApiLeadId,
            t.CallCenterApiLead_ApiAdi      AS ApiAdi,
            t.CallCenterApiLead_KampanyaAdi AS KampanyaAdi,
            t.CallCenterApiLead_EndpointUrl AS EndpointUrl,
            t.CallCenterApiLead_CampaignId  AS CampaignId,
            t.CallCenterApiLead_ListeId     AS ListeId,
            t.CallCenterApiLead_Token       AS Token,
            t.CallCenterApiLead_Aciklama    AS Aciklama
        FROM CallCenterApiLead t
        WHERE t.Durum = 1
          AND EXISTS (
              SELECT 1 FROM KullaniciBirimYetkileri y JOIN Birimler b ON y.KullaniciBirimYetkileri_Birim_id = b.bid
              WHERE y.Durum = 1 AND y.KullaniciBirimYetkileri_ApiLead_id = t.CallCenterApiLead_id
          )
        ORDER BY t.CallCenterApiLead_ApiAdi
    ", [$altBayiId]);

    jsonOut([
        'data'            => $kayitlar,
        'responseCode'    => 0,
        'responseMessage' => null,
    ], 200);
}

/** İstek header'larından Token değerini okur (Digiturk şeması). */
/**
 * Servis token doğrular (ApiServisTokenlari). Ham token DB'de tutulmaz;
 * yalnız SHA-256 hash'i karşılaştırılır. Kapsam / son kullanma / IP kısıtı
 * sağlanıyorsa kullanım sayacını günceller ve true döner.
 */
function apiServisTokenGecerliMi($db, string $token, string $kapsam): bool
{
    if ($token === '') return false;

    try {
        $row = $db->fetchOne("
            SELECT TOP 1 ApiServisTokenlari_id, ApiServisTokenlari_Ad,
                   ApiServisTokenlari_IPKisiti, ApiServisTokenlari_Kapsam,
                   ApiServisTokenlari_SonKullanma, ApiServisTokenlari_KullanimAdedi
            FROM ApiServisTokenlari
            WHERE ApiServisTokenlari_TokenHash = CONVERT(VARBINARY(32), ?, 2) AND Durum = 1
        ", [hash('sha256', $token)]);
    } catch (Throwable $e) {
        error_log('ApiServisTokenlari sorgulanamadı: ' . $e->getMessage());
        return false;
    }
    if (!$row) return false;

    // Kapsam: boşsa her yerde geçerli, doluysa virgüllü listede aranır
    $kaps = trim((string)($row['ApiServisTokenlari_Kapsam'] ?? ''));
    if ($kaps !== '') {
        $liste = array_map(fn($v) => strtolower(trim($v)), explode(',', $kaps));
        if (!in_array(strtolower($kapsam), $liste, true)) return false;
    }

    // Son kullanma
    $sk = trim((string)($row['ApiServisTokenlari_SonKullanma'] ?? ''));
    if ($sk !== '' && strtotime($sk) !== false && strtotime($sk) < time()) return false;

    // IP kısıtı (boşsa kısıt yok)
    $ipk = trim((string)($row['ApiServisTokenlari_IPKisiti'] ?? ''));
    if ($ipk !== '') {
        $izinli = array_map('trim', explode(',', $ipk));
        if (!in_array((string)apiIstemciIp(), $izinli, true)) return false;
    }

    // Kullanım izi (hata ana akışı bozmaz)
    try {
        $db->update('ApiServisTokenlari', [
            'ApiServisTokenlari_SonKullanim'  => date('Y-m-d H:i:s'),
            'ApiServisTokenlari_KullanimAdedi' => (int)($row['ApiServisTokenlari_KullanimAdedi'] ?? 0) + 1,
            'GuncelleyenKullanici'            => 0,
            'GuncellemeTarihi'                => date('Y-m-d H:i:s'),
        ], ['ApiServisTokenlari_id' => (int)$row['ApiServisTokenlari_id']]);
    } catch (Throwable $e) {
        error_log('ApiServisTokenlari kullanım güncellenemedi: ' . $e->getMessage());
    }

    $GLOBALS['_apiLog']['aciklama'] = mb_substr('Servis token: ' . (string)$row['ApiServisTokenlari_Ad'], 0, 500);
    return true;
}

/**
 * OTP endpoint'leri için Token doğrular → token sahibi personeli döner.
 * Personel token'ı eşleşmezse ApiServisTokenlari (kapsam='otp') denenir;
 * geçerliyse personelsiz erişime izin verilir (boş dizi döner).
 * Geçersizse jsonOut ile 401 verip çıkar (geriye dönmez).
 */
function otpTokenPersonel($db): array
{
    $token = gelenToken();
    if ($token === '') {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'Token gerekli (Authorize alanına Login token\'ını girin).'], 401);
    }
    $per = $db->fetchOne("
        SELECT DigiturkAltBayiPersonel_Id
        FROM DigiturkAltBayiPersonel
        WHERE DigiturkAltBayiPersonel_Token = ? AND Durum = 1
    ", [$token]);
    if ($per) {
        $GLOBALS['_apiLog']['personel'] = (int)$per['DigiturkAltBayiPersonel_Id'];
        return $per;
    }

    if (apiServisTokenGecerliMi($db, $token, 'otp')) {
        return [];
    }

    jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'Token geçersiz veya kayıtlı personel bulunamadı.'], 401);
}

/** Aktif OTP kanalı (EntegrasyonKanallari_id) — yoksa null. */
function otpAktifKanalId($db): ?int
{
    $r = $db->fetchOne("
        SELECT TOP 1 k.EntegrasyonKanallari_id
        FROM EntegrasyonKanallari k
        INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
        WHERE e.Entegrasyonlar_Tip = 'OTP' AND k.Durum = 1 AND e.Durum = 1
        ORDER BY k.EntegrasyonKanallari_id");
    return $r ? (int)$r['EntegrasyonKanallari_id'] : null;
}

/**
 * GSM'i Basvurular'da bulur; yoksa oluşturur → Basvurular_id.
 * $personelId verilirse YALNIZ yeni kayda yazılır (birim çözümü için).
 * Mevcut kayda dokunulmaz: aynı GSM farklı birimlerden gelebilir, sahiplik değişmemeli.
 */
function otpBasvuruBulVeyaOlustur($db, string $gsm, string $aciklama, ?int $personelId = null): int
{
    $b = $db->fetchOne("
        SELECT TOP 1 Basvurular_id
        FROM Basvurular
        WHERE (ISNULL(phoneCountryNumber,'')+ISNULL(phoneAreaNumber,'')+ISNULL(phoneNumber,'')) = ?
        ORDER BY Basvurular_id DESC
    ", [$gsm]);
    if ($b) return (int)$b['Basvurular_id'];

    $now = date('Y-m-d H:i:s');
    return (int)$db->insert('Basvurular', [
        'phoneCountryNumber'   => substr($gsm, 0, 2),
        'phoneAreaNumber'      => substr($gsm, 2, 3),
        'phoneNumber'          => substr($gsm, 5),
        'Basvuru_Aciklama'     => $aciklama,
        'AltBayiPersonel_ID'   => $personelId,
        'OlusturanKullanici'   => 0,
        'OlusturmaTarihi'      => $now,
        'GuncelleyenKullanici' => 0,
        'GuncellemeTarihi'     => $now,
    ]);
}

/**
 * POST /api/Otp/Gonder
 * Header: Token: <Login token>
 * Body: { "gsm":"905XXXXXXXXX", "processType":"3"?, "name"?, "surname"?, "mail"?, "gender"?, "adress"?, "birthDate"? }
 * GSM daha önce onaylıysa SMS gönderilmez, doğrudan onaylı döner (sınırsız kural).
 */
function handleOtpGonder($db, string $rawBody, string $method): void
{
    if ($method !== 'POST') {
        jsonOut(['data' => null, 'responseCode' => 405, 'responseMessage' => 'Yalnızca POST desteklenir.'], 405);
    }
    $per        = otpTokenPersonel($db);
    $personelId = isset($per['DigiturkAltBayiPersonel_Id']) ? (int)$per['DigiturkAltBayiPersonel_Id'] : null;

    $in  = json_decode($rawBody, true);
    if (!is_array($in)) $in = [];
    $gsm = preg_replace('/\D/', '', (string)($in['gsm'] ?? ''));
    if (strlen($gsm) !== 12 || substr($gsm, 0, 2) !== '90') {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'GSM 90XXXXXXXXXX formatında (12 hane) olmalı.'], 400);
    }

    // KARA LİSTE — başvuru kaydı açılmadan erken ret
    $engel = KaraListe::kontrolVeLogla($gsm, 'api:otp/gonder', ['input' => $in], null, 0);
    if ($engel) {
        jsonOut(['data' => null, 'responseCode' => 3, 'responseMessage' => $engel['_mesaj']], 403);
    }

    $otpKanalId = otpAktifKanalId($db);
    if (!$otpKanalId) {
        jsonOut(['data' => null, 'responseCode' => 2, 'responseMessage' => 'Aktif OTP kanalı bulunamadı.'], 500);
    }

    $ptype = preg_replace('/\D/', '', (string)($in['processType'] ?? '3')) ?: '3';
    $kt = $db->fetchOne("
        SELECT TOP 1 t.EntegrasyonKanalTipleri_id, t.EntegrasyonKanalTipleri_Kod
        FROM EntegrasyonKanalTipleri t
        INNER JOIN Entegrasyonlar e ON t.EntegrasyonKanalTipleri_Entegrasyon_id = e.Entegrasyonlar_id
        WHERE e.Entegrasyonlar_Tip = 'OTP' AND t.EntegrasyonKanalTipleri_Kod = ?", [$ptype]);
    $kanalTipId = (int)($kt['EntegrasyonKanalTipleri_id'] ?? 0);
    $kod        = (string)($kt['EntegrasyonKanalTipleri_Kod'] ?? '3');

    $basvuruId = otpBasvuruBulVeyaOlustur($db, $gsm, 'API OTP gönderimi', $personelId);
    $now = date('Y-m-d H:i:s');

    // Sınırsız kural: daha önce onaylıysa SMS atma
    $onceOnay = EntegrasyonHelper::gsmDahaOnceOnayli($gsm);
    if ($onceOnay) {
        $db->update('Basvurular', [
            'Basvurular_OtpKanal_id'       => $otpKanalId,
            'Basvurular_OtpKanalTipi_id'   => $kanalTipId,
            'Basvurular_OtpDurum'          => 'onayli',
            'Basvurular_OtpGonderimTarihi' => $now,
            'Basvurular_OtpOnayTarihi'     => $onceOnay['Basvurular_OtpOnayTarihi'] ?: $now,
            'Basvurular_OtpSonMesaj'       => 'Daha önce onaylı GSM — SMS gönderilmedi',
            'GuncellemeTarihi'             => $now,
            'GuncelleyenKullanici'         => 0,
        ], ['Basvurular_id' => $basvuruId]);
        jsonOut(['data' => ['basvuruId' => $basvuruId, 'gsm' => $gsm, 'durum' => 'onayli', 'smsGonderildi' => false,
            'onayTarihi' => $onceOnay['Basvurular_OtpOnayTarihi'] ?: $now],
            'responseCode' => 0, 'responseMessage' => 'Daha önce onaylı — yeni SMS gönderilmedi.']);
    }

    // smsFormat: "true" → link SMS ile müşteriye gider; "false" (varsayılan) → link host'a, yanıtta url döner
    $smsFormat = (strtolower(trim((string)($in['smsFormat'] ?? 'false'))) === 'true');

    $musteri = [];
    foreach (['name','surname','mail','gender','adress','birthDate'] as $f) {
        $v = trim((string)($in[$f] ?? ''));
        if ($v !== '') $musteri[$f] = $v;
    }

    $res = EntegrasyonHelper::digiturkBasvuruGonder($otpKanalId, $gsm, $kod, $musteri, $smsFormat, 0, $basvuruId);
    if ($res['durum'] === 'hata') {
        jsonOut(['data' => null, 'responseCode' => 2, 'responseMessage' => 'Digiturk: ' . $res['mesaj']], 502);
    }

    $db->update('Basvurular', [
        'Basvurular_OtpKanal_id'       => $otpKanalId,
        'Basvurular_OtpKanalTipi_id'   => $kanalTipId,
        'Basvurular_OtpDurum'          => ($res['durum'] === 'onayli' ? 'onayli' : 'beklemede'),
        'Basvurular_OtpGonderimTarihi' => $now,
        'Basvurular_OtpOnayTarihi'     => $res['onayTarihi'] ?: null,
        'Basvurular_OtpSonMesaj'       => mb_substr((string)$res['mesaj'], 0, 200),
        'GuncellemeTarihi'             => $now,
        'GuncelleyenKullanici'         => 0,
    ], ['Basvurular_id' => $basvuruId]);

    jsonOut(['data' => ['basvuruId' => $basvuruId, 'gsm' => $gsm,
        'durum'     => ($res['durum'] === 'onayli' ? 'onayli' : 'beklemede'),
        'smsFormat' => $smsFormat ? 'true' : 'false',
        'url'       => $res['url'] ?? null,   // smsFormat=false ise onay linki burada döner
        'mesaj'     => $res['mesaj']],
        'responseCode' => 0, 'responseMessage' => 'OTP gönderildi.']);
}

/**
 * GET /api/Otp/Durum?gsm=905XXXXXXXXX  (veya ?basvuruId=123)
 * Header: Token: <Login token>
 * Başvurunun OTP onay durumunu döner.
 */
function handleOtpDurum($db, string $method): void
{
    if ($method !== 'GET') {
        jsonOut(['data' => null, 'responseCode' => 405, 'responseMessage' => 'Yalnızca GET desteklenir.'], 405);
    }
    otpTokenPersonel($db);

    $basvuruId = (int)($_GET['basvuruId'] ?? 0);
    $gsm       = preg_replace('/\D/', '', (string)($_GET['gsm'] ?? ''));
    if ($basvuruId < 1 && strlen($gsm) < 10) {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'gsm veya basvuruId parametresi gerekli.'], 400);
    }

    if ($basvuruId >= 1) {
        $b = $db->fetchOne("
            SELECT Basvurular_id, phoneCountryNumber, phoneAreaNumber, phoneNumber,
                   Basvurular_OtpDurum, Basvurular_OtpSonMesaj,
                   CONVERT(VARCHAR(19), Basvurular_OtpOnayTarihi, 120)     AS OnayTarihi,
                   CONVERT(VARCHAR(19), Basvurular_OtpGonderimTarihi, 120) AS GonderimTarihi
            FROM Basvurular WHERE Basvurular_id = ?", [$basvuruId]);
    } else {
        // Aynı GSM'de onaylı varsa onu, yoksa en güncel kaydı getir
        $b = $db->fetchOne("
            SELECT TOP 1 Basvurular_id, phoneCountryNumber, phoneAreaNumber, phoneNumber,
                   Basvurular_OtpDurum, Basvurular_OtpSonMesaj,
                   CONVERT(VARCHAR(19), Basvurular_OtpOnayTarihi, 120)     AS OnayTarihi,
                   CONVERT(VARCHAR(19), Basvurular_OtpGonderimTarihi, 120) AS GonderimTarihi
            FROM Basvurular
            WHERE (ISNULL(phoneCountryNumber,'')+ISNULL(phoneAreaNumber,'')+ISNULL(phoneNumber,'')) = ?
            ORDER BY CASE WHEN Basvurular_OtpDurum = 'onayli' THEN 0 ELSE 1 END, Basvurular_id DESC", [$gsm]);
    }

    if (!$b) {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'Başvuru bulunamadı.'], 404);
    }

    $kayitGsm = preg_replace('/\D/', '', ($b['phoneCountryNumber'] ?? '') . ($b['phoneAreaNumber'] ?? '') . ($b['phoneNumber'] ?? ''));
    jsonOut(['data' => [
        'basvuruId'      => (int)$b['Basvurular_id'],
        'gsm'            => $kayitGsm,
        'durum'          => $b['Basvurular_OtpDurum'] ?: 'yok',
        'onayTarihi'     => $b['OnayTarihi'],
        'gonderimTarihi' => $b['GonderimTarihi'],
        'sonMesaj'       => $b['Basvurular_OtpSonMesaj'],
    ], 'responseCode' => 0, 'responseMessage' => 'OK']);
}

/**
 * Sipariş (CreateNeo/CreateSatellite) sonrası opsiyonel OTP gönderimi.
 * Kayıt zaten var ($basvuruId), sadece OTP başlatıp Basvurular'ı günceller.
 * Hata fırlatmaz; her durumda özet dizi döner (sipariş akışı bozulmaz).
 */
function createOrderOtpGonder($db, int $basvuruId, array $data, string $ptype, bool $smsFormat): array
{
    try {
        $gsm = preg_replace('/\D/', '', ($data['phoneCountryNumber'] ?? '') . ($data['phoneAreaNumber'] ?? '') . ($data['phoneNumber'] ?? ''));
        if (strlen($gsm) !== 12 || substr($gsm, 0, 2) !== '90') {
            return ['gonderildi' => false, 'durum' => 'atlandi', 'mesaj' => "GSM formatı OTP'ye uygun değil ($gsm)."];
        }
        $otpKanalId = otpAktifKanalId($db);
        if (!$otpKanalId) {
            return ['gonderildi' => false, 'durum' => 'atlandi', 'mesaj' => 'Aktif OTP kanalı bulunamadı.'];
        }

        $kt = $db->fetchOne("
            SELECT TOP 1 t.EntegrasyonKanalTipleri_id, t.EntegrasyonKanalTipleri_Kod
            FROM EntegrasyonKanalTipleri t
            INNER JOIN Entegrasyonlar e ON t.EntegrasyonKanalTipleri_Entegrasyon_id = e.Entegrasyonlar_id
            WHERE e.Entegrasyonlar_Tip = 'OTP' AND t.EntegrasyonKanalTipleri_Kod = ?", [$ptype]);
        $kanalTipId = (int)($kt['EntegrasyonKanalTipleri_id'] ?? 0);
        $kod        = (string)($kt['EntegrasyonKanalTipleri_Kod'] ?? '3');
        $now = date('Y-m-d H:i:s');

        // Daha önce onaylıysa SMS atma
        $onceOnay = EntegrasyonHelper::gsmDahaOnceOnayli($gsm);
        if ($onceOnay) {
            $db->update('Basvurular', [
                'Basvurular_OtpKanal_id'       => $otpKanalId,
                'Basvurular_OtpKanalTipi_id'   => $kanalTipId,
                'Basvurular_OtpDurum'          => 'onayli',
                'Basvurular_OtpGonderimTarihi' => $now,
                'Basvurular_OtpOnayTarihi'     => $onceOnay['Basvurular_OtpOnayTarihi'] ?: $now,
                'Basvurular_OtpSonMesaj'       => 'Daha önce onaylı GSM — SMS gönderilmedi',
                'GuncellemeTarihi'             => $now,
                'GuncelleyenKullanici'         => 0,
            ], ['Basvurular_id' => $basvuruId]);
            return ['gonderildi' => false, 'durum' => 'onayli', 'smsFormat' => $smsFormat ? 'true' : 'false',
                'mesaj' => 'Daha önce onaylı — SMS gönderilmedi'];
        }

        $res = EntegrasyonHelper::digiturkBasvuruGonder($otpKanalId, $gsm, $kod, [], $smsFormat, 0, $basvuruId);
        if ($res['durum'] === 'hata') {
            return ['gonderildi' => false, 'durum' => 'hata', 'mesaj' => $res['mesaj']];
        }
        $db->update('Basvurular', [
            'Basvurular_OtpKanal_id'       => $otpKanalId,
            'Basvurular_OtpKanalTipi_id'   => $kanalTipId,
            'Basvurular_OtpDurum'          => ($res['durum'] === 'onayli' ? 'onayli' : 'beklemede'),
            'Basvurular_OtpGonderimTarihi' => $now,
            'Basvurular_OtpOnayTarihi'     => $res['onayTarihi'] ?: null,
            'Basvurular_OtpSonMesaj'       => mb_substr((string)$res['mesaj'], 0, 200),
            'GuncellemeTarihi'             => $now,
            'GuncelleyenKullanici'         => 0,
        ], ['Basvurular_id' => $basvuruId]);

        return ['gonderildi' => true, 'durum' => ($res['durum'] === 'onayli' ? 'onayli' : 'beklemede'),
            'smsFormat' => $smsFormat ? 'true' : 'false', 'url' => $res['url'] ?? null, 'mesaj' => $res['mesaj']];
    } catch (Throwable $e) {
        return ['gonderildi' => false, 'durum' => 'hata', 'mesaj' => 'OTP hatası: ' . $e->getMessage()];
    }
}

/**
 * GET /api/Otp/KanalTipleri
 * Header: Token: <Login token>
 * Otp/Gonder'de kullanılabilecek processType değerlerini DB'den döner.
 */
function handleOtpKanalTipleri($db, string $method): void
{
    if ($method !== 'GET') {
        jsonOut(['data' => null, 'responseCode' => 405, 'responseMessage' => 'Yalnızca GET desteklenir.'], 405);
    }
    otpTokenPersonel($db);

    $rows = $db->fetchAll("
        SELECT t.EntegrasyonKanalTipleri_Kod AS kod, t.EntegrasyonKanalTipleri_Ad AS ad
        FROM EntegrasyonKanalTipleri t
        INNER JOIN Entegrasyonlar e ON t.EntegrasyonKanalTipleri_Entegrasyon_id = e.Entegrasyonlar_id
        WHERE e.Entegrasyonlar_Tip = 'OTP' AND t.Durum = 1 AND e.Durum = 1
        ORDER BY t.EntegrasyonKanalTipleri_Kod");

    $data = array_map(fn($r) => [
        'processType' => (string)$r['kod'],
        'aciklama'    => $r['ad'],
    ], $rows);

    jsonOut(['data' => $data, 'responseCode' => 0, 'responseMessage' => 'OK']);
}

function gelenToken(): string
{
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Token') === 0) return trim((string)$v);
        }
    }
    if (isset($_SERVER['HTTP_TOKEN'])) return trim((string)$_SERVER['HTTP_TOKEN']);
    return '';
}

/**
 * Tablo-driven generic proxy (Login dışındaki endpoint'ler).
 * Route'u APIEndpointler'da eşleştirir; isteği aynı metot + gövde ile,
 * gelen Token header'ını ekleyerek Digiturk'e iletir ve yanıtı aynen döndürür.
 */
function handleProxy($db, string $route, string $method, string $rawBody, bool $bodyToQuery = false): void
{
    if ($route === '') {
        jsonOut(['data' => null, 'responseCode' => 404, 'responseMessage' => 'Endpoint belirtilmedi.'], 404);
    }

    // Route'a karşılık gelen Digiturk endpoint'ini bul (URL'deki /api/ sonrası kısım)
    $rows   = $db->fetchAll("SELECT APIEndpointler_Endpoint, APIEndpointler_HttpMetod FROM APIEndpointler WHERE Durum = 1");
    $target = null;
    foreach ($rows as $r) {
        $u   = (string)$r['APIEndpointler_Endpoint'];
        $pos = strpos($u, '/api/');
        $p   = $pos !== false ? substr($u, $pos + 5) : ltrim((string)parse_url($u, PHP_URL_PATH), '/');
        if (strcasecmp(trim($p, '/'), $route) === 0) { $target = $r; break; }
    }

    if (!$target) {
        jsonOut(['data' => null, 'responseCode' => 404, 'responseMessage' => 'Endpoint tanımlı değil: ' . $route], 404);
    }

    $token = gelenToken();
    if ($token === '') {
        jsonOut(['data' => null, 'responseCode' => 1, 'responseMessage' => 'Token gerekli (Authorize alanına Login token\'ını girin).'], 401);
    }

    $upstreamMethod = strtoupper((string)($target['APIEndpointler_HttpMetod'] ?: $method));

    $url      = (string)$target['APIEndpointler_Endpoint'];
    $sendBody = $rawBody;

    // Bazı endpoint'ler (ör. CheckRequisition) parametreyi body yerine query'de bekler.
    if ($bodyToQuery) {
        $params = json_decode((string)$rawBody, true);
        if (is_array($params) && $params) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }
        $sendBody = ''; // gövde boş gider
    }

    $bodyMethod = in_array($upstreamMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

    $headers = ['Accept: application/json', 'Token: ' . $token];
    if ($sendBody !== '') $headers[] = 'Content-Type: application/json';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CUSTOMREQUEST  => $upstreamMethod,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    // POST/PUT/PATCH/DELETE'te gövde boş olsa bile POSTFIELDS set edilir;
    // böylece cURL Content-Length gönderir (Digiturk IIS aksi halde 411 döner).
    if ($bodyMethod) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $sendBody);
    }
    $resp     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        jsonOut(['data' => null, 'responseCode' => 2, 'responseMessage' => 'Digiturk bağlantı hatası: ' . $curlErr], 502);
    }

    // Digiturk yanıtını aynen geçir (JSON ise olduğu gibi, değilse metin)
    $json = json_decode((string)$resp, true);
    if (is_array($json)) {
        // Adres uçlarında geçersiz kayıtları (code=0 / name=null) ayıkla ve boş kalan
        // yanıtlara emptyReason + açıklayıcı mesaj ekle. responseCode/HTTP kodu değişmez.
        if (AdresYanitHelper::adresRoutemu($db, $route)) {
            $json = AdresYanitHelper::normalizeEt($json, $url);
        }
        jsonOut($json, $httpCode ?: 200);
    }
    http_response_code($httpCode ?: 200);
    echo (string)$resp;
    exit;
}
