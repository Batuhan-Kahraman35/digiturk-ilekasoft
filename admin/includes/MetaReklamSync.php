<?php
/**
 * Meta Reklam Senkronizasyonu
 *
 * Graph API'den (System User token) Hesap / Kampanya / Facebook Sayfası / Lead Formu
 * verilerini çekip Reklam* tablolarına UPSERT eder (Facebook ID anahtarıyla).
 *
 * FK kuralı:
 *  - Sayfa→Kampanya bağı Meta'da yok → MANUEL (senkron dokunmaz).
 *  - Form→Sayfa bağı Meta'dan gelir → yeni kayıtta OTOMATIK bağlanır,
 *    mevcut kayıtta korunur (kullanıcı değiştirmişse üzerine yazılmaz).
 */

require_once __DIR__ . '/../db.php';

class MetaReklamSync
{
    private static function db(): Database
    {
        return Database::getInstance();
    }

    /** Aktif Meta kanalını döndürür (AppId / Token / BaseURL / AppSecret). */
    private static function kanal(): ?array
    {
        return self::db()->fetchOne("
            SELECT k.EntegrasyonKanallari_Instance AS AppId,
                   k.EntegrasyonKanallari_Sifre    AS Token,
                   e.Entegrasyonlar_BaseURL        AS BaseURL,
                   e.Entegrasyonlar_ApiKey         AS AppSecret
            FROM EntegrasyonKanallari k
            INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
            WHERE e.Entegrasyonlar_Tip = 'meta' AND k.Durum = 1 AND e.Durum = 1
            ORDER BY k.EntegrasyonKanallari_id
        ");
    }

    private static function proof(string $token, string $secret): string
    {
        return hash_hmac('sha256', $token, $secret);
    }

    /** Tek Graph GET çağrısı. */
    private static function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $cevap = curl_exec($ch);
        $kod   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hata  = curl_error($ch);
        unset($ch);

        $json = json_decode($cevap, true) ?: [];
        return ['ok' => ($kod >= 200 && $kod < 300 && !$hata), 'data' => $json, 'kod' => $kod, 'hata' => $hata];
    }

    /** Sayfalı (paging.next) sonucu tümüyle toplar. */
    private static function getAll(string $baseURL, string $path, array $params, string $token, string $secret): array
    {
        $params['access_token']    = $token;
        $params['appsecret_proof'] = self::proof($token, $secret);
        $params['limit']           = 200;
        $url = $baseURL . '/' . ltrim($path, '/') . '?' . http_build_query($params);

        $tum = []; $guvenlik = 0;
        while ($url && $guvenlik < 50) {
            $r = self::get($url);
            if (!$r['ok']) {
                throw new Exception($r['data']['error']['message'] ?? ('HTTP ' . $r['kod']));
            }
            foreach (($r['data']['data'] ?? []) as $satir) $tum[] = $satir;
            $url = $r['data']['paging']['next'] ?? null;
            $guvenlik++;
        }
        return $tum;
    }

    private static function metaPlatformId(): int
    {
        $r = self::db()->fetchOne("SELECT TOP 1 ReklamPlatformlari_id FROM ReklamPlatformlari WHERE ReklamPlatformlari_Adi = N'Meta Ads' AND Durum = 1");
        return (int)($r['ReklamPlatformlari_id'] ?? 0);
    }

    /** Hepsini sırayla senkronize eder (sayfa, formdan önce gelir). */
    public static function tumunuSenkronize(int $kullaniciId = 1): array
    {
        $kanal = self::kanal();
        if (!$kanal) throw new Exception('Aktif Meta kanalı bulunamadı.');

        $platformId = self::metaPlatformId();
        if (!$platformId) throw new Exception('Meta Ads platformu bulunamadı (ReklamPlatformlari).');

        $baseURL = rtrim($kanal['BaseURL'], '/');
        $token   = $kanal['Token'];
        $secret  = $kanal['AppSecret'];

        return [
            'hesap'    => self::syncHesaplar($baseURL, $token, $secret, $platformId, $kullaniciId),
            'kampanya' => self::syncKampanyalar($baseURL, $token, $secret, $kullaniciId),
            'sayfa'    => self::syncSayfalar($baseURL, $token, $secret, $kullaniciId),
            'form'     => self::syncFormlar($baseURL, $token, $secret, $kullaniciId),
        ];
    }

