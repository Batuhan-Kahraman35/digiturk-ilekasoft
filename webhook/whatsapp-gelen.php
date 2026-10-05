<?php
/**
 * WhatsApp Gelen Mesaj Webhook'u (Evolution API)
 *
 * URL: https://proje.ornekyazilim.com/webhook/whatsapp-gelen.php?key=<SECRET>
 * Evolution tarafında instance için `messages.upsert` olayına bağlanır.
 *
 * İşi: gelen mesajı aktif hatırlatma kurallarının BULUNDUĞU AŞAMANIN hedefiyle
 * eşleştirip aşamayı ilerletmek.
 *
 *   • Metin mesajı → aşamanın AnahtarKelime'sini içeriyorsa  → tetikleyici 'whatsapp'
 *   • PDF/belge    → aşama 'belge' tetikleyicisine açıksa    → tetikleyici 'belge'
 *
 * Aşama hangi tetikleyicilere açıksa yalnız onlar iş görür; kapalıysa mesaj yok sayılır.
 * İlerletme mantığı tek noktada: cron/tasks.php → hatirlatmaAsamaIlerlet().
 */

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../cron/tasks.php';

const WA_SISTEM_KULLANICI = 1;

/**
 * LID → telefon eşleşmeleri.
 *
 * WhatsApp bireysel sohbetlerde numara yerine LID gönderebiliyor ve bazı kişilerde
 * payload'da telefon hiç yer almıyor (remoteJidAlt/senderPn boş gelir). O kişiler
 * için eşleşme burada elle tutuluyor. Liste büyürse DB tablosuna taşınmalı.
 */
const WA_LID_NUMARA = [
    '10000000000001' => '905550000002',   // Ajans Bütçe Talebi — 1. aşama hedefi
];

function waLog(string $mesaj): void
{
    @file_put_contents(__DIR__ . '/../logs/whatsapp-webhook.log',
        date('Y-m-d H:i:s') . ' ' . $mesaj . PHP_EOL, FILE_APPEND);
}

function waCikis(int $kod, array $govde): void
{
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($govde, JSON_UNESCAPED_UNICODE);
    exit;
}

/** JID'i karşılaştırılabilir hale getirir: sunucu ekini atar, rakamları bırakır. */
function waNormalizeJid(?string $jid): string
{
    $jid = (string)$jid;
    if ($jid === '') return '';
    if (str_contains($jid, '@g.us')) {              // grup: id kısmı belirleyici
        return strtolower(explode('@', $jid)[0]) . '@g.us';
    }
    $yerel = explode('@', $jid)[0];
    $yerel = explode(':', $yerel)[0];               // 905xx:12@s.whatsapp.net → 905xx
    return preg_replace('/\D/', '', $yerel);
}

/**
 * Gelen belgenin base64 içeriğini döndürür.
 * Önce payload'a bakar ("Webhook Base64" açıksa oradadır), yoksa Evolution'dan indirir.
 */
function waBelgeBase64(Database $db, array $mesaj, array $belge): ?string
{
    // 1) Payload içinde geldiyse
    foreach ([
        $mesaj['message']['base64'] ?? null,
        $mesaj['base64']            ?? null,
        $belge['base64']            ?? null,
    ] as $b64) {
        if (is_string($b64) && strlen($b64) > 100) return $b64;
    }

    // 2) Yoksa Evolution'dan iste
    $kanal = $db->fetchOne("
        SELECT k.EntegrasyonKanallari_Instance AS Instance,
               e.Entegrasyonlar_BaseURL        AS BaseURL,
               e.Entegrasyonlar_ApiKey         AS ApiKey
        FROM EntegrasyonKanallari k
        INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
        WHERE e.Entegrasyonlar_Tip = 'whatsapp' AND k.Durum = 1 AND e.Durum = 1
        ORDER BY k.EntegrasyonKanallari_id
    ");
    if (!$kanal || empty($mesaj['key']['id'])) return null;

    $url = rtrim($kanal['BaseURL'], '/') . '/chat/getBase64FromMediaMessage/' . $kanal['Instance'];
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'message'      => ['key' => ['id' => $mesaj['key']['id']]],
            'convertToMp4' => false,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $kanal['ApiKey']],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $cevap = curl_exec($ch);
    $kod   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($kod < 200 || $kod >= 300) {
        waLog("Belge indirilemedi (HTTP {$kod}): " . substr((string)$cevap, 0, 200));
        return null;
    }

    $j = json_decode((string)$cevap, true);
    $b = $j['base64'] ?? $j['media'] ?? null;
    return (is_string($b) && strlen($b) > 100) ? $b : null;
}

