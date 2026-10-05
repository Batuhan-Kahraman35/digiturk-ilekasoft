<?php
/**
 * PWA - Push aboneliği kaydet / sil (cihaz adresleri: dbo.BildirimAbonelikleri)
 * POST action=kaydet : {endpoint, p256dh, auth}
 * POST action=sil    : {endpoint}
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı.']);
    exit;
}

$user        = Auth::user();
$kullaniciId = (int)($user['kullanici_id'] ?? 0);
$db          = Database::getInstance();

$input  = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $input['action'] ?? 'kaydet';

try {
    if ($action === 'sil') {
        $endpoint = $input['endpoint'] ?? '';
        if ($endpoint === '') throw new Exception('endpoint gerekli.');
        $db->execute("
            UPDATE dbo.BildirimAbonelikleri
            SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
            WHERE BildirimAbonelikleri_Endpoint = ?
        ", [$kullaniciId, $endpoint]);
        echo json_encode(['success' => true, 'message' => 'Abonelik iptal edildi.']);
        exit;
    }

    // kaydet
    $endpoint = $input['endpoint'] ?? '';
    $p256dh   = $input['p256dh'] ?? '';
    $auth     = $input['auth'] ?? '';
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        throw new Exception('Eksik abonelik bilgisi.');
    }
    $tarayici = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    // Endpoint UNIQUE; aynı cihaz başka kullanıcıya geçmiş olabilir -> sahibi güncellenir.
    $mevcut = $db->fetchOne("
        SELECT BildirimAbonelikleri_id FROM dbo.BildirimAbonelikleri
        WHERE BildirimAbonelikleri_Endpoint = ?
    ", [$endpoint]);

    if ($mevcut) {
        $db->execute("
            UPDATE dbo.BildirimAbonelikleri
            SET BildirimAbonelikleri_KullaniciId = ?,
                BildirimAbonelikleri_P256dh      = ?,
                BildirimAbonelikleri_Auth        = ?,
                BildirimAbonelikleri_Tarayici    = ?,
                Durum = 1,
                GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
            WHERE BildirimAbonelikleri_id = ?
        ", [$kullaniciId, $p256dh, $auth, $tarayici, $kullaniciId, $mevcut['BildirimAbonelikleri_id']]);
    } else {
        $db->insert('dbo.BildirimAbonelikleri', [
            'BildirimAbonelikleri_KullaniciId' => $kullaniciId,
            'BildirimAbonelikleri_Endpoint'    => $endpoint,
            'BildirimAbonelikleri_P256dh'      => $p256dh,
            'BildirimAbonelikleri_Auth'        => $auth,
            'BildirimAbonelikleri_Tarayici'    => $tarayici,
            'OlusturanKullanici'               => $kullaniciId,
            'OlusturmaTarihi'                  => date('Y-m-d H:i:s'),
            'Durum'                            => 1,
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Bildirim aboneliği kaydedildi.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
