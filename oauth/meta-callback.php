<?php
/**
 * Meta OAuth — Callback
 *
 * code → kısa ömürlü user token → uzun ömürlü user token (60 gün) → Page Token'lar.
 * Her sayfa: ReklamFacebookSayfalari'na token UPSERT + leadgen webhook'a subscribe.
 * Bitince Reklam Yönetimi'ne sonuç parametreleriyle döner.
 */

require_once __DIR__ . '/../admin/auth.php';
requireAuth();

const META_REDIRECT_URI = 'https://proje.ornekyazilim.com/oauth/meta-callback.php';
const META_DONUS = '/admin/reklam-yonetimi';

$user   = Auth::user();
$kullaniciId = (int)($user['kullanici_id'] ?? 1);
$db     = Database::getInstance();

function donus(string $durum, array $ekstra = []): void
{
    $q = array_merge(['meta_baglan' => $durum], $ekstra);
    header('Location: ' . META_DONUS . '?' . http_build_query($q));
    exit;
}

// ── State biçim kontrolü (asıl doğrulama config çekildikten sonra, HMAC ile) ──
$state = (string)($_GET['state'] ?? '');
if ($state === '') {
    donus('hata', ['mesaj' => 'state']);
}
$stateParca = explode('.', $state);

// ── Kullanıcı izni reddettiyse ──
if (isset($_GET['error']) || !isset($_GET['code'])) {
    donus('iptal');
}
$code = (string)$_GET['code'];

// ── Config (state'in başındaki entegrasyon id hangi Meta App olduğunu söyler) ──
$entegrasyonId = (int)($stateParca[0] ?? 0);

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
    donus('hata', ['mesaj' => 'config']);
}

$baseURL = rtrim((string)$cfg['BaseURL'], '/');
$appId   = (string)$cfg['AppId'];
$secret  = (string)$cfg['AppSecret'];
$entId   = (int)$cfg['EntegrasyonId'];

// ── State (CSRF) doğrula: HMAC birincil, session yedek ──
// Facebook dönüşü cross-site redirect olduğu için session cookie taşınmayabilir;
// bu yüzden state'in içindeki App Secret imzası asıl kanıttır.
$hmacGecerli = false;
if (count($stateParca) === 3) {
    $beklenenImza = substr(hash_hmac('sha256', $stateParca[0] . '.' . $stateParca[1], $secret), 0, 32);
    $hmacGecerli  = hash_equals($beklenenImza, (string)$stateParca[2]);
}
$oturumState    = (string)($_SESSION['meta_oauth_state'] ?? '');
$oturumGecerli  = $oturumState !== '' && hash_equals($oturumState, $state);

if (!$hmacGecerli && !$oturumGecerli) {
    donus('hata', ['mesaj' => 'state']);
}
unset($_SESSION['meta_oauth_state']);

/** Basit GET → dizi. */
function gGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false]);
    $r = json_decode((string)curl_exec($ch), true) ?: [];
    unset($ch);
    return $r;
}
/** Basit POST → dizi. */
function gPost(string $url, array $data): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data),
    ]);
    $r = json_decode((string)curl_exec($ch), true) ?: [];
    unset($ch);
    return $r;
}
function proof(string $t, string $s): string { return hash_hmac('sha256', $t, $s); }

// ── 1) code → kısa ömürlü user token ──
$r = gGet($baseURL . '/oauth/access_token?' . http_build_query([
    'client_id'     => $appId,
    'redirect_uri'  => META_REDIRECT_URI,
    'client_secret' => $secret,
    'code'          => $code,
]));
$kisaToken = (string)($r['access_token'] ?? '');
if ($kisaToken === '') {
    donus('hata', ['mesaj' => 'token']);
}

// ── 2) uzun ömürlü user token (60 gün) ──
$r = gGet($baseURL . '/oauth/access_token?' . http_build_query([
    'grant_type'        => 'fb_exchange_token',
    'client_id'         => $appId,
    'client_secret'     => $secret,
    'fb_exchange_token' => $kisaToken,
]));
$uzunToken = (string)($r['access_token'] ?? $kisaToken);

// ── 3) Sayfalar + Page Token'lar ──
$pages = [];
$url = $baseURL . '/me/accounts?' . http_build_query([
    'fields'          => 'id,name,access_token',
    'limit'           => 200,
    'access_token'    => $uzunToken,
    'appsecret_proof' => proof($uzunToken, $secret),
]);
$guard = 0;
while ($url && $guard < 20) {
    $r = gGet($url);
    foreach (($r['data'] ?? []) as $p) $pages[] = $p;
    $url = $r['paging']['next'] ?? null;
    $guard++;
}

// ── 4) Her sayfa: token UPSERT + leadgen subscribe ──
$now = date('Y-m-d H:i:s');
$kaydedilen = 0; $abone = 0;
foreach ($pages as $p) {
    $pid = (string)($p['id'] ?? '');
    $pad = (string)($p['name'] ?? '');
    $pt  = (string)($p['access_token'] ?? '');
    if ($pid === '' || $pt === '') continue;

    // UPSERT (SayfaID anahtar; Kampanya_id'ye dokunma)
    $mevcut = $db->fetchOne(
        "SELECT ReklamFacebookSayfalari_id FROM ReklamFacebookSayfalari WHERE ReklamFacebookSayfalari_SayfaID = ?",
        [$pid]
    );
    if ($mevcut) {
        $db->update('ReklamFacebookSayfalari', [
            'ReklamFacebookSayfalari_SayfaAdi'      => $pad,
            'ReklamFacebookSayfalari_Token'         => $pt,
            'ReklamFacebookSayfalari_WebhookAktif'  => 1,
            'ReklamFacebookSayfalari_Entegrasyon_id'=> $entId,
            'GuncelleyenKullanici'                  => $kullaniciId,
            'GuncellemeTarihi'                      => $now,
        ], ['ReklamFacebookSayfalari_id' => $mevcut['ReklamFacebookSayfalari_id']]);
    } else {
        $db->insert('ReklamFacebookSayfalari', [
            'ReklamFacebookSayfalari_Kampanya_id'   => null,
            'ReklamFacebookSayfalari_SayfaAdi'      => $pad,
            'ReklamFacebookSayfalari_SayfaID'       => $pid,
            'ReklamFacebookSayfalari_Token'         => $pt,
            'ReklamFacebookSayfalari_WebhookAktif'  => 1,
            'ReklamFacebookSayfalari_Entegrasyon_id'=> $entId,
            'OlusturanKullanici'                    => $kullaniciId,
            'OlusturmaTarihi'                       => $now,
            'Durum'                                 => 1,
        ]);
    }
    $kaydedilen++;

    // leadgen subscribe
    $s = gPost($baseURL . '/' . $pid . '/subscribed_apps', [
        'subscribed_fields' => 'leadgen',
        'access_token'      => $pt,
        'appsecret_proof'   => proof($pt, $secret),
    ]);
    if (!empty($s['success'])) $abone++;
}

donus('ok', ['sayfa' => $kaydedilen, 'abone' => $abone]);
