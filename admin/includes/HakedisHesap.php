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
    // IrisRapor bayiyi yalnız ADIYLA taşır. Aynı ada sahip birden fazla alt bayi varsa
    // (ör. "AYER DİGİTAL" hem ORNEK hem ORNEK BAYI altında) ad tek başına yetmez;
    // bu durumda ana bayi adı (IrisRapor_TalebiGirenBayiAdi) da eşleşme şartı olur.
    // Adı tekil olan bayilerde ek şart uygulanmaz — mevcut davranış korunur.
    $bayiFilter = '';
    $bayiAd     = null;
    $bayiAnaAd  = null;
    if ($altbayiId) {
        $ab = $db->fetchOne("
            SELECT a.DigiturkAltBayiler_Ad AS ad,
                   an.DigiturkAnaBayiler_Ad AS anaAd,
                   (SELECT COUNT(*) FROM dbo.DigiturkAltBayiler x
                     WHERE x.DigiturkAltBayiler_Ad = a.DigiturkAltBayiler_Ad AND x.Durum = 1) AS adAdedi
            FROM dbo.DigiturkAltBayiler a
            LEFT JOIN dbo.DigiturkAnaBayiler an ON an.DigiturkAnaBayiler_Id = a.DigiturkAltBayiler_AnaBayiId
            WHERE a.DigiturkAltBayiler_Id = ?
        ", [$altbayiId]);
        if ($ab) {
            $bayiFilter = " AND r.IrisRapor_TalebiGirenPersonelAltbayi = ?";
            $bayiAd     = $ab['ad'];
            if ((int)$ab['adAdedi'] > 1 && !empty($ab['anaAd'])) {
                $bayiFilter .= " AND r.IrisRapor_TalebiGirenBayiAdi = ?";
                $bayiAnaAd   = $ab['anaAd'];
            }
        }
    }

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

        // Parametre sırası: bas, bit, [bayi], [anaBayi], [outlet], [satis], [birimAdlari...]
        $qp = [$bas, $bit];
        if ($bayiAd    !== null) $qp[] = $bayiAd;
        if ($bayiAnaAd !== null) $qp[] = $bayiAnaAd;
        $durumFilter = '';
        if ($outlet !== '') { $durumFilter .= " AND r.IrisRapor_GuncelOutletDurum = ?"; $qp[] = $outlet; }
        if ($satis  !== '') { $durumFilter .= " AND r.IrisRapor_SatisDurumu = ?";       $qp[] = $satis; }

        // uygun (dönemdeki uygun kayıtlar) → bayiToplam (skala kademesi) + kampanyaAdet (kampanya bazında adet)
        // Kampanya tutarı correlated subquery ile. DonemAy/DonemYil (int) literal gömülür (injection yok).
        $sql = "
            ;WITH uygun AS (
                SELECT
                    r.IrisRapor_TalebiGirenPersonelAltbayi AS Altbayi,
                    ab.anaBayiAd                            AS AnaBayi,
                    ISNULL(bm.birim, N'(Birim Yok)')       AS Birim,
                    bm.birimId                              AS BirimId,
                    ab.altbayiId                            AS AltBayiId,
                    ISNULL(ab.altbayiId, 0)                 AS AltBayiKey,
                    r.IrisRapor_TalepTuru                   AS TalepTuru,
                    r.IrisRapor_MemoKodu                    AS MemoKodu,
                    r.IrisRapor_Kampanya                    AS Kampanya,
                    sg.grupId                               AS GrupId,
                    -- Skala grubu anahtarı: gruba üyeyse grup, değilse bayinin kendisi
                    CASE WHEN sg.grupId IS NOT NULL
                         THEN 'G' + CAST(sg.grupId AS VARCHAR(20))
                         ELSE 'B' + CAST(ISNULL(ab.altbayiId, 0) AS VARCHAR(20))
                              + N'|' + ISNULL(r.IrisRapor_TalebiGirenPersonelAltbayi, N'')
                    END                                     AS GrupKey
                FROM dbo.DigiturkIrisRapor r
                OUTER APPLY (
                    -- Aynı ada sahip birden fazla alt bayi olabilir; rapordaki ana bayi adıyla
                    -- örtüşen kayıt önceliklidir, örtüşen yoksa eski davranış (en büyük Id) sürer.
                    SELECT TOP 1 a.DigiturkAltBayiler_Id AS altbayiId,
                                 an.DigiturkAnaBayiler_Ad AS anaBayiAd
                    FROM DigiturkAltBayiler a
                    LEFT JOIN DigiturkAnaBayiler an ON an.DigiturkAnaBayiler_Id = a.DigiturkAltBayiler_AnaBayiId
                    WHERE a.DigiturkAltBayiler_Ad = r.IrisRapor_TalebiGirenPersonelAltbayi AND a.Durum = 1
                    ORDER BY CASE WHEN an.DigiturkAnaBayiler_Ad = r.IrisRapor_TalebiGirenBayiAdi THEN 0 ELSE 1 END,
                             a.DigiturkAltBayiler_Id DESC
                ) ab
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
                SELECT Altbayi, AltBayiKey, GrupKey, MAX(GrupId) AS GrupId, COUNT(*) AS Toplam
                FROM uygun
                GROUP BY Altbayi, AltBayiKey, GrupKey
            ),
            grupToplam AS (
                -- Skala kademesi GRUP havuzundan: eşleştirilmiş alt bayilerin adetleri
                -- toplanır. Gruba üye olmayan bayi kendi anahtarıyla tek başına gelir.
                SELECT GrupKey, COUNT(*) AS GrupToplam,
                    CASE WHEN COUNT(*) >= 2000 THEN 1
                         WHEN COUNT(*) >= 1000 THEN 2
                         WHEN COUNT(*) >= 500  THEN 3
                         ELSE 4 END AS SkalaKodu
                FROM uygun
                GROUP BY GrupKey
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
                GROUP BY u.Altbayi, u.AltBayiKey, u.AnaBayi, u.Birim, t.HakedisKampanyaTanimlari_KampanyaAdi
            )
            SELECT
                ka.Birim, ka.BirimId, ka.Altbayi, ka.AnaBayi, ka.AltBayiId, ka.AltBayiKey,
                ka.KampanyaAdi, ka.TanimId, ka.Adet,
                bt.Toplam, gt.SkalaKodu, gt.GrupToplam, bt.GrupId,
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
                    'kademe'      => (int)$p['SkalaKodu'],
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

    // ── Alt bayi filtresi (ad + gerekiyorsa ana bayi adı) ──
    $bayiFilter = '';
    $bayiAd     = null;
    $bayiAnaAd  = null;
    if ($altbayiId) {
        $ab = $db->fetchOne("
            SELECT a.DigiturkAltBayiler_Ad AS ad,
                   an.DigiturkAnaBayiler_Ad AS anaAd,
                   (SELECT COUNT(*) FROM dbo.DigiturkAltBayiler x
                     WHERE x.DigiturkAltBayiler_Ad = a.DigiturkAltBayiler_Ad AND x.Durum = 1) AS adAdedi
            FROM dbo.DigiturkAltBayiler a
            LEFT JOIN dbo.DigiturkAnaBayiler an ON an.DigiturkAnaBayiler_Id = a.DigiturkAltBayiler_AnaBayiId
            WHERE a.DigiturkAltBayiler_Id = ?
        ", [$altbayiId]);
        if ($ab) {
            $bayiFilter = " AND r.IrisRapor_TalebiGirenPersonelAltbayi = ?";
            $bayiAd     = $ab['ad'];
            if ((int)$ab['adAdedi'] > 1 && !empty($ab['anaAd'])) {
                $bayiFilter .= " AND r.IrisRapor_TalebiGirenBayiAdi = ?";
                $bayiAnaAd   = $ab['anaAd'];
            }
        }
    }

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
        if ($bayiAd    !== null) $qp[] = $bayiAd;
        if ($bayiAnaAd !== null) $qp[] = $bayiAnaAd;
        $durumFilter = '';
        if ($outlet !== '') { $durumFilter .= " AND r.IrisRapor_GuncelOutletDurum = ?"; $qp[] = $outlet; }
        if ($satis  !== '') { $durumFilter .= " AND r.IrisRapor_SatisDurumu = ?";       $qp[] = $satis; }
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
                r.IrisRapor_TalebiGirenPersonelAltbayi,
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
            OUTER APPLY (
                SELECT TOP 1 a.DigiturkAltBayiler_Id AS altbayiId,
                             an.DigiturkAnaBayiler_Ad AS anaBayiAd
                FROM DigiturkAltBayiler a
                LEFT JOIN DigiturkAnaBayiler an ON an.DigiturkAnaBayiler_Id = a.DigiturkAltBayiler_AnaBayiId
                WHERE a.DigiturkAltBayiler_Ad = r.IrisRapor_TalebiGirenPersonelAltbayi AND a.Durum = 1
                ORDER BY CASE WHEN an.DigiturkAnaBayiler_Ad = r.IrisRapor_TalebiGirenBayiAdi THEN 0 ELSE 1 END,
                         a.DigiturkAltBayiler_Id DESC
            ) ab
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
