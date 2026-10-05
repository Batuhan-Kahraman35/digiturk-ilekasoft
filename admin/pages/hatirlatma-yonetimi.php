<?php
/**
 * Admin Panel - Hatırlatma Yönetimi (ortak cron hatırlatma motoru)
 *
 * Kural  = ne zaman / hangi koşulla çalışır, nasıl sıfırlanır
 * Aşama  = kime, hangi mesaj, hangi aralıkla; nasıl bir sonrakine geçer
 *
 * Motor: cron/tasks.php → gorevHatirlatmaGonder()
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Hatırlatma Yönetimi';
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

// ─── AJAX ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $uid    = (int)$user['kullanici_id'];
    $simdi  = date('Y-m-d H:i:s');

    try {
        switch ($action) {

            // ── Tanım listeleri (dropdown'lar tamamen DB'den) ────────────────
            case 'tanim_listesi':
                echo json_encode([
                    'success'      => true,
                    'kosullar'     => $db->fetchAll("
                        SELECT CronHatirlatmaKosulTurleri_Id AS Id, CronHatirlatmaKosulTurleri_Ad AS Ad,
                               CronHatirlatmaKosulTurleri_Kod AS Kod, CronHatirlatmaKosulTurleri_Aciklama AS Aciklama,
                               CronHatirlatmaKosulTurleri_ParametreSemasi AS Semasi,
                               CronHatirlatmaKosulTurleri_Degiskenler AS Degiskenler
                        FROM CronHatirlatmaKosulTurleri WHERE Durum = 1 ORDER BY CronHatirlatmaKosulTurleri_Ad"),
                    'sifirlamalar' => $db->fetchAll("
                        SELECT CronHatirlatmaSifirlamaTurleri_Id AS Id, CronHatirlatmaSifirlamaTurleri_Ad AS Ad,
                               CronHatirlatmaSifirlamaTurleri_Aciklama AS Aciklama
                        FROM CronHatirlatmaSifirlamaTurleri WHERE Durum = 1 ORDER BY CronHatirlatmaSifirlamaTurleri_Id"),
                    'hizalamalar'  => $db->fetchAll("
                        SELECT CronHatirlatmaHizalamaTurleri_Id AS Id, CronHatirlatmaHizalamaTurleri_Ad AS Ad,
                               CronHatirlatmaHizalamaTurleri_Aciklama AS Aciklama
                        FROM CronHatirlatmaHizalamaTurleri WHERE Durum = 1 ORDER BY CronHatirlatmaHizalamaTurleri_Id"),
                    'tetikleyiciler' => $db->fetchAll("
                        SELECT CronHatirlatmaTetikleyiciTurleri_Id AS Id, CronHatirlatmaTetikleyiciTurleri_Ad AS Ad,
                               CronHatirlatmaTetikleyiciTurleri_Kod AS Kod, CronHatirlatmaTetikleyiciTurleri_Aciklama AS Aciklama
                        FROM CronHatirlatmaTetikleyiciTurleri WHERE Durum = 1 ORDER BY CronHatirlatmaTetikleyiciTurleri_Id"),
                    'gunler'       => $db->fetchAll("
                        SELECT HaftaGunleri_Id AS Id, HaftaGunleri_Ad AS Ad, HaftaGunleri_GunNo AS GunNo
                        FROM HaftaGunleri WHERE Durum = 1 ORDER BY HaftaGunleri_GunNo"),
                    'waKanallar'   => $db->fetchAll("
                        SELECT k.EntegrasyonKanallari_id AS Id, k.EntegrasyonKanallari_KanalAdi AS Ad, e.Entegrasyonlar_Adi AS Entegrasyon
                        FROM EntegrasyonKanallari k
                        INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                        WHERE e.Entegrasyonlar_Tip = 'whatsapp' AND k.Durum = 1 AND e.Durum = 1
                        ORDER BY e.Entegrasyonlar_Adi, k.EntegrasyonKanallari_KanalAdi"),
                    'voipKanallar' => $db->fetchAll("
                        SELECT k.EntegrasyonKanallari_id AS Id, k.EntegrasyonKanallari_KanalAdi AS Ad, e.Entegrasyonlar_Adi AS Entegrasyon
                        FROM EntegrasyonKanallari k
                        INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                        WHERE e.Entegrasyonlar_Tip = 'voip' AND k.Durum = 1 AND e.Durum = 1
                        ORDER BY e.Entegrasyonlar_Adi, k.EntegrasyonKanallari_KanalAdi"),
                ]);
                break;

            // ── Kural listesi ────────────────────────────────────────────────
            case 'kural_listele':
                $liste = $db->fetchAll("
                    SELECT k.CronHatirlatmaKurallari_Id             AS Id,
                           k.CronHatirlatmaKurallari_Ad             AS Ad,
                           k.CronHatirlatmaKurallari_Aciklama       AS Aciklama,
                           k.CronHatirlatmaKurallari_KosulTuruId    AS KosulTuruId,
                           k.CronHatirlatmaKurallari_KosulParametre AS KosulParametre,
                           k.CronHatirlatmaKurallari_SifirlamaTuruId AS SifirlamaTuruId,
                           kt.CronHatirlatmaKosulTurleri_Ad         AS KosulAd,
                           kt.CronHatirlatmaKosulTurleri_Kod        AS KosulKod,
                           st.CronHatirlatmaSifirlamaTurleri_Ad     AS SifirlamaAd,
                           k.CronHatirlatmaKurallari_AktifAsamaId   AS AktifAsamaId,
                           a.CronHatirlatmaAsamalari_Ad             AS AktifAsamaAd,
                           a.CronHatirlatmaAsamalari_SiraNo         AS AktifAsamaSira,
                           a.CronHatirlatmaAsamalari_HedefNo        AS AktifHedefNo,
                           a.CronHatirlatmaAsamalari_MaxDeneme      AS AktifMaxDeneme,
                           a.CronHatirlatmaAsamalari_PeriyotDakika  AS AktifPeriyot,
                           a.CronHatirlatmaAsamalari_IstenenAlanlar AS AktifIstenenAlanlar,
                           CONVERT(VARCHAR(5), a.CronHatirlatmaAsamalari_BaslangicSaati, 108) AS AktifBaslangic,
                           CONVERT(VARCHAR(5), a.CronHatirlatmaAsamalari_BitisSaati, 108)     AS AktifBitis,
                           k.CronHatirlatmaKurallari_DenemeSayisi   AS DenemeSayisi,
                           CONVERT(VARCHAR(19), k.CronHatirlatmaKurallari_SonGonderimZamani, 120) AS SonGonderim,
                           CONVERT(VARCHAR(19), k.CronHatirlatmaKurallari_SonTamamlamaZamani, 120) AS SonTamamlama,
                           CONVERT(VARCHAR(10), k.CronHatirlatmaKurallari_SonTamamlamaTarihi, 120) AS SonTamamlamaTarihi,
                           tt.CronHatirlatmaTetikleyiciTurleri_Ad   AS TamamlayanTetikleyici,
                           k.Durum                                  AS Aktif,
                           (SELECT COUNT(*) FROM CronHatirlatmaAsamalari x
                             WHERE x.CronHatirlatmaAsamalari_KuralId = k.CronHatirlatmaKurallari_Id AND x.Durum = 1) AS AsamaSayisi,
                           STUFF((SELECT ', ' + g.HaftaGunleri_Ad
                                  FROM CronHatirlatmaKuralGunleri kg
                                  INNER JOIN HaftaGunleri g ON kg.CronHatirlatmaKuralGunleri_GunId = g.HaftaGunleri_Id
                                  WHERE kg.CronHatirlatmaKuralGunleri_KuralId = k.CronHatirlatmaKurallari_Id
                                    AND kg.Durum = 1 AND g.Durum = 1
                                  ORDER BY g.HaftaGunleri_GunNo
                                  FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 2, '') AS Gunler
                    FROM CronHatirlatmaKurallari k
                    INNER JOIN CronHatirlatmaKosulTurleri kt
                            ON k.CronHatirlatmaKurallari_KosulTuruId = kt.CronHatirlatmaKosulTurleri_Id
                    INNER JOIN CronHatirlatmaSifirlamaTurleri st
                            ON k.CronHatirlatmaKurallari_SifirlamaTuruId = st.CronHatirlatmaSifirlamaTurleri_Id
                    LEFT JOIN CronHatirlatmaAsamalari a
                           ON k.CronHatirlatmaKurallari_AktifAsamaId = a.CronHatirlatmaAsamalari_Id
                    LEFT JOIN CronHatirlatmaTetikleyiciTurleri tt
                           ON k.CronHatirlatmaKurallari_TamamlamaTetikleyiciId = tt.CronHatirlatmaTetikleyiciTurleri_Id
                    ORDER BY k.CronHatirlatmaKurallari_Id DESC
                ");

                foreach ($liste as &$k) {
                    $k['Gunleri'] = array_column($db->fetchAll("
                        SELECT CronHatirlatmaKuralGunleri_GunId AS GunId
                        FROM CronHatirlatmaKuralGunleri
                        WHERE CronHatirlatmaKuralGunleri_KuralId = ? AND Durum = 1
                    ", [(int)$k['Id']]), 'GunId');
                }
                unset($k);

                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            // ── İnfoBox ──────────────────────────────────────────────────────
            case 'istatistik':
                $s = $db->fetchOne("
                    SELECT
                      (SELECT COUNT(*) FROM CronHatirlatmaKurallari WHERE Durum = 1) AS Aktif,
                      (SELECT COUNT(*) FROM CronHatirlatmaKurallari
                        WHERE Durum = 1 AND CronHatirlatmaKurallari_AktifAsamaId IS NOT NULL) AS Bekleyen,
                      (SELECT COUNT(*) FROM CronHatirlatmaKurallari
                        WHERE Durum = 1 AND CronHatirlatmaKurallari_SonTamamlamaTarihi = CAST(GETDATE() AS DATE)) AS BugunTamamlanan,
                      (SELECT COUNT(*) FROM EntegrasyonLoglari l
                        WHERE l.EntegrasyonLoglari_Tip = 'whatsapp'
                          AND CAST(l.EntegrasyonLoglari_GonderimTarihi AS DATE) = CAST(GETDATE() AS DATE)
                          AND l.EntegrasyonLoglari_Alici IN (
                                SELECT DISTINCT CronHatirlatmaAsamalari_HedefNo
                                FROM CronHatirlatmaAsamalari WHERE Durum = 1)) AS BugunGonderim
                ");
                echo json_encode(['success' => true, 'data' => $s]);
                break;

            // ── Kural kaydet ─────────────────────────────────────────────────
            case 'kural_kaydet':
                $id       = (int)($_POST['id'] ?? 0);
                $ad       = trim($_POST['ad'] ?? '');
                $aciklama = trim($_POST['aciklama'] ?? '');
                $kosulId  = (int)($_POST['kosul_turu_id'] ?? 0);
                $sifId    = (int)($_POST['sifirlama_turu_id'] ?? 0);
                $kosulPrm = trim($_POST['kosul_parametre'] ?? '');
                $aktif    = !empty($_POST['aktif']) ? 1 : 0;
                $gunler   = array_filter(array_map('intval', (array)($_POST['gunler'] ?? [])));

                if ($ad === '' || !$kosulId || !$sifId) {
                    echo json_encode(['success' => false, 'message' => 'Ad, koşul türü ve sıfırlama türü zorunludur.']);
                    break;
                }
                if ($kosulPrm !== '' && json_decode($kosulPrm, true) === null) {
                    echo json_encode(['success' => false, 'message' => 'Koşul parametreleri geçerli JSON değil.']);
                    break;
                }

                if ($id > 0) {
                    if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok.']); break; }
                    $db->query("
                        UPDATE CronHatirlatmaKurallari SET
                            CronHatirlatmaKurallari_Ad              = ?,
                            CronHatirlatmaKurallari_Aciklama        = ?,
                            CronHatirlatmaKurallari_KosulTuruId     = ?,
                            CronHatirlatmaKurallari_KosulParametre  = ?,
                            CronHatirlatmaKurallari_SifirlamaTuruId = ?,
                            Durum = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = ?
                        WHERE CronHatirlatmaKurallari_Id = ?
                    ", [$ad, $aciklama ?: null, $kosulId, $kosulPrm ?: null, $sifId, $aktif, $uid, $simdi, $id]);
                    $mesaj = 'Kural güncellendi.';
                } else {
                    if (!$permissions['can_add']) { echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok.']); break; }
                    $id = $db->insert('CronHatirlatmaKurallari', [
                        'CronHatirlatmaKurallari_Ad'              => $ad,
                        'CronHatirlatmaKurallari_Aciklama'        => $aciklama ?: null,
                        'CronHatirlatmaKurallari_KosulTuruId'     => $kosulId,
                        'CronHatirlatmaKurallari_KosulParametre'  => $kosulPrm ?: null,
                        'CronHatirlatmaKurallari_SifirlamaTuruId' => $sifId,
                        'OlusturanKullanici'   => $uid, 'OlusturmaTarihi'  => $simdi,
                        'GuncelleyenKullanici' => $uid, 'GuncellemeTarihi' => $simdi,
                        'Durum' => $aktif,
                    ]);
                    $mesaj = 'Kural eklendi. Sırada aşama tanımlamak var.';
                }

                // Günler: tamamen yeniden yazılır
                $db->query("DELETE FROM CronHatirlatmaKuralGunleri WHERE CronHatirlatmaKuralGunleri_KuralId = ?", [$id]);
                foreach ($gunler as $gunId) {
                    $db->insert('CronHatirlatmaKuralGunleri', [
                        'CronHatirlatmaKuralGunleri_KuralId' => $id,
                        'CronHatirlatmaKuralGunleri_GunId'   => $gunId,
                        'OlusturanKullanici'   => $uid, 'OlusturmaTarihi'  => $simdi,
                        'GuncelleyenKullanici' => $uid, 'GuncellemeTarihi' => $simdi,
                        'Durum' => 1,
                    ]);
                }

                echo json_encode(['success' => true, 'message' => $mesaj, 'id' => $id]);
                break;

            case 'kural_sil':
                if (!$permissions['can_delete']) { echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok.']); break; }
                $db->query("UPDATE CronHatirlatmaKurallari SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = ?
                            WHERE CronHatirlatmaKurallari_Id = ?", [$uid, $simdi, (int)$_POST['id']]);
                echo json_encode(['success' => true, 'message' => 'Kural pasife alındı.']);
                break;

            // ── Aşamalar ─────────────────────────────────────────────────────
            case 'asama_listele':
                $kuralId = (int)$_POST['kural_id'];
                $asamalar = $db->fetchAll("
                    SELECT a.CronHatirlatmaAsamalari_Id             AS Id,
                           a.CronHatirlatmaAsamalari_SiraNo         AS SiraNo,
                           a.CronHatirlatmaAsamalari_Ad             AS Ad,
                           a.CronHatirlatmaAsamalari_KanalId        AS KanalId,
                           kn.EntegrasyonKanallari_KanalAdi         AS KanalAd,
                           a.CronHatirlatmaAsamalari_HedefNo        AS HedefNo,
                           a.CronHatirlatmaAsamalari_Mesaj          AS Mesaj,
                           a.CronHatirlatmaAsamalari_PeriyotDakika  AS PeriyotDakika,
                           CONVERT(VARCHAR(5), a.CronHatirlatmaAsamalari_BaslangicSaati, 108) AS BaslangicSaati,
                           CONVERT(VARCHAR(5), a.CronHatirlatmaAsamalari_BitisSaati, 108)     AS BitisSaati,
                           a.CronHatirlatmaAsamalari_HizalamaTuruId AS HizalamaTuruId,
                           a.CronHatirlatmaAsamalari_MaxDeneme      AS MaxDeneme,
                           a.CronHatirlatmaAsamalari_AnahtarKelime  AS AnahtarKelime,
                           a.CronHatirlatmaAsamalari_SonrakiAsamaId AS SonrakiAsamaId,
                           a.CronHatirlatmaAsamalari_IstenenAlanlar AS IstenenAlanlar,
                           a.CronHatirlatmaAsamalari_EkGonder       AS EkGonder
                    FROM CronHatirlatmaAsamalari a
                    LEFT JOIN EntegrasyonKanallari kn ON a.CronHatirlatmaAsamalari_KanalId = kn.EntegrasyonKanallari_id
                    WHERE a.CronHatirlatmaAsamalari_KuralId = ? AND a.Durum = 1
                    ORDER BY a.CronHatirlatmaAsamalari_SiraNo, a.CronHatirlatmaAsamalari_Id
                ", [$kuralId]);

                foreach ($asamalar as &$a) {
                    $a['Tetikleyiciler'] = $db->fetchAll("
                        SELECT t.CronHatirlatmaTetikleyiciTurleri_Id AS Id, t.CronHatirlatmaTetikleyiciTurleri_Ad AS Ad
                        FROM CronHatirlatmaAsamaTetikleyicileri at
                        INNER JOIN CronHatirlatmaTetikleyiciTurleri t
                                ON at.CronHatirlatmaAsamaTetikleyicileri_TetikleyiciId = t.CronHatirlatmaTetikleyiciTurleri_Id
                        WHERE at.CronHatirlatmaAsamaTetikleyicileri_AsamaId = ? AND at.Durum = 1 AND t.Durum = 1
                    ", [(int)$a['Id']]);
                }
                unset($a);

                echo json_encode(['success' => true, 'data' => $asamalar]);
                break;

            case 'asama_kaydet':
                if (!$permissions['can_edit'] && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']); break;
                }
                $id      = (int)($_POST['id'] ?? 0);
                $kuralId = (int)($_POST['kural_id'] ?? 0);
                $ad      = trim($_POST['ad'] ?? '');
                $kanalId = (int)($_POST['kanal_id'] ?? 0);
                $hedefNo = trim($_POST['hedef_no'] ?? '');
                $mesaj   = trim($_POST['mesaj'] ?? '');
                $periyot = (int)($_POST['periyot_dakika'] ?? 0);
                $sira    = (int)($_POST['sira_no'] ?? 1);
                $bas     = trim($_POST['baslangic_saati'] ?? '');
                $bit     = trim($_POST['bitis_saati'] ?? '');
                $hiz     = (int)($_POST['hizalama_turu_id'] ?? 0);
                $maxDen  = trim($_POST['max_deneme'] ?? '');
                $anahtar = trim($_POST['anahtar_kelime'] ?? '');
                $sonraki = (int)($_POST['sonraki_asama_id'] ?? 0);
                $istenen = trim($_POST['istenen_alanlar'] ?? '');
                $tetikler = array_filter(array_map('intval', (array)($_POST['tetikleyiciler'] ?? [])));

                if (!$kuralId || $ad === '' || !$kanalId || $hedefNo === '' || $mesaj === '') {
                    echo json_encode(['success' => false, 'message' => 'Ad, kanal, hedef ve mesaj zorunludur.']);
                    break;
                }
                if ($bas !== '' && $bit !== '' && $bas > $bit) {
                    echo json_encode(['success' => false, 'message' => 'Başlangıç saati bitiş saatinden büyük olamaz.']);
                    break;
                }

                $veri = [
                    'CronHatirlatmaAsamalari_KuralId'        => $kuralId,
                    'CronHatirlatmaAsamalari_SiraNo'         => $sira,
                    'CronHatirlatmaAsamalari_Ad'             => $ad,
                    'CronHatirlatmaAsamalari_KanalId'        => $kanalId,
                    'CronHatirlatmaAsamalari_HedefNo'        => $hedefNo,
                    'CronHatirlatmaAsamalari_Mesaj'          => $mesaj,
                    'CronHatirlatmaAsamalari_PeriyotDakika'  => $periyot,
                    'CronHatirlatmaAsamalari_BaslangicSaati' => $bas !== '' ? $bas : null,
                    'CronHatirlatmaAsamalari_BitisSaati'     => $bit !== '' ? $bit : null,
                    'CronHatirlatmaAsamalari_HizalamaTuruId' => $hiz ?: null,
                    'CronHatirlatmaAsamalari_MaxDeneme'      => $maxDen !== '' ? (int)$maxDen : null,
                    'CronHatirlatmaAsamalari_AnahtarKelime'  => $anahtar ?: null,
                    'CronHatirlatmaAsamalari_SonrakiAsamaId' => $sonraki ?: null,
                    'CronHatirlatmaAsamalari_IstenenAlanlar' => ($istenen !== '' && $istenen !== '[]') ? $istenen : null,
                    'CronHatirlatmaAsamalari_EkGonder'       => !empty($_POST['ek_gonder']) ? 1 : 0,
                ];

                if ($id > 0) {
                    $veri['GuncelleyenKullanici'] = $uid;
                    $veri['GuncellemeTarihi']     = $simdi;
                    $db->update('CronHatirlatmaAsamalari', $veri, ['CronHatirlatmaAsamalari_Id' => $id]);
                    $mesajSonuc = 'Aşama güncellendi.';
                } else {
                    $veri['OlusturanKullanici']   = $uid;
                    $veri['OlusturmaTarihi']      = $simdi;
                    $veri['GuncelleyenKullanici'] = $uid;
                    $veri['GuncellemeTarihi']     = $simdi;
                    $veri['Durum']                = 1;
                    $id = $db->insert('CronHatirlatmaAsamalari', $veri);
                    $mesajSonuc = 'Aşama eklendi.';

                    // Kuralın aktif aşaması yoksa bu aşamadan başlat
                    $db->query("
                        UPDATE CronHatirlatmaKurallari
                        SET CronHatirlatmaKurallari_AktifAsamaId = ?, GuncelleyenKullanici = ?, GuncellemeTarihi = ?
                        WHERE CronHatirlatmaKurallari_Id = ?
                          AND CronHatirlatmaKurallari_AktifAsamaId IS NULL
                          AND CronHatirlatmaKurallari_SonTamamlamaTarihi IS NULL
                    ", [$id, $uid, $simdi, $kuralId]);
                }

                $db->query("DELETE FROM CronHatirlatmaAsamaTetikleyicileri WHERE CronHatirlatmaAsamaTetikleyicileri_AsamaId = ?", [$id]);
                foreach ($tetikler as $tId) {
                    $db->insert('CronHatirlatmaAsamaTetikleyicileri', [
                        'CronHatirlatmaAsamaTetikleyicileri_AsamaId'       => $id,
                        'CronHatirlatmaAsamaTetikleyicileri_TetikleyiciId' => $tId,
                        'OlusturanKullanici'   => $uid, 'OlusturmaTarihi'  => $simdi,
                        'GuncelleyenKullanici' => $uid, 'GuncellemeTarihi' => $simdi,
                        'Durum' => 1,
                    ]);
                }

                echo json_encode(['success' => true, 'message' => $mesajSonuc]);
                break;

            case 'asama_sil':
                if (!$permissions['can_delete']) { echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok.']); break; }
                $asamaId = (int)$_POST['id'];

                $kullanan = $db->fetchOne("
                    SELECT COUNT(*) AS Adet FROM CronHatirlatmaKurallari
                    WHERE CronHatirlatmaKurallari_AktifAsamaId = ? AND Durum = 1
                ", [$asamaId]);
                if ((int)($kullanan['Adet'] ?? 0) > 0) {
                    echo json_encode(['success' => false, 'message' => 'Bu aşama şu an aktif. Önce kuralı ilerletin veya başka aşamaya alın.']);
                    break;
                }

                $db->query("UPDATE CronHatirlatmaAsamalari SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = ?
                            WHERE CronHatirlatmaAsamalari_Id = ?", [$uid, $simdi, $asamaId]);
                echo json_encode(['success' => true, 'message' => 'Aşama pasife alındı.']);
                break;

            // ── İşlemler ─────────────────────────────────────────────────────
            case 'asama_ilerlet':   // "Tamamla" butonu
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']); break; }
                require_once __DIR__ . '/../../cron/tasks.php';
                $veri = [];
                foreach ((array)($_POST['veri'] ?? []) as $ad => $deger) {
                    $veri[(string)$ad] = is_scalar($deger) ? (string)$deger : '';
                }
                $r = hatirlatmaAsamaIlerlet($db, (int)$_POST['id'], 'manuel', $uid, $veri);
                echo json_encode(['success' => $r['ok'], 'message' => $r['mesaj']]);
                break;

            case 'simdi_gonder':    // periyot/pencere beklemeden gönder
            case 'test_gonder':     // dry run — gönderim yok, çıktı döner
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']); break; }
                require_once __DIR__ . '/../../cron/tasks.php';
                $r = gorevHatirlatmaGonder([
                    'kural' => (int)$_POST['id'],
                    'dry'   => $action === 'test_gonder' ? 1 : 0,
                    'zorla' => $action === 'simdi_gonder' ? 1 : 0,
                ], $db);
                echo json_encode([
                    'success' => $r['durum'] == 1,
                    'message' => $r['sonuc'],
                    'cikti'   => $r['cikti'],
                ]);
                break;

            case 'gecmis':          // gönderim geçmişi (EntegrasyonLoglari)
                $kuralId = (int)$_POST['id'];
                $liste = $db->fetchAll("
                    SELECT TOP 100
                           CONVERT(VARCHAR(19), l.EntegrasyonLoglari_GonderimTarihi, 120) AS Tarih,
                           l.EntegrasyonLoglari_Alici          AS Alici,
                           l.EntegrasyonLoglari_Mesaj          AS Mesaj,
                           l.EntegrasyonLoglari_GonderimDurumu AS GonderimDurumu,
                           l.EntegrasyonLoglari_HataMesaj      AS HataMesaj
                    FROM EntegrasyonLoglari l
                    WHERE l.EntegrasyonLoglari_Tip = 'whatsapp'
                      AND l.EntegrasyonLoglari_Alici IN (
                            SELECT CronHatirlatmaAsamalari_HedefNo
                            FROM CronHatirlatmaAsamalari
                            WHERE CronHatirlatmaAsamalari_KuralId = ? AND Durum = 1)
                    ORDER BY l.EntegrasyonLoglari_id DESC
                ", [$kuralId]);
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        }
    } catch (Throwable $e) {
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

                <div class="alert alert-info d-flex align-items-center justify-content-between py-2">
                    <div>
                        <i class="bi bi-clock-history me-1"></i>
                        Gönderimler <strong>Cron Yönetimi</strong> üzerinden dakikada bir çalışan
                        <span class="fw-bold">Hatırlatma Motoru</span> görevi tarafından yapılır.
                        Bu sayfa kuralları ve aşamaları yönetir.
                    </div>
                    <a href="/Admin/pages/cron-yonetimi.php" class="btn btn-sm btn-outline-primary ms-3 text-nowrap">
                        <i class="bi bi-gear"></i> Cron Yönetimi
                    </a>
                </div>

                <!-- InfoBox -->
                <div class="row">
                    <div class="col-md-3 col-sm-6 col-12">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-bell"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Kural</span>
                                <span class="info-box-number" id="stat-aktif">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 col-12">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bekleyen (aşaması açık)</span>
                                <span class="info-box-number" id="stat-bekleyen">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 col-12">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check2-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün Tamamlanan</span>
                                <span class="info-box-number" id="stat-tamamlanan">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 col-12">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-whatsapp"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugünkü Gönderim</span>
                                <span class="info-box-number" id="stat-gonderim">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtre -->
                <div class="card card-outline card-secondary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtreler</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filtrePanel">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body collapse" id="filtrePanel">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Ara (ad / açıklama)</label>
                                <input type="text" class="form-control" id="f_arama" placeholder="Kural adı...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Koşul Türü</label>
                                <select class="form-select" id="f_kosul"><option value="">Tümü</option></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Sıfırlama</label>
                                <select class="form-select" id="f_sifirlama"><option value="">Tümü</option></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Durum</label>
                                <select class="form-select" id="f_durum">
                                    <option value="">Tümü</option>
                                    <option value="bekleyen">Bekleyen (aşaması açık)</option>
                                    <option value="tamamlanan">Tamamlanan</option>
                                    <option value="pasif">Pasif</option>
                                </select>
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn btn-secondary btn-sm" onclick="filtreTemizle()"><i class="bi bi-x-lg"></i> Temizle</button>
                                <button class="btn btn-primary btn-sm" onclick="filtreUygula()"><i class="bi bi-search"></i> Uygula</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Hatırlatma Kuralları</h3>
                        <div class="card-tools">
                            <?php if ($permissions['can_add']): ?>
                            <button class="btn btn-primary btn-sm" onclick="kuralModalAc()">
                                <i class="bi bi-plus-lg"></i> Yeni Kural
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="tblKurallar" class="table table-bordered table-striped table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Kural</th>
                                    <th>Koşul</th>
                                    <th>Sıfırlama</th>
                                    <th>Günler</th>
                                    <th>Aktif Aşama</th>
                                    <th>Son Gönderim</th>
                                    <th>Durum</th>
                                    <th style="min-width:230px">İşlem</th>
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

<!-- Kural Modal -->
<div class="modal fade" id="modalKural" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalKuralBaslik">Yeni Kural</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="k_id">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Kural Adı <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="k_ad" placeholder="Günlük WhatsApp Hatırlatma">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Açıklama</label>
                        <input type="text" class="form-control" id="k_aciklama">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Koşul Türü <span class="text-danger">*</span></label>
                        <select class="form-select" id="k_kosul"></select>
                        <small class="text-muted" id="k_kosul_aciklama"></small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Sıfırlama <span class="text-danger">*</span></label>
                        <select class="form-select" id="k_sifirlama"></select>
                        <small class="text-muted" id="k_sifirlama_aciklama"></small>
                    </div>

                    <div class="col-12" id="k_kosul_param_kutu" style="display:none">
                        <hr class="my-1">
                        <small class="text-muted fw-bold">Koşul Parametreleri</small>
                        <div class="row g-2 mt-1" id="k_kosul_param"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Çalışma Günleri <small class="text-muted">(boş = her gün)</small></label>
                        <select class="form-select" id="k_gunler" multiple></select>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="k_aktif" checked>
                            <label class="form-check-label" for="k_aktif">Kural Aktif</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" onclick="kuralKaydet()"><i class="bi bi-save"></i> Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- Aşamalar Modal -->
<div class="modal fade" id="modalAsamalar" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-diagram-3"></i> Aşamalar — <span id="as_kural_ad"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="as_kural_id">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted">
                        Aşamalar sırayla çalışır. Son aşama bitince kural tamamlanır ve sıfırlama türüne göre yeniden başlar.
                    </small>
                    <?php if ($permissions['can_add']): ?>
                    <button class="btn btn-sm btn-primary" onclick="asamaModalAc()"><i class="bi bi-plus-lg"></i> Yeni Aşama</button>
                    <?php endif; ?>
                </div>
                <table class="table table-bordered table-sm align-middle" id="tblAsamalar">
                    <thead>
                        <tr>
                            <th style="width:60px">Sıra</th>
                            <th>Aşama</th>
                            <th>Hedef</th>
                            <th>Periyot</th>
                            <th>Pencere</th>
                            <th>İlerletme</th>
                            <th style="width:110px">İşlem</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Aşama Modal -->
<div class="modal fade" id="modalAsama" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalAsamaBaslik">Yeni Aşama</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="a_id">
                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Sıra <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="a_sira" value="1" min="1">
                    </div>
                    <div class="col-md-10">
                        <label class="form-label">Aşama Adı <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="a_ad" placeholder="Hatırlatma / Tamamlama Bildirimi">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">WhatsApp Kanalı <span class="text-danger">*</span></label>
                        <select class="form-select" id="a_kanal"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Hedef No / Grup <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="a_hedef" placeholder="905xxxxxxxxx veya 1203...@g.us">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Mesaj <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="a_mesaj" rows="3"></textarea>
                        <small class="text-muted" id="a_degiskenler"></small>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Periyot (dk)</label>
                        <input type="number" class="form-control" id="a_periyot" value="60" min="0">
                        <small class="text-muted">0 = tek seferlik</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Başlangıç Saati</label>
                        <input type="time" class="form-control" id="a_baslangic">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Bitiş Saati</label>
                        <input type="time" class="form-control" id="a_bitis">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Max Deneme</label>
                        <input type="number" class="form-control" id="a_maxdeneme" placeholder="boş = sınırsız" min="1">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Hizalama</label>
                        <select class="form-select" id="a_hizalama"></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Anahtar Kelime <small class="text-muted">(WhatsApp yanıtı)</small></label>
                        <input type="text" class="form-control" id="a_anahtar" placeholder="TAMAM">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">İlerletme Tetikleyicileri</label>
                        <select class="form-select" id="a_tetikleyiciler" multiple></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Sonraki Aşama <small class="text-muted">(boş = sıradaki)</small></label>
                        <select class="form-select" id="a_sonraki"></select>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="a_ek_gonder">
                            <label class="form-check-label" for="a_ek_gonder">
                                Önceki aşamada gelen belgeyi de ilet
                                <small class="text-muted">(WhatsApp'tan düşen PDF, mesaj açıklama olarak eklenir)</small>
                            </label>
                        </div>
                    </div>

                    <div class="col-12">
                        <hr class="my-1">
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted fw-bold">Tamamlarken İstenecek Bilgiler</small>
                            <button type="button" class="btn btn-xs btn-outline-primary" onclick="alanEkle()">
                                <i class="bi bi-plus-lg"></i> Alan Ekle
                            </button>
                        </div>
                        <small class="text-muted d-block mb-2">
                            "Tamamla" butonuna basılınca bu alanlar sorulur; girilen değerler
                            <strong>sonraki aşamanın</strong> mesajında <code>{alan_adi}</code> olarak kullanılır.
                        </small>
                        <div id="a_istenen_alanlar"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" onclick="asamaKaydet()"><i class="bi bi-save"></i> Kaydet</button>
            </div>
        </div>
    </div>
</div>

<!-- Tamamla Modal (aşamanın istediği bilgiler) -->
<div class="modal fade" id="modalTamamla" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-check-lg"></i> Aşamayı Tamamla</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="t_kural_id">
                <p class="mb-3" id="t_aciklama"></p>
                <div class="row g-3" id="t_alanlar"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-success" onclick="tamamlaGonder()"><i class="bi bi-check-lg"></i> Tamamla</button>
            </div>
        </div>
    </div>
</div>

<!-- Geçmiş / Çıktı Modal -->
<div class="modal fade" id="modalGecmis" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalGecmisBaslik">Gönderim Geçmişi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalGecmisGovde"></div>
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
const pageUrl = '<?= $_SERVER['PHP_SELF'] ?>';
const permissions = {
    canAdd:    <?= $permissions['can_add']    ? 'true' : 'false' ?>,
    canEdit:   <?= $permissions['can_edit']   ? 'true' : 'false' ?>,
    canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
};

let dtKurallar = null;
let tanimlar   = { kosullar: [], sifirlamalar: [], hizalamalar: [], tetikleyiciler: [], gunler: [], waKanallar: [], voipKanallar: [] };
let kurallar   = [];
let asamalar   = [];
let filtreler  = {};

function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

$(document).ready(function () {
    tanimlariYukle(function () {
        kuralListele();
        istatistikYukle();
    });
});

function tanimlariYukle(sonra) {
    $.post(pageUrl, { action: 'tanim_listesi' }, function (r) {
        if (!r.success) { showToast('Tanımlar yüklenemedi', 'error'); return; }
        tanimlar = r;

        const $fk = $('#f_kosul'), $fs = $('#f_sifirlama');
        tanimlar.kosullar.forEach(k => $fk.append(`<option value="${k.Id}">${esc(k.Ad)}</option>`));
        tanimlar.sifirlamalar.forEach(s => $fs.append(`<option value="${s.Id}">${esc(s.Ad)}</option>`));

        if (sonra) sonra();
    }, 'json');
}

function istatistikYukle() {
    $.post(pageUrl, { action: 'istatistik' }, function (r) {
        if (!r.success) return;
        $('#stat-aktif').text(r.data.Aktif || 0);
        $('#stat-bekleyen').text(r.data.Bekleyen || 0);
        $('#stat-tamamlanan').text(r.data.BugunTamamlanan || 0);
        $('#stat-gonderim').text(r.data.BugunGonderim || 0);
    }, 'json');
}

// ─── Liste ───────────────────────────────────────────────────────────────────
function kuralListele() {
    $.post(pageUrl, { action: 'kural_listele' }, function (r) {
        if (!r.success) { showToast(r.message || 'Liste yüklenemedi', 'error'); return; }
        kurallar = r.data;
        tabloDoldur();
    }, 'json');
}

function tabloDoldur() {
    if (dtKurallar) { dtKurallar.destroy(); dtKurallar = null; }
    const $tb = $('#tblKurallar tbody').empty();

    kurallar.filter(kuralFiltreGecer).forEach(k => {
        const tamamlandi = !k.AktifAsamaId;
        const durumHtml  = k.Aktif != 1
            ? '<span class="badge text-bg-secondary">Pasif</span>'
            : (tamamlandi
                ? `<span class="badge text-bg-success">Tamamlandı</span>${k.SonTamamlama ? `<br><small class="text-muted">${esc(k.SonTamamlama)}</small>` : ''}`
                : '<span class="badge text-bg-warning">Bekliyor</span>');

        const asamaHtml = k.AktifAsamaId
            ? `<strong>#${k.AktifAsamaSira} ${esc(k.AktifAsamaAd)}</strong>
               <br><small class="text-muted">${esc(k.AktifHedefNo)}</small>
               <br><small class="text-muted">Deneme: ${k.DenemeSayisi}${k.AktifMaxDeneme ? ' / ' + k.AktifMaxDeneme : ''}
               ${k.AktifBaslangic || k.AktifBitis ? ' · ' + esc(k.AktifBaslangic || '') + '–' + esc(k.AktifBitis || '') : ''}</small>`
            : `<span class="text-muted">—</span>`;

        let islem = '';
        if (permissions.canEdit && k.Aktif == 1 && k.AktifAsamaId) {
            islem += `<button class="btn btn-xs btn-success me-1" onclick="asamaIlerlet(${k.Id})" title="Aşamayı tamamla"><i class="bi bi-check-lg"></i> Tamamla</button>`;
            islem += `<button class="btn btn-xs btn-outline-info me-1" onclick="simdiGonder(${k.Id})" title="Periyot beklemeden gönder"><i class="bi bi-send"></i></button>`;
        }
        if (permissions.canEdit) {
            islem += `<button class="btn btn-xs btn-outline-secondary me-1" onclick="testGonder(${k.Id})" title="Dry run"><i class="bi bi-bug"></i></button>`;
        }
        islem += `<button class="btn btn-xs btn-outline-dark me-1" onclick="asamalarAc(${k.Id})" title="Aşamalar"><i class="bi bi-diagram-3"></i> ${k.AsamaSayisi}</button>`;
        islem += `<button class="btn btn-xs btn-outline-primary me-1" onclick="gecmisAc(${k.Id})" title="Geçmiş"><i class="bi bi-clock-history"></i></button>`;
        if (permissions.canEdit) islem += `<button class="btn btn-xs btn-outline-primary me-1" onclick="kuralDuzenle(${k.Id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
        if (permissions.canDelete) islem += `<button class="btn btn-xs btn-outline-danger" onclick="kuralSil(${k.Id})" title="Sil"><i class="bi bi-trash"></i></button>`;

        $tb.append(`<tr>
            <td><strong>${esc(k.Ad)}</strong>${k.Aciklama ? `<br><small class="text-muted">${esc(k.Aciklama)}</small>` : ''}</td>
            <td><small>${esc(k.KosulAd)}</small></td>
            <td><small>${esc(k.SifirlamaAd)}</small></td>
            <td><small>${k.Gunler ? esc(k.Gunler) : 'Her gün'}</small></td>
            <td>${asamaHtml}</td>
            <td><small>${esc(k.SonGonderim || '—')}</small></td>
            <td>${durumHtml}</td>
            <td>${islem}</td>
        </tr>`);
    });

    dtKurallar = $('#tblKurallar').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
        dom: 'lrtip',
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']],
        order: [[0, 'asc']],
        columnDefs: [{ orderable: false, targets: [7] }],
        destroy: true,
    });
}

function kuralFiltreGecer(k) {
    if (filtreler.arama) {
        const t = (k.Ad + ' ' + (k.Aciklama || '')).toLowerCase();
        if (t.indexOf(filtreler.arama.toLowerCase()) === -1) return false;
    }
    if (filtreler.kosul && k.KosulTuruId != filtreler.kosul) return false;
    if (filtreler.sifirlama && k.SifirlamaTuruId != filtreler.sifirlama) return false;
    if (filtreler.durum === 'bekleyen'   && !(k.Aktif == 1 && k.AktifAsamaId)) return false;
    if (filtreler.durum === 'tamamlanan' && !(k.Aktif == 1 && !k.AktifAsamaId)) return false;
    if (filtreler.durum === 'pasif'      && k.Aktif == 1) return false;
    return true;
}

function filtreUygula() {
    filtreler = {
        arama:     $('#f_arama').val().trim(),
        kosul:     $('#f_kosul').val(),
        sifirlama: $('#f_sifirlama').val(),
        durum:     $('#f_durum').val(),
    };
    tabloDoldur();
    showToast('Filtre uygulandı', 'info');
}

function filtreTemizle() {
    $('#f_arama').val('');
    $('#f_kosul, #f_sifirlama, #f_durum').val('').trigger('change');
    filtreler = {};
    tabloDoldur();
    showToast('Filtreler temizlendi', 'info');
}

// ─── Kural modalı ────────────────────────────────────────────────────────────
function kuralModalAc(k = null) {
    $('#modalKuralBaslik').text(k ? 'Kural Düzenle' : 'Yeni Kural');
    $('#k_id').val(k ? k.Id : '');
    $('#k_ad').val(k ? k.Ad : '');
    $('#k_aciklama').val(k ? (k.Aciklama || '') : '');

    const $kosul = $('#k_kosul').empty();
    tanimlar.kosullar.forEach(x => $kosul.append(`<option value="${x.Id}">${esc(x.Ad)}</option>`));
    const $sif = $('#k_sifirlama').empty();
    tanimlar.sifirlamalar.forEach(x => $sif.append(`<option value="${x.Id}">${esc(x.Ad)}</option>`));
    const $gun = $('#k_gunler').empty();
    tanimlar.gunler.forEach(x => $gun.append(`<option value="${x.Id}">${esc(x.Ad)}</option>`));

    if (k) {
        $kosul.val(k.KosulTuruId);
        $sif.val(k.SifirlamaTuruId);
        $gun.val((k.Gunleri || []).map(String));
    } else {
        $gun.val([]);
    }
    $('#k_aktif').prop('checked', k ? k.Aktif == 1 : true);

    // Modal select2'leri: custom.js zaten init etmiş olabilir → destroy + dropdownParent
    ['#k_kosul', '#k_sifirlama', '#k_gunler'].forEach(sel => {
        if ($(sel).hasClass('select2-hidden-accessible')) $(sel).select2('destroy');
        $(sel).select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#modalKural') });
    });

    kosulDegisti(k ? JSON.parse(k.KosulParametre || '{}') : {});
    $('#k_kosul').off('change.kosul').on('change.kosul', () => kosulDegisti({}));
    $('#k_sifirlama').off('change.sif').on('change.sif', sifirlamaAciklamaGuncelle);
    sifirlamaAciklamaGuncelle();

    new bootstrap.Modal('#modalKural').show();
}

function sifirlamaAciklamaGuncelle() {
    const s = tanimlar.sifirlamalar.find(x => x.Id == $('#k_sifirlama').val());
    $('#k_sifirlama_aciklama').text(s ? (s.Aciklama || '') : '');
}

/** Koşul türünün parametre şemasından formu üretir (hiçbir alan koda gömülü değil). */
function kosulDegisti(mevcut) {
    const k = tanimlar.kosullar.find(x => x.Id == $('#k_kosul').val());
    $('#k_kosul_aciklama').text(k ? (k.Aciklama || '') : '');

    const $kutu = $('#k_kosul_param').empty();
    let sema = [];
    try { sema = JSON.parse((k && k.Semasi) || '[]') || []; } catch (e) { sema = []; }

    if (!sema.length) { $('#k_kosul_param_kutu').hide(); return; }
    $('#k_kosul_param_kutu').show();

    sema.forEach(alan => {
        const deger = mevcut && mevcut[alan.ad] !== undefined ? mevcut[alan.ad] : '';
        const zorunlu = alan.zorunlu ? ' <span class="text-danger">*</span>' : '';
        let girdi;

        if (alan.tip === 'kanal') {
            const opts = tanimlar.voipKanallar.map(v =>
                `<option value="${v.Id}" ${v.Id == deger ? 'selected' : ''}>${esc(v.Entegrasyon)} — ${esc(v.Ad)}</option>`).join('');
            girdi = `<select class="form-control kosul-param" data-ad="${esc(alan.ad)}"><option value="">Seçiniz</option>${opts}</select>`;
        } else if (alan.tip === 'sayi') {
            girdi = `<input type="number" step="any" class="form-control kosul-param" data-ad="${esc(alan.ad)}" value="${esc(deger)}">`;
        } else {
            girdi = `<input type="text" class="form-control kosul-param" data-ad="${esc(alan.ad)}" value="${esc(deger)}">`;
        }

        $kutu.append(`<div class="col-md-4">
            <label class="form-label">${esc(alan.etiket || alan.ad)}${zorunlu}</label>
            ${girdi}
        </div>`);
    });
}

