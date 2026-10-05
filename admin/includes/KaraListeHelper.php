<?php
/**
 * Kara Liste Helper
 *
 * OTP, başvuru ve lead akışlarında engellenecek GSM / TC kayıtlarını kontrol eder.
 * Engellenen denemeler ayrı tabloya değil, mevcut dbo.BasvuruLog tablosuna
 * 'KARALISTE_ENGEL' işlem türüyle yazılır (admin/pages/basvuru-log.php'de görünür).
 *
 * Tablo: dbo.KaraListe
 *
 * Kullanım (engelleme noktalarında):
 *   require_once __DIR__ . '/KaraListeHelper.php';
 *   $engel = KaraListe::kontrolVeLogla($gsm, 'otp/gonder', ['payload' => $in]);
 *   if ($engel) { ... isteği reddet ... }
 */

require_once __DIR__ . '/BasvuruLogHelper.php';

class KaraListe
{
    /** BasvuruLog'a yazılan işlem türü */
    const LOG_ISLEM = 'KARALISTE_ENGEL';

    private static $db = null;

    private static function db()
    {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
        return self::$db;
    }

    // ─── Normalizasyon ────────────────────────────────────────────────────────

    /**
     * GSM'i 90XXXXXXXXXX biçimine getirir.
     * 5321234567 / 05321234567 / +90 532 123 45 67 → 905321234567
     *
     * @return string|null Geçersizse null
     */
    public static function normalizeGsm(?string $gsm): ?string
    {
        $s = preg_replace('/\D/', '', (string)$gsm);
        if ($s === '') return null;

        // Uluslararası çıkış öneki: 0090... → 90... (TR'de 00 ile başlayan numara yok)
        if (substr($s, 0, 2) === '00') $s = substr($s, 2);

        if (strlen($s) === 10 && $s[0] === '5')                   $s = '90' . $s;             // 5321234567
        elseif (strlen($s) === 11 && substr($s, 0, 2) === '05')   $s = '9' . $s;              // 05321234567
        elseif (strlen($s) === 13 && substr($s, 0, 3) === '900')  $s = '90' . substr($s, 3);  // 9005321234567

        return strlen($s) === 12 && substr($s, 0, 2) === '90' ? $s : null;
    }

    /** TCKN'i yalnız rakama indirger. Geçersizse null. */
    public static function normalizeTc(?string $tc): ?string
    {
        $s = preg_replace('/\D/', '', (string)$tc);
        return strlen($s) === 11 ? $s : null;
    }

    /**
     * Parçalı telefon alanlarını (Basvurular tablosu düzeni) birleştirip normalize eder.
     * @param array $kayit phoneCountryNumber / phoneAreaNumber / phoneNumber içeren dizi
     */
    public static function kayittanGsm(array $kayit): ?string
    {
        return self::normalizeGsm(
            ($kayit['phoneCountryNumber'] ?? '') .
            ($kayit['phoneAreaNumber']    ?? '') .
            ($kayit['phoneNumber']        ?? '')
        );
    }

    // ─── Kontrol ──────────────────────────────────────────────────────────────

