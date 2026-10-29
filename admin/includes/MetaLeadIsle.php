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
                   k.EntegrasyonKanallari_Sifre        AS SistemToken
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

        $token = trim((string)($sayfa['ReklamFacebookSayfalari_Token'] ?? '')) ?: (string)$cfg['SistemToken'];
        return $token === '' ? null : ['token' => $token, 'cfg' => $cfg];
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
     *
     * Dönüş: ['durum' => eklendi|mevcut|tokenyok|graph|pasif|kural|karaliste|hata, 'mesaj' => string]
     */
    public static function isle(Database $db, array $baglam, string $leadgenId, string $formId = '', array $sec = []): array
    {
        if ($leadgenId === '') return ['durum' => 'hata', 'mesaj' => 'leadgen_id bos'];

        // Tekrar → atla (idempotent: Basvurular_LeadgenID)
        if ($db->fetchOne("SELECT Basvurular_id FROM Basvurular WHERE Basvurular_LeadgenID = ?", [$leadgenId])) {
            return ['durum' => 'mevcut', 'mesaj' => "MEVCUT lead=$leadgenId"];
        }

        // Lead detayı
        $lead = $sec['lead'] ?? null;
        if (!$lead) {
            $r = self::graphGet(self::url($baglam, $leadgenId, ['fields' => 'field_data,form_id,created_time']));
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
        $notlar = [];
        if ($formFk) {
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
        $engel = ($sec['karaListeLog'] ?? true)
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

        $params = ['fields' => 'id,created_time,field_data,form_id', 'limit' => $limit];
        if ($after) $params['after'] = $after;
        if ($baslangicTs) {
            $params['filtering'] = json_encode([[
                'field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $baslangicTs,
            ]]);
        }

        $r = self::graphGet(self::url($baglam, $form['FormID'] . '/leads', $params), 60);
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
        return ['ok' => true, 'hata' => null, 'sonraki' => $sonraki, 'sayac' => $sayac];
    }
}