function kuralKaydet() {
    const param = {};
    let eksik = false;
    $('#k_kosul_param .kosul-param').each(function () {
        const v = $(this).val();
        if (v !== '' && v !== null) param[$(this).data('ad')] = v;
    });

    const kosulTuru = tanimlar.kosullar.find(x => x.Id == $('#k_kosul').val());
    try {
        (JSON.parse((kosulTuru && kosulTuru.Semasi) || '[]') || []).forEach(a => {
            if (a.zorunlu && (param[a.ad] === undefined || param[a.ad] === '')) eksik = true;
        });
    } catch (e) {}

    if (!$('#k_ad').val().trim()) { showToast('Kural adı zorunludur', 'warning'); return; }
    if (eksik) { showToast('Zorunlu koşul parametrelerini doldurun', 'warning'); return; }

    $.post(pageUrl, {
        action: 'kural_kaydet',
        id: $('#k_id').val(),
        ad: $('#k_ad').val().trim(),
        aciklama: $('#k_aciklama').val().trim(),
        kosul_turu_id: $('#k_kosul').val(),
        sifirlama_turu_id: $('#k_sifirlama').val(),
        kosul_parametre: JSON.stringify(param),
        gunler: $('#k_gunler').val() || [],
        aktif: $('#k_aktif').is(':checked') ? 1 : 0,
    }, function (r) {
        if (!r.success) { showToast(r.message, 'error'); return; }
        bootstrap.Modal.getInstance('#modalKural').hide();
        showToast(r.message, 'success');
        kuralListele();
        istatistikYukle();
    }, 'json');
}

