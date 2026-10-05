<?php
/**
 * Meta Leadgen Webhook Alıcısı
 *
 * Gerçek dosya olduğu için web.config rewrite'ına takılmaz (api/ gateway'i bypass).
 * URL: https://proje.ornekyazilim.com/webhook/meta-lead.php
 *
 * GET  → Meta doğrulaması (hub.verify_token eşleşirse hub.challenge döner)
 * POST → X-Hub-Signature-256 (App Secret) doğrula → her leadgen_id için
 *        sayfanın Page Token'ı ile Graph'tan lead detayını çek →
 *        ReklamLeadKayitlari'na UPSERT (leadgen_id tekrarını engeller).
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
require_once __DIR__ . '/../admin/includes/KaraListeHelper.php';

const META_SISTEM_KULLANICI = 1; // OlusturanKullanici (sistem)

/** Basit debug log (logs/meta-webhook.log). */
function whLog(string $mesaj): void
{
    $dosya = __DIR__ . '/../logs/meta-webhook.log';
    @file_put_contents($dosya, date('Y-m-d H:i:s') . ' ' . $mesaj . PHP_EOL, FILE_APPEND);
}

/** Aktif TÜM Meta entegrasyonları (çoklu app). */
function metaConfigListesi(Database $db): array
{
    return $db->fetchAll("
        SELECT e.Entegrasyonlar_id                 AS EntegrasyonId,
               e.Entegrasyonlar_Adi                AS Adi,
               e.Entegrasyonlar_ApiKey             AS AppSecret,
               e.Entegrasyonlar_BaseURL            AS BaseURL,
               e.Entegrasyonlar_WebhookVerifyToken AS VerifyToken,
               k.EntegrasyonKanallari_Sifre        AS SistemToken
        FROM Entegrasyonlar e
        LEFT JOIN EntegrasyonKanallari k
               ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id AND k.Durum = 1
        WHERE e.Entegrasyonlar_Tip = 'meta' AND e.Durum = 1
        ORDER BY e.Entegrasyonlar_id
    ") ?: [];
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

/** appsecret_proof üretir. */
function appProof(string $token, string $secret): string
{
    return hash_hmac('sha256', $token, $secret);
}

/** Graph GET → ['ok'=>bool,'data'=>array]. */
function graphGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $cevap = curl_exec($ch);
    $kod   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hata  = curl_error($ch);
    unset($ch);
    $json = json_decode((string)$cevap, true) ?: [];
    return ['ok' => ($kod >= 200 && $kod < 300 && !$hata), 'data' => $json, 'kod' => $kod, 'hata' => $hata];
}

require_once __DIR__ . '/../admin/includes/MetaLeadAlan.php';

// ─── Akış ──────────────────────────────────────────────────────────────────────
$db    = Database::getInstance();
$liste = metaConfigListesi($db);

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

        // Tekrar bildirim → atla (idempotent: Basvurular_LeadgenID)
        $var = $db->fetchOne(
            "SELECT Basvurular_id FROM Basvurular WHERE Basvurular_LeadgenID = ?",
            [$leadgenId]
        );
        if ($var) { continue; }

        // Sayfanın Page Token'ı (yoksa System User token fallback)
        $sayfa = $db->fetchOne("
            SELECT ReklamFacebookSayfalari_id, ReklamFacebookSayfalari_Token,
                   ReklamFacebookSayfalari_Entegrasyon_id
            FROM ReklamFacebookSayfalari WHERE ReklamFacebookSayfalari_SayfaID = ?
        ", [$pid]);

        // Token hangi app'e aitse appsecret_proof o app'in secret'ıyla üretilmeli.
        // Sayfa başka bir app'e bağlıysa (OAuth ile devredilmiş olabilir) onun config'i kullanılır.
        $kaynak     = $cfg;
        $sayfaEntId = (int)($sayfa['ReklamFacebookSayfalari_Entegrasyon_id'] ?? 0);
        if ($sayfaEntId > 0 && $sayfaEntId !== (int)$cfg['EntegrasyonId']) {
            foreach ($liste as $c) {
                if ((int)$c['EntegrasyonId'] === $sayfaEntId) { $kaynak = $c; break; }
            }
        }

        $pageToken = trim((string)($sayfa['ReklamFacebookSayfalari_Token'] ?? '')) ?: (string)$kaynak['SistemToken'];
        if ($pageToken === '') { whLog("Token yok sayfa=$pid lead=$leadgenId"); continue; }

        // Lead detayını çek
        $url = rtrim((string)$kaynak['BaseURL'], '/') . '/' . $leadgenId . '?' . http_build_query([
            'fields'           => 'field_data,form_id,created_time',
            'access_token'     => $pageToken,
            'appsecret_proof'  => appProof($pageToken, (string)$kaynak['AppSecret']),
        ]);
        $r = graphGet($url);
        if (!$r['ok']) {
            whLog("Graph HATA lead=$leadgenId kod={$r['kod']} " . ($r['data']['error']['message'] ?? $r['hata']));
            continue;
        }

        $fieldData = $r['data']['field_data'] ?? [];
        $gercekFormId = (string)($r['data']['form_id'] ?? $formId);

        // Form FK eşleşmesi
        $formFk = null;
        if ($gercekFormId !== '') {
            $f = $db->fetchOne(
                "SELECT ReklamLeadFormlari_id FROM ReklamLeadFormlari WHERE ReklamLeadFormlari_FormID = ?",
                [$gercekFormId]
            );
            $formFk = $f['ReklamLeadFormlari_id'] ?? null;
        }

        // Alan eşlemesi (form için tanımlıysa) — yoksa otomatik tahmin devreye girer
        $a = alanlariEsle($db, $formFk !== null ? (int)$formFk : null, $fieldData);

        // KARA LİSTE — engellenen numara hiç kaydedilmez.
        $engel = KaraListe::kontrolVeLogla(
            KaraListe::kayittanGsm([
                'phoneCountryNumber' => $a['phoneCountryNumber'],
                'phoneAreaNumber'    => $a['phoneAreaNumber'],
                'phoneNumber'        => $a['phoneNumber'],
            ]),
            'webhook:meta-lead',
            ['leadgen_id' => $leadgenId, 'form_id' => $gercekFormId, 'alanlar' => $a],
            null,
            META_SISTEM_KULLANICI
        );
        if ($engel) {
            whLog("KARA LISTE engel lead=$leadgenId gsm=" . $engel['KaraListe_Deger']);
            continue;
        }

        $now = date('Y-m-d H:i:s');
        try {
            $db->insert('Basvurular', array_merge($a, [
                'ReklamLeadFormlari_ID' => $formFk,
                'Basvurular_LeadgenID'  => $leadgenId,
                'OlusturanKullanici'    => META_SISTEM_KULLANICI,
                'OlusturmaTarihi'       => $now,
                'GuncelleyenKullanici'  => META_SISTEM_KULLANICI,
                'GuncellemeTarihi'      => $now,
            ]));
            $islenen++;
        } catch (Throwable $e) {
            whLog("INSERT HATA lead=$leadgenId " . $e->getMessage());
        }
    }
}

whLog("POST islendi=$islenen entegrasyon={$cfg['EntegrasyonId']} ({$cfg['Adi']})");
http_response_code(200);
echo 'EVENT_RECEIVED';
