<?php
/**
 * Admin Panel - Banka Hesap Hareketleri
 *
 * Örnek Portal Banka API'sinden senkronlanan hesap hareketlerini listeler.
 * Hareket tablosu sınırsız büyüdüğü için liste server-side DataTables ile çalışır.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/PortalBankaHelper.php';

requireAuth();

// Ödeme dökümanları odemeler.php ile aynı dizini paylaşır (dekont buraya indirilir).
define('ODEME_UPLOAD_DIR', __DIR__ . '/../uploads/odemeler/');
define('ODEME_UPLOAD_URL', '/admin/uploads/odemeler/');

/** Hareketten ödeme oluştururken varsayılan ödeme türü */
const VARSAYILAN_ODEME_TURU = 'Avans';

/**
 * Karşı taraf filtreleri (sabit liste).
 * Anahtar = filtrede kullanılan değer, dizi = etiket + eşleşme deseni (LIKE).
 * İstemciden gelen değer doğrudan sorguya girmez; yalnız bu listeden çözülür.
 *
 * VARSAYILAN DAVRANIŞ: filtre boş bırakılırsa yalnız bu listedeki karşı taraflar gösterilir.
 * Tüm hareketleri görmek için filtreden "Tümü" (KARSI_TARAF_TUMU) seçilmelidir.
 */
const KARSI_TARAF_FILTRELERI = [
    '3m'      => ['etiket' => '3M Digital',     'desen' => '%3M Digital%'],
    'fork'    => ['etiket' => 'FORK YAZILIM',   'desen' => '%FORK YAZILIM%'],
    'gulbahar'=> ['etiket' => 'Gülbahar Tenli', 'desen' => '%Gülbahar Tenli%'],
];

/** Karşı taraf filtresini tamamen kaldıran özel değer */
const KARSI_TARAF_TUMU = 'tumu';

/**
 * Minimum tutar filtresi boş bırakıldığında uygulanan varsayılan alt sınır.
 * Küçük komisyon/masraf kayıtları listeyi doldurmasın diye. 0 yazılarak kaldırılabilir.
 */
const VARSAYILAN_MIN_TUTAR = 100;

$user = Auth::user();
$db   = Database::getInstance();

$currentPagefile = basename($_SERVER['PHP_SELF']);
$pageinfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle = $pageinfo['sayfalar_sayfa_adi'] ?? 'Banka Hesap Hareketleri';
$menuAdi   = $pageinfo['menu_adi'] ?? null;

$permissions = PageAuth::checkPagePermissions($user['kullanici_id'], $user['departman_id'], $currentPagefile);
if (!$permissions['has_access']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Erişim yetkiniz yok.']);
        exit;
    }
    PageAuth::accessDenied($permissions['error'] ?? 'Bu sayfaya erişim yetkiniz yok.');
}

$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$siteTitle    = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

/**
 * Kullanıcıdan gelen tutar metnini sayıya çevirir.
 * TR (1.552,87) ve EN (1,552.87) biçimlerini ayraç konumundan çözer.
 * Basit str_replace(',', '.') yaklaşımı "100.000,00" değerini 100'e düşürdüğü için kullanılmaz.
 */
function tutarCoz(mixed $deger): ?float
{
    if ($deger === null) return null;
    $s = preg_replace('/[^\d.,-]/', '', trim((string)$deger));
    if ($s === '') return null;

    $sonVirgul = strrpos($s, ',');
    $sonNokta  = strrpos($s, '.');

    if ($sonVirgul !== false && $sonNokta !== false) {
        // İkisi de var → sonda gelen ondalık ayracıdır, diğeri binlik
        $s = $sonVirgul > $sonNokta
            ? str_replace(['.', ','], ['', '.'], $s)
            : str_replace(',', '', $s);
    } elseif ($sonVirgul !== false) {
        // Yalnız virgül: sondan tam 3 hane ise binlik (1,552), değilse ondalık (349,00)
        $s = preg_match('/,\d{3}$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
    } elseif ($sonNokta !== false && preg_match('/\.\d{3}$/', $s)) {
        // Yalnız nokta ve sondan tam 3 hane → binlik ayracı (1.552)
        $s = str_replace('.', '', $s);
    }

    return is_numeric($s) ? (float)$s : null;
}

/**
 * Filtre koşullarını üretir. Hem liste hem istatistik sorgularında kullanılır.
 * @return array [where cümlesi, parametreler]
 */
function bankaHareketFiltre(array $g): array
{
    $where  = ['t.Durum = 1'];
    $params = [];

    $ara = trim($g['search'] ?? '');
    if ($ara !== '') {
        $where[] = "(t.BankaHareketleri_KarsiTaraf LIKE ? OR t.BankaHareketleri_Aciklama LIKE ?
                     OR t.BankaHareketleri_ReferansNo LIKE ? OR t.BankaHareketleri_Iban LIKE ?
                     OR t.BankaHareketleri_VknTckn LIKE ?)";
        for ($i = 0; $i < 5; $i++) $params[] = '%' . $ara . '%';
    }
    if (($g['banka'] ?? '') !== '') {
        $where[]  = "t.BankaHareketleri_BankaId = ?";
        $params[] = (int)$g['banka'];
    }
    if (($g['hesap'] ?? '') !== '') {
        $where[]  = "t.BankaHareketleri_HesapId = ?";
        $params[] = (int)$g['hesap'];
    }
    // Karşı taraf: yalnız sabit listeden çözülür (istemci girdisi sorguya doğrudan girmez).
    // Boş → varsayılan olarak listedeki karşı taraflar; 'tumu' → filtre uygulanmaz.
    $kt = $g['karsi_taraf'] ?? '';
    if ($kt !== KARSI_TARAF_TUMU) {
        if ($kt !== '' && isset(KARSI_TARAF_FILTRELERI[$kt])) {
            $where[]  = "t.BankaHareketleri_KarsiTaraf LIKE ?";
            $params[] = KARSI_TARAF_FILTRELERI[$kt]['desen'];
        } else {
            $parcalar = [];
            foreach (KARSI_TARAF_FILTRELERI as $f) {
                $parcalar[] = "t.BankaHareketleri_KarsiTaraf LIKE ?";
                $params[]   = $f['desen'];
            }
            $where[] = '(' . implode(' OR ', $parcalar) . ')';
        }
    }
    if (in_array($g['tip'] ?? '', ['A', 'B'], true)) {
        $where[]  = "t.BankaHareketleri_Tip = ?";
        $params[] = $g['tip'];
    }
    if (($g['eslesme'] ?? '') !== '') {
        $where[]  = "t.BankaHareketleri_EslesmeDurumu = ?";
        $params[] = (int)$g['eslesme'];
    }
    if (trim($g['tarih_bas'] ?? '') !== '') {
        $where[]  = "t.BankaHareketleri_IslemTarihi >= ?";
        $params[] = trim($g['tarih_bas']) . ' 00:00:00';
    }
    if (trim($g['tarih_bit'] ?? '') !== '') {
        $where[]  = "t.BankaHareketleri_IslemTarihi <= ?";
        $params[] = trim($g['tarih_bit']) . ' 23:59:59';
    }
    // Min tutar boş bırakılırsa varsayılan alt sınır uygulanır (0 yazılarak kaldırılabilir)
    $minTutar = tutarCoz($g['min_tutar'] ?? null);
    if ($minTutar === null) $minTutar = VARSAYILAN_MIN_TUTAR;
    if ($minTutar > 0) {
        $where[]  = "t.BankaHareketleri_Tutar >= ?";
        $params[] = $minTutar;
    }

    $maxTutar = tutarCoz($g['max_tutar'] ?? null);
    if ($maxTutar !== null && $maxTutar > 0) {
        $where[]  = "t.BankaHareketleri_Tutar <= ?";
        $params[] = $maxTutar;
    }

    return [implode(' AND ', $where), $params];
}

