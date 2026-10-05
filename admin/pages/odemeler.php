<?php
/**
 * Admin Panel - Ödemeler
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Ödemeler';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Döküman upload ayarları
define('ODEME_UPLOAD_DIR', __DIR__ . '/../uploads/odemeler/');
define('ODEME_UPLOAD_URL', '/admin/uploads/odemeler/');
$izinliUzantilar = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
$maxDosyaBoyutu  = 10 * 1024 * 1024; // 10 MB

// ── VoIP ödemeleri → birim çözümü ────────────────────────────────────────────
// VoIP gider kayıtlarını cron üretir (cron/tasks.php, OdemeTuruId=4) ve
// Odemeler_KullaniciBirim_id alanını doldurmaz. Bu kayıtlarda birim ilişkisi
// Odemeler_Referans (telefon) → VoIPHesaplar → KullaniciBirimYetkileri
// junction'ı üzerinden kurulur (dashboard.php / voip-harcamalar.php ile aynı yol).
// Aşağıdaki iki parça "o" alias'lı Odemeler tablosuyla birlikte kullanılır.

// SELECT içinde: kaydın VoIP hesabına bağlı aktif birim adları (çoklu bağda virgüllü)
const ODEME_VOIP_BIRIM_ADI = "(
    SELECT STRING_AGG(kb.KullaniciBirim_Adi, ', ')
    FROM VoIPHesaplar v
    JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_VoIPHesap_id = v.VoIPHesaplar_id
    JOIN KullaniciBirim kb           ON kb.KullaniciBirim_id = kby.KullaniciBirimYetkileri_Birim_id
    WHERE v.VoIPHesaplar_TelefonNo = o.Odemeler_Referans
      AND v.Durum = 1 AND kby.Durum = 1
      AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
      AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
)";

/**
 * WHERE içinde: kaydın VoIP hesabı verilen birim id listesine bağlı mı?
 * $ph → hazır placeholder listesi (örn. "?" veya "?,?,?").
 */
function odemeVoipBirimExists(string $ph): string {
    return "EXISTS (
        SELECT 1 FROM VoIPHesaplar v
        JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_VoIPHesap_id = v.VoIPHesaplar_id
        WHERE v.VoIPHesaplar_TelefonNo = o.Odemeler_Referans
          AND v.Durum = 1 AND kby.Durum = 1
          AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
    )";
}

// Ortak filtre WHERE üretici (list + stats + excel ortak kullanır)
// $birimKisitli true ise sonuçlar yalnız $izinliBirimIdleri ile sınırlanır (birim_gor).
function odemelerFiltre(array $post, bool $birimKisitli = false, array $izinliBirimIdleri = []): array {
    $where  = ["1=1"];
    $params = [];

    if (!empty($post['tur'])) {
        $where[]  = "o.Odemeler_OdemeTuruId = ?";
        $params[] = (int)$post['tur'];
    }
    if (isset($post['yon']) && $post['yon'] !== '') {
        $where[]  = "t.OdemeTurleri_GelirMi = ?";
        $params[] = (int)$post['yon'];
    }
    if (!empty($post['birim'])) {
        // Doğrudan atanmış birim VEYA VoIP hesabı üzerinden bağlı birim
        $where[]  = "(o.Odemeler_KullaniciBirim_id = ? OR " . odemeVoipBirimExists('?') . ")";
        $params[] = (int)$post['birim'];
        $params[] = (int)$post['birim'];
    }
    if (!empty($post['tarih_bas'])) {
        $where[]  = "o.Odemeler_Tarih >= ?";
        $params[] = $post['tarih_bas'];
    }
    if (!empty($post['tarih_bit'])) {
        $where[]  = "o.Odemeler_Tarih <= ?";
        $params[] = $post['tarih_bit'];
    }
    if (!empty($post['search'])) {
        $where[]  = "(o.Odemeler_Referans LIKE ? OR o.Odemeler_Aciklama LIKE ?)";
        $params[] = "%" . $post['search'] . "%";
        $params[] = "%" . $post['search'] . "%";
    }
    if (isset($post['status']) && $post['status'] !== '') {
        $where[]  = "o.Durum = ?";
        $params[] = (int)$post['status'];
    }

    // Birim görünürlük kısıtı (birim_gor): yalnız izinli birimler; birimsiz (NULL) kayıtlar dışlanır.
    // VoIP kayıtlarında birim alanı NULL olduğundan junction üzerinden de kontrol edilir.
    if ($birimKisitli) {
        if (empty($izinliBirimIdleri)) {
            $where[] = "1=0";
        } else {
            $ph = implode(',', array_fill(0, count($izinliBirimIdleri), '?'));
            $where[] = "(o.Odemeler_KullaniciBirim_id IN ($ph) OR " . odemeVoipBirimExists($ph) . ")";
            foreach ($izinliBirimIdleri as $b) $params[] = $b;
            foreach ($izinliBirimIdleri as $b) $params[] = $b;
        }
    }

    return [implode(" AND ", $where), $params];
}

// ── Birim görünürlük kısıtı (birim_gor): admin değil + can_view_birim → kendi birimi + alt birimleri ──
$birimKisitli      = (!$permissions['is_admin'] && !empty($permissions['can_view_birim']));
$izinliBirimIdleri = [];
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
        $izinliBirimIdleri = array_map(fn($r) => (int)$r['KullaniciBirim_id'], $rows);
    }
    // Birim atanmamış kısıtlı kullanıcı → boş liste → hiçbir kayıt görünmez (güvenli varsayılan)
}

// Bir ödeme kaydının birimi mevcut kullanıcının kapsamında mı? (birim_gor IDOR koruması)
$odemeErisim = function (int $id) use ($db, $birimKisitli, $izinliBirimIdleri): bool {
    if (!$birimKisitli) return true;
    if ($id <= 0) return false;
    if (empty($izinliBirimIdleri)) return false;

    $ph  = implode(',', array_fill(0, count($izinliBirimIdleri), '?'));
    // Doğrudan atanmış birim VEYA (VoIP kayıtlarında) telefon → hesap → birim junction'ı
    $rec = $db->fetchOne(
        "SELECT 1 AS ok FROM Odemeler o
         WHERE o.Odemeler_Id = ?
           AND (o.Odemeler_KullaniciBirim_id IN ($ph) OR " . odemeVoipBirimExists($ph) . ")",
        array_merge([$id], $izinliBirimIdleri, $izinliBirimIdleri)
    );
    return (bool)$rec;
};

