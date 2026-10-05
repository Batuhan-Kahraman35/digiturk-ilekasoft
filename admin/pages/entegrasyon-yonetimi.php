<?php
/**
 * Admin Panel - Entegrasyon Yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/EntegrasyonHelper.php';

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

$pageTitle       = $pageinfo['sayfalar_sayfa_adi'] ?? 'Entegrasyon Yönetimi';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok.');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ─── AJAX ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // ── Entegrasyonlar ──────────────────────────────────────────────
            case 'entegrasyon_listele':
                $search = $_POST['search'] ?? '';
                $tip    = $_POST['tip']    ?? '';
                $durum  = $_POST['durum']  ?? '';

                $where  = ['1=1'];
                $params = [];

                if ($search) {
                    $where[]  = "(e.Entegrasyonlar_Adi LIKE ? OR e.Entegrasyonlar_BaseURL LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($tip !== '') {
                    $where[]  = "e.Entegrasyonlar_Tip = ?";
                    $params[] = $tip;
                }
                if ($durum !== '') {
                    $where[]  = "e.Durum = ?";
                    $params[] = (int)$durum;
                }

                $liste = $db->fetchAll("
                    SELECT
                        e.Entegrasyonlar_id,
                        e.Entegrasyonlar_Adi,
                        e.Entegrasyonlar_Tip,
                        e.Entegrasyonlar_BaseURL,
                        e.Entegrasyonlar_Aciklama,
                        e.Durum,
                        CONVERT(VARCHAR(19), e.OlusturmaTarihi, 120) as OlusturmaTarihi,
                        (SELECT COUNT(*) FROM EntegrasyonKanallari k WHERE k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id AND k.Durum = 1) as kanal_sayisi
                    FROM Entegrasyonlar e
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY e.Entegrasyonlar_Adi
                ", $params);
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            case 'entegrasyon_getir':
                $id     = (int)($_POST['id'] ?? 0);
                $kayit  = $db->fetchOne("SELECT * FROM Entegrasyonlar WHERE Entegrasyonlar_id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $kayit]);
                break;

            case 'entegrasyon_kaydet':
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0 && !$permissions['can_edit'])   { echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok.']); break; }
                if ($id == 0 && !$permissions['can_add'])   { echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok.']); break; }

                $data = [
                    'Entegrasyonlar_Adi'      => $_POST['Entegrasyonlar_Adi'] ?? '',
                    'Entegrasyonlar_Tip'      => $_POST['Entegrasyonlar_Tip'] ?? '',
                    'Entegrasyonlar_BaseURL'  => $_POST['Entegrasyonlar_BaseURL'] ?? null,
                    'Entegrasyonlar_ApiKey'   => $_POST['Entegrasyonlar_ApiKey'] ?? null,
                    'Entegrasyonlar_Aciklama' => $_POST['Entegrasyonlar_Aciklama'] ?? null,
                    'Durum'                   => isset($_POST['Durum']) ? 1 : 0,
                ];

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']      = date('Y-m-d H:i:s');
                    $db->update('Entegrasyonlar', $data, ['Entegrasyonlar_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Entegrasyon güncellendi.']);
                } else {
                    $data['OlusturanKullanici'] = $user['kullanici_id'];
                    $data['OlusturmaTarihi']     = date('Y-m-d H:i:s');
                    $newId = $db->insert('Entegrasyonlar', $data);
                    echo json_encode(['success' => true, 'message' => 'Entegrasyon eklendi.', 'id' => $newId]);
                }
                break;

            case 'entegrasyon_sil':
                if (!$permissions['can_delete']) { echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok.']); break; }
                $id = (int)($_POST['id'] ?? 0);
                $kanalSayisi = $db->fetchOne("SELECT COUNT(*) as c FROM EntegrasyonKanallari WHERE EntegrasyonKanallari_Entegrasyon_id = ?", [$id])['c'] ?? 0;
                if ($kanalSayisi > 0) {
                    echo json_encode(['success' => false, 'message' => "Bu entegrasyona bağlı {$kanalSayisi} kanal var. Önce kanalları silin."]);
                    break;
                }
                $db->delete('Entegrasyonlar', ['Entegrasyonlar_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Entegrasyon silindi.']);
                break;

            // ── Kanallar ────────────────────────────────────────────────────
            case 'kanal_listele':
                $search         = $_POST['search']          ?? '';
                $entegrasyonId  = $_POST['entegrasyon_id']  ?? '';
                $durum          = $_POST['durum']           ?? '';

                $where  = ['1=1'];
                $params = [];

                if ($search) {
                    $where[]  = "(k.EntegrasyonKanallari_KanalAdi LIKE ? OR k.EntegrasyonKanallari_Kullanici LIKE ? OR k.EntegrasyonKanallari_Instance LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($entegrasyonId !== '') {
                    $where[]  = "k.EntegrasyonKanallari_Entegrasyon_id = ?";
                    $params[] = (int)$entegrasyonId;
                }
                if ($durum !== '') {
                    $where[]  = "k.Durum = ?";
                    $params[] = (int)$durum;
                }

                $liste = $db->fetchAll("
                    SELECT
                        k.EntegrasyonKanallari_id,
                        k.EntegrasyonKanallari_KanalAdi,
                        k.EntegrasyonKanallari_Instance,
                        k.EntegrasyonKanallari_Host,
                        k.EntegrasyonKanallari_Port,
                        k.EntegrasyonKanallari_Kullanici,
                        k.EntegrasyonKanallari_GondericiAd,
                        k.EntegrasyonKanallari_SifreliBaslanti,
                        k.Durum,
                        e.Entegrasyonlar_Adi,
                        e.Entegrasyonlar_Tip,
                        CONVERT(VARCHAR(19), k.OlusturmaTarihi, 120) as OlusturmaTarihi,
                        (SELECT COUNT(*) FROM EntegrasyonLoglari l WHERE l.EntegrasyonLoglari_Kanal_id = k.EntegrasyonKanallari_id) as log_sayisi
                    FROM EntegrasyonKanallari k
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY e.Entegrasyonlar_Tip, k.EntegrasyonKanallari_KanalAdi
                ", $params);
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            case 'kanal_getir':
                $id    = (int)($_POST['id'] ?? 0);
                $kayit = $db->fetchOne("SELECT * FROM EntegrasyonKanallari WHERE EntegrasyonKanallari_id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $kayit]);
                break;

            case 'kanal_kaydet':
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0 && !$permissions['can_edit'])  { echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok.']); break; }
                if ($id == 0 && !$permissions['can_add'])  { echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok.']); break; }

                $data = [
                    'EntegrasyonKanallari_Entegrasyon_id'  => (int)($_POST['EntegrasyonKanallari_Entegrasyon_id'] ?? 0),
                    'EntegrasyonKanallari_KanalAdi'        => $_POST['EntegrasyonKanallari_KanalAdi'] ?? '',
                    'EntegrasyonKanallari_Instance'        => $_POST['EntegrasyonKanallari_Instance'] ?? null,
                    'EntegrasyonKanallari_Host'            => $_POST['EntegrasyonKanallari_Host'] ?? null,
                    'EntegrasyonKanallari_Port'            => !empty($_POST['EntegrasyonKanallari_Port']) ? (int)$_POST['EntegrasyonKanallari_Port'] : null,
                    'EntegrasyonKanallari_Kullanici'       => $_POST['EntegrasyonKanallari_Kullanici'] ?? null,
                    'EntegrasyonKanallari_GondericiAd'     => $_POST['EntegrasyonKanallari_GondericiAd'] ?? null,
                    'EntegrasyonKanallari_SifreliBaslanti' => isset($_POST['EntegrasyonKanallari_SifreliBaslanti']) ? 1 : 0,
                    'Durum'                                => isset($_POST['Durum']) ? 1 : 0,
                ];

                // OTP (Digiturk) kanalında firma bilgileri Ayarlar JSON'unda tutulur.
                // Eski kolonlar da senkron yazılır (Helper fallback'i bozulmasın diye).
                $entTip = $db->fetchOne(
                    "SELECT Entegrasyonlar_Tip FROM Entegrasyonlar WHERE Entegrasyonlar_id = ?",
                    [$data['EntegrasyonKanallari_Entegrasyon_id']]
                )['Entegrasyonlar_Tip'] ?? '';

                if (strtoupper($entTip) === 'OTP') {
                    $firma = [
                        'companyName' => trim($_POST['firma_companyName'] ?? ''),
                        'companyCode' => trim($_POST['firma_companyCode'] ?? ''),
                        'companyType' => trim($_POST['firma_companyType'] ?? ''),
                        'companyIp'   => trim($_POST['firma_companyIp']   ?? ''),
                    ];
                    $data['EntegrasyonKanallari_Ayarlar']     = json_encode($firma, JSON_UNESCAPED_UNICODE);
                    $data['EntegrasyonKanallari_GondericiAd'] = $firma['companyName'];
                    $data['EntegrasyonKanallari_Kullanici']   = $firma['companyCode'];
                    $data['EntegrasyonKanallari_Instance']    = $firma['companyType'];
                    $data['EntegrasyonKanallari_Host']        = $firma['companyIp'];
                }

                // Şifre alanı boş bırakıldıysa güncelleme sırasında dokunma
                $sifre = $_POST['EntegrasyonKanallari_Sifre'] ?? '';
                if ($id == 0 || $sifre !== '') {
                    $data['EntegrasyonKanallari_Sifre'] = $sifre;
                }

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']      = date('Y-m-d H:i:s');
                    $db->update('EntegrasyonKanallari', $data, ['EntegrasyonKanallari_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Kanal güncellendi.']);
                } else {
                    $data['OlusturanKullanici'] = $user['kullanici_id'];
                    $data['OlusturmaTarihi']     = date('Y-m-d H:i:s');
                    $newId = $db->insert('EntegrasyonKanallari', $data);
                    echo json_encode(['success' => true, 'message' => 'Kanal eklendi.', 'id' => $newId]);
                }
                break;

            case 'kanal_sil':
                if (!$permissions['can_delete']) { echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok.']); break; }
                $id = (int)($_POST['id'] ?? 0);
                $logSayisi = $db->fetchOne("SELECT COUNT(*) as c FROM EntegrasyonLoglari WHERE EntegrasyonLoglari_Kanal_id = ?", [$id])['c'] ?? 0;
                if ($logSayisi > 0) {
                    echo json_encode(['success' => false, 'message' => "Bu kanala ait {$logSayisi} log kaydı var. Silmeden önce logları temizleyin."]);
                    break;
                }
                $db->delete('EntegrasyonKanallari', ['EntegrasyonKanallari_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Kanal silindi.']);
                break;

            // ── Loglar ──────────────────────────────────────────────────────
            case 'log_listele':
                $kanalId  = $_POST['kanal_id']  ?? '';
                $tip      = $_POST['tip']        ?? '';
                $durum    = $_POST['log_durum']  ?? '';
                $tarihBas = $_POST['tarih_bas']  ?? '';
                $tarihBit = $_POST['tarih_bit']  ?? '';

                $where  = ['1=1'];
                $params = [];

                if ($kanalId !== '') {
                    $where[]  = "l.EntegrasyonLoglari_Kanal_id = ?";
                    $params[] = (int)$kanalId;
                }
                if ($tip !== '') {
                    $where[]  = "l.EntegrasyonLoglari_Tip = ?";
                    $params[] = $tip;
                }
                if ($durum !== '') {
                    $where[]  = "l.EntegrasyonLoglari_GonderimDurumu = ?";
                    $params[] = $durum;
                }
                if ($tarihBas !== '') {
                    $where[]  = "l.EntegrasyonLoglari_GonderimTarihi >= ?";
                    $params[] = $tarihBas . ' 00:00:00';
                }
                if ($tarihBit !== '') {
                    $where[]  = "l.EntegrasyonLoglari_GonderimTarihi <= ?";
                    $params[] = $tarihBit . ' 23:59:59';
                }

                $liste = $db->fetchAll("
                    SELECT TOP 500
                        l.EntegrasyonLoglari_id,
                        l.EntegrasyonLoglari_Tip,
                        l.EntegrasyonLoglari_Alici,
                        l.EntegrasyonLoglari_Konu,
                        l.EntegrasyonLoglari_GonderimDurumu,
                        l.EntegrasyonLoglari_HataMesaj,
                        l.EntegrasyonLoglari_CevapVerisi,
                        CONVERT(VARCHAR(19), l.EntegrasyonLoglari_GonderimTarihi, 120) as GonderimTarihi,
                        k.EntegrasyonKanallari_KanalAdi,
                        e.Entegrasyonlar_Adi
                    FROM EntegrasyonLoglari l
                    INNER JOIN EntegrasyonKanallari k ON l.EntegrasyonLoglari_Kanal_id = k.EntegrasyonKanallari_id
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY l.EntegrasyonLoglari_GonderimTarihi DESC
                ", $params);
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            case 'log_detay':
                $id    = (int)($_POST['id'] ?? 0);
                $kayit = $db->fetchOne("SELECT * FROM EntegrasyonLoglari WHERE EntegrasyonLoglari_id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $kayit]);
                break;

            // ── Stats ────────────────────────────────────────────────────────
            case 'stats':
                $stats = [
                    'toplam_entegrasyon' => $db->fetchOne("SELECT COUNT(*) as c FROM Entegrasyonlar WHERE Durum = 1")['c'] ?? 0,
                    'toplam_kanal'       => $db->fetchOne("SELECT COUNT(*) as c FROM EntegrasyonKanallari WHERE Durum = 1")['c'] ?? 0,
                    'bugun_gonderim'     => $db->fetchOne("SELECT COUNT(*) as c FROM EntegrasyonLoglari WHERE CAST(EntegrasyonLoglari_GonderimTarihi AS DATE) = CAST(GETDATE() AS DATE)")['c'] ?? 0,
                    'hata_sayisi'        => $db->fetchOne("SELECT COUNT(*) as c FROM EntegrasyonLoglari WHERE EntegrasyonLoglari_GonderimDurumu = 'hata' AND CAST(EntegrasyonLoglari_GonderimTarihi AS DATE) = CAST(GETDATE() AS DATE)")['c'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            // ── Test Gönder ──────────────────────────────────────────────────
            case 'test_gonder':
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']); break; }
                $kanalId = (int)($_POST['kanal_id'] ?? 0);
                $alici   = trim($_POST['alici'] ?? '');
                $mesaj   = trim($_POST['mesaj'] ?? 'Test mesajı - ' . date('d.m.Y H:i'));

                if (!$kanalId || !$alici) {
                    echo json_encode(['success' => false, 'message' => 'Kanal ve alıcı zorunlu.']);
                    break;
                }

                $kanal = EntegrasyonHelper::kanalGetir($kanalId);
                if (!$kanal) {
                    echo json_encode(['success' => false, 'message' => 'Kanal bulunamadı.']);
                    break;
                }

                if ($kanal['Entegrasyonlar_Tip'] === 'whatsapp') {
                    $sonuc = EntegrasyonHelper::whatsappGonder($kanalId, $alici, $mesaj, $user['kullanici_id']);
                } elseif (strtoupper($kanal['Entegrasyonlar_Tip']) === 'OTP') {
                    // Digiturk: alıcı = GSM, mesaj alanı kullanılmaz; processType seçilir
                    $gsm = preg_replace('/\D/', '', $alici);
                    if (strlen($gsm) < 10) {
                        $sonuc = ['success' => false, 'message' => 'Geçerli bir GSM girin (905xxxxxxxxx).'];
                    } else {
                        $kod = trim($_POST['process_type'] ?? '3');
                        $res = EntegrasyonHelper::digiturkBasvuruGonder($kanalId, $gsm, $kod, [], true, $user['kullanici_id'], 0);
                        $sonuc = [
                            'success' => $res['success'],
                            'message' => ($res['durum'] === 'yeni' ? 'Başvuru oluşturuldu, SMS gönderildi. ' : '')
                                       . ($res['mesaj'] ?? '') . ' [durum: ' . $res['durum'] . ']',
                        ];
                    }
                } elseif ($kanal['Entegrasyonlar_Tip'] === 'voip' || $kanal['Entegrasyonlar_Tip'] === 'callcenter') {
                    $sonuc = ['success' => false, 'message' => 'Bu kanal tipi için test gönderimi desteklenmiyor.'];
                } else {
                    $sonuc = EntegrasyonHelper::emailGonder($kanalId, $alici, 'Test E-postası', $mesaj, false, $user['kullanici_id']);
                }
                echo json_encode($sonuc);
                break;

            // ── Select options ───────────────────────────────────────────────
            case 'entegrasyon_select':
                $liste = $db->fetchAll("SELECT Entegrasyonlar_id, Entegrasyonlar_Adi, Entegrasyonlar_Tip FROM Entegrasyonlar WHERE Durum = 1 ORDER BY Entegrasyonlar_Adi");
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            case 'kanal_select':
                $entId = (int)($_POST['entegrasyon_id'] ?? 0);
                $params = [];
                $kosul  = '';
                if ($entId) {
                    $kosul    = "AND k.EntegrasyonKanallari_Entegrasyon_id = ?";
                    $params[] = $entId;
                }
                $liste = $db->fetchAll("
                    SELECT k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_KanalAdi, e.Entegrasyonlar_Tip
                    FROM EntegrasyonKanallari k
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE k.Durum = 1 AND e.Durum = 1 {$kosul}
                    ORDER BY k.EntegrasyonKanallari_KanalAdi
                ", $params);
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
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
        .badge-whatsapp { background-color: #25D366; color: #fff; }
        .badge-email    { background-color: #EA4335; color: #fff; }
        .badge-voip     { background-color: #6f42c1; color: #fff; }
        .badge-basarili { background-color: #d4edda; color: #155724; }
        .badge-hata     { background-color: #f8d7da; color: #721c24; }
        .badge-bekliyor { background-color: #fff3cd; color: #856404; }
        .sifre-toggle { cursor: pointer; }
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
                            <span class="info-box-icon"><i class="bi bi-plug"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Entegrasyon</span>
                                <span class="info-box-number" id="stat-entegrasyon">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-broadcast"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Kanal</span>
                                <span class="info-box-number" id="stat-kanal">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-send"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün Gönderim</span>
                                <span class="info-box-number" id="stat-bugun">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-exclamation-triangle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün Hata</span>
                                <span class="info-box-number" id="stat-hata">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sekmeler -->
                <ul class="nav nav-tabs mb-3" id="mainTabs">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#tabEntegrasyon">
                            <i class="bi bi-plug"></i> Entegrasyonlar
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#tabKanal">
                            <i class="bi bi-broadcast"></i> Kanallar
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#tabLog">
                            <i class="bi bi-journal-text"></i> Gönderim Logları
                        </a>
                    </li>
                </ul>

                <div class="tab-content">

                    <!-- ─── TAB: Entegrasyonlar ──────────────────────────── -->
                    <div class="tab-pane fade show active" id="tabEntegrasyon">
                        <!-- Filtre -->
                        <div class="card card-primary card-outline mb-3">
                            <div class="card-header">
                                <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                <div class="card-tools">
                                    <button class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterEntegrasyon">
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body collapse" id="filterEntegrasyon">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" id="fe_search" placeholder="Ad veya URL...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Tip</label>
                                        <select class="form-select select2-basic" id="fe_tip">
                                            <option value="">Tümü</option>
                                            <option value="whatsapp">WhatsApp</option>
                                            <option value="email">E-posta</option>
                                            <option value="voip">VoIP</option>
                                            <option value="callcenter">Çağrı Merkezi</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select select2-basic" id="fe_durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-12">
                                        <button class="btn btn-primary" onclick="entegrasyonListele()"><i class="bi bi-search"></i> Filtrele</button>
                                        <button class="btn btn-secondary" onclick="entegrasyonFiltreTemizle()"><i class="bi bi-x-circle"></i> Temizle</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Entegrasyon Listesi</h3>
                                <div class="card-tools">
                                    <?php if ($permissions['can_add']): ?>
                                    <button class="btn btn-primary btn-sm" onclick="entegrasyonModalAc()">
                                        <i class="bi bi-plus-circle"></i> Yeni Entegrasyon
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <table id="tblEntegrasyon" class="table table-bordered table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Ad</th>
                                            <th>Tip</th>
                                            <th>Base URL</th>
                                            <th>Kanal</th>
                                            <th>Durum</th>
                                            <th>Oluşturma</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- ─── TAB: Kanallar ────────────────────────────────── -->
                    <div class="tab-pane fade" id="tabKanal">
                        <!-- Filtre -->
                        <div class="card card-primary card-outline mb-3">
                            <div class="card-header">
                                <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                <div class="card-tools">
                                    <button class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterKanal">
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body collapse" id="filterKanal">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Ara</label>
                                        <input type="text" class="form-control" id="fk_search" placeholder="Kanal adı, kullanıcı, instance...">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Entegrasyon</label>
                                        <select class="form-select select2-basic" id="fk_entegrasyon">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select select2-basic" id="fk_durum">
                                            <option value="">Tümü</option>
                                            <option value="1">Aktif</option>
                                            <option value="0">Pasif</option>
                                        </select>
                                    </div>
                                    <div class="col-md-12">
                                        <button class="btn btn-primary" onclick="kanalListele()"><i class="bi bi-search"></i> Filtrele</button>
                                        <button class="btn btn-secondary" onclick="kanalFiltreTemizle()"><i class="bi bi-x-circle"></i> Temizle</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Kanal Listesi</h3>
                                <div class="card-tools">
                                    <?php if ($permissions['can_add']): ?>
                                    <button class="btn btn-primary btn-sm" onclick="kanalModalAc()">
                                        <i class="bi bi-plus-circle"></i> Yeni Kanal
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <table id="tblKanal" class="table table-bordered table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Kanal Adı</th>
                                            <th>Entegrasyon</th>
                                            <th>Instance / Host</th>
                                            <th>Kullanıcı</th>
                                            <th>Log</th>
                                            <th>Durum</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- ─── TAB: Loglar ──────────────────────────────────── -->
                    <div class="tab-pane fade" id="tabLog">
                        <!-- Filtre -->
                        <div class="card card-primary card-outline mb-3">
                            <div class="card-header">
                                <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                <div class="card-tools">
                                    <button class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterLog">
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body collapse" id="filterLog">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label">Kanal</label>
                                        <select class="form-select select2-basic" id="fl_kanal">
                                            <option value="">Tümü</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Tip</label>
                                        <select class="form-select select2-basic" id="fl_tip">
                                            <option value="">Tümü</option>
                                            <option value="whatsapp">WhatsApp</option>
                                            <option value="whatsapp_durum">WhatsApp Bağlantı Durumu</option>
                                            <option value="email">E-posta</option>
                                            <option value="voip">VoIP</option>
                                            <option value="callcenter">Çağrı Merkezi</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Durum</label>
                                        <select class="form-select select2-basic" id="fl_durum">
                                            <option value="">Tümü</option>
                                            <option value="basarili">Başarılı</option>
                                            <option value="hata">Hata</option>
                                            <option value="bekliyor">Bekliyor</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Başlangıç</label>
                                        <input type="date" class="form-control" id="fl_tarih_bas">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Bitiş</label>
                                        <input type="date" class="form-control" id="fl_tarih_bit">
                                    </div>
                                    <div class="col-md-12">
                                        <button class="btn btn-primary" onclick="logListele()"><i class="bi bi-search"></i> Filtrele</button>
                                        <button class="btn btn-secondary" onclick="logFiltreTemizle()"><i class="bi bi-x-circle"></i> Temizle</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Gönderim Logları <small class="text-muted">(son 500 kayıt)</small></h3>
                            </div>
                            <div class="card-body">
                                <table id="tblLog" class="table table-bordered table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Tarih</th>
                                            <th>Tip</th>
                                            <th>Kanal</th>
                                            <th>Alıcı</th>
                                            <th>Konu</th>
                                            <th>Durum</th>
                                            <th>Detay</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div><!-- /.tab-content -->
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     MODAL: Entegrasyon
════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalEntegrasyon" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEntegrasyonBaslik">Yeni Entegrasyon</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formEntegrasyon">
                <div class="modal-body">
                    <input type="hidden" id="e_id" name="id">

                    <div class="mb-3">
                        <label class="form-label">Entegrasyon Adı <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="e_adi" name="Entegrasyonlar_Adi" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tip <span class="text-danger">*</span></label>
                        <select class="form-select select2-modal" id="e_tip" name="Entegrasyonlar_Tip" required>
                            <option value="">Seçin...</option>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="email">E-posta</option>
                            <option value="voip">VoIP</option>
                            <option value="callcenter">Çağrı Merkezi</option>
                            <option value="banka">Banka (Portal API)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Base URL</label>
                        <input type="text" class="form-control" id="e_baseurl" name="Entegrasyonlar_BaseURL" placeholder="https://...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">API Key / Global Key</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="e_apikey" name="Entegrasyonlar_ApiKey">
                            <button type="button" class="btn btn-outline-secondary sifre-toggle" data-target="e_apikey">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Açıklama</label>
                        <textarea class="form-control" id="e_aciklama" name="Entegrasyonlar_Aciklama" rows="2"></textarea>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="e_durum" name="Durum" checked>
                        <label class="form-check-label" for="e_durum">Aktif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     MODAL: Kanal
════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalKanal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalKanalBaslik">Yeni Kanal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formKanal">
                <div class="modal-body">
                    <input type="hidden" id="k_id" name="id">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Entegrasyon <span class="text-danger">*</span></label>
                            <select class="form-select select2-modal" id="k_entegrasyon" name="EntegrasyonKanallari_Entegrasyon_id" required>
                                <option value="">Seçin...</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Kanal Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="k_kanal_adi" name="EntegrasyonKanallari_KanalAdi" required>
                        </div>
                    </div>

                    <!-- WhatsApp / VoIP ortak alan (instance / hesap yolu) -->
                    <div id="k_whatsapp_alanlari">
                        <div class="mb-3">
                            <label class="form-label k_instance_label">Instance Adı</label>
                            <input type="text" class="form-control" id="k_instance" name="EntegrasyonKanallari_Instance" placeholder="Örn: BatuhanIS">
                            <div class="form-text k_instance_hint"></div>
                        </div>
                    </div>

                    <!-- Email alanları -->
                    <div id="k_email_alanlari" style="display:none">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label k_host_label">SMTP Host</label>
                                <input type="text" class="form-control" id="k_host" name="EntegrasyonKanallari_Host" placeholder="smtp.gmail.com">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Port</label>
                                <input type="number" class="form-control" id="k_port" name="EntegrasyonKanallari_Port" placeholder="465">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label k_kullanici_label">Kullanıcı (E-posta)</label>
                                <input type="text" class="form-control" id="k_kullanici" name="EntegrasyonKanallari_Kullanici">
                            </div>
                            <div class="col-md-6 mb-3 email-only-field">
                                <label class="form-label">Gönderici Adı</label>
                                <input type="text" class="form-control" id="k_gonderici_ad" name="EntegrasyonKanallari_GondericiAd">
                            </div>
                        </div>
                        <div class="mb-3 email-only-field">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="k_sifreli" name="EntegrasyonKanallari_SifreliBaslanti" checked>
                                <label class="form-check-label" for="k_sifreli">SSL/TLS (465)</label>
                            </div>
                        </div>
                    </div>

                    <!-- OTP (Digiturk) firma alanları -->
                    <div id="k_otp_alanlari" style="display:none">
                        <div class="alert alert-info py-2">
                            <i class="bi bi-info-circle"></i>
                            Bu değerler Digiturk isteğindeki <code>company</code> bloğunu oluşturur.
                            Firma kodu Digiturk tarafında değişirse burada güncellenmelidir.
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Firma Adı <span class="text-muted">(companyName)</span></label>
                                <input type="text" class="form-control" id="k_firma_ad" name="firma_companyName" placeholder="Ornek">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Firma Kodu <span class="text-muted">(companyCode)</span></label>
                                <input type="text" class="form-control" id="k_firma_kod" name="firma_companyCode" placeholder="10000001">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Firma Tipi <span class="text-muted">(companyType)</span></label>
                                <input type="text" class="form-control" id="k_firma_tip" name="firma_companyType" placeholder="online">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Firma IP <span class="text-muted">(companyIp)</span></label>
                                <input type="text" class="form-control" id="k_firma_ip" name="firma_companyIp" placeholder="10.0.0.11">
                                <div class="form-text">Sunucunun Digiturk'e çıkış yaptığı, whitelist'e tanımlı sabit IP.</div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3" id="k_sifre_alani">
                        <label class="form-label">Şifre / App Password <span class="text-muted" id="k_sifre_ipucu"></span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="k_sifre" name="EntegrasyonKanallari_Sifre">
                            <button type="button" class="btn btn-outline-secondary sifre-toggle" data-target="k_sifre">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="k_durum" name="Durum" checked>
                        <label class="form-check-label" for="k_durum">Aktif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-outline-info" onclick="testModalAc()"><i class="bi bi-send"></i> Test Gönder</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     MODAL: Log Detay
════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalLogDetay" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Log Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="logDetayIcerik">
                <div class="text-center py-3"><div class="spinner-border text-primary"></div></div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     MODAL: Test Gönder
════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalTest" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Test Mesajı Gönder</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="test_kanal_id">
                <div class="alert alert-warning py-2" id="test_otp_uyari" style="display:none">
                    <i class="bi bi-exclamation-triangle"></i>
                    Bu test <strong>gerçek başvuru oluşturur ve müşteriye SMS gönderir</strong>. Kendi numaranızla deneyin.
                </div>
                <div class="mb-3">
                    <label class="form-label" id="test_alici_label">Alıcı <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="test_alici" placeholder="Telefon (905xx...) veya e-posta">
                </div>
                <div class="mb-3" id="test_process_alani" style="display:none">
                    <label class="form-label">İşlem Tipi (processType)</label>
                    <select class="form-select" id="test_process_type">
                        <?php
                        $otpTipleri = $db->fetchAll("
                            SELECT t.EntegrasyonKanalTipleri_Kod, t.EntegrasyonKanalTipleri_Ad
                            FROM EntegrasyonKanalTipleri t
                            INNER JOIN Entegrasyonlar e ON t.EntegrasyonKanalTipleri_Entegrasyon_id = e.Entegrasyonlar_id
                            WHERE e.Entegrasyonlar_Tip = 'OTP' AND e.Durum = 1 AND t.Durum = 1
                            ORDER BY t.EntegrasyonKanalTipleri_Kod
                        ");
                        foreach ($otpTipleri as $kt): ?>
                            <option value="<?= htmlspecialchars($kt['EntegrasyonKanalTipleri_Kod']) ?>" <?= $kt['EntegrasyonKanalTipleri_Kod'] === '3' ? 'selected' : '' ?>>
                                <?= htmlspecialchars($kt['EntegrasyonKanalTipleri_Kod'] . ' - ' . $kt['EntegrasyonKanalTipleri_Ad']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3" id="test_mesaj_alani">
                    <label class="form-label">Mesaj</label>
                    <textarea class="form-control" id="test_mesaj" rows="3" placeholder="Test mesajı içeriği..."></textarea>
                </div>
                <div id="test_sonuc"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                <button type="button" class="btn btn-primary" onclick="testGonder()"><i class="bi bi-send"></i> Gönder</button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script>
// Sidebar kapalı durumunu hatırla (bu sayfa custom.js yüklemiyor)
(function () {
    var KEY = 'sidebarCollapsed', BP = 992;
    function uygula() {
        if (window.innerWidth > BP && localStorage.getItem(KEY) === '1') {
            document.body.classList.add('sidebar-collapse');
            document.body.classList.remove('sidebar-open');
        }
    }
    function init() {
        try { uygula(); } catch (e) {}
        var rzt;
        window.addEventListener('resize', function () {
            clearTimeout(rzt);
            rzt = setTimeout(function () { try { uygula(); } catch (e) {} }, 60);
        });
        document.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('[data-lte-toggle="sidebar"]')) {
                try { localStorage.setItem(KEY, document.body.classList.contains('sidebar-collapse') ? '1' : '0'); } catch (er) {}
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const pageUrl = '<?= $_SERVER['PHP_SELF'] ?>';
let dtEntegrasyon, dtKanal, dtLog;
let entegrasyonListData = [];

// ── Başlangıç ──────────────────────────────────────────────────────────────
$(document).ready(function () {
    initSelect2();
    statsYukle();
    entegrasyonSelectDoldur();
    kanalSelectDoldur();
    entegrasyonListele();
    kanalListele();

    // Tab değişince tabloyu yeniden çiz
    $('a[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        const target = $(e.target).attr('href');
        if (target === '#tabLog' && !dtLog) { logListele(); }
        if (dtEntegrasyon) dtEntegrasyon.columns.adjust();
        if (dtKanal)       dtKanal.columns.adjust();
        if (dtLog)         dtLog.columns.adjust();
    });

    // Şifre göster/gizle
    $(document).on('click', '.sifre-toggle', function () {
        const targetId = $(this).data('target');
        const input    = $('#' + targetId);
        const isPass   = input.attr('type') === 'password';
        input.attr('type', isPass ? 'text' : 'password');
        $(this).find('i').toggleClass('bi-eye bi-eye-slash');
    });

    // Entegrasyon tipi değişince kanal form alanlarını güncelle
    $('#k_entegrasyon').on('change', function () {
        const selected = $(this).find(':selected');
        const tip = selected.data('tip') || '';
        kanalFormTipGuncelle(tip);
    });

    // Form submit
    $('#formEntegrasyon').on('submit', function (e) { e.preventDefault(); entegrasyonKaydet(); });
    $('#formKanal').on('submit',       function (e) { e.preventDefault(); kanalKaydet(); });
});

// ── Yardımcılar ────────────────────────────────────────────────────────────
function initSelect2() {
    $('.select2-basic').select2({ theme: 'bootstrap-5', width: '100%' });
}

function select2Modal(selector, modalSelector) {
    // Çift init'e karşı önce destroy (modal her açılışta yeniden kurulur)
    if ($(selector).hasClass('select2-hidden-accessible')) $(selector).select2('destroy');
    $(selector).select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $(modalSelector) });
}

function statsYukle() {
    $.post(pageUrl, { action: 'stats' }, function (r) {
        if (!r.success) return;
        $('#stat-entegrasyon').text(r.data.toplam_entegrasyon);
        $('#stat-kanal').text(r.data.toplam_kanal);
        $('#stat-bugun').text(r.data.bugun_gonderim);
        $('#stat-hata').text(r.data.hata_sayisi);
    });
}

function entegrasyonSelectDoldur() {
    $.post(pageUrl, { action: 'entegrasyon_select' }, function (r) {
        if (!r.success) return;
        entegrasyonListData = r.data;
        const $fe = $('#fk_entegrasyon'), $ke = $('#k_entegrasyon');
        $fe.find('option:not(:first)').remove();
        $ke.find('option:not(:first)').remove();
        r.data.forEach(function (e) {
            $fe.append(`<option value="${e.Entegrasyonlar_id}">${e.Entegrasyonlar_Adi}</option>`);
            $ke.append(`<option value="${e.Entegrasyonlar_id}" data-tip="${e.Entegrasyonlar_Tip}">${e.Entegrasyonlar_Adi} (${tipBadge(e.Entegrasyonlar_Tip, true)})</option>`);
        });
    });
}

function kanalSelectDoldur() {
    $.post(pageUrl, { action: 'kanal_select' }, function (r) {
        if (!r.success) return;
        const $fl = $('#fl_kanal');
        $fl.find('option:not(:first)').remove();
        r.data.forEach(function (k) {
            $fl.append(`<option value="${k.EntegrasyonKanallari_id}">${k.EntegrasyonKanallari_KanalAdi}</option>`);
        });
    });
}

function tipBadge(tip, textOnly) {
    if (textOnly) {
        if (tip === 'whatsapp_durum') return 'WhatsApp Bağlantı';
        if (tip === 'whatsapp')   return 'WhatsApp';
        if (tip === 'voip')       return 'VoIP';
        if (tip === 'callcenter') return 'Çağrı Merkezi';
        if (tip === 'banka')      return 'Banka';
        return 'E-posta';
    }
    if (tip === 'whatsapp_durum') return '<span class="badge text-bg-dark"><i class="bi bi-plug"></i> WhatsApp Bağlantı</span>';
    if (tip === 'whatsapp')   return '<span class="badge badge-whatsapp"><i class="bi bi-whatsapp"></i> WhatsApp</span>';
    if (tip === 'voip')       return '<span class="badge badge-voip"><i class="bi bi-telephone"></i> VoIP</span>';
    if (tip === 'callcenter') return '<span class="badge text-bg-info"><i class="bi bi-headset"></i> Çağrı Merkezi</span>';
    if (tip === 'banka')      return '<span class="badge text-bg-primary"><i class="bi bi-bank"></i> Banka</span>';
    return '<span class="badge badge-email"><i class="bi bi-envelope"></i> E-posta</span>';
}

function durumBadge(durum) {
    return durum ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-danger">Pasif</span>';
}

function logDurumBadge(durum) {
    const map = { basarili: 'badge-basarili', hata: 'badge-hata', bekliyor: 'badge-bekliyor' };
    const label = { basarili: 'Başarılı', hata: 'Hata', bekliyor: 'Bekliyor' };
    return `<span class="badge ${map[durum] || 'badge-bekliyor'}">${label[durum] || durum}</span>`;
}

function kanalFormTipGuncelle(tip) {
    // OTP dışındaki tiplerde firma bloğu ve şifre alanı varsayılan durumuna döner
    const otpMu = String(tip).toLowerCase() === 'otp';
    $('#k_otp_alanlari').toggle(otpMu);
    $('#k_sifre_alani').toggle(!otpMu);

    if (otpMu) {
        $('#k_whatsapp_alanlari').hide();
        $('#k_email_alanlari').hide();
        return;
    }

    if (tip === 'email') {
        $('#k_whatsapp_alanlari').hide();
        $('#k_email_alanlari').show();
        $('.email-only-field').show();
        $('#k_host').attr('placeholder', 'smtp.gmail.com');
        $('#k_port').attr('placeholder', '465');
        $('.k_host_label').text('SMTP Host');
        $('.k_kullanici_label').text('Kullanıcı (E-posta)');
        $('#k_sifre_ipucu').text('(Gmail App Password)');
    } else if (tip === 'voip') {
        $('#k_whatsapp_alanlari').show();
        $('#k_email_alanlari').show();
        $('.email-only-field').hide();
        $('#k_host').attr('placeholder', 'sipreg2.example.com');
        $('#k_port').attr('placeholder', '5060');
        $('.k_host_label').text('SIP Sunucu (Host)');
        $('.k_kullanici_label').text('Kullanıcı Adı');
        $('.k_instance_label').text('Hesap Yolu');
        $('.k_instance_hint').text('Örn: c51 — URL\'deki /c51/ kısmı, her hesaba özeldir.');
        $('#k_instance').attr('placeholder', 'c51');
        $('#k_sifre_ipucu').text('(Panel Şifresi)');
    } else if (tip === 'callcenter') {
        $('#k_whatsapp_alanlari').hide();
        $('#k_email_alanlari').show();
        $('.email-only-field').hide();
        $('.k_host_label').text('Sunucu (Host)');
        $('#k_host').attr('placeholder', 'callcenter.example.com');
        $('#k_port').attr('placeholder', '443');
        $('.k_kullanici_label').text('Kullanıcı / E-posta');
        $('#k_sifre_ipucu').text('(Panel Şifresi)');
    } else {
        $('#k_whatsapp_alanlari').show();
        $('#k_email_alanlari').hide();
        $('.email-only-field').show();
        $('.k_instance_label').text('Instance Adı');
        $('.k_instance_hint').text('');
        $('#k_instance').attr('placeholder', 'Örn: BatuhanIS');
        $('#k_sifre_ipucu').text('');
    }
}

// ── Entegrasyon CRUD ────────────────────────────────────────────────────────
function entegrasyonListele() {
    const data = {
        action : 'entegrasyon_listele',
        search : $('#fe_search').val(),
        tip    : $('#fe_tip').val(),
        durum  : $('#fe_durum').val(),
    };
    $.post(pageUrl, data, function (r) {
        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }

        if (dtEntegrasyon) dtEntegrasyon.destroy();
        const $tbody = $('#tblEntegrasyon tbody').empty();

        r.data.forEach(function (e) {
            const editBtn =
                (<?= $permissions['can_edit']   ? 'true' : 'false' ?> ? `<button class="btn btn-xs btn-warning me-1" onclick='entegrasyonDuzenle(${e.Entegrasyonlar_id})'><i class="bi bi-pencil"></i></button>` : '') +
                (<?= $permissions['can_delete'] ? 'true' : 'false' ?> ? `<button class="btn btn-xs btn-danger" onclick='entegrasyonSil(${e.Entegrasyonlar_id},"${htmlEncode(e.Entegrasyonlar_Adi)}")'><i class="bi bi-trash"></i></button>` : '');

            $tbody.append(`<tr>
                <td>${htmlEncode(e.Entegrasyonlar_Adi)}</td>
                <td>${tipBadge(e.Entegrasyonlar_Tip)}</td>
                <td><small>${htmlEncode(e.Entegrasyonlar_BaseURL || '-')}</small></td>
                <td><span class="badge text-bg-info">${e.kanal_sayisi}</span></td>
                <td>${durumBadge(e.Durum)}</td>
                <td><small>${e.OlusturmaTarihi || ''}</small></td>
                <td>${editBtn}</td>
            </tr>`);
        });

        dtEntegrasyon = $('#tblEntegrasyon').DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc']], pageLength: 25, destroy: true
        });
    });
}

function entegrasyonFiltreTemizle() {
    $('#fe_search').val('');
    $('#fe_tip, #fe_durum').val('').trigger('change');
    entegrasyonListele();
}

function entegrasyonModalAc(id) {
    $('#formEntegrasyon')[0].reset();
    $('#e_id').val('');
    $('#e_durum').prop('checked', true);
    $('#modalEntegrasyonBaslik').text('Yeni Entegrasyon');
    select2Modal('#e_tip', '#modalEntegrasyon');
    $('#modalEntegrasyon').modal('show');
}

function entegrasyonDuzenle(id) {
    $.post(pageUrl, { action: 'entegrasyon_getir', id }, function (r) {
        if (!r.success || !r.data) return;
        const e = r.data;
        $('#e_id').val(e.Entegrasyonlar_id);
        $('#e_adi').val(e.Entegrasyonlar_Adi);
        $('#e_tip').val(e.Entegrasyonlar_Tip).trigger('change');
        $('#e_baseurl').val(e.Entegrasyonlar_BaseURL);
        $('#e_apikey').val(e.Entegrasyonlar_ApiKey);
        $('#e_aciklama').val(e.Entegrasyonlar_Aciklama);
        $('#e_durum').prop('checked', e.Durum == 1);
        $('#modalEntegrasyonBaslik').text('Entegrasyon Düzenle');
        select2Modal('#e_tip', '#modalEntegrasyon');
        $('#modalEntegrasyon').modal('show');
    });
}

function entegrasyonKaydet() {
    const data = $('#formEntegrasyon').serialize() + '&action=entegrasyon_kaydet';
    $.post(pageUrl, data, function (r) {
        if (r.success) {
            $('#modalEntegrasyon').modal('hide');
            Swal.fire('Başarılı', r.message, 'success');
            entegrasyonListele();
            entegrasyonSelectDoldur();
            statsYukle();
        } else {
            Swal.fire('Hata', r.message, 'error');
        }
    });
}

function entegrasyonSil(id, adi) {
    Swal.fire({
        title: 'Silmek istiyor musunuz?',
        text: `"${adi}" entegrasyonu silinecek.`,
        icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Evet, Sil', cancelButtonText: 'İptal',
        confirmButtonColor: '#dc3545'
    }).then(function (result) {
        if (!result.isConfirmed) return;
        $.post(pageUrl, { action: 'entegrasyon_sil', id }, function (r) {
            if (r.success) {
                Swal.fire('Silindi', r.message, 'success');
                entegrasyonListele();
                entegrasyonSelectDoldur();
                statsYukle();
            } else {
                Swal.fire('Hata', r.message, 'error');
            }
        });
    });
}

// ── Kanal CRUD ──────────────────────────────────────────────────────────────
function kanalListele() {
    const data = {
        action         : 'kanal_listele',
        search         : $('#fk_search').val(),
        entegrasyon_id : $('#fk_entegrasyon').val(),
        durum          : $('#fk_durum').val(),
    };
    $.post(pageUrl, data, function (r) {
        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }

        if (dtKanal) dtKanal.destroy();
        const $tbody = $('#tblKanal tbody').empty();

        r.data.forEach(function (k) {
            const instanceHost = k.Entegrasyonlar_Tip === 'whatsapp'
                ? htmlEncode(k.EntegrasyonKanallari_Instance || '-')
                : `${htmlEncode(k.EntegrasyonKanallari_Host || '-')}:${k.EntegrasyonKanallari_Port || ''}`;

            const editBtn   = <?= $permissions['can_edit']   ? 'true' : 'false' ?> ? `<button class="btn btn-xs btn-warning me-1" onclick='kanalDuzenle(${k.EntegrasyonKanallari_id})'><i class="bi bi-pencil"></i></button>` : '';
            const deleteBtn = <?= $permissions['can_delete'] ? 'true' : 'false' ?> ? `<button class="btn btn-xs btn-danger me-1" onclick='kanalSil(${k.EntegrasyonKanallari_id},"${htmlEncode(k.EntegrasyonKanallari_KanalAdi)}")'><i class="bi bi-trash"></i></button>` : '';
            const testBtn   = <?= $permissions['can_edit']   ? 'true' : 'false' ?> ? `<button class="btn btn-xs btn-info" onclick='testKanalAc(${k.EntegrasyonKanallari_id},"${k.Entegrasyonlar_Tip}")'><i class="bi bi-send"></i></button>` : '';

            $tbody.append(`<tr>
                <td><strong>${htmlEncode(k.EntegrasyonKanallari_KanalAdi)}</strong></td>
                <td>${tipBadge(k.Entegrasyonlar_Tip)}<br><small>${htmlEncode(k.Entegrasyonlar_Adi)}</small></td>
                <td><small>${instanceHost}</small></td>
                <td><small>${htmlEncode(k.EntegrasyonKanallari_Kullanici || k.EntegrasyonKanallari_Instance || '-')}</small></td>
                <td><span class="badge text-bg-secondary">${k.log_sayisi}</span></td>
                <td>${durumBadge(k.Durum)}</td>
                <td>${editBtn}${deleteBtn}${testBtn}</td>
            </tr>`);
        });

        dtKanal = $('#tblKanal').DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc']], pageLength: 25, destroy: true
        });
    });
}

function kanalFiltreTemizle() {
    $('#fk_search').val('');
    $('#fk_entegrasyon, #fk_durum').val('').trigger('change');
    kanalListele();
}

function kanalModalAc() {
    $('#formKanal')[0].reset();
    $('#k_id').val('');
    $('#k_durum, #k_sifreli').prop('checked', true);
    $('#modalKanalBaslik').text('Yeni Kanal');
    $('#k_sifre_ipucu').text('');
    kanalFormTipGuncelle('whatsapp');
    select2Modal('#k_entegrasyon', '#modalKanal');
    $('#modalKanal').modal('show');
}

function kanalDuzenle(id) {
    $.post(pageUrl, { action: 'kanal_getir', id }, function (r) {
        if (!r.success || !r.data) return;
        const k = r.data;

        // Entegrasyon tipini bul
        const entObj = entegrasyonListData.find(e => e.Entegrasyonlar_id == k.EntegrasyonKanallari_Entegrasyon_id);
        const tip = entObj ? entObj.Entegrasyonlar_Tip : '';

        $('#k_id').val(k.EntegrasyonKanallari_id);
        $('#k_entegrasyon').val(k.EntegrasyonKanallari_Entegrasyon_id).trigger('change');
        $('#k_kanal_adi').val(k.EntegrasyonKanallari_KanalAdi);
        $('#k_instance').val(k.EntegrasyonKanallari_Instance);
        $('#k_host').val(k.EntegrasyonKanallari_Host);
        $('#k_port').val(k.EntegrasyonKanallari_Port);
        $('#k_kullanici').val(k.EntegrasyonKanallari_Kullanici);
        $('#k_gonderici_ad').val(k.EntegrasyonKanallari_GondericiAd);
        $('#k_sifreli').prop('checked', k.EntegrasyonKanallari_SifreliBaslanti == 1);
        $('#k_sifre').val('');

        // OTP firma bilgileri: Ayarlar JSON'undan; alan boşsa eski kolona düşer
        let ayar = {};
        try { ayar = JSON.parse(k.EntegrasyonKanallari_Ayarlar || '{}') || {}; } catch (e) { ayar = {}; }
        $('#k_firma_ad').val(ayar.companyName || k.EntegrasyonKanallari_GondericiAd || '');
        $('#k_firma_kod').val(ayar.companyCode || k.EntegrasyonKanallari_Kullanici   || '');
        $('#k_firma_tip').val(ayar.companyType || k.EntegrasyonKanallari_Instance    || '');
        $('#k_firma_ip').val(ayar.companyIp    || k.EntegrasyonKanallari_Host        || '');

        $('#k_durum').prop('checked', k.Durum == 1);
        $('#modalKanalBaslik').text('Kanal Düzenle');

        kanalFormTipGuncelle(tip);
        if (k.EntegrasyonKanallari_Sifre) {
            $('#k_sifre_ipucu').text('(boş bırakırsanız değişmez)');
        }

        select2Modal('#k_entegrasyon', '#modalKanal');
        $('#modalKanal').modal('show');
    });
}

function kanalKaydet() {
    const data = $('#formKanal').serialize() + '&action=kanal_kaydet';
    $.post(pageUrl, data, function (r) {
        if (r.success) {
            $('#modalKanal').modal('hide');
            Swal.fire('Başarılı', r.message, 'success');
            kanalListele();
            kanalSelectDoldur();
            statsYukle();
        } else {
            Swal.fire('Hata', r.message, 'error');
        }
    });
}

function kanalSil(id, adi) {
    Swal.fire({
        title: 'Silmek istiyor musunuz?',
        text: `"${adi}" kanalı silinecek.`,
        icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Evet, Sil', cancelButtonText: 'İptal',
        confirmButtonColor: '#dc3545'
    }).then(function (result) {
        if (!result.isConfirmed) return;
        $.post(pageUrl, { action: 'kanal_sil', id }, function (r) {
            if (r.success) {
                Swal.fire('Silindi', r.message, 'success');
                kanalListele();
                kanalSelectDoldur();
                statsYukle();
            } else {
                Swal.fire('Hata', r.message, 'error');
            }
        });
    });
}

// ── Log ─────────────────────────────────────────────────────────────────────
function logListele() {
    const data = {
        action    : 'log_listele',
        kanal_id  : $('#fl_kanal').val(),
        tip       : $('#fl_tip').val(),
        log_durum : $('#fl_durum').val(),
        tarih_bas : $('#fl_tarih_bas').val(),
        tarih_bit : $('#fl_tarih_bit').val(),
    };
    $.post(pageUrl, data, function (r) {
        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }

        if (dtLog) dtLog.destroy();
        const $tbody = $('#tblLog tbody').empty();

        r.data.forEach(function (l) {
            $tbody.append(`<tr>
                <td><small>${l.GonderimTarihi || ''}</small></td>
                <td>${tipBadge(l.EntegrasyonLoglari_Tip)}</td>
                <td><small>${htmlEncode(l.EntegrasyonKanallari_KanalAdi)}</small></td>
                <td><small>${htmlEncode(l.EntegrasyonLoglari_Alici)}</small></td>
                <td><small>${htmlEncode(l.EntegrasyonLoglari_Konu || '-')}</small></td>
                <td>${logDurumBadge(l.EntegrasyonLoglari_GonderimDurumu)}</td>
                <td><button class="btn btn-xs btn-outline-secondary" onclick='logDetay(${l.EntegrasyonLoglari_id})'><i class="bi bi-eye"></i></button></td>
            </tr>`);
        });

        dtLog = $('#tblLog').DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'desc']], pageLength: 50, destroy: true
        });
    });
}

function logFiltreTemizle() {
    $('#fl_kanal, #fl_tip, #fl_durum').val('').trigger('change');
    $('#fl_tarih_bas, #fl_tarih_bit').val('');
    logListele();
}

function logDetay(id) {
    $('#logDetayIcerik').html('<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>');
    $('#modalLogDetay').modal('show');
    $.post(pageUrl, { action: 'log_detay', id }, function (r) {
        if (!r.success || !r.data) { $('#logDetayIcerik').html('<p class="text-danger">Veri bulunamadı.</p>'); return; }
        const l = r.data;
        let html = `
            <table class="table table-sm table-bordered">
                <tr><th>ID</th><td>${l.EntegrasyonLoglari_id}</td></tr>
                <tr><th>Tip</th><td>${tipBadge(l.EntegrasyonLoglari_Tip)}</td></tr>
                <tr><th>Alıcı</th><td>${htmlEncode(l.EntegrasyonLoglari_Alici)}</td></tr>
                <tr><th>Konu</th><td>${htmlEncode(l.EntegrasyonLoglari_Konu || '-')}</td></tr>
                <tr><th>Durum</th><td>${logDurumBadge(l.EntegrasyonLoglari_GonderimDurumu)}</td></tr>
                <tr><th>Tarih</th><td>${l.EntegrasyonLoglari_GonderimTarihi || ''}</td></tr>
            </table>`;

        if (l.EntegrasyonLoglari_Mesaj) {
            html += `<div class="mb-2"><strong>Mesaj:</strong><pre class="bg-light p-2 rounded" style="max-height:150px;overflow:auto">${htmlEncode(l.EntegrasyonLoglari_Mesaj)}</pre></div>`;
        }
        if (l.EntegrasyonLoglari_HataMesaj) {
            html += `<div class="mb-2"><strong>Hata:</strong><pre class="bg-light p-2 rounded text-danger" style="max-height:100px;overflow:auto">${htmlEncode(l.EntegrasyonLoglari_HataMesaj)}</pre></div>`;
        }
        if (l.EntegrasyonLoglari_IstekVerisi) {
            html += `<div class="mb-2"><strong>İstek:</strong><pre class="bg-light p-2 rounded" style="max-height:100px;overflow:auto">${htmlEncode(l.EntegrasyonLoglari_IstekVerisi)}</pre></div>`;
        }
        if (l.EntegrasyonLoglari_CevapVerisi) {
            html += `<div class="mb-2"><strong>Cevap:</strong><pre class="bg-light p-2 rounded" style="max-height:100px;overflow:auto">${htmlEncode(l.EntegrasyonLoglari_CevapVerisi)}</pre></div>`;
        }

        $('#logDetayIcerik').html(html);
    });
}

// ── Test Gönder ─────────────────────────────────────────────────────────────
function testModalAc() {
    const kanalId = $('#k_id').val();
    if (!kanalId) { Swal.fire('Uyarı', 'Önce kanalı kaydedin.', 'warning'); return; }
    testKanalAc(kanalId, $('#k_entegrasyon').find(':selected').data('tip') || '');
}

function testKanalAc(kanalId, tip) {
    const otpMu = String(tip || '').toLowerCase() === 'otp';

    $('#test_kanal_id').val(kanalId);
    $('#test_alici, #test_mesaj').val('');
    $('#test_sonuc').html('');

    // OTP'de mesaj yerine GSM + işlem tipi sorulur
    $('#test_otp_uyari').toggle(otpMu);
    $('#test_process_alani').toggle(otpMu);
    $('#test_mesaj_alani').toggle(!otpMu);
    $('#test_alici_label').html(otpMu
        ? 'GSM <span class="text-danger">*</span>'
        : 'Alıcı <span class="text-danger">*</span>');
    $('#test_alici').attr('placeholder', otpMu
        ? '905xxxxxxxxx'
        : 'Telefon (905xx...) veya e-posta');

    $('#modalTest').modal('show');
    if (otpMu) select2Modal('#test_process_type', '#modalTest');
}

function testGonder() {
    const data = {
        action       : 'test_gonder',
        kanal_id     : $('#test_kanal_id').val(),
        alici        : $('#test_alici').val(),
        mesaj        : $('#test_mesaj').val(),
        process_type : $('#test_process_type').val() || '3',
    };
    if (!data.alici) { Swal.fire('Uyarı', 'Alıcı boş olamaz.', 'warning'); return; }

    $('#test_sonuc').html('<div class="text-center"><div class="spinner-border spinner-border-sm text-primary"></div> Gönderiliyor...</div>');
    $.post(pageUrl, data, function (r) {
        const cls   = r.success ? 'success' : 'danger';
        const ikon  = r.success ? 'check-circle' : 'x-circle';
        $('#test_sonuc').html(`<div class="alert alert-${cls} mt-2"><i class="bi bi-${ikon}"></i> ${htmlEncode(r.message)}</div>`);
        if (r.success) { statsYukle(); logListele(); }
    });
}

// ── Util ─────────────────────────────────────────────────────────────────────
function htmlEncode(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

</body>
</html>
