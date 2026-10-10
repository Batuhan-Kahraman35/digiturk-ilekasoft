<?php
/**
 * Digiturk API Kota Servisi
 *
 * Digiturk ccapi 08.09.2026 sürümüyle metod bazlı günlük istek limiti getirdi:
 *   Authentication/Login         → KULLANICI (LoginCd) bazlı günlük 10
 *   Order/CheckRequisition(List) → bayi + talep id bazlı günlük 40
 *
 * LOGIN LİMİTİ KULLANICI BAZLI (15.09.2026 testi): Doküman "bayi bazlı" diyor ama yanlış.
 * ORNEK (10000002) #9 art arda 10 başarılı login sonrası HTTP 429 / responseCode 429
 * aldı; 1 sn sonra aynı OrganisationCd'deki #21, #20, #16 token aldı. Başarılı login'ler
 * sayılıyor. Bu yüzden bayi bazlı sayaç/kilit YOK; kısıtlama personel bazlıdır.
 *
 * ORTAK LOGIN (digiturkLogin): Tüm kaynaklar bu fonksiyonu kullanır. Personelin günlük
 * login limiti (_GunlukLoginLimit, panelden yalnız admin; NULL = sınırsız, en fazla 10)
 * Digiturk'e giden her isteği sayar (_GunlukLoginAdedi). Hata cevapları
 * DigiturkLoginHataKurallari tablosuyla sınıflandırılır: şifre süresi dolmuşsa personel
 * şifre değişene kadar (_LoginKilitSifreli), geçici hatada kural süresi kadar
 * (_LoginKilitBitis) kilitlenir. Digiturk limit cevabında kilit yazılmaz, yanıt aynen döner.
 *
 * Kullanan: cron/tasks.php, api/index.php, admin/pages/bayi-yonetimi.php, admin/pages/api-swagger.php
 */

defined('KOTA_EP_LOGIN')       || define('KOTA_EP_LOGIN',       1);   // APIEndpointler_Id
defined('KOTA_EP_DURUM_LISTE') || define('KOTA_EP_DURUM_LISTE', 18);
defined('KOTA_EP_DURUM_TEKIL') || define('KOTA_EP_DURUM_TEKIL', 17);

