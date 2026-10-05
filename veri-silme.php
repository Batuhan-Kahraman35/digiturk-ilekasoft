<?php
/**
 * Veri Silme — herkese açık (Meta App Review zorunlu sayfası).
 *
 * İki işlevi var:
 *  1) Facebook "Data Deletion Request Callback" (POST signed_request):
 *     imza doğrulanır, talep loglanır, Meta'ya {url, confirmation_code} JSON döner.
 *     App settings → Temel → "Data Deletion Request URL" olarak gir:
 *       https://proje.ornekyazilim.com/veri-silme.php
 *  2) Normal ziyaret (GET): kullanıcıya silme talebi talimatları gösterir.
 *     ?id=KOD ile talep durumu görüntülenir.
 *
 * Sabitleri kendi bilgilerinle GÜNCELLE.
 */
require_once __DIR__ . '/admin/db.php';

const FIRMA_ADI       = 'ORNEK';
const ILETISIM_EPOSTA = 'info@ornekyazilim.com';   // ← kendi e-postanla değiştir
const SITE_URL        = 'https://proje.ornekyazilim.com';

$logDosya = __DIR__ . '/logs/meta-veri-silme.log';

/** App Secret'ı DB'den al (Entegrasyonlar_ApiKey). */
function appSecret(): string
{
    $r = Database::getInstance()->fetchOne(
        "SELECT TOP 1 Entegrasyonlar_ApiKey AS s FROM Entegrasyonlar WHERE Entegrasyonlar_Tip = 'meta' AND Durum = 1 ORDER BY Entegrasyonlar_id"
    );
    return (string)($r['s'] ?? '');
}

/** Facebook signed_request çöz + imza doğrula. */
function signedRequestCoz(string $signed, string $secret): ?array
{
    if (strpos($signed, '.') === false) return null;
    [$sig, $payload] = explode('.', $signed, 2);
    $sigDecoded = base64_decode(strtr($sig, '-_', '+/'));
    $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    if (!is_array($data)) return null;
    $beklenen = hash_hmac('sha256', $payload, $secret, true);
    if (!hash_equals($beklenen, (string)$sigDecoded)) return null;
    return $data;
}

// ─── 1) Facebook Data Deletion Callback (POST signed_request) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signed_request'])) {
    header('Content-Type: application/json; charset=utf-8');

    $data = signedRequestCoz((string)$_POST['signed_request'], appSecret());
    if (!$data || empty($data['user_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid signed_request']);
        exit;
    }

    $fbUserId = preg_replace('/[^0-9]/', '', (string)$data['user_id']);
    $kod = 'del_' . $fbUserId . '_' . substr(bin2hex(random_bytes(6)), 0, 10);

    // Talebi logla (FB user_id lead'lerle eşlenmiyor; manuel/uyum amaçlı kayıt)
    if (!is_dir(dirname($logDosya))) @mkdir(dirname($logDosya), 0755, true);
    @file_put_contents($logDosya,
        date('Y-m-d H:i:s') . " TALEP fb_user=$fbUserId kod=$kod" . PHP_EOL,
        FILE_APPEND
    );

    echo json_encode([
        'url'               => SITE_URL . '/veri-silme.php?id=' . urlencode($kod),
        'confirmation_code' => $kod,
    ]);
    exit;
}

// ─── 2) Durum sorgulama (?id=KOD) ───────────────────────────────────────────
$durumKod = trim((string)($_GET['id'] ?? ''));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Veri Silme — <?= htmlspecialchars(FIRMA_ADI) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width: 760px;">
    <h1 class="mb-3">Veri Silme Talebi</h1>

    <?php if ($durumKod !== ''): ?>
        <div class="alert alert-success">
            <strong>Talebiniz alındı.</strong><br>
            Onay kodunuz: <code><?= htmlspecialchars($durumKod) ?></code><br>
            Verileriniz en geç 30 gün içinde sistemlerimizden silinecektir.
        </div>
    <?php endif; ?>

    <p><strong><?= htmlspecialchars(FIRMA_ADI) ?></strong> olarak, hakkınızda tuttuğumuz kişisel verilerin
    (ad, telefon, e-posta vb.) silinmesini talep edebilirsiniz.</p>

    <h4 class="mt-4">Nasıl talep ederim?</h4>
    <ol>
        <li><strong>E-posta ile:</strong> <a href="mailto:<?= htmlspecialchars(ILETISIM_EPOSTA) ?>"><?= htmlspecialchars(ILETISIM_EPOSTA) ?></a>
            adresine, kayıtlı telefon veya e-posta bilginizle "verilerimi silin" talebi gönderin.</li>
        <li><strong>Facebook üzerinden:</strong> Facebook hesabınızın Ayarlar → Uygulamalar ve Web Siteleri
            bölümünden uygulamamızı kaldırdığınızda, otomatik bir silme talebi oluşturulur.</li>
    </ol>

    <p>Talebiniz, ilgili mevzuat çerçevesinde en geç <strong>30 gün</strong> içinde sonuçlandırılır.</p>

    <h4 class="mt-4">İletişim</h4>
    <p><a href="mailto:<?= htmlspecialchars(ILETISIM_EPOSTA) ?>"><?= htmlspecialchars(ILETISIM_EPOSTA) ?></a></p>

    <hr class="mt-4">
    <p class="text-muted small">
        <a href="/gizlilik-politikasi.php">Gizlilik Politikası</a> &middot;
        &copy; <?= date('Y') ?> <?= htmlspecialchars(FIRMA_ADI) ?>
    </p>
</div>
</body>
</html>