/** Belgeyi storage altına yazar, kayıt için meta döndürür. */
function waBelgeKaydet(string $base64, string $dosyaAdi, string $mimetype): ?array
{
    $dizin = __DIR__ . '/../storage/hatirlatma-ekleri';
    if (!is_dir($dizin) && !@mkdir($dizin, 0775, true)) {
        waLog('Ek dizini oluşturulamadı: ' . $dizin);
        return null;
    }

    // 30 günden eski ekleri temizle
    foreach ((array)glob($dizin . '/*') as $eski) {
        if (is_file($eski) && filemtime($eski) < strtotime('-30 days')) @unlink($eski);
    }

    $temizAd = preg_replace('/[^A-Za-z0-9._-]/', '_', $dosyaAdi) ?: 'belge.pdf';
    $ad      = date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6) . '-' . $temizAd;
    $yol     = $dizin . '/' . $ad;

    $ham = base64_decode($base64, true);
    if ($ham === false || @file_put_contents($yol, $ham) === false) {
        waLog('Ek diske yazılamadı: ' . $ad);
        return null;
    }

    return ['ek_dosya' => $ad, 'ek_ad' => $dosyaAdi, 'ek_mime' => $mimetype];
}

/** Türkçe karakter güvenli büyük harf (I/İ sorunu için). */
function waUpper(string $s): string
{
    return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $s), 'UTF-8');
}

// ─── Güvenlik ────────────────────────────────────────────────────────────────
// Anahtar kodda tutulmaz: aktif WhatsApp entegrasyonlarının
// Entegrasyonlar_WebhookVerifyToken alanından okunur.
$db  = Database::getInstance();

// Evolution'da "Webhook by Events" açıksa URL'in sonuna olay adı eklenir
// (?key=SECRET/messages-upsert). Anahtarı ilk bölümden alıyoruz ki ayar açık kalsa da çalışsın.
$key = explode('/', (string)($_GET['key'] ?? ''))[0];

