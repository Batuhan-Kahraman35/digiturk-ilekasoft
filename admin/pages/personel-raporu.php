<?php
/**
 * Admin Panel - Personel Raporu
 * DigiturkIrisRapor verisini talebi giren personel bazında ISP / NEO / UYDU olarak özetler.
 * Satış / Kurulum / Onay tanımları bayi-gunluk-rapor.php ile birebir aynıdır:
 *   Satış   : IrisRapor_TalepGirisTarihi aralıkta
 *   Kurulum : IrisRapor_MemoKapanisTarihi aralıkta ve IrisRapor_SatisDurumu = 'Tamamlandı'
 *   Onay    : IrisRapor_TalepGirisTarihi aralıkta ve IrisRapor_TeyitDurum = 'ONAYLANDI'
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Personel Raporu';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ── Birim görünürlük kısıtı (birim_gor): kendi birimi + alt birimlerine bağlı alt bayiler ──
require_once __DIR__ . '/../includes/GorunurlukYetki.php';
$gorunurluk = gorunurlukKisitiHesapla($db, $permissions, (int)$user['kullanici_id']);

/**
 * Kısıtlı kullanıcının görebileceği alt bayi adları (IrisRapor_TalebiGirenPersonelAltbayi ile eşleşir).
 * null = kısıt yok. Tarih verilirse yalnız o aralıkla çakışan birim yetkileri sayılır.
 */
function izinliAltbayiler(object $db, array $gorunurluk, ?string $bas = null, ?string $bit = null): ?array {
    if (!$gorunurluk['birimKisitli']) return null;
    $birimler = $gorunurluk['izinliBirimler'];
    if (!$birimler) return [];

    $params = $birimler;
    $tarih  = '';
    if ($bas !== null && $bit !== null) {
        $tarih = " AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi < DATEADD(DAY, 1, CAST(? AS DATE)))
                   AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= CAST(? AS DATE))";
        array_push($params, $bit, $bas);
    }
    $rows = $db->fetchAll("
        SELECT DISTINCT a.DigiturkAltBayiler_Ad AS ad
        FROM KullaniciBirimYetkileri kby
        JOIN DigiturkAltBayiler a ON a.DigiturkAltBayiler_Id = kby.KullaniciBirimYetkileri_AltBayi_id
        WHERE kby.KullaniciBirimYetkileri_Birim_id IN (" . implode(',', array_fill(0, count($birimler), '?')) . ")
          AND kby.Durum = 1 AND a.Durum = 1
          {$tarih}
    ", $params);
    return array_values(array_filter(array_column($rows, 'ad'), 'strlen'));
}