function kuralDuzenle(id) {
    const k = kurallar.find(x => x.Id == id);
    if (k) kuralModalAc(k);
}

function kuralSil(id) {
    Swal.fire({
        title: 'Kural pasife alınsın mı?', icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Evet', cancelButtonText: 'İptal', confirmButtonColor: '#d33',
    }).then(res => {
        if (!res.isConfirmed) return;
        $.post(pageUrl, { action: 'kural_sil', id }, function (r) {
            showToast(r.message, r.success ? 'success' : 'error');
            kuralListele(); istatistikYukle();
        }, 'json');
    });
}

// ─── Aşamalar ────────────────────────────────────────────────────────────────
function asamalarAc(kuralId) {
    const k = kurallar.find(x => x.Id == kuralId);
    $('#as_kural_id').val(kuralId);
    $('#as_kural_ad').text(k ? k.Ad : '');
    asamaListele();
    new bootstrap.Modal('#modalAsamalar').show();
}

function asamaListele() {
    $.post(pageUrl, { action: 'asama_listele', kural_id: $('#as_kural_id').val() }, function (r) {
        if (!r.success) { showToast(r.message || 'Aşamalar yüklenemedi', 'error'); return; }
        asamalar = r.data;
        const $tb = $('#tblAsamalar tbody').empty();

        if (!asamalar.length) {
            $tb.append('<tr><td colspan="7" class="text-center text-muted py-3">Henüz aşama yok. Kural çalışmaz.</td></tr>');
            return;
        }

        asamalar.forEach(a => {
            const periyot = a.PeriyotDakika > 0 ? a.PeriyotDakika + ' dk' : '<span class="badge text-bg-info">Tek seferlik</span>';
            const pencere = (a.BaslangicSaati || a.BitisSaati)
                ? esc(a.BaslangicSaati || '00:00') + '–' + esc(a.BitisSaati || '23:59')
                : '<span class="text-muted">7/24</span>';
            const tetik = (a.Tetikleyiciler || []).map(t => `<span class="badge text-bg-light border me-1">${esc(t.Ad)}</span>`).join('') || '<span class="text-muted">—</span>';

            let islem = '';
            if (permissions.canEdit)   islem += `<button class="btn btn-xs btn-outline-primary me-1" onclick="asamaDuzenle(${a.Id})"><i class="bi bi-pencil"></i></button>`;
            if (permissions.canDelete) islem += `<button class="btn btn-xs btn-outline-danger" onclick="asamaSil(${a.Id})"><i class="bi bi-trash"></i></button>`;

            $tb.append(`<tr>
                <td class="text-center">${a.SiraNo}</td>
                <td><strong>${esc(a.Ad)}</strong>${a.AnahtarKelime ? `<br><small class="text-muted">Anahtar: ${esc(a.AnahtarKelime)}</small>` : ''}</td>
                <td><small>${esc(a.HedefNo)}<br><span class="text-muted">${esc(a.KanalAd || '')}</span></small></td>
                <td><small>${periyot}${a.MaxDeneme ? `<br>max ${a.MaxDeneme}` : ''}</small></td>
                <td><small>${pencere}</small></td>
                <td><small>${tetik}</small></td>
                <td>${islem}</td>
            </tr>`);
        });
    }, 'json');
}

