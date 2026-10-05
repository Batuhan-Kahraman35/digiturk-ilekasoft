<?php
/**
 * Admin Panel - API Lead Yönetimi (CallCenter)
 * CallCenter addLead endpoint kimliklerini yönetir.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/CallCenterHelper.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'API Lead Yönetimi';
$menuAdi   = $pageinfo['menu_adi'] ?? 'CallCenter';

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'list':
                $search   = $_POST['search'] ?? '';
                $status   = $_POST['status'] ?? '';
                $kampanya = $_POST['kampanya'] ?? '';
                $birim    = $_POST['birim']  ?? '';

                $where  = ["1=1"];
                $params = [];

                if ($search) {
                    $where[] = "(t.CallCenterApiLead_ApiAdi LIKE ? OR t.CallCenterApiLead_KampanyaAdi LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($status !== '') {
                    $where[] = "t.Durum = ?";
                    $params[] = $status;
                }
                if ($kampanya !== '') {
                    $where[] = "kk.CallCenterKampanyalar_Baslik = ?";
                    $params[] = $kampanya;
                }
                if ($birim !== '') {
                    $where[] = "EXISTS (
                        SELECT 1 FROM KullaniciBirimYetkileri kby
                        WHERE kby.KullaniciBirimYetkileri_ApiLead_id = t.CallCenterApiLead_id
                          AND kby.KullaniciBirimYetkileri_Birim_id = ?
                          AND kby.Durum = 1
                    )";
                    $params[] = $birim;
                }

                $whereClause = implode(" AND ", $where);

                $list = $db->fetchAll("
                    SELECT
                        t.CallCenterApiLead_id,
                        t.CallCenterApiLead_ApiAdi,
                        t.CallCenterApiLead_KampanyaAdi,
                        t.CallCenterApiLead_EndpointUrl,
                        t.CallCenterApiLead_CampaignId,
                        kk.CallCenterKampanyalar_Baslik as KampanyaBaslik,
                        t.CallCenterApiLead_ListeId,
                        t.CallCenterApiLead_Aciklama,
                        (SELECT STRING_AGG(b.KullaniciBirim_Adi, ', ')
                         FROM KullaniciBirimYetkileri kby
                         JOIN KullaniciBirim b ON kby.KullaniciBirimYetkileri_Birim_id = b.KullaniciBirim_id
                         WHERE kby.KullaniciBirimYetkileri_ApiLead_id = t.CallCenterApiLead_id
                           AND kby.Durum = 1) as Birimler,
                        t.Durum,
                        CONVERT(VARCHAR(19), t.GuncellemeTarihi, 120) as GuncellemeTarihi,
                        k.kullanici_ad + ' ' + k.kullanici_soyad as GuncelleyenAd
                    FROM CallCenterApiLead t
                    LEFT JOIN kullanicilar k ON t.GuncelleyenKullanici = k.kullanici_id
                    LEFT JOIN CallCenterKampanyalar kk ON kk.CallCenterKampanyalar_KaynakId = t.CallCenterApiLead_CampaignId
                    WHERE $whereClause
                    ORDER BY t.CallCenterApiLead_ApiAdi
                ", $params);

                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'kampanyalar':
                $rows = $db->fetchAll("
                    SELECT DISTINCT kk.CallCenterKampanyalar_Baslik AS ad
                    FROM CallCenterApiLead t
                    INNER JOIN CallCenterKampanyalar kk
                        ON kk.CallCenterKampanyalar_KaynakId = t.CallCenterApiLead_CampaignId
                    WHERE kk.CallCenterKampanyalar_Baslik IS NOT NULL AND kk.CallCenterKampanyalar_Baslik <> ''
                    ORDER BY kk.CallCenterKampanyalar_Baslik
                ");
                echo json_encode(['success' => true, 'data' => array_column($rows, 'ad')]);
                break;

            case 'get':
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("SELECT * FROM CallCenterApiLead WHERE CallCenterApiLead_id = ?", [$id]);
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

                $endpoint = trim($_POST['endpoint_url'] ?? '');
                if ($endpoint === '') {
                    $endpoint = 'https://callcenter.ornekyazilim.com/api/leadapi/addLead';
                }

                $data = [
                    'CallCenterApiLead_ApiAdi'      => trim($_POST['api_adi'] ?? ''),
                    'CallCenterApiLead_KampanyaAdi' => trim($_POST['kampanya_adi'] ?? ''),
                    'CallCenterApiLead_EndpointUrl' => $endpoint,
                    'CallCenterApiLead_Token'       => trim($_POST['token'] ?? ''),
                    'CallCenterApiLead_CampaignId'  => (int)($_POST['campaign_id'] ?? 0),
                    'CallCenterApiLead_ListeId'     => trim($_POST['liste_id'] ?? ''),
                    'CallCenterApiLead_Aciklama'    => trim($_POST['aciklama'] ?? ''),
                    'Durum'                         => isset($_POST['durum']) ? 1 : 0,
                ];

                if ($data['CallCenterApiLead_Token'] === '') {
                    echo json_encode(['success' => false, 'message' => 'Token zorunludur!']);
                    break;
                }
                if ($data['CallCenterApiLead_CampaignId'] <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir Campaign ID giriniz!']);
                    break;
                }

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->update('CallCenterApiLead', $data, ['CallCenterApiLead_id' => $id]);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['OlusturanKullanici']   = $user['kullanici_id'];
                    $data['OlusturmaTarihi']      = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->insert('CallCenterApiLead', $data);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt eklendi' : 'Ekleme hatası']);
                }
                break;

            case 'toggle_durum':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                $id    = (int)($_POST['id'] ?? 0);
                $durum = (int)($_POST['durum'] ?? 0);
                $result = $db->update('CallCenterApiLead', [
                    'Durum'                => $durum,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['CallCenterApiLead_id' => $id]);
                echo json_encode(['success' => (bool)$result]);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id = (int)($_POST['id'] ?? 0);
                $result = $db->delete('CallCenterApiLead', ['CallCenterApiLead_id' => $id]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt silindi' : 'Silme hatası']);
                break;

            case 'stats':
                $stats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as c FROM CallCenterApiLead")['c'] ?? 0,
                    'aktif'  => $db->fetchOne("SELECT COUNT(*) as c FROM CallCenterApiLead WHERE Durum = 1")['c'] ?? 0,
                    'pasif'  => $db->fetchOne("SELECT COUNT(*) as c FROM CallCenterApiLead WHERE Durum = 0")['c'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            case 'sync':
                if (!$permissions['can_add'] && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Güncelleme yetkiniz yok!']);
                    break;
                }

                $sonuc = CallCenterHelper::leadApiKayitlari(80);
                if (!$sonuc['success']) {
                    echo json_encode(['success' => false, 'message' => $sonuc['message']]);
                    break;
                }

                $eklenen = 0; $guncellenen = 0;
                $endpoint = 'https://callcenter.ornekyazilim.com/api/leadapi/addLead';

                foreach ($sonuc['records'] as $rec) {
                    $kaynakId = (int)$rec['id'];
                    $apiAdi   = $rec['api_name']    ?? '';
                    $token    = $rec['password']    ?? '';
                    $campaign = (int)($rec['campaign_id'] ?? 0);
                    $listeId  = $rec['list_id']     ?? '';
                    $aktif    = (isset($rec['status']) && (string)$rec['status'] === '1') ? 1 : 0;

                    $mevcut = $db->fetchOne(
                        "SELECT CallCenterApiLead_id FROM CallCenterApiLead WHERE CallCenterApiLead_KaynakId = ?",
                        [$kaynakId]
                    );

                    if ($mevcut) {
                        $db->update('CallCenterApiLead', [
                            'CallCenterApiLead_ApiAdi'      => $apiAdi,
                            'CallCenterApiLead_EndpointUrl' => $endpoint,
                            'CallCenterApiLead_Token'       => $token,
                            'CallCenterApiLead_CampaignId'  => $campaign,
                            'CallCenterApiLead_ListeId'     => $listeId,
                            'Durum'                         => $aktif,
                            'GuncelleyenKullanici'          => $user['kullanici_id'],
                            'GuncellemeTarihi'              => date('Y-m-d H:i:s'),
                        ], ['CallCenterApiLead_id' => $mevcut['CallCenterApiLead_id']]);
                        $guncellenen++;
                    } else {
                        $db->insert('CallCenterApiLead', [
                            'CallCenterApiLead_KaynakId'    => $kaynakId,
                            'CallCenterApiLead_ApiAdi'      => $apiAdi,
                            'CallCenterApiLead_KampanyaAdi' => $apiAdi,
                            'CallCenterApiLead_EndpointUrl' => $endpoint,
                            'CallCenterApiLead_Token'       => $token,
                            'CallCenterApiLead_CampaignId'  => $campaign,
                            'CallCenterApiLead_ListeId'     => $listeId,
                            'CallCenterApiLead_Aciklama'    => 'API senkronu ile eklendi',
                            'Durum'                         => $aktif,
                            'OlusturanKullanici'            => $user['kullanici_id'],
                            'OlusturmaTarihi'               => date('Y-m-d H:i:s'),
                            'GuncelleyenKullanici'          => $user['kullanici_id'],
                            'GuncellemeTarihi'              => date('Y-m-d H:i:s'),
                        ]);
                        $eklenen++;
                    }
                }

                echo json_encode([
                    'success' => true,
                    'message' => "Senkron tamamlandı: {$eklenen} yeni, {$guncellenen} güncellendi.",
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

// Filtre için birim listesi (sayfa render)
$birimler = $db->fetchAll("
    SELECT KullaniciBirim_id, KullaniciBirim_Adi
    FROM KullaniciBirim
    WHERE Durum = 1
    ORDER BY KullaniciBirim_Adi
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
        .status-badge  { padding: .25rem .5rem; border-radius: .25rem; font-size: .875rem; }
        .status-active   { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; }
        .token-cell { letter-spacing: 1px; }
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
                    <div class="col-md-4">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-hdd-network"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Kimlik</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif</span>
                                <span class="info-box-number" id="stat-aktif">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Pasif</span>
                                <span class="info-box-number" id="stat-pasif">0</span>
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
                                    <input type="text" class="form-control" name="search" id="filter_search" placeholder="API adı veya kampanya...">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Kampanya</label>
                                    <select class="form-select" name="kampanya" id="filter_kampanya">
                                        <option value="">Tümü</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Birim</label>
                                    <select class="form-select" name="birim" id="filter_birim">
                                        <option value="">Tümü</option>
                                        <?php foreach ($birimler as $b): ?>
                                        <option value="<?= (int)$b['KullaniciBirim_id'] ?>"><?= htmlspecialchars($b['KullaniciBirim_Adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Durum</label>
                                    <select class="form-select" name="status" id="filter_status">
                                        <option value="">Tümü</option>
                                        <option value="1">Aktif</option>
                                        <option value="0">Pasif</option>
                                    </select>
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
                    <div class="card-header">
                        <h3 class="card-title">API Lead Kimlikleri</h3>
                        <div class="card-tools">
                            <?php if ($permissions['can_add'] || $permissions['can_edit']): ?>
                            <button type="button" class="btn btn-success btn-sm" id="btnSync">
                                <i class="bi bi-cloud-download"></i> API'den Güncelle
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>API Adı</th>
                                    <th>Kampanya</th>
                                    <th>Campaign ID</th>
                                    <th>Kampanya Adı</th>
                                    <th>Liste ID</th>
                                    <th>Birim</th>
                                    <th>Durum</th>
                                    <th>Son Güncelleme</th>
                                    <th style="width:140px">İşlemler</th>
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

<!-- Kayıt Modal -->
<div class="modal fade" id="kayitModal" tabindex="-1" aria-labelledby="kayitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kayitModalLabel">Yeni Kayıt Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kayitForm">
                <div class="modal-body">
                    <input type="hidden" id="rec_id" name="id">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">API Adı</label>
                            <input type="text" class="form-control" id="api_adi" name="api_adi" placeholder="Ör: Digiturk.biz">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kampanya Adı</label>
                            <input type="text" class="form-control" id="kampanya_adi" name="kampanya_adi" placeholder="Ör: Digiturk Kampanyası">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Endpoint URL</label>
                            <input type="text" class="form-control mono" id="endpoint_url" name="endpoint_url"
                                   value="https://callcenter.ornekyazilim.com/api/leadapi/addLead"
                                   placeholder="https://callcenter.ornekyazilim.com/api/leadapi/addLead">
                            <small class="text-muted">Boş bırakılırsa varsayılan addLead adresi kullanılır.</small>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Token <span class="text-danger">*</span></label>
                            <input type="text" class="form-control mono" id="token" name="token" required placeholder="API şifresi / token">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Campaign ID <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="campaign_id" name="campaign_id" required min="1" placeholder="Ör: 3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Liste ID</label>
                            <input type="text" class="form-control" id="liste_id" name="liste_id" placeholder="Ör: 50832806">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" id="aciklama" name="aciklama" rows="2" placeholder="Serbest not..."></textarea>
                        </div>
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="durum" name="durum" checked>
                                <label class="form-check-label" for="durum">Aktif</label>
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

<!-- Dökümantasyon Modal -->
<div class="modal fade" id="docModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-code"></i> Lead Gönderim Dökümantasyonu <small class="text-muted ms-2" id="doc_apiadi"></small></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">

                <!-- 1. URL & Method -->
                <h6 class="fw-bold">1. İstek Türü ve URL</h6>
                <table class="table table-sm table-bordered mb-4">
                    <tr><th style="width:140px">Method</th><td><span class="badge text-bg-success">POST</span></td></tr>
                    <tr><th>URL</th><td><code id="doc_url" class="mono"></code></td></tr>
                </table>

                <!-- 2. Header -->
                <h6 class="fw-bold">2. Header Alanı</h6>
                <table class="table table-sm table-bordered mb-4">
                    <tr><th style="width:140px">Content-Type</th><td><code>application/json</code></td></tr>
                    <tr>
                        <th>token</th>
                        <td>
                            <code id="doc_token" class="mono"></code>
                            <button class="btn btn-sm btn-outline-secondary ms-2" onclick="copyDoc('doc_token')"><i class="bi bi-clipboard"></i></button>
                        </td>
                    </tr>
                </table>

                <!-- 3. Campaign ID -->
                <h6 class="fw-bold">3. Kampanya ID Alanı</h6>
                <p class="text-muted mb-2"><strong>Kampanya ID</strong> ve <strong>Telefon Numarası</strong> zorunlu alanlardır. İzin verilen alanları aşağıdaki tablodan kontrol edip kalan verileri JSON formatında gönderin.</p>
                <table class="table table-sm table-bordered mb-4">
                    <tr><th style="width:140px">campaign_id</th><td><code id="doc_campaign" class="mono"></code></td></tr>
                </table>

                <!-- Örnek istek -->
                <h6 class="fw-bold">Örnek İstek (JSON Body)</h6>
                <div class="position-relative mb-2">
                    <button class="btn btn-sm btn-outline-secondary position-absolute end-0 top-0 m-1" onclick="copyDoc('doc_json')"><i class="bi bi-clipboard"></i> Kopyala</button>
                    <pre class="bg-body-secondary p-3 rounded mono" id="doc_json" style="white-space:pre-wrap"></pre>
                </div>
                <h6 class="fw-bold">cURL</h6>
                <div class="position-relative mb-4">
                    <button class="btn btn-sm btn-outline-secondary position-absolute end-0 top-0 m-1" onclick="copyDoc('doc_curl')"><i class="bi bi-clipboard"></i> Kopyala</button>
                    <pre class="bg-body-secondary p-3 rounded mono" id="doc_curl" style="white-space:pre-wrap"></pre>
                </div>

                <!-- 4. Gönderim Alanları -->
                <h6 class="fw-bold">4. Gönderim Alanları</h6>
                <table class="table table-sm table-bordered table-striped">
                    <thead>
                        <tr><th>Gönderilen Alan</th><th>Başlık</th><th>İzin Durumu</th><th>Tip</th></tr>
                    </thead>
                    <tbody>
                        <tr><td class="mono">first_name</td><td>İsim</td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>TEXT</td></tr>
                        <tr><td class="mono">last_name</td><td>Soyisim</td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>TEXT</td></tr>
                        <tr><td class="mono">phone_number</td><td>Telefon <span class="badge text-bg-danger">Zorunlu</span></td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>TEXT <small class="text-danger">— 905550000000 şeklinde olmak zorunda!</small></td></tr>
                        <tr><td class="mono">alt_phone</td><td>Diğer Telefon</td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>NUMBER</td></tr>
                        <tr><td class="mono">address1</td><td>Adres</td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>TEXT</td></tr>
                        <tr><td class="mono">city</td><td>Şehir</td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>TEXT</td></tr>
                        <tr class="text-muted"><td class="mono">state</td><td>Bölge Kodu</td><td><span class="badge text-bg-secondary">İzin Verilmeyen</span></td><td>TEXT</td></tr>
                        <tr class="text-muted"><td class="mono">province</td><td>Yerleşke</td><td><span class="badge text-bg-secondary">İzin Verilmeyen</span></td><td>TEXT</td></tr>
                        <tr class="text-muted"><td class="mono">postal_code</td><td>Posta Kodu</td><td><span class="badge text-bg-secondary">İzin Verilmeyen</span></td><td>TEXT</td></tr>
                        <tr class="text-muted"><td class="mono">country_code</td><td>Ülke Kodu</td><td><span class="badge text-bg-secondary">İzin Verilmeyen</span></td><td>TEXT</td></tr>
                        <tr><td class="mono">gender</td><td>Cinsiyet</td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>SELECT</td></tr>
                        <tr><td class="mono">date_of_birth</td><td>Doğum Tarihi</td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>DATE</td></tr>
                        <tr><td class="mono">email</td><td>E-posta</td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>EMAIL</td></tr>
                        <tr><td class="mono">comments</td><td>Açıklama</td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>TEXTAREA</td></tr>
                        <tr><td class="mono">field_1</td><td>TC No</td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>NUMBER</td></tr>
                        <tr><td class="mono">field_2</td><td>Memo ID</td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>NUMBER</td></tr>
                        <tr><td class="mono">field_3</td><td>Başvuru Kaynağı</td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>TEXT</td></tr>
                        <tr><td class="mono">field_4</td><td>Gclid</td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>TEXT</td></tr>
                        <tr><td class="mono">field_5</td><td>Müşteri No</td><td><span class="badge text-bg-success">İzin Verilen</span></td><td>NUMBER</td></tr>
                    </tbody>
                </table>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
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
    const permissions = {
        canAdd:    <?= $permissions['can_add']    ? 'true' : 'false' ?>,
        canEdit:   <?= $permissions['can_edit']   ? 'true' : 'false' ?>,
        canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
    };

    let kayitModal, docModal, dataTable, currentFilters = {};

    $(document).ready(function () {
        kayitModal = new bootstrap.Modal(document.getElementById('kayitModal'));
        docModal   = new bootstrap.Modal(document.getElementById('docModal'));

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc']],
            columnDefs: [{ orderable: false, targets: [8] }],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        });

        loadStats();
        loadKampanyalar();
        loadList();

        // Filtre select'ini custom.js (.form-select) otomatik Select2 yapıyor — elle init etmiyoruz.

        $('#kayitForm').on('submit', function (e) {
            e.preventDefault();
            saveRecord();
        });

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            currentFilters = {
                search:   $('#filter_search').val(),
                kampanya: $('#filter_kampanya').val(),
                birim:    $('#filter_birim').val(),
                status:   $('#filter_status').val()
            };
            Object.keys(currentFilters).forEach(k => { if (!currentFilters[k] && currentFilters[k] !== '0') delete currentFilters[k]; });
            loadList();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filterForm')[0].reset();
            $('#filter_kampanya, #filter_birim, #filter_status').val('').trigger('change');
            currentFilters = {};
            loadList();
            showToast('Filtreler temizlendi', 'info');
        });

        document.getElementById('kayitModal').addEventListener('hidden.bs.modal', resetForm);

        $('#btnSync').on('click', syncFromApi);
    });

    function syncFromApi() {
        confirmAction(
            'API\'den güncellensin mi?',
            'CallCenter Lead API kimlikleri çekilip tabloya işlenecek.',
            function () {
                const $btn = $('#btnSync');
                const eski = $btn.html();
                $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Güncelleniyor...');
                $.ajax({
                    url: '', method: 'POST',
                    data: { action: 'sync' },
                    dataType: 'json',
                    success: function (r) {
                        if (r.success) {
                            showSuccess('Tamamlandı!', r.message);
                            loadKampanyalar();
                            loadList();
                            loadStats();
                        } else {
                            showError('Hata!', r.message);
                        }
                    },
                    error: function () { showError('Bağlantı Hatası!', 'Senkron sırasında sunucuya ulaşılamadı.'); },
                    complete: function () { $btn.prop('disabled', false).html(eski); }
                });
            }
        );
    }

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-aktif').text(r.data.aktif);
            $('#stat-pasif').text(r.data.pasif);
        });
    }

    function loadKampanyalar() {
        $.post('', { action: 'kampanyalar' }, function (r) {
            if (!r.success) return;
            const $sel = $('#filter_kampanya');
            const mevcut = $sel.val();
            $sel.find('option:not(:first)').remove();
            r.data.forEach(ad => $sel.append(`<option value="${escapeHtml(ad)}">${escapeHtml(ad)}</option>`));
            $sel.val(mevcut).trigger('change');
        }, 'json');
    }

    function loadList() {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'list', ...currentFilters },
            dataType: 'json',
            success: function (r) {
                if (r.success) renderTable(r.data);
                else showToast('Liste yüklenirken hata oluştu', 'error');
            },
            error: function () { showToast('Sunucu hatası oluştu', 'error'); }
        });
    }

    function renderTable(rows) {
        dataTable.clear();
        rows.forEach(row => {
            const apiAdi      = escapeHtml(row.CallCenterApiLead_ApiAdi || '-');
            const kampanya    = escapeHtml(row.CallCenterApiLead_KampanyaAdi || '-');
            const campId      = escapeHtml(row.CallCenterApiLead_CampaignId || '-');
            const kampanyaAdi = escapeHtml(row.KampanyaBaslik || '-');
            const listeId     = escapeHtml(row.CallCenterApiLead_ListeId || '-');
            const birimler    = row.Birimler
                ? escapeHtml(row.Birimler)
                : '<span class="text-muted">-</span>';

            const durum = row.Durum == 1
                ? `<span class="status-badge status-active" style="cursor:pointer" onclick="toggleDurum(${row.CallCenterApiLead_id}, 0)" title="Pasife al">Aktif</span>`
                : `<span class="status-badge status-inactive" style="cursor:pointer" onclick="toggleDurum(${row.CallCenterApiLead_id}, 1)" title="Aktife al">Pasif</span>`;

            let islemler = `<button class="btn btn-sm btn-info me-1" onclick="showDoc(${row.CallCenterApiLead_id})" title="Dökümantasyon"><i class="bi bi-file-earmark-code"></i></button>`;
            if (permissions.canEdit)
                islemler += `<button class="btn btn-sm btn-warning me-1" onclick="editRecord(${row.CallCenterApiLead_id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
            if (permissions.canDelete)
                islemler += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${row.CallCenterApiLead_id})" title="Sil"><i class="bi bi-trash"></i></button>`;

            dataTable.row.add([
                apiAdi,
                kampanya,
                `<span class="mono">${campId}</span>`,
                kampanyaAdi,
                `<span class="mono">${listeId}</span>`,
                birimler,
                durum,
                escapeHtml(row.GuncellemeTarihi || '-'),
                islemler
            ]);
        });
        dataTable.draw();
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function resetForm() {
        document.getElementById('kayitForm').reset();
        document.getElementById('rec_id').value = '';
        document.getElementById('kayitModalLabel').textContent = 'Yeni Kayıt Ekle';
        document.getElementById('endpoint_url').value = 'https://callcenter.ornekyazilim.com/api/leadapi/addLead';
        document.getElementById('durum').checked = true;
    }

    function saveRecord() {
        const formData = new FormData(document.getElementById('kayitForm'));
        formData.append('action', 'save');

        $.ajax({
            url: '', method: 'POST',
            data: formData, processData: false, contentType: false, dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showToast(r.message, 'success');
                    kayitModal.hide();
                    loadList();
                    loadStats();
                } else {
                    showToast(r.message, 'error');
                }
            },
            error: function () { showToast('Kayıt sırasında hata oluştu', 'error'); }
        });
    }

    function showDoc(id) {
        $.post('', { action: 'get', id: id }, function (r) {
            if (!r.success || !r.data) { showToast('Kayıt bulunamadı', 'error'); return; }
            const d = r.data;
            const url   = d.CallCenterApiLead_EndpointUrl || 'https://callcenter.ornekyazilim.com/api/leadapi/addLead';
            const token = d.CallCenterApiLead_Token || '';
            const camp  = d.CallCenterApiLead_CampaignId || '';

            $('#doc_apiadi').text(d.CallCenterApiLead_ApiAdi ? '— ' + d.CallCenterApiLead_ApiAdi : '');
            $('#doc_url').text(url);
            $('#doc_token').text(token);
            $('#doc_campaign').text(camp);

            const body = {
                campaign_id: (Number(camp) || camp),
                first_name: 'Ahmet',
                last_name: 'Köse',
                phone_number: '905550000000',
                city: 'Yalova',
                email: 'ornek@mail.com',
                comments: 'Örnek lead',
                field_3: 'Web Form'
            };
            $('#doc_json').text(JSON.stringify(body, null, 2));
            $('#doc_curl').text(
                `curl -X POST "${url}" \\\n` +
                `  -H "Content-Type: application/json" \\\n` +
                `  -H "token: ${token}" \\\n` +
                `  -d '${JSON.stringify(body)}'`
            );

            docModal.show();
        }, 'json');
    }

    function copyDoc(elId) {
        const t = document.getElementById(elId).innerText;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(t).then(() => showToast('Kopyalandı', 'success'));
        } else {
            const ta = document.createElement('textarea');
            ta.value = t; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); showToast('Kopyalandı', 'success'); } catch (e) { showToast('Kopyalanamadı', 'error'); }
            document.body.removeChild(ta);
        }
    }

    function editRecord(id) {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'get', id: id },
            dataType: 'json',
            success: function (r) {
                if (!r.success || !r.data) { showToast('Kayıt bulunamadı', 'error'); return; }
                const d = r.data;
                document.getElementById('rec_id').value       = d.CallCenterApiLead_id;
                document.getElementById('api_adi').value      = d.CallCenterApiLead_ApiAdi || '';
                document.getElementById('kampanya_adi').value = d.CallCenterApiLead_KampanyaAdi || '';
                document.getElementById('endpoint_url').value = d.CallCenterApiLead_EndpointUrl || '';
                document.getElementById('token').value        = d.CallCenterApiLead_Token || '';
                document.getElementById('campaign_id').value  = d.CallCenterApiLead_CampaignId || '';
                document.getElementById('liste_id').value     = d.CallCenterApiLead_ListeId || '';
                document.getElementById('aciklama').value     = d.CallCenterApiLead_Aciklama || '';
                document.getElementById('durum').checked      = d.Durum == 1;
                document.getElementById('kayitModalLabel').textContent = 'Kayıt Düzenle';
                kayitModal.show();
            },
            error: function () { showToast('Kayıt yüklenirken hata oluştu', 'error'); }
        });
    }

    function toggleDurum(id, yeniDurum) {
        if (!permissions.canEdit) return;
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'toggle_durum', id: id, durum: yeniDurum },
            dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showToast(yeniDurum == 1 ? 'Aktif edildi' : 'Pasif edildi', 'success');
                    loadList();
                    loadStats();
                } else {
                    showToast('Durum değiştirilemedi', 'error');
                }
            }
        });
    }

    function deleteRecord(id) {
        confirmAction(
            'Bu kaydı silmek istediğinize emin misiniz?',
            'Bu işlem geri alınamaz!',
            function () {
                $.ajax({
                    url: '', method: 'POST',
                    data: { action: 'delete', id: id },
                    dataType: 'json',
                    success: function (r) {
                        if (r.success) {
                            showSuccess('Silindi!', r.message);
                            loadList();
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
