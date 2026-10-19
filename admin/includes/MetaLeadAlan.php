<?php
/**
 * Meta lead alanlarının Basvurular kolonlarına dönüştürülmesi.
 *
 * Hem webhook (webhook/meta-lead.php) hem de panelin geriye dönük
 * doldurma işlemi (admin/pages/reklam-yonetimi.php) bu dosyayı kullanır.
 */

/** field_data dizisinden Isim/Soyisim/Telefon/Email ayıklar (Basvurular eşlemesi). */
function alanlariAyikla(array $fieldData): array
{
    $ad = ''; $soyad = ''; $tam = ''; $tel = ''; $mail = '';
    foreach ($fieldData as $f) {
        $isim = strtolower((string)($f['name'] ?? ''));
        $deger = (string)($f['values'][0] ?? '');
        switch (true) {
            case $isim === 'full_name' || $isim === 'name':          $tam = $deger; break;
            case $isim === 'first_name' || str_contains($isim, 'ad'): $ad = $ad ?: $deger; break;
            case $isim === 'last_name'  || str_contains($isim, 'soyad'): $soyad = $soyad ?: $deger; break;
            case str_contains($isim, 'phone') || str_contains($isim, 'telefon'): $tel = $tel ?: $deger; break;
            case str_contains($isim, 'email') || str_contains($isim, 'mail'):    $mail = $mail ?: $deger; break;
        }
    }
    // first/last gelmediyse full_name'i böl (ilk kelime Isim, kalan Soyisim)
    if ($ad === '' && $soyad === '' && trim($tam) !== '') {
        $parca = preg_split('/\s+/', trim($tam));
        $ad = array_shift($parca);
        $soyad = implode(' ', $parca);
    }

    // Telefon: yalnız rakam. Ülke '90'. Son 10 haneden sağdan 7 = abone no (phoneNumber),
    // kalan = alan kodu (phoneAreaNumber, boşluksuz). Örn: 5550000000 → bölge 501, numara 3571085
    $rakam = preg_replace('/\D/', '', $tel);
    $ulke = '90'; $bolge = null; $numara = '';
    if ($rakam !== '') {
        $son10  = strlen($rakam) > 10 ? substr($rakam, -10) : $rakam;
        $numara = substr($son10, -7);            // sağdan 7 hane
        $bolge  = substr($son10, 0, -7) ?: null; // kalan haneler (alan kodu)
    }

    return [
        'isim'     => trim($ad)   ?: null,
        'soyisim'  => trim($soyad) ?: null,
        'ulkeKod'  => $numara !== '' ? $ulke : null,
        'bolgeKod' => $numara !== '' ? $bolge : null,
        'telefon'  => $numara ?: null,
        'email'    => $mail ?: null,
    ];
}

/**
 * Meta sorusunun varsayılan türünü tahmin eder.
 * Dönüş: adsoyad | isim | soyisim | telefon | email | null (null → Açıklama)
 */
function alanTuruTahmin(string $key, string $type = ''): ?string
{
    $type = strtoupper($type);
    $k    = mb_strtolower($key);
    if ($type === 'FULL_NAME' || $k === 'full_name' || preg_match('/ad[ıi]?(n[ıi]z)?_?soyad/u', $k)) return 'adsoyad';
    if ($type === 'FIRST_NAME' || $k === 'first_name') return 'isim';
    if ($type === 'LAST_NAME'  || $k === 'last_name')  return 'soyisim';
    if ($type === 'PHONE' || $k === 'phone_number' || str_contains($k, 'phone') || str_contains($k, 'telefon')) return 'telefon';
    if ($type === 'EMAIL' || $k === 'email' || str_contains($k, 'mail')) return 'email';
    return null;
}