function asamaModalAc(a = null) {
    $('#modalAsamaBaslik').text(a ? 'Aşama Düzenle' : 'Yeni Aşama');
    $('#a_id').val(a ? a.Id : '');
    $('#a_sira').val(a ? a.SiraNo : (asamalar.length + 1));
    $('#a_ad').val(a ? a.Ad : '');
    $('#a_hedef').val(a ? a.HedefNo : '');
    $('#a_mesaj').val(a ? a.Mesaj : '');
    $('#a_periyot').val(a ? a.PeriyotDakika : 60);
    $('#a_baslangic').val(a ? (a.BaslangicSaati || '') : '');
    $('#a_bitis').val(a ? (a.BitisSaati || '') : '');
    $('#a_maxdeneme').val(a && a.MaxDeneme ? a.MaxDeneme : '');
    $('#a_anahtar').val(a && a.AnahtarKelime ? a.AnahtarKelime : '');
    $('#a_ek_gonder').prop('checked', !!(a && a.EkGonder == 1));

    const $kanal = $('#a_kanal').empty();
    tanimlar.waKanallar.forEach(k => $kanal.append(`<option value="${k.Id}">${esc(k.Entegrasyon)} — ${esc(k.Ad)}</option>`));
    const $hiz = $('#a_hizalama').empty().append('<option value="">Başlangıçtan itibaren (varsayılan)</option>');
    tanimlar.hizalamalar.forEach(h => $hiz.append(`<option value="${h.Id}">${esc(h.Ad)}</option>`));
    const $tet = $('#a_tetikleyiciler').empty();
    tanimlar.tetikleyiciler.forEach(t => $tet.append(`<option value="${t.Id}">${esc(t.Ad)}</option>`));
    const $son = $('#a_sonraki').empty().append('<option value="">Sıradaki aşama</option>');
    asamalar.filter(x => !a || x.Id != a.Id).forEach(x => $son.append(`<option value="${x.Id}">#${x.SiraNo} ${esc(x.Ad)}</option>`));

    if (a) {
        $kanal.val(a.KanalId);
        $hiz.val(a.HizalamaTuruId || '');
        $tet.val((a.Tetikleyiciler || []).map(t => String(t.Id)));
        $son.val(a.SonrakiAsamaId || '');
    } else {
        $tet.val([]);
    }

    // Tamamlarken istenecek bilgiler
    $('#a_istenen_alanlar').empty();
    if (a && a.IstenenAlanlar) {
        try { (JSON.parse(a.IstenenAlanlar) || []).forEach(alanEkle); } catch (e) {}
    }

    // Kuralın koşul değişkenlerini ipucu olarak göster
    const kural = kurallar.find(x => x.Id == $('#as_kural_id').val());
    const kosul = kural ? tanimlar.kosullar.find(x => x.Id == kural.KosulTuruId) : null;
    let kosulDeg = [];
    try { kosulDeg = JSON.parse((kosul && kosul.Degiskenler) || '[]') || []; } catch (e) {}
    const genel = ['kural', 'asama', 'hedef', 'sira', 'deneme', 'tarih', 'saat'];

    // Önceki aşamalarda toplanan bilgiler de bu aşamanın mesajında kullanılabilir
    const oncekiAlanlar = [];
    asamalar.filter(x => !a || x.SiraNo < a.SiraNo).forEach(x => {
        try { (JSON.parse(x.IstenenAlanlar || '[]') || []).forEach(al => { if (al.ad) oncekiAlanlar.push(al.ad); }); } catch (e) {}
    });

    $('#a_degiskenler').html('Kullanılabilir: ' +
        genel.concat(kosulDeg, oncekiAlanlar).map(d => `<code>{${esc(d)}}</code>`).join(' '));

    ['#a_kanal', '#a_hizalama', '#a_tetikleyiciler', '#a_sonraki'].forEach(sel => {
        if ($(sel).hasClass('select2-hidden-accessible')) $(sel).select2('destroy');
        $(sel).select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#modalAsama') });
    });

    new bootstrap.Modal('#modalAsama').show();
}

