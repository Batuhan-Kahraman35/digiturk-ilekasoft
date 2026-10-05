<?php
/**
 * Admin Panel - Bayi Günlük Rapor
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Bayi Günlük Rapor';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ── AJAX handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'filtrele') {
    header('Content-Type: application/json');

    $tarih = $_POST['tarih'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) {
        echo json_encode(['success' => false, 'message' => 'Geçersiz tarih formatı']);
        exit;
    }

    try {
        $satis = $db->fetchAll("
            SELECT
                IrisRapor_TalebiGirenPersonelAltbayi AS bayi,
                ISNULL([ISP],  0) AS isp,
                ISNULL([NEO],  0) AS neo,
                ISNULL([UYDU], 0) AS uydu,
                ISNULL([NEO],  0) + ISNULL([UYDU], 0) AS toplam
            FROM (
                SELECT IrisRapor_TalebiGirenPersonelAltbayi,
                       IrisRapor_MemoKayitTipi
                FROM dbo.DigiturkIrisRapor
                WHERE CAST(IrisRapor_TalepGirisTarihi AS DATE) = ?
            ) src
            PIVOT (
                COUNT(IrisRapor_MemoKayitTipi)
                FOR IrisRapor_MemoKayitTipi IN ([ISP], [NEO], [UYDU])
            ) AS PV
            ORDER BY toplam DESC
        ", [$tarih]);

        $kurulum = $db->fetchAll("
            SELECT
                IrisRapor_TalebiGirenPersonelAltbayi AS bayi,
                ISNULL([ISP],  0) AS isp,
                ISNULL([NEO],  0) AS neo,
                ISNULL([UYDU], 0) AS uydu,
                ISNULL([NEO],  0) + ISNULL([UYDU], 0) AS toplam
            FROM (
                SELECT IrisRapor_TalebiGirenPersonelAltbayi,
                       IrisRapor_MemoKayitTipi
                FROM dbo.DigiturkIrisRapor
                WHERE CAST(IrisRapor_MemoKapanisTarihi AS DATE) = ?
                  AND IrisRapor_SatisDurumu = 'Tamamlandı'
            ) src
            PIVOT (
                COUNT(IrisRapor_MemoKayitTipi)
                FOR IrisRapor_MemoKayitTipi IN ([ISP], [NEO], [UYDU])
            ) AS PV
            ORDER BY toplam DESC
        ", [$tarih]);

        $onay = $db->fetchAll("
            SELECT
                IrisRapor_TalebiGirenPersonelAltbayi AS bayi,
                ISNULL([ISP],  0) AS isp,
                ISNULL([NEO],  0) AS neo,
                ISNULL([UYDU], 0) AS uydu,
                ISNULL([NEO],  0) + ISNULL([UYDU], 0) AS toplam
            FROM (
                SELECT IrisRapor_TalebiGirenPersonelAltbayi,
                       IrisRapor_MemoKayitTipi
                FROM dbo.DigiturkIrisRapor
                WHERE CAST(IrisRapor_TalepGirisTarihi AS DATE) = ?
                  AND IrisRapor_TeyitDurum = 'ONAYLANDI'
            ) src
            PIVOT (
                COUNT(IrisRapor_MemoKayitTipi)
                FOR IrisRapor_MemoKayitTipi IN ([ISP], [NEO], [UYDU])
            ) AS PV
            ORDER BY toplam DESC
        ", [$tarih]);

        $voip = voipSorgu($db, $tarih);

        $reklam  = reklamSorgu($db, $tarih);
        $reklam  = reklamMetaEkle($db, $reklam, $tarih);
        $maliyet = maliyetHesapla($reklam, onayAdetSorgu($db, $tarih));

        $sonKayit = $db->fetchOne("
            SELECT MAX(OlusturmaTarihi) AS son_tarih
            FROM dbo.DigiturkIrisRapor
            WHERE CAST(IrisRapor_TalepGirisTarihi AS DATE) = ?
        ", [$tarih]);
        $raporTarihiAjax = $sonKayit['son_tarih']
            ? date('d.m.Y H:i', strtotime($sonKayit['son_tarih']))
            : null;

        echo json_encode(['success' => true, 'satis' => $satis, 'kurulum' => $kurulum, 'onay' => $onay, 'voip' => $voip, 'reklam' => $reklam, 'maliyet' => $maliyet, 'rapor_tarihi' => $raporTarihiAjax]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── İlk yükleme: en son kayıt tarihi (yoksa dün) ────────────────────────────
$sonKayitTarih = $db->fetchOne("
    SELECT CAST(MAX(IrisRapor_TalepGirisTarihi) AS DATE) AS son_tarih
    FROM dbo.DigiturkIrisRapor
");
$dun = $sonKayitTarih['son_tarih']
    ? date('Y-m-d', strtotime($sonKayitTarih['son_tarih']))
    : date('Y-m-d', strtotime('-1 day'));

function pivotSorgu(object $db, string $tarihKolonu, string $tarih, string $ekKosul = ''): array {
    return $db->fetchAll("
        SELECT
            IrisRapor_TalebiGirenPersonelAltbayi AS bayi,
            ISNULL([ISP],  0) AS isp,
            ISNULL([NEO],  0) AS neo,
            ISNULL([UYDU], 0) AS uydu,
            ISNULL([NEO],  0) + ISNULL([UYDU], 0) AS toplam
        FROM (
            SELECT IrisRapor_TalebiGirenPersonelAltbayi,
                   IrisRapor_MemoKayitTipi
            FROM dbo.DigiturkIrisRapor
            WHERE CAST({$tarihKolonu} AS DATE) = ?
            {$ekKosul}
        ) src
        PIVOT (
            COUNT(IrisRapor_MemoKayitTipi)
            FOR IrisRapor_MemoKayitTipi IN ([ISP], [NEO], [UYDU])
        ) AS PV
        ORDER BY toplam DESC
    ", [$tarih]);
}

$satirSatis   = pivotSorgu($db, 'IrisRapor_TalepGirisTarihi',  $dun);
$satirKurulum = pivotSorgu($db, 'IrisRapor_MemoKapanisTarihi', $dun, "AND IrisRapor_SatisDurumu = 'Tamamlandı'");
$satirOnay    = pivotSorgu($db, 'IrisRapor_TalepGirisTarihi',  $dun, "AND IrisRapor_TeyitDurum = 'ONAYLANDI'");
$satirVoip    = voipSorgu($db, $dun);
$toplamVoip   = array_sum(array_column($satirVoip, 'ucret'));

$satirReklam       = reklamSorgu($db, $dun);
$satirReklam       = reklamMetaEkle($db, $satirReklam, $dun);
$satirMaliyet      = maliyetHesapla($satirReklam, onayAdetSorgu($db, $dun));
$toplamHarcanan    = array_sum(array_column($satirReklam, 'harcanan'));
$toplamLead        = array_sum(array_column($satirReklam, 'lead'));
$toplamMaliyetAdet = array_sum(array_column($satirMaliyet, 'adet'));
$genelMaliyet      = $toplamMaliyetAdet > 0 ? array_sum(array_column($satirMaliyet, 'tutar')) / $toplamMaliyetAdet : null;

/**
 * VoIP günlük ücret — Odemeler (OdemeTuruId=4) → telefon (Odemeler_Referans)
 * → VoIPHesaplar → KullaniciBirimYetkileri (ödeme tarihi yetki aralığında) → KullaniciBirim.
 * Birime (bayi) göre gruplar, toplam ücreti döner.
 */
