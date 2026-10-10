<?php
/**
 * Admin Panel - Başvuru Yönetimi
 * Reklam / web vb. kaynaklardan gelen başvuruları yönetir.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/BasvuruLogHelper.php';
require_once __DIR__ . '/../includes/EntegrasyonHelper.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Başvuru Yönetimi';
$menuAdi   = $pageinfo['menu_adi'] ?? 'Başvurular';

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// =====================================================================
// BİRİM BAZLI YETKİ KISITI (KullaniciBirimYetkileri junction)
// Başvuru görünürlüğü SİLSİLE: ApiLead yetkisi VEYA Personel/AltBayi yetkisi.
// Kısıtlı kullanıcı = !is_admin && birim_gor=1
// =====================================================================
$birimKisitli   = (!$permissions['is_admin'] && !empty($permissions['can_view_birim']));
$izinliBirimler = [];

// KENDİ KAYITLARINI GÖR kısıtı: admin değil + kendi_kullanicini_gor yetkisi → yalnız OlusturanKullanici = kendi id
$kendiKisitli = (!$permissions['is_admin'] && !empty($permissions['can_view_own_records']));
$kullaniciId  = (int)$user['kullanici_id'];

// "Başvuruları Dağıt" butonu şu departmanlarda gizlenir: 9 Agent, 23 Kıdemsiz Agent
$dagitGizliDepartmanlar = [9, 23];
$dagitButonGoster = !in_array((int)($user['departman_id'] ?? 0), $dagitGizliDepartmanlar, true);

// "Numara Sildirme" butonu yalnız şu departmanda görünür: 21 Takım Lideri (admin her zaman görür)
$uyeSildirmeDepartmanlar = [21];
$uyeSildirmeGoster = !empty($permissions['is_admin'])
    || in_array((int)($user['departman_id'] ?? 0), $uyeSildirmeDepartmanlar, true);
$uyeSildirmeWhatsappAktif = true;
$uyeSildirmeWhatsappGrup  = '120363000000000001@g.us';
$uyeSildirmeEmailAlicilar = [
    'destek1@ornekkurum.com',
    'yetkili1@ornekkurum.com',
    'satis@ornekkurum.com',
];

/**
 * Dağıtım şablonları (profil). Tarih ifadeleri MSSQL sabitidir (kullanıcı girdisi yok).
 *   kidem    : Dün 19:00'dan sonrası → Departman 9 (Kıdemli Agent)
 *   kidemsiz : Önceki gün 19:00 – dün 19:00 arası + İletişim Durum=1 (Ulaşılamadı) → Departman 23 (Kıdemsiz Agent)
 * Dağıtım yalnızca kullanıcının kendi birimi + alt birimlerine yapılır (birimAgaci).
 * @return array|null ['ad', 'dept'=>[], 'tarihWhere', 'aciklama']
 */
function dagitimProfili(string $tip): ?array {
    $dun19    = "DATEADD(HOUR, 19, CAST(CAST(DATEADD(DAY, -1, GETDATE()) AS DATE) AS DATETIME))";
    $onceki19 = "DATEADD(HOUR, 19, CAST(CAST(DATEADD(DAY, -2, GETDATE()) AS DATE) AS DATETIME))";
    switch ($tip) {
        case 'kidem':
            return [
                'ad'         => 'Kıdemli Agent',
                'dept'       => [9],
                'tarihWhere' => "t.OlusturmaTarihi >= $dun19",
                'aciklama'   => 'Dün 19:00 sonrası oluşturulan başvurular',
            ];
        case 'kidemsiz':
            return [
                'ad'         => 'Kıdemsiz Agent',
                'dept'       => [23],
                'tarihWhere' => "t.OlusturmaTarihi >= $onceki19 AND t.OlusturmaTarihi < $dun19 AND t.Basvurular_IletisimDurum_ID = 1",
                'aciklama'   => "Önceki gün 19:00 – dün 19:00 arası, İletişim Durumu 'Ulaşılamadı' başvurular",
            ];
        default:
            return null;
    }
}

/**
 * Dağıt modalındaki ek filtre anahtarları → WHERE parçası (parametresiz sabit ifadeler).
 *   sadece_otp    (varsayılan 1): yalnız OTP onaylı
 *   sadece_isimli (varsayılan 0): İsim ve Soyisim dolu
 *   sadece_reklam (varsayılan 0): reklam lead formundan gelen
 *   reklam_leadformlari[]        : sadece_reklam=1 iken seçili lead formları (boşsa tümü)
 */
function dagitimEkWhere(array $post): string {
    $w = '';
    if (($post['sadece_otp'] ?? '1') === '1')    $w .= " AND t.Basvurular_OtpDurum = 'onayli'";
    if (($post['sadece_isimli'] ?? '0') === '1') $w .= " AND LTRIM(RTRIM(ISNULL(t.Isim, ''))) <> '' AND LTRIM(RTRIM(ISNULL(t.Soyisim, ''))) <> ''";
    if (($post['sadece_reklam'] ?? '0') === '1') {
        $w .= " AND t.ReklamLeadFormlari_ID IS NOT NULL";
        // Seçili lead formları (boşsa tüm reklam kaynakları). Değerler int'e çevrilir → enjeksiyon riski yok.
        $formIds = array_values(array_unique(array_filter(
            array_map('intval', (array)($post['reklam_leadformlari'] ?? [])),
            fn($v) => $v > 0
        )));
        if (!empty($formIds)) {
            $w .= " AND t.ReklamLeadFormlari_ID IN (" . implode(',', $formIds) . ")";
        }
    }
    if (($post['karaliste_haric'] ?? '1') === '1') $w .= " AND NOT " . karaListeEslesmeSql();
    return $w;
}

/**
 * Başvuru (alias t) aktif kara listede mi? EXISTS ifadesi döner.
 * GSM 90XXXXXXXXXX biçiminde (KaraListe::normalizeGsm), TC düz eşleşir.
 */
function karaListeEslesmeSql(): string {
    $rakam = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                CONCAT(ISNULL(t.phoneCountryNumber,''), ISNULL(t.phoneAreaNumber,''), ISNULL(t.phoneNumber,'')),
                '+',''),' ',''),'-',''),'(',''),')','')";
    return "EXISTS (
        SELECT 1 FROM KaraListe k
        WHERE k.Durum = 1
          AND (k.KaraListe_BaslangicTarihi IS NULL OR k.KaraListe_BaslangicTarihi <= GETDATE())
          AND (k.KaraListe_BitisTarihi     IS NULL OR k.KaraListe_BitisTarihi     >= GETDATE())
          AND (
                (k.KaraListe_Tur = 'gsm' AND LEN($rakam) >= 10 AND k.KaraListe_Deger = '90' + RIGHT($rakam, 10))
             OR (k.KaraListe_Tur = 'tc'  AND t.TCKimlikNo IS NOT NULL AND k.KaraListe_Deger = t.TCKimlikNo)
          )
    )";
}

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
    // kullanici_birim_id boşsa $izinliBirimler boş → hiçbir kayıt görünmez (güvenli varsayılan)
}

/**
 * Başvuru birim kısıt WHERE parçası — SİLSİLE: ApiLead VEYA Personel/AltBayi VEYA
 * Meta Lead (LeadFormu → Sayfa → Birim) yetkisi. $izinliBirimler üst+alt birimleri
 * içerdiğinden hiyerarşi (üst birim alt birimi görür) otomatik uygulanır.
 * @return array [sqlFragment, params]
 */
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