function asamaDuzenle(id) {
    const a = asamalar.find(x => x.Id == id);
    if (a) asamaModalAc(a);
}

// ─── "Tamamlarken istenecek bilgiler" kurgusu ────────────────────────────────
function alanEkle(alan) {
    const a = alan || { ad: '', etiket: '', tip: 'text', zorunlu: false };
    const tipler = [['text', 'Metin'], ['sayi', 'Sayı'], ['tarih', 'Tarih'], ['tarihsaat', 'Tarih + Saat']];
    const opts = tipler.map(([v, l]) => `<option value="${v}" ${a.tip === v ? 'selected' : ''}>${l}</option>`).join('');

    $('#a_istenen_alanlar').append(`
        <div class="row g-2 mb-2 align-items-end istenen-alan">
            <div class="col-md-3">
                <input type="text" class="form-control form-control-sm alan-ad" placeholder="tutar" value="${esc(a.ad)}">
                <small class="text-muted">yer tutucu adı</small>
            </div>
            <div class="col-md-4">
                <input type="text" class="form-control form-control-sm alan-etiket" placeholder="Ödeme Tutarı" value="${esc(a.etiket)}">
            </div>
            <div class="col-md-3">
                <select class="form-control form-control-sm alan-tip">${opts}</select>
            </div>
            <div class="col-md-1">
                <div class="form-check form-switch" title="Zorunlu">
                    <input class="form-check-input alan-zorunlu" type="checkbox" role="switch" ${a.zorunlu ? 'checked' : ''}>
                </div>
            </div>
            <div class="col-md-1 text-end">
                <button type="button" class="btn btn-xs btn-outline-danger" onclick="$(this).closest('.istenen-alan').remove()">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>`);
}

