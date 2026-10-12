<?php
/**
 * SiteAyarHelper - Kuruluma (bayiye) özgü site ayarları
 *
 * Firma adı, kök adres, iletişim e-postası gibi değerler kodda tutulmaz:
 *   dbo.tanim_site_ayarlari (son kayıt)
 * Aynı kod farklı alan adı / DB ile çalıştırıldığında yalnız bu tablo değişir.
 * İstek başına tek sorgu yapılır; DB erişilemezse varsayılanlara düşülür.
 */
class SiteAyarHelper
{
    private static ?array $ayar = null;

    /** Tablodaki son ayar satırı (önbellekli) */
    public static function hepsi(): array
    {
        if (self::$ayar === null) {
            try {
                self::$ayar = Database::getInstance()->fetchOne("
                    SELECT TOP 1 *
                    FROM dbo.tanim_site_ayarlari
                    ORDER BY site_ayarlari_id DESC
                ") ?: [];
            } catch (Throwable $e) {
                self::$ayar = [];
            }
        }
        return self::$ayar;
    }

    /** Tek kolon değeri; boşsa $varsayilan */
    public static function deger(string $kolon, string $varsayilan = ''): string
    {
        $deger = trim((string)(self::hepsi()['site_ayarlari_' . $kolon] ?? ''));
        return $deger !== '' ? $deger : $varsayilan;
    }

    /**
     * Sitenin kök adresi (sonda / yok): https://alan.adi
     * DB'de şemasız ya da /admin ile girilmişse normalize edilir; boşsa istekteki host kullanılır.
     */
    public static function kokUrl(): string
    {
        $url = self::deger('site_url');
        if ($url === '') {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            return 'https://' . $host;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $url = rtrim($url, '/');
        return preg_replace('#/admin$#i', '', $url);
    }

    public static function firmaAdi(): string
    {
        return self::deger('firma_adi', self::deger('site_title', 'Firma'));
    }

    public static function iletisimEposta(): string
    {
        return self::deger('iletisim_eposta', self::deger('Admin_email'));
    }

    public static function callcenterLeadUrl(): string
    {
        return self::deger('callcenter_lead_url');
    }

    /** Meta OAuth dönüş adresi (Meta App ayarlarında da kayıtlı olmalı) */
    public static function metaRedirectUri(): string
    {
        return self::kokUrl() . '/oauth/meta-callback.php';
    }
}
