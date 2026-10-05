<?php
/**
 * Destek (Ticket) Entegrasyon Yardımcısı
 * Kaynak API: destek.ornekyazilim.com  (X-API-KEY)
 *
 * - API key/URL DB'den okunur: tanim_site_ayarlari.site_ayarlari_destek_api_key / _url
 * - Oturumdaki personel (Auth::user) API 'kullanici' objesine maplenir.
 * - JSON ve multipart (CURLFile) dosya yükleme desteklenir.
 *
 * Actionlar: ticket_meta, list_tickets, ticket_detail, ticket_messages,
 *            create_ticket, reply_ticket
 */

class DestekHelper
{
    private static ?Database $db = null;
    private static ?string $apiKey = null;
    private static ?string $apiUrl = null;
    private static ?array  $kullaniciCache = null;

    /** Son API çağrısının ham teknik bilgisi (debug için) */
    private static array $sonDebug = [];

    public static function sonDebug(): array
    {
        return self::$sonDebug;
    }

    /** Dosya başına izinli maksimum boyut (byte) */
    private const MAX_DOSYA_BOYUTU = 20 * 1024 * 1024; // 20 MB

    /** İzinli MIME tipleri (finfo ile gerçek içerik kontrolü) */
    private const IZINLI_MIME = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private static function db(): Database
    {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
        return self::$db;
    }

    // ─── Yapılandırma (DB'den) ──────────────────────────────────────────────

