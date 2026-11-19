<?php
/**
 * Hakediş hesaplama çekirdeği.
 *
 * hakedis-hesaplama.php sayfasındaki 'hesapla' AJAX'ı ve "Ödemeye Aktar" akışı
 * aynı fonksiyonu kullanır — tutarlar tarayıcıdan alınmaz, her zaman yeniden hesaplanır.
 */

if (!function_exists('hakedisDonemTarihleri')) {
    /**
     * Dönem tarih aralığı: başlangıç = ayın 4'ü, bitiş = sonraki ayın 3'ü
     */
    function hakedisDonemTarihleri(int $yil, int $ay): array {
        $baslangic  = sprintf('%04d-%02d-04', $yil, $ay);
        $sonrakiAy  = $ay + 1;
        $sonrakiYil = $yil;
        if ($sonrakiAy > 12) { $sonrakiAy = 1; $sonrakiYil++; }
        $bitis = sprintf('%04d-%02d-03', $sonrakiYil, $sonrakiAy);
        return [$baslangic, $bitis];
    }
}

if (!function_exists('hakedisAltBayiApply')) {
    /**
     * IrisRapor satırını (r) alt bayiye bağlayan OUTER APPLY (alias: ab → altbayiId, altbayiAd, anaBayiAd).
     *
     * - Rapor alt bayi adını taşıyorsa ad ile eşleşir. Aynı adlı birden çok alt bayi varsa
     *   (ör. "AYER DİGİTAL" hem ORNEK hem ORNEK BAYI altında) talebi giren bayi KODU,
     *   sonra adı ile örtüşen ana bayinin alt bayisi seçilir.
     * - Alt bayi adı boşsa (satışı ana bayinin kendi personeli girmiş, ör. İZMİR BATI/DOĞU)
     *   talebi giren bayi koduna sahip ana bayinin alt bayisine bağlanır.
     */
    function hakedisAltBayiApply(): string {
        return "OUTER APPLY (
                    SELECT TOP 1 a.DigiturkAltBayiler_Id  AS altbayiId,
                                 a.DigiturkAltBayiler_Ad  AS altbayiAd,
                                 an.DigiturkAnaBayiler_Ad AS anaBayiAd
                    FROM DigiturkAltBayiler a
                    LEFT JOIN DigiturkAnaBayiler an ON an.DigiturkAnaBayiler_Id = a.DigiturkAltBayiler_AnaBayiId
                    WHERE a.Durum = 1
                      AND (a.DigiturkAltBayiler_Ad = r.IrisRapor_TalebiGirenPersonelAltbayi
                           OR (r.IrisRapor_TalebiGirenPersonelAltbayi IS NULL
                               AND an.DigiturkAnaBayiler_BayiKodu = r.IrisRapor_TalebiGirenBayiKodu))
                    ORDER BY CASE WHEN an.DigiturkAnaBayiler_BayiKodu = r.IrisRapor_TalebiGirenBayiKodu THEN 0
                                  WHEN an.DigiturkAnaBayiler_Ad       = r.IrisRapor_TalebiGirenBayiAdi  THEN 1
                                  ELSE 2 END,
                             CASE WHEN a.DigiturkAltBayiler_Ad = an.DigiturkAnaBayiler_Ad THEN 0 ELSE 1 END,
                             a.DigiturkAltBayiler_Id DESC
                ) ab";
    }
}

if (!function_exists('hakedisPersonelListesi')) {
    /** Personel filtresi (IrisRapor_TalebiGirenPersonel adları): tekil, boşsuz, en fazla 200 */
    function hakedisPersonelListesi($gelen): array {
        if (!is_array($gelen)) $gelen = ($gelen === '' || $gelen === null) ? [] : [$gelen];
        $liste = array_values(array_unique(array_filter(array_map(fn($p) => trim((string)$p), $gelen), fn($p) => $p !== '')));
        return array_slice($liste, 0, 200);
    }
}

