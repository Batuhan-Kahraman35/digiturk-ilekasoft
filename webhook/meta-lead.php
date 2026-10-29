<?php
/**
 * Meta Leadgen Webhook Alıcısı
 *
 * Gerçek dosya olduğu için web.config rewrite'ına takılmaz (api/ gateway'i bypass).
 * URL: https://proje.ornekyazilim.com/webhook/meta-lead.php
 *
 * GET  → Meta doğrulaması (hub.verify_token eşleşirse hub.challenge döner)
 * POST → X-Hub-Signature-256 (App Secret) doğrula → her leadgen_id için
 *        MetaLeadIsle::isle() (lead detayı, kurallar, kara liste, Basvurular INSERT;
 *        leadgen_id tekrarını engeller).
 *
 * ÇOKLU APP: Birden fazla Meta App (ayrı işletme portföyleri) aynı anda desteklenir.
 * Payload hangi app'ten geldiğini söylemediği için tespit imzadan yapılır:
 *   GET  → verify token hangi entegrasyonla eşleşiyorsa o app doğrulanır.
 *   POST → X-Hub-Signature-256 sırayla her App Secret ile denenir; tutan = kaynak app.
 * Böylece tüm app'ler tek callback URL'i paylaşabilir.
 *
 * Config kaynağı (Tip='meta' olan TÜM aktif entegrasyonlar):
 *   Entegrasyonlar.Entegrasyonlar_ApiKey              → App Secret (imza + appsecret_proof)
 *   Entegrasyonlar.Entegrasyonlar_BaseURL             → Graph base (ör. https://graph.facebook.com/v21.0)
 *   Entegrasyonlar.Entegrasyonlar_WebhookVerifyToken  → GET doğrulama anahtarı
 *   EntegrasyonKanallari.EntegrasyonKanallari_Sifre   → System User token (Page Token yoksa fallback)
 *   ReklamFacebookSayfalari.ReklamFacebookSayfalari_Token → sayfa bazlı Page Token (öncelikli)
 */

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/includes/MetaLeadIsle.php';

/** Basit debug log (logs/meta-webhook.log). */
function whLog(string $mesaj): void
{
    $dosya = __DIR__ . '/../logs/meta-webhook.log';
    @file_put_contents($dosya, date('Y-m-d H:i:s') . ' ' . $mesaj . PHP_EOL, FILE_APPEND);
}

/**
 * POST gövdesinin imzasını her App Secret ile deneyip kaynak entegrasyonu bulur.
 * Eşleşme yoksa null (imza geçersiz).
 */
function imzadanEntegrasyon(array $liste, string $raw, string $imza): ?array
{
    if ($imza === '') return null;
    foreach ($liste as $cfg) {
        $secret = (string)($cfg['AppSecret'] ?? '');
        if ($secret === '') continue;
        if (hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $imza)) {
            return $cfg;
        }
    }
    return null;
}

// ─── Akış ──────────────────────────────────────────────────────────────────────
$db    = Database::getInstance();
$liste = MetaLeadIsle::configListesi($db);

if (!$liste) {
    http_response_code(500);
    whLog('HATA: aktif Meta entegrasyonu bulunamadi.');
    exit('config yok');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── GET: Meta doğrulama (hangi app'in verify token'ı tutarsa o onaylanır) ────
if ($method === 'GET') {
    $mode      = $_GET['hub_mode']         ?? ($_GET['hub.mode']         ?? '');
    $token     = $_GET['hub_verify_token'] ?? ($_GET['hub.verify_token'] ?? '');
    $challenge = $_GET['hub_challenge']    ?? ($_GET['hub.challenge']    ?? '');

    if ($mode === 'subscribe') {
        foreach ($liste as $c) {
            $vt = (string)($c['VerifyToken'] ?? '');
            if ($vt !== '' && hash_equals($vt, (string)$token)) {
                whLog('Dogrulama OK. entegrasyon=' . $c['EntegrasyonId'] . ' (' . $c['Adi'] . ')');
                header('Content-Type: text/plain');
                echo $challenge;
                exit;
            }
        }
    }
    http_response_code(403);
    whLog('Dogrulama RED (token hicbir entegrasyonla eslesmedi).');
    exit('forbidden');
}

// ── POST: lead bildirimi ─────────────────────────────────────────────────────
$raw = file_get_contents('php://input');

// İmza doğrulama + kaynak app tespiti (X-Hub-Signature-256)
$imza = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$cfg  = imzadanEntegrasyon($liste, $raw, (string)$imza);
if (!$cfg) {
    http_response_code(403);
    whLog('Imza RED (hicbir App Secret eslesmedi). gelen=' . $imza);
    exit('bad signature');
}

$payload = json_decode($raw, true);
if (!is_array($payload) || ($payload['object'] ?? '') !== 'page') {
    http_response_code(200); // Meta'ya 200 dön ki tekrar denemesin
    exit('ignored');
}

$islenen = 0;

foreach (($payload['entry'] ?? []) as $entry) {
    $pageId = (string)($entry['id'] ?? '');

    foreach (($entry['changes'] ?? []) as $change) {
        if (($change['field'] ?? '') !== 'leadgen') continue;
        $v = $change['value'] ?? [];
        $leadgenId = (string)($v['leadgen_id'] ?? '');
        $formId    = (string)($v['form_id'] ?? '');
        $pid       = (string)($v['page_id'] ?? $pageId);
        if ($leadgenId === '') continue;

        // Sayfanın Page Token'ı (yoksa System User token fallback); imzası tutan app varsayılan
        $baglam = MetaLeadIsle::sayfaBaglami($db, $liste, $pid, $cfg);
        if (!$baglam) { whLog("Token yok sayfa=$pid lead=$leadgenId"); continue; }

        $sonuc = MetaLeadIsle::isle($db, $baglam, $leadgenId, $formId);
        foreach ($sonuc['notlar'] ?? [] as $not) whLog($not);
        if ($sonuc['durum'] === 'eklendi')    { $islenen++; }
        elseif ($sonuc['durum'] !== 'mevcut') { whLog($sonuc['mesaj']); }
    }
}

whLog("POST islendi=$islenen entegrasyon={$cfg['EntegrasyonId']} ({$cfg['Adi']})");
http_response_code(200);
echo 'EVENT_RECEIVED';
