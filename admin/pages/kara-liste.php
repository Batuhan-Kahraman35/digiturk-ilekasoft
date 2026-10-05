<?php
/**
 * Admin Panel - Kara Liste
 *
 * OTP, başvuru ve lead akışlarında engellenecek GSM / TC kayıtlarının yönetimi.
 * Engellenen denemeler dbo.BasvuruLog tablosuna 'KARALISTE_ENGEL' işlemiyle yazılır.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/KaraListeHelper.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Kara Liste';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

/** Kayıt türleri (tablo şemasının parçası — tek yerde tanımlı) */
$KARALISTE_TURLERI = [
    'gsm' => 'Telefon (GSM)',
    'tc'  => 'TC Kimlik No',
];

// ─── AJAX ─────────────────────────────────────────────────────────────────────
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

                $search    = trim($_POST['f_search'] ?? '');
                $tur       = $_POST['f_tur']    ?? '';
                $durum     = $_POST['f_durum']  ?? '';
                $kaynak    = trim($_POST['f_kaynak'] ?? '');
                $baslangic = trim($_POST['f_baslangic'] ?? '');
                $bitis     = trim($_POST['f_bitis'] ?? '');

                $where  = ["1=1"];
                $params = [];

                if ($search !== '') {
                    // Numara aranıyorsa normalize edilmiş halini de dene
                    $rakam = preg_replace('/\D/', '', $search);
                    $where[] = "(t.KaraListe_Deger LIKE ? OR t.KaraListe_Aciklama LIKE ? OR t.KaraListe_Kaynak LIKE ?" .
                               ($rakam !== '' ? " OR t.KaraListe_Deger LIKE ?" : "") . ")";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    if ($rakam !== '') $params[] = "%$rakam%";
                }
                if ($tur    !== '' && isset($KARALISTE_TURLERI[$tur])) { $where[] = "t.KaraListe_Tur = ?"; $params[] = $tur; }
                if ($kaynak !== '') { $where[] = "t.KaraListe_Kaynak = ?"; $params[] = $kaynak; }

                if ($durum === '1')      { $where[] = "t.Durum = 1"; }
                elseif ($durum === '0')  { $where[] = "t.Durum = 0"; }
                elseif ($durum === 'suresi_dolmus') {
                    $where[] = "t.Durum = 1 AND t.KaraListe_BitisTarihi IS NOT NULL AND t.KaraListe_BitisTarihi < GETDATE()";
                } elseif ($durum === 'yururlukte') {
                    $where[] = "t.Durum = 1
                                AND (t.KaraListe_BaslangicTarihi IS NULL OR t.KaraListe_BaslangicTarihi <= GETDATE())
                                AND (t.KaraListe_BitisTarihi     IS NULL OR t.KaraListe_BitisTarihi     >= GETDATE())";
                }

                if ($baslangic !== '') { $where[] = "t.OlusturmaTarihi >= ?"; $params[] = $baslangic . ' 00:00:00'; }
                if ($bitis     !== '') { $where[] = "t.OlusturmaTarihi <= ?"; $params[] = $bitis . ' 23:59:59'; }

                $wClause = implode(" AND ", $where);

                $recordsTotal    = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM dbo.KaraListe")['c'] ?? 0);
                $recordsFiltered = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM dbo.KaraListe t WHERE $wClause", $params)['c'] ?? 0);

                $orderMap = [
                    0 => 't.KaraListe_Deger',
                    1 => 't.KaraListe_Tur',
                    2 => 't.KaraListe_Aciklama',
                    3 => 't.KaraListe_Kaynak',
                    4 => 't.KaraListe_BitisTarihi',
                    5 => 't.KaraListe_EngellemeSayisi',
                    6 => 't.Durum',
                    7 => 't.OlusturmaTarihi',
                ];
                $orderIdx = (int)($_POST['order'][0]['column'] ?? 7);
                $orderBy  = $orderMap[$orderIdx] ?? 't.OlusturmaTarihi';
                $orderDir = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

                $data = [];
                if ($recordsFiltered > 0) {
                    $ids = $db->fetchAll("
                        SELECT t.KaraListe_id
                        FROM dbo.KaraListe t
                        WHERE $wClause
                        ORDER BY $orderBy $orderDir, t.KaraListe_id DESC
                        OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
                    ", $params);

                    if ($ids) {
                        $idList = implode(',', array_map(fn($r) => (int)$r['KaraListe_id'], $ids));
                        $data = $db->fetchAll("
                            SELECT
                                t.KaraListe_id,
                                t.KaraListe_Tur,
                                t.KaraListe_Deger,
                                t.KaraListe_Aciklama,
                                t.KaraListe_Kaynak,
                                t.KaraListe_EngellemeSayisi,
                                t.Durum,
                                CONVERT(VARCHAR(10), t.KaraListe_BaslangicTarihi, 120) AS BaslangicTarihi,
                                CONVERT(VARCHAR(10), t.KaraListe_BitisTarihi, 120)     AS BitisTarihi,
                                CONVERT(VARCHAR(19), t.KaraListe_SonEngellemeTarihi, 120) AS SonEngellemeTarihi,
                                CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120)           AS OlusturmaTarihi,
                                CASE
                                    WHEN t.Durum = 0 THEN 'pasif'
                                    WHEN t.KaraListe_BitisTarihi IS NOT NULL AND t.KaraListe_BitisTarihi < GETDATE() THEN 'suresi_dolmus'
                                    WHEN t.KaraListe_BaslangicTarihi IS NOT NULL AND t.KaraListe_BaslangicTarihi > GETDATE() THEN 'beklemede'
                                    ELSE 'yururlukte'
                                END AS EtkinDurum,
                                LTRIM(RTRIM(ISNULL(k.kullanici_ad,'') + ' ' + ISNULL(k.kullanici_soyad,''))) AS EkleyenAd
                            FROM dbo.KaraListe t
                            LEFT JOIN kullanicilar k ON k.kullanici_id = t.OlusturanKullanici
                            WHERE t.KaraListe_id IN ($idList)
                            ORDER BY $orderBy $orderDir, t.KaraListe_id DESC
                        ");
                    }
                }

                echo json_encode([
                    'draw' => $draw, 'recordsTotal' => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered, 'data' => $data
                ]);
                break;

            case 'stats':
                $stats = [
                    'toplam'     => $db->fetchOne("SELECT COUNT(*) AS c FROM dbo.KaraListe")['c'] ?? 0,
                    'yururlukte' => $db->fetchOne("
                        SELECT COUNT(*) AS c FROM dbo.KaraListe
                        WHERE Durum = 1
                          AND (KaraListe_BaslangicTarihi IS NULL OR KaraListe_BaslangicTarihi <= GETDATE())
                          AND (KaraListe_BitisTarihi     IS NULL OR KaraListe_BitisTarihi     >= GETDATE())")['c'] ?? 0,
                    'pasif'      => $db->fetchOne("
                        SELECT COUNT(*) AS c FROM dbo.KaraListe
                        WHERE Durum = 0
                           OR (KaraListe_BitisTarihi IS NOT NULL AND KaraListe_BitisTarihi < GETDATE())")['c'] ?? 0,
                    'engelleme'  => $db->fetchOne("SELECT ISNULL(SUM(KaraListe_EngellemeSayisi),0) AS c FROM dbo.KaraListe")['c'] ?? 0,
                    'bugun'      => $db->fetchOne("
                        SELECT COUNT(*) AS c FROM BasvuruLog
                        WHERE BasvuruLog_Islem = ? AND CAST(OlusturmaTarihi AS DATE) = CAST(GETDATE() AS DATE)",
                        [KaraListe::LOG_ISLEM])['c'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            case 'get':
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("
                    SELECT
                        KaraListe_id, KaraListe_Tur, KaraListe_Deger, KaraListe_Aciklama, KaraListe_Kaynak, Durum,
                        CONVERT(VARCHAR(10), KaraListe_BaslangicTarihi, 120) AS BaslangicTarihi,
                        CONVERT(VARCHAR(10), KaraListe_BitisTarihi, 120)     AS BitisTarihi
                    FROM dbo.KaraListe WHERE KaraListe_id = ?", [$id]);
                if (!$row) { echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı']); break; }
                echo json_encode(['success' => true, 'data' => $row]);
                break;

            case 'save':
                $id = (int)($_POST['id'] ?? 0);

                if ($id > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']); break;
                }
                if ($id === 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']); break;
                }

                $tur = $_POST['tur'] ?? 'gsm';
                if (!isset($KARALISTE_TURLERI[$tur])) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz kayıt türü']); break;
                }

                $ham   = trim((string)($_POST['deger'] ?? ''));
                $deger = $tur === 'tc' ? KaraListe::normalizeTc($ham) : KaraListe::normalizeGsm($ham);

                if ($deger === null) {
                    echo json_encode(['success' => false, 'message' => $tur === 'tc'
                        ? 'Geçersiz TC kimlik no (11 hane olmalı)'
                        : 'Geçersiz numara. Örnek: 5321234567 veya 905321234567']);
                    break;
                }

                $baslangic = trim((string)($_POST['baslangic'] ?? ''));
                $bitis     = trim((string)($_POST['bitis'] ?? ''));
                if ($baslangic !== '' && $bitis !== '' && $bitis < $baslangic) {
                    echo json_encode(['success' => false, 'message' => 'Bitiş tarihi başlangıçtan önce olamaz']); break;
                }

                // Aynı tür+değer başka kayıtta var mı?
                $cakisma = $db->fetchOne(
                    "SELECT KaraListe_id FROM dbo.KaraListe WHERE KaraListe_Tur = ? AND KaraListe_Deger = ? AND KaraListe_id <> ?",
                    [$tur, $deger, $id]
                );
                if ($cakisma) {
                    echo json_encode(['success' => false, 'message' => $deger . ' zaten kara listede kayıtlı']); break;
                }

                $veri = [
                    'KaraListe_Tur'             => $tur,
                    'KaraListe_Deger'           => $deger,
                    'KaraListe_Aciklama'        => trim((string)($_POST['aciklama'] ?? '')) ?: null,
                    'KaraListe_Kaynak'          => trim((string)($_POST['kaynak'] ?? '')) ?: 'manuel',
                    'KaraListe_BaslangicTarihi' => $baslangic ?: null,
                    'KaraListe_BitisTarihi'     => $bitis ? $bitis . ' 23:59:59' : null,
                    'Durum'                     => isset($_POST['durum']) ? 1 : 0,
                    'GuncelleyenKullanici'      => $user['kullanici_id'],
                    'GuncellemeTarihi'          => date('Y-m-d H:i:s'),
                ];

                if ($id > 0) {
                    $ok = $db->update('KaraListe', $veri, ['KaraListe_id' => $id]);
                    echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Kayıt güncellendi' : 'Güncelleme hatası']);
                } else {
                    $veri['OlusturanKullanici'] = $user['kullanici_id'];
                    $veri['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                    $ok = $db->insert('KaraListe', $veri);
                    echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Kayıt eklendi' : 'Ekleme hatası']);
                }
                break;

            case 'toplu_ekle':
                if (!$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']); break;
                }

                $tur = $_POST['tur'] ?? 'gsm';
                if (!isset($KARALISTE_TURLERI[$tur])) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz kayıt türü']); break;
                }

                $ham      = (string)($_POST['degerler'] ?? '');
                $aciklama = trim((string)($_POST['aciklama'] ?? '')) ?: null;
                $kaynak   = trim((string)($_POST['kaynak'] ?? '')) ?: 'toplu';
                $bitis    = trim((string)($_POST['bitis'] ?? ''));

                $satirlar = preg_split('/[\r\n,;\t]+/', $ham, -1, PREG_SPLIT_NO_EMPTY);
                if (!$satirlar) {
                    echo json_encode(['success' => false, 'message' => 'Hiç değer girilmedi']); break;
                }
                if (count($satirlar) > 5000) {
                    echo json_encode(['success' => false, 'message' => 'Tek seferde en fazla 5000 kayıt eklenebilir']); break;
                }

                $eklendi = 0; $guncellendi = 0; $gecersiz = [];
                foreach ($satirlar as $s) {
                    $s = trim($s);
                    if ($s === '') continue;
                    $r = KaraListe::ekle($s, $tur, $aciklama, $kaynak, $user['kullanici_id'], null,
                                         $bitis ? $bitis . ' 23:59:59' : null);
                    if (!$r['success'])              $gecersiz[] = $s;
                    elseif ($r['durum'] === 'eklendi') $eklendi++;
                    else                              $guncellendi++;
                }

                echo json_encode([
                    'success' => true,
                    'message' => "Eklenen: $eklendi, Güncellenen: $guncellendi, Geçersiz: " . count($gecersiz),
                    'gecersiz' => array_slice($gecersiz, 0, 50),
                ]);
                break;

            case 'toggle_durum':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']); break;
                }
                $id    = (int)($_POST['id'] ?? 0);
                $durum = (int)($_POST['durum'] ?? 0) === 1 ? 1 : 0;
                $ok = $db->update('KaraListe', [
                    'Durum'                => $durum,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['KaraListe_id' => $id]);
                echo json_encode(['success' => (bool)$ok]);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']); break;
                }
                $id = (int)($_POST['id'] ?? 0);
                $ok = $db->delete('KaraListe', ['KaraListe_id' => $id]);
                echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Kayıt silindi' : 'Silme hatası']);
                break;

            case 'gecmis':
                // Bu değere ait engellenen denemeler (BasvuruLog)
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("SELECT KaraListe_Deger FROM dbo.KaraListe WHERE KaraListe_id = ?", [$id]);
                if (!$row) { echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı']); break; }

                $liste = $db->fetchAll("
                    SELECT TOP 200
                        l.BasvuruLog_id,
                        l.BasvuruLog_Basvuru_id,
                        l.BasvuruLog_ApiEndpoint,
                        l.BasvuruLog_Aciklama,
                        l.BasvuruLog_IP,
                        CONVERT(VARCHAR(19), l.OlusturmaTarihi, 120) AS OlusturmaTarihi,
                        LTRIM(RTRIM(ISNULL(k.kullanici_ad,'') + ' ' + ISNULL(k.kullanici_soyad,''))) AS KullaniciAdi
                    FROM BasvuruLog l
                    LEFT JOIN kullanicilar k ON k.kullanici_id = l.OlusturanKullanici
                    WHERE l.BasvuruLog_Islem = ?
                      AND l.BasvuruLog_Aciklama LIKE ?
                    ORDER BY l.OlusturmaTarihi DESC
                ", [KaraListe::LOG_ISLEM, $row['KaraListe_Deger'] . '%']);

                echo json_encode(['success' => true, 'deger' => $row['KaraListe_Deger'], 'data' => $liste]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// Filtre dropdown verileri (DB'den dinamik)
$kaynaklar = $db->fetchAll("
    SELECT DISTINCT KaraListe_Kaynak AS ad
    FROM dbo.KaraListe
    WHERE KaraListe_Kaynak IS NOT NULL AND LTRIM(RTRIM(KaraListe_Kaynak)) <> ''
    ORDER BY KaraListe_Kaynak
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
        .durum-badge { padding: .25rem .5rem; border-radius: .25rem; font-size: .8rem; white-space: nowrap; }
        .durum-yururlukte    { background-color: #f8d7da; color: #721c24; }
        .durum-pasif         { background-color: #e2e3e5; color: #41464b; }
        .durum-suresi_dolmus { background-color: #fff3cd; color: #664d03; }
        .durum-beklemede     { background-color: #cff4fc; color: #055160; }
        .deger-badge { font-family: monospace; font-weight: 600; font-size: .9rem; }
        .tur-badge   { font-size: .75rem; padding: .15rem .4rem; border-radius: .25rem; background: #e9ecef; color: #495057; }
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
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-list-ul"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Kayıt</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-shield-slash"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Yürürlükte</span>
                                <span class="info-box-number" id="stat-yururlukte">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-secondary">
                            <span class="info-box-icon"><i class="bi bi-pause-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Pasif / Süresi Dolmuş</span>
                                <span class="info-box-number" id="stat-pasif">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-slash-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Engellenen Deneme</span>
                                <span class="info-box-number" id="stat-engelleme">0</span>
                                <span class="info-box-text">Bugün: <b id="stat-bugun">0</b></span>
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
                                <div class="col-md-4">
                                    <label class="form-label">Ara</label>
                                    <input type="text" class="form-control" id="filter_search" placeholder="Numara, TC, açıklama...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tür</label>
                                    <select class="form-select" id="filter_tur">
                                        <option value="">Tümü</option>
                                        <?php foreach ($KARALISTE_TURLERI as $k => $v): ?>
                                        <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Durum</label>
                                    <select class="form-select" id="filter_durum">
                                        <option value="">Tümü</option>
                                        <option value="yururlukte">Yürürlükte</option>
                                        <option value="suresi_dolmus">Süresi Dolmuş</option>
                                        <option value="0">Pasif</option>
                                        <option value="1">Aktif (tarih bakılmaksızın)</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Kaynak</label>
                                    <select class="form-select" id="filter_kaynak">
                                        <option value="">Tümü</option>
                                        <?php foreach ($kaynaklar as $k): ?>
                                        <option value="<?= htmlspecialchars($k['ad']) ?>"><?= htmlspecialchars($k['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Eklenme (Başlangıç)</label>
                                    <input type="date" class="form-control" id="filter_baslangic">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Eklenme (Bitiş)</label>
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
                    <div class="card-header">
                        <h3 class="card-title">Engellenen Numaralar</h3>
                        <div class="card-tools d-flex gap-2">
                            <?php if ($permissions['can_add']): ?>
                            <button type="button" class="btn btn-warning btn-sm" id="btnTopluEkle">
                                <i class="bi bi-list-check"></i> Toplu Ekle
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" id="btnYeniEkle">
                                <i class="bi bi-plus-circle"></i> Yeni Ekle
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover w-100">
                            <thead>
                                <tr>
                                    <th>Değer</th>
                                    <th>Tür</th>
                                    <th>Açıklama</th>
                                    <th>Kaynak</th>
                                    <th>Bitiş</th>
                                    <th>Engelleme</th>
                                    <th>Durum</th>
                                    <th>Eklenme</th>
                                    <th style="width:120px">İşlemler</th>
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
<div class="modal fade" id="kayitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kayitModalLabel">Kara Listeye Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kayitForm">
                <div class="modal-body">
                    <input type="hidden" id="rec_id" name="id">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Tür <span class="text-danger">*</span></label>
                            <select class="form-select" id="rec_tur" name="tur">
                                <?php foreach ($KARALISTE_TURLERI as $k => $v): ?>
                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Değer <span class="text-danger">*</span></label>
                            <input type="text" class="form-control font-monospace" id="rec_deger" name="deger" required
                                   placeholder="5321234567 / 905321234567">
                            <div class="form-text">Numara otomatik olarak 90XXXXXXXXXX biçimine çevrilir.</div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Açıklama</label>
                            <input type="text" class="form-control" id="rec_aciklama" name="aciklama" maxlength="500"
                                   placeholder="Ör: KVKK talebi, mükerrer başvuru, şikayet">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Kaynak</label>
                            <input type="text" class="form-control" id="rec_kaynak" name="kaynak" maxlength="100"
                                   list="kaynakListesi" placeholder="manuel">
                            <datalist id="kaynakListesi">
                                <?php foreach ($kaynaklar as $k): ?>
                                <option value="<?= htmlspecialchars($k['ad']) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Başlangıç Tarihi</label>
                            <input type="date" class="form-control" id="rec_baslangic" name="baslangic">
                            <div class="form-text">Boş = hemen</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Bitiş Tarihi</label>
                            <input type="date" class="form-control" id="rec_bitis" name="bitis">
                            <div class="form-text">Boş = süresiz</div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="rec_durum" name="durum" value="1" checked>
                                <label class="form-check-label" for="rec_durum">Aktif</label>
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

<!-- Toplu Ekleme Modal -->
<div class="modal fade" id="topluModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Toplu Kara Liste Ekleme</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="topluForm">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Tür <span class="text-danger">*</span></label>
                            <select class="form-select" id="toplu_tur" name="tur">
                                <?php foreach ($KARALISTE_TURLERI as $k => $v): ?>
                                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Kaynak</label>
                            <input type="text" class="form-control" id="toplu_kaynak" name="kaynak" maxlength="100"
                                   list="kaynakListesi" placeholder="toplu">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Bitiş Tarihi</label>
                            <input type="date" class="form-control" id="toplu_bitis" name="bitis">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Açıklama (hepsine uygulanır)</label>
                            <input type="text" class="form-control" id="toplu_aciklama" name="aciklama" maxlength="500">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Değerler <span class="text-danger">*</span></label>
                            <textarea class="form-control font-monospace" id="toplu_degerler" name="degerler" rows="10" required
                                      placeholder="Her satıra bir numara:&#10;5321234567&#10;05339876543&#10;+90 542 111 22 33"></textarea>
                            <div class="form-text">Satır, virgül, noktalı virgül veya sekme ile ayrılabilir. En fazla 5000 kayıt.</div>
                        </div>
                        <div class="col-md-12 d-none" id="topluSonuc"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-upload"></i> Ekle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Engelleme Geçmişi Modal -->
<div class="modal fade" id="gecmisModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Engelleme Geçmişi — <span id="gecmisDeger" class="font-monospace"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-striped mb-0">
                        <thead>
                            <tr>
                                <th style="width:160px">Tarih</th>
                                <th>Nokta</th>
                                <th>Açıklama</th>
                                <th style="width:120px">IP</th>
                                <th style="width:90px">Başvuru</th>
                            </tr>
                        </thead>
                        <tbody id="gecmisBody"></tbody>
                    </table>
                </div>
                <div class="form-text mt-2">Son 200 kayıt gösterilir. Tamamı için Başvuru Log sayfasındaki "Kara Liste Engel" filtresini kullanın.</div>
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

    const TUR_ADLARI = <?= json_encode($KARALISTE_TURLERI, JSON_UNESCAPED_UNICODE) ?>;

    const DURUM_ETIKET = {
        yururlukte:    'Yürürlükte',
        pasif:         'Pasif',
        suresi_dolmus: 'Süresi Dolmuş',
        beklemede:     'Beklemede'
    };

    let kayitModal, topluModal, gecmisModal, dataTable;

    $(document).ready(function () {
        kayitModal  = new bootstrap.Modal(document.getElementById('kayitModal'));
        topluModal  = new bootstrap.Modal(document.getElementById('topluModal'));
        gecmisModal = new bootstrap.Modal(document.getElementById('gecmisModal'));

        // Modal içi select'ler: custom.js otomatik init ettiği için önce destroy, sonra dropdownParent ile kur
        ['#rec_tur:#kayitModal', '#toplu_tur:#topluModal'].forEach(function (p) {
            const [sel, parent] = p.split(':');
            if ($(sel).hasClass('select2-hidden-accessible')) $(sel).select2('destroy');
            $(sel).select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $(parent), minimumResultsForSearch: Infinity });
        });

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            processing: true,
            serverSide: true,
            scrollX: true,
            dom: 'lrtip',
            order: [[7, 'desc']],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            ajax: {
                url: '', type: 'POST',
                data: function (d) {
                    d.action       = 'list';
                    d.f_search     = $('#filter_search').val()    || '';
                    d.f_tur        = $('#filter_tur').val()       || '';
                    d.f_durum      = $('#filter_durum').val()     || '';
                    d.f_kaynak     = $('#filter_kaynak').val()    || '';
                    d.f_baslangic  = $('#filter_baslangic').val() || '';
                    d.f_bitis      = $('#filter_bitis').val()     || '';
                },
                error: function () { showToast('Liste yüklenirken hata oluştu', 'error'); }
            },
            columnDefs: [{ orderable: false, targets: [8] }],
            columns: [
                { data: 'KaraListe_Deger', render: d => `<span class="deger-badge">${escapeHtml(d)}</span>` },
                { data: 'KaraListe_Tur',   render: d => `<span class="tur-badge">${escapeHtml(TUR_ADLARI[d] || d)}</span>` },
                { data: 'KaraListe_Aciklama', render: d => escapeHtml(d || '-') },
                { data: 'KaraListe_Kaynak',   render: d => escapeHtml(d || '-') },
                { data: 'BitisTarihi',        render: d => d ? escapeHtml(d) : '<span class="text-muted">Süresiz</span>' },
                { data: 'KaraListe_EngellemeSayisi', render: function (d, t, row) {
                    const n = parseInt(d || 0, 10);
                    if (!n) return '<span class="text-muted">0</span>';
                    return `<a href="#" onclick="gecmisGoster(${row.KaraListe_id});return false;" title="${escapeHtml(row.SonEngellemeTarihi || '')}">
                                <span class="badge bg-warning text-dark">${n}</span></a>`;
                }},
                { data: 'EtkinDurum', render: function (d, t, row) {
                    const etiket = DURUM_ETIKET[d] || d;
                    const yeni   = row.Durum == 1 ? 0 : 1;
                    const tik    = permissions.canEdit
                        ? ` style="cursor:pointer" onclick="toggleDurum(${row.KaraListe_id}, ${yeni})" title="${row.Durum == 1 ? 'Pasife al' : 'Aktife al'}"`
                        : '';
                    return `<span class="durum-badge durum-${d}"${tik}>${etiket}</span>`;
                }},
                { data: 'OlusturmaTarihi', render: function (d, t, row) {
                    return escapeHtml(d || '-') + (row.EkleyenAd ? `<br><small class="text-muted">${escapeHtml(row.EkleyenAd)}</small>` : '');
                }},
                { data: 'KaraListe_id', render: function (d) {
                    let h = `<button class="btn btn-sm btn-secondary me-1" onclick="gecmisGoster(${d})" title="Engelleme geçmişi"><i class="bi bi-clock-history"></i></button>`;
                    if (permissions.canEdit)
                        h += `<button class="btn btn-sm btn-warning me-1" onclick="editRecord(${d})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
                    if (permissions.canDelete)
                        h += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${d})" title="Sil"><i class="bi bi-trash"></i></button>`;
                    return h;
                }}
            ]
        });

        loadStats();

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            dataTable.ajax.reload();
            loadStats();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filter_search, #filter_baslangic, #filter_bitis').val('');
            $('#filter_tur, #filter_durum, #filter_kaynak').val('').trigger('change');
            dataTable.ajax.reload();
            loadStats();
            showToast('Filtreler temizlendi', 'info');
        });

        $('#btnYeniEkle').on('click', function () { resetForm(); kayitModal.show(); });

        $('#btnTopluEkle').on('click', function () {
            document.getElementById('topluForm').reset();
            $('#toplu_tur').val('gsm').trigger('change');
            $('#topluSonuc').addClass('d-none').html('');
            topluModal.show();
        });

        $('#kayitForm').on('submit', function (e) { e.preventDefault(); saveRecord(); });
        $('#topluForm').on('submit', function (e) { e.preventDefault(); topluEkle(); });

        // Tür değişince placeholder güncelle
        $('#rec_tur').on('change', function () {
            $('#rec_deger').attr('placeholder', $(this).val() === 'tc' ? '12345678901' : '5321234567 / 905321234567');
        });

        document.getElementById('kayitModal').addEventListener('hidden.bs.modal', resetForm);
    });

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-yururlukte').text(r.data.yururlukte);
            $('#stat-pasif').text(r.data.pasif);
            $('#stat-engelleme').text(r.data.engelleme);
            $('#stat-bugun').text(r.data.bugun);
        }, 'json');
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function resetForm() {
        document.getElementById('kayitForm').reset();
        document.getElementById('rec_id').value = '';
        document.getElementById('kayitModalLabel').textContent = 'Kara Listeye Ekle';
        document.getElementById('rec_durum').checked = true;
        $('#rec_tur').val('gsm').trigger('change');
    }

    function saveRecord() {
        const fd = new FormData(document.getElementById('kayitForm'));
        fd.append('action', 'save');
        fd.set('tur', $('#rec_tur').val());

        $.ajax({
            url: '', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showToast(r.message, 'success');
                    kayitModal.hide();
                    dataTable.ajax.reload(null, false);
                    loadStats();
                } else {
                    showToast(r.message, 'error');
                }
            },
            error: function () { showToast('Kayıt sırasında hata oluştu', 'error'); }
        });
    }

    function topluEkle() {
        const fd = new FormData(document.getElementById('topluForm'));
        fd.append('action', 'toplu_ekle');
        fd.set('tur', $('#toplu_tur').val());

        const $btn = $('#topluForm button[type=submit]');
        $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> İşleniyor...');

        $.ajax({
            url: '', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function (r) {
                if (!r.success) { showToast(r.message, 'error'); return; }

                let html = `<div class="alert alert-success mb-0">${escapeHtml(r.message)}</div>`;
                if (r.gecersiz && r.gecersiz.length) {
                    html += `<div class="alert alert-warning mt-2 mb-0"><b>Geçersiz değerler:</b><br>
                             <span class="font-monospace small">${r.gecersiz.map(escapeHtml).join(', ')}</span></div>`;
                }
                $('#topluSonuc').removeClass('d-none').html(html);
                $('#toplu_degerler').val('');
                dataTable.ajax.reload(null, false);
                loadStats();
            },
            error: function () { showToast('Toplu ekleme sırasında hata oluştu', 'error'); },
            complete: function () { $btn.prop('disabled', false).html('<i class="bi bi-upload"></i> Ekle'); }
        });
    }

    function editRecord(id) {
        $.post('', { action: 'get', id: id }, function (r) {
            if (!r.success || !r.data) { showToast(r.message || 'Kayıt bulunamadı', 'error'); return; }
            const d = r.data;
            document.getElementById('rec_id').value        = d.KaraListe_id;
            document.getElementById('rec_deger').value     = d.KaraListe_Deger || '';
            document.getElementById('rec_aciklama').value  = d.KaraListe_Aciklama || '';
            document.getElementById('rec_kaynak').value    = d.KaraListe_Kaynak || '';
            document.getElementById('rec_baslangic').value = d.BaslangicTarihi || '';
            document.getElementById('rec_bitis').value     = d.BitisTarihi || '';
            document.getElementById('rec_durum').checked   = d.Durum == 1;
            $('#rec_tur').val(d.KaraListe_Tur).trigger('change');
            document.getElementById('kayitModalLabel').textContent = 'Kara Liste Kaydını Düzenle';
            kayitModal.show();
        }, 'json');
    }

    function toggleDurum(id, yeniDurum) {
        if (!permissions.canEdit) return;
        $.post('', { action: 'toggle_durum', id: id, durum: yeniDurum }, function (r) {
            if (r.success) {
                showToast(yeniDurum == 1 ? 'Aktif edildi' : 'Pasif edildi', 'success');
                dataTable.ajax.reload(null, false);
                loadStats();
            } else {
                showToast(r.message || 'Durum değiştirilemedi', 'error');
            }
        }, 'json');
    }

    function deleteRecord(id) {
        confirmAction(
            'Bu kaydı kara listeden çıkarmak istediğinize emin misiniz?',
            'Numara tekrar OTP ve başvuru alabilir hale gelir!',
            function () {
                $.post('', { action: 'delete', id: id }, function (r) {
                    if (r.success) {
                        showSuccess('Silindi!', r.message);
                        dataTable.ajax.reload(null, false);
                        loadStats();
                    } else {
                        showError('Hata!', r.message);
                    }
                }, 'json');
            }
        );
    }

    function gecmisGoster(id) {
        $.post('', { action: 'gecmis', id: id }, function (r) {
            if (!r.success) { showToast(r.message || 'Geçmiş alınamadı', 'error'); return; }
            $('#gecmisDeger').text(r.deger);

            const $b = $('#gecmisBody').empty();
            if (!r.data.length) {
                $b.append('<tr><td colspan="5" class="text-center text-muted">Bu değer için engellenmiş deneme yok.</td></tr>');
            } else {
                r.data.forEach(function (x) {
                    $b.append(`<tr>
                        <td class="font-monospace small">${escapeHtml(x.OlusturmaTarihi)}</td>
                        <td><code>${escapeHtml(x.BasvuruLog_ApiEndpoint || '-')}</code></td>
                        <td class="small">${escapeHtml(x.BasvuruLog_Aciklama || '-')}</td>
                        <td class="font-monospace small">${escapeHtml(x.BasvuruLog_IP || '-')}</td>
                        <td>${x.BasvuruLog_Basvuru_id ? escapeHtml(x.BasvuruLog_Basvuru_id) : '<span class="text-muted">-</span>'}</td>
                    </tr>`);
                });
            }
            gecmisModal.show();
        }, 'json');
    }
</script>
</body>
</html>