function voipSorgu(object $db, string $tarih): array {
    return $db->fetchAll("
        SELECT
            ISNULL(b.birim, 'Bilinmeyen') AS bayi,
            SUM(CAST(o.Odemeler_Tutar AS DECIMAL(18,4))) AS ucret
        FROM Odemeler o
        OUTER APPLY (
            SELECT TOP 1 kb.KullaniciBirim_Adi AS birim
            FROM VoIPHesaplar vh
            JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_VoIPHesap_id = vh.VoIPHesaplar_id
            JOIN KullaniciBirim kb ON kby.KullaniciBirimYetkileri_Birim_id = kb.KullaniciBirim_id
            WHERE vh.VoIPHesaplar_TelefonNo = o.Odemeler_Referans
              AND vh.Durum = 1 AND kby.Durum = 1
              AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= o.Odemeler_Tarih)
              AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= o.Odemeler_Tarih)
            ORDER BY kby.KullaniciBirimYetkileri_id DESC
        ) b
        WHERE o.Odemeler_OdemeTuruId = 4
          AND CAST(o.Odemeler_Tarih AS DATE) = ?
        GROUP BY ISNULL(b.birim, 'Bilinmeyen')
        ORDER BY ucret DESC
    ", [$tarih]);
}

/**
 * Reklam gideri (OdemeTuruId=3) → Birim'e göre toplam tutar.
 * cron/tasks.php reklamGiderBirim() ile birebir aynı sorgu.
 */