// ── AJAX: personel bazlı rapor ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rapor') {
    header('Content-Type: application/json');

    $bas = $_POST['tarih_bas'] ?? '';
    $bit = $_POST['tarih_bit'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bas) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bit)) {
        echo json_encode(['success' => false, 'message' => 'Geçersiz tarih formatı']);
        exit;
    }
    if ($bas > $bit) [$bas, $bit] = [$bit, $bas];

    // Çoklu seçimler \x1F ayracıyla gelir (isimlerde virgül olabilir)
    $liste = fn($k) => array_values(array_filter(array_map('trim', explode("\x1F", (string)($_POST[$k] ?? ''))), 'strlen'));
    $altbayiler = $liste('altbayi');
    $personeller = $liste('personel');

    $params = [$bas, $bit];
    $where  = [];
    $izinli = izinliAltbayiler($db, $gorunurluk, $bas, $bit);
    if ($izinli !== null) {
        if (!$izinli) {
            $where[] = "1 = 0";
        } else {
            $where[] = "r.IrisRapor_TalebiGirenPersonelAltbayi IN (" . implode(',', array_fill(0, count($izinli), '?')) . ")";
            array_push($params, ...$izinli);
        }
    }
    if ($altbayiler) {
        $where[] = "r.IrisRapor_TalebiGirenPersonelAltbayi IN (" . implode(',', array_fill(0, count($altbayiler), '?')) . ")";
        array_push($params, ...$altbayiler);
    }
    if ($personeller) {
        $where[] = "r.IrisRapor_TalebiGirenPersonel IN (" . implode(',', array_fill(0, count($personeller), '?')) . ")";
        array_push($params, ...$personeller);
    }
    $ekKosul = $where ? ' AND ' . implode(' AND ', $where) : '';

    try {
        $satirlar = $db->fetchAll("
            SELECT
                ISNULL(x.personel, N'(Bilinmeyen)') AS personel,
                ISNULL(x.kodu, N'')                 AS kodu,
                ISNULL(x.altbayi, N'-')             AS altbayi,
                SUM(CASE WHEN x.satis = 1   AND x.tip = 'ISP'  THEN 1 ELSE 0 END) AS satis_isp,
                SUM(CASE WHEN x.satis = 1   AND x.tip = 'NEO'  THEN 1 ELSE 0 END) AS satis_neo,
                SUM(CASE WHEN x.satis = 1   AND x.tip = 'UYDU' THEN 1 ELSE 0 END) AS satis_uydu,
                SUM(CASE WHEN x.kurulum = 1 AND x.tip = 'ISP'  THEN 1 ELSE 0 END) AS kurulum_isp,
                SUM(CASE WHEN x.kurulum = 1 AND x.tip = 'NEO'  THEN 1 ELSE 0 END) AS kurulum_neo,
                SUM(CASE WHEN x.kurulum = 1 AND x.tip = 'UYDU' THEN 1 ELSE 0 END) AS kurulum_uydu,
                SUM(CASE WHEN x.onay = 1    AND x.tip = 'ISP'  THEN 1 ELSE 0 END) AS onay_isp,
                SUM(CASE WHEN x.onay = 1    AND x.tip = 'NEO'  THEN 1 ELSE 0 END) AS onay_neo,
                SUM(CASE WHEN x.onay = 1    AND x.tip = 'UYDU' THEN 1 ELSE 0 END) AS onay_uydu
            FROM (
                SELECT
                    r.IrisRapor_TalebiGirenPersonel        AS personel,
                    r.IrisRapor_TalebiGirenPersonelKodu    AS kodu,
                    r.IrisRapor_TalebiGirenPersonelAltbayi AS altbayi,
                    r.IrisRapor_MemoKayitTipi              AS tip,
                    CASE WHEN r.IrisRapor_TalepGirisTarihi >= p.bas AND r.IrisRapor_TalepGirisTarihi < p.bit
                         THEN 1 ELSE 0 END AS satis,
                    CASE WHEN r.IrisRapor_MemoKapanisTarihi >= p.bas AND r.IrisRapor_MemoKapanisTarihi < p.bit
                          AND r.IrisRapor_SatisDurumu = N'Tamamlandı'
                         THEN 1 ELSE 0 END AS kurulum,
                    CASE WHEN r.IrisRapor_TalepGirisTarihi >= p.bas AND r.IrisRapor_TalepGirisTarihi < p.bit
                          AND r.IrisRapor_TeyitDurum = 'ONAYLANDI'
                         THEN 1 ELSE 0 END AS onay
                FROM dbo.DigiturkIrisRapor r
                CROSS JOIN (SELECT CAST(? AS DATE) AS bas, DATEADD(DAY, 1, CAST(? AS DATE)) AS bit) p
                WHERE r.IrisRapor_MemoKayitTipi IN ('ISP', 'NEO', 'UYDU')
                  AND (
                        (r.IrisRapor_TalepGirisTarihi  >= p.bas AND r.IrisRapor_TalepGirisTarihi  < p.bit)
                     OR (r.IrisRapor_MemoKapanisTarihi >= p.bas AND r.IrisRapor_MemoKapanisTarihi < p.bit)
                  )
                  {$ekKosul}
            ) x
            GROUP BY x.personel, x.kodu, x.altbayi
            HAVING SUM(x.satis) + SUM(x.kurulum) > 0
            ORDER BY SUM(CASE WHEN x.satis = 1 AND x.tip IN ('NEO', 'UYDU') THEN 1 ELSE 0 END) DESC, x.personel
        ", $params);

        foreach ($satirlar as &$s) {
            foreach (['satis', 'kurulum', 'onay'] as $g) {
                foreach (['isp', 'neo', 'uydu'] as $t) $s["{$g}_{$t}"] = (int)$s["{$g}_{$t}"];
                $s["{$g}_tv"] = $s["{$g}_neo"] + $s["{$g}_uydu"];
            }
        }
        unset($s);

        $sonKayit = $db->fetchOne("SELECT MAX(OlusturmaTarihi) AS son_tarih FROM dbo.DigiturkIrisRapor");

        echo json_encode([
            'success'      => true,
            'data'         => $satirlar,
            'rapor_tarihi' => $sonKayit['son_tarih'] ? date('d.m.Y H:i', strtotime($sonKayit['son_tarih'])) : null,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── İlk yükleme: en son veri günü (yoksa dün) ───────────────────────────────
$sonKayitTarih = $db->fetchOne("SELECT CAST(MAX(IrisRapor_TalepGirisTarihi) AS DATE) AS son_tarih FROM dbo.DigiturkIrisRapor");
$varsayilanTarih = $sonKayitTarih['son_tarih']
    ? date('Y-m-d', strtotime($sonKayitTarih['son_tarih']))
    : date('Y-m-d', strtotime('-1 day'));

// Filtre seçenekleri rapor verisinden (kısıtlı kullanıcıya yalnız izinli alt bayiler)
$izinliListe = izinliAltbayiler($db, $gorunurluk);
$listeKosul  = '';
$listeParam  = [];
if ($izinliListe !== null) {
    if (!$izinliListe) {
        $listeKosul = " AND 1 = 0";
    } else {
        $listeKosul = " AND IrisRapor_TalebiGirenPersonelAltbayi IN (" . implode(',', array_fill(0, count($izinliListe), '?')) . ")";
        $listeParam = $izinliListe;
    }
}
$altbayiListe = $db->fetchAll("
    SELECT DISTINCT IrisRapor_TalebiGirenPersonelAltbayi AS ad
    FROM dbo.DigiturkIrisRapor
    WHERE IrisRapor_TalebiGirenPersonelAltbayi IS NOT NULL AND IrisRapor_TalebiGirenPersonelAltbayi <> ''
    {$listeKosul}
    ORDER BY ad
", $listeParam);
$personelListe = $db->fetchAll("
    SELECT DISTINCT IrisRapor_TalebiGirenPersonel AS ad
    FROM dbo.DigiturkIrisRapor
    WHERE IrisRapor_TalebiGirenPersonel IS NOT NULL AND IrisRapor_TalebiGirenPersonel <> ''
    {$listeKosul}
    ORDER BY ad
", $listeParam);
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/custom.css">

    <style>
        /* Renkler bayi-gunluk-rapor.php ile aynı. scrollX başlık/altlığı ayrı tabloya kopyaladığı için id değil sınıf seçici */
        .rapor-tablo { font-size: 13px; }
        .rapor-tablo thead th { background: #2a6496 !important; color: #fff !important; text-align: center; vertical-align: middle; white-space: nowrap; font-size: 13px; }
        .rapor-tablo td { vertical-align: middle; }
        .rapor-tablo td.sayi { text-align: center; }
        .rapor-tablo td.tv { font-weight: 700; }
        .rapor-tablo tfoot th { background: #2a6496 !important; color: #fff !important; font-size: 14px; font-weight: 700; }
        .rapor-tablo tfoot th.sayi { text-align: center; }
        .rapor-tablo .grup-bas { border-left: 2px solid #adb5bd; }
        .rapor-tarihi { font-size: 12px; color: #888; text-align: right; }
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
                    <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <?php if ($menuAdi): ?><li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li><?php endif; ?>
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
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-cart-check"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Satış (TV / ISP)</span>
                                <span class="info-box-number" id="stat-satis">0 / 0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-patch-check"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Onay (TV / ISP)</span>
                                <span class="info-box-number" id="stat-onay">0 / 0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-tools"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Kurulum (TV / ISP)</span>
                                <span class="info-box-number" id="stat-kurulum">0 / 0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-people"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Personel</span>
                                <span class="info-box-number" id="stat-personel">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="true">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body collapse show" id="filterCard">
                        <form id="filterForm" onsubmit="return false;">
                            <div class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label" for="filter_tarih_bas">Başlangıç</label>
                                    <input type="date" class="form-control" id="filter_tarih_bas" value="<?= $varsayilanTarih ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label" for="filter_tarih_bit">Bitiş</label>
                                    <input type="date" class="form-control" id="filter_tarih_bit" value="<?= $varsayilanTarih ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="filter_altbayi">Alt Bayi</label>
                                    <select class="form-select" id="filter_altbayi" multiple data-placeholder="Tümü">
                                        <?php foreach ($altbayiListe as $a): ?>
                                        <option value="<?= htmlspecialchars($a['ad']) ?>"><?= htmlspecialchars($a['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="filter_personel">Personel</label>
                                    <select class="form-select" id="filter_personel" multiple data-placeholder="Tümü">
                                        <?php foreach ($personelListe as $p): ?>
                                        <option value="<?= htmlspecialchars($p['ad']) ?>"><?= htmlspecialchars($p['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-3 d-flex gap-2">
                                <button type="button" class="btn btn-primary" id="btnFiltrele"><i class="bi bi-search me-1"></i>Filtrele</button>
                                <button type="button" class="btn btn-secondary" id="btnTemizle"><i class="bi bi-x-circle me-1"></i>Temizle</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tablo -->
                <div class="card">
                    <div class="card-body">
                        <table class="table table-bordered table-hover table-sm w-100 rapor-tablo" id="tblPersonel">
                            <thead>
                                <tr>
                                    <th rowspan="2">PERSONEL</th>
                                    <th rowspan="2">ALT BAYİ</th>
                                    <th colspan="4" class="grup-bas">SATIŞ</th>
                                    <th colspan="4" class="grup-bas">ONAY</th>
                                    <th colspan="4" class="grup-bas">KURULUM</th>
                                </tr>
                                <tr>
                                    <th class="grup-bas">ISP</th><th>NEO</th><th>UYDU</th><th>TV TOPLAM</th>
                                    <th class="grup-bas">ISP</th><th>NEO</th><th>UYDU</th><th>TV TOPLAM</th>
                                    <th class="grup-bas">ISP</th><th>NEO</th><th>UYDU</th><th>TV TOPLAM</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="2">Genel Toplam</th>
                                    <?php for ($i = 2; $i < 14; $i++): ?>
                                    <th class="sayi<?= ($i - 2) % 4 === 0 ? ' grup-bas' : '' ?>">0</th>
                                    <?php endfor; ?>
                                </tr>
                            </tfoot>
                        </table>
                        <div class="rapor-tarihi mt-2" id="rapor-tarihi"></div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/admin/assets/js/custom.js"></script>

<script>
$(function () {
    // Sayı kolonları: 2..13 (her grupta ISP, NEO, UYDU, TV)
    const sayiKolon = function (alan, grupBas) {
        return {
            data: alan,
            className: 'sayi' + (alan.endsWith('_tv') ? ' tv' : '') + (grupBas ? ' grup-bas' : '')
        };
    };
    const kolonlar = [
        { data: 'personel', render: $.fn.dataTable.render.text() },
        { data: 'altbayi',  render: $.fn.dataTable.render.text() }
    ];
    ['satis', 'onay', 'kurulum'].forEach(function (g) {
        kolonlar.push(sayiKolon(g + '_isp', true), sayiKolon(g + '_neo'), sayiKolon(g + '_uydu'), sayiKolon(g + '_tv'));
    });

    const tablo = $('#tblPersonel').DataTable({
        data: [],
        columns: kolonlar,
        order: [[5, 'desc']],
        pageLength: 50,
        lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'Tümü']],
        dom: 'lrtip',
        scrollX: true,
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
        footerCallback: function () {
            const api = this.api();
            for (let i = 2; i < 14; i++) {
                const toplam = api.column(i, { search: 'applied' }).data()
                    .reduce(function (a, b) { return a + (parseInt(b) || 0); }, 0);
                $(api.column(i).footer()).text(toplam);
            }
        }
    });

    // Çoklu seçim değerleri: isimlerde virgül olabileceği için ayraç olarak \x1F kullanılır
    const coklu = function (id) { return ($(id).val() || []).join('\x1F'); };

    function yukle() {
        const bas = $('#filter_tarih_bas').val();
        const bit = $('#filter_tarih_bit').val();
        if (!bas || !bit) { showToast('Tarih aralığı seçiniz', 'warning'); return; }

        const $btn = $('#btnFiltrele').prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm me-1"></span>Yükleniyor...');

        $.post('', {
            action:    'rapor',
            tarih_bas: bas,
            tarih_bit: bit,
            altbayi:   coklu('#filter_altbayi'),
            personel:  coklu('#filter_personel')
        }, null, 'json').done(function (res) {
            if (!res.success) { showToast(res.message || 'Rapor alınamadı', 'error'); return; }
            tablo.clear().rows.add(res.data).draw();
            ozetGuncelle(res.data);
            $('#rapor-tarihi').text('Rapor Tarihi: ' + (res.rapor_tarihi || '-'));
        }).fail(function () {
            showToast('Sunucuya bağlanılamadı', 'error');
        }).always(function () {
            $btn.prop('disabled', false).html('<i class="bi bi-search me-1"></i>Filtrele');
        });
    }

    function ozetGuncelle(satirlar) {
        const t = { satis_tv: 0, satis_isp: 0, kurulum_tv: 0, kurulum_isp: 0, onay_tv: 0, onay_isp: 0 };
        satirlar.forEach(function (s) { Object.keys(t).forEach(function (k) { t[k] += parseInt(s[k]) || 0; }); });
        $('#stat-satis').text(t.satis_tv + ' / ' + t.satis_isp);
        $('#stat-kurulum').text(t.kurulum_tv + ' / ' + t.kurulum_isp);
        $('#stat-onay').text(t.onay_tv + ' / ' + t.onay_isp);
        $('#stat-personel').text(satirlar.length);
    }

    $('#btnFiltrele').on('click', yukle);
    $('#filter_tarih_bas, #filter_tarih_bit').on('keydown', function (e) { if (e.key === 'Enter') yukle(); });
    $('#btnTemizle').on('click', function () {
        $('#filter_tarih_bas, #filter_tarih_bit').val('<?= $varsayilanTarih ?>');
        $('#filter_altbayi, #filter_personel').val(null).trigger('change');
        yukle();
    });

    yukle();
});
</script>
</body>
</html>
