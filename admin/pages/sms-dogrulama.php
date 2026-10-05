<?php
/**
 * Admin Panel - SMS Doğrulama (Digiturk OTP)
 * Tablo: Basvurular (OTP kolonları)  |  Entegrasyon: Entegrasyonlar Tip='OTP'
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/EntegrasyonHelper.php';
require_once __DIR__ . '/../includes/KaraListeHelper.php';
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

$pageTitle       = $pageinfo['sayfalar_sayfa_adi'] ?? 'SMS Doğrulama';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Aktif OTP kanalı + kanal tipleri (processType) — hepsi DB'den
$otpKanal = $db->fetchOne("
    SELECT TOP 1 k.EntegrasyonKanallari_id
    FROM EntegrasyonKanallari k
    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
    WHERE e.Entegrasyonlar_Tip = 'OTP' AND k.Durum = 1 AND e.Durum = 1
    ORDER BY k.EntegrasyonKanallari_id
");
$otpKanalId = (int)($otpKanal['EntegrasyonKanallari_id'] ?? 0);

$kanalTipleri = $db->fetchAll("
    SELECT t.EntegrasyonKanalTipleri_id, t.EntegrasyonKanalTipleri_Kod, t.EntegrasyonKanalTipleri_Ad
    FROM EntegrasyonKanalTipleri t
    INNER JOIN Entegrasyonlar e ON t.EntegrasyonKanalTipleri_Entegrasyon_id = e.Entegrasyonlar_id
    WHERE e.Entegrasyonlar_Tip = 'OTP' AND t.Durum = 1
    ORDER BY t.EntegrasyonKanalTipleri_Kod
");

/** Basvurular satırından GSM'i (90XXXXXXXXXX) kurar. */
function otpGsmKur(array $b): string {
    return preg_replace('/\D/', '',
        ($b['phoneCountryNumber'] ?? '') . ($b['phoneAreaNumber'] ?? '') . ($b['phoneNumber'] ?? ''));
}

/** Helper durum → Basvurular_OtpDurum eşlemesi. */
function otpDurumEsle(string $durum): string {
    return $durum === 'onayli' ? 'onayli' : 'beklemede'; // yeni/beklemede → beklemede
}

/** Herhangi bir formattaki numarayı 905XXXXXXXXX'e (12 hane) çevirir. Geçersizse false. */
function otpNumaraNormalize(string $line) {
    $d = preg_replace('/\D/', '', $line);
    if ($d === '') return false;
    if (strlen($d) === 14 && substr($d, 0, 4) === '0090')     $d = substr($d, 2); // 0090... → 90...
    elseif (strlen($d) === 13 && substr($d, 0, 3) === '090')  $d = substr($d, 1); // 090...  → 90...
    if (strlen($d) === 10)                                    $d = '90' . $d;             // 5XXXXXXXXX
    elseif (strlen($d) === 11 && $d[0] === '0')               $d = '90' . substr($d, 1);  // 05XXXXXXXXX
    return (strlen($d) === 12 && substr($d, 0, 2) === '90') ? $d : false;
}

// =====================================================================
// BİRİM BAZLI YETKİ KISITI (basvuru-yonetimi.php mantığı)
// =====================================================================
$kullaniciId    = $user['kullanici_id'];
$birimKisitli   = (!$permissions['is_admin'] && !empty($permissions['can_view_birim']));
$izinliBirimler = [];

if ($birimKisitli) {
    $kb = $db->fetchOne("SELECT kullanici_birim_id FROM kullanicilar WHERE kullanici_id = ?", [$kullaniciId]);
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

/** Başvurunun (alias b) birim silsilesine göre yetki kısıt fragmanı. */
function otpBirimKisitWhere(array $izinliBirimler, string $leadIdExpr, string $perIdExpr, string $altBayiIdExpr, string $leadFormIdExpr, string $olusturanExpr = ''): array {
    $ph = implode(',', array_fill(0, count($izinliBirimler), '?'));
    $tarih = "kby.Durum = 1
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())";
    $sql = "(
        EXISTS (SELECT 1 FROM KullaniciBirimYetkileri kby
            WHERE kby.KullaniciBirimYetkileri_ApiLead_id = $leadIdExpr AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph) AND $tarih)
        OR EXISTS (SELECT 1 FROM KullaniciBirimYetkileri kby
            WHERE kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
              AND (kby.KullaniciBirimYetkileri_Personel_id = $perIdExpr OR kby.KullaniciBirimYetkileri_AltBayi_id = $altBayiIdExpr) AND $tarih)
        OR EXISTS (SELECT 1 FROM ReklamLeadFormlari f
            INNER JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_ReklamSayfa_id = f.ReklamLeadFormlari_Sayfa_id
            WHERE f.ReklamLeadFormlari_id = $leadFormIdExpr AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph) AND $tarih)";
    $params = array_merge($izinliBirimler, $izinliBirimler, $izinliBirimler);
    if ($olusturanExpr !== '') {
        // Bağ yoksa (Personel/ApiLead/ReklamForm hepsi NULL) → oluşturan kullanıcının birimi (OTP toplu kayıtları)
        $sql .= "
        OR ($perIdExpr IS NULL AND $leadIdExpr IS NULL AND $leadFormIdExpr IS NULL
            AND EXISTS (SELECT 1 FROM kullanicilar kuc WHERE kuc.kullanici_id = $olusturanExpr AND kuc.kullanici_birim_id IN ($ph)))";
        $params = array_merge($params, $izinliBirimler);
    }
    $sql .= ")";
    return [$sql, $params];
}

