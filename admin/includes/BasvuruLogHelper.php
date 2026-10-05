<?php
/**
 * Başvuru Log Helper
 * basvuru-form.php ve basvuru-yonetimi.php sayfalarındaki tüm değişiklikleri
 * (ekle/güncelle/sil) ve API istek/yanıtlarını BasvuruLog tablosuna kaydeder.
 *
 * Tablo: dbo.BasvuruLog
 */

/** Karşılaştırma için değer normalizasyonu */
function basvuruLogNormalize($value) {
    if ($value instanceof DateTime) return $value->format('Y-m-d H:i:s');
    if ($value === null || $value === '') return null;
    if (is_numeric($value)) return (string)$value;
    return $value;
}

/** İstekteki IP adresi */
function basvuruLogIp() {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
}

/**
 * CRUD değişikliği loglar (EKLE / GUNCELLE / SIL).
 * Değişen alanları öncesi/sonrası karşılaştırarak otomatik çıkarır.
 *
 * @param object      $db
 * @param int|null    $basvuruId
 * @param string      $islem        EKLE | GUNCELLE | SIL
 * @param array|null  $oncesi       İşlem öncesi kayıt (EKLE için null)
 * @param array|null  $sonrasi      İşlem sonrası kayıt (SIL için null)
 * @param int         $kullaniciId
 * @param string|null $aciklama
 * @return bool
 */
function basvuruLogKaydet($db, $basvuruId, $islem, $oncesi, $sonrasi, $kullaniciId, $aciklama = null) {
    try {
        // Değişen alanları hesapla (yalnız GUNCELLE'de anlamlı)
        $degisen = [];
        if ($islem === 'GUNCELLE' && is_array($oncesi) && is_array($sonrasi)) {
            foreach ($sonrasi as $alan => $yeni) {
                $eski = $oncesi[$alan] ?? null;
                if (basvuruLogNormalize($eski) !== basvuruLogNormalize($yeni)) {
                    $degisen[$alan] = ['eski' => basvuruLogNormalize($eski), 'yeni' => basvuruLogNormalize($yeni)];
                }
            }
            // Hiç değişiklik yoksa (örn. aynı değerlerle kaydet) log atma
            if (empty($degisen)) return true;
        }

        $db->insert('BasvuruLog', [
            'BasvuruLog_Basvuru_id'     => $basvuruId,
            'BasvuruLog_Islem'          => $islem,
            'BasvuruLog_Oncesi'         => $oncesi  !== null ? json_encode($oncesi,  JSON_UNESCAPED_UNICODE) : null,
            'BasvuruLog_Sonrasi'        => $sonrasi !== null ? json_encode($sonrasi, JSON_UNESCAPED_UNICODE) : null,
            'BasvuruLog_DegisenAlanlar' => !empty($degisen) ? json_encode($degisen, JSON_UNESCAPED_UNICODE) : null,
            'BasvuruLog_Aciklama'       => $aciklama,
            'BasvuruLog_IP'             => basvuruLogIp(),
            'OlusturanKullanici'        => $kullaniciId,
            'OlusturmaTarihi'           => date('Y-m-d H:i:s'),
            'Durum'                     => 1,
        ]);
        return true;
    } catch (Exception $e) {
        // Log hatası ana işlemi engellemez
        error_log('BasvuruLogHelper (kaydet) Hatası: ' . $e->getMessage());
        return false;
    }
}

/**
 * API istek/yanıt loglar (API_SIPARIS / API_SUREC).
 *
 * @param object      $db
 * @param int|null    $basvuruId
 * @param string      $islem        API_SIPARIS | API_SUREC
 * @param string|null $endpoint     Method + URL
 * @param mixed       $istek        Gönderilen body (array/string)
 * @param mixed       $yanit        Dönen yanıt (array/string)
 * @param int|null    $http         HTTP durum kodu
 * @param int         $kullaniciId
 * @param string|null $aciklama
 * @return bool
 */
function basvuruLogApi($db, $basvuruId, $islem, $endpoint, $istek, $yanit, $http, $kullaniciId, $aciklama = null) {
    try {
        $toStr = function ($v) {
            if ($v === null) return null;
            return is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        };
        $db->insert('BasvuruLog', [
            'BasvuruLog_Basvuru_id' => $basvuruId,
            'BasvuruLog_Islem'      => $islem,
            'BasvuruLog_ApiEndpoint'=> $endpoint,
            'BasvuruLog_ApiIstek'   => $toStr($istek),
            'BasvuruLog_ApiYanit'   => $toStr($yanit),
            'BasvuruLog_HttpKodu'   => $http !== null ? (int)$http : null,
            'BasvuruLog_Aciklama'   => $aciklama,
            'BasvuruLog_IP'         => basvuruLogIp(),
            'OlusturanKullanici'    => $kullaniciId,
            'OlusturmaTarihi'       => date('Y-m-d H:i:s'),
            'Durum'                 => 1,
        ]);
        return true;
    } catch (Exception $e) {
        error_log('BasvuruLogHelper (api) Hatası: ' . $e->getMessage());
        return false;
    }
}
