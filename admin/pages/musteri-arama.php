<?php
/**
 * Admin Panel - Müşteri Arama (Toplu Sorgu)
 * DigiturkIrisRapor üzerinde IrisRapor_DtMusteriNo veya IrisRapor_MemoId ile toplu arama.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/GorunurlukYetki.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPagefile = basename($_SERVER['PHP_SELF']);
$pageinfo = $db->fetchOne("
    SELECT
        s.sayfalar_sayfa_adi,
        s.sayfalar_aciklama,
        m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Müşteri Arama';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Birim görünürlük kısıtı (birim_gor): admin değil + birim_gor → yalnız kendi birimi + alt birimleri.
// IRIS kaydının birimi, alt bayi adı → KullaniciBirimYetkileri zinciriyle belirlenir.
$gk             = gorunurlukKisitiHesapla($db, $permissions, $user['kullanici_id']);
$birimKisitli   = $gk['birimKisitli'];
$izinliBirimler = $gk['izinliBirimler'];

// En fazla toplu sorgulanabilecek numara sayısı (MSSQL parametre limiti güvenli sınırı)
const MUSTERI_ARAMA_MAX = 1000;

/**
 * Textarea içeriğinden tekil, sayısal numara listesi üretir.
 * Satır sonu, virgül, noktalı virgül, boşluk ve tab ayırıcı kabul eder.
 */
function musteriAramaNumaralariAyristir(string $ham): array {
    $parcalar = preg_split('/[\s,;]+/', $ham, -1, PREG_SPLIT_NO_EMPTY);
    $temiz = [];
    foreach ($parcalar as $p) {
        $p = preg_replace('/\D/', '', $p); // yalnız rakam
        if ($p !== '') $temiz[$p] = $p;    // anahtar ile tekilleştir
    }
    return array_values($temiz);
}