function reklamSorgu(object $db, string $tarih): array {
    return $db->fetchAll("
        SELECT
            b.birim AS bayi,
            SUM(CAST(o.Odemeler_Tutar AS DECIMAL(18,4))) AS tutar
        FROM Odemeler o
        CROSS APPLY (
            SELECT TOP 1 kb.KullaniciBirim_Adi AS birim
            FROM ReklamHesaplari rh
            JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_ReklamHesap_id = rh.ReklamHesaplari_id
            JOIN KullaniciBirim kb ON kby.KullaniciBirimYetkileri_Birim_id = kb.KullaniciBirim_id
            WHERE rh.ReklamHesaplari_HesapID = o.Odemeler_Aciklama
              AND rh.Durum = 1 AND kby.Durum = 1
              AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= o.Odemeler_Tarih)
              AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= o.Odemeler_Tarih)
            ORDER BY kby.KullaniciBirimYetkileri_id DESC
        ) b
        WHERE o.Odemeler_OdemeTuruId = 3
          AND CAST(o.Odemeler_Tarih AS DATE) = ?
        GROUP BY b.birim
        ORDER BY tutar DESC
    ", [$tarih]);
}

/**
 * Reklam gideri satırlarına Meta canlı verisini ekler: harcanan (KDV dahil), lead, lead başı maliyet.
 * Hesap → bayi eşlemesi reklamSorgu() ile aynı: o tarihte geçerli KullaniciBirimYetkileri.
 * Bayiye bağlı olmayan hesaplar "Atanmamış" satırında toplanır. Meta hatası raporu bozmaz (değerler null).
 */
function reklamMetaEkle(object $db, array $reklam, string $tarih): array {
    $kdvOrani = 20; // % — guncel-borc-bakiyeleri.php varsayılanı ile aynı
    $bayiler  = [];
    foreach ($reklam as $r) {
        $bayiler[$r['bayi']] = ['bayi' => $r['bayi'], 'tutar' => (float)$r['tutar'], 'harcanan' => null, 'lead' => null, 'lead_maliyet' => null];
    }

    try {
        require_once __DIR__ . '/../includes/MetaReklamSync.php';
        $meta = MetaReklamSync::gunHarcamaLead($tarih);
    } catch (Throwable $e) {
        return array_values($bayiler);
    }

    $harita = [];
    foreach ($db->fetchAll("
        SELECT rh.ReklamHesaplari_HesapID AS act, b.birim
        FROM ReklamHesaplari rh
        CROSS APPLY (
            SELECT TOP 1 kb.KullaniciBirim_Adi AS birim
            FROM KullaniciBirimYetkileri kby
            JOIN KullaniciBirim kb ON kby.KullaniciBirimYetkileri_Birim_id = kb.KullaniciBirim_id
            WHERE kby.KullaniciBirimYetkileri_ReklamHesap_id = rh.ReklamHesaplari_id
              AND kby.Durum = 1
              AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= ?)
              AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= ?)
            ORDER BY kby.KullaniciBirimYetkileri_id DESC
        ) b
        WHERE rh.Durum = 1
    ", [$tarih, $tarih]) as $h) {
        $harita[$h['act']] = $h['birim'];
    }

    foreach ($meta as $m) {
        if ($m['spend'] <= 0 && $m['lead'] <= 0) continue;
        $bayi = $harita[$m['act_id']] ?? 'Atanmamış';
        if (!isset($bayiler[$bayi])) $bayiler[$bayi] = ['bayi' => $bayi, 'tutar' => 0.0, 'harcanan' => null, 'lead' => null, 'lead_maliyet' => null];
        $bayiler[$bayi]['harcanan'] = ($bayiler[$bayi]['harcanan'] ?? 0) + $m['spend'] * (1 + $kdvOrani / 100);
        $bayiler[$bayi]['lead']     = ($bayiler[$bayi]['lead'] ?? 0) + $m['lead'];
    }
    foreach ($bayiler as &$b) {
        if ($b['harcanan'] !== null) $b['harcanan'] = round($b['harcanan'], 2);
        $b['lead_maliyet'] = $b['lead'] ? round($b['harcanan'] / $b['lead'], 2) : null;
    }
    unset($b);

    return array_values($bayiler);
}

/**
 * Birim'e göre ONAY adedi (ISP+NEO+UYDU).
 * cron/tasks.php onayAdetBirim() ile birebir aynı sorgu.
 */
