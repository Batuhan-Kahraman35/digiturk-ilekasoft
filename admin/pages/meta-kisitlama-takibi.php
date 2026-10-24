<?php
/**
 * Admin Panel - Meta Kısıtlama Takibi
 * Reklam hesabı / Facebook sayfası / Instagram vb. askıya alma, fikri mülkiyet şikayeti ve
 * itiraz süreçlerinin takibi + belge arşivi + önleme kontrol listesi.
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Meta Kısıtlama Takibi';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Ek dosya ayarları
define('MK_UPLOAD_DIR', __DIR__ . '/../uploads/meta-kisitlama/');
define('MK_UPLOAD_URL', '/admin/uploads/meta-kisitlama/');
$izinliUzantilar = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx'];
$maxDosyaBoyutu  = 10 * 1024 * 1024; // 10 MB

/** Yüklenen dosyayı kaydeder. Dosya yoksa null, hata varsa string mesaj, başarılıysa [url, ad] döner. */
function mkDosyaYukle(string $alan, string $onek)
{
    global $izinliUzantilar, $maxDosyaBoyutu;
    if (empty($_FILES[$alan]['name']) || $_FILES[$alan]['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES[$alan]['error'] !== UPLOAD_ERR_OK) return 'Dosya yüklenemedi!';

    $ext = strtolower(pathinfo($_FILES[$alan]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $izinliUzantilar)) return 'Geçersiz dosya türü! İzinli: ' . implode(', ', $izinliUzantilar);
    if ($_FILES[$alan]['size'] > $maxDosyaBoyutu) return 'Dosya boyutu 10 MB sınırını aşıyor!';

    if (!is_dir(MK_UPLOAD_DIR)) mkdir(MK_UPLOAD_DIR, 0775, true);
    $ad = $onek . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$alan]['tmp_name'], MK_UPLOAD_DIR . $ad)) return 'Dosya yüklenemedi!';

    return [MK_UPLOAD_URL . $ad, mb_substr($_FILES[$alan]['name'], 0, 200)];
}