function istenenAlanlariTopla() {
    const liste = [];
    $('#a_istenen_alanlar .istenen-alan').each(function () {
        const ad = $(this).find('.alan-ad').val().trim();
        if (!ad) return;
        liste.push({
            ad:      ad,
            etiket:  $(this).find('.alan-etiket').val().trim() || ad,
            tip:     $(this).find('.alan-tip').val(),
            zorunlu: $(this).find('.alan-zorunlu').is(':checked'),
        });
    });
    return liste;
}

function asamaKaydet() {
    if (!$('#a_ad').val().trim() || !$('#a_hedef').val().trim() || !$('#a_mesaj').val().trim()) {
        showToast('Ad, hedef ve mesaj zorunludur', 'warning'); return;
    }
    $.post(pageUrl, {
        action: 'asama_kaydet',
        id: $('#a_id').val(),
        kural_id: $('#as_kural_id').val(),
        sira_no: $('#a_sira').val(),
        ad: $('#a_ad').val().trim(),
        kanal_id: $('#a_kanal').val(),
        hedef_no: $('#a_hedef').val().trim(),
        mesaj: $('#a_mesaj').val(),
        periyot_dakika: $('#a_periyot').val() || 0,
        baslangic_saati: $('#a_baslangic').val(),
        bitis_saati: $('#a_bitis').val(),
        hizalama_turu_id: $('#a_hizalama').val() || 0,
        max_deneme: $('#a_maxdeneme').val(),
        anahtar_kelime: $('#a_anahtar').val().trim(),
        sonraki_asama_id: $('#a_sonraki').val() || 0,
        tetikleyiciler: $('#a_tetikleyiciler').val() || [],
        istenen_alanlar: JSON.stringify(istenenAlanlariTopla()),
        ek_gonder: $('#a_ek_gonder').is(':checked') ? 1 : 0,
    }, function (r) {
        if (!r.success) { showToast(r.message, 'error'); return; }
        bootstrap.Modal.getInstance('#modalAsama').hide();
        showToast(r.message, 'success');
        asamaListele(); kuralListele();
    }, 'json');
}

