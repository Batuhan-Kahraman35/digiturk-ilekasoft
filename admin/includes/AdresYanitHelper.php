<?php
/**
 * AdresYanitHelper — Digiturk Address uçlarının yanıtlarını normalize eder.
 *
 * Sorun: NVİ'de bağımsız bölüm (kapı/daire) kaydı olmayan binalarda Digiturk
 * adres servisi boş liste yerine geçersiz bir kayıt döndürüyor:
 *     { "code": 0, "name": null }
 * Bu yüzden istemci tarafında liste "dolu" görünüyor, seçilecek bir şey çıkmıyor
 * ve BBK adres kodu üretilemiyor. Aynı şekilde GetFullAdressByAdressCode ucu
 * çözümlenemeyen kodlarda responseCode=0 döndürüp 'address' alanını boş bırakıyor.
 *
 * Bu yardımcı geçersiz kayıtları ayıklar ve boş kalan yanıtlara makine tarafından
 * okunabilir bir sebep (emptyReason) ile açıklayıcı bir mesaj ekler.
 *
 * responseCode ve HTTP durum kodu ASLA değiştirilmez — mevcut entegrasyonlar bozulmasın.
 */

class AdresYanitHelper
{
    /** Endpoint kategorisi — adres uçları bu kategoriden dinamik çözülür. */
    public const KATEGORI = 'Address';

    /**
     * Adres kategorisindeki endpoint'lerin gateway route'larını (küçük harf) döner.
     * Route = Digiturk URL'indeki '/api/' sonrası kısım. Örn: 'address/getdoorbybuilding'
     *
     * @return string[]
     */
    public static function adresRoutelari($db): array
    {
        $rows = $db->fetchAll(
            "SELECT APIEndpointler_Endpoint
             FROM APIEndpointler
             WHERE APIEndpointler_Kategori = ? AND Durum = 1",
            [self::KATEGORI]
        );

        $out = [];
        foreach ($rows as $r) {
            $u   = (string)$r['APIEndpointler_Endpoint'];
            $pos = strpos($u, '/api/');
            $p   = $pos !== false ? substr($u, $pos + 5) : ltrim((string)parse_url($u, PHP_URL_PATH), '/');
            $p   = strtolower(trim($p, '/'));
            if ($p !== '') $out[] = $p;
        }
        return $out;
    }

    /** Verilen route adres kategorisine ait mi? */
    public static function adresRoutemu($db, string $route): bool
    {
        return in_array(strtolower(trim($route, '/')), self::adresRoutelari($db), true);
    }

    /**
     * Endpoint URL'inden / route'tan seviye etiketi türetir.
     * 'Address/GetDoorByBuilding' -> 'kapı/daire'   (GetXByY kalıbındaki X)
     * Eşleşme yoksa null döner; mesaj o zaman genel kalır.
     */
    public static function seviyeEtiketi(string $routeVeyaUrl): ?string
    {
        // Sondaki aksiyon adını al: .../GetDoorByBuilding -> GetDoorByBuilding
        $aksiyon = basename(parse_url($routeVeyaUrl, PHP_URL_PATH) ?: $routeVeyaUrl);

        if (!preg_match('/^Get([A-Za-z]+?)(?:By[A-Za-z]+)?$/i', $aksiyon, $m)) return null;

        // Digiturk aksiyon adı -> Türkçe seviye adı
        $sozluk = [
            'allcities' => 'şehir',
            'cities'    => 'şehir',
            'counties'  => 'ilçe',
            'county'    => 'ilçe',
            'burg'      => 'bucak',
            'village'   => 'köy',
            'quarter'   => 'mahalle',
            'street'    => 'sokak',
            'building'  => 'bina',
            'door'      => 'kapı/daire',
        ];
        return $sozluk[strtolower($m[1])] ?? null;
    }

    /**
     * Tek bir liste kaydı geçerli mi?
     * code boş / '0' / 0 ise ya da name boş ise geçersiz sayılır.
     */
    public static function gecerliKayit($kayit): bool
    {
        if (!is_array($kayit)) return false;

        $code = $kayit['code'] ?? $kayit['Code'] ?? null;
        if ($code === null || $code === '' || (string)$code === '0') return false;

        $name = $kayit['name'] ?? $kayit['Name'] ?? null;
        if ($name === null || trim((string)$name) === '') return false;

        return true;
    }

    /**
     * Adres listesinden geçersiz kayıtları ayıklar (indeksler yeniden sıfırlanır).
     *
     * @param  mixed $data  Digiturk yanıtının 'data' alanı
     * @return array
     */
    public static function listeyiTemizle($data): array
    {
        if (!is_array($data)) return [];
        return array_values(array_filter($data, [self::class, 'gecerliKayit']));
    }

    /**
     * Digiturk adres yanıtını bütün olarak normalize eder.
     *
     * - data dizi ise  : geçersiz kayıtlar ayıklanır; liste boş kalırsa emptyReason eklenir
     *                    (kapı ucunda NO_DOOR_RECORD, diğerlerinde NO_RECORD)
     * - data obje ise  : (GetFullAdressByAdressCode) 'address' boşsa ADDRESS_NOT_RESOLVED
     * - başka şekilse  : dokunulmaz
     *
     * responseCode değiştirilmez. responseMessage yalnızca BOŞ ise doldurulur;
     * Digiturk kendi mesajını göndermişse üzerine yazılmaz.
     *
     * @param  array  $yanit         Digiturk'ün tam JSON yanıtı
     * @param  string $routeVeyaUrl  Seviye etiketi türetmek için route ya da endpoint URL'i
     * @return array                 Normalize edilmiş yanıt
     */
    public static function normalizeEt(array $yanit, string $routeVeyaUrl): array
    {
        // Digiturk hata döndüyse dokunma — mesajı zaten kendisi veriyor.
        if (($yanit['responseCode'] ?? 0) !== 0) return $yanit;

        if (!array_key_exists('data', $yanit)) return $yanit;

        $data    = $yanit['data'];
        $etiket  = self::seviyeEtiketi($routeVeyaUrl);
        $mesajBos = trim((string)($yanit['responseMessage'] ?? '')) === '';

        // --- Liste uçları (İlçe → Kapı) ---
        if (is_array($data) && (empty($data) || array_is_list($data))) {
            $temiz         = self::listeyiTemizle($data);
            $yanit['data'] = $temiz;

            if (empty($temiz)) {
                $kapiMi = ($etiket === 'kapı/daire');
                $yanit['emptyReason'] = $kapiMi ? 'NO_DOOR_RECORD' : 'NO_RECORD';
                if ($mesajBos) {
                    $yanit['responseMessage'] = $kapiMi
                        ? 'Bu bina için kapı/daire kaydı bulunmuyor. Adres kodu (BBK) üretilemiyor; aynı sokakta kapı kaydı olan bir bina deneyin.'
                        : 'Bu seçim için ' . ($etiket ?? 'adres') . ' kaydı bulunmuyor.';
                }
            }
            return $yanit;
        }

        // --- Tam adres çözümleme ucu (GetFullAdressByAdressCode) ---
        if (is_array($data)) {
            $adres = trim((string)($data['address'] ?? $data['Address'] ?? ''));
            if ($adres === '') {
                $yanit['emptyReason'] = 'ADDRESS_NOT_RESOLVED';
                if ($mesajBos) {
                    $yanit['responseMessage'] = 'Bu adres kodu çözümlenemedi; geçerli bir BBK adres kodu değil.';
                }
            }
            return $yanit;
        }

        // data null / skaler → dokunma
        return $yanit;
    }
}
