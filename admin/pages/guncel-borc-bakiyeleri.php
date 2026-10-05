<?php
/**
 * Admin Panel - Güncel Borç Bakiyeleri
 * İşletme portföyü reklam hesaplarının güncel borç bakiyeleri + ödeme kartı bazında toplamlar.
 * Meta System User token (EntegrasyonKanallari) ile Graph API'den canlı çekilir.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';

requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPagefile = basename($_SERVER['PHP_SELF']);
$pageinfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle       = $pageinfo['sayfalar_sayfa_adi'] ?? 'Güncel Borç Bakiyeleri';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok.');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ── Meta kanalı (token/secret/baseURL) — reklam-yonetimi.php ile aynı kaynak ──
$kanal = $db->fetchOne("
    SELECT k.EntegrasyonKanallari_Sifre AS Token,
           e.Entegrasyonlar_BaseURL     AS BaseURL,
           e.Entegrasyonlar_ApiKey      AS AppSecret
    FROM EntegrasyonKanallari k
    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
    WHERE e.Entegrasyonlar_Tip = 'meta' AND k.Durum = 1 AND e.Durum = 1
    ORDER BY k.EntegrasyonKanallari_id
");

$baseURL = $kanal ? rtrim($kanal['BaseURL'], '/') : '';
$token   = $kanal ? (string)$kanal['Token'] : '';
$secret  = $kanal ? (string)$kanal['AppSecret'] : '';
$kdvOrani = isset($_GET['kdv']) ? (float)$_GET['kdv'] : 20; // % — reklam KDV oranı (varsayılan %20)

// ── Graph yardımcıları ──
function gbProof($t, $s) { return hash_hmac('sha256', $t, $s); }
function gbGet($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false]);
    $c = curl_exec($ch); $kod = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    $j = json_decode($c, true) ?: [];
    return ['ok' => ($kod >= 200 && $kod < 300 && !$err), 'data' => $j, 'kod' => $kod, 'err' => $err];
}
function gbAll($baseURL, $path, $fields, $token, $secret) {
    $params = ['fields' => $fields, 'access_token' => $token, 'appsecret_proof' => gbProof($token, $secret), 'limit' => 200];
    $url = $baseURL . '/' . ltrim($path, '/') . '?' . http_build_query($params);
    $tum = []; $guv = 0; $err = null;
    while ($url && $guv < 50) {
        $r = gbGet($url);
        if (!$r['ok']) { $err = $r['data']['error']['message'] ?? ('HTTP ' . $r['kod']); break; }
        foreach (($r['data']['data'] ?? []) as $x) $tum[] = $x;
        $url = $r['data']['paging']['next'] ?? null; $guv++;
    }
    return ['rows' => $tum, 'err' => $err];
}

$durumAdi = [1 => 'ACTIVE', 2 => 'DISABLED', 3 => 'UNSETTLED', 7 => 'PENDING_RISK_REVIEW', 8 => 'PENDING_SETTLEMENT', 9 => 'IN_GRACE_PERIOD', 100 => 'PENDING_CLOSURE', 101 => 'CLOSED', 201 => 'ANY_ACTIVE', 202 => 'ANY_CLOSED'];

// balance Meta'da hesabın para biriminin ALT biriminde (kuruş) string döner → /100
function gbParaFormat($ham, $currency) {
    if ($ham === null || $ham === '') return '—';
    $val = ((float)$ham) / 100;
    return number_format($val, 2, ',', '.') . ' ' . $currency;
}

/** "Mastercard *1234", "Visa · 4242" → yalnızca sayı ("1234"). Sayı yoksa metni aynen döner. */
function gbKartSade($s) {
    if ($s === null || $s === '') return $s;
    if (preg_match('/(\d{2,})\s*$/', (string)$s, $m)) return $m[1];
    return $s;
}

// Token'ın (System User) atandığı tüm reklam hesapları — portföy ID'si sabitlenmez,
// token hangi portföye bağlıysa onun hesapları gelir (MetaReklamSync::syncHesaplar ile aynı uç)
if ($kanal) {
    $fields   = 'id,name,account_status,currency,balance,amount_spent,spend_cap,funding_source_details,business{id,name}';
    $hesaplar = gbAll($baseURL, 'me/adaccounts', $fields, $token, $secret);
} else {
    $hesaplar = ['rows' => [], 'err' => 'Aktif Meta kanalı bulunamadı (EntegrasyonKanallari)'];
}

// Günlük harcama tablosu borçtan bağımsızdır: borcu 0 olan / durdurulmuş hesaplar da harcamış olabilir
$tumHesaplar = $hesaplar['rows'];

// ── Yalnızca ACTIVE ve borcu > 0 olan hesaplar ──
$sadeceActive = fn($arr) => array_values(array_filter($arr, fn($a) =>
    (int)($a['account_status'] ?? 0) === 1 && (float)($a['balance'] ?? 0) > 0
));
$hesaplar['rows'] = $sadeceActive($hesaplar['rows']);

