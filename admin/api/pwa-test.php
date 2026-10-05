<?php
/**
 * PWA - Test bildirimi: dbo.Bildirimler'e kayıt atar + push gönderir.
 * POST (oturum gerekli)
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/Bildirim.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
    exit;
}

$user        = Auth::user();
$kullaniciId = (int)($user['kullanici_id'] ?? 0);
$db          = Database::getInstance();

try {
    // Kayıt oluştur; push'u ayrı tetikleyip sonucunu raporlayabilmek için push=false
    $bildirimId = Bildirim::olustur($db, [
        'kullanici_id' => $kullaniciId,
        'baslik'       => 'Test Bildirimi',
        'govde'        => 'Push bildirimleri çalışıyor 🎉',
        'url'          => '/admin/',
        'tip'          => 'basari',
        'push'         => false,
        'olusturan'    => $kullaniciId,
    ]);

    $push = Bildirim::push($db, $bildirimId);

    $gonderilen = $push['gonderilen'] ?? 0;
    $basarisiz  = $push['basarisiz'] ?? 0;

    $mesaj = 'Bildirim oluşturuldu (çana düştü). Push: ' . $gonderilen . ' cihaz';
    if ($basarisiz > 0)          $mesaj .= ', ' . $basarisiz . ' başarısız';
    if (!empty($push['hata']))   $mesaj .= ' — ' . $push['hata'];
    if ($gonderilen === 0 && $basarisiz === 0 && empty($push['hata'])) {
        $mesaj .= ' (bu kullanıcıya ait aktif cihaz yok — "Bildirimleri Aç"a basılmamış olabilir)';
    }

    echo json_encode([
        'success'     => true,
        'bildirim_id' => $bildirimId,
        'push'        => $push,
        'message'     => $mesaj,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
