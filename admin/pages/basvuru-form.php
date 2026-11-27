<?php
/**
 * Admin Panel - Başvuru Ekleme/Düzenleme Formu
 * Tablo: Basvurular  |  Yetki: parent sayfa basvuru-yonetimi.php üzerinden
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/BasvuruLogHelper.php';
require_once __DIR__ . '/../includes/AdresYanitHelper.php';
require_once __DIR__ . '/../includes/KaraListeHelper.php';
requireAuth();

$user = Auth::user();
$db   = Database::getInstance();

// Yetki — ana sayfanın yetkilerini kullan (yeni Menu_Sayfalar kaydı gerekmez)
$parentPagefile  = 'basvuru-yonetimi.php';
$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $parentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$editMode = isset($_GET['id']) && (int)$_GET['id'] > 0;
$id       = $editMode ? (int)$_GET['id'] : 0;

if ($editMode && !$permissions['can_edit']) PageAuth::accessDenied('Düzenleme yetkiniz yok!');
if (!$editMode && !$permissions['can_add']) PageAuth::accessDenied('Ekleme yetkiniz yok!');

// =====================================================================
// BİRİM BAZLI YETKİ KISITI (basvuru-yonetimi.php ile aynı mantık)
// =====================================================================
$birimKisitli   = (!$permissions['is_admin'] && !empty($permissions['can_view_birim']));
$izinliBirimler = [];

// KENDİ KAYITLARINI GÖR kısıtı: admin değil + kendi_kullanicini_gor → yalnız kendi oluşturduğu veya kendisine atanan
$kendiKisitli = (!$permissions['is_admin'] && !empty($permissions['can_view_own_records']));
$kullaniciId  = (int)$user['kullanici_id'];

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
}

// =====================================================================
// ALAN BAZLI YETKİ (Menu_SayfaAlanlari + Menu_SayfaAlanYetkileri)
// Departman–alan kaydı yoksa alan görünür + düzenlenir. Admin her zaman tüm alanlar.
// Başvuru durumu AlanKilitle=1 (TAMAM vb.) olan kayıtta yalnız KilitliDuzenle=1 alanlar düzenlenir.
// =====================================================================
$alanSayfaId  = (int)($db->fetchOne("SELECT TOP 1 sayfalar_id FROM Menu_Sayfalar WHERE sayfalar_sayfa_url LIKE ? AND sayfalar_durum = 1", ['%' . $parentPagefile])['sayfalar_id'] ?? 0);
$alanAdmin    = !empty($permissions['is_admin']);
$formKayitId  = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (int)($_POST['id'] ?? 0) : (int)($_GET['id'] ?? 0);
$alanYetki    = $alanAdmin ? [] : alanYetkileriGetir($db, $alanSayfaId, (int)($user['departman_id'] ?? 0));
$kayitKilitli = basvuruKayitKilitliMi($db, $formKayitId);

$alanGor = function (string $kod) use ($alanAdmin, $alanYetki, $formKayitId): bool {
    if ($alanAdmin || !isset($alanYetki[$kod])) return true;
    if ($kod === 'AltBayiPersonel_ID' && $formKayitId === 0) return true;   // yeni kayıtta zorunlu alan
    return (int)$alanYetki[$kod]['gor'] === 1;
};
$alanDuzenle = function (string $kod) use ($alanAdmin, $alanYetki, $formKayitId, $kayitKilitli): bool {
    if ($alanAdmin || !isset($alanYetki[$kod])) return true;
    if ($kod === 'AltBayiPersonel_ID' && $formKayitId === 0) return true;
    $y = $alanYetki[$kod];
    return (int)$y['gor'] === 1 && (int)$y['duzenle'] === 1
        && (!$kayitKilitli || (int)$y['kilitli_duzenle'] === 1);
};
// Form öğesi için disabled + etiket kilit ikonu
$alanRo  = fn(string $kod): string => $alanDuzenle($kod) ? '' : 'disabled';
$alanIco = function (string $kod) use ($alanDuzenle, $alanYetki, $kayitKilitli): string {
    if ($alanDuzenle($kod)) return '';
    $tamamKilidi = $kayitKilitli && isset($alanYetki[$kod]) && (int)$alanYetki[$kod]['duzenle'] === 1;
    $baslik = $tamamKilidi ? 'Başvuru tamamlandığı için değiştirilemez' : 'Bu departman değiştiremez';
    return ' <i class="bi bi-lock-fill text-muted small" title="' . $baslik . '"></i>';
};

/** Departmanın sayfa alan yetkileri: [kod => [id, kod, etiket, bolum, gor, duzenle, kilitli_duzenle]] */
function alanYetkileriGetir($db, int $sayfaId, int $departmanId): array {
    $rows = $db->fetchAll("
        SELECT a.Menu_SayfaAlanlari_id AS id, a.Menu_SayfaAlanlari_Kod AS kod,
               a.Menu_SayfaAlanlari_Etiket AS etiket, a.Menu_SayfaAlanlari_Bolum AS bolum,
               CAST(ISNULL(y.Menu_SayfaAlanYetkileri_Gor, 1) AS INT)            AS gor,
               CAST(ISNULL(y.Menu_SayfaAlanYetkileri_Duzenle, 1) AS INT)        AS duzenle,
               CAST(ISNULL(y.Menu_SayfaAlanYetkileri_KilitliDuzenle, 1) AS INT) AS kilitli_duzenle
        FROM Menu_SayfaAlanlari a
        LEFT JOIN Menu_SayfaAlanYetkileri y
               ON y.Menu_SayfaAlanYetkileri_Alan_id = a.Menu_SayfaAlanlari_id
              AND y.Menu_SayfaAlanYetkileri_Departman_id = ? AND y.Durum = 1
        WHERE a.Menu_SayfaAlanlari_Sayfa_id = ? AND a.Durum = 1
        ORDER BY a.Menu_SayfaAlanlari_Sira
    ", [$departmanId, $sayfaId]);
    $out = [];
    foreach ($rows as $r) $out[$r['kod']] = $r;
    return $out;
}

/** Başvurunun başvuru durumu alanları kilitliyor mu (BasvuruDurum_AlanKilitle = 1 → TAMAM vb.) */
function basvuruKayitKilitliMi($db, int $basvuruId): bool {
    if ($basvuruId <= 0) return false;
    return (bool)$db->fetchOne("
        SELECT TOP 1 1 AS v FROM Basvurular b
        INNER JOIN BasvuruDurum d ON d.BasvuruDurum_id = b.BasvuruDurum_ID
        WHERE b.Basvurular_id = ? AND d.BasvuruDurum_AlanKilitle = 1
    ", [$basvuruId]);
}

function basvuruBirimKisitWhere(array $izinliBirimler, string $leadIdExpr, string $perIdExpr, string $altBayiIdExpr, string $leadFormIdExpr, string $olusturanExpr = ''): array {
    $ph = implode(',', array_fill(0, count($izinliBirimler), '?'));
    $tarih = "kby.Durum = 1
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())";
    $sql = "(
        EXISTS (
            SELECT 1 FROM KullaniciBirimYetkileri kby
            WHERE kby.KullaniciBirimYetkileri_ApiLead_id = $leadIdExpr
              AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
              AND $tarih
        )
        OR EXISTS (
            SELECT 1 FROM KullaniciBirimYetkileri kby
            WHERE kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
              AND (kby.KullaniciBirimYetkileri_Personel_id = $perIdExpr
                OR kby.KullaniciBirimYetkileri_AltBayi_id  = $altBayiIdExpr)
              AND $tarih
        )
        OR EXISTS (
            SELECT 1 FROM ReklamLeadFormlari f
            INNER JOIN KullaniciBirimYetkileri kby
                    ON kby.KullaniciBirimYetkileri_ReklamSayfa_id = f.ReklamLeadFormlari_Sayfa_id
            WHERE f.ReklamLeadFormlari_id = $leadFormIdExpr
              AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
              AND $tarih
        )";
    $params = array_merge($izinliBirimler, $izinliBirimler, $izinliBirimler);
    if ($olusturanExpr !== '') {
        // Bağ yoksa (Personel/ApiLead/ReklamForm hepsi NULL) → oluşturan kullanıcının birimine düşer (OTP toplu kayıtları)
        $sql .= "
        OR ($perIdExpr IS NULL AND $leadIdExpr IS NULL AND $leadFormIdExpr IS NULL
            AND EXISTS (SELECT 1 FROM kullanicilar kuc
                        WHERE kuc.kullanici_id = $olusturanExpr AND kuc.kullanici_birim_id IN ($ph)))";
        $params = array_merge($params, $izinliBirimler);
    }
    $sql .= ")";
    return [$sql, $params];
}

function basvuruKayitBirimYetkiliMi($db, array $izinliBirimler, int $basvuruId): bool {
    if (empty($izinliBirimler)) return false;
    [$w, $p] = basvuruBirimKisitWhere(
        $izinliBirimler,
        't.CallCenterApiLead_ID',
        't.AltBayiPersonel_ID',
        '(SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID)',
        't.ReklamLeadFormlari_ID',
        't.OlusturanKullanici'
    );
    $row = $db->fetchOne("SELECT TOP 1 1 AS v FROM Basvurular t WHERE t.Basvurular_id = ? AND $w", array_merge([$basvuruId], $p));
    return (bool)$row;
}

/** Tekil başvurunun, kullanıcının kendi oluşturduğu VEYA kendisine atanan kayıt olup olmadığı (kendi_gor kısıtı) */
function basvuruKayitKendiMi($db, int $kullaniciId, int $basvuruId): bool {
    $row = $db->fetchOne("SELECT TOP 1 1 AS v FROM Basvurular WHERE Basvurular_id = ? AND (OlusturanKullanici = ? OR Basvurular_AtananKullanici_ID = ?)", [$basvuruId, $kullaniciId, $kullaniciId]);
    return (bool)$row;
}

/**
 * Kaydetme sırasında: kısıtlı kullanıcının form'dan seçtiği değer (personel/lead/form)
 * gerçekten izinli birim(ler)e ait mi? Boş seçim (0/null) serbesttir.
 */
function secimBirimYetkiliMi($db, array $izinliBirimler, string $tip, int $deger): bool {
    if ($deger <= 0) return true;            // boş seçim engellenmez
    if (empty($izinliBirimler)) return false;
    $ph = implode(',', array_fill(0, count($izinliBirimler), '?'));
    $tarih = "kby.Durum = 1
        AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
        AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())";
    switch ($tip) {
        case 'personel': // doğrudan personel VEYA bağlı alt bayi yetkisi (silsile)
            $sql = "SELECT TOP 1 1 FROM DigiturkAltBayiPersonel p
                    WHERE p.DigiturkAltBayiPersonel_Id = ? AND EXISTS (
                        SELECT 1 FROM KullaniciBirimYetkileri kby
                        WHERE kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
                          AND (kby.KullaniciBirimYetkileri_Personel_id = p.DigiturkAltBayiPersonel_Id
                            OR kby.KullaniciBirimYetkileri_AltBayi_id  = p.DigiturkAltBayiPersonel_AltBayiId)
                          AND $tarih)";
            break;
        case 'lead':
            $sql = "SELECT TOP 1 1 FROM CallCenterApiLead cl
                    WHERE cl.CallCenterApiLead_id = ? AND EXISTS (
                        SELECT 1 FROM KullaniciBirimYetkileri kby
                        WHERE kby.KullaniciBirimYetkileri_ApiLead_id = cl.CallCenterApiLead_id
                          AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
                          AND $tarih)";
            break;
        case 'form': // lead formu → Facebook sayfası → birim
            $sql = "SELECT TOP 1 1 FROM ReklamLeadFormlari f
                    WHERE f.ReklamLeadFormlari_id = ? AND EXISTS (
                        SELECT 1 FROM KullaniciBirimYetkileri kby
                        WHERE kby.KullaniciBirimYetkileri_ReklamSayfa_id = f.ReklamLeadFormlari_Sayfa_id
                          AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
                          AND $tarih)";
            break;
        default: return false;
    }
    return (bool)$db->fetchOne($sql, array_merge([$deger], $izinliBirimler));
}

// BBK adres modalı token kaynağı — STATİK personel (yalnızca bu modal için kullanılır)
const BBK_ADRES_PERSONEL_ID = 1;

