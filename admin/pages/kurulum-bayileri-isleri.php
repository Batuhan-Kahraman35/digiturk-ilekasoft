<?php
/**
 * Admin Panel - Kurulum Bayileri İşleri
 * IRIS raporundaki (DigiturkIrisRapor) memoları, Digiturk'ün kuruluma yönlendirdiği
 * bayiye (IrisRapor_MemoYonlenenBayiKodu/Adi) göre listeler. Rapor, iris_rapor cron
 * görevi ile beslenir; MemoId üzerinden upsert edildiği için her satır bir memodur.
 *
 * Tablo büyük (48 bin+ satır, sürekli büyür) olduğu için:
 *  - Liste server-side, iki aşamalı: ucuz CTE yalnız sayfanın ID'lerini alır.
 *  - Filtre seçenekleri ve InfoBox/kırılım ayrı AJAX action'larıyla, tablo çizildikten
 *    SONRA çekilir; sayfa ilk açılışta ağır sorgu çalıştırmaz.
 *  - Varsayılan tarih aralığı son 30 gündür.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
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

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Kurulum Bayileri İşleri';
$menuAdi   = $pageinfo['menu_adi'] ?? 'Başvurular';

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);

if (!$permissions['has_access']) {
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok!');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// =====================================================================
// YETKİ KISITI
//  - Birim: raporla eşleşen personel (IrisRapor_DigiturkAltBayiPersonel) veya
//    onun alt bayisi kullanıcının birim ağacına yetkili olmalı.
//  - Kendi kaydı: memonun talebi, kullanıcının oluşturduğu/atandığı bir başvuruya bağlı olmalı.
// =====================================================================
$birimKisitli   = (!$permissions['is_admin'] && !empty($permissions['can_view_birim']));
$kendiKisitli   = (!$permissions['is_admin'] && !empty($permissions['can_view_own_records']));
$kullaniciId    = (int)$user['kullanici_id'];
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
    // kullanici_birim_id boşsa $izinliBirimler boş → hiçbir kayıt görünmez (güvenli varsayılan)
}

/**
 * Temel kısıt: yönlendirilen bayi dolu + yetki. null → kullanıcı hiçbir kaydı göremez.
 * @return array|null [where[], params[]]
 */
function kbiTemelKisit(bool $birimKisitli, array $izinliBirimler, bool $kendiKisitli, int $kullaniciId): ?array {
    $where  = ["r.IrisRapor_MemoYonlenenBayiKodu IS NOT NULL"];
    $params = [];
    if ($birimKisitli) {
        if (empty($izinliBirimler)) return null;
        $ph = implode(',', array_fill(0, count($izinliBirimler), '?'));
        $where[] = "EXISTS (
            SELECT 1 FROM KullaniciBirimYetkileri kby
            WHERE kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
              AND kby.Durum = 1
              AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
              AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
              AND (kby.KullaniciBirimYetkileri_Personel_id = r.IrisRapor_DigiturkAltBayiPersonel
                OR kby.KullaniciBirimYetkileri_AltBayi_id  = (SELECT pp.DigiturkAltBayiPersonel_AltBayiId
                                                              FROM DigiturkAltBayiPersonel pp
                                                              WHERE pp.DigiturkAltBayiPersonel_Id = r.IrisRapor_DigiturkAltBayiPersonel)))";
        $params = array_merge($params, $izinliBirimler);
    }
    if ($kendiKisitli) {
        $where[] = "EXISTS (SELECT 1 FROM Basvurular b
                            WHERE b.TalepKayitNo = r.IrisRapor_TalepId
                              AND (b.OlusturanKullanici = ? OR b.Basvurular_AtananKullanici_ID = ?))";
        $params[] = $kullaniciId;
        $params[] = $kullaniciId;
    }
    return [$where, $params];
}

/**
 * Filtre paneli koşulları. list ve stats aynı koşulu kullanır.
 * Dropdown değerleri tablodaki gerçek değerlerden gelir; eşitlikle aranır (indeks dostu).
 * @return array [where[], params[]]
 */
