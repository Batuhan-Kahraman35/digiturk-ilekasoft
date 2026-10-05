<?php
/**
 * Admin Panel - API Swagger (Endpoint Test / Deneme)
 * APIEndpointler tablosundan beslenir. Sunucu tarafı cURL proxy ile
 * endpoint'leri çalıştırır (CORS + güvenlik). Kimlik bilgileri ve token
 * DB'de saklanmaz; kullanıcı ekranda elle girer/yapıştırır.
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

$pageTitle       = $pageinfo['sayfalar_sayfa_adi'] ?? 'API Swagger';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

// Birim görünürlük bağlamı: kısıtlıysa yalnız kendi birimi + alt birimleri
$birimKisitli   = (!$permissions['is_admin'] && !empty($permissions['can_view_birim']));
$izinliBirimler = [];
if ($birimKisitli) {
    $kb = $db->fetchOne("SELECT kullanici_birim_id FROM kullanicilar WHERE kullanici_id = ?", [$user['kullanici_id']]);
    $kullaniciBirimId = $kb['kullanici_birim_id'] ?? null;
    if ($kullaniciBirimId) {
        $rows = $db->fetchAll("
            WITH BirimAgaci AS (
                SELECT KullaniciBirim_id FROM KullaniciBirim WHERE KullaniciBirim_id = ?
                UNION ALL
                SELECT b.KullaniciBirim_id FROM KullaniciBirim b
                INNER JOIN BirimAgaci a ON b.KullaniciBirim_UstBirim_id = a.KullaniciBirim_id
            )
            SELECT KullaniciBirim_id FROM BirimAgaci
        ", [$kullaniciBirimId]);
        $izinliBirimler = array_map(fn($r) => (int)$r['KullaniciBirim_id'], $rows);
    }
}

// Personel birim kısıt EXISTS parçası (silsile: doğrudan Personel VEYA bağlı Alt Bayi)
function apiSwaggerPersonelKisit(array $izinliBirimler): array {
    $ph  = implode(',', array_fill(0, count($izinliBirimler), '?'));
    $sql = " AND EXISTS (
        SELECT 1 FROM KullaniciBirimYetkileri kby
        WHERE kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
          AND kby.Durum = 1
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
          AND (kby.KullaniciBirimYetkileri_Personel_id = p.DigiturkAltBayiPersonel_Id
            OR kby.KullaniciBirimYetkileri_AltBayi_id  = p.DigiturkAltBayiPersonel_AltBayiId)
    )";
    return [$sql, $izinliBirimler];
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // Aktif endpoint'leri kategori bazlı getir
            case 'list':
                $rows = $db->fetchAll("
                    SELECT
                        APIEndpointler_Id,
                        APIEndpointler_Kategori,
                        APIEndpointler_HttpMetod,
                        APIEndpointler_Endpoint,
                        APIEndpointler_Aciklama,
                        APIEndpointler_ParametreOrnek,
                        APIEndpointler_YanitOrnek
                    FROM APIEndpointler
                    WHERE Durum = 1
                    ORDER BY APIEndpointler_Id
                ");
                // Her endpoint için kendi gateway yolumuzu türet (Digiturk URL'indeki /api/ sonrası)
                foreach ($rows as &$r) {
                    $u   = (string)$r['APIEndpointler_Endpoint'];
                    $pos = strpos($u, '/api/');
                    $r['gateway_path'] = $pos !== false
                        ? substr($u, $pos + 5)
                        : ltrim((string)parse_url($u, PHP_URL_PATH), '/');
                }
                unset($r);
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            // Birim'e ait API personelleri (token alınabilecek hesaplar)
            case 'personel_list':
                $where  = "p.Durum = 1";
                $params = [];
                if ($birimKisitli) {
                    if (empty($izinliBirimler)) {
                        echo json_encode(['success' => true, 'data' => []]);
                        break;
                    }
                    [$frag, $prm] = apiSwaggerPersonelKisit($izinliBirimler);
                    $where  .= $frag;
                    $params  = $prm;
                }
                $personeller = $db->fetchAll("
                    SELECT
                        p.DigiturkAltBayiPersonel_Id        AS id,
                        p.DigiturkAltBayiPersonel_AdSoyad   AS adsoyad,
                        p.DigiturkAltBayiPersonel_KullaniciAdi AS kullaniciadi,
                        p.DigiturkAltBayiPersonel_TokenDurum   AS tokenDurum,
                        CONVERT(VARCHAR(16), p.DigiturkAltBayiPersonel_TokenSuresi, 120) AS tokenSuresi,
                        a.DigiturkAltBayiler_Ad   AS altbayi,
                        n.DigiturkAnaBayiler_BayiKodu AS bayikodu
                    FROM DigiturkAltBayiPersonel p
                    LEFT JOIN DigiturkAltBayiler a ON p.DigiturkAltBayiPersonel_AltBayiId = a.DigiturkAltBayiler_Id
                    LEFT JOIN DigiturkAnaBayiler n ON a.DigiturkAltBayiler_AnaBayiId      = n.DigiturkAnaBayiler_Id
                    WHERE $where
                    ORDER BY p.DigiturkAltBayiPersonel_AdSoyad
                ", $params);
                echo json_encode(['success' => true, 'data' => $personeller]);
                break;

            // Seçili personel ile login → token döndür (Authorize'a yapıştırılır)
            case 'personel_login':
                $pid = (int)($_POST['personel_id'] ?? 0);
                if ($pid <= 0) { echo json_encode(['success' => false, 'message' => 'Geçersiz personel']); break; }

                // Birim erişim doğrulaması
                $erisimWhere = "p.DigiturkAltBayiPersonel_Id = ? AND p.Durum = 1";
                $erisimPrm   = [$pid];
                if ($birimKisitli) {
                    if (empty($izinliBirimler)) { echo json_encode(['success' => false, 'message' => 'Bu personele erişim yetkiniz yok']); break; }
                    [$frag, $prm] = apiSwaggerPersonelKisit($izinliBirimler);
                    $erisimWhere .= $frag;
                    $erisimPrm    = array_merge($erisimPrm, $prm);
                }

                $per = $db->fetchOne("
                    SELECT p.DigiturkAltBayiPersonel_Id
                    FROM DigiturkAltBayiPersonel p
                    LEFT JOIN DigiturkAltBayiler a ON p.DigiturkAltBayiPersonel_AltBayiId = a.DigiturkAltBayiler_Id
                    LEFT JOIN DigiturkAnaBayiler n ON a.DigiturkAltBayiler_AnaBayiId      = n.DigiturkAnaBayiler_Id
                    WHERE $erisimWhere
                ", $erisimPrm);

                if (!$per) { echo json_encode(['success' => false, 'message' => 'Personele erişim yetkiniz yok veya kayıt yok']); break; }

                // Ortak login: geçerli token varsa Digiturk'e gidilmez; taze token için "zorla".
                // Bayi limiti ve şifre/bekleme kilitleri fonksiyonda uygulanır.
                require_once __DIR__ . '/../includes/DigiturkKotaServisi.php';
                $s = digiturkLogin($db, $pid, 'swagger', !empty($_POST['zorla']), 60, (int)$user['kullanici_id']);

                if (!$s['basarili']) { echo json_encode(['success' => false, 'message' => $s['mesaj']]); break; }

                echo json_encode([
                    'success'  => true,
                    'token'    => $s['token'],
                    'onbellek' => $s['onbellek'],
                    'message'  => $s['onbellek']
                        ? $s['mesaj'] . ' Digiturk\'e istek gönderilmedi.'
                        : $s['mesaj'],
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
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">

    <style>
        .badge-metod { font-size: .72rem; font-weight: 700; padding: .3rem .55rem; border-radius: .3rem; letter-spacing: .3px; min-width: 62px; display:inline-block; text-align:center; }
        .metod-GET    { background: #d1ecf1; color: #0c5460; }
        .metod-POST   { background: #d4edda; color: #155724; }
        .metod-PUT    { background: #fff3cd; color: #856404; }
        .metod-PATCH  { background: #e2d9f3; color: #432874; }
        .metod-DELETE { background: #f8d7da; color: #721c24; }

        .ep-row { border: 1px solid #e3e6ea; border-radius: .4rem; margin-bottom: .5rem; overflow: hidden; }
        .ep-head { display:flex; align-items:center; gap:.6rem; padding:.55rem .8rem; cursor:pointer; background:#fafbfc; }
        .ep-head:hover { background:#f1f3f5; }
        .ep-path { font-family: monospace; font-size: .85rem; font-weight: 600; word-break: break-all; }
        .ep-desc { color:#6c757d; font-size:.82rem; margin-left:auto; text-align:right; }
        .ep-body { padding:.9rem; border-top:1px dashed #e3e6ea; display:none; }
        .ep-row.open .ep-body { display:block; }
        .ep-row.open .ep-head { background:#eef2f7; }

        .kategori-baslik { font-weight:700; font-size:1rem; margin:1rem 0 .5rem; padding-bottom:.3rem; border-bottom:2px solid #0d6efd; color:#0d6efd; }

        pre.json-box { background:#1e1e1e; color:#d4d4d4; border-radius:.375rem; padding:.75rem; font-size:.8rem; max-height:340px; overflow:auto; white-space:pre-wrap; word-break:break-word; margin:0; }
        pre.json-light { background:#f8f9fa; color:#212529; border:1px solid #dee2e6; }
        textarea.body-input { font-family: monospace; font-size:.82rem; }

        .resp-meta { display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; margin-bottom:.4rem; }
        .status-pill { padding:.2rem .55rem; border-radius:.3rem; font-weight:700; font-size:.78rem; }
        .status-2xx { background:#d4edda; color:#155724; }
        .status-4xx, .status-5xx { background:#f8d7da; color:#721c24; }
        .status-3xx { background:#fff3cd; color:#856404; }

        .authorize-box.ok { border-color:#198754 !important; }
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
                                <span class="info-box-text">Aktif Endpoint</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-tags"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Kategori</span>
                                <span class="info-box-number" id="stat-kategori">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box" id="auth-info-box">
                            <span class="info-box-icon text-bg-secondary"><i class="bi bi-shield-lock"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Yetkilendirme</span>
                                <span class="info-box-number" id="stat-auth" style="font-size:1rem;">Token yok</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Birim API Personelleri -->
                <div class="card card-info card-outline mb-3" id="personelCard">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-person-badge"></i> Birim API Personelleri <small class="text-muted">— Token al ve Authorize'a yapıştır</small></h3>
                    </div>
                    <div class="card-body" id="personelBody">
                        <div class="text-muted small py-2"><span class="spinner-border spinner-border-sm text-info"></span> Personeller yükleniyor...</div>
                    </div>
                </div>

                <!-- Authorize -->
                <div class="card card-success card-outline mb-3 authorize-box" id="authorizeCard">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-shield-lock"></i> Authorize (Bearer Token)</h3>
                    </div>
                    <div class="card-body">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-10">
                                <label class="form-label">Token <small class="text-muted">— Login yanıtındaki <code>data.token</code> değerini buraya yapıştırın. Tüm isteklere <code>Authorization: Bearer</code> olarak eklenir.</small></label>
                                <input type="text" class="form-control font-monospace" id="authToken" placeholder="ör: eyJhbGciOi...">
                            </div>
                            <div class="col-md-2 d-grid">
                                <button type="button" class="btn btn-outline-danger" id="clearToken"><i class="bi bi-x-circle"></i> Temizle</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Arama -->
                <div class="card mb-3">
                    <div class="card-body py-2">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-8">
                                <input type="text" class="form-control" id="searchBox" placeholder="Endpoint, kategori veya açıklamada ara...">
                            </div>
                            <div class="col-md-4 text-md-end">
                                <button type="button" class="btn btn-sm btn-outline-warning" id="postmanDownload"><i class="bi bi-download"></i> Postman İndir</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="expandAll"><i class="bi bi-arrows-expand"></i> Tümünü Aç</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="collapseAll"><i class="bi bi-arrows-collapse"></i> Tümünü Kapat</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Endpoint Listesi -->
                <div id="swaggerContainer">
                    <div class="text-center text-muted py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <div class="mt-2">Endpoint'ler yükleniyor...</div>
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
    const metodRenk = { GET:'metod-GET', POST:'metod-POST', PUT:'metod-PUT', PATCH:'metod-PATCH', DELETE:'metod-DELETE' };
    const TOKEN_KEY = 'apiSwaggerToken';
    let allEndpoints = [];

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function pathOf(url) {
        try { const u = new URL(url); return u.pathname; } catch { return url; }
    }

    function prettyJson(str) {
        if (str === null || str === undefined || str === '') return '';
        try { return JSON.stringify(JSON.parse(str), null, 2); } catch { return String(str); }
    }

    function statusClass(code) {
        if (code >= 200 && code < 300) return 'status-2xx';
        if (code >= 300 && code < 400) return 'status-3xx';
        if (code >= 400 && code < 500) return 'status-4xx';
        return 'status-5xx';
    }

    $(document).ready(function () {
        // Token'ı localStorage'dan yükle (kolaylık; sunucuda saklanmaz)
        const saved = localStorage.getItem(TOKEN_KEY);
        if (saved) $('#authToken').val(saved);
        refreshAuthState();

        loadList();
        loadPersoneller();

        $('#authToken').on('input', function () {
            localStorage.setItem(TOKEN_KEY, $(this).val().trim());
            refreshAuthState();
        });

        $('#clearToken').on('click', function () {
            $('#authToken').val('');
            localStorage.removeItem(TOKEN_KEY);
            refreshAuthState();
            showToast('Token temizlendi', 'info');
        });

        $('#searchBox').on('input', function () {
            applySearch($(this).val().trim().toLowerCase());
        });

        $('#expandAll').on('click', () => $('.ep-row').addClass('open'));
        $('#collapseAll').on('click', () => $('.ep-row').removeClass('open'));

        $('#postmanDownload').on('click', downloadPostman);
    });

    function refreshAuthState() {
        const t = $('#authToken').val().trim();
        const $box = $('#authorizeCard');
        const $stat = $('#stat-auth');
        const $icon = $('#auth-info-box .info-box-icon');
        if (t) {
            $box.addClass('ok');
            $stat.text('Token aktif').removeClass('text-danger').addClass('text-success');
            $icon.removeClass('text-bg-secondary text-bg-danger').addClass('text-bg-success');
        } else {
            $box.removeClass('ok');
            $stat.text('Token yok').removeClass('text-success').addClass('text-muted');
            $icon.removeClass('text-bg-success text-bg-danger').addClass('text-bg-secondary');
        }
    }

    function loadList() {
        $.ajax({
            url: '', method: 'POST', data: { action: 'list' }, dataType: 'json',
            success: function (r) {
                if (!r.success) { showToast('Liste yüklenemedi', 'error'); return; }
                allEndpoints = r.data || [];
                renderEndpoints(allEndpoints);
                updateStats(allEndpoints);
            },
            error: function () { showToast('Sunucu hatası', 'error'); }
        });
    }

    function updateStats(rows) {
        $('#stat-toplam').text(rows.length);
        const kategoriler = new Set(rows.map(r => r.APIEndpointler_Kategori || '-'));
        $('#stat-kategori').text(kategoriler.size);
    }

    function renderEndpoints(rows) {
        const $c = $('#swaggerContainer').empty();
        if (!rows.length) {
            $c.html('<div class="alert alert-info">Aktif endpoint bulunamadı.</div>');
            return;
        }

        // Kategoriye göre grupla
        const gruplar = {};
        rows.forEach(r => {
            const k = r.APIEndpointler_Kategori || 'Diğer';
            (gruplar[k] = gruplar[k] || []).push(r);
        });

        Object.keys(gruplar).forEach(kategori => {
            const $kat = $(`<div class="kategori-grup" data-kategori="${escapeHtml(kategori)}"></div>`);
            $kat.append(`<div class="kategori-baslik"><i class="bi bi-folder2-open"></i> ${escapeHtml(kategori)}</div>`);

            gruplar[kategori].forEach(ep => {
                const metodClass = metodRenk[ep.APIEndpointler_HttpMetod] || '';
                const metod = ep.APIEndpointler_HttpMetod || 'GET';
                const hasBody = ['POST','PUT','PATCH','DELETE'].includes(metod);
                const id = ep.APIEndpointler_Id;
                const yanitOrnek = prettyJson(ep.APIEndpointler_YanitOrnek);
                const paramOrnek = ep.APIEndpointler_ParametreOrnek || '';

                const bodyAlani = hasBody ? `
                    <div class="mb-2">
                        <label class="form-label fw-semibold">İstek Gövdesi <small class="text-muted">(JSON)</small></label>
                        <textarea class="form-control body-input" id="body-${id}" rows="7">${escapeHtml(paramOrnek)}</textarea>
                    </div>` : `
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Query Parametreleri <small class="text-muted">(opsiyonel)</small></label>
                        <input type="text" class="form-control" id="query-${id}" placeholder="örn: gsm=905XXXXXXXXX  (veya basvuruId=123)">
                        <div class="text-muted small mt-1"><i class="bi bi-info-circle"></i> Bu metot gövde almaz; parametreler URL'e eklenir.</div>
                    </div>`;

                const gwPath = ep.gateway_path || '';
                const gwUrl  = location.origin + '/api/' + gwPath;

                const row = `
                <div class="ep-row" data-id="${id}" data-search="${escapeHtml((kategori + ' ' + gwPath + ' ' + (ep.APIEndpointler_Aciklama||'')).toLowerCase())}">
                    <div class="ep-head" onclick="toggleRow(${id})">
                        <span class="badge-metod ${metodClass}">${escapeHtml(metod)}</span>
                        <span class="ep-path">/api/${escapeHtml(gwPath)}</span>
                        <span class="ep-desc">${escapeHtml(ep.APIEndpointler_Aciklama || '')}</span>
                    </div>
                    <div class="ep-body">
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="small mb-2">
                                    <div><span class="font-monospace">${escapeHtml(gwUrl)}</span></div>
                                </div>
                                ${bodyAlani}
                                <button type="button" class="btn btn-primary btn-sm" onclick="execEndpoint(${id})">
                                    <i class="bi bi-play-fill"></i> Çalıştır
                                </button>
                                <span id="loading-${id}" class="ms-2 d-none"><span class="spinner-border spinner-border-sm text-primary"></span></span>
                            </div>
                            <div class="col-lg-6">
                                <ul class="nav nav-tabs nav-tabs-sm" role="tablist">
                                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#resp-${id}">Yanıt</a></li>
                                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#ornek-${id}">Beklenen Örnek</a></li>
                                </ul>
                                <div class="tab-content pt-2">
                                    <div class="tab-pane fade show active" id="resp-${id}">
                                        <div id="respMeta-${id}" class="resp-meta"></div>
                                        <pre class="json-box" id="respBody-${id}">— Henüz çalıştırılmadı —</pre>
                                        <div id="tokenAksiyon-${id}" class="mt-2"></div>
                                    </div>
                                    <div class="tab-pane fade" id="ornek-${id}">
                                        <pre class="json-box json-light">${escapeHtml(yanitOrnek || '— Örnek yok —')}</pre>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>`;
                $kat.append(row);
            });

            $c.append($kat);
        });
    }

    function toggleRow(id) {
        $(`.ep-row[data-id="${id}"]`).toggleClass('open');
    }

    function applySearch(q) {
        if (!q) {
            $('.ep-row, .kategori-grup').show();
            return;
        }
        $('.ep-row').each(function () {
            const match = $(this).data('search').includes(q);
            $(this).toggle(match);
        });
        // Boş kalan kategorileri gizle
        $('.kategori-grup').each(function () {
            $(this).toggle($(this).find('.ep-row:visible').length > 0);
        });
    }

    function execEndpoint(id) {
        const ep = allEndpoints.find(e => String(e.APIEndpointler_Id) === String(id));
        if (!ep) { showToast('Endpoint bulunamadı', 'error'); return; }

        const method = (ep.APIEndpointler_HttpMetod || 'GET').toUpperCase();
        const gwPath = ep.gateway_path || '';
        let   url    = '/api/' + gwPath;                       // kendi gateway'imiz (same-origin)
        const token  = $('#authToken').val().trim();
        const hasBody = ['POST','PUT','PATCH','DELETE'].includes(method);

        // Gövdesiz (GET) metotlarda query parametrelerini URL'e ekle
        if (!hasBody) {
            const $q = $(`#query-${id}`);
            const q  = $q.length ? $q.val().trim().replace(/^[?&]+/, '') : '';
            if (q) url += (url.includes('?') ? '&' : '?') + q;
        }

        const $bodyInput = $(`#body-${id}`);
        const body = $bodyInput.length ? $bodyInput.val() : '';

        // JSON ön-doğrulama (gövde varsa)
        if (hasBody && $bodyInput.length && body.trim() !== '') {
            try { JSON.parse(body); }
            catch (e) {
                showToast('İstek gövdesi geçerli JSON değil: ' + e.message, 'error');
                return;
            }
        }

        $(`#loading-${id}`).removeClass('d-none');
        $(`#respBody-${id}`).text('İstek gönderiliyor...');
        $(`#respMeta-${id}`).empty();
        $(`#tokenAksiyon-${id}`).empty();

        // Parametresiz POST'ta boş gövde + Content-Type kombinasyonunu ModSecurity (CRS 920420)
        // reddediyor; bu yüzden gövde boşsa '{}' gönder (adres istekleriyle aynı şekil).
        const payload = (hasBody && body.trim() !== '') ? body : '{}';

        const headers = { 'Accept': 'application/json' };
        if (hasBody) headers['Content-Type'] = 'application/json';
        if (token)   headers['Token'] = token;                 // Digiturk token header (Login'de gerekmez)

        const t0 = performance.now();
        fetch(url, {
            method:  method,
            headers: headers,
            body:    hasBody ? payload : undefined
        }).then(async function (res) {
            const elapsed = Math.round(performance.now() - t0);
            const text = await res.text();
            $(`#loading-${id}`).addClass('d-none');
            const sc = statusClass(res.status);
            $(`#respMeta-${id}`).html(
                `<span class="status-pill ${sc}">HTTP ${res.status}</span>` +
                `<span class="text-muted small"><i class="bi bi-clock"></i> ${elapsed} ms</span>` +
                `<span class="badge-metod ${metodRenk[method]||''}">${escapeHtml(method)}</span>` +
                yanitUyarilari(text)
            );
            // Ham yanıt DEĞİŞTİRİLMEZ — uyarılar yalnız meta satırında gösterilir.
            $(`#respBody-${id}`).text(prettyJson(text) || '(boş yanıt)');

            // Login akışı: yanıttan data.token yakala → Authorize'a koy butonu
            tryOfferToken(id, text);
        }).catch(function (err) {
            $(`#loading-${id}`).addClass('d-none');
            $(`#respMeta-${id}`).html(`<span class="status-pill status-5xx">HATA</span>`);
            $(`#respBody-${id}`).text('İstek gönderilemedi: ' + err.message);
        });
    }

    /**
     * Yanıtta gözden kaçan sorunları meta satırında rozet olarak gösterir.
     * Ham JSON'a dokunulmaz; yalnız teşhisi hızlandırır.
     *  - Geçersiz liste kaydı (code=0 / name=null) — kaynak veri eksikliği
     *  - responseCode=0 ama data boş — sessiz başarısızlık
     *  - Gateway'in eklediği emptyReason
     */
    function yanitUyarilari(respStr) {
        let o;
        try { o = JSON.parse(respStr); } catch { return ''; }
        if (!o || typeof o !== 'object') return '';

        const rozet = (renk, ikon, metin) =>
            `<span class="badge text-bg-${renk}" title="${escapeHtml(metin)}"><i class="bi bi-${ikon}"></i> ${escapeHtml(metin)}</span>`;

        let out = '';
        const rc   = o.responseCode;
        const data = o.data;

        if (rc === 0 || rc === undefined) {
            if (Array.isArray(data)) {
                const bozuk = data.filter(it => it && (
                    it.code === 0 || it.code === '0' || it.code === null || it.code === undefined ||
                    it.name === null || it.name === undefined || String(it.name).trim() === ''
                )).length;
                if (bozuk > 0) {
                    out += rozet('warning', 'exclamation-triangle',
                        `${bozuk} geçersiz kayıt (code=0 / name=null) — kaynak veri eksikliği`);
                }
                if (data.length === 0) {
                    out += rozet('secondary', 'info-circle', 'Başarılı yanıt, veri yok');
                }
            } else if (data === null || data === undefined) {
                out += rozet('secondary', 'info-circle', 'Başarılı yanıt, veri yok');
            }
        }

        if (o.emptyReason) {
            out += rozet('warning', 'signpost-split', o.emptyReason);
        }
        return out;
    }

    function tryOfferToken(id, respStr) {
        let tok = null;
        try {
            const o = JSON.parse(respStr);
            tok = o?.data?.token || o?.token || null;
        } catch { /* yoksay */ }

        if (tok) {
            const safe = String(tok).replace(/'/g, "\\'");
            $(`#tokenAksiyon-${id}`).html(
                `<button type="button" class="btn btn-success btn-sm" onclick="useToken('${safe}')">
                    <i class="bi bi-shield-check"></i> Token'ı Authorize'a Koy
                </button>`
            );
        }
    }

    function useToken(tok) {
        $('#authToken').val(tok);
        localStorage.setItem(TOKEN_KEY, tok);
        refreshAuthState();
        $('html, body').animate({ scrollTop: 0 }, 300);
        showToast('Token Authorize alanına eklendi', 'success');
    }

    // Birim API personellerini yükle
    function loadPersoneller() {
        $.ajax({
            url: '', method: 'POST', data: { action: 'personel_list' }, dataType: 'json',
            success: function (r) {
                const $b = $('#personelBody');
                if (!r.success) { $b.html('<div class="text-danger small">Personeller yüklenemedi.</div>'); return; }
                const rows = r.data || [];
                if (!rows.length) {
                    $b.html('<div class="text-muted small"><i class="bi bi-info-circle"></i> Biriminize ait API personeli bulunamadı.</div>');
                    return;
                }
                let html = '<div class="table-responsive"><table class="table table-sm align-middle mb-0">' +
                    '<thead><tr><th>Ad Soyad</th><th>Kullanıcı Adı</th><th>Alt Bayi</th><th>Bayi Kodu</th><th>Token Süresi</th><th class="text-end">İşlem</th></tr></thead><tbody>';
                rows.forEach(p => {
                    const durumBadge = String(p.tokenDurum) === '1'
                        ? '<span class="badge text-bg-success">Aktif</span>'
                        : '<span class="badge text-bg-secondary">Yok</span>';
                    html += `<tr>
                        <td><strong>${escapeHtml(p.adsoyad || '-')}</strong></td>
                        <td><code>${escapeHtml(p.kullaniciadi || '-')}</code></td>
                        <td>${escapeHtml(p.altbayi || '-')}</td>
                        <td>${escapeHtml(p.bayikodu || '-')}</td>
                        <td>${durumBadge} <span class="text-muted small">${escapeHtml(p.tokenSuresi || '')}</span></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-info text-white" onclick="personelLogin(${p.id}, this, false)"
                                        title="Kayıtlı token geçerliyse onu kullanır, Digiturk'e istek göndermez">
                                    <i class="bi bi-box-arrow-in-right"></i> Token Al
                                </button>
                                <button type="button" class="btn btn-outline-warning" onclick="personelLogin(${p.id}, this, true)"
                                        title="Digiturk'ten YENİ token ister — bayinin günlük 10 login kotasından düşer">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                        </td>
                    </tr>`;
                });
                html += '</tbody></table></div>';
                $b.html(html);
            },
            error: function () { $('#personelBody').html('<div class="text-danger small">Sunucu hatası.</div>'); }
        });
    }

    // Seçili personel ile login ol, token'ı Authorize'a koy.
    // Digiturk login'i bayi başına günlük 10 istekle sınırlı olduğundan varsayılan
    // davranış kayıtlı geçerli token'ı kullanmaktır; zorla=true yeni token ister.
    function personelLogin(pid, btn, zorla) {
        const $btn = $(btn);
        const eski = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Giriş...');
        $.ajax({
            url: '', method: 'POST', dataType: 'json',
            data: { action: 'personel_login', personel_id: pid, zorla: zorla ? 1 : 0 },
            success: function (r) {
                if (r.success && r.token) {
                    useToken(r.token);
                    loadPersoneller(); // token süresi/durum güncellensin
                    if (r.message) showToast(r.message, r.onbellek ? 'info' : 'success');
                } else {
                    showToast(r.message || 'Token alınamadı', 'error');
                }
            },
            error: function () { showToast('Sunucu hatası', 'error'); },
            complete: function () { $btn.prop('disabled', false).html(eski); }
        });
    }

    // Postman Collection v2.1 üret ve indir
    function downloadPostman() {
        if (!allEndpoints.length) { showToast('İndirilecek endpoint yok', 'warning'); return; }

        // Kategoriye göre klasörler
        const gruplar = {};
        allEndpoints.forEach(ep => {
            const k = ep.APIEndpointler_Kategori || 'Diğer';
            (gruplar[k] = gruplar[k] || []).push(ep);
        });

        const klasorler = Object.keys(gruplar).map(kategori => ({
            name: kategori,
            item: gruplar[kategori].map(ep => {
                const metod   = (ep.APIEndpointler_HttpMetod || 'GET').toUpperCase();
                const hasBody = ['POST','PUT','PATCH','DELETE'].includes(metod);
                const gwPath  = (ep.gateway_path || '').replace(/^\/+/, '');
                const segments = gwPath.split('/').filter(Boolean);

                const header = [{ key: 'Accept', value: 'application/json' }];
                if (hasBody) header.push({ key: 'Content-Type', value: 'application/json' });
                header.push({ key: 'Token', value: '{{token}}' });

                const istek = {
                    method: metod,
                    header: header,
                    url: {
                        raw: '{{base_url}}/api/' + gwPath,
                        host: ['{{base_url}}'],
                        path: ['api', ...segments]
                    },
                    description: ep.APIEndpointler_Aciklama || ''
                };

                if (hasBody) {
                    const ham = (ep.APIEndpointler_ParametreOrnek || '').trim() || '{}';
                    istek.body = {
                        mode: 'raw',
                        raw: ham,
                        options: { raw: { language: 'json' } }
                    };
                }

                return {
                    name: (ep.APIEndpointler_Aciklama || gwPath || metod),
                    request: istek
                };
            })
        }));

        const collection = {
            info: {
                name: <?= json_encode($siteTitle, JSON_UNESCAPED_UNICODE) ?> + ' - API',
                description: 'API Swagger sayfasından üretilmiştir.',
                schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'
            },
            item: klasorler,
            variable: [
                { key: 'base_url', value: location.origin, type: 'string' },
                { key: 'token', value: $('#authToken').val().trim(), type: 'string' }
            ]
        };

        const blob = new Blob([JSON.stringify(collection, null, 2)], { type: 'application/json' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'api-postman-collection.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(a.href);
        showToast('Postman koleksiyonu indirildi', 'success');
    }
</script>
</body>
</html>
