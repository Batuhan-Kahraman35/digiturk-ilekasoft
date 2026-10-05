<?php
/**
 * Admin Panel - Bayi Yönetimi
 * Ana Bayiler / Alt Bayiler / Personel
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';

requireAuth();

$user = Auth::user();
$db = Database::getInstance();

$currentPagefile = basename($_SERVER['PHP_SELF']);
$pagePermissions = PageAuth::checkPagePermissions(
    $user['kullanici_id'],
    $user['departman_id'],
    $currentPagefile
);

if (!$pagePermissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.']);
        exit;
    }
    PageAuth::accessDenied($pagePermissions['error'] ?? 'Bu sayfaya erişim yetkiniz bulunmamaktadır.');
}

$pageinfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle   = $pageinfo['sayfalar_sayfa_adi'] ?? 'Bayi Yönetimi';
$pageDesc    = $pageinfo['sayfalar_aciklama']  ?? '';
$menuAdi     = $pageinfo['menu_adi']           ?? null;

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// =====================================================================
// BİRİM BAZLI YETKİ KISITI
// Kısıtlı kullanıcı = !is_admin && birim_gor=1
// Birim ilişkisi KullaniciBirimYetkileri junction'ı üzerinden (çoka-çok).
// =====================================================================
$birimKisitli   = (!$pagePermissions['is_admin'] && !empty($pagePermissions['can_view_birim']));
$izinliBirimler = [];                 // kısıtlıysa izin verilen KullaniciBirim_id listesi
$anaSekmeGoster = !$birimKisitli;     // Ana Bayiler global tanım → kısıtlı kullanıcıdan gizlenir

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
    // kullanici_birim_id boşsa $izinliBirimler boş kalır → hiçbir kayıt görünmez (güvenli varsayılan)
}

function birimYetkiliMi(bool $birimKisitli, array $izinliBirimler, $birimId): bool {
    return !$birimKisitli || in_array((int)$birimId, $izinliBirimler, true);
}

/**
 * Junction (KullaniciBirimYetkileri) üzerinden birim kısıt WHERE parçası üretir (aktif tarih filtreli).
 * @return array [sqlFragment, params]
 */
function birimKisitWhere(array $izinliBirimler, string $junctionCol, string $idExpr): array {
    $ph  = implode(',', array_fill(0, count($izinliBirimler), '?'));
    $sql = "EXISTS (
        SELECT 1 FROM KullaniciBirimYetkileri kby
        WHERE kby.$junctionCol = $idExpr
          AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
          AND kby.Durum = 1
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
    )";
    return [$sql, $izinliBirimler];
}

/** Bir alt bayi/personel kaydının kısıtlı kullanıcının birimine ait olup olmadığını doğrular */
function kayitBirimYetkiliMi($db, array $izinliBirimler, string $junctionCol, int $kayitId): bool {
    if (empty($izinliBirimler)) return false;
    $ph  = implode(',', array_fill(0, count($izinliBirimler), '?'));
    $row = $db->fetchOne("
        SELECT TOP 1 1 AS v FROM KullaniciBirimYetkileri kby
        WHERE kby.$junctionCol = ?
          AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
          AND kby.Durum = 1
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
    ", array_merge([$kayitId], $izinliBirimler));
    return (bool)$row;
}

/** Kayıt sırasında seçilen birimlerin tümünün izinli olduğunu (ve en az bir tane seçildiğini) doğrular */
function birimSecimDogrula(bool $birimKisitli, array $izinliBirimler, string $birimYetkileriJson): void {
    if (!$birimKisitli) return;
    $by      = json_decode($birimYetkileriJson ?: '[]', true) ?: [];
    $secilen = array_filter(array_map(fn($x) => (int)($x['birim_id'] ?? 0), $by));
    if (empty($secilen)) throw new Exception('En az bir birim yetkisi seçmelisiniz.');
    foreach ($secilen as $bid) {
        if (!in_array($bid, $izinliBirimler, true)) throw new Exception('Yetkiniz olmayan bir birim seçemezsiniz.');
    }
}

/**
 * Personel görünürlük/yönetim kısıtı — SİLSİLE: doğrudan Personel yetkisi VEYA
 * personelin bağlı olduğu Alt Bayi yetkisi erişim sağlar. @return [sqlFragment, params]
 */
function personelBirimKisitWhere(array $izinliBirimler, string $perIdExpr, string $altBayiIdExpr): array {
    $ph  = implode(',', array_fill(0, count($izinliBirimler), '?'));
    $sql = "EXISTS (
        SELECT 1 FROM KullaniciBirimYetkileri kby
        WHERE kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
          AND kby.Durum = 1
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
          AND (kby.KullaniciBirimYetkileri_Personel_id = $perIdExpr
            OR kby.KullaniciBirimYetkileri_AltBayi_id  = $altBayiIdExpr)
    )";
    return [$sql, $izinliBirimler];
}

/**
 * Personel günlük Digiturk login limiti: boş → NULL (sınırsız), 0-10 tam sayı → limit.
 * 0 yazılırsa o personel için Digiturk'e hiç login atılmaz. Digiturk login limiti
 * kullanıcı bazlı günlük 10 olduğu için daha büyük değer anlamsızdır.
 */
function perLoginLimitOku($deger): ?int {
    $deger = trim((string)$deger);
    if ($deger === '') return null;
    if (!ctype_digit($deger) || (int)$deger > 10)
        throw new Exception('Günlük login limiti 0 ile 10 arasında bir tam sayı olmalıdır (boş = sınırsız). Digiturk kullanıcı başına günde 10 login\'e izin veriyor.');
    return (int)$deger;
}

