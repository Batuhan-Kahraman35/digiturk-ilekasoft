<?php
/**
 * Lead formu alım kuralları (ReklamLeadFormKurallari).
 *
 * Form adına göre Meta lead'inin alınıp alınmayacağına karar verir:
 *  - ALMA   : ad kurala uyuyorsa lead alınmaz (her zaman önceliklidir)
 *  - SADECE : en az bir aktif SADECE kuralı varsa, yalnız bunlardan birine uyan formların lead'i alınır
 *
 * Karşılaştırmada büyük/küçük harf, i/ı/İ/I, boşluk, "-" ve "_" farkı yok sayılır
 * ("DigiturkMerkez" ↔ "DIGITURK MERKEZ" eşleşir).
 */
class LeadFormKural
{
    /** Kural tipleri ve eşleşme türleri karar mantığına bağlı olduğu için kodda tutulur (DB'de CHECK ile kısıtlı). */
    public const TIPLER = [
        'ALMA'   => 'Hariç tut (lead alma)',
        'SADECE' => 'Sadece bunları al',
    ];
    public const ESLESMELER = [
        'ICERIR' => 'İçerir',
        'BASLAR' => 'İle başlar',
        'BITER'  => 'İle biter',
        'ESIT'   => 'Tam eşit',
    ];

    private static ?array $onbellek = null;

    /**
     * Aktif kurallar (istek başına bir kez okunur). Tablo okunamazsa boş döner:
     * kural hatası webhook'u durdurup lead kaybettirmemeli.
     */
    public static function kurallar(Database $db): array
    {
        if (self::$onbellek === null) {
            try {
                self::$onbellek = $db->fetchAll("
                    SELECT ReklamLeadFormKurallari_id, ReklamLeadFormKurallari_Tip,
                           ReklamLeadFormKurallari_Eslesme, ReklamLeadFormKurallari_Deger
                    FROM ReklamLeadFormKurallari
                    WHERE Durum = 1
                ") ?: [];
            } catch (Throwable $e) {
                self::$onbellek = [];
            }
        }
        return self::$onbellek;
    }

    public static function normalize(string $s): string
    {
        $s = str_replace(['İ', 'I', 'ı'], 'i', $s);
        $s = mb_strtolower($s, 'UTF-8');
        return preg_replace('/[\s\-_]+/u', '', $s);
    }

    public static function uyuyorMu(string $formAdi, array $kural): bool
    {
        $ad  = self::normalize($formAdi);
        $dgr = self::normalize((string)$kural['ReklamLeadFormKurallari_Deger']);
        if ($dgr === '') return false;

        switch ($kural['ReklamLeadFormKurallari_Eslesme']) {
            case 'BASLAR': return str_starts_with($ad, $dgr);
            case 'BITER':  return str_ends_with($ad, $dgr);
            case 'ESIT':   return $ad === $dgr;
            default:       return str_contains($ad, $dgr);
        }
    }

    /**
     * Form adı kurallara göre engelleniyorsa nedenini döner, lead alınacaksa null.
     * @return array{kural_id:?int, neden:string}|null
     */
    public static function engelNedeni(string $formAdi, array $kurallar): ?array
    {
        $sadece = [];
        foreach ($kurallar as $k) {
            if ($k['ReklamLeadFormKurallari_Tip'] === 'ALMA' && self::uyuyorMu($formAdi, $k)) {
                return [
                    'kural_id' => (int)$k['ReklamLeadFormKurallari_id'],
                    'neden'    => 'Hariç tut: "' . $k['ReklamLeadFormKurallari_Deger'] . '"',
                ];
            }
            if ($k['ReklamLeadFormKurallari_Tip'] === 'SADECE') $sadece[] = $k;
        }

        if ($sadece) {
            foreach ($sadece as $k) {
                if (self::uyuyorMu($formAdi, $k)) return null;
            }
            return ['kural_id' => null, 'neden' => 'Sadece-al kurallarının hiçbirine uymuyor'];
        }
        return null;
    }
}
