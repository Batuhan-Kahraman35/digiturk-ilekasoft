<?php
/**
 * Destek Bildirim Proxy (AJAX)
 * Kaynak API: destek.ornekyazilim.com (DestekHelper)
 *
 * Header'daki bildirim çanı 60 sn'de bir çağırır. API key server-side kalır.
 *
 *   GET destek-bildirim.php?son_kontrol=2026-07-03T12:00:00
 *
 * Yanıt: { success, yeni_sayisi, toplam, ticketler[] }
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/DestekHelper.php';

header('Content-Type: application/json; charset=utf-8');

// Oturum kontrolü (AJAX → redirect yerine JSON)
if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
    exit;
}

if (!DestekHelper::aktifMi()) {
    echo json_encode(['success' => false, 'message' => 'Destek API yapılandırılmamış.']);
    exit;
}

$res = DestekHelper::listele();
if (!($res['success'] ?? false)) {
    echo json_encode(['success' => false, 'message' => $res['message'] ?? 'API hatası.']);
    exit;
}

$tickets = $res['data'] ?? [];
$toplam  = count($tickets);

// son_kontrol'den sonra yanıt gelen ticket'lar = yeni
$sonKontrol     = trim($_GET['son_kontrol'] ?? '');
$sonKontrolTime = $sonKontrol !== '' ? strtotime($sonKontrol) : 0;

$yeniSayisi = 0;
$liste      = [];

foreach ($tickets as $t) {
    $sonYanitStr = $t['son_yanit_tarihi'] ?? ($t['acilis_tarihi'] ?? '');
    $yeni = false;
    if (!empty($t['son_yanit_tarihi']) && $sonKontrolTime > 0
        && strtotime($t['son_yanit_tarihi']) > $sonKontrolTime) {
        $yeni = true;
        $yeniSayisi++;
    }
    $liste[] = [
        'id'        => (int)($t['Tickets_id'] ?? 0),
        'no'        => $t['Tickets_no'] ?? '',
        'konu'      => $t['Tickets_konu'] ?? '',
        'durum_ad'  => $t['durum_ad'] ?? '',
        'son_yanit' => $sonYanitStr,
        'yeni'      => $yeni,
        '_sira'     => strtotime($sonYanitStr) ?: 0,
    ];
}

// En güncel üstte; dropdown için ilk 10
usort($liste, fn($a, $b) => $b['_sira'] <=> $a['_sira']);
$liste = array_slice($liste, 0, 10);
foreach ($liste as &$l) { unset($l['_sira']); }
unset($l);

echo json_encode([
    'success'     => true,
    'yeni_sayisi' => $yeniSayisi,
    'toplam'      => $toplam,
    'ticketler'   => $liste,
], JSON_UNESCAPED_UNICODE);