    /**
     * Değer kara listede mi? Yalnız aktif (Durum=1) ve tarih aralığı içindeki kayıt döner.
     *
     * @param string $deger Normalize edilmiş GSM veya TCKN
     * @param string $tur   gsm | tc
     * @return array|null KaraListe satırı veya null
     */
    public static function kontrol(?string $deger, string $tur = 'gsm'): ?array
    {
        if ($deger === null || $deger === '') return null;

        try {
            return self::db()->fetchOne("
                SELECT TOP 1 *
                FROM dbo.KaraListe
                WHERE KaraListe_Tur = ?
                  AND KaraListe_Deger = ?
                  AND Durum = 1
                  AND (KaraListe_BaslangicTarihi IS NULL OR KaraListe_BaslangicTarihi <= GETDATE())
                  AND (KaraListe_BitisTarihi     IS NULL OR KaraListe_BitisTarihi     >= GETDATE())
            ", [$tur, $deger]) ?: null;
        } catch (Exception $e) {
            // Kontrol hatası akışı kesmemeli; engelli saymayız.
            error_log('KaraListe kontrol hatası: ' . $e->getMessage());
            return null;
        }
    }

    /** Ham GSM ile kontrol (normalize eder). */
    public static function gsmEngelli(?string $gsm): ?array
    {
        return self::kontrol(self::normalizeGsm($gsm), 'gsm');
    }

    /** Ham TCKN ile kontrol (normalize eder). */
    public static function tcEngelli(?string $tc): ?array
    {
        return self::kontrol(self::normalizeTc($tc), 'tc');
    }

    // ─── Kontrol + Log ────────────────────────────────────────────────────────

    /**
     * Engelleme noktalarında kullanılacak ana fonksiyon.
     * GSM (ve verildiyse TC) kara listede ise denemeyi loglar, sayaç artırır ve kaydı döner.
     *
     * @param string      $gsm         Ham veya normalize GSM
     * @param string      $nokta       Engelleme noktası: 'otp/gonder', 'meta_lead', 'basvuru-form'...
     * @param array|null  $detay       İstek gövdesi / bağlam (JSON olarak loglanır)
     * @param int|null    $basvuruId   Varsa ilgili başvuru
     * @param int         $kullaniciId İşlemi tetikleyen kullanıcı (API/cron için 0)
     * @param string|null $tc          Opsiyonel TCKN kontrolü
     * @return array|null Engelliyse KaraListe satırı (+ '_mesaj'), değilse null
     */
    public static function kontrolVeLogla(
        ?string $gsm,
        string $nokta,
        ?array $detay = null,
        ?int $basvuruId = null,
        int $kullaniciId = 0,
        ?string $tc = null
    ): ?array {
        $kayit = self::gsmEngelli($gsm);
        $tur   = 'gsm';

        if (!$kayit && $tc !== null) {
            $kayit = self::tcEngelli($tc);
            $tur   = 'tc';
        }
        if (!$kayit) return null;

        self::denemeKaydet($kayit, $nokta, $detay, $basvuruId, $kullaniciId, $tur);

        $kayit['_mesaj'] = self::mesaj($kayit);
        return $kayit;
    }

    /** Engellenen denemeyi BasvuruLog'a yazar ve KaraListe sayacını artırır. */
    private static function denemeKaydet(
        array $kayit,
        string $nokta,
        ?array $detay,
        ?int $basvuruId,
        int $kullaniciId,
        string $tur
    ): void {
        try {
            $aciklama = sprintf(
                '%s (%s) kara listede engellendi — %s',
                $kayit['KaraListe_Deger'],
                strtoupper($tur),
                trim((string)($kayit['KaraListe_Aciklama'] ?? '')) !== ''
                    ? $kayit['KaraListe_Aciklama']
                    : 'açıklama yok'
            );

            self::db()->insert('BasvuruLog', [
                'BasvuruLog_Basvuru_id' => $basvuruId,
                'BasvuruLog_Islem'      => self::LOG_ISLEM,
                'BasvuruLog_ApiEndpoint' => mb_substr($nokta, 0, 1000),
                'BasvuruLog_ApiIstek'   => $detay !== null
                    ? json_encode([
                        'kara_liste_id' => $kayit['KaraListe_id'],
                        'tur'           => $tur,
                        'deger'         => $kayit['KaraListe_Deger'],
                        'kaynak'        => $kayit['KaraListe_Kaynak'],
                        'detay'         => $detay,
                      ], JSON_UNESCAPED_UNICODE)
                    : null,
                'BasvuruLog_Aciklama'   => mb_substr($aciklama, 0, 1000),
                'BasvuruLog_IP'         => basvuruLogIp(),
                'OlusturanKullanici'    => $kullaniciId,
                'OlusturmaTarihi'       => date('Y-m-d H:i:s'),
                'Durum'                 => 1,
            ]);

            self::db()->execute("
                UPDATE dbo.KaraListe
                SET KaraListe_EngellemeSayisi    = ISNULL(KaraListe_EngellemeSayisi, 0) + 1,
                    KaraListe_SonEngellemeTarihi = GETDATE()
                WHERE KaraListe_id = ?
            ", [$kayit['KaraListe_id']]);
        } catch (Exception $e) {
            // Log hatası engellemeyi bozmamalı
            error_log('KaraListe log hatası: ' . $e->getMessage());
        }
    }

    /** Kullanıcıya/API'ye dönecek standart ret mesajı. */
    public static function mesaj(array $kayit): string
    {
        return 'Bu numara ile işlem yapılamaz.';
    }

    // ─── Yönetim ──────────────────────────────────────────────────────────────

    /**
     * Kara listeye ekler veya mevcut kaydı günceller/aktifleştirir.
     *
     * @return array ['success','durum','mesaj','id']  durum: eklendi | guncellendi | gecersiz
     */
    public static function ekle(
        string $ham,
        string $tur = 'gsm',
        ?string $aciklama = null,
        ?string $kaynak = 'manuel',
        int $kullaniciId = 0,
        ?string $baslangic = null,
        ?string $bitis = null
    ): array {
        $deger = $tur === 'tc' ? self::normalizeTc($ham) : self::normalizeGsm($ham);

        if ($deger === null) {
            return ['success' => false, 'durum' => 'gecersiz', 'id' => null,
                    'mesaj' => ($tur === 'tc' ? 'Geçersiz TC kimlik no: ' : 'Geçersiz numara: ') . $ham];
        }

        $mevcut = self::db()->fetchOne(
            "SELECT KaraListe_id FROM dbo.KaraListe WHERE KaraListe_Tur = ? AND KaraListe_Deger = ?",
            [$tur, $deger]
        );

        $veri = [
            'KaraListe_Aciklama'        => $aciklama,
            'KaraListe_Kaynak'          => $kaynak,
            'KaraListe_BaslangicTarihi' => $baslangic ?: null,
            'KaraListe_BitisTarihi'     => $bitis ?: null,
            'Durum'                     => 1,
            'GuncelleyenKullanici'      => $kullaniciId,
            'GuncellemeTarihi'          => date('Y-m-d H:i:s'),
        ];

        if ($mevcut) {
            self::db()->update('KaraListe', $veri, ['KaraListe_id' => $mevcut['KaraListe_id']]);
            return ['success' => true, 'durum' => 'guncellendi', 'id' => (int)$mevcut['KaraListe_id'],
                    'mesaj' => $deger . ' güncellendi'];
        }

        $veri['KaraListe_Tur']       = $tur;
        $veri['KaraListe_Deger']     = $deger;
        $veri['OlusturanKullanici']  = $kullaniciId;
        $veri['OlusturmaTarihi']     = date('Y-m-d H:i:s');

        $id = self::db()->insert('KaraListe', $veri);
        return ['success' => (bool)$id, 'durum' => 'eklendi', 'id' => (int)$id,
                'mesaj' => $deger . ' eklendi'];
    }
}