// BBK adres API çağrısı (Digiturk IRIS) — token: DigiturkAltBayiPersonel id=BBK_ADRES_PERSONEL_ID
function addressApiCall($db, int $endpointId, $code): array {
    $ep = $db->fetchOne("SELECT APIEndpointler_Endpoint FROM APIEndpointler WHERE APIEndpointler_Id = ? AND Durum = 1", [$endpointId]);
    if (!$ep) return ['ok' => false, 'msg' => 'Endpoint bulunamadı (Id=' . $endpointId . ')'];
    $per = $db->fetchOne("SELECT DigiturkAltBayiPersonel_Token FROM DigiturkAltBayiPersonel WHERE DigiturkAltBayiPersonel_Id = ?", [BBK_ADRES_PERSONEL_ID]);
    if (!$per || empty($per['DigiturkAltBayiPersonel_Token'])) return ['ok' => false, 'msg' => 'Aktif token yok! Token yenileyin.'];

    $ch = curl_init($ep['APIEndpointler_Endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['code' => (int)$code]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Token: ' . $per['DigiturkAltBayiPersonel_Token']],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['ok' => false, 'msg' => 'Bağlantı hatası: ' . $err];
    $j = json_decode($resp, true);
    if (!$j || ($j['responseCode'] ?? -1) !== 0) {
        return ['ok' => false, 'msg' => $j['responseMessage'] ?? ('HTTP ' . $http)];
    }
    return ['ok' => true, 'data' => $j['data'] ?? null, 'url' => $ep['APIEndpointler_Endpoint']];
}

// Endpoint 10 tam adres yanıtından şehri APISehirler ile eşleştirir. Önce kod (APISehirler_Kod), sonra ad.
function bbkSehirEslestir($db, $adr): array {
    if (!is_array($adr)) return [null, null];
    // 1) Şehir KODU ile (GetAllCities 'code' = APISehirler_Kod)
    foreach (['cityCode', 'CityCode', 'cityId', 'CityId', 'ilKodu', 'ilKod', 'city_code', 'sehirKodu', 'sehirKod'] as $k) {
        if (isset($adr[$k]) && $adr[$k] !== '' && is_numeric($adr[$k])) {
            $s = $db->fetchOne("SELECT APISehirler_Id, APISehirler_Ad FROM APISehirler WHERE APISehirler_Kod = ? AND Durum = 1", [(int)$adr[$k]]);
            if ($s) return [(int)$s['APISehirler_Id'], $s['APISehirler_Ad']];
        }
    }
    // 2) Şehir ADI ile (normalize: TR büyük harf)
    foreach (['city', 'City', 'cityName', 'CityName', 'il', 'Il', 'ilAd', 'ilAdi', 'sehir', 'sehirAdi'] as $k) {
        if (isset($adr[$k]) && is_string($adr[$k]) && trim($adr[$k]) !== '') {
            $ad = mb_strtoupper(trim($adr[$k]), 'UTF-8');
            $s = $db->fetchOne("SELECT APISehirler_Id, APISehirler_Ad FROM APISehirler WHERE UPPER(APISehirler_Ad) = ? AND Durum = 1", [$ad]);
            if ($s) return [(int)$s['APISehirler_Id'], $s['APISehirler_Ad']];
        }
    }

    // Tüm metni topla (alanlar İLÇE/ŞEHİR formatında olabilir: "...ALTINDAĞ/ANKARA")
    $metin = '';
    array_walk_recursive($adr, function ($v) use (&$metin) { if (is_scalar($v)) $metin .= ' ' . $v; });
    $metinUpper = mb_strtoupper($metin, 'UTF-8');

    // 3) "İLÇE/ŞEHİR" formatı — her string alanda son "/" sonrası parça
    foreach ($adr as $v) {
        if (is_string($v) && mb_strpos($v, '/') !== false) {
            $parca = trim(mb_substr($v, mb_strrpos($v, '/') + 1, null, 'UTF-8'));
            if ($parca !== '') {
                $ad = mb_strtoupper($parca, 'UTF-8');
                $s = $db->fetchOne("SELECT APISehirler_Id, APISehirler_Ad FROM APISehirler WHERE UPPER(APISehirler_Ad) = ? AND Durum = 1", [$ad]);
                if ($s) return [(int)$s['APISehirler_Id'], $s['APISehirler_Ad']];
            }
        }
    }

    // 4) Son çare: tüm şehir adlarını metinde kelime sınırıyla ara
    $sehirler = $db->fetchAll("SELECT APISehirler_Id, APISehirler_Ad FROM APISehirler WHERE Durum = 1");
    foreach ($sehirler as $s) {
        $ad = mb_strtoupper(trim($s['APISehirler_Ad']), 'UTF-8');
        if ($ad === '') continue;
        if (preg_match('/(^|[^0-9A-ZÇĞİÖŞÜ])' . preg_quote($ad, '/') . '($|[^0-9A-ZÇĞİÖŞÜ])/u', $metinUpper)) {
            return [(int)$s['APISehirler_Id'], $s['APISehirler_Ad']];
        }
    }
    return [null, null];
}

// Kampanya listesi: APIKampanyalar_Tur=1 HER ZAMAN listelenir (şehirden bağımsız);
// şehir seçiliyse şehrin KOI/SuperKOI durumuna göre ek kampanyalar eklenir:
//  - Şehir KOI=1            → + APIKampanyalar_KOI=1
//  - Şehir SuperKOI=1       → + APIKampanyalar_SUPERKOI=1
//  - Şehir KOI=1 & SuperKOI=1→ + (KOI=1 VEYA SUPERKOI=1)
//  - Şehir normal (0/0)     → + kalan (KOI=0 ve SUPERKOI=0)
// $ensureKampanyaId: düzenlemede kayıtlı kampanya filtreye uymasa da listede kalsın diye.
function kampanyaListesi($db, $sehirId, $ensureKampanyaId = 0): array {
    $sehirId = (int)$sehirId;
    $kosul = "k.APIKampanyalar_Tur = 1"; // her zaman
    if ($sehirId > 0) {
        $s = $db->fetchOne("SELECT APISehirler_KOI, APISehirler_SuperKOI FROM APISehirler WHERE APISehirler_Id = ?", [$sehirId]);
        $koi   = (int)($s['APISehirler_KOI'] ?? 0);
        $super = (int)($s['APISehirler_SuperKOI'] ?? 0);
        if ($koi && $super) {
            $kosul .= " OR k.APIKampanyalar_KOI = 1 OR k.APIKampanyalar_SUPERKOI = 1";
        } elseif ($koi) {
            $kosul .= " OR k.APIKampanyalar_KOI = 1";
        } elseif ($super) {
            $kosul .= " OR k.APIKampanyalar_SUPERKOI = 1";
        } else {
            $kosul .= " OR (k.APIKampanyalar_KOI = 0 AND k.APIKampanyalar_SUPERKOI = 0)";
        }
    }
    $where = "k.Durum = 1 AND ($kosul";
    if ((int)$ensureKampanyaId > 0) $where .= " OR k.APIKampanyalar_Id = " . (int)$ensureKampanyaId;
    $where .= ")";

    return $db->fetchAll("
        SELECT k.APIKampanyalar_Id AS id,
            CONCAT(
                '[', COALESCE(NULLIF(LTRIM(RTRIM(CAST(k.APIKampanyalar_OfferFromCode AS NVARCHAR(50)))), ''), '—'), '] ',
                COALESCE(NULLIF(LTRIM(RTRIM(k.APIKampanyalar_PaketAdi)), ''), k.APIKampanyalar_Ad, CONCAT('Kampanya #', k.APIKampanyalar_Id)),
                ' — ',
                COALESCE(CONVERT(VARCHAR(20), CAST(
                    CASE WHEN UPPER(LTRIM(RTRIM(k.APIKampanyalar_FaturaDonemi))) = 'YIL'
                         THEN k.APIKampanyalar_Fiyat / 12.0
                         ELSE k.APIKampanyalar_Fiyat END
                AS DECIMAL(18,2))), '0'),
                ' ', COALESCE(k.APIKampanyalar_ParaBirimi, 'TL'),
                CASE WHEN o.APIOdemeYontemleri_Ad IS NOT NULL THEN CONCAT(' — ', o.APIOdemeYontemleri_Ad) ELSE '' END,
                CASE WHEN t.APIKampanyaTurleri_Tur_Adi IS NOT NULL THEN CONCAT(' — ', t.APIKampanyaTurleri_Tur_Adi) ELSE '' END
            ) AS ad
        FROM APIKampanyalar k
        LEFT JOIN APIOdemeYontemleri o ON k.APIKampanyalar_OdemeTuru = o.APIOdemeYontemleri_Id
        LEFT JOIN APIKampanyaTurleri t ON k.APIKampanyalar_Tur     = t.APIKampanyaTurleri_Id
        WHERE $where
        ORDER BY k.APIKampanyalar_PaketAdi, k.APIKampanyalar_Ad
    ");
}

/**
 * Başvurunun bağlı birim(ler)inden Alt Bayi Personeli çözümler (form/lead/personel silsilesi).
 * O birim(ler)e KullaniciBirimYetkileri ile atanmış personel TEK ise id'sini, değilse null döndürür.
 */
function basvuruBirimPersoneli($db, $basvuru): ?string {
    $formId = (int)($basvuru['ReklamLeadFormlari_ID'] ?? 0);
    $leadId = (int)($basvuru['CallCenterApiLead_ID'] ?? 0);
    $perId  = (int)($basvuru['AltBayiPersonel_ID'] ?? 0);
    if ($formId <= 0 && $leadId <= 0 && $perId <= 0) return null;
    $tarih = "kby.Durum = 1
        AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
        AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())";
    $rows = $db->fetchAll("
        ;WITH BasvuruBirim AS (
            SELECT kby.KullaniciBirimYetkileri_Birim_id AS BirimId
            FROM KullaniciBirimYetkileri kby
            WHERE $tarih AND (
                kby.KullaniciBirimYetkileri_Personel_id = ?
                OR kby.KullaniciBirimYetkileri_AltBayi_id = (SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = ?)
                OR kby.KullaniciBirimYetkileri_ApiLead_id = ?)
            UNION
            SELECT kby.KullaniciBirimYetkileri_Birim_id
            FROM ReklamLeadFormlari f
            INNER JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_ReklamSayfa_id = f.ReklamLeadFormlari_Sayfa_id AND $tarih
            WHERE f.ReklamLeadFormlari_id = ?
        )
        SELECT DISTINCT p.DigiturkAltBayiPersonel_Id AS id
        FROM KullaniciBirimYetkileri kby
        INNER JOIN DigiturkAltBayiPersonel p ON p.Durum = 1 AND (
            kby.KullaniciBirimYetkileri_Personel_id = p.DigiturkAltBayiPersonel_Id
            OR kby.KullaniciBirimYetkileri_AltBayi_id = p.DigiturkAltBayiPersonel_AltBayiId)
        WHERE kby.KullaniciBirimYetkileri_Birim_id IN (SELECT BirimId FROM BasvuruBirim) AND $tarih
    ", [$perId, $perId, $leadId, $formId]);
    return count($rows) === 1 ? (string)$rows[0]['id'] : null;
}

// =====================================================================
// İLETİŞİM GEÇMİŞİ (BasvuruIletisimGecmisi) — her temas ayrı kayıt, üzerine yazılmaz
// =====================================================================
/**
 * Departmanın seçebildiği aktif iletişim durumları.
 * Durum için BasvuruIletisimDurumDepartmanlari kaydı yoksa herkese açıktır; admin hepsini görür.
 * $durumId verilirse yalnız o durumu döner (sunucu tarafı yetki kontrolü için).
 */
function iletisimDurumlariSecilebilir($db, int $departmanId, bool $admin, int $durumId = 0): array {
    $params = [];
    $sql = "SELECT d.BasvuruIletisimDurum_id AS id, d.BasvuruIletisimDurum_Mesaj AS ad,
                   d.BasvuruIletisimDurum_Renk AS renk, CAST(d.BasvuruIletisimDurum_TarihSecilir AS INT) AS tarih
            FROM BasvuruIletisimDurum d
            WHERE d.Durum = 1";
    if (!$admin) {
        $sql .= " AND (NOT EXISTS (SELECT 1 FROM BasvuruIletisimDurumDepartmanlari x
                                   WHERE x.BasvuruIletisimDurumDepartmanlari_IletisimDurum_id = d.BasvuruIletisimDurum_id AND x.Durum = 1)
                    OR EXISTS (SELECT 1 FROM BasvuruIletisimDurumDepartmanlari x
                               WHERE x.BasvuruIletisimDurumDepartmanlari_IletisimDurum_id = d.BasvuruIletisimDurum_id AND x.Durum = 1
                                 AND x.BasvuruIletisimDurumDepartmanlari_Departman_id = ?))";
        $params[] = $departmanId;
    }
    if ($durumId > 0) { $sql .= " AND d.BasvuruIletisimDurum_id = ?"; $params[] = $durumId; }
    return $db->fetchAll($sql . " ORDER BY d.BasvuruIletisimDurum_Mesaj", $params);
}

/** datetime-local değeri (Y-m-d\TH:i) → 'Y-m-d H:i:s'; geçersizse null */
function iletisimRandevuTarihi(string $deger): ?string {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', trim($deger));
    return ($dt && $dt->format('Y-m-d\TH:i') === trim($deger)) ? $dt->format('Y-m-d H:i:00') : null;
}

/**
 * İletişim geçmişi satırları. 'duzenle' = bu kullanıcı kaydı düzenleyebilir mi:
 * admin her zaman; diğerleri yalnız kaydın durumunun "Değiştirebilen Departmanlar"
 * listesinde (BasvuruIletisimDurumDegistirenDepartmanlari) departmanı varsa ya da durumda "Kendisi"
 * (BasvuruIletisimDurum_KendisiDegistirir) açıksa ve kaydı bu kullanıcı yazdıysa. Liste boşsa yalnız admin.
 * $gecmisId verilirse yalnız o kayıt döner (sunucu tarafı yetki kontrolü için).
 */
function iletisimGecmisiGetir($db, int $basvuruId, int $departmanId = 0, bool $admin = false, int $kullaniciId = 0, int $gecmisId = 0): array {
    if ($basvuruId <= 0) return [];
    $params = [$admin ? 1 : 0, $kullaniciId, $departmanId, $basvuruId];
    $ek = '';
    if ($gecmisId > 0) { $ek = ' AND g.BasvuruIletisimGecmisi_id = ?'; $params[] = $gecmisId; }
    return $db->fetchAll("
        SELECT g.BasvuruIletisimGecmisi_id AS id,
               g.BasvuruIletisimGecmisi_IletisimDurum_ID AS durum_id,
               g.BasvuruIletisimGecmisi_Not AS notu,
               d.BasvuruIletisimDurum_Mesaj AS durum,
               d.BasvuruIletisimDurum_Renk AS renk,
               CAST(ISNULL(d.BasvuruIletisimDurum_TarihSecilir, 0) AS INT) AS durum_tarih,
               CASE WHEN g.BasvuruIletisimGecmisi_RandevuTarihi IS NULL THEN NULL ELSE
                    CONVERT(VARCHAR(10), g.BasvuruIletisimGecmisi_RandevuTarihi, 104) + ' ' + CONVERT(VARCHAR(5), g.BasvuruIletisimGecmisi_RandevuTarihi, 108) END AS randevu,
               LEFT(CONVERT(VARCHAR(19), g.BasvuruIletisimGecmisi_RandevuTarihi, 126), 16) AS randevu_ham,
               CONVERT(VARCHAR(10), g.OlusturmaTarihi, 104) + ' ' + CONVERT(VARCHAR(5), g.OlusturmaTarihi, 108) AS tarih,
               LTRIM(RTRIM(CONCAT(k.kullanici_ad, ' ', k.kullanici_soyad))) AS kullanici,
               CASE WHEN g.GuncellemeTarihi IS NULL THEN NULL ELSE
                    CONVERT(VARCHAR(10), g.GuncellemeTarihi, 104) + ' ' + CONVERT(VARCHAR(5), g.GuncellemeTarihi, 108) END AS duzenleme_tarih,
               LTRIM(RTRIM(CONCAT(gk.kullanici_ad, ' ', gk.kullanici_soyad))) AS duzenleyen,
               CASE WHEN ? = 1 THEN 1
                    WHEN d.BasvuruIletisimDurum_KendisiDegistirir = 1 AND g.OlusturanKullanici = ? THEN 1
                    WHEN EXISTS (SELECT 1 FROM BasvuruIletisimDurumDegistirenDepartmanlari x
                                 WHERE x.BasvuruIletisimDurumDegistirenDepartmanlari_IletisimDurum_id = g.BasvuruIletisimGecmisi_IletisimDurum_ID
                                   AND x.BasvuruIletisimDurumDegistirenDepartmanlari_Departman_id = ? AND x.Durum = 1) THEN 1
                    ELSE 0 END AS duzenle
        FROM BasvuruIletisimGecmisi g
        LEFT JOIN BasvuruIletisimDurum d ON d.BasvuruIletisimDurum_id = g.BasvuruIletisimGecmisi_IletisimDurum_ID
        LEFT JOIN kullanicilar k         ON k.kullanici_id = g.OlusturanKullanici
        LEFT JOIN kullanicilar gk        ON gk.kullanici_id = g.GuncelleyenKullanici
        WHERE g.BasvuruIletisimGecmisi_Basvuru_id = ? AND g.Durum = 1$ek
        ORDER BY g.OlusturmaTarihi DESC, g.BasvuruIletisimGecmisi_id DESC
    ", $params);
}

function iletisimGecmisiHtml(array $rows): string {
    if (!$rows) return '<div class="text-muted small py-2">Henüz iletişim kaydı yok.</div>';
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $h = '<ul class="list-group list-group-flush">';
    foreach ($rows as $r) {
        $duzenleBtn = (int)$r['duzenle'] === 1
            ? ' <button type="button" class="btn btn-link btn-sm p-0 ms-1 ig-duzenle" title="Düzenle"'
              . ' data-id="' . (int)$r['id'] . '" data-durum="' . (int)$r['durum_id'] . '"'
              . ' data-durum-ad="' . $e($r['durum']) . '" data-durum-tarih="' . (int)$r['durum_tarih'] . '"'
              . ' data-randevu="' . $e($r['randevu_ham']) . '"'
              . ' data-not="' . $e($r['notu']) . '"><i class="bi bi-pencil-square"></i></button>'
            : '';
        $h .= '<li class="list-group-item px-0 py-2">'
            . '<div class="d-flex justify-content-between align-items-start gap-2">'
            . '<div>'
            . ($r['durum'] ? (preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($r['renk'] ?? ''))
                    ? '<span class="badge" style="background:' . $r['renk'] . '">' . $e($r['durum']) . '</span>'
                    : '<span class="badge bg-primary">' . $e($r['durum']) . '</span>') : '')
            . (!empty($r['randevu']) ? ' <span class="badge bg-warning text-dark"><i class="bi bi-calendar-event"></i> ' . $e($r['randevu']) . '</span>' : '')
            . '</div>'
            . '<small class="text-muted text-nowrap"><i class="bi bi-person"></i> ' . $e($r['kullanici'] ?: '—')
            . ' · <i class="bi bi-clock"></i> ' . $e($r['tarih']) . $duzenleBtn . '</small>'
            . '</div>'
            . ($r['notu'] !== null && $r['notu'] !== '' ? '<div class="mt-1" style="white-space:pre-line">' . $e($r['notu']) . '</div>' : '')
            . (!empty($r['duzenleme_tarih'])
                ? '<div class="small text-muted fst-italic mt-1"><i class="bi bi-pencil"></i> Düzenlendi: ' . $e($r['duzenleyen'] ?: '—') . ' · ' . $e($r['duzenleme_tarih']) . '</div>'
                : '')
            . '</li>';
    }
    return $h . '</ul>';
}

/**
 * Başvurunun son iletişim durumunu geçmişteki en yeni durumlu kayda eşitler (düzenleme sonrası).
 * Değişiklik varsa Basvurular güncellenir ve BasvuruLog'a yazılır.
 */
function iletisimSonDurumEsitle($db, int $basvuruId, int $kullaniciId): void {
    $son = $db->fetchOne("
        SELECT TOP 1 BasvuruIletisimGecmisi_IletisimDurum_ID AS durum_id FROM BasvuruIletisimGecmisi
        WHERE BasvuruIletisimGecmisi_Basvuru_id = ? AND Durum = 1 AND BasvuruIletisimGecmisi_IletisimDurum_ID IS NOT NULL
        ORDER BY OlusturmaTarihi DESC, BasvuruIletisimGecmisi_id DESC
    ", [$basvuruId]);
    $yeni = $son ? (int)$son['durum_id'] : null;
    $oncesi = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$basvuruId]);
    if (!$oncesi || (isset($oncesi['Basvurular_IletisimDurum_ID']) ? (int)$oncesi['Basvurular_IletisimDurum_ID'] : null) === $yeni) return;
    $db->update('Basvurular', [
        'Basvurular_IletisimDurum_ID' => $yeni,
        'GuncelleyenKullanici'        => $kullaniciId,
        'GuncellemeTarihi'            => date('Y-m-d H:i:s'),
    ], ['Basvurular_id' => $basvuruId]);
    $sonrasi = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$basvuruId]);
    basvuruLogKaydet($db, $basvuruId, 'GUNCELLE', $oncesi, $sonrasi, $kullaniciId, 'İletişim geçmişi kaydı düzenlendi');
}

