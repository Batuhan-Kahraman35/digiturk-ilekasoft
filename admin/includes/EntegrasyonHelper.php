<?php
/**
 * Entegrasyon Gönderim Yardımcısı
 * WhatsApp (Evolution API) ve E-posta (PHPMailer/SMTP) gönderimi
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/KaraListeHelper.php';

class EntegrasyonHelper
{
    private static Database $db;

    private static function db(): Database
    {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance();
        }
        return self::$db;
    }

    // ─── WhatsApp ──────────────────────────────────────────────────────────────

    /**
     * WhatsApp mesajı gönderir.
     *
     * @param int    $kanalId      EntegrasyonKanallari_id
     * @param string $telefon      Uluslararası format, örn: "905551234567"
     * @param string $mesaj        Gönderilecek metin
     * @param int    $kullaniciId  İşlemi yapan kullanıcı
     * @return array ['success' => bool, 'message' => string, 'log_id' => int|null]
     */
    public static function whatsappGonder(int $kanalId, string $telefon, string $mesaj, int $kullaniciId = 1, bool $karaListeKontrol = false): array
    {
        // KARA LİSTE — yalnız $karaListeKontrol=true ise uygulanır.
        // Varsayılan KAPALI: bu fonksiyonun mevcut çağıranlarının tamamı iç bildirim
        // (VoIP uyarısı, hatırlatma aşaması, şifre sıfırlama, kanal testi). Kara liste
        // müşteri numaraları için tutulduğundan, buraya koşulsuz kontrol koymak bir
        // personel/grup numarası listeye girdiğinde iç bildirimleri de susturur.
        // MÜŞTERİYE mesaj gönderen yeni bir akış eklenirse true geçilmeli.
        if ($karaListeKontrol) {
            $engel = KaraListe::kontrolVeLogla(
                $telefon, 'EntegrasyonHelper::whatsapp', ['kanal_id' => $kanalId], null, $kullaniciId
            );
            if ($engel) {
                return ['success' => false, 'engelli' => true, 'message' => $engel['_mesaj'], 'log_id' => null];
            }
        }

        $kanal = self::kanalGetir($kanalId, 'whatsapp');
        if (!$kanal) {
            return ['success' => false, 'message' => 'Kanal bulunamadı veya pasif.', 'log_id' => null];
        }

        $entegrasyon = self::entegrasyonGetir($kanal['EntegrasyonKanallari_Entegrasyon_id']);
        if (!$entegrasyon) {
            return ['success' => false, 'message' => 'Entegrasyon bulunamadı.', 'log_id' => null];
        }

        $baseURL  = rtrim($entegrasyon['Entegrasyonlar_BaseURL'], '/');
        $apiKey   = $entegrasyon['Entegrasyonlar_ApiKey'];
        $instance = $kanal['EntegrasyonKanallari_Instance'];

        $endpoint = "{$baseURL}/message/sendText/{$instance}";
        $payload  = json_encode(['number' => $telefon, 'text' => $mesaj]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "apikey: {$apiKey}",
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $cevap     = curl_exec($ch);
        $httpKod   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlHata  = curl_error($ch);
        unset($ch);

        $basarili = ($httpKod >= 200 && $httpKod < 300 && !$curlHata);
        $hataMesaj = $curlHata ?: ($basarili ? null : "HTTP {$httpKod}: {$cevap}");

        $logId = self::logKaydet([
            'kanal_id'       => $kanalId,
            'tip'            => 'whatsapp',
            'alici'          => $telefon,
            'konu'           => null,
            'mesaj'          => $mesaj,
            'durum'          => $basarili ? 'basarili' : 'hata',
            'hata_mesaj'     => $hataMesaj,
            'istek_verisi'   => $payload,
            'cevap_verisi'   => $cevap,
            'kullanici_id'   => $kullaniciId,
        ]);

        return [
            'success' => $basarili,
            'message' => $basarili ? 'WhatsApp mesajı gönderildi.' : $hataMesaj,
            'log_id'  => $logId,
        ];
    }

    /**
     * WhatsApp görsel (PNG) gönderir — Evolution API sendMedia.
     *
     * @param int    $kanalId     EntegrasyonKanallari_id
     * @param string $alici       Telefon (905xx) veya grup JID (120363...@g.us)
     * @param string $imageData   Ham PNG verisi (base64 değil)
     * @param string $caption     Görsel altı yazısı
     * @param int    $kullaniciId İşlemi yapan kullanıcı
     */
    public static function whatsappResimGonder(int $kanalId, string $alici, string $imageData, string $caption = '', int $kullaniciId = 1): array
    {
        $kanal = self::kanalGetir($kanalId, 'whatsapp');
        if (!$kanal) {
            return ['success' => false, 'message' => 'Kanal bulunamadı veya pasif.', 'log_id' => null];
        }

        $entegrasyon = self::entegrasyonGetir($kanal['EntegrasyonKanallari_Entegrasyon_id']);
        if (!$entegrasyon) {
            return ['success' => false, 'message' => 'Entegrasyon bulunamadı.', 'log_id' => null];
        }

        $baseURL  = rtrim($entegrasyon['Entegrasyonlar_BaseURL'], '/');
        $apiKey   = $entegrasyon['Entegrasyonlar_ApiKey'];
        $instance = $kanal['EntegrasyonKanallari_Instance'];

        $endpoint = "{$baseURL}/message/sendMedia/{$instance}";
        $payload  = json_encode([
            'number'    => $alici,
            'mediatype' => 'image',
            'mimetype'  => 'image/png',
            'media'     => base64_encode($imageData),
            'caption'   => $caption,
            'fileName'  => 'rapor.png',
        ]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "apikey: {$apiKey}"],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $cevap    = curl_exec($ch);
        $httpKod  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlHata = curl_error($ch);
        unset($ch);

        $basarili  = ($httpKod >= 200 && $httpKod < 300 && !$curlHata);
        $hataMesaj = $curlHata ?: ($basarili ? null : "HTTP {$httpKod}: {$cevap}");

        $logId = self::logKaydet([
            'kanal_id'     => $kanalId,
            'tip'          => 'whatsapp',
            'alici'        => $alici,
            'konu'         => 'Rapor Görseli',
            'mesaj'        => $caption,
            'durum'        => $basarili ? 'basarili' : 'hata',
            'hata_mesaj'   => $hataMesaj,
            'istek_verisi' => json_encode(['alici' => $alici, 'mediatype' => 'image', 'caption' => $caption]),
            'cevap_verisi' => $cevap,
            'kullanici_id' => $kullaniciId,
        ]);

        return [
            'success' => $basarili,
            'message' => $basarili ? 'WhatsApp resim gönderildi.' : $hataMesaj,
            'log_id'  => $logId,
        ];
    }

    /**
     * WhatsApp belge (PDF vb.) gönderir — Evolution API sendMedia (mediatype: document).
     *
     * @param int    $kanalId    EntegrasyonKanallari_id
     * @param string $alici      Telefon (905xx) veya grup JID (120363...@g.us)
     * @param string $base64     Dosyanın base64 içeriği (ham veri DEĞİL)
     * @param string $dosyaAdi   Alıcıda görünecek dosya adı (ör. dekont.pdf)
     * @param string $mimetype   application/pdf gibi
     * @param string $caption    Belge altına düşecek metin
     */
    public static function whatsappBelgeGonder(
        int $kanalId, string $alici, string $base64,
        string $dosyaAdi = 'belge.pdf', string $mimetype = 'application/pdf',
        string $caption = '', int $kullaniciId = 1
    ): array {
        $kanal = self::kanalGetir($kanalId, 'whatsapp');
        if (!$kanal) {
            return ['success' => false, 'message' => 'Kanal bulunamadı veya pasif.', 'log_id' => null];
        }

        $entegrasyon = self::entegrasyonGetir($kanal['EntegrasyonKanallari_Entegrasyon_id']);
        if (!$entegrasyon) {
            return ['success' => false, 'message' => 'Entegrasyon bulunamadı.', 'log_id' => null];
        }

        $baseURL  = rtrim($entegrasyon['Entegrasyonlar_BaseURL'], '/');
        $apiKey   = $entegrasyon['Entegrasyonlar_ApiKey'];
        $instance = $kanal['EntegrasyonKanallari_Instance'];

        $endpoint = "{$baseURL}/message/sendMedia/{$instance}";
        $payload  = json_encode([
            'number'    => $alici,
            'mediatype' => 'document',
            'mimetype'  => $mimetype,
            'media'     => $base64,
            'caption'   => $caption,
            'fileName'  => $dosyaAdi,
        ]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "apikey: {$apiKey}"],
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $cevap    = curl_exec($ch);
        $httpKod  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlHata = curl_error($ch);
        unset($ch);

        $basarili  = ($httpKod >= 200 && $httpKod < 300 && !$curlHata);
        $hataMesaj = $curlHata ?: ($basarili ? null : "HTTP {$httpKod}: {$cevap}");

        $logId = self::logKaydet([
            'kanal_id'     => $kanalId,
            'tip'          => 'whatsapp',
            'alici'        => $alici,
            'konu'         => $dosyaAdi,
            'mesaj'        => $caption,
            'durum'        => $basarili ? 'basarili' : 'hata',
            'hata_mesaj'   => $hataMesaj,
            // base64 log'a yazılmaz (tablo şişmesin)
            'istek_verisi' => json_encode([
                'alici' => $alici, 'mediatype' => 'document',
                'fileName' => $dosyaAdi, 'mimetype' => $mimetype, 'caption' => $caption,
            ], JSON_UNESCAPED_UNICODE),
            'cevap_verisi' => $cevap,
            'kullanici_id' => $kullaniciId,
        ]);

        return [
            'success' => $basarili,
            'message' => $basarili ? 'WhatsApp belge gönderildi.' : $hataMesaj,
            'log_id'  => $logId,
        ];
    }

    // ─── E-posta ───────────────────────────────────────────────────────────────

    /**
     * E-posta gönderir.
     *
     * @param int          $kanalId      EntegrasyonKanallari_id
     * @param string|array $kime         "ad@mail.com" veya [["ad@mail.com","Ad Soyad"], ...]
     * @param string       $konu         E-posta konusu
     * @param string       $icerik       Mesaj içeriği
     * @param bool         $html         HTML içerik mi?
     * @param int          $kullaniciId  İşlemi yapan kullanıcı
     * @param array        $cc           Bilgi (CC) alıcıları: ["ad@mail.com", ...]
     * @return array ['success' => bool, 'message' => string, 'log_id' => int|null]
     */
    public static function emailGonder(int $kanalId, $kime, string $konu, string $icerik, bool $html = false, int $kullaniciId = 1, array $cc = []): array
    {
        $kanal = self::kanalGetir($kanalId, 'email');
        if (!$kanal) {
            return ['success' => false, 'message' => 'Kanal bulunamadı veya pasif.', 'log_id' => null];
        }

        // Alıcıları normalize et
        $alicilar = is_array($kime) ? $kime : [[$kime, '']];
        $aliciMetin = implode(', ', array_column($alicilar, 0));

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $kanal['EntegrasyonKanallari_Host'] ?: 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $kanal['EntegrasyonKanallari_Kullanici'];
            $mail->Password   = $kanal['EntegrasyonKanallari_Sifre'];
            $mail->SMTPSecure = $kanal['EntegrasyonKanallari_SifreliBaslanti'] ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)($kanal['EntegrasyonKanallari_Port'] ?: 465);
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom(
                $kanal['EntegrasyonKanallari_Kullanici'],
                $kanal['EntegrasyonKanallari_GondericiAd'] ?: ''
            );

            foreach ($alicilar as $alici) {
                $mail->addAddress($alici[0], $alici[1] ?? '');
            }
            foreach ($cc as $ccAdres) {
                if ($ccAdres) $mail->addCC($ccAdres);
            }

            $mail->Subject = $konu;
            if ($html) {
                $mail->isHTML(true);
                $mail->Body    = $icerik;
                $mail->AltBody = strip_tags($icerik);
            } else {
                $mail->Body = $icerik;
            }

            $mail->send();

            $logId = self::logKaydet([
                'kanal_id'       => $kanalId,
                'tip'            => 'email',
                'alici'          => $aliciMetin,
                'konu'           => $konu,
                'mesaj'          => $icerik,
                'durum'          => 'basarili',
                'hata_mesaj'     => null,
                'istek_verisi'   => json_encode(['kime' => $alicilar, 'cc' => $cc, 'konu' => $konu]),
                'cevap_verisi'   => 'OK',
                'kullanici_id'   => $kullaniciId,
            ]);

            return ['success' => true, 'message' => 'E-posta gönderildi.', 'log_id' => $logId];

        } catch (MailerException) {
            $hataMesaj = $mail->ErrorInfo;

            $logId = self::logKaydet([
                'kanal_id'       => $kanalId,
                'tip'            => 'email',
                'alici'          => $aliciMetin,
                'konu'           => $konu,
                'mesaj'          => $icerik,
                'durum'          => 'hata',
                'hata_mesaj'     => $hataMesaj,
                'istek_verisi'   => json_encode(['kime' => $alicilar, 'cc' => $cc, 'konu' => $konu]),
                'cevap_verisi'   => null,
                'kullanici_id'   => $kullaniciId,
            ]);

            return ['success' => false, 'message' => $hataMesaj, 'log_id' => $logId];
        }
    }

    // ─── Digiturk SMS Doğrulama (OTP) ──────────────────────────────────────────

    /**
     * Digiturk başvurusu oluşturur (SMS doğrulama tetikler).
     *
     * @param int    $kanalId      EntegrasyonKanallari_id (Tip='OTP')
     * @param string $gsm          90XXXXXXXXXX (temizlenir)
     * @param string $processType  1..5 (Gelen/Giden Çağrı, Online, Fiziksel, Meta)
     * @param array  $musteri      Opsiyonel: name, surname, mail, gender, adress, birthDate
     * @param bool   $smsFormat    true → link SMS ile müşteriye gider
     * @param int    $kullaniciId  İşlemi yapan kullanıcı
     * @return array ['success','durum','mesaj','onayTarihi','url','http','raw','json','log_id']
     */
    public static function digiturkBasvuruGonder(int $kanalId, string $gsm, string $processType = '3', array $musteri = [], bool $smsFormat = true, int $kullaniciId = 1, int $basvuruId = 0): array
    {
        return self::digiturkGonder($kanalId, $gsm, $processType, $musteri, $smsFormat, 'otp', $kullaniciId, $basvuruId);
    }

    /**
     * Digiturk durum sorgusu — aynı Add isteğini atar, message'ı yorumlar.
     * Mevcut kayıt varsa YENİ SMS GÖNDERMEZ (repeated/verified), sadece durumu döner.
     *
     * $basvuruId MUTLAKA geçilmeli: 0 kalırsa istekte redirectUrl olmaz ve müşteri
     * onaylasa bile onay sisteme dönmez, kayıt sonsuza kadar 'beklemede' kalır.
     *
     * @param int  $basvuruId  redirectUrl'e bid olarak eklenir (onay dönüşü için zorunlu)
     * @param bool $smsFormat  true → link SMS ile müşteriye gider (toplu gönderim akışı)
     * @return array ['success','durum','mesaj','onayTarihi','url','http','raw','json','log_id']
     */
    public static function digiturkDurumSorgula(int $kanalId, string $gsm, string $processType = '3', int $kullaniciId = 1, int $basvuruId = 0, bool $smsFormat = true): array
    {
        return self::digiturkGonder($kanalId, $gsm, $processType, [], $smsFormat, 'otp-sorgu', $kullaniciId, $basvuruId);
    }

    /** Digiturk Add isteğini kurar, gönderir, loglar ve durumu yorumlar. */
    private static function digiturkGonder(int $kanalId, string $gsm, string $processType, array $musteri, bool $smsFormat, string $logTip, int $kullaniciId, int $basvuruId = 0): array
    {
        $kanal = self::kanalGetir($kanalId, 'OTP');
        if (!$kanal) {
            return ['success' => false, 'durum' => 'hata', 'mesaj' => 'Kanal bulunamadı veya pasif.',
                    'onayTarihi' => null, 'url' => null, 'http' => 0, 'raw' => null, 'json' => null, 'log_id' => null];
        }

        $baseURL = $kanal['Entegrasyonlar_BaseURL'];
        $apiKey  = $kanal['Entegrasyonlar_ApiKey'];
        $gsm     = preg_replace('/\D/', '', $gsm);

        // KARA LİSTE — son savunma hattı. Tüm OTP/SMS çıkışları buradan geçtiği için
        // çağıran katman kontrolü atlasa bile istek Digiturk'e gitmez.
        $engel = KaraListe::kontrolVeLogla(
            $gsm,
            'EntegrasyonHelper::' . $logTip,
            ['kanal_id' => $kanalId, 'processType' => $processType, 'smsFormat' => $smsFormat],
            $basvuruId > 0 ? $basvuruId : null,
            $kullaniciId
        );
        if ($engel) {
            // durum='hata' — mevcut çağıranların tamamı bu değeri zaten ele alıyor.
            // Kara listeden kaynaklandığı 'engelli' bayrağıyla ayırt edilir.
            return ['success' => false, 'durum' => 'hata', 'engelli' => true, 'mesaj' => $engel['_mesaj'],
                    'onayTarihi' => null, 'url' => null, 'http' => 0, 'raw' => null, 'json' => null, 'log_id' => null];
        }

        // customer alanları (opsiyoneller sadece doluysa)
        $customer = ['gsm' => $gsm, 'smsFormat' => $smsFormat ? 'true' : 'false'];
        foreach (['name','surname','mail','gender','adress','birthDate'] as $f) {
            $v = trim((string)($musteri[$f] ?? ''));
            if ($v !== '') $customer[$f] = $v;
        }
        // responseUrl (callback) — DB'de tanımlıysa gönderilir, onay sonucu buraya POST edilir
        $callbackUrl = trim((string)($kanal['Entegrasyonlar_CallbackURL'] ?? ''));
        if ($callbackUrl !== '') $customer['responseUrl'] = $callbackUrl;
        // redirectUrl — onay sonrası tarayıcı buraya yönlenir; bid ile hangi kaydın onaylandığını taşırız
        $redirectBase = trim((string)($kanal['Entegrasyonlar_RedirectURL'] ?? ''));
        if ($redirectBase !== '' && $basvuruId > 0) {
            $sep = strpos($redirectBase, '?') !== false ? '&' : '?';
            $customer['redirectUrl'] = $redirectBase . $sep . 'bid=' . $basvuruId;
        }

        $payload = [
            'processType' => (string)$processType,
            'company'     => self::digiturkFirmaBilgisi($kanal),
            'customer'    => $customer,
        ];
        $istekJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $ch = curl_init($baseURL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $istekJson,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $cevap    = curl_exec($ch);
        $httpKod  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlHata = curl_error($ch);
        unset($ch);

        $json = json_decode($cevap ?? '', true);
        $y    = self::digiturkYorumla($json, $curlHata, $cevap ?? '', $httpKod);

        $logId = self::logKaydet([
            'kanal_id'     => $kanalId,
            'tip'          => $logTip,
            'alici'        => $gsm,
            'konu'         => $logTip === 'otp-sorgu' ? 'Durum Sorgu' : 'SMS Doğrulama',
            'mesaj'        => $y['mesaj'],
            'durum'        => in_array($y['durum'], ['onayli','beklemede','yeni'], true) ? 'basarili' : 'hata',
            'hata_mesaj'   => $y['durum'] === 'hata' ? $y['mesaj'] : null,
            'istek_verisi' => $istekJson,
            'cevap_verisi' => $cevap,
            'kullanici_id' => $kullaniciId,
        ]);

        return [
            'success'    => ($y['durum'] !== 'hata'),
            'durum'      => $y['durum'],
            'mesaj'      => $y['mesaj'],
            'onayTarihi' => $y['onayTarihi'],
            'url'        => $y['url'],
            'http'       => $httpKod,
            'raw'        => $cevap,
            'json'       => $json,
            'log_id'     => $logId,
        ];
    }

    /**
     * İstekte gönderilecek company bloğunu kurar.
     * Öncelik EntegrasyonKanallari_Ayarlar (JSON); alan boşsa eski kolona düşer.
     * @return array ['companyName','companyCode','companyType','companyIp']
     */
    private static function digiturkFirmaBilgisi(array $kanal): array
    {
        $ayar = json_decode((string)($kanal['EntegrasyonKanallari_Ayarlar'] ?? ''), true) ?: [];

        $eski = [
            'companyName' => $kanal['EntegrasyonKanallari_GondericiAd'] ?? '',
            'companyCode' => $kanal['EntegrasyonKanallari_Kullanici']   ?? '',
            'companyType' => $kanal['EntegrasyonKanallari_Instance']    ?? '',
            'companyIp'   => $kanal['EntegrasyonKanallari_Host']        ?? '',
        ];

        $company = [];
        foreach ($eski as $alan => $varsayilan) {
            $deger = trim((string)($ayar[$alan] ?? ''));
            $company[$alan] = $deger !== '' ? $deger : (string)$varsayilan;
        }
        return $company;
    }

    /**
     * Digiturk yanıtını yorumlar (HTTP koduna DEĞİL, message'a göre).
     * @return array ['durum'=>onayli|beklemede|yeni|hata, 'mesaj','onayTarihi','url']
     */
    private static function digiturkYorumla(?array $json, string $curlHata = '', string $ham = '', int $httpKod = 0): array
    {
        if ($curlHata !== '') {
            return ['durum' => 'hata', 'mesaj' => 'Bağlantı hatası: ' . $curlHata, 'onayTarihi' => null, 'url' => null];
        }
        // Servis bazı hatalarda JSON değil düz metin döner ("Geçersiz Sms" gibi) — metni kaybetme
        if (!is_array($json)) {
            $duz = trim($ham);
            return [
                'durum' => 'hata',
                'mesaj' => $duz !== '' ? "HTTP {$httpKod}: {$duz}" : "HTTP {$httpKod}: Boş yanıt",
                'onayTarihi' => null, 'url' => null,
            ];
        }
        $msg = mb_strtolower((string)($json['message'] ?? ''));
        $url = $json['url'] ?? null;

        if (strpos($msg, 'verified') !== false) {
            return ['durum' => 'onayli', 'mesaj' => $json['message'], 'onayTarihi' => $json['date'] ?? null, 'url' => $url];
        }
        if (strpos($msg, 'repeated') !== false) {
            return ['durum' => 'beklemede', 'mesaj' => $json['message'], 'onayTarihi' => null, 'url' => $url];
        }
        if (strpos($msg, 'request') !== false && !empty($json['status'])) {
            return ['durum' => 'yeni', 'mesaj' => $json['message'], 'onayTarihi' => null, 'url' => $url];
        }
        return ['durum' => 'hata', 'mesaj' => $json['message'] ?? 'Bilinmeyen yanıt', 'onayTarihi' => null, 'url' => $url];
    }

    // ─── Yardımcı metotlar ─────────────────────────────────────────────────────

    /** Kanal bilgisini DB'den getirir (aktif + tip kontrolü ile). */
    public static function kanalGetir(int $kanalId, ?string $tip = null): ?array
    {
        $tipKosul = $tip ? "AND e.Entegrasyonlar_Tip = ?" : "";
        $params   = $tip ? [$kanalId, $tip] : [$kanalId];

        return self::db()->fetchOne("
            SELECT k.*, e.Entegrasyonlar_Tip, e.Entegrasyonlar_BaseURL, e.Entegrasyonlar_ApiKey, e.Entegrasyonlar_CallbackURL, e.Entegrasyonlar_RedirectURL
            FROM EntegrasyonKanallari k
            INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
            WHERE k.EntegrasyonKanallari_id = ? AND k.Durum = 1 AND e.Durum = 1
            {$tipKosul}
        ", $params);
    }

    /** Entegrasyon bilgisini DB'den getirir. */
    public static function entegrasyonGetir(int $entegrasyonId): ?array
    {
        return self::db()->fetchOne(
            "SELECT * FROM Entegrasyonlar WHERE Entegrasyonlar_id = ? AND Durum = 1",
            [$entegrasyonId]
        );
    }

    /**
     * Bu GSM daha önce onaylanmış mı? (Basvurular'da OtpDurum='onayli' kayıt arar.)
     * Sınırsız geçerlilik: bir kez onaylı GSM tekrar SMS istemeden onaylı sayılır.
     * @return array|null ['Basvurular_id','Basvurular_OtpOnayTarihi'] veya null
     */
    public static function gsmDahaOnceOnayli(string $gsm): ?array
    {
        $gsm = preg_replace('/\D/', '', $gsm);
        if (strlen($gsm) < 10) return null;
        return self::db()->fetchOne("
            SELECT TOP 1 Basvurular_id, Basvurular_OtpOnayTarihi
            FROM Basvurular
            WHERE Basvurular_OtpDurum = 'onayli'
              AND (ISNULL(phoneCountryNumber,'')+ISNULL(phoneAreaNumber,'')+ISNULL(phoneNumber,'')) = ?
            ORDER BY Basvurular_OtpOnayTarihi DESC
        ", [$gsm]);
    }

    /** Tipe göre aktif kanalları listeler. */
    public static function aktifKanallar(string $tip): array
    {
        return self::db()->fetchAll("
            SELECT k.EntegrasyonKanallari_id, k.EntegrasyonKanallari_KanalAdi
            FROM EntegrasyonKanallari k
            INNER JOIN Entegrasyonlar e ON k.EntegrasyonKanallari_Entegrasyon_id = e.Entegrasyonlar_id
            WHERE e.Entegrasyonlar_Tip = ? AND k.Durum = 1 AND e.Durum = 1
            ORDER BY k.EntegrasyonKanallari_KanalAdi
        ", [$tip]);
    }

    /** Gönderim logu kaydeder, log ID döner. */
    private static function logKaydet(array $veri): ?int
    {
        try {
            return self::db()->insert('EntegrasyonLoglari', [
                'EntegrasyonLoglari_Kanal_id'       => $veri['kanal_id'],
                'EntegrasyonLoglari_Tip'             => $veri['tip'],
                'EntegrasyonLoglari_Alici'           => $veri['alici'],
                'EntegrasyonLoglari_Konu'            => $veri['konu'],
                'EntegrasyonLoglari_Mesaj'           => $veri['mesaj'],
                'EntegrasyonLoglari_GonderimDurumu'  => $veri['durum'],
                'EntegrasyonLoglari_HataMesaj'       => $veri['hata_mesaj'],
                'EntegrasyonLoglari_IstekVerisi'     => $veri['istek_verisi'],
                'EntegrasyonLoglari_CevapVerisi'     => $veri['cevap_verisi'],
                'EntegrasyonLoglari_GonderimTarihi'  => date('Y-m-d H:i:s'),
                'OlusturanKullanici'                 => $veri['kullanici_id'],
                'OlusturmaTarihi'                    => date('Y-m-d H:i:s'),
                'Durum'                              => 1,
            ]);
        } catch (Exception $e) {
            error_log('EntegrasyonHelper log hatası: ' . $e->getMessage());
            return null;
        }
    }
}
