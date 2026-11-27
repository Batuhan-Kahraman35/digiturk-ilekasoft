<?php
/**
 * Admin Panel - Başvuru Durum Yönetimi
 * Üç tablo tek sayfada: BasvuruDurum + BasvuruSurecDurum + BasvuruIletisimDurum (departman eşleşmesi: BasvuruIletisimDurumDepartmanlari)
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
                // Bu durumdaki başvuruda formdaki alanlar kilitlenir (yalnız KilitliDuzenle yetkili alanlar düzenlenir)
                'kilit' => 'BasvuruDurum_AlanKilitle',
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
        'iletisim' => [
            'tablo' => 'BasvuruIletisimDurum',
            'pk'    => 'BasvuruIletisimDurum_id',
            'cols'  => [
                'mesaj' => 'BasvuruIletisimDurum_Mesaj',
                'renk'  => 'BasvuruIletisimDurum_Renk',
                // Bu durum seçilince başvuru formunda tarih/saat alanı açılır ve zorunlu olur (ör. Randevu)
                'tarih' => 'BasvuruIletisimDurum_TarihSecilir',
                // "Kendisi": İletişim Geçmişi'ndeki kaydı yazan kullanıcı kendi kaydını düzenleyebilir
                'kendisi' => 'BasvuruIletisimDurum_KendisiDegistirir',
            ],
        ],
    ];
    return $map[$type] ?? null;
}

/**
 * İletişim durumu ↔ departman ara tabloları (kayıt yoksa ilgili işlem herkese açık):
 *   secen     → BasvuruIletisimDurumDepartmanlari: durumu seçebilen departmanlar
 *   degistiren → BasvuruIletisimDurumDegistirenDepartmanlari: bu durumdaki başvurunun iletişim kaydını değiştirebilenler
 */
function iletisimAraTablo(string $tur): string {
    return ['secen' => 'BasvuruIletisimDurumDepartmanlari', 'degistiren' => 'BasvuruIletisimDurumDegistirenDepartmanlari'][$tur]
        ?? throw new InvalidArgumentException('Geçersiz departman türü');
}

/** İletişim durumu → departman eşleşmeleri: [durum_id => [[id, ad], ...]] */
function iletisimDepartmanlari($db, ?int $durumId = null, string $tur = 'secen'): array {
    $t = iletisimAraTablo($tur);
    $sql = "SELECT x.{$t}_IletisimDurum_id AS durum_id, d.departman_id AS id, d.departman_adi AS ad
            FROM {$t} x
            INNER JOIN Kullanici_Departmanlar d ON d.departman_id = x.{$t}_Departman_id
            WHERE x.Durum = 1" . ($durumId ? " AND x.{$t}_IletisimDurum_id = ?" : "") . "
            ORDER BY d.departman_adi";
    $out = [];
    foreach ($db->fetchAll($sql, $durumId ? [$durumId] : []) as $r) {
        $out[(int)$r['durum_id']][] = ['id' => (int)$r['id'], 'ad' => $r['ad']];
    }
    return $out;
}