function onayAdetSorgu(object $db, string $tarih): array {
    return $db->fetchAll("
        SELECT
            kb.KullaniciBirim_Adi AS birim,
            COUNT(*) AS adet
        FROM dbo.DigiturkIrisRapor ir
        JOIN DigiturkAltBayiler a ON a.DigiturkAltBayiler_Ad = ir.IrisRapor_TalebiGirenPersonelAltbayi
        JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_AltBayi_id = a.DigiturkAltBayiler_Id AND kby.Durum = 1
        JOIN KullaniciBirim kb ON kby.KullaniciBirimYetkileri_Birim_id = kb.KullaniciBirim_id
        WHERE CAST(ir.IrisRapor_TalepGirisTarihi AS DATE) = ?
          AND ir.IrisRapor_TeyitDurum = 'ONAYLANDI'
          AND ir.IrisRapor_MemoKayitTipi IN ('ISP','NEO','UYDU')
        GROUP BY kb.KullaniciBirim_Adi
    ", [$tarih]);
}

/**
 * Satış başı maliyet = Birim HARCANAN (Meta canlı, KDV dahil) / Birim onay adedi (ISP + TV TOPLAM).
 * $reklam, reklamMetaEkle() çıktısıdır. Meta verisi yoksa (harcanan null) maliyet hesaplanmaz.
 * NOT: cron/tasks.php satisBasiMaliyet() hâlâ Odemeler gideri (KDV hariç) ile hesaplar.
 */
function maliyetHesapla(array $reklam, array $onay): array {
    $adetMap = [];
    foreach ($onay as $r) $adetMap[$r['birim']] = (int)$r['adet'];

    $sonuc = [];
    foreach ($reklam as $r) {
        $birim = $r['bayi'];
        if ($r['harcanan'] === null) {
            $sonuc[] = ['bayi' => $birim, 'maliyet' => null, 'adet' => 0, 'tutar' => 0.0];   // genel ortalamaya girmez
            continue;
        }
        $tutar = (float)$r['harcanan'];
        $adet  = $adetMap[$birim] ?? 0;
        $sonuc[] = [
            'bayi'    => $birim,
            'maliyet' => $adet > 0 ? $tutar / $adet : null,
            'adet'    => $adet,
            'tutar'   => $tutar,
        ];
    }
    return $sonuc;
}

function genelToplam(array $satirlar): array {
    return [
        'isp'    => array_sum(array_column($satirlar, 'isp')),
        'neo'    => array_sum(array_column($satirlar, 'neo')),
        'uydu'   => array_sum(array_column($satirlar, 'uydu')),
        'toplam' => array_sum(array_column($satirlar, 'toplam')),
        'genel'  => array_sum(array_column($satirlar, 'isp'))
                  + array_sum(array_column($satirlar, 'neo'))
                  + array_sum(array_column($satirlar, 'uydu')),
    ];
}

$toplamSatis   = genelToplam($satirSatis);
$toplamKurulum = genelToplam($satirKurulum);
$toplamOnay    = genelToplam($satirOnay);

$sonKayit = $db->fetchOne("
    SELECT MAX(OlusturmaTarihi) AS son_tarih
    FROM dbo.DigiturkIrisRapor
    WHERE CAST(IrisRapor_TalepGirisTarihi AS DATE) = ?
", [$dun]);
$raporTarihi = $sonKayit['son_tarih']
    ? date('d.m.Y H:i', strtotime($sonKayit['son_tarih']))
    : '-';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        .rapor-baslik {
            background: #2a6496;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            padding: 8px 12px;
            white-space: nowrap;
        }
        .rapor-tablo {
            font-size: 13px;
            margin-bottom: 0;
        }
        .rapor-tablo thead th {
            background: #2a6496;
            color: #fff;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
            font-size: 13px;
        }
        .rapor-tablo thead th:first-child {
            text-align: left;
        }
        .rapor-tablo tbody td {
            vertical-align: middle;
        }
        .rapor-tablo tbody td:not(:first-child) {
            text-align: center;
        }
        .toplam-satir td {
            background: #2a6496 !important;
            color: #fff !important;
            font-size: 14px;
            font-weight: 700;
        }
        .toplam-satir td:not(:first-child) {
            text-align: center;
        }
        .rapor-bos {
            text-align: center;
            color: #888;
            padding: 20px 0;
            font-size: 13px;
        }
        .rapor-tarihi {
            font-size: 12px;
            color: #888;
            text-align: right;
            margin-top: 16px;
        }
        #tarih-filtre {
            max-width: 180px;
        }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="app-content">
            <div class="container-fluid">

                <!-- Sayfa başlığı -->
                <div class="app-content-header mb-3">
                    <div class="container-fluid">
                        <div class="row align-items-center">
                            <div class="col-sm-6">
                                <h2 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h2>
                            </div>
                            <div class="col-sm-6 text-end d-flex justify-content-end align-items-center gap-2">
                                <div class="input-group" style="max-width:220px;">
                                    <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                                    <input type="date" id="tarih-filtre" class="form-control form-control-sm"
                                           value="<?= $dun ?>">
                                </div>
                                <button class="btn btn-sm btn-primary" id="btn-filtrele">
                                    <i class="bi bi-funnel me-1"></i>Filtrele
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2x2 Grid -->
                <div class="row g-3" id="rapor-grid">

                    <!-- SOL ÜST: SATIŞ -->
                    <div class="col-lg-6">
                        <div class="rapor-baslik">BAYİ GÜNLÜK SATIŞ ADET</div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover rapor-tablo" id="tbl-satis">
                                <thead>
                                    <tr>
                                        <th>BAYİ İSMİ</th>
                                        <th>ISP</th>
                                        <th>NEO</th>
                                        <th>UYDU</th>
                                        <th>TV TOPLAM</th>
                                        <th>TOPLAM</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-satis">
                                    <?php if (empty($satirSatis)): ?>
                                        <tr><td colspan="6" class="rapor-bos">Bu tarihe ait satış verisi bulunamadı</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($satirSatis as $s): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($s['bayi'] ?? '-') ?></td>
                                            <td><?= $s['isp'] ?></td>
                                            <td><?= $s['neo'] ?></td>
                                            <td><?= $s['uydu'] ?></td>
                                            <td><strong><?= $s['toplam'] ?></strong></td>
                                            <td><strong><?= (int)$s['isp'] + (int)$s['neo'] + (int)$s['uydu'] ?></strong></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr class="toplam-satir">
                                            <td>Genel Toplam</td>
                                            <td><?= $toplamSatis['isp'] ?></td>
                                            <td><?= $toplamSatis['neo'] ?></td>
                                            <td><?= $toplamSatis['uydu'] ?></td>
                                            <td><?= $toplamSatis['toplam'] ?></td>
                                            <td><?= $toplamSatis['genel'] ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- SAĞ ÜST: KURULUM -->
                    <div class="col-lg-6">
                        <div class="rapor-baslik">BAYİ GÜNLÜK KURULUM ADET</div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover rapor-tablo" id="tbl-kurulum">
                                <thead>
                                    <tr>
                                        <th>BAYİ İSMİ</th>
                                        <th>ISP</th>
                                        <th>NEO</th>
                                        <th>UYDU</th>
                                        <th>TV TOPLAM</th>
                                        <th>TOPLAM</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-kurulum">
                                    <?php if (empty($satirKurulum)): ?>
                                        <tr><td colspan="6" class="rapor-bos">Bu tarihe ait kurulum verisi bulunamadı</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($satirKurulum as $s): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($s['bayi'] ?? '-') ?></td>
                                            <td><?= $s['isp'] ?></td>
                                            <td><?= $s['neo'] ?></td>
                                            <td><?= $s['uydu'] ?></td>
                                            <td><strong><?= $s['toplam'] ?></strong></td>
                                            <td><strong><?= (int)$s['isp'] + (int)$s['neo'] + (int)$s['uydu'] ?></strong></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr class="toplam-satir">
                                            <td>Genel Toplam</td>
                                            <td><?= $toplamKurulum['isp'] ?></td>
                                            <td><?= $toplamKurulum['neo'] ?></td>
                                            <td><?= $toplamKurulum['uydu'] ?></td>
                                            <td><?= $toplamKurulum['toplam'] ?></td>
                                            <td><?= $toplamKurulum['genel'] ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- SOL ALT: ONAY -->
                    <div class="col-lg-6">
                        <div class="rapor-baslik">BAYİ GÜNLÜK ONAY ADET</div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover rapor-tablo" id="tbl-onay">
                                <thead>
                                    <tr>
                                        <th>BAYİ İSMİ</th>
                                        <th>ISP</th>
                                        <th>NEO</th>
                                        <th>UYDU</th>
                                        <th>TV TOPLAM</th>
                                        <th>TOPLAM</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-onay">
                                    <?php if (empty($satirOnay)): ?>
                                        <tr><td colspan="6" class="rapor-bos">Bu tarihe ait onay verisi bulunamadı</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($satirOnay as $s): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($s['bayi'] ?? '-') ?></td>
                                            <td><?= $s['isp'] ?></td>
                                            <td><?= $s['neo'] ?></td>
                                            <td><?= $s['uydu'] ?></td>
                                            <td><strong><?= $s['toplam'] ?></strong></td>
                                            <td><strong><?= (int)$s['isp'] + (int)$s['neo'] + (int)$s['uydu'] ?></strong></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr class="toplam-satir">
                                            <td>Genel Toplam</td>
                                            <td><?= $toplamOnay['isp'] ?></td>
                                            <td><?= $toplamOnay['neo'] ?></td>
                                            <td><?= $toplamOnay['uydu'] ?></td>
                                            <td><?= $toplamOnay['toplam'] ?></td>
                                            <td><?= $toplamOnay['genel'] ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- SAĞ ALT: VoIP -->
                    <div class="col-lg-6">
                        <div class="rapor-baslik">BAYİ GÜNLÜK VoIP ÜCRET</div>
                        <div class="table-responsive">
                            <table class="table table-bordered rapor-tablo" id="tbl-voip">
                                <thead>
                                    <tr>
                                        <th>BAYİ İSMİ</th>
                                        <th>ÜCRET</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-voip">
                                    <?php if (empty($satirVoip)): ?>
                                        <tr><td colspan="2" class="rapor-bos">Bu tarihe ait VoIP verisi bulunamadı</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($satirVoip as $v): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($v['bayi'] ?? '-') ?></td>
                                            <td><strong><?= number_format((float)$v['ucret'], 2, ',', '.') ?> ₺</strong></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr class="toplam-satir">
                                            <td>Genel Toplam</td>
                                            <td><?= number_format((float)$toplamVoip, 2, ',', '.') ?> ₺</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- REKLAM GİDERİ -->
                    <div class="col-lg-6">
                        <div class="rapor-baslik">BAYİ GÜNLÜK REKLAM GİDERİ</div>
                        <div class="table-responsive">
                            <table class="table table-bordered rapor-tablo" id="tbl-reklam">
                                <thead>
                                    <tr>
                                        <th>BAYİ İSMİ</th>
                                        <th title="Meta canlı harcama, KDV dahil">HARCANAN</th>
                                        <th>LEAD</th>
                                        <th>LEAD BAŞI MALİYET</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-reklam">
                                    <?php if (empty($satirReklam)): ?>
                                        <tr><td colspan="4" class="rapor-bos">Bu tarihe ait reklam gideri bulunamadı</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($satirReklam as $r): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($r['bayi'] ?? '-') ?></td>
                                            <td><strong><?= $r['harcanan'] === null ? '—' : number_format((float)$r['harcanan'], 2, ',', '.') . ' ₺' ?></strong></td>
                                            <td><?= $r['lead'] === null ? '—' : (int)$r['lead'] ?></td>
                                            <td><?= $r['lead_maliyet'] === null ? '—' : number_format((float)$r['lead_maliyet'], 2, ',', '.') . ' ₺' ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr class="toplam-satir">
                                            <td>Genel Toplam</td>
                                            <td><?= number_format((float)$toplamHarcanan, 2, ',', '.') ?> ₺</td>
                                            <td><?= (int)$toplamLead ?></td>
                                            <td><?= $toplamLead > 0 ? number_format($toplamHarcanan / $toplamLead, 2, ',', '.') . ' ₺' : '—' ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ONAY BAŞI MALİYET -->
                    <div class="col-lg-6">
                        <div class="rapor-baslik">BAYİ GÜNLÜK ONAY BAŞI MALİYET</div>
                        <div class="table-responsive">
                            <table class="table table-bordered rapor-tablo" id="tbl-maliyet">
                                <thead>
                                    <tr>
                                        <th>BAYİ İSMİ</th>
                                        <th>MALİYET</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody-maliyet">
                                    <?php if (empty($satirMaliyet)): ?>
                                        <tr><td colspan="2" class="rapor-bos">Bu tarihe ait maliyet verisi bulunamadı</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($satirMaliyet as $m): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($m['bayi'] ?? '-') ?></td>
                                            <td><strong><?= $m['maliyet'] === null ? '—' : number_format((float)$m['maliyet'], 2, ',', '.') . ' ₺' ?></strong></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr class="toplam-satir">
                                            <td>Genel Toplam</td>
                                            <td><?= $genelMaliyet === null ? '—' : number_format((float)$genelMaliyet, 2, ',', '.') . ' ₺' ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div><!-- /row -->

                <div class="rapor-tarihi" id="rapor-tarihi">Rapor Tarihi: <?= $raporTarihi ?></div>

            </div><!-- /container-fluid -->
        </div><!-- /app-content -->
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.10.1/browser/overlayscrollbars.browser.es5.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    eşitleYukseklik();

    document.getElementById('btn-filtrele').addEventListener('click', filtrele);
    document.getElementById('tarih-filtre').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') filtrele();
    });
});

