<?php
/**
 * Admin Panel - Reklam Yönetimi
 * Platform → Hesap → Kampanya → Facebook Sayfası → Lead Formu hiyerarşisi
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/PageAuth.php';
require_once __DIR__ . '/../includes/MetaReklamSync.php';

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

$pageTitle       = $pageinfo['sayfalar_sayfa_adi'] ?? 'Reklam Yönetimi';
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

// ─── Birim bazlı veri kısıtı (Facebook Sayfaları) ───────────────────────────
// Kısıtlı kullanıcı = admin değil + birim_gor=1. Yalnız kendi birimi + alt birimlerine
// junction (KullaniciBirimYetkileri_ReklamSayfa_id) ile atanmış sayfaları görür/düzenler.
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
    // kullanici_birim_id boşsa $izinliBirimler boş → hiçbir sayfa görünmez (güvenli varsayılan)
}

/**
 * Facebook sayfası birim kısıt WHERE parçası (EXISTS). Aktif junction: Durum=1 + tarih aralığı.
 * @return array [sqlFragment, params]
 */
function sayfaBirimKisitWhere(array $izinliBirimler, string $sayfaIdExpr): array {
    $ph = implode(',', array_fill(0, count($izinliBirimler), '?'));
    $sql = "EXISTS (
        SELECT 1 FROM KullaniciBirimYetkileri kby
        WHERE kby.KullaniciBirimYetkileri_ReklamSayfa_id = $sayfaIdExpr
          AND kby.KullaniciBirimYetkileri_Birim_id IN ($ph)
          AND kby.Durum = 1
          AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
          AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
    )";
    return [$sql, $izinliBirimler];
}

/** Tekil sayfanın kullanıcı birim kapsamında olup olmadığı (getir/kaydet-edit/sil/webhook). */
function sayfaKayitBirimYetkiliMi($db, array $izinliBirimler, int $sayfaId): bool {
    if (empty($izinliBirimler) || $sayfaId <= 0) return false;
    [$w, $p] = sayfaBirimKisitWhere($izinliBirimler, '?');
    $row = $db->fetchOne(
        "SELECT TOP 1 1 AS v FROM ReklamFacebookSayfalari WHERE ReklamFacebookSayfalari_id = ? AND $w",
        array_merge([$sayfaId, $sayfaId], $p)
    );
    return (bool)$row;
}

// ─── Tablo metadataları (generic CRUD için) ─────────────────────────────────
$RT = [
    'platform' => [
        't'    => 'ReklamPlatformlari',
        'pk'   => 'ReklamPlatformlari_id',
        'cols' => [
            'ReklamPlatformlari_Adi'        => 's',
            'ReklamPlatformlari_Simge'      => 's',
            'ReklamPlatformlari_Varsayilan' => 'b',
        ],
    ],
    'hesap' => [
        't'    => 'ReklamHesaplari',
        'pk'   => 'ReklamHesaplari_id',
        'cols' => [
            'ReklamHesaplari_Platform_id' => 'i',
            'ReklamHesaplari_HesapAdi'    => 's',
            'ReklamHesaplari_HesapID'     => 's',
            'ReklamHesaplari_TelefonNo'   => 's',
            'ReklamHesaplari_HesapDurumu' => 'i',
        ],
    ],
    'kampanya' => [
        't'    => 'ReklamKampanyalari',
        'pk'   => 'ReklamKampanyalari_id',
        'cols' => [
            'ReklamKampanyalari_Hesap_id'       => 'i',
            'ReklamKampanyalari_KampanyaAdi'    => 's',
            'ReklamKampanyalari_KampanyaID'     => 's',
            'ReklamKampanyalari_Kitle'          => 's',
            'ReklamKampanyalari_KampanyaDurumu' => 's',
        ],
    ],
    'sayfa' => [
        't'    => 'ReklamFacebookSayfalari',
        'pk'   => 'ReklamFacebookSayfalari_id',
        'cols' => [
            'ReklamFacebookSayfalari_Kampanya_id' => 'i',
            'ReklamFacebookSayfalari_SayfaAdi'    => 's',
            'ReklamFacebookSayfalari_SayfaID'     => 's',
        ],
    ],
    'form' => [
        't'    => 'ReklamLeadFormlari',
        'pk'   => 'ReklamLeadFormlari_id',
        'cols' => [
            'ReklamLeadFormlari_Sayfa_id'   => 'i',
            'ReklamLeadFormlari_FormAdi'    => 's',
            'ReklamLeadFormlari_FormID'     => 's',
            'ReklamLeadFormlari_FormDurumu' => 's',
        ],
    ],
];

/** POST'tan gelen değeri tipe göre normalize eder. */
function rtDeger($tip, $ham)
{
    if ($tip === 'i') return ($ham === '' || $ham === null) ? null : (int)$ham;
    if ($tip === 'b') return !empty($ham) ? 1 : 0;
    $s = trim((string)$ham);
    return $s === '' ? null : $s;
}

// ─── Meta bağlantı testi yardımcıları (temp/meta-test.php'den taşındı) ───────────
function mtProof($token, $secret) { return hash_hmac('sha256', $token, $secret); }

function mtGraphGet($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $cevap = curl_exec($ch);
    $kod   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hata  = curl_error($ch);
    $json  = json_decode($cevap, true) ?: [];
    return ['ok' => ($kod >= 200 && $kod < 300 && !$hata), 'data' => $json, 'kod' => $kod, 'hata' => $hata];
}

/** paging.next dahil tümünü toplar */
function mtGraphAll($baseURL, $path, $params, $token, $secret) {
    $params['access_token']    = $token;
    $params['appsecret_proof'] = mtProof($token, $secret);
    $params['limit']           = 200;
    $url = $baseURL . '/' . ltrim($path, '/') . '?' . http_build_query($params);

    $tum = []; $guv = 0; $err = null;
    while ($url && $guv < 50) {
        $r = mtGraphGet($url);
        if (!$r['ok']) { $err = $r['data']['error']['message'] ?? ('HTTP ' . $r['kod']); break; }
        foreach (($r['data']['data'] ?? []) as $s) $tum[] = $s;
        $url = $r['data']['paging']['next'] ?? null;
        $guv++;
    }
    return ['rows' => $tum, 'err' => $err];
}

function mtMaske($t) {
    $n = strlen($t);
    if ($n <= 10) return str_repeat('*', $n);
    return substr($t, 0, 6) . str_repeat('*', max(0, $n - 12)) . substr($t, -6);
}

/** Meta account_status kodu → ad. Meta API sabiti olduğu için DB'de tutulmaz. */
const MT_HESAP_DURUMLARI = [
    1   => 'ACTIVE',            2   => 'DISABLED',
    3   => 'UNSETTLED',         7   => 'PENDING_RISK_REVIEW',
    8   => 'PENDING_SETTLEMENT', 9  => 'IN_GRACE_PERIOD',
    100 => 'PENDING_CLOSURE',   101 => 'CLOSED',
    201 => 'ANY_ACTIVE',        202 => 'ANY_CLOSED',
];
function mtDurumAdi($st) { return MT_HESAP_DURUMLARI[(int)$st] ?? (string)$st; }

/** Platform adı Meta ailesinden mi (Facebook/Instagram kampanyaları). */
function mtPlatformMetaMi($ad) { return stripos((string)$ad, 'meta') !== false; }

/** mtGraphGet sonucundan okunabilir hata metni. */
function mtHataMesaji(array $r) {
    return $r['data']['error']['message']
        ?? ('HTTP ' . $r['kod'] . (!empty($r['hata']) ? ' — ' . $r['hata'] : ''));
}

/** DB'deki ad ile Meta'daki ad aynı mı (boşluk/büyük-küçük farkı yok sayılır). */
function mtAdEslestiMi($dbAd, $metaAd) {
    $n = function ($s) { return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$s)), 'UTF-8'); };
    return $n($dbAd) === $n($metaAd);
}

