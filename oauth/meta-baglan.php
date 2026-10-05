<?php
/**
 * Meta OAuth — Başlat ("Facebook ile Bağlan")
 *
 * Giriş yapmış admin kullanıcıyı Facebook izin ekranına yönlendirir.
 * CSRF için state session'da tutulur, callback'te doğrulanır.
 * Dönüş: oauth/meta-callback.php
 *
 * ÇOKLU APP: ?e=<Entegrasyonlar_id> ile hangi Meta App üzerinden bağlanılacağı seçilir.
 * Parametre yoksa ilk aktif Meta entegrasyonu kullanılır (geriye dönük uyumluluk).
 */

require_once __DIR__ . '/../admin/auth.php';
requireAuth();

const META_REDIRECT_URI = 'https://proje.ornekyazilim.com/oauth/meta-callback.php';
const META_SCOPES = 'pages_show_list,pages_manage_metadata,pages_manage_ads,leads_retrieval,pages_read_engagement,business_management';

$db = Database::getInstance();

// Hangi Meta App? (?e=<Entegrasyonlar_id>; yoksa ilk aktif entegrasyon)
$entegrasyonId = (int)($_GET['e'] ?? 0);

$sql = "
    SELECT TOP 1
           e.Entegrasyonlar_id             AS EntegrasyonId,
           k.EntegrasyonKanallari_Instance AS AppId,
           e.Entegrasyonlar_BaseURL        AS BaseURL,
           e.Entegrasyonlar_ApiKey         AS AppSecret
    FROM Entegrasyonlar e
    LEFT JOIN EntegrasyonKanallari k
           ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id AND k.Durum = 1
    WHERE e.Entegrasyonlar_Tip = 'meta' AND e.Durum = 1
";
$params = [];
if ($entegrasyonId > 0) {
    $sql .= " AND e.Entegrasyonlar_id = ?";
    $params[] = $entegrasyonId;
}
$sql .= " ORDER BY e.Entegrasyonlar_id";

$cfg = $db->fetchOne($sql, $params);

if (!$cfg || empty($cfg['AppId'])) {
    exit('Meta entegrasyon ayarı (App ID) bulunamadı.');
}

// Dialog tabanı: graph.facebook.com → www.facebook.com (versiyonu koru)
$dialogBase = str_replace('graph.facebook.com', 'www.facebook.com', rtrim((string)$cfg['BaseURL'], '/'));

// CSRF state — "<entegrasyonId>.<rastgele>.<hmac>"
// HMAC, App Secret ile üretilir: Facebook dönüşünde session cookie taşınmasa da
// (cross-site redirect) state doğrulanabilir kalır. Session yedek kontrol olarak durur.
$entId = (int)$cfg['EntegrasyonId'];
$nonce = bin2hex(random_bytes(16));
$imza  = substr(hash_hmac('sha256', $entId . '.' . $nonce, (string)$cfg['AppSecret']), 0, 32);
$state = $entId . '.' . $nonce . '.' . $imza;
$_SESSION['meta_oauth_state'] = $state;

$url = $dialogBase . '/dialog/oauth?' . http_build_query([
    'client_id'     => $cfg['AppId'],
    'redirect_uri'  => META_REDIRECT_URI,
    'state'         => $state,
    'response_type' => 'code',
    'scope'         => META_SCOPES,
]);

header('Location: ' . $url);
exit;