function filtrele() {
    var tarih = document.getElementById('tarih-filtre').value;
    if (!tarih) return;

    var btn = document.getElementById('btn-filtrele');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Yükleniyor...';

    var fd = new FormData();
    fd.append('action', 'filtrele');
    fd.append('tarih', tarih);

    fetch('', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) {
                Swal.fire('Hata', res.message || 'Bilinmeyen hata', 'error');
                return;
            }
            tabloGuncelle('tbody-satis',   res.satis,   'satış');
            tabloGuncelle('tbody-kurulum', res.kurulum, 'kurulum');
            tabloGuncelle('tbody-onay',    res.onay,    'onay');
            voipGuncelle('tbody-voip',     res.voip);
            reklamGuncelle('tbody-reklam', res.reklam);
            maliyetGuncelle('tbody-maliyet', res.maliyet);

            document.getElementById('rapor-tarihi').textContent =
                'Rapor Tarihi: ' + (res.rapor_tarihi || '-');

            // Yükseklik eşitle (DOM güncellenince)
            setTimeout(eşitleYukseklik, 50);
        })
        .catch(function () {
            Swal.fire('Hata', 'Sunucuya bağlanılamadı', 'error');
        })
        .finally(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-funnel me-1"></i>Filtrele';
        });
}

function tabloGuncelle(tbodyId, satirlar, tip) {
    var tbody = document.getElementById(tbodyId);

    // Gizli doldurma satırlarını temizle
    tbody.querySelectorAll('tr[data-doldurma]').forEach(function (tr) { tr.remove(); });

    if (!satirlar || satirlar.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="rapor-bos">Bu tarihe ait ' + tip + ' verisi bulunamadı</td></tr>';
        return;
    }

    var isp = 0, neo = 0, uydu = 0, toplam = 0;
    var html = '';
    satirlar.forEach(function (s) {
        var sGenel = (parseInt(s.isp) || 0) + (parseInt(s.neo) || 0) + (parseInt(s.uydu) || 0);
        isp    += parseInt(s.isp)    || 0;
        neo    += parseInt(s.neo)    || 0;
        uydu   += parseInt(s.uydu)   || 0;
        toplam += parseInt(s.toplam) || 0;
        html += '<tr>' +
            '<td>' + escHtml(s.bayi || '-') + '</td>' +
            '<td>' + s.isp    + '</td>' +
            '<td>' + s.neo    + '</td>' +
            '<td>' + s.uydu   + '</td>' +
            '<td><strong>' + s.toplam + '</strong></td>' +
            '<td><strong>' + sGenel + '</strong></td>' +
            '</tr>';
    });
    html += '<tr class="toplam-satir">' +
        '<td>Genel Toplam</td>' +
        '<td>' + isp    + '</td>' +
        '<td>' + neo    + '</td>' +
        '<td>' + uydu   + '</td>' +
        '<td>' + toplam + '</td>' +
        '<td>' + (isp + neo + uydu) + '</td>' +
        '</tr>';

    tbody.innerHTML = html;
}

