<?php
/**
 * Admin Panel - VoIP Hesapları
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';

requireAuth();

// ─── Sippy Softswitch Senkronizasyon ──────────────────────────────────────────
function sippyCurl(string $url, array $post = [], string $cookieFile = '', string $referer = '', bool $follow = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    ]);
    if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw     = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $final   = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $hdrSize), $m);
    return ['body' => substr($raw, $hdrSize), 'code' => $code, 'final' => $final, 'location' => trim($m[1] ?? '')];
}

function sippySync(array $kanal, $db): array
{
    $baseUrl    = rtrim($kanal['Entegrasyonlar_BaseURL'], '/');
    $hesapYolu  = trim($kanal['EntegrasyonKanallari_Instance'], '/');
    $loginUrl   = "{$baseUrl}/{$hesapYolu}/account.php";
    $accountUrl = "{$baseUrl}/{$hesapYolu}/accounts.php";
    $postUrl    = "{$baseUrl}/main.php";
    $cookieFile = sys_get_temp_dir() . '/sippy_' . md5($kanal['EntegrasyonKanallari_id']) . '.txt';
    $kanalId    = (int)$kanal['EntegrasyonKanallari_id'];

    if (file_exists($cookieFile)) unlink($cookieFile);

    // Login
    sippyCurl($loginUrl, [], $cookieFile);
    $login = sippyCurl($postUrl, [
        'acct_type' => 'customer',
        'login_page' => '',
        'username'  => $kanal['EntegrasyonKanallari_Kullanici'],
        'password'  => $kanal['EntegrasyonKanallari_Sifre'],
        'Login'     => 'Login',
    ], $cookieFile, $loginUrl, false);

    $loc = $login['location'];
    $loginOk = ($login['code'] >= 301 && $login['code'] <= 302
        && strpos($loc, 'index.php') === false
        && strpos($loc, 'account.php') === false
        && $loc !== '');

    if (!$loginOk) {
        return ['success' => false, 'message' => "Login başarısız: {$kanal['EntegrasyonKanallari_KanalAdi']}"];
    }

    // Session'ı oturuma bağla
    $afterUrl = str_starts_with($loc, 'http') ? $loc : "{$baseUrl}/" . ltrim($loc, '/');
    sippyCurl($afterUrl, [], $cookieFile, $postUrl);

    // Accounts sayfasını çek
    $resp = sippyCurl($accountUrl, [], $cookieFile, $afterUrl);
    if ($resp['final'] !== $accountUrl) {
        return ['success' => false, 'message' => "Hesaplar sayfası alınamadı: {$kanal['EntegrasyonKanallari_KanalAdi']}"];
    }

    // Tablo parse: en fazla satırlı tabloyu seç
    preg_match_all('/<table[^>]*>(.*?)<\/table>/is', $resp['body'], $tblM);
    $hesapTabloHtml = '';
    $enCok = 0;
    foreach ($tblM[1] as $tbl) {
        preg_match_all('/<tr[^>]*>/i', $tbl, $trm);
        if (count($trm[0]) > $enCok && count($trm[0]) >= 5) {
            $enCok = count($trm[0]);
            $hesapTabloHtml = $tbl;
        }
    }

    $hesaplar = [];
    if ($hesapTabloHtml) {
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $hesapTabloHtml, $rowM);
        foreach ($rowM[1] as $rIdx => $row) {
            if ($rIdx === 0) continue;
            preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $row, $cellM);
            $ham  = $cellM[1];
            $hcr  = array_map(fn($c) => trim(strip_tags(html_entity_decode($c))), $ham);
            if (empty(array_filter($hcr))) continue;

            if (count($hcr) >= 7) {
                $durumHam = $ham[0] ?? '';
                $telefon  = preg_replace('/\D/', '', $hcr[1]);
                $aciklama = $hcr[2];
            } else {
                $durumHam = '';
                $telefon  = preg_replace('/\D/', '', $hcr[0]);
                $aciklama = $hcr[1] ?? '';
            }

            if (strlen($telefon) < 10) continue;

            if (stripos($durumHam, 'block') !== false || stripos($durumHam, 'blok') !== false) {
                $durum = 'bloke';
            } elseif (stripos($aciklama, 'BLOKE') !== false) {
                $durum = 'bloke';
            } elseif (stripos($durumHam, 'disabled') !== false || stripos($durumHam, 'inactive') !== false) {
                $durum = 'pasif';
            } else {
                $durum = 'aktif';
            }

            $hesaplar[] = ['telefon' => $telefon, 'aciklama' => $aciklama ?: null, 'durum' => $durum];
        }
    }

    // Upsert
    $simdi   = date('Y-m-d H:i:s');
    $eklenen = $guncellenen = 0;

    foreach ($hesaplar as $h) {
        $mevcut = $db->fetchOne(
            "SELECT VoIPHesaplar_id FROM VoIPHesaplar WHERE VoIPHesaplar_Kanal_id = ? AND VoIPHesaplar_TelefonNo = ?",
            [$kanalId, $h['telefon']]
        );
        if ($mevcut) {
            $db->query(
                "UPDATE VoIPHesaplar SET VoIPHesaplar_Aciklama=?, VoIPHesaplar_HesapDurum=?,
                 VoIPHesaplar_SonSenkTarihi=?, GuncelleyenKullanici=?, GuncellemeTarihi=?
                 WHERE VoIPHesaplar_id=?",
                [$h['aciklama'], $h['durum'], $simdi, $kanalId, $simdi, $mevcut['VoIPHesaplar_id']]
            );
            $guncellenen++;
        } else {
            $db->query(
                "INSERT INTO VoIPHesaplar (VoIPHesaplar_Kanal_id, VoIPHesaplar_TelefonNo,
                 VoIPHesaplar_Aciklama, VoIPHesaplar_HesapDurum, VoIPHesaplar_SonSenkTarihi,
                 OlusturanKullanici, OlusturmaTarihi, GuncelleyenKullanici, GuncellemeTarihi, Durum)
                 VALUES (?,?,?,?,?,1,?,1,?,1)",
                [$kanalId, $h['telefon'], $h['aciklama'], $h['durum'], $simdi, $simdi, $simdi]
            );
            $eklenen++;
        }
    }

    if (file_exists($cookieFile)) unlink($cookieFile);

    return ['success' => true, 'eklenen' => $eklenen, 'guncellenen' => $guncellenen, 'toplam' => count($hesaplar)];
}

$user = Auth::user();
$db   = Database::getInstance();

$currentPagefile = basename($_SERVER['PHP_SELF']);
$pageinfo = $db->fetchOne("
    SELECT s.sayfalar_sayfa_adi, s.sayfalar_aciklama, m.menuler_menu_adi as menu_adi
    FROM Menu_Sayfalar s
    LEFT JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
    WHERE s.sayfalar_sayfa_url LIKE ? AND s.sayfalar_durum = 1
", ['%' . $currentPagefile]);

$pageTitle       = $pageinfo['sayfalar_sayfa_adi'] ?? 'VoIP Hesapları';
$pageDescription = $pageinfo['sayfalar_aciklama'] ?? '';
$menuAdi         = $pageinfo['menu_adi'] ?? null;

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

// ─── Birim bazlı veri kısıtı (birim_gor) — KullaniciBirimYetkileri junction üzerinden ─────
// VoIPHesaplar → birim (KullaniciBirimYetkileri_VoIPHesap_id) doğrudan bağlanır.
$birimKisitli   = (!$permissions['is_admin'] && !empty($permissions['can_view_birim']));
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
}

/**
 * Birim kısıt EXISTS parçası — VoIPHesaplar → birim junction (doğrudan VoIPHesap_id).
 * @return [sql, params]
 */
