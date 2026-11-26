<?php
/**
 * API kampanya senkronizasyonu ("API'den Güncelle").
 * Kullanım: api-kampanyalar.php butonu (IRIS İşlemleri / islem=kampanya zamanlayıcısını tetikler) ve
 * cron/tasks.php gorevIrisIslemler() → islem=kampanya. Mantık yalnız burada durur.
 *
 * Kurallar: ccapi listesinde olan kampanya aktif (yeni eklense bile), listede olmayan pasif;
 * IRIS Kampanya Bilgileri yalnız KOI / Süper KOI bayrağı için okunur.
 */

if (!function_exists('apiKampanyaSenkron')) {

// ISP senkronizasyon sabitleri
// Fiyat ve hız varyantları BBK adresine bağlı geldiği için sabit referans adresler kullanılır.
// Fiber adres 35–1000 Mbps, VDSL adres 16–50 Mbps döndürür; ikisi birleşince katalog tamamlanır.
// (100 Mbps XDSL varyantı bu iki adreste bulunmadığı için eksik kalır.)
defined('ISP_REFERANS_BBK') || define('ISP_REFERANS_BBK', ['99944924', '9977316']);
defined('ISP_TUR_ID') || define('ISP_TUR_ID', 3);
defined('ISP_ATLA_KODLAR') || define('ISP_ATLA_KODLAR', ['YWV']);                      // STATIK IP — kampanya değil, ek hizmet
defined('ISP_KATEGORI_MAP') || define('ISP_KATEGORI_MAP', [25 => 'ISP+NEO', 26 => 'ISP+UYDU']);

// Metinden PaketAdi türetir (NEO, UYDU ve ISP için ortak)
function paketAdiTuret($metin) {
    $paketAdiMap = [
        'Beşiktaş'    => 'Beşiktaş Taraftar Paketi',
        'Fenerbahçe'  => 'Fenerbahçe Taraftar Paketi',
        'Galatasaray' => 'Galatasaray Taraftar Paketi',
        'Trabzonspor' => 'Trabzonspor Taraftar Paketi',
        'Dolu'        => 'Yıldız Dolu Paketi',
        'Eğlence'     => 'Eğlence ve Avrupanın Yıldızı Paketi',
        'Sporun'      => 'Sporun Yıldızı Paketi',
    ];
    foreach ($paketAdiMap as $keyword => $paketAdi) {
        if (strpos((string)$metin, $keyword) !== false) return $paketAdi;
    }
    return null;
}

// offerToCode'dan hız ve altyapı türetir: PTS_YLN_VDSL_35E_SRV -> "35 Mbps VDSL"
// API'nin description alanı 20 karakterde kesildiği için hız kodun kendisinden okunur.
function ispHizAciklama($offerToCode) {
    if (!preg_match('/(FIBER|VDSL)_?(\d+)(G)?/i', (string)$offerToCode, $m)) return null;
    $hiz = (int)$m[2];
    if (!empty($m[3])) $hiz *= 1000;                    // 1G -> 1000 Mbps
    return $hiz . ' Mbps ' . (strtoupper($m[1]) === 'FIBER' ? 'Fiber' : 'VDSL');
}

// API senkronizasyon yardımcı fonksiyonları
function processEntity($db, $entityData, $userId, $tur, &$inserted, &$updated, array &$gorulen = []) {
    $kategoriAdi  = null;
    $entityResult = $entityData['entityResult'] ?? null;

    if ($entityResult && !empty($entityResult['entityJsonTypeList'])) {
        $jsonType    = json_decode($entityResult['entityJsonTypeList'], true);
        $kategoriAdi = $jsonType['GORUNEN_ISIM'] ?? null;
    }

    foreach (($entityData['offerResultList'] ?? []) as $offer) {
        $id = upsertKampanya($db, $offer, $kategoriAdi, $userId, $tur, $inserted, $updated);
        if ($id) $gorulen[$id] = true;
    }

    foreach (($entityData['subProductCatalogList'] ?? []) as $sub) {
        processEntity($db, $sub, $userId, $tur, $inserted, $updated, $gorulen);
    }
}

// Dönüş: kaydedilen satırın Id'si (atlandıysa 0). API listesinde olan kampanya aktif yazılır.
function upsertKampanya($db, $offer, $kategoriAdi, $userId, $tur, &$inserted, &$updated): int {
    $offerFromCode = $offer['offerFromCode'] ?? null;
    $offerToCode   = $offer['offerToCode']   ?? null;
    if (!$offerFromCode || !$offerToCode) return 0;

    $existing = $db->fetchOne("
        SELECT APIKampanyalar_Id FROM APIKampanyalar
        WHERE APIKampanyalar_OfferFromCode = ?
          AND APIKampanyalar_OfferToCode   = ?
          AND ISNULL(APIKampanyalar_KategoriAdi, '') = ISNULL(?, '')
    ", [$offerFromCode, $offerToCode, $kategoriAdi]);

    // Açıklama'dan PaketAdi türet
    $derivedPaketAdi = paketAdiTuret($offer['description'] ?? '');

    // KategoriAdi'ndan OdemeTuru türet
    $odemeTuru = null;
    if ($kategoriAdi) {
        if (strpos($kategoriAdi, 'KREDI')   !== false) $odemeTuru = 1;
        elseif (strpos($kategoriAdi, 'FATURALI') !== false) $odemeTuru = 2;
    }

    // KategoriAdi'ndan Tur türet (endpoint'ten gelen $tur'u override eder)
    if ($kategoriAdi) {
        if (strpos($kategoriAdi, 'NEO')  !== false) $tur = 1;
        elseif (strpos($kategoriAdi, 'UYDU') !== false) $tur = 2;
    }

    $apiData = [
        'APIKampanyalar_Tur'           => $tur,
        'APIKampanyalar_OdemeTuru'     => $odemeTuru,
        'APIKampanyalar_KategoriAdi'   => $kategoriAdi,
        'APIKampanyalar_OfferFromCode' => $offerFromCode,
        'APIKampanyalar_OfferFromId'   => isset($offer['offerFromId'])  ? (int)$offer['offerFromId']   : null,
        'APIKampanyalar_OfferToCode'   => $offerToCode,
        'APIKampanyalar_OfferToId'     => isset($offer['offerToId'])    ? (int)$offer['offerToId']     : null,
        'APIKampanyalar_Ad'            => $offer['name']                ?? null,
        'APIKampanyalar_Aciklama'      => $offer['description']         ?? null,
        'APIKampanyalar_Fiyat'         => isset($offer['priceAmount'])  ? (float)$offer['priceAmount'] : null,
        'APIKampanyalar_ParaBirimi'    => $offer['currencyTypeCd']      ?? 'TL',
        'APIKampanyalar_FaturaDonemi'  => $offer['billFrequencyTypeCd'] ?? null,
        'GuncelleyenKullanici'         => $userId,
        'GuncellemeTarihi'             => date('Y-m-d H:i:s'),
    ];

    if ($derivedPaketAdi !== null) {
        $apiData['APIKampanyalar_PaketAdi'] = $derivedPaketAdi;
    }

    // Digiturk listesinde olan kampanya aktiftir (yeni eklense bile); listeden
    // kalkanlar sync sonunda pasife alınır.
    $apiData['Durum'] = 1;

    if ($existing) {
        $db->update('APIKampanyalar', $apiData, ['APIKampanyalar_Id' => $existing['APIKampanyalar_Id']]);
        $updated++;
        return (int)$existing['APIKampanyalar_Id'];
    }
    $apiData['OlusturanKullanici'] = $userId;
    $apiData['OlusturmaTarihi']    = date('Y-m-d H:i:s');
    $id = (int)$db->insert('APIKampanyalar', $apiData);
    $inserted++;
    $GLOBALS['_syncYeniIdler'][] = $id;
    return $id;
}

// ISP kampanyası kaydeder (endpoint 25/26)
// NEO/UYDU'dan farkları: fiyat alanı 'price' (priceAmount yok), kampanya kodu offerFromCode'da
// (name null gelir), TV paketi ayrı bir listede (bundleOfferResultList) ve fiyatı ana fiyata eklenir.
// Dönüş: kaydedilen satırın Id'si ve TV paketi (hediye sorgusu için), atlandıysa null.
function upsertIspKampanya($db, $offer, $kategoriAdi, $userId, &$inserted, &$updated): ?array {
    $offerFromCode = $offer['offerFromCode'] ?? null;
    $offerToCode   = $offer['offerToCode']   ?? null;
    if (!$offerFromCode || !$offerToCode) return null;
    if (in_array($offerFromCode, ISP_ATLA_KODLAR, true)) return null;

    // TV paketi olmayan satır (ör. tek başına ek hizmet) kampanya sayılmaz
    $bundleList = $offer['bundleOfferResultList'] ?? [];
    if (!$bundleList) return null;

    // Fiyat = internet + TV paketi. STATIK IP ve modem opsiyonel olduğu için toplama girmez.
    $fiyat = (float)($offer['price'] ?? 0);
    foreach ($bundleList as $bundle) {
        $fiyat += (float)($bundle['price'] ?? 0);
    }

    $ilkBundle = $bundleList[0];

    $existing = $db->fetchOne("
        SELECT APIKampanyalar_Id FROM APIKampanyalar
        WHERE APIKampanyalar_OfferFromCode = ?
          AND APIKampanyalar_OfferToCode   = ?
          AND ISNULL(APIKampanyalar_KategoriAdi, '') = ISNULL(?, '')
    ", [$offerFromCode, $offerToCode, $kategoriAdi]);

    $apiData = [
        'APIKampanyalar_Tur'           => ISP_TUR_ID,
        'APIKampanyalar_KategoriAdi'   => $kategoriAdi,
        'APIKampanyalar_OfferFromCode' => $offerFromCode,
        'APIKampanyalar_OfferFromId'   => isset($offer['offerFromId']) ? (int)$offer['offerFromId'] : null,
        'APIKampanyalar_OfferToCode'   => $offerToCode,
        'APIKampanyalar_OfferToId'     => isset($offer['offerToId'])   ? (int)$offer['offerToId']   : null,
        'APIKampanyalar_Ad'            => $offerFromCode,
        'APIKampanyalar_Aciklama'      => ispHizAciklama($offerToCode),
        'APIKampanyalar_Fiyat'         => $fiyat,
        'APIKampanyalar_ParaBirimi'    => $ilkBundle['currencyTypeCd'] ?? $offer['currencyTypeCd'] ?? 'TL',
        'APIKampanyalar_FaturaDonemi'  => $offer['billFrequencyTypeCd'] ?? $ilkBundle['billFrequencyTypeCd'] ?? null,
        'GuncelleyenKullanici'         => $userId,
        'GuncellemeTarihi'             => date('Y-m-d H:i:s'),
    ];

    // PaketAdi bundle adından türetilir; ISP'de ana satırın description'ı hız bilgisi taşır
    $derivedPaketAdi = paketAdiTuret($ilkBundle['name'] ?? $ilkBundle['description'] ?? '');
    if ($derivedPaketAdi !== null) {
        $apiData['APIKampanyalar_PaketAdi'] = $derivedPaketAdi;
    }

    // Digiturk listesinde olan kampanya aktiftir (yeni eklense bile)
    $apiData['Durum'] = 1;

    if ($existing) {
        $db->update('APIKampanyalar', $apiData, ['APIKampanyalar_Id' => $existing['APIKampanyalar_Id']]);
        $updated++;
        $id = (int)$existing['APIKampanyalar_Id'];
    } else {
        $apiData['OlusturanKullanici'] = $userId;
        $apiData['OlusturmaTarihi']    = date('Y-m-d H:i:s');
        $id = (int)$db->insert('APIKampanyalar', $apiData);
        $inserted++;
        $GLOBALS['_syncYeniIdler'][] = $id;
    }

    return [
        'id'       => $id,
        'tvFromId' => (int)($ilkBundle['offerFromId'] ?? 0),
        'tvToId'   => (int)($ilkBundle['offerToId'] ?? 0),
        'tvKod'    => (string)($ilkBundle['offerToCode'] ?? ''),
    ];
}

/**
 * @return array ['success'=>bool, 'message'=>string, 'inserted', 'updated', 'pasif', 'hediye', 'koi', 'superkoi', 'tvPasif']
 */
function apiKampanyaSenkron($db, int $kullaniciId): array
{
    $personel = $db->fetchOne("
        SELECT DigiturkAltBayiPersonel_Token
        FROM DigiturkAltBayiPersonel
        WHERE DigiturkAltBayiPersonel_Id = ?
    ", [(int)siteAyar('api_personel_id', 0)]);

    if (!$personel || empty($personel['DigiturkAltBayiPersonel_Token'])) {
        return ['success' => false, 'message' => 'Aktif token bulunamadı! Lütfen token yenileyin.'];
    }

    $token     = $personel['DigiturkAltBayiPersonel_Token'];
    $endpoints = $db->fetchAll("
        SELECT APIEndpointler_Id, APIEndpointler_Endpoint, APIEndpointler_Aciklama
        FROM APIEndpointler
        WHERE APIEndpointler_Id IN (11, 12) AND Durum = 1
        ORDER BY APIEndpointler_Id
    ");

    $totalInserted = 0;
    $totalUpdated  = 0;
    $errors        = [];
    $GLOBALS['_syncYeniIdler'] = [];   // bu sync'te yeni eklenen kayıtlar (upsert fonksiyonları doldurur)
    $tvGorulen   = [];                 // NEO/UYDU: API'de görülen kayıt Id'leri
    $tvBasarili  = [];                 // yanıtı sorunsuz gelen uçların türleri (1/2)

    foreach ($endpoints as $ep) {
        $ch = curl_init($ep['APIEndpointler_Endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Token: ' . $token,
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        unset($ch);

        if ($curlErr || $response === false) {
            $errors[] = "{$ep['APIEndpointler_Aciklama']}: Bağlantı hatası ($curlErr)";
            continue;
        }
        if ($httpCode !== 200) {
            $errors[] = "{$ep['APIEndpointler_Aciklama']}: HTTP $httpCode";
            continue;
        }

        $data = json_decode($response, true);
        if (!$data || ($data['responseCode'] ?? -1) !== 0) {
            $errors[] = "{$ep['APIEndpointler_Aciklama']}: API hata kodu " . ($data['responseCode'] ?? '?');
            continue;
        }

        $turMap = [11 => 1, 12 => 2];
        $tur    = $turMap[$ep['APIEndpointler_Id']] ?? null;
        $once   = count($tvGorulen);
        processEntity($db, $data['data'], $kullaniciId, $tur, $totalInserted, $totalUpdated, $tvGorulen);
        // Boş liste dönen uç "başarılı" sayılmaz; aksi halde o türün tamamı pasife düşerdi.
        if ($tur && count($tvGorulen) > $once) $tvBasarili[] = $tur;
    }

    // NEO/UYDU: listeden kalkanları pasife al — yalnız yanıtı sorunsuz gelen türlerde.
    // Tür, kategori adından türetildiği için (NEO→1, UYDU→2) kayıtlar türe göre süzülür.
    $tvPasif = 0;
    if ($tvBasarili) {
        $turlar = array_values(array_unique($tvBasarili));
        $ids    = array_map('intval', array_keys($tvGorulen));
        $tph    = implode(',', array_fill(0, count($turlar), '?'));
        $iph    = implode(',', array_fill(0, count($ids), '?'));
        $kosul  = "APIKampanyalar_Tur IN ($tph) AND Durum = 1 AND APIKampanyalar_Id NOT IN ($iph)";
        $tvPasif = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM APIKampanyalar WHERE $kosul",
                                       array_merge($turlar, $ids))['c'] ?? 0);
        if ($tvPasif) {
            $db->execute("UPDATE APIKampanyalar SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE() WHERE $kosul",
                         array_merge([(int)$kullaniciId], $turlar, $ids));
        }
    }

    // ISP kampanyaları (endpoint 25/26) — NEO/UYDU'dan ayrı akış.
    // Bu uçlar boş gövde kabul etmez; her referans BBK için ayrı çağrı yapılır.
    $ispEndpoints = $db->fetchAll("
        SELECT APIEndpointler_Id, APIEndpointler_Endpoint, APIEndpointler_Aciklama
        FROM APIEndpointler
        WHERE APIEndpointler_Id IN (25, 26) AND Durum = 1
        ORDER BY APIEndpointler_Id
    ");

    // Pasife alma ve hediye için: kategori başına görülen kayıtlar ve
    // o kategorinin tüm çağrıları sorunsuz mu (biri bile hatalıysa pasife alma yapılmaz)
    $ispGorulen   = [];   // kategori => [Id => true]
    $ispKatHatali = [];   // kategori => true
    $ispTvPaket   = [];   // "fromId/toId" => ['kod' => ..., 'idler' => [..]]

    foreach ($ispEndpoints as $ep) {
        $epId        = (int)$ep['APIEndpointler_Id'];
        $kategoriAdi = ISP_KATEGORI_MAP[$epId] ?? null;
        if (!$kategoriAdi) continue;
        $ispGorulen[$kategoriAdi] = $ispGorulen[$kategoriAdi] ?? [];

        foreach (ISP_REFERANS_BBK as $bbk) {
            $ch = curl_init($ep['APIEndpointler_Endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['bbkId' => (int)$bbk, 'adslNo' => null]),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Token: ' . $token,
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 60,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            unset($ch);

            if ($curlErr || $response === false) {
                $errors[] = "{$ep['APIEndpointler_Aciklama']} (BBK $bbk): Bağlantı hatası ($curlErr)";
                $ispKatHatali[$kategoriAdi] = true;
                continue;
            }
            if ($httpCode !== 200) {
                $errors[] = "{$ep['APIEndpointler_Aciklama']} (BBK $bbk): HTTP $httpCode";
                $ispKatHatali[$kategoriAdi] = true;
                continue;
            }

            $data = json_decode($response, true);
            if (!$data || ($data['responseCode'] ?? -1) !== 0) {
                // responseCode 2 = "altyapı mevcut değildir" — hata değil, o adreste ISP yok.
                // Yine de referans adresin altyapısı kaybolmuş olabilir; o kategoride pasife alma yapılmaz.
                $ispKatHatali[$kategoriAdi] = true;
                if (($data['responseCode'] ?? -1) !== 2) {
                    $errors[] = "{$ep['APIEndpointler_Aciklama']} (BBK $bbk): API hata kodu "
                              . ($data['responseCode'] ?? '?');
                }
                continue;
            }

            foreach (($data['data']['offerResultList'] ?? []) as $offer) {
                $kay = upsertIspKampanya($db, $offer, $kategoriAdi, $kullaniciId,
                                         $totalInserted, $totalUpdated);
                if (!$kay || !$kay['id']) continue;
                $ispGorulen[$kategoriAdi][$kay['id']] = true;
                if ($kay['tvFromId'] && $kay['tvToId']) {
                    $tk = $kay['tvFromId'] . '/' . $kay['tvToId'];
                    $ispTvPaket[$tk]['kod']     = $kay['tvKod'];
                    $ispTvPaket[$tk]['idler'][] = $kay['id'];
                }
            }
        }
    }

    // (a) Listeden kalkan ISP kampanyalarını pasife al.
    // Yalnız kategorinin tüm referans çağrıları başarılı ve en az bir kayıt döndüyse;
    // aksi halde geçici bir hata bütün kataloğu kapatabilirdi.
    $ispPasif = 0;
    foreach ($ispGorulen as $kat => $idler) {
        if (!empty($ispKatHatali[$kat]) || !$idler) continue;
        $ids = array_map('intval', array_keys($idler));
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $ispPasif += (int)($db->fetchOne("
            SELECT COUNT(*) AS c FROM APIKampanyalar
            WHERE APIKampanyalar_Tur = ? AND APIKampanyalar_KategoriAdi = ? AND Durum = 1
              AND APIKampanyalar_Id NOT IN ($ph)
        ", array_merge([ISP_TUR_ID, $kat], $ids))['c'] ?? 0);
        $db->execute("
            UPDATE APIKampanyalar SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
            WHERE APIKampanyalar_Tur = ? AND APIKampanyalar_KategoriAdi = ? AND Durum = 1
              AND APIKampanyalar_Id NOT IN ($ph)
        ", array_merge([(int)$kullaniciId, ISP_TUR_ID, $kat], $ids));
    }

    // (b) API hediyesi: TV paketi başına bir kez Endpoint 14 sorulur; dönen zorunlu grup
    // adı yalnız Hediye alanı BOŞ olan kayıtlara yazılır (elle yazılan metne dokunulmaz).
    $ispHediye = 0;
    $epHediye  = $db->fetchOne("SELECT APIEndpointler_Endpoint FROM APIEndpointler WHERE APIEndpointler_Id = 14 AND Durum = 1");
    if ($epHediye && $ispTvPaket) {
        foreach ($ispTvPaket as $tk => $tv) {
            [$fId, $tId] = array_map('intval', explode('/', $tk));
            $ch = curl_init($epHediye['APIEndpointler_Endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['offerFromId' => $fId, 'offerToId' => $tId]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Token: ' . $token],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 30,
            ]);
            $hr = curl_exec($ch);
            $herr = curl_error($ch);
            unset($ch);
            if ($herr || $hr === false) { $errors[] = "Hediye sorgusu ({$tv['kod']}): Bağlantı hatası ($herr)"; continue; }
            $hj = json_decode((string)$hr, true);
            $metinler = [];
            foreach (($hj['data']['groupOfEntityEntity'] ?? null) ?: [] as $g) {
                $sec = array_filter(array_map(fn($p) => trim((string)($p['productSpecName'] ?? $p['offerToCd'] ?? '')),
                                              $g['packageOfEntity'] ?? []));
                $m = trim((string)($g['name'] ?? ''));
                if ($m === '') continue;
                if (count($sec) > 1) $m .= ' (' . (int)($g['minValue'] ?? 1) . ' seçim: ' . implode(', ', $sec) . ')';
                $metinler[] = $m;
            }
            if (!$metinler) continue;
            $ids = array_values(array_unique(array_map('intval', $tv['idler'])));
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $metin = mb_substr('API hediyesi: ' . implode(' | ', $metinler), 0, 1000);
            $ispHediye += (int)($db->fetchOne("
                SELECT COUNT(*) AS c FROM APIKampanyalar
                WHERE APIKampanyalar_Id IN ($ph) AND LTRIM(RTRIM(ISNULL(APIKampanyalar_Hediye, ''))) = ''
            ", $ids)['c'] ?? 0);
            $db->execute("
                UPDATE APIKampanyalar SET APIKampanyalar_Hediye = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                WHERE APIKampanyalar_Id IN ($ph) AND LTRIM(RTRIM(ISNULL(APIKampanyalar_Hediye, ''))) = ''
            ", array_merge([$metin, (int)$kullaniciId], $ids));
        }
    }

    // (c) KOI / Süper KOI bayrakları — IRIS "Kampanya Bilgileri" açıklamasından.
    // ccapi listesi bölge bilgisi vermez; IRIS (CrmOffer/GetAgencyCampaignInfo) açıklamada
    // "Kalkınmada Öncelikli İllere Özel" / "SÜPER KOİ İllerine Özel" yazar.
    // Yalnız 0 → 1 yapılır; elle verilen bayraklar kaldırılmaz.
    $koiAdet = $superKoiAdet = 0;
    try {
        require_once __DIR__ . '/IrisTalepServisi.php';
        $iris = irisPersonelOturum($db, (int)siteAyar('api_personel_id', 0));
        try {
            $ir = irisWebPost($iris['ch'], 'CrmOffer/GetAgencyCampaignInfo', new stdClass(),
                              $iris['webToken'], $iris['verToken']);
        } finally {
            irisTalepOturumKapat($iris);
        }
        if ((int)($ir['ResponseCode'] ?? -1) !== 0) {
            throw new RuntimeException(irisMesaj($ir) ?: 'ResponseCode ' . ($ir['ResponseCode'] ?? '?'));
        }
        $koiIdler = $superIdler = [];
        foreach (irisVeri($ir) as $c) {
            $aciklama = (string)($c['CampaignDescription'] ?? '');
            $fromId   = (int)($c['OfferFromId'] ?? 0);
            if (!$fromId || $aciklama === '') continue;
            // Türkçe İ/ı büyük-küçük eşleşmesi PCRE'de güvenilir değil; harf sınıfı açık yazılır.
            if (preg_match('/s[üuÜU]per\s*-?\s*ko[iİIı]/iu', $aciklama)) {
                $superIdler[] = $fromId;
            } elseif (preg_match('/kalk[ıiIİ]nmada\s+[öoÖO]ncel[iİIı]kl[iİIı]/iu', $aciklama)) {
                $koiIdler[] = $fromId;
            }
        }
        foreach ([['APIKampanyalar_KOI', $koiIdler, 'koiAdet'], ['APIKampanyalar_SUPERKOI', $superIdler, 'superKoiAdet']] as [$kol, $ids, $sayac]) {
            $ids = array_values(array_unique($ids));
            if (!$ids) continue;
            foreach (array_chunk($ids, 500) as $parca) {
                $ph = implode(',', array_fill(0, count($parca), '?'));
                $$sayac += (int)($db->fetchOne("
                    SELECT COUNT(*) AS c FROM APIKampanyalar
                    WHERE APIKampanyalar_OfferFromId IN ($ph) AND ISNULL($kol, 0) = 0
                ", $parca)['c'] ?? 0);
                $db->execute("
                    UPDATE APIKampanyalar SET $kol = 1, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                    WHERE APIKampanyalar_OfferFromId IN ($ph) AND ISNULL($kol, 0) = 0
                ", array_merge([(int)$kullaniciId], $parca));
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'IRIS KOI bilgisi alınamadı: ' . $e->getMessage();
        // KOI bilgisi gelmeden yeni kampanya aktif kalırsa (ör. KOI illerine özel ucuz
        // kampanya) tüm şehirlerde görünür. Bu sync'te eklenenler pasif bırakılır.
        $yeni = array_values(array_filter(array_map('intval', $GLOBALS['_syncYeniIdler'] ?? [])));
        if ($yeni) {
            $ph = implode(',', array_fill(0, count($yeni), '?'));
            $db->execute("UPDATE APIKampanyalar SET Durum = 0 WHERE APIKampanyalar_Id IN ($ph)", $yeni);
            $errors[] = count($yeni) . ' yeni kampanya KOI bilgisi alınamadığı için pasif bırakıldı (sync tekrarlanınca aktifleşir).';
        }
    }

    $message = "$totalInserted kayıt eklendi, $totalUpdated kayıt güncellendi.";
    if ($koiAdet)      $message .= " $koiAdet kampanya KOI olarak işaretlendi.";
    if ($superKoiAdet) $message .= " $superKoiAdet kampanya Süper KOI olarak işaretlendi.";
    if ($tvPasif)   $message .= " Listeden kalkan $tvPasif NEO/Uydu kampanyası pasife alındı.";
    if ($ispPasif)  $message .= " Listeden kalkan $ispPasif internet kampanyası pasife alındı.";
    if ($ispHediye) $message .= " $ispHediye internet kampanyasına API hediyesi yazıldı.";
    if ($errors) {
        $message .= ' | Hatalar: ' . implode(' | ', $errors);
    }

    return [
        'success'  => empty($errors) || ($totalInserted + $totalUpdated > 0),
        'message'  => $message,
        'inserted' => $totalInserted,
        'updated'  => $totalUpdated,
        'pasif'    => $ispPasif,
        'hediye'   => $ispHediye,
        'koi'      => $koiAdet,
        'superkoi' => $superKoiAdet,
        'tvPasif'  => $tvPasif,
    ];
}

} // function_exists