// Sonuç kolonları (list + excel ortak) — arama sonucunu tek yerden getirir
// $birimKisitli true ise yalnız alt bayisi izinli birimlere yetkilendirilmiş kayıtlar döner (birim_gor).
function musteriAramaSorgula($db, string $alan, array $numaralar, bool $birimKisitli = false, array $izinliBirimler = []): array {
    if (empty($numaralar)) return [];

    $kolon  = $alan === 'memo' ? 'IrisRapor_MemoId' : 'IrisRapor_DtMusteriNo';
    $ph     = implode(',', array_fill(0, count($numaralar), '?'));
    $params = $numaralar;

    // Birim görünürlük kısıtı: kaydın alt bayisi izinli birimlerden birine yetkili mi?
    $birimKisit = '';
    if ($birimKisitli) {
        if (empty($izinliBirimler)) {
            $birimKisit = ' AND 1=0'; // birimi yoksa hiçbir kayıt görünmesin
        } else {
            $bph = implode(',', array_fill(0, count($izinliBirimler), '?'));
            $birimKisit = "
              AND EXISTS (
                  SELECT 1
                  FROM DigiturkAltBayiler a2
                  JOIN KullaniciBirimYetkileri kby2 ON kby2.KullaniciBirimYetkileri_AltBayi_id = a2.DigiturkAltBayiler_Id AND kby2.Durum = 1
                  WHERE a2.DigiturkAltBayiler_Ad = ir.IrisRapor_TalebiGirenPersonelAltbayi AND a2.Durum = 1
                    AND kby2.KullaniciBirimYetkileri_Birim_id IN ($bph)
              )";
            foreach ($izinliBirimler as $b) $params[] = $b;
        }
    }

    return $db->fetchAll("
        SELECT
            ir.IrisRapor_MemoId,
            ir.IrisRapor_DtMusteriNo,
            ir.IrisRapor_TalepTuru,
            ir.IrisRapor_MemoKayitTipi,
            ir.IrisRapor_MemoKodu,
            CONVERT(VARCHAR(19), ir.IrisRapor_TalepGirisTarihi, 120)  AS TalepGirisTarihi,
            CONVERT(VARCHAR(19), ir.IrisRapor_MemoKapanisTarihi, 120) AS MemoKapanisTarihi,
            ir.IrisRapor_TalebiGirenBayiAdi,
            ir.IrisRapor_TalebiGirenPersonel,
            ir.IrisRapor_TalebiGirenPersonelAltbayi,
            bm.birim AS Birim,
            ir.IrisRapor_SatisDurumu,
            ir.IrisRapor_BasvuruSurecDurumu,
            ir.IrisRapor_TeyitDurum,
            ir.IrisRapor_MemoSonDurum,
            ir.IrisRapor_MemoSonAciklama,
            ir.IrisRapor_Paket,
            ir.IrisRapor_Kampanya
        FROM DigiturkIrisRapor ir
        OUTER APPLY (
            SELECT TOP 1 kb.KullaniciBirim_Adi AS birim
            FROM DigiturkAltBayiler a
            JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_AltBayi_id = a.DigiturkAltBayiler_Id
            JOIN KullaniciBirim kb           ON kb.KullaniciBirim_id = kby.KullaniciBirimYetkileri_Birim_id
            WHERE a.DigiturkAltBayiler_Ad = ir.IrisRapor_TalebiGirenPersonelAltbayi
              AND a.Durum = 1 AND kby.Durum = 1
              AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
              AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
            ORDER BY kby.KullaniciBirimYetkileri_id DESC
        ) bm
        WHERE ir.{$kolon} IN ({$ph}){$birimKisit}
        ORDER BY ir.IrisRapor_DtMusteriNo, ir.IrisRapor_TalepGirisTarihi DESC
    ", $params);
}

// Excel indir (JSON header'dan önce yakalanır)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'excel_indir') {
    $alan      = ($_POST['alan'] ?? 'musteri') === 'memo' ? 'memo' : 'musteri';
    $numaralar = musteriAramaNumaralariAyristir($_POST['numaralar'] ?? '');
    if (count($numaralar) > MUSTERI_ARAMA_MAX) $numaralar = array_slice($numaralar, 0, MUSTERI_ARAMA_MAX);
    $list = musteriAramaSorgula($db, $alan, $numaralar, $birimKisitli, $izinliBirimler);

    $filename = 'musteri_arama_' . date('Y-m-d_H-i-s') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">';
    echo '<xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
    echo '<x:Name>MusteriArama</x:Name>';
    echo '<x:WorksheetOptions><x:Print><x:ValidPrinterinfo/></x:Print></x:WorksheetOptions>';
    echo '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml></head><body>';
    echo '<table border="1">';
    echo '<thead><tr style="background-color:#0d6efd;color:#fff;font-weight:bold;">';
    echo '<th>Memo ID</th><th>Müşteri No</th><th>Talep Türü</th><th>Kayıt Tipi</th><th>Memo Kodu</th>';
    echo '<th>Talep Giriş</th><th>Memo Kapanış</th><th>Talebi Giren Bayi</th><th>Personel</th><th>Alt Bayi</th><th>Birim</th>';
    echo '<th>Satış Durumu</th><th>Başvuru Süreç</th><th>Teyit</th><th>Memo Son Durum</th><th>Memo Açıklama</th>';
    echo '<th>Paket</th><th>Kampanya</th>';
    echo '</tr></thead><tbody>';
    foreach ($list as $r) {
        echo '<tr>';
        foreach ([
            'IrisRapor_MemoId','IrisRapor_DtMusteriNo','IrisRapor_TalepTuru','IrisRapor_MemoKayitTipi','IrisRapor_MemoKodu',
            'TalepGirisTarihi','MemoKapanisTarihi','IrisRapor_TalebiGirenBayiAdi','IrisRapor_TalebiGirenPersonel','IrisRapor_TalebiGirenPersonelAltbayi','Birim',
            'IrisRapor_SatisDurumu','IrisRapor_BasvuruSurecDurumu','IrisRapor_TeyitDurum','IrisRapor_MemoSonDurum','IrisRapor_MemoSonAciklama',
            'IrisRapor_Paket','IrisRapor_Kampanya',
        ] as $k) {
            echo '<td style="mso-number-format:\'\@\'">' . htmlspecialchars((string)($r[$k] ?? '')) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'ara':
                $alan      = ($_POST['alan'] ?? 'musteri') === 'memo' ? 'memo' : 'musteri';
                $numaralar = musteriAramaNumaralariAyristir($_POST['numaralar'] ?? '');

                if (empty($numaralar)) {
                    echo json_encode(['success' => false, 'message' => 'Lütfen en az bir numara girin.']);
                    break;
                }

                $asildi = false;
                if (count($numaralar) > MUSTERI_ARAMA_MAX) {
                    $numaralar = array_slice($numaralar, 0, MUSTERI_ARAMA_MAX);
                    $asildi = true;
                }

                $list  = musteriAramaSorgula($db, $alan, $numaralar, $birimKisitli, $izinliBirimler);
                $kolon = $alan === 'memo' ? 'IrisRapor_MemoId' : 'IrisRapor_DtMusteriNo';

                // Bulunan tekil numaralar → bulunamayanları çıkar
                $bulunanSet = [];
                foreach ($list as $r) $bulunanSet[(string)$r[$kolon]] = true;
                $bulunamayan = array_values(array_filter($numaralar, fn($n) => !isset($bulunanSet[$n])));

                echo json_encode([
                    'success'     => true,
                    'data'        => $list,
                    'ozet'        => [
                        'sorgulanan'  => count($numaralar),
                        'eslesen'     => count($bulunanSet),
                        'kayit'       => count($list),
                        'bulunamayan' => count($bulunamayan),
                    ],
                    'bulunamayan' => $bulunamayan,
                    'asildi'      => $asildi,
                    'limit'       => MUSTERI_ARAMA_MAX,
                ]);
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
        .status-badge  { padding: .2rem .5rem; border-radius: .25rem; font-size: .8rem; white-space: nowrap; }
        .status-active   { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        #kayitTable td, #kayitTable th { white-space: nowrap; font-size: .85rem; }
        #bulunamayanKutu { display: none; }
        #bulunamayanListe {
            max-height: 120px; overflow-y: auto; font-family: monospace;
            font-size: .85rem; background: #fff; border: 1px solid #f5c2c7; border-radius: .25rem; padding: .5rem;
        }
        .numara-alan textarea { font-family: monospace; }
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
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-list-ol"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Sorgulanan Numara</span>
                                <span class="info-box-number" id="stat-sorgulanan">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check2-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Eşleşen Numara</span>
                                <span class="info-box-number" id="stat-eslesen">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-collection"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bulunan Kayıt</span>
                                <span class="info-box-number" id="stat-kayit">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bulunamayan</span>
                                <span class="info-box-number" id="stat-bulunamayan">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Arama Paneli -->
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-search"></i> Toplu Müşteri Arama</h3>
                    </div>
                    <div class="card-body">
                        <form id="aramaForm">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label d-block">Arama Alanı</label>
                                    <div class="btn-group" role="group">
                                        <input type="radio" class="btn-check" name="alan" id="alan_musteri" value="musteri" checked>
                                        <label class="btn btn-outline-primary" for="alan_musteri"><i class="bi bi-person-vcard"></i> Müşteri No</label>
                                        <input type="radio" class="btn-check" name="alan" id="alan_memo" value="memo">
                                        <label class="btn btn-outline-primary" for="alan_memo"><i class="bi bi-hash"></i> Memo ID</label>
                                    </div>
                                    <small class="text-muted d-block mt-2">
                                        Her satıra bir numara girin (virgül/boşlukla da ayırabilirsiniz).
                                        En fazla <?= MUSTERI_ARAMA_MAX ?> numara.
                                    </small>
                                    <div class="mt-2">
                                        <span class="badge text-bg-secondary" id="numaraSayaci">0 numara</span>
                                    </div>
                                </div>
                                <div class="col-md-8 numara-alan">
                                    <label class="form-label" id="numaraLabel">Müşteri Numaraları</label>
                                    <textarea class="form-control" name="numaralar" id="numaralar" rows="6"
                                              placeholder="Örn:&#10;123456789&#10;987654321&#10;..."></textarea>
                                </div>
                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Ara</button>
                                    <button type="button" class="btn btn-secondary" id="temizle"><i class="bi bi-x-circle"></i> Temizle</button>
                                    <button type="button" class="btn btn-success" id="excelIndir" disabled><i class="bi bi-file-earmark-excel"></i> Excel indir</button>
                                </div>
                            </div>
                        </form>

                        <div class="alert alert-danger mt-3 mb-0" id="bulunamayanKutu">
                            <strong><i class="bi bi-exclamation-triangle"></i> Bulunamayan Numaralar (<span id="bulunamayanSayi">0</span>)</strong>
                            <div id="bulunamayanListe" class="mt-2"></div>
                        </div>
                    </div>
                </div>

                <!-- Sonuç Listesi -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Arama Sonuçları</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="kayitTable" class="table table-bordered table-striped table-hover" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Memo ID</th>
                                        <th>Müşteri No</th>
                                        <th>Talep Türü</th>
                                        <th>Kayıt Tipi</th>
                                        <th>Talep Giriş</th>
                                        <th>Alt Bayi</th>
                                        <th>Birim</th>
                                        <th>Satış Durumu</th>
                                        <th>Başvuru Süreç</th>
                                        <th>Teyit</th>
                                        <th>Memo Son Durum</th>
                                        <th>Paket</th>
                                        <th>Kampanya</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
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
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
    let dataTable, sonAramaVar = false;

    function numaralariSay() {
        const parcalar = ($('#numaralar').val() || '').split(/[\s,;]+/).filter(x => x.replace(/\D/g, '') !== '');
        const tekil = new Set(parcalar.map(x => x.replace(/\D/g, '')));
        $('#numaraSayaci').text(tekil.size + ' numara');
    }

    $(document).ready(function () {
        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[1, 'asc']],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']],
            scrollX: true
        });

        $('#numaralar').on('input', numaralariSay);

        // Radyo değişince label güncelle
        $('input[name="alan"]').on('change', function () {
            const memo = $('#alan_memo').is(':checked');
            $('#numaraLabel').text(memo ? 'Memo ID Numaraları' : 'Müşteri Numaraları');
        });

        $('#aramaForm').on('submit', function (e) {
            e.preventDefault();
            araYap();
        });

        $('#temizle').on('click', function () {
            $('#numaralar').val('');
            numaralariSay();
            dataTable.clear().draw();
            $('#stat-sorgulanan, #stat-eslesen, #stat-kayit, #stat-bulunamayan').text('0');
            $('#bulunamayanKutu').hide();
            $('#excelIndir').prop('disabled', true);
            sonAramaVar = false;
            showToast('Temizlendi', 'info');
        });

        $('#excelIndir').on('click', function () {
            if (!sonAramaVar) return;
            const form = $('<form>', { method: 'POST', action: '', target: '_blank' });
            form.append($('<input>', { type: 'hidden', name: 'action',    value: 'excel_indir' }));
            form.append($('<input>', { type: 'hidden', name: 'alan',      value: $('input[name="alan"]:checked').val() }));
            form.append($('<textarea>', { name: 'numaralar' }).val($('#numaralar').val()).css('display', 'none'));
            $('body').append(form);
            form.submit();
            form.remove();
            showToast('Excel dosyası indiriliyor...', 'info');
        });
    });

    function araYap() {
        const numaralar = $('#numaralar').val().trim();
        if (!numaralar) { showToast('Lütfen en az bir numara girin.', 'error'); return; }

        $.ajax({
            url: '', method: 'POST',
            data: { action: 'ara', alan: $('input[name="alan"]:checked').val(), numaralar: numaralar },
            dataType: 'json',
            beforeSend: function () { showToast('Aranıyor...', 'info'); },
            success: function (r) {
                if (!r.success) { showToast(r.message || 'Arama hatası', 'error'); return; }

                $('#stat-sorgulanan').text(r.ozet.sorgulanan);
                $('#stat-eslesen').text(r.ozet.eslesen);
                $('#stat-kayit').text(r.ozet.kayit);
                $('#stat-bulunamayan').text(r.ozet.bulunamayan);

                renderTable(r.data);

                if (r.bulunamayan && r.bulunamayan.length) {
                    $('#bulunamayanSayi').text(r.bulunamayan.length);
                    $('#bulunamayanListe').text(r.bulunamayan.join(', '));
                    $('#bulunamayanKutu').show();
                } else {
                    $('#bulunamayanKutu').hide();
                }

                if (r.asildi) showToast('Numara sayısı ' + r.limit + ' sınırını aştı, ilk ' + r.limit + ' tanesi sorgulandı.', 'warning');

                sonAramaVar = true;
                $('#excelIndir').prop('disabled', r.ozet.kayit === 0);

                if (r.ozet.kayit === 0) showToast('Kayıt bulunamadı', 'warning');
                else showToast(r.ozet.kayit + ' kayıt bulundu', 'success');
            },
            error: function () { showToast('Sunucu hatası oluştu', 'error'); }
        });
    }

    function renderTable(rows) {
        dataTable.clear();
        rows.forEach(row => {
            dataTable.row.add([
                escapeHtml(row.IrisRapor_MemoId || '-'),
                escapeHtml(row.IrisRapor_DtMusteriNo || '-'),
                escapeHtml(row.IrisRapor_TalepTuru || '-'),
                escapeHtml(row.IrisRapor_MemoKayitTipi || '-'),
                escapeHtml(row.TalepGirisTarihi || '-'),
                escapeHtml(row.IrisRapor_TalebiGirenPersonelAltbayi || '-'),
                escapeHtml(row.Birim || '-'),
                escapeHtml(row.IrisRapor_SatisDurumu || '-'),
                escapeHtml(row.IrisRapor_BasvuruSurecDurumu || '-'),
                escapeHtml(row.IrisRapor_TeyitDurum || '-'),
                escapeHtml(row.IrisRapor_MemoSonDurum || '-'),
                escapeHtml(row.IrisRapor_Paket || '-'),
                escapeHtml(row.IrisRapor_Kampanya || '-')
            ]);
        });
        dataTable.draw();
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
</script>
</body>
</html>