function voipGuncelle(tbodyId, satirlar) {
    var tbody = document.getElementById(tbodyId);
    tbody.querySelectorAll('tr[data-doldurma]').forEach(function (tr) { tr.remove(); });

    if (!satirlar || satirlar.length === 0) {
        tbody.innerHTML = '<tr><td colspan="2" class="rapor-bos">Bu tarihe ait VoIP verisi bulunamadı</td></tr>';
        return;
    }

    var toplam = 0;
    var html = '';
    satirlar.forEach(function (v) {
        toplam += parseFloat(v.ucret) || 0;
        html += '<tr>' +
            '<td>' + escHtml(v.bayi || '-') + '</td>' +
            '<td><strong>' + paraFormat(v.ucret) + ' ₺</strong></td>' +
            '</tr>';
    });
    html += '<tr class="toplam-satir">' +
        '<td>Genel Toplam</td>' +
        '<td>' + paraFormat(toplam) + ' ₺</td>' +
        '</tr>';

    tbody.innerHTML = html;
}

function reklamGuncelle(tbodyId, satirlar) {
    var tbody = document.getElementById(tbodyId);
    tbody.querySelectorAll('tr[data-doldurma]').forEach(function (tr) { tr.remove(); });

    if (!satirlar || satirlar.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="rapor-bos">Bu tarihe ait reklam gideri bulunamadı</td></tr>';
        return;
    }

    var bos = function (v) { return v === null || v === undefined; };
    var harcanan = 0, lead = 0, html = '';
    satirlar.forEach(function (r) {
        harcanan += parseFloat(r.harcanan) || 0;
        lead     += parseInt(r.lead)       || 0;
        html += '<tr>' +
            '<td>' + escHtml(r.bayi || '-') + '</td>' +
            '<td><strong>' + (bos(r.harcanan) ? '—' : paraFormat(r.harcanan) + ' ₺') + '</strong></td>' +
            '<td>' + (bos(r.lead) ? '—' : parseInt(r.lead)) + '</td>' +
            '<td>' + (bos(r.lead_maliyet) ? '—' : paraFormat(r.lead_maliyet) + ' ₺') + '</td>' +
            '</tr>';
    });
    html += '<tr class="toplam-satir">' +
        '<td>Genel Toplam</td>' +
        '<td>' + paraFormat(harcanan) + ' ₺</td>' +
        '<td>' + lead + '</td>' +
        '<td>' + (lead > 0 ? paraFormat(harcanan / lead) + ' ₺' : '—') + '</td>' +
        '</tr>';

    tbody.innerHTML = html;
}

