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

    // Eşleme tanımlı değilse eski otomatik tahmin
    if (!$eslemeler) {
        $a = alanlariAyikla($fieldData);
        return array_merge($bos, [
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
