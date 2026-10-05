<?php
/**
 * Admin Panel - Birim Yönetimi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
requireAuth();

$user = Auth::user();
$db = Database::getInstance();

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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Birim Yönetimi';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // ================================================================
            // BİRİM
            // ================================================================
            case 'list':
                $search     = $_POST['search']      ?? '';
                $status     = $_POST['status']      ?? '';
                $ustBirimId = $_POST['ust_birim_id'] ?? '';

                $whereConditions = ["1=1"];
                $params = [];

                if ($search) {
                    $whereConditions[] = "(b.KullaniciBirim_Adi LIKE ? OR b.KullaniciBirim_Kodu LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($status !== '') {
                    $whereConditions[] = "b.Durum = ?";
                    $params[] = $status;
                }
                $kullaniciVar = $_POST['kullanici_var'] ?? '';
                if ($kullaniciVar === '1') {
                    $whereConditions[] = "(SELECT COUNT(*) FROM kullanicilar k WHERE k.kullanici_birim_id = b.KullaniciBirim_id AND k.kullanici_durum = 1) > 0";
                } elseif ($kullaniciVar === '0') {
                    $whereConditions[] = "(SELECT COUNT(*) FROM kullanicilar k WHERE k.kullanici_birim_id = b.KullaniciBirim_id AND k.kullanici_durum = 1) = 0";
                }
                if ($ustBirimId !== '') {
                    if ($ustBirimId === '0') {
                        $whereConditions[] = "b.KullaniciBirim_UstBirim_id IS NULL";
                    } else {
                        $whereConditions[] = "b.KullaniciBirim_UstBirim_id = ?";
                        $params[] = $ustBirimId;
                    }
                }

                $whereClause = implode(" AND ", $whereConditions);
                $birimList = $db->fetchAll("
                    SELECT
                        b.KullaniciBirim_id,
                        b.KullaniciBirim_Adi,
                        b.KullaniciBirim_Kodu,
                        b.KullaniciBirim_Aciklama,
                        b.KullaniciBirim_Renk,
                        b.KullaniciBirim_UstBirim_id,
                        ub.KullaniciBirim_Adi as UstBirim_Adi,
                        b.Durum,
                        CONVERT(VARCHAR(19), b.OlusturmaTarihi, 120) as OlusturmaTarihi,
                        ko.kullanici_ad + ' ' + ko.kullanici_soyad as OlusturanAd,
                        (SELECT COUNT(*) FROM kullanicilar k WHERE k.kullanici_birim_id = b.KullaniciBirim_id AND k.kullanici_durum = 1) as KullaniciSayisi
                    FROM KullaniciBirim b
                    LEFT JOIN KullaniciBirim ub ON b.KullaniciBirim_UstBirim_id = ub.KullaniciBirim_id
                    LEFT JOIN kullanicilar ko ON b.OlusturanKullanici = ko.kullanici_id
                    WHERE $whereClause
                    ORDER BY ub.KullaniciBirim_Adi, b.KullaniciBirim_Adi
                ", $params);

                echo json_encode(['success' => true, 'data' => $birimList]);
                break;

            case 'get':
                $birimId = (int)($_POST['birim_id'] ?? 0);
                $birim = $db->fetchOne("SELECT * FROM KullaniciBirim WHERE KullaniciBirim_id = ?", [$birimId]);
                echo json_encode(['success' => true, 'data' => $birim]);
                break;

            case 'get_all_for_select':
                $excludeId = (int)($_POST['exclude_id'] ?? 0);
                $params = [];
                $excludeWhere = '';
                if ($excludeId > 0) {
                    $excludeWhere = 'AND KullaniciBirim_id != ?';
                    $params[] = $excludeId;
                }
                $list = $db->fetchAll("
                    SELECT KullaniciBirim_id, KullaniciBirim_Adi, KullaniciBirim_UstBirim_id
                    FROM KullaniciBirim
                    WHERE Durum = 1 $excludeWhere
                    ORDER BY KullaniciBirim_Adi
                ", $params);
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'save':
                $birimId = (int)($_POST['birim_id'] ?? 0);
                if ($birimId > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']); break;
                }
                if ($birimId == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']); break;
                }

                $ustBirimId = $_POST['KullaniciBirim_UstBirim_id'] ?? null;
                $ustBirimId = ($ustBirimId !== '' && $ustBirimId !== null) ? (int)$ustBirimId : null;

                if ($birimId > 0 && $ustBirimId === $birimId) {
                    echo json_encode(['success' => false, 'message' => 'Bir birim kendisini üst birim olarak seçemez!']); break;
                }

                $data = [
                    'KullaniciBirim_Adi'         => $_POST['KullaniciBirim_Adi'] ?? '',
                    'KullaniciBirim_Kodu'        => $_POST['KullaniciBirim_Kodu'] ?: null,
                    'KullaniciBirim_Aciklama'    => $_POST['KullaniciBirim_Aciklama'] ?: null,
                    'KullaniciBirim_Renk'        => preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['KullaniciBirim_Renk'] ?? '') ? $_POST['KullaniciBirim_Renk'] : '#6c757d',
                    'KullaniciBirim_UstBirim_id' => $ustBirimId,
                    'Durum'                      => isset($_POST['Durum']) ? 1 : 0,
                ];

                if ($birimId > 0) {
                    $data['GuncellemeTarihi']    = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $result = $db->update('KullaniciBirim', $data, ['KullaniciBirim_id' => $birimId]);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Birim başarıyla güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['OlusturmaTarihi']     = date('Y-m-d H:i:s');
                    $data['OlusturanKullanici']  = $user['kullanici_id'];
                    $data['GuncellemeTarihi']    = date('Y-m-d H:i:s');
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $result = $db->insert('KullaniciBirim', $data);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Birim başarıyla eklendi' : 'Ekleme hatası']);
                }
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']); break;
                }

                $birimId = (int)($_POST['birim_id'] ?? 0);
                $checks = [];

                $altBirimSayisi = $db->fetchOne(
                    "SELECT COUNT(*) as cnt FROM KullaniciBirim WHERE KullaniciBirim_UstBirim_id = ?",
                    [$birimId]
                )['cnt'] ?? 0;
                if ($altBirimSayisi > 0) $checks[] = "$altBirimSayisi alt birim";

                $yetkiSayisi = $db->fetchOne(
                    "SELECT COUNT(*) as cnt FROM KullaniciBirimYetkileri WHERE KullaniciBirimYetkileri_Birim_id = ?",
                    [$birimId]
                )['cnt'] ?? 0;
                if ($yetkiSayisi > 0) $checks[] = "$yetkiSayisi yetki kaydı";

                $kullaniciSayisi = $db->fetchOne(
                    "SELECT COUNT(*) as cnt FROM kullanicilar WHERE kullanici_birim_id = ?",
                    [$birimId]
                )['cnt'] ?? 0;
                if ($kullaniciSayisi > 0) $checks[] = "$kullaniciSayisi kullanıcı";

                if (!empty($checks)) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Bu birim silinemez! Şu kayıtlarda kullanılıyor: ' . implode(', ', $checks)
                    ]);
                    break;
                }

                $result = $db->delete('KullaniciBirim', ['KullaniciBirim_id' => $birimId]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Birim başarıyla silindi' : 'Silme hatası']);
                break;

            case 'stats':
                $stats = [
                    'toplam'    => $db->fetchOne("SELECT COUNT(*) as cnt FROM KullaniciBirim")['cnt'] ?? 0,
                    'aktif'     => $db->fetchOne("SELECT COUNT(*) as cnt FROM KullaniciBirim WHERE Durum = 1")['cnt'] ?? 0,
                    'pasif'     => $db->fetchOne("SELECT COUNT(*) as cnt FROM KullaniciBirim WHERE Durum = 0")['cnt'] ?? 0,
                    'ana_birim' => $db->fetchOne("SELECT COUNT(*) as cnt FROM KullaniciBirim WHERE KullaniciBirim_UstBirim_id IS NULL")['cnt'] ?? 0,
                    'yetki'     => $db->fetchOne("SELECT COUNT(*) as cnt FROM KullaniciBirimYetkileri WHERE Durum = 1")['cnt'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            // ================================================================
            // YETKİ
            // ================================================================
            case 'get_alt_bayiler':
                $list = $db->fetchAll("
                    SELECT a.DigiturkAltBayiler_Id as id,
                           a.DigiturkAltBayiler_Ad + ' (' + n.DigiturkAnaBayiler_Ad + ')' as ad
                    FROM DigiturkAltBayiler a
                    LEFT JOIN DigiturkAnaBayiler n ON a.DigiturkAltBayiler_AnaBayiId = n.DigiturkAnaBayiler_Id
                    WHERE a.Durum = 1
                    ORDER BY a.DigiturkAltBayiler_Ad
                ", []);
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get_personel':
                $list = $db->fetchAll("
                    SELECT p.DigiturkAltBayiPersonel_Id as id,
                           p.DigiturkAltBayiPersonel_AdSoyad + ' (' + a.DigiturkAltBayiler_Ad + ')' as ad
                    FROM DigiturkAltBayiPersonel p
                    LEFT JOIN DigiturkAltBayiler a ON p.DigiturkAltBayiPersonel_AltBayiId = a.DigiturkAltBayiler_Id
                    WHERE p.Durum = 1
                    ORDER BY p.DigiturkAltBayiPersonel_AdSoyad
                ", []);
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get_voip_hesaplar':
                $excludeYetkiId = (int)($_POST['exclude_yetki_id'] ?? 0);
                $list = $db->fetchAll("
                    SELECT v.VoIPHesaplar_id as id,
                           v.VoIPHesaplar_TelefonNo + ISNULL(' — ' + v.VoIPHesaplar_Aciklama, '') as ad,
                           v.VoIPHesaplar_TelefonNo
                    FROM VoIPHesaplar v
                    WHERE v.Durum = 1
                      AND v.VoIPHesaplar_HesapDurum = 'aktif'
                      AND NOT EXISTS (
                          SELECT 1 FROM KullaniciBirimYetkileri k
                          WHERE k.KullaniciBirimYetkileri_VoIPHesap_id = v.VoIPHesaplar_id
                            AND k.Durum = 1
                            AND (? = 0 OR k.KullaniciBirimYetkileri_id != ?)
                      )
                    ORDER BY v.VoIPHesaplar_TelefonNo
                ", [$excludeYetkiId, $excludeYetkiId]);
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get_reklam_hesaplar':
                $list = $db->fetchAll("
                    SELECT r.ReklamHesaplari_id as id,
                           r.ReklamHesaplari_HesapAdi + ISNULL(' (' + pl.ReklamPlatformlari_Adi + ')', '') as ad
                    FROM ReklamHesaplari r
                    LEFT JOIN ReklamPlatformlari pl ON r.ReklamHesaplari_Platform_id = pl.ReklamPlatformlari_id
                    WHERE r.Durum = 1
                    ORDER BY r.ReklamHesaplari_HesapAdi
                ", []);
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get_reklam_kampanyalar':
                $list = $db->fetchAll("
                    SELECT k.ReklamKampanyalari_id as id,
                           k.ReklamKampanyalari_KampanyaAdi + ISNULL(' — ' + r.ReklamHesaplari_HesapAdi, '') as ad
                    FROM ReklamKampanyalari k
                    LEFT JOIN ReklamHesaplari r ON k.ReklamKampanyalari_Hesap_id = r.ReklamHesaplari_id
                    WHERE k.Durum = 1
                    ORDER BY k.ReklamKampanyalari_KampanyaAdi
                ", []);
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get_reklam_sayfalar':
                $list = $db->fetchAll("
                    SELECT s.ReklamFacebookSayfalari_id as id,
                           s.ReklamFacebookSayfalari_SayfaAdi + ISNULL(' — ' + k.ReklamKampanyalari_KampanyaAdi, '') as ad
                    FROM ReklamFacebookSayfalari s
                    LEFT JOIN ReklamKampanyalari k ON s.ReklamFacebookSayfalari_Kampanya_id = k.ReklamKampanyalari_id
                    WHERE s.Durum = 1
                    ORDER BY s.ReklamFacebookSayfalari_SayfaAdi
                ", []);
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get_callcenter_kampanyalar':
                $list = $db->fetchAll("
                    SELECT k.CallCenterKampanyalar_id as id,
                           k.CallCenterKampanyalar_Baslik as ad
                    FROM CallCenterKampanyalar k
                    WHERE k.Durum = 1
                    ORDER BY k.CallCenterKampanyalar_Baslik
                ", []);
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get_apilead_kayitlari':
                $list = $db->fetchAll("
                    SELECT t.CallCenterApiLead_id as id,
                           ISNULL(NULLIF(t.CallCenterApiLead_ApiAdi, ''), CONCAT('API #', t.CallCenterApiLead_id)) as ad
                    FROM CallCenterApiLead t
                    WHERE t.Durum = 1
                    ORDER BY ad
                ", []);
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'yetki_list':
                $birimId   = (int)($_POST['birim_id']   ?? 0);
                $hedefTur  = $_POST['hedef_tur']  ?? '';
                $yDurum    = $_POST['y_durum']    ?? '';
                $where     = ["1=1"];
                $params    = [];

                if ($birimId > 0) {
                    $where[] = "k.KullaniciBirimYetkileri_Birim_id = ?";
                    $params[] = $birimId;
                }
                if ($hedefTur === 'altbayi') {
                    $where[] = "k.KullaniciBirimYetkileri_AltBayi_id IS NOT NULL";
                } elseif ($hedefTur === 'personel') {
                    $where[] = "k.KullaniciBirimYetkileri_Personel_id IS NOT NULL";
                } elseif ($hedefTur === 'voip') {
                    $where[] = "k.KullaniciBirimYetkileri_VoIPHesap_id IS NOT NULL";
                } elseif ($hedefTur === 'reklamhesap') {
                    $where[] = "k.KullaniciBirimYetkileri_ReklamHesap_id IS NOT NULL";
                } elseif ($hedefTur === 'reklamkampanya') {
                    $where[] = "k.KullaniciBirimYetkileri_ReklamKampanya_id IS NOT NULL";
                } elseif ($hedefTur === 'reklamsayfa') {
                    $where[] = "k.KullaniciBirimYetkileri_ReklamSayfa_id IS NOT NULL";
                } elseif ($hedefTur === 'callcenterkampanya') {
                    $where[] = "k.KullaniciBirimYetkileri_CallCenterKampanya_id IS NOT NULL";
                } elseif ($hedefTur === 'apilead') {
                    $where[] = "k.KullaniciBirimYetkileri_ApiLead_id IS NOT NULL";
                }
                if ($yDurum !== '') {
                    $where[] = "k.Durum = ?";
                    $params[] = $yDurum;
                }

                $whereClause = implode(" AND ", $where);
                $list = $db->fetchAll("
                    SELECT
                        k.KullaniciBirimYetkileri_id,
                        k.KullaniciBirimYetkileri_Birim_id,
                        b.KullaniciBirim_Adi as Birim_Adi,
                        k.KullaniciBirimYetkileri_AltBayi_id,
                        k.KullaniciBirimYetkileri_Personel_id,
                        k.KullaniciBirimYetkileri_VoIPHesap_id,
                        k.KullaniciBirimYetkileri_ReklamHesap_id,
                        k.KullaniciBirimYetkileri_ReklamKampanya_id,
                        k.KullaniciBirimYetkileri_ReklamSayfa_id,
                        k.KullaniciBirimYetkileri_CallCenterKampanya_id,
                        k.KullaniciBirimYetkileri_ApiLead_id,
                        a.DigiturkAltBayiler_Ad as AltBayi_Adi,
                        p.DigiturkAltBayiPersonel_AdSoyad as Personel_Adi,
                        v.VoIPHesaplar_TelefonNo as VoIP_TelefonNo,
                        rh.ReklamHesaplari_HesapAdi as ReklamHesap_Adi,
                        rk.ReklamKampanyalari_KampanyaAdi as ReklamKampanya_Adi,
                        rs.ReklamFacebookSayfalari_SayfaAdi as ReklamSayfa_Adi,
                        cck.CallCenterKampanyalar_Baslik as CallCenterKampanya_Adi,
                        al.CallCenterApiLead_ApiAdi as ApiLead_Adi,
                        CONVERT(VARCHAR(10), k.KullaniciBirimYetkileri_BaslangicTarihi, 23) as BaslangicTarihi,
                        CONVERT(VARCHAR(10), k.KullaniciBirimYetkileri_BitisTarihi, 23) as BitisTarihi,
                        k.Durum
                    FROM KullaniciBirimYetkileri k
                    INNER JOIN KullaniciBirim b ON k.KullaniciBirimYetkileri_Birim_id = b.KullaniciBirim_id
                    LEFT JOIN DigiturkAltBayiler a ON k.KullaniciBirimYetkileri_AltBayi_id = a.DigiturkAltBayiler_Id
                    LEFT JOIN DigiturkAltBayiPersonel p ON k.KullaniciBirimYetkileri_Personel_id = p.DigiturkAltBayiPersonel_Id
                    LEFT JOIN VoIPHesaplar v ON k.KullaniciBirimYetkileri_VoIPHesap_id = v.VoIPHesaplar_id
                    LEFT JOIN ReklamHesaplari rh ON k.KullaniciBirimYetkileri_ReklamHesap_id = rh.ReklamHesaplari_id
                    LEFT JOIN ReklamKampanyalari rk ON k.KullaniciBirimYetkileri_ReklamKampanya_id = rk.ReklamKampanyalari_id
                    LEFT JOIN ReklamFacebookSayfalari rs ON k.KullaniciBirimYetkileri_ReklamSayfa_id = rs.ReklamFacebookSayfalari_id
                    LEFT JOIN CallCenterKampanyalar cck ON k.KullaniciBirimYetkileri_CallCenterKampanya_id = cck.CallCenterKampanyalar_id
                    LEFT JOIN CallCenterApiLead al ON k.KullaniciBirimYetkileri_ApiLead_id = al.CallCenterApiLead_id
                    WHERE $whereClause
                    ORDER BY b.KullaniciBirim_Adi
                ", $params);
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'yetki_get':
                $id = (int)($_POST['yetki_id'] ?? 0);
                $yetki = $db->fetchOne("
                    SELECT k.*,
                           CONVERT(VARCHAR(10), k.KullaniciBirimYetkileri_BaslangicTarihi, 23) as BaslangicTarihi_fmt,
                           CONVERT(VARCHAR(10), k.KullaniciBirimYetkileri_BitisTarihi, 23) as BitisTarihi_fmt
                    FROM KullaniciBirimYetkileri k
                    WHERE k.KullaniciBirimYetkileri_id = ?
                ", [$id]);
                echo json_encode(['success' => true, 'data' => $yetki]);
                break;

            case 'yetki_save':
                $yetkiId = (int)($_POST['yetki_id'] ?? 0);
                if ($yetkiId > 0 && !$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']); break;
                }
                if ($yetkiId == 0 && !$permissions['can_add']) {
                    echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok!']); break;
                }

                $birimIdY   = (int)($_POST['KullaniciBirimYetkileri_Birim_id'] ?? 0);
                $hedefTurY  = $_POST['hedef_tur'] ?? '';
                $altBayiId  = ($hedefTurY === 'altbayi')  ? ((int)($_POST['KullaniciBirimYetkileri_AltBayi_id']  ?? 0) ?: null) : null;
                $personelId = ($hedefTurY === 'personel') ? ((int)($_POST['KullaniciBirimYetkileri_Personel_id'] ?? 0) ?: null) : null;
                $voipId     = ($hedefTurY === 'voip')     ? ((int)($_POST['KullaniciBirimYetkileri_VoIPHesap_id'] ?? 0) ?: null) : null;
                $reklamHesapId    = ($hedefTurY === 'reklamhesap')    ? ((int)($_POST['KullaniciBirimYetkileri_ReklamHesap_id']    ?? 0) ?: null) : null;
                $reklamKampanyaId = ($hedefTurY === 'reklamkampanya') ? ((int)($_POST['KullaniciBirimYetkileri_ReklamKampanya_id'] ?? 0) ?: null) : null;
                $reklamSayfaId    = ($hedefTurY === 'reklamsayfa')    ? ((int)($_POST['KullaniciBirimYetkileri_ReklamSayfa_id']    ?? 0) ?: null) : null;
                $callCenterKampanyaId = ($hedefTurY === 'callcenterkampanya') ? ((int)($_POST['KullaniciBirimYetkileri_CallCenterKampanya_id'] ?? 0) ?: null) : null;
                $apiLeadId  = ($hedefTurY === 'apilead') ? ((int)($_POST['KullaniciBirimYetkileri_ApiLead_id'] ?? 0) ?: null) : null;
                $baslangic  = $_POST['KullaniciBirimYetkileri_BaslangicTarihi'] ?? '';
                $bitis      = $_POST['KullaniciBirimYetkileri_BitisTarihi'] ?: null;

                if (!$birimIdY || !$baslangic) {
                    echo json_encode(['success' => false, 'message' => 'Birim ve başlangıç tarihi zorunludur!']); break;
                }
                if (!$altBayiId && !$personelId && !$voipId && !$reklamHesapId && !$reklamKampanyaId && !$reklamSayfaId && !$callCenterKampanyaId && !$apiLeadId) {
                    echo json_encode(['success' => false, 'message' => 'Bir hedef (alt bayi, personel, VoIP, reklam hesabı/kampanyası, Facebook sayfası, çağrı merkezi kampanyası veya API lead) seçilmelidir!']); break;
                }

                $durum = isset($_POST['Durum_yetki']) ? 1 : 0;
                if ($bitis && $bitis <= date('Y-m-d')) {
                    $durum = 0;
                }

                $data = [
                    'KullaniciBirimYetkileri_Birim_id'        => $birimIdY,
                    'KullaniciBirimYetkileri_AltBayi_id'      => $altBayiId,
                    'KullaniciBirimYetkileri_Personel_id'     => $personelId,
                    'KullaniciBirimYetkileri_VoIPHesap_id'    => $voipId,
                    'KullaniciBirimYetkileri_ReklamHesap_id'    => $reklamHesapId,
                    'KullaniciBirimYetkileri_ReklamKampanya_id' => $reklamKampanyaId,
                    'KullaniciBirimYetkileri_ReklamSayfa_id'    => $reklamSayfaId,
                    'KullaniciBirimYetkileri_CallCenterKampanya_id' => $callCenterKampanyaId,
                    'KullaniciBirimYetkileri_ApiLead_id'      => $apiLeadId,
                    'KullaniciBirimYetkileri_BaslangicTarihi' => $baslangic,
                    'KullaniciBirimYetkileri_BitisTarihi'     => $bitis,
                    'Durum'                                   => $durum,
                    'GuncellemeTarihi'                        => date('Y-m-d H:i:s'),
                    'GuncelleyenKullanici'                    => $user['kullanici_id'],
                ];

                if ($yetkiId > 0) {
                    $result = $db->update('KullaniciBirimYetkileri', $data, ['KullaniciBirimYetkileri_id' => $yetkiId]);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Yetki başarıyla güncellendi' : 'Güncelleme hatası']);
                } else {
                    $data['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                    $data['OlusturanKullanici'] = $user['kullanici_id'];
                    $result = $db->insert('KullaniciBirimYetkileri', $data);
                    echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Yetki başarıyla eklendi' : 'Ekleme hatası']);
                }
                break;

            case 'yetki_delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']); break;
                }
                $yetkiId = (int)($_POST['yetki_id'] ?? 0);
                $result = $db->delete('KullaniciBirimYetkileri', ['KullaniciBirimYetkileri_id' => $yetkiId]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Yetki başarıyla silindi' : 'Silme hatası']);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">

    <style>
        .status-badge  { padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.875rem; }
        .status-active   { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .alt-birim-badge { font-size: 0.8rem; }
        .renk-onizleme   { width:42px; height:38px; border:1px solid #ced4da; border-radius:.375rem; padding:2px; cursor:pointer; }
        .renk-nokta      { display:inline-block; width:14px; height:14px; border-radius:50%; vertical-align:middle; margin-right:6px; border:1px solid rgba(0,0,0,.15); }
        #hedef-altbayi-group, #hedef-personel-group, #hedef-voip-group,
        #hedef-reklamhesap-group, #hedef-reklamkampanya-group, #hedef-reklamsayfa-group,
        #hedef-callcenterkampanya-group, #hedef-apilead-group { display: none; }
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

                <!-- Info Boxes - Birimler -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-diagram-3"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Birim</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Birim</span>
                                <span class="info-box-number" id="stat-aktif">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-x-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Pasif Birim</span>
                                <span class="info-box-number" id="stat-pasif">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-shield-check"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif Yetki</span>
                                <span class="info-box-number" id="stat-yetki">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sekmeler -->
                <div class="card card-primary card-outline">
                    <div class="card-header p-0">
                        <ul class="nav nav-tabs card-header-tabs" id="birimTabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" id="tab-birim" data-bs-toggle="tab" href="#pane-birim" role="tab">
                                    <i class="bi bi-diagram-3 me-1"></i> Birimler
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="tab-yetki" data-bs-toggle="tab" href="#pane-yetki" role="tab">
                                    <i class="bi bi-shield-check me-1"></i> Birim Yetkileri
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body tab-content">

                        <!-- =============== BİRİMLER =============== -->
                        <div class="tab-pane fade show active" id="pane-birim" role="tabpanel">
                            <!-- Filtre -->
                            <div class="card card-outline card-secondary mb-3">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard">
                                            <i class="bi bi-chevron-down"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body collapse" id="filterCard">
                                    <form id="filterForm">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Ara</label>
                                                <input type="text" class="form-control" name="search" id="filter_search" placeholder="Birim adı veya kodu...">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Üst Birim</label>
                                                <select class="form-select" name="ust_birim_id" id="filter_ust_birim">
                                                    <option value="">Tümü</option>
                                                    <option value="0">Ana Birimler</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Durum</label>
                                                <select class="form-select" name="status" id="filter_status">
                                                    <option value="">Tümü</option>
                                                    <option value="1">Aktif</option>
                                                    <option value="0">Pasif</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Kullanıcı Durumu</label>
                                                <select class="form-select" name="kullanici_var" id="filter_kullanici_var">
                                                    <option value="">Tümü</option>
                                                    <option value="1">Kullanıcısı olanlar</option>
                                                    <option value="0">Kullanıcısı olmayanlar</option>
                                                </select>
                                            </div>
                                            <div class="col-md-12">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-search"></i> Filtrele
                                                </button>
                                                <button type="button" class="btn btn-secondary" id="clearFilters">
                                                    <i class="bi bi-x-circle"></i> Temizle
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <!-- Tablo -->
                            <div class="d-flex justify-content-end mb-2">
                                <?php if ($permissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#birimModal" onclick="resetForm()">
                                    <i class="bi bi-plus-circle"></i> Yeni Birim Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                            <table id="birimTable" class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Birim Adı</th>
                                        <th>Kod</th>
                                        <th>Üst Birim</th>
                                        <th>Açıklama</th>
                                        <th>Kullanıcı</th>
                                        <th>Durum</th>
                                        <th>Oluşturma</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>

                        <!-- =============== BİRİM YETKİLERİ =============== -->
                        <div class="tab-pane fade" id="pane-yetki" role="tabpanel">
                            <!-- Filtre -->
                            <div class="card card-outline card-secondary mb-3">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                    <div class="card-tools">
                                        <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#yetkiFilterCard">
                                            <i class="bi bi-chevron-down"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body collapse" id="yetkiFilterCard">
                                    <form id="yetkiFilterForm">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Birim</label>
                                                <select class="form-select" name="birim_id" id="yfilter_birim">
                                                    <option value="">Tümü</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Hedef Türü</label>
                                                <select class="form-select" name="hedef_tur" id="yfilter_hedef_tur">
                                                    <option value="">Tümü</option>
                                                    <option value="altbayi">Alt Bayi</option>
                                                    <option value="personel">Personel</option>
                                                    <option value="voip">VoIP Hesabı</option>
                                                    <option value="reklamhesap">Reklam Hesabı</option>
                                                    <option value="reklamkampanya">Reklam Kampanyası</option>
                                                    <option value="reklamsayfa">Facebook/Web Sayfası</option>
                                                    <option value="callcenterkampanya">Çağrı Merkezi Kampanyası</option>
                                                    <option value="apilead">API Lead</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Durum</label>
                                                <select class="form-select" name="y_durum" id="yfilter_durum">
                                                    <option value="">Tümü</option>
                                                    <option value="1">Aktif</option>
                                                    <option value="0">Pasif</option>
                                                </select>
                                            </div>
                                            <div class="col-md-12">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-search"></i> Filtrele
                                                </button>
                                                <button type="button" class="btn btn-secondary" id="clearYetkiFilters">
                                                    <i class="bi bi-x-circle"></i> Temizle
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <!-- Tablo -->
                            <div class="d-flex justify-content-end mb-2">
                                <?php if ($permissions['can_add']): ?>
                                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#yetkiModal" onclick="resetYetkiForm()">
                                    <i class="bi bi-plus-circle"></i> Yeni Yetki Ekle
                                </button>
                                <?php endif; ?>
                            </div>
                            <table id="yetkiTable" class="table table-bordered table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Birim</th>
                                        <th>Hedef Türü</th>
                                        <th>Hedef</th>
                                        <th>Başlangıç</th>
                                        <th>Bitiş</th>
                                        <th>Durum</th>
                                        <th>İşlemler</th>
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

<!-- Birim Modal -->
<div class="modal fade" id="birimModal" tabindex="-1" aria-labelledby="birimModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="birimModalLabel">Yeni Birim Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="birimForm">
                <div class="modal-body">
                    <input type="hidden" id="birim_id" name="birim_id" value="">

                    <div class="mb-3">
                        <label for="KullaniciBirim_Adi" class="form-label">Birim Adı <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="KullaniciBirim_Adi" name="KullaniciBirim_Adi" required>
                    </div>
                    <div class="mb-3">
                        <label for="KullaniciBirim_Kodu" class="form-label">Birim Kodu</label>
                        <input type="text" class="form-control" id="KullaniciBirim_Kodu" name="KullaniciBirim_Kodu">
                    </div>
                    <div class="mb-3">
                        <label for="KullaniciBirim_UstBirim_id" class="form-label">Üst Birim</label>
                        <select class="form-select" id="KullaniciBirim_UstBirim_id" name="KullaniciBirim_UstBirim_id">
                            <option value="">— Ana Birim (üst yok) —</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="KullaniciBirim_Renk" class="form-label">Renk</label>
                        <div class="d-flex align-items-center gap-2">
                            <input type="color" class="renk-onizleme" id="KullaniciBirim_Renk_picker" value="#6c757d">
                            <input type="text" class="form-control font-monospace" id="KullaniciBirim_Renk" name="KullaniciBirim_Renk" value="#6c757d" maxlength="7" style="max-width:140px" placeholder="#RRGGBB">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="KullaniciBirim_Aciklama" class="form-label">Açıklama</label>
                        <textarea class="form-control" id="KullaniciBirim_Aciklama" name="KullaniciBirim_Aciklama" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="Durum" name="Durum" checked>
                            <label class="form-check-label" for="Durum">Aktif</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> İptal
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Kaydet
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Yetki Modal -->
<div class="modal fade" id="yetkiModal" tabindex="-1" aria-labelledby="yetkiModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="yetkiModalLabel">Yeni Yetki Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="yetkiForm">
                <div class="modal-body">
                    <input type="hidden" id="yetki_id" name="yetki_id" value="">

                    <div class="row g-3">
                        <div class="col-md-12">
                            <label for="KullaniciBirimYetkileri_Birim_id" class="form-label">Birim <span class="text-danger">*</span></label>
                            <select class="form-select" id="KullaniciBirimYetkileri_Birim_id" name="KullaniciBirimYetkileri_Birim_id" required>
                                <option value="">— Birim seçin —</option>
                            </select>
                        </div>

                        <div class="col-md-12">
                            <label for="hedef_tur" class="form-label">Hedef Türü <span class="text-danger">*</span></label>
                            <select class="form-select" id="hedef_tur" name="hedef_tur">
                                <option value="">— Hedef türü seçin —</option>
                                <option value="altbayi">Alt Bayi</option>
                                <option value="personel">Personel</option>
                                <option value="voip">VoIP Hesabı</option>
                                <option value="reklamhesap">Reklam Hesabı</option>
                                <option value="reklamkampanya">Reklam Kampanyası</option>
                                <option value="reklamsayfa">Facebook/Web Sayfası</option>
                                <option value="callcenterkampanya">Çağrı Merkezi Kampanyası</option>
                                <option value="apilead">Çağrı Merkezi API Lead</option>
                            </select>
                        </div>

                        <div class="col-md-12" id="hedef-altbayi-group">
                            <label for="KullaniciBirimYetkileri_AltBayi_id" class="form-label">Alt Bayi <span class="text-danger">*</span></label>
                            <select class="form-select" id="KullaniciBirimYetkileri_AltBayi_id" name="KullaniciBirimYetkileri_AltBayi_id">
                                <option value="">— Alt bayi seçin —</option>
                            </select>
                        </div>

                        <div class="col-md-12" id="hedef-personel-group">
                            <label for="KullaniciBirimYetkileri_Personel_id" class="form-label">Personel <span class="text-danger">*</span></label>
                            <select class="form-select" id="KullaniciBirimYetkileri_Personel_id" name="KullaniciBirimYetkileri_Personel_id">
                                <option value="">— Personel seçin —</option>
                            </select>
                        </div>

                        <div class="col-md-12" id="hedef-voip-group">
                            <label for="KullaniciBirimYetkileri_VoIPHesap_id" class="form-label">VoIP Hesabı <span class="text-danger">*</span></label>
                            <select class="form-select" id="KullaniciBirimYetkileri_VoIPHesap_id" name="KullaniciBirimYetkileri_VoIPHesap_id">
                                <option value="">— VoIP hesabı seçin —</option>
                            </select>
                        </div>

                        <div class="col-md-12" id="hedef-reklamhesap-group">
                            <label for="KullaniciBirimYetkileri_ReklamHesap_id" class="form-label">Reklam Hesabı <span class="text-danger">*</span></label>
                            <select class="form-select" id="KullaniciBirimYetkileri_ReklamHesap_id" name="KullaniciBirimYetkileri_ReklamHesap_id">
                                <option value="">— Reklam hesabı seçin —</option>
                            </select>
                        </div>

                        <div class="col-md-12" id="hedef-reklamkampanya-group">
                            <label for="KullaniciBirimYetkileri_ReklamKampanya_id" class="form-label">Reklam Kampanyası <span class="text-danger">*</span></label>
                            <select class="form-select" id="KullaniciBirimYetkileri_ReklamKampanya_id" name="KullaniciBirimYetkileri_ReklamKampanya_id">
                                <option value="">— Reklam kampanyası seçin —</option>
                            </select>
                        </div>

                        <div class="col-md-12" id="hedef-reklamsayfa-group">
                            <label for="KullaniciBirimYetkileri_ReklamSayfa_id" class="form-label">Facebook/Web Sayfası <span class="text-danger">*</span></label>
                            <select class="form-select" id="KullaniciBirimYetkileri_ReklamSayfa_id" name="KullaniciBirimYetkileri_ReklamSayfa_id">
                                <option value="">— Facebook/Web sayfası seçin —</option>
                            </select>
                        </div>

                        <div class="col-md-12" id="hedef-callcenterkampanya-group">
                            <label for="KullaniciBirimYetkileri_CallCenterKampanya_id" class="form-label">Çağrı Merkezi Kampanyası <span class="text-danger">*</span></label>
                            <select class="form-select" id="KullaniciBirimYetkileri_CallCenterKampanya_id" name="KullaniciBirimYetkileri_CallCenterKampanya_id">
                                <option value="">— Çağrı merkezi kampanyası seçin —</option>
                            </select>
                        </div>

                        <div class="col-md-12" id="hedef-apilead-group">
                            <label for="KullaniciBirimYetkileri_ApiLead_id" class="form-label">Çağrı Merkezi API Lead <span class="text-danger">*</span></label>
                            <select class="form-select" id="KullaniciBirimYetkileri_ApiLead_id" name="KullaniciBirimYetkileri_ApiLead_id">
                                <option value="">— API lead seçin —</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="KullaniciBirimYetkileri_BaslangicTarihi" class="form-label">Başlangıç Tarihi <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="KullaniciBirimYetkileri_BaslangicTarihi" name="KullaniciBirimYetkileri_BaslangicTarihi" required>
                        </div>

                        <div class="col-md-6">
                            <label for="KullaniciBirimYetkileri_BitisTarihi" class="form-label">Bitiş Tarihi <span class="text-muted">(boş = süresiz)</span></label>
                            <input type="date" class="form-control" id="KullaniciBirimYetkileri_BitisTarihi" name="KullaniciBirimYetkileri_BitisTarihi">
                        </div>

                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="Durum_yetki" name="Durum_yetki" checked>
                                <label class="form-check-label" for="Durum_yetki">Aktif</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> İptal
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-save"></i> Kaydet
                    </button>
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
    let birimModal, yetkiModal;
    let dataTable, yetkiTable;

    const permissions = {
        canAdd:    <?= $permissions['can_add']    ? 'true' : 'false' ?>,
        canEdit:   <?= $permissions['can_edit']   ? 'true' : 'false' ?>,
        canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
    };

    let currentFilters = {};
    let currentYetkiFilters = {};

    function formatDate(d) {
        if (!d) return '-';
        try {
            if (typeof d === 'object' && d.date) d = d.date;
            const dt = new Date(String(d).replace(' ', 'T'));
            if (isNaN(dt.getTime())) return '-';
            return dt.toLocaleDateString('tr-TR', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
        } catch(e) { return '-'; }
    }

    function formatDateShort(d) {
        if (!d) return '-';
        try {
            const dt = new Date(d + 'T00:00:00');
            if (isNaN(dt.getTime())) return '-';
            return dt.toLocaleDateString('tr-TR', { year: 'numeric', month: '2-digit', day: '2-digit' });
        } catch(e) { return '-'; }
    }

    function escHtml(str) {
        if (!str || str === '-') return str || '-';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    $(document).ready(function () {
        birimModal = new bootstrap.Modal(document.getElementById('birimModal'));
        yetkiModal = new bootstrap.Modal(document.getElementById('yetkiModal'));

        // ---- DataTables ----
        dataTable = $('#birimTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[2, 'asc'], [0, 'asc']],
            columnDefs: [{ orderable: false, targets: [7] }],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        });

        yetkiTable = $('#yetkiTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc']],
            columnDefs: [{ orderable: false, targets: [6] }],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        });

        // ---- Select2 ----
        $('#filter_ust_birim').select2({ theme: 'bootstrap-5', placeholder: 'Tümü', allowClear: true, width: '100%' });
        $('#filter_status').select2({ theme: 'bootstrap-5', placeholder: 'Tümü', allowClear: true, width: '100%' });
        $('#filter_kullanici_var').select2({ theme: 'bootstrap-5', placeholder: 'Tümü', allowClear: true, width: '100%' });
        $('#KullaniciBirim_UstBirim_id').select2({ theme: 'bootstrap-5', placeholder: '— Ana Birim (üst yok) —', allowClear: true, width: '100%', dropdownParent: $('#birimModal') });
        $('#yfilter_birim').select2({ theme: 'bootstrap-5', placeholder: 'Tümü', allowClear: true, width: '100%' });
        $('#yfilter_hedef_tur').select2({ theme: 'bootstrap-5', placeholder: 'Tümü', allowClear: true, width: '100%' });
        $('#yfilter_durum').select2({ theme: 'bootstrap-5', placeholder: 'Tümü', allowClear: true, width: '100%' });
        $('#KullaniciBirimYetkileri_Birim_id').select2({ theme: 'bootstrap-5', placeholder: '— Birim seçin —', allowClear: true, width: '100%', dropdownParent: $('#yetkiModal') });
        $('#KullaniciBirimYetkileri_AltBayi_id').select2({ theme: 'bootstrap-5', placeholder: '— Alt bayi seçin —', allowClear: true, width: '100%', dropdownParent: $('#yetkiModal') });
        $('#KullaniciBirimYetkileri_Personel_id').select2({ theme: 'bootstrap-5', placeholder: '— Personel seçin —', allowClear: true, width: '100%', dropdownParent: $('#yetkiModal') });
        $('#KullaniciBirimYetkileri_VoIPHesap_id').select2({ theme: 'bootstrap-5', placeholder: '— VoIP hesabı seçin —', allowClear: true, width: '100%', dropdownParent: $('#yetkiModal') });
        $('#KullaniciBirimYetkileri_ReklamHesap_id').select2({ theme: 'bootstrap-5', placeholder: '— Reklam hesabı seçin —', allowClear: true, width: '100%', dropdownParent: $('#yetkiModal') });
        $('#KullaniciBirimYetkileri_ReklamKampanya_id').select2({ theme: 'bootstrap-5', placeholder: '— Reklam kampanyası seçin —', allowClear: true, width: '100%', dropdownParent: $('#yetkiModal') });
        $('#KullaniciBirimYetkileri_ReklamSayfa_id').select2({ theme: 'bootstrap-5', placeholder: '— Facebook/Web sayfası seçin —', allowClear: true, width: '100%', dropdownParent: $('#yetkiModal') });
        $('#KullaniciBirimYetkileri_CallCenterKampanya_id').select2({ theme: 'bootstrap-5', placeholder: '— Çağrı merkezi kampanyası seçin —', allowClear: true, width: '100%', dropdownParent: $('#yetkiModal') });
        $('#KullaniciBirimYetkileri_ApiLead_id').select2({ theme: 'bootstrap-5', placeholder: '— API lead seçin —', allowClear: true, width: '100%', dropdownParent: $('#yetkiModal') });
        $('#hedef_tur').select2({ theme: 'bootstrap-5', placeholder: '— Hedef türü seçin —', allowClear: true, width: '100%', dropdownParent: $('#yetkiModal') });

        // ---- İlk yüklemeler ----
        loadStats();
        loadBirimList();
        loadFilterUstBirim();
        loadYetkiList();
        loadYetkiFilterBirim();

        // ---- Hedef türü dropdown ----
        $('#hedef_tur').on('change', function () {
            const val = $(this).val();
            $('#hedef-altbayi-group').toggle(val === 'altbayi');
            $('#hedef-personel-group').toggle(val === 'personel');
            $('#hedef-voip-group').toggle(val === 'voip');
            $('#hedef-reklamhesap-group').toggle(val === 'reklamhesap');
            $('#hedef-reklamkampanya-group').toggle(val === 'reklamkampanya');
            $('#hedef-reklamsayfa-group').toggle(val === 'reklamsayfa');
            $('#hedef-callcenterkampanya-group').toggle(val === 'callcenterkampanya');
            $('#hedef-apilead-group').toggle(val === 'apilead');
            if (val === 'altbayi' && $('#KullaniciBirimYetkileri_AltBayi_id option').length <= 1) {
                loadAltBayilerSelect();
            }
            if (val === 'personel' && $('#KullaniciBirimYetkileri_Personel_id option').length <= 1) {
                loadPersonelSelect();
            }
            if (val === 'voip' && $('#KullaniciBirimYetkileri_VoIPHesap_id option').length <= 1) {
                loadVoIPSelect(null, parseInt($('#yetki_id').val()) || 0);
            }
            if (val === 'reklamhesap' && $('#KullaniciBirimYetkileri_ReklamHesap_id option').length <= 1) {
                loadReklamHesapSelect();
            }
            if (val === 'reklamkampanya' && $('#KullaniciBirimYetkileri_ReklamKampanya_id option').length <= 1) {
                loadReklamKampanyaSelect();
            }
            if (val === 'reklamsayfa' && $('#KullaniciBirimYetkileri_ReklamSayfa_id option').length <= 1) {
                loadReklamSayfaSelect();
            }
            if (val === 'callcenterkampanya' && $('#KullaniciBirimYetkileri_CallCenterKampanya_id option').length <= 1) {
                loadCallCenterKampanyaSelect();
            }
            if (val === 'apilead' && $('#KullaniciBirimYetkileri_ApiLead_id option').length <= 1) {
                loadApiLeadSelect();
            }
        });

        // ---- Renk picker <-> hex senkronizasyonu ----
        $('#KullaniciBirim_Renk_picker').on('input', function () {
            $('#KullaniciBirim_Renk').val(this.value);
        });
        $('#KullaniciBirim_Renk').on('input', function () {
            const v = this.value.trim();
            if (/^#[0-9A-Fa-f]{6}$/.test(v)) $('#KullaniciBirim_Renk_picker').val(v);
        });

        // ---- Form olayları ----
        $('#birimForm').on('submit', function (e) { e.preventDefault(); saveBirim(); });
        $('#yetkiForm').on('submit', function (e) { e.preventDefault(); saveYetki(); });

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            currentFilters = {
                search:        $('#filter_search').val(),
                ust_birim_id:  $('#filter_ust_birim').val(),
                status:        $('#filter_status').val(),
                kullanici_var: $('#filter_kullanici_var').val()
            };
            Object.keys(currentFilters).forEach(k => { if (!currentFilters[k] && currentFilters[k] !== '0') delete currentFilters[k]; });
            loadBirimList();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filterForm')[0].reset();
            $('#filter_ust_birim, #filter_status, #filter_kullanici_var').val('').trigger('change.select2');
            currentFilters = {};
            loadBirimList();
            showToast('Filtreler temizlendi', 'info');
        });

        $('#yetkiFilterForm').on('submit', function (e) {
            e.preventDefault();
            currentYetkiFilters = {
                birim_id:  $('#yfilter_birim').val()      || '',
                hedef_tur: $('#yfilter_hedef_tur').val()  || '',
                y_durum:   $('#yfilter_durum').val()      || ''
            };
            loadYetkiList();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearYetkiFilters').on('click', function () {
            $('#yetkiFilterForm')[0].reset();
            $('#yfilter_birim, #yfilter_hedef_tur, #yfilter_durum').val('').trigger('change.select2');
            currentYetkiFilters = {};
            loadYetkiList();
            showToast('Filtreler temizlendi', 'info');
        });
    });

    // ================================================================
    // BİRİM FONKSİYONLARI
    // ================================================================
    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (r.success) {
                $('#stat-toplam').text(r.data.toplam);
                $('#stat-aktif').text(r.data.aktif);
                $('#stat-pasif').text(r.data.pasif);
                $('#stat-yetki').text(r.data.yetki);
            }
        });
    }

    function loadFilterUstBirim() {
        $.post('', { action: 'get_all_for_select' }, function (r) {
            if (!r.success) return;
            const sel = $('#filter_ust_birim');
            sel.find('option:not(:first):not([value="0"])').remove();
            r.data.forEach(b => sel.append(new Option(b.KullaniciBirim_Adi, b.KullaniciBirim_id)));
            sel.trigger('change.select2');
        });
    }

    function loadUstBirimSelect(excludeId, selectedId) {
        $.post('', { action: 'get_all_for_select', exclude_id: excludeId || 0 }, function (r) {
            if (!r.success) return;
            const sel = $('#KullaniciBirim_UstBirim_id');
            sel.find('option:not(:first)').remove();
            r.data.forEach(b => {
                const opt = new Option(b.KullaniciBirim_Adi, b.KullaniciBirim_id, false, b.KullaniciBirim_id == selectedId);
                sel.append(opt);
            });
            sel.val(selectedId || '').trigger('change.select2');
        });
    }

    function loadBirimList() {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'list', ...currentFilters },
            dataType: 'json',
            success: function (r) {
                if (r.success) renderBirimTable(r.data);
                else showToast('Liste yüklenirken hata oluştu', 'error');
            },
            error: function () { showToast('Sunucu hatası oluştu', 'error'); }
        });
    }

    function renderBirimTable(data) {
        dataTable.clear();
        data.forEach(b => {
            const durum   = b.Durum
                ? '<span class="status-badge status-active">Aktif</span>'
                : '<span class="status-badge status-inactive">Pasif</span>';
            const ustBirim = b.UstBirim_Adi
                ? `<span class="badge bg-secondary alt-birim-badge">${escHtml(b.UstBirim_Adi)}</span>`
                : '<span class="text-muted">Ana Birim</span>';
            const aciklama = b.KullaniciBirim_Aciklama
                ? (b.KullaniciBirim_Aciklama.length > 50 ? b.KullaniciBirim_Aciklama.substring(0, 50) + '…' : b.KullaniciBirim_Aciklama)
                : '-';

            let islemler = '';
            if (permissions.canEdit) {
                islemler += `<button class="btn btn-sm btn-warning me-1" onclick="editBirim(${b.KullaniciBirim_id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
            }
            if (permissions.canDelete) {
                islemler += `<button class="btn btn-sm btn-danger" onclick="deleteBirim(${b.KullaniciBirim_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
            }
            if (!islemler) islemler = '<span class="text-muted">-</span>';

            const kullaniciSayisi = b.KullaniciSayisi > 0
                ? `<span class="badge bg-primary">${b.KullaniciSayisi}</span>`
                : '<span class="text-muted">-</span>';

            const renk = /^#[0-9A-Fa-f]{6}$/.test(b.KullaniciBirim_Renk || '') ? b.KullaniciBirim_Renk : '#6c757d';
            const adiHtml = `<span class="renk-nokta" style="background:${renk}"></span>${escHtml(b.KullaniciBirim_Adi)}`;

            dataTable.row.add([
                adiHtml,
                b.KullaniciBirim_Kodu || '-',
                ustBirim,
                escHtml(aciklama),
                kullaniciSayisi,
                durum,
                formatDate(b.OlusturmaTarihi),
                islemler
            ]);
        });
        dataTable.draw();
    }

    function setBirimRenk(v) {
        v = /^#[0-9A-Fa-f]{6}$/.test(v || '') ? v : '#6c757d';
        $('#KullaniciBirim_Renk').val(v);
        $('#KullaniciBirim_Renk_picker').val(v);
    }

    function resetForm() {
        document.getElementById('birimForm').reset();
        document.getElementById('birim_id').value = '';
        document.getElementById('birimModalLabel').textContent = 'Yeni Birim Ekle';
        setBirimRenk('#6c757d');
        loadUstBirimSelect(0, '');
    }

    function saveBirim() {
        const formData = new FormData(document.getElementById('birimForm'));
        formData.append('action', 'save');
        $.ajax({
            url: '', method: 'POST',
            data: formData, processData: false, contentType: false,
            dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showToast(r.message, 'success');
                    birimModal.hide();
                    loadBirimList();
                    loadStats();
                    loadFilterUstBirim();
                    loadYetkiFilterBirim();
                } else {
                    showToast(r.message, 'error');
                }
            },
            error: function () { showToast('Kayıt sırasında hata oluştu', 'error'); }
        });
    }

    function editBirim(birimId) {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'get', birim_id: birimId },
            dataType: 'json',
            success: function (r) {
                if (r.success && r.data) {
                    const b = r.data;
                    document.getElementById('birim_id').value                = b.KullaniciBirim_id;
                    document.getElementById('KullaniciBirim_Adi').value      = b.KullaniciBirim_Adi || '';
                    document.getElementById('KullaniciBirim_Kodu').value     = b.KullaniciBirim_Kodu || '';
                    document.getElementById('KullaniciBirim_Aciklama').value = b.KullaniciBirim_Aciklama || '';
                    setBirimRenk(b.KullaniciBirim_Renk);
                    document.getElementById('Durum').checked                 = b.Durum == 1;
                    document.getElementById('birimModalLabel').textContent   = 'Birim Düzenle';
                    loadUstBirimSelect(b.KullaniciBirim_id, b.KullaniciBirim_UstBirim_id);
                    birimModal.show();
                }
            },
            error: function () { showToast('Kayıt yüklenirken hata oluştu', 'error'); }
        });
    }

    function deleteBirim(birimId) {
        confirmAction(
            'Bu birimi silmek istediğinize emin misiniz?',
            'Bu işlem geri alınamaz!',
            function () {
                $.ajax({
                    url: '', method: 'POST',
                    data: { action: 'delete', birim_id: birimId },
                    dataType: 'json',
                    success: function (r) {
                        if (r.success) {
                            showSuccess('Silindi!', r.message);
                            loadBirimList();
                            loadStats();
                            loadFilterUstBirim();
                        } else {
                            showError('Hata!', r.message);
                        }
                    },
                    error: function () { showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'); }
                });
            }
        );
    }

    // ================================================================
    // YETKİ FONKSİYONLARI
    // ================================================================
    function loadYetkiFilterBirim() {
        $.post('', { action: 'get_all_for_select' }, function (r) {
            if (!r.success) return;
            const sels = ['#yfilter_birim', '#KullaniciBirimYetkileri_Birim_id'];
            sels.forEach(selId => {
                const sel = $(selId);
                sel.find('option:not(:first)').remove();
                r.data.forEach(b => sel.append(new Option(b.KullaniciBirim_Adi, b.KullaniciBirim_id)));
                sel.trigger('change.select2');
            });
        });
    }

    function loadAltBayilerSelect(selectedId) {
        $.post('', { action: 'get_alt_bayiler' }, function (r) {
            if (!r.success) return;
            const sel = $('#KullaniciBirimYetkileri_AltBayi_id');
            sel.find('option:not(:first)').remove();
            r.data.forEach(item => {
                const opt = new Option(item.ad, item.id, false, item.id == selectedId);
                sel.append(opt);
            });
            sel.val(selectedId || '').trigger('change.select2');
        });
    }

    function loadPersonelSelect(selectedId) {
        $.post('', { action: 'get_personel' }, function (r) {
            if (!r.success) return;
            const sel = $('#KullaniciBirimYetkileri_Personel_id');
            sel.find('option:not(:first)').remove();
            r.data.forEach(item => {
                const opt = new Option(item.ad, item.id, false, item.id == selectedId);
                sel.append(opt);
            });
            sel.val(selectedId || '').trigger('change.select2');
        });
    }

    function loadVoIPSelect(selectedId, excludeYetkiId) {
        $.post('', { action: 'get_voip_hesaplar', exclude_yetki_id: excludeYetkiId || 0 }, function (r) {
            if (!r.success) return;
            const sel = $('#KullaniciBirimYetkileri_VoIPHesap_id');
            sel.find('option:not(:first)').remove();
            r.data.forEach(item => {
                const opt = new Option(item.ad, item.id, false, item.id == selectedId);
                sel.append(opt);
            });
            sel.val(selectedId || '').trigger('change.select2');
        });
    }

    function loadReklamHesapSelect(selectedId) {
        $.post('', { action: 'get_reklam_hesaplar' }, function (r) {
            if (!r.success) return;
            const sel = $('#KullaniciBirimYetkileri_ReklamHesap_id');
            sel.find('option:not(:first)').remove();
            r.data.forEach(item => {
                const opt = new Option(item.ad, item.id, false, item.id == selectedId);
                sel.append(opt);
            });
            sel.val(selectedId || '').trigger('change.select2');
        });
    }

    function loadReklamKampanyaSelect(selectedId) {
        $.post('', { action: 'get_reklam_kampanyalar' }, function (r) {
            if (!r.success) return;
            const sel = $('#KullaniciBirimYetkileri_ReklamKampanya_id');
            sel.find('option:not(:first)').remove();
            r.data.forEach(item => {
                const opt = new Option(item.ad, item.id, false, item.id == selectedId);
                sel.append(opt);
            });
            sel.val(selectedId || '').trigger('change.select2');
        });
    }

    function loadReklamSayfaSelect(selectedId) {
        $.post('', { action: 'get_reklam_sayfalar' }, function (r) {
            if (!r.success) return;
            const sel = $('#KullaniciBirimYetkileri_ReklamSayfa_id');
            sel.find('option:not(:first)').remove();
            r.data.forEach(item => {
                const opt = new Option(item.ad, item.id, false, item.id == selectedId);
                sel.append(opt);
            });
            sel.val(selectedId || '').trigger('change.select2');
        });
    }

    function loadCallCenterKampanyaSelect(selectedId) {
        $.post('', { action: 'get_callcenter_kampanyalar' }, function (r) {
            if (!r.success) return;
            const sel = $('#KullaniciBirimYetkileri_CallCenterKampanya_id');
            sel.find('option:not(:first)').remove();
            r.data.forEach(item => {
                const opt = new Option(item.ad, item.id, false, item.id == selectedId);
                sel.append(opt);
            });
            sel.val(selectedId || '').trigger('change.select2');
        });
    }

    function loadApiLeadSelect(selectedId) {
        $.post('', { action: 'get_apilead_kayitlari' }, function (r) {
            if (!r.success) return;
            const sel = $('#KullaniciBirimYetkileri_ApiLead_id');
            sel.find('option:not(:first)').remove();
            r.data.forEach(item => {
                const opt = new Option(item.ad, item.id, false, item.id == selectedId);
                sel.append(opt);
            });
            sel.val(selectedId || '').trigger('change.select2');
        });
    }

    function loadYetkiList() {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'yetki_list', ...currentYetkiFilters },
            dataType: 'json',
            success: function (r) {
                if (r.success) renderYetkiTable(r.data);
                else showToast('Yetki listesi yüklenirken hata oluştu', 'error');
            },
            error: function () { showToast('Sunucu hatası oluştu', 'error'); }
        });
    }

    function renderYetkiTable(data) {
        yetkiTable.clear();
        data.forEach(y => {
            let hedefTur, hedefAdi;
            if (y.KullaniciBirimYetkileri_AltBayi_id) {
                hedefTur = '<span class="badge bg-primary">Alt Bayi</span>';
                hedefAdi = escHtml(y.AltBayi_Adi);
            } else if (y.KullaniciBirimYetkileri_Personel_id) {
                hedefTur = '<span class="badge bg-info">Personel</span>';
                hedefAdi = escHtml(y.Personel_Adi);
            } else if (y.KullaniciBirimYetkileri_VoIPHesap_id) {
                hedefTur = '<span class="badge bg-success">VoIP</span>';
                hedefAdi = escHtml(y.VoIP_TelefonNo);
            } else if (y.KullaniciBirimYetkileri_ReklamHesap_id) {
                hedefTur = '<span class="badge bg-warning text-dark">Reklam Hesabı</span>';
                hedefAdi = escHtml(y.ReklamHesap_Adi);
            } else if (y.KullaniciBirimYetkileri_ReklamKampanya_id) {
                hedefTur = '<span class="badge bg-secondary">Reklam Kampanyası</span>';
                hedefAdi = escHtml(y.ReklamKampanya_Adi);
            } else if (y.KullaniciBirimYetkileri_ReklamSayfa_id) {
                hedefTur = '<span class="badge bg-dark">Facebook/Web Sayfası</span>';
                hedefAdi = escHtml(y.ReklamSayfa_Adi);
            } else if (y.KullaniciBirimYetkileri_CallCenterKampanya_id) {
                hedefTur = '<span class="badge bg-danger">Çağrı Merkezi Kampanyası</span>';
                hedefAdi = escHtml(y.CallCenterKampanya_Adi);
            } else {
                hedefTur = '<span class="badge bg-primary">API Lead</span>';
                hedefAdi = escHtml(y.ApiLead_Adi);
            }
            const bitis = y.BitisTarihi
                ? formatDateShort(y.BitisTarihi)
                : '<span class="text-muted">Süresiz</span>';
            const durum = y.Durum
                ? '<span class="status-badge status-active">Aktif</span>'
                : '<span class="status-badge status-inactive">Pasif</span>';

            let islemler = '';
            if (permissions.canEdit) {
                islemler += `<button class="btn btn-sm btn-warning me-1" onclick="editYetki(${y.KullaniciBirimYetkileri_id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
            }
            if (permissions.canDelete) {
                islemler += `<button class="btn btn-sm btn-danger" onclick="deleteYetki(${y.KullaniciBirimYetkileri_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
            }
            if (!islemler) islemler = '<span class="text-muted">-</span>';

            yetkiTable.row.add([
                escHtml(y.Birim_Adi),
                hedefTur,
                hedefAdi,
                formatDateShort(y.BaslangicTarihi),
                bitis,
                durum,
                islemler
            ]);
        });
        yetkiTable.draw();
    }

    function resetYetkiForm() {
        document.getElementById('yetkiForm').reset();
        document.getElementById('yetki_id').value = '';
        document.getElementById('yetkiModalLabel').textContent = 'Yeni Yetki Ekle';
        $('#hedef-altbayi-group, #hedef-personel-group, #hedef-voip-group, #hedef-reklamhesap-group, #hedef-reklamkampanya-group, #hedef-reklamsayfa-group, #hedef-callcenterkampanya-group, #hedef-apilead-group').hide();
        $('#hedef_tur').val('').trigger('change.select2');
        $('#KullaniciBirimYetkileri_Birim_id').val('').trigger('change.select2');
        $('#KullaniciBirimYetkileri_AltBayi_id').val('').trigger('change.select2');
        $('#KullaniciBirimYetkileri_Personel_id').val('').trigger('change.select2');
        $('#KullaniciBirimYetkileri_VoIPHesap_id').val('').trigger('change.select2');
        $('#KullaniciBirimYetkileri_ReklamHesap_id').val('').trigger('change.select2');
        $('#KullaniciBirimYetkileri_ReklamKampanya_id').val('').trigger('change.select2');
        $('#KullaniciBirimYetkileri_ReklamSayfa_id').val('').trigger('change.select2');
        $('#KullaniciBirimYetkileri_CallCenterKampanya_id').val('').trigger('change.select2');
        $('#KullaniciBirimYetkileri_ApiLead_id').val('').trigger('change.select2');
        loadYetkiFilterBirim();
    }

    function saveYetki() {
        const hedefTur = $('#hedef_tur').val();
        if (!hedefTur) { showToast('Hedef türü seçilmelidir!', 'error'); return; }

        const formData = new FormData(document.getElementById('yetkiForm'));
        formData.append('action', 'yetki_save');
        $.ajax({
            url: '', method: 'POST',
            data: formData, processData: false, contentType: false,
            dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showToast(r.message, 'success');
                    yetkiModal.hide();
                    loadYetkiList();
                    loadStats();
                } else {
                    showToast(r.message, 'error');
                }
            },
            error: function () { showToast('Kayıt sırasında hata oluştu', 'error'); }
        });
    }

    function editYetki(yetkiId) {
        $.ajax({
            url: '', method: 'POST',
            data: { action: 'yetki_get', yetki_id: yetkiId },
            dataType: 'json',
            success: function (r) {
                if (!r.success || !r.data) { showToast('Kayıt yüklenemedi', 'error'); return; }
                const y = r.data;

                document.getElementById('yetki_id').value = y.KullaniciBirimYetkileri_id;
                document.getElementById('yetkiModalLabel').textContent = 'Yetki Düzenle';
                document.getElementById('KullaniciBirimYetkileri_BaslangicTarihi').value = y.BaslangicTarihi_fmt || '';
                document.getElementById('KullaniciBirimYetkileri_BitisTarihi').value     = y.BitisTarihi_fmt || '';
                document.getElementById('Durum_yetki').checked = y.Durum == 1;

                loadYetkiFilterBirim();
                setTimeout(() => {
                    $('#KullaniciBirimYetkileri_Birim_id').val(y.KullaniciBirimYetkileri_Birim_id).trigger('change.select2');
                }, 300);

                $('#hedef-altbayi-group, #hedef-personel-group, #hedef-voip-group, #hedef-reklamhesap-group, #hedef-reklamkampanya-group, #hedef-reklamsayfa-group, #hedef-callcenterkampanya-group, #hedef-apilead-group').hide();
                if (y.KullaniciBirimYetkileri_AltBayi_id) {
                    $('#hedef_tur').val('altbayi').trigger('change.select2');
                    $('#hedef-altbayi-group').show();
                    loadAltBayilerSelect(y.KullaniciBirimYetkileri_AltBayi_id);
                } else if (y.KullaniciBirimYetkileri_Personel_id) {
                    $('#hedef_tur').val('personel').trigger('change.select2');
                    $('#hedef-personel-group').show();
                    loadPersonelSelect(y.KullaniciBirimYetkileri_Personel_id);
                } else if (y.KullaniciBirimYetkileri_VoIPHesap_id) {
                    $('#hedef_tur').val('voip').trigger('change.select2');
                    $('#hedef-voip-group').show();
                    loadVoIPSelect(y.KullaniciBirimYetkileri_VoIPHesap_id, y.KullaniciBirimYetkileri_id);
                } else if (y.KullaniciBirimYetkileri_ReklamHesap_id) {
                    $('#hedef_tur').val('reklamhesap').trigger('change.select2');
                    $('#hedef-reklamhesap-group').show();
                    loadReklamHesapSelect(y.KullaniciBirimYetkileri_ReklamHesap_id);
                } else if (y.KullaniciBirimYetkileri_ReklamKampanya_id) {
                    $('#hedef_tur').val('reklamkampanya').trigger('change.select2');
                    $('#hedef-reklamkampanya-group').show();
                    loadReklamKampanyaSelect(y.KullaniciBirimYetkileri_ReklamKampanya_id);
                } else if (y.KullaniciBirimYetkileri_ReklamSayfa_id) {
                    $('#hedef_tur').val('reklamsayfa').trigger('change.select2');
                    $('#hedef-reklamsayfa-group').show();
                    loadReklamSayfaSelect(y.KullaniciBirimYetkileri_ReklamSayfa_id);
                } else if (y.KullaniciBirimYetkileri_CallCenterKampanya_id) {
                    $('#hedef_tur').val('callcenterkampanya').trigger('change.select2');
                    $('#hedef-callcenterkampanya-group').show();
                    loadCallCenterKampanyaSelect(y.KullaniciBirimYetkileri_CallCenterKampanya_id);
                } else {
                    $('#hedef_tur').val('apilead').trigger('change.select2');
                    $('#hedef-apilead-group').show();
                    loadApiLeadSelect(y.KullaniciBirimYetkileri_ApiLead_id);
                }

                yetkiModal.show();
            },
            error: function () { showToast('Kayıt yüklenirken hata oluştu', 'error'); }
        });
    }

    function deleteYetki(yetkiId) {
        confirmAction(
            'Bu yetkiyi silmek istediğinize emin misiniz?',
            'Bu işlem geri alınamaz!',
            function () {
                $.ajax({
                    url: '', method: 'POST',
                    data: { action: 'yetki_delete', yetki_id: yetkiId },
                    dataType: 'json',
                    success: function (r) {
                        if (r.success) {
                            showSuccess('Silindi!', r.message);
                            loadYetkiList();
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