$tokenlar = array_filter(array_column($db->fetchAll("
    SELECT Entegrasyonlar_WebhookVerifyToken AS Token
    FROM Entegrasyonlar
    WHERE Entegrasyonlar_Tip = 'whatsapp' AND Durum = 1
      AND Entegrasyonlar_WebhookVerifyToken IS NOT NULL
      AND LEN(Entegrasyonlar_WebhookVerifyToken) > 0
") ?: [], 'Token'));

$gecerli = false;
foreach ($tokenlar as $t) {
    if (hash_equals((string)$t, $key)) { $gecerli = true; break; }
}

if (!$tokenlar) {
    waLog('503 - WhatsApp entegrasyonunda WebhookVerifyToken tanimli degil.');
    waCikis(503, ['success' => false, 'message' => 'Webhook anahtarı tanımlı değil.']);
}
if (!$gecerli) {
    waLog('403 - gecersiz key, IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    waCikis(403, ['success' => false, 'message' => 'Yetkisiz.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    waCikis(200, ['success' => true, 'message' => 'WhatsApp webhook aktif.']);
}

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    waLog('Gecersiz JSON: ' . substr($raw, 0, 300));
    waCikis(400, ['success' => false, 'message' => 'Geçersiz JSON.']);
}

// ─── Olay ayrıştırma ─────────────────────────────────────────────────────────
$olay = strtolower(str_replace('.', '_', (string)($payload['event'] ?? '')));
if ($olay !== 'messages_upsert') {
    waCikis(200, ['success' => true, 'message' => 'İlgisiz olay: ' . $olay]);
}

// Evolution bazen data'yı tek obje, bazen dizi olarak yollar
$data     = $payload['data'] ?? [];
$mesajlar = isset($data['key']) ? [$data] : (is_array($data) ? $data : []);

$sonuclar = [];

foreach ($mesajlar as $m) {
    if (!is_array($m) || !isset($m['key'])) continue;
    if (!empty($m['key']['fromMe'])) continue;                    // kendi gönderdiğimiz mesaj

    // WhatsApp bireysel sohbetlerde artık telefon yerine LID (@lid) gönderebiliyor.
    // Bu yüzden tek alana güvenmeyip payload'daki tüm kimlik alanlarını topluyoruz.
    $kimlikler = array_values(array_unique(array_filter(array_map('waNormalizeJid', [
        $m['key']['remoteJid']      ?? null,
        $m['key']['remoteJidAlt']   ?? null,
        $m['key']['senderPn']       ?? null,
        $m['key']['participant']    ?? null,
        $m['key']['participantPn']  ?? null,
        $m['key']['participantAlt'] ?? null,
        $m['senderPn']              ?? null,
    ]))));

    // Payload'da yalnız LID geldiyse bilinen eşleşmeden numarayı da ekle (ve tersi)
    foreach ($kimlikler as $k) {
        if (isset(WA_LID_NUMARA[$k])) $kimlikler[] = WA_LID_NUMARA[$k];
        $lid = array_search($k, WA_LID_NUMARA, true);
        if ($lid !== false) $kimlikler[] = $lid;
    }
    $kimlikler = array_values(array_unique($kimlikler));

    $gonderen = $kimlikler[0] ?? '';
    if ($gonderen === '') continue;

    $icerik = $m['message'] ?? [];
    $metin  = (string)($icerik['conversation']
              ?? $icerik['extendedTextMessage']['text']
              ?? $icerik['imageMessage']['caption']
              ?? $icerik['documentMessage']['caption']
              ?? $icerik['documentWithCaptionMessage']['message']['documentMessage']['caption']
              ?? '');

    $belge = $icerik['documentMessage']
             ?? $icerik['documentWithCaptionMessage']['message']['documentMessage']
             ?? null;

    // Yalnız PDF tetikleyici sayılır (dekont). Gruba düşen Word/Excel/görsel
    // aşamayı ilerletmesin diye tür kontrolü yapılıyor.
    $belgeVar = is_array($belge) && (
        str_contains(strtolower((string)($belge['mimetype'] ?? '')), 'pdf')
        || str_ends_with(strtolower((string)($belge['fileName'] ?? '')), '.pdf')
    );

    waLog(sprintf('GELEN | %s | %s | %s',
        implode(',', $kimlikler),
        $belgeVar ? ('belge:' . ($belge['mimetype'] ?? '?')) : 'metin',
        mb_substr(str_replace("\n", ' ', $metin), 0, 80)));

    if ($metin === '' && !$belgeVar) continue;                    // ilgilenmediğimiz tür

    // ── Bu hedefe bağlı, aktif aşaması olan kurallar ────────────────────────
    $adaylar = $db->fetchAll("
        SELECT k.CronHatirlatmaKurallari_Id      AS KuralId,
               k.CronHatirlatmaKurallari_Ad      AS KuralAd,
               a.CronHatirlatmaAsamalari_Id      AS AsamaId,
               a.CronHatirlatmaAsamalari_Ad      AS AsamaAd,
               a.CronHatirlatmaAsamalari_HedefNo AS HedefNo,
               a.CronHatirlatmaAsamalari_AnahtarKelime AS AnahtarKelime
        FROM CronHatirlatmaKurallari k
        INNER JOIN CronHatirlatmaAsamalari a
                ON k.CronHatirlatmaKurallari_AktifAsamaId = a.CronHatirlatmaAsamalari_Id
        WHERE k.Durum = 1 AND a.Durum = 1
    ") ?: [];

    foreach ($adaylar as $aday) {
        if (!in_array(waNormalizeJid($aday['HedefNo']), $kimlikler, true)) continue;

        $tetikleyiciler = hatirlatmaAsamaTetikleyicileri($db, (int)$aday['AsamaId']);
        $kod = null;

        // Belge (PDF vb.) → 'belge'
        if ($belgeVar && in_array('belge', $tetikleyiciler, true)) {
            $kod = 'belge';
        }

        // Metin + anahtar kelime → 'whatsapp'
        if ($kod === null && $metin !== '' && in_array('whatsapp', $tetikleyiciler, true)) {
            $anahtar = trim((string)($aday['AnahtarKelime'] ?? ''));
            if ($anahtar !== '' && str_contains(waUpper($metin), waUpper($anahtar))) {
                $kod = 'whatsapp';
            }
        }

        if ($kod === null) continue;

        // Belgeyle ilerliyorsak dosyayı sakla; sonraki aşama "eki de ilet" seçiliyse kullanır
        $veri = [];
        if ($kod === 'belge') {
            $b64 = waBelgeBase64($db, $m, $belge);
            if ($b64 !== null) {
                $meta = waBelgeKaydet($b64,
                    (string)($belge['fileName'] ?? 'belge.pdf'),
                    (string)($belge['mimetype'] ?? 'application/pdf'));
                if ($meta) {
                    $veri = $meta;
                    waLog('Ek kaydedildi: ' . $meta['ek_dosya']);
                }
            } else {
                waLog('Belge içeriği alınamadı; aşama yine de ilerletiliyor.');
            }
        }

        $r = hatirlatmaAsamaIlerlet($db, (int)$aday['KuralId'], $kod, WA_SISTEM_KULLANICI, $veri);
        $sonuclar[] = [
            'kural'       => $aday['KuralAd'],
            'asama'       => $aday['AsamaAd'],
            'tetikleyici' => $kod,
            'ok'          => $r['ok'],
            'mesaj'       => $r['mesaj'],
        ];
        waLog(sprintf('%s | %s → %s | %s | %s',
            $gonderen, $aday['KuralAd'], $kod, $r['ok'] ? 'OK' : 'RED', $r['mesaj']));
    }
}

waCikis(200, ['success' => true, 'islenen' => count($sonuclar), 'sonuclar' => $sonuclar]);
