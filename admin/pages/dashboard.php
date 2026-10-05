<?php
/**
 * Admin Panel - Varsayılan Dashboard (Anasayfa)
 * Finans (Ödemeler / VoIP / Hakediş) + Bayi & Personel özeti.
 * NOT: Bu dosya anasayfa.php tarafından include edilir, doğrudan çağrılmaz!
 * $user ve $db değişkenleri anasayfa.php'den gelir.
 */

// Site başlığı
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// ======================================================================
// BİRİM GÖRÜNÜRLÜK KISITI
// Admin (departman_id=1) her şeyi görür. Diğer kullanıcılar yalnız kendi
// birimi + alt birimlerinin verilerini görür.
// ======================================================================
$isAdmin      = ((int)($user['departman_id'] ?? 0) === 1);
$birimKisitli = !$isAdmin;
$izinliBirimler = [];

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
    // kullanici_birim_id boşsa $izinliBirimler boş → hiçbir veri görünmez (güvenli varsayılan)
}

// Kısıtlı + birimi yoksa hiçbir kayıt görünmesin
$hicYok = ($birimKisitli && empty($izinliBirimler));

// --- Odemeler (o alias) birim WHERE parçası ---
$odemeW = '';
$odemeP = [];
if ($birimKisitli) {
    if ($hicYok) {
        $odemeW = ' AND 1=0 ';
    } else {
        $ph     = implode(',', array_fill(0, count($izinliBirimler), '?'));
        $odemeW = " AND o.Odemeler_KullaniciBirim_id IN ($ph) ";
        $odemeP = $izinliBirimler;
    }
}

// Alt Bayi birim EXISTS parçası (junction: KullaniciBirimYetkileri_AltBayi_id)
function dashAltBayiBirimExists(array $izinliBirimler, string $idExpr): string {
    $ph = implode(',', array_fill(0, count($izinliBirimler), '?'));
    return "EXISTS (
        SELECT 1 FROM KullaniciBirimYetkileri kby
        WHERE kby.KullaniciBirimYetkileri_AltBayi_id = $idExpr
          AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
          AND kby.Durum = 1
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
    )";
}

// Personel birim EXISTS parçası (silsile: doğrudan personel VEYA bağlı alt bayi yetkisi)
function dashPersonelBirimExists(array $izinliBirimler, string $perIdExpr, string $altBayiIdExpr): string {
    $ph = implode(',', array_fill(0, count($izinliBirimler), '?'));
    return "EXISTS (
        SELECT 1 FROM KullaniciBirimYetkileri kby
        WHERE kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
          AND kby.Durum = 1
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
          AND (kby.KullaniciBirimYetkileri_Personel_id = $perIdExpr
            OR kby.KullaniciBirimYetkileri_AltBayi_id  = $altBayiIdExpr)
    )";
}

// IrisRapor birim kısıtı: bayi adı (IrisRapor_TalebiGirenPersonelAltbayi)
// → DigiturkAltBayiler_Ad → KullaniciBirimYetkileri → izinli birim.
// [where, params] döner.
function irisRaporBirimKisit(bool $hicYok, bool $birimKisitli, array $izinliBirimler, string $bayiAdExpr): array {
    if ($hicYok) {
        return [' AND 1=0 ', []];
    }
    if (!$birimKisitli) {
        return ['', []];
    }
    $ph = implode(',', array_fill(0, count($izinliBirimler), '?'));
    $w = " AND EXISTS (
        SELECT 1 FROM DigiturkAltBayiler a
        JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_AltBayi_id = a.DigiturkAltBayiler_Id
        WHERE a.DigiturkAltBayiler_Ad = $bayiAdExpr
          AND a.Durum = 1
          AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
          AND kby.Durum = 1
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
    ) ";
    return [$w, $izinliBirimler];
}

