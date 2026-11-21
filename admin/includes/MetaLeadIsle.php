<?php
/**
 * Meta lead'ini Basvurular'a işleyen ortak akış.
 *
 * Kullananlar:
 *  - webhook/meta-lead.php          → anlık leadgen bildirimi
 *  - reklam-yonetimi (form_eksik_lead_cek) → formun geçmiş lead'lerini elle çekme
 *  - cron (meta_eksik_lead)          → son günlerde webhook'un kaçırdıklarını tamamlama
 *
 * Tek lead için sıra: tekrar kontrolü (Basvurular_LeadgenID) → lead detayı → pasif form →
 * form adı kuralları → varsayılan alan eşlemesi → alan eşleme → kara liste → INSERT.
 */

require_once __DIR__ . '/MetaLeadAlan.php';
require_once __DIR__ . '/LeadFormKural.php';
require_once __DIR__ . '/KaraListeHelper.php';

class MetaLeadIsle
{
    public const SISTEM_KULLANICI = 1; // OlusturanKullanici (sistem)

    /** Aktif TÜM Meta entegrasyonları (çoklu app). */
    public static function configListesi(Database $db): array
    {
        return $db->fetchAll("
            SELECT e.Entegrasyonlar_id                 AS EntegrasyonId,
                   e.Entegrasyonlar_Adi                AS Adi,
                   e.Entegrasyonlar_ApiKey             AS AppSecret,
                   e.Entegrasyonlar_BaseURL            AS BaseURL,
                   e.Entegrasyonlar_WebhookVerifyToken AS VerifyToken,
                   k.EntegrasyonKanallari_Sifre        AS SistemToken,
                   k.EntegrasyonKanallari_Instance     AS AppId,
                   k.EntegrasyonKanallari_id           AS KanalId
            FROM Entegrasyonlar e
            LEFT JOIN EntegrasyonKanallari k
                   ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id AND k.Durum = 1
            WHERE e.Entegrasyonlar_Tip = 'meta' AND e.Durum = 1
            ORDER BY e.Entegrasyonlar_id
        ") ?: [];
    }

    /** appsecret_proof üretir. */
    public static function proof(string $token, string $secret): string
    {
        return hash_hmac('sha256', $token, $secret);
    }

    /** Graph GET → ['ok'=>bool,'data'=>array,'kod'=>int,'hata'=>string]. */
    public static function graphGet(string $url, int $timeout = 30): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $cevap = curl_exec($ch);
        $kod   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hata  = curl_error($ch);
        unset($ch);
        $json = json_decode((string)$cevap, true) ?: [];
        return ['ok' => ($kod >= 200 && $kod < 300 && !$hata), 'data' => $json, 'kod' => $kod, 'hata' => $hata];
    }

    /** Graph hata mesajı (log/ekran için tek satır). */
    public static function hataMetni(array $r): string
    {
        return (string)($r['data']['error']['message'] ?? ($r['hata'] ?: 'HTTP ' . $r['kod']));
    }