// ─── AJAX ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();

    $action = $_POST['action'] ?? '';

    // Dekont ikili içerik döndürür; JSON başlığı bu uçta kullanılmaz.
    if ($action !== 'dekont') {
        header('Content-Type: application/json; charset=utf-8');
    }

    try {
        switch ($action) {

            // ── Liste (server-side DataTables) ──────────────────────────────
            case 'list': {
                $draw   = (int)($_POST['draw'] ?? 1);
                $start  = max(0, (int)($_POST['start'] ?? 0));
                $length = (int)($_POST['length'] ?? 25);
                if ($length <= 0 || $length > 200) $length = 25;

                [$wFilt, $pFilt] = bankaHareketFiltre([
                    'search'      => $_POST['search']['value'] ?? '',
                    'banka'       => $_POST['f_banka']       ?? '',
                    'hesap'       => $_POST['f_hesap']       ?? '',
                    'karsi_taraf' => $_POST['f_karsi_taraf'] ?? '',
                    'tip'         => $_POST['f_tip']         ?? '',
                    'eslesme'   => $_POST['f_eslesme']   ?? '',
                    'tarih_bas' => $_POST['f_tarih_bas'] ?? '',
                    'tarih_bit' => $_POST['f_tarih_bit'] ?? '',
                    'min_tutar' => $_POST['f_min_tutar'] ?? '',
                    'max_tutar' => $_POST['f_max_tutar'] ?? '',
                ]);

                // Sayımlar JOIN'siz, tek ana tablodan
                $recordsTotal    = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM BankaHareketleri t WHERE t.Durum = 1")['c'] ?? 0);
                $recordsFiltered = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM BankaHareketleri t WHERE $wFilt", $pFilt)['c'] ?? 0);

                // Sıralama: kolon index → whitelist
                $orderMap = [
                    0 => 't.BankaHareketleri_IslemTarihi',
                    1 => 't.BankaHareketleri_BankaAdi',
                    2 => 't.BankaHareketleri_HesapNo',
                    3 => 't.BankaHareketleri_KarsiTaraf',
                    4 => 't.BankaHareketleri_Aciklama',
                    5 => 't.BankaHareketleri_Tip',
                    6 => 't.BankaHareketleri_Tutar',
                    7 => 't.BankaHareketleri_KalanBakiye',
                ];
                $orderIdx = (int)($_POST['order'][0]['column'] ?? 0);
                $orderBy  = $orderMap[$orderIdx] ?? 't.BankaHareketleri_IslemTarihi';
                $orderDir = strtolower($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

                $data = [];
                if ($recordsFiltered > 0) {
                    // 1) Ucuz CTE: yalnız filtre + sıralama + sayfalama
                    // 2) Dış SELECT: yalnız o sayfanın satırlarına ek çözümleme (ödeme eşleşmesi)
                    $data = $db->fetchAll("
                        WITH Sayfa AS (
                            SELECT t.BankaHareketleri_id
                            FROM BankaHareketleri t
                            WHERE $wFilt
                            ORDER BY $orderBy $orderDir, t.BankaHareketleri_id DESC
                            OFFSET $start ROWS FETCH NEXT $length ROWS ONLY
                        )
                        SELECT
                            t.BankaHareketleri_id            AS Id,
                            t.BankaHareketleri_PortalId      AS PortalId,
                            t.BankaHareketleri_BankaAdi      AS BankaAdi,
                            t.BankaHareketleri_HesapNo       AS HesapNo,
                            t.BankaHareketleri_Iban          AS Iban,
                            t.BankaHareketleri_FirmaAdi      AS FirmaAdi,
                            t.BankaHareketleri_KarsiTaraf    AS KarsiTaraf,
                            t.BankaHareketleri_VknTckn       AS VknTckn,
                            t.BankaHareketleri_Aciklama      AS Aciklama,
                            t.BankaHareketleri_ReferansNo    AS ReferansNo,
                            t.BankaHareketleri_Tip           AS Tip,
                            t.BankaHareketleri_Tutar         AS Tutar,
                            t.BankaHareketleri_ParaBirimi    AS ParaBirimi,
                            t.BankaHareketleri_KalanBakiye   AS KalanBakiye,
                            t.BankaHareketleri_MasrafMi      AS MasrafMi,
                            t.BankaHareketleri_DekontVar     AS DekontVar,
                            t.BankaHareketleri_EslesmeDurumu AS EslesmeDurumu,
                            t.BankaHareketleri_Odemeler_id   AS OdemeId,
                            CONVERT(VARCHAR(19), t.BankaHareketleri_IslemTarihi, 120) AS IslemTarihi,
                            CONVERT(VARCHAR(19), t.BankaHareketleri_KayitTarihi, 120) AS KayitTarihi
                        FROM Sayfa s
                        INNER JOIN BankaHareketleri t ON t.BankaHareketleri_id = s.BankaHareketleri_id
                        ORDER BY $orderBy $orderDir, t.BankaHareketleri_id DESC
                    ", $pFilt);
                }

                echo json_encode([
                    'draw'            => $draw,
                    'recordsTotal'    => $recordsTotal,
                    'recordsFiltered' => $recordsFiltered,
                    'data'            => $data,
                ]);
                break;
            }

            // ── InfoBox sayaçları (tablo sorgusundan ayrı) ──────────────────
            case 'stats': {
                [$wFilt, $pFilt] = bankaHareketFiltre([
                    'search'      => $_POST['f_search']      ?? '',
                    'banka'       => $_POST['f_banka']       ?? '',
                    'hesap'       => $_POST['f_hesap']       ?? '',
                    'karsi_taraf' => $_POST['f_karsi_taraf'] ?? '',
                    'tip'         => $_POST['f_tip']         ?? '',
                    'eslesme'   => $_POST['f_eslesme']   ?? '',
                    'tarih_bas' => $_POST['f_tarih_bas'] ?? '',
                    'tarih_bit' => $_POST['f_tarih_bit'] ?? '',
                    'min_tutar' => $_POST['f_min_tutar'] ?? '',
                    'max_tutar' => $_POST['f_max_tutar'] ?? '',
                ]);

                $ozet = $db->fetchOne("
                    SELECT
                        COUNT(*) AS adet,
                        ISNULL(SUM(CASE WHEN t.BankaHareketleri_Tip = 'A' THEN t.BankaHareketleri_Tutar ELSE 0 END), 0) AS giris,
                        ISNULL(SUM(CASE WHEN t.BankaHareketleri_Tip = 'B' THEN t.BankaHareketleri_Tutar ELSE 0 END), 0) AS cikis
                    FROM BankaHareketleri t
                    WHERE $wFilt
                ", $pFilt);

                $son = $db->fetchOne("
                    SELECT
                        CONVERT(VARCHAR(19), MAX(t.GuncellemeTarihi), 120)              AS sonSenkron,
                        CONVERT(VARCHAR(19), MAX(t.BankaHareketleri_IslemTarihi), 120)  AS sonHareket
                    FROM BankaHareketleri t
                ");

                echo json_encode(['success' => true, 'data' => [
                    'adet'       => (int)($ozet['adet'] ?? 0),
                    'giris'      => (float)($ozet['giris'] ?? 0),
                    'cikis'      => (float)($ozet['cikis'] ?? 0),
                    'net'        => (float)($ozet['giris'] ?? 0) - (float)($ozet['cikis'] ?? 0),
                    'sonSenkron' => $son['sonSenkron'] ?? null,
                    'sonHareket' => $son['sonHareket'] ?? null,
                ]]);
                break;
            }

            // ── Filtre dropdown kaynakları (tablodaki gerçek değerlerden) ───
            case 'filtre_kaynak': {
                $bankalar = $db->fetchAll("
                    SELECT DISTINCT t.BankaHareketleri_BankaId AS id, t.BankaHareketleri_BankaAdi AS ad
                    FROM BankaHareketleri t
                    WHERE t.BankaHareketleri_BankaId IS NOT NULL AND t.Durum = 1
                    ORDER BY ad
                ");
                $hesaplar = $db->fetchAll("
                    SELECT
                        t.BankaHareketleri_HesapId AS id,
                        MAX(t.BankaHareketleri_BankaAdi) + ' - ' + ISNULL(MAX(t.BankaHareketleri_HesapNo), '?') AS ad
                    FROM BankaHareketleri t
                    WHERE t.BankaHareketleri_HesapId IS NOT NULL AND t.Durum = 1
                    GROUP BY t.BankaHareketleri_HesapId
                    ORDER BY ad
                ");
                echo json_encode(['success' => true, 'data' => ['bankalar' => $bankalar, 'hesaplar' => $hesaplar]]);
                break;
            }

            // ── Tek hareket detayı ──────────────────────────────────────────
            case 'detay': {
                $id    = (int)($_POST['id'] ?? 0);
                $kayit = $db->fetchOne("
                    SELECT
                        t.*,
                        CONVERT(VARCHAR(19), t.BankaHareketleri_IslemTarihi, 120) AS IslemTarihiStr,
                        CONVERT(VARCHAR(19), t.BankaHareketleri_KayitTarihi, 120) AS KayitTarihiStr,
                        CONVERT(VARCHAR(19), t.GuncellemeTarihi, 120)             AS GuncellemeTarihiStr
                    FROM BankaHareketleri t
                    WHERE t.BankaHareketleri_id = ?
                ", [$id]);

                if (!$kayit) {
                    echo json_encode(['success' => false, 'message' => 'Kayıt bulunamadı.']);
                    break;
                }
                echo json_encode(['success' => true, 'data' => $kayit]);
                break;
            }

            // ── Hesap bakiyeleri (API'den anlık) ────────────────────────────
            case 'bakiyeler': {
                $yanit = PortalBankaHelper::hesaplar($db);
                if (!$yanit['success']) {
                    echo json_encode(['success' => false, 'message' => $yanit['message']]);
                    break;
                }
                echo json_encode(['success' => true, 'data' => $yanit['data'], 'kalan_hak' => $yanit['kalan_hak']]);
                break;
            }

            // ── Manuel senkron ──────────────────────────────────────────────
            case 'senkron': {
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Senkron yetkiniz yok.']);
                    break;
                }
                set_time_limit(900);
                $r = PortalBankaHelper::hareketSenkron($db, 30, (int)$user['kullanici_id']);
                echo json_encode([
                    'success'   => $r['success'],
                    'message'   => $r['message'],
                    'eklenen'   => $r['eklenen'],
                    'kalan_hak' => $r['kalan_hak'],
                ]);
                break;
            }

            // ── Dekont indir (token tarayıcıya sızmaz, sunucu üzerinden) ────
            case 'dekont': {
                $id  = (int)($_POST['id'] ?? 0);
                $row = $db->fetchOne("
                    SELECT BankaHareketleri_PortalId AS PortalId, BankaHareketleri_DekontVar AS DekontVar
                    FROM BankaHareketleri WHERE BankaHareketleri_id = ?
                ", [$id]);

                if (!$row || empty($row['DekontVar'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => 'Bu hareketin dekontu yok.']);
                    break;
                }

                $dekont = PortalBankaHelper::dekontIndir($db, (int)$row['PortalId']);
                if (!$dekont['success']) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => $dekont['message']]);
                    break;
                }

                header('Content-Type: ' . $dekont['mime']);
                header('Content-Disposition: inline; filename="' . $dekont['dosya_adi'] . '"');
                header('Content-Length: ' . strlen($dekont['icerik']));
                echo $dekont['icerik'];
                break;
            }

            // ── Eşleştirme adayı ödemeler (tutar/tarih yakınlığına göre sıralı) ─
            case 'odeme_ara': {
                $hareketId = (int)($_POST['hareket_id'] ?? 0);
                $ara       = trim($_POST['ara'] ?? '');

                $h = $db->fetchOne("
                    SELECT BankaHareketleri_Tutar AS Tutar,
                           CONVERT(VARCHAR(10), BankaHareketleri_IslemTarihi, 23) AS Tarih
                    FROM BankaHareketleri WHERE BankaHareketleri_id = ?
                ", [$hareketId]);

                $tutar = (float)($h['Tutar'] ?? 0);
                $tarih = $h['Tarih'] ?? date('Y-m-d');

                // Bağlanabilecek ödemeler yalnız varsayılan türle (Avans) sınırlıdır
                $where  = ['o.Durum = 1', 't.OdemeTurleri_Ad = ?'];
                $params = [VARSAYILAN_ODEME_TURU];
                if ($ara !== '') {
                    $where[]  = "(o.Odemeler_Referans LIKE ? OR o.Odemeler_Aciklama LIKE ? OR b.KullaniciBirim_Adi LIKE ?)";
                    $params[] = "%$ara%"; $params[] = "%$ara%"; $params[] = "%$ara%";
                }
                // Zaten başka bir harekete bağlı ödemeler listelenmez
                $where[] = "NOT EXISTS (
                    SELECT 1 FROM BankaHareketleri bh
                    WHERE bh.BankaHareketleri_Odemeler_id = o.Odemeler_Id
                      AND bh.BankaHareketleri_id <> ?
                )";
                $params[] = $hareketId;

                // Sıralama: önce ödeme tarihi hareket tarihiyle aynı olanlar, sonra tutar yakınlığı
                $sirala = array_merge([$tutar, $tarih], $params);
                $liste  = $db->fetchAll("
                    SELECT TOP 50
                        o.Odemeler_Id                                AS Id,
                        o.Odemeler_Tutar                             AS Tutar,
                        CONVERT(VARCHAR(10), o.Odemeler_Tarih, 23)   AS Tarih,
                        o.Odemeler_Referans                          AS Referans,
                        t.OdemeTurleri_Ad                            AS TurAdi,
                        b.KullaniciBirim_Adi                         AS BirimAdi,
                        ABS(o.Odemeler_Tutar - ?)                    AS TutarFarki,
                        ABS(DATEDIFF(day, o.Odemeler_Tarih, ?))      AS GunFarki
                    FROM Odemeler o
                    LEFT JOIN OdemeTurleri  t ON t.OdemeTurleri_Id   = o.Odemeler_OdemeTuruId
                    LEFT JOIN KullaniciBirim b ON b.KullaniciBirim_id = o.Odemeler_KullaniciBirim_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY GunFarki, TutarFarki, o.Odemeler_Id DESC
                ", $sirala);

                echo json_encode(['success' => true, 'data' => $liste, 'hareket' => ['tutar' => $tutar, 'tarih' => $tarih]]);
                break;
            }

            // ── Hareketi mevcut bir ödemeye bağla ───────────────────────────
            case 'odeme_bagla': {
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Eşleştirme yetkiniz yok.']);
                    break;
                }
                $hareketId = (int)($_POST['hareket_id'] ?? 0);
                $odemeId   = (int)($_POST['odeme_id'] ?? 0);

                $h = $db->fetchOne("SELECT BankaHareketleri_id FROM BankaHareketleri WHERE BankaHareketleri_id = ?", [$hareketId]);
                // Tür kısıtı sunucuda da doğrulanır; istemci listeyi atlayıp başka tür gönderemez
                $o = $db->fetchOne("
                    SELECT o.Odemeler_Id
                    FROM Odemeler o
                    INNER JOIN OdemeTurleri t ON t.OdemeTurleri_Id = o.Odemeler_OdemeTuruId
                    WHERE o.Odemeler_Id = ? AND o.Durum = 1 AND t.OdemeTurleri_Ad = ?
                ", [$odemeId, VARSAYILAN_ODEME_TURU]);
                if (!$h) {
                    echo json_encode(['success' => false, 'message' => 'Hareket bulunamadı.']);
                    break;
                }
                if (!$o) {
                    echo json_encode(['success' => false, 'message' => 'Yalnız ' . VARSAYILAN_ODEME_TURU . ' türündeki ödemelere bağlanabilir.']);
                    break;
                }

                $bagli = $db->fetchOne("
                    SELECT BankaHareketleri_id FROM BankaHareketleri
                    WHERE BankaHareketleri_Odemeler_id = ? AND BankaHareketleri_id <> ?
                ", [$odemeId, $hareketId]);
                if ($bagli) {
                    echo json_encode(['success' => false, 'message' => 'Bu ödeme zaten başka bir harekete bağlı.']);
                    break;
                }

                $db->update('BankaHareketleri', [
                    'BankaHareketleri_Odemeler_id'     => $odemeId,
                    'BankaHareketleri_EslesmeDurumu'   => 1,
                    'BankaHareketleri_EsleyenKullanici' => (int)$user['kullanici_id'],
                    'BankaHareketleri_EslesmeTarihi'   => date('Y-m-d H:i:s'),
                    'GuncelleyenKullanici'             => (int)$user['kullanici_id'],
                    'GuncellemeTarihi'                 => date('Y-m-d H:i:s'),
                ], ['BankaHareketleri_id' => $hareketId]);

                echo json_encode(['success' => true, 'message' => 'Hareket #' . $odemeId . ' numaralı ödemeye bağlandı.']);
                break;
            }

            // ── Hareketten yeni ödeme oluştur ───────────────────────────────
            case 'odeme_olustur': {
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme oluşturma yetkiniz yok.']);
                    break;
                }

                $hareketId = (int)($_POST['hareket_id'] ?? 0);
                $turId     = (int)($_POST['odeme_turu_id'] ?? 0);
                $birimId   = ($_POST['birim_id'] ?? '') !== '' ? (int)$_POST['birim_id'] : null;
                $tutar     = tutarCoz($_POST['tutar'] ?? null);
                $tarih     = trim($_POST['tarih'] ?? '');
                $referans  = trim($_POST['referans'] ?? '');
                $aciklama  = trim($_POST['aciklama'] ?? '');
                $dekontAl  = !empty($_POST['dekont_al']);

                $h = $db->fetchOne("SELECT * FROM BankaHareketleri WHERE BankaHareketleri_id = ?", [$hareketId]);
                if (!$h) {
                    echo json_encode(['success' => false, 'message' => 'Hareket bulunamadı.']);
                    break;
                }
                if ((int)$h['BankaHareketleri_EslesmeDurumu'] === 1) {
                    echo json_encode(['success' => false, 'message' => 'Bu hareket zaten bir ödemeye bağlı.']);
                    break;
                }
                if ($turId <= 0)               { echo json_encode(['success' => false, 'message' => 'Ödeme türü zorunludur.']); break; }
                if ($tutar === null || $tutar <= 0) { echo json_encode(['success' => false, 'message' => 'Geçerli bir tutar giriniz.']); break; }
                if ($tarih === '')        { echo json_encode(['success' => false, 'message' => 'Tarih zorunludur.']); break; }
                if ($birimId === null)    { echo json_encode(['success' => false, 'message' => 'Birim (bayi) seçmelisiniz.']); break; }

                // Dekont varsa indirilip ödeme dökümanı olarak kaydedilir
                $dokumanYolu = null;
                if ($dekontAl && !empty($h['BankaHareketleri_DekontVar'])) {
                    $dekont = PortalBankaHelper::dekontIndir($db, (int)$h['BankaHareketleri_PortalId']);
                    if ($dekont['success']) {
                        if (!is_dir(ODEME_UPLOAD_DIR)) {
                            mkdir(ODEME_UPLOAD_DIR, 0775, true);
                        }
                        $dosyaAdi = 'dekont_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.pdf';
                        if (file_put_contents(ODEME_UPLOAD_DIR . $dosyaAdi, $dekont['icerik']) !== false) {
                            $dokumanYolu = ODEME_UPLOAD_URL . $dosyaAdi;
                        }
                    }
                }

                $simdi = date('Y-m-d H:i:s');
                $odemeId = $db->insert('Odemeler', [
                    'Odemeler_OdemeTuruId'       => $turId,
                    'Odemeler_Tutar'             => $tutar,
                    'Odemeler_Tarih'             => $tarih,
                    'Odemeler_Referans'          => $referans !== '' ? $referans : null,
                    'Odemeler_Aciklama'          => $aciklama !== '' ? $aciklama : null,
                    'Odemeler_Dokuman'           => $dokumanYolu,
                    'Odemeler_KullaniciBirim_id' => $birimId,
                    'Durum'                      => 1,
                    'OlusturanKullanici'         => (int)$user['kullanici_id'],
                    'OlusturmaTarihi'            => $simdi,
                    'GuncelleyenKullanici'       => (int)$user['kullanici_id'],
                    'GuncellemeTarihi'           => $simdi,
                ]);

                if (!$odemeId) {
                    echo json_encode(['success' => false, 'message' => 'Ödeme kaydı oluşturulamadı.']);
                    break;
                }

                $db->update('BankaHareketleri', [
                    'BankaHareketleri_Odemeler_id'      => $odemeId,
                    'BankaHareketleri_EslesmeDurumu'    => 1,
                    'BankaHareketleri_EsleyenKullanici' => (int)$user['kullanici_id'],
                    'BankaHareketleri_EslesmeTarihi'    => $simdi,
                    'GuncelleyenKullanici'              => (int)$user['kullanici_id'],
                    'GuncellemeTarihi'                  => $simdi,
                ], ['BankaHareketleri_id' => $hareketId]);

                echo json_encode([
                    'success'  => true,
                    'message'  => 'Ödeme #' . $odemeId . ' oluşturuldu ve harekete bağlandı.'
                                . ($dokumanYolu ? ' Dekont döküman olarak eklendi.' : ''),
                    'odeme_id' => $odemeId,
                ]);
                break;
            }

            // ── Eşleşmeyi kaldır / hareketi yoksay ──────────────────────────
            case 'eslesme_guncelle': {
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']);
                    break;
                }
                $hareketId = (int)($_POST['hareket_id'] ?? 0);
                $durum     = (int)($_POST['durum'] ?? 0);   // 0=bekliyor 2=yoksayıldı
                if (!in_array($durum, [0, 2], true)) {
                    echo json_encode(['success' => false, 'message' => 'Geçersiz durum.']);
                    break;
                }

                $db->update('BankaHareketleri', [
                    'BankaHareketleri_Odemeler_id'      => null,
                    'BankaHareketleri_EslesmeDurumu'    => $durum,
                    'BankaHareketleri_EsleyenKullanici' => $durum === 2 ? (int)$user['kullanici_id'] : null,
                    'BankaHareketleri_EslesmeTarihi'    => $durum === 2 ? date('Y-m-d H:i:s') : null,
                    'GuncelleyenKullanici'              => (int)$user['kullanici_id'],
                    'GuncellemeTarihi'                  => date('Y-m-d H:i:s'),
                ], ['BankaHareketleri_id' => $hareketId]);

                echo json_encode([
                    'success' => true,
                    'message' => $durum === 2 ? 'Hareket yoksayıldı.' : 'Eşleşme kaldırıldı, hareket tekrar bekliyor.',
                ]);
                break;
            }

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        }
    } catch (Exception $e) {
        if ($action !== 'dekont') {
            echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        }
    }
    exit;
}

$entegrasyonHazir = PortalBankaHelper::hazirMi($db);

// Ödeme oluşturma modalı için kaynaklar (statik liste yok, hepsi DB'den)
$birimler   = $db->fetchAll("
    SELECT KullaniciBirim_id, KullaniciBirim_Adi
    FROM KullaniciBirim WHERE Durum = 1 ORDER BY KullaniciBirim_Adi
");
$odemeTurleri = $db->fetchAll("
    SELECT OdemeTurleri_Id, OdemeTurleri_Ad, OdemeTurleri_GelirMi
    FROM OdemeTurleri WHERE Durum = 1 ORDER BY OdemeTurleri_Ad
");

// Varsayılan tür isimle çözülür; tür silinmiş/yeniden adlandırılmışsa listenin ilkine düşer.
$varsayilanTurId = 0;
foreach ($odemeTurleri as $t) {
    if ($t['OdemeTurleri_Ad'] === VARSAYILAN_ODEME_TURU) { $varsayilanTurId = (int)$t['OdemeTurleri_Id']; break; }
}
if (!$varsayilanTurId && $odemeTurleri) {
    $varsayilanTurId = (int)$odemeTurleri[0]['OdemeTurleri_Id'];
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
        .mono      { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .82rem; }
        .tutar     { font-weight: 600; white-space: nowrap; }
        .tutar-a   { color: #198754; }
        .tutar-b   { color: #dc3545; }
        .aciklama-hucre { max-width: 320px; }
        .aciklama-hucre .kisa { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .karsi-hucre { max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .detay-tablo th { width: 190px; white-space: nowrap; background: #f8f9fa; }
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

                <?php if (!$entegrasyonHazir): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    Banka entegrasyonu tanımlı değil. Entegrasyon Yönetimi sayfasından
                    <strong>tip: banka</strong> kaydını (Base URL + API Key) ekleyin.
                </div>
                <?php endif; ?>

                <!-- InfoBox -->
                <div class="row mb-3">
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-list-columns"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Hareket (filtreli)</span>
                                <span class="info-box-number" id="stat-adet">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-box-arrow-in-down"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Giriş (Alacak)</span>
                                <span class="info-box-number" id="stat-giris">0,00</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-box-arrow-up"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Çıkış (Borç)</span>
                                <span class="info-box-number" id="stat-cikis">0,00</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box text-bg-secondary">
                            <span class="info-box-icon"><i class="bi bi-arrow-left-right"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Net</span>
                                <span class="info-box-number" id="stat-net">0,00</span>
                                <span class="info-box-text small" id="stat-senkron">Son senkron: -</span>
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
                        <form id="filterForm" onsubmit="return false;">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Ara</label>
                                    <input type="text" class="form-control" id="filter_search"
                                           placeholder="Karşı taraf, açıklama, referans, IBAN, VKN/TCKN...">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Banka</label>
                                    <select class="form-select" id="filter_banka">
                                        <option value="">Tümü</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Hesap</label>
                                    <select class="form-select" id="filter_hesap">
                                        <option value="">Tümü</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Karşı Taraf</label>
                                    <select class="form-select" id="filter_karsi_taraf">
                                        <option value="" selected>Seçili firmalar (<?= count(KARSI_TARAF_FILTRELERI) ?>)</option>
                                        <?php foreach (KARSI_TARAF_FILTRELERI as $anahtar => $f): ?>
                                        <option value="<?= htmlspecialchars($anahtar) ?>"><?= htmlspecialchars($f['etiket']) ?></option>
                                        <?php endforeach; ?>
                                        <option value="<?= KARSI_TARAF_TUMU ?>">Tümü (filtresiz)</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Hareket Tipi</label>
                                    <select class="form-select" id="filter_tip">
                                        <option value="">Tümü</option>
                                        <option value="A">Alacak (para girişi)</option>
                                        <option value="B">Borç (para çıkışı)</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Başlangıç Tarihi</label>
                                    <input type="date" class="form-control" id="filter_tarih_bas">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Bitiş Tarihi</label>
                                    <input type="date" class="form-control" id="filter_tarih_bit">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Min. Tutar</label>
                                    <input type="text" class="form-control" id="filter_min_tutar"
                                           placeholder="<?= VARSAYILAN_MIN_TUTAR ?> (varsayılan)">
                                    <div class="form-text">Tümünü görmek için 0 yazın.</div>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Maks. Tutar</label>
                                    <input type="text" class="form-control" id="filter_max_tutar" placeholder="0">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Ödeme Eşleşmesi</label>
                                    <select class="form-select" id="filter_eslesme">
                                        <option value="">Tümü</option>
                                        <option value="0">Bekliyor</option>
                                        <option value="1">Eşleşti</option>
                                        <option value="2">Yoksayıldı</option>
                                    </select>
                                </div>
                                <div class="col-md-2 d-flex align-items-end gap-2">
                                    <button type="button" class="btn btn-primary w-100" id="btnFiltrele">
                                        <i class="bi bi-search"></i> Filtrele
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="btnTemizle" title="Temizle">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tablo -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            Hesap Hareketleri
                            <span class="badge text-bg-info ms-2" id="ktRozet"></span>
                            <span class="badge text-bg-secondary ms-1" id="minRozet"></span>
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btnBakiyeler">
                                <i class="bi bi-wallet2"></i> Hesap Bakiyeleri
                            </button>
                            <?php if ($permissions['can_edit']): ?>
                            <button type="button" class="btn btn-success btn-sm" id="btnSenkron">
                                <i class="bi bi-arrow-repeat"></i> Şimdi Senkronize Et
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <table id="kayitTable" class="table table-bordered table-striped table-hover" style="width:100%">
                            <thead>
                                <tr>
                                    <th>İşlem Tarihi</th>
                                    <th>Banka</th>
                                    <th>Hesap</th>
                                    <th>Karşı Taraf</th>
                                    <th>Açıklama</th>
                                    <th class="text-center">Tip</th>
                                    <th class="text-end">Tutar</th>
                                    <th class="text-end">Kalan Bakiye</th>
                                    <th class="text-center">Ödeme</th>
                                    <th class="text-center" style="width:150px">İşlemler</th>
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

<!-- Detay Modal -->
<div class="modal fade" id="detayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt"></i> Hareket Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detayGovde">
                <div class="text-center text-muted py-4">Yükleniyor...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<!-- Ödemeye Aktar Modal -->
<div class="modal fade" id="odemeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-arrow-left-right"></i> Ödemeye Aktar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="om_hareket_id">

                <div class="alert alert-light border mb-3" id="om_ozet"></div>

                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#om_yeni" type="button" role="tab">
                            <i class="bi bi-plus-circle"></i> Yeni Ödeme Oluştur
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#om_mevcut" type="button" role="tab">
                            <i class="bi bi-link-45deg"></i> Mevcut Ödemeye Bağla
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Yeni ödeme -->
                    <div class="tab-pane fade show active" id="om_yeni" role="tabpanel">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Ödeme Türü <span class="text-danger">*</span></label>
                                <select class="form-select" id="om_tur">
                                    <?php foreach ($odemeTurleri as $t): ?>
                                    <option value="<?= (int)$t['OdemeTurleri_Id'] ?>"
                                        <?= (int)$t['OdemeTurleri_Id'] === $varsayilanTurId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['OdemeTurleri_Ad']) ?>
                                        (<?= (int)$t['OdemeTurleri_GelirMi'] === 1 ? 'Gelir' : 'Gider' ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Birim / Bayi <span class="text-danger">*</span></label>
                                <select class="form-select" id="om_birim">
                                    <option value="">Seçin...</option>
                                    <?php foreach ($birimler as $b): ?>
                                    <option value="<?= (int)$b['KullaniciBirim_id'] ?>"><?= htmlspecialchars($b['KullaniciBirim_Adi']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tutar <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="om_tutar">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tarih <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="om_tarih">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Referans</label>
                                <input type="text" class="form-control" id="om_referans"
                                       placeholder="Banka referans numarası">
                                <div class="form-text">Hareketin banka referans numarasıyla dolu gelir; değiştirebilirsiniz.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Açıklama</label>
                                <textarea class="form-control" id="om_aciklama" rows="2"></textarea>
                            </div>
                            <div class="col-12" id="om_dekont_alan">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="om_dekont" value="1" checked>
                                    <label class="form-check-label" for="om_dekont">Dekontu indirip ödeme dökümanı olarak ekle</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Mevcut ödeme -->
                    <div class="tab-pane fade" id="om_mevcut" role="tabpanel">
                        <div class="input-group mb-3">
                            <input type="text" class="form-control" id="om_ara" placeholder="Referans, açıklama veya birim adına göre ara...">
                            <button type="button" class="btn btn-outline-primary" id="om_ara_btn">
                                <i class="bi bi-search"></i> Ara
                            </button>
                        </div>
                        <p class="text-muted small mb-2">
                            Yalnız <strong><?= htmlspecialchars(VARSAYILAN_ODEME_TURU) ?></strong> türündeki ödemeler listelenir.
                            Ödeme tarihi hareket tarihiyle <strong>aynı</strong> olanlar en üstte önerilir;
                            tutar da tutuyorsa <span class="badge bg-success">Birebir</span> olarak işaretlenir.
                        </p>
                        <div id="om_liste"><div class="text-muted text-center py-3">Yükleniyor...</div></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn btn-primary" id="om_kaydet">
                    <i class="bi bi-check-lg"></i> Ödemeyi Oluştur
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bakiyeler Modal -->
<div class="modal fade" id="bakiyeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-wallet2"></i> Hesap Bakiyeleri</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="bakiyeGovde">
                <div class="text-center text-muted py-4">Yükleniyor...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
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
    const CAN_EDIT         = <?= $permissions['can_edit'] ? 'true' : 'false' ?>;
    const VARSAYILAN_TUR_ID = <?= (int)$varsayilanTurId ?>;

    let dataTable, detayModal, bakiyeModal, odemeModal;

    $(document).ready(function () {
        detayModal  = new bootstrap.Modal(document.getElementById('detayModal'));
        bakiyeModal = new bootstrap.Modal(document.getElementById('bakiyeModal'));
        odemeModal  = new bootstrap.Modal(document.getElementById('odemeModal'));

        // Modal içindeki select'ler: custom.js otomatik init ettiği için önce destroy edilir
        ['#om_tur', '#om_birim'].forEach(function (sel) {
            if ($(sel).hasClass('select2-hidden-accessible')) $(sel).select2('destroy');
            $(sel).select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#odemeModal') });
        });

        $('#om_kaydet').on('click', odemeOlustur);
        $('#om_ara_btn').on('click', odemeAdaylariYukle);
        $('#om_ara').on('keypress', function (e) { if (e.which === 13) odemeAdaylariYukle(); });

        // Sekmeye göre alt buton değişir: yeni ödeme oluşturma yalnız ilk sekmede anlamlı
        $('[data-bs-target="#om_yeni"]').on('shown.bs.tab',   function () { $('#om_kaydet').show(); });
        $('[data-bs-target="#om_mevcut"]').on('shown.bs.tab', function () { $('#om_kaydet').hide(); });

        dataTable = $('#kayitTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            processing: true,
            serverSide: true,
            autoWidth: false,
            scrollX: true,
            dom: 'lrtip', // global arama kapalı — filtre panelindeki "Ara" kullanılır
            order: [[0, 'desc']],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            ajax: {
                url: '', type: 'POST',
                data: function (d) {
                    d.action        = 'list';
                    d.search.value  = $('#filter_search').val() || '';
                    d.f_banka       = $('#filter_banka').val() || '';
                    d.f_hesap       = $('#filter_hesap').val() || '';
                    d.f_karsi_taraf = $('#filter_karsi_taraf').val() || '';
                    d.f_tip         = $('#filter_tip').val() || '';
                    d.f_eslesme     = $('#filter_eslesme').val() || '';
                    d.f_tarih_bas   = $('#filter_tarih_bas').val() || '';
                    d.f_tarih_bit   = $('#filter_tarih_bit').val() || '';
                    d.f_min_tutar   = $('#filter_min_tutar').val() || '';
                    d.f_max_tutar   = $('#filter_max_tutar').val() || '';
                    return d;
                },
                error: function () { showToast('Liste yüklenirken hata oluştu', 'error'); }
            },
            columns: [
                { data: 'IslemTarihi', render: d => escapeHtml(d || '-') },
                { data: 'BankaAdi',    render: d => escapeHtml(d || '-') },
                { data: null,          render: (d, t, r) => hesapHucre(r) },
                { data: 'KarsiTaraf',  render: d => `<div class="karsi-hucre" title="${escapeHtml(d || '')}">${escapeHtml(d || '-')}</div>` },
                { data: 'Aciklama',    render: d => `<div class="aciklama-hucre" title="${escapeHtml(d || '')}"><span class="kisa">${escapeHtml(d || '-')}</span></div>` },
                { data: 'Tip',         className: 'text-center', render: d => tipRozet(d) },
                { data: null,          className: 'text-end', render: (d, t, r) => tutarHucre(r) },
                { data: null,          className: 'text-end', render: (d, t, r) => r.KalanBakiye === null ? '-' : `<span class="mono">${paraBicim(r.KalanBakiye)}</span>` },
                { data: null,          className: 'text-center', orderable: false, render: (d, t, r) => eslesmeRozet(r.EslesmeDurumu, r.OdemeId) },
                { data: null,          className: 'text-center', orderable: false, render: (d, t, r) => islemlerHucre(r) }
            ]
        });

        filtreKaynakYukle();
        loadStats();
        ktRozetGuncelle();

        $('#btnFiltrele').on('click', function () { dataTable.ajax.reload(); loadStats(); ktRozetGuncelle(); });
        $('#btnTemizle').on('click', function () {
            $('#filter_search, #filter_tarih_bas, #filter_tarih_bit, #filter_min_tutar, #filter_max_tutar').val('');
            $('#filter_banka, #filter_hesap, #filter_karsi_taraf, #filter_tip, #filter_eslesme').val('').trigger('change');
            dataTable.ajax.reload();
            loadStats();
            ktRozetGuncelle();
        });
        $('#filter_search').on('keypress', function (e) {
            if (e.which === 13) { dataTable.ajax.reload(); loadStats(); ktRozetGuncelle(); }
        });

        $('#btnSenkron').on('click', senkronCalistir);
        $('#btnBakiyeler').on('click', bakiyeleriGoster);

        $('#kayitTable tbody').on('click', '.btn-detay', function () {
            detayAc($(this).data('id'));
        });

        // Sidebar açılıp kapanınca kolon genişliklerini yeniden hesapla
        $(document).on('click', '[data-lte-toggle="sidebar"]', function () {
            setTimeout(function () { if (dataTable) dataTable.columns.adjust(); }, 350);
        });
    });

    // Karşı taraf ve min. tutar filtreleri varsayılan olarak açık;
    // kullanıcı ne gördüğünü başlıktaki rozetlerden anlasın
    function ktRozetGuncelle() {
        const secim = $('#filter_karsi_taraf').val() || '';
        const $r = $('#ktRozet');
        if (secim === '<?= KARSI_TARAF_TUMU ?>') {
            $r.hide();
        } else {
            const etiket = secim === ''
                ? 'Seçili firmalar'
                : $('#filter_karsi_taraf option:selected').text();
            $r.text(etiket).show();
        }

        // Min. tutar: boşsa varsayılan sınır uygulanır
        const min = ($('#filter_min_tutar').val() || '').trim();
        const etkinMin = min === '' ? <?= VARSAYILAN_MIN_TUTAR ?> : parseFloat(min.replace(/\./g, '').replace(',', '.'));
        const $m = $('#minRozet');
        if (!etkinMin || isNaN(etkinMin) || etkinMin <= 0) $m.hide();
        else $m.text('≥ ' + paraBicim(etkinMin)).show();
    }

    // ── Hücre üreticileri ────────────────────────────────────────────────────
    function hesapHucre(r) {
        const no = escapeHtml(r.HesapNo || '-');
        const ib = r.Iban ? `<br><small class="mono text-muted">${escapeHtml(r.Iban)}</small>` : '';
        return `<span class="mono">${no}</span>${ib}`;
    }

    function tipRozet(tip) {
        if (tip === 'A') return '<span class="badge bg-success">Alacak</span>';
        if (tip === 'B') return '<span class="badge bg-danger">Borç</span>';
        return '<span class="badge bg-secondary">-</span>';
    }

    function tutarHucre(r) {
        const sinif = r.Tip === 'A' ? 'tutar-a' : (r.Tip === 'B' ? 'tutar-b' : '');
        const isaret = r.Tip === 'A' ? '+' : (r.Tip === 'B' ? '-' : '');
        return `<span class="tutar ${sinif}">${isaret}${paraBicim(r.Tutar)} ${escapeHtml(r.ParaBirimi || '')}</span>`;
    }

    function islemlerHucre(r) {
        let html = `<button type="button" class="btn btn-sm btn-outline-primary btn-detay" data-id="${r.Id}" title="Detay">
                        <i class="bi bi-eye"></i>
                    </button>`;
        if (Number(r.DekontVar) === 1) {
            html += ` <button type="button" class="btn btn-sm btn-outline-danger" title="Dekont"
                          onclick="dekontAc(${r.Id})"><i class="bi bi-file-earmark-pdf"></i></button>`;
        }
        if (!CAN_EDIT) return html;

        if (Number(r.EslesmeDurumu) === 1) {
            html += ` <button type="button" class="btn btn-sm btn-outline-secondary" title="Eşleşmeyi kaldır"
                          onclick="eslesmeGuncelle(${r.Id}, 0)"><i class="bi bi-link-45deg"></i></button>`;
        } else {
            html += ` <button type="button" class="btn btn-sm btn-success" title="Ödemeye aktar"
                          onclick="odemeModalAc(${r.Id})"><i class="bi bi-cash-coin"></i></button>`;
            if (Number(r.EslesmeDurumu) !== 2) {
                html += ` <button type="button" class="btn btn-sm btn-outline-secondary" title="Yoksay"
                              onclick="eslesmeGuncelle(${r.Id}, 2)"><i class="bi bi-slash-circle"></i></button>`;
            }
        }
        return html;
    }

    // ── Ödemeye aktar ────────────────────────────────────────────────────────
    function odemeModalAc(id) {
        const satir = dataTable.rows().data().toArray().find(r => Number(r.Id) === Number(id));
        if (!satir) return;

        $('#om_hareket_id').val(id);
        $('#om_ozet').html(`
            <div class="d-flex justify-content-between flex-wrap gap-2">
                <div><strong>${escapeHtml(satir.BankaAdi || '-')}</strong>
                     <span class="mono">${escapeHtml(satir.HesapNo || '')}</span></div>
                <div>${escapeHtml(satir.IslemTarihi || '')}</div>
                <div>${tutarHucre(satir)}</div>
            </div>
            <div class="small text-muted mt-1">${escapeHtml(satir.KarsiTaraf || '')} — ${escapeHtml(satir.Aciklama || '')}</div>
        `);

        // Yeni ödeme formu hareketten doldurulur.
        // Referans = bankanın referans numarası; karşı taraf açıklamaya girer.
        $('#om_tutar').val(paraBicim(satir.Tutar));
        $('#om_tarih').val((satir.IslemTarihi || '').substring(0, 10));
        $('#om_referans').val(satir.ReferansNo || '');
        $('#om_aciklama').val([satir.KarsiTaraf, satir.Aciklama].filter(Boolean).join(' — '));
        $('#om_birim').val('').trigger('change');
        $('#om_tur').val(VARSAYILAN_TUR_ID).trigger('change');
        $('#om_dekont_alan').toggle(Number(satir.DekontVar) === 1);
        $('#om_dekont').prop('checked', Number(satir.DekontVar) === 1);

        // Sekme her açılışta "Yeni Ödeme"ye döner
        bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#om_yeni"]')).show();
        $('#om_ara').val('');
        $('#om_liste').html('<div class="text-muted text-center py-3">Yükleniyor...</div>');
        odemeAdaylariYukle();

        odemeModal.show();
    }

    function odemeAdaylariYukle() {
        $.post('', {
            action:     'odeme_ara',
            hareket_id: $('#om_hareket_id').val(),
            ara:        $('#om_ara').val() || ''
        }, function (r) {
            if (!r.success) {
                $('#om_liste').html(`<div class="alert alert-danger mb-0">${escapeHtml(r.message)}</div>`);
                return;
            }
            if (!r.data.length) {
                $('#om_liste').html('<div class="text-muted text-center py-3">Bağlanabilecek <?= htmlspecialchars(VARSAYILAN_ODEME_TURU) ?> kaydı bulunamadı.</div>');
                return;
            }
            let satirlar = '';
            r.data.forEach(function (o) {
                const ayniTarih  = Number(o.GunFarki) === 0;
                const ayniTutar  = Number(o.TutarFarki) === 0;
                // Tarih eşleşmesi önerinin ana ölçütü; tutar da tutuyorsa "birebir" sayılır
                const satirSinif = ayniTarih ? (ayniTutar ? 'table-success' : 'table-warning') : '';

                let isaret = '';
                if (ayniTarih && ayniTutar) isaret = '<span class="badge bg-success ms-1">Birebir</span>';
                else if (ayniTarih)         isaret = '<span class="badge bg-warning text-dark ms-1">Aynı tarih</span>';

                satirlar += `<tr class="${satirSinif}">
                    <td class="mono">#${o.Id}</td>
                    <td>${escapeHtml(o.Tarih || '-')}${isaret}</td>
                    <td>${escapeHtml(o.TurAdi || '-')}</td>
                    <td>${escapeHtml(o.BirimAdi || '-')}</td>
                    <td>${escapeHtml(o.Referans || '-')}</td>
                    <td class="text-end tutar">${paraBicim(o.Tutar)}</td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-primary" onclick="odemeyeBagla(${o.Id})">
                            <i class="bi bi-link-45deg"></i> Bağla
                        </button>
                    </td>
                </tr>`;
            });
            $('#om_liste').html(`
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-hover mb-0">
                        <thead><tr>
                            <th>No</th><th>Tarih</th><th>Tür</th><th>Birim</th><th>Referans</th>
                            <th class="text-end">Tutar</th><th class="text-center" style="width:90px"></th>
                        </tr></thead>
                        <tbody>${satirlar}</tbody>
                    </table>
                </div>
            `);
        }, 'json');
    }

    function odemeyeBagla(odemeId) {
        $.post('', {
            action:     'odeme_bagla',
            hareket_id: $('#om_hareket_id').val(),
            odeme_id:   odemeId
        }, function (r) {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) { odemeModal.hide(); dataTable.ajax.reload(null, false); }
        }, 'json');
    }

    function odemeOlustur() {
        const $btn = $('#om_kaydet');
        $btn.prop('disabled', true);

        $.post('', {
            action:        'odeme_olustur',
            hareket_id:    $('#om_hareket_id').val(),
            odeme_turu_id: $('#om_tur').val(),
            birim_id:      $('#om_birim').val(),
            tutar:         $('#om_tutar').val(),
            tarih:         $('#om_tarih').val(),
            referans:      $('#om_referans').val(),
            aciklama:      $('#om_aciklama').val(),
            dekont_al:     $('#om_dekont').is(':checked') ? 1 : 0
        }, function (r) {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) { odemeModal.hide(); dataTable.ajax.reload(null, false); }
        }, 'json').fail(function () {
            showToast('Ödeme oluşturulamadı.', 'error');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    }

    function eslesmeGuncelle(id, durum) {
        const mesaj = durum === 2
            ? 'Bu hareket yoksayılacak. Devam edilsin mi?'
            : 'Ödeme bağlantısı kaldırılacak (ödeme kaydı silinmez). Devam edilsin mi?';

        Swal.fire({
            title: 'Onay', text: mesaj, icon: 'question',
            showCancelButton: true, confirmButtonText: 'Evet', cancelButtonText: 'Vazgeç'
        }).then(function (s) {
            if (!s.isConfirmed) return;
            $.post('', { action: 'eslesme_guncelle', hareket_id: id, durum: durum }, function (r) {
                showToast(r.message, r.success ? 'success' : 'error');
                if (r.success) dataTable.ajax.reload(null, false);
            }, 'json');
        });
    }

    // ── Dekont: POST ile yeni sekmede açılır (token sunucuda kalır) ──────────
    function dekontAc(id) {
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = '';
        f.target = '_blank';
        f.innerHTML = `<input type="hidden" name="action" value="dekont">
                       <input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(f);
        f.submit();
        document.body.removeChild(f);
    }

    // ── Detay ────────────────────────────────────────────────────────────────
    function detayAc(id) {
        $('#detayGovde').html('<div class="text-center text-muted py-4">Yükleniyor...</div>');
        detayModal.show();

        $.post('', { action: 'detay', id: id }, function (r) {
            if (!r.success) {
                $('#detayGovde').html(`<div class="alert alert-danger mb-0">${escapeHtml(r.message)}</div>`);
                return;
            }
            const d = r.data;
            const satir = (baslik, deger) => `<tr><th>${baslik}</th><td>${deger}</td></tr>`;
            const tip = d.BankaHareketleri_Tip;

            $('#detayGovde').html(`
                <table class="table table-sm table-bordered detay-tablo mb-0">
                    ${satir('Portal Hareket No', `<span class="mono">${d.BankaHareketleri_PortalId}</span>`)}
                    ${satir('İşlem Tarihi',      escapeHtml(d.IslemTarihiStr || '-'))}
                    ${satir('Kayıt Tarihi',      escapeHtml(d.KayitTarihiStr || '-'))}
                    ${satir('Firma',             escapeHtml(d.BankaHareketleri_FirmaAdi || '-'))}
                    ${satir('Banka',             escapeHtml(d.BankaHareketleri_BankaAdi || '-'))}
                    ${satir('Hesap No',          `<span class="mono">${escapeHtml(d.BankaHareketleri_HesapNo || '-')}</span>`)}
                    ${satir('IBAN',              `<span class="mono">${escapeHtml(d.BankaHareketleri_Iban || '-')}</span>`)}
                    ${satir('Karşı Taraf',       escapeHtml(d.BankaHareketleri_KarsiTaraf || '-'))}
                    ${satir('VKN / TCKN',        `<span class="mono">${escapeHtml(d.BankaHareketleri_VknTckn || '-')}</span>`)}
                    ${satir('Tip',               tipRozet(tip))}
                    ${satir('Tutar',             `<span class="tutar ${tip === 'A' ? 'tutar-a' : 'tutar-b'}">${paraBicim(d.BankaHareketleri_Tutar)} ${escapeHtml(d.BankaHareketleri_ParaBirimi || '')}</span>`)}
                    ${satir('Kalan Bakiye',      d.BankaHareketleri_KalanBakiye === null ? '-' : `<span class="mono">${paraBicim(d.BankaHareketleri_KalanBakiye)}</span>`)}
                    ${satir('Açıklama',          escapeHtml(d.BankaHareketleri_Aciklama || '-'))}
                    ${satir('Referans No',       `<span class="mono">${escapeHtml(d.BankaHareketleri_ReferansNo || '-')}</span>`)}
                    ${satir('Masraf mı?',        Number(d.BankaHareketleri_MasrafMi) === 1 ? 'Evet' : 'Hayır')}
                    ${satir('Dekont',            Number(d.BankaHareketleri_DekontVar) === 1
                        ? `<button type="button" class="btn btn-sm btn-outline-danger" onclick="dekontAc(${d.BankaHareketleri_id})"><i class="bi bi-file-earmark-pdf"></i> Dekontu Aç</button>`
                        : '<span class="text-muted">Yok</span>')}
                    ${satir('Ödeme Eşleşmesi',   eslesmeRozet(d.BankaHareketleri_EslesmeDurumu, d.BankaHareketleri_Odemeler_id))}
                    ${satir('Son Güncelleme',    escapeHtml(d.GuncellemeTarihiStr || '-'))}
                </table>
            `);
        }, 'json').fail(function () {
            $('#detayGovde').html('<div class="alert alert-danger mb-0">Detay alınamadı.</div>');
        });
    }

    function eslesmeRozet(durum, odemeId) {
        const d = Number(durum);
        if (d === 1) return `<span class="badge bg-success">Eşleşti</span> <span class="mono">#${odemeId || '?'}</span>`;
        if (d === 2) return '<span class="badge bg-secondary">Yoksayıldı</span>';
        return '<span class="badge bg-warning text-dark">Bekliyor</span>';
    }

    // ── Hesap bakiyeleri (API'den anlık) ─────────────────────────────────────
    function bakiyeleriGoster() {
        $('#bakiyeGovde').html('<div class="text-center text-muted py-4">Yükleniyor...</div>');
        bakiyeModal.show();

        $.post('', { action: 'bakiyeler' }, function (r) {
            if (!r.success) {
                $('#bakiyeGovde').html(`<div class="alert alert-danger mb-0">${escapeHtml(r.message)}</div>`);
                return;
            }
            if (!r.data.length) {
                $('#bakiyeGovde').html('<div class="text-muted text-center py-4">Hesap bulunamadı.</div>');
                return;
            }

            let toplam = 0;
            let satirlar = '';
            r.data.forEach(function (h) {
                toplam += Number(h.bakiye || 0);
                satirlar += `<tr>
                    <td>${escapeHtml(h.banka ? h.banka.ad : '-')}</td>
                    <td class="mono">${escapeHtml(h.hesap_no || '-')}</td>
                    <td class="mono">${escapeHtml(h.iban || '-')}</td>
                    <td>${escapeHtml(h.sube_adi || '-')}</td>
                    <td class="text-end tutar ${Number(h.bakiye) < 0 ? 'tutar-b' : 'tutar-a'}">${paraBicim(h.bakiye)}</td>
                    <td class="text-end mono">${paraBicim(h.kullanilabilir_bakiye)}</td>
                    <td class="text-center small text-muted">${escapeHtml((h.son_senkron || '').replace('T', ' '))}</td>
                </tr>`;
            });

            $('#bakiyeGovde').html(`
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-striped mb-2">
                        <thead>
                            <tr>
                                <th>Banka</th><th>Hesap No</th><th>IBAN</th><th>Şube</th>
                                <th class="text-end">Bakiye</th><th class="text-end">Kullanılabilir</th><th class="text-center">Son Senkron</th>
                            </tr>
                        </thead>
                        <tbody>${satirlar}</tbody>
                        <tfoot>
                            <tr class="table-light">
                                <th colspan="4" class="text-end">Toplam</th>
                                <th class="text-end tutar ${toplam < 0 ? 'tutar-b' : 'tutar-a'}">${paraBicim(toplam)}</th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <small class="text-muted">Veriler portal API'sinden anlık çekilmiştir.
                    ${r.kalan_hak !== null ? 'Kalan API hakkı: ' + r.kalan_hak + '.' : ''}</small>
            `);
        }, 'json').fail(function () {
            $('#bakiyeGovde').html('<div class="alert alert-danger mb-0">Bakiyeler alınamadı.</div>');
        });
    }

    // ── Manuel senkron ───────────────────────────────────────────────────────
    function senkronCalistir() {
        const $btn = $('#btnSenkron');
        const eski = $btn.html();
        $btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat"></i> Senkronlanıyor...');

        $.post('', { action: 'senkron' }, function (r) {
            showToast(r.message, r.success ? 'success' : 'error');
            if (r.success) { dataTable.ajax.reload(); loadStats(); filtreKaynakYukle(); }
        }, 'json').fail(function () {
            showToast('Senkron isteği başarısız oldu.', 'error');
        }).always(function () {
            $btn.prop('disabled', false).html(eski);
        });
    }

    // ── İstatistik ve filtre kaynakları ──────────────────────────────────────
    function loadStats() {
        $.post('', {
            action:      'stats',
            f_search:    $('#filter_search').val() || '',
            f_banka:       $('#filter_banka').val() || '',
            f_hesap:       $('#filter_hesap').val() || '',
            f_karsi_taraf: $('#filter_karsi_taraf').val() || '',
            f_tip:         $('#filter_tip').val() || '',
            f_eslesme:   $('#filter_eslesme').val() || '',
            f_tarih_bas: $('#filter_tarih_bas').val() || '',
            f_tarih_bit: $('#filter_tarih_bit').val() || '',
            f_min_tutar: $('#filter_min_tutar').val() || '',
            f_max_tutar: $('#filter_max_tutar').val() || ''
        }, function (r) {
            if (!r.success) return;
            $('#stat-adet').text(Number(r.data.adet).toLocaleString('tr-TR'));
            $('#stat-giris').text(paraBicim(r.data.giris));
            $('#stat-cikis').text(paraBicim(r.data.cikis));
            $('#stat-net').text(paraBicim(r.data.net));
            $('#stat-senkron').text('Son senkron: ' + (r.data.sonSenkron || '-'));
        }, 'json');
    }

    function filtreKaynakYukle() {
        $.post('', { action: 'filtre_kaynak' }, function (r) {
            if (!r.success) return;
            doldur('#filter_banka', r.data.bankalar);
            doldur('#filter_hesap', r.data.hesaplar);
        }, 'json');
    }

    // Select2 zaten kurulu olabilir: close + destroy → html → yeniden init sırası korunmalı
    function doldur(sel, liste) {
        const $s = $(sel);
        const secili = $s.val();
        if ($s.hasClass('select2-hidden-accessible')) {
            $s.select2('close');
            $s.select2('destroy');
        }
        let html = '<option value="">Tümü</option>';
        liste.forEach(function (o) {
            html += `<option value="${o.id}">${escapeHtml(o.ad)}</option>`;
        });
        $s.html(html).val(secili || '');
        initSelect2(sel);
    }

    // ── Yardımcılar ──────────────────────────────────────────────────────────
    function paraBicim(deger) {
        const s = Number(deger || 0);
        return s.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
</script>
</body>
</html>
