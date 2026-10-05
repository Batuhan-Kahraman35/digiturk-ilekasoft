<?php
/**
 * Fikstür Kazıma Yardımcısı
 *
 * haberciniz.biz lig gösterim servisinden (league_standing_table.php) cari hafta
 * fikstürünü çeker ve SporFikstur tablosuna upsert eder.
 *
 * Kaynak yalnız CARİ HAFTAYI döner; geçmiş haftalar tabloda birikerek oluşur.
 * Bu yüzden kayıtlar SporFikstur_Anahtar (lig|hafta|ev|deplasman) üzerinden
 * tekilleştirilir: aynı maç tekrar geldiğinde skor/durum güncellenir, yeni satır
 * açılmaz.
 */
class FiksturHelper
{
    /** Kaynak servis adresi (301 yönlendirmesi var, CURLOPT_FOLLOWLOCATION zorunlu) */
    private const KAYNAK_URL = 'https://www.haberciniz.biz/service/sport/league_standing_table.php';

    /** Kaynak User-Agent istemeyen isteklere yanıt vermiyor */
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    private const ZAMAN_ASIMI = 30;

    /**
     * Kazınacak ligler. Kaynak HTML lig ADINI vermiyor (yalnız sekme indeksi),
     * bu yüzden ad eşlemesi burada tutulur. Sıra = kaynaktaki tab_N sırası.
     */
    private const LIGLER = [
        'TUR1' => 'Süper Lig',
        'TUR2' => 'TFF 1. Lig',
        'SPA1' => 'La Liga',
        'ENG1' => 'Premier Lig',
        'GER1' => 'Bundesliga',
    ];

    // ─── Dış arayüz ───────────────────────────────────────────────────────────

    /** Kazınacak lig kodu → ad listesi */
    public static function ligler(): array
    {
        return self::LIGLER;
    }

    /**
     * Tüm ligleri kazıyıp tabloya yazar.
     *
     * @param  int|null $kullanici İşlemi tetikleyen personel (cron'da null)
     * @return array ['success'=>bool,'message'=>string,'eklenen'=>int,'guncellenen'=>int,'toplam'=>int,'ligler'=>array]
     */
    public static function senkron($db, ?int $kullanici = null): array
    {
        try {
            $html = self::kaynagiCek(array_keys(self::LIGLER));
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Kaynak alınamadı: ' . $e->getMessage(),
                'eklenen' => 0, 'guncellenen' => 0, 'toplam' => 0, 'ligler' => [],
            ];
        }

        $maclar = self::ayristir($html);

        if (!$maclar) {
            return [
                'success' => false,
                'message' => 'Kaynak alındı fakat hiç maç ayrıştırılamadı (sayfa yapısı değişmiş olabilir).',
                'eklenen' => 0, 'guncellenen' => 0, 'toplam' => 0, 'ligler' => [],
            ];
        }

        $eklenen = 0; $guncellenen = 0; $ligOzet = [];

        foreach ($maclar as $mac) {
            $sonuc = self::kaydet($db, $mac, $kullanici);
            if ($sonuc === 'eklendi')      $eklenen++;
            elseif ($sonuc === 'guncellendi') $guncellenen++;

            $kod = $mac['lig_kodu'];
            $ligOzet[$kod] = ($ligOzet[$kod] ?? 0) + 1;
        }