function maliyetGuncelle(tbodyId, satirlar) {
    var tbody = document.getElementById(tbodyId);
    tbody.querySelectorAll('tr[data-doldurma]').forEach(function (tr) { tr.remove(); });

    if (!satirlar || satirlar.length === 0) {
        tbody.innerHTML = '<tr><td colspan="2" class="rapor-bos">Bu tarihe ait maliyet verisi bulunamadı</td></tr>';
        return;
    }

    var toplamTutar = 0, toplamAdet = 0, html = '';
    satirlar.forEach(function (m) {
        toplamTutar += parseFloat(m.tutar) || 0;
        toplamAdet  += parseInt(m.adet)   || 0;
        var deger = (m.maliyet === null || m.maliyet === undefined) ? '—' : paraFormat(m.maliyet) + ' ₺';
        html += '<tr>' +
            '<td>' + escHtml(m.bayi || '-') + '</td>' +
            '<td><strong>' + deger + '</strong></td>' +
            '</tr>';
    });
    var genel = toplamAdet > 0 ? paraFormat(toplamTutar / toplamAdet) + ' ₺' : '—';
    html += '<tr class="toplam-satir">' +
        '<td>Genel Toplam</td>' +
        '<td>' + genel + '</td>' +
        '</tr>';

    tbody.innerHTML = html;
}