// VoIP birim kısıtı: VoIP ödemeleri Odemeler_KullaniciBirim_id ile değil,
// telefon (Odemeler_Referans) → VoIPHesaplar → birim junction ile bağlıdır.
// (voip-harcamalar.php ile aynı mantık.) [where, params] döner.
function dashVoipBirimWhere(bool $hicYok, bool $birimKisitli, array $izinliBirimler): array {
    if ($hicYok) {
        return [' AND 1=0 ', []];
    }
    if (!$birimKisitli) {
        return ['', []];
    }
    $ph = implode(',', array_fill(0, count($izinliBirimler), '?'));
    $w = " AND EXISTS (
        SELECT 1 FROM VoIPHesaplar vrk
        JOIN KullaniciBirimYetkileri kby ON kby.KullaniciBirimYetkileri_VoIPHesap_id = vrk.VoIPHesaplar_id
        WHERE vrk.VoIPHesaplar_TelefonNo = o.Odemeler_Referans
          AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
          AND kby.Durum = 1
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
    ) ";
    return [$w, $izinliBirimler];
}

/**
 * Başvuru birim kısıt WHERE parçası — SİLSİLE: ApiLead VEYA Personel/AltBayi VEYA
 * Meta Lead (LeadFormu → Sayfa → Birim) yetkisi. $izinliBirimler üst+alt birimleri
 * içerdiğinden hiyerarşi (üst birim alt birimi görür) otomatik uygulanır.
 * (basvuru-yonetimi.php'den taşındı — Dağılım kartları için.)
 * @return array [sqlFragment, params]
 */
function basvuruBirimKisitWhere(array $izinliBirimler, string $leadIdExpr, string $perIdExpr, string $altBayiIdExpr, string $leadFormIdExpr): array {
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
        )
    )";
    return [$sql, array_merge($izinliBirimler, $izinliBirimler, $izinliBirimler)];
}