function asamaSil(id) {
    Swal.fire({
        title: 'Aşama pasife alınsın mı?', icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Evet', cancelButtonText: 'İptal', confirmButtonColor: '#d33',
    }).then(res => {
        if (!res.isConfirmed) return;
        $.post(pageUrl, { action: 'asama_sil', id }, function (r) {
            showToast(r.message, r.success ? 'success' : 'error');
            asamaListele(); kuralListele();
        }, 'json');
    });
}

// ─── İşlemler ────────────────────────────────────────────────────────────────
function asamaIlerlet(id) {
    const k = kurallar.find(x => x.Id == id);
    if (!k) return;

    let alanlar = [];
    try { alanlar = JSON.parse(k.AktifIstenenAlanlar || '[]') || []; } catch (e) {}

    // Aşama bilgi istemiyorsa doğrudan onay yeter
    if (!alanlar.length) {
        Swal.fire({
            title: 'Aşama tamamlansın mı?',
            html: `<strong>${esc(k.AktifAsamaAd)}</strong> tamamlanacak.<br>
                   <small class="text-muted">Sıradaki aşama varsa ona geçilir, yoksa kural tamamlanır.</small>`,
            icon: 'question', showCancelButton: true, confirmButtonText: 'Evet, tamamla', cancelButtonText: 'İptal',
        }).then(res => { if (res.isConfirmed) tamamlaIstek(id, {}); });
        return;
    }

    // Bilgi isteniyorsa modal aç
    $('#t_kural_id').val(id);
    $('#t_aciklama').html(`<strong>${esc(k.AktifAsamaAd)}</strong> tamamlanacak.
        Aşağıdaki bilgiler sonraki aşamanın mesajında kullanılacak.`);

    const $kutu = $('#t_alanlar').empty();
    alanlar.forEach(a => {
        const tipMap = { sayi: 'number', tarih: 'date', tarihsaat: 'datetime-local' };
        const tip = tipMap[a.tip] || 'text';
        const adim = a.tip === 'sayi' ? ' step="any"' : '';
        $kutu.append(`<div class="col-12">
            <label class="form-label">${esc(a.etiket || a.ad)}${a.zorunlu ? ' <span class="text-danger">*</span>' : ''}</label>
            <input type="${tip}"${adim} class="form-control tamamla-alan" data-ad="${esc(a.ad)}" data-tip="${esc(a.tip)}">
        </div>`);
    });

    new bootstrap.Modal('#modalTamamla').show();
}

