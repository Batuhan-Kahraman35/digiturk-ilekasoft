<?php
/**
 * Admin Panel - API Kampanyalar
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'API Kampanyalar';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ISP senkronizasyon sabitleri
// Fiyat ve hız varyantları BBK adresine bağlı geldiği için sabit referans adresler kullanılır.
// Fiber adres 35–1000 Mbps, VDSL adres 16–50 Mbps döndürür; ikisi birleşince katalog tamamlanır.
// (100 Mbps XDSL varyantı bu iki adreste bulunmadığı için eksik kalır.)
const ISP_REFERANS_BBK  = ['99944924', '9977316'];
const ISP_TUR_ID        = 3;
const ISP_ATLA_KODLAR   = ['YWV'];                      // STATIK IP — kampanya değil, ek hizmet
const ISP_KATEGORI_MAP  = [25 => 'ISP+NEO', 26 => 'ISP+UYDU'];

// Metinden PaketAdi türetir (NEO, UYDU ve ISP için ortak)
function paketAdiTuret($metin) {
    $paketAdiMap = [
        'Beşiktaş'    => 'Beşiktaş Taraftar Paketi',
        'Fenerbahçe'  => 'Fenerbahçe Taraftar Paketi',
        'Galatasaray' => 'Galatasaray Taraftar Paketi',
        'Trabzonspor' => 'Trabzonspor Taraftar Paketi',
        'Dolu'        => 'Yıldız Dolu Paketi',
        'Eğlence'     => 'Eğlence ve Avrupanın Yıldızı Paketi',
        'Sporun'      => 'Sporun Yıldızı Paketi',
    ];
    foreach ($paketAdiMap as $keyword => $paketAdi) {
        if (strpos((string)$metin, $keyword) !== false) return $paketAdi;
    }
    return null;
}

// offerToCode'dan hız ve altyapı türetir: PTS_YLN_VDSL_35E_SRV -> "35 Mbps VDSL"
// API'nin description alanı 20 karakterde kesildiği için hız kodun kendisinden okunur.
function ispHizAciklama($offerToCode) {
    if (!preg_match('/(FIBER|VDSL)_?(\d+)(G)?/i', (string)$offerToCode, $m)) return null;
    $hiz = (int)$m[2];
    if (!empty($m[3])) $hiz *= 1000;                    // 1G -> 1000 Mbps
    return $hiz . ' Mbps ' . (strtoupper($m[1]) === 'FIBER' ? 'Fiber' : 'VDSL');
}

// API senkronizasyon yardımcı fonksiyonları
function processEntity($db, $entityData, $userId, $tur, &$inserted, &$updated) {
    $kategoriAdi  = null;
    $entityResult = $entityData['entityResult'] ?? null;

    if ($entityResult && !empty($entityResult['entityJsonTypeList'])) {
        $jsonType    = json_decode($entityResult['entityJsonTypeList'], true);
        $kategoriAdi = $jsonType['GORUNEN_ISIM'] ?? null;
    }

    foreach (($entityData['offerResultList'] ?? []) as $offer) {
        upsertKampanya($db, $offer, $kategoriAdi, $userId, $tur, $inserted, $updated);
    }

    foreach (($entityData['subProductCatalogList'] ?? []) as $sub) {
        processEntity($db, $sub, $userId, $tur, $inserted, $updated);
    }
}

function upsertKampanya($db, $offer, $kategoriAdi, $userId, $tur, &$inserted, &$updated) {
    $offerFromCode = $offer['offerFromCode'] ?? null;
    $offerToCode   = $offer['offerToCode']   ?? null;
    if (!$offerFromCode || !$offerToCode) return;

    $existing = $db->fetchOne("
        SELECT APIKampanyalar_Id FROM APIKampanyalar
        WHERE APIKampanyalar_OfferFromCode = ?
          AND APIKampanyalar_OfferToCode   = ?
          AND ISNULL(APIKampanyalar_KategoriAdi, '') = ISNULL(?, '')
    ", [$offerFromCode, $offerToCode, $kategoriAdi]);

    // Açıklama'dan PaketAdi türet
    $derivedPaketAdi = paketAdiTuret($offer['description'] ?? '');

    // KategoriAdi'ndan OdemeTuru türet
    $odemeTuru = null;
    if ($kategoriAdi) {
        if (strpos($kategoriAdi, 'KREDI')   !== false) $odemeTuru = 1;
        elseif (strpos($kategoriAdi, 'FATURALI') !== false) $odemeTuru = 2;
    }

    // KategoriAdi'ndan Tur türet (endpoint'ten gelen $tur'u override eder)
    if ($kategoriAdi) {
        if (strpos($kategoriAdi, 'NEO')  !== false) $tur = 1;
        elseif (strpos($kategoriAdi, 'UYDU') !== false) $tur = 2;
    }

    $apiData = [
        'APIKampanyalar_Tur'           => $tur,
        'APIKampanyalar_OdemeTuru'     => $odemeTuru,
        'APIKampanyalar_KategoriAdi'   => $kategoriAdi,
        'APIKampanyalar_OfferFromCode' => $offerFromCode,
        'APIKampanyalar_OfferFromId'   => isset($offer['offerFromId'])  ? (int)$offer['offerFromId']   : null,
        'APIKampanyalar_OfferToCode'   => $offerToCode,
        'APIKampanyalar_OfferToId'     => isset($offer['offerToId'])    ? (int)$offer['offerToId']     : null,
        'APIKampanyalar_Ad'            => $offer['name']                ?? null,
        'APIKampanyalar_Aciklama'      => $offer['description']         ?? null,
        'APIKampanyalar_Fiyat'         => isset($offer['priceAmount'])  ? (float)$offer['priceAmount'] : null,
        'APIKampanyalar_ParaBirimi'    => $offer['currencyTypeCd']      ?? 'TL',
        'APIKampanyalar_FaturaDonemi'  => $offer['billFrequencyTypeCd'] ?? null,
        'GuncelleyenKullanici'         => $userId,
        'GuncellemeTarihi'             => date('Y-m-d H:i:s'),
    ];

    if ($derivedPaketAdi !== null) {
        $apiData['APIKampanyalar_PaketAdi'] = $derivedPaketAdi;
    }

    if ($existing) {
        $db->update('APIKampanyalar', $apiData, ['APIKampanyalar_Id' => $existing['APIKampanyalar_Id']]);
        $updated++;
    } else {
        $apiData['OlusturanKullanici'] = $userId;
        $apiData['OlusturmaTarihi']    = date('Y-m-d H:i:s');
        $apiData['Durum']              = 0;
        $db->insert('APIKampanyalar', $apiData);
        $inserted++;
    }
}

// ISP kampanyası kaydeder (endpoint 25/26)
// NEO/UYDU'dan farkları: fiyat alanı 'price' (priceAmount yok), kampanya kodu offerFromCode'da
// (name null gelir), TV paketi ayrı bir listede (bundleOfferResultList) ve fiyatı ana fiyata eklenir.
function upsertIspKampanya($db, $offer, $kategoriAdi, $userId, &$inserted, &$updated) {
    $offerFromCode = $offer['offerFromCode'] ?? null;
    $offerToCode   = $offer['offerToCode']   ?? null;
    if (!$offerFromCode || !$offerToCode) return;
    if (in_array($offerFromCode, ISP_ATLA_KODLAR, true)) return;

    // TV paketi olmayan satır (ör. tek başına ek hizmet) kampanya sayılmaz
    $bundleList = $offer['bundleOfferResultList'] ?? [];
    if (!$bundleList) return;

    // Fiyat = internet + TV paketi. STATIK IP ve modem opsiyonel olduğu için toplama girmez.
    $fiyat = (float)($offer['price'] ?? 0);
    foreach ($bundleList as $bundle) {
        $fiyat += (float)($bundle['price'] ?? 0);
    }

    $ilkBundle = $bundleList[0];

    $existing = $db->fetchOne("
        SELECT APIKampanyalar_Id FROM APIKampanyalar
        WHERE APIKampanyalar_OfferFromCode = ?
          AND APIKampanyalar_OfferToCode   = ?
          AND ISNULL(APIKampanyalar_KategoriAdi, '') = ISNULL(?, '')
    ", [$offerFromCode, $offerToCode, $kategoriAdi]);

    $apiData = [
        'APIKampanyalar_Tur'           => ISP_TUR_ID,
        'APIKampanyalar_KategoriAdi'   => $kategoriAdi,
        'APIKampanyalar_OfferFromCode' => $offerFromCode,
        'APIKampanyalar_OfferFromId'   => isset($offer['offerFromId']) ? (int)$offer['offerFromId'] : null,
        'APIKampanyalar_OfferToCode'   => $offerToCode,
        'APIKampanyalar_OfferToId'     => isset($offer['offerToId'])   ? (int)$offer['offerToId']   : null,
        'APIKampanyalar_Ad'            => $offerFromCode,
        'APIKampanyalar_Aciklama'      => ispHizAciklama($offerToCode),
        'APIKampanyalar_Fiyat'         => $fiyat,
        'APIKampanyalar_ParaBirimi'    => $ilkBundle['currencyTypeCd'] ?? $offer['currencyTypeCd'] ?? 'TL',
        'APIKampanyalar_FaturaDonemi'  => $offer['billFrequencyTypeCd'] ?? $ilkBundle['billFrequencyTypeCd'] ?? null,
        'GuncelleyenKullanici'         => $userId,
        'GuncellemeTarihi'             => date('Y-m-d H:i:s'),
    ];

    // PaketAdi bundle adından türetilir; ISP'de ana satırın description'ı hız bilgisi taşır
    $derivedPaketAdi = paketAdiTuret($ilkBundle['name'] ?? $ilkBundle['description'] ?? '');
    if ($derivedPaketAdi !== null) {
        $apiData['APIKampanyalar_PaketAdi'] = $derivedPaketAdi;
    }

    if ($existing) {
        $db->update('APIKampanyalar', $apiData, ['APIKampanyalar_Id' => $existing['APIKampanyalar_Id']]);
        $updated++;
    } else {
        $apiData['OlusturanKullanici'] = $userId;
        $apiData['OlusturmaTarihi']    = date('Y-m-d H:i:s');
        $apiData['Durum']              = 0;
        $db->insert('APIKampanyalar', $apiData);
        $inserted++;
    }
}

// Bir kodun tüm kayıtlarında bayrak: 1 = hepsinde var, 0 = hiçbirinde yok, null = karışık
function topluBayrakDurum($varOlan, $toplam) {
    if ((int)$varOlan === 0)       return 0;
    if ((int)$varOlan === (int)$toplam) return 1;
    return null;
}

// AJAX işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'list':
                $search       = $_POST['search']        ?? '';
                $aciklama     = $_POST['aciklama']      ?? '';
                $kod          = $_POST['kod']           ?? '';
                $paketAdi     = $_POST['paket_adi']     ?? '';
                $faturaDonemi = $_POST['fatura_donemi'] ?? '';
                $tur          = $_POST['tur']           ?? '';
                $odemeTuru    = $_POST['odeme_turu']    ?? '';
                $koiDurum     = $_POST['koi_durum']     ?? '';
                $status       = $_POST['status']        ?? '';
                $olusBas      = $_POST['olusturma_bas'] ?? '';
                $olusBit      = $_POST['olusturma_bit'] ?? '';
                $guncBas      = $_POST['guncelleme_bas'] ?? '';
                $guncBit      = $_POST['guncelleme_bit'] ?? '';

                $where  = ["1=1"];
                $params = [];

                if ($search) {
                    $where[]  = "(k.APIKampanyalar_Ad LIKE ? OR k.APIKampanyalar_KategoriAdi LIKE ? OR k.APIKampanyalar_OfferFromCode LIKE ? OR k.APIKampanyalar_OfferToCode LIKE ? OR k.APIKampanyalar_Aciklama LIKE ?)";
                    $params   = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%"]);
                }
                if ($aciklama) {
                    $where[]  = "k.APIKampanyalar_Aciklama LIKE ?";
                    $params[] = "%$aciklama%";
                }
                if ($kod) {
                    $where[]  = "k.APIKampanyalar_Ad = ?";
                    $params[] = $kod;
                }
                if ($paketAdi) {
                    $where[]  = "k.APIKampanyalar_PaketAdi LIKE ?";
                    $params[] = "%$paketAdi%";
                }
                if ($faturaDonemi) {
                    $where[]  = "k.APIKampanyalar_FaturaDonemi = ?";
                    $params[] = $faturaDonemi;
                }
                if ($tur !== '') {
                    $where[]  = "k.APIKampanyalar_Tur = ?";
                    $params[] = (int)$tur;
                }
                if ($odemeTuru !== '') {
                    $where[]  = "k.APIKampanyalar_OdemeTuru = ?";
                    $params[] = (int)$odemeTuru;
                }
                if ($koiDurum === 'koi') {
                    $where[] = "k.APIKampanyalar_KOI = 1";
                } elseif ($koiDurum === 'superkoi') {
                    $where[] = "k.APIKampanyalar_SUPERKOI = 1";
                }
                if ($status !== '') {
                    $where[]  = "k.Durum = ?";
                    $params[] = (int)$status;
                }
                if ($olusBas) {
                    $where[]  = "k.OlusturmaTarihi >= ?";
                    $params[] = $olusBas . ' 00:00:00';
                }
                if ($olusBit) {
                    $where[]  = "k.OlusturmaTarihi <= ?";
                    $params[] = $olusBit . ' 23:59:59';
                }
                if ($guncBas) {
                    $where[]  = "k.GuncellemeTarihi >= ?";
                    $params[] = $guncBas . ' 00:00:00';
                }
                if ($guncBit) {
                    $where[]  = "k.GuncellemeTarihi <= ?";
                    $params[] = $guncBit . ' 23:59:59';
                }

                $whereClause = implode(" AND ", $where);

                $list = $db->fetchAll("
                    SELECT
                        k.APIKampanyalar_Id,
                        k.APIKampanyalar_KategoriAdi,
                        k.APIKampanyalar_OfferFromCode,
                        k.APIKampanyalar_OfferFromId,
                        k.APIKampanyalar_OfferToCode,
                        k.APIKampanyalar_OfferToId,
                        k.APIKampanyalar_Ad,
                        k.APIKampanyalar_Aciklama,
                        k.APIKampanyalar_Fiyat,
                        k.APIKampanyalar_ParaBirimi,
                        k.APIKampanyalar_FaturaDonemi,
                        k.APIKampanyalar_PaketAdi,
                        k.APIKampanyalar_Hediye,
                        k.APIKampanyalar_KOI,
                        k.APIKampanyalar_SUPERKOI,
                        k.APIKampanyalar_Tur,
                        k.APIKampanyalar_OdemeTuru,
                        k.Durum,
                        t.APIKampanyaTurleri_Tur_Adi,
                        t.APIKampanyaTurleri_Renk,
                        t.APIKampanyaTurleri_Simge,
                        o.APIOdemeYontemleri_Ad,
                        CONVERT(VARCHAR(19), k.OlusturmaTarihi, 120)   as OlusturmaTarihi,
                        CONVERT(VARCHAR(19), k.GuncellemeTarihi, 120) as GuncellemeTarihi
                    FROM APIKampanyalar k
                    LEFT JOIN APIKampanyaTurleri t ON k.APIKampanyalar_Tur     = t.APIKampanyaTurleri_Id
                    LEFT JOIN APIOdemeYontemleri o ON k.APIKampanyalar_OdemeTuru = o.APIOdemeYontemleri_Id
                    WHERE $whereClause
                    ORDER BY k.APIKampanyalar_KategoriAdi, k.APIKampanyalar_Ad
                ", $params);

                echo json_encode(['success' => true, 'data' => $list]);
                break;

            case 'get':
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("SELECT * FROM APIKampanyalar WHERE APIKampanyalar_Id = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $row]);
                break;

            case 'save':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz kayıt!']);
                    break;
                }

                $data = [
                    'APIKampanyalar_Tur'       => ($_POST['tur'] ?? '')        ?: null,
                    'APIKampanyalar_OdemeTuru'  => ($_POST['odeme_turu'] ?? '') ?: null,
                    'APIKampanyalar_PaketAdi'   => trim($_POST['paket_adi'] ?? '') ?: null,
                    'APIKampanyalar_Hediye'     => trim($_POST['hediye'] ?? '')    ?: null,
                    'APIKampanyalar_KOI'        => isset($_POST['koi'])      ? 1 : 0,
                    'APIKampanyalar_SUPERKOI'   => isset($_POST['superkoi']) ? 1 : 0,
                    'Durum'                     => isset($_POST['durum'])    ? 1 : 0,
                    'GuncelleyenKullanici'      => $user['kullanici_id'],
                    'GuncellemeTarihi'          => date('Y-m-d H:i:s'),
                ];

                $result = $db->update('APIKampanyalar', $data, ['APIKampanyalar_Id' => $id]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kayıt güncellendi' : 'Güncelleme hatası']);
                break;

            // Listede seçilen kayıtlarda yalnız işaretlenen alanları günceller
            case 'toplu_guncelle':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }

                $idler   = json_decode($_POST['idler']   ?? '[]', true);
                $alanlar = json_decode($_POST['alanlar'] ?? '{}', true);
                if (!is_array($idler))   $idler   = [];
                if (!is_array($alanlar)) $alanlar = [];

                $idler = array_values(array_unique(array_filter(array_map('intval', $idler))));
                if (!$idler) {
                    echo json_encode(['success' => false, 'message' => 'Güncellenecek kayıt seçilmedi!']);
                    break;
                }
                if (count($idler) > 5000) {
                    echo json_encode(['success' => false, 'message' => 'En fazla 5000 kayıt güncellenebilir!']);
                    break;
                }

                // Yalnızca gönderilen alanlar yazılır; gönderilmeyenlere dokunulmaz
                $data = [];
                if (array_key_exists('durum', $alanlar))
                    $data['Durum'] = !empty($alanlar['durum']) ? 1 : 0;
                if (array_key_exists('koi', $alanlar))
                    $data['APIKampanyalar_KOI'] = !empty($alanlar['koi']) ? 1 : 0;
                if (array_key_exists('superkoi', $alanlar))
                    $data['APIKampanyalar_SUPERKOI'] = !empty($alanlar['superkoi']) ? 1 : 0;
                if (array_key_exists('hediye', $alanlar))
                    $data['APIKampanyalar_Hediye'] = trim((string)$alanlar['hediye']) ?: null;
                if (array_key_exists('paket_adi', $alanlar))
                    $data['APIKampanyalar_PaketAdi'] = trim((string)$alanlar['paket_adi']) ?: null;
                if (array_key_exists('tur', $alanlar))
                    $data['APIKampanyalar_Tur'] = ($alanlar['tur'] ?? '') !== '' ? (int)$alanlar['tur'] : null;
                if (array_key_exists('odeme_turu', $alanlar))
                    $data['APIKampanyalar_OdemeTuru'] = ($alanlar['odeme_turu'] ?? '') !== '' ? (int)$alanlar['odeme_turu'] : null;

                if (!$data) {
                    echo json_encode(['success' => false, 'message' => 'Güncellenecek alan seçilmedi!']);
                    break;
                }

                $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');

                // Kolon adları sabit listeden gelir, değerler parametreli gider
                $setler  = [];
                $params  = [];
                foreach ($data as $kolon => $deger) {
                    $setler[] = "$kolon = ?";
                    $params[] = $deger;
                }
                $idPh   = implode(',', array_fill(0, count($idler), '?'));
                $params = array_merge($params, $idler);

                $db->execute("
                    UPDATE APIKampanyalar
                    SET " . implode(', ', $setler) . "
                    WHERE APIKampanyalar_Id IN ($idPh)
                ", $params);

                $alanSayisi = count($data) - 2;   // GuncelleyenKullanici + GuncellemeTarihi hariç
                echo json_encode([
                    'success' => true,
                    'message' => count($idler) . " kayıtta $alanSayisi alan güncellendi.",
                ]);
                break;

            case 'toggle_durum':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }
                $id    = (int)($_POST['id']    ?? 0);
                $durum = (int)($_POST['durum'] ?? 0);
                $result = $db->update('APIKampanyalar', [
                    'Durum'                => $durum,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['APIKampanyalar_Id' => $id]);
                echo json_encode(['success' => (bool)$result]);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id     = (int)($_POST['id'] ?? 0);
                $result = $db->delete('APIKampanyalar', ['APIKampanyalar_Id' => $id]);
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Kampanya silindi' : 'Silme hatası']);
                break;

            // Toplu kampanya kodu aktifleştirme — önizleme
            case 'toplu_aktif_onizle':
                $gelen = json_decode($_POST['kodlar'] ?? '[]', true);
                if (!is_array($gelen)) $gelen = [];

                $temiz = [];   // kod => ['koi' => 0|1, 'superkoi' => 0|1]
                foreach ($gelen as $g) {
                    $k = trim((string)($g['kod'] ?? ''));
                    if ($k === '' || isset($temiz[$k])) continue;
                    $hediye = trim((string)($g['hediye'] ?? ''));
                    $temiz[$k] = [
                        'koi'      => !empty($g['koi'])      ? 1 : 0,
                        'superkoi' => !empty($g['superkoi']) ? 1 : 0,
                        'hediye'   => $hediye !== '' ? $hediye : null,
                    ];
                }
                if (!$temiz) {
                    echo json_encode(['success' => false, 'message' => 'Listede geçerli kampanya kodu bulunamadı!']);
                    break;
                }
                if (count($temiz) > 2000) {
                    echo json_encode(['success' => false, 'message' => 'En fazla 2000 kampanya kodu işlenebilir!']);
                    break;
                }

                $kodListe = array_keys($temiz);
                $ph       = implode(',', array_fill(0, count($kodListe), '?'));
                $eslesen  = $db->fetchAll("
                    SELECT
                        APIKampanyalar_Ad as kod,
                        COUNT(*)          as adet,
                        SUM(CASE WHEN Durum = 1                   THEN 1 ELSE 0 END) as aktif,
                        SUM(CASE WHEN APIKampanyalar_KOI = 1      THEN 1 ELSE 0 END) as koi,
                        SUM(CASE WHEN APIKampanyalar_SUPERKOI = 1 THEN 1 ELSE 0 END) as superkoi,
                        MIN(ISNULL(APIKampanyalar_Hediye, '')) as hediye_min,
                        MAX(ISNULL(APIKampanyalar_Hediye, '')) as hediye_max
                    FROM APIKampanyalar
                    WHERE APIKampanyalar_Ad IN ($ph)
                    GROUP BY APIKampanyalar_Ad
                ", $kodListe);

                $map = [];
                foreach ($eslesen as $e) $map[$e['kod']] = $e;

                $satirlar = [];
                foreach ($temiz as $kod => $bayrak) {
                    $adet = isset($map[$kod]) ? (int)$map[$kod]['adet'] : 0;
                    $satirlar[] = [
                        'kod'             => $kod,
                        'adet'            => $adet,
                        'aktif'           => $adet ? (int)$map[$kod]['aktif'] : 0,
                        'koi'             => $bayrak['koi'],
                        'superkoi'        => $bayrak['superkoi'],
                        'hediye'          => $bayrak['hediye'],
                        // Kodun tüm kayıtlarında hediye aynıysa o değer, farklıysa null (karışık)
                        'mevcut_hediye'   => ($adet && $map[$kod]['hediye_min'] === $map[$kod]['hediye_max'])
                                                ? $map[$kod]['hediye_min'] : null,
                        'hediye_karisik'  => (bool)($adet && $map[$kod]['hediye_min'] !== $map[$kod]['hediye_max']),
                        // 1 = tüm kayıtlarda var, 0 = hiçbirinde yok, null = karışık
                        'mevcut_koi'      => $adet ? topluBayrakDurum((int)$map[$kod]['koi'], $adet)      : null,
                        'mevcut_superkoi' => $adet ? topluBayrakDurum((int)$map[$kod]['superkoi'], $adet) : null,
                    ];
                }

                $pasifOlacak = $db->fetchOne("
                    SELECT COUNT(*) as c FROM APIKampanyalar
                    WHERE Durum = 1 AND ISNULL(APIKampanyalar_Ad, '') NOT IN ($ph)
                ", $kodListe)['c'] ?? 0;

                echo json_encode(['success' => true, 'data' => [
                    'satirlar'     => $satirlar,
                    'pasif_olacak' => (int)$pasifOlacak,
                ]]);
                break;

            // Toplu kampanya kodu aktifleştirme — uygula
            case 'toplu_aktif_uygula':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok!']);
                    break;
                }

                $gelen      = json_decode($_POST['kodlar'] ?? '[]', true);
                $digerPasif = ($_POST['diger_pasif'] ?? '0') === '1';
                if (!is_array($gelen)) $gelen = [];

                $temiz = [];
                foreach ($gelen as $g) {
                    $k = trim((string)($g['kod'] ?? ''));
                    if ($k === '' || isset($temiz[$k])) continue;
                    $hediye = trim((string)($g['hediye'] ?? ''));
                    $temiz[$k] = [
                        'koi'      => !empty($g['koi'])      ? 1 : 0,
                        'superkoi' => !empty($g['superkoi']) ? 1 : 0,
                        'hediye'   => $hediye !== '' ? $hediye : null,
                    ];
                }
                if (!$temiz) {
                    echo json_encode(['success' => false, 'message' => 'İşlenecek kampanya kodu yok!']);
                    break;
                }
                if (count($temiz) > 2000) {
                    echo json_encode(['success' => false, 'message' => 'En fazla 2000 kampanya kodu işlenebilir!']);
                    break;
                }

                $kodListe = array_keys($temiz);
                $ph       = implode(',', array_fill(0, count($kodListe), '?'));
                $simdi    = date('Y-m-d H:i:s');
                $uid      = $user['kullanici_id'];

                $pasifAdet = 0;
                if ($digerPasif) {
                    $pasifAdet = (int)($db->fetchOne("
                        SELECT COUNT(*) as c FROM APIKampanyalar
                        WHERE Durum = 1 AND ISNULL(APIKampanyalar_Ad, '') NOT IN ($ph)
                    ", $kodListe)['c'] ?? 0);

                    $db->execute("
                        UPDATE APIKampanyalar
                        SET Durum = 0, GuncelleyenKullanici = ?, GuncellemeTarihi = ?
                        WHERE Durum = 1 AND ISNULL(APIKampanyalar_Ad, '') NOT IN ($ph)
                    ", array_merge([$uid, $simdi], $kodListe));
                }

                $aktifAdet = (int)($db->fetchOne("
                    SELECT COUNT(*) as c FROM APIKampanyalar
                    WHERE APIKampanyalar_Ad IN ($ph) AND Durum = 0
                ", $kodListe)['c'] ?? 0);

                // KÖİ / SÜPER KÖİ kombinasyonuna göre grupla — en fazla 4 grup, her grup tek UPDATE
                $gruplar = [];
                foreach ($temiz as $kod => $b) {
                    $anahtar = $b['koi'] . '|' . $b['superkoi'] . '|' . ($b['hediye'] ?? '');
                    $gruplar[$anahtar]['bayrak']   = $b;
                    $gruplar[$anahtar]['kodlar'][] = $kod;
                }

                $koiAdet    = 0;
                $hediyeAdet = 0;
                foreach ($gruplar as $grup) {
                    $koi        = $grup['bayrak']['koi'];
                    $superkoi   = $grup['bayrak']['superkoi'];
                    $hediye     = $grup['bayrak']['hediye'];
                    $kodlarGrup = $grup['kodlar'];
                    $gph        = implode(',', array_fill(0, count($kodlarGrup), '?'));

                    $koiAdet += (int)($db->fetchOne("
                        SELECT COUNT(*) as c FROM APIKampanyalar
                        WHERE APIKampanyalar_Ad IN ($gph)
                          AND (ISNULL(APIKampanyalar_KOI, 0) <> ? OR ISNULL(APIKampanyalar_SUPERKOI, 0) <> ?)
                    ", array_merge($kodlarGrup, [$koi, $superkoi]))['c'] ?? 0);

                    $hediyeAdet += (int)($db->fetchOne("
                        SELECT COUNT(*) as c FROM APIKampanyalar
                        WHERE APIKampanyalar_Ad IN ($gph)
                          AND ISNULL(APIKampanyalar_Hediye, '') <> ?
                    ", array_merge($kodlarGrup, [$hediye ?? '']))['c'] ?? 0);

                    $db->execute("
                        UPDATE APIKampanyalar
                        SET Durum                   = 1,
                            APIKampanyalar_KOI      = ?,
                            APIKampanyalar_SUPERKOI = ?,
                            APIKampanyalar_Hediye   = ?,
                            GuncelleyenKullanici    = ?,
                            GuncellemeTarihi        = ?
                        WHERE APIKampanyalar_Ad IN ($gph)
                    ", array_merge([$koi, $superkoi, $hediye, $uid, $simdi], $kodlarGrup));
                }

                $mesaj  = "$aktifAdet kayıt aktif edildi";
                $mesaj .= ", $koiAdet kaydın KÖİ/SÜPER KÖİ bilgisi";
                $mesaj .= ", $hediyeAdet kaydın hediye içeriği güncellendi";
                $mesaj .= $digerPasif ? ", $pasifAdet kayıt pasife alındı." : '.';

                echo json_encode([
                    'success'    => true,
                    'message'    => $mesaj,
                    'aktif_adet' => $aktifAdet,
                    'koi_adet'    => $koiAdet,
                    'hediye_adet' => $hediyeAdet,
                    'pasif_adet' => $pasifAdet,
                ]);

                break;

            case 'stats':
                $toplam  = $db->fetchOne("SELECT COUNT(*) as c FROM APIKampanyalar")['c'] ?? 0;
                $aktif   = $db->fetchOne("SELECT COUNT(*) as c FROM APIKampanyalar WHERE Durum = 1")['c'] ?? 0;
                $sonSync = $db->fetchOne("SELECT CONVERT(VARCHAR(16), MAX(GuncellemeTarihi), 120) as s FROM APIKampanyalar")['s'] ?? null;
                echo json_encode(['success' => true, 'data' => [
                    'toplam'   => $toplam,
                    'aktif'    => $aktif,
                    'son_sync' => $sonSync ?? '-',
                ]]);
                break;

            case 'sync_from_api':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Yetkiniz yok!']);
                    break;
                }

                $personel = $db->fetchOne("
                    SELECT DigiturkAltBayiPersonel_Token
                    FROM DigiturkAltBayiPersonel
                    WHERE DigiturkAltBayiPersonel_Id = 1
                ");

                if (!$personel || empty($personel['DigiturkAltBayiPersonel_Token'])) {
                    echo json_encode(['success' => false, 'message' => 'Aktif token bulunamadı! Lütfen token yenileyin.']);
                    break;
                }

                $token     = $personel['DigiturkAltBayiPersonel_Token'];
                $endpoints = $db->fetchAll("
                    SELECT APIEndpointler_Id, APIEndpointler_Endpoint, APIEndpointler_Aciklama
                    FROM APIEndpointler
                    WHERE APIEndpointler_Id IN (11, 12) AND Durum = 1
                    ORDER BY APIEndpointler_Id
                ");

                $totalInserted = 0;
                $totalUpdated  = 0;
                $errors        = [];

                foreach ($endpoints as $ep) {
                    $ch = curl_init($ep['APIEndpointler_Endpoint']);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => '{}',
                        CURLOPT_HTTPHEADER     => [
                            'Content-Type: application/json',
                            'Token: ' . $token,
                        ],
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_TIMEOUT        => 30,
                    ]);

                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlErr  = curl_error($ch);
                    unset($ch);

                    if ($curlErr || $response === false) {
                        $errors[] = "{$ep['APIEndpointler_Aciklama']}: Bağlantı hatası ($curlErr)";
                        continue;
                    }
                    if ($httpCode !== 200) {
                        $errors[] = "{$ep['APIEndpointler_Aciklama']}: HTTP $httpCode";
                        continue;
                    }

                    $data = json_decode($response, true);
                    if (!$data || ($data['responseCode'] ?? -1) !== 0) {
                        $errors[] = "{$ep['APIEndpointler_Aciklama']}: API hata kodu " . ($data['responseCode'] ?? '?');
                        continue;
                    }

                    $turMap = [11 => 1, 12 => 2];
                    $tur    = $turMap[$ep['APIEndpointler_Id']] ?? null;
                    processEntity($db, $data['data'], $user['kullanici_id'], $tur, $totalInserted, $totalUpdated);
                }

                // ISP kampanyaları (endpoint 25/26) — NEO/UYDU'dan ayrı akış.
                // Bu uçlar boş gövde kabul etmez; her referans BBK için ayrı çağrı yapılır.
                $ispEndpoints = $db->fetchAll("
                    SELECT APIEndpointler_Id, APIEndpointler_Endpoint, APIEndpointler_Aciklama
                    FROM APIEndpointler
                    WHERE APIEndpointler_Id IN (25, 26) AND Durum = 1
                    ORDER BY APIEndpointler_Id
                ");

                foreach ($ispEndpoints as $ep) {
                    $epId        = (int)$ep['APIEndpointler_Id'];
                    $kategoriAdi = ISP_KATEGORI_MAP[$epId] ?? null;
                    if (!$kategoriAdi) continue;

                    foreach (ISP_REFERANS_BBK as $bbk) {
                        $ch = curl_init($ep['APIEndpointler_Endpoint']);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => json_encode(['bbkId' => (int)$bbk, 'adslNo' => null]),
                            CURLOPT_HTTPHEADER     => [
                                'Content-Type: application/json',
                                'Token: ' . $token,
                            ],
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_TIMEOUT        => 60,
                        ]);

                        $response = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $curlErr  = curl_error($ch);
                        unset($ch);

                        if ($curlErr || $response === false) {
                            $errors[] = "{$ep['APIEndpointler_Aciklama']} (BBK $bbk): Bağlantı hatası ($curlErr)";
                            continue;
                        }
                        if ($httpCode !== 200) {
                            $errors[] = "{$ep['APIEndpointler_Aciklama']} (BBK $bbk): HTTP $httpCode";
                            continue;
                        }

                        $data = json_decode($response, true);
                        if (!$data || ($data['responseCode'] ?? -1) !== 0) {
                            // responseCode 2 = "altyapı mevcut değildir" — hata değil, o adreste ISP yok
                            if (($data['responseCode'] ?? -1) !== 2) {
                                $errors[] = "{$ep['APIEndpointler_Aciklama']} (BBK $bbk): API hata kodu "
                                          . ($data['responseCode'] ?? '?');
                            }
                            continue;
                        }

                        foreach (($data['data']['offerResultList'] ?? []) as $offer) {
                            upsertIspKampanya($db, $offer, $kategoriAdi, $user['kullanici_id'],
                                              $totalInserted, $totalUpdated);
                        }
                    }
                }

                $message = "$totalInserted kayıt eklendi, $totalUpdated kayıt güncellendi.";
                if ($errors) {
                    $message .= ' | Hatalar: ' . implode(' | ', $errors);
                }

                echo json_encode([
                    'success'  => empty($errors) || ($totalInserted + $totalUpdated > 0),
                    'message'  => $message,
                    'inserted' => $totalInserted,
                    'updated'  => $totalUpdated,
                ]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// Dropdown verileri
$kampanyaTurleri = $db->fetchAll("SELECT APIKampanyaTurleri_Id, APIKampanyaTurleri_Tur_Adi, APIKampanyaTurleri_Renk, APIKampanyaTurleri_Simge FROM APIKampanyaTurleri WHERE Durum = 1 ORDER BY APIKampanyaTurleri_Tur_Adi");
$odemeYontemleri = $db->fetchAll("SELECT APIOdemeYontemleri_Id, APIOdemeYontemleri_Ad FROM APIOdemeYontemleri WHERE Durum = 1 ORDER BY APIOdemeYontemleri_Ad");
$kampanyaKodlari = $db->fetchAll("SELECT DISTINCT APIKampanyalar_Ad FROM APIKampanyalar WHERE APIKampanyalar_Ad IS NOT NULL ORDER BY APIKampanyalar_Ad");
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
        .status-badge    { padding: .25rem .5rem; border-radius: .25rem; font-size: .875rem; }
        .status-active   { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .offer-code      { font-family: monospace; font-weight: 600; font-size: .85rem; background: #e9ecef; padding: .2rem .45rem; border-radius: .3rem; }
        .tur-badge       { display: inline-flex; align-items: center; gap: .35rem; padding: .25rem .6rem; border-radius: 1rem; font-weight: 600; font-size: .8rem; color: #fff; }
        .fiyat-text      { font-weight: 600; font-size: .9rem; }
        .fiyat-zero      { color: #6c757d; }
        .donemi-badge    { font-size: .78rem; font-weight: 600; padding: .2rem .5rem; border-radius: .3rem; }
        .donemi-ay       { background: #cff4fc; color: #0a4d5c; }
        .donemi-yil      { background: #d1e7ff; color: #0a3d6b; }
        .flag-koi        { font-size: .75rem; font-weight: 700; padding: .15rem .4rem; border-radius: .25rem; background: #fff3cd; color: #664d03; margin-left: .2rem; }
        .flag-superkoi   { font-size: .75rem; font-weight: 700; padding: .15rem .4rem; border-radius: .25rem; background: #f8d7da; color: #842029; margin-left: .2rem; }
        .modal-readonly  { background: #f8f9fa; pointer-events: none; opacity: .85; }
        .sync-btn-loading .spinner-border { display: inline-block !important; }
        .sync-btn-loading .bi-arrow-repeat { display: none !important; }
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
                            <span class="info-box-icon"><i class="bi bi-collection"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Kampanya</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif</span>
                                <span class="info-box-number" id="stat-aktif">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-arrow-repeat"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Son Senkronizasyon</span>
                                <span class="info-box-number fs-6" id="stat-son-sync">-</span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php include __DIR__ . '/../includes/api-personel-bilgisi.php'; ?>

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
                                <div class="col-md-3">
                                    <label class="form-label">Ara</label>
                                    <input type="text" class="form-control" name="search" id="filter_search" placeholder="Kod, açıklama...">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Kampanya Kod</label>
                                    <select class="form-select select2" name="kod" id="filter_kod">
                                        <option value="">Tümü</option>
                                        <?php foreach ($kampanyaKodlari as $k): ?>
                                        <option value="<?= htmlspecialchars($k['APIKampanyalar_Ad']) ?>">
                                            <?= htmlspecialchars($k['APIKampanyalar_Ad']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Açıklama</label>
                                    <input type="text" class="form-control" name="aciklama" id="filter_aciklama" placeholder="Açıklama ara...">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Paket Adı</label>
                                    <input type="text" class="form-control" name="paket_adi" id="filter_paket_adi" placeholder="Paket adı ara...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Fatura Dönemi</label>
                                    <select class="form-select select2" name="fatura_donemi" id="filter_fatura_donemi">
                                        <option value="">Tümü</option>
                                        <option value="AY">Aylık</option>
                                        <option value="YIL">Yıllık</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Tür</label>
                                    <select class="form-select select2" name="tur" id="filter_tur">
                                        <option value="">Tümü</option>
                                        <?php foreach ($kampanyaTurleri as $t): ?>
                                        <option value="<?= $t['APIKampanyaTurleri_Id'] ?>">
                                            <?= htmlspecialchars($t['APIKampanyaTurleri_Tur_Adi']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Ödeme Türü</label>
                                    <select class="form-select select2" name="odeme_turu" id="filter_odeme_turu">
                                        <option value="">Tümü</option>
                                        <?php foreach ($odemeYontemleri as $o): ?>
                                        <option value="<?= $o['APIOdemeYontemleri_Id'] ?>">
                                            <?= htmlspecialchars($o['APIOdemeYontemleri_Ad']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">KOI / SUPER KOI</label>
                                    <select class="form-select select2" name="koi_durum" id="filter_koi_durum">
                                        <option value="">Tümü</option>
                                        <option value="koi">KOI</option>
                                        <option value="superkoi">SUPER KOI</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Durum</label>
                                    <select class="form-select select2" name="status" id="filter_status">
                                        <option value="">Tümü</option>
                                        <option value="1">Aktif</option>
                                        <option value="0">Pasif</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Oluşturma Tarihi (Başlangıç)</label>
                                    <input type="date" class="form-control" name="olusturma_bas" id="filter_olusturma_bas">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Oluşturma Tarihi (Bitiş)</label>
                                    <input type="date" class="form-control" name="olusturma_bit" id="filter_olusturma_bit">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Güncelleme Tarihi (Başlangıç)</label>
                                    <input type="date" class="form-control" name="guncelleme_bas" id="filter_guncelleme_bas">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Güncelleme Tarihi (Bitiş)</label>
                                    <input type="date" class="form-control" name="guncelleme_bit" id="filter_guncelleme_bit">
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
                        <h3 class="card-title"><i class="bi bi-list-ul"></i> Kampanya Listesi</h3>
                        <div class="card-tools">
                            <?php if ($permissions['can_edit']): ?>
                            <button type="button" class="btn btn-warning btn-sm d-none" id="topluGuncelleBtn">
                                <i class="bi bi-pencil-square"></i> Seçilenleri Güncelle (<span id="secimSayisi">0</span>)
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" id="topluBtn"><i class="bi bi-file-earmark-excel"></i> Toplu Aktifleştir</button>
                            <button type="button" class="btn btn-success btn-sm" id="syncBtn" onclick="syncFromApi()">
                                <i class="bi bi-arrow-repeat"></i>
                                <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                API'den Güncelle
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <?php if ($permissions['can_edit']): ?>
                                    <th style="width:42px" class="text-center">
                                        <div class="form-check form-switch d-flex justify-content-center">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="secTumu" title="Filtrelenmiş tüm satırları seç">
                                        </div>
                                    </th>
                                    <?php endif; ?>
                                    <th style="width:70px">Kampanya Kod</th>
                                    <th>Paket Adı</th>
                                    <th>Hediye</th>
                                    <th>Offer Kodları</th>
                                    <th>Fiyat</th>
                                    <th>Dönem</th>
                                    <th>Tür</th>
                                    <th>Ödeme Türü</th>
                                    <th>KOI / SUPER KOI</th>
                                    <th>Durum</th>
                                    <th style="width:130px">Oluşturma Tarihi</th>
                                    <th style="width:90px">İşlemler</th>
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

<!-- Düzenle Modal -->
<div class="modal fade" id="kayitModal" tabindex="-1" aria-labelledby="kayitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kayitModalLabel">Kampanya Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kayitForm">
                <div class="modal-body">
                    <input type="hidden" id="rec_id" name="id">

                    <!-- API'den gelen bilgiler (salt okunur) -->
                    <div class="card card-outline card-secondary mb-3">
                        <div class="card-header py-2">
                            <h6 class="card-title mb-0 text-muted"><i class="bi bi-cloud-download"></i> API'den Gelen Bilgiler</h6>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <div class="col-md-12">
                                    <label class="form-label small text-muted mb-1">Kategori</label>
                                    <input type="text" class="form-control form-control-sm modal-readonly" id="view_kategori" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-1">Kampanya Kod</label>
                                    <input type="text" class="form-control form-control-sm modal-readonly" id="view_ad" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-1">Açıklama</label>
                                    <input type="text" class="form-control form-control-sm modal-readonly" id="view_aciklama" readonly>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small text-muted mb-1">Offer From</label>
                                    <input type="text" class="form-control form-control-sm font-monospace modal-readonly" id="view_from" readonly>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small text-muted mb-1">Offer To</label>
                                    <input type="text" class="form-control form-control-sm font-monospace modal-readonly" id="view_to" readonly>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small text-muted mb-1">Fiyat</label>
                                    <input type="text" class="form-control form-control-sm modal-readonly" id="view_fiyat" readonly>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small text-muted mb-1">Dönem</label>
                                    <input type="text" class="form-control form-control-sm modal-readonly" id="view_donem" readonly>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Manuel alanlar -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Kampanya Türü</label>
                            <select class="form-select select2-modal" name="tur" id="tur">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($kampanyaTurleri as $t): ?>
                                <option value="<?= $t['APIKampanyaTurleri_Id'] ?>"
                                    data-renk="<?= htmlspecialchars($t['APIKampanyaTurleri_Renk'] ?? '#007bff') ?>"
                                    data-simge="<?= htmlspecialchars($t['APIKampanyaTurleri_Simge'] ?? 'bi-tag') ?>">
                                    <?= htmlspecialchars($t['APIKampanyaTurleri_Tur_Adi']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ödeme Türü</label>
                            <select class="form-select select2-modal" name="odeme_turu" id="odeme_turu">
                                <option value="">-- Seçiniz --</option>
                                <?php foreach ($odemeYontemleri as $o): ?>
                                <option value="<?= $o['APIOdemeYontemleri_Id'] ?>">
                                    <?= htmlspecialchars($o['APIOdemeYontemleri_Ad']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Paket Adı</label>
                            <input type="text" class="form-control" id="paket_adi" name="paket_adi" placeholder="Dijital paket adı...">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Hediye</label>
                            <textarea class="form-control" id="hediye" name="hediye" rows="2" placeholder="Hediye içeriği..."></textarea>
                        </div>
                        <div class="col-md-12">
                            <div class="d-flex gap-4 flex-wrap">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="koi" name="koi">
                                    <label class="form-check-label fw-semibold" for="koi">KOI</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="superkoi" name="superkoi">
                                    <label class="form-check-label fw-semibold" for="superkoi">SUPER KOI</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="durum" name="durum" checked>
                                    <label class="form-check-label" for="durum">Aktif</label>
                                </div>
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

<!-- Toplu Kampanya Kodu Aktifleştirme Modal -->
<?php if ($permissions['can_edit']): ?>
<div class="modal fade" id="topluModal" tabindex="-1" aria-labelledby="topluModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="topluModalLabel"><i class="bi bi-file-earmark-excel"></i> Toplu Kampanya Kodu Aktifleştirme</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Excel Dosyası <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="topluDosya" accept=".xlsx,.xls,.csv">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-success w-100" id="topluOrnekBtn">
                            <i class="bi bi-download"></i> Örnek Excel
                        </button>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="button" class="btn btn-primary w-100" id="topluOnizleBtn">
                            <i class="bi bi-eye"></i> Önizle
                        </button>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="topluDigerPasif" value="1" checked>
                            <label class="form-check-label" for="topluDigerPasif">
                                Listede olmayan tüm kampanyaları <strong>pasife al</strong>
                            </label>
                        </div>
                    </div>
                    <div class="col-12">
                        <small class="text-muted">
                            <strong>A sütunu:</strong> KAMPANYA KODU &nbsp;&middot;&nbsp;
                            <strong>B sütunu:</strong> KÖİ / SÜPER KÖİ &nbsp;&middot;&nbsp;
                            <strong>C sütunu:</strong> HEDİYE İÇERİK.
                            Excel'deki kodlarla eşleşen kampanyalar <strong>aktif</strong> edilir; anahtar kolon
                            <code>APIKampanyalar_Ad</code> alanıdır. Aynı kod birden fazla kayıtta geçiyorsa hepsi aktif olur.
                            B sütununa <code>KÖİ</code> veya <code>SÜPER KÖİ</code> yazılır (ikisi birden için
                            <code>KÖİ, SÜPER KÖİ</code>). B ve C sütunlarında <strong>birleştirilmiş hücreler</strong>
                            desteklenir; değer, birleştirmenin kapsadığı tüm kampanya kodlarına uygulanır.
                            C sütununa yalnız paket adı yazılır; kaydedilirken sonuna otomatik olarak
                            <code>Paketi Hediye!</code> eklenir (<em>Sporun Yıldızı</em> &rarr;
                            <em>Sporun Yıldızı Paketi Hediye!</em>).
                            <strong>Boş bırakılan</strong> satırlarda ilgili alan <strong>temizlenir</strong>.
                            En fazla <strong>2000</strong> kod işlenebilir.
                        </small>
                    </div>
                </div>

                <div class="alert alert-warning py-2 mb-3" id="topluKoiUyari" style="display:none">
                    <i class="bi bi-exclamation-triangle"></i>
                    Excel'de <strong>KÖİ / SÜPER KÖİ</strong> sütunu (B) bulunamadı.
                    Devam ederseniz seçili kampanyaların her iki bayrağı da <strong>kaldırılacak</strong>.
                </div>

                <div class="alert alert-warning py-2 mb-3" id="topluHediyeUyari" style="display:none">
                    <i class="bi bi-exclamation-triangle"></i>
                    Excel'de <strong>HEDİYE İÇERİK</strong> sütunu (C) bulunamadı.
                    Devam ederseniz seçili kampanyaların hediye içeriği <strong>temizlenecek</strong>.
                </div>

                <div id="topluOnizlemeAlan" style="display:none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong>Önizleme</strong>
                        <span class="text-muted" id="topluOzet"></span>
                    </div>
                    <div class="table-responsive" style="max-height:420px;overflow-y:auto">
                        <table class="table table-sm table-bordered align-middle" id="topluOnizlemeTable">
                            <thead>
                                <tr>
                                    <th style="width:34px"><input type="checkbox" id="topluTumu" checked></th>
                                    <th>#</th>
                                    <th>Kampanya Kodu</th>
                                    <th class="text-center">Eşleşen Kayıt</th>
                                    <th class="text-center">Şu An Aktif</th>
                                    <th class="text-center">KÖİ</th>
                                    <th class="text-center">SÜPER KÖİ</th>
                                    <th style="min-width:180px">Hediye İçerik</th>
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
                <button type="button" class="btn btn-primary" id="topluUygulaBtn" style="display:none">
                    <i class="bi bi-check2-circle"></i> Seçilenleri Aktif Et
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Seçilenleri Toplu Güncelle -->
<div class="modal fade" id="topluGuncelleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Seçilenleri Toplu Güncelle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="topluGuncelleForm">
                <div class="modal-body">
                    <div class="alert alert-info py-2">
                        <i class="bi bi-info-circle"></i>
                        <strong><span id="tgSecimSayisi">0</span></strong> kayıt güncellenecek.
                        Yalnızca <em>açtığın</em> alanlar değiştirilir, kapalı alanlara dokunulmaz.
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input tg-anahtar" type="checkbox" role="switch"
                                       id="tg_durum_ac" data-hedef="tg_durum">
                                <label class="form-check-label fw-semibold" for="tg_durum_ac">Aktif / Pasif</label>
                            </div>
                            <div class="form-check form-switch ms-3 mt-1">
                                <input class="form-check-input" type="checkbox" role="switch" id="tg_durum" disabled>
                                <label class="form-check-label" for="tg_durum">Aktif yap</label>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input tg-anahtar" type="checkbox" role="switch"
                                       id="tg_koi_ac" data-hedef="tg_koi">
                                <label class="form-check-label fw-semibold" for="tg_koi_ac">KOI</label>
                            </div>
                            <div class="form-check form-switch ms-3 mt-1">
                                <input class="form-check-input" type="checkbox" role="switch" id="tg_koi" disabled>
                                <label class="form-check-label" for="tg_koi">KOI işaretle</label>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input tg-anahtar" type="checkbox" role="switch"
                                       id="tg_superkoi_ac" data-hedef="tg_superkoi">
                                <label class="form-check-label fw-semibold" for="tg_superkoi_ac">SUPER KOI</label>
                            </div>
                            <div class="form-check form-switch ms-3 mt-1">
                                <input class="form-check-input" type="checkbox" role="switch" id="tg_superkoi" disabled>
                                <label class="form-check-label" for="tg_superkoi">SUPER KOI işaretle</label>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input tg-anahtar" type="checkbox" role="switch"
                                       id="tg_hediye_ac" data-hedef="tg_hediye">
                                <label class="form-check-label fw-semibold" for="tg_hediye_ac">Hediye</label>
                            </div>
                            <input type="text" class="form-control mt-1" id="tg_hediye" maxlength="500"
                                   placeholder="Boş bırakılırsa hediye silinir" disabled>
                        </div>

                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input tg-anahtar" type="checkbox" role="switch"
                                       id="tg_paket_adi_ac" data-hedef="tg_paket_adi">
                                <label class="form-check-label fw-semibold" for="tg_paket_adi_ac">Paket Adı</label>
                            </div>
                            <input type="text" class="form-control mt-1" id="tg_paket_adi" maxlength="200"
                                   placeholder="Boş bırakılırsa paket adı silinir" disabled>
                        </div>

                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input tg-anahtar" type="checkbox" role="switch"
                                       id="tg_tur_ac" data-hedef="tg_tur">
                                <label class="form-check-label fw-semibold" for="tg_tur_ac">Kampanya Türü</label>
                            </div>
                            <select class="form-select mt-1" id="tg_tur" disabled>
                                <option value="">-- Temizle --</option>
                                <?php foreach ($kampanyaTurleri as $t): ?>
                                <option value="<?= $t['APIKampanyaTurleri_Id'] ?>"><?= htmlspecialchars($t['APIKampanyaTurleri_Tur_Adi']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input tg-anahtar" type="checkbox" role="switch"
                                       id="tg_odeme_turu_ac" data-hedef="tg_odeme_turu">
                                <label class="form-check-label fw-semibold" for="tg_odeme_turu_ac">Ödeme Türü</label>
                            </div>
                            <select class="form-select mt-1" id="tg_odeme_turu" disabled>
                                <option value="">-- Temizle --</option>
                                <?php foreach ($odemeYontemleri as $o): ?>
                                <option value="<?= $o['APIOdemeYontemleri_Id'] ?>"><?= htmlspecialchars($o['APIOdemeYontemleri_Ad']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check2-circle"></i> Güncelle
                    </button>
                </div>
            </form>
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
        canEdit:   <?= $permissions['can_edit']   ? 'true' : 'false' ?>,
        canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>
    };

    let kayitModal, dataTable, currentFilters = {};
    let topluModal, topluSatirlar = [], topluPasifOlacak = 0, topluKoiKolonVar = false, topluHediyeKolonVar = false;

    // Toplu güncellemede seçili kayıt id'leri. DataTables sayfalamada satırları DOM'dan
    // çıkardığı için seçim checkbox'ta değil burada tutulur.
    let topluGuncelleModal;
    const seciliIdler = new Set();

    // Seçim kolonu yalnız düzenleme yetkisi varken basılır; diğer kolon indeksleri ona göre kayar
    const secKolonVar = permissions.canEdit ? 1 : 0;

    $(document).ready(function () {
        kayitModal = new bootstrap.Modal(document.getElementById('kayitModal'));

        dataTable = $('#kayitTable').DataTable({
            language : { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order    : [[secKolonVar, 'asc'], [secKolonVar + 1, 'asc']],
            columnDefs: [{ orderable: false, targets: secKolonVar ? [0, 12] : [11] }],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Tümü']]
        });

        loadStats();
        loadList();

        // custom.js tüm .form-select'leri zaten initialize etti
        // Modal select'leri dropdownParent ile yeniden başlat
        $('#tur, #odeme_turu').select2('destroy');
        initSelect2InModal('#tur, #odeme_turu', '#kayitModal');

        if (permissions.canEdit) {
            topluGuncelleModal = new bootstrap.Modal(document.getElementById('topluGuncelleModal'));
            $('#tg_tur, #tg_odeme_turu').select2('destroy');
            initSelect2InModal('#tg_tur, #tg_odeme_turu', '#topluGuncelleModal');

            // Satır seçimi
            $('#kayitTable tbody').on('change', '.satir-sec', function () {
                const id = parseInt(this.value, 10);
                this.checked ? seciliIdler.add(id) : seciliIdler.delete(id);
                secimGuncelle();
            });

            // Başlıktaki anahtar: filtrelenmiş TÜM satırları seçer (yalnız görünen sayfayı değil)
            $('#secTumu').on('change', function () {
                const sec = this.checked;
                dataTable.rows({ search: 'applied' }).nodes().to$()
                    .find('.satir-sec').each(function () {
                        const id = parseInt(this.value, 10);
                        this.checked = sec;
                        sec ? seciliIdler.add(id) : seciliIdler.delete(id);
                    });
                secimGuncelle();
            });

            $('#topluGuncelleBtn').on('click', function () {
                $('#tgSecimSayisi').text(seciliIdler.size);
                topluGuncelleModal.show();
            });

            // Alan anahtarı açık değilse ilgili giriş kilitli kalır
            $('.tg-anahtar').on('change', function () {
                const hedef = $('#' + $(this).data('hedef'));
                hedef.prop('disabled', !this.checked);
                if (hedef.hasClass('select2-hidden-accessible')) hedef.trigger('change.select2');
            });

            $('#topluGuncelleForm').on('submit', function (e) {
                e.preventDefault();
                topluGuncelleGonder();
            });
        }

        $('#kayitForm').on('submit', function (e) {
            e.preventDefault();
            saveRecord();
        });

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            currentFilters = {
                search       : $('#filter_search').val(),
                aciklama     : $('#filter_aciklama').val(),
                kod          : $('#filter_kod').val(),
                paket_adi    : $('#filter_paket_adi').val(),
                fatura_donemi: $('#filter_fatura_donemi').val(),
                tur          : $('#filter_tur').val(),
                odeme_turu   : $('#filter_odeme_turu').val(),
                koi_durum    : $('#filter_koi_durum').val(),
                status       : $('#filter_status').val(),
                olusturma_bas : $('#filter_olusturma_bas').val(),
                olusturma_bit : $('#filter_olusturma_bit').val(),
                guncelleme_bas: $('#filter_guncelleme_bas').val(),
                guncelleme_bit: $('#filter_guncelleme_bit').val()
            };
            Object.keys(currentFilters).forEach(k => { if (currentFilters[k] === '') delete currentFilters[k]; });
            loadList();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filterForm')[0].reset();
            $('.select2').val('').trigger('change');
            currentFilters = {};
            loadList();
            showToast('Filtreler temizlendi', 'info');
        });

        // ===== Toplu Aktifleştirme modalı =====
        if (document.getElementById('topluModal')) {
            topluModal = new bootstrap.Modal(document.getElementById('topluModal'));

            $('#topluBtn').on('click', function () {
                $('#topluDosya').val('');
                $('#topluOnizlemeAlan').hide();
                $('#topluUygulaBtn').hide();
                $('#topluOnizlemeTable tbody').empty();
                topluSatirlar    = [];
                topluPasifOlacak = 0;
                topluKoiKolonVar    = false;
                topluHediyeKolonVar = false;
                $('#topluKoiUyari, #topluHediyeUyari').hide();
                topluModal.show();
            });

            $('#topluOrnekBtn').on('click', topluOrnekIndir);
            $('#topluOnizleBtn').on('click', topluOnizle);
            $('#topluUygulaBtn').on('click', topluUygula);
            $('#topluDigerPasif').on('change', topluOzetGuncelle);

            $('#topluTumu').on('change', function () {
                $('.toplu-sec:not(:disabled)').prop('checked', this.checked);
                topluOzetGuncelle();
            });
            $(document).on('change', '.toplu-sec', topluOzetGuncelle);
        }

    });

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-aktif').text(r.data.aktif);
            $('#stat-son-sync').text(r.data.son_sync);
        });
    }

    function loadList() {
        $.ajax({
            url    : '', method: 'POST',
            data   : { action: 'list', ...currentFilters },
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
            // Kampanya adı
            const adCell    = escapeHtml(row.APIKampanyalar_Ad || '-');

            // Offer kodları
            const fromCode = escapeHtml(row.APIKampanyalar_OfferFromCode || '-');
            const toCode   = escapeHtml(row.APIKampanyalar_OfferToCode   || '-');
            const offerCell = `<span class="offer-code">${fromCode}</span> <i class="bi bi-arrow-right text-muted"></i> <span class="offer-code">${toCode}</span>`;

            // Fiyat (gizli sıfır dolgulu değer sıralama için başta durur;
            // aksi halde "1.629 TL" metni harf harf sıralanıp 699 > 1.099 çıkar)
            let fiyatCell = '-';
            if (row.APIKampanyalar_Fiyat !== null && row.APIKampanyalar_Fiyat !== undefined) {
                const fiyat  = parseFloat(row.APIKampanyalar_Fiyat);
                const pb     = escapeHtml(row.APIKampanyalar_ParaBirimi || 'TL');
                const siraNo = fiyat.toFixed(2).padStart(12, '0');
                const govde  = fiyat === 0
                    ? `<span class="fiyat-text fiyat-zero">Ücretsiz</span>`
                    : `<span class="fiyat-text">${fiyat.toLocaleString('tr-TR')} ${pb}</span>`;
                fiyatCell = `<span class="d-none">${siraNo}</span>${govde}`;
            }

            // Dönem
            const donem = row.APIKampanyalar_FaturaDonemi;
            let donemCell = '-';
            if (donem === 'AY')  donemCell = `<span class="donemi-badge donemi-ay">Aylık</span>`;
            if (donem === 'YIL') donemCell = `<span class="donemi-badge donemi-yil">Yıllık</span>`;

            // Tür badge
            let turCell = '<span class="text-muted small">-</span>';
            if (row.APIKampanyaTurleri_Tur_Adi) {
                const renk  = escapeHtml(row.APIKampanyaTurleri_Renk  || '#007bff');
                const simge = escapeHtml(row.APIKampanyaTurleri_Simge || 'bi-tag');
                const turAd = escapeHtml(row.APIKampanyaTurleri_Tur_Adi);
                turCell = `<span class="tur-badge" style="background-color:${renk}"><i class="bi ${simge}"></i> ${turAd}</span>`;
            }

            // Ödeme Türü
            const odemeTuruCell = row.APIOdemeYontemleri_Ad
                ? `<span class="donemi-badge donemi-ay">${escapeHtml(row.APIOdemeYontemleri_Ad)}</span>`
                : '<span class="text-muted small">-</span>';

            // KOI / SUPER KOI
            let koiCell = '';
            if (row.APIKampanyalar_KOI == 1)      koiCell += `<span class="flag-koi">KOI</span>`;
            if (row.APIKampanyalar_SUPERKOI == 1) koiCell += `<span class="flag-superkoi">SUPER KOI</span>`;
            if (!koiCell) koiCell = '<span class="text-muted small">-</span>';

            // Paket Adı / Hediye
            const paketAdiCell = escapeHtml(row.APIKampanyalar_PaketAdi || '-');
            const hediyeCell   = row.APIKampanyalar_Hediye
                ? escapeHtml(row.APIKampanyalar_Hediye)
                : '<span class="text-muted small">-</span>';

            // Satır seçimi (toplu güncelleme için)
            const satirId = Number(row.APIKampanyalar_Id);
            const secCell = `<div class="form-check form-switch d-flex justify-content-center">
                <input class="form-check-input satir-sec" type="checkbox" role="switch"
                       value="${satirId}" ${seciliIdler.has(satirId) ? 'checked' : ''}></div>`;

            // Durum
            const durum = row.Durum == 1
                ? `<span class="status-badge status-active" style="cursor:pointer" onclick="toggleDurum(${row.APIKampanyalar_Id}, 0)" title="Pasife al">Aktif</span>`
                : `<span class="status-badge status-inactive" style="cursor:pointer" onclick="toggleDurum(${row.APIKampanyalar_Id}, 1)" title="Aktife al">Pasif</span>`;

            // İşlemler
            let islemler = '';
            if (permissions.canEdit)
                islemler += `<button class="btn btn-sm btn-warning me-1" onclick="editRecord(${row.APIKampanyalar_Id})" title="Düzenle"><i class="bi bi-pencil"></i></button>`;
            if (permissions.canDelete)
                islemler += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${row.APIKampanyalar_Id})" title="Sil"><i class="bi bi-trash"></i></button>`;
            if (!islemler) islemler = '<span class="text-muted">-</span>';

            // Tarihler (gizli ISO değeri sıralama için başta durur)
            const olusturmaCell = tarihHucre(row.OlusturmaTarihi);

            const satir = [adCell, paketAdiCell, hediyeCell, offerCell, fiyatCell, donemCell,
                           turCell, odemeTuruCell, koiCell, durum, olusturmaCell, islemler];
            if (permissions.canEdit) satir.unshift(secCell);
            dataTable.row.add(satir);
        });
        dataTable.draw();

        // Liste yenilendi: artık var olmayan id'ler seçimde kalmasın
        if (permissions.canEdit) {
            const mevcut = new Set(rows.map(r => Number(r.APIKampanyalar_Id)));
            [...seciliIdler].forEach(id => { if (!mevcut.has(id)) seciliIdler.delete(id); });
            secimGuncelle();
        }
    }

    function editRecord(id) {
        $.ajax({
            url    : '', method: 'POST',
            data   : { action: 'get', id: id },
            dataType: 'json',
            success: function (r) {
                if (!r.success || !r.data) { showToast('Kayıt bulunamadı', 'error'); return; }
                const d = r.data;

                document.getElementById('rec_id').value    = d.APIKampanyalar_Id;
                document.getElementById('view_kategori').value = d.APIKampanyalar_KategoriAdi || '';
                document.getElementById('view_ad').value       = d.APIKampanyalar_Ad          || '';
                document.getElementById('view_aciklama').value = d.APIKampanyalar_Aciklama    || '';
                document.getElementById('view_from').value     = (d.APIKampanyalar_OfferFromCode || '') + (d.APIKampanyalar_OfferFromId ? ' (' + d.APIKampanyalar_OfferFromId + ')' : '');
                document.getElementById('view_to').value       = (d.APIKampanyalar_OfferToCode   || '') + (d.APIKampanyalar_OfferToId   ? ' (' + d.APIKampanyalar_OfferToId   + ')' : '');
                document.getElementById('view_fiyat').value    = (d.APIKampanyalar_Fiyat !== null ? d.APIKampanyalar_Fiyat + ' ' + (d.APIKampanyalar_ParaBirimi || 'TL') : '');
                document.getElementById('view_donem').value    = d.APIKampanyalar_FaturaDonemi === 'AY' ? 'Aylık' : (d.APIKampanyalar_FaturaDonemi === 'YIL' ? 'Yıllık' : '');
                document.getElementById('paket_adi').value     = d.APIKampanyalar_PaketAdi    || '';
                document.getElementById('hediye').value        = d.APIKampanyalar_Hediye       || '';
                document.getElementById('koi').checked         = d.APIKampanyalar_KOI      == 1;
                document.getElementById('superkoi').checked    = d.APIKampanyalar_SUPERKOI == 1;
                document.getElementById('durum').checked       = d.Durum == 1;

                $('#tur').val(d.APIKampanyalar_Tur       || '').trigger('change');
                $('#odeme_turu').val(d.APIKampanyalar_OdemeTuru || '').trigger('change');

                kayitModal.show();
            },
            error: function () { showToast('Kayıt yüklenirken hata oluştu', 'error'); }
        });
    }

    function saveRecord() {
        const formData = new FormData(document.getElementById('kayitForm'));
        formData.append('action', 'save');

        $.ajax({
            url : '', method: 'POST',
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

    function toggleDurum(id, yeniDurum) {
        if (!permissions.canEdit) return;
        $.ajax({
            url    : '', method: 'POST',
            data   : { action: 'toggle_durum', id: id, durum: yeniDurum },
            dataType: 'json',
            success: function (r) {
                if (r.success) {
                    showToast(yeniDurum == 1 ? 'Aktif edildi' : 'Pasif edildi', 'success');
                    loadList();
                    loadStats();
                } else {
                    showToast('Durum değiştirilemedi', 'error');
                }
            }
        });
    }

    function deleteRecord(id) {
        confirmAction('Bu kampanyayı silmek istediğinize emin misiniz?', 'Bu işlem geri alınamaz!', function () {
            $.ajax({
                url    : '', method: 'POST',
                data   : { action: 'delete', id: id },
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
        });
    }

    function syncFromApi() {
        const btn = document.getElementById('syncBtn');
        btn.disabled = true;
        btn.classList.add('sync-btn-loading');

        $.ajax({
            url    : '', method: 'POST',
            data   : { action: 'sync_from_api' },
            dataType: 'json',
            timeout : 60000,
            success: function (r) {
                if (r.success) {
                    showSuccess('Senkronizasyon Tamamlandı', r.message);
                    loadList();
                    loadStats();
                } else {
                    showError('Senkronizasyon Hatası', r.message);
                }
            },
            error: function () { showError('Bağlantı Hatası!', 'API\'ye ulaşılamıyor.'); },
            complete: function () {
                btn.disabled = false;
                btn.classList.remove('sync-btn-loading');
            }
        });
    }

    // ===== Toplu Kampanya Kodu Aktifleştirme =====

    // Başlık eşleştirmesi için: Türkçe karakterleri sadeleştirip harf/rakam dışını atar
    function topluNorm(s) {
        return String(s || '')
            .toLocaleUpperCase('tr-TR')
            .replace(/Ç/g, 'C').replace(/Ğ/g, 'G').replace(/İ/g, 'I')
            .replace(/Ö/g, 'O').replace(/Ş/g, 'S').replace(/Ü/g, 'U')
            .replace(/[^A-Z0-9]/g, '');
    }

    // Birleştirilmiş hücreleri aç: Excel'de merge edilen aralıkta yalnız sol-üst hücrede
    // değer bulunur, diğerleri boş gelir. Aralıktaki tüm hücrelere aynı değeri yazar.
    function topluMergeYay(ws, rows) {
        const merges = ws['!merges'] || [];
        merges.forEach(function (m) {
            const deger = (rows[m.s.r] || [])[m.s.c];
            if (deger === undefined || String(deger).trim() === '') return;
            for (let r = m.s.r; r <= m.e.r; r++) {
                if (!rows[r]) rows[r] = [];
                for (let c = m.s.c; c <= m.e.c; c++) {
                    if (rows[r][c] === undefined || String(rows[r][c]).trim() === '') rows[r][c] = deger;
                }
            }
        });
        return rows;
    }

    // Hediye içeriğinin sonuna standart eki koyar: "Sporun Yıldızı" → "Sporun Yıldızı Paketi Hediye!"
    // Zaten ekle biten değerler olduğu gibi bırakılır, boş hücreye ek yazılmaz.
    const TOPLU_HEDIYE_SONEK = ' Paketi Hediye!';
    function topluHediyeSonek(v) {
        const t = String(v || '').trim().replace(/\s+/g, ' ');
        if (!t) return '';
        return topluNorm(t).endsWith(topluNorm(TOPLU_HEDIYE_SONEK)) ? t : t + TOPLU_HEDIYE_SONEK;
    }

    // B sütunundaki tip metnini bayraklara çevirir:
    // "KÖİ" → koi, "SÜPER KÖİ" → superkoi, "KÖİ, SÜPER KÖİ" → ikisi, boş → hiçbiri
    function topluTipParse(v) {
        const t = topluNorm(v);
        if (!t) return { koi: 0, superkoi: 0 };
        const superkoi = t.indexOf('SUPERKOI') !== -1 ? 1 : 0;
        // SÜPER KÖİ ifadesini çıkardıktan sonra hâlâ KÖİ kalıyorsa normal KÖİ de işaretlidir
        const kalan = t.split('SUPERKOI').join('');
        const koi = kalan.indexOf('KOI') !== -1 ? 1 : 0;
        return { koi: koi, superkoi: superkoi };
    }

    function topluOrnekIndir() {
        const veri = [
            ['KAMPANYA KODU', 'KÖİ / SÜPER KÖİ', 'HEDİYE İÇERİK'],
            ['KMP0001', 'KÖİ', 'Sporun Yıldızı'],
            ['KMP0002', 'KÖİ', 'Sporun Yıldızı'],
            ['KMP0003', 'SÜPER KÖİ', 'Yıldız Dolu'],
            ['KMP0004', '', '']
        ];
        const ws = XLSX.utils.aoa_to_sheet(veri);
        ws['!cols'] = [{ wch: 24 }, { wch: 20 }, { wch: 28 }];
        // İlk iki satırın B ve C hücrelerini birleştir — desteklenen kullanım örneği
        ws['!merges'] = [
            { s: { r: 1, c: 1 }, e: { r: 2, c: 1 } },
            { s: { r: 1, c: 2 }, e: { r: 2, c: 2 } }
        ];
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Kampanya Kodlari');
        XLSX.writeFile(wb, 'ornek-kampanya-kodlari.xlsx');
    }

    function topluOnizle() {
        const file = document.getElementById('topluDosya').files[0];
        if (!file) { showToast('Lütfen bir Excel dosyası seçiniz', 'warning'); return; }

        const reader = new FileReader();
        reader.onload = function (e) {
            try {
                const wb   = XLSX.read(e.target.result, { type: 'array' });
                const ws   = wb.Sheets[wb.SheetNames[0]];
                // blankrows: true — merge aralığındaki satır numaraları kaymasın diye boş satırlar korunur
                const rows = topluMergeYay(ws, XLSX.utils.sheet_to_json(ws, { header: 1, blankrows: true, defval: '', raw: false, range: 0 }));

                // Başlık satırını bul (ilk 10 satırda KOD içeren hücre); yoksa ilk sütun kod kabul edilir
                let hIdx = -1, kodCol = 0, tipCol = -1, hediyeCol = -1;
                for (let i = 0; i < Math.min(rows.length, 10); i++) {
                    const n  = (rows[i] || []).map(topluNorm);
                    const ci = n.findIndex(x => x.indexOf('KOD') !== -1);
                    if (ci === -1) continue;
                    hIdx   = i;
                    kodCol = ci;
                    // Tip sütunu: başlığında KÖİ geçen ilk sütun (varsayılan B)
                    tipCol = n.findIndex((x, j) => j !== ci && x.indexOf('KOI') !== -1);
                    if (tipCol === -1 && ci === 0 && n.length > 1) tipCol = 1;
                    // Hediye sütunu: başlığında HEDİYE geçen sütun (varsayılan C)
                    hediyeCol = n.findIndex((x, j) => j !== ci && j !== tipCol && x.indexOf('HEDIYE') !== -1);
                    if (hediyeCol === -1 && ci === 0 && n.length > 2) hediyeCol = 2;
                    break;
                }

                const kodlar = [], gorulen = {};
                for (let r = (hIdx === -1 ? 0 : hIdx + 1); r < rows.length; r++) {
                    const satir = rows[r] || [];
                    const k = String(satir[kodCol] || '').trim();
                    if (!k || gorulen[k]) continue;
                    gorulen[k] = true;
                    const tip    = tipCol    !== -1 ? topluTipParse(satir[tipCol]) : { koi: 0, superkoi: 0 };
                    const hediye = hediyeCol !== -1 ? topluHediyeSonek(satir[hediyeCol]) : '';
                    kodlar.push({ kod: k, koi: tip.koi, superkoi: tip.superkoi, hediye: hediye });
                }

                if (!kodlar.length) {
                    showError('Veri yok!', 'Excel içinde kampanya kodu bulunamadı. "KAMPANYA KODU" başlıklı bir sütun olmalı.');
                    return;
                }
                if (kodlar.length > 2000) {
                    showError('Limit aşıldı!', 'En fazla 2000 kampanya kodu işlenebilir.');
                    return;
                }

                topluKoiKolonVar    = (tipCol    !== -1);
                topluHediyeKolonVar = (hediyeCol !== -1);

                $.post('', { action: 'toplu_aktif_onizle', kodlar: JSON.stringify(kodlar) }, function (r) {
                    if (!r.success) { showError('Hata!', r.message); return; }
                    topluSatirlar    = r.data.satirlar;
                    topluPasifOlacak = r.data.pasif_olacak;
                    renderTopluOnizleme();
                }, 'json').fail(function () { showError('Hata!', 'Sunucuya ulaşılamıyor.'); });
            } catch (err) {
                showError('Okuma hatası!', 'Excel okunamadı: ' + err.message);
            }
        };
        reader.readAsArrayBuffer(file);
    }

    // Excel değeri ile mevcut DB değerini karşılaştıran rozet
    function topluBayrakHucre(yeni, mevcut) {
        const etiket = yeni ? '<span class="badge text-bg-primary">Evet</span>'
                            : '<span class="badge text-bg-light text-muted border">Hayır</span>';
        if (mevcut === null || mevcut === undefined) {
            return etiket + ' <i class="bi bi-arrow-left-right text-warning" title="Kayıtlar arasında farklı — hepsi bu değere çekilecek"></i>';
        }
        if (Number(mevcut) !== Number(yeni)) {
            return etiket + ' <i class="bi bi-arrow-left-right text-warning" title="Mevcut: ' + (Number(mevcut) ? 'Evet' : 'Hayır') + ' — değişecek"></i>';
        }
        return etiket;
    }

    // Hediye içeriğini mevcut DB değeriyle karşılaştıran hücre
    function topluHediyeHucre(s) {
        const yeni    = String(s.hediye || '');
        const etiket  = yeni ? escapeHtml(yeni) : '<span class="text-muted fst-italic">boş</span>';
        if (!s.adet) return etiket;
        if (s.hediye_karisik) {
            return etiket + ' <i class="bi bi-arrow-left-right text-warning" title="Kayıtlar arasında farklı — hepsi bu değere çekilecek"></i>';
        }
        const mevcut = String(s.mevcut_hediye || '');
        if (mevcut !== yeni) {
            return etiket + ' <i class="bi bi-arrow-left-right text-warning" title="Mevcut: ' + escapeHtml(mevcut || 'boş') + ' — değişecek"></i>';
        }
        return etiket;
    }

    function renderTopluOnizleme() {
        const tb = $('#topluOnizlemeTable tbody').empty();

        topluSatirlar.forEach(function (s, i) {
            const bulundu = s.adet > 0;

            let durum;
            if (!bulundu)               durum = '<span class="badge text-bg-danger">Kod bulunamadı</span>';
            else if (s.aktif >= s.adet) durum = '<span class="badge text-bg-secondary">Zaten aktif</span>';
            else                        durum = '<span class="badge text-bg-success">Aktif edilecek</span>';

            tb.append(
                '<tr class="' + (bulundu ? '' : 'table-light text-muted') + '">' +
                '<td><input type="checkbox" class="toplu-sec" data-i="' + i + '"' + (bulundu ? ' checked' : ' disabled') + '></td>' +
                '<td>' + (i + 1) + '</td>' +
                '<td><strong>' + escapeHtml(s.kod) + '</strong></td>' +
                '<td class="text-center">' + s.adet + '</td>' +
                '<td class="text-center">' + s.aktif + '</td>' +
                '<td class="text-center">' + topluBayrakHucre(s.koi, s.mevcut_koi) + '</td>' +
                '<td class="text-center">' + topluBayrakHucre(s.superkoi, s.mevcut_superkoi) + '</td>' +
                '<td>' + topluHediyeHucre(s) + '</td>' +
                '<td>' + durum + '</td>' +
                '</tr>'
            );
        });

        $('#topluKoiUyari').toggle(!topluKoiKolonVar);
        $('#topluHediyeUyari').toggle(!topluHediyeKolonVar);
        $('#topluTumu').prop('checked', true);
        $('#topluOnizlemeAlan').show();
        $('#topluUygulaBtn').show();
        topluOzetGuncelle();
    }

    function topluOzetGuncelle() {
        let kod = 0, kayit = 0, koi = 0, superkoi = 0, hediye = 0;
        $('.toplu-sec:checked').each(function () {
            const s = topluSatirlar[$(this).data('i')];
            kod++;
            kayit    += s.adet;
            koi      += s.koi      ? 1 : 0;
            superkoi += s.superkoi ? 1 : 0;
            hediye   += s.hediye   ? 1 : 0;
        });
        const bulunamayan = topluSatirlar.filter(s => s.adet === 0).length;

        let html = 'Seçili: <strong>' + kod + '</strong> kod &mdash; <strong>' + kayit + '</strong> kampanya kaydı';
        html += ' &mdash; KÖİ: <strong>' + koi + '</strong> / SÜPER KÖİ: <strong>' + superkoi + '</strong>';
        html += ' &mdash; Hediyeli: <strong>' + hediye + '</strong>';
        if (bulunamayan) html += ' &mdash; <span class="text-danger">Bulunamayan: ' + bulunamayan + '</span>';
        if ($('#topluDigerPasif').is(':checked')) {
            html += ' &mdash; <span class="text-warning">Pasife alınacak (tahmini): ' + topluPasifOlacak + '</span>';
        }
        $('#topluOzet').html(html);
    }

    function topluUygula() {
        const kodlar = [];
        $('.toplu-sec:checked').each(function () {
            const s = topluSatirlar[$(this).data('i')];
            kodlar.push({ kod: s.kod, koi: s.koi, superkoi: s.superkoi, hediye: s.hediye || '' });
        });
        if (!kodlar.length) { showToast('İşlenecek kod seçilmedi', 'warning'); return; }

        const digerPasif = $('#topluDigerPasif').is(':checked');
        let uyari = 'Seçili kodlar aktif edilecek; KÖİ / SÜPER KÖİ ve hediye içeriği Excel’deki değerlerle güncellenecek.';
        if (digerPasif) uyari += ' Listede olmayan TÜM kampanyalar pasife alınacak. Bu işlem geri alınamaz!';

        confirmAction(kodlar.length + ' kampanya kodu işlenecek. Onaylıyor musunuz?', uyari, function () {
            $.post('', {
                action     : 'toplu_aktif_uygula',
                kodlar     : JSON.stringify(kodlar),
                diger_pasif: digerPasif ? '1' : '0'
            }, function (r) {
                if (r.success) {
                    showSuccess('Tamamlandı!', r.message);
                    bootstrap.Modal.getInstance(document.getElementById('topluModal')).hide();
                    loadList();
                    loadStats();
                } else {
                    showError('Hata!', r.message);
                }
            }, 'json').fail(function () { showError('Hata!', 'Sunucuya ulaşılamıyor.'); });
        });
    }

    // Seçim sayacı ve "Seçilenleri Güncelle" butonunun görünürlüğü
    function secimGuncelle() {
        $('#secimSayisi').text(seciliIdler.size);
        $('#topluGuncelleBtn').toggleClass('d-none', seciliIdler.size === 0);

        // Filtrelenmiş satırların tamamı seçiliyse başlık anahtarı da işaretli görünsün
        const gorunen = dataTable.rows({ search: 'applied' }).nodes().to$().find('.satir-sec');
        const secili  = gorunen.filter(':checked').length;
        $('#secTumu').prop('checked', gorunen.length > 0 && secili === gorunen.length);
    }

    function topluGuncelleGonder() {
        if (!seciliIdler.size) return;

        // Yalnız anahtarı açık alanlar gönderilir; kapalı alanlar sunucuda hiç ellenmez
        const alanlar = {};
        if ($('#tg_durum_ac').is(':checked'))      alanlar.durum      = $('#tg_durum').is(':checked')      ? 1 : 0;
        if ($('#tg_koi_ac').is(':checked'))        alanlar.koi        = $('#tg_koi').is(':checked')        ? 1 : 0;
        if ($('#tg_superkoi_ac').is(':checked'))   alanlar.superkoi   = $('#tg_superkoi').is(':checked')   ? 1 : 0;
        if ($('#tg_hediye_ac').is(':checked'))     alanlar.hediye     = $('#tg_hediye').val();
        if ($('#tg_paket_adi_ac').is(':checked'))  alanlar.paket_adi  = $('#tg_paket_adi').val();
        if ($('#tg_tur_ac').is(':checked'))        alanlar.tur        = $('#tg_tur').val();
        if ($('#tg_odeme_turu_ac').is(':checked')) alanlar.odeme_turu = $('#tg_odeme_turu').val();

        if (!Object.keys(alanlar).length) {
            Swal.fire('Uyarı', 'Güncellenecek en az bir alan seçmelisiniz!', 'warning');
            return;
        }

        Swal.fire({
            title: 'Emin misiniz?',
            html : `<strong>${seciliIdler.size}</strong> kayıtta <strong>${Object.keys(alanlar).length}</strong> alan güncellenecek.`,
            icon : 'question',
            showCancelButton : true,
            confirmButtonText: 'Evet, güncelle',
            cancelButtonText : 'Vazgeç'
        }).then(sonuc => {
            if (!sonuc.isConfirmed) return;

            $.post('', {
                action : 'toplu_guncelle',
                idler  : JSON.stringify([...seciliIdler]),
                alanlar: JSON.stringify(alanlar)
            }, null, 'json')
            .done(r => {
                if (r.success) {
                    topluGuncelleModal.hide();
                    seciliIdler.clear();
                    $('#topluGuncelleForm')[0].reset();
                    $('.tg-anahtar').trigger('change');
                    Swal.fire('Başarılı', r.message, 'success');
                    loadStats();
                    loadList();
                } else {
                    Swal.fire('Hata', r.message || 'Güncelleme başarısız', 'error');
                }
            })
            .fail(() => Swal.fire('Hata', 'Sunucuya ulaşılamadı!', 'error'));
        });
    }

    function tarihHucre(deger) {
        if (!deger) return '<span class="text-muted small">-</span>';
        const iso = String(deger);                       // 2026-08-07 14:32:10
        const p   = iso.split(' ');
        const d   = (p[0] || '').split('-');
        const s   = (p[1] || '').substring(0, 5);
        if (d.length !== 3) return escapeHtml(iso);
        return `<span class="d-none">${escapeHtml(iso)}</span><span class="small">${d[2]}.${d[1]}.${d[0]}${s ? ' ' + s : ''}</span>`;
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
</script>
</body>
</html>
