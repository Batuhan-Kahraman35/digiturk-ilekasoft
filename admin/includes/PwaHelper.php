<?php
/**
 * PwaHelper - PWA push için ortak yardımcılar
 *
 * VAPID anahtarları entegrasyon yapısında tutulur (kod içinde hardcode YOK):
 *   Entegrasyonlar        -> Entegrasyonlar_Tip = 'webpush'
 *   EntegrasyonKanallari  -> EntegrasyonKanallari_KanalAdi = 'vapid'
 *                            EntegrasyonKanallari_Ayarlar  = JSON {public, private(PEM), subject}
 */
class PwaHelper
{
    /** VAPID yapılandırmasını entegrasyon kanalından (webpush/vapid) döner; eksikse null */
    public static function vapid(Database $db): ?array
    {
        $kanal = $db->fetchOne("
            SELECT k.EntegrasyonKanallari_Ayarlar
            FROM dbo.EntegrasyonKanallari k
            INNER JOIN dbo.Entegrasyonlar e
                    ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
            WHERE e.Entegrasyonlar_Tip = 'webpush'
              AND k.EntegrasyonKanallari_KanalAdi = 'vapid'
              AND e.Durum = 1 AND k.Durum = 1
        ");
        if (!$kanal || empty($kanal['EntegrasyonKanallari_Ayarlar'])) {
            return null;
        }
        $ayar = json_decode($kanal['EntegrasyonKanallari_Ayarlar'], true) ?: [];
        if (empty($ayar['public']) || empty($ayar['private'])) {
            return null;
        }
        return [
            'public'  => $ayar['public'],
            'private' => $ayar['private'],
            'subject' => $ayar['subject'] ?? 'mailto:destek@ornekyazilim.com',
        ];
    }

    /**
     * Bir kullanıcının aktif cihazlarına push gönderir.
     * Ölü abonelikleri (404/410) otomatik pasif yapar.
     * @return array ['gonderilen'=>int, 'basarisiz'=>int]
     */
    public static function kullaniciyaGonder(Database $db, int $kullaniciId, array $bildirim): array
    {
        require_once __DIR__ . '/WebPush.php';
        $vapid = self::vapid($db);
        if (!$vapid) return ['gonderilen' => 0, 'basarisiz' => 0, 'hata' => 'VAPID ayarlı değil'];

        $aboneler = $db->fetchAll("
            SELECT BildirimAbonelikleri_id, BildirimAbonelikleri_Endpoint,
                   BildirimAbonelikleri_P256dh, BildirimAbonelikleri_Auth
            FROM dbo.BildirimAbonelikleri
            WHERE BildirimAbonelikleri_KullaniciId = ? AND Durum = 1
        ", [$kullaniciId]);

        return self::aboneleregonder($db, $aboneler, $bildirim, $vapid);
    }

    /** Sistemdeki tüm aktif cihazlara push gönderir (genel duyuru) */
    public static function tumCihazlaraGonder(Database $db, array $bildirim): array
    {
        require_once __DIR__ . '/WebPush.php';
        $vapid = self::vapid($db);
        if (!$vapid) return ['gonderilen' => 0, 'basarisiz' => 0, 'hata' => 'VAPID ayarlı değil'];

        $aboneler = $db->fetchAll("
            SELECT BildirimAbonelikleri_id, BildirimAbonelikleri_Endpoint,
                   BildirimAbonelikleri_P256dh, BildirimAbonelikleri_Auth
            FROM dbo.BildirimAbonelikleri
            WHERE Durum = 1
        ");
        return self::aboneleregonder($db, $aboneler, $bildirim, $vapid);
    }

    /** Verilen abonelik listesine push gönderir; ölüleri pasifler */
    private static function aboneleregonder(Database $db, array $aboneler, array $bildirim, array $vapid): array
    {
        $gonderilen = 0;
        $basarisiz  = 0;
        $payload = json_encode($bildirim, JSON_UNESCAPED_UNICODE);

        foreach ($aboneler as $a) {
            try {
                $sonuc = WebPush::gonder(
                    $a['BildirimAbonelikleri_Endpoint'],
                    $a['BildirimAbonelikleri_P256dh'],
                    $a['BildirimAbonelikleri_Auth'],
                    $payload,
                    $vapid
                );
            } catch (Exception $e) {
                // Tek bir bozuk abonelik tüm gönderimi durdurmasın
                $basarisiz++;
                error_log('WebPush hatasi (abonelik ' . $a['BildirimAbonelikleri_id'] . '): ' . $e->getMessage());
                continue;
            }

            if ($sonuc['kod'] >= 200 && $sonuc['kod'] < 300) {
                $gonderilen++;
            } else {
                $basarisiz++;
                // 404/410 = abonelik artık geçersiz (tarayıcı/cihaz kaldırmış)
                if (in_array($sonuc['kod'], [404, 410], true)) {
                    $db->execute("
                        UPDATE dbo.BildirimAbonelikleri
                        SET Durum = 0, GuncellemeTarihi = GETDATE()
                        WHERE BildirimAbonelikleri_id = ?
                    ", [$a['BildirimAbonelikleri_id']]);
                }
            }
        }
        return ['gonderilen' => $gonderilen, 'basarisiz' => $basarisiz];
    }
}
