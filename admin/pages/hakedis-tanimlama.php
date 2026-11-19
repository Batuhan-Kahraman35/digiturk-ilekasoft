<?php
/**
 * Admin Panel - Hakediş Tanımlama
 * Alt bayi bazında, dönem (ayın 4'ü - sonraki ayın 3'ü) için
 * Kampanya Tanımı x Adet Skalası matrisinde hakediş tutarları.
 * Kampanya satırları HakedisKampanyaTanimlari tablosundan (FK) gelir.
 * Skala sütunları alt bayinin dönemdeki skala setinden gelir (HakedisAltBayiSkalaSetleri;
 * ataması yoksa varsayılan set). Setler ve atamalar "Skala Setleri" modalından yönetilir.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/HakedisHesap.php';   // hakedisBayiSkalaSetId, hakedisSetKademeleri
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Hakediş Tanımlama';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ── Sabit listeler ───────────────────────────────────────────────
// Adet skalası: setler + kademeler (HakedisSkalaSetleri / HakedisSkalaKademeleri).
// $SKALALAR = tüm kademelerin etiketi (liste/filtre); matris sütunları bayinin setinden gelir.
$SKALA_SETLERI = [];   // [setId => ['ad', 'varsayilan', 'kademeler' => [id => ad]]]
foreach ($db->fetchAll("
    SELECT s.HakedisSkalaSetleri_id AS setId, s.HakedisSkalaSetleri_Ad AS setAd,
           s.HakedisSkalaSetleri_Varsayilan AS varsayilan,
           k.HakedisSkalaKademeleri_id AS id, k.HakedisSkalaKademeleri_Ad AS ad
    FROM dbo.HakedisSkalaSetleri s
    LEFT JOIN dbo.HakedisSkalaKademeleri k
      ON k.HakedisSkalaKademeleri_Set_id = s.HakedisSkalaSetleri_id AND k.Durum = 1
    WHERE s.Durum = 1
    ORDER BY s.HakedisSkalaSetleri_Varsayilan DESC, s.HakedisSkalaSetleri_Ad, k.HakedisSkalaKademeleri_Sira
") as $sk) {
    $sid = (int)$sk['setId'];
    if (!isset($SKALA_SETLERI[$sid])) {
        $SKALA_SETLERI[$sid] = ['ad' => $sk['setAd'], 'varsayilan' => (bool)$sk['varsayilan'], 'kademeler' => []];
    }
    if ($sk['id'] !== null) $SKALA_SETLERI[$sid]['kademeler'][(int)$sk['id']] = $sk['ad'];
}
$SKALALAR = [];
foreach ($db->fetchAll("SELECT HakedisSkalaKademeleri_id AS id, HakedisSkalaKademeleri_Ad AS ad FROM dbo.HakedisSkalaKademeleri") as $sk) {
    $SKALALAR[(int)$sk['id']] = $sk['ad'];
}
$VARSAYILAN_SET_ID = null;
foreach ($SKALA_SETLERI as $sid => $s) { if ($s['varsayilan']) { $VARSAYILAN_SET_ID = $sid; break; } }

$AYLAR    = [1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran',
             7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'];

// Kampanya tanımları (matris satırları) — HakedisKampanyaTanimlari tablosundan.
// Aynı kampanya adı birden çok kayıtta olabilir (farklı TalepTuru/MemoKodu);
// matris/filtre için ada göre tekilleştir, temsilci id = MIN(id).
$KAMPANYA_TANIMLARI = $db->fetchAll("
    SELECT MIN(HakedisKampanyaTanimlari_id) AS id,
           HakedisKampanyaTanimlari_KampanyaAdi AS ad
    FROM dbo.HakedisKampanyaTanimlari
    WHERE Durum = 1
    GROUP BY HakedisKampanyaTanimlari_KampanyaAdi
    ORDER BY HakedisKampanyaTanimlari_KampanyaAdi
");

/**
 * Dönem tarih aralığı: başlangıç = ayın 4'ü, bitiş = sonraki ayın 3'ü
 */
function donemTarihleri(int $yil, int $ay): array {
    $baslangic   = sprintf('%04d-%02d-04', $yil, $ay);
    $sonrakiAy   = $ay + 1;
    $sonrakiYil  = $yil;
    if ($sonrakiAy > 12) { $sonrakiAy = 1; $sonrakiYil++; }
    $bitis = sprintf('%04d-%02d-03', $sonrakiYil, $sonrakiAy);
    return [$baslangic, $bitis];
}

/**
 * Bir hakediş kaydının alt bayisi, mevcut kullanıcının erişim kapsamında mı?
 * birim_gor IDOR koruması — tekil güncelle/sil işlemlerinde kullanılır.
 */
function hakedisKayitErisim($db, int $id, callable $altBayiErisim): bool {
    if ($id <= 0) return false;
    $rec = $db->fetchOne("SELECT DigiturkHakedisTanimlari_AltBayi_id AS ab FROM DigiturkHakedisTanimlari WHERE DigiturkHakedisTanimlari_id = ?", [$id]);
    if (!$rec) return false;
    return $altBayiErisim((int)$rec['ab']);
}

// ── Birim görünürlük kısıtı (birim_gor): admin değil + can_view_birim → kendi birimi + alt birimleri ──
// Bu sayfa alt bayi bazlı; izinli birimlerdeki alt bayi id'leri KullaniciBirimYetkileri junction'ından bulunur.
$birimKisitli        = (!$permissions['is_admin'] && !empty($permissions['can_view_birim']));
$izinliBirimIdleri   = [];
$izinliAltBayiIdleri = [];
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
    if ($izinliBirimIdleri) {
        $ph = implode(',', array_fill(0, count($izinliBirimIdleri), '?'));
        $abRows = $db->fetchAll("
            SELECT DISTINCT kby.KullaniciBirimYetkileri_AltBayi_id AS id
            FROM KullaniciBirimYetkileri kby
            WHERE kby.KullaniciBirimYetkileri_AltBayi_id IS NOT NULL
              AND kby.Durum = 1
              AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
              AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
              AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
        ", $izinliBirimIdleri);
        $izinliAltBayiIdleri = array_map(fn($r) => (int)$r['id'], $abRows);
    }
    // Birim/alt bayi yoksa boş liste → hiçbir kayıt görünmez (güvenli varsayılan)
}

// Kısıtlı kullanıcı bu alt bayiyi görebilir/işleyebilir mi?
$altBayiErisim = function (int $abId) use ($birimKisitli, $izinliAltBayiIdleri): bool {
    if (!$birimKisitli) return true;
    return in_array($abId, $izinliAltBayiIdleri, true);
};