function voipBirimKisitWhere(array $izinliBirimler): array {
    $ph  = implode(',', array_fill(0, count($izinliBirimler), '?'));
    $sql = "EXISTS (
        SELECT 1 FROM KullaniciBirimYetkileri kby
        WHERE kby.KullaniciBirimYetkileri_VoIPHesap_id = v.VoIPHesaplar_id
          AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
          AND kby.Durum = 1
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
    )";
    return [$sql, $izinliBirimler];
}

// ─── AJAX ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            case 'hesap_listele':
                $search   = $_POST['search']   ?? '';
                $kanalId  = $_POST['kanal_id'] ?? '';
                $durum    = $_POST['durum']    ?? '';
                $birimId  = $_POST['birim_id'] ?? '';

                $where  = ['1=1'];
                $params = [];

                if ($search) {
                    $where[]  = "(v.VoIPHesaplar_TelefonNo LIKE ? OR v.VoIPHesaplar_Aciklama LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($kanalId !== '') {
                    $where[]  = "v.VoIPHesaplar_Kanal_id = ?";
                    $params[] = (int)$kanalId;
                }
                if ($durum !== '') {
                    $where[]  = "v.VoIPHesaplar_HesapDurum = ?";
                    $params[] = $durum;
                }
                if ($birimId !== '') {
                    $where[]  = "EXISTS (SELECT 1 FROM KullaniciBirimYetkileri kbf
                                         WHERE kbf.KullaniciBirimYetkileri_VoIPHesap_id = v.VoIPHesaplar_id
                                           AND kbf.KullaniciBirimYetkileri_Birim_id = ? AND kbf.Durum = 1
                                           AND (kbf.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kbf.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
                                           AND (kbf.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kbf.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE()))";
                    $params[] = (int)$birimId;
                }

                // Birim bazlı veri kısıtı (yalnız izinli birimlerin VoIP hesapları)
                if ($birimKisitli) {
                    if (empty($izinliBirimler)) { echo json_encode(['success' => true, 'data' => []]); break; }
                    [$wK, $pK] = voipBirimKisitWhere($izinliBirimler);
                    $where[] = $wK;
                    $params  = array_merge($params, $pK);
                }

                $liste = $db->fetchAll("
                    SELECT
                        v.VoIPHesaplar_id,
                        v.VoIPHesaplar_TelefonNo,
                        v.VoIPHesaplar_Aciklama,
                        v.VoIPHesaplar_HesapDurum,
                        v.Durum,
                        CONVERT(VARCHAR(19), v.VoIPHesaplar_SonSenkTarihi, 120) as SonSenkTarihi,
                        k.EntegrasyonKanallari_KanalAdi,
                        e.Entegrasyonlar_Adi as OperatorAdi,
                        (SELECT STRING_AGG(kb.KullaniciBirim_Adi, ', ')
                         FROM KullaniciBirimYetkileri kby
                         JOIN KullaniciBirim kb ON kby.KullaniciBirimYetkileri_Birim_id = kb.KullaniciBirim_id
                         WHERE kby.KullaniciBirimYetkileri_VoIPHesap_id = v.VoIPHesaplar_id AND kby.Durum = 1
                           AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
                           AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())) AS Birimler
                    FROM VoIPHesaplar v
                    INNER JOIN EntegrasyonKanallari k ON v.VoIPHesaplar_Kanal_id = k.EntegrasyonKanallari_id
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY e.Entegrasyonlar_Adi, v.VoIPHesaplar_TelefonNo
                ", $params);

                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            case 'stats': {
                $bk = ''; $bp = [];
                if ($birimKisitli) {
                    if (empty($izinliBirimler)) {
                        echo json_encode(['success' => true, 'data' => ['toplam'=>0,'aktif'=>0,'bloke'=>0,'pasif'=>0,'son_senk'=>null]]);
                        break;
                    }
                    [$wK, $pK] = voipBirimKisitWhere($izinliBirimler);
                    $bk = ' AND ' . $wK;
                    $bp = $pK;
                }
                $stats = [
                    'toplam' => $db->fetchOne("SELECT COUNT(*) as c FROM VoIPHesaplar v WHERE v.Durum = 1$bk", $bp)['c'] ?? 0,
                    'aktif'  => $db->fetchOne("SELECT COUNT(*) as c FROM VoIPHesaplar v WHERE v.VoIPHesaplar_HesapDurum = 'aktif' AND v.Durum = 1$bk", $bp)['c'] ?? 0,
                    'bloke'  => $db->fetchOne("SELECT COUNT(*) as c FROM VoIPHesaplar v WHERE v.VoIPHesaplar_HesapDurum = 'bloke' AND v.Durum = 1$bk", $bp)['c'] ?? 0,
                    'pasif'  => $db->fetchOne("SELECT COUNT(*) as c FROM VoIPHesaplar v WHERE v.VoIPHesaplar_HesapDurum NOT IN ('aktif','bloke') AND v.Durum = 1$bk", $bp)['c'] ?? 0,
                    'son_senk' => $db->fetchOne("SELECT CONVERT(VARCHAR(19), MAX(v.VoIPHesaplar_SonSenkTarihi), 120) as t FROM VoIPHesaplar v WHERE 1=1$bk", $bp)['t'] ?? null,
                ];
                echo json_encode(['success' => true, 'data' => $stats]);
                break;
            }

            case 'sync_voip':
                if (!$permissions['can_edit']) {
                    echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']);
                    break;
                }
                $kanallar = $db->fetchAll("
                    SELECT k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_KanalAdi,
                           k.EntegrasyonKanallari_Host, k.EntegrasyonKanallari_Instance,
                           k.EntegrasyonKanallari_Kullanici, k.EntegrasyonKanallari_Sifre,
                           e.Entegrasyonlar_BaseURL
                    FROM EntegrasyonKanallari k
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE e.Entegrasyonlar_Tip = 'voip' AND k.Durum = 1 AND e.Durum = 1
                      AND ISNULL(k.EntegrasyonKanallari_Kullanici, '') <> '' AND ISNULL(k.EntegrasyonKanallari_Instance, '') <> ''
                ");

                if (!$kanallar) {
                    echo json_encode(['success' => false, 'message' => 'Aktif VoIP kanalı bulunamadı.']);
                    break;
                }

                $sonuclar = [];
                $toplamEklenen = $toplamGuncellenen = 0;

                foreach ($kanallar as $k) {
                    $sonuc = sippySync($k, $db);
                    $sonuclar[] = array_merge($sonuc, ['kanal' => $k['EntegrasyonKanallari_KanalAdi']]);
                    if ($sonuc['success']) {
                        $toplamEklenen     += $sonuc['eklenen'];
                        $toplamGuncellenen += $sonuc['guncellenen'];
                    }
                }

                echo json_encode([
                    'success'     => true,
                    'eklenen'     => $toplamEklenen,
                    'guncellenen' => $toplamGuncellenen,
                    'detay'       => $sonuclar,
                ]);
                break;

            case 'kanal_select':
                $liste = $db->fetchAll("
                    SELECT DISTINCT k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_KanalAdi, e.Entegrasyonlar_Adi
                    FROM VoIPHesaplar v
                    INNER JOIN EntegrasyonKanallari k ON v.VoIPHesaplar_Kanal_id = k.EntegrasyonKanallari_id
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    ORDER BY e.Entegrasyonlar_Adi, k.EntegrasyonKanallari_KanalAdi
                ");
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            case 'birim_select':
                if ($birimKisitli) {
                    if (empty($izinliBirimler)) { echo json_encode(['success' => true, 'data' => []]); break; }
                    $ph    = implode(',', array_fill(0, count($izinliBirimler), '?'));
                    $liste = $db->fetchAll("SELECT KullaniciBirim_id, KullaniciBirim_Adi FROM KullaniciBirim WHERE Durum=1 AND KullaniciBirim_id IN ($ph) ORDER BY KullaniciBirim_Adi", $izinliBirimler);
                } else {
                    $liste = $db->fetchAll("SELECT KullaniciBirim_id, KullaniciBirim_Adi FROM KullaniciBirim WHERE Durum=1 ORDER BY KullaniciBirim_Adi");
                }
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            case 'teslim_et':
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']); break; }
                $hesapId = (int)($_POST['hesap_id'] ?? 0);
                $birimId = (int)($_POST['birim_id'] ?? 0);
                $tarih   = $_POST['tarih'] ?? '';
                if (!$hesapId || !$birimId || $tarih === '') {
                    echo json_encode(['success' => false, 'message' => 'Hesap, birim ve teslim tarihi zorunludur.']); break;
                }
                // Bloke hesaba işlem yapılamaz
                $hd = $db->fetchOne("SELECT VoIPHesaplar_HesapDurum FROM VoIPHesaplar WHERE VoIPHesaplar_id = ?", [$hesapId]);
                if (strtolower((string)($hd['VoIPHesaplar_HesapDurum'] ?? '')) === 'bloke') {
                    echo json_encode(['success' => false, 'message' => 'Bloke hesaba işlem yapılamaz.']); break;
                }
                // Çift teslim engeli: hesabın geçerli (aktif) bir teslim kaydı varsa önce iade edilmelidir
                $aktif = $db->fetchOne("
                    SELECT COUNT(*) AS c FROM KullaniciBirimYetkileri
                    WHERE KullaniciBirimYetkileri_VoIPHesap_id = ? AND Durum = 1
                      AND (KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
                      AND (KullaniciBirimYetkileri_BitisTarihi     IS NULL OR KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
                ", [$hesapId]);
                if ((int)($aktif['c'] ?? 0) > 0) {
                    echo json_encode(['success' => false, 'message' => 'Bu hesap zaten bir birime teslim edilmiş. Önce iade alın.']); break;
                }
                $now = date('Y-m-d H:i:s');
                $ok = $db->insert('KullaniciBirimYetkileri', [
                    'KullaniciBirimYetkileri_Birim_id'        => $birimId,
                    'KullaniciBirimYetkileri_VoIPHesap_id'    => $hesapId,
                    'KullaniciBirimYetkileri_BaslangicTarihi' => $tarih,
                    'KullaniciBirimYetkileri_BitisTarihi'     => null,
                    'Durum'                                   => 1,
                    'OlusturanKullanici'                      => $user['kullanici_id'],
                    'OlusturmaTarihi'                         => $now,
                    'GuncelleyenKullanici'                    => $user['kullanici_id'],
                    'GuncellemeTarihi'                        => $now,
                ]);
                echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Hesap birime teslim edildi.' : 'Teslim kaydı oluşturulamadı.']);
                break;

            case 'iade_al':
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Yetkiniz yok.']); break; }
                $hesapId = (int)($_POST['hesap_id'] ?? 0);
                $tarih   = $_POST['tarih'] ?? '';
                if (!$hesapId || $tarih === '') {
                    echo json_encode(['success' => false, 'message' => 'Hesap ve iade tarihi zorunludur.']); break;
                }
                // Bloke hesaba işlem yapılamaz
                $hd = $db->fetchOne("SELECT VoIPHesaplar_HesapDurum FROM VoIPHesaplar WHERE VoIPHesaplar_id = ?", [$hesapId]);
                if (strtolower((string)($hd['VoIPHesaplar_HesapDurum'] ?? '')) === 'bloke') {
                    echo json_encode(['success' => false, 'message' => 'Bloke hesaba işlem yapılamaz.']); break;
                }
                // Hesabın geçerli (aktif) teslim kayıtlarını bul
                $kayitlar = $db->fetchAll("
                    SELECT KullaniciBirimYetkileri_id FROM KullaniciBirimYetkileri
                    WHERE KullaniciBirimYetkileri_VoIPHesap_id = ? AND Durum = 1
                      AND (KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
                      AND (KullaniciBirimYetkileri_BitisTarihi     IS NULL OR KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
                ", [$hesapId]);
                if (!$kayitlar) {
                    echo json_encode(['success' => false, 'message' => 'Bu hesabın aktif teslim kaydı bulunamadı.']); break;
                }
                $now   = date('Y-m-d H:i:s');
                $durum = ($tarih <= date('Y-m-d')) ? 0 : 1; // iade tarihi bugün/geçmişse pasif, ileri tarihliyse o güne dek aktif
                foreach ($kayitlar as $k) {
                    $db->update('KullaniciBirimYetkileri', [
                        'KullaniciBirimYetkileri_BitisTarihi' => $tarih,
                        'Durum'                               => $durum,
                        'GuncelleyenKullanici'                => $user['kullanici_id'],
                        'GuncellemeTarihi'                    => $now,
                    ], ['KullaniciBirimYetkileri_id' => $k['KullaniciBirimYetkileri_id']]);
                }
                echo json_encode(['success' => true, 'message' => 'Hesap iade alındı.']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/Admin/assets/css/custom.css">

    <style>
        .badge-aktif { background-color: #d4edda; color: #155724; }
        .badge-bloke { background-color: #f8d7da; color: #721c24; }
        .badge-pasif { background-color: #fff3cd; color: #856404; }
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
                    <div class="col-md-3">
                        <div class="info-box text-bg-primary">
                            <span class="info-box-icon"><i class="bi bi-telephone"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Toplam Hesap</span>
                                <span class="info-box-number" id="stat-toplam">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-success">
                            <span class="info-box-icon"><i class="bi bi-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Aktif</span>
                                <span class="info-box-number" id="stat-aktif">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-danger">
                            <span class="info-box-icon"><i class="bi bi-slash-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Bloke</span>
                                <span class="info-box-number" id="stat-bloke">0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box text-bg-warning">
                            <span class="info-box-icon"><i class="bi bi-pause-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Pasif / Diğer</span>
                                <span class="info-box-number" id="stat-pasif">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Son Senkronizasyon -->
                <div class="alert alert-secondary py-2 mb-3" id="son-senk-bilgi" style="display:none">
                    <i class="bi bi-arrow-repeat"></i> Son senkronizasyon: <strong id="son-senk-tarih"></strong>
                </div>

                <!-- Filtre -->
                <div class="card card-primary card-outline mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                        <div class="card-tools">
                            <button class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#filterPanel">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body collapse" id="filterPanel">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Ara</label>
                                <input type="text" class="form-control" id="f_search" placeholder="Telefon no veya açıklama...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Operatör / Kanal</label>
                                <select class="form-select select2-basic" id="f_kanal">
                                    <option value="">Tümü</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Hesap Durumu</label>
                                <select class="form-select select2-basic" id="f_durum">
                                    <option value="">Tümü</option>
                                    <option value="aktif">Aktif</option>
                                    <option value="bloke">Bloke</option>
                                    <option value="pasif">Pasif</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Birim</label>
                                <select class="form-select select2-basic" id="f_birim">
                                    <option value="">Tümü</option>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <button class="btn btn-primary" onclick="hesapListele()"><i class="bi bi-search"></i> Filtrele</button>
                                <button class="btn btn-secondary" onclick="filtreTemizle()"><i class="bi bi-x-circle"></i> Temizle</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tablo -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Hesap Listesi</h3>
                        <div class="card-tools"></div>
                    </div>
                    <div class="card-body">
                        <table id="tblHesaplar" class="table table-bordered table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Operatör</th>
                                    <th>Kanal</th>
                                    <th>Telefon No</th>
                                    <th>Açıklama</th>
                                    <th>Birim</th>
                                    <th>Hesap Durumu</th>
                                    <th>Son Senkronizasyon</th>
                                    <th class="text-end" style="width:180px">İşlemler</th>
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

<!-- Teslim Et Modal -->
<div class="modal fade" id="teslimModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-box-arrow-in-down"></i> Hesabı Birime Teslim Et</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="teslim_hesap_id">
                <p class="text-muted small mb-3" id="teslim_hesap_bilgi"></p>
                <div class="mb-3">
                    <label class="form-label">Birim <span class="text-danger">*</span></label>
                    <select class="form-select" id="teslim_birim">
                        <option value="">— Birim seçin —</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label">Teslim Tarihi <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="teslim_tarih">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Vazgeç</button>
                <button type="button" class="btn btn-success" id="btnTeslimKaydet"><i class="bi bi-check-circle"></i> Teslim Et</button>
            </div>
        </div>
    </div>
</div>

<!-- İade Al Modal -->
<div class="modal fade" id="iadeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="bi bi-box-arrow-up"></i> Hesabı İade Al</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="iade_hesap_id">
                <p class="text-muted small mb-3" id="iade_hesap_bilgi"></p>
                <div class="mb-2">
                    <label class="form-label">İade Tarihi <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="iade_tarih">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Vazgeç</button>
                <button type="button" class="btn btn-warning" id="btnIadeKaydet"><i class="bi bi-check-circle"></i> İade Al</button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script>
// Sidebar kapalı durumunu hatırla (bu sayfa custom.js yüklemiyor)
(function () {
    var KEY = 'sidebarCollapsed', BP = 992;
    function uygula() {
        if (window.innerWidth > BP && localStorage.getItem(KEY) === '1') {
            document.body.classList.add('sidebar-collapse');
            document.body.classList.remove('sidebar-open');
        }
    }
    function init() {
        try { uygula(); } catch (e) {}
        var rzt;
        window.addEventListener('resize', function () {
            clearTimeout(rzt);
            rzt = setTimeout(function () { try { uygula(); } catch (e) {} }, 60);
        });
        document.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('[data-lte-toggle="sidebar"]')) {
                try { localStorage.setItem(KEY, document.body.classList.contains('sidebar-collapse') ? '1' : '0'); } catch (er) {}
            }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const pageUrl = '<?= $_SERVER['PHP_SELF'] ?>';
const canEdit = <?= $permissions['can_edit'] ? 'true' : 'false' ?>;
let dtHesaplar;
let birimListesi = [];
let teslimModal, iadeModal;

$(document).ready(function () {
    $('.select2-basic').select2({ theme: 'bootstrap-5', width: '100%' });
    teslimModal = new bootstrap.Modal(document.getElementById('teslimModal'));
    iadeModal   = new bootstrap.Modal(document.getElementById('iadeModal'));
    $('#btnTeslimKaydet').on('click', teslimKaydet);
    $('#btnIadeKaydet').on('click', iadeKaydet);

    statsYukle();
    kanalSelectDoldur();
    birimSelectDoldur();
    hesapListele();
});

function statsYukle() {
    $.post(pageUrl, { action: 'stats' }, function (r) {
        if (!r.success) return;
        $('#stat-toplam').text(r.data.toplam);
        $('#stat-aktif').text(r.data.aktif);
        $('#stat-bloke').text(r.data.bloke);
        $('#stat-pasif').text(r.data.pasif);
        if (r.data.son_senk) {
            $('#son-senk-tarih').text(r.data.son_senk);
            $('#son-senk-bilgi').show();
        }
    });
}

function kanalSelectDoldur() {
    $.post(pageUrl, { action: 'kanal_select' }, function (r) {
        if (!r.success) return;
        const $sel = $('#f_kanal');
        $sel.find('option:not(:first)').remove();
        r.data.forEach(function (k) {
            $sel.append(`<option value="${k.EntegrasyonKanallari_id}">${htmlEncode(k.Entegrasyonlar_Adi)} — ${htmlEncode(k.EntegrasyonKanallari_KanalAdi)}</option>`);
        });
        $sel.trigger('change');
    });
}

function birimSelectDoldur() {
    $.post(pageUrl, { action: 'birim_select' }, function (r) {
        if (!r.success) return;
        birimListesi = r.data || [];
        const $sel = $('#f_birim');
        $sel.find('option:not(:first)').remove();
        birimListesi.forEach(function (b) {
            $sel.append(`<option value="${b.KullaniciBirim_id}">${htmlEncode(b.KullaniciBirim_Adi)}</option>`);
        });
        $sel.trigger('change');
    });
}

function birimBadges(str) {
    if (!str) return '<span class="text-muted">-</span>';
    return String(str).split(', ').map(b => `<span class="badge text-bg-light border me-1">${htmlEncode(b)}</span>`).join('');
}

function hesapListele() {
    const data = {
        action   : 'hesap_listele',
        search   : $('#f_search').val(),
        kanal_id : $('#f_kanal').val(),
        durum    : $('#f_durum').val(),
        birim_id : $('#f_birim').val(),
    };

    $.post(pageUrl, data, function (r) {
        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }

        if (dtHesaplar) dtHesaplar.destroy();
        const $tbody = $('#tblHesaplar tbody').empty();

        r.data.forEach(function (v, idx) {
            $tbody.append(`<tr>
                <td>${idx + 1}</td>
                <td><small>${htmlEncode(v.OperatorAdi)}</small></td>
                <td><small>${htmlEncode(v.EntegrasyonKanallari_KanalAdi)}</small></td>
                <td><strong>${htmlEncode(v.VoIPHesaplar_TelefonNo)}</strong></td>
                <td><small>${htmlEncode(v.VoIPHesaplar_Aciklama || '-')}</small></td>
                <td>${birimBadges(v.Birimler)}</td>
                <td>${durumBadge(v.VoIPHesaplar_HesapDurum)}</td>
                <td><small>${v.SonSenkTarihi || '-'}</small></td>
                <td class="text-end">${islemlerCell(v)}</td>
            </tr>`);
        });

        dtHesaplar = $('#tblHesaplar').DataTable({
            language : { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            order    : [[3, 'asc']],
            pageLength: 25,
            destroy  : true,
            columnDefs: [{ targets: -1, orderable: false }],
        });
    });
}

function islemlerCell(v) {
    if (!canEdit) return '<span class="text-muted">-</span>';
    // Bloke hesaplarda hiçbir işlem yapılamaz
    if (String(v.VoIPHesaplar_HesapDurum).toLowerCase() === 'bloke') {
        return '<span class="text-muted small"><i class="bi bi-slash-circle"></i> Bloke</span>';
    }
    const id  = parseInt(v.VoIPHesaplar_id);
    const tel = htmlEncode(v.VoIPHesaplar_TelefonNo);
    if (v.Birimler) {
        return `<button class="btn btn-sm btn-warning" onclick="iadeAc(${id}, '${tel}')"><i class="bi bi-box-arrow-up"></i> İade Al</button>`;
    }
    return `<button class="btn btn-sm btn-success" onclick="teslimAc(${id}, '${tel}')"><i class="bi bi-box-arrow-in-down"></i> Teslim Et</button>`;
}

function bugunStr() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

// ===== Teslim Et =====
function teslimAc(id, tel) {
    $('#teslim_hesap_id').val(id);
    $('#teslim_hesap_bilgi').html('<i class="bi bi-telephone"></i> <strong>' + tel + '</strong> numaralı hesap birime teslim edilecek.');
    $('#teslim_tarih').val(bugunStr());

    const $sel = $('#teslim_birim');
    if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
    $sel.find('option:not(:first)').remove();
    birimListesi.forEach(function (b) {
        $sel.append(`<option value="${b.KullaniciBirim_id}">${htmlEncode(b.KullaniciBirim_Adi)}</option>`);
    });
    $sel.val('');
    $sel.select2({ theme: 'bootstrap-5', width: '100%', placeholder: '— Birim seçin —', dropdownParent: $('#teslimModal') });

    teslimModal.show();
}

function teslimKaydet() {
    const hesapId = $('#teslim_hesap_id').val();
    const birimId = $('#teslim_birim').val();
    const tarih   = $('#teslim_tarih').val();
    if (!birimId) { Swal.fire('Uyarı', 'Lütfen bir birim seçin.', 'warning'); return; }
    if (!tarih)   { Swal.fire('Uyarı', 'Lütfen teslim tarihi seçin.', 'warning'); return; }

    const $btn = $('#btnTeslimKaydet').prop('disabled', true);
    $.post(pageUrl, { action: 'teslim_et', hesap_id: hesapId, birim_id: birimId, tarih: tarih }, function (r) {
        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }
        teslimModal.hide();
        Swal.fire('Başarılı', r.message, 'success');
        statsYukle();
        hesapListele();
    }).fail(function () {
        Swal.fire('Hata', 'Sunucuya bağlanılamadı.', 'error');
    }).always(function () {
        $btn.prop('disabled', false);
    });
}

// ===== İade Al =====
function iadeAc(id, tel) {
    $('#iade_hesap_id').val(id);
    $('#iade_hesap_bilgi').html('<i class="bi bi-telephone"></i> <strong>' + tel + '</strong> numaralı hesap birimden iade alınacak.');
    $('#iade_tarih').val(bugunStr());
    iadeModal.show();
}

function iadeKaydet() {
    const hesapId = $('#iade_hesap_id').val();
    const tarih   = $('#iade_tarih').val();
    if (!tarih) { Swal.fire('Uyarı', 'Lütfen iade tarihi seçin.', 'warning'); return; }

    const $btn = $('#btnIadeKaydet').prop('disabled', true);
    $.post(pageUrl, { action: 'iade_al', hesap_id: hesapId, tarih: tarih }, function (r) {
        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }
        iadeModal.hide();
        Swal.fire('Başarılı', r.message, 'success');
        statsYukle();
        hesapListele();
    }).fail(function () {
        Swal.fire('Hata', 'Sunucuya bağlanılamadı.', 'error');
    }).always(function () {
        $btn.prop('disabled', false);
    });
}

function filtreTemizle() {
    $('#f_search').val('');
    $('#f_kanal, #f_durum, #f_birim').val('').trigger('change');
    hesapListele();
}

function durumBadge(durum) {
    const cls = { aktif: 'badge-aktif', bloke: 'badge-bloke', pasif: 'badge-pasif' };
    const lbl = { aktif: 'Aktif', bloke: 'Bloke', pasif: 'Pasif' };
    const c = cls[durum] || 'badge-pasif';
    const l = lbl[durum] || durum;
    return `<span class="badge ${c}">${htmlEncode(l)}</span>`;
}


function htmlEncode(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

</body>
</html>