if (!function_exists('hakedisBayiSkalaSetId')) {
    /**
     * Alt bayinin dönemde geçerli skala seti (hakedisKademeApply ile aynı kural):
     * dönemden önce/aynı dönemde başlayan en son atama, yoksa varsayılan set.
     */
    function hakedisBayiSkalaSetId($db, ?int $altBayiId, int $yil, int $ay): ?int {
        if ($altBayiId) {
            $r = $db->fetchOne("
                SELECT TOP 1 x.HakedisAltBayiSkalaSetleri_Set_id AS id
                FROM dbo.HakedisAltBayiSkalaSetleri x
                JOIN dbo.HakedisSkalaSetleri s
                  ON s.HakedisSkalaSetleri_id = x.HakedisAltBayiSkalaSetleri_Set_id AND s.Durum = 1
                WHERE x.HakedisAltBayiSkalaSetleri_AltBayi_id = ? AND x.Durum = 1
                  AND x.HakedisAltBayiSkalaSetleri_BaslangicYil * 100 + x.HakedisAltBayiSkalaSetleri_BaslangicAy <= ?
                ORDER BY x.HakedisAltBayiSkalaSetleri_BaslangicYil DESC, x.HakedisAltBayiSkalaSetleri_BaslangicAy DESC
            ", [$altBayiId, $yil * 100 + $ay]);
            if ($r) return (int)$r['id'];
        }
        $v = $db->fetchOne("SELECT TOP 1 HakedisSkalaSetleri_id AS id FROM dbo.HakedisSkalaSetleri WHERE HakedisSkalaSetleri_Varsayilan = 1 AND Durum = 1");
        return $v ? (int)$v['id'] : null;
    }
}

if (!function_exists('hakedisSetKademeleri')) {
    /** Setin aktif kademeleri, Sira'ya göre: [{id, ad, alt, ust}] */
    function hakedisSetKademeleri($db, int $setId): array {
        return array_map(fn($k) => [
            'id'  => (int)$k['id'],
            'ad'  => (string)$k['ad'],
            'alt' => (int)$k['alt'],
            'ust' => $k['ust'] !== null ? (int)$k['ust'] : null,
        ], $db->fetchAll("
            SELECT HakedisSkalaKademeleri_id AS id, HakedisSkalaKademeleri_Ad AS ad,
                   HakedisSkalaKademeleri_AltSinir AS alt, HakedisSkalaKademeleri_UstSinir AS ust
            FROM dbo.HakedisSkalaKademeleri
            WHERE HakedisSkalaKademeleri_Set_id = ? AND Durum = 1
            ORDER BY HakedisSkalaKademeleri_Sira, HakedisSkalaKademeleri_AltSinir DESC
        ", [$setId]));
    }
}

if (!function_exists('hakedisKademeApply')) {
    /**
     * Adet toplamından skala kademesini bulan OUTER APPLY (alias: kd → SkalaKodu, SkalaAd).
     *
     * Set seçimi: alt bayinin dönemden önce/aynı dönemde başlayan en son ataması
     * (HakedisAltBayiSkalaSetleri); ataması yoksa varsayılan set (HakedisSkalaSetleri_Varsayilan = 1).
     * Kademe: AltSinir dahil, UstSinir hariç (NULL = sınırsız). Boşluğa düşen adette kd NULL döner.
     *
     * @param string $toplamKolon  adet toplamı kolonu (ör. g.GrupToplam)
     * @param string $altBayiKolon alt bayi id kolonu (NULL olabilir → varsayılan set)
     */
    function hakedisKademeApply(string $toplamKolon, string $altBayiKolon, int $yil, int $ay): string {
        $donem = $yil * 100 + $ay;   // int, literal gömülür
        return "OUTER APPLY (
                    SELECT TOP 1 k.HakedisSkalaKademeleri_id AS SkalaKodu,
                                 k.HakedisSkalaKademeleri_Ad AS SkalaAd
                    FROM dbo.HakedisSkalaKademeleri k
                    WHERE k.Durum = 1
                      AND k.HakedisSkalaKademeleri_Set_id = ISNULL(
                            (SELECT TOP 1 x.HakedisAltBayiSkalaSetleri_Set_id
                               FROM dbo.HakedisAltBayiSkalaSetleri x
                               JOIN dbo.HakedisSkalaSetleri xs
                                 ON xs.HakedisSkalaSetleri_id = x.HakedisAltBayiSkalaSetleri_Set_id AND xs.Durum = 1
                              WHERE x.HakedisAltBayiSkalaSetleri_AltBayi_id = {$altBayiKolon}
                                AND x.Durum = 1
                                AND x.HakedisAltBayiSkalaSetleri_BaslangicYil * 100 + x.HakedisAltBayiSkalaSetleri_BaslangicAy <= {$donem}
                              ORDER BY x.HakedisAltBayiSkalaSetleri_BaslangicYil DESC, x.HakedisAltBayiSkalaSetleri_BaslangicAy DESC),
                            (SELECT TOP 1 vs.HakedisSkalaSetleri_id
                               FROM dbo.HakedisSkalaSetleri vs
                              WHERE vs.HakedisSkalaSetleri_Varsayilan = 1 AND vs.Durum = 1))
                      AND {$toplamKolon} >= k.HakedisSkalaKademeleri_AltSinir
                      AND (k.HakedisSkalaKademeleri_UstSinir IS NULL OR {$toplamKolon} < k.HakedisSkalaKademeleri_UstSinir)
                    ORDER BY k.HakedisSkalaKademeleri_AltSinir DESC
                ) kd";
    }
}

/**
 * Alt bayi × kampanya hakediş hesabı.
 *
 * @param array $in  yil, ay[], altbayi, birim, outlet, satis, bas, bit
 * @return array ['success'=>bool, 'message'=>?string, 'data'=>array, 'donemler'=>array]
 */
function hakedisHesapla(array $in, $db, bool $birimKisitli, array $izinliBirimIdleri): array {
    $yil       = (int)($in['yil'] ?? 0);
    $aylar     = $in['ay'] ?? [];
    $altbayiId = (int)($in['altbayi'] ?? 0);   // 0  = tümü
    $birimId   = (int)($in['birim'] ?? 0);     // 0  = tümü (seçilen birim + alt birimleri)
    $outlet    = trim((string)($in['outlet'] ?? ''));
    $satis     = trim((string)($in['satis'] ?? ''));
    // Personel filtresi: adetleri daraltır, skala kademesi bayinin TÜM satışından hesaplanır
    $personeller = hakedisPersonelListesi($in['personel'] ?? []);

    // Manuel tarih aralığı (dolu ve geçerli ise tanım/formülü ezer)
    $mBas   = trim((string)($in['bas'] ?? ''));
    $mBit   = trim((string)($in['bit'] ?? ''));
    $manuel = preg_match('/^\d{4}-\d{2}-\d{2}$/', $mBas)
           && preg_match('/^\d{4}-\d{2}-\d{2}$/', $mBit)
           && $mBas <= $mBit;

    if (!is_array($aylar)) $aylar = ($aylar === '' ? [] : [$aylar]);
    $aylar = array_values(array_unique(array_filter(array_map('intval', $aylar), fn($a) => $a >= 1 && $a <= 12)));
    sort($aylar);

    if (!$yil || empty($aylar)) {
        return ['success' => false, 'message' => 'Yıl ve en az bir ay seçilmelidir'];
    }

    // ── Ortak filtreler (aya bağlı değil, bir kez hesaplanır) ──
    // Alt bayi filtresi, satırın çözülen alt bayi ID'si üzerinden (hakedisAltBayiApply)
    $bayiFilter = $altbayiId ? " AND ab.altbayiId = ?" : '';

    // Birim filtresi + görünürlük kısıtı → efektif birim adları (ka.Birim IN ...)
    $birimFilter = '';
    $birimAdlari = [];

    $secilenIdler = null; // null = birim seçilmedi
    if ($birimId) {
        $agac = $db->fetchAll("
            ;WITH birimAgaci AS (
                SELECT KullaniciBirim_id FROM dbo.KullaniciBirim WHERE KullaniciBirim_id = ?
                UNION ALL
                SELECT k.KullaniciBirim_id FROM dbo.KullaniciBirim k
                JOIN birimAgaci b ON k.KullaniciBirim_UstBirim_id = b.KullaniciBirim_id
            )
            SELECT KullaniciBirim_id FROM birimAgaci
        ", [$birimId]);
        $secilenIdler = array_map(fn($r) => (int)$r['KullaniciBirim_id'], $agac);
    }

    // Efektif id kümesi: null = sınırsız (tüm birimler)
    $efektifIdler = $secilenIdler;
    if ($birimKisitli) {
        if (empty($izinliBirimIdleri)) {
            $efektifIdler = [];
        } elseif ($secilenIdler !== null) {
            $efektifIdler = array_values(array_intersect($secilenIdler, $izinliBirimIdleri));
        } else {
            $efektifIdler = $izinliBirimIdleri;
        }
    }

    if ($efektifIdler !== null) {
        if (empty($efektifIdler)) {
            $birimFilter = ' WHERE 1 = 0';
        } else {
            $ph = implode(',', array_fill(0, count($efektifIdler), '?'));
            $adRows = $db->fetchAll("SELECT KullaniciBirim_Adi FROM dbo.KullaniciBirim WHERE KullaniciBirim_id IN ($ph)", $efektifIdler);
            $birimAdlari = array_values(array_filter(array_map(fn($r) => $r['KullaniciBirim_Adi'], $adRows)));
            $birimFilter = $birimAdlari
                ? " WHERE ka.Birim IN (" . implode(',', array_fill(0, count($birimAdlari), '?')) . ")"
                : ' WHERE 1 = 0';
        }
    }

    // ── Her seçili ay için ayrı hesapla, sonuçları birleştir ──
    $grouped  = [];
    $donemler = [];

    foreach ($aylar as $ay) {

        // Dönem tarihleri: manuel > hakediş tanımı > formül
        $donemTanim = $db->fetchOne("
            SELECT MIN(CAST(DigiturkHakedisTanimlari_BaslangicTarihi AS DATE)) AS bas,
                   MAX(CAST(DigiturkHakedisTanimlari_BitisTarihi     AS DATE)) AS bit
            FROM dbo.DigiturkHakedisTanimlari
            WHERE DigiturkHakedisTanimlari_DonemYil = ? AND DigiturkHakedisTanimlari_DonemAy = ? AND Durum = 1
        ", [$yil, $ay]);

        if ($manuel) {
            $bas = $mBas; $bit = $mBit; $donemKaynak = 'manuel';
        } elseif ($donemTanim && !empty($donemTanim['bas']) && !empty($donemTanim['bit'])) {
            $bas = $donemTanim['bas']; $bit = $donemTanim['bit']; $donemKaynak = 'tanim';
        } else {
            [$bas, $bit] = hakedisDonemTarihleri($yil, $ay); $donemKaynak = 'formul';
        }

        // Parametre sırası: bas, bit, [altbayiId], [outlet], [satis], [birimAdlari...]
        $qp = [$bas, $bit];
        if ($altbayiId) $qp[] = $altbayiId;
        $durumFilter = '';
        if ($outlet !== '') { $durumFilter .= " AND r.IrisRapor_GuncelOutletDurum = ?"; $qp[] = $outlet; }
        if ($satis  !== '') { $durumFilter .= " AND r.IrisRapor_SatisDurumu = ?";       $qp[] = $satis; }

        // Personel: bayiToplam + kampanyaAdet'e uygulanır (grupToplam'a değil → kademe değişmez).
        // CTE sırası bayiToplam → kampanyaAdet olduğu için parametreler iki kez, bu sırayla eklenir.
        $personelBt = $personelKa = '';
        if ($personeller) {
            $pPh = implode(',', array_fill(0, count($personeller), '?'));
            $personelBt = " WHERE Personel IN ($pPh)";
            $personelKa = " WHERE u.Personel IN ($pPh)";
            foreach ($personeller as $pp) $qp[] = $pp;
            foreach ($personeller as $pp) $qp[] = $pp;
        }

        // uygun (dönemdeki uygun kayıtlar) → bayiToplam (skala kademesi) + kampanyaAdet (kampanya bazında adet)
        // Kampanya tutarı correlated subquery ile. DonemAy/DonemYil (int) literal gömülür (injection yok).
        $sql = "
            ;WITH uygun AS (
                SELECT
                    ISNULL(r.IrisRapor_TalebiGirenPersonelAltbayi, ab.altbayiAd) AS Altbayi,
                    ab.anaBayiAd                            AS AnaBayi,
                    ISNULL(bm.birim, N'(Birim Yok)')       AS Birim,
                    bm.birimId                              AS BirimId,
                    ab.altbayiId                            AS AltBayiId,
                    ISNULL(ab.altbayiId, 0)                 AS AltBayiKey,
                    r.IrisRapor_TalepTuru                   AS TalepTuru,
                    r.IrisRapor_MemoKodu                    AS MemoKodu,
                    r.IrisRapor_Kampanya                    AS Kampanya,
                    r.IrisRapor_DtMusteriNo                 AS MusteriNo,
                    r.IrisRapor_TalebiGirenPersonel         AS Personel,
                    sg.grupId                               AS GrupId,
                    -- Skala grubu anahtarı: gruba üyeyse grup, değilse bayinin kendisi
                    CASE WHEN sg.grupId IS NOT NULL
                         THEN 'G' + CAST(sg.grupId AS VARCHAR(20))
                         ELSE 'B' + CAST(ISNULL(ab.altbayiId, 0) AS VARCHAR(20))
                              + N'|' + ISNULL(ISNULL(r.IrisRapor_TalebiGirenPersonelAltbayi, ab.altbayiAd), N'')
                    END                                     AS GrupKey
                FROM dbo.DigiturkIrisRapor r
                " . hakedisAltBayiApply() . "
                OUTER APPLY (
                    -- Birim, yukarıda çözülen alt bayi ID'sinden gider (isim eşleşmesi değil)
                    SELECT TOP 1 kb.KullaniciBirim_Adi AS birim, kb.KullaniciBirim_id AS birimId
                    FROM KullaniciBirimYetkileri kby
                    JOIN KullaniciBirim kb ON kb.KullaniciBirim_id = kby.KullaniciBirimYetkileri_Birim_id
                    WHERE kby.KullaniciBirimYetkileri_AltBayi_id = ab.altbayiId
                      AND kby.Durum = 1
                      AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
                      AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
                    ORDER BY kby.KullaniciBirimYetkileri_id DESC
                ) bm
                OUTER APPLY (
                    -- Alt bayinin aktif skala grubu (hakedis-tanimlama > Alt Bayi Eşleştirme).
                    -- Filtreli unique index gereği bir alt bayi tek aktif grupta olabilir.
                    SELECT TOP 1 gu.DigiturkAltBayiSkalaGrupUyeleri_Grup_id AS grupId
                    FROM dbo.DigiturkAltBayiSkalaGrupUyeleri gu
                    JOIN dbo.DigiturkAltBayiSkalaGruplari gg
                      ON gg.DigiturkAltBayiSkalaGruplari_id = gu.DigiturkAltBayiSkalaGrupUyeleri_Grup_id
                     AND gg.Durum = 1
                    WHERE gu.DigiturkAltBayiSkalaGrupUyeleri_AltBayi_id = ab.altbayiId
                      AND gu.Durum = 1
                    ORDER BY gu.DigiturkAltBayiSkalaGrupUyeleri_id DESC
                ) sg
                WHERE r.IrisRapor_MemoKapanisTarihi >= ?
                  AND r.IrisRapor_MemoKapanisTarihi < DATEADD(DAY, 1, ?)
                  $bayiFilter
                  $durumFilter
            ),
            bayiToplam AS (
                -- Bayinin KENDİ adedi (ekranda görünen); aynı adlı iki bayi ayrı sayılır
                -- MusteriToplam: aynı müşteri no bir kez sayılır (net-kazanc Müşteri Adet)
                SELECT Altbayi, AltBayiKey, GrupKey, MAX(GrupId) AS GrupId, COUNT(*) AS Toplam,
                       COUNT(DISTINCT MusteriNo) AS MusteriToplam
                FROM uygun
                $personelBt
                GROUP BY Altbayi, AltBayiKey, GrupKey
            ),
            grupToplam AS (
                -- Skala kademesi GRUP havuzundan: eşleştirilmiş alt bayilerin adetleri
                -- toplanır. Gruba üye olmayan bayi kendi anahtarıyla tek başına gelir.
                -- Kademe, bayinin skala setinden (hakedisKademeApply); grup üyeleri aynı sette
                -- tutulduğu için set grubun herhangi bir üyesinden çözülür.
                SELECT g.GrupKey, g.GrupToplam, kd.SkalaKodu, kd.SkalaAd
                FROM (
                    SELECT GrupKey, COUNT(*) AS GrupToplam, MIN(AltBayiId) AS SetBayiId
                    FROM uygun
                    GROUP BY GrupKey
                ) g
                " . hakedisKademeApply('g.GrupToplam', 'g.SetBayiId', $yil, $ay) . "
            ),
            kampanyaAdet AS (
                SELECT
                    u.Altbayi, u.AltBayiKey, u.AnaBayi, u.Birim,
                    MAX(u.AltBayiId)                          AS AltBayiId,
                    MAX(u.BirimId)                            AS BirimId,
                    t.HakedisKampanyaTanimlari_KampanyaAdi   AS KampanyaAdi,
                    MIN(t.HakedisKampanyaTanimlari_id)       AS TanimId,
                    COUNT(*)                                 AS Adet
                FROM uygun u
                JOIN dbo.HakedisKampanyaTanimlari t
                  ON u.TalepTuru = t.HakedisKampanyaTanimlari_TalepTuru
                 AND u.MemoKodu  = t.HakedisKampanyaTanimlari_MemoKodu
                 AND t.Durum = 1
                 AND (
                     -- Kampanya'lı tanım: IrisRapor kampanyası birebir eşleşir
                     (t.HakedisKampanyaTanimlari_Kampanya IS NOT NULL
                         AND u.Kampanya = t.HakedisKampanyaTanimlari_Kampanya)
                     OR
                     -- Kampanya'sız (genel) tanım: aynı Talep+Memo grubunda tanımlı spesifik
                     -- kampanyalara giden kayıtlar düşülür (mükerrer sayımı önler).
                     (t.HakedisKampanyaTanimlari_Kampanya IS NULL
                         AND NOT EXISTS (
                             SELECT 1 FROM dbo.HakedisKampanyaTanimlari t3
                             WHERE t3.HakedisKampanyaTanimlari_TalepTuru = t.HakedisKampanyaTanimlari_TalepTuru
                               AND t3.HakedisKampanyaTanimlari_MemoKodu  = t.HakedisKampanyaTanimlari_MemoKodu
                               AND t3.HakedisKampanyaTanimlari_Kampanya IS NOT NULL
                               AND t3.HakedisKampanyaTanimlari_Kampanya = u.Kampanya
                               AND t3.Durum = 1
                         ))
                 )
                $personelKa
                GROUP BY u.Altbayi, u.AltBayiKey, u.AnaBayi, u.Birim, t.HakedisKampanyaTanimlari_KampanyaAdi
            )
            SELECT
                ka.Birim, ka.BirimId, ka.Altbayi, ka.AnaBayi, ka.AltBayiId, ka.AltBayiKey,
                ka.KampanyaAdi, ka.TanimId, ka.Adet,
                bt.Toplam, bt.MusteriToplam, gt.SkalaKodu, gt.SkalaAd, gt.GrupToplam, bt.GrupId,
                (SELECT gg2.DigiturkAltBayiSkalaGruplari_Ad
                   FROM dbo.DigiturkAltBayiSkalaGruplari gg2
                  WHERE gg2.DigiturkAltBayiSkalaGruplari_id = bt.GrupId) AS GrupAd,
                -- Tutar kampanya ADINA göre bulunur (temsilci id karmaşası olmasın):
                -- matris tutarı aynı ada sahip herhangi bir tanım id'sine yazmış olabilir.
                (SELECT MAX(h.DigiturkHakedisTanimlari_Tutar)
                   FROM dbo.DigiturkHakedisTanimlari h
                   JOIN dbo.HakedisKampanyaTanimlari kt2
                     ON kt2.HakedisKampanyaTanimlari_id = h.DigiturkHakedisTanimlari_KampanyaTanim_id
                   WHERE h.DigiturkHakedisTanimlari_AltBayi_id     = ka.AltBayiId
                     AND h.DigiturkHakedisTanimlari_DonemAy         = $ay
                     AND h.DigiturkHakedisTanimlari_DonemYil        = $yil
                     AND kt2.HakedisKampanyaTanimlari_KampanyaAdi   = ka.KampanyaAdi
                     AND kt2.Durum = 1
                     AND h.DigiturkHakedisTanimlari_AdetSkalasiKodu = gt.SkalaKodu
                     AND h.Durum = 1) AS BirimTutar
            FROM kampanyaAdet ka
            JOIN bayiToplam bt ON bt.Altbayi = ka.Altbayi AND bt.AltBayiKey = ka.AltBayiKey
            JOIN grupToplam gt ON gt.GrupKey = bt.GrupKey
            $birimFilter
            ORDER BY ka.Birim, ka.AnaBayi, ka.Altbayi, ka.KampanyaAdi
        ";
        foreach ($birimAdlari as $ba) { $qp[] = $ba; }
        $rows = $db->fetchAll($sql, $qp);

        foreach ($rows as $p) {
            // Aynı adlı bayiler ayrı satır kalsın diye anahtara alt bayi ID'si de girer
            $key = $ay . '|' . (string)$p['Birim'] . '|' . (int)$p['AltBayiKey'] . '|' . (string)$p['Altbayi'];
            if (!isset($grouped[$key])) {
                $bayiAdi  = trim((string)$p['Altbayi']);
                $anaBayi  = trim((string)($p['AnaBayi'] ?? ''));
                $grouped[$key] = [
                    'donemYil'    => $yil,
                    'donemAy'     => $ay,
                    'birim'       => (string)$p['Birim'],
                    'birimId'     => $p['BirimId'] !== null ? (int)$p['BirimId'] : null,
                    'bayi'        => $bayiAdi,
                    'anaBayi'     => $anaBayi,
                    // Görünen etiket: "ANA BAYİ — ALT BAYİ" (ana bayi çözülemezse yalnız alt bayi)
                    'bayiEtiket'  => $anaBayi !== '' ? $anaBayi . ' — ' . $bayiAdi : $bayiAdi,
                    'id'          => $p['AltBayiId'] !== null ? (int)$p['AltBayiId'] : null,
                    'toplam'      => (int)$p['Toplam'],
                    'musteriToplam' => (int)$p['MusteriToplam'],
                    'kademe'      => $p['SkalaKodu'] !== null ? (int)$p['SkalaKodu'] : null,
                    'kademeAd'    => $p['SkalaAd'] !== null ? (string)$p['SkalaAd'] : null,
                    // Skala grubu: kademe grup toplam adedinden hesaplandıysa doludur
                    'grupAd'      => ($p['GrupAd'] !== null && $p['GrupAd'] !== '') ? (string)$p['GrupAd'] : null,
                    'grupToplam'  => (int)$p['GrupToplam'],
                    'eslesti'     => $p['AltBayiId'] !== null,
                    'kampanyalar' => [],   // ad => {adet, birimTutar, hakedis, eksik}
                    'hakedis'     => 0.0,
                    'eksik'       => false,
                ];
            }
            $adet       = (int)$p['Adet'];
            $birimTutar = $p['BirimTutar'] !== null ? (float)$p['BirimTutar'] : null;
            $hk         = ($birimTutar !== null) ? $adet * $birimTutar : 0.0;

            $grouped[$key]['kampanyalar'][(string)$p['KampanyaAdi']] = [
                'adet'       => $adet,
                'birimTutar' => $birimTutar,
                'hakedis'    => $hk,
                'eksik'      => ($birimTutar === null),
            ];
            $grouped[$key]['hakedis'] += $hk;
            if ($birimTutar === null) $grouped[$key]['eksik'] = true;
        }

        $donemler[] = [
            'yil'    => $yil,
            'ay'     => $ay,
            'bas'    => date('d.m.Y', strtotime($bas)),
            'bit'    => date('d.m.Y', strtotime($bit)),
            'kaynak' => $donemKaynak,
        ];
    } // foreach aylar

    $result = array_values($grouped);

    // Dönem (ay) → Birim (A→Z) → Ana Bayi → Alt Bayi sırala
    usort($result, fn($a, $b) =>
        [$a['donemAy'], $a['birim'], $a['anaBayi'], $a['bayi']]
        <=> [$b['donemAy'], $b['birim'], $b['anaBayi'], $b['bayi']]);

    return ['success' => true, 'data' => $result, 'donemler' => $donemler];
}

/**
 * Hesaplama satırlarını ödeme kayıtlarına gruplar: (dönem × hedef birim) → 1 ödeme.
 *
 * Filtrede birim seçiliyse (hedefBirimId) o dönemin tüm alt bayileri tek kayıtta toplanır;
 * seçilmemişse satırların kendi birimlerine göre gruplanır.
 *
 * @return array [ ['donemYil','donemAy','birimId','birimAd','referans','tutar','aciklama','eksik','satirSayisi'], ... ]
 */
function hakedisOdemeGruplari(array $satirlar, ?int $hedefBirimId, ?string $hedefBirimAd, array $AYLAR): array {
    $gruplar = [];

    foreach ($satirlar as $r) {
        $bId = $hedefBirimId ?: ($r['birimId'] ?? null);
        $bAd = $hedefBirimId ? (string)$hedefBirimAd : (string)$r['birim'];

        $key = $r['donemYil'] . '-' . $r['donemAy'] . '-' . ($bId ?? 'yok');

        if (!isset($gruplar[$key])) {
            $gruplar[$key] = [
                'donemYil'    => (int)$r['donemYil'],
                'donemAy'     => (int)$r['donemAy'],
                'birimId'     => $bId !== null ? (int)$bId : null,
                'birimAd'     => $bAd,
                'referans'    => ($AYLAR[(int)$r['donemAy']] ?? '') . "'" . substr((string)$r['donemYil'], -2) . ' Hakediş',
                'tutar'       => 0.0,
                'satirlar'    => [],
                'eksik'       => false,
                'satirSayisi' => 0,
            ];
        }

        foreach ($r['kampanyalar'] as $kad => $k) {
            if ((int)$k['adet'] <= 0) continue;
            $birimTutar = $k['birimTutar'];
            $gruplar[$key]['satirlar'][] = sprintf(
                '%s | %s: %s x %s = %s',
                $r['bayiEtiket'] ?? $r['bayi'],
                $kad,
                number_format((int)$k['adet'], 0, ',', '.'),
                $birimTutar !== null ? number_format((float)$birimTutar, 2, ',', '.') : 'TANIMSIZ',
                number_format((float)$k['hakedis'], 2, ',', '.')
            );
        }

        $gruplar[$key]['tutar'] += (float)$r['hakedis'];
        $gruplar[$key]['satirSayisi']++;
        if (!empty($r['eksik'])) $gruplar[$key]['eksik'] = true;
    }

    foreach ($gruplar as &$g) {
        $g['satirlar'][] = 'TOPLAM = ' . number_format($g['tutar'], 2, ',', '.');
        $g['aciklama']   = implode("\n", $g['satirlar']);
        unset($g['satirlar']);
    }
    unset($g);

    // Dönem sırasına göre
    $out = array_values($gruplar);
    usort($out, fn($a, $b) => [$a['donemYil'], $a['donemAy'], $a['birimAd']] <=> [$b['donemYil'], $b['donemAy'], $b['birimAd']]);
    return $out;
}

/**
 * Hakediş filtrelerine uyan HAM DigiturkIrisRapor kayıtları.
 *
 * hakedis-hesaplama.php'deki "Excel indir" butonu bu fonksiyonu kullanır:
 * hesaplanmış özet yerine, hesaba giren satırların kendisi dışa aktarılır.
 * Filtre mantığı hakedisHesapla() ile birebir aynıdır (dönem tarihi, alt bayi,
 * birim görünürlüğü, outlet ve satış durumu).
 *
 * @param array $in yil, ay[], altbayi, birim, outlet, satis, bas, bit
 * @return array ['success'=>bool,'message'=>?string,'data'=>array,'donemler'=>array,'kesildi'=>bool]
 */
function hakedisIrisKayitlari(array $in, $db, bool $birimKisitli, array $izinliBirimIdleri): array {
    $LIMIT = 100000;   // tarayıcı tarafı xlsx üretimi için azami satır

    $yil       = (int)($in['yil'] ?? 0);
    $aylar     = $in['ay'] ?? [];
    $altbayiId = (int)($in['altbayi'] ?? 0);
    $birimId   = (int)($in['birim'] ?? 0);
    $outlet    = trim((string)($in['outlet'] ?? ''));
    $satis     = trim((string)($in['satis'] ?? ''));
    $personeller = hakedisPersonelListesi($in['personel'] ?? []);

    $mBas   = trim((string)($in['bas'] ?? ''));
    $mBit   = trim((string)($in['bit'] ?? ''));
    $manuel = preg_match('/^\d{4}-\d{2}-\d{2}$/', $mBas)
           && preg_match('/^\d{4}-\d{2}-\d{2}$/', $mBit)
           && $mBas <= $mBit;

    if (!is_array($aylar)) $aylar = ($aylar === '' ? [] : [$aylar]);
    $aylar = array_values(array_unique(array_filter(array_map('intval', $aylar), fn($a) => $a >= 1 && $a <= 12)));
    sort($aylar);

    if (!$yil || empty($aylar)) {
        return ['success' => false, 'message' => 'Yıl ve en az bir ay seçilmelidir'];
    }

    // ── Alt bayi filtresi: çözülen alt bayi ID'si (hakedisHesapla ile aynı) ──
    $bayiFilter = $altbayiId ? " AND ab.altbayiId = ?" : '';

    // ── Birim filtresi + görünürlük kısıtı → efektif birim adları ──
    $birimAdlari  = [];
    $secilenIdler = null;
    if ($birimId) {
        $agac = $db->fetchAll("
            ;WITH birimAgaci AS (
                SELECT KullaniciBirim_id FROM dbo.KullaniciBirim WHERE KullaniciBirim_id = ?
                UNION ALL
                SELECT k.KullaniciBirim_id FROM dbo.KullaniciBirim k
                JOIN birimAgaci b ON k.KullaniciBirim_UstBirim_id = b.KullaniciBirim_id
            )
            SELECT KullaniciBirim_id FROM birimAgaci
        ", [$birimId]);
        $secilenIdler = array_map(fn($r) => (int)$r['KullaniciBirim_id'], $agac);
    }

    $efektifIdler = $secilenIdler;
    if ($birimKisitli) {
        if (empty($izinliBirimIdleri))   $efektifIdler = [];
        elseif ($secilenIdler !== null)  $efektifIdler = array_values(array_intersect($secilenIdler, $izinliBirimIdleri));
        else                             $efektifIdler = $izinliBirimIdleri;
    }

    $birimFilter = '';
    if ($efektifIdler !== null) {
        if (empty($efektifIdler)) {
            $birimFilter = ' AND 1 = 0';
        } else {
            $ph = implode(',', array_fill(0, count($efektifIdler), '?'));
            $adRows = $db->fetchAll("SELECT KullaniciBirim_Adi FROM dbo.KullaniciBirim WHERE KullaniciBirim_id IN ($ph)", $efektifIdler);
            $birimAdlari = array_values(array_filter(array_map(fn($r) => $r['KullaniciBirim_Adi'], $adRows)));
            $birimFilter = $birimAdlari
                ? " AND ISNULL(bm.birim, N'(Birim Yok)') IN (" . implode(',', array_fill(0, count($birimAdlari), '?')) . ")"
                : ' AND 1 = 0';
        }
    }

    $data     = [];
    $donemler = [];
    $kesildi  = false;

    foreach ($aylar as $ay) {
        if ($kesildi) break;

        // Dönem tarihleri: manuel > hakediş tanımı > formül (hakedisHesapla ile aynı)
        $donemTanim = $db->fetchOne("
            SELECT MIN(CAST(DigiturkHakedisTanimlari_BaslangicTarihi AS DATE)) AS bas,
                   MAX(CAST(DigiturkHakedisTanimlari_BitisTarihi     AS DATE)) AS bit
            FROM dbo.DigiturkHakedisTanimlari
            WHERE DigiturkHakedisTanimlari_DonemYil = ? AND DigiturkHakedisTanimlari_DonemAy = ? AND Durum = 1
        ", [$yil, $ay]);

        if ($manuel) {
            $bas = $mBas; $bit = $mBit; $donemKaynak = 'manuel';
        } elseif ($donemTanim && !empty($donemTanim['bas']) && !empty($donemTanim['bit'])) {
            $bas = $donemTanim['bas']; $bit = $donemTanim['bit']; $donemKaynak = 'tanim';
        } else {
            [$bas, $bit] = hakedisDonemTarihleri($yil, $ay); $donemKaynak = 'formul';
        }

        $qp = [$bas, $bit];
        if ($altbayiId) $qp[] = $altbayiId;
        $durumFilter = '';
        if ($outlet !== '') { $durumFilter .= " AND r.IrisRapor_GuncelOutletDurum = ?"; $qp[] = $outlet; }
        if ($satis  !== '') { $durumFilter .= " AND r.IrisRapor_SatisDurumu = ?";       $qp[] = $satis; }
        if ($personeller) {
            $durumFilter .= " AND r.IrisRapor_TalebiGirenPersonel IN (" . implode(',', array_fill(0, count($personeller), '?')) . ")";
            foreach ($personeller as $pp) $qp[] = $pp;
        }
        foreach ($birimAdlari as $ba) { $qp[] = $ba; }

        $kalan = $LIMIT - count($data) + 1;   // +1: kesilme tespiti için bir fazla çekilir
        if ($kalan <= 0) { $kesildi = true; break; }

        $sql = "
            SELECT TOP ($kalan)
                ISNULL(bm.birim, N'(Birim Yok)')        AS Birim,
                ISNULL(ab.anaBayiAd, N'')               AS AnaBayi,
                r.IrisRapor_MemoId,
                r.IrisRapor_MemoIdTip,
                r.IrisRapor_MemoKodu,
                r.IrisRapor_MemoKayitTipi,
                r.IrisRapor_MemoSonDurum,
                r.IrisRapor_MemoSonCevap,
                r.IrisRapor_MemoSonAciklama,
                r.IrisRapor_MemoKapanisTarihi,
                r.IrisRapor_MemoYonlenenBayiAdi,
                r.IrisRapor_MemoYonlenenBayiKodu,
                r.IrisRapor_MemoYonlenenBayiBolge,
                r.IrisRapor_MemoYonlenenBayiYoneticisi,
                r.IrisRapor_MemoYonlenenBayiTeknikYntc,
                r.IrisRapor_TalepId,
                r.IrisRapor_TalepTuru,
                r.IrisRapor_TalepKaynak,
                r.IrisRapor_TalepGirisTarihi,
                r.IrisRapor_TalepTakipNotu,
                r.IrisRapor_TalebiGirenBayiAdi,
                r.IrisRapor_TalebiGirenBayiKodu,
                r.IrisRapor_TalebiGirenPersonel,
                r.IrisRapor_TalebiGirenPersonelKodu,
                r.IrisRapor_TalebiGirenPersonelNo,
                ISNULL(r.IrisRapor_TalebiGirenPersonelAltbayi, ab.altbayiAd) AS IrisRapor_TalebiGirenPersonelAltbayi,
                r.IrisRapor_Kampanya,
                r.IrisRapor_Paket,
                r.IrisRapor_SatisDurumu,
                r.IrisRapor_BasvuruSurecDurumu,
                r.IrisRapor_GuncelOutletDurum,
                r.IrisRapor_TeyitDurum,
                r.IrisRapor_TeyitAramaDurum,
                r.IrisRapor_RandevuTarihi,
                r.IrisRapor_DtMusteriNo,
                r.IrisRapor_AktiveEdilenUyeNo,
                r.IrisRapor_AktiveEdilenOutletNo,
                r.IrisRapor_AktiveEdilenSozlesmeNo,
                r.IrisRapor_AktiveEdilenSozlesmeKmp,
                r.IrisRapor_AktiveEdilenSozlesmeDurum,
                r.IrisRapor_UyduBasvuruUyeNo,
                r.IrisRapor_UyduBasvuruPotansiyelNo
            FROM dbo.DigiturkIrisRapor r
            " . hakedisAltBayiApply() . "
            OUTER APPLY (
                SELECT TOP 1 kb.KullaniciBirim_Adi AS birim, kb.KullaniciBirim_id AS birimId
                FROM KullaniciBirimYetkileri kby
                JOIN KullaniciBirim kb ON kb.KullaniciBirim_id = kby.KullaniciBirimYetkileri_Birim_id
                WHERE kby.KullaniciBirimYetkileri_AltBayi_id = ab.altbayiId
                  AND kby.Durum = 1
                  AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
                  AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
                ORDER BY kby.KullaniciBirimYetkileri_id DESC
            ) bm
            WHERE r.IrisRapor_MemoKapanisTarihi >= ?
              AND r.IrisRapor_MemoKapanisTarihi < DATEADD(DAY, 1, ?)
              $bayiFilter
              $durumFilter
              $birimFilter
            ORDER BY r.IrisRapor_MemoKapanisTarihi, r.IrisRapor_MemoId
        ";

        $rows = $db->fetchAll($sql, $qp);

        foreach ($rows as $r) {
            if (count($data) >= $LIMIT) { $kesildi = true; break; }
            $data[] = array_merge(['Donem' => sprintf('%02d.%04d', $ay, $yil)], $r);
        }

        $donemler[] = [
            'yil'    => $yil,
            'ay'     => $ay,
            'bas'    => date('d.m.Y', strtotime((string)$bas)),
            'bit'    => date('d.m.Y', strtotime((string)$bit)),
            'kaynak' => $donemKaynak,
        ];
    }

    return ['success' => true, 'data' => $data, 'donemler' => $donemler, 'kesildi' => $kesildi, 'limit' => $LIMIT];
}