// ======================================================================
// AJAX İşlemleri
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            // -------- Özet İstatistikler (InfoBox) --------
            case 'ozet_stats':
                $finans = $db->fetchOne("
                    SELECT
                        ISNULL(SUM(CASE WHEN t.OdemeTurleri_GelirMi = 1 THEN o.Odemeler_Tutar ELSE 0 END), 0) AS gelir,
                        ISNULL(SUM(CASE WHEN t.OdemeTurleri_GelirMi = 0 THEN o.Odemeler_Tutar ELSE 0 END), 0) AS gider
                    FROM Odemeler o
                    JOIN OdemeTurleri t ON o.Odemeler_OdemeTuruId = t.OdemeTurleri_Id
                    WHERE o.Durum = 1
                      AND MONTH(o.Odemeler_Tarih) = MONTH(GETDATE())
                      AND YEAR(o.Odemeler_Tarih)  = YEAR(GETDATE())
                      $odemeW
                ", $odemeP);

                // VoIP harcama = OdemeTurleri adı "VoIP Gideri" olan ödemeler (sabit id yerine isme göre).
                // Birim kısıtı $odemeW ile DEĞİL, telefon → VoIPHesaplar junction ile uygulanır.
                [$voipBirimW, $voipBirimP] = dashVoipBirimWhere($hicYok, $birimKisitli, $izinliBirimler);
                $voip = $db->fetchOne("
                    SELECT ISNULL(SUM(o.Odemeler_Tutar), 0) AS tutar
                    FROM Odemeler o
                    JOIN OdemeTurleri t ON o.Odemeler_OdemeTuruId = t.OdemeTurleri_Id
                    WHERE o.Durum = 1
                      AND t.OdemeTurleri_Ad = N'VoIP Gideri'
                      AND MONTH(o.Odemeler_Tarih) = MONTH(GETDATE())
                      AND YEAR(o.Odemeler_Tarih)  = YEAR(GETDATE())
                      $voipBirimW
                ", $voipBirimP);

                if ($hicYok) {
                    $altBayi = ['adet' => 0];
                    $personel = ['adet' => 0];
                } elseif ($birimKisitli) {
                    $altBayi = $db->fetchOne(
                        "SELECT COUNT(*) AS adet FROM DigiturkAltBayiler
                         WHERE Durum = 1 AND " . dashAltBayiBirimExists($izinliBirimler, 'DigiturkAltBayiler_Id'),
                        $izinliBirimler
                    );
                    $personel = $db->fetchOne(
                        "SELECT COUNT(*) AS adet FROM DigiturkAltBayiPersonel p
                         WHERE p.Durum = 1 AND " . dashPersonelBirimExists($izinliBirimler, 'p.DigiturkAltBayiPersonel_Id', 'p.DigiturkAltBayiPersonel_AltBayiId'),
                        $izinliBirimler
                    );
                } else {
                    $altBayi  = $db->fetchOne("SELECT COUNT(*) AS adet FROM DigiturkAltBayiler WHERE Durum = 1");
                    $personel = $db->fetchOne("SELECT COUNT(*) AS adet FROM DigiturkAltBayiPersonel WHERE Durum = 1");
                }

                // Ana Bayiler global tanım → kısıtlı kullanıcıdan gizlenir
                $anaBayi = $birimKisitli
                    ? ['adet' => 0]
                    : $db->fetchOne("SELECT COUNT(*) AS adet FROM DigiturkAnaBayiler WHERE Durum = 1");

                $gelir = floatval($finans['gelir'] ?? 0);
                $gider = floatval($finans['gider'] ?? 0);

                echo json_encode(['success' => true, 'data' => [
                    'bu_ay_net'    => $gelir - $gider,
                    'bu_ay_gelir'  => $gelir,
                    'bu_ay_gider'  => $gider,
                    'voip_harcama' => floatval($voip['tutar'] ?? 0),
                    'aktif_altbayi'=> intval($altBayi['adet'] ?? 0),
                    'aktif_anabayi'=> intval($anaBayi['adet'] ?? 0),
                    'saha_personel'=> intval($personel['adet'] ?? 0),
                ]]);
                break;

            // -------- Son 30 Gün ONAY Adet Trendi — ISP / NEO / UYDU (line) --------
            // Kaynak: DigiturkIrisRapor (bayi-gunluk-rapor ONAY mantığı).
            case 'onay_kurulum_trend':
                $bayi   = trim($_POST['bayi'] ?? '');
                $bayiW  = $bayi !== '' ? ' AND IrisRapor_TalebiGirenPersonelAltbayi = ? ' : '';
                $bayiP  = $bayi !== '' ? [$bayi] : [];
                // Birim görünürlük kısıtı (IrisRapor bayi adı → DigiturkAltBayiler → birim)
                [$birimW, $birimP] = irisRaporBirimKisit($hicYok, $birimKisitli, $izinliBirimler, 'IrisRapor_TalebiGirenPersonelAltbayi');
                $onayRows = $db->fetchAll("
                    SELECT CONVERT(VARCHAR(10), CAST(IrisRapor_TalepGirisTarihi AS DATE), 120) AS gun,
                           SUM(CASE WHEN IrisRapor_MemoKayitTipi = 'ISP'  THEN 1 ELSE 0 END) AS isp,
                           SUM(CASE WHEN IrisRapor_MemoKayitTipi = 'NEO'  THEN 1 ELSE 0 END) AS neo,
                           SUM(CASE WHEN IrisRapor_MemoKayitTipi = 'UYDU' THEN 1 ELSE 0 END) AS uydu
                    FROM dbo.DigiturkIrisRapor
                    WHERE IrisRapor_TeyitDurum = 'ONAYLANDI'
                      AND CAST(IrisRapor_TalepGirisTarihi AS DATE) >= CAST(DATEADD(DAY, -29, GETDATE()) AS DATE)
                      $bayiW
                      $birimW
                    GROUP BY CAST(IrisRapor_TalepGirisTarihi AS DATE)
                ", array_merge($bayiP, $birimP));

                $onayMap = [];
                foreach ($onayRows as $r) { $onayMap[$r['gun']] = $r; }

                $data = [];
                for ($i = 29; $i >= 0; $i--) {
                    $g = date('Y-m-d', strtotime("-$i day"));
                    $row = $onayMap[$g] ?? null;
                    $data[] = [
                        'gun'  => $g,
                        'isp'  => (int)($row['isp']  ?? 0),
                        'neo'  => (int)($row['neo']  ?? 0),
                        'uydu' => (int)($row['uydu'] ?? 0),
                    ];
                }
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            // -------- Bu Ay Gider Dağılımı (donut) --------
            case 'gider_dagilim':
                $data = $db->fetchAll("
                    SELECT
                        t.OdemeTurleri_Ad AS ad,
                        ISNULL(SUM(o.Odemeler_Tutar), 0) AS tutar
                    FROM Odemeler o
                    JOIN OdemeTurleri t ON o.Odemeler_OdemeTuruId = t.OdemeTurleri_Id
                    WHERE o.Durum = 1
                      AND t.OdemeTurleri_GelirMi = 0
                      AND MONTH(o.Odemeler_Tarih) = MONTH(GETDATE())
                      AND YEAR(o.Odemeler_Tarih)  = YEAR(GETDATE())
                      $odemeW
                    GROUP BY t.OdemeTurleri_Ad
                    HAVING SUM(o.Odemeler_Tutar) > 0
                    ORDER BY tutar DESC
                ", $odemeP);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            // -------- Son Ödemeler --------
            case 'son_odemeler':
                $data = $db->fetchAll("
                    SELECT TOP 8
                        o.Odemeler_Id,
                        t.OdemeTurleri_Ad,
                        t.OdemeTurleri_GelirMi,
                        o.Odemeler_Tutar,
                        o.Odemeler_Aciklama,
                        CONVERT(VARCHAR(10), o.Odemeler_Tarih, 104) AS tarih
                    FROM Odemeler o
                    JOIN OdemeTurleri t ON o.Odemeler_OdemeTuruId = t.OdemeTurleri_Id
                    WHERE o.Durum = 1
                      $odemeW
                    ORDER BY o.Odemeler_Tarih DESC, o.Odemeler_Id DESC
                ", $odemeP);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            // -------- En Çok Personelli Alt Bayiler --------
            case 'bayi_personel':
                if ($hicYok) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $bpW = $birimKisitli
                    ? ' AND ' . dashAltBayiBirimExists($izinliBirimler, 'ab.DigiturkAltBayiler_Id')
                    : '';
                $bpP = $birimKisitli ? $izinliBirimler : [];
                $data = $db->fetchAll("
                    SELECT TOP 8
                        ab.DigiturkAltBayiler_Ad,
                        an.DigiturkAnaBayiler_Ad AS ana_bayi,
                        (SELECT COUNT(*) FROM DigiturkAltBayiPersonel p
                          WHERE p.DigiturkAltBayiPersonel_AltBayiId = ab.DigiturkAltBayiler_Id
                            AND p.Durum = 1) AS personel_adet
                    FROM DigiturkAltBayiler ab
                    LEFT JOIN DigiturkAnaBayiler an ON ab.DigiturkAltBayiler_AnaBayiId = an.DigiturkAnaBayiler_Id
                    WHERE ab.Durum = 1
                      $bpW
                    ORDER BY personel_adet DESC, ab.DigiturkAltBayiler_Ad
                ", $bpP);
                echo json_encode(['success' => true, 'data' => $data]);
                break;

            // ── Dağılım kartları: birim bazlı + platform bazlı başvuru sayıları (tüm zamanlar) ──
            // basvuru-yonetimi.php'den taşındı. Birim kısıtı: admin değilse kendi birimi + alt birimler.
            case 'dagilim': {
                if ($hicYok) {
                    echo json_encode(['success' => true, 'birim' => [], 'platform' => []]);
                    break;
                }
                $wParts  = [];
                $wParams = [];
                if ($birimKisitli) {
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
                $wClause = $wParts ? implode(' AND ', $wParts) : '1=1';

                // Birim: personel/altbayi/lead → birim; bulunamazsa lead formu → sayfa → birim
                $birimDagilim = $db->fetchAll("
                    SELECT x.ad AS ad, COUNT(*) AS adet
                    FROM (
                        SELECT COALESCE(
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
                            'Tanımsız'
                        ) AS ad
                        FROM Basvurular t
                        WHERE $wClause
                    ) x
                    GROUP BY x.ad
                    ORDER BY adet DESC", $wParams);

                // Platform: SADECE Reklam Lead Formu olan başvurular sayılır (Call Center/API hariç).
                // lead formu → sayfa → kampanya → hesap → platform; zincir kopuksa 'Meta'.
                $platformDagilim = $db->fetchAll("
                    SELECT x.ad AS ad, COUNT(*) AS adet
                    FROM (
                        SELECT COALESCE(
                            (SELECT TOP 1 pl.ReklamPlatformlari_Adi
                               FROM ReklamLeadFormlari f
                               INNER JOIN ReklamFacebookSayfalari s ON s.ReklamFacebookSayfalari_id = f.ReklamLeadFormlari_Sayfa_id
                               LEFT JOIN ReklamKampanyalari k ON k.ReklamKampanyalari_id  = s.ReklamFacebookSayfalari_Kampanya_id
                               LEFT JOIN ReklamHesaplari     h ON h.ReklamHesaplari_id     = k.ReklamKampanyalari_Hesap_id
                               LEFT JOIN ReklamPlatformlari  pl ON pl.ReklamPlatformlari_id = h.ReklamHesaplari_Platform_id
                               WHERE f.ReklamLeadFormlari_id = t.ReklamLeadFormlari_ID),
                            'Meta'
                        ) AS ad
                        FROM Basvurular t
                        WHERE t.ReklamLeadFormlari_ID IS NOT NULL AND $wClause
                    ) x
                    GROUP BY x.ad
                    ORDER BY adet DESC", $wParams);

                echo json_encode(['success' => true, 'birim' => $birimDagilim, 'platform' => $platformDagilim]);
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

// ONAY grafiği bayi filtresi için distinct bayi listesi (birim kısıtına tabi)
[$listeBirimW, $listeBirimP] = irisRaporBirimKisit($hicYok, $birimKisitli, $izinliBirimler, 'IrisRapor_TalebiGirenPersonelAltbayi');
$bayiListesi = $db->fetchAll("
    SELECT DISTINCT IrisRapor_TalebiGirenPersonelAltbayi AS bayi
    FROM dbo.DigiturkIrisRapor
    WHERE IrisRapor_TalebiGirenPersonelAltbayi IS NOT NULL
      AND LTRIM(RTRIM(IrisRapor_TalebiGirenPersonelAltbayi)) <> ''
      $listeBirimW
    ORDER BY bayi
", $listeBirimP);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ana Sayfa - <?= htmlspecialchars($siteTitle) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/source-sans-3@5.0.12/index.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/adminlte.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="/admin/assets/css/custom.css">
    <style>
        .dashboard-card { transition: transform 0.2s, box-shadow 0.2s; border: none; }
        .dashboard-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
        .small-box .inner h3 { font-size: 1.8rem; font-weight: 700; }
        .small-box .inner p { font-size: 0.85rem; }
        .table-dashboard th { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.4px; color: #6c757d; border-top: none; }
        .table-dashboard td { font-size: 0.85rem; vertical-align: middle; }
        .card-title { font-size: 1rem; font-weight: 600; }
        .hosgeldin-bar { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 0.5rem; }
        .hosgeldin-bar h4 { margin: 0; font-weight: 600; }
        .hosgeldin-bar small { opacity: 0.85; }
    </style>
</head>
<body class="layout-fixed sidebar-expand-lg sidebar-open bg-body-tertiary">
    <div class="app-wrapper">
        <?php include __DIR__ . '/../includes/header.php'; ?>
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <main class="app-main">
            <div class="app-content">
                <div class="container-fluid">

                    <!-- Hoş geldin barı -->
                    <div class="hosgeldin-bar text-white p-3 mb-4 d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <h4><i class="bi bi-grid-1x2 me-2"></i>Hoş geldiniz, <?= htmlspecialchars($user['name'] ?? '') ?></h4>
                            <small id="bugun_tarih">&nbsp;</small>
                        </div>
                        <div class="text-end">
                            <small class="d-block">Aktif Ana Bayi</small>
                            <span class="fs-4 fw-bold" id="hb_anabayi">-</span>
                        </div>
                    </div>

                    <!-- InfoBox'lar -->
                    <div class="row mb-4">
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-primary dashboard-card">
                                <div class="inner">
                                    <h3 id="ib_net">-</h3>
                                    <p>Bu Ay Net Gelir</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-graph-up-arrow"></i></div>
                                <a href="/admin/odemeler" class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover">
                                    Ödemeler <i class="bi bi-arrow-right-circle ms-1"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-warning dashboard-card">
                                <div class="inner">
                                    <h3 id="ib_voip">-</h3>
                                    <p>Bu Ay VoIP Harcama</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-telephone-outbound"></i></div>
                                <a href="/admin/voip-harcamalar" class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover">
                                    Harcamalar <i class="bi bi-arrow-right-circle ms-1"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-success dashboard-card">
                                <div class="inner">
                                    <h3 id="ib_altbayi">-</h3>
                                    <p>Aktif Alt Bayi</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-shop"></i></div>
                                <a href="/admin/bayi-yonetimi" class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover">
                                    Bayiler <i class="bi bi-arrow-right-circle ms-1"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="small-box text-bg-info dashboard-card">
                                <div class="inner">
                                    <h3 id="ib_personel">-</h3>
                                    <p>Saha Personeli</p>
                                </div>
                                <div class="small-box-icon"><i class="bi bi-people"></i></div>
                                <a href="/admin/bayi-yonetimi" class="small-box-footer link-light link-underline-opacity-0 link-underline-opacity-50-hover">
                                    Personel <i class="bi bi-arrow-right-circle ms-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Grafikler -->
                    <div class="row mb-4">
                        <div class="col-lg-8 mb-3 mb-lg-0">
                            <div class="card dashboard-card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <h3 class="card-title mb-0"><i class="bi bi-graph-up me-2"></i>Son 30 Gün ONAY Adet</h3>
                                    <select id="filtre_bayi" class="form-select form-select-sm" style="max-width:260px;">
                                        <option value="">Tüm Bayiler</option>
                                        <?php foreach ($bayiListesi as $b): ?>
                                            <option value="<?= htmlspecialchars($b['bayi']) ?>"><?= htmlspecialchars($b['bayi']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="card-body">
                                    <div id="chart_trend" style="min-height:300px;"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-pie-chart me-2"></i>Bu Ay Gider Dağılımı</h3>
                                </div>
                                <div class="card-body">
                                    <div id="chart_gider" style="min-height:300px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Başvuru Dağılım Kartları -->
                    <div class="row mb-4">
                        <div class="col-lg-6 mb-3 mb-lg-0">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-diagram-3 me-2"></i>Birime Göre Dağılım</h3>
                                </div>
                                <div class="card-body" id="dagilimBirim" style="max-height:280px;overflow:auto">
                                    <div class="text-muted small">Yükleniyor...</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-megaphone me-2"></i>Platforma Göre Dağılım</h3>
                                </div>
                                <div class="card-body" id="dagilimPlatform" style="max-height:280px;overflow:auto">
                                    <div class="text-muted small">Yükleniyor...</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tablolar -->
                    <div class="row mb-4">
                        <!-- Son Ödemeler -->
                        <div class="col-lg-7 mb-3 mb-lg-0">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-cash-coin me-2"></i>Son Ödemeler</h3>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-dashboard mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Tür</th>
                                                    <th>Açıklama</th>
                                                    <th class="text-end">Tutar</th>
                                                    <th>Tarih</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbl_son_odemeler">
                                                <tr><td colspan="4" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- En Çok Personelli Alt Bayiler -->
                        <div class="col-lg-5">
                            <div class="card dashboard-card h-100">
                                <div class="card-header">
                                    <h3 class="card-title"><i class="bi bi-shop-window me-2"></i>En Çok Personelli Alt Bayiler</h3>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover table-dashboard mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Alt Bayi</th>
                                                    <th>Ana Bayi</th>
                                                    <th class="text-end">Personel</th>
                                                </tr>
                                            </thead>
                                            <tbody id="tbl_bayi_personel">
                                                <tr><td colspan="3" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                            </tbody>
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

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
    <script src="/admin/assets/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <!-- Select2, custom.js'ten ÖNCE yüklenmeli: custom.js hazır olduğunda
         tüm .form-select elemanlarını otomatik searchable Select2 yapıyor. -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="/admin/assets/js/custom.js"></script>
    <script>
    // ---------- Yardımcılar ----------
    function fmtMoney(val) {
        if (val === null || val === undefined || val === '') return '0 ₺';
        return parseFloat(val).toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' ₺';
    }
    function fmtMoneyShort(val) {
        if (!val && val !== 0) return '0 ₺';
        val = parseFloat(val);
        const neg = val < 0 ? '-' : '';
        val = Math.abs(val);
        if (val >= 1000000) return neg + (val / 1000000).toFixed(1).replace('.', ',') + 'M ₺';
        if (val >= 1000)    return neg + (val / 1000).toFixed(0) + 'K ₺';
        return neg + val.toLocaleString('tr-TR', { maximumFractionDigits: 0 }) + ' ₺';
    }
    function ayEtiket(yyyymm) {
        const aylar = ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'];
        if (!yyyymm) return '';
        const p = yyyymm.split('-');
        return aylar[parseInt(p[1], 10) - 1] + ' ' + p[0].slice(2);
    }

    $(document).ready(function () {
        // Bugünün tarihi
        document.getElementById('bugun_tarih').textContent =
            new Date().toLocaleDateString('tr-TR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

        loadOzet();
        loadOnayKurulum();
        $('#filtre_bayi').on('change', loadOnayKurulum);
        loadGiderDagilim();
        loadSonOdemeler();
        loadBayiPersonel();
        loadDagilim();
    });

    // ---------- Başvuru Dağılım Kartları (Birim + Platform) ----------
    function escapeHtml(s) {
        return (s == null ? '' : String(s))
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function loadDagilim() {
        $.post('', { action: 'dagilim' }, function (r) {
            if (!r.success) return;
            dagilimCiz('#dagilimBirim', r.birim, 'bg-primary');
            dagilimCiz('#dagilimPlatform', r.platform, 'bg-info');
        }, 'json');
    }
    function dagilimCiz(sel, rows, barClass) {
        const box = $(sel).empty();
        if (!rows || !rows.length) { box.html('<div class="text-muted small">Kayıt yok.</div>'); return; }
        const toplam = rows.reduce((s, x) => s + (parseInt(x.adet, 10) || 0), 0) || 1;
        rows.forEach(function (x) {
            const adet = parseInt(x.adet, 10) || 0;
            const pct  = Math.round((adet / toplam) * 100);
            box.append(`
                <div class="mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-truncate" style="max-width:75%" title="${escapeHtml(x.ad || '-')}">${escapeHtml(x.ad || '-')}</span>
                        <span><span class="fw-bold">${adet}</span> <small class="text-muted">%${pct}</small></span>
                    </div>
                    <div class="progress" style="height:6px">
                        <div class="progress-bar ${barClass}" role="progressbar" style="width:${pct}%"></div>
                    </div>
                </div>`);
        });
    }

    // ---------- InfoBox ----------
    function loadOzet() {
        $.post('', { action: 'ozet_stats' }, function (res) {
            if (!res.success) return;
            const d = res.data;
            $('#ib_net').text(fmtMoneyShort(d.bu_ay_net));
            $('#ib_voip').text(fmtMoneyShort(d.voip_harcama));
            $('#ib_altbayi').text(d.aktif_altbayi);
            $('#ib_personel').text(d.saha_personel);
            $('#hb_anabayi').text(d.aktif_anabayi);
        }, 'json');
    }

    // ---------- ONAY Adet Trend (ISP / NEO / UYDU) ----------
    function gunEtiket(yyyymmdd) {
        if (!yyyymmdd) return '';
        const p = yyyymmdd.split('-');
        return p[2] + '.' + p[1];
    }
    let onayChart = null;
    function loadOnayKurulum() {
        const bayi = $('#filtre_bayi').val() || '';
        $.post('', { action: 'onay_kurulum_trend', bayi: bayi }, function (res) {
            if (!res.success) return;
            const kategoriler = res.data.map(r => gunEtiket(r.gun));
            const isp  = res.data.map(r => parseInt(r.isp)  || 0);
            const neo  = res.data.map(r => parseInt(r.neo)  || 0);
            const uydu = res.data.map(r => parseInt(r.uydu) || 0);

            if (onayChart) { onayChart.destroy(); }
            onayChart = new ApexCharts(document.querySelector('#chart_trend'), {
                chart: { type: 'line', height: 300, toolbar: { show: false }, fontFamily: 'inherit', zoom: { enabled: false } },
                series: [
                    { name: 'ISP', data: isp },
                    { name: 'NEO', data: neo },
                    { name: 'UYDU', data: uydu }
                ],
                colors: ['#0d6efd', '#fd7e14', '#198754'],
                stroke: { width: 3, curve: 'smooth' },
                markers: { size: 3, hover: { size: 5 } },
                dataLabels: { enabled: false },
                xaxis: { categories: kategoriler, tickAmount: 10, labels: { rotate: -45, style: { fontSize: '11px' } } },
                yaxis: { labels: { formatter: v => Math.round(v) } },
                legend: { position: 'top' },
                tooltip: { y: { formatter: v => v + ' adet' } },
                noData: { text: 'Veri yok' }
            });
            onayChart.render();
        }, 'json');
    }

    // ---------- Gider Dağılımı (Donut) ----------
    function loadGiderDagilim() {
        $.post('', { action: 'gider_dagilim' }, function (res) {
            if (!res.success) return;
            const etiketler = res.data.map(r => r.ad);
            const tutarlar  = res.data.map(r => Math.round(parseFloat(r.tutar)));

            new ApexCharts(document.querySelector('#chart_gider'), {
                chart: { type: 'donut', height: 300, fontFamily: 'inherit' },
                series: tutarlar.length ? tutarlar : [],
                labels: etiketler,
                colors: ['#0d6efd', '#fd7e14', '#6f42c1', '#20c997', '#dc3545', '#ffc107', '#0dcaf0', '#6c757d'],
                legend: { position: 'bottom' },
                dataLabels: { enabled: true, formatter: (val) => val.toFixed(0) + '%' },
                tooltip: { y: { formatter: v => fmtMoney(v) } },
                noData: { text: 'Bu ay gider kaydı yok' }
            }).render();
        }, 'json');
    }

    // ---------- Son Ödemeler ----------
    function loadSonOdemeler() {
        $.post('', { action: 'son_odemeler' }, function (res) {
            if (!res.success || !res.data.length) {
                $('#tbl_son_odemeler').html('<tr><td colspan="4" class="text-center text-muted py-3">Kayıt bulunamadı</td></tr>');
                return;
            }
            let html = '';
            res.data.forEach(function (r) {
                const gelir = r.OdemeTurleri_GelirMi == 1;
                const tutarCls = gelir ? 'text-success' : 'text-danger';
                const isaret   = gelir ? '+' : '-';
                const turBadge = '<span class="badge ' + (gelir ? 'bg-success' : 'bg-danger') + '-subtle text-' + (gelir ? 'success' : 'danger') + '">' + (r.OdemeTurleri_Ad || '-') + '</span>';
                html += '<tr>' +
                    '<td>' + turBadge + '</td>' +
                    '<td><small>' + (r.Odemeler_Aciklama ? $('<div>').text(r.Odemeler_Aciklama).html() : '-') + '</small></td>' +
                    '<td class="text-end fw-semibold ' + tutarCls + '">' + isaret + fmtMoney(r.Odemeler_Tutar) + '</td>' +
                    '<td><small class="text-muted">' + (r.tarih || '-') + '</small></td>' +
                    '</tr>';
            });
            $('#tbl_son_odemeler').html(html);
        }, 'json');
    }

    // ---------- En Çok Personelli Alt Bayiler ----------
    function loadBayiPersonel() {
        $.post('', { action: 'bayi_personel' }, function (res) {
            if (!res.success || !res.data.length) {
                $('#tbl_bayi_personel').html('<tr><td colspan="3" class="text-center text-muted py-3">Kayıt bulunamadı</td></tr>');
                return;
            }
            let html = '';
            res.data.forEach(function (r) {
                html += '<tr>' +
                    '<td><a href="/admin/bayi-yonetimi" class="text-decoration-none fw-semibold">' + (r.DigiturkAltBayiler_Ad ? $('<div>').text(r.DigiturkAltBayiler_Ad).html() : '-') + '</a></td>' +
                    '<td><small class="text-muted">' + (r.ana_bayi ? $('<div>').text(r.ana_bayi).html() : '-') + '</small></td>' +
                    '<td class="text-end"><span class="badge bg-primary rounded-pill">' + (r.personel_adet ?? 0) + '</span></td>' +
                    '</tr>';
            });
            $('#tbl_bayi_personel').html(html);
        }, 'json');
    }
    </script>
</body>
</html>
