<?php
/**
 * Admin Panel - API Güvenlik Log
 * /api/ gateway'ine gelen tüm isteklerin erişim/güvenlik logu (IP, endpoint, sonuç, süre).
 * Login denemeleri org + kullanıcı adı ile kaydedilir; ŞİFRE hiçbir zaman loglanmaz.
 * Salt-okunur. Tablo: dbo.ApiGuvenlikLog
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'API Güvenlik Log';
$menuAdi   = $pageinfo['menu_adi'] ?? 'Digiturk API';

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Sonuç → renk eşlemesi (etiketler DB'den DISTINCT gelir; renk yoksa gri)
function sonucRenk($sonuc) {
    $map = [
        'BASARILI'   => '#198754',
        'YETKISIZ'   => '#fd7e14',
        'RATE_LIMIT' => '#dc3545',
        'BASARISIZ'  => '#6c757d',
    ];
    return $map[$sonuc] ?? '#6c757d';
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
                $sonuc     = $_POST['f_sonuc']     ?? '';
                $metod     = $_POST['f_metod']     ?? '';
                $ip        = trim($_POST['f_ip']   ?? '');
                $baslangic = trim($_POST['f_baslangic'] ?? '');
                $bitis     = trim($_POST['f_bitis']     ?? '');

                $where  = ["1=1"];
                $params = [];
                if ($search !== '') {
                    $where[] = "(t.ApiGuvenlikLog_Endpoint LIKE ? OR t.ApiGuvenlikLog_LoginKullanici LIKE ? OR t.ApiGuvenlikLog_Organisation LIKE ? OR t.ApiGuvenlikLog_IP LIKE ?)";
                    for ($i = 0; $i < 4; $i++) $params[] = "%$search%";
                }
                if ($sonuc     !== '') { $where[] = "t.ApiGuvenlikLog_Sonuc = ?";  $params[] = $sonuc; }
                if ($metod     !== '') { $where[] = "t.ApiGuvenlikLog_Metod = ?";  $params[] = $metod; }
                if ($ip        !== '') { $where[] = "t.ApiGuvenlikLog_IP LIKE ?";  $params[] = "%$ip%"; }
                if ($baslangic !== '') { $where[] = "t.OlusturmaTarihi >= ?";      $params[] = $baslangic . ' 00:00:00'; }
                if ($bitis     !== '') { $where[] = "t.OlusturmaTarihi <= ?";      $params[] = $bitis . ' 23:59:59'; }

                $wClause = implode(" AND ", $where);

                $recordsTotal    = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM ApiGuvenlikLog")['c'] ?? 0);
                $recordsFiltered = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM ApiGuvenlikLog t WHERE $wClause", $params)['c'] ?? 0);

                $orderMap = [
                    0 => 't.OlusturmaTarihi',
                    1 => 't.ApiGuvenlikLog_IP',
                    2 => 't.ApiGuvenlikLog_Endpoint',
                    3 => 't.ApiGuvenlikLog_Metod',
                    5 => 't.ApiGuvenlikLog_Sonuc',
                    6 => 't.ApiGuvenlikLog_HttpKodu',
                    7 => 't.ApiGuvenlikLog_SureMs',
                ];
                $orderIdx = (int)($_POST['order'][0]['column'] ?? 0);
                $orderBy  = $orderMap[$orderIdx] ?? 't.OlusturmaTarihi';
                $orderDir = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

                $data = [];
                if ($recordsFiltered > 0) {
                    $data = $db->fetchAll("
                        SELECT
                            t.ApiGuvenlikLog_id,
                            t.ApiGuvenlikLog_IP,
                            t.ApiGuvenlikLog_Endpoint,
                            t.ApiGuvenlikLog_Metod,
                            t.ApiGuvenlikLog_Personel_id,
                            t.ApiGuvenlikLog_LoginKullanici,
                            t.ApiGuvenlikLog_HttpKodu,
                            t.ApiGuvenlikLog_Sonuc,
                            t.ApiGuvenlikLog_SureMs,
                            p.DigiturkAltBayiPersonel_KullaniciAdi AS PersonelKullaniciAdi,
                            CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) AS OlusturmaTarihi
                        FROM ApiGuvenlikLog t
                        LEFT JOIN DigiturkAltBayiPersonel p ON p.DigiturkAltBayiPersonel_Id = t.ApiGuvenlikLog_Personel_id
                        WHERE $wClause
                        ORDER BY $orderBy $orderDir, t.ApiGuvenlikLog_id DESC
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
                        p.DigiturkAltBayiPersonel_KullaniciAdi AS PersonelKullaniciAdi,
                        CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) AS OlusturmaTarihiStr
                    FROM ApiGuvenlikLog t
                    LEFT JOIN DigiturkAltBayiPersonel p ON p.DigiturkAltBayiPersonel_Id = t.ApiGuvenlikLog_Personel_id
                    WHERE t.ApiGuvenlikLog_id = ?", [$id]);
                if (!$row) { echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı']); break; }
                echo json_encode(['success' => true, 'data' => $row]);
                break;

            case 'stats':
                $stats = [
                    'toplam'      => $db->fetchOne("SELECT COUNT(*) AS c FROM ApiGuvenlikLog")['c'] ?? 0,
                    'bugun'       => $db->fetchOne("SELECT COUNT(*) AS c FROM ApiGuvenlikLog WHERE CAST(OlusturmaTarihi AS DATE) = CAST(GETDATE() AS DATE)")['c'] ?? 0,
                    'basarisizLogin' => $db->fetchOne("
                        SELECT COUNT(*) AS c FROM ApiGuvenlikLog
                        WHERE ApiGuvenlikLog_Endpoint = 'Authentication/Login'
                          AND ApiGuvenlikLog_Sonuc <> 'BASARILI'
                          AND CAST(OlusturmaTarihi AS DATE) = CAST(GETDATE() AS DATE)
                    ")['c'] ?? 0,
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

// Filtre dropdown verileri (DB'den DISTINCT — kodda sabit liste yok)
$sonucList = $db->fetchAll("SELECT DISTINCT ApiGuvenlikLog_Sonuc AS ad FROM ApiGuvenlikLog WHERE ApiGuvenlikLog_Sonuc IS NOT NULL ORDER BY ApiGuvenlikLog_Sonuc");
$metodList = $db->fetchAll("SELECT DISTINCT ApiGuvenlikLog_Metod AS ad FROM ApiGuvenlikLog WHERE ApiGuvenlikLog_Metod IS NOT NULL ORDER BY ApiGuvenlikLog_Metod");
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
                            <span class="info-box-icon"><i class="bi bi-hdd-network"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam İstek</span>
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
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-shield-exclamation"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün Başarısız Login</span>
                                <span class="info-box-number" id="stat-basarisizLogin">0</span>
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
                                    <input type="text" class="form-control" id="filter_search" placeholder="Endpoint, kullanıcı, org, IP...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Sonuç</label>
                                    <select class="form-select" id="filter_sonuc">
                                        <option value="">Tümü</option>
                                        <?php foreach ($sonucList as $s): ?>
                                        <option value="<?= htmlspecialchars($s['ad']) ?>"><?= htmlspecialchars($s['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Metod</label>
                                    <select class="form-select" id="filter_metod">
                                        <option value="">Tümü</option>
                                        <?php foreach ($metodList as $m): ?>
                                        <option value="<?= htmlspecialchars($m['ad']) ?>"><?= htmlspecialchars($m['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">IP</label>
                                    <input type="text" class="form-control" id="filter_ip" placeholder="IP adresi">
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
                    <div class="card-header"><h3 class="card-title">İstek Kayıtları</h3></div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>IP</th>
                                    <th>Endpoint</th>
                                    <th>Metod</th>
                                    <th>Personel / Login</th>
                                    <th>Sonuç</th>
                                    <th>HTTP</th>
                                    <th>Süre</th>
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
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-hdd-network"></i> İstek Detayı</h5>
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
    const SONUC_RENK = {
        'BASARILI':   '#198754',
        'YETKISIZ':   '#fd7e14',
        'RATE_LIMIT': '#dc3545',
        'BASARISIZ':  '#6c757d'
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
                    d.f_sonuc      = $('#filter_sonuc').val() || '';
                    d.f_metod      = $('#filter_metod').val() || '';
                    d.f_ip         = $('#filter_ip').val() || '';
                    d.f_baslangic  = $('#filter_baslangic').val() || '';
                    d.f_bitis      = $('#filter_bitis').val() || '';
                    return d;
                },
                error: function () { showToast('Liste yüklenirken hata oluştu', 'error'); }
            },
            columns: [
                { data: 'OlusturmaTarihi', render: d => escapeHtml(d || '-') },
                { data: 'ApiGuvenlikLog_IP', render: d => `<span class="mono">${escapeHtml(d || '-')}</span>` },
                { data: 'ApiGuvenlikLog_Endpoint', render: d => `<span class="mono">${escapeHtml(d || '-')}</span>` },
                { data: 'ApiGuvenlikLog_Metod', render: d => escapeHtml(d || '-') },
                { data: null, orderable: false, render: (d, t, r) => personelCell(r) },
                { data: 'ApiGuvenlikLog_Sonuc', render: d => sonucBadge(d) },
                { data: 'ApiGuvenlikLog_HttpKodu', render: d => escapeHtml(d || '-') },
                { data: 'ApiGuvenlikLog_SureMs', render: d => (d === null || d === undefined) ? '-' : (d + ' ms') },
                { data: null, orderable: false, render: (d, t, r) => `<button class="btn btn-sm btn-outline-primary" onclick="detayAc(${r.ApiGuvenlikLog_id})" title="Detay"><i class="bi bi-eye"></i></button>` }
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
            $('#filter_sonuc, #filter_metod').val('').trigger('change');
            dataTable.ajax.reload();
            showToast('Filtreler temizlendi', 'info');
        });
    });

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-bugun').text(r.data.bugun);
            $('#stat-basarisizLogin').text(r.data.basarisizLogin);
        }, 'json');
    }

    function personelCell(row) {
        if (row.PersonelKullaniciAdi) {
            return escapeHtml(row.PersonelKullaniciAdi) + (row.ApiGuvenlikLog_Personel_id ? ' <span class="text-muted">#' + row.ApiGuvenlikLog_Personel_id + '</span>' : '');
        }
        if (row.ApiGuvenlikLog_LoginKullanici) {
            return '<span class="mono">' + escapeHtml(row.ApiGuvenlikLog_LoginKullanici) + '</span> <span class="text-muted small">(deneme)</span>';
        }
        return '<span class="text-muted">-</span>';
    }

    function sonucBadge(sonuc) {
        const renk = SONUC_RENK[sonuc] || '#6c757d';
        const yazi = renk === '#ffc107' ? '#000' : '#fff';
        return `<span class="status-badge" style="background:${renk};color:${yazi}">${escapeHtml(sonuc || '-')}</span>`;
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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
        let html = '<div class="row mb-2 small">';
        html += metaCol('Tarih', escapeHtml(d.OlusturmaTarihiStr || '-'));
        html += metaCol('IP', '<span class="mono">' + escapeHtml(d.ApiGuvenlikLog_IP || '-') + '</span>');
        html += metaCol('Metod', escapeHtml(d.ApiGuvenlikLog_Metod || '-'));
        html += metaCol('Endpoint', '<span class="mono">' + escapeHtml(d.ApiGuvenlikLog_Endpoint || '-') + '</span>');
        html += metaCol('Sonuç', sonucBadge(d.ApiGuvenlikLog_Sonuc));
        html += metaCol('HTTP', escapeHtml(d.ApiGuvenlikLog_HttpKodu || '-'));
        html += metaCol('Süre', (d.ApiGuvenlikLog_SureMs === null || d.ApiGuvenlikLog_SureMs === undefined) ? '-' : (d.ApiGuvenlikLog_SureMs + ' ms'));
        html += metaCol('Personel', d.PersonelKullaniciAdi ? (escapeHtml(d.PersonelKullaniciAdi) + ' #' + d.ApiGuvenlikLog_Personel_id) : '-');
        html += metaCol('Organisation', escapeHtml(d.ApiGuvenlikLog_Organisation || '-'));
        html += metaCol('Login Kullanıcı', escapeHtml(d.ApiGuvenlikLog_LoginKullanici || '-'));
        html += '</div>';

        if (d.ApiGuvenlikLog_UserAgent) {
            html += '<div class="small mb-2"><span class="text-muted">User-Agent:</span> <span class="mono">' + escapeHtml(d.ApiGuvenlikLog_UserAgent) + '</span></div>';
        }
        if (d.ApiGuvenlikLog_Aciklama) {
            html += '<div class="small mb-2"><span class="text-muted">Açıklama:</span> ' + escapeHtml(d.ApiGuvenlikLog_Aciklama) + '</div>';
        }
        html += '<div class="alert alert-light border small mb-0"><i class="bi bi-shield-lock"></i> Güvenlik gereği şifre hiçbir zaman loglanmaz.</div>';
        return html;
    }

    function metaCol(label, value) {
        return '<div class="col-md-4 mb-1"><span class="text-muted">' + escapeHtml(label) + ':</span> ' + value + '</div>';
    }
</script>
</body>
</html>
