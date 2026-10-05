<?php
/**
 * anahtar.php - Cron web erişim anahtarı
 *
 * Anahtar kodda tutulmaz: dbo.tanim_site_ayarlari.site_ayarlari_cron_anahtari
 * DB'de boşsa web tetiklemesi tamamen kapalıdır (her istek reddedilir).
 */

function cronAnahtari($db): string
{
    $satir = $db->fetchOne("
        SELECT TOP 1 site_ayarlari_cron_anahtari
        FROM dbo.tanim_site_ayarlari
        ORDER BY site_ayarlari_id DESC
    ");
    return trim((string)($satir['site_ayarlari_cron_anahtari'] ?? ''));
}

/** Web isteğindeki ?key= değerini doğrular; geçersizse 403 ile çıkar. */
function cronAnahtarDogrula($db, string $verilen): void
{
    $anahtar = cronAnahtari($db);
    if ($anahtar === '' || !hash_equals($anahtar, $verilen)) {
        http_response_code(403);
        echo 'Yetkisiz erişim.' . PHP_EOL;
        exit(1);
    }
}