// =====================================================================
// AJAX İŞLEMLERİ (kaydet + BBK adres proxy + şehre göre kampanya)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    try {
        // BBK adres API proxy — kademeli adres (2-9) + tam adres çözümleme (10)
        if ($action === 'address') {
            $endpointId = (int)($_POST['endpoint_id'] ?? 0);
            $code       = $_POST['code'] ?? '0';
            if ($endpointId < 2 || $endpointId > 10) throw new Exception('Geçersiz endpoint');
            $r = addressApiCall($db, $endpointId, $code);
            if (!$r['ok']) { echo json_encode(['success' => false, 'message' => $r['msg']]); exit; }
            if ($endpointId === 10) {
                echo json_encode(['success' => true, 'data' => $r['data']]);
            } else {
                // Digiturk, kaydı olmayan seviyelerde boş liste yerine geçersiz kayıt
                // ({code:0, name:null}) döndürüyor. Bunlar JS'e hiç ulaşmasın.
                $temiz = AdresYanitHelper::listeyiTemizle($r['data'] ?: []);
                // code'ları string olarak döndür (16 haneye kadar — JS Number taşmasını önler)
                $items = array_map(fn($x) => ['name' => $x['name'] ?? '', 'code' => (string)($x['code'] ?? '')], $temiz);

                $out = ['success' => true, 'data' => $items];
                if (!$items) {
                    $etiket = AdresYanitHelper::seviyeEtiketi((string)($r['url'] ?? ''));
                    $kapiMi = ($etiket === 'kapı/daire');
                    $out['emptyReason'] = $kapiMi ? 'NO_DOOR_RECORD' : 'NO_RECORD';
                    $out['message']     = $kapiMi
                        ? 'Bu bina için kapı/daire kaydı bulunmuyor. BBK adres kodu üretilemiyor; aynı sokakta kapı kaydı olan bir bina deneyin.'
                        : 'Bu seçim için ' . ($etiket ?? 'adres') . ' kaydı bulunmuyor.';
                }
                echo json_encode($out);
            }
            exit;
        }

        // BBK kodunu doğrula (endpoint 10) + şehir tespiti
        if ($action === 'bbk_dogrula') {
            $kod = trim((string)($_POST['bbk'] ?? ''));
            if ($kod === '') throw new Exception('BBK kodu boş');
            $r = addressApiCall($db, 10, $kod);
            if (!$r['ok']) { echo json_encode(['success' => false, 'message' => $r['msg']]); exit; }
            $adr = is_array($r['data']) ? $r['data'] : [];
            [$sehirId, $sehirAd] = bbkSehirEslestir($db, $adr);
            echo json_encode(['success' => true, 'adres' => $adr, 'sehir_id' => $sehirId, 'sehir_ad' => $sehirAd]);
            exit;
        }

        // Şehre göre kampanya listesi
        if ($action === 'kampanyalar') {
            $sehirId = (int)($_POST['sehir_id'] ?? 0);
            echo json_encode(['success' => true, 'data' => kampanyaListesi($db, $sehirId)]);
            exit;
        }

        // Telefon mükerrer kontrolü — aynı ülke+alan+numara kaç başvuruda var (düzenlemede kendisi hariç)
        if ($action === 'mukerrer_kontrol') {
            $ulke = trim((string)($_POST['phoneCountryNumber'] ?? ''));
            $alan = trim((string)($_POST['phoneAreaNumber'] ?? ''));
            $no   = trim((string)($_POST['phoneNumber'] ?? ''));
            $haric = (int)($_POST['id'] ?? 0);
            if ($no === '') { echo json_encode(['success' => true, 'adet' => 0]); exit; }
            $adet = (int)($db->fetchOne(
                "SELECT COUNT(*) AS c FROM Basvurular
                 WHERE ISNULL(phoneCountryNumber,'') = ? AND ISNULL(phoneAreaNumber,'') = ?
                   AND ISNULL(phoneNumber,'') = ? AND Basvurular_id <> ?",
                [$ulke, $alan, $no, $haric])['c'] ?? 0);

            // Kara liste durumu (yalnız bilgi — sayaç artırmaz, log yazmaz)
            $kl = KaraListe::gsmEngelli($ulke . $alan . $no);

            echo json_encode([
                'success'   => true,
                'adet'      => $adet,
                'karaListe' => $kl ? ['aciklama' => $kl['KaraListe_Aciklama'] ?: null] : null,
            ]);
            exit;
        }

        // Reklam Lead Formu → bağlı birimin alt bayi personelleri (form seçilince otomatik personel için)
        if ($action === 'form_personel') {
            $formId = (int)($_POST['form_id'] ?? 0);
            if ($formId <= 0) { echo json_encode(['success' => true, 'data' => []]); exit; }
            $tarih = "kby.Durum = 1
                AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
                AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())";
            $birimFiltre = '';
            $params = [$formId];
            if ($birimKisitli) {
                if (empty($izinliBirimler)) { echo json_encode(['success' => true, 'data' => []]); exit; }
                $ph = implode(',', array_fill(0, count($izinliBirimler), '?'));
                $birimFiltre = " AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)";
                $params = array_merge($params, $izinliBirimler);
            }
            $rows = $db->fetchAll("
                SELECT DISTINCT p.DigiturkAltBayiPersonel_Id AS id,
                       CONCAT(COALESCE(an.DigiturkAnaBayiler_Ad + N' — ', ''),
                              COALESCE(a.DigiturkAltBayiler_Ad + N'/', ''),
                              p.DigiturkAltBayiPersonel_AdSoyad) AS ad
                FROM ReklamLeadFormlari f
                INNER JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_ReklamSayfa_id = f.ReklamLeadFormlari_Sayfa_id
                INNER JOIN DigiturkAltBayiPersonel p ON p.Durum = 1 AND (
                    kby.KullaniciBirimYetkileri_Personel_id = p.DigiturkAltBayiPersonel_Id
                    OR kby.KullaniciBirimYetkileri_AltBayi_id  = p.DigiturkAltBayiPersonel_AltBayiId)
                LEFT JOIN DigiturkAltBayiler a  ON a.DigiturkAltBayiler_Id = p.DigiturkAltBayiPersonel_AltBayiId
                LEFT JOIN DigiturkAnaBayiler an ON an.DigiturkAnaBayiler_Id = a.DigiturkAltBayiler_AnaBayiId
                WHERE f.ReklamLeadFormlari_id = ? $birimFiltre AND $tarih
                ORDER BY ad
            ", $params);
            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        // Sayfa Yetki modalı — departmanın alan yetkileri (yalnız admin)
        if ($action === 'alan_yetki_getir') {
            if (!$alanAdmin) throw new Exception('Bu işlem için yetkiniz yok!');
            $depId = (int)($_POST['departman_id'] ?? 0);
            if ($depId <= 0) throw new Exception('Departman seçiniz.');
            echo json_encode(['success' => true, 'data' => array_values(alanYetkileriGetir($db, $alanSayfaId, $depId))]);
            exit;
        }

        // Sayfa Yetki modalı — kaydet (her alan için MERGE)
        if ($action === 'alan_yetki_kaydet') {
            if (!$alanAdmin) throw new Exception('Bu işlem için yetkiniz yok!');
            $depId = (int)($_POST['departman_id'] ?? 0);
            if ($depId <= 0) throw new Exception('Departman seçiniz.');
            $gelen = json_decode((string)($_POST['yetkiler'] ?? '[]'), true);
            if (!is_array($gelen)) throw new Exception('Geçersiz veri.');

            // Yalnız bu sayfaya ait alan id'leri kabul edilir
            $gecerli = array_map(fn($r) => (int)$r['id'], array_values(alanYetkileriGetir($db, $alanSayfaId, $depId)));
            $simdi   = date('Y-m-d H:i:s');
            foreach ($gelen as $g) {
                $alanId = (int)($g['id'] ?? 0);
                if (!in_array($alanId, $gecerli, true)) continue;
                $gor     = !empty($g['gor']) ? 1 : 0;
                $duzenle = ($gor && !empty($g['duzenle'])) ? 1 : 0;
                $kilitli = ($duzenle && !empty($g['kilitli_duzenle'])) ? 1 : 0;
                $ok = $db->execute("
                    MERGE dbo.Menu_SayfaAlanYetkileri AS t
                    USING (SELECT ? AS Alan_id, ? AS Departman_id) AS s
                       ON t.Menu_SayfaAlanYetkileri_Alan_id = s.Alan_id AND t.Menu_SayfaAlanYetkileri_Departman_id = s.Departman_id
                    WHEN MATCHED THEN UPDATE SET
                         Menu_SayfaAlanYetkileri_Gor = ?, Menu_SayfaAlanYetkileri_Duzenle = ?, Menu_SayfaAlanYetkileri_KilitliDuzenle = ?,
                         Durum = 1, GuncelleyenKullanici = ?, GuncellemeTarihi = ?
                    WHEN NOT MATCHED THEN INSERT
                         (Menu_SayfaAlanYetkileri_Alan_id, Menu_SayfaAlanYetkileri_Departman_id, Menu_SayfaAlanYetkileri_Gor,
                          Menu_SayfaAlanYetkileri_Duzenle, Menu_SayfaAlanYetkileri_KilitliDuzenle, OlusturanKullanici, OlusturmaTarihi, Durum)
                         VALUES (s.Alan_id, s.Departman_id, ?, ?, ?, ?, ?, 1);
                ", [$alanId, $depId, $gor, $duzenle, $kilitli, $user['kullanici_id'], $simdi,
                    $gor, $duzenle, $kilitli, $user['kullanici_id'], $simdi]);
                if (!$ok) throw new Exception('Alan yetkisi kaydedilemedi (alan #' . $alanId . ').');
            }
            echo json_encode(['success' => true, 'message' => 'Alan yetkileri kaydedildi']);
            exit;
        }

        // Süreç Kontrol — Endpoint 18 (CheckRequisitionList): gövde [TalepKayitNo] → data[0].requestStatusCode
        // HTTP 200 ve requestStatusCode gelirse: BasvuruSurecDurum_ID = requestStatusCode, BasvuruDurum_ID = 1
        if ($action === 'surec_kontrol') {
            // Yalnız departman 1/21/22 (POST manipülasyonuna karşı sunucuda da kontrol)
            if (!in_array((int)($user['departman_id'] ?? 0), [1, 21, 22], true)) {
                throw new Exception('Bu işlem için yetkiniz yok!');
            }
            $kayitId = (int)($_POST['id'] ?? 0);
            if ($kayitId <= 0) throw new Exception('Süreç sorgusu yalnız kayıtlı başvuruda yapılır.');
            if (($birimKisitli && !basvuruKayitBirimYetkiliMi($db, $izinliBirimler, $kayitId))
                || ($kendiKisitli && !basvuruKayitKendiMi($db, $kullaniciId, $kayitId))) {
                throw new Exception('Bu kayda erişim yetkiniz yok!');
            }

            // Boşluksuz — yalnız rakam
            $temiz        = fn($v) => preg_replace('/\D/', '', (string)$v);
            $musteriNo    = $temiz($_POST['MusteriNo'] ?? '');
            $talepKayitNo = $temiz($_POST['TalepKayitNo'] ?? '');
            $memoId       = $temiz($_POST['MemoID'] ?? '');
            // Alan yetkisi yoksa (departman / süreç kilidi) POST yok sayılır, kayıttaki değer kullanılır
            $mevcutNo = $db->fetchOne("SELECT MusteriNo, TalepKayitNo, MemoID FROM Basvurular WHERE Basvurular_id = ?", [$kayitId]) ?: [];
            if (!$alanDuzenle('MusteriNo'))    $musteriNo    = $temiz($mevcutNo['MusteriNo'] ?? '');
            if (!$alanDuzenle('TalepKayitNo')) $talepKayitNo = $temiz($mevcutNo['TalepKayitNo'] ?? '');
            if (!$alanDuzenle('MemoID'))       $memoId       = $temiz($mevcutNo['MemoID'] ?? '');
            if ($talepKayitNo === '') throw new Exception('Talep Kayıt No boş olamaz.');

            // Süreç sorgusu ortak servisten yürür: endpoint (Order/CheckRequisition),
            // query string biçimi ve TOKEN SAHİBİ PERSONEL orada çözülür. Personel,
            // başvurunun ana bayisi için IRIS rapor cron zamanlamasında tanımlı olan
            // hesaptır — böylece cron'daki değişiklik panele de yansır, burada ayrıca
            // endpoint id'si veya personel sabiti tutulmaz.
            require_once __DIR__ . '/../includes/IrisTalepServisi.php';

            $anaBayiId = irisBasvuruAnaBayi($db, $kayitId);
            $istekBilgi = 'POST Order/CheckRequisition?RequestId=' . (int)$talepKayitNo;

            try {
                $apiSonuc = irisDurumSorgula($db, [(int)$talepKayitNo], $anaBayiId);
            } catch (Throwable $ex) {
                basvuruLogApi($db, $kayitId, 'API_SUREC', $istekBilgi, '', $ex->getMessage(), 0, $user['kullanici_id'], 'Süreç kontrol hatası');
                echo json_encode(['success' => false, 'message' => 'Süreç sorgusu başarısız: ' . $ex->getMessage()]);
                exit;
            }

            $data0      = $apiSonuc[(int)$talepKayitNo] ?? null;
            $statusCode = $data0 ? ($data0['requestStatusCode'] ?? $data0['RequestStatusCode'] ?? null) : null;
            $resp       = json_encode($data0, JSON_UNESCAPED_UNICODE);
            $http       = $data0 ? 200 : 0;

            if ($statusCode !== null) {
                $logOncesi = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$kayitId]);
                $db->update('Basvurular', [
                    'MusteriNo'            => ($musteriNo === '' ? null : (int)$musteriNo),
                    'TalepKayitNo'         => (int)$talepKayitNo,
                    'MemoID'               => ($memoId === '' ? null : (int)$memoId),
                    'BasvuruSurecDurum_ID' => (int)$statusCode,
                    'BasvuruDurum_ID'      => 1,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], ['Basvurular_id' => $kayitId]);
                $logSonrasi = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$kayitId]);
                basvuruLogKaydet($db, $kayitId, 'GUNCELLE', $logOncesi, $logSonrasi, $user['kullanici_id'], 'Süreç kontrol (CheckRequisition) — requestStatusCode=' . (int)$statusCode);
                basvuruLogApi($db, $kayitId, 'API_SUREC', $istekBilgi, '', $resp, $http, $user['kullanici_id'], 'BasvuruSurecDurum_ID=' . (int)$statusCode . ', BasvuruDurum_ID=1');

                $sd = $db->fetchOne("SELECT BasvuruSurecDurum_Mesaj AS ad FROM BasvuruSurecDurum WHERE BasvuruSurecDurum_id = ?", [(int)$statusCode]);
                $bd = $db->fetchOne("SELECT BasvuruDurum_Mesaj AS ad FROM BasvuruDurum WHERE BasvuruDurum_id = 1");
                echo json_encode([
                    'success'         => true,
                    'message'         => 'Süreç durumu güncellendi (requestStatusCode = ' . (int)$statusCode . ').',
                    'http'            => $http,
                    'surecDurumId'    => (int)$statusCode,
                    'surecDurumAd'    => $sd['ad'] ?? null,
                    'basvuruDurumId'  => 1,
                    'basvuruDurumAd'  => $bd['ad'] ?? null,
                ]);
                exit;
            }

            basvuruLogApi($db, $kayitId, 'API_SUREC', $istekBilgi, '', $resp, $http, $user['kullanici_id'], 'Süreç kontrol başarısız (statusCode alınamadı)');
            echo json_encode(['success' => false, 'message' => 'Süreç durumu alınamadı — Digiturk bu talep için kayıt döndürmedi.']);
            exit;
        }

        // İletişim geçmişine kayıt ekle (durum ve/veya not). Durum seçildiyse ana kayıttaki son durum da güncellenir.
        if ($action === 'iletisim_ekle') {
            $kayitId = (int)($_POST['id'] ?? 0);
            if ($kayitId <= 0) throw new Exception('İletişim kaydı yalnız kayıtlı başvuruya eklenir.');
            if (!$permissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok!');
            if (($birimKisitli && !basvuruKayitBirimYetkiliMi($db, $izinliBirimler, $kayitId))
                || ($kendiKisitli && !basvuruKayitKendiMi($db, $kullaniciId, $kayitId))) {
                throw new Exception('Bu kayda erişim yetkiniz yok!');
            }

            // Alan yetkisi: durum → Basvurular_IletisimDurum_ID, not → Basvuru_Aciklama
            $durumId = $alanDuzenle('Basvurular_IletisimDurum_ID') ? (int)($_POST['durum_id'] ?? 0) : 0;
            $notu    = $alanDuzenle('Basvuru_Aciklama') ? trim((string)($_POST['not'] ?? '')) : '';
            if ($durumId <= 0 && $notu === '') throw new Exception('İletişim durumu seçin veya not yazın.');
            if (mb_strlen($notu) > 1000) throw new Exception('Not en fazla 1000 karakter olabilir.');
            // Departman yetkisi + tarih/saat zorunluluğu (durum tanımındaki bayrak)
            $randevu = null;
            if ($durumId > 0) {
                $durumSatir = iletisimDurumlariSecilebilir($db, (int)($user['departman_id'] ?? 0), $alanAdmin, $durumId)[0] ?? null;
                if (!$durumSatir) throw new Exception('Bu iletişim durumunu seçme yetkiniz yok.');
                if ((int)$durumSatir['tarih'] === 1) {
                    $randevu = iletisimRandevuTarihi((string)($_POST['randevu'] ?? ''));
                    if ($randevu === null) throw new Exception('"' . $durumSatir['ad'] . '" için tarih ve saat seçmelisiniz.');
                }
            }

            $simdi = date('Y-m-d H:i:s');
            $db->insert('BasvuruIletisimGecmisi', [
                'BasvuruIletisimGecmisi_Basvuru_id'       => $kayitId,
                'BasvuruIletisimGecmisi_IletisimDurum_ID' => $durumId > 0 ? $durumId : null,
                'BasvuruIletisimGecmisi_Not'              => $notu !== '' ? $notu : null,
                'BasvuruIletisimGecmisi_RandevuTarihi'    => $randevu,
                'OlusturanKullanici'                      => $user['kullanici_id'],
                'OlusturmaTarihi'                         => $simdi,
                'Durum'                                   => 1,
            ]);

            if ($durumId > 0) {
                $logOncesi = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$kayitId]);
                $db->update('Basvurular', [
                    'Basvurular_IletisimDurum_ID' => $durumId,
                    'GuncelleyenKullanici'        => $user['kullanici_id'],
                    'GuncellemeTarihi'            => $simdi,
                ], ['Basvurular_id' => $kayitId]);
                $logSonrasi = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$kayitId]);
                basvuruLogKaydet($db, $kayitId, 'GUNCELLE', $logOncesi, $logSonrasi, $user['kullanici_id'], 'İletişim geçmişine kayıt eklendi');
            }

            echo json_encode([
                'success' => true,
                'message' => 'İletişim kaydı eklendi',
                'html'    => iletisimGecmisiHtml(iletisimGecmisiGetir($db, $kayitId, (int)($user['departman_id'] ?? 0), $alanAdmin, (int)$user['kullanici_id'])),
            ]);
            exit;
        }

        // İletişim geçmişindeki mevcut kaydı düzenle (durum + not + randevu).
        // Yetki: admin veya kaydın durumunun "Değiştirebilen Departmanlar" listesindeki departman.
        if ($action === 'iletisim_duzenle') {
            $kayitId  = (int)($_POST['id'] ?? 0);
            $gecmisId = (int)($_POST['gecmis_id'] ?? 0);
            if ($kayitId <= 0 || $gecmisId <= 0) throw new Exception('Geçersiz kayıt.');
            if (!$permissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok!');
            if (($birimKisitli && !basvuruKayitBirimYetkiliMi($db, $izinliBirimler, $kayitId))
                || ($kendiKisitli && !basvuruKayitKendiMi($db, $kullaniciId, $kayitId))) {
                throw new Exception('Bu kayda erişim yetkiniz yok!');
            }

            $depId = (int)($user['departman_id'] ?? 0);
            $eski  = iletisimGecmisiGetir($db, $kayitId, $depId, $alanAdmin, (int)$user['kullanici_id'], $gecmisId)[0] ?? null;
            if (!$eski) throw new Exception('İletişim kaydı bulunamadı.');
            if ((int)$eski['duzenle'] !== 1) throw new Exception('Bu kaydı düzenleme yetkiniz yok.');

            // Alan yetkisi yoksa eski değer korunur
            $durumId = $alanDuzenle('Basvurular_IletisimDurum_ID') ? (int)($_POST['durum_id'] ?? 0) : (int)$eski['durum_id'];
            $notu    = $alanDuzenle('Basvuru_Aciklama') ? trim((string)($_POST['not'] ?? '')) : (string)($eski['notu'] ?? '');
            if ($durumId <= 0 && $notu === '') throw new Exception('İletişim durumu seçin veya not yazın.');
            if (mb_strlen($notu) > 1000) throw new Exception('Not en fazla 1000 karakter olabilir.');

            $randevu = null;
            if ($durumId > 0) {
                // Durum değiştiyse yeni durum departmanın seçebildikleri arasında olmalı; aynı kaldıysa kontrol edilmez
                if ($durumId !== (int)$eski['durum_id']
                    && !iletisimDurumlariSecilebilir($db, $depId, $alanAdmin, $durumId)) {
                    throw new Exception('Bu iletişim durumunu seçme yetkiniz yok.');
                }
                $dt = $db->fetchOne("SELECT BasvuruIletisimDurum_Mesaj AS ad, CAST(BasvuruIletisimDurum_TarihSecilir AS INT) AS tarih
                                     FROM BasvuruIletisimDurum WHERE BasvuruIletisimDurum_id = ?", [$durumId]);
                if (!$dt) throw new Exception('Geçersiz iletişim durumu.');
                if ((int)$dt['tarih'] === 1) {
                    $randevu = iletisimRandevuTarihi((string)($_POST['randevu'] ?? ''));
                    if ($randevu === null) throw new Exception('"' . $dt['ad'] . '" için tarih ve saat seçmelisiniz.');
                }
            }

            $db->update('BasvuruIletisimGecmisi', [
                'BasvuruIletisimGecmisi_IletisimDurum_ID' => $durumId > 0 ? $durumId : null,
                'BasvuruIletisimGecmisi_Not'              => $notu !== '' ? $notu : null,
                'BasvuruIletisimGecmisi_RandevuTarihi'    => $randevu,
                'GuncelleyenKullanici'                    => $user['kullanici_id'],
                'GuncellemeTarihi'                        => date('Y-m-d H:i:s'),
            ], ['BasvuruIletisimGecmisi_id' => $gecmisId]);

            // Düzenlenen kayıt en yeni durumlu kayıtsa başvurunun son durumu da değişir
            iletisimSonDurumEsitle($db, $kayitId, (int)$user['kullanici_id']);
            $sonAd = $db->fetchOne("SELECT d.BasvuruIletisimDurum_Mesaj AS ad FROM Basvurular b
                                    LEFT JOIN BasvuruIletisimDurum d ON d.BasvuruIletisimDurum_id = b.Basvurular_IletisimDurum_ID
                                    WHERE b.Basvurular_id = ?", [$kayitId])['ad'] ?? null;

            echo json_encode([
                'success'  => true,
                'message'  => 'İletişim kaydı güncellendi',
                'html'     => iletisimGecmisiHtml(iletisimGecmisiGetir($db, $kayitId, $depId, $alanAdmin, (int)$user['kullanici_id'])),
                'sonDurum' => $sonAd,
            ]);
            exit;
        }

        if ($action !== 'kaydet') {
            throw new Exception('Geçersiz işlem');
        }

        $kayitId = (int)($_POST['id'] ?? 0);

        if ($kayitId > 0 && !$permissions['can_edit']) throw new Exception('Düzenleme yetkiniz yok!');
        if ($kayitId == 0 && !$permissions['can_add'])  throw new Exception('Ekleme yetkiniz yok!');
        if ($kayitId > 0 && (($birimKisitli && !basvuruKayitBirimYetkiliMi($db, $izinliBirimler, $kayitId))
                          || ($kendiKisitli && !basvuruKayitKendiMi($db, $kullaniciId, $kayitId)))) {
            throw new Exception('Bu kayda erişim yetkiniz yok!');
        }

        $intOrNull = function ($v) { $v = trim((string)$v); return ($v === '') ? null : (int)$v; };
        $strOrNull = function ($v) { $v = trim((string)$v); return ($v === '') ? null : $v; };

        $data = [
            'Isim'                  => $strOrNull($_POST['Isim'] ?? ''),
            'Soyisim'               => $strOrNull($_POST['Soyisim'] ?? ''),
            'TCKimlikNo'            => $strOrNull($_POST['TCKimlikNo'] ?? ''),
            'email'                 => $strOrNull($_POST['email'] ?? ''),
            'phoneCountryNumber'    => $strOrNull($_POST['phoneCountryNumber'] ?? ''),
            'phoneAreaNumber'       => $strOrNull($_POST['phoneAreaNumber'] ?? ''),
            'phoneNumber'           => $strOrNull($_POST['phoneNumber'] ?? ''),
            'birthDate'             => $strOrNull($_POST['birthDate'] ?? ''),
            'genderType'            => $strOrNull($_POST['genderType'] ?? ''),
            'KimlikKartiTurleri_ID' => $intOrNull($_POST['KimlikKartiTurleri_ID'] ?? ''),
            'bbkAddressCode'        => $strOrNull($_POST['bbkAddressCode'] ?? ''),
            'Kampanyalar_ID'        => $intOrNull($_POST['Kampanyalar_ID'] ?? ''),
            'BasvuruDurum_ID'       => $intOrNull($_POST['BasvuruDurum_ID'] ?? ''),
            'BasvuruDurumMesaj'     => $strOrNull($_POST['BasvuruDurumMesaj'] ?? ''),
            'Basvuru_Aciklama'      => $strOrNull($_POST['Basvuru_Aciklama'] ?? ''),
            'Basvurular_IletisimDurum_ID' => $intOrNull($_POST['Basvurular_IletisimDurum_ID'] ?? ''),
            'MusteriNo'             => $intOrNull($_POST['MusteriNo'] ?? ''),
            'TalepKayitNo'          => $intOrNull($_POST['TalepKayitNo'] ?? ''),
            'MemoID'                => $intOrNull($_POST['MemoID'] ?? ''),
            'BasvuruSurecDurum_ID'  => $intOrNull($_POST['BasvuruSurecDurum_ID'] ?? ''),
            'AltBayiPersonel_ID'    => $intOrNull($_POST['AltBayiPersonel_ID'] ?? ''),
            'ReklamLeadFormlari_ID' => $intOrNull($_POST['ReklamLeadFormlari_ID'] ?? ''),
            'CallCenterApiLead_ID'  => $intOrNull($_POST['CallCenterApiLead_ID'] ?? ''),
            // Digiturk sipariş alanları (CC servis dokümanı V5.01)
            // 0 = adrese bakan bayiye (API varsayılanı), 1 = bana yönlendir
            'ticketRoutingType'     => ((string)($_POST['ticketRoutingType'] ?? '') === '1') ? 1 : 0,
            'isHandicapped'         => isset($_POST['isHandicapped']) ? 1 : 0,
            'isVeteran'             => isset($_POST['isVeteran'])     ? 1 : 0,
        ];

        // Alan yetkisi: görünmeyen / düzenlenemeyen (departman veya süreç kilidi) alanlar yok sayılır → kayıttaki değer korunur
        foreach (array_keys($data) as $alanKod) {
            if (!$alanDuzenle($alanKod)) unset($data[$alanKod]);
        }
        // Düzenlemede açıklama salt okunur (ilk bilgi); iletişim durumu yalnız İletişim Geçmişi'nden değişir
        $yeniRandevu = null;
        if ($kayitId > 0) {
            unset($data['Basvuru_Aciklama'], $data['Basvurular_IletisimDurum_ID']);
        } elseif (!empty($data['Basvurular_IletisimDurum_ID'])) {
            // Yeni kayıtta da departman yetkisi + tarih/saat zorunluluğu
            $durumSatir = iletisimDurumlariSecilebilir($db, (int)($user['departman_id'] ?? 0), $alanAdmin, (int)$data['Basvurular_IletisimDurum_ID'])[0] ?? null;
            if (!$durumSatir) throw new Exception('Bu iletişim durumunu seçme yetkiniz yok.');
            if ((int)$durumSatir['tarih'] === 1) {
                $yeniRandevu = iletisimRandevuTarihi((string)($_POST['IletisimRandevuTarihi'] ?? ''));
                if ($yeniRandevu === null) throw new Exception('"' . $durumSatir['ad'] . '" için tarih ve saat seçmelisiniz.');
            }
        }

        // TC Kimlik No: doluysa 11 haneli sayı olmalı (JS filtresi atlanırsa diye sunucuda da kontrol)
        if (($data['TCKimlikNo'] ?? null) !== null) {
            $tc = preg_replace('/\D/', '', $data['TCKimlikNo']);
            if (strlen($tc) !== 11 || $tc[0] === '0') {
                throw new Exception('TC Kimlik No 11 haneli sayı olmalıdır.');
            }
            $data['TCKimlikNo'] = $tc;
        }

        // KARA LİSTE — numara (veya TC) engelliyse kayıt oluşturulmaz/güncellenmez.
        // Yetkisi olmayan (POST'tan çıkarılan) alanlar için kayıttaki değer kullanılır.
        $klVeri = $data;
        if ($kayitId > 0) {
            $klMevcut = $db->fetchOne("SELECT Isim, Soyisim, TCKimlikNo, phoneCountryNumber, phoneAreaNumber, phoneNumber FROM Basvurular WHERE Basvurular_id = ?", [$kayitId]) ?: [];
            $klVeri   = array_merge($klMevcut, $data);
        }
        $engel = KaraListe::kontrolVeLogla(
            KaraListe::kayittanGsm($klVeri),
            'basvuru-form:' . ($kayitId > 0 ? 'guncelle' : 'ekle'),
            ['Isim' => $klVeri['Isim'] ?? null, 'Soyisim' => $klVeri['Soyisim'] ?? null],
            $kayitId > 0 ? $kayitId : null,
            $user['kullanici_id'],
            (string)($klVeri['TCKimlikNo'] ?? '')
        );
        if ($engel) {
            throw new Exception($engel['_mesaj'] . ' (kara listede)');
        }

        // Kısıtlı kullanıcı: seçilen personel/lead/form izinli birime ait olmalı (POST manipülasyonuna karşı)
        if ($birimKisitli) {
            if (!secimBirimYetkiliMi($db, $izinliBirimler, 'personel', (int)($data['AltBayiPersonel_ID'] ?? 0))) {
                throw new Exception('Seçilen alt bayi personeli için yetkiniz yok!');
            }
            if (!secimBirimYetkiliMi($db, $izinliBirimler, 'lead', (int)($data['CallCenterApiLead_ID'] ?? 0))) {
                throw new Exception('Seçilen API lead için yetkiniz yok!');
            }
            if (!secimBirimYetkiliMi($db, $izinliBirimler, 'form', (int)($data['ReklamLeadFormlari_ID'] ?? 0))) {
                throw new Exception('Seçilen reklam lead formu için yetkiniz yok!');
            }
        }

        // Yeni başvuruda Alt Bayi Personeli zorunlu (tüm departmanlar)
        if ($kayitId == 0 && empty($data['AltBayiPersonel_ID'])) {
            throw new Exception('Alt Bayi Personeli seçimi zorunludur.');
        }

        // Alt Bayi Personeli: alan yetkisi olmayan departman elle değiştiremez.
        // Atanmışsa mevcut değer korunur (POST manipülasyonuna karşı);
        // NULL ise ve forma (kullanıcının birim kapsamında) TEK personel bağlıysa onu otomatik atar (ekranda görünen değer).
        if (!$alanDuzenle('AltBayiPersonel_ID') && $kayitId > 0) {
            $mevcutPer = $db->fetchOne("SELECT AltBayiPersonel_ID, ReklamLeadFormlari_ID, CallCenterApiLead_ID FROM Basvurular WHERE Basvurular_id = ?", [$kayitId]);
            $mevcutId  = isset($mevcutPer['AltBayiPersonel_ID']) ? (int)$mevcutPer['AltBayiPersonel_ID'] : 0;

            if ($mevcutId > 0) {
                $data['AltBayiPersonel_ID'] = $mevcutId;   // zaten atanmış → korunur (POST manipülasyonuna karşı)
            } else {
                // NULL → ekranı üreten aynı silsileyle (form/lead/personel → birim → tek personel) türet ve yaz
                $data['AltBayiPersonel_ID'] = basvuruBirimPersoneli($db, [
                    'ReklamLeadFormlari_ID' => (int)($data['ReklamLeadFormlari_ID'] ?? 0) ?: (int)($mevcutPer['ReklamLeadFormlari_ID'] ?? 0),
                    'CallCenterApiLead_ID'  => (int)($data['CallCenterApiLead_ID'] ?? 0) ?: (int)($mevcutPer['CallCenterApiLead_ID'] ?? 0),
                    'AltBayiPersonel_ID'    => 0,
                ]);
            }
        }

        if ($kayitId > 0) {
            // LOG: güncelleme öncesi tam kayıt
            $logOncesi = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$kayitId]);

            $data['GuncelleyenKullanici'] = $user['kullanici_id'];
            $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
            $result = $db->update('Basvurular', $data, ['Basvurular_id' => $kayitId]);

            // LOG: güncelleme sonrası tam kayıt → değişen alanlar otomatik hesaplanır
            $logSonrasi = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$kayitId]);
            basvuruLogKaydet($db, $kayitId, 'GUNCELLE', $logOncesi, $logSonrasi, $user['kullanici_id'], 'Başvuru formdan güncellendi');

            echo json_encode(['success' => (bool)$result, 'message' => 'Başvuru güncellendi', 'redirect' => '/Admin/basvuru-yonetimi']);
        } else {
            $data['OlusturanKullanici']   = $user['kullanici_id'];
            $data['OlusturmaTarihi']      = date('Y-m-d H:i:s');
            $data['GuncelleyenKullanici'] = $user['kullanici_id'];
            $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
            // Yeni kayıt ekleyene otomatik atanır (dağıtım havuzuna girmez)
            $data['Basvurular_AtananKullanici_ID'] = $user['kullanici_id'];
            $data['Basvurular_AtamaTarihi']        = date('Y-m-d H:i:s');
            $yeniId = $db->insert('Basvurular', $data);

            // LOG: yeni eklenen tam kayıt
            $logSonrasi = $yeniId ? $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [(int)$yeniId]) : $data;
            basvuruLogKaydet($db, (int)$yeniId, 'EKLE', null, $logSonrasi, $user['kullanici_id'], 'Başvuru formdan eklendi');

            // Yeni kayıtta iletişim durumu seçildiyse geçmişin ilk kaydı olarak da yazılır
            if ($yeniId && !empty($data['Basvurular_IletisimDurum_ID'])) {
                $db->insert('BasvuruIletisimGecmisi', [
                    'BasvuruIletisimGecmisi_Basvuru_id'       => (int)$yeniId,
                    'BasvuruIletisimGecmisi_IletisimDurum_ID' => (int)$data['Basvurular_IletisimDurum_ID'],
                    'BasvuruIletisimGecmisi_RandevuTarihi'    => $yeniRandevu,
                    'OlusturanKullanici'                      => $user['kullanici_id'],
                    'OlusturmaTarihi'                         => $data['OlusturmaTarihi'],
                    'Durum'                                   => 1,
                ]);
            }

            echo json_encode(['success' => (bool)$yeniId, 'message' => 'Başvuru eklendi', 'redirect' => '/Admin/basvuru-yonetimi']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// =====================================================================
// DÜZENLEME — kayıt çek
// =====================================================================
$basvuru = null;
if ($editMode) {
    if (($birimKisitli && !basvuruKayitBirimYetkiliMi($db, $izinliBirimler, $id))
        || ($kendiKisitli && !basvuruKayitKendiMi($db, $kullaniciId, $id))) {
        PageAuth::accessDenied('Bu kayda erişim yetkiniz yok!');
    }
    $basvuru = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$id]);
    if (!$basvuru) {
        header('Location: /Admin/basvuru-yonetimi?error=notfound');
        exit;
    }
}

