<?php
/**
 * Satış (Başvuru Durumu TAMAM) ve kurulum (Süreç Tamamlandı) bilgisini reklam platformlarına bildirir.
 *
 * Kaynak tek: Basvurular. Her platformun kendi göndericisi vardır; gönderilen her olay
 * ReklamDonusumOlaylari'na (Basvurular_id + Platform + Asama tekil) yazılır, böylece aynı
 * olay aynı platforma iki kez gitmez, başarısızlar sonraki turda yeniden denenir.
 *
 * Platformlar:
 *  - Meta: Conversions API (CRM / dönüşüm lead'leri). Eşleşme yalnız Meta lead numarasıyla
 *    (Basvurular_LeadgenID); telefon/ad gönderilmez.
 *  - GoogleAds, Microsoft, TikTok: ileride gönderici eklenecek.
 *
 * Kullananlar: cron (meta_islemler, islem=donusum; eski kod reklam_donusum_bildir).
 */

class ReklamDonusumBildir
{
    public const SISTEM_KULLANICI = 1;

    /** Meta CAPI tek istekte en fazla 1000 olay kabul eder. */
    private const META_PAKET = 500;

    /** Meta sunucu olaylarında event_time en fazla 7 gün geriye gidebilir; 6 günde kesilir. */
    private const META_GERI_SN = 6 * 86400;

    /**
     * Aşama tanımları: Asama => [durum kolonu, varsayılan durum id listesi, tarih kolonu].
     * Durum id'leri görev parametresinden (CronGorevler) ezilebilir.
     */
    private const ASAMALAR = [
        'Satis'   => ['BasvuruDurum_ID',      '1,9',  'BasvuruDurum_KontrolTarihi'],
        'Kuruldu' => ['BasvuruSurecDurum_ID', '5,15', 'BasvuruSurecDurum_KontrolTarihi'],
    ];

    /**
     * Bildirilecek olayları bulur ve (kuru değilse) gönderir.
     *
     * @param array $p platform, veri_seti, gun, limit, satis_durumlari, kurulum_durumlari, test_kodu
     * @param bool  $kuru true: hiçbir şey gönderilmez/yazılmaz, yalnız sayım ve örnek döner
     * @return array ['sayac' => [...], 'ornek' => [...], 'hata' => ?string]
     */
    public static function calistir(Database $db, array $p, bool $kuru): array
    {
        $platform = trim((string)($p['platform'] ?? 'Meta'));
        if ($platform !== 'Meta') {
            return ['sayac' => [], 'ornek' => [], 'hata' => "Platform henüz desteklenmiyor: {$platform}"];
        }
        return self::meta($db, $p, $kuru);
    }

    // ─────────────────────────────── Meta ───────────────────────────────

    private static function meta(Database $db, array $p, bool $kuru): array
    {
        $veriSeti = preg_replace('/\D/', '', (string)($p['veri_seti'] ?? ''));
        if ($veriSeti === '') {
            return ['sayac' => [], 'ornek' => [], 'hata' => 'veri_seti parametresi boş (Meta veri seti kimliği).'];
        }
        $gun   = max(1, min(180, (int)($p['gun'] ?? 90)));
        $limit = max(1, min(5000, (int)($p['limit'] ?? 2000)));

        $kanal = null;
        if (!$kuru) {
            $kanal = self::metaKanal($db);
            if (!$kanal) {
                return ['sayac' => [], 'ornek' => [], 'hata' => 'Aktif Meta entegrasyon kanalı (token) bulunamadı.'];
            }
        }

        $satisDurumlari = self::idListesi((string)($p['satis_durumlari'] ?? self::ASAMALAR['Satis'][1]));

        $sayac = []; $ornek = []; $hata = null;
        foreach (self::ASAMALAR as $asama => [$durumKolon, $varsayilan, $tarihKolon]) {
            $param = $asama === 'Satis' ? 'satis_durumlari' : 'kurulum_durumlari';
            $durumlar = self::idListesi((string)($p[$param] ?? $varsayilan));
            if (!$durumlar || !$satisDurumlari) continue;

            $satirlar = self::metaAdaylar($db, $asama, $durumKolon, $durumlar, $satisDurumlari, $tarihKolon, $gun, $limit);
            $sayac[$asama] = ['aday' => count($satirlar), 'gonderildi' => 0, 'hata' => 0];
            if ($kuru) {
                $ornek[$asama] = array_slice($satirlar, 0, 5);
                continue;
            }

            foreach (array_chunk($satirlar, self::META_PAKET) as $paket) {
                $r = self::metaGonder($kanal, $veriSeti, $asama, $paket, (string)($p['test_kodu'] ?? ''));
                foreach ($paket as $s) {
                    self::kaydet($db, (int)$s['id'], 'Meta', $asama, 'LeadID', (string)$s['leadgen'], $veriSeti, $r['ok'], $r['yanit']);
                }
                $sayac[$asama][$r['ok'] ? 'gonderildi' : 'hata'] += count($paket);
                if (!$r['ok']) $hata = $r['yanit'];
            }
        }
        return ['sayac' => $sayac, 'ornek' => $ornek, 'hata' => $hata];
    }