// Excel indir (JSON header'dan önce yakalanır)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'excel_indir') {
    [$whereClause, $params] = odemelerFiltre($_POST, $birimKisitli, $izinliBirimIdleri);

    $list = $db->fetchAll("
        SELECT
            CONVERT(VARCHAR(10), o.Odemeler_Tarih, 104) as Tarih,
            t.OdemeTurleri_Ad,
            CASE WHEN t.OdemeTurleri_GelirMi = 1 THEN 'Gelir' ELSE 'Gider' END as Yon,
            COALESCE(b.KullaniciBirim_Adi, " . ODEME_VOIP_BIRIM_ADI . ") AS KullaniciBirim_Adi,
            o.Odemeler_Tutar,
            o.Odemeler_Referans,
            o.Odemeler_Aciklama,
            CASE WHEN o.Durum = 1 THEN 'Aktif' ELSE 'Pasif' END as DurumText,
            CONVERT(VARCHAR(19), o.GuncellemeTarihi, 120) as GuncellemeTarihi
        FROM Odemeler o
        LEFT JOIN OdemeTurleri t ON o.Odemeler_OdemeTuruId = t.OdemeTurleri_Id
        LEFT JOIN KullaniciBirim b ON o.Odemeler_KullaniciBirim_id = b.KullaniciBirim_id
        WHERE $whereClause
        ORDER BY o.Odemeler_Tarih DESC, o.Odemeler_Id DESC
    ", $params);

    $filename = 'odemeler_' . date('Y-m-d_H-i-s') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">';
    echo '<xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
    echo '<x:Name>Odemeler</x:Name>';
    echo '<x:WorksheetOptions><x:Print><x:ValidPrinterinfo/></x:Print></x:WorksheetOptions>';
    echo '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml></head><body>';
    echo '<table border="1">';
    echo '<thead><tr style="background-color:#0d6efd;color:#fff;font-weight:bold;">';
    echo '<th>Tarih</th><th>Ödeme Türü</th><th>Yön</th><th>Birim</th><th>Tutar</th>';
    echo '<th>Referans</th><th>Açıklama</th><th>Durum</th><th>Güncelleme Tarihi</th>';
    echo '</tr></thead><tbody>';
    foreach ($list as $r) {
        $tutar = number_format((float)($r['Odemeler_Tutar'] ?? 0), 2, ',', '.');
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['Tarih'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['OdemeTurleri_Ad'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['Yon'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['KullaniciBirim_Adi'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($tutar) . '</td>';
        echo '<td>' . htmlspecialchars($r['Odemeler_Referans'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['Odemeler_Aciklama'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['DurumText'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['GuncellemeTarihi'] ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'list':
                [$whereClause, $params] = odemelerFiltre($_POST, $birimKisitli, $izinliBirimIdleri);

                $list = $db->fetchAll("
                    SELECT
                        o.Odemeler_Id,
                        o.Odemeler_OdemeTuruId,
                        o.Odemeler_Tutar,
                        CONVERT(VARCHAR(10), o.Odemeler_Tarih, 104) as Tarih,
                        CONVERT(VARCHAR(10), o.Odemeler_Tarih, 23) as Tarih_ISO,
                        o.Odemeler_Referans,
                        o.Odemeler_Aciklama,
                        o.Odemeler_Dokuman,
                        o.Durum,
                        o.Odemeler_KullaniciBirim_id,
                        COALESCE(b.KullaniciBirim_Adi, " . ODEME_VOIP_BIRIM_ADI . ") AS KullaniciBirim_Adi,
                        t.OdemeTurleri_Ad,
                        t.OdemeTurleri_GelirMi,
                        CONVERT(VARCHAR(19), o.GuncellemeTarihi, 120) as GuncellemeTarihi
                    FROM Odemeler o
                    LEFT JOIN OdemeTurleri t ON o.Odemeler_OdemeTuruId = t.OdemeTurleri_Id
                    LEFT JOIN KullaniciBirim b ON o.Odemeler_KullaniciBirim_id = b.KullaniciBirim_id
                    WHERE $whereClause
                    ORDER BY o.Odemeler_Tarih DESC, o.Odemeler_Id DESC
                ", $params);

                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get':
                $id  = (int)($_POST['id'] ?? 0);
                if (!$odemeErisim($id)) {
                    echo json_encode(['success' => false, 'message' => 'Bu kayıt için yetkiniz yok']);
                    break;
                }
                $row = $db->fetchOne("
                    SELECT o.*, CONVERT(VARCHAR(10), o.Odemeler_Tarih, 23) as Tarih_ISO
                    FROM Odemeler o WHERE o.Odemeler_Id = ?
                ", [$id]);
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

                // Birim görünürlük kısıtı (birim_gor)
                if ($birimKisitli) {
                    // Düzenlemede mevcut kayıt kapsamda olmalı (IDOR)
                    if ($id > 0 && !$odemeErisim($id)) {
                        echo json_encode(['success' => false, 'message' => 'Bu kayıt için yetkiniz yok']);
                        break;
                    }
                    // Kısıtlı kullanıcı yalnız yetkili olduğu bir birime kayıt yapabilir (birimsiz olamaz)
                    $secilenBirim = !empty($_POST['birim']) ? (int)$_POST['birim'] : null;
                    if ($secilenBirim === null || !in_array($secilenBirim, $izinliBirimIdleri, true)) {
                        echo json_encode(['success' => false, 'message' => 'Yetkili olduğunuz bir birim seçmelisiniz']);
                        break;
                    }
                }

                $turId  = (int)($_POST['odeme_turu_id'] ?? 0);
                $tutar  = str_replace(',', '.', trim($_POST['tutar'] ?? '0'));
                $tarih  = trim($_POST['tarih'] ?? '');

                if ($turId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme türü zorunludur!']);
                    break;
                }
                if (!is_numeric($tutar) || (float)$tutar <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Geçerli bir tutar giriniz!']);
                    break;
                }
                if ($tarih === '') {
                    echo json_encode(['success' => false, 'message' => 'Tarih zorunludur!']);
                    break;
                }

                // Döküman yükleme
                $dokumanYolu = null;
                if (!empty($_FILES['dokuman']['name']) && $_FILES['dokuman']['error'] === UPLOAD_ERR_OK) {
                    global $izinliUzantilar, $maxDosyaBoyutu;
                    $ext = strtolower(pathinfo($_FILES['dokuman']['name'], PATHINFO_EXTENSION));

                    if (!in_array($ext, $izinliUzantilar)) {
                        echo json_encode(['success' => false, 'message' => 'Geçersiz dosya türü! İzinli: ' . implode(', ', $izinliUzantilar)]);
                        break;
                    }
                    if ($_FILES['dokuman']['size'] > $maxDosyaBoyutu) {
                        echo json_encode(['success' => false, 'message' => 'Dosya boyutu 10 MB sınırını aşıyor!']);
                        break;
                    }
                    if (!is_dir(ODEME_UPLOAD_DIR)) {
                        mkdir(ODEME_UPLOAD_DIR, 0775, true);
                    }
                    $fileName = 'odeme_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (!move_uploaded_file($_FILES['dokuman']['tmp_name'], ODEME_UPLOAD_DIR . $fileName)) {
                        echo json_encode(['success' => false, 'message' => 'Dosya yüklenemedi!']);
                        break;
                    }
                    $dokumanYolu = ODEME_UPLOAD_URL . $fileName;
                }

                $data = [
                    'Odemeler_OdemeTuruId' => $turId,
                    'Odemeler_Tutar'       => (float)$tutar,
                    'Odemeler_Tarih'       => $tarih,
                    'Odemeler_Referans'    => trim($_POST['referans'] ?? '') ?: null,
                    'Odemeler_Aciklama'    => trim($_POST['aciklama'] ?? '') ?: null,
                    'Odemeler_KullaniciBirim_id' => !empty($_POST['birim']) ? (int)$_POST['birim'] : null,
                    'Durum'                => isset($_POST['durum']) ? 1 : 0,
                ];

                if ($id > 0) {
                    // Yeni döküman yüklendiyse eskisini sil
                    if ($dokumanYolu !== null) {
                        $eski = $db->fetchOne("SELECT Odemeler_Dokuman FROM Odemeler WHERE Odemeler_Id = ?", [$id]);
                        if (!empty($eski['Odemeler_Dokuman'])) {
                            $eskiYol = __DIR__ . '/../' . ltrim(str_replace('/admin/', '', $eski['Odemeler_Dokuman']), '/');
                            if (is_file($eskiYol)) @unlink($eskiYol);
                        }
                        $data['Odemeler_Dokuman'] = $dokumanYolu;
                    }
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->update('Odemeler', $data, ['Odemeler_Id' => $id]);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['Odemeler_Dokuman']     = $dokumanYolu;
                    $data['OlusturanKullanici']   = $user['kullanici_id'];
                    $data['OlusturmaTarihi']      = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $result = $db->insert('Odemeler', $data);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt eklendi' : 'Ekleme hatası']);
                }
                break;

            // Excel toplu yükleme — önizleme: abone no → birim eşleme (musteri-arama mantığı) + mükerrer kontrolü
            case 'excel_onizle':
                if (!$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }
                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!is_array($rows) || empty($rows)) {
                    echo json_encode(['success' => false, 'message' => 'İşlenecek satır bulunamadı!']);
                    break;
                }
                if (count($rows) > 500) {
                    echo json_encode(['success' => false, 'message' => 'En fazla 500 satır yüklenebilir!']);
                    break;
                }

                $aboneler = [];
                foreach ($rows as $r) {
                    $a = preg_replace('/\D/', '', (string)($r['abone'] ?? ''));
                    if ($a !== '') $aboneler[$a] = $a;
                }
                $aboneler = array_values($aboneler);

                // Abone no → birim (en güncel, birimi olan IRIS kaydı öncelikli)
                $birimMap = [];
                if (!empty($aboneler)) {
                    $ph  = implode(',', array_fill(0, count($aboneler), '?'));
                    $res = $db->fetchAll("
                        SELECT x.DtMusteriNo, x.BirimId, x.BirimAdi
                        FROM (
                            SELECT
                                ir.IrisRapor_DtMusteriNo AS DtMusteriNo,
                                bm.BirimId, bm.BirimAdi,
                                ROW_NUMBER() OVER (
                                    PARTITION BY ir.IrisRapor_DtMusteriNo
                                    ORDER BY CASE WHEN bm.BirimId IS NULL THEN 1 ELSE 0 END, ir.IrisRapor_TalepGirisTarihi DESC
                                ) AS rn
                            FROM DigiturkIrisRapor ir
                            OUTER APPLY (
                                SELECT TOP 1 kb.KullaniciBirim_id AS BirimId, kb.KullaniciBirim_Adi AS BirimAdi
                                FROM DigiturkAltBayiler a
                                JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_AltBayi_id = a.DigiturkAltBayiler_Id
                                JOIN KullaniciBirim kb           ON kb.KullaniciBirim_id = kby.KullaniciBirimYetkileri_Birim_id
                                WHERE a.DigiturkAltBayiler_Ad = ir.IrisRapor_TalebiGirenPersonelAltbayi
                                  AND a.Durum = 1 AND kby.Durum = 1
                                  AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
                                  AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
                                ORDER BY kby.KullaniciBirimYetkileri_id DESC
                            ) bm
                            WHERE ir.IrisRapor_DtMusteriNo IN ($ph)
                        ) x WHERE x.rn = 1
                    ", $aboneler);
                    foreach ($res as $r) {
                        $birimMap[(string)$r['DtMusteriNo']] = [
                            'id' => $r['BirimId'] !== null ? (int)$r['BirimId'] : null,
                            'ad' => $r['BirimAdi'],
                        ];
                    }
                }

                // Mükerrer kontrolü: aynı referans (abone no) + tarih + tutar daha önce kaydedilmiş mi?
                $mevcutKeys = [];
                if (!empty($aboneler)) {
                    $ph = implode(',', array_fill(0, count($aboneler), '?'));
                    $mv = $db->fetchAll("
                        SELECT Odemeler_Referans AS ref,
                               CONVERT(VARCHAR(10), Odemeler_Tarih, 23) AS tarih,
                               Odemeler_Tutar AS tutar
                        FROM Odemeler WHERE Odemeler_Referans IN ($ph)
                    ", $aboneler);
                    foreach ($mv as $m) {
                        $mevcutKeys[$m['ref'] . '|' . $m['tarih'] . '|' . number_format((float)$m['tutar'], 2, '.', '')] = true;
                    }
                }

                $out    = [];
                $icKeys = []; // dosya içi mükerrer takibi
                foreach ($rows as $r) {
                    $abone = preg_replace('/\D/', '', (string)($r['abone'] ?? ''));
                    if ($abone === '') continue;
                    $tarih = (string)($r['tarih'] ?? '');
                    $tutar = is_numeric($r['tutar'] ?? null) ? round((float)$r['tutar'], 2) : null;

                    $key      = $abone . '|' . $tarih . '|' . number_format((float)$tutar, 2, '.', '');
                    $mukerrer = isset($mevcutKeys[$key]) || isset($icKeys[$key]);
                    $icKeys[$key] = true;

                    $b       = $birimMap[$abone] ?? null;
                    $birimId = $b['id'] ?? null;
                    // Kısıtlı kullanıcı: yetki dışı birim otomatik atanamaz (dropdown'dan izinli birim seçmeli)
                    if ($birimId !== null && $birimKisitli && !in_array($birimId, $izinliBirimIdleri, true)) {
                        $birimId = null;
                    }

                    $out[] = [
                        'abone'     => $abone,
                        'tarih'     => $tarih,
                        'tutar'     => $tutar,
                        'aciklama'  => trim((string)($r['aciklama'] ?? '')),
                        'birim_id'  => $birimId,
                        'birim_adi' => $b['ad'] ?? null,
                        'mukerrer'  => $mukerrer,
                    ];
                }
                echo json_encode(['success' => true, 'data' => $out]);
                break;

            // Excel toplu yükleme — kaydet (mükerrerler otomatik atlanır)
            case 'toplu_kaydet':
                if (!$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }
                $turId = (int)($_POST['odeme_turu_id'] ?? 0);
                if ($turId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme türü zorunludur!']);
                    break;
                }
                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!is_array($rows) || empty($rows)) {
                    echo json_encode(['success' => false, 'message' => 'Kaydedilecek satır bulunamadı!']);
                    break;
                }
                if (count($rows) > 500) {
                    echo json_encode(['success' => false, 'message' => 'En fazla 500 satır yüklenebilir!']);
                    break;
                }

                // Güncel mükerrer seti (kaydetme anında tekrar kontrol)
                $aboneler = [];
                foreach ($rows as $r) {
                    $a = preg_replace('/\D/', '', (string)($r['abone'] ?? ''));
                    if ($a !== '') $aboneler[$a] = $a;
                }
                $aboneler   = array_values($aboneler);
                $mevcutKeys = [];
                if (!empty($aboneler)) {
                    $ph = implode(',', array_fill(0, count($aboneler), '?'));
                    $mv = $db->fetchAll("
                        SELECT Odemeler_Referans AS ref,
                               CONVERT(VARCHAR(10), Odemeler_Tarih, 23) AS tarih,
                               Odemeler_Tutar AS tutar
                        FROM Odemeler WHERE Odemeler_Referans IN ($ph)
                    ", $aboneler);
                    foreach ($mv as $m) {
                        $mevcutKeys[$m['ref'] . '|' . $m['tarih'] . '|' . number_format((float)$m['tutar'], 2, '.', '')] = true;
                    }
                }

                $eklendi = 0; $atlanan = 0; $hatali = 0;
                $simdi   = date('Y-m-d H:i:s');
                foreach ($rows as $r) {
                    $abone = preg_replace('/\D/', '', (string)($r['abone'] ?? ''));
                    $tarih = (string)($r['tarih'] ?? '');
                    $tutar = is_numeric($r['tutar'] ?? null) ? round((float)$r['tutar'], 2) : 0;
                    $birim = !empty($r['birim']) ? (int)$r['birim'] : null;

                    if ($abone === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || $tutar <= 0) {
                        $hatali++;
                        continue;
                    }
                    // Kısıtlı kullanıcı yalnız yetkili olduğu bir birime kayıt yapabilir (save ile aynı kural)
                    if ($birimKisitli && ($birim === null || !in_array($birim, $izinliBirimIdleri, true))) {
                        $hatali++;
                        continue;
                    }
                    // Mükerrer → otomatik atla
                    $key = $abone . '|' . $tarih . '|' . number_format($tutar, 2, '.', '');
                    if (isset($mevcutKeys[$key])) {
                        $atlanan++;
                        continue;
                    }
                    $mevcutKeys[$key] = true; // dosya içi mükerrer de atlansın

                    $ok = $db->insert('Odemeler', [
                        'Odemeler_OdemeTuruId'       => $turId,
                        'Odemeler_Tutar'             => $tutar,
                        'Odemeler_Tarih'             => $tarih,
                        'Odemeler_Referans'          => $abone,
                        'Odemeler_Aciklama'          => trim((string)($r['aciklama'] ?? '')) ?: null,
                        'Odemeler_KullaniciBirim_id' => $birim,
                        'Durum'                      => 1,
                        'OlusturanKullanici'         => $user['kullanici_id'],
                        'OlusturmaTarihi'            => $simdi,
                        'GuncelleyenKullanici'       => $user['kullanici_id'],
                        'GuncellemeTarihi'           => $simdi,
                    ]);
                    if ($ok) $eklendi++; else $hatali++;
                }

                $msg = $eklendi . ' kayıt eklendi';
                if ($atlanan > 0) $msg .= ', ' . $atlanan . ' mükerrer atlandı';
                if ($hatali  > 0) $msg .= ', ' . $hatali . ' satır hatalı';
                echo json_encode(['success' => true, 'message' => $msg, 'eklendi' => $eklendi, 'atlanan' => $atlanan, 'hatali' => $hatali]);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id = (int)($_POST['id'] ?? 0);
                if (!$odemeErisim($id)) {
                    echo json_encode(['success' => false, 'message' => 'Bu kayıt için yetkiniz yok']);
                    break;
                }

                $eski = $db->fetchOne("SELECT Odemeler_Dokuman FROM Odemeler WHERE Odemeler_Id = ?", [$id]);
                $result = $db->delete('Odemeler', ['Odemeler_Id' => $id]);
                if ($result && !empty($eski['Odemeler_Dokuman'])) {
                    $eskiYol = __DIR__ . '/../' . ltrim(str_replace('/admin/', '', $eski['Odemeler_Dokuman']), '/');
                    if (is_file($eskiYol)) @unlink($eskiYol);
                }
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt silindi' : 'Silme hatası']);
                break;

            case 'stats':
                [$whereClause, $params] = odemelerFiltre($_POST, $birimKisitli, $izinliBirimIdleri);
                $row = $db->fetchOne("
                    SELECT
                        COUNT(*) as kayit,
                        ISNULL(SUM(CASE WHEN t.OdemeTurleri_GelirMi = 1 THEN o.Odemeler_Tutar ELSE 0 END), 0) as gelir,
                        ISNULL(SUM(CASE WHEN t.OdemeTurleri_GelirMi = 0 THEN o.Odemeler_Tutar ELSE 0 END), 0) as gider
                    FROM Odemeler o
                    LEFT JOIN OdemeTurleri t ON o.Odemeler_OdemeTuruId = t.OdemeTurleri_Id
                    WHERE $whereClause
                ", $params);

                $gelir = (float)($row['gelir'] ?? 0);
                $gider = (float)($row['gider'] ?? 0);
                echo json_encode(['success' => true, 'data' => [
                    'kayit' => (int)($row['kayit'] ?? 0),
                    'gelir' => $gelir,
                    'gider' => $gider,
                    'net'   => $gelir - $gider,
                ]]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// Aktif ödeme türleri (dropdownlar için)
$turler = $db->fetchAll("
    SELECT OdemeTurleri_Id, OdemeTurleri_Ad, OdemeTurleri_GelirMi
    FROM OdemeTurleri WHERE Durum = 1 ORDER BY OdemeTurleri_Ad
");

// Aktif birimler (dropdownlar için) — kısıtlı kullanıcıya yalnız izinli birimler
if ($birimKisitli && empty($izinliBirimIdleri)) {
    $birimler = [];
} else {
    $birimlerWhere  = "Durum = 1";
    $birimlerParams = [];
    if ($birimKisitli) {
        $birimlerWhere .= " AND KullaniciBirim_id IN (" . implode(',', array_fill(0, count($izinliBirimIdleri), '?')) . ")";
        $birimlerParams = $izinliBirimIdleri;
    }
    $birimler = $db->fetchAll("
        SELECT KullaniciBirim_id, KullaniciBirim_Adi
        FROM KullaniciBirim WHERE $birimlerWhere ORDER BY KullaniciBirim_Adi
    ", $birimlerParams);
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
        .status-badge  { padding: .25rem .5rem; border-radius: .25rem; font-size: .875rem; }
        .status-active   { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .yon-badge {
            display: inline-flex; align-items: center; gap: .3rem;
            padding: .25rem .55rem; border-radius: 1rem;
            font-weight: 600; font-size: .8rem; color: #fff;
        }
        .yon-gelir { background-color: #198754; }
        .yon-gider { background-color: #dc3545; }
        .tutar-gelir { color: #198754; font-weight: 600; }
        .tutar-gider { color: #dc3545; font-weight: 600; }
        .info-box-number.tutar { font-size: 1.15rem; }
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
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-arrow-down-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Gelir</span>
                                <span class="info-box-number tutar" id="stat-gelir">0,00 ₺</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-arrow-up-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Gider</span>
                                <span class="info-box-number tutar" id="stat-gider">0,00 ₺</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-wallet2"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Net Bakiye</span>
                                <span class="info-box-number tutar" id="stat-net">0,00 ₺</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-secondary">
                            <span class="info-box-icon"><i class="bi bi-receipt"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Kayıt Sayısı</span>
                                <span class="info-box-number" id="stat-kayit">0</span>
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
                                    <label class="form-label">Ödeme Türü</label>
                                    <select class="form-select select2" name="tur" id="filter_tur">
                                        <option value="">Tümü</option>
                                        <?php foreach ($turler as $t): ?>
                                            <option value="<?= $t['OdemeTurleri_Id'] ?>"><?= htmlspecialchars($t['OdemeTurleri_Ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Yön</label>
                                    <select class="form-select select2" name="yon" id="filter_yon">
                                        <option value="">Tümü</option>
                                        <option value="1">Gelir</option>
                                        <option value="0">Gider</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Başlangıç Tarihi</label>
                                    <input type="date" class="form-control" name="tarih_bas" id="filter_tarih_bas">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Bitiş Tarihi</label>
                                    <input type="date" class="form-control" name="tarih_bit" id="filter_tarih_bit">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Ara (Referans / Açıklama)</label>
                                    <input type="text" class="form-control" name="search" id="filter_search" placeholder="VoIP no, kampanya, açıklama...">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Birim</label>
                                    <select class="form-select select2" name="birim" id="filter_birim">
                                        <option value="">Tümü</option>
                                        <?php foreach ($birimler as $b): ?>
                                            <option value="<?= $b['KullaniciBirim_id'] ?>"><?= htmlspecialchars($b['KullaniciBirim_Adi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Durum</label>
                                    <select class="form-select select2" name="status" id="filter_status">
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
                        <h3 class="card-title">Ödeme Kayıtları</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-success btn-sm me-1" id="excelIndir">
                                <i class="bi bi-file-earmark-excel"></i> Excel indir
                            </button>
                            <?php if ($permissions['can_add']): ?>
                            <button type="button" class="btn btn-info btn-sm me-1" data-bs-toggle="modal" data-bs-target="#excelModal">
                                <i class="bi bi-file-earmark-arrow-up"></i> Excel ile Toplu Yükle
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#kayitModal" onclick="resetForm()">
                                <i class="bi bi-plus-circle"></i> Yeni Ödeme
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>Ödeme Türü</th>
                                    <th>Yön</th>
                                    <th>Birim</th>
                                    <th class="text-end">Tutar</th>
                                    <th>Referans</th>
                                    <th>Açıklama</th>
                                    <th>Döküman</th>
                                    <th>Durum</th>
                                    <th style="width:100px">İşlemler</th>
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
                <h5 class="modal-title" id="kayitModalLabel">Yeni Ödeme Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kayitForm">
                <div class="modal-body">
                    <input type="hidden" id="rec_id" name="id">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Ödeme Türü <span class="text-danger">*</span></label>
                            <select class="form-select select2-modal" id="odeme_turu_id" name="odeme_turu_id" required style="width:100%">
                                <option value="">Seçiniz...</option>
                                <?php foreach ($turler as $t): ?>
                                    <option value="<?= $t['OdemeTurleri_Id'] ?>" data-yon="<?= $t['OdemeTurleri_GelirMi'] ?>">
                                        <?= htmlspecialchars($t['OdemeTurleri_Ad']) ?> (<?= $t['OdemeTurleri_GelirMi'] == 1 ? 'Gelir' : 'Gider' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tutar (₺) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" class="form-control" id="tutar" name="tutar" required placeholder="0,00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tarih <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="tarih" name="tarih" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Birim</label>
                            <select class="form-select select2-modal" id="birim" name="birim" style="width:100%">
                                <option value="">Seçiniz... (boş bırakılabilir)</option>
                                <?php foreach ($birimler as $b): ?>
                                    <option value="<?= $b['KullaniciBirim_id'] ?>"><?= htmlspecialchars($b['KullaniciBirim_Adi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Referans</label>
                            <input type="text" class="form-control" id="referans" name="referans" placeholder="VoIP No, kampanya adı vb.">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" id="aciklama" name="aciklama" rows="2" placeholder="İsteğe bağlı açıklama"></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Döküman (Fatura/Dekont)</label>
                            <input type="file" class="form-control" id="dokuman" name="dokuman" accept=".pdf,.jpg,.jpeg,.png,.webp">
                            <small class="text-muted">İzinli: PDF, JPG, PNG, WEBP — En fazla 10 MB</small>
                            <div id="mevcutDokuman" class="mt-2" style="display:none">
                                <span class="badge text-bg-info"><i class="bi bi-paperclip"></i> Mevcut döküman:</span>
                                <a href="#" id="mevcutDokumanLink" target="_blank">Görüntüle</a>
                                <small class="text-muted d-block">Yeni dosya seçerseniz mevcut döküman değiştirilir.</small>
                            </div>
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

<!-- Excel Toplu Yükleme Modal -->
<?php if ($permissions['can_add']): ?>
<div class="modal fade" id="excelModal" tabindex="-1" aria-labelledby="excelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="excelModalLabel"><i class="bi bi-file-earmark-excel"></i> Excel ile Toplu Ödeme Yükle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-5">
                        <label class="form-label">Ödeme Türü <span class="text-danger">*</span></label>
                        <select class="form-select" id="excel_tur" style="width:100%">
                            <option value="">Seçiniz...</option>
                            <?php foreach ($turler as $t): ?>
                                <option value="<?= $t['OdemeTurleri_Id'] ?>">
                                    <?= htmlspecialchars($t['OdemeTurleri_Ad']) ?> (<?= $t['OdemeTurleri_GelirMi'] == 1 ? 'Gelir' : 'Gider' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Excel Dosyası <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="excelDosya" accept=".xlsx,.xls">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-primary w-100" id="excelOnizleBtn"><i class="bi bi-eye"></i> Önizle</button>
                    </div>
                    <div class="col-12">
                        <small class="text-muted">
                            Beklenen kolonlar: <strong>ABONE NO</strong>, <strong>İŞLEM TARİHİ</strong>, <strong>TUTAR</strong>, <strong>İŞLEM AÇIKLAMA</strong>.
                            Abone no başında <strong>10</strong> yoksa otomatik eklenir; birim, müşteri arama mantığıyla otomatik bulunur.
                            Aynı abone no + tarih + tutar daha önce kaydedilmişse satır <strong>otomatik atlanır</strong>.
                        </small>
                    </div>
                </div>
                <div id="excelOnizlemeAlan" style="display:none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong>Önizleme</strong>
                        <span class="text-muted" id="excelOzet"></span>
                    </div>
                    <div class="table-responsive" style="max-height:420px;overflow-y:auto">
                        <table class="table table-sm table-bordered align-middle" id="excelOnizlemeTable">
                            <thead>
                                <tr>
                                    <th style="width:34px"><input type="checkbox" id="excelTumu" checked></th>
                                    <th>Abone No</th>
                                    <th>Tarih</th>
                                    <th class="text-end">Tutar</th>
                                    <th style="min-width:220px">Birim</th>
                                    <th>Açıklama</th>
                                    <th>Durum</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                <button type="button" class="btn btn-primary" id="excelKaydetBtn" style="display:none"><i class="bi bi-save"></i> Seçilenleri Kaydet</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/admin/assets/vendor/sheetjs/xlsx.full.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
    const permissions = {
        canAdd:    <?= $permissions['can_add']    ? 'true' : 'false' ?>,
        canEdit:   <?= $permissions['can_edit']   ? 'true' : 'false' ?>,
        canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
    };

    const BIRIMLER = <?= json_encode(array_map(fn($b) => ['id' => (int)$b['KullaniciBirim_id'], 'ad' => $b['KullaniciBirim_Adi']], $birimler), JSON_UNESCAPED_UNICODE) ?>;
    const EXCEL_MAX_SATIR = 500;

    let kayitModal, dataTable, currentFilters = {}, excelSatirlar = [];

    function formatTutar(val) {
        return Number(val || 0).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
    }

    $(document).ready(function () {
        kayitModal = new bootstrap.Modal(document.getElementById('kayitModal'));

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'desc']],
            columnDefs: [
                { orderable: false, targets: [7, 9] },
                {
                    // Hucrede ISO tarih (YYYY-AA-GG) tutulur, ekranda GG.AA.YYYY gosterilir.
                    targets: 0,
                    render: function (data, type) {
                        if (!data) return type === 'display' ? '-' : '';
                        if (type === 'sort' || type === 'type') return data;
                        var p = String(data).split('-');
                        return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : data;
                    }
                }
            ],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        });

        loadStats();
        loadList();

        // Filtre alanlarını custom.js (.form-select) otomatik Select2 yapıyor — burada tekrar init etmiyoruz.
        // Modal içindeki select'i çift init'e karşı koruyup dropdownParent ile yeniden kuruyoruz.
        if ($('#odeme_turu_id').hasClass('select2-hidden-accessible')) $('#odeme_turu_id').select2('destroy');
        $('#odeme_turu_id').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#kayitModal') });

        if ($('#birim').hasClass('select2-hidden-accessible')) $('#birim').select2('destroy');
        $('#birim').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#kayitModal'), allowClear: true, placeholder: 'Seçiniz... (boş bırakılabilir)' });

        if (document.getElementById('excelModal')) {
            if ($('#excel_tur').hasClass('select2-hidden-accessible')) $('#excel_tur').select2('destroy');
            $('#excel_tur').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#excelModal') });

            $('#excelOnizleBtn').on('click', excelOnizle);
            $('#excelKaydetBtn').on('click', excelKaydet);
            $('#excelTumu').on('change', function () {
                $('.excel-sec:not(:disabled)').prop('checked', this.checked);
                excelOzetGuncelle();
            });
            $(document).on('change', '.excel-sec', excelOzetGuncelle);

            document.getElementById('excelModal').addEventListener('hidden.bs.modal', function () {
                document.getElementById('excelDosya').value = '';
                $('#excel_tur').val('').trigger('change');
                $('#excelOnizlemeTable tbody').empty();
                $('#excelOnizlemeAlan').hide();
                $('#excelKaydetBtn').hide();
                excelSatirlar = [];
            });
        }

        $('#kayitForm').on('submit', function (e) {
            e.preventDefault();
            saveRecord();
        });

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            currentFilters = {
                tur:       $('#filter_tur').val(),
                yon:       $('#filter_yon').val(),
                tarih_bas: $('#filter_tarih_bas').val(),
                tarih_bit: $('#filter_tarih_bit').val(),
                search:    $('#filter_search').val(),
                birim:     $('#filter_birim').val(),
                status:    $('#filter_status').val()
            };
            Object.keys(currentFilters).forEach(k => { if (!currentFilters[k] && currentFilters[k] !== '0') delete currentFilters[k]; });
            loadList();
            loadStats();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filterForm')[0].reset();
            $('#filter_tur, #filter_yon, #filter_birim, #filter_status').val('').trigger('change');
            currentFilters = {};
            loadList();
            loadStats();
            showToast('Filtreler temizlendi', 'info');
        });

        $('#excelIndir').on('click', function () {
            const form = $('<form>', { method: 'POST', action: '', target: '_blank' });
            form.append($('<input>', { type: 'hidden', name: 'action', value: 'excel_indir' }));
            Object.keys(currentFilters).forEach(function (k) {
                form.append($('<input>', { type: 'hidden', name: k, value: currentFilters[k] }));
            });
            $('body').append(form);
            form.submit();
            form.remove();
            showToast('Excel dosyası indiriliyor...', 'info');
        });

        document.getElementById('kayitModal').addEventListener('hidden.bs.modal', resetForm);
    });

    function loadStats() {
        $.post('', { action: 'stats', ...currentFilters }, function (r) {
            if (!r.success) return;
            $('#stat-gelir').text(formatTutar(r.data.gelir));
            $('#stat-gider').text(formatTutar(r.data.gider));
            $('#stat-net').text(formatTutar(r.data.net));
            $('#stat-kayit').text(r.data.kayit);
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
            const gelir = row.OdemeTurleri_GelirMi == 1;
            const tur   = escapeHtml(row.OdemeTurleri_Ad || '-');

            const yon = gelir
                ? `<span class="yon-badge yon-gelir"><i class="bi bi-arrow-down-circle"></i> Gelir</span>`
                : `<span class="yon-badge yon-gider"><i class="bi bi-arrow-up-circle"></i> Gider</span>`;

            const birim = row.KullaniciBirim_Adi
                ? escapeHtml(row.KullaniciBirim_Adi)
                : '<span class="text-muted">-</span>';

            const tutar = `<span class="${gelir ? 'tutar-gelir' : 'tutar-gider'}">${gelir ? '+' : '-'}${formatTutar(row.Odemeler_Tutar)}</span>`;

            const aciklama = row.Odemeler_Aciklama
                ? `<span title="${escapeHtml(row.Odemeler_Aciklama)}">${escapeHtml(String(row.Odemeler_Aciklama).substring(0, 40))}${String(row.Odemeler_Aciklama).length > 40 ? '…' : ''}</span>`
                : '<span class="text-muted">-</span>';

            const dokuman = row.Odemeler_Dokuman
                ? `<a href="${escapeHtml(row.Odemeler_Dokuman)}" target="_blank" class="btn btn-sm btn-outline-info" title="Dökümanı aç"><i class="bi bi-file-earmark-text"></i></a>`
                : '<span class="text-muted">-</span>';

            const durum = row.Durum == 1
                ? `<span class="status-badge status-active">Aktif</span>`
                : `<span class="status-badge status-inactive">Pasif</span>`;

            let islemler = '';
            if (permissions.canEdit)
                islemler += `<button class="btn btn-sm btn-warning me-1" onclick="editRecord(${row.Odemeler_Id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
            if (permissions.canDelete)
                islemler += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${row.Odemeler_Id})" title="Sil"><i class="bi bi-trash"></i></button>`;
            if (!islemler) islemler = '<span class="text-muted">-</span>';

            dataTable.row.add([
                row.Tarih_ISO || '',
                tur,
                yon,
                birim,
                tutar,
                escapeHtml(row.Odemeler_Referans || '-'),
                aciklama,
                dokuman,
                durum,
                islemler
            ]);
        });
        dataTable.draw();
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function resetForm() {
        document.getElementById('kayitForm').reset();
        document.getElementById('rec_id').value = '';
        document.getElementById('kayitModalLabel').textContent = 'Yeni Ödeme Ekle';
        document.getElementById('durum').checked = true;
        document.getElementById('mevcutDokuman').style.display = 'none';
        $('#odeme_turu_id').val('').trigger('change');
        $('#birim').val('').trigger('change');
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

    function editRecord(id) {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'get', id: id },
            dataType: 'json',
            success: function (r) {
                if (!r.success || !r.data) { showToast('Kayıt bulunamadı', 'error'); return; }
                const d = r.data;
                document.getElementById('rec_id').value   = d.Odemeler_Id;
                $('#odeme_turu_id').val(String(d.Odemeler_OdemeTuruId)).trigger('change');
                document.getElementById('tutar').value    = parseFloat(d.Odemeler_Tutar);
                document.getElementById('tarih').value    = d.Tarih_ISO || '';
                document.getElementById('referans').value = d.Odemeler_Referans || '';
                document.getElementById('aciklama').value = d.Odemeler_Aciklama || '';
                $('#birim').val(d.Odemeler_KullaniciBirim_id ? String(d.Odemeler_KullaniciBirim_id) : '').trigger('change');
                document.getElementById('durum').checked  = d.Durum == 1;

                if (d.Odemeler_Dokuman) {
                    document.getElementById('mevcutDokuman').style.display = 'block';
                    document.getElementById('mevcutDokumanLink').href = d.Odemeler_Dokuman;
                } else {
                    document.getElementById('mevcutDokuman').style.display = 'none';
                }

                document.getElementById('kayitModalLabel').textContent = 'Ödeme Düzenle';
                kayitModal.show();
            },
            error: function () { showToast('Kayıt yüklenirken hata oluştu', 'error'); }
        });
    }

    // ===== Excel Toplu Yükleme =====

    function excelNorm(s) {
        return String(s || '').toLocaleUpperCase('tr-TR').replace(/[^A-ZÇĞİÖŞÜ0-9]/g, '');
    }

    // Date nesnesi / "2.06.2026" / "02/06/2026" → "2026-06-02"
    function excelTarihParse(v) {
        if (v instanceof Date && !isNaN(v)) {
            const y = v.getFullYear(), mo = String(v.getMonth() + 1).padStart(2, '0'), d = String(v.getDate()).padStart(2, '0');
            return y + '-' + mo + '-' + d;
        }
        const m = String(v || '').trim().match(/(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/);
        if (!m) return null;
        return m[3] + '-' + m[2].padStart(2, '0') + '-' + m[1].padStart(2, '0');
    }

    // 1552.87 (number) / "1.552,87" (TR) / "1,552.87" (EN) → sayı
    function excelTutarParse(v) {
        if (v === null || v === undefined || v === '') return null;
        if (typeof v === 'number') return isNaN(v) ? null : v;
        let s = String(v).trim().replace(/[^\d.,-]/g, '');
        if (!s) return null;
        const lc = s.lastIndexOf(','), ld = s.lastIndexOf('.');
        if (lc > -1 && ld > -1) {
            // İkisi de var → sonda gelen ondalık ayracı, diğeri binlik
            s = lc > ld ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');
        } else if (lc > -1) {
            // Yalnız virgül: sondan 3 hane ise binlik (1,552), değilse ondalık (349,00)
            s = /,\d{3}$/.test(s) ? s.replace(/,/g, '') : s.replace(',', '.');
        } else if (ld > -1 && /\.\d{3}$/.test(s)) {
            // Yalnız nokta ve sondan 3 hane → binlik ayracı (1.552)
            s = s.replace(/\./g, '');
        }
        const n = parseFloat(s);
        return isNaN(n) ? null : n;
    }

    function excelOnizle() {
        if (!$('#excel_tur').val()) { showToast('Önce ödeme türü seçiniz', 'warning'); return; }
        const file = document.getElementById('excelDosya').files[0];
        if (!file) { showToast('Lütfen bir Excel dosyası seçiniz', 'warning'); return; }

        const reader = new FileReader();
        reader.onload = function (e) {
            try {
                const wb   = XLSX.read(e.target.result, { type: 'array', cellDates: true });
                const ws   = wb.Sheets[wb.SheetNames[0]];
                const rows = XLSX.utils.sheet_to_json(ws, { header: 1, blankrows: false, defval: '', raw: true });

                // Başlık satırını bul (ilk 10 satırda ABONE içeren hücre)
                let hIdx = -1, col = {};
                for (let i = 0; i < Math.min(rows.length, 10); i++) {
                    const n  = rows[i].map(excelNorm);
                    const ai = n.findIndex(x => x.includes('ABONE'));
                    if (ai === -1) continue;
                    hIdx = i;
                    col = {
                        abone:    ai,
                        tarih:    n.findIndex(x => x.includes('TARİH')),
                        tutar:    n.findIndex(x => x.includes('TUTAR')),
                        aciklama: n.findIndex(x => x.includes('AÇIKLAMA'))
                    };
                    break;
                }
                if (hIdx === -1 || col.tarih === -1 || col.tutar === -1) {
                    showError('Format hatası!', 'ABONE NO / İŞLEM TARİHİ / TUTAR kolonları bulunamadı.');
                    return;
                }

                const liste = [];
                for (let r = hIdx + 1; r < rows.length; r++) {
                    const s = rows[r];
                    let abone = String(s[col.abone] || '').replace(/\D/g, '');
                    if (!abone) continue;
                    if (!abone.startsWith('10')) abone = '10' + abone; // musteri-arama kuralı
                    liste.push({
                        abone:    abone,
                        tarih:    excelTarihParse(s[col.tarih]),
                        tutar:    excelTutarParse(s[col.tutar]),
                        aciklama: col.aciklama !== -1 ? String(s[col.aciklama] || '').trim() : ''
                    });
                }
                if (!liste.length) { showError('Veri yok!', 'Excel içinde işlenecek satır bulunamadı.'); return; }
                if (liste.length > EXCEL_MAX_SATIR) { showError('Limit aşıldı!', 'En fazla ' + EXCEL_MAX_SATIR + ' satır yüklenebilir.'); return; }

                // Sunucudan birim eşleme + mükerrer bilgisi
                $.post('', { action: 'excel_onizle', rows: JSON.stringify(liste) }, function (r) {
                    if (!r.success) { showError('Hata!', r.message); return; }
                    excelSatirlar = r.data;
                    renderExcelOnizleme();
                }, 'json').fail(function () { showError('Hata!', 'Sunucuya ulaşılamıyor.'); });
            } catch (err) {
                showError('Okuma hatası!', 'Excel okunamadı: ' + err.message);
            }
        };
        reader.readAsArrayBuffer(file);
    }

    function renderExcelOnizleme() {
        const tb = $('#excelOnizlemeTable tbody').empty();
        excelSatirlar.forEach(function (s, i) {
            const hatali     = !s.tarih || !s.tutar || s.tutar <= 0;
            const secilebilir = !s.mukerrer && !hatali;

            let durum;
            if (s.mukerrer)       durum = '<span class="badge text-bg-secondary">Mükerrer — atlanacak</span>';
            else if (hatali)      durum = '<span class="badge text-bg-danger">Tarih/tutar hatalı</span>';
            else if (!s.birim_id) durum = '<span class="badge text-bg-warning">Birim bulunamadı</span>';
            else                  durum = '<span class="badge text-bg-success">Hazır</span>';

            let opts = '<option value="">— Birim yok —</option>';
            BIRIMLER.forEach(function (b) {
                opts += '<option value="' + b.id + '"' + (s.birim_id == b.id ? ' selected' : '') + '>' + escapeHtml(b.ad) + '</option>';
            });

            const tarihG   = s.tarih ? s.tarih.split('-').reverse().join('.') : '-';
            const acKisa   = String(s.aciklama || '');
            tb.append(
                '<tr class="' + (secilebilir ? '' : 'table-light text-muted') + '">' +
                '<td><input type="checkbox" class="excel-sec" data-i="' + i + '"' + (secilebilir ? ' checked' : ' disabled') + '></td>' +
                '<td>' + escapeHtml(s.abone) + '</td>' +
                '<td>' + tarihG + '</td>' +
                '<td class="text-end">' + (s.tutar ? formatTutar(s.tutar) : '-') + '</td>' +
                '<td><select class="excel-birim" data-i="' + i + '" style="width:100%"' + (secilebilir ? '' : ' disabled') + '>' + opts + '</select></td>' +
                '<td><span title="' + escapeHtml(acKisa) + '">' + escapeHtml(acKisa.substring(0, 50)) + (acKisa.length > 50 ? '…' : '') + '</span></td>' +
                '<td>' + durum + '</td>' +
                '</tr>'
            );
        });

        // Satır birim dropdown'ları: sonradan eklendiği için custom.js init etmez — searchable Select2'yi elle kur
        $('#excelOnizlemeTable .excel-birim').each(function () {
            $(this).select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#excelModal') });
        });

        $('#excelTumu').prop('checked', true);
        $('#excelOnizlemeAlan').show();
        $('#excelKaydetBtn').show();
        excelOzetGuncelle();
    }

    function excelOzetGuncelle() {
        let adet = 0, toplam = 0;
        $('.excel-sec:checked').each(function () {
            adet++;
            toplam += excelSatirlar[$(this).data('i')].tutar || 0;
        });
        const mukerrer = excelSatirlar.filter(s => s.mukerrer).length;
        $('#excelOzet').html(
            'Seçili: <strong>' + adet + '</strong> satır — Toplam: <strong>' + formatTutar(toplam) + '</strong>' +
            (mukerrer ? ' — <span class="text-secondary">Mükerrer: ' + mukerrer + '</span>' : '')
        );
    }

    function excelKaydet() {
        const turId = $('#excel_tur').val();
        if (!turId) { showToast('Ödeme türü seçiniz', 'warning'); return; }

        const rows = [];
        $('.excel-sec:checked').each(function () {
            const i = $(this).data('i');
            const s = excelSatirlar[i];
            rows.push({
                abone:    s.abone,
                tarih:    s.tarih,
                tutar:    s.tutar,
                aciklama: s.aciklama,
                birim:    $('.excel-birim[data-i="' + i + '"]').val() || ''
            });
        });
        if (!rows.length) { showToast('Kaydedilecek satır seçilmedi', 'warning'); return; }

        confirmAction(
            rows.length + ' satır kaydedilecek. Onaylıyor musunuz?',
            'Seçilen ödeme türüyle toplu kayıt yapılacak. Mükerrer satırlar otomatik atlanır.',
            function () {
                $.post('', { action: 'toplu_kaydet', odeme_turu_id: turId, rows: JSON.stringify(rows) }, function (r) {
                    if (r.success) {
                        showSuccess('Tamamlandı!', r.message);
                        bootstrap.Modal.getInstance(document.getElementById('excelModal')).hide();
                        loadList();
                        loadStats();
                    } else {
                        showError('Hata!', r.message);
                    }
                }, 'json').fail(function () { showError('Hata!', 'Sunucuya ulaşılamıyor.'); });
            }
        );
    }

    function deleteRecord(id) {
        confirmAction(
            'Bu ödeme kaydını silmek istediğinize emin misiniz?',
            'Döküman dosyası da silinecek. Bu işlem geri alınamaz!',
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
