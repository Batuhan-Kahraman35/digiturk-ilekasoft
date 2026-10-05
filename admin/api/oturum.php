<?php
/**
 * Oturum Durum / Uzatma API
 *
 * action=durum  -> kalan sureyi doner (oturuma dokunmaz)
 * action=uzat   -> login_time'i tazeler, sure ayardaki deger kadar yeniden baslar
 *
 * NOT: Bilerek requireAuth() kullanilmaz. requireAuth() sure doldugunda login.php'ye
 * 302 dondurur; bu uc noktanin her zaman JSON donmesi gerekir.
 */

require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = $_POST['action'] ?? $_GET['action'] ?? 'durum';
$omur   = oturumTimeoutSaniye();

// Oturum hic yoksa (cikis yapilmis / session temizlenmis)
if (empty($_SESSION['logged_in']) || empty($_SESSION['login_time'])) {
    echo json_encode(['oturum' => false, 'kalan' => 0, 'omur' => $omur]);
    exit;
}

$kalan = $omur - (time() - $_SESSION['login_time']);

if ($action === 'uzat') {
    if ($kalan <= 0) {
        // Suresi dolmus bir oturum uzatilamaz
        Auth::logout();
        echo json_encode(['oturum' => false, 'kalan' => 0, 'omur' => $omur]);
        exit;
    }

    // Uzatma miktari da ayardan gelir; hicbir yerde sabit sure yoktur
    $_SESSION['login_time'] = time();
    $kalan = $omur;
}

echo json_encode([
    'oturum' => $kalan > 0,
    'kalan'  => max(0, $kalan),
    'omur'   => $omur
]);