function kbiFiltreWhere(array $post): array {
    $where  = [];
    $params = [];

    $search = trim((string)($post['f_search'] ?? ''));
    if ($search !== '') {
        $conds  = ["r.IrisRapor_MemoYonlenenBayiAdi LIKE ?", "r.IrisRapor_TalebiGirenPersonel LIKE ?"];
        $params = ["%$search%", "%$search%"];
        // Numara aramaları eşitlikle (LIKE '%..%' büyük tabloda indeks kullanamaz)
        if (preg_match('/^\d{5,19}$/', $search)) {
            $conds[] = "r.IrisRapor_TalepId = ?";              $params[] = $search;
            $conds[] = "r.IrisRapor_MemoId = ?";               $params[] = $search;
            $conds[] = "r.IrisRapor_DtMusteriNo = ?";          $params[] = $search;
            $conds[] = "r.IrisRapor_MemoYonlenenBayiKodu = ?"; $params[] = $search;
            $conds[] = "r.IrisRapor_AktiveEdilenUyeNo = ?";    $params[] = $search;
        }
        $where[] = "(" . implode(" OR ", $conds) . ")";
    }

    $esit = [
        'f_bayi'       => 'r.IrisRapor_MemoYonlenenBayiKodu',
        'f_bolge'      => 'r.IrisRapor_MemoYonlenenBayiBolge',
        'f_talep_turu' => 'r.IrisRapor_TalepTuru',
        'f_satis'      => 'r.IrisRapor_SatisDurumu',
        'f_surec'      => 'r.IrisRapor_BasvuruSurecDurumu',
        'f_memo_durum' => 'r.IrisRapor_MemoSonDurum',
        'f_giren_bayi' => 'r.IrisRapor_TalebiGirenBayiKodu',
    ];
    foreach ($esit as $alan => $kolon) {
        $v = trim((string)($post[$alan] ?? ''));
        if ($v !== '') { $where[] = "$kolon = ?"; $params[] = $v; }
    }

    $personel = (string)($post['f_personel'] ?? '');
    if ($personel !== '') { $where[] = "r.IrisRapor_DigiturkAltBayiPersonel = ?"; $params[] = (int)$personel; }

    // Memo: acik → kapanış tarihi yok, kapali → kapanmış
    $memo = (string)($post['f_memo_acik'] ?? '');
    if ($memo === 'acik')   $where[] = "r.IrisRapor_MemoKapanisTarihi IS NULL";
    if ($memo === 'kapali') $where[] = "r.IrisRapor_MemoKapanisTarihi IS NOT NULL";

    // Tarih filtreleri (date input: YYYY-MM-DD)
    $tarihler = [
        'f_tarih_bas'   => ['r.IrisRapor_TalepGirisTarihi',  '>=', ' 00:00:00'],
        'f_tarih_bit'   => ['r.IrisRapor_TalepGirisTarihi',  '<=', ' 23:59:59'],
        'f_kapanis_bas' => ['r.IrisRapor_MemoKapanisTarihi', '>=', ' 00:00:00'],
        'f_kapanis_bit' => ['r.IrisRapor_MemoKapanisTarihi', '<=', ' 23:59:59'],
    ];
    foreach ($tarihler as $alan => [$kolon, $op, $saat]) {
        $v = trim((string)($post[$alan] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $where[] = "$kolon $op ?"; $params[] = $v . $saat; }
    }

    return [$where, $params];
}

// ── Excel indir (JSON header'dan ÖNCE — çıktı .xls). Aktif filtrelerle, tarihe göre yeniden eskiye ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'excel_indir') {
    $temel = kbiTemelKisit($birimKisitli, $izinliBirimler, $kendiKisitli, $kullaniciId);
    $rows  = [];
    if ($temel !== null) {
        [$whereBase, $paramsBase] = $temel;
        [$wF, $pF] = kbiFiltreWhere($_POST);
        $rows = $db->fetchAll("
            SELECT
                r.IrisRapor_MemoYonlenenBayiKodu  AS BayiKodu,
                r.IrisRapor_MemoYonlenenBayiAdi   AS BayiAdi,
                r.IrisRapor_MemoYonlenenBayiBolge AS Bolge,
                r.IrisRapor_DtMusteriNo           AS MusteriNo,
                r.IrisRapor_TalepId               AS TalepId,
                r.IrisRapor_MemoId                AS MemoId,
                CONVERT(VARCHAR(16), r.IrisRapor_TalepGirisTarihi, 120)  AS TalepGiris,
                r.IrisRapor_TalebiGirenPersonel   AS GirenPersonel,
                r.IrisRapor_TalebiGirenBayiKodu   AS GirenBayiKodu,
                r.IrisRapor_SatisDurumu           AS SatisDurumu,
                r.IrisRapor_BasvuruSurecDurumu    AS SurecDurumu,
                r.IrisRapor_MemoSonDurum          AS MemoSonDurum,
                r.IrisRapor_MemoSonCevap          AS MemoSonCevap,
                CONVERT(VARCHAR(16), r.IrisRapor_RandevuTarihi, 120)     AS Randevu,
                CONVERT(VARCHAR(16), r.IrisRapor_MemoKapanisTarihi, 120) AS Kapanis
            FROM DigiturkIrisRapor r
            WHERE " . implode(' AND ', array_merge($whereBase, $wF)) . "
            ORDER BY r.IrisRapor_TalepGirisTarihi DESC, r.IrisRapor_Id DESC", array_merge($paramsBase, $pF));
    }

    $basliklar = [
        'BayiKodu' => 'Kurulum Bayi Kodu', 'BayiAdi' => 'Kurulum Bayisi', 'Bolge' => 'Bölge',
        'MusteriNo' => 'Müşteri No', 'TalepId' => 'Talep No', 'MemoId' => 'Memo No', 'TalepGiris' => 'Talep Giriş',
        'GirenPersonel' => 'Giren Personel', 'GirenBayiKodu' => 'Giren Bayi Kodu', 'SatisDurumu' => 'Satış Durumu',
        'SurecDurumu' => 'Süreç (IRIS)', 'MemoSonDurum' => 'Memo Son Durum', 'MemoSonCevap' => 'Memo Son Cevap',
        'Randevu' => 'Randevu', 'Kapanis' => 'Memo Kapanış',
    ];
    // Numara kolonları metin olarak yazılır (başta sıfır kaybı / bilimsel gösterim olmasın)
    $metin = ['BayiKodu', 'MusteriNo', 'TalepId', 'MemoId', 'GirenBayiKodu'];

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kurulum_bayileri_isleri_' . date('Y-m-d_H-i-s') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM (Türkçe karakter)
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head>';
    echo '<meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">';
    echo '</head><body><table border="1"><thead><tr style="background-color:#0d6efd;color:white;font-weight:bold;">';
    foreach ($basliklar as $b) echo '<th>' . htmlspecialchars($b) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach (array_keys($basliklar) as $k) {
            $stil = in_array($k, $metin, true) ? ' style="mso-number-format:\'\@\'"' : '';
            echo "<td{$stil}>" . htmlspecialchars((string)($row[$k] ?? '')) . '</td>';
        }
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
        $temel = kbiTemelKisit($birimKisitli, $izinliBirimler, $kendiKisitli, $kullaniciId);

        switch ($action) {

            case 'list': {
                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 200) $length = 25;

                if ($temel === null) {
                    echo json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
                    break;
                }
                [$whereBase, $paramsBase] = $temel;
                [$wF, $pF] = kbiFiltreWhere($_POST);
                $whereFilt  = array_merge($whereBase, $wF);
                $paramsFilt = array_merge($paramsBase, $pF);

                $wBaseClause = implode(' AND ', $whereBase);
                $wFiltClause = implode(' AND ', $whereFilt);

                // Sayımlar JOIN'siz, yalnız DigiturkIrisRapor r
                $recordsTotal    = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM DigiturkIrisRapor r WHERE $wBaseClause", $paramsBase)['c'] ?? 0);
                $recordsFiltered = $wF
                    ? (int)($db->fetchOne("SELECT COUNT(*) AS c FROM DigiturkIrisRapor r WHERE $wFiltClause", $paramsFilt)['c'] ?? 0)
                    : $recordsTotal;

                // Sıralama: kolon index → whitelist (tüm kolonlar ana tabloda; CTE tek tablo kalır)
                $orderMap = [
                    0 => 'r.IrisRapor_MemoYonlenenBayiAdi',
                    1 => 'r.IrisRapor_MemoYonlenenBayiBolge',
                    2 => 'r.IrisRapor_DtMusteriNo',
                    3 => 'r.IrisRapor_TalepGirisTarihi',
                    4 => 'r.IrisRapor_TalebiGirenPersonel',
                    5 => 'r.IrisRapor_SatisDurumu',
                    6 => 'r.IrisRapor_BasvuruSurecDurumu',
                    7 => 'r.IrisRapor_MemoSonDurum',
                    8 => 'r.IrisRapor_RandevuTarihi',
                    9 => 'r.IrisRapor_MemoKapanisTarihi',
                ];
                $orderIdx = (int)($_POST['order'][0]['column'] ?? 3);
                $orderBy  = $orderMap[$orderIdx] ?? 'r.IrisRapor_TalepGirisTarihi';
                $orderDir = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

                $data = [];
                if ($recordsFiltered > 0) {
                    // 1) CTE: yalnız filtre + sıralama + sayfa → ID
                    // 2) Dış SELECT: görüntülenecek kolonlar + başvuru eşleşmesi yalnız bu N satıra
                    $data = $db->fetchAll("
                        WITH Sayfa AS (
                            SELECT r.IrisRapor_Id
                            FROM DigiturkIrisRapor r
                            WHERE $wFiltClause
                            ORDER BY $orderBy $orderDir, r.IrisRapor_Id DESC
                            OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
                        )
                        SELECT
                            r.IrisRapor_Id,
                            r.IrisRapor_MemoYonlenenBayiKodu  AS BayiKodu,
                            r.IrisRapor_MemoYonlenenBayiAdi   AS BayiAdi,
                            r.IrisRapor_MemoYonlenenBayiBolge AS Bolge,
                            r.IrisRapor_TalepId               AS TalepId,
                            r.IrisRapor_MemoId                AS MemoId,
                            r.IrisRapor_DtMusteriNo           AS MusteriNo,
                            CONVERT(VARCHAR(16), r.IrisRapor_TalepGirisTarihi, 120)  AS TalepGiris,
                            r.IrisRapor_TalebiGirenPersonel   AS GirenPersonel,
                            r.IrisRapor_TalebiGirenBayiKodu   AS GirenBayiKodu,
                            r.IrisRapor_SatisDurumu           AS SatisDurumu,
                            r.IrisRapor_BasvuruSurecDurumu    AS SurecDurumu,
                            r.IrisRapor_MemoSonDurum          AS MemoSonDurum,
                            r.IrisRapor_MemoSonCevap          AS MemoSonCevap,
                            CONVERT(VARCHAR(16), r.IrisRapor_RandevuTarihi, 120)     AS Randevu,
                            CONVERT(VARCHAR(16), r.IrisRapor_MemoKapanisTarihi, 120) AS Kapanis,
                            bsv.Basvurular_id                 AS BasvuruId
                        FROM Sayfa s
                        INNER JOIN DigiturkIrisRapor r ON r.IrisRapor_Id = s.IrisRapor_Id
                        OUTER APPLY (
                            SELECT TOP 1 b.Basvurular_id
                            FROM Basvurular b
                            WHERE b.TalepKayitNo = r.IrisRapor_TalepId
                            ORDER BY b.Basvurular_id DESC
                        ) bsv
                        ORDER BY $orderBy $orderDir, r.IrisRapor_Id DESC
                    ", $paramsFilt);
                }

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $data,
                ]);
                break;
            }

            // InfoBox + bayi kırılımı (liste filtresiyle aynı koşul); tablo çizildikten sonra çağrılır
            case 'stats': {
                $bos = ['toplam' => 0, 'bayi' => 0, 'acik' => 0, 'kapali' => 0, 'kirilim' => []];
                if ($temel === null) { echo json_encode(['success' => true, 'data' => $bos]); break; }

                [$whereBase, $paramsBase] = $temel;
                // Kırılım bayi ve açık/kapalı memo filtresinden etkilenmez: bir bayiye tıklanınca
                // liste daralır ama kırılım tüm bayileri göstermeye devam eder (başka bayiye geçilebilir).
                $post = $_POST;
                $seciliBayi = trim((string)($post['f_bayi'] ?? ''));
                unset($post['f_bayi'], $post['f_memo_acik']);
                [$wF, $pF] = kbiFiltreWhere($post);
                $wClause = implode(' AND ', array_merge($whereBase, $wF));
                $params  = array_merge($paramsBase, $pF);

                // Kırılım tek geçişte; özet sayılar kırılımdan toplanır (ikinci tarama yok)
                $kirilim = $db->fetchAll("
                    SELECT r.IrisRapor_MemoYonlenenBayiKodu AS kod,
                           MAX(r.IrisRapor_MemoYonlenenBayiAdi) AS ad,
                           COUNT(*) AS toplam,
                           SUM(CASE WHEN r.IrisRapor_MemoKapanisTarihi IS NULL THEN 1 ELSE 0 END) AS acik
                    FROM DigiturkIrisRapor r
                    WHERE $wClause
                    GROUP BY r.IrisRapor_MemoYonlenenBayiKodu
                    ORDER BY COUNT(*) DESC", $params);

                // InfoBox: bayi seçiliyse yalnız o bayinin satırı, değilse tümü
                $toplam = 0; $acik = 0;
                foreach ($kirilim as $k) {
                    if ($seciliBayi !== '' && (string)$k['kod'] !== $seciliBayi) continue;
                    $toplam += (int)$k['toplam']; $acik += (int)$k['acik'];
                }

                echo json_encode(['success' => true, 'data' => [
                    'toplam'  => $toplam,
                    'bayi'    => $seciliBayi !== '' ? ($toplam > 0 ? 1 : 0) : count($kirilim),
                    'acik'    => $acik,
                    'kapali'  => $toplam - $acik,
                    'kirilim' => $kirilim,
                ]]);
                break;
            }

            // Filtre dropdown seçenekleri: sayfa render'ını yavaşlatmasın diye ayrı ve bir kez çekilir.
            // Yalnız tabloda gerçekten geçen değerler listelenir (yetki kısıtı dahil).
            case 'secenekler': {
                $bos = ['bayi' => [], 'bolge' => [], 'talep_turu' => [], 'satis' => [], 'surec' => [],
                        'memo_durum' => [], 'giren_bayi' => [], 'personel' => []];
                if ($temel === null) { echo json_encode(['success' => true, 'data' => $bos]); break; }
                [$whereBase, $paramsBase] = $temel;
                $w = implode(' AND ', $whereBase);

                $distinct = function (string $kolon) use ($db, $w, $paramsBase) {
                    return array_column($db->fetchAll("
                        SELECT $kolon AS v FROM DigiturkIrisRapor r
                        WHERE $w AND $kolon IS NOT NULL AND $kolon <> ''
                        GROUP BY $kolon ORDER BY $kolon", $paramsBase), 'v');
                };
                $kodAd = function (string $kodKolon, string $adKolon) use ($db, $w, $paramsBase) {
                    return $db->fetchAll("
                        SELECT $kodKolon AS id, CONCAT(MAX($adKolon), ' (', $kodKolon, ')') AS ad
                        FROM DigiturkIrisRapor r
                        WHERE $w AND $kodKolon IS NOT NULL
                        GROUP BY $kodKolon
                        ORDER BY MAX($adKolon)", $paramsBase);
                };

                echo json_encode(['success' => true, 'data' => [
                    'bayi'       => $kodAd('r.IrisRapor_MemoYonlenenBayiKodu', 'r.IrisRapor_MemoYonlenenBayiAdi'),
                    'bolge'      => $distinct('r.IrisRapor_MemoYonlenenBayiBolge'),
                    'talep_turu' => $distinct('r.IrisRapor_TalepTuru'),
                    'satis'      => $distinct('r.IrisRapor_SatisDurumu'),
                    'surec'      => $distinct('r.IrisRapor_BasvuruSurecDurumu'),
                    'memo_durum' => $distinct('r.IrisRapor_MemoSonDurum'),
                    'giren_bayi' => $kodAd('r.IrisRapor_TalebiGirenBayiKodu', 'r.IrisRapor_TalebiGirenBayiAdi'),
                    'personel'   => $db->fetchAll("
                        SELECT p.DigiturkAltBayiPersonel_Id AS id, p.DigiturkAltBayiPersonel_AdSoyad AS ad
                        FROM DigiturkAltBayiPersonel p
                        WHERE EXISTS (SELECT 1 FROM DigiturkIrisRapor r
                                      WHERE $w AND r.IrisRapor_DigiturkAltBayiPersonel = p.DigiturkAltBayiPersonel_Id)
                        ORDER BY p.DigiturkAltBayiPersonel_AdSoyad", $paramsBase),
                ]]);
                break;
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
        }
    } catch (Throwable $e) {
        error_log('kurulum-bayileri-isleri: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'İşlem sırasında hata oluştu.']);
    }
    exit;
}

// Varsayılan tarih aralığı: son 30 gün (ilk açılışta tüm geçmiş taranmasın; filtreden genişletilebilir)
$varsayilanBas = date('Y-m-d', strtotime('-30 days'));
$varsayilanBit = date('Y-m-d');
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
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; }
        #kirilimTablo tbody tr { cursor: pointer; }
        #kirilimTablo tbody tr.aktif { background: rgba(13,110,253,.12); }
        .kirilim-kutu { max-height: 560px; overflow-y: auto; }
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

                <!-- Info Boxes (aktif filtreye göre) -->
                <div class="row mb-3">
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-briefcase"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam İş (Memo)</span>
                                <span class="info-box-number" id="stat-toplam">…</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-info">
                            <span class="info-box-icon"><i class="bi bi-shop"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Kurulum Bayisi</span>
                                <span class="info-box-number" id="stat-bayi">…</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-warning" title="Memo kapanış tarihi olmayanlar">
                            <span class="info-box-icon"><i class="bi bi-hourglass-split"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Açık Memo</span>
                                <span class="info-box-number" id="stat-acik">…</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check2-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Kapanan Memo</span>
                                <span class="info-box-number" id="stat-kapali">…</span>
                            </div>
                        </div>
                    </div>
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
                                    <input type="text" class="form-control" id="filter_search" placeholder="Talep / memo / müşteri / üye no, bayi, personel">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Kurulum Bayisi</label>
                                    <select class="form-select" id="filter_bayi" data-secenek="bayi"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Bölge</label>
                                    <select class="form-select" id="filter_bolge" data-secenek="bolge"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Memo</label>
                                    <select class="form-select" id="filter_memo_acik">
                                        <option value="">Tümü</option>
                                        <option value="acik">Açık (kapanmamış)</option>
                                        <option value="kapali">Kapanmış</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Talep Türü</label>
                                    <select class="form-select" id="filter_talep_turu" data-secenek="talep_turu"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Satış Durumu</label>
                                    <select class="form-select" id="filter_satis" data-secenek="satis"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Başvuru Süreç Durumu (IRIS)</label>
                                    <select class="form-select" id="filter_surec" data-secenek="surec"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Memo Son Durum</label>
                                    <select class="form-select" id="filter_memo_durum" data-secenek="memo_durum"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Talebi Giren Bayi</label>
                                    <select class="form-select" id="filter_giren_bayi" data-secenek="giren_bayi"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Eşleşen Personel</label>
                                    <select class="form-select" id="filter_personel" data-secenek="personel"><option value="">Tümü</option></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Talep Giriş (Başlangıç)</label>
                                    <input type="date" class="form-control" id="filter_tarih_bas" value="<?= $varsayilanBas ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Talep Giriş (Bitiş)</label>
                                    <input type="date" class="form-control" id="filter_tarih_bit" value="<?= $varsayilanBit ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Memo Kapanış (Başlangıç)</label>
                                    <input type="date" class="form-control" id="filter_kapanis_bas">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Memo Kapanış (Bitiş)</label>
                                    <input type="date" class="form-control" id="filter_kapanis_bit">
                                </div>
                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrele</button>
                                    <button type="button" class="btn btn-secondary" id="clearFilters"><i class="bi bi-x-circle"></i> Temizle</button>
                                    <small class="text-muted ms-2">Varsayılan: son 30 günde girilen talepler. "Temizle" tüm geçmişi gösterir.</small>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="row">
                    <!-- Bayi kırılımı: satıra tıklayınca liste o bayiye filtrelenir -->
                    <div class="col-xl-3">
                        <div class="card mb-3">
                            <div class="card-header">
                                <h3 class="card-title"><i class="bi bi-bar-chart"></i> Bayi Bazında İşler</h3>
                            </div>
                            <div class="card-body p-0 kirilim-kutu">
                                <table class="table table-sm table-hover mb-0" id="kirilimTablo">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Kurulum Bayisi</th>
                                            <th class="text-end" title="Toplam memo">Top.</th>
                                            <th class="text-end text-warning" title="Açık memo">Açık</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr><td colspan="3" class="text-center text-muted py-3">Yükleniyor...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Liste -->
                    <div class="col-xl-9">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Kurulum Bayileri İşleri</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-success btn-sm" id="btnExcelIndir">
                                        <i class="bi bi-file-earmark-excel"></i> Excel İndir
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <table id="kayitTable" class="table table-bordered table-striped table-hover w-100">
                                    <thead>
                                        <tr>
                                            <th>Kurulum Bayisi</th>
                                            <th>Bölge</th>
                                            <th>Müşteri No</th>
                                            <th>Talep Giriş</th>
                                            <th>Giren Personel</th>
                                            <th>Satış Durumu</th>
                                            <th>Süreç (IRIS)</th>
                                            <th>Memo Son Durum</th>
                                            <th>Randevu</th>
                                            <th>Memo Kapanış</th>
                                            <th style="width:50px"></th>
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
    let dataTable;
    let secenekYuklendi = false;

    // list ve stats aynı filtreyi kullanır
    function filtreler() {
        return {
            f_search:      $('#filter_search').val() || '',
            f_bayi:        $('#filter_bayi').val() || '',
            f_bolge:       $('#filter_bolge').val() || '',
            f_memo_acik:   $('#filter_memo_acik').val() || '',
            f_talep_turu:  $('#filter_talep_turu').val() || '',
            f_satis:       $('#filter_satis').val() || '',
            f_surec:       $('#filter_surec').val() || '',
            f_memo_durum:  $('#filter_memo_durum').val() || '',
            f_giren_bayi:  $('#filter_giren_bayi').val() || '',
            f_personel:    $('#filter_personel').val() || '',
            f_tarih_bas:   $('#filter_tarih_bas').val() || '',
            f_tarih_bit:   $('#filter_tarih_bit').val() || '',
            f_kapanis_bas: $('#filter_kapanis_bas').val() || '',
            f_kapanis_bit: $('#filter_kapanis_bit').val() || ''
        };
    }

    $(document).ready(function () {
        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            processing: true,
            serverSide: true,
            autoWidth: false,
            scrollX: true,
            dom: 'lrtip', // global arama kapalı — filtre panelindeki "Ara" kullanılır
            order: [[3, 'desc']],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            ajax: {
                url: '', type: 'POST',
                data: function (d) { return Object.assign(d, { action: 'list' }, filtreler()); },
                error: function () { showToast('Liste yüklenirken hata oluştu', 'error'); }
            },
            columns: [
                { data: null,          render: (d, t, r) => `<strong>${escapeHtml(r.BayiAdi || '-')}</strong><br><small class="text-muted mono">${escapeHtml(r.BayiKodu || '')}</small>` },
                { data: 'Bolge',       render: d => escapeHtml(d || '-') },
                { data: 'MusteriNo',   render: d => d ? `<span class="mono">${escapeHtml(d)}</span>` : '<span class="text-muted">-</span>' },
                { data: 'TalepGiris',  render: d => escapeHtml(d || '-') },
                { data: null,          render: (d, t, r) => escapeHtml(r.GirenPersonel || '-') + (r.GirenBayiKodu ? `<br><small class="text-muted">${escapeHtml(r.GirenBayiKodu)}</small>` : '') },
                { data: 'SatisDurumu', render: d => escapeHtml(d || '-') },
                { data: 'SurecDurumu', render: d => escapeHtml(d || '-') },
                { data: null,          render: (d, t, r) => kisaMetin(r.MemoSonDurum, r.MemoSonCevap) },
                { data: 'Randevu',     render: d => escapeHtml(d || '-') },
                { data: 'Kapanis',     render: d => d ? escapeHtml(d) : '<span class="badge text-bg-warning">Açık</span>' },
                { data: null, orderable: false, className: 'text-center',
                  render: (d, t, r) => r.BasvuruId
                      ? `<a href="/Admin/basvuru-form?id=${encodeURIComponent(r.BasvuruId)}" target="_blank" class="btn btn-sm btn-outline-primary" title="Başvuruyu aç"><i class="bi bi-box-arrow-up-right"></i></a>`
                      : '' }
            ]
        });

        // Ağır sorgular tablo ilk kez çizildikten sonra: önce özet/kırılım, sonra filtre seçenekleri
        dataTable.one('draw', function () {
            loadStats();
            loadSecenekler();
        });

        $(document).on('click', '[data-lte-toggle="sidebar"]', function () {
            setTimeout(function () { if (dataTable) dataTable.columns.adjust(); }, 350);
        });

        // Filtre select'lerini custom.js (.form-select) otomatik Select2 yapıyor — elle init edilmez.

        // Excel: aktif filtrelerle gizli form POST (dosya indirmesi AJAX ile yapılamaz)
        $('#btnExcelIndir').on('click', function () {
            const alanlar = Object.assign({ action: 'excel_indir' }, filtreler());
            const form = $('<form>', { method: 'POST', action: '' });
            Object.keys(alanlar).forEach(function (k) {
                form.append($('<input>', { type: 'hidden', name: k, value: alanlar[k] }));
            });
            $('body').append(form);
            form.submit();
            form.remove();
            showToast('Excel dosyası indiriliyor...', 'info');
        });

        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            yenile();
            showToast('Filtre uygulandı', 'info');
        });

        $('#clearFilters').on('click', function () {
            $('#filterForm')[0].reset();
            $('#filter_tarih_bas, #filter_tarih_bit, #filter_kapanis_bas, #filter_kapanis_bit').val('');
            $('#filterForm select').val('').trigger('change');
            yenile();
            showToast('Filtreler temizlendi (tüm tarihler)', 'info');
        });

        // Kırılım satırı → liste o bayinin AÇIK memolarına filtrelenir; aynı satıra tekrar
        // tıklamak bayi ve memo filtresini birlikte kaldırır
        $('#kirilimTablo tbody').on('click', 'tr[data-kod]', function () {
            const kod    = $(this).attr('data-kod');
            const secili = $('#filter_bayi').val() === kod;
            if (!secili && !$('#filter_bayi').find('option').filter(function () { return this.value === kod; }).length) {
                // Seçenekler henüz gelmediyse geçici seçenek eklenir
                $('#filter_bayi').append(new Option($(this).find('td:first').text(), kod));
            }
            $('#filter_bayi').val(secili ? '' : kod).trigger('change');
            $('#filter_memo_acik').val(secili ? '' : 'acik').trigger('change');
            yenile();
        });
    });

    function yenile() {
        dataTable.ajax.reload();
        loadStats();
    }

    function loadStats() {
        $.post('', Object.assign({ action: 'stats' }, filtreler()), function (r) {
            if (!r.success) { showToast(r.message || 'İstatistikler alınamadı', 'error'); return; }
            $('#stat-toplam').text(r.data.toplam);
            $('#stat-bayi').text(r.data.bayi);
            $('#stat-acik').text(r.data.acik);
            $('#stat-kapali').text(r.data.kapali);
            renderKirilim(r.data.kirilim || []);
        }, 'json').fail(function () { showToast('İstatistikler alınamadı', 'error'); });
    }

    function loadSecenekler() {
        if (secenekYuklendi) return;
        $.post('', { action: 'secenekler' }, function (r) {
            if (!r.success) return;
            secenekYuklendi = true;
            $('#filterForm select[data-secenek]').each(function () {
                const $s     = $(this);
                const liste  = r.data[$s.data('secenek')] || [];
                const mevcut = $s.val();
                $s.find('option').filter(function () { return this.value !== ''; }).remove();
                liste.forEach(function (o) {
                    const id = (typeof o === 'object') ? o.id : o;
                    const ad = (typeof o === 'object') ? o.ad : o;
                    $s.append(new Option(ad, id, false, String(id) === String(mevcut)));
                });
                $s.trigger('change.select2');
            });
        }, 'json');
    }

    function renderKirilim(satirlar) {
        const $tb = $('#kirilimTablo tbody').empty();
        if (!satirlar.length) {
            $tb.append('<tr><td colspan="3" class="text-center text-muted py-3">Kayıt yok</td></tr>');
            return;
        }
        const secili = $('#filter_bayi').val();
        satirlar.forEach(function (s) {
            $tb.append(`
                <tr data-kod="${escapeHtml(s.kod)}" class="${s.kod === secili ? 'aktif' : ''}" title="${escapeHtml(s.kod)} — listeyi bu bayiye göre filtrele">
                    <td>${escapeHtml(s.ad || s.kod)}</td>
                    <td class="text-end fw-semibold">${parseInt(s.toplam, 10) || 0}</td>
                    <td class="text-end">${parseInt(s.acik, 10) || 0}</td>
                </tr>`);
        });
    }

    function kisaMetin(durum, cevap) {
        if (!durum && !cevap) return '<span class="text-muted">-</span>';
        const tam  = [durum, cevap].filter(Boolean).join(' — ');
        const kisa = durum || cevap || '';
        return `<span title="${escapeHtml(tam)}">${escapeHtml(kisa.length > 30 ? kisa.substring(0, 30) + '…' : kisa)}</span>`;
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
</script>
</body>
</html>
