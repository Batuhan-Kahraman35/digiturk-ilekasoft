<?php
/**
 * Admin Panel - Başvuru Log
 * basvuru-yonetimi ve basvuru-form sayfalarındaki tüm değişikliklerin (ekle/güncelle/sil)
 * ve API istek/yanıtlarının uçtan uca log kaydı. Salt-okunur.
 * Tablo: dbo.BasvuruLog
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPagefile = basename($_SERVER['PHP_SELF']);
$pageinfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Başvuru Log';
$menuAdi   = $pageinfo['menu_adi'] ?? 'Başvurular';

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// İşlem türü → etiket/renk eşlemesi
function islemBadge($islem) {
    $map = [
        'EKLE'        => ['Eklendi',        '#198754'],
        'GUNCELLE'    => ['Güncellendi',    '#ffc107'],
        'SIL'         => ['Silindi',        '#dc3545'],
        'API_SIPARIS' => ['API Sipariş',    '#0d6efd'],
        'API_SUREC'   => ['API Süreç',      '#6c757d'],
        'KARALISTE_ENGEL' => ['Kara Liste Engel', '#212529'],
    ];
    return $map[$islem] ?? [$islem, '#6c757d'];
}

// =====================================================================
// AJAX işlemleri
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {

            case 'list':
                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 200) $length = 25;

                $search    = trim($_POST['search']['value'] ?? '');
                $basvuru   = $_POST['f_basvuru']   ?? '';
                $islem     = $_POST['f_islem']     ?? '';
                $kullanici = $_POST['f_kullanici'] ?? '';
                $baslangic = trim($_POST['f_baslangic'] ?? '');
                $bitis     = trim($_POST['f_bitis']     ?? '');

                $where  = ["1=1"];
                $params = [];
                if ($search !== '') {
                    $where[] = "(t.BasvuruLog_Aciklama LIKE ? OR b.Isim LIKE ? OR b.Soyisim LIKE ? OR t.BasvuruLog_ApiEndpoint LIKE ?)";
                    for ($i = 0; $i < 4; $i++) $params[] = "%$search%";
                }
                if ($basvuru   !== '') { $where[] = "t.BasvuruLog_Basvuru_id = ?"; $params[] = (int)$basvuru; }
                if ($islem     !== '') { $where[] = "t.BasvuruLog_Islem = ?";      $params[] = $islem; }
                if ($kullanici !== '') { $where[] = "t.OlusturanKullanici = ?";    $params[] = (int)$kullanici; }
                if ($baslangic !== '') { $where[] = "t.OlusturmaTarihi >= ?";      $params[] = $baslangic . ' 00:00:00'; }
                if ($bitis     !== '') { $where[] = "t.OlusturmaTarihi <= ?";      $params[] = $bitis . ' 23:59:59'; }

                $wClause = implode(" AND ", $where);

                $recordsTotal    = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM BasvuruLog")['c'] ?? 0);
                $recordsFiltered = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM BasvuruLog t LEFT JOIN Basvurular b ON b.Basvurular_id = t.BasvuruLog_Basvuru_id WHERE $wClause", $params)['c'] ?? 0);

                $orderMap = [
                    0 => 't.OlusturmaTarihi',
                    1 => 't.BasvuruLog_Basvuru_id',
                    2 => 't.BasvuruLog_Islem',
                    3 => 'KullaniciAdi',
                ];
                $orderIdx = (int)($_POST['order'][0]['column'] ?? 0);
                $orderBy  = $orderMap[$orderIdx] ?? 't.OlusturmaTarihi';
                $orderDir = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

                $data = [];
                if ($recordsFiltered > 0) {
                    $data = $db->fetchAll("
                        SELECT
                            t.BasvuruLog_id,
                            t.BasvuruLog_Basvuru_id,
                            t.BasvuruLog_Islem,
                            t.BasvuruLog_Aciklama,
                            t.BasvuruLog_HttpKodu,
                            t.BasvuruLog_ApiEndpoint,
                            LTRIM(RTRIM(ISNULL(b.Isim,'') + ' ' + ISNULL(b.Soyisim,''))) AS BasvuruAdSoyad,
                            LTRIM(RTRIM(ISNULL(k.kullanici_ad,'') + ' ' + ISNULL(k.kullanici_soyad,''))) AS KullaniciAdi,
                            CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) AS OlusturmaTarihi
                        FROM BasvuruLog t
                        LEFT JOIN Basvurular  b ON b.Basvurular_id = t.BasvuruLog_Basvuru_id
                        LEFT JOIN kullanicilar k ON k.kullanici_id  = t.OlusturanKullanici
                        WHERE $wClause
                        ORDER BY $orderBy $orderDir, t.BasvuruLog_id DESC
                        OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
                    ", $params);
                }

                echo json_encode(['draw' => $draw, 'recordsTotal' => $recordsTotal, 'recordsFiltered' => $recordsFiltered, 'data' => $data]);
                break;

            case 'detay':
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("
                    SELECT
                        t.*,
                        LTRIM(RTRIM(ISNULL(b.Isim,'') + ' ' + ISNULL(b.Soyisim,''))) AS BasvuruAdSoyad,
                        LTRIM(RTRIM(ISNULL(k.kullanici_ad,'') + ' ' + ISNULL(k.kullanici_soyad,''))) AS KullaniciAdi,
                        CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) AS OlusturmaTarihiStr
                    FROM BasvuruLog t
                    LEFT JOIN Basvurular  b ON b.Basvurular_id = t.BasvuruLog_Basvuru_id
                    LEFT JOIN kullanicilar k ON k.kullanici_id  = t.OlusturanKullanici
                    WHERE t.BasvuruLog_id = ?", [$id]);
                if (!$row) { echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı']); break; }
                echo json_encode(['success' => true, 'data' => $row]);
                break;

            case 'stats':
                $stats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) AS c FROM BasvuruLog")['c'] ?? 0,
                    'bugun'  => $db->fetchOne("SELECT COUNT(*) AS c FROM BasvuruLog WHERE CAST(OlusturmaTarihi AS DATE) = CAST(GETDATE() AS DATE)")['c'] ?? 0,
                    'api'    => $db->fetchOne("SELECT COUNT(*) AS c FROM BasvuruLog WHERE BasvuruLog_Islem IN ('API_SIPARIS','API_SUREC')")['c'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// Filtre dropdown verileri
$islemTurleri = $db->fetchAll("SELECT DISTINCT BasvuruLog_Islem AS ad FROM BasvuruLog WHERE BasvuruLog_Islem IS NOT NULL ORDER BY BasvuruLog_Islem");
$kullanicilar = $db->fetchAll("
    SELECT DISTINCT k.kullanici_id AS id,
        LTRIM(RTRIM(ISNULL(k.kullanici_ad,'') + ' ' + ISNULL(k.kullanici_soyad,''))) AS ad
    FROM BasvuruLog t
    INNER JOIN kullanicilar k ON k.kullanici_id = t.OlusturanKullanici
    ORDER BY ad
");
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
        .status-badge { padding: .25rem .5rem; border-radius: .25rem; font-size: .8rem; white-space: nowrap; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; }
        .json-box { background:#f8f9fa; border:1px solid #dee2e6; border-radius:.375rem; padding:.75rem; font-size:.78rem; max-height:340px; overflow:auto; white-space:pre-wrap; word-break:break-all; margin-bottom:0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .diff-eski { background:#fff1f0; }
        .diff-yeni { background:#f0fff4; }
        table.diff-table td { font-size:.82rem; vertical-align:top; }
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
                    <div class="col-md-4">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-journal-text"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Log</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-calendar-day"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün</span>
                                <span class="info-box-number" id="stat-bugun">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-cloud-arrow-up"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">API Gönderim</span>
                                <span class="info-box-number" id="stat-api">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body collapse" id="filterCard">
                        <form id="filterForm">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">Ara</label>
                                    <input type="text" class="form-control" id="filter_search" placeholder="Açıklama, ad, endpoint...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Başvuru ID</label>
                                    <input type="number" class="form-control" id="filter_basvuru" placeholder="ID">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">İşlem</label>
                                    <select class="form-select" id="filter_islem">
                                        <option value="">Tümü</option>
                                        <?php foreach ($islemTurleri as $i): ?>
                                        <option value="<?= htmlspecialchars($i['ad']) ?>"><?= htmlspecialchars(islemBadge($i['ad'])[0]) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Kullanıcı</label>
                                    <select class="form-select" id="filter_kullanici">
                                        <option value="">Tümü</option>
                                        <?php foreach ($kullanicilar as $k): ?>
                                        <option value="<?= (int)$k['id'] ?>"><?= htmlspecialchars($k['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Başlangıç</label>
                                    <input type="date" class="form-control" id="filter_baslangic">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Bitiş</label>
                                    <input type="date" class="form-control" id="filter_bitis">
                                </div>
                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                    <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Liste -->
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Log Kayıtları</h3></div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>Başvuru</th>
                                    <th>İşlem</th>
                                    <th>Kullanıcı</th>
                                    <th>Açıklama</th>
                                    <th style="width:80px">Detay</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Detay Modal -->
<div class="modal fade" id="detayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-journal-text"></i> Log Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detayBody">
                <div class="text-center text-muted py-4">Yükleniyor...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
            </div>
        </div>
    </div>
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
    const ISLEM_RENK = {
        'EKLE':        { ad: 'Eklendi',     renk: '#198754' },
        'GUNCELLE':    { ad: 'Güncellendi', renk: '#ffc107' },
        'SIL':         { ad: 'Silindi',     renk: '#dc3545' },
        'API_SIPARIS': { ad: 'API Sipariş', renk: '#0d6efd' },
        'API_SUREC':   { ad: 'API Süreç',   renk: '#6c757d' },
        'KARALISTE_ENGEL': { ad: 'Kara Liste Engel', renk: '#212529' }
    };

    let dataTable, detayModal;

    $(document).ready(function () {
        detayModal = new bootstrap.Modal(document.getElementById('detayModal'));

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            processing: true,
            serverSide: true,
            dom: 'lrtip',
            order: [[0, 'desc']],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            ajax: {
                url: '', type: 'POST',
                data: function (d) {
                    d.action       = 'list';
                    d.search.value = $('#filter_search').val() || '';
                    d.f_basvuru    = $('#filter_basvuru').val() || '';
                    d.f_islem      = $('#filter_islem').val() || '';
                    d.f_kullanici  = $('#filter_kullanici').val() || '';
                    d.f_baslangic  = $('#filter_baslangic').val() || '';
                    d.f_bitis      = $('#filter_bitis').val() || '';
                    return d;
                },
                error: function () { showToast('Liste yüklenirken hata oluştu', 'error'); }
            },
            columns: [
                { data: 'OlusturmaTarihi', render: d => escapeHtml(d || '-') },
                { data: null, orderable: false, render: (d, t, r) => basvuruCell(r) },
                { data: 'BasvuruLog_Islem', render: d => islemBadge(d) },
                { data: 'KullaniciAdi', render: d => escapeHtml(d || '-') },
                { data: 'BasvuruLog_Aciklama', render: d => escapeHtml(d || '-') },
                { data: null, orderable: false, render: (d, t, r) => `<button class="btn btn-sm btn-outline-primary" onclick="detayAc(${r.BasvuruLog_id})" title="Detay"><i class="bi bi-eye"></i></button>` }
            ]
        });

        loadStats();

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            dataTable.ajax.reload();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filterForm')[0].reset();
            $('#filter_islem, #filter_kullanici').val('').trigger('change');
            dataTable.ajax.reload();
            showToast('Filtreler temizlendi', 'info');
        });
    });

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-bugun').text(r.data.bugun);
            $('#stat-api').text(r.data.api);
        }, 'json');
    }

    function basvuruCell(row) {
        if (!row.BasvuruLog_Basvuru_id) return '<span class="text-muted">-</span>';
        const ad = row.BasvuruAdSoyad ? ' — ' + escapeHtml(row.BasvuruAdSoyad) : '';
        return `<a href="/Admin/basvuru-form?id=${row.BasvuruLog_Basvuru_id}" target="_blank">#${row.BasvuruLog_Basvuru_id}</a>${ad}`;
    }

    function islemBadge(islem) {
        const i = ISLEM_RENK[islem] || { ad: islem || '-', renk: '#6c757d' };
        const yazi = i.renk === '#ffc107' ? '#000' : '#fff';
        return `<span class="status-badge" style="background:${i.renk};color:${yazi}">${escapeHtml(i.ad)}</span>`;
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function jsonPretty(raw) {
        if (!raw) return null;
        try { return JSON.stringify(JSON.parse(raw), null, 2); } catch (e) { return raw; }
    }

    function detayAc(id) {
        $('#detayBody').html('<div class="text-center text-muted py-4">Yükleniyor...</div>');
        detayModal.show();
        $.post('', { action: 'detay', id: id }, function (r) {
            if (!r.success) { $('#detayBody').html('<div class="alert alert-danger">' + escapeHtml(r.message || 'Hata') + '</div>'); return; }
            $('#detayBody').html(detayHtml(r.data));
        }, 'json').fail(function () {
            $('#detayBody').html('<div class="alert alert-danger">Detay alınamadı</div>');
        });
    }

    function detayHtml(d) {
        const i = ISLEM_RENK[d.BasvuruLog_Islem] || { ad: d.BasvuruLog_Islem, renk: '#6c757d' };
        let html = '';

        // Üst bilgi
        html += '<div class="row mb-3 small">';
        html += metaCol('Tarih', d.OlusturmaTarihiStr);
        html += metaCol('İşlem', islemBadge(d.BasvuruLog_Islem));
        html += metaCol('Kullanıcı', escapeHtml(d.KullaniciAdi || '-'));
        html += metaCol('Başvuru', d.BasvuruLog_Basvuru_id ? ('#' + d.BasvuruLog_Basvuru_id + (d.BasvuruAdSoyad ? ' — ' + escapeHtml(d.BasvuruAdSoyad) : '')) : '-');
        html += metaCol('IP', escapeHtml(d.BasvuruLog_IP || '-'));
        if (d.BasvuruLog_Aciklama) html += metaCol('Açıklama', escapeHtml(d.BasvuruLog_Aciklama));
        html += '</div>';

        // Değişen alanlar (GUNCELLE)
        if (d.BasvuruLog_DegisenAlanlar) {
            let deg = null;
            try { deg = JSON.parse(d.BasvuruLog_DegisenAlanlar); } catch (e) {}
            if (deg && Object.keys(deg).length) {
                html += '<h6 class="fw-bold mt-2"><i class="bi bi-pencil-square"></i> Değişen Alanlar</h6>';
                html += '<div class="table-responsive"><table class="table table-sm table-bordered diff-table"><thead><tr><th>Alan</th><th>Önceki</th><th>Sonraki</th></tr></thead><tbody>';
                Object.keys(deg).forEach(function (alan) {
                    html += '<tr><td class="fw-semibold">' + escapeHtml(alan) + '</td>' +
                            '<td class="diff-eski">' + escapeHtml(deg[alan].eski === null ? '(boş)' : deg[alan].eski) + '</td>' +
                            '<td class="diff-yeni">' + escapeHtml(deg[alan].yeni === null ? '(boş)' : deg[alan].yeni) + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
        }

        // Öncesi / Sonrası tam JSON
        if (d.BasvuruLog_Oncesi || d.BasvuruLog_Sonrasi) {
            html += '<div class="row mt-2">';
            if (d.BasvuruLog_Oncesi)  html += '<div class="col-md-6"><label class="form-label fw-bold mb-1">Öncesi</label><pre class="json-box">' + escapeHtml(jsonPretty(d.BasvuruLog_Oncesi)) + '</pre></div>';
            if (d.BasvuruLog_Sonrasi) html += '<div class="col-md-6"><label class="form-label fw-bold mb-1">Sonrası</label><pre class="json-box">' + escapeHtml(jsonPretty(d.BasvuruLog_Sonrasi)) + '</pre></div>';
            html += '</div>';
        }

        // API istek / yanıt
        if (d.BasvuruLog_ApiEndpoint || d.BasvuruLog_ApiIstek || d.BasvuruLog_ApiYanit) {
            html += '<hr><h6 class="fw-bold"><i class="bi bi-cloud"></i> API Bilgisi</h6>';
            if (d.BasvuruLog_ApiEndpoint) html += '<div class="small mb-2"><b>Endpoint:</b> <span class="mono">' + escapeHtml(d.BasvuruLog_ApiEndpoint) + '</span>' + (d.BasvuruLog_HttpKodu ? ' <span class="badge bg-secondary">HTTP ' + d.BasvuruLog_HttpKodu + '</span>' : '') + '</div>';
            html += '<div class="row">';
            html += '<div class="col-md-6"><label class="form-label fw-bold mb-1">Gönderilen</label><pre class="json-box">' + escapeHtml(jsonPretty(d.BasvuruLog_ApiIstek) || '—') + '</pre></div>';
            html += '<div class="col-md-6"><label class="form-label fw-bold mb-1">Yanıt</label><pre class="json-box">' + escapeHtml(jsonPretty(d.BasvuruLog_ApiYanit) || '—') + '</pre></div>';
            html += '</div>';
        }

        return html;
    }

    function metaCol(label, value) {
        return '<div class="col-md-4 mb-1"><span class="text-muted">' + escapeHtml(label) + ':</span> ' + value + '</div>';
    }
</script>
</body>
</html>
