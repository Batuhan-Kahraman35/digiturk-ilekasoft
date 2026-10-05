<?php
/**
 * Bildirim - Merkezi bildirim yönetimi
 * Tüm bildirimler dbo.Bildirimler tablosundan yönetilir.
 * Oluşturma + (opsiyonel) push gönderimi + listeleme + okundu işaretleme.
 *
 * Tasarım: abonelik != bildirim.
 *   dbo.BildirimAbonelikleri = cihaz push adresi (kullanıcı başına çok cihaz)
 *   dbo.Bildirimler          = mesajın kendisi
 */
require_once __DIR__ . '/PwaHelper.php';

class Bildirim
{
    /**
     * Yeni bildirim oluşturur; push=true ise kullanıcının cihazlarına push atar.
     *
     * @param array $veri {
     *   kullanici_id (int, hedef; null = genel/herkes),
     *   baslik (string), govde (string), url (string),
     *   tip (string: info|basari|uyari|hata), push (bool, varsayılan true),
     *   olusturan (int)
     * }
     * @return int Oluşan Bildirimler_id
     */
    public static function olustur(Database $db, array $veri): int
    {
        $pushGonder = array_key_exists('push', $veri) ? (int)(bool)$veri['push'] : 1;
        $olusturan  = (int)($veri['olusturan'] ?? ($veri['kullanici_id'] ?? 0));

        $bildirimId = $db->insert('dbo.Bildirimler', [
            'Bildirimler_KullaniciId'    => $veri['kullanici_id'] ?? null,
            'Bildirimler_Baslik'         => mb_substr($veri['baslik'] ?? '', 0, 200),
            'Bildirimler_Govde'          => mb_substr($veri['govde'] ?? '', 0, 1000),
            'Bildirimler_Url'            => mb_substr($veri['url'] ?? '/admin/', 0, 500),
            'Bildirimler_Tip'            => $veri['tip'] ?? 'info',
            'Bildirimler_Okundu'         => 0,
            'Bildirimler_PushGonder'     => $pushGonder,
            'Bildirimler_PushGonderildi' => 0,
            'OlusturanKullanici'         => $olusturan,
            'OlusturmaTarihi'            => date('Y-m-d H:i:s'),
            'Durum'                      => 1,
        ]);

        if ($pushGonder && !empty($veri['kullanici_id'])) {
            self::push($db, (int)$bildirimId);
        }
        return (int)$bildirimId;
    }

