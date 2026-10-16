<?php
/**
 * Admin Panel - Net Kazanç
 *
 * Dönem + birim için: Hakediş − (Personel Maaşları + Reklam Gideri) = Net Kazanç
 *
 *  - Hakediş: hakedis-hesaplama.php ile aynı çekirdek (HakedisHesap.php → hakedisHesapla).
 *  - Birim seçilirse yalnız o birim (alt birimler dahil edilmez).
 *  - Maaş: seçilen birimdeki aktif personelin kullanici_maas_tutari × seçilen ay sayısı.
 *  - Reklam: her dönemin tarih aralığında Meta insights spend (KDV hariç); hesap → birim
 *    eşleşmesi guncel-borc-bakiyeleri.php ile aynı (KullaniciBirimYetkileri, bugün geçerli yetki).
 *  Yeni tablo tutulmaz, anlık hesaplanır.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/HakedisHesap.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Net Kazanç';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

$AYLAR = [1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran',
          7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'];

// ── Birim görünürlük kısıtı (birim_gor): admin değil + can_view_birim → kendi birimi + alt birimleri ──
$birimKisitli      = (!$permissions['is_admin'] && !empty($permissions['can_view_birim']));
$izinliBirimIdleri = [];
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
        $izinliBirimIdleri = array_map(fn($r) => (int)$r['KullaniciBirim_id'], $rows);
    }
    // Birim atanmamış kısıtlı kullanıcı → boş liste → hiçbir kayıt görünmez (güvenli varsayılan)
}

/**
 * Hesaba girecek birim id'leri: YALNIZ seçilen birim (alt birimler dahil edilmez), görünürlük kısıtıyla kesişim.
 * null = sınırsız (birim seçilmedi, kısıt yok) · [] = hiçbir birim
 */
function nkEfektifBirimler(int $birimId, bool $kisitli, array $izinli): ?array {
    $secilen = $birimId ? [$birimId] : null;
    if (!$kisitli) return $secilen;
    if (empty($izinli)) return [];
    return $secilen !== null ? array_values(array_intersect($secilen, $izinli)) : $izinli;
}

/**
 * Token'ın erişebildiği tüm reklam hesaplarının [bas, bit] aralığındaki harcaması (KDV hariç, ana birim).
 * Tek istek: insights alan genişletmesi (hesap başına ayrı insights çağrısı yapılmaz).
 *
 * @return array ['rows' => [act => ['ad','cur','spend']], 'err' => ?string]
 */
function nkMetaHarcama(?array $kanal, string $bas, string $bit): array {
    if (!$kanal) return ['rows' => [], 'err' => 'Aktif Meta kanalı bulunamadı (EntegrasyonKanallari)'];

    $baseURL = rtrim((string)$kanal['BaseURL'], '/');
    $token   = (string)$kanal['Token'];
    $fields  = 'id,name,currency,insights.time_range(' . json_encode(['since' => $bas, 'until' => $bit]) . '){spend}';
    $url     = $baseURL . '/me/adaccounts?' . http_build_query([
        'fields'          => $fields,
        'limit'           => 100,
        'access_token'    => $token,
        'appsecret_proof' => hash_hmac('sha256', $token, (string)$kanal['AppSecret']),
    ]);

    $rows = []; $guv = 0;
    while ($url && $guv++ < 30) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_SSL_VERIFYPEER => false]);
        $c   = curl_exec($ch);
        $kod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $j = json_decode((string)$c, true) ?: [];
        if ($err || $kod < 200 || $kod >= 300) {
            return ['rows' => $rows, 'err' => $j['error']['message'] ?? ($err ?: 'HTTP ' . $kod)];
        }
        foreach ($j['data'] ?? [] as $a) {
            $spend = 0.0;
            foreach ($a['insights']['data'] ?? [] as $i) $spend += (float)($i['spend'] ?? 0);
            $rows[(string)$a['id']] = ['ad' => $a['name'] ?? $a['id'], 'cur' => $a['currency'] ?? '', 'spend' => $spend];
        }
        $url = $j['paging']['next'] ?? null;
    }
    return ['rows' => $rows, 'err' => null];
}