// Ortak birim kısıt fragmanı (alias b)
$birimWhereSql = '1=1'; $birimWhereParams = [];
if ($birimKisitli) {
    if (empty($izinliBirimler)) { $birimWhereSql = '1=0'; }
    else {
        [$birimWhereSql, $birimWhereParams] = otpBirimKisitWhere(
            $izinliBirimler,
            'b.CallCenterApiLead_ID',
            'b.AltBayiPersonel_ID',
            '(SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = b.AltBayiPersonel_ID)',
            'b.ReklamLeadFormlari_ID',
            'b.OlusturanKullanici'
        );
    }
}

// Başvurunun birim adını çözen SQL ifadesi (alias b)
$birimAdiExpr = "COALESCE(
    (SELECT TOP 1 kb.KullaniciBirim_Adi FROM KullaniciBirimYetkileri kby
       INNER JOIN KullaniciBirim kb ON kb.KullaniciBirim_id = kby.KullaniciBirimYetkileri_Birim_id
       WHERE kby.Durum = 1 AND (kby.KullaniciBirimYetkileri_Personel_id = b.AltBayiPersonel_ID
         OR kby.KullaniciBirimYetkileri_AltBayi_id = (SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = b.AltBayiPersonel_ID)
         OR kby.KullaniciBirimYetkileri_ApiLead_id = b.CallCenterApiLead_ID)),
    (SELECT TOP 1 kb2.KullaniciBirim_Adi FROM ReklamLeadFormlari f
       INNER JOIN KullaniciBirimYetkileri kby2 ON kby2.KullaniciBirimYetkileri_ReklamSayfa_id = f.ReklamLeadFormlari_Sayfa_id AND kby2.Durum = 1
       INNER JOIN KullaniciBirim kb2 ON kb2.KullaniciBirim_id = kby2.KullaniciBirimYetkileri_Birim_id
       WHERE f.ReklamLeadFormlari_id = b.ReklamLeadFormlari_ID),
    (SELECT TOP 1 kbc.KullaniciBirim_Adi FROM kullanicilar kuc
       INNER JOIN KullaniciBirim kbc ON kbc.KullaniciBirim_id = kuc.kullanici_birim_id
       WHERE kuc.kullanici_id = b.OlusturanKullanici)
)";