// ── Gün filtresi (harcama tablosu) — varsayılan son 7 gün, en fazla 93 gün ──
$tarihOku = function ($s) {
    $d = DateTime::createFromFormat('!Y-m-d', (string)$s);
    return ($d && $d->format('Y-m-d') === $s) ? $d : null;
};
$bugun  = new DateTime('today');
$bitTar = $tarihOku($_GET['bit'] ?? '') ?: clone $bugun;
$basTar = $tarihOku($_GET['bas'] ?? '') ?: (clone $bitTar)->modify('-6 days');
if ($bitTar > $bugun) $bitTar = clone $bugun;
if ($basTar > $bitTar) $basTar = clone $bitTar;
if ($basTar->diff($bitTar)->days > 92) $basTar = (clone $bitTar)->modify('-92 days');
$bas = $basTar->format('Y-m-d');
$bit = $bitTar->format('Y-m-d');

/**
 * act_xxx için [gün => harcama] + [gün => lead] — spend ana birimde (TL) ondalık string döner, kuruş değil.
 * Lead: actions içindeki 'lead' tipi (tüm form lead'lerinin toplamı; onsite_conversion.lead_grouped ile aynı).
 */
function gbGunlukHarcama($baseURL, $act, $bas, $bit, $token, $secret) {
    $url = $baseURL . '/' . $act . '/insights?' . http_build_query([
        'fields'          => 'spend,actions',
        'time_range'      => json_encode(['since' => $bas, 'until' => $bit]),
        'time_increment'  => 1,
        'limit'           => 500,
        'access_token'    => $token,
        'appsecret_proof' => gbProof($token, $secret),
    ]);
    $gunler = []; $leadler = []; $guv = 0;
    while ($url && $guv++ < 10) {
        $r = gbGet($url);
        if (!$r['ok']) return ['gunler' => $gunler, 'leadler' => $leadler, 'err' => $r['data']['error']['message'] ?? ('HTTP ' . $r['kod'])];
        foreach ($r['data']['data'] ?? [] as $x) {
            $gunler[$x['date_start']] = (float)($x['spend'] ?? 0);
            foreach ($x['actions'] ?? [] as $ac) {
                if (($ac['action_type'] ?? '') === 'lead') $leadler[$x['date_start']] = (int)$ac['value'];
            }
        }
        $url = $r['data']['paging']['next'] ?? null;
    }
    return ['gunler' => $gunler, 'leadler' => $leadler, 'err' => null];
}

/**
 * Hesap cüzdanındaki anlık "Mevcut Bakiye" (Reklam Yöneticisi → Ödemeler → Bakiye).
 * Graph API bu tutarı hiçbir alanda vermez; activities olaylarından yeniden kurulur:
 *   funding_event_successful ("Bakiyeye para eklendi") → cüzdan += amount
 *   ad_account_billing_charge → KDV'li tutar cüzdana sığıyorsa cüzdandan düşülür, sığmıyorsa karttan çekilmiştir
 * Meta faturayı önce cüzdandan keser; cüzdan yetmezse fatura cüzdanın kalanı kadar bölünür, geri kalanı kart öder.
 * Tüm tutarlar kuruş. Hesaplar curl_multi ile paralel çekilir (hesap başına sayfalar sıralı).
 *
 * @return array act => ['kurus' => int, 'err' => ?string]
 */
function gbCuzdanBakiyeleri($baseURL, array $actler, $token, $secret, $kdvOrani, $gunGeri = 180) {
    $ilkUrl = fn($act) => $baseURL . '/' . $act . '/activities?' . http_build_query([
        'fields'          => 'event_type,event_time,extra_data',
        'since'           => strtotime("-{$gunGeri} days"),
        'limit'           => 500,
        'access_token'    => $token,
        'appsecret_proof' => gbProof($token, $secret),
    ]);
    $olaylar = []; $hata = []; $bekleyen = [];
    foreach ($actler as $act) { $olaylar[$act] = []; $bekleyen[$act] = [$ilkUrl($act), 0]; }

    $mh = curl_multi_init();
    $ekle = function ($act, $url) use ($mh) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_PRIVATE => $act]);
        curl_multi_add_handle($mh, $ch);
    };
    foreach ($bekleyen as $act => [$url]) $ekle($act, $url);

    do {
        curl_multi_exec($mh, $calisan);
        while ($bilgi = curl_multi_info_read($mh)) {
            $ch  = $bilgi['handle'];
            $act = curl_getinfo($ch, CURLINFO_PRIVATE);
            $kod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $j   = json_decode((string)curl_multi_getcontent($ch), true) ?: [];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($kod < 200 || $kod >= 300) { $hata[$act] = $j['error']['message'] ?? ('HTTP ' . $kod); continue; }
            foreach ($j['data'] ?? [] as $x) {
                if (in_array($x['event_type'] ?? '', ['funding_event_successful', 'ad_account_billing_charge'], true)) $olaylar[$act][] = $x;
            }
            $sonraki = $j['paging']['next'] ?? null;
            if ($sonraki && ++$bekleyen[$act][1] < 30) { $ekle($act, $sonraki); $calisan = 1; }
        }
        if ($calisan) curl_multi_select($mh, 1.0);
    } while ($calisan);
    curl_multi_close($mh);

    $sonuc = [];
    foreach ($actler as $act) {
        if (isset($hata[$act])) { $sonuc[$act] = ['kurus' => null, 'err' => $hata[$act]]; continue; }
        $liste = $olaylar[$act];
        usort($liste, fn($a, $b) => strcmp($a['event_time'] ?? '', $b['event_time'] ?? ''));
        $w = 0;
        foreach ($liste as $x) {
            $ek = json_decode($x['extra_data'] ?? '', true) ?: [];
            if ($x['event_type'] === 'funding_event_successful') {
                $w += (int)($ek['amount'] ?? 0);
            } elseif ($w > 0) {
                $kdvli = (int)round((int)($ek['new_value'] ?? 0) * (1 + $kdvOrani / 100));
                if ($kdvli <= $w + 2) $w = max(0, $w - $kdvli);   // 2 kuruş yuvarlama payı
            }
        }
        $sonuc[$act] = ['kurus' => $w, 'err' => null];
    }
    return $sonuc;
}