// =====================================================================
// BİRİM RENGİ (yalnız düzenleme) — liste sayfasıyla aynı COALESCE mantığı
// =====================================================================
$birimAdi = null;
$birimRenk = null;
if ($editMode && $basvuru) {
    $bilgi = $db->fetchOne("
        SELECT
          COALESCE(
            (SELECT TOP 1 b.KullaniciBirim_Adi FROM KullaniciBirimYetkileri kby
               INNER JOIN KullaniciBirim b ON b.KullaniciBirim_id = kby.KullaniciBirimYetkileri_Birim_id
               WHERE kby.Durum = 1
                 AND (kby.KullaniciBirimYetkileri_Personel_id = t.AltBayiPersonel_ID
                   OR kby.KullaniciBirimYetkileri_AltBayi_id = (SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID)
                   OR kby.KullaniciBirimYetkileri_ApiLead_id = t.CallCenterApiLead_ID)),
            (SELECT TOP 1 b2.KullaniciBirim_Adi FROM ReklamLeadFormlari f
               INNER JOIN KullaniciBirimYetkileri kby2 ON kby2.KullaniciBirimYetkileri_ReklamSayfa_id = f.ReklamLeadFormlari_Sayfa_id AND kby2.Durum = 1
               INNER JOIN KullaniciBirim b2 ON b2.KullaniciBirim_id = kby2.KullaniciBirimYetkileri_Birim_id
               WHERE f.ReklamLeadFormlari_id = t.ReklamLeadFormlari_ID)
          ) AS BirimAdi,
          COALESCE(
            (SELECT TOP 1 b.KullaniciBirim_Renk FROM KullaniciBirimYetkileri kby
               INNER JOIN KullaniciBirim b ON b.KullaniciBirim_id = kby.KullaniciBirimYetkileri_Birim_id
               WHERE kby.Durum = 1
                 AND (kby.KullaniciBirimYetkileri_Personel_id = t.AltBayiPersonel_ID
                   OR kby.KullaniciBirimYetkileri_AltBayi_id = (SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID)
                   OR kby.KullaniciBirimYetkileri_ApiLead_id = t.CallCenterApiLead_ID)),
            (SELECT TOP 1 b2.KullaniciBirim_Renk FROM ReklamLeadFormlari f
               INNER JOIN KullaniciBirimYetkileri kby2 ON kby2.KullaniciBirimYetkileri_ReklamSayfa_id = f.ReklamLeadFormlari_Sayfa_id AND kby2.Durum = 1
               INNER JOIN KullaniciBirim b2 ON b2.KullaniciBirim_id = kby2.KullaniciBirimYetkileri_Birim_id
               WHERE f.ReklamLeadFormlari_id = t.ReklamLeadFormlari_ID)
          ) AS BirimRenk
        FROM Basvurular t WHERE t.Basvurular_id = ?
    ", [$id]);
    $birimAdi  = $bilgi['BirimAdi'] ?? null;
    $birimRenk = (preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($bilgi['BirimRenk'] ?? ''))) ? $bilgi['BirimRenk'] : null;
}
// Kart ve başlık için inline stiller (renk yoksa boş = varsayılan görünüm korunur)
$kartStyle   = $birimRenk ? ' style="border:2px solid ' . $birimRenk . '"' : '';
$baslikStyle = $birimRenk ? ' style="background-color:' . $birimRenk . ';color:#fff;border-bottom:0"' : '';

