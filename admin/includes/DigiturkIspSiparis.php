<?php
/**
 * Digiturk internetli sipariş (CreateISPandNeo / CreateISPandSatellite) ön hazırlığı.
 * API gateway (api/index.php) ve panel (basvuru-yonetimi.php "API'ye Gönder") ortak kullanır.
 *
 * ccapi-test ortamında doğrulanan kurallar (26.11.2026):
 *  1) Sipariş, AYNI TOKEN ile aynı bbkId için kampanya listesi sorgulanmadan kabul
 *     edilmez (responseCode=21 "…sorgulanmış kampanya listesi bulunamamıştır").
 *     Bu yüzden liste her siparişten hemen önce canlı sorgulanır.
 *  2) Kalemlerin GetOfferGiftsByOfferId yanıtındaki zorunlu hediye grupları
 *     (minValue > 0) giftList'te olmalıdır (responseCode=21 "…En az 1 adet").
 *  3) ticketRoutingType=0 ile internet ticket'ı adrese bakan bayiye düşer, TV ticket'ı
 *     bize kalır; gönderilmemişse 1 (bize yönlendir) kullanılır.
 *  4) Liste yanıtı test ortamında ~116 sn sürdü; zaman aşımı ISP_CCAPI_TIMEOUT.
 */

defined('ISP_CCAPI_TIMEOUT') || define('ISP_CCAPI_TIMEOUT', 180);

// APIEndpointler kimlikleri
defined('ISP_EP_LISTE_NEO')  || define('ISP_EP_LISTE_NEO',  25);
defined('ISP_EP_LISTE_UYDU') || define('ISP_EP_LISTE_UYDU', 26);
defined('ISP_EP_SIPARIS_NEO')  || define('ISP_EP_SIPARIS_NEO',  27);
defined('ISP_EP_SIPARIS_UYDU') || define('ISP_EP_SIPARIS_UYDU', 28);
defined('ISP_EP_HEDIYE')     || define('ISP_EP_HEDIYE',     14);

/** Hazırlık durduğunda fırlatılır; çağıran taraf kendi biçiminde yanıtlar. */
class IspSiparisHatasi extends RuntimeException
{
    public function __construct(
        string $mesaj,
        public int $http = 400,
        public $responseCode = 1,
        public ?array $veri = null
    ) {
        parent::__construct($mesaj);
    }
}

/** Digiturk ccapi'ye JSON POST (Token header). Dönüş: [httpKodu, json|null, curlHata]. */
function ispCcapiPost(string $url, string $token, array $body, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'Token: ' . $token],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $json = json_decode((string)$resp, true);
    return [$http, is_array($json) ? $json : null, $err];
}

function ispEndpointUrl($db, int $endpointId): string
{
    $ep = $db->fetchOne("SELECT APIEndpointler_Endpoint FROM APIEndpointler WHERE APIEndpointler_Id = ? AND Durum = 1", [$endpointId]);
    if (!$ep || empty($ep['APIEndpointler_Endpoint'])) {
        throw new IspSiparisHatasi("Endpoint $endpointId bulunamadı/pasif.", 500, 2);
    }
    return (string)$ep['APIEndpointler_Endpoint'];
}

/** Digiturk paket/offer satırını sipariş kalemine çevirir. */
function ispKalem(array $o): array
{
    return [
        'offerFromId'   => (int)$o['offerFromId'],
        'offerToId'     => (int)$o['offerToId'],
        'frequency'     => (int)($o['frequency'] ?? $o['billFrequency'] ?? 1) ?: 1,
        'frequencyCode' => (string)($o['frequencyCode'] ?? $o['frequencyCd'] ?? $o['billFrequencyTypeCd'] ?? 'AY') ?: 'AY',
    ];
}

/**
 * Sipariş gövdesini Digiturk kurallarına göre tamamlar; $in referansla güncellenir.
 *  - ispCampaignItem ve bbkAddressCode zorunlu.
 *  - TV kalemi (neo/satelliteCampaignItem) yoksa canlı teklifin bundleOfferResultList[0]'ından,
 *    modemCampaignItem yoksa ve addedModem true ise equipmentOfferResultList[0]'ından doldurulur.
 *  - giftList boşsa: seçenek sayısı zorunlu adede eşit gruplar otomatik eklenir; seçim
 *    gerekiyorsa IspSiparisHatasi (responseCode=21, veri.hediyeSecimi) fırlatılır.
 * @param int $tur 3 = ISP+NEO, 4 = ISP+UYDU
 * @return array bilgi notları
 * @throws IspSiparisHatasi
 */