if (!function_exists('digiturkKotaTanim')) {

/**
 * Endpoint'in limit tanımını döner.
 * @return array ['limit' => ?int, 'kapsam' => string]  limit null = limitsiz
 */
function digiturkKotaTanim($db, int $endpointId): array
{
    static $onbellek = [];
    if (isset($onbellek[$endpointId])) return $onbellek[$endpointId];

    $r = $db->fetchOne("
        SELECT APIEndpointler_GunlukLimit AS limitAdet, APIEndpointler_LimitKapsami AS kapsam
        FROM APIEndpointler WHERE APIEndpointler_Id = ?", [$endpointId]);

    return $onbellek[$endpointId] = [
        'limit'  => isset($r['limitAdet']) && $r['limitAdet'] !== null ? (int)$r['limitAdet'] : null,
        'kapsam' => (string)($r['kapsam'] ?? 'bayi'),
    ];
}

/**
 * Login token'ının geçerlilik süresi (saat).
 *
 * ÖLÇÜM (08-09.09.2026): Digiturk token'ı TAM 15 SAAT yaşıyor — #1 dün 15:00'te
 * alınan token bugün 06:00'da, #34 dün 06:05'te alınan token dün 21:05'te 401
 * verdi. Kod bunu "+1 gün" varsaydığı için 9 saat boyunca ölü token geçerli
 * sanılıyor, yenileme filtresi devreye girmiyor ve sorgular sessizce 401 alıyordu.
 * Değer APIEndpointler_TokenOmruSaat kolonundan okunur; tanımsızsa güvenli
 * varsayılan 14 saat (ölçülen 15'in bir saat altı) kullanılır.
 */
function digiturkTokenOmruSaat($db): int
{
    static $omur = null;
    if ($omur !== null) return $omur;

    $r = $db->fetchOne("SELECT APIEndpointler_TokenOmruSaat AS omur
                        FROM APIEndpointler WHERE APIEndpointler_Id = ?", [KOTA_EP_LOGIN]);
    $deger = isset($r['omur']) && (int)$r['omur'] > 0 ? (int)$r['omur'] : 14;
    return $omur = max(1, min(24, $deger));
}

/** Yeni alınan token için TokenSuresi değeri. */
function digiturkTokenBitis($db): string
{
    return date('Y-m-d H:i:s', time() + digiturkTokenOmruSaat($db) * 3600);
}

// ─── Ortak Digiturk login ────────────────────────────────────────────────────

/**
 * Aktif login hata kuralları (DigiturkLoginHataKurallari), öncelik sırasıyla.
 */
function digiturkLoginHataKurallari($db): array
{
    static $kurallar = null;
    if ($kurallar !== null) return $kurallar;

    return $kurallar = $db->fetchAll("
        SELECT DigiturkLoginHataKurallari_Ad            AS ad,
               DigiturkLoginHataKurallari_Kalip         AS kalip,
               DigiturkLoginHataKurallari_HttpKodu      AS http,
               DigiturkLoginHataKurallari_HataTuru      AS hataTuru,
               DigiturkLoginHataKurallari_KilitTipi     AS kilitTipi,
               DigiturkLoginHataKurallari_KilitSuresiDk AS sureDk,
               DigiturkLoginHataKurallari_TekrarDene    AS tekrar
        FROM DigiturkLoginHataKurallari
        WHERE Durum = 1
        ORDER BY DigiturkLoginHataKurallari_Oncelik, DigiturkLoginHataKurallari_Id") ?: [];
}

/**
 * Digiturk cevabını kurallarla eşleştirir. Eşleşme yoksa null.
 * Kalıp SQL LIKE sözdizimindedir (% ve _), büyük/küçük harf duyarsız karşılaştırılır.
 */
function digiturkLoginHataKuraliBul($db, int $httpKodu, string $mesaj): ?array
{
    foreach (digiturkLoginHataKurallari($db) as $k) {
        if ($k['http'] !== null && (int)$k['http'] !== $httpKodu) continue;
        if ($k['kalip'] !== null && $k['kalip'] !== '') {
            $regex = '/^' . strtr(preg_quote((string)$k['kalip'], '/'), ['%' => '.*', '_' => '.']) . '$/isu';
            if (!preg_match($regex, $mesaj)) continue;
        }
        return $k;
    }
    return null;
}

/**
 * Digiturk login — tüm kaynaklar (cron, dış API, panel, swagger) bu fonksiyonu kullanır.
 *
 *   1. Personel pasif / bilgisi eksik        → Digiturk'e gidilmez
 *   2. Geçerli token var (ve $zorla değil)   → kayıtlı token döner
 *   3. Personel kilitli (şifre / bekleme)    → gidilmez
 *   4. Personel günlük login limiti dolu     → gidilmez (_GunlukLoginLimit; NULL = sınırsız)
 *   5. Digiturk'e istek; hata cevabı DigiturkLoginHataKurallari ile sınıflandırılıp
 *      personele ilgili kilit yazılır.
 *
 * Günlük limit Digiturk'e GİDEN her isteği sayar (başarılı/başarısız, tekrar dahil);
 * önbellekten dönen token sayılmaz. Tüm kaynaklar (panel butonu dahil) aynı limite uyar.
 *
 * @param string $kaynak  cron | api | panel | swagger (çıktı/log için)
 * @param bool   $zorla   true → geçerli token olsa da yeniler (kilitler yine uygulanır)
 * @param int    $payDk   Token bitimine bu kadar dakikadan az kalmışsa geçersiz sayılır
 * @param int    $kullaniciId GuncelleyenKullanici (sistem kaynakları için 1)
 * @return array [
 *   'basarili'    => bool,
 *   'token'       => ?string,
 *   'onbellek'    => bool,    // kayıtlı token döndü
 *   'istek_gitti' => bool,    // Digiturk'e en az bir istek gönderildi
 *   'deneme'      => int,     // Digiturk'e giden istek sayısı
 *   'hata_turu'   => ?string, // null | pasif | eksik | limit | personel_limit | sifre | gecici | baglanti | bilinmeyen
 *   'mesaj'       => string,
 *   'http'        => int,     // çağırana önerilen HTTP kodu
 *   'digiturk_http' => ?int,  // Digiturk'ün döndüğü HTTP kodu (istek gittiyse)
 *   'yanit'       => ?array,  // Digiturk ham yanıtı (varsa)
 *   'bayi_kodu'   => ?string,
 *   'kilit_bitis' => ?string,
 * ]
 */
function digiturkLogin($db, int $personelId, string $kaynak = 'panel', bool $zorla = false, int $payDk = 60, int $kullaniciId = 1): array
{
    $sonuc = [
        'basarili' => false, 'token' => null, 'onbellek' => false, 'istek_gitti' => false, 'deneme' => 0,
        'hata_turu' => null, 'mesaj' => '', 'http' => 200, 'digiturk_http' => null, 'yanit' => null,
        'bayi_kodu' => null, 'kilit_bitis' => null,
    ];

    $per = $db->fetchOne("
        SELECT p.DigiturkAltBayiPersonel_Id                  AS id,
               p.DigiturkAltBayiPersonel_KullaniciAdi        AS kullanici,
               p.DigiturkAltBayiPersonel_Sifre               AS sifre,
               p.Durum                                       AS durum,
               p.DigiturkAltBayiPersonel_Token               AS token,
               p.DigiturkAltBayiPersonel_TokenSuresi         AS tokenSuresi,
               p.DigiturkAltBayiPersonel_TokenDurum          AS tokenDurum,
               p.DigiturkAltBayiPersonel_LoginKilitBitis     AS perKilitBitis,
               p.DigiturkAltBayiPersonel_LoginKilitSifreli   AS perKilitSifreli,
               p.DigiturkAltBayiPersonel_LoginHataMesaji     AS perHataMesaji,
               p.DigiturkAltBayiPersonel_SonLoginTarihi      AS sonLogin,
               p.DigiturkAltBayiPersonel_GunlukLoginAdedi    AS gunlukDeneme,
               p.DigiturkAltBayiPersonel_GunlukBasariliLogin AS gunlukBasarili,
               p.DigiturkAltBayiPersonel_GunlukLoginLimit    AS gunlukLimit,
               n.DigiturkAnaBayiler_BayiKodu                 AS bayiKodu
        FROM DigiturkAltBayiPersonel p
        LEFT JOIN DigiturkAltBayiler a ON p.DigiturkAltBayiPersonel_AltBayiId = a.DigiturkAltBayiler_Id
        LEFT JOIN DigiturkAnaBayiler n ON a.DigiturkAltBayiler_AnaBayiId      = n.DigiturkAnaBayiler_Id
        WHERE p.DigiturkAltBayiPersonel_Id = ?", [$personelId]);

    // 1) Personel
    if (!$per || (int)$per['durum'] !== 1) {
        return array_merge($sonuc, ['hata_turu' => 'pasif', 'http' => 401,
            'mesaj' => "Personel #{$personelId} bulunamadı veya pasif."]);
    }
    $sonuc['bayi_kodu'] = $per['bayiKodu'];
    if (empty($per['kullanici']) || empty($per['sifre']) || empty($per['bayiKodu'])) {
        return array_merge($sonuc, ['hata_turu' => 'eksik', 'http' => 400,
            'mesaj' => "Personel #{$personelId}: kullanıcı adı, şifre veya bayi kodu eksik."]);
    }

    $simdi = time();

    // 2) Geçerli token
    if (!$zorla && (int)$per['tokenDurum'] === 1 && !empty($per['token']) && !empty($per['tokenSuresi'])
        && strtotime((string)$per['tokenSuresi']) > $simdi + $payDk * 60) {
        return array_merge($sonuc, ['basarili' => true, 'onbellek' => true, 'token' => $per['token'],
            'mesaj' => 'Kayıtlı token kullanıldı (bitiş: ' . $per['tokenSuresi'] . ').']);
    }

    // 3) Personel kilidi
    if ((int)$per['perKilitSifreli'] === 1) {
        return array_merge($sonuc, ['hata_turu' => 'sifre', 'http' => 401,
            'mesaj' => "Personel #{$personelId} şifre hatası nedeniyle kilitli; şifre güncellenene kadar login atılmaz. "
                     . ($per['perHataMesaji'] ?? '')]);
    }
    if (!empty($per['perKilitBitis']) && strtotime((string)$per['perKilitBitis']) > $simdi) {
        return array_merge($sonuc, ['hata_turu' => 'gecici', 'http' => 503, 'kilit_bitis' => $per['perKilitBitis'],
            'mesaj' => "Personel #{$personelId} geçici beklemede (bitiş: {$per['perKilitBitis']}). "
                     . ($per['perHataMesaji'] ?? '')]);
    }

    // 4) Personel günlük login limiti (bugün Digiturk'e giden istek sayısı)
    $ayniGun     = !empty($per['sonLogin']) && date('Y-m-d', strtotime((string)$per['sonLogin'])) === date('Y-m-d');
    $bugunDeneme = $ayniGun ? (int)$per['gunlukDeneme'] : 0;
    $gunlukLimit = $per['gunlukLimit'] !== null ? (int)$per['gunlukLimit'] : null;
    if ($gunlukLimit !== null && $bugunDeneme >= $gunlukLimit) {
        return array_merge($sonuc, ['hata_turu' => 'personel_limit', 'http' => 429,
            'kilit_bitis' => date('Y-m-d 00:00:00', strtotime('+1 day')),
            'mesaj' => "Personel #{$personelId} günlük login limiti doldu ({$bugunDeneme}/{$gunlukLimit}). "
                     . 'Limit gece yarısı sıfırlanır; gerekirse Bayi Yönetimi → Personel sekmesinden artırılabilir.']);
    }

    // 5) Digiturk'e istek
    $ep = $db->fetchOne("SELECT APIEndpointler_Endpoint FROM APIEndpointler WHERE APIEndpointler_Id = ? AND Durum = 1", [KOTA_EP_LOGIN]);
    if (!$ep || empty($ep['APIEndpointler_Endpoint'])) {
        return array_merge($sonuc, ['hata_turu' => 'bilinmeyen', 'http' => 500,
            'mesaj' => 'Login endpoint tanımı yok (APIEndpointler Id=' . KOTA_EP_LOGIN . ').']);
    }

    $body = json_encode(['OrganisationCd' => $per['bayiKodu'], 'LoginCd' => $per['kullanici'], 'Password' => $per['sifre']]);

    $deneme   = 0;
    $kural    = null;
    $json     = null;
    $httpKodu = 0;
    $curlErr  = '';
    $mesaj    = '';
    $basarili = false;
    for ($i = 1; $i <= 2; $i++) {
        $deneme++;
        $ch = curl_init($ep['APIEndpointler_Endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $resp     = curl_exec($ch);
        $httpKodu = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $json  = $curlErr ? null : json_decode((string)$resp, true);
        // Digiturk hata cevaplarında alan adları PascalCase gelebiliyor (ResponseMessage)
        $mesaj = $curlErr ?: (string)($json['responseMessage'] ?? $json['ResponseMessage'] ?? "HTTP {$httpKodu}");

        // Başarı cevabı bugüne kadar camelCase geldi; hata cevaplarında PascalCase görüldüğü
        // için (2.16.3) başarı alanları da iki biçimde okunur.
        $token    = $json['data']['token'] ?? $json['Data']['Token'] ?? $json['Data']['token'] ?? null;
        $kod      = $json['responseCode'] ?? $json['ResponseCode'] ?? -1;
        $basarili = !$curlErr && $httpKodu === 200 && !empty($token) && (int)$kod === 0;
        if ($basarili || $curlErr) break;

        $kural = digiturkLoginHataKuraliBul($db, $httpKodu, $mesaj);
        // Yalnız kural izin veriyorsa, bu ilk denemeyse ve günlük limit elveriyorsa bir kez daha
        $limitElverir = $gunlukLimit === null || $bugunDeneme + $deneme < $gunlukLimit;
        if ($i === 1 && $kural && (int)$kural['tekrar'] === 1 && $limitElverir) { sleep(3); continue; }
        break;
    }

    $sonuc['istek_gitti']   = true;
    $sonuc['deneme']        = $deneme;
    $sonuc['digiturk_http'] = $curlErr ? null : $httpKodu;
    $sonuc['yanit']       = is_array($json) ? $json : null;

    // Sayaçlar (gün değişince sıfırdan)
    $now     = date('Y-m-d H:i:s');
    $alanlar = [
        'DigiturkAltBayiPersonel_SonLoginTarihi'      => $now,
        'DigiturkAltBayiPersonel_GunlukLoginAdedi'    => $bugunDeneme + $deneme,
        'DigiturkAltBayiPersonel_GunlukBasariliLogin' => ($ayniGun ? (int)$per['gunlukBasarili'] : 0) + ($basarili ? 1 : 0),
        'GuncelleyenKullanici' => $kullaniciId,
        'GuncellemeTarihi'     => $now,
    ];

    // Başarılı
    if ($basarili) {
        $db->update('DigiturkAltBayiPersonel', array_merge($alanlar, [
            'DigiturkAltBayiPersonel_Token'            => $token,
            'DigiturkAltBayiPersonel_TokenSuresi'      => digiturkTokenBitis($db),
            'DigiturkAltBayiPersonel_TokenDurum'       => 1,
            'DigiturkAltBayiPersonel_TokenYanit'       => json_encode($json, JSON_UNESCAPED_UNICODE),
            'DigiturkAltBayiPersonel_LoginKilitBitis'  => null,
            'DigiturkAltBayiPersonel_LoginKilitSifreli'=> 0,
            'DigiturkAltBayiPersonel_LoginHataMesaji'  => null,
        ]), ['DigiturkAltBayiPersonel_Id' => $personelId]);

        return array_merge($sonuc, ['basarili' => true, 'token' => $token,
            'mesaj' => "Digiturk'ten yeni token alındı ({$deneme} deneme)."]);
    }

    // Başarısız
    // responseMessage yoksa (ör. mesajsız HTTP 500) ham gövdenin başı eklenir; kural eşleşmesi $mesaj ile yapıldı
    $mesajDetay = $mesaj;
    if (!$curlErr && !isset($json['responseMessage']) && !isset($json['ResponseMessage'])) {
        $govde = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$resp)));
        $mesajDetay .= $govde !== '' ? ' — ' . mb_substr($govde, 0, 300) : ' — (boş yanıt)';
    }
    $mesajKisa = mb_substr($mesajDetay, 0, 500);

    if ($curlErr) {
        // Bizim taraftaki bağlantı hatası: kilit yazılmaz
        $db->update('DigiturkAltBayiPersonel', array_merge($alanlar, [
            'DigiturkAltBayiPersonel_TokenDurum'      => 2,
            'DigiturkAltBayiPersonel_LoginHataMesaji' => $mesajKisa,
        ]), ['DigiturkAltBayiPersonel_Id' => $personelId]);
        return array_merge($sonuc, ['hata_turu' => 'baglanti', 'http' => 502,
            'mesaj' => "Digiturk bağlantı hatası: {$mesaj}"]);
    }

    $hataTuru   = $kural['hataTuru'] ?? 'bilinmeyen';
    $kilitBitis = null;
    $perAlanlar = ['DigiturkAltBayiPersonel_LoginHataMesaji' => $mesajKisa];

    // Limit token'ın kendisiyle ilgili değil; TokenDurum'a dokunulmaz
    if ($hataTuru !== 'limit') $perAlanlar['DigiturkAltBayiPersonel_TokenDurum'] = 2;

    if ($kural) {
        switch ($kural['kilitTipi']) {
            case 'gun_sonu':
                $kilitBitis = date('Y-m-d 00:00:00', strtotime('+1 day'));
                break;
            case 'sure':
                $kilitBitis = date('Y-m-d H:i:s', $simdi + max(1, (int)$kural['sureDk']) * 60);
                break;
            case 'sifre_degisene_kadar':
                $perAlanlar['DigiturkAltBayiPersonel_LoginKilitSifreli'] = 1;
                break;
        }

        // Digiturk limiti kullanıcı bazlı olduğu için kilit her zaman personele yazılır
        if ($kilitBitis !== null) {
            $perAlanlar['DigiturkAltBayiPersonel_LoginKilitBitis'] = $kilitBitis;
        }
    }

    $db->update('DigiturkAltBayiPersonel', array_merge($alanlar, $perAlanlar), ['DigiturkAltBayiPersonel_Id' => $personelId]);

    $httpOneri = ['limit' => 429, 'sifre' => 401, 'gecici' => 503][$hataTuru] ?? ($httpKodu ?: 502);

    return array_merge($sonuc, [
        'hata_turu'   => $hataTuru,
        'http'        => $httpOneri,
        'kilit_bitis' => $kilitBitis,
        'mesaj'       => $mesajDetay . ($kural ? " [{$kural['ad']}]" : ''),
    ]);
}

/**
 * Personelin şifresi değiştiğinde çağrılır: şifre kilidi ve geçici bekleme kaldırılır.
 * (bayi-yonetimi personel kaydetme)
 */
function digiturkLoginKilidiniKaldir($db, int $personelId, int $kullaniciId = 1): void
{
    $db->update('DigiturkAltBayiPersonel', [
        'DigiturkAltBayiPersonel_LoginKilitSifreli' => 0,
        'DigiturkAltBayiPersonel_LoginKilitBitis'   => null,
        'DigiturkAltBayiPersonel_LoginHataMesaji'   => null,
        'GuncelleyenKullanici' => $kullaniciId,
        'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
    ], ['DigiturkAltBayiPersonel_Id' => $personelId]);
}

/**
 * IRIS / Digiturk login hatası şifre kaynaklıysa (DigiturkLoginHataKurallari, HataTuru='sifre')
 * aktif adminlere (departman 1) panel + push bildirimi gönderir. Hesap kilitlenmez.
 *
 * Tekrar önleme: aynı hesap için okunmamış ya da son 24 saatte gönderilmiş bildirim varsa
 * yenisi oluşturulmaz (görev her saat aynı hatayı aldığı için).
 *
 * @param string $hesapTuru 'anabayi' | 'personel'
 * @return int Gönderilen bildirim sayısı (şifre hatası değilse / tekrar ise 0)
 */
function loginSifreHatasiBildir($db, string $hesapTuru, int $hesapId, string $hesapAd, string $mesaj, string $kaynak): int
{
    try {
        $sifreHatasi = false;
        foreach (digiturkLoginHataKurallari($db) as $k) {
            if ($k['hataTuru'] !== 'sifre' || $k['kalip'] === null || $k['kalip'] === '') continue;
            $regex = '/^' . strtr(preg_quote((string)$k['kalip'], '/'), ['%' => '.*', '_' => '.']) . '$/isu';
            if (preg_match($regex, $mesaj)) { $sifreHatasi = true; break; }
        }
        if (!$sifreHatasi) return 0;

        $etiket = $hesapTuru === 'anabayi' ? 'Ana bayi' : 'Personel';
        $baslik = "Şifre hatası: {$etiket} #{$hesapId} {$hesapAd}";

        $tekrar = $db->fetchOne("
            SELECT TOP 1 Bildirimler_id AS id FROM dbo.Bildirimler
            WHERE Bildirimler_Baslik = ?
              AND (Bildirimler_Okundu = 0 OR OlusturmaTarihi > DATEADD(HOUR, -24, GETDATE()))", [mb_substr($baslik, 0, 200)]);
        if ($tekrar) return 0;

        require_once __DIR__ . '/Bildirim.php';
        $adminler = $db->fetchAll("
            SELECT kullanici_id AS id FROM kullanicilar
            WHERE kullanici_departman_id = 1 AND kullanici_durum = 1") ?: [];

        $govde = "{$kaynak} IRIS'e giriş yapamadı: " . mb_substr(preg_replace('/\s+/u', ' ', $mesaj), 0, 400)
               . ' — Şifreyi IRIS\'te doğrulayıp Bayi Yönetimi\'nden güncelleyin.';

        foreach ($adminler as $a) {
            Bildirim::olustur($db, [
                'kullanici_id' => (int)$a['id'],
                'baslik'       => $baslik,
                'govde'        => $govde,
                'url'          => '/admin/bayi-yonetimi',
                'tip'          => 'hata',
                'push'         => true,
                'olusturan'    => 1,
            ]);
        }
        return count($adminler);
    } catch (Throwable $e) {
        // Bildirim hatası asıl görevi bozmasın
        error_log('loginSifreHatasiBildir: ' . $e->getMessage());
        return 0;
    }
}

} // function_exists guard
