<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';

requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

$currentPagefile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$pagePermissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $pagePermissions['error'] ?? 'Erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$pageinfo  = $db->fetchOne("SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi FROM Menu_Sayfalar s LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1", ['%' . $currentPagefile]);
$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Cron Yönetimi';
$pageDesc  = $pageinfo['sayfalar_aciklama']  ?? '';
$menuAdi   = $pageinfo['menu_adi']           ?? null;
$siteTitle = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC")['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

require_once __DIR__ . '/../../cron/anahtar.php';
define('CRON_KEY',    htmlspecialchars(cronAnahtari($db)));
define('RUNNER_URL',  (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/cron/runner.php');
define('WORKER_URL',  (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/cron/worker.php');

// ─── AJAX ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = $_POST['action'] ?? '';

        // ── Stats ──────────────────────────────────────────────────────────
        if ($action === 'stats') {
            $today = date('Y-m-d');
            echo json_encode(['success' => true, 'data' => [
                'toplam_gorev'      => $db->fetchOne("SELECT COUNT(*) AS c FROM CronGorevler WHERE Durum=1")['c'] ?? 0,
                'aktif_zamanlama'   => $db->fetchOne("SELECT COUNT(*) AS c FROM CronZamanlamalar WHERE Durum=1")['c'] ?? 0,
                'bugun_basarili'    => $db->fetchOne("SELECT COUNT(*) AS c FROM CronCalismaLog WHERE CronCalismaLog_CalismaDurum=1 AND CAST(CronCalismaLog_BaslangicTarihi AS DATE)=?", [$today])['c'] ?? 0,
                'bugun_hatali'      => $db->fetchOne("SELECT COUNT(*) AS c FROM CronCalismaLog WHERE CronCalismaLog_CalismaDurum=2 AND CAST(CronCalismaLog_BaslangicTarihi AS DATE)=?", [$today])['c'] ?? 0,
            ]]);
            exit;
        }

        // ── Görev + Zamanlayıcı listesi ────────────────────────────────────
        if ($action === 'gorev_listele') {
            $gorevler = $db->fetchAll("SELECT CronGorevler_Id, CronGorevler_Ad, CronGorevler_Aciklama, CronGorevler_GorevKodu, CronGorevler_Parametreler, Durum FROM CronGorevler ORDER BY CronGorevler_Id");
            foreach ($gorevler as &$g) {
                $g['Zamanlamalar'] = $db->fetchAll("
                    SELECT z.CronZamanlamalar_Id, z.CronZamanlamalar_Ad, z.CronZamanlamalar_CronIfadesi,
                           CONVERT(VARCHAR(16), z.CronZamanlamalar_BaslangicTarihi, 120) AS Baslangic,
                           CONVERT(VARCHAR(16), z.CronZamanlamalar_BitisTarihi,    120) AS Bitis,
                           z.CronZamanlamalar_SabitParametreler,
                           z.CronZamanlamalar_TelafiDakika,
                           CONVERT(VARCHAR(19), z.CronZamanlamalar_SonCalisma,     120) AS SonCalisma,
                           z.Durum,
                           l.CronCalismaLog_CalismaDurum AS SonDurum,
                           l.CronCalismaLog_SureSaniye   AS SonSure,
                           l.CronCalismaLog_Sonuc        AS SonSonuc
                    FROM CronZamanlamalar z
                    OUTER APPLY (
                        SELECT TOP 1 CronCalismaLog_CalismaDurum, CronCalismaLog_SureSaniye, CronCalismaLog_Sonuc
                        FROM CronCalismaLog WHERE CronCalismaLog_ZamanlamaId = z.CronZamanlamalar_Id
                        ORDER BY CronCalismaLog_Id DESC
                    ) l
                    WHERE z.CronZamanlamalar_GorevId = ?
                    ORDER BY z.CronZamanlamalar_Id
                ", [$g['CronGorevler_Id']]);
            }
            echo json_encode(['success' => true, 'data' => $gorevler]);
            exit;
        }

        // ── Zamanlayıcı ekle ───────────────────────────────────────────────
        if ($action === 'zamanlama_ekle') {
            if (!$pagePermissions['can_add']) throw new Exception('Ekleme yetkiniz yok.');
            $gorevId = intval($_POST['gorev_id'] ?? 0);
            $ad      = trim($_POST['ad'] ?? '');
            $cron    = trim($_POST['cron_ifadesi'] ?? '');
            $bas     = trim($_POST['baslangic'] ?? '') ?: date('Y-m-d H:i:s');
            $bitis   = trim($_POST['bitis'] ?? '') ?: null;
            $sparams = trim($_POST['sabit_params'] ?? '') ?: null;
            $telafi  = max(0, intval($_POST['telafi_dakika'] ?? 0)) ?: null;
            if (!$gorevId || !$ad || !$cron) throw new Exception('Görev, ad ve cron ifadesi zorunludur.');
            $simdi = date('Y-m-d H:i:s');
            $db->insert('CronZamanlamalar', [
                'CronZamanlamalar_GorevId'          => $gorevId,
                'CronZamanlamalar_Ad'               => $ad,
                'CronZamanlamalar_CronIfadesi'      => $cron,
                'CronZamanlamalar_BaslangicTarihi'  => $bas,
                'CronZamanlamalar_BitisTarihi'      => $bitis,
                'CronZamanlamalar_SabitParametreler'=> $sparams,
                'CronZamanlamalar_TelafiDakika'     => $telafi,
                'OlusturanKullanici'                => $user['kullanici_id'],
                'OlusturmaTarihi'                   => $simdi,
                'GuncelleyenKullanici'              => $user['kullanici_id'],
                'GuncellemeTarihi'                  => $simdi,
                'Durum'                             => 1,
            ]);
            echo json_encode(['success' => true, 'message' => 'Zamanlayıcı eklendi.']);
            exit;
        }

        // ── Zamanlayıcı güncelle ───────────────────────────────────────────
        if ($action === 'zamanlama_guncelle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');
            $id      = intval($_POST['zamanlama_id'] ?? 0);
            $ad      = trim($_POST['ad'] ?? '');
            $cron    = trim($_POST['cron_ifadesi'] ?? '');
            $bas     = trim($_POST['baslangic'] ?? '') ?: date('Y-m-d H:i:s');
            $bitis   = trim($_POST['bitis'] ?? '') ?: null;
            $sparams = trim($_POST['sabit_params'] ?? '') ?: null;
            $telafi  = max(0, intval($_POST['telafi_dakika'] ?? 0)) ?: null;
            if (!$id || !$ad || !$cron) throw new Exception('Id, ad ve cron ifadesi zorunludur.');
            $db->update('CronZamanlamalar', [
                'CronZamanlamalar_Ad'               => $ad,
                'CronZamanlamalar_CronIfadesi'      => $cron,
                'CronZamanlamalar_BaslangicTarihi'  => $bas,
                'CronZamanlamalar_BitisTarihi'      => $bitis,
                'CronZamanlamalar_SabitParametreler'=> $sparams,
                'CronZamanlamalar_TelafiDakika'     => $telafi,
                'GuncelleyenKullanici'              => $user['kullanici_id'],
                'GuncellemeTarihi'                  => date('Y-m-d H:i:s'),
            ], ['CronZamanlamalar_Id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Zamanlayıcı güncellendi.']);
            exit;
        }

        // ── Zamanlayıcı sil ────────────────────────────────────────────────
        if ($action === 'zamanlama_sil') {
            if (!$pagePermissions['can_delete']) throw new Exception('Silme yetkiniz yok.');
            $id = intval($_POST['zamanlama_id'] ?? 0);
            if (!$id) throw new Exception('Geçersiz id.');
            $db->update('CronZamanlamalar', ['Durum' => 0, 'GuncelleyenKullanici' => $user['kullanici_id'], 'GuncellemeTarihi' => date('Y-m-d H:i:s')], ['CronZamanlamalar_Id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Zamanlayıcı silindi.']);
            exit;
        }

        // ── Zamanlayıcı durum toggle ───────────────────────────────────────
        if ($action === 'zamanlama_durum') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');
            $id    = intval($_POST['zamanlama_id'] ?? 0);
            $durum = intval($_POST['durum'] ?? 0);
            if (!$id) throw new Exception('Geçersiz id.');
            $db->update('CronZamanlamalar', ['Durum' => $durum, 'GuncelleyenKullanici' => $user['kullanici_id'], 'GuncellemeTarihi' => date('Y-m-d H:i:s')], ['CronZamanlamalar_Id' => $id]);
            echo json_encode(['success' => true]);
            exit;
        }

        // ── Manuel tetikle ─────────────────────────────────────────────────
        if ($action === 'tetikle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok.');
            require_once __DIR__ . '/../../cron/tasks.php';

            $zamanlamaId = intval($_POST['zamanlama_id'] ?? 0);
            $gorevId     = 0;

            if ($zamanlamaId > 0) {
                $zam = $db->fetchOne("SELECT z.*, g.CronGorevler_GorevKodu, g.CronGorevler_Id AS GorevId, g.CronGorevler_Parametreler FROM CronZamanlamalar z INNER JOIN CronGorevler g ON z.CronZamanlamalar_GorevId = g.CronGorevler_Id WHERE z.CronZamanlamalar_Id = ? AND z.Durum = 1", [$zamanlamaId]);
                if (!$zam) throw new Exception('Zamanlayıcı bulunamadı.');
                $gorevId   = (int)$zam['GorevId'];
                $gorevKodu = $zam['CronGorevler_GorevKodu'];
                $params    = json_decode($zam['CronZamanlamalar_SabitParametreler'] ?? '{}', true) ?? [];
                $params    = dinamikParamCoz($params);
                $db->update('CronZamanlamalar', ['CronZamanlamalar_SonCalisma' => date('Y-m-d H:i:s')], ['CronZamanlamalar_Id' => $zamanlamaId]);
            } else {
                $gorevId = intval($_POST['gorev_id'] ?? 0);
                if (!$gorevId) throw new Exception('Görev seçilmedi.');
                $gorev = $db->fetchOne("SELECT * FROM CronGorevler WHERE CronGorevler_Id = ? AND Durum = 1", [$gorevId]);
                if (!$gorev) throw new Exception('Görev bulunamadı.');
                $gorevKodu   = $gorev['CronGorevler_GorevKodu'];
                $zamanlamaId = null;
                $schema      = json_decode($gorev['CronGorevler_Parametreler'] ?? '[]', true) ?: [];
                $params      = [];
                foreach ($schema as $p) {
                    $val = trim($_POST[$p['ad']] ?? '');
                    if (($p['zorunlu'] ?? false) && $val === '') throw new Exception("'{$p['etiket']}' parametresi zorunludur.");
                    if ($val !== '') $params[$p['ad']] = $val;
                }
            }

            $logId    = cronLogOlustur($db, $gorevId, $zamanlamaId, $params, 0, $user['kullanici_id']);
            $basZaman = microtime(true);

            $sonuc = gorevCalistir($gorevKodu, $params, $db);
            cronLogBitir($db, $logId, $sonuc['durum'], $sonuc['sonuc'], $basZaman);

            echo json_encode(['success' => true, 'message' => $sonuc['sonuc'], 'durum' => $sonuc['durum'], 'cikti' => $sonuc['cikti']]);
            exit;
        }

        // ── Log listesi ────────────────────────────────────────────────────
        if ($action === 'log_listele') {
            $gorevId     = intval($_POST['gorev_id']     ?? 0);
            $zamanlamaId = intval($_POST['zamanlama_id'] ?? 0);
            $where = ['l.Durum = 1']; $p = [];
            if ($gorevId > 0)     { $where[] = 'l.CronCalismaLog_GorevId = ?';     $p[] = $gorevId; }
            if ($zamanlamaId > 0) { $where[] = 'l.CronCalismaLog_ZamanlamaId = ?'; $p[] = $zamanlamaId; }
            $rows = $db->fetchAll("
                SELECT TOP 200
                    l.CronCalismaLog_Id,
                    g.CronGorevler_Ad,
                    g.CronGorevler_GorevKodu,
                    z.CronZamanlamalar_Ad AS ZamanlamaAd,
                    CONVERT(VARCHAR(19), l.CronCalismaLog_BaslangicTarihi, 120) AS BaslangicTarihi,
                    CONVERT(VARCHAR(19), l.CronCalismaLog_BitisTarihi,    120) AS BitisTarihi,
                    l.CronCalismaLog_SureSaniye,
                    l.CronCalismaLog_Parametreler,
                    l.CronCalismaLog_CalismaDurum,
                    l.CronCalismaLog_Sonuc,
                    l.CronCalismaLog_TetikleyenTur
                FROM CronCalismaLog l
                INNER JOIN CronGorevler g ON l.CronCalismaLog_GorevId = g.CronGorevler_Id
                LEFT  JOIN CronZamanlamalar z ON l.CronCalismaLog_ZamanlamaId = z.CronZamanlamalar_Id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY l.CronCalismaLog_Id DESC
            ", $p);
            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        throw new Exception('Geçersiz işlem.');

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>
        .cron-code  { font-family: monospace; font-size: .8rem; background:#f4f4f4; padding:2px 6px; border-radius:4px; }
        .cikti-log  { font-family: monospace; font-size: .8rem; white-space: pre-wrap; max-height:350px; overflow-y:auto; background:#1e1e1e; color:#d4d4d4; padding:12px; border-radius:6px; }
        .z-table th { white-space: nowrap; font-size:.82rem; }
        .z-table td { font-size:.82rem; vertical-align:middle; }
        .runner-url { font-size:.78rem; word-break:break-all; }
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

                <!-- Plesk Bilgi Kartı -->
                <div class="alert alert-light border mb-3 py-2">
                    <div class="row align-items-center">
                        <div class="col-auto"><i class="bi bi-gear-fill text-primary fs-5"></i></div>
                        <div class="col">
                            <strong>Plesk Cron (Tek Satır):</strong>
                            <span class="cron-code ms-2">* * * * *</span>
                            <span class="runner-url ms-3 text-muted"><?= RUNNER_URL ?>?key=<?= CRON_KEY ?></span>
                        </div>
                        <div class="col-auto">
                            <button class="btn btn-sm btn-outline-secondary" onclick="kopyala('runnerUrl')" title="URL Kopyala"><i class="bi bi-clipboard"></i></button>
                            <input type="text" id="runnerUrl" class="visually-hidden" value="<?= RUNNER_URL ?>?key=<?= CRON_KEY ?>">
                        </div>
                    </div>
                </div>

                <?php include __DIR__ . '/../includes/api-personel-bilgisi.php'; ?>

                <!-- InfoBoxes -->
                <div class="row mb-3">
                    <div class="col-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-list-task"></i></span>
                            <div class="info-box-content"><span class="info-box-text">Tanımlı Görev</span><span class="info-box-number" id="stat-gorev">—</span></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-clock"></i></span>
                            <div class="info-box-content"><span class="info-box-text">Aktif Zamanlayıcı</span><span class="info-box-number" id="stat-zamanlama">—</span></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content"><span class="info-box-text">Bugün Başarılı</span><span class="info-box-number" id="stat-basarili">—</span></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-danger shadow-sm"><i class="bi bi-x-circle"></i></span>
                            <div class="info-box-content"><span class="info-box-text">Bugün Hatalı</span><span class="info-box-number" id="stat-hatali">—</span></div>
                        </div>
                    </div>
                </div>

                <!-- Sekmeler -->
                <div class="card card-primary card-outline">
                    <div class="card-header p-0">
                        <ul class="nav nav-tabs card-header-tabs align-items-center" role="tablist">
                            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#pane-gorevler" role="tab"><i class="bi bi-list-task me-1"></i> Görevler</a></li>
                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#pane-log" role="tab" id="tab-log"><i class="bi bi-journal-text me-1"></i> Çalışma Geçmişi</a></li>
                            <li class="nav-item ms-auto pe-2">
                                <a href="/Admin/pages/hatirlatma-yonetimi.php" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-bell me-1"></i> Hatırlatma Yönetimi
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body tab-content p-0">

                        <!-- Görevler Sekmesi -->
                        <div class="tab-pane fade show active p-3" id="pane-gorevler">
                            <div id="gorevListesi"></div>
                        </div>

                        <!-- Log Sekmesi -->
                        <div class="tab-pane fade" id="pane-log">
                            <div class="p-3 border-bottom">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-4">
                                        <label class="form-label mb-1">Görev</label>
                                        <select class="form-select form-select-sm" id="log_gorev_filtre"><option value="0">Tüm Görevler</option></select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label mb-1">Zamanlayıcı</label>
                                        <select class="form-select form-select-sm" id="log_zamanlama_filtre"><option value="0">Tüm Zamanlayıcılar</option></select>
                                    </div>
                                    <div class="col-auto">
                                        <button class="btn btn-sm btn-primary" onclick="logListele()"><i class="bi bi-search"></i> Filtrele</button>
                                    </div>
                                </div>
                            </div>
                            <div class="p-3">
                                <table id="logTable" class="table table-bordered table-striped table-hover table-sm">
                                    <thead><tr><th>#</th><th>Görev</th><th>Zamanlayıcı</th><th>Tetikleyen</th><th>Başlangıç</th><th>Bitiş</th><th>Süre</th><th>Durum</th><th>Sonuç</th></tr></thead>
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

<!-- ── Zamanlayıcı Modal ─────────────────────────────────────────────────── -->
<div class="modal fade" id="zamanlamaModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clock-history me-1"></i> <span id="zamanlamaBaslik">Zamanlayıcı</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="z_id">
                <input type="hidden" id="z_gorev_id">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Zamanlayıcı Adı <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="z_ad" placeholder="Örn: IRIS - Her Gün Dün">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Sıklık Şablonu</label>
                        <select class="form-select" id="z_sablon" onchange="cronSablonSec()">
                            <option value="">— Şablon Seç —</option>
                            <option value="* * * * *">Her dakika</option>
                            <option value="*/5 * * * *">Her 5 dakika</option>
                            <option value="*/15 * * * *">Her 15 dakika</option>
                            <option value="*/30 * * * *">Her 30 dakika</option>
                            <option value="0 * * * *">Her saat başı</option>
                            <option value="0 */3 * * *">Her 3 saatte bir</option>
                            <option value="0 */6 * * *">Her 6 saatte bir</option>
                            <option value="0 */12 * * *">Her 12 saatte bir</option>
                            <option value="0 6 * * *">Her gün 06:00</option>
                            <option value="0 7 * * *">Her gün 07:00</option>
                            <option value="0 8 * * *">Her gün 08:00</option>
                            <option value="0 23 * * *">Her gece 23:00</option>
                            <option value="0 8 * * 1">Her Pazartesi 08:00</option>
                            <option value="0 8 1 * *">Her ayın 1'i 08:00</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Cron İfadesi <span class="text-danger">*</span></label>
                        <input type="text" class="form-control cron-code" id="z_cron" placeholder="0 7 * * *">
                        <div class="form-text">Dakika Saat GünAyı AySayısı HaftaGünü (0=Paz)</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Başlangıç Tarihi <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" id="z_baslangic">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Bitiş Tarihi <span class="text-muted">(boş = sürekli)</span></label>
                        <input type="datetime-local" class="form-control" id="z_bitis">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Kaçan Turu Telafi Et</label>
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" role="switch" id="z_telafi" value="1"
                                   onchange="document.getElementById('z_telafi_dakika').disabled = !this.checked">
                            <label class="form-check-label" for="z_telafi">Zamanı kaçırılırsa sonraki dakikada çalıştır</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Telafi Süresi (dakika)</label>
                        <input type="number" class="form-control" id="z_telafi_dakika" min="1" max="720" value="30" disabled>
                        <div class="form-text">Kaçan tur bu süreden eskiyse telafi edilmez. En fazla bir tur telafi edilir.</div>
                    </div>
                    <div class="col-12" id="z_params_container" style="display:none">
                        <hr class="my-1">
                        <label class="form-label fw-semibold">Sabit Parametreler</label>
                        <div id="z_params_alan"></div>
                        <div class="alert alert-light border small mt-2 mb-0">
                            <strong>Dinamik Tarih Değerleri:</strong>
                            <code>{bugun}</code> Bugün &nbsp;
                            <code>{dun}</code> Dün &nbsp;
                            <code>{7gun_once}</code> 7 gün önce &nbsp;
                            <code>{30gun_once}</code> 30 gün önce &nbsp;
                            <code>{ay_basi}</code> Ayın 1'i &nbsp;
                            <code>{gecen_ay_basi}</code> Geçen ay başı &nbsp;
                            <code>{gecen_ay_sonu}</code> Geçen ay sonu &nbsp;
                            <code>{3ay_once}</code> 3 ay önce &nbsp;
                            <code>{6ay_once}</code> 6 ay önce
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" onclick="zamanlamaKaydet()"><i class="bi bi-save"></i> Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Manuel Tetikle Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="tetikleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-play-circle me-1"></i> <span id="tetikleBaslik"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="tetikleGorevId">
                <input type="hidden" id="tetikleZamanlamaId">
                <div id="tetikleParamAlan"></div>
                <div class="alert alert-info py-2 small mb-0">
                    <i class="bi bi-info-circle"></i> Görev tamamlanana kadar sayfa bekler.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-success" onclick="tetikleBaslat()"><i class="bi bi-play-fill"></i> Çalıştır</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Çıktı Modal ───────────────────────────────────────────────────────── -->
<div class="modal fade" id="ciktiModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-terminal me-1"></i> Görev Çıktısı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="ciktiDurum" class="mb-2"></div>
                <pre id="ciktiMetin" class="cikti-log"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
const PAGE_URL = '/Admin/pages/cron-yonetimi.php';
// Aktif birimler — parametre şemasında tip:"birim" olan alanlar için (dropdown)
const BIRIMLER = <?= json_encode($db->fetchAll("SELECT KullaniciBirim_id, KullaniciBirim_Adi FROM KullaniciBirim WHERE Durum = 1 ORDER BY KullaniciBirim_Adi"), JSON_UNESCAPED_UNICODE) ?>;
// Aktif alt bayi personeli — parametre şemasında tip:"personel" olan alanlar için (dropdown)
const PERSONELLER = <?= json_encode($db->fetchAll("
    SELECT p.DigiturkAltBayiPersonel_Id AS id,
           p.DigiturkAltBayiPersonel_AdSoyad AS ad,
           ISNULL(n.DigiturkAnaBayiler_BayiKodu, '') AS bayi_kodu
    FROM DigiturkAltBayiPersonel p
    LEFT JOIN DigiturkAltBayiler a ON p.DigiturkAltBayiPersonel_AltBayiId = a.DigiturkAltBayiler_Id
    LEFT JOIN DigiturkAnaBayiler n ON a.DigiturkAltBayiler_AnaBayiId      = n.DigiturkAnaBayiler_Id
    WHERE p.Durum = 1
    ORDER BY p.DigiturkAltBayiPersonel_AdSoyad"), JSON_UNESCAPED_UNICODE) ?>;
// Ana bayiler — parametre şemasında tip:"anabayi" olan alanlar için (dropdown).
// Bırakılan bayinin zamanlayıcısı düzenlenebilsin diye pasifler de listelenir.
const ANA_BAYILER = <?= json_encode($db->fetchAll("
    SELECT DigiturkAnaBayiler_Id AS id, DigiturkAnaBayiler_Ad AS ad,
           ISNULL(DigiturkAnaBayiler_BayiKodu, '') AS bayi_kodu, Durum AS durum
    FROM DigiturkAnaBayiler
    ORDER BY Durum DESC, DigiturkAnaBayiler_Ad"), JSON_UNESCAPED_UNICODE) ?>;
const SECIMLI_TIPLER = ['birim', 'personel', 'anabayi', 'secim'];
const canEdit  = <?= json_encode((bool)$pagePermissions['can_edit']) ?>;
const canAdd   = <?= json_encode((bool)$pagePermissions['can_add']) ?>;
const canDel   = <?= json_encode((bool)$pagePermissions['can_delete']) ?>;

let logTable, zamanlamaModal, tetikleModal, ciktiModal;
let gorevler = [];
let tumZamanlamalar = [];

$(document).ready(function () {
    zamanlamaModal = new bootstrap.Modal(document.getElementById('zamanlamaModal'));
    tetikleModal   = new bootstrap.Modal(document.getElementById('tetikleModal'));
    ciktiModal     = new bootstrap.Modal(document.getElementById('ciktiModal'));

    $('#log_gorev_filtre, #log_zamanlama_filtre').select2({ theme: 'bootstrap-5', width: '100%' });
    $('#log_gorev_filtre').on('change', function () { doldurZamanlamaFiltre($(this).val()); });

    statsYukle();
    gorevleriYukle();

    $('#tab-log').on('click', function () {
        if (!logTable) initLogTable();
        else logListele();
    });
});

// ─── Stats ────────────────────────────────────────────────────────────────────
function statsYukle() {
    $.post(PAGE_URL, { action: 'stats' }, function (r) {
        if (!r.success) return;
        $('#stat-gorev').text(r.data.toplam_gorev);
        $('#stat-zamanlama').text(r.data.aktif_zamanlama);
        $('#stat-basarili').text(r.data.bugun_basarili);
        $('#stat-hatali').text(r.data.bugun_hatali);
    });
}

// ─── Görev kartları ───────────────────────────────────────────────────────────
function gorevleriYukle() {
    $.post(PAGE_URL, { action: 'gorev_listele' }, function (r) {
        if (!r.success) { showToast('Görevler yüklenemedi.', 'error'); return; }
        gorevler = r.data;
        tumZamanlamalar = [];
        r.data.forEach(g => (g.Zamanlamalar || []).forEach(z => tumZamanlamalar.push({ id: z.CronZamanlamalar_Id, ad: z.CronZamanlamalar_Ad, gorevId: g.CronGorevler_Id })));
        renderGorevler(r.data);
        doldurGorevFiltre(r.data);
    });
}

function renderGorevler(data) {
    const $el = $('#gorevListesi').empty();
    data.forEach(function (g) {
        const zamanlamalar = g.Zamanlamalar || [];
        const params       = JSON.parse(g.CronGorevler_Parametreler || '[]');

        let zSatirlar = zamanlamalar.map(function (z) {
            const durumBadge = calismaDurumBadge(z.SonDurum);
            const aktifBtn   = canEdit
                ? `<button class="btn btn-xs btn-outline-${z.Durum ? 'warning' : 'success'} me-1" onclick="zamanlamaDurum(${z.CronZamanlamalar_Id}, ${z.Durum ? 0 : 1})" title="${z.Durum ? 'Durdur' : 'Aktifleştir'}"><i class="bi bi-${z.Durum ? 'pause' : 'play'}"></i></button>`
                : '';
            const tetikleBtn = canEdit
                ? `<button class="btn btn-xs btn-success me-1" onclick="tetikleZamanlamaAc(${z.CronZamanlamalar_Id})" title="Tetikle"><i class="bi bi-play-fill"></i></button>`
                : '';
            const editBtn    = canEdit
                ? `<button class="btn btn-xs btn-warning me-1" onclick="zamanlamaAc(${g.CronGorevler_Id}, ${z.CronZamanlamalar_Id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`
                : '';
            const silBtn     = canDel
                ? `<button class="btn btn-xs btn-danger" onclick="zamanlamaSil(${z.CronZamanlamalar_Id}, '${esc(z.CronZamanlamalar_Ad)}')" title="Sil"><i class="bi bi-trash"></i></button>`
                : '';

            const bitisStr = z.Bitis ? `<br><small class="text-danger">↩ ${z.Bitis}</small>` : '<br><small class="text-muted">Sürekli</small>';

            return `<tr class="${z.Durum ? '' : 'table-secondary text-muted'}">
                <td><span class="fw-semibold">${esc(z.CronZamanlamalar_Ad)}</span>${z.Durum ? '' : ' <span class="badge bg-secondary">Durduruldu</span>'}</td>
                <td><span class="cron-code">${esc(z.CronZamanlamalar_CronIfadesi)}</span>${Number(z.CronZamanlamalar_TelafiDakika) > 0
                    ? ` <span class="badge bg-info-subtle text-info-emphasis" title="Kaçan tur ${z.CronZamanlamalar_TelafiDakika} dk içinde telafi edilir"><i class="bi bi-arrow-clockwise"></i> telafi</span>` : ''}</td>
                <td class="small">${esc(z.Baslangic || '—')}${bitisStr}</td>
                <td class="small">${esc(z.SonCalisma || '—')}</td>
                <td>${z.SonCalisma ? durumBadge : '<span class="text-muted">—</span>'}${z.SonSure != null ? `<br><small class="text-muted">${z.SonSure}s</small>` : ''}</td>
                <td>${aktifBtn}${tetikleBtn}${editBtn}${silBtn}</td>
            </tr>`;
        }).join('');

        if (!zSatirlar) zSatirlar = '<tr><td colspan="6" class="text-center text-muted small py-2">Henüz zamanlayıcı yok.</td></tr>';

        const tetikleBtn = canEdit
            ? `<button class="btn btn-sm btn-outline-success" onclick="tetikleGorevAc(${g.CronGorevler_Id})"><i class="bi bi-play-fill"></i> Manuel Çalıştır</button>`
            : '';
        const ekleBtn = canAdd
            ? `<button class="btn btn-sm btn-primary" onclick="zamanlamaAc(${g.CronGorevler_Id}, 0)"><i class="bi bi-plus-circle"></i> Zamanlayıcı Ekle</button>`
            : '';

        $el.append(`
        <div class="card mb-3 border-${g.Durum ? 'primary' : 'secondary'}">
            <div class="card-header d-flex justify-content-between align-items-center py-2 bg-light">
                <div>
                    <span class="fw-bold">${esc(g.CronGorevler_Ad)}</span>
                    <span class="cron-code ms-2">${esc(g.CronGorevler_GorevKodu)}</span>
                    ${g.CronGorevler_Aciklama ? `<span class="text-muted small ms-2">${esc(g.CronGorevler_Aciklama)}</span>` : ''}
                </div>
                <div class="d-flex gap-1">${tetikleBtn} ${ekleBtn}</div>
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered table-sm mb-0 z-table">
                    <thead class="table-light"><tr><th>Zamanlayıcı Adı</th><th>Cron İfadesi</th><th>Başlangıç / Bitiş</th><th>Son Çalışma</th><th>Durum</th><th style="width:120px">İşlem</th></tr></thead>
                    <tbody>${zSatirlar}</tbody>
                </table>
            </div>
        </div>`);
    });
}

function calismaDurumBadge(d) {
    const n = parseInt(d);
    if (d === null || d === undefined || d === '') return '<span class="text-muted small">—</span>';
    if (n === 0) return '<span class="badge bg-warning text-dark">Devam</span>';
    if (n === 1) return '<span class="badge bg-success">Başarılı</span>';
    if (n === 2) return '<span class="badge bg-danger">Hata</span>';
    return '<span class="badge bg-secondary">?</span>';
}

// ─── Zamanlayıcı Modal ────────────────────────────────────────────────────────
function zamanlamaAc(gorevId, zamanlamaId) {
    const g = gorevler.find(x => x.CronGorevler_Id == gorevId);
    if (!g) return;

    document.getElementById('z_gorev_id').value = gorevId;
    document.getElementById('z_id').value        = zamanlamaId || '';
    document.getElementById('z_sablon').value    = '';

    const params = JSON.parse(g.CronGorevler_Parametreler || '[]');
    const hasPar = params.length > 0;
    document.getElementById('z_params_container').style.display = hasPar ? '' : 'none';

    if (zamanlamaId) {
        document.getElementById('zamanlamaBaslik').textContent = 'Zamanlayıcı Düzenle';
        const z = (g.Zamanlamalar || []).find(x => x.CronZamanlamalar_Id == zamanlamaId);
        if (!z) return;
        document.getElementById('z_ad').value      = z.CronZamanlamalar_Ad;
        document.getElementById('z_cron').value    = z.CronZamanlamalar_CronIfadesi;
        document.getElementById('z_sablon').value  = z.CronZamanlamalar_CronIfadesi;
        document.getElementById('z_baslangic').value = (z.Baslangic || '').replace(' ', 'T');
        document.getElementById('z_bitis').value     = (z.Bitis   || '').replace(' ', 'T');
        telafiAlaniDoldur(z.CronZamanlamalar_TelafiDakika);
        const savedParams = JSON.parse(z.CronZamanlamalar_SabitParametreler || '{}');
        renderZamanlamaParams(params, savedParams);
    } else {
        document.getElementById('zamanlamaBaslik').textContent = 'Yeni Zamanlayıcı';
        document.getElementById('z_ad').value      = '';
        document.getElementById('z_cron').value    = '';
        document.getElementById('z_baslangic').value = new Date().toISOString().slice(0, 16);
        document.getElementById('z_bitis').value     = '';
        telafiAlaniDoldur(null);
        renderZamanlamaParams(params, {});
    }

    zamanlamaModal.show();
}

// Telafi switch'i ve dakika alanını kayıtlı değere göre kur.
function telafiAlaniDoldur(dakika) {
    const acik = Number(dakika) > 0;
    document.getElementById('z_telafi').checked         = acik;
    document.getElementById('z_telafi_dakika').value    = acik ? dakika : 30;
    document.getElementById('z_telafi_dakika').disabled = !acik;
}

function renderZamanlamaParams(schema, values) {
    const $alan = $('#z_params_alan').empty();
    schema.forEach(function (p) {
        const val = values[p.ad] || '';
        if (SECIMLI_TIPLER.includes(p.tip)) {
            const opts = secimOptions(p.tip, val, p);
            $alan.append(`
            <div class="mb-2">
                <label class="form-label mb-1">${esc(p.etiket)} ${p.zorunlu ? '<span class="text-danger">*</span>' : ''}</label>
                <select class="form-select form-select-sm" id="zp_${p.ad}">${opts}</select>
            </div>`);
            modalSelect2('#zp_' + p.ad, '#zamanlamaModal');
        } else {
            $alan.append(`
            <div class="mb-2">
                <label class="form-label mb-1">${esc(p.etiket)} ${p.zorunlu ? '<span class="text-danger">*</span>' : ''}</label>
                <input type="text" class="form-control form-control-sm" id="zp_${p.ad}" value="${esc(val)}" placeholder="${esc(p.tip === 'tarih' ? '{dun} ya da dd.mm.yyyy' : '')}">
            </div>`);
        }
    });
}

// tip:"birim" alanları için ortak yardımcılar
function birimOptions(selected) {
    let opts = '<option value="">— Birim Seç —</option>';
    BIRIMLER.forEach(b => {
        const sel = String(b.KullaniciBirim_id) === String(selected) ? 'selected' : '';
        opts += `<option value="${b.KullaniciBirim_id}" ${sel}>${esc(b.KullaniciBirim_Adi)}</option>`;
    });
    return opts;
}

// tip:"personel" alanları için — etikette bayi kodu da gösterilir
function personelOptions(selected) {
    let opts = '<option value="">— Personel Seç —</option>';
    PERSONELLER.forEach(p => {
        const sel   = String(p.id) === String(selected) ? 'selected' : '';
        const etiket = p.bayi_kodu ? `${p.ad} (${p.bayi_kodu})` : p.ad;
        opts += `<option value="${p.id}" ${sel}>${esc(etiket)}</option>`;
    });
    return opts;
}

// tip:"anabayi" alanları için — boş seçim = tüm bayiler
function anaBayiOptions(selected) {
    let opts = '<option value="">— Tüm Bayiler —</option>';
    ANA_BAYILER.forEach(b => {
        const sel    = String(b.id) === String(selected) ? 'selected' : '';
        const etiket = (b.bayi_kodu ? `${b.ad} (${b.bayi_kodu})` : b.ad) + (Number(b.durum) === 1 ? '' : ' — pasif');
        opts += `<option value="${b.id}" ${sel}>${esc(etiket)}</option>`;
    });
    return opts;
}

// tip:"secim" alanları için — seçenekler görev kaydının parametre şemasından (secenekler) gelir
function secenekOptions(p, selected) {
    let opts = '<option value="">— Seçiniz —</option>';
    (p.secenekler || []).forEach(s => {
        const sel = String(s.deger) === String(selected) ? 'selected' : '';
        opts += `<option value="${esc(s.deger)}" ${sel}>${esc(s.etiket || s.deger)}</option>`;
    });
    return opts;
}

function secimOptions(tip, selected, p) {
    if (tip === 'secim')   return secenekOptions(p || {}, selected);
    if (tip === 'birim')   return birimOptions(selected);
    if (tip === 'anabayi') return anaBayiOptions(selected);
    return personelOptions(selected);
}

// Modal içi çift-init'e karşı destroy guard + dropdownParent (CLAUDE.md kuralı)
function modalSelect2(selId, modalId) {
    const $sel = $(selId);
    if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
    $sel.select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $(modalId) });
}

function cronSablonSec() {
    const val = document.getElementById('z_sablon').value;
    if (val) document.getElementById('z_cron').value = val;
}

function zamanlamaKaydet() {
    const id      = document.getElementById('z_id').value;
    const gorevId = document.getElementById('z_gorev_id').value;
    const ad      = document.getElementById('z_ad').value.trim();
    const cron    = document.getElementById('z_cron').value.trim();
    const bas     = document.getElementById('z_baslangic').value.replace('T', ' ');
    const bitis   = document.getElementById('z_bitis').value.replace('T', ' ') || '';
    const telafi  = document.getElementById('z_telafi').checked
        ? (parseInt(document.getElementById('z_telafi_dakika').value, 10) || 0) : 0;

    if (!ad || !cron) { showToast('Ad ve cron ifadesi zorunludur.', 'warning'); return; }
    if (telafi && (telafi < 1 || telafi > 720)) { showToast('Telafi süresi 1-720 dakika arasında olmalıdır.', 'warning'); return; }

    const g      = gorevler.find(x => x.CronGorevler_Id == gorevId);
    const params = JSON.parse((g?.CronGorevler_Parametreler) || '[]');
    const pObj   = {};
    params.forEach(p => { const v = ($('#zp_' + p.ad).val() || '').trim(); if (v) pObj[p.ad] = v; });

    const data = {
        action:       id ? 'zamanlama_guncelle' : 'zamanlama_ekle',
        gorev_id:     gorevId,
        zamanlama_id: id,
        ad,
        cron_ifadesi: cron,
        baslangic:    bas,
        bitis,
        sabit_params: Object.keys(pObj).length ? JSON.stringify(pObj) : '',
        telafi_dakika: telafi,
    };

    $.post(PAGE_URL, data, function (r) {
        if (r.success) {
            zamanlamaModal.hide();
            showToast(r.message, 'success');
            gorevleriYukle();
            statsYukle();
        } else {
            showToast(r.message, 'error');
        }
    });
}

function zamanlamaSil(id, ad) {
    confirmAction(`"${ad}" zamanlayıcısını silmek istiyor musunuz?`, '', function () {
        $.post(PAGE_URL, { action: 'zamanlama_sil', zamanlama_id: id }, function (r) {
            if (r.success) { showToast(r.message, 'success'); gorevleriYukle(); statsYukle(); }
            else showToast(r.message, 'error');
        });
    });
}

function zamanlamaDurum(id, durum) {
    $.post(PAGE_URL, { action: 'zamanlama_durum', zamanlama_id: id, durum }, function (r) {
        if (r.success) gorevleriYukle();
        else showToast(r.message, 'error');
    });
}

// ─── Manuel Tetikleme ─────────────────────────────────────────────────────────
function tetikleGorevAc(gorevId) {
    const g = gorevler.find(x => x.CronGorevler_Id == gorevId);
    if (!g) return;
    document.getElementById('tetikleGorevId').value      = gorevId;
    document.getElementById('tetikleZamanlamaId').value  = '';
    document.getElementById('tetikleBaslik').textContent = g.CronGorevler_Ad;
    const params = JSON.parse(g.CronGorevler_Parametreler || '[]');
    renderTetikleParams(params, {});
    tetikleModal.show();
}

function tetikleZamanlamaAc(zamanlamaId) {
    let g = null, z = null;
    gorevler.forEach(gv => { const found = (gv.Zamanlamalar || []).find(x => x.CronZamanlamalar_Id == zamanlamaId); if (found) { g = gv; z = found; } });
    if (!g || !z) return;
    const savedVals = JSON.parse(z.CronZamanlamalar_SabitParametreler || '{}');
    document.getElementById('tetikleGorevId').value      = g.CronGorevler_Id;
    document.getElementById('tetikleZamanlamaId').value  = zamanlamaId;
    document.getElementById('tetikleBaslik').textContent = `${g.CronGorevler_Ad} » ${z.CronZamanlamalar_Ad}`;

    // Kayıtlı parametreleri sadece bilgi olarak göster, tekrar sormadan çalıştır
    const $alan = $('#tetikleParamAlan').empty();
    const anahtarlar = Object.keys(savedVals);
    if (anahtarlar.length) {
        let html = '<div class="alert alert-secondary py-2 small mb-0"><i class="bi bi-database me-1"></i><strong>Kayıtlı parametreler:</strong><ul class="mb-0 mt-1">';
        anahtarlar.forEach(k => { html += `<li><code>${esc(k)}</code> = <code>${esc(String(savedVals[k]))}</code></li>`; });
        html += '</ul></div>';
        $alan.html(html);
    } else {
        $alan.html('<p class="text-muted small mb-0"><i class="bi bi-info-circle"></i> Kayıtlı parametre yok, görev varsayılan değerlerle çalışacak.</p>');
    }
    tetikleModal.show();
}

function renderTetikleParams(params, values) {
    const $alan = $('#tetikleParamAlan').empty();
    if (!params.length) {
        $alan.append('<p class="text-muted small mb-2"><i class="bi bi-info-circle"></i> Bu görev parametre gerektirmiyor.</p>');
        return;
    }
    params.forEach(function (p) {
        const saved = values[p.ad] || '';
        if (SECIMLI_TIPLER.includes(p.tip)) {
            const opts = secimOptions(p.tip, saved, p);
            $alan.append(`
            <div class="mb-3">
                <label class="form-label">${esc(p.etiket)} ${p.zorunlu ? '<span class="text-danger">*</span>' : ''}</label>
                <select class="form-select" id="tp_${p.ad}">${opts}</select>
            </div>`);
            modalSelect2('#tp_' + p.ad, '#tetikleModal');
            return;
        }
        const isDate = p.tip === 'tarih';
        const inputVal = isDate && saved && !saved.startsWith('{') ? saved.split('.').reverse().join('-') : '';
        $alan.append(`
        <div class="mb-3">
            <label class="form-label">${esc(p.etiket)} ${p.zorunlu ? '<span class="text-danger">*</span>' : ''}</label>
            <input type="${isDate ? 'date' : 'text'}" class="form-control" id="tp_${p.ad}" value="${esc(inputVal || '')}">
            ${saved ? `<div class="form-text">Kayıtlı: <code>${esc(saved)}</code></div>` : ''}
        </div>`);
    });
}

function tetikleBaslat() {
    const gorevId     = document.getElementById('tetikleGorevId').value;
    const zamanlamaId = document.getElementById('tetikleZamanlamaId').value;
    const g           = gorevler.find(x => x.CronGorevler_Id == gorevId);
    if (!g) return;

    const data = { action: 'tetikle', gorev_id: gorevId, zamanlama_id: zamanlamaId || '' };

    // Zamanlayıcı tetiklemesinde parametreler DB'den gelir; form validasyonu sadece manuel çalıştırmada yapılır
    if (!zamanlamaId) {
        const params = JSON.parse(g.CronGorevler_Parametreler || '[]');
        for (const p of params) {
            const val = ($('#tp_' + p.ad).val() || '').trim();
            if ((p.zorunlu ?? false) && !val) { showToast(p.etiket + ' zorunludur.', 'warning'); return; }
            if (val) data[p.ad] = p.tip === 'tarih' && /^\d{4}-\d{2}-\d{2}$/.test(val) ? val.split('-').reverse().join('.') : val;
        }
    }

    tetikleModal.hide();
    Swal.fire({ title: 'Görev çalıştırılıyor…', html: `<b>${esc(g.CronGorevler_Ad)}</b> çalışıyor, lütfen bekleyin.`, allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    $.ajax({
        url: PAGE_URL, method: 'POST', data, dataType: 'json', timeout: 700000,
        success: function (r) {
            Swal.close();
            statsYukle(); gorevleriYukle();
            if (r.success) showCikti(r.durum, r.message, r.cikti || '');
            else showError('Hata!', r.message);
        },
        error: function (xhr, status) {
            Swal.close();
            showError('Bağlantı Hatası', status === 'timeout' ? 'Zaman aşımı.' : 'Sunucuya ulaşılamadı.');
        }
    });
}

function showCikti(durum, mesaj, cikti) {
    const badge = durum === 1 ? '<span class="badge bg-success fs-6">Başarılı</span>' : '<span class="badge bg-danger fs-6">Hata</span>';
    document.getElementById('ciktiDurum').innerHTML  = badge + ' <span class="ms-2">' + esc(mesaj) + '</span>';
    document.getElementById('ciktiMetin').textContent = cikti;
    ciktiModal.show();
}

// ─── Log ─────────────────────────────────────────────────────────────────────
function doldurGorevFiltre(data) {
    const $sel = $('#log_gorev_filtre');
    $sel.find('option:not(:first)').remove();
    data.forEach(g => $sel.append(`<option value="${g.CronGorevler_Id}">${esc(g.CronGorevler_Ad)}</option>`));
    $sel.trigger('change.select2');
}

function doldurZamanlamaFiltre(gorevId) {
    const $sel = $('#log_zamanlama_filtre');
    $sel.find('option:not(:first)').remove();
    const g = gorevler.find(x => x.CronGorevler_Id == gorevId);
    if (g) (g.Zamanlamalar || []).forEach(z => $sel.append(`<option value="${z.CronZamanlamalar_Id}">${esc(z.CronZamanlamalar_Ad)}</option>`));
    $sel.trigger('change.select2');
}

function initLogTable() {
    logTable = $('#logTable').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        order: [[0, 'desc']], pageLength: 25,
        columnDefs: [{ orderable: false, targets: [8] }]
    });
    logListele();
}

function logListele() {
    $.post(PAGE_URL, { action: 'log_listele', gorev_id: $('#log_gorev_filtre').val() || 0, zamanlama_id: $('#log_zamanlama_filtre').val() || 0 }, function (r) {
        if (!r.success || !logTable) return;
        logTable.clear();
        r.data.forEach(function (l) {
            logTable.row.add([
                l.CronCalismaLog_Id,
                `<span class="fw-semibold">${esc(l.CronGorevler_Ad)}</span>`,
                esc(l.ZamanlamaAd || '—'),
                l.CronCalismaLog_TetikleyenTur == 1 ? '<span class="badge bg-secondary">Otomatik</span>' : '<span class="badge bg-primary">Manuel</span>',
                esc(l.BaslangicTarihi || '—'),
                esc(l.BitisTarihi || '—'),
                l.CronCalismaLog_SureSaniye != null ? `<span class="badge bg-light text-dark">${l.CronCalismaLog_SureSaniye}s</span>` : '—',
                calismaDurumBadge(l.CronCalismaLog_CalismaDurum),
                `<small class="text-muted">${esc((l.CronCalismaLog_Sonuc || '').substring(0, 80))}${(l.CronCalismaLog_Sonuc || '').length > 80 ? '…' : ''}</small>`
            ]);
        });
        logTable.draw();
    });
}

// ─── Yardımcılar ─────────────────────────────────────────────────────────────
function kopyala(inputId) {
    const el = document.getElementById(inputId);
    el.select();
    document.execCommand('copy');
    showToast('Kopyalandı!', 'success');
}

function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
