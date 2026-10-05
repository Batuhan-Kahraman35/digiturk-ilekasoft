<?php
/**
 * Bildirim API — header çanını besler
 *
 * İki kaynak birleştirilir:
 *   1) dbo.Bildirimler       — kalıcı bildirimler (kendine ait + genel). Herkese açık.
 *   2) Bekleyen başvurular   — türetilmiş uyarı (kullanicilar.kullanici_durum IS NULL).
 *                              Yalnızca personel-yonetimi.php yetkisi olanlara gösterilir.
 *
 * action=get_notifications : liste + okunmamış sayısı
 * action=get_count         : sadece sayı
 * action=mark_read         : bildirim_id verilirse tekini, verilmezse tümünü okundu yapar
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/Bildirim.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$db          = Database::getInstance();
$user        = Auth::user();
$kullaniciId = (int)($user['kullanici_id'] ?? 0);

// Başvuru bildirimleri yetkiye tabi; Bildirimler tablosu herkese açık.
$basvuruYetkisi = PageAuth::checkPagePermissions(
    $kullaniciId,
    $user['departman_id'] ?? null,
    'personel-yonetimi.php'
)['has_access'];

/** Dakika farkını "5 dakika önce" gibi okunur metne çevirir */
function zamanMetni(int $dakika): string
{
    if ($dakika < 1)    return 'az önce';
    if ($dakika < 60)   return $dakika . ' dakika önce';
    if ($dakika < 1440) return floor($dakika / 60) . ' saat önce';
    return floor($dakika / 1440) . ' gün önce';
}

/** Bildirim tipini çan ikonu + rengine eşler */
function tipGorunum(string $tip): array
{
    return [
        'basari' => ['bi-check-circle',        'success'],
        'uyari'  => ['bi-exclamation-triangle', 'warning'],
        'hata'   => ['bi-x-circle',            'danger'],
    ][$tip] ?? ['bi-info-circle', 'primary'];
}

/** Bekleyen başvuruları çan bildirimi formatına çevirir */
function basvuruBildirimleri(Database $db): array
{
    $basvurular = $db->fetchAll("
        SELECT kullanici_id, kullanici_ad, kullanici_soyad, kullanici_email,
               DATEDIFF(MINUTE, kullanici_olusturma_tarihi, GETDATE()) AS dakika_once
        FROM kullanicilar
        WHERE kullanici_durum IS NULL
          AND kullanici_olusturma_tarihi >= DATEADD(DAY, -30, GETDATE())
        ORDER BY kullanici_olusturma_tarihi DESC
    ");

    $liste = [];
    foreach ($basvurular as $b) {
        $adSoyad = trim(($b['kullanici_ad'] ?? '') . ' ' . ($b['kullanici_soyad'] ?? ''));
        if ($adSoyad === '') $adSoyad = $b['kullanici_email'];

        $liste[] = [
            'id'          => 'basvuru-' . $b['kullanici_id'],
            'bildirim_id' => null,          // türetilmiş -> okundu işaretlenemez
            'tip'         => 'basvuru',
            'baslik'      => 'Yeni Başvuru',
            'mesaj'       => $adSoyad . ' başvuruda bulundu',
            'zaman'       => zamanMetni((int)$b['dakika_once']),
            'dakika_once' => (int)$b['dakika_once'],
            'link'        => '/admin/pages/personel-yonetimi.php',
            'icon'        => 'bi-person-plus',
            'renk'        => 'warning',
            'okundu'      => 0,
        ];
    }
    return $liste;
}

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // ─── Okundu işaretle ───
    if ($action === 'mark_read') {
        $bildirimId = $_POST['bildirim_id'] ?? $_GET['bildirim_id'] ?? null;
        Bildirim::okunduYap($db, $kullaniciId, $bildirimId ? (int)$bildirimId : null);
        echo json_encode(['success' => true]);
        exit;
    }

    // ─── Sadece sayı ───
    if ($action === 'get_count') {
        $sayi = Bildirim::okunmamisSayisi($db, $kullaniciId);
        if ($basvuruYetkisi) {
            $sayi += (int)($db->fetchOne("
                SELECT COUNT(*) AS sayi FROM kullanicilar
                WHERE kullanici_durum IS NULL
                  AND kullanici_olusturma_tarihi >= DATEADD(DAY, -30, GETDATE())
            ")['sayi'] ?? 0);
        }
        echo json_encode(['success' => true, 'count' => $sayi]);
        exit;
    }

    // ─── Liste ───
    if ($action === 'get_notifications') {
        $bildirimler = [];

        // 1) Kalıcı bildirimler (dbo.Bildirimler)
        foreach (Bildirim::listele($db, $kullaniciId, 20) as $b) {
            [$ikon, $renk] = tipGorunum($b['Bildirimler_Tip'] ?? 'info');
            $bildirimler[] = [
                'id'          => 'bildirim-' . $b['Bildirimler_id'],
                'bildirim_id' => (int)$b['Bildirimler_id'],
                'tip'         => $b['Bildirimler_Tip'],
                'baslik'      => $b['Bildirimler_Baslik'],
                'mesaj'       => $b['Bildirimler_Govde'],
                'zaman'       => zamanMetni((int)$b['dakika_once']),
                'dakika_once' => (int)$b['dakika_once'],
                'link'        => $b['Bildirimler_Url'] ?: '/admin/',
                'icon'        => $ikon,
                'renk'        => $renk,
                'okundu'      => (int)$b['Bildirimler_Okundu'],
            ];
        }

        // 2) Bekleyen başvurular (yetkiliyse)
        if ($basvuruYetkisi) {
            $bildirimler = array_merge($bildirimler, basvuruBildirimleri($db));
        }

        // En yeni üstte
        usort($bildirimler, fn($a, $b) => $a['dakika_once'] <=> $b['dakika_once']);
        $bildirimler = array_slice($bildirimler, 0, 20);

        // Rozet sayısı: okunmamışlar
        $count = 0;
        foreach ($bildirimler as $b) {
            if (!$b['okundu']) $count++;
        }

        echo json_encode([
            'success' => true,
            'data'    => $bildirimler,
            'count'   => $count,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new Exception('Geçersiz işlem');

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