        return [
            'success'     => true,
            'message'     => sprintf('%d maç işlendi (%d yeni, %d güncel).', count($maclar), $eklenen, $guncellenen),
            'eklenen'     => $eklenen,
            'guncellenen' => $guncellenen,
            'toplam'      => count($maclar),
            'ligler'      => $ligOzet,
        ];
    }

    // ─── Kaynak ───────────────────────────────────────────────────────────────

    /** Servisten ham HTML çeker */
    private static function kaynagiCek(array $ligKodlari): string
    {
        $url = self::KAYNAK_URL . '?' . http_build_query([
            'color'  => 1,
            'select' => implode(',', $ligKodlari),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => self::ZAMAN_ASIMI,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING       => '',
        ]);

        $govde = curl_exec($ch);
        $hata  = curl_error($ch);
        $kod   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($govde === false)  throw new RuntimeException($hata ?: 'cURL hatası');
        if ($kod !== 200)      throw new RuntimeException('HTTP ' . $kod);
        if (trim($govde) === '') throw new RuntimeException('Boş yanıt');

        return $govde;
    }

    // ─── Ayrıştırma ───────────────────────────────────────────────────────────

    /**
     * HTML'den maç satırlarını çıkarır.
     * Her lig <div class="tab_N lig_table_N"> bloğunda, fikstür ise o bloğun
     * <div class="fixture"> altında yer alır.
     *
     * @return array<int,array>
     */
    public static function ayristir(string $html): array
    {
        $ligKodlari = array_keys(self::LIGLER);

        $onceki = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($onceki);

        $xpath = new DOMXPath($dom);
        $maclar = [];

        foreach ($xpath->query('//div[starts-with(@class, "tab_")]') as $blok) {
            if (!$blok instanceof DOMElement) continue;
            if (!preg_match('/\btab_(\d+)\b/', $blok->getAttribute('class'), $m)) continue;
            $index = (int)$m[1];

            $ligKodu = $ligKodlari[$index] ?? null;
            if ($ligKodu === null) continue;

            $fixture = $xpath->query('.//div[contains(@class, "fixture")][not(contains(@class, "fixture-table"))]', $blok)->item(0);
            if (!$fixture) continue;

            $haftaDugum = $xpath->query('.//div[contains(@class, "header_1")]', $fixture)->item(0);
            $haftaMetin = $haftaDugum ? self::metin($haftaDugum) : '';
            $hafta      = preg_match('/(\d+)/', $haftaMetin, $h) ? (int)$h[1] : 0;

            foreach ($xpath->query('.//div[@class="fixture-table"]/div', $fixture) as $satir) {
                $spanlar = $xpath->query('./span', $satir);
                if ($spanlar->length < 3) continue;

                $mac = self::satirCoz(
                    self::metin($spanlar->item(0)),
                    self::metin($spanlar->item(1)),
                    self::metin($spanlar->item(2))
                );
                if ($mac === null) continue;

                $mac['lig_kodu']    = $ligKodu;
                $mac['lig_adi']     = self::LIGLER[$ligKodu];
                $mac['hafta']       = $hafta;
                $mac['hafta_metin'] = $haftaMetin;
                $mac['anahtar']     = self::anahtar($ligKodu, $hafta, $mac['ev'], $mac['deplasman']);

                $maclar[] = $mac;
            }
        }

        return $maclar;
    }

    /**
     * Tek fikstür satırını çözer.
     * Tarih kolonu: "22.08.2025 MS" (bitti) · "30.08.2026 83" (devam eden dakika)
     *               "31.08.2026 21:30" (başlamamış, saat)
     */
    private static function satirCoz(string $tarihMetin, string $macMetin, string $skorMetin): ?array
    {
        // Takımlar " - " ile ayrılır; takım adında tek tire olabildiği için ayraç boşluklu aranır
        $parcalar = preg_split('/\s+-\s+/u', $macMetin, 2);
        if (count($parcalar) !== 2) return null;

        $ev        = trim($parcalar[0]);
        $deplasman = trim($parcalar[1]);
        if ($ev === '' || $deplasman === '') return null;

        // Tarih + ek bilgi
        $tarih = null; $ek = '';
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s*(.*)$/u', trim($tarihMetin), $t)) {
            $tarih = sprintf('%04d-%02d-%02d', $t[3], $t[2], $t[1]);
            $ek    = trim($t[4]);
        }

        // Skor
        $evGol = null; $depGol = null;
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/u', trim($skorMetin), $s)) {
            $evGol  = (int)$s[1];
            $depGol = (int)$s[2];
        }

        // Durum + saat
        $saat = null;
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $ek, $sa)) {
            $saat  = sprintf('%02d:%02d:00', $sa[1], $sa[2]);
            $durum = 'bekliyor';
        } elseif (preg_match('/^\d{1,3}$/', $ek)) {
            $durum = 'devam';                       // sahadaki dakika
        } elseif ($ek !== '') {
            $durum = 'bitti';                       // MS, İY vb.
        } else {
            $durum = $evGol !== null ? 'bitti' : 'bekliyor';
        }
        if ($evGol === null && $durum === 'bitti') $durum = 'bekliyor';

        $macTarihi = $tarih === null ? null : $tarih . ' ' . ($saat ?? '00:00:00');

        return [
            'tarih_metin' => trim($tarihMetin),
            'mac_tarihi'  => $macTarihi,
            'ev'          => $ev,
            'deplasman'   => $deplasman,
            'ev_gol'      => $evGol,
            'dep_gol'     => $depGol,
            'durum_kodu'  => $durum,
            'durum_metin' => $ek,
        ];
    }

    /** Tekilleştirme anahtarı: lig|hafta|ev|deplasman */
    private static function anahtar(string $ligKodu, int $hafta, string $ev, string $deplasman): string
    {
        $sadelestir = static function (string $s): string {
            $s = mb_strtolower(trim($s), 'UTF-8');
            return preg_replace('/\s+/u', ' ', $s);
        };
        return $ligKodu . '|' . $hafta . '|' . $sadelestir($ev) . '|' . $sadelestir($deplasman);
    }

    /** Düğüm metnini sadeleştirir */
    private static function metin(?DOMNode $dugum): string
    {
        if (!$dugum) return '';
        $metin = html_entity_decode($dugum->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $metin));
    }

    // ─── Takımlar ─────────────────────────────────────────────────────────────

    /** @var array<string,int> Süreç içi takım önbelleği (resmi ad → id) */
    private static array $takimCache = [];

    /**
     * Takımı resmi adına göre bulur, yoksa oluşturur.
     * Kısa ad ve logo panelden girilir; kazıma bu alanlara ASLA dokunmaz.
     */
    private static function takimId($db, string $resmiAd, string $ligKodu, ?int $kullanici): ?int
    {
        $resmiAd = trim($resmiAd);
        if ($resmiAd === '') return null;

        if (isset(self::$takimCache[$resmiAd])) return self::$takimCache[$resmiAd];

        $kayit = $db->fetchOne(
            "SELECT TOP 1 SporTakimlar_Id FROM SporTakimlar WHERE SporTakimlar_ResmiAd = ?",
            [$resmiAd]
        );

        if ($kayit) {
            return self::$takimCache[$resmiAd] = (int)$kayit['SporTakimlar_Id'];
        }

        $simdi = date('Y-m-d H:i:s');
        $id = (int)$db->insert('SporTakimlar', [
            'SporTakimlar_ResmiAd' => $resmiAd,
            'SporTakimlar_LigKodu' => $ligKodu,
            'OlusturanKullanici'   => $kullanici,
            'OlusturmaTarihi'      => $simdi,
            'Durum'                => 1,
        ]);

        return self::$takimCache[$resmiAd] = $id;
    }

    // ─── Kayıt ────────────────────────────────────────────────────────────────

    /**
     * Maçı anahtara göre ekler veya günceller.
     * @return string 'eklendi' | 'guncellendi' | 'degismedi'
     */
    private static function kaydet($db, array $mac, ?int $kullanici): string
    {
        $simdi = date('Y-m-d H:i:s');

        $evTakimId  = self::takimId($db, $mac['ev'],        $mac['lig_kodu'], $kullanici);
        $depTakimId = self::takimId($db, $mac['deplasman'], $mac['lig_kodu'], $kullanici);

        $mevcut = $db->fetchOne("
            SELECT TOP 1 SporFikstur_Id, SporFikstur_EvGol, SporFikstur_DeplasmanGol,
                   SporFikstur_DurumKodu, SporFikstur_DurumMetin, SporFikstur_MacTarihi,
                   SporFikstur_EvTakimId, SporFikstur_DeplasmanTakimId
            FROM SporFikstur
            WHERE SporFikstur_Anahtar = ?
        ", [$mac['anahtar']]);

        if (!$mevcut) {
            $db->insert('SporFikstur', [
                'SporFikstur_LigKodu'          => $mac['lig_kodu'],
                'SporFikstur_LigAdi'           => $mac['lig_adi'],
                'SporFikstur_Hafta'            => $mac['hafta'],
                'SporFikstur_HaftaMetin'       => $mac['hafta_metin'],
                'SporFikstur_MacTarihi'        => $mac['mac_tarihi'],
                'SporFikstur_TarihMetin'       => $mac['tarih_metin'],
                'SporFikstur_EvSahibi'         => $mac['ev'],
                'SporFikstur_Deplasman'        => $mac['deplasman'],
                'SporFikstur_EvTakimId'        => $evTakimId,
                'SporFikstur_DeplasmanTakimId' => $depTakimId,
                'SporFikstur_EvGol'            => $mac['ev_gol'],
                'SporFikstur_DeplasmanGol'     => $mac['dep_gol'],
                'SporFikstur_DurumKodu'        => $mac['durum_kodu'],
                'SporFikstur_DurumMetin'       => $mac['durum_metin'],
                'SporFikstur_Anahtar'          => $mac['anahtar'],
                'SporFikstur_SonKazimaTarihi'  => $simdi,
                'OlusturanKullanici'           => $kullanici,
                'OlusturmaTarihi'              => $simdi,
                'Durum'                        => 1,
            ]);
            return 'eklendi';
        }

        $degisti = (string)$mevcut['SporFikstur_EvGol']        !== (string)$mac['ev_gol']
                || (string)$mevcut['SporFikstur_DeplasmanGol'] !== (string)$mac['dep_gol']
                || (string)$mevcut['SporFikstur_DurumKodu']    !== (string)$mac['durum_kodu']
                || (string)$mevcut['SporFikstur_DurumMetin']   !== (string)$mac['durum_metin'];

        if (!$degisti) {
            // Yalnız kazıma damgasını tazele; takım bağı eksikse (eski kayıt) tamamla
            $db->execute("
                UPDATE SporFikstur
                SET SporFikstur_SonKazimaTarihi  = ?,
                    SporFikstur_EvTakimId        = ISNULL(SporFikstur_EvTakimId, ?),
                    SporFikstur_DeplasmanTakimId = ISNULL(SporFikstur_DeplasmanTakimId, ?)
                WHERE SporFikstur_Id = ?
            ", [$simdi, $evTakimId, $depTakimId, $mevcut['SporFikstur_Id']]);
            return 'degismedi';
        }

        $db->update('SporFikstur', [
            'SporFikstur_MacTarihi'        => $mac['mac_tarihi'],
            'SporFikstur_TarihMetin'       => $mac['tarih_metin'],
            'SporFikstur_EvTakimId'        => $evTakimId,
            'SporFikstur_DeplasmanTakimId' => $depTakimId,
            'SporFikstur_EvGol'           => $mac['ev_gol'],
            'SporFikstur_DeplasmanGol'    => $mac['dep_gol'],
            'SporFikstur_DurumKodu'       => $mac['durum_kodu'],
            'SporFikstur_DurumMetin'      => $mac['durum_metin'],
            'SporFikstur_SonKazimaTarihi' => $simdi,
            'GuncelleyenKullanici'        => $kullanici,
            'GuncellemeTarihi'            => $simdi,
        ], ['SporFikstur_Id' => $mevcut['SporFikstur_Id']]);

        return 'guncellendi';
    }
}