function ispSiparisHazirla($db, int $tur, array &$in, string $token): array
{
    $notlar  = [];
    $tvAlan  = $tur === 3 ? 'neoCampaignItem' : 'satelliteCampaignItem';
    $listeEp = $tur === 3 ? ISP_EP_LISTE_NEO : ISP_EP_LISTE_UYDU;
    $bbk     = (int)($in['bbkAddressCode'] ?? 0);

    if ($bbk <= 0) {
        throw new IspSiparisHatasi('İnternetli siparişte bbkAddressCode (gerçek BBK adres kodu) zorunludur.');
    }
    $isp = $in['ispCampaignItem'] ?? null;
    if (!is_array($isp) || empty($isp['offerFromId']) || empty($isp['offerToId'])) {
        throw new IspSiparisHatasi('ispCampaignItem (offerFromId / offerToId) zorunludur.');
    }

    // 1) Kampanya listesi — aynı token, aynı adres
    [$http, $liste, $err] = ispCcapiPost(ispEndpointUrl($db, $listeEp), $token,
        ['bbkId' => $bbk, 'adslNo' => ($in['adslNo'] ?? '') !== '' ? $in['adslNo'] : null], ISP_CCAPI_TIMEOUT);
    if ($err) {
        throw new IspSiparisHatasi('Kampanya sorgusu bağlantı hatası: ' . $err, 502, 2);
    }
    $kod = $liste['responseCode'] ?? $liste['ResponseCode'] ?? null;
    if ($liste === null || (int)($kod ?? -1) !== 0) {
        $mesaj = (string)($liste['responseMessage'] ?? $liste['ResponseMessage'] ?? "HTTP $http");
        throw new IspSiparisHatasi('Adres için kampanya sorgusu başarısız: ' . $mesaj, $http >= 400 ? $http : 400, $kod ?? 2);
    }
    $teklif = null;
    foreach (($liste['data']['offerResultList'] ?? []) as $o) {
        if ((int)($o['offerFromId'] ?? 0) === (int)$isp['offerFromId']
            && (int)($o['offerToId'] ?? 0) === (int)$isp['offerToId']) { $teklif = $o; break; }
    }
    if ($teklif === null) {
        $mevcut = array_values(array_unique(array_filter(array_map(
            fn($o) => $o['offerToCode'] ?? null, $liste['data']['offerResultList'] ?? []))));
        throw new IspSiparisHatasi('Seçilen internet kampanyası bu adreste sunulmuyor (offerFromId '
            . (int)$isp['offerFromId'] . ' / offerToId ' . (int)$isp['offerToId'] . ').'
            . ($mevcut ? ' Adreste sunulanlar: ' . implode(', ', $mevcut) : ''), 400, 1);
    }
    $notlar['kampanyaSorgusu'] = 'Aynı token ile bbkId ' . $bbk . ' sorgulandı, teklif: ' . ($teklif['offerToCode'] ?? '');

    // TV kalemi
    $tv = $in[$tvAlan] ?? null;
    if (!is_array($tv) || empty($tv['offerFromId']) || empty($tv['offerToId'])) {
        $b = $teklif['bundleOfferResultList'][0] ?? null;
        if (!$b) throw new IspSiparisHatasi("Teklifte TV paketi (bundle) yok; $tvAlan gönderilmeli.");
        $in[$tvAlan] = ispKalem($b);
        $notlar['tvKalemi'] = ($b['offerToCode'] ?? '') . ' canlı tekliften eklendi.';
    }

    // Modem kalemi
    if (!empty($in['addedModem']) && empty($in['modemCampaignItem']['offerToId'])) {
        $m = $teklif['equipmentOfferResultList'][0] ?? null;
        if ($m) {
            $in['modemCampaignItem'] = ispKalem($m);
            $notlar['modemKalemi'] = ($m['offerToCode'] ?? '') . ' canlı tekliften eklendi.';
        }
    }

    // 2) Zorunlu hediyeler — giftList gönderilmişse dokunulmaz
    if (empty($in['giftList'])) {
        $hediyeUrl = ispEndpointUrl($db, ISP_EP_HEDIYE);
        $eklenen = $secimler = [];
        foreach ([$tvAlan, 'ispCampaignItem', 'modemCampaignItem', 'additionalCampaignItem'] as $alan) {
            $it = $in[$alan] ?? null;
            if (!is_array($it) || empty($it['offerFromId']) || empty($it['offerToId'])) continue;
            [, $h, $herr] = ispCcapiPost($hediyeUrl, $token,
                ['offerFromId' => (int)$it['offerFromId'], 'offerToId' => (int)$it['offerToId']], 60);
            if ($herr || $h === null) {
                throw new IspSiparisHatasi("$alan için hediye sorgusu başarısız: " . ($herr ?: 'yanıt JSON değil'), 502, 2);
            }
            foreach (($h['data']['groupOfEntityEntity'] ?? null) ?: [] as $grup) {
                $min      = (int)($grup['minValue'] ?? 0);
                $paketler = $grup['packageOfEntity'] ?? [];
                if ($min <= 0 || !$paketler) continue;
                if (count($paketler) <= $min) {
                    foreach ($paketler as $p) $eklenen[] = ispKalem($p);
                } else {
                    $secimler[] = [
                        'kalem'      => $alan,
                        'grup'       => (string)($grup['name'] ?? ''),
                        'secilecek'  => $min,
                        'secenekler' => array_map(fn($p) => ispKalem($p) + [
                            'ad' => (string)($p['productSpecName'] ?? $p['offerToCd'] ?? ''),
                        ], $paketler),
                    ];
                }
            }
        }
        if ($secimler) {
            throw new IspSiparisHatasi('Kampanyada zorunlu hediye seçimi var; seçilen kalem(ler)i giftList ile gönderin.',
                400, 21, ['hediyeSecimi' => $secimler]);
        }
        if ($eklenen) {
            $in['giftList'] = $eklenen;
            $notlar['hediyeEklendi'] = count($eklenen) . ' zorunlu hediye kalemi otomatik eklendi.';
        }
    }

    // 3) Yönlendirme — açıkça gönderildiyse korunur
    if (!isset($in['ticketRoutingType']) || $in['ticketRoutingType'] === '') {
        $in['ticketRoutingType']   = 1;
        $in['ticketRoutingDelaer'] = $in['ticketRoutingDelaer'] ?? '';
        $notlar['yonlendirme'] = 'ticketRoutingType gönderilmedi, 1 (bize yönlendir) kullanıldı.';
    }

    return $notlar;
}
