<?php
/**
 * Admin Panel - Başvuru Durum Yönetimi
 * İki tablo tek sayfada: BasvuruDurum + BasvuruSurecDurum
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Başvuru Durum Yönetimi';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

/**
 * Tabloya göre konfigürasyon (statik string yerine merkezi tanım)
 */
function tabloConfig(string $type): ?array {
    $map = [
        'durum' => [
            'tablo' => 'BasvuruDurum',
            'pk'    => 'BasvuruDurum_id',
            'cols'  => [
                'kod'   => 'BasvuruDurum_DurumKodu',
                'mesaj' => 'BasvuruDurum_Mesaj',
                'renk'  => 'BasvuruDurum_Renk',
            ],
        ],
        'surec' => [
            'tablo' => 'BasvuruSurecDurum',
            'pk'    => 'BasvuruSurecDurum_id',
            'cols'  => [
                'mesaj'    => 'BasvuruSurecDurum_Mesaj',
                'aciklama' => 'BasvuruSurecDurum_Aciklama',
                'renk'     => 'BasvuruSurecDurum_Renk',
                // Sonuçlanmış süreçler (tamamlandı/iptal vb.) süreç sorgulama cron'unda ve
                // listelerde "açık" sayılmaz; kodda sabit liste yerine bu bayrak okunur.
                'sonuc'    => 'BasvuruSurecDurum_Sonuclandi',
            ],
        ],
    ];
    return $map[$type] ?? null;
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $type   = $_POST['type']   ?? '';
    $cfg    = tabloConfig($type);

    if (!$cfg && !in_array($action, ['stats'])) {
        echo json_encode(['success' => false, 'message' => 'Geçersiz tablo türü']);
        exit;
    }

    try {
        switch ($action) {

            case 'list':
                $search = trim($_POST['search'] ?? '');
                $c      = $cfg['cols'];

                $where  = ["1=1"];
                $params = [];

                if ($search !== '') {
                    if ($type === 'durum') {
                        $where[]  = "(CAST(t.{$c['kod']} AS NVARCHAR) LIKE ? OR t.{$c['mesaj']} LIKE ?)";
                        $params[] = "%$search%";
                        $params[] = "%$search%";
                    } else {
                        $where[]  = "(t.{$c['mesaj']} LIKE ? OR t.{$c['aciklama']} LIKE ?)";
                        $params[] = "%$search%";
                        $params[] = "%$search%";
                    }
                }

                $whereClause = implode(" AND ", $where);

                if ($type === 'durum') {
                    $select = "t.{$cfg['pk']} AS id, t.{$c['kod']} AS kod, t.{$c['mesaj']} AS mesaj, t.{$c['renk']} AS renk";
                    $order  = "t.{$c['kod']}";
                } else {
                    $select = "t.{$cfg['pk']} AS id, t.{$c['mesaj']} AS mesaj, t.{$c['aciklama']} AS aciklama, t.{$c['renk']} AS renk, t.{$c['sonuc']} AS sonuc";
                    $order  = "t.{$cfg['pk']}";
                }

                $list = $db->fetchAll("
                    SELECT $select,
                        CONVERT(VARCHAR(19), t.GuncellemeTarihi, 120) AS GuncellemeTarihi,
                        k.kullanici_ad + ' ' + k.kullanici_soyad AS GuncelleyenAd
                    FROM {$cfg['tablo']} t
                    LEFT JOIN kullanicilar k ON t.GuncelleyenKullanici = k.kullanici_id
                    WHERE $whereClause
                    ORDER BY $order
                ", $params);

                echo json_encode(['success' => true, 'data' => $list], JSON_UNESCAPED_UNICODE);
                break;

            case 'get':
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("SELECT * FROM {$cfg['tablo']} WHERE {$cfg['pk']} = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $row]);
                break;

            case 'save':
                $id = (int)($_POST['id'] ?? 0);

                if ($id > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($id == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }

                $c    = $cfg['cols'];
                $renk = trim($_POST['renk'] ?? '');

                // Renk format doğrulama (#RRGGBB)
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $renk)) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz renk formatı (#RRGGBB olmalı)']);
                    break;
                }

                if ($type === 'durum') {
                    $mesaj = trim($_POST['mesaj'] ?? '');
                    if ($mesaj === '' || $_POST['kod'] === '' || !is_numeric($_POST['kod'])) {
                        echo json_encode(['success' => false, 'message' => 'Durum Kodu ve Mesaj zorunludur']);
                        break;
                    }
                    $data = [
                        $c['kod']   => (int)$_POST['kod'],
                        $c['mesaj'] => $mesaj,
                        $c['renk']  => $renk,
                    ];
                } else {
                    $mesaj = trim($_POST['mesaj'] ?? '');
                    if ($mesaj === '') {
                        echo json_encode(['success' => false, 'message' => 'Mesaj zorunludur']);
                        break;
                    }
                    $data = [
                        $c['mesaj']    => $mesaj,
                        $c['aciklama'] => trim($_POST['aciklama'] ?? ''),
                        $c['renk']     => $renk,
                        $c['sonuc']    => !empty($_POST['sonuclandi']) ? 1 : 0,
                    ];
                }

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $db->update($cfg['tablo'], $data, [$cfg['pk'] => $id]);
                    echo json_encode(['success' => true, 'message' => 'Kayıt güncellendi']);
                } else {
                    $data['OlusturanKullanici']   = $user['kullanici_id'];
                    $data['OlusturmaTarihi']      = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $db->insert($cfg['tablo'], $data);
                    echo json_encode(['success' => true, 'message' => 'Kayıt eklendi']);
                }
                break;

            // Tablo içi switch: yalnız "Sonuçlandı" bayrağını değiştirir
            case 'sonuc_toggle':
                if ($type !== 'surec') {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz tablo türü']);
                    break;
                }
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                $id = (int)($_POST['id'] ?? 0);
                $db->update($cfg['tablo'], [
                    $cfg['cols']['sonuc']  => !empty($_POST['deger']) ? 1 : 0,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], [$cfg['pk'] => $id]);
                echo json_encode(['success' => true, 'message' => !empty($_POST['deger'])
                    ? 'Süreç sonuçlanmış olarak işaretlendi' : 'Süreç açık olarak işaretlendi']);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id = (int)($_POST['id'] ?? 0);
                $db->delete($cfg['tablo'], [$cfg['pk'] => $id]);
                echo json_encode(['success' => true, 'message' => 'Kayıt silindi']);
                break;

            case 'stats':
                $stats = [
                    'durum' => $db->fetchOne("SELECT COUNT(*) AS c FROM BasvuruDurum")['c'] ?? 0,
                    'surec' => $db->fetchOne("SELECT COUNT(*) AS c FROM BasvuruSurecDurum")['c'] ?? 0,
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
        .renk-badge { display:inline-block; padding:.3rem .7rem; border-radius:.3rem; color:#fff; font-weight:600; font-size:.85rem; text-shadow:0 1px 1px rgba(0,0,0,.25); }
        .kod-badge  { font-family:monospace; font-weight:700; background:#e9ecef; padding:.2rem .55rem; border-radius:.3rem; }
        .renk-onizleme { width:42px; height:38px; border:1px solid #ced4da; border-radius:.375rem; padding:2px; cursor:pointer; }
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
                    <div class="col-md-6">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-flag"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Başvuru Durumu</span>
                                <span class="info-box-number" id="stat-durum">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-diagram-3"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Süreç Durumu</span>
                                <span class="info-box-number" id="stat-surec">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sekmeler -->
                <ul class="nav nav-tabs" id="anaTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-durum-btn" data-bs-toggle="tab" data-bs-target="#tab-durum" type="button" role="tab">
                            <i class="bi bi-flag"></i> Başvuru Durumları
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-surec-btn" data-bs-toggle="tab" data-bs-target="#tab-surec" type="button" role="tab">
                            <i class="bi bi-diagram-3"></i> Süreç Durumları
                        </button>
                    </li>
                </ul>

                <div class="tab-content bg-body p-3 border border-top-0 rounded-bottom">

                    <!-- ============ TAB: BAŞVURU DURUMLARI ============ -->
                    <div class="tab-pane fade show active" id="tab-durum" role="tabpanel">
                        <!-- Filtre -->
                        <div class="card card-primary card-outline mb-3">
                            <div class="card-header">
                                <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterDurum"><i class="bi bi-chevron-down"></i></button>
                                </div>
                            </div>
                            <div class="card-body collapse" id="filterDurum">
                                <form class="filterForm" data-type="durum">
                                    <div class="row g-3">
                                        <div class="col-md-9">
                                            <label class="form-label">Ara</label>
                                            <input type="text" class="form-control f-search" placeholder="Durum kodu veya mesaj...">
                                        </div>
                                        <div class="col-md-3 d-flex align-items-end gap-2">
                                            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                            <button type="reset" class="btn btn-secondary f-clear"><i class="bi bi-x-circle"></i> Temizle</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Başvuru Durumları</h3>
                                <div class="card-tools">
                                    <?php if ($permissions['can_add']): ?>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="yeniKayit('durum')">
                                        <i class="bi bi-plus-circle"></i> Yeni Ekle
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <table id="durumTable" class="table table-bordered table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width:120px">Durum Kodu</th>
                                            <th>Mesaj</th>
                                            <th style="width:160px">Renk</th>
                                            <th style="width:160px">Son Güncelleme</th>
                                            <th>Güncelleyen</th>
                                            <th style="width:100px">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- ============ TAB: SÜREÇ DURUMLARI ============ -->
                    <div class="tab-pane fade" id="tab-surec" role="tabpanel">
                        <!-- Filtre -->
                        <div class="card card-info card-outline mb-3">
                            <div class="card-header">
                                <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterSurec"><i class="bi bi-chevron-down"></i></button>
                                </div>
                            </div>
                            <div class="card-body collapse" id="filterSurec">
                                <form class="filterForm" data-type="surec">
                                    <div class="row g-3">
                                        <div class="col-md-9">
                                            <label class="form-label">Ara</label>
                                            <input type="text" class="form-control f-search" placeholder="Mesaj veya açıklama...">
                                        </div>
                                        <div class="col-md-3 d-flex align-items-end gap-2">
                                            <button type="submit" class="btn btn-info"><i class="bi bi-search"></i> Filtrele</button>
                                            <button type="reset" class="btn btn-secondary f-clear"><i class="bi bi-x-circle"></i> Temizle</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Süreç Durumları</h3>
                                <div class="card-tools">
                                    <?php if ($permissions['can_add']): ?>
                                    <button type="button" class="btn btn-info btn-sm" onclick="yeniKayit('surec')">
                                        <i class="bi bi-plus-circle"></i> Yeni Ekle
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <table id="surecTable" class="table table-bordered table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Mesaj</th>
                                            <th>Açıklama</th>
                                            <th class="text-center" style="width:110px" title="İşaretli süreçler sonuçlanmış sayılır; süreç sorgulama cron'u bu başvuruları sorgulamaz">Sonuçlandı</th>
                                            <th style="width:160px">Renk</th>
                                            <th style="width:160px">Son Güncelleme</th>
                                            <th>Güncelleyen</th>
                                            <th style="width:100px">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- Kayıt Modal (ortak) -->
<div class="modal fade" id="kayitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kayitModalLabel">Yeni Kayıt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kayitForm">
                <div class="modal-body">
                    <input type="hidden" id="rec_id" name="id">
                    <input type="hidden" id="rec_type" name="type">
                    <div class="row g-3">
                        <!-- Sadece BasvuruDurum -->
                        <div class="col-md-4 only-durum">
                            <label class="form-label">Durum Kodu <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="f_kod" name="kod" placeholder="Ör: 0, 1, 200">
                        </div>
                        <div class="col-md-8 col-mesaj">
                            <label class="form-label">Mesaj <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="f_mesaj" name="mesaj" required placeholder="Ör: TAMAM, BEKLEMEDE">
                        </div>
                        <!-- Sadece BasvuruSurecDurum -->
                        <div class="col-md-12 only-surec">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" id="f_aciklama" name="aciklama" rows="2" placeholder="Sürecin detaylı açıklaması..."></textarea>
                        </div>
                        <div class="col-md-12 only-surec">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="f_sonuclandi" name="sonuclandi" value="1">
                                <label class="form-check-label" for="f_sonuclandi">Sonuçlandı <small class="text-muted">(tamamlandı / iptal vb. — süreç sorgulama cron'u bu başvuruları artık sorgulamaz)</small></label>
                            </div>
                        </div>
                        <!-- Renk (ortak) -->
                        <div class="col-md-12">
                            <label class="form-label">Renk <span class="text-danger">*</span></label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="renk-onizleme" id="f_renk_picker" value="#6c757d">
                                <input type="text" class="form-control font-monospace" id="f_renk" name="renk" value="#6c757d" maxlength="7" style="max-width:140px" placeholder="#RRGGBB">
                                <span class="renk-badge" id="renk_onizleme_badge" style="background:#6c757d">Önizleme</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
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
    const permissions = {
        canAdd:    <?= $permissions['can_add']    ? 'true' : 'false' ?>,
        canEdit:   <?= $permissions['can_edit']   ? 'true' : 'false' ?>,
        canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
    };

    let kayitModal;
    const tables  = {};           // type => DataTable
    const filters = { durum: {}, surec: {} };

    $(document).ready(function () {
        kayitModal = new bootstrap.Modal(document.getElementById('kayitModal'));

        tables.durum = $('#durumTable').DataTable(dtConfig([[0, 'asc']], [2,3,5]));
        tables.surec = $('#surecTable').DataTable(dtConfig([[0, 'asc']], [2,3,4,6]));

        loadStats();
        loadList('durum');
        loadList('surec');

        // Filtre formları
        $('.filterForm').on('submit', function (e) {
            e.preventDefault();
            const type = $(this).data('type');
            filters[type] = { search: $(this).find('.f-search').val() };
            loadList(type);
            showToast('Filtre uygulandı', 'info');
        });
        $('.f-clear').on('click', function () {
            const type = $(this).closest('.filterForm').data('type');
            filters[type] = {};
            setTimeout(() => loadList(type), 0);
            showToast('Filtreler temizlendi', 'info');
        });

        // Renk picker <-> hex senkronizasyonu
        $('#f_renk_picker').on('input', function () {
            $('#f_renk').val(this.value);
            $('#renk_onizleme_badge').css('background', this.value);
        });
        $('#f_renk').on('input', function () {
            const v = this.value.trim();
            if (/^#[0-9A-Fa-f]{6}$/.test(v)) {
                $('#f_renk_picker').val(v);
                $('#renk_onizleme_badge').css('background', v);
            }
        });

        $('#kayitForm').on('submit', function (e) {
            e.preventDefault();
            saveRecord();
        });
    });

    function dtConfig(order, noOrder) {
        return {
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: order,
            columnDefs: [{ orderable: false, targets: noOrder }],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        };
    }

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-durum').text(r.data.durum);
            $('#stat-surec').text(r.data.surec);
        }, 'json');
    }

    function loadList(type) {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'list', type: type, ...filters[type] },
            dataType: 'json',
            success: function (r) {
                if (r.success) (type === 'durum' ? renderDurum : renderSurec)(r.data);
                else showToast('Liste yüklenemedi: ' + (r.message || ''), 'error');
            },
            error: function () { showToast('Sunucu hatası', 'error'); }
        });
    }

    function renkBadge(renk, mesaj) {
        const r = escapeHtml(renk || '#6c757d');
        return `<span class="renk-badge" style="background:${r}">${escapeHtml(mesaj || '')}</span>`;
    }
    function renkHucre(renk) {
        const r = escapeHtml(renk || '-');
        return `<span class="renk-badge" style="background:${r}">&nbsp;&nbsp;&nbsp;</span> <span class="font-monospace">${r}</span>`;
    }
    function islemBtn(type, id) {
        let h = '';
        if (permissions.canEdit)
            h += `<button class="btn btn-sm btn-warning me-1" onclick="editRecord('${type}',${id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
        if (permissions.canDelete)
            h += `<button class="btn btn-sm btn-danger" onclick="deleteRecord('${type}',${id})" title="Sil"><i class="bi bi-trash"></i></button>`;
        return h || '<span class="text-muted">-</span>';
    }

    function renderDurum(rows) {
        const dt = tables.durum;
        dt.clear();
        rows.forEach(row => {
            dt.row.add([
                `<span class="kod-badge">${escapeHtml(String(row.kod))}</span>`,
                renkBadge(row.renk, row.mesaj),
                renkHucre(row.renk),
                escapeHtml(row.GuncellemeTarihi || '-'),
                escapeHtml(row.GuncelleyenAd || '-'),
                islemBtn('durum', row.id)
            ]);
        });
        dt.draw();
    }

    function renderSurec(rows) {
        const dt = tables.surec;
        dt.clear();
        rows.forEach(row => {
            dt.row.add([
                renkBadge(row.renk, row.mesaj),
                escapeHtml(row.aciklama || '-'),
                sonucSwitch(row),
                renkHucre(row.renk),
                escapeHtml(row.GuncellemeTarihi || '-'),
                escapeHtml(row.GuncelleyenAd || '-'),
                islemBtn('surec', row.id)
            ]);
        });
        dt.draw();
    }

    function sonucSwitch(row) {
        const acik = Number(row.sonuc) === 1 ? 'checked' : '';
        const kapali = permissions.canEdit ? '' : 'disabled';
        return `<div class="form-check form-switch d-flex justify-content-center">
                    <input class="form-check-input sonuc-toggle" type="checkbox" role="switch" data-id="${row.id}" ${acik} ${kapali}>
                    <label class="form-check-label"></label>
                </div>`;
    }

    $(document).on('change', '.sonuc-toggle', function () {
        const $cb = $(this);
        const deger = $cb.is(':checked') ? 1 : 0;
        $.post('', { action: 'sonuc_toggle', type: 'surec', id: $cb.data('id'), deger: deger }, function (r) {
            if (r.success) { showToast(r.message, 'success'); loadList('surec'); }
            else { $cb.prop('checked', !deger); showToast(r.message, 'error'); }
        }, 'json').fail(function () { $cb.prop('checked', !deger); showToast('Güncelleme başarısız', 'error'); });
    });

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function setTypeUI(type) {
        $('#rec_type').val(type);
        if (type === 'durum') {
            $('.only-durum').show();
            $('.only-surec').hide();
            $('#f_kod').prop('required', true);
            $('.col-mesaj').removeClass('col-md-12').addClass('col-md-8');
        } else {
            $('.only-durum').hide();
            $('.only-surec').show();
            $('#f_kod').prop('required', false);
            $('.col-mesaj').removeClass('col-md-8').addClass('col-md-12');
        }
    }

    function setRenk(v) {
        v = v || '#6c757d';
        $('#f_renk').val(v);
        $('#f_renk_picker').val(v);
        $('#renk_onizleme_badge').css('background', v);
    }

    function yeniKayit(type) {
        document.getElementById('kayitForm').reset();
        $('#rec_id').val('');
        setTypeUI(type);
        setRenk('#6c757d');
        $('#kayitModalLabel').text(type === 'durum' ? 'Yeni Başvuru Durumu' : 'Yeni Süreç Durumu');
        kayitModal.show();
    }

    function editRecord(type, id) {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'get', type: type, id: id },
            dataType: 'json',
            success: function (r) {
                if (!r.success || !r.data) { showToast('Kayıt bulunamadı', 'error'); return; }
                const d = r.data;
                document.getElementById('kayitForm').reset();
                setTypeUI(type);
                $('#rec_id').val(id);
                if (type === 'durum') {
                    $('#f_kod').val(d.BasvuruDurum_DurumKodu);
                    $('#f_mesaj').val(d.BasvuruDurum_Mesaj);
                    setRenk(d.BasvuruDurum_Renk);
                } else {
                    $('#f_mesaj').val(d.BasvuruSurecDurum_Mesaj);
                    $('#f_aciklama').val(d.BasvuruSurecDurum_Aciklama);
                    $('#f_sonuclandi').prop('checked', Number(d.BasvuruSurecDurum_Sonuclandi) === 1);
                    setRenk(d.BasvuruSurecDurum_Renk);
                }
                $('#kayitModalLabel').text(type === 'durum' ? 'Başvuru Durumu Düzenle' : 'Süreç Durumu Düzenle');
                kayitModal.show();
            },
            error: function () { showToast('Kayıt yüklenemedi', 'error'); }
        });
    }

    function saveRecord() {
        const formData = new FormData(document.getElementById('kayitForm'));
        formData.append('action', 'save');
        const type = $('#rec_type').val();

        $.ajax({
            url: '', method: 'POST',
            data: formData, processData: false, contentType: false, dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showToast(r.message, 'success');
                    kayitModal.hide();
                    loadList(type);
                    loadStats();
                } else {
                    showToast(r.message, 'error');
                }
            },
            error: function () { showToast('Kayıt sırasında hata oluştu', 'error'); }
        });
    }

    function deleteRecord(type, id) {
        confirmAction(
            'Bu kaydı silmek istediğinize emin misiniz?',
            'Bu işlem geri alınamaz!',
            function () {
                $.ajax({
                    url: '', method: 'POST',
                    data: { action: 'delete', type: type, id: id },
                    dataType: 'json',
                    success: function (r) {
                        if (r.success) {
                            showSuccess('Silindi!', r.message);
                            loadList(type);
                            loadStats();
                        } else {
                            showError('Hata!', r.message);
                        }
                    },
                    error: function () { showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'); }
                });
            }
        );
    }
</script>
</body>
</html>
