<?php
/**
 * Portal Banka API Yardımcısı
 *
 * Örnek Portal (portal.ornekyazilim.com/api/v1) banka hareketleri servisine salt okunur erişim.
 * BaseURL ve token, Entegrasyonlar tablosundan (Entegrasyonlar_Tip = 'banka') okunur;
 * kodda hiçbir kimlik bilgisi tutulmaz.
 *
 * Senkron mantığı iki modludur:
 *   - İlk kurulum : baslangic_tarih ile son N gün, onceki_id cursor'ı ile geriye gezinerek
 *   - Artımlı     : MAX(BankaHareketleri_PortalId) → sonraki_id ile artan sırada
 */
class PortalBankaHelper
{
    /** Entegrasyonlar_Tip değeri */
    private const TIP = 'banka';

    /** Sayfa başına kayıt (API üst sınırı 500) */
    private const SAYFA_LIMIT = 500;

    /** Tek çalıştırmada atılacak azami istek (saatlik 1000 limitine karşı emniyet) */
    private const MAX_ISTEK = 200;

    /** @var array|null Süreç içi entegrasyon önbelleği */
    private static $entegrasyon = null;

    // ─── Entegrasyon ──────────────────────────────────────────────────────────

    /**
     * Aktif banka entegrasyonunu döner.
     * @return array|null ['BaseURL' => ..., 'ApiKey' => ...]
     */
    public static function entegrasyonGetir($db)
    {
        if (self::$entegrasyon !== null) return self::$entegrasyon;

        $kayit = $db->fetchOne("
            SELECT TOP 1
                Entegrasyonlar_id     AS Id,
                Entegrasyonlar_BaseURL AS BaseURL,
                Entegrasyonlar_ApiKey  AS ApiKey
            FROM Entegrasyonlar
            WHERE Entegrasyonlar_Tip = ? AND Durum = 1
            ORDER BY Entegrasyonlar_id
        ", [self::TIP]);

        if (!$kayit || empty($kayit['BaseURL']) || empty($kayit['ApiKey'])) {
            return null;
        }

        $kayit['BaseURL']  = rtrim($kayit['BaseURL'], '/');
        self::$entegrasyon = $kayit;
        return $kayit;
    }

    /** Entegrasyon yapılandırılmış mı? */
    public static function hazirMi($db): bool
    {
        return self::entegrasyonGetir($db) !== null;
    }

    // ─── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * API'ye GET isteği atar, JSON gövdeyi diziye çevirir.
     *
     * @param string $yol   Örn. 'hareketler', 'hesaplar'
     * @param array  $query Sorgu parametreleri (boş değerler atılır)
     * @return array ['success'=>bool, 'data'=>array, 'meta'=>array, 'message'=>string, 'http'=>int, 'kalan_hak'=>?int]
     */
    public static function istek($db, string $yol, array $query = []): array
    {
        $ent = self::entegrasyonGetir($db);
        if (!$ent) {
            return self::hata('Aktif banka entegrasyonu bulunamadı (Entegrasyonlar_Tip = banka).');
        }

        $query = array_filter($query, fn($v) => $v !== null && $v !== '');
        $url   = $ent['BaseURL'] . '/' . ltrim($yol, '/');
        if ($query) $url .= '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $ent['ApiKey'],
                'Accept: application/json',
            ],
        ]);
        $yanit    = curl_exec($ch);
        $curlHata = curl_error($ch);
        $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $basBoyut = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($yanit === false) {
            return self::hata('Bağlantı hatası: ' . $curlHata);
        }

        $basliklar = substr($yanit, 0, $basBoyut);
        $govde     = substr($yanit, $basBoyut);
        $kalanHak  = null;
        if (preg_match('/X-RateLimit-Remaining:\s*(\d+)/i', $basliklar, $m)) {
            $kalanHak = (int)$m[1];
        }

        if ($http !== 200) {
            $coz  = json_decode($govde, true);
            $mesaj = $coz['message'] ?? $coz['mesaj'] ?? ('HTTP ' . $http);
            return self::hata($mesaj, $http, $kalanHak);
        }

        $coz = json_decode($govde, true);
        if (!is_array($coz)) {
            return self::hata('Geçersiz JSON yanıtı.', $http, $kalanHak);
        }
        if (empty($coz['success'])) {
            return self::hata($coz['message'] ?? 'API başarısız yanıt döndü.', $http, $kalanHak);
        }

        return [
            'success'   => true,
            'data'      => $coz['data'] ?? [],
            'meta'      => $coz['meta'] ?? [],
            'message'   => '',
            'http'      => $http,
            'kalan_hak' => $kalanHak,
        ];
    }

    private static function hata(string $mesaj, int $http = 0, ?int $kalanHak = null): array
    {
        return ['success' => false, 'data' => [], 'meta' => [], 'message' => $mesaj, 'http' => $http, 'kalan_hak' => $kalanHak];
    }

    // ─── Uçlar ────────────────────────────────────────────────────────────────

    /** Token'ın eriştiği firmalar */
    public static function firmalar($db): array { return self::istek($db, 'firmalar'); }

    /** Token'ın eriştiği bankalar */
    public static function bankalar($db): array { return self::istek($db, 'bankalar'); }

    /** Banka hesapları ve güncel bakiyeler */
    public static function hesaplar($db, array $filtre = []): array { return self::istek($db, 'hesaplar', $filtre); }

    /** Ham hareket listesi (tek sayfa) */
    public static function hareketler($db, array $filtre = []): array { return self::istek($db, 'hareketler', $filtre); }

    /**
     * Harekete ait dekont PDF'ini indirir.
     * @return array ['success'=>bool, 'icerik'=>string, 'dosya_adi'=>string, 'mime'=>string, 'message'=>string]
     */
    public static function dekontIndir($db, int $hareketId): array
    {
        $ent = self::entegrasyonGetir($db);
        if (!$ent) {
            return ['success' => false, 'icerik' => '', 'dosya_adi' => '', 'mime' => '', 'message' => 'Aktif banka entegrasyonu bulunamadı.'];
        }

        $ch = curl_init($ent['BaseURL'] . '/hareketler/' . $hareketId . '/dekont');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $ent['ApiKey']],
        ]);
        $yanit    = curl_exec($ch);
        $curlHata = curl_error($ch);
        $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $basBoyut = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $mime     = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($yanit === false) {
            return ['success' => false, 'icerik' => '', 'dosya_adi' => '', 'mime' => '', 'message' => 'Bağlantı hatası: ' . $curlHata];
        }

        $basliklar = substr($yanit, 0, $basBoyut);
        $govde     = substr($yanit, $basBoyut);

        if ($http === 403) {
            return ['success' => false, 'icerik' => '', 'dosya_adi' => '', 'mime' => '', 'message' => 'Dekont erişim yetkisi yok.'];
        }
        if ($http !== 200 || $govde === '') {
            $coz = json_decode($govde, true);
            return ['success' => false, 'icerik' => '', 'dosya_adi' => '', 'mime' => '', 'message' => $coz['message'] ?? ('Dekont alınamadı (HTTP ' . $http . ').')];
        }

        $dosyaAdi = 'dekont-' . $hareketId . '.pdf';
        if (preg_match('/filename="([^"]+)"/i', $basliklar, $m)) {
            $dosyaAdi = basename($m[1]);
        }

        return [
            'success'   => true,
            'icerik'    => $govde,
            'dosya_adi' => $dosyaAdi,
            'mime'      => $mime ?: 'application/pdf',
            'message'   => '',
        ];
    }

    // ─── Senkron ──────────────────────────────────────────────────────────────

    /**
     * Hareketleri BankaHareketleri tablosuna çeker.
     *
     * Tabloda hiç kayıt yoksa son $ilkGun günlük geçmiş (onceki_id ile geriye gezinerek),
     * varsa MAX(PortalId) sonrası (sonraki_id ile artan) çekilir.
     *
     * @param int $ilkGun İlk kurulumda kaç günlük geçmiş çekilecek
     * @return array ['success'=>bool, 'eklenen'=>int, 'guncellenen'=>int, 'istek'=>int, 'kalan_hak'=>?int, 'mod'=>string, 'message'=>string]
     */
    public static function hareketSenkron($db, int $ilkGun = 30, int $kullaniciId = 1): array
    {
        $sonuc = [
            'success' => false, 'eklenen' => 0, 'guncellenen' => 0,
            'istek' => 0, 'kalan_hak' => null, 'mod' => '', 'message' => '',
        ];

        if (!self::hazirMi($db)) {
            $sonuc['message'] = 'Aktif banka entegrasyonu bulunamadı (Entegrasyonlar_Tip = banka).';
            return $sonuc;
        }

        $sonId = (int)($db->fetchOne("SELECT MAX(BankaHareketleri_PortalId) AS m FROM BankaHareketleri")['m'] ?? 0);
        $artimli = $sonId > 0;
        $sonuc['mod'] = $artimli ? 'artimli' : 'ilk_kurulum';

        $filtre = ['limit' => self::SAYFA_LIMIT];
        if ($artimli) {
            $filtre['sonraki_id'] = $sonId;
        } else {
            $filtre['baslangic_tarih'] = date('Y-m-d', strtotime('-' . max(1, $ilkGun) . ' days'));
        }

        $istekSayisi = 0;
        while ($istekSayisi < self::MAX_ISTEK) {
            $yanit = self::hareketler($db, $filtre);
            $istekSayisi++;
            $sonuc['istek']     = $istekSayisi;
            $sonuc['kalan_hak'] = $yanit['kalan_hak'] ?? $sonuc['kalan_hak'];

            if (!$yanit['success']) {
                $sonuc['message'] = $yanit['message'];
                return $sonuc;
            }

            $kayitlar = $yanit['data'];
            if (!$kayitlar) break;

            foreach ($kayitlar as $h) {
                $yazim = self::hareketYaz($db, $h, $kullaniciId);
                if ($yazim === 'eklendi')      $sonuc['eklenen']++;
                elseif ($yazim === 'guncellendi') $sonuc['guncellenen']++;
            }

            $meta = $yanit['meta'] ?? [];
            if (empty($meta['devam_var'])) break;

            // Artımlı modda artan sırada ilerlenir (son kaydın id'si), ilk kurulumda geriye gezinilir.
            $cursor = $meta['sonraki_cursor'] ?? [];
            if ($artimli) {
                $ileri = $cursor['sonraki_id'] ?? end($kayitlar)['id'] ?? null;
                if (!$ileri || (int)$ileri <= (int)$filtre['sonraki_id']) break;
                $filtre['sonraki_id'] = (int)$ileri;
            } else {
                $geri = $cursor['onceki_id'] ?? end($kayitlar)['id'] ?? null;
                if (!$geri) break;
                if (isset($filtre['onceki_id']) && (int)$geri >= (int)$filtre['onceki_id']) break;
                $filtre['onceki_id'] = (int)$geri;
            }
        }

        $sonuc['success'] = true;
        $sonuc['message'] = sprintf(
            '%s: %d eklendi, %d güncellendi (%d istek).',
            $artimli ? 'Artımlı senkron' : 'İlk kurulum (' . $ilkGun . ' gün)',
            $sonuc['eklenen'], $sonuc['guncellenen'], $sonuc['istek']
        );
        return $sonuc;
    }

    /**
     * Tek hareketi PortalId anahtarıyla ekler veya günceller.
     * @return string 'eklendi' | 'guncellendi' | 'atlandi'
     */
    private static function hareketYaz($db, array $h, int $kullaniciId): string
    {
        $portalId = (int)($h['id'] ?? 0);
        if ($portalId <= 0) return 'atlandi';

        $simdi = date('Y-m-d H:i:s');
        $veri  = [
            'BankaHareketleri_PortalId'    => $portalId,
            'BankaHareketleri_HesapId'     => isset($h['hesap_id']) ? (int)$h['hesap_id'] : null,
            'BankaHareketleri_HesapNo'     => self::kes($h['hesap']['no'] ?? null, 60),
            'BankaHareketleri_Iban'        => self::kes($h['iban'] ?? null, 40),
            'BankaHareketleri_FirmaId'     => isset($h['firma']['id']) ? (int)$h['firma']['id'] : null,
            'BankaHareketleri_FirmaAdi'    => self::kes($h['firma']['ad'] ?? null, 150),
            'BankaHareketleri_BankaId'     => isset($h['banka']['id']) ? (int)$h['banka']['id'] : null,
            'BankaHareketleri_BankaAdi'    => self::kes($h['banka']['ad'] ?? null, 150),
            'BankaHareketleri_KarsiTaraf'  => self::kes($h['karsi_taraf'] ?? null, 255),
            'BankaHareketleri_VknTckn'     => self::kes($h['vkn_tckn'] ?? null, 20),
            'BankaHareketleri_IslemTarihi' => self::tarih($h['islem_tarihi'] ?? null),
            'BankaHareketleri_KayitTarihi' => self::tarih($h['kayit_tarihi'] ?? null),
            'BankaHareketleri_Tutar'       => (float)($h['tutar'] ?? 0),
            'BankaHareketleri_Tip'         => in_array($h['tip'] ?? '', ['A', 'B'], true) ? $h['tip'] : null,
            'BankaHareketleri_ParaBirimi'  => self::kes($h['para_birimi'] ?? null, 5),
            'BankaHareketleri_Aciklama'    => self::kes($h['aciklama'] ?? null, 1000),
            'BankaHareketleri_ReferansNo'  => self::kes($h['referans_no'] ?? null, 150),
            'BankaHareketleri_KalanBakiye' => isset($h['kalan_bakiye']) ? (float)$h['kalan_bakiye'] : null,
            'BankaHareketleri_MasrafMi'    => !empty($h['masraf_mi'])  ? 1 : 0,
            'BankaHareketleri_DekontVar'   => !empty($h['dekont_var']) ? 1 : 0,
            'GuncelleyenKullanici'         => $kullaniciId,
            'GuncellemeTarihi'             => $simdi,
        ];

        $mevcut = $db->fetchOne(
            "SELECT BankaHareketleri_id FROM BankaHareketleri WHERE BankaHareketleri_PortalId = ?",
            [$portalId]
        );

        if ($mevcut) {
            $db->update('BankaHareketleri', $veri, ['BankaHareketleri_id' => $mevcut['BankaHareketleri_id']]);
            return 'guncellendi';
        }

        $veri['Durum']              = 1;
        $veri['OlusturanKullanici'] = $kullaniciId;
        $veri['OlusturmaTarihi']    = $simdi;
        $db->insert('BankaHareketleri', $veri);
        return 'eklendi';
    }

    /** ISO tarihi MSSQL DATETIME biçimine çevirir */
    private static function tarih(?string $deger): ?string
    {
        if (!$deger) return null;
        $ts = strtotime($deger);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    /** Kolon uzunluğuna göre kırpar (çok baytlı karakterlere güvenli) */
    private static function kes($deger, int $uzunluk): ?string
    {
        if ($deger === null) return null;
        $deger = trim((string)$deger);
        if ($deger === '') return null;
        return mb_substr($deger, 0, $uzunluk);
    }
}