/** Verilen birimin kendisi + tüm alt birimleri (hiyerarşi). Dağıtım kapsamı için. */
function birimAgaci($db, int $birimId): array {
    if ($birimId <= 0) return [];
    $rows = $db->fetchAll("
        WITH BirimAgaci AS (
            SELECT KullaniciBirim_id FROM KullaniciBirim WHERE KullaniciBirim_id = ?
            UNION ALL
            SELECT b.KullaniciBirim_id FROM KullaniciBirim b
            INNER JOIN BirimAgaci a ON b.KullaniciBirim_UstBirim_id = a.KullaniciBirim_id
        )
        SELECT KullaniciBirim_id FROM BirimAgaci
    ", [$birimId]);
    return array_map(fn($r) => (int)$r['KullaniciBirim_id'], $rows);
}

/** Tekil başvurunun (ApiLead veya Personel/AltBayi silsilesiyle) kullanıcı kapsamında olup olmadığı */
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
 * Başvuru için API gönderim verisini hazırlar (endpoint + token + body).
 * Endpoint: Kampanya Tur=1 → 15 (CreateNeo), Tur=2 → 16 (CreateSatellite)
 * Token  : Basvurular.AltBayiPersonel_ID → DigiturkAltBayiPersonel_Token
 * @return array ['ok'=>bool, 'msg'=>?string, ...] hazırlık başarılıysa endpointId/url/method/token/personel/body döner.
 */
function basvuruApiHazirla($db, int $basvuruId): array {
    global $kullaniciId;
    $b = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$basvuruId]);
    if (!$b) return ['ok' => false, 'msg' => 'Başvuru bulunamadı'];

    if (empty($b['Kampanyalar_ID'])) return ['ok' => false, 'msg' => 'Başvuruda kampanya seçili değil'];
    $k = $db->fetchOne("
        SELECT APIKampanyalar_Tur, APIKampanyalar_OfferToId, APIKampanyalar_OfferFromId, APIKampanyalar_FaturaDonemi
        FROM APIKampanyalar WHERE APIKampanyalar_Id = ?", [(int)$b['Kampanyalar_ID']]);
    if (!$k) return ['ok' => false, 'msg' => 'Kampanya kaydı bulunamadı (Id=' . (int)$b['Kampanyalar_ID'] . ')'];

    $tur        = (int)$k['APIKampanyalar_Tur'];
    $endpointId = $tur === 1 ? 15 : ($tur === 2 ? 16 : 0);
    if (!$endpointId) return ['ok' => false, 'msg' => 'Kampanya türü API gönderimi için uygun değil (Tur=' . $tur . ')'];
    if (empty($k['APIKampanyalar_OfferToId']) || empty($k['APIKampanyalar_OfferFromId'])) {
        return ['ok' => false, 'msg' => 'Kampanyada OfferToId / OfferFromId eksik'];
    }

    $ep = $db->fetchOne("
        SELECT APIEndpointler_Endpoint, APIEndpointler_HttpMetod
        FROM APIEndpointler WHERE APIEndpointler_Id = ? AND Durum = 1", [$endpointId]);
    if (!$ep) return ['ok' => false, 'msg' => "Endpoint bulunamadı veya pasif (Id=$endpointId)"];

    if (empty($b['AltBayiPersonel_ID'])) return ['ok' => false, 'msg' => 'Başvuruda Alt Bayi Personeli seçili değil (token kaynağı)'];
    $per = $db->fetchOne("
        SELECT DigiturkAltBayiPersonel_Token, DigiturkAltBayiPersonel_AdSoyad
        FROM DigiturkAltBayiPersonel WHERE DigiturkAltBayiPersonel_Id = ?", [(int)$b['AltBayiPersonel_ID']]);
    if (!$per || empty($per['DigiturkAltBayiPersonel_Token'])) {
        return ['ok' => false, 'msg' => 'Personelin aktif token\'ı yok! Önce token yenileyin.'];
    }

    // Boş alanları Basvurular tablosunda kalıcı yapmak için toplanacak güncellemeler
    $guncelle = [];

    // Kimlik türü seçili değilse varsayılan olarak Id=1 kaydın card_code'u kullanılır
    if (!empty($b['KimlikKartiTurleri_ID'])) {
        $kimlikId = (int)$b['KimlikKartiTurleri_ID'];
    } else {
        $kimlikId = 1;
        $guncelle['KimlikKartiTurleri_ID'] = $kimlikId;
    }
    $kk         = $db->fetchOne("SELECT APIKimlikKartiTurleri_card_code FROM APIKimlikKartiTurleri WHERE APIKimlikKartiTurleri_Id = ?", [$kimlikId]);
    $kimlikCode = $kk['APIKimlikKartiTurleri_card_code'] ?? null;

    // Saat gün ortasına çekilir: Digiturk tarihi UTC'ye çevirip gün alıyor,
    // 00:00+03:00 bir önceki güne düşüp kimlik doğrulamasını bozuyor.
    $birth = null;
    $bd = trim((string)($b['birthDate'] ?? ''));
    if ($bd !== '') {
        $dt = date_create(substr($bd, 0, 10));
        if ($dt) $birth = $dt->format('Y-m-d') . 'T12:00:00+03:00';
    }

    // email boşsa: isimsoyisim + rastgele 3 hane + @gmail.com (türkçe karakter temizli)
    $email = trim((string)($b['email'] ?? ''));
    if ($email === '') {
        $tr = ['ç','ğ','ı','i̇','ö','ş','ü','Ç','Ğ','I','İ','Ö','Ş','Ü',' '];
        $en = ['c','g','i','i','o','s','u','c','g','i','i','o','s','u',''];
        $ad = str_replace($tr, $en, mb_strtolower(trim((string)($b['Isim'] ?? '') . (string)($b['Soyisim'] ?? '')), 'UTF-8'));
        $ad = preg_replace('/[^a-z0-9]/', '', $ad);
        if ($ad === '') $ad = 'kullanici';
        $email = $ad . random_int(100, 999) . '@gmail.com';
        $guncelle['email'] = $email;
    }

    // genderType boşsa varsayılan BAY
    $gender = trim((string)($b['genderType'] ?? ''));
    if ($gender === '') {
        $gender = 'BAY';
        $guncelle['genderType'] = $gender;
    }

    // Üretilen boş değerleri Basvurular tablosuna kalıcı yaz
    if ($guncelle) {
        $set = [];
        $par = [];
        foreach ($guncelle as $kol => $val) { $set[] = "$kol = ?"; $par[] = $val; }
        $set[] = "GuncelleyenKullanici = ?"; $par[] = (int)$kullaniciId;
        $set[] = "GuncellemeTarihi = GETDATE()";
        $par[] = $basvuruId;
        $db->execute("UPDATE Basvurular SET " . implode(', ', $set) . " WHERE Basvurular_id = ?", $par);
    }

    $bbk  = trim((string)($b['bbkAddressCode'] ?? ''));
    $body = [
        'bbkAddressCode'     => ctype_digit($bbk) ? (int)$bbk : $bbk,
        'email'              => $email,
        'phoneCountryNumber' => (string)($b['phoneCountryNumber'] ?? ''),
        'phoneAreaNumber'    => (string)($b['phoneAreaNumber'] ?? ''),
        'phoneNumber'        => (string)($b['phoneNumber'] ?? ''),
        'birthDate'          => $birth,
        'citizenNumber'      => (string)($b['TCKimlikNo'] ?? ''),
        'firstName'          => $b['Isim'] ?? '',
        'surname'            => $b['Soyisim'] ?? '',
        'genderType'         => mb_strtolower($gender, 'UTF-8'),
        'identityCardType'   => $kimlikCode,
        // CC servis dokümanı V5.01 ile eklenen alanlar.
        // ticketRoutingType 0 = adrese bakan bayiye (API varsayılanı), 1 = bana.
        // ticketRoutingDelaer yalnız tip 2'de zorunlu; tip 2 formda sunulmuyor.
        'ticketRoutingType'  => (int)($b['ticketRoutingType'] ?? 0),
        // Doküman örneğinde boş string; null yerine '' gönderilir.
        'ticketRoutingDelaer'=> (string)($b['ticketRoutingDelaer'] ?? ''),
        'isHandicapped'      => !empty($b['isHandicapped']),
        'isVeteran'          => !empty($b['isVeteran']),
        'orderBasketSimulateItemList' => [[
            'offerToId'     => (int)$k['APIKampanyalar_OfferToId'],
            'offerFromId'   => (int)$k['APIKampanyalar_OfferFromId'],
            'frequency'     => 1,
            'frequencyCode' => $k['APIKampanyalar_FaturaDonemi'] ?: 'AY',
        ]],
        'orderBasketSimulateItemGiftList' => [],
    ];

    return [
        'ok'         => true,
        'endpointId' => $endpointId,
        'url'        => $ep['APIEndpointler_Endpoint'],
        'method'     => $ep['APIEndpointler_HttpMetod'] ?: 'POST',
        'token'      => $per['DigiturkAltBayiPersonel_Token'],
        'personel'   => $per['DigiturkAltBayiPersonel_AdSoyad'] ?? '',
        'body'       => $body,
    ];
}

/**
 * Serbest arama WHERE parçası. Metin alanlarında normal LIKE;
 * telefon için arama terimindeki rakamlar alınıp 3 telefon kolonunun
 * (country+area+number) boşluk/tire temizlenmiş birleşimi üzerinde aranır.
 * Böylece "905550000003", "5550000003", "05550000003", "0000003" hepsi eşleşir.
 * @return array [sqlFragment, params]
 */
function basvuruAramaWhere(string $search): array {
    $conds  = ["t.Isim LIKE ?", "t.Soyisim LIKE ?", "t.TCKimlikNo LIKE ?", "t.email LIKE ?"];
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
    $tel = preg_replace('/\D/', '', $search);
    if ($tel !== '') {
        $conds[]  = "REPLACE(REPLACE(CONCAT(ISNULL(t.phoneCountryNumber,''), ISNULL(t.phoneAreaNumber,''), ISNULL(t.phoneNumber,'')), ' ', ''), '-', '') LIKE ?";
        $params[] = "%$tel%";
    } else {
        $conds[]  = "t.phoneNumber LIKE ?";
        $params[] = "%$search%";
    }
    return ["(" . implode(" OR ", $conds) . ")", $params];
}

// ── Excel indir (JSON header'dan ÖNCE — çıktı JSON değil, .xls) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'excel_indir') {
    // Filtreler (list ile aynı)
    $search     = trim($_POST['search']['value'] ?? ($_POST['search'] ?? ''));
    $durum      = $_POST['f_basvuru_durum'] ?? '';
    $surecDurum = $_POST['f_surec_durum']   ?? '';
    $iletisimD  = $_POST['f_iletisim_durum'] ?? '';
    $kampanya   = $_POST['f_kampanya']      ?? '';
    $birim      = $_POST['f_birim']         ?? '';
    $personelF  = $_POST['f_personel']      ?? '';
    $sayfaF     = $_POST['f_sayfa']         ?? '';
    $atananF    = $_POST['f_atanan']        ?? '';
    $leadFormF  = $_POST['f_lead_formu']    ?? '';
    $tarihBas   = trim($_POST['f_tarih_bas'] ?? '');
    $tarihBit   = trim($_POST['f_tarih_bit'] ?? '');

    // Temel (yetki) kısıt — list ile aynı
    $whereFilt  = ["1=1"];
    $paramsFilt = [];
    if ($birimKisitli) {
        if (empty($izinliBirimler)) { $whereFilt[] = "1=0"; } // hiçbir kayıt
        else {
            [$wBirim, $pBirim] = basvuruBirimKisitWhere(
                $izinliBirimler,
                't.CallCenterApiLead_ID',
                't.AltBayiPersonel_ID',
                '(SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID)',
                't.ReklamLeadFormlari_ID',
                't.OlusturanKullanici'
            );
            $whereFilt[]  = $wBirim;
            $paramsFilt   = array_merge($paramsFilt, $pBirim);
        }
    }
    if ($kendiKisitli) {
        $whereFilt[]  = "(t.OlusturanKullanici = ? OR t.Basvurular_AtananKullanici_ID = ?)";
        $paramsFilt[] = $kullaniciId;
        $paramsFilt[] = $kullaniciId;
    }

    // Kullanıcı filtreleri
    if ($search !== '') {
        [$wAra, $pAra] = basvuruAramaWhere($search);
        $whereFilt[] = $wAra;
        $paramsFilt  = array_merge($paramsFilt, $pAra);
    }
    if ($durum !== '')      { $whereFilt[] = "t.BasvuruDurum_ID = ?";      $paramsFilt[] = (int)$durum; }
    if ($surecDurum !== '') { $whereFilt[] = "t.BasvuruSurecDurum_ID = ?"; $paramsFilt[] = (int)$surecDurum; }
    if ($iletisimD !== '')  { $whereFilt[] = "t.Basvurular_IletisimDurum_ID = ?"; $paramsFilt[] = (int)$iletisimD; }
    if ($kampanya !== '')   { $whereFilt[] = "t.Kampanyalar_ID = ?";       $paramsFilt[] = (int)$kampanya; }
    if ($personelF !== '')  { $whereFilt[] = "t.AltBayiPersonel_ID = ?";   $paramsFilt[] = (int)$personelF; }
    if ($atananF === '0')      { $whereFilt[] = "t.Basvurular_AtananKullanici_ID IS NULL"; }
    elseif ($atananF !== '')   { $whereFilt[] = "t.Basvurular_AtananKullanici_ID = ?"; $paramsFilt[] = (int)$atananF; }
    // Lead formu (çoklu, virgülle): 'yok' → reklamdan gelmeyen, pozitif id'ler → seçili lead formları
    $lfSecim = array_filter(array_map('trim', explode(',', (string)$leadFormF)), 'strlen');
    $lfIds   = array_values(array_unique(array_map('intval', array_filter($lfSecim, 'ctype_digit'))));
    $lfKosul = [];
    if (in_array('yok', $lfSecim, true)) { $lfKosul[] = "t.ReklamLeadFormlari_ID IS NULL"; }
    if ($lfIds) { $lfKosul[] = "t.ReklamLeadFormlari_ID IN (" . implode(',', $lfIds) . ")"; }
    if ($lfKosul) { $whereFilt[] = '(' . implode(' OR ', $lfKosul) . ')'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $tarihBas)) {
        $whereFilt[]  = "t.OlusturmaTarihi >= ?";
        $paramsFilt[] = str_replace('T', ' ', $tarihBas) . ':00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $tarihBit)) {
        $whereFilt[]  = "t.OlusturmaTarihi <= ?";
        $paramsFilt[] = str_replace('T', ' ', $tarihBit) . ':59';
    }
    if ($sayfaF !== '') {
        $whereFilt[] = "EXISTS (
            SELECT 1 FROM ReklamLeadFormlari f
            WHERE f.ReklamLeadFormlari_id = t.ReklamLeadFormlari_ID
              AND f.ReklamLeadFormlari_Sayfa_id = ?)";
        $paramsFilt[] = (int)$sayfaF;
    }
    if ($birim !== '') {
        $whereFilt[] = "(EXISTS (
            SELECT 1 FROM KullaniciBirimYetkileri kbf
            WHERE kbf.KullaniciBirimYetkileri_Birim_id = ? AND kbf.Durum = 1
              AND (kbf.KullaniciBirimYetkileri_Personel_id = t.AltBayiPersonel_ID
                OR kbf.KullaniciBirimYetkileri_AltBayi_id = (SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID)
                OR kbf.KullaniciBirimYetkileri_ApiLead_id = t.CallCenterApiLead_ID))
          OR EXISTS (
            SELECT 1 FROM ReklamLeadFormlari f
            INNER JOIN KullaniciBirimYetkileri kbf2
                    ON kbf2.KullaniciBirimYetkileri_ReklamSayfa_id = f.ReklamLeadFormlari_Sayfa_id
            WHERE kbf2.KullaniciBirimYetkileri_Birim_id = ? AND kbf2.Durum = 1
              AND f.ReklamLeadFormlari_id = t.ReklamLeadFormlari_ID)
          OR (t.AltBayiPersonel_ID IS NULL AND t.CallCenterApiLead_ID IS NULL AND t.ReklamLeadFormlari_ID IS NULL
              AND EXISTS (SELECT 1 FROM kullanicilar kuc WHERE kuc.kullanici_id = t.OlusturanKullanici AND kuc.kullanici_birim_id = ?)))";
        $paramsFilt[] = (int)$birim;
        $paramsFilt[] = (int)$birim;
        $paramsFilt[] = (int)$birim;
    }

    $wFiltClause = implode(" AND ", $whereFilt);

    // Export sorgusu — görünen kolonlar (mükerrer sayımı gibi ağır alt sorgular yok)
    $rows = $db->fetchAll("
        SELECT
            COALESCE(
              (SELECT TOP 1 b.KullaniciBirim_Adi
                 FROM KullaniciBirimYetkileri kby
                 INNER JOIN KullaniciBirim b ON b.KullaniciBirim_id = kby.KullaniciBirimYetkileri_Birim_id
                 WHERE kby.Durum = 1
                   AND (kby.KullaniciBirimYetkileri_Personel_id = t.AltBayiPersonel_ID
                     OR kby.KullaniciBirimYetkileri_AltBayi_id = (SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID)
                     OR kby.KullaniciBirimYetkileri_ApiLead_id = t.CallCenterApiLead_ID)),
              (SELECT TOP 1 b2.KullaniciBirim_Adi
                 FROM ReklamLeadFormlari f
                 INNER JOIN KullaniciBirimYetkileri kby2
                         ON kby2.KullaniciBirimYetkileri_ReklamSayfa_id = f.ReklamLeadFormlari_Sayfa_id
                        AND kby2.Durum = 1
                 INNER JOIN KullaniciBirim b2 ON b2.KullaniciBirim_id = kby2.KullaniciBirimYetkileri_Birim_id
                 WHERE f.ReklamLeadFormlari_id = t.ReklamLeadFormlari_ID),
              (SELECT TOP 1 kbc.KullaniciBirim_Adi
                 FROM kullanicilar kuc
                 INNER JOIN KullaniciBirim kbc ON kbc.KullaniciBirim_id = kuc.kullanici_birim_id
                 WHERE kuc.kullanici_id = t.OlusturanKullanici)
            ) AS BirimAdi,
            p.DigiturkAltBayiPersonel_AdSoyad AS PersonelAdi,
            CASE WHEN t.ReklamLeadFormlari_ID IS NULL THEN 'Yok' ELSE 'Var' END AS LeadFormuDurum,
            LTRIM(RTRIM(CONCAT(ISNULL(t.Isim, ''), ' ', ISNULL(t.Soyisim, '')))) AS AdSoyad,
            t.TCKimlikNo,
            LTRIM(RTRIM(CONCAT(ISNULL(t.phoneCountryNumber,''), ' ', ISNULL(t.phoneAreaNumber,''), ' ', ISNULL(t.phoneNumber,'')))) AS Telefon,
            kmp.APIKampanyalar_Ad      AS KampanyaAdi,
            bd.BasvuruDurum_Mesaj      AS DurumMesaj,
            t.BasvuruDurumMesaj        AS BasvuruDurumMesaj,
            sd.BasvuruSurecDurum_Mesaj AS SurecMesaj,
            idr.BasvuruIletisimDurum_Mesaj AS IletisimDurumMesaj,
            CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) AS OlusturmaTarihi,
            LTRIM(RTRIM(CONCAT(ISNULL(ak.kullanici_ad, ''), ' ', ISNULL(ak.kullanici_soyad, '')))) AS AtananAdSoyad
        FROM Basvurular t
        LEFT JOIN APIKampanyalar         kmp ON kmp.APIKampanyalar_Id        = t.Kampanyalar_ID
        LEFT JOIN BasvuruDurum           bd  ON bd.BasvuruDurum_id           = t.BasvuruDurum_ID
        LEFT JOIN BasvuruSurecDurum      sd  ON sd.BasvuruSurecDurum_id      = t.BasvuruSurecDurum_ID
        LEFT JOIN BasvuruIletisimDurum   idr ON idr.BasvuruIletisimDurum_id = t.Basvurular_IletisimDurum_ID
        LEFT JOIN DigiturkAltBayiPersonel p  ON p.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID
        LEFT JOIN kullanicilar           ak  ON ak.kullanici_id             = t.Basvurular_AtananKullanici_ID
        WHERE $wFiltClause
        ORDER BY t.OlusturmaTarihi DESC, t.Basvurular_id DESC
    ", $paramsFilt);

    $filename = 'basvuru_listesi_' . date('Y-m-d_H-i-s') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM (Türkçe karakter)

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head>';
    echo '<meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">';
    echo '<xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
    echo '<x:Name>Başvurular</x:Name>';
    echo '<x:WorksheetOptions><x:Print><x:ValidPrinterInfo/></x:Print></x:WorksheetOptions>';
    echo '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml>';
    echo '</head><body><table border="1"><thead>';
    echo '<tr style="background-color:#0d6efd;color:white;font-weight:bold;">';
    foreach (['Birim','Alt Bayi Personeli','Ad Soyad','TC Kimlik','Telefon','Kampanya','Başvuru Durumu','Durum Mesajı','Süreç Durumu','İletişim Durumu','Tarih','Atanan','Reklam'] as $h) {
        echo '<th>' . htmlspecialchars($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        // TC/telefon başında sıfır kaybolmasın: metin olarak zorla
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['BirimAdi'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['PersonelAdi'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['AdSoyad'] ?? '') . '</td>';
        echo '<td style="mso-number-format:\'\@\'">' . htmlspecialchars($r['TCKimlikNo'] ?? '') . '</td>';
        echo '<td style="mso-number-format:\'\@\'">' . htmlspecialchars($r['Telefon'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['KampanyaAdi'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['DurumMesaj'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['BasvuruDurumMesaj'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['SurecMesaj'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['IletisimDurumMesaj'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['OlusturmaTarihi'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['AtananAdSoyad'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($r['LeadFormuDurum'] ?? '') . '</td>';
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

            // ── Başvuruları dağıt: önizleme (profil bazlı bekleyen sayısı + hedef dept kişiler) ──
            case 'dagit_onizleme': {
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok!']); break; }
                $prof = dagitimProfili($_POST['tip'] ?? '');
                if (!$prof) { echo json_encode(['success' => false, 'message' => 'Geçersiz dağıtım tipi.']); break; }
                $kb = $db->fetchOne("SELECT kullanici_birim_id FROM kullanicilar WHERE kullanici_id = ?", [$kullaniciId]);
                $birimId = (int)($kb['kullanici_birim_id'] ?? 0);
                if ($birimId <= 0) { echo json_encode(['success' => false, 'message' => 'Biriminiz tanımlı değil, dağıtım yapılamaz.']); break; }
                $agac = birimAgaci($db, $birimId);
                if (empty($agac)) { echo json_encode(['success' => false, 'message' => 'Birim ağacı bulunamadı.']); break; }

                // Ek filtreler: OTP Doğrulananlar / İsim soyismi olanlar / Reklamdan gelenler
                $otpWhere = dagitimEkWhere($_POST);

                [$wB, $pB] = basvuruBirimKisitWhere($agac,
                    't.CallCenterApiLead_ID', 't.AltBayiPersonel_ID',
                    '(SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID)',
                    't.ReklamLeadFormlari_ID');
                $bekleyen = (int)($db->fetchOne(
                    "SELECT COUNT(*) AS c FROM Basvurular t
                     WHERE t.Basvurular_AtananKullanici_ID IS NULL
                       AND ({$prof['tarihWhere']})$otpWhere AND $wB",
                    $pB)['c'] ?? 0);

                $phB   = implode(',', array_fill(0, count($agac), '?'));
                $phDep = implode(',', array_fill(0, count($prof['dept']), '?'));
                $kisiler = $db->fetchAll("
                    SELECT kullanici_id AS id,
                           LTRIM(RTRIM(CONCAT(kullanici_ad, ' ', kullanici_soyad))) AS ad
                    FROM kullanicilar
                    WHERE kullanici_departman_id IN ($phDep) AND kullanici_durum = 1 AND kullanici_birim_id IN ($phB)
                    ORDER BY kullanici_ad, kullanici_soyad", array_merge($prof['dept'], $agac));

                echo json_encode([
                    'success'  => true,
                    'bekleyen' => $bekleyen,
                    'kisiler'  => $kisiler,
                    'profilAd' => $prof['ad'],
                    'aciklama' => $prof['aciklama'],
                ]);
                break;
            }

            // ── Başvuruları dağıt: uygula (profil bazlı round-robin atama + log) ──
            case 'dagit_uygula': {
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok!']); break; }
                $prof = dagitimProfili($_POST['tip'] ?? '');
                if (!$prof) { echo json_encode(['success' => false, 'message' => 'Geçersiz dağıtım tipi.']); break; }

                // Kişi başı adet: { kullanici_id: adet }. 0/negatif ve geçersiz anahtarlar elenir.
                $adetGirdi = (array)($_POST['adetler'] ?? []);
                $adetler = [];
                foreach ($adetGirdi as $kid => $ad) {
                    $kid = (int)$kid; $ad = (int)$ad;
                    if ($kid > 0 && $ad > 0) $adetler[$kid] = $ad;
                }
                if (empty($adetler)) { echo json_encode(['success' => false, 'message' => 'En az bir kişiye adet girin.']); break; }
                $secili = array_keys($adetler);

                $kb = $db->fetchOne("SELECT kullanici_birim_id FROM kullanicilar WHERE kullanici_id = ?", [$kullaniciId]);
                $birimId = (int)($kb['kullanici_birim_id'] ?? 0);
                if ($birimId <= 0) { echo json_encode(['success' => false, 'message' => 'Biriminiz tanımlı değil, dağıtım yapılamaz.']); break; }
                $agac = birimAgaci($db, $birimId);
                if (empty($agac)) { echo json_encode(['success' => false, 'message' => 'Birim ağacı bulunamadı.']); break; }

                // Seçili kişileri doğrula (profil dept + birim kapsamı — POST manipülasyonuna karşı)
                $phK   = implode(',', array_fill(0, count($secili), '?'));
                $phB   = implode(',', array_fill(0, count($agac), '?'));
                $phDep = implode(',', array_fill(0, count($prof['dept']), '?'));
                $gecerli = $db->fetchAll("
                    SELECT kullanici_id FROM kullanicilar
                    WHERE kullanici_id IN ($phK) AND kullanici_departman_id IN ($phDep) AND kullanici_durum = 1
                      AND kullanici_birim_id IN ($phB)
                    ORDER BY kullanici_id", array_merge($secili, $prof['dept'], $agac));
                $kisiIds = array_map(fn($r) => (int)$r['kullanici_id'], $gecerli);
                if (empty($kisiIds)) { echo json_encode(['success' => false, 'message' => 'Geçerli kişi bulunamadı.']); break; }
                // Doğrulanmayan kişileri adet eşlemesinden çıkar (giriş sırası korunur)
                $adetler = array_intersect_key($adetler, array_flip($kisiIds));
                if (empty($adetler)) { echo json_encode(['success' => false, 'message' => 'Geçerli kişi bulunamadı.']); break; }
                $toplamIstenen = array_sum($adetler);

                // Hedef başvurular (profil tarih/durum kriteri + atanmamış + birim kapsamı).
                // İstenen toplam kadar TOP ile çekilir; gerçek bekleyen azsa doğal olarak kısılır.
                // Ek filtreler: OTP Doğrulananlar / İsim soyismi olanlar / Reklamdan gelenler
                $otpWhere = dagitimEkWhere($_POST);

                [$wB, $pB] = basvuruBirimKisitWhere($agac,
                    't.CallCenterApiLead_ID', 't.AltBayiPersonel_ID',
                    '(SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID)',
                    't.ReklamLeadFormlari_ID');
                $hedefler = $db->fetchAll("
                    SELECT TOP (?) t.Basvurular_id FROM Basvurular t
                    WHERE t.Basvurular_AtananKullanici_ID IS NULL
                      AND ({$prof['tarihWhere']})$otpWhere AND $wB
                    ORDER BY t.Basvurular_id",
                    array_merge([$toplamIstenen], $pB));
                if (empty($hedefler)) { echo json_encode(['success' => false, 'message' => 'Dağıtılacak başvuru yok.']); break; }

                // Havuzu kişilere sırayla dilimle: her kişiye girdiği adet kadar başvuru (FIFO).
                // Havuz istenenden azsa son kişiler eksik/boş kalır (doğal kısıt).
                $havuz = array_map(fn($h) => (int)$h['Basvurular_id'], $hedefler);
                $gruplar = [];
                $ofset = 0;
                foreach ($adetler as $kid => $ad) {
                    if ($ofset >= count($havuz)) break;
                    $dilim = array_slice($havuz, $ofset, $ad);
                    if (!empty($dilim)) { $gruplar[$kid] = $dilim; $ofset += count($dilim); }
                }

                $now = date('Y-m-d H:i:s');
                $toplam = 0;
                $db->execute("BEGIN TRANSACTION");
                try {
                    foreach ($gruplar as $kid => $ids) {
                        $ph = implode(',', array_fill(0, count($ids), '?'));
                        $db->execute("
                            UPDATE Basvurular
                            SET Basvurular_AtananKullanici_ID = ?, Basvurular_AtamaTarihi = ?,
                                GuncelleyenKullanici = ?, GuncellemeTarihi = ?
                            WHERE Basvurular_id IN ($ph)",
                            array_merge([$kid, $now, $kullaniciId, $now], $ids));
                        foreach ($ids as $bid) {
                            basvuruLogKaydet($db, $bid, 'GUNCELLE',
                                ['Basvurular_AtananKullanici_ID' => null],
                                ['Basvurular_AtananKullanici_ID' => $kid, 'Basvurular_AtamaTarihi' => $now],
                                $kullaniciId, 'Toplu dağıtım (' . $prof['ad'] . ') ile atandı');
                        }
                        $toplam += count($ids);
                    }
                    $db->execute("COMMIT");
                } catch (Throwable $e) {
                    $db->execute("ROLLBACK");
                    echo json_encode(['success' => false, 'message' => 'Dağıtım hatası: ' . $e->getMessage()]);
                    break;
                }
                echo json_encode(['success' => true, 'message' => "$toplam başvuru, " . count($gruplar) . " {$prof['ad']} kişisine dağıtıldı."]);
                break;
            }

            // ── Başvuruları dağıt (manuel): tablodan seçilen başvurular, atanmış/boş fark etmez ──
            case 'dagit_manuel_onizleme':
            case 'dagit_manuel_uygula': {
                if (!$permissions['can_edit'] || !$dagitButonGoster) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok!']); break; }
                $kb = $db->fetchOne("SELECT kullanici_birim_id FROM kullanicilar WHERE kullanici_id = ?", [$kullaniciId]);
                $birimId = (int)($kb['kullanici_birim_id'] ?? 0);
                if ($birimId <= 0) { echo json_encode(['success' => false, 'message' => 'Biriminiz tanımlı değil, dağıtım yapılamaz.']); break; }
                $agac = birimAgaci($db, $birimId);
                if (empty($agac)) { echo json_encode(['success' => false, 'message' => 'Birim ağacı bulunamadı.']); break; }

                // Seçilen başvuru ID'leri (seçim sırası korunur) → birim kapsamında olanlar
                $girdiIds = array_values(array_unique(array_filter(
                    array_map('intval', (array)($_POST['basvuru_ids'] ?? [])), fn($v) => $v > 0)));
                if (empty($girdiIds)) { echo json_encode(['success' => false, 'message' => 'Başvuru seçilmedi.']); break; }
                [$wB, $pB] = basvuruBirimKisitWhere($agac,
                    't.CallCenterApiLead_ID', 't.AltBayiPersonel_ID',
                    '(SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID)',
                    't.ReklamLeadFormlari_ID', 't.OlusturanKullanici');
                $phI = implode(',', array_fill(0, count($girdiIds), '?'));
                $rows = $db->fetchAll("
                    SELECT t.Basvurular_id, t.Basvurular_AtananKullanici_ID FROM Basvurular t
                    WHERE t.Basvurular_id IN ($phI) AND $wB", array_merge($girdiIds, $pB));
                $mevcutAtanan = [];
                foreach ($rows as $r) $mevcutAtanan[(int)$r['Basvurular_id']] = $r['Basvurular_AtananKullanici_ID'] !== null ? (int)$r['Basvurular_AtananKullanici_ID'] : null;
                $havuz = array_values(array_filter($girdiIds, fn($id) => array_key_exists($id, $mevcutAtanan)));
                if (empty($havuz)) { echo json_encode(['success' => false, 'message' => 'Seçilen başvurular biriminizin kapsamında değil.']); break; }
                $kapsamDisi = count($girdiIds) - count($havuz);

                // Kara listedekileri hariç tut (anahtar varsayılan açık)
                $karaListeAtlanan = 0;
                if (($_POST['karaliste_haric'] ?? '1') === '1') {
                    $phH = implode(',', array_fill(0, count($havuz), '?'));
                    $klIds = array_map(fn($r) => (int)$r['Basvurular_id'], $db->fetchAll("
                        SELECT t.Basvurular_id FROM Basvurular t
                        WHERE t.Basvurular_id IN ($phH) AND " . karaListeEslesmeSql(), $havuz));
                    if ($klIds) {
                        $havuz = array_values(array_diff($havuz, $klIds));
                        $karaListeAtlanan = count($klIds);
                    }
                    if (empty($havuz)) { echo json_encode(['success' => false, 'message' => 'Seçilen başvuruların tamamı kara listede.']); break; }
                }

                // Aday kişiler: birim ağacındaki tüm aktif kullanıcılar
                $phB = implode(',', array_fill(0, count($agac), '?'));
                $kisiler = $db->fetchAll("
                    SELECT k.kullanici_id AS id,
                           LTRIM(RTRIM(CONCAT(k.kullanici_ad, ' ', k.kullanici_soyad))) AS ad,
                           d.departman_adi AS departman
                    FROM kullanicilar k
                    LEFT JOIN kullanici_Departmanlar d ON d.departman_id = k.kullanici_departman_id
                    WHERE k.kullanici_durum = 1 AND k.kullanici_birim_id IN ($phB)
                    ORDER BY k.kullanici_ad, k.kullanici_soyad", $agac);

                if ($action === 'dagit_manuel_onizleme') {
                    $atanmis = count(array_filter($havuz, fn($id) => $mevcutAtanan[$id] !== null));
                    echo json_encode([
                        'success'  => true,
                        'bekleyen' => count($havuz),
                        'kisiler'  => $kisiler,
                        'profilAd' => 'Manuel Dağıtım',
                        'aciklama' => count($havuz) . ' seçili başvuru (' . $atanmis . ' tanesi zaten atanmış, yeniden atanacak)'
                                    . ($kapsamDisi > 0 ? ' — ' . $kapsamDisi . ' kayıt birim kapsamı dışında, atlandı' : '')
                                    . ($karaListeAtlanan > 0 ? ' — ' . $karaListeAtlanan . ' kayıt kara listede, atlandı' : ''),
                    ]);
                    break;
                }

                // Kişi başı adet + kişi doğrulaması (birim kapsamı — POST manipülasyonuna karşı)
                $gecerliKisi = array_flip(array_map(fn($k) => (int)$k['id'], $kisiler));
                $adetler = [];
                foreach ((array)($_POST['adetler'] ?? []) as $kid => $ad) {
                    $kid = (int)$kid; $ad = (int)$ad;
                    if ($kid > 0 && $ad > 0 && isset($gecerliKisi[$kid])) $adetler[$kid] = $ad;
                }
                if (empty($adetler)) { echo json_encode(['success' => false, 'message' => 'En az bir geçerli kişiye adet girin.']); break; }
                if (array_sum($adetler) > count($havuz)) { echo json_encode(['success' => false, 'message' => 'Girilen toplam, seçili başvuru sayısından fazla.']); break; }

                $gruplar = [];
                $ofset = 0;
                foreach ($adetler as $kid => $ad) {
                    $dilim = array_slice($havuz, $ofset, $ad);
                    if (!empty($dilim)) { $gruplar[$kid] = $dilim; $ofset += count($dilim); }
                }

                $now = date('Y-m-d H:i:s');
                $toplam = 0;
                $db->execute("BEGIN TRANSACTION");
                try {
                    foreach ($gruplar as $kid => $ids) {
                        $ph = implode(',', array_fill(0, count($ids), '?'));
                        $db->execute("
                            UPDATE Basvurular
                            SET Basvurular_AtananKullanici_ID = ?, Basvurular_AtamaTarihi = ?,
                                GuncelleyenKullanici = ?, GuncellemeTarihi = ?
                            WHERE Basvurular_id IN ($ph)",
                            array_merge([$kid, $now, $kullaniciId, $now], $ids));
                        foreach ($ids as $bid) {
                            basvuruLogKaydet($db, $bid, 'GUNCELLE',
                                ['Basvurular_AtananKullanici_ID' => $mevcutAtanan[$bid]],
                                ['Basvurular_AtananKullanici_ID' => $kid, 'Basvurular_AtamaTarihi' => $now],
                                $kullaniciId, 'Manuel dağıtım ile ' . ($mevcutAtanan[$bid] === null ? 'atandı' : 'yeniden atandı'));
                        }
                        $toplam += count($ids);
                    }
                    $db->execute("COMMIT");
                } catch (Throwable $e) {
                    $db->execute("ROLLBACK");
                    echo json_encode(['success' => false, 'message' => 'Dağıtım hatası: ' . $e->getMessage()]);
                    break;
                }
                echo json_encode(['success' => true, 'message' => "$toplam başvuru, " . count($gruplar) . " kişiye manuel dağıtıldı."]);
                break;
            }

            case 'list':
                // ── DataTables server-side parametreleri ──
                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 200) $length = 25; // aşırı/"tümü" isteklerine tavan

                $search     = trim($_POST['search']['value'] ?? '');
                $durum      = $_POST['f_basvuru_durum'] ?? '';
                $surecDurum = $_POST['f_surec_durum']   ?? '';
                $iletisimD  = $_POST['f_iletisim_durum'] ?? '';
                $kampanya   = $_POST['f_kampanya']      ?? '';
                $birim      = $_POST['f_birim']         ?? '';
                $personelF  = $_POST['f_personel']      ?? '';
                $sayfaF     = $_POST['f_sayfa']         ?? '';
                $atananF    = $_POST['f_atanan']        ?? '';
                $leadFormF  = $_POST['f_lead_formu']    ?? '';
                $tarihBas   = trim($_POST['f_tarih_bas'] ?? '');
                $tarihBit   = trim($_POST['f_tarih_bit'] ?? '');

                // ── Temel (yetki) kısıt — her zaman uygulanır ──
                $whereBase  = ["1=1"];
                $paramsBase = [];
                if ($birimKisitli) {
                    if (empty($izinliBirimler)) {
                        echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
                        break;
                    }
                    [$wBirim, $pBirim] = basvuruBirimKisitWhere(
                        $izinliBirimler,
                        't.CallCenterApiLead_ID',
                        't.AltBayiPersonel_ID',
                        '(SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID)',
                        't.ReklamLeadFormlari_ID',
                        't.OlusturanKullanici'
                    );
                    $whereBase[]  = $wBirim;
                    $paramsBase   = array_merge($paramsBase, $pBirim);
                }
                if ($kendiKisitli) {
                    $whereBase[]  = "(t.OlusturanKullanici = ? OR t.Basvurular_AtananKullanici_ID = ?)";
                    $paramsBase[] = $kullaniciId;
                    $paramsBase[] = $kullaniciId;
                }

                // ── Kullanıcı filtreleri (temel kısıt üstüne) ──
                $whereFilt  = $whereBase;
                $paramsFilt = $paramsBase;
                if ($search !== '') {
                    [$wAra, $pAra] = basvuruAramaWhere($search);
                    $whereFilt[] = $wAra;
                    $paramsFilt  = array_merge($paramsFilt, $pAra);
                }
                if ($durum !== '')      { $whereFilt[] = "t.BasvuruDurum_ID = ?";      $paramsFilt[] = (int)$durum; }
                if ($surecDurum !== '') { $whereFilt[] = "t.BasvuruSurecDurum_ID = ?"; $paramsFilt[] = (int)$surecDurum; }
                if ($iletisimD !== '')  { $whereFilt[] = "t.Basvurular_IletisimDurum_ID = ?"; $paramsFilt[] = (int)$iletisimD; }
                if ($kampanya !== '')   { $whereFilt[] = "t.Kampanyalar_ID = ?";       $paramsFilt[] = (int)$kampanya; }
                if ($personelF !== '')  { $whereFilt[] = "t.AltBayiPersonel_ID = ?";   $paramsFilt[] = (int)$personelF; }
                // Atanan kullanıcı: '0' → atanmamış (NULL), pozitif id → belirli kullanıcı
                if ($atananF === '0')      { $whereFilt[] = "t.Basvurular_AtananKullanici_ID IS NULL"; }
                elseif ($atananF !== '')   { $whereFilt[] = "t.Basvurular_AtananKullanici_ID = ?"; $paramsFilt[] = (int)$atananF; }
                // Lead formu (çoklu, virgülle): 'yok' → reklamdan gelmeyen, pozitif id'ler → seçili lead formları
                $lfSecim = array_filter(array_map('trim', explode(',', (string)$leadFormF)), 'strlen');
                $lfIds   = array_values(array_unique(array_map('intval', array_filter($lfSecim, 'ctype_digit'))));
                $lfKosul = [];
                if (in_array('yok', $lfSecim, true)) { $lfKosul[] = "t.ReklamLeadFormlari_ID IS NULL"; }
                if ($lfIds) { $lfKosul[] = "t.ReklamLeadFormlari_ID IN (" . implode(',', $lfIds) . ")"; }
                if ($lfKosul) { $whereFilt[] = '(' . implode(' OR ', $lfKosul) . ')'; }
                // datetime-local: "YYYY-MM-DDTHH:MM" → "YYYY-MM-DD HH:MM:SS"
                if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $tarihBas)) {
                    $whereFilt[]  = "t.OlusturmaTarihi >= ?";
                    $paramsFilt[] = str_replace('T', ' ', $tarihBas) . ':00';
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $tarihBit)) {
                    $whereFilt[]  = "t.OlusturmaTarihi <= ?";
                    $paramsFilt[] = str_replace('T', ' ', $tarihBit) . ':59';
                }
                if ($sayfaF !== '') {
                    $whereFilt[] = "EXISTS (
                        SELECT 1 FROM ReklamLeadFormlari f
                        WHERE f.ReklamLeadFormlari_id = t.ReklamLeadFormlari_ID
                          AND f.ReklamLeadFormlari_Sayfa_id = ?)";
                    $paramsFilt[] = (int)$sayfaF;
                }
                if ($birim !== '') {
                    $whereFilt[] = "(EXISTS (
                        SELECT 1 FROM KullaniciBirimYetkileri kbf
                        WHERE kbf.KullaniciBirimYetkileri_Birim_id = ? AND kbf.Durum = 1
                          AND (kbf.KullaniciBirimYetkileri_Personel_id = t.AltBayiPersonel_ID
                            OR kbf.KullaniciBirimYetkileri_AltBayi_id = (SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID)
                            OR kbf.KullaniciBirimYetkileri_ApiLead_id = t.CallCenterApiLead_ID))
                      OR EXISTS (
                        SELECT 1 FROM ReklamLeadFormlari f
                        INNER JOIN KullaniciBirimYetkileri kbf2
                                ON kbf2.KullaniciBirimYetkileri_ReklamSayfa_id = f.ReklamLeadFormlari_Sayfa_id
                        WHERE kbf2.KullaniciBirimYetkileri_Birim_id = ? AND kbf2.Durum = 1
                          AND f.ReklamLeadFormlari_id = t.ReklamLeadFormlari_ID)
                      OR (t.AltBayiPersonel_ID IS NULL AND t.CallCenterApiLead_ID IS NULL AND t.ReklamLeadFormlari_ID IS NULL
                          AND EXISTS (SELECT 1 FROM kullanicilar kuc WHERE kuc.kullanici_id = t.OlusturanKullanici AND kuc.kullanici_birim_id = ?)))";
                    $paramsFilt[] = (int)$birim;
                    $paramsFilt[] = (int)$birim;
                    $paramsFilt[] = (int)$birim;
                }

                $wBaseClause = implode(" AND ", $whereBase);
                $wFiltClause = implode(" AND ", $whereFilt);

                // Sayımlar (JOIN'siz, yalnız Basvurular t — hızlı)
                $recordsTotal    = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM Basvurular t WHERE $wBaseClause", $paramsBase)['c'] ?? 0);
                $recordsFiltered = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM Basvurular t WHERE $wFiltClause", $paramsFilt)['c'] ?? 0);

                // ── Sıralama (kolon index → güvenli whitelist) ──
                // Kolon 0 = seçim checkbox'ı (orderable değil); veri kolonları 1'den başlar
                $orderMap = [
                    1 => 'birim.BirimAdi',
                    2 => 'p.DigiturkAltBayiPersonel_AdSoyad',
                    3 => 't.Isim',
                    4 => 't.TCKimlikNo',
                    5 => 't.phoneNumber',
                    6 => 'kmp.APIKampanyalar_Ad',
                    7 => 'bd.BasvuruDurum_Mesaj',
                    8 => 't.BasvuruDurumMesaj',
                    9 => 'sd.BasvuruSurecDurum_Mesaj',
                    10 => 'idr.BasvuruIletisimDurum_Mesaj',
                    11 => 't.OlusturmaTarihi',
                    12 => 'ak.kullanici_ad',
                    13 => 't.ReklamLeadFormlari_ID',
                ];
                $orderIdx = (int)($_POST['order'][0]['column'] ?? 11);
                $orderBy  = $orderMap[$orderIdx] ?? 't.OlusturmaTarihi';
                $orderDir = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

                // Birim adı+rengini TEK seferde çözen APPLY (öncelik: Personel/Bayi/Lead → LeadFormu → OluşturanKullanici).
                // Eskiden BirimAdi ve BirimRenk için ayrı ayrı 3'er correlated subquery vardı; burada tek geçişte hem ad hem renk.
                $birimApplySql = "
                    OUTER APPLY (
                        SELECT TOP 1 x.Adi AS BirimAdi, x.Renk AS BirimRenk
                        FROM (
                            SELECT 1 AS oncelik, b.KullaniciBirim_Adi AS Adi, b.KullaniciBirim_Renk AS Renk
                              FROM KullaniciBirimYetkileri kby
                              INNER JOIN KullaniciBirim b ON b.KullaniciBirim_id = kby.KullaniciBirimYetkileri_Birim_id
                              WHERE kby.Durum = 1
                                AND (kby.KullaniciBirimYetkileri_Personel_id = t.AltBayiPersonel_ID
                                  OR kby.KullaniciBirimYetkileri_AltBayi_id = (SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID)
                                  OR kby.KullaniciBirimYetkileri_ApiLead_id = t.CallCenterApiLead_ID)
                            UNION ALL
                            SELECT 2, b2.KullaniciBirim_Adi, b2.KullaniciBirim_Renk
                              FROM ReklamLeadFormlari f
                              INNER JOIN KullaniciBirimYetkileri kby2
                                      ON kby2.KullaniciBirimYetkileri_ReklamSayfa_id = f.ReklamLeadFormlari_Sayfa_id AND kby2.Durum = 1
                              INNER JOIN KullaniciBirim b2 ON b2.KullaniciBirim_id = kby2.KullaniciBirimYetkileri_Birim_id
                              WHERE f.ReklamLeadFormlari_id = t.ReklamLeadFormlari_ID
                            UNION ALL
                            SELECT 3, kbc.KullaniciBirim_Adi, kbc.KullaniciBirim_Renk
                              FROM kullanicilar kuc
                              INNER JOIN KullaniciBirim kbc ON kbc.KullaniciBirim_id = kuc.kullanici_birim_id
                              WHERE kuc.kullanici_id = t.OlusturanKullanici
                        ) x
                        ORDER BY x.oncelik
                    ) birim";
                // BirimAdi'ye göre sıralanıyorsa APPLY sayfalama CTE'sine de gerekir; değilse CTE hafif kalsın.
                $cteBirimApply = ($orderBy === 'birim.BirimAdi') ? $birimApplySql : '';

                $data = [];
                if ($recordsFiltered > 0) {
                    // 1) Sayfa CTE'si: pahalı APPLY/subquery YOK — yalnız filtre + sıralama + OFFSET/FETCH ile 25 ID.
                    // 2) Dış SELECT: ağır alt sorgular (birim, mükerrer) yalnız bu 25 satıra uygulanır.
                    $data = $db->fetchAll("
                        WITH Sayfa AS (
                            SELECT t.Basvurular_id
                            FROM Basvurular t
                            LEFT JOIN APIKampanyalar         kmp ON kmp.APIKampanyalar_Id        = t.Kampanyalar_ID
                            LEFT JOIN BasvuruDurum           bd  ON bd.BasvuruDurum_id           = t.BasvuruDurum_ID
                            LEFT JOIN BasvuruSurecDurum      sd  ON sd.BasvuruSurecDurum_id      = t.BasvuruSurecDurum_ID
                            LEFT JOIN BasvuruIletisimDurum   idr ON idr.BasvuruIletisimDurum_id = t.Basvurular_IletisimDurum_ID
                            LEFT JOIN DigiturkAltBayiPersonel p  ON p.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID
                            LEFT JOIN kullanicilar           ak  ON ak.kullanici_id             = t.Basvurular_AtananKullanici_ID
                            $cteBirimApply
                            WHERE $wFiltClause
                            ORDER BY $orderBy $orderDir, t.Basvurular_id DESC
                            OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
                        )
                        SELECT
                            t.Basvurular_id, t.Isim, t.Soyisim, t.TCKimlikNo,
                            t.ReklamLeadFormlari_ID AS LeadFormuID,
                            lf.ReklamLeadFormlari_FormAdi AS LeadFormuAdi,
                            t.phoneCountryNumber, t.phoneAreaNumber, t.phoneNumber,
                            muk.MukerrerAdet,
                            kl.KaraListe_Tur      AS KaraListeTur,
                            kl.KaraListe_Aciklama AS KaraListeAciklama,
                            kmp.APIKampanyalar_Ad      AS KampanyaAdi,
                            bd.BasvuruDurum_Mesaj      AS DurumMesaj,
                            bd.BasvuruDurum_Renk       AS DurumRenk,
                            sd.BasvuruSurecDurum_Mesaj AS SurecMesaj,
                            sd.BasvuruSurecDurum_Renk  AS SurecRenk,
                            t.BasvuruDurumMesaj        AS BasvuruDurumMesaj,
                            idr.BasvuruIletisimDurum_Mesaj AS IletisimDurumMesaj,
                            birim.BirimAdi,
                            birim.BirimRenk,
                            p.DigiturkAltBayiPersonel_AdSoyad AS PersonelAdi,
                            t.Basvurular_AtananKullanici_ID AS AtananID,
                            ak.kullanici_ad    AS AtananAd,
                            ak.kullanici_soyad AS AtananSoyad,
                            t.TalepKayitNo,
                            t.Basvurular_OtpDurum AS OtpDurum,
                            t.Basvurular_OtpSonMesaj AS OtpSonMesaj,
                            CONVERT(VARCHAR(19), t.Basvurular_OtpOnayTarihi, 120)     AS OtpOnayTarihi,
                            CONVERT(VARCHAR(19), t.Basvurular_OtpGonderimTarihi, 120) AS OtpGonderimTarihi,
                            CONVERT(VARCHAR(19), t.OlusturmaTarihi, 120) AS OlusturmaTarihi
                        FROM Sayfa s
                        INNER JOIN Basvurular t ON t.Basvurular_id = s.Basvurular_id
                        LEFT JOIN APIKampanyalar         kmp ON kmp.APIKampanyalar_Id        = t.Kampanyalar_ID
                        LEFT JOIN BasvuruDurum           bd  ON bd.BasvuruDurum_id           = t.BasvuruDurum_ID
                        LEFT JOIN BasvuruSurecDurum      sd  ON sd.BasvuruSurecDurum_id      = t.BasvuruSurecDurum_ID
                        LEFT JOIN BasvuruIletisimDurum   idr ON idr.BasvuruIletisimDurum_id = t.Basvurular_IletisimDurum_ID
                        LEFT JOIN DigiturkAltBayiPersonel p  ON p.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID
                        LEFT JOIN kullanicilar           ak  ON ak.kullanici_id             = t.Basvurular_AtananKullanici_ID
                        LEFT JOIN ReklamLeadFormlari lf ON lf.ReklamLeadFormlari_id = t.ReklamLeadFormlari_ID
                        $birimApplySql
                        OUTER APPLY (
                            SELECT COUNT(*) AS MukerrerAdet
                            FROM Basvurular dd
                            WHERE dd.phoneNumber = t.phoneNumber
                              AND ISNULL(dd.phoneAreaNumber,'')    = ISNULL(t.phoneAreaNumber,'')
                              AND ISNULL(dd.phoneCountryNumber,'') = ISNULL(t.phoneCountryNumber,'')
                              AND t.phoneNumber IS NOT NULL AND t.phoneNumber <> ''
                        ) muk
                        -- Kara liste: GSM 90XXXXXXXXXX biçiminde (KaraListe::normalizeGsm), TC düz eşleşir; GSM önceliklidir
                        CROSS APPLY (
                            SELECT REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                                CONCAT(ISNULL(t.phoneCountryNumber,''), ISNULL(t.phoneAreaNumber,''), ISNULL(t.phoneNumber,'')),
                                '+',''),' ',''),'-',''),'(',''),')','') AS TelRakam
                        ) tr
                        OUTER APPLY (
                            SELECT TOP 1 k.KaraListe_Tur, k.KaraListe_Aciklama
                            FROM KaraListe k
                            WHERE k.Durum = 1
                              AND (k.KaraListe_BaslangicTarihi IS NULL OR k.KaraListe_BaslangicTarihi <= GETDATE())
                              AND (k.KaraListe_BitisTarihi     IS NULL OR k.KaraListe_BitisTarihi     >= GETDATE())
                              AND (
                                    (k.KaraListe_Tur = 'gsm' AND LEN(tr.TelRakam) >= 10 AND k.KaraListe_Deger = '90' + RIGHT(tr.TelRakam, 10))
                                 OR (k.KaraListe_Tur = 'tc'  AND t.TCKimlikNo IS NOT NULL AND k.KaraListe_Deger = t.TCKimlikNo)
                              )
                            ORDER BY CASE k.KaraListe_Tur WHEN 'gsm' THEN 0 ELSE 1 END
                        ) kl
                        ORDER BY $orderBy $orderDir, t.Basvurular_id DESC
                    ", $paramsFilt);
                }

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $data,
                ]);
                break;

            case 'delete':
                if (!$permissions['can_delete']) {
                    echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok!']);
                    break;
                }
                $id = (int)($_POST['id'] ?? 0);
                if ($birimKisitli && !basvuruKayitBirimYetkiliMi($db, $izinliBirimler, $id)) {
                    echo json_encode(['success' => false, 'message' => 'Bu kayda erişim yetkiniz yok!']);
                    break;
                }
                if ($kendiKisitli && !basvuruKayitKendiMi($db, $kullaniciId, $id)) {
                    echo json_encode(['success' => false, 'message' => 'Bu kayda erişim yetkiniz yok!']);
                    break;
                }
                // LOG: silmeden önce tam kaydı al
                $logOncesi = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$id]);
                $result = $db->delete('Basvurular', ['Basvurular_id' => $id]);
                if ($result && $logOncesi) {
                    basvuruLogKaydet($db, $id, 'SIL', $logOncesi, null, $user['kullanici_id'], 'Başvuru listeden silindi');
                }
                echo json_encode(['success' => (bool)$result, 'message' => $result ? 'Başvuru silindi' : 'Silme hatası']);
                break;

            // Numara sildirme talebi (çoklu): seçili başvuruların Ad Soyad + TC + Telefon bilgisi
            // tek e-posta / tek WhatsApp mesajı olarak gider.
            // onizle=1 → yalnız kayıtları ve eksikleri döner. zorla=1 → eksik alan olsa da gönderir.
            case 'uye_sildirme': {
                if (!$uyeSildirmeGoster) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok!']); break; }
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
                if (!$ids)             { echo json_encode(['success' => false, 'message' => 'En az bir kayıt seçin.']); break; }
                if (count($ids) > 200) { echo json_encode(['success' => false, 'message' => 'Tek seferde en fazla 200 kayıt gönderilebilir.']); break; }

                $yetkisiz = false;
                foreach ($ids as $id) {
                    if (($birimKisitli && !basvuruKayitBirimYetkiliMi($db, $izinliBirimler, $id))
                        || ($kendiKisitli && !basvuruKayitKendiMi($db, $kullaniciId, $id))) { $yetkisiz = true; break; }
                }
                if ($yetkisiz) { echo json_encode(['success' => false, 'message' => 'Seçili kayıtlardan birine erişim yetkiniz yok!']); break; }

                $ph = implode(',', array_fill(0, count($ids), '?'));
                $rows = $db->fetchAll("
                    SELECT Basvurular_id,
                           LTRIM(RTRIM(CONCAT(ISNULL(Isim, ''), ' ', ISNULL(Soyisim, '')))) AS AdSoyad,
                           LTRIM(RTRIM(ISNULL(TCKimlikNo, ''))) AS TC,
                           LTRIM(RTRIM(CONCAT(ISNULL(phoneCountryNumber,''), ISNULL(phoneAreaNumber,''), ISNULL(phoneNumber,'')))) AS Telefon
                    FROM Basvurular WHERE Basvurular_id IN ($ph)
                    ORDER BY Basvurular_id", $ids);
                if (!$rows) { echo json_encode(['success' => false, 'message' => 'Başvuru bulunamadı!']); break; }

                $kayitlar = [];
                $eksikVar = false;
                foreach ($rows as $r0) {
                    $eksik = [];
                    if ($r0['AdSoyad'] === '') $eksik[] = 'İsim Soyisim';
                    if ($r0['TC'] === '')      $eksik[] = 'TC Kimlik';
                    if ($r0['Telefon'] === '') $eksik[] = 'Telefon';
                    if ($eksik) $eksikVar = true;
                    $kayitlar[] = ['id' => (int)$r0['Basvurular_id'], 'AdSoyad' => $r0['AdSoyad'], 'TC' => $r0['TC'], 'Telefon' => $r0['Telefon'], 'eksik' => $eksik];
                }

                if (($_POST['onizle'] ?? '0') === '1') {
                    echo json_encode(['success' => true, 'kayitlar' => $kayitlar, 'eksikVar' => $eksikVar]); break;
                }
                if ($eksikVar && ($_POST['zorla'] ?? '0') !== '1') {
                    echo json_encode(['success' => false, 'eksikVar' => true, 'message' => 'Seçili kayıtlarda eksik alan var.']); break;
                }

                $gonderen = trim((string)($user['name'] ?? '')) ?: (string)($user['kullanici_email'] ?? '');
                $kanal    = $_POST['kanal'] ?? '';
                $adet     = count($kayitlar);

                if ($kanal === 'whatsapp') {
                    if (!$uyeSildirmeWhatsappAktif) { echo json_encode(['success' => false, 'message' => 'WhatsApp gönderimi şu anda pasif.']); break; }
                    $waKanallari = EntegrasyonHelper::aktifKanallar('whatsapp');
                    if (empty($waKanallari)) { echo json_encode(['success' => false, 'message' => 'Aktif WhatsApp kanalı bulunamadı!']); break; }
                    $mesaj = "*Numara Silme Talebi* ({$adet} kayıt)\n";
                    foreach ($kayitlar as $n => $k) {
                        $mesaj .= "\n" . ($n + 1) . ") İsim Soyisim: " . ($k['AdSoyad'] ?: '-')
                                . "\n   TC Kimlik: " . ($k['TC'] ?: '-')
                                . "\n   Telefon: "   . ($k['Telefon'] ?: '-') . "\n";
                    }
                    $mesaj .= "\nTalep eden: {$gonderen}";
                    $r = EntegrasyonHelper::whatsappGonder((int)$waKanallari[0]['EntegrasyonKanallari_id'], $uyeSildirmeWhatsappGrup, $mesaj, $kullaniciId);
                    if ($r['success']) {
                        foreach ($kayitlar as $k) basvuruLogKaydet($db, $k['id'], 'NUMARA_SIL', null, null, $kullaniciId, 'Numara sildirme talebi WhatsApp ile gönderildi');
                    }
                    echo json_encode(['success' => $r['success'], 'message' => $r['success'] ? "{$adet} kayıt WhatsApp grubuna gönderildi." : $r['message']]);
                    break;
                }

                if ($kanal === 'email') {
                    $emailKanallari = EntegrasyonHelper::aktifKanallar('email');
                    if (empty($emailKanallari)) { echo json_encode(['success' => false, 'message' => 'Aktif e-posta kanalı bulunamadı!']); break; }
                    $h  = fn($v) => htmlspecialchars($v !== '' ? $v : '-', ENT_QUOTES, 'UTF-8');
                    $td = 'style="padding:6px 12px;border:1px solid #ddd"';
                    $govde = "<p>Merhaba,</p><p>Aşağıdaki " . ($adet > 1 ? "{$adet} numaranın" : "numaranın") . " silinmesini rica ederiz.</p>"
                           . "<table style=\"border-collapse:collapse\"><tr>"
                           . "<th {$td} align=\"left\">#</th><th {$td} align=\"left\">İsim Soyisim</th>"
                           . "<th {$td} align=\"left\">TC Kimlik</th><th {$td} align=\"left\">Telefon</th></tr>";
                    foreach ($kayitlar as $n => $k) {
                        $govde .= "<tr><td {$td}>" . ($n + 1) . "</td><td {$td}>" . $h($k['AdSoyad']) . "</td>"
                                . "<td {$td}>" . $h($k['TC']) . "</td><td {$td}>" . $h($k['Telefon']) . "</td></tr>";
                    }
                    $govde .= "</table><p>Talep eden: " . htmlspecialchars($gonderen, ENT_QUOTES, 'UTF-8') . "</p>";

                    $konu = $adet === 1
                        ? 'Numara Silme Talebi - ' . ($kayitlar[0]['AdSoyad'] ?: ('Başvuru #' . $kayitlar[0]['id']))
                        : "Numara Silme Talebi - {$adet} Kayıt";
                    $kime = array_map(fn($m) => [$m, ''], $uyeSildirmeEmailAlicilar);
                    $cc   = !empty($user['kullanici_email']) ? [$user['kullanici_email']] : [];
                    $r = EntegrasyonHelper::emailGonder((int)$emailKanallari[0]['EntegrasyonKanallari_id'], $kime, $konu, $govde, true, $kullaniciId, $cc);
                    if ($r['success']) {
                        foreach ($kayitlar as $k) basvuruLogKaydet($db, $k['id'], 'NUMARA_SIL', null, null, $kullaniciId, 'Numara sildirme talebi e-posta ile gönderildi');
                    }
                    echo json_encode(['success' => $r['success'], 'message' => $r['success'] ? "{$adet} kayıt e-posta ile gönderildi." : ('E-posta gönderilemedi: ' . $r['message'])]);
                    break;
                }

                echo json_encode(['success' => false, 'message' => 'Geçersiz gönderim kanalı!']);
                break;
            }

            case 'otp_sorgu': {
                $id = (int)($_POST['id'] ?? 0);
                if ($birimKisitli && !basvuruKayitBirimYetkiliMi($db, $izinliBirimler, $id)) {
                    echo json_encode(['success' => false, 'message' => 'Bu kayda erişim yetkiniz yok!']); break;
                }
                if ($kendiKisitli && !basvuruKayitKendiMi($db, $kullaniciId, $id)) {
                    echo json_encode(['success' => false, 'message' => 'Bu kayda erişim yetkiniz yok!']); break;
                }
                $b = $db->fetchOne("SELECT * FROM Basvurular WHERE Basvurular_id = ?", [$id]);
                if (!$b) { echo json_encode(['success' => false, 'message' => 'Başvuru bulunamadı!']); break; }

                // ── Zaten onaylı ise API'ye gitme, tekrar SMS/kod tetikleme ──
                if (($b['Basvurular_OtpDurum'] ?? '') === 'onayli') {
                    echo json_encode([
                        'success' => true,
                        'durum'   => 'onayli',
                        'url'     => null,
                        'message' => 'OTP zaten onaylı — yeni istek gönderilmedi'
                            . (!empty($b['Basvurular_OtpOnayTarihi']) ? ' (' . $b['Basvurular_OtpOnayTarihi'] . ')' : ''),
                    ]);
                    break;
                }

                // Aktif OTP kanalı
                $otpKanal = $db->fetchOne("
                    SELECT TOP 1 k.EntegrasyonKanallari_id
                    FROM EntegrasyonKanallari k
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE e.Entegrasyonlar_Tip = 'OTP' AND k.Durum = 1 AND e.Durum = 1
                    ORDER BY k.EntegrasyonKanallari_id");
                $otpKanalId = (int)($otpKanal['EntegrasyonKanallari_id'] ?? 0);
                if (!$otpKanalId) { echo json_encode(['success' => false, 'message' => 'Aktif OTP kanalı bulunamadı!']); break; }

                $gsm = preg_replace('/\D/', '', ($b['phoneCountryNumber'] ?? '') . ($b['phoneAreaNumber'] ?? '') . ($b['phoneNumber'] ?? ''));
                if (strlen($gsm) !== 12 || substr($gsm, 0, 2) !== '90') {
                    echo json_encode(['success' => false, 'message' => "GSM formatı hatalı ($gsm). 90XXXXXXXXXX olmalı."]); break;
                }

                // Sınırsız kural: aynı GSM başka kayıtta onaylıysa API'ye gitme, bu kaydı da onayla
                $onceOnay = EntegrasyonHelper::gsmDahaOnceOnayli($gsm);
                if ($onceOnay && (int)$onceOnay['Basvurular_id'] !== $id) {
                    $now = date('Y-m-d H:i:s');
                    $db->update('Basvurular', [
                        'Basvurular_OtpDurum'      => 'onayli',
                        'Basvurular_OtpOnayTarihi' => $onceOnay['Basvurular_OtpOnayTarihi'] ?: $now,
                        'Basvurular_OtpSonMesaj'   => 'Daha önce onaylı GSM — SMS gönderilmedi',
                        'GuncellemeTarihi'         => $now,
                        'GuncelleyenKullanici'     => $user['kullanici_id'],
                    ], ['Basvurular_id' => $id]);
                    echo json_encode(['success' => true, 'durum' => 'onayli', 'url' => null,
                        'message' => 'Daha önce onaylı GSM — SMS gönderilmedi']);
                    break;
                }

                // processType: mevcut kanal tipi varsa onu, yoksa Online (3)
                $kanalTipId = (int)($b['Basvurular_OtpKanalTipi_id'] ?? 0);
                if ($kanalTipId) {
                    $kt  = $db->fetchOne("SELECT EntegrasyonKanalTipleri_Kod FROM EntegrasyonKanalTipleri WHERE EntegrasyonKanalTipleri_id = ?", [$kanalTipId]);
                    $kod = $kt['EntegrasyonKanalTipleri_Kod'] ?? '3';
                } else {
                    $kt = $db->fetchOne("
                        SELECT TOP 1 t.EntegrasyonKanalTipleri_id, t.EntegrasyonKanalTipleri_Kod
                        FROM EntegrasyonKanalTipleri t
                        INNER JOIN Entegrasyonlar e ON t.EntegrasyonKanalTipleri_Entegrasyon_id = e.Entegrasyonlar_id
                        WHERE e.Entegrasyonlar_Tip = 'OTP' AND t.EntegrasyonKanalTipleri_Kod = '3'");
                    $kanalTipId = (int)($kt['EntegrasyonKanalTipleri_id'] ?? 0);
                    $kod        = $kt['EntegrasyonKanalTipleri_Kod'] ?? '3';
                }

                // onay modu: smsFormat=false → müşteriye SMS gitmez, onay URL'i response'da döner (agent modalda açar)
                $onayModu = !empty($_POST['onay']);
                if ($onayModu) {
                    $res = EntegrasyonHelper::digiturkBasvuruGonder($otpKanalId, $gsm, $kod, [], false, $user['kullanici_id'], $id);
                } else {
                    // $id ZORUNLU: redirectUrl'e bid olarak girer, onay dönüşü bu sayede yakalanır
                    $res = EntegrasyonHelper::digiturkDurumSorgula($otpKanalId, $gsm, $kod, $user['kullanici_id'], $id);
                }
                if ($res['durum'] === 'hata') { echo json_encode(['success' => false, 'message' => 'Digiturk: ' . $res['mesaj']]); break; }

                // Digiturk isteği sürerken (30 sn'ye kadar) müşteri onaylamış olabilir; redirect
                // webhook'u kaydı 'onayli' yapar. Baştaki $b bayat olduğu için kaydı TAZE oku:
                // 'onayli' durum hiçbir koşulda 'beklemede'ye düşürülmez.
                $son      = $db->fetchOne("SELECT Basvurular_OtpDurum, Basvurular_OtpOnayTarihi FROM Basvurular WHERE Basvurular_id = ?", [$id]);
                $sonDurum = (string)($son['Basvurular_OtpDurum'] ?? '');
                if ($sonDurum === 'onayli' && $res['durum'] !== 'onayli') {
                    echo json_encode([
                        'success' => true,
                        'durum'   => 'onayli',
                        'url'     => null,
                        'message' => 'OTP bu sırada onaylandı — durum korundu'
                            . (!empty($son['Basvurular_OtpOnayTarihi']) ? ' (' . $son['Basvurular_OtpOnayTarihi'] . ')' : ''),
                    ]);
                    break;
                }

                $now = date('Y-m-d H:i:s');
                $upd = [
                    'Basvurular_OtpDurum'      => ($res['durum'] === 'onayli' ? 'onayli' : 'beklemede'),
                    'Basvurular_OtpOnayTarihi' => $res['onayTarihi'] ?: ($b['Basvurular_OtpOnayTarihi'] ?? null),
                    'Basvurular_OtpSonMesaj'   => mb_substr((string)$res['mesaj'], 0, 200),
                    'GuncellemeTarihi'         => $now,
                    'GuncelleyenKullanici'     => $user['kullanici_id'],
                ];
                if (empty($b['Basvurular_OtpGonderimTarihi'])) {
                    $upd['Basvurular_OtpGonderimTarihi'] = $now;
                    $upd['Basvurular_OtpKanal_id']       = $otpKanalId;
                    $upd['Basvurular_OtpKanalTipi_id']   = $kanalTipId;
                }
                $db->update('Basvurular', $upd, ['Basvurular_id' => $id]);

                $etiket = $res['durum'] === 'onayli' ? 'ONAYLI' : 'BEKLEMEDE';
                echo json_encode([
                    'success' => true,
                    'durum'   => $res['durum'],
                    'url'     => $res['url'] ?? null,
                    'message' => "OTP Durum: $etiket ({$res['mesaj']})",
                ]);
                break;
            }

            case 'stats': {
                $wParts  = [];
                $wParams = [];
                if ($birimKisitli) {
                    if (empty($izinliBirimler)) {
                        echo json_encode(['success' => true, 'data' => ['toplam' => 0, 'bugun' => 0, 'atanmamis' => 0, 'ulasilamadi' => 0]]);
                        break;
                    }
                    [$wS, $pS] = basvuruBirimKisitWhere(
                        $izinliBirimler,
                        't.CallCenterApiLead_ID',
                        't.AltBayiPersonel_ID',
                        '(SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID)',
                        't.ReklamLeadFormlari_ID',
                        't.OlusturanKullanici'
                    );
                    $wParts[] = $wS;
                    $wParams  = array_merge($wParams, $pS);
                }
                if ($kendiKisitli) {
                    $wParts[]  = "(t.OlusturanKullanici = ? OR t.Basvurular_AtananKullanici_ID = ?)";
                    $wParams[] = $kullaniciId;
                    $wParams[] = $kullaniciId;
                }
                $wClause = $wParts ? implode(' AND ', $wParts) : '1=1';
                // İnfobox sayaçları dağıtım şablonlarıyla aynı kriteri kullanır (atanmamış + profil tarih/durum)
                $kidemTarih    = dagitimProfili('kidem')['tarihWhere'];
                $kidemsizTarih = dagitimProfili('kidemsiz')['tarihWhere'];
                $stats = [
                    'toplam'    => $db->fetchOne("SELECT COUNT(*) AS c FROM Basvurular t WHERE $wClause", $wParams)['c'] ?? 0,
                    'bugun'     => $db->fetchOne("SELECT COUNT(*) AS c FROM Basvurular t WHERE CAST(t.OlusturmaTarihi AS DATE) = CAST(GETDATE() AS DATE) AND $wClause", $wParams)['c'] ?? 0,
                    'atanmamis' => $db->fetchOne("SELECT COUNT(*) AS c FROM Basvurular t WHERE t.Basvurular_AtananKullanici_ID IS NULL AND ($kidemTarih) AND $wClause", $wParams)['c'] ?? 0,
                    'kidemsiz'  => $db->fetchOne("SELECT COUNT(*) AS c FROM Basvurular t WHERE t.Basvurular_AtananKullanici_ID IS NULL AND ($kidemsizTarih) AND $wClause", $wParams)['c'] ?? 0,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
            }

            // ── Günlük personel kırılımı: her durum değeri için ayrı kutu.
            //    Bugün oluşturulan başvuruları, Atanan Kullanıcı (agent) ve agent'ın
            //    birimi bazında, durum değerine göre gruplar ──
            case 'stats_kirilim': {
                $wParts  = [];
                $wParams = [];
                if ($birimKisitli) {
                    if (empty($izinliBirimler)) {
                        echo json_encode(['success' => true, 'data' => ['basvuru' => [], 'iletisim' => []]]);
                        break;
                    }
                    [$wS, $pS] = basvuruBirimKisitWhere(
                        $izinliBirimler,
                        't.CallCenterApiLead_ID',
                        't.AltBayiPersonel_ID',
                        '(SELECT pp.DigiturkAltBayiPersonel_AltBayiId FROM DigiturkAltBayiPersonel pp WHERE pp.DigiturkAltBayiPersonel_Id = t.AltBayiPersonel_ID)',
                        't.ReklamLeadFormlari_ID'
                    );
                    $wParts[] = $wS;
                    $wParams  = array_merge($wParams, $pS);
                }
                if ($kendiKisitli) {
                    $wParts[]  = "(t.OlusturanKullanici = ? OR t.Basvurular_AtananKullanici_ID = ?)";
                    $wParams[] = $kullaniciId;
                    $wParams[] = $kullaniciId;
                }
                $wExtra = $wParts ? (' AND ' . implode(' AND ', $wParts)) : '';

                // Tablo/kolon adları sabit literal (kullanıcı girdisi değil) — enjeksiyon riski yok.
                // Her durum DEĞERİ için bir kutu; içinde bugüne ait birim → personel → adet.
                $kirilim = function (string $durumTablo, string $durumIdKol, string $mesajKol,
                                     string $renkKol, string $basvuruKol, string $durumWhere)
                                    use ($db, $wExtra, $wParams) {
                    // Tüm durum değerleri (kaydı olmasa da kutu görünsün)
                    $durumlar = $db->fetchAll(
                        "SELECT $durumIdKol AS id, $mesajKol AS ad, $renkKol AS renk
                         FROM $durumTablo $durumWhere ORDER BY $mesajKol");
                    // Bugünkü kırılım (durum + birim + personel)
                    $rows = $db->fetchAll("
                        SELECT t.$basvuruKol AS DurumId,
                               ISNULL(kb.KullaniciBirim_Adi, N'Birimsiz') AS BirimAdi,
                               ISNULL(kb.KullaniciBirim_id, 0)           AS BirimId,
                               LTRIM(RTRIM(CONCAT(k.kullanici_ad, ' ', k.kullanici_soyad))) AS PersonelAd,
                               COUNT(*) AS Adet
                        FROM Basvurular t
                        INNER JOIN kullanicilar   k  ON k.kullanici_id      = t.Basvurular_AtananKullanici_ID
                        LEFT  JOIN KullaniciBirim kb ON kb.KullaniciBirim_id = k.kullanici_birim_id
                        WHERE CAST(t.OlusturmaTarihi AS DATE) = CAST(GETDATE() AS DATE)
                          AND t.$basvuruKol IS NOT NULL
                          $wExtra
                        GROUP BY t.$basvuruKol, kb.KullaniciBirim_Adi, kb.KullaniciBirim_id, k.kullanici_ad, k.kullanici_soyad
                        ORDER BY BirimAdi, Adet DESC, PersonelAd
                    ", $wParams);

                    // durumId → birimId → kişiler
                    $map = [];
                    foreach ($rows as $r) {
                        $did = (int)$r['DurumId'];
                        $bid = (int)$r['BirimId'];
                        if (!isset($map[$did]))       $map[$did] = [];
                        if (!isset($map[$did][$bid])) $map[$did][$bid] = ['birim' => $r['BirimAdi'], 'toplam' => 0, 'kisiler' => []];
                        $map[$did][$bid]['kisiler'][] = ['ad' => $r['PersonelAd'], 'adet' => (int)$r['Adet']];
                        $map[$did][$bid]['toplam']   += (int)$r['Adet'];
                    }

                    $out = [];
                    foreach ($durumlar as $d) {
                        $did      = (int)$d['id'];
                        $birimler = isset($map[$did]) ? array_values($map[$did]) : [];
                        $toplam   = 0;
                        foreach ($birimler as $b) $toplam += $b['toplam'];
                        $out[] = [
                            'durumId'  => $did,
                            'durum'    => $d['ad'],
                            'renk'     => $d['renk'] ?? null,
                            'toplam'   => $toplam,
                            'birimler' => $birimler,
                        ];
                    }
                    return $out;
                };

                echo json_encode([
                    'success' => true,
                    'data'    => [
                        'basvuru'  => $kirilim('BasvuruDurum', 'BasvuruDurum_id', 'BasvuruDurum_Mesaj', 'BasvuruDurum_Renk', 'BasvuruDurum_ID', ''),
                        'iletisim' => $kirilim('BasvuruIletisimDurum', 'BasvuruIletisimDurum_id', 'BasvuruIletisimDurum_Mesaj', 'NULL', 'Basvurular_IletisimDurum_ID', 'WHERE Durum = 1'),
                    ],
                ]);
                break;
            }

            case 'api_onizle': {
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok!']); break; }
                $id = (int)($_POST['id'] ?? 0);
                if ($birimKisitli && !basvuruKayitBirimYetkiliMi($db, $izinliBirimler, $id)) {
                    echo json_encode(['success' => false, 'message' => 'Bu kayda erişim yetkiniz yok!']); break;
                }
                if ($kendiKisitli && !basvuruKayitKendiMi($db, $kullaniciId, $id)) {
                    echo json_encode(['success' => false, 'message' => 'Bu kayda erişim yetkiniz yok!']); break;
                }
                // TalepKayitNo doluysa: 15/16 atlanır, yalnızca süreç sorgusu (Endpoint 17) yapılır
                $bk17 = $db->fetchOne("SELECT TalepKayitNo FROM Basvurular WHERE Basvurular_id = ?", [$id]);
                $tkn  = $bk17['TalepKayitNo'] ?? null;
                if (!empty($tkn)) {
                    $ep17 = $db->fetchOne("SELECT APIEndpointler_Endpoint FROM APIEndpointler WHERE APIEndpointler_Id = 17 AND Durum = 1");
                    $url17 = $ep17
                        ? $ep17['APIEndpointler_Endpoint'] . ((strpos($ep17['APIEndpointler_Endpoint'], '?') === false ? '?' : '&') . 'RequestId=' . urlencode((string)$tkn))
                        : '(Endpoint 17 bulunamadı/pasif)';
                    echo json_encode([
                        'success'  => true,
                        'mode'     => 'surec',
                        'endpoint' => $url17,
                        'method'   => 'POST',
                        'not'      => 'TalepKayitNo dolu (' . $tkn . '). Sipariş gönderimi (Endpoint 15/16) atlanır; yalnızca süreç durumu sorgulanır (boş gövde).',
                    ]);
                    break;
                }

                $h = basvuruApiHazirla($db, $id);
                if (!$h['ok']) { echo json_encode(['success' => false, 'message' => $h['msg']]); break; }
                echo json_encode([
                    'success'  => true,
                    'mode'     => 'siparis',
                    'endpoint' => $h['url'],
                    'method'   => $h['method'],
                    'personel' => $h['personel'],
                    'body'     => $h['body'],
                ]);
                break;
            }

            // Dijital teyit SMS'ini yeniden gönderir (Endpoint 29 — Order/SendSmsAgain).
            // Satış talebi açıldıktan sonra müşteriye ödeme bilgisi girişi ve Digiturk
            // sözleşme onayı için gönderilen SMS'tir; OTP doğrulamasıyla ilgisi yoktur.
            // Uç RequestId istediği için yalnız TalepKayitNo dolu kayıtlarda çalışır.
            // Doküman: aynı üyeye son 24 saatte gönderim yapıldıysa yenisi gitmez.
            case 'sms_tekrar': {
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok!']); break; }
                $id = (int)($_POST['id'] ?? 0);
                if ($birimKisitli && !basvuruKayitBirimYetkiliMi($db, $izinliBirimler, $id)) {
                    echo json_encode(['success' => false, 'message' => 'Bu kayda erişim yetkiniz yok!']); break;
                }
                if ($kendiKisitli && !basvuruKayitKendiMi($db, $kullaniciId, $id)) {
                    echo json_encode(['success' => false, 'message' => 'Bu kayda erişim yetkiniz yok!']); break;
                }

                $bk = $db->fetchOne("
                    SELECT b.TalepKayitNo, per.DigiturkAltBayiPersonel_Token AS Token
                    FROM Basvurular b
                    LEFT JOIN DigiturkAltBayiPersonel per ON per.DigiturkAltBayiPersonel_Id = b.AltBayiPersonel_ID
                    WHERE b.Basvurular_id = ?", [$id]);
                if (!$bk)                     { echo json_encode(['success' => false, 'message' => 'Başvuru bulunamadı']); break; }
                if (empty($bk['TalepKayitNo'])) { echo json_encode(['success' => false, 'message' => 'Talep kayıt no boş — henüz satış talebi oluşmamış.']); break; }
                if (empty($bk['Token']))        { echo json_encode(['success' => false, 'message' => 'Personelin aktif token\'ı yok! Önce token yenileyin.']); break; }

                $ep29 = $db->fetchOne("SELECT APIEndpointler_Endpoint FROM APIEndpointler WHERE APIEndpointler_Id = 29 AND Durum = 1");
                if (!$ep29) { echo json_encode(['success' => false, 'message' => 'Endpoint 29 bulunamadı/pasif.']); break; }

                $url29  = $ep29['APIEndpointler_Endpoint'];
                $url29 .= (strpos($url29, '?') === false ? '?' : '&') . 'RequestId=' . urlencode((string)$bk['TalepKayitNo']);

                $ch29 = curl_init($url29);
                curl_setopt_array($ch29, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => '',
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'Token: ' . $bk['Token']],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT        => 60,
                ]);
                $resp29 = curl_exec($ch29);
                $http29 = curl_getinfo($ch29, CURLINFO_HTTP_CODE);
                $err29  = curl_error($ch29);
                curl_close($ch29);

                if ($err29) {
                    basvuruLogApi($db, $id, 'API_SMS', 'POST ' . $url29, '(boş gövde)', 'Bağlantı hatası: ' . $err29, null, $user['kullanici_id'], 'Teyit SMS gönderimi başarısız (bağlantı)');
                    echo json_encode(['success' => false, 'message' => 'Bağlantı hatası: ' . $err29]);
                    break;
                }

                $j29  = json_decode((string)$resp29, true);
                $rc29 = is_array($j29) ? ($j29['responseCode'] ?? $j29['ResponseCode'] ?? null) : null;
                $rm29 = is_array($j29) ? trim((string)($j29['responseMessage'] ?? $j29['ResponseMessage'] ?? '')) : '';
                $ok29 = ($http29 < 400) && ($rc29 !== null) && ((int)$rc29 === 0);

                basvuruLogApi($db, $id, 'API_SMS', 'POST ' . $url29, '(boş gövde, RequestId=' . $bk['TalepKayitNo'] . ')',
                    $resp29, $http29, $user['kullanici_id'],
                    'Teyit SMS tekrar gönderimi — responseCode=' . ($rc29 ?? '-'));

                // API'nin 24 saat kuralını nasıl bildirdiği dokümante değil;
                // yanıt olduğu gibi gösterilir, yorum eklenmez.
                echo json_encode([
                    'success'         => $ok29,
                    'message'         => $ok29
                        ? ('Teyit SMS isteği gönderildi.' . ($rm29 !== '' ? ' (' . $rm29 . ')' : ''))
                        : ('SMS gönderilemedi — HTTP ' . $http29 . ($rm29 !== '' ? ': ' . $rm29 : '') . ($rc29 !== null ? ' [responseCode=' . $rc29 . ']' : '')),
                    'http'            => $http29,
                    'responseCode'    => $rc29,
                    'responseMessage' => $rm29,
                    'yanit'           => ($j29 !== null) ? json_encode($j29, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $resp29,
                ]);
                break;
            }

            case 'api_gonder': {
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok!']); break; }
                $id = (int)($_POST['id'] ?? 0);
                if ($birimKisitli && !basvuruKayitBirimYetkiliMi($db, $izinliBirimler, $id)) {
                    echo json_encode(['success' => false, 'message' => 'Bu kayda erişim yetkiniz yok!']); break;
                }
                if ($kendiKisitli && !basvuruKayitKendiMi($db, $kullaniciId, $id)) {
                    echo json_encode(['success' => false, 'message' => 'Bu kayda erişim yetkiniz yok!']); break;
                }

                // Başvurunun TalepKayitNo'su + token kaynağı (alt bayi personeli)
                $bk = $db->fetchOne("
                    SELECT b.TalepKayitNo, per.DigiturkAltBayiPersonel_Token AS Token
                    FROM Basvurular b
                    LEFT JOIN DigiturkAltBayiPersonel per ON per.DigiturkAltBayiPersonel_Id = b.AltBayiPersonel_ID
                    WHERE b.Basvurular_id = ?", [$id]);
                if (!$bk) { echo json_encode(['success' => false, 'message' => 'Başvuru bulunamadı']); break; }
                $talepKayitNo = $bk['TalepKayitNo'] ?? null;
                $token        = $bk['Token'] ?? null;

                // Echo için varsayılanlar
                $gonderilen      = null;
                $yanitGosterim   = '';
                $http            = null;
                $responseCode    = null;
                $responseMessage = '';
                $basarili        = false;
                $durumYazildi    = false;
                $durumMesaji     = '';

                // ── Sipariş oluştur (Endpoint 15/16) — YALNIZ TalepKayitNo BOŞSA ──
                if (empty($talepKayitNo)) {
                    $h = basvuruApiHazirla($db, $id);
                    if (!$h['ok']) { echo json_encode(['success' => false, 'message' => $h['msg']]); break; }
                    $token = $h['token'];

                    $gonderilen = json_encode($h['body'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                    $ch = curl_init($h['url']);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => json_encode($h['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'Token: ' . $token],
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_TIMEOUT        => 60,
                    ]);
                    $resp = curl_exec($ch);
                    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $err  = curl_error($ch);
                    curl_close($ch);

                    if ($err) {
                        // LOG: sipariş bağlantı hatası
                        basvuruLogApi($db, $id, 'API_SIPARIS', $h['method'] . ' ' . $h['url'], $h['body'], 'Bağlantı hatası: ' . $err, null, $user['kullanici_id'], 'Sipariş gönderimi başarısız (bağlantı)');
                        echo json_encode(['success' => false, 'message' => 'Bağlantı hatası: ' . $err, 'gonderilen' => $gonderilen]); break;
                    }

                    $json          = json_decode($resp, true);
                    $yanitGosterim = ($json !== null)
                        ? json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                        : $resp;

                    $responseCode    = is_array($json) ? ($json['ResponseCode']    ?? $json['responseCode']    ?? null) : null;
                    $responseMessage = is_array($json) ? (string)($json['ResponseMessage'] ?? $json['responseMessage'] ?? '') : '';
                    $basarili        = ($http < 400) && ((int)$responseCode === 0) && ($responseCode !== null);

                    // responseCode → BasvuruDurum_DurumKodu eşleştir; BasvuruDurum_ID + Mesaj güncelle
                    if ($responseCode !== null) {
                        $durum = $db->fetchOne("SELECT TOP 1 BasvuruDurum_id, BasvuruDurum_Mesaj FROM BasvuruDurum WHERE BasvuruDurum_DurumKodu = ?", [(int)$responseCode]);
                        $durumMesaji = $durum['BasvuruDurum_Mesaj'] ?? '';
                        $now   = date('Y-m-d H:i:s');
                        $mesaj = $responseMessage !== '' ? $responseMessage : ($basarili ? 'Başarılı' : ('HTTP ' . $http));
                        $upd   = [
                            'BasvuruDurumMesaj'    => mb_substr($mesaj, 0, 500),
                            'GuncelleyenKullanici' => $user['kullanici_id'],
                            'GuncellemeTarihi'     => $now,
                        ];
                        if (!empty($durum['BasvuruDurum_id'])) {
                            $upd['BasvuruDurum_ID'] = (int)$durum['BasvuruDurum_id'];
                            $durumYazildi = true;
                        }
                        // Başarılı yanıtta data alanlarını ilgili kolonlara yaz
                        $data = is_array($json) ? ($json['data'] ?? $json['Data'] ?? null) : null;
                        if ($basarili && is_array($data)) {
                            if (!empty($data['accountNumber'])) $upd['MusteriNo']    = (int)$data['accountNumber'];
                            if (!empty($data['requestId']))     $upd['TalepKayitNo'] = (int)$data['requestId'];
                            if (!empty($data['caseId']))        $upd['MemoID']       = (int)$data['caseId'];
                        }
                        $db->update('Basvurular', $upd, ['Basvurular_id' => $id]);
                    }

                    // LOG: sipariş API isteği/yanıtı
                    basvuruLogApi($db, $id, 'API_SIPARIS', $h['method'] . ' ' . $h['url'], $h['body'], $yanitGosterim, $http, $user['kullanici_id'],
                        'Sipariş gönderimi (Endpoint ' . $h['endpointId'] . '), responseCode=' . ($responseCode ?? '-'));
                } else {
                    // TalepKayitNo dolu → sipariş zaten oluşturulmuş; 15/16 atlanır
                    $gonderilen = '(TalepKayitNo dolu: ' . $talepKayitNo . ' — sipariş gönderimi (Endpoint 15/16) atlandı)';
                    $basarili   = true;
                }

                // ── Süreç sorgusu (Endpoint 17) — YALNIZ TalepKayitNo DOLUYSA ──
                // POST, body boş, URL sonuna ?RequestId=TalepKayitNo. Yanıt: data.requestStatusCode → BasvuruSurecDurum_ID
                $surecBilgi = null;
                if (!empty($talepKayitNo)) {
                    if (empty($token)) {
                        $surecBilgi = 'Süreç sorgusu atlandı: Personel token\'ı yok.';
                    } else {
                        $ep17 = $db->fetchOne("SELECT APIEndpointler_Endpoint FROM APIEndpointler WHERE APIEndpointler_Id = 17 AND Durum = 1");
                        if (!$ep17) {
                            $surecBilgi = 'Süreç sorgusu atlandı: Endpoint 17 bulunamadı/pasif.';
                        } else {
                            $url17 = $ep17['APIEndpointler_Endpoint'];
                            $url17 .= (strpos($url17, '?') === false ? '?' : '&') . 'RequestId=' . urlencode((string)$talepKayitNo);

                            $ch17 = curl_init($url17);
                            curl_setopt_array($ch17, [
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_POST           => true,
                                CURLOPT_POSTFIELDS     => '',
                                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'Token: ' . $token],
                                CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_TIMEOUT        => 60,
                            ]);
                            $resp17 = curl_exec($ch17);
                            $http17 = curl_getinfo($ch17, CURLINFO_HTTP_CODE);
                            $err17  = curl_error($ch17);
                            curl_close($ch17);

                            if ($err17) {
                                $surecBilgi = 'Süreç sorgusu bağlantı hatası: ' . $err17;
                            } else {
                                $j17 = json_decode($resp17, true);
                                $yanitGosterim .= ($yanitGosterim !== '' ? "\n\n" : '')
                                    . "--- Süreç Sorgusu (Endpoint 17 — RequestId=" . $talepKayitNo . ") ---\n"
                                    . (($j17 !== null) ? json_encode($j17, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $resp17);

                                $data17     = is_array($j17) ? ($j17['data'] ?? $j17['Data'] ?? null) : null;
                                $statusCode = is_array($data17) ? ($data17['requestStatusCode'] ?? $data17['RequestStatusCode'] ?? null) : null;

                                if ($statusCode !== null && $statusCode !== '') {
                                    $db->update('Basvurular', [
                                        'BasvuruSurecDurum_ID' => (int)$statusCode,
                                        'BasvuruDurum_ID'      => 1, // TalepKayitNo dolu + süreç sorgusu başarılı → başvuru durumu 1
                                        'GuncelleyenKullanici' => $user['kullanici_id'],
                                        'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                                    ], ['Basvurular_id' => $id]);
                                    $surecBilgi = 'Süreç durumu güncellendi: BasvuruSurecDurum_ID = ' . (int)$statusCode . ', BasvuruDurum_ID = 1';
                                } else {
                                    $surecBilgi = 'Süreç sorgusu yapıldı ama requestStatusCode alınamadı (HTTP ' . $http17 . ').';
                                }
                            }

                            // LOG: süreç sorgusu API isteği/yanıtı (Endpoint 17)
                            basvuruLogApi($db, $id, 'API_SUREC', 'POST ' . $url17, '(boş gövde, RequestId=' . $talepKayitNo . ')', $resp17, $http17, $user['kullanici_id'], $surecBilgi);
                        }
                    }
                }

                echo json_encode([
                    'success'         => true,
                    'gonderildi'      => $basarili,
                    'http'            => $http,
                    'responseCode'    => $responseCode,
                    'responseMessage' => $responseMessage,
                    'durumYazildi'    => $durumYazildi,
                    'durumMesaji'     => $durumMesaji,
                    'surecBilgi'      => $surecBilgi,
                    'gonderilen'      => $gonderilen,
                    'yanit'           => $yanitGosterim,
                ]);
                break;
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

// Sayfa render — filtre dropdown verileri
$kampanyalar = $db->fetchAll("
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
    LEFT JOIN APIKampanyaTurleri t ON k.APIKampanyalar_Tur       = t.APIKampanyaTurleri_Id
    WHERE k.Durum = 1
    ORDER BY k.APIKampanyalar_PaketAdi, k.APIKampanyalar_Ad
");
// Yalnızca en az bir başvuruda kullanılan durumlar listelenir. BasvuruDurum tablosunda
// hiç kaydı olmayan kodlar da tanımlı (HATA-11, HATA-500, BEKLEMEDE …); bunlar filtreye
// düşünce DataTable boş dönüyordu. Yeni bir kod ilk kez kullanıldığında listeye
// kendiliğinden girer.
$basvuruDurumlari = $db->fetchAll("
    SELECT d.BasvuruDurum_id AS id, d.BasvuruDurum_Mesaj AS ad
    FROM BasvuruDurum d
    WHERE EXISTS (SELECT 1 FROM Basvurular b WHERE b.BasvuruDurum_ID = d.BasvuruDurum_id)
    ORDER BY d.BasvuruDurum_Mesaj
");
$surecDurumlari = $db->fetchAll("
    SELECT BasvuruSurecDurum_id AS id, BasvuruSurecDurum_Mesaj AS ad FROM BasvuruSurecDurum ORDER BY BasvuruSurecDurum_Mesaj
");
$iletisimDurumlari = $db->fetchAll("
    SELECT BasvuruIletisimDurum_id AS id, BasvuruIletisimDurum_Mesaj AS ad FROM BasvuruIletisimDurum WHERE Durum = 1 ORDER BY BasvuruIletisimDurum_Mesaj
");
$birimler = $db->fetchAll("
    SELECT KullaniciBirim_id AS id, KullaniciBirim_Adi AS ad FROM KullaniciBirim ORDER BY KullaniciBirim_Adi
");
$personellerFiltre = $db->fetchAll("
    SELECT DigiturkAltBayiPersonel_Id AS id, DigiturkAltBayiPersonel_AdSoyad AS ad
    FROM DigiturkAltBayiPersonel WHERE Durum = 1 ORDER BY DigiturkAltBayiPersonel_AdSoyad
");
// Atanan kullanıcı filtresi: başvurulara atanmış (distinct) kullanıcılar
$atananlar = $db->fetchAll("
    SELECT DISTINCT k.kullanici_id AS id,
           LTRIM(RTRIM(CONCAT(k.kullanici_ad, ' ', k.kullanici_soyad))) AS ad
    FROM Basvurular b
    INNER JOIN kullanicilar k ON k.kullanici_id = b.Basvurular_AtananKullanici_ID
    WHERE b.Basvurular_AtananKullanici_ID IS NOT NULL
    ORDER BY ad
");
$sayfalar = $db->fetchAll("
    SELECT s.ReklamFacebookSayfalari_id AS id,
        CONCAT(
            CASE WHEN pl.ReklamPlatformlari_Adi IS NOT NULL THEN CONCAT('[', pl.ReklamPlatformlari_Adi, '] ') ELSE '' END,
            s.ReklamFacebookSayfalari_SayfaAdi,
            ' (', s.ReklamFacebookSayfalari_id, ')'
        ) AS ad
    FROM ReklamFacebookSayfalari s
    LEFT JOIN ReklamKampanyalari k ON k.ReklamKampanyalari_id  = s.ReklamFacebookSayfalari_Kampanya_id
    LEFT JOIN ReklamHesaplari     h ON h.ReklamHesaplari_id     = k.ReklamKampanyalari_Hesap_id
    LEFT JOIN ReklamPlatformlari  pl ON pl.ReklamPlatformlari_id = h.ReklamHesaplari_Platform_id
    WHERE s.Durum = 1
      AND EXISTS (
          SELECT 1 FROM Basvurular b
          INNER JOIN ReklamLeadFormlari f ON f.ReklamLeadFormlari_id = b.ReklamLeadFormlari_ID
          WHERE f.ReklamLeadFormlari_Sayfa_id = s.ReklamFacebookSayfalari_id
      )
    ORDER BY pl.ReklamPlatformlari_Adi, s.ReklamFacebookSayfalari_SayfaAdi
");

// Dağıt modalı: başvurusu bulunan reklam lead formları (sayfa adıyla birlikte)
$dagitLeadFormlari = $db->fetchAll("
    SELECT f.ReklamLeadFormlari_id AS id,
        CONCAT(
            f.ReklamLeadFormlari_FormAdi,
            CASE WHEN s.ReklamFacebookSayfalari_SayfaAdi IS NOT NULL
                 THEN CONCAT(' — ', s.ReklamFacebookSayfalari_SayfaAdi) ELSE '' END
        ) AS ad
    FROM ReklamLeadFormlari f
    LEFT JOIN ReklamFacebookSayfalari s ON s.ReklamFacebookSayfalari_id = f.ReklamLeadFormlari_Sayfa_id
    WHERE EXISTS (
        SELECT 1 FROM Basvurular b WHERE b.ReklamLeadFormlari_ID = f.ReklamLeadFormlari_id
    )
    ORDER BY s.ReklamFacebookSayfalari_SayfaAdi, f.ReklamLeadFormlari_FormAdi
");
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
        .status-badge { padding: .25rem .5rem; border-radius: .25rem; font-size: .8rem; white-space: nowrap; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; }
        .json-box { background:#f8f9fa; border:1px solid #dee2e6; border-radius:.375rem; padding:.75rem; font-size:.8rem; max-height:280px; overflow:auto; white-space:pre-wrap; word-break:break-all; margin-bottom:0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .spin { display:inline-block; animation: otpSpin 1s linear infinite; }
        @keyframes otpSpin { to { transform: rotate(360deg); } }
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
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-people"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Başvuru</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-calendar-day"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bugün</span>
                                <span class="info-box-number" id="stat-bugun">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-warning" title="Kıdemli şablonu: atanmamış + dün 19:00 sonrası oluşturulan başvurular">
                            <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Atanmamış (Kıdemli)</span>
                                <span class="info-box-number" id="stat-atanmamis">0</span>
                                <span class="progress-description" style="font-size:.7rem;opacity:.75;">Dün 19:00 sonrası gelenler</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-danger" title="Kıdemsiz şablonu: atanmamış + önceki gün 19:00 – dün 19:00 arası + Ulaşılamadı">
                            <span class="info-box-icon"><i class="bi bi-telephone-x"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Atanmamış (Kıdemsiz)</span>
                                <span class="info-box-number" id="stat-kidemsiz">0</span>
                                <span class="progress-description" style="font-size:.7rem;opacity:.75;">Önceki gün 19:00 – dün 19:00, Ulaşılamadı</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Günlük Personel Kırılımı: her durum değeri için ayrı kutu (Atanan Kullanıcı bazında, agent birimine göre) -->
                <h6 class="text-muted mb-2 mt-1"><i class="bi bi-person-check"></i> Başvuru Durumu — Bugün</h6>
                <div class="row" id="kirilim-basvuru">
                    <div class="col-12 text-muted text-center py-2">Yükleniyor...</div>
                </div>
                <h6 class="text-muted mb-2 mt-2"><i class="bi bi-telephone"></i> İletişim Durumu — Bugün</h6>
                <div class="row" id="kirilim-iletisim">
                    <div class="col-12 text-muted text-center py-2">Yükleniyor...</div>
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
                                <div class="col-md-3">
                                    <label class="form-label">Ara</label>
                                    <input type="text" class="form-control" name="search" id="filter_search" placeholder="Ad, soyad, TC, e-posta, telefon...">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Birim</label>
                                    <select class="form-select" name="birim" id="filter_birim">
                                        <option value="">Tümü</option>
                                        <?php foreach ($birimler as $b): ?>
                                        <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Alt Bayi Personeli</label>
                                    <select class="form-select" name="personel" id="filter_personel">
                                        <option value="">Tümü</option>
                                        <?php foreach ($personellerFiltre as $p): ?>
                                        <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Atanan Kullanıcı</label>
                                    <select class="form-select" name="atanan" id="filter_atanan">
                                        <option value="">Tümü</option>
                                        <option value="0">— Atanmamış —</option>
                                        <?php foreach ($atananlar as $ak): ?>
                                        <option value="<?= (int)$ak['id'] ?>"><?= htmlspecialchars($ak['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Sayfa</label>
                                    <select class="form-select" name="sayfa" id="filter_sayfa">
                                        <option value="">Tümü</option>
                                        <?php foreach ($sayfalar as $sf): ?>
                                        <option value="<?= (int)$sf['id'] ?>"><?= htmlspecialchars($sf['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Reklam</label>
                                    <select class="form-select" name="lead_formu[]" id="filter_lead_formu" multiple data-placeholder="Tümü">
                                        <option value="yok">Yok (reklamdan gelmeyen)</option>
                                        <?php foreach ($dagitLeadFormlari as $lf): ?>
                                        <option value="<?= (int)$lf['id'] ?>"><?= htmlspecialchars($lf['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Kampanya</label>
                                    <select class="form-select" name="kampanya" id="filter_kampanya">
                                        <option value="">Tümü</option>
                                        <?php foreach ($kampanyalar as $k): ?>
                                        <option value="<?= (int)$k['id'] ?>"><?= htmlspecialchars($k['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Başvuru Durumu</label>
                                    <select class="form-select" name="basvuru_durum" id="filter_basvuru_durum">
                                        <option value="">Tümü</option>
                                        <?php foreach ($basvuruDurumlari as $d): ?>
                                        <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Süreç Durumu</label>
                                    <select class="form-select" name="surec_durum" id="filter_surec_durum">
                                        <option value="">Tümü</option>
                                        <?php foreach ($surecDurumlari as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">İletişim Durumu</label>
                                    <select class="form-select" name="iletisim_durum" id="filter_iletisim_durum">
                                        <option value="">Tümü</option>
                                        <?php foreach ($iletisimDurumlari as $idr): ?>
                                        <option value="<?= (int)$idr['id'] ?>"><?= htmlspecialchars($idr['ad']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Başlangıç Tarihi</label>
                                    <input type="datetime-local" class="form-control" name="tarih_bas" id="filter_tarih_bas">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Bitiş Tarihi</label>
                                    <input type="datetime-local" class="form-control" name="tarih_bit" id="filter_tarih_bit">
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
                        <h3 class="card-title">Başvurular</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-success btn-sm" id="btnExcelIndir">
                                <i class="bi bi-file-earmark-excel"></i> Excel İndir
                            </button>
                            <?php if ($permissions['can_edit']): ?>
                            <button type="button" class="btn btn-info btn-sm" id="btnTopluOtp">
                                <i class="bi bi-phone-vibrate"></i> Toplu OTP Sorgula
                                <span class="badge bg-light text-dark" id="topluOtpSayac">0</span>
                            </button>
                            <?php endif; ?>
                            <?php if ($uyeSildirmeGoster): ?>
                            <button type="button" class="btn btn-dark btn-sm" id="btnUyeSildirme">
                                <i class="bi bi-person-x"></i> Numara Sildirme
                                <span class="badge bg-light text-dark" id="uyeSildirmeSayac">0</span>
                            </button>
                            <?php endif; ?>
                            <?php if ($permissions['can_edit'] && $dagitButonGoster): ?>
                            <button type="button" class="btn btn-warning btn-sm" id="btnDagit">
                                <i class="bi bi-diagram-3"></i> Başvuruları Dağıt
                            </button>
                            <?php endif; ?>
                            <?php if ($permissions['can_add']): ?>
                            <a href="/Admin/basvuru-form" class="btn btn-success btn-sm">
                                <i class="bi bi-plus-circle"></i> Yeni Başvuru
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width:36px">
                                        <input type="checkbox" class="form-check-input" id="otpSelectAll"
                                               title="OTP gönderilmemişlerin tümünü seç">
                                    </th>
                                    <th>Birim</th>
                                    <th>Alt Bayi Personeli</th>
                                    <th>Ad Soyad</th>
                                    <th>TC Kimlik</th>
                                    <th>Telefon</th>
                                    <th>Kampanya</th>
                                    <th>Başvuru Durumu</th>
                                    <th>Durum Mesajı</th>
                                    <th>Süreç Durumu</th>
                                    <th>İletişim Durumu</th>
                                    <th>Tarih</th>
                                    <th class="text-center">Atanan</th>
                                    <th class="text-center">Reklam</th>
                                    <th class="text-center" style="width:44px">OTP</th>
                                    <th style="width:190px">İşlemler</th>
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

<!-- API'ye Gönder Modal -->
<div class="modal fade" id="apiModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cloud-upload"></i> API'ye Gönder</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="apiMeta" class="mb-2 small text-muted"></div>
                <label class="form-label fw-bold mb-1"><i class="bi bi-box-arrow-up-right"></i> Gönderilecek Veri</label>
                <pre class="json-box" id="apiGonderilen">—</pre>
                <label class="form-label fw-bold mb-1 mt-3"><i class="bi bi-box-arrow-in-down-left"></i> API Yanıtı</label>
                <pre class="json-box" id="apiYanit">— Henüz gönderilmedi —</pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
                <button type="button" class="btn btn-info" id="btnApiGonder"><i class="bi bi-send"></i> Gönder</button>
            </div>
        </div>
    </div>
</div>

<?php if ($uyeSildirmeGoster): ?>
<!-- Numara Sildirme Modalı -->
<div class="modal fade" id="uyeSildirmeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-x"></i> Numara Sildirme <span class="badge bg-secondary" id="usAdet">0</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive" style="max-height:50vh">
                    <table class="table table-sm table-bordered mb-2">
                        <thead><tr><th>#</th><th>İsim Soyisim</th><th>TC Kimlik</th><th>Telefon</th></tr></thead>
                        <tbody id="usKayitlar"><tr><td colspan="4" class="text-center text-muted">—</td></tr></tbody>
                    </table>
                </div>
                <div id="usEksikUyari" class="alert alert-warning py-2 mb-0 d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
                <button type="button" class="btn btn-success" id="btnUsWhatsapp" <?= $uyeSildirmeWhatsappAktif ? '' : 'disabled title="Şu anda pasif"' ?>><i class="bi bi-whatsapp"></i> WhatsApp ile Gönder</button>
                <button type="button" class="btn btn-primary" id="btnUsEmail"><i class="bi bi-envelope"></i> E-posta ile Gönder</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- OTP Onay Ekranı Modalı (iframe) -->
<div class="modal fade" id="otpOnayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-shield-lock"></i> Digiturk Onay Ekranı</h5>
                <div class="ms-auto d-flex gap-2 align-items-center">
                    <a href="#" id="otpOnayYeniSekme" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Yeni sekmede aç"><i class="bi bi-box-arrow-up-right"></i></a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0" style="position:relative;">
                <div id="otpOnayYukleniyor" class="text-center text-muted py-5"><i class="bi bi-arrow-repeat spin"></i> Onay ekranı yükleniyor...</div>
                <iframe id="otpOnayFrame" src="" style="width:100%;height:70vh;border:0;display:none;"></iframe>
            </div>
            <div class="modal-footer">
                <small class="text-muted me-auto">Agent onay parolasını (token) bu ekranda girer.</small>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
            </div>
        </div>
    </div>
</div>

<!-- Başvuruları Dağıt Modalı -->
<div class="modal fade" id="dagitModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-diagram-3"></i> Başvuruları Dağıt</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Manuel mod: tablodan seçim yapılmışsa şablon/filtre yerine bu uyarı görünür -->
        <div id="dagitManuelBilgi" class="alert alert-warning d-none mb-3">
          <i class="bi bi-hand-index"></i> Tablodan <strong id="dagitManuelSayi">0</strong> başvuru seçildi.
          Atanmış olanlar dahil seçtiğiniz kişilere yeniden atanacak.
        </div>
        <div id="dagitSablonAlan">
        <p class="text-muted mb-2">Bir dağıtım şablonu seçin. Yalnızca kendi biriminize ve alt birimlerinize ait başvurular dağıtılır.</p>
        <div class="d-grid gap-2 mb-3">
          <button type="button" class="btn btn-primary dagit-sablon" data-tip="kidem">
            <i class="bi bi-star-fill"></i> Kıdemli Agent'a Dağıt
          </button>
          <button type="button" class="btn btn-warning dagit-sablon" data-tip="kidemsiz">
            <i class="bi bi-star"></i> Kıdemsiz Agent'a Dağıt
          </button>
        </div>

        <div class="mb-3">
          <div class="form-check form-switch">
            <input class="form-check-input dagit-filtre" type="checkbox" role="switch" id="dagitSadeceOtp" checked>
            <label class="form-check-label" for="dagitSadeceOtp">
              OTP Doğrulananlar <span class="text-muted small">(işaretsizse tüm başvurular dağıtılır)</span>
            </label>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input dagit-filtre" type="checkbox" role="switch" id="dagitSadeceIsimli">
            <label class="form-check-label" for="dagitSadeceIsimli">
              İsim soyismi olanlar <span class="text-muted small">(isim ve soyisim dolu olanlar)</span>
            </label>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input dagit-filtre" type="checkbox" role="switch" id="dagitSadeceReklam">
            <label class="form-check-label" for="dagitSadeceReklam">
              Reklamdan gelenler <span class="text-muted small">(reklam lead formundan gelenler)</span>
            </label>
          </div>

          <!-- Lead formu seçimi: yalnız "Reklamdan gelenler" açıkken görünür -->
          <div id="dagitReklamSayfaAlan" class="d-none mt-2 ms-4">
            <div class="dropdown">
              <button class="btn btn-sm btn-outline-secondary dropdown-toggle w-100 text-start" type="button"
                      id="dagitReklamSayfaBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside">
                <span id="dagitReklamSayfaOzet">Tüm lead formları</span>
              </button>
              <div class="dropdown-menu w-100 p-2" style="max-height:320px;overflow:auto">
                <input type="text" class="form-control form-control-sm mb-2" id="dagitReklamSayfaAra" placeholder="Lead formu ara...">
                <div class="d-flex justify-content-between mb-2">
                  <button type="button" class="btn btn-sm btn-link p-0" id="dagitReklamSayfaTumu">Tümünü seç</button>
                  <button type="button" class="btn btn-sm btn-link p-0 text-danger" id="dagitReklamSayfaTemizle">Temizle</button>
                </div>
                <?php foreach ($dagitLeadFormlari as $lf): ?>
                <div class="form-check form-switch dagit-sayfa-satir" data-ad="<?= htmlspecialchars(mb_strtolower($lf['ad'], 'UTF-8')) ?>">
                  <input class="form-check-input dagit-reklam-sayfa" type="checkbox" role="switch"
                         value="<?= (int)$lf['id'] ?>" id="drs_<?= (int)$lf['id'] ?>">
                  <label class="form-check-label small" for="drs_<?= (int)$lf['id'] ?>"><?= htmlspecialchars($lf['ad']) ?></label>
                </div>
                <?php endforeach; ?>
                <?php if (empty($dagitLeadFormlari)): ?>
                <div class="text-muted small">Lead formu bulunamadı.</div>
                <?php endif; ?>
              </div>
            </div>
            <div class="form-text">Hiçbiri seçilmezse tüm lead formları dağıtılır.</div>
          </div>
        </div>
        </div><!-- /dagitSablonAlan -->

        <!-- Şablon ve manuel dağıtımda ortak -->
        <div class="mb-3">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="dagitKaraListeHaric" checked>
            <label class="form-check-label" for="dagitKaraListeHaric">
              Kara listedekileri dağıtma <span class="text-muted small">(telefon veya TC kara listede olanlar atlanır)</span>
            </label>
          </div>
        </div>

        <div id="dagitOnizlemeAlan" class="d-none">
          <div class="alert alert-info mb-3">
            <div class="fw-bold mb-1"><i class="bi bi-diagram-3"></i> Şablon: <span id="dagitProfilAd">—</span></div>
            <div class="small text-muted mb-2" id="dagitAciklama">—</div>
            <span id="dagitBekleyenEtiket">Dağıtılacak (henüz atanmamış) başvuru</span>: <strong id="dagitBekleyen">0</strong><br>
            Seçili kişi: <strong id="dagitSecili">0</strong> &middot; Toplam girilen: <strong id="dagitGirilen">0</strong> &middot; Kalan: <strong id="dagitKalan">0</strong>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-1">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" role="switch" id="dagitKisiTumu" checked>
              <label class="form-check-label fw-bold" for="dagitKisiTumu">Dağıtılacak kişiler <span class="text-muted small fw-normal">(tümünü seç)</span></label>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="btnEsitDagit">
              <i class="bi bi-distribute-vertical"></i> Eşit Dağıt
            </button>
          </div>
          <div class="form-text mb-2">Her kişinin sağındaki kutuya kaç başvuru atanacağını girin. 0 girilen kişiye atama yapılmaz.</div>
          <div id="dagitKisiler" style="max-height:300px;overflow:auto"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal</button>
        <button type="button" class="btn btn-success d-none" id="btnDagitUygula"><i class="bi bi-send"></i> Dağıt</button>
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
<script src="/Admin/assets/js/custom.js"></script>

<script>
    const permissions = {
        canAdd:    <?= $permissions['can_add']    ? 'true' : 'false' ?>,
        canEdit:   <?= $permissions['can_edit']   ? 'true' : 'false' ?>,
        canDelete: <?= $permissions['can_delete'] ? 'true' : 'false' ?>,
        uyeSildirme: <?= $uyeSildirmeGoster ? 'true' : 'false' ?>,
        dagit:     <?= ($permissions['can_edit'] && $dagitButonGoster) ? 'true' : 'false' ?>
    };

    let dataTable;
    let apiModal, apiAktifId = 0;
    let otpOnayModal;

    $(document).ready(function () {
        apiModal = new bootstrap.Modal(document.getElementById('apiModal'));
        $('#btnApiGonder').on('click', apiGonder);

        otpOnayModal = new bootstrap.Modal(document.getElementById('otpOnayModal'));
        // Modal kapanınca iframe'i boşalt (arka planda çalışmaya devam etmesin)
        document.getElementById('otpOnayModal').addEventListener('hidden.bs.modal', function () {
            $('#otpOnayFrame').hide().attr('src', '');
            $('#otpOnayYukleniyor').show();
        });
        // iframe yüklenince spinner'ı gizle
        $('#otpOnayFrame').on('load', function () {
            if (this.src) { $('#otpOnayYukleniyor').hide(); $(this).show(); }
        });

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            processing: true,
            serverSide: true,
            autoWidth: false, // sabit piksel genişlik verme; tablo konteynere göre akışkan kalsın (sidebar toggle)
            dom: 'lrtip', // global arama kutusu gizli — filtre panelindeki "Ara" kullanılır
            order: [[11, 'desc']],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            ajax: {
                url: '', type: 'POST',
                data: function (d) {
                    d.action            = 'list';
                    d.search.value      = $('#filter_search').val() || '';
                    d.f_birim           = $('#filter_birim').val() || '';
                    d.f_personel        = $('#filter_personel').val() || '';
                    d.f_atanan          = $('#filter_atanan').val() || '';
                    d.f_sayfa           = $('#filter_sayfa').val() || '';
                    d.f_lead_formu      = ($('#filter_lead_formu').val() || []).join(',');
                    d.f_kampanya        = $('#filter_kampanya').val() || '';
                    d.f_basvuru_durum   = $('#filter_basvuru_durum').val() || '';
                    d.f_surec_durum     = $('#filter_surec_durum').val() || '';
                    d.f_iletisim_durum  = $('#filter_iletisim_durum').val() || '';
                    d.f_tarih_bas       = $('#filter_tarih_bas').val() || '';
                    d.f_tarih_bit       = $('#filter_tarih_bit').val() || '';
                    return d;
                },
                error: function () { showToast('Liste yüklenirken hata oluştu', 'error'); }
            },
            columns: [
                { data: null, orderable: false, className: 'text-center', render: (d, t, r) => otpSecimCell(r) },
                { data: 'BirimAdi',          render: d => escapeHtml(d || '-') },
                { data: 'PersonelAdi',       render: d => escapeHtml(d || '-') },
                { data: null,                render: (d, t, r) => escapeHtml(((r.Isim || '') + ' ' + (r.Soyisim || '')).trim() || '-') },
                { data: 'TCKimlikNo',        render: (d, t, r) => tcCell(r) },
                { data: null,                render: (d, t, r) => telCell(r) },
                { data: 'KampanyaAdi',       render: d => escapeHtml(d || '-') },
                { data: null,                render: (d, t, r) => badge(r.DurumMesaj, r.DurumRenk) },
                { data: 'BasvuruDurumMesaj', render: d => durumMesajCell(d) },
                { data: null,                render: (d, t, r) => badge(r.SurecMesaj, r.SurecRenk) },
                { data: 'IletisimDurumMesaj', render: d => escapeHtml(d || '-') },
                { data: 'OlusturmaTarihi',   render: d => escapeHtml(d || '-') },
                { data: null, className: 'text-center', render: (d, t, r) => atananCell(r) },
                { data: 'LeadFormuID', className: 'text-center', render: (d, t, r) => leadFormuCell(r) },
                { data: null, className: 'text-center', orderable: false, render: (d, t, r) => otpDurumIkon(r) },
                { data: null, orderable: false, render: (d, t, r) => islemlerCell(r) }
            ],
            createdRow: function (row, data) {
                const renk = /^#[0-9A-Fa-f]{6}$/.test(data.BirimRenk || '') ? data.BirimRenk : null;
                if (!renk) return;
                const cells = row.children;
                for (let i = 0; i < cells.length; i++) {
                    cells[i].style.backgroundColor = renk + '1a'; // ~%10 opaklık tint
                }
                if (cells[0]) cells[0].style.borderLeft = '4px solid ' + renk;
            }
        });

        loadStats();

        // Sidebar açılıp kapanınca tablo genişliğini yeniden hesapla (CSS geçişi ~300ms)
        $(document).on('click', '[data-lte-toggle="sidebar"]', function () {
            setTimeout(function () { if (dataTable) dataTable.columns.adjust(); }, 350);
        });

        // ── Toplu OTP: tümünü seç / satır seçimi / buton ──
        $('#btnTopluOtp').on('click', topluOtpSorgula);
        $('#btnUyeSildirme').on('click', uyeSildirmeAc);
        $('#otpSelectAll').on('change', function () {
            $('#kayitTable tbody .otp-secim').prop('checked', this.checked);
            topluOtpSayacGuncelle();
        });
        $('#kayitTable tbody').on('change', '.otp-secim', function () {
            const toplam  = $('#kayitTable tbody .otp-secim').length;
            const secili  = $('#kayitTable tbody .otp-secim:checked').length;
            $('#otpSelectAll').prop('checked', toplam > 0 && secili === toplam);
            topluOtpSayacGuncelle();
        });
        // Sayfa/sıralama değişince "tümünü seç" ve sayaç sıfırlanır (server-side yeniden çizim)
        dataTable.on('draw', function () {
            $('#otpSelectAll').prop('checked', false);
            topluOtpSayacGuncelle();
        });

        // Atanan avatarına tıklayınca "Atanan Kullanıcı" filtresine set et
        $('#kayitTable tbody').on('click', '.atanan-avatar', function () {
            const id = $(this).attr('data-atanan-id');
            if (!id) return;
            $('#filter_atanan').val(id).trigger('change');
            dataTable.ajax.reload();
        });

        // Telefon hücresine tıklayınca boşluksuz panoya kopyala
        $('#kayitTable tbody').on('click', '.tel-kopya', function () {
            const tel = $(this).attr('data-tel');
            if (!tel) return;
            const bitti = () => showToast('Telefon kopyalandı: ' + tel, 'success');
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(tel).then(bitti).catch(function () {
                    showToast('Kopyalanamadı', 'error');
                });
            } else {
                // Fallback (eski tarayıcı / http)
                const ta = document.createElement('textarea');
                ta.value = tel; document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); bitti(); }
                catch (e) { showToast('Kopyalanamadı', 'error'); }
                document.body.removeChild(ta);
            }
        });

        // Filtre select'lerini custom.js (.form-select) otomatik Select2 yapıyor — elle init etmiyoruz.

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            dataTable.ajax.reload();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filterForm')[0].reset();
            $('#filter_birim, #filter_personel, #filter_atanan, #filter_sayfa, #filter_kampanya, #filter_basvuru_durum, #filter_surec_durum, #filter_iletisim_durum').val('').trigger('change');
            $('#filter_lead_formu').val(null).trigger('change');
            dataTable.ajax.reload();
            showToast('Filtreler temizlendi', 'info');
        });

        // ── Excel İndir: aktif filtrelerle gizli form POST ──
        $('#btnExcelIndir').on('click', function () {
            const alanlar = {
                action:            'excel_indir',
                'search[value]':   $('#filter_search').val() || '',
                f_birim:           $('#filter_birim').val() || '',
                f_personel:        $('#filter_personel').val() || '',
                f_atanan:          $('#filter_atanan').val() || '',
                f_sayfa:           $('#filter_sayfa').val() || '',
                f_lead_formu:      ($('#filter_lead_formu').val() || []).join(','),
                f_kampanya:        $('#filter_kampanya').val() || '',
                f_basvuru_durum:   $('#filter_basvuru_durum').val() || '',
                f_surec_durum:     $('#filter_surec_durum').val() || '',
                f_iletisim_durum:  $('#filter_iletisim_durum').val() || '',
                f_tarih_bas:       $('#filter_tarih_bas').val() || '',
                f_tarih_bit:       $('#filter_tarih_bit').val() || ''
            };
            const form = $('<form>', { method: 'POST', action: '' });
            Object.keys(alanlar).forEach(function (k) {
                form.append($('<input>', { type: 'hidden', name: k, value: alanlar[k] }));
            });
            $('body').append(form);
            form.submit();
            form.remove();
            showToast('Excel dosyası indiriliyor...', 'info');
        });

        // ── Başvuruları Dağıt ──
        // Aktif şablon (kidem / kidemsiz). Şablon butonuna basılınca dolar.
        // 'manuel' ise tablodan seçilen başvurular (dagitManuelIds) dağıtılır.
        let dagitTip = null;
        let dagitManuelIds = [];

        // Toolbar butonu: tabloda seçim varsa manuel modda, yoksa şablon seçimiyle aç
        $('#btnDagit').on('click', function () {
            dagitTip = null;
            dagitManuelIds = $('#kayitTable tbody .otp-secim:checked').map(function () { return this.value; }).get();
            const manuel = dagitManuelIds.length > 0;
            $('#dagitOnizlemeAlan').addClass('d-none');
            $('#btnDagitUygula').addClass('d-none');
            $('#dagitKisiler').empty();
            $('.dagit-sablon').prop('disabled', false);
            $('#dagitSablonAlan').toggleClass('d-none', manuel);
            $('#dagitManuelBilgi').toggleClass('d-none', !manuel);
            $('#dagitManuelSayi').text(dagitManuelIds.length);
            $('#dagitBekleyenEtiket').text(manuel ? 'Dağıtılacak (seçili) başvuru' : 'Dağıtılacak (henüz atanmamış) başvuru');
            new bootstrap.Modal('#dagitModal').show();
            if (manuel) dagitManuelOnizle();
        });

        function dagitManuelOnizle() {
            $('#dagitOnizlemeAlan').addClass('d-none');
            $('#btnDagitUygula').addClass('d-none');
            $.post('', { action: 'dagit_manuel_onizleme', basvuru_ids: dagitManuelIds, karaliste_haric: dagitKaraListeHaric() }, function (r) {
                if (!r.success) { showToast(r.message || 'Önizleme alınamadı', 'error'); return; }
                dagitOnizlemeDoldur('manuel', r, true);
            }, 'json').fail(() => showToast('Önizleme alınamadı', 'error'));
        }

        // Kara liste anahtarı: şablon modunda şablonu, manuel modda seçimi yeniden önizle
        $('#dagitKaraListeHaric').on('change', function () {
            if (dagitManuelIds.length) dagitManuelOnizle();
            else if (dagitTip) $('.dagit-sablon[data-tip="' + dagitTip + '"]').trigger('click');
        });

        // Önizleme cevabını modala işle (şablon ve manuel ortak)
        function dagitOnizlemeDoldur(tip, r, departmanGoster) {
                dagitTip = tip;
                $('#dagitProfilAd').text(r.profilAd || '');
                $('#dagitAciklama').text(r.aciklama || '');
                $('#dagitBekleyen').text(r.bekleyen);
                const box = $('#dagitKisiler').empty();
                if (!r.kisiler || !r.kisiler.length) {
                    box.html('<div class="text-muted">Bu birimde atanabilecek kullanıcı bulunamadı.</div>');
                } else {
                    r.kisiler.forEach(function (k) {
                        const dep = departmanGoster && k.departman ? ` <span class="text-muted small">(${escapeHtml(k.departman)})</span>` : '';
                        box.append(`<div class="dagit-satir d-flex align-items-center justify-content-between gap-2 py-1 border-bottom">
                            <div class="form-check form-switch mb-0 flex-grow-1">
                                <input class="form-check-input dagit-kisi" type="checkbox" role="switch" value="${k.id}" id="dk_${k.id}" ${departmanGoster ? '' : 'checked'}>
                                <label class="form-check-label" for="dk_${k.id}"><span class="dagit-kisi-ad">${escapeHtml(k.ad || ('#' + k.id))}</span>${dep}</label>
                            </div>
                            <input type="number" class="form-control form-control-sm dagit-adet" data-id="${k.id}" min="0" step="1" value="0" style="width:90px" ${departmanGoster ? 'disabled' : ''}>
                        </div>`);
                    });
                    esitDagit();
                }
                const tumu = !departmanGoster;
                $('#dagitKisiTumu').prop('checked', tumu).prop('indeterminate', false)
                    .prop('disabled', !(r.kisiler && r.kisiler.length));
                guncelleDagitOnizleme();
                $('#dagitOnizlemeAlan').removeClass('d-none');
                $('#btnDagitUygula').removeClass('d-none');
        }

        // Şablon butonu: profil önizlemesini yükle, alanları işaretle
        $('.dagit-sablon').on('click', function () {
            const tip = $(this).data('tip');
            $('.dagit-sablon').prop('disabled', true);
            $.post('', Object.assign({ action: 'dagit_onizleme', tip: tip }, dagitFiltreler()), function (r) {
                $('.dagit-sablon').prop('disabled', false);
                if (!r.success) { showToast(r.message || 'Önizleme alınamadı', 'error'); return; }
                dagitOnizlemeDoldur(tip, r, false);
            }, 'json').fail(() => {
                $('.dagit-sablon').prop('disabled', false);
                showToast('Önizleme alınamadı', 'error');
            });
        });

        // Filtre anahtarları değişince, açık bir önizleme varsa bekleyen sayısını yeniden çek
        $('.dagit-filtre').on('change', function () {
            if (dagitTip) $('.dagit-sablon[data-tip="' + dagitTip + '"]').trigger('click');
        });

        // "Reklamdan gelenler" açık/kapalı → sayfa seçim dropdown'ını göster/gizle
        $('#dagitSadeceReklam').on('change', function () {
            $('#dagitReklamSayfaAlan').toggleClass('d-none', !this.checked);
            if (!this.checked) {
                $('.dagit-reklam-sayfa').prop('checked', false);
                dagitReklamSayfaOzet();
            }
        });

        // Sayfa listesinde arama
        $('#dagitReklamSayfaAra').on('input', function () {
            const q = (this.value || '').toLocaleLowerCase('tr');
            $('.dagit-sayfa-satir').each(function () {
                $(this).toggleClass('d-none', q !== '' && String($(this).data('ad')).indexOf(q) === -1);
            });
        });

        // Tümünü seç / temizle (yalnız görünür satırlar)
        $('#dagitReklamSayfaTumu').on('click', function () {
            $('.dagit-sayfa-satir:not(.d-none) .dagit-reklam-sayfa').prop('checked', true).last().trigger('change');
        });
        $('#dagitReklamSayfaTemizle').on('click', function () {
            $('.dagit-reklam-sayfa').prop('checked', false).last().trigger('change');
        });

        // Sayfa seçimi değişince özeti güncelle ve önizlemeyi tazele
        $(document).on('change', '.dagit-reklam-sayfa', function () {
            dagitReklamSayfaOzet();
            if (dagitTip) $('.dagit-sablon[data-tip="' + dagitTip + '"]').trigger('click');
        });

        // Kişiler: tümünü seç / kaldır → adetleri eşit dağıtarak yeniden doldur
        $('#dagitKisiTumu').on('change', function () {
            const secili = this.checked;
            $('.dagit-kisi').each(function () {
                this.checked = secili;
                $('.dagit-adet[data-id="' + this.value + '"]').prop('disabled', !secili);
            });
            esitDagit();
        });

        $(document).on('change', '.dagit-kisi', function () {
            const $adet = $('.dagit-adet[data-id="' + this.value + '"]');
            $adet.prop('disabled', !this.checked);
            if (!this.checked) $adet.val(0);
            const toplam = $('.dagit-kisi').length, secili = $('.dagit-kisi:checked').length;
            $('#dagitKisiTumu').prop('checked', toplam > 0 && secili === toplam)
                               .prop('indeterminate', secili > 0 && secili < toplam);
            // Manuel modda kişi seçildikçe seçili başvurular otomatik eşit bölünür
            if (dagitTip === 'manuel') { esitDagit(); return; }
            guncelleDagitOnizleme();
        });
        $(document).on('input', '.dagit-adet', guncelleDagitOnizleme);
        $('#btnEsitDagit').on('click', esitDagit);

        $('#btnDagitUygula').on('click', function () {
            if (!dagitTip) { showToast('Önce bir şablon seçin', 'warning'); return; }
            const bekleyen = parseInt($('#dagitBekleyen').text(), 10) || 0;
            const adetMap  = dagitAdetMap();
            const idler    = Object.keys(adetMap);
            const toplam   = idler.reduce((s, id) => s + adetMap[id], 0);
            const profilAd = $('#dagitProfilAd').text();
            if (bekleyen === 0)  { showToast('Dağıtılacak başvuru yok', 'warning'); return; }
            if (!idler.length)   { showToast('En az bir kişiye 1 veya daha fazla adet girin', 'warning'); return; }
            if (toplam > bekleyen) { showToast('Girilen toplam (' + toplam + '), bekleyenden (' + bekleyen + ') fazla olamaz', 'warning'); return; }
            const satirlar = idler.map(function (id) {
                const ad = escapeHtml($('label[for="dk_' + id + '"] .dagit-kisi-ad').text());
                return `<tr><td class="text-start">${ad}</td><td class="text-end"><strong>${adetMap[id]}</strong></td></tr>`;
            }).join('');
            Swal.fire({
                title: profilAd + ' — Dağıtım onayı',
                html: `Toplam <strong>${toplam}</strong> başvuru aşağıdaki gibi atanacak:
                       <table class="table table-sm mt-2 mb-0"><tbody>${satirlar}</tbody></table>`,
                icon: 'question', showCancelButton: true,
                confirmButtonText: 'Evet, dağıt', cancelButtonText: 'İptal'
            }).then(function (res) {
                if (!res.isConfirmed) return;
                $('#btnDagitUygula').prop('disabled', true);
                const veri = dagitTip === 'manuel'
                    ? { action: 'dagit_manuel_uygula', basvuru_ids: dagitManuelIds, adetler: adetMap, karaliste_haric: dagitKaraListeHaric() }
                    : Object.assign({ action: 'dagit_uygula', tip: dagitTip, adetler: adetMap }, dagitFiltreler());
                $.post('', veri, function (r) {
                    if (r.success) {
                        showToast(r.message, 'success');
                        bootstrap.Modal.getInstance(document.getElementById('dagitModal')).hide();
                        dataTable.ajax.reload();
                        loadStats();
                    } else {
                        showToast(r.message || 'Dağıtım başarısız', 'error');
                    }
                }, 'json').fail(() => showToast('Dağıtım başarısız', 'error'))
                  .always(() => $('#btnDagitUygula').prop('disabled', false));
            });
        });
    });

    // Modal özeti: seçili kişi sayısı + toplam girilen + kalan (bekleyen - girilen)
    // Dağıt modalı filtre anahtarları → POST parametreleri
    function dagitFiltreler() {
        const reklam = $('#dagitSadeceReklam').is(':checked');
        return {
            sadece_otp:      $('#dagitSadeceOtp').is(':checked') ? '1' : '0',
            sadece_isimli:   $('#dagitSadeceIsimli').is(':checked') ? '1' : '0',
            sadece_reklam:   reklam ? '1' : '0',
            reklam_leadformlari: reklam ? dagitSeciliLeadFormlari() : [],
            karaliste_haric: dagitKaraListeHaric(),
        };
    }

    function dagitKaraListeHaric() {
        return $('#dagitKaraListeHaric').is(':checked') ? '1' : '0';
    }

    // Dağıt modalında seçili lead formu ID'leri
    function dagitSeciliLeadFormlari() {
        return $('.dagit-reklam-sayfa:checked').map(function () { return this.value; }).get();
    }

    // Dropdown butonundaki özet metni (seçim yoksa "Tüm lead formları")
    function dagitReklamSayfaOzet() {
        const $sec = $('.dagit-reklam-sayfa:checked');
        const n = $sec.length;
        let metin = 'Tüm lead formları';
        if (n === 1)      metin = $('label[for="drs_' + $sec.val() + '"]').text().trim();
        else if (n > 1)   metin = n + ' lead formu seçildi';
        $('#dagitReklamSayfaOzet').text(metin);
    }

    function guncelleDagitOnizleme() {
        const bekleyen = parseInt($('#dagitBekleyen').text(), 10) || 0;
        const secili   = $('.dagit-kisi:checked').length;
        let girilen = 0;
        $('.dagit-kisi:checked').each(function () {
            girilen += parseInt($('.dagit-adet[data-id="' + this.value + '"]').val(), 10) || 0;
        });
        const kalan = bekleyen - girilen;
        $('#dagitSecili').text(secili);
        $('#dagitGirilen').text(girilen);
        $('#dagitKalan').text(kalan).toggleClass('text-danger', kalan < 0);
    }

    // Seçili kişilerin { kullanici_id: adet } eşlemesi (0/boş girişler atlanır)
    function dagitAdetMap() {
        const map = {};
        $('.dagit-kisi:checked').each(function () {
            const adet = parseInt($('.dagit-adet[data-id="' + this.value + '"]').val(), 10) || 0;
            if (adet > 0) map[this.value] = adet;
        });
        return map;
    }

    // Bekleyeni seçili kişilere eşit böl, kutulara yaz (kalan artık ilk kişilere +1)
    function esitDagit() {
        const bekleyen = parseInt($('#dagitBekleyen').text(), 10) || 0;
        const $secili  = $('.dagit-kisi:checked');
        $('.dagit-adet').val(0);
        const n = $secili.length;
        if (n > 0 && bekleyen > 0) {
            const taban = Math.floor(bekleyen / n);
            let artan   = bekleyen % n;
            $secili.each(function () {
                $('.dagit-adet[data-id="' + this.value + '"]').val(taban + (artan-- > 0 ? 1 : 0));
            });
        }
        guncelleDagitOnizleme();
    }

    function loadStats() {
        $.post('', { action: 'stats' }, function (r) {
            if (!r.success) return;
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-bugun').text(r.data.bugun);
            $('#stat-atanmamis').text(r.data.atanmamis);
            $('#stat-kidemsiz').text(r.data.kidemsiz);
        }, 'json');
        loadKirilim();
    }

    // Günlük personel kırılımı: her durum değeri için ayrı kutu
    function loadKirilim() {
        $.post('', { action: 'stats_kirilim' }, function (r) {
            if (!r.success) return;
            renderKirilimGrid('#kirilim-basvuru', r.data.basvuru, 'primary');
            renderKirilimGrid('#kirilim-iletisim', r.data.iletisim, 'info');
        }, 'json');
    }

    function renderKirilimGrid(containerSel, durumlar, varsayilanRenk) {
        const $c = $(containerSel);
        if (!durumlar || !durumlar.length) {
            $c.html('<div class="col-12 text-muted text-center py-2">Durum tanımı yok</div>');
            return;
        }
        const pre = containerSel.replace('#', '');
        let html = '';
        durumlar.forEach(function (d, i) {
            if (!d.toplam) return; // veri olmayan (bugün kaydı bulunmayan) durum kutusu gizlenir
            const renk = d.renk || '';
            const baslikAttr = renk
                ? `style="background:${renk};color:#fff;cursor:pointer"`
                : `class="text-bg-${varsayilanRenk}" style="cursor:pointer"`;
            const cid = `${pre}-box-${i}`;
            let ic = '';
            d.birimler.forEach(function (g) {
                ic += `<div class="mb-2">
                    <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-1">
                        <strong style="font-size:.8rem"><i class="bi bi-diagram-3 me-1"></i>${escapeHtml(g.birim)}</strong>
                        <span class="badge bg-secondary">${g.toplam}</span>
                    </div>`;
                g.kisiler.forEach(function (k) {
                    ic += `<div class="d-flex justify-content-between px-1 py-1" style="font-size:.8rem">
                        <span><i class="bi bi-person me-1 text-muted"></i>${escapeHtml(k.ad)}</span>
                        <span class="badge bg-light text-dark">${k.adet}</span>
                    </div>`;
                });
                ic += `</div>`;
            });
            // İlk yüklemede yalnızca başlık (durum + toplam) görünür; başlığa tıklanınca akordiyon açılır
            html += `<div class="col-xl-3 col-lg-4 col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center py-2" ${baslikAttr}
                         role="button" data-bs-toggle="collapse" data-bs-target="#${cid}" aria-expanded="false" aria-controls="${cid}">
                        <span style="font-weight:600"><i class="bi bi-chevron-down me-1"></i>${escapeHtml(d.durum)}</span>
                        <span class="badge bg-light text-dark">${d.toplam}</span>
                    </div>
                    <div class="collapse" id="${cid}">
                        <div class="card-body p-2" style="max-height:280px;overflow:auto">${ic}</div>
                    </div>
                </div>
            </div>`;
        });
        $c.html(html || '<div class="col-12 text-muted text-center py-2">Bugün kayıt yok</div>');
    }

    // Listeyi yeniden yükle (sayfada kalarak)
    function loadList() {
        if (dataTable) dataTable.ajax.reload(null, false);
    }

    function badge(text, renk) {
        if (!text) return '<span class="text-muted">-</span>';
        const bg = renk || '#6c757d';
        return `<span class="status-badge" style="background:${bg};color:#fff">${escapeHtml(text)}</span>`;
    }

    // ReklamLeadFormlari_ID dolu mu? (dolu = reklam lead formundan gelen başvuru)
    function leadFormuCell(row) {
        const id    = row.LeadFormuID;
        const dolu  = (id !== null && id !== undefined && id !== '');
        const icon  = dolu ? 'bi-megaphone-fill' : 'bi-dash-circle';
        const cls   = dolu ? 'text-primary' : 'text-muted';
        const title = dolu ? (row.LeadFormuAdi || 'Reklam lead formu') : 'Reklam lead formu yok';
        return `<i class="bi ${icon} ${cls}" style="font-size:1.15rem" title="${escapeHtml(title)}"></i>`;
    }

    function telCell(row) {
        const ulke = row.phoneCountryNumber || '';
        const alan = row.phoneAreaNumber || '';
        const no   = row.phoneNumber || '';
        if (!(ulke || alan || no)) return '<span class="text-muted">-</span>';
        const goster = (ulke + ' ' + alan + ' ' + no).replace(/\s+/g, ' ').trim();
        const kopya  = (ulke + alan + no).replace(/\D/g, ''); // boşluksuz, yalnız rakam
        const mAdet  = parseInt(row.MukerrerAdet, 10) || 0;
        const mBadge = mAdet > 1
            ? ` <span class="badge bg-danger" title="Bu telefon numarası ${mAdet} başvuruda mükerrer"><i class="bi bi-exclamation-triangle-fill"></i> ${mAdet}x</span>`
            : '';
        const kBadge = row.KaraListeTur === 'gsm' ? karaListeBadge(row) : '';
        return `<span class="mono tel-kopya" data-tel="${escapeHtml(kopya)}" title="Kopyalamak için tıkla" style="cursor:pointer">${escapeHtml(goster)}</span>${mBadge}${kBadge}`;
    }

    function karaListeBadge(row) {
        const acik = (row.KaraListeAciklama || '').trim();
        const title = 'Kara listede' + (acik ? ': ' + acik : '');
        return ` <span class="badge bg-dark" title="${escapeHtml(title)}"><i class="bi bi-slash-circle-fill"></i> Kara Liste</span>`;
    }

    function tcCell(row) {
        const tc = row.TCKimlikNo || '';
        if (!tc) return '<span class="text-muted">-</span>';
        return escapeHtml(tc) + (row.KaraListeTur === 'tc' ? karaListeBadge(row) : '');
    }

    function durumMesajCell(dmRaw) {
        dmRaw = dmRaw || '';
        return dmRaw
            ? `<span title="${escapeHtml(dmRaw)}">${escapeHtml(dmRaw.length > 10 ? dmRaw.substring(0, 10) + '…' : dmRaw)}</span>`
            : '<span class="text-muted">-</span>';
    }

    function atananCell(row) {
        const ad  = (row.AtananAd || '').trim();
        const soy = (row.AtananSoyad || '').trim();
        if (!ad && !soy) return '<span class="text-muted" title="Atanmamış">—</span>';
        const bas = s => s ? s.charAt(0).toLocaleUpperCase('tr-TR') : '';
        const bh  = (bas(ad) + bas(soy)) || '?';
        const tam = (ad + ' ' + soy).trim();
        const id  = row.AtananID || '';
        // İsimden deterministik renk (aynı kişi → aynı renk)
        let h = 0; for (let i = 0; i < tam.length; i++) h = (h * 31 + tam.charCodeAt(i)) % 360;
        return `<span class="atanan-avatar" data-atanan-id="${escapeHtml(id)}" `
             + `title="Bu kullanıcıya göre filtrele: ${escapeHtml(tam)}" `
             + `style="cursor:pointer;display:inline-flex;align-items:center;justify-content:center;`
             + `width:30px;height:30px;border-radius:50%;background:hsl(${h},55%,42%);`
             + `color:#fff;font-size:12px;font-weight:600;line-height:1">${escapeHtml(bh)}</span>`;
    }

    function islemlerCell(row) {
        let islemler = '';
        if (permissions.canEdit)
            islemler += `<a href="/Admin/basvuru-form?id=${row.Basvurular_id}" class="btn btn-sm btn-warning me-1" title="Düzenle"><i class="bi bi-pencil"></i></a>`;
        if (permissions.canEdit)
            islemler += `<button class="btn btn-sm btn-info me-1" onclick="apiGonderAc(${row.Basvurular_id})" title="API'ye Gönder"><i class="bi bi-cloud-upload"></i></button>`;
        if (permissions.canEdit)
            islemler += `<button class="btn btn-sm btn-secondary me-1" onclick="otpSorgu(${row.Basvurular_id})" title="OTP Sorgula"><i class="bi bi-phone-vibrate"></i></button>`;
        // Teyit SMS'i yalnız satış talebi oluşmuş (TalepKayitNo dolu) kayıtlarda gönderilebilir
        if (permissions.canEdit && row.TalepKayitNo)
            islemler += `<button class="btn btn-sm btn-outline-success me-1" onclick="smsTekrar(${row.Basvurular_id}, '${row.TalepKayitNo}')" title="Teyit SMS'ini Tekrar Gönder"><i class="bi bi-chat-dots"></i></button>`;
        if (permissions.canDelete)
            islemler += `<button class="btn btn-sm btn-danger" onclick="deleteRecord(${row.Basvurular_id})" title="Sil"><i class="bi bi-trash"></i></button>`;
        return islemler || '<span class="text-muted">-</span>';
    }

    // ── Numara Sildirme (üstteki buton, seçili kayıtlar) ──
    let uyeSildirmeIdler = [], uyeSildirmeEksikVar = false;
    function uyeSildirmeSayacGuncelle() {
        $('#uyeSildirmeSayac').text($('#kayitTable tbody .otp-secim:checked').length);
    }
    function uyeSildirmeAc() {
        const ids = $('#kayitTable tbody .otp-secim:checked').map(function () { return this.value; }).get();
        if (!ids.length) { showToast('Numara sildirme için en az bir kayıt seçin.', 'warning'); return; }
        uyeSildirmeIdler = ids; uyeSildirmeEksikVar = false;
        $('#usAdet').text(ids.length);
        $('#usKayitlar').html('<tr><td colspan="4" class="text-center text-muted"><i class="bi bi-arrow-repeat spin"></i> Yükleniyor...</td></tr>');
        $('#usEksikUyari').addClass('d-none').empty();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('uyeSildirmeModal')).show();
        $.post('', { action: 'uye_sildirme', ids: ids, onizle: '1' }, function (r) {
            if (!r.success) { $('#usKayitlar').html(''); showToast(r.message || 'Kayıtlar alınamadı', 'error'); return; }
            const bos = v => v ? escapeHtml(v) : '<span class="text-danger">Yok</span>';
            $('#usKayitlar').html(r.kayitlar.map((k, i) =>
                `<tr class="${k.eksik.length ? 'table-warning' : ''}"><td>${i + 1}</td><td>${bos(k.AdSoyad)}</td>`
                + `<td class="mono">${bos(k.TC)}</td><td class="mono">${bos(k.Telefon)}</td></tr>`).join(''));
            uyeSildirmeEksikVar = !!r.eksikVar;
            if (uyeSildirmeEksikVar) {
                const n = r.kayitlar.filter(k => k.eksik.length).length;
                $('#usEksikUyari').removeClass('d-none')
                    .html('<i class="bi bi-exclamation-triangle"></i> <strong>' + n + '</strong> kayıtta eksik alan var (sarı satırlar).');
                showToast(n + ' kayıtta eksik alan var', 'warning');
            }
        }, 'json').fail(() => showToast('Kayıtlar alınamadı', 'error'));
    }

    function uyeSildirmeGonder(kanal, btn) {
        const gonder = (zorla) => {
            const $b = $(btn), eski = $b.html();
            $b.prop('disabled', true).html('<i class="bi bi-arrow-repeat spin"></i> Gönderiliyor...');
            $.post('', { action: 'uye_sildirme', ids: uyeSildirmeIdler, kanal: kanal, zorla: zorla ? '1' : '0' }, function (r) {
                showToast(r.message || (r.success ? 'Gönderildi' : 'Gönderilemedi'), r.success ? 'success' : 'error');
                if (r.success) bootstrap.Modal.getInstance(document.getElementById('uyeSildirmeModal')).hide();
            }, 'json').fail(() => showToast('Gönderim başarısız', 'error'))
              .always(() => $b.prop('disabled', false).html(eski));
        };
        if (!uyeSildirmeEksikVar) { gonder(false); return; }
        Swal.fire({
            title: 'Eksik alan var',
            html: 'Seçili kayıtların bazılarında İsim Soyisim, TC veya Telefon boş.<br>Yine de gönderilsin mi?',
            icon: 'warning', showCancelButton: true,
            confirmButtonText: 'Yine de Gönder', cancelButtonText: 'İptal'
        }).then(res => { if (res.isConfirmed) gonder(true); });
    }

    $(document).on('click', '#btnUsEmail',    function () { uyeSildirmeGonder('email', this); });
    $(document).on('click', '#btnUsWhatsapp', function () { uyeSildirmeGonder('whatsapp', this); });

    // Dijital teyit SMS'ini yeniden gönderir (Endpoint 29 — Order/SendSmsAgain).
    // Müşteriye ödeme bilgisi girişi ve sözleşme onayı için giden SMS'tir.
    // Doküman: aynı üyeye son 24 saatte gönderim yapıldıysa yenisi gitmez.
    function smsTekrar(id, talepNo) {
        Swal.fire({
            title: 'Teyit SMS\'i tekrar gönderilsin mi?',
            html: `Talep <strong>${escapeHtml(String(talepNo))}</strong> için müşteriye
                   ödeme bilgisi girişi ve sözleşme onayı SMS'i <strong>yeniden gönderilecek</strong>.
                   <div class="text-muted small mt-2">Son 24 saat içinde gönderim yapıldıysa
                   Digiturk yeni SMS göndermez.</div>`,
            icon: 'question', showCancelButton: true,
            confirmButtonText: 'Evet, gönder', cancelButtonText: 'İptal'
        }).then(function (res) {
            if (!res.isConfirmed) return;
            Swal.fire({ title: 'SMS gönderiliyor...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
            $.post('', { action: 'sms_tekrar', id: id }, function (r) {
                Swal.close();
                showToast(r.message || (r.success ? 'Gönderildi' : 'Gönderilemedi'), r.success ? 'success' : 'error');
            }, 'json').fail(function () {
                Swal.close();
                showToast('Teyit SMS isteği başarısız', 'error');
            });
        });
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Seçim kolonu: yalnız OTP GÖNDERİLMEMİŞ (OtpGonderimTarihi boş) kayıtlar seçilebilir.
    // Gönderilmiş olanlar otomatik atlanır — kutu yerine soluk tik gösterilir.
    // Numara Sildirme yetkisi varsa gönderilmiş kayıtlar da seçilebilir (data-otp-gonderildi ile işaretlenir,
    // toplu OTP bunları yine atlar).
    function otpSecimCell(row) {
        if (row.OtpGonderimTarihi) {
            // Numara Sildirme veya Dağıt yetkisi varsa seçilebilir (manuel dağıtım için)
            if (!permissions.uyeSildirme && !permissions.dagit)
                return '<i class="bi bi-check2 text-muted" title="OTP gönderilmiş — toplu sorguda atlanır"></i>';
            return `<input type="checkbox" class="form-check-input otp-secim" data-otp-gonderildi="1" value="${row.Basvurular_id}" title="OTP gönderilmiş — toplu OTP'de atlanır">`;
        }
        return `<input type="checkbox" class="form-check-input otp-secim" value="${row.Basvurular_id}">`;
    }

    // Seçili (gönderilmemiş) kayıt sayacını butonda güncelle
    function topluOtpSayacGuncelle() {
        $('#topluOtpSayac').text($('#kayitTable tbody .otp-secim:checked:not([data-otp-gonderildi])').length);
        uyeSildirmeSayacGuncelle();
    }

    // OTP durumunu ikonla göster (dar kolon)
    function otpDurumIkon(row) {
        const d = row.OtpDurum;
        let icon, cls, title;
        if (d === 'onayli') {
            icon = 'bi-check-circle-fill'; cls = 'text-success';
            title = 'Onaylı' + (row.OtpOnayTarihi ? ' — ' + row.OtpOnayTarihi : '');
        } else if (d === 'beklemede') {
            icon = 'bi-hourglass-split'; cls = 'text-warning';
            title = 'Beklemede' + (row.OtpSonMesaj ? ' — ' + row.OtpSonMesaj : '');
        } else if (d === 'iptal') {
            icon = 'bi-x-circle-fill'; cls = 'text-danger'; title = 'İptal';
        } else {
            icon = 'bi-dash-circle'; cls = 'text-muted'; title = 'OTP gönderilmedi';
        }
        return `<i class="bi ${icon} ${cls}" style="font-size:1.15rem" title="${escapeHtml(title)}"></i>`;
    }

    // ===== OTP Onay Ekranı (smsFormat=false → url alır, modalda iframe açar) =====
    function otpSorgu(id) {
        Swal.fire({ title: 'OTP onay ekranı alınıyor...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
        $.ajax({
            url: '', method: 'POST', dataType: 'json',
            data: { action: 'otp_sorgu', id: id, onay: 1 },
            success: function (r) {
                Swal.close();
                if (r.success) {
                    if (r.durum === 'onayli') {
                        // Zaten onaylı → modal açmaya gerek yok
                        showToast(r.message, 'success');
                    } else if (r.url) {
                        otpOnayModalAc(r.url);
                        showToast(r.message, 'info');
                    } else {
                        showToast(r.message + ' (Onay URL dönmedi)', 'warning');
                    }
                    loadList();
                } else {
                    showToast(r.message, 'error');
                }
            },
            error: function () { Swal.close(); showToast('Sunucuya ulaşılamıyor', 'error'); }
        });
    }

    // Onay ekranını modal içinde iframe olarak açar
    function otpOnayModalAc(url) {
        $('#otpOnayFrame').hide().attr('src', url);
        $('#otpOnayYukleniyor').show();
        $('#otpOnayYeniSekme').attr('href', url);
        otpOnayModal.show();
    }

    // redirectUrl onay sayfası (otp-redirect.php) onaylandığında parent'a postMessage atar → anlık güncelle
    window.addEventListener('message', function (e) {
        if (e.data && e.data.otp === 'onayli') {
            try { otpOnayModal.hide(); } catch (_) {}
            showToast('OTP onayı alındı — durum güncellendi', 'success');
            loadList();
        }
    });

    // ===== Toplu OTP Sorgula (seçili, gönderilmemiş kayıtları sıralı sorgular) =====
    function topluOtpSorgula() {
        const ids = $('#kayitTable tbody .otp-secim:checked:not([data-otp-gonderildi])').map(function () { return this.value; }).get();
        if (!ids.length) {
            showToast('OTP gönderilmemiş en az bir kayıt seçin.', 'warning');
            return;
        }
        confirmAction(
            'Toplu OTP sorgulansın mı?',
            ids.length + ' kayıt tek tek sorgulanacak. OTP gönderilmiş kayıtlar zaten seçilemez.',
            function () {
                const toplam = ids.length;
                let i = 0, onayli = 0, hata = 0;
                Swal.fire({
                    title: 'Toplu OTP sorgulanıyor...',
                    html: '<b>0</b> / ' + toplam,
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });
                function next() {
                    if (i >= toplam) {
                        Swal.close();
                        showToast(
                            'Tamamlandı: ' + toplam + ' sorgulandı, ' + onayli + ' onaylı, ' + hata + ' hata.',
                            hata ? 'warning' : 'success'
                        );
                        loadList();
                        loadStats();
                        return;
                    }
                    $.ajax({
                        url: '', method: 'POST', dataType: 'json',
                        data: { action: 'otp_sorgu', id: ids[i] },
                        success: function (r) {
                            if (r.success) { if (r.durum === 'onayli') onayli++; }
                            else { hata++; }
                        },
                        error: function () { hata++; },
                        complete: function () {
                            i++;
                            Swal.update({ html: '<b>' + i + '</b> / ' + toplam });
                            next();
                        }
                    });
                }
                next();
            }
        );
    }

    function deleteRecord(id) {
        confirmAction(
            'Bu başvuruyu silmek istediğinize emin misiniz?',
            'Bu işlem geri alınamaz!',
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

    // ===== API'ye Gönder =====
    function apiGonderAc(id) {
        apiAktifId = id;
        $('#apiMeta').html('');
        $('#apiGonderilen').text('Yükleniyor...');
        $('#apiYanit').text('— Henüz gönderilmedi —');
        $('#btnApiGonder').prop('disabled', true);
        apiModal.show();
        $.post('', { action: 'api_onizle', id: id }, function (r) {
            if (!r.success) {
                $('#apiGonderilen').text('');
                $('#apiMeta').html('<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> ' + escapeHtml(r.message) + '</span>');
                return;
            }
            if (r.mode === 'surec') {
                $('#apiMeta').html('<b>Endpoint:</b> <span class="mono">' + escapeHtml(r.method + ' ' + r.endpoint) + '</span>');
                $('#apiGonderilen').text(r.not || '(boş gövde — yalnızca süreç sorgusu)');
                $('#btnApiGonder').prop('disabled', false);
                return;
            }
            $('#apiMeta').html(
                '<b>Endpoint:</b> <span class="mono">' + escapeHtml(r.method + ' ' + r.endpoint) + '</span><br>' +
                '<b>Token Personeli:</b> ' + escapeHtml(r.personel || '-')
            );
            $('#apiGonderilen').text(JSON.stringify(r.body, null, 2));
            $('#btnApiGonder').prop('disabled', false);
        }, 'json').fail(function () {
            $('#apiGonderilen').text('');
            $('#apiMeta').html('<span class="text-danger">Önizleme alınamadı</span>');
        });
    }

    function apiGonder() {
        if (!apiAktifId) return;
        const $btn = $('#btnApiGonder');
        const eski = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Gönderiliyor...');
        $('#apiYanit').text('Bekleniyor...');
        $.post('', { action: 'api_gonder', id: apiAktifId }, function (r) {
            if (!r.success) {
                $('#apiYanit').text(r.message || 'Hata');
                if (r.gonderilen) $('#apiGonderilen').text(r.gonderilen);
                showError('Hata!', r.message || 'Gönderim başarısız');
                return;
            }
            if (r.gonderilen) $('#apiGonderilen').text(r.gonderilen);
            $('#apiYanit').text(r.yanit || '');
            const ozet = r.durumMesaji || r.responseMessage || (r.gonderildi ? 'Başarılı' : 'Başarısız');
            if (r.gonderildi) showSuccess('Gönderildi!', ozet);
            else              showError('Başarısız', ozet + ' (HTTP ' + r.http + ')');
            if (r.surecBilgi) showToast(r.surecBilgi, 'info');
            loadList();
            loadStats();
        }, 'json').fail(function () {
            $('#apiYanit').text('Sunucu hatası');
            showError('Bağlantı Hatası!', 'Sunucuya ulaşılamadı.');
        }).always(function () {
            $btn.prop('disabled', false).html(eski);
        });
    }
</script>
</body>
</html>
