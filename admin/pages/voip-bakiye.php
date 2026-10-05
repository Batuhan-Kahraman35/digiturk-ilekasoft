<?php
/**
 * Admin Panel - VoIP Bakiye & Ödeme Geçmişi
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'VoIP Bakiye & Ödemeler';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

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

// ─── cURL & parse yardımcıları ────────────────────────────────────────────────
function voipCurl(string $url, array $post = [], string $cookieFile = '', string $referer = '', bool $follow = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    ]);
    if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw     = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $final   = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $hdrSize), $m);
    return ['body' => substr($raw, $hdrSize), 'code' => $code, 'final' => $final, 'location' => trim($m[1] ?? '')];
}

function voipLogin(array $kanal): array
{
    $baseUrl    = rtrim($kanal['Entegrasyonlar_BaseURL'], '/');
    $hesapYolu  = trim($kanal['EntegrasyonKanallari_Instance'], '/');
    $loginUrl   = "{$baseUrl}/{$hesapYolu}/account.php";
    $postUrl    = "{$baseUrl}/main.php";
    $cookieFile = sys_get_temp_dir() . '/sippy_bak_' . md5($kanal['EntegrasyonKanallari_id']) . '.txt';

    if (file_exists($cookieFile)) unlink($cookieFile);
    voipCurl($loginUrl, [], $cookieFile);

    $login = voipCurl($postUrl, [
        'acct_type' => 'customer', 'login_page' => '',
        'username'  => $kanal['EntegrasyonKanallari_Kullanici'],
        'password'  => $kanal['EntegrasyonKanallari_Sifre'],
        'Login'     => 'Login',
    ], $cookieFile, $loginUrl, false);

    $loc     = $login['location'];
    $loginOk = ($login['code'] >= 301 && strpos($loc, 'index.php') === false && strpos($loc, 'account.php') === false && $loc !== '');
    $afterUrl = $loginOk ? (str_starts_with($loc, 'http') ? $loc : "{$baseUrl}/" . ltrim($loc, '/')) : '';
    if ($afterUrl) voipCurl($afterUrl, [], $cookieFile, $postUrl);

    return ['ok' => $loginOk, 'baseUrl' => $baseUrl, 'hesapYolu' => $hesapYolu, 'cookieFile' => $cookieFile, 'afterUrl' => $afterUrl];
}

function sippyBakiyeSync(array $kanal, $db): array
{
    $sess = voipLogin($kanal);
    if (!$sess['ok']) return ['success' => false, 'message' => 'Login başarısız: ' . $kanal['EntegrasyonKanallari_KanalAdi']];

    $kanalId    = (int)$kanal['EntegrasyonKanallari_id'];
    $prefsUrl   = "{$sess['baseUrl']}/{$sess['hesapYolu']}/customer_prefs.php";
    $odemeUrl   = "{$sess['baseUrl']}/{$sess['hesapYolu']}/payments_history.php";
    $simdi      = date('Y-m-d H:i:s');

    // ── Bakiye ────────────────────────────────────────────────────────────────
    $prefsResp = voipCurl($prefsUrl, [], $sess['cookieFile'], $sess['afterUrl']);
    $bakiye    = null;

    if (strpos($prefsResp['final'], 'customer_prefs.php') !== false) {
        preg_match('/<input[^>]+name=["\']balance["\'][^>]+value=["\']([^"\']+)["\']/i', $prefsResp['body'], $bM);
        if (!$bM) preg_match('/<input[^>]+value=["\']([^"\']+)["\'][^>]+name=["\']balance["\']/i', $prefsResp['body'], $bM);
        $bakiye = isset($bM[1]) ? (float)$bM[1] : null;
    }

    if ($bakiye !== null) {
        $db->query(
            "INSERT INTO VoIPBakiye (VoIPBakiye_Kanal_id, VoIPBakiye_Tarih, VoIPBakiye_Bakiye,
             OlusturanKullanici, OlusturmaTarihi, GuncelleyenKullanici, GuncellemeTarihi)
             VALUES (?,?,?,1,?,1,?)",
            [$kanalId, $simdi, $bakiye, $simdi, $simdi]
        );
    }

    // ── Ödemeler ──────────────────────────────────────────────────────────────
    $odemeResp    = voipCurl($odemeUrl, [], $sess['cookieFile'], $sess['afterUrl']);
    $odemeEklenen = 0;

    if (strpos($odemeResp['final'], 'payments_history.php') !== false) {
        preg_match_all('/<table[^>]*>(.*?)<\/table>/is', $odemeResp['body'], $tblM);
        $anaTablo = [];
        $enCok    = 0;
        foreach ($tblM[1] as $tblContent) {
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tblContent, $rowM);
            $satirlar = [];
            foreach ($rowM[1] as $row) {
                preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $row, $cellM);
                $hcr = array_map(fn($c) => trim(strip_tags(html_entity_decode($c))), $cellM[1]);
                if (array_filter($hcr)) $satirlar[] = $hcr;
            }
            if (count($satirlar) > $enCok && count($satirlar) >= 3) {
                $enCok = count($satirlar);
                $anaTablo = $satirlar;
            }
        }

        foreach ($anaTablo as $rIdx => $satir) {
            if ($rIdx === 0 || count($satir) < 4) continue;
            $islemNo  = trim($satir[0]);
            if (!$islemNo || !is_numeric($islemNo)) continue;

            // Tarih: "18 Jun 2026 13:54"
            $tarihObj = DateTime::createFromFormat('d M Y H:i', $satir[1]);
            $tarihStr = $tarihObj ? $tarihObj->format('Y-m-d H:i:s') : $simdi;

            $yontem   = $satir[2] ?? '';
            $tutar    = (float)str_replace(',', '.', $satir[3]);
            $aciklama = $satir[4] ?? '';
            $sonuc    = $satir[5] ?? '';

            $mevcut = $db->fetchOne(
                "SELECT VoIPOdemeler_id FROM VoIPOdemeler WHERE VoIPOdemeler_Kanal_id=? AND VoIPOdemeler_IslemNo=?",
                [$kanalId, $islemNo]
            );

            if (!$mevcut) {
                $db->query(
                    "INSERT INTO VoIPOdemeler (VoIPOdemeler_Kanal_id, VoIPOdemeler_IslemNo,
                     VoIPOdemeler_Tarih, VoIPOdemeler_Yontem, VoIPOdemeler_BrutTutar,
                     VoIPOdemeler_Aciklama, VoIPOdemeler_Sonuc,
                     OlusturanKullanici, OlusturmaTarihi, GuncelleyenKullanici, GuncellemeTarihi)
                     VALUES (?,?,?,?,?,?,?,1,?,1,?)",
                    [$kanalId, $islemNo, $tarihStr, $yontem, $tutar, $aciklama, $sonuc, $simdi, $simdi]
                );
                $odemeEklenen++;
            }
        }
    }

    if (file_exists($sess['cookieFile'])) unlink($sess['cookieFile']);

    return [
        'success'      => true,
        'bakiye'       => $bakiye,
        'odemeEklenen' => $odemeEklenen,
    ];
}

// ─── AJAX ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'stats':
                $kanalId = $_POST['kanal_id'] ?? '';
                $where   = $kanalId !== '' ? 'WHERE VoIPBakiye_Kanal_id = ?' : '';
                $params  = $kanalId !== '' ? [(int)$kanalId] : [];

                $guncel = $db->fetchOne("
                    SELECT TOP 1 b.VoIPBakiye_Bakiye,
                           CONVERT(VARCHAR(19), b.VoIPBakiye_Tarih, 120) as VoIPBakiye_Tarih,
                           k.EntegrasyonKanallari_KanalAdi, e.Entegrasyonlar_Adi
                    FROM VoIPBakiye b
                    INNER JOIN EntegrasyonKanallari k ON b.VoIPBakiye_Kanal_id = k.EntegrasyonKanallari_id
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    " . ($kanalId !== '' ? 'WHERE b.VoIPBakiye_Kanal_id = ?' : '') . "
                    ORDER BY b.VoIPBakiye_id DESC
                ", $params);

                $sonOdeme = $db->fetchOne("
                    SELECT TOP 1 VoIPOdemeler_BrutTutar,
                           CONVERT(VARCHAR(19), VoIPOdemeler_Tarih, 120) as VoIPOdemeler_Tarih
                    FROM VoIPOdemeler
                    " . ($kanalId !== '' ? 'WHERE VoIPOdemeler_Kanal_id = ?' : '') . "
                    ORDER BY VoIPOdemeler_Tarih DESC
                ", $params);

                $toplamOdeme = $db->fetchOne("
                    SELECT SUM(VoIPOdemeler_BrutTutar) as toplam
                    FROM VoIPOdemeler
                    " . ($kanalId !== '' ? 'WHERE VoIPOdemeler_Kanal_id = ?' : ''), $params);

                echo json_encode(['success' => true, 'guncel' => $guncel, 'sonOdeme' => $sonOdeme, 'toplamOdeme' => $toplamOdeme]);
                break;

            case 'bakiye_gecmisi':
                $kanalId = $_POST['kanal_id'] ?? '';
                $where   = $kanalId !== '' ? 'WHERE VoIPBakiye_Kanal_id = ?' : '';
                $params  = $kanalId !== '' ? [(int)$kanalId] : [];

                $liste = $db->fetchAll("
                    SELECT TOP 30
                        CONVERT(VARCHAR(19), b.VoIPBakiye_Tarih, 120) as Tarih,
                        CAST(b.VoIPBakiye_Bakiye AS DECIMAL(12,4)) as Bakiye,
                        k.EntegrasyonKanallari_KanalAdi, e.Entegrasyonlar_Adi
                    FROM VoIPBakiye b
                    INNER JOIN EntegrasyonKanallari k ON b.VoIPBakiye_Kanal_id = k.EntegrasyonKanallari_id
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    {$where}
                    ORDER BY b.VoIPBakiye_id DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            case 'odeme_listesi':
                $kanalId = $_POST['kanal_id'] ?? '';
                $where   = $kanalId !== '' ? 'WHERE o.VoIPOdemeler_Kanal_id = ?' : '';
                $params  = $kanalId !== '' ? [(int)$kanalId] : [];

                $liste = $db->fetchAll("
                    SELECT
                        o.VoIPOdemeler_IslemNo,
                        CONVERT(VARCHAR(19), o.VoIPOdemeler_Tarih, 120) as Tarih,
                        o.VoIPOdemeler_Yontem,
                        CAST(o.VoIPOdemeler_BrutTutar AS DECIMAL(12,4)) as BrutTutar,
                        o.VoIPOdemeler_Aciklama,
                        o.VoIPOdemeler_Sonuc,
                        k.EntegrasyonKanallari_KanalAdi, e.Entegrasyonlar_Adi
                    FROM VoIPOdemeler o
                    INNER JOIN EntegrasyonKanallari k ON o.VoIPOdemeler_Kanal_id = k.EntegrasyonKanallari_id
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    {$where}
                    ORDER BY o.VoIPOdemeler_Tarih DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            case 'kanal_select':
                $liste = $db->fetchAll("
                    SELECT k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_KanalAdi, e.Entegrasyonlar_Adi
                    FROM EntegrasyonKanallari k
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE e.Entegrasyonlar_Tip = 'voip' AND k.Durum = 1 AND e.Durum = 1
                    ORDER BY e.Entegrasyonlar_Adi, k.EntegrasyonKanallari_KanalAdi
                ");
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            case 'sync':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']); break;
                }
                $kanallar = $db->fetchAll("
                    SELECT k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_KanalAdi,
                           k.EntegrasyonKanallari_Instance, k.EntegrasyonKanallari_Kullanici,
                           k.EntegrasyonKanallari_Sifre, e.Entegrasyonlar_BaseURL
                    FROM EntegrasyonKanallari k
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE e.Entegrasyonlar_Tip = 'voip' AND k.Durum = 1 AND e.Durum = 1
                ");

                if (!$kanallar) { echo json_encode(['success' => false, 'message' => 'Aktif VoIP kanalı bulunamadı.']); break; }

                $sonuclar = [];
                foreach ($kanallar as $k) {
                    $sonuc = sippyBakiyeSync($k, $db);
                    $sonuclar[] = array_merge($sonuc, ['kanal' => $k['EntegrasyonKanallari_KanalAdi']]);
                }
                echo json_encode(['success' => true, 'detay' => $sonuclar]);
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

                <!-- Kanal filtresi + Güncelle -->
                <div class="row mb-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Operatör / Kanal</label>
                        <select class="form-select select2-basic" id="f_kanal">
                            <option value="">Tümü</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100" onclick="yukle()"><i class="bi bi-search"></i> Filtrele</button>
                    </div>
                    <?php if ($permissions['can_edit']): ?>
                    <div class="col-md-3 ms-auto">
                        <button class="btn btn-success w-100" onclick="guncelle()" id="btnGuncelle">
                            <i class="bi bi-arrow-repeat"></i> Bakiye & Ödemeleri Güncelle
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-wallet2"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Güncel Bakiye</span>
                                <span class="info-box-number" id="stat-bakiye">—</span>
                                <span class="info-box-text" id="stat-bakiye-tarih" style="font-size:11px"></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-cash-stack"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Son Yükleme</span>
                                <span class="info-box-number" id="stat-son-odeme">—</span>
                                <span class="info-box-text" id="stat-son-odeme-tarih" style="font-size:11px"></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-graph-up-arrow"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Yükleme</span>
                                <span class="info-box-number" id="stat-toplam-odeme">—</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-clock-history"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bakiye Güncelleme</span>
                                <span class="info-box-number" id="stat-kayit-sayisi">—</span>
                                <span class="info-box-text">kayıt</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Bakiye Geçmişi -->
                    <div class="col-md-4">
                        <div class="card card-outline card-success">
                            <div class="card-header">
                                <h3 class="card-title"><i class="bi bi-clock-history"></i> Bakiye Geçmişi</h3>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-sm table-striped mb-0" id="tblBakiye">
                                    <thead>
                                        <tr>
                                            <th>Tarih</th>
                                            <th>Kanal</th>
                                            <th class="text-end">Bakiye (TRY)</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Ödeme Geçmişi -->
                    <div class="col-md-8">
                        <div class="card card-outline card-primary">
                            <div class="card-header">
                                <h3 class="card-title"><i class="bi bi-receipt"></i> Ödeme Geçmişi</h3>
                            </div>
                            <div class="card-body">
                                <table id="tblOdemeler" class="table table-bordered table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>İşlem No</th>
                                            <th>Tarih</th>
                                            <th>Kanal</th>
                                            <th>Yöntem</th>
                                            <th class="text-end">Tutar (TRY)</th>
                                            <th>Sonuç</th>
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
let dtOdemeler;

$(document).ready(function () {
    $('.select2-basic').select2({ theme: 'bootstrap-5', width: '100%' });
    kanalSelectDoldur();
    yukle();
});

function kanalSelectDoldur() {
    $.post(pageUrl, { action: 'kanal_select' }, function (r) {
        if (!r.success) return;
        const $sel = $('#f_kanal');
        $sel.find('option:not(:first)').remove();
        r.data.forEach(k => {
            $sel.append(`<option value="${k.EntegrasyonKanallari_id}">${htmlEncode(k.Entegrasyonlar_Adi)} — ${htmlEncode(k.EntegrasyonKanallari_KanalAdi)}</option>`);
        });
        $sel.trigger('change');
    });
}

function yukle() {
    const kanalId = $('#f_kanal').val();
    statsYukle(kanalId);
    bakiyeGecmisiYukle(kanalId);
    odemeListesiYukle(kanalId);
}

function statsYukle(kanalId) {
    $.post(pageUrl, { action: 'stats', kanal_id: kanalId }, function (r) {
        if (!r.success) return;
        const g = r.guncel, s = r.sonOdeme, t = r.toplamOdeme;
        $('#stat-bakiye').text(g ? parseFloat(g.VoIPBakiye_Bakiye).toLocaleString('tr-TR', {minimumFractionDigits:2}) + ' ₺' : '—');
        $('#stat-bakiye-tarih').text(g ? g.VoIPBakiye_Tarih : '');
        $('#stat-son-odeme').text(s ? parseFloat(s.VoIPOdemeler_BrutTutar).toLocaleString('tr-TR', {minimumFractionDigits:2}) + ' ₺' : '—');
        $('#stat-son-odeme-tarih').text(s ? s.VoIPOdemeler_Tarih : '');
        $('#stat-toplam-odeme').text(t?.toplam ? parseFloat(t.toplam).toLocaleString('tr-TR', {minimumFractionDigits:2}) + ' ₺' : '—');
    });
}

function bakiyeGecmisiYukle(kanalId) {
    $.post(pageUrl, { action: 'bakiye_gecmisi', kanal_id: kanalId }, function (r) {
        if (!r.success) return;
        const $tbody = $('#tblBakiye tbody').empty();
        $('#stat-kayit-sayisi').text(r.data.length);
        r.data.forEach(b => {
            $tbody.append(`<tr>
                <td><small>${htmlEncode(b.Tarih)}</small></td>
                <td><small>${htmlEncode(b.EntegrasyonKanallari_KanalAdi)}</small></td>
                <td class="text-end"><strong>${parseFloat(b.Bakiye).toLocaleString('tr-TR', {minimumFractionDigits:2})}</strong></td>
            </tr>`);
        });
    });
}

function odemeListesiYukle(kanalId) {
    $.post(pageUrl, { action: 'odeme_listesi', kanal_id: kanalId }, function (r) {
        if (!r.success) return;
        if (dtOdemeler) dtOdemeler.destroy();
        const $tbody = $('#tblOdemeler tbody').empty();

        r.data.forEach(o => {
            const sonucBadge = o.VoIPOdemeler_Sonuc === 'TAMAM'
                ? `<span class="badge text-bg-success">${htmlEncode(o.VoIPOdemeler_Sonuc)}</span>`
                : `<span class="badge text-bg-secondary">${htmlEncode(o.VoIPOdemeler_Sonuc)}</span>`;

            $tbody.append(`<tr>
                <td><code>${htmlEncode(o.VoIPOdemeler_IslemNo)}</code></td>
                <td><small>${htmlEncode(o.Tarih)}</small></td>
                <td><small>${htmlEncode(o.EntegrasyonKanallari_KanalAdi)}</small></td>
                <td><small>${htmlEncode(o.VoIPOdemeler_Yontem)}</small></td>
                <td class="text-end"><strong>${parseFloat(o.BrutTutar).toLocaleString('tr-TR', {minimumFractionDigits:4})}</strong></td>
                <td>${sonucBadge}</td>
            </tr>`);
        });

        dtOdemeler = $('#tblOdemeler').DataTable({
            language  : { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order     : [[1, 'desc']],
            pageLength: 25,
            destroy   : true,
        });
    });
}

function guncelle() {
    Swal.fire({
        title: 'Güncelleniyor...',
        text: 'Bakiye ve ödeme bilgileri çekiliyor.',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });
    $('#btnGuncelle').prop('disabled', true);

    $.post(pageUrl, { action: 'sync' }, function (r) {
        $('#btnGuncelle').prop('disabled', false);
        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }

        let html = '';
        (r.detay || []).forEach(d => {
            const ikon = d.success ? '✅' : '❌';
            const msg  = d.success
                ? `Bakiye: <b>${d.bakiye !== null ? parseFloat(d.bakiye).toLocaleString('tr-TR', {minimumFractionDigits:2}) + ' ₺' : '—'}</b> | Yeni ödeme: <b>${d.odemeEklenen}</b>`
                : d.message;
            html += `<div>${ikon} <b>${htmlEncode(d.kanal)}</b>: ${msg}</div>`;
        });

        Swal.fire({ icon: 'success', title: 'Güncellendi', html });
        yukle();
    }).fail(function () {
        $('#btnGuncelle').prop('disabled', false);
        Swal.fire('Hata', 'Sunucuya bağlanılamadı.', 'error');
    });
}

function htmlEncode(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

</body>
</html>
