<?php
/**
 * Admin Panel - Destek Taleplerim
 * Kaynak API: destek.ornekyazilim.com (DestekHelper)
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/DestekHelper.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Destek Taleplerim';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

// Destek talepleri tüm giriş yapmış kullanıcılara açık (sayfa-yetki kontrolü yok, requireAuth yeterli)

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ─── AJAX: liste ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'list') {
            if (!DestekHelper::aktifMi()) {
                echo json_encode(['success' => false, 'message' => 'Destek API yapılandırılmamış. Site Ayarları > Destek API bölümünü doldurun.']);
                exit;
            }
            $res = DestekHelper::listele();
            if (!($res['success'] ?? false)) {
                echo json_encode(['success' => false, 'message' => $res['message'] ?? 'Talepler alınamadı.']);
                exit;
            }
            echo json_encode(['success' => true, 'data' => $res['data'] ?? []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ─── Meta (durum/öncelik filtre listeleri + kapalı durum adları) ───
$metaRes     = DestekHelper::aktifMi() ? DestekHelper::meta() : ['success' => false];
$meta        = ($metaRes['success'] ?? false) ? ($metaRes['data'] ?? []) : [];
$kategoriler = $meta['kategoriler'] ?? [];
$durumlar    = $meta['durumlar'] ?? [];
$oncelikler  = $meta['oncelikler'] ?? [];
$kapaliDurumAdlari = array_values(array_map(
    fn($d) => $d['ad'],
    array_filter($durumlar, fn($d) => (int)($d['kapatma'] ?? 0) === 1)
));

// ─── DEBUG (?debug=1) ───
$debugAktif = isset($_GET['debug']);
$debugData  = [];
if ($debugAktif) {
    $debugData['aktif_mi']   = DestekHelper::aktifMi();
    $debugData['meta_call']  = DestekHelper::sonDebug(); // meta çağrısından kalan
    $listRes = DestekHelper::aktifMi() ? DestekHelper::listele() : ['success' => false, 'message' => 'API kapalı'];
    $debugData['list_call']  = DestekHelper::sonDebug(); // listele çağrısından kalan
    $debugData['list_sonuc'] = $listRes;
    $debugData['eposta']     = DestekHelper::aktifMi() ? DestekHelper::eposta() : '(yok)';
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
        .durum-badge, .oncelik-badge { padding: .3rem .6rem; border-radius: 1rem; font-size: .8rem; font-weight: 600; color: #fff; white-space: nowrap; }
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

                <?php if ($debugAktif): ?>
                <!-- ═══ DEBUG PANELİ (?debug=1) ═══ -->
                <div class="card card-warning card-outline mb-3">
                    <div class="card-header"><h3 class="card-title"><i class="bi bi-bug"></i> Destek API Debug</h3></div>
                    <div class="card-body">
                        <p class="mb-2">
                            <strong>API Aktif mi:</strong>
                            <?= $debugData['aktif_mi'] ? '<span class="badge text-bg-success">EVET</span>' : '<span class="badge text-bg-danger">HAYIR</span>' ?>
                            &nbsp; <strong>E-posta:</strong> <code><?= htmlspecialchars($debugData['eposta']) ?></code>
                        </p>
                        <?php foreach (['meta_call' => 'ticket_meta çağrısı', 'list_call' => 'list_tickets çağrısı'] as $key => $baslik):
                            $d = $debugData[$key] ?? []; ?>
                        <div class="mb-3">
                            <h6 class="fw-bold"><?= $baslik ?></h6>
                            <table class="table table-sm table-bordered mb-1">
                                <tr><th style="width:140px">HTTP Kodu</th>
                                    <td><span class="badge text-bg-<?= (($d['http_code'] ?? 0) == 200) ? 'success' : 'danger' ?>"><?= (int)($d['http_code'] ?? 0) ?></span></td></tr>
                                <tr><th>URL</th><td><code><?= htmlspecialchars($d['url'] ?? '-') ?></code></td></tr>
                                <tr><th>cURL Hatası</th><td><?= htmlspecialchars($d['curl_err'] ?? '') ?: '<span class="text-muted">yok</span>' ?></td></tr>
                                <tr><th>Gönderilen</th><td><code style="white-space:pre-wrap"><?= htmlspecialchars($d['gonderilen'] ?? '-') ?></code></td></tr>
                                <tr><th>Ham Yanıt</th><td><pre class="mb-0" style="max-height:250px;overflow:auto;white-space:pre-wrap"><?= htmlspecialchars($d['ham_yanit'] ?? '-') ?></pre></td></tr>
                            </table>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="info-box text-bg-secondary">
                            <span class="info-box-icon"><i class="bi bi-ticket-detailed"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Talep</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-envelope-open"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Açık</span>
                                <span class="info-box-number" id="stat-acik">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-arrow-repeat"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">İşlemde</span>
                                <span class="info-box-number" id="stat-islemde">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Kapalı</span>
                                <span class="info-box-number" id="stat-kapali">0</span>
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
                                    <label class="form-label">Kategori</label>
                                    <select class="form-select select2" name="kategori" id="filter_kategori">
                                        <option value="">Tümü</option>
                                        <?php foreach ($kategoriler as $k): ?>
                                            <option value="<?= htmlspecialchars($k['ad']) ?>"><?= htmlspecialchars($k['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Öncelik</label>
                                    <select class="form-select select2" name="oncelik" id="filter_oncelik">
                                        <option value="">Tümü</option>
                                        <?php foreach ($oncelikler as $o): ?>
                                            <option value="<?= htmlspecialchars($o['ad']) ?>"><?= htmlspecialchars($o['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Durum</label>
                                    <select class="form-select select2" name="durum" id="filter_durum">
                                        <option value="">Tümü</option>
                                        <?php foreach ($durumlar as $d): ?>
                                            <option value="<?= htmlspecialchars($d['ad']) ?>"><?= htmlspecialchars($d['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Başlangıç Tarihi</label>
                                    <input type="date" class="form-control" id="filter_baslangic">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Bitiş Tarihi</label>
                                    <input type="date" class="form-control" id="filter_bitis">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x-circle me-1"></i> Temizle</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Liste -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Destek Talepleri</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-outline-secondary btn-sm me-1" id="btnYenile"><i class="bi bi-arrow-clockwise"></i> Yenile</button>
                            <a href="/admin/destek-talep-detay?yeni=1" class="btn btn-primary btn-sm">
                                <i class="bi bi-plus-circle"></i> Yeni Talep
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover" style="width:100%">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Talep No</th>
                                    <th>Konu</th>
                                    <th>Kategori</th>
                                    <th>Öncelik</th>
                                    <th>Durum</th>
                                    <th>Kullanıcı</th>
                                    <th>Tarih</th>
                                    <th style="width:80px">İşlem</th>
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
    // Kapali durumlar meta'daki kapatma bayragindan gelir; acik ve islemde
    // ayrimi durum adina gore yapilir, durum id'leri koda gomulmez.
    const KAPALI_DURUMLAR = <?= json_encode($kapaliDurumAdlari, JSON_UNESCAPED_UNICODE) ?>;
    const DURUM_ACIK      = 'Açık';
    const DURUM_ISLEMDE   = 'İşlemde';
    let dataTable, tumVeri = [];

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }

    function istatistikGuncelle(veri) {
        $('#stat-toplam').text(veri.length);
        $('#stat-kapali').text(veri.filter(t => KAPALI_DURUMLAR.includes(t.durum_ad)).length);
        $('#stat-acik').text(veri.filter(t => t.durum_ad === DURUM_ACIK).length);
        $('#stat-islemde').text(veri.filter(t => t.durum_ad === DURUM_ISLEMDE).length);
    }

    function tabloDoldur(veri) {
        const rows = veri.map(t => {
            const talepId      = parseInt(t.Tickets_id) || 0;
            const detayLink    = `/admin/destek-talep-detay?id=${talepId}`;
            const ccSimge      = Number(t.cc_mi) === 1
                ? ' <i class="bi bi-people-fill text-secondary ms-1" title="Bu talebe bilgilendirme (CC) amacıyla eklendiniz"></i>'
                : '';
            const konu         = `<a href="${detayLink}" class="text-decoration-none fw-semibold">${esc(t.Tickets_konu || '')}</a>${ccSimge}`;
            const kategoriHtml = t.kategori_ad
                ? `<span class="badge" style="background:${esc(t.kategori_renk || '#6c757d')}">${esc(t.kategori_ad)}</span>`
                : '-';
            const durumBadge   = `<span class="durum-badge" style="background:${esc(t.durum_renk || '#6c757d')}">${esc(t.durum_ad || '-')}</span>`;
            const oncelikBadge = `<span class="oncelik-badge" style="background:${esc(t.oncelik_renk || '#6c757d')}">${esc(t.oncelik_ad || '-')}</span>`;
            const olusturan    = (String(t.olusturan_ad || '') + ' ' + String(t.olusturan_soyad || '')).trim();
            const detayBtn     = `<a href="${detayLink}" class="btn btn-sm btn-outline-primary" title="Detay"><i class="bi bi-eye"></i></a>`;
            return [
                talepId,
                `<code>${esc(t.Tickets_no || '')}</code>`,
                konu,
                kategoriHtml,
                oncelikBadge,
                durumBadge,
                olusturan ? esc(olusturan) : '-',
                esc(t.acilis_tarihi || '-'),
                detayBtn
            ];
        });
        dataTable.clear().rows.add(rows).draw();
    }

    function filtreUygula() {
        const kategori = $('#filter_kategori').val() || '';
        const durum    = $('#filter_durum').val() || '';
        const oncelik  = $('#filter_oncelik').val() || '';
        const bas      = $('#filter_baslangic').val() || '';
        const bit      = $('#filter_bitis').val() || '';
        let veri = tumVeri.filter(t => {
            if (kategori && t.kategori_ad !== kategori) return false;
            if (durum && t.durum_ad !== durum) return false;
            if (oncelik && t.oncelik_ad !== oncelik) return false;
            if (bas || bit) {
                const tarih = String(t.acilis_tarihi || '').substring(0, 10);
                if (!tarih) return false;
                if (bas && tarih < bas) return false;
                if (bit && tarih > bit) return false;
            }
            return true;
        });
        tabloDoldur(veri);
        istatistikGuncelle(veri);
    }

    function listeYukle() {
        $.post('', { action: 'list' }, function (res) {
            if (!res.success) {
                Swal.fire({ icon: 'error', title: 'Hata', text: res.message || 'Talepler alınamadı.' });
                tumVeri = [];
                tabloDoldur([]);
                istatistikGuncelle([]);
                return;
            }
            tumVeri = res.data || [];
            filtreUygula();
        }, 'json').fail(function () {
            Swal.fire({ icon: 'error', title: 'Bağlantı Hatası', text: 'Sunucuya ulaşılamadı.' });
        });
    }

    $(document).ready(function () {
        // Liste görüntülendi → header bildirim badge'ini sıfırla
        localStorage.setItem('destek_son_kontrol', new Date().toISOString());

        $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

        dataTable = $('#kayitTable').DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'desc']],
            columnDefs: [{ orderable: false, targets: [3, 4, 5, 8] }],
            pageLength: 25
        });

        listeYukle();

        $('#filterForm').on('submit', function (e) { e.preventDefault(); filtreUygula(); });
        $('#filter_kategori, #filter_durum, #filter_oncelik').on('change', filtreUygula);
        $('#filter_baslangic, #filter_bitis').on('change', filtreUygula);
        $('#clearFilters').on('click', function () {
            $('#filter_kategori, #filter_durum, #filter_oncelik').val('').trigger('change.select2');
            $('#filter_baslangic, #filter_bitis').val('');
            filtreUygula();
        });
        $('#btnYenile').on('click', listeYukle);
    });
</script>
</body>
</html>