// Filtre birim dropdown listesi (kısıtlıysa yalnız izinli birimler)
if ($birimKisitli) {
    $birimFiltreListe = empty($izinliBirimler) ? [] : $db->fetchAll(
        "SELECT KullaniciBirim_id, KullaniciBirim_Adi FROM KullaniciBirim
         WHERE Durum = 1 AND KullaniciBirim_id IN (" . implode(',', array_fill(0, count($izinliBirimler), '?')) . ")
         ORDER BY KullaniciBirim_Adi", $izinliBirimler);
} else {
    $birimFiltreListe = $db->fetchAll("SELECT KullaniciBirim_id, KullaniciBirim_Adi FROM KullaniciBirim WHERE Durum = 1 ORDER BY KullaniciBirim_Adi");
}

// =====================================================================
// AJAX
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'stats':
                $bw = $birimKisitli ? " AND ($birimWhereSql)" : "";
                $bp = $birimKisitli ? $birimWhereParams : [];
                $stats = [
                    'toplam'    => $db->fetchOne("SELECT COUNT(*) c FROM Basvurular b WHERE b.Basvurular_OtpGonderimTarihi IS NOT NULL$bw", $bp)['c'] ?? 0,
                    'beklemede' => $db->fetchOne("SELECT COUNT(*) c FROM Basvurular b WHERE b.Basvurular_OtpDurum = 'beklemede'$bw", $bp)['c'] ?? 0,
                    'onayli'    => $db->fetchOne("SELECT COUNT(*) c FROM Basvurular b WHERE b.Basvurular_OtpDurum = 'onayli'$bw", $bp)['c'] ?? 0,
                    'bugun'     => $db->fetchOne("SELECT COUNT(*) c FROM Basvurular b WHERE CAST(b.Basvurular_OtpGonderimTarihi AS DATE) = CAST(GETDATE() AS DATE)$bw", $bp)['c'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;

            case 'list':
                $search   = trim($_POST['search']    ?? '');
                $durum    = $_POST['durum']           ?? '';
                $kanalTip = $_POST['kanal_tipi']      ?? '';
                $tBas     = $_POST['tarih_bas']       ?? '';
                $tBit     = $_POST['tarih_bit']       ?? '';

                $fbirim   = $_POST['f_birim']          ?? '';

                $where  = ["b.Basvurular_OtpGonderimTarihi IS NOT NULL"];
                $params = [];

                // Birim bazlı yetki kısıtı (kısıtlı kullanıcı)
                if ($birimKisitli) {
                    $where[] = "($birimWhereSql)";
                    $params  = array_merge($params, $birimWhereParams);
                }

                if ($search !== '') {
                    $where[] = "((ISNULL(b.Isim,'')+' '+ISNULL(b.Soyisim,'')) LIKE ?
                        OR (ISNULL(b.phoneCountryNumber,'')+ISNULL(b.phoneAreaNumber,'')+ISNULL(b.phoneNumber,'')) LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%" . preg_replace('/\D/', '', $search) . "%";
                }
                if ($durum !== '')    { $where[] = "b.Basvurular_OtpDurum = ?";        $params[] = $durum; }
                if ($kanalTip !== '') { $where[] = "b.Basvurular_OtpKanalTipi_id = ?";  $params[] = (int)$kanalTip; }
                if ($tBas !== '')     { $where[] = "b.Basvurular_OtpGonderimTarihi >= ?"; $params[] = $tBas . ' 00:00:00'; }
                if ($tBit !== '')     { $where[] = "b.Basvurular_OtpGonderimTarihi <= ?"; $params[] = $tBit . ' 23:59:59'; }
                if ($fbirim !== '') {
                    $where[] = "(EXISTS (SELECT 1 FROM KullaniciBirimYetkileri kbf
                            WHERE kbf.KullaniciBirimYetkileri_Birim_id = ? AND kbf.Durum = 1
                              AND (kbf.KullaniciBirimYetkileri_Personel_id = b.AltBayiPersonel_ID
                                OR kbf.KullaniciBirimYetkileri_AltBayi_id = (SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = b.AltBayiPersonel_ID)
                                OR kbf.KullaniciBirimYetkileri_ApiLead_id = b.CallCenterApiLead_ID))
                          OR EXISTS (SELECT 1 FROM ReklamLeadFormlari f
                            INNER JOIN KullaniciBirimYetkileri kbf2 ON kbf2.KullaniciBirimYetkileri_ReklamSayfa_id = f.ReklamLeadFormlari_Sayfa_id
                            WHERE kbf2.KullaniciBirimYetkileri_Birim_id = ? AND kbf2.Durum = 1 AND f.ReklamLeadFormlari_id = b.ReklamLeadFormlari_ID)
                          OR (b.AltBayiPersonel_ID IS NULL AND b.CallCenterApiLead_ID IS NULL AND b.ReklamLeadFormlari_ID IS NULL
                              AND EXISTS (SELECT 1 FROM kullanicilar kuc WHERE kuc.kullanici_id = b.OlusturanKullanici AND kuc.kullanici_birim_id = ?)))";
                    $params[] = (int)$fbirim;
                    $params[] = (int)$fbirim;
                    $params[] = (int)$fbirim;
                }

                $whereClause = implode(" AND ", $where);
                $rows = $db->fetchAll("
                    SELECT
                        b.Basvurular_id,
                        $birimAdiExpr AS BirimAdi,
                        (ISNULL(b.Isim,'')+' '+ISNULL(b.Soyisim,'')) AS AdSoyad,
                        (ISNULL(b.phoneCountryNumber,'')+ISNULL(b.phoneAreaNumber,'')+ISNULL(b.phoneNumber,'')) AS Gsm,
                        b.Basvurular_OtpDurum AS Durum,
                        CONVERT(VARCHAR(19), b.Basvurular_OtpGonderimTarihi, 120) AS GonderimTarihi,
                        CONVERT(VARCHAR(19), b.Basvurular_OtpOnayTarihi, 120)      AS OnayTarihi,
                        b.Basvurular_OtpSonMesaj AS SonMesaj,
                        b.Basvurular_OtpKanalTipi_id AS KanalTipiId,
                        t.EntegrasyonKanalTipleri_Ad AS KanalTipiAd
                    FROM Basvurular b
                    LEFT JOIN EntegrasyonKanalTipleri t ON b.Basvurular_OtpKanalTipi_id = t.EntegrasyonKanalTipleri_id
                    WHERE $whereClause
                    ORDER BY b.Basvurular_OtpGonderimTarihi DESC
                ", $params);
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            case 'toplu_gonder':
                if (!$permissions['can_add']) { echo json_encode(['success' => false, 'message' => 'Gönderme yetkiniz yok!']); break; }
                if (!$otpKanalId)             { echo json_encode(['success' => false, 'message' => 'Aktif OTP kanalı bulunamadı!']); break; }

                $kanalTipId = (int)($_POST['kanal_tipi_id'] ?? 0);
                $ham        = trim($_POST['numaralar'] ?? '');
                if (!$kanalTipId || $ham === '') { echo json_encode(['success' => false, 'message' => 'Numara ve kanal tipi zorunludur!']); break; }

                $tip = $db->fetchOne("SELECT EntegrasyonKanalTipleri_Kod FROM EntegrasyonKanalTipleri WHERE EntegrasyonKanalTipleri_id = ?", [$kanalTipId]);
                if (!$tip) { echo json_encode(['success' => false, 'message' => 'Kanal tipi geçersiz!']); break; }
                $kod = $tip['EntegrasyonKanalTipleri_Kod'];

                // Satırları ayrıştır + normalize + tekilleştir
                $satirlar = preg_split('/[\r\n,;]+/', $ham);
                $gecerli  = [];
                $detay    = [];
                $basarili = 0; $hata = 0;
                foreach ($satirlar as $s) {
                    $s = trim($s);
                    if ($s === '') continue;
                    $n = otpNumaraNormalize($s);
                    if ($n === false) { $detay[] = ['numara' => $s, 'durum' => 'hata', 'mesaj' => 'Geçersiz format']; $hata++; continue; }
                    $gecerli[$n] = true; // dedupe
                }

                $now = date('Y-m-d H:i:s');
                foreach (array_keys($gecerli) as $gsm) {
                    // KARA LİSTE — başvuru kaydı açılmadan önce; aksi halde engellenen
                    // numara için boş Basvurular satırı kalırdı.
                    $engel = KaraListe::kontrolVeLogla($gsm, 'sms-dogrulama:toplu', null, null, $user['kullanici_id']);
                    if ($engel) {
                        $detay[] = ['numara' => $gsm, 'durum' => 'engelli', 'mesaj' => $engel['_mesaj']];
                        $hata++;
                        continue;
                    }

                    // find-or-create
                    $b = $db->fetchOne("
                        SELECT TOP 1 Basvurular_id, Isim, Soyisim, email, genderType, birthDate
                        FROM Basvurular
                        WHERE (ISNULL(phoneCountryNumber,'')+ISNULL(phoneAreaNumber,'')+ISNULL(phoneNumber,'')) = ?
                        ORDER BY Basvurular_id DESC
                    ", [$gsm]);

                    if ($b) {
                        $basvuruId = (int)$b['Basvurular_id'];
                        $musteri = [
                            'name'      => $b['Isim'] ?? '',
                            'surname'   => $b['Soyisim'] ?? '',
                            'mail'      => $b['email'] ?? '',
                            'gender'    => $b['genderType'] ?? '',
                            'birthDate' => !empty($b['birthDate']) ? substr((string)$b['birthDate'], 0, 10) : '',
                        ];
                    } else {
                        $basvuruId = $db->insert('Basvurular', [
                            'phoneCountryNumber' => substr($gsm, 0, 2),
                            'phoneAreaNumber'    => substr($gsm, 2, 3),
                            'phoneNumber'        => substr($gsm, 5),
                            'Basvuru_Aciklama'   => 'SMS Doğrulama toplu gönderim',
                            'OlusturanKullanici' => $user['kullanici_id'],
                            'OlusturmaTarihi'    => $now,
                            'GuncelleyenKullanici' => $user['kullanici_id'],
                            'GuncellemeTarihi'   => $now,
                        ]);
                        $musteri = [];
                    }

                    // Sınırsız kural: bu GSM daha önce onaylıysa SMS atma, direkt onayla
                    $onceOnay = EntegrasyonHelper::gsmDahaOnceOnayli($gsm);
                    if ($onceOnay) {
                        $db->update('Basvurular', [
                            'Basvurular_OtpKanal_id'       => $otpKanalId,
                            'Basvurular_OtpKanalTipi_id'   => $kanalTipId,
                            'Basvurular_OtpDurum'          => 'onayli',
                            'Basvurular_OtpGonderimTarihi' => $now,
                            'Basvurular_OtpOnayTarihi'     => $onceOnay['Basvurular_OtpOnayTarihi'] ?: $now,
                            'Basvurular_OtpSonMesaj'       => 'Daha önce onaylı GSM — SMS gönderilmedi',
                            'GuncellemeTarihi'             => $now,
                            'GuncelleyenKullanici'         => $user['kullanici_id'],
                        ], ['Basvurular_id' => $basvuruId]);
                        $detay[] = ['numara' => $gsm, 'durum' => 'onayli', 'mesaj' => 'Daha önce onaylı — SMS gönderilmedi'];
                        $basarili++;
                        continue;
                    }

                    $res = EntegrasyonHelper::digiturkBasvuruGonder($otpKanalId, $gsm, $kod, $musteri, true, $user['kullanici_id'], $basvuruId);

                    if ($res['durum'] === 'hata') {
                        $detay[] = ['numara' => $gsm, 'durum' => 'hata', 'mesaj' => $res['mesaj']];
                        $hata++;
                        continue;
                    }

                    $db->update('Basvurular', [
                        'Basvurular_OtpKanal_id'       => $otpKanalId,
                        'Basvurular_OtpKanalTipi_id'   => $kanalTipId,
                        'Basvurular_OtpDurum'          => otpDurumEsle($res['durum']),
                        'Basvurular_OtpGonderimTarihi' => $now,
                        'Basvurular_OtpOnayTarihi'     => $res['onayTarihi'] ?: null,
                        'Basvurular_OtpPath'           => $res['url'] ? mb_substr($res['url'], 0, 300) : null,
                        'Basvurular_OtpSonMesaj'       => mb_substr((string)$res['mesaj'], 0, 200),
                        'GuncellemeTarihi'             => $now,
                        'GuncelleyenKullanici'         => $user['kullanici_id'],
                    ], ['Basvurular_id' => $basvuruId]);

                    $detay[] = ['numara' => $gsm, 'durum' => $res['durum'], 'mesaj' => $res['mesaj']];
                    $basarili++;
                }

                echo json_encode([
                    'success'  => true,
                    'toplam'   => $basarili + $hata,
                    'basarili' => $basarili,
                    'hata'     => $hata,
                    'detay'    => $detay,
                ]);
                break;

            case 'sorgula':
                if (!$otpKanalId) { echo json_encode(['success' => false, 'message' => 'Aktif OTP kanalı bulunamadı!']); break; }

                $basvuruId = (int)($_POST['basvuru_id'] ?? 0);
                $b = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$basvuruId]);
                if (!$b) { echo json_encode(['success' => false, 'message' => 'Başvuru bulunamadı!']); break; }

                if ($birimKisitli) {
                    $chk = $db->fetchOne("SELECT TOP 1 1 v FROM Basvurular b WHERE b.Basvurular_id = ? AND ($birimWhereSql)",
                        array_merge([$basvuruId], $birimWhereParams));
                    if (!$chk) { echo json_encode(['success' => false, 'message' => 'Bu kayıt için yetkiniz yok!']); break; }
                }

                // Zaten onaylıysa Digiturk'e HİÇ gitme: sorgu aslında yeni bir Add isteğidir,
                // müşteriye yeni kod gönderir ve dönen 'request' cevabı onayı 'beklemede'ye ezer.
                if (($b['Basvurular_OtpDurum'] ?? '') === 'onayli') {
                    echo json_encode([
                        'success' => true,
                        'durum'   => 'onayli',
                        'message' => 'Durum: ONAYLI — yeni istek gönderilmedi'
                            . (!empty($b['Basvurular_OtpOnayTarihi']) ? ' (' . $b['Basvurular_OtpOnayTarihi'] . ')' : ''),
                    ]);
                    break;
                }

                $gsm      = otpGsmKur($b);
                $kanalId  = (int)($b['Basvurular_OtpKanal_id'] ?: $otpKanalId);
                $kanalTip = $db->fetchOne("SELECT EntegrasyonKanalTipleri_Kod FROM EntegrasyonKanalTipleri WHERE EntegrasyonKanalTipleri_id = ?", [(int)$b['Basvurular_OtpKanalTipi_id']]);
                $kod      = $kanalTip['EntegrasyonKanalTipleri_Kod'] ?? '3';

                // $basvuruId → redirectUrl'e bid olarak girer (onay dönüşü yakalanır),
                // smsFormat=false → tekil durum sorgusunda müşteriye yeni SMS gitmez
                $res = EntegrasyonHelper::digiturkDurumSorgula($kanalId, $gsm, $kod, $user['kullanici_id'], $basvuruId, false);
                if ($res['durum'] === 'hata') {
                    echo json_encode(['success' => false, 'message' => 'Digiturk: ' . $res['mesaj']]); break;
                }

                // İstek sürerken redirect webhook'u onaylamış olabilir — taze oku, onayı ezme
                $son      = $db->fetchOne("SELECT Basvurular_OtpDurum, Basvurular_OtpOnayTarihi FROM Basvurular WHERE Basvurular_id = ?", [$basvuruId]);
                $sonDurum = (string)($son['Basvurular_OtpDurum'] ?? '');
                if ($sonDurum === 'onayli' && $res['durum'] !== 'onayli') {
                    echo json_encode([
                        'success' => true,
                        'durum'   => 'onayli',
                        'message' => 'Durum: ONAYLI — bu sırada onaylandı, durum korundu'
                            . (!empty($son['Basvurular_OtpOnayTarihi']) ? ' (' . $son['Basvurular_OtpOnayTarihi'] . ')' : ''),
                    ]);
                    break;
                }

                $db->update('Basvurular', [
                    'Basvurular_OtpDurum'      => otpDurumEsle($res['durum']),
                    'Basvurular_OtpOnayTarihi' => $res['onayTarihi'] ?: ($b['Basvurular_OtpOnayTarihi'] ?? null),
                    'Basvurular_OtpSonMesaj'   => mb_substr((string)$res['mesaj'], 0, 200),
                    'GuncellemeTarihi'         => date('Y-m-d H:i:s'),
                    'GuncelleyenKullanici'     => $user['kullanici_id'],
                ], ['Basvurular_id' => $basvuruId]);

                $etiket = $res['durum'] === 'onayli' ? 'ONAYLI' : 'BEKLEMEDE';
                echo json_encode(['success' => true, 'message' => "Durum: $etiket ({$res['mesaj']})", 'durum' => $res['durum']]);
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
        .otp-badge { padding: .3rem .55rem; border-radius: .3rem; font-size: .8rem; font-weight: 600; white-space: nowrap; }
        .otp-onayli    { background:#d1e7dd; color:#0f5132; }
        .otp-beklemede { background:#fff3cd; color:#664d03; }
        .otp-iptal     { background:#f8d7da; color:#842029; }
        .otp-yok       { background:#e2e3e5; color:#41464b; }
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

                <!-- InfoBox -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-phone"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Gönderim</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Beklemede</span>
                                <span class="info-box-number" id="stat-beklemede">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Onaylı</span>
                                <span class="info-box-number" id="stat-onayli">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-calendar-day"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün</span>
                                <span class="info-box-number" id="stat-bugun">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card card-primary card-outline">
                    <div class="card-body">

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
                                            <input type="text" class="form-control" name="search" id="filter_search" placeholder="Ad soyad veya GSM...">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Birim</label>
                                            <select class="form-select" name="f_birim" id="filter_birim">
                                                <option value="">Tümü</option>
                                                <?php foreach ($birimFiltreListe as $bf): ?>
                                                <option value="<?= (int)$bf['KullaniciBirim_id'] ?>"><?= htmlspecialchars($bf['KullaniciBirim_Adi']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Kanal Tipi</label>
                                            <select class="form-select" name="kanal_tipi" id="filter_kanal_tipi">
                                                <option value="">Tümü</option>
                                                <?php foreach ($kanalTipleri as $kt): ?>
                                                <option value="<?= (int)$kt['EntegrasyonKanalTipleri_id'] ?>">
                                                    <?= htmlspecialchars($kt['EntegrasyonKanalTipleri_Kod'] . ' - ' . $kt['EntegrasyonKanalTipleri_Ad']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Durum</label>
                                            <select class="form-select" name="durum" id="filter_durum">
                                                <option value="">Tümü</option>
                                                <option value="beklemede">Beklemede</option>
                                                <option value="onayli">Onaylı</option>
                                                <option value="iptal">İptal</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Gönderim (Başlangıç)</label>
                                            <input type="date" class="form-control" name="tarih_bas" id="filter_tarih_bas">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Gönderim (Bitiş)</label>
                                            <input type="date" class="form-control" name="tarih_bit" id="filter_tarih_bit">
                                        </div>
                                        <div class="col-md-12">
                                            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                            <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Araç çubuğu -->
                        <div class="d-flex justify-content-end mb-2">
                            <?php if ($permissions['can_add']): ?>
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#gonderModal">
                                <i class="bi bi-plus-circle"></i> Yeni SMS Doğrulama Gönder
                            </button>
                            <?php endif; ?>
                        </div>

                        <table id="otpTable" class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Birim</th>
                                    <th>Ad Soyad</th>
                                    <th>GSM</th>
                                    <th>Kanal Tipi</th>
                                    <th>Durum</th>
                                    <th>Gönderim</th>
                                    <th>Onay</th>
                                    <th>Son Mesaj</th>
                                    <th>İşlemler</th>
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

<!-- Gönder Modal -->
<div class="modal fade" id="gonderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-phone-vibrate"></i> Yeni SMS Doğrulama Gönder</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="gonderForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Kanal Tipi (processType) <span class="text-danger">*</span></label>
                        <select class="form-select" id="g_kanal_tipi_id" name="kanal_tipi_id" required>
                            <option value="">— Kanal tipi seçin —</option>
                            <?php foreach ($kanalTipleri as $kt): ?>
                            <option value="<?= (int)$kt['EntegrasyonKanalTipleri_id'] ?>" <?= $kt['EntegrasyonKanalTipleri_Kod'] === '3' ? 'selected' : '' ?>>
                                <?= htmlspecialchars($kt['EntegrasyonKanalTipleri_Kod'] . ' - ' . $kt['EntegrasyonKanalTipleri_Ad']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Numaralar <span class="text-danger">*</span></label>
                        <textarea class="form-control font-monospace" id="g_numaralar" name="numaralar" rows="8"
                            placeholder="Her satıra bir numara:&#10;05550000000&#10;5550000000&#10;+90 555 000 00 00&#10;905550000000"></textarea>
                        <div class="form-text">Her satıra bir numara. Format farketmez; otomatik <b>905XXXXXXXXX</b>'e çevrilir. Mevcut başvuruda yoksa yeni kayıt açılır.</div>
                    </div>
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle"></i> Her numaraya SMS ile doğrulama linki gönderilir. Onay durumu satırdaki
                        <b>Durum Sorgula</b> ile güncellenir.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Gönder</button>
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
const permissions = { canAdd: <?= $permissions['can_add'] ? 'true' : 'false' ?> };
let dataTable, gonderModal;
let currentFilters = {};

const toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3500, timerProgressBar: true });
function bildirim(icon, title) { toast.fire({ icon, title }); }

function esc(s) { if (s === null || s === undefined || s === '') return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function fmtDT(d) {
    if (!d) return '-';
    try { const dt = new Date(String(d).replace(' ', 'T')); if (isNaN(dt)) return '-';
        return dt.toLocaleString('tr-TR', { year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit' }); }
    catch(e){ return '-'; }
}
function durumBadge(d) {
    const map = { onayli:['otp-onayli','Onaylı'], beklemede:['otp-beklemede','Beklemede'], iptal:['otp-iptal','İptal'] };
    const m = map[d] || ['otp-yok', d || '-'];
    return `<span class="otp-badge ${m[0]}">${esc(m[1])}</span>`;
}

function loadStats() {
    $.post('', { action: 'stats' }, function (r) {
        if (!r.success) return;
        $('#stat-toplam').text(r.data.toplam);
        $('#stat-beklemede').text(r.data.beklemede);
        $('#stat-onayli').text(r.data.onayli);
        $('#stat-bugun').text(r.data.bugun);
    }, 'json');
}

function loadTable() {
    $.post('', Object.assign({ action: 'list' }, currentFilters), function (r) {
        dataTable.clear();
        if (r.success && r.data.length) {
            const rows = r.data.map(function (x) {
                const sorgulaBtn = `<button class="btn btn-sm btn-outline-info" onclick="sorgula(${x.Basvurular_id})" title="Durum Sorgula"><i class="bi bi-arrow-repeat"></i></button>`;
                const mesaj = x.SonMesaj ? `<span title="${esc(x.SonMesaj)}">${esc(String(x.SonMesaj).substring(0,24))}</span>` : '-';
                return [
                    esc(x.BirimAdi) || '-',
                    esc(x.AdSoyad) || '-',
                    esc(x.Gsm) || '-',
                    esc(x.KanalTipiAd) || '-',
                    durumBadge(x.Durum),
                    fmtDT(x.GonderimTarihi),
                    fmtDT(x.OnayTarihi),
                    mesaj,
                    sorgulaBtn
                ];
            });
            dataTable.rows.add(rows);
        }
        dataTable.draw();
    }, 'json');
}

function sorgula(id) {
    Swal.fire({ title: 'Sorgulanıyor...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
    $.post('', { action: 'sorgula', basvuru_id: id }, function (r) {
        Swal.close();
        bildirim(r.success ? (r.durum === 'onayli' ? 'success' : 'info') : 'error', r.message);
        if (r.success) { loadTable(); loadStats(); }
    }, 'json').fail(() => { Swal.close(); bildirim('error', 'İstek başarısız'); });
}

$(document).ready(function () {
    gonderModal = new bootstrap.Modal(document.getElementById('gonderModal'));

    dataTable = $('#otpTable').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
        order: [[5, 'desc']],
        columnDefs: [{ orderable: false, targets: [8] }],
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
    });

    // NOT: Filtre .form-select'leri custom.js otomatik Select2 yapıyor — elle init YOK.

    // Filtre
    $('#filterForm').on('submit', function (e) {
        e.preventDefault();
        currentFilters = {
            search:     $('#filter_search').val(),
            f_birim:    $('#filter_birim').val(),
            durum:      $('#filter_durum').val(),
            kanal_tipi: $('#filter_kanal_tipi').val(),
            tarih_bas:  $('#filter_tarih_bas').val(),
            tarih_bit:  $('#filter_tarih_bit').val()
        };
        loadTable();
    });
    $('#clearFilters').on('click', function () {
        $('#filter_search,#filter_tarih_bas,#filter_tarih_bit').val('');
        $('#filter_birim,#filter_durum,#filter_kanal_tipi').val('').trigger('change');
        currentFilters = {};
        loadTable();
    });

    // Modal açılınca: kanal tipi Select2 — destroy guard + dropdownParent
    document.getElementById('gonderModal').addEventListener('shown.bs.modal', function () {
        if ($('#g_kanal_tipi_id').hasClass('select2-hidden-accessible')) $('#g_kanal_tipi_id').select2('destroy');
        $('#g_kanal_tipi_id').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#gonderModal') });
    });
    document.getElementById('gonderModal').addEventListener('hidden.bs.modal', function () {
        $('#g_numaralar').val('');
    });

    // Toplu gönder
    $('#gonderForm').on('submit', function (e) {
        e.preventDefault();
        const numaralar = $('#g_numaralar').val().trim();
        const kanalTipId = $('#g_kanal_tipi_id').val();
        if (!numaralar || !kanalTipId) { bildirim('warning', 'Numara ve kanal tipi zorunludur'); return; }
        Swal.fire({ title: 'Gönderiliyor...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
        $.post('', { action: 'toplu_gonder', numaralar: numaralar, kanal_tipi_id: kanalTipId }, function (r) {
            if (!r.success) { Swal.close(); bildirim('error', r.message || 'Hata'); return; }
            const rows = (r.detay || []).map(function (d) {
                const rozet = d.durum === 'engelli'
                    ? '<span class="badge bg-dark">Kara Liste</span>'
                    : durumBadge(d.durum === 'onayli' ? 'onayli' : (d.durum === 'hata' ? 'iptal' : 'beklemede'));
                return `<tr><td class="font-monospace">${esc(d.numara)}</td><td>${rozet}</td><td class="text-start small">${esc(d.mesaj)}</td></tr>`;
            }).join('');
            gonderModal.hide();
            Swal.fire({
                title: `Gönderim tamam — ${r.basarili}/${r.toplam} başarılı`,
                icon: r.hata ? 'warning' : 'success',
                width: 640,
                html: `<div style="max-height:340px;overflow:auto"><table class="table table-sm table-bordered mb-0">
                        <thead><tr><th>Numara</th><th>Durum</th><th>Mesaj</th></tr></thead><tbody>${rows}</tbody></table></div>`
            });
            loadTable(); loadStats();
        }, 'json').fail(() => { Swal.close(); bildirim('error', 'İstek başarısız'); });
    });

    loadStats();
    loadTable();
});
</script>
</body>
</html>