// ── Birim kısıtı: admin değil + birim_gor=1 ise yalnızca kendi birimi + alt birimleri ──
$birimKisitli   = (!$permissions['is_admin'] && !empty($permissions['can_view_birim']));
$izinliBirimler = [];
if ($birimKisitli) {
    $kb = $db->fetchOne("SELECT kullanici_birim_id FROM kullanicilar WHERE kullanici_id = ?", [$user['kullanici_id']]);
    $kullaniciBirimId = $kb['kullanici_birim_id'] ?? null;
    if ($kullaniciBirimId) {
        $rows = $db->fetchAll("
            WITH BirimAgaci AS (
                SELECT KullaniciBirim_id FROM KullaniciBirim WHERE KullaniciBirim_id = ?
                UNION ALL
                SELECT b.KullaniciBirim_id FROM KullaniciBirim b
                INNER JOIN BirimAgaci a ON b.KullaniciBirim_UstBirim_id = a.KullaniciBirim_id
            )
            SELECT KullaniciBirim_id FROM BirimAgaci
        ", [$kullaniciBirimId]);
        $izinliBirimler = array_map(fn($r) => (int)$r['KullaniciBirim_id'], $rows);
    }
    // birim atanmamış kısıtlı kullanıcı → $izinliBirimler boş → hiçbir hesap görünmez (güvenli varsayılan)
}

// ── Hesap (act_xxx) → Birim adı + Birim_id eşleşmesi (MetaReklamSync::hesapBirimMap mantığı) ──
$birimRows = $db->fetchAll("
    SELECT rh.ReklamHesaplari_HesapID AS HesapID,
           yetki.Birim_id,
           kb.KullaniciBirim_Adi AS Birim
    FROM ReklamHesaplari rh
    OUTER APPLY (
        SELECT TOP 1 kby.KullaniciBirimYetkileri_Birim_id AS Birim_id
        FROM KullaniciBirimYetkileri kby
        WHERE kby.KullaniciBirimYetkileri_ReklamHesap_id = rh.ReklamHesaplari_id
          AND kby.Durum = 1
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
        ORDER BY kby.KullaniciBirimYetkileri_id
    ) yetki
    LEFT JOIN KullaniciBirim kb ON kb.KullaniciBirim_id = yetki.Birim_id
    WHERE rh.Durum = 1
");
$birimMap = []; $birimIdMap = [];
foreach ($birimRows as $r) {
    $hid = (string)$r['HesapID'];
    if (!empty($r['Birim']))    $birimMap[$hid]   = $r['Birim'];
    if (!empty($r['Birim_id'])) $birimIdMap[$hid] = (int)$r['Birim_id'];
}
$mapBul = function ($map, $actId) {
    $actId = (string)$actId;
    if (isset($map[$actId])) return $map[$actId];
    $num = preg_replace('/[^0-9]/', '', $actId);
    return $map['act_' . $num] ?? ($map[$num] ?? null);
};
$birimBul   = fn($actId) => $mapBul($birimMap, $actId);
$birimIdBul = fn($actId) => $mapBul($birimIdMap, $actId);

// ── Birim kısıtı uygulaması: yalnızca izinli birimlerdeki hesaplar ──
if ($birimKisitli) {
    $birimFiltre = function ($arr) use ($birimIdBul, $izinliBirimler) {
        return array_values(array_filter($arr, function ($a) use ($birimIdBul, $izinliBirimler) {
            $bid = $birimIdBul($a['id'] ?? '');
            return $bid !== null && in_array((int)$bid, $izinliBirimler, true);
        }));
    };
    $hesaplar['rows'] = $birimFiltre($hesaplar['rows']);
    $tumHesaplar      = $birimFiltre($tumHesaplar);
}

// ── AJAX: hesap × gün tablosu — seçili günler (tekli/çoklu, varsayılan bugün) ──
if (($_GET['action'] ?? '') === 'gunluk') {
    header('Content-Type: application/json; charset=utf-8');
    $bugunStr = $bugun->format('Y-m-d');
    $secili = [];
    foreach (explode(',', (string)($_GET['gunler'] ?? '')) as $g) {
        $g = trim($g);
        if ($tarihOku($g) && $g <= $bugunStr) $secili[$g] = true;
    }
    if (!$secili) $secili[$bugunStr] = true;
    ksort($secili);
    $gunListe = array_keys($secili);
    $dBas = reset($gunListe); $dBit = end($gunListe);
    // Tek insights çağrısı min→max aralığını kapsar; 93 günü aşan seçim reddedilir
    if ((new DateTime($dBas))->diff(new DateTime($dBit))->days > 92) {
        echo json_encode(['data' => [], 'hata' => 'Seçilen ilk ve son gün arası en fazla 93 gün olabilir.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $satirlar = []; $hatalar = [];
    foreach ($tumHesaplar as $a) {
        $act = $a['id'] ?? '';
        if ($act === '') continue;
        $h = gbGunlukHarcama($baseURL, $act, $dBas, $dBit, $token, $secret);
        if ($h['err']) { $hatalar[] = ($a['name'] ?? $act) . ': ' . $h['err']; continue; }
        foreach ($gunListe as $gun) {
            $sp = $h['gunler'][$gun] ?? 0.0;
            $ld = $h['leadler'][$gun] ?? 0;
            if ($sp <= 0 && $ld <= 0) continue;
            $satirlar[] = [
                'gun'     => $gun,
                'act'     => $act,
                'ad'      => $a['name'] ?? $act,
                'cur'     => $a['currency'] ?? '',
                'birim'   => $birimBul($act),
                'harcama' => round($sp * (1 + $kdvOrani / 100), 2),   // KDV dahil
                'lead'    => $ld,
            ];
        }
    }
    echo json_encode(['data' => $satirlar, 'gunler' => $gunListe, 'hata' => $hatalar ? implode(' | ', $hatalar) : null], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AJAX: detay tablolarındaki hesapların cüzdan (Mevcut Bakiye) tutarları — activities geçmişi uzun, sayfayı bekletmez ──
if (($_GET['action'] ?? '') === 'cuzdan') {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // gunluk isteğiyle paralel çalışabilsin
    set_time_limit(180);
    header('Content-Type: application/json; charset=utf-8');
    $actler = array_values(array_filter(array_map(fn($a) => $a['id'] ?? '', $hesaplar['rows'])));
    $cuzdan = $actler ? gbCuzdanBakiyeleri($baseURL, $actler, $token, $secret, $kdvOrani) : [];
    $cikti  = [];
    foreach ($hesaplar['rows'] as $a) {
        $c = $cuzdan[$a['id'] ?? ''] ?? null;
        if (!$c) continue;
        $bakiye = $c['kurus'] === null ? null : $c['kurus'] / 100;
        $borcKdv = ((float)($a['balance'] ?? 0)) / 100 * (1 + $kdvOrani / 100);
        $cikti[$a['id']] = [
            'bakiye'       => $bakiye,
            'kullanilabilir' => $bakiye === null ? null : round($bakiye - $borcKdv, 2),   // Meta: "bir sonraki bakiye ödemenizden sonra"
            'cur'          => $a['currency'] ?? '',
            'hata'         => $c['err'],
        ];
    }
    echo json_encode(['data' => $cikti], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Günlük harcamalar: gün × hesap matrisi (harcaması olmayan hesaplar gizlenir) ──
$harcamaGunleri = [];
for ($d = clone $bitTar; $d >= $basTar; $d->modify('-1 day')) $harcamaGunleri[$d->format('Y-m-d')] = [];
$harcamaHesaplari = [];   // act => ['ad','cur','toplam']
$harcamaHatalari  = [];   // hesap adı => hata
$leadToplam       = 0;
foreach ($tumHesaplar as $a) {
    $act = $a['id'] ?? '';
    if ($act === '') continue;
    $h = gbGunlukHarcama($baseURL, $act, $bas, $bit, $token, $secret);
    if ($h['err']) { $harcamaHatalari[$a['name'] ?? $act] = $h['err']; continue; }
    $leadToplam += array_sum($h['leadler']);
    $toplam = array_sum($h['gunler']);
    if ($toplam <= 0) continue;
    $harcamaHesaplari[$act] = ['ad' => $a['name'] ?? $act, 'cur' => $a['currency'] ?? '', 'toplam' => $toplam];
    foreach ($h['gunler'] as $gun => $tutar) {
        if (isset($harcamaGunleri[$gun])) $harcamaGunleri[$gun][$act] = $tutar;
    }
}
uasort($harcamaHesaplari, fn($x, $y) => strcmp($x['ad'], $y['ad']));
$harcamaToplam = array_sum(array_column($harcamaHesaplari, 'toplam'));
$gunSayisi     = count($harcamaGunleri);
$harcamaCur    = $harcamaHesaplari ? reset($harcamaHesaplari)['cur'] : 'TRY';

// ── Detay kartları: portföy (business) bazında gruplama ──
$gruplar = [];
foreach ($hesaplar['rows'] as $a) {
    $baslik = isset($a['business']['id'])
        ? ($a['business']['name'] ?? '') . ' (' . $a['business']['id'] . ')'
        : 'Portföye bağlı olmayan hesaplar';
    $gruplar[$baslik][] = $a;
}
ksort($gruplar);

// ── Ödeme Kartı / Kaynağı bazında toplam borç özeti ──
$kartOzet = [];
foreach ($hesaplar['rows'] as $a) {
    $fs   = $a['funding_source_details'] ?? null;
    $kart = gbKartSade($fs['display_string'] ?? ($fs['type'] ?? 'Tanımsız / erişim yok'));
    $cur  = $a['currency'] ?? '';
    $borc = ((float)($a['balance'] ?? 0)) / 100;
    if (!isset($kartOzet[$kart])) $kartOzet[$kart] = ['adet' => 0, 'borc' => 0.0, 'cur' => $cur];
    $kartOzet[$kart]['adet']++;
    $kartOzet[$kart]['borc'] += $borc;
}
uasort($kartOzet, fn($x, $y) => $y['borc'] <=> $x['borc']);
$kartSayisi  = count($kartOzet);
$hesapSayisi = array_sum(array_column($kartOzet, 'adet'));
$genelBorc   = array_sum(array_column($kartOzet, 'borc'));
$genelBorcKdv = $genelBorc * (1 + $kdvOrani / 100);
$genelCur    = $kartOzet ? reset($kartOzet)['cur'] : 'TRY';
$kdvEtiket   = rtrim(rtrim(number_format($kdvOrani, 2, '.', ''), '0'), '.');

$e = fn($s) => htmlspecialchars((string)$s);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($pageTitle) ?> - <?= $e($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/Admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6"><h3 class="mb-0"><?= $e($pageTitle) ?></h3></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?><li class="breadcrumb-item"><?= $e($menuAdi) ?></li><?php endif; ?>
                            <li class="breadcrumb-item active"><?= $e($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <?php if ($hesaplar['err']): ?>
                <div class="alert alert-danger py-2 small">
                    Reklam hesapları çekilemedi: <?= $e($hesaplar['err']) ?>
                </div>
                <?php elseif (!$gruplar): ?>
                <div class="alert alert-secondary py-2 small">Borcu olan aktif reklam hesabı yok.</div>
                <?php endif; ?>

                <!-- Filtre -->
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele
                            <span class="badge text-bg-light ms-2"><?= $e($basTar->format('d.m.Y')) ?> – <?= $e($bitTar->format('d.m.Y')) ?></span>
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body collapse" id="filterCard">
                        <form method="get" id="filterForm">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label" for="filter_bas">Başlangıç</label>
                                    <input type="date" class="form-control" id="filter_bas" name="bas" value="<?= $e($bas) ?>" max="<?= $e($bugun->format('Y-m-d')) ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="filter_bit">Bitiş</label>
                                    <input type="date" class="form-control" id="filter_bit" name="bit" value="<?= $e($bit) ?>" max="<?= $e($bugun->format('Y-m-d')) ?>">
                                </div>
                                <div class="col-md-6 d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-gun-bas="0" data-gun-bit="0">Bugün</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-gun-bas="1" data-gun-bit="1">Dün</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-gun-bas="6" data-gun-bit="0">Son 7 gün</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-gun-bas="29" data-gun-bit="0">Son 30 gün</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bu-ay="1">Bu ay</button>
                                    <button type="submit" class="btn btn-primary btn-sm ms-auto"><i class="bi bi-search"></i> Uygula</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- InfoBox'lar: genel toplamlar + kart/kaynak bazında (hepsi yan yana) -->
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-4 col-sm-6"><div class="info-box text-bg-light"><span class="info-box-icon"><i class="bi bi-graph-up"></i></span><div class="info-box-content"><span class="info-box-text">Harcama (<?= (int)$gunSayisi ?> gün)</span><span class="info-box-number"><?= $e(number_format($harcamaToplam, 2, ',', '.') . ' ' . $harcamaCur) ?></span></div></div></div>
                    <div class="col-lg-3 col-md-4 col-sm-6"><div class="info-box text-bg-light"><span class="info-box-icon"><i class="bi bi-calendar-day"></i></span><div class="info-box-content"><span class="info-box-text">Günlük Ortalama Harcama</span><span class="info-box-number"><?= $e(number_format($gunSayisi ? $harcamaToplam / $gunSayisi : 0, 2, ',', '.') . ' ' . $harcamaCur) ?></span></div></div></div>
                    <div class="col-lg-3 col-md-4 col-sm-6"><div class="info-box text-bg-light"><span class="info-box-icon"><i class="bi bi-person-lines-fill"></i></span><div class="info-box-content"><span class="info-box-text">Gelen Lead (<?= (int)$gunSayisi ?> gün)</span><span class="info-box-number"><?= $e(number_format($leadToplam, 0, ',', '.')) ?></span></div></div></div>
                    <div class="col-lg-3 col-md-4 col-sm-6"><div class="info-box text-bg-light"><span class="info-box-icon"><i class="bi bi-tag"></i></span><div class="info-box-content"><span class="info-box-text">Lead Başı Maliyet</span><span class="info-box-number"><?= $leadToplam ? $e(number_format($harcamaToplam / $leadToplam, 2, ',', '.') . ' ' . $harcamaCur) : '—' ?></span></div></div></div>
                    <div class="col-lg-3 col-md-4 col-sm-6"><div class="info-box text-bg-warning"><span class="info-box-icon"><i class="bi bi-cash-stack"></i></span><div class="info-box-content"><span class="info-box-text">Toplam Borç</span><span class="info-box-number"><?= $e(number_format($genelBorc, 2, ',', '.') . ' ' . $genelCur) ?></span></div></div></div>
                    <div class="col-lg-3 col-md-4 col-sm-6"><div class="info-box text-bg-danger"><span class="info-box-icon"><i class="bi bi-receipt"></i></span><div class="info-box-content"><span class="info-box-text">Toplam Borç + KDV (%<?= $e($kdvEtiket) ?>)</span><span class="info-box-number"><?= $e(number_format($genelBorcKdv, 2, ',', '.') . ' ' . $genelCur) ?></span></div></div></div>
                    <?php
                    $kartRenkleri = ['primary', 'success', 'info', 'secondary', 'dark'];
                    $ri = 0;
                    foreach ($kartOzet as $kart => $o):
                        $renk  = $kartRenkleri[$ri++ % count($kartRenkleri)];
                        $kdvli = $o['borc'] * (1 + $kdvOrani / 100);
                    ?>
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <div class="info-box text-bg-<?= $renk ?>">
                            <span class="info-box-icon"><i class="bi bi-credit-card-2-front"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text"><?= $e($kart) ?> · <?= (int)$o['adet'] ?> hesap</span>
                                <span class="info-box-number"><?= $e(number_format($kdvli, 2, ',', '.') . ' ' . $o['cur']) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Günlük harcamalar: gün × hesap -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h3 class="card-title"><i class="bi bi-calendar3"></i> Günlük Harcamalar · <?= $e($basTar->format('d.m.Y')) ?> – <?= $e($bitTar->format('d.m.Y')) ?></h3>
                        <div class="card-tools"><span class="badge text-bg-light"><?= count($harcamaHesaplari) ?> hesap</span></div>
                    </div>
                    <div class="card-body p-0">
                        <?php foreach ($harcamaHatalari as $hAd => $hMsg): ?>
                            <div class="alert alert-danger m-3 mb-0 py-2 small"><?= $e($hAd) ?>: <?= $e($hMsg) ?></div>
                        <?php endforeach; ?>
                        <?php if (!$harcamaHesaplari): ?>
                            <div class="text-muted m-3">Seçili aralıkta harcama yok.</div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Gün</th>
                                        <?php foreach ($harcamaHesaplari as $act => $hh): ?>
                                            <th class="text-end"><?= $e($hh['ad']) ?></th>
                                        <?php endforeach; ?>
                                        <th class="text-end">Toplam</th>
                                        <th class="text-end">Toplam + KDV (%<?= $e($kdvEtiket) ?>)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($harcamaGunleri as $gun => $satir): $gunToplam = array_sum($satir); ?>
                                    <tr>
                                        <td class="text-nowrap"><?= $e(date('d.m.Y', strtotime($gun))) ?></td>
                                        <?php foreach ($harcamaHesaplari as $act => $hh): ?>
                                            <td class="text-end<?= empty($satir[$act]) ? ' text-muted' : '' ?>"><?= $e(number_format($satir[$act] ?? 0, 2, ',', '.')) ?></td>
                                        <?php endforeach; ?>
                                        <td class="text-end fw-semibold"><?= $e(number_format($gunToplam, 2, ',', '.')) ?></td>
                                        <td class="text-end"><?= $e(number_format($gunToplam * (1 + $kdvOrani / 100), 2, ',', '.')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light fw-bold">
                                    <tr>
                                        <td>Toplam</td>
                                        <?php foreach ($harcamaHesaplari as $hh): ?>
                                            <td class="text-end"><?= $e(number_format($hh['toplam'], 2, ',', '.')) ?></td>
                                        <?php endforeach; ?>
                                        <td class="text-end"><?= $e(number_format($harcamaToplam, 2, ',', '.') . ' ' . $harcamaCur) ?></td>
                                        <td class="text-end"><?= $e(number_format($harcamaToplam * (1 + $kdvOrani / 100), 2, ',', '.') . ' ' . $harcamaCur) ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Hesap × gün: harcama (KDV dahil) + lead — AJAX, varsayılan bugün -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h3 class="card-title"><i class="bi bi-table"></i> Hesap Bazında Günlük Harcama ve Lead · <span id="gunlukBaslik">Bugün</span></h3>
                    </div>
                    <div class="card-body">
                        <div class="row g-2 align-items-end mb-3">
                            <div class="col-md-4">
                                <label class="form-label" for="dt_gunler">Gün(ler)</label>
                                <input type="text" class="form-control" id="dt_gunler" placeholder="Gün seçin (tekli / çoklu)" readonly>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="filter_hesap">Reklam Hesabı</label>
                                <select class="form-select" id="filter_hesap">
                                    <option value="">Tümü</option>
                                    <?php
                                    $hesapAdlari = array_unique(array_filter(array_map(fn($a) => $a['name'] ?? null, $tumHesaplar)));
                                    sort($hesapAdlari, SORT_NATURAL);
                                    foreach ($hesapAdlari as $hAd): ?>
                                        <option value="<?= $e($hAd) ?>"><?= $e($hAd) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5 d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-dt-gun="0">Bugün</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-dt-gun="1">Dün</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-dt-son="7">Son 7 gün</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-dt-son="30">Son 30 gün</button>
                            </div>
                        </div>
                        <table id="gunlukTable" class="table table-sm table-hover table-striped align-middle w-100">
                            <thead class="table-light">
                                <tr>
                                    <th>Gün</th>
                                    <th>Reklam Hesabı</th>
                                    <th>Birim</th>
                                    <th class="text-end">Harcama (KDV dahil %<?= $e($kdvEtiket) ?>)</th>
                                    <th class="text-end">Lead</th>
                                    <th class="text-end">Lead Başı Maliyet</th>
                                </tr>
                            </thead>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="3">Toplam (görünen)</td>
                                    <td class="text-end" id="ftHarcama"></td>
                                    <td class="text-end" id="ftLead"></td>
                                    <td class="text-end" id="ftCpl"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Detay: hesap bazında -->
                <?php foreach ($gruplar as $baslik => $grupSatirlari): $sonuc = ['rows' => $grupSatirlari, 'err' => null]; ?>
                <div class="card mb-4">
                    <div class="card-header bg-dark text-white">
                        <h3 class="card-title"><?= $e($baslik) ?></h3>
                        <div class="card-tools"><span class="badge text-bg-light"><?= $sonuc['err'] ? '—' : count($sonuc['rows']) ?></span></div>
                    </div>
                    <div class="card-body p-0">
                        <?php if ($sonuc['err']): ?>
                            <div class="alert alert-danger m-3 mb-0 py-2 small"><?= $e($sonuc['err']) ?></div>
                        <?php elseif (empty($sonuc['rows'])): ?>
                            <div class="text-muted m-3">Kayıt yok.</div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th><th>Birim</th><th>Ad</th><th>Durum</th>
                                        <th class="text-end">Bakiye (borç)</th>
                                        <th class="text-end">Borç + KDV (%<?= $e($kdvEtiket) ?>)</th>
                                        <th class="text-end">Mevcut Bakiye</th>
                                        <th>Ödeme Kartı / Kaynağı</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($sonuc['rows'] as $i => $a):
                                    $cur = $a['currency'] ?? '';
                                    $st  = $a['account_status'] ?? null;
                                    $fs  = $a['funding_source_details'] ?? null;
                                    $kart = gbKartSade($fs['display_string'] ?? ($fs['type'] ?? null));
                                    $stBadge = in_array($st, [1]) ? 'success' : (in_array($st, [3,7,8,9]) ? 'warning' : 'danger');
                                    $birim = $birimBul($a['id'] ?? '');
                                ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>
                                        <td>
                                            <?php if ($birim): ?><span class="badge text-bg-secondary"><?= $e($birim) ?></span>
                                            <?php else: ?><span class="text-muted fst-italic">atanmamış</span><?php endif; ?>
                                        </td>
                                        <td><?= $e($a['name'] ?? '') ?><br><span class="text-muted" style="font-size:11px"><code><?= $e($a['id'] ?? '') ?></code></span></td>
                                        <td><span class="badge text-bg-<?= $stBadge ?>"><?= $e($durumAdi[$st] ?? (string)$st) ?></span></td>
                                        <td class="text-end"><?= $e(gbParaFormat($a['balance'] ?? null, $cur)) ?></td>
                                        <td class="text-end fw-semibold">
                                            <?php
                                            $borc = ($a['balance'] ?? '') === '' ? null : ((float)$a['balance']) / 100;
                                            echo $borc === null ? '—' : $e(number_format($borc * (1 + $kdvOrani / 100), 2, ',', '.') . ' ' . $cur);
                                            ?>
                                        </td>
                                        <td class="text-end cuzdan-hucre" data-act="<?= $e($a['id'] ?? '') ?>">
                                            <span class="spinner-border spinner-border-sm text-secondary" role="status"></span>
                                        </td>
                                        <td>
                                            <?php if ($kart): ?>
                                                <i class="bi bi-credit-card-2-front"></i> <?= $e($kart) ?>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">tanımsız / erişim yok</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="alert alert-info small">
                    <b>Not:</b> Yalnızca <b>ACTIVE</b> ve borcu <b>0'dan büyük</b> hesaplar listelenir. <code>balance</code> = hesabın ödenmemiş güncel borcu.
                    <b>Borç + KDV</b> = borç × (1 + KDV oranı); Meta ayrı bir vergi tutarı alanı vermediği için oran üzerinden hesaplanır (varsayılan %20).
                    Kart bilgisi <code>funding_source_details</code> alanından gelir; boşsa token o hesabın ödeme kaynağını okuyamıyordur.
                    Veriler Meta Graph API'den <b>canlı</b> çekilir.
                    <b>Günlük harcama</b> Meta insights <code>spend</code> değeridir (KDV hariç); tarih filtresi yalnız harcama tablosunu etkiler, borç her zaman günceldir.
                    Bugünün harcaması Meta tarafında birkaç saat gecikmeli güncellenebilir.
                    <b>Mevcut Bakiye</b> = hesaba yüklenmiş, henüz harcanmamış para (Reklam Yöneticisi → Ödemeler → Bakiye). Meta bu tutarı API'de vermediği için
                    son 180 günün <code>activities</code> olaylarından hesaplanır: "Bakiyeye para eklendi" eklenir, KDV'li tutarı bakiyeye sığan faturalar düşülür.
                    Altındaki tutar, güncel borç + KDV ödendikten sonra kullanılabilir kalan bakiyedir.
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/tr.js"></script>
<script>
// Hesap × gün tablosu — AJAX (?action=gunluk), varsayılan bugün; takvimden tekli/çoklu gün seçilir
$(function () {
    const cur = <?= json_encode($harcamaCur) ?>;
    const tl  = n => Number(n).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + cur;
    const trTarih = s => s.split('-').reverse().join('.');
    const gunOnce = n => { const d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() - n); return d; };

    const fp = flatpickr('#dt_gunler', {
        mode: 'multiple',
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'd.m.Y',
        conjunction: ', ',
        maxDate: 'today',
        locale: 'tr',
        defaultDate: [gunOnce(0)],
        onClose: () => dt.ajax.reload()
    });
    const seciliGunler = () => fp.selectedDates.map(d => fp.formatDate(d, 'Y-m-d')).sort();

    const dt = $('#gunlukTable').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
        dom: 'lrtip',
        processing: true,
        ajax: {
            url: '?action=gunluk',
            data: d => ({ gunler: seciliGunler().join(','), kdv: <?= json_encode((float)$kdvOrani) ?> }),
            dataSrc: function (json) {
                if (json.hata) showToast(json.hata, 'error');
                const g = json.gunler || [];
                $('#gunlukBaslik').text(g.length === 1 ? trTarih(g[0]) : g.length + ' gün (' + trTarih(g[0]) + ' – ' + trTarih(g[g.length - 1]) + ')');
                return json.data;
            },
            error: () => showToast('Günlük harcama verisi alınamadı.', 'error')
        },
        columns: [
            { data: 'gun', className: 'text-nowrap', render: (v, t) => t === 'display' ? trTarih(v) : v },
            { data: 'ad', render: (v, t, r) => t === 'display' ? $('<div>').text(v).html() + '<br><span class="text-muted" style="font-size:11px"><code>' + r.act + '</code></span>' : v },
            { data: 'birim', render: v => v ? '<span class="badge text-bg-secondary">' + $('<div>').text(v).html() + '</span>' : '<span class="text-muted fst-italic">atanmamış</span>' },
            { data: 'harcama', className: 'text-end', render: (v, t) => t === 'display' ? tl(v) : v },
            { data: 'lead', className: 'text-end fw-semibold' },
            { data: null, className: 'text-end', render: (v, t, r) => {
                const cpl = r.lead > 0 ? r.harcama / r.lead : null;
                return t === 'display' ? (cpl === null ? '—' : tl(cpl)) : (cpl ?? -1);
            } }
        ],
        order: [[0, 'desc'], [3, 'desc']],
        pageLength: 25,
        lengthMenu: [25, 50, 100, 500],
        footerCallback: function () {
            const api = this.api();
            const topla = i => api.column(i, { search: 'applied' }).data().toArray().reduce((t, x) => t + (parseFloat(x) || 0), 0);
            const harcama = topla(3), lead = topla(4);
            $('#ftHarcama').text(tl(harcama));
            $('#ftLead').text(lead.toLocaleString('tr-TR'));
            $('#ftCpl').text(lead ? tl(harcama / lead) : '—');
        }
    });

    // Hızlı seçimler
    $('[data-dt-gun]').on('click', function () { fp.setDate([gunOnce(+$(this).data('dt-gun'))]); dt.ajax.reload(); });
    $('[data-dt-son]').on('click', function () {
        const n = +$(this).data('dt-son');
        fp.setDate(Array.from({ length: n }, (_, i) => gunOnce(i)));
        dt.ajax.reload();
    });

    // Hesap filtresi → tablo süzülür (sunucuya gitmez)
    $('#filter_hesap').on('change', function () {
        const v = $(this).val();
        dt.column(1).search(v ? '^' + $.fn.dataTable.util.escapeRegex(v) + '$' : '', true, false).draw();
    });
});
</script>
<script>
// Detay tabloları: Mevcut Bakiye (cüzdan) — AJAX (?action=cuzdan), activities geçmişi uzun olduğu için sonradan dolar
$(function () {
    const $hucreler = $('.cuzdan-hucre');
    if (!$hucreler.length) return;
    const para = (n, cur) => Number(n).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + cur;
    $.getJSON('?action=cuzdan', { kdv: <?= json_encode((float)$kdvOrani) ?> })
        .done(function (json) {
            $hucreler.each(function () {
                const $h = $(this), c = (json.data || {})[$h.data('act')];
                if (!c) { $h.html('<span class="text-muted">—</span>'); return; }
                if (c.hata) { $h.html($('<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> okunamadı</span>').attr('title', c.hata)); return; }
                if (!c.bakiye) { $h.html('<span class="text-muted">—</span>'); return; }
                $h.html('<span class="fw-semibold text-success">' + para(c.bakiye, c.cur) + '</span>'
                    + '<br><span class="text-muted" style="font-size:11px">ödeme sonrası kullanılabilir: ' + para(c.kullanilabilir, c.cur) + '</span>');
            });
        })
        .fail(function () {
            $hucreler.html('<span class="text-danger">—</span>');
            showToast('Mevcut bakiye bilgisi alınamadı.', 'error');
        });
});
</script>
<script>
// Hızlı tarih butonları → alanları doldurup formu gönderir
$(function () {
    const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const gunOnce = n => { const d = new Date(); d.setDate(d.getDate() - n); return d; };
    $('#filterForm [data-gun-bas], #filterForm [data-bu-ay]').on('click', function () {
        const $b = $(this);
        if ($b.data('bu-ay')) {
            const d = new Date();
            $('#filter_bas').val(ymd(new Date(d.getFullYear(), d.getMonth(), 1)));
            $('#filter_bit').val(ymd(d));
        } else {
            $('#filter_bas').val(ymd(gunOnce($b.data('gun-bas'))));
            $('#filter_bit').val(ymd(gunOnce($b.data('gun-bit'))));
        }
        $('#filterForm').trigger('submit');
    });
});
</script>
</body>
</html>