// Değer yardımcıları
function val($basvuru, $key, $default = '') {
    return ($basvuru && isset($basvuru[$key]) && $basvuru[$key] !== null) ? $basvuru[$key] : $default;
}
$isEdit = $editMode;

$pageTitle = $editMode ? 'Başvuru Düzenle' : 'Yeni Başvuru';

$pageinfo = $db->fetchOne("
    SELECT m.menuler_menu_adi AS menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%basvuru-yonetimi.php']);
$menuAdi = $pageinfo['menu_adi'] ?? 'Başvurular';

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// =====================================================================
// DROPDOWN VERİLERİ
// =====================================================================
$kimlikKartiTurleri = $db->fetchAll("
    SELECT APIKimlikKartiTurleri_Id AS id, APIKimlikKartiTurleri_card_name AS ad, APIKimlikKartiTurleri_card_code AS kod
    FROM APIKimlikKartiTurleri WHERE Durum = 1 ORDER BY APIKimlikKartiTurleri_card_name
");
// Kampanyalar — şehir BBK doğrulamadan tespit edilir; başlangıçta sadece Tur=1 (+ düzenlemede kayıtlı kampanya)
$kampanyalar = kampanyaListesi(
    $db,
    0,
    $editMode ? (int)val($basvuru, 'Kampanyalar_ID') : 0
);
$basvuruDurumlari = $db->fetchAll("SELECT BasvuruDurum_id AS id, BasvuruDurum_Mesaj AS ad FROM BasvuruDurum ORDER BY BasvuruDurum_Mesaj");
$surecDurumlari   = $db->fetchAll("SELECT BasvuruSurecDurum_id AS id, BasvuruSurecDurum_Mesaj AS ad FROM BasvuruSurecDurum ORDER BY BasvuruSurecDurum_Mesaj");
// İletişim durumları — yalnız kullanıcının departmanının seçebildikleri (departman tanımı yoksa herkes, admin hepsi)
$iletisimDurumlari = iletisimDurumlariSecilebilir($db, (int)($user['departman_id'] ?? 0), $alanAdmin);
$igSonDurum = '';
if ($editMode && val($basvuru, 'Basvurular_IletisimDurum_ID') !== '') {
    $igSonDurum = (string)($db->fetchOne("SELECT BasvuruIletisimDurum_Mesaj AS ad FROM BasvuruIletisimDurum WHERE BasvuruIletisimDurum_id = ?",
        [(int)val($basvuru, 'Basvurular_IletisimDurum_ID')])['ad'] ?? '');
}

// Personel ve ApiLead — kısıtlı kullanıcı için KullaniciBirimYetkileri ile filtreli
$kbyTarih = "kby.Durum = 1
    AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
    AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())";

if ($birimKisitli && empty($izinliBirimler)) {
    $personeller    = [];
    $leadler        = [];
    $reklamFormlari = [];
} elseif ($birimKisitli) {
    $phBirim = implode(',', array_fill(0, count($izinliBirimler), '?'));
    $personeller = $db->fetchAll("
        SELECT p.DigiturkAltBayiPersonel_Id AS id,
               CONCAT(COALESCE(an.DigiturkAnaBayiler_Ad + N' — ', ''),
                      COALESCE(a.DigiturkAltBayiler_Ad + N'/', ''),
                      p.DigiturkAltBayiPersonel_AdSoyad) AS ad
        FROM DigiturkAltBayiPersonel p
        LEFT JOIN DigiturkAltBayiler a  ON a.DigiturkAltBayiler_Id = p.DigiturkAltBayiPersonel_AltBayiId
        LEFT JOIN DigiturkAnaBayiler an ON an.DigiturkAnaBayiler_Id = a.DigiturkAltBayiler_AnaBayiId
        WHERE p.Durum = 1 AND EXISTS (
            SELECT 1 FROM KullaniciBirimYetkileri kby
            WHERE kby.KullaniciBirimYetkileri_Birim_id IN ($phBirim)
              AND (kby.KullaniciBirimYetkileri_Personel_id = p.DigiturkAltBayiPersonel_Id
                OR kby.KullaniciBirimYetkileri_AltBayi_id  = p.DigiturkAltBayiPersonel_AltBayiId)
              AND $kbyTarih
        )
        ORDER BY ad
    ", $izinliBirimler);
    $leadler = $db->fetchAll("
        SELECT cl.CallCenterApiLead_id AS id, cl.CallCenterApiLead_ApiAdi AS ad
        FROM CallCenterApiLead cl
        WHERE cl.Durum = 1 AND EXISTS (
            SELECT 1 FROM KullaniciBirimYetkileri kby
            WHERE kby.KullaniciBirimYetkileri_ApiLead_id = cl.CallCenterApiLead_id
              AND kby.KullaniciBirimYetkileri_Birim_id IN ($phBirim)
              AND $kbyTarih
        )
        ORDER BY cl.CallCenterApiLead_ApiAdi
    ", $izinliBirimler);
    // Reklam Lead Formları — SİLSİLE: birimin ReklamSayfa (Facebook Sayfası) yetkisi olan sayfalara ait formlar
    $reklamFormlari = $db->fetchAll("
        SELECT f.ReklamLeadFormlari_id AS id,
               CONCAT(
                   COALESCE(NULLIF(LTRIM(RTRIM(f.ReklamLeadFormlari_FormAdi)), ''), CONCAT('Form #', f.ReklamLeadFormlari_id)),
                   CASE WHEN NULLIF(LTRIM(RTRIM(f.ReklamLeadFormlari_FormID)), '') IS NOT NULL
                        THEN CONCAT(' [', LTRIM(RTRIM(f.ReklamLeadFormlari_FormID)), ']') ELSE '' END
               ) AS ad
        FROM ReklamLeadFormlari f
        WHERE f.Durum = 1 AND EXISTS (
            SELECT 1 FROM KullaniciBirimYetkileri kby
            WHERE kby.KullaniciBirimYetkileri_ReklamSayfa_id = f.ReklamLeadFormlari_Sayfa_id
              AND kby.KullaniciBirimYetkileri_Birim_id IN ($phBirim)
              AND $kbyTarih
        )
        ORDER BY f.ReklamLeadFormlari_FormAdi
    ", $izinliBirimler);
} else {
    $personeller = $db->fetchAll("
        SELECT p.DigiturkAltBayiPersonel_Id AS id,
               CONCAT(COALESCE(an.DigiturkAnaBayiler_Ad + N' — ', ''),
                      COALESCE(a.DigiturkAltBayiler_Ad + N'/', ''),
                      p.DigiturkAltBayiPersonel_AdSoyad) AS ad
        FROM DigiturkAltBayiPersonel p
        LEFT JOIN DigiturkAltBayiler a  ON a.DigiturkAltBayiler_Id = p.DigiturkAltBayiPersonel_AltBayiId
        LEFT JOIN DigiturkAnaBayiler an ON an.DigiturkAnaBayiler_Id = a.DigiturkAltBayiler_AnaBayiId
        WHERE p.Durum = 1
        ORDER BY ad");
    $leadler     = $db->fetchAll("SELECT CallCenterApiLead_id AS id, CallCenterApiLead_ApiAdi AS ad FROM CallCenterApiLead WHERE Durum = 1 ORDER BY CallCenterApiLead_ApiAdi");
    $reklamFormlari = $db->fetchAll("
        SELECT ReklamLeadFormlari_id AS id,
               CONCAT(
                   COALESCE(NULLIF(LTRIM(RTRIM(ReklamLeadFormlari_FormAdi)), ''), CONCAT('Form #', ReklamLeadFormlari_id)),
                   CASE WHEN NULLIF(LTRIM(RTRIM(ReklamLeadFormlari_FormID)), '') IS NOT NULL
                        THEN CONCAT(' [', LTRIM(RTRIM(ReklamLeadFormlari_FormID)), ']') ELSE '' END
               ) AS ad
        FROM ReklamLeadFormlari WHERE Durum = 1 ORDER BY ReklamLeadFormlari_FormAdi
    ");
}

// Varsayılanlar (ekleme modu)
$vGender = $editMode ? val($basvuru, 'genderType') : 'BAY';
$vKimlik = $editMode ? val($basvuru, 'KimlikKartiTurleri_ID') : '1';
$vUlke   = $editMode ? val($basvuru, 'phoneCountryNumber') : '90';
$vBirth  = substr((string)val($basvuru, 'birthDate'), 0, 10);
// Digiturk sipariş alanları — varsayılan 0 (API'nin kendi varsayılanıyla aynı)
$vRouting  = (string)(val($basvuru, 'ticketRoutingType') ?: '0');
$vEngelli  = (string)val($basvuru, 'isHandicapped') === '1';
$vGazi     = (string)val($basvuru, 'isVeteran')     === '1';
// Alt Bayi Personeli — kayıtlı değilse otomatik çöz:
//  1) Başvurunun bağlı birim(ler)inden tek personel varsa (form/lead silsilesi)
//  2) Aksi halde (birime göre filtreli) listede tek personel varsa
$vAltBayi = (string)val($basvuru, 'AltBayiPersonel_ID');
if ($vAltBayi === '' && $editMode) {
    $bp = basvuruBirimPersoneli($db, $basvuru);
    if ($bp !== null) $vAltBayi = $bp;
}
if ($vAltBayi === '' && count($personeller) === 1) {
    $vAltBayi = (string)$personeller[0]['id'];
}
// Alan görünürlük/düzenleme: $alanGor / $alanRo / $alanIco (Menu_SayfaAlanYetkileri). Yeni kayıtta Alt Bayi Personeli herkese açık.
$altBayiKilit = !$alanDuzenle('AltBayiPersonel_ID');
// Süreç sorgusu (Endpoint 18) — işlem yetkisi: admin VEYA departman 1/21/22
$surecErisim = !empty($permissions['is_admin']) || in_array((int)($user['departman_id'] ?? 0), [1, 21, 22], true);
// Sayfa Yetki modalı — departman listesi (Admin her zaman tam yetkili olduğu için listelenmez)
$yetkiDepartmanlar = $alanAdmin
    ? $db->fetchAll("SELECT departman_id AS id, departman_adi AS ad FROM kullanici_Departmanlar WHERE departman_durum = 1 AND departman_id <> 1 ORDER BY departman_adi")
    : [];
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">
    <style>.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }</style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
<div class="app-wrapper">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="app-main">
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-sm-6 d-flex align-items-center gap-2">
                        <h3 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h3>
                        <?php if ($alanAdmin): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSayfaYetki" title="Departman bazlı alan yetkileri">
                            <i class="bi bi-shield-lock"></i> Sayfa Yetki
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-end">
                            <li class="breadcrumb-item"><?= htmlspecialchars($menuAdi) ?></li>
                            <li class="breadcrumb-item"><a href="/Admin/basvuru-yonetimi">Başvuru Yönetimi</a></li>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-content">
            <div class="container-fluid">
                <form id="basvuruForm">
                    <input type="hidden" name="id" value="<?= (int)$id ?>">

                    <!-- Başvuran Bilgileri -->
                    <div class="card card-primary card-outline mb-3"<?= $kartStyle ?>>
                        <div class="card-header d-flex justify-content-between align-items-center"<?= $baslikStyle ?>>
                            <h3 class="card-title mb-0"><i class="bi bi-person-vcard"></i> Başvuran Bilgileri</h3>
                            <?php if ($birimAdi): ?>
                            <span class="badge" style="background:rgba(255,255,255,.25);color:#fff;font-weight:600;">
                                <i class="bi bi-diagram-3"></i> <?= htmlspecialchars($birimAdi) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <?php if ($alanGor('Isim')): ?>
                                <div class="col-md-6 col-lg-3">
                                    <label class="form-label">İsim<?= $alanIco('Isim') ?></label>
                                    <input type="text" class="form-control" name="Isim" maxlength="100" value="<?= htmlspecialchars(val($basvuru, 'Isim')) ?>" <?= $alanRo('Isim') ?>>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('Soyisim')): ?>
                                <div class="col-md-6 col-lg-3">
                                    <label class="form-label">Soyisim<?= $alanIco('Soyisim') ?></label>
                                    <input type="text" class="form-control" name="Soyisim" maxlength="100" value="<?= htmlspecialchars(val($basvuru, 'Soyisim')) ?>" <?= $alanRo('Soyisim') ?>>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('TCKimlikNo')): ?>
                                <div class="col-md-6 col-lg-3">
                                    <label class="form-label">TC Kimlik No<?= $alanIco('TCKimlikNo') ?></label>
                                    <input type="text" class="form-control mono" name="TCKimlikNo" id="TCKimlikNo" maxlength="11" inputmode="numeric" placeholder="11 hane" value="<?= htmlspecialchars(val($basvuru, 'TCKimlikNo')) ?>" <?= $alanRo('TCKimlikNo') ?>>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('genderType')): ?>
                                <div class="col-md-6 col-lg-3">
                                    <label class="form-label">Cinsiyet<?= $alanIco('genderType') ?></label>
                                    <select class="form-select" name="genderType" <?= $alanRo('genderType') ?>>
                                        <option value="">Seçiniz</option>
                                        <option value="BAY"   <?= $vGender === 'BAY' ? 'selected' : '' ?>>BAY</option>
                                        <option value="BAYAN" <?= $vGender === 'BAYAN' ? 'selected' : '' ?>>BAYAN</option>
                                    </select>
                                </div>
                                <?php endif; ?>

                                <?php if ($alanGor('email')): ?>
                                <div class="col-md-6 col-lg-5">
                                    <label class="form-label">E-posta<?= $alanIco('email') ?></label>
                                    <input type="email" class="form-control" name="email" maxlength="255" value="<?= htmlspecialchars(val($basvuru, 'email')) ?>" <?= $alanRo('email') ?>>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('birthDate')): ?>
                                <div class="col-6 col-lg-2">
                                    <label class="form-label">Doğum Tarihi<?= $alanIco('birthDate') ?></label>
                                    <input type="date" class="form-control" name="birthDate" value="<?= htmlspecialchars($vBirth) ?>" <?= $alanRo('birthDate') ?>>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('phoneCountryNumber')): ?>
                                <div class="col-4 col-md-2 col-lg-1">
                                    <label class="form-label">Ülke<?= $alanIco('phoneCountryNumber') ?></label>
                                    <input type="text" class="form-control mono" name="phoneCountryNumber" id="phoneCountryNumber" maxlength="2" inputmode="numeric" placeholder="90" value="<?= htmlspecialchars($vUlke) ?>" <?= $alanRo('phoneCountryNumber') ?>>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('phoneAreaNumber')): ?>
                                <div class="col-4 col-md-2 col-lg-1">
                                    <label class="form-label">Alan<?= $alanIco('phoneAreaNumber') ?></label>
                                    <input type="text" class="form-control mono" name="phoneAreaNumber" id="phoneAreaNumber" maxlength="3" inputmode="numeric" placeholder="532" value="<?= htmlspecialchars(val($basvuru, 'phoneAreaNumber')) ?>" <?= $alanRo('phoneAreaNumber') ?>>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('phoneNumber')): ?>
                                <div class="col-4 col-md-3 col-lg-2">
                                    <label class="form-label">Telefon No<?= $alanIco('phoneNumber') ?> <span id="mukerrerBadge" class="badge bg-danger d-none"></span></label>
                                    <input type="text" class="form-control mono" name="phoneNumber" id="phoneNumber" maxlength="10" inputmode="numeric" placeholder="5550000000" value="<?= htmlspecialchars(val($basvuru, 'phoneNumber')) ?>" <?= $alanRo('phoneNumber') ?>>
                                </div>
                                <?php endif; ?>

                                <?php if ($alanGor('KimlikKartiTurleri_ID')): ?>
                                <div class="col-md-6">
                                    <label class="form-label">Kimlik Kartı Türü<?= $alanIco('KimlikKartiTurleri_ID') ?></label>
                                    <select class="form-select" name="KimlikKartiTurleri_ID" <?= $alanRo('KimlikKartiTurleri_ID') ?>>
                                        <option value="">Seçiniz</option>
                                        <?php foreach ($kimlikKartiTurleri as $k): ?>
                                        <option value="<?= (int)$k['id'] ?>" <?= (string)$vKimlik === (string)$k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['ad']) ?> (<?= htmlspecialchars($k['kod']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('ticketRoutingType')): ?>
                                <div class="col-md-6 col-lg-3">
                                    <label class="form-label">Talep Yönlendirme<?= $alanIco('ticketRoutingType') ?></label>
                                    <select class="form-select" name="ticketRoutingType" <?= $alanRo('ticketRoutingType') ?>>
                                        <option value="0" <?= $vRouting !== '1' ? 'selected' : '' ?>>Adrese bakan bayiye</option>
                                        <option value="1" <?= $vRouting === '1' ? 'selected' : '' ?>>Bana yönlendir</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('isHandicapped') || $alanGor('isVeteran')): ?>
                                <div class="col-md-6 col-lg-3">
                                    <label class="form-label">İndirim Beyanı <small class="text-muted">(belge gerekebilir)</small></label>
                                    <div class="d-flex gap-3 pt-1">
                                        <?php if ($alanGor('isHandicapped')): ?>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="isHandicapped" name="isHandicapped" value="1" <?= $vEngelli ? 'checked' : '' ?> <?= $alanRo('isHandicapped') ?>>
                                            <label class="form-check-label" for="isHandicapped">Engelli<?= $alanIco('isHandicapped') ?></label>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($alanGor('isVeteran')): ?>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="isVeteran" name="isVeteran" value="1" <?= $vGazi ? 'checked' : '' ?> <?= $alanRo('isVeteran') ?>>
                                            <label class="form-check-label" for="isVeteran">Şehit/Gazi<?= $alanIco('isVeteran') ?></label>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($alanGor('bbkAddressCode')): ?>
                                <div class="col-md-6">
                                    <label class="form-label">BBK Adres Kodu<?= $alanIco('bbkAddressCode') ?> <small class="text-muted">(doğrulayınca şehir/kampanya belirlenir)</small></label>
                                    <div class="input-group">
                                        <input type="text" class="form-control mono" name="bbkAddressCode" id="bbkAddressCode" maxlength="16" value="<?= htmlspecialchars(val($basvuru, 'bbkAddressCode')) ?>" <?= $alanRo('bbkAddressCode') ?>>
                                        <button type="button" class="btn btn-outline-success" id="btnBbkDogrula" title="BBK kodunu doğrula ve şehri belirle" <?= $alanRo('bbkAddressCode') ?>><i class="bi bi-check2-circle"></i></button>
                                        <button type="button" class="btn btn-outline-primary" id="btnBbkModal" title="Adres seçerek üret" <?= $alanRo('bbkAddressCode') ?>><i class="bi bi-geo-alt"></i></button>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('Kampanyalar_ID')): ?>
                                <div class="col-12">
                                    <label class="form-label">Kampanya<?= $alanIco('Kampanyalar_ID') ?> <small class="text-muted">(şehre göre filtrelenir)</small></label>
                                    <select class="form-select" name="Kampanyalar_ID" id="Kampanyalar_ID" data-kilitli="<?= $alanDuzenle('Kampanyalar_ID') ? '0' : '1' ?>" <?= $alanRo('Kampanyalar_ID') ?>>
                                        <option value="">Seçiniz</option>
                                        <?php foreach ($kampanyalar as $k): ?>
                                        <option value="<?= (int)$k['id'] ?>" <?= (string)val($basvuru, 'Kampanyalar_ID') === (string)$k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>

                                <?php if ($editMode): ?>
                                <?php if ($alanGor('Basvuru_Aciklama')): ?>
                                <div class="col-12">
                                    <label class="form-label">Açıklama <small class="text-muted">(ilk gelen bilgi — değiştirilemez, notlar İletişim Geçmişi'ne eklenir)</small></label>
                                    <textarea class="form-control bg-light" rows="2" readonly><?= htmlspecialchars(val($basvuru, 'Basvuru_Aciklama')) ?></textarea>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('Basvurular_IletisimDurum_ID') || $alanGor('Basvuru_Aciklama')):
                                    $igDurumYetki = $alanGor('Basvurular_IletisimDurum_ID') && $alanDuzenle('Basvurular_IletisimDurum_ID');
                                    $igNotYetki   = $alanGor('Basvuru_Aciklama') && $alanDuzenle('Basvuru_Aciklama');
                                ?>
                                <div class="col-12">
                                    <label class="form-label mb-1"><i class="bi bi-clock-history"></i> İletişim Geçmişi</label>
                                    <?php if ($igDurumYetki || $igNotYetki): ?>
                                    <div class="row g-2 align-items-start mb-2">
                                        <?php if ($igDurumYetki): ?>
                                        <div class="col-md-3">
                                            <!-- name yok: ana form kaydına gitmez, yalnız iletisim_ekle ile gönderilir -->
                                            <select class="form-select" id="igDurum">
                                                <option value="">— Durum değişmedi —</option>
                                                <?php foreach ($iletisimDurumlari as $idr): ?>
                                                <option value="<?= (int)$idr['id'] ?>" data-tarih="<?= (int)$idr['tarih'] ?>"><?= htmlspecialchars($idr['ad']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3 d-none" id="igRandevuKutu">
                                            <input type="datetime-local" class="form-control" id="igRandevu" title="Randevu tarihi ve saati">
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($igNotYetki): ?>
                                        <div class="col" id="igNotKutu">
                                            <textarea class="form-control" id="igNot" rows="1" maxlength="1000" placeholder="Not (ör. arandı, akşam tekrar aranacak)"></textarea>
                                        </div>
                                        <?php endif; ?>
                                        <div class="col-md-2 d-grid">
                                            <button type="button" class="btn btn-outline-primary" id="btnIletisimEkle"><i class="bi bi-plus-lg"></i> Ekle</button>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($alanGor('Basvurular_IletisimDurum_ID')): ?>
                                    <div class="small mb-1">Son durum: <span class="badge bg-secondary" id="igSonDurum"><?= $igSonDurum !== '' ? htmlspecialchars($igSonDurum) : '—' ?></span></div>
                                    <?php endif; ?>
                                    <div id="iletisimGecmisi" class="border rounded px-2" style="max-height:320px;overflow-y:auto">
                                        <?= iletisimGecmisiHtml(iletisimGecmisiGetir($db, $id, (int)($user['departman_id'] ?? 0), $alanAdmin, (int)$user['kullanici_id'])) ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php else: ?>
                                <?php if ($alanGor('Basvuru_Aciklama')): ?>
                                <div class="col-md-9">
                                    <label class="form-label">Açıklama<?= $alanIco('Basvuru_Aciklama') ?></label>
                                    <textarea class="form-control" name="Basvuru_Aciklama" rows="2" maxlength="500" <?= $alanRo('Basvuru_Aciklama') ?>><?= htmlspecialchars(val($basvuru, 'Basvuru_Aciklama')) ?></textarea>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('Basvurular_IletisimDurum_ID')): ?>
                                <div class="col-md-3">
                                    <label class="form-label">İletişim Durumu<?= $alanIco('Basvurular_IletisimDurum_ID') ?></label>
                                    <select class="form-select" name="Basvurular_IletisimDurum_ID" id="yeniIletisimDurum" <?= $alanRo('Basvurular_IletisimDurum_ID') ?>>
                                        <option value="">Seçiniz</option>
                                        <?php foreach ($iletisimDurumlari as $idr): ?>
                                        <option value="<?= (int)$idr['id'] ?>" data-tarih="<?= (int)$idr['tarih'] ?>"><?= htmlspecialchars($idr['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 offset-md-9 d-none" id="yeniRandevuKutu">
                                    <label class="form-label">Randevu Tarihi <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control" name="IletisimRandevuTarihi" id="yeniRandevu">
                                </div>
                                <?php endif; ?>
                                <?php endif; /* editMode */ ?>
                            </div>
                        </div>
                    </div>

                    <!-- Durum & İlişkilendirme -->
                    <div class="card card-secondary card-outline mb-3"<?= $kartStyle ?>>
                        <div class="card-header d-flex justify-content-between align-items-center"<?= $baslikStyle ?>>
                            <h3 class="card-title mb-0"><i class="bi bi-diagram-3"></i> Durum & İlişkilendirme</h3>
                            <?php if ($kayitKilitli && !$alanAdmin): ?>
                            <span class="badge bg-success"><i class="bi bi-lock-fill"></i> Başvuru tamamlandı — bazı alanlar kilitli</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <?php if ($alanGor('BasvuruDurum_ID')): ?>
                                <div class="col-md-4">
                                    <label class="form-label">Başvuru Durumu<?= $alanIco('BasvuruDurum_ID') ?></label>
                                    <select class="form-select" name="BasvuruDurum_ID" <?= $alanRo('BasvuruDurum_ID') ?>>
                                        <option value="">Seçiniz</option>
                                        <?php foreach ($basvuruDurumlari as $d): ?>
                                        <option value="<?= (int)$d['id'] ?>" <?= (string)val($basvuru, 'BasvuruDurum_ID') === (string)$d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('BasvuruSurecDurum_ID')): ?>
                                <div class="col-md-4">
                                    <label class="form-label">Süreç Durumu<?= $alanIco('BasvuruSurecDurum_ID') ?></label>
                                    <select class="form-select" name="BasvuruSurecDurum_ID" <?= $alanRo('BasvuruSurecDurum_ID') ?>>
                                        <option value="">Seçiniz</option>
                                        <?php foreach ($surecDurumlari as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>" <?= (string)val($basvuru, 'BasvuruSurecDurum_ID') === (string)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('BasvuruDurumMesaj')): ?>
                                <div class="col-md-4">
                                    <label class="form-label">Durum Mesajı<?= $alanIco('BasvuruDurumMesaj') ?></label>
                                    <input type="text" class="form-control" name="BasvuruDurumMesaj" maxlength="500" value="<?= htmlspecialchars(val($basvuru, 'BasvuruDurumMesaj')) ?>" <?= $alanRo('BasvuruDurumMesaj') ?>>
                                </div>
                                <?php endif; ?>

                                <?php if ($alanGor('MusteriNo')): ?>
                                <div class="col-md-4">
                                    <label class="form-label">Müşteri No<?= $alanIco('MusteriNo') ?></label>
                                    <input type="text" class="form-control mono" name="MusteriNo" id="MusteriNo" inputmode="numeric" value="<?= htmlspecialchars(val($basvuru, 'MusteriNo')) ?>" <?= $alanRo('MusteriNo') ?>>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('TalepKayitNo')): ?>
                                <div class="col-md-4">
                                    <label class="form-label">Talep Kayıt No<?= $alanIco('TalepKayitNo') ?></label>
                                    <input type="text" class="form-control mono" name="TalepKayitNo" id="TalepKayitNo" inputmode="numeric" value="<?= htmlspecialchars(val($basvuru, 'TalepKayitNo')) ?>" <?= $alanRo('TalepKayitNo') ?>>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('MemoID')): ?>
                                <div class="col-md-4">
                                    <label class="form-label">Memo ID<?= $alanIco('MemoID') ?></label>
                                    <input type="text" class="form-control mono" name="MemoID" id="MemoID" inputmode="numeric" value="<?= htmlspecialchars(val($basvuru, 'MemoID')) ?>" <?= $alanRo('MemoID') ?>>
                                </div>
                                <?php endif; ?>
                                <?php if ($editMode && $surecErisim): ?>
                                <div class="col-12">
                                    <button type="button" class="btn btn-outline-info" id="btnSurecKontrol">
                                        <i class="bi bi-arrow-repeat"></i> Kontrol Et (Süreç Sorgusu)
                                    </button>
                                    <small class="text-muted ms-2">Talep Kayıt No ile Endpoint 18 sorgulanır; süreç durumu ve başvuru durumu güncellenir.</small>
                                </div>
                                <?php endif; ?>

                                <?php if ($alanGor('AltBayiPersonel_ID')): ?>
                                <div class="col-md-4">
                                    <label class="form-label">Alt Bayi Personeli<?= (!$editMode && !$altBayiKilit) ? ' <span class="text-danger">*</span>' : '' ?><?= $alanIco('AltBayiPersonel_ID') ?></label>
                                    <select class="form-select" name="AltBayiPersonel_ID" <?= $alanRo('AltBayiPersonel_ID') ?><?= (!$editMode && !$altBayiKilit) ? ' required' : '' ?>>
                                        <option value="">Seçiniz</option>
                                        <?php foreach ($personeller as $p): ?>
                                        <option value="<?= (int)$p['id'] ?>" <?= $vAltBayi === (string)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('ReklamLeadFormlari_ID')): ?>
                                <div class="col-md-4">
                                    <label class="form-label">Reklam Lead Formu<?= $alanIco('ReklamLeadFormlari_ID') ?></label>
                                    <select class="form-select" name="ReklamLeadFormlari_ID" <?= $alanRo('ReklamLeadFormlari_ID') ?>>
                                        <option value="">Seçiniz</option>
                                        <?php foreach ($reklamFormlari as $rf): ?>
                                        <option value="<?= (int)$rf['id'] ?>" <?= (string)val($basvuru, 'ReklamLeadFormlari_ID') === (string)$rf['id'] ? 'selected' : '' ?>><?= htmlspecialchars($rf['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <?php if ($alanGor('CallCenterApiLead_ID')): ?>
                                <div class="col-md-4">
                                    <label class="form-label">Çağrı Merkezi API Lead<?= $alanIco('CallCenterApiLead_ID') ?></label>
                                    <select class="form-select" name="CallCenterApiLead_ID" <?= $alanRo('CallCenterApiLead_ID') ?>>
                                        <option value="">Seçiniz</option>
                                        <?php foreach ($leadler as $l): ?>
                                        <option value="<?= (int)$l['id'] ?>" <?= (string)val($basvuru, 'CallCenterApiLead_ID') === (string)$l['id'] ? 'selected' : '' ?>><?= htmlspecialchars($l['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button>
                        <a href="/Admin/basvuru-yonetimi" class="btn btn-secondary"><i class="bi bi-x-circle"></i> İptal</a>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<?php if ($editMode): ?>
<!-- İletişim geçmişi kaydı düzenleme -->
<div class="modal fade" id="igDuzenleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square"></i> İletişim Kaydını Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="igdId">
                <div class="mb-3" <?= $alanDuzenle('Basvurular_IletisimDurum_ID') ? '' : 'hidden' ?>>
                    <label class="form-label">İletişim Durumu</label>
                    <select class="form-select" id="igdDurum">
                        <option value="">— Durum yok —</option>
                        <?php foreach ($iletisimDurumlari as $idr): ?>
                        <option value="<?= (int)$idr['id'] ?>" data-tarih="<?= (int)$idr['tarih'] ?>"><?= htmlspecialchars($idr['ad']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3 d-none" id="igdRandevuKutu">
                    <label class="form-label">Randevu Tarihi <span class="text-danger">*</span></label>
                    <input type="datetime-local" class="form-control" id="igdRandevu">
                </div>
                <div <?= $alanDuzenle('Basvuru_Aciklama') ? '' : 'hidden' ?>>
                    <label class="form-label">Not</label>
                    <textarea class="form-control" id="igdNot" rows="3" maxlength="1000"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
                <button type="button" class="btn btn-primary" id="btnIgdKaydet"><i class="bi bi-save"></i> Kaydet</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($alanAdmin): ?>
<!-- Sayfa Yetki Modal — departman bazlı alan görünürlük / düzenleme -->
<div class="modal fade" id="sayfaYetkiModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-shield-lock"></i> Sayfa Yetki — Alan Bazlı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 align-items-end mb-3">
                    <div class="col-md-5">
                        <label class="form-label">Departman</label>
                        <select class="form-select" id="syDepartman">
                            <option value="">Seçiniz</option>
                            <?php foreach ($yetkiDepartmanlar as $d): ?>
                            <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['ad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <small class="text-muted">
                            Admin her zaman tüm alanları görür ve düzenler.
                            <b>Tamamlandıysa Düzenler</b> kapalıysa başvuru durumu tamamlanan (TAMAM) kayıtta alan kilitlenir.
                        </small>
                    </div>
                </div>
                <div id="syAlanlar"><p class="text-muted text-center my-4">Departman seçiniz.</p></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                <button type="button" class="btn btn-primary" id="btnSyKaydet" disabled><i class="bi bi-save"></i> Kaydet</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- BBK Adres Modal -->
<div class="modal fade" id="bbkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-geo-alt"></i> BBK Adres Kodu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Şehirden başlayarak aşağı doğru seçim yapın; kapı/daire seçilince BBK adres kodu oluşur.</p>
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label">Şehir</label><select class="form-select" id="addr_city"><option value="">Seçiniz</option></select></div>
                    <div class="col-md-6"><label class="form-label">İlçe</label><select class="form-select" id="addr_county" disabled><option value="">Seçiniz</option></select></div>
                    <div class="col-md-6"><label class="form-label">Bucak</label><select class="form-select" id="addr_burg" disabled><option value="">Seçiniz</option></select></div>
                    <div class="col-md-6"><label class="form-label">Köy</label><select class="form-select" id="addr_village" disabled><option value="">Seçiniz</option></select></div>
                    <div class="col-md-6"><label class="form-label">Mahalle</label><select class="form-select" id="addr_quarter" disabled><option value="">Seçiniz</option></select></div>
                    <div class="col-md-6"><label class="form-label">Sokak</label><select class="form-select" id="addr_street" disabled><option value="">Seçiniz</option></select></div>
                    <div class="col-md-6"><label class="form-label">Bina</label><select class="form-select" id="addr_building" disabled><option value="">Seçiniz</option></select></div>
                    <div class="col-md-6"><label class="form-label">Kapı / Daire</label><select class="form-select" id="addr_door" disabled><option value="">Seçiniz</option></select></div>
                </div>
                <div class="alert alert-info mt-3 d-none" id="bbkSonuc"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                <button type="button" class="btn btn-success" id="btnBbkKullan" disabled><i class="bi bi-check-lg"></i> Bu Kodu Kullan</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script src="/Admin/assets/js/custom.js"></script>

<script>
    $(document).ready(function () {
        // Select'leri custom.js (.form-select) otomatik Select2 yapıyor — sayfa formu olduğu için modal sorunu yok.

        // Sadece rakam + hane sınırı
        [['phoneCountryNumber', 2], ['phoneAreaNumber', 3], ['phoneNumber', 10], ['TCKimlikNo', 11]].forEach(function (item) {
            const el = document.getElementById(item[0]);
            if (el) el.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, item[1]);
            });
        });

        // Telefon mükerrer kontrolü — aynı ülke+alan+numara başka başvuruda var mı
        const kayitId = <?= (int)$id ?>;
        let mukerrerTimer = null;
        function mukerrerKontrol() {
            const no = ($('#phoneNumber').val() || '').replace(/\D/g, '');
            const $b = $('#mukerrerBadge');
            if (!no) { $b.addClass('d-none').text(''); return; }
            $.ajax({
                url: '', method: 'POST', dataType: 'json',
                data: {
                    action: 'mukerrer_kontrol',
                    phoneCountryNumber: ($('#phoneCountryNumber').val() || '').replace(/\D/g, ''),
                    phoneAreaNumber: ($('#phoneAreaNumber').val() || '').replace(/\D/g, ''),
                    phoneNumber: no,
                    id: kayitId
                }
            }).done(function (r) {
                if (!r || !r.success) { $b.addClass('d-none').text(''); return; }

                // Kara liste uyarısı mükerrer uyarısının önüne geçer — kayıt zaten reddedilecek
                if (r.karaListe) {
                    const not = r.karaListe.aciklama ? ' — ' + r.karaListe.aciklama : '';
                    $b.removeClass('d-none bg-danger').addClass('bg-dark')
                      .html('<i class="bi bi-shield-slash-fill"></i> KARA LİSTE' + not);
                } else if (r.adet > 0) {
                    $b.removeClass('d-none bg-dark').addClass('bg-danger')
                      .html('<i class="bi bi-exclamation-triangle-fill"></i> ' + r.adet + ' mükerrer');
                } else {
                    $b.addClass('d-none').text('');
                }
            });
        }
        ['phoneCountryNumber', 'phoneAreaNumber', 'phoneNumber'].forEach(function (idv) {
            const el = document.getElementById(idv);
            if (el) el.addEventListener('input', function () {
                clearTimeout(mukerrerTimer);
                mukerrerTimer = setTimeout(mukerrerKontrol, 400);
            });
        });
        mukerrerKontrol(); // açılışta kontrol et (düzenleme modunda mevcut numara)

        // Süreç alanları — boşluksuz (sadece rakam)
        ['MusteriNo', 'TalepKayitNo', 'MemoID'].forEach(function (idv) {
            const el = document.getElementById(idv);
            if (el) el.addEventListener('input', function () { this.value = this.value.replace(/\D/g, ''); });
        });

        // Bir select'i değere ayarla; seçenek yoksa ekle (kilitli/Select2 select'lerde de görünür)
        function setSelectValue(name, id, ad) {
            if (id === null || id === undefined) return;
            const $s = $('select[name="' + name + '"]');
            if (!$s.length) return;
            if (!$s.find('option[value="' + id + '"]').length) $s.append(new Option(ad || ('#' + id), id, false, false));
            $s.val(String(id)).trigger('change');
        }

        // Kontrol Et — Endpoint 18 süreç sorgusu (gövde [TalepKayitNo])
        $('#btnSurecKontrol').on('click', function () {
            const talep = ($('#TalepKayitNo').val() || '').replace(/\D/g, '');
            if (!talep) { showToast('Önce Talep Kayıt No girin', 'warning'); return; }
            const $b = $(this); const eski = $b.html();
            $b.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Sorgulanıyor...');
            $.post('', {
                action:       'surec_kontrol',
                id:           <?= (int)$id ?>,
                MusteriNo:    ($('#MusteriNo').val() || '').replace(/\D/g, ''),
                TalepKayitNo: talep,
                MemoID:       ($('#MemoID').val() || '').replace(/\D/g, '')
            }, function (r) {
                if (!r.success) { showError('Sorgu Başarısız', r.message || 'Bilinmeyen hata'); return; }
                setSelectValue('BasvuruSurecDurum_ID', r.surecDurumId, r.surecDurumAd);
                setSelectValue('BasvuruDurum_ID', r.basvuruDurumId, r.basvuruDurumAd);
                showSuccess('Güncellendi ✓', r.message);
            }, 'json').fail(function () { showError('Bağlantı Hatası', 'Süreç sorgusu yapılamadı'); })
              .always(function () { $b.prop('disabled', false).html(eski); });
        });

        // İletişim Geçmişi — kayıt ekle (ana form kaydından bağımsız)
        // Durum tanımında "tarih/saat seçilir" işaretliyse (ör. Randevu) tarih alanı açılır ve zorunlu olur
        function tarihGerekli($sel) { return String($sel.find('option:selected').data('tarih')) === '1'; }
        $('#igDurum').on('change', function () {
            const gerekli = tarihGerekli($(this));
            $('#igRandevuKutu').toggleClass('d-none', !gerekli);
            if (!gerekli) $('#igRandevu').val('');
        });
        $('#yeniIletisimDurum').on('change', function () {
            const gerekli = tarihGerekli($(this));
            $('#yeniRandevuKutu').toggleClass('d-none', !gerekli);
            $('#yeniRandevu').prop('required', gerekli);
            if (!gerekli) $('#yeniRandevu').val('');
        });

        $('#btnIletisimEkle').on('click', function () {
            const durumId = $('#igDurum').val() || '';
            const notu    = ($('#igNot').val() || '').trim();
            const randevu = $('#igRandevu').val() || '';
            if (!durumId && !notu) { showToast('İletişim durumu seçin veya not yazın', 'warning'); return; }
            if (durumId && tarihGerekli($('#igDurum')) && !randevu) { showToast('Bu durum için tarih ve saat seçin', 'warning'); $('#igRandevu').trigger('focus'); return; }
            const $b = $(this); const eski = $b.html();
            $b.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
            $.post('', { action: 'iletisim_ekle', id: <?= (int)$id ?>, durum_id: durumId, not: notu, randevu: randevu }, function (r) {
                if (!r.success) { showError('Hata!', r.message || 'Bilinmeyen hata'); return; }
                $('#iletisimGecmisi').html(r.html).scrollTop(0);
                if (durumId) $('#igSonDurum').text($('#igDurum option:selected').text());
                $('#igDurum').val('').trigger('change');
                $('#igNot').val('');
                showToast(r.message, 'success');
            }, 'json').fail(function () { showError('Bağlantı Hatası', 'İletişim kaydı eklenemedi'); })
              .always(function () { $b.prop('disabled', false).html(eski); });
        });

        // İletişim geçmişi kaydını düzenle (kalem ikonu yalnız yetkili kullanıcıya gelir; sunucu da kontrol eder)
        const $igdModal = $('#igDuzenleModal');
        if ($igdModal.length) {
            const igdModal = new bootstrap.Modal($igdModal[0]);
            // Modal içi select: custom.js init'i dropdownParent veremez → destroy + yeniden kur
            if ($('#igdDurum').hasClass('select2-hidden-accessible')) $('#igdDurum').select2('destroy');
            $('#igdDurum').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $igdModal });

            $('#igdDurum').on('change', function () {
                const gerekli = tarihGerekli($(this));
                $('#igdRandevuKutu').toggleClass('d-none', !gerekli);
                if (!gerekli) $('#igdRandevu').val('');
            });

            $(document).on('click', '.ig-duzenle', function () {
                const $b = $(this);
                const durum = String($b.data('durum') || '');
                // Kaydın durumu kullanıcının seçebildikleri arasında yoksa (yalnız düzenleme yetkisi) seçenek olarak eklenir
                $('#igdDurum option[data-ek="1"]').remove();
                if (durum && durum !== '0' && !$('#igdDurum option[value="' + durum + '"]').length) {
                    $('#igdDurum').append($('<option>', { value: durum, text: $b.data('durumAd'), 'data-tarih': $b.data('durumTarih'), 'data-ek': '1' }));
                }
                $('#igdId').val($b.data('id'));
                $('#igdDurum').val(durum !== '0' ? durum : '').trigger('change');
                $('#igdRandevu').val($b.data('randevu') || '');
                $('#igdNot').val($b.attr('data-not') || '');
                igdModal.show();
            });

            $('#btnIgdKaydet').on('click', function () {
                const durumId = $('#igdDurum').val() || '';
                const notu    = ($('#igdNot').val() || '').trim();
                const randevu = $('#igdRandevu').val() || '';
                if (!durumId && !notu) { showToast('İletişim durumu seçin veya not yazın', 'warning'); return; }
                if (durumId && tarihGerekli($('#igdDurum')) && !randevu) { showToast('Bu durum için tarih ve saat seçin', 'warning'); return; }
                const $b = $(this); const eski = $b.html();
                $b.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
                $.post('', { action: 'iletisim_duzenle', id: <?= (int)$id ?>, gecmis_id: $('#igdId').val(),
                             durum_id: durumId, not: notu, randevu: randevu }, function (r) {
                    if (!r.success) { showError('Hata!', r.message || 'Bilinmeyen hata'); return; }
                    $('#iletisimGecmisi').html(r.html);
                    $('#igSonDurum').text(r.sonDurum || '—');
                    igdModal.hide();
                    showToast(r.message, 'success');
                }, 'json').fail(function () { showError('Bağlantı Hatası', 'İletişim kaydı güncellenemedi'); })
                  .always(function () { $b.prop('disabled', false).html(eski); });
            });
        }

        $('#basvuruForm').on('submit', function (e) {
            e.preventDefault();
            // Alt Bayi Personeli zorunlu (Select2 gizli required'ı tarayıcı validasyonuyla çalışmaz)
            const $per = $('select[name=AltBayiPersonel_ID]');
            if ($per.prop('required') && !$per.val()) {
                showError('Eksik Alan', 'Alt Bayi Personeli seçimi zorunludur.');
                try { $per.select2('open'); } catch (err) {}
                return;
            }
            const formData = new FormData(this);
            formData.append('action', 'kaydet');
            const $btn = $(this).find('button[type=submit]');
            const eski = $btn.html();
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Kaydediliyor...');

            $.ajax({
                url: '', method: 'POST',
                data: formData, processData: false, contentType: false, dataType: 'json',
                success: function (r) {
                    if (r.success) {
                        showSuccess('Başarılı!', r.message);
                        setTimeout(() => { window.location.href = r.redirect || '/Admin/basvuru-yonetimi'; }, 1000);
                    } else {
                        showError('Hata!', r.message);
                        $btn.prop('disabled', false).html(eski);
                    }
                },
                error: function () {
                    showError('Bağlantı Hatası!', 'Kayıt sırasında sunucuya ulaşılamadı.');
                    $btn.prop('disabled', false).html(eski);
                }
            });
        });

        // ===== Kampanya yükleme (şehir BBK doğrulamadan tespit edilir) =====
        function rebuildKampanya(html, disabled) {
            const $k = $('#Kampanyalar_ID');
            if ($k.hasClass('select2-hidden-accessible')) $k.select2('destroy');
            // Alan yetkisi kilitliyse (data-kilitli=1) yeniden yüklemede de kilitli kalır
            $k.html(html).prop('disabled', !!disabled || $k.data('kilitli') == 1).select2({ theme: 'bootstrap-5', width: '100%' });
        }
        function kampanyalariYukle(sehirId) {
            rebuildKampanya('<option value="">Yükleniyor...</option>', true);
            $.post('', { action: 'kampanyalar', sehir_id: sehirId || 0 }, function (r) {
                let html = '<option value="">Seçiniz</option>';
                if (r.success && Array.isArray(r.data)) {
                    r.data.forEach(function (k) { html += '<option value="' + k.id + '">' + escapeHtml(k.ad) + '</option>'; });
                }
                rebuildKampanya(html, false);
            }, 'json').fail(function () {
                rebuildKampanya('<option value="">Seçiniz</option>', false);
                showError('Hata', 'Kampanyalar yüklenemedi');
            });
        }

        // Edit modu: kayıtlı kampanya Select2 init sonrası görünür olsun
        <?php if ($editMode && val($basvuru, 'Kampanyalar_ID')): ?>
        $('#Kampanyalar_ID').val('<?= (int)val($basvuru, 'Kampanyalar_ID') ?>').trigger('change');
        <?php endif; ?>

        // ===== Reklam Lead Formu → Alt Bayi Personeli otomatik seçim =====
        // Form seçili + personel boşsa: forma bağlı tek personel varsa onu seçer.
        function formPersonelDoldur() {
            const formId = $('select[name=ReklamLeadFormlari_ID]').val();
            const $per   = $('select[name=AltBayiPersonel_ID]');
            if (!formId || $per.val()) return; // form yok veya personel zaten seçili
            $.post('', { action: 'form_personel', form_id: formId }, function (r) {
                if (r.success && Array.isArray(r.data) && r.data.length === 1) {
                    $per.val(String(r.data[0].id)).trigger('change');
                    showToast('Alt bayi personeli forma göre otomatik seçildi', 'info');
                }
            }, 'json');
        }
        $('select[name=ReklamLeadFormlari_ID]').on('change', formPersonelDoldur);
        formPersonelDoldur(); // sayfa açılışında (form seçili + personel boşsa)

        <?php if ($alanAdmin): ?>
        // ===== Sayfa Yetki Modal (alan bazlı departman yetkisi) =====
        const syModalEl = document.getElementById('sayfaYetkiModal');
        const syModal   = new bootstrap.Modal(syModalEl);
        if ($('#syDepartman').hasClass('select2-hidden-accessible')) $('#syDepartman').select2('destroy');
        $('#syDepartman').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#sayfaYetkiModal') });

        $('#btnSayfaYetki').on('click', function () { syModal.show(); });

        function sySwitch(alanId, tur, checked, disabled) {
            const idv = 'sy_' + tur + '_' + alanId;
            return '<div class="form-check form-switch d-flex justify-content-center">'
                 + '<input class="form-check-input sy-' + tur + '" type="checkbox" role="switch" id="' + idv + '"'
                 + ' data-alan="' + alanId + '"' + (checked ? ' checked' : '') + (disabled ? ' disabled' : '') + '>'
                 + '<label class="form-check-label" for="' + idv + '"></label></div>';
        }

        // Görür kapalıysa Düzenler, Düzenler kapalıysa Tamamlandıysa Düzenler pasif
        function syBagimlilik($satir) {
            const gor = $satir.find('.sy-gor').prop('checked');
            const $d  = $satir.find('.sy-duzenle').prop('disabled', !gor);
            if (!gor) $d.prop('checked', false);
            const duz = $d.prop('checked');
            const $k  = $satir.find('.sy-kilitli').prop('disabled', !duz);
            if (!duz) $k.prop('checked', false);
        }

        function syYukle() {
            const dep = $('#syDepartman').val();
            $('#btnSyKaydet').prop('disabled', true);
            if (!dep) { $('#syAlanlar').html('<p class="text-muted text-center my-4">Departman seçiniz.</p>'); return; }
            $('#syAlanlar').html('<p class="text-center my-4"><span class="spinner-border spinner-border-sm"></span> Yükleniyor...</p>');
            $.post('', { action: 'alan_yetki_getir', departman_id: dep }, function (r) {
                if (!r.success) { showError('Hata', r.message || 'Yetkiler alınamadı'); return; }
                const bolumler = {};
                r.data.forEach(function (a) { (bolumler[a.bolum] = bolumler[a.bolum] || []).push(a); });
                let html = '';
                Object.keys(bolumler).forEach(function (b) {
                    html += '<div class="card mb-3"><div class="card-header py-2"><b>' + escapeHtml(b) + '</b></div>'
                          + '<div class="card-body p-0"><table class="table table-sm table-hover align-middle mb-0">'
                          + '<thead><tr><th>Alan</th><th class="text-center" style="width:120px">Görür</th>'
                          + '<th class="text-center" style="width:120px">Düzenler</th>'
                          + '<th class="text-center" style="width:190px">Tamamlandıysa Düzenler</th></tr></thead><tbody>';
                    bolumler[b].forEach(function (a) {
                        const gor = a.gor == 1, duz = gor && a.duzenle == 1, kil = duz && a.kilitli_duzenle == 1;
                        html += '<tr data-alan="' + a.id + '"><td>' + escapeHtml(a.etiket) + ' <small class="text-muted mono">' + escapeHtml(a.kod) + '</small></td>'
                              + '<td>' + sySwitch(a.id, 'gor', gor, false) + '</td>'
                              + '<td>' + sySwitch(a.id, 'duzenle', duz, !gor) + '</td>'
                              + '<td>' + sySwitch(a.id, 'kilitli', kil, !duz) + '</td></tr>';
                    });
                    html += '</tbody></table></div></div>';
                });
                $('#syAlanlar').html(html || '<p class="text-muted text-center my-4">Bu sayfa için alan tanımı yok.</p>');
                $('#btnSyKaydet').prop('disabled', !html);
            }, 'json').fail(function () { showError('Bağlantı Hatası', 'Yetkiler alınamadı'); });
        }

        $('#syDepartman').on('change', syYukle);
        $('#syAlanlar').on('change', '.form-check-input', function () { syBagimlilik($(this).closest('tr')); });

        $('#btnSyKaydet').on('click', function () {
            const dep = $('#syDepartman').val();
            if (!dep) return;
            const yetkiler = [];
            $('#syAlanlar tr[data-alan]').each(function () {
                const $t = $(this);
                yetkiler.push({
                    id: $t.data('alan'),
                    gor: $t.find('.sy-gor').prop('checked') ? 1 : 0,
                    duzenle: $t.find('.sy-duzenle').prop('checked') ? 1 : 0,
                    kilitli_duzenle: $t.find('.sy-kilitli').prop('checked') ? 1 : 0
                });
            });
            const $b = $(this); const eski = $b.html();
            $b.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Kaydediliyor...');
            $.post('', { action: 'alan_yetki_kaydet', departman_id: dep, yetkiler: JSON.stringify(yetkiler) }, function (r) {
                if (r.success) showSuccess('Kaydedildi', r.message);
                else showError('Hata', r.message || 'Kaydedilemedi');
            }, 'json').fail(function () { showError('Bağlantı Hatası', 'Yetkiler kaydedilemedi'); })
              .always(function () { $b.prop('disabled', false).html(eski); });
        });
        <?php endif; ?>

        // ===== BBK Adres Modal =====
        const bbkModal = new bootstrap.Modal(document.getElementById('bbkModal'));

        $('#btnBbkModal').on('click', function () { bbkModal.show(); });

        // BBK Doğrula — endpoint 10 + şehir tespiti + kampanya güncelleme
        $('#btnBbkDogrula').on('click', function () {
            const kod = $('#bbkAddressCode').val().trim();
            if (!kod) { showToast('Önce BBK kodu girin', 'warning'); return; }
            const $b = $(this); const eski = $b.html();
            $b.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
            $.post('', { action: 'bbk_dogrula', bbk: kod }, function (r) {
                if (!r.success) { showError('Geçersiz BBK', r.message || 'Adres bulunamadı'); return; }
                const ozet = bbkAdresOzet(r.adres);
                if (r.sehir_id) {
                    kampanyalariYukle(r.sehir_id); // şehre göre kampanyalar
                    showSuccess('BBK Geçerli ✓', ozet + (r.sehir_ad ? '\nŞehir: ' + r.sehir_ad + ' — kampanyalar güncellendi' : ''));
                } else {
                    showSuccess('BBK Geçerli ✓', ozet + '\nŞehir tespit edilemedi; kampanyalar varsayılan listede.');
                }
            }, 'json').fail(function () { showError('Bağlantı Hatası', 'Doğrulama yapılamadı'); })
              .always(function () { $b.prop('disabled', false).html(eski); });
        });

        // Modal açılınca select'leri Select2 yap (CLAUDE.md: modalda destroy + dropdownParent)
        document.getElementById('bbkModal').addEventListener('shown.bs.modal', function () {
            addrLevels.forEach(function (l) {
                const $s = $('#' + l.id);
                if ($s.hasClass('select2-hidden-accessible')) $s.select2('destroy');
                $s.select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#bbkModal') });
            });
            const kod = ($('#bbkAddressCode').val() || '').trim();
            if (kod && bbkPrefilledFor !== kod) {
                // Textbox'ta kod var → tüm seviyeleri o adrese göre seçili getir
                resetBbkSonuc();
                bbkModalPrefillFromCode(kod);
            } else if (!kod && $('#addr_city option').length <= 1) {
                addrLoad(0, 0); // kod yok → sadece şehirleri yükle (code=0)
            }
        });

        // Her seviye değişince bir altını yükle, daha alttakileri temizle
        addrLevels.forEach(function (lvl, idx) {
            $('#' + lvl.id).on('change', function () {
                const val = this.value;
                for (let j = idx + 1; j < addrLevels.length; j++) {
                    rebuildSelect($('#' + addrLevels[j].id), '<option value="">Seçiniz</option>', true);
                }
                resetBbkSonuc();
                if (!val) return;
                if (idx < addrLevels.length - 1) {
                    addrLoad(idx + 1, val);
                } else {
                    // Kapı seçildi → code = BBK adres kodu
                    secilenBbk = val;
                    $('#bbkSonuc').removeClass('d-none').html('Oluşan BBK Adres Kodu: <strong class="mono">' + escapeHtml(val) + '</strong>');
                    $('#btnBbkKullan').prop('disabled', false);
                }
            });
        });

        $('#btnBbkKullan').on('click', function () {
            if (!secilenBbk) return;
            $('#bbkAddressCode').val(secilenBbk);
            bbkModal.hide();
            showToast('BBK kodu forma eklendi', 'success');
        });
    });

    // ===== BBK Adres yardımcıları =====
    const addrLevels = [
        { id: 'addr_city',     ep: 2, key: 'city' },
        { id: 'addr_county',   ep: 3, key: 'county' },
        { id: 'addr_burg',     ep: 4, key: 'burg' },
        { id: 'addr_village',  ep: 5, key: 'village' },
        { id: 'addr_quarter',  ep: 6, key: 'quarter' },
        { id: 'addr_street',   ep: 7, key: 'street' },
        { id: 'addr_building', ep: 8, key: 'building' },
        { id: 'addr_door',     ep: 9, key: 'door' }
    ];
    let secilenBbk = '';
    let bbkPrefilledFor = '';   // en son hangi BBK kodu için modal önceden dolduruldu

    // Select2'li bir select'i güvenle yeniden kur (destroy → html → init). empty()/append() Select2'yi bozuyor.
    function rebuildSelect($s, html, disabled) {
        if ($s.hasClass('select2-hidden-accessible')) $s.select2('destroy');
        $s.html(html).prop('disabled', !!disabled);
        $s.select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#bbkModal') });
    }

    // Digiturk, kaydı olmayan seviyelerde boş liste yerine geçersiz bir kayıt
    // ({code:0, name:null}) döndürüyor. Bu kayıtlar listeye alınmaz.
    function gecerliAdresKayitlari(r) {
        if (!r || !Array.isArray(r.data)) return [];
        return r.data.filter(function (it) {
            if (!it) return false;
            const code = it.code;
            const name = it.name;
            if (code === null || code === undefined || String(code).trim() === '' || String(code).trim() === '0') return false;
            if (name === null || name === undefined || String(name).trim() === '') return false;
            return true;
        });
    }

    // Seviye boş kaldığında uyarı. Kapı/daire seviyesi BBK'yı tamamen engellediği için
    // geçici toast yerine kalıcı ve açıklayıcı bir uyarı gösterilir.
    function adresBosUyari(r, idx) {
        const kapiSeviyesi = (idx === addrLevels.length - 1);
        const mesaj = r.message || (kapiSeviyesi
            ? 'Bu bina için kapı/daire kaydı bulunmuyor. BBK adres kodu üretilemiyor; aynı sokakta kapı kaydı olan bir bina deneyin.'
            : 'Bu seçim için kayıt bulunamadı.');

        if (kapiSeviyesi) {
            $('#bbkSonuc').removeClass('d-none alert-info').addClass('alert-warning')
                .html('<i class="bi bi-exclamation-triangle"></i> ' + escapeHtml(mesaj));
            $('#btnBbkKullan').prop('disabled', true);
            secilenBbk = '';
        } else {
            showToast(mesaj, 'warning');
        }
    }

    // idx seviyesini, parentCode parametresiyle ilgili endpoint'ten doldurur
    function addrLoad(idx, parentCode) {
        const lvl = addrLevels[idx];
        const $s = $('#' + lvl.id);
        rebuildSelect($s, '<option value="">Yükleniyor...</option>', true);
        $.post('', { action: 'address', endpoint_id: lvl.ep, code: parentCode }, function (r) {
            // Sunucu geçersiz kayıtları ayıklıyor; burada ikinci bir güvence olarak yine süzülür.
            const kayitlar = gecerliAdresKayitlari(r);
            let opts = '<option value="">Seçiniz</option>';
            const hasData = r.success && kayitlar.length > 0;
            if (hasData) {
                kayitlar.forEach(function (it) { opts += '<option value="' + escapeHtml(it.code) + '">' + escapeHtml(it.name) + '</option>'; });
            }
            rebuildSelect($s, opts, !hasData);
            if (!r.success) {
                showError('Adres Hatası', r.message || 'Adres verisi alınamadı');
            } else if (!hasData) {
                adresBosUyari(r, idx);
            }
        }, 'json').fail(function () {
            rebuildSelect($s, '<option value="">Seçiniz</option>', true);
            showError('Bağlantı Hatası', 'Adres servisine ulaşılamadı');
        });
    }

    // Bir seviyeyi parentCode ile yükler ve wantCode varsa o option'ı seçer (cascade change tetiklemeden).
    // Promise döner → seviyeler sırayla zincirlenebilir.
    function loadLevelSelectAndPick(idx, parentCode, wantCode) {
        return new Promise(function (resolve) {
            const lvl = addrLevels[idx];
            const $s = $('#' + lvl.id);
            rebuildSelect($s, '<option value="">Yükleniyor...</option>', true);
            $.post('', { action: 'address', endpoint_id: lvl.ep, code: parentCode }, function (r) {
                const kayitlar = gecerliAdresKayitlari(r);
                let opts = '<option value="">Seçiniz</option>';
                const hasData = r.success && kayitlar.length > 0;
                if (hasData) {
                    kayitlar.forEach(function (it) { opts += '<option value="' + escapeHtml(it.code) + '">' + escapeHtml(it.name) + '</option>'; });
                }
                rebuildSelect($s, opts, !hasData);
                if (wantCode !== null && wantCode !== undefined && wantCode !== '') {
                    $s.val(String(wantCode));
                    // Select2 görselini güncelle ama uygulamanın 'change' cascade handler'ını TETİKLEME
                    if ($s.val() === String(wantCode)) $s.trigger('change.select2');
                }
                resolve($s.val());
            }, 'json').fail(function () {
                rebuildSelect($s, '<option value="">Seçiniz</option>', true);
                resolve('');
            });
        });
    }

    // Endpoint 10 (bbk_dogrula) 'adres' objesindeki kodlarla tüm seviyeleri sırayla seçili getirir.
    async function bbkModalPrefill(adres, doorCode) {
        for (let i = 0; i < addrLevels.length; i++) {
            const node = adres[addrLevels[i].key];
            const parentCode = i === 0 ? 0 : (adres[addrLevels[i - 1].key] ? String(adres[addrLevels[i - 1].key].code) : 0);
            // Kapı seviyesinde textbox'taki tam BBK kodunu kullan (sayı hassasiyeti riskini önler)
            const wantCode = (i === addrLevels.length - 1) ? String(doorCode) : (node ? String(node.code) : '');
            await loadLevelSelectAndPick(i, parentCode, wantCode);
        }
        if (doorCode) {
            secilenBbk = String(doorCode);
            $('#bbkSonuc').removeClass('d-none').html('Oluşan BBK Adres Kodu: <strong class="mono">' + escapeHtml(String(doorCode)) + '</strong>');
            $('#btnBbkKullan').prop('disabled', false);
        }
    }

    // Textbox'taki BBK kodunu çözüp modalı önceden doldurur. Çözümlenemezse sadece şehirleri yükler.
    function bbkModalPrefillFromCode(kod) {
        $('#addr_door').prop('disabled', true);
        $.post('', { action: 'bbk_dogrula', bbk: kod }, function (r) {
            if (r && r.success && r.adres && typeof r.adres === 'object') {
                bbkModalPrefill(r.adres, kod).then(function () { bbkPrefilledFor = kod; });
            } else {
                addrLoad(0, 0); // çözümlenemedi → normal akış
            }
        }, 'json').fail(function () { addrLoad(0, 0); });
    }

    function resetBbkSonuc() {
        $('#bbkSonuc').addClass('d-none').removeClass('alert-warning').addClass('alert-info').html('');
        $('#btnBbkKullan').prop('disabled', true);
        secilenBbk = '';
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function bbkAdresOzet(adr) {
        if (!adr || typeof adr !== 'object') return '';
        const parts = [];
        Object.keys(adr).forEach(function (k) {
            const v = adr[k];
            if (v !== null && typeof v !== 'object' && String(v).trim() !== '') parts.push(String(v));
        });
        return parts.join(' ');
    }
</script>
</body>
</html>
