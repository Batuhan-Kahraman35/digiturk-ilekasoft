<?php
/**
 * Admin Panel - IRIS Talep Eşleştirme
 *
 * Basvurular tablosundaki telefon ile IRIS'te talep arar, "Talep Kaydeden"i
 * DigiturkAltBayiPersonel ile eşleştirir ve kontrollü şekilde kaydeder.
 * Süreç durumları ccapi Order/CheckRequisitionList ile güncellenir.
 *
 * Tüm sorgular önce KURU çalışır; yazma yalnızca seçilen satırlar için yapılır.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/IrisTalepServisi.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'IRIS Talep Eşleştirme';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ── AJAX ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    @set_time_limit(600);

    try {
        switch ($action) {

            case 'stats': {
                $stats = [
                    'toplam'     => $db->fetchOne("SELECT COUNT(*) AS c FROM Basvurular")['c'] ?? 0,
                    'eslesmemis' => $db->fetchOne("SELECT COUNT(*) AS c FROM Basvurular
                                                   WHERE TalepKayitNo IS NULL AND phoneNumber IS NOT NULL AND phoneNumber <> ''")['c'] ?? 0,
                    'eslesmis'   => $db->fetchOne("SELECT COUNT(*) AS c FROM Basvurular WHERE TalepKayitNo IS NOT NULL AND TalepKayitNo > 0")['c'] ?? 0,
                    'surecsiz'   => $db->fetchOne("SELECT COUNT(*) AS c FROM Basvurular
                                                   WHERE TalepKayitNo IS NOT NULL AND TalepKayitNo > 0 AND BasvuruSurecDurum_ID IS NULL")['c'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
            }

            // IRIS sorgularında kullanılacak ana bayi hesabını değiştirir
            case 'hesap_kaydet': {
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok.']);
                    break;
                }
                $anaBayiId = (int)($_POST['anabayi_id'] ?? 0);

                if ($anaBayiId > 0) {
                    $hedef = $db->fetchOne("
                        SELECT DigiturkAnaBayiler_Ad           AS ad,
                               DigiturkAnaBayiler_KullaniciAdi AS kullanici,
                               DigiturkAnaBayiler_Sifre        AS sifre
                        FROM DigiturkAnaBayiler
                        WHERE DigiturkAnaBayiler_Id = ? AND Durum = 1", [$anaBayiId]);
                    if (!$hedef) {
                        echo json_encode(['success' => false, 'message' => 'Ana bayi bulunamadı veya pasif.']);
                        break;
                    }
                    if (empty($hedef['kullanici']) || empty($hedef['sifre'])) {
                        echo json_encode(['success' => false,
                            'message' => "{$hedef['ad']} için IRIS kullanıcı adı/şifresi tanımlı değil."]);
                        break;
                    }
                }

                // Bayrak filtered unique index ile korunuyor: önce hepsi sıfırlanır
                $db->execute("UPDATE DigiturkAnaBayiler
                              SET DigiturkAnaBayiler_IrisTalepVarsayilan = 0,
                                  GuncelleyenKullanici = ?, GuncellemeTarihi = ?
                              WHERE DigiturkAnaBayiler_IrisTalepVarsayilan = 1",
                             [$user['kullanici_id'], date('Y-m-d H:i:s')]);

                if ($anaBayiId > 0) {
                    $db->update('DigiturkAnaBayiler', [
                        'DigiturkAnaBayiler_IrisTalepVarsayilan' => 1,
                        'GuncelleyenKullanici'                   => $user['kullanici_id'],
                        'GuncellemeTarihi'                       => date('Y-m-d H:i:s'),
                    ], ['DigiturkAnaBayiler_Id' => $anaBayiId]);
                }

                echo json_encode([
                    'success'   => true,
                    'message'   => $anaBayiId > 0
                        ? 'IRIS sorgu hesabı güncellendi: ' . $hedef['ad']
                        : 'IRIS sorgu hesabı kaldırıldı.',
                    'kullanici' => $anaBayiId > 0 ? $hedef['kullanici'] : '-',
                ]);
                break;
            }

            // Serbest telefon sorgusu (birden fazla numara)
            case 'tel_sorgula': {
                $ham = trim((string)($_POST['telefonlar'] ?? ''));
                $liste = array_values(array_filter(array_map(
                    fn($t) => preg_replace('/\D/', '', $t),
                    preg_split('/[\s,;]+/', $ham, -1, PREG_SPLIT_NO_EMPTY) ?: []
                )));
                if (!$liste) { echo json_encode(['success' => false, 'message' => 'En az bir telefon numarası girin.']); break; }
                if (count($liste) > 20) { echo json_encode(['success' => false, 'message' => 'Tek seferde en fazla 20 numara sorgulanabilir.']); break; }

                $perMap   = irisPersonelHaritasi($db);
                $birimMap = irisPersonelBirimHaritasi($db);
                $o        = irisTalepOturum($db);
                $cikti    = [];

                foreach ($liste as $tel) {
                    if (strlen($tel) < 10) {
                        $cikti[] = ['telefon' => $tel, 'sonuc' => 'gecersiz', 'mesaj' => 'Numara 10 hane olmalı (başında 0 olmadan).', 'adaylar' => [], 'basvurular' => []];
                        continue;
                    }
                    $tel = substr($tel, -10);

                    try {
                        $e = irisTalepAra($o, $tel, $perMap);
                    } catch (Throwable $ex) {
                        $cikti[] = ['telefon' => $tel, 'sonuc' => 'hata', 'mesaj' => $ex->getMessage(), 'adaylar' => [], 'basvurular' => []];
                        continue;
                    }

                    // Bu telefona ait Basvurular kayıtları
                    $basvurular = $db->fetchAll("
                        SELECT b.Basvurular_id, b.Isim, b.Soyisim, b.MusteriNo, b.TalepKayitNo, b.MemoID,
                               b.AltBayiPersonel_ID, b.BasvuruDurum_ID, b.BasvuruSurecDurum_ID,
                               p.DigiturkAltBayiPersonel_AdSoyad AS PersonelAd,
                               sd.BasvuruSurecDurum_Mesaj        AS SurecAd
                        FROM Basvurular b
                        LEFT JOIN DigiturkAltBayiPersonel p ON p.DigiturkAltBayiPersonel_Id = b.AltBayiPersonel_ID
                        LEFT JOIN BasvuruSurecDurum sd      ON sd.BasvuruSurecDurum_id      = b.BasvuruSurecDurum_ID
                        WHERE b.phoneAreaNumber = ? AND b.phoneNumber = ?
                        ORDER BY b.Basvurular_id DESC", [substr($tel, 0, 3), substr($tel, 3)]);

                    $adaylar = [];
                    foreach ($e['adaylar'] as $a) {
                        // Bu talep başka bir başvuruya bağlı mı?
                        $talepNo = (int)($a['talep']['RequisitionId'] ?? 0);
                        $sahip   = null;
                        if ($talepNo > 0) {
                            $q = $db->fetchOne("SELECT TOP 1 Basvurular_id FROM Basvurular WHERE TalepKayitNo = ?", [$talepNo]);
                            if ($q) $sahip = (int)$q['Basvurular_id'];
                        }

                        $adaylar[] = [
                            'sahipId'      => $sahip,
                            'talepKayitNo' => $a['talep']['RequisitionId'] ?? null,
                            'musteriNo'    => irisMusteriNoTemizle($a['detay']['AccountNo'] ?? ($a['talep']['AccountNo'] ?? null)),
                            'memoId'       => $a['talep']['MemoId'] ?? null,
                            'tip'          => trim((string)($a['talep']['RequisitionType'] ?? '')),
                            'durumAdi'     => $a['talep']['Status'] ?? null,
                            'surecId'      => isset($a['detay']['RequisitonStatus']) ? (int)$a['detay']['RequisitonStatus'] : null,
                            'uye'          => trim(irisAdDuzgun($a['detay']['Name'] ?? '') . ' ' . irisAdDuzgun($a['detay']['Surname'] ?? '')),
                            'isim'         => irisAdDuzgun($a['detay']['Name']    ?? '') ?: null,
                            'soyisim'      => irisAdDuzgun($a['detay']['Surname'] ?? '') ?: null,
                            'durumMesaj'   => trim((string)($a['detay']['RequisitionStatusTitle'] ?? $a['talep']['Status'] ?? '')) ?: null,
                            'kaydeden'     => $a['kaydeden'],
                            'personelId'   => $a['personel']['id']      ?? null,
                            'personelAd'   => $a['personel']['adSoyad'] ?? null,
                            'birimAdi'     => isset($a['personel']['id']) ? ($birimMap[(int)$a['personel']['id']]['adi']  ?? null) : null,
                            'birimRenk'    => isset($a['personel']['id']) ? ($birimMap[(int)$a['personel']['id']]['renk'] ?? '#6c757d') : null,
                            'tarih'        => $a['talep']['Date'] ?? null,
                        ];
                    }

                    $cikti[] = [
                        'telefon'    => $tel,
                        'sonuc'      => $e['sonuc'],
                        'adaylar'    => $adaylar,
                        'basvurular' => $basvurular,
                    ];
                }
                irisTalepOturumKapat($o);
                echo json_encode(['success' => true, 'data' => $cikti]);
                break;
            }

            // Toplu kuru tarama: eşleşmemiş başvuruları IRIS'te ara
            case 'toplu_tara': {
                $adet    = max(1, min(200, (int)($_POST['adet'] ?? 10)));
                $sira    = ($_POST['sira'] ?? 'yeni') === 'eski' ? 'ASC' : 'DESC';
                $sadeceB = !empty($_POST['sadece_bos_personel']);
                // Kaç gündür kontrol edilmemişler taransın (0 = kontrol tarihine bakma)
                $bekleme = max(0, min(365, (int)($_POST['bekleme_gun'] ?? 3)));

                $ek = $sadeceB ? " AND AltBayiPersonel_ID IS NULL" : "";
                if ($bekleme > 0) {
                    $ek .= " AND (BasvuruDurum_KontrolTarihi IS NULL
                                  OR BasvuruDurum_KontrolTarihi < DATEADD(DAY, -{$bekleme}, GETDATE()))";
                }

                // Hiç kontrol edilmemişler önce, sonra en eski kontrol edilenler
                $kayitlar = $db->fetchAll("
                    SELECT TOP {$adet} Basvurular_id, Isim, Soyisim, phoneAreaNumber, phoneNumber,
                           AltBayiPersonel_ID, BasvuruDurum_KontrolTarihi
                    FROM Basvurular
                    WHERE TalepKayitNo IS NULL AND phoneNumber IS NOT NULL AND phoneNumber <> ''{$ek}
                    ORDER BY CASE WHEN BasvuruDurum_KontrolTarihi IS NULL THEN 0 ELSE 1 END,
                             BasvuruDurum_KontrolTarihi ASC,
                             Basvurular_id {$sira}");

                if (!$kayitlar) { echo json_encode(['success' => true, 'data' => [], 'ozet' => []]); break; }

                // Mükerrer telefon haritası: aynı numaraya sahip TÜM başvurular (tek sorgu)
                $telAnahtarlari = [];
                foreach ($kayitlar as $k) {
                    $t = irisTelefonBirlestir($k['phoneAreaNumber'], $k['phoneNumber']);
                    if (strlen($t) >= 10) $telAnahtarlari[$t] = true;
                }
                $mukerrer = [];
                if ($telAnahtarlari) {
                    $anahtarlar = array_keys($telAnahtarlari);
                    $yerTutucu  = implode(',', array_fill(0, count($anahtarlar), '?'));
                    $tumu = $db->fetchAll("
                        SELECT Basvurular_id, phoneAreaNumber, phoneNumber, TalepKayitNo
                        FROM Basvurular
                        WHERE phoneAreaNumber + phoneNumber IN ({$yerTutucu})
                        ORDER BY Basvurular_id DESC", $anahtarlar);
                    foreach ($tumu as $t) {
                        $anahtar = irisTelefonBirlestir($t['phoneAreaNumber'], $t['phoneNumber']);
                        $mukerrer[$anahtar][] = [
                            'id'     => (int)$t['Basvurular_id'],
                            'talep'  => $t['TalepKayitNo'] !== null ? (int)$t['TalepKayitNo'] : null,
                        ];
                    }
                }

                $perMap   = irisPersonelHaritasi($db);
                $birimMap = irisPersonelBirimHaritasi($db);
                $durumMap = irisDurumKoduHaritasi($db);
                $o        = irisTalepOturum($db);

                $satirlar = [];
                $ozet     = ['eslesti' => 0, 'talep_yok' => 0, 'personel_eslesmedi' => 0, 'talep_kullanimda' => 0, 'hata' => 0];
                // Bu tarama içinde bir talebin iki kayda birden atanmasını engeller
                $bulunanTalepler = [];
                // IRIS'e gerçekten sorulan kayıtlar — tarama sonunda kontrol tarihi damgalanır
                $sorulanlar = [];

                foreach ($kayitlar as $k) {
                    $id  = (int)$k['Basvurular_id'];
                    $tel = irisTelefonBirlestir($k['phoneAreaNumber'], $k['phoneNumber']);
                    $ad  = trim(($k['Isim'] ?? '') . ' ' . ($k['Soyisim'] ?? ''));

                    // Aynı telefonu taşıyan diğer başvurular
                    $digerKayitlar = array_values(array_filter($mukerrer[$tel] ?? [], fn($m) => $m['id'] !== $id));
                    $mkr = [
                        'mukerrerSayi'  => count($digerKayitlar),
                        'mukerrerIdler' => array_column($digerKayitlar, 'id'),
                        'mukerrerBagli' => count(array_filter($digerKayitlar, fn($m) => !empty($m['talep']))),
                    ];

                    if (strlen($tel) < 10) {
                        $ozet['hata']++;
                        $satirlar[] = $mkr + ['id' => $id, 'ad' => $ad, 'telefon' => $tel, 'sonuc' => 'hata', 'mesaj' => 'Telefon hanesi eksik'];
                        continue;
                    }

                    try {
                        $e = irisTalepAra($o, $tel, $perMap);
                        $sorulanlar[] = $id;   // sorgu başarılı → kontrol tarihi damgalanacak
                    } catch (Throwable $ex) {
                        $ozet['hata']++;
                        $satirlar[] = $mkr + ['id' => $id, 'ad' => $ad, 'telefon' => $tel, 'sonuc' => 'hata', 'mesaj' => $ex->getMessage()];
                        continue;
                    }

                    if ($e['sonuc'] !== 'eslesti') {
                        $ozet[$e['sonuc']]++;
                        // IRIS'te hiç talebi olmayanlar listelenmez (amaç var olanları eşleştirmek);
                        // sayıları özette görünür.
                        if ($e['sonuc'] === 'talep_yok') continue;

                        $kaydedenler = implode(', ', array_map(fn($a) => $a['kaydeden'] ?: '-', $e['adaylar']));
                        $satirlar[] = $mkr + ['id' => $id, 'ad' => $ad, 'telefon' => $tel, 'sonuc' => $e['sonuc'],
                                              'mesaj' => $kaydedenler !== '' ? 'IRIS kaydeden: ' . $kaydedenler : ''];
                        continue;
                    }

                    $veri = irisYazilacakAlanlar($e, $k, $durumMap, false);

                    // Bu talep başka bir başvuruya zaten bağlıysa tekrar bağlama
                    // (aynı numarayla 2-3 kez başvuru yapılmış olabiliyor).
                    $talepNo = (int)$veri['TalepKayitNo'];
                    $sahip   = $bulunanTalepler[$talepNo] ?? null;
                    if (!$sahip) {
                        $q = $db->fetchOne("SELECT TOP 1 Basvurular_id FROM Basvurular
                                            WHERE TalepKayitNo = ? AND Basvurular_id <> ?", [$talepNo, $id]);
                        if ($q) $sahip = (int)$q['Basvurular_id'];
                    }
                    if ($sahip) {
                        $ozet['talep_kullanimda']++;
                        $satirlar[] = $mkr + [
                            'id' => $id, 'ad' => $ad, 'telefon' => $tel, 'sonuc' => 'talep_kullanimda',
                            'talepKayitNo' => $talepNo, 'sahipId' => $sahip,
                            'mesaj' => "Talep #{$talepNo} zaten başvuru #{$sahip} kaydına bağlı",
                        ];
                        continue;
                    }
                    $bulunanTalepler[$talepNo] = $id;

                    $ozet['eslesti']++;
                    $yeniIsim    = $veri['Isim']    ?? null;
                    $yeniSoyisim = $veri['Soyisim'] ?? null;
                    $yeniAd      = trim(($yeniIsim ?? '') . ' ' . ($yeniSoyisim ?? ''));

                    $satirlar[] = $mkr + [
                        'id'           => $id,
                        'ad'           => $ad,
                        'telefon'      => $tel,
                        'sonuc'        => 'eslesti',
                        'uye'          => $yeniAd !== '' ? $yeniAd : trim(($e['detay']['Name'] ?? '') . ' ' . ($e['detay']['Surname'] ?? '')),
                        'isim'         => $yeniIsim,
                        'soyisim'      => $yeniSoyisim,
                        'adDegisiyor'  => ($yeniAd !== '' && irisAdNormalize($yeniAd) !== irisAdNormalize($ad)),
                        'durumMesaj'   => $veri['BasvuruDurumMesaj'] ?? null,
                        'talepKayitNo' => $veri['TalepKayitNo'],
                        'musteriNo'    => $veri['MusteriNo'],
                        'memoId'       => $veri['MemoID'],
                        'surecId'      => $veri['BasvuruSurecDurum_ID'] ?? null,
                        'kaydeden'     => $e['kaydeden'],
                        'personelId'   => (int)$e['personel']['id'],
                        'personelAd'   => $e['personel']['adSoyad'],
                        'birimAdi'     => $birimMap[(int)$e['personel']['id']]['adi']  ?? null,
                        'birimRenk'    => $birimMap[(int)$e['personel']['id']]['renk'] ?? '#6c757d',
                        'mevcutPersonelId' => $k['AltBayiPersonel_ID'] !== null ? (int)$k['AltBayiPersonel_ID'] : null,
                        'personelCelisti'  => (!empty($k['AltBayiPersonel_ID']) && (int)$k['AltBayiPersonel_ID'] !== (int)$e['personel']['id']),
                    ];
                }
                irisTalepOturumKapat($o);

                // Sorgulanan kayıtları damgala: aynı kayıtlar her turda tekrar sorgulanmasın.
                // (Kuru tarama veri değiştirmez; bu yalnızca "sorduk" kaydıdır.)
                $ozet['damgalanan'] = 0;
                if ($sorulanlar) {
                    try {
                        $yerTutucu = implode(',', array_fill(0, count($sorulanlar), '?'));
                        $ok = $db->execute("UPDATE Basvurular SET BasvuruDurum_KontrolTarihi = GETDATE()
                                            WHERE Basvurular_id IN ({$yerTutucu})", $sorulanlar);
                        if ($ok === false) $ozet['damgaHata'] = 'Kontrol tarihi güncellenemedi (UPDATE false döndü)';
                        else               $ozet['damgalanan'] = count($sorulanlar);
                    } catch (Throwable $ex) {
                        $ozet['damgaHata'] = 'Kontrol tarihi yazılamadı: ' . $ex->getMessage();
                    }
                }

                echo json_encode(['success' => true, 'data' => $satirlar, 'ozet' => $ozet]);
                break;
            }

            // Seçilen başvurulara talep bilgilerini yaz
            case 'eslesme_kaydet': {
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']); break; }

                $satirlar = json_decode((string)($_POST['satirlar'] ?? '[]'), true);
                if (!is_array($satirlar) || !$satirlar) { echo json_encode(['success' => false, 'message' => 'Kaydedilecek satır seçilmedi.']); break; }

                $personeliEz = !empty($_POST['personeli_ez']);
                $durumMap    = irisDurumKoduHaritasi($db);
                $simdi       = date('Y-m-d H:i:s');
                $yazilan = 0; $atlanan = 0; $hatalar = [];

                foreach ($satirlar as $s) {
                    $id = (int)($s['id'] ?? 0);
                    if ($id <= 0) { $atlanan++; continue; }

                    $mevcut = $db->fetchOne("SELECT Basvurular_id, AltBayiPersonel_ID, TalepKayitNo FROM Basvurular WHERE Basvurular_id = ?", [$id]);
                    if (!$mevcut) { $atlanan++; $hatalar[] = "#{$id} bulunamadı"; continue; }

                    $veri = [
                        'TalepKayitNo'         => (int)($s['talepKayitNo'] ?? 0),
                        'MemoID'               => (int)($s['memoId'] ?? 0),
                        'GuncelleyenKullanici' => $user['kullanici_id'],
                        'GuncellemeTarihi'     => $simdi,
                    ];
                    if (!empty($s['musteriNo'])) $veri['MusteriNo'] = (int)$s['musteriNo'];
                    if (isset($durumMap[0]))     $veri['BasvuruDurum_ID'] = $durumMap[0];
                    if (!empty($s['surecId'])) {
                        $veri['BasvuruSurecDurum_ID']            = (int)$s['surecId'];
                        $veri['BasvuruSurecDurum_KontrolTarihi'] = $simdi;
                    }
                    // Isim / Soyisim IRIS üye bilgisiyle güncellenir
                    if (!empty($s['isim']))      $veri['Isim']    = irisAdDuzgun($s['isim']);
                    if (!empty($s['soyisim']))   $veri['Soyisim'] = irisAdDuzgun($s['soyisim']);
                    if (!empty($s['durumMesaj'])) $veri['BasvuruDurumMesaj'] = mb_substr((string)$s['durumMesaj'], 0, 500);

                    if ($veri['TalepKayitNo'] <= 0) { $atlanan++; $hatalar[] = "#{$id} talep no yok"; continue; }

                    // Aynı talep başka bir başvuruya bağlıysa yazma (tarama ile kayıt arasında
                    // veri değişmiş olabilir; son söz burada verilir).
                    $sahip = $db->fetchOne("SELECT TOP 1 Basvurular_id FROM Basvurular
                                            WHERE TalepKayitNo = ? AND Basvurular_id <> ?",
                                           [$veri['TalepKayitNo'], $id]);
                    if ($sahip) {
                        $atlanan++;
                        $hatalar[] = "#{$id}: talep {$veri['TalepKayitNo']} zaten #{$sahip['Basvurular_id']} kaydında";
                        continue;
                    }

                    // AltBayiPersonel_ID: boşsa yaz, doluysa yalnızca "ez" seçiliyse
                    $yeniPersonel = (int)($s['personelId'] ?? 0);
                    if ($yeniPersonel > 0 && (empty($mevcut['AltBayiPersonel_ID']) || $personeliEz)) {
                        $veri['AltBayiPersonel_ID'] = $yeniPersonel;
                    }

                    try {
                        $db->update('Basvurular', $veri, ['Basvurular_id' => $id]);
                        $yazilan++;
                    } catch (Throwable $ex) {
                        $atlanan++; $hatalar[] = "#{$id}: " . $ex->getMessage();
                    }
                }

                echo json_encode([
                    'success' => true,
                    'message' => "{$yazilan} kayıt güncellendi" . ($atlanan ? ", {$atlanan} atlandı" : ''),
                    'yazilan' => $yazilan, 'atlanan' => $atlanan,
                    'hatalar' => array_slice($hatalar, 0, 10),
                ]);
                break;
            }

            // Süreç durumu kuru sorgu (CheckRequisitionList)
            case 'durum_tara': {
                $adet       = max(1, min(500, (int)($_POST['adet'] ?? 50)));
                $sadeceBos  = !empty($_POST['sadece_bos']);
                $finalHaric = !empty($_POST['final_haric']);
                $bekleme    = max(0, min(365, (int)($_POST['bekleme_gun'] ?? 1)));

                $where = ["TalepKayitNo IS NOT NULL", "TalepKayitNo > 0"];
                if ($sadeceBos)  $where[] = "BasvuruSurecDurum_ID IS NULL";
                // Final durumlar: 5 Tamamlandı, 6 Başka Firma, 7 Zaman Aşımı, 9 Başka Bayi, 14 Teyitten Geçemedi, 18 Hatalı Kayıt
                if ($finalHaric) $where[] = "(BasvuruSurecDurum_ID IS NULL OR BasvuruSurecDurum_ID NOT IN (SELECT BasvuruSurecDurum_id FROM BasvuruSurecDurum WHERE BasvuruSurecDurum_Sonuclandi = 1))";
                if ($bekleme > 0) {
                    $where[] = "(BasvuruSurecDurum_KontrolTarihi IS NULL
                                 OR BasvuruSurecDurum_KontrolTarihi < DATEADD(DAY, -{$bekleme}, GETDATE()))";
                }
                $whereSql = implode(' AND ', $where);

                // Hiç kontrol edilmemişler önce, sonra en eski kontrol edilenler
                $kayitlar = $db->fetchAll("
                    SELECT TOP {$adet} Basvurular_id, Isim, Soyisim, TalepKayitNo,
                           BasvuruDurum_ID, BasvuruSurecDurum_ID, BasvuruSurecDurum_KontrolTarihi
                    FROM Basvurular WHERE {$whereSql}
                    ORDER BY CASE WHEN BasvuruSurecDurum_KontrolTarihi IS NULL THEN 0 ELSE 1 END,
                             BasvuruSurecDurum_KontrolTarihi ASC,
                             Basvurular_id ASC");
                if (!$kayitlar) { echo json_encode(['success' => true, 'data' => [], 'ozet' => []]); break; }

                $durumMap = irisDurumKoduHaritasi($db);
                $surecAdi = [];
                foreach ($db->fetchAll("SELECT BasvuruSurecDurum_id AS id, BasvuruSurecDurum_Mesaj AS mesaj, BasvuruSurecDurum_Renk AS renk FROM BasvuruSurecDurum") as $d) {
                    $surecAdi[(int)$d['id']] = ['mesaj' => $d['mesaj'], 'renk' => $d['renk']];
                }

                // Süreç durumu yalnız canlı ccapi'den (CheckRequisition) okunur. DigiturkIrisRapor
                // kullanılmaz: rapordaki IrisRapor_BasvuruSurecDurumu farklı bir sınıflandırmadır
                // (İptal, NEO_BUNDLE_BEKLEME, UYDU_KURULUM…) ve yalnız "Tamamlandı" metni
                // BasvuruSurecDurum ile tesadüfen eşleşir — ccapi'nin 15 (Aktivasyon Yapıldı /
                // Prim Başka Kayıtta) dediği talepleri 5 (Tamamlandı) gösterip yanlış kayda yol açıyordu.
                $talepNolar = array_values(array_filter(array_map(fn($k) => (int)$k['TalepKayitNo'], $kayitlar)));

                $apiSonuc = [];
                foreach ($talepNolar ? irisDurumSorgula($db, $talepNolar) : [] as $no => $d) {
                    $d['_kaynak']  = 'api';
                    $apiSonuc[$no] = $d;
                }

                $satirlar = []; $ozet = ['sorgulanan' => count($kayitlar), 'donen' => 0, 'degisen' => 0, 'donmeyen' => 0,
                                         'apiden' => count($apiSonuc)];
                $sorulanlar = [];   // API'den yanıt gelen kayıtlar → kontrol tarihi damgalanır

                foreach ($kayitlar as $k) {
                    $no = (int)$k['TalepKayitNo'];
                    $d  = $apiSonuc[$no] ?? null;
                    if (!$d) { $ozet['donmeyen']++; continue; }
                    $ozet['donen']++;
                    $sorulanlar[] = (int)$k['Basvurular_id'];

                    $yeniSurec = (int)($d['requestStatusCode'] ?? 0);
                    $eskiSurec = $k['BasvuruSurecDurum_ID'] !== null ? (int)$k['BasvuruSurecDurum_ID'] : null;
                    $eskiDurum = $k['BasvuruDurum_ID']      !== null ? (int)$k['BasvuruDurum_ID']      : null;
                    $yeniDurum = $durumMap[0] ?? null;
                    if ($yeniSurec <= 0) continue;

                    // Yalnızca süreç durumu değişenler listelenir (cron da sadece bu alanı yazar)
                    if ($yeniSurec === $eskiSurec) continue;
                    $ozet['degisen']++;

                    $satirlar[] = [
                        'id'           => (int)$k['Basvurular_id'],
                        'ad'           => trim(($k['Isim'] ?? '') . ' ' . ($k['Soyisim'] ?? '')),
                        'talepKayitNo' => $no,
                        'eskiSurecId'  => $eskiSurec,
                        'eskiSurecAd'  => $eskiSurec !== null ? ($surecAdi[$eskiSurec]['mesaj'] ?? null) : null,
                        'yeniSurecId'  => $yeniSurec,
                        'yeniSurecAd'  => $d['requestStatusTitle'] ?? ($surecAdi[$yeniSurec]['mesaj'] ?? null),
                        'durumMesaj'   => trim((string)($d['requestStatusTitle'] ?? '')) ?: null,
                        'yeniSurecRenk'=> $surecAdi[$yeniSurec]['renk'] ?? '#6c757d',
                        'eskiDurumId'  => $eskiDurum,
                        'yeniDurumId'  => $yeniDurum,
                        'bayi'         => $d['referredDealer'] ?? null,
                        'islemDurum'   => $d['processStatus'] ?? null,
                        'kaynak'       => $d['_kaynak'] ?? 'api',
                        'veriTarihi'   => $d['_veriTarihi'] ?? null,
                    ];
                }

                // Yanıt alınan kayıtları damgala (durum değişmemiş olsa da sorduk)
                $ozet['damgalanan'] = 0;
                if ($sorulanlar) {
                    try {
                        $yerTutucu = implode(',', array_fill(0, count($sorulanlar), '?'));
                        $ok = $db->execute("UPDATE Basvurular SET BasvuruSurecDurum_KontrolTarihi = GETDATE()
                                            WHERE Basvurular_id IN ({$yerTutucu})", $sorulanlar);
                        if ($ok === false) $ozet['damgaHata'] = 'Kontrol tarihi güncellenemedi (UPDATE false döndü)';
                        else               $ozet['damgalanan'] = count($sorulanlar);
                    } catch (Throwable $ex) {
                        $ozet['damgaHata'] = 'Kontrol tarihi yazılamadı: ' . $ex->getMessage();
                    }
                }

                echo json_encode(['success' => true, 'data' => $satirlar, 'ozet' => $ozet]);
                break;
            }

            case 'durum_kaydet': {
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']); break; }

                $satirlar = json_decode((string)($_POST['satirlar'] ?? '[]'), true);
                if (!is_array($satirlar) || !$satirlar) { echo json_encode(['success' => false, 'message' => 'Kaydedilecek satır seçilmedi.']); break; }

                $simdi = date('Y-m-d H:i:s');
                $yazilan = 0; $atlanan = 0;

                foreach ($satirlar as $s) {
                    $id    = (int)($s['id'] ?? 0);
                    $surec = (int)($s['yeniSurecId'] ?? 0);
                    if ($id <= 0 || $surec <= 0) { $atlanan++; continue; }

                    // Süreç güncellemesi yalnızca BasvuruSurecDurum_ID yazar (cron ile aynı davranış).
                    $veri = [
                        'BasvuruSurecDurum_ID'            => $surec,
                        'BasvuruSurecDurum_KontrolTarihi' => $simdi,
                        'GuncelleyenKullanici'            => $user['kullanici_id'],
                        'GuncellemeTarihi'                => $simdi,
                    ];

                    try { $db->update('Basvurular', $veri, ['Basvurular_id' => $id]); $yazilan++; }
                    catch (Throwable $ex) { $atlanan++; }
                }

                echo json_encode(['success' => true, 'message' => "{$yazilan} kayıt güncellendi" . ($atlanan ? ", {$atlanan} atlandı" : '')]);
                break;
            }

            // Tek başvuruya, seçilen talebi elle bağla (telefon sekmesinden)
            case 'tekil_kaydet': {
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']); break; }

                $id = (int)($_POST['basvuru_id'] ?? 0);
                $mevcut = $db->fetchOne("SELECT Basvurular_id, AltBayiPersonel_ID FROM Basvurular WHERE Basvurular_id = ?", [$id]);
                if (!$mevcut) { echo json_encode(['success' => false, 'message' => 'Başvuru bulunamadı.']); break; }

                $talep = (int)($_POST['talep_kayit_no'] ?? 0);
                if ($talep <= 0) { echo json_encode(['success' => false, 'message' => 'Talep Kayıt No geçersiz.']); break; }

                $sahip = $db->fetchOne("SELECT TOP 1 Basvurular_id FROM Basvurular
                                        WHERE TalepKayitNo = ? AND Basvurular_id <> ?", [$talep, $id]);
                if ($sahip) {
                    echo json_encode(['success' => false,
                        'message' => "Talep {$talep} zaten başvuru #{$sahip['Basvurular_id']} kaydına bağlı. Aynı talep birden fazla başvuruya yazılamaz."]);
                    break;
                }

                $durumMap = irisDurumKoduHaritasi($db);
                $veri = [
                    'TalepKayitNo'         => $talep,
                    'MemoID'               => (int)($_POST['memo_id'] ?? 0),
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ];
                if (!empty($_POST['musteri_no']))  $veri['MusteriNo'] = (int)$_POST['musteri_no'];
                if (!empty($_POST['surec_id']))    $veri['BasvuruSurecDurum_ID'] = (int)$_POST['surec_id'];
                if (isset($durumMap[0]))           $veri['BasvuruDurum_ID'] = $durumMap[0];
                if (!empty($_POST['durum_mesaj'])) $veri['BasvuruDurumMesaj'] = mb_substr((string)$_POST['durum_mesaj'], 0, 500);
                if (!empty($_POST['isim']))        $veri['Isim']    = irisAdDuzgun((string)$_POST['isim']);
                if (!empty($_POST['soyisim']))     $veri['Soyisim'] = irisAdDuzgun((string)$_POST['soyisim']);

                // Tekil kayıt elle onaylanan bir işlem: IRIS'teki "Talep Kaydeden" personeli
                // mevcut değeri ezer (toplu tarama kuralından farklı, bilinçli).
                $yeniPersonel = (int)($_POST['personel_id'] ?? 0);
                if ($yeniPersonel > 0) $veri['AltBayiPersonel_ID'] = $yeniPersonel;

                $db->update('Basvurular', $veri, ['Basvurular_id' => $id]);
                echo json_encode(['success' => true, 'message' => "Başvuru #{$id} güncellendi."]);
                break;
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
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

    <style>
        .surec-badge { display:inline-block; padding:.25rem .6rem; border-radius:1rem; color:#fff; font-size:.8rem; font-weight:600; }
        .sonuc-badge { padding:.2rem .5rem; border-radius:.25rem; font-size:.8rem; font-weight:600; }
        .s-eslesti   { background:#d4edda; color:#155724; }
        .s-yok       { background:#e2e3e5; color:#41464b; }
        .s-personel  { background:#fff3cd; color:#664d03; }
        .s-kullanimda{ background:#cfe2ff; color:#084298; }
        .s-hata      { background:#f8d7da; color:#721c24; }
        .celisti     { background:#fff3cd !important; }
        .tel-kart    { border-left:4px solid #0d6efd; }
        .tel-kart.yok      { border-left-color:#adb5bd; }
        .tel-kart.personel { border-left-color:#ffc107; }
        .tel-kart.hata     { border-left-color:#dc3545; }
        .mono        { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        #topluTable td, #durumTable td { vertical-align: middle; }
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
                            <span class="info-box-icon"><i class="bi bi-people"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Başvuru</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-question-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Eşleştirilecek</span>
                                <span class="info-box-number" id="stat-eslesmemis">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-link-45deg"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Talebi Eşleşmiş</span>
                                <span class="info-box-number" id="stat-eslesmis">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-secondary">
                            <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Süreç Durumu Boş</span>
                                <span class="info-box-number" id="stat-surecsiz">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- IRIS Sorgularında Kullanılan Hesap -->
                <?php include __DIR__ . '/../includes/iris-hesap-bilgisi.php'; ?>

                <!-- Sekmeler -->
                <div class="card">
                    <div class="card-header p-0 border-bottom-0">
                        <ul class="nav nav-tabs" id="anaTab" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-tel" type="button">
                                    <i class="bi bi-telephone"></i> Telefon ile Test
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-toplu" type="button">
                                    <i class="bi bi-collection"></i> Toplu Eşleştirme
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-durum" type="button">
                                    <i class="bi bi-arrow-repeat"></i> Süreç Durumu Güncelle
                                </button>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content">

                            <!-- ── Telefon ile Test ── -->
                            <div class="tab-pane fade show active" id="tab-tel">
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label class="form-label">Telefon Numaraları <span class="text-muted">(alt alta veya virgülle, başında 0 olmadan 10 hane)</span></label>
                                        <textarea class="form-control mono" id="telefonlar" rows="4" placeholder="5550000004&#10;5550000005"></textarea>
                                    </div>
                                    <div class="col-md-4 d-flex flex-column justify-content-end">
                                        <div class="small text-muted mb-2">
                                            <i class="bi bi-info-circle"></i>
                                            "Bu kayda yaz" personeli IRIS'teki Talep Kaydeden ile <b>değiştirir</b>.
                                        </div>
                                        <button class="btn btn-primary" id="btnTelSorgula"><i class="bi bi-search"></i> IRIS'te Sorgula</button>
                                    </div>
                                </div>
                                <hr>
                                <div id="telSonuc"><p class="text-muted mb-0">Numara girip sorgulayın. Bu ekran hiçbir şey yazmaz; kayıt yalnızca "Bu başvuruya yaz" ile yapılır.</p></div>
                            </div>

                            <!-- ── Toplu Eşleştirme ── -->
                            <div class="tab-pane fade" id="tab-toplu">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-2">
                                        <label class="form-label">Kayıt Adedi</label>
                                        <select class="form-select" id="toplu_adet">
                                            <option>5</option><option selected>10</option><option>25</option>
                                            <option>50</option><option>100</option><option>200</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Sıralama</label>
                                        <select class="form-select" id="toplu_sira">
                                            <option value="yeni" selected>En yeni başvurulardan</option>
                                            <option value="eski">En eski başvurulardan</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Kontrol Aralığı</label>
                                        <select class="form-select" id="toplu_bekleme">
                                            <option value="0">Tümü (tarihe bakma)</option>
                                            <option value="1">1 gündür sorulmayanlar</option>
                                            <option value="3" selected>3 gündür sorulmayanlar</option>
                                            <option value="7">7 gündür sorulmayanlar</option>
                                            <option value="15">15 gündür sorulmayanlar</option>
                                            <option value="30">30 gündür sorulmayanlar</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="toplu_sadece_bos">
                                            <label class="form-check-label" for="toplu_sadece_bos">Sadece personeli boş kayıtlar</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="toplu_personeli_ez">
                                            <label class="form-check-label" for="toplu_personeli_ez">Dolu personeli IRIS'e göre ez</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <button class="btn btn-primary" id="btnTopluTara"><i class="bi bi-search"></i> Kuru Tara</button>
                                        <button class="btn btn-success" id="btnTopluKaydet" disabled><i class="bi bi-save"></i> Seçilenleri Kaydet</button>
                                    </div>
                                </div>

                                <div id="topluOzet" class="mt-3"></div>

                                <div class="table-responsive mt-2">
                                    <table id="topluTable" class="table table-bordered table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th style="width:36px"><input type="checkbox" id="topluHepsi"></th>
                                                <th>Başvuru</th>
                                                <th>Telefon</th>
                                                <th>Sonuç</th>
                                                <th>IRIS Üye</th>
                                                <th>Talep No</th>
                                                <th>Müşteri No</th>
                                                <th>Memo ID</th>
                                                <th>Süreç</th>
                                                <th>Talep Kaydeden → Personel</th>
                                                <th>Birim</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- ── Süreç Durumu ── -->
                            <div class="tab-pane fade" id="tab-durum">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-2">
                                        <label class="form-label">Kayıt Adedi</label>
                                        <select class="form-select" id="durum_adet">
                                            <option>25</option><option selected>50</option>
                                            <option>100</option><option>250</option><option>500</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Kontrol Aralığı</label>
                                        <select class="form-select" id="durum_bekleme">
                                            <option value="0">Tümü (tarihe bakma)</option>
                                            <option value="1" selected>1 gündür sorulmayanlar</option>
                                            <option value="3">3 gündür sorulmayanlar</option>
                                            <option value="7">7 gündür sorulmayanlar</option>
                                            <option value="15">15 gündür sorulmayanlar</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="durum_sadece_bos" value="1">
                                            <label class="form-check-label" for="durum_sadece_bos">Sadece süreç durumu boş olanlar</label>
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="durum_final_haric" value="1" checked>
                                            <label class="form-check-label" for="durum_final_haric">Sonuçlanmışları atla (Tamamlandı / İptal)</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <button class="btn btn-primary" id="btnDurumTara"><i class="bi bi-search"></i> Kuru Sorgula</button>
                                        <button class="btn btn-success" id="btnDurumKaydet" disabled><i class="bi bi-save"></i> Seçilenleri Kaydet</button>
                                    </div>
                                </div>

                                <div id="durumOzet" class="mt-3"></div>

                                <div class="table-responsive mt-2">
                                    <table id="durumTable" class="table table-bordered table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th style="width:36px"><input type="checkbox" id="durumHepsi"></th>
                                                <th>Başvuru</th>
                                                <th>Talep No</th>
                                                <th>Mevcut Süreç</th>
                                                <th>Yeni Süreç</th>
                                                <th>Yön. Bayi</th>
                                                <th>İşlem Durumu</th>
                                                <th>Kaynak</th>
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
    const permissions = { canEdit: <?= $permissions['can_edit'] ? 'true' : 'false' ?> };

    let topluSatirlar = [], durumSatirlar = [];

    $(document).ready(function () {
        loadStats();

        // custom.js .form-select'leri otomatik Select2 yapıyor — burada tekrar init edilmez.

        $('#btnTelSorgula').on('click', telSorgula);
        $('#btnTopluTara').on('click', topluTara);
        $('#btnTopluKaydet').on('click', topluKaydet);
        $('#btnDurumTara').on('click', durumTara);
        $('#btnDurumKaydet').on('click', durumKaydet);

        $('#topluHepsi').on('change', function () {
            $('#topluTable tbody input.satir-sec:not(:disabled)').prop('checked', this.checked);
            topluSecimGuncelle();
        });
        $('#durumHepsi').on('change', function () {
            $('#durumTable tbody input.satir-sec').prop('checked', this.checked);
            durumSecimGuncelle();
        });
        $('#topluTable').on('change', 'input.satir-sec', topluSecimGuncelle);
        $('#durumTable').on('change', 'input.satir-sec', durumSecimGuncelle);

        // Telefon sekmesi toplu kaydetme — sonuçlar sonradan çizildiği için delege edilir
        $('#telSonuc').on('click', '#btnTelTopluKaydet', telTopluKaydet);
        $('#telSonuc').on('change', '#telHepsi', function () {
            $('#telSonuc .tel-satir-sec').prop('checked', this.checked);
            telSecimGuncelle();
        });
        $('#telSonuc').on('change', '.tel-satir-sec', telSecimGuncelle);
    });

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-eslesmemis').text(r.data.eslesmemis);
            $('#stat-eslesmis').text(r.data.eslesmis);
            $('#stat-surecsiz').text(r.data.surecsiz);
        }, 'json');
    }

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
    }

    /** Başvuru ID'sini düzenleme formuna yeni sekmede açılan link yapar */
    function basvuruLink(id) {
        if (!id) return '-';
        return `<a href="/Admin/basvuru-form?id=${encodeURIComponent(id)}" target="_blank" rel="noopener"
                   title="Başvuruyu yeni sekmede aç">#${escapeHtml(id)} <i class="bi bi-box-arrow-up-right small"></i></a>`;
    }

    /** Aynı telefonu taşıyan başka başvurular varsa uyarı rozeti */
    function mukerrerRozet(s) {
        const n = Number(s.mukerrerSayi || 0);
        if (!n) return '';
        const idler = (s.mukerrerIdler || []).join(', ');
        const bagli = Number(s.mukerrerBagli || 0);
        const baslik = `Aynı telefonda ${n} başka başvuru daha var (#${idler})`
                     + (bagli ? ` — bunların ${bagli} tanesi zaten bir talebe bağlı` : '');
        return `<br><span class="badge bg-warning text-dark" title="${escapeHtml(baslik)}">
                  <i class="bi bi-files"></i> ${n} mükerrer</span>`;
    }

    /** Birim adını renkli rozet olarak yazar */
    function birimRozet(adi, renk) {
        if (!adi) return '<span class="text-muted">-</span>';
        return `<span class="surec-badge" style="background:${escapeHtml(renk || '#6c757d')}">${escapeHtml(adi)}</span>`;
    }

    function butonBekle($btn, bekle, metin) {
        if (bekle) {
            $btn.data('eski', $btn.html()).prop('disabled', true)
                .html('<span class="spinner-border spinner-border-sm"></span> ' + (metin || 'İşleniyor...'));
        } else {
            $btn.prop('disabled', false).html($btn.data('eski'));
        }
    }

    // ── Telefon ile Test ─────────────────────────────────────────────────────
    function telSorgula() {
        const veri = $('#telefonlar').val().trim();
        if (!veri) { showToast('En az bir telefon numarası girin', 'warning'); return; }

        const $b = $('#btnTelSorgula');
        butonBekle($b, true, 'IRIS sorgulanıyor...');
        $('#telSonuc').html('<p class="text-muted">Sorgulanıyor…</p>');

        $.post('', { action: 'tel_sorgula', telefonlar: veri }, function (r) {
            butonBekle($b, false);
            if (!r.success) { showToast(r.message || 'Sorgu hatası', 'error'); $('#telSonuc').html(''); return; }
            telSonucCiz(r.data);
        }, 'json').fail(function () {
            butonBekle($b, false);
            showToast('Sunucu hatası', 'error');
            $('#telSonuc').html('');
        });
    }

    function telSonucCiz(liste) {
        if (!liste.length) { $('#telSonuc').html('<p class="text-muted">Sonuç yok.</p>'); return; }

        let html = '';
        // Toplu kaydetme çubuğu — yazılabilir satır varsa gösterilir
        if (permissions.canEdit) {
            html += `<div class="d-flex align-items-center gap-3 mb-3 p-2 bg-light rounded" id="telTopluCubuk" style="display:none!important">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="telHepsi">
                            <label class="form-check-label" for="telHepsi">Tümünü seç</label>
                        </div>
                        <span class="text-muted small" id="telSecimSayi">0 kayıt seçildi</span>
                        <button class="btn btn-sm btn-success ms-auto" id="btnTelTopluKaydet" disabled>
                            <i class="bi bi-save"></i> Seçilenleri Kaydet
                        </button>
                     </div>`;
        }
        liste.forEach((s, si) => {
            const durumSinif = s.sonuc === 'eslesti' ? '' :
                               s.sonuc === 'talep_yok' ? 'yok' :
                               s.sonuc === 'personel_eslesmedi' ? 'personel' : 'hata';
            const rozet = {
                eslesti:            '<span class="sonuc-badge s-eslesti">Eşleşti</span>',
                talep_yok:          '<span class="sonuc-badge s-yok">IRIS\'te talep yok</span>',
                personel_eslesmedi: '<span class="sonuc-badge s-personel">Personel eşleşmedi</span>',
                gecersiz:           '<span class="sonuc-badge s-hata">Geçersiz numara</span>',
                hata:               '<span class="sonuc-badge s-hata">Hata</span>'
            }[s.sonuc] || '';

            html += `<div class="card mb-3 tel-kart ${durumSinif}">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-telephone"></i> <strong class="mono">${escapeHtml(s.telefon)}</strong> ${rozet}</span>
                    <span class="text-muted small">${s.adaylar.length} talep · ${s.basvurular.length} başvuru kaydı</span>
                </div>
                <div class="card-body">`;

            if (s.mesaj) html += `<div class="alert alert-warning py-2">${escapeHtml(s.mesaj)}</div>`;

            if (s.adaylar.length) {
                html += `<div class="table-responsive"><table class="table table-sm table-bordered mb-3">
                    <thead><tr><th>Talep No</th><th>Müşteri No</th><th>Memo ID</th><th>Tip</th><th>IRIS Üye</th>
                    <th>Durum</th><th>Talep Kaydeden</th><th>Personel</th><th>Birim</th></tr></thead><tbody>`;
                s.adaylar.forEach(a => {
                    html += `<tr>
                        <td class="mono">${escapeHtml(a.talepKayitNo)}
                            ${a.sahipId ? '<br><span class="badge bg-secondary" title="Bu talep başka bir başvuruya bağlı">'
                                          + 'bağlı: #' + a.sahipId + '</span>' : ''}</td>
                        <td class="mono">${escapeHtml(a.musteriNo)}</td>
                        <td class="mono">${escapeHtml(a.memoId)}</td>
                        <td>${escapeHtml(a.tip)}</td>
                        <td>${escapeHtml(a.uye)}</td>
                        <td>${escapeHtml(a.durumAdi)} ${a.surecId ? '<span class="text-muted">(#' + a.surecId + ')</span>' : ''}</td>
                        <td>${escapeHtml(a.kaydeden || '-')}</td>
                        <td>${a.personelId
                            ? '<span class="badge bg-success">' + escapeHtml(a.personelAd) + '</span>'
                            : '<span class="badge bg-secondary">eşleşme yok</span>'}</td>
                        <td>${birimRozet(a.birimAdi, a.birimRenk)}</td>
                    </tr>`;
                });
                html += `</tbody></table></div>`;
            }

            if (s.basvurular.length) {
                html += `<h6 class="mb-2">Bu telefona ait başvuru kayıtları
                    ${s.basvurular.length > 1
                        ? '<span class="badge bg-warning text-dark ms-1"><i class="bi bi-files"></i> '
                          + s.basvurular.length + ' mükerrer kayıt</span>'
                        : ''}</h6>`;
                html += `
                    <div class="table-responsive"><table class="table table-sm table-bordered mb-0">
                    <thead><tr><th style="width:36px"></th><th>ID</th><th>Ad Soyad</th><th>Talep No</th><th>Müşteri No</th><th>Memo</th>
                    <th>Personel</th><th>Süreç</th><th style="width:150px">İşlem</th></tr></thead><tbody>`;
                s.basvurular.forEach((b, bi) => {
                    const eslesen = s.adaylar.find(a => a.personelId);
                    const celisti = eslesen && b.AltBayiPersonel_ID && Number(b.AltBayiPersonel_ID) !== Number(eslesen.personelId);
                    // Bu başvuru zaten o talebe bağlı mı (kaydedildikten sonra bu duruma geçer)
                    const zatenBagli = eslesen && b.TalepKayitNo
                        && Number(b.TalepKayitNo) === Number(eslesen.talepKayitNo);
                    // Yazılabilir = eşleşen talep var, talep başka kayda bağlı değil, yetki var
                    const yazilabilir = permissions.canEdit && eslesen && !zatenBagli
                        && !(eslesen.sahipId && Number(eslesen.sahipId) !== Number(b.Basvurular_id));
                    html += `<tr class="${celisti ? 'celisti' : ''}">
                        <td class="text-center">${yazilabilir
                            ? `<div class="form-check form-switch d-flex justify-content-center mb-0">
                                 <input class="form-check-input tel-satir-sec" type="checkbox" role="switch"
                                        data-si="${si}" data-bi="${bi}">
                                 <label class="form-check-label"></label>
                               </div>`
                            : ''}</td>
                        <td class="mono">${basvuruLink(b.Basvurular_id)}</td>
                        <td>${escapeHtml((b.Isim || '') + ' ' + (b.Soyisim || ''))}</td>
                        <td class="mono">${escapeHtml(b.TalepKayitNo || '-')}</td>
                        <td class="mono">${escapeHtml(b.MusteriNo || '-')}</td>
                        <td class="mono">${escapeHtml(b.MemoID || '-')}</td>
                        <td>${b.AltBayiPersonel_ID
                                ? escapeHtml(b.PersonelAd || ('#' + b.AltBayiPersonel_ID))
                                : '<span class="text-muted">-</span>'}${celisti ? ' <i class="bi bi-exclamation-triangle-fill text-warning" title="IRIS ile çelişiyor"></i>' : ''}</td>
                        <td>${b.BasvuruSurecDurum_ID
                                ? escapeHtml(b.SurecAd || b.BasvuruSurecDurum_ID)
                                : '<span class="text-muted">-</span>'}</td>
                        <td>`;
                    const talepBaskasinda = eslesen && eslesen.sahipId
                                            && Number(eslesen.sahipId) !== Number(b.Basvurular_id);
                    if (zatenBagli) {
                        html += `<span class="badge bg-success" title="Talep ${eslesen.talepKayitNo} bu kayda bağlı">
                                    <i class="bi bi-check-circle"></i> Kaydedildi</span>`;
                    } else if (permissions.canEdit && eslesen && talepBaskasinda) {
                        html += `<span class="badge bg-secondary" title="Talep ${eslesen.talepKayitNo} zaten #${eslesen.sahipId} kaydına bağlı">
                                    <i class="bi bi-lock"></i> #${eslesen.sahipId} kaydında</span>`;
                    } else if (permissions.canEdit && eslesen) {
                        html += `<button class="btn btn-sm btn-success" onclick="tekilKaydet(${si}, ${bi})">
                                    <i class="bi bi-save"></i> Bu kayda yaz</button>`;
                    } else if (!eslesen) {
                        html += `<span class="text-muted small">eşleşen talep yok</span>`;
                    } else {
                        html += `<span class="text-muted small">yetki yok</span>`;
                    }
                    html += `</td></tr>`;
                });
                html += `</tbody></table></div>`;
            } else if (s.sonuc !== 'gecersiz') {
                html += `<p class="text-muted mb-0">Bu telefonla eşleşen başvuru kaydı yok.</p>`;
            }

            html += `</div></div>`;
        });

        $('#telSonuc').html(html);
        window._telSonuc = liste;

        // Yazılabilir satır yoksa toplu çubuk gizli kalır
        if ($('#telSonuc .tel-satir-sec').length) {
            $('#telTopluCubuk').attr('style', '');
        }
        telSecimGuncelle();
    }

    /** Seçim sayacı ve buton durumu */
    function telSecimGuncelle() {
        const n = $('#telSonuc .tel-satir-sec:checked').length;
        $('#telSecimSayi').text(n + ' kayıt seçildi');
        $('#btnTelTopluKaydet').prop('disabled', n === 0);

        const toplam = $('#telSonuc .tel-satir-sec').length;
        $('#telHepsi').prop('checked', toplam > 0 && n === toplam);
    }

    /**
     * Kaydedilen satırı IRIS'e tekrar gitmeden yerel olarak günceller.
     * Eskiden telSorgula() çağrılıyordu; bu, girilen TÜM numaraları yeniden
     * sorguladığı için her kaydetmede gereksiz IRIS trafiği oluşuyordu.
     */
    function telSatirGuncelle(si, bi, aday) {
        const s = (window._telSonuc || [])[si];
        if (!s) return;
        const b = s.basvurular[bi];
        if (!b) return;

        b.TalepKayitNo         = aday.talepKayitNo;
        b.MusteriNo            = aday.musteriNo || b.MusteriNo;
        b.MemoID               = aday.memoId    || b.MemoID;
        b.AltBayiPersonel_ID   = aday.personelId;
        b.PersonelAd           = aday.personelAd;
        b.BasvuruSurecDurum_ID = aday.surecId;
        b.SurecAd              = aday.durumMesaj || aday.durumAdi;
        if (aday.isim)    b.Isim    = aday.isim;
        if (aday.soyisim) b.Soyisim = aday.soyisim;

        // Talep artık bu kayda bağlı: diğer başvurular için kilitli görünmeli
        s.adaylar.forEach(a => {
            if (Number(a.talepKayitNo) === Number(aday.talepKayitNo)) a.sahipId = Number(b.Basvurular_id);
        });

        telSonucCiz(window._telSonuc);
    }

    function tekilKaydet(sonucIndex, basvuruIndex) {
        const s = (window._telSonuc || [])[sonucIndex];
        if (!s) return;
        const a = s.adaylar.find(x => x.personelId);
        const b = s.basvurular[basvuruIndex];
        if (!a || !b) { showToast('Eşleşen talep yok', 'warning'); return; }

        const eskiPersonel = b.AltBayiPersonel_ID
            ? escapeHtml(b.PersonelAd || ('#' + b.AltBayiPersonel_ID))
            : 'boş';
        const personelDegisiyor = Number(b.AltBayiPersonel_ID || 0) !== Number(a.personelId);
        const personelSatir = personelDegisiyor
            ? `<b>Personel:</b> <span class="text-decoration-line-through text-muted">${eskiPersonel}</span>
               → <b class="text-success">${escapeHtml(a.personelAd)}</b>`
            : `<b>Personel:</b> ${escapeHtml(a.personelAd)} <span class="text-muted">(değişmiyor)</span>`;

        Swal.fire({
            title: 'Kaydedilsin mi?',
            html: `<div class="text-start small">
                     <b>Başvuru:</b> #${b.Basvurular_id} ${escapeHtml((b.Isim || '') + ' ' + (b.Soyisim || ''))}<br>
                     <b>Talep No:</b> ${a.talepKayitNo}<br>
                     <b>Müşteri No:</b> ${a.musteriNo || '-'}<br>
                     <b>Memo ID:</b> ${a.memoId || '-'}<br>
                     <b>Süreç:</b> ${escapeHtml(a.durumAdi || a.surecId || '-')}<br>
                     ${personelSatir}
                   </div>`,
            icon: 'question', showCancelButton: true,
            confirmButtonText: 'Kaydet', cancelButtonText: 'Vazgeç'
        }).then(res => {
            if (!res.isConfirmed) return;
            $.post('', {
                action: 'tekil_kaydet',
                basvuru_id: b.Basvurular_id,
                talep_kayit_no: a.talepKayitNo,
                musteri_no: a.musteriNo || '',
                memo_id: a.memoId || '',
                surec_id: a.surecId || '',
                personel_id: a.personelId,
                durum_mesaj: a.durumMesaj || '',
                isim: a.isim || '',
                soyisim: a.soyisim || ''
            }, function (r) {
                showToast(r.message || (r.success ? 'Kaydedildi' : 'Hata'), r.success ? 'success' : 'error');
                // IRIS'e tekrar sorulmaz; satır yerel state üzerinden tazelenir
                if (r.success) { loadStats(); telSatirGuncelle(sonucIndex, basvuruIndex, a); }
            }, 'json');
        });
    }

    /** Telefon sekmesi — seçili satırları tek istekte kaydeder */
    function telTopluKaydet() {
        const secili = [];
        $('#telSonuc .tel-satir-sec:checked').each(function () {
            const si = $(this).data('si'), bi = $(this).data('bi');
            const s  = (window._telSonuc || [])[si];
            if (!s) return;
            const b = s.basvurular[bi];
            const a = s.adaylar.find(x => x.personelId);
            if (!a || !b) return;
            secili.push({
                id: b.Basvurular_id, talepKayitNo: a.talepKayitNo,
                musteriNo: a.musteriNo || '', memoId: a.memoId || '',
                surecId: a.surecId || '', personelId: a.personelId,
                durumMesaj: a.durumMesaj || '', isim: a.isim || '', soyisim: a.soyisim || '',
                _si: si, _bi: bi
            });
        });
        if (!secili.length) { showToast('Kaydedilecek satır seçilmedi', 'warning'); return; }

        const liste = secili.map(x =>
            `#${x.id} → talep ${x.talepKayitNo}`).join('<br>');

        Swal.fire({
            title: `${secili.length} kayıt kaydedilsin mi?`,
            html: `<div class="text-start small">${liste}</div>
                   <div class="text-muted small mt-2">Personel, IRIS'teki Talep Kaydeden ile değiştirilir.</div>`,
            icon: 'question', showCancelButton: true,
            confirmButtonText: 'Kaydet', cancelButtonText: 'Vazgeç'
        }).then(res => {
            if (!res.isConfirmed) return;
            const $b = $('#btnTelTopluKaydet');
            butonBekle($b, true, 'Kaydediliyor...');

            // Toplu Eşleştirme sekmesiyle aynı uç; personeli_ez=1 tekil butonun davranışıyla eşleşir
            $.post('', {
                action: 'eslesme_kaydet',
                satirlar: JSON.stringify(secili.map(({ _si, _bi, ...v }) => v)),
                personeli_ez: 1
            }, function (r) {
                butonBekle($b, false);
                showToast(r.message || (r.success ? 'Kaydedildi' : 'Hata'), r.success ? 'success' : 'error');
                if (r.hatalar && r.hatalar.length) {
                    Swal.fire({ title: 'Atlanan kayıtlar', icon: 'warning',
                                html: `<div class="text-start small">${r.hatalar.map(escapeHtml).join('<br>')}</div>` });
                }
                if (!r.success) return;

                loadStats();
                // IRIS'e tekrar sorulmaz; kaydedilen satırlar yerel state'te tazelenir
                secili.forEach(x => {
                    const s = (window._telSonuc || [])[x._si];
                    const b = s && s.basvurular[x._bi];
                    if (!b) return;
                    b.TalepKayitNo         = x.talepKayitNo;
                    b.MusteriNo            = x.musteriNo || b.MusteriNo;
                    b.MemoID               = x.memoId    || b.MemoID;
                    b.AltBayiPersonel_ID   = x.personelId;
                    b.PersonelAd           = s.adaylar.find(a => a.personelId)?.personelAd || b.PersonelAd;
                    b.BasvuruSurecDurum_ID = x.surecId;
                    b.SurecAd              = x.durumMesaj || b.SurecAd;
                    if (x.isim)    b.Isim    = x.isim;
                    if (x.soyisim) b.Soyisim = x.soyisim;
                    s.adaylar.forEach(a => {
                        if (Number(a.talepKayitNo) === Number(x.talepKayitNo)) a.sahipId = Number(b.Basvurular_id);
                    });
                });
                telSonucCiz(window._telSonuc);
            }, 'json').fail(function () {
                butonBekle($b, false);
                showToast('Sunucu hatası', 'error');
            });
        });
    }

    // ── Toplu Eşleştirme ─────────────────────────────────────────────────────
    function topluTara() {
        const $b = $('#btnTopluTara');
        butonBekle($b, true, 'Taranıyor...');
        $('#topluTable tbody').html('<tr><td colspan="11" class="text-center text-muted">IRIS sorgulanıyor…</td></tr>');
        $('#topluOzet').html('');
        $('#btnTopluKaydet').prop('disabled', true);

        $.post('', {
            action: 'toplu_tara',
            adet: $('#toplu_adet').val(),
            sira: $('#toplu_sira').val(),
            bekleme_gun: $('#toplu_bekleme').val(),
            sadece_bos_personel: $('#toplu_sadece_bos').is(':checked') ? 1 : 0
        }, function (r) {
            butonBekle($b, false);
            if (!r.success) { showToast(r.message || 'Tarama hatası', 'error'); $('#topluTable tbody').html(''); return; }
            topluSatirlar = r.data || [];
            topluCiz(r.ozet || {});
        }, 'json').fail(function () {
            butonBekle($b, false);
            showToast('Sunucu hatası (istek zaman aşımına uğramış olabilir)', 'error');
            $('#topluTable tbody').html('');
        });
    }

    function topluCiz(ozet) {
        $('#topluOzet').html(
            `<div class="alert alert-info py-2 mb-0">
                <b>Eşleşti:</b> ${ozet.eslesti || 0} &nbsp;|&nbsp;
                <b>Personel eşleşmedi:</b> ${ozet.personel_eslesmedi || 0} &nbsp;|&nbsp;
                <b>Talep başka kayıtta:</b> ${ozet.talep_kullanimda || 0} &nbsp;|&nbsp;
                <b>Hata:</b> ${ozet.hata || 0}
                ${ozet.talep_yok ? '<br><span class="text-muted">' + ozet.talep_yok
                    + ' kayıtta IRIS\'te talep bulunamadı — listelenmedi.</span>' : ''}
                <br><span class="text-muted">Başvuru verisi değişmedi — kaydetmek için satırları seçin.
                ${ozet.damgalanan ? ozet.damgalanan + ' kaydın kontrol tarihi güncellendi, bir sonraki taramada sıra diğerlerine geçer.' : ''}</span>
                ${ozet.damgaHata ? '<br><span class="text-danger"><b>⚠ ' + escapeHtml(ozet.damgaHata) + '</b></span>' : ''}
             </div>`);

        let html = '';
        topluSatirlar.forEach((s, i) => {
            const secilebilir = s.sonuc === 'eslesti';
            const rozet = {
                eslesti:            '<span class="sonuc-badge s-eslesti">Eşleşti</span>',
                talep_yok:          '<span class="sonuc-badge s-yok">Talep yok</span>',
                personel_eslesmedi: '<span class="sonuc-badge s-personel">Personel eşleşmedi</span>',
                talep_kullanimda:   '<span class="sonuc-badge s-kullanimda"><i class="bi bi-lock"></i> Talep başka kayıtta</span>',
                hata:               '<span class="sonuc-badge s-hata">Hata</span>'
            }[s.sonuc] || '';

            let personelHtml = '-';
            if (s.sonuc === 'eslesti') {
                personelHtml = escapeHtml(s.kaydeden) + ' → <b>#' + s.personelId + '</b> ' + escapeHtml(s.personelAd);
                if (s.personelCelisti) {
                    personelHtml += ` <i class="bi bi-exclamation-triangle-fill text-warning"
                        title="Mevcut kayıtta personel #${s.mevcutPersonelId} yazıyor"></i>`;
                }
            } else if (s.sonuc === 'talep_kullanimda') {
                personelHtml = `<span class="small">Talep <b>${escapeHtml(s.talepKayitNo)}</b> →
                                ${basvuruLink(s.sahipId)} kaydında</span>`;
            } else if (s.mesaj) {
                personelHtml = '<span class="text-muted small">' + escapeHtml(s.mesaj) + '</span>';
            }

            html += `<tr class="${s.personelCelisti ? 'celisti' : ''}">
                <td><input type="checkbox" class="satir-sec" data-i="${i}" ${secilebilir ? 'checked' : 'disabled'}></td>
                <td class="mono">${basvuruLink(s.id)}<br><span class="text-muted small">${escapeHtml(s.ad)}</span></td>
                <td class="mono">${escapeHtml(s.telefon)}${mukerrerRozet(s)}</td>
                <td>${rozet}</td>
                <td>${s.uye
                        ? (s.adDegisiyor
                            ? '<span class="text-decoration-line-through text-muted small">' + escapeHtml(s.ad) + '</span><br>'
                              + '<b class="text-success">' + escapeHtml(s.uye) + '</b>'
                            : escapeHtml(s.uye))
                        : '-'}</td>
                <td class="mono">${escapeHtml(s.talepKayitNo || '-')}</td>
                <td class="mono">${escapeHtml(s.musteriNo || '-')}</td>
                <td class="mono">${escapeHtml(s.memoId || '-')}</td>
                <td>${escapeHtml(s.surecId || '-')}</td>
                <td>${personelHtml}</td>
                <td>${birimRozet(s.birimAdi, s.birimRenk)}</td>
            </tr>`;
        });
        $('#topluTable tbody').html(html ||
            '<tr><td colspan="11" class="text-center text-muted">Eşleştirilebilecek talep bulunamadı</td></tr>');
        $('#topluHepsi').prop('checked', false);
        topluSecimGuncelle();
    }

    function topluSecimGuncelle() {
        const n = $('#topluTable tbody input.satir-sec:checked').length;
        $('#btnTopluKaydet').prop('disabled', n === 0 || !permissions.canEdit)
            .html('<i class="bi bi-save"></i> Seçilenleri Kaydet' + (n ? ' (' + n + ')' : ''));
    }

    function topluKaydet() {
        const secili = [];
        $('#topluTable tbody input.satir-sec:checked').each(function () {
            secili.push(topluSatirlar[$(this).data('i')]);
        });
        if (!secili.length) { showToast('Satır seçilmedi', 'warning'); return; }

        const ez = $('#toplu_personeli_ez').is(':checked');
        Swal.fire({
            title: secili.length + ' kayıt güncellenecek',
            html: `<div class="text-start small">
                     Talep No, Müşteri No, Memo ID ve Süreç Durumu yazılacak.<br>
                     Personel: ${ez ? '<b class="text-danger">dolu olanlar da ezilecek</b>' : 'yalnızca boş olanlara yazılacak'}<br>
                     İsim/Soyisim: <b>IRIS üye bilgisiyle güncellenecek</b>
                     (${secili.filter(x => x.adDegisiyor).length} kayıtta ad değişiyor).
                   </div>`,
            icon: 'warning', showCancelButton: true,
            confirmButtonText: 'Kaydet', cancelButtonText: 'Vazgeç'
        }).then(res => {
            if (!res.isConfirmed) return;
            const $b = $('#btnTopluKaydet');
            butonBekle($b, true, 'Kaydediliyor...');
            $.post('', { action: 'eslesme_kaydet', satirlar: JSON.stringify(secili), personeli_ez: ez ? 1 : 0 },
                function (r) {
                    butonBekle($b, false);
                    showToast(r.message || (r.success ? 'Kaydedildi' : 'Hata'), r.success ? 'success' : 'error');
                    if (r.success) { loadStats(); topluTara(); }
                }, 'json').fail(function () {
                    butonBekle($b, false);
                    showToast('Sunucu hatası', 'error');
                });
        });
    }

    // ── Süreç Durumu ─────────────────────────────────────────────────────────
    // Verinin nereden geldiğini gösterir; rapor satırlarında veri yaşı da yazılır.
    function kaynakRozet(s) {
        if (s.kaynak !== 'rapor') {
            return '<span class="badge bg-primary">Canlı API</span>';
        }
        let yas = '';
        if (s.veriTarihi) {
            const t = new Date(s.veriTarihi.replace(' ', 'T'));
            if (!isNaN(t)) {
                const saat = Math.floor((Date.now() - t.getTime()) / 3600000);
                yas = saat < 1 ? '1 saatten yeni'
                    : (saat < 48 ? saat + ' saat önce' : Math.floor(saat / 24) + ' gün önce');
            }
        }
        return '<span class="badge bg-secondary">IRIS Raporu</span>'
             + (yas ? '<br><span class="text-muted">' + escapeHtml(yas) + '</span>' : '');
    }

    function durumTara() {
        const $b = $('#btnDurumTara');
        butonBekle($b, true, 'Sorgulanıyor...');
        $('#durumTable tbody').html('<tr><td colspan="8" class="text-center text-muted">Sorgulanıyor…</td></tr>');
        $('#durumOzet').html('');
        $('#btnDurumKaydet').prop('disabled', true);

        $.post('', {
            action: 'durum_tara',
            adet: $('#durum_adet').val(),
            bekleme_gun: $('#durum_bekleme').val(),
            sadece_bos: $('#durum_sadece_bos').is(':checked') ? 1 : 0,
            final_haric: $('#durum_final_haric').is(':checked') ? 1 : 0
        }, function (r) {
            butonBekle($b, false);
            if (!r.success) { showToast(r.message || 'Sorgu hatası', 'error'); $('#durumTable tbody').html(''); return; }
            durumSatirlar = r.data || [];
            durumCiz(r.ozet || {});
        }, 'json').fail(function () {
            butonBekle($b, false);
            showToast('Sunucu hatası', 'error');
            $('#durumTable tbody').html('');
        });
    }

    function durumCiz(ozet) {
        $('#durumOzet').html(
            `<div class="alert alert-info py-2 mb-0">
                <b>Sorgulanan:</b> ${ozet.sorgulanan || 0} &nbsp;|&nbsp;
                <b>Dönen:</b> ${ozet.donen || 0} &nbsp;|&nbsp;
                <b>Değişecek:</b> ${ozet.degisen || 0} &nbsp;|&nbsp;
                <b>Yanıt gelmeyen:</b> ${ozet.donmeyen || 0}
                <br><span class="text-muted">
                    Kaynak: canlı API (CheckRequisition) <b>${ozet.apiden || 0}</b>.
                    Süreç durumu yazılmadı — kaydetmek için satırları seçin.
                    ${ozet.damgalanan ? ozet.damgalanan + ' kaydın kontrol tarihi güncellendi.' : ''}</span>
                ${ozet.damgaHata ? '<br><span class="text-danger"><b>⚠ ' + escapeHtml(ozet.damgaHata) + '</b></span>' : ''}
             </div>`);

        let html = '';
        durumSatirlar.forEach((s, i) => {
            html += `<tr>
                <td><input type="checkbox" class="satir-sec" data-i="${i}" checked></td>
                <td class="mono">${basvuruLink(s.id)}<br><span class="text-muted small">${escapeHtml(s.ad)}</span></td>
                <td class="mono">${escapeHtml(s.talepKayitNo)}</td>
                <td>${s.eskiSurecId ? escapeHtml(s.eskiSurecAd || s.eskiSurecId) : '<span class="text-muted">boş</span>'}</td>
                <td><span class="surec-badge" style="background:${escapeHtml(s.yeniSurecRenk || '#6c757d')}">${escapeHtml(s.yeniSurecAd || s.yeniSurecId)}</span></td>
                <td>${escapeHtml(s.bayi || '-')}</td>
                <td class="small">${escapeHtml(s.islemDurum || '-')}</td>
                <td class="small">${kaynakRozet(s)}</td>
            </tr>`;
        });
        $('#durumTable tbody').html(html || '<tr><td colspan="8" class="text-center text-muted">Değişen kayıt yok</td></tr>');
        $('#durumHepsi').prop('checked', durumSatirlar.length > 0);
        durumSecimGuncelle();
    }

    function durumSecimGuncelle() {
        const n = $('#durumTable tbody input.satir-sec:checked').length;
        $('#btnDurumKaydet').prop('disabled', n === 0 || !permissions.canEdit)
            .html('<i class="bi bi-save"></i> Seçilenleri Kaydet' + (n ? ' (' + n + ')' : ''));
    }

    function durumKaydet() {
        const secili = [];
        $('#durumTable tbody input.satir-sec:checked').each(function () {
            secili.push(durumSatirlar[$(this).data('i')]);
        });
        if (!secili.length) { showToast('Satır seçilmedi', 'warning'); return; }

        Swal.fire({
            title: secili.length + ' kaydın süreç durumu güncellenecek',
            icon: 'warning', showCancelButton: true,
            confirmButtonText: 'Kaydet', cancelButtonText: 'Vazgeç'
        }).then(res => {
            if (!res.isConfirmed) return;
            const $b = $('#btnDurumKaydet');
            butonBekle($b, true, 'Kaydediliyor...');
            $.post('', { action: 'durum_kaydet', satirlar: JSON.stringify(secili) }, function (r) {
                butonBekle($b, false);
                showToast(r.message || (r.success ? 'Kaydedildi' : 'Hata'), r.success ? 'success' : 'error');
                if (r.success) { loadStats(); durumTara(); }
            }, 'json').fail(function () {
                butonBekle($b, false);
                showToast('Sunucu hatası', 'error');
            });
        });
    }
</script>
</body>
</html>