/** Boş string → null, aksi halde trim edilmiş değer. */
function mkDeger($v) { $v = trim((string)$v); return $v === '' ? null : $v; }

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $simdi  = date('Y-m-d H:i:s');
    $uid    = $user['kullanici_id'];

    try {
        switch ($action) {

            // ═════════════ OLAYLAR ═════════════
            case 'list':
                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 200) $length = 25;

                $search = trim($_POST['search']['value'] ?? '');
                $where  = ["o.Durum = 1"];
                $params = [];

                if ($search !== '') {
                    $where[] = "(o.MetaKisitlamaOlaylari_MetaSikayetNo LIKE ? OR o.MetaKisitlamaOlaylari_ReklamKodlari LIKE ?
                                 OR o.MetaKisitlamaOlaylari_HakSahibi LIKE ? OR o.MetaKisitlamaOlaylari_VarlikAdi LIKE ?
                                 OR o.MetaKisitlamaOlaylari_Aciklama LIKE ?
                                 OR EXISTS (SELECT 1 FROM ReklamHesaplari xh WHERE xh.ReklamHesaplari_id = o.MetaKisitlamaOlaylari_ReklamHesap_id
                                            AND (xh.ReklamHesaplari_HesapAdi LIKE ? OR xh.ReklamHesaplari_HesapID LIKE ?))
                                 OR EXISTS (SELECT 1 FROM ReklamFacebookSayfalari xs WHERE xs.ReklamFacebookSayfalari_id = o.MetaKisitlamaOlaylari_FacebookSayfa_id
                                            AND (xs.ReklamFacebookSayfalari_SayfaAdi LIKE ? OR xs.ReklamFacebookSayfalari_SayfaID LIKE ?)))";
                    array_push($params, ...array_fill(0, 9, "%$search%"));
                }
                foreach ([
                    'f_varlik_turu' => 'o.MetaKisitlamaOlaylari_VarlikTuru_id',
                    'f_olay_turu'   => 'o.MetaKisitlamaOlaylari_OlayTuru_id',
                    'f_durum'       => 'o.MetaKisitlamaOlaylari_OlayDurumu_id',
                    'f_sorumlu'     => 'o.MetaKisitlamaOlaylari_Sorumlu_id',
                ] as $alan => $kolon) {
                    if (($_POST[$alan] ?? '') !== '') { $where[] = "$kolon = ?"; $params[] = (int)$_POST[$alan]; }
                }
                if (($_POST['f_acik'] ?? '') !== '') {
                    $where[] = "EXISTS (SELECT 1 FROM tanim_MetaOlayDurumlari xd WHERE xd.tanim_MetaOlayDurumlari_id = o.MetaKisitlamaOlaylari_OlayDurumu_id
                                AND xd.tanim_MetaOlayDurumlari_Kapanis = ?)";
                    $params[] = $_POST['f_acik'] === '1' ? 0 : 1;
                }
                if (($_POST['f_kaynak'] ?? '') !== '') { $where[] = "o.MetaKisitlamaOlaylari_Otomatik = ?"; $params[] = (int)$_POST['f_kaynak']; }
                if (!empty($_POST['f_tarih_bas'])) { $where[] = "o.MetaKisitlamaOlaylari_OlayTarihi >= ?"; $params[] = $_POST['f_tarih_bas'] . ' 00:00:00'; }
                if (!empty($_POST['f_tarih_bit'])) { $where[] = "o.MetaKisitlamaOlaylari_OlayTarihi <= ?"; $params[] = $_POST['f_tarih_bit'] . ' 23:59:59'; }

                $wClause = implode(' AND ', $where);

                // Sıralama: kolon index → kolon adı whitelist
                $siralama = [
                    0 => 'o.MetaKisitlamaOlaylari_OlayTarihi',
                    1 => 'COALESCE(h.ReklamHesaplari_HesapAdi, s.ReklamFacebookSayfalari_SayfaAdi, o.MetaKisitlamaOlaylari_VarlikAdi)',
                    2 => 'ot.tanim_MetaOlayTurleri_Adi',
                    3 => 'od.tanim_MetaOlayDurumlari_Sira',
                    4 => 'o.MetaKisitlamaOlaylari_MetaSikayetNo',
                    5 => 'o.MetaKisitlamaOlaylari_HakSahibi',
                    6 => 'o.MetaKisitlamaOlaylari_ItirazTarihi',
                    7 => 'o.MetaKisitlamaOlaylari_SonrakiKontrolTarihi',
                ];
                $orderBy  = $siralama[(int)($_POST['order'][0]['column'] ?? 0)] ?? $siralama[0];
                $orderDir = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

                $recordsTotal    = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM MetaKisitlamaOlaylari o WHERE o.Durum = 1")['c'] ?? 0);
                $recordsFiltered = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM MetaKisitlamaOlaylari o WHERE $wClause", $params)['c'] ?? 0);

                $data = [];
                if ($recordsFiltered > 0) {
                    $joinlar = "
                        LEFT JOIN ReklamHesaplari          h  ON h.ReklamHesaplari_id = o.MetaKisitlamaOlaylari_ReklamHesap_id
                        LEFT JOIN ReklamFacebookSayfalari  s  ON s.ReklamFacebookSayfalari_id = o.MetaKisitlamaOlaylari_FacebookSayfa_id
                        LEFT JOIN tanim_MetaOlayTurleri    ot ON ot.tanim_MetaOlayTurleri_id = o.MetaKisitlamaOlaylari_OlayTuru_id
                        LEFT JOIN tanim_MetaOlayDurumlari  od ON od.tanim_MetaOlayDurumlari_id = o.MetaKisitlamaOlaylari_OlayDurumu_id";
                    $data = $db->fetchAll("
                        WITH Sayfa AS (
                            SELECT o.MetaKisitlamaOlaylari_id
                            FROM MetaKisitlamaOlaylari o
                            $joinlar
                            WHERE $wClause
                            ORDER BY $orderBy $orderDir, o.MetaKisitlamaOlaylari_id DESC
                            OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
                        )
                        SELECT
                            o.MetaKisitlamaOlaylari_id AS id,
                            CONVERT(VARCHAR(16), o.MetaKisitlamaOlaylari_OlayTarihi, 120) AS OlayTarihi,
                            vt.tanim_MetaVarlikTurleri_Adi AS VarlikTuru,
                            vt.tanim_MetaVarlikTurleri_Kod AS VarlikKod,
                            COALESCE(h.ReklamHesaplari_HesapAdi, s.ReklamFacebookSayfalari_SayfaAdi, o.MetaKisitlamaOlaylari_VarlikAdi) AS VarlikAdi,
                            COALESCE(h.ReklamHesaplari_HesapID, s.ReklamFacebookSayfalari_SayfaID) AS VarlikMetaID,
                            ot.tanim_MetaOlayTurleri_Adi    AS OlayTuru,
                            od.tanim_MetaOlayDurumlari_Adi  AS DurumAdi,
                            od.tanim_MetaOlayDurumlari_Renk AS DurumRenk,
                            od.tanim_MetaOlayDurumlari_Kapanis AS Kapanis,
                            o.MetaKisitlamaOlaylari_MetaSikayetNo AS SikayetNo,
                            o.MetaKisitlamaOlaylari_HakSahibi     AS HakSahibi,
                            o.MetaKisitlamaOlaylari_Otomatik      AS Otomatik,
                            CONVERT(VARCHAR(10), o.MetaKisitlamaOlaylari_ItirazTarihi, 120)         AS ItirazTarihi,
                            CONVERT(VARCHAR(10), o.MetaKisitlamaOlaylari_SonrakiKontrolTarihi, 120) AS SonrakiKontrol,
                            CASE WHEN od.tanim_MetaOlayDurumlari_Kapanis = 0
                                      AND o.MetaKisitlamaOlaylari_SonrakiKontrolTarihi < CAST(GETDATE() AS DATE) THEN 1 ELSE 0 END AS KontrolGecti,
                            k.kullanici_ad + ' ' + k.kullanici_soyad AS Sorumlu,
                            hr.HareketAdet
                        FROM Sayfa sy
                        INNER JOIN MetaKisitlamaOlaylari o ON o.MetaKisitlamaOlaylari_id = sy.MetaKisitlamaOlaylari_id
                        $joinlar
                        LEFT JOIN tanim_MetaVarlikTurleri vt ON vt.tanim_MetaVarlikTurleri_id = o.MetaKisitlamaOlaylari_VarlikTuru_id
                        LEFT JOIN kullanicilar k ON k.kullanici_id = o.MetaKisitlamaOlaylari_Sorumlu_id
                        OUTER APPLY (
                            SELECT COUNT(*) AS HareketAdet FROM MetaKisitlamaHareketleri x
                            WHERE x.MetaKisitlamaHareketleri_Olay_id = o.MetaKisitlamaOlaylari_id AND x.Durum = 1
                        ) hr
                        ORDER BY $orderBy $orderDir, o.MetaKisitlamaOlaylari_id DESC
                    ", $params);
                }

                echo json_encode(['draw' => $draw, 'recordsTotal' => $recordsTotal, 'recordsFiltered' => $recordsFiltered, 'data' => $data]);
                break;

            case 'stats':
                $r = $db->fetchOne("
                    SELECT
                        SUM(CASE WHEN d.tanim_MetaOlayDurumlari_Kapanis = 0 THEN 1 ELSE 0 END) AS acik,
                        SUM(CASE WHEN d.tanim_MetaOlayDurumlari_Kapanis = 0 AND o.MetaKisitlamaOlaylari_ItirazTarihi IS NOT NULL THEN 1 ELSE 0 END) AS itirazda,
                        SUM(CASE WHEN d.tanim_MetaOlayDurumlari_Kapanis = 0 AND o.MetaKisitlamaOlaylari_SonrakiKontrolTarihi < CAST(GETDATE() AS DATE) THEN 1 ELSE 0 END) AS kontrol_gecen,
                        SUM(CASE WHEN o.MetaKisitlamaOlaylari_OlayTarihi >= DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1) THEN 1 ELSE 0 END) AS bu_ay
                    FROM MetaKisitlamaOlaylari o
                    INNER JOIN tanim_MetaOlayDurumlari d ON d.tanim_MetaOlayDurumlari_id = o.MetaKisitlamaOlaylari_OlayDurumu_id
                    WHERE o.Durum = 1
                ");
                $belge = $db->fetchOne("
                    SELECT SUM(CASE WHEN MetaBelgeler_GecerlilikBitis < DATEADD(DAY, 30, CAST(GETDATE() AS DATE)) THEN 1 ELSE 0 END) AS biten
                    FROM MetaBelgeler WHERE Durum = 1
                ");
                echo json_encode(['success' => true, 'data' => [
                    'acik'          => (int)($r['acik'] ?? 0),
                    'itirazda'      => (int)($r['itirazda'] ?? 0),
                    'kontrol_gecen' => (int)($r['kontrol_gecen'] ?? 0),
                    'bu_ay'         => (int)($r['bu_ay'] ?? 0),
                    'belge_biten'   => (int)($belge['biten'] ?? 0),
                ]]);
                break;

            case 'olay_get':
                $row = $db->fetchOne("
                    SELECT o.*,
                        CONVERT(VARCHAR(16), o.MetaKisitlamaOlaylari_OlayTarihi, 126)           AS OlayTarihiInput,
                        CONVERT(VARCHAR(10), o.MetaKisitlamaOlaylari_ItirazTarihi, 120)         AS ItirazTarihiInput,
                        CONVERT(VARCHAR(10), o.MetaKisitlamaOlaylari_SonrakiKontrolTarihi, 120) AS SonrakiKontrolInput
                    FROM MetaKisitlamaOlaylari o WHERE o.MetaKisitlamaOlaylari_id = ?
                ", [(int)($_POST['id'] ?? 0)]);
                echo json_encode(['success' => (bool)$row, 'data' => $row]);
                break;

            case 'olay_kaydet':
                $id = (int)($_POST['id'] ?? 0);
                if (($id > 0 && !$permissions['can_edit']) || ($id == 0 && !$permissions['can_add'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }

                $varlikTuru = (int)($_POST['varlik_turu'] ?? 0);
                $kod = $db->fetchOne("SELECT tanim_MetaVarlikTurleri_Kod AS kod FROM tanim_MetaVarlikTurleri WHERE tanim_MetaVarlikTurleri_id = ?", [$varlikTuru])['kod'] ?? '';
                $hesapId = $kod === 'hesap' ? ((int)($_POST['reklam_hesap'] ?? 0) ?: null) : null;
                $sayfaId = $kod === 'sayfa' ? ((int)($_POST['facebook_sayfa'] ?? 0) ?: null) : null;
                $varlikAdi = in_array($kod, ['hesap', 'sayfa']) ? null : mkDeger($_POST['varlik_adi'] ?? '');

                $data = [
                    'MetaKisitlamaOlaylari_VarlikTuru_id'        => $varlikTuru,
                    'MetaKisitlamaOlaylari_ReklamHesap_id'       => $hesapId,
                    'MetaKisitlamaOlaylari_FacebookSayfa_id'     => $sayfaId,
                    'MetaKisitlamaOlaylari_VarlikAdi'            => $varlikAdi,
                    'MetaKisitlamaOlaylari_OlayTuru_id'          => (int)($_POST['olay_turu'] ?? 0),
                    'MetaKisitlamaOlaylari_OlayDurumu_id'        => (int)($_POST['olay_durumu'] ?? 0),
                    'MetaKisitlamaOlaylari_OlayTarihi'           => str_replace('T', ' ', $_POST['olay_tarihi'] ?? ''),
                    'MetaKisitlamaOlaylari_MetaSikayetNo'        => mkDeger($_POST['sikayet_no'] ?? ''),
                    'MetaKisitlamaOlaylari_ReklamKodlari'        => mkDeger($_POST['reklam_kodlari'] ?? ''),
                    'MetaKisitlamaOlaylari_HakSahibi'            => mkDeger($_POST['hak_sahibi'] ?? ''),
                    'MetaKisitlamaOlaylari_HakSahibiEposta'      => mkDeger($_POST['hak_sahibi_eposta'] ?? ''),
                    'MetaKisitlamaOlaylari_IddiaEdilenHak'       => mkDeger($_POST['iddia_edilen_hak'] ?? ''),
                    'MetaKisitlamaOlaylari_Aciklama'             => mkDeger($_POST['aciklama'] ?? ''),
                    'MetaKisitlamaOlaylari_ItirazTarihi'         => mkDeger($_POST['itiraz_tarihi'] ?? ''),
                    'MetaKisitlamaOlaylari_SonrakiKontrolTarihi' => mkDeger($_POST['sonraki_kontrol'] ?? ''),
                    'MetaKisitlamaOlaylari_Sorumlu_id'           => ((int)($_POST['sorumlu'] ?? 0)) ?: null,
                ];

                if (!$kod || !$data['MetaKisitlamaOlaylari_OlayTuru_id'] || !$data['MetaKisitlamaOlaylari_OlayDurumu_id'] || !$data['MetaKisitlamaOlaylari_OlayTarihi']) {
                    echo json_encode(['success' => false, 'message' => 'Varlık türü, olay türü, durum ve tarih zorunludur!']);
                    break;
                }
                if (($kod === 'hesap' && !$hesapId) || ($kod === 'sayfa' && !$sayfaId) || (!in_array($kod, ['hesap', 'sayfa']) && !$varlikAdi)) {
                    echo json_encode(['success' => false, 'message' => 'Varlık seçimi / adı zorunludur!']);
                    break;
                }

                $kapanis = (int)($db->fetchOne("SELECT tanim_MetaOlayDurumlari_Kapanis AS k FROM tanim_MetaOlayDurumlari WHERE tanim_MetaOlayDurumlari_id = ?",
                    [$data['MetaKisitlamaOlaylari_OlayDurumu_id']])['k'] ?? 0);

                if ($id > 0) {
                    $eski = $db->fetchOne("SELECT MetaKisitlamaOlaylari_OlayDurumu_id AS d, MetaKisitlamaOlaylari_KapanisTarihi AS kt FROM MetaKisitlamaOlaylari WHERE MetaKisitlamaOlaylari_id = ?", [$id]);
                    $data['MetaKisitlamaOlaylari_KapanisTarihi'] = $kapanis ? ($eski['kt'] ?? $simdi) : null;
                    $data['GuncelleyenKullanici'] = $uid;
                    $data['GuncellemeTarihi']     = $simdi;
                    $db->update('MetaKisitlamaOlaylari', $data, ['MetaKisitlamaOlaylari_id' => $id]);
                    if ((int)($eski['d'] ?? 0) !== $data['MetaKisitlamaOlaylari_OlayDurumu_id']) {
                        $db->insert('MetaKisitlamaHareketleri', [
                            'MetaKisitlamaHareketleri_Olay_id'       => $id,
                            'MetaKisitlamaHareketleri_Tarih'         => $simdi,
                            'MetaKisitlamaHareketleri_OlayDurumu_id' => $data['MetaKisitlamaOlaylari_OlayDurumu_id'],
                            'MetaKisitlamaHareketleri_Aciklama'      => 'Durum olay düzenleme ekranından değiştirildi.',
                            'OlusturanKullanici' => $uid, 'OlusturmaTarihi' => $simdi,
                        ]);
                    }
                    echo json_encode(['success' => true, 'message' => 'Olay güncellendi']);
                } else {
                    $data['MetaKisitlamaOlaylari_KapanisTarihi'] = $kapanis ? $simdi : null;
                    $data['MetaKisitlamaOlaylari_Otomatik'] = 0;
                    $data['OlusturanKullanici'] = $uid;
                    $data['OlusturmaTarihi']    = $simdi;
                    $yeniId = $db->insert('MetaKisitlamaOlaylari', $data);
                    $db->insert('MetaKisitlamaHareketleri', [
                        'MetaKisitlamaHareketleri_Olay_id'       => $yeniId,
                        'MetaKisitlamaHareketleri_Tarih'         => $data['MetaKisitlamaOlaylari_OlayTarihi'],
                        'MetaKisitlamaHareketleri_OlayDurumu_id' => $data['MetaKisitlamaOlaylari_OlayDurumu_id'],
                        'MetaKisitlamaHareketleri_Aciklama'      => 'Olay kaydedildi.',
                        'OlusturanKullanici' => $uid, 'OlusturmaTarihi' => $simdi,
                    ]);
                    echo json_encode(['success' => true, 'message' => 'Olay eklendi']);
                }
                break;

            case 'olay_sil':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id = (int)($_POST['id'] ?? 0);
                $db->update('MetaKisitlamaOlaylari', ['Durum' => 0, 'GuncelleyenKullanici' => $uid, 'GuncellemeTarihi' => $simdi],
                    ['MetaKisitlamaOlaylari_id' => $id]);
                echo json_encode(['success' => true, 'message' => 'Olay silindi']);
                break;

            case 'detay':
                $id = (int)($_POST['id'] ?? 0);
                $olay = $db->fetchOne("
                    SELECT o.MetaKisitlamaOlaylari_id AS id,
                        vt.tanim_MetaVarlikTurleri_Adi AS VarlikTuru,
                        COALESCE(h.ReklamHesaplari_HesapAdi, s.ReklamFacebookSayfalari_SayfaAdi, o.MetaKisitlamaOlaylari_VarlikAdi) AS VarlikAdi,
                        COALESCE(h.ReklamHesaplari_HesapID, s.ReklamFacebookSayfalari_SayfaID) AS VarlikMetaID,
                        ot.tanim_MetaOlayTurleri_Adi AS OlayTuru,
                        od.tanim_MetaOlayDurumlari_Adi AS DurumAdi, od.tanim_MetaOlayDurumlari_Renk AS DurumRenk,
                        o.MetaKisitlamaOlaylari_OlayDurumu_id AS DurumId,
                        CONVERT(VARCHAR(16), o.MetaKisitlamaOlaylari_OlayTarihi, 120) AS OlayTarihi,
                        o.MetaKisitlamaOlaylari_MetaSikayetNo AS SikayetNo, o.MetaKisitlamaOlaylari_ReklamKodlari AS ReklamKodlari,
                        o.MetaKisitlamaOlaylari_HakSahibi AS HakSahibi, o.MetaKisitlamaOlaylari_HakSahibiEposta AS HakSahibiEposta,
                        o.MetaKisitlamaOlaylari_IddiaEdilenHak AS IddiaEdilenHak, o.MetaKisitlamaOlaylari_MetaKodu AS MetaKodu,
                        o.MetaKisitlamaOlaylari_Aciklama AS Aciklama, o.MetaKisitlamaOlaylari_Otomatik AS Otomatik,
                        CONVERT(VARCHAR(10), o.MetaKisitlamaOlaylari_ItirazTarihi, 120) AS ItirazTarihi,
                        CONVERT(VARCHAR(10), o.MetaKisitlamaOlaylari_SonrakiKontrolTarihi, 120) AS SonrakiKontrol,
                        CONVERT(VARCHAR(16), o.MetaKisitlamaOlaylari_KapanisTarihi, 120) AS KapanisTarihi,
                        k.kullanici_ad + ' ' + k.kullanici_soyad AS Sorumlu
                    FROM MetaKisitlamaOlaylari o
                    LEFT JOIN tanim_MetaVarlikTurleri vt ON vt.tanim_MetaVarlikTurleri_id = o.MetaKisitlamaOlaylari_VarlikTuru_id
                    LEFT JOIN ReklamHesaplari h ON h.ReklamHesaplari_id = o.MetaKisitlamaOlaylari_ReklamHesap_id
                    LEFT JOIN ReklamFacebookSayfalari s ON s.ReklamFacebookSayfalari_id = o.MetaKisitlamaOlaylari_FacebookSayfa_id
                    LEFT JOIN tanim_MetaOlayTurleri ot ON ot.tanim_MetaOlayTurleri_id = o.MetaKisitlamaOlaylari_OlayTuru_id
                    LEFT JOIN tanim_MetaOlayDurumlari od ON od.tanim_MetaOlayDurumlari_id = o.MetaKisitlamaOlaylari_OlayDurumu_id
                    LEFT JOIN kullanicilar k ON k.kullanici_id = o.MetaKisitlamaOlaylari_Sorumlu_id
                    WHERE o.MetaKisitlamaOlaylari_id = ?
                ", [$id]);
                $hareketler = $db->fetchAll("
                    SELECT x.MetaKisitlamaHareketleri_id AS id,
                        CONVERT(VARCHAR(16), x.MetaKisitlamaHareketleri_Tarih, 120) AS Tarih,
                        d.tanim_MetaOlayDurumlari_Adi AS DurumAdi, d.tanim_MetaOlayDurumlari_Renk AS DurumRenk,
                        x.MetaKisitlamaHareketleri_Aciklama AS Aciklama,
                        x.MetaKisitlamaHareketleri_DosyaYolu AS DosyaYolu, x.MetaKisitlamaHareketleri_DosyaAdi AS DosyaAdi,
                        ISNULL(k.kullanici_ad + ' ' + k.kullanici_soyad, 'Sistem') AS Ekleyen
                    FROM MetaKisitlamaHareketleri x
                    LEFT JOIN tanim_MetaOlayDurumlari d ON d.tanim_MetaOlayDurumlari_id = x.MetaKisitlamaHareketleri_OlayDurumu_id
                    LEFT JOIN kullanicilar k ON k.kullanici_id = x.OlusturanKullanici
                    WHERE x.MetaKisitlamaHareketleri_Olay_id = ? AND x.Durum = 1
                    ORDER BY x.MetaKisitlamaHareketleri_Tarih DESC, x.MetaKisitlamaHareketleri_id DESC
                ", [$id]);
                echo json_encode(['success' => (bool)$olay, 'data' => $olay, 'hareketler' => $hareketler]);
                break;

            case 'hareket_ekle':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                $olayId   = (int)($_POST['olay_id'] ?? 0);
                $tarih    = str_replace('T', ' ', $_POST['tarih'] ?? '') ?: $simdi;
                $durumId  = ((int)($_POST['olay_durumu'] ?? 0)) ?: null;
                $aciklama = mkDeger($_POST['aciklama'] ?? '');

                $dosya = mkDosyaYukle('dosya', 'hareket');
                if (is_string($dosya)) { echo json_encode(['success' => false, 'message' => $dosya]); break; }
                if (!$aciklama && !$dosya && !$durumId) {
                    echo json_encode(['success' => false, 'message' => 'Açıklama, dosya veya durum girilmelidir!']);
                    break;
                }

                $db->insert('MetaKisitlamaHareketleri', [
                    'MetaKisitlamaHareketleri_Olay_id'       => $olayId,
                    'MetaKisitlamaHareketleri_Tarih'         => $tarih,
                    'MetaKisitlamaHareketleri_OlayDurumu_id' => $durumId,
                    'MetaKisitlamaHareketleri_Aciklama'      => $aciklama,
                    'MetaKisitlamaHareketleri_DosyaYolu'     => $dosya[0] ?? null,
                    'MetaKisitlamaHareketleri_DosyaAdi'      => $dosya[1] ?? null,
                    'OlusturanKullanici' => $uid, 'OlusturmaTarihi' => $simdi,
                ]);

                // Olaya yansıt: durum, kapanış, itiraz tarihi, sonraki kontrol
                $guncelle = ['GuncelleyenKullanici' => $uid, 'GuncellemeTarihi' => $simdi];
                if ($durumId) {
                    $kapanis = (int)($db->fetchOne("SELECT tanim_MetaOlayDurumlari_Kapanis AS k FROM tanim_MetaOlayDurumlari WHERE tanim_MetaOlayDurumlari_id = ?", [$durumId])['k'] ?? 0);
                    $guncelle['MetaKisitlamaOlaylari_OlayDurumu_id']  = $durumId;
                    $guncelle['MetaKisitlamaOlaylari_KapanisTarihi'] = $kapanis ? $tarih : null;
                }
                if (!empty($_POST['itiraz'])) {
                    $guncelle['MetaKisitlamaOlaylari_ItirazTarihi'] = $tarih;
                }
                if (($sk = mkDeger($_POST['sonraki_kontrol'] ?? '')) !== null) {
                    $guncelle['MetaKisitlamaOlaylari_SonrakiKontrolTarihi'] = $sk;
                }
                $db->update('MetaKisitlamaOlaylari', $guncelle, ['MetaKisitlamaOlaylari_id' => $olayId]);

                echo json_encode(['success' => true, 'message' => 'Hareket eklendi']);
                break;

            case 'hareket_sil':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $db->update('MetaKisitlamaHareketleri', ['Durum' => 0, 'GuncelleyenKullanici' => $uid, 'GuncellemeTarihi' => $simdi],
                    ['MetaKisitlamaHareketleri_id' => (int)($_POST['id'] ?? 0)]);
                echo json_encode(['success' => true, 'message' => 'Hareket silindi']);
                break;

            // ═════════════ BELGE ARŞİVİ ═════════════
            case 'belge_liste':
                $list = $db->fetchAll("
                    SELECT b.MetaBelgeler_id AS id, b.MetaBelgeler_Adi AS Adi, b.MetaBelgeler_Aciklama AS Aciklama,
                        b.MetaBelgeler_DosyaYolu AS DosyaYolu,
                        CONVERT(VARCHAR(10), b.MetaBelgeler_GecerlilikBitis, 120) AS GecerlilikBitis,
                        DATEDIFF(DAY, CAST(GETDATE() AS DATE), b.MetaBelgeler_GecerlilikBitis) AS KalanGun,
                        CONVERT(VARCHAR(16), ISNULL(b.GuncellemeTarihi, b.OlusturmaTarihi), 120) AS Tarih
                    FROM MetaBelgeler b WHERE b.Durum = 1
                    ORDER BY b.MetaBelgeler_Adi
                ");
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'belge_get':
                $row = $db->fetchOne("SELECT *, CONVERT(VARCHAR(10), MetaBelgeler_GecerlilikBitis, 120) AS BitisInput FROM MetaBelgeler WHERE MetaBelgeler_id = ?", [(int)($_POST['id'] ?? 0)]);
                echo json_encode(['success' => (bool)$row, 'data' => $row]);
                break;

            case 'belge_kaydet':
                $id = (int)($_POST['id'] ?? 0);
                if (($id > 0 && !$permissions['can_edit']) || ($id == 0 && !$permissions['can_add'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                $adi = mkDeger($_POST['adi'] ?? '');
                if (!$adi) { echo json_encode(['success' => false, 'message' => 'Belge adı zorunludur!']); break; }

                $dosya = mkDosyaYukle('dosya', 'belge');
                if (is_string($dosya)) { echo json_encode(['success' => false, 'message' => $dosya]); break; }
                if ($id == 0 && !$dosya) { echo json_encode(['success' => false, 'message' => 'Dosya seçilmelidir!']); break; }

                $data = [
                    'MetaBelgeler_Adi'             => $adi,
                    'MetaBelgeler_Aciklama'        => mkDeger($_POST['aciklama'] ?? ''),
                    'MetaBelgeler_GecerlilikBitis' => mkDeger($_POST['gecerlilik_bitis'] ?? ''),
                ];
                if ($dosya) $data['MetaBelgeler_DosyaYolu'] = $dosya[0];

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $uid;
                    $data['GuncellemeTarihi']     = $simdi;
                    $db->update('MetaBelgeler', $data, ['MetaBelgeler_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Belge güncellendi']);
                } else {
                    $data['OlusturanKullanici'] = $uid;
                    $data['OlusturmaTarihi']    = $simdi;
                    $db->insert('MetaBelgeler', $data);
                    echo json_encode(['success' => true, 'message' => 'Belge eklendi']);
                }
                break;

            case 'belge_sil':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $db->update('MetaBelgeler', ['Durum' => 0, 'GuncelleyenKullanici' => $uid, 'GuncellemeTarihi' => $simdi],
                    ['MetaBelgeler_id' => (int)($_POST['id'] ?? 0)]);
                echo json_encode(['success' => true, 'message' => 'Belge silindi']);
                break;

            // ═════════════ KONTROL LİSTESİ ═════════════
            case 'kontrol_liste':
                $list = $db->fetchAll("
                    SELECT MetaKontrolMaddeleri_id AS id, MetaKontrolMaddeleri_Baslik AS Baslik,
                        MetaKontrolMaddeleri_Aciklama AS Aciklama, MetaKontrolMaddeleri_Sira AS Sira, Durum
                    FROM MetaKontrolMaddeleri
                    ORDER BY MetaKontrolMaddeleri_Sira, MetaKontrolMaddeleri_id
                ");
                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'kontrol_get':
                $row = $db->fetchOne("SELECT * FROM MetaKontrolMaddeleri WHERE MetaKontrolMaddeleri_id = ?", [(int)($_POST['id'] ?? 0)]);
                echo json_encode(['success' => (bool)$row, 'data' => $row]);
                break;

            case 'kontrol_kaydet':
                $id = (int)($_POST['id'] ?? 0);
                if (($id > 0 && !$permissions['can_edit']) || ($id == 0 && !$permissions['can_add'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok!']);
                    break;
                }
                $data = [
                    'MetaKontrolMaddeleri_Baslik'   => mkDeger($_POST['baslik'] ?? ''),
                    'MetaKontrolMaddeleri_Aciklama' => mkDeger($_POST['aciklama'] ?? ''),
                    'MetaKontrolMaddeleri_Sira'     => (int)($_POST['sira'] ?? 0),
                    'Durum'                         => isset($_POST['durum']) ? 1 : 0,
                ];
                if (!$data['MetaKontrolMaddeleri_Baslik']) { echo json_encode(['success' => false, 'message' => 'Başlık zorunludur!']); break; }

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $uid;
                    $data['GuncellemeTarihi']     = $simdi;
                    $db->update('MetaKontrolMaddeleri', $data, ['MetaKontrolMaddeleri_id' => $id]);
                    echo json_encode(['success' => true, 'message' => 'Madde güncellendi']);
                } else {
                    $data['OlusturanKullanici'] = $uid;
                    $data['OlusturmaTarihi']    = $simdi;
                    $db->insert('MetaKontrolMaddeleri', $data);
                    echo json_encode(['success' => true, 'message' => 'Madde eklendi']);
                }
                break;

            case 'kontrol_toggle':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                $db->update('MetaKontrolMaddeleri', ['Durum' => (int)($_POST['durum'] ?? 0), 'GuncelleyenKullanici' => $uid, 'GuncellemeTarihi' => $simdi],
                    ['MetaKontrolMaddeleri_id' => (int)($_POST['id'] ?? 0)]);
                echo json_encode(['success' => true]);
                break;

            case 'kontrol_sil':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $db->delete('MetaKontrolMaddeleri', ['MetaKontrolMaddeleri_id' => (int)($_POST['id'] ?? 0)]);
                echo json_encode(['success' => true, 'message' => 'Madde silindi']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// ── Seçenek listeleri (DB'den) ──
$varlikTurleri = $db->fetchAll("SELECT tanim_MetaVarlikTurleri_id AS id, tanim_MetaVarlikTurleri_Adi AS ad, tanim_MetaVarlikTurleri_Kod AS kod FROM tanim_MetaVarlikTurleri WHERE Durum = 1 ORDER BY tanim_MetaVarlikTurleri_Sira");
$olayTurleri   = $db->fetchAll("SELECT tanim_MetaOlayTurleri_id AS id, tanim_MetaOlayTurleri_Adi AS ad FROM tanim_MetaOlayTurleri WHERE Durum = 1 ORDER BY tanim_MetaOlayTurleri_Sira");
$olayDurumlari = $db->fetchAll("SELECT tanim_MetaOlayDurumlari_id AS id, tanim_MetaOlayDurumlari_Adi AS ad FROM tanim_MetaOlayDurumlari WHERE Durum = 1 ORDER BY tanim_MetaOlayDurumlari_Sira");
$hesaplar      = $db->fetchAll("SELECT ReklamHesaplari_id AS id, ReklamHesaplari_HesapAdi AS ad, ReklamHesaplari_HesapID AS ext, Durum FROM ReklamHesaplari ORDER BY Durum DESC, ReklamHesaplari_HesapAdi");
// Tablo web sitelerini de tutuyor; yalnız sayısal Meta sayfa ID'si olanlar
$sayfalar      = $db->fetchAll("
    SELECT ReklamFacebookSayfalari_id AS id, ReklamFacebookSayfalari_SayfaAdi AS ad, ReklamFacebookSayfalari_SayfaID AS ext, Durum
    FROM ReklamFacebookSayfalari
    WHERE ReklamFacebookSayfalari_SayfaID <> '' AND ReklamFacebookSayfalari_SayfaID NOT LIKE '%[^0-9]%'
    ORDER BY Durum DESC, ReklamFacebookSayfalari_SayfaAdi
");
$kullanicilar  = $db->fetchAll("SELECT kullanici_id AS id, kullanici_ad + ' ' + kullanici_soyad AS ad FROM kullanicilar WHERE kullanici_durum = 1 ORDER BY kullanici_ad, kullanici_soyad");

function mkSecenekler(array $liste, bool $ext = false): string
{
    $html = '';
    foreach ($liste as $r) {
        $etiket = $r['ad'] . ($ext && !empty($r['ext']) ? ' (' . $r['ext'] . ')' : '') . (isset($r['Durum']) && !$r['Durum'] ? ' - pasif' : '');
        $kod = isset($r['kod']) ? ' data-kod="' . htmlspecialchars($r['kod']) . '"' : '';
        $html .= '<option value="' . (int)$r['id'] . '"' . $kod . '>' . htmlspecialchars($etiket) . '</option>';
    }
    return $html;
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
        .zaman-cizelgesi { list-style: none; padding-left: 0; margin: 0; }
        .zaman-cizelgesi li { position: relative; padding: 0 0 1rem 1.5rem; border-left: 2px solid var(--bs-border-color); margin-left: .5rem; }
        .zaman-cizelgesi li:last-child { border-left-color: transparent; }
        .zaman-cizelgesi li::before { content: ''; position: absolute; left: -7px; top: .25rem; width: 12px; height: 12px; border-radius: 50%; background: var(--bs-primary); }
        .detay-tablo th { width: 35%; font-weight: 600; color: var(--bs-secondary-color); }
        .kontrol-gecti { color: var(--bs-danger); font-weight: 600; }
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

                <!-- Info Boxes -->
                <div class="row mb-3">
                    <div class="col-md col-sm-6">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-exclamation-octagon"></i></span>
                            <div class="info-box-content"><span class="info-box-text">Açık Olay</span><span class="info-box-number" id="stat-acik">0</span></div>
                        </div>
                    </div>
                    <div class="col-md col-sm-6">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content"><span class="info-box-text">İtirazda Bekleyen</span><span class="info-box-number" id="stat-itirazda">0</span></div>
                        </div>
                    </div>
                    <div class="col-md col-sm-6">
                        <div class="info-box text-bg-dark">
                            <span class="info-box-icon"><i class="bi bi-alarm"></i></span>
                            <div class="info-box-content"><span class="info-box-text">Kontrol Tarihi Geçen</span><span class="info-box-number" id="stat-kontrol">0</span></div>
                        </div>
                    </div>
                    <div class="col-md col-sm-6">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-calendar-month"></i></span>
                            <div class="info-box-content"><span class="info-box-text">Bu Ay Açılan</span><span class="info-box-number" id="stat-buay">0</span></div>
                        </div>
                    </div>
                    <div class="col-md col-sm-6">
                        <div class="info-box text-bg-secondary">
                            <span class="info-box-icon"><i class="bi bi-file-earmark-x"></i></span>
                            <div class="info-box-content"><span class="info-box-text">Süresi Dolan Belge (30 gün)</span><span class="info-box-number" id="stat-belge">0</span></div>
                        </div>
                    </div>
                </div>

                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabOlaylar" type="button"><i class="bi bi-shield-exclamation"></i> Olaylar</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabBelgeler" type="button"><i class="bi bi-folder2-open"></i> Belge Arşivi</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabKontrol" type="button"><i class="bi bi-list-check"></i> Önleme Kontrol Listesi</button></li>
                </ul>

                <div class="tab-content">
                    <!-- ═════ OLAYLAR ═════ -->
                    <div class="tab-pane fade show active" id="tabOlaylar">
                        <div class="card card-primary card-outline mb-3">
                            <div class="card-header">
                                <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-expanded="false"><i class="bi bi-chevron-down"></i></button>
                                </div>
                            </div>
                            <div class="card-body collapse" id="filterCard">
                                <form id="filterForm">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Ara</label>
                                            <input type="text" class="form-control" id="filter_search" placeholder="Varlık, şikayet no, reklam kodu, hak sahibi...">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Varlık Türü</label>
                                            <select class="form-select" id="filter_varlik_turu"><option value="">Tümü</option><?= mkSecenekler($varlikTurleri) ?></select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Olay Türü</label>
                                            <select class="form-select" id="filter_olay_turu"><option value="">Tümü</option><?= mkSecenekler($olayTurleri) ?></select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Durum</label>
                                            <select class="form-select" id="filter_durum"><option value="">Tümü</option><?= mkSecenekler($olayDurumlari) ?></select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Açık / Kapalı</label>
                                            <select class="form-select" id="filter_acik">
                                                <option value="">Tümü</option><option value="1">Açık</option><option value="0">Kapalı</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Kaynak</label>
                                            <select class="form-select" id="filter_kaynak">
                                                <option value="">Tümü</option><option value="1">Otomatik</option><option value="0">Manuel</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Sorumlu</label>
                                            <select class="form-select" id="filter_sorumlu"><option value="">Tümü</option><?= mkSecenekler($kullanicilar) ?></select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Başlangıç</label>
                                            <input type="date" class="form-control" id="filter_tarih_bas">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Bitiş</label>
                                            <input type="date" class="form-control" id="filter_tarih_bit">
                                        </div>
                                        <div class="col-md-12">
                                            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                            <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Kısıtlama ve Şikayet Olayları</h3>
                                <div class="card-tools">
                                    <?php if ($permissions['can_add']): ?>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="yeniOlay()"><i class="bi bi-plus-circle"></i> Yeni Olay</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <table id="olayTable" class="table table-bordered table-striped table-hover w-100">
                                    <thead>
                                        <tr>
                                            <th>Tarih</th>
                                            <th>Varlık</th>
                                            <th>Olay Türü</th>
                                            <th>Durum</th>
                                            <th>Şikayet No</th>
                                            <th>Hak Sahibi</th>
                                            <th>İtiraz</th>
                                            <th>Sonraki Kontrol</th>
                                            <th>Sorumlu</th>
                                            <th style="width:120px">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- ═════ BELGE ARŞİVİ ═════ -->
                    <div class="tab-pane fade" id="tabBelgeler">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Yetki Belgesi, Sözleşme ve Marka İzinleri</h3>
                                <div class="card-tools">
                                    <?php if ($permissions['can_add']): ?>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="yeniBelge()"><i class="bi bi-upload"></i> Belge Yükle</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <table id="belgeTable" class="table table-bordered table-striped table-hover w-100">
                                    <thead><tr><th>Belge</th><th>Açıklama</th><th>Geçerlilik</th><th>Güncelleme</th><th style="width:130px">İşlemler</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- ═════ KONTROL LİSTESİ ═════ -->
                    <div class="tab-pane fade" id="tabKontrol">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Reklam ve Sayfa Yayına Çıkmadan Önce</h3>
                                <div class="card-tools">
                                    <?php if ($permissions['can_add']): ?>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="yeniKontrol()"><i class="bi bi-plus-circle"></i> Madde Ekle</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <table id="kontrolTable" class="table table-bordered table-striped table-hover w-100">
                                    <thead><tr><th style="width:60px">Sıra</th><th>Madde</th><th>Açıklama</th><th style="width:80px">Aktif</th><th style="width:100px">İşlemler</th></tr></thead>
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

<!-- Olay Modal -->
<div class="modal fade" id="olayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="olayModalLabel">Yeni Olay</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="olayForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="olay_id">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Varlık Türü <span class="text-danger">*</span></label>
                            <select class="form-select" name="varlik_turu" id="varlik_turu" required><?= mkSecenekler($varlikTurleri) ?></select>
                        </div>
                        <div class="col-md-8 varlik-alan" data-kod="hesap">
                            <label class="form-label">Reklam Hesabı <span class="text-danger">*</span></label>
                            <select class="form-select" name="reklam_hesap" id="reklam_hesap"><option value="">Seçiniz</option><?= mkSecenekler($hesaplar, true) ?></select>
                        </div>
                        <div class="col-md-8 varlik-alan" data-kod="sayfa">
                            <label class="form-label">Facebook Sayfası <span class="text-danger">*</span></label>
                            <select class="form-select" name="facebook_sayfa" id="facebook_sayfa"><option value="">Seçiniz</option><?= mkSecenekler($sayfalar, true) ?></select>
                        </div>
                        <div class="col-md-8 varlik-alan" data-kod="diger">
                            <label class="form-label">Varlık Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="varlik_adi" id="varlik_adi" placeholder="Ör: @evinternetim (Instagram)">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Olay Türü <span class="text-danger">*</span></label>
                            <select class="form-select" name="olay_turu" id="olay_turu" required><?= mkSecenekler($olayTurleri) ?></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Durum <span class="text-danger">*</span></label>
                            <select class="form-select" name="olay_durumu" id="olay_durumu" required><?= mkSecenekler($olayDurumlari) ?></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Olay Tarihi <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="olay_tarihi" id="olay_tarihi" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Meta Şikayet No</label>
                            <input type="text" class="form-control" name="sikayet_no" id="sikayet_no">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Şikayet Edilen Reklam Kodları</label>
                            <input type="text" class="form-control" name="reklam_kodlari" id="reklam_kodlari" placeholder="Virgülle ayırın">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Hak Sahibi</label>
                            <input type="text" class="form-control" name="hak_sahibi" id="hak_sahibi">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Hak Sahibi E-posta</label>
                            <input type="email" class="form-control" name="hak_sahibi_eposta" id="hak_sahibi_eposta">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">İddia Edilen Hak</label>
                            <input type="text" class="form-control" name="iddia_edilen_hak" id="iddia_edilen_hak" placeholder="Ticari Marka, Telif...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">İtiraz Tarihi</label>
                            <input type="date" class="form-control" name="itiraz_tarihi" id="itiraz_tarihi">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Sonraki Kontrol</label>
                            <input type="date" class="form-control" name="sonraki_kontrol" id="sonraki_kontrol">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Sorumlu</label>
                            <select class="form-select" name="sorumlu" id="sorumlu"><option value="">Seçiniz</option><?= mkSecenekler($kullanicilar) ?></select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" name="aciklama" id="aciklama" rows="3"></textarea>
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

<!-- Detay Modal -->
<div class="modal fade" id="detayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detayModalLabel">Olay Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    <div class="col-lg-5">
                        <table class="table table-sm detay-tablo mb-0"><tbody id="detayBilgi"></tbody></table>
                    </div>
                    <div class="col-lg-7">
                        <?php if ($permissions['can_edit']): ?>
                        <form id="hareketForm" class="card card-body bg-body-tertiary mb-3">
                            <input type="hidden" name="olay_id" id="hareket_olay_id">
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label">Tarih</label>
                                    <input type="datetime-local" class="form-control form-control-sm" name="tarih" id="hareket_tarih">
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label">Yeni Durum</label>
                                    <select class="form-select" name="olay_durumu" id="hareket_durum"><option value="">Değiştirme</option><?= mkSecenekler($olayDurumlari) ?></select>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Açıklama</label>
                                    <textarea class="form-control form-control-sm" name="aciklama" rows="2" placeholder="Ör: Meta formundan itiraz gönderildi, yetki belgesi eklendi."></textarea>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Dosya</label>
                                    <input type="file" class="form-control form-control-sm" name="dosya" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Sonraki Kontrol</label>
                                    <input type="date" class="form-control form-control-sm" name="sonraki_kontrol">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="hareket_itiraz" name="itiraz" value="1">
                                        <label class="form-check-label" for="hareket_itiraz">İtiraz kaydı</label>
                                    </div>
                                </div>
                                <div class="col-md-12 text-end">
                                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> Hareket Ekle</button>
                                </div>
                            </div>
                        </form>
                        <?php endif; ?>
                        <h6 class="mb-3"><i class="bi bi-clock-history"></i> Zaman Çizelgesi</h6>
                        <ul class="zaman-cizelgesi" id="detayHareketler"></ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Belge Modal -->
<div class="modal fade" id="belgeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="belgeModalLabel">Belge Yükle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="belgeForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="belge_id">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Belge Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="adi" id="belge_adi" required placeholder="Ör: Digiturk Yetkili Satıcı Belgesi 2026">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" name="aciklama" id="belge_aciklama" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Geçerlilik Bitişi</label>
                            <input type="date" class="form-control" name="gecerlilik_bitis" id="belge_bitis">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Dosya <span class="text-danger" id="belgeDosyaZorunlu">*</span></label>
                            <input type="file" class="form-control" name="dosya" id="belge_dosya" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx">
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

<!-- Kontrol Maddesi Modal -->
<div class="modal fade" id="kontrolModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kontrolModalLabel">Madde Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kontrolForm">
                <div class="modal-body">
                    <input type="hidden" name="id" id="kontrol_id">
                    <div class="row g-3">
                        <div class="col-md-9">
                            <label class="form-label">Başlık <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="baslik" id="kontrol_baslik" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sıra</label>
                            <input type="number" class="form-control" name="sira" id="kontrol_sira" value="0">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" name="aciklama" id="kontrol_aciklama" rows="2"></textarea>
                        </div>
                        <div class="col-md-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="kontrol_durum" name="durum" value="1" checked>
                                <label class="form-check-label" for="kontrol_durum">Aktif</label>
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

    let olayModal, detayModal, belgeModal, kontrolModal, olayTable, belgeTable, kontrolTable, aktifOlayId = 0;

    $(document).ready(function () {
        olayModal    = new bootstrap.Modal(document.getElementById('olayModal'));
        detayModal   = new bootstrap.Modal(document.getElementById('detayModal'));
        belgeModal   = new bootstrap.Modal(document.getElementById('belgeModal'));
        kontrolModal = new bootstrap.Modal(document.getElementById('kontrolModal'));

        // Modal select'leri: custom.js init'ini kaldırıp dropdownParent ile yeniden kur
        [['#varlik_turu,#reklam_hesap,#facebook_sayfa,#olay_turu,#olay_durumu,#sorumlu', '#olayModal'],
         ['#hareket_durum', '#detayModal']].forEach(([sec, modal]) => {
            $(sec).each(function () {
                if ($(this).hasClass('select2-hidden-accessible')) $(this).select2('destroy');
                $(this).select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $(modal) });
            });
        });

        olayTable = $('#olayTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            processing: true,
            serverSide: true,
            autoWidth: false,
            scrollX: true,
            dom: 'lrtip',
            order: [[0, 'desc']],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            ajax: {
                url: '', type: 'POST',
                data: function (d) {
                    d.action        = 'list';
                    d.search.value  = $('#filter_search').val() || '';
                    d.f_varlik_turu = $('#filter_varlik_turu').val() || '';
                    d.f_olay_turu   = $('#filter_olay_turu').val() || '';
                    d.f_durum       = $('#filter_durum').val() || '';
                    d.f_acik        = $('#filter_acik').val() || '';
                    d.f_kaynak      = $('#filter_kaynak').val() || '';
                    d.f_sorumlu     = $('#filter_sorumlu').val() || '';
                    d.f_tarih_bas   = $('#filter_tarih_bas').val() || '';
                    d.f_tarih_bit   = $('#filter_tarih_bit').val() || '';
                    return d;
                },
                error: function () { showToast('Liste yüklenirken hata oluştu', 'error'); }
            },
            columns: [
                { data: 'OlayTarihi', render: v => escapeHtml(v || '-') },
                { data: 'VarlikAdi', render: (v, t, r) =>
                    `<div class="fw-semibold">${escapeHtml(v || '-')}</div>
                     <small class="text-muted">${escapeHtml(r.VarlikTuru || '')}${r.VarlikMetaID ? ' · ' + escapeHtml(r.VarlikMetaID) : ''}</small>
                     ${r.Otomatik == 1 ? '<span class="badge text-bg-info ms-1" title="Cron tarafından açıldı">Oto</span>' : ''}` },
                { data: 'OlayTuru', render: v => escapeHtml(v || '-') },
                { data: 'DurumAdi', render: (v, t, r) => `<span class="badge text-bg-${escapeHtml(r.DurumRenk || 'secondary')}">${escapeHtml(v || '-')}</span>` },
                { data: 'SikayetNo', render: v => escapeHtml(v || '-') },
                { data: 'HakSahibi', render: v => escapeHtml(v || '-') },
                { data: 'ItirazTarihi', render: v => escapeHtml(v || '-') },
                { data: 'SonrakiKontrol', render: (v, t, r) => v
                    ? `<span class="${r.KontrolGecti == 1 ? 'kontrol-gecti' : ''}">${escapeHtml(v)}${r.KontrolGecti == 1 ? ' <i class="bi bi-exclamation-triangle"></i>' : ''}</span>` : '-' },
                { data: 'Sorumlu', render: v => escapeHtml(v || '-') },
                { data: null, orderable: false, render: (v, t, r) => {
                    let b = `<button class="btn btn-sm btn-info me-1" onclick="detayAc(${r.id})" title="Detay / Hareketler"><i class="bi bi-clock-history"></i> ${r.HareketAdet || 0}</button>`;
                    if (permissions.canEdit)   b += `<button class="btn btn-sm btn-warning me-1" onclick="olayDuzenle(${r.id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
                    if (permissions.canDelete) b += `<button class="btn btn-sm btn-danger" onclick="olaySil(${r.id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                    return b;
                } }
            ]
        });
        olayTable.on('xhr', loadStats);

        belgeTable = $('#belgeTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc']], columnDefs: [{ orderable: false, targets: [4] }], pageLength: 25
        });
        kontrolTable = $('#kontrolTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order: [[0, 'asc']], columnDefs: [{ orderable: false, targets: [3, 4] }], pageLength: 50
        });
        belgeListe();
        kontrolListe();

        // Gizli sekmede kurulan tabloların kolon genişliklerini düzelt
        $('button[data-bs-toggle="tab"]').on('shown.bs.tab', () => $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust());

        $('#filterForm').on('submit', function (e) { e.preventDefault(); olayTable.ajax.reload(); showToast('Filtre uygulandı', 'info'); });
        $('#clearFilters').on('click', function () {
            $('#filterForm')[0].reset();
            $('#filterForm .form-select').val('').trigger('change');
            olayTable.ajax.reload();
            showToast('Filtreler temizlendi', 'info');
        });

        $('#varlik_turu').on('change', varlikAlanGuncelle);
        $('#olayForm').on('submit', function (e) { e.preventDefault(); formGonder(this, 'olay_kaydet', () => { olayModal.hide(); olayTable.ajax.reload(null, false); }); });
        $('#hareketForm').on('submit', function (e) {
            e.preventDefault();
            formGonder(this, 'hareket_ekle', () => { detayAc(aktifOlayId); olayTable.ajax.reload(null, false); });
        });
        $('#belgeForm').on('submit', function (e) { e.preventDefault(); formGonder(this, 'belge_kaydet', () => { belgeModal.hide(); belgeListe(); loadStats(); }); });
        $('#kontrolForm').on('submit', function (e) { e.preventDefault(); formGonder(this, 'kontrol_kaydet', () => { kontrolModal.hide(); kontrolListe(); }); });
    });

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function simdiInput() {
        const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
        return d.toISOString().slice(0, 16);
    }

    function formGonder(form, action, basarili) {
        const fd = new FormData(form);
        fd.append('action', action);
        $.ajax({
            url: '', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function (r) {
                if (r.success) { showToast(r.message, 'success'); basarili(); }
                else showToast(r.message, 'error');
            },
            error: function () { showToast('Kayıt sırasında hata oluştu', 'error'); }
        });
    }

    function postSil(action, id, soru, basarili) {
        confirmAction(soru, 'Bu işlem geri alınamaz!', function () {
            $.post('', { action: action, id: id }, function (r) {
                if (r.success) { showSuccess('Silindi!', r.message); basarili(); }
                else showError('Hata!', r.message);
            }, 'json').fail(() => showError('Bağlantı Hatası!', 'Sunucuya ulaşılamıyor.'));
        });
    }

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-acik').text(r.data.acik);
            $('#stat-itirazda').text(r.data.itirazda);
            $('#stat-kontrol').text(r.data.kontrol_gecen);
            $('#stat-buay').text(r.data.bu_ay);
            $('#stat-belge').text(r.data.belge_biten);
        }, 'json');
    }

    // ═════ Olaylar ═════
    function varlikAlanGuncelle() {
        let kod = $('#varlik_turu option:selected').data('kod');
        if (kod !== 'hesap' && kod !== 'sayfa') kod = 'diger';
        $('.varlik-alan').addClass('d-none');
        $(`.varlik-alan[data-kod="${kod}"]`).removeClass('d-none');
    }

    function yeniOlay() {
        const f = document.getElementById('olayForm');
        f.reset();
        $('#olay_id').val('');
        $('#olayForm .form-select').each(function () { $(this).val($(this).find('option:first').val()).trigger('change'); });
        $('#olay_tarihi').val(simdiInput());
        $('#olayModalLabel').text('Yeni Olay');
        varlikAlanGuncelle();
        olayModal.show();
    }

    function olayDuzenle(id) {
        $.post('', { action: 'olay_get', id: id }, function (r) {
            if (!r.success) { showToast('Kayıt bulunamadı', 'error'); return; }
            const d = r.data;
            $('#olay_id').val(d.MetaKisitlamaOlaylari_id);
            $('#varlik_turu').val(String(d.MetaKisitlamaOlaylari_VarlikTuru_id)).trigger('change');
            $('#reklam_hesap').val(d.MetaKisitlamaOlaylari_ReklamHesap_id ? String(d.MetaKisitlamaOlaylari_ReklamHesap_id) : '').trigger('change');
            $('#facebook_sayfa').val(d.MetaKisitlamaOlaylari_FacebookSayfa_id ? String(d.MetaKisitlamaOlaylari_FacebookSayfa_id) : '').trigger('change');
            $('#varlik_adi').val(d.MetaKisitlamaOlaylari_VarlikAdi || '');
            $('#olay_turu').val(String(d.MetaKisitlamaOlaylari_OlayTuru_id)).trigger('change');
            $('#olay_durumu').val(String(d.MetaKisitlamaOlaylari_OlayDurumu_id)).trigger('change');
            $('#sorumlu').val(d.MetaKisitlamaOlaylari_Sorumlu_id ? String(d.MetaKisitlamaOlaylari_Sorumlu_id) : '').trigger('change');
            $('#olay_tarihi').val(d.OlayTarihiInput || '');
            $('#sikayet_no').val(d.MetaKisitlamaOlaylari_MetaSikayetNo || '');
            $('#reklam_kodlari').val(d.MetaKisitlamaOlaylari_ReklamKodlari || '');
            $('#hak_sahibi').val(d.MetaKisitlamaOlaylari_HakSahibi || '');
            $('#hak_sahibi_eposta').val(d.MetaKisitlamaOlaylari_HakSahibiEposta || '');
            $('#iddia_edilen_hak').val(d.MetaKisitlamaOlaylari_IddiaEdilenHak || '');
            $('#itiraz_tarihi').val(d.ItirazTarihiInput || '');
            $('#sonraki_kontrol').val(d.SonrakiKontrolInput || '');
            $('#aciklama').val(d.MetaKisitlamaOlaylari_Aciklama || '');
            $('#olayModalLabel').text('Olay Düzenle');
            varlikAlanGuncelle();
            olayModal.show();
        }, 'json');
    }

    function olaySil(id) {
        postSil('olay_sil', id, 'Bu olayı silmek istediğinize emin misiniz?', () => olayTable.ajax.reload(null, false));
    }

    function detayAc(id) {
        aktifOlayId = id;
        $.post('', { action: 'detay', id: id }, function (r) {
            if (!r.success) { showToast('Kayıt bulunamadı', 'error'); return; }
            const d = r.data;
            const satir = (k, v) => v ? `<tr><th>${k}</th><td>${v}</td></tr>` : '';
            $('#detayModalLabel').text(`${d.VarlikAdi || 'Olay'} · ${d.OlayTuru || ''}`);
            $('#detayBilgi').html(
                satir('Varlık', `${escapeHtml(d.VarlikAdi)}<br><small class="text-muted">${escapeHtml(d.VarlikTuru)}${d.VarlikMetaID ? ' · ' + escapeHtml(d.VarlikMetaID) : ''}</small>`) +
                satir('Durum', `<span class="badge text-bg-${escapeHtml(d.DurumRenk || 'secondary')}">${escapeHtml(d.DurumAdi)}</span>${d.Otomatik == 1 ? ' <span class="badge text-bg-info">Otomatik</span>' : ''}`) +
                satir('Olay Tarihi', escapeHtml(d.OlayTarihi)) +
                satir('Şikayet No', escapeHtml(d.SikayetNo)) +
                satir('Reklam Kodları', escapeHtml(d.ReklamKodlari)) +
                satir('Hak Sahibi', escapeHtml(d.HakSahibi)) +
                satir('Hak Sahibi E-posta', d.HakSahibiEposta ? `<a href="mailto:${escapeHtml(d.HakSahibiEposta)}">${escapeHtml(d.HakSahibiEposta)}</a>` : '') +
                satir('İddia Edilen Hak', escapeHtml(d.IddiaEdilenHak)) +
                satir('Meta Kodu', escapeHtml(d.MetaKodu)) +
                satir('İtiraz Tarihi', escapeHtml(d.ItirazTarihi)) +
                satir('Sonraki Kontrol', escapeHtml(d.SonrakiKontrol)) +
                satir('Kapanış', escapeHtml(d.KapanisTarihi)) +
                satir('Sorumlu', escapeHtml(d.Sorumlu)) +
                satir('Açıklama', escapeHtml(d.Aciklama).replace(/\n/g, '<br>'))
            );

            $('#detayHareketler').html(r.hareketler.length ? r.hareketler.map(h => `
                <li>
                    <div class="d-flex justify-content-between">
                        <strong>${escapeHtml(h.Tarih)}</strong>
                        <span>
                            ${h.DurumAdi ? `<span class="badge text-bg-${escapeHtml(h.DurumRenk || 'secondary')}">${escapeHtml(h.DurumAdi)}</span>` : ''}
                            ${permissions.canDelete ? `<button class="btn btn-sm btn-link text-danger p-0 ms-2" onclick="hareketSil(${h.id})" title="Sil"><i class="bi bi-trash"></i></button>` : ''}
                        </span>
                    </div>
                    ${h.Aciklama ? `<div>${escapeHtml(h.Aciklama).replace(/\n/g, '<br>')}</div>` : ''}
                    ${h.DosyaYolu ? `<a href="${escapeHtml(h.DosyaYolu)}" target="_blank"><i class="bi bi-paperclip"></i> ${escapeHtml(h.DosyaAdi || 'Ek dosya')}</a>` : ''}
                    <small class="text-muted d-block">${escapeHtml(h.Ekleyen)}</small>
                </li>`).join('') : '<li class="text-muted">Hareket yok</li>');

            const hf = document.getElementById('hareketForm');
            if (hf) {
                hf.reset();
                $('#hareket_olay_id').val(id);
                $('#hareket_tarih').val(simdiInput());
                $('#hareket_durum').val('').trigger('change');
            }
            detayModal.show();
        }, 'json');
    }

    function hareketSil(id) {
        postSil('hareket_sil', id, 'Bu hareketi silmek istediğinize emin misiniz?', () => { detayAc(aktifOlayId); olayTable.ajax.reload(null, false); });
    }

    // ═════ Belge Arşivi ═════
    function belgeListe() {
        $.post('', { action: 'belge_liste' }, function (r) {
            if (!r.success) { showToast('Belgeler yüklenemedi', 'error'); return; }
            belgeTable.clear();
            r.data.forEach(b => {
                let gecerlilik = '<span class="text-muted">Süresiz</span>';
                if (b.GecerlilikBitis) {
                    const renk = b.KalanGun < 0 ? 'danger' : (b.KalanGun <= 30 ? 'warning' : 'success');
                    const not  = b.KalanGun < 0 ? 'Süresi doldu' : `${b.KalanGun} gün`;
                    gecerlilik = `${escapeHtml(b.GecerlilikBitis)} <span class="badge text-bg-${renk}">${not}</span>`;
                }
                let islem = `<a class="btn btn-sm btn-success me-1" href="${escapeHtml(b.DosyaYolu)}" target="_blank" title="İndir"><i class="bi bi-download"></i></a>`;
                if (permissions.canEdit)   islem += `<button class="btn btn-sm btn-warning me-1" onclick="belgeDuzenle(${b.id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
                if (permissions.canDelete) islem += `<button class="btn btn-sm btn-danger" onclick="belgeSil(${b.id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                belgeTable.row.add([escapeHtml(b.Adi), escapeHtml(b.Aciklama || '-'), gecerlilik, escapeHtml(b.Tarih || '-'), islem]);
            });
            belgeTable.draw();
        }, 'json');
    }

    function yeniBelge() {
        document.getElementById('belgeForm').reset();
        $('#belge_id').val('');
        $('#belgeDosyaZorunlu').show();
        $('#belgeModalLabel').text('Belge Yükle');
        belgeModal.show();
    }

    function belgeDuzenle(id) {
        $.post('', { action: 'belge_get', id: id }, function (r) {
            if (!r.success) { showToast('Kayıt bulunamadı', 'error'); return; }
            document.getElementById('belgeForm').reset();
            $('#belge_id').val(r.data.MetaBelgeler_id);
            $('#belge_adi').val(r.data.MetaBelgeler_Adi || '');
            $('#belge_aciklama').val(r.data.MetaBelgeler_Aciklama || '');
            $('#belge_bitis').val(r.data.BitisInput || '');
            $('#belgeDosyaZorunlu').hide();
            $('#belgeModalLabel').text('Belge Düzenle');
            belgeModal.show();
        }, 'json');
    }

    function belgeSil(id) {
        postSil('belge_sil', id, 'Bu belgeyi silmek istediğinize emin misiniz?', () => { belgeListe(); loadStats(); });
    }

    // ═════ Kontrol Listesi ═════
    function kontrolListe() {
        $.post('', { action: 'kontrol_liste' }, function (r) {
            if (!r.success) { showToast('Kontrol listesi yüklenemedi', 'error'); return; }
            kontrolTable.clear();
            r.data.forEach(k => {
                const sw = `<div class="form-check form-switch d-flex justify-content-center">
                        <input class="form-check-input" type="checkbox" role="switch" id="kd_${k.id}" ${k.Durum == 1 ? 'checked' : ''}
                               ${permissions.canEdit ? '' : 'disabled'} onchange="kontrolToggle(${k.id}, this.checked ? 1 : 0)">
                        <label class="form-check-label" for="kd_${k.id}"></label>
                    </div>`;
                let islem = '';
                if (permissions.canEdit)   islem += `<button class="btn btn-sm btn-warning me-1" onclick="kontrolDuzenle(${k.id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
                if (permissions.canDelete) islem += `<button class="btn btn-sm btn-danger" onclick="kontrolSil(${k.id})" title="Sil"><i class="bi bi-trash"></i></button>`;
                kontrolTable.row.add([k.Sira, escapeHtml(k.Baslik), escapeHtml(k.Aciklama || '-'), sw, islem || '-']);
            });
            kontrolTable.draw();
        }, 'json');
    }

    function yeniKontrol() {
        document.getElementById('kontrolForm').reset();
        $('#kontrol_id').val('');
        $('#kontrol_durum').prop('checked', true);
        $('#kontrolModalLabel').text('Madde Ekle');
        kontrolModal.show();
    }

    function kontrolDuzenle(id) {
        $.post('', { action: 'kontrol_get', id: id }, function (r) {
            if (!r.success) { showToast('Kayıt bulunamadı', 'error'); return; }
            $('#kontrol_id').val(r.data.MetaKontrolMaddeleri_id);
            $('#kontrol_baslik').val(r.data.MetaKontrolMaddeleri_Baslik || '');
            $('#kontrol_aciklama').val(r.data.MetaKontrolMaddeleri_Aciklama || '');
            $('#kontrol_sira').val(r.data.MetaKontrolMaddeleri_Sira || 0);
            $('#kontrol_durum').prop('checked', r.data.Durum == 1);
            $('#kontrolModalLabel').text('Madde Düzenle');
            kontrolModal.show();
        }, 'json');
    }

    function kontrolToggle(id, durum) {
        $.post('', { action: 'kontrol_toggle', id: id, durum: durum }, function (r) {
            if (r.success) showToast(durum ? 'Madde aktif edildi' : 'Madde pasif edildi', 'success');
            else { showToast(r.message || 'Durum değiştirilemedi', 'error'); kontrolListe(); }
        }, 'json');
    }

    function kontrolSil(id) {
        postSil('kontrol_sil', id, 'Bu maddeyi silmek istediğinize emin misiniz?', kontrolListe);
    }
</script>
</body>
</html>