    /**
     * Self-healing: Basvurular_LeadgenID dolu ama ReklamLeadFormlari_ID NULL kalmış
     * başvuruları onarır. Webhook lead'i, formu daha ReklamLeadFormlari'da yokken
     * kaydederse FK NULL kalır. Burada leadgen_id ile Graph'tan form_id yeniden
     * çekilip tablodaki formla eşleştirilir.
     *
     * NOT: Meta leadgen verisi ~90 gün sonra Graph'tan silinir; o kayıtlar onarılamaz.
     * Senkronizasyondan SONRA çağrılmalıdır (yeni formlar tabloya girmiş olur).
     */
    public static function basvuruFormlariniOnar(int $kullaniciId = 1): array
    {
        $kanal = self::kanal();
        if (!$kanal) throw new Exception('Aktif Meta kanalı bulunamadı.');

        $baseURL = rtrim($kanal['BaseURL'], '/');
        $token   = $kanal['Token'];
        $secret  = $kanal['AppSecret'];

        $kayitlar = self::db()->fetchAll("
            SELECT Basvurular_id, Basvurular_LeadgenID
            FROM Basvurular
            WHERE Basvurular_LeadgenID IS NOT NULL AND ReklamLeadFormlari_ID IS NULL
        ");

        // Aday token'lar: sistem token + DB'de token'ı kayıtlı sayfalar (me/accounts dışı sayfaların
        // lead'lerini yalnızca kendi page token'ı okuyabilir). Son başarılı token başa alınır.
        $adaylar = [['token' => $token, 'secret' => $secret]];
        $dbSayfalar = self::db()->fetchAll("
            SELECT DISTINCT s.ReklamFacebookSayfalari_Token AS token, e.Entegrasyonlar_ApiKey AS secret
            FROM ReklamFacebookSayfalari s
            LEFT JOIN Entegrasyonlar e ON e.Entegrasyonlar_id = s.ReklamFacebookSayfalari_Entegrasyon_id
            WHERE s.Durum = 1 AND LEN(ISNULL(s.ReklamFacebookSayfalari_Token, '')) > 0
        ") ?: [];
        foreach ($dbSayfalar as $d) {
            $adaylar[] = ['token' => $d['token'], 'secret' => $d['secret'] ?: $secret];
        }

        $onarilan = 0; $formYok = 0; $hata = 0;
        foreach ($kayitlar as $k) {
            $leadgenId = trim((string)$k['Basvurular_LeadgenID']);
            if ($leadgenId === '') { $hata++; continue; }

            $r = ['ok' => false];
            foreach ($adaylar as $i => $a) {
                $r = self::get($baseURL . '/' . $leadgenId . '?' . http_build_query([
                    'fields'          => 'form_id',
                    'access_token'    => $a['token'],
                    'appsecret_proof' => self::proof($a['token'], $a['secret']),
                ]));
                if ($r['ok']) {
                    if ($i > 0) { unset($adaylar[$i]); array_unshift($adaylar, $a); }
                    break;
                }
            }
            if (!$r['ok']) { $hata++; continue; }            // veri silinmiş/erişilemez

            $formId = (string)($r['data']['form_id'] ?? '');
            if ($formId === '') { $hata++; continue; }

            $f = self::db()->fetchOne(
                "SELECT ReklamLeadFormlari_id FROM ReklamLeadFormlari WHERE ReklamLeadFormlari_FormID = ?",
                [$formId]
            );
            if (!$f) { $formYok++; continue; }               // sync sonrası hâlâ yoksa atla

            self::db()->update('Basvurular', [
                'ReklamLeadFormlari_ID' => $f['ReklamLeadFormlari_id'],
                'GuncelleyenKullanici'  => $kullaniciId,
                'GuncellemeTarihi'      => date('Y-m-d H:i:s'),
            ], ['Basvurular_id' => $k['Basvurular_id']]);
            $onarilan++;
        }

        return ['toplam' => count($kayitlar), 'onarilan' => $onarilan, 'formYok' => $formYok, 'hata' => $hata];
    }

    // ─── Hesaplar: /me/adaccounts ───────────────────────────────────────────
    private static function syncHesaplar($baseURL, $token, $secret, $platformId, $kullaniciId): array
    {
        $eklenen = 0; $guncellenen = 0;
        $liste = self::getAll($baseURL, 'me/adaccounts', ['fields' => 'id,name,account_status'], $token, $secret);

        foreach ($liste as $h) {
            $hesapId = $h['id'] ?? '';            // act_xxx
            if ($hesapId === '') continue;

            $mevcut = self::db()->fetchOne("SELECT ReklamHesaplari_id FROM ReklamHesaplari WHERE ReklamHesaplari_HesapID = ?", [$hesapId]);
            $veri = [
                'ReklamHesaplari_Platform_id' => $platformId,
                'ReklamHesaplari_HesapAdi'    => $h['name'] ?? $hesapId,
                'ReklamHesaplari_HesapID'     => $hesapId,
                'ReklamHesaplari_HesapDurumu' => $h['account_status'] ?? null,
            ];
            if ($mevcut) {
                $veri['GuncelleyenKullanici'] = $kullaniciId;
                $veri['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                self::db()->update('ReklamHesaplari', $veri, ['ReklamHesaplari_id' => $mevcut['ReklamHesaplari_id']]);
                $guncellenen++;
            } else {
                $veri['OlusturanKullanici'] = $kullaniciId;
                $veri['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                $veri['Durum']              = 1;
                self::db()->insert('ReklamHesaplari', $veri);
                $eklenen++;
            }
        }
        return ['eklenen' => $eklenen, 'guncellenen' => $guncellenen, 'toplam' => count($liste)];
    }

    // ─── Kampanyalar: /act_xxx/campaigns ────────────────────────────────────
    private static function syncKampanyalar($baseURL, $token, $secret, $kullaniciId): array
    {
        $eklenen = 0; $guncellenen = 0; $toplam = 0;
        $hesaplar = self::db()->fetchAll("
            SELECT ReklamHesaplari_id, ReklamHesaplari_HesapID
            FROM ReklamHesaplari
            WHERE ReklamHesaplari_HesapID LIKE 'act[_]%' AND Durum = 1
        ");

        foreach ($hesaplar as $hesap) {
            try {
                $kampanyalar = self::getAll($baseURL, $hesap['ReklamHesaplari_HesapID'] . '/campaigns',
                    ['fields' => 'id,name,status,objective'], $token, $secret);
            } catch (Exception $e) {
                continue; // erişilemeyen hesabı atla
            }
            foreach ($kampanyalar as $k) {
                $toplam++;
                $mevcut = self::db()->fetchOne("SELECT ReklamKampanyalari_id FROM ReklamKampanyalari WHERE ReklamKampanyalari_KampanyaID = ?", [$k['id']]);
                $veri = [
                    'ReklamKampanyalari_Hesap_id'       => $hesap['ReklamHesaplari_id'],
                    'ReklamKampanyalari_KampanyaAdi'    => $k['name'] ?? '',
                    'ReklamKampanyalari_KampanyaID'     => $k['id'],
                    'ReklamKampanyalari_Kitle'          => $k['objective'] ?? null,
                    'ReklamKampanyalari_KampanyaDurumu' => $k['status'] ?? null,
                ];
                if ($mevcut) {
                    $veri['GuncelleyenKullanici'] = $kullaniciId;
                    $veri['GuncellemeTarihi']     = date('Y-m-d H:i:s');
                    self::db()->update('ReklamKampanyalari', $veri, ['ReklamKampanyalari_id' => $mevcut['ReklamKampanyalari_id']]);
                    $guncellenen++;
                } else {
                    $veri['OlusturanKullanici'] = $kullaniciId;
                    $veri['OlusturmaTarihi']    = date('Y-m-d H:i:s');
                    $veri['Durum']              = 1;
                    self::db()->insert('ReklamKampanyalari', $veri);
                    $eklenen++;
                }
            }
        }
        return ['eklenen' => $eklenen, 'guncellenen' => $guncellenen, 'toplam' => $toplam];
    }

    // ─── Sayfalar: /me/accounts (Kampanya_id MANUEL, dokunulmaz) ────────────
    private static function syncSayfalar($baseURL, $token, $secret, $kullaniciId): array
    {
        $eklenen = 0; $guncellenen = 0;
        $liste = self::getAll($baseURL, 'me/accounts', ['fields' => 'id,name'], $token, $secret);

        foreach ($liste as $s) {
            $sayfaId = $s['id'] ?? '';
            if ($sayfaId === '') continue;

            $mevcut = self::db()->fetchOne("SELECT ReklamFacebookSayfalari_id FROM ReklamFacebookSayfalari WHERE ReklamFacebookSayfalari_SayfaID = ?", [$sayfaId]);
            if ($mevcut) {
                self::db()->update('ReklamFacebookSayfalari', [
                    'ReklamFacebookSayfalari_SayfaAdi' => $s['name'] ?? '',
                    'GuncelleyenKullanici'             => $kullaniciId,
                    'GuncellemeTarihi'                 => date('Y-m-d H:i:s'),
                ], ['ReklamFacebookSayfalari_id' => $mevcut['ReklamFacebookSayfalari_id']]);
                $guncellenen++;
            } else {
                self::db()->insert('ReklamFacebookSayfalari', [
                    'ReklamFacebookSayfalari_Kampanya_id' => null, // manuel bağlanacak
                    'ReklamFacebookSayfalari_SayfaAdi'    => $s['name'] ?? '',
                    'ReklamFacebookSayfalari_SayfaID'     => $sayfaId,
                    'OlusturanKullanici'                  => $kullaniciId,
                    'OlusturmaTarihi'                     => date('Y-m-d H:i:s'),
                    'Durum'                               => 1,
                ]);
                $eklenen++;
            }
        }
        return ['eklenen' => $eklenen, 'guncellenen' => $guncellenen, 'toplam' => count($liste)];
    }

    // ─── Formlar: /{page}/leadgen_forms (Page Token; Sayfa_id otomatik eşleşir) ─
    private static function syncFormlar($baseURL, $token, $secret, $kullaniciId): array
    {
        $eklenen = 0; $guncellenen = 0; $toplam = 0;
        $sayfalar = self::getAll($baseURL, 'me/accounts', ['fields' => 'id,name,access_token'], $token, $secret);
        foreach ($sayfalar as &$s) $s['_secret'] = $secret;
        unset($s);

        // me/accounts'ta görünmeyen, DB'ye kendi token'ıyla (elle / OAuth) eklenmiş sayfalar.
        // Token hangi app'e aitse appsecret_proof o entegrasyonun secret'ıyla üretilir.
        $gorulen = array_column($sayfalar, 'id');
        $dbSayfalar = self::db()->fetchAll("
            SELECT s.ReklamFacebookSayfalari_SayfaID AS id,
                   s.ReklamFacebookSayfalari_Token   AS access_token,
                   e.Entegrasyonlar_ApiKey           AS _secret
            FROM ReklamFacebookSayfalari s
            LEFT JOIN Entegrasyonlar e ON e.Entegrasyonlar_id = s.ReklamFacebookSayfalari_Entegrasyon_id
            WHERE s.Durum = 1 AND LEN(ISNULL(s.ReklamFacebookSayfalari_Token, '')) > 0
        ") ?: [];
        foreach ($dbSayfalar as $d) {
            if (in_array($d['id'], $gorulen, true)) continue;
            $d['_secret'] = $d['_secret'] ?: $secret;
            $sayfalar[] = $d;
        }

        foreach ($sayfalar as $s) {
            $pageToken = $s['access_token'] ?? null;
            if (!$pageToken) continue;

            // Otomatik FK için bizdeki sayfa kaydı
            $bizimSayfa = self::db()->fetchOne(
                "SELECT ReklamFacebookSayfalari_id FROM ReklamFacebookSayfalari WHERE ReklamFacebookSayfalari_SayfaID = ?",
                [$s['id'] ?? '']
            );
            $sayfaFk = $bizimSayfa['ReklamFacebookSayfalari_id'] ?? null;

            try {
                $formlar = self::getAll($baseURL, ($s['id'] ?? '') . '/leadgen_forms',
                    ['fields' => 'id,name,status'], $pageToken, $s['_secret']);
            } catch (Exception $e) {
                continue; // form izni olmayan / erişilemeyen sayfayı atla
            }

            foreach ($formlar as $f) {
                $toplam++;
                $mevcut = self::db()->fetchOne("SELECT ReklamLeadFormlari_id FROM ReklamLeadFormlari WHERE ReklamLeadFormlari_FormID = ?", [$f['id']]);
                if ($mevcut) {
                    // Sayfa_id'ye DOKUNMA (kullanıcının manuel seçimi korunur)
                    self::db()->update('ReklamLeadFormlari', [
                        'ReklamLeadFormlari_FormAdi'    => $f['name'] ?? '',
                        'ReklamLeadFormlari_FormDurumu' => $f['status'] ?? null,
                        'GuncelleyenKullanici'          => $kullaniciId,
                        'GuncellemeTarihi'              => date('Y-m-d H:i:s'),
                    ], ['ReklamLeadFormlari_id' => $mevcut['ReklamLeadFormlari_id']]);
                    $guncellenen++;
                } else {
                    self::db()->insert('ReklamLeadFormlari', [
                        'ReklamLeadFormlari_Sayfa_id'   => $sayfaFk, // otomatik eşleşme (yoksa NULL)
                        'ReklamLeadFormlari_FormAdi'    => $f['name'] ?? '',
                        'ReklamLeadFormlari_FormID'     => $f['id'],
                        'ReklamLeadFormlari_FormDurumu' => $f['status'] ?? null,
                        'OlusturanKullanici'            => $kullaniciId,
                        'OlusturmaTarihi'               => date('Y-m-d H:i:s'),
                        'Durum'                         => 1,
                    ]);
                    $eklenen++;
                }
            }
        }
        return ['eklenen' => $eklenen, 'guncellenen' => $guncellenen, 'toplam' => $toplam];
    }

    // ─── Günlük Harcamalar (Odemeler cron'u için) ───────────────────────────
    /**
     * Belirtilen dönemin hesap bazlı harcamalarını döndürür (varsayılan: bugün).
     * Her eleman: ['act_id','ad','currency','spend','birim_id'].
     * Birim, ReklamHesaplari + KullaniciBirimYetkileri üzerinden çözülür.
     */
    public static function gunlukHarcamalar(string $donem = 'today'): array
    {
        $kanal = self::kanal();
        if (!$kanal) throw new Exception('Aktif Meta kanalı bulunamadı.');

        $baseURL = rtrim($kanal['BaseURL'], '/');
        $token   = $kanal['Token'];
        $secret  = $kanal['AppSecret'];

        $hesaplar = self::getAll($baseURL, 'me/adaccounts',
            ['fields' => 'id,name,currency'], $token, $secret);

        $birimMap = self::hesapBirimMap();

        $sonuc = [];
        foreach ($hesaplar as $h) {
            $act = $h['id'] ?? '';
            if ($act === '') continue;

            $url = $baseURL . '/' . $act . '/insights?' . http_build_query([
                'fields'          => 'spend',
                'date_preset'     => $donem,
                'access_token'    => $token,
                'appsecret_proof' => self::proof($token, $secret),
            ]);
            $r = self::get($url);
            $spend = $r['ok'] ? (float)($r['data']['data'][0]['spend'] ?? 0) : 0.0;

            $sonuc[] = [
                'act_id'   => $act,
                'ad'       => $h['name'] ?? $act,
                'currency' => $h['currency'] ?? '',
                'spend'    => $spend,
                'birim_id' => $birimMap[$act] ?? null,
            ];
        }
        return $sonuc;
    }

    /**
     * Belirli bir günün (Y-m-d) hesap bazlı harcama + lead sayısı (canlı insights).
     * spend KDV hariç, ana birimde (TL). Lead = actions içindeki 'lead' tipi.
     * Her eleman: ['act_id','ad','currency','spend','lead'].
     */
    public static function gunHarcamaLead(string $tarih): array
    {
        $kanal = self::kanal();
        if (!$kanal) throw new Exception('Aktif Meta kanalı bulunamadı.');

        $baseURL = rtrim($kanal['BaseURL'], '/');
        $token   = $kanal['Token'];
        $secret  = $kanal['AppSecret'];

        $hesaplar = self::getAll($baseURL, 'me/adaccounts', ['fields' => 'id,name,currency'], $token, $secret);

        $sonuc = [];
        foreach ($hesaplar as $h) {
            $act = $h['id'] ?? '';
            if ($act === '') continue;

            $r = self::get($baseURL . '/' . $act . '/insights?' . http_build_query([
                'fields'          => 'spend,actions',
                'time_range'      => json_encode(['since' => $tarih, 'until' => $tarih]),
                'access_token'    => $token,
                'appsecret_proof' => self::proof($token, $secret),
            ]));
            $satir = $r['ok'] ? ($r['data']['data'][0] ?? []) : [];
            $lead  = 0;
            foreach ($satir['actions'] ?? [] as $ac) {
                if (($ac['action_type'] ?? '') === 'lead') $lead = (int)$ac['value'];
            }

            $sonuc[] = [
                'act_id'   => $act,
                'ad'       => $h['name'] ?? $act,
                'currency' => $h['currency'] ?? '',
                'spend'    => (float)($satir['spend'] ?? 0),
                'lead'     => $lead,
            ];
        }
        return $sonuc;
    }

    /** act_id (act_xxx) => KullaniciBirim_id (ilk aktif yetki). */
    private static function hesapBirimMap(): array
    {
        $rows = self::db()->fetchAll("
            SELECT rh.ReklamHesaplari_HesapID AS HesapID,
                   (SELECT TOP 1 kby.KullaniciBirimYetkileri_Birim_id
                      FROM KullaniciBirimYetkileri kby
                     WHERE kby.KullaniciBirimYetkileri_ReklamHesap_id = rh.ReklamHesaplari_id
                       AND kby.Durum = 1
                       AND (kby.KullaniciBirimYetkileri_BaslangicTarihi IS NULL OR kby.KullaniciBirimYetkileri_BaslangicTarihi <= GETDATE())
                       AND (kby.KullaniciBirimYetkileri_BitisTarihi     IS NULL OR kby.KullaniciBirimYetkileri_BitisTarihi     >= GETDATE())
                     ORDER BY kby.KullaniciBirimYetkileri_id
                   ) AS Birim_id
            FROM ReklamHesaplari rh
            WHERE rh.Durum = 1
        ");
        $map = [];
        foreach ($rows as $r) {
            if ($r['Birim_id'] !== null && $r['Birim_id'] !== '') {
                $map[$r['HesapID']] = (int)$r['Birim_id'];
            }
        }
        return $map;
    }
}