// ── AJAX işlemleri ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // Aktif alt bayiler (Select2) — kısıtlı kullanıcıya yalnız izinli alt bayiler
            case 'altbayiler':
                if ($birimKisitli && empty($izinliAltBayiIdleri)) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $abWhere = "a.Durum = 1";
                $abParams = [];
                if ($birimKisitli) {
                    $abWhere .= " AND a.DigiturkAltBayiler_Id IN (" . implode(',', array_fill(0, count($izinliAltBayiIdleri), '?')) . ")";
                    $abParams = $izinliAltBayiIdleri;
                }
                // Etiket "ANA BAYİ — ALT BAYİ"; aynı adlı alt bayiler ancak ana bayisiyle ayrılabilir
                $list = $db->fetchAll("
                    SELECT a.DigiturkAltBayiler_Id,
                           a.DigiturkAltBayiler_Ad,
                           ISNULL(an.DigiturkAnaBayiler_Ad, N'') AS AnaBayiAd,
                           CASE WHEN an.DigiturkAnaBayiler_Ad IS NULL THEN a.DigiturkAltBayiler_Ad
                                ELSE an.DigiturkAnaBayiler_Ad + N' — ' + a.DigiturkAltBayiler_Ad
                           END AS Etiket
                    FROM DigiturkAltBayiler a
                    LEFT JOIN DigiturkAnaBayiler an ON an.DigiturkAnaBayiler_Id = a.DigiturkAltBayiler_AnaBayiId
                    WHERE $abWhere
                    ORDER BY AnaBayiAd, a.DigiturkAltBayiler_Ad
                ", $abParams);
                echo json_encode(['success' => true, 'data' => $list], JSON_UNESCAPED_UNICODE);
                break;

            // İstatistik kutuları
            case 'stats':
                $sWhere = '';
                $sParams = [];
                if ($birimKisitli) {
                    if (empty($izinliAltBayiIdleri)) {
                        echo json_encode(['success' => true, 'data' => ['toplam' => 0, 'altbayi' => 0, 'donem' => 0]]);
                        break;
                    }
                    $sWhere = " WHERE DigiturkHakedisTanimlari_AltBayi_id IN (" . implode(',', array_fill(0, count($izinliAltBayiIdleri), '?')) . ")";
                    $sParams = $izinliAltBayiIdleri;
                }
                $stats = [
                    'toplam'   => $db->fetchOne("SELECT COUNT(*) c FROM DigiturkHakedisTanimlari$sWhere", $sParams)['c'] ?? 0,
                    'altbayi'  => $db->fetchOne("SELECT COUNT(DISTINCT DigiturkHakedisTanimlari_AltBayi_id) c FROM DigiturkHakedisTanimlari$sWhere", $sParams)['c'] ?? 0,
                    'donem'    => $db->fetchOne("SELECT COUNT(DISTINCT CONCAT(DigiturkHakedisTanimlari_DonemYil,'-',DigiturkHakedisTanimlari_DonemAy)) c FROM DigiturkHakedisTanimlari$sWhere", $sParams)['c'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            // Filtreli liste (her hücre = bir satır)
            case 'list':
                $altbayi      = (int)($_POST['altbayi'] ?? 0);
                $yil          = (int)($_POST['yil'] ?? 0);
                $ay           = (int)($_POST['ay'] ?? 0);
                $kampanyaTanim = (int)($_POST['kampanya_tanim'] ?? 0);
                $skala        = (int)($_POST['skala'] ?? 0);
                $tutarMin     = $_POST['tutar_min'] ?? '';
                $tutarMax     = $_POST['tutar_max'] ?? '';

                $where  = ["1=1"];
                $params = [];

                if ($altbayi)      { $where[] = "h.DigiturkHakedisTanimlari_AltBayi_id = ?";        $params[] = $altbayi; }
                if ($yil)          { $where[] = "h.DigiturkHakedisTanimlari_DonemYil = ?";          $params[] = $yil; }
                if ($ay)           { $where[] = "h.DigiturkHakedisTanimlari_DonemAy = ?";           $params[] = $ay; }
                if ($kampanyaTanim){ $where[] = "h.DigiturkHakedisTanimlari_KampanyaTanim_id = ?";  $params[] = $kampanyaTanim; }
                if ($skala)        { $where[] = "h.DigiturkHakedisTanimlari_AdetSkalasiKodu = ?";   $params[] = $skala; }
                if ($tutarMin !== ''){ $where[] = "h.DigiturkHakedisTanimlari_Tutar >= ?";          $params[] = (float)$tutarMin; }
                if ($tutarMax !== ''){ $where[] = "h.DigiturkHakedisTanimlari_Tutar <= ?";          $params[] = (float)$tutarMax; }

                // Birim görünürlük kısıtı: yalnız izinli alt bayiler
                if ($birimKisitli) {
                    if (empty($izinliAltBayiIdleri)) {
                        echo json_encode(['success' => true, 'data' => []]);
                        break;
                    }
                    $where[] = "h.DigiturkHakedisTanimlari_AltBayi_id IN (" . implode(',', array_fill(0, count($izinliAltBayiIdleri), '?')) . ")";
                    foreach ($izinliAltBayiIdleri as $abId) $params[] = $abId;
                }

                $whereClause = implode(" AND ", $where);

                $list = $db->fetchAll("
                    SELECT
                        h.DigiturkHakedisTanimlari_id,
                        h.DigiturkHakedisTanimlari_AltBayi_id,
                        h.DigiturkHakedisTanimlari_DonemYil,
                        h.DigiturkHakedisTanimlari_DonemAy,
                        CONVERT(VARCHAR(10), h.DigiturkHakedisTanimlari_BaslangicTarihi, 104) AS Baslangic,
                        CONVERT(VARCHAR(10), h.DigiturkHakedisTanimlari_BitisTarihi, 104)     AS Bitis,
                        h.DigiturkHakedisTanimlari_KampanyaTanim_id,
                        h.DigiturkHakedisTanimlari_AdetSkalasiKodu,
                        h.DigiturkHakedisTanimlari_Tutar,
                        CASE WHEN ban.DigiturkAnaBayiler_Ad IS NULL THEN b.DigiturkAltBayiler_Ad
                             ELSE ban.DigiturkAnaBayiler_Ad + N' — ' + b.DigiturkAltBayiler_Ad
                        END AS AltBayiAd,
                        kt.HakedisKampanyaTanimlari_KampanyaAdi AS KampanyaAdi,
                        CONVERT(VARCHAR(19), h.GuncellemeTarihi, 120) AS GuncellemeTarihi,
                        k.kullanici_ad + ' ' + k.kullanici_soyad AS GuncelleyenAd
                    FROM DigiturkHakedisTanimlari h
                    LEFT JOIN DigiturkAltBayiler b ON h.DigiturkHakedisTanimlari_AltBayi_id = b.DigiturkAltBayiler_Id
                    LEFT JOIN DigiturkAnaBayiler ban ON ban.DigiturkAnaBayiler_Id = b.DigiturkAltBayiler_AnaBayiId
                    LEFT JOIN HakedisKampanyaTanimlari kt ON h.DigiturkHakedisTanimlari_KampanyaTanim_id = kt.HakedisKampanyaTanimlari_id
                    LEFT JOIN kullanicilar k ON h.GuncelleyenKullanici = k.kullanici_id
                    WHERE $whereClause
                    ORDER BY ban.DigiturkAnaBayiler_Ad, b.DigiturkAltBayiler_Ad,
                             h.DigiturkHakedisTanimlari_DonemYil DESC,
                             h.DigiturkHakedisTanimlari_DonemAy DESC,
                             kt.HakedisKampanyaTanimlari_KampanyaAdi,
                             h.DigiturkHakedisTanimlari_AdetSkalasiKodu
                ", $params);

                echo json_encode(['success' => true, 'data' => $list]);
                break;

            // Matris: bayinin dönemdeki skala seti (sütunlar) + tek ayda mevcut değerler
            case 'matris_get':
                $altbayi = (int)($_POST['altbayi'] ?? 0);
                $yil     = (int)($_POST['yil'] ?? 0);
                $aylar   = $_POST['aylar'] ?? [];
                if (!is_array($aylar)) $aylar = [$aylar];
                $aylar = array_values(array_unique(array_filter(array_map('intval', $aylar), fn($a) => $a >= 1 && $a <= 12)));
                sort($aylar);

                if (!$yil || empty($aylar)) {
                    echo json_encode(['success' => false, 'message' => 'Yıl ve ay seçilmelidir']);
                    break;
                }
                if ($altbayi && !$altBayiErisim($altbayi)) {
                    echo json_encode(['success' => false, 'message' => 'Bu alt bayi için yetkiniz yok']);
                    break;
                }

                // Her ayın seti; aylar farklı setlerdeyse matris tek seferde girilemez
                $aySetleri = [];
                foreach ($aylar as $a) $aySetleri[$a] = hakedisBayiSkalaSetId($db, $altbayi ?: null, $yil, $a);
                $setIdler = array_values(array_unique(array_filter($aySetleri)));
                if (count($setIdler) !== 1) {
                    $detay = [];
                    foreach ($aySetleri as $a => $sid) $detay[] = $AYLAR[$a] . ': ' . ($SKALA_SETLERI[$sid]['ad'] ?? 'set yok');
                    echo json_encode([
                        'success' => false, 'setFarkli' => true,
                        'message' => 'Seçili aylar farklı skala setlerinde (' . implode(', ', $detay) . '). Ayları ayrı ayrı girin.',
                    ], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $setId     = $setIdler[0];
                $kademeler = hakedisSetKademeleri($db, $setId);

                $matris = [];
                $digerSet = 0;   // bu dönemde başka setin kademelerine yazılmış tanım sayısı
                if ($altbayi && count($aylar) === 1) {
                    $kIdler = array_column($kademeler, 'id');
                    $rows = $db->fetchAll("
                        SELECT DigiturkHakedisTanimlari_KampanyaTanim_id AS k,
                               DigiturkHakedisTanimlari_AdetSkalasiKodu AS s,
                               DigiturkHakedisTanimlari_Tutar AS t
                        FROM DigiturkHakedisTanimlari
                        WHERE DigiturkHakedisTanimlari_AltBayi_id = ?
                          AND DigiturkHakedisTanimlari_DonemYil = ?
                          AND DigiturkHakedisTanimlari_DonemAy = ?
                    ", [$altbayi, $yil, $aylar[0]]);
                    foreach ($rows as $r) {
                        if (in_array((int)$r['s'], $kIdler, true)) $matris[$r['k']][$r['s']] = $r['t'];
                        else $digerSet++;
                    }
                }

                echo json_encode([
                    'success'   => true,
                    'data'      => $matris,
                    'set'       => ['id' => $setId, 'ad' => $SKALA_SETLERI[$setId]['ad'] ?? '', 'varsayilan' => $setId === $VARSAYILAN_SET_ID],
                    'kademeler' => $kademeler,
                    'digerSet'  => $digerSet,
                ], JSON_UNESCAPED_UNICODE);
                break;

            // Matris kaydet (kampanya tanımı x skala upsert / boş hücre sil)
            case 'matris_save':
                if (!$permissions['can_add'] && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt yetkiniz yok!']);
                    break;
                }

                $altbayi  = (int)($_POST['altbayi'] ?? 0);
                $yil      = (int)($_POST['yil'] ?? 0);
                $aylar    = $_POST['aylar'] ?? [];
                $tutarlar = $_POST['tutar'] ?? []; // [kampanyaTanimId][skalaKodu] = deger

                if (!is_array($aylar)) $aylar = [$aylar];
                $aylar = array_values(array_unique(array_filter(array_map('intval', $aylar), fn($a) => $a >= 1 && $a <= 12)));

                if (!$altbayi || !$yil || empty($aylar)) {
                    echo json_encode(['success' => false, 'message' => 'Alt bayi, yıl ve en az bir ay seçilmelidir']);
                    break;
                }
                if ($yil < 2000 || $yil > 2100) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz dönem']);
                    break;
                }
                if (!$altBayiErisim($altbayi)) {
                    echo json_encode(['success' => false, 'message' => 'Bu alt bayi için yetkiniz yok']);
                    break;
                }

                // Kademeler bayinin dönemdeki setinden; tüm aylar aynı sette olmalı.
                // Yalnız o setin kademelerine yazılır/silinir, başka setin kayıtlarına dokunulmaz.
                $kaydetSetler = [];
                foreach ($aylar as $a) $kaydetSetler[$a] = hakedisBayiSkalaSetId($db, $altbayi, $yil, $a);
                $kaydetSetIdler = array_values(array_unique(array_filter($kaydetSetler)));
                if (count($kaydetSetIdler) !== 1) {
                    echo json_encode(['success' => false, 'message' => 'Seçili aylar farklı skala setlerinde. Ayları ayrı ayrı kaydedin.']);
                    break;
                }
                $kaydetKademeler = array_column(hakedisSetKademeleri($db, $kaydetSetIdler[0]), 'id');
                if (empty($kaydetKademeler)) {
                    echo json_encode(['success' => false, 'message' => 'Bayinin skala setinde aktif kademe yok']);
                    break;
                }

                // Geçerli (aktif) kampanya tanımı id'leri — ada göre tekil, temsilci = MIN(id)
                $gecerliTanimlar = array_map('intval', array_column(
                    $db->fetchAll("
                        SELECT MIN(HakedisKampanyaTanimlari_id) AS id
                        FROM HakedisKampanyaTanimlari
                        WHERE Durum = 1
                        GROUP BY HakedisKampanyaTanimlari_KampanyaAdi
                    "),
                    'id'
                ));

                if (empty($gecerliTanimlar)) {
                    echo json_encode(['success' => false, 'message' => 'Aktif kampanya tanımı yok! Önce Hakediş Kampanya Tanımlama sayfasından kampanya ekleyin.']);
                    break;
                }

                $now = date('Y-m-d H:i:s');
                $uid = $user['kullanici_id'];

                $eklendi = $guncellendi = $silindi = 0;

                $upsert = function ($ay, $baslangic, $bitis, $tanimId, $s, $raw)
                          use ($db, $altbayi, $yil, $now, $uid, &$eklendi, &$guncellendi, &$silindi) {
                    $raw = is_string($raw) ? trim($raw) : $raw;

                    $mevcut = $db->fetchOne("
                        SELECT DigiturkHakedisTanimlari_id
                        FROM DigiturkHakedisTanimlari
                        WHERE DigiturkHakedisTanimlari_AltBayi_id = ?
                          AND DigiturkHakedisTanimlari_DonemYil = ?
                          AND DigiturkHakedisTanimlari_DonemAy = ?
                          AND DigiturkHakedisTanimlari_KampanyaTanim_id = ?
                          AND DigiturkHakedisTanimlari_AdetSkalasiKodu = ?
                    ", [$altbayi, $yil, $ay, $tanimId, $s]);

                    // Boş hücre → varsa sil
                    if ($raw === '' || $raw === null) {
                        if ($mevcut) {
                            $db->delete('DigiturkHakedisTanimlari', ['DigiturkHakedisTanimlari_id' => $mevcut['DigiturkHakedisTanimlari_id']]);
                            $silindi++;
                        }
                        return;
                    }

                    $tutar = (float)str_replace(',', '.', $raw);

                    if ($mevcut) {
                        $db->update('DigiturkHakedisTanimlari', [
                            'DigiturkHakedisTanimlari_Tutar'           => $tutar,
                            'DigiturkHakedisTanimlari_BaslangicTarihi' => $baslangic,
                            'DigiturkHakedisTanimlari_BitisTarihi'     => $bitis,
                            'GuncelleyenKullanici'                     => $uid,
                            'GuncellemeTarihi'                         => $now,
                        ], ['DigiturkHakedisTanimlari_id' => $mevcut['DigiturkHakedisTanimlari_id']]);
                        $guncellendi++;
                    } else {
                        $db->insert('DigiturkHakedisTanimlari', [
                            'DigiturkHakedisTanimlari_AltBayi_id'       => $altbayi,
                            'DigiturkHakedisTanimlari_DonemYil'         => $yil,
                            'DigiturkHakedisTanimlari_DonemAy'          => $ay,
                            'DigiturkHakedisTanimlari_BaslangicTarihi'  => $baslangic,
                            'DigiturkHakedisTanimlari_BitisTarihi'      => $bitis,
                            'DigiturkHakedisTanimlari_KampanyaTanim_id' => $tanimId,
                            'DigiturkHakedisTanimlari_AdetSkalasiKodu'  => $s,
                            'DigiturkHakedisTanimlari_Tutar'            => $tutar,
                            'OlusturanKullanici'                        => $uid,
                            'OlusturmaTarihi'                           => $now,
                            'GuncelleyenKullanici'                      => $uid,
                            'GuncellemeTarihi'                          => $now,
                            'Durum'                                     => 1,
                        ]);
                        $eklendi++;
                    }
                };

                foreach ($aylar as $ay) {
                    [$baslangic, $bitis] = donemTarihleri($yil, $ay);
                    foreach ($gecerliTanimlar as $tanimId) {
                        foreach ($kaydetKademeler as $s) {
                            $upsert($ay, $baslangic, $bitis, $tanimId, $s, $tutarlar[$tanimId][$s] ?? '');
                        }
                    }
                }

                $donemSay = count($aylar);
                echo json_encode([
                    'success' => true,
                    'message' => "$donemSay döneme kaydedildi. Eklenen: $eklendi, Güncellenen: $guncellendi, Silinen: $silindi",
                ]);
                break;

            // Tek kayıt tutar güncelleme
            case 'tutar_guncelle':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                $id    = (int)($_POST['id'] ?? 0);
                if (!hakedisKayitErisim($db, $id, $altBayiErisim)) {
                    echo json_encode(['success' => false, 'message' => 'Bu kayıt için yetkiniz yok']);
                    break;
                }
                $tutar = (float)str_replace(',', '.', (string)($_POST['tutar'] ?? '0'));
                $result = $db->update('DigiturkHakedisTanimlari', [
                    'DigiturkHakedisTanimlari_Tutar' => $tutar,
                    'GuncelleyenKullanici'           => $user['kullanici_id'],
                    'GuncellemeTarihi'               => date('Y-m-d H:i:s'),
                ], ['DigiturkHakedisTanimlari_id' => $id]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Tutar güncellendi' : 'Güncelleme hatası']);
                break;

            // ── Alt Bayi Skala Eşleştirme (grup) ─────────────────────────
            // Aynı gruptaki alt bayilerin adetleri toplanır, tek skala kademesi
            // bulunur; her üye kendi matrisindeki o kademe fiyatını kullanır.
            case 'gruplar':
                $gruplar = $db->fetchAll("
                    SELECT g.DigiturkAltBayiSkalaGruplari_id       AS id,
                           g.DigiturkAltBayiSkalaGruplari_Ad       AS ad,
                           g.DigiturkAltBayiSkalaGruplari_Aciklama AS aciklama,
                           (SELECT COUNT(*)
                              FROM DigiturkAltBayiSkalaGrupUyeleri u
                             WHERE u.DigiturkAltBayiSkalaGrupUyeleri_Grup_id = g.DigiturkAltBayiSkalaGruplari_id
                               AND u.Durum = 1) AS uyeSayisi,
                           STUFF((
                               SELECT N', ' + CASE WHEN an.DigiturkAnaBayiler_Ad IS NULL
                                                   THEN a.DigiturkAltBayiler_Ad
                                                   ELSE an.DigiturkAnaBayiler_Ad + N' — ' + a.DigiturkAltBayiler_Ad
                                              END
                               FROM DigiturkAltBayiSkalaGrupUyeleri u2
                               JOIN DigiturkAltBayiler a
                                 ON a.DigiturkAltBayiler_Id = u2.DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id
                               LEFT JOIN DigiturkAnaBayiler an
                                 ON an.DigiturkAnaBayiler_Id = a.DigiturkAltBayiler_AnaBayiId
                               WHERE u2.DigiturkAltBayiSkalaGrupUyeleri_Grup_id = g.DigiturkAltBayiSkalaGruplari_id
                                 AND u2.Durum = 1
                               ORDER BY an.DigiturkAnaBayiler_Ad, a.DigiturkAltBayiler_Ad
                               FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 2, N'') AS uyeler
                    FROM DigiturkAltBayiSkalaGruplari g
                    WHERE g.Durum = 1
                    ORDER BY g.DigiturkAltBayiSkalaGruplari_Ad
                ");
                echo json_encode(['success' => true, 'data' => $gruplar], JSON_UNESCAPED_UNICODE);
                break;

            // Grup ekle / güncelle
            case 'grup_kaydet':
                $gid      = (int)($_POST['grup_id'] ?? 0);
                $grupAd   = trim((string)($_POST['ad'] ?? ''));
                $grupAcik = trim((string)($_POST['aciklama'] ?? ''));

                if ($gid > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                if ($gid === 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']);
                    break;
                }
                if ($grupAd === '') {
                    echo json_encode(['success' => false, 'message' => 'Grup adı zorunludur']);
                    break;
                }
                $grupAd   = mb_substr($grupAd, 0, 150);
                $grupAcik = mb_substr($grupAcik, 0, 500);

                $ayni = $db->fetchOne("
                    SELECT DigiturkAltBayiSkalaGruplari_id AS id
                    FROM DigiturkAltBayiSkalaGruplari
                    WHERE DigiturkAltBayiSkalaGruplari_Ad = ? AND Durum = 1
                      AND DigiturkAltBayiSkalaGruplari_id <> ?
                ", [$grupAd, $gid]);
                if ($ayni) {
                    echo json_encode(['success' => false, 'message' => 'Bu adla aktif bir grup zaten var']);
                    break;
                }

                $gnow = date('Y-m-d H:i:s');
                if ($gid > 0) {
                    $db->update('DigiturkAltBayiSkalaGruplari', [
                        'DigiturkAltBayiSkalaGruplari_Ad'       => $grupAd,
                        'DigiturkAltBayiSkalaGruplari_Aciklama' => ($grupAcik !== '' ? $grupAcik : null),
                        'GuncelleyenKullanici'                  => $user['kullanici_id'],
                        'GuncellemeTarihi'                      => $gnow,
                    ], ['DigiturkAltBayiSkalaGruplari_id' => $gid]);
                    echo json_encode(['success' => true, 'id' => $gid, 'message' => 'Grup güncellendi']);
                } else {
                    $yeniId = $db->insert('DigiturkAltBayiSkalaGruplari', [
                        'DigiturkAltBayiSkalaGruplari_Ad'       => $grupAd,
                        'DigiturkAltBayiSkalaGruplari_Aciklama' => ($grupAcik !== '' ? $grupAcik : null),
                        'OlusturanKullanici'                    => $user['kullanici_id'],
                        'OlusturmaTarihi'                       => $gnow,
                        'GuncelleyenKullanici'                  => $user['kullanici_id'],
                        'GuncellemeTarihi'                      => $gnow,
                        'Durum'                                 => 1,
                    ]);
                    echo json_encode(['success' => true, 'id' => (int)$yeniId, 'message' => 'Grup eklendi']);
                }
                break;

            // Grup sil (soft delete — üyelikler de pasife çekilir)
            case 'grup_sil':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $gid = (int)($_POST['grup_id'] ?? 0);
                if ($gid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz grup']);
                    break;
                }
                $gnow = date('Y-m-d H:i:s');
                $db->query("
                    UPDATE DigiturkAltBayiSkalaGrupUyeleri
                    SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = ?
                    WHERE DigiturkAltBayiSkalaGrupUyeleri_Grup_id = ? AND Durum = 1
                ", [$user['kullanici_id'], $gnow, $gid]);
                $db->update('DigiturkAltBayiSkalaGruplari', [
                    'Durum'                => 0,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => $gnow,
                ], ['DigiturkAltBayiSkalaGruplari_id' => $gid]);
                echo json_encode(['success' => true, 'message' => 'Grup silindi']);
                break;

            // Grubun aktif üyeleri (Select2 seçili değerleri)
            case 'grup_uyeler':
                $gid = (int)($_POST['grup_id'] ?? 0);
                if ($gid <= 0) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $uyeler = $db->fetchAll("
                    SELECT u.DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id AS id,
                           CASE WHEN an.DigiturkAnaBayiler_Ad IS NULL THEN a.DigiturkAltBayiler_Ad
                                ELSE an.DigiturkAnaBayiler_Ad + N' — ' + a.DigiturkAltBayiler_Ad
                           END AS etiket
                    FROM DigiturkAltBayiSkalaGrupUyeleri u
                    LEFT JOIN DigiturkAltBayiler a ON a.DigiturkAltBayiler_Id = u.DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id
                    LEFT JOIN DigiturkAnaBayiler an ON an.DigiturkAnaBayiler_Id = a.DigiturkAltBayiler_AnaBayiId
                    WHERE u.DigiturkAltBayiSkalaGrupUyeleri_Grup_id = ? AND u.Durum = 1
                    ORDER BY an.DigiturkAnaBayiler_Ad, a.DigiturkAltBayiler_Ad
                ", [$gid]);
                // Kısıtlı kullanıcı yetkisi dışındaki üyeyi görür ama listeden çıkaramaz
                foreach ($uyeler as &$uy) {
                    $uy['yetkili'] = $altBayiErisim((int)$uy['id']);
                }
                unset($uy);
                echo json_encode(['success' => true, 'data' => $uyeler], JSON_UNESCAPED_UNICODE);
                break;

            // Grup üyelerini topluca ayarla (gelen liste = grubun yeni aktif üyeleri)
            case 'grup_uye_kaydet':
                if (!$permissions['can_add'] && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt yetkiniz yok!']);
                    break;
                }
                $gid   = (int)($_POST['grup_id'] ?? 0);
                $gelen = $_POST['uyeler'] ?? [];
                if (!is_array($gelen)) $gelen = [$gelen];
                $gelen = array_values(array_unique(array_filter(array_map('intval', $gelen), fn($v) => $v > 0)));

                $grupVar = $db->fetchOne("
                    SELECT DigiturkAltBayiSkalaGruplari_id AS id
                    FROM DigiturkAltBayiSkalaGruplari
                    WHERE DigiturkAltBayiSkalaGruplari_id = ? AND Durum = 1
                ", [$gid]);
                if (!$grupVar) {
                    echo json_encode(['success' => false, 'message' => 'Grup bulunamadı']);
                    break;
                }

                // Eklenecek her alt bayi için birim yetkisi şart
                $yetkiHatasi = false;
                foreach ($gelen as $abId) {
                    if (!$altBayiErisim($abId)) { $yetkiHatasi = true; break; }
                }
                if ($yetkiHatasi) {
                    echo json_encode(['success' => false, 'message' => 'Yetkiniz olmayan bir alt bayi seçtiniz']);
                    break;
                }

                // Grup tek kademe üretir → üyeler güncel dönemde aynı skala setinde olmalı
                if (count($gelen) > 1) {
                    $buYilG = (int)date('Y'); $buAyG = (int)date('n');
                    $uyeSetleri = [];
                    foreach ($gelen as $abId) {
                        $uyeSetleri[hakedisBayiSkalaSetId($db, $abId, $buYilG, $buAyG) ?? 0][] = $abId;
                    }
                    if (count($uyeSetleri) > 1) {
                        $parca = [];
                        foreach ($uyeSetleri as $sid => $abler) {
                            $parca[] = ($SKALA_SETLERI[$sid]['ad'] ?? 'set yok') . ' (' . count($abler) . ' bayi)';
                        }
                        echo json_encode([
                            'success' => false,
                            'message' => 'Grup üyeleri farklı skala setlerinde: ' . implode(', ', $parca)
                                       . '. Önce Skala Setleri > Bayi Atamaları\'ndan aynı sete alın.',
                        ], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                }

                // Başka bir aktif grupta olan alt bayi eklenemez
                if ($gelen) {
                    $ph = implode(',', array_fill(0, count($gelen), '?'));
                    $cakisan = $db->fetchAll("
                        SELECT g.DigiturkAltBayiSkalaGruplari_Ad AS grupAd,
                               CASE WHEN an.DigiturkAnaBayiler_Ad IS NULL THEN a.DigiturkAltBayiler_Ad
                                    ELSE an.DigiturkAnaBayiler_Ad + N' — ' + a.DigiturkAltBayiler_Ad
                               END AS etiket
                        FROM DigiturkAltBayiSkalaGrupUyeleri u
                        JOIN DigiturkAltBayiSkalaGruplari g
                          ON g.DigiturkAltBayiSkalaGruplari_id = u.DigiturkAltBayiSkalaGrupUyeleri_Grup_id
                        LEFT JOIN DigiturkAltBayiler a ON a.DigiturkAltBayiler_Id = u.DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id
                        LEFT JOIN DigiturkAnaBayiler an ON an.DigiturkAnaBayiler_Id = a.DigiturkAltBayiler_AnaBayiId
                        WHERE u.Durum = 1 AND g.Durum = 1
                          AND u.DigiturkAltBayiSkalaGrupUyeleri_Grup_id <> ?
                          AND u.DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id IN ($ph)
                    ", array_merge([$gid], $gelen));
                    if ($cakisan) {
                        $mesaj = [];
                        foreach ($cakisan as $c) $mesaj[] = $c['etiket'] . ' → ' . $c['grupAd'];
                        echo json_encode([
                            'success' => false,
                            'message' => 'Şu alt bayiler başka bir grupta: ' . implode(', ', $mesaj)
                                       . '. Önce o gruptan çıkarmalısınız.',
                        ], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                }

                $gnow = date('Y-m-d H:i:s');
                $uid  = $user['kullanici_id'];

                $mevcutRows = $db->fetchAll("
                    SELECT DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id AS id
                    FROM DigiturkAltBayiSkalaGrupUyeleri
                    WHERE DigiturkAltBayiSkalaGrupUyeleri_Grup_id = ? AND Durum = 1
                ", [$gid]);
                $mevcut = array_map(fn($r) => (int)$r['id'], $mevcutRows);

                // Çıkarılanlar → pasife (yetki dışı üyelere dokunulmaz)
                $cikan = 0;
                foreach (array_diff($mevcut, $gelen) as $abId) {
                    if (!$altBayiErisim((int)$abId)) continue;
                    $db->query("
                        UPDATE DigiturkAltBayiSkalaGrupUyeleri
                        SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = ?
                        WHERE DigiturkAltBayiSkalaGrupUyeleri_Grup_id = ?
                          AND DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id = ? AND Durum = 1
                    ", [$uid, $gnow, $gid, (int)$abId]);
                    $cikan++;
                }

                // Eklenenler → pasif kayıt varsa aktife, yoksa yeni kayıt
                $eklenen = 0;
                foreach (array_diff($gelen, $mevcut) as $abId) {
                    $pasif = $db->fetchOne("
                        SELECT TOP 1 DigiturkAltBayiSkalaGrupUyeleri_id AS id
                        FROM DigiturkAltBayiSkalaGrupUyeleri
                        WHERE DigiturkAltBayiSkalaGrupUyeleri_Grup_id = ?
                          AND DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id = ? AND Durum = 0
                        ORDER BY DigiturkAltBayiSkalaGrupUyeleri_id DESC
                    ", [$gid, (int)$abId]);
                    if ($pasif) {
                        $db->update('DigiturkAltBayiSkalaGrupUyeleri', [
                            'Durum'                => 1,
                            'GuncelleyenKullanici' => $uid,
                            'GuncellemeTarihi'     => $gnow,
                        ], ['DigiturkAltBayiSkalaGrupUyeleri_id' => $pasif['id']]);
                    } else {
                        $db->insert('DigiturkAltBayiSkalaGrupUyeleri', [
                            'DigiturkAltBayiSkalaGrupUyeleri_Grup_id'    => $gid,
                            'DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id' => (int)$abId,
                            'OlusturanKullanici'                         => $uid,
                            'OlusturmaTarihi'                            => $gnow,
                            'GuncelleyenKullanici'                       => $uid,
                            'GuncellemeTarihi'                           => $gnow,
                            'Durum'                                      => 1,
                        ]);
                    }
                    $eklenen++;
                }

                echo json_encode([
                    'success' => true,
                    'message' => "Üyeler kaydedildi. Eklenen: $eklenen, Çıkarılan: $cikan",
                ]);
                break;

            // Matris için: seçili alt bayi bir gruba üye mi?
            case 'altbayi_grup':
                $abId = (int)($_POST['altbayi'] ?? 0);
                if ($abId <= 0) {
                    echo json_encode(['success' => true, 'data' => null]);
                    break;
                }
                $bilgi = $db->fetchOne("
                    SELECT g.DigiturkAltBayiSkalaGruplari_id AS id,
                           g.DigiturkAltBayiSkalaGruplari_Ad AS ad,
                           (SELECT COUNT(*)
                              FROM DigiturkAltBayiSkalaGrupUyeleri u2
                             WHERE u2.DigiturkAltBayiSkalaGrupUyeleri_Grup_id = g.DigiturkAltBayiSkalaGruplari_id
                               AND u2.Durum = 1) AS uyeSayisi
                    FROM DigiturkAltBayiSkalaGrupUyeleri u
                    JOIN DigiturkAltBayiSkalaGruplari g
                      ON g.DigiturkAltBayiSkalaGruplari_id = u.DigiturkAltBayiSkalaGrupUyeleri_Grup_id
                    WHERE u.DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id = ? AND u.Durum = 1 AND g.Durum = 1
                ", [$abId]);
                echo json_encode(['success' => true, 'data' => $bilgi ?: null], JSON_UNESCAPED_UNICODE);
                break;

            // ── Skala Setleri ─────────────────────────────────────────────
            // Set = kademe listesi (AltSinir dahil, UstSinir hariç, NULL = sınırsız).
            // Bayi → set ataması dönemden itibaren geçerli; ataması olmayan varsayılan seti kullanır.
            case 'skala_setleri':
                $setler = $db->fetchAll("
                    SELECT s.HakedisSkalaSetleri_id AS id, s.HakedisSkalaSetleri_Ad AS ad,
                           CAST(s.HakedisSkalaSetleri_Varsayilan AS INT) AS varsayilan,
                           (SELECT COUNT(DISTINCT x.HakedisAltBayiSkalaSetleri_AltBayi_id)
                              FROM dbo.HakedisAltBayiSkalaSetleri x
                             WHERE x.HakedisAltBayiSkalaSetleri_Set_id = s.HakedisSkalaSetleri_id AND x.Durum = 1) AS atamaSayisi
                    FROM dbo.HakedisSkalaSetleri s
                    WHERE s.Durum = 1
                    ORDER BY s.HakedisSkalaSetleri_Varsayilan DESC, s.HakedisSkalaSetleri_Ad
                ");
                $kademeRows = $db->fetchAll("
                    SELECT k.HakedisSkalaKademeleri_Set_id AS setId, k.HakedisSkalaKademeleri_id AS id,
                           k.HakedisSkalaKademeleri_Ad AS ad, k.HakedisSkalaKademeleri_AltSinir AS alt,
                           k.HakedisSkalaKademeleri_UstSinir AS ust,
                           (SELECT COUNT(*) FROM dbo.DigiturkHakedisTanimlari h
                             WHERE h.DigiturkHakedisTanimlari_AdetSkalasiKodu = k.HakedisSkalaKademeleri_id) AS tanimSayisi
                    FROM dbo.HakedisSkalaKademeleri k
                    WHERE k.Durum = 1
                    ORDER BY k.HakedisSkalaKademeleri_Sira
                ");
                foreach ($setler as &$st) {
                    $st['kademeler'] = array_values(array_filter($kademeRows, fn($k) => (int)$k['setId'] === (int)$st['id']));
                    $st['tanimSayisi'] = array_sum(array_map(fn($k) => (int)$k['tanimSayisi'], $st['kademeler']));
                }
                unset($st);
                echo json_encode(['success' => true, 'data' => $setler], JSON_UNESCAPED_UNICODE);
                break;

            case 'skala_set_kaydet':
                if (empty($permissions['is_admin'])) {
                    echo json_encode(['success' => false, 'message' => 'Skala setlerini yalnız yönetici düzenleyebilir']);
                    break;
                }
                $sid   = (int)($_POST['set_id'] ?? 0);
                $setAd = mb_substr(trim((string)($_POST['ad'] ?? '')), 0, 100);
                $gelenK = json_decode((string)($_POST['kademeler'] ?? '[]'), true);

                if ($setAd === '') { echo json_encode(['success' => false, 'message' => 'Set adı zorunludur']); break; }
                if (!is_array($gelenK) || !$gelenK) { echo json_encode(['success' => false, 'message' => 'En az bir kademe girilmelidir']); break; }

                // Normalize + doğrula: 0'dan başlar, boşluksuz/çakışmasız ardışık, son kademe sınırsız
                $kd = [];
                foreach ($gelenK as $g) {
                    $kAd  = mb_substr(trim((string)($g['ad'] ?? '')), 0, 100);
                    $kAlt = (string)($g['alt'] ?? '') === '' ? null : (int)$g['alt'];
                    $kUst = (string)($g['ust'] ?? '') === '' ? null : (int)$g['ust'];
                    if ($kAd === '' || $kAlt === null || $kAlt < 0) {
                        echo json_encode(['success' => false, 'message' => 'Her kademenin adı ve alt sınırı (0 veya üstü) dolu olmalı']);
                        break 2;
                    }
                    if ($kUst !== null && $kUst <= $kAlt) {
                        echo json_encode(['success' => false, 'message' => "\"$kAd\": üst sınır alt sınırdan büyük olmalı"], JSON_UNESCAPED_UNICODE);
                        break 2;
                    }
                    $kd[] = ['id' => (int)($g['id'] ?? 0), 'ad' => $kAd, 'alt' => $kAlt, 'ust' => $kUst];
                }
                usort($kd, fn($a, $b) => $a['alt'] <=> $b['alt']);
                if ($kd[0]['alt'] !== 0) { echo json_encode(['success' => false, 'message' => 'İlk kademe 0 adetten başlamalı']); break; }
                for ($i = 0; $i < count($kd) - 1; $i++) {
                    if ($kd[$i]['ust'] === null || $kd[$i]['ust'] !== $kd[$i + 1]['alt']) {
                        echo json_encode([
                            'success' => false,
                            'message' => "\"{$kd[$i]['ad']}\" üst sınırı, sonraki kademenin (\"{$kd[$i + 1]['ad']}\") alt sınırına eşit olmalı (boşluk/çakışma var)",
                        ], JSON_UNESCAPED_UNICODE);
                        break 2;
                    }
                }
                if ($kd[count($kd) - 1]['ust'] !== null) {
                    echo json_encode(['success' => false, 'message' => 'En yüksek kademenin üst sınırı boş (sınırsız) olmalı']);
                    break;
                }
                // Sira: en yüksek kademe 1 (eski düzenle aynı: 1 = 2000 ve üzeri)
                $n = count($kd);
                foreach ($kd as $i => &$k) $k['sira'] = $n - $i;
                unset($k);

                $ayniAd = $db->fetchOne("SELECT 1 AS v FROM dbo.HakedisSkalaSetleri WHERE HakedisSkalaSetleri_Ad = ? AND Durum = 1 AND HakedisSkalaSetleri_id <> ?", [$setAd, $sid]);
                if ($ayniAd) { echo json_encode(['success' => false, 'message' => 'Bu adla aktif bir set zaten var']); break; }

                $snow = date('Y-m-d H:i:s');
                $uid  = $user['kullanici_id'];

                if ($sid > 0) {
                    $mevcutK = $db->fetchAll("
                        SELECT k.HakedisSkalaKademeleri_id AS id, k.HakedisSkalaKademeleri_Ad AS ad,
                               (SELECT COUNT(*) FROM dbo.DigiturkHakedisTanimlari h
                                 WHERE h.DigiturkHakedisTanimlari_AdetSkalasiKodu = k.HakedisSkalaKademeleri_id) AS tanimSayisi
                        FROM dbo.HakedisSkalaKademeleri k
                        WHERE k.HakedisSkalaKademeleri_Set_id = ? AND k.Durum = 1
                    ", [$sid]);
                    if (!$db->fetchOne("SELECT 1 AS v FROM dbo.HakedisSkalaSetleri WHERE HakedisSkalaSetleri_id = ? AND Durum = 1", [$sid])) {
                        echo json_encode(['success' => false, 'message' => 'Set bulunamadı']);
                        break;
                    }
                    $mevcutIdler = array_map(fn($r) => (int)$r['id'], $mevcutK);
                    foreach ($kd as $k) {
                        if ($k['id'] && !in_array($k['id'], $mevcutIdler, true)) {
                            echo json_encode(['success' => false, 'message' => 'Kademe bu sete ait değil']);
                            break 2;
                        }
                    }
                    // Çıkarılan kademe tanımda kullanılıyorsa silinemez
                    $kalanIdler = array_filter(array_column($kd, 'id'));
                    $kullanilan = array_filter($mevcutK, fn($r) => !in_array((int)$r['id'], $kalanIdler, true) && (int)$r['tanimSayisi'] > 0);
                    if ($kullanilan) {
                        $adlar = implode(', ', array_map(fn($r) => $r['ad'] . ' (' . $r['tanimSayisi'] . ' tanım)', $kullanilan));
                        echo json_encode(['success' => false, 'message' => "Hakediş tanımı olan kademe çıkarılamaz: $adlar"], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                    foreach ($mevcutIdler as $mid) {
                        if (in_array($mid, $kalanIdler, true)) continue;
                        $db->update('HakedisSkalaKademeleri', ['Durum' => 0, 'GuncelleyenKullanici' => $uid, 'GuncellemeTarihi' => $snow],
                                    ['HakedisSkalaKademeleri_id' => $mid]);
                    }
                    $db->update('HakedisSkalaSetleri', ['HakedisSkalaSetleri_Ad' => $setAd, 'GuncelleyenKullanici' => $uid, 'GuncellemeTarihi' => $snow],
                                ['HakedisSkalaSetleri_id' => $sid]);
                } else {
                    $sid = (int)$db->insert('HakedisSkalaSetleri', [
                        'HakedisSkalaSetleri_Ad'         => $setAd,
                        'HakedisSkalaSetleri_Varsayilan' => 0,
                        'OlusturanKullanici' => $uid, 'OlusturmaTarihi' => $snow,
                        'GuncelleyenKullanici' => $uid, 'GuncellemeTarihi' => $snow, 'Durum' => 1,
                    ]);
                }

                foreach ($kd as $k) {
                    $alanlar = [
                        'HakedisSkalaKademeleri_Ad'       => $k['ad'],
                        'HakedisSkalaKademeleri_AltSinir' => $k['alt'],
                        'HakedisSkalaKademeleri_UstSinir' => $k['ust'],
                        'HakedisSkalaKademeleri_Sira'     => $k['sira'],
                        'GuncelleyenKullanici' => $uid, 'GuncellemeTarihi' => $snow,
                    ];
                    if ($k['id']) {
                        $db->update('HakedisSkalaKademeleri', $alanlar, ['HakedisSkalaKademeleri_id' => $k['id']]);
                    } else {
                        $db->insert('HakedisSkalaKademeleri', $alanlar + [
                            'HakedisSkalaKademeleri_Set_id' => $sid,
                            'OlusturanKullanici' => $uid, 'OlusturmaTarihi' => $snow, 'Durum' => 1,
                        ]);
                    }
                }
                echo json_encode(['success' => true, 'id' => $sid, 'message' => 'Skala seti kaydedildi']);
                break;

            // Varsayılan set: tek UPDATE ile (filtreli unique index ifade sonunda kontrol edilir)
            case 'skala_set_varsayilan':
                if (empty($permissions['is_admin'])) {
                    echo json_encode(['success' => false, 'message' => 'Varsayılan seti yalnız yönetici değiştirebilir']);
                    break;
                }
                $sid = (int)($_POST['set_id'] ?? 0);
                if (!$db->fetchOne("SELECT 1 AS v FROM dbo.HakedisSkalaSetleri WHERE HakedisSkalaSetleri_id = ? AND Durum = 1", [$sid])) {
                    echo json_encode(['success' => false, 'message' => 'Set bulunamadı']);
                    break;
                }
                $ok = $db->execute("
                    UPDATE dbo.HakedisSkalaSetleri
                       SET HakedisSkalaSetleri_Varsayilan = CASE WHEN HakedisSkalaSetleri_id = ? THEN 1 ELSE 0 END,
                           GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                     WHERE HakedisSkalaSetleri_Varsayilan = 1 OR HakedisSkalaSetleri_id = ?
                ", [$sid, $user['kullanici_id'], $sid]);
                echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Varsayılan set değiştirildi' : 'Güncelleme hatası']);
                break;

            case 'skala_set_sil':
                if (empty($permissions['is_admin'])) {
                    echo json_encode(['success' => false, 'message' => 'Skala setlerini yalnız yönetici silebilir']);
                    break;
                }
                $sid = (int)($_POST['set_id'] ?? 0);
                $set = $db->fetchOne("SELECT HakedisSkalaSetleri_Varsayilan AS v FROM dbo.HakedisSkalaSetleri WHERE HakedisSkalaSetleri_id = ? AND Durum = 1", [$sid]);
                if (!$set) { echo json_encode(['success' => false, 'message' => 'Set bulunamadı']); break; }
                if ((int)$set['v'] === 1) { echo json_encode(['success' => false, 'message' => 'Varsayılan set silinemez']); break; }
                $kullanim = $db->fetchOne("
                    SELECT (SELECT COUNT(*) FROM dbo.HakedisAltBayiSkalaSetleri
                             WHERE HakedisAltBayiSkalaSetleri_Set_id = ? AND Durum = 1) AS atama,
                           (SELECT COUNT(*) FROM dbo.DigiturkHakedisTanimlari h
                              JOIN dbo.HakedisSkalaKademeleri k ON k.HakedisSkalaKademeleri_id = h.DigiturkHakedisTanimlari_AdetSkalasiKodu
                             WHERE k.HakedisSkalaKademeleri_Set_id = ?) AS tanim
                ", [$sid, $sid]);
                if ((int)$kullanim['atama'] > 0 || (int)$kullanim['tanim'] > 0) {
                    echo json_encode(['success' => false, 'message' => "Set kullanımda ({$kullanim['atama']} bayi ataması, {$kullanim['tanim']} hakediş tanımı). Önce bunları kaldırın."]);
                    break;
                }
                $snow = date('Y-m-d H:i:s');
                $db->execute("UPDATE dbo.HakedisSkalaKademeleri SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = ? WHERE HakedisSkalaKademeleri_Set_id = ? AND Durum = 1",
                             [$user['kullanici_id'], $snow, $sid]);
                $db->update('HakedisSkalaSetleri', ['Durum' => 0, 'GuncelleyenKullanici' => $user['kullanici_id'], 'GuncellemeTarihi' => $snow],
                            ['HakedisSkalaSetleri_id' => $sid]);
                echo json_encode(['success' => true, 'message' => 'Set silindi']);
                break;

            // ── Bayi → Set atamaları ──────────────────────────────────────
            case 'skala_atamalari':
                $aWhere = 'x.Durum = 1';
                $aParams = [];
                if ($birimKisitli) {
                    if (empty($izinliAltBayiIdleri)) { echo json_encode(['success' => true, 'data' => []]); break; }
                    $aWhere .= ' AND x.HakedisAltBayiSkalaSetleri_AltBayi_id IN (' . implode(',', array_fill(0, count($izinliAltBayiIdleri), '?')) . ')';
                    $aParams = $izinliAltBayiIdleri;
                }
                $atamalar = $db->fetchAll("
                    SELECT x.HakedisAltBayiSkalaSetleri_id AS id,
                           x.HakedisAltBayiSkalaSetleri_AltBayi_id AS altbayiId,
                           CASE WHEN an.DigiturkAnaBayiler_Ad IS NULL THEN a.DigiturkAltBayiler_Ad
                                ELSE an.DigiturkAnaBayiler_Ad + N' — ' + a.DigiturkAltBayiler_Ad END AS altbayi,
                           x.HakedisAltBayiSkalaSetleri_Set_id AS setId, s.HakedisSkalaSetleri_Ad AS setAd,
                           x.HakedisAltBayiSkalaSetleri_BaslangicYil AS yil, x.HakedisAltBayiSkalaSetleri_BaslangicAy AS ay,
                           g.DigiturkAltBayiSkalaGruplari_Ad AS grupAd
                    FROM dbo.HakedisAltBayiSkalaSetleri x
                    JOIN dbo.HakedisSkalaSetleri s ON s.HakedisSkalaSetleri_id = x.HakedisAltBayiSkalaSetleri_Set_id
                    LEFT JOIN DigiturkAltBayiler a ON a.DigiturkAltBayiler_Id = x.HakedisAltBayiSkalaSetleri_AltBayi_id
                    LEFT JOIN DigiturkAnaBayiler an ON an.DigiturkAnaBayiler_Id = a.DigiturkAltBayiler_AnaBayiId
                    OUTER APPLY (
                        SELECT TOP 1 gg.DigiturkAltBayiSkalaGruplari_Ad
                        FROM DigiturkAltBayiSkalaGrupUyeleri gu
                        JOIN DigiturkAltBayiSkalaGruplari gg ON gg.DigiturkAltBayiSkalaGruplari_id = gu.DigiturkAltBayiSkalaGrupUyeleri_Grup_id AND gg.Durum = 1
                        WHERE gu.DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id = x.HakedisAltBayiSkalaSetleri_AltBayi_id AND gu.Durum = 1
                    ) g
                    WHERE $aWhere
                    ORDER BY altbayi, x.HakedisAltBayiSkalaSetleri_BaslangicYil DESC, x.HakedisAltBayiSkalaSetleri_BaslangicAy DESC
                ", $aParams);
                echo json_encode(['success' => true, 'data' => $atamalar], JSON_UNESCAPED_UNICODE);
                break;

            // Atama ekle/güncelle. Bayi bir skala grubundaysa grubun tüm üyelerine uygulanır.
            case 'skala_atama_kaydet':
                if (!$permissions['can_add'] && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt yetkiniz yok!']);
                    break;
                }
                $abId = (int)($_POST['altbayi'] ?? 0);
                $sid  = (int)($_POST['set_id'] ?? 0);
                $aYil = (int)($_POST['yil'] ?? 0);
                $aAy  = (int)($_POST['ay'] ?? 0);
                if (!$abId || !$sid || $aYil < 2000 || $aYil > 2100 || $aAy < 1 || $aAy > 12) {
                    echo json_encode(['success' => false, 'message' => 'Alt bayi, set, yıl ve ay seçilmelidir']);
                    break;
                }
                if (!$db->fetchOne("SELECT 1 AS v FROM dbo.HakedisSkalaSetleri WHERE HakedisSkalaSetleri_id = ? AND Durum = 1", [$sid])) {
                    echo json_encode(['success' => false, 'message' => 'Set bulunamadı']);
                    break;
                }
                $grup = $db->fetchOne("
                    SELECT gu.DigiturkAltBayiSkalaGrupUyeleri_Grup_id AS id, gg.DigiturkAltBayiSkalaGruplari_Ad AS ad
                    FROM DigiturkAltBayiSkalaGrupUyeleri gu
                    JOIN DigiturkAltBayiSkalaGruplari gg ON gg.DigiturkAltBayiSkalaGruplari_id = gu.DigiturkAltBayiSkalaGrupUyeleri_Grup_id AND gg.Durum = 1
                    WHERE gu.DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id = ? AND gu.Durum = 1
                ", [$abId]);
                $hedefler = [$abId];
                if ($grup) {
                    $hedefler = array_map(fn($r) => (int)$r['id'], $db->fetchAll("
                        SELECT DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id AS id FROM DigiturkAltBayiSkalaGrupUyeleri
                        WHERE DigiturkAltBayiSkalaGrupUyeleri_Grup_id = ? AND Durum = 1
                    ", [(int)$grup['id']]));
                }
                foreach ($hedefler as $h) {
                    if (!$altBayiErisim($h)) {
                        echo json_encode(['success' => false, 'message' => 'Bu alt bayi (veya skala grubundaki bir üye) için yetkiniz yok']);
                        break 2;
                    }
                }

                $anow = date('Y-m-d H:i:s');
                $uid  = $user['kullanici_id'];
                foreach ($hedefler as $h) {
                    $var = $db->fetchOne("
                        SELECT HakedisAltBayiSkalaSetleri_id AS id FROM dbo.HakedisAltBayiSkalaSetleri
                        WHERE HakedisAltBayiSkalaSetleri_AltBayi_id = ? AND HakedisAltBayiSkalaSetleri_BaslangicYil = ?
                          AND HakedisAltBayiSkalaSetleri_BaslangicAy = ? AND Durum = 1
                    ", [$h, $aYil, $aAy]);
                    if ($var) {
                        $db->update('HakedisAltBayiSkalaSetleri', ['HakedisAltBayiSkalaSetleri_Set_id' => $sid, 'GuncelleyenKullanici' => $uid, 'GuncellemeTarihi' => $anow],
                                    ['HakedisAltBayiSkalaSetleri_id' => (int)$var['id']]);
                    } else {
                        $db->insert('HakedisAltBayiSkalaSetleri', [
                            'HakedisAltBayiSkalaSetleri_AltBayi_id'   => $h,
                            'HakedisAltBayiSkalaSetleri_Set_id'       => $sid,
                            'HakedisAltBayiSkalaSetleri_BaslangicYil' => $aYil,
                            'HakedisAltBayiSkalaSetleri_BaslangicAy'  => $aAy,
                            'OlusturanKullanici' => $uid, 'OlusturmaTarihi' => $anow,
                            'GuncelleyenKullanici' => $uid, 'GuncellemeTarihi' => $anow, 'Durum' => 1,
                        ]);
                    }
                }

                // Uyarı: başlangıçtan sonraki dönemlerde yeni setin dışındaki kademelere yazılmış tanımlar
                $phH = implode(',', array_fill(0, count($hedefler), '?'));
                $eskiTanim = $db->fetchOne("
                    SELECT COUNT(*) AS c FROM dbo.DigiturkHakedisTanimlari h
                    JOIN dbo.HakedisSkalaKademeleri k ON k.HakedisSkalaKademeleri_id = h.DigiturkHakedisTanimlari_AdetSkalasiKodu
                    WHERE h.DigiturkHakedisTanimlari_AltBayi_id IN ($phH)
                      AND h.DigiturkHakedisTanimlari_DonemYil * 100 + h.DigiturkHakedisTanimlari_DonemAy >= ?
                      AND k.HakedisSkalaKademeleri_Set_id <> ?
                ", array_merge($hedefler, [$aYil * 100 + $aAy, $sid]));
                $mesaj = 'Atama kaydedildi' . ($grup ? " ({$grup['ad']} grubunun " . count($hedefler) . ' üyesine uygulandı)' : '');
                echo json_encode([
                    'success'   => true,
                    'message'   => $mesaj,
                    'eskiTanim' => (int)($eskiTanim['c'] ?? 0),
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'skala_atama_sil':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $atId = (int)($_POST['atama_id'] ?? 0);
                $at = $db->fetchOne("
                    SELECT HakedisAltBayiSkalaSetleri_AltBayi_id AS ab, HakedisAltBayiSkalaSetleri_BaslangicYil AS yil,
                           HakedisAltBayiSkalaSetleri_BaslangicAy AS ay
                    FROM dbo.HakedisAltBayiSkalaSetleri WHERE HakedisAltBayiSkalaSetleri_id = ? AND Durum = 1
                ", [$atId]);
                if (!$at || !$altBayiErisim((int)$at['ab'])) {
                    echo json_encode(['success' => false, 'message' => 'Atama bulunamadı veya yetkiniz yok']);
                    break;
                }
                // Gruptaysa aynı dönemli atama tüm üyelerden kalkar (grup tek sette kalsın)
                $hedefler = [(int)$at['ab']];
                $grupId = $db->fetchOne("
                    SELECT gu.DigiturkAltBayiSkalaGrupUyeleri_Grup_id AS id FROM DigiturkAltBayiSkalaGrupUyeleri gu
                    JOIN DigiturkAltBayiSkalaGruplari gg ON gg.DigiturkAltBayiSkalaGruplari_id = gu.DigiturkAltBayiSkalaGrupUyeleri_Grup_id AND gg.Durum = 1
                    WHERE gu.DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id = ? AND gu.Durum = 1
                ", [(int)$at['ab']]);
                if ($grupId) {
                    $hedefler = array_map(fn($r) => (int)$r['id'], $db->fetchAll("
                        SELECT DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id AS id FROM DigiturkAltBayiSkalaGrupUyeleri
                        WHERE DigiturkAltBayiSkalaGrupUyeleri_Grup_id = ? AND Durum = 1
                    ", [(int)$grupId['id']]));
                    foreach ($hedefler as $h) {
                        if (!$altBayiErisim($h)) {
                            echo json_encode(['success' => false, 'message' => 'Skala grubundaki bir üye için yetkiniz yok']);
                            break 2;
                        }
                    }
                }
                $phH = implode(',', array_fill(0, count($hedefler), '?'));
                $db->execute("
                    UPDATE dbo.HakedisAltBayiSkalaSetleri SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = GETDATE()
                    WHERE Durum = 1 AND HakedisAltBayiSkalaSetleri_BaslangicYil = ? AND HakedisAltBayiSkalaSetleri_BaslangicAy = ?
                      AND HakedisAltBayiSkalaSetleri_AltBayi_id IN ($phH)
                ", array_merge([$user['kullanici_id'], (int)$at['yil'], (int)$at['ay']], $hedefler));
                echo json_encode(['success' => true, 'message' => 'Atama kaldırıldı' . (count($hedefler) > 1 ? ' (' . count($hedefler) . ' grup üyesi)' : '')]);
                break;

            // Tek kayıt sil
            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id     = (int)($_POST['id'] ?? 0);
                if (!hakedisKayitErisim($db, $id, $altBayiErisim)) {
                    echo json_encode(['success' => false, 'message' => 'Bu kayıt için yetkiniz yok']);
                    break;
                }
                $result = $db->delete('DigiturkHakedisTanimlari', ['DigiturkHakedisTanimlari_id' => $id]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt silindi' : 'Silme hatası']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// Yıl listesi (dinamik)
$buYil  = (int)date('Y');
$yillar = range($buYil + 1, $buYil - 3);
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
        .status-badge { padding: .25rem .5rem; border-radius: .25rem; font-size: .875rem; }
        .kampanya-badge { background:#e7f1ff; color:#0d6efd; padding:.2rem .5rem; border-radius:.3rem; font-size:.8rem; font-weight:600; }
        .skala-badge { background:#f1e7ff; color:#6f42c1; padding:.2rem .5rem; border-radius:.3rem; font-size:.8rem; font-weight:600; }
        .donem-badge { background:#e9ecef; color:#495057; padding:.2rem .5rem; border-radius:.3rem; font-size:.8rem; font-family:monospace; }
        .tutar-text { font-weight:600; }
        .matris-table th, .matris-table td { vertical-align: middle; text-align: center; }
        .matris-table input { text-align: right; }
        .matris-corner { background:#f8f9fa; }
        /* Modal açıkken sayfadaki (matris/filtre) Select2 kutuları backdrop önüne çıkmasın */
        body.modal-open .app-main .select2-container { z-index: auto !important; }
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
                            <span class="info-box-icon"><i class="bi bi-cash-stack"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Tanım</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-shop"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Tanımlı Alt Bayi</span>
                                <span class="info-box-number" id="stat-altbayi">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-calendar-range"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Tanımlı Dönem</span>
                                <span class="info-box-number" id="stat-donem">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hakediş Girişi (Matris) -->
                <?php if ($permissions['can_add'] || $permissions['can_edit']): ?>
                <div class="card card-success card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-grid-3x3-gap"></i> Hakediş Girişi</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-sm btn-outline-secondary me-1" id="btnSkalaModal">
                                <i class="bi bi-sliders"></i> Skala Setleri
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary me-1" id="btnGrupModal">
                                <i class="bi bi-diagram-3"></i> Alt Bayi Eşleştirme
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#excelModal">
                                <i class="bi bi-file-earmark-excel"></i> Excel ile Yükle
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 align-items-end mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Alt Bayi <span class="text-danger">*</span></label>
                                <select class="form-select" id="m_altbayi"></select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Yıl <span class="text-danger">*</span></label>
                                <select class="form-select" id="m_yil"></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Ay <span class="text-danger">*</span> <small class="text-muted">(çoklu seçilebilir)</small></label>
                                <select class="form-select" id="m_ay" multiple></select>
                            </div>
                            <div class="col-md-3">
                                <div class="alert alert-secondary mb-0 py-2 px-3">
                                    <i class="bi bi-calendar-range"></i> Dönem:
                                    <strong id="m_donem_text">-</strong>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-primary py-2 px-3 small" id="m_grup_bilgi" style="display:none"></div>
                        <div class="alert py-2 px-3 small" id="m_set_bilgi" style="display:none"></div>

                        <!-- Sütunlar (kademeler) bayinin dönemdeki skala setinden JS ile çizilir (renderMatris) -->
                        <div class="table-responsive">
                            <table class="table table-bordered matris-table mb-0">
                                <thead class="table-light" id="matrisHead"></thead>
                                <tbody id="matrisBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <button type="button" class="btn btn-secondary" id="btnMatrisTemizle"><i class="bi bi-eraser"></i> Temizle</button>
                        <button type="button" class="btn btn-success" id="btnMatrisKaydet"><i class="bi bi-save"></i> Kaydet</button>
                    </div>
                </div>
                <?php endif; ?>

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
                                    <label class="form-label">Alt Bayi</label>
                                    <select class="form-select" name="altbayi" id="filter_altbayi"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Yıl</label>
                                    <select class="form-select select2" name="yil" id="filter_yil">
                                        <option value="">Tümü</option>
                                        <?php foreach ($yillar as $y): ?><option value="<?= $y ?>"><?= $y ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Ay</label>
                                    <select class="form-select select2" name="ay" id="filter_ay">
                                        <option value="">Tümü</option>
                                        <?php foreach ($AYLAR as $no => $ad): ?><option value="<?= $no ?>"><?= $ad ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Kampanya Tanımı</label>
                                    <select class="form-select select2" name="kampanya_tanim" id="filter_kampanya_tanim">
                                        <option value="">Tümü</option>
                                        <?php foreach ($KAMPANYA_TANIMLARI as $kt): ?><option value="<?= $kt['id'] ?>"><?= htmlspecialchars($kt['ad']) ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Adet Skalası</label>
                                    <select class="form-select select2" name="skala" id="filter_skala">
                                        <option value="">Tümü</option>
                                        <?php foreach ($SKALA_SETLERI as $st): ?>
                                        <optgroup label="<?= htmlspecialchars($st['ad']) ?>">
                                            <?php foreach ($st['kademeler'] as $sk => $sad): ?><option value="<?= $sk ?>"><?= htmlspecialchars($sad) ?></option><?php endforeach; ?>
                                        </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Min Tutar (₺)</label>
                                    <input type="number" step="0.01" class="form-control" name="tutar_min" id="filter_tutar_min">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Max Tutar (₺)</label>
                                    <input type="number" step="0.01" class="form-control" name="tutar_max" id="filter_tutar_max">
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
                        <h3 class="card-title">Hakediş Tanımları</h3>
                    </div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Alt Bayi</th>
                                    <th>Dönem</th>
                                    <th>Tarih Aralığı</th>
                                    <th>Kampanya Tanımı</th>
                                    <th>Adet Skalası</th>
                                    <th class="text-end">Tutar (₺)</th>
                                    <th>Son Güncelleme</th>
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

<!-- Tutar Düzenleme Modal -->
<div class="modal fade" id="tutarModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tutar Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="tutarForm">
                <div class="modal-body">
                    <input type="hidden" id="t_id" name="id">
                    <div class="mb-2 small text-muted" id="t_bilgi"></div>
                    <label class="form-label">Tutar (₺) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" min="0" class="form-control" id="t_tutar" name="tutar" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Excel ile Yükle Modal -->
<div class="modal fade" id="excelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-excel text-success"></i> Excel ile Hakediş Yükle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-secondary py-2 px-3 mb-3 d-flex align-items-center justify-content-between">
                    <span><i class="bi bi-bullseye"></i> Hedef: <strong id="excelHedefText">-</strong></span>
                    <small class="text-muted">Alt Bayi / Yıl / Ay seçimi arka paneldedir</small>
                </div>
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <i class="bi bi-info-circle"></i>
                    Excel'deki değerler <strong>yukarıdaki hedef</strong> için matrise doldurulur.
                    Yükledikten sonra kontrol edip <strong>Kaydet</strong> butonuna basın.
                    Satırlar <strong>Kampanya Tanımı</strong>, sütunlar <strong>Adet Skalası</strong> olmalıdır (ilk satır ve ilk sütun başlık).
                </div>

                <div class="table-responsive mb-3">
                    <!-- Örnek: matrisin o anki skala setine göre JS ile çizilir (excelHedefGuncelle) -->
                    <table class="table table-bordered table-sm text-center align-middle small mb-1" id="excelOrnekTablo"></table>
                    <span class="text-muted small">Beklenen format (örnek):</span>
                </div>

                <div class="d-flex align-items-center gap-2 mb-3">
                    <button type="button" class="btn btn-outline-primary" id="btnOrnekExcel">
                        <i class="bi bi-download"></i> Örnek Excel İndir
                    </button>
                </div>

                <label class="form-label">Excel Dosyası Seçin <span class="text-danger">*</span></label>
                <input type="file" class="form-control" id="excelFile" accept=".xlsx,.xls,.csv">
                <div class="form-text">Desteklenen formatlar: .xlsx, .xls, .csv</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                <button type="button" class="btn btn-success" id="btnExcelYukle"><i class="bi bi-arrow-down-square"></i> Matrise Aktar</button>
            </div>
        </div>
    </div>
</div>

<!-- Alt Bayi Eşleştirme Modal -->
<div class="modal fade" id="grupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-diagram-3 text-primary"></i> Alt Bayi Eşleştirme (Skala Grupları)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <i class="bi bi-info-circle"></i>
                    Aynı gruptaki alt bayilerin dönem <strong>adetleri toplanır</strong> ve tek bir
                    <strong>skala kademesi</strong> hesaplanır. Her alt bayi hakedişini
                    <strong>kendi tanımlı fiyatlarından</strong>, ancak bu ortak kademeden alır.
                    Bir alt bayi aynı anda yalnız bir gruba üye olabilir.
                </div>

                <div class="row g-3">
                    <!-- Grup listesi -->
                    <div class="col-lg-5">
                        <div class="card card-outline card-primary mb-0">
                            <div class="card-header py-2">
                                <h3 class="card-title fs-6 mb-0"><i class="bi bi-collection"></i> Gruplar</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnGrupYeni">
                                        <i class="bi bi-plus-lg"></i> Yeni Grup
                                    </button>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="list-group list-group-flush" id="grupListesi">
                                    <div class="list-group-item text-muted small">Yükleniyor...</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Grup detay + üyeler -->
                    <div class="col-lg-7">
                        <div class="card card-outline card-success mb-0">
                            <div class="card-header py-2">
                                <h3 class="card-title fs-6 mb-0"><i class="bi bi-pencil-square"></i> <span id="grupDetayBaslik">Grup Seçiniz</span></h3>
                            </div>
                            <div class="card-body" id="grupDetayBody" style="display:none">
                                <input type="hidden" id="g_id">
                                <div class="row g-2 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Grup Adı <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="g_ad" maxlength="150" placeholder="Örn: ORNEK BAYI Grubu">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Açıklama</label>
                                        <input type="text" class="form-control" id="g_aciklama" maxlength="500" placeholder="Opsiyonel">
                                    </div>
                                    <div class="col-12 text-end">
                                        <button type="button" class="btn btn-sm btn-primary" id="btnGrupKaydet">
                                            <i class="bi bi-save"></i> Grubu Kaydet
                                        </button>
                                    </div>
                                </div>

                                <hr class="my-3">

                                <div id="grupUyeAlani">
                                    <label class="form-label">
                                        Gruba Dahil Alt Bayiler
                                        <small class="text-muted">(çoklu seçim)</small>
                                    </label>
                                    <select class="form-select" id="g_uyeler" multiple></select>
                                    <div class="form-text" id="g_uyeNot"></div>
                                    <div class="text-end mt-3">
                                        <button type="button" class="btn btn-success" id="btnUyeKaydet">
                                            <i class="bi bi-people"></i> Üyeleri Kaydet
                                        </button>
                                    </div>
                                </div>
                                <div id="grupUyeUyari" class="alert alert-warning py-2 px-3 small mb-0" style="display:none">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    Üye eklemek için önce grubu kaydedin.
                                </div>
                            </div>
                            <div class="card-body text-muted small" id="grupDetayBos">
                                Soldaki listeden bir grup seçin veya <strong>Yeni Grup</strong> ile oluşturun.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
            </div>
        </div>
    </div>
</div>

<!-- Skala Setleri Modal -->
<div class="modal fade" id="skalaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-sliders text-secondary"></i> Skala Setleri</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabSetler" type="button" role="tab">
                            <i class="bi bi-collection"></i> Setler
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabAtamalar" type="button" role="tab">
                            <i class="bi bi-shop"></i> Bayi Atamaları
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Setler -->
                    <div class="tab-pane fade show active" id="tabSetler" role="tabpanel">
                        <div class="alert alert-info py-2 px-3 small mb-3">
                            <i class="bi bi-info-circle"></i>
                            Ataması olmayan alt bayiler <strong>varsayılan</strong> seti kullanır.
                            Alt sınır <strong>dahil</strong>, üst sınır <strong>hariç</strong>tir
                            (ör. 0-30 → 0..29). En yüksek kademenin üst sınırı boş bırakılır (sınırsız).
                        </div>
                        <div class="row g-3">
                            <div class="col-lg-5">
                                <div class="card card-outline card-secondary mb-0">
                                    <div class="card-header py-2">
                                        <h3 class="card-title fs-6 mb-0"><i class="bi bi-collection"></i> Setler</h3>
                                        <div class="card-tools">
                                            <button type="button" class="btn btn-sm btn-outline-secondary skala-admin" id="btnSetYeni">
                                                <i class="bi bi-plus-lg"></i> Yeni Set
                                            </button>
                                        </div>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="list-group list-group-flush" id="setListesi">
                                            <div class="list-group-item text-muted small">Yükleniyor...</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-7">
                                <div class="card card-outline card-success mb-0">
                                    <div class="card-header py-2">
                                        <h3 class="card-title fs-6 mb-0"><i class="bi bi-pencil-square"></i> <span id="setDetayBaslik">Set Seçiniz</span></h3>
                                    </div>
                                    <div class="card-body" id="setDetayBody" style="display:none">
                                        <input type="hidden" id="s_id">
                                        <div class="mb-3">
                                            <label class="form-label" for="s_ad">Set Adı <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="s_ad" maxlength="100" placeholder="Örn: Yeni Sistem (0-30-60)">
                                        </div>
                                        <div class="alert alert-warning py-2 px-3 small" id="s_kullanimUyari" style="display:none"></div>
                                        <table class="table table-sm table-bordered align-middle mb-2">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Kademe Adı</th>
                                                    <th style="width:120px">Alt Sınır</th>
                                                    <th style="width:120px">Üst Sınır</th>
                                                    <th style="width:50px"></th>
                                                </tr>
                                            </thead>
                                            <tbody id="kademeSatirlari"></tbody>
                                        </table>
                                        <div class="d-flex justify-content-between">
                                            <button type="button" class="btn btn-sm btn-outline-secondary skala-admin" id="btnKademeEkle">
                                                <i class="bi bi-plus-lg"></i> Kademe Ekle
                                            </button>
                                            <button type="button" class="btn btn-sm btn-success skala-admin" id="btnSetKaydet">
                                                <i class="bi bi-save"></i> Seti Kaydet
                                            </button>
                                        </div>
                                    </div>
                                    <div class="card-body text-muted small" id="setDetayBos">
                                        Soldaki listeden bir set seçin veya <strong>Yeni Set</strong> ile oluşturun.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bayi Atamaları -->
                    <div class="tab-pane fade" id="tabAtamalar" role="tabpanel">
                        <div class="alert alert-info py-2 px-3 small mb-3">
                            <i class="bi bi-info-circle"></i>
                            Atama, seçilen dönemden <strong>itibaren</strong> geçerlidir; önceki dönemler eski setle kalır.
                            Alt bayi bir skala grubundaysa atama grubun <strong>tüm üyelerine</strong> uygulanır.
                        </div>
                        <div class="row g-2 align-items-end mb-3">
                            <div class="col-md-4">
                                <label class="form-label" for="a_altbayi">Alt Bayi <span class="text-danger">*</span></label>
                                <select class="form-select" id="a_altbayi"></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="a_set">Skala Seti <span class="text-danger">*</span></label>
                                <select class="form-select" id="a_set"></select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="a_yil">Başlangıç Yılı</label>
                                <select class="form-select" id="a_yil"></select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="a_ay">Başlangıç Ayı</label>
                                <select class="form-select" id="a_ay"></select>
                            </div>
                            <div class="col-md-1 d-grid">
                                <button type="button" class="btn btn-success" id="btnAtamaKaydet" title="Atamayı Kaydet"><i class="bi bi-save"></i></button>
                            </div>
                        </div>
                        <table class="table table-sm table-bordered table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Alt Bayi</th>
                                    <th>Skala Seti</th>
                                    <th>Başlangıç</th>
                                    <th>Skala Grubu</th>
                                    <th style="width:60px"></th>
                                </tr>
                            </thead>
                            <tbody id="atamaSatirlari">
                                <tr><td colspan="5" class="text-muted small">Yükleniyor...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
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
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
    const permissions = {
        canAdd:    <?= $permissions['can_add']    ? 'true' : 'false' ?>,
        canEdit:   <?= $permissions['can_edit']   ? 'true' : 'false' ?>,
        canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>,
        isAdmin:   <?= !empty($permissions['is_admin']) ? 'true' : 'false' ?>
    };

    // Tüm kademe etiketleri (liste/filtre); Skala Setleri modalında değişince güncellenir
    let SKALALAR = <?= json_encode((object)$SKALALAR, JSON_UNESCAPED_UNICODE) ?>;
    // Matris sütunları: seçili bayinin dönemdeki setinden (matrisGetir). İlk çizim varsayılan setle.
    let MATRIS_KADEMELER = <?= json_encode(
        $VARSAYILAN_SET_ID ? hakedisSetKademeleri($db, $VARSAYILAN_SET_ID) : [], JSON_UNESCAPED_UNICODE) ?>;
    let matrisIstekNo = 0;
    const AYLAR    = <?= json_encode($AYLAR, JSON_UNESCAPED_UNICODE) ?>;
    // Kampanya tanımları: [{id, ad}]  +  id → ad haritası
    const KAMPANYA_TANIMLARI = <?= json_encode($KAMPANYA_TANIMLARI, JSON_UNESCAPED_UNICODE) ?>;
    const KAMPANYA_AD = {};
    KAMPANYA_TANIMLARI.forEach(k => KAMPANYA_AD[k.id] = k.ad);

    let dataTable, tutarModal, grupModal, currentFilters = {};
    let ALT_BAYILER = [], GRUPLAR = [];
    let skalaModal, SETLER = [], skalaDegisti = false;

    $(document).ready(function () {
        tutarModal = new bootstrap.Modal(document.getElementById('tutarModal'));
        grupModal  = new bootstrap.Modal(document.getElementById('grupModal'));
        skalaModal = new bootstrap.Modal(document.getElementById('skalaModal'));

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc']],
            columnDefs: [{ orderable: false, targets: [7] }],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        });

        // Statik filtre select'leri (.form-select) custom.js tarafından otomatik
        // Select2 yapılıyor — burada tekrar init ETME (çift init hayalet dropdown yapar).

        renderMatris(MATRIS_KADEMELER);
        initDonemSelect();
        loadAltBayiler();
        loadStats();
        loadList();
        updateDonemText();

        $('#m_yil, #m_ay').on('change', function () { updateDonemText(); matrisGetir(); });
        $('#m_altbayi').on('change', function () { matrisGetir(); grupBilgiGuncelle(); });

        $('#btnMatrisKaydet').on('click', matrisKaydet);
        $('#btnMatrisTemizle').on('click', function () {
            $('.matris-input').val('');
            showToast('Matris temizlendi', 'info');
        });

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            currentFilters = {
                altbayi:        $('#filter_altbayi').val(),
                yil:            $('#filter_yil').val(),
                ay:             $('#filter_ay').val(),
                kampanya_tanim: $('#filter_kampanya_tanim').val(),
                skala:          $('#filter_skala').val(),
                tutar_min:      $('#filter_tutar_min').val(),
                tutar_max:      $('#filter_tutar_max').val()
            };
            Object.keys(currentFilters).forEach(k => { if (!currentFilters[k]) delete currentFilters[k]; });
            loadList();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filterForm')[0].reset();
            $('#filter_altbayi, #filter_yil, #filter_ay, #filter_kampanya_tanim, #filter_skala').val('').trigger('change');
            currentFilters = {};
            loadList();
            showToast('Filtreler temizlendi', 'info');
        });

        $('#tutarForm').on('submit', function (e) {
            e.preventDefault();
            tutarKaydet();
        });

        $('#btnSkalaModal').on('click', skalaModalAc);
        $('#btnSetYeni').on('click', setYeni);
        $('#btnKademeEkle').on('click', () => kademeSatirEkle({}));
        $('#btnSetKaydet').on('click', setKaydet);
        $('#btnAtamaKaydet').on('click', atamaKaydet);
        $('#kademeSatirlari').on('click', '.kademe-sil', function () { $(this).closest('tr').remove(); });
        // Modal kapanınca matris sütunları / liste etiketleri yeni setlerle tazelensin
        $('#skalaModal').on('hidden.bs.modal', function () {
            if (!skalaDegisti) return;
            skalaDegisti = false;
            matrisGetir();
            loadList();
        });

        $('#btnGrupModal').on('click', grupModalAc);
        $('#btnGrupYeni').on('click', grupYeni);
        $('#btnGrupKaydet').on('click', grupKaydet);
        $('#btnUyeKaydet').on('click', uyeKaydet);

        $('#btnOrnekExcel').on('click', ornekExcelIndir);
        $('#btnExcelYukle').on('click', excelYukle);
        $('#excelModal').on('show.bs.modal', excelHedefGuncelle);
    });

    function excelHedefGuncelle() {
        const abVal = $('#m_altbayi').val();
        const abAd  = $('#m_altbayi').find('option:selected').text();
        const yil   = $('#m_yil').val();
        const aylar = ($('#m_ay').val() || []).map(Number).sort((a, b) => a - b);
        if (!abVal || !yil || aylar.length === 0) {
            $('#excelHedefText').html('<span class="text-danger">Önce Alt Bayi, Yıl ve Ay seçiniz</span>');
        } else {
            const ayAd = aylar.map(a => AYLAR[a]).join(', ');
            $('#excelHedefText').text(`${abAd} · ${ayAd} ${yil}`);
        }
        // Beklenen format: matrisin o anki kademeleri
        const bas = '<thead class="table-light"><tr><th class="bg-light"></th>'
            + MATRIS_KADEMELER.map(k => `<th>${escapeHtml(k.ad)}</th>`).join('') + '</tr></thead>';
        const govde = KAMPANYA_TANIMLARI.map(kt => `<tr><th class="table-light text-start">${escapeHtml(kt.ad)}</th>`
            + MATRIS_KADEMELER.map(() => '<td class="text-muted">0,00</td>').join('') + '</tr>').join('');
        $('#excelOrnekTablo').html(bas + '<tbody>' + govde + '</tbody>');
    }

    // ── Excel yükleme ─────────────────────────────────────────────
    // İsim eşleştirme için normalize (küçük harf, TR karakter, boşluk/işaret temizliği)
    function norm(str) {
        return String(str == null ? '' : str)
            .toLocaleLowerCase('tr-TR')
            .replace(/[İIı]/g, 'i').replace(/ı/g, 'i')
            .replace(/[çÇ]/g, 'c').replace(/[ğĞ]/g, 'g')
            .replace(/[öÖ]/g, 'o').replace(/[şŞ]/g, 's').replace(/[üÜ]/g, 'u')
            .replace(/[^a-z0-9]/g, '');
    }

    // "₺1.703,00" / "950,00" / 950.5 → Number
    function parseTutar(v) {
        if (v == null || v === '') return null;
        if (typeof v === 'number') return v;
        let s = String(v).replace(/[^\d.,-]/g, '').trim();
        if (s === '') return null;
        // Türkçe: nokta binlik, virgül ondalık
        if (s.indexOf(',') > -1) s = s.replace(/\./g, '').replace(',', '.');
        const n = parseFloat(s);
        return isNaN(n) ? null : n;
    }

    function ornekExcelIndir() {
        const skl = MATRIS_KADEMELER.map(k => k.ad);
        const aoa = [['Kampanya \\ Skala', ...skl]];
        KAMPANYA_TANIMLARI.forEach(k => {
            aoa.push([k.ad, ...skl.map(() => 0)]);
        });
        const ws = XLSX.utils.aoa_to_sheet(aoa);
        ws['!cols'] = [{ wch: 22 }, ...skl.map(() => ({ wch: 18 }))];
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Hakedis');
        XLSX.writeFile(wb, 'hakedis-ornek.xlsx');
    }

    function excelYukle() {
        const altbayi = $('#m_altbayi').val();
        const yil = $('#m_yil').val();
        const aylar = $('#m_ay').val() || [];
        if (!altbayi || !yil || aylar.length === 0) {
            showToast('Önce Alt Bayi, Yıl ve Ay seçiniz', 'warning');
            return;
        }
        const file = document.getElementById('excelFile').files[0];
        if (!file) { showToast('Lütfen bir Excel dosyası seçiniz', 'warning'); return; }

        const reader = new FileReader();
        reader.onload = function (e) {
            try {
                const wb = XLSX.read(e.target.result, { type: 'array' });
                const ws = wb.Sheets[wb.SheetNames[0]];
                const rows = XLSX.utils.sheet_to_json(ws, { header: 1, blankrows: false, defval: '' });
                if (!rows.length) { showError('Hata!', 'Dosya boş görünüyor.'); return; }

                // Başlık satırı → skala sütunlarını isimle eşle
                const baslik = rows[0].map(norm);
                // Sütunlar matrisin o anki setinin kademeleriyle eşlenir (isimle, yoksa sırayla)
                const skalaKodlari = MATRIS_KADEMELER.map(k => String(k.id));
                const skalaNormMap = {};                            // normAd → kod
                MATRIS_KADEMELER.forEach(k => skalaNormMap[norm(k.ad)] = String(k.id));
                // Kampanya tanımı: normAd → id
                const kampNormMap = {};
                KAMPANYA_TANIMLARI.forEach(k => kampNormMap[norm(k.ad)] = k.id);

                // Sütun index → skala kodu
                const colToSkala = {};
                for (let c = 1; c < baslik.length; c++) {
                    if (skalaNormMap[baslik[c]]) colToSkala[c] = skalaNormMap[baslik[c]];
                }
                const isimEslesme = Object.keys(colToSkala).length === skalaKodlari.length;

                $('.matris-input').val('');
                let dolduruldu = 0;

                for (let r = 1; r < rows.length; r++) {
                    const satir = rows[r];
                    if (!satir || satir.length === 0) continue;
                    // Satır → kampanya tanımı id (isimle, yoksa sıraya göre)
                    let kkod = kampNormMap[norm(satir[0])];
                    if (!kkod && KAMPANYA_TANIMLARI[r - 1]) kkod = KAMPANYA_TANIMLARI[r - 1].id;   // sıra bazlı yedek
                    if (!kkod) continue;

                    for (let c = 1; c < satir.length; c++) {
                        const skod = isimEslesme ? colToSkala[c] : skalaKodlari[c - 1];
                        if (!skod) continue;
                        const val = parseTutar(satir[c]);
                        if (val === null) continue;
                        $(`.matris-input[data-k="${kkod}"][data-s="${skod}"]`).val(val);
                        dolduruldu++;
                    }
                }

                if (dolduruldu === 0) {
                    showError('Eşleşme yok!', 'Excel başlıkları/satırları tanınamadı. Örnek Excel formatını kullanın.');
                    return;
                }
                bootstrap.Modal.getInstance(document.getElementById('excelModal')).hide();
                document.getElementById('excelFile').value = '';
                showToast(dolduruldu + ' değer matrise aktarıldı. Kontrol edip Kaydet\'e basın.', 'success');
            } catch (err) {
                showError('Okuma hatası!', 'Excel okunamadı: ' + err.message);
            }
        };
        reader.readAsArrayBuffer(file);
    }

    // Çift init (hayalet dropdown) ve açık dropdown'ın scroll handler'ının
    // DOM'da kalmasından doğan "select2('close') ... not using Select2" hatasını engeller.
    // ÖNEMLİ sıra: önce close+destroy, SONRA .html(), en son init.
    function rebuildSelect2($el, html, opts) {
        if ($el.hasClass('select2-hidden-accessible')) {
            try { $el.select2('close'); } catch (e) {}
            try { $el.select2('destroy'); } catch (e) {}
        }
        if (html !== null && html !== undefined) $el.html(html);
        return $el.select2(opts);
    }

    function initDonemSelect() {
        const buYil = new Date().getFullYear();
        const buAy  = new Date().getMonth() + 1;

        let yilOpts = '';
        for (let y = buYil + 1; y >= buYil - 3; y--) {
            yilOpts += `<option value="${y}" ${y === buYil ? 'selected' : ''}>${y}</option>`;
        }

        let ayOpts = '';
        for (const no in AYLAR) {
            ayOpts += `<option value="${no}" ${parseInt(no) === buAy ? 'selected' : ''}>${AYLAR[no]}</option>`;
        }

        rebuildSelect2($('#m_yil'), yilOpts, { theme: 'bootstrap-5', width: '100%' });
        rebuildSelect2($('#m_ay'), ayOpts, { theme: 'bootstrap-5', width: '100%', placeholder: 'Ay seçiniz...', closeOnSelect: false });
    }

    function loadAltBayiler() {
        $.post('', { action: 'altbayiler' }, function (r) {
            if (!r.success) return;
            ALT_BAYILER = r.data || [];
            const optsM = ['<option value="">Seçiniz...</option>'];
            const optsF = ['<option value="">Tümü</option>'];
            r.data.forEach(b => {
                const o = `<option value="${b.DigiturkAltBayiler_Id}">${escapeHtml(b.Etiket)}</option>`;
                optsM.push(o); optsF.push(o);
            });
            rebuildSelect2($('#m_altbayi'), optsM.join(''), { theme: 'bootstrap-5', width: '100%', placeholder: 'Seçiniz...' });
            rebuildSelect2($('#filter_altbayi'), optsF.join(''), { theme: 'bootstrap-5', width: '100%' });
            matrisGetir();
            grupBilgiGuncelle();
        }, 'json');
    }

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-altbayi').text(r.data.altbayi);
            $('#stat-donem').text(r.data.donem);
        }, 'json');
    }

    function donemAralik(yil, ay) {
        let sAy = ay + 1, sYil = yil;
        if (sAy > 12) { sAy = 1; sYil++; }
        const bas = `04.${String(ay).padStart(2,'0')}.${yil}`;
        const bit = `03.${String(sAy).padStart(2,'0')}.${sYil}`;
        return `${bas} - ${bit}`;
    }

    function updateDonemText() {
        const yil  = parseInt($('#m_yil').val());
        const aylar = ($('#m_ay').val() || []).map(Number).sort((a,b)=>a-b);
        if (!yil || aylar.length === 0) { $('#m_donem_text').text('-'); return; }
        if (aylar.length === 1) {
            $('#m_donem_text').text(donemAralik(yil, aylar[0]));
        } else {
            $('#m_donem_text').text(`${aylar.length} dönem: ` + aylar.map(a => AYLAR[a]).join(', '));
        }
    }

    // Matris: satır = kampanya tanımı, sütun = kademe (bayinin setinden)
    function renderMatris(kademeler) {
        MATRIS_KADEMELER = kademeler || [];
        $('#matrisHead').html('<tr><th class="matris-corner">Kampanya \\ Skala</th>'
            + MATRIS_KADEMELER.map(k => `<th>${escapeHtml(k.ad)}</th>`).join('') + '</tr>');

        if (!KAMPANYA_TANIMLARI.length) {
            $('#matrisBody').html(`<tr><td colspan="${MATRIS_KADEMELER.length + 1}" class="text-center text-muted py-3">
                <i class="bi bi-info-circle"></i> Aktif kampanya tanımı yok. Önce
                <a href="hakedis-kampanya-tanimlama.php">Hakediş Kampanya Tanımlama</a> sayfasından kampanya ekleyin.</td></tr>`);
            return;
        }
        $('#matrisBody').html(KAMPANYA_TANIMLARI.map(kt =>
            `<tr><th class="matris-corner text-start">${escapeHtml(kt.ad)}</th>` +
            MATRIS_KADEMELER.map(k => `<td>
                <div class="input-group input-group-sm">
                    <input type="number" step="0.01" min="0" class="form-control matris-input"
                           data-k="${kt.id}" data-s="${k.id}" placeholder="0,00">
                    <span class="input-group-text">₺</span>
                </div></td>`).join('') + '</tr>').join(''));
    }

    function matrisGetir() {
        const altbayi = $('#m_altbayi').val() || '';
        const yil = $('#m_yil').val();
        const aylar = $('#m_ay').val() || [];
        $('.matris-input').val('');
        if (!yil || aylar.length === 0) { $('#m_set_bilgi').hide(); return; }

        // Set (sütunlar) her seçimde çözülür; değerler yalnız tek ayda yüklenir (çoklu seçimde belirsiz)
        const no = ++matrisIstekNo;
        $.post('', { action: 'matris_get', altbayi, yil, aylar }, function (r) {
            if (no !== matrisIstekNo) return;   // eski yanıt
            const $bilgi = $('#m_set_bilgi');
            if (!r.success) {
                $bilgi.removeClass('alert-secondary alert-warning').addClass('alert-danger')
                      .html(`<i class="bi bi-exclamation-octagon"></i> ${escapeHtml(r.message)}`).show();
                return;
            }
            renderMatris(r.kademeler);
            let html = `<i class="bi bi-sliders"></i> Skala seti: <strong>${escapeHtml(r.set.ad)}</strong>`
                     + (r.set.varsayilan ? ' <span class="badge bg-secondary">varsayılan</span>' : ' <span class="badge bg-primary">atama</span>');
            if (r.digerSet > 0) {
                html += `<br><i class="bi bi-exclamation-triangle"></i> Bu dönemde başka bir setin kademelerine yazılmış
                         <strong>${r.digerSet}</strong> tanım var; hesaplamada kullanılmaz. Listeden silebilirsiniz.`;
            }
            $bilgi.removeClass('alert-danger alert-secondary alert-warning')
                  .addClass(r.digerSet > 0 ? 'alert-warning' : 'alert-secondary').html(html).show();

            for (const k in (r.data || {})) {
                for (const s in r.data[k]) {
                    $(`.matris-input[data-k="${k}"][data-s="${s}"]`).val(parseFloat(r.data[k][s]));
                }
            }
        }, 'json');
    }

    function matrisKaydet() {
        const altbayi = $('#m_altbayi').val();
        const yil = $('#m_yil').val();
        const aylar = $('#m_ay').val() || [];
        if (!altbayi || !yil || aylar.length === 0) { showToast('Alt bayi, yıl ve en az bir ay seçiniz', 'warning'); return; }
        if ($('.matris-input').length === 0) { showToast('Aktif kampanya tanımı yok — önce kampanya ekleyin', 'warning'); return; }

        const kaydet = function () {
            const fd = new FormData();
            fd.append('action', 'matris_save');
            fd.append('altbayi', altbayi);
            fd.append('yil', yil);
            aylar.forEach(a => fd.append('aylar[]', a));
            $('.matris-input').each(function () {
                const k = $(this).data('k'), s = $(this).data('s');
                fd.append(`tutar[${k}][${s}]`, $(this).val());
            });

            const $btn = $('#btnMatrisKaydet');
            $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Kaydediliyor...');

            $.ajax({
                url: '', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
                success: function (r) {
                    if (r.success) { showSuccess('Tamamlandı!', r.message); loadList(); loadStats(); }
                    else showError('Hata!', r.message);
                },
                error: function () { showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'); },
                complete: function () { $btn.prop('disabled', false).html('<i class="bi bi-save"></i> Kaydet'); }
            });
        };

        if (aylar.length > 1) {
            const adlar = aylar.map(Number).sort((a,b)=>a-b).map(a => AYLAR[a]).join(', ');
            confirmAction(
                `Aynı tutarlar ${aylar.length} döneme yazılacak`,
                `Seçili aylar: ${adlar} (${yil}). Devam edilsin mi?`,
                kaydet
            );
        } else {
            kaydet();
        }
    }

    function loadList() {
        $.ajax({
            url: '', method: 'POST', data: { action: 'list', ...currentFilters }, dataType: 'json',
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
            const donem  = `<span class="donem-badge">${AYLAR[row.DigiturkHakedisTanimlari_DonemAy]} ${row.DigiturkHakedisTanimlari_DonemYil}</span>`;
            const aralik = `${row.Baslangic} - ${row.Bitis}`;
            const kampTanim = `<span class="kampanya-badge">${escapeHtml(row.KampanyaAdi || KAMPANYA_AD[row.DigiturkHakedisTanimlari_KampanyaTanim_id] || '-')}</span>`;
            const skala  = `<span class="skala-badge">${escapeHtml(SKALALAR[row.DigiturkHakedisTanimlari_AdetSkalasiKodu] || '-')}</span>`;
            const tutar  = `<span class="tutar-text">${formatTutar(row.DigiturkHakedisTanimlari_Tutar)}</span>`;

            let islemler = '';
            if (permissions.canEdit)
                islemler += `<button class="btn btn-sm btn-warning me-1" onclick='editTutar(${JSON.stringify(row)})' title="Tutarı Düzenle"><i class="bi bi-pencil"></i></button>`;
            if (permissions.canDelete)
                islemler += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${row.DigiturkHakedisTanimlari_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
            if (!islemler) islemler = '<span class="text-muted">-</span>';

            dataTable.row.add([
                escapeHtml(row.AltBayiAd || '-'),
                donem,
                `<span class="text-muted small">${aralik}</span>`,
                kampTanim,
                skala,
                tutar,
                escapeHtml(row.GuncellemeTarihi || '-'),
                islemler
            ]);
        });
        dataTable.draw();
    }

    function formatTutar(v) {
        const n = parseFloat(v || 0);
        return n.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function editTutar(row) {
        $('#t_id').val(row.DigiturkHakedisTanimlari_id);
        $('#t_tutar').val(parseFloat(row.DigiturkHakedisTanimlari_Tutar));
        $('#t_bilgi').html(
            `<strong>${escapeHtml(row.AltBayiAd)}</strong> &middot; ` +
            `${AYLAR[row.DigiturkHakedisTanimlari_DonemAy]} ${row.DigiturkHakedisTanimlari_DonemYil} &middot; ` +
            `${escapeHtml(row.KampanyaAdi || KAMPANYA_AD[row.DigiturkHakedisTanimlari_KampanyaTanim_id] || '-')} / ` +
            `${escapeHtml(SKALALAR[row.DigiturkHakedisTanimlari_AdetSkalasiKodu])}`
        );
        tutarModal.show();
    }

    function tutarKaydet() {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'tutar_guncelle', id: $('#t_id').val(), tutar: $('#t_tutar').val() },
            dataType: 'json',
            success: function (r) {
                if (r.success) { showToast(r.message, 'success'); tutarModal.hide(); loadList(); }
                else showToast(r.message, 'error');
            },
            error: function () { showToast('Kayıt sırasında hata oluştu', 'error'); }
        });
    }

    // ── Alt Bayi Eşleştirme (Skala Grupları) ──────────────────────
    // Aynı gruptaki alt bayilerin adetleri toplanır, tek skala kademesi bulunur.
    // Alt bayi seçenekleri ALT_BAYILER'den gelir (loadAltBayiler dolduruyor).
    function grupModalAc() {
        loadGruplar();
        grupDetayGizle();
        grupModal.show();
    }

    function grupDetayGizle() {
        $('#grupDetayBody').hide();
        $('#grupDetayBos').show();
        $('#grupDetayBaslik').text('Grup Seçiniz');
    }

    function loadGruplar(seciliId) {
        $.post('', { action: 'gruplar' }, function (r) {
            if (!r.success) { showToast('Gruplar yüklenemedi', 'error'); return; }
            GRUPLAR = r.data || [];
            const $liste = $('#grupListesi');
            if (!GRUPLAR.length) {
                $liste.html('<div class="list-group-item text-muted small">Henüz grup tanımlanmamış.</div>');
                if (!seciliId) grupDetayGizle();
                return;
            }
            $liste.html(GRUPLAR.map(g => {
                const uyeler = g.uyeler ? escapeHtml(g.uyeler) : '<span class="text-muted">Üye yok</span>';
                const silBtn = permissions.canDelete
                    ? `<button class="btn btn-sm btn-outline-danger ms-2" onclick="grupSil(event, ${g.id})" title="Grubu Sil"><i class="bi bi-trash"></i></button>`
                    : '';
                return `
                    <div class="list-group-item list-group-item-action grup-satir" data-id="${g.id}" style="cursor:pointer">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="me-2">
                                <div class="fw-semibold">${escapeHtml(g.ad)}
                                    <span class="badge bg-secondary ms-1">${g.uyeSayisi} alt bayi</span>
                                </div>
                                <div class="small text-muted">${uyeler}</div>
                            </div>
                            <div class="text-nowrap">${silBtn}</div>
                        </div>
                    </div>`;
            }).join(''));
            $('.grup-satir').on('click', function () { grupSec($(this).data('id')); });
            if (seciliId) grupSec(seciliId);
        }, 'json');
    }

    function grupSec(id) {
        const g = GRUPLAR.find(x => String(x.id) === String(id));
        if (!g) return;
        $('.grup-satir').removeClass('active');
        $(`.grup-satir[data-id="${id}"]`).addClass('active');
        $('#g_id').val(g.id);
        $('#g_ad').val(g.ad);
        $('#g_aciklama').val(g.aciklama || '');
        $('#grupDetayBaslik').text('Grup Düzenle');
        $('#grupDetayBos').hide();
        $('#grupDetayBody').show();
        $('#grupUyeAlani').show();
        $('#grupUyeUyari').hide();
        loadGrupUyeleri(g.id);
    }

    function grupYeni() {
        $('.grup-satir').removeClass('active');
        $('#g_id').val('');
        $('#g_ad').val('');
        $('#g_aciklama').val('');
        $('#grupDetayBaslik').text('Yeni Grup');
        $('#grupDetayBos').hide();
        $('#grupDetayBody').show();
        $('#grupUyeAlani').hide();
        $('#grupUyeUyari').show();
        setTimeout(() => $('#g_ad').trigger('focus'), 200);
    }

    function grupKaydet() {
        const ad = $('#g_ad').val().trim();
        if (!ad) { showToast('Grup adı zorunludur', 'warning'); return; }
        const $btn = $('#btnGrupKaydet').prop('disabled', true);
        $.post('', { action: 'grup_kaydet', grup_id: $('#g_id').val() || 0, ad: ad, aciklama: $('#g_aciklama').val() },
            function (r) {
                if (r.success) { showToast(r.message, 'success'); loadGruplar(r.id); }
                else showError('Hata!', r.message);
            }, 'json')
         .always(() => $btn.prop('disabled', false));
    }

    function grupSil(e, id) {
        e.stopPropagation();
        confirmAction(
            'Bu grubu silmek istediğinize emin misiniz?',
            'Grup üyelikleri de kaldırılır; alt bayiler yeniden kendi adetleriyle kademelenir.',
            function () {
                $.post('', { action: 'grup_sil', grup_id: id }, function (r) {
                    if (r.success) { showSuccess('Silindi!', r.message); loadGruplar(); grupDetayGizle(); grupBilgiGuncelle(); }
                    else showError('Hata!', r.message);
                }, 'json');
            }
        );
    }

    function loadGrupUyeleri(grupId) {
        $.post('', { action: 'grup_uyeler', grup_id: grupId }, function (r) {
            if (!r.success) return;
            const secili   = r.data.map(u => String(u.id));
            const yetkisiz = r.data.filter(u => !u.yetkili);

            // Seçilebilir liste + listede olmayan (yetki dışı) mevcut üyeler
            const varOlan = ALT_BAYILER.map(b => String(b.DigiturkAltBayiler_Id));
            let opts = ALT_BAYILER.map(b =>
                `<option value="${b.DigiturkAltBayiler_Id}">${escapeHtml(b.Etiket)}</option>`).join('');
            r.data.forEach(u => {
                if (varOlan.indexOf(String(u.id)) === -1)
                    opts += `<option value="${u.id}">${escapeHtml(u.etiket || ('#' + u.id))}</option>`;
            });

            // Modal içi Select2: dropdownParent şart (arama kutusu focus alsın)
            rebuildSelect2($('#g_uyeler'), opts, {
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Alt bayi seçiniz...',
                closeOnSelect: false,
                dropdownParent: $('#grupModal')
            });
            $('#g_uyeler').val(secili).trigger('change.select2');

            $('#g_uyeNot').html(yetkisiz.length
                ? `<span class="text-warning"><i class="bi bi-shield-lock"></i> ${yetkisiz.length} üye birim yetkiniz dışında — çıkaramazsınız.</span>`
                : 'Seçimden çıkardığınız alt bayiler gruptan ayrılır.');
        }, 'json');
    }

    function uyeKaydet() {
        const grupId = $('#g_id').val();
        if (!grupId) { showToast('Önce grubu kaydedin', 'warning'); return; }
        const secili = $('#g_uyeler').val() || [];
        const $btn = $('#btnUyeKaydet').prop('disabled', true);
        const fd = new FormData();
        fd.append('action', 'grup_uye_kaydet');
        fd.append('grup_id', grupId);
        secili.forEach(v => fd.append('uyeler[]', v));
        $.ajax({
            url: '', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function (r) {
                if (r.success) { showSuccess('Tamamlandı!', r.message); loadGruplar(grupId); grupBilgiGuncelle(); }
                else showError('Hata!', r.message);
            },
            error: function () { showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'); },
            complete: function () { $btn.prop('disabled', false); }
        });
    }

    // Matris üstünde: seçili alt bayi bir gruptaysa bilgilendirme rozeti
    function grupBilgiGuncelle() {
        const ab    = $('#m_altbayi').val();
        const $kutu = $('#m_grup_bilgi');
        if (!$kutu.length) return;
        if (!ab) { $kutu.hide(); return; }
        $.post('', { action: 'altbayi_grup', altbayi: ab }, function (r) {
            if (!r.success || !r.data) { $kutu.hide(); return; }
            $kutu.html(
                `<i class="bi bi-diagram-3"></i> Bu alt bayi <strong>${escapeHtml(r.data.ad)}</strong> ` +
                `grubunda (${r.data.uyeSayisi} alt bayi). Skala kademesi grup toplam adedinden hesaplanır.`
            ).show();
        }, 'json');
    }

    // ── Skala Setleri ─────────────────────────────────────────────
    function skalaModalAc() {
        $('.skala-admin').toggle(permissions.isAdmin);
        setDetayGizle();
        atamaFormHazirla();
        loadSetler();
        loadAtamalar();
        skalaModal.show();
    }

    function setDetayGizle() {
        $('#setDetayBody').hide();
        $('#setDetayBos').show();
        $('#setDetayBaslik').text('Set Seçiniz');
    }

    function loadSetler(seciliId) {
        $.post('', { action: 'skala_setleri' }, function (r) {
            if (!r.success) { showToast('Skala setleri yüklenemedi', 'error'); return; }
            SETLER = r.data || [];

            // Etiket haritası + liste filtresi tazelenir (yeni kademeler)
            SETLER.forEach(s => s.kademeler.forEach(k => SKALALAR[k.id] = k.ad));
            const filtreOpts = '<option value="">Tümü</option>' + SETLER.map(s =>
                `<optgroup label="${escapeHtml(s.ad)}">` +
                s.kademeler.map(k => `<option value="${k.id}">${escapeHtml(k.ad)}</option>`).join('') + '</optgroup>').join('');
            const filtreSecili = $('#filter_skala').val();
            rebuildSelect2($('#filter_skala'), filtreOpts, { theme: 'bootstrap-5', width: '100%' });
            $('#filter_skala').val(filtreSecili).trigger('change.select2');

            // Atama formundaki set listesi
            const setSecili = $('#a_set').val();
            rebuildSelect2($('#a_set'), '<option value="">Seçiniz...</option>' + SETLER.map(s =>
                `<option value="${s.id}">${escapeHtml(s.ad)}${s.varsayilan == 1 ? ' (varsayılan)' : ''}</option>`).join(''),
                { theme: 'bootstrap-5', width: '100%', dropdownParent: $('#skalaModal') });
            if (setSecili) $('#a_set').val(setSecili).trigger('change.select2');

            const $liste = $('#setListesi');
            if (!SETLER.length) { $liste.html('<div class="list-group-item text-muted small">Henüz set yok.</div>'); return; }
            $liste.html(SETLER.map(s => {
                const ozet = s.kademeler.map(k => escapeHtml(k.ad)).join(' · ') || '<span class="text-muted">Kademe yok</span>';
                const varsayilan = s.varsayilan == 1;
                const sw = `<div class="form-check form-switch mb-0" title="Varsayılan set">
                        <input class="form-check-input set-varsayilan" type="checkbox" role="switch"
                               id="set_vars_${s.id}" data-id="${s.id}" value="1" ${varsayilan ? 'checked' : ''}
                               ${(!permissions.isAdmin || varsayilan) ? 'disabled' : ''}>
                        <label class="form-check-label small" for="set_vars_${s.id}">Varsayılan</label>
                    </div>`;
                const silBtn = (permissions.isAdmin && !varsayilan)
                    ? `<button class="btn btn-sm btn-outline-danger ms-2 set-sil" data-id="${s.id}" title="Seti Sil"><i class="bi bi-trash"></i></button>` : '';
                return `<div class="list-group-item list-group-item-action set-satir" data-id="${s.id}" style="cursor:pointer">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="me-2">
                                <div class="fw-semibold">${escapeHtml(s.ad)}
                                    <span class="badge bg-secondary ms-1">${s.atamaSayisi} bayi</span>
                                    <span class="badge bg-light text-dark ms-1">${s.tanimSayisi} tanım</span>
                                </div>
                                <div class="small text-muted">${ozet}</div>
                            </div>
                            <div class="d-flex align-items-center text-nowrap">${sw}${silBtn}</div>
                        </div>
                    </div>`;
            }).join(''));

            $('.set-satir').on('click', function (e) {
                if ($(e.target).closest('.form-check, .set-sil').length) return;
                setSec($(this).data('id'));
            });
            $('.set-varsayilan').on('change', function () { setVarsayilanYap($(this).data('id'), this); });
            $('.set-sil').on('click', function (e) { e.stopPropagation(); setSil($(this).data('id')); });
            if (seciliId) setSec(seciliId);
        }, 'json');
    }

    function kademeSatirEkle(k) {
        const kilit = !permissions.isAdmin ? 'disabled' : '';
        $('#kademeSatirlari').append(`<tr data-id="${k.id || ''}">
            <td><input type="text" class="form-control form-control-sm k-ad" maxlength="100" value="${escapeHtml(k.ad || '')}" placeholder="Örn: 0-30 Arası" ${kilit}></td>
            <td><input type="number" min="0" step="1" class="form-control form-control-sm k-alt" value="${k.alt ?? ''}" ${kilit}></td>
            <td><input type="number" min="1" step="1" class="form-control form-control-sm k-ust" value="${k.ust ?? ''}" placeholder="sınırsız" ${kilit}></td>
            <td class="text-center">${permissions.isAdmin ? '<button type="button" class="btn btn-sm btn-outline-danger kademe-sil" title="Kademeyi Çıkar"><i class="bi bi-x-lg"></i></button>' : ''}</td>
        </tr>`);
    }

    function setSec(id) {
        const s = SETLER.find(x => String(x.id) === String(id));
        if (!s) return;
        $('.set-satir').removeClass('active');
        $(`.set-satir[data-id="${id}"]`).addClass('active');
        $('#s_id').val(s.id);
        $('#s_ad').val(s.ad).prop('disabled', !permissions.isAdmin);
        $('#kademeSatirlari').empty();
        // Düzenleme alttan üste (0 → sınırsız) daha okunaklı
        [...s.kademeler].sort((a, b) => a.alt - b.alt).forEach(kademeSatirEkle);
        const uyari = [];
        if (s.varsayilan == 1) uyari.push('Bu <strong>varsayılan</strong> set: sınır değişikliği ataması olmayan tüm bayileri etkiler.');
        if (s.tanimSayisi > 0) uyari.push(`Bu setin kademelerinde <strong>${s.tanimSayisi}</strong> hakediş tanımı var: sınır değişikliği eski dönem hesaplarını da değiştirir.`);
        $('#s_kullanimUyari').html(uyari.map(u => `<i class="bi bi-exclamation-triangle"></i> ${u}`).join('<br>')).toggle(uyari.length > 0);
        $('#setDetayBaslik').text('Set Düzenle');
        $('#setDetayBos').hide();
        $('#setDetayBody').show();
    }

    function setYeni() {
        $('.set-satir').removeClass('active');
        $('#s_id').val('');
        $('#s_ad').val('').prop('disabled', false);
        $('#kademeSatirlari').empty();
        kademeSatirEkle({ alt: 0 });
        $('#s_kullanimUyari').hide();
        $('#setDetayBaslik').text('Yeni Set');
        $('#setDetayBos').hide();
        $('#setDetayBody').show();
        setTimeout(() => $('#s_ad').trigger('focus'), 200);
    }

    function setKaydet() {
        const ad = $('#s_ad').val().trim();
        if (!ad) { showToast('Set adı zorunludur', 'warning'); return; }
        const kademeler = $('#kademeSatirlari tr').map(function () {
            return {
                id:  $(this).data('id') || 0,
                ad:  $(this).find('.k-ad').val().trim(),
                alt: $(this).find('.k-alt').val(),
                ust: $(this).find('.k-ust').val()
            };
        }).get();
        if (!kademeler.length) { showToast('En az bir kademe ekleyin', 'warning'); return; }

        const gonder = function () {
            const $btn = $('#btnSetKaydet').prop('disabled', true);
            $.post('', { action: 'skala_set_kaydet', set_id: $('#s_id').val() || 0, ad, kademeler: JSON.stringify(kademeler) }, function (r) {
                if (r.success) { showToast(r.message, 'success'); skalaDegisti = true; loadSetler(r.id); }
                else showError('Hata!', r.message);
            }, 'json').always(() => $btn.prop('disabled', false));
        };
        const s = SETLER.find(x => String(x.id) === String($('#s_id').val()));
        if (s && (s.tanimSayisi > 0 || s.varsayilan == 1)) {
            confirmAction('Kullanımdaki set değiştirilecek', 'Sınır değişikliği bu seti kullanan tüm dönemlerin hesabını etkiler. Devam edilsin mi?', gonder);
        } else {
            gonder();
        }
    }

    function setVarsayilanYap(id, el) {
        const s = SETLER.find(x => String(x.id) === String(id));
        el.checked = false;   // onaydan sonra liste yenilenince doğru durum gelir
        confirmAction(
            `"${s ? s.ad : ''}" varsayılan set yapılsın mı?`,
            'Ataması olmayan TÜM alt bayilerin kademesi (eski dönemler dahil) bu setten hesaplanır.',
            function () {
                $.post('', { action: 'skala_set_varsayilan', set_id: id }, function (r) {
                    if (r.success) { showToast(r.message, 'success'); skalaDegisti = true; loadSetler(); }
                    else showError('Hata!', r.message);
                }, 'json');
            }
        );
    }

    function setSil(id) {
        confirmAction('Bu seti silmek istediğinize emin misiniz?', 'Yalnız atama ve hakediş tanımı olmayan set silinebilir.', function () {
            $.post('', { action: 'skala_set_sil', set_id: id }, function (r) {
                if (r.success) { showSuccess('Silindi!', r.message); skalaDegisti = true; loadSetler(); setDetayGizle(); }
                else showError('Hata!', r.message);
            }, 'json');
        });
    }

    function atamaFormHazirla() {
        const modalOpt = { theme: 'bootstrap-5', width: '100%', dropdownParent: $('#skalaModal') };
        rebuildSelect2($('#a_altbayi'), '<option value="">Seçiniz...</option>' + ALT_BAYILER.map(b =>
            `<option value="${b.DigiturkAltBayiler_Id}">${escapeHtml(b.Etiket)}</option>`).join(''), modalOpt);
        // Varsayılan başlangıç: matristeki yıl / ilk ay
        const yil = parseInt($('#m_yil').val()) || new Date().getFullYear();
        const ay  = ($('#m_ay').val() || []).map(Number).sort((a, b) => a - b)[0] || (new Date().getMonth() + 1);
        let yilOpts = '';
        for (let y = new Date().getFullYear() + 1; y >= new Date().getFullYear() - 3; y--) {
            yilOpts += `<option value="${y}" ${y === yil ? 'selected' : ''}>${y}</option>`;
        }
        rebuildSelect2($('#a_yil'), yilOpts, modalOpt);
        rebuildSelect2($('#a_ay'), Object.keys(AYLAR).map(no =>
            `<option value="${no}" ${parseInt(no) === ay ? 'selected' : ''}>${AYLAR[no]}</option>`).join(''), modalOpt);
    }

    function loadAtamalar() {
        $.post('', { action: 'skala_atamalari' }, function (r) {
            const $tb = $('#atamaSatirlari');
            if (!r.success) { $tb.html('<tr><td colspan="5" class="text-danger small">Atamalar yüklenemedi</td></tr>'); return; }
            if (!r.data.length) {
                $tb.html('<tr><td colspan="5" class="text-muted small">Atama yok — tüm alt bayiler varsayılan seti kullanıyor.</td></tr>');
                return;
            }
            $tb.html(r.data.map(a => `<tr>
                <td>${escapeHtml(a.altbayi || ('#' + a.altbayiId))}</td>
                <td><span class="skala-badge">${escapeHtml(a.setAd)}</span></td>
                <td><span class="donem-badge">${AYLAR[a.ay]} ${a.yil}</span> ve sonrası</td>
                <td>${a.grupAd ? escapeHtml(a.grupAd) : '<span class="text-muted">-</span>'}</td>
                <td class="text-center">${permissions.canDelete
                    ? `<button class="btn btn-sm btn-outline-danger" onclick="atamaSil(${a.id})" title="Atamayı Kaldır"><i class="bi bi-trash"></i></button>` : ''}</td>
            </tr>`).join(''));
        }, 'json');
    }

    function atamaKaydet() {
        const veri = { action: 'skala_atama_kaydet', altbayi: $('#a_altbayi').val(), set_id: $('#a_set').val(), yil: $('#a_yil').val(), ay: $('#a_ay').val() };
        if (!veri.altbayi || !veri.set_id) { showToast('Alt bayi ve skala seti seçiniz', 'warning'); return; }
        const $btn = $('#btnAtamaKaydet').prop('disabled', true);
        $.post('', veri, function (r) {
            if (!r.success) { showError('Hata!', r.message); return; }
            skalaDegisti = true;
            loadAtamalar(); loadSetler();
            if (r.eskiTanim > 0) {
                showWarning('Atama kaydedildi', `${r.message}. Başlangıç döneminden sonra eski setin kademelerine yazılmış ${r.eskiTanim} tanım var; `
                    + 'bu dönemlerin matrisini yeni kademelerle yeniden girin.');
            } else {
                showToast(r.message, 'success');
            }
        }, 'json').always(() => $btn.prop('disabled', false));
    }

    function atamaSil(id) {
        confirmAction('Atama kaldırılsın mı?', 'Bayi o dönemden itibaren önceki atamasına veya varsayılan sete döner.', function () {
            $.post('', { action: 'skala_atama_sil', atama_id: id }, function (r) {
                if (r.success) { showToast(r.message, 'success'); skalaDegisti = true; loadAtamalar(); loadSetler(); }
                else showError('Hata!', r.message);
            }, 'json');
        });
    }

    function deleteRecord(id) {
        confirmAction(
            'Bu hakediş kaydını silmek istediğinize emin misiniz?',
            'Bu işlem geri alınamaz!',
            function () {
                $.ajax({
                    url: '', method: 'POST', data: { action: 'delete', id: id }, dataType: 'json',
                    success: function (r) {
                        if (r.success) { showSuccess('Silindi!', r.message); loadList(); loadStats(); }
                        else showError('Hata!', r.message);
                    },
                    error: function () { showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'); }
                });
            }
        );
    }
</script>
</body>
</html>