/** Bir personelin (doğrudan Personel veya bağlı Alt Bayi silsilesiyle) kullanıcı kapsamında olup olmadığı */
function personelKayitBirimYetkiliMi($db, array $izinliBirimler, int $personelId): bool {
    if (empty($izinliBirimler)) return false;
    $ph  = implode(',', array_fill(0, count($izinliBirimler), '?'));
    $row = $db->fetchOne("
        SELECT TOP 1 1 AS v
        FROM DigiturkAltBayiPersonel p
        WHERE p.DigiturkAltBayiPersonel_Id = ?
          AND EXISTS (
              SELECT 1 FROM KullaniciBirimYetkileri kby
              WHERE kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
                AND kby.Durum = 1
                AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
                AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
                AND (kby.KullaniciBirimYetkileri_Personel_id = p.DigiturkAltBayiPersonel_Id
                  OR kby.KullaniciBirimYetkileri_AltBayi_id  = p.DigiturkAltBayiPersonel_AltBayiId)
          )
    ", array_merge([$personelId], $izinliBirimler));
    return (bool)$row;
}

/**
 * Personel kaydında birim seçim doğrulaması. Seçilen birimler izinli olmalı;
 * personel-düzeyi birim seçilmemişse bağlı Alt Bayi üzerinden erişim olmalıdır.
 */
function personelBirimSecimDogrula($db, bool $birimKisitli, array $izinliBirimler, string $json, int $altBayiId): void {
    if (!$birimKisitli) return;
    $by      = json_decode($json ?: '[]', true) ?: [];
    $secilen = array_filter(array_map(fn($x) => (int)($x['birim_id'] ?? 0), $by));
    foreach ($secilen as $bid) {
        if (!in_array($bid, $izinliBirimler, true)) throw new Exception('Yetkiniz olmayan bir birim seçemezsiniz.');
    }
    if (empty($secilen) && !kayitBirimYetkiliMi($db, $izinliBirimler, 'KullaniciBirimYetkileri_AltBayi_id', $altBayiId)) {
        throw new Exception('En az bir birim yetkisi seçmelisiniz (veya bağlı alt bayiye yetkiniz olmalı).');
    }
}

// =====================================================================
// AJAX İŞLEMLERİ
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        $action = $_POST['action'] ?? '';

        // ----- STATS -----
        if ($action === 'stats') {
            if ($birimKisitli) {
                // Ana bayi global tanım → kısıtlı kullanıcıya gösterilmez (kutular gizli)
                if (empty($izinliBirimler)) {
                    echo json_encode(['success' => true, 'data' => ['ana_toplam'=>0,'ana_aktif'=>0,'alt_toplam'=>0,'per_toplam'=>0]]);
                    exit;
                }
                [$wAlt, $pAlt] = birimKisitWhere($izinliBirimler, 'KullaniciBirimYetkileri_AltBayi_id',   'a.DigiturkAltBayiler_Id');
                // Personel sayacı listeyle aynı silsileyi kullanır (Personel + bağlı Alt Bayi)
                [$wPer, $pPer] = personelBirimKisitWhere($izinliBirimler, 'p.DigiturkAltBayiPersonel_Id', 'p.DigiturkAltBayiPersonel_AltBayiId');
                echo json_encode(['success' => true, 'data' => [
                    'ana_toplam'  => 0,
                    'ana_aktif'   => 0,
                    'alt_toplam'  => $db->fetchOne("SELECT COUNT(*) AS s FROM DigiturkAltBayiler a WHERE $wAlt", $pAlt)['s'] ?? 0,
                    'per_toplam'  => $db->fetchOne("SELECT COUNT(*) AS s FROM DigiturkAltBayiPersonel p WHERE $wPer", $pPer)['s'] ?? 0,
                ]]);
                exit;
            }
            echo json_encode(['success' => true, 'data' => [
                'ana_toplam'  => $db->fetchOne("SELECT COUNT(*) AS s FROM DigiturkAnaBayiler")['s'] ?? 0,
                'ana_aktif'   => $db->fetchOne("SELECT COUNT(*) AS s FROM DigiturkAnaBayiler WHERE Durum = 1")['s'] ?? 0,
                'alt_toplam'  => $db->fetchOne("SELECT COUNT(*) AS s FROM DigiturkAltBayiler")['s'] ?? 0,
                'per_toplam'  => $db->fetchOne("SELECT COUNT(*) AS s FROM DigiturkAltBayiPersonel")['s'] ?? 0,
            ]]);
            exit;
        }

        // =====================================================================
        // ANA BAYİLER
        // =====================================================================
        if ($action === 'ana_listele') {
            if ($birimKisitli) { echo json_encode(['success' => true, 'data' => []]); exit; }
            $search  = $_POST['search']    ?? '';
            $durum   = $_POST['durum']     ?? '';
            $where   = ["1=1"];
            $params  = [];

            if ($search !== '') {
                $where[]  = "(DigiturkAnaBayiler_Ad LIKE ? OR DigiturkAnaBayiler_BayiKodu LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            if ($durum !== '') {
                $where[]  = "Durum = ?";
                $params[] = $durum;
            }

            $rows = $db->fetchAll("
                SELECT DigiturkAnaBayiler_Id, DigiturkAnaBayiler_Ad, DigiturkAnaBayiler_BayiKodu,
                       DigiturkAnaBayiler_KullaniciAdi, DigiturkAnaBayiler_Sifre,
                       Durum,
                       CONVERT(VARCHAR(16), OlusturmaTarihi, 120) AS OlusturmaTarihi
                FROM DigiturkAnaBayiler
                WHERE " . implode(' AND ', $where) . "
                ORDER BY DigiturkAnaBayiler_Ad
            ", $params);

            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        if ($action === 'ana_ekle') {
            if ($birimKisitli) throw new Exception('Ana bayi işlemleri için yetkiniz yok.');
            if (!$pagePermissions['can_add']) throw new Exception('Ekleme yetkiniz bulunmamaktadır.');

            $ad           = trim($_POST['ad']            ?? '');
            $bayiKodu     = strtoupper(trim($_POST['bayi_kodu'] ?? ''));
            $kullaniciAdi = trim($_POST['kullanici_adi'] ?? '') ?: null;
            $sifre        = trim($_POST['sifre']         ?? '');

            if (empty($ad) || empty($bayiKodu)) throw new Exception('Ad ve Bayi Kodu zorunludur.');

            $kontrol = $db->fetchOne("SELECT DigiturkAnaBayiler_Id FROM DigiturkAnaBayiler WHERE DigiturkAnaBayiler_BayiKodu = ?", [$bayiKodu]);
            if ($kontrol) throw new Exception('Bu bayi kodu zaten kayıtlı.');

            $id = $db->insert('DigiturkAnaBayiler', [
                'DigiturkAnaBayiler_Ad'           => $ad,
                'DigiturkAnaBayiler_BayiKodu'     => $bayiKodu,
                'DigiturkAnaBayiler_KullaniciAdi' => $kullaniciAdi,
                'DigiturkAnaBayiler_Sifre'        => $sifre ?: null,
                'OlusturanKullanici'          => $user['kullanici_id'],
                'OlusturmaTarihi'             => date('Y-m-d H:i:s'),
                'GuncelleyenKullanici'        => $user['kullanici_id'],
                'GuncellemeTarihi'            => date('Y-m-d H:i:s'),
                'Durum'                       => 1,
            ]);

            echo json_encode(['success' => true, 'message' => 'Ana bayi eklendi.', 'id' => $id]);
            exit;
        }

        if ($action === 'ana_guncelle') {
            if ($birimKisitli) throw new Exception('Ana bayi işlemleri için yetkiniz yok.');
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');

            $id           = intval($_POST['id']          ?? 0);
            $ad           = trim($_POST['ad']            ?? '');
            $bayiKodu     = strtoupper(trim($_POST['bayi_kodu'] ?? ''));
            $kullaniciAdi = trim($_POST['kullanici_adi'] ?? '') ?: null;
            $sifre        = trim($_POST['sifre']         ?? '');
            $durum        = intval($_POST['durum']       ?? 1);

            if ($id <= 0 || empty($ad) || empty($bayiKodu)) throw new Exception('Geçersiz veri.');

            $kontrol = $db->fetchOne("SELECT DigiturkAnaBayiler_Id FROM DigiturkAnaBayiler WHERE DigiturkAnaBayiler_BayiKodu = ? AND DigiturkAnaBayiler_Id != ?", [$bayiKodu, $id]);
            if ($kontrol) throw new Exception('Bu bayi kodu başka bir kayıtta kullanılıyor.');

            $data = [
                'DigiturkAnaBayiler_Ad'           => $ad,
                'DigiturkAnaBayiler_BayiKodu'     => $bayiKodu,
                'DigiturkAnaBayiler_KullaniciAdi' => $kullaniciAdi,
                'GuncelleyenKullanici'        => $user['kullanici_id'],
                'GuncellemeTarihi'            => date('Y-m-d H:i:s'),
                'Durum'                       => $durum,
            ];
            if ($sifre !== '') {
                $data['DigiturkAnaBayiler_Sifre'] = $sifre ?: null;
            }

            $db->update('DigiturkAnaBayiler', $data, ['DigiturkAnaBayiler_Id' => $id]);

            echo json_encode(['success' => true, 'message' => 'Ana bayi güncellendi.']);
            exit;
        }

        if ($action === 'ana_sil') {
            if ($birimKisitli) throw new Exception('Ana bayi işlemleri için yetkiniz yok.');
            if (!$pagePermissions['can_delete']) throw new Exception('Silme yetkiniz bulunmamaktadır.');

            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Geçersiz id.');

            $altVar = $db->fetchOne("SELECT COUNT(*) AS s FROM DigiturkAltBayiler WHERE DigiturkAltBayiler_AnaBayiId = ?", [$id]);
            if (($altVar['s'] ?? 0) > 0) throw new Exception('Bu ana bayiye bağlı alt bayiler var. Önce onları silin.');

            $db->delete('DigiturkAnaBayiler', ['DigiturkAnaBayiler_Id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Ana bayi silindi.']);
            exit;
        }

        // =====================================================================
        // ALT BAYİLER
        // =====================================================================
        if ($action === 'get_ana_bayiler') {
            $rows = $db->fetchAll("SELECT DigiturkAnaBayiler_Id AS id, DigiturkAnaBayiler_Ad AS ad FROM DigiturkAnaBayiler WHERE Durum = 1 ORDER BY DigiturkAnaBayiler_Ad");
            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        if ($action === 'alt_listele') {
            $search    = $_POST['search']      ?? '';
            $durum     = $_POST['durum']       ?? '';
            $anaBayiId = $_POST['ana_bayi_id'] ?? '';
            $where     = ["1=1"];
            $params    = [];

            if ($search !== '') {
                $where[]  = "(a.DigiturkAltBayiler_Ad LIKE ? OR a.DigiturkAltBayiler_KullaniciAdi LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            if ($durum !== '') {
                $where[]  = "a.Durum = ?";
                $params[] = $durum;
            }
            if ($anaBayiId !== '') {
                $where[]  = "a.DigiturkAltBayiler_AnaBayiId = ?";
                $params[] = $anaBayiId;
            }
            if ($birimKisitli) {
                if (empty($izinliBirimler)) { echo json_encode(['success' => true, 'data' => []]); exit; }
                [$wB, $pB] = birimKisitWhere($izinliBirimler, 'KullaniciBirimYetkileri_AltBayi_id', 'a.DigiturkAltBayiler_Id');
                $where[]   = $wB;
                $params    = array_merge($params, $pB);
            }

            $rows = $db->fetchAll("
                SELECT a.DigiturkAltBayiler_Id, a.DigiturkAltBayiler_AnaBayiId,
                       a.DigiturkAltBayiler_Ad, a.DigiturkAltBayiler_KullaniciAdi,
                       a.Durum,
                       n.DigiturkAnaBayiler_Ad AS AnaBayiAd,
                       a.DigiturkAltBayiler_Sifre,
                       CONVERT(VARCHAR(16), a.OlusturmaTarihi, 120) AS OlusturmaTarihi,
                       CASE WHEN a.DigiturkAltBayiler_Sifre IS NOT NULL THEN 1 ELSE 0 END AS SifreVar
                FROM DigiturkAltBayiler a
                LEFT JOIN DigiturkAnaBayiler n ON a.DigiturkAltBayiler_AnaBayiId = n.DigiturkAnaBayiler_Id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.DigiturkAltBayiler_Ad
            ", $params);

            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        if ($action === 'alt_ekle') {
            if (!$pagePermissions['can_add']) throw new Exception('Ekleme yetkiniz bulunmamaktadır.');
            birimSecimDogrula($birimKisitli, $izinliBirimler, $_POST['birim_yetkileri'] ?? '[]');

            $anaBayiId    = intval($_POST['ana_bayi_id']   ?? 0);
            $ad           = trim($_POST['ad']              ?? '');
            $kullaniciAdi = trim($_POST['kullanici_adi']   ?? '') ?: null;
            $sifre        = trim($_POST['sifre']           ?? '');

            if ($anaBayiId <= 0 || empty($ad))
                throw new Exception('Ana bayi ve ad zorunludur.');

            if ($kullaniciAdi) {
                $kontrol = $db->fetchOne("SELECT DigiturkAltBayiler_Id FROM DigiturkAltBayiler WHERE DigiturkAltBayiler_KullaniciAdi = ?", [$kullaniciAdi]);
                if ($kontrol) throw new Exception('Bu kullanıcı adı zaten kayıtlı.');
            }

            $id = $db->insert('DigiturkAltBayiler', [
                'DigiturkAltBayiler_AnaBayiId'    => $anaBayiId,
                'DigiturkAltBayiler_Ad'           => $ad,
                'DigiturkAltBayiler_KullaniciAdi' => $kullaniciAdi,
                'DigiturkAltBayiler_Sifre'        => $sifre ?: null,
                'OlusturanKullanici'              => $user['kullanici_id'],
                'OlusturmaTarihi'                 => date('Y-m-d H:i:s'),
                'GuncelleyenKullanici'            => $user['kullanici_id'],
                'GuncellemeTarihi'                => date('Y-m-d H:i:s'),
                'Durum'                           => 1,
            ]);

            $birimYetkileri = json_decode($_POST['birim_yetkileri'] ?? '[]', true) ?: [];
            foreach ($birimYetkileri as $by) {
                $birimId = intval($by['birim_id'] ?? 0);
                if ($birimId <= 0) continue;
                $db->insert('KullaniciBirimYetkileri', [
                    'KullaniciBirimYetkileri_Birim_id'        => $birimId,
                    'KullaniciBirimYetkileri_AltBayi_id'      => $id,
                    'KullaniciBirimYetkileri_BaslangicTarihi' => $by['baslangic'] ?: null,
                    'KullaniciBirimYetkileri_BitisTarihi'     => $by['bitis'] ?: null,
                    'OlusturanKullanici'                      => $user['kullanici_id'],
                    'OlusturmaTarihi'                         => date('Y-m-d H:i:s'),
                    'GuncelleyenKullanici'                    => $user['kullanici_id'],
                    'GuncellemeTarihi'                        => date('Y-m-d H:i:s'),
                    'Durum'                                   => 1,
                ]);
            }

            echo json_encode(['success' => true, 'message' => 'Alt bayi eklendi.', 'id' => $id]);
            exit;
        }

        if ($action === 'alt_guncelle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');
            if (!$pagePermissions['is_admin']) throw new Exception('Alt bayi düzenleme yetkiniz bulunmamaktadır.');

            $id           = intval($_POST['id']            ?? 0);
            $anaBayiId    = intval($_POST['ana_bayi_id']   ?? 0);
            $ad           = trim($_POST['ad']              ?? '');
            $kullaniciAdi = trim($_POST['kullanici_adi']   ?? '') ?: null;
            $sifre        = trim($_POST['sifre']           ?? '');
            $durum        = intval($_POST['durum']         ?? 1);

            if ($id <= 0 || $anaBayiId <= 0 || empty($ad))
                throw new Exception('Geçersiz veri.');

            if ($birimKisitli) {
                if (!kayitBirimYetkiliMi($db, $izinliBirimler, 'KullaniciBirimYetkileri_AltBayi_id', $id))
                    throw new Exception('Bu kayıt için yetkiniz yok.');
                birimSecimDogrula($birimKisitli, $izinliBirimler, $_POST['birim_yetkileri'] ?? '[]');
            }

            if ($kullaniciAdi) {
                $kontrol = $db->fetchOne("SELECT DigiturkAltBayiler_Id FROM DigiturkAltBayiler WHERE DigiturkAltBayiler_KullaniciAdi = ? AND DigiturkAltBayiler_Id != ?", [$kullaniciAdi, $id]);
                if ($kontrol) throw new Exception('Bu kullanıcı adı başka bir kayıtta kullanılıyor.');
            }

            $data = [
                'DigiturkAltBayiler_AnaBayiId'    => $anaBayiId,
                'DigiturkAltBayiler_Ad'           => $ad,
                'DigiturkAltBayiler_KullaniciAdi' => $kullaniciAdi,
                'GuncelleyenKullanici'            => $user['kullanici_id'],
                'GuncellemeTarihi'                => date('Y-m-d H:i:s'),
                'Durum'                           => $durum,
            ];
            if ($sifre !== '') {
                $data['DigiturkAltBayiler_Sifre'] = $sifre ?: null;
            }

            $db->update('DigiturkAltBayiler', $data, ['DigiturkAltBayiler_Id' => $id]);

            $birimYetkileri = json_decode($_POST['birim_yetkileri'] ?? '[]', true) ?: [];
            $db->delete('KullaniciBirimYetkileri', ['KullaniciBirimYetkileri_AltBayi_id' => $id]);
            foreach ($birimYetkileri as $by) {
                $birimId = intval($by['birim_id'] ?? 0);
                if ($birimId <= 0) continue;
                $db->insert('KullaniciBirimYetkileri', [
                    'KullaniciBirimYetkileri_Birim_id'        => $birimId,
                    'KullaniciBirimYetkileri_AltBayi_id'      => $id,
                    'KullaniciBirimYetkileri_BaslangicTarihi' => $by['baslangic'] ?: null,
                    'KullaniciBirimYetkileri_BitisTarihi'     => $by['bitis'] ?: null,
                    'OlusturanKullanici'                      => $user['kullanici_id'],
                    'OlusturmaTarihi'                         => date('Y-m-d H:i:s'),
                    'GuncelleyenKullanici'                    => $user['kullanici_id'],
                    'GuncellemeTarihi'                        => date('Y-m-d H:i:s'),
                    'Durum'                                   => 1,
                ]);
            }

            echo json_encode(['success' => true, 'message' => 'Alt bayi güncellendi.']);
            exit;
        }

        if ($action === 'alt_sil') {
            if (!$pagePermissions['can_delete']) throw new Exception('Silme yetkiniz bulunmamaktadır.');

            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Geçersiz id.');

            if ($birimKisitli && !kayitBirimYetkiliMi($db, $izinliBirimler, 'KullaniciBirimYetkileri_AltBayi_id', $id))
                throw new Exception('Bu kayıt için yetkiniz yok.');

            $perVar = $db->fetchOne("SELECT COUNT(*) AS s FROM DigiturkAltBayiPersonel WHERE DigiturkAltBayiPersonel_AltBayiId = ?", [$id]);
            if (($perVar['s'] ?? 0) > 0) throw new Exception('Bu alt bayiye bağlı personel kayıtları var. Önce onları silin.');

            $db->delete('DigiturkAltBayiler', ['DigiturkAltBayiler_Id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Alt bayi silindi.']);
            exit;
        }

        // =====================================================================
        // PERSONEL
        // =====================================================================
        if ($action === 'get_alt_bayiler') {
            $anaBayiId = $_POST['ana_bayi_id'] ?? '';
            $wAna      = '';
            $pAna      = [];
            if ($anaBayiId !== '') {
                $wAna  = " AND DigiturkAltBayiler_AnaBayiId = ?";
                $pAna[] = $anaBayiId;
            }
            if ($birimKisitli) {
                if (empty($izinliBirimler)) { echo json_encode(['success' => true, 'data' => []]); exit; }
                [$wB, $pB] = birimKisitWhere($izinliBirimler, 'KullaniciBirimYetkileri_AltBayi_id', 'DigiturkAltBayiler_Id');
                $rows = $db->fetchAll("SELECT DigiturkAltBayiler_Id AS id, DigiturkAltBayiler_Ad AS ad FROM DigiturkAltBayiler WHERE Durum = 1 AND $wB$wAna ORDER BY DigiturkAltBayiler_Ad", array_merge($pB, $pAna));
            } else {
                $rows = $db->fetchAll("SELECT DigiturkAltBayiler_Id AS id, DigiturkAltBayiler_Ad AS ad FROM DigiturkAltBayiler WHERE Durum = 1$wAna ORDER BY DigiturkAltBayiler_Ad", $pAna);
            }
            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        if ($action === 'per_listele') {
            $search    = $_POST['search']      ?? '';
            $durum     = $_POST['durum']       ?? '';
            $altBayiId = $_POST['alt_bayi_id'] ?? '';
            $anaBayiId = $_POST['ana_bayi_id'] ?? '';
            $where     = ["1=1"];
            $params    = [];

            if ($search !== '') {
                $where[]  = "(p.DigiturkAltBayiPersonel_AdSoyad LIKE ? OR p.DigiturkAltBayiPersonel_KullaniciAdi LIKE ? OR p.DigiturkAltBayiPersonel_KimlikNo LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            if ($durum !== '') {
                $where[]  = "p.Durum = ?";
                $params[] = $durum;
            }
            if ($altBayiId !== '') {
                $where[]  = "p.DigiturkAltBayiPersonel_AltBayiId = ?";
                $params[] = $altBayiId;
            }
            if ($anaBayiId !== '') {
                $where[]  = "a.DigiturkAltBayiler_AnaBayiId = ?";
                $params[] = $anaBayiId;
            }
            if ($birimKisitli) {
                if (empty($izinliBirimler)) { echo json_encode(['success' => true, 'data' => []]); exit; }
                // Silsile: doğrudan Personel yetkisi VEYA bağlı Alt Bayi yetkisi
                [$wB, $pB] = personelBirimKisitWhere($izinliBirimler, 'p.DigiturkAltBayiPersonel_Id', 'p.DigiturkAltBayiPersonel_AltBayiId');
                $where[]   = $wB;
                $params    = array_merge($params, $pB);
            }

            $rows = $db->fetchAll("
                SELECT p.DigiturkAltBayiPersonel_Id, p.DigiturkAltBayiPersonel_AltBayiId,
                       p.DigiturkAltBayiPersonel_AdSoyad, p.DigiturkAltBayiPersonel_KimlikNo,
                       p.DigiturkAltBayiPersonel_KullaniciAdi, p.Durum,
                       a.DigiturkAltBayiler_Ad AS AltBayiAd,
                       n.DigiturkAnaBayiler_Ad AS AnaBayiAd,
                       p.DigiturkAltBayiPersonel_Sifre,
                       p.DigiturkAltBayiPersonel_TokenDurum,
                       CONVERT(VARCHAR(16), p.DigiturkAltBayiPersonel_TokenSuresi, 120) AS TokenSuresi,
                       CASE WHEN p.DigiturkAltBayiPersonel_TokenSuresi > GETDATE() THEN 1 ELSE 0 END AS TokenGecerli,
                       p.DigiturkAltBayiPersonel_LoginKilitSifreli AS LoginKilitSifreli,
                       CASE WHEN p.DigiturkAltBayiPersonel_LoginKilitBitis > GETDATE()
                            THEN CONVERT(VARCHAR(16), p.DigiturkAltBayiPersonel_LoginKilitBitis, 120) END AS LoginKilitBitis,
                       p.DigiturkAltBayiPersonel_LoginHataMesaji AS LoginHataMesaji,
                       p.DigiturkAltBayiPersonel_GunlukLoginLimit AS GunlukLoginLimit,
                       CASE WHEN CAST(p.DigiturkAltBayiPersonel_SonLoginTarihi AS DATE) = CAST(GETDATE() AS DATE)
                            THEN ISNULL(p.DigiturkAltBayiPersonel_GunlukLoginAdedi, 0) ELSE 0 END AS BugunLogin,
                       CASE WHEN CAST(p.DigiturkAltBayiPersonel_SonLoginTarihi AS DATE) = CAST(GETDATE() AS DATE)
                            THEN ISNULL(p.DigiturkAltBayiPersonel_GunlukBasariliLogin, 0) ELSE 0 END AS BugunBasarili,
                       CONVERT(VARCHAR(16), p.OlusturmaTarihi, 120) AS OlusturmaTarihi,
                       CASE WHEN p.DigiturkAltBayiPersonel_Sifre IS NOT NULL THEN 1 ELSE 0 END AS SifreVar
                FROM DigiturkAltBayiPersonel p
                LEFT JOIN DigiturkAltBayiler  a ON p.DigiturkAltBayiPersonel_AltBayiId = a.DigiturkAltBayiler_Id
                LEFT JOIN DigiturkAnaBayiler  n ON a.DigiturkAltBayiler_AnaBayiId      = n.DigiturkAnaBayiler_Id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.DigiturkAltBayiPersonel_AdSoyad
            ", $params);

            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        if ($action === 'per_ekle') {
            if (!$pagePermissions['can_add']) throw new Exception('Ekleme yetkiniz bulunmamaktadır.');

            $altBayiId    = intval($_POST['alt_bayi_id']   ?? 0);
            $adSoyad      = trim($_POST['ad_soyad']        ?? '');
            $kimlikNo     = trim($_POST['kimlik_no']       ?? '') ?: null;
            $kullaniciAdi = trim($_POST['kullanici_adi']   ?? '');
            $sifre        = trim($_POST['sifre']           ?? '');
            // Günlük login limitini yalnız admin belirler; admin olmayanda varsayılan 10 (Digiturk'ün kullanıcı başı limiti).
            // Admin alanı bilerek boşaltırsa sınırsız (NULL) kaydedilir.
            $loginLimit   = $pagePermissions['is_admin'] ? perLoginLimitOku($_POST['gunluk_login_limit'] ?? '10') : 10;

            if ($altBayiId <= 0 || empty($adSoyad) || empty($kullaniciAdi) || empty($sifre))
                throw new Exception('Alt bayi, ad soyad, kullanıcı adı ve şifre zorunludur.');

            personelBirimSecimDogrula($db, $birimKisitli, $izinliBirimler, $_POST['birim_yetkileri'] ?? '[]', $altBayiId);

            $kontrol = $db->fetchOne("SELECT DigiturkAltBayiPersonel_Id FROM DigiturkAltBayiPersonel WHERE DigiturkAltBayiPersonel_KullaniciAdi = ?", [$kullaniciAdi]);
            if ($kontrol) throw new Exception('Bu kullanıcı adı zaten kayıtlı.');

            $id = $db->insert('DigiturkAltBayiPersonel', [
                'DigiturkAltBayiPersonel_AltBayiId'    => $altBayiId,
                'DigiturkAltBayiPersonel_AdSoyad'      => $adSoyad,
                'DigiturkAltBayiPersonel_KimlikNo'     => $kimlikNo,
                'DigiturkAltBayiPersonel_KullaniciAdi' => $kullaniciAdi,
                'DigiturkAltBayiPersonel_Sifre'        => $sifre ?: null,
                'DigiturkAltBayiPersonel_GunlukLoginLimit' => $loginLimit,
                'OlusturanKullanici'                   => $user['kullanici_id'],
                'OlusturmaTarihi'                      => date('Y-m-d H:i:s'),
                'GuncelleyenKullanici'                 => $user['kullanici_id'],
                'GuncellemeTarihi'                     => date('Y-m-d H:i:s'),
                'Durum'                                => 1,
            ]);

            $birimYetkileri = json_decode($_POST['birim_yetkileri'] ?? '[]', true) ?: [];
            foreach ($birimYetkileri as $by) {
                $birimId = intval($by['birim_id'] ?? 0);
                if ($birimId <= 0) continue;
                $db->insert('KullaniciBirimYetkileri', [
                    'KullaniciBirimYetkileri_Birim_id'        => $birimId,
                    'KullaniciBirimYetkileri_Personel_id'     => $id,
                    'KullaniciBirimYetkileri_BaslangicTarihi' => $by['baslangic'] ?: null,
                    'KullaniciBirimYetkileri_BitisTarihi'     => $by['bitis'] ?: null,
                    'OlusturanKullanici'                      => $user['kullanici_id'],
                    'OlusturmaTarihi'                         => date('Y-m-d H:i:s'),
                    'GuncelleyenKullanici'                    => $user['kullanici_id'],
                    'GuncellemeTarihi'                        => date('Y-m-d H:i:s'),
                    'Durum'                                   => 1,
                ]);
            }

            echo json_encode(['success' => true, 'message' => 'Personel eklendi.', 'id' => $id]);
            exit;
        }

        if ($action === 'per_guncelle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');

            $id           = intval($_POST['id']            ?? 0);
            $altBayiId    = intval($_POST['alt_bayi_id']   ?? 0);
            $adSoyad      = trim($_POST['ad_soyad']        ?? '');
            $kimlikNo     = trim($_POST['kimlik_no']       ?? '') ?: null;
            $kullaniciAdi = trim($_POST['kullanici_adi']   ?? '');
            $sifre        = trim($_POST['sifre']           ?? '');
            $durum        = intval($_POST['durum']         ?? 1);

            if ($id <= 0 || $altBayiId <= 0 || empty($adSoyad) || empty($kullaniciAdi))
                throw new Exception('Geçersiz veri.');

            // Şifre değişirse Digiturk login kilidi (şifre süresi / bekleme) kaldırılır
            require_once __DIR__ . '/../includes/DigiturkKotaServisi.php';
            $eskiSifre = (string)($db->fetchOne("SELECT DigiturkAltBayiPersonel_Sifre AS s FROM DigiturkAltBayiPersonel WHERE DigiturkAltBayiPersonel_Id = ?", [$id])['s'] ?? '');
            $sifreDegisti = $sifre !== '' && $sifre !== $eskiSifre;

            // Admin olmayan kullanıcı yalnızca şifreyi güncelleyebilir
            if (!$pagePermissions['is_admin']) {
                if ($birimKisitli && !personelKayitBirimYetkiliMi($db, $izinliBirimler, $id))
                    throw new Exception('Bu kayıt için yetkiniz yok.');
                if ($sifre === '') throw new Exception('Şifre alanı boş olamaz.');

                $db->update('DigiturkAltBayiPersonel', [
                    'DigiturkAltBayiPersonel_Sifre' => $sifre,
                    'GuncelleyenKullanici'          => $user['kullanici_id'],
                    'GuncellemeTarihi'              => date('Y-m-d H:i:s'),
                ], ['DigiturkAltBayiPersonel_Id' => $id]);
                if ($sifreDegisti) digiturkLoginKilidiniKaldir($db, $id, (int)$user['kullanici_id']);

                echo json_encode(['success' => true, 'message' => 'Şifre güncellendi.']);
                exit;
            }

            if ($birimKisitli) {
                if (!personelKayitBirimYetkiliMi($db, $izinliBirimler, $id))
                    throw new Exception('Bu kayıt için yetkiniz yok.');
                personelBirimSecimDogrula($db, $birimKisitli, $izinliBirimler, $_POST['birim_yetkileri'] ?? '[]', $altBayiId);
            }

            $kontrol = $db->fetchOne("SELECT DigiturkAltBayiPersonel_Id FROM DigiturkAltBayiPersonel WHERE DigiturkAltBayiPersonel_KullaniciAdi = ? AND DigiturkAltBayiPersonel_Id != ?", [$kullaniciAdi, $id]);
            if ($kontrol) throw new Exception('Bu kullanıcı adı başka bir kayıtta kullanılıyor.');

            $data = [
                'DigiturkAltBayiPersonel_AltBayiId'    => $altBayiId,
                'DigiturkAltBayiPersonel_AdSoyad'      => $adSoyad,
                'DigiturkAltBayiPersonel_KimlikNo'     => $kimlikNo,
                'DigiturkAltBayiPersonel_KullaniciAdi' => $kullaniciAdi,
                // Bu blok yalnız admin için çalışır (admin olmayan yukarıda yalnız şifreyi güncelleyip çıkar)
                'DigiturkAltBayiPersonel_GunlukLoginLimit' => perLoginLimitOku($_POST['gunluk_login_limit'] ?? ''),
                'GuncelleyenKullanici'                 => $user['kullanici_id'],
                'GuncellemeTarihi'                     => date('Y-m-d H:i:s'),
                'Durum'                                => $durum,
            ];
            if ($sifre !== '') {
                $data['DigiturkAltBayiPersonel_Sifre'] = $sifre ?: null;
            }

            $db->update('DigiturkAltBayiPersonel', $data, ['DigiturkAltBayiPersonel_Id' => $id]);
            if ($sifreDegisti) digiturkLoginKilidiniKaldir($db, $id, (int)$user['kullanici_id']);

            $birimYetkileri = json_decode($_POST['birim_yetkileri'] ?? '[]', true) ?: [];
            $db->delete('KullaniciBirimYetkileri', ['KullaniciBirimYetkileri_Personel_id' => $id]);
            foreach ($birimYetkileri as $by) {
                $birimId = intval($by['birim_id'] ?? 0);
                if ($birimId <= 0) continue;
                $db->insert('KullaniciBirimYetkileri', [
                    'KullaniciBirimYetkileri_Birim_id'        => $birimId,
                    'KullaniciBirimYetkileri_Personel_id'     => $id,
                    'KullaniciBirimYetkileri_BaslangicTarihi' => $by['baslangic'] ?: null,
                    'KullaniciBirimYetkileri_BitisTarihi'     => $by['bitis'] ?: null,
                    'OlusturanKullanici'                      => $user['kullanici_id'],
                    'OlusturmaTarihi'                         => date('Y-m-d H:i:s'),
                    'GuncelleyenKullanici'                    => $user['kullanici_id'],
                    'GuncellemeTarihi'                        => date('Y-m-d H:i:s'),
                    'Durum'                                   => 1,
                ]);
            }

            echo json_encode(['success' => true, 'message' => 'Personel güncellendi.']);
            exit;
        }

        if ($action === 'per_durum_degistir') {
            if (!$pagePermissions['can_edit'])  throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');
            if (!$pagePermissions['is_admin'])  throw new Exception('Durum değiştirme yetkiniz bulunmamaktadır.');

            $id    = intval($_POST['id']    ?? 0);
            $durum = intval($_POST['durum'] ?? 0) === 1 ? 1 : 0;
            if ($id <= 0) throw new Exception('Geçersiz kayıt.');

            if ($birimKisitli && !personelKayitBirimYetkiliMi($db, $izinliBirimler, $id))
                throw new Exception('Bu kayıt için yetkiniz yok.');

            $db->update('DigiturkAltBayiPersonel', [
                'Durum'                => $durum,
                'GuncelleyenKullanici' => $user['kullanici_id'],
                'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
            ], ['DigiturkAltBayiPersonel_Id' => $id]);

            echo json_encode(['success' => true, 'message' => $durum ? 'Personel aktif edildi.' : 'Personel pasife alındı.']);
            exit;
        }

        if ($action === 'per_sil') {
            if (!$pagePermissions['can_delete']) throw new Exception('Silme yetkiniz bulunmamaktadır.');

            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Geçersiz id.');

            if ($birimKisitli && !personelKayitBirimYetkiliMi($db, $izinliBirimler, $id))
                throw new Exception('Bu kayıt için yetkiniz yok.');

            $db->delete('DigiturkAltBayiPersonel', ['DigiturkAltBayiPersonel_Id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Personel silindi.']);
            exit;
        }

        if ($action === 'per_token_guncelle') {
            if (!$pagePermissions['can_edit']) throw new Exception('Düzenleme yetkiniz bulunmamaktadır.');

            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Geçersiz id.');

            if ($birimKisitli && !personelKayitBirimYetkiliMi($db, $izinliBirimler, $id))
                throw new Exception('Bu kayıt için yetkiniz yok.');

            // Ortak login: pasif personel, bayi limiti ve şifre/bekleme kilitleri fonksiyonda kontrol edilir.
            // Buton bilinçli yenileme olduğu için geçerli token olsa da yenilenir ($zorla = true).
            require_once __DIR__ . '/../includes/DigiturkKotaServisi.php';
            $s = digiturkLogin($db, $id, 'panel', true, 60, (int)$user['kullanici_id']);

            if (!$s['basarili']) throw new Exception($s['mesaj']);

            echo json_encode(['success' => true, 'message' => 'Token başarıyla güncellendi.']);
            exit;
        }

        if ($action === 'get_birimler') {
            if ($birimKisitli) {
                if (empty($izinliBirimler)) { echo json_encode(['success' => true, 'data' => []]); exit; }
                $ph   = implode(',', array_fill(0, count($izinliBirimler), '?'));
                $rows = $db->fetchAll("SELECT KullaniciBirim_id AS id, KullaniciBirim_Adi AS ad FROM KullaniciBirim WHERE Durum = 1 AND KullaniciBirim_id IN ($ph) ORDER BY KullaniciBirim_Adi", $izinliBirimler);
            } else {
                $rows = $db->fetchAll("SELECT KullaniciBirim_id AS id, KullaniciBirim_Adi AS ad FROM KullaniciBirim WHERE Durum = 1 ORDER BY KullaniciBirim_Adi");
            }
            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        if ($action === 'alt_birim_yetkileri') {
            $altBayiId = intval($_POST['alt_bayi_id'] ?? 0);
            if ($birimKisitli && !kayitBirimYetkiliMi($db, $izinliBirimler, 'KullaniciBirimYetkileri_AltBayi_id', $altBayiId)) {
                echo json_encode(['success' => true, 'data' => []]); exit;
            }
            $rows = $db->fetchAll("
                SELECT k.KullaniciBirimYetkileri_Birim_id,
                       CONVERT(VARCHAR(10), k.KullaniciBirimYetkileri_BaslangicTarihi, 23) AS BaslangicTarihi,
                       CONVERT(VARCHAR(10), k.KullaniciBirimYetkileri_BitisTarihi, 23)     AS BitisTarihi
                FROM KullaniciBirimYetkileri k
                WHERE k.KullaniciBirimYetkileri_AltBayi_id = ? AND k.Durum = 1
                ORDER BY k.KullaniciBirimYetkileri_id
            ", [$altBayiId]);
            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        if ($action === 'per_birim_yetkileri') {
            $personelId = intval($_POST['personel_id'] ?? 0);
            if ($birimKisitli && !personelKayitBirimYetkiliMi($db, $izinliBirimler, $personelId)) {
                echo json_encode(['success' => true, 'data' => []]); exit;
            }
            $rows = $db->fetchAll("
                SELECT k.KullaniciBirimYetkileri_Birim_id,
                       CONVERT(VARCHAR(10), k.KullaniciBirimYetkileri_BaslangicTarihi, 23) AS BaslangicTarihi,
                       CONVERT(VARCHAR(10), k.KullaniciBirimYetkileri_BitisTarihi, 23)     AS BitisTarihi
                FROM KullaniciBirimYetkileri k
                WHERE k.KullaniciBirimYetkileri_Personel_id = ? AND k.Durum = 1
                ORDER BY k.KullaniciBirimYetkileri_id
            ", [$personelId]);
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
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <!-- Sayfa Başlığı -->
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6"><h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3></div>
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
                <?php $boxCol = $anaSekmeGoster ? 'col-md-3' : 'col-md-6'; ?>
                <div class="row mb-3">
                    <?php if ($anaSekmeGoster): ?>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-primary shadow-sm"><i class="bi bi-building"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Ana Bayiler</span>
                                <span class="info-box-number" id="stat-ana">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-md-3">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-success shadow-sm"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Ana Bayi</span>
                                <span class="info-box-number" id="stat-ana-aktif">0</span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-12 col-sm-6 <?= $boxCol ?>">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-warning shadow-sm"><i class="bi bi-shop"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Alt Bayiler</span>
                                <span class="info-box-number" id="stat-alt">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 <?= $boxCol ?>">
                        <div class="info-box">
                            <span class="info-box-icon text-bg-info shadow-sm"><i class="bi bi-people"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Personel</span>
                                <span class="info-box-number" id="stat-per">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sekmeler -->
                <div class="card card-primary card-outline">
                    <div class="card-header p-0">
                        <ul class="nav nav-tabs card-header-tabs" id="bayiTabs" role="tablist">
                            <?php if ($anaSekmeGoster): ?>
                            <li class="nav-item">
                                <a class="nav-link active" id="tab-ana" data-bs-toggle="tab" href="#pane-ana" role="tab">
                                    <i class="bi bi-building me-1"></i> Ana Bayiler
                                </a>
                            </li>
                            <?php endif; ?>
                            <li class="nav-item">
                                <a class="nav-link <?= $anaSekmeGoster ? '' : 'active' ?>" id="tab-alt" data-bs-toggle="tab" href="#pane-alt" role="tab">
                                    <i class="bi bi-shop me-1"></i> Alt Bayiler
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="tab-per" data-bs-toggle="tab" href="#pane-per" role="tab">
                                    <i class="bi bi-people me-1"></i> Personel
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body tab-content">

                        <!-- =============== ANA BAYİLER =============== -->
                        <?php if ($anaSekmeGoster): ?>
                        <div class="tab-pane fade show active" id="pane-ana" role="tabpanel">
                            <!-- Filtre -->
                            <div class="card card-outline card-secondary mb-3">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#anaFilter">
                                            <i class="bi bi-chevron-down"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body collapse" id="anaFilter">
                                    <form id="anaFilterForm">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Ara (Ad / Bayi Kodu)</label>
                                                <input type="text" class="form-control" id="ana_search" placeholder="Ara...">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Durum</label>
                                                <select class="form-select" id="ana_durum">
                                                    <option value="">Tümü</option>
                                                    <option value="1">Aktif</option>
                                                    <option value="0">Pasif</option>
                                                </select>
                                            </div>
                                            <div class="col-md-12">
                                                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                                <button type="reset" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Temizle</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <!-- Tablo -->
                            <div class="d-flex justify-content-end mb-2">
                                <?php if ($pagePermissions['can_add']): ?>
                                <button class="btn btn-primary btn-sm" onclick="anaModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni Ana Bayi
                                </button>
                                <?php endif; ?>
                            </div>
                            <table id="anaTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Bayi Adı</th>
                                        <th>Bayi Kodu</th>
                                        <th>Kullanıcı Adı</th>
                                        <th>Durum</th>
                                        <th>Kayıt Tarihi</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                        <!-- =============== ALT BAYİLER =============== -->
                        <div class="tab-pane fade <?= $anaSekmeGoster ? '' : 'show active' ?>" id="pane-alt" role="tabpanel">
                            <div class="card card-outline card-secondary mb-3">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#altFilter">
                                            <i class="bi bi-chevron-down"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body collapse" id="altFilter">
                                    <form id="altFilterForm">
                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <label class="form-label">Ana Bayi</label>
                                                <select class="form-select" id="alt_ana_bayi_id">
                                                    <option value="">Tümü</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Ara (Ad / Kullanıcı Adı)</label>
                                                <input type="text" class="form-control" id="alt_search" placeholder="Ara...">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Durum</label>
                                                <select class="form-select" id="alt_durum">
                                                    <option value="">Tümü</option>
                                                    <option value="1">Aktif</option>
                                                    <option value="0">Pasif</option>
                                                </select>
                                            </div>
                                            <div class="col-md-12">
                                                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                                <button type="reset" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Temizle</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end mb-2">
                                <?php if ($pagePermissions['can_add']): ?>
                                <button class="btn btn-primary btn-sm" onclick="altModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni Alt Bayi
                                </button>
                                <?php endif; ?>
                            </div>
                            <table id="altTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Ana Bayi</th>
                                        <th>Alt Bayi Adı</th>
                                        <th>Kullanıcı Adı</th>
                                        <th>Durum</th>
                                        <th>Kayıt Tarihi</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>

                        <!-- =============== PERSONEL =============== -->
                        <div class="tab-pane fade" id="pane-per" role="tabpanel">
                            <div class="card card-outline card-secondary mb-3">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#perFilter">
                                            <i class="bi bi-chevron-down"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body collapse" id="perFilter">
                                    <form id="perFilterForm">
                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <label class="form-label">Ana Bayi</label>
                                                <select class="form-select" id="per_ana_bayi_id">
                                                    <option value="">Tümü</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Alt Bayi</label>
                                                <select class="form-select" id="per_alt_bayi_id">
                                                    <option value="">Tümü</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Ara (Ad / Kullanıcı / Kimlik No)</label>
                                                <input type="text" class="form-control" id="per_search" placeholder="Ara...">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Durum</label>
                                                <select class="form-select" id="per_durum">
                                                    <option value="">Tümü</option>
                                                    <option value="1">Aktif</option>
                                                    <option value="0">Pasif</option>
                                                </select>
                                            </div>
                                            <div class="col-md-12">
                                                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                                <button type="reset" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Temizle</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end mb-2 gap-2">
                                <?php if ($pagePermissions['can_add']): ?>
                                <button class="btn btn-primary btn-sm" onclick="perModal()">
                                    <i class="bi bi-plus-circle"></i> Yeni Personel
                                </button>
                                <?php endif; ?>
                            </div>
                            <table id="perTable" class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Ana Bayi</th>
                                        <th>Alt Bayi</th>
                                        <th>Ad Soyad</th>
                                        <th>Kimlik No</th>
                                        <th>Kullanıcı Adı</th>
                                        <th>Token</th>
                                        <th>Login (Bugün)</th>
                                        <th>Durum</th>
                                        <th>Token Süresi</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>

                    </div><!-- /tab-content -->
                </div>

            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- ===== MODAL: ANA BAYİ ===== -->
<div class="modal fade" id="anaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="anaModalTitle">Ana Bayi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ana_id">
                <div class="mb-3">
                    <label class="form-label">Bayi Adı <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="ana_ad" placeholder="Bayi adı">
                </div>
                <div class="mb-3">
                    <label class="form-label">Bayi Kodu <span class="text-danger">*</span></label>
                    <input type="text" class="form-control text-uppercase" id="ana_bayi_kodu" placeholder="Örn: BK-001" maxlength="50">
                </div>
                <div class="mb-3">
                    <label class="form-label">Kullanıcı Adı</label>
                    <input type="text" class="form-control" id="ana_kullanici_adi" placeholder="Kullanıcı adı (opsiyonel)">
                </div>
                <div class="mb-3">
                    <label class="form-label">Şifre</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="ana_sifre" placeholder="Şifre (opsiyonel)">
                        <button class="btn btn-outline-secondary" type="button" onclick="toggleSifre('ana_sifre')">
                            <i class="bi bi-eye-slash"></i>
                        </button>
                    </div>
                    <small class="text-muted" id="ana_sifre_hint" style="display:none">Boş bırakırsanız mevcut şifre korunur.</small>
                </div>
                <div class="mb-3" id="ana_durum_row" style="display:none">
                    <label class="form-label">Durum</label>
                    <select class="form-select" id="ana_modal_durum">
                        <option value="1">Aktif</option>
                        <option value="0">Pasif</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" id="anaSaveBtn" onclick="anaKaydet()">Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL: ALT BAYİ ===== -->
<div class="modal fade" id="altModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="altModalTitle">Alt Bayi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="alt_id">
                <div class="mb-3">
                    <label class="form-label">Ana Bayi <span class="text-danger">*</span></label>
                    <select class="form-select" id="alt_modal_ana_bayi_id">
                        <option value="">Seçiniz...</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Alt Bayi Adı <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="alt_ad" placeholder="Alt bayi adı">
                </div>
                <div class="mb-3">
                    <label class="form-label">Kullanıcı Adı</label>
                    <input type="text" class="form-control" id="alt_kullanici_adi" placeholder="Kullanıcı adı (opsiyonel)">
                </div>
                <div class="mb-3">
                    <label class="form-label">Şifre</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="alt_sifre" placeholder="Şifre (opsiyonel)">
                        <button class="btn btn-outline-secondary" type="button" onclick="toggleSifre('alt_sifre')">
                            <i class="bi bi-eye-slash"></i>
                        </button>
                    </div>
                    <small class="text-muted" id="alt_sifre_hint" style="display:none">Boş bırakırsanız mevcut şifre korunur.</small>
                </div>
                <div class="mb-3" id="alt_durum_row" style="display:none">
                    <label class="form-label">Durum</label>
                    <select class="form-select" id="alt_modal_durum">
                        <option value="1">Aktif</option>
                        <option value="0">Pasif</option>
                    </select>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong><i class="bi bi-shield-check me-1"></i> Birim Yetkileri</strong>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="altBirimSatirEkle()">
                        <i class="bi bi-plus"></i> Birim Ekle
                    </button>
                </div>
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Birim</th>
                            <th style="width:140px">Başlangıç</th>
                            <th style="width:140px">Bitiş</th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody id="altBirimRows"></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" onclick="altKaydet()">Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL: PERSONEL ===== -->
<div class="modal fade" id="perModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="perModalTitle">Personel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="per_id">
                <div class="mb-3">
                    <label class="form-label">Alt Bayi <span class="text-danger">*</span></label>
                    <select class="form-select" id="per_modal_alt_bayi_id">
                        <option value="">Seçiniz...</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Ad Soyad <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="per_ad_soyad" placeholder="Ad Soyad">
                </div>
                <div class="mb-3">
                    <label class="form-label">Kimlik No</label>
                    <input type="text" class="form-control" id="per_kimlik_no" placeholder="TC / Yabancı Kimlik No" maxlength="20">
                </div>
                <div class="mb-3">
                    <label class="form-label">Kullanıcı Adı <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="per_kullanici_adi" placeholder="Kullanıcı adı">
                </div>
                <div class="mb-3">
                    <label class="form-label">Şifre <span class="text-danger" id="per_sifre_zorunlu">*</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="per_sifre" placeholder="Şifre">
                        <button class="btn btn-outline-secondary" type="button" onclick="toggleSifre('per_sifre')">
                            <i class="bi bi-eye-slash"></i>
                        </button>
                    </div>
                    <small class="text-muted" id="per_sifre_hint" style="display:none">Boş bırakırsanız mevcut şifre korunur.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="per_gunluk_login_limit">Günlük Login Limiti</label>
                    <input type="number" class="form-control" id="per_gunluk_login_limit" min="0" max="10" step="1" placeholder="Boş = sınırsız (en fazla 10)">
                    <small class="text-danger" id="per_login_limit_yetki" style="display:none">
                        <i class="bi bi-lock"></i> Bu alanı yalnızca admin değiştirebilir.
                    </small>
                    <small class="text-muted">
                        Bu personel için Digiturk'e günde en fazla kaç login isteği gönderileceği (başarılı/başarısız hepsi sayılır, kayıtlı token kullanımı sayılmaz).
                        Digiturk kullanıcı başına günde en fazla 10 login'e izin verir. Token 14 saat geçerli olduğu için
                        normal kullanımda <strong>3</strong> önerilir. Gece yarısı sıfırlanır.
                    </small>
                </div>
                <div class="mb-3" id="per_durum_row" style="display:none">
                    <label class="form-label">Durum</label>
                    <select class="form-select" id="per_modal_durum">
                        <option value="1">Aktif</option>
                        <option value="0">Pasif</option>
                    </select>
                </div>
                <div id="per_birim_bolum">
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong><i class="bi bi-shield-check me-1"></i> Birim Yetkileri</strong>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="perBirimSatirEkle()">
                            <i class="bi bi-plus"></i> Birim Ekle
                        </button>
                    </div>
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Birim</th>
                                <th style="width:140px">Başlangıç</th>
                                <th style="width:140px">Bitiş</th>
                                <th style="width:40px"></th>
                            </tr>
                        </thead>
                        <tbody id="perBirimRows"></tbody>
                    </table>
                </div>
                <div id="per_sadece_sifre_uyari" class="alert alert-info mt-3 mb-0 py-2" style="display:none">
                    <i class="bi bi-info-circle me-1"></i> Bu kayıtta yalnızca şifre alanını güncelleyebilirsiniz.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" onclick="perKaydet()">Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- JS -->
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
const PAGE_URL  = '/Admin/pages/bayi-yonetimi.php';
const perms     = <?= json_encode($pagePermissions) ?>;
const anaSekmeGoster = <?= $anaSekmeGoster ? 'true' : 'false' ?>;

let anaTable, altTable, perTable;
let anaFilters = {}, altFilters = {}, perFilters = {};

// Satır verisi önbelleği — JSON.stringify/onclick çift tırnak sorununu önler
const anaRows = {}, altRows = {}, perRows = {};

let birimlerListesi = [];

// ── Yardımcılar ─────────────────────────────────────────────────────────────
function post(data) {
    return $.post(PAGE_URL, data);
}

function durumBadge(d) {
    return d == 1
        ? '<span class="badge bg-success">Aktif</span>'
        : '<span class="badge bg-danger">Pasif</span>';
}

function tokenDurumBadge(row) {
    const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const durum = row.DigiturkAltBayiPersonel_TokenDurum;
    let b;
    if (durum == 2)                         b = '<span class="badge bg-danger">Hata</span>';
    else if (durum == 1 && row.TokenGecerli == 1) b = '<span class="badge bg-success">Aktif</span>';
    else if (durum == 1)                    b = '<span class="badge bg-warning text-dark">Süresi Doldu</span>';
    else                                    b = '<span class="badge bg-secondary">Yok</span>';

    // Login kilitleri (ortak digiturkLogin): Digiturk'e login atılmayan durumlar
    const hata = esc(row.LoginHataMesaji);
    if (row.LoginKilitSifreli == 1)
        b += ` <span class="badge bg-dark" title="Şifre güncellenene kadar login atılmaz. ${hata}"><i class="bi bi-lock"></i> Şifre kilidi</span>`;
    else if (row.LoginKilitBitis)
        b += ` <span class="badge bg-info text-dark" title="${hata}"><i class="bi bi-hourglass-split"></i> Beklemede ${esc(row.LoginKilitBitis.substring(11))}</span>`;
    return b;
}

function perTokenGuncelle(id) {
    const adSoyad = perRows[id]?.DigiturkAltBayiPersonel_AdSoyad ?? '';
    Swal.fire({
        title: 'Token Güncelle',
        text: `"${adSoyad}" için yeni token alınacak. Devam edilsin mi?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Evet, Güncelle',
        cancelButtonText: 'İptal',
        confirmButtonColor: '#198754',
    }).then(result => {
        if (!result.isConfirmed) return;
        Swal.fire({ title: 'Token alınıyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        post({ action: 'per_token_guncelle', id }).done(r => {
            Swal.close();
            if (r.success) { showToast(r.message, 'success'); perTable.ajax.reload(null, false); }
            else showError('Hata!', r.message);
        }).fail(() => {
            Swal.close();
            showError('Hata!', 'Sunucu bağlantısı kurulamadı.');
        });
    });
}


function toggleSifre(inputId) {
    const el  = document.getElementById(inputId);
    const btn = el.nextElementSibling;
    if (el.type === 'text') {
        el.type = 'password';
        btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
    } else {
        el.type = 'text';
        btn.innerHTML = '<i class="bi bi-eye"></i>';
    }
}

function select2Init(selector, parent = null) {
    const $el = $(selector);
    if (!$el.length) return;
    // custom.js tüm .form-select'leri init ediyor → çift init'e karşı önce destroy
    if ($el.hasClass('select2-hidden-accessible')) $el.select2('destroy');
    const opt = {
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Seçiniz...',
        allowClear: true,
        language: {
            noResults: () => 'Sonuç bulunamadı',
            searching: () => 'Aranıyor...'
        }
    };
    if (parent) opt.dropdownParent = $(parent);
    $el.select2(opt);
}

// ── Birim Yetkileri ──────────────────────────────────────────────────────────
function loadBirimler() {
    post({ action: 'get_birimler' }).done(r => { if (r.success) birimlerListesi = r.data; });
}

function birimSatirHTML(tur, birimId = '', baslangic = '', bitis = '') {
    const opts = birimlerListesi.map(b =>
        `<option value="${b.id}" ${b.id == birimId ? 'selected' : ''}>${b.ad}</option>`
    ).join('');
    return `<tr>
        <td><select class="form-select form-select-sm ${tur}-birim-sel"><option value="">Seçiniz...</option>${opts}</select></td>
        <td><input type="date" class="form-control form-control-sm ${tur}-birim-bas" value="${baslangic}"></td>
        <td><input type="date" class="form-control form-control-sm ${tur}-birim-bit" value="${bitis}"></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()"><i class="bi bi-x"></i></button></td>
    </tr>`;
}

function altBirimSatirEkle(birimId = '', baslangic = '', bitis = '') {
    const $row = $(birimSatirHTML('alt', birimId, baslangic, bitis));
    $('#altBirimRows').append($row);
    $row.find('.alt-birim-sel').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Seçiniz...', allowClear: true, dropdownParent: $('#altModal') });
}

function perBirimSatirEkle(birimId = '', baslangic = '', bitis = '') {
    const $row = $(birimSatirHTML('per', birimId, baslangic, bitis));
    $('#perBirimRows').append($row);
    $row.find('.per-birim-sel').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Seçiniz...', allowClear: true, dropdownParent: $('#perModal') });
}

function loadAltBirimYetkileri(altBayiId) {
    $('#altBirimRows').empty();
    if (!altBayiId) return;
    post({ action: 'alt_birim_yetkileri', alt_bayi_id: altBayiId }).done(r => {
        if (r.success) r.data.forEach(x => altBirimSatirEkle(x.KullaniciBirimYetkileri_Birim_id, x.BaslangicTarihi, x.BitisTarihi));
    });
}

function loadPerBirimYetkileri(personelId) {
    $('#perBirimRows').empty();
    if (!personelId) return;
    post({ action: 'per_birim_yetkileri', personel_id: personelId }).done(r => {
        if (r.success) r.data.forEach(x => perBirimSatirEkle(x.KullaniciBirimYetkileri_Birim_id, x.BaslangicTarihi, x.BitisTarihi));
    });
}

function collectAltBirimYetkileri() {
    const rows = [];
    $('#altBirimRows tr').each(function () {
        const birimId = $(this).find('.alt-birim-sel').val();
        if (birimId) rows.push({ birim_id: birimId, baslangic: $(this).find('.alt-birim-bas').val(), bitis: $(this).find('.alt-birim-bit').val() });
    });
    return rows;
}

function collectPerBirimYetkileri() {
    const rows = [];
    $('#perBirimRows tr').each(function () {
        const birimId = $(this).find('.per-birim-sel').val();
        if (birimId) rows.push({ birim_id: birimId, baslangic: $(this).find('.per-birim-bas').val(), bitis: $(this).find('.per-birim-bit').val() });
    });
    return rows;
}

// ── Stats ────────────────────────────────────────────────────────────────────
function loadStats() {
    post({ action: 'stats' }).done(r => {
        if (!r.success) return;
        $('#stat-ana').text(r.data.ana_toplam);
        $('#stat-ana-aktif').text(r.data.ana_aktif);
        $('#stat-alt').text(r.data.alt_toplam);
        $('#stat-per').text(r.data.per_toplam);
    });
}

// ── Ana Bayiler ──────────────────────────────────────────────────────────────
function initAnaTable() {
    anaTable = $('#anaTable').DataTable({
        processing: true,
        scrollX: true,
        autoWidth: false,
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        ajax: {
            url: PAGE_URL, type: 'POST',
            data: () => ({ action: 'ana_listele', ...anaFilters }),
            dataSrc: r => r.success ? r.data : [],
        },
        columns: [
            { data: 'DigiturkAnaBayiler_Id' },
            { data: 'DigiturkAnaBayiler_Ad' },
            { data: 'DigiturkAnaBayiler_BayiKodu' },
            { data: 'DigiturkAnaBayiler_KullaniciAdi', defaultContent: '-' },
            { data: 'Durum', render: durumBadge },
            { data: 'OlusturmaTarihi', defaultContent: '-' },
            {
                data: null, orderable: false,
                render: d => {
                    anaRows[d.DigiturkAnaBayiler_Id] = d;
                    let b = '';
                    if (perms.can_edit)   b += `<button class="btn btn-sm btn-warning me-1" onclick="anaModal(${d.DigiturkAnaBayiler_Id})"><i class="bi bi-pencil"></i></button>`;
                    if (perms.can_delete) b += `<button class="btn btn-sm btn-danger"        onclick="anaSil(${d.DigiturkAnaBayiler_Id})"><i class="bi bi-trash"></i></button>`;
                    return b || '-';
                }
            }
        ],
        order: [[1, 'asc']]
    });
}

function anaModal(id = null) {
    const row = id ? anaRows[id] : null;
    $('#ana_id').val('');
    $('#ana_ad').val('');
    $('#ana_bayi_kodu').val('');
    $('#ana_kullanici_adi').val('');
    $('#ana_sifre').val('');
    $('#ana_modal_durum').val('1');
    $('#ana_durum_row').hide();
    $('#ana_sifre_hint').hide();

    if (row) {
        $('#anaModalTitle').text('Ana Bayi Düzenle');
        $('#ana_id').val(row.DigiturkAnaBayiler_Id);
        $('#ana_ad').val(row.DigiturkAnaBayiler_Ad);
        $('#ana_bayi_kodu').val(row.DigiturkAnaBayiler_BayiKodu);
        $('#ana_kullanici_adi').val(row.DigiturkAnaBayiler_KullaniciAdi || '');
        $('#ana_sifre').val(row.DigiturkAnaBayiler_Sifre || '');
        $('#ana_modal_durum').val(row.Durum);
        $('#ana_durum_row').show();
        $('#ana_sifre_hint').show();
    } else {
        $('#anaModalTitle').text('Yeni Ana Bayi');
    }
    new bootstrap.Modal(document.getElementById('anaModal')).show();
}

function anaKaydet() {
    const id  = $('#ana_id').val();
    const ad  = $('#ana_ad').val().trim();
    const kod = $('#ana_bayi_kodu').val().trim();
    const kad = $('#ana_kullanici_adi').val().trim();
    const sif = $('#ana_sifre').val();

    if (!ad || !kod) { showToast('Ad ve Bayi Kodu zorunludur.', 'warning'); return; }

    const data = id
        ? { action: 'ana_guncelle', id, ad, bayi_kodu: kod, kullanici_adi: kad, sifre: sif, durum: $('#ana_modal_durum').val() }
        : { action: 'ana_ekle', ad, bayi_kodu: kod, kullanici_adi: kad, sifre: sif };

    post(data).done(r => {
        if (r.success) {
            bootstrap.Modal.getInstance(document.getElementById('anaModal')).hide();
            showToast(r.message, 'success');
            anaTable.ajax.reload();
            loadStats();
        } else {
            showToast(r.message, 'error');
        }
    });
}

function anaSil(id) {
    const ad = anaRows[id]?.DigiturkAnaBayiler_Ad ?? '';
    confirmAction(`"${ad}" ana bayisini silmek istiyor musunuz?`, 'Bu işlem geri alınamaz!', () => {
        post({ action: 'ana_sil', id }).done(r => {
            if (r.success) { showSuccess('Silindi!', r.message); anaTable.ajax.reload(); loadStats(); }
            else showError('Hata!', r.message);
        });
    });
}

// ── Alt Bayiler ──────────────────────────────────────────────────────────────
function initAltTable() {
    altTable = $('#altTable').DataTable({
        processing: true,
        scrollX: true,
        autoWidth: false,
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        ajax: {
            url: PAGE_URL, type: 'POST',
            data: () => ({ action: 'alt_listele', ...altFilters }),
            dataSrc: r => r.success ? r.data : [],
        },
        columns: [
            { data: 'DigiturkAltBayiler_Id' },
            { data: 'AnaBayiAd', defaultContent: '-' },
            { data: 'DigiturkAltBayiler_Ad' },
            { data: 'DigiturkAltBayiler_KullaniciAdi', defaultContent: '-' },
            { data: 'Durum', render: durumBadge },
            { data: 'OlusturmaTarihi', defaultContent: '-' },
            {
                data: null, orderable: false,
                render: d => {
                    altRows[d.DigiturkAltBayiler_Id] = d;
                    let b = '';
                    // Alt bayi düzenleme yalnızca admin'e açık
                    if (perms.can_edit && perms.is_admin) b += `<button class="btn btn-sm btn-warning me-1" onclick="altModal(${d.DigiturkAltBayiler_Id})"><i class="bi bi-pencil"></i></button>`;
                    if (perms.can_delete) b += `<button class="btn btn-sm btn-danger"        onclick="altSil(${d.DigiturkAltBayiler_Id})"><i class="bi bi-trash"></i></button>`;
                    return b || '-';
                }
            }
        ],
        order: [[1, 'asc'], [2, 'asc']]
    });
}

function loadAnaBayilerDropdown(targetId, selectedVal = '') {
    post({ action: 'get_ana_bayiler' }).done(r => {
        if (!r.success) return;
        const sel = $(targetId);
        sel.find('option:not(:first)').remove();
        r.data.forEach(x => sel.append(`<option value="${x.id}">${x.ad}</option>`));
        if (selectedVal) sel.val(selectedVal).trigger('change.select2');
    });
}

function altModal(id = null) {
    const row = id ? altRows[id] : null;
    $('#alt_id').val('');
    $('#alt_modal_ana_bayi_id').val('');
    $('#alt_ad').val('');
    $('#alt_kullanici_adi').val('');
    $('#alt_sifre').val('');
    $('#alt_modal_durum').val('1');
    $('#alt_durum_row').hide();
    $('#alt_sifre_hint').hide();
    $('#alt_sifre_durum').hide();

    loadAnaBayilerDropdown('#alt_modal_ana_bayi_id', row ? row.DigiturkAltBayiler_AnaBayiId : '');
    loadAltBirimYetkileri(row ? row.DigiturkAltBayiler_Id : null);

    if (row) {
        $('#altModalTitle').text('Alt Bayi Düzenle');
        $('#alt_id').val(row.DigiturkAltBayiler_Id);
        $('#alt_ad').val(row.DigiturkAltBayiler_Ad);
        $('#alt_kullanici_adi').val(row.DigiturkAltBayiler_KullaniciAdi || '');
        $('#alt_modal_durum').val(row.Durum);
        $('#alt_durum_row').show();
        $('#alt_sifre').val(row.DigiturkAltBayiler_Sifre || '');
        $('#alt_sifre_hint').show();
    } else {
        $('#altModalTitle').text('Yeni Alt Bayi');
    }
    new bootstrap.Modal(document.getElementById('altModal')).show();
}

function altKaydet() {
    const id  = $('#alt_id').val();
    const data = {
        action:          id ? 'alt_guncelle' : 'alt_ekle',
        ana_bayi_id:     $('#alt_modal_ana_bayi_id').val(),
        ad:              $('#alt_ad').val().trim(),
        kullanici_adi:   $('#alt_kullanici_adi').val().trim(),
        sifre:           $('#alt_sifre').val(),
        durum:           $('#alt_modal_durum').val(),
        birim_yetkileri: JSON.stringify(collectAltBirimYetkileri()),
    };
    if (id) data.id = id;

    if (!data.ana_bayi_id || !data.ad) {
        showToast('Ana bayi ve ad zorunludur.', 'warning'); return;
    }

    post(data).done(r => {
        if (r.success) {
            bootstrap.Modal.getInstance(document.getElementById('altModal')).hide();
            showToast(r.message, 'success');
            altTable.ajax.reload();
            loadStats();
        } else {
            showToast(r.message, 'error');
        }
    });
}

function altSil(id) {
    const ad = altRows[id]?.DigiturkAltBayiler_Ad ?? '';
    confirmAction(`"${ad}" alt bayisini silmek istiyor musunuz?`, 'Bu işlem geri alınamaz!', () => {
        post({ action: 'alt_sil', id }).done(r => {
            if (r.success) { showSuccess('Silindi!', r.message); altTable.ajax.reload(); loadStats(); }
            else showError('Hata!', r.message);
        });
    });
}

// ── Personel ─────────────────────────────────────────────────────────────────
function initPerTable() {
    perTable = $('#perTable').DataTable({
        processing: true,
        scrollX: true,
        autoWidth: false,
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        ajax: {
            url: PAGE_URL, type: 'POST',
            data: () => ({ action: 'per_listele', ...perFilters }),
            dataSrc: r => r.success ? r.data : [],
        },
        columns: [
            { data: 'DigiturkAltBayiPersonel_Id' },
            { data: 'AnaBayiAd',   defaultContent: '-' },
            { data: 'AltBayiAd',   defaultContent: '-' },
            { data: 'DigiturkAltBayiPersonel_AdSoyad' },
            { data: 'DigiturkAltBayiPersonel_KimlikNo', defaultContent: '-' },
            { data: 'DigiturkAltBayiPersonel_KullaniciAdi', defaultContent: '-' },
            {
                data: null, orderable: false,
                render: d => tokenDurumBadge(d)
            },
            {
                data: 'BugunLogin', className: 'text-center',
                render: (d, type, row) => {
                    if (type !== 'display') return d;
                    const limit    = row.GunlukLoginLimit;
                    const kullanim = Number(d || 0);
                    const title    = `Bugün Digiturk'e giden login isteği: ${kullanim} (başarılı: ${row.BugunBasarili || 0})`;
                    if (limit === null || limit === undefined)
                        return `<span class="badge bg-light text-dark border" title="${title} — limit yok">${kullanim} / ∞</span>`;
                    const cls = kullanim >= limit ? 'bg-danger' : (kullanim > 0 ? 'bg-warning text-dark' : 'bg-success');
                    return `<span class="badge ${cls}" title="${title}">${kullanim} / ${limit}</span>`;
                }
            },
            {
                data: 'Durum',
                render: (d, type, row) => {
                    if (type !== 'display') return d;
                    if (!(perms.can_edit && perms.is_admin)) return durumBadge(d);
                    const id = row.DigiturkAltBayiPersonel_Id;
                    return `<div class="form-check form-switch d-flex justify-content-center">
                                <input class="form-check-input per-durum-switch" type="checkbox" role="switch"
                                       id="per_durum_sw_${id}" data-id="${id}" ${d == 1 ? 'checked' : ''}>
                                <label class="form-check-label" for="per_durum_sw_${id}"></label>
                            </div>`;
                }
            },
            { data: 'TokenSuresi', defaultContent: '-' },
            {
                data: null, orderable: false,
                render: d => {
                    perRows[d.DigiturkAltBayiPersonel_Id] = d;
                    let b = '';
                    if (perms.can_edit) {
                        b += `<button class="btn btn-sm btn-success me-1" onclick="perTokenGuncelle(${d.DigiturkAltBayiPersonel_Id})" title="Token Güncelle"><i class="bi bi-key"></i></button>`;
                        b += `<button class="btn btn-sm btn-warning me-1" onclick="perModal(${d.DigiturkAltBayiPersonel_Id})"><i class="bi bi-pencil"></i></button>`;
                    }
                    if (perms.can_delete) b += `<button class="btn btn-sm btn-danger" onclick="perSil(${d.DigiturkAltBayiPersonel_Id})"><i class="bi bi-trash"></i></button>`;
                    return b || '-';
                }
            }
        ],
        order: [[3, 'asc']]
    });
}

function loadAltBayilerDropdown(targetId, selectedVal = '', anaBayiId = '') {
    post({ action: 'get_alt_bayiler', ana_bayi_id: anaBayiId }).done(r => {
        if (!r.success) return;
        const sel = $(targetId);
        sel.find('option:not(:first)').remove();
        r.data.forEach(x => sel.append(`<option value="${x.id}">${x.ad}</option>`));
        if (selectedVal) sel.val(selectedVal).trigger('change.select2');
    });
}

/** Personel modalında şifre dışındaki alanları kilitler (admin olmayan kullanıcı için) */
function perModalKilit(kilitli) {
    $('#per_ad_soyad, #per_kimlik_no, #per_kullanici_adi').prop('readonly', kilitli);
    $('#per_modal_alt_bayi_id, #per_modal_durum').prop('disabled', kilitli).trigger('change.select2');
    $('#per_birim_bolum').toggle(!kilitli);
    $('#per_sadece_sifre_uyari').toggle(kilitli);
}

function perModal(id = null) {
    const row = id ? perRows[id] : null;
    $('#per_id').val('');
    $('#per_modal_alt_bayi_id').val('');
    $('#per_ad_soyad').val('');
    $('#per_kimlik_no').val('');
    $('#per_kullanici_adi').val('');
    $('#per_sifre').val('');
    $('#per_gunluk_login_limit').val('10');   // yeni personel varsayılanı (Digiturk kullanıcı başı günlük limit)
    $('#per_modal_durum').val('1');
    $('#per_durum_row').hide();
    $('#per_sifre_hint').hide();
    $('#per_sifre_durum').hide();
    $('#per_sifre_zorunlu').show();

    loadAltBayilerDropdown('#per_modal_alt_bayi_id', row ? row.DigiturkAltBayiPersonel_AltBayiId : '');
    loadPerBirimYetkileri(row ? row.DigiturkAltBayiPersonel_Id : null);

    // Admin olmayan kullanıcı mevcut personelde yalnızca şifreyi güncelleyebilir
    perModalKilit(!!row && !perms.is_admin);

    // Günlük login limiti yeni kayıtta da düzenlemede de yalnız admin tarafından doldurulur
    $('#per_gunluk_login_limit').prop('disabled', !perms.is_admin);
    $('#per_login_limit_yetki').toggle(!perms.is_admin);

    if (row) {
        $('#perModalTitle').text('Personel Düzenle');
        $('#per_id').val(row.DigiturkAltBayiPersonel_Id);
        $('#per_ad_soyad').val(row.DigiturkAltBayiPersonel_AdSoyad);
        $('#per_kimlik_no').val(row.DigiturkAltBayiPersonel_KimlikNo || '');
        $('#per_kullanici_adi').val(row.DigiturkAltBayiPersonel_KullaniciAdi || '');
        $('#per_modal_durum').val(row.Durum);
        $('#per_durum_row').show();
        $('#per_sifre').val(row.DigiturkAltBayiPersonel_Sifre || '');
        $('#per_gunluk_login_limit').val(row.GunlukLoginLimit ?? '');
        $('#per_sifre_hint').show();
        $('#per_sifre_zorunlu').hide();
    } else {
        $('#perModalTitle').text('Yeni Personel');
    }
    new bootstrap.Modal(document.getElementById('perModal')).show();
}

function perKaydet() {
    const id  = $('#per_id').val();
    const data = {
        action:          id ? 'per_guncelle' : 'per_ekle',
        alt_bayi_id:     $('#per_modal_alt_bayi_id').val(),
        ad_soyad:        $('#per_ad_soyad').val().trim(),
        kimlik_no:       $('#per_kimlik_no').val().trim(),
        kullanici_adi:   $('#per_kullanici_adi').val().trim(),
        sifre:           $('#per_sifre').val(),
        durum:           $('#per_modal_durum').val(),
        birim_yetkileri: JSON.stringify(collectPerBirimYetkileri()),
    };
    if (id) data.id = id;
    if (perms.is_admin) data.gunluk_login_limit = $('#per_gunluk_login_limit').val().trim();

    if (!data.alt_bayi_id || !data.ad_soyad || !data.kullanici_adi || (!id && !data.sifre)) {
        showToast('Zorunlu alanları doldurun.', 'warning'); return;
    }

    post(data).done(r => {
        if (r.success) {
            bootstrap.Modal.getInstance(document.getElementById('perModal')).hide();
            showToast(r.message, 'success');
            perTable.ajax.reload();
            loadStats();
        } else {
            showToast(r.message, 'error');
        }
    });
}

function perSil(id) {
    const adSoyad = perRows[id]?.DigiturkAltBayiPersonel_AdSoyad ?? '';
    confirmAction(`"${adSoyad}" personelini silmek istiyor musunuz?`, 'Bu işlem geri alınamaz!', () => {
        post({ action: 'per_sil', id }).done(r => {
            if (r.success) { showSuccess('Silindi!', r.message); perTable.ajax.reload(); loadStats(); }
            else showError('Hata!', r.message);
        });
    });
}

// ── Init ─────────────────────────────────────────────────────────────────────
$(document).ready(() => {
    loadStats();
    loadBirimler();
    if (anaSekmeGoster) initAnaTable();
    initAltTable();
    initPerTable();

    // Filtre dropdownları
    loadAnaBayilerDropdown('#alt_ana_bayi_id');
    loadAnaBayilerDropdown('#per_ana_bayi_id');
    loadAltBayilerDropdown('#per_alt_bayi_id');

    // Personel filtresi: Ana Bayi seçilince Alt Bayi listesi daralır
    $('#per_ana_bayi_id').on('change', function () {
        loadAltBayilerDropdown('#per_alt_bayi_id', '', $(this).val() || '');
    });

    // Personel tablosunda durum değiştirme (switch)
    $('#perTable tbody').on('change', '.per-durum-switch', function () {
        const $sw   = $(this);
        const id    = $sw.data('id');
        const durum = $sw.is(':checked') ? 1 : 0;
        $sw.prop('disabled', true);
        post({ action: 'per_durum_degistir', id, durum }).done(r => {
            if (r.success) {
                showToast(r.message, 'success');
                if (perRows[id]) perRows[id].Durum = durum;
                loadStats();
            } else {
                showToast(r.message, 'error');
                $sw.prop('checked', durum !== 1);
            }
        }).fail(() => {
            showToast('İşlem başarısız.', 'error');
            $sw.prop('checked', durum !== 1);
        }).always(() => $sw.prop('disabled', false));
    });

    // Select2
    setTimeout(() => {
        // Filtre select'leri (modal dışı)
        select2Init('#alt_ana_bayi_id');
        select2Init('#per_ana_bayi_id');
        select2Init('#per_alt_bayi_id');
        select2Init('#ana_durum');
        select2Init('#alt_durum');
        select2Init('#per_durum');
        // Modal select'leri — dropdownParent zorunlu, yoksa arama kutusu focus alamaz
        select2Init('#ana_modal_durum', '#anaModal');
        select2Init('#alt_modal_ana_bayi_id', '#altModal');
        select2Init('#alt_modal_durum', '#altModal');
        select2Init('#per_modal_alt_bayi_id', '#perModal');
        select2Init('#per_modal_durum', '#perModal');
    }, 200);

    // Sidebar daraltınca tabloları yeniden boyutlandır
    $('[data-lte-toggle="sidebar"]').on('click', () => {
        setTimeout(() => {
            [anaTable, altTable, perTable].forEach(t => t && t.columns.adjust().draw());
        }, 350);
    });

    // Tab değişince tablo yeniden boyutlandır
    $('#bayiTabs a').on('shown.bs.tab', () => {
        [anaTable, altTable, perTable].forEach(t => t && t.columns.adjust());
    });

    // ── Filtreler ────────────────────────────────────────────────────────────
    $('#anaFilterForm').on('submit', e => {
        e.preventDefault();
        anaFilters = { search: $('#ana_search').val(), durum: $('#ana_durum').val() };
        Object.keys(anaFilters).forEach(k => { if (!anaFilters[k]) delete anaFilters[k]; });
        anaTable.ajax.reload();
        showToast('Filtre uygulandı.', 'info');
    }).on('reset', () => {
        setTimeout(() => {
            anaFilters = {};
            $('#ana_durum').val('').trigger('change.select2');
            anaTable.ajax.reload();
        }, 50);
    });

    $('#altFilterForm').on('submit', e => {
        e.preventDefault();
        altFilters = {
            ana_bayi_id: $('#alt_ana_bayi_id').val(),
            search:      $('#alt_search').val(),
            durum:       $('#alt_durum').val()
        };
        Object.keys(altFilters).forEach(k => { if (!altFilters[k]) delete altFilters[k]; });
        altTable.ajax.reload();
        showToast('Filtre uygulandı.', 'info');
    }).on('reset', () => {
        setTimeout(() => {
            altFilters = {};
            $('#alt_ana_bayi_id').val('').trigger('change.select2');
            $('#alt_durum').val('').trigger('change.select2');
            altTable.ajax.reload();
        }, 50);
    });

    $('#perFilterForm').on('submit', e => {
        e.preventDefault();
        perFilters = {
            ana_bayi_id: $('#per_ana_bayi_id').val(),
            alt_bayi_id: $('#per_alt_bayi_id').val(),
            search:      $('#per_search').val(),
            durum:       $('#per_durum').val()
        };
        Object.keys(perFilters).forEach(k => { if (!perFilters[k]) delete perFilters[k]; });
        perTable.ajax.reload();
        showToast('Filtre uygulandı.', 'info');
    }).on('reset', () => {
        setTimeout(() => {
            perFilters = {};
            $('#per_ana_bayi_id').val('').trigger('change.select2');
            loadAltBayilerDropdown('#per_alt_bayi_id');
            $('#per_alt_bayi_id').val('').trigger('change.select2');
            $('#per_durum').val('').trigger('change.select2');
            perTable.ajax.reload();
        }, 50);
    });
});
</script>
</body>
</html>