/**
 * Formun Meta sorularını ReklamLeadFormAlanlari ile senkronlar ve varsayılan eşlemeyi yazar.
 *
 * - Yeni soru: varsayılan hedefle eklenir (ad soyad / telefon / e-posta kendi alanına,
 *   kalanlar çoklu kabul eden alana — Açıklama).
 * - Hiç eşlemesi olmayan form: mevcut boş satırlar da varsayılanla doldurulur.
 * - Eşlemesi olan formda mevcut seçimlere dokunulmaz.
 * - Meta'dan kaybolan sorular pasife alınır (eşleme silinmez).
 *
 * $tamListe = false (web formu): yalnız yeni sorular eklenir; mevcut satırlara ve
 * listede olmayan sorulara dokunulmaz (her başvuru tüm soruları göndermeyebilir).
 *
 * Panel (Alan Eşleme modalı), webhook (yeni formdan ilk lead) ve web başvuru formu ortak kullanır.
 */
function formAlanlariSenkronla(Database $db, int $fid, array $sorular, int $kullaniciId, bool $tamListe = true): void
{
    $simdi = date('Y-m-d H:i:s');

    // Tür → hedef kolon (katalogdan)
    $hedef = ['adsoyad' => null, 'isim' => null, 'soyisim' => null, 'telefon' => null, 'email' => null, 'coklu' => null];
    foreach ($db->fetchAll("
        SELECT tanim_BasvuruHedefAlanlari_Kolon      AS kolon,
               tanim_BasvuruHedefAlanlari_Tip        AS tip,
               tanim_BasvuruHedefAlanlari_CokluKabul AS coklu
        FROM tanim_BasvuruHedefAlanlari
        WHERE Durum = 1
        ORDER BY tanim_BasvuruHedefAlanlari_Sira
    ") as $h) {
        $kolon = (string)$h['kolon'];
        if ((int)$h['coklu'] === 1) { $hedef['coklu'] = $hedef['coklu'] ?: $kolon; continue; }
        if (in_array($h['tip'], ['adsoyad', 'telefon'], true)) $hedef[$h['tip']] = $hedef[$h['tip']] ?: $kolon;
        elseif (in_array(strtolower($kolon), ['isim', 'soyisim', 'email'], true)) $hedef[strtolower($kolon)] = $kolon;
    }

    $eslemeVar  = false;
    $kullanilan = [];
    foreach ($db->fetchAll("
        SELECT DISTINCT ReklamLeadFormAlanlari_HedefKolon AS kolon FROM ReklamLeadFormAlanlari
        WHERE ReklamLeadFormAlanlari_Form_id = ? AND ReklamLeadFormAlanlari_HedefKolon IS NOT NULL
    ", [$fid]) as $k) { $eslemeVar = true; $kullanilan[$k['kolon']] = true; }

    // Tek alan kabul eden hedef bir kez verilir (2. telefon sorusu Açıklama'ya düşer)
    $varsayilan = function (array $q) use ($hedef, &$kullanilan) {
        $tur  = alanTuruTahmin((string)($q['key'] ?? ''), (string)($q['type'] ?? ''));
        $aday = $tur ? $hedef[$tur] : null;
        if ($aday && empty($kullanilan[$aday])) { $kullanilan[$aday] = true; return $aday; }
        return $hedef['coklu'];
    };

    $gelenler = [];
    foreach ($sorular as $q) {
        $alanAdi = trim((string)($q['key'] ?? ''));
        if ($alanAdi === '') continue;
        $gelenler[] = $alanAdi;
        $soru = mb_substr((string)($q['label'] ?? ''), 0, 400);

        $var = $db->fetchOne("
            SELECT ReklamLeadFormAlanlari_id, ReklamLeadFormAlanlari_HedefKolon FROM ReklamLeadFormAlanlari
            WHERE ReklamLeadFormAlanlari_Form_id = ? AND ReklamLeadFormAlanlari_MetaAlanAdi = ?
        ", [$fid, $alanAdi]);

        if ($var && !$tamListe) continue;

        if ($var) {
            // Soru metni değişmiş olabilir; eşleme korunur
            $guncel = [
                'ReklamLeadFormAlanlari_MetaSoru' => $soru,
                'Durum'                           => 1,
                'GuncelleyenKullanici'            => $kullaniciId,
                'GuncellemeTarihi'                => $simdi,
            ];
            if (!$eslemeVar && $var['ReklamLeadFormAlanlari_HedefKolon'] === null) {
                $guncel['ReklamLeadFormAlanlari_HedefKolon'] = $varsayilan($q);
            }
            $db->update('ReklamLeadFormAlanlari', $guncel, ['ReklamLeadFormAlanlari_id' => $var['ReklamLeadFormAlanlari_id']]);
        } else {
            $db->insert('ReklamLeadFormAlanlari', [
                'ReklamLeadFormAlanlari_Form_id'     => $fid,
                'ReklamLeadFormAlanlari_MetaAlanAdi' => $alanAdi,
                'ReklamLeadFormAlanlari_MetaSoru'    => $soru,
                'ReklamLeadFormAlanlari_HedefKolon'  => $varsayilan($q),
                'ReklamLeadFormAlanlari_Sira'        => 0,
                'OlusturanKullanici'                 => $kullaniciId,
                'OlusturmaTarihi'                    => $simdi,
                'Durum'                              => 1,
            ]);
        }
    }

    if ($gelenler && $tamListe) {
        $yer = implode(',', array_fill(0, count($gelenler), '?'));
        $db->execute("
            UPDATE ReklamLeadFormAlanlari SET Durum = 0,
                   GuncelleyenKullanici = ?, GuncellemeTarihi = ?
            WHERE ReklamLeadFormAlanlari_Form_id = ?
              AND ReklamLeadFormAlanlari_MetaAlanAdi NOT IN ($yer)
        ", array_merge([$kullaniciId, $simdi, $fid], $gelenler));
    }
}

/**
 * Form bazlı alan eşlemesiyle Basvurular kolonlarını üretir.
 *
 * ReklamLeadFormAlanlari'nda bu form için eşleme tanımlıysa o kullanılır;
 * hiç eşleme yoksa alanlariAyikla() otomatik tahmini devreye girer.
 * Dönen dizi doğrudan Basvurular kolonlarıdır.
 */
function alanlariEsle(Database $db, ?int $formFk, array $fieldData): array
{
    $bos = [
        'Isim' => null, 'Soyisim' => null, 'email' => null,
        'phoneCountryNumber' => null, 'phoneAreaNumber' => null, 'phoneNumber' => null,
        'TCKimlikNo' => null, 'birthDate' => null, 'Basvuru_Aciklama' => null,
    ];

    // Meta alan adı → değer
    $gelen = [];
    foreach ($fieldData as $f) {
        $ad = (string)($f['name'] ?? '');
        if ($ad === '') continue;
        $gelen[$ad] = (string)($f['values'][0] ?? '');
    }

    $eslemeler = [];
    if ($formFk) {
        $eslemeler = $db->fetchAll("
            SELECT a.ReklamLeadFormAlanlari_MetaAlanAdi AS alan,
                   a.ReklamLeadFormAlanlari_MetaSoru    AS soru,
                   a.ReklamLeadFormAlanlari_HedefKolon  AS hedef,
                   a.ReklamLeadFormAlanlari_Sira        AS sira,
                   t.tanim_BasvuruHedefAlanlari_Tip        AS tip,
                   t.tanim_BasvuruHedefAlanlari_MaxUzunluk AS maxlen
            FROM ReklamLeadFormAlanlari a
            INNER JOIN tanim_BasvuruHedefAlanlari t
                    ON t.tanim_BasvuruHedefAlanlari_Kolon = a.ReklamLeadFormAlanlari_HedefKolon
                   AND t.Durum = 1
            WHERE a.ReklamLeadFormAlanlari_Form_id = ?
              AND a.Durum = 1
              AND a.ReklamLeadFormAlanlari_HedefKolon IS NOT NULL
            ORDER BY a.ReklamLeadFormAlanlari_Sira, a.ReklamLeadFormAlanlari_id
        ", [$formFk]);
    }

    // Eşleme tanımlı değilse eski otomatik tahmin; tanınmayan sorular Açıklama'ya
    if (!$eslemeler) {
        $a = alanlariAyikla($fieldData);
        $aciklama = [];
        foreach ($gelen as $ad => $deger) {
            if (trim($deger) === '' || alanTuruTahmin($ad) !== null) continue;
            $aciklama[] = trim(str_replace('_', ' ', $ad)) . ': ' . trim($deger);
        }
        return array_merge($bos, [
            'Basvuru_Aciklama'   => $aciklama ? mb_substr(implode("\n", $aciklama), 0, 500) : null,
            'Isim'               => $a['isim'],
            'Soyisim'            => $a['soyisim'],
            'email'              => $a['email'],
            'phoneCountryNumber' => $a['ulkeKod'],
            'phoneAreaNumber'    => $a['bolgeKod'],
            'phoneNumber'        => $a['telefon'],
        ]);
    }

    $veri     = $bos;
    $aciklama = [];

    foreach ($eslemeler as $e) {
        $deger = trim((string)($gelen[$e['alan']] ?? ''));
        if ($deger === '') continue;

        $hedef = (string)$e['hedef'];
        $tip   = (string)$e['tip'];
        $max   = $e['maxlen'] !== null ? (int)$e['maxlen'] : null;

        switch ($tip) {
            case 'adsoyad':
                $parca = preg_split('/\s+/', $deger);
                $veri['Isim']    = mb_substr(array_shift($parca), 0, 100);
                $veri['Soyisim'] = $parca ? mb_substr(implode(' ', $parca), 0, 100) : null;
                break;

            case 'telefon':
                $rakam = preg_replace('/\D/', '', $deger);
                if ($rakam !== '') {
                    $son10 = strlen($rakam) > 10 ? substr($rakam, -10) : $rakam;
                    $veri['phoneNumber']        = substr($son10, -7);
                    $veri['phoneAreaNumber']    = substr($son10, 0, -7) ?: null;
                    $veri['phoneCountryNumber'] = '90';
                }
                break;

            case 'tcno':
                $tc = preg_replace('/\D/', '', $deger);
                $veri['TCKimlikNo'] = $tc !== '' ? substr($tc, 0, 11) : null;
                break;

            case 'tarih':
                $ts = strtotime($deger);
                $veri['birthDate'] = $ts ? date('Y-m-d', $ts) : null;
                break;

            default: // metin
                if ($hedef === 'Basvuru_Aciklama') {
                    $etiket = trim((string)($e['soru'] ?? '')) ?: $e['alan'];
                    $aciklama[] = $etiket . ': ' . $deger;
                } else {
                    $veri[$hedef] = $max ? mb_substr($deger, 0, $max) : $deger;
                }
                break;
        }
    }

    // Açıklama: yalnız "Soru: cevap" satırları, 500 karakterde kırpılır
    $veri['Basvuru_Aciklama'] = $aciklama
        ? mb_substr(implode("\n", $aciklama), 0, 500)
        : null;

    return $veri;
}

/**
 * Web başvuru formundan gelen alanları form eşlemesiyle işler.
 *
 * $alanlar: [['key' => 'sehir', 'label' => 'Şehir', 'value' => 'İstanbul'], ...]
 * Form biliniyorsa yeni sorular ReklamLeadFormAlanlari'na varsayılan hedefle eklenir
 * (panelde Alan Eşleme modalında görünür ve değiştirilebilir). Form başına satır
 * sayısı sınırlıdır; dış kaynaktan sınırsız soru kaydı açılamaz.
 * Dönen dizi alanlariEsle() çıktısıdır (Basvurular kolonları).
 */
function webFormAlanlariniIsle(Database $db, ?int $formFk, array $alanlar, int $kullaniciId): array
{
    $sorular = []; $fieldData = [];
    foreach ($alanlar as $a) {
        $key = trim((string)($a['key'] ?? ''));
        if ($key === '') continue;
        $sorular[]   = ['key' => $key, 'label' => (string)($a['label'] ?? '') ?: $key];
        $fieldData[] = ['name' => $key, 'values' => [(string)($a['value'] ?? '')]];
    }

    if ($formFk && $sorular) {
        $adet = (int)($db->fetchOne(
            "SELECT COUNT(*) AS adet FROM ReklamLeadFormAlanlari WHERE ReklamLeadFormAlanlari_Form_id = ?",
            [$formFk]
        )['adet'] ?? 0);
        if ($adet < 30) formAlanlariSenkronla($db, $formFk, $sorular, $kullaniciId, false);
    }

    return alanlariEsle($db, $formFk, $fieldData);
}