// ── AJAX işlemleri ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // Aktif birimler (filtre Select2) — kısıtlı kullanıcıya yalnız izinli birimler
            case 'birimler':
                if ($birimKisitli && empty($izinliBirimIdleri)) {
                    echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $bWhere = "Durum = 1";
                $bParams = [];
                if ($birimKisitli) {
                    $bWhere .= " AND KullaniciBirim_id IN (" . implode(',', array_fill(0, count($izinliBirimIdleri), '?')) . ")";
                    $bParams = $izinliBirimIdleri;
                }
                $list = $db->fetchAll("
                    SELECT KullaniciBirim_id, KullaniciBirim_Adi
                    FROM KullaniciBirim
                    WHERE $bWhere
                    ORDER BY KullaniciBirim_Adi
                ", $bParams);
                echo json_encode(['success' => true, 'data' => $list], JSON_UNESCAPED_UNICODE);
                break;

            // Tek dönem tarih aralığı (tanım varsa tanımdan, yoksa formül)
            case 'donem':
                $yil = (int)($_POST['yil'] ?? 0);
                $ay  = (int)($_POST['ay'] ?? 0);
                if (!$yil || $ay < 1 || $ay > 12) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz yıl/ay']);
                    break;
                }
                $donemTanim = $db->fetchOne("
                    SELECT MIN(CAST(DigiturkHakedisTanimlari_BaslangicTarihi AS DATE)) AS bas,
                           MAX(CAST(DigiturkHakedisTanimlari_BitisTarihi     AS DATE)) AS bit
                    FROM dbo.DigiturkHakedisTanimlari
                    WHERE DigiturkHakedisTanimlari_DonemYil = ? AND DigiturkHakedisTanimlari_DonemAy = ? AND Durum = 1
                ", [$yil, $ay]);
                if ($donemTanim && !empty($donemTanim['bas']) && !empty($donemTanim['bit'])) {
                    $bas = date('Y-m-d', strtotime((string)$donemTanim['bas']));
                    $bit = date('Y-m-d', strtotime((string)$donemTanim['bit']));
                    $kaynak = 'tanim';
                } else {
                    [$bas, $bit] = hakedisDonemTarihleri($yil, $ay);
                    $kaynak = 'formul';
                }
                echo json_encode(['success' => true, 'bas' => $bas, 'bit' => $bit, 'kaynak' => $kaynak]);
                break;

            // Net kazanç: hakediş − (maaş + reklam)
            case 'hesapla':
                // 1) Hakediş — hakedis-hesaplama.php ile aynı çekirdek
                $in = $_POST;
                unset($in['altbayi']);   // net kazanç birim bazlıdır
                // Hakediş her zaman hakedis-hesaplama varsayılanıyla: yalnız AKTIF outlet + Tamamlandı satış
                $in['outlet'] = 'AKTIF';
                $in['satis']  = 'Tamamlandı';
                $hk = hakedisHesapla($in, $db, $birimKisitli, $izinliBirimIdleri);
                if (!$hk['success']) {
                    echo json_encode($hk, JSON_UNESCAPED_UNICODE);
                    break;
                }
                $aySayisi = count($hk['donemler']);

                $efektif = nkEfektifBirimler((int)($_POST['birim'] ?? 0), $birimKisitli, $izinliBirimIdleri);
                $kapsamda = fn($bid) => $efektif === null || ($bid !== null && in_array((int)$bid, $efektif, true));

                $birimAdlari = [];
                foreach ($db->fetchAll("SELECT KullaniciBirim_id, KullaniciBirim_Adi FROM dbo.KullaniciBirim") as $b) {
                    $birimAdlari[(int)$b['KullaniciBirim_id']] = $b['KullaniciBirim_Adi'];
                }

                // Birim özeti: anahtar birim id (0 = birim atanmamış)
                $ozet = [];
                $ozetSatir = function ($bid) use (&$ozet, $birimAdlari) {
                    $k = (int)($bid ?? 0);
                    if (!isset($ozet[$k])) {
                        $ozet[$k] = [
                            'birimId' => $k ?: null,
                            'birim'   => $k ? ($birimAdlari[$k] ?? ('#' . $k)) : '(Birim atanmamış)',
                            'hakedis' => 0.0, 'adet' => 0,
                            'maas' => 0.0, 'personel' => 0, 'maasEksik' => 0,
                            'reklam' => 0.0,
                        ];
                    }
                    return $k;
                };

                $hakedisDetay = [];
                $hakedisEksik = 0;
                foreach ($hk['data'] as $r) {
                    // hakedisHesapla birim ağacını (alt birimler) döner; net kazançta yalnız seçilen birim
                    if (!$kapsamda($r['birimId'])) continue;
                    $k = $ozetSatir($r['birimId']);
                    $ozet[$k]['hakedis'] += (float)$r['hakedis'];
                    $ozet[$k]['adet']    += (int)$r['toplam'];
                    if ($r['eksik']) $hakedisEksik++;
                    $hakedisDetay[] = [
                        'donemYil' => $r['donemYil'], 'donemAy' => $r['donemAy'],
                        'birim'    => $ozet[$k]['birim'],
                        'bayi'     => $r['bayiEtiket'],
                        'adet'     => (int)$r['toplam'],
                        'hakedis'  => round((float)$r['hakedis'], 2),
                        'eksik'    => (bool)$r['eksik'],
                    ];
                }

                // 2) Personel maaşları — aktif personel, aylık maaş × seçilen ay sayısı
                $pWhere = "k.kullanici_durum = 1";
                $pParams = [];
                if ($efektif !== null) {
                    if (empty($efektif)) {
                        $pWhere .= " AND 1 = 0";
                    } else {
                        $pWhere .= " AND k.kullanici_birim_id IN (" . implode(',', array_fill(0, count($efektif), '?')) . ")";
                        $pParams = $efektif;
                    }
                }
                $personeller = $db->fetchAll("
                    SELECT k.kullanici_id, k.kullanici_ad, k.kullanici_soyad,
                           k.kullanici_birim_id, k.kullanici_maas_tutari
                    FROM kullanicilar k
                    WHERE $pWhere
                    ORDER BY k.kullanici_ad, k.kullanici_soyad
                ", $pParams);

                $maasDetay = [];
                foreach ($personeller as $p) {
                    $k = $ozetSatir($p['kullanici_birim_id']);
                    $aylik = $p['kullanici_maas_tutari'] !== null ? (float)$p['kullanici_maas_tutari'] : null;
                    $toplam = ($aylik ?? 0) * $aySayisi;
                    $ozet[$k]['maas']     += $toplam;
                    $ozet[$k]['personel'] += 1;
                    if ($aylik === null) $ozet[$k]['maasEksik']++;
                    $maasDetay[] = [
                        'ad'     => trim($p['kullanici_ad'] . ' ' . $p['kullanici_soyad']),
                        'birim'  => $ozet[$k]['birim'],
                        'aylik'  => $aylik,
                        'ay'     => $aySayisi,
                        'toplam' => round($toplam, 2),
                    ];
                }

                // 3) Reklam giderleri — hesap → birim eşleşmesi (bugün geçerli yetki)
                $kanal = $db->fetchOne("
                    SELECT k.EntegrasyonKanallari_Sifre AS Token,
                           e.Entegrasyonlar_BaseURL     AS BaseURL,
                           e.Entegrasyonlar_ApiKey      AS AppSecret
                    FROM EntegrasyonKanallari k
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE e.Entegrasyonlar_Tip = 'meta' AND k.Durum = 1 AND e.Durum = 1
                    ORDER BY k.EntegrasyonKanallari_id
                ");
                $hesapBirim = [];
                foreach ($db->fetchAll("
                    SELECT rh.ReklamHesaplari_HesapID AS HesapID, yetki.Birim_id
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
                    WHERE rh.Durum = 1
                ") as $r) {
                    if (!empty($r['Birim_id'])) {
                        $hesapBirim[preg_replace('/[^0-9]/', '', (string)$r['HesapID'])] = (int)$r['Birim_id'];
                    }
                }

                $reklamDetay = [];
                $reklamHata  = [];
                $bugun = date('Y-m-d');
                foreach ($hk['donemler'] as $d) {
                    $bas = DateTime::createFromFormat('!d.m.Y', $d['bas'])->format('Y-m-d');
                    $bit = DateTime::createFromFormat('!d.m.Y', $d['bit'])->format('Y-m-d');
                    if ($bit > $bugun) $bit = $bugun;
                    if ($bas > $bit) continue;   // dönem henüz başlamadı

                    $mh = nkMetaHarcama($kanal, $bas, $bit);
                    if ($mh['err']) $reklamHata[] = $AYLAR[$d['ay']] . ' ' . $d['yil'] . ': ' . $mh['err'];

                    foreach ($mh['rows'] as $act => $a) {
                        if ($a['spend'] <= 0) continue;
                        $bid = $hesapBirim[preg_replace('/[^0-9]/', '', $act)] ?? null;
                        if (!$kapsamda($bid)) continue;
                        // Kısıtlı kullanıcı birimsiz hesabı göremez ($kapsamda null'ı reddeder)
                        $k = $ozetSatir($bid);
                        $ozet[$k]['reklam'] += $a['spend'];   // Meta spend KDV hariçtir
                        $reklamDetay[] = [
                            'donemYil' => $d['yil'], 'donemAy' => $d['ay'],
                            'act'      => $act,
                            'ad'       => $a['ad'],
                            'birim'    => $ozet[$k]['birim'],
                            'harcama'  => round($a['spend'], 2),
                        ];
                    }
                }

                // 4) Net
                foreach ($ozet as &$o) {
                    $o['hakedis'] = round($o['hakedis'], 2);
                    $o['maas']    = round($o['maas'], 2);
                    $o['reklam']  = round($o['reklam'], 2);
                    $o['gider']   = round($o['maas'] + $o['reklam'], 2);
                    $o['net']     = round($o['hakedis'] - $o['gider'], 2);
                }
                unset($o);
                $ozet = array_values($ozet);
                // Birim adına göre; "(Birim atanmamış)" en sonda
                usort($ozet, fn($a, $b) => [$a['birimId'] === null, $a['birim']] <=> [$b['birimId'] === null, $b['birim']]);

                echo json_encode([
                    'success'      => true,
                    'ozet'         => $ozet,
                    'hakedis'      => $hakedisDetay,
                    'maas'         => $maasDetay,
                    'reklam'       => $reklamDetay,
                    'donemler'     => $hk['donemler'],
                    'aySayisi'     => $aySayisi,
                    'hakedisEksik' => $hakedisEksik,
                    'reklamHata'   => $reklamHata ? implode(' | ', $reklamHata) : null,
                ], JSON_UNESCAPED_UNICODE);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/Admin/assets/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">

    <style>
        .donem-badge { background:#e9ecef; color:#495057; padding:.2rem .5rem; border-radius:.3rem; font-size:.8rem; font-family:monospace; }
        .eksik-badge { background:#fff3cd; color:#997404; padding:.15rem .4rem; border-radius:.3rem; font-size:.75rem; }
        .tutar-arti  { font-weight:600; color:#198754; }
        .tutar-eksi  { font-weight:600; color:#dc3545; }
        .tutar-gider { color:#b02a37; }
        #netKazancKutu.negatif { background-color: var(--bs-danger) !important; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6">
                        <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?>
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <?php endif; ?>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-cash-stack"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Hakediş (₺)</span>
                                <span class="info-box-number" id="stat-hakedis">0,00</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-people"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Personel Maaşları (₺) <small id="stat-personel"></small></span>
                                <span class="info-box-number" id="stat-maas">0,00</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-megaphone"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Reklam Gideri (KDV hariç, ₺)</span>
                                <span class="info-box-number" id="stat-reklam">0,00</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary" id="netKazancKutu">
                            <span class="info-box-icon"><i class="bi bi-graph-up-arrow"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Net Kazanç (₺)</span>
                                <span class="info-box-number" id="stat-net">0,00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hesaplama Filtresi -->
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-calculator"></i> Hesaplama</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filtreGovde" aria-expanded="true">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body collapse show" id="filtreGovde">
                        <form id="hesapForm">
                            <!-- 1) Dönem -->
                            <div class="row g-3 align-items-end">
                                <div class="col-md-2">
                                    <label class="form-label">Yıl <span class="text-danger">*</span></label>
                                    <select class="form-select" name="yil" id="h_yil"></select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Ay <span class="text-danger">*</span> <small class="text-muted">(çoklu)</small></label>
                                    <select class="form-select" name="ay[]" id="h_ay" multiple></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Başlangıç</label>
                                    <input type="date" class="form-control" name="bas" id="h_bas">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Bitiş</label>
                                    <input type="date" class="form-control" name="bit" id="h_bit">
                                </div>
                            </div>
                            <!-- 2) Birim + hakediş kriterleri -->
                            <div class="row g-3 align-items-end mt-0">
                                <div class="col-md-8">
                                    <label class="form-label">Birim</label>
                                    <select class="form-select" name="birim" id="h_birim"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-calculator"></i> Hesapla</button>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-12">
                                    <div class="alert alert-secondary mb-0 py-2 px-3">
                                        <i class="bi bi-calendar-range"></i> Dönem: <strong id="h_donem_text">-</strong>
                                        <span class="text-muted ms-2 small">(hakediş MemoKapanışTarihi'ne, reklam harcaması aynı tarih aralığına göre)</span>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div id="uyarilar"></div>

                <!-- Birim Bazında Net Kazanç -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-diagram-3"></i> Birim Bazında Net Kazanç</h3>
                    </div>
                    <div class="card-body">
                        <table id="ozetTable" class="table table-bordered table-striped table-hover nowrap w-100">
                            <thead>
                                <tr>
                                    <th>Birim</th>
                                    <th class="text-end">Hakediş Adedi</th>
                                    <th class="text-end">Hakediş (₺)</th>
                                    <th class="text-end">Personel</th>
                                    <th class="text-end">Maaş (₺)</th>
                                    <th class="text-end">Reklam (KDV hariç, ₺)</th>
                                    <th class="text-end">Toplam Gider (₺)</th>
                                    <th class="text-end">Net Kazanç (₺)</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                            <tfoot>
                                <tr class="table-secondary fw-bold">
                                    <th class="text-end">GENEL TOPLAM</th>
                                    <th class="text-end" id="ft-adet">0</th>
                                    <th class="text-end" id="ft-hakedis">0,00</th>
                                    <th class="text-end" id="ft-personel">0</th>
                                    <th class="text-end" id="ft-maas">0,00</th>
                                    <th class="text-end" id="ft-reklam">0,00</th>
                                    <th class="text-end" id="ft-gider">0,00</th>
                                    <th class="text-end" id="ft-net">0,00</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Detaylar -->
                <div class="card">
                    <div class="card-header p-0 border-bottom-0">
                        <ul class="nav nav-tabs" role="tablist">
                            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabHakedis" type="button"><i class="bi bi-cash-stack"></i> Hakediş <span class="badge text-bg-secondary" id="say-hakedis">0</span></button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabMaas" type="button"><i class="bi bi-people"></i> Personel Maaşları <span class="badge text-bg-secondary" id="say-maas">0</span></button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabReklam" type="button"><i class="bi bi-megaphone"></i> Reklam Giderleri <span class="badge text-bg-secondary" id="say-reklam">0</span></button></li>
                        </ul>
                    </div>
                    <div class="card-body tab-content">
                        <div class="tab-pane fade show active" id="tabHakedis">
                            <table id="hakedisTable" class="table table-sm table-bordered table-striped table-hover nowrap w-100">
                                <thead><tr><th>Dönem</th><th>Birim</th><th>Alt Bayi</th><th class="text-end">Adet</th><th class="text-end">Hakediş (₺)</th></tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <div class="tab-pane fade" id="tabMaas">
                            <table id="maasTable" class="table table-sm table-bordered table-striped table-hover nowrap w-100">
                                <thead><tr><th>Personel</th><th>Birim</th><th class="text-end">Aylık Maaş (₺)</th><th class="text-end">Ay</th><th class="text-end">Toplam (₺)</th></tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <div class="tab-pane fade" id="tabReklam">
                            <table id="reklamTable" class="table table-sm table-bordered table-striped table-hover nowrap w-100">
                                <thead><tr><th>Dönem</th><th>Reklam Hesabı</th><th>Birim</th><th class="text-end">Harcama (KDV hariç, ₺)</th></tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info small mt-3">
                    <b>Hesaplama:</b> Net Kazanç = Hakediş − (Personel Maaşları + Reklam Gideri).
                    <b>Hakediş</b> Hakediş Hesaplama sayfasıyla aynı kurallarla, Outlet Durumu <b>AKTIF</b> ve Satış Durumu <b>Tamamlandı</b> kayıtlardan hesaplanır.
                    <b>Maaş</b> = birimdeki aktif personelin aylık maaş tutarı × seçilen ay sayısı (maaşı girilmemiş personel 0 sayılır).
                    <b>Reklam</b> = her dönemin tarih aralığındaki Meta harcaması (KDV hariç; Meta API vergi oranı/tutarı vermez). Hesabın birimi bugün geçerli birim yetkisinden bulunur.
                    Birim seçilirse yalnız o birim hesaplanır, alt birimler dahil edilmez.
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
    const AYLAR = <?= json_encode($AYLAR, JSON_UNESCAPED_UNICODE) ?>;
    const DT_DIL = { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' };
    // Dönem sütunu: değer YYYYAA sayısı (sıralama), görüntü badge
    const donemKolon = {
        targets: 0,
        render: function (data, type) {
            const yil = Math.floor(data / 100), ay = data % 100;
            if (type === 'display') return `<span class="donem-badge">${escapeHtml(AYLAR[ay])} ${yil}</span>`;
            if (type === 'filter')  return AYLAR[ay] + ' ' + yil;
            return data;
        }
    };
    // Tutar sütunları: sıralama ham sayıyla, görüntü tr-TR
    const tutarRender = cls => (data, type) => type === 'display' || type === 'filter'
        ? (data === null ? '<span class="eksik-badge">girilmemiş</span>' : `<span class="${typeof cls === 'function' ? cls(data) : cls}">${formatTutar(data)}</span>`)
        : (data ?? 0);
    const netCls = v => +v < 0 ? 'tutar-eksi' : 'tutar-arti';

    let ozetDT, hakedisDT, maasDT, reklamDT;

    $(document).ready(function () {
        ozetDT = $('#ozetTable').DataTable({
            language: DT_DIL, dom: 'rt', paging: false, info: false, order: [],
            columnDefs: [
                { targets: [2],    render: tutarRender('tutar-arti') },
                { targets: [4, 5, 6], render: tutarRender('tutar-gider') },
                { targets: [7],    render: tutarRender(netCls) },
                { targets: [1, 3], render: (d, t) => t === 'display' ? (+d).toLocaleString('tr-TR') : d }
            ]
        });
        hakedisDT = $('#hakedisTable').DataTable({
            language: DT_DIL, order: [[0, 'asc'], [1, 'asc'], [2, 'asc']], pageLength: 25,
            columnDefs: [donemKolon, { targets: 4, render: tutarRender('tutar-arti') }]
        });
        maasDT = $('#maasTable').DataTable({
            language: DT_DIL, order: [[1, 'asc'], [0, 'asc']], pageLength: 25,
            columnDefs: [{ targets: [2, 4], render: tutarRender('tutar-gider') }]
        });
        reklamDT = $('#reklamTable').DataTable({
            language: DT_DIL, order: [[0, 'asc'], [3, 'desc']], pageLength: 25,
            columnDefs: [donemKolon, { targets: 3, render: tutarRender('tutar-gider') }]
        });

        // Gizli sekmede kurulan tabloların sütun genişliği sekme açılınca düzeltilir
        $('button[data-bs-toggle="tab"]').on('shown.bs.tab', () => $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust());

        initDonemSelect();
        loadBirimler();
        updateDonemText();
        loadDonemTarih();

        $('#h_yil, #h_ay').on('change', function () {
            updateDonemText();
            loadDonemTarih();
        });

        $('#hesapForm').on('submit', function (e) {
            e.preventDefault();
            hesapla();
        });
    });

    // Aynı elemana ikinci kez select2 uygulanmasını (hayalet dropdown) engeller
    function safeSelect2($el, opts) {
        if ($el.hasClass('select2-hidden-accessible')) $el.select2('destroy');
        return $el.select2(opts);
    }

    function initDonemSelect() {
        // Varsayılan dönem = önceki ay (Ocak ise Aralık + bir önceki yıl)
        const now = new Date();
        let hedefAy  = now.getMonth();
        let hedefYil = now.getFullYear();
        if (hedefAy === 0) { hedefAy = 12; hedefYil -= 1; }

        const buYil = now.getFullYear();
        let yilOpts = '';
        for (let y = buYil + 1; y >= buYil - 3; y--) {
            yilOpts += `<option value="${y}" ${y === hedefYil ? 'selected' : ''}>${y}</option>`;
        }
        $('#h_yil').html(yilOpts);

        let ayOpts = '';
        for (const no in AYLAR) {
            ayOpts += `<option value="${no}" ${parseInt(no) === hedefAy ? 'selected' : ''}>${AYLAR[no]}</option>`;
        }
        $('#h_ay').html(ayOpts);

        safeSelect2($('#h_yil'), { theme: 'bootstrap-5', width: '100%' });
        safeSelect2($('#h_ay'),  { theme: 'bootstrap-5', width: '100%', placeholder: 'Ay seçiniz...', closeOnSelect: false });
    }

    function loadBirimler() {
        $.post('', { action: 'birimler' }, function (r) {
            if (!r.success) return;
            const opts = ['<option value="">Tümü</option>'];
            r.data.forEach(b => opts.push(`<option value="${b.KullaniciBirim_id}">${escapeHtml(b.KullaniciBirim_Adi)}</option>`));
            safeSelect2($('#h_birim').html(opts.join('')), { theme: 'bootstrap-5', width: '100%' });
        }, 'json');
    }

    function donemAralik(yil, ay) {
        let sAy = ay + 1, sYil = yil;
        if (sAy > 12) { sAy = 1; sYil++; }
        return `04.${String(ay).padStart(2,'0')}.${yil} - 03.${String(sAy).padStart(2,'0')}.${sYil}`;
    }

    // Yıl + tek ay seçiliyken dönem tarihini (tanım/formül) getirip inputlara yazar.
    function loadDonemTarih() {
        const yil = $('#h_yil').val();
        const aylar = ($('#h_ay').val() || []).map(Number);

        if (!yil || aylar.length !== 1) {
            $('#h_bas, #h_bit').val('').prop('disabled', true);
            return;
        }
        $('#h_bas, #h_bit').prop('disabled', false);
        $.post('', { action: 'donem', yil: yil, ay: aylar[0] }, function (r) {
            if (r && r.success) {
                $('#h_bas').val(r.bas);
                $('#h_bit').val(r.bit);
            }
        }, 'json');
    }

    function updateDonemText() {
        const yil = parseInt($('#h_yil').val());
        const aylar = ($('#h_ay').val() || []).map(Number).sort((a, b) => a - b);
        if (!yil || aylar.length === 0) { $('#h_donem_text').text('-'); return; }
        if (aylar.length === 1) $('#h_donem_text').text(donemAralik(yil, aylar[0]));
        else $('#h_donem_text').text(`${aylar.length} dönem: ` + aylar.map(a => AYLAR[a]).join(', '));
    }

    function hesapla() {
        const yil = $('#h_yil').val(), aylar = $('#h_ay').val() || [];
        if (!yil || aylar.length === 0) { showToast('Yıl ve en az bir ay seçiniz', 'warning'); return; }

        const bas = (aylar.length === 1 && !$('#h_bas').prop('disabled')) ? ($('#h_bas').val() || '') : '';
        const bit = (aylar.length === 1 && !$('#h_bit').prop('disabled')) ? ($('#h_bit').val() || '') : '';

        const $btn = $('#hesapForm button[type="submit"]');
        $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Hesaplanıyor...');
        $('#uyarilar').html('');

        $.ajax({
            url: '', method: 'POST', dataType: 'json',
            data: {
                action: 'hesapla', yil, ay: aylar, bas, bit,
                birim: $('#h_birim').val()
            },
            success: function (r) {
                if (!r.success) { showError('Hata!', r.message); return; }

                render(r);

                if (r.donemler && r.donemler.length) {
                    if (r.donemler.length === 1) {
                        const d = r.donemler[0];
                        const kaynakEtiket = { manuel: ' (manuel tarih)', tanim: ' (hakediş tanımından)', formul: ' (formül — tanım yok)' };
                        $('#h_donem_text').text(`${d.bas} - ${d.bit}` + (kaynakEtiket[d.kaynak] || ''));
                    } else {
                        $('#h_donem_text').text(`${r.donemler.length} dönem: ${r.donemler.map(d => AYLAR[d.ay]).join(', ')} ${r.donemler[0].yil}`);
                    }
                }

                const uyari = [];
                if (r.reklamHata)       uyari.push(`<i class="bi bi-exclamation-octagon"></i> Reklam harcaması alınamadı: ${escapeHtml(r.reklamHata)}`);
                if (r.hakedisEksik)     uyari.push(`<i class="bi bi-exclamation-triangle"></i> ${r.hakedisEksik} hakediş satırında tanımsız tutar var (0 sayıldı).`);
                const maasEksik = r.ozet.reduce((t, o) => t + o.maasEksik, 0);
                if (maasEksik)          uyari.push(`<i class="bi bi-exclamation-triangle"></i> ${maasEksik} aktif personelin maaş tutarı girilmemiş (0 sayıldı).`);
                $('#uyarilar').html(uyari.map(u => `<div class="alert alert-warning py-2 px-3 small mb-2">${u}</div>`).join(''));

                if (r.reklamHata) showToast('Reklam gideri eksik olabilir', 'warning');
                else showToast('Net kazanç hesaplandı', 'success');
            },
            error: function () { showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'); },
            complete: function () { $btn.prop('disabled', false).html('<i class="bi bi-calculator"></i> Hesapla'); }
        });
    }

    function render(r) {
        // Birim özeti
        ozetDT.clear();
        const ft = { adet: 0, hakedis: 0, personel: 0, maas: 0, reklam: 0, gider: 0, net: 0 };
        r.ozet.forEach(o => {
            const birimHucre = escapeHtml(o.birim) + (o.maasEksik ? ` <span class="eksik-badge" title="Maaşı girilmemiş personel">${o.maasEksik} maaşsız</span>` : '');
            ozetDT.row.add([birimHucre, o.adet, o.hakedis, o.personel, o.maas, o.reklam, o.gider, o.net]);
            Object.keys(ft).forEach(k => ft[k] += +o[k]);
        });
        ozetDT.draw();

        $('#ft-adet').text(ft.adet.toLocaleString('tr-TR'));
        $('#ft-personel').text(ft.personel.toLocaleString('tr-TR'));
        ['hakedis', 'maas', 'reklam', 'gider'].forEach(k => $('#ft-' + k).text(formatTutar(ft[k])));
        $('#ft-net').html(`<span class="${netCls(ft.net)}">${formatTutar(ft.net)}</span>`);

        // InfoBox
        $('#stat-hakedis').text(formatTutar(ft.hakedis));
        $('#stat-maas').text(formatTutar(ft.maas));
        $('#stat-personel').text(`· ${ft.personel} kişi × ${r.aySayisi} ay`);
        $('#stat-reklam').text(formatTutar(ft.reklam));
        $('#stat-net').text(formatTutar(ft.net));
        $('#netKazancKutu').toggleClass('negatif', ft.net < 0);

        // Detaylar
        hakedisDT.clear();
        r.hakedis.forEach(h => hakedisDT.row.add([
            h.donemYil * 100 + h.donemAy,
            escapeHtml(h.birim),
            escapeHtml(h.bayi) + (h.eksik ? ' <span class="eksik-badge">tanımsız tutar</span>' : ''),
            h.adet,
            h.hakedis
        ]));
        hakedisDT.draw();

        maasDT.clear();
        r.maas.forEach(m => maasDT.row.add([escapeHtml(m.ad), escapeHtml(m.birim), m.aylik, m.ay, m.toplam]));
        maasDT.draw();

        reklamDT.clear();
        r.reklam.forEach(x => reklamDT.row.add([
            x.donemYil * 100 + x.donemAy,
            escapeHtml(x.ad) + `<br><span class="text-muted" style="font-size:11px"><code>${escapeHtml(x.act)}</code></span>`,
            escapeHtml(x.birim),
            x.harcama
        ]));
        reklamDT.draw();

        $('#say-hakedis').text(r.hakedis.length);
        $('#say-maas').text(r.maas.length);
        $('#say-reklam').text(r.reklam.length);
    }

    function formatTutar(v) {
        const n = parseFloat(v || 0);
        return n.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
</script>
</body>
</html>