    /**
     * Sayfa askıda mı (SQL EXISTS ifadesi). Açık (kapanış durumunda olmayan) olay, türü lead akışını
     * durduran (tanim_MetaOlayTurleri_LeadAkisiDurur = 1) ya da Graph erişim hatası (MetaKodu GRAPH%) ise askıda sayılır.
     * Askıdaki sayfanın formları eksik lead / sağlık taramasında atlanır, olay kapanınca kendiliğinden geri gelir.
     * Durum alanına dokunulmaz: pasif form lead almaz, askı kalkınca gelen lead'ler kaybolmasın.
     *
     * @param string $sayfaIdIfade ReklamFacebookSayfalari_id veren SQL ifadesi (ör. 's.ReklamFacebookSayfalari_id')
     */
    public static function askidaKosulu(string $sayfaIdIfade): string
    {
        return "EXISTS (
            SELECT 1 FROM MetaKisitlamaOlaylari ako
            INNER JOIN tanim_MetaOlayDurumlari akd ON akd.tanim_MetaOlayDurumlari_id = ako.MetaKisitlamaOlaylari_OlayDurumu_id
            LEFT JOIN tanim_MetaOlayTurleri akt ON akt.tanim_MetaOlayTurleri_id = ako.MetaKisitlamaOlaylari_OlayTuru_id
            WHERE ako.MetaKisitlamaOlaylari_FacebookSayfa_id = {$sayfaIdIfade}
              AND ako.Durum = 1 AND akd.tanim_MetaOlayDurumlari_Kapanis = 0
              AND (akt.tanim_MetaOlayTurleri_LeadAkisiDurur = 1 OR ako.MetaKisitlamaOlaylari_MetaKodu LIKE 'GRAPH%')
        )";
    }

    /** Graph URL'i: token + appsecret_proof eklenmiş. */
    private static function url(array $baglam, string $yol, array $params = []): string
    {
        $params['access_token']    = $baglam['token'];
        $params['appsecret_proof'] = self::proof($baglam['token'], (string)$baglam['cfg']['AppSecret']);
        return rtrim((string)$baglam['cfg']['BaseURL'], '/') . '/' . ltrim($yol, '/') . '?' . http_build_query($params);
    }

    /**
     * Sayfanın Page Token'ı + token'ın ait olduğu app config'i.
     * Token hangi app'e aitse appsecret_proof o app'in secret'ıyla üretilmeli; sayfa başka bir
     * app'e bağlıysa (OAuth ile devredilmiş olabilir) onun config'i kullanılır.
     * Page Token yoksa System User token'a düşülür. Token hiç yoksa null.
     */
    public static function sayfaBaglami(Database $db, array $liste, string $pageId, ?array $varsayilan = null): ?array
    {
        $sayfa = $pageId !== '' ? $db->fetchOne("
            SELECT ReklamFacebookSayfalari_id, ReklamFacebookSayfalari_Token,
                   ReklamFacebookSayfalari_Entegrasyon_id
            FROM ReklamFacebookSayfalari WHERE ReklamFacebookSayfalari_SayfaID = ?
        ", [$pageId]) : null;

        $cfg        = $varsayilan ?? ($liste[0] ?? null);
        $sayfaEntId = (int)($sayfa['ReklamFacebookSayfalari_Entegrasyon_id'] ?? 0);
        if ($sayfaEntId > 0 && (int)($cfg['EntegrasyonId'] ?? 0) !== $sayfaEntId) {
            foreach ($liste as $c) {
                if ((int)$c['EntegrasyonId'] === $sayfaEntId) { $cfg = $c; break; }
            }
        }
        if (!$cfg) return null;

        $sayfaToken  = trim((string)($sayfa['ReklamFacebookSayfalari_Token'] ?? ''));
        $sistemToken = trim((string)($cfg['SistemToken'] ?? ''));
        $token       = $sayfaToken ?: $sistemToken;
        if ($token === '') return null;

        // Yedek token: sayfa token'ı lead okuyamazsa (Lead Access kısıtı) System User denenir.
        // Ters durum da olur (System User'ın erişimi yok, sayfa token'ı var); birincil her zaman sayfa token'ı.
        $yedek = ($sayfaToken !== '' && $sistemToken !== '' && $sayfaToken !== $sistemToken) ? $sistemToken : null;
        return ['token' => $token, 'cfg' => $cfg, 'yedekToken' => $yedek];
    }

    /** Bağlamın yedek token'lı kopyası; yedek yoksa null. */
    private static function yedekBaglam(array $baglam): ?array
    {
        $t = (string)($baglam['yedekToken'] ?? '');
        return $t === '' ? null : ['token' => $t, 'cfg' => $baglam['cfg'], 'yedekToken' => null];
    }

    /** Meta created_time (ISO 8601, UTC) → yerel saat 'Y-m-d H:i:s'. Okunamazsa null. */
    public static function yerelTarih(?string $metaTarih): ?string
    {
        if (!$metaTarih) return null;
        try {
            return (new DateTime($metaTarih))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Tek lead'i işler.
     *
     * $sec:
     *  - lead         : önceden çekilmiş lead (field_data, form_id, created_time) → Graph'a tekrar gidilmez
     *  - gercekTarih  : true → OlusturmaTarihi = lead'in Meta'daki created_time (geriye dönük çekim)
     *  - nokta        : kara liste log noktası
     *  - karaListeLog : false → engelde kara liste logu yazılmaz (cron her turda aynı lead'i görür)
     *  - kuru         : true → hiçbir şey yazılmaz (INSERT, alan eşleme senkronu, kara liste logu yok);
     *                   eklenecek lead için durum 'eklenecek' döner (sağlık kontrolü: "panelde olması gereken")
     *
     * Dönüş: ['durum' => eklendi|eklenecek|mevcut|tokenyok|graph|pasif|kural|karaliste|hata, 'mesaj' => string]
     */
    public static function isle(Database $db, array $baglam, string $leadgenId, string $formId = '', array $sec = []): array
    {
        if ($leadgenId === '') return ['durum' => 'hata', 'mesaj' => 'leadgen_id bos'];

        // Tekrar → atla (idempotent: Basvurular_LeadgenID)
        if ($db->fetchOne("SELECT Basvurular_id FROM Basvurular WHERE Basvurular_LeadgenID = ?", [$leadgenId])) {
            return ['durum' => 'mevcut', 'mesaj' => "MEVCUT lead=$leadgenId"];
        }

        // Lead detayı (birincil token okuyamazsa yedek token denenir)
        $notlar = [];
        $lead = $sec['lead'] ?? null;
        if (!$lead) {
            $alanlar = ['fields' => 'field_data,form_id,created_time'];
            $r = self::graphGet(self::url($baglam, $leadgenId, $alanlar));
            if (!$r['ok'] && ($yb = self::yedekBaglam($baglam))) {
                $ry = self::graphGet(self::url($yb, $leadgenId, $alanlar));
                if ($ry['ok']) {
                    $notlar[] = "Yedek token ile okundu lead=$leadgenId (birincil: " . self::hataMetni($r) . ')';
                    $r = $ry; $baglam = $yb;
                }
            }
            if (!$r['ok']) {
                return ['durum' => 'graph', 'mesaj' => "Graph HATA lead=$leadgenId kod={$r['kod']} " . self::hataMetni($r)];
            }
            $lead = $r['data'];
        }

        $fieldData    = $lead['field_data'] ?? [];
        $gercekFormId = (string)($lead['form_id'] ?? $formId);

        // Form FK eşleşmesi
        $formFk = null;
        $f      = null;
        if ($gercekFormId !== '') {
            $f = $db->fetchOne(
                "SELECT ReklamLeadFormlari_id, ReklamLeadFormlari_FormAdi, Durum FROM ReklamLeadFormlari WHERE ReklamLeadFormlari_FormID = ?",
                [$gercekFormId]
            );
            $formFk = $f['ReklamLeadFormlari_id'] ?? null;
        }

        // Pasif form → lead alınmaz
        if ($f && (int)$f['Durum'] === 0) {
            return ['durum' => 'pasif', 'mesaj' => "PASIF FORM atlandi lead=$leadgenId form=$gercekFormId"];
        }

        // Lead alım kuralları (form adına göre). Form henüz DB'de yoksa adı Graph'tan alınır;
        // ad hiç öğrenilemezse lead kaybolmasın diye alınır.
        $kurallar = LeadFormKural::kurallar($db);
        if ($kurallar && $gercekFormId !== '') {
            $formAdi = (string)($f['ReklamLeadFormlari_FormAdi'] ?? '');
            if ($formAdi === '') {
                $rn      = self::graphGet(self::url($baglam, $gercekFormId, ['fields' => 'name']));
                $formAdi = (string)($rn['data']['name'] ?? '');
            }
            $engel = $formAdi !== '' ? LeadFormKural::engelNedeni($formAdi, $kurallar) : null;
            if ($engel) {
                return ['durum' => 'kural', 'mesaj' => "KURAL atlandi lead=$leadgenId form=$gercekFormId ($formAdi) neden=" . $engel['neden']];
            }
        }

        // Form için hiç alan satırı yoksa (yeni form) soruları çekip varsayılan eşlemeyi kur:
        // ad soyad / telefon / e-posta kendi alanına, kalanlar Açıklama'ya
        $kuru = !empty($sec['kuru']);
        if ($formFk && !$kuru) {
            $alanVar = $db->fetchOne(
                "SELECT TOP 1 ReklamLeadFormAlanlari_id FROM ReklamLeadFormAlanlari WHERE ReklamLeadFormAlanlari_Form_id = ?",
                [$formFk]
            );
            if (!$alanVar) {
                $rq = self::graphGet(self::url($baglam, $gercekFormId, ['fields' => 'questions{key,label,type}']));
                if ($rq['ok'] && !empty($rq['data']['questions'])) {
                    try {
                        formAlanlariSenkronla($db, (int)$formFk, $rq['data']['questions'], self::SISTEM_KULLANICI);
                        $notlar[] = "Varsayilan alan eslemesi olusturuldu form=$formFk";
                    } catch (Throwable $e) {
                        $notlar[] = "Varsayilan esleme HATA form=$formFk " . $e->getMessage();
                    }
                } else {
                    $notlar[] = "Form sorulari alinamadi form=$formFk kod={$rq['kod']}";
                }
            }
        }

        // Alan eşlemesi (form için tanımlıysa) — yoksa otomatik tahmin devreye girer
        $a = alanlariEsle($db, $formFk !== null ? (int)$formFk : null, $fieldData);

        // KARA LİSTE — engellenen numara hiç kaydedilmez.
        $gsm   = KaraListe::kayittanGsm([
            'phoneCountryNumber' => $a['phoneCountryNumber'],
            'phoneAreaNumber'    => $a['phoneAreaNumber'],
            'phoneNumber'        => $a['phoneNumber'],
        ]);
        $engel = (($sec['karaListeLog'] ?? true) && !$kuru)
            ? KaraListe::kontrolVeLogla(
                $gsm,
                $sec['nokta'] ?? 'webhook:meta-lead',
                ['leadgen_id' => $leadgenId, 'form_id' => $gercekFormId, 'alanlar' => $a],
                null,
                self::SISTEM_KULLANICI
            )
            : KaraListe::kontrol($gsm, 'gsm');
        if ($engel) {
            return ['durum' => 'karaliste', 'mesaj' => "KARA LISTE engel lead=$leadgenId gsm=" . $engel['KaraListe_Deger'], 'notlar' => $notlar];
        }

        if ($kuru) {
            return ['durum' => 'eklenecek', 'mesaj' => "EKLENECEK lead=$leadgenId", 'notlar' => $notlar];
        }

        $now    = date('Y-m-d H:i:s');
        $tarih  = !empty($sec['gercekTarih']) ? (self::yerelTarih($lead['created_time'] ?? null) ?? $now) : $now;
        try {
            $db->insert('Basvurular', array_merge($a, [
                'ReklamLeadFormlari_ID' => $formFk,
                'Basvurular_LeadgenID'  => $leadgenId,
                'OlusturanKullanici'    => self::SISTEM_KULLANICI,
                'OlusturmaTarihi'       => $tarih,
                'GuncelleyenKullanici'  => self::SISTEM_KULLANICI,
                'GuncellemeTarihi'      => $now,
            ]));
        } catch (Throwable $e) {
            return ['durum' => 'hata', 'mesaj' => "INSERT HATA lead=$leadgenId " . $e->getMessage(), 'notlar' => $notlar];
        }
        return ['durum' => 'eklendi', 'mesaj' => "EKLENDI lead=$leadgenId", 'notlar' => $notlar];
    }

    /**
     * Formun Meta'daki lead'lerinden bir sayfa (Graph cursor ile) çeker ve her birini isle()'den geçirir.
     * Meta lead verisini ~90 gün tutar; $baslangicTs (unix) verilirse yalnız o andan sonraki lead'ler gelir.
     *
     * Dönüş: ['ok'=>bool, 'hata'=>?string, 'sonraki'=>?string (after cursor),
     *         'sayac'=>['bulunan'=>n, 'eklendi'=>n, 'mevcut'=>n, ...]]
     */
    public static function formLeadleriniCek(Database $db, int $formFk, ?string $after = null, int $limit = 25, ?int $baslangicTs = null, array $sec = []): array
    {
        $sayac = ['bulunan' => 0, 'eklendi' => 0, 'mevcut' => 0, 'pasif' => 0, 'kural' => 0, 'karaliste' => 0, 'graph' => 0, 'hata' => 0, 'tokenyok' => 0];

        $form = $db->fetchOne("
            SELECT f.ReklamLeadFormlari_FormID AS FormID, s.ReklamFacebookSayfalari_SayfaID AS SayfaID
            FROM ReklamLeadFormlari f
            LEFT JOIN ReklamFacebookSayfalari s ON s.ReklamFacebookSayfalari_id = f.ReklamLeadFormlari_Sayfa_id
            WHERE f.ReklamLeadFormlari_id = ?
        ", [$formFk]);
        if (!$form || (string)$form['FormID'] === '') {
            return ['ok' => false, 'hata' => 'Form bulunamadı.', 'sonraki' => null, 'sayac' => $sayac];
        }

        $baglam = self::sayfaBaglami($db, self::configListesi($db), (string)($form['SayfaID'] ?? ''));
        if (!$baglam) {
            return ['ok' => false, 'hata' => 'Sayfa token\'ı bulunamadı.', 'sonraki' => null, 'sayac' => $sayac];
        }

        // Yedek token'la başlamış sayfalama: cursor 'y:' önekiyle döner, aynı token'la sürdürülür
        $yedekte = $after !== null && str_starts_with($after, 'y:');
        if ($yedekte) {
            $after = substr($after, 2);
            $yb = self::yedekBaglam($baglam);
            if (!$yb) return ['ok' => false, 'hata' => 'Yedek token bulunamadı.', 'sonraki' => null, 'sayac' => $sayac];
            $baglam = $yb;
        }

        $params = ['fields' => 'id,created_time,field_data,form_id', 'limit' => $limit];
        if ($after) $params['after'] = $after;
        if ($baslangicTs) {
            $params['filtering'] = json_encode([[
                'field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $baslangicTs,
            ]]);
        }

        $r = self::graphGet(self::url($baglam, $form['FormID'] . '/leads', $params), 60);

        // Lead Access kısıtında sayfa token'ı hata yerine BOŞ liste döner; ilk sayfada boş/hata → yedek token
        if (!$yedekte && $after === null && (!$r['ok'] || empty($r['data']['data'])) && ($yb = self::yedekBaglam($baglam))) {
            $ry = self::graphGet(self::url($yb, $form['FormID'] . '/leads', $params), 60);
            if ($ry['ok'] && !empty($ry['data']['data'])) {
                $r = $ry; $baglam = $yb; $yedekte = true;
            }
        }

        if (!$r['ok']) {
            return ['ok' => false, 'hata' => self::hataMetni($r), 'sonraki' => null, 'sayac' => $sayac];
        }

        $sec += ['gercekTarih' => true];
        foreach (($r['data']['data'] ?? []) as $lead) {
            $sayac['bulunan']++;
            $sonuc = self::isle($db, $baglam, (string)($lead['id'] ?? ''), (string)$form['FormID'], $sec + ['lead' => $lead]);
            $sayac[$sonuc['durum']] = ($sayac[$sonuc['durum']] ?? 0) + 1;
        }

        $sonraki = !empty($r['data']['paging']['next']) ? ($r['data']['paging']['cursors']['after'] ?? null) : null;
        if ($sonraki !== null && $yedekte) $sonraki = 'y:' . $sonraki;
        return ['ok' => true, 'hata' => null, 'sonraki' => $sonraki, 'sayac' => $sayac, 'yedekToken' => $yedekte];
    }

    /** leadSaglik() sorun kodunun açıklaması + yapılacak iş (destek talebi ve panel uyarısı). */
    public static function saglikTalimat(string $kod, array $s): string
    {
        $ad = $s['sayfa']['ad'] . ' (' . $s['sayfa']['ext'] . ')';
        return match ($kod) {
            'OKUMA_YOK' => "Lead'ler hiçbir token ile okunamıyor; yeni lead'ler panele DÜŞMÜYOR.\n"
                         . "Yapılacak: Reklam Yönetimi → Facebook Sayfaları → Meta Bağlan (OAuth) ile sayfa token'ı yenilenmeli, "
                         . "sayfa ORNEK sistem kullanıcısına atanmalı ve İşletme Ayarları → Potansiyel Müşteri Bilgileri Erişimi kontrol edilmeli.",
            'EKSIK'     => count($s['eksik']) . " lead Meta'da var ama panelde yok (webhook ve eksik lead cron'u almamış).\n"
                         . "Yapılacak: Reklam Yönetimi → ilgili form → Eksik lead çek; sonra kök neden (token/abonelik) incelenmeli.",
            'KISIT'     => "Sayfa token'ı lead verisini okuyamıyor (Potansiyel Müşteri Erişimi kısıtı). Lead'ler şu an System User token'ı ile "
                         . "alınıyor; o erişim de kalkarsa lead'ler tamamen durur.\n"
                         . "Yapılacak: İşletme Ayarları → Entegrasyonlar → Potansiyel Müşteri Bilgileri Erişimi → {$s['sayfa']['ad']} → "
                         . 'Kişiler: ' . ($s['tokenSahibi'] ?: 'token sahibi kullanıcı') . " eklenmeli; CRM'ler: uygulama "
                         . ($s['appId'] ?: '-') . ' ekli olmalı.',
            'ABONELIK'  => "Sayfanın leadgen webhook aboneliği yok; yeni lead'ler anlık gelmez (yalnız 3 saatlik eksik lead cron'u ile gelir).\n"
                         . "Yapılacak: Reklam Yönetimi → Facebook Sayfaları → {$ad} → 📡 Webhook aboneliği.",
            default     => $kod,
        };
    }

    /**
     * Sayfanın lead akış sağlığı (salt okuma; cron meta_lead_saglik + Meta Bağlantı Testi).
     * Webhook'un gerçekte kullandığı token'larla (DB'deki Page Token + System User) ölçer:
     *  - OKUMA_YOK : bir aktif formun lead'leri hiçbir token'la okunamıyor → lead'ler kayboluyor
     *  - EKSIK     : son N saatte Meta'da olup panelde olmayan (kural/kara liste/pasif dışı) lead var
     *  - KISIT     : Page Token lead'i okuyamıyor (hata/boş) ama System User okuyor → Potansiyel Müşteri Erişimi kısıtı
     *  - ABONELIK  : sayfanın bizim app'e leadgen webhook aboneliği yok
     *
     * $sec: saat (varsayılan 24) → lead penceresi, eksikDk (varsayılan 60) → bu süreden yeni lead eksik sayılmaz
     */
    public static function leadSaglik(Database $db, string $pageId, array $sec = []): array
    {
        $saat    = max(1, (int)($sec['saat'] ?? 24));
        $eksikDk = max(0, (int)($sec['eksikDk'] ?? 60));
        $sonuc   = ['sayfa' => ['ext' => $pageId, 'ad' => $pageId], 'formlar' => [], 'abonelik' => null, 'abonelikHata' => null,
                    'eksik' => [], 'tokenSahibi' => null, 'kodlar' => [], 'appId' => null];

        $sayfa = $db->fetchOne("
            SELECT ReklamFacebookSayfalari_id AS id, ReklamFacebookSayfalari_SayfaAdi AS ad, ReklamFacebookSayfalari_Token AS token
            FROM ReklamFacebookSayfalari WHERE ReklamFacebookSayfalari_SayfaID = ?
        ", [$pageId]);
        if (!$sayfa) { $sonuc['kodlar'][] = 'OKUMA_YOK'; $sonuc['hata'] = 'Sayfa kayıtlı değil.'; return $sonuc; }
        $sonuc['sayfa'] = ['id' => (int)$sayfa['id'], 'ext' => $pageId, 'ad' => (string)$sayfa['ad']];

        $baglam = self::sayfaBaglami($db, self::configListesi($db), $pageId);
        if (!$baglam) { $sonuc['kodlar'][] = 'OKUMA_YOK'; $sonuc['hata'] = 'Token yok.'; return $sonuc; }
        $cfg = $baglam['cfg'];
        $sonuc['appId'] = (string)($cfg['AppId'] ?? '') ?: null;

        // Token'lar: Page Token (varsa) + System User
        $sayfaToken = trim((string)($sayfa['token'] ?? ''));
        $tokenlar   = [];
        if ($sayfaToken !== '') $tokenlar['sayfa'] = ['token' => $sayfaToken, 'cfg' => $cfg];
        if (trim((string)$cfg['SistemToken']) !== '' && $cfg['SistemToken'] !== $sayfaToken) {
            $tokenlar['sistem'] = ['token' => (string)$cfg['SistemToken'], 'cfg' => $cfg];
        }

        $formlar = $db->fetchAll("
            SELECT ReklamLeadFormlari_FormID AS fid, ReklamLeadFormlari_FormAdi AS ad
            FROM ReklamLeadFormlari
            WHERE ReklamLeadFormlari_Sayfa_id = ? AND Durum = 1 AND ReklamLeadFormlari_FormDurumu = 'ACTIVE'
              AND ReklamLeadFormlari_FormID NOT LIKE '%[^0-9]%'
            ORDER BY ReklamLeadFormlari_id
        ", [(int)$sayfa['id']]) ?: [];

        $params = ['fields' => 'id,created_time,field_data,form_id', 'limit' => 100, 'filtering' => json_encode([[
            'field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => time() - $saat * 3600,
        ]])];
        $esik = time() - $eksikDk * 60;
        $kisitVar = false; $okumaYok = false;

        foreach ($formlar as $f) {
            $satir = ['fid' => $f['fid'], 'ad' => $f['ad'], 'sonuc' => [], 'durum' => 'OK'];
            $enIyi = null; $enIyiBaglam = null;
            foreach ($tokenlar as $tur => $b) {
                $r    = self::graphGet(self::url($b, $f['fid'] . '/leads', $params), 60);
                $adet = $r['ok'] ? count($r['data']['data'] ?? []) : 0;
                $satir['sonuc'][$tur] = ['ok' => $r['ok'], 'adet' => $adet, 'fazla' => $r['ok'] && !empty($r['data']['paging']['next']), 'hata' => $r['ok'] ? null : self::hataMetni($r)];
                if ($r['ok'] && ($enIyi === null || $adet > count($enIyi['data']['data'] ?? []))) { $enIyi = $r; $enIyiBaglam = $b; }
            }
            $sy = $satir['sonuc']['sayfa'] ?? null; $si = $satir['sonuc']['sistem'] ?? null;

            if ($enIyi === null) {
                $satir['durum'] = 'OKUMA_YOK'; $okumaYok = true;
            } elseif ($sy && $si && $si['adet'] > 0 && (!$sy['ok'] || $sy['adet'] === 0)) {
                $satir['durum'] = 'KISIT'; $kisitVar = true;
            }

            // Panelde olması gereken ama olmayan lead'ler (kuru isle: kural / kara liste / pasif elenir)
            foreach (($enIyi['data']['data'] ?? []) as $lead) {
                $ts = strtotime((string)($lead['created_time'] ?? '')) ?: time();
                if ($ts > $esik) continue;
                $i = self::isle($db, $enIyiBaglam, (string)($lead['id'] ?? ''), (string)$f['fid'], ['lead' => $lead, 'kuru' => true]);
                if ($i['durum'] === 'eklenecek') {
                    $sonuc['eksik'][] = ['id' => (string)$lead['id'], 'tarih' => self::yerelTarih($lead['created_time'] ?? null), 'form' => $f['ad']];
                }
            }
            $sonuc['formlar'][] = $satir;
        }

        // Webhook aboneliği (Page Token gerekir)
        if (isset($tokenlar['sayfa'])) {
            $r = self::graphGet(self::url($tokenlar['sayfa'], $pageId . '/subscribed_apps', ['fields' => 'id,name,subscribed_fields']));
            if (!$r['ok']) {
                $sonuc['abonelikHata'] = self::hataMetni($r);
            } else {
                $sonuc['abonelik'] = false;
                foreach (($r['data']['data'] ?? []) as $app) {
                    if ($sonuc['appId'] && (string)($app['id'] ?? '') !== $sonuc['appId']) continue;
                    if (in_array('leadgen', $app['subscribed_fields'] ?? [], true)) { $sonuc['abonelik'] = true; break; }
                }
            }
        }

        // Kısıtta talimat için Page Token sahibi (Potansiyel Müşteri Erişimi'ne eklenecek kişi)
        if ($kisitVar && isset($tokenlar['sayfa'], $tokenlar['sistem'])) {
            $base = rtrim((string)$cfg['BaseURL'], '/');
            $d    = self::graphGet($base . '/debug_token?' . http_build_query(['input_token' => $sayfaToken, 'access_token' => $cfg['SistemToken']]));
            $uid  = (string)($d['data']['data']['user_id'] ?? '');
            if ($uid !== '') {
                $n = self::graphGet(self::url($tokenlar['sistem'], $uid, ['fields' => 'name']));
                $sonuc['tokenSahibi'] = (string)($n['data']['name'] ?? '') ?: "kullanıcı #$uid";
            }
        }

        if ($okumaYok)                     $sonuc['kodlar'][] = 'OKUMA_YOK';
        if ($sonuc['eksik'])               $sonuc['kodlar'][] = 'EKSIK';
        if ($kisitVar)                     $sonuc['kodlar'][] = 'KISIT';
        if ($sonuc['abonelik'] === false)  $sonuc['kodlar'][] = 'ABONELIK';
        return $sonuc;
    }
}