function tamamlaGonder() {
    const veri = {};
    let eksik = false;

    $('#t_alanlar .tamamla-alan').each(function () {
        let v = ($(this).val() || '').trim();
        if (v && $(this).data('tip') === 'tarihsaat') v = v.replace('T', ' ') + ':00';
        if (v) veri[$(this).data('ad')] = v;
    });

    // Zorunlu kontrolü sunucuda da yapılıyor; burada erken uyarı
    $('#t_alanlar .form-label .text-danger').each(function () {
        const $inp = $(this).closest('.col-12').find('.tamamla-alan');
        if (!($inp.val() || '').trim()) eksik = true;
    });
    if (eksik) { showToast('Zorunlu alanları doldurun', 'warning'); return; }

    tamamlaIstek($('#t_kural_id').val(), veri, true);
}

function tamamlaIstek(id, veri, modalKapat) {
    $.post(pageUrl, { action: 'asama_ilerlet', id: id, veri: veri }, function (r) {
        if (r.success && modalKapat) bootstrap.Modal.getInstance('#modalTamamla').hide();
        showToast(r.message, r.success ? 'success' : 'warning');
        kuralListele(); istatistikYukle();
    }, 'json');
}

function simdiGonder(id) {
    Swal.fire({
        title: 'Şimdi gönderilsin mi?',
        text: 'Periyot, saat penceresi ve deneme sınırı atlanarak mesaj gönderilir.',
        icon: 'question', showCancelButton: true, confirmButtonText: 'Gönder', cancelButtonText: 'İptal',
    }).then(res => {
        if (!res.isConfirmed) return;
        $.post(pageUrl, { action: 'simdi_gonder', id }, function (r) {
            ciktiGoster('Gönderim Sonucu', r);
            kuralListele(); istatistikYukle();
        }, 'json');
    });
}

function testGonder(id) {
    $.post(pageUrl, { action: 'test_gonder', id }, function (r) {
        ciktiGoster('Dry Run — gönderim yapılmadı', r);
    }, 'json');
}

function ciktiGoster(baslik, r) {
    $('#modalGecmisBaslik').text(baslik);
    $('#modalGecmisGovde').html(`
        <div class="alert ${r.success ? 'alert-success' : 'alert-danger'} py-2">${esc(r.message)}</div>
        <pre class="bg-dark text-light p-3 rounded" style="max-height:420px;overflow:auto">${esc(r.cikti || '')}</pre>
    `);
    new bootstrap.Modal('#modalGecmis').show();
}

function gecmisAc(id) {
    $.post(pageUrl, { action: 'gecmis', id }, function (r) {
        $('#modalGecmisBaslik').text('Gönderim Geçmişi (son 100)');
        if (!r.success) { $('#modalGecmisGovde').html(`<div class="alert alert-danger">${esc(r.message)}</div>`); }
        else if (!r.data.length) { $('#modalGecmisGovde').html('<div class="text-muted text-center py-3">Kayıt yok.</div>'); }
        else {
            let html = `<table class="table table-sm table-bordered align-middle">
                <thead><tr><th style="width:150px">Tarih</th><th style="width:200px">Alıcı</th><th>Mesaj</th><th style="width:90px">Durum</th></tr></thead><tbody>`;
            r.data.forEach(l => {
                const ok = l.GonderimDurumu === 'basarili';
                html += `<tr>
                    <td><small>${esc(l.Tarih)}</small></td>
                    <td><small>${esc(l.Alici)}</small></td>
                    <td><small>${esc((l.Mesaj || '').substring(0, 300))}</small>
                        ${l.HataMesaj ? `<br><small class="text-danger">${esc(l.HataMesaj)}</small>` : ''}</td>
                    <td><span class="badge ${ok ? 'text-bg-success' : 'text-bg-danger'}">${ok ? 'Başarılı' : 'Hata'}</span></td>
                </tr>`;
            });
            $('#modalGecmisGovde').html(html + '</tbody></table>');
        }
        new bootstrap.Modal('#modalGecmis').show();
    }, 'json');
}
</script>

</body>
</html>
