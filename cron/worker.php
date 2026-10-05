<?php
/**
 * worker.php - Manuel Tetikleme / Doğrudan Çalıştırma
 *
 * Zamanlayıcı ile (kayıtlı parametreler):
 *   https://domain.com/cron/worker.php?zamanlama=ID&key=KEY
 *
 * Görev koduyla (özel parametreler):
 *   https://domain.com/cron/worker.php?gorev=KODU&key=KEY[&param=deger]
 *
 * CLI:
 *   php cron/worker.php zamanlama=ID
 *   php cron/worker.php gorev=KODU [param=deger]
 */

date_default_timezone_set('Europe/Istanbul');

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/anahtar.php';

$isCli = PHP_SAPI === 'cli';

if ($isCli) {
    parse_str(implode('&', array_slice($argv ?? [], 1)), $input);
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $input = $_GET;
    cronAnahtarDogrula(Database::getInstance(), (string)($input['key'] ?? ''));
}

$db = Database::getInstance();

// ─── Zamanlayıcı ile çalıştırma ──────────────────────────────────────────────
if (!empty($input['zamanlama'])) {
    $zamanlamaId = (int)$input['zamanlama'];
    $zamanlama   = $db->fetchOne("
        SELECT z.*, g.CronGorevler_GorevKodu, g.CronGorevler_Ad AS GorevAd, g.CronGorevler_Id AS GorevId
        FROM CronZamanlamalar z
        INNER JOIN CronGorevler g ON z.CronZamanlamalar_GorevId = g.CronGorevler_Id
        WHERE z.CronZamanlamalar_Id = ? AND z.Durum = 1 AND g.Durum = 1
    ", [$zamanlamaId]);

    if (!$zamanlama) {
        echo "Hata: Zamanlayıcı bulunamadı (Id={$zamanlamaId})." . PHP_EOL;
        exit(1);
    }

    $gorevId     = (int)$zamanlama['GorevId'];
    $gorevKodu   = $zamanlama['CronGorevler_GorevKodu'];
    $gorevAd     = $zamanlama['GorevAd'];
    $params      = json_decode($zamanlama['CronZamanlamalar_SabitParametreler'] ?? '{}', true) ?? [];
    $params      = dinamikParamCoz($params);

    $db->update('CronZamanlamalar',
        ['CronZamanlamalar_SonCalisma' => date('Y-m-d H:i:s')],
        ['CronZamanlamalar_Id' => $zamanlamaId]
    );

// ─── Görev koduyla çalıştırma ─────────────────────────────────────────────────
} elseif (!empty($input['gorev'])) {
    $gorevKodu = trim($input['gorev']);
    $gorev     = $db->fetchOne(
        "SELECT CronGorevler_Id, CronGorevler_Ad, CronGorevler_Parametreler FROM CronGorevler WHERE CronGorevler_GorevKodu = ? AND Durum = 1",
        [$gorevKodu]
    );
    if (!$gorev) {
        echo "Hata: '{$gorevKodu}' görevi bulunamadı." . PHP_EOL;
        exit(1);
    }

    $gorevId     = (int)$gorev['CronGorevler_Id'];
    $gorevAd     = $gorev['CronGorevler_Ad'];
    $zamanlamaId = null;

    $schema = json_decode($gorev['CronGorevler_Parametreler'] ?? '[]', true) ?: [];
    $params = [];
    foreach ($schema as $p) {
        if (!empty($input[$p['ad']])) $params[$p['ad']] = $input[$p['ad']];
    }

} else {
    echo 'Hata: gorev veya zamanlama parametresi zorunlu.' . PHP_EOL;
    exit(1);
}

$tetikTur = $isCli ? 1 : 0;
$logId    = cronLogOlustur($db, $gorevId, $zamanlamaId ?? null, $params, $tetikTur);
$basZaman = microtime(true);
$basTarih = date('Y-m-d H:i:s');

echo "[{$basTarih}] Görev başladı: {$gorevAd}" . PHP_EOL;
if ($params) echo 'Parametreler: ' . json_encode($params, JSON_UNESCAPED_UNICODE) . PHP_EOL;

try {
    $sonuc = gorevCalistir($gorevKodu, $params, $db);
    cronLogBitir($db, $logId, $sonuc['durum'], $sonuc['sonuc'], $basZaman);
    $sure = (int)(microtime(true) - $basZaman);
    echo PHP_EOL . $sonuc['cikti'];
    echo '[' . date('Y-m-d H:i:s') . "] Tamamlandı ({$sure}s): {$sonuc['sonuc']}" . PHP_EOL;
} catch (Throwable $e) {
    $msg = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    cronLogBitir($db, $logId, 2, $msg, $basZaman);
    echo 'Hata: ' . $msg . PHP_EOL;
    exit(1);
}
