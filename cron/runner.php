<?php
/**
 * runner.php - Cron Zamanlayıcı
 *
 * Plesk'e tek satır eklenir (her dakika çalışır):
 *   Cron: * * * * *
 *   URL : https://proje.ornekyazilim.com/cron/runner.php?key=<anahtar>
 *   Anahtar: tanim_site_ayarlari.site_ayarlari_cron_anahtari (Cron Yönetimi sayfasında görünür)
 *
 * CLI: php cron/runner.php
 */

date_default_timezone_set('Europe/Istanbul');

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/anahtar.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    cronAnahtarDogrula(Database::getInstance(), (string)($_GET['key'] ?? ''));
}

set_time_limit(600);
// Web'den tetiklendiğinde istemci bağlantısı koparsa görev yarıda kalmasın.
ignore_user_abort(true);

$db    = Database::getInstance();
$simdi = new DateTime();
$now   = date('Y-m-d H:i:s');
$dakikaBaslangic = (clone $simdi)->setTime((int)$simdi->format('H'), (int)$simdi->format('i'), 0);

echo "[{$now}] Runner başladı." . PHP_EOL;

$zamanlamalar = $db->fetchAll("
    SELECT
        z.CronZamanlamalar_Id,
        z.CronZamanlamalar_Ad,
        z.CronZamanlamalar_CronIfadesi,
        z.CronZamanlamalar_SabitParametreler,
        z.CronZamanlamalar_SonCalisma,
        z.CronZamanlamalar_TelafiDakika,
        g.CronGorevler_Id,
        g.CronGorevler_GorevKodu,
        g.CronGorevler_Ad AS GorevAd
    FROM CronZamanlamalar z
    INNER JOIN CronGorevler g ON z.CronZamanlamalar_GorevId = g.CronGorevler_Id
    WHERE z.Durum = 1
      AND g.Durum = 1
      AND z.CronZamanlamalar_BaslangicTarihi <= GETDATE()
      AND (z.CronZamanlamalar_BitisTarihi IS NULL OR z.CronZamanlamalar_BitisTarihi >= GETDATE())
");

if (!$zamanlamalar) {
    echo 'Aktif zamanlama bulunamadı.' . PHP_EOL;
    exit(0);
}

$calisacaklar = [];

foreach ($zamanlamalar as $z) {
    $sonZaman = $z['CronZamanlamalar_SonCalisma'] ? new DateTime($z['CronZamanlamalar_SonCalisma']) : null;

    if (cronEslesiyor($z['CronZamanlamalar_CronIfadesi'], $simdi)) {
        if ($sonZaman && $sonZaman >= $dakikaBaslangic) {
            echo "  Atlandı: [{$z['CronZamanlamalar_Ad']}] bu dakika zaten çalıştı." . PHP_EOL;
            continue;
        }
        $z['TelafiTuru'] = null;
        $calisacaklar[]  = $z;
        continue;
    }

    // ── Kaçan tur telafisi ───────────────────────────────────────────────────
    // Uzun süren bir görev turu sonraki dakikalara sarkarsa o dakikanın turu hiç
    // başlamaz ve zamanlama sessizce atlanır. Telafi açıksa son çalışmadan bu yana
    // kaçırılmış eşleşme aranır; bulunursa görev bir dakika gecikmeyle çalıştırılır.
    $telafi = (int)($z['CronZamanlamalar_TelafiDakika'] ?? 0);
    if ($telafi <= 0 || !$sonZaman) continue;

    // Geriye doğru taranır; ilk bulunan (en yeni) kaçan tur çalıştırılır, eskiler
    // atlanır. Çalıştıktan sonra SonCalisma güncellendiği için aynı tur tekrar bulunmaz.
    $t     = (clone $dakikaBaslangic)->modify('-1 minute');
    $sinir = (clone $dakikaBaslangic)->modify("-{$telafi} minute");

    while ($t >= $sinir && $t > $sonZaman) {
        if (cronEslesiyor($z['CronZamanlamalar_CronIfadesi'], $t)) {
            echo "  Telafi: [{$z['CronZamanlamalar_Ad']}] {$t->format('H:i')} turu kaçmış, şimdi çalıştırılıyor." . PHP_EOL;
            $z['TelafiTuru'] = $t->format('H:i');
            $calisacaklar[]  = $z;
            break;
        }
        $t->modify('-1 minute');
    }
}

if (!$calisacaklar) {
    echo 'Bu dakika için zamanlanmış görev yok.' . PHP_EOL;
    exit(0);
}

// Fatal/timeout ile ölen görev logu "çalışıyor" (0) durumunda asılı kalmasın.
$aktifLog = ['id' => null, 'bas' => null, 'ad' => null];
register_shutdown_function(function () use (&$aktifLog) {
    if (!$aktifLog['id']) return;
    $hata = error_get_last();
    $msg  = $hata && in_array($hata['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
        ? 'Fatal: ' . $hata['message']
        : 'Görev yarıda kesildi (zaman aşımı veya process sonlandırıldı).';
    try {
        cronLogBitir(Database::getInstance(), $aktifLog['id'], 2, $msg, $aktifLog['bas']);
    } catch (Throwable) {
        // shutdown sırasında DB de düşmüş olabilir; log kaybını sessiz geç.
    }
    echo PHP_EOL . "<<< [{$aktifLog['ad']}] KESİLDİ: {$msg}" . PHP_EOL;
});

foreach ($calisacaklar as $z) {
    $gorevId     = (int)$z['CronGorevler_Id'];
    $zamanlamaId = (int)$z['CronZamanlamalar_Id'];
    $gorevKodu   = $z['CronGorevler_GorevKodu'];
    $params      = json_decode($z['CronZamanlamalar_SabitParametreler'] ?? '{}', true) ?? [];
    $params      = dinamikParamCoz($params);

    // Çift çalışmayı önlemek için SonCalisma'yı hemen güncelle
    $db->update('CronZamanlamalar',
        ['CronZamanlamalar_SonCalisma' => date('Y-m-d H:i:s')],
        ['CronZamanlamalar_Id' => $zamanlamaId]
    );

    $logId    = cronLogOlustur($db, $gorevId, $zamanlamaId, $params, 1);
    $basZaman = microtime(true);
    $aktifLog = ['id' => $logId, 'bas' => $basZaman, 'ad' => $z['CronZamanlamalar_Ad']];
    // Süre sayacı her görev için baştan başlasın; önceki görevin harcadığı
    // saniyeler sonrakini zaman aşımına düşürmesin.
    set_time_limit(600);

    // Telafi ile çalışan görev panelde ayırt edilebilsin.
    $onek = $z['TelafiTuru'] ? "(telafi: {$z['TelafiTuru']} turu) " : '';

    echo PHP_EOL . ">>> [{$z['GorevAd']}] » [{$z['CronZamanlamalar_Ad']}] {$onek}başladı." . PHP_EOL;
    if ($params) echo '    Parametreler: ' . json_encode($params, JSON_UNESCAPED_UNICODE) . PHP_EOL;

    try {
        $sonuc = gorevCalistir($gorevKodu, $params, $db);
        cronLogBitir($db, $logId, $sonuc['durum'], $onek . $sonuc['sonuc'], $basZaman);
        $sure      = (int)(microtime(true) - $basZaman);
        $durumYazi = $sonuc['durum'] === 1 ? 'BAŞARILI' : 'HATA';
        if ($sonuc['cikti']) echo $sonuc['cikti'];
        echo "<<< [{$z['CronZamanlamalar_Ad']}] {$durumYazi} ({$sure}s): {$sonuc['sonuc']}" . PHP_EOL;
    } catch (Throwable $e) {
        $msg = $onek . get_class($e) . ': ' . $e->getMessage();
        cronLogBitir($db, $logId, 2, $msg, $basZaman);
        echo "<<< [{$z['CronZamanlamalar_Ad']}] HATA: {$msg}" . PHP_EOL;
    }

    // Log kapandı; shutdown handler bunu "kesildi" diye işaretlemesin.
    $aktifLog = ['id' => null, 'bas' => null, 'ad' => null];
}

echo PHP_EOL . '[' . date('Y-m-d H:i:s') . '] Runner tamamlandı.' . PHP_EOL;