    /** Bildirimi ilgili kullanıcının cihazlarına push olarak gönderir */
    public static function push(Database $db, int $bildirimId): array
    {
        $b = $db->fetchOne("
            SELECT * FROM dbo.Bildirimler WHERE Bildirimler_id = ?
        ", [$bildirimId]);

        if (!$b || empty($b['Bildirimler_KullaniciId'])) {
            return ['gonderilen' => 0, 'basarisiz' => 0];
        }

        $sonuc = PwaHelper::kullaniciyaGonder($db, (int)$b['Bildirimler_KullaniciId'], [
            'baslik' => $b['Bildirimler_Baslik'],
            'govde'  => $b['Bildirimler_Govde'],
            'url'    => $b['Bildirimler_Url'],
            'tag'    => 'bildirim-' . $bildirimId,
        ]);

        if (($sonuc['gonderilen'] ?? 0) > 0) {
            $db->execute("
                UPDATE dbo.Bildirimler SET Bildirimler_PushGonderildi = 1
                WHERE Bildirimler_id = ?
            ", [$bildirimId]);
        }
        return $sonuc;
    }

    /**
     * Birden çok kullanıcıya aynı bildirimi oluşturur + push atar.
     * @return array ['bildirim'=>int oluşan kayıt, 'push'=>int push gönderilen cihaz]
     */
    public static function olusturCoklu(Database $db, array $kullaniciIdler, array $veri): array
    {
        $bildirimSayisi = 0;
        $pushToplam     = 0;
        $pushIstenen    = array_key_exists('push', $veri) ? (bool)$veri['push'] : true;

        foreach (array_unique(array_map('intval', $kullaniciIdler)) as $kid) {
            if ($kid <= 0) continue;
            $veri['kullanici_id'] = $kid;
            $veri['push'] = false; // push'u aşağıda tek sefer tetikle
            $bid = self::olustur($db, $veri);
            $bildirimSayisi++;
            if ($pushIstenen) {
                $r = self::push($db, $bid);
                $pushToplam += ($r['gonderilen'] ?? 0);
            }
        }
        return ['bildirim' => $bildirimSayisi, 'push' => $pushToplam];
    }

    /**
     * Genel duyuru: tek broadcast kaydı (KullaniciId NULL, herkesin çanında görünür)
     * + istenirse tüm cihazlara push.
     *
     * BİLİNEN KISIT: Bildirimler_Okundu tek bit olduğu için genel duyuruyu bir kullanıcı
     * okuduğunda herkeste okundu görünür. Kullanıcı bazlı okundu takibi gerekirse
     * ayrı bir BildirimOkumalari tablosu gerekir.
     */
    public static function genelDuyuru(Database $db, array $veri): array
    {
        $pushIstenen = array_key_exists('push', $veri) ? (bool)$veri['push'] : true;
        $veri['kullanici_id'] = null;
        $veri['push'] = false;
        $bid = self::olustur($db, $veri);

        $push = ['gonderilen' => 0];
        if ($pushIstenen) {
            $push = PwaHelper::tumCihazlaraGonder($db, [
                'baslik' => $veri['baslik'] ?? '',
                'govde'  => $veri['govde'] ?? '',
                'url'    => $veri['url'] ?? '/admin/',
                'tag'    => 'bildirim-' . $bid,
            ]);
            if (($push['gonderilen'] ?? 0) > 0) {
                $db->execute("
                    UPDATE dbo.Bildirimler SET Bildirimler_PushGonderildi = 1
                    WHERE Bildirimler_id = ?
                ", [$bid]);
            }
        }
        return ['bildirim_id' => $bid, 'push' => $push['gonderilen'] ?? 0];
    }

    /** Kullanıcının bildirimlerini listeler (kendine ait + genel) */
    public static function listele(Database $db, int $kullaniciId, int $limit = 20): array
    {
        return $db->fetchAll("
            SELECT TOP (?) Bildirimler_id, Bildirimler_Baslik, Bildirimler_Govde,
                   Bildirimler_Url, Bildirimler_Tip, Bildirimler_Okundu,
                   CONVERT(VARCHAR(19), OlusturmaTarihi, 120) AS olusturma_tarihi,
                   DATEDIFF(MINUTE, OlusturmaTarihi, GETDATE()) AS dakika_once
            FROM dbo.Bildirimler
            WHERE Durum = 1
              AND (Bildirimler_KullaniciId = ? OR Bildirimler_KullaniciId IS NULL)
            ORDER BY Bildirimler_id DESC
        ", [$limit, $kullaniciId]);
    }

    /** Okunmamış bildirim sayısı */
    public static function okunmamisSayisi(Database $db, int $kullaniciId): int
    {
        $r = $db->fetchOne("
            SELECT COUNT(*) AS sayi FROM dbo.Bildirimler
            WHERE Durum = 1 AND Bildirimler_Okundu = 0
              AND (Bildirimler_KullaniciId = ? OR Bildirimler_KullaniciId IS NULL)
        ", [$kullaniciId]);
        return (int)($r['sayi'] ?? 0);
    }

    /** Bir bildirimi (veya tümünü) okundu işaretle */
    public static function okunduYap(Database $db, int $kullaniciId, ?int $bildirimId = null): void
    {
        if ($bildirimId) {
            $db->execute("
                UPDATE dbo.Bildirimler
                SET Bildirimler_Okundu = 1, Bildirimler_OkunmaTarihi = GETDATE(),
                    GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                WHERE Bildirimler_id = ?
                  AND (Bildirimler_KullaniciId = ? OR Bildirimler_KullaniciId IS NULL)
            ", [$kullaniciId, $bildirimId, $kullaniciId]);
        } else {
            $db->execute("
                UPDATE dbo.Bildirimler
                SET Bildirimler_Okundu = 1, Bildirimler_OkunmaTarihi = GETDATE(),
                    GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                WHERE Bildirimler_Okundu = 0
                  AND (Bildirimler_KullaniciId = ? OR Bildirimler_KullaniciId IS NULL)
            ", [$kullaniciId, $kullaniciId]);
        }
    }
}
