<?php
/**
 * WebPush - Bağımlılıksız Web Push gönderici (VAPID + RFC 8291 aes128gcm)
 * Sadece openssl + curl kullanır. Harici composer paketi gerektirmez.
 *
 * Kullanım:
 *   $vapid = ['public'=>..., 'private'=>PEM, 'subject'=>'mailto:...'];
 *   $sonuc = WebPush::gonder($endpoint, $p256dh, $auth, $payloadJson, $vapid);
 *   // $sonuc['kod'] => HTTP durum kodu (201 başarı, 404/410 abonelik ölmüş)
 */
class WebPush
{
    /**
     * openssl.cnf yolu.
     * Windows'ta OPENSSL_CONF ortam değişkeni genelde tanımsızdır; conf bulunamazsa
     * openssl_pkey_new() false döner. Bu yüzden yol açıkça geçilir.
     */
    private static function opensslConf(): array
    {
        return ['config' => __DIR__ . '/openssl.cnf'];
    }

    /** base64url encode */
    private static function b64u($data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** base64url decode */
    private static function b64uDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        return base64_decode($data);
    }

    /** Ham 65 byte EC public point'ten openssl public key üretir (P-256) */
    private static function pointToPublicKey(string $point65)
    {
        // SubjectPublicKeyInfo sabit önek (P-256) + ham nokta
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point65;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($pem);
    }

    /** ECDSA DER imzayı JOSE ham (R||S, 64 byte) formatına çevirir */
    private static function derToRaw(string $der): string
    {
        $offset = 0;
        if (ord($der[$offset++]) !== 0x30) throw new Exception('Geçersiz imza (SEQUENCE)');
        if (ord($der[$offset]) & 0x80) $offset += (ord($der[$offset]) & 0x7f) + 1; else $offset++;
        // R
        if (ord($der[$offset++]) !== 0x02) throw new Exception('Geçersiz imza (R)');
        $rlen = ord($der[$offset++]);
        $r = substr($der, $offset, $rlen); $offset += $rlen;
        // S
        if (ord($der[$offset++]) !== 0x02) throw new Exception('Geçersiz imza (S)');
        $slen = ord($der[$offset++]);
        $s = substr($der, $offset, $slen);
        // 32 byte'a normalize et
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }

    /** VAPID JWT üretir (ES256) */
    private static function vapidJwt(string $endpoint, array $vapid): string
    {
        $p = parse_url($endpoint);
        $aud = $p['scheme'] . '://' . $p['host'];

        $header  = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::b64u(json_encode([
            'aud' => $aud,
            'exp' => time() + 12 * 3600,
            'sub' => $vapid['subject'],
        ], JSON_UNESCAPED_SLASHES));
        $input = $header . '.' . $payload;

        $pkey = openssl_pkey_get_private($vapid['private']);
        if (!$pkey) throw new Exception('VAPID özel anahtar okunamadı.');
        $der = '';
        openssl_sign($input, $der, $pkey, OPENSSL_ALGO_SHA256);

        return $input . '.' . self::b64u(self::derToRaw($der));
    }

    /** Payload'ı RFC 8291 (aes128gcm) ile şifreler; gönderilecek gövdeyi döner */
    private static function sifrele(string $p256dh, string $auth, string $payload): string
    {
        $uaPublic   = self::b64uDecode($p256dh); // 65 byte
        $authSecret = self::b64uDecode($auth);   // 16 byte

        // Efemeral (geçici) sunucu anahtar çifti
        $as = openssl_pkey_new(array_merge(
            ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'],
            self::opensslConf()
        ));
        if (!$as) throw new Exception('Efemeral anahtar üretilemedi (openssl).');
        $asDetails = openssl_pkey_get_details($as);
        $asPublic  = "\x04" . str_pad($asDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT)
                            . str_pad($asDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        // ECDH ortak sırrı
        $uaKey = self::pointToPublicKey($uaPublic);
        $ecdh  = openssl_pkey_derive($uaKey, $as, 32);
        if ($ecdh === false) throw new Exception('ECDH türetme başarısız.');

        // RFC 8291: IKM
        $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
        $ikm     = hash_hkdf('sha256', $ecdh, 32, $keyInfo, $authSecret);

        // RFC 8188: CEK + NONCE
        $salt  = random_bytes(16);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // Tek kayıt: plaintext || 0x02 (delimiter)
        $plaintext = $payload . "\x02";
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        $ciphertext .= $tag;

        // Gövde başlığı: salt(16) | rs(4) | idlen(1) | keyid(as_public 65)
        $rs = 4096;
        return $salt . pack('N', $rs) . chr(strlen($asPublic)) . $asPublic . $ciphertext;
    }

    /**
     * Bildirimi gönderir.
     * @return array ['kod'=>int, 'hata'=>string|null]
     */
    public static function gonder(string $endpoint, string $p256dh, string $auth, string $payloadJson, array $vapid): array
    {
        $body = self::sifrele($p256dh, $auth, $payloadJson);
        $jwt  = self::vapidJwt($endpoint, $vapid);

        $headers = [
            'Authorization: vapid t=' . $jwt . ', k=' . $vapid['public'],
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: 86400',
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $kod  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        return ['kod' => $kod, 'hata' => $err ?: ($kod >= 400 ? $resp : null)];
    }
}