    /** Meta lead numarası olan, aşamaya ulaşmış ve Meta'ya henüz başarıyla bildirilmemiş başvurular. */
    private static function metaAdaylar(Database $db, string $asama, string $durumKolon, array $durumlar,
                                        array $satisDurumlari, string $tarihKolon, int $gun, int $limit): array
    {
        $yer = implode(',', array_fill(0, count($durumlar), '?'));
        // Kurulum, satışın devamıdır: kurulum adayında satış durumu da aranır
        $satisSart = '';
        $satisParam = [];
        if ($asama === 'Kuruldu') {
            $satisSart  = ' AND b.BasvuruDurum_ID IN (' . implode(',', array_fill(0, count($satisDurumlari), '?')) . ')';
            $satisParam = $satisDurumlari;
        }

        return $db->fetchAll("
            SELECT TOP {$limit}
                   b.Basvurular_id         AS id,
                   b.Basvurular_LeadgenID  AS leadgen,
                   b.{$tarihKolon}         AS tarih
            FROM Basvurular b
            WHERE ISNULL(b.Basvurular_LeadgenID, '') <> ''
              AND b.Basvurular_LeadgenID NOT LIKE '%[^0-9]%'
              AND b.OlusturmaTarihi >= DATEADD(DAY, -?, GETDATE())
              AND b.{$durumKolon} IN ({$yer}){$satisSart}
              AND NOT EXISTS (
                  SELECT 1 FROM ReklamDonusumOlaylari o
                  WHERE o.Basvurular_id = b.Basvurular_id
                    AND o.ReklamDonusumOlaylari_Platform = 'Meta'
                    AND o.ReklamDonusumOlaylari_Asama = ?
                    AND o.ReklamDonusumOlaylari_Basarili = 1
              )
            ORDER BY b.Basvurular_id
        ", array_merge([$gun], $durumlar, $satisParam, [$asama]));
    }

    /** Tek CAPI isteği (paket). Yanıt metni tabloya yazılmak üzere kısaltılır. */
    private static function metaGonder(array $kanal, string $veriSeti, string $asama, array $paket, string $testKodu): array
    {
        $simdi = time();
        $olaylar = [];
        foreach ($paket as $s) {
            // Aşamanın gerçek zamanı biliniyorsa o; Meta 7 günden eskisini reddettiği için sınırda kesilir
            $zaman = $s['tarih'] ? strtotime((string)$s['tarih']) : $simdi;
            $zaman = min($simdi, max($simdi - self::META_GERI_SN, $zaman ?: $simdi));
            $olaylar[] = [
                'event_name'    => $asama,
                'event_time'    => $zaman,
                'action_source' => 'system_generated',
                'user_data'     => ['lead_id' => (int)$s['leadgen']],
                'custom_data'   => ['event_source' => 'crm', 'lead_event_source' => 'ORNEK CRM'],
            ];
        }

        $govde = [
            'data'            => json_encode($olaylar),
            'access_token'    => $kanal['Token'],
            'appsecret_proof' => hash_hmac('sha256', $kanal['Token'], $kanal['AppSecret']),
        ];
        if ($testKodu !== '') $govde['test_event_code'] = $testKodu;

        $ch = curl_init(rtrim($kanal['BaseURL'], '/') . '/' . $veriSeti . '/events');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($govde),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $cevap = (string)curl_exec($ch);
        $kod   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cHata = curl_error($ch);
        unset($ch);

        $json = json_decode($cevap, true) ?: [];
        $ok = $kod >= 200 && $kod < 300 && !$cHata && isset($json['events_received']);
        $yanit = $ok
            ? 'events_received=' . $json['events_received'] . (isset($json['fbtrace_id']) ? ' trace=' . $json['fbtrace_id'] : '')
            : ($cHata ?: ($json['error']['message'] ?? ('HTTP ' . $kod . ' ' . mb_substr($cevap, 0, 300))));
        return ['ok' => $ok, 'yanit' => mb_substr($yanit, 0, 1000)];
    }

    /** Aktif Meta kanalı (System User token). */
    private static function metaKanal(Database $db): ?array
    {
        return $db->fetchOne("
            SELECT TOP 1 k.EntegrasyonKanallari_Sifre AS Token,
                   e.Entegrasyonlar_BaseURL           AS BaseURL,
                   e.Entegrasyonlar_ApiKey            AS AppSecret
            FROM EntegrasyonKanallari k
            INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
            WHERE e.Entegrasyonlar_Tip = 'meta' AND k.Durum = 1 AND e.Durum = 1
              AND ISNULL(k.EntegrasyonKanallari_Sifre, '') <> ''
            ORDER BY k.EntegrasyonKanallari_id
        ") ?: null;
    }

    // ─────────────────────────────── Ortak ───────────────────────────────

    /** Olayı yazar; aynı başvuru+platform+aşama varsa (önceki başarısız deneme) günceller. */
    private static function kaydet(Database $db, int $basvuruId, string $platform, string $asama, string $eslesmeTuru,
                                   string $eslesmeDegeri, string $hedefId, bool $basarili, string $yanit): void
    {
        $db->execute("
            MERGE dbo.ReklamDonusumOlaylari AS h
            USING (SELECT ? AS Basvurular_id, ? AS Platform, ? AS Asama) AS k
               ON h.Basvurular_id = k.Basvurular_id
              AND h.ReklamDonusumOlaylari_Platform = k.Platform
              AND h.ReklamDonusumOlaylari_Asama = k.Asama
            WHEN MATCHED THEN UPDATE SET
                ReklamDonusumOlaylari_EslesmeTuru    = ?,
                ReklamDonusumOlaylari_EslesmeDegeri  = ?,
                ReklamDonusumOlaylari_HedefID        = ?,
                ReklamDonusumOlaylari_GonderimTarihi = GETDATE(),
                ReklamDonusumOlaylari_Basarili       = ?,
                ReklamDonusumOlaylari_Yanit          = ?,
                GuncelleyenKullanici                 = ?,
                GuncellemeTarihi                     = GETDATE()
            WHEN NOT MATCHED THEN INSERT
                (Basvurular_id, ReklamDonusumOlaylari_Platform, ReklamDonusumOlaylari_Asama,
                 ReklamDonusumOlaylari_EslesmeTuru, ReklamDonusumOlaylari_EslesmeDegeri, ReklamDonusumOlaylari_HedefID,
                 ReklamDonusumOlaylari_GonderimTarihi, ReklamDonusumOlaylari_Basarili, ReklamDonusumOlaylari_Yanit,
                 OlusturanKullanici, OlusturmaTarihi, Durum)
            VALUES (k.Basvurular_id, k.Platform, k.Asama, ?, ?, ?, GETDATE(), ?, ?, ?, GETDATE(), 1);
        ", [
            $basvuruId, $platform, $asama,
            $eslesmeTuru, $eslesmeDegeri, $hedefId, $basarili ? 1 : 0, $yanit, self::SISTEM_KULLANICI,
            $eslesmeTuru, $eslesmeDegeri, $hedefId, $basarili ? 1 : 0, $yanit, self::SISTEM_KULLANICI,
        ]);
    }

    /** "1, 9" → [1, 9] */
    private static function idListesi(string $metin): array
    {
        return array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', $metin)), fn($v) => $v > 0));
    }
}