/** Aktif Meta entegrasyon kanalı (token + base URL + app secret). */
function mtMetaKanal($db) {
    return $db->fetchOne("
        SELECT k.EntegrasyonKanallari_Sifre AS Token,
               e.Entegrasyonlar_BaseURL     AS BaseURL,
               e.Entegrasyonlar_ApiKey      AS AppSecret
        FROM EntegrasyonKanallari k
        INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
        WHERE e.Entegrasyonlar_Tip = 'meta' AND k.Durum = 1 AND e.Durum = 1
        ORDER BY k.EntegrasyonKanallari_id
    ");
}

// ─── AJAX ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    try {
        // Generic CRUD: <entity>_<op>  (op: getir | kaydet | sil)
        $parcalar = explode('_', $action, 2);
        $entity   = $parcalar[0] ?? '';
        $op       = $parcalar[1] ?? '';

        if (isset($RT[$entity]) && in_array($op, ['getir', 'kaydet', 'sil', 'durum'], true)) {
            $meta = $RT[$entity];

            // ── Facebook Sayfaları: birim bazlı kısıt ──────────────────────────
            // Ekleme adminde (kısıtlı kullanıcı yeni sayfa ekleyemez); getir/düzenle/sil
            // yalnız kendi birimine junction ile atanmış sayfa üzerinde yapılabilir.
            if ($entity === 'sayfa' && $birimKisitli) {
                $sid = (int)($_POST['id'] ?? 0);
                if ($op === 'kaydet' && $sid === 0) {
                    echo json_encode(['success' => false, 'message' => 'Yeni Facebook sayfası ekleme yetkiniz yok.']); exit;
                }
                if ($sid > 0 && !sayfaKayitBirimYetkiliMi($db, $izinliBirimler, $sid)) {
                    echo json_encode(['success' => false, 'message' => 'Bu sayfa için yetkiniz yok.']); exit;
                }
            }

            if ($op === 'durum') {
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok.']); exit; }
                $id    = (int)($_POST['id'] ?? 0);
                $yeni  = (int)($_POST['durum'] ?? 0) === 1 ? 1 : 0;
                if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Kayıt seçilmedi.']); exit; }

                $db->update($meta['t'], [
                    'Durum'                => $yeni,
                    'GuncelleyenKullanici' => $user['kullanici_id'],
                    'GuncellemeTarihi'     => date('Y-m-d H:i:s'),
                ], [$meta['pk'] => $id]);

                echo json_encode(['success' => true, 'message' => $yeni ? 'Aktif edildi.' : 'Pasife alındı.']);
                exit;
            }

            if ($op === 'getir') {
                $id    = (int)($_POST['id'] ?? 0);
                $kayit = $db->fetchOne("SELECT * FROM {$meta['t']} WHERE {$meta['pk']} = ?", [$id]);
                echo json_encode(['success' => true, 'data' => $kayit]);
                exit;
            }

            if ($op === 'kaydet') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0 && !$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok.']); exit; }
                if ($id == 0 && !$permissions['can_add'])  { echo json_encode(['success' => false, 'message' => 'Ekleme yetkiniz yok.']); exit; }

                $data = [];
                foreach ($meta['cols'] as $col => $tip) {
                    $data[$col] = rtDeger($tip, $_POST[$col] ?? null);
                }

                // Platform "varsayılan" tekilse: yeni varsayılan seçilince diğerlerini sıfırla
                if ($entity === 'platform' && !empty($data['ReklamPlatformlari_Varsayilan'])) {
                    $db->execute("UPDATE ReklamPlatformlari SET ReklamPlatformlari_Varsayilan = 0");
                }

                if ($id > 0) {
                    $data['GuncelleyenKullanici'] = $user['kullanici_id'];
                    $data['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    $db->update($meta['t'], $data, [$meta['pk'] => $id]);
                    $savedId = $id; $mesaj = 'Kayıt güncellendi.';
                } else {
                    $data['OlusturanKullanici'] = $user['kullanici_id'];
                    $data['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                    $data['Durum']              = isset($_POST['Durum']) ? 1 : 1;
                    $savedId = $db->insert($meta['t'], $data); $mesaj = 'Kayıt eklendi.';
                }

                // Facebook sayfası: birim yetkileri (junction) senkronu — yalnız tam yetkili
                // (kısıtlı kullanıcı birim atamasını değiştiremez; mevcut atamalar korunur).
                if ($entity === 'sayfa' && !$birimKisitli) {
                    $birimYetkileri = json_decode($_POST['birim_yetkileri'] ?? '[]', true) ?: [];
                    $db->delete('KullaniciBirimYetkileri', ['KullaniciBirimYetkileri_ReklamSayfa_id' => $savedId]);
                    foreach ($birimYetkileri as $by) {
                        $birimId = (int)($by['birim_id'] ?? 0);
                        if ($birimId <= 0) continue;
                        $db->insert('KullaniciBirimYetkileri', [
                            'KullaniciBirimYetkileri_Birim_id'        => $birimId,
                            'KullaniciBirimYetkileri_ReklamSayfa_id'  => $savedId,
                            'KullaniciBirimYetkileri_BaslangicTarihi' => ($by['baslangic'] ?? '') ?: null,
                            'KullaniciBirimYetkileri_BitisTarihi'     => ($by['bitis'] ?? '') ?: null,
                            'OlusturanKullanici'                      => $user['kullanici_id'],
                            'OlusturmaTarihi'                         => date('Y-m-d H:i:s'),
                            'GuncelleyenKullanici'                    => $user['kullanici_id'],
                            'GuncellemeTarihi'                        => date('Y-m-d H:i:s'),
                            'Durum'                                   => 1,
                        ]);
                    }
                }

                echo json_encode(['success' => true, 'message' => $mesaj, 'id' => $savedId]);
                exit;
            }

            if ($op === 'sil') {
                if (!$permissions['can_delete']) { echo json_encode(['success' => false, 'message' => 'Silme yetkiniz yok.']); exit; }
                $id = (int)($_POST['id'] ?? 0);
                // Facebook sayfası silinince junction (birim yetkileri) satırları da temizlenir (FK)
                if ($entity === 'sayfa') {
                    $db->delete('KullaniciBirimYetkileri', ['KullaniciBirimYetkileri_ReklamSayfa_id' => $id]);
                }
                $db->delete($meta['t'], [$meta['pk'] => $id]);
                echo json_encode(['success' => true, 'message' => 'Kayıt silindi.']);
                exit;
            }
        }

        // Özel action'lar (listeleme + select + stats)
        switch ($action) {

            // ── Platformlar ─────────────────────────────────────────────────
            case 'platform_listele':
                $liste = $db->fetchAll("
                    SELECT p.*,
                        (SELECT COUNT(*) FROM ReklamHesaplari h WHERE h.ReklamHesaplari_Platform_id = p.ReklamPlatformlari_id AND h.Durum = 1) AS hesap_sayisi
                    FROM ReklamPlatformlari p
                    ORDER BY p.ReklamPlatformlari_Adi
                ");
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            // ── Hesaplar ────────────────────────────────────────────────────
            case 'hesap_listele':
                $platformId = $_POST['platform_id'] ?? '';
                $search     = $_POST['search']      ?? '';
                $where = ['1=1']; $params = [];
                if ($platformId !== '') { $where[] = 'h.ReklamHesaplari_Platform_id = ?'; $params[] = (int)$platformId; }
                if ($search !== '')     { $where[] = '(h.ReklamHesaplari_HesapAdi LIKE ? OR h.ReklamHesaplari_HesapID LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
                if (($_POST['durum'] ?? '') !== '') { $where[] = 'h.Durum = ?'; $params[] = (int)$_POST['durum']; }
                $liste = $db->fetchAll("
                    SELECT h.*, p.ReklamPlatformlari_Adi, p.ReklamPlatformlari_Simge,
                        (SELECT COUNT(*) FROM ReklamKampanyalari k WHERE k.ReklamKampanyalari_Hesap_id = h.ReklamHesaplari_id AND k.Durum = 1) AS kampanya_sayisi
                    FROM ReklamHesaplari h
                    INNER JOIN ReklamPlatformlari p ON h.ReklamHesaplari_Platform_id = p.ReklamPlatformlari_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY h.ReklamHesaplari_HesapAdi
                ", $params);
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            // ── Tek hesabın Meta bağlantısını canlı kontrol et ──────────────
            case 'hesap_meta_kontrol':
                $hid = (int)($_POST['id'] ?? 0);
                if ($hid <= 0) { echo json_encode(['success' => false, 'message' => 'Hesap seçilmedi.']); break; }

                $hesap = $db->fetchOne("
                    SELECT h.ReklamHesaplari_id, h.ReklamHesaplari_HesapAdi, h.ReklamHesaplari_HesapID,
                           h.ReklamHesaplari_HesapDurumu, p.ReklamPlatformlari_Adi AS platform
                    FROM ReklamHesaplari h
                    INNER JOIN ReklamPlatformlari p ON h.ReklamHesaplari_Platform_id = p.ReklamPlatformlari_id
                    WHERE h.ReklamHesaplari_id = ?
                ", [$hid]);
                if (!$hesap) { echo json_encode(['success' => false, 'message' => 'Hesap bulunamadı.']); break; }
                if (!mtPlatformMetaMi($hesap['platform'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu kontrol yalnızca Meta platformundaki hesaplarda kullanılabilir.']); break;
                }

                $actId = preg_replace('/[^a-z0-9_]/i', '', (string)$hesap['ReklamHesaplari_HesapID']);
                if ($actId === '') { echo json_encode(['success' => false, 'message' => 'Hesabın Meta hesap ID (act_...) bilgisi yok.']); break; }
                if (stripos($actId, 'act_') !== 0) $actId = 'act_' . $actId;

                $kanal = mtMetaKanal($db);
                if (!$kanal) { echo json_encode(['success' => false, 'message' => 'Aktif Meta kanalı bulunamadı.']); break; }

                $baseURL = rtrim($kanal['BaseURL'], '/');
                $token   = (string)$kanal['Token'];
                $secret  = (string)$kanal['AppSecret'];

                $alanlar = 'id,name,account_status,disable_reason,currency,business{id,name}';
                $r = mtGraphGet($baseURL . '/' . $actId . '?fields=' . urlencode($alanlar)
                    . '&access_token=' . urlencode($token) . '&appsecret_proof=' . mtProof($token, $secret));

                if (!$r['ok']) {
                    echo json_encode([
                        'success' => true,
                        'bagli'   => false,
                        'data'    => [
                            'meta_id' => $actId,
                            'hata'    => mtHataMesaji($r),
                        ],
                    ]);
                    break;
                }

                $d      = $r['data'];
                $status = isset($d['account_status']) ? (int)$d['account_status'] : null;

                // Canlı durum DB'ye yazılır (yalnız düzenleme yetkisi varsa).
                if ($status !== null && $permissions['can_edit']
                    && (int)$hesap['ReklamHesaplari_HesapDurumu'] !== $status) {
                    $db->update('ReklamHesaplari', [
                        'ReklamHesaplari_HesapDurumu' => $status,
                        'GuncelleyenKullanici'        => $user['kullanici_id'],
                        'GuncellemeTarihi'            => date('Y-m-d H:i:s'),
                    ], ['ReklamHesaplari_id' => $hid]);
                }

                echo json_encode(['success' => true, 'bagli' => true, 'data' => [
                    'meta_id'      => $d['id']   ?? $actId,
                    'ad'           => $d['name'] ?? '',
                    'durum'        => $status,
                    'durum_adi'    => $status !== null ? mtDurumAdi($status) : '—',
                    'kapali_sebep' => $d['disable_reason'] ?? null,
                    'para_birimi'  => $d['currency'] ?? null,
                    'isletme'      => $d['business']['name'] ?? null,
                    'isletme_id'   => $d['business']['id'] ?? null,
                    'ad_eslesti'   => mtAdEslestiMi($hesap['ReklamHesaplari_HesapAdi'], $d['name'] ?? ''),
                ]]);
                break;

            // ── Kampanyalar ─────────────────────────────────────────────────
            case 'kampanya_listele':
                $hesapId = $_POST['hesap_id'] ?? '';
                $search  = $_POST['search']   ?? '';
                $where = ['1=1']; $params = [];
                if ($hesapId !== '') { $where[] = 'k.ReklamKampanyalari_Hesap_id = ?'; $params[] = (int)$hesapId; }
                if ($search !== '')  { $where[] = '(k.ReklamKampanyalari_KampanyaAdi LIKE ? OR k.ReklamKampanyalari_KampanyaID LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
                if (($_POST['durum'] ?? '') !== '') { $where[] = 'k.Durum = ?'; $params[] = (int)$_POST['durum']; }
                $liste = $db->fetchAll("
                    SELECT k.*, h.ReklamHesaplari_HesapAdi, p.ReklamPlatformlari_Adi AS platform_adi,
                        (SELECT COUNT(*) FROM ReklamFacebookSayfalari s WHERE s.ReklamFacebookSayfalari_Kampanya_id = k.ReklamKampanyalari_id AND s.Durum = 1) AS sayfa_sayisi
                    FROM ReklamKampanyalari k
                    INNER JOIN ReklamHesaplari h ON k.ReklamKampanyalari_Hesap_id = h.ReklamHesaplari_id
                    LEFT JOIN ReklamPlatformlari p ON h.ReklamHesaplari_Platform_id = p.ReklamPlatformlari_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY k.ReklamKampanyalari_KampanyaAdi
                ", $params);
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            // ── Sayfalar ────────────────────────────────────────────────────
            case 'sayfa_listele':
                $kampanyaId = $_POST['kampanya_id'] ?? '';
                $search     = $_POST['search']      ?? '';
                $abone      = $_POST['abone']        ?? '';
                $birimFiltre = $_POST['birim_id']   ?? '';
                $durumF     = $_POST['durum']       ?? '';
                $minLead    = $_POST['min_lead']    ?? '';
                $sonLead    = $_POST['son_lead']    ?? '';
                $where = ['1=1']; $params = [];
                if ($kampanyaId !== '') { $where[] = 's.ReklamFacebookSayfalari_Kampanya_id = ?'; $params[] = (int)$kampanyaId; }
                if ($search !== '')     { $where[] = '(s.ReklamFacebookSayfalari_SayfaAdi LIKE ? OR s.ReklamFacebookSayfalari_SayfaID LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
                if ($abone === '1')     { $where[] = 's.ReklamFacebookSayfalari_LeadgenAbone = 1'; }
                elseif ($abone === '0') { $where[] = 's.ReklamFacebookSayfalari_LeadgenAbone = 0'; }
                elseif ($abone === 'null') { $where[] = 's.ReklamFacebookSayfalari_LeadgenAbone IS NULL'; }
                if ($durumF !== '')     { $where[] = 's.Durum = ?'; $params[] = (int)$durumF; }
                if ($minLead !== '' && (int)$minLead > 0) { $where[] = 'ISNULL(ld.adet, 0) >= ?'; $params[] = (int)$minLead; }
                if ($sonLead === 'yok')      { $where[] = 'ld.son IS NULL'; }
                elseif ($sonLead !== '' && (int)$sonLead > 0) {
                    $where[] = 'ld.son >= DATEADD(DAY, ?, CAST(GETDATE() AS DATE))'; $params[] = -(int)$sonLead;
                }
                if ($birimFiltre !== '') {
                    $where[] = "EXISTS (SELECT 1 FROM KullaniciBirimYetkileri kbf
                                        WHERE kbf.KullaniciBirimYetkileri_ReklamSayfa_id = s.ReklamFacebookSayfalari_id
                                          AND kbf.KullaniciBirimYetkileri_Birim_id = ? AND kbf.Durum = 1)";
                    $params[] = (int)$birimFiltre;
                }
                if ($birimKisitli) {
                    if (empty($izinliBirimler)) { echo json_encode(['success' => true, 'data' => []]); break; }
                    [$bw, $bp] = sayfaBirimKisitWhere($izinliBirimler, 's.ReklamFacebookSayfalari_id');
                    $where[] = $bw; $params = array_merge($params, $bp);
                }
                $liste = $db->fetchAll("
                    SELECT s.*, k.ReklamKampanyalari_KampanyaAdi, p.ReklamPlatformlari_Adi AS platform_adi,
                        (SELECT COUNT(*) FROM ReklamLeadFormlari f WHERE f.ReklamLeadFormlari_Sayfa_id = s.ReklamFacebookSayfalari_id AND f.Durum = 1) AS form_sayisi,
                        (SELECT STRING_AGG(kb.KullaniciBirim_Adi, ', ')
                           FROM KullaniciBirimYetkileri kby
                           INNER JOIN KullaniciBirim kb ON kby.KullaniciBirimYetkileri_Birim_id = kb.KullaniciBirim_id
                          WHERE kby.KullaniciBirimYetkileri_ReklamSayfa_id = s.ReklamFacebookSayfalari_id AND kby.Durum = 1) AS birim_adlari,
                        ISNULL(ld.adet, 0) AS lead_adedi,
                        ld.son              AS son_lead
                    FROM ReklamFacebookSayfalari s
                    LEFT JOIN ReklamKampanyalari k ON s.ReklamFacebookSayfalari_Kampanya_id = k.ReklamKampanyalari_id
                    LEFT JOIN ReklamHesaplari h ON k.ReklamKampanyalari_Hesap_id = h.ReklamHesaplari_id
                    LEFT JOIN ReklamPlatformlari p ON h.ReklamHesaplari_Platform_id = p.ReklamPlatformlari_id
                    OUTER APPLY (
                        SELECT COUNT(*) AS adet, MAX(b.OlusturmaTarihi) AS son
                        FROM Basvurular b
                        INNER JOIN ReklamLeadFormlari lf ON lf.ReklamLeadFormlari_id = b.ReklamLeadFormlari_ID
                        WHERE lf.ReklamLeadFormlari_Sayfa_id = s.ReklamFacebookSayfalari_id
                    ) ld
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY s.ReklamFacebookSayfalari_SayfaAdi
                ", $params);
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            // ── Formlar ─────────────────────────────────────────────────────
            case 'form_listele':
                $sayfaId = $_POST['sayfa_id'] ?? '';
                $search  = $_POST['search']   ?? '';
                $durumF  = $_POST['durum']    ?? '';
                $minLead = $_POST['min_lead'] ?? '';
                $sonLead = $_POST['son_lead'] ?? '';
                $esleme = $_POST['esleme'] ?? '';
                $where = ['1=1']; $params = [];
                if ($sayfaId !== '') { $where[] = 'f.ReklamLeadFormlari_Sayfa_id = ?'; $params[] = (int)$sayfaId; }
                if ($search !== '')  { $where[] = '(f.ReklamLeadFormlari_FormAdi LIKE ? OR f.ReklamLeadFormlari_FormID LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
                if ($durumF !== '')  { $where[] = 'f.Durum = ?'; $params[] = (int)$durumF; }
                if ($minLead !== '' && (int)$minLead > 0) { $where[] = 'ISNULL(ld.adet, 0) >= ?'; $params[] = (int)$minLead; }
                if ($sonLead === 'yok')      { $where[] = 'ld.son IS NULL'; }
                elseif ($sonLead !== '' && (int)$sonLead > 0) {
                    $where[] = 'ld.son >= DATEADD(DAY, ?, CAST(GETDATE() AS DATE))'; $params[] = -(int)$sonLead;
                }
                if ($esleme === 'var') { $where[] = 'ISNULL(es.eslenen, 0) > 0'; }
                elseif ($esleme === 'yok') { $where[] = 'ISNULL(es.eslenen, 0) = 0'; }
                $liste = $db->fetchAll("
                    SELECT f.*, s.ReklamFacebookSayfalari_SayfaAdi, p.ReklamPlatformlari_Adi AS platform_adi,
                           ISNULL(ld.adet, 0) AS lead_adedi,
                           ISNULL(ld.kurulan, 0) AS kurulan_adedi,
                           ld.son              AS son_lead,
                           ISNULL(es.toplam, 0)  AS alan_toplam,
                           ISNULL(es.eslenen, 0) AS alan_eslenen
                    FROM ReklamLeadFormlari f
                    LEFT JOIN ReklamFacebookSayfalari s ON f.ReklamLeadFormlari_Sayfa_id = s.ReklamFacebookSayfalari_id
                    LEFT JOIN ReklamKampanyalari k ON s.ReklamFacebookSayfalari_Kampanya_id = k.ReklamKampanyalari_id
                    LEFT JOIN ReklamHesaplari h ON k.ReklamKampanyalari_Hesap_id = h.ReklamHesaplari_id
                    LEFT JOIN ReklamPlatformlari p ON h.ReklamHesaplari_Platform_id = p.ReklamPlatformlari_id
                    OUTER APPLY (
                        SELECT COUNT(*) AS adet, MAX(b.OlusturmaTarihi) AS son,
                               SUM(CASE WHEN b.BasvuruDurum_ID = 1 THEN 1 ELSE 0 END) AS kurulan
                        FROM Basvurular b
                        WHERE b.ReklamLeadFormlari_ID = f.ReklamLeadFormlari_id
                    ) ld
                    OUTER APPLY (
                        SELECT COUNT(*) AS toplam,
                               SUM(CASE WHEN a.ReklamLeadFormAlanlari_HedefKolon IS NOT NULL THEN 1 ELSE 0 END) AS eslenen
                        FROM ReklamLeadFormAlanlari a
                        WHERE a.ReklamLeadFormAlanlari_Form_id = f.ReklamLeadFormlari_id AND a.Durum = 1
                    ) es
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY f.ReklamLeadFormlari_FormAdi
                ", $params);
                echo json_encode(['success' => true, 'data' => $liste]);
                break;

            // ── Tek kampanyanın Meta bağlantısını canlı kontrol et ──────────
            case 'kampanya_meta_kontrol':
                $kid = (int)($_POST['id'] ?? 0);
                if ($kid <= 0) { echo json_encode(['success' => false, 'message' => 'Kampanya seçilmedi.']); break; }

                $kamp = $db->fetchOne("
                    SELECT k.ReklamKampanyalari_id, k.ReklamKampanyalari_KampanyaAdi, k.ReklamKampanyalari_KampanyaID,
                           k.ReklamKampanyalari_KampanyaDurumu, p.ReklamPlatformlari_Adi AS platform
                    FROM ReklamKampanyalari k
                    INNER JOIN ReklamHesaplari h ON k.ReklamKampanyalari_Hesap_id = h.ReklamHesaplari_id
                    LEFT JOIN ReklamPlatformlari p ON h.ReklamHesaplari_Platform_id = p.ReklamPlatformlari_id
                    WHERE k.ReklamKampanyalari_id = ?
                ", [$kid]);
                if (!$kamp) { echo json_encode(['success' => false, 'message' => 'Kampanya bulunamadı.']); break; }
                if (!mtPlatformMetaMi($kamp['platform'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu kontrol yalnızca Meta platformundaki kampanyalarda kullanılabilir.']); break;
                }

                $kampId = preg_replace('/[^0-9]/', '', (string)$kamp['ReklamKampanyalari_KampanyaID']);
                if ($kampId === '') { echo json_encode(['success' => false, 'message' => 'Kampanyanın Meta kampanya ID bilgisi yok.']); break; }

                $kanal = mtMetaKanal($db);
                if (!$kanal) { echo json_encode(['success' => false, 'message' => 'Aktif Meta kanalı bulunamadı.']); break; }
                $baseURL = rtrim($kanal['BaseURL'], '/');
                $token   = (string)$kanal['Token'];
                $secret  = (string)$kanal['AppSecret'];

                $r = mtGraphGet($baseURL . '/' . $kampId . '?fields=' . urlencode('id,name,status,effective_status,objective,account_id,created_time')
                    . '&access_token=' . urlencode($token) . '&appsecret_proof=' . mtProof($token, $secret));

                if (!$r['ok']) {
                    echo json_encode(['success' => true, 'bagli' => false, 'data' => [
                        'meta_id' => $kampId,
                        'hata'    => mtHataMesaji($r),
                    ]]);
                    break;
                }

                $d      = $r['data'];
                $durum  = $d['effective_status'] ?? ($d['status'] ?? null);

                if ($durum !== null && $permissions['can_edit']
                    && (string)$kamp['ReklamKampanyalari_KampanyaDurumu'] !== (string)$durum) {
                    $db->update('ReklamKampanyalari', [
                        'ReklamKampanyalari_KampanyaDurumu' => $durum,
                        'GuncelleyenKullanici'              => $user['kullanici_id'],
                        'GuncellemeTarihi'                  => date('Y-m-d H:i:s'),
                    ], ['ReklamKampanyalari_id' => $kid]);
                }

                echo json_encode(['success' => true, 'bagli' => true, 'data' => [
                    'meta_id'     => $d['id']     ?? $kampId,
                    'ad'          => $d['name']   ?? '',
                    'durum'       => $durum,
                    'ham_durum'   => $d['status'] ?? null,
                    'hedef'       => $d['objective'] ?? null,
                    'hesap_id'    => isset($d['account_id']) ? 'act_' . $d['account_id'] : null,
                    'olusturulma' => $d['created_time'] ?? null,
                    'ad_eslesti'  => mtAdEslestiMi($kamp['ReklamKampanyalari_KampanyaAdi'], $d['name'] ?? ''),
                ]]);
                break;

            // ── Tek sayfanın Meta bağlantısını canlı kontrol et ─────────────
            case 'sayfa_meta_kontrol':
                $sid = (int)($_POST['id'] ?? 0);
                if ($sid <= 0) { echo json_encode(['success' => false, 'message' => 'Sayfa seçilmedi.']); break; }

                if ($birimKisitli && !sayfaKayitBirimYetkiliMi($db, $izinliBirimler, $sid)) {
                    echo json_encode(['success' => false, 'message' => 'Bu sayfa için yetkiniz yok.']); break;
                }

                $sayfa = $db->fetchOne("
                    SELECT s.ReklamFacebookSayfalari_id, s.ReklamFacebookSayfalari_SayfaAdi,
                           s.ReklamFacebookSayfalari_SayfaID, s.ReklamFacebookSayfalari_Token AS sayfaToken,
                           s.ReklamFacebookSayfalari_LeadgenAbone AS abone,
                           p.ReklamPlatformlari_Adi AS platform
                    FROM ReklamFacebookSayfalari s
                    LEFT JOIN ReklamKampanyalari k ON s.ReklamFacebookSayfalari_Kampanya_id = k.ReklamKampanyalari_id
                    LEFT JOIN ReklamHesaplari h ON k.ReklamKampanyalari_Hesap_id = h.ReklamHesaplari_id
                    LEFT JOIN ReklamPlatformlari p ON h.ReklamHesaplari_Platform_id = p.ReklamPlatformlari_id
                    WHERE s.ReklamFacebookSayfalari_id = ?
                ", [$sid]);
                if (!$sayfa) { echo json_encode(['success' => false, 'message' => 'Sayfa bulunamadı.']); break; }
                // Kampanyaya bağlı olmayan sayfada platform NULL gelir; bu tablo zaten FB/Meta
                // sayfaları tutar. Yalnız platform DOLU ve Meta-dışıysa engelle.
                if (!empty($sayfa['platform']) && !mtPlatformMetaMi($sayfa['platform'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu kontrol yalnızca Meta platformundaki sayfalarda kullanılabilir.']); break;
                }

                $pageId = preg_replace('/[^0-9]/', '', (string)$sayfa['ReklamFacebookSayfalari_SayfaID']);
                if ($pageId === '') { echo json_encode(['success' => false, 'message' => 'Sayfanın Facebook ID bilgisi yok.']); break; }

                $kanal = mtMetaKanal($db);
                if (!$kanal) { echo json_encode(['success' => false, 'message' => 'Aktif Meta kanalı bulunamadı.']); break; }
                $baseURL = rtrim($kanal['BaseURL'], '/');
                $token   = (string)$kanal['Token'];
                $secret  = (string)$kanal['AppSecret'];

                $r = mtGraphGet($baseURL . '/' . $pageId . '?fields=' . urlencode('id,name,access_token,category')
                    . '&access_token=' . urlencode($token) . '&appsecret_proof=' . mtProof($token, $secret));

                if (!$r['ok']) {
                    echo json_encode(['success' => true, 'bagli' => false, 'data' => [
                        'meta_id' => $pageId,
                        'hata'    => mtHataMesaji($r),
                    ]]);
                    break;
                }

                $d = $r['data'];
                // Page token: önce sayfanın OAuth ile alınmış kendi token'ı, yoksa System User'dan gelen
                $pgToken = trim((string)($sayfa['sayfaToken'] ?? '')) ?: ($d['access_token'] ?? null);

                // Page token varsa leadgen aboneliği de canlı doğrulanır
                $leadgen = null; $formSayisi = null;
                if ($pgToken) {
                    $subs = mtGraphGet($baseURL . '/' . $pageId . '/subscribed_apps?fields=id,name,subscribed_fields'
                        . '&access_token=' . urlencode($pgToken) . '&appsecret_proof=' . mtProof($pgToken, $secret));
                    if ($subs['ok']) {
                        $leadgen = false;
                        foreach (($subs['data']['data'] ?? []) as $app) {
                            if (in_array('leadgen', $app['subscribed_fields'] ?? [], true)) { $leadgen = true; break; }
                        }
                    }
                    $ff = mtGraphAll($baseURL, $pageId . '/leadgen_forms', ['fields' => 'id'], $pgToken, $secret);
                    if (!$ff['err']) $formSayisi = count($ff['rows']);
                }

                // Canlı abonelik bilgisi DB ile farklıysa güncellenir
                if ($leadgen !== null && $permissions['can_edit']
                    && (int)$sayfa['abone'] !== (int)$leadgen) {
                    $db->update('ReklamFacebookSayfalari', [
                        'ReklamFacebookSayfalari_LeadgenAbone'   => $leadgen ? 1 : 0,
                        'ReklamFacebookSayfalari_AbonelikTarihi' => date('Y-m-d H:i:s'),
                        'GuncelleyenKullanici'                   => $user['kullanici_id'],
                        'GuncellemeTarihi'                       => date('Y-m-d H:i:s'),
                    ], ['ReklamFacebookSayfalari_id' => $sid]);
                }

                echo json_encode(['success' => true, 'bagli' => true, 'data' => [
                    'meta_id'        => $d['id']   ?? $pageId,
                    'ad'             => $d['name'] ?? '',
                    'kategori'       => $d['category'] ?? null,
                    'page_token_var' => (bool)$pgToken,
                    'token_kaynagi'  => trim((string)($sayfa['sayfaToken'] ?? '')) !== '' ? 'Sayfanın kendi token\'ı (OAuth)' : ($d['access_token'] ?? null ? 'System User' : null),
                    'leadgen'        => $leadgen,
                    'form_sayisi'    => $formSayisi,
                    'ad_eslesti'     => mtAdEslestiMi($sayfa['ReklamFacebookSayfalari_SayfaAdi'], $d['name'] ?? ''),
                ]]);
                break;

            // ── Tek lead formunun Meta bağlantısını canlı kontrol et ────────
            case 'form_meta_kontrol':
                $fid = (int)($_POST['id'] ?? 0);
                if ($fid <= 0) { echo json_encode(['success' => false, 'message' => 'Form seçilmedi.']); break; }

                $form = $db->fetchOne("
                    SELECT f.ReklamLeadFormlari_id, f.ReklamLeadFormlari_FormAdi, f.ReklamLeadFormlari_FormID,
                           f.ReklamLeadFormlari_FormDurumu, f.ReklamLeadFormlari_Sayfa_id,
                           s.ReklamFacebookSayfalari_SayfaID  AS sayfaMetaId,
                           s.ReklamFacebookSayfalari_SayfaAdi AS sayfaAdi,
                           s.ReklamFacebookSayfalari_Token    AS sayfaToken,
                           p.ReklamPlatformlari_Adi AS platform
                    FROM ReklamLeadFormlari f
                    LEFT JOIN ReklamFacebookSayfalari s ON f.ReklamLeadFormlari_Sayfa_id = s.ReklamFacebookSayfalari_id
                    LEFT JOIN ReklamKampanyalari k ON s.ReklamFacebookSayfalari_Kampanya_id = k.ReklamKampanyalari_id
                    LEFT JOIN ReklamHesaplari h ON k.ReklamKampanyalari_Hesap_id = h.ReklamHesaplari_id
                    LEFT JOIN ReklamPlatformlari p ON h.ReklamHesaplari_Platform_id = p.ReklamPlatformlari_id
                    WHERE f.ReklamLeadFormlari_id = ?
                ", [$fid]);
                if (!$form) { echo json_encode(['success' => false, 'message' => 'Form bulunamadı.']); break; }
                if (!empty($form['platform']) && !mtPlatformMetaMi($form['platform'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu kontrol yalnızca Meta platformundaki formlarda kullanılabilir.']); break;
                }
                if ($birimKisitli && !empty($form['ReklamLeadFormlari_Sayfa_id'])
                    && !sayfaKayitBirimYetkiliMi($db, $izinliBirimler, (int)$form['ReklamLeadFormlari_Sayfa_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu formun sayfası için yetkiniz yok.']); break;
                }

                $formId = preg_replace('/[^0-9]/', '', (string)$form['ReklamLeadFormlari_FormID']);
                if ($formId === '') { echo json_encode(['success' => false, 'message' => 'Formun Meta form ID bilgisi yok.']); break; }

                $kanal = mtMetaKanal($db);
                if (!$kanal) { echo json_encode(['success' => false, 'message' => 'Aktif Meta kanalı bulunamadı.']); break; }
                $baseURL = rtrim($kanal['BaseURL'], '/');
                $token   = (string)$kanal['Token'];
                $secret  = (string)$kanal['AppSecret'];

                $r = mtGraphGet($baseURL . '/' . $formId . '?fields=' . urlencode('id,name,status,locale,page{id,name}')
                    . '&access_token=' . urlencode($token) . '&appsecret_proof=' . mtProof($token, $secret));

                if (!$r['ok']) {
                    echo json_encode(['success' => true, 'bagli' => false, 'data' => [
                        'meta_id' => $formId,
                        'hata'    => mtHataMesaji($r),
                    ]]);
                    break;
                }

                $d          = $r['data'];
                $metaSayfa  = $d['page']['name'] ?? null;
                $metaSayfaId = $d['page']['id']  ?? null;

                // Senkronun gerçek yolu Page Token'dır; onunla da doğrulanır.
                $pgToken = trim((string)($form['sayfaToken'] ?? '')) ?: null;
                if (!$pgToken && $metaSayfaId) {
                    $pg = mtGraphGet($baseURL . '/' . $metaSayfaId . '?fields=access_token'
                        . '&access_token=' . urlencode($token) . '&appsecret_proof=' . mtProof($token, $secret));
                    $pgToken = $pg['data']['access_token'] ?? null;
                }
                $pageTokenOk = null;
                if ($pgToken) {
                    $fp = mtGraphGet($baseURL . '/' . $formId . '?fields=id,name,status'
                        . '&access_token=' . urlencode($pgToken) . '&appsecret_proof=' . mtProof($pgToken, $secret));
                    $pageTokenOk = $fp['ok'];
                }

                $durum = $d['status'] ?? null;
                if ($durum !== null && $permissions['can_edit']
                    && (string)$form['ReklamLeadFormlari_FormDurumu'] !== (string)$durum) {
                    $db->update('ReklamLeadFormlari', [
                        'ReklamLeadFormlari_FormDurumu' => $durum,
                        'GuncelleyenKullanici'          => $user['kullanici_id'],
                        'GuncellemeTarihi'              => date('Y-m-d H:i:s'),
                    ], ['ReklamLeadFormlari_id' => $fid]);
                }

                echo json_encode(['success' => true, 'bagli' => true, 'data' => [
                    'meta_id'        => $d['id']   ?? $formId,
                    'ad'             => $d['name'] ?? '',
                    'durum'          => $durum,
                    'dil'            => $d['locale'] ?? null,
                    'meta_sayfa'     => $metaSayfa,
                    'meta_sayfa_id'  => $metaSayfaId,
                    'sayfa_eslesti'  => ($metaSayfaId !== null && preg_replace('/[^0-9]/', '', (string)$form['sayfaMetaId']) === (string)$metaSayfaId),
                    'db_sayfa'       => $form['sayfaAdi'],
                    'page_token_ok'  => $pageTokenOk,
                    'ad_eslesti'     => mtAdEslestiMi($form['ReklamLeadFormlari_FormAdi'], $d['name'] ?? ''),
                ]]);
                break;

            // ── Lead formu alan eşlemesi: Meta sorularını çek + mevcut eşlemeyi dön ──
            case 'form_alanlari':
                $fid = (int)($_POST['id'] ?? 0);
                if ($fid <= 0) { echo json_encode(['success' => false, 'message' => 'Form seçilmedi.']); break; }

                $form = $db->fetchOne("
                    SELECT f.ReklamLeadFormlari_id, f.ReklamLeadFormlari_FormAdi, f.ReklamLeadFormlari_FormID,
                           f.ReklamLeadFormlari_Sayfa_id,
                           s.ReklamFacebookSayfalari_SayfaID  AS sayfaMetaId,
                           s.ReklamFacebookSayfalari_SayfaAdi AS sayfaAdi,
                           s.ReklamFacebookSayfalari_Token    AS sayfaToken
                    FROM ReklamLeadFormlari f
                    LEFT JOIN ReklamFacebookSayfalari s ON f.ReklamLeadFormlari_Sayfa_id = s.ReklamFacebookSayfalari_id
                    WHERE f.ReklamLeadFormlari_id = ?
                ", [$fid]);
                if (!$form) { echo json_encode(['success' => false, 'message' => 'Form bulunamadı.']); break; }

                if ($birimKisitli && !empty($form['ReklamLeadFormlari_Sayfa_id'])
                    && !sayfaKayitBirimYetkiliMi($db, $izinliBirimler, (int)$form['ReklamLeadFormlari_Sayfa_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu formun sayfası için yetkiniz yok.']); break;
                }

                // Hedef kolon kataloğu (dropdown kaynağı)
                $hedefler = $db->fetchAll("
                    SELECT tanim_BasvuruHedefAlanlari_Kolon      AS kolon,
                           tanim_BasvuruHedefAlanlari_Etiket     AS etiket,
                           tanim_BasvuruHedefAlanlari_Tip        AS tip,
                           tanim_BasvuruHedefAlanlari_CokluKabul AS coklu
                    FROM tanim_BasvuruHedefAlanlari
                    WHERE Durum = 1
                    ORDER BY tanim_BasvuruHedefAlanlari_Sira
                ");

                // Meta'dan formun soruları (Page Token öncelikli, yoksa System User token)
                $metaHata = null;
                $formId   = preg_replace('/[^0-9]/', '', (string)$form['ReklamLeadFormlari_FormID']);
                $kanal    = mtMetaKanal($db);

                if ($formId !== '' && $kanal) {
                    $baseURL = rtrim($kanal['BaseURL'], '/');
                    $secret  = (string)$kanal['AppSecret'];
                    $tk      = trim((string)($form['sayfaToken'] ?? '')) ?: (string)$kanal['Token'];

                    $r = mtGraphGet($baseURL . '/' . $formId . '?fields=' . urlencode('questions{key,label,type}')
                        . '&access_token=' . urlencode($tk) . '&appsecret_proof=' . mtProof($tk, $secret));

                    if ($r['ok']) {
                        $sorular = $r['data']['questions'] ?? [];
                        $simdi   = date('Y-m-d H:i:s');
                        $gelenler = [];

                        foreach ($sorular as $q) {
                            $alanAdi = trim((string)($q['key'] ?? ''));
                            if ($alanAdi === '') continue;
                            $gelenler[] = $alanAdi;
                            $soru = mb_substr((string)($q['label'] ?? ''), 0, 400);

                            $var = $db->fetchOne("
                                SELECT ReklamLeadFormAlanlari_id FROM ReklamLeadFormAlanlari
                                WHERE ReklamLeadFormAlanlari_Form_id = ? AND ReklamLeadFormAlanlari_MetaAlanAdi = ?
                            ", [$fid, $alanAdi]);

                            if ($var) {
                                // Soru metni değişmiş olabilir; eşleme korunur
                                $db->update('ReklamLeadFormAlanlari', [
                                    'ReklamLeadFormAlanlari_MetaSoru' => $soru,
                                    'Durum'                           => 1,
                                    'GuncelleyenKullanici'            => $user['kullanici_id'],
                                    'GuncellemeTarihi'                => $simdi,
                                ], ['ReklamLeadFormAlanlari_id' => $var['ReklamLeadFormAlanlari_id']]);
                            } else {
                                $db->insert('ReklamLeadFormAlanlari', [
                                    'ReklamLeadFormAlanlari_Form_id'     => $fid,
                                    'ReklamLeadFormAlanlari_MetaAlanAdi' => $alanAdi,
                                    'ReklamLeadFormAlanlari_MetaSoru'    => $soru,
                                    'ReklamLeadFormAlanlari_HedefKolon'  => null,
                                    'ReklamLeadFormAlanlari_Sira'        => 0,
                                    'OlusturanKullanici'                 => $user['kullanici_id'],
                                    'OlusturmaTarihi'                    => $simdi,
                                    'Durum'                              => 1,
                                ]);
                            }
                        }

                        // Meta'dan kaybolan alanlar pasife alınır (eşleme silinmez)
                        if ($gelenler) {
                            $yer = implode(',', array_fill(0, count($gelenler), '?'));
                            $db->execute("
                                UPDATE ReklamLeadFormAlanlari SET Durum = 0,
                                       GuncelleyenKullanici = ?, GuncellemeTarihi = ?
                                WHERE ReklamLeadFormAlanlari_Form_id = ?
                                  AND ReklamLeadFormAlanlari_MetaAlanAdi NOT IN ($yer)
                            ", array_merge([$user['kullanici_id'], $simdi, $fid], $gelenler));
                        }
                    } else {
                        $metaHata = mtHataMesaji($r);
                    }
                } elseif (!$kanal) {
                    $metaHata = 'Aktif Meta kanalı bulunamadı.';
                } else {
                    $metaHata = 'Formun Meta form ID bilgisi yok.';
                }

                $alanlar = $db->fetchAll("
                    SELECT ReklamLeadFormAlanlari_id         AS id,
                           ReklamLeadFormAlanlari_MetaAlanAdi AS alan,
                           ReklamLeadFormAlanlari_MetaSoru    AS soru,
                           ReklamLeadFormAlanlari_HedefKolon  AS hedef,
                           ReklamLeadFormAlanlari_Sira        AS sira,
                           Durum                              AS durum
                    FROM ReklamLeadFormAlanlari
                    WHERE ReklamLeadFormAlanlari_Form_id = ?
                    ORDER BY Durum DESC, ReklamLeadFormAlanlari_id
                ", [$fid]);

                // Formun bağlı olduğu sayfanın birim yetkileri (modalda düzenlenebilir)
                $sayfaFk  = $form['ReklamLeadFormlari_Sayfa_id'] ?? null;
                $birimler = [];
                if ($sayfaFk && !$birimKisitli) {
                    $birimler = $db->fetchAll("
                        SELECT KullaniciBirimYetkileri_Birim_id                     AS birim_id,
                               CONVERT(VARCHAR(10), KullaniciBirimYetkileri_BaslangicTarihi, 23) AS baslangic,
                               CONVERT(VARCHAR(10), KullaniciBirimYetkileri_BitisTarihi, 23)     AS bitis
                        FROM KullaniciBirimYetkileri
                        WHERE KullaniciBirimYetkileri_ReklamSayfa_id = ? AND Durum = 1
                        ORDER BY KullaniciBirimYetkileri_id
                    ", [$sayfaFk]);
                }

                echo json_encode(['success' => true, 'data' => [
                    'form_id'   => $fid,
                    'form_adi'  => $form['ReklamLeadFormlari_FormAdi'],
                    'sayfa_adi' => $form['sayfaAdi'],
                    'alanlar'   => $alanlar,
                    'hedefler'  => $hedefler,
                    'sayfa_id'  => $sayfaFk,
                    'birimler'  => $birimler,
                    'meta_hata' => $metaHata,
                ]]);
                break;

            // ── Lead formu alan eşlemesini kaydet ───────────────────────────
            case 'form_alanlari_kaydet':
                if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Düzenleme yetkiniz yok.']); break; }

                $fid      = (int)($_POST['id'] ?? 0);
                $eslemeler = json_decode($_POST['eslemeler'] ?? '[]', true) ?: [];
                if ($fid <= 0) { echo json_encode(['success' => false, 'message' => 'Form seçilmedi.']); break; }

                $form = $db->fetchOne("SELECT ReklamLeadFormlari_Sayfa_id FROM ReklamLeadFormlari WHERE ReklamLeadFormlari_id = ?", [$fid]);
                if (!$form) { echo json_encode(['success' => false, 'message' => 'Form bulunamadı.']); break; }
                if ($birimKisitli && !empty($form['ReklamLeadFormlari_Sayfa_id'])
                    && !sayfaKayitBirimYetkiliMi($db, $izinliBirimler, (int)$form['ReklamLeadFormlari_Sayfa_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Bu formun sayfası için yetkiniz yok.']); break;
                }

                // Katalog: geçerli hedefler + çoklu kabul bilgisi
                $katalog = [];
                foreach ($db->fetchAll("
                    SELECT tanim_BasvuruHedefAlanlari_Kolon AS kolon,
                           tanim_BasvuruHedefAlanlari_CokluKabul AS coklu
                    FROM tanim_BasvuruHedefAlanlari WHERE Durum = 1
                ") as $k) { $katalog[$k['kolon']] = (int)$k['coklu']; }

                // Doğrulama: bilinmeyen hedef + çoklu kabul etmeyen hedefe birden fazla alan
                $sayac = [];
                foreach ($eslemeler as $e) {
                    $hedef = trim((string)($e['hedef'] ?? ''));
                    if ($hedef === '') continue;
                    if (!isset($katalog[$hedef])) {
                        echo json_encode(['success' => false, 'message' => 'Geçersiz hedef alan: ' . $hedef]); exit;
                    }
                    $sayac[$hedef] = ($sayac[$hedef] ?? 0) + 1;
                }
                foreach ($sayac as $hedef => $adet) {
                    if ($adet > 1 && $katalog[$hedef] !== 1) {
                        echo json_encode(['success' => false, 'message' => 'Bu hedefe yalnızca tek alan eşlenebilir: ' . $hedef]); exit;
                    }
                }

                $simdi = date('Y-m-d H:i:s');
                $guncellenen = 0;
                foreach ($eslemeler as $e) {
                    $satirId = (int)($e['id'] ?? 0);
                    if ($satirId <= 0) continue;
                    $hedef = trim((string)($e['hedef'] ?? ''));
                    $db->update('ReklamLeadFormAlanlari', [
                        'ReklamLeadFormAlanlari_HedefKolon' => $hedef !== '' ? $hedef : null,
                        'ReklamLeadFormAlanlari_Sira'       => (int)($e['sira'] ?? 0),
                        'GuncelleyenKullanici'              => $user['kullanici_id'],
                        'GuncellemeTarihi'                  => $simdi,
                    ], ['ReklamLeadFormAlanlari_id' => $satirId, 'ReklamLeadFormAlanlari_Form_id' => $fid]);
                    $guncellenen++;
                }

                // Sayfanın birim yetkileri (yalnız tam yetkili kullanıcı değiştirebilir)
                $sayfaFk = $form['ReklamLeadFormlari_Sayfa_id'] ?? null;
                if ($sayfaFk && !$birimKisitli && isset($_POST['birim_yetkileri'])) {
                    $birimYetkileri = json_decode($_POST['birim_yetkileri'], true) ?: [];
                    $db->delete('KullaniciBirimYetkileri', ['KullaniciBirimYetkileri_ReklamSayfa_id' => $sayfaFk]);
                    foreach ($birimYetkileri as $by) {
                        $birimId = (int)($by['birim_id'] ?? 0);
                        if ($birimId <= 0) continue;
                        $db->insert('KullaniciBirimYetkileri', [
                            'KullaniciBirimYetkileri_Birim_id'        => $birimId,
                            'KullaniciBirimYetkileri_ReklamSayfa_id'  => $sayfaFk,
                            'KullaniciBirimYetkileri_BaslangicTarihi' => ($by['baslangic'] ?? '') ?: null,
                            'KullaniciBirimYetkileri_BitisTarihi'     => ($by['bitis'] ?? '') ?: null,
                            'OlusturanKullanici'                      => $user['kullanici_id'],
                            'OlusturmaTarihi'                         => $simdi,
                            'GuncelleyenKullanici'                    => $user['kullanici_id'],
                            'GuncellemeTarihi'                        => $simdi,
                            'Durum'                                   => 1,
                        ]);
                    }
                }

                // ── Geriye dönük doldurma ───────────────────────────────────
                // Eşleme kaydedilince bu formun son 90 gündeki lead'leri Meta'dan
                // yeniden çekilip eşlenmiş kolonların üzerine yazılır.
                // (Meta lead verisini ~90 gün sonra siler; daha eskiler atlanır.)
                require_once __DIR__ . '/../includes/MetaLeadAlan.php';

                $geri  = ['kayit' => 0, 'guncellenen' => 0, 'veriYok' => 0, 'hata' => 0];
                $kanal = mtMetaKanal($db);

                // Hangi Basvurular kolonları yazılacak — yalnız eşlemesi olanlar
                $yazilacak = [];
                foreach ($db->fetchAll("
                    SELECT DISTINCT t.tanim_BasvuruHedefAlanlari_Kolon AS kolon,
                           t.tanim_BasvuruHedefAlanlari_Tip           AS tip
                    FROM ReklamLeadFormAlanlari a
                    INNER JOIN tanim_BasvuruHedefAlanlari t
                            ON t.tanim_BasvuruHedefAlanlari_Kolon = a.ReklamLeadFormAlanlari_HedefKolon
                    WHERE a.ReklamLeadFormAlanlari_Form_id = ? AND a.Durum = 1
                      AND a.ReklamLeadFormAlanlari_HedefKolon IS NOT NULL
                ", [$fid]) as $h) {
                    if ($h['tip'] === 'adsoyad')      { $yazilacak[] = 'Isim'; $yazilacak[] = 'Soyisim'; }
                    elseif ($h['tip'] === 'telefon')  { $yazilacak[] = 'phoneCountryNumber'; $yazilacak[] = 'phoneAreaNumber'; $yazilacak[] = 'phoneNumber'; }
                    else                              { $yazilacak[] = $h['kolon']; }
                }
                $yazilacak = array_values(array_unique($yazilacak));

                if ($kanal && $yazilacak) {
                    $sayfaTk = $db->fetchOne("
                        SELECT s.ReklamFacebookSayfalari_Token AS tk
                        FROM ReklamLeadFormlari f
                        LEFT JOIN ReklamFacebookSayfalari s ON s.ReklamFacebookSayfalari_id = f.ReklamLeadFormlari_Sayfa_id
                        WHERE f.ReklamLeadFormlari_id = ?
                    ", [$fid]);

                    $baseURL = rtrim($kanal['BaseURL'], '/');
                    $secret  = (string)$kanal['AppSecret'];
                    $tk      = trim((string)($sayfaTk['tk'] ?? '')) ?: (string)$kanal['Token'];

                    $kayitlar = $db->fetchAll("
                        SELECT TOP 1000 Basvurular_id, Basvurular_LeadgenID
                        FROM Basvurular
                        WHERE ReklamLeadFormlari_ID = ?
                          AND Basvurular_LeadgenID IS NOT NULL
                          AND OlusturmaTarihi >= DATEADD(DAY, -90, GETDATE())
                        ORDER BY Basvurular_id DESC
                    ", [$fid]);

                    $geri['kayit'] = count($kayitlar);
                    if ($kayitlar) @set_time_limit(0);

                    foreach ($kayitlar as $kyt) {
                        $lg = (string)$kyt['Basvurular_LeadgenID'];
                        $rg = mtGraphGet($baseURL . '/' . $lg . '?fields=field_data'
                            . '&access_token=' . urlencode($tk) . '&appsecret_proof=' . mtProof($tk, $secret));

                        if (!$rg['ok']) { $geri['veriYok']++; continue; }

                        $fd = $rg['data']['field_data'] ?? [];
                        if (!$fd) { $geri['veriYok']++; continue; }

                        $cozum = alanlariEsle($db, $fid, $fd);
                        $upd   = [];
                        foreach ($yazilacak as $kol) {
                            if (array_key_exists($kol, $cozum)) $upd[$kol] = $cozum[$kol];
                        }
                        if (!$upd) { continue; }

                        $upd['GuncelleyenKullanici'] = $user['kullanici_id'];
                        $upd['GuncellemeTarihi']     = $simdi;

                        try {
                            $db->update('Basvurular', $upd, ['Basvurular_id' => $kyt['Basvurular_id']]);
                            $geri['guncellenen']++;
                        } catch (Throwable $e) {
                            $geri['hata']++;
                        }
                    }
                }

                $mesaj = $guncellenen . ' alan eşlemesi kaydedildi.';
                if ($geri['kayit'] > 0) {
                    $mesaj .= ' Geçmiş başvurular: ' . $geri['guncellenen'] . '/' . $geri['kayit'] . ' güncellendi';
                    if ($geri['veriYok']) $mesaj .= ', ' . $geri['veriYok'] . ' kaydın verisi Meta tarafında yok';
                    if ($geri['hata'])    $mesaj .= ', ' . $geri['hata'] . ' hata';
                    $mesaj .= '.';
                }
                echo json_encode(['success' => true, 'message' => $mesaj, 'geri' => $geri]);
                break;

            // ── Birim yetkileri (junction) ──────────────────────────────────
            case 'get_birimler':
                echo json_encode(['success' => true, 'data' => $db->fetchAll(
                    "SELECT KullaniciBirim_id AS id, KullaniciBirim_Adi AS ad FROM KullaniciBirim WHERE Durum = 1 ORDER BY KullaniciBirim_Adi"
                )]);
                break;
            case 'sayfa_birim_yetkileri':
                $sid = (int)($_POST['id'] ?? 0);
                echo json_encode(['success' => true, 'data' => $db->fetchAll("
                    SELECT k.KullaniciBirimYetkileri_Birim_id,
                           CONVERT(VARCHAR(10), k.KullaniciBirimYetkileri_BaslangicTarihi, 23) AS BaslangicTarihi,
                           CONVERT(VARCHAR(10), k.KullaniciBirimYetkileri_BitisTarihi, 23)     AS BitisTarihi
                    FROM KullaniciBirimYetkileri k
                    WHERE k.KullaniciBirimYetkileri_ReklamSayfa_id = ? AND k.Durum = 1
                    ORDER BY k.KullaniciBirimYetkileri_id
                ", [$sid])]);
                break;

            // ── Select kaynakları ───────────────────────────────────────────
            case 'platform_select':
                echo json_encode(['success' => true, 'data' => $db->fetchAll(
                    "SELECT ReklamPlatformlari_id AS id, ReklamPlatformlari_Adi AS ad FROM ReklamPlatformlari WHERE Durum = 1 ORDER BY ReklamPlatformlari_Adi"
                )]);
                break;
            case 'hesap_select':
                echo json_encode(['success' => true, 'data' => $db->fetchAll(
                    "SELECT ReklamHesaplari_id AS id, ReklamHesaplari_HesapAdi AS ad FROM ReklamHesaplari WHERE Durum = 1 ORDER BY ReklamHesaplari_HesapAdi"
                )]);
                break;
            case 'kampanya_select':
                echo json_encode(['success' => true, 'data' => $db->fetchAll(
                    "SELECT ReklamKampanyalari_id AS id, ReklamKampanyalari_KampanyaAdi AS ad FROM ReklamKampanyalari WHERE Durum = 1 ORDER BY ReklamKampanyalari_KampanyaAdi"
                )]);
                break;
            case 'sayfa_select':
                echo json_encode(['success' => true, 'data' => $db->fetchAll(
                    "SELECT ReklamFacebookSayfalari_id AS id, ReklamFacebookSayfalari_SayfaAdi AS ad FROM ReklamFacebookSayfalari WHERE Durum = 1 ORDER BY ReklamFacebookSayfalari_SayfaAdi"
                )]);
                break;

            // ── Stats ────────────────────────────────────────────────────────
            case 'stats':
                // Sayfa sayacı, listedeki kısıtla aynı kapsamda olmalı (kısıtlı kullanıcı yalnız kendi birimini sayar)
                $sayfaSayacSql = "SELECT COUNT(*) c FROM ReklamFacebookSayfalari s WHERE s.Durum = 1";
                $sayfaSayacParams = [];
                if ($birimKisitli) {
                    if (empty($izinliBirimler)) {
                        $sayfaSayac = 0;
                    } else {
                        [$bw, $bp] = sayfaBirimKisitWhere($izinliBirimler, 's.ReklamFacebookSayfalari_id');
                        $sayfaSayac = $db->fetchOne("$sayfaSayacSql AND $bw", $bp)['c'] ?? 0;
                    }
                } else {
                    $sayfaSayac = $db->fetchOne($sayfaSayacSql, $sayfaSayacParams)['c'] ?? 0;
                }
                echo json_encode(['success' => true, 'data' => [
                    'platform' => $db->fetchOne("SELECT COUNT(*) c FROM ReklamPlatformlari WHERE Durum = 1")['c'] ?? 0,
                    'hesap'    => $db->fetchOne("SELECT COUNT(*) c FROM ReklamHesaplari WHERE Durum = 1")['c'] ?? 0,
                    'kampanya' => $db->fetchOne("SELECT COUNT(*) c FROM ReklamKampanyalari WHERE Durum = 1")['c'] ?? 0,
                    'sayfa'    => $sayfaSayac,
                    'form'     => $db->fetchOne("SELECT COUNT(*) c FROM ReklamLeadFormlari WHERE Durum = 1")['c'] ?? 0,
                ]]);
                break;

            // ── Sayfa bazlı Webhook (leadgen) durum + abone etme ─────────────
            case 'sayfa_webhook':
                $sid    = (int)($_POST['id'] ?? 0);
                $doSub  = ($_POST['do_subscribe'] ?? '') === '1';

                if ($birimKisitli && !sayfaKayitBirimYetkiliMi($db, $izinliBirimler, $sid)) {
                    echo json_encode(['success' => false, 'message' => 'Bu sayfa için yetkiniz yok.']); break;
                }

                $sayfa = $db->fetchOne("
                    SELECT s.ReklamFacebookSayfalari_SayfaAdi AS ad, s.ReklamFacebookSayfalari_SayfaID AS pageId,
                           s.ReklamFacebookSayfalari_Token AS sayfaToken, p.ReklamPlatformlari_Adi AS platform
                    FROM ReklamFacebookSayfalari s
                    LEFT JOIN ReklamKampanyalari k ON s.ReklamFacebookSayfalari_Kampanya_id = k.ReklamKampanyalari_id
                    LEFT JOIN ReklamHesaplari h ON k.ReklamKampanyalari_Hesap_id = h.ReklamHesaplari_id
                    LEFT JOIN ReklamPlatformlari p ON h.ReklamHesaplari_Platform_id = p.ReklamPlatformlari_id
                    WHERE s.ReklamFacebookSayfalari_id = ?
                ", [$sid]);
                if (!$sayfa) { echo json_encode(['success' => false, 'message' => 'Sayfa bulunamadı.']); break; }
                // Kampanyaya bağlı olmayan sayfada platform NULL gelir; bu tablo zaten FB/Meta
                // sayfaları tutar. Yalnız platform DOLU ve Meta-dışıysa engelle.
                if ($sayfa['platform'] !== null && $sayfa['platform'] !== '' && stripos((string)$sayfa['platform'], 'meta') === false) {
                    echo json_encode(['success' => false, 'message' => 'Webhook aboneliği yalnızca Meta platformundaki sayfalarda kullanılabilir.']);
                    break;
                }
                if (empty($sayfa['pageId'])) { echo json_encode(['success' => false, 'message' => 'Sayfanın Facebook ID bilgisi yok.']); break; }

                $kanal = $db->fetchOne("
                    SELECT k.EntegrasyonKanallari_Sifre AS Token, e.Entegrasyonlar_BaseURL AS BaseURL, e.Entegrasyonlar_ApiKey AS AppSecret
                    FROM EntegrasyonKanallari k
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE e.Entegrasyonlar_Tip = 'meta' AND k.Durum = 1 AND e.Durum = 1
                    ORDER BY k.EntegrasyonKanallari_id
                ");
                if (!$kanal) { echo json_encode(['success' => false, 'message' => 'Aktif Meta kanalı bulunamadı.']); break; }

                $baseURL = rtrim($kanal['BaseURL'], '/');
                $token   = (string)$kanal['Token'];
                $secret  = (string)$kanal['AppSecret'];
                $pageId  = preg_replace('/[^0-9]/', '', (string)$sayfa['pageId']);

                // Page token: önce sayfanın OAuth ile alınmış kendi token'ı (admin yetkisi taşır),
                // yoksa System User token'ı üzerinden çek (fallback).
                $pgToken = trim((string)($sayfa['sayfaToken'] ?? '')) ?: null;
                if (!$pgToken) {
                    $pg = mtGraphGet($baseURL . '/' . $pageId . '?fields=id,name,access_token&access_token=' . urlencode($token) . '&appsecret_proof=' . mtProof($token, $secret));
                    $pgToken = $pg['data']['access_token'] ?? null;
                    if (!$pg['ok'] || !$pgToken) {
                        echo json_encode(['success' => false, 'message' => 'Sayfa page token alınamadı. Sayfa System User\'a atanmamış olabilir. (' . ($pg['data']['error']['message'] ?? ('HTTP ' . $pg['kod'])) . ')']);
                        break;
                    }
                }

                // Abone etme (yalnızca istenirse — yazma işlemi, düzenleme yetkisi gerekir)
                $subMesaj = null;
                if ($doSub) {
                    if (!$permissions['can_edit']) { echo json_encode(['success' => false, 'message' => 'Abone etme için düzenleme yetkiniz yok.']); break; }
                    $ch = curl_init($baseURL . '/' . $pageId . '/subscribed_apps');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => http_build_query([
                            'subscribed_fields' => 'leadgen',
                            'access_token'      => $pgToken,
                            'appsecret_proof'   => mtProof($pgToken, $secret),
                        ]),
                    ]);
                    $c = curl_exec($ch); $kod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $sonuc = json_decode($c, true) ?: [];
                    if (!($kod >= 200 && $kod < 300)) {
                        echo json_encode(['success' => false, 'message' => 'Abone etme başarısız: ' . ($sonuc['error']['message'] ?? ('HTTP ' . $kod))]);
                        break;
                    }
                    $subMesaj = 'Sayfa leadgen webhook\'una abone edildi.';
                }

                // Güncel abonelik durumu
                $pgSubs = mtGraphGet($baseURL . '/' . $pageId . '/subscribed_apps?fields=id,name,subscribed_fields&access_token=' . urlencode($pgToken) . '&appsecret_proof=' . mtProof($pgToken, $secret));
                $abone = false; $apps = [];
                foreach (($pgSubs['data']['data'] ?? []) as $app) {
                    $alanlar = $app['subscribed_fields'] ?? [];
                    $apps[] = ($app['name'] ?? '?') . ' [' . implode(',', $alanlar) . ']';
                    if (in_array('leadgen', $alanlar, true)) $abone = true;
                }

                // Durumu DB'ye yaz (filtre ve rozet buradan okunur)
                $db->update('ReklamFacebookSayfalari', [
                    'ReklamFacebookSayfalari_LeadgenAbone'   => $abone ? 1 : 0,
                    'ReklamFacebookSayfalari_AbonelikTarihi' => date('Y-m-d H:i:s'),
                    'GuncelleyenKullanici'                   => $user['kullanici_id'],
                    'GuncellemeTarihi'                       => date('Y-m-d H:i:s'),
                ], ['ReklamFacebookSayfalari_id' => $sid]);

                echo json_encode([
                    'success'  => true,
                    'abone'    => $abone,
                    'ad'       => $sayfa['ad'],
                    'pageId'   => $pageId,
                    'apps'     => $apps,
                    'subMesaj' => $subMesaj,
                ]);
                break;

            // ── Meta Bağlantı Testi (temp/meta-test.php mantığı) ─────────────
            case 'baglanti_testi':
                $kanal = $db->fetchOne("
                    SELECT k.EntegrasyonKanallari_Instance AS AppId,
                           k.EntegrasyonKanallari_Sifre    AS Token,
                           e.Entegrasyonlar_BaseURL        AS BaseURL,
                           e.Entegrasyonlar_ApiKey         AS AppSecret
                    FROM EntegrasyonKanallari k
                    INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
                    WHERE e.Entegrasyonlar_Tip = 'meta' AND k.Durum = 1 AND e.Durum = 1
                    ORDER BY k.EntegrasyonKanallari_id
                ");
                if (!$kanal) { echo json_encode(['success' => false, 'message' => 'Aktif Meta kanalı bulunamadı.']); break; }

                $baseURL = rtrim($kanal['BaseURL'], '/');
                $token   = (string)$kanal['Token'];
                $secret  = (string)$kanal['AppSecret'];

                $formId  = preg_replace('/[^0-9]/', '', (string)($_POST['test_form'] ?? ''));
                $bizId   = preg_replace('/[^0-9]/', '', (string)($_POST['test_business'] ?? ''));
                $pageIdT = preg_replace('/[^0-9]/', '', (string)($_POST['test_page'] ?? ''));
                $kampHes = preg_replace('/[^a-z0-9_]/i', '', (string)($_POST['test_act'] ?? ''));
                $doSub   = ($_POST['do_subscribe'] ?? '') === '1';


                // Token sahibi
                $me = mtGraphGet($baseURL . '/me?fields=id,name&access_token=' . urlencode($token) . '&appsecret_proof=' . mtProof($token, $secret));
                // Reklam hesapları + sayfalar
                $hesaplar = mtGraphAll($baseURL, 'me/adaccounts', ['fields' => 'id,name,account_status,business{id,name}'], $token, $secret);
                $sayfalar = mtGraphAll($baseURL, 'me/accounts', ['fields' => 'id,name,access_token'], $token, $secret);

                // Kampanyalar (opsiyonel)
                $kampSonuc = ($kampHes !== '') ? mtGraphAll($baseURL, $kampHes . '/campaigns', ['fields' => 'id,name,status,objective'], $token, $secret) : null;

                // Lead form testi
                $formSU = $formPage = $formLeads = $formPageAdi = null;
                if ($formId !== '') {
                    $formSU = mtGraphGet($baseURL . '/' . $formId . '?fields=id,name,status,locale,page{id,name}&access_token=' . urlencode($token) . '&appsecret_proof=' . mtProof($token, $secret));
                    $formPageId  = $formSU['data']['page']['id'] ?? null;
                    $formPageAdi = $formSU['data']['page']['name'] ?? null;
                    $pageToken = null;
                    if ($formPageId) {
                        foreach ($sayfalar['rows'] as $s) {
                            if (($s['id'] ?? '') === $formPageId) { $pageToken = $s['access_token'] ?? null; break; }
                        }
                    }
                    if ($pageToken) {
                        $formPage  = mtGraphGet($baseURL . '/' . $formId . '?fields=id,name,status&access_token=' . urlencode($pageToken) . '&appsecret_proof=' . mtProof($pageToken, $secret));
                        $formLeads = mtGraphGet($baseURL . '/' . $formId . '/leads?limit=3&access_token=' . urlencode($pageToken) . '&appsecret_proof=' . mtProof($pageToken, $secret));
                    }
                }

                // İşletme portfolyosu
                $biz = $bizOwnedPages = $bizClientPages = $bizOwnedAcc = $bizClientAcc = null;
                $bizSayfaFormlari = []; $bizFormLimit = 25; $bizFormKesildi = false;
                if ($bizId !== '') {
                    $biz            = mtGraphGet($baseURL . '/' . $bizId . '?fields=id,name&access_token=' . urlencode($token) . '&appsecret_proof=' . mtProof($token, $secret));
                    $bizOwnedPages  = mtGraphAll($baseURL, $bizId . '/owned_pages',       ['fields' => 'id,name'], $token, $secret);
                    $bizClientPages = mtGraphAll($baseURL, $bizId . '/client_pages',      ['fields' => 'id,name'], $token, $secret);
                    $bizOwnedAcc    = mtGraphAll($baseURL, $bizId . '/owned_ad_accounts', ['fields' => 'id,name,account_status'], $token, $secret);
                    $bizClientAcc   = mtGraphAll($baseURL, $bizId . '/client_ad_accounts',['fields' => 'id,name,account_status'], $token, $secret);

                    // İşletmenin sayfaları için lead formları (Page Token me/accounts'tan eşlenir).
                    // Business seviyesinde lead edge'i yoktur; form ID'leri sayfa+Page Token ile çekilir.
                    $sayfaTokenMap = [];
                    foreach (($sayfalar['rows'] ?? []) as $s) {
                        if (!empty($s['id'])) $sayfaTokenMap[$s['id']] = $s['access_token'] ?? null;
                    }
                    $bizFormSay = 0;
                    foreach (array_merge($bizOwnedPages['rows'] ?? [], $bizClientPages['rows'] ?? []) as $bp) {
                        $bpid = (string)($bp['id'] ?? '');
                        if ($bpid === '' || isset($bizSayfaFormlari[$bpid])) continue; // owned+client tekrarını atla
                        if ($bizFormSay >= $bizFormLimit) { $bizFormKesildi = true; break; }
                        $tk = $sayfaTokenMap[$bpid] ?? null;
                        if (!$tk) {
                            $bizSayfaFormlari[$bpid] = ['ad' => $bp['name'] ?? '', 'formlar' => null, 'hata' => 'Page Token yok — sayfa System User\'a atanmamış'];
                        } else {
                            $ff = mtGraphAll($baseURL, $bpid . '/leadgen_forms', ['fields' => 'id,name,status'], $tk, $secret);
                            $bizSayfaFormlari[$bpid] = ['ad' => $bp['name'] ?? '', 'formlar' => $ff['rows'], 'hata' => $ff['err']];
                        }
                        $bizFormSay++;
                    }
                }

                // Facebook sayfası testi
                $pg = $pgFormlar = $pgFormBulundu = $pgSubs = null; $pgLeadgenAbone = false; $pgToken = null; $subYapildi = null;
                if ($pageIdT !== '') {
                    $pg = mtGraphGet($baseURL . '/' . $pageIdT . '?fields=id,name,access_token&access_token=' . urlencode($token) . '&appsecret_proof=' . mtProof($token, $secret));
                    $pgToken = $pg['data']['access_token'] ?? null;
                    if ($pgToken) {
                        $pgFormlar = mtGraphAll($baseURL, $pageIdT . '/leadgen_forms', ['fields' => 'id,name,status'], $pgToken, $secret);
                        foreach ($pgFormlar['rows'] as $ff) {
                            if (($ff['id'] ?? '') === $formId) { $pgFormBulundu = $ff; break; }
                        }
                        // Webhook aboneliği
                        $pgSubs = mtGraphGet($baseURL . '/' . $pageIdT . '/subscribed_apps?fields=id,name,subscribed_fields&access_token=' . urlencode($pgToken) . '&appsecret_proof=' . mtProof($pgToken, $secret));
                        foreach (($pgSubs['data']['data'] ?? []) as $app) {
                            foreach (($app['subscribed_fields'] ?? []) as $sf) {
                                if ($sf === 'leadgen') { $pgLeadgenAbone = true; break 2; }
                            }
                        }
                        // Abone etme (yalnızca istenirse — yazma işlemi)
                        if ($doSub && !$pgLeadgenAbone) {
                            $ch = curl_init($baseURL . '/' . $pageIdT . '/subscribed_apps');
                            curl_setopt_array($ch, [
                                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_POST => true,
                                CURLOPT_POSTFIELDS => http_build_query([
                                    'subscribed_fields' => 'leadgen',
                                    'access_token'      => $pgToken,
                                    'appsecret_proof'   => mtProof($pgToken, $secret),
                                ]),
                            ]);
                            $c = curl_exec($ch); $kod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            $subYapildi = ['ok' => ($kod >= 200 && $kod < 300), 'data' => json_decode($c, true) ?: [], 'kod' => $kod];
                            if (!empty($subYapildi['ok'])) $pgLeadgenAbone = true;
                        }
                    }
                }

                // ── HTML fragment üret ──
                $h = function ($s) { return htmlspecialchars((string)$s); };
                // Özel sorgu: belirli bir ID girildiyse yalnızca ona ait bölüm gösterilir.
                $ozelSorgu = ($formId !== '' || $bizId !== '' || $pageIdT !== '' || $kampHes !== '');
                ob_start();
                ?>
                <div class="alert alert-warning py-2 px-3 small mb-3">
                    <div class="fw-bold mb-1"><i class="bi bi-shield-check"></i> Yeni Facebook / Lead eklerken kontrol listesi</div>
                    <div class="mb-1"><b>ÖNEMLİ:</b> Bir işletme bize <u>ortaklık (partnership)</u> verdiğinde, sayfa üzerinde
                        <b>"Facebook erişimiyle tam kontrol"</b> vermesi gerekir. Sadece görev/reklam yetkisi verirse
                        webhook aboneliği <code>(#200) sufficient administrative permission</code> hatası verir ve <b>lead gelmez</b>.</div>
                    <ol class="mb-1 ps-3">
                        <li>Karşı işletme: <b>Sayfa erişimi → Tam kontrol</b> versin (görev tabanlı erişim YETMEZ).</li>
                        <li>Token sahibi kullanıcıda <b>2FA aktif</b> olmalı (işletme zorunlu kılıyorsa şart).</li>
                        <li>Sayfa <b>System User'a</b> atanmalı → uzun ömürlü, kişiye bağımsız token.</li>
                        <li><b>OAuth ile bağlan</b> (Meta Bağlan) → token kaydolur + <code>leadgen</code> otomatik subscribe olur.</li>
                        <li>Bu testte <b>5-c Webhook aboneliği = ABONE ✓</b> olduğunu doğrula.</li>
                        <li>Meta <b>Lead Ads Testing Tool</b>'dan test lead'i gönder → <code>logs/meta-webhook.log</code>'a düşmeli.</li>
                    </ol>
                    <div class="text-muted">Not: <code>subscribed_apps</code> aboneliği token/izin değişince düşebilir; lead durursa önce buradan kontrol et.</div>
                </div>
                <?php if (!$ozelSorgu): ?>
                <div class="mb-3 p-2 rounded border bg-body-tertiary small">
                    <div>Base URL: <code><?= $h($baseURL) ?></code></div>
                    <div>Token: <code><?= $h(mtMaske($token)) ?></code></div>
                    <div>Token sahibi:
                        <?php if ($me['ok']): ?>
                            <span class="text-success fw-semibold"><?= $h($me['data']['name'] ?? '?') ?> (ID: <?= $h($me['data']['id'] ?? '?') ?>)</span>
                        <?php else: ?>
                            <span class="text-danger fw-semibold">HATA — <?= $h($me['data']['error']['message'] ?? ('HTTP ' . $me['kod'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <h6 class="mt-3">1) Reklam Hesapları — <code>me/adaccounts</code> (<?= count($hesaplar['rows']) ?>)</h6>
                <?php if ($hesaplar['err']): ?><div class="alert alert-danger py-1 px-2 small"><?= $h($hesaplar['err']) ?></div><?php endif; ?>
                <div class="table-responsive"><table class="table table-sm table-bordered small mb-1">
                    <thead class="table-dark"><tr><th>#</th><th>Hesap ID</th><th>Ad</th><th>Durum</th><th>İşletme</th></tr></thead><tbody>
                    <?php foreach ($hesaplar['rows'] as $i => $hh): $st = $hh['account_status'] ?? null; ?>
                    <tr><td><?= $i + 1 ?></td><td><code><?= $h($hh['id'] ?? '') ?></code></td><td><?= $h($hh['name'] ?? '') ?></td>
                        <td><?= $h(mtDurumAdi($st)) ?></td>
                        <td><?= $h(($hh['business']['name'] ?? '—') . (isset($hh['business']['id']) ? ' (' . $hh['business']['id'] . ')' : '')) ?></td></tr>
                    <?php endforeach; ?></tbody></table></div>

                <h6 class="mt-3">2) Facebook Sayfaları — <code>me/accounts</code> (<?= count($sayfalar['rows']) ?>)</h6>
                <?php if ($sayfalar['err']): ?><div class="alert alert-danger py-1 px-2 small"><?= $h($sayfalar['err']) ?></div><?php endif; ?>
                <div class="table-responsive"><table class="table table-sm table-bordered small mb-1">
                    <thead class="table-dark"><tr><th>#</th><th>Sayfa ID</th><th>Ad</th><th>Page Token</th></tr></thead><tbody>
                    <?php foreach ($sayfalar['rows'] as $i => $s): $pt = !empty($s['access_token']); ?>
                    <tr><td><?= $i + 1 ?></td><td><code><?= $h($s['id'] ?? '') ?></code></td><td><?= $h($s['name'] ?? '') ?></td>
                        <td><?= $pt ? '<span class="text-success fw-semibold">VAR ✓</span>' : '<span class="text-danger fw-semibold">YOK ✗</span>' ?></td></tr>
                    <?php endforeach; ?></tbody></table></div>
                <?php endif; /* !$ozelSorgu */ ?>

                <h6 class="mt-3">3) Lead Form Testi — <?= $formId === '' ? '<span class="text-muted">ID girilmedi</span>' : 'ID <code>' . $h($formId) . '</code>' ?></h6>
                <?php if ($formId !== ''): ?>
                    <div class="border rounded p-2 mb-1 small"><b>a) System User token ile:</b>
                        <?php if ($formSU['ok']): ?>
                            <span class="text-success fw-semibold">ERİŞİLDİ ✓</span> — Ad: <b><?= $h($formSU['data']['name'] ?? '?') ?></b>, Durum: <code><?= $h($formSU['data']['status'] ?? '?') ?></code>, Sayfa: <b><?= $h($formPageAdi ?? '—') ?></b>
                        <?php else: ?><span class="text-danger fw-semibold">HATA — <?= $h($formSU['data']['error']['message'] ?? ('HTTP ' . $formSU['kod'])) ?></span><?php endif; ?>
                    </div>
                    <div class="border rounded p-2 mb-1 small"><b>b) Page Token ile (senkronun gerçek yolu):</b>
                        <?php if ($formPage === null): ?><span class="text-danger fw-semibold">Page token bulunamadı ✗</span> — senkron bu formu çekemez.
                        <?php elseif ($formPage['ok']): ?><span class="text-success fw-semibold">ERİŞİLDİ ✓</span> — Durum: <code><?= $h($formPage['data']['status'] ?? '?') ?></code>
                        <?php else: ?><span class="text-danger fw-semibold">HATA — <?= $h($formPage['data']['error']['message'] ?? ('HTTP ' . $formPage['kod'])) ?></span><?php endif; ?>
                    </div>
                    <div class="border rounded p-2 mb-1 small"><b>c) Lead çekme testi:</b>
                        <?php if ($formLeads === null): ?><span class="text-danger fw-semibold">Test edilemedi ✗</span> — page token yok.
                        <?php elseif ($formLeads['ok']): ?><span class="text-success fw-semibold">ERİŞİLDİ ✓</span> — dönen lead: <b><?= count($formLeads['data']['data'] ?? []) ?></b>
                        <?php else: ?><span class="text-danger fw-semibold">HATA — <?= $h($formLeads['data']['error']['message'] ?? ('HTTP ' . $formLeads['kod'])) ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>

                <h6 class="mt-3">4) İşletme Portfolyosu — <?= $bizId === '' ? '<span class="text-muted">ID girilmedi</span>' : 'ID <code>' . $h($bizId) . '</code>' ?></h6>
                <?php if ($bizId !== ''): ?>
                    <div class="border rounded p-2 mb-1 small"><b>Erişim:</b>
                        <?php if ($biz['ok']): ?><span class="text-success fw-semibold">ERİŞİLDİ ✓</span> — <b><?= $h($biz['data']['name'] ?? '?') ?></b>
                        <?php else: ?><span class="text-danger fw-semibold">HATA — <?= $h($biz['data']['error']['message'] ?? ('HTTP ' . $biz['kod'])) ?></span><?php endif; ?>
                    </div>
                    <div class="table-responsive"><table class="table table-sm table-bordered small mb-1">
                        <thead class="table-dark"><tr><th>Varlık türü</th><th>Adet</th><th>İçerik / Hata</th></tr></thead><tbody>
                        <?php foreach ([
                            ['owned_pages',        $bizOwnedPages],
                            ['client_pages',       $bizClientPages],
                            ['owned_ad_accounts',  $bizOwnedAcc],
                            ['client_ad_accounts', $bizClientAcc],
                        ] as [$ad, $sonuc]): ?>
                        <tr><td><?= $h($ad) ?></td><td><?= $sonuc && !$sonuc['err'] ? count($sonuc['rows']) : '—' ?></td>
                            <td><?php
                                if ($sonuc && $sonuc['err']) echo '<span class="text-danger">' . $h($sonuc['err']) . '</span>';
                                elseif ($sonuc) { $ad2 = array_map(fn($x) => $h(($x['name'] ?? '?') . ' [' . ($x['id'] ?? '') . ']'), $sonuc['rows']); echo $ad2 ? implode('<br>', $ad2) : '<i>boş</i>'; }
                            ?></td></tr>
                        <?php endforeach; ?></tbody></table></div>

                    <div class="fw-semibold small mt-2 mb-1">Sayfa bazlı Lead Formları
                        <span class="text-muted">(Business seviyesinde lead edge'i yok; form ID'leri her sayfanın Page Token'ı ile çekilir)</span>
                        <?php if (!empty($bizFormKesildi)): ?><span class="badge text-bg-warning">ilk <?= (int)$bizFormLimit ?> sayfa</span><?php endif; ?>
                    </div>
                    <?php if (empty($bizSayfaFormlari)): ?>
                        <?php
                        $bizErisimOk = !empty($biz['ok']);
                        $bizHepBos = (($bizOwnedPages['rows'] ?? []) === [] && ($bizClientPages['rows'] ?? []) === []
                                   && ($bizOwnedAcc['rows'] ?? []) === []  && ($bizClientAcc['rows'] ?? []) === []);
                        ?>
                        <?php if ($bizErisimOk && $bizHepBos): ?>
                        <div class="alert alert-info py-2 px-3 small mb-1">
                            <b>İşletmeye erişim var ama tüm varlıklar boş.</b> Bu genelde girilen ID'nin
                            <b>karşı tarafın (partner) İşletme Yöneticisi</b> olduğu anlamına gelir. Ortaklık, onların
                            portföyünü listeleme yetkisi vermez; yalnızca belirli varlıkları <u>senin işletmene</u> paylaştırır.
                            <div class="mt-1">Paylaşılan sayfayı/formu görmek için:</div>
                            <ul class="mb-0 ps-3">
                                <li><b>2) Facebook Sayfaları</b> bölümüne bak (bu testi ID'siz çalıştır) — sayfa System User'a atanmışsa orada listelenir.</li>
                                <li>Veya <b>kendi İşletme ID'ni</b> gir → sayfa <code>client_pages</code> altında çıkar.</li>
                                <li>Veya doğrudan <b>5) Facebook Sayfası Testi</b>'ne <b>Sayfa ID</b> gir → formlar + webhook durumu gelir.</li>
                            </ul>
                        </div>
                        <?php else: ?>
                        <div class="text-muted small">İşletmede sayfa bulunamadı ya da sayfa listesi boş.</div>
                        <?php endif; ?>
                    <?php else: ?>
                    <div class="table-responsive"><table class="table table-sm table-bordered small mb-1">
                        <thead class="table-dark"><tr><th>Sayfa</th><th>Form ID</th><th>Form Adı</th><th>Durum</th></tr></thead><tbody>
                        <?php foreach ($bizSayfaFormlari as $bpid => $bilgi): ?>
                            <?php if ($bilgi['hata']): ?>
                                <tr><td><?= $h($bilgi['ad']) ?><br><small class="text-muted"><?= $h($bpid) ?></small></td>
                                    <td colspan="3"><span class="text-danger"><?= $h($bilgi['hata']) ?></span></td></tr>
                            <?php elseif (empty($bilgi['formlar'])): ?>
                                <tr><td><?= $h($bilgi['ad']) ?><br><small class="text-muted"><?= $h($bpid) ?></small></td>
                                    <td colspan="3"><i>form yok</i></td></tr>
                            <?php else: $ilk = true; foreach ($bilgi['formlar'] as $ff): ?>
                                <tr><td><?php if ($ilk): ?><?= $h($bilgi['ad']) ?><br><small class="text-muted"><?= $h($bpid) ?></small><?php $ilk = false; endif; ?></td>
                                    <td><code><?= $h($ff['id'] ?? '') ?></code></td><td><?= $h($ff['name'] ?? '') ?></td><td><?= $h($ff['status'] ?? '') ?></td></tr>
                            <?php endforeach; endif; ?>
                        <?php endforeach; ?></tbody></table></div>
                    <?php endif; ?>
                <?php endif; ?>

                <h6 class="mt-3">5) Facebook Sayfası Testi — <?= $pageIdT === '' ? '<span class="text-muted">ID girilmedi</span>' : 'ID <code>' . $h($pageIdT) . '</code>' ?></h6>
                <?php if ($pageIdT !== ''): ?>
                    <div class="border rounded p-2 mb-1 small"><b>a) Sayfaya erişim + Page Token:</b>
                        <?php if ($pg['ok']): ?><span class="text-success fw-semibold">ERİŞİLDİ ✓</span> — <b><?= $h($pg['data']['name'] ?? '?') ?></b>, Page Token: <?= !empty($pg['data']['access_token']) ? '<span class="text-success fw-semibold">VAR ✓</span>' : '<span class="text-danger fw-semibold">YOK ✗</span>' ?>
                        <?php else: ?><span class="text-danger fw-semibold">HATA — <?= $h($pg['data']['error']['message'] ?? ('HTTP ' . $pg['kod'])) ?></span><?php endif; ?>
                    </div>
                    <?php if ($pgFormlar !== null): ?>
                    <div class="border rounded p-2 mb-1 small"><b>b) Sayfadaki lead formları (<?= count($pgFormlar['rows']) ?>):</b>
                        <?php if ($pgFormlar['err']): ?><span class="text-danger"><?= $h($pgFormlar['err']) ?></span>
                        <?php else: ?>
                            <?php if ($formId !== ''): ?>Aranan <code><?= $h($formId) ?></code>: <?= $pgFormBulundu ? '<span class="text-success fw-semibold">BULUNDU ✓</span>' : '<span class="text-danger fw-semibold">YOK ✗</span>' ?><?php endif; ?>
                            <div class="table-responsive mt-1"><table class="table table-sm table-bordered small mb-0">
                                <thead class="table-dark"><tr><th>#</th><th>Form ID</th><th>Ad</th><th>Durum</th></tr></thead><tbody>
                                <?php foreach ($pgFormlar['rows'] as $i => $ff): ?>
                                <tr<?= (($ff['id'] ?? '') === $formId) ? ' class="table-success"' : '' ?>><td><?= $i + 1 ?></td><td><code><?= $h($ff['id'] ?? '') ?></code></td><td><?= $h($ff['name'] ?? '') ?></td><td><?= $h($ff['status'] ?? '') ?></td></tr>
                                <?php endforeach; ?></tbody></table></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($pgSubs !== null): ?>
                    <div class="border rounded p-2 mb-1 small"><b>c) Webhook aboneliği (<code>leadgen</code>):</b>
                        <?php if ($pgLeadgenAbone): ?><span class="text-success fw-semibold">ABONE ✓</span> — yeni lead'ler otomatik düşer.
                        <?php else: ?><span class="text-danger fw-semibold">ABONE DEĞİL ✗</span> — yeni başvurular otomatik gelmez.
                            <button type="button" class="btn btn-sm btn-danger ms-2 py-0" onclick="baglantiTestiCalistir(true)">Şimdi leadgen'e abone et</button>
                        <?php endif; ?>
                        <?php if ($subYapildi !== null): ?>
                            <div class="mt-1"><?= $subYapildi['ok'] ? '<span class="text-success fw-semibold">Abone edildi ✓</span>' : '<span class="text-danger fw-semibold">Abone HATA — ' . $h($subYapildi['data']['error']['message'] ?? ('HTTP ' . $subYapildi['kod'])) . '</span>' ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($kampSonuc !== null): ?>
                <h6 class="mt-3">6) Kampanyalar — <code><?= $h($kampHes) ?></code> (<?= count($kampSonuc['rows']) ?>)</h6>
                <?php if ($kampSonuc['err']): ?><div class="alert alert-danger py-1 px-2 small"><?= $h($kampSonuc['err']) ?></div><?php endif; ?>
                <div class="table-responsive"><table class="table table-sm table-bordered small mb-1">
                    <thead class="table-dark"><tr><th>#</th><th>ID</th><th>Ad</th><th>Durum</th><th>Amaç</th></tr></thead><tbody>
                    <?php foreach ($kampSonuc['rows'] as $i => $k): ?>
                    <tr><td><?= $i + 1 ?></td><td><code><?= $h($k['id'] ?? '') ?></code></td><td><?= $h($k['name'] ?? '') ?></td><td><?= $h($k['status'] ?? '') ?></td><td><?= $h($k['objective'] ?? '') ?></td></tr>
                    <?php endforeach; ?></tbody></table></div>
                <?php endif; ?>
                <?php
                $html = ob_get_clean();
                echo json_encode(['success' => true, 'html' => $html]);
                break;

            // Meta senkronizasyonu Cron Yönetimi'ne taşındı (görev: meta_reklam_senkronize).

            default:
                echo json_encode(['success' => false, 'message' => 'Geçersiz işlem.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $e->getMessage()]);
    }
    exit;
}

$canAdd  = $permissions['can_add']  ? 'true' : 'false';
$canEdit = $permissions['can_edit'] ? 'true' : 'false';
$canDel  = $permissions['can_delete'] ? 'true' : 'false';
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

                <!-- Senkron Bar -->
                <?php if ($permissions['can_add'] || $permissions['can_edit']): ?>
                <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                    <div class="alert alert-light border mb-0 py-2 px-3 small flex-grow-1">
                        <i class="bi bi-info-circle text-primary me-1"></i>
                        Meta senkronizasyonu artık otomatik çalışır.
                        <a href="/Admin/pages/cron-yonetimi.php" class="alert-link">Cron Yönetimi</a>'ndeki
                        <strong>Meta Reklam Senkronizasyonu</strong> görevinden yönetilir.
                    </div>
                    <button type="button" class="btn btn-outline-secondary flex-shrink-0" onclick="baglantiTestiAc()">
                        <i class="bi bi-plug"></i> Bağlantı Testi
                    </button>
                    <a class="btn btn-primary flex-shrink-0" href="/oauth/meta-baglan.php">
                        <i class="bi bi-facebook"></i> Facebook ile Bağlan
                    </a>
                </div>
                <?php endif; ?>

                <!-- InfoBox -->
                <div class="row mb-3">
                    <div class="col"><div class="info-box text-bg-primary"><span class="info-box-icon"><i class="bi bi-grid"></i></span><div class="info-box-content"><span class="info-box-text">Platform</span><span class="info-box-number" id="stat-platform">0</span></div></div></div>
                    <div class="col"><div class="info-box text-bg-success"><span class="info-box-icon"><i class="bi bi-person-badge"></i></span><div class="info-box-content"><span class="info-box-text">Hesap</span><span class="info-box-number" id="stat-hesap">0</span></div></div></div>
                    <div class="col"><div class="info-box text-bg-info"><span class="info-box-icon"><i class="bi bi-megaphone"></i></span><div class="info-box-content"><span class="info-box-text">Kampanya</span><span class="info-box-number" id="stat-kampanya">0</span></div></div></div>
                    <div class="col"><div class="info-box text-bg-warning"><span class="info-box-icon"><i class="bi bi-flag"></i></span><div class="info-box-content"><span class="info-box-text">Sayfa</span><span class="info-box-number" id="stat-sayfa">0</span></div></div></div>
                    <div class="col"><div class="info-box text-bg-danger"><span class="info-box-icon"><i class="bi bi-ui-checks"></i></span><div class="info-box-content"><span class="info-box-text">Form</span><span class="info-box-number" id="stat-form">0</span></div></div></div>
                </div>

                <!-- Sekmeler -->
                <ul class="nav nav-tabs mb-3" id="mainTabs">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabPlatform"><i class="bi bi-grid"></i> Platformlar</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabHesap"><i class="bi bi-person-badge"></i> Hesaplar</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabKampanya"><i class="bi bi-megaphone"></i> Kampanyalar</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabSayfa"><i class="bi bi-flag"></i> Facebook/Web Sayfaları</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabForm"><i class="bi bi-ui-checks"></i> Lead Formları</a></li>
                </ul>

                <div class="tab-content">

                    <!-- TAB: Platformlar -->
                    <div class="tab-pane fade show active" id="tabPlatform">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Platformlar</h3>
                                <div class="card-tools">
                                    <button class="btn btn-success btn-sm me-1" onclick="excelIndir('platform')"><i class="bi bi-file-earmark-excel"></i> Excel</button>
                                    <?php if ($permissions['can_add']): ?><button class="btn btn-primary btn-sm" onclick="modalAc('platform')"><i class="bi bi-plus-circle"></i> Yeni Platform</button><?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <table id="tblPlatform" class="table table-bordered table-striped table-hover">
                                    <thead><tr><th>ID</th><th>İkon</th><th>Ad</th><th>Varsayılan</th><th>Hesap</th><th>Durum</th><th>İşlem</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- TAB: Hesaplar -->
                    <div class="tab-pane fade" id="tabHesap">
                        <div class="card card-primary card-outline mb-3">
                            <div class="card-header"><h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                <div class="card-tools"><button class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#fltHesap"><i class="bi bi-chevron-down"></i></button></div>
                            </div>
                            <div class="card-body collapse" id="fltHesap">
                                <div class="row g-3">
                                    <div class="col-md-4"><label class="form-label">Ara</label><input type="text" class="form-control" id="fh_search" placeholder="Hesap adı / ID"></div>
                                    <div class="col-md-4"><label class="form-label">Platform</label><select class="form-select select2-basic" id="fh_platform"><option value="">Tümü</option></select></div>
                                    <div class="col-md-4"><label class="form-label">Durum</label><select class="form-select select2-basic" id="fh_durum"><option value="">Tümü</option><option value="1">Aktif</option><option value="0">Pasif</option></select></div>
                                    <div class="col-md-12"><button class="btn btn-primary" onclick="listele('hesap')"><i class="bi bi-search"></i> Filtrele</button></div>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header"><h3 class="card-title">Reklam Hesapları</h3>
                                <div class="card-tools"><button class="btn btn-success btn-sm me-1" onclick="excelIndir('hesap')"><i class="bi bi-file-earmark-excel"></i> Excel</button><?php if ($permissions['can_add']): ?><button class="btn btn-primary btn-sm" onclick="modalAc('hesap')"><i class="bi bi-plus-circle"></i> Yeni Hesap</button><?php endif; ?></div>
                            </div>
                            <div class="card-body">
                                <table id="tblHesap" class="table table-bordered table-striped table-hover">
                                    <thead><tr><th>ID</th><th>Hesap Adı</th><th>Platform</th><th>Hesap ID</th><th>Telefon</th><th>Hesap Durumu</th><th>Kampanya</th><th>Durum</th><th>İşlem</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- TAB: Kampanyalar -->
                    <div class="tab-pane fade" id="tabKampanya">
                        <div class="card card-primary card-outline mb-3">
                            <div class="card-header"><h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                <div class="card-tools"><button class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#fltKampanya"><i class="bi bi-chevron-down"></i></button></div>
                            </div>
                            <div class="card-body collapse" id="fltKampanya">
                                <div class="row g-3">
                                    <div class="col-md-4"><label class="form-label">Ara</label><input type="text" class="form-control" id="fk_search" placeholder="Kampanya adı / ID"></div>
                                    <div class="col-md-4"><label class="form-label">Hesap</label><select class="form-select select2-basic" id="fk_hesap"><option value="">Tümü</option></select></div>
                                    <div class="col-md-4"><label class="form-label">Durum</label><select class="form-select select2-basic" id="fk_durum"><option value="">Tümü</option><option value="1">Aktif</option><option value="0">Pasif</option></select></div>
                                    <div class="col-md-12"><button class="btn btn-primary" onclick="listele('kampanya')"><i class="bi bi-search"></i> Filtrele</button></div>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header"><h3 class="card-title">Kampanyalar</h3>
                                <div class="card-tools"><button class="btn btn-success btn-sm me-1" onclick="excelIndir('kampanya')"><i class="bi bi-file-earmark-excel"></i> Excel</button><?php if ($permissions['can_add']): ?><button class="btn btn-primary btn-sm" onclick="modalAc('kampanya')"><i class="bi bi-plus-circle"></i> Yeni Kampanya</button><?php endif; ?></div>
                            </div>
                            <div class="card-body">
                                <table id="tblKampanya" class="table table-bordered table-striped table-hover">
                                    <thead><tr><th>ID</th><th>Kampanya Adı</th><th>Hesap</th><th>Kampanya ID</th><th>Kitle</th><th>Kampanya Durumu</th><th>Sayfa</th><th>Durum</th><th>İşlem</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- TAB: Sayfalar -->
                    <div class="tab-pane fade" id="tabSayfa">
                        <div class="card card-primary card-outline mb-3">
                            <div class="card-header"><h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                <div class="card-tools"><button class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#fltSayfa"><i class="bi bi-chevron-down"></i></button></div>
                            </div>
                            <div class="card-body collapse" id="fltSayfa">
                                <div class="row g-3">
                                    <div class="col-md-4"><label class="form-label">Ara</label><input type="text" class="form-control" id="fs_search" placeholder="Sayfa adı / ID"></div>
                                    <div class="col-md-4"><label class="form-label">Kampanya</label><select class="form-select select2-basic" id="fs_kampanya"><option value="">Tümü</option></select></div>
                                    <div class="col-md-4"><label class="form-label">Webhook Aboneliği</label>
                                        <select class="form-select select2-basic" id="fs_abone">
                                            <option value="">Tümü</option>
                                            <option value="1">Abone ✓</option>
                                            <option value="0">Abone değil ✗</option>
                                            <option value="null">Kontrol edilmedi</option>
                                        </select>
                                    </div>
                                    <?php if (!$birimKisitli): ?>
                                    <div class="col-md-4"><label class="form-label">Birim</label>
                                        <select class="form-select select2-basic" id="fs_birim"><option value="">Tümü</option></select>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-md-4"><label class="form-label">Durum</label><select class="form-select select2-basic" id="fs_durum"><option value="">Tümü</option><option value="1">Aktif</option><option value="0">Pasif</option></select></div>
                                    <div class="col-md-4"><label class="form-label">Min. Lead Adedi</label><input type="number" class="form-control" id="fs_min_lead" min="0" step="1" placeholder="örn. 1"></div>
                                    <div class="col-md-4"><label class="form-label">Son Lead</label><select class="form-select select2-basic" id="fs_son_lead"><option value="">Tümü</option><option value="1">Bugün</option><option value="7">Son 7 gün</option><option value="30">Son 30 gün</option><option value="90">Son 90 gün</option><option value="yok">Hiç lead yok</option></select></div>
                                    <div class="col-md-12"><button class="btn btn-primary" onclick="listele('sayfa')"><i class="bi bi-search"></i> Filtrele</button></div>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header"><h3 class="card-title">Facebook/Web Sayfaları</h3>
                                <div class="card-tools"><button class="btn btn-success btn-sm me-1" onclick="excelIndir('sayfa')"><i class="bi bi-file-earmark-excel"></i> Excel</button><?php if ($permissions['can_add'] && !$birimKisitli): ?><button class="btn btn-primary btn-sm" onclick="modalAc('sayfa')"><i class="bi bi-plus-circle"></i> Yeni Sayfa</button><?php endif; ?></div>
                            </div>
                            <div class="card-body">
                                <table id="tblSayfa" class="table table-bordered table-striped table-hover">
                                    <thead><tr><th>ID</th><th>Sayfa Adı</th><th>Kampanya</th><th>Birim</th><th>Sayfa ID</th><th>Form</th><th>Abonelik</th><th>Lead</th><th>Son Lead</th><th>Durum</th><th>İşlem</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- TAB: Formlar -->
                    <div class="tab-pane fade" id="tabForm">
                        <div class="card card-primary card-outline mb-3">
                            <div class="card-header"><h3 class="card-title"><i class="bi bi-funnel"></i> Filtrele</h3>
                                <div class="card-tools"><button class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#fltForm"><i class="bi bi-chevron-down"></i></button></div>
                            </div>
                            <div class="card-body collapse" id="fltForm">
                                <div class="row g-3">
                                    <div class="col-md-4"><label class="form-label">Ara</label><input type="text" class="form-control" id="ff_search" placeholder="Form adı / ID"></div>
                                    <div class="col-md-4"><label class="form-label">Sayfa</label><select class="form-select select2-basic" id="ff_sayfa"><option value="">Tümü</option></select></div>
                                    <div class="col-md-4"><label class="form-label">Durum</label><select class="form-select select2-basic" id="ff_durum"><option value="">Tümü</option><option value="1">Aktif</option><option value="0">Pasif</option></select></div>
                                    <div class="col-md-4"><label class="form-label">Min. Lead Adedi</label><input type="number" class="form-control" id="ff_min_lead" min="0" step="1" placeholder="örn. 1"></div>
                                    <div class="col-md-4"><label class="form-label">Son Lead</label><select class="form-select select2-basic" id="ff_son_lead"><option value="">Tümü</option><option value="1">Bugün</option><option value="7">Son 7 gün</option><option value="30">Son 30 gün</option><option value="90">Son 90 gün</option><option value="yok">Hiç lead yok</option></select></div>
                                    <div class="col-md-4"><label class="form-label">Alan Eşlemesi</label><select class="form-select select2-basic" id="ff_esleme"><option value="">Tümü</option><option value="var">Eşlenmiş</option><option value="yok">Eşlenmemiş</option></select></div>
                                    <div class="col-md-12"><button class="btn btn-primary" onclick="listele('form')"><i class="bi bi-search"></i> Filtrele</button></div>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header"><h3 class="card-title">Lead Formları</h3>
                                <div class="card-tools"><button class="btn btn-success btn-sm me-1" onclick="excelIndir('form')"><i class="bi bi-file-earmark-excel"></i> Excel</button><?php if ($permissions['can_add']): ?><button class="btn btn-primary btn-sm" onclick="modalAc('form')"><i class="bi bi-plus-circle"></i> Yeni Form</button><?php endif; ?></div>
                            </div>
                            <div class="card-body">
                                <table id="tblForm" class="table table-bordered table-striped table-hover">
                                    <thead><tr><th>ID</th><th>Form Adı</th><th>Sayfa</th><th>Form ID</th><th>Form Durumu</th><th>Eşleme</th><th>Lead</th><th>Kurulan</th><th>Son Lead</th><th>Durum</th><th>İşlem</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div><!-- /.tab-content -->
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>

<!-- MODAL: Platform -->
<div class="modal fade" id="modalPlatform" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form id="formPlatform">
    <div class="modal-header"><h5 class="modal-title">Platform</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" name="id">
        <div class="mb-3"><label class="form-label">Ad <span class="text-danger">*</span></label><input type="text" class="form-control" name="ReklamPlatformlari_Adi" required></div>
        <div class="mb-3"><label class="form-label">Simge (Bootstrap Icon)</label>
            <div class="input-group"><span class="input-group-text"><i class="bi" id="platformSimgeOnizleme"></i></span>
                <input type="text" class="form-control" name="ReklamPlatformlari_Simge" id="platformSimge" placeholder="bi-meta"></div>
            <div class="form-text">Örn: bi-google, bi-meta, bi-tiktok</div>
        </div>
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ReklamPlatformlari_Varsayilan" id="platformVarsayilan"><label class="form-check-label" for="platformVarsayilan">Varsayılan platform</label></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button></div>
</form></div></div></div>

<!-- MODAL: Hesap -->
<div class="modal fade" id="modalHesap" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form id="formHesap">
    <div class="modal-header"><h5 class="modal-title">Reklam Hesabı</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" name="id">
        <div class="mb-3"><label class="form-label">Platform <span class="text-danger">*</span></label><select class="form-select" name="ReklamHesaplari_Platform_id" id="hesapPlatform" required><option value="">Seçin...</option></select></div>
        <div class="mb-3"><label class="form-label">Hesap Adı</label><input type="text" class="form-control" name="ReklamHesaplari_HesapAdi"></div>
        <div class="mb-3"><label class="form-label">ID</label><input type="text" class="form-control" name="ReklamHesaplari_HesapID" placeholder="act_xxx veya hesap kimliği"></div>
        <div class="mb-3"><label class="form-label">Telefon No</label><input type="text" class="form-control" name="ReklamHesaplari_TelefonNo"></div>
        <div class="mb-3"><label class="form-label">Hesap Durumu (account_status)</label><input type="number" class="form-control" name="ReklamHesaplari_HesapDurumu" placeholder="1=Aktif, 2=Pasif, 101=Kapalı"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button></div>
</form></div></div></div>

<!-- MODAL: Kampanya -->
<div class="modal fade" id="modalKampanya" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form id="formKampanya">
    <div class="modal-header"><h5 class="modal-title">Kampanya</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" name="id">
        <div class="mb-3"><label class="form-label">Hesap <span class="text-danger">*</span></label><select class="form-select" name="ReklamKampanyalari_Hesap_id" id="kampanyaHesap" required><option value="">Seçin...</option></select></div>
        <div class="mb-3"><label class="form-label">Kampanya Adı</label><input type="text" class="form-control" name="ReklamKampanyalari_KampanyaAdi"></div>
        <div class="mb-3"><label class="form-label">ID</label><input type="text" class="form-control" name="ReklamKampanyalari_KampanyaID"></div>
        <div class="mb-3"><label class="form-label">Kitle (objective)</label><input type="text" class="form-control" name="ReklamKampanyalari_Kitle" placeholder="OUTCOME_LEADS"></div>
        <div class="mb-3"><label class="form-label">Kampanya Durumu (status)</label><input type="text" class="form-control" name="ReklamKampanyalari_KampanyaDurumu" placeholder="ACTIVE / PAUSED"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button></div>
</form></div></div></div>

<!-- MODAL: Sayfa -->
<div class="modal fade" id="modalSayfa" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form id="formSayfa">
    <div class="modal-header"><h5 class="modal-title">Facebook Sayfası</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" name="id">
        <div class="mb-3"><label class="form-label">Kampanya <span class="text-danger">*</span></label><select class="form-select" name="ReklamFacebookSayfalari_Kampanya_id" id="sayfaKampanya" required><option value="">Seçin...</option></select></div>
        <div class="mb-3"><label class="form-label">Sayfa Adı</label><input type="text" class="form-control" name="ReklamFacebookSayfalari_SayfaAdi"></div>
        <div class="mb-3"><label class="form-label">ID</label><input type="text" class="form-control" name="ReklamFacebookSayfalari_SayfaID"></div>
        <?php if (!$birimKisitli): ?>
        <hr>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <strong><i class="bi bi-shield-check me-1"></i> Birim Yetkileri</strong>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="sayfaBirimSatirEkle()"><i class="bi bi-plus"></i> Birim Ekle</button>
        </div>
        <div class="form-text mb-2">Bu sayfayı hangi birim(ler) görebilir/yönetebilir. Boş bırakılırsa yalnız tam yetkili kullanıcılar görür.</div>
        <table class="table table-sm table-bordered mb-0">
            <thead class="table-light"><tr><th>Birim</th><th style="width:150px">Başlangıç</th><th style="width:150px">Bitiş</th><th style="width:44px"></th></tr></thead>
            <tbody id="sayfaBirimRows"></tbody>
        </table>
        <?php endif; ?>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button></div>
</form></div></div></div>

<!-- Lead Formu Alan Eşleme -->
<div class="modal fade" id="modalAlanEsleme" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-diagram-3"></i> Alan Eşleme</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <input type="hidden" id="aeFormId">
        <div class="mb-2">
            <div class="fw-semibold" id="aeFormAdi"></div>
            <small class="text-muted" id="aeSayfaAdi"></small>
        </div>
        <div id="aeUyari"></div>
        <div class="alert alert-light border py-2 px-3 small">
            <i class="bi bi-info-circle text-primary me-1"></i>
            Formdaki her soruyu bir başvuru alanına eşleyin. Boş bırakılan sorular kaydedilmez.
            <strong>Açıklama</strong> hedefine birden fazla soru eşlenebilir; sıra numarasına göre
            <code>Soru: cevap</code> satırları hâlinde birleştirilir.
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:38%">Meta Sorusu</th>
                        <th style="width:22%">Alan Adı</th>
                        <th style="width:28%">Başvuru Alanı</th>
                        <th style="width:12%">Sıra</th>
                    </tr>
                </thead>
                <tbody id="aeSatirlar"></tbody>
            </table>
        </div>
        <?php if (!$birimKisitli): ?>
        <hr class="my-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <strong><i class="bi bi-shield-check me-1"></i> Birim Yetkileri</strong>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="aeBirimSatirEkle()"><i class="bi bi-plus"></i> Birim Ekle</button>
        </div>
        <div class="form-text mb-2">
            Bu formun bağlı olduğu <strong id="aeBirimSayfaAdi" class="text-body">sayfanın</strong> birim yetkileri.
            Burada yapılan değişiklik Facebook/Web Sayfaları sekmesine de yansır.
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light"><tr><th>Birim</th><th style="width:150px">Başlangıç</th><th style="width:150px">Bitiş</th><th style="width:44px"></th></tr></thead>
                <tbody id="aeBirimRows"></tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
        <button type="button" class="btn btn-primary" onclick="alanEslemeKaydet()"><i class="bi bi-save"></i> Kaydet</button>
    </div>
</div></div></div>

<!-- MODAL: Form -->
<div class="modal fade" id="modalForm" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form id="formForm">
    <div class="modal-header"><h5 class="modal-title">Lead Formu</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" name="id">
        <div class="mb-3"><label class="form-label">Sayfa <span class="text-danger">*</span></label><select class="form-select" name="ReklamLeadFormlari_Sayfa_id" id="formSayfaSec" required><option value="">Seçin...</option></select></div>
        <div class="mb-3"><label class="form-label">Form Adı</label><input type="text" class="form-control" name="ReklamLeadFormlari_FormAdi"></div>
        <div class="mb-3"><label class="form-label">ID</label><input type="text" class="form-control" name="ReklamLeadFormlari_FormID"></div>
        <div class="mb-3"><label class="form-label">Form Durumu (status)</label><input type="text" class="form-control" name="ReklamLeadFormlari_FormDurumu" placeholder="ACTIVE / ARCHIVED"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Kaydet</button></div>
</form></div></div></div>

<!-- MODAL: Bağlantı Testi -->
<div class="modal fade" id="modalBaglantiTesti" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plug"></i> Meta Bağlantı Testi</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="row g-2 mb-3">
            <div class="col-md-3"><label class="form-label small mb-1">Lead Form ID</label><input type="text" class="form-control form-control-sm" id="bt_form" placeholder="Örn: 2545493179242661"></div>
            <div class="col-md-3"><label class="form-label small mb-1">İşletme (Business) ID</label><input type="text" class="form-control form-control-sm" id="bt_business" placeholder="Örn: 337430704409887"></div>
            <div class="col-md-3"><label class="form-label small mb-1">Facebook Sayfa ID</label><input type="text" class="form-control form-control-sm" id="bt_page" placeholder="Örn: 1209137835613210"></div>
            <div class="col-md-3"><label class="form-label small mb-1">Reklam Hesabı (act_...)</label><input type="text" class="form-control form-control-sm" id="bt_act" placeholder="Kampanyalar için"></div>
        </div>
        <div class="d-flex gap-2 mb-3">
            <button type="button" class="btn btn-primary btn-sm" onclick="baglantiTestiCalistir()"><i class="bi bi-play-circle"></i> Testi Çalıştır</button>
            <span class="text-muted small align-self-center">Alanları boş bırakırsanız yalnızca token, hesap ve sayfa kontrolleri yapılır.</span>
        </div>
        <div id="bt_sonuc"><div class="text-muted text-center py-4">Testi başlatmak için yukarıdaki butona basın.</div></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button></div>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="/Admin/assets/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="/admin/assets/vendor/sheetjs/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Sidebar kapalı durumunu hatırla (bu sayfa custom.js yüklemiyor — Select2 çakışması)
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

<script>
const pageUrl = '<?= $_SERVER['PHP_SELF'] ?>';
const CAN = { add: <?= $canAdd ?>, edit: <?= $canEdit ?>, del: <?= $canDel ?> };
const BIRIM_KISITLI = <?= $birimKisitli ? 'true' : 'false' ?>;
const HESAP_DURUMLARI = <?= json_encode(MT_HESAP_DURUMLARI, JSON_UNESCAPED_UNICODE) ?>;
const dt = {};
let birimlerListesi = [];

// Entity tanımları (modal id, form id, kolon render)
const ENT = {
    platform: { modal: '#modalPlatform', form: '#formPlatform', tbl: '#tblPlatform' },
    hesap:    { modal: '#modalHesap',    form: '#formHesap',    tbl: '#tblHesap' },
    kampanya: { modal: '#modalKampanya', form: '#formKampanya', tbl: '#tblKampanya' },
    sayfa:    { modal: '#modalSayfa',    form: '#formSayfa',    tbl: '#tblSayfa' },
    form:     { modal: '#modalForm',     form: '#formForm',     tbl: '#tblForm' },
};

$(function () {
    $('.select2-basic').select2({ theme: 'bootstrap-5', width: '100%' });
    statsYukle();
    selectDoldur();
    loadBirimler();
    listele('platform');
    metaBaglanSonuc();

    // Diğer sekmeler ilk açılışta yüklensin
    const yuklendi = { platform: true };
    $('a[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        const ent = $(e.target).attr('href').replace('#tab', '').toLowerCase();
        const map = { platform:'platform', hesap:'hesap', kampanya:'kampanya', sayfa:'sayfa', form:'form' };
        const k = map[ent];
        if (k && !yuklendi[k]) { listele(k); yuklendi[k] = true; }
        // Yalnizca gorunur hale gelen sekmenin tablosunu yeniden olc
        if (k && dt[k]) dt[k].columns.adjust();
    });

    // Form submit
    Object.keys(ENT).forEach(k => {
        $(ENT[k].form).on('submit', function (e) { e.preventDefault(); kaydet(k); });
    });

    // Simge önizleme
    $('#platformSimge').on('input', function () {
        $('#platformSimgeOnizleme').attr('class', 'bi ' + ($(this).val() || ''));
    });
});

function enc(s) { return $('<div>').text(s == null ? '' : s).html(); }
function durumBadge(d) { return d == 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-danger">Pasif</span>'; }


// Eşleme durumu — hiç alan çekilmemiş / eşlenmemiş / kısmi / tam
function eslemeRozet(eslenen, toplam) {
    const e = parseInt(eslenen || 0, 10);
    const t = parseInt(toplam  || 0, 10);
    if (!t) return '<span class="badge text-bg-secondary" title="Alanlar henüz Meta\'dan çekilmedi">Çekilmedi</span>';
    if (!e) return '<span class="badge text-bg-danger">Eşlenmemiş</span>';
    if (e < t) return `<span class="badge text-bg-warning">${e}/${t}</span>`;
    return `<span class="badge text-bg-success">${e}/${t}</span>`;
}

// Lead adedi — sıfırsa soluk, doluysa vurgulu
function leadAdetRozet(adet) {
    const n = parseInt(adet || 0, 10);
    if (!n) return '<span class="badge text-bg-secondary">0</span>';
    return `<span class="badge text-bg-primary">${n.toLocaleString('tr-TR')}</span>`;
}

// Kurulan adedi (BasvuruDurum_ID = 1) — sıfırsa soluk, doluysa yeşil
function kurulanAdetRozet(adet) {
    const n = parseInt(adet || 0, 10);
    if (!n) return '<span class="badge text-bg-secondary">0</span>';
    return `<span class="badge text-bg-success">${n.toLocaleString('tr-TR')}</span>`;
}

// Son lead zamanı — tarih + "x gün önce"
function sonLeadMetni(tarih) {
    if (!tarih) return '<span class="text-muted">—</span>';
    const t = new Date(String(tarih).replace(' ', 'T'));
    if (isNaN(t)) return '<span class="text-muted">—</span>';

    const gun = Math.floor((Date.now() - t.getTime()) / 86400000);
    let ek;
    if (gun <= 0)      ek = '<span class="badge text-bg-success ms-1">bugün</span>';
    else if (gun === 1) ek = '<span class="badge text-bg-success ms-1">dün</span>';
    else if (gun <= 7)  ek = `<span class="badge text-bg-info ms-1">${gun} gün önce</span>`;
    else if (gun <= 30) ek = `<span class="badge text-bg-warning ms-1">${gun} gün önce</span>`;
    else                ek = `<span class="badge text-bg-danger ms-1">${gun} gün önce</span>`;

    const p = n => String(n).padStart(2, '0');
    const gosterim = `${p(t.getDate())}.${p(t.getMonth() + 1)}.${t.getFullYear()} ${p(t.getHours())}:${p(t.getMinutes())}`;
    return `<small>${gosterim}</small>${ek}`;
}
// Tablo içi durum anahtarı — yetkisi yoksa salt okunur
function durumSwitch(ent, id, durum) {
    const aktif = durum == 1;
    const dis   = CAN.edit ? '' : 'disabled';
    return `<div class="form-check form-switch d-flex justify-content-center mb-0">
        <input class="form-check-input" type="checkbox" role="switch"
               id="drm-${ent}-${id}" ${aktif ? 'checked' : ''} ${dis}
               onchange="durumDegistir('${ent}', ${id}, this)">
        <label class="form-check-label" for="drm-${ent}-${id}"></label>
    </div>`;
}

function durumDegistir(ent, id, el) {
    const yeni  = el.checked ? 1 : 0;
    const eski  = yeni ? 0 : 1;
    el.disabled = true;

    $.post(pageUrl, { action: ent + '_durum', id: id, durum: yeni }, function (r) {
        el.disabled = false;
        if (!r.success) {
            el.checked = (eski == 1);
            Swal.fire('Hata', r.message || 'Durum değiştirilemedi.', 'error');
            return;
        }
        statsYukle();
        Swal.fire({
            toast: true, position: 'top-end', icon: 'success',
            title: yeni ? 'Aktif edildi' : 'Pasife alındı',
            timer: 1400, showConfirmButton: false
        });
    }, 'json').fail(function () {
        el.disabled = false;
        el.checked  = (eski == 1);
        Swal.fire('Hata', 'Sunucu hatası.', 'error');
    });
}
function aboneBadge(a) {
    if (a == 1) return '<span class="badge text-bg-success"><i class="bi bi-broadcast"></i> Abone</span>';
    if (a == 0) return '<span class="badge text-bg-danger">Abone değil</span>';
    return '<span class="badge text-bg-secondary">Kontrol edilmedi</span>';
}
// Webhook (leadgen) yalnızca Meta platformunda anlamlıdır
function platformMeta(ad) { return (ad || '').toLowerCase().indexOf('meta') !== -1; }
// Meta account_status kodu → rozet (senkron/kontrol sonrası DB'deki son değer)
function hesapDurumBadge(kod) {
    if (kod === null || kod === '' || typeof kod === 'undefined') {
        return '<span class="badge text-bg-secondary">Kontrol edilmedi</span>';
    }
    const ad = HESAP_DURUMLARI[kod] || ('Kod ' + kod);
    const renk = (kod == 1) ? 'success' : (kod == 9 || kod == 3 || kod == 7 || kod == 8) ? 'warning' : 'danger';
    return `<span class="badge text-bg-${renk}">${enc(ad)}</span>`;
}

// Kampanya/form gibi metin durumlar için rozet (ACTIVE yeşil, PAUSED sarı, gerisi kırmızı)
function metinDurumBadge(s) {
    if (!s) return '<span class="badge text-bg-secondary">Kontrol edilmedi</span>';
    const u = String(s).toUpperCase();
    const renk = (u === 'ACTIVE') ? 'success'
        : (u.indexOf('PAUSED') !== -1 || u.indexOf('PENDING') !== -1 || u.indexOf('IN_PROCESS') !== -1) ? 'warning'
        : 'danger';
    return `<span class="badge text-bg-${renk}">${enc(u)}</span>`;
}

function eslesmeSatiri(eslesti, dbAd) {
    if (eslesti) return '<div class="text-success"><i class="bi bi-check-circle"></i> Ad panel kaydıyla aynı</div>';
    return `<div class="text-warning"><i class="bi bi-exclamation-triangle"></i> Paneldeki ad farklı: ${enc(dbAd || '—')}</div>`;
}
function evetHayir(v) {
    if (v === null || typeof v === 'undefined') return '<span class="text-muted">Bilinmiyor</span>';
    return v ? '<span class="text-success">Evet</span>' : '<span class="text-danger">Hayır</span>';
}

// Her entity için: ID alanı, ad alanı, platform alanı, durum hücresi ve sonuç gövdesi
const META_KONTROL = {
    hesap: {
        pk: 'ReklamHesaplari_id', ad: 'ReklamHesaplari_HesapAdi',
        metaId: 'ReklamHesaplari_HesapID', platform: 'ReklamPlatformlari_Adi',
        platformZorunlu: true,
        hucre: d => hesapDurumBadge(d.durum),
        ikon:  d => (d.durum == 1 ? 'success' : 'warning'),
        govde: (d, x) => `
            <div><b>Hesap ID:</b> <code>${enc(d.meta_id)}</code></div>
            <div><b>Meta'daki adı:</b> ${enc(d.ad)}</div>
            <div><b>Durum:</b> ${hesapDurumBadge(d.durum)}</div>
            ${d.kapali_sebep ? `<div><b>Kapanma sebebi:</b> ${enc(d.kapali_sebep)}</div>` : ''}
            ${d.para_birimi ? `<div><b>Para birimi:</b> ${enc(d.para_birimi)}</div>` : ''}
            <div><b>İşletme:</b> ${enc(d.isletme || '—')}${d.isletme_id ? ' (' + enc(d.isletme_id) + ')' : ''}</div>
            ${eslesmeSatiri(d.ad_eslesti, x[META_KONTROL.hesap.ad])}`,
        hataIpucu: 'Hesap System User\'a atanmamış, token yetkisi yetersiz veya hesap erişimden çıkarılmış olabilir.',
    },
    kampanya: {
        pk: 'ReklamKampanyalari_id', ad: 'ReklamKampanyalari_KampanyaAdi',
        metaId: 'ReklamKampanyalari_KampanyaID', platform: 'platform_adi',
        platformZorunlu: true,
        hucre: d => metinDurumBadge(d.durum),
        ikon:  d => (String(d.durum).toUpperCase() === 'ACTIVE' ? 'success' : 'warning'),
        govde: (d, x) => `
            <div><b>Kampanya ID:</b> <code>${enc(d.meta_id)}</code></div>
            <div><b>Meta'daki adı:</b> ${enc(d.ad)}</div>
            <div><b>Etkin durum:</b> ${metinDurumBadge(d.durum)}</div>
            ${d.ham_durum && d.ham_durum !== d.durum ? `<div><b>Ayarlanan durum:</b> ${metinDurumBadge(d.ham_durum)}</div>` : ''}
            ${d.hedef ? `<div><b>Hedef (objective):</b> ${enc(d.hedef)}</div>` : ''}
            ${d.hesap_id ? `<div><b>Reklam hesabı:</b> <code>${enc(d.hesap_id)}</code></div>` : ''}
            ${eslesmeSatiri(d.ad_eslesti, x[META_KONTROL.kampanya.ad])}`,
        hataIpucu: 'Kampanya silinmiş olabilir veya token bu reklam hesabını görmüyor olabilir.',
    },
    sayfa: {
        pk: 'ReklamFacebookSayfalari_id', ad: 'ReklamFacebookSayfalari_SayfaAdi',
        metaId: 'ReklamFacebookSayfalari_SayfaID', platform: 'platform_adi',
        platformZorunlu: false, // kampanyasız sayfada platform NULL gelir
        hucre: d => (d.leadgen === null ? null : aboneBadge(d.leadgen ? 1 : 0)),
        ikon:  d => (d.page_token_var ? 'success' : 'warning'),
        govde: (d, x) => `
            <div><b>Sayfa ID:</b> <code>${enc(d.meta_id)}</code></div>
            <div><b>Meta'daki adı:</b> ${enc(d.ad)}</div>
            ${d.kategori ? `<div><b>Kategori:</b> ${enc(d.kategori)}</div>` : ''}
            <div><b>Page Token alınabiliyor:</b> ${evetHayir(d.page_token_var)}${d.token_kaynagi ? ` <span class="text-muted">(${enc(d.token_kaynagi)})</span>` : ''}</div>
            <div><b>Leadgen aboneliği:</b> ${evetHayir(d.leadgen)}</div>
            ${d.form_sayisi !== null ? `<div><b>Meta'daki lead formu sayısı:</b> ${enc(d.form_sayisi)}</div>` : ''}
            ${eslesmeSatiri(d.ad_eslesti, x[META_KONTROL.sayfa.ad])}
            ${!d.page_token_var ? '<div class="text-danger mt-1">Page Token yok — senkron bu sayfanın formlarını ve lead\'lerini çekemez.</div>' : ''}`,
        hataIpucu: 'Sayfa System User\'a atanmamış veya portföyden çıkarılmış olabilir.',
    },
    form: {
        pk: 'ReklamLeadFormlari_id', ad: 'ReklamLeadFormlari_FormAdi',
        metaId: 'ReklamLeadFormlari_FormID', platform: 'platform_adi',
        platformZorunlu: false,
        hucre: d => metinDurumBadge(d.durum),
        ikon:  d => ((d.page_token_ok && d.sayfa_eslesti) ? 'success' : 'warning'),
        govde: (d, x) => `
            <div><b>Form ID:</b> <code>${enc(d.meta_id)}</code></div>
            <div><b>Meta'daki adı:</b> ${enc(d.ad)}</div>
            <div><b>Durum:</b> ${metinDurumBadge(d.durum)}</div>
            ${d.dil ? `<div><b>Dil:</b> ${enc(d.dil)}</div>` : ''}
            <div><b>Meta'daki sayfası:</b> ${enc(d.meta_sayfa || '—')}${d.meta_sayfa_id ? ' (' + enc(d.meta_sayfa_id) + ')' : ''}</div>
            <div><b>Page Token ile okunabiliyor:</b> ${evetHayir(d.page_token_ok)}</div>
            ${d.sayfa_eslesti
                ? '<div class="text-success"><i class="bi bi-check-circle"></i> Panelde bağlı olduğu sayfa ile aynı</div>'
                : `<div class="text-warning"><i class="bi bi-exclamation-triangle"></i> Panelde başka sayfaya bağlı: ${enc(d.db_sayfa || '—')}</div>`}
            ${eslesmeSatiri(d.ad_eslesti, x[META_KONTROL.form.ad])}
            ${d.page_token_ok === false ? '<div class="text-danger mt-1">Page Token ile okunamıyor — senkron bu formun lead\'lerini çekemez.</div>' : ''}`,
        hataIpucu: 'Form silinmiş/arşivlenmiş olabilir veya token bu sayfayı görmüyor olabilir.',
    },
};

// Buton yalnız Meta platformundaki ve Meta ID'si dolu satırlarda çıkar
function metaKontrolBtn(ent, x) {
    const c = META_KONTROL[ent];
    const platformAdi = x[c.platform];
    const metaVar = c.platformZorunlu ? platformMeta(platformAdi) : (!platformAdi || platformMeta(platformAdi));
    if (!metaVar || !x[c.metaId]) return '';
    return `<button class="btn btn-sm btn-outline-primary me-1" title="Meta'da Kontrol Et"
        onclick="metaKontrol('${ent}',${x[c.pk]}, this)"><i class="bi bi-plug"></i></button>`;
}

const metaKontrolSatirlari = {}; // ent_id → o satırın verisi (ad karşılaştırması için)

function metaKontrol(ent, id, btn) {
    const c = META_KONTROL[ent];
    const x = metaKontrolSatirlari[ent + '_' + id] || {};
    const $b = $(btn), eski = $b.html();
    $b.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.post(pageUrl, { action: ent + '_meta_kontrol', id: id }, function (r) {
        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }
        const d = r.data;
        const $hucre = $('#md-' + ent + '-' + id);
        if (!r.bagli) {
            $hucre.html('<span class="badge text-bg-danger">Bağlantı yok</span>');
            Swal.fire({
                icon: 'error', title: 'Meta bağlantısı yok',
                html: `<div class="text-start small">
                       <div><b>Meta ID:</b> <code>${enc(d.meta_id)}</code></div>
                       <div class="mt-2"><b>Hata:</b> ${enc(d.hata)}</div>
                       <div class="text-muted mt-2">${enc(c.hataIpucu)}</div></div>`
            });
            return;
        }
        const yeni = c.hucre(d);
        if (yeni !== null) $hucre.html(yeni);
        Swal.fire({
            icon: c.ikon(d), title: 'Meta bağlantısı var',
            html: `<div class="text-start small">${c.govde(d, x)}</div>`
        });
    }, 'json').fail(function () {
        Swal.fire('Hata', 'Kontrol sırasında bağlantı hatası oluştu.', 'error');
    }).always(function () {
        $b.prop('disabled', false).html(eski);
    });
}

function islemBtn(ent, id, ad) {
    let h = '';
    if (CAN.edit) h += `<button class="btn btn-sm btn-warning me-1" onclick="duzenle('${ent}',${id})"><i class="bi bi-pencil"></i></button>`;
    if (CAN.del)  h += `<button class="btn btn-sm btn-danger" onclick="sil('${ent}',${id},'${enc(ad)}')"><i class="bi bi-trash"></i></button>`;
    return h;
}

function statsYukle() {
    $.post(pageUrl, { action: 'stats' }, function (r) {
        if (!r.success) return;
        $('#stat-platform').text(r.data.platform);
        $('#stat-hesap').text(r.data.hesap);
        $('#stat-kampanya').text(r.data.kampanya);
        $('#stat-sayfa').text(r.data.sayfa);
        $('#stat-form').text(r.data.form);
    });
}

// Filtre + modal select'lerini doldur
function selectDoldur() {
    const kaynaklar = [
        { action: 'platform_select', hedefler: ['#fh_platform', '#hesapPlatform'] },
        { action: 'hesap_select',    hedefler: ['#fk_hesap', '#kampanyaHesap'] },
        { action: 'kampanya_select', hedefler: ['#fs_kampanya', '#sayfaKampanya'] },
        { action: 'sayfa_select',    hedefler: ['#ff_sayfa', '#formSayfaSec'] },
    ];
    kaynaklar.forEach(s => {
        $.post(pageUrl, { action: s.action }, function (r) {
            if (!r.success) return;
            s.hedefler.forEach(h => {
                const $el = $(h);
                const ilk = $el.find('option:first');
                const filtreMi = ilk.val() === '';
                $el.empty();
                if (filtreMi) $el.append('<option value="">Tümü / Seçin...</option>');
                r.data.forEach(o => $el.append(`<option value="${o.id}">${enc(o.ad)}</option>`));
            });
        });
    });
}

function listele(ent) {
    const data = { action: ent + '_listele' };
    if (ent === 'hesap')    { data.search = $('#fh_search').val(); data.platform_id = $('#fh_platform').val(); data.durum = $('#fh_durum').val(); }
    if (ent === 'kampanya') { data.search = $('#fk_search').val(); data.hesap_id    = $('#fk_hesap').val(); data.durum = $('#fk_durum').val(); }
    if (ent === 'sayfa')    { data.search = $('#fs_search').val(); data.kampanya_id = $('#fs_kampanya').val(); data.abone = $('#fs_abone').val(); data.birim_id = $('#fs_birim').val() || ''; data.durum = $('#fs_durum').val(); data.min_lead = $('#fs_min_lead').val(); data.son_lead = $('#fs_son_lead').val(); }
    if (ent === 'form')     { data.search = $('#ff_search').val(); data.sayfa_id    = $('#ff_sayfa').val(); data.durum = $('#ff_durum').val(); data.min_lead = $('#ff_min_lead').val(); data.son_lead = $('#ff_son_lead').val(); data.esleme = $('#ff_esleme').val(); }

    $.post(pageUrl, data, function (r) {
        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }
        if (dt[ent]) dt[ent].destroy();
        const $tb = $(ENT[ent].tbl + ' tbody').empty();
        r.data.forEach(row => $tb.append(satirHtml(ent, row)));
        dt[ent] = $(ENT[ent].tbl).DataTable({
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/tr.json' },
            // Lead formları: varsayılan sıralama Son Lead (index 8), en yeni üstte
            order: ent === 'form' ? [[8, 'desc']] : [[0, 'asc']], pageLength: 25, destroy: true,
            // Gizli sekmede kurulan tablo 0 genislik olcer; autoWidth kapali olunca
            // sabit inline genislikler yazilmaz ve sekme degisince tablo daralmaz.
            autoWidth: false,
            columnDefs: [{ targets: 0, width: "70px", className: "text-muted" }]
        });
        // Sekme o an gorunurse hemen, degilse shown.bs.tab tetikleyecek
        if ($(ENT[ent].tbl).is(":visible")) dt[ent].columns.adjust();
    });
}

function satirHtml(ent, x) {
    // Meta kontrol sonucunda paneldeki ad ile karşılaştırabilmek için satırı sakla
    if (META_KONTROL[ent]) metaKontrolSatirlari[ent + '_' + x[META_KONTROL[ent].pk]] = x;
    if (ent === 'platform') {
        const vb = x.ReklamPlatformlari_Varsayilan == 1 ? '<span class="badge text-bg-primary">Varsayılan</span>' : '';
        return `<tr>
            <td>${x.ReklamPlatformlari_id}</td>
            <td class="text-center"><i class="bi ${enc(x.ReklamPlatformlari_Simge)}" style="font-size:1.4rem"></i></td>
            <td>${enc(x.ReklamPlatformlari_Adi)}</td><td>${vb}</td>
            <td><span class="badge text-bg-info">${x.hesap_sayisi}</span></td>
            <td>${durumSwitch('platform', x.ReklamPlatformlari_id, x.Durum)}</td>
            <td>${islemBtn('platform', x.ReklamPlatformlari_id, x.ReklamPlatformlari_Adi)}</td></tr>`;
    }
    if (ent === 'hesap') {
        return `<tr>
            <td>${x.ReklamHesaplari_id}</td>
            <td>${enc(x.ReklamHesaplari_HesapAdi)}</td>
            <td><i class="bi ${enc(x.ReklamPlatformlari_Simge)}"></i> ${enc(x.ReklamPlatformlari_Adi)}</td>
            <td><small>${enc(x.ReklamHesaplari_HesapID)}</small></td>
            <td>${enc(x.ReklamHesaplari_TelefonNo)}</td>
            <td id="md-hesap-${x.ReklamHesaplari_id}">${hesapDurumBadge(x.ReklamHesaplari_HesapDurumu)}</td>
            <td><span class="badge text-bg-info">${x.kampanya_sayisi}</span></td>
            <td>${durumSwitch('hesap', x.ReklamHesaplari_id, x.Durum)}</td>
            <td>${metaKontrolBtn('hesap', x)}${islemBtn('hesap', x.ReklamHesaplari_id, x.ReklamHesaplari_HesapAdi)}</td></tr>`;
    }
    if (ent === 'kampanya') {
        return `<tr>
            <td>${x.ReklamKampanyalari_id}</td>
            <td>${enc(x.ReklamKampanyalari_KampanyaAdi)}</td>
            <td>${enc(x.ReklamHesaplari_HesapAdi)}</td>
            <td><small>${enc(x.ReklamKampanyalari_KampanyaID)}</small></td>
            <td>${enc(x.ReklamKampanyalari_Kitle)}</td>
            <td id="md-kampanya-${x.ReklamKampanyalari_id}">${metinDurumBadge(x.ReklamKampanyalari_KampanyaDurumu)}</td>
            <td><span class="badge text-bg-info">${x.sayfa_sayisi}</span></td>
            <td>${durumSwitch('kampanya', x.ReklamKampanyalari_id, x.Durum)}</td>
            <td>${metaKontrolBtn('kampanya', x)}${islemBtn('kampanya', x.ReklamKampanyalari_id, x.ReklamKampanyalari_KampanyaAdi)}</td></tr>`;
    }
    if (ent === 'sayfa') {
        // Kampanyaya bağlı olmayan sayfalarda platform_adi NULL gelir; bu tablo zaten FB/Meta
        // sayfaları tutar, o yüzden platform boşsa da Meta say (yalnız açıkça Meta-dışıysa gizle).
        const isMeta = !x.platform_adi || platformMeta(x.platform_adi);
        const whBtn = isMeta
            ? `<button class="btn btn-sm btn-info me-1" title="Webhook aboneliği" onclick="sayfaWebhook(${x.ReklamFacebookSayfalari_id},'${enc(x.ReklamFacebookSayfalari_SayfaAdi)}')"><i class="bi bi-broadcast"></i></button>`
            : '';
        const whRozet = isMeta ? aboneBadge(x.ReklamFacebookSayfalari_LeadgenAbone) : '<span class="text-muted">—</span>';
        return `<tr>
            <td>${x.ReklamFacebookSayfalari_id}</td>
            <td>${enc(x.ReklamFacebookSayfalari_SayfaAdi)}</td>
            <td>${enc(x.ReklamKampanyalari_KampanyaAdi)}</td>
            <td>${birimBadges(x.birim_adlari)}</td>
            <td><small>${enc(x.ReklamFacebookSayfalari_SayfaID)}</small></td>
            <td><span class="badge text-bg-info">${x.form_sayisi}</span></td>
            <td id="md-sayfa-${x.ReklamFacebookSayfalari_id}">${whRozet}</td>
            <td data-order="${parseInt(x.lead_adedi||0,10)}">${leadAdetRozet(x.lead_adedi)}</td>
            <td data-order="${x.son_lead ? new Date(String(x.son_lead).replace(" ","T")).getTime()||0 : 0}">${sonLeadMetni(x.son_lead)}</td>
            <td>${durumSwitch('sayfa', x.ReklamFacebookSayfalari_id, x.Durum)}</td>
            <td>${metaKontrolBtn('sayfa', x)}${whBtn}${islemBtn('sayfa', x.ReklamFacebookSayfalari_id, x.ReklamFacebookSayfalari_SayfaAdi)}</td></tr>`;
    }
    if (ent === 'form') {
        return `<tr>
            <td>${x.ReklamLeadFormlari_id}</td>
            <td>${enc(x.ReklamLeadFormlari_FormAdi)}</td>
            <td>${enc(x.ReklamFacebookSayfalari_SayfaAdi)}</td>
            <td><small>${enc(x.ReklamLeadFormlari_FormID)}</small></td>
            <td id="md-form-${x.ReklamLeadFormlari_id}">${metinDurumBadge(x.ReklamLeadFormlari_FormDurumu)}</td>
            <td data-order="${parseInt(x.alan_eslenen||0,10)}">${eslemeRozet(x.alan_eslenen, x.alan_toplam)}</td>
            <td data-order="${parseInt(x.lead_adedi||0,10)}">${leadAdetRozet(x.lead_adedi)}</td>
            <td data-order="${parseInt(x.kurulan_adedi||0,10)}">${kurulanAdetRozet(x.kurulan_adedi)}</td>
            <td data-order="${x.son_lead ? new Date(String(x.son_lead).replace(" ","T")).getTime()||0 : 0}">${sonLeadMetni(x.son_lead)}</td>
            <td>${durumSwitch('form', x.ReklamLeadFormlari_id, x.Durum)}</td>
            <td>${metaKontrolBtn('form', x)}${CAN.edit ? `<button class="btn btn-sm btn-info me-1" title="Alan Eşle" onclick="alanEslemeAc(${x.ReklamLeadFormlari_id})"><i class="bi bi-diagram-3"></i></button>` : ''}${islemBtn('form', x.ReklamLeadFormlari_id, x.ReklamLeadFormlari_FormAdi)}</td></tr>`;
    }
    return '';
}

function modalAc(ent) {
    const f = $(ENT[ent].form)[0]; f.reset();
    $(ENT[ent].form + ' [name=id]').val('');
    if (ent === 'platform') $('#platformSimgeOnizleme').attr('class', 'bi');
    if (ent === 'sayfa') $('#sayfaBirimRows').empty();
    new bootstrap.Modal(ENT[ent].modal).show();
}

function duzenle(ent, id) {
    $.post(pageUrl, { action: ent + '_getir', id: id }, function (r) {
        if (!r.success || !r.data) { Swal.fire('Hata', 'Kayıt bulunamadı.', 'error'); return; }
        const f = $(ENT[ent].form); f[0].reset();
        Object.keys(r.data).forEach(k => {
            const $el = f.find(`[name="${k}"]`);
            if (!$el.length) return;
            if ($el.attr('type') === 'checkbox') $el.prop('checked', r.data[k] == 1);
            else $el.val(r.data[k]);
        });
        f.find('[name=id]').val(id);
        if (ent === 'platform') $('#platformSimgeOnizleme').attr('class', 'bi ' + (r.data.ReklamPlatformlari_Simge || ''));
        if (ent === 'sayfa') loadSayfaBirimYetkileri(id);
        new bootstrap.Modal(ENT[ent].modal).show();
    });
}

function kaydet(ent) {
    const data = $(ENT[ent].form).serializeArray();
    const payload = { action: ent + '_kaydet' };
    data.forEach(i => payload[i.name] = i.value);
    // checkbox işaretli değilse serializeArray atlar; switch alanlarını netle
    if (ent === 'platform') payload['ReklamPlatformlari_Varsayilan'] = $('#platformVarsayilan').is(':checked') ? 1 : 0;
    if (ent === 'sayfa' && !BIRIM_KISITLI) payload['birim_yetkileri'] = JSON.stringify(collectSayfaBirimYetkileri());

    $.post(pageUrl, payload, function (r) {
        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }
        bootstrap.Modal.getInstance($(ENT[ent].modal)[0]).hide();
        Swal.fire({ icon: 'success', title: r.message, timer: 1200, showConfirmButton: false });
        listele(ent); statsYukle(); selectDoldur();
    });
}

// OAuth dönüşünde sonucu göster ve URL'yi temizle
function metaBaglanSonuc() {
    const p = new URLSearchParams(window.location.search);
    const d = p.get('meta_baglan');
    if (!d) return;
    if (d === 'ok') {
        Swal.fire({ icon: 'success', title: 'Facebook Bağlandı',
            html: `<b>${p.get('sayfa') || 0}</b> sayfa kaydedildi, <b>${p.get('abone') || 0}</b> tanesi leadgen'e abone oldu.` });
        statsYukle(); selectDoldur(); if (dt['sayfa']) listele('sayfa');
    } else if (d === 'iptal') {
        Swal.fire('İptal', 'Facebook izni verilmedi.', 'info');
    } else {
        Swal.fire('Hata', 'Bağlantı başarısız (' + (p.get('mesaj') || 'bilinmeyen') + ').', 'error');
    }
    history.replaceState(null, '', pageUrl);
}

// Meta senkronizasyonu Cron Yönetimi'ne taşındı (görev: meta_reklam_senkronize).

// ── Bağlantı Testi ──────────────────────────────────────────────────────────
let btModal = null;
function baglantiTestiAc() {
    if (!btModal) btModal = new bootstrap.Modal('#modalBaglantiTesti');
    btModal.show();
}
function baglantiTestiCalistir(subscribe) {
    const $s = $('#bt_sonuc');
    $s.html('<div class="text-center py-4"><div class="spinner-border text-primary"></div><div class="mt-2 text-muted">Meta Graph API sorgulanıyor...</div></div>');
    $.post(pageUrl, {
        action: 'baglanti_testi',
        test_form:     $('#bt_form').val(),
        test_business: $('#bt_business').val(),
        test_page:     $('#bt_page').val(),
        test_act:      $('#bt_act').val(),
        do_subscribe:  subscribe === true ? '1' : ''
    }, function (r) {
        if (!r.success) { $s.html('<div class="alert alert-danger">' + enc(r.message) + '</div>'); return; }
        $s.html(r.html);
        if (subscribe === true) Swal.fire({ icon: 'success', title: 'İşlem tamam', text: 'Abone durumu güncellendi.', timer: 1400, showConfirmButton: false });
    }).fail(function () {
        $s.html('<div class="alert alert-danger">İstek başarısız oldu.</div>');
    });
}

// ── Sayfa bazlı Webhook (leadgen) aboneliği ─────────────────────────────────
function sayfaWebhook(id, ad, subscribe) {
    Swal.fire({
        title: 'Webhook durumu sorgulanıyor...',
        html: '<b>' + enc(ad) + '</b>',
        allowOutsideClick: false, didOpen: () => Swal.showLoading()
    });
    $.post(pageUrl, { action: 'sayfa_webhook', id: id, do_subscribe: subscribe === true ? '1' : '' }, function (r) {
        if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }

        // Durum DB'ye yazıldı; tablo rozetini tazele
        if (dt['sayfa']) listele('sayfa');

        const rozet = r.abone
            ? '<span class="badge text-bg-success">ABONE ✓</span> Yeni lead\'ler webhook ile otomatik düşer.'
            : '<span class="badge text-bg-danger">ABONE DEĞİL ✗</span> Yeni başvurular otomatik gelmez.';
        const appsHtml = (r.apps && r.apps.length)
            ? '<div class="small text-muted mt-2">Abone uygulamalar:<br>' + r.apps.map(enc).join('<br>') + '</div>'
            : '<div class="small text-muted mt-2">Abone uygulama yok.</div>';
        const subInfo = r.subMesaj ? '<div class="alert alert-success py-1 px-2 small mt-2 mb-0">' + enc(r.subMesaj) + '</div>' : '';

        Swal.fire({
            title: enc(r.ad),
            html: '<div class="text-start">' + rozet + appsHtml + subInfo + '</div>',
            icon: r.abone ? 'success' : 'warning',
            showCancelButton: !r.abone && CAN.edit,
            confirmButtonText: (!r.abone && CAN.edit) ? 'Şimdi leadgen\'e abone et' : 'Kapat',
            cancelButtonText: 'Kapat',
            confirmButtonColor: r.abone ? '#3085d6' : '#d33'
        }).then(res => {
            if (res.isConfirmed && !r.abone && CAN.edit) sayfaWebhook(id, ad, true);
        });
    }).fail(function () { Swal.fire('Hata', 'İstek başarısız oldu.', 'error'); });
}

// ── Birim Yetkileri (junction) ──────────────────────────────────────────────
function birimBadges(s) {
    if (!s) return '<span class="text-muted">—</span>';
    return String(s).split(', ').map(a => `<span class="badge text-bg-secondary me-1">${enc(a)}</span>`).join('');
}

function loadBirimler() {
    $.post(pageUrl, { action: 'get_birimler' }, function (r) {
        if (!r.success) return;
        birimlerListesi = r.data;
        const $f = $('#fs_birim');
        if ($f.length) { r.data.forEach(b => $f.append(`<option value="${b.id}">${enc(b.ad)}</option>`)); }
    });
}

function sayfaBirimSatirHTML(birimId = '', baslangic = '', bitis = '') {
    const opts = birimlerListesi.map(b => `<option value="${b.id}" ${b.id == birimId ? 'selected' : ''}>${enc(b.ad)}</option>`).join('');
    return `<tr>
        <td><select class="form-select form-select-sm sayfa-birim-sel"><option value="">Seçiniz...</option>${opts}</select></td>
        <td><input type="date" class="form-control form-control-sm sayfa-birim-bas" value="${baslangic || ''}"></td>
        <td><input type="date" class="form-control form-control-sm sayfa-birim-bit" value="${bitis || ''}"></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()"><i class="bi bi-x"></i></button></td>
    </tr>`;
}

function sayfaBirimSatirEkle(birimId = '', baslangic = '', bitis = '') {
    const $row = $(sayfaBirimSatirHTML(birimId, baslangic, bitis));
    $('#sayfaBirimRows').append($row);
    $row.find('.sayfa-birim-sel').select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Seçiniz...', allowClear: true, dropdownParent: $('#modalSayfa') });
}

function loadSayfaBirimYetkileri(sayfaId) {
    $('#sayfaBirimRows').empty();
    if (!sayfaId || BIRIM_KISITLI) return;
    $.post(pageUrl, { action: 'sayfa_birim_yetkileri', id: sayfaId }, function (r) {
        if (r.success) r.data.forEach(x => sayfaBirimSatirEkle(x.KullaniciBirimYetkileri_Birim_id, x.BaslangicTarihi, x.BitisTarihi));
    });
}

function collectSayfaBirimYetkileri() {
    const rows = [];
    $('#sayfaBirimRows tr').each(function () {
        const birimId = $(this).find('.sayfa-birim-sel').val();
        if (birimId) rows.push({ birim_id: birimId, baslangic: $(this).find('.sayfa-birim-bas').val(), bitis: $(this).find('.sayfa-birim-bit').val() });
    });
    return rows;
}

// ── Excel indirme: tablodaki filtrelenmiş/sıralı satırlar (İşlem kolonu hariç) ──
function excelIndir(ent) {
    if (!dt[ent]) { Swal.fire('Uyarı', 'Tablo henüz yüklenmedi.', 'warning'); return; }
    const sayfaAdlari = { platform: 'Platformlar', hesap: 'Hesaplar', kampanya: 'Kampanyalar', sayfa: 'Sayfalar', form: 'Lead Formlari' };
    const $th = $(ENT[ent].tbl + ' thead th');
    const son = $th.length - 1; // son kolon: İşlem
    const basliklar = $th.slice(0, son).map((i, el) => $(el).text().trim()).get();
    const satirlar = dt[ent].rows({ search: 'applied', order: 'applied' }).nodes().toArray().map(tr =>
        $(tr).children('td').slice(0, son).map((i, td) => {
            const t = $(td).text().replace(/\s+/g, ' ').trim();
            return (i === 0 && /^\d+$/.test(t)) ? Number(t) : t;
        }).get()
    );
    if (!satirlar.length) { Swal.fire('Uyarı', 'İndirilecek kayıt yok.', 'warning'); return; }

    const ws = XLSX.utils.aoa_to_sheet([basliklar, ...satirlar]);
    ws['!cols'] = basliklar.map((b, i) => ({ wch: Math.min(50, Math.max(b.length, ...satirlar.map(r => String(r[i] ?? '').length)) + 2) }));
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, sayfaAdlari[ent]);
    XLSX.writeFile(wb, `reklam_${ent}_${new Date().toISOString().slice(0, 10)}.xlsx`);
}

function sil(ent, id, ad) {
    Swal.fire({
        title: 'Emin misiniz?', html: `<b>${ad}</b> silinecek.`, icon: 'warning',
        showCancelButton: true, confirmButtonText: 'Evet, sil', cancelButtonText: 'İptal', confirmButtonColor: '#d33'
    }).then(res => {
        if (!res.isConfirmed) return;
        $.post(pageUrl, { action: ent + '_sil', id: id }, function (r) {
            if (!r.success) { Swal.fire('Hata', r.message, 'error'); return; }
            Swal.fire({ icon: 'success', title: r.message, timer: 1200, showConfirmButton: false });
            listele(ent); statsYukle(); selectDoldur();
        });
    });
}

// ── Lead Formu Alan Eşleme ──────────────────────────────────────────────────
let AE_HEDEFLER = [];

function alanEslemeAc(formId) {
    $('#aeSatirlar').html('<tr><td colspan="4" class="text-center text-muted py-3">Meta sorguları çekiliyor…</td></tr>');
    $('#aeUyari').empty();
    $('#aeFormId').val(formId);
    $('#aeFormAdi').text('');
    $('#aeSayfaAdi').text('');
    new bootstrap.Modal('#modalAlanEsleme').show();

    $.post(pageUrl, { action: 'form_alanlari', id: formId }, function (r) {
        if (!r.success) {
            $('#aeSatirlar').html(`<tr><td colspan="4" class="text-danger text-center py-3">${enc(r.message)}</td></tr>`);
            return;
        }
        const d = r.data;
        AE_HEDEFLER = d.hedefler || [];
        $('#aeFormAdi').text(d.form_adi || '');
        $('#aeBirimSayfaAdi').text(d.sayfa_adi || 'sayfanın');
        $('#aeBirimRows').empty();
        (d.birimler || []).forEach(b => aeBirimSatirEkle(b.birim_id, b.baslangic, b.bitis));
        $('#aeSayfaAdi').text(d.sayfa_adi ? 'Sayfa: ' + d.sayfa_adi : '');

        if (d.meta_hata) {
            $('#aeUyari').html(`<div class="alert alert-warning py-2 px-3 small mb-2">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Meta'dan güncel sorular çekilemedi: ${enc(d.meta_hata)}<br>
                Aşağıda daha önce kaydedilmiş alanlar gösteriliyor.</div>`);
        }

        alanEslemeSatirlariCiz(d.alanlar || []);
    }, 'json').fail(function () {
        $('#aeSatirlar').html('<tr><td colspan="4" class="text-danger text-center py-3">Sunucu hatası.</td></tr>');
    });
}

function alanEslemeSatirlariCiz(alanlar) {
    if (!alanlar.length) {
        $('#aeSatirlar').html('<tr><td colspan="4" class="text-center text-muted py-3">Bu formda soru bulunamadı.</td></tr>');
        return;
    }

    const secenekler = ['<option value="">— Eşleme yok —</option>']
        .concat(AE_HEDEFLER.map(h => `<option value="${enc(h.kolon)}">${enc(h.etiket)}</option>`))
        .join('');

    const html = alanlar.map(a => {
        const pasif = String(a.durum) !== '1';
        return `<tr class="${pasif ? 'table-secondary' : ''}" data-satir="${a.id}">
            <td>
                <div>${enc(a.soru || '—')}</div>
                ${pasif ? '<small class="text-muted"><i class="bi bi-slash-circle"></i> Meta\'da artık yok</small>' : ''}
            </td>
            <td><small class="text-muted">${enc(a.alan)}</small></td>
            <td><select class="ae-hedef" data-satir="${a.id}" style="width:100%">${secenekler}</select></td>
            <td><input type="number" class="form-control form-control-sm ae-sira" data-satir="${a.id}"
                       value="${parseInt(a.sira || 0, 10)}" min="0" step="1"></td>
        </tr>`;
    }).join('');

    $('#aeSatirlar').html(html);

    // Kayıtlı hedefleri seç, sonra Select2'yi modal içinde kur
    alanlar.forEach(a => {
        $(`.ae-hedef[data-satir="${a.id}"]`).val(a.hedef || '');
    });

    $('.ae-hedef').each(function () {
        if ($(this).hasClass('select2-hidden-accessible')) $(this).select2('destroy');
        $(this).select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: $('#modalAlanEsleme'),
            placeholder: '— Eşleme yok —',
            allowClear: true
        });
    });
}


function aeBirimSatirEkle(birimId = '', baslangic = '', bitis = '') {
    if (typeof BIRIM_KISITLI !== 'undefined' && BIRIM_KISITLI) return;
    const opts = birimlerListesi.map(b => `<option value="${b.id}" ${b.id == birimId ? 'selected' : ''}>${enc(b.ad)}</option>`).join('');
    const $row = $(`<tr>
        <td><select class="form-select form-select-sm ae-birim-sel"><option value="">Seçiniz...</option>${opts}</select></td>
        <td><input type="date" class="form-control form-control-sm ae-birim-bas" value="${baslangic || ''}"></td>
        <td><input type="date" class="form-control form-control-sm ae-birim-bit" value="${bitis || ''}"></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()"><i class="bi bi-x"></i></button></td>
    </tr>`);
    $('#aeBirimRows').append($row);
    $row.find('.ae-birim-sel').select2({
        theme: 'bootstrap-5', width: '100%', placeholder: 'Seçiniz...',
        allowClear: true, dropdownParent: $('#modalAlanEsleme')
    });
}

function aeBirimleriTopla() {
    const rows = [];
    $('#aeBirimRows tr').each(function () {
        const birimId = $(this).find('.ae-birim-sel').val();
        if (birimId) rows.push({
            birim_id:  birimId,
            baslangic: $(this).find('.ae-birim-bas').val(),
            bitis:     $(this).find('.ae-birim-bit').val()
        });
    });
    return rows;
}
function alanEslemeKaydet() {
    const formId = $('#aeFormId').val();
    const eslemeler = [];

    $('#aeSatirlar tr[data-satir]').each(function () {
        const id = $(this).data('satir');
        eslemeler.push({
            id:    id,
            hedef: $(`.ae-hedef[data-satir="${id}"]`).val() || '',
            sira:  parseInt($(`.ae-sira[data-satir="${id}"]`).val() || 0, 10)
        });
    });

    if (!eslemeler.length) { Swal.fire('Uyarı', 'Kaydedilecek alan yok.', 'warning'); return; }

    Swal.fire({
        title: 'Kaydediliyor',
        html: 'Eşleme yazılıyor, geçmiş başvurular Meta\'dan yeniden çekiliyor…<br><small class="text-muted">Kayıt sayısına göre bir kaç dakika sürebilir.</small>',
        allowOutsideClick: false, allowEscapeKey: false,
        didOpen: () => Swal.showLoading()
    });

    $.post(pageUrl, {
        action: 'form_alanlari_kaydet',
        id: formId,
        eslemeler: JSON.stringify(eslemeler),
        birim_yetkileri: JSON.stringify(aeBirimleriTopla())
    }, function (r) {
        if (!r.success) { Swal.fire('Hata', r.message || 'Kaydedilemedi.', 'error'); return; }
        bootstrap.Modal.getInstance(document.getElementById('modalAlanEsleme')).hide();
        listele('form');
        Swal.fire({ icon: 'success', title: 'Kaydedildi', text: r.message });
    }, 'json').fail(function () {
        Swal.fire('Hata', 'Sunucu hatası.', 'error');
    });
}
</script>
</body>
</html>