function paraFormat(n) {
    return (parseFloat(n) || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function eşitleYukseklik() {
    // Gizli doldurma satırlarını temizle
    document.querySelectorAll('tr[data-doldurma]').forEach(function (tr) { tr.remove(); });

    doldur('tbl-satis',   'tbl-kurulum');
    doldur('tbl-onay',    'tbl-voip');
    doldur('tbl-reklam',  'tbl-maliyet');
}

function doldur(id1, id2) {
    var t1 = document.getElementById(id1);
    var t2 = document.getElementById(id2);
    if (!t1 || !t2) return;

    var h1 = t1.closest('.table-responsive').offsetHeight;
    var h2 = t2.closest('.table-responsive').offsetHeight;
    if (h1 === h2) return;

    var hedef = h1 < h2 ? t1 : t2;
    var fark  = Math.abs(h1 - h2);
    var satirY = (hedef.querySelector('tbody tr') || {}).offsetHeight || 30;
    var adet   = Math.ceil(fark / satirY);
    var kolan  = hedef.querySelector('thead tr') ? hedef.querySelector('thead tr').cells.length : 5;

    var tbody = hedef.querySelector('tbody');
    var toplamSatir = tbody.querySelector('tr.toplam-satir');
    for (var i = 0; i < adet; i++) {
        var tr = document.createElement('tr');
        tr.setAttribute('data-doldurma', '1');
        tr.style.visibility = 'hidden';
        tr.innerHTML = '<td colspan="' + kolan + '">&nbsp;</td>';
        if (toplamSatir) tbody.insertBefore(tr, toplamSatir);
        else tbody.appendChild(tr);
    }
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
</script>
</body>
</html>