$departmanlar = $db->fetchAll("SELECT departman_id AS id, departman_adi AS ad FROM Kullanici_Departmanlar WHERE departman_durum = 1 ORDER BY departman_adi");

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
                    } elseif ($type === 'iletisim') {
                        $where[]  = "t.{$c['mesaj']} LIKE ?";
                        $params[] = "%$search%";
                    } else {
                        $where[]  = "(t.{$c['mesaj']} LIKE ? OR t.{$c['aciklama']} LIKE ?)";
                        $params[] = "%$search%";
                        $params[] = "%$search%";
                    }
                }

                $whereClause = implode(" AND ", $where);

                if ($type === 'durum') {
                    $select = "t.{$cfg['pk']} AS id, t.{$c['kod']} AS kod, t.{$c['mesaj']} AS mesaj, t.{$c['renk']} AS renk, t.{$c['kilit']} AS kilit";
                    $order  = "t.{$c['kod']}";
                } elseif ($type === 'iletisim') {
                    $select = "t.{$cfg['pk']} AS id, t.{$c['mesaj']} AS mesaj, t.{$c['renk']} AS renk, t.{$c['tarih']} AS tarih, t.{$c['kendisi']} AS kendisi, t.Durum AS aktif,
                               (SELECT COUNT(*) FROM Basvurular b WHERE b.Basvurular_IletisimDurum_ID = t.{$cfg['pk']}) AS kullanim";
                    $order  = "t.{$c['mesaj']}";
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

                if ($type === 'iletisim') {
                    $eslesme    = iletisimDepartmanlari($db);
                    $degistiren = iletisimDepartmanlari($db, null, 'degistiren');
                    foreach ($list as &$satir) {
                        $satir['departmanlar'] = $eslesme[(int)$satir['id']] ?? [];
                        $satir['degistiren']   = $degistiren[(int)$satir['id']] ?? [];
                    }
                    unset($satir);
                }

                echo json_encode(['success' => true, 'data' => $list], JSON_UNESCAPED_UNICODE);
                break;

            case 'get':
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("SELECT * FROM {$cfg['tablo']} WHERE {$cfg['pk']} = ?", [$id]);
                if ($row && $type === 'iletisim') {
                    $row['departmanlar'] = array_column(iletisimDepartmanlari($db, $id)[$id] ?? [], 'id');
                    $row['degistiren']   = array_column(iletisimDepartmanlari($db, $id, 'degistiren')[$id] ?? [], 'id');
                }
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
                        $c['kilit'] => !empty($_POST['alan_kilitle']) ? 1 : 0,
                    ];
                } elseif ($type === 'iletisim') {
                    $mesaj = trim($_POST['mesaj'] ?? '');
                    if ($mesaj === '') {
                        echo json_encode(['success' => false, 'message' => 'Durum adı zorunludur']);
                        break;
                    }
                    $data = [
                        $c['mesaj'] => mb_substr($mesaj, 0, 100),
                        $c['renk']  => $renk,
                        $c['tarih'] => !empty($_POST['tarih_secilir']) ? 1 : 0,
                        // Değiştirebilen listesindeki "kendisi" seçeneği departman değil, bayraktır
                        $c['kendisi'] => in_array('kendisi', (array)($_POST['degistiren_departmanlar'] ?? []), true) ? 1 : 0,
                        'Durum'     => !empty($_POST['aktif']) ? 1 : 0,
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
                    $mesajOut = 'Kayıt güncellendi';
                } else {
                    $data['OlusturanKullanici']   = $user['kullanici_id'];
                    $data['OlusturmaTarihi']      = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');

                    if ($type === 'surec') {
                        // Süreç sorgusundaki requestStatusCode doğrudan ID'ye yazıldığı için
                        // yeni kayıtta ID otomatik verilmez; Digiturk'ün API kodu elle girilir.
                        $apiKod = (int)($_POST['api_kod'] ?? 0);
                        if ($apiKod <= 0) {
                            echo json_encode(['success' => false, 'message' => 'API Kodu zorunludur (Digiturk requestStatusCode, pozitif sayı)']);
                            break;
                        }
                        $var = $db->fetchOne("SELECT {$c['mesaj']} AS ad FROM {$cfg['tablo']} WHERE {$cfg['pk']} = ?", [$apiKod]);
                        if ($var) {
                            echo json_encode(['success' => false, 'message' => "API Kodu $apiKod zaten kayıtlı: " . $var['ad']]);
                            break;
                        }
                        $data = [$cfg['pk'] => $apiKod] + $data;
                        $db->execute("SET IDENTITY_INSERT dbo.{$cfg['tablo']} ON");
                        try {
                            $db->insert($cfg['tablo'], $data);
                        } finally {
                            $db->execute("SET IDENTITY_INSERT dbo.{$cfg['tablo']} OFF");
                        }
                        $id = $apiKod;
                    } else {
                        $id = (int)$db->insert($cfg['tablo'], $data);
                    }
                    $mesajOut = 'Kayıt eklendi';
                }

                // İletişim durumu → seçebilen / değiştirebilen departmanlar (boş = herkes). Listeler tamamen yeniden yazılır.
                if ($type === 'iletisim' && $id > 0) {
                    $gecerli = array_map('intval', array_column($departmanlar, 'id'));
                    foreach (['secen' => 'departmanlar', 'degistiren' => 'degistiren_departmanlar'] as $tur => $alan) {
                        $t = iletisimAraTablo($tur);
                        $secilen = array_values(array_unique(array_filter(
                            array_map('intval', (array)($_POST[$alan] ?? [])),
                            fn($d) => in_array($d, $gecerli, true)
                        )));
                        $db->execute("DELETE FROM {$t} WHERE {$t}_IletisimDurum_id = ?", [$id]);
                        foreach ($secilen as $depId) {
                            $db->insert($t, [
                                "{$t}_IletisimDurum_id" => $id,
                                "{$t}_Departman_id"     => $depId,
                                'OlusturanKullanici'    => $user['kullanici_id'],
                                'OlusturmaTarihi'       => date('Y-m-d H:i:s'),
                                'Durum'                 => 1,
                            ]);
                        }
                    }
                }

                echo json_encode(['success' => true, 'message' => $mesajOut]);
                break;

            // Tablo içi switch: başvuru durumunda "Alan Kilitle" bayrağı
            case 'kilit_toggle':
                if ($type !== 'durum') {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz tablo türü']);
                    break;
                }
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                $db->update($cfg['tablo'], [
                    $cfg['cols']['kilit']  => !empty($_POST['deger']) ? 1 : 0,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], [$cfg['pk'] => (int)($_POST['id'] ?? 0)]);
                echo json_encode(['success' => true, 'message' => !empty($_POST['deger'])
                    ? 'Bu durumdaki başvurularda form alanları kilitlenir' : 'Alan kilidi kaldırıldı']);
                break;

            // Tablo içi switch: iletişim durumunda tarih/saat seçimi aç/kapat
            case 'tarih_toggle':
                if ($type !== 'iletisim') {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz tablo türü']);
                    break;
                }
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                $db->update($cfg['tablo'], [
                    $cfg['cols']['tarih']  => !empty($_POST['deger']) ? 1 : 0,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], [$cfg['pk'] => (int)($_POST['id'] ?? 0)]);
                echo json_encode(['success' => true, 'message' => !empty($_POST['deger'])
                    ? 'Bu durumda tarih/saat seçimi zorunlu' : 'Tarih/saat seçimi kapatıldı']);
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
                if ($type === 'iletisim') {
                    // Başvuruda veya iletişim geçmişinde kullanılan durum silinmez → pasif yapılır
                    $kullanim = (int)($db->fetchOne("
                        SELECT (SELECT COUNT(*) FROM Basvurular WHERE Basvurular_IletisimDurum_ID = ?)
                             + (SELECT COUNT(*) FROM BasvuruIletisimGecmisi WHERE BasvuruIletisimGecmisi_IletisimDurum_ID = ?) AS c
                    ", [$id, $id])['c'] ?? 0);
                    if ($kullanim > 0) {
                        echo json_encode(['success' => false, 'message' => "Bu durum $kullanim kayıtta kullanılıyor, silinemez. Düzenleyip pasif yapabilirsiniz."]);
                        break;
                    }
                    foreach (['secen', 'degistiren'] as $tur) {
                        $t = iletisimAraTablo($tur);
                        $db->execute("DELETE FROM {$t} WHERE {$t}_IletisimDurum_id = ?", [$id]);
                    }
                }
                $db->delete($cfg['tablo'], [$cfg['pk'] => $id]);
                echo json_encode(['success' => true, 'message' => 'Kayıt silindi']);
                break;

            case 'stats':
                $stats = [
                    'durum'    => $db->fetchOne("SELECT COUNT(*) AS c FROM BasvuruDurum")['c'] ?? 0,
                    'surec'    => $db->fetchOne("SELECT COUNT(*) AS c FROM BasvuruSurecDurum")['c'] ?? 0,
                    'iletisim' => $db->fetchOne("SELECT COUNT(*) AS c FROM BasvuruIletisimDurum WHERE Durum = 1")['c'] ?? 0,
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
                    <div class="col-md-4">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-flag"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Başvuru Durumu</span>
                                <span class="info-box-number" id="stat-durum">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-diagram-3"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Süreç Durumu</span>
                                <span class="info-box-number" id="stat-surec">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-telephone"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">İletişim Durumu (aktif)</span>
                                <span class="info-box-number" id="stat-iletisim">0</span>
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
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-iletisim-btn" data-bs-toggle="tab" data-bs-target="#tab-iletisim" type="button" role="tab">
                            <i class="bi bi-telephone"></i> İletişim Durumları
                        </button>
                    </li>
                </ul>

                <div class="tab-content bg-body p-3 border border-top-0 rounded-bottom">

                    <!-- ============ TAB: BAŞVURU DURUMLARI ============ -->
                    <div class="tab-pane fade show active" id="tab-durum" role="tabpanel">
                        <div class="alert alert-info py-2 mb-3">
                            <div><i class="bi bi-cloud-download"></i> <strong>Kaynak: API.</strong> Başvuru durumları Digiturk API'sinden gelir.</div>
                            <ul class="mb-0 mt-1 small">
                                <li>Sipariş yanıtındaki <code>responseCode</code>, <strong>Durum Kodu</strong> ile eşleştirilir. Kodu değiştirirseniz o yanıtla gelen başvurular bu duruma bağlanmaz.</li>
                                <li>Aynı Durum Kodu iki kayıtta varsa yalnız ilki kullanılır; kodlar tekil olmalıdır.</li>
                                <li>TAMAM durumları (kod 0 ve 200) başvuruyu satış sayar ve formdaki alanları kilitler.</li>
                            </ul>
                        </div>
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
                                            <th class="text-center" style="width:120px" title="Bu durumdaki başvuruda formdaki alanlar kilitlenir; yalnız 'kilitliyken düzenlenir' yetkili alanlar değişir">Alan Kilitle</th>
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
                        <div class="alert alert-info py-2 mb-3">
                            <div><i class="bi bi-cloud-download"></i> <strong>Kaynak: API.</strong> Süreç durumları Digiturk API'sinden gelir.</div>
                            <ul class="mb-0 mt-1 small">
                                <li>Süreç sorgusundaki <code>requestStatusCode</code>, <strong>API Kodu</strong> kolonundaki numarayla başvuruya yazılır. Yeni kayıtta API Kodu Digiturk'ün kod listesindeki değerle girilir ve sonradan değiştirilemez.</li>
                                <li><strong>Sonuçlandı</strong> işaretli süreçler (iptal, tamamlandı vb.) süreç sorgulama cron'unda tekrar sorgulanmaz.</li>
                                <li>Süreç sorgusu başarılı olan başvurunun Başvuru Durumu otomatik olarak TAMAM yapılır.</li>
                            </ul>
                        </div>
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
                                            <th style="width:110px" title="Digiturk requestStatusCode — süreç sorgusunda bu numara başvuruya yazılır">API Kodu</th>
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

                    <!-- ============ TAB: İLETİŞİM DURUMLARI ============ -->
                    <div class="tab-pane fade" id="tab-iletisim" role="tabpanel">
                        <div class="alert alert-success py-2 mb-3">
                            <div><i class="bi bi-telephone"></i> <strong>Kaynak: Meta ve web.</strong> İletişim durumları Meta reklam formlarından ve web sitesinden gelen başvuruların takibi için kullanılır. Digiturk API'sine gönderilmez.</div>
                            <ul class="mb-0 mt-1 small">
                                <li>Temsilci başvuru formundaki <strong>İletişim Geçmişi</strong>'nden durum seçer; her seçim kim ve ne zaman bilgisiyle ayrı kayıt olarak saklanır.</li>
                                <li><strong>Seçebilen Departmanlar</strong> boşsa durumu herkes seçebilir; admin her zaman tüm durumları görür.</li>
                                <li><strong>Değiştirebilen Departmanlar</strong>: İletişim Geçmişi'nde bu durumdaki mevcut kayıtların durumunu ve notunu düzenleyebilen departmanlar (kalem ikonu). <strong>Kendisi</strong> seçilirse kaydı yazan kullanıcı, departmanı listede olmasa da kendi kaydını düzenler. Boşsa yalnız admin düzenler; her düzenleme kim ve ne zaman bilgisiyle gösterilir.</li>
                                <li><strong>Tarih/Saat</strong> açık olan durumda (ör. Randevu) tarih ve saat girmek zorunludur.</li>
                                <li>Kullanılmış durum silinemez; listeden kaldırmak için <strong>pasif</strong> yapın, geçmiş kayıtlar korunur.</li>
                            </ul>
                        </div>
                        <!-- Filtre -->
                        <div class="card card-success card-outline mb-3">
                            <div class="card-header">
                                <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterIletisim"><i class="bi bi-chevron-down"></i></button>
                                </div>
                            </div>
                            <div class="card-body collapse" id="filterIletisim">
                                <form class="filterForm" data-type="iletisim">
                                    <div class="row g-3">
                                        <div class="col-md-9">
                                            <label class="form-label">Ara</label>
                                            <input type="text" class="form-control f-search" placeholder="Durum adı...">
                                        </div>
                                        <div class="col-md-3 d-flex align-items-end gap-2">
                                            <button type="submit" class="btn btn-success"><i class="bi bi-search"></i> Filtrele</button>
                                            <button type="reset" class="btn btn-secondary f-clear"><i class="bi bi-x-circle"></i> Temizle</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">İletişim Durumları <small class="text-muted">(departman seçilmeyen durumu herkes seçebilir)</small></h3>
                                <div class="card-tools">
                                    <?php if ($permissions['can_add']): ?>
                                    <button type="button" class="btn btn-success btn-sm" onclick="yeniKayit('iletisim')">
                                        <i class="bi bi-plus-circle"></i> Yeni Ekle
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <table id="iletisimTable" class="table table-bordered table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Durum</th>
                                            <th>Seçebilen Departmanlar</th>
                                            <th title="İletişim Geçmişi'nde bu durumdaki kayıtları (durum + not) düzenleyebilen departmanlar; boşsa yalnız admin">Değiştirebilen Departmanlar</th>
                                            <th class="text-center" style="width:120px" title="Bu durum seçilince başvuru formunda tarih/saat alanı açılır ve zorunlu olur">Tarih/Saat</th>
                                            <th class="text-center" style="width:90px">Aktif</th>
                                            <th class="text-end" style="width:90px">Başvuru</th>
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
                        <div class="col-md-12 only-durum">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="f_alan_kilitle" name="alan_kilitle" value="1">
                                <label class="form-check-label" for="f_alan_kilitle">Alan Kilitle <small class="text-muted">(bu durumdaki başvuruda form alanları kilitlenir — ör. TAMAM)</small></label>
                            </div>
                        </div>
                        <!-- Sadece BasvuruSurecDurum -->
                        <div class="col-md-4 only-surec">
                            <label class="form-label">API Kodu <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="f_api_kod" name="api_kod" min="1" placeholder="requestStatusCode">
                            <small class="text-muted" id="f_api_kod_not">Kayıttan sonra değiştirilemez.</small>
                        </div>
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
                        <!-- Sadece BasvuruIletisimDurum -->
                        <div class="col-md-12 only-iletisim">
                            <label class="form-label">Seçebilen Departmanlar <small class="text-muted">(boş bırakılırsa herkes seçebilir; admin her zaman seçer)</small></label>
                            <select class="form-select" id="f_departmanlar" name="departmanlar[]" multiple>
                                <?php foreach ($departmanlar as $dp): ?>
                                <option value="<?= (int)$dp['id'] ?>"><?= htmlspecialchars($dp['ad']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12 only-iletisim">
                            <label class="form-label">Değiştirebilen Departmanlar <small class="text-muted">(İletişim Geçmişi'nde bu durumdaki kayıtların durumunu/notunu düzenler; "Kendisi" = kaydı yazan kendi kaydını düzenler; boşsa yalnız admin)</small></label>
                            <select class="form-select" id="f_degistiren" name="degistiren_departmanlar[]" multiple>
                                <option value="kendisi">Kendisi (kaydı yazan kullanıcı)</option>
                                <?php foreach ($departmanlar as $dp): ?>
                                <option value="<?= (int)$dp['id'] ?>"><?= htmlspecialchars($dp['ad']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 only-iletisim">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="f_tarih_secilir" name="tarih_secilir" value="1">
                                <label class="form-check-label" for="f_tarih_secilir">Tarih/saat seçilir <small class="text-muted">(ör. Randevu — seçilince tarih zorunlu)</small></label>
                            </div>
                        </div>
                        <div class="col-md-6 only-iletisim">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="f_aktif" name="aktif" value="1" checked>
                                <label class="form-check-label" for="f_aktif">Aktif <small class="text-muted">(pasif durum formda listelenmez)</small></label>
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
    const filters = { durum: {}, surec: {}, iletisim: {} };
    const tipBaslik = {
        durum:    ['Yeni Başvuru Durumu',  'Başvuru Durumu Düzenle'],
        surec:    ['Yeni Süreç Durumu',    'Süreç Durumu Düzenle'],
        iletisim: ['Yeni İletişim Durumu', 'İletişim Durumu Düzenle']
    };

    $(document).ready(function () {
        kayitModal = new bootstrap.Modal(document.getElementById('kayitModal'));

        tables.durum    = $('#durumTable').DataTable(dtConfig([[0, 'asc']], [2,3,4,6]));
        tables.surec    = $('#surecTable').DataTable(dtConfig([[0, 'asc']], [3,4,5,7]));
        tables.iletisim = $('#iletisimTable').DataTable(dtConfig([[0, 'asc']], [1,2,3,4,8]));

        // Modal içi çoklu seçim: custom.js'in otomatik init'i dropdownParent veremez → destroy + yeniden kur
        ['#f_departmanlar', '#f_degistiren'].forEach(function (sec) {
            if ($(sec).hasClass('select2-hidden-accessible')) $(sec).select2('destroy');
            $(sec).select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#kayitModal'), placeholder: 'Herkes', allowClear: true });
        });

        loadStats();
        loadList('durum');
        loadList('surec');
        loadList('iletisim');

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
            $('#stat-iletisim').text(r.data.iletisim);
        }, 'json');
    }

    function loadList(type) {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'list', type: type, ...filters[type] },
            dataType: 'json',
            success: function (r) {
                if (r.success) ({ durum: renderDurum, surec: renderSurec, iletisim: renderIletisim })[type](r.data);
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
                bayrakSwitch('kilit-toggle', row.id, row.kilit),
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
                `<span class="kod-badge">${escapeHtml(String(row.id))}</span>`,
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

    function renderIletisim(rows) {
        const dt = tables.iletisim;
        dt.clear();
        rows.forEach(row => {
            const depRozet = (liste, bos) => (liste || []).length
                ? liste.map(d => `<span class="badge bg-secondary me-1">${escapeHtml(d.ad)}</span>`).join('')
                : `<span class="text-muted">${bos}</span>`;
            const dep = depRozet(row.departmanlar, 'Herkes');
            const kendisiRozet = Number(row.kendisi) === 1 ? '<span class="badge bg-info text-dark me-1">Kendisi</span>' : '';
            const degistiren = kendisiRozet + ((row.degistiren || []).length || !kendisiRozet
                ? depRozet(row.degistiren, 'Yalnız admin') : '');
            const tarihAcik = Number(row.tarih) === 1 ? 'checked' : '';
            const kapali = permissions.canEdit ? '' : 'disabled';
            dt.row.add([
                renkBadge(row.renk, row.mesaj),
                dep,
                degistiren,
                `<div class="form-check form-switch d-flex justify-content-center">
                    <input class="form-check-input tarih-toggle" type="checkbox" role="switch" data-id="${row.id}" ${tarihAcik} ${kapali}>
                    <label class="form-check-label"></label>
                 </div>`,
                Number(row.aktif) === 1 ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Pasif</span>',
                `<span class="d-block text-end">${escapeHtml(String(row.kullanim || 0))}</span>`,
                escapeHtml(row.GuncellemeTarihi || '-'),
                escapeHtml(row.GuncelleyenAd || '-'),
                islemBtn('iletisim', row.id)
            ]);
        });
        dt.draw();
    }

    $(document).on('change', '.tarih-toggle', function () {
        const $cb = $(this);
        const deger = $cb.is(':checked') ? 1 : 0;
        $.post('', { action: 'tarih_toggle', type: 'iletisim', id: $cb.data('id'), deger: deger }, function (r) {
            if (r.success) { showToast(r.message, 'success'); loadList('iletisim'); }
            else { $cb.prop('checked', !deger); showToast(r.message, 'error'); }
        }, 'json').fail(function () { $cb.prop('checked', !deger); showToast('Güncelleme başarısız', 'error'); });
    });

    function sonucSwitch(row) {
        const acik = Number(row.sonuc) === 1 ? 'checked' : '';
        const kapali = permissions.canEdit ? '' : 'disabled';
        return `<div class="form-check form-switch d-flex justify-content-center">
                    <input class="form-check-input sonuc-toggle" type="checkbox" role="switch" data-id="${row.id}" ${acik} ${kapali}>
                    <label class="form-check-label"></label>
                </div>`;
    }

    function bayrakSwitch(sinif, id, deger) {
        const acik = Number(deger) === 1 ? 'checked' : '';
        const kapali = permissions.canEdit ? '' : 'disabled';
        return `<div class="form-check form-switch d-flex justify-content-center">
                    <input class="form-check-input ${sinif}" type="checkbox" role="switch" data-id="${id}" ${acik} ${kapali}>
                    <label class="form-check-label"></label>
                </div>`;
    }

    $(document).on('change', '.kilit-toggle', function () {
        const $cb = $(this);
        const deger = $cb.is(':checked') ? 1 : 0;
        $.post('', { action: 'kilit_toggle', type: 'durum', id: $cb.data('id'), deger: deger }, function (r) {
            if (r.success) { showToast(r.message, 'success'); loadList('durum'); }
            else { $cb.prop('checked', !deger); showToast(r.message, 'error'); }
        }, 'json').fail(function () { $cb.prop('checked', !deger); showToast('Güncelleme başarısız', 'error'); });
    });

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
        $('.only-durum').toggle(type === 'durum');
        $('.only-surec').toggle(type === 'surec');
        $('.only-iletisim').toggle(type === 'iletisim');
        $('#f_kod').prop('required', type === 'durum');
        // Yeni süreç kaydında API Kodu zorunlu ve düzenlenebilir; düzenlemede editRecord salt okunur yapar
        $('#f_api_kod').prop({ required: type === 'surec', readonly: false });
        $('#f_api_kod_not').text('Kayıttan sonra değiştirilemez.');
        $('.col-mesaj').toggleClass('col-md-8', type !== 'iletisim').toggleClass('col-md-12', type === 'iletisim');
        $('#f_departmanlar, #f_degistiren').val(null).trigger('change');
        $('#f_aktif').prop('checked', true);
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
        $('#kayitModalLabel').text(tipBaslik[type][0]);
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
                    $('#f_alan_kilitle').prop('checked', Number(d.BasvuruDurum_AlanKilitle) === 1);
                    setRenk(d.BasvuruDurum_Renk);
                } else if (type === 'iletisim') {
                    $('#f_mesaj').val(d.BasvuruIletisimDurum_Mesaj);
                    $('#f_tarih_secilir').prop('checked', Number(d.BasvuruIletisimDurum_TarihSecilir) === 1);
                    $('#f_aktif').prop('checked', Number(d.Durum) === 1);
                    $('#f_departmanlar').val((d.departmanlar || []).map(String)).trigger('change');
                    const degistirenSecim = (d.degistiren || []).map(String);
                    if (Number(d.BasvuruIletisimDurum_KendisiDegistirir) === 1) degistirenSecim.unshift('kendisi');
                    $('#f_degistiren').val(degistirenSecim).trigger('change');
                    setRenk(d.BasvuruIletisimDurum_Renk);
                } else {
                    $('#f_api_kod').val(d.BasvuruSurecDurum_id).prop({ required: false, readonly: true });
                    $('#f_api_kod_not').text('API kodu değiştirilemez (başvurular bu numarayla bağlı).');
                    $('#f_mesaj').val(d.BasvuruSurecDurum_Mesaj);
                    $('#f_aciklama').val(d.BasvuruSurecDurum_Aciklama);
                    $('#f_sonuclandi').prop('checked', Number(d.BasvuruSurecDurum_Sonuclandi) === 1);
                    setRenk(d.BasvuruSurecDurum_Renk);
                }
                $('#kayitModalLabel').text(tipBaslik[type][1]);
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
