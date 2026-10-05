<?php
/**
 * Admin Panel - Hakediş Tanımlama
 * Alt bayi bazında, dönem (ayın 4'ü - sonraki ayın 3'ü) için
 * Kampanya Tanımı x Adet Skalası matrisinde hakediş tutarları.
 * Kampanya satırları HakedisKampanyaTanimlari tablosundan (FK) gelir.
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Hakediş Tanımlama';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ── Sabit listeler ───────────────────────────────────────────────
$SKALALAR = [1 => '2000 Adet ve Üzeri', 2 => '1000-2000 Arası', 3 => '500-1000 Arası', 4 => '0-500 Arası'];
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

            // Matris için mevcut dönem değerlerini getir
            case 'matris_get':
                $altbayi = (int)($_POST['altbayi'] ?? 0);
                $yil     = (int)($_POST['yil'] ?? 0);
                $ay      = (int)($_POST['ay'] ?? 0);

                if (!$altbayi || !$yil || !$ay) {
                    echo json_encode(['success' => false, 'message' => 'Alt bayi, yıl ve ay seçilmelidir']);
                    break;
                }
                if (!$altBayiErisim($altbayi)) {
                    echo json_encode(['success' => false, 'message' => 'Bu alt bayi için yetkiniz yok']);
                    break;
                }

                $rows = $db->fetchAll("
                    SELECT DigiturkHakedisTanimlari_KampanyaTanim_id AS k,
                           DigiturkHakedisTanimlari_AdetSkalasiKodu AS s,
                           DigiturkHakedisTanimlari_Tutar AS t
                    FROM DigiturkHakedisTanimlari
                    WHERE DigiturkHakedisTanimlari_AltBayi_id = ?
                      AND DigiturkHakedisTanimlari_DonemYil = ?
                      AND DigiturkHakedisTanimlari_DonemAy = ?
                ", [$altbayi, $yil, $ay]);

                $matris = [];
                foreach ($rows as $r) {
                    $matris[$r['k']][$r['s']] = $r['t'];
                }

                echo json_encode(['success' => true, 'data' => $matris]);
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
                        foreach ([1, 2, 3, 4] as $s) {
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

                        <div class="table-responsive">
                            <table class="table table-bordered matris-table mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="matris-corner">Kampanya \ Skala</th>
                                        <?php foreach ($SKALALAR as $sk => $sad): ?>
                                            <th><?= htmlspecialchars($sad) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($KAMPANYA_TANIMLARI)): ?>
                                    <tr>
                                        <td colspan="<?= count($SKALALAR) + 1 ?>" class="text-center text-muted py-3">
                                            <i class="bi bi-info-circle"></i>
                                            Aktif kampanya tanımı yok. Önce
                                            <a href="hakedis-kampanya-tanimlama.php">Hakediş Kampanya Tanımlama</a>
                                            sayfasından kampanya ekleyin.
                                        </td>
                                    </tr>
                                    <?php else: foreach ($KAMPANYA_TANIMLARI as $kt): ?>
                                    <tr>
                                        <th class="matris-corner text-start"><?= htmlspecialchars($kt['ad']) ?></th>
                                        <?php foreach ($SKALALAR as $sk => $sad): ?>
                                        <td>
                                            <div class="input-group input-group-sm">
                                                <input type="number" step="0.01" min="0"
                                                       class="form-control matris-input"
                                                       data-k="<?= $kt['id'] ?>" data-s="<?= $sk ?>"
                                                       placeholder="0,00">
                                                <span class="input-group-text">₺</span>
                                            </div>
                                        </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
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
                                        <?php foreach ($SKALALAR as $sk => $sad): ?><option value="<?= $sk ?>"><?= htmlspecialchars($sad) ?></option><?php endforeach; ?>
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
                    <table class="table table-bordered table-sm text-center align-middle small mb-1">
                        <thead class="table-light">
                            <tr>
                                <th class="bg-light"></th>
                                <?php foreach ($SKALALAR as $sad): ?><th><?= htmlspecialchars($sad) ?></th><?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($KAMPANYA_TANIMLARI as $kt): ?>
                            <tr>
                                <th class="table-light text-start"><?= htmlspecialchars($kt['ad']) ?></th>
                                <?php foreach ($SKALALAR as $s): ?><td class="text-muted">0,00</td><?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
        canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
    };

    const SKALALAR = <?= json_encode($SKALALAR, JSON_UNESCAPED_UNICODE) ?>;
    const AYLAR    = <?= json_encode($AYLAR, JSON_UNESCAPED_UNICODE) ?>;
    // Kampanya tanımları: [{id, ad}]  +  id → ad haritası
    const KAMPANYA_TANIMLARI = <?= json_encode($KAMPANYA_TANIMLARI, JSON_UNESCAPED_UNICODE) ?>;
    const KAMPANYA_AD = {};
    KAMPANYA_TANIMLARI.forEach(k => KAMPANYA_AD[k.id] = k.ad);

    let dataTable, tutarModal, grupModal, currentFilters = {};
    let ALT_BAYILER = [], GRUPLAR = [];

    $(document).ready(function () {
        tutarModal = new bootstrap.Modal(document.getElementById('tutarModal'));
        grupModal  = new bootstrap.Modal(document.getElementById('grupModal'));

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc']],
            columnDefs: [{ orderable: false, targets: [7] }],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        });

        // Statik filtre select'leri (.form-select) custom.js tarafından otomatik
        // Select2 yapılıyor — burada tekrar init ETME (çift init hayalet dropdown yapar).

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
        const skl = Object.values(SKALALAR);
        const aoa = [['Kampanya \\ Skala', ...skl]];
        KAMPANYA_TANIMLARI.forEach(k => {
            aoa.push([k.ad, 0, 0, 0, 0]);
        });
        const ws = XLSX.utils.aoa_to_sheet(aoa);
        ws['!cols'] = [{ wch: 22 }, { wch: 16 }, { wch: 16 }, { wch: 16 }, { wch: 16 }];
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
                const skalaKodlari = Object.keys(SKALALAR);        // ['1','2','3','4']
                const skalaNormMap = {};                            // normAd → kod
                skalaKodlari.forEach(k => skalaNormMap[norm(SKALALAR[k])] = k);
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

    function matrisGetir() {
        const altbayi = $('#m_altbayi').val();
        const yil = $('#m_yil').val();
        const aylar = $('#m_ay').val() || [];
        $('.matris-input').val('');
        // Yalnızca tek ay seçiliyse mevcut değerleri yükle (çoklu seçimde belirsiz)
        if (!altbayi || !yil || aylar.length !== 1) return;

        $.post('', { action: 'matris_get', altbayi, yil, ay: aylar[0] }, function (r) {
            if (!r.success || !r.data) return;
            for (const k in r.data) {
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