    /** API key ve URL'i site_ayarlari'ndan bir kez okur. */
    private static function yapilandirmaYukle(): void
    {
        if (self::$apiKey !== null && self::$apiUrl !== null) {
            return;
        }
        $ayar = self::db()->fetchOne("
            SELECT TOP 1 site_ayarlari_destek_api_key, site_ayarlari_destek_api_url
            FROM dbo.tanim_site_ayarlari
            ORDER BY site_ayarlari_id DESC
        ");
        self::$apiKey = trim((string)($ayar['site_ayarlari_destek_api_key'] ?? ''));
        self::$apiUrl = trim((string)($ayar['site_ayarlari_destek_api_url'] ?? ''));
    }

    /** Entegrasyon yapılandırılmış mı? (key + url dolu mu) */
    public static function aktifMi(): bool
    {
        self::yapilandirmaYukle();
        return self::$apiKey !== '' && self::$apiUrl !== '';
    }

    // ─── Oturumdaki personel → API kullanici objesi ─────────────────────────

    /**
     * Auth::user() ID'sinden ad/soyad/telefon'u DB'den çekip API formatına çevirir.
     * kaynak_kullanici_id = kullanici_id (panel personel ID'si)
     */
    private static function kullaniciBilgisi(): array
    {
        if (self::$kullaniciCache !== null) {
            return self::$kullaniciCache;
        }

        // Cron/CLI bağlamında oturum yoktur; Auth sınıfı yüklü olmayabilir.
        $u  = class_exists('Auth') ? Auth::user() : null;
        $id = (int)($u['kullanici_id'] ?? 0);

        $satir = $id > 0 ? self::db()->fetchOne("
            SELECT kullanici_ad, kullanici_soyad, kullanici_email, kullanici_telefon
            FROM kullanicilar WHERE kullanici_id = ?
        ", [$id]) : null;

        self::$kullaniciCache = [
            'kaynak_kullanici_id' => (string)$id,
            'ad'                  => $satir['kullanici_ad'] ?? '',
            'soyad'               => $satir['kullanici_soyad'] ?? '',
            'eposta'              => $satir['kullanici_email'] ?? ($u['kullanici_email'] ?? ''),
            'telefon'             => $satir['kullanici_telefon'] ?? '',
        ];
        return self::$kullaniciCache;
    }

    /**
     * Oturumsuz bağlamlarda (cron, CLI) talebi açacak personeli elle atar.
     * Auth::user() çağrılmadan cache doldurulur.
     */
    public static function kullaniciAta(int $kullaniciId): bool
    {
        $satir = self::db()->fetchOne("
            SELECT kullanici_ad, kullanici_soyad, kullanici_email, kullanici_telefon
            FROM kullanicilar WHERE kullanici_id = ?
        ", [$kullaniciId]);

        if (!$satir || trim((string)($satir['kullanici_email'] ?? '')) === '') {
            return false;
        }

        self::$kullaniciCache = [
            'kaynak_kullanici_id' => (string)$kullaniciId,
            'ad'                  => $satir['kullanici_ad'] ?? '',
            'soyad'               => $satir['kullanici_soyad'] ?? '',
            'eposta'              => $satir['kullanici_email'],
            'telefon'             => $satir['kullanici_telefon'] ?? '',
        ];
        return true;
    }

    /** Oturumdaki personelin e-postası (list/detay filtrelerinde kullanılır). */
    public static function eposta(): string
    {
        return self::kullaniciBilgisi()['eposta'];
    }

    // ─── Düşük seviye API çağrısı ───────────────────────────────────────────

    /**
     * API'ye POST isteği. $files boş değilse multipart/form-data (CURLFile),
     * aksi halde application/json gönderir.
     *
     * @param array $files ['dosyalar' => $_FILES['dosyalar']] formatında
     * @return array API yanıtı (success/message/data/errors)
     */
    public static function apiCall(string $action, array $payload = [], array $files = []): array
    {
        self::yapilandirmaYukle();
        if (!self::aktifMi()) {
            return ['success' => false, 'message' => 'Destek API yapılandırılmamış (site ayarları).'];
        }

        $payload['action'] = $action;
        $headers  = ['X-API-KEY: ' . self::$apiKey];
        $multipart = !empty($files['dosyalar']) && isset($files['dosyalar']['name']) && is_array($files['dosyalar']['name']);

        if ($multipart) {
            $postData = [];
            foreach ($payload as $k => $v) {
                $postData[$k] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
            }
            $count = count($files['dosyalar']['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($files['dosyalar']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                $postData["dosyalar[$i]"] = new CURLFile(
                    $files['dosyalar']['tmp_name'][$i],
                    $files['dosyalar']['type'][$i],
                    $files['dosyalar']['name'][$i]
                );
            }
        } else {
            $postData   = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $headers[]  = 'Content-Type: application/json';
        }
        $headers[] = 'Accept: application/json';

        $ch = curl_init(self::$apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'DigiturkOrnekYazilim-Destek/1.0 (+PHP cURL)',
        ]);
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Debug bilgisi (sonDebug ile okunur)
        self::$sonDebug = [
            'action'    => $action,
            'url'       => self::$apiUrl,
            'multipart' => $multipart,
            'http_code' => $code,
            'curl_err'  => $err,
            'gonderilen'=> $multipart ? '(multipart)' : $postData,
            'ham_yanit' => is_string($res) ? mb_substr($res, 0, 2000) : '(false)',
        ];

        if ($res === false) {
            return ['success' => false, 'message' => 'API bağlantı hatası: ' . ($err ?: 'Bilinmeyen hata')];
        }
        $data = json_decode($res, true);
        if (is_array($data)) {
            return $data;
        }
        if ($code < 200 || $code >= 300) {
            return ['success' => false, 'message' => 'API HTTP hatası: ' . $code . ' — ' . mb_substr($res, 0, 200)];
        }
        return ['success' => false, 'message' => 'Geçersiz API yanıtı: ' . mb_substr($res, 0, 200)];
    }

    // ─── Dosya doğrulama ────────────────────────────────────────────────────

    /**
     * $_FILES['dosyalar'] içindeki her dosyayı boyut ve MIME açısından kontrol eder.
     * @return array ['gecerli' => bool, 'hata' => string]
     */
    public static function dosyalariDogrula(array $files): array
    {
        if (empty($files['name']) || !is_array($files['name'])) {
            return ['gecerli' => true, 'hata' => ''];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK) {
                return ['gecerli' => false, 'hata' => 'Dosya yükleme hatası (kod: ' . $err . ').'];
            }
            if (($files['size'][$i] ?? 0) > self::MAX_DOSYA_BOYUTU) {
                return ['gecerli' => false, 'hata' => $files['name'][$i] . ' — dosya 20MB sınırını aşıyor.'];
            }
            $mime = $finfo->file($files['tmp_name'][$i]) ?: '';
            if (!in_array($mime, self::IZINLI_MIME, true)) {
                return ['gecerli' => false, 'hata' => $files['name'][$i] . ' — izin verilmeyen dosya türü (' . $mime . ').'];
            }
        }
        return ['gecerli' => true, 'hata' => ''];
    }

    // ─── Görüntüleme / mükerrer gönderim yardımcıları ───────────────────────

    /** Düz metni güvenli HTML'e çevirir: önce kaçış, sonra URL'leri link yapar, satır sonlarını korur */
    public static function metinHtml($metin): string
    {
        $html = htmlspecialchars((string)$metin, ENT_QUOTES, 'UTF-8');
        $html = preg_replace_callback('~\b((?:https?://|www\.)[^\s<]+)~iu', function ($m) {
            $url = $m[1];
            $kuyruk = '';
            // Cümle sonundaki noktalama linke dahil edilmez
            if (preg_match('~[.,;:!?)\]]+$~', $url, $s)) {
                $kuyruk = $s[0];
                $url = substr($url, 0, -strlen($kuyruk));
            }
            $href = preg_match('~^www\.~i', $url) ? 'https://' . $url : $url;
            return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $kuyruk;
        }, $html);
        return nl2br($html);
    }

    /** Geçerli biçimde (32 hex) form anahtarı mı? */
    public static function formAnahtarGecerliMi(string $anahtar): bool
    {
        return (bool)preg_match('/^[a-f0-9]{32}$/', $anahtar);
    }

    /**
     * Mükerrer gönderim koruması: form anahtarı yalnız bir kez kullanılabilir.
     * PHP session kilidi aynı oturumdaki eşzamanlı istekleri sıraya sokar;
     * ikinci istek, ilki bittikten sonra anahtarı dolu bulur.
     */
    public static function formAnahtarKaydi(string $anahtar): ?string
    {
        return $_SESSION['destek_form_anahtarlar'][$anahtar] ?? null;
    }

    /** Başarılı gönderimden SONRA anahtarı ilk sonuçla birlikte kaydeder (son 20). */
    public static function formAnahtarKaydet(string $anahtar, string $sonuc): void
    {
        $liste = $_SESSION['destek_form_anahtarlar'] ?? [];
        $liste[$anahtar] = $sonuc;
        $_SESSION['destek_form_anahtarlar'] = array_slice($liste, -20, null, true);
    }

    // ─── Yüksek seviye kısayollar ───────────────────────────────────────────

    /** Aktif kategori/öncelik/durum listeleri. */
    public static function meta(): array
    {
        return self::apiCall('ticket_meta');
    }

    /** Oturumdaki personelin ticket listesi. */
    public static function listele(): array
    {
        return self::apiCall('list_tickets', ['eposta' => self::eposta()]);
    }

    /** Ticket detayı + mesajlar. */
    public static function detay(int $ticketId): array
    {
        return self::apiCall('ticket_detail', [
            'ticket_id' => $ticketId,
            'eposta'    => self::eposta(),
        ]);
    }

    /** Yeni ticket oluştur (opsiyonel dosya ekleri). */
    public static function olustur(string $konu, string $mesaj, int $kategoriId, int $oncelikId, array $files = []): array
    {
        return self::apiCall('create_ticket', [
            'konu'        => $konu,
            'mesaj'       => $mesaj,
            'kategori_id' => $kategoriId,
            'oncelik_id'  => $oncelikId,
            'kullanici'   => self::kullaniciBilgisi(),
        ], $files);
    }

    /** Mevcut tickete yanıt (opsiyonel dosya ekleri). */
    public static function yanitla(int $ticketId, string $mesaj, array $files = []): array
    {
        return self::apiCall('reply_ticket', [
            'ticket_id' => $ticketId,
            'mesaj'     => $mesaj,
            'kullanici' => self::kullaniciBilgisi(),
            'eposta'    => self::eposta(),
        ], $files);
    }
}
