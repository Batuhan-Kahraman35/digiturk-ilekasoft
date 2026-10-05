<?php
/**
 * CallCenter (Esdisis) Yardımcısı
 * Entegrasyon id=6 bilgisiyle login olur, JWT alır ve Lead API kayıtlarını çeker.
 */

class CallCenterHelper
{
    private const ENTEGRASYON_ID = 6;

    /** Entegrasyon id=6 kanal + base bilgilerini getirir. */
    private static function kanal(): ?array
    {
        $db = Database::getInstance();
        return $db->fetchOne("
            SELECT TOP 1
                k.EntegrasyonKanallari_Kullanici AS Kullanici,
                k.EntegrasyonKanallari_Sifre      AS Sifre,
                e.Entegrasyonlar_BaseURL          AS BaseURL
            FROM EntegrasyonKanallari k
            INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
            WHERE e.Entegrasyonlar_id = ? AND k.Durum = 1 AND e.Durum = 1
            ORDER BY k.EntegrasyonKanallari_id
        ", [self::ENTEGRASYON_ID]);
    }

    private static function cookieFile(): string
    {
        $dir = __DIR__ . '/../../storage';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return $dir . '/cc_sync_cookie.txt';
    }

    /** Genel cURL — cookie tabanlı (login/sayfa çekme). */
    private static function curl(string $url, ?array $jsonBody, string $cookieFile, ?string $jwt = null): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'];
        if ($jwt)      $headers[] = 'Authorization: Bearer ' . $jwt;
        if ($jsonBody !== null) $headers[] = 'Content-Type: application/json';

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
        }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        unset($ch);
        return ['body' => $body, 'code' => $code, 'error' => $err];
    }

    /**
     * Login olur ve JWT döndürür.
     * @return array ['success'=>bool, 'jwt'=>?string, 'baseUrl'=>string, 'message'=>string]
     */
    public static function jwtAl(): array
    {
        $kanal = self::kanal();
        if (!$kanal) {
            return ['success' => false, 'jwt' => null, 'baseUrl' => '', 'message' => 'CallCenter entegrasyon kaydı (id=6) bulunamadı.'];
        }

        $baseUrl    = rtrim($kanal['BaseURL'], '/');
        $cookieFile = self::cookieFile();
        if (file_exists($cookieFile)) @unlink($cookieFile);

        // 1) JSON login → cookie
        $login = self::curl($baseUrl . '/user/login', [
            'identity' => $kanal['Kullanici'],
            'password' => $kanal['Sifre'],
        ], $cookieFile);

        $resp = json_decode((string)$login['body'], true);
        if (!is_array($resp) || empty($resp['status'])) {
            return ['success' => false, 'jwt' => null, 'baseUrl' => $baseUrl, 'message' => 'Login başarısız: ' . mb_substr((string)$login['body'], 0, 200)];
        }
        if (!array_key_exists('verify_no', $resp)) {
            return ['success' => false, 'jwt' => null, 'baseUrl' => $baseUrl, 'message' => 'Login 2FA/doğrulama istiyor; otomatik senkron yapılamadı.'];
        }

        // 2) Bir panel sayfasından JWT'yi çek
        $page = self::curl($baseUrl . '/leadapi', null, $cookieFile);
        if (!preg_match('/Bearer\s+([A-Za-z0-9._\-]+)/', (string)$page['body'], $m)) {
            return ['success' => false, 'jwt' => null, 'baseUrl' => $baseUrl, 'message' => 'JWT token sayfada bulunamadı.'];
        }

        return ['success' => true, 'jwt' => $m[1], 'baseUrl' => $baseUrl, 'message' => 'OK'];
    }

    /**
     * Lead API kayıtlarını çeker (/api/leadapi/{id} id=1..maxId).
     * @return array ['success'=>bool, 'records'=>array, 'message'=>string]
     */
    public static function leadApiKayitlari(int $maxId = 60): array
    {
        $auth = self::jwtAl();
        if (!$auth['success']) {
            return ['success' => false, 'records' => [], 'message' => $auth['message']];
        }

        $baseUrl    = $auth['baseUrl'];
        $jwt        = $auth['jwt'];
        $cookieFile = self::cookieFile();
        $records    = [];

        for ($id = 1; $id <= $maxId; $id++) {
            $r  = self::curl($baseUrl . '/api/leadapi/' . $id, null, $cookieFile, $jwt);
            $b  = trim((string)$r['body']);
            if ($r['code'] !== 200 || $b === '' || $b[0] !== '{') continue;
            $rec = json_decode($b, true);
            if (!is_array($rec) || empty($rec['id'])) continue;
            if (!empty($rec['deleted_at'])) continue; // silinmişleri atla
            $records[] = $rec;
        }

        if (file_exists($cookieFile)) @unlink($cookieFile);

        return ['success' => true, 'records' => $records, 'message' => count($records) . ' kayıt çekildi.'];
    }

    /**
     * Esdisis kampanyalarını çeker (/api/campaign/{id} id=1..maxId).
     * @return array ['success'=>bool, 'records'=>array, 'message'=>string]
     */
    public static function kampanyalar(int $maxId = 60): array
    {
        $auth = self::jwtAl();
        if (!$auth['success']) {
            return ['success' => false, 'records' => [], 'message' => $auth['message']];
        }

        $baseUrl    = $auth['baseUrl'];
        $jwt        = $auth['jwt'];
        $cookieFile = self::cookieFile();
        $records    = [];

        for ($id = 1; $id <= $maxId; $id++) {
            $r = self::curl($baseUrl . '/api/campaign/' . $id, null, $cookieFile, $jwt);
            $b = trim((string)$r['body']);
            if ($r['code'] !== 200 || $b === '' || $b[0] !== '{') continue;
            $rec = json_decode($b, true);
            if (!is_array($rec) || empty($rec['id'])) continue;
            $records[] = $rec;
        }

        if (file_exists($cookieFile)) @unlink($cookieFile);

        return ['success' => true, 'records' => $records, 'message' => count($records) . ' kampanya çekildi.'];
    }

    /**
     * Data paketlerini çeker (GET /api/datapacket — DataTables dizi formatı).
     * @return array ['success'=>bool, 'records'=>array, 'message'=>string]
     */
    public static function dataPaketleri(): array
    {
        $auth = self::jwtAl();
        if (!$auth['success']) {
            return ['success' => false, 'records' => [], 'message' => $auth['message']];
        }

        $cookieFile = self::cookieFile();
        $r = self::curl($auth['baseUrl'] . '/api/datapacket?draw=1&start=0&length=2000', null, $cookieFile, $auth['jwt']);
        if (file_exists($cookieFile)) @unlink($cookieFile);

        $resp = json_decode((string)$r['body'], true);
        if (!is_array($resp) || !isset($resp['data']) || !is_array($resp['data'])) {
            return ['success' => false, 'records' => [], 'message' => 'Liste alınamadı: ' . mb_substr((string)$r['body'], 0, 200)];
        }

        return ['success' => true, 'records' => $resp['data'], 'message' => count($resp['data']) . ' paket çekildi.'];
    }
}
